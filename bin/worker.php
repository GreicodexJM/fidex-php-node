#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FideX PHP — Queue Worker
 *
 * Processes async jobs from the fidex_queue table.
 *
 * Jobs handled:
 *   - transmit_message: POST JWE envelope to partner's receive_endpoint
 *   - process_inbound:  decrypt JWE, dispatch J-MDN receipt
 *   - send_jmdn:        POST J-MDN to partner's receipt_endpoint
 *
 * Run manually: php bin/worker.php
 * Run via cron: * * * * * /usr/bin/php /path/to/bin/worker.php >> /tmp/fidex-worker.log 2>&1
 *
 * On cPanel: add to crontab in cPanel > Cron Jobs
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use FideX\Core\Domain\Receipt;
use FideX\Port\Outbound\QueuePort;

$app = require dirname(__DIR__) . '/config/bootstrap.php';

/** @var \FideX\Adapter\Outbound\Queue\DatabaseQueue $queue */
$queue       = $app['queue'];
/** @var \FideX\Port\Outbound\MessageRepositoryPort $messageRepo */
$messageRepo = $app['messageRepo'];
/** @var \FideX\Port\Outbound\PartnerRepositoryPort $partnerRepo */
$partnerRepo = $app['partnerRepo'];
/** @var \FideX\Port\Outbound\CryptoServicePort $crypto */
$crypto      = $app['cryptoService'];
/** @var \FideX\Adapter\Outbound\Http\CurlHttpClient $http */
$http        = $app['queue']; // Use direct access from app
$http        = new \FideX\Adapter\Outbound\Http\CurlHttpClient();
/** @var \FideX\Port\Outbound\LoggerPort $logger */
$logger      = $app['logger'];
/** @var ?\FideX\Core\Domain\KeyPair $signingKeyPair */
$signingKeyPair    = $app['signingKeyPair'];
/** @var ?\FideX\Core\Domain\KeyPair $encryptionKeyPair */
$encryptionKeyPair = $app['encryptionKeyPair'];
$nodeId            = $_ENV['FIDEX_NODE_ID'] ?? getenv('FIDEX_NODE_ID') ?: 'urn:custom:fidex-node';

$maxRetries = 5;
$batchSize  = 20;

$jobs = $queue->fetchPending([], $batchSize);

if (empty($jobs)) {
    exit(0);
}

$logger->info('Worker: processing jobs', ['count' => count($jobs)]);

foreach ($jobs as $job) {
    try {
        match ($job->getJobType()) {
            QueuePort::JOB_TRANSMIT_MESSAGE => handleTransmit($job, $messageRepo, $partnerRepo, $queue, $http, $logger),
            QueuePort::JOB_PROCESS_INBOUND  => handleInbound($job, $messageRepo, $partnerRepo, $queue, $crypto, $http, $logger, $signingKeyPair, $encryptionKeyPair, $nodeId),
            QueuePort::JOB_SEND_JMDN        => handleSendJmdn($job, $messageRepo, $partnerRepo, $queue, $http, $logger),
            default => $logger->warning('Worker: unknown job type', ['type' => $job->getJobType()]),
        };
    } catch (\Throwable $e) {
        $logger->error('Worker: job failed', [
            'job_id'  => $job->getId(),
            'job_type' => $job->getJobType(),
            'error'   => $e->getMessage(),
        ]);

        if ($job->getRetryCount() >= $maxRetries) {
            $queue->markDone($job->getId()); // Discard permanently failed jobs
        } else {
            $queue->markFailed($job->getId(), $e->getMessage());
        }
    }
}

// ─── Job handlers ─────────────────────────────────────────────────────────────

