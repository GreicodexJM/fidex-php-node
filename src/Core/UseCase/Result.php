<?php

declare(strict_types=1);

namespace FideX\Core\UseCase;

/**
 * Use Case Result Value Object
 *
 * All use cases return a Result to avoid throwing exceptions for business failures.
 * This keeps control flow predictable and testable.
 *
 * Usage:
 *   $result = $useCase->execute($request);
 *   if ($result->isSuccess()) {
 *       $data = $result->getData();
 *   } else {
 *       $error = $result->getError();     // FideX error code string
 *       $message = $result->getMessage(); // Human-readable detail
 *   }
 */
final class Result
{
    // FideX error codes (maps to HTTP status codes in controllers)
    public const ERR_VALIDATION_ERROR    = 'VALIDATION_ERROR';      // 400
    public const ERR_UNKNOWN_PARTNER     = 'UNKNOWN_PARTNER';       // 401
    public const ERR_PARTNER_INACTIVE    = 'PARTNER_INACTIVE';      // 403
    public const ERR_MESSAGE_NOT_FOUND   = 'MESSAGE_NOT_FOUND';     // 404
    public const ERR_PAYLOAD_TOO_LARGE   = 'PAYLOAD_TOO_LARGE';     // 413
    public const ERR_RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';   // 429
    public const ERR_PROCESSING_ERROR    = 'PROCESSING_ERROR';      // 500
    public const ERR_CRYPTO_ERROR        = 'CRYPTO_ERROR';          // 500
    public const ERR_SIGNATURE_INVALID   = 'SIGNATURE_INVALID';     // 401
    public const ERR_DUPLICATE_MESSAGE   = 'DUPLICATE_MESSAGE';     // 409

    private function __construct(
        private readonly bool   $success,
        private readonly mixed  $data,
        private readonly string $error,
        private readonly string $message,
    ) {
    }

    /**
     * Create a successful result.
     *
     * @param mixed $data Any payload to return to the caller
     */
    public static function success(mixed $data = null): self
    {
        return new self(
            success: true,
            data: $data,
            error: '',
            message: '',
        );
    }

    /**
     * Create a failure result.
     *
     * @param string $error One of the ERR_* constants
     * @param string $message Human-readable detail
     */
    public static function failure(string $error, string $message = ''): self
    {
        return new self(
            success: false,
            data: null,
            error: $error,
            message: $message,
        );
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Map Result error codes to HTTP status codes.
     */
    public function toHttpStatusCode(): int
    {
        if ($this->success) {
            return 200;
        }

        return match ($this->error) {
            self::ERR_VALIDATION_ERROR    => 400,
            self::ERR_UNKNOWN_PARTNER     => 401,
            self::ERR_SIGNATURE_INVALID   => 401,
            self::ERR_PARTNER_INACTIVE    => 403,
            self::ERR_MESSAGE_NOT_FOUND   => 404,
            self::ERR_DUPLICATE_MESSAGE   => 409,
            self::ERR_PAYLOAD_TOO_LARGE   => 413,
            self::ERR_RATE_LIMIT_EXCEEDED => 429,
            default                       => 500,
        };
    }
}
