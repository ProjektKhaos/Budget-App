<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/validators.php';

$user = requireAuthenticatedUser();
$username = $user['username'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    respond(200, stateResponse($username));
}
if ($method !== 'PUT') {
    header('Allow: GET, PUT');
    respond(405, ['error' => 'Metoden stöds inte.']);
}

requireWriteSecurity();
$payload = readJsonBody();
$name = is_string($payload['name'] ?? null) ? $payload['name'] : '';
$baseRevision = $payload['baseRevision'] ?? null;
$schemaVersion = $payload['schemaVersion'] ?? null;
if (!in_array($name, DOCUMENT_NAMES, true) ||
    !is_int($baseRevision) ||
    $baseRevision < 0 ||
    $schemaVersion !== schemaVersionFor($name)
) {
    respond(422, ['error' => 'Dokumentets metadata är ogiltig.']);
}
$data = validateDocument($name, $payload['data'] ?? null);
$json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);

$pdo = database();
$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        'SELECT document_name, schema_version, revision, payload, updated_at
         FROM documents WHERE owner_username = ? AND document_name = ? FOR UPDATE'
    );
    $statement->execute([$username, $name]);
    $current = $statement->fetch();
    $currentRevision = $current ? (int) $current['revision'] : 0;
    if ($currentRevision !== $baseRevision) {
        $pdo->rollBack();
        respond(409, [
            'error' => 'Data har ändrats på en annan enhet.',
            'document' => $current ? documentRow($current) : null,
        ]);
    }
    $nextRevision = $currentRevision + 1;
    $write = $pdo->prepare(
        'INSERT INTO documents
            (owner_username, document_name, schema_version, revision, payload, updated_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            schema_version = VALUES(schema_version),
            revision = VALUES(revision),
            payload = VALUES(payload),
            updated_at = UTC_TIMESTAMP()'
    );
    $write->execute([$username, $name, $schemaVersion, $nextRevision, $json]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($error instanceof JsonException) {
        respond(422, ['error' => 'Dokumentet kunde inte kodas.']);
    }
    respond(500, ['error' => 'Dokumentet kunde inte sparas.']);
}

$read = $pdo->prepare(
    'SELECT document_name, schema_version, revision, payload, updated_at
     FROM documents WHERE owner_username = ? AND document_name = ?'
);
$read->execute([$username, $name]);
respond(200, ['document' => documentRow($read->fetch())]);
