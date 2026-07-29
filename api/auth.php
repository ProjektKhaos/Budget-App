<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const ATTEMPT_WINDOW = 900;
const ATTEMPT_LIMIT = 5;

function credentials(): array
{
    $file = configValue('auth_file');
    try {
        $raw = @file_get_contents($file);
        $value = $raw === false ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $value = null;
    }
    if (!is_array($value) ||
        !is_string($value['username'] ?? null) ||
        !is_string($value['name'] ?? null) ||
        !is_string($value['password_hash'] ?? null)
    ) {
        respond(500, ['error' => 'Kontot är inte korrekt konfigurerat.']);
    }
    return $value;
}

function updateAttempts(string $key, ?bool $failed): int
{
    $pdo = database();
    $threshold = gmdate('Y-m-d H:i:s', time() - ATTEMPT_WINDOW);
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$threshold]);
    if ($failed === true) {
        $pdo->prepare(
            'INSERT INTO login_attempts (client_key, attempted_at) VALUES (?, UTC_TIMESTAMP())'
        )->execute([$key]);
    } elseif ($failed === false) {
        $pdo->prepare('DELETE FROM login_attempts WHERE client_key = ?')->execute([$key]);
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE client_key = ?');
    $statement->execute([$key]);
    return (int) $statement->fetchColumn();
}

function authPayload(): array
{
    $user = sessionUser();
    return [
        'authenticated' => $user !== null,
        'user' => $user,
        'csrfToken' => $user !== null ? csrfToken() : null,
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    respond(200, authPayload());
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    respond(405, ['error' => 'Metoden stöds inte.']);
}

$payload = readJsonBody(4096);
$action = $payload['action'] ?? null;
if ($action === 'logout') {
    requireAuthenticatedUser();
    requireWriteSecurity();
    $_SESSION = [];
    session_regenerate_id(true);
    respond(200, ['authenticated' => false, 'user' => null]);
}
if ($action !== 'login') {
    respond(400, ['error' => 'Ogiltig kontoåtgärd.']);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$expected = expectedOrigin();
if ($origin !== '' && !hash_equals($expected, $origin)) {
    respond(403, ['error' => 'Begäran kommer från fel webbplats.']);
}

$username = is_string($payload['username'] ?? null) ? trim($payload['username']) : '';
$password = is_string($payload['password'] ?? null) ? $payload['password'] : '';
$clientKey = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$attempts = updateAttempts($clientKey, null);
if ($attempts >= ATTEMPT_LIMIT) {
    respond(429, ['error' => 'För många försök. Vänta 15 minuter.']);
}

$stored = credentials();
$valid = hash_equals($stored['username'], $username) &&
    password_verify($password, $stored['password_hash']);
if (!$valid) {
    $attempts = updateAttempts($clientKey, true);
    usleep(250000);
    respond(
        $attempts >= ATTEMPT_LIMIT ? 429 : 401,
        ['error' => $attempts >= ATTEMPT_LIMIT
            ? 'För många försök. Vänta 15 minuter.'
            : 'Fel användarnamn eller lösenord.']
    );
}

updateAttempts($clientKey, false);
session_regenerate_id(true);
$_SESSION['user'] = [
    'name' => $stored['name'],
    'username' => $stored['username'],
    'role' => 'Ägare',
];
csrfToken();
respond(200, authPayload());
