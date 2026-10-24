<?php

/**
 * Verifies a release archive against its detached signature and the pinned public key.
 * Standalone on purpose (no framework, no database), so the updater can run it before trusting anything.
 *
 *   php scripts/verify-release.php <archive> <signature-file> <public-key-file> [expected-sha256]
 * Exit status 0 = authentic, 1 = rejected, 2 = usage error. Prints the reason on failure.
 */
if ($argc < 4 || ! is_file($argv[1]) || ! is_file($argv[2]) || ! is_file($argv[3])) {
    fwrite(STDERR, "usage: php scripts/verify-release.php <archive> <signature> <public-key> [sha256]\n");
    exit(2);
}

$public = base64_decode(trim((string) file_get_contents($argv[3])), true);
$signature = base64_decode(trim((string) file_get_contents($argv[2])), true);
$bytes = file_get_contents($argv[1]);

if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
    fwrite(STDERR, "invalid public key\n");
    exit(1);
}
if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
    fwrite(STDERR, "malformed signature\n");
    exit(1);
}
if (isset($argv[4]) && ! hash_equals(strtolower($argv[4]), hash('sha256', $bytes))) {
    fwrite(STDERR, "checksum mismatch\n");
    exit(1);
}
if (! sodium_crypto_sign_verify_detached($signature, $bytes, $public)) {
    fwrite(STDERR, "signature does not match\n");
    exit(1);
}

echo "ok\n";
