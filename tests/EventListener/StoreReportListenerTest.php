<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\EventListener;

use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\EventListener\StoreReportListener;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class StoreReportListenerTest extends TestCase
{
    public function testDoesNothingWhenNoStorageIsConfigured(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');

        $listener = new StoreReportListener(storage: null, logger: $logger);

        $listener(new CrawlCompletedEvent(new CrawlReport(startUrl: 'https://example.com')));
    }

    public function testLogsTheSavedLocationOnSuccess(): void
    {
        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->method('save')->willReturn('/var/link_checker/report-123.json');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('/var/link_checker/report-123.json'));

        $listener = new StoreReportListener(storage: $storage, logger: $logger);

        $listener(new CrawlCompletedEvent(new CrawlReport(startUrl: 'https://example.com')));
    }

    public function testLogsAndSwallowsTheErrorWhenStorageThrows(): void
    {
        $storage = $this->createMock(ReportStorageInterface::class);
        $storage->method('save')->willThrowException(new RuntimeException('Disk full'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Disk full'));

        $listener = new StoreReportListener(storage: $storage, logger: $logger);

        $listener(new CrawlCompletedEvent(new CrawlReport(startUrl: 'https://example.com')));
    }
}
