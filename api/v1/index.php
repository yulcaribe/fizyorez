<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_GET['path'] ?? '';
if ($path === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $marker = '/api/v1';
    $position = strpos($uri, $marker);
    $path = $position === false ? $uri : substr($uri, $position + strlen($marker));
}

$segments = array_values(array_filter(explode('/', trim((string) $path, '/'))));
$body = request_input();

try {
    if ($segments === [] || $segments[0] === 'health') {
        ApiResponse::ok(['status' => 'ready', 'app' => config('app.name')]);
    }

    if ($segments[0] === 'auth' && ($segments[1] ?? '') === 'login' && $method === 'POST') {
        $user = Auth::attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        if (!$user) {
            ApiResponse::error('E-posta veya şifre hatalı.', 422);
        }

        $token = Auth::issueToken((int) $user['id']);
        ApiResponse::ok(['user' => $user, 'access_token' => $token['token'], 'expires_at' => $token['expires_at']]);
    }

    if ($segments[0] === 'auth' && ($segments[1] ?? '') === 'logout' && $method === 'POST') {
        Auth::logout();
        ApiResponse::ok();
    }

    if ($segments[0] === 'me' && $method === 'GET') {
        ApiResponse::ok(['user' => Auth::requireApi()]);
    }

    if ($segments[0] === 'bootstrap' && $method === 'GET') {
        $user = Auth::requireApi();
        ApiResponse::ok([
            'user' => $user,
            'services' => DB::fetchAll('SELECT * FROM services WHERE active = 1 ORDER BY name'),
            'consultants' => DB::fetchAll('SELECT u.id, u.name, u.email, u.phone, cp.title, cp.color, cp.booking_deadline_hours FROM users u LEFT JOIN consultant_profiles cp ON cp.user_id = u.id WHERE u.role = "consultant" AND u.status = "active" ORDER BY u.name'),
            'packages' => DB::fetchAll('SELECT * FROM packages WHERE active = 1 ORDER BY name'),
            'settings' => publicSettings(),
        ]);
    }

    if ($segments[0] === 'users') {
        $user = Auth::requireApi(['admin']);
        if ($method === 'GET') {
            $role = $_GET['role'] ?? null;
            $where = $role ? 'WHERE role = ?' : '';
            $params = $role ? [$role] : [];
            ApiResponse::ok(['users' => DB::fetchAll('SELECT id, role, name, email, phone, status, created_at FROM users ' . $where . ' ORDER BY name', $params)]);
        }

        if ($method === 'POST') {
            $newUser = createUser($body);
            ApiResponse::ok(['user' => $newUser]);
        }

        if (isset($segments[1]) && in_array($method, ['PUT', 'PATCH'], true)) {
            $updated = updateUser((int) $segments[1], $body);
            ApiResponse::ok(['user' => $updated]);
        }
    }

    if ($segments[0] === 'services') {
        Auth::requireApi();
        if ($method === 'GET') {
            ApiResponse::ok(['services' => DB::fetchAll('SELECT * FROM services ORDER BY active DESC, name')]);
        }

        Auth::requireApi(['admin']);
        if ($method === 'POST') {
            $service = saveService(null, $body);
            ApiResponse::ok(['service' => $service]);
        }
        if (isset($segments[1]) && in_array($method, ['PUT', 'PATCH'], true)) {
            $service = saveService((int) $segments[1], $body);
            ApiResponse::ok(['service' => $service]);
        }
    }

    if ($segments[0] === 'packages') {
        Auth::requireApi();
        if ($method === 'GET') {
            ApiResponse::ok(['packages' => DB::fetchAll('SELECT * FROM packages ORDER BY active DESC, name')]);
        }

        Auth::requireApi(['admin']);
        if ($method === 'POST') {
            $package = savePackage(null, $body);
            ApiResponse::ok(['package' => $package]);
        }
        if (isset($segments[1]) && in_array($method, ['PUT', 'PATCH'], true)) {
            $package = savePackage((int) $segments[1], $body);
            ApiResponse::ok(['package' => $package]);
        }
    }

    if ($segments[0] === 'customer-packages') {
        $user = Auth::requireApi();
        if ($method === 'GET') {
            $customerId = $user['role'] === 'customer' ? (int) $user['id'] : (int) ($_GET['customer_id'] ?? 0);
            if ($customerId < 1 && $user['role'] === 'admin') {
                ApiResponse::ok(['customer_packages' => DB::fetchAll(customerPackageSql() . ' ORDER BY cp.expires_at DESC')]);
            }
            ApiResponse::ok(['customer_packages' => DB::fetchAll(customerPackageSql() . ' WHERE cp.customer_id = ? ORDER BY cp.expires_at DESC', [$customerId])]);
        }

        Auth::requireApi(['admin']);
        if ($method === 'POST') {
            $assigned = assignPackage($body);
            ApiResponse::ok(['customer_package' => $assigned]);
        }
    }

    if ($segments[0] === 'reservations') {
        $user = Auth::requireApi();
        if ($method === 'GET') {
            ApiResponse::ok(['reservations' => ReservationService::list($user, $_GET)]);
        }

        if ($method === 'POST' && !isset($segments[1])) {
            ApiResponse::ok(['reservation' => ReservationService::create($user, $body)]);
        }

        if (isset($segments[1], $segments[2]) && $segments[2] === 'cancel' && $method === 'POST') {
            ApiResponse::ok(['reservation' => ReservationService::cancel($user, (int) $segments[1])]);
        }

        if (isset($segments[1], $segments[2]) && $segments[2] === 'status' && $method === 'POST') {
            ApiResponse::ok(['reservation' => ReservationService::setStatus($user, (int) $segments[1], (string) ($body['status'] ?? ''))]);
        }

        if (isset($segments[1], $segments[2]) && $segments[2] === 'reschedule' && in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
            ApiResponse::ok(['reservation' => ReservationService::reschedule($user, (int) $segments[1], (string) ($body['starts_at'] ?? ''))]);
        }

        if (isset($segments[1], $segments[2]) && $segments[2] === 'payment' && in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
            Auth::requireApi(['admin']);
            $status = (string) ($body['payment_status'] ?? '');
            if (!in_array($status, ['paid', 'pending', 'cancelled'], true)) {
                ApiResponse::error('Ödeme durumu geçersiz.', 422);
            }
            DB::execute('UPDATE reservations SET payment_status = ?, updated_at = NOW() WHERE id = ?', [$status, (int) $segments[1]]);
            ApiResponse::ok(['reservation' => ReservationService::find((int) $segments[1])]);
        }
    }

    if ($segments[0] === 'availability') {
        $user = Auth::requireApi();
        if ($method === 'GET') {
            $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($_GET['consultant_id'] ?? 0);
            $params = [];
            $where = '';
            if ($consultantId > 0) {
                $where = 'WHERE a.consultant_id = ?';
                $params[] = $consultantId;
            }
            ApiResponse::ok(['availability' => DB::fetchAll('SELECT a.*, u.name AS consultant_name FROM consultant_availability a INNER JOIN users u ON u.id = a.consultant_id ' . $where . ' ORDER BY a.weekday, a.start_time', $params)]);
        }

        if ($method === 'POST') {
            $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($body['consultant_id'] ?? 0);
            if ($user['role'] !== 'admin' && $consultantId !== (int) $user['id']) {
                ApiResponse::error('Bu işlem için yetkiniz yok.', 403);
            }
            $id = DB::insert(
                'INSERT INTO consultant_availability (consultant_id, weekday, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)',
                [$consultantId, (int) $body['weekday'], (string) $body['start_time'], (string) $body['end_time']]
            );
            ApiResponse::ok(['availability' => DB::fetch('SELECT * FROM consultant_availability WHERE id = ?', [$id])]);
        }

        if (isset($segments[1]) && $method === 'DELETE') {
            $availability = DB::fetch('SELECT * FROM consultant_availability WHERE id = ?', [(int) $segments[1]]);
            if (!$availability) {
                ApiResponse::error('Müsaitlik bulunamadı.', 404);
            }
            if ($user['role'] !== 'admin' && (int) $availability['consultant_id'] !== (int) $user['id']) {
                ApiResponse::error('Bu işlem için yetkiniz yok.', 403);
            }
            DB::execute('DELETE FROM consultant_availability WHERE id = ?', [(int) $segments[1]]);
            ApiResponse::ok();
        }
    }

    if ($segments[0] === 'time-off') {
        $user = Auth::requireApi(['admin', 'consultant']);
        if ($method === 'GET') {
            $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($_GET['consultant_id'] ?? 0);
            $where = $consultantId > 0 ? 'WHERE t.consultant_id = ?' : '';
            $params = $consultantId > 0 ? [$consultantId] : [];
            ApiResponse::ok(['time_off' => DB::fetchAll('SELECT t.*, u.name AS consultant_name FROM consultant_time_off t INNER JOIN users u ON u.id = t.consultant_id ' . $where . ' ORDER BY t.start_at DESC', $params)]);
        }
        if ($method === 'POST') {
            $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($body['consultant_id'] ?? 0);
            $id = DB::insert(
                'INSERT INTO consultant_time_off (consultant_id, start_at, end_at, reason) VALUES (?, ?, ?, ?)',
                [$consultantId, normalizeApiDate((string) $body['start_at']), normalizeApiDate((string) $body['end_at']), (string) ($body['reason'] ?? '')]
            );
            ApiResponse::ok(['time_off' => DB::fetch('SELECT * FROM consultant_time_off WHERE id = ?', [$id])]);
        }
    }

    if ($segments[0] === 'settings') {
        Auth::requireApi(['admin']);
        if ($method === 'GET') {
            ApiResponse::ok(['settings' => publicSettings()]);
        }
        if (in_array($method, ['PUT', 'PATCH', 'POST'], true)) {
            foreach (['booking_change_deadline_hours', 'late_cancel_burn_credit', 'credit_deduction_policy', 'reservation_reminder_hours'] as $key) {
                if (array_key_exists($key, $body)) {
                    save_setting($key, (string) $body[$key]);
                }
            }
            ApiResponse::ok(['settings' => publicSettings()]);
        }
    }

    if ($segments[0] === 'reports' && ($segments[1] ?? '') === 'summary') {
        $user = Auth::requireApi(['admin', 'consultant']);
        ApiResponse::ok(['summary' => reportSummary($user)]);
    }

    if ($segments[0] === 'mail' && ($segments[1] ?? '') === 'send' && $method === 'POST') {
        $token = (string) ($_GET['token'] ?? $body['token'] ?? '');
        if (!hash_equals((string) config('security.cron_token'), $token)) {
            ApiResponse::error('Cron token hatalı.', 403);
        }
        ApiResponse::ok(['mail' => Mailer::sendPending(25)]);
    }

    ApiResponse::error('Endpoint bulunamadı.', 404);
} catch (Throwable $e) {
    ApiResponse::error(friendly_error_message($e), 422);
}

