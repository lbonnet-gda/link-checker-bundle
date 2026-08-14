<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Model;

final class ExtractedLink
{
    public function __construct(
        public readonly string $url,
        public readonly string $sourceUrl,
        public readonly string $anchorText = '',
        public readonly bool $isExternal = false,
    ) {
    }
}
