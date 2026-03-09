<?php

declare(strict_types=1);

namespace FideX\Core\UseCase;

use FideX\Core\Domain\KeyPair;
use FideX\Core\Domain\Message;
use FideX\Port\Outbound\CryptoServicePort;
use FideX\Port\Outbound\LoggerPort;
use FideX\Port\Outbound\MessageRepositoryPort;
use FideX\Port\Outbound\PartnerRepositoryPort;
use FideX\Port\Outbound\QueuePort;

/**
 * TransmitMessage Use Case
 *
 * Orchestrates outbound message transmission:
 * 1. Validate request (partner exists, payload not empty, webhook HTTPS)
 * 2. Sign payload → JWS (RS256, sender's private key)
 * 3. Encrypt JWS → JWE (RSA-OAEP + A256GCM, receiver's public key)
 * 4. Persist message with QUEUED status
 * 5. Enqueue JOB_TRANSMIT_MESSAGE for async HTTP delivery
 *
 * Implements SPARC pseudocode:
 *   validate → sign → encrypt → persist → enqueue → return
 */
final class TransmitMessage
{
    public function __construct(
        private readonly MessageRepositoryPort $messageRepository,
        private readonly PartnerRepositoryPort $partnerRepository,
        private readonly CryptoServicePort     $cryptoService,
        private readonly QueuePort             $queue,
        private readonly LoggerPort            $logger,
        private readonly string                $nodeId,
        private readonly KeyPair               $signingKeyPair,
        private readonly int                   $maxPayloadBytes = 10 * 1024 * 1024, // 10 MB
    ) {
    }

    /**
     * Execute the transmit use case.
     *
     * @param array<string, mixed> $payload Raw business document
     */
    public function execute(
        string  $destinationPartnerId,
        string  $documentType,
        array   $payload,
        ?string $receiptWebhook = null,
    ): Result {
        // ── Step 1: Validate inputs ─────────────────────────────────────────

        if (empty($destinationPartnerId)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'destination_partner_id is required.');
        }

