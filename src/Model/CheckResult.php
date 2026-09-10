<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

final class CheckResult
{
    private const REACHABLE_RANGE_START = 200;
    private const REACHABLE_RANGE_END = 400;

    public function __construct(
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly float $duration = 0.0,
        public readonly ?string $errorMessage = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $contentType = null,
        public readonly bool $likelyBlocked = false,
        public readonly ?BotProvider $blockedBy = null,
    ) {
    }

    public function isReachable(): bool
    {
        return $this->statusCode !== null
            && $this->statusCode >= self::REACHABLE_RANGE_START
            && $this->statusCode < self::REACHABLE_RANGE_END;
    }

    public function isBroken(): bool
    {
        return !$this->isReachable();
    }
}
