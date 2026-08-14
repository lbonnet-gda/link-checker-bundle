<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

use Symfony\Component\HttpFoundation\Response;

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
        return $this->statusCode !== null
            && $this->statusCode >= Response::HTTP_OK
            && $this->statusCode < Response::HTTP_BAD_REQUEST;
    }

    public function isBroken(): bool
    {
        return !$this->isSuccessful();
    }
}
