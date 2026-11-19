<?php
declare(strict_types=1);

require __DIR__ . '/run-physics.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$home = dirname(__DIR__, 3);
$privateDir = $home . '/zikatec-private/coolerparoon';
$configPath = $privateDir . '/config.php';

try {
    if (!is_dir($privateDir) && !mkdir($privateDir, 0700, true) && !is_dir($privateDir)) {
        throw new RuntimeException('Private storage is unavailable.');
    }
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $storageRoot = realpath($privateDir);
    if ($documentRoot === false || $storageRoot === false ||
        $storageRoot === $documentRoot || str_starts_with($storageRoot, rtrim($documentRoot, '/') . '/')) {
        throw new RuntimeException('Private storage must be outside the document root.');
    }
    $privateDir = $storageRoot;
    $configPath = $privateDir . '/config.php';
    @chmod($privateDir, 0700);

    $secretPath = $privateDir . '/auth.secret';
    $secretLock = fopen($privateDir . '/secret.lock', 'c');
    if ($secretLock === false || !flock($secretLock, LOCK_EX)) {
        throw new RuntimeException('Authentication secret is unavailable.');
    }
    if (!is_file($secretPath)) {
        if (file_put_contents($secretPath, random_bytes(32), LOCK_EX) === false) {
            flock($secretLock, LOCK_UN);
            fclose($secretLock);
            throw new RuntimeException('Authentication secret could not be created.');
        }
        @chmod($secretPath, 0600);
    }
    $authSecret = file_get_contents($secretPath);
    flock($secretLock, LOCK_UN);
    fclose($secretLock);
    if ($authSecret === false || strlen($authSecret) < 32) {
        throw new RuntimeException('Authentication secret is unavailable.');
    }

    $config = [];
    if (is_file($configPath)) {
        $loadedConfig = require $configPath;
        if (is_array($loadedConfig)) {
            $config = $loadedConfig;
        }
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET' && cookieToken() !== null && ($_SERVER['HTTP_X_KOLOR_REQUEST'] ?? '') !== '1') {
        respond(403, ['error' => 'درخواست معتبر نیست.']);
    }
    $route = trim((string)($_GET['path'] ?? ''), '/');

    if ($method === 'POST' && $route === 'auth/request-code') {
        $body = readJsonBody();
        $mobile = normalizePhone($body['phone'] ?? '');
        if ($mobile === null) {
            respond(400, ['error' => 'شمارهٔ موبایل معتبر وارد کنید.']);
        }

        $apiKey = trim((string)($config['smsir_api_key'] ?? ''));
        $templateId = (int)($config['smsir_template_id'] ?? 0);
        $parameterName = trim((string)($config['smsir_template_parameter'] ?? 'CODE')) ?: 'CODE';
        if ($apiKey === '' || $templateId < 1) {
            respond(503, ['error' => 'ارسال پیامک هنوز پیکربندی نشده است.']);
        }

        $phoneHash = hmacValue('phone:' . $mobile);
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $challenge = withStore(function (&$state) use ($phoneHash, $remoteAddress): array {
            pruneState($state);
            if (!allowRate($state, 'ip:' . $remoteAddress, 20, 600000) ||
                !allowRate($state, 'phone:' . $phoneHash, 3, 600000, 60000)) {
                return ['status' => 429, 'error' => 'برای درخواست دوباره کمی صبر کنید.'];
            }
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $now = nowMs();
            $state['otps'][$phoneHash] = [
                'code_hash' => hmacValue('otp:' . $phoneHash . ':' . $code),
                'expires_at' => $now + 300000,
                'attempts' => 0,
                'created_at' => $now,
            ];
            return ['status' => 200, 'code' => $code];
        });

        if ($challenge['status'] !== 200) {
            respond($challenge['status'], ['error' => $challenge['error']]);
        }
        [$sent, $reason] = sendSmsOtp($mobile, $challenge['code'], $apiKey, $templateId, $parameterName);
        if (!$sent) {
            withStore(function (&$state) use ($phoneHash): void {
                unset($state['otps'][$phoneHash]);
            });
            error_log('SMS.ir verification request failed: ' . $reason);
            respond(502, ['error' => 'ارسال پیامک انجام نشد. کمی بعد دوباره تلاش کنید.']);
        }
        respond(200, ['message' => 'کد تأیید تا ۵ دقیقه معتبر است.', 'expiresIn' => 300]);
    }

    if ($method === 'POST' && $route === 'auth/verify-code') {
        $body = readJsonBody();
        $mobile = normalizePhone($body['phone'] ?? '');
        $code = is_string($body['code'] ?? null) ? trim($body['code']) : '';
        if ($mobile === null || !preg_match('/^\d{6}$/', $code)) {
            respond(400, ['error' => 'شماره یا کد تأیید معتبر نیست.']);
        }
        $phoneHash = hmacValue('phone:' . $mobile);
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $result = withStore(function (&$state) use ($phoneHash, $code, $remoteAddress): array {
            pruneState($state);
            if (!allowRate($state, 'verify:' . $remoteAddress, 40, 600000)) {
                return ['status' => 429, 'error' => 'برای تلاش دوباره کمی صبر کنید.'];
            }
            $challenge = $state['otps'][$phoneHash] ?? null;
            if (!$challenge || $challenge['expires_at'] < nowMs() || $challenge['attempts'] >= 5) {
                unset($state['otps'][$phoneHash]);
                return ['status' => 400, 'error' => 'کد منقضی شده است؛ کد تازه بگیرید.'];
            }
            $state['otps'][$phoneHash]['attempts']++;
            $expected = (string)$challenge['code_hash'];
            $actual = hmacValue('otp:' . $phoneHash . ':' . $code);
            if (!hash_equals($expected, $actual)) {
                return ['status' => 400, 'error' => 'کد واردشده درست نیست.'];
            }

            unset($state['otps'][$phoneHash]);
            $player = null;
            foreach ($state['players'] as $candidate) {
                if (hash_equals((string)$candidate['phone_hash'], $phoneHash)) {
                    $player = $candidate;
                    break;
                }
            }
            if ($player === null) {
                $guestName = 'guest' . (int)$state['next_guest'];
                $state['next_guest']++;
                $playerId = bin2hex(random_bytes(16));
                $player = [
                    'id' => $playerId,
                    'phone_hash' => $phoneHash,
                    'created_at' => nowMs(),
                    'best_score' => 0,
                    'guest_name' => $guestName,
                    'username' => $guestName,
                ];
                $state['players'][$playerId] = $player;
            }

            $token = base64Url(random_bytes(32));
            $state['sessions'][hash('sha256', $token)] = [
                'player_id' => $player['id'],
                'expires_at' => nowMs() + 30 * 24 * 60 * 60 * 1000,
            ];
            return ['status' => 200, 'token' => $token, 'player' => playerView($player)];
        });
        if ($result['status'] !== 200) {
            respond($result['status'], ['error' => $result['error']]);
        }
        setSessionCookie($result['token'], time() + 30 * 24 * 60 * 60);
        respond(200, ['player' => $result['player']]);
    }

    if ($method === 'POST' && $route === 'auth/logout') {
        $token = sessionToken();
        if ($token !== null) {
            $tokenHash = hash('sha256', $token);
            withStore(function (&$state) use ($tokenHash): void {
                unset($state['sessions'][$tokenHash]);
            });
        }
        setSessionCookie('', 1);
        respond(200, ['ok' => true]);
    }

    if ($method === 'GET' && $route === 'me') {
        $result = withStore(function (&$state): array {
            $player = authenticatedPlayer($state);
            return $player === null
                ? ['status' => 401, 'error' => 'برای ادامه وارد حساب شوید.']
                : ['status' => 200, 'player' => playerView($player)];
        }, false);
        if ($result['status'] !== 200) {
            respond($result['status'], ['error' => $result['error']]);
        }
        if (cookieToken() === null) {
            setSessionCookie(sessionToken(), time() + 30 * 24 * 60 * 60);
        }
        respond(200, ['player' => $result['player']]);
    }

    if ($method === 'PUT' && $route === 'me/username') {
        $body = readJsonBody();
        $result = withStore(function (&$state) use ($body): array {
            pruneState($state);
            $player = authenticatedPlayer($state);
            if ($player === null) {
                return ['status' => 401, 'error' => 'برای ادامه وارد حساب شوید.'];
            }
            if (!is_string($body['username'] ?? null)) {
                return ['status' => 400, 'error' => 'نام معتبر نیست.'];
            }
            try {
                $username = normalizeUsername($body['username']);
            } catch (InvalidArgumentException $error) {
                return ['status' => 400, 'error' => $error->getMessage()];
            }
            $state['players'][$player['id']]['username'] = $username;
            $state['players'][$player['id']]['username_chosen'] = true;
            return ['status' => 200, 'player' => playerView($state['players'][$player['id']])];
        });
        if ($result['status'] !== 200) {
            respond($result['status'], ['error' => $result['error']]);
        }
        respond(200, ['player' => $result['player']]);
    }

    if ($method === 'GET' && $route === 'leaderboard') {
        $leaderboard = withStore(function (&$state): array {
            $viewer = authenticatedPlayer($state);
            $players = array_values(array_filter($state['players'], static fn(array $player): bool => (int)$player['best_score'] > 0));
            usort($players, static function (array $a, array $b): int {
                return ((int)$b['best_score'] <=> (int)$a['best_score'])
                    ?: ((int)$a['created_at'] <=> (int)$b['created_at'])
                    ?: strcmp((string)$a['id'], (string)$b['id']);
            });
            $leaders = [];
            $ownRank = null;
            foreach ($players as $index => $player) {
                $isYou = $player['id'] === ($viewer['id'] ?? null);
                if ($index < 5) {
                    $leaders[] = leaderboardEntry($player, $index + 1, $isYou);
                }
                if ($isYou) {
                    $ownRank = $index + 1;
                }
            }
            $personal = $viewer !== null && ($ownRank === null || $ownRank > 5)
                ? leaderboardEntry($viewer, $ownRank, true) : null;
            return ['players' => $leaders, 'personal' => $personal];
        }, false);
        respond(200, $leaderboard);
    }

    if ($method === 'POST' && $route === 'runs') {
        $body = readJsonBody();
        $width = $body['width'] ?? null;
        if (!is_int($width) || $width < 240 || $width > 1920) {
            respond(400, ['error' => 'عرض میدان بازی معتبر نیست.']);
        }
        $result = withStore(function (&$state) use ($width): array {
            pruneState($state);
            $player = authenticatedPlayer($state);
            if ($player === null) {
                return ['status' => 401, 'error' => 'برای ادامه وارد حساب شوید.'];
            }
            if (needsUsername($player)) {
                return ['status' => 403, 'error' => 'قبل از شروع بازی، نام نمایشی‌ات را ذخیره کن.'];
            }
            if (!allowRate($state, 'runs:' . $player['id'], 60, 600000)) {
                return ['status' => 429, 'error' => 'تعداد دورهای شروع‌شده زیاد است؛ کمی بعد دوباره تلاش کنید.'];
            }
            $runId = uuidV4();
            $seed = random_int(1, 2147483646);
            $state['runs'][$runId] = [
                'player_id' => $player['id'], 'started_at' => nowMs(), 'seed' => $seed, 'width' => $width,
            ];
            return ['status' => 201, 'runId' => $runId, 'seed' => $seed, 'width' => $width];
        });
        if ($result['status'] !== 201) {
            respond($result['status'], ['error' => $result['error']]);
        }
        respond(201, ['runId' => $result['runId'], 'seed' => $result['seed'], 'width' => $result['width']]);
    }

    if ($method === 'POST' && $route === 'scores') {
        $body = readJsonBody(65536);
        $result = withStore(function (&$state) use ($body): array {
            pruneState($state);
            $player = authenticatedPlayer($state);
            if ($player === null) {
                return ['status' => 401, 'error' => 'نشست شما منقضی شده است؛ دوباره وارد شوید.'];
            }
            if (needsUsername($player)) {
                return ['status' => 403, 'error' => 'قبل از ثبت امتیاز، نام نمایشی‌ات را ذخیره کن.'];
            }
            $runId = $body['runId'] ?? null;
            $endTick = $body['endTick'] ?? null;
            $jumps = $body['jumps'] ?? null;
            if (!is_int($endTick) || !is_array($jumps) || !is_string($runId) ||
                !preg_match('/^[0-9a-f-]{36}$/i', $runId)) {
                return ['status' => 400, 'error' => 'اطلاعات رکورد معتبر نیست.'];
            }
            $run = $state['runs'][$runId] ?? null;
            if (!$run || $run['player_id'] !== $player['id'] || !isset($run['seed'], $run['width'])) {
                return ['status' => 400, 'error' => 'دور بازی معتبر نیست.'];
            }
            $durationMs = nowMs() - (int)$run['started_at'];
            $score = verifyRun($run, $endTick, $jumps, $durationMs);
            unset($state['runs'][$runId]);
            if ($score === null) {
                return ['status' => 400, 'error' => 'ورودی‌های این دور با نتیجهٔ بازی سازگار نیست.'];
            }
            $state['players'][$player['id']]['best_score'] = max((int)$player['best_score'], $score);
            return ['status' => 201, 'player' => playerView($state['players'][$player['id']])];
        });
        if ($result['status'] !== 201) {
            respond($result['status'], ['error' => $result['error']]);
        }
        respond(201, ['player' => $result['player']]);
    }

    respond(404, ['error' => 'مسیر پیدا نشد.']);
} catch (Throwable $error) {
    error_log('Cooler Paroon API error: ' . $error->getMessage());
    respond(500, ['error' => 'خطای داخلی سرور.']);
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nowMs(): int
{
    return (int)floor(microtime(true) * 1000);
}

function base64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function uuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function hmacValue(string $value): string
{
    global $authSecret;
    return hash_hmac('sha256', $value, $authSecret);
}

function normalizePhone(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $digits = strtr(trim((string)$value), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    $digits = preg_replace('/[\s()\-]/u', '', $digits) ?? '';
    if (preg_match('/^09\d{9}$/', $digits)) {
        return '+98' . substr($digits, 1);
    }
    if (preg_match('/^989\d{9}$/', $digits)) {
        return '+' . $digits;
    }
    if (preg_match('/^\+989\d{9}$/', $digits)) {
        return $digits;
    }
    return null;
}

function readJsonBody(int $maximum = 8192): array
{
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'application/json') {
        respond(415, ['error' => 'نوع درخواست معتبر نیست.']);
    }
    $input = file_get_contents('php://input', false, null, 0, $maximum + 1);
    if ($input === false || strlen($input) > $maximum) {
        respond(413, ['error' => 'درخواست بیش از حد بزرگ است.']);
    }
    try {
        $body = json_decode($input, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'درخواست قابل خواندن نیست.']);
    }
    if (!$body instanceof stdClass) {
        respond(400, ['error' => 'درخواست قابل خواندن نیست.']);
    }
    return (array)$body;
}

