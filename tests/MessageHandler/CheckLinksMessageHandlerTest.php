<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\MessageHandler;

use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Lbonnet\LinkCheckerBundle\Message\CheckLinksMessage;
use Lbonnet\LinkCheckerBundle\MessageHandler\CheckLinksMessageHandler;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CheckLinksMessageHandlerTest extends TestCase
{
    public function testLogsAnErrorAndSkipsTheCrawlWhenNoUrlIsAvailable(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->never())->method('crawl');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new CheckLinksMessageHandler(crawler: $crawler, defaultBaseUrl: null, logger: $logger);

        $handler(new CheckLinksMessage());
    }

    public function testCrawlsTheUrlFromTheMessageWhenProvided(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->once())
            ->method('crawl')
            ->with('https://example.com/blog', 2, false, ['#/admin#'])
            ->willReturn(new CrawlReport(startUrl: 'https://example.com/blog'));

        $handler = new CheckLinksMessageHandler(crawler: $crawler, defaultBaseUrl: 'https://default.example.com');

        $handler(
            new CheckLinksMessage(
                startUrl: 'https://example.com/blog',
                maxDepth: 2,
                checkExternal: false,
                excludePatterns: ['#/admin#'],
            )
        );
    }

    public function testFallsBackToTheConfiguredDefaultBaseUrlWhenTheMessageHasNone(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->expects($this->once())
            ->method('crawl')
            ->with('https://default.example.com', null, null, [])
            ->willReturn(new CrawlReport(startUrl: 'https://default.example.com'));

        $handler = new CheckLinksMessageHandler(crawler: $crawler, defaultBaseUrl: 'https://default.example.com');

        $handler(new CheckLinksMessage());
    }
}
