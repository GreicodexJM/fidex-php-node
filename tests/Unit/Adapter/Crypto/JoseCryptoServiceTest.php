<?php

declare(strict_types=1);

namespace FideX\Tests\Unit\Adapter\Crypto;

use FideX\Adapter\Outbound\Crypto\JoseCryptoService;
use FideX\Core\Domain\KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * JoseCryptoService Unit Tests
 *
 * Tests the FideX Sign-then-Encrypt pipeline:
 *   sign() → JWS
 *   encrypt() → JWE(JWS)
 *   decrypt() → JWS
 *   verify() → payload
 *
 * Uses 2048-bit RSA keys generated in setUpBeforeClass() for speed.
 *
 * TDD: These tests were written BEFORE the implementation.
 */
class JoseCryptoServiceTest extends TestCase
{
    private static string $senderPrivateKeyPem;
    private static string $senderPublicKeyPem;
    private static string $receiverPrivateKeyPem;
    private static string $receiverPublicKeyPem;
    private static KeyPair $senderSignKeyPair;
    private static KeyPair $receiverEncKeyPair;
    private JoseCryptoService $crypto;

    public static function setUpBeforeClass(): void
    {
        // Use 1024-bit RSA for test speed only (never use in production)
        $senderSignKey = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $senderPrivPem = '';
        openssl_pkey_export($senderSignKey, $senderPrivPem);
        self::$senderPrivateKeyPem = $senderPrivPem;
        $details = openssl_pkey_get_details($senderSignKey);
        self::$senderPublicKeyPem = $details['key'];

        $receiverEncKey = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $receiverPrivPem = '';
        openssl_pkey_export($receiverEncKey, $receiverPrivPem);
        self::$receiverPrivateKeyPem = $receiverPrivPem;
        $details = openssl_pkey_get_details($receiverEncKey);
        self::$receiverPublicKeyPem = $details['key'];

        self::$senderSignKeyPair = new KeyPair(
            keyId: 'sender-sign-test-01',
            use: KeyPair::USE_SIGN,
            privateKeyPem: self::$senderPrivateKeyPem,
            publicKeyPem: self::$senderPublicKeyPem,
            algorithm: 'RS256',
        );

        self::$receiverEncKeyPair = new KeyPair(
            keyId: 'receiver-enc-test-01',
            use: KeyPair::USE_ENC,
            privateKeyPem: self::$receiverPrivateKeyPem,
            publicKeyPem: self::$receiverPublicKeyPem,
            algorithm: 'RSA-OAEP',
        );
    }

    protected function setUp(): void
    {
        $this->crypto = new JoseCryptoService();
    }

    // ─── sign() Tests ──────────────────────────────────────────────────────

    public function test_sign_returns_jws_compact_serialization(): void
    {
        $payload = ['order_id' => 'PO-123', 'amount' => 1000.00];

        $jws = $this->crypto->sign($payload, self::$senderSignKeyPair);

        // JWS compact = header.payload.signature (3 parts)
        $parts = explode('.', $jws);
        $this->assertCount(3, $parts, 'JWS compact serialization must have 3 parts');
    }

    public function test_sign_includes_correct_algorithm_header(): void
    {
        $payload = ['order_id' => 'PO-123'];

        $jws = $this->crypto->sign($payload, self::$senderSignKeyPair);

        $parts = explode('.', $jws);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('RS256', $header['alg']);
        $this->assertSame('sender-sign-test-01', $header['kid']);
    }

    public function test_sign_embeds_payload(): void
    {
        $payload = ['order_id' => 'PO-123', 'amount' => 99.50];

        $jws = $this->crypto->sign($payload, self::$senderSignKeyPair);

        $parts = explode('.', $jws);
        $decodedPayload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        $this->assertSame('PO-123', $decodedPayload['order_id']);
        $this->assertSame(99.50, $decodedPayload['amount']);
    }

    // ─── encrypt() Tests ───────────────────────────────────────────────────

    public function test_encrypt_returns_jwe_compact_serialization(): void
    {
        $payload = ['data' => 'test'];
        $jws = $this->crypto->sign($payload, self::$senderSignKeyPair);

        $jwe = $this->crypto->encrypt($jws, self::$receiverPublicKeyPem, 'receiver-enc-test-01');

        // JWE compact = header.key.iv.ciphertext.tag (5 parts)
        $parts = explode('.', $jwe);
        $this->assertCount(5, $parts, 'JWE compact serialization must have 5 parts');
    }

    public function test_encrypt_uses_rsa_oaep_algorithm(): void
    {
        $jws = $this->crypto->sign(['test' => true], self::$senderSignKeyPair);

        $jwe = $this->crypto->encrypt($jws, self::$receiverPublicKeyPem, 'receiver-enc-test-01');

        $parts = explode('.', $jwe);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('RSA-OAEP', $header['alg']);
        $this->assertSame('A256GCM', $header['enc']);
        $this->assertSame('JWT', $header['cty'], 'JWE MUST include cty:JWT per FideX spec');
    }

    // ─── decrypt() Tests ───────────────────────────────────────────────────

