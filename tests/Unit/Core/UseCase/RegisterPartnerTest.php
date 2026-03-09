<?php

declare(strict_types=1);

namespace FideX\Tests\Unit\Core\UseCase;

use FideX\Core\UseCase\RegisterPartner;
use FideX\Core\UseCase\Result;
use FideX\Port\Outbound\HttpClientPort;
use FideX\Port\Outbound\HttpResponse;
use FideX\Port\Outbound\LoggerPort;
use FideX\Tests\Doubles\InMemoryPartnerRepository;
use PHPUnit\Framework\TestCase;

/**
 * RegisterPartnerTest
 *
 * Tests the RegisterPartner use case with mocked HTTP client and an
 * in-memory partner repository.  No real network calls are made.
 *
 * @covers \FideX\Core\UseCase\RegisterPartner
 */
final class RegisterPartnerTest extends TestCase
{
    private InMemoryPartnerRepository $partnerRepo;
    private HttpClientPort            $httpClient;
    private LoggerPort                $logger;
    private RegisterPartner           $useCase;

    protected function setUp(): void
    {
        $this->partnerRepo = new InMemoryPartnerRepository();
        $this->httpClient  = $this->createMock(HttpClientPort::class);
        $this->logger      = $this->createMock(LoggerPort::class);

        $this->useCase = new RegisterPartner(
            $this->partnerRepo,
            $this->httpClient,
            $this->logger,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Returns a well-formed AS5 config array (as returned by json()). */
    private function validAs5Config(string $partnerId = 'urn:custom:partner-b'): array
    {
        return [
            'partner_id'       => $partnerId,
            'name'             => 'Partner B Pharmacy',
            'receive_endpoint' => 'https://partner-b.example.com/api/v1/receive',
            'receipt_endpoint' => 'https://partner-b.example.com/api/v1/receipt',
            'jwks_url'         => 'https://partner-b.example.com/.well-known/jwks.json',
            'as5_config_url'   => 'https://partner-b.example.com/as5/config',
        ];
    }

    /** Builds an HttpResponse that represents a successful JSON reply. */
    private function okJsonResponse(array $data): HttpResponse
    {
        return new HttpResponse(200, json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** Builds an HttpResponse that represents a failed request. */
    private function failResponse(int $code = 503): HttpResponse
    {
        return new HttpResponse($code, '');
    }

    // ── Tests: URL validation ──────────────────────────────────────────────

    public function test_returns_failure_when_as5_config_url_is_empty(): void
    {
        $result = $this->useCase->execute('');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
    }

    public function test_returns_failure_when_as5_config_url_is_not_https(): void
    {
        $result = $this->useCase->execute('http://partner.example.com/as5/config');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
        $this->assertStringContainsString('HTTPS', $result->getMessage());
    }

    // ── Tests: HTTP fetch failures ─────────────────────────────────────────

    public function test_returns_failure_when_as5_config_fetch_fails(): void
    {
        $this->httpClient
            ->method('get')
            ->willReturn($this->failResponse(503));

        $result = $this->useCase->execute('https://partner.example.com/as5/config');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_PROCESSING_ERROR, $result->getError());
        $this->assertStringContainsString('503', $result->getMessage());
    }

    public function test_returns_failure_when_as5_config_is_missing_required_field(): void
    {
        $config = $this->validAs5Config();
        unset($config['receive_endpoint']); // remove required field

        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->okJsonResponse($config),
            );

        $result = $this->useCase->execute('https://partner.example.com/as5/config');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_VALIDATION_ERROR, $result->getError());
        $this->assertStringContainsString('receive_endpoint', $result->getMessage());
    }

    // ── Tests: Duplicate partner ───────────────────────────────────────────

    public function test_returns_failure_when_partner_is_already_registered(): void
    {
        // Pre-register the partner
        $this->httpClient
            ->method('get')
            ->willReturn($this->okJsonResponse($this->validAs5Config()));

        $url = 'https://partner-b.example.com/as5/config';
        $this->useCase->execute($url); // first registration

        // Second call — overwrite=false (default)
        $result = $this->useCase->execute($url);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(Result::ERR_DUPLICATE_MESSAGE, $result->getError());
    }

    // ── Tests: Happy path ─────────────────────────────────────────────────

    public function test_registers_partner_successfully_and_persists_to_repository(): void
    {
        $jwks   = ['keys' => [['use' => 'sig', 'kty' => 'RSA', 'n' => 'abc', 'e' => 'AQAB']]];
        $config = $this->validAs5Config('urn:gln:0614141000099');

        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->okJsonResponse($config),                 // AS5 config fetch
                new HttpResponse(200, json_encode($jwks)),      // JWKS fetch
            );

        $result = $this->useCase->execute('https://partner-b.example.com/as5/config');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('urn:gln:0614141000099', $result->getData()['partner_id']);
        $this->assertTrue($result->getData()['jwks_cached']);

        // Partner must be persisted
        $this->assertTrue($this->partnerRepo->exists('urn:gln:0614141000099'));
    }

    public function test_registers_partner_even_when_jwks_fetch_fails(): void
    {
        $config = $this->validAs5Config();

        $this->httpClient
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                $this->okJsonResponse($config),  // AS5 config: OK
                $this->failResponse(404),        // JWKS: not available yet
            );

        $result = $this->useCase->execute('https://partner-b.example.com/as5/config');

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->getData()['jwks_cached']); // cached=false, but no failure
        $this->assertTrue($this->partnerRepo->exists('urn:custom:partner-b'));
    }
}
