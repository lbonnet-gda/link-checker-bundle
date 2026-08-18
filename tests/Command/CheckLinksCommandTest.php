<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Command;

use Lbonnet\LinkCheckerBundle\Command\CheckLinksCommand;
use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Lbonnet\LinkCheckerBundle\Model\ExtractedLink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

final class CheckLinksCommandTest extends TestCase
{
    public function testExecuteFailsWhenNoUrlProvided(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $command = new CheckLinksCommand($crawler, null);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::INVALID, $exitCode);
        $this->assertStringContainsString('No URL provided', $tester->getDisplay());
    }

    public function testExecuteSuccessWhenNoBrokenLinks(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->method('crawl')->willReturn(
            new CrawlReport('https://example.com', [], 5, 0.42)
        );

        $command = new CheckLinksCommand($crawler, 'https://example.com');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('All clear!', $tester->getDisplay());
    }

    public function testExecuteFailsWhenBrokenLinksFound(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->method('crawl')->willReturn(
            new CrawlReport(
                startUrl: 'https://example.com',
                brokenLinks: [
                    [
                        'link' => new ExtractedLink(
                            'https://example.com/dead',
                            'https://example.com',
                            'Dead Link',
                            false
                        ),
                        'result' => new CheckResult('https://example.com/dead', Response::HTTP_NOT_FOUND, 0.1),
                    ],
                ],
                totalChecked: 2,
                totalDuration: 0.15
            )
        );

        $command = new CheckLinksCommand($crawler, 'https://example.com');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('https://example.com/dead', $tester->getDisplay());
        $this->assertStringContainsString('404', $tester->getDisplay());
    }

    public function testExecuteFlagsLikelyBlockedLinksInOutput(): void
    {
        $crawler = $this->createMock(CrawlerInterface::class);
        $crawler->method('crawl')->willReturn(
            new CrawlReport(
                startUrl: 'https://example.com',
                brokenLinks: [
                    [
                        'link' => new ExtractedLink(
                            'https://protected.example.com',
                            'https://example.com',
                            'Partner',
                            true
                        ),
                        'result' => new CheckResult(
                            'https://protected.example.com',
                            Response::HTTP_FORBIDDEN,
                            0.1,
                            likelyBlocked: true,
                            blockedBy: 'Akamai',
                        ),
                    ],
                ],
                totalChecked: 1,
                totalDuration: 0.1
            )
        );

        $command = new CheckLinksCommand($crawler, 'https://example.com');
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('blocked by Akamai', $tester->getDisplay());
        $this->assertStringContainsString('anti-bot blocks', $tester->getDisplay());
    }
}
