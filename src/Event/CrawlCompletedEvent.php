<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Event;

use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Symfony\Contracts\EventDispatcher\Event;

final class CrawlCompletedEvent extends Event
{
    public function __construct(
        public readonly CrawlReport $report,
    ) {
    }
}
