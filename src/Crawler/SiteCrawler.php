<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
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
        $throttle = is_string($startHost) && $this->httpClient instanceof ThrottleExemptionInterface
            ? $this->httpClient
            : null;
        $throttle?->setExemptHost($startHost);

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

                try {
                    $response = $this->httpClient->request(Request::METHOD_GET, $link->url);
                    $html = $response->getContent();
                } catch (Throwable) {
                    continue;
                }

                $extracted = $this->extractor->extract($html, $link->url, $activeExcludePatterns);

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
            $throttle?->setExemptHost(null);
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