function publicSettings(): array
{
    return [
        'booking_change_deadline_hours' => setting('booking_change_deadline_hours', '12'),
        'late_cancel_burn_credit' => setting('late_cancel_burn_credit', '1'),
        'credit_deduction_policy' => setting('credit_deduction_policy', 'on_booking'),
        'reservation_reminder_hours' => setting('reservation_reminder_hours', '24'),
    ];
}

function createUser(array $data): array
{
    $role = (string) ($data['role'] ?? '');
    if (!in_array($role, ['admin', 'consultant', 'customer'], true)) {
        throw new RuntimeException('Kullanıcı rolü geçersiz.');
    }

    $id = DB::insert(
        'INSERT INTO users (role, name, email, phone, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())',
        [
            $role,
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['phone'] ?? '')),
            password_hash((string) ($data['password'] ?? 'password'), PASSWORD_DEFAULT),
        ]
    );

    if ($role === 'consultant') {
        DB::execute(
            'INSERT INTO consultant_profiles (user_id, title, bio, color, booking_deadline_hours) VALUES (?, ?, ?, ?, ?)',
            [$id, (string) ($data['title'] ?? 'Danışman'), '', (string) ($data['color'] ?? '#0891b2'), $data['booking_deadline_hours'] ?? null]
        );
    }

    return DB::fetch('SELECT id, role, name, email, phone, status, created_at FROM users WHERE id = ?', [$id]);
}

