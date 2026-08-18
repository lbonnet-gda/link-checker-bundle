# LinkCheckerBundle

A Symfony bundle to crawl a site and detect broken internal and external links.

Designed to run **outside the request/response cycle** — as a console command, a scheduled cron, or an async Messenger
worker — so it fits both CI pipelines and continuous monitoring of a live site.

## Requirements

- PHP >= 8.1
- Symfony 6.4, 7.4, or 8.1

## Installation

```bash
composer require lbonnet/link-checker-bundle
```

If you don't use Symfony Flex, enable the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    Lbonnet\LinkCheckerBundle\LinkCheckerBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/link_checker.yaml`:

```yaml
link_checker:
    base_url: 'https://example.com' # Default base URL to crawl
    max_depth: 3 # Maximum crawl depth (0 = start page only)
    timeout: 10 # Per-request HTTP timeout in seconds
    user_agent: 'Mozilla/5.0 (compatible; LinkCheckerBundle/1.0; +https://github.com/lbonnet-gda/link-checker-bundle)' # Sent as the User-Agent header; identify your crawler honestly, don't spoof a browser UA
    check_external: true # Check status of outbound links
    storage_dir: '%kernel.project_dir%/var/link_checker' # Directory for JSON audit reports
    exclude_patterns: # Regex patterns for URLs to ignore
        - '#/admin#'
        - '#/login#'
        - '#\.pdf$#'
```

## Usage

### 1. Console Command (CLI & CI)

Run an on-demand audit directly from the command line:

```bash
# Using the configured base_url
php bin/console link-checker:check

# Crawling a specific starting URL
php bin/console link-checker:check https://example.com

# With custom depth and without checking external links
php bin/console link-checker:check https://example.com --max-depth=2 --no-external

# With extra exclude patterns
php bin/console link-checker:check --exclude="#/preview#" --exclude="#/staging#"
```

> [!TIP]
> **CI / Exit Codes:** The command returns `0` (`Command::SUCCESS`) if no broken links are found, and `1`
(`Command::FAILURE`) if any broken links are detected. This makes it ideal for pull request validations and deployment
checks.

### 2. Asynchronous Execution (Messenger)

The bundle provides a `CheckLinksMessage` and its handler to offload the crawl to an asynchronous worker queue:

```php
use Lbonnet\LinkCheckerBundle\Message\CheckLinksMessage;
use Symfony\Component\Messenger\MessageBusInterface;

// In a controller, command or custom service
public function triggerAudit(MessageBusInterface $bus): void
{
    // Uses default configuration values
    $bus->dispatch(new CheckLinksMessage());

    // Or with custom parameters
    $bus->dispatch(new CheckLinksMessage(
        startUrl: 'https://example.com/blog',
        maxDepth: 2,
        checkExternal: false,
    ));
}
```

### 3. Automated Monitoring (Symfony Scheduler)

If you use `symfony/scheduler` (Symfony 6.3+), you can schedule periodic audits in your application's
`ScheduleProvider`:

```php
namespace App\Scheduler;

use Lbonnet\LinkCheckerBundle\Message\CheckLinksMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class MainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                // Run daily at 03:00 AM
                RecurringMessage::cron('0 3 * * *', new CheckLinksMessage())
            );
    }
}
```

### 4. Custom Notifications & Event Handling

When a crawl completes, a `CrawlCompletedEvent` is dispatched. You can listen to this event to send alerts (Slack,
Email, Discord) or perform custom actions:

```php
namespace App\EventListener;

use Lbonnet\LinkCheckerBundle\Event\CrawlCompletedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;

#[AsEventListener]
final class LinkCheckerNotificationListener
{
    public function __construct(
        private readonly NotifierInterface $notifier,
    ) {
    }

    public function __invoke(CrawlCompletedEvent $event): void
    {
        $report = $event->report;

        if (!$report->hasBrokenLinks()) {
            return;
        }

        $message = sprintf(
            'Found %d broken link(s) on %s (checked %d links in %.2fs).',
            $report->getBrokenLinksCount(),
            $report->startUrl,
            $report->totalChecked,
            $report->totalDuration
        );

        $notification = new Notification($message, ['chat/slack', 'email']);
        $this->notifier->send($notification);
    }
}
```

> [!NOTE]
> If a `CrawlCompletedEvent` listener throws (e.g., a misconfigured notifier transport), the bundle catches and logs
> the error instead of letting it propagate — a broken notification integration won't discard an otherwise
> successful crawl or prevent the JSON report from being saved.

### 5. Report Storage

By default, every completed crawl automatically saves a detailed JSON snapshot in `var/link_checker/`:

```json
{
    "startUrl": "https://example.com",
    "createdAt": "2026-08-14T14:15:00+02:00",
    "totalChecked": 42,
    "totalDuration": 3.12,
    "brokenLinksCount": 1,
    "likelyBlockedCount": 0,
    "brokenLinks": [
        {
            "url": "https://example.com/missing-page",
            "sourceUrl": "https://example.com/about",
            "anchorText": "Our team",
            "isExternal": false,
            "statusCode": 404,
            "duration": 0.08,
            "errorMessage": null,
            "redirectUrl": null,
            "likelyBlocked": false,
            "blockedBy": null
        }
    ]
}
```

To disable automatic file storage, set `storage_dir: null` in your bundle configuration.

### A note on external link false positives

Some external sites reject automated HTTP clients outright (Akamai, Cloudflare, Sucuri, Incapsula, DataDome...),
independently of what the resource actually contains. The bundle can't reliably bypass this — doing so would mean
impersonating a browser to evade bot protection, which this bundle deliberately does not do. `UrlChecker` still flags
this pattern when it recognizes a known bot-mitigation signature on a 403/429/503 response: the link is still reported
as broken (the crawler genuinely couldn't fetch it), but each affected entry gets
`"likelyBlocked": true` and a `"blockedBy"` provider name so you can distinguish "possibly just blocked" from
"probably a real dead link" instead of treating every entry the same. The CLI table and summary line surface the same
distinction. Domains you've manually verified as false positives can be silenced with `exclude_patterns`.

## License

MIT — see [LICENSE](LICENSE).
