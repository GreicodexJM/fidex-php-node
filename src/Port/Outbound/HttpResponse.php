<?php

declare(strict_types=1);

namespace FideX\Port\Outbound;

/**
 * HTTP Response Value Object
 *
 * Returned by HttpClientPort implementations.
 * Immutable, framework-free.
 */
final class HttpResponse
{
    public function __construct(
        private readonly int     $statusCode,
        private readonly string  $body,
        private readonly array   $headers = [],
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isAccepted(): bool
    {
        return $this->statusCode === 202;
    }

    /**
     * Decode JSON body, returns null on failure.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
