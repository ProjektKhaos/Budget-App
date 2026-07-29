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
$revisions = $payload['revisions'] ?? null;
if (!is_array($documents) || $documents === [] || !is_array($revisions)) {
    respond(422, ['error' => 'Säkerhetskopian saknar serverdata.']);
}
$validated = [];
foreach ($documents as $name => $data) {
    if (!is_string($name) || !in_array($name, DOCUMENT_NAMES, true) ||
        !is_int($revisions[$name] ?? null) ||
        $revisions[$name] < 0
    ) {
        respond(422, ['error' => 'Säkerhetskopians metadata är ogiltig.']);
    }
    $validated[$name] = validateDocument($name, $data);
}

$username = $user['username'];
$pdo = database();
$pdo->beginTransaction();
try {
    $select = $pdo->prepare(
        'SELECT revision FROM documents
         WHERE owner_username = ? AND document_name = ? FOR UPDATE'
    );
    $write = $pdo->prepare(
        'INSERT INTO documents
            (owner_username, document_name, schema_version, revision, payload, updated_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version),
            revision = VALUES(revision), payload = VALUES(payload),
            updated_at = UTC_TIMESTAMP()'
    );
    foreach ($validated as $name => $data) {
        $select->execute([$username, $name]);
        $current = $select->fetchColumn();
        $currentRevision = $current === false ? 0 : (int) $current;
        if ($currentRevision !== $revisions[$name]) {
            $pdo->rollBack();
            respond(409, ['error' => 'Serverdata ändrades innan importen kunde sparas.']);
        }
        $write->execute([
            $username,
            $name,
            schemaVersionFor($name),
            $currentRevision + 1,
            json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
    }
    $pdo->commit();
} catch (Throwable) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respond(500, ['error' => 'Säkerhetskopian kunde inte importeras.']);
}
respond(200, stateResponse($username));
