<?php

declare(strict_types=1);

function encryptionKey(): string
{
    $raw = @file_get_contents(configValue('master_key_file'));
    if ($raw === false) {
        respond(500, ['error' => 'Krypteringsnyckeln kan inte läsas.']);
    }
    $key = sodium_base642bin(trim($raw), SODIUM_BASE64_VARIANT_ORIGINAL);
    if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        respond(500, ['error' => 'Krypteringsnyckeln är ogiltig.']);
    }
    return $key;
}

function storeSecret(
    PDO $pdo,
    string $username,
    string $name,
    string $plainText
): void {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ciphertext = sodium_crypto_secretbox($plainText, $nonce, encryptionKey());
    $statement = $pdo->prepare(
        'INSERT INTO user_secrets
            (owner_username, secret_name, nonce, ciphertext, updated_at)
         VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            nonce = VALUES(nonce),
            ciphertext = VALUES(ciphertext),
            updated_at = UTC_TIMESTAMP()'
    );
    $statement->execute([$username, $name, $nonce, $ciphertext]);
}

function readSecret(PDO $pdo, string $username, string $name): ?string
{
    $statement = $pdo->prepare(
        'SELECT nonce, ciphertext FROM user_secrets
         WHERE owner_username = ? AND secret_name = ?'
    );
    $statement->execute([$username, $name]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }
    $plainText = sodium_crypto_secretbox_open(
        $row['ciphertext'],
        $row['nonce'],
        encryptionKey()
    );
    return is_string($plainText) ? $plainText : null;
}
