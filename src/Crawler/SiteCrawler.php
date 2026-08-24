<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\Http\BoundedContentReader;
use Lbonnet\LinkCheckerBundle\Http\ThrottleExemptionInterface;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Lbonnet\LinkCheckerBundle\Robots\RobotsTxtCheckerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SiteCrawler implements CrawlerInterface
{
    /**
     * Caps how much of a page's body is read before extracting links from it. Internal
     * pages are fetched in full (unlike external links, which only ever get a small
     * Range request), so a compromised or oversized page shouldn't be able to exhaust
     * the crawler's memory just because it's reachable from the site being audited.
     */
    private const MAX_HTML_LENGTH = 5_000_000;

    public function __construct(
        private readonly LinkExtractorInterface $extractor,
        private readonly UrlCheckerInterface $urlChecker,
        private readonly HttpClientInterface $httpClient,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?RobotsTxtCheckerInterface $robotsTxtChecker = null,
        private readonly int $defaultMaxDepth = 3,
        private readonly bool $defaultCheckExternal = true,
        /** @var list<string> */
        private readonly array $defaultExcludePatterns = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function crawl(
        string $startUrl,
        ?int $maxDepth = null,
        ?bool $checkExternal = null,
        array $excludePatterns = [],
        ?callable $progressCallback = null,
    ): CrawlReport {
        $startTime = microtime(true);
        $maxDepth = $maxDepth ?? $this->defaultMaxDepth;
        $checkExternal = $checkExternal ?? $this->defaultCheckExternal;
        $activeExcludePatterns = array_merge($this->defaultExcludePatterns, $excludePatterns);

        $visited = [];
        $brokenLinks = [];
        $totalChecked = 0;

        /** @var list<array{link: ExtractedLink, depth: int}> $queue */
        $queue = [
            [
                'link' => new ExtractedLink(
                    url: $startUrl,
                    sourceUrl: $startUrl,
                    anchorText: 'Root'
                ),
                'depth' => 0,
            ],
        ];

        $startHost = parse_url($startUrl, PHP_URL_HOST);
        $throttle = null;

        if (is_string($startHost) && $this->httpClient instanceof ThrottleExemptionInterface) {
            $throttle = $this->httpClient;

            $crawlDelay = $this->robotsTxtChecker?->crawlDelay($startUrl);
            $delayMs = $crawlDelay !== null ? (int)round($crawlDelay * 1000) : 0;

            $throttle->setHostDelay($startHost, $delayMs);
        }

        try {
            while (!empty($queue)) {
                $item = array_shift($queue);
                $link = $item['link'];
                $depth = $item['depth'];

                $visitedKey = $this->visitedKey($link->url);

                if (isset($visited[$visitedKey])) {
                    continue;
                }

                $visited[$visitedKey] = true;
                $totalChecked++;

                $checkResult = $this->urlChecker->check($link->url);

                if ($checkResult->isBroken()) {
                    $brokenLinks[] = [
                        'link' => $link,
                        'result' => $checkResult,
                    ];
                }

                if ($progressCallback !== null) {
                    $progressCallback($link->url, $totalChecked, $checkResult->isBroken());
                }

                if ($link->isExternal || $checkResult->isBroken()) {
                    continue;
                }

                if ($depth >= $maxDepth) {
                    continue;
                }

                if ($checkResult->contentType !== null && !str_contains($checkResult->contentType, 'text/html')) {
                    continue;
                }

                $effectiveUrl = $link->url;

                try {
                    $response = $this->httpClient->request(Request::METHOD_GET, $link->url);
                    $html = BoundedContentReader::read($this->httpClient, $response, self::MAX_HTML_LENGTH);

                    $infoUrl = $response->getInfo('url');
                    if (is_string($infoUrl) && $infoUrl !== '') {
                        $effectiveUrl = $infoUrl;
                    }
                } catch (Throwable) {
                    continue;
                }

                $extracted = $this->extractor->extract($html, $effectiveUrl, $activeExcludePatterns);

                foreach ($extracted as $nextLink) {
                    if (isset($visited[$this->visitedKey($nextLink->url)])) {
                        continue;
                    }

                    if ($nextLink->isExternal && !$checkExternal) {
                        continue;
                    }

                    if (!$nextLink->isExternal && $this->robotsTxtChecker?->isAllowed($nextLink->url) === false) {
                        continue;
                    }

                    $queue[] = [
                        'link' => $nextLink,
                        'depth' => $depth + 1,
                    ];
                }
            }
        } finally {
            $throttle?->setHostDelay(null);
        }

        $totalDuration = microtime(true) - $startTime;

        $report = new CrawlReport(
            startUrl: $startUrl,
            brokenLinks: $brokenLinks,
            totalChecked: $totalChecked,
            totalDuration: round($totalDuration, 3)
        );

        try {
            $this->eventDispatcher?->dispatch(new CrawlCompletedEvent($report));
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('[LinkChecker] A "CrawlCompletedEvent" listener failed: %s', $e->getMessage())
            );
        }

        return $report;
    }

    /**
     * Normalizes the scheme to avoid crawling the same page twice just because it's
     * reachable via both http:// and https://. The URL actually requested is left untouched.
     */
    private function visitedKey(string $url): string
    {
        return preg_replace('#^http://#i', 'https://', $url, 1) ?? $url;
    }
}
