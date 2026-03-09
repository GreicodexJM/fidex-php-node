<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

/**
 * Outbound Port: HTTP Client
 *
 * Defines how the domain core sends HTTP requests to partner nodes.
 * Uses pure cURL under the hood — no Guzzle dependency.
 * Implementation: CurlHttpClient
 */
interface HttpClientPort
{
    /**
     * Send an HTTP POST request with a JSON body.
     *
     * @param string $url Target URL
     * @param array<string, mixed> $payload Request body (will be JSON-encoded)
     * @param array<string, string> $headers Additional HTTP headers
     * @return HttpResponse
     */
    public function post(string $url, array $payload, array $headers = []): HttpResponse;

    /**
     * Send an HTTP GET request.
     *
     * @param string $url Target URL
     * @param array<string, string> $headers Additional HTTP headers
     * @return HttpResponse
     */
    public function get(string $url, array $headers = []): HttpResponse;
}
