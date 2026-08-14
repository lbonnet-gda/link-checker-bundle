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
    base_url: 'https://example.com'   # default site to crawl
    max_depth: 3                      # crawl depth from the start URL
    timeout: 10                       # per-request timeout (seconds)
    check_external: true              # also check outbound links
    exclude_patterns: # URLs matching these regexes are skipped
        - '#/admin#'
        - '#\.pdf$#'
```

## Usage

> Command coming in a later step.

```bash
php bin/console link-checker:check
```

## Roadmap

- [x] Bundle skeleton and configuration
- [x] Link extraction from HTML
- [x] URL status checking (HttpClient)
- [x] Crawler orchestration
- [ ] Console command
- [ ] Result persistence and notifications
- [ ] Tests & CI matrix

## License

MIT — see [LICENSE](LICENSE).
