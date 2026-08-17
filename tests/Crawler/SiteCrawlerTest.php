<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Crawler\SiteCrawler;
use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
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

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            defaultMaxDepth: 2
        );

        $report = $crawler->crawl($startUrl);

        $this->assertTrue($report->hasBrokenLinks());
        $this->assertSame(1, $report->getBrokenLinksCount());
        $this->assertSame('https://example.com/page-404', $report->brokenLinks[0]['link']->url);
        $this->assertSame(Response::HTTP_NOT_FOUND, $report->brokenLinks[0]['result']->statusCode);
    }

    public function testCrawlDispatchesEvent(): void
    {
        $startUrl = 'https://example.com';
        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $httpClient = new MockHttpClient(new MockResponse('<html></html>'));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CrawlCompletedEvent::class));

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            eventDispatcher: $dispatcher
        );

        $crawler->crawl($startUrl);
    }

    public function testCrawlReturnsReportEvenIfEventListenerThrows(): void
    {
        $startUrl = 'https://example.com';
        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $httpClient = new MockHttpClient(new MockResponse('<html></html>'));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('Slack transport misconfigured'));

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            eventDispatcher: $dispatcher
        );

        $report = $crawler->crawl($startUrl);

        $this->assertSame($startUrl, $report->startUrl);
        $this->assertFalse($report->hasBrokenLinks());
    }
}
