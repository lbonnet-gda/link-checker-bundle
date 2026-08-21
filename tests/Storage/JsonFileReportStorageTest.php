<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Storage;

use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use Lbonnet\LinkCheckerBundle\Storage\JsonFileReportStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        } elseif (is_file($this->tempDir)) {
            unlink($this->tempDir);
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
                [
                    'link' => new ExtractedLink(
                        'https://protected.example.com', 'https://example.com', 'Partner', true
                    ),
                    'result' => new CheckResult(
                        'https://protected.example.com',
                        Response::HTTP_FORBIDDEN,
                        0.15,
                        likelyBlocked: true,
                        blockedBy: 'Akamai',
                    ),
                ],
            ],
            totalChecked: 2,
            totalDuration: 0.27
        );

        $savedPath = $storage->save($report);

        $this->assertFileExists($savedPath);

        $decoded = json_decode((string)file_get_contents($savedPath), true);
        $this->assertSame('https://example.com', $decoded['startUrl']);
        $this->assertSame(2, $decoded['brokenLinksCount']);
        $this->assertSame(1, $decoded['likelyBlockedCount']);
        $this->assertSame(Response::HTTP_NOT_FOUND, $decoded['brokenLinks'][0]['statusCode']);
        $this->assertFalse($decoded['brokenLinks'][0]['likelyBlocked']);
        $this->assertTrue($decoded['brokenLinks'][1]['likelyBlocked']);
        $this->assertSame('Akamai', $decoded['brokenLinks'][1]['blockedBy']);
    }

    public function testSaveCreatesStorageDirectoryWhenMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->tempDir);

        $storage = new JsonFileReportStorage($this->tempDir);
        $storage->save(new CrawlReport(startUrl: 'https://example.com'));

        $this->assertDirectoryExists($this->tempDir);
    }

    public function testSaveWithNoBrokenLinksProducesEmptyReport(): void
    {
        $storage = new JsonFileReportStorage($this->tempDir);

        $savedPath = $storage->save(
            new CrawlReport(
                startUrl: 'https://example.com',
                totalChecked: 10,
                totalDuration: 1.5,
            )
        );

        $decoded = json_decode((string)file_get_contents($savedPath), true);

        $this->assertSame(0, $decoded['brokenLinksCount']);
        $this->assertSame(0, $decoded['likelyBlockedCount']);
        $this->assertSame([], $decoded['brokenLinks']);
        $this->assertSame(10, $decoded['totalChecked']);
    }

    public function testSaveSerializesNonHttpFailureFields(): void
    {
        $storage = new JsonFileReportStorage($this->tempDir);

        $report = new CrawlReport(
            startUrl: 'https://example.com',
            brokenLinks: [
                [
                    'link' => new ExtractedLink('https://unreachable.example.com', 'https://example.com', 'Dead'),
                    'result' => new CheckResult(
                        'https://unreachable.example.com',
                        duration: 5.0,
                        errorMessage: 'Could not resolve host',
                    ),
                ],
            ],
            totalChecked: 1,
        );

        $savedPath = $storage->save($report);
        $decoded = json_decode((string)file_get_contents($savedPath), true);

        $this->assertNull($decoded['brokenLinks'][0]['statusCode']);
        $this->assertSame('Could not resolve host', $decoded['brokenLinks'][0]['errorMessage']);
        $this->assertNull($decoded['brokenLinks'][0]['redirectUrl']);
    }

    public function testSaveForDifferentStartUrlsProducesDistinctFiles(): void
    {
        $storage = new JsonFileReportStorage($this->tempDir);

        $firstPath = $storage->save(new CrawlReport(startUrl: 'https://example.com'));
        $secondPath = $storage->save(new CrawlReport(startUrl: 'https://another-example.com'));

        $this->assertNotSame($firstPath, $secondPath);
        $this->assertFileExists($firstPath);
        $this->assertFileExists($secondPath);
    }

    public function testConsecutiveSavesForSameStartUrlDoNotCollide(): void
    {
        $storage = new JsonFileReportStorage($this->tempDir);
        $report = new CrawlReport(startUrl: 'https://example.com');

        $firstPath = $storage->save($report);
        $secondPath = $storage->save($report);

        $this->assertNotSame(
            $firstPath,
            $secondPath,
            'Two saves within the same second must not overwrite each other.'
        );
        $this->assertFileExists($firstPath);
        $this->assertFileExists($secondPath);
    }

    public function testSaveThrowsWhenStorageDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->tempDir, 'not a directory');

        $storage = new JsonFileReportStorage($this->tempDir);

        $this->expectException(RuntimeException::class);

        $storage->save(new CrawlReport(startUrl: 'https://example.com'));
    }
}
