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
const USERS_FILE = 'sales-dashboard-users.json';
const SETTINGS_FILE = 'sales-dashboard-settings.json';
const DASHBOARD_SETTING_KEYS = [
    'targets', 'targetsByMonth', 'targetsByCustomRange',
    'quantityTargetsByMonth', 'quantityTargetsByCustomRange',
    'percentTargetsByMonth', 'percentTargetsByCustomRange',
    'conversionTargetsByMonth', 'sellerConversionTargetsByMonth',
    'sellerConversionTargetsByCustomRange', 'dashboardRoles', 'customPeople',
];

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function passwordLength(string $password): int {
    $length = preg_match_all('/./us', $password, $matches);
    return $length === false ? 0 : $length;
}

function hashSellerPassword(string $password): array {
    return [password_hash(hash('sha256', $password), PASSWORD_DEFAULT), 'sha256-v1'];
}

function verifySellerPassword(string $password, array $account): bool {
    $hash = (string) ($account['passwordHash'] ?? '');
    if ($hash === '') return false;
    $scheme = (string) ($account['passwordHashScheme'] ?? '');
    if ($scheme === 'sha256-v1') return password_verify(hash('sha256', $password), $hash);
    if ($scheme === '') return password_verify($password, $hash);
    return false;
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

function privateJson(string $name): array {
    if (!in_array($name, [USERS_FILE, SETTINGS_FILE], true)) return [];
    $file = PRIVATE_DIR . '/' . $name;
    if (!is_file($file)) return [];
    $value = json_decode((string) file_get_contents($file), true);
    return is_array($value) ? $value : [];
}

function savePrivateJson(string $name, array $value): void {
    if (!in_array($name, [USERS_FILE, SETTINGS_FILE], true)) throw new RuntimeException('ذخیره‌سازی امن انجام نشد.');
    if (!is_dir(PRIVATE_DIR) && !@mkdir(PRIVATE_DIR, 0700, true) && !is_dir(PRIVATE_DIR)) throw new RuntimeException('ذخیره‌سازی امن انجام نشد.');
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > 2_000_000) throw new RuntimeException('حجم تنظیمات از حد مجاز بیشتر است.');
    $path = PRIVATE_DIR . '/' . $name;
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false) throw new RuntimeException('ذخیره‌سازی امن انجام نشد.');
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('ذخیره‌سازی امن انجام نشد.');
    }
    @chmod($path, 0600);
}

function sessionRole(): string {
    return (string) ($_SESSION['dashboard_role'] ?? 'manager');
}

function sellerOdooId(string $salespersonId): string {
    return preg_match('/^odoo-([1-9][0-9]*)$/', $salespersonId, $matches) ? $matches[1] : '';
}

function sellerAccount(string $salespersonId): ?array {
    $accounts = privateJson(USERS_FILE)['accounts'] ?? [];
    $account = is_array($accounts) ? ($accounts[$salespersonId] ?? null) : null;
    return is_array($account) ? $account : null;
}

function resetSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function authenticated(): bool {
    if (empty($_SESSION['admin_authenticated']) || empty($_SESSION['admin_username'])) return false;
    $last = (int) ($_SESSION['admin_last_seen'] ?? 0);
    if ($last < time() - SESSION_TIMEOUT) {
        resetSession();
        return false;
    }
    $role = sessionRole();
    if (!in_array($role, ['manager', 'seller'], true)) { resetSession(); return false; }
    if ($role === 'seller') {
        $salespersonId = (string) ($_SESSION['salesperson_id'] ?? '');
        $account = sellerAccount($salespersonId);
        $settings = privateJson(SETTINGS_FILE);
        $configuredRole = (string) ($settings['dashboardRoles'][$salespersonId] ?? 'seller');
        $sessionPasswordVersion = (string) ($_SESSION['seller_password_version'] ?? '');
        $accountPasswordVersion = (string) ($account['passwordVersion'] ?? '');
        if (!$account || empty($account['active']) || strcasecmp((string) ($account['username'] ?? ''), (string) $_SESSION['admin_username']) !== 0 || $configuredRole !== 'seller' || !hash_equals($accountPasswordVersion, $sessionPasswordVersion)) {
            resetSession();
            return false;
        }
        $_SESSION['must_change_password'] = !empty($account['mustChangePassword']);
    }
    $_SESSION['admin_last_seen'] = time();
    return true;
}

