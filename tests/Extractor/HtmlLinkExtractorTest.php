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

    public function testMalformedTargetUrlIsNotExternalAndDoesNotThrow(): void
    {
        $html = '<a href="https://example.com:-1/page">Malformed port</a>';

        $links = $this->extractor->extract($html, 'https://example.com');

        $this->assertCount(1, $links);
        $this->assertFalse($links[0]->isExternal);
    }

    public function testExcludePatterns(): void
    {
        $html = '<a href="/admin/dashboard">Admin</a><a href="/public/page">Page</a>';

        $links = $this->extractor->extract($html, 'https://example.com', ['#/admin#']);

        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/public/page', $links[0]->url);
    }

    public function testExtractResolvesParentDirectoryTraversal(): void
    {
        $html = <<<HTML
        <a href="../../about">Up two levels</a>
        <a href="../sibling">Up one level</a>
        HTML;

        $links = $this->extractor->extract($html, 'https://example.com/blog/2024/post.html');

        $this->assertCount(2, $links);
        $this->assertSame('https://example.com/about', $links[0]->url);
        $this->assertSame('https://example.com/blog/sibling', $links[1]->url);
    }

    public function testExtractResolvesParentDirectoryTraversalPastRoot(): void
    {
        $html = '<a href="../../../overflow">Too many levels up</a>';

        $links = $this->extractor->extract($html, 'https://example.com/blog/post.html');

        $this->assertCount(1, $links);
        $this->assertSame('https://example.com/overflow', $links[0]->url);
    }

    public function testExtractPreservesQueryStringAndStripsFragment(): void
    {
        $html = <<<HTML
        <a href="/search?q=test&page=2">Absolute with query</a>
        <a href="results?sort=asc#top">Relative with query and fragment</a>
        HTML;

        $links = $this->extractor->extract($html, 'https://example.com/blog/index.html');

        $this->assertCount(2, $links);
        $this->assertSame('https://example.com/search?q=test&page=2', $links[0]->url);
        $this->assertSame('https://example.com/blog/results?sort=asc', $links[1]->url);
    }
}
