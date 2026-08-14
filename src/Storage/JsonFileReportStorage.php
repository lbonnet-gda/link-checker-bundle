<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Storage;

use DateTimeInterface;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;

final class JsonFileReportStorage implements ReportStorageInterface
{
    public function __construct(
        private readonly string $storageDirectory,
    ) {
    }

    public function save(CrawlReport $report): string
    {
        if (!is_dir($this->storageDirectory)) {
            mkdir($this->storageDirectory, 0775, true);
        }

        $filename = sprintf(
            'report-%s-%s.json',
            date('Y-m-d_H-i-s'),
            substr(md5($report->startUrl), 0, 8)
        );

        $filePath = rtrim($this->storageDirectory, '/').'/'.$filename;

        $data = [
            'startUrl' => $report->startUrl,
            'createdAt' => date(DateTimeInterface::ATOM),
            'totalChecked' => $report->totalChecked,
            'totalDuration' => $report->totalDuration,
            'brokenLinksCount' => $report->getBrokenLinksCount(),
            'brokenLinks' => array_map(static fn(array $item) => [
                'url' => $item['link']->url,
                'sourceUrl' => $item['link']->sourceUrl,
                'anchorText' => $item['link']->anchorText,
                'isExternal' => $item['link']->isExternal,
                'statusCode' => $item['result']->statusCode,
                'duration' => $item['result']->duration,
                'errorMessage' => $item['result']->errorMessage,
                'redirectUrl' => $item['result']->redirectUrl,
            ], $report->brokenLinks),
        ];

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $filePath;
    }
}
