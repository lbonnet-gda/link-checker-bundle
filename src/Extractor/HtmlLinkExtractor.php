<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Extractor;

use Lbonnet\CrawlerToolkit\Html\DiscoveredHref;
use Lbonnet\CrawlerToolkit\Html\LinkDiscoverer;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;

final class HtmlLinkExtractor implements LinkExtractorInterface
{
    public function __construct(
        private readonly LinkDiscoverer $discoverer = new LinkDiscoverer(),
    ) {
    }

    public function extract(string $html, string $sourceUrl, array $excludePatterns = []): array
    {
        return array_map(
            static fn(DiscoveredHref $href): ExtractedLink => new ExtractedLink(
                url: $href->url,
                sourceUrl: $sourceUrl,
                anchorText: $href->anchorText,
                isExternal: $href->isExternal,
            ),
            $this->discoverer->discover($html, $sourceUrl, $excludePatterns),
        );
    }
}
