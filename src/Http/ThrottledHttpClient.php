<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorator that enforces a minimum delay between consecutive requests to the same host, so the
 * bundle doesn't hammer sites — especially third-party ones it doesn't control — in a tight loop.
 * A single host can be temporarily exempted via {@see setExemptHost()}, typically the site
 * currently being audited, which the crawler owns and doesn't need to be throttled against.
 */
final class ThrottledHttpClient implements HttpClientInterface, ResetInterface, ThrottleExemptionInterface
{
    private HttpClientInterface $client;
    private int $delayMs;
    private ?string $exemptHost = null;

    /** @var array<string, float> host => microtime() of the last request start */
    private array $lastRequestAt = [];

    public function __construct(HttpClientInterface $client, int $delayMs = 0)
    {
        $this->client = $client;
        $this->delayMs = $delayMs;
    }

    public function setExemptHost(?string $host): void
    {
        $this->exemptHost = $host !== null ? strtolower($host) : null;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->throttle($url);

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }

        $this->lastRequestAt = [];
    }

    private function throttle(string $url): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return;
        }

        if ($this->delayMs > 0 && $host !== $this->exemptHost) {
            $elapsedMs = (microtime(true) - ($this->lastRequestAt[$host] ?? 0.0)) * 1000;
            $remainingMs = $this->delayMs - $elapsedMs;

            if ($remainingMs > 0) {
                usleep((int)round($remainingMs * 1000));
            }
        }

        $this->lastRequestAt[$host] = microtime(true);
    }
}
