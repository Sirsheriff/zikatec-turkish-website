<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('max_execution_time', '240');

session_name('zikatec_admin_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');

const PRIVATE_DIR = '/home3/zikatecn/zikatec-private';
const SESSION_TIMEOUT = 3600;
const CACHE_TTL = 3600;
const MAX_LOGIN_ATTEMPTS = 6;
const LOGIN_WINDOW = 900;

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function privateConfig(): array {
    $file = PRIVATE_DIR . '/config.php';
    if (!is_file($file)) return [];
    $value = require $file;
    return is_array($value) ? $value : [];
}

function envOr(array $config, string $env, string $key): string {
    $value = getenv($env);
    return $value !== false && $value !== '' ? (string) $value : trim((string) ($config[$key] ?? ''));
}

function adminCredentials(array $config): array {
    $username = envOr($config, 'ZIKATEC_ADMIN_USER', 'username');
    $password = envOr($config, 'ZIKATEC_ADMIN_PASSWORD', 'password');
    $hash = envOr($config, 'ZIKATEC_ADMIN_PASSWORD_HASH', 'password_hash');
    $configured = $username !== '' && $username !== 'CHANGE_ME'
        && (($password !== '' && $password !== 'CHANGE_ME_NOW') || ($hash !== '' && !empty(password_get_info($hash)['algo'])));
    return [$configured, $username, $password, $hash];
}

function authenticated(): bool {
    if (empty($_SESSION['admin_authenticated']) || empty($_SESSION['admin_username'])) return false;
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last < time() - SESSION_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['admin_last_seen'] = time();
    return true;
}

function requireAuth(): void {
    if (!authenticated()) respond(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'نشست شما معتبر نیست؛ دوباره وارد شوید.']]);
}

function csrfToken(): string {
    if (empty($_SESSION['admin_csrf_token'])) $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    return (string) $_SESSION['admin_csrf_token'];
}

function requireCsrf(): void {
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['admin_csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) respond(403, ['error' => ['code' => 'CSRF_FAILED', 'message' => 'درخواست امنیتی معتبر نیست.']]);
}

function rateFile(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return PRIVATE_DIR . '/login-' . hash('sha256', $ip) . '.json';
}

function loginAttempts(): array {
    $file = rateFile();
    if (!is_file($file)) return [];
    $values = json_decode((string) file_get_contents($file), true);
    return array_values(array_filter(is_array($values) ? $values : [], fn($time) => (int) $time > time() - LOGIN_WINDOW));
}

function recordLoginFailure(array $attempts): void {
    if (!is_dir(PRIVATE_DIR)) @mkdir(PRIVATE_DIR, 0700, true);
    $attempts[] = time();
    file_put_contents(rateFile(), json_encode($attempts), LOCK_EX);
    @chmod(rateFile(), 0600);
}

function clearLoginFailures(): void { $file = rateFile(); if (is_file($file)) @unlink($file); }

function listValue(array $config, string $env, string $key): array {
    $raw = getenv($env);
    $value = $raw !== false && $raw !== '' ? $raw : ($config[$key] ?? []);
    if (is_string($value)) $value = explode(',', $value);
    return array_values(array_filter(array_map(fn($item) => trim((string) $item), is_array($value) ? $value : []), fn($item) => $item !== ''));
}

function odooConfig(array $config): array {
    $base = rtrim(envOr($config, 'ODOO_BASE_URL', 'odoo_base_url'), '/');
    $database = envOr($config, 'ODOO_DATABASE', 'odoo_database');
    $apiKey = envOr($config, 'ODOO_API_KEY', 'odoo_api_key');
    if ($base === '' || $database === '' || $apiKey === '' || $database === 'CHANGE_ME' || $apiKey === 'CHANGE_ME') {
        respond(503, ['error' => ['code' => 'ODOO_NOT_CONFIGURED', 'message' => 'تنظیمات امن Odoo روی هاست هنوز کامل نشده است.']]);
    }
    $parts = parse_url($base);
    if (($parts['scheme'] ?? '') !== 'https') respond(500, ['error' => ['code' => 'ODOO_HTTPS_REQUIRED', 'message' => 'آدرس Odoo باید HTTPS باشد.']]);
    return [
        'baseUrl' => $base,
        'database' => $database,
        'apiKey' => $apiKey,
        'deviceProductIds' => listValue($config, 'ODOO_DEVICE_PRODUCT_IDS', 'odoo_device_product_ids'),
        'deviceCategoryIds' => listValue($config, 'ODOO_DEVICE_CATEGORY_IDS', 'odoo_device_category_ids'),
    ];
}

function odooCall(array $config, string $model, string $method, array $payload = []): mixed {
    if (!function_exists('curl_init')) throw new RuntimeException('افزونه cURL روی PHP فعال نیست.');
    $url = $config['baseUrl'] . '/json/2/' . rawurlencode($model) . '/' . rawurlencode($method);
    $payload['context'] = array_merge(['lang' => 'fa_IR', 'tz' => 'Asia/Tehran'], is_array($payload['context'] ?? null) ? $payload['context'] : []);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: bearer ' . $config['apiKey'],
            'X-Odoo-Database: ' . $config['database'],
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: Zikatech-Sales-Dashboard/1.0',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($raw === false || $curlError !== '') throw new RuntimeException('ارتباط با Odoo برقرار نشد.');
    $decoded = json_decode($raw, true);
    if ($status < 200 || $status >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['name'] ?? ('Odoo HTTP ' . $status)) : ('Odoo HTTP ' . $status);
        throw new RuntimeException($message);
    }
    return $decoded;
}

