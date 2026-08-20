<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Robots;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class RobotsTxtChecker implements RobotsTxtCheckerInterface
{
    private const MAX_CONTENT_LENGTH = 500_000;

    /** @var array<string, list<array{pattern: string, allow: bool}>> host => applicable rules */
    private array $rulesByHost = [];

    /** @var array<string, true> hosts whose robots.txt has already been fetched (successfully or not) */
    private array $fetchedHosts = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent,
        private readonly bool $enabled = true,
    ) {
    }

    public function isAllowed(string $url): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }

        $this->ensureLoaded($url, $host);

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?'.$query;
        }

        return $this->matchRules($this->rulesByHost[$host] ?? [], $path);
    }

    private function ensureLoaded(string $url, string $host): void
    {
        if (isset($this->fetchedHosts[$host])) {
            return;
        }

        $this->fetchedHosts[$host] = true;
        $this->rulesByHost[$host] = [];

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $robotsUrl = sprintf('%s://%s/robots.txt', $scheme, $host);

        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $robotsUrl, ['timeout' => 5]);
            if ($response->getStatusCode() >= Response::HTTP_MULTIPLE_CHOICES) {
                return;
            }

            $content = substr($response->getContent(), 0, self::MAX_CONTENT_LENGTH);
        } catch (Throwable) {
            return;
        }

        $this->rulesByHost[$host] = $this->parse($content);
    }

    /**
     * @return list<array{pattern: string, allow: bool}>
     */
    private function parse(string $content): array
    {
        /** @var array<string, list<array{pattern: string, allow: bool}>> $groups */
        $groups = [];
        $agents = [];
        $rules = [];
        $collectingAgents = true;

        $commit = function () use (&$groups, &$agents, &$rules): void {
            foreach ($agents as $agent) {
                $groups[$agent] = array_merge($groups[$agent] ?? [], $rules);
            }
            $agents = [];
            $rules = [];
        };

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim((string)preg_replace('/#.*/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (!$collectingAgents) {
                    $commit();
                }
                $agents[] = strtolower($value);
                $collectingAgents = true;
                continue;
            }

            if (!in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }

            $collectingAgents = false;

            if ($field === 'disallow' && $value === '') {
                continue; // an empty Disallow means "no restriction"
            }

            $rules[] = ['pattern' => $value, 'allow' => $field === 'allow'];
        }
        $commit();

        $ourUserAgent = strtolower($this->userAgent);

        foreach ($groups as $agent => $agentRules) {
            if ($agent !== '' && $agent !== '*' && str_contains($ourUserAgent, $agent)) {
                return $agentRules;
            }
        }

        return $groups['*'] ?? [];
    }

    /**
     * @param list<array{pattern: string, allow: bool}> $rules
     */
    private function matchRules(array $rules, string $path): bool
    {
        $bestLength = -1;
        $allowed = true;

        foreach ($rules as $rule) {
            if (!$this->matchesPattern($path, $rule['pattern'])) {
                continue;
            }

            $length = strlen($rule['pattern']);
            if ($length > $bestLength) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    private function matchesPattern(string $path, string $pattern): bool
    {
        $endAnchor = str_ends_with($pattern, '$');
        $rawPattern = $endAnchor ? substr($pattern, 0, -1) : $pattern;

        $regex = '#^'.str_replace('\*', '.*', preg_quote($rawPattern, '#')).($endAnchor ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }
}