    public function test_decrypt_returns_inner_jws(): void
    {
        $originalPayload = ['invoice_id' => 'INV-456', 'total' => 5000.00];
        $jws = $this->crypto->sign($originalPayload, self::$senderSignKeyPair);
        $jwe = $this->crypto->encrypt($jws, self::$receiverPublicKeyPem, 'receiver-enc-test-01');

        $decryptedJws = $this->crypto->decrypt($jwe, self::$receiverEncKeyPair);

        // Result should be a valid JWS (3 parts)
        $parts = explode('.', $decryptedJws);
        $this->assertCount(3, $parts);
    }

    public function test_decrypt_with_wrong_key_throws_exception(): void
    {
        $jws = $this->crypto->sign(['test' => true], self::$senderSignKeyPair);
        $jwe = $this->crypto->encrypt($jws, self::$receiverPublicKeyPem, 'receiver-enc-test-01');

        // Generate a different key pair (wrong key) — 1024-bit for test speed
        $wrongKey = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($wrongKey, $wrongPrivatePem);
        $wrongDetails = openssl_pkey_get_details($wrongKey);
        $wrongKeyPair = new KeyPair('wrong-key', KeyPair::USE_ENC, $wrongPrivatePem, $wrongDetails['key'], 'RSA-OAEP');

        $this->expectException(\RuntimeException::class);
        $this->crypto->decrypt($jwe, $wrongKeyPair);
    }

    // ─── verify() Tests ────────────────────────────────────────────────────

    public function test_verify_returns_original_payload(): void
    {
        $originalPayload = ['order_id' => 'PO-789', 'amount' => 123.45];
        $jws = $this->crypto->sign($originalPayload, self::$senderSignKeyPair);

        $verifiedPayload = $this->crypto->verify($jws, self::$senderPublicKeyPem);

        $this->assertSame('PO-789', $verifiedPayload['order_id']);
        $this->assertSame(123.45, $verifiedPayload['amount']);
    }

    public function test_verify_with_wrong_key_throws_exception(): void
    {
        $jws = $this->crypto->sign(['test' => true], self::$senderSignKeyPair);

        // Use receiver's key instead of sender's
        $this->expectException(\RuntimeException::class);
        $this->crypto->verify($jws, self::$receiverPublicKeyPem);
    }

    // ─── Full round-trip test ──────────────────────────────────────────────

    public function test_full_sign_encrypt_decrypt_verify_roundtrip(): void
    {
        $originalPayload = [
            'order_id'    => 'PO-ROUNDTRIP-001',
            'amount'      => 9999.99,
            'line_items'  => [
                ['sku' => 'MED-001', 'qty' => 100],
                ['sku' => 'MED-002', 'qty' => 50],
            ],
        ];

        // Sender: sign → encrypt
        $jws = $this->crypto->sign($originalPayload, self::$senderSignKeyPair);
        $jwe = $this->crypto->encrypt($jws, self::$receiverPublicKeyPem, 'receiver-enc-test-01');

        // Receiver: decrypt → verify
        $decryptedJws = $this->crypto->decrypt($jwe, self::$receiverEncKeyPair);
        $verifiedPayload = $this->crypto->verify($decryptedJws, self::$senderPublicKeyPem);

        $this->assertSame($originalPayload['order_id'], $verifiedPayload['order_id']);
        $this->assertSame($originalPayload['amount'], $verifiedPayload['amount']);
        $this->assertCount(2, $verifiedPayload['line_items']);
        $this->assertSame('MED-001', $verifiedPayload['line_items'][0]['sku']);
    }

    // ─── pemToJwk() Tests ──────────────────────────────────────────────────

    public function test_pem_to_jwk_returns_correct_structure(): void
    {
        $jwk = $this->crypto->pemToJwk(
            self::$senderPublicKeyPem,
            'sender-sign-test-01',
            'sig',
            'RS256'
        );

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('sender-sign-test-01', $jwk['kid']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertArrayHasKey('n', $jwk);
        $this->assertArrayHasKey('e', $jwk);
        $this->assertNotEmpty($jwk['n']);
        $this->assertNotEmpty($jwk['e']);
    }

    // ─── signReceipt() + verifyReceipt() Tests ────────────────────────────

    public function test_sign_and_verify_receipt_roundtrip(): void
    {
        $jmdnPayload = [
            'original_message_id' => 'fdx-test-uuid-1234',
            'status'              => 'DELIVERED',
            'receiver_id'         => 'urn:custom:test-receiver',
            'hash_verification'   => 'sha256:abc123',
            'timestamp'           => '2026-03-09T10:00:00.000Z',
            'error_log'           => null,
        ];

        $signature = $this->crypto->signReceipt($jmdnPayload, self::$senderSignKeyPair);
        $isValid = $this->crypto->verifyReceipt($signature, self::$senderPublicKeyPem);

        $this->assertTrue($isValid);
    }

    public function test_verify_receipt_with_wrong_key_returns_false(): void
    {
        $jmdnPayload = [
            'original_message_id' => 'fdx-test-uuid-1234',
            'status'              => 'DELIVERED',
            'receiver_id'         => 'urn:custom:test-receiver',
            'hash_verification'   => 'sha256:abc123',
            'timestamp'           => '2026-03-09T10:00:00.000Z',
            'error_log'           => null,
        ];

        $signature = $this->crypto->signReceipt($jmdnPayload, self::$senderSignKeyPair);
        $isValid = $this->crypto->verifyReceipt($signature, self::$receiverPublicKeyPem);

        $this->assertFalse($isValid);
    }
}
