<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Command;

use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Lbonnet\LinkCheckerBundle\Model\CrawlReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;

#[AsCommand(
    name: 'link-checker:check',
    description: 'Crawls a website to detect and report broken links.',
)]
final class CheckLinksCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly CrawlerInterface $crawler,
        private readonly ?string $defaultBaseUrl = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'url',
                InputArgument::OPTIONAL,
                'The starting URL to crawl (defaults to link_checker.base_url)'
            )
            ->addOption(
                'max-depth',
                'd',
                InputOption::VALUE_REQUIRED,
                'Override the maximum crawl depth'
            )
            ->addOption(
                'no-external',
                null,
                InputOption::VALUE_NONE,
                'Disable checking of external links'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional regex patterns for URLs to exclude'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('The "link-checker:check" command is already running in another process. Skipping.');

            return Command::SUCCESS;
        }

        try {
            /** @var string|null $startUrl */
            $startUrl = $input->getArgument('url') ?? $this->defaultBaseUrl;

            if ($startUrl === null || trim($startUrl) === '') {
                $io->error('No URL provided. Pass an URL as argument or configure "link_checker.base_url".');

                return Command::INVALID;
            }

            /** @var string|null $maxDepthOption */
            $maxDepthOption = $input->getOption('max-depth');
            $maxDepth = $maxDepthOption !== null ? (int)$maxDepthOption : null;

            $checkExternal = $input->getOption('no-external') ? false : null;

            /** @var list<string> $excludePatterns */
            $excludePatterns = (array)$input->getOption('exclude');

            $io->title('Link Checker');
            $io->text(sprintf('Starting crawl on: <info>%s</info>', $startUrl));
            $io->newLine();

            $progressBar = null;

            if (!$io->isVerbose()) {
                ProgressBar::setPlaceholderFormatterDefinition(
                    'truncated_url',
                    static fn(ProgressBar $bar): string => self::truncate((string)$bar->getMessage())
                );

                $progressBar = $io->createProgressBar();
                $progressBar->setFormat(' %current% URLs checked [%elapsed%] <fg=cyan>%truncated_url%</>');
                $progressBar->setMessage('Starting...');
                $progressBar->start();
            }

            $progressCallback = static function (string $currentUrl, int $totalChecked, bool $isBroken) use (
                $io,
                $progressBar
            ): void {
                if ($io->isVerbose()) {
                    $status = $isBroken ? '<fg=red>[BROKEN]</>' : '<fg=green>[OK]</>';
                    $io->text(sprintf('%s (%d) %s', $status, $totalChecked, $currentUrl));
                } elseif ($progressBar !== null) {
                    $progressBar->setMessage($currentUrl);
                    $progressBar->advance();
                }
            };

            $report = $this->crawler->crawl(
                startUrl: $startUrl,
                maxDepth: $maxDepth,
                checkExternal: $checkExternal,
                excludePatterns: $excludePatterns,
                progressCallback: $progressCallback,
            );

            if ($progressBar !== null) {
                $progressBar->finish();
                $io->newLine(2);
            } else {
                $io->newLine();
            }

            if (!$report->hasBrokenLinks()) {
                $io->success(
                    sprintf(
                        'All clear! Checked %d link(s) in %.2fs with 0 broken links.',
                        $report->totalChecked,
                        $report->totalDuration
                    )
                );

                return Command::SUCCESS;
            }

            self::renderBrokenLinksReport($io, $report, $startUrl);

            return Command::FAILURE;
        } finally {
            $this->release();
        }
    }

    private static function renderBrokenLinksReport(SymfonyStyle $io, CrawlReport $report, string $startUrl): void
    {
        $io->section(sprintf('Broken Links Found (%d)', $report->getBrokenLinksCount()));

        $table = $io->createTable();
        $table->setHeaders(['Status', 'Type', 'Broken URL', 'Source Page', 'Anchor']);

        $table->setStyle('box');

        $statusWidth = 30;
        $typeWidth = 10;
        $borderOverhead = 21;
        $available = max(45, (new Terminal())->getWidth() - $statusWidth - $typeWidth - $borderOverhead);

        $urlWidth = (int)round($available * 0.45);
        $sourceWidth = (int)round($available * 0.35);
        $anchorWidth = (int)round($available * 0.20);

        $table->setColumnMaxWidth(0, $statusWidth);
        $table->setColumnMaxWidth(2, $urlWidth);
        $table->setColumnMaxWidth(3, $sourceWidth);
        $table->setColumnMaxWidth(4, $anchorWidth);

        foreach ($report->brokenLinks as $item) {
            $link = $item['link'];
            $result = $item['result'];

            if ($result->statusCode !== null) {
                if ($result->likelyBlocked) {
                    $status = sprintf(
                        '<fg=yellow>%d (blocked by %s?)</>',
                        $result->statusCode,
                        $result->blockedBy?->value
                    );
                } else {
                    $status = sprintf('<fg=red>%d</>', $result->statusCode);
                }
            } else {
                $error = $result->errorMessage ?? 'Error';
                if (str_contains($error, 'Idle timeout') || str_contains($error, 'timed out')) {
                    $error = 'Timeout';
                } elseif (str_contains($error, 'Could not resolve host')) {
                    $error = 'DNS Error';
                } elseif (str_contains($error, 'Connection refused')) {
                    $error = 'Connection Refused';
                } elseif (str_contains($error, 'SSL')) {
                    $error = 'SSL Error';
                } else {
                    $error = self::truncate($error, $statusWidth);
                }
                $status = sprintf('<fg=red>%s</>', $error);
            }

            $brokenUrlDisplay = sprintf('<href=%s>%s</>', $link->url, self::truncate($link->url, $urlWidth));

            $sourceHost = parse_url($link->sourceUrl, PHP_URL_HOST);
            $startHost = parse_url($startUrl, PHP_URL_HOST);

            if ($sourceHost === $startHost) {
                $sourcePath = parse_url($link->sourceUrl, PHP_URL_PATH) ?: '/';
                $sourceDisplay = sprintf(
                    '<href=%s>%s</>',
                    $link->sourceUrl,
                    self::truncate($sourcePath, $sourceWidth)
                );
            } else {
                $sourceDisplay = sprintf(
                    '<href=%s>%s</>',
                    $link->sourceUrl,
                    self::truncate($link->sourceUrl, $sourceWidth)
                );
            }

            $table->addRow([
                $status,
                $link->isExternal ? 'External' : 'Internal',
                $brokenUrlDisplay,
                $sourceDisplay,
                $link->anchorText !== '' ? self::truncate($link->anchorText, $anchorWidth) : '<fg=gray>(empty)</>',
            ]);
        }

        $table->render();
        $io->newLine();

        $likelyBlockedCount = count(
            array_filter(
                $report->brokenLinks,
                static fn(array $item) => $item['result']->likelyBlocked
            )
        );

        $summary = sprintf(
            'Found %d broken link(s) out of %d checked (Duration: %.2fs).',
            $report->getBrokenLinksCount(),
            $report->totalChecked,
            $report->totalDuration
        );

        if ($likelyBlockedCount > 0) {
            $summary .= sprintf(
                ' %d of them look like anti-bot blocks rather than genuinely dead links — verify manually.',
                $likelyBlockedCount
            );
        }

        $io->error($summary);
    }

    private static function truncate(string $text, int $maxLength = 60): string
    {
        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength - 3, 'UTF-8').'...'
            : $text;
    }
}