function searchReadAll(array $config, string $model, array $options = []): array {
    $records = [];
    $limit = 1000;
    for ($offset = 0; ; $offset += $limit) {
        $payload = array_merge(['domain' => [], 'fields' => [], 'order' => 'id asc'], $options, ['limit' => $limit, 'offset' => $offset]);
        $page = odooCall($config, $model, 'search_read', $payload);
        if (!is_array($page)) throw new RuntimeException('پاسخ Odoo برای ' . $model . ' معتبر نیست.');
        array_push($records, ...$page);
        if (count($page) < $limit) return $records;
        if (count($records) >= 100000) throw new RuntimeException('تعداد رکوردهای Odoo از سقف ایمن عبور کرد.');
    }
}

function chunkFetch(array $config, string $model, array $ids, array $fields): array {
    $result = [];
    foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
        if (!$chunk) continue;
        array_push($result, ...searchReadAll($config, $model, ['domain' => [['id', 'in', $chunk]], 'fields' => $fields]));
    }
    return $result;
}

function refId(mixed $value): int {
    if (is_array($value) && isset($value[0])) return (int) $value[0];
    return is_numeric($value) ? (int) $value : 0;
}

function synchronize(array $config): array {
    odooCall($config, 'res.users', 'context_get');
    $users = searchReadAll($config, 'res.users', ['domain' => [['share', '=', false]], 'context' => ['active_test' => false], 'fields' => ['id', 'name', 'active']]);
    $leads = searchReadAll($config, 'crm.lead', ['context' => ['active_test' => false], 'fields' => ['id','name','type','active','user_id','team_id','stage_id','partner_id','contact_name','source_id','expected_revenue','probability','create_date','write_date','date_open','date_closed','date_last_stage_update','x_studio_pursuit_of_sales']]);
    $orders = searchReadAll($config, 'sale.order', ['domain' => [['state', '!=', 'cancel']], 'fields' => ['id','name','state','date_order','create_date','amount_total','user_id','team_id','partner_id','opportunity_id']]);
    $invoices = searchReadAll($config, 'account.move', ['domain' => [['move_type','=','out_invoice'],['state','=','posted']], 'fields' => ['id','name','state','move_type','invoice_date','amount_total','amount_total_signed','amount_residual_signed','payment_state','invoice_user_id','partner_id','invoice_line_ids']]);
    $activities = searchReadAll($config, 'mail.activity', ['domain' => [['res_model','=','crm.lead']], 'fields' => ['id','res_id','user_id','activity_type_id','summary','date_deadline','create_date','active']]);
    $messages = searchReadAll($config, 'mail.message', ['domain' => [['model','=','crm.lead']], 'fields' => ['id','res_id','date','subtype_id','author_id']]);
    $contacts = searchReadAll($config, 'res.partner', ['domain' => [['active','=',true]], 'fields' => ['id','name','create_date','user_id','company_type','parent_id','active']]);
    $orderLines = chunkFetch($config, 'sale.order.line', array_map(fn($item) => (int) $item['id'], $orders), ['id','order_id','product_id','product_uom_qty','price_unit','discount','price_subtotal','price_total']);
    $invoiceLineIds = [];
    foreach ($invoices as $invoice) foreach (($invoice['invoice_line_ids'] ?? []) as $id) $invoiceLineIds[] = (int) $id;
    $invoiceLines = chunkFetch($config, 'account.move.line', $invoiceLineIds, ['id','move_id','product_id','quantity','price_subtotal','price_total','sale_line_ids']);
    $productIds = [];
    foreach (array_merge($orderLines, $invoiceLines) as $line) { $id = refId($line['product_id'] ?? null); if ($id) $productIds[] = $id; }
    $products = chunkFetch($config, 'product.product', $productIds, ['id','name','type','categ_id']);
    return [
        'raw' => ['users'=>$users,'leads'=>$leads,'orders'=>$orders,'invoices'=>$invoices,'activities'=>$activities,'crmMessages'=>$messages,'contacts'=>$contacts,'orderLines'=>$orderLines,'invoiceLines'=>$invoiceLines,'productCatalog'=>$products],
        'options' => ['deviceProductIds'=>$config['deviceProductIds'],'deviceCategoryIds'=>$config['deviceCategoryIds']],
        'syncedAt' => gmdate('c'),
        'source' => 'odoo',
    ];
}

