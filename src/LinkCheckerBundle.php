<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle;

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

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
            ->set('link_checker.storage_dir', $config['storage_dir']);
    }
}
