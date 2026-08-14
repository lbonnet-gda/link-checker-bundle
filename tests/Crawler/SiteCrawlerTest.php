<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Crawler\SiteCrawler;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class SiteCrawlerTest extends TestCase
{
    public function testCrawlFindsBrokenLinksAndRespectsDepth(): void
    {
        $startUrl = 'https://example.com';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([
            new ExtractedLink('https://example.com/page-1', $startUrl, 'Page 1', false),
            new ExtractedLink('https://example.com/page-404', $startUrl, 'Broken', false),
        ]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturnCallback(static function (string $url) {
            if ($url === 'https://example.com/page-404') {
                return new CheckResult($url, Response::HTTP_NOT_FOUND, 0.1);
            }

            return new CheckResult($url, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8');
        });

        $httpClient = new MockHttpClient(new MockResponse('<html><body>...</body></html>'));

        $crawler = new SiteCrawler($extractor, $urlChecker, $httpClient, 2, true);

        $report = $crawler->crawl($startUrl);

        $this->assertTrue($report->hasBrokenLinks());
        $this->assertSame(1, $report->getBrokenLinksCount());
        $this->assertSame('https://example.com/page-404', $report->brokenLinks[0]['link']->url);
        $this->assertSame(Response::HTTP_NOT_FOUND, $report->brokenLinks[0]['result']->statusCode);
    }
}
