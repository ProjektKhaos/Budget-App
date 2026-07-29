<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/secret-functions.php';

$user = requireAuthenticatedUser();
requireWriteSecurity();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
    header('Allow: PUT');
    respond(405, ['error' => 'Metoden stöds inte.']);
}
$payload = readJsonBody(4096);
$value = is_string($payload['value'] ?? null) ? trim($payload['value']) : '';
if (mb_strlen($value) > 256) {
    respond(422, ['error' => 'Kursnyckeln är för lång.']);
}
$pdo = database();
if ($value === '') {
    $statement = $pdo->prepare(
        'DELETE FROM user_secrets WHERE owner_username = ? AND secret_name = ?'
    );
    $statement->execute([$user['username'], 'alpha_vantage']);
} else {
    storeSecret($pdo, $user['username'], 'alpha_vantage', $value);
}
respond(200, ['configured' => $value !== '']);
