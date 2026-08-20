<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Robots;

interface RobotsTxtCheckerInterface
{
    /**
     * Whether the given URL may be crawled according to its host's robots.txt.
     */
    public function isAllowed(string $url): bool;
}
