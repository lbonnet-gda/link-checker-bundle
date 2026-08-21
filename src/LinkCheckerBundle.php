<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle;

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Lbonnet\LinkCheckerBundle\Http\ThrottledHttpClient;
use Lbonnet\LinkCheckerBundle\Storage\JsonFileReportStorage;
use Lbonnet\LinkCheckerBundle\Storage\ReportStorageInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LinkCheckerBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();
        $children = $rootNode->children();

        $children->scalarNode('base_url')
            ->defaultNull()
            ->info('Default base URL to crawl when none is passed to the command.')
            ->end();

        $children->integerNode('max_depth')
            ->defaultValue(3)
            ->min(0)
            ->info('Maximum crawl depth from the starting URL.')
            ->end();

        $children->integerNode('timeout')
            ->defaultValue(10)
            ->min(1)
            ->info('Per-request timeout in seconds when checking a URL.')
            ->end();

        $children->scalarNode('user_agent')
            ->defaultValue(UrlChecker::DEFAULT_USER_AGENT)
            ->info(
                'User-Agent header sent when checking a URL. Identify your crawler honestly; do not spoof a browser UA to bypass bot protection.'
            )
            ->end();

        $children->booleanNode('check_external')
            ->defaultTrue()
            ->info('Whether to check links pointing outside the base host.')
            ->end();

        $children->arrayNode('exclude_patterns')
            ->info('Regular expression patterns for URLs to skip.')
            ->scalarPrototype()->end();

        $children->scalarNode('storage_dir')
            ->defaultValue('%kernel.project_dir%/var/link_checker')
            ->info('Directory where crawl reports in JSON will be stored. Set to empty or null to disable.')
            ->end();

        $children->integerNode('storage_max_reports')
            ->defaultValue(30)
            ->min(0)
            ->info(
                'Maximum number of stored reports to keep per crawled URL; the oldest are deleted past that. Set to 0 to keep every report forever.'
            )
            ->end();

        $children->booleanNode('allow_private_network')
            ->defaultFalse()
            ->info(
                'Allow requests to URLs resolving to private/loopback/link-local IP ranges (e.g. 127.0.0.1, 10.0.0.0/8, cloud metadata endpoints). The crawler follows links found on the pages it visits, so leaving this disabled (default) prevents SSRF if it ever crawls untrusted or third-party content. Enable only to intentionally audit an internal network.'
            )
            ->end();

        $children->integerNode('request_delay_ms')
            ->defaultValue(200)
            ->min(0)
            ->info(
                'Minimum delay, in milliseconds, enforced between consecutive requests to the same host. The host you\'re crawling (the "url" argument/"base_url") is always exempt, so this only slows down requests to other hosts — chiefly external links you don\'t control. Set to 0 to disable throttling entirely, including for external hosts.'
            )
            ->end();

        $children->booleanNode('respect_robots_txt')
            ->defaultTrue()
            ->info(
                'Fetch and honor the crawled site\'s robots.txt: matching Disallow rules stop the crawler from following/checking further internal links under that path. Does not affect single-status checks of external links, and does not apply to the URL you explicitly start the crawl from.'
            )
            ->end();
    }

    /**
     * @param array{
     *     base_url: string|null,
     *     max_depth: int,
     *     timeout: int,
     *     user_agent: string,
     *     check_external: bool,
     *     exclude_patterns: list<string>,
     *     storage_dir: string|null,
     *     storage_max_reports: int,
     *     allow_private_network: bool,
     *     request_delay_ms: int,
     *     respect_robots_txt: bool,
     * } $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $container->parameters()
            ->set('link_checker.base_url', $config['base_url'])
            ->set('link_checker.max_depth', $config['max_depth'])
            ->set('link_checker.timeout', $config['timeout'])
            ->set('link_checker.user_agent', $config['user_agent'])
            ->set('link_checker.check_external', $config['check_external'])
            ->set('link_checker.exclude_patterns', $config['exclude_patterns'])
            ->set('link_checker.storage_dir', $config['storage_dir'])
            ->set('link_checker.storage_max_reports', $config['storage_max_reports'])
            ->set('link_checker.allow_private_network', $config['allow_private_network'])
            ->set('link_checker.request_delay_ms', $config['request_delay_ms'])
            ->set('link_checker.respect_robots_txt', $config['respect_robots_txt']);

        $privateNetworkGuardId = 'link_checker.http_client.private_network_guard';

        if ($config['allow_private_network']) {
            $builder->setAlias($privateNetworkGuardId, HttpClientInterface::class);
        } else {
            $builder->register($privateNetworkGuardId, NoPrivateNetworkHttpClient::class)
                ->setArguments([new Reference(HttpClientInterface::class)]);
        }

        $builder->register('link_checker.http_client', ThrottledHttpClient::class)
            ->setArguments([new Reference($privateNetworkGuardId), $config['request_delay_ms']]);

        if ($config['storage_dir'] === null || $config['storage_dir'] === '') {
            $builder->removeDefinition(JsonFileReportStorage::class);
            $builder->removeAlias(ReportStorageInterface::class);
        }
    }
}
