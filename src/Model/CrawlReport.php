<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

final class CrawlReport
{
    /**
     * @param string $startUrl
     * @param list<array{link: ExtractedLink, result: CheckResult}> $brokenLinks
     * @param int $totalChecked
     * @param float $totalDuration
     */
    public function __construct(
        public readonly string $startUrl,
        public readonly array $brokenLinks = [],
        public readonly int $totalChecked = 0,
        public readonly float $totalDuration = 0.0,
    ) {
    }

    public function hasBrokenLinks(): bool
    {
        return count($this->brokenLinks) > 0;
    }

    public function getBrokenLinksCount(): int
    {
        return count($this->brokenLinks);
    }
}
