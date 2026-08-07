<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$allowedOrigin = (string) config('api.allowed_origin', config('app.url', ''));
$requestOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($requestOrigin !== '' && $allowedOrigin !== '' && rtrim($requestOrigin, '/') === rtrim($allowedOrigin, '/')) {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('X-FizyoRez-API: beta');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) ($_GET['path'] ?? '');
if ($path === '') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $marker = '/api/v1';
    $position = strpos($uri, $marker);
    $path = $position === false ? $uri : substr($uri, $position + strlen($marker));
}
$segments = array_values(array_filter(explode('/', trim($path, '/'))));
$body = request_input();

try {
    $resource = $segments[0] ?? 'health';
    $id = isset($segments[1]) && ctype_digit((string) $segments[1]) ? (int) $segments[1] : null;
    $operation = (string) ($segments[2] ?? '');

    if ($resource === 'health') {
        ApiResponse::ok(['status' => 'ready', 'app' => config('app.name'), 'api' => 'v1-beta']);
    }

    if ($resource === 'auth' && ($segments[1] ?? '') === 'login' && $method === 'POST') {
        $user = Auth::attempt((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        if (!$user) {
            ApiResponse::error('E-posta veya şifre hatalı.', 422);
        }
        $token = Auth::issueToken((int) $user['id']);
        ApiResponse::ok(['user' => $user, 'access_token' => $token['token'], 'expires_at' => $token['expires_at']]);
    }

    if ($resource === 'mail' && ($segments[1] ?? '') === 'send' && $method === 'POST') {
        $token = (string) ($_GET['token'] ?? $body['token'] ?? '');
        if (!hash_equals((string) config('security.cron_token'), $token)) {
            ApiResponse::error('Cron token hatalı.', 403);
        }
        ApiResponse::ok(['mail' => Mailer::sendPending(25)]);
    }

    $user = Auth::requireApi();

    if ($resource === 'auth' && ($segments[1] ?? '') === 'logout' && $method === 'POST') {
        Auth::revokeCurrentApiToken();
        ApiResponse::ok();
    }

    if ($resource === 'me' && $method === 'GET') {
        ApiResponse::ok(['user' => $user]);
    }

    if ($resource === 'bootstrap' && $method === 'GET') {
        ApiResponse::ok([
            'user' => $user,
            'services' => DB::fetchAll('SELECT * FROM services WHERE active = 1 ORDER BY name'),
            'consultants' => consultants_api(),
            'packages' => DB::fetchAll('SELECT * FROM packages WHERE active = 1 ORDER BY name'),
            'settings' => Management::publicSettings(),
        ]);
    }

    if ($resource === 'users') {
        Authorization::require($user, 'users.view');
        if ($method === 'GET') {
            $role = (string) ($_GET['role'] ?? '');
            $where = $role !== '' ? 'WHERE role = ?' : '';
            ApiResponse::ok(['users' => DB::fetchAll('SELECT id, role, name, email, phone, status, created_at FROM users ' . $where . ' ORDER BY name', $role !== '' ? [$role] : [])]);
        }
        if ($method === 'POST' && $id === null) {
            ApiResponse::ok(['user' => Management::createUser($user, $body)]);
        }
        if ($id && in_array($method, ['PUT', 'PATCH'], true)) {
            ApiResponse::ok(['user' => Management::updateUser($user, $id, $body)]);
        }
    }

    if ($resource === 'services') {
        if ($method === 'GET') {
            ApiResponse::ok(['services' => DB::fetchAll('SELECT * FROM services ORDER BY active DESC, name')]);
        }
        if ($method === 'POST' && $id === null) {
            ApiResponse::ok(['service' => Management::saveService($user, null, $body)]);
        }
        if ($id && in_array($method, ['PUT', 'PATCH'], true)) {
            ApiResponse::ok(['service' => Management::saveService($user, $id, $body)]);
        }
    }

    if ($resource === 'packages') {
        if ($method === 'GET') {
            ApiResponse::ok(['packages' => DB::fetchAll('SELECT * FROM packages ORDER BY active DESC, name')]);
        }
        if ($method === 'POST' && $id === null) {
            ApiResponse::ok(['package' => Management::savePackage($user, null, $body)]);
        }
        if ($id && in_array($method, ['PUT', 'PATCH'], true)) {
            ApiResponse::ok(['package' => Management::savePackage($user, $id, $body)]);
        }
    }

    if ($resource === 'customer-packages') {
        if ($method === 'GET') {
            if ($user['role'] === 'customer') {
                $customerId = (int) $user['id'];
            } else {
                Authorization::require($user, 'packages.manage');
                $customerId = (int) ($_GET['customer_id'] ?? 0);
            }
            $where = $customerId > 0 ? ' WHERE cp.customer_id = ?' : '';
            ApiResponse::ok(['customer_packages' => DB::fetchAll(Management::customerPackageSql() . $where . ' ORDER BY cp.expires_at DESC', $customerId > 0 ? [$customerId] : [])]);
        }
        if ($method === 'POST') {
            ApiResponse::ok(['customer_package' => Management::assignPackage($user, $body)]);
        }
    }

    if ($resource === 'reservations') {
        if ($method === 'GET') {
            ApiResponse::ok(['reservations' => ReservationService::list($user, $_GET)]);
        }
        if ($method === 'POST' && $id === null) {
            ApiResponse::ok(['reservation' => ReservationService::create($user, $body)]);
        }
        if ($id && $operation === 'cancel' && $method === 'POST') {
            ApiResponse::ok(['reservation' => ReservationService::cancel($user, $id)]);
        }
        if ($id && $operation === 'status' && $method === 'POST') {
            ApiResponse::ok(['reservation' => ReservationService::setStatus($user, $id, (string) ($body['status'] ?? ''))]);
        }
        if ($id && $operation === 'reschedule' && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            ApiResponse::ok(['reservation' => ReservationService::reschedule($user, $id, (string) ($body['starts_at'] ?? ''))]);
        }
        if ($id && $operation === 'payment') {
            ApiResponse::error('Ödeme durumu doğrudan değiştirilemez. /payments uç noktasını kullanın.', 409);
        }
    }

    if ($resource === 'payments') {
        if ($method === 'GET') {
            ApiResponse::ok(['payments' => PaymentService::list($user, isset($_GET['status']) ? (string) $_GET['status'] : null)]);
        }
        if ($method === 'POST' && $id === null) {
            if ($user['role'] === 'customer') {
                ApiResponse::ok(['payment' => PaymentService::purchaseWithWallet(
                    $user,
                    (string) ($body['target_type'] ?? ''),
                    (int) ($body['target_id'] ?? 0)
                )]);
            }
            ApiResponse::ok(['payment' => PaymentService::create($user, $body)]);
        }
        if ($id && $operation === 'approve' && $method === 'POST') {
            ApiResponse::ok(['payment' => PaymentService::approve($user, $id)]);
        }
        if ($id && $operation === 'cancel' && $method === 'POST') {
            ApiResponse::ok(['payment' => PaymentService::cancel($user, $id, (string) ($body['note'] ?? ''))]);
        }
        if ($id && $operation === 'refund' && $method === 'POST') {
            ApiResponse::ok(['payment' => PaymentService::refund($user, $id, (string) ($body['note'] ?? ''))]);
        }
        if ($id && $operation === 'reopen' && $method === 'POST') {
            ApiResponse::ok(['payment' => PaymentService::reopen($user, $id, (string) ($body['note'] ?? ''))]);
        }
    }

    if ($resource === 'availability') {
        if ($method === 'GET') {
            if ($id) {
                ApiResponse::ok(['days' => ScheduleService::calendarDays(
                    $user,
                    $id,
                    (string) ($_GET['from'] ?? date('Y-m-d')),
                    (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+30 days')))
                )]);
            }
            ApiResponse::ok(['consultants' => ScheduleService::calendarSummaries($user)]);
        }
        if ($method === 'POST' && $operation === '') {
            ScheduleService::saveDate($user, $body);
            ApiResponse::ok();
        }
        if ($id && $operation === 'generate' && $method === 'POST') {
            ScheduleService::generateCalendar(
                $user,
                $id,
                (string) ($body['from_date'] ?? date('Y-m-d')),
                (string) ($body['to_date'] ?? date('Y-m-d', strtotime('+180 days')))
            );
            ApiResponse::ok();
        }
    }

    if ($resource === 'wallet') {
        if ($method === 'GET') {
            $walletCustomerId = $user['role'] === 'customer' ? (int) $user['id'] : (int) ($_GET['customer_id'] ?? 0);
            if ($user['role'] !== 'customer') Authorization::require($user, 'payments.view_all');
            if ($walletCustomerId < 1) ApiResponse::error('customer_id zorunludur.', 422);
            ApiResponse::ok([
                'balance' => WalletService::balance($walletCustomerId),
                'reserved_balance' => WalletService::reservedBalance($walletCustomerId),
                'available_balance' => WalletService::availableBalance($walletCustomerId),
                'transactions' => WalletService::transactions($user, $walletCustomerId),
            ]);
        }
        if (($segments[1] ?? '') === 'top-up-test' && $method === 'POST') {
            ApiResponse::ok(['top_up' => WalletService::simulateTopUp($user, $body)]);
        }
    }

    if ($resource === 'time-off') {
        if ($method === 'GET') {
            ApiResponse::ok(['time_off' => ScheduleService::timeOff($user)]);
        }
        if ($method === 'POST') {
            ScheduleService::addTimeOff($user, $body);
            ApiResponse::ok();
        }
        if ($id && $method === 'DELETE') {
            ScheduleService::deleteTimeOff($user, $id);
            ApiResponse::ok();
        }
    }

    if ($resource === 'clinical-notes') {
        if ($method === 'GET') {
            ApiResponse::ok(['clinical_notes' => ClinicalService::notes($user, isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null)]);
        }
        if ($method === 'POST') {
            ApiResponse::ok(['clinical_note' => ClinicalService::createNote($user, $body)]);
        }
    }

    if ($resource === 'exercise-programs' && $method === 'GET' && $user['role'] === 'customer') {
        ApiResponse::ok(['exercise_programs' => ClinicalService::programsForCustomer((int) $user['id'])]);
    }

    if ($resource === 'settings') {
        Authorization::require($user, 'settings.manage');
        if ($method === 'GET') {
            ApiResponse::ok(['settings' => Management::publicSettings()]);
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            foreach (['booking_change_deadline_hours', 'late_cancel_burn_credit', 'credit_deduction_policy', 'reservation_reminder_hours', 'currency', 'bank_name', 'bank_iban', 'bank_account_name', 'test_card_number', 'test_card_expiry', 'test_card_cvv'] as $key) {
                if (array_key_exists($key, $body)) {
                    save_setting($key, trim((string) $body[$key]));
                }
            }
            Audit::record((int) $user['id'], 'settings.updated', 'settings');
            ApiResponse::ok(['settings' => Management::publicSettings()]);
        }
    }

    if ($resource === 'reports' && ($segments[1] ?? '') === 'summary') {
        Authorization::require($user, 'reports.view');
        ApiResponse::ok(['summary' => Management::reportSummary($user)]);
    }

    ApiResponse::error('Endpoint bulunamadı.', 404);
} catch (Throwable $e) {
    ApiResponse::error(friendly_error_message($e), 422);
}

function consultants_api(): array
{
    return DB::fetchAll(
        'SELECT u.id, u.name, u.email, u.phone, cp.title, cp.color, cp.booking_deadline_hours
         FROM users u LEFT JOIN consultant_profiles cp ON cp.user_id = u.id
         WHERE u.role = "consultant" AND u.status = "active" ORDER BY u.name'
    );
}
