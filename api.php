<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_name('zikatec_admin_session');
session_set_cookie_params([
    'lifetime' => 28800,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$privateDirectory = dirname((string) ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__)) . DIRECTORY_SEPARATOR . 'zikatec-private';
$configFile = $privateDirectory . DIRECTORY_SEPARATOR . 'config.php';
$dataFile = $privateDirectory . DIRECTORY_SEPARATOR . 'consultations.json';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        respond(405, ['error' => 'Bu işlem için geçersiz istek yöntemi.']);
    }
}

function readBody(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        respond(413, ['error' => 'Gönderilen bilgiler çok büyük.']);
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Gönderilen bilgiler okunamadı.']);
    }
    return $data;
}

function cleanText($value, int $maxLength): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength, 'UTF-8')
        : substr($text, 0, $maxLength);
}

function ensurePrivateStorage(string $directory, string $file): void
{
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        respond(500, ['error' => 'Kayıt alanı oluşturulamadı.']);
    }
    if (!file_exists($file)) {
        if (file_put_contents($file, "[]\n", LOCK_EX) === false) {
            respond(500, ['error' => 'Kayıt dosyası oluşturulamadı.']);
        }
        @chmod($file, 0600);
    }
}

function readRecords(string $file): array
{
    $handle = fopen($file, 'c+');
    if ($handle === false) respond(500, ['error' => 'Kayıtlar okunamadı.']);
    try {
        if (!flock($handle, LOCK_SH)) respond(500, ['error' => 'Kayıtlar kilitlenemedi.']);
        rewind($handle);
        $contents = stream_get_contents($handle);
        $records = json_decode($contents ?: '[]', true);
        return is_array($records) ? $records : [];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function updateRecords(string $file, callable $callback)
{
    $handle = fopen($file, 'c+');
    if ($handle === false) respond(500, ['error' => 'Kayıt dosyası açılamadı.']);
    try {
        if (!flock($handle, LOCK_EX)) respond(500, ['error' => 'Kayıtlar kilitlenemedi.']);
        rewind($handle);
        $contents = stream_get_contents($handle);
        $records = json_decode($contents ?: '[]', true);
        if (!is_array($records)) $records = [];

        $result = $callback($records);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($handle);
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function requireAdmin(): void
{
    if (empty($_SESSION['admin_authenticated']) || empty($_SESSION['admin_username'])) {
        respond(401, ['error' => 'Oturum açmanız gerekiyor.']);
    }
    $lastSeen = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($lastSeen > 0 && time() - $lastSeen > 28800) {
        $_SESSION = [];
        session_destroy();
        respond(401, ['error' => 'Oturumunuz sona erdi.']);
    }
    $_SESSION['admin_last_seen'] = time();
}

function loadCredentials(string $configFile): array
{
    $username = getenv('ZIKATEC_ADMIN_USER') ?: '';
    $password = getenv('ZIKATEC_ADMIN_PASSWORD') ?: '';

    if (($username === '' || $password === '') && is_file($configFile)) {
        $config = require $configFile;
        if (is_array($config)) {
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');
        }
    }

    $configured = $username !== '' && $password !== ''
        && $username !== 'CHANGE_ME' && $password !== 'CHANGE_ME_NOW';
    return [$configured, $username, $password];
}

ensurePrivateStorage($privateDirectory, $dataFile);
$action = (string) ($_GET['action'] ?? '');

if ($action === 'create') {
    requireMethod('POST');
    $body = readBody();
    $entry = [
        'id' => bin2hex(random_bytes(16)),
        'createdAt' => gmdate('c'),
        'fullName' => cleanText($body['fullName'] ?? '', 120),
        'phone' => cleanText($body['phone'] ?? '', 40),
        'city' => cleanText($body['city'] ?? '', 80),
        'industry' => cleanText($body['industry'] ?? '', 100),
        'area' => cleanText($body['area'] ?? '', 30),
        'message' => cleanText($body['message'] ?? '', 1200),
        'source' => 'website',
        'status' => 'new',
    ];
    if ($entry['fullName'] === '' || $entry['phone'] === '' || $entry['message'] === '') {
        respond(422, ['error' => 'Ad soyad, telefon ve açıklama zorunludur.']);
    }
    updateRecords($dataFile, function (array &$records) use ($entry): void {
        $records[] = $entry;
    });
    respond(201, ['ok' => true, 'id' => $entry['id']]);
}

if ($action === 'login') {
    requireMethod('POST');
    [$configured, $adminUser, $adminPassword] = loadCredentials($configFile);
    if (!$configured) {
        respond(503, ['error' => 'Yönetici hesabı henüz sunucuda ayarlanmamış.']);
    }
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
    if ($blockedUntil > time()) {
        respond(429, ['error' => 'Çok fazla hatalı deneme. Lütfen daha sonra tekrar deneyin.']);
    }
    $body = readBody();
    $valid = hash_equals($adminUser, (string) ($body['username'] ?? ''))
        && hash_equals($adminPassword, (string) ($body['password'] ?? ''));
    if (!$valid) {
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) $_SESSION['login_blocked_until'] = time() + 600;
        respond(401, ['error' => 'Kullanıcı adı veya parola hatalı.']);
    }
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $adminUser;
    $_SESSION['admin_last_seen'] = time();
    unset($_SESSION['login_attempts'], $_SESSION['login_blocked_until']);
    respond(200, ['ok' => true, 'username' => $adminUser]);
}

if ($action === 'session') {
    requireMethod('GET');
    requireAdmin();
    respond(200, ['authenticated' => true, 'username' => $_SESSION['admin_username']]);
}

if ($action === 'logout') {
    requireMethod('POST');
    requireAdmin();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}

if ($action === 'list') {
    requireMethod('GET');
    requireAdmin();
    $records = readRecords($dataFile);
    usort($records, static fn(array $a, array $b): int => strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? '')));
    respond(200, ['items' => $records]);
}

if ($action === 'status') {
    requireMethod('POST');
    requireAdmin();
    $body = readBody();
    $id = cleanText($body['id'] ?? '', 64);
    $status = cleanText($body['status'] ?? '', 20);
    if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
        respond(422, ['error' => 'Geçersiz durum.']);
    }
    $updated = updateRecords($dataFile, function (array &$records) use ($id, $status): ?array {
        foreach ($records as &$record) {
            if (($record['id'] ?? '') === $id) {
                $record['status'] = $status;
                $record['updatedAt'] = gmdate('c');
                return $record;
            }
        }
        return null;
    });
    if ($updated === null) respond(404, ['error' => 'Talep bulunamadı.']);
    respond(200, ['ok' => true, 'item' => $updated]);
}

respond(404, ['error' => 'API işlemi bulunamadı.']);
