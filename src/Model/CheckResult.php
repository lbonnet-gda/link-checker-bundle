<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

final class CheckResult
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $statusCode = null,
        public readonly float $duration = 0.0,
        public readonly ?string $errorMessage = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $contentType = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 400;
    }

    public function isBroken(): bool
    {
        return !$this->isSuccessful();
    }
}
