<?php

declare(strict_types=1);

namespace FideX\Core\UseCase;

use FideX\Core\Domain\KeyPair;
use FideX\Core\Domain\Receipt;
use FideX\Port\Outbound\CryptoServicePort;
use FideX\Port\Outbound\LoggerPort;
use FideX\Port\Outbound\MessageRepositoryPort;
use FideX\Port\Outbound\PartnerRepositoryPort;

/**
 * ProcessReceipt Use Case
 *
 * Handles inbound J-MDN receipts received at POST /api/v1/receipt.
 *
 * 1. Validate J-MDN structure
 * 2. Find the original outbound message
 * 3. Find the partner (receipt signer)
 * 4. Verify J-MDN signature using sender's public key from JWKS
 * 5. Verify hash_verification matches stored encrypted_payload
 * 6. Update original message status to ACKNOWLEDGED or FAILED
 */
final class ProcessReceipt
{
    public function __construct(
        private readonly MessageRepositoryPort $messageRepository,
        private readonly PartnerRepositoryPort $partnerRepository,
        private readonly CryptoServicePort     $cryptoService,
        private readonly LoggerPort            $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $jmdn J-MDN payload from partner
     */
    public function execute(array $jmdn): Result
    {
        // ── Step 1: Validate J-MDN structure ──────────────────────────────

        $required = ['original_message_id', 'status', 'receiver_id', 'hash_verification', 'timestamp', 'signature'];
        foreach ($required as $field) {
            if (empty($jmdn[$field])) {
                return Result::failure(Result::ERR_VALIDATION_ERROR, "J-MDN field '{$field}' is required.");
            }
        }

        $originalMessageId = (string) $jmdn['original_message_id'];
        $status            = (string) $jmdn['status'];
        $receiverId        = (string) $jmdn['receiver_id'];
        $hashVerification  = (string) $jmdn['hash_verification'];
        $signature         = (string) $jmdn['signature'];

        // ── Step 2: Find the original outbound message ─────────────────────

        $message = $this->messageRepository->findById($originalMessageId);

        if ($message === null) {
            return Result::failure(
                Result::ERR_MESSAGE_NOT_FOUND,
                "Original message '{$originalMessageId}' not found."
            );
        }

        // ── Step 3: Find the partner (who signed the receipt) ──────────────

        $partner = $this->partnerRepository->findById($receiverId);

        if ($partner === null) {
            return Result::failure(
                Result::ERR_UNKNOWN_PARTNER,
                "Receipt signer '{$receiverId}' is not a registered partner."
            );
        }

        // ── Step 4: Verify J-MDN signature ────────────────────────────────

        $signerPublicKeyPem = $this->extractSigningPublicKeyPem($partner->getCachedJwks());

        if ($signerPublicKeyPem !== null) {
            $isValid = $this->cryptoService->verifyReceipt($signature, $signerPublicKeyPem);

            if (!$isValid) {
                $this->logger->warning('ProcessReceipt: invalid signature', [
                    'original_message_id' => $originalMessageId,
                    'receiver_id'         => $receiverId,
                ]);
                return Result::failure(
                    Result::ERR_SIGNATURE_INVALID,
                    'J-MDN signature verification failed.'
                );
            }
        }

        // ── Step 5: Verify hash_verification ──────────────────────────────

        $expectedHash = 'sha256:' . hash('sha256', $message->getEncryptedPayload());
        if ($hashVerification !== $expectedHash) {
            $this->logger->warning('ProcessReceipt: hash mismatch', [
                'original_message_id' => $originalMessageId,
                'expected'            => $expectedHash,
                'received'            => $hashVerification,
            ]);
            return Result::failure(
                Result::ERR_VALIDATION_ERROR,
                'hash_verification does not match original encrypted payload.'
            );
        }

        // ── Step 6: Update message status ──────────────────────────────────

        if ($status === Receipt::STATUS_DELIVERED) {
            $message->markAcknowledged();
            $this->logger->info('ProcessReceipt: message acknowledged', [
                'original_message_id' => $originalMessageId,
            ]);
        } else {
            $message->markFailed("J-MDN reported status: {$status}. Error: " . ($jmdn['error_log'] ?? 'none'));
            $this->logger->warning('ProcessReceipt: message delivery failed per J-MDN', [
                'original_message_id' => $originalMessageId,
                'status'              => $status,
            ]);
        }

        $this->messageRepository->save($message);

        return Result::success(['original_message_id' => $originalMessageId, 'status' => $status]);
    }

    /**
     * Extract the first signing (use=sig) public key PEM from cached JWKS JSON.
     */
    private function extractSigningPublicKeyPem(?string $cachedJwks): ?string
    {
        if ($cachedJwks === null) {
            return null;
        }

        $jwks = json_decode($cachedJwks, true);
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['use'] ?? '') === 'sig' && !empty($key['n']) && !empty($key['e'])) {
                return $this->jwkToPem($key);
            }
        }

        return null;
    }

    /** @param array<string, mixed> $jwk */
    private function jwkToPem(array $jwk): string
    {
        $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
        $e = base64_decode(strtr($jwk['e'], '-_', '+/'));

        if (ord($n[0]) > 0x7f) {
            $n = "\x00" . $n;
        }
        if (ord($e[0]) > 0x7f) {
            $e = "\x00" . $e;
        }

        $modulus  = "\x02" . $this->encodeLength(strlen($n)) . $n;
        $exponent = "\x02" . $this->encodeLength(strlen($e)) . $e;
        $sequence = "\x30" . $this->encodeLength(strlen($modulus . $exponent)) . $modulus . $exponent;
        $bitstring = "\x00" . $sequence;
        $bitstring = "\x03" . $this->encodeLength(strlen($bitstring)) . $bitstring;

        $oid  = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $oids = "\x30" . $this->encodeLength(strlen($oid)) . $oid;
        $spki = "\x30" . $this->encodeLength(strlen($oids . $bitstring)) . $oids . $bitstring;

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
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
