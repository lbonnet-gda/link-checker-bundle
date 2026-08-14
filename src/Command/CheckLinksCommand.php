<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Command;

use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'link-checker:check',
    description: 'Crawls a website to detect and report broken links.',
)]
final class CheckLinksCommand extends Command
{
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

        $progressCallback = static function (string $currentUrl, int $totalChecked, bool $isBroken) use ($io): void {
            if ($io->isVerbose()) {
                $status = $isBroken ? '<fg=red>[BROKEN]</>' : '<fg=green>[OK]</>';
                $io->text(sprintf('%s (%d) %s', $status, $totalChecked, $currentUrl));
            }
        };

        $report = $this->crawler->crawl(
            startUrl: $startUrl,
            maxDepth: $maxDepth,
            checkExternal: $checkExternal,
            excludePatterns: $excludePatterns,
            progressCallback: $progressCallback,
        );

        $io->newLine();

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

        $io->section(sprintf('Broken Links Found (%d)', $report->getBrokenLinksCount()));

        $tableRows = [];
        foreach ($report->brokenLinks as $item) {
            $link = $item['link'];
            $result = $item['result'];

            $statusDisplay = $result->statusCode !== null
                ? (string)$result->statusCode
                : 'ERROR: '.($result->errorMessage ?? 'Unknown');

            $tableRows[] = [
                $link->url,
                $statusDisplay,
                $link->sourceUrl,
                $link->anchorText ?: '<fg=gray>(empty)</>',
                $link->isExternal ? 'External' : 'Internal',
            ];
        }

        $io->table(
            ['Broken URL', 'Status / Error', 'Source Page', 'Anchor Text', 'Type'],
            $tableRows
        );

        $io->error(
            sprintf(
                'Found %d broken link(s) out of %d checked (Duration: %.2fs).',
                $report->getBrokenLinksCount(),
                $report->totalChecked,
                $report->totalDuration
            )
        );

        return Command::FAILURE;
    }
}
