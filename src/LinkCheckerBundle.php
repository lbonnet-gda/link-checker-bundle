<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle;

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Lbonnet\LinkCheckerBundle\Http\ThrottledHttpClient;
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
        // @formatter:off
        $definition->rootNode()
            ->children()
                ->scalarNode('base_url')
                    ->defaultNull()
                    ->info('Default base URL to crawl when none is passed to the command.')
                ->end()
                ->integerNode('max_depth')
                    ->defaultValue(3)
                    ->min(0)
                    ->info('Maximum crawl depth from the starting URL.')
                ->end()
                ->integerNode('timeout')
                    ->defaultValue(10)
                    ->min(1)
                    ->info('Per-request timeout in seconds when checking a URL.')
                ->end()
                ->scalarNode('user_agent')
                    ->defaultValue(UrlChecker::DEFAULT_USER_AGENT)
                    ->info('User-Agent header sent when checking a URL. Identify your crawler honestly; do not spoof a browser UA to bypass bot protection.')
                ->end()
                ->booleanNode('check_external')
                    ->defaultTrue()
                    ->info('Whether to check links pointing outside the base host.')
                ->end()
                ->arrayNode('exclude_patterns')
                    ->scalarPrototype()->end()
                    ->info('Regular expression patterns for URLs to skip.')
                ->end()
                ->scalarNode('storage_dir')
                    ->defaultValue('%kernel.project_dir%/var/link_checker')
                    ->info('Directory where crawl reports in JSON will be stored. Set to empty or null to disable.')
                ->end()
                ->booleanNode('allow_private_network')
                    ->defaultFalse()
                    ->info('Allow requests to URLs resolving to private/loopback/link-local IP ranges (e.g. 127.0.0.1, 10.0.0.0/8, cloud metadata endpoints). The crawler follows links found on the pages it visits, so leaving this disabled (default) prevents SSRF if it ever crawls untrusted or third-party content. Enable only to intentionally audit an internal network.')
                ->end()
                ->integerNode('request_delay_ms')
                    ->defaultValue(200)
                    ->min(0)
                    ->info('Minimum delay, in milliseconds, enforced between consecutive requests to the same host. The host you\'re crawling (the "url" argument/"base_url") is always exempt, so this only slows down requests to other hosts — chiefly external links you don\'t control. Set to 0 to disable throttling entirely, including for external hosts.')
                ->end()
                ->booleanNode('respect_robots_txt')
                    ->defaultTrue()
                    ->info('Fetch and honor the crawled site\'s robots.txt: matching Disallow rules stop the crawler from following/checking further internal links under that path. Does not affect single-status checks of external links, and does not apply to the URL you explicitly start the crawl from.')
                ->end()
            ->end()
        ;
        // @formatter:on
    }

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
    }
}
