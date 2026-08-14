<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Checker;

use Lbonnet\LinkCheckerBundle\Model\CheckResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class UrlChecker implements UrlCheckerInterface
{
    private const FALLBACK_STATUS_CODES = [
        Response::HTTP_FORBIDDEN,
        Response::HTTP_METHOD_NOT_ALLOWED,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultTimeout = 10,
    ) {
    }

    public function check(string $url, ?int $timeout = null): CheckResult
    {
        $requestTimeout = $timeout ?? $this->defaultTimeout;
        $startTime = microtime(true);

        try {
            $response = $this->httpClient->request(Request::METHOD_HEAD, $url, [
                'timeout' => $requestTimeout,
                'max_redirects' => 5,
            ]);

            $statusCode = $response->getStatusCode();

            if (in_array($statusCode, self::FALLBACK_STATUS_CODES, true)) {
                $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                    'timeout' => $requestTimeout,
                    'max_redirects' => 5,
                    'headers' => [
                        'Range' => 'bytes=0-1024',
                    ],
                ]);
                $statusCode = $response->getStatusCode();
            }

            $duration = microtime(true) - $startTime;
            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $redirectUrl = $response->getInfo('redirect_url');

            return new CheckResult(
                url: $url,
                statusCode: $statusCode,
                duration: round($duration, 3),
                redirectUrl: $redirectUrl ?: null,
                contentType: $contentType
            );
        } catch (TransportExceptionInterface $e) {
            $duration = microtime(true) - $startTime;

            return new CheckResult(
                url: $url,
                statusCode: null,
                duration: round($duration, 3),
                errorMessage: $e->getMessage()
            );
        } catch (Throwable $e) {
            $duration = microtime(true) - $startTime;

            return new CheckResult(
                url: $url,
                statusCode: null,
                duration: round($duration, 3),
                errorMessage: 'Unexpected error: '.$e->getMessage()
            );
        }
    }
}