function requireAuth(): void {
    if (!authenticated()) respond(401, ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'نشست شما معتبر نیست؛ دوباره وارد شوید.']]);
}

function requireDashboardAccess(): void {
    requireAuth();
    if (sessionRole() === 'seller' && !empty($_SESSION['must_change_password'])) respond(403, ['error' => ['code' => 'PASSWORD_CHANGE_REQUIRED', 'message' => 'برای ادامه، ابتدا رمز عبور اولیه را تغییر دهید.']]);
}

function requireManager(): void {
    requireAuth();
    if (sessionRole() !== 'manager') respond(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'فقط مدیر فروش به این بخش دسترسی دارد.']]);
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

function chunkFetchByField(array $config, string $model, string $field, array $ids, array $fields): array {
    $result = [];
    foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
        if (!$chunk) continue;
        array_push($result, ...searchReadAll($config, $model, ['domain' => [[$field, 'in', $chunk]], 'fields' => $fields]));
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
    $orderLines = chunkFetchByField($config, 'sale.order.line', 'order_id', array_map(fn($item) => (int) $item['id'], $orders), ['id','order_id','product_id','product_uom_qty','price_unit','discount','price_subtotal','price_total']);
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

function referenceId(mixed $value): string {
    if (is_array($value)) return (string) ($value[0] ?? $value['id'] ?? '');
    return is_scalar($value) ? (string) $value : '';
}

function sellerDashboardSettings(array $settings, string $salespersonId): array {
    $scoped = [];
    foreach (['targets', 'dashboardRoles'] as $key) {
        if (isset($settings[$key]) && is_array($settings[$key]) && array_key_exists($salespersonId, $settings[$key])) $scoped[$key] = [$salespersonId => $settings[$key][$salespersonId]];
    }
    foreach ([
        'targetsByMonth', 'targetsByCustomRange', 'quantityTargetsByMonth', 'quantityTargetsByCustomRange',
        'percentTargetsByMonth', 'percentTargetsByCustomRange', 'sellerConversionTargetsByMonth', 'sellerConversionTargetsByCustomRange',
    ] as $key) {
        foreach (($settings[$key] ?? []) as $period => $values) {
            if (is_array($values) && array_key_exists($salespersonId, $values)) $scoped[$key][$period] = [$salespersonId => $values[$salespersonId]];
        }
    }
    foreach (($settings['customPeople'] ?? []) as $person) {
        if (is_array($person) && ($person['id'] ?? '') === $salespersonId) $scoped['customPeople'][] = $person;
    }
    return $scoped;
}

function cleanDashboardSettings(array $input): array {
    $clean = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, DASHBOARD_SETTING_KEYS, true)) continue;
        if ($key === 'customPeople') {
            if (!is_array($value) || count($value) > 500) respond(400, ['error' => ['code' => 'INVALID_SETTINGS', 'message' => 'فهرست کاربران معتبر نیست.']]);
            $people = [];
            foreach ($value as $person) {
                if (!is_array($person)) continue;
                $id = (string) ($person['id'] ?? '');
                $name = trim((string) ($person['name'] ?? ''));
                if (!preg_match('/^seller-[a-z0-9]{1,24}$/', $id) || !preg_match('/^.{1,100}$/us', $name)) continue;
                $color = (string) ($person['color'] ?? '#7055ec');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#7055ec';
                $role = (string) ($person['role'] ?? 'کارشناس فروش');
                if (!preg_match('/^.{0,64}$/us', $role)) $role = 'کارشناس فروش';
                $avatar = (string) ($person['avatar'] ?? '');
                if (!preg_match('/^.{0,8}$/us', $avatar)) $avatar = '';
                $people[] = [
                    'id' => $id, 'name' => $name, 'role' => $role, 'avatar' => $avatar, 'color' => $color,
                    'active' => ($person['active'] ?? true) !== false, 'custom' => true,
                    'dashboardRole' => in_array($person['dashboardRole'] ?? 'seller', ['seller', 'manager', 'other'], true) ? ($person['dashboardRole'] ?? 'seller') : 'seller',
                ];
            }
            $clean[$key] = $people;
            continue;
        }
        if (!is_array($value) || count($value) > 10000) respond(400, ['error' => ['code' => 'INVALID_SETTINGS', 'message' => 'ساختار تنظیمات معتبر نیست.']]);
        if ($key === 'dashboardRoles') {
            $roles = [];
            foreach ($value as $id => $role) {
                if ((preg_match('/^odoo-[1-9][0-9]*$/', (string) $id) || preg_match('/^seller-[a-z0-9]{1,24}$/', (string) $id)) && in_array($role, ['seller', 'manager', 'other'], true)) $roles[$id] = $role;
            }
            $clean[$key] = $roles;
        } else $clean[$key] = $value;
    }
    return $clean;
}

