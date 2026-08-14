<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SiteCrawler implements CrawlerInterface
{
    public function __construct(
        private readonly LinkExtractorInterface $extractor,
        private readonly UrlCheckerInterface $urlChecker,
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultMaxDepth = 3,
        private readonly bool $defaultCheckExternal = true,
        /** @var list<string> */
        private readonly array $defaultExcludePatterns = [],
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
                    anchorText: 'Root',
                    isExternal: false
                ),
                'depth' => 0,
            ],
        ];

        while (!empty($queue)) {
            $item = array_shift($queue);
            $link = $item['link'];
            $depth = $item['depth'];

            if (isset($visited[$link->url])) {
                continue;
            }

            $visited[$link->url] = true;
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
                if (isset($visited[$nextLink->url])) {
                    continue;
                }

                if ($nextLink->isExternal && !$checkExternal) {
                    continue;
                }

                $queue[] = [
                    'link' => $nextLink,
                    'depth' => $depth + 1,
                ];
            }
        }

        $totalDuration = microtime(true) - $startTime;

        return new CrawlReport(
            startUrl: $startUrl,
            brokenLinks: $brokenLinks,
            totalChecked: $totalChecked,
            totalDuration: round($totalDuration, 3)
        );
    }
}
