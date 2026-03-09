<?php

declare(strict_types=1);

namespace FideX\Core\Domain;

/**
 * Receipt Value Object (J-MDN)
 *
 * Represents a JSON Message Disposition Notification.
 * Sent by the receiver back to the sender to acknowledge delivery.
 */
final class Receipt
{
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_FAILED    = 'FAILED';

    public function __construct(
        private readonly string  $originalMessageId,
        private readonly string  $status,
        private readonly string  $receiverId,
        private readonly string  $hashVerification,
        private readonly string  $timestamp,
        private readonly ?string $errorLog,
        private readonly string  $signature,
    ) {
    }

    public static function create(
        string  $originalMessageId,
        string  $status,
        string  $receiverId,
        string  $encryptedPayload,
        ?string $errorLog = null,
        string  $signature = '',
    ): self {
        return new self(
            originalMessageId: $originalMessageId,
            status: $status,
            receiverId: $receiverId,
            hashVerification: 'sha256:' . hash('sha256', $encryptedPayload),
            timestamp: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            errorLog: $errorLog,
            signature: $signature,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'original_message_id' => $this->originalMessageId,
            'status'              => $this->status,
            'receiver_id'         => $this->receiverId,
            'hash_verification'   => $this->hashVerification,
            'timestamp'           => $this->timestamp,
            'error_log'           => $this->errorLog,
            'signature'           => $this->signature,
        ];
    }

    public function withSignature(string $signature): self
    {
        return new self(
            originalMessageId: $this->originalMessageId,
            status: $this->status,
            receiverId: $this->receiverId,
            hashVerification: $this->hashVerification,
            timestamp: $this->timestamp,
            errorLog: $this->errorLog,
            signature: $signature,
        );
    }

    public function getOriginalMessageId(): string { return $this->originalMessageId; }
    public function getStatus(): string            { return $this->status; }
    public function getReceiverId(): string        { return $this->receiverId; }
    public function getHashVerification(): string  { return $this->hashVerification; }
    public function getTimestamp(): string         { return $this->timestamp; }
    public function getErrorLog(): ?string         { return $this->errorLog; }
    public function getSignature(): string         { return $this->signature; }
}
