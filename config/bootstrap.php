<?php

declare(strict_types=1);

/**
 * FideX PHP — Dependency Injection Bootstrap
 *
 * Wires all ports to their concrete adapter implementations.
 * Called once from public/index.php and bin/worker.php.
 *
 * No framework DI container — just explicit constructor injection.
 */

use FideX\Adapter\Inbound\Http\DiscoveryController;
use FideX\Adapter\Inbound\Http\HealthController;
use FideX\Adapter\Inbound\Http\PartnerController;
use FideX\Adapter\Inbound\Http\ReceiptController;
use FideX\Adapter\Inbound\Http\ReceiveController;
use FideX\Adapter\Inbound\Http\TransmitController;
use FideX\Adapter\Outbound\Crypto\JoseCryptoService;
use FideX\Adapter\Outbound\Http\CurlHttpClient;
use FideX\Adapter\Outbound\Logger\FileLogger;
use FideX\Adapter\Outbound\Persistence\DatabaseConnection;
use FideX\Adapter\Outbound\Persistence\SqliteMessageRepository;
use FideX\Adapter\Outbound\Persistence\SqlitePartnerRepository;
use FideX\Adapter\Outbound\Queue\DatabaseQueue;
use FideX\Core\Domain\KeyPair;
use FideX\Core\UseCase\ProcessReceipt;
use FideX\Core\UseCase\RegisterPartner;
use FideX\Core\UseCase\ReceiveMessage;
use FideX\Core\UseCase\TransmitMessage;

// ── Load .env if it exists ──────────────────────────────────────────────────
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}

// ── Read config from environment ────────────────────────────────────────────
$nodeId       = $_ENV['FIDEX_NODE_ID']       ?? getenv('FIDEX_NODE_ID')       ?: 'urn:custom:fidex-node';
$nodeName     = $_ENV['FIDEX_NODE_NAME']     ?? getenv('FIDEX_NODE_NAME')     ?: 'FideX PHP Node';
$nodeBaseUrl  = rtrim($_ENV['FIDEX_NODE_BASE_URL'] ?? getenv('FIDEX_NODE_BASE_URL') ?: 'https://fidex.example.com', '/');
$apiKey       = $_ENV['FIDEX_API_KEY']       ?? getenv('FIDEX_API_KEY')       ?: '';

$signPrivPath = $_ENV['FIDEX_SIGN_PRIVATE_KEY_PATH'] ?? getenv('FIDEX_SIGN_PRIVATE_KEY_PATH') ?: 'keys/sign_private.pem';
$signPubPath  = $_ENV['FIDEX_SIGN_PUBLIC_KEY_PATH']  ?? getenv('FIDEX_SIGN_PUBLIC_KEY_PATH')  ?: 'keys/sign_public.pem';
$signKeyId    = $_ENV['FIDEX_SIGN_KEY_ID']           ?? getenv('FIDEX_SIGN_KEY_ID')           ?: 'node-sign-key';

$encPrivPath  = $_ENV['FIDEX_ENC_PRIVATE_KEY_PATH'] ?? getenv('FIDEX_ENC_PRIVATE_KEY_PATH') ?: 'keys/enc_private.pem';
$encPubPath   = $_ENV['FIDEX_ENC_PUBLIC_KEY_PATH']  ?? getenv('FIDEX_ENC_PUBLIC_KEY_PATH')  ?: 'keys/enc_public.pem';
$encKeyId     = $_ENV['FIDEX_ENC_KEY_ID']           ?? getenv('FIDEX_ENC_KEY_ID')           ?: 'node-enc-key';

$logPath      = $_ENV['FIDEX_LOG_PATH']      ?? getenv('FIDEX_LOG_PATH')      ?: 'storage/logs/fidex.log';
$logLevel     = $_ENV['FIDEX_LOG_LEVEL']     ?? getenv('FIDEX_LOG_LEVEL')     ?: 'info';