function cacheFile(): string { return PRIVATE_DIR . '/sales-dashboard-cache.json'; }

function readCache(): ?array {
    $file = cacheFile();
    if (!is_file($file)) return null;
    $value = json_decode((string) file_get_contents($file), true);
    return is_array($value) ? $value : null;
}

function writeCache(array $value): void {
    if (!is_dir(PRIVATE_DIR)) @mkdir(PRIVATE_DIR, 0700, true);
    $temporary = cacheFile() . '.tmp';
    file_put_contents($temporary, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($temporary, 0600);
    rename($temporary, cacheFile());
}

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$config = privateConfig();

if ($action === 'login' && $method === 'POST') {
    $attempts = loginAttempts();
    if (count($attempts) >= MAX_LOGIN_ATTEMPTS) respond(429, ['error' => ['code' => 'RATE_LIMITED', 'message' => 'تلاش‌های ورود بیش از حد است؛ کمی بعد دوباره امتحان کنید.']]);
    [$configured, $username, $password, $hash] = adminCredentials($config);
    if (!$configured) respond(503, ['error' => ['code' => 'AUTH_NOT_CONFIGURED', 'message' => 'ورود مدیریت روی سرور تنظیم نشده است.']]);
    $input = body();
    $passwordValid = $hash !== '' ? password_verify((string) ($input['password'] ?? ''), $hash) : hash_equals($password, (string) ($input['password'] ?? ''));
    if (!$passwordValid || !hash_equals($username, (string) ($input['username'] ?? ''))) {
        recordLoginFailure($attempts);
        usleep(350000);
        respond(401, ['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'نام کاربری یا رمز عبور صحیح نیست.']]);
    }
    clearLoginFailures();
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_last_seen'] = time();
    respond(200, ['authenticated' => true, 'username' => $username, 'csrfToken' => csrfToken()]);
}

if ($action === 'session' && $method === 'GET') {
    if (!authenticated()) respond(200, ['authenticated' => false]);
    respond(200, ['authenticated' => true, 'username' => (string) $_SESSION['admin_username'], 'csrfToken' => csrfToken()]);
}

if ($action === 'logout' && $method === 'POST') {
    requireAuth(); requireCsrf();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    respond(200, ['ok' => true]);
}

if ($action === 'snapshot' && $method === 'GET') {
    requireAuth();
    try {
        $cached = readCache();
        if ($cached !== null && strtotime((string) ($cached['syncedAt'] ?? '')) > time() - CACHE_TTL) respond(200, $cached);
        $snapshot = synchronize(odooConfig($config));
        writeCache($snapshot);
        respond(200, $snapshot);
    } catch (Throwable $error) {
        $cached = readCache();
        if ($cached !== null) { $cached['warning'] = 'نمایش آخرین نسخه ذخیره‌شده؛ بروزرسانی Odoo ناموفق بود.'; respond(200, $cached); }
        error_log('[sales-dashboard] ' . $error->getMessage());
        respond(502, ['error' => ['code' => 'ODOO_SYNC_FAILED', 'message' => 'همگام‌سازی Odoo ناموفق بود.']]);
    }
}

if ($action === 'sync' && $method === 'POST') {
    requireAuth(); requireCsrf();
    try {
        $snapshot = synchronize(odooConfig($config));
        writeCache($snapshot);
        respond(200, $snapshot);
    } catch (Throwable $error) {
        error_log('[sales-dashboard] ' . $error->getMessage());
        respond(502, ['error' => ['code' => 'ODOO_SYNC_FAILED', 'message' => 'همگام‌سازی Odoo ناموفق بود.']]);
    }
}

respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'مسیر API پیدا نشد.']]);
