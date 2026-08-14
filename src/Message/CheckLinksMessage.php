<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Message;

final class CheckLinksMessage
{
    /**
     * @param list<string> $excludePatterns
     */
    public function __construct(
        public readonly ?string $startUrl = null,
        public readonly ?int $maxDepth = null,
        public readonly ?bool $checkExternal = null,
        public readonly array $excludePatterns = [],
    ) {
    }
}
