<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Http;

interface ThrottleExemptionInterface
{
    /**
     * Overrides the throttling delay for a single host, replacing the globally configured
     * delay for requests to it until cleared (pass host: null to clear it). Lets the crawler
     * honor the audited site's own robots.txt Crawl-delay for that host — or stay fully
     * unthrottled against it (the default, delayMs: 0) when it doesn't specify one — while
     * still throttling requests to every other host it happens to check or fetch.
     */
    public function setHostDelay(?string $host, int $delayMs = 0): void;
}
