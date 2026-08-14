<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\EventListener;

use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Lbonnet\LinkCheckerBundle\Storage\ReportStorageInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;

#[AsEventListener]
final class StoreReportListener
{
    public function __construct(
        private readonly ?ReportStorageInterface $storage = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CrawlCompletedEvent $event): void
    {
        if ($this->storage === null) {
            return;
        }

        try {
            $location = $this->storage->save($event->report);
            $this->logger->info(sprintf('[LinkChecker] Report saved to %s', $location));
        } catch (Throwable $e) {
            $this->logger->error(sprintf('[LinkChecker] Failed to save report: %s', $e->getMessage()));
        }
    }
}
