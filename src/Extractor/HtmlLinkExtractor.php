<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Extractor;

use DOMElement;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Symfony\Component\DomCrawler\Crawler;

final class HtmlLinkExtractor implements LinkExtractorInterface
{
    private const IGNORED_SCHEMES = ['mailto:', 'tel:', 'javascript:', 'data:', 'sms:', 'whatsapp:'];

    public function extract(string $html, string $sourceUrl, array $excludePatterns = []): array
    {
        if (trim($html) === '') {
            return [];
        }

        $crawler = new Crawler($html, $sourceUrl);

        $baseUrl = $sourceUrl;
        $baseNode = $crawler->filter('base[href]');
        if ($baseNode->count() > 0) {
            $baseHref = $baseNode->attr('href');
            if ($baseHref !== null && trim($baseHref) !== '') {
                $baseUrl = $this->resolveUrl($sourceUrl, trim($baseHref));
            }
        }

        $sourceHost = parse_url($sourceUrl, PHP_URL_HOST);
        $extractedLinks = [];
        $seenUrls = [];

        foreach ($crawler->filter('a[href]') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $rawHref = trim($element->getAttribute('href'));
            $anchorText = trim($element->textContent);

            if ($this->shouldIgnore($rawHref)) {
                continue;
            }

            $resolvedUrl = $this->resolveUrl($baseUrl, $rawHref);

            $cleanUrl = $this->stripFragment($resolvedUrl);

            if ($cleanUrl === '' || isset($seenUrls[$cleanUrl])) {
                continue;
            }

            if ($this->isExcluded($cleanUrl, $excludePatterns)) {
                continue;
            }

            $targetHost = parse_url($cleanUrl, PHP_URL_HOST);
            $isExternal =
                is_string($targetHost) && is_string($sourceHost)
                && (strcasecmp($targetHost, $sourceHost) !== 0);

            $seenUrls[$cleanUrl] = true;
            $extractedLinks[] = new ExtractedLink(
                url: $cleanUrl,
                sourceUrl: $sourceUrl,
                anchorText: $anchorText,
                isExternal: $isExternal
            );
        }

        return $extractedLinks;
    }

    private function shouldIgnore(string $href): bool
    {
        if ($href === '' || $href === '#') {
            return true;
        }

        if (str_starts_with($href, '#')) {
            return true;
        }

        $lower = strtolower($href);
        foreach (self::IGNORED_SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                return true;
            }
        }

        return false;
    }

    private function resolveUrl(string $baseUrl, string $relativeUrl): string
    {
        if (preg_match('#^https?://#i', $relativeUrl) === 1) {
            return $relativeUrl;
        }

        $parsedBase = parse_url($baseUrl);
        if ($parsedBase === false) {
            return $relativeUrl;
        }

        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':'.$parsedBase['port'] : '';
        $basePath = $parsedBase['path'] ?? '/';

        if (str_starts_with($relativeUrl, '//')) {
            return $scheme.':'.$relativeUrl;
        }

        if (str_starts_with($relativeUrl, '/')) {
            return sprintf('%s://%s%s%s', $scheme, $host, $port, $relativeUrl);
        }

        $dir = preg_replace('#/[^/]*$#', '', $basePath);
        $combinedPath = rtrim((string)$dir, '/').'/'.$relativeUrl;

        $segments = explode('/', $combinedPath);
        $resolvedSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolvedSegments);
            } else {
                $resolvedSegments[] = $segment;
            }
        }

        return sprintf('%s://%s%s/%s', $scheme, $host, $port, implode('/', $resolvedSegments));
    }

    private function stripFragment(string $url): string
    {
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            return substr($url, 0, $hashPos);
        }

        return $url;
    }

    /**
     * @param list<string> $patterns
     */
    private function isExcluded(string $url, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
