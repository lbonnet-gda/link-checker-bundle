<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Crawler;

use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Crawler\SiteCrawler;
use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\Http\ThrottleExemptionInterface;
use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Lbonnet\LinkCheckerBundle\Robots\RobotsTxtCheckerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

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

    public function testCrawlDeduplicatesHttpAndHttpsVariantsOfSameUrl(): void
    {
        $startUrl = 'https://example.com';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([
            new ExtractedLink('http://example.com/dup', $startUrl, 'Dup (http)', false),
            new ExtractedLink('https://example.com/dup', $startUrl, 'Dup (https)', false),
        ]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->expects($this->exactly(2))
            ->method('check')
            ->willReturn(new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8'));

        $httpClient = new MockHttpClient(new MockResponse('<html><body>...</body></html>'));

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            defaultMaxDepth: 1
        );

        $report = $crawler->crawl($startUrl);

        $this->assertSame(2, $report->totalChecked);
    }

    public function testCrawlSkipsInternalLinksDisallowedByRobotsTxt(): void
    {
        $startUrl = 'https://example.com';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([
            new ExtractedLink('https://example.com/allowed', $startUrl, 'Allowed', false),
            new ExtractedLink('https://example.com/blocked', $startUrl, 'Blocked', false),
        ]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturnCallback(static function (string $url) {
            if (str_contains($url, '/blocked')) {
                throw new LogicException('A robots.txt-disallowed URL must never be checked.');
            }

            return new CheckResult($url, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8');
        });

        $httpClient = new MockHttpClient(new MockResponse('<html><body>...</body></html>'));

        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('isAllowed')->willReturnCallback(
            static fn(string $url) => !str_contains($url, '/blocked')
        );

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            robotsTxtChecker: $robotsTxtChecker,
            defaultMaxDepth: 1
        );

        $report = $crawler->crawl($startUrl);

        $this->assertSame(2, $report->totalChecked);
    }

    public function testCrawlExtractsLinksAgainstTheUrlAfterRedirection(): void
    {
        $startUrl = 'https://example.com';
        $effectiveUrl = 'https://example.com/fr';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->expects($this->once())
            ->method('extract')
            ->with($this->anything(), $effectiveUrl, $this->anything())
            ->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $httpClient = new MockHttpClient(
            new MockResponse('<html><body>...</body></html>', ['url' => $effectiveUrl])
        );

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            defaultMaxDepth: 1
        );

        $crawler->crawl($startUrl);
    }

    public function testCrawlDoesNotExtractLinksBeyondTheHtmlSizeCap(): void
    {
        $startUrl = 'https://example.com';

        $padding = str_repeat('a', 5_000_001);
        $body = (static function () use ($padding) {
            yield $padding;
            yield '<a href="/late">Too late</a>';
        })();

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->expects($this->once())
            ->method('extract')
            ->with(
                $this->callback(static fn(string $html) => !str_contains($html, '/late')),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $httpClient = new MockHttpClient(new MockResponse($body));

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            defaultMaxDepth: 1
        );

        $crawler->crawl($startUrl);
    }

    public function testCrawlExemptsTheAuditedHostFromThrottling(): void
    {
        $startUrl = 'https://example.com';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $httpClient = new class(new MockHttpClient(new MockResponse('<html></html>')))
            implements HttpClientInterface, ThrottleExemptionInterface {
            /** @var list<array{0: ?string, 1: int}> */
            public array $hostDelayCalls = [];

            public function __construct(private HttpClientInterface $inner)
            {
            }

            public function setHostDelay(?string $host, int $delayMs = 0): void
            {
                $this->hostDelayCalls[] = [$host, $delayMs];
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->inner->request($method, $url, $options);
            }

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null
            ): ResponseStreamInterface {
                return $this->inner->stream($responses, $timeout);
            }

            public function withOptions(array $options): static
            {
                $clone = clone $this;
                $clone->inner = $this->inner->withOptions($options);

                return $clone;
            }
        };

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
        );

        $crawler->crawl($startUrl);

        $this->assertSame([['example.com', 0], [null, 0]], $httpClient->hostDelayCalls);
    }

    public function testCrawlHonorsTheAuditedHostsRobotsTxtCrawlDelay(): void
    {
        $startUrl = 'https://example.com';

        $extractor = $this->createMock(LinkExtractorInterface::class);
        $extractor->method('extract')->willReturn([]);

        $urlChecker = $this->createMock(UrlCheckerInterface::class);
        $urlChecker->method('check')->willReturn(
            new CheckResult($startUrl, Response::HTTP_OK, 0.05, contentType: 'text/html; charset=UTF-8')
        );

        $robotsTxtChecker = $this->createMock(RobotsTxtCheckerInterface::class);
        $robotsTxtChecker->method('crawlDelay')->with($startUrl)->willReturn(2.5);

        $httpClient = new class(new MockHttpClient(new MockResponse('<html></html>')))
            implements HttpClientInterface, ThrottleExemptionInterface {
            /** @var list<array{0: ?string, 1: int}> */
            public array $hostDelayCalls = [];

            public function __construct(private HttpClientInterface $inner)
            {
            }

            public function setHostDelay(?string $host, int $delayMs = 0): void
            {
                $this->hostDelayCalls[] = [$host, $delayMs];
            }

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                return $this->inner->request($method, $url, $options);
            }

            public function stream(
                ResponseInterface|iterable $responses,
                ?float $timeout = null
            ): ResponseStreamInterface {
                return $this->inner->stream($responses, $timeout);
            }

            public function withOptions(array $options): static
            {
                $clone = clone $this;
                $clone->inner = $this->inner->withOptions($options);

                return $clone;
            }
        };

        $crawler = new SiteCrawler(
            extractor: $extractor,
            urlChecker: $urlChecker,
            httpClient: $httpClient,
            robotsTxtChecker: $robotsTxtChecker,
        );

        $crawler->crawl($startUrl);

        $this->assertSame([['example.com', 2500], [null, 0]], $httpClient->hostDelayCalls);
    }
}
