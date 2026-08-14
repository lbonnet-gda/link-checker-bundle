<?php

declare(strict_types=1);

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

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
        ]);

    $services->set(UrlChecker::class)
        ->arg('$defaultTimeout', param('link_checker.timeout'));
};
