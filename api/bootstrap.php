<?php

declare(strict_types=1);

const BUDGETKOLL_CONFIG = '/etc/budget-app/config.php';
const MAX_JSON_BYTES = 1048576;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (!is_file(BUDGETKOLL_CONFIG)) {
    http_response_code(500);
    echo '{"error":"Serverlagringen är inte konfigurerad."}';
    exit;
}

$budgetkollConfig = require BUDGETKOLL_CONFIG;
if (!is_array($budgetkollConfig)) {
    http_response_code(500);
    echo '{"error":"Serverkonfigurationen är ogiltig."}';
    exit;
}

session_name('budgetkoll_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    exit;
}

function configValue(string $name): string
{
    global $budgetkollConfig;
    $value = $budgetkollConfig[$name] ?? null;
    if (!is_string($value) || $value === '') {
        respond(500, ['error' => 'Serverkonfigurationen saknar ett värde.']);
    }
    return $value;
}

function expectedOrigin(): string
{
    return rtrim(configValue('app_origin'), '/');
}

function database(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    try {
        $connection = new PDO(
            configValue('dsn'),
            configValue('database_user'),
            configValue('database_password'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException) {
        respond(503, ['error' => 'Databasen kan inte nås just nu.']);
    }
    return $connection;
}

function sessionUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function requireAuthenticatedUser(): array
{
    $user = sessionUser();
    if ($user === null || !is_string($user['username'] ?? null)) {
        respond(401, ['error' => 'Logga in för att fortsätta.']);
    }
    return $user;
}

function csrfToken(): string
{
    if (!is_string($_SESSION['csrf'] ?? null)) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function requireWriteSecurity(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $expected = expectedOrigin();
    if ($origin !== '' && !hash_equals($expected, $origin)) {
        respond(403, ['error' => 'Begäran kommer från fel webbplats.']);
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        respond(403, ['error' => 'Säkerhetstoken saknas eller är ogiltig.']);
    }
}

function readJsonBody(int $maxBytes = MAX_JSON_BYTES): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        respond(415, ['error' => 'Content-Type måste vara application/json.']);
    }
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) {
        respond(413, ['error' => 'För stor begäran.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        respond(413, ['error' => 'För stor begäran.']);
    }
    try {
        $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Ogiltig JSON.']);
    }
    if (!is_array($payload)) {
        respond(400, ['error' => 'JSON-objekt krävs.']);
    }
    return $payload;
}

function documentRow(array $row): array
{
    try {
        $data = json_decode((string) $row['payload'], true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(500, ['error' => 'Serverdata har ett ogiltigt format.']);
    }
    return [
        'name' => (string) $row['document_name'],
        'schemaVersion' => (int) $row['schema_version'],
        'revision' => (int) $row['revision'],
        'updatedAt' => gmdate('c', strtotime((string) $row['updated_at'])),
        'data' => $data,
    ];
}

function readDocuments(string $username): array
{
    $statement = database()->prepare(
        'SELECT document_name, schema_version, revision, payload, updated_at
         FROM documents WHERE owner_username = ? ORDER BY document_name'
    );
    $statement->execute([$username]);
    $documents = [];
    foreach ($statement->fetchAll() as $row) {
        $document = documentRow($row);
        $documents[$document['name']] = $document;
    }
    return $documents;
}

function secretConfigured(string $username): bool
{
    $statement = database()->prepare(
        'SELECT 1 FROM user_secrets
         WHERE owner_username = ? AND secret_name = ? LIMIT 1'
    );
    $statement->execute([$username, 'alpha_vantage']);
    return (bool) $statement->fetchColumn();
}

function stateResponse(string $username): array
{
    return [
        'documents' => readDocuments($username),
        'secretConfigured' => secretConfigured($username),
    ];
}
