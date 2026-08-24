<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Robots;

interface RobotsTxtCheckerInterface
{
    /**
     * Whether the given URL may be crawled according to its host's robots.txt.
     */
    public function isAllowed(string $url): bool;

    /**
     * Returns the Crawl-delay (in seconds) the URL's host requests for our user agent via
     * robots.txt, or null if none is specified (or robots.txt handling is disabled).
     */
    public function crawlDelay(string $url): ?float;
}