function initialState(): array
{
    return ['next_guest' => 52163, 'players' => [], 'otps' => [], 'sessions' => [], 'runs' => [], 'limits' => []];
}

function withStore(callable $callback, bool $write = true): mixed
{
    global $privateDir;
    $lock = fopen($privateDir . '/state.lock', 'c');
    if ($lock === false || !flock($lock, $write ? LOCK_EX : LOCK_SH)) {
        throw new RuntimeException('Private storage is unavailable.');
    }
    @chmod($privateDir . '/state.lock', 0600);
    try {
        $statePath = $privateDir . '/state.json';
        if (is_file($statePath)) {
            $raw = file_get_contents($statePath);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($state) || !isset($state['players'], $state['otps'], $state['sessions'], $state['runs'], $state['limits'])) {
                throw new RuntimeException('Private storage is unreadable.');
            }
        } else {
            $state = initialState();
        }

        $result = $callback($state);
        if (!$write) {
            return $result;
        }
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporaryPath = $privateDir . '/state.' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            if (file_put_contents($temporaryPath, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new RuntimeException('Private storage could not be written.');
            }
            @chmod($temporaryPath, 0600);
            if (!rename($temporaryPath, $statePath)) {
                throw new RuntimeException('Private storage could not be updated.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function pruneState(array &$state): void
{
    $now = nowMs();
    foreach ($state['otps'] as $key => $challenge) {
        if ((int)$challenge['expires_at'] <= $now) {
            unset($state['otps'][$key]);
        }
    }
    foreach ($state['sessions'] as $key => $session) {
        if ((int)$session['expires_at'] <= $now) {
            unset($state['sessions'][$key]);
        }
    }
    foreach ($state['runs'] as $key => $run) {
        if ($now - (int)$run['started_at'] >= RUN_LIFETIME_MS || !isset($run['seed'], $run['width'])) {
            unset($state['runs'][$key]);
        }
    }
    foreach ($state['limits'] as $key => $limit) {
        if ($now - (int)$limit['started_at'] > 600000) {
            unset($state['limits'][$key]);
        }
    }
}

function allowRate(array &$state, string $key, int $maximum, int $intervalMs, int $cooldownMs = 0): bool
{
    $key = hmacValue('rate:' . $key);
    $now = nowMs();
    $entry = $state['limits'][$key] ?? null;
    if (!is_array($entry) || $now - (int)$entry['started_at'] >= $intervalMs) {
        $entry = ['started_at' => $now, 'last_at' => 0, 'count' => 0];
    }
    if (($cooldownMs > 0 && $now - (int)$entry['last_at'] < $cooldownMs) || (int)$entry['count'] >= $maximum) {
        $state['limits'][$key] = $entry;
        return false;
    }
    $entry['count']++;
    $entry['last_at'] = $now;
    $state['limits'][$key] = $entry;
    return true;
}

function cookieToken(): ?string
{
    $token = $_COOKIE['kolor_session'] ?? null;
    return is_string($token) && preg_match('/^[A-Za-z0-9_-]{40,100}$/D', $token) ? $token : null;
}

function sessionToken(): ?string
{
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return cookieToken() ?? (preg_match('/^Bearer ([A-Za-z0-9_-]{40,100})$/D', $authorization, $match) ? $match[1] : null);
}

function setSessionCookie(string $token, int $expires): void
{
    setcookie('kolor_session', $token, [
        'expires' => $expires, 'path' => '/game/coolerparoon/', 'secure' => true,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}

function authenticatedPlayer(array $state): ?array
{
    $token = sessionToken();
    if ($token === null) {
        return null;
    }
    $session = $state['sessions'][hash('sha256', $token)] ?? null;
    if (!is_array($session) || (int)$session['expires_at'] <= nowMs()) {
        return null;
    }
    return $state['players'][$session['player_id']] ?? null;
}

function playerView(array $player): array
{
    return [
        'id' => (string)$player['id'],
        'username' => (string)$player['username'],
        'guestName' => (string)$player['guest_name'],
        'bestScore' => (int)$player['best_score'],
        'needsUsername' => needsUsername($player),
    ];
}

function needsUsername(array $player): bool
{
    $username = (string)($player['username'] ?? '');
    return empty($player['username_chosen']) && ($username === '' || $username === (string)$player['guest_name']);
}

function leaderboardEntry(array $player, ?int $rank, bool $isYou): array
{
    return [
        'username' => (string)$player['username'], 'bestScore' => (int)$player['best_score'],
        'rank' => $rank, 'isYou' => $isYou,
    ];
}

function normalizeUsername(string $value): string
{
    $username = trim(preg_replace('/[\p{Z}\s\x{FEFF}]+/u', ' ', $value) ?? '');
    if ($username === '') {
        throw new InvalidArgumentException('برای ادامه، یک نام نمایشی وارد کن.');
    }
    preg_match_all('/./us', $username, $characters);
    $length = count($characters[0]);
    if ($length > 24 || !preg_match('/^[\p{L}\p{M}\p{N}\x{200C}_. -]+$/u', $username) || !preg_match('/[\p{L}\p{N}]/u', $username)) {
        throw new InvalidArgumentException('نام باید ۱ تا ۲۴ حرف یا عدد باشد؛ فاصله، نقطه، خط تیره و زیرخط هم مجاز است.');
    }
    return $username;
}

function sendSmsOtp(string $mobile, string $code, string $apiKey, int $templateId, string $parameterName): array
{
    $payload = json_encode([
        'mobile' => '0' . substr($mobile, 3),
        'templateId' => $templateId,
        'parameters' => [['name' => $parameterName, 'value' => $code]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return [false, 'درخواست SMS.ir آماده نشد.'];
    }

    if (function_exists('curl_init')) {
        $curl = curl_init('https://api.sms.ir/v1/send/verify');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-API-KEY: ' . $apiKey],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($curl);
        $statusCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $transportError = $responseBody === false;
        curl_close($curl);
        if ($transportError) {
            return [false, 'ارتباط با سرویس SMS.ir برقرار نشد؛ اتصال اینترنت و دسترسی سرور را بررسی کنید.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-API-KEY: " . $apiKey . "\r\n",
                'content' => $payload,
                'timeout' => 12,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $responseBody = @file_get_contents('https://api.sms.ir/v1/send/verify', false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
                $statusCode = (int)$match[1];
            }
        }
        if ($responseBody === false) {
            return [false, 'ارتباط با سرویس SMS.ir برقرار نشد؛ اتصال اینترنت و دسترسی سرور را بررسی کنید.'];
        }
    }

    $result = json_decode((string)$responseBody, true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($result) || (int)($result['status'] ?? 0) !== 1) {
        $message = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)($result['message'] ?? '')) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');
        preg_match_all('/./us', $message, $messageCharacters);
        if (count($messageCharacters[0]) > 180) {
            $message = implode('', array_slice($messageCharacters[0], 0, 180));
        }
        $reason = $statusCode > 0 ? 'HTTP ' . $statusCode : 'کد ' . (string)($result['status'] ?? 'نامشخص');
        return [false, $reason . ($message !== '' ? ': ' . $message : '')];
    }
    return [true, ''];
}
