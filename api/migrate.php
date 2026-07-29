<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/validators.php';

$user = requireAuthenticatedUser();
requireWriteSecurity();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Metoden stöds inte.']);
}
$payload = readJsonBody();
$documents = $payload['documents'] ?? null;
if (!is_array($documents) || $documents === []) {
    respond(422, ['error' => 'Ingen giltig lokal data valdes.']);
}
$validated = [];
foreach ($documents as $name => $data) {
    if (!is_string($name) || !in_array($name, DOCUMENT_NAMES, true)) {
        respond(422, ['error' => 'Okänd dokumenttyp i migreringen.']);
    }
    $validated[$name] = validateDocument($name, $data);
}
$alphaKey = is_string($payload['alphaVantageKey'] ?? null)
    ? trim($payload['alphaVantageKey'])
    : '';
if (mb_strlen($alphaKey) > 256) {
    respond(422, ['error' => 'Kursnyckeln är för lång.']);
}

$username = $user['username'];
$pdo = database();
$pdo->beginTransaction();
try {
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM documents WHERE owner_username = ? FOR UPDATE'
    );
    $check->execute([$username]);
    if ((int) $check->fetchColumn() !== 0) {
        $pdo->rollBack();
        respond(409, ['error' => 'Serverkontot innehåller redan data.']);
    }
    $insert = $pdo->prepare(
        'INSERT INTO documents
            (owner_username, document_name, schema_version, revision, payload, updated_at)
         VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())'
    );
    foreach ($validated as $name => $data) {
        $insert->execute([
            $username,
            $name,
            schemaVersionFor($name),
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }
    if ($alphaKey !== '') {
        require_once __DIR__ . '/secret-functions.php';
        storeSecret($pdo, $username, 'alpha_vantage', $alphaKey);
    }
    $pdo->commit();
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(500, ['error' => 'Den lokala datan kunde inte flyttas.']);
}
respond(200, stateResponse($username));