// Resolve relative key paths
$projectRoot = dirname(__DIR__);
if (!str_starts_with($signPrivPath, '/')) { $signPrivPath = $projectRoot . '/' . $signPrivPath; }
if (!str_starts_with($signPubPath,  '/')) { $signPubPath  = $projectRoot . '/' . $signPubPath; }
if (!str_starts_with($encPrivPath,  '/')) { $encPrivPath  = $projectRoot . '/' . $encPrivPath; }
if (!str_starts_with($encPubPath,   '/')) { $encPubPath   = $projectRoot . '/' . $encPubPath; }
if (!str_starts_with($logPath,      '/')) { $logPath      = $projectRoot . '/' . $logPath; }

// ── Adapters (outbound) ─────────────────────────────────────────────────────
$pdo = DatabaseConnection::get();

$messageRepo = new SqliteMessageRepository($pdo);
$partnerRepo = new SqlitePartnerRepository($pdo);
$cryptoService = new JoseCryptoService();
$httpClient  = new CurlHttpClient();
$queue       = new DatabaseQueue($pdo);
$logger      = new FileLogger($logPath, $logLevel);

// ── Key pairs ───────────────────────────────────────────────────────────────
$signingKeyPair = null;
$encryptionKeyPair = null;

if (file_exists($signPrivPath) && file_exists($signPubPath)) {
    $signingKeyPair = KeyPair::fromPemFiles($signKeyId, KeyPair::USE_SIGN, $signPrivPath, $signPubPath);
}

if (file_exists($encPrivPath) && file_exists($encPubPath)) {
    $encryptionKeyPair = KeyPair::fromPemFiles($encKeyId, KeyPair::USE_ENC, $encPrivPath, $encPubPath);
}

// ── Use cases ───────────────────────────────────────────────────────────────
$transmitMessage = $signingKeyPair !== null
    ? new TransmitMessage($messageRepo, $partnerRepo, $cryptoService, $queue, $logger, $nodeId, $signingKeyPair)
    : null;

$receiveMessage  = new ReceiveMessage($messageRepo, $partnerRepo, $queue, $logger, $nodeId);
$processReceipt  = new ProcessReceipt($messageRepo, $partnerRepo, $cryptoService, $logger);
$allowHttpRegistration = filter_var(
    $_ENV['FIDEX_ALLOW_HTTP_REGISTRATION'] ?? getenv('FIDEX_ALLOW_HTTP_REGISTRATION') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);
$registerPartner = new RegisterPartner($partnerRepo, $httpClient, $logger, $allowHttpRegistration);

// ── Controllers ─────────────────────────────────────────────────────────────
$transmitController = $transmitMessage !== null
    ? new TransmitController($transmitMessage, $apiKey)
    : null;

$receiveController  = new ReceiveController($receiveMessage);
$receiptController  = new ReceiptController($processReceipt);
$partnerController  = new PartnerController($registerPartner, $apiKey);

$discoveryController = ($signingKeyPair !== null && $encryptionKeyPair !== null)
    ? new DiscoveryController($cryptoService, $signingKeyPair, $encryptionKeyPair, $nodeId, $nodeName, $nodeBaseUrl)
    : null;

$healthController = new HealthController($pdo, $queue, $signPrivPath, $encPrivPath, $nodeId);

return [
    'transmit'    => $transmitController,
    'receive'     => $receiveController,
    'receipt'     => $receiptController,
    'partner'     => $partnerController,
    'discovery'   => $discoveryController,
    'health'      => $healthController,
    'pdo'         => $pdo,
    'queue'       => $queue,
    'logger'      => $logger,
    'messageRepo' => $messageRepo,
    'partnerRepo' => $partnerRepo,
    'cryptoService' => $cryptoService,
    'signingKeyPair'    => $signingKeyPair,
    'encryptionKeyPair' => $encryptionKeyPair,
    // Node identity (used by NodeInfoController)
    'nodeId'      => $nodeId,
    'nodeName'    => $nodeName,
    'nodeBaseUrl' => $nodeBaseUrl,
];
