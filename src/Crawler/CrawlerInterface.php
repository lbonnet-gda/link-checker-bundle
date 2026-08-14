<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Crawler;

use Lbonnet\LinkCheckerBundle\Model\CrawlReport;

interface CrawlerInterface
{
    /**
     * Starts a site crawl from its starting URL.
     *
     * @param string $startUrl Starting URL
     * @param int|null $maxDepth Max depth (null = bundle default value)
     * @param bool|null $checkExternal Check external links (null = bundle default value)
     * @param list<string> $excludePatterns Additional exclusion regex patterns
     * @param (callable(string $currentUrl, int $totalChecked, bool $isBroken): void)|null $progressCallback
     */
    public function crawl(
        string $startUrl,
        ?int $maxDepth = null,
        ?bool $checkExternal = null,
        array $excludePatterns = [],
        ?callable $progressCallback = null,
    ): CrawlReport;
}
