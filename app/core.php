<?php

declare(strict_types=1);

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'] ?? [];
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function normalize_app_path(string $path): string
{
    $path = '/' . trim($path, '/');

    return $path === '//' ? '/' : $path;
}

function app_base_path(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $configured = trim((string) config('app.base_path', ''), '/');
    if ($configured !== '') {
        return $basePath = '/' . $configured;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($scriptName, '/api/v1/index.php')) {
        $scriptDir = dirname(dirname(dirname($scriptName)));
    } elseif (str_ends_with($scriptName, '/admin/index.php')) {
        $scriptDir = dirname(dirname($scriptName));
    } elseif (str_ends_with($scriptName, '/index.php')) {
        $scriptDir = dirname($scriptName);
    } else {
        $scriptDir = '';
    }

    $scriptDir = trim(str_replace('\\', '/', $scriptDir), '/');

    return $basePath = $scriptDir === '' ? '' : '/' . $scriptDir;
}

function url_for(string $path = ''): string
{
    if (preg_match('#^(?:https?:)?//#i', $path) || str_starts_with($path, '#')) {
        return $path;
    }

    $base = app_base_path();
    $path = normalize_app_path($path);

    return $path === '/' ? ($base === '' ? '/' : $base . '/') : $base . $path;
}

function asset_url(string $path): string
{
    return url_for('/' . ltrim($path, '/'));
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) config('app.url', ''), '/');
    if ($base === '') {
        return url_for($path);
    }

    $baseUrlPath = trim((string) parse_url($base, PHP_URL_PATH), '/');
    if ($baseUrlPath === '' && app_base_path() !== '') {
        $base .= app_base_path();
    }

    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url_for($path));
    exit;
}

function request_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        return is_array($payload) ? $payload : [];
    }

    return $_POST;
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = app_base_path();
    if ($base !== '' && ($uri === $base || str_starts_with($uri, $base . '/'))) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }

    $path = normalize_app_path($uri);
    if ($path === '/index.php') {
        return '/';
    }

    return $path;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals((string) ($_SESSION['_csrf'] ?? ''), (string) $token)) {
        throw new RuntimeException('Oturum güvenlik doğrulaması başarısız.');
    }
}

function friendly_error_message(Throwable $e): string
{
    $message = $e->getMessage();

    if (str_contains($message, 'SQLSTATE[42S02]') || str_contains($message, 'Base table or view not found')) {
        return 'Veritabani semasi eksik. Hosting panelinden database/schema.sql dosyasini MySQL veritabanina aktarip tekrar deneyin.';
    }

    if (str_contains($message, 'SQLSTATE[HY000] [1049]') || str_contains($message, 'Unknown database')) {
        return 'Veritabani bulunamadi. config/config.php icindeki db.name degerini hostingdeki MySQL veritabani adiyla ayni yapin.';
    }

    if (str_contains($message, 'SQLSTATE[HY000] [1045]') || str_contains($message, 'Access denied for user')) {
        return 'Veritabani kullanici adi veya sifresi hatali. config/config.php icindeki db.user ve db.pass degerlerini kontrol edin.';
    }

    if (str_contains($message, 'could not find driver')) {
        return 'pdo_mysql PHP eklentisi aktif degil. Hosting PHP 8.2 ayarlarindan PDO MySQL eklentisini acin.';
    }

    if (str_contains($message, 'SQLSTATE[HY000] [2002]')) {
        return 'Veritabanı sunucusuna ulaşılamadı. db.host değerini ve hosting MySQL servisinin açık olduğunu kontrol edin.';
    }

    return $message;
}

