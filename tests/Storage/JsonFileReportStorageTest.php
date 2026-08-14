<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Storage;

use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Lbonnet\LinkCheckerBundle\Storage\JsonFileReportStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class JsonFileReportStorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/link_checker_tests_'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir.'/*') ?: []);
            rmdir($this->tempDir);
        }
    }

    public function testSaveCreatesValidJsonReport(): void
    {
        $storage = new JsonFileReportStorage($this->tempDir);

        $report = new CrawlReport(
            startUrl: 'https://example.com',
            brokenLinks: [
                [
                    'link' => new ExtractedLink('https://example.com/404', 'https://example.com', 'Broken', false),
                    'result' => new CheckResult('https://example.com/404', Response::HTTP_NOT_FOUND, 0.12),
                ],
            ],
            totalChecked: 1,
            totalDuration: 0.12
        );

        $savedPath = $storage->save($report);

        $this->assertFileExists($savedPath);

        $decoded = json_decode((string)file_get_contents($savedPath), true);
        $this->assertSame('https://example.com', $decoded['startUrl']);
        $this->assertSame(1, $decoded['brokenLinksCount']);
        $this->assertSame(Response::HTTP_NOT_FOUND, $decoded['brokenLinks'][0]['statusCode']);
    }
}