function updateUser(int $id, array $data): array
{
    DB::execute(
        'UPDATE users SET name = ?, email = ?, phone = ?, status = ? WHERE id = ?',
        [
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['email'] ?? '')),
            trim((string) ($data['phone'] ?? '')),
            in_array(($data['status'] ?? 'active'), ['active', 'passive'], true) ? $data['status'] : 'active',
            $id,
        ]
    );

    if (!empty($data['password'])) {
        DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string) $data['password'], PASSWORD_DEFAULT), $id]);
    }

    return DB::fetch('SELECT id, role, name, email, phone, status, created_at FROM users WHERE id = ?', [$id]);
}

function saveService(?int $id, array $data): array
{
    $type = in_array(($data['type'] ?? 'one_to_one'), ['one_to_one', 'group'], true) ? $data['type'] : 'one_to_one';
    $capacity = $type === 'group' ? max(1, (int) ($data['capacity'] ?? 1)) : 1;

    if ($id) {
        DB::execute(
            'UPDATE services SET name = ?, description = ?, type = ?, duration_minutes = ?, capacity = ?, price = ?, active = ? WHERE id = ?',
            [(string) $data['name'], (string) ($data['description'] ?? ''), $type, (int) $data['duration_minutes'], $capacity, (float) $data['price'], (int) ($data['active'] ?? 1), $id]
        );
    } else {
        $id = DB::insert(
            'INSERT INTO services (name, description, type, duration_minutes, capacity, price, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [(string) $data['name'], (string) ($data['description'] ?? ''), $type, (int) $data['duration_minutes'], $capacity, (float) $data['price'], (int) ($data['active'] ?? 1)]
        );
    }

    return DB::fetch('SELECT * FROM services WHERE id = ?', [$id]);
}

