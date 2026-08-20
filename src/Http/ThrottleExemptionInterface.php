<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Http;

interface ThrottleExemptionInterface
{
    /**
     * Exempts the given host from the configured request delay until cleared (pass null to
     * clear it). Lets the crawler stay fast on the site it's actually auditing while still
     * throttling requests to every other host it happens to check or fetch.
     */
    public function setExemptHost(?string $host): void;
}