function filterSnapshotForSeller(array $snapshot, string $salespersonId): array {
    $userId = sellerOdooId($salespersonId);
    $raw = $snapshot['raw'] ?? [];
    if (!is_array($raw)) $raw = [];
    $ownsUser = static fn($record, $field): bool => is_array($record) && $userId !== '' && referenceId($record[$field] ?? null) === $userId;
    $leads = $userId === '' ? [] : array_values(array_filter($raw['leads'] ?? [], fn($item) => $ownsUser($item, 'user_id')));
    $orders = $userId === '' ? [] : array_values(array_filter($raw['orders'] ?? [], fn($item) => $ownsUser($item, 'user_id')));
    $invoices = $userId === '' ? [] : array_values(array_filter($raw['invoices'] ?? [], fn($item) => $ownsUser($item, 'invoice_user_id') || $ownsUser($item, 'user_id')));
    $leadIds = array_fill_keys(array_map(fn($item) => (string) ($item['id'] ?? ''), $leads), true);
    $orderIds = array_fill_keys(array_map(fn($item) => (string) ($item['id'] ?? ''), $orders), true);
    $invoiceIds = array_fill_keys(array_map(fn($item) => (string) ($item['id'] ?? ''), $invoices), true);
    $activities = array_values(array_filter($raw['activities'] ?? [], fn($item) => $ownsUser($item, 'user_id') && isset($leadIds[(string) ($item['res_id'] ?? '')])));
    $messages = array_values(array_filter($raw['crmMessages'] ?? [], fn($item) => isset($leadIds[(string) ($item['res_id'] ?? '')])));
    $orderLines = array_values(array_filter($raw['orderLines'] ?? [], fn($item) => isset($orderIds[referenceId($item['order_id'] ?? null)])));
    $invoiceLines = array_values(array_filter($raw['invoiceLines'] ?? [], fn($item) => isset($invoiceIds[referenceId($item['move_id'] ?? null)])));
    $partnerIds = [];
    foreach (array_merge($leads, $orders, $invoices) as $record) {
        $id = referenceId($record['partner_id'] ?? null);
        if ($id !== '') $partnerIds[$id] = true;
    }
    $contacts = array_values(array_filter($raw['contacts'] ?? [], fn($item) => isset($partnerIds[(string) ($item['id'] ?? '')]) || $ownsUser($item, 'user_id')));
    $productIds = [];
    foreach (array_merge($orderLines, $invoiceLines) as $line) {
        $id = referenceId($line['product_id'] ?? null);
        if ($id !== '') $productIds[$id] = true;
    }
    $products = array_values(array_filter($raw['productCatalog'] ?? [], fn($item) => isset($productIds[(string) ($item['id'] ?? '')])));
    $raw['users'] = $userId === '' ? [] : array_values(array_filter($raw['users'] ?? [], fn($item) => (string) ($item['id'] ?? '') === $userId));
    $raw['leads'] = $leads;
    $raw['orders'] = $orders;
    $raw['invoices'] = $invoices;
    $raw['activities'] = $activities;
    $raw['crmMessages'] = $messages;
    $raw['contacts'] = $contacts;
    $raw['orderLines'] = $orderLines;
    $raw['invoiceLines'] = $invoiceLines;
    $raw['productCatalog'] = $products;
    $snapshot['raw'] = $raw;
    $snapshot['dashboardSettings'] = sellerDashboardSettings(privateJson(SETTINGS_FILE), $salespersonId);
    return $snapshot;
}