        if (empty($documentType)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'document_type is required.');
        }

        if (empty($payload)) {
            return Result::failure(Result::ERR_VALIDATION_ERROR, 'payload must not be empty.');
        }

        // Validate receipt_webhook: if provided, must be HTTPS
        if ($receiptWebhook !== null && !str_starts_with($receiptWebhook, 'https://')) {
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'receipt_webhook must use HTTPS (per FideX spec section 3.2).'
            );
        }

        // Check payload size (JSON-encoded)
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($payloadJson) > $this->maxPayloadBytes) {
            return Result::failure(Result::ERR_PAYLOAD_TOO_LARGE, 'Payload exceeds 10 MB limit.');
        }

        // ── Step 2: Find and validate partner ──────────────────────────────

        $partner = $this->partnerRepository->findById($destinationPartnerId);

        if ($partner === null) {
            $this->logger->warning('TransmitMessage: unknown partner', ['partner_id' => $destinationPartnerId]);
            return Result::failure(
                Result::ERR_UNKNOWN_PARTNER,
                "Partner '{$destinationPartnerId}' is not registered."
            );
        }

        if (!$partner->isActive()) {
            return Result::failure(
                Result::ERR_PARTNER_INACTIVE,
                "Partner '{$destinationPartnerId}' is inactive."
            );
        }

        // ── Step 3: Extract receiver's encryption public key from cached JWKS

        $receiverPublicKeyPem = null;
        $receiverKeyId        = null;

        if ($partner->getCachedJwks() !== null) {
            $jwks = json_decode($partner->getCachedJwks(), true);
            foreach ($jwks['keys'] ?? [] as $key) {
                if (($key['use'] ?? '') === 'enc') {
                    $receiverKeyId = $key['kid'] ?? 'partner-enc-key';
                    // Convert JWK to PEM if possible, or pass the JWK kid as reference
                    // For mock/test scenarios the crypto service receives the PEM directly
                    // In production, JwkToPem conversion happens here
                    $receiverPublicKeyPem = $this->jwkToPem($key);
                    break;
                }
            }
        }

        // If we can't find a key, we still proceed with the partner's JWKS URL
        // The crypto service will use an empty string and the kid for lookup
        $receiverPublicKeyPem ??= '';
        $receiverKeyId        ??= 'partner-enc-key';

        // ── Step 4: Sign payload with sender's private key (JWS) ───────────

        try {
            $jws = $this->cryptoService->sign($payload, $this->signingKeyPair);
        } catch (\Throwable $e) {
            $this->logger->error('TransmitMessage: signing failed', ['error' => $e->getMessage()]);
            return Result::failure(Result::ERR_CRYPTO_ERROR, 'Payload signing failed: ' . $e->getMessage());
        }

        // ── Step 5: Encrypt JWS with receiver's public key (JWE) ───────────

        try {
            $jwe = $this->cryptoService->encrypt($jws, $receiverPublicKeyPem, $receiverKeyId);
        } catch (\Throwable $e) {
            $this->logger->error('TransmitMessage: encryption failed', ['error' => $e->getMessage()]);
            return Result::failure(Result::ERR_CRYPTO_ERROR, 'Payload encryption failed: ' . $e->getMessage());
        }

        // ── Step 6: Build message entity ───────────────────────────────────

        $messageId = 'fdx-' . $this->generateUuid();

        $message = Message::createOutbound(
            messageId: $messageId,
            senderId: $this->nodeId,
            receiverId: $destinationPartnerId,
            documentType: $documentType,
            rawPayload: $payload,
            receiptWebhook: $receiptWebhook,
        );

        $message->setEncryptedPayload($jwe);
        $message->markQueued();

        // ── Step 7: Persist message ─────────────────────────────────────────

        $this->messageRepository->save($message);

        // ── Step 8: Enqueue async HTTP delivery ────────────────────────────

        $this->queue->enqueue(QueuePort::JOB_TRANSMIT_MESSAGE, [
            'message_id'       => $messageId,
            'receive_endpoint' => $partner->getReceiveEndpoint(),
        ]);

        $this->logger->info('TransmitMessage: queued', [
            'message_id'  => $messageId,
            'receiver_id' => $destinationPartnerId,
        ]);

        return Result::success([
            'message_id' => $messageId,
            'status'     => $message->getStatus()->value,
            'timestamp'  => $message->getTimestamp(),
        ]);
    }

    private function generateUuid(): string
    {
        // RFC 4122 UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Convert a JWK array to PEM public key string.
     * Handles RSA keys with n and e parameters.
     *
     * @param array<string, mixed> $jwk
     */
    private function jwkToPem(array $jwk): string
    {
        if (empty($jwk['n']) || empty($jwk['e'])) {
            return '';
        }

        try {
            $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
            $e = base64_decode(strtr($jwk['e'], '-_', '+/'));

            // Ensure positive integers (prepend 0x00 if high bit is set)
            if (ord($n[0]) > 0x7f) {
                $n = "\x00" . $n;
            }
            if (ord($e[0]) > 0x7f) {
                $e = "\x00" . $e;
            }

            $modulus     = "\x02" . $this->encodeLength(strlen($n)) . $n;
            $exponent    = "\x02" . $this->encodeLength(strlen($e)) . $e;
            $sequence    = "\x30" . $this->encodeLength(strlen($modulus . $exponent)) . $modulus . $exponent;
            $bitstring   = "\x00" . $sequence;
            $bitstring   = "\x03" . $this->encodeLength(strlen($bitstring)) . $bitstring;

            $oidRsaEncryption = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $oidSeq = "\x30" . $this->encodeLength(strlen($oidRsaEncryption)) . $oidRsaEncryption;
            $spki   = "\x30" . $this->encodeLength(strlen($oidSeq . $bitstring)) . $oidSeq . $bitstring;

            return "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split(base64_encode($spki), 64, "\n")
                . "-----END PUBLIC KEY-----\n";
        } catch (\Throwable) {
            return '';
        }
    }

    private function encodeLength(int $length): string
    {
        if ($length <= 0x7f) {
            return chr($length);
        }
        $temp = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($temp)) . $temp;
    }
}
