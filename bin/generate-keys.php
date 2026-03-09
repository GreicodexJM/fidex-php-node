#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FideX PHP — RSA Key Generator
 *
 * Generates two RSA-4096 key pairs:
 *   - keys/sign_private.pem / keys/sign_public.pem   (signing, RS256)
 *   - keys/enc_private.pem  / keys/enc_public.pem    (encryption, RSA-OAEP)
 *
 * Usage: php bin/generate-keys.php
 *        make keys
 *
 * WARNING: Never commit private keys to version control.
 *          Add keys/ to your .gitignore.
 */

$keySize  = 4096;
$keysDir  = dirname(__DIR__) . '/keys';

if (!is_dir($keysDir)) {
    mkdir($keysDir, 0750, true);
    echo "Created keys/ directory." . PHP_EOL;
}

// Check if keys already exist
if (file_exists($keysDir . '/sign_private.pem') || file_exists($keysDir . '/enc_private.pem')) {
    echo "⚠ Key files already exist. Delete them first if you want to regenerate." . PHP_EOL;
    echo "  rm keys/*.pem && make keys" . PHP_EOL;
    exit(1);
}

echo "Generating RSA-{$keySize} signing key pair..." . PHP_EOL;
$signKey = openssl_pkey_new([
    'private_key_bits' => $keySize,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

if ($signKey === false) {
    echo "ERROR: openssl_pkey_new failed. Check your PHP OpenSSL config." . PHP_EOL;
    exit(1);
}

openssl_pkey_export($signKey, $signPrivate);
$signDetails = openssl_pkey_get_details($signKey);
$signPublic  = $signDetails['key'];

file_put_contents($keysDir . '/sign_private.pem', $signPrivate);
chmod($keysDir . '/sign_private.pem', 0600);
file_put_contents($keysDir . '/sign_public.pem', $signPublic);

echo "✓ Signing keys saved: keys/sign_private.pem, keys/sign_public.pem" . PHP_EOL;

echo "Generating RSA-{$keySize} encryption key pair..." . PHP_EOL;
$encKey = openssl_pkey_new([
    'private_key_bits' => $keySize,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

openssl_pkey_export($encKey, $encPrivate);
$encDetails = openssl_pkey_get_details($encKey);
$encPublic  = $encDetails['key'];

file_put_contents($keysDir . '/enc_private.pem', $encPrivate);
chmod($keysDir . '/enc_private.pem', 0600);
file_put_contents($keysDir . '/enc_public.pem', $encPublic);

echo "✓ Encryption keys saved: keys/enc_private.pem, keys/enc_public.pem" . PHP_EOL;
echo PHP_EOL;
echo "Next steps:" . PHP_EOL;
echo "  1. Set FIDEX_SIGN_KEY_ID and FIDEX_ENC_KEY_ID in your .env" . PHP_EOL;
echo "  2. Run: make migrate" . PHP_EOL;
echo "  3. Run: make serve  (or deploy to your web server)" . PHP_EOL;
echo "  4. Share your AS5 config URL with partners: \$FIDEX_NODE_BASE_URL/as5/config" . PHP_EOL;
