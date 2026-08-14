<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Tests\Checker;

use Lbonnet\LinkCheckerBundle\Checker\UrlChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class UrlCheckerTest extends TestCase
{
    public function testCheckSuccessfulHeadRequest(): void
    {
        $mockResponse = new MockResponse('', [
            'http_code' => Response::HTTP_OK,
            'response_headers' => ['content-type' => 'text/html; charset=UTF-8'],
        ]);
        $client = new MockHttpClient($mockResponse);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com');

        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->isBroken());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
        $this->assertSame('text/html; charset=UTF-8', $result->contentType);
        $this->assertNull($result->errorMessage);
    }

    public function testCheckFallbackToGetOn405(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => Response::HTTP_METHOD_NOT_ALLOWED]),
            new MockResponse('content', ['http_code' => Response::HTTP_OK]),
        ];
        $client = new MockHttpClient($responses);
        $checker = new UrlChecker($client, 5);

        $result = $checker->check('https://example.com/api');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame(Response::HTTP_OK, $result->statusCode);
    }

    public function testCheckHandlesTransportException(): void
    {
        $client = new MockHttpClient(static function () {
            throw new TransportException('Connection timeout');
        });
        $checker = new UrlChecker($client, 2);

        $result = $checker->check('https://timeout.com');

        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->isBroken());
        $this->assertNull($result->statusCode);
        $this->assertStringContainsString('Connection timeout', (string)$result->errorMessage);
    }
}