function handleTransmit(
    \FideX\Port\Outbound\QueueJob $job,
    $messageRepo, $partnerRepo, $queue, $http, $logger
): void {
    $payload    = $job->getPayload();
    $messageId  = $payload['message_id'];

    $message = $messageRepo->findById($messageId);
    if ($message === null) {
        throw new \RuntimeException("Message {$messageId} not found.");
    }

    $partner = $partnerRepo->findById($message->getReceiverId());
    if ($partner === null) {
        throw new \RuntimeException("Partner {$message->getReceiverId()} not found.");
    }

    $envelope = [
        'routing_header' => [
            'fidex_version' => '1.0',
            'message_id'    => $message->getMessageId(),
            'sender_id'     => $message->getSenderId(),
            'receiver_id'   => $message->getReceiverId(),
            'document_type' => $message->getDocumentType(),
            'timestamp'     => $message->getTimestamp(),
            'payload_digest' => 'sha256:' . hash('sha256', $message->getEncryptedPayload()),
        ],
        'encrypted_payload' => $message->getEncryptedPayload(),
    ];

    if ($message->getReceiptWebhook() !== null) {
        $envelope['routing_header']['receipt_webhook'] = $message->getReceiptWebhook();
    }

    $response = $http->post($partner->getReceiveEndpoint(), $envelope);

    if ($response->isAccepted() || $response->isSuccess()) {
        $message->markSent();
        $messageRepo->save($message);
        $queue->markDone($job->getId());
        $logger->info('Worker: message sent', ['message_id' => $messageId, 'status' => $response->getStatusCode()]);
    } else {
        throw new \RuntimeException(
            "HTTP {$response->getStatusCode()} from {$partner->getReceiveEndpoint()}: {$response->getBody()}"
        );
    }
}

function handleInbound(
    \FideX\Port\Outbound\QueueJob $job,
    $messageRepo, $partnerRepo, $queue, $crypto, $http, $logger,
    $signingKeyPair, $encryptionKeyPair, string $nodeId
): void {
    $messageId = $job->getPayload()['message_id'];
    $message   = $messageRepo->findById($messageId);

    if ($message === null) {
        throw new \RuntimeException("Message {$messageId} not found.");
    }

    $partner = $partnerRepo->findById($message->getSenderId());

    // Decrypt the JWE
    if ($encryptionKeyPair === null) {
        throw new \RuntimeException('Encryption key pair not configured.');
    }

    $jws = $crypto->decrypt($message->getEncryptedPayload(), $encryptionKeyPair);

    // Verify signature if partner JWKS cached
    if ($partner !== null && $partner->getCachedJwks() !== null) {
        $jwks = json_decode($partner->getCachedJwks(), true);
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['use'] ?? '') === 'sig') {
                // Could verify here; skip for now
                break;
            }
        }
    }

    $message->markDecrypted();
    $messageRepo->save($message);

    // Enqueue J-MDN dispatch
    $queue->enqueue(QueuePort::JOB_SEND_JMDN, ['message_id' => $messageId]);
    $queue->markDone($job->getId());

    $logger->info('Worker: inbound decrypted', ['message_id' => $messageId]);
}

function handleSendJmdn(
    \FideX\Port\Outbound\QueueJob $job,
    $messageRepo, $partnerRepo, $queue, $http, $logger
): void {
    $messageId = $job->getPayload()['message_id'];
    $message   = $messageRepo->findById($messageId);

    if ($message === null) {
        throw new \RuntimeException("Message {$messageId} not found for J-MDN.");
    }

    $partner = $partnerRepo->findById($message->getSenderId());

    $receipt = Receipt::create(
        originalMessageId: $messageId,
        status: Receipt::STATUS_DELIVERED,
        receiverId: $message->getReceiverId(),
        encryptedPayload: $message->getEncryptedPayload(),
    );

    $receiptEndpoint = $partner?->getReceiptEndpoint()
        ?? $message->getReceiptWebhook();

    if ($receiptEndpoint === null) {
        $logger->warning('Worker: no receipt endpoint, skipping J-MDN', ['message_id' => $messageId]);
        $message->markDelivered();
        $messageRepo->save($message);
        $queue->markDone($job->getId());
        return;
    }

    $response = $http->post($receiptEndpoint, $receipt->toArray());

    if ($response->isSuccess()) {
        $message->markDelivered();
        $messageRepo->save($message);
        $queue->markDone($job->getId());
        $logger->info('Worker: J-MDN sent', ['message_id' => $messageId, 'endpoint' => $receiptEndpoint]);
    } else {
        throw new \RuntimeException(
            "J-MDN delivery failed HTTP {$response->getStatusCode()}: {$response->getBody()}"
        );
    }
}
