<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/secret-functions.php';

$user = requireAuthenticatedUser();
requireWriteSecurity();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Metoden stöds inte.']);
}
$payload = readJsonBody(4096);
$action = $payload['action'] ?? null;
$query = is_string($payload['query'] ?? null) ? trim($payload['query']) : '';
if (!in_array($action, ['search', 'quote'], true) ||
    $query === '' ||
    mb_strlen($query) > 100
) {
    respond(422, ['error' => 'Ogiltig kursförfrågan.']);
}
$apiKey = readSecret(database(), $user['username'], 'alpha_vantage');
if ($apiKey === null) {
    respond(409, ['error' => 'Lägg först in en Alpha Vantage-nyckel.']);
}
$parameters = $action === 'search'
    ? ['function' => 'SYMBOL_SEARCH', 'keywords' => $query, 'apikey' => $apiKey]
    : ['function' => 'GLOBAL_QUOTE', 'symbol' => $query, 'apikey' => $apiKey];
$curl = curl_init('https://www.alphavantage.co/query?' . http_build_query($parameters));
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Budgetkoll/2.0'],
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
if (!is_string($response) || $status !== 200) {
    respond(502, ['error' => 'Kurstjänsten svarade inte.']);
}
try {
    $body = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    respond(502, ['error' => 'Kurstjänsten gav ett ogiltigt svar.']);
}
$serviceError = $body['Error Message'] ?? $body['Note'] ?? $body['Information'] ?? null;
if (is_string($serviceError)) {
    respond(502, ['error' => $serviceError]);
}
if ($action === 'search') {
    $matches = [];
    foreach (is_array($body['bestMatches'] ?? null) ? $body['bestMatches'] : [] as $item) {
        if (!is_array($item) || !is_string($item['1. symbol'] ?? null)) {
            continue;
        }
        $matches[] = [
            'symbol' => $item['1. symbol'],
            'name' => (string) ($item['2. name'] ?? ''),
            'type' => (string) ($item['3. type'] ?? ''),
            'region' => (string) ($item['4. region'] ?? ''),
            'currency' => (string) ($item['8. currency'] ?? ''),
            'matchScore' => (float) ($item['9. matchScore'] ?? 0),
        ];
    }
    respond(200, ['matches' => array_slice($matches, 0, 10)]);
}
$quote = $body['Global Quote'] ?? null;
$price = is_array($quote) ? filter_var($quote['05. price'] ?? null, FILTER_VALIDATE_FLOAT) : false;
if ($price === false || $price < 0) {
    respond(404, ['error' => 'Ingen kurs hittades för symbolen.']);
}
respond(200, [
    'symbol' => (string) ($quote['01. symbol'] ?? $query),
    'price' => (float) $price,
    'date' => (string) ($quote['07. latest trading day'] ?? ''),
]);
