<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Http;

use FideX\Port\Outbound\HttpClientPort;
use FideX\Port\Outbound\HttpResponse;

/**
 * cURL HTTP Client
 *
 * Implements HttpClientPort using PHP's built-in cURL extension.
 * Zero external dependencies — works on any PHP host.
 *
 * Follows FideX transport requirements:
 * - TLS 1.2+ enforced
 * - Proper timeout settings
 * - Content-Type: application/json
 */
final class CurlHttpClient implements HttpClientPort
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
    ) {
    }

    public function post(string $url, array $payload, array $headers = []): HttpResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($body),
        ];

        return $this->execute($url, 'POST', $body, array_merge($defaultHeaders, $this->formatHeaders($headers)));
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $defaultHeaders = ['Accept: application/json'];
        return $this->execute($url, 'GET', null, array_merge($defaultHeaders, $this->formatHeaders($headers)));
    }

    /**
     * @param string[] $headers
     */
    private function execute(string $url, string $method, ?string $body, array $headers): HttpResponse
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_USERAGENT      => 'fidex-php/1.0',
            CURLOPT_HEADER         => false,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($ch);
        $statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);

        curl_close($ch);

        if ($responseBody === false) {
            // Connection error — return 0 status
            return new HttpResponse(0, 'cURL error: ' . $error);
        }

        return new HttpResponse($statusCode, (string) $responseBody);
    }

    /** @param array<string, string> $headers */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = "{$name}: {$value}";
        }
        return $formatted;
    }
}