function snapshotForSession(array $snapshot): array {
    $snapshot['dashboardSettings'] = privateJson(SETTINGS_FILE);
    if (sessionRole() === 'seller') return filterSnapshotForSeller($snapshot, (string) ($_SESSION['salesperson_id'] ?? ''));
    return $snapshot;
}

$action = (string) ($_GET['action'] ?? '');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$config = privateConfig();

if ($action === 'login' && $method === 'POST') {
    $attempts = loginAttempts();
    if (count($attempts) >= MAX_LOGIN_ATTEMPTS) respond(429, ['error' => ['code' => 'RATE_LIMITED', 'message' => 'تلاش‌های ورود بیش از حد است؛ کمی بعد دوباره امتحان کنید.']]);
    [$configured, $adminUsername, $adminPassword, $adminHash] = adminCredentials($config);
    $input = body();
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $managerValid = $configured && hash_equals($adminUsername, $username)
        && ($adminHash !== '' ? password_verify($password, $adminHash) : hash_equals($adminPassword, $password));
    $seller = null;
    if (!$managerValid && $username !== '') {
        $accounts = privateJson(USERS_FILE)['accounts'] ?? [];
        foreach (is_array($accounts) ? $accounts : [] as $salespersonId => $account) {
            if (!is_array($account) || empty($account['active']) || strcasecmp((string) ($account['username'] ?? ''), $username) !== 0) continue;
            $role = (string) (privateJson(SETTINGS_FILE)['dashboardRoles'][$salespersonId] ?? 'seller');
            if (verifySellerPassword($password, $account) && $role === 'seller') {
                $seller = [
                    'salespersonId' => (string) $salespersonId,
                    'username' => (string) $account['username'],
                    'mustChangePassword' => !empty($account['mustChangePassword']),
                    'passwordVersion' => (string) ($account['passwordVersion'] ?? ''),
                ];
                break;
            }
        }
    }
    if (!$managerValid && !$seller) {
        $accounts = privateJson(USERS_FILE)['accounts'] ?? [];
        if (!$configured && !$accounts) respond(503, ['error' => ['code' => 'AUTH_NOT_CONFIGURED', 'message' => 'ورود مدیریت روی سرور تنظیم نشده است.']]);
        recordLoginFailure($attempts);
        usleep(350000);
        respond(401, ['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'نام کاربری یا رمز عبور صحیح نیست.']]);
    }
    clearLoginFailures();
    session_regenerate_id(true);
    $_SESSION['admin_authenticated'] = true;
    $_SESSION['admin_username'] = $managerValid ? $adminUsername : $seller['username'];
    $_SESSION['dashboard_role'] = $managerValid ? 'manager' : 'seller';
    $_SESSION['must_change_password'] = $seller ? $seller['mustChangePassword'] : false;
    if ($seller) {
        $_SESSION['salesperson_id'] = $seller['salespersonId'];
        $_SESSION['seller_password_version'] = $seller['passwordVersion'];
    }
    $_SESSION['admin_last_seen'] = time();
    respond(200, [
        'authenticated' => true,
        'username' => (string) $_SESSION['admin_username'],
        'role' => (string) $_SESSION['dashboard_role'],
        'salespersonId' => $seller['salespersonId'] ?? null,
        'mustChangePassword' => (bool) ($_SESSION['must_change_password'] ?? false),
        'csrfToken' => csrfToken(),
    ]);
}

if ($action === 'session' && $method === 'GET') {
    if (!authenticated()) respond(200, ['authenticated' => false]);
    respond(200, [
        'authenticated' => true,
        'username' => (string) $_SESSION['admin_username'],
        'role' => sessionRole(),
        'salespersonId' => sessionRole() === 'seller' ? (string) $_SESSION['salesperson_id'] : null,
        'mustChangePassword' => sessionRole() === 'seller' && !empty($_SESSION['must_change_password']),
        'csrfToken' => csrfToken(),
    ]);
}

if ($action === 'logout' && $method === 'POST') {
    requireAuth(); requireCsrf();
    resetSession();
    respond(200, ['ok' => true]);
}

if ($action === 'account-list' && $method === 'GET') {
    requireManager();
    $accounts = privateJson(USERS_FILE)['accounts'] ?? [];
    $public = [];
    foreach (is_array($accounts) ? $accounts : [] as $salespersonId => $account) {
        if (!is_array($account)) continue;
        $public[] = ['salespersonId' => (string) $salespersonId, 'username' => (string) ($account['username'] ?? ''), 'active' => !empty($account['active']), 'mustChangePassword' => !empty($account['mustChangePassword'])];
    }
    respond(200, ['accounts' => $public]);
}

if ($action === 'account-save' && $method === 'POST') {
    requireManager(); requireCsrf();
    $input = body();
    $salespersonId = trim((string) ($input['salespersonId'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if (!preg_match('/^(?:odoo-[1-9][0-9]*|seller-[a-z0-9]{1,24})$/', $salespersonId)
        || !preg_match('/^[A-Za-z0-9._@+-]{3,64}$/', $username)
        || strlen($password) > 1024) respond(400, ['error' => ['code' => 'INVALID_ACCOUNT', 'message' => 'نام کاربری یا شناسه فروشنده معتبر نیست.']]);
    $settings = privateJson(SETTINGS_FILE);
    if (($settings['dashboardRoles'][$salespersonId] ?? 'seller') !== 'seller') respond(409, ['error' => ['code' => 'NOT_A_SELLER', 'message' => 'برای این کاربر نقش فروشنده فعال نیست.']]);
    [$configured, $adminUsername] = adminCredentials($config);
    if ($configured && strcasecmp($username, $adminUsername) === 0) respond(409, ['error' => ['code' => 'USERNAME_EXISTS', 'message' => 'این نام کاربری قبلاً ثبت شده است.']]);
    $store = privateJson(USERS_FILE);
    $accounts = is_array($store['accounts'] ?? null) ? $store['accounts'] : [];
    $current = is_array($accounts[$salespersonId] ?? null) ? $accounts[$salespersonId] : null;
    foreach ($accounts as $existingId => $account) {
        if ($existingId !== $salespersonId && is_array($account) && strcasecmp((string) ($account['username'] ?? ''), $username) === 0) respond(409, ['error' => ['code' => 'USERNAME_EXISTS', 'message' => 'این نام کاربری قبلاً ثبت شده است.']]);
    }
    if ((!$current || $password !== '') && (passwordLength($password) < 8 || strlen($password) > 1024)) respond(400, ['error' => ['code' => 'WEAK_PASSWORD', 'message' => 'رمز عبور باید دست‌کم ۸ نویسه داشته باشد.']]);
    $passwordHash = (string) ($current['passwordHash'] ?? '');
    $passwordHashScheme = (string) ($current['passwordHashScheme'] ?? '');
    $passwordVersion = (string) ($current['passwordVersion'] ?? '');
    if ($password !== '') {
        [$passwordHash, $passwordHashScheme] = hashSellerPassword($password);
        $passwordVersion = bin2hex(random_bytes(16));
    }
    if ($passwordHash === '') respond(400, ['error' => ['code' => 'WEAK_PASSWORD', 'message' => 'رمز عبور اولیه لازم است.']]);
    $mustChangePassword = $password !== '' ? true : !empty($current['mustChangePassword']);
    $accounts[$salespersonId] = ['username' => $username, 'passwordHash' => $passwordHash, 'passwordHashScheme' => $passwordHashScheme, 'passwordVersion' => $passwordVersion, 'mustChangePassword' => $mustChangePassword, 'active' => $current['active'] ?? true];
    $store['accounts'] = $accounts;
    try { savePrivateJson(USERS_FILE, $store); }
    catch (Throwable $error) { error_log('[sales-dashboard] private account save failed'); respond(500, ['error' => ['code' => 'SAVE_FAILED', 'message' => 'ذخیره دسترسی ناموفق بود.']]); }
    respond(200, ['account' => ['salespersonId' => $salespersonId, 'username' => $username, 'active' => !empty($accounts[$salespersonId]['active']), 'mustChangePassword' => $mustChangePassword]]);
}

if ($action === 'account-status' && $method === 'POST') {
    requireManager(); requireCsrf();
    $input = body();
    $salespersonId = trim((string) ($input['salespersonId'] ?? ''));
    $active = filter_var($input['active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $store = privateJson(USERS_FILE);
    $accounts = is_array($store['accounts'] ?? null) ? $store['accounts'] : [];
    if (!isset($accounts[$salespersonId]) || !is_array($accounts[$salespersonId]) || $active === null) respond(404, ['error' => ['code' => 'ACCOUNT_NOT_FOUND', 'message' => 'حساب فروشنده پیدا نشد.']]);
    if ($active && (privateJson(SETTINGS_FILE)['dashboardRoles'][$salespersonId] ?? 'seller') !== 'seller') respond(409, ['error' => ['code' => 'NOT_A_SELLER', 'message' => 'برای این کاربر نقش فروشنده فعال نیست.']]);
    $accounts[$salespersonId]['active'] = $active;
    $store['accounts'] = $accounts;
    try { savePrivateJson(USERS_FILE, $store); }
    catch (Throwable $error) { error_log('[sales-dashboard] private account save failed'); respond(500, ['error' => ['code' => 'SAVE_FAILED', 'message' => 'تغییر دسترسی ناموفق بود.']]); }
    respond(200, ['account' => ['salespersonId' => $salespersonId, 'username' => (string) $accounts[$salespersonId]['username'], 'active' => $active, 'mustChangePassword' => !empty($accounts[$salespersonId]['mustChangePassword'])]]);
}

if ($action === 'password-change' && $method === 'POST') {
    requireAuth(); requireCsrf();
    if (sessionRole() !== 'seller') respond(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'این عملیات فقط برای حساب فروشنده در دسترس است.']]);
    $input = body();
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    $newPassword = (string) ($input['newPassword'] ?? '');
    if (passwordLength($newPassword) < 8 || strlen($newPassword) > 1024) respond(400, ['error' => ['code' => 'WEAK_PASSWORD', 'message' => 'رمز جدید باید دست‌کم ۸ نویسه داشته باشد.']]);
    if ($newPassword === $currentPassword) respond(400, ['error' => ['code' => 'PASSWORD_UNCHANGED', 'message' => 'رمز جدید باید با رمز فعلی متفاوت باشد.']]);
    $salespersonId = (string) $_SESSION['salesperson_id'];
    $store = privateJson(USERS_FILE);
    $accounts = is_array($store['accounts'] ?? null) ? $store['accounts'] : [];
    $account = is_array($accounts[$salespersonId] ?? null) ? $accounts[$salespersonId] : null;
    if (!$account || !verifySellerPassword($currentPassword, $account)) respond(401, ['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'رمز فعلی صحیح نیست.']]);
    $passwordVersion = bin2hex(random_bytes(16));
    [$accounts[$salespersonId]['passwordHash'], $accounts[$salespersonId]['passwordHashScheme']] = hashSellerPassword($newPassword);
    $accounts[$salespersonId]['passwordVersion'] = $passwordVersion;
    $accounts[$salespersonId]['mustChangePassword'] = false;
    $store['accounts'] = $accounts;
    try { savePrivateJson(USERS_FILE, $store); }
    catch (Throwable $error) { error_log('[sales-dashboard] private password change failed'); respond(500, ['error' => ['code' => 'SAVE_FAILED', 'message' => 'تغییر رمز ناموفق بود.']]); }
    $_SESSION['seller_password_version'] = $passwordVersion;
    $_SESSION['must_change_password'] = false;
    session_regenerate_id(true);
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    respond(200, ['ok' => true, 'mustChangePassword' => false, 'csrfToken' => csrfToken()]);
}

if ($action === 'dashboard-settings' && $method === 'GET') {
    requireDashboardAccess();
    $settings = privateJson(SETTINGS_FILE);
    if (sessionRole() === 'seller') $settings = sellerDashboardSettings($settings, (string) $_SESSION['salesperson_id']);
    respond(200, ['settings' => $settings]);
}

if ($action === 'dashboard-settings' && $method === 'POST') {
    requireManager(); requireCsrf();
    $input = body();
    if (!is_array($input['settings'] ?? null)) respond(400, ['error' => ['code' => 'INVALID_SETTINGS', 'message' => 'تنظیمات داشبورد معتبر نیست.']]);
    $settings = cleanDashboardSettings($input['settings']);
    try { savePrivateJson(SETTINGS_FILE, $settings); }
    catch (Throwable $error) { error_log('[sales-dashboard] private settings save failed'); respond(500, ['error' => ['code' => 'SAVE_FAILED', 'message' => 'ذخیره تنظیمات ناموفق بود.']]); }
    respond(200, ['ok' => true]);
}

if ($action === 'snapshot' && $method === 'GET') {
    requireDashboardAccess();
    try {
        $cached = readCache();
        if ($cached !== null && strtotime((string) ($cached['syncedAt'] ?? '')) > time() - CACHE_TTL) respond(200, snapshotForSession($cached));
        $snapshot = synchronize(odooConfig($config));
        writeCache($snapshot);
        respond(200, snapshotForSession($snapshot));
    } catch (Throwable $error) {
        $cached = readCache();
        if ($cached !== null) { $cached['warning'] = 'نمایش آخرین نسخه ذخیره‌شده؛ بروزرسانی Odoo ناموفق بود.'; respond(200, snapshotForSession($cached)); }
        error_log('[sales-dashboard] ' . $error->getMessage());
        respond(502, ['error' => ['code' => 'ODOO_SYNC_FAILED', 'message' => 'همگام‌سازی Odoo ناموفق بود.']]);
    }
}

if ($action === 'sync' && $method === 'POST') {
    requireDashboardAccess(); requireCsrf();
    try {
        $snapshot = synchronize(odooConfig($config));
        writeCache($snapshot);
        respond(200, snapshotForSession($snapshot));
    } catch (Throwable $error) {
        error_log('[sales-dashboard] ' . $error->getMessage());
        respond(502, ['error' => ['code' => 'ODOO_SYNC_FAILED', 'message' => 'همگام‌سازی Odoo ناموفق بود.']]);
    }
}

respond(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'مسیر API پیدا نشد.']]);
