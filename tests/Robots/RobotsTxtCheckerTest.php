<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Robots;

use Lbonnet\LinkCheckerBundle\Robots\RobotsTxtChecker;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class RobotsTxtCheckerTest extends TestCase
{
    public function testAllowsEverythingWhenDisabled(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new LogicException('robots.txt should not be fetched when disabled');
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testAllowsEverythingWhenRobotsTxtIsMissing(): void
    {
        $client = new MockHttpClient(static fn() => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND]));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testAllowsEverythingWhenFetchFails(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/anything'));
    }

    public function testDisallowsMatchingPathUnderWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/admin/settings'));
        $this->assertTrue($checker->isAllowed('https://example.com/blog'));
    }

    public function testMostSpecificRuleWins(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\nAllow: /admin/public\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/admin/secret'));
        $this->assertTrue($checker->isAllowed('https://example.com/admin/public/page'));
    }

    public function testUserAgentSpecificGroupOverridesWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /\nUser-agent: TestBot\nDisallow: /private\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/public'));
        $this->assertFalse($checker->isAllowed('https://example.com/private/data'));
    }

    public function testSupportsWildcardAndEndAnchorPatterns(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /files/*.pdf$\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertFalse($checker->isAllowed('https://example.com/files/report.pdf'));
        $this->assertTrue($checker->isAllowed('https://example.com/files/report.txt'));
    }

    public function testTruncatesRobotsTxtBeyondTheSizeCap(): void
    {
        $padding = str_repeat("# padding\n", 50_001);
        $body = (static function () use ($padding) {
            yield $padding;
            yield "User-agent: *\nDisallow: /late\n";
        })();

        $client = new MockHttpClient(static fn() => new MockResponse($body));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertTrue($checker->isAllowed('https://example.com/late'));
    }

    public function testCrawlDelayIsNullWhenNotSpecified(): void
    {
        $robotsTxt = "User-agent: *\nDisallow: /admin\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertNull($checker->crawlDelay('https://example.com/'));
    }

    public function testCrawlDelayIsNullWhenDisabled(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new LogicException('robots.txt should not be fetched when disabled');
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0', enabled: false);

        $this->assertNull($checker->crawlDelay('https://example.com/'));
    }

    public function testParsesCrawlDelayForWildcardGroup(): void
    {
        $robotsTxt = "User-agent: *\nCrawl-delay: 10\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertSame(10.0, $checker->crawlDelay('https://example.com/'));
    }

    public function testUserAgentSpecificCrawlDelayOverridesWildcard(): void
    {
        $robotsTxt = "User-agent: *\nCrawl-delay: 10\nUser-agent: TestBot\nCrawl-delay: 2\n";
        $client = new MockHttpClient(static fn() => new MockResponse($robotsTxt));
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $this->assertSame(2.0, $checker->crawlDelay('https://example.com/'));
    }

    public function testFetchesRobotsTxtOnlyOncePerHost(): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls): MockResponse {
            $calls++;

            return new MockResponse("User-agent: *\nDisallow: /admin\n");
        });
        $checker = new RobotsTxtChecker($client, 'TestBot/1.0');

        $checker->isAllowed('https://example.com/admin');
        $checker->isAllowed('https://example.com/other');

        $this->assertSame(1, $calls);
    }
}
