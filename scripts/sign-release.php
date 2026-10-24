<?php

/**
 * Signs a release archive with Ed25519 (libsodium). Used by the release workflow, never on a server.
 *
 *   php scripts/sign-release.php <file> <secret-key-base64>  > file.sig
 */
if ($argc !== 3 || ! is_file($argv[1])) {
    fwrite(STDERR, "usage: php scripts/sign-release.php <file> <secret-key-base64>\n");
    exit(2);
}

$secret = base64_decode(trim($argv[2]), true);
if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "invalid secret key\n");
    exit(2);
}

echo base64_encode(sodium_crypto_sign_detached(file_get_contents($argv[1]), $secret)), "\n";
