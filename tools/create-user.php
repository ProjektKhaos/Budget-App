<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Skriptet får endast köras i terminalen.\n");
    exit(1);
}

$username = trim((string) ($argv[1] ?? ''));
$name = trim((string) ($argv[2] ?? ''));
if ($username === '' || $name === '') {
    fwrite(STDERR, "Användning: php tools/create-user.php <användarnamn> \"<visningsnamn>\"\n");
    exit(1);
}

fwrite(STDERR, 'Lösenord: ');
$hideInput = PHP_OS_FAMILY !== 'Windows'
    && function_exists('stream_isatty')
    && stream_isatty(STDIN);
if ($hideInput) {
    shell_exec('stty -echo');
}
$password = trim((string) fgets(STDIN));
if ($hideInput) {
    shell_exec('stty echo');
}
fwrite(STDERR, "\n");

if (strlen($password) < 12) {
    fwrite(STDERR, "Lösenordet måste innehålla minst 12 tecken.\n");
    exit(1);
}

$payload = [
    'username' => $username,
    'name' => $name,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
];

echo json_encode(
    $payload,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
), "\n";
