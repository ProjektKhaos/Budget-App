<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Endast POST stöds.']);
}

requireAuthenticatedUser();
requireWriteSecurity();

$client = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateFile = sys_get_temp_dir() . '/budgetkoll-avanza-rate-' . hash('sha256', $client);
$rateHandle = fopen($rateFile, 'c+');
if ($rateHandle !== false && flock($rateHandle, LOCK_EX)) {
    $now = time();
    $rawRateData = stream_get_contents($rateHandle);
    $timestamps = json_decode($rawRateData ?: '[]', true);
    $timestamps = is_array($timestamps)
        ? array_values(array_filter($timestamps, static fn ($value): bool =>
            is_int($value) && $value > $now - 60
        ))
        : [];
    if (count($timestamps) >= 60) {
        flock($rateHandle, LOCK_UN);
        fclose($rateHandle);
        respond(429, ['error' => 'För många kursförfrågningar. Försök igen om en minut.']);
    }
    $timestamps[] = $now;
    ftruncate($rateHandle, 0);
    rewind($rateHandle);
    fwrite($rateHandle, json_encode($timestamps));
    fflush($rateHandle);
    flock($rateHandle, LOCK_UN);
    fclose($rateHandle);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
$query = is_array($input) && is_string($input['query'] ?? null)
    ? trim($input['query'])
    : '';
$type = is_array($input) && is_string($input['type'] ?? null)
    ? strtolower(trim($input['type']))
    : '';
if ($query === '' || mb_strlen($query) > 80 || !in_array($type, ['stock', 'fund', 'certificate'], true)) {
    respond(400, ['error' => 'Ogiltig instrumentsökning.']);
}

$payload = json_encode([
    'query' => $query,
    'searchFilter' => ['types' => [strtoupper($type)]],
    'pagination' => ['from' => 0, 'size' => 3],
], JSON_UNESCAPED_UNICODE);
$curl = curl_init('https://www.avanza.se/_api/search/filtered-search');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: Budgetkoll/1.0 read-only market lookup',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);
curl_close($curl);
if (!is_string($response) || $status !== 200) {
    respond(502, [
        'error' => $curlError !== ''
            ? 'Avanzas marknadsdata kunde inte nås.'
            : 'Avanza svarade inte med marknadsdata.',
    ]);
}

$body = json_decode($response, true);
$hits = is_array($body) && is_array($body['hits'] ?? null) ? $body['hits'] : [];
$hit = $hits[0] ?? null;
$price = is_array($hit) && is_array($hit['price'] ?? null)
    ? ($hit['price']['last'] ?? null)
    : null;
if (!is_array($hit) || !is_string($price) || trim($price) === '') {
    respond(404, ['error' => 'Ingen aktuell Avanza-kurs hittades.']);
}

respond(200, [
    'name' => (string) ($hit['title'] ?? $query),
    'orderBookId' => (string) ($hit['orderBookId'] ?? ''),
    'price' => $price,
    'currency' => (string) ($hit['price']['currency'] ?? ''),
    'todayChangePercent' => $hit['price']['todayChangePercent'] ?? null,
    'todayChangeValue' => $hit['price']['todayChangeValue'] ?? null,
    'date' => gmdate('Y-m-d'),
    'source' => 'avanza-public',
]);
