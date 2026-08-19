<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests;

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use Lbonnet\LinkCheckerBundle\Checker\UrlCheckerInterface;
use Lbonnet\LinkCheckerBundle\Command\CheckLinksCommand;
use Lbonnet\LinkCheckerBundle\Crawler\CrawlerInterface;
use Lbonnet\LinkCheckerBundle\Crawler\SiteCrawler;
use Lbonnet\LinkCheckerBundle\Extractor\HtmlLinkExtractor;
use Lbonnet\LinkCheckerBundle\Extractor\LinkExtractorInterface;
use Lbonnet\LinkCheckerBundle\LinkCheckerBundle;
use Lbonnet\LinkCheckerBundle\MessageHandler\CheckLinksMessageHandler;
use Lbonnet\LinkCheckerBundle\Storage\JsonFileReportStorage;
use Lbonnet\LinkCheckerBundle\Storage\ReportStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LinkCheckerBundleTest extends TestCase
{
    public function testDefaultConfigurationAndParameters(): void
    {
        $container = $this->createContainer();
        $bundle = new LinkCheckerBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load([], $container);

        $this->assertNull($container->getParameter('link_checker.base_url'));
        $this->assertSame(3, $container->getParameter('link_checker.max_depth'));
        $this->assertSame(10, $container->getParameter('link_checker.timeout'));
        $this->assertSame(UrlChecker::DEFAULT_USER_AGENT, $container->getParameter('link_checker.user_agent'));
        $this->assertTrue($container->getParameter('link_checker.check_external'));
        $this->assertSame([], $container->getParameter('link_checker.exclude_patterns'));
        $this->assertSame(
            '%kernel.project_dir%/var/link_checker',
            $container->getParameter('link_checker.storage_dir')
        );
        $this->assertFalse($container->getParameter('link_checker.allow_private_network'));

        $this->assertTrue($container->hasDefinition('link_checker.http_client'));
        $this->assertSame(
            NoPrivateNetworkHttpClient::class,
            $container->getDefinition('link_checker.http_client')->getClass()
        );
    }

    public function testAllowPrivateNetworkDisablesSsrfProtection(): void
    {
        $container = $this->createContainer();
        $bundle = new LinkCheckerBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertNotNull($extension);

        $extension->load(['link_checker' => ['allow_private_network' => true]], $container);

        $this->assertTrue($container->getParameter('link_checker.allow_private_network'));
        $this->assertTrue($container->hasAlias('link_checker.http_client'));
        $this->assertSame(
            HttpClientInterface::class,
            (string)$container->getAlias('link_checker.http_client')
        );
    }

    public function testCustomConfigurationAndServiceRegistration(): void
    {
        $container = $this->createContainer();
        $bundle = new LinkCheckerBundle();
        $extension = $bundle->getContainerExtension();

        $this->assertNotNull($extension);

        $customConfig = [
            'link_checker' => [
                'base_url' => 'https://example.com',
                'max_depth' => 5,
                'timeout' => 20,
                'user_agent' => 'CustomBot/2.0',
                'check_external' => false,
                'storage_dir' => '/custom/storage/path',
                'exclude_patterns' => [
                    '#/admin#',
                    '#/logout#',
                ],
            ],
        ];

        $extension->load($customConfig, $container);

        $this->assertSame('https://example.com', $container->getParameter('link_checker.base_url'));
        $this->assertSame(5, $container->getParameter('link_checker.max_depth'));
        $this->assertSame(20, $container->getParameter('link_checker.timeout'));
        $this->assertSame('CustomBot/2.0', $container->getParameter('link_checker.user_agent'));
        $this->assertFalse($container->getParameter('link_checker.check_external'));
        $this->assertSame(['#/admin#', '#/logout#'], $container->getParameter('link_checker.exclude_patterns'));
        $this->assertSame('/custom/storage/path', $container->getParameter('link_checker.storage_dir'));

        $this->assertTrue($container->hasDefinition(HtmlLinkExtractor::class));
        $this->assertTrue($container->hasDefinition(UrlChecker::class));
        $this->assertTrue($container->hasDefinition(SiteCrawler::class));
        $this->assertTrue($container->hasDefinition(CheckLinksCommand::class));
        $this->assertTrue($container->hasDefinition(CheckLinksMessageHandler::class));
        $this->assertTrue($container->hasDefinition(JsonFileReportStorage::class));

        $this->assertTrue(
            $container->hasAlias(LinkExtractorInterface::class)
            || $container->hasDefinition(LinkExtractorInterface::class)
        );
        $this->assertTrue(
            $container->hasAlias(UrlCheckerInterface::class)
            || $container->hasDefinition(UrlCheckerInterface::class)
        );
        $this->assertTrue(
            $container->hasAlias(CrawlerInterface::class)
            || $container->hasDefinition(CrawlerInterface::class)
        );
        $this->assertTrue(
            $container->hasAlias(ReportStorageInterface::class)
            || $container->hasDefinition(ReportStorageInterface::class)
        );
    }

    private function createContainer(): ContainerBuilder
    {
        $tempDir = sys_get_temp_dir();

        return new ContainerBuilder(new ParameterBag([
            'kernel.debug' => false,
            'kernel.project_dir' => $tempDir,
            'kernel.build_dir' => $tempDir,
            'kernel.cache_dir' => $tempDir,
            'kernel.charset' => 'UTF-8',
            'kernel.environment' => 'test',
        ]));
    }
}
