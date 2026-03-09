<?php

declare(strict_types=1);

namespace FideX\Adapter\Outbound\Persistence;

use FideX\Core\Domain\Message;
use FideX\Core\Domain\MessageStatus;
use FideX\Port\Outbound\MessageRepositoryPort;

/**
 * SQLite Message Repository
 *
 * Implements MessageRepositoryPort using PDO + SQLite (or MySQL).
 * Maps between the Message entity and the fidex_messages table.
 */
final class SqliteMessageRepository implements MessageRepositoryPort
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
    }

    public function save(Message $message): void
    {
        $sql = <<<SQL
            INSERT INTO fidex_messages (
                message_id, sender_id, receiver_id, document_type, timestamp,
                status, direction, raw_payload, encrypted_payload,
                receipt_webhook, retry_count, error_message, created_at, updated_at
            ) VALUES (
                :message_id, :sender_id, :receiver_id, :document_type, :timestamp,
                :status, :direction, :raw_payload, :encrypted_payload,
                :receipt_webhook, :retry_count, :error_message, :created_at, :updated_at
            )
            ON CONFLICT(message_id) DO UPDATE SET
                status            = excluded.status,
                encrypted_payload = excluded.encrypted_payload,
                retry_count       = excluded.retry_count,
                error_message     = excluded.error_message,
                updated_at        = excluded.updated_at
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':message_id'        => $message->getMessageId(),
            ':sender_id'         => $message->getSenderId(),
            ':receiver_id'       => $message->getReceiverId(),
            ':document_type'     => $message->getDocumentType(),
            ':timestamp'         => $message->getTimestamp(),
            ':status'            => $message->getStatus()->value,
            ':direction'         => $message->getDirection(),
            ':raw_payload'       => json_encode($message->getRawPayload(), JSON_THROW_ON_ERROR),
            ':encrypted_payload' => $message->getEncryptedPayload(),
            ':receipt_webhook'   => $message->getReceiptWebhook(),
            ':retry_count'       => $message->getRetryCount(),
            ':error_message'     => $message->getErrorMessage(),
            ':created_at'        => $message->getCreatedAt()->format('Y-m-d\TH:i:s.v\Z'),
            ':updated_at'        => $message->getUpdatedAt()->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    }

    public function findById(string $messageId): ?Message
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fidex_messages WHERE message_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $messageId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByStatus(MessageStatus $status, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fidex_messages WHERE status = :status ORDER BY updated_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':status', $status->value);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function findPendingRetries(int $maxRetries, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM fidex_messages
             WHERE status = :status AND retry_count < :max_retries
             ORDER BY updated_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':status', MessageStatus::RETRY->value);
        $stmt->bindValue(':max_retries', $maxRetries, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'hydrate'], $stmt->fetchAll());
    }

    public function exists(string $messageId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM fidex_messages WHERE message_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Message
    {
        return Message::reconstitute(
            messageId: (string) $row['message_id'],
            senderId: (string) $row['sender_id'],
            receiverId: (string) $row['receiver_id'],
            documentType: (string) $row['document_type'],
            timestamp: (string) $row['timestamp'],
            status: MessageStatus::from((string) $row['status']),
            direction: (string) $row['direction'],
            rawPayload: json_decode((string) $row['raw_payload'], true, 512, JSON_THROW_ON_ERROR),
            encryptedPayload: (string) $row['encrypted_payload'],
            receiptWebhook: isset($row['receipt_webhook']) ? (string) $row['receipt_webhook'] : null,
            retryCount: (int) $row['retry_count'],
            errorMessage: isset($row['error_message']) ? (string) $row['error_message'] : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: new \DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
