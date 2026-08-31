<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Storage;

use DateTimeInterface;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use RuntimeException;

final class JsonFileReportStorage implements ReportStorageInterface
{
    public function __construct(
        private readonly string $storageDirectory,
        private readonly int $maxReports = 0,
    ) {
    }

    public function save(CrawlReport $report): string
    {
        if (
            !is_dir($this->storageDirectory)
            && !@mkdir($this->storageDirectory, 0775, true)
            && !is_dir($this->storageDirectory)
        ) {
            throw new RuntimeException(
                sprintf('Could not create report storage directory "%s".', $this->storageDirectory)
            );
        }

        $filename = sprintf(
            'report-%s-%s-%s.json',
            date('Y-m-d_H-i-s'),
            self::hash($report->startUrl),
            uniqid('', true)
        );

        $filePath = rtrim($this->storageDirectory, '/').'/'.$filename;

        $data = [
            'startUrl' => $report->startUrl,
            'createdAt' => date(DateTimeInterface::ATOM),
            'totalChecked' => $report->totalChecked,
            'totalDuration' => $report->totalDuration,
            'brokenLinksCount' => $report->getBrokenLinksCount(),
            'likelyBlockedCount' => count(
                array_filter(
                    $report->brokenLinks,
                    static fn(array $item) => $item['result']->likelyBlocked
                )
            ),
            'brokenLinks' => array_map(static fn(array $item) => [
                'url' => $item['link']->url,
                'sourceUrl' => $item['link']->sourceUrl,
                'anchorText' => $item['link']->anchorText,
                'isExternal' => $item['link']->isExternal,
                'statusCode' => $item['result']->statusCode,
                'duration' => $item['result']->duration,
                'errorMessage' => $item['result']->errorMessage,
                'redirectUrl' => $item['result']->redirectUrl,
                'likelyBlocked' => $item['result']->likelyBlocked,
                'blockedBy' => $item['result']->blockedBy?->value,
            ], $report->brokenLinks),
        ];

        $written = file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($written === false) {
            throw new RuntimeException(sprintf('Could not write report file "%s".', $filePath));
        }

        $this->rotate($report->startUrl);

        return $filePath;
    }

    private function rotate(string $startUrl): void
    {
        if ($this->maxReports <= 0) {
            return;
        }

        $files = glob(
            sprintf(
                '%s/report-*-%s-*.json',
                rtrim($this->storageDirectory, '/'),
                self::hash($startUrl)
            )
        ) ?: [];

        sort($files);

        $excess = count($files) - $this->maxReports;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($files[$i]);
        }
    }

    private static function hash(string $startUrl): string
    {
        return substr(md5($startUrl), 0, 8);
    }
}