function setup_checks(): array
{
    $checks = [
        [
            'label' => 'PHP surumu',
            'ok' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'detail' => 'Aktif: ' . PHP_VERSION . ' / Gerekli: 8.0+',
        ],
        [
            'label' => 'PDO MySQL',
            'ok' => extension_loaded('pdo_mysql'),
            'detail' => extension_loaded('pdo_mysql') ? 'Aktif' : 'Kapali',
        ],
        [
            'label' => 'Session',
            'ok' => extension_loaded('session'),
            'detail' => extension_loaded('session') ? 'Aktif' : 'Kapali',
        ],
        [
            'label' => 'Mbstring',
            'ok' => extension_loaded('mbstring'),
            'detail' => extension_loaded('mbstring') ? 'Aktif' : 'Kapali',
        ],
    ];

    try {
        DB::pdo();
        $checks[] = ['label' => 'Veritabani baglantisi', 'ok' => true, 'detail' => 'Baglanti basarili'];

        $requiredTables = [
            'users',
            'api_tokens',
            'settings',
            'consultant_profiles',
            'services',
            'packages',
            'customer_packages',
            'consultant_availability',
            'consultant_time_off',
            'reservations',
            'mail_queue',
            'roles',
            'permissions',
            'role_permissions',
            'credit_transactions',
            'payments',
            'payment_events',
            'audit_logs',
            'clinical_notes',
            'patient_history',
            'exercise_library',
            'exercise_programs',
            'exercise_program_items',
        ];
        $rows = DB::fetchAll('SHOW TABLES');
        $tables = array_map(static fn (array $row): string => (string) reset($row), $rows);
        $missingTables = array_values(array_diff($requiredTables, $tables));
        $checks[] = [
            'label' => 'Veritabani tablolari',
            'ok' => $missingTables === [],
            'detail' => $missingTables === [] ? 'Tum tablolar mevcut' : 'Eksik: ' . implode(', ', $missingTables),
        ];

        if ($missingTables === []) {
            $roleColumn = DB::fetch("SHOW COLUMNS FROM users LIKE 'role'");
            $modernRoleSchema = $roleColumn && str_contains((string) ($roleColumn['Type'] ?? ''), 'super_admin') && str_contains((string) ($roleColumn['Type'] ?? ''), 'staff');
            $checks[] = [
                'label' => 'Beta yetki şeması',
                'ok' => (bool) $modernRoleSchema,
                'detail' => $modernRoleSchema ? 'Rol bazlı yeni şema aktif' : 'Eski users.role yapısı bulundu; temiz beta şemasını içe aktarın',
            ];
            $userCount = DB::fetch('SELECT COUNT(*) AS total FROM users WHERE status = "active"');
            $checks[] = [
                'label' => 'Aktif kullanici',
                'ok' => (int) ($userCount['total'] ?? 0) > 0,
                'detail' => (string) ((int) ($userCount['total'] ?? 0)) . ' aktif kullanici',
            ];
        }
    } catch (Throwable $e) {
        $checks[] = [
            'label' => 'Veritabani baglantisi',
            'ok' => false,
            'detail' => friendly_error_message($e),
        ];
    }

    return $checks;
}

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = config('db');
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $db['host'] ?? 'localhost',
            $db['name'] ?? '',
            $db['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(friendly_error_message($e), 0, $e);
        }

        return self::$pdo;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::execute($sql, $params);

        return (int) self::pdo()->lastInsertId();
    }
}

final class ApiResponse
{
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data = []): never
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, array $details = []): never
    {
        self::json(['ok' => false, 'message' => $message, 'details' => $details], $status);
    }
}

