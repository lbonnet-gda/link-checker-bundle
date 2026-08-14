<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Services will be registered here as they are implemented
    // (link extractor, URL checker, crawler, console command...).
    $services->load('Lbonnet\\LinkCheckerBundle\\', '../src/')
        ->exclude([
            '../src/LinkCheckerBundle.php',
            '../src/DependencyInjection/',
        ]);
};
