<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Extractor;

use Lbonnet\LinkCheckerBundle\Extractor\HtmlLinkExtractor;
use PHPUnit\Framework\TestCase;

final class HtmlLinkExtractorTest extends TestCase
{
    private HtmlLinkExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HtmlLinkExtractor();
    }

    public function testExtractResolvesRelativeAndExternalUrls(): void
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
            <body>
                <a href="/contact">Contact</a>
                <a href="blog/article-1">Article</a>
                <a href="https://externalsite.com/page">External link</a>
                <a href="mailto:test@example.com">Email</a>
                <a href="#section-top">Anchor</a>
            </body>
        </html>
        HTML;

        $links = $this->extractor->extract($html, 'https://example.com/sub/index.html');

        $this->assertCount(3, $links);

        $this->assertSame('https://example.com/contact', $links[0]->url);
        $this->assertSame('Contact', $links[0]->anchorText);
        $this->assertFalse($links[0]->isExternal);

        $this->assertSame('https://example.com/sub/blog/article-1', $links[1]->url);
        $this->assertFalse($links[1]->isExternal);

        $this->assertSame('https://externalsite.com/page', $links[2]->url);
        $this->assertTrue($links[2]->isExternal);
    }

    public function testExcludePatterns(): void
    {
        $html = '<a href="/admin/dashboard">Admin</a><a href="/public/page">Page</a>';

        $links = $this->extractor->extract($html, 'https://example.com', ['#/admin#']);

        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/public/page', $links[0]->url);
    }
}