final class Auth
{
    public static function attempt(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        $user = DB::fetch('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1', [$email]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        DB::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Audit::record((int) $user['id'], 'auth.login', 'user', (int) $user['id']);

        return self::sanitizeUser($user);
    }

    public static function registerCustomer(array $data): array
    {
        if (!(bool) config('registration.enabled', true)) {
            throw new RuntimeException('Yeni üyelik alımı şu anda kapalı.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ad soyad ve geçerli e-posta adresi zorunludur.');
        }
        if (strlen($password) < 8 || $password !== (string) ($data['password_confirmation'] ?? '')) {
            throw new RuntimeException('Şifre en az 8 karakter olmalı ve tekrarıyla eşleşmelidir.');
        }
        if (empty($data['privacy_consent'])) {
            throw new RuntimeException('Üyelik için gizlilik ve veri işleme onayı zorunludur.');
        }
        if (DB::fetch('SELECT id FROM users WHERE email = ? LIMIT 1', [$email])) {
            throw new RuntimeException('Bu e-posta adresiyle kayıtlı bir hesap var.');
        }

        DB::pdo()->beginTransaction();
        try {
            $id = DB::insert(
                'INSERT INTO users (role, name, email, phone, password_hash, status, privacy_consent_at, created_at) VALUES ("customer", ?, ?, ?, ?, "active", NOW(), NOW())',
                [$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]
            );
            Audit::record($id, 'customer.registered', 'user', $id);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::sanitizeUser((array) DB::fetch('SELECT * FROM users WHERE id = ?', [$id]));
    }

    public static function updateOwnProfile(array $actor, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new RuntimeException('Ad soyad en az 3 karakter olmalıdır.');
        }
        DB::execute('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone, $actor['id']]);
        Audit::record((int) $actor['id'], 'user.profile_updated', 'user', (int) $actor['id']);

        return self::sanitizeUser((array) DB::fetch('SELECT * FROM users WHERE id = ?', [$actor['id']]));
    }

    public static function changePassword(array $actor, array $data): void
    {
        $user = DB::fetch('SELECT password_hash FROM users WHERE id = ?', [$actor['id']]);
        if (!$user || !password_verify((string) ($data['current_password'] ?? ''), (string) $user['password_hash'])) {
            throw new RuntimeException('Mevcut şifre doğru değil.');
        }
        $password = (string) ($data['new_password'] ?? '');
        if (strlen($password) < 8 || $password !== (string) ($data['new_password_confirmation'] ?? '')) {
            throw new RuntimeException('Yeni şifre en az 8 karakter olmalı ve tekrarıyla eşleşmelidir.');
        }
        DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $actor['id']]);
        Audit::record((int) $actor['id'], 'user.password_changed', 'user', (int) $actor['id']);
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = DB::fetch('SELECT * FROM users WHERE id = ? AND status = "active"', [(int) $_SESSION['user_id']]);

        return $user ? self::sanitizeUser($user) : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public static function requireWeb(array $roles = []): array
    {
        $user = self::currentUser();
        if (!$user) {
            redirect_to('/login');
        }

        if ($roles && !in_array($user['role'], $roles, true)) {
            http_response_code(403);
            exit('Bu alana erişim yetkiniz yok.');
        }

        return $user;
    }

    public static function issueToken(int $userId): array
    {
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain . (string) config('security.token_secret'));
        $expiresAt = (new DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s');

        DB::insert(
            'INSERT INTO api_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())',
            [$userId, $hash, $expiresAt]
        );

        return ['token' => $plain, 'expires_at' => $expiresAt];
    }

    public static function apiUser(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return null;
        }

        $hash = hash('sha256', trim($matches[1]) . (string) config('security.token_secret'));
        $token = DB::fetch(
            'SELECT t.id AS api_token_id, u.* FROM api_tokens t INNER JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.expires_at > NOW() AND u.status = "active" LIMIT 1',
            [$hash]
        );

        if (!$token) {
            return null;
        }

        DB::execute('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [$token['api_token_id']]);
        unset($token['api_token_id']);

        return self::sanitizeUser($token);
    }

    public static function revokeCurrentApiToken(): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return;
        }
        $hash = hash('sha256', trim($matches[1]) . (string) config('security.token_secret'));
        DB::execute('DELETE FROM api_tokens WHERE token_hash = ?', [$hash]);
    }

    public static function requireApi(array $roles = []): array
    {
        $user = self::apiUser();
        if (!$user) {
            ApiResponse::error('Giriş gerekli.', 401);
        }

        if ($roles && !in_array($user['role'], $roles, true)) {
            ApiResponse::error('Bu işlem için yetkiniz yok.', 403);
        }

        return $user;
    }

    public static function sanitizeUser(array $user): array
    {
        unset($user['password_hash'], $user['token_hash']);
        return $user;
    }
}

function setting(string $key, mixed $default = null): mixed
{
    $cache = $GLOBALS['_fizyorez_setting_cache'] ?? [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $row = DB::fetch('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    $GLOBALS['_fizyorez_setting_cache'][$key] = $row ? $row['setting_value'] : $default;

    return $GLOBALS['_fizyorez_setting_cache'][$key];
}

function save_setting(string $key, string $value): void
{
    DB::execute(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value]
    );
    unset($GLOBALS['_fizyorez_setting_cache'][$key]);
}

function user_label(?array $user): string
{
    if (!$user) {
        return '-';
    }

    return (string) ($user['name'] ?? $user['email'] ?? '-');
}
