<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\MessageHandler;

use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Lbonnet\LinkCheckerBundle\Message\CheckLinksMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CheckLinksMessageHandler
{
    public function __construct(
        private readonly CrawlerInterface $crawler,
        private readonly ?string $defaultBaseUrl = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(CheckLinksMessage $message): void
    {
        $startUrl = $message->startUrl ?? $this->defaultBaseUrl;

        if ($startUrl === null || trim($startUrl) === '') {
            $this->logger->error('[LinkChecker] No base URL configured or provided in CheckLinksMessage.');

            return;
        }

        $this->logger->info(sprintf('[LinkChecker] Async crawl starting on: %s', $startUrl));

        $this->crawler->crawl(
            startUrl: $startUrl,
            maxDepth: $message->maxDepth,
            checkExternal: $message->checkExternal,
            excludePatterns: $message->excludePatterns,
        );
    }
}
