<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Storage;

use Lbonnet\LinkCheckerBundle\Model\CrawlReport;

interface ReportStorageInterface
{
    /**
     * Persists the report and returns its identifier or save path.
     */
    public function save(CrawlReport $report): string;
}