function savePackage(?int $id, array $data): array
{
    if ($id) {
        DB::execute(
            'UPDATE packages SET name = ?, description = ?, total_credits = ?, validity_days = ?, price = ?, active = ? WHERE id = ?',
            [(string) $data['name'], (string) ($data['description'] ?? ''), (int) $data['total_credits'], (int) $data['validity_days'], (float) $data['price'], (int) ($data['active'] ?? 1), $id]
        );
    } else {
        $id = DB::insert(
            'INSERT INTO packages (name, description, total_credits, validity_days, price, active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [(string) $data['name'], (string) ($data['description'] ?? ''), (int) $data['total_credits'], (int) $data['validity_days'], (float) $data['price'], (int) ($data['active'] ?? 1)]
        );
    }

    return DB::fetch('SELECT * FROM packages WHERE id = ?', [$id]);
}

function assignPackage(array $data): array
{
    $package = DB::fetch('SELECT * FROM packages WHERE id = ? AND active = 1', [(int) ($data['package_id'] ?? 0)]);
    if (!$package) {
        throw new RuntimeException('Paket bulunamadı.');
    }

    $startsAt = (string) ($data['starts_at'] ?? date('Y-m-d'));
    $expiresAt = (new DateTimeImmutable($startsAt))->modify('+' . (int) $package['validity_days'] . ' days')->format('Y-m-d');
    $id = DB::insert(
        'INSERT INTO customer_packages (customer_id, package_id, credits_total, credits_remaining, starts_at, expires_at, status, payment_status, created_at) VALUES (?, ?, ?, ?, ?, ?, "active", ?, NOW())',
        [(int) $data['customer_id'], (int) $package['id'], (int) $package['total_credits'], (int) $package['total_credits'], $startsAt, $expiresAt, (string) ($data['payment_status'] ?? 'pending')]
    );

    return DB::fetch(customerPackageSql() . ' WHERE cp.id = ?', [$id]);
}

function customerPackageSql(): string
{
    return 'SELECT cp.*, p.name AS package_name, u.name AS customer_name
            FROM customer_packages cp
            INNER JOIN packages p ON p.id = cp.package_id
            INNER JOIN users u ON u.id = cp.customer_id';
}

function normalizeApiDate(string $value): string
{
    $value = str_replace('T', ' ', $value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16))
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if (!$date) {
        throw new RuntimeException('Tarih formatı geçersiz.');
    }

    return $date->format('Y-m-d H:i:s');
}

function reportSummary(array $user): array
{
    $consultantFilter = '';
    $params = [];
    if ($user['role'] === 'consultant') {
        $consultantFilter = ' AND consultant_id = ?';
        $params[] = $user['id'];
    }

    $today = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE DATE(starts_at) = CURDATE()' . $consultantFilter, $params);
    $upcoming = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE starts_at >= NOW() AND status IN ("pending", "confirmed")' . $consultantFilter, $params);
    $completed = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status = "completed" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $consultantFilter, $params);
    $noShow = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status = "no_show" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $consultantFilter, $params);
    $revenue = $user['role'] === 'admin'
        ? DB::fetch('SELECT COALESCE(SUM(price), 0) AS total FROM reservations WHERE reservation_type = "single" AND payment_status = "paid" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)')
        : ['total' => 0];

    return [
        'today_reservations' => (int) ($today['total'] ?? 0),
        'upcoming_reservations' => (int) ($upcoming['total'] ?? 0),
        'completed_last_30_days' => (int) ($completed['total'] ?? 0),
        'no_show_last_30_days' => (int) ($noShow['total'] ?? 0),
        'single_session_revenue_last_30_days' => (float) ($revenue['total'] ?? 0),
    ];
}
