<?php

declare(strict_types=1);

namespace Lbonnet\LinkCheckerBundle\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BoundedContentReader
{
    /**
     * Reads the body up to $maxLength bytes, cancelling the request past that instead of buffering it whole.
     * @throws TransportExceptionInterface
     */
    public static function read(HttpClientInterface $httpClient, ResponseInterface $response, int $maxLength): string
    {
        $content = '';

        foreach ($httpClient->stream($response) as $chunk) {
            $content .= $chunk->getContent();

            if (strlen($content) > $maxLength) {
                $response->cancel();
                break;
            }
        }

        return $content;
    }
}
