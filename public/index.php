<?php

declare(strict_types=1);

/**
 * FideX PHP Reference Implementation — Front Controller
 *
 * All HTTP requests are routed through this file via .htaccess.
 *
 * Web root: this public/ directory
 * Project root: one level up
 */

// ── Autoload ─────────────────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/vendor/autoload.php';

// ── Bootstrap DI ─────────────────────────────────────────────────────────────
$app = require_once dirname(__DIR__) . '/config/bootstrap.php';

// ── Router ───────────────────────────────────────────────────────────────────
$router = new \FideX\Adapter\Inbound\Http\Router();

// ── Public B2B endpoints (no auth) ───────────────────────────────────────────
$router->add('POST', '/api/v1/receive',  $app['receive']);
$router->add('POST', '/api/v1/receipt',  $app['receipt']);

// ── Internal ERP endpoint (API key required) ─────────────────────────────────
if ($app['transmit'] !== null) {
    $router->add('POST', '/api/v1/transmit', $app['transmit']);
} else {
    $router->add('POST', '/api/v1/transmit', function (array $p): void {
        \FideX\Adapter\Inbound\Http\Router::error(
            'CONFIGURATION_ERROR',
            'Node keys are not configured. Run: make keys',
            503
        );
    });
}

// ── Partner registration (API key required) ───────────────────────────────────
$router->add('POST', '/api/v1/partners/register', $app['partner']);

// ── Discovery endpoints (public, no auth) ─────────────────────────────────────
if ($app['discovery'] !== null) {
    $router->add('GET', '/.well-known/jwks.json', [$app['discovery'], 'jwks']);
    $router->add('GET', '/as5/config',            [$app['discovery'], 'as5Config']);
} else {
    $router->add('GET', '/.well-known/jwks.json', function (array $p): void {
        \FideX\Adapter\Inbound\Http\Router::error('CONFIGURATION_ERROR', 'Keys not configured.', 503);
    });
    $router->add('GET', '/as5/config', function (array $p): void {
        \FideX\Adapter\Inbound\Http\Router::error('CONFIGURATION_ERROR', 'Keys not configured.', 503);
    });
}

// ── Node info (public, used by QR onboarding webapp) ─────────────────────────
$nodeInfoController = new \FideX\Adapter\Inbound\Http\NodeInfoController(
    $app['nodeId'],
    $app['nodeName'],
    $app['nodeBaseUrl'],
    $app['signingKeyPair'] !== null && $app['encryptionKeyPair'] !== null,
    $app['pdo']
);
$router->add('GET',     '/api/v1/node-info', $nodeInfoController);
$router->add('OPTIONS', '/api/v1/node-info', $nodeInfoController); // CORS preflight

// ── Health check ─────────────────────────────────────────────────────────────
$router->add('GET', '/health', $app['health']);

// ── Dispatch ─────────────────────────────────────────────────────────────────
$router->dispatch();
