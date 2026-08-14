<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Extractor;

use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;

interface LinkExtractorInterface
{
    /**
     * @param string $html The HTML content of the source page
     * @param string $sourceUrl The URL of the current page from which the HTML originates
     * @param list<string> $excludePatterns Optional exclusion regex patterns
     *
     * @return list<ExtractedLink>
     */
    public function extract(string $html, string $sourceUrl, array $excludePatterns = []): array;
}
