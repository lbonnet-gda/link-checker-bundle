<?php

declare(strict_types=1);

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Lbonnet\LinkCheckerBundle\Command\CheckLinksCommand;
use Lbonnet\LinkCheckerBundle\Crawler\SiteCrawler;
use Lbonnet\LinkCheckerBundle\MessageHandler\CheckLinksMessageHandler;
use Lbonnet\LinkCheckerBundle\Storage\JsonFileReportStorage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Lbonnet\\LinkCheckerBundle\\', '../src/')
        ->exclude([
            '../src/LinkCheckerBundle.php',
            '../src/DependencyInjection/',
            '../src/Model/',
            '../src/Event/',
            '../src/Message/',
        ]);

    $services->set(UrlChecker::class)
        ->arg('$httpClient', service('link_checker.http_client'))
        ->arg('$defaultTimeout', param('link_checker.timeout'))
        ->arg('$userAgent', param('link_checker.user_agent'));

    $services->set(SiteCrawler::class)
        ->arg('$httpClient', service('link_checker.http_client'))
        ->arg('$defaultMaxDepth', param('link_checker.max_depth'))
        ->arg('$defaultCheckExternal', param('link_checker.check_external'))
        ->arg('$defaultExcludePatterns', param('link_checker.exclude_patterns'));

    $services->set(CheckLinksCommand::class)
        ->arg('$defaultBaseUrl', param('link_checker.base_url'));

    $services->set(CheckLinksMessageHandler::class)
        ->arg('$defaultBaseUrl', param('link_checker.base_url'));

    $services->set(JsonFileReportStorage::class)
        ->arg('$storageDirectory', param('link_checker.storage_dir'));
};
