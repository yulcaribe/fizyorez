<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$path = current_path();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    if (in_array($path, ['/health', '/setup-check'], true)) {
        render_health();
        exit;
    }

    if ($path === '/register') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            verify_csrf();
            Auth::registerCustomer($_POST);
            $user = Auth::attempt((string) $_POST['email'], (string) $_POST['password']);
            if (!$user) {
                throw new RuntimeException('Hesap oluşturuldu ancak otomatik giriş yapılamadı.');
            }
            flash('success', 'Hoş geldiniz. Üyeliğiniz oluşturuldu.');
            redirect_to('/customer');
        }
        render_register($flash);
        exit;
    }

    if ($path === '/login') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            verify_csrf();
            $user = Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if (!$user) {
                flash('error', 'E-posta veya şifre hatalı.');
                redirect_to('/login');
            }
            redirect_to(role_home((string) $user['role']));
        }
        render_login($flash);
        exit;
    }

    if ($path === '/logout') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            redirect_to('/login');
        }
        verify_csrf();
        Auth::logout();
        redirect_to('/login');
    }

    $user = Auth::requireWeb();
    if (str_starts_with($path, '/consultant')) {
        redirect_to('/admin' . substr($path, strlen('/consultant')));
    }
    if ($path === '/' || $path === '/index.php') {
        redirect_to(role_home((string) $user['role']));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handle_web_action($user, $path);
    }

    render_page($user, $path, $flash);
} catch (Throwable $e) {
    http_response_code(500);
    render_error($e);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function role_home(string $role): string
{
    return $role === 'customer' ? '/customer' : '/admin';
}

function handle_web_action(array $user, string $path): never
{
    verify_csrf();
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'create_user': Management::createUser($user, $_POST); break;
            case 'update_user': Management::updateUser($user, (int) $_POST['user_id'], $_POST); break;
            case 'create_service': Management::saveService($user, null, $_POST); break;
            case 'create_package': Management::savePackage($user, null, $_POST); break;
            case 'assign_package': Management::assignPackage($user, $_POST); break;
            case 'adjust_credit': CreditLedger::adjust($user, (int) $_POST['customer_package_id'], (int) $_POST['amount'], (string) ($_POST['note'] ?? '')); break;
            case 'adjust_wallet': WalletService::adjust($user, (int) $_POST['customer_id'], (float) $_POST['amount'], (string) ($_POST['note'] ?? '')); break;
            case 'save_settings': save_settings_action($user); break;
            case 'save_role_permissions': Authorization::saveRolePermissions($user, (int) $_POST['role_id'], (array) ($_POST['permission_ids'] ?? [])); break;
            case 'create_reservation': ReservationService::create($user, $_POST); break;
            case 'cancel_reservation': ReservationService::cancel($user, (int) $_POST['reservation_id']); break;
            case 'reschedule_reservation': ReservationService::reschedule($user, (int) $_POST['reservation_id'], (string) $_POST['starts_at']); break;
            case 'review_reschedule_request': ReservationService::reviewChangeRequest($user, (int) $_POST['request_id'], (string) $_POST['decision'], (string) ($_POST['review_note'] ?? '')); break;
            case 'set_reservation_status': ReservationService::setStatus($user, (int) $_POST['reservation_id'], (string) $_POST['status']); break;
            case 'save_date_availability': ScheduleService::saveDate($user, $_POST); break;
            case 'clear_date_availability': ScheduleService::clearDate($user, $_POST); break;
            case 'generate_calendar': ScheduleService::generateCalendar($user, (int) ($_POST['consultant_id'] ?? 0), (string) $_POST['from_date'], (string) $_POST['to_date']); break;
            case 'add_time_off': ScheduleService::addTimeOff($user, $_POST); break;
            case 'delete_time_off': ScheduleService::deleteTimeOff($user, (int) $_POST['time_off_id']); break;
            case 'create_payment': PaymentService::create($user, $_POST); break;
            case 'approve_payment': PaymentService::approve($user, (int) $_POST['payment_id']); break;
            case 'cancel_payment': PaymentService::cancel($user, (int) $_POST['payment_id'], (string) ($_POST['note'] ?? '')); break;
            case 'refund_payment': PaymentService::refund($user, (int) $_POST['payment_id'], (string) ($_POST['note'] ?? '')); break;
            case 'reopen_payment': PaymentService::reopen($user, (int) $_POST['payment_id'], (string) ($_POST['note'] ?? '')); break;
            case 'simulate_wallet_topup': WalletService::simulateTopUp($user, $_POST); break;
            case 'purchase_with_wallet': PaymentService::purchaseWithWallet($user, (string) ($_POST['target_type'] ?? ''), (int) ($_POST['target_id'] ?? 0)); break;
            case 'update_profile': Auth::updateOwnProfile($user, $_POST); break;
            case 'change_password': Auth::changePassword($user, $_POST); break;
            case 'create_clinical_note': ClinicalService::createNote($user, $_POST); break;
            case 'add_patient_history': ClinicalService::addHistory($user, $_POST); break;
            case 'create_exercise': ClinicalService::createExercise($user, $_POST); break;
            case 'create_exercise_program': ClinicalService::createProgram($user, $_POST); break;
            case 'add_exercise_program_item': ClinicalService::addProgramItem($user, $_POST); break;
            default: throw new RuntimeException('İşlem bulunamadı.');
        }
        flash('success', 'İşlem başarıyla tamamlandı.');
    } catch (Throwable $e) {
        flash('error', friendly_error_message($e));
    }
    $returnStatus = (string) ($_POST['return_status'] ?? '');
    $returnView = (string) ($_POST['return_view'] ?? '');
    if ($path === '/admin/payments' && (isset(PaymentService::STATUSES[$returnStatus]) || $returnView !== '')) {
        $query = http_build_query(array_filter(['status' => $returnStatus, 'view' => $returnView], static fn (string $value): bool => $value !== ''));
        redirect_to($path . ($query !== '' ? '?' . $query : ''));
    }
    redirect_to($path);
}

function save_settings_action(array $user): void
{
    Authorization::require($user, 'settings.manage');
    if (array_key_exists('test_card_number', $_POST)) {
        $_POST['test_card_number'] = preg_replace('/\D+/', '', (string) $_POST['test_card_number']);
        $_POST['test_card_cvv'] = preg_replace('/\D+/', '', (string) ($_POST['test_card_cvv'] ?? ''));
        $_POST['test_card_expiry'] = preg_replace('/\s+/', '', (string) ($_POST['test_card_expiry'] ?? ''));
        if (strlen((string) $_POST['test_card_number']) < 12 || strlen((string) $_POST['test_card_number']) > 19
            || !preg_match('#^\d{2}/\d{2}$#', (string) $_POST['test_card_expiry'])
            || strlen((string) $_POST['test_card_cvv']) < 3 || strlen((string) $_POST['test_card_cvv']) > 4) {
            throw new RuntimeException('Test kartı bilgileri geçersiz.');
        }
    }
    if (array_key_exists('currency', $_POST)) {
        $_POST['currency'] = strtoupper(trim((string) $_POST['currency']));
        if (!preg_match('/^[A-Z]{3}$/', (string) $_POST['currency'])) {
            throw new RuntimeException('Para birimi üç harfli kod olmalıdır.');
        }
    }
    $allowed = [
        'booking_change_deadline_hours', 'late_cancel_burn_credit', 'credit_deduction_policy',
        'reservation_reminder_hours', 'currency', 'bank_name', 'bank_iban', 'bank_account_name',
        'test_card_number', 'test_card_expiry', 'test_card_cvv',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $_POST)) {
            save_setting($key, trim((string) $_POST[$key]));
        }
    }
    Audit::record((int) $user['id'], 'settings.updated', 'settings');
}

function render_health(): void
{
    $checks = setup_checks();
    $allOk = !in_array(false, array_column($checks, 'ok'), true);
    ?>
    <!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kurulum Kontrolü · <?= e(config('app.name')) ?></title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css') . '?v=6') ?>"></head>
    <body class="auth-page"><main class="auth-card wide"><div class="brand-mark">FR</div><h1>Kurulum kontrolü</h1>
    <p class="muted"><?= $allOk ? 'Sistem çalışmaya hazır.' : 'Aşağıdaki eksikleri giderip tekrar deneyin.' ?></p>
    <div class="table-wrap"><table><thead><tr><th>Kontrol</th><th>Durum</th><th>Detay</th></tr></thead><tbody>
    <?php foreach ($checks as $check): ?><tr><td><?= e($check['label']) ?></td><td><span class="badge <?= $check['ok'] ? 'completed' : 'cancelled' ?>"><?= $check['ok'] ? 'Hazır' : 'Hata' ?></span></td><td><?= e($check['detail']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><a class="btn btn-primary" href="<?= e(url_for('/login')) ?>">Girişe dön</a></main></body></html>
    <?php
}

function render_login(?array $flash): void
{
    render_auth_start('Giriş Yap'); ?>
    <main class="auth-shell">
        <section class="auth-hero"><div class="brand-mark">FR</div><p class="eyebrow">Fizyoterapi yönetimi</p><h1>Danışan takibi artık daha düzenli.</h1><p>Seans, paket, hak ve ekip takvimini tek güvenli çalışma alanında yönetin.</p></section>
        <form method="post" class="auth-card"><?= csrf_field() ?><h2>Hesabınıza giriş yapın</h2><?php render_flash($flash); ?>
            <label>E-posta<input type="email" name="email" autocomplete="email" required></label>
            <label>Şifre<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="btn btn-primary btn-block">Giriş Yap</button>
            <?php if ((bool) config('registration.enabled', true)): ?><p class="auth-link">Danışan mısınız? <a href="<?= e(url_for('/register')) ?>">Ücretsiz hesap oluşturun</a></p><?php endif; ?>
        </form>
    </main></body></html>
    <?php
}

function render_register(?array $flash): void
{
    render_auth_start('Danışan Üyeliği'); ?>
    <main class="auth-shell">
        <section class="auth-hero"><div class="brand-mark">FR</div><p class="eyebrow">Danışan hesabı</p><h1>Randevularınız ve paketleriniz yanınızda.</h1><p>Kendi hesabınızı oluşturun; fizyoterapist ve personel hesapları kurum tarafından açılır.</p></section>
        <form method="post" class="auth-card"><?= csrf_field() ?><h2>Yeni hesap oluştur</h2><?php render_flash($flash); ?>
            <label>Ad soyad<input name="name" autocomplete="name" required></label><label>E-posta<input type="email" name="email" autocomplete="email" required></label>
            <label>Telefon<input name="phone" autocomplete="tel"></label><label>Şifre<input type="password" name="password" minlength="8" autocomplete="new-password" required></label>
            <label>Şifre tekrarı<input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required></label>
            <label class="check-label"><input type="checkbox" name="privacy_consent" value="1" required><span>Üyelik verilerimin hizmetin sunulması amacıyla işlenmesini kabul ediyorum.</span></label>
            <button class="btn btn-primary btn-block">Hesabımı Oluştur</button><p class="auth-link">Zaten hesabınız var mı? <a href="<?= e(url_for('/login')) ?>">Giriş yapın</a></p>
        </form>
    </main></body></html>
    <?php
}

function render_auth_start(string $title): void
{
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · <?= e(config('app.name')) ?></title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css') . '?v=6') ?>"></head><body class="auth-page"><?php
}

function render_page(array $user, string $path, ?array $flash): void
{
    assert_role_path($user, $path);
    $title = page_title($path);
    ?>
    <!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(config('app.name')) ?></title><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css') . '?v=6') ?>"></head><body>
    <div class="app-shell"><aside class="sidebar" id="sidebar"><a href="<?= e(url_for(role_home((string) $user['role']))) ?>" class="brand"><span class="brand-mark">FR</span><span><strong><?= e(config('app.name')) ?></strong><small>Beta</small></span></a><nav class="nav"><?= nav_links($user, $path) ?></nav></aside>
    <div class="main-shell"><header class="topbar"><button type="button" class="menu-button" data-menu aria-controls="sidebar" aria-expanded="false" aria-label="Menüyü aç">☰</button><div><p class="eyebrow"><?= e(role_label((string) $user['role'])) ?></p><h1><?= e($title) ?></h1></div><div class="user-box"><span><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?></small></span><form method="post" action="<?= e(url_for('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost">Çıkış</button></form></div></header>
    <?php render_flash($flash); ?><main class="content"><?php render_route($user, $path); ?></main></div></div>
    <script src="<?= e(asset_url('assets/js/app.js') . '?v=6') ?>"></script></body></html>
    <?php
}

function assert_role_path(array $user, string $path): void
{
    $prefix = role_home((string) $user['role']);
    if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
        redirect_to($prefix);
    }
}

function page_title(string $path): string
{
    return [
        '/admin' => 'Genel Bakış', '/admin/users' => 'Personel Yönetimi', '/admin/customers' => 'Danışanlar', '/admin/roles' => 'Rol ve Yetkiler',
        '/admin/services' => 'Hizmetler', '/admin/packages' => 'Paket ve Haklar', '/admin/reservations' => 'Rezervasyonlar',
        '/admin/availability' => 'Takvim ve Planlama', '/admin/time-off' => 'İzin ve Molalar', '/admin/payments' => 'Ödemeler ve Bakiye',
        '/admin/reports' => 'Raporlar', '/admin/settings' => 'Sistem Ayarları', '/admin/profile' => 'Profilim',
        '/admin/clinical' => 'Fizyoterapi Kayıtları', '/admin/exercises' => 'Egzersiz Programları',
        '/admin/audit' => 'İşlem Kayıtları',
        '/customer' => 'Ana Sayfa', '/customer/book' => 'Randevu Al', '/customer/reservations' => 'Randevularım',
        '/customer/packages' => 'Paketlerim', '/customer/payments' => 'Bakiye ve Ödemelerim', '/customer/profile' => 'Profilim',
        '/customer/programs' => 'Egzersiz Programım',
    ][$path] ?? 'FizyoRez';
}

function role_label(string $role): string
{
    return ['super_admin' => 'Süper Yönetici', 'admin' => 'Yönetici', 'staff' => 'Resepsiyon / Personel', 'consultant' => 'Fizyoterapist', 'customer' => 'Danışan Portalı'][$role] ?? $role;
}

function nav_links(array $user, string $path): string
{
    if ($user['role'] === 'customer') {
        $links = ['/customer' => 'Ana Sayfa', '/customer/book' => 'Randevu Al', '/customer/reservations' => 'Randevularım', '/customer/packages' => 'Paketlerim', '/customer/programs' => 'Egzersiz Programım', '/customer/payments' => 'Bakiye ve Ödemelerim', '/customer/profile' => 'Profilim'];
    } else {
        $links = ['/admin' => 'Genel Bakış'];
        if (can($user, 'reservations.view_all') || can($user, 'reservations.manage_own')) $links['/admin/reservations'] = 'Rezervasyonlar';
        if (can($user, 'users.view')) {
            $links['/admin/users'] = 'Personel';
            $links['/admin/customers'] = 'Danışanlar';
        }
        if (can($user, 'roles.view')) $links['/admin/roles'] = 'Rol ve Yetkiler';
        if (can($user, 'services.manage')) $links['/admin/services'] = 'Hizmetler';
        if (can($user, 'packages.manage')) $links['/admin/packages'] = 'Paket ve Haklar';
        if (can($user, 'clinical.view_all')) $links['/admin/clinical'] = 'Fizyoterapi Kayıtları';
        if (can($user, 'exercises.manage')) $links['/admin/exercises'] = 'Egzersiz Programları';
        if (can($user, 'schedules.manage_own') || can($user, 'schedules.view_all')) $links['/admin/availability'] = 'Takvim ve Planlama';
        if (can($user, 'time_off.manage_own') || can($user, 'time_off.manage_all')) $links['/admin/time-off'] = 'İzin ve Molalar';
        if (can($user, 'payments.view_all') || can($user, 'payments.create') || can($user, 'payments.approve') || can($user, 'payments.refund') || can($user, 'wallets.adjust')) $links['/admin/payments'] = 'Ödemeler ve Bakiye';
        if (can($user, 'reports.view')) $links['/admin/reports'] = 'Raporlar';
        if (can($user, 'settings.manage')) $links['/admin/settings'] = 'Ayarlar';
        if (can($user, 'audit_logs.view')) $links['/admin/audit'] = 'İşlem Kayıtları';
        $links['/admin/profile'] = 'Profilim';
    }
    $html = '';
    foreach ($links as $href => $label) {
        $html .= '<a class="' . ($path === $href ? 'active' : '') . '" href="' . e(url_for($href)) . '">' . e($label) . '</a>';
    }
    return $html;
}

function render_route(array $user, string $path): void
{
    switch ($path) {
        case '/admin': Authorization::require($user, 'dashboard.view'); render_dashboard($user); break;
        case '/customer': render_dashboard($user); break;
        case '/admin/users': Authorization::require($user, 'users.view'); render_users($user, false); break;
        case '/admin/customers': Authorization::require($user, 'users.view'); render_users($user, true); break;
        case '/admin/roles': Authorization::require($user, 'roles.view'); render_roles($user); break;
        case '/admin/services': Authorization::require($user, 'services.manage'); render_services(); break;
        case '/admin/packages': Authorization::require($user, 'packages.manage'); render_packages($user); break;
        case '/admin/clinical': Authorization::require($user, 'clinical.view_all'); render_clinical($user); break;
        case '/admin/exercises': Authorization::require($user, 'exercises.manage'); render_exercises($user); break;
        case '/admin/reservations': case '/customer/reservations': case '/customer/book': render_reservations($user, $path); break;
        case '/admin/availability': render_availability($user); break;
        case '/admin/time-off': render_time_off($user); break;
        case '/admin/payments': case '/customer/payments': render_financial_page($user); break;
        case '/admin/reports': Authorization::require($user, 'reports.view'); render_reports($user); break;
        case '/admin/settings': Authorization::require($user, 'settings.manage'); render_settings(); break;
        case '/admin/audit': Authorization::require($user, 'audit_logs.view'); render_audit_logs(); break;
        case '/admin/profile': case '/customer/profile': render_profile($user); break;
        case '/customer/packages': render_customer_packages($user); break;
        case '/customer/programs': render_customer_programs($user); break;
        default: http_response_code(404); echo '<section class="panel empty-state"><h2>Sayfa bulunamadı</h2></section>';
    }
}

function render_dashboard(array $user): void
{
    if ($user['role'] === 'customer') {
        $packages = DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? ORDER BY cp.expires_at ASC', [$user['id']]);
        $upcoming = ReservationService::list($user, ['from' => date('Y-m-d H:i:s')]);
        $walletBalance = WalletService::availableBalance((int) $user['id']);
        ?><section class="welcome-card"><div><p class="eyebrow">Hoş geldiniz</p><h2><?= e($user['name']) ?></h2><p>Randevularınızı, paket haklarınızı ve ödeme durumunuzu buradan takip edebilirsiniz.</p></div><a class="btn btn-light" href="<?= e(url_for('/customer/book')) ?>">Yeni Randevu</a></section>
        <section class="grid cards-4"><div class="metric"><span>Aktif paket</span><strong><?= count(array_filter($packages, fn ($p) => $p['status'] === 'active')) ?></strong></div><div class="metric"><span>Kalan hak</span><strong><?= array_sum(array_map(fn ($p) => (int) $p['credits_remaining'], $packages)) ?></strong></div><div class="metric"><span>Yaklaşan randevu</span><strong><?= count($upcoming) ?></strong></div><div class="metric accent"><span>Kullanılabilir bakiye</span><strong><?= money($walletBalance) ?></strong></div></section><?php
        render_reservation_table($user, array_slice($upcoming, 0, 8));
        return;
    }
    $summary = Management::reportSummary($user);
    ?><section class="grid cards-5"><div class="metric"><span>Bugün</span><strong><?= e($summary['today_reservations']) ?></strong></div><div class="metric"><span>Yaklaşan</span><strong><?= e($summary['upcoming_reservations']) ?></strong></div><div class="metric"><span>30 gün tamamlanan</span><strong><?= e($summary['completed_last_30_days']) ?></strong></div><div class="metric"><span>Gelmedi</span><strong><?= e($summary['no_show_last_30_days']) ?></strong></div><?php if (can($user, 'payments.view_all')): ?><div class="metric accent"><span>30 gün tahsilat</span><strong><?= money($summary['revenue_last_30_days']) ?></strong></div><?php endif; ?></section><?php
    render_reservation_table($user, ReservationService::list($user, ['from' => date('Y-m-d 00:00:00'), 'to' => date('Y-m-d 23:59:59')]));
}

function render_users(array $actor, bool $customersOnly): void
{
    $where = $customersOnly ? 'role = "customer"' : 'role != "customer"';
    $users = DB::fetchAll(
        'SELECT id, role, name, email, phone, status, created_at FROM users WHERE ' . $where . '
         ORDER BY FIELD(role, "super_admin", "admin", "staff", "consultant", "customer"), name'
    );
    $wallets = [];
    if ($customersOnly) {
        foreach (WalletService::balancesForCustomers() as $wallet) {
            $wallets[(int) $wallet['id']] = (float) $wallet['wallet_balance'];
        }
    }
    if (can($actor, 'users.create')): ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= $customersOnly ? 'Danışan hesabı' : 'Ekip hesabı' ?></p><h2><?= $customersOnly ? 'Yeni danışan oluştur' : 'Yeni personel oluştur' ?></h2></div></div><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_user">
    <?php if ($customersOnly): ?><input type="hidden" name="role" value="customer"><?php else: ?><label>Rol<select name="role"><option value="consultant">Fizyoterapist</option><option value="staff">Resepsiyon / Personel</option><?php if ($actor['role'] === 'super_admin'): ?><option value="admin">Yönetici</option><?php endif; ?></select></label><?php endif; ?>
    <label>Ad soyad<input name="name" required></label><label>E-posta<input type="email" name="email" required></label><label>Telefon<input name="phone"></label><label>Geçici şifre<input type="password" name="password" minlength="8" required></label><?php if (!$customersOnly): ?><label>Fizyoterapist unvanı<input name="title" placeholder="Fizyoterapist"></label><?php endif; ?><button class="btn btn-primary"><?= $customersOnly ? 'Danışan Oluştur' : 'Personel Oluştur' ?></button></form></section>
    <?php endif; ?>
    <section class="panel"><div class="section-heading directory-heading"><div><p class="eyebrow"><?= count($users) ?> kayıt</p><h2><?= $customersOnly ? 'Danışanlar' : 'Personel ve yöneticiler' ?></h2><p class="muted"><?= $customersOnly ? 'Danışan portalına kendi kaydolanlar ve işletme tarafından açılan hesaplar burada yönetilir.' : 'Personel rolleri danışan hesaplarından ayrı tutulur.' ?></p></div></div>
    <div class="user-directory <?= $customersOnly ? 'has-wallet' : '' ?>">
        <div class="user-table-head"><span>Ad soyad</span><span>İletişim</span><span>Rol</span><?php if ($customersOnly): ?><span>Bakiye</span><?php endif; ?><span>Durum</span><span></span></div>
        <?php foreach ($users as $item):
            $editable = can($actor, 'users.update') && ($actor['role'] === 'super_admin' || !in_array($item['role'], ['super_admin', 'admin'], true));
            if ($editable): ?><details class="user-row"><summary class="user-row-summary"><?php else: ?><div class="user-row is-static"><div class="user-row-summary"><?php endif; ?>
                <span class="user-cell user-primary" data-label="Ad soyad"><strong><?= e($item['name']) ?></strong><small>Kullanıcı #<?= e($item['id']) ?></small></span>
                <span class="user-cell user-contact" data-label="İletişim"><strong><?= e($item['email']) ?></strong><small><?= e($item['phone'] ?: 'Telefon bilgisi yok') ?></small></span>
                <span class="user-cell" data-label="Rol"><span class="role-chip"><?= e(role_label((string) $item['role'])) ?></span></span>
                <?php if ($customersOnly): ?><span class="user-cell wallet-cell" data-label="Bakiye"><strong><?= money($wallets[(int) $item['id']] ?? 0) ?></strong></span><?php endif; ?>
                <span class="user-cell" data-label="Durum"><span class="badge <?= e($item['status']) ?>"><?= e(status_account_label((string) $item['status'])) ?></span></span>
                <span class="user-row-action"><?= $editable ? 'Düzenle' : '' ?></span>
            <?php if ($editable): ?></summary><form method="post" class="form-grid compact"><?= csrf_field() ?><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= e($item['id']) ?>"><label>Ad soyad<input name="name" value="<?= e($item['name']) ?>" required></label><label>E-posta<input type="email" name="email" value="<?= e($item['email']) ?>" required></label><label>Telefon<input name="phone" value="<?= e($item['phone']) ?>"></label><?php if ($customersOnly): ?><input type="hidden" name="role" value="customer"><?php else: ?><label>Rol<select name="role"><?php personnel_role_options((string) $item['role'], $actor); ?></select></label><?php endif; ?><label>Durum<select name="status"><?php foreach (['active' => 'Aktif', 'pending' => 'Bekliyor', 'suspended' => 'Askıda', 'passive' => 'Pasif'] as $value => $label): ?><option value="<?= e($value) ?>" <?= selected($item['status'], $value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label>Yeni şifre <small>(isteğe bağlı)</small><input type="password" name="password" minlength="8"></label><button class="btn btn-primary">Değişiklikleri Kaydet</button></form></details><?php else: ?></div></div><?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$users): ?><p class="empty">Henüz kayıt yok.</p><?php endif; ?>
    </div></section>
    <?php
}

function render_roles(array $actor): void
{
    $groups = Authorization::permissionGroups();
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow">Rol bazlı erişim</p><h2>Yetki matrisi</h2><p class="muted">Yetkiler kullanıcıya tek tek değil, role atanır. Süper yönetici tüm yetkilere sahiptir.</p></div></div></section><div class="role-grid"><?php
    foreach (Authorization::roles() as $role): $assigned = Authorization::rolePermissionIds((int) $role['id']); $locked = in_array($role['slug'], ['super_admin', 'customer'], true) || !can($actor, 'roles.manage'); ?>
        <form method="post" class="panel role-card"><?= csrf_field() ?><input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role_id" value="<?= e($role['id']) ?>"><h2><?= e($role['name']) ?></h2>
        <?php if ($role['slug'] === 'super_admin'): ?><p class="notice">Tüm yetkiler otomatik olarak açık.</p><?php elseif ($role['slug'] === 'customer'): ?><p class="notice">Danışan erişimi kendi portalı ile sınırlandırılmıştır.</p><?php else: foreach ($groups as $group => $permissions): ?><fieldset><legend><?= e($group) ?></legend><?php foreach ($permissions as $permission): ?><label class="check-label"><input type="checkbox" name="permission_ids[]" value="<?= e($permission['id']) ?>" <?= in_array((int) $permission['id'], $assigned, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>><span><?= e($permission['name']) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; if (!$locked): ?><button class="btn btn-primary">Yetkileri Kaydet</button><?php endif; endif; ?></form>
    <?php endforeach; ?></div><?php
}

function render_services(): void
{
    $services = DB::fetchAll('SELECT * FROM services ORDER BY active DESC, name'); ?>
    <section class="panel"><h2>Yeni hizmet / seans</h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_service"><label>Hizmet adı<input name="name" required></label><label>Seans tipi<select name="type"><option value="one_to_one">Birebir</option><option value="group">Grup</option></select></label><label>Süre (dk)<input type="number" name="duration_minutes" value="60" min="10" required></label><label>Kapasite<input type="number" name="capacity" value="1" min="1"></label><label>Tek seans fiyatı<input type="number" step="0.01" name="price" value="0" min="0"></label><label>Açıklama<input name="description"></label><button class="btn btn-primary">Hizmet Ekle</button></form></section><?php render_simple_table($services, ['name' => 'Hizmet', 'type' => 'Tip', 'duration_minutes' => 'Süre', 'capacity' => 'Kapasite', 'price' => 'Fiyat', 'active' => 'Aktif']);
}

function render_packages(array $actor): void
{
    $packages = DB::fetchAll('SELECT * FROM packages ORDER BY active DESC, name'); $customers = customers();
    $assigned = DB::fetchAll(Management::customerPackageSql() . ' ORDER BY cp.id DESC LIMIT 200'); ?>
    <section class="grid two-col"><div class="panel"><h2>Yeni paket</h2><form method="post" class="form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_package"><label>Ad<input name="name" required></label><label>Hak sayısı<input type="number" name="total_credits" value="10" min="1"></label><label>Geçerlilik (gün)<input type="number" name="validity_days" value="30" min="1"></label><label>Fiyat<input type="number" step="0.01" name="price" value="0" min="0"></label><label>Açıklama<input name="description"></label><button class="btn btn-primary">Paket Ekle</button></form></div>
    <div class="panel"><h2>Danışana paket tanımla</h2><p class="muted">Paket bedeli danışanın kullanılabilir bakiyesinden rezerve edilir. Yetkili onayından sonra haklar otomatik açılır.</p><form method="post" class="form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="assign_package"><label>Danışan<select name="customer_id"><?php options($customers); ?></select></label><label>Paket<select name="package_id"><?php options($packages); ?></select></label><label>Başlangıç<input type="date" name="starts_at" value="<?= e(date('Y-m-d')) ?>"></label><button class="btn btn-primary">Paketi Onaya Gönder</button></form><p class="form-note">Bekleyen talebi Ödemeler ekranından takip edebilirsiniz.</p></div></section>
    <?php render_simple_table($packages, ['name' => 'Paket', 'total_credits' => 'Hak', 'validity_days' => 'Gün', 'price' => 'Fiyat']); ?>
    <section class="panel"><h2>Tanımlı paketler ve hak düzeltme</h2><div class="table-wrap"><table><thead><tr><th>Danışan</th><th>Paket</th><th>Hak</th><th>Geçerlilik</th><th>Ödeme</th><?php if (can($actor, 'credits.adjust')): ?><th>Düzeltme</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($assigned as $item): ?><tr><td><?= e($item['customer_name']) ?></td><td><?= e($item['package_name']) ?></td><td><strong><?= e($item['credits_remaining']) ?></strong> / <?= e($item['credits_total']) ?></td><td><?= e(date_only((string) $item['expires_at'])) ?></td><td><span class="badge <?= e($item['payment_status']) ?>"><?= e(payment_label((string) $item['payment_status'])) ?></span></td><?php if (can($actor, 'credits.adjust')): ?><td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="adjust_credit"><input type="hidden" name="customer_package_id" value="<?= e($item['id']) ?>"><input type="number" name="amount" placeholder="+/- hak" required><input name="note" placeholder="Düzeltme nedeni" required><button class="btn btn-small">Uygula</button></form></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="panel"><h2>Son hak hareketleri</h2><?php $moves = DB::fetchAll('SELECT ct.*, u.name AS customer_name, a.name AS actor_name FROM credit_transactions ct INNER JOIN users u ON u.id = ct.customer_id LEFT JOIN users a ON a.id = ct.created_by ORDER BY ct.id DESC LIMIT 100'); render_bare_table($moves, ['created_at' => 'Tarih', 'customer_name' => 'Danışan', 'amount' => 'Hareket', 'balance_after' => 'Yeni bakiye', 'reason' => 'Sebep', 'note' => 'Not', 'actor_name' => 'İşlemi yapan']); ?></section>
    <?php
}

function render_reservations(array $user, string $path): void
{
    $filters = $path === '/customer/book' ? ['from' => date('Y-m-d H:i:s')] : [];
    $reservations = ReservationService::list($user, $filters);
    if ($user['role'] === 'customer') $reservations = array_slice($reservations, 0, 10);
    if ($path === '/admin/reservations' && can($user, 'reservations.reschedule_approve')) render_change_request_queue($user);
    if ($path === '/customer/book') render_booking_matrix();
    if ($path !== '/customer/reservations'): ?><section class="panel"><div class="section-heading"><div><p class="eyebrow">Birebir ve grup seansları</p><h2>Yeni rezervasyon</h2></div></div><?php render_reservation_form($user); ?></section><?php endif;
    render_reservation_table($user, $reservations);
}

function render_booking_matrix(): void
{
    $matrix = ScheduleService::bookingMatrix(date('Y-m-d'), date('Y-m-d', strtotime('+6 days'))); ?>
    <section class="panel booking-matrix-panel"><div class="section-heading"><div><p class="eyebrow">Bugünden itibaren 7 gün</p><h2>Fizyoterapist çalışma takvimi</h2><p class="muted">Yeşil çalışma saatine tıklayarak fizyoterapist ve tarihi rezervasyon formuna aktarabilirsiniz.</p></div></div>
    <?php if ($matrix['consultants']): ?><div class="booking-matrix-wrap"><table class="booking-matrix"><thead><tr><th>Gün / Tarih</th><?php foreach ($matrix['consultants'] as $consultant): ?><th><?= e($consultant['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($matrix['days'] as $day): $dayDate = new DateTimeImmutable((string) $day['work_date']); ?><tr><th><strong><?= e(date_only((string) $day['work_date'])) ?></strong><small><?= e(weekdays()[(int) $dayDate->format('N')]) ?></small></th><?php foreach ($matrix['consultants'] as $consultant): $availability = $day['consultants'][(int) $consultant['id']]; $isAvailable = (int) $availability['is_working'] === 1 && $availability['slots']; ?><td>
        <?php if ($isAvailable): ?><div class="booking-slot-list"><?php foreach ($availability['slots'] as $slot): ?><button type="button" class="booking-slot-cell" data-book-consultant="<?= e($consultant['id']) ?>" data-book-date="<?= e($day['work_date']) ?>" data-book-time="<?= e(substr((string) $slot['start_time'], 0, 5)) ?>"><strong><?= e(substr((string) $slot['start_time'], 0, 5) . '–' . substr((string) $slot['end_time'], 0, 5)) ?></strong><small>Seç</small></button><?php endforeach; ?></div>
        <?php else: ?><span class="booking-slot-cell is-closed">Müsait değil</span><?php endif; ?>
    </td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">Aktif fizyoterapist bulunamadı.</p><?php endif; ?></section><?php
}

function render_reservation_form(array $user): void
{
    $services = DB::fetchAll('SELECT * FROM services WHERE active = 1 ORDER BY name'); $physios = consultants(); $customerRows = customers();
    $ownPackages = $user['role'] === 'customer' ? DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? AND cp.status = "active" ORDER BY cp.expires_at', [$user['id']]) : []; $manageAll = can($user, 'reservations.manage_all'); ?>
    <form method="post" class="form-grid" data-reservation-form data-reservation-timing><?= csrf_field() ?><input type="hidden" name="action" value="create_reservation">
    <?php if ($manageAll): ?><label>Danışan<select name="customer_id"><?php options($customerRows); ?></select></label><label>Fizyoterapist<select name="consultant_id"><?php options($physios); ?></select></label><?php elseif ($user['role'] === 'consultant'): ?><label>Danışan<select name="customer_id"><?php options($customerRows); ?></select></label><?php else: ?><label>Fizyoterapist<select name="consultant_id"><?php options($physios); ?></select></label><?php endif; ?>
    <label>Hizmet<select name="service_id" data-reservation-service><?php foreach ($services as $service): ?><option value="<?= e($service['id']) ?>" data-duration="<?= e($service['duration_minutes']) ?>"><?= e($service['name'] . ' · ' . $service['duration_minutes'] . ' dk') ?></option><?php endforeach; ?></select></label><label>Rezervasyon tipi<select name="reservation_type" data-toggle-package><option value="package">Paket hakkı</option><option value="single">Tek seans</option></select></label>
    <?php if ($user['role'] === 'customer'): ?><label data-package-field>Paket<select name="customer_package_id"><option value="">Süresi en yakın paket</option><?php options($ownPackages, 'package_name'); ?></select></label><?php endif; ?>
    <label>Başlangıç<input type="datetime-local" name="starts_at" data-reservation-start required></label><label>Bitiş <small>(seans süresine göre otomatik)</small><input type="text" data-reservation-end readonly placeholder="Başlangıç ve hizmet seçin"></label><label>Not<input name="notes" placeholder="Seansla ilgili kısa not"></label><button class="btn btn-primary">Rezervasyon Oluştur</button></form><p class="form-note">Bitiş saati seçilen hizmetin süresine göre otomatik hesaplanır. Ödeme durumu yalnızca kayıtlı ödeme işlemiyle güncellenir.</p>
    <?php
}

function render_reservation_table(array $user, array $reservations): void
{
    if ($user['role'] === 'customer') {
        render_customer_reservation_cards($user, array_slice($reservations, 0, 10));
        return;
    }
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($reservations) ?> kayıt</p><h2>Rezervasyonlar</h2></div></div><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Hizmet</th><th>Danışan</th><th>Fizyoterapist</th><th>Durum</th><th>Ödeme</th><th>İşlem</th></tr></thead><tbody>
    <?php foreach ($reservations as $item): ?><tr><td><strong><?= e(reservation_range((string) $item['starts_at'], (string) $item['ends_at'])) ?></strong><small><?= e($item['reservation_type'] === 'single' ? 'Tek seans' : 'Paket') ?></small></td><td><?= e($item['service_name']) ?><small><?= e($item['duration_minutes']) ?> dk</small></td><td><?= e($item['customer_name']) ?></td><td><?= e($item['consultant_name']) ?></td><td><span class="badge <?= e($item['status']) ?>"><?= e(status_label((string) $item['status'])) ?></span></td><td><span class="badge <?= e($item['payment_status']) ?>"><?= e(payment_label((string) $item['payment_status'])) ?></span></td><td class="actions">
    <?php if (in_array($item['status'], ['pending', 'confirmed'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><button class="btn btn-small btn-danger">İptal</button></form><details class="action-pop"><summary class="btn btn-small">Taşı</summary><form method="post" class="popover" data-reservation-timing data-fixed-duration="<?= e($item['duration_minutes']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reschedule_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><label>Yeni başlangıç<input type="datetime-local" name="starts_at" data-reservation-start required></label><label>Yeni bitiş<input type="text" data-reservation-end readonly></label><button class="btn btn-small">Kaydet</button></form></details><?php endif; ?>
    <?php if ((can($user, 'reservations.manage_all') || ($user['role'] === 'consultant' && can($user, 'reservations.manage_own'))) && in_array($item['status'], ['pending', 'confirmed'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_reservation_status"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><input type="hidden" name="status" value="completed"><button class="btn btn-small btn-success">Geldi</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_reservation_status"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><input type="hidden" name="status" value="no_show"><button class="btn btn-small">Gelmedi</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$reservations): ?><tr><td colspan="7" class="empty">Henüz rezervasyon yok.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

function render_customer_reservation_cards(array $user, array $reservations): void
{
    ?><section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($reservations) ?> kayıt · En fazla 10</p><h2>Rezervasyonlarım</h2><p class="muted">Yalnızca değişiklik süresi geçmemiş aktif rezervasyonlar için yeni tarih talebi gönderebilirsiniz.</p></div></div><div class="customer-reservation-list">
    <?php foreach ($reservations as $item): $active = in_array($item['status'], ['pending', 'confirmed'], true); $canRequestChange = ReservationService::customerCanRequestChange($item); ?><article class="customer-reservation-card"><header><div><strong><?= e(reservation_range((string) $item['starts_at'], (string) $item['ends_at'])) ?></strong><small><?= e($item['service_name']) ?> · <?= e($item['duration_minutes']) ?> dk · <?= e($item['reservation_type'] === 'single' ? 'Tek seans' : 'Paket') ?></small></div><span class="badge <?= e($item['status']) ?>"><?= e(status_label((string) $item['status'])) ?></span></header><div class="customer-reservation-meta"><span><small>Fizyoterapist</small><strong><?= e($item['consultant_name']) ?></strong></span><span><small>Ödeme</small><span class="badge <?= e($item['payment_status']) ?>"><?= e(payment_label((string) $item['payment_status'])) ?></span></span></div>
        <?php if (!empty($item['pending_change_id'])): ?><div class="change-request-pending"><strong>Değişiklik onayı bekleniyor</strong><span>İstenen saat: <?= e(reservation_range_from_duration((string) $item['pending_requested_starts_at'], (int) $item['duration_minutes'])) ?></span></div><?php endif; ?>
        <?php if ($active): ?><div class="customer-reservation-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><button class="btn btn-small btn-danger" data-confirm="Bu rezervasyon iptal edilsin mi?">İptal</button></form><?php if ($canRequestChange): ?><details><summary class="btn btn-small">Tarih Değişikliği İste</summary><form method="post" class="customer-change-form" data-reservation-timing data-fixed-duration="<?= e($item['duration_minutes']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="reschedule_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><label>Yeni başlangıç<input type="datetime-local" name="starts_at" data-reservation-start required></label><label>Yeni bitiş <small>(otomatik)</small><input type="text" data-reservation-end readonly></label><button class="btn btn-primary btn-small">Onaya Gönder</button></form></details><?php endif; ?></div><?php endif; ?>
    </article><?php endforeach; ?><?php if (!$reservations): ?><p class="empty">Henüz rezervasyon yok.</p><?php endif; ?></div></section><?php
}

function render_change_request_queue(array $user): void
{
    $requests = ReservationService::pendingChangeRequests($user);
    if (!$requests) return; ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($requests) ?> onay bekliyor</p><h2>Tarih değişikliği talepleri</h2><p class="muted">Onay anında çalışma programı ve randevu çakışmaları yeniden kontrol edilir.</p></div></div><div class="change-request-list"><?php foreach ($requests as $request): ?><article class="change-request-card"><div><strong><?= e($request['customer_name']) ?></strong><small><?= e($request['consultant_name'] . ' · ' . $request['service_name'] . ' · ' . $request['duration_minutes'] . ' dk') ?></small></div><div class="change-request-dates"><span><small>Mevcut</small><strong><?= e(reservation_range_from_duration((string) $request['current_starts_at'], (int) $request['duration_minutes'])) ?></strong></span><span class="change-arrow">→</span><span><small>İstenen</small><strong><?= e(reservation_range_from_duration((string) $request['requested_starts_at'], (int) $request['duration_minutes'])) ?></strong></span></div><form method="post" class="change-review-form"><?= csrf_field() ?><input type="hidden" name="action" value="review_reschedule_request"><input type="hidden" name="request_id" value="<?= e($request['id']) ?>"><input name="review_note" placeholder="Onay / ret notu (isteğe bağlı)"><button class="btn btn-small btn-success" name="decision" value="approved">Onayla</button><button class="btn btn-small btn-danger" name="decision" value="rejected">Reddet</button></form></article><?php endforeach; ?></div></section><?php
}

function render_availability(array $user): void
{
    $summaries = ScheduleService::calendarSummaries($user);
    render_admin_scheduler($user);
    if (can($user, 'reservations.reschedule_approve')) render_change_request_queue($user);
    $manageAll = can($user, 'schedules.manage_all');
    $selectedConsultantId = (int) ($_GET['consultant_id'] ?? ($user['role'] === 'consultant' ? $user['id'] : 0));
    $from = (string) ($_GET['from'] ?? date('Y-m-d'));
    $to = (string) ($_GET['to'] ?? date('Y-m-d', strtotime('+30 days')));
    ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Tarih bazlı müsaitlik</p><h2>Fizyoterapist seçin</h2><p class="muted">Takvimini görmek veya değiştirmek istediğiniz fizyoterapiste tıklayın.</p></div></div>
        <div class="physio-picker"><?php foreach ($summaries as $item): ?><a class="physio-option <?= (int) $item['consultant_id'] === $selectedConsultantId ? 'active' : '' ?>" href="<?= e(url_for('/admin/availability') . '?consultant_id=' . (int) $item['consultant_id'] . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to)) ?>"><span class="physio-dot"></span><span><strong><?= e($item['consultant_name']) ?></strong><small><?= e($item['working_days']) ?> açık tarih · Takvimi görüntüle</small></span></a><?php endforeach; ?><?php if (!$summaries): ?><p class="empty">Aktif fizyoterapist yok.</p><?php endif; ?></div>
    </section>
    <?php if ($selectedConsultantId > 0):
        $days = ScheduleService::calendarDays($user, $selectedConsultantId, $from, $to);
        $selectedName = '-';
        foreach ($summaries as $summary) if ((int) $summary['consultant_id'] === $selectedConsultantId) $selectedName = (string) $summary['consultant_name']; ?>
        <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= e($selectedName) ?></p><h2>Rezervasyon müsaitliği</h2><p class="muted">Bir tarihin “Düzenle” alanını açarak çalışma durumunu ve saatlerini değiştirebilirsiniz.</p></div></div>
        <form method="get" class="form-grid calendar-filter" action="<?= e(url_for('/admin/availability')) ?>"><input type="hidden" name="consultant_id" value="<?= e($selectedConsultantId) ?>"><label>Başlangıç<input type="date" name="from" value="<?= e($from) ?>" required></label><label>Bitiş<input type="date" name="to" value="<?= e($to) ?>" required></label><button class="btn">Tarihleri Göster</button></form>
        <div class="schedule-grid"><div class="schedule-grid-head"><span>Tarih</span><span>Gün</span><span>Çalışma saatleri</span><span>Durum</span><span></span></div><?php foreach ($days as $day):
            $morning = $afternoon = $custom = null;
            $slotLabels = [];
            foreach ($day['slots'] as $slot) {
                if ($slot['period'] === 'morning') $morning = $slot;
                elseif ($slot['period'] === 'afternoon') $afternoon = $slot;
                elseif ($slot['period'] === 'custom') $custom = $slot;
                $slotLabels[] = substr((string) $slot['start_time'], 0, 5) . '–' . substr((string) $slot['end_time'], 0, 5);
            }
            $isWorking = (int) $day['is_working'] === 1;
            $dayDate = new DateTimeImmutable((string) $day['work_date']); ?>
            <details class="schedule-item <?= $isWorking ? 'is-working' : 'is-closed' ?>"><summary class="schedule-summary"><strong><?= e(date_only((string) $day['work_date'])) ?></strong><span><?= e(weekdays()[(int) $dayDate->format('N')]) ?></span><span class="schedule-hours"><?= e($isWorking && $slotLabels ? implode(' · ', $slotLabels) : 'Müsaitlik kapalı') ?></span><span><span class="badge <?= $isWorking ? 'active' : 'passive' ?>"><?= $isWorking ? 'Müsait' : 'Kapalı' ?></span></span><span class="schedule-action">Düzenle</span></summary>
                <form method="post" class="schedule-edit-form"><?= csrf_field() ?><input type="hidden" name="consultant_id" value="<?= e($selectedConsultantId) ?>"><input type="hidden" name="work_date" value="<?= e($day['work_date']) ?>"><input type="hidden" name="is_working" value="0">
                    <label class="schedule-working-toggle"><input type="checkbox" name="is_working" value="1" <?= $isWorking ? 'checked' : '' ?>><span><strong>Bu tarihte çalışıyor</strong><small>Kapalı bırakmak için işareti kaldırın.</small></span></label>
                    <div class="schedule-period"><label class="check-label"><input type="checkbox" name="morning" value="1" <?= ($morning || !$isWorking) ? 'checked' : '' ?>><span>Sabah</span></label><label>Başlangıç<input type="time" name="morning_start" value="<?= e($morning ? substr((string) $morning['start_time'], 0, 5) : '08:00') ?>"></label><label>Bitiş<input type="time" name="morning_end" value="<?= e($morning ? substr((string) $morning['end_time'], 0, 5) : '12:00') ?>"></label></div>
                    <div class="schedule-period"><label class="check-label"><input type="checkbox" name="afternoon" value="1" <?= ($afternoon || !$isWorking) ? 'checked' : '' ?>><span>Öğleden sonra</span></label><label>Başlangıç<input type="time" name="afternoon_start" value="<?= e($afternoon ? substr((string) $afternoon['start_time'], 0, 5) : '13:00') ?>"></label><label>Bitiş<input type="time" name="afternoon_end" value="<?= e($afternoon ? substr((string) $afternoon['end_time'], 0, 5) : '18:00') ?>"></label></div>
                    <?php if ($custom): ?><input type="hidden" name="custom_start" value="<?= e(substr((string) $custom['start_time'], 0, 5)) ?>"><input type="hidden" name="custom_end" value="<?= e(substr((string) $custom['end_time'], 0, 5)) ?>"><?php endif; ?>
                    <div class="schedule-form-actions"><button class="btn btn-primary" name="action" value="save_date_availability">Müsaitliği Kaydet</button><button class="btn btn-danger" name="action" value="clear_date_availability" formnovalidate data-confirm="Bu tarihin çalışma programı silinsin mi?">Çalışma Programını Sil</button></div>
                </form>
            </details><?php endforeach; ?><?php if (!$days): ?><p class="empty">Bu tarih aralığında kayıt yok.</p><?php endif; ?></div></section>
    <?php elseif ($manageAll): ?><section class="panel empty-state"><h2>Düzenlenecek fizyoterapisti seçin</h2><p>Yukarıdaki listede fizyoterapistin yanındaki Düzenle düğmesine basın.</p></section><?php endif;
}

function render_admin_scheduler(array $user): void
{
    $canSeeReservations = can($user, 'reservations.view_all') || ($user['role'] === 'consultant' && can($user, 'reservations.manage_own'));
    if (!$canSeeReservations) return;

    $from = date('Y-m-d');
    $to = date('Y-m-d', strtotime('+6 days'));
    $onlyConsultants = $user['role'] === 'consultant' && !can($user, 'reservations.view_all') ? [(int) $user['id']] : null;
    $matrix = ScheduleService::bookingMatrix($from, $to, $onlyConsultants);
    $reservationMap = [];
    foreach (ReservationService::scheduleItems($user, $from, $to) as $reservation) {
        $date = substr((string) $reservation['starts_at'], 0, 10);
        $reservationMap[$date][(int) $reservation['consultant_id']][] = $reservation;
    }
    $canManage = can($user, 'reservations.manage_all') || ($user['role'] === 'consultant' && can($user, 'reservations.manage_own')); ?>
    <section class="panel admin-scheduler-panel"><div class="section-heading"><div><p class="eyebrow">Bugünden itibaren 7 gün</p><h2>Seans planlama takvimi</h2><p class="muted">Çalışma saatine tıklayarak yeni randevu planlayın; renkli randevu kaydına tıklayarak tarihini değiştirin.</p></div></div>
    <?php if ($matrix['consultants']): ?><div class="booking-matrix-wrap"><table class="booking-matrix admin-booking-matrix"><thead><tr><th>Gün / Tarih</th><?php foreach ($matrix['consultants'] as $consultant): ?><th><?= e($consultant['name']) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($matrix['days'] as $day): $dayDate = new DateTimeImmutable((string) $day['work_date']); ?><tr><th><strong><?= e(date_only((string) $day['work_date'])) ?></strong><small><?= e(weekdays()[(int) $dayDate->format('N')]) ?></small></th><?php foreach ($matrix['consultants'] as $consultant): $consultantId = (int) $consultant['id']; $availability = $day['consultants'][$consultantId]; $appointments = $reservationMap[$day['work_date']][$consultantId] ?? []; ?><td><div class="scheduler-cell">
        <?php foreach ($appointments as $appointment): $appointmentLabel = substr((string) $appointment['starts_at'], 11, 5) . '–' . substr((string) $appointment['ends_at'], 11, 5); if ($canManage): ?><button type="button" class="scheduler-appointment" data-edit-reservation="<?= e($appointment['id']) ?>" data-edit-start="<?= e(str_replace(' ', 'T', substr((string) $appointment['starts_at'], 0, 16))) ?>" data-edit-duration="<?= e($appointment['duration_minutes']) ?>" data-edit-label="<?= e($appointment['customer_name'] . ' · ' . $appointmentLabel) ?>"><strong><?= e($appointmentLabel . ' · ' . $appointment['customer_name']) ?></strong><small><?= e($appointment['service_name'] . ' · ' . $appointment['duration_minutes'] . ' dk') ?> · Düzenle</small></button><?php else: ?><span class="scheduler-appointment is-readonly"><strong><?= e($appointmentLabel . ' · ' . $appointment['customer_name']) ?></strong><small><?= e($appointment['service_name'] . ' · ' . $appointment['duration_minutes'] . ' dk') ?></small></span><?php endif; endforeach; ?>
        <?php if ((int) $availability['is_working'] === 1 && $availability['slots']): ?><div class="scheduler-work-slots"><?php foreach ($availability['slots'] as $slot): $slotLabel = substr((string) $slot['start_time'], 0, 5) . '–' . substr((string) $slot['end_time'], 0, 5); if ($canManage): ?><button type="button" class="booking-slot-cell" data-book-consultant="<?= e($consultantId) ?>" data-book-date="<?= e($day['work_date']) ?>" data-book-time="<?= e(substr((string) $slot['start_time'], 0, 5)) ?>"><strong><?= e($slotLabel) ?></strong><small>Randevu planla</small></button><?php else: ?><span class="booking-slot-cell"><strong><?= e($slotLabel) ?></strong></span><?php endif; endforeach; ?></div><?php else: ?><span class="booking-slot-cell is-closed">Müsait değil</span><?php endif; ?>
    </div></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="empty">Aktif fizyoterapist bulunamadı.</p><?php endif; ?></section>
    <?php if ($canManage): ?><section class="grid two-col scheduler-editor-grid"><div class="panel" id="scheduler-new-reservation"><p class="eyebrow">Yeni planlama</p><h2>Randevu oluştur</h2><?php render_reservation_form($user); ?></div><div class="panel scheduler-edit-panel"><p class="eyebrow">Mevcut planlama</p><h2>Randevu tarihini değiştir</h2><p class="muted" data-scheduler-edit-empty>Takvimdeki renkli bir randevuya tıklayın.</p><form method="post" class="form-grid single" data-scheduler-edit-form data-reservation-timing hidden><?= csrf_field() ?><input type="hidden" name="action" value="reschedule_reservation"><input type="hidden" name="reservation_id"><p class="notice" data-scheduler-edit-label></p><label>Yeni başlangıç<input type="datetime-local" name="starts_at" data-reservation-start required></label><label>Yeni bitiş <small>(otomatik)</small><input type="text" data-reservation-end readonly></label><button class="btn btn-primary">Randevuyu Güncelle</button></form></div></section><?php endif;
}

function render_time_off(array $user): void
{
    $items = ScheduleService::timeOff($user); $manageAll = can($user, 'time_off.manage_all'); ?>
    <section class="panel"><h2>İzin / mola ekle</h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_time_off"><?php if ($manageAll): ?><label>Fizyoterapist<select name="consultant_id"><?php options(consultants()); ?></select></label><?php endif; ?><label>Başlangıç<input type="datetime-local" name="start_at" required></label><label>Bitiş<input type="datetime-local" name="end_at" required></label><label>Neden<input name="reason"></label><label class="check-label"><input type="checkbox" name="confirm_conflicts" value="1"><span>Aktif randevu çakışması varsa kontrol ettim</span></label><button class="btn btn-primary">İzin Ekle</button></form></section>
    <section class="panel"><h2>İzin kayıtları</h2><div class="table-wrap"><table><thead><tr><th>Fizyoterapist</th><th>Başlangıç</th><th>Bitiş</th><th>Neden</th><th></th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e($item['consultant_name']) ?></td><td><?= e(dt((string) $item['start_at'])) ?></td><td><?= e(dt((string) $item['end_at'])) ?></td><td><?= e($item['reason']) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_time_off"><input type="hidden" name="time_off_id" value="<?= e($item['id']) ?>"><button class="btn btn-small btn-danger">Sil</button></form></td></tr><?php endforeach; ?></tbody></table></div></section><?php
}

function render_financial_page(array $user): void
{
    $isCustomer = $user['role'] === 'customer';
    $status = $isCustomer ? null : trim((string) ($_GET['status'] ?? ''));
    $view = $isCustomer ? null : trim((string) ($_GET['view'] ?? ''));
    $status = $status === '' ? null : $status;
    $view = $view === '' ? null : $view;
    $canView = $isCustomer || can($user, 'payments.view_all') || can($user, 'payments.approve') || can($user, 'payments.refund');
    $transactions = $canView ? PaymentService::list($user, $status, $view) : [];

    if ($isCustomer) {
        render_customer_wallet($user);
    } else {
        render_admin_financial_forms($user);
    }

    if ($canView) {
        render_financial_transactions($user, $transactions, $status, $view);
    }
}

function render_customer_wallet(array $user): void
{
    $customerId = (int) $user['id'];
    $balance = WalletService::balance($customerId);
    $reserved = WalletService::reservedBalance($customerId);
    $available = WalletService::availableBalance($customerId);
    $packages = DB::fetchAll('SELECT id, name, description, total_credits, validity_days, price FROM packages WHERE active = 1 ORDER BY price, name');
    $singleReservations = DB::fetchAll(
        'SELECT r.id, r.starts_at, r.price, r.payment_status, s.name AS service_name
         FROM reservations r
         INNER JOIN services s ON s.id = r.service_id
         WHERE r.customer_id = ? AND r.reservation_type = "single" AND r.payment_status != "paid"
           AND r.status IN ("pending", "confirmed") AND r.starts_at >= NOW()
         ORDER BY r.starts_at ASC LIMIT 20',
        [$customerId]
    ); ?>
    <section class="wallet-hero financial-wallet"><div><p class="eyebrow">FizyoRez bakiyesi</p><h2><?= money($available) ?></h2><p>Paket ve tek seans satın alımlarında kullanılabilir bakiye.</p></div><div class="wallet-breakdown"><span><small>Toplam</small><strong><?= money($balance) ?></strong></span><span><small>Onayda rezerve</small><strong><?= money($reserved) ?></strong></span></div></section>
    <section class="grid two-col"><form method="post" class="panel card-payment form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="simulate_wallet_topup"><p class="eyebrow">Beta ödeme</p><h2>Kartla bakiye yükle</h2><label>Kart üzerindeki ad<input name="cardholder" autocomplete="cc-name" required></label><label>Kart numarası<input name="card_number" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" data-card-number required></label><div class="form-grid compact"><label>Son kullanma<input name="expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="AA/YY" data-card-expiry required></label><label>CVV<input type="password" name="cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4" required></label></div><label>Yüklenecek tutar<input type="number" name="amount" min="1" max="100000" step="0.01" value="1000" required></label><button class="btn btn-primary">Bakiye Yüklemeyi Onaya Gönder</button><p class="form-note">Kart doğrulanırsa işlem yetkili onayına düşer. Tam kart numarası ve CVV kaydedilmez.</p></form>
    <section class="panel"><p class="eyebrow">Bakiye sistemi</p><h2>Nasıl çalışır?</h2><div class="flow-steps"><span><b>1</b>Kartla veya işletme üzerinden bakiye yükleyin.</span><span><b>2</b>Yetkili onayından sonra bakiye kullanılabilir olur.</span><span><b>3</b>Paket ya da tek seans seçin; tutar onay süresince rezerve edilir.</span></div></section></section>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Paket ve haklar</p><h2>Bakiyeyle paket satın al</h2><p class="muted">Satın alma talebi onaylanınca haklarınız otomatik tanımlanır.</p></div></div><div class="purchase-grid"><?php foreach ($packages as $package): ?><article class="purchase-card"><div><h3><?= e($package['name']) ?></h3><p><?= e($package['description'] ?: 'Fizyoterapi seans paketi') ?></p><small><?= e($package['total_credits']) ?> hak · <?= e($package['validity_days']) ?> gün</small></div><footer><strong><?= money($package['price']) ?></strong><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_with_wallet"><input type="hidden" name="target_type" value="package"><input type="hidden" name="target_id" value="<?= e($package['id']) ?>"><button class="btn btn-small btn-primary" <?= $available < (float) $package['price'] ? 'disabled' : '' ?>>Bakiyeyle Al</button></form></footer></article><?php endforeach; ?><?php if (!$packages): ?><p class="empty">Satışta aktif paket yok.</p><?php endif; ?></div></section>
    <?php if ($singleReservations): ?><section class="panel"><div class="section-heading"><div><p class="eyebrow">Tek seanslar</p><h2>Bekleyen seans ödemeleri</h2></div></div><div class="purchase-grid"><?php foreach ($singleReservations as $reservation): ?><article class="purchase-card"><div><h3><?= e($reservation['service_name']) ?></h3><p><?= e(dt((string) $reservation['starts_at'])) ?></p><span class="badge <?= e($reservation['payment_status']) ?>"><?= e(payment_label((string) $reservation['payment_status'])) ?></span></div><footer><strong><?= money($reservation['price']) ?></strong><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="purchase_with_wallet"><input type="hidden" name="target_type" value="reservation"><input type="hidden" name="target_id" value="<?= e($reservation['id']) ?>"><button class="btn btn-small btn-primary" <?= $available < (float) $reservation['price'] || $reservation['payment_status'] === 'awaiting_approval' ? 'disabled' : '' ?>>Bakiyeyle Öde</button></form></footer></article><?php endforeach; ?></div></section><?php endif;
}

function render_admin_financial_forms(array $user): void
{
    $balances = WalletService::balancesForCustomers();
    $canCreate = can($user, 'payments.create');
    $canAdjust = can($user, 'wallets.adjust');
    if (!$canCreate && !$canAdjust) return; ?>
    <section class="grid two-col">
    <?php if ($canCreate): ?><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_payment"><p class="eyebrow">Tahsilat kaydı</p><h2>Danışan bakiyesi yükle</h2><p class="muted">Nakit, banka veya POS tahsilatı onaylandıktan sonra danışan bakiyesine eklenir.</p><label>Danışan<select name="customer_id" required><?php foreach ($balances as $customer): ?><option value="<?= e($customer['id']) ?>"><?= e($customer['name']) ?> · kullanılabilir <?= money($customer['available_balance']) ?></option><?php endforeach; ?></select></label><label>Tutar<input type="number" name="amount" min="0.01" step="0.01" required></label><label>Yöntem<select name="method"><?php foreach (PaymentService::STAFF_METHODS as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Referans no<input name="reference_no" placeholder="Dekont / POS / kasa no"></label><label>Açıklama<textarea name="note" placeholder="Tahsilat açıklaması" required></textarea></label><button class="btn btn-primary" <?= !$balances ? 'disabled' : '' ?>>Bakiye Yüklemeyi Onaya Gönder</button></form><?php endif; ?>
    <?php if ($canAdjust): ?><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="adjust_wallet"><p class="eyebrow">Gerekçeli düzeltme</p><h2>Bakiye ekle veya düş</h2><p class="muted">Düzeltme onaylanmadan bakiyeyi değiştirmez. Eksi tutar düşüm oluşturur.</p><label>Danışan<select name="customer_id" required><?php foreach ($balances as $customer): ?><option value="<?= e($customer['id']) ?>"><?= e($customer['name']) ?> · kullanılabilir <?= money($customer['available_balance']) ?></option><?php endforeach; ?></select></label><label>Düzeltme tutarı<input type="number" name="amount" step="0.01" placeholder="Örn. 250 veya -100" required></label><label>Düzeltme nedeni<textarea name="note" placeholder="Bakiye neden değiştiriliyor?" required></textarea></label><button class="btn btn-primary" <?= !$balances ? 'disabled' : '' ?>>Düzeltmeyi Onaya Gönder</button></form><?php endif; ?>
    </section><?php
}

function render_financial_transactions(array $user, array $transactions, ?string $status, ?string $view): void
{
    $isCustomer = $user['role'] === 'customer'; ?>
    <section class="panel financial-ledger"><div class="section-heading"><div><p class="eyebrow"><?= count($transactions) ?> hareket</p><h2><?= $isCustomer ? 'Bakiye ve ödeme hareketlerim' : 'Tüm finans hareketleri' ?></h2><p class="muted">Yükleme, paket, tek seans, kesinti, düzeltme ve iadeler tek yerde tutulur.</p></div></div>
    <?php if (!$isCustomer): ?><nav class="status-tabs financial-tabs"><?php foreach ([['', '', 'Tümü'], ['awaiting_approval', '', 'Onay Bekleyen'], ['approved', '', 'Onaylanan'], ['rejected', '', 'Reddedilen'], ['', 'deductions', 'Kesintiler'], ['', 'adjustments', 'Düzeltmeler'], ['', 'refunds', 'İadeler']] as [$tabStatus, $tabView, $label]): $query = http_build_query(array_filter(['status' => $tabStatus, 'view' => $tabView], static fn (string $value): bool => $value !== '')); $active = (string) $status === $tabStatus && (string) $view === $tabView; ?><a class="<?= $active ? 'active' : '' ?>" href="<?= e(url_for('/admin/payments') . ($query ? '?' . $query : '')) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <div class="table-wrap"><table><thead><tr><th>Tarih / No</th><?php if (!$isCustomer): ?><th>Danışan</th><?php endif; ?><th>Hareket</th><th>Tutar</th><th>Yöntem / Kart</th><th>Bakiye</th><th>Açıklama</th><?php if (!$isCustomer): ?><th>Oluşturan / Onaylayan</th><th>Durum</th><th></th><?php else: ?><th>Durum</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($transactions as $item): $signed = $item['direction'] === 'debit' ? -(float) $item['amount'] : (float) $item['amount']; $canReviewOwn = $user['role'] === 'super_admin' || (int) $item['created_by'] !== (int) $user['id']; ?><tr><td><?= e(dt((string) $item['created_at'])) ?><small>#<?= e($item['id']) ?> · <?= e($item['reference_no'] ?: '-') ?></small></td><?php if (!$isCustomer): ?><td><strong><?= e($item['customer_name']) ?></strong></td><?php endif; ?><td><strong><?= e(PaymentService::TYPES[$item['transaction_type']] ?? $item['transaction_type']) ?></strong><small><?= e($item['target_name'] ?? '') ?></small></td><td><strong class="wallet-amount <?= $signed < 0 ? 'is-negative' : 'is-positive' ?>"><?= $signed > 0 ? '+' : '' ?><?= money($signed, (string) $item['currency']) ?></strong><?php if ((float) $item['reserved_amount'] > 0): ?><small><?= money($item['reserved_amount']) ?> rezerve</small><?php endif; ?></td><td><?= e(PaymentService::METHODS[$item['method']] ?? $item['method']) ?><?php if ($item['card_last_four']): ?><small><?= e($item['card_brand'] ?: 'Kart') ?> · •••• <?= e($item['card_last_four']) ?></small><?php endif; ?></td><td><?= $item['balance_before'] === null ? '-' : money($item['balance_before']) ?><?php if ($item['balance_after'] !== null): ?><small>→ <?= money($item['balance_after']) ?></small><?php endif; ?></td><td><?= e($item['note'] ?: '-') ?><?php if ($item['review_note']): ?><small>Karar: <?= e($item['review_note']) ?></small><?php endif; ?></td>
    <?php if (!$isCustomer): ?><td><?= e($item['created_by_name'] ?: '-') ?><small><?= e($item['reviewed_by_name'] ?: 'Onay bekliyor') ?></small></td><td><span class="badge <?= e($item['status']) ?>"><?= e(PaymentService::STATUSES[$item['status']] ?? $item['status']) ?></span></td><td class="actions"><?php if ($item['status'] === 'awaiting_approval' && can($user, 'payments.approve') && $canReviewOwn): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($status ?? '') ?>"><input type="hidden" name="return_view" value="<?= e($view ?? '') ?>"><button class="btn btn-small btn-success">Onayla</button></form><details class="action-pop"><summary class="btn btn-small btn-danger">Reddet</summary><form method="post" class="popover"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($status ?? '') ?>"><input type="hidden" name="return_view" value="<?= e($view ?? '') ?>"><input name="note" placeholder="Reddetme nedeni" required><button class="btn btn-small btn-danger">Reddet</button></form></details><?php endif; ?><?php if ($item['status'] === 'approved' && can($user, 'payments.refund')): ?><details class="action-pop"><summary class="btn btn-small">Ters İşlem</summary><form method="post" class="popover"><?= csrf_field() ?><input type="hidden" name="action" value="refund_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($status ?? '') ?>"><input type="hidden" name="return_view" value="<?= e($view ?? '') ?>"><input name="note" placeholder="İade / ters işlem nedeni" required><button class="btn btn-small btn-danger">Onaya Gönder</button></form></details><?php endif; ?></td><?php else: ?><td><span class="badge <?= e($item['status']) ?>"><?= e(PaymentService::STATUSES[$item['status']] ?? $item['status']) ?></span></td><?php endif; ?></tr><?php endforeach; ?>
    <?php if (!$transactions): ?><tr><td colspan="<?= $isCustomer ? 7 : 10 ?>" class="empty">Bu bölümde finans hareketi yok.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

function render_payments(array $user): void
{
    $statusFilter = $user['role'] === 'customer' ? null : trim((string) ($_GET['status'] ?? ''));
    if ($statusFilter === '') $statusFilter = null;
    $canViewPayments = $user['role'] === 'customer' || can($user, 'payments.view_all');
    $payments = $canViewPayments ? PaymentService::list($user, $statusFilter) : [];
    if ($user['role'] === 'customer') {
        $balance = WalletService::balance((int) $user['id']);
        $walletTransactions = WalletService::transactions($user); ?>
        <section class="wallet-hero"><div><p class="eyebrow">Test cüzdanı</p><h2><?= money($balance) ?></h2><p>Bu bakiye yalnızca beta ödeme akışını denemek içindir; gerçek para hareketi oluşturmaz.</p></div><span class="badge awaiting_approval">TEST MODU</span></section>
        <section class="grid two-col"><form method="post" class="panel card-payment form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="simulate_wallet_topup"><p class="eyebrow">Test ödemesi</p><h2>Kartla bakiye yükle</h2><label>Kart üzerindeki ad<input name="cardholder" autocomplete="cc-name" required></label><label>Kart numarası<input name="card_number" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" data-card-number required></label><div class="form-grid compact"><label>Son kullanma<input name="expiry" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="AA/YY" data-card-expiry required></label><label>CVV<input type="password" name="cvv" inputmode="numeric" autocomplete="cc-csc" maxlength="4" required></label></div><label>Yüklenecek tutar<input type="number" name="amount" min="1" max="100000" step="0.01" value="1000" required></label><button class="btn btn-primary">Ödemeyi Onayla</button><p class="form-note">Kart bilgileri kaydedilmez. Bu işlem yalnızca test bakiyesi oluşturur.</p></form>
        <section class="panel"><p class="eyebrow">Bakiye hareketleri</p><h2>Test cüzdan geçmişi</h2><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Tutar</th><th>Kart</th><th>Referans</th><th>Durum</th><th>Son bakiye</th></tr></thead><tbody><?php foreach ($walletTransactions as $item): ?><tr><td><?= e(dt((string) $item['created_at'])) ?></td><td><?= money($item['amount']) ?></td><td>•••• <?= e($item['card_last_four'] ?: '----') ?></td><td><?= e($item['reference_no']) ?></td><td><span class="badge <?= $item['status'] === 'approved' ? 'paid' : 'cancelled' ?>"><?= $item['status'] === 'approved' ? 'Onaylandı' : 'Reddedildi' ?></span></td><td><?= money($item['balance_after']) ?></td></tr><?php endforeach; ?><?php if (!$walletTransactions): ?><tr><td colspan="6" class="empty">Henüz bakiye hareketi yok.</td></tr><?php endif; ?></tbody></table></div></section></section>
        <?php
    }
    if ($user['role'] !== 'customer' && can($user, 'wallets.adjust')) render_wallet_management($user);
    if ($user['role'] !== 'customer' && can($user, 'payments.create')) {
        $customerPackages = DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.payment_status != "paid" ORDER BY cp.id DESC');
        $singleReservations = DB::fetchAll('SELECT r.id, r.customer_id, r.price, c.name AS customer_name, s.name AS service_name FROM reservations r INNER JOIN users c ON c.id=r.customer_id INNER JOIN services s ON s.id=r.service_id WHERE r.reservation_type="single" AND r.payment_status != "paid" ORDER BY r.id DESC'); ?>
        <section class="grid two-col"><div class="panel"><h2>Paket ödemesi kaydet</h2><?php payment_beta_notice(); ?><form method="post" class="form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_payment"><input type="hidden" name="target_type" value="package"><label>Paket<select name="target_id" data-payment-target><?php if (!$customerPackages): ?><option value="">Ödeme bekleyen paket yok</option><?php endif; ?><?php foreach ($customerPackages as $item): ?><option value="<?= e($item['id']) ?>" data-customer="<?= e($item['customer_id']) ?>" data-amount="<?= e($item['package_price']) ?>"><?= e($item['customer_name'] . ' · ' . $item['package_name']) ?></option><?php endforeach; ?></select></label><label>Danışan <small>(paketten otomatik)</small><select name="customer_id" disabled><?php options(customers()); ?></select></label><label>Tutar<input type="number" name="amount" step="0.01" min="0.01" required></label><?php payment_method_fields(); ?><button class="btn btn-primary" <?= !$customerPackages ? 'disabled' : '' ?>>Ödeme Kaydı Oluştur</button></form></div>
        <div class="panel"><h2>Tek seans ödemesi kaydet</h2><form method="post" class="form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_payment"><input type="hidden" name="target_type" value="reservation"><label>Rezervasyon<select name="target_id" data-payment-target><?php if (!$singleReservations): ?><option value="">Ödeme bekleyen tek seans yok</option><?php endif; ?><?php foreach ($singleReservations as $item): ?><option value="<?= e($item['id']) ?>" data-customer="<?= e($item['customer_id']) ?>" data-amount="<?= e($item['price']) ?>"><?= e('#' . $item['id'] . ' · ' . $item['customer_name'] . ' · ' . $item['service_name']) ?></option><?php endforeach; ?></select></label><label>Danışan <small>(seanstan otomatik)</small><select name="customer_id" disabled><?php options(customers()); ?></select></label><label>Tutar<input type="number" name="amount" step="0.01" min="0.01" required></label><?php payment_method_fields(); ?><button class="btn btn-primary" <?= !$singleReservations ? 'disabled' : '' ?>>Ödeme Kaydı Oluştur</button></form></div></section><?php
    }
    if (!$canViewPayments) return;
    $canPaymentAction = can($user, 'payments.approve') || can($user, 'payments.refund'); ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow"><?= count($payments) ?> kayıt</p><h2><?= $user['role'] === 'customer' ? 'Ödeme geçmişim' : 'Ödeme hareketleri' ?></h2></div></div>
    <?php if ($user['role'] !== 'customer'): ?><nav class="status-tabs"><?php foreach (['' => 'Tümü', 'awaiting_approval' => 'Onay Bekliyor', 'pending' => 'Bekleyen', 'paid' => 'Ödendi', 'refunded' => 'İade', 'cancelled' => 'İptal'] as $value => $label): $href = url_for('/admin/payments') . ($value !== '' ? '?status=' . rawurlencode((string) $value) : ''); ?><a class="<?= (string) ($statusFilter ?? '') === (string) $value ? 'active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></nav><?php endif; ?>
    <div class="table-wrap"><table><thead><tr><th>Tarih</th><?php if ($user['role'] !== 'customer'): ?><th>Danışan</th><?php endif; ?><th>Tutar</th><th>Yöntem</th><th>Referans</th><th>Durum</th><?php if ($canPaymentAction): ?><th>İşlem</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($payments as $item): ?><tr><td><?= e(dt((string) $item['created_at'])) ?></td><?php if ($user['role'] !== 'customer'): ?><td><?= e($item['customer_name']) ?></td><?php endif; ?><td><strong><?= money($item['amount'], (string) $item['currency']) ?></strong></td><td><?= e(PaymentService::METHODS[$item['method']] ?? $item['method']) ?></td><td><?= e($item['reference_no'] ?: '-') ?></td><td><span class="badge <?= e($item['status']) ?>"><?= e(PaymentService::STATUSES[$item['status']] ?? $item['status']) ?></span></td>
    <?php if ($canPaymentAction): ?><td class="actions">
        <?php if (can($user, 'payments.approve') && in_array($item['status'], ['pending', 'awaiting_approval'], true)): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($statusFilter ?? '') ?>"><button class="btn btn-small btn-success">Onayla</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($statusFilter ?? '') ?>"><button class="btn btn-small btn-danger">İptal</button></form><?php endif; ?>
        <?php if ($item['status'] === 'paid' && can($user, 'payments.approve')): ?><details class="action-pop"><summary class="btn btn-small">Ödendiyi Geri Al</summary><form method="post" class="popover"><?= csrf_field() ?><input type="hidden" name="action" value="reopen_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($statusFilter ?? '') ?>"><input name="note" placeholder="Geri alma nedeni" required><button class="btn btn-small">Beklemeye Al</button></form></details><?php endif; ?>
        <?php if ($item['status'] === 'paid' && can($user, 'payments.refund')): ?><details class="action-pop"><summary class="btn btn-small">İade</summary><form method="post" class="popover"><?= csrf_field() ?><input type="hidden" name="action" value="refund_payment"><input type="hidden" name="payment_id" value="<?= e($item['id']) ?>"><input type="hidden" name="return_status" value="<?= e($statusFilter ?? '') ?>"><input name="note" placeholder="İade nedeni" required><button class="btn btn-small btn-danger">İade Et</button></form></details><?php endif; ?>
    </td><?php endif; ?></tr><?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="7" class="empty">Bu sekmede ödeme kaydı yok.</td></tr><?php endif; ?></tbody></table></div></section><?php
}

function render_wallet_management(array $user): void
{
    $transactions = WalletService::transactions($user);
    $balances = WalletService::balancesForCustomers(); ?>
    <section class="grid two-col"><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="adjust_wallet"><p class="eyebrow">Gerekçeli işlem</p><h2>Danışan bakiyesi düzelt</h2><label>Danışan<select name="customer_id"><?php if (!$balances): ?><option value="">Aktif danışan yok</option><?php endif; ?><?php foreach ($balances as $customer): ?><option value="<?= e($customer['id']) ?>"><?= e($customer['name']) ?> · <?= money($customer['wallet_balance']) ?></option><?php endforeach; ?></select></label><label>Düzeltme tutarı <small>(eklemek için +, düşmek için -)</small><input type="number" name="amount" step="0.01" placeholder="Örn. 250 veya -100" required></label><label>Düzeltme nedeni<textarea name="note" placeholder="Bakiye neden değiştiriliyor?" required></textarea></label><button class="btn btn-primary" <?= !$balances ? 'disabled' : '' ?>>Bakiye Düzeltmesini Kaydet</button></form>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Son <?= count($transactions) ?> hareket</p><h2>Bakiye işlem günlüğü</h2></div></div><div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Danışan</th><th>Hareket</th><th>Önce / Sonra</th><th>Açıklama</th><th>İşlemi yapan</th></tr></thead><tbody><?php foreach ($transactions as $item): ?><tr><td><?= e(dt((string) $item['created_at'])) ?></td><td><?= e($item['customer_name']) ?></td><td><strong class="wallet-amount <?= (float) $item['amount'] < 0 ? 'is-negative' : 'is-positive' ?>"><?= (float) $item['amount'] > 0 ? '+' : '' ?><?= money($item['amount']) ?></strong><small><?= e($item['transaction_type'] === 'manual' ? 'Manuel düzeltme' : 'Test bakiye yükleme') ?></small></td><td><?= money($item['balance_before']) ?><small>→ <?= money($item['balance_after']) ?></small></td><td><?= e($item['note'] ?: '-') ?></td><td><?= e($item['created_by_name'] ?: '-') ?></td></tr><?php endforeach; ?><?php if (!$transactions): ?><tr><td colspan="6" class="empty">Henüz bakiye hareketi yok.</td></tr><?php endif; ?></tbody></table></div></section></section><?php
}

function payment_method_fields(): void
{
    ?><label>Yöntem<select name="method"><?php foreach (PaymentService::METHODS as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Referans no<input name="reference_no" placeholder="Dekont / işlem no"></label><label>Not<input name="note"></label><?php
}

function payment_beta_notice(): void
{
    ?><p class="notice">Banka, kart ve PayPal seçenekleri beta aşamasında danışan bakiyesine yükleme ve yetkili onayıyla çalışır. Canlı entegrasyon daha sonra açılacaktır.</p><?php
}

function render_clinical(array $user): void
{
    $customerRows = customers(); $physios = consultants(); $notes = ClinicalService::notes($user);
    $history = DB::fetchAll('SELECT h.*, c.name AS customer_name, u.name AS created_by_name FROM patient_history h INNER JOIN users c ON c.id=h.customer_id INNER JOIN users u ON u.id=h.created_by ORDER BY h.id DESC LIMIT 200'); ?>
    <section class="grid two-col"><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_clinical_note"><p class="eyebrow">Fizyoterapi süreci</p><h2>Değerlendirme / seans notu</h2><label>Danışan<select name="customer_id"><?php options($customerRows); ?></select></label><?php if ($user['role'] !== 'consultant'): ?><label>Fizyoterapist<select name="consultant_id"><?php options($physios); ?></select></label><?php endif; ?><label>Not türü<select name="note_type"><option value="assessment">İlk değerlendirme</option><option value="treatment">Seans / uygulama notu</option><option value="progress">İlerleme değerlendirmesi</option><option value="discharge">Süreç sonu notu</option></select></label><label>Danışanın beyanı<textarea name="subjective" rows="3" placeholder="Şikâyet, geri bildirim, seans öncesi durum"></textarea></label><label>Fizyoterapist gözlemi<textarea name="objective" rows="3" placeholder="Fonksiyonel gözlem ve ölçümler"></textarea></label><label>Değerlendirme<textarea name="assessment" rows="3"></textarea></label><label>Uygulama / sonraki plan<textarea name="plan" rows="3"></textarea></label><label>Görünürlük<select name="visibility"><option value="internal">Yalnızca ekip</option><option value="customer_shared">Danışanla plan ve değerlendirmeyi paylaş</option></select></label><button class="btn btn-primary">Notu Kaydet</button></form>
    <form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="add_patient_history"><p class="eyebrow">Danışan geçmişi</p><h2>Geçmiş / hedef kaydı ekle</h2><label>Danışan<select name="customer_id"><?php options($customerRows); ?></select></label><label>Kayıt türü<select name="history_type"><option value="goal">Hedef</option><option value="injury">Geçmiş yaralanma</option><option value="condition">Mevcut durum</option><option value="allergy">Alerji / hassasiyet</option><option value="other">Diğer</option></select></label><label>Başlık<input name="title" required></label><label>Tarih<input type="date" name="event_date"></label><label>Ayrıntılar<textarea name="details" rows="6"></textarea></label><button class="btn btn-primary">Geçmişe Ekle</button></form></section>
    <section class="panel"><h2>Son fizyoterapi kayıtları</h2><div class="clinical-list"><?php foreach ($notes as $item): ?><article class="clinical-entry"><header><div><strong><?= e($item['customer_name']) ?></strong><small><?= e(clinical_type_label((string) $item['note_type'])) ?> · <?= e(dt((string) $item['created_at'])) ?> · <?= e($item['consultant_name']) ?></small></div><span class="badge"><?= $item['visibility'] === 'customer_shared' ? 'Danışanla paylaşıldı' : 'Ekip içi' ?></span></header><?php if ($item['subjective']): ?><p><b>Danışan:</b> <?= nl2br(e($item['subjective'])) ?></p><?php endif; ?><?php if ($item['objective']): ?><p><b>Gözlem:</b> <?= nl2br(e($item['objective'])) ?></p><?php endif; ?><?php if ($item['assessment']): ?><p><b>Değerlendirme:</b> <?= nl2br(e($item['assessment'])) ?></p><?php endif; ?><?php if ($item['plan']): ?><p><b>Plan:</b> <?= nl2br(e($item['plan'])) ?></p><?php endif; ?></article><?php endforeach; ?><?php if (!$notes): ?><p class="empty">Henüz fizyoterapi kaydı yok.</p><?php endif; ?></div></section>
    <section class="panel"><h2>Danışan geçmişi</h2><?php render_bare_table($history, ['customer_name' => 'Danışan', 'history_type' => 'Tür', 'title' => 'Başlık', 'details' => 'Ayrıntı', 'event_date' => 'Tarih', 'created_by_name' => 'Ekleyen']); ?></section><?php
}

function render_exercises(array $user): void
{
    $exercises = DB::fetchAll('SELECT * FROM exercise_library WHERE active=1 ORDER BY name');
    $programs = DB::fetchAll('SELECT ep.*, c.name AS customer_name, u.name AS created_by_name FROM exercise_programs ep INNER JOIN users c ON c.id=ep.customer_id INNER JOIN users u ON u.id=ep.created_by ORDER BY ep.id DESC LIMIT 200'); ?>
    <section class="grid two-col"><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_exercise"><p class="eyebrow">Egzersiz kütüphanesi</p><h2>Egzersiz ekle</h2><label>Egzersiz adı<input name="name" required></label><label>Kısa açıklama<textarea name="description" rows="3"></textarea></label><label>Uygulama yönergesi<textarea name="instructions" rows="6"></textarea></label><button class="btn btn-primary">Kütüphaneye Ekle</button></form>
    <form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="create_exercise_program"><p class="eyebrow">Danışana özel</p><h2>Egzersiz programı oluştur</h2><label>Danışan<select name="customer_id"><?php options(customers()); ?></select></label><label>Program adı<input name="title" required placeholder="Örn. 1. Hafta Ev Programı"></label><label>Egzersiz<select name="exercise_id"><?php options($exercises); ?></select></label><div class="form-grid compact"><label>Set<input type="number" name="sets_count" min="1"></label><label>Tekrar<input name="repetitions" placeholder="10 tekrar"></label><label>Bekleme sn<input type="number" name="hold_seconds" min="0"></label></div><label>Sıklık<input name="frequency_text" placeholder="Günde 2 kez"></label><label>Özel yönerge<textarea name="item_instructions" rows="3"></textarea></label><label>Başlangıç<input type="date" name="starts_at" value="<?= e(date('Y-m-d')) ?>"></label><label>Bitiş<input type="date" name="expires_at"></label><label>Program notu<textarea name="notes" rows="3"></textarea></label><button class="btn btn-primary">Programı Yayınla</button></form></section>
    <section class="panel"><h2>Mevcut programa egzersiz ekle</h2><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_exercise_program_item"><label>Program<select name="program_id"><?php foreach ($programs as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['customer_name'] . ' · ' . $item['title']) ?></option><?php endforeach; ?></select></label><label>Egzersiz<select name="exercise_id"><?php options($exercises); ?></select></label><label>Set<input type="number" name="sets_count" min="1"></label><label>Tekrar<input name="repetitions" placeholder="10 tekrar"></label><label>Bekleme (sn)<input type="number" name="hold_seconds" min="0"></label><label>Sıklık<input name="frequency_text" placeholder="Günde 2 kez"></label><label>Özel yönerge<input name="item_instructions"></label><button class="btn btn-primary">Programa Ekle</button></form></section>
    <section class="panel"><h2>Egzersiz kütüphanesi</h2><?php render_bare_table($exercises, ['name' => 'Egzersiz', 'description' => 'Açıklama', 'instructions' => 'Yönerge']); ?></section><section class="panel"><h2>Programlar</h2><?php render_bare_table($programs, ['customer_name' => 'Danışan', 'title' => 'Program', 'starts_at' => 'Başlangıç', 'expires_at' => 'Bitiş', 'status' => 'Durum', 'created_by_name' => 'Hazırlayan']); ?></section><?php
}

function render_customer_programs(array $user): void
{
    $programs = ClinicalService::programsForCustomer((int) $user['id']); $notes = ClinicalService::sharedNotesForCustomer((int) $user['id']); ?>
    <section class="program-grid"><?php foreach ($programs as $item): ?><article class="panel program-card"><p class="eyebrow"><?= e(date_only((string) $item['starts_at'])) ?><?= $item['expires_at'] ? ' – ' . e(date_only((string) $item['expires_at'])) : '' ?></p><h2><?= e($item['title']) ?></h2><h3><?= e($item['exercise_name']) ?></h3><?php if ($item['description']): ?><p><?= nl2br(e($item['description'])) ?></p><?php endif; ?><div class="exercise-stats"><span><strong><?= e($item['sets_count'] ?: '-') ?></strong> set</span><span><strong><?= e($item['repetitions'] ?: '-') ?></strong> tekrar</span><span><strong><?= e($item['hold_seconds'] ?: '-') ?></strong> sn</span></div><?php if ($item['frequency_text']): ?><p><b>Sıklık:</b> <?= e($item['frequency_text']) ?></p><?php endif; ?><?php if ($item['exercise_instructions'] || $item['item_instructions']): ?><div class="notice"><?= nl2br(e($item['item_instructions'] ?: $item['exercise_instructions'])) ?></div><?php endif; ?></article><?php endforeach; ?><?php if (!$programs): ?><section class="panel empty-state"><h2>Aktif egzersiz programınız yok</h2><p>Fizyoterapistiniz program yayınladığında burada görünecek.</p></section><?php endif; ?></section>
    <?php if ($notes): ?><section class="panel"><h2>Sizinle paylaşılan süreç notları</h2><div class="clinical-list"><?php foreach ($notes as $item): ?><article class="clinical-entry"><header><div><strong><?= e(clinical_type_label((string) $item['note_type'])) ?></strong><small><?= e(dt((string) $item['created_at'])) ?> · <?= e($item['consultant_name']) ?></small></div></header><?php if ($item['assessment']): ?><p><b>Değerlendirme:</b> <?= nl2br(e($item['assessment'])) ?></p><?php endif; ?><?php if ($item['plan']): ?><p><b>Plan:</b> <?= nl2br(e($item['plan'])) ?></p><?php endif; ?></article><?php endforeach; ?></div></section><?php endif;
}

function render_reports(array $user): void
{
    render_dashboard($user); $popular = DB::fetchAll('SELECT s.name, COUNT(*) AS total FROM reservations r INNER JOIN services s ON s.id = r.service_id GROUP BY s.id ORDER BY total DESC LIMIT 10'); render_simple_table($popular, ['name' => 'Hizmet', 'total' => 'Rezervasyon']);
}

function render_settings(): void
{
    $settings = Management::publicSettings(); ?>
    <section class="grid two-col"><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><h2>Rezervasyon kuralları</h2><label>Değişiklik son süresi (saat)<input type="number" name="booking_change_deadline_hours" value="<?= e($settings['booking_change_deadline_hours']) ?>" min="0"></label><label>Geç iptalde hak yansın mı?<select name="late_cancel_burn_credit"><option value="1" <?= selected($settings['late_cancel_burn_credit'], '1') ?>>Evet</option><option value="0" <?= selected($settings['late_cancel_burn_credit'], '0') ?>>Hayır</option></select></label><label>Hak düşme anı<select name="credit_deduction_policy"><option value="on_booking" <?= selected($settings['credit_deduction_policy'], 'on_booking') ?>>Rezervasyon oluşturulunca</option><option value="on_attendance" <?= selected($settings['credit_deduction_policy'], 'on_attendance') ?>>Geldi / gelmedi işaretlenince</option></select></label><label>Hatırlatma (saat)<input type="number" name="reservation_reminder_hours" value="<?= e($settings['reservation_reminder_hours']) ?>" min="1"></label><button class="btn btn-primary">Kuralları Kaydet</button></form>
    <form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="save_settings"><h2>Ödeme ayarları</h2><?php payment_beta_notice(); ?><label>Para birimi<input name="currency" value="<?= e($settings['currency']) ?>" maxlength="3"></label><label>Banka adı<input name="bank_name" value="<?= e($settings['bank_name']) ?>"></label><label>Hesap sahibi<input name="bank_account_name" value="<?= e($settings['bank_account_name']) ?>"></label><label>IBAN<input name="bank_iban" value="<?= e($settings['bank_iban']) ?>"></label><hr><h3>Test kartı</h3><p class="form-note">Danışan portalındaki sahte bakiye yüklemede yalnızca bu bilgiler onay verir.</p><label>Test kart numarası<input name="test_card_number" inputmode="numeric" value="<?= e($settings['test_card_number']) ?>" minlength="12" maxlength="19" required></label><label>Son kullanma (AA/YY)<input name="test_card_expiry" value="<?= e($settings['test_card_expiry']) ?>" maxlength="5" required></label><label>Test CVV<input name="test_card_cvv" inputmode="numeric" value="<?= e($settings['test_card_cvv']) ?>" minlength="3" maxlength="4" required></label><button class="btn btn-primary">Ödeme Ayarlarını Kaydet</button></form></section><?php
}

function render_audit_logs(): void
{
    $logs = DB::fetchAll('SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.id DESC LIMIT 500'); ?>
    <section class="panel"><div class="section-heading"><div><p class="eyebrow">Son <?= count($logs) ?> hareket</p><h2>Değiştirilemez işlem izi</h2><p class="muted">Kullanıcı, rol, rezervasyon, ödeme, hak ve fizyoterapi kaydı işlemleri burada izlenir.</p></div></div><?php render_bare_table($logs, ['created_at' => 'Tarih', 'actor_name' => 'İşlemi yapan', 'action' => 'İşlem', 'entity_type' => 'Kayıt türü', 'entity_id' => 'Kayıt no', 'ip_address' => 'IP', 'details_json' => 'Ayrıntı']); ?></section><?php
}

function render_customer_packages(array $user): void
{
    $packages = DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? ORDER BY cp.expires_at DESC', [$user['id']]); ?>
    <section class="section-heading"><div><p class="eyebrow">Paket ve haklar</p><h2>Paketlerim</h2></div><a class="btn btn-primary" href="<?= e(url_for('/customer/payments')) ?>">Bakiyeyle Paket Al</a></section>
    <section class="package-grid"><?php foreach ($packages as $item): ?><article class="panel package-card"><div><span class="badge <?= e($item['status']) ?>"><?= e($item['status']) ?></span><h2><?= e($item['package_name']) ?></h2><p><?= e(date_only((string) $item['starts_at'])) ?> – <?= e(date_only((string) $item['expires_at'])) ?></p></div><div class="credit-ring"><strong><?= e($item['credits_remaining']) ?></strong><span>/ <?= e($item['credits_total']) ?> hak</span></div><p>Ödeme: <strong><?= e(payment_label((string) $item['payment_status'])) ?></strong></p></article><?php endforeach; ?><?php if (!$packages): ?><section class="panel empty-state"><h2>Henüz paketiniz yok</h2><p>Size uygun paket için işletmeyle iletişime geçebilirsiniz.</p></section><?php endif; ?></section>
    <section class="panel"><h2>Hak hareketleri</h2><?php $moves = DB::fetchAll('SELECT ct.* FROM credit_transactions ct WHERE ct.customer_id = ? ORDER BY ct.id DESC LIMIT 100', [$user['id']]); render_bare_table($moves, ['created_at' => 'Tarih', 'amount' => 'Hareket', 'balance_after' => 'Kalan', 'reason' => 'Sebep', 'note' => 'Not']); ?></section><?php
}

function render_profile(array $user): void
{
    ?><section class="grid two-col"><form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="update_profile"><p class="eyebrow">Hesap bilgileri</p><h2>Profilimi güncelle</h2><label>Ad soyad<input name="name" value="<?= e($user['name']) ?>" required></label><label>E-posta<input value="<?= e($user['email']) ?>" disabled></label><label>Telefon<input name="phone" value="<?= e($user['phone']) ?>"></label><button class="btn btn-primary">Bilgileri Kaydet</button></form>
    <form method="post" class="panel form-grid single"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><p class="eyebrow">Güvenlik</p><h2>Şifremi değiştir</h2><label>Mevcut şifre<input type="password" name="current_password" required></label><label>Yeni şifre<input type="password" name="new_password" minlength="8" required></label><label>Yeni şifre tekrarı<input type="password" name="new_password_confirmation" minlength="8" required></label><button class="btn btn-primary">Şifreyi Değiştir</button></form></section><?php
}

function render_simple_table(array $rows, array $columns): void
{
    ?><section class="panel"><?php render_bare_table($rows, $columns); ?></section><?php
}

function render_bare_table(array $rows, array $columns): void
{
    ?><div class="table-wrap"><table><thead><tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $key => $label): ?><td><?= e(display_value($row[$key] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="empty">Kayıt yok.</td></tr><?php endif; ?></tbody></table></div><?php
}

function render_flash(?array $flash): void
{
    if ($flash) echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
}

function render_error(Throwable $e): void
{
    ?><!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css') . '?v=6') ?>"><title>FizyoRez Hata</title></head><body class="auth-page"><main class="auth-card wide"><div class="brand-mark">FR</div><h1>İşlem tamamlanamadı</h1><p><?= e(friendly_error_message($e)) ?></p><p class="muted">Kurulum ayrıntıları için <a href="<?= e(url_for('/health')) ?>">sistem kontrolünü</a> açabilirsiniz.</p><a class="btn btn-primary" href="<?= e(url_for('/login')) ?>">Girişe dön</a></main></body></html><?php
}

function customers(): array { return DB::fetchAll('SELECT id, name FROM users WHERE role = "customer" AND status = "active" ORDER BY name'); }
function consultants(): array { return DB::fetchAll('SELECT id, name FROM users WHERE role = "consultant" AND status = "active" ORDER BY name'); }
function options(array $rows, string $labelKey = 'name'): void { foreach ($rows as $row) echo '<option value="' . e($row['id']) . '">' . e($row[$labelKey] ?? $row['name'] ?? $row['id']) . '</option>'; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function selected(mixed $left, mixed $right): string { return (string) $left === (string) $right ? 'selected' : ''; }
function weekdays(): array { return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar']; }
function date_only(string $value): string { if ($value === '') return '-'; try { return (new DateTimeImmutable($value))->format('d-m-Y'); } catch (Throwable) { return $value; } }
function dt(string $value): string { try { return (new DateTimeImmutable($value))->format('d-m-Y H:i'); } catch (Throwable) { return $value; } }
function reservation_range(string $startsAt, string $endsAt): string { try { $start = new DateTimeImmutable($startsAt); $end = new DateTimeImmutable($endsAt); return $start->format('d-m-Y H:i') . '–' . $end->format('H:i'); } catch (Throwable) { return $startsAt . '–' . $endsAt; } }
function reservation_range_from_duration(string $startsAt, int $durationMinutes): string { try { $start = new DateTimeImmutable($startsAt); return reservation_range($start->format('Y-m-d H:i:s'), $start->modify('+' . max(0, $durationMinutes) . ' minutes')->format('Y-m-d H:i:s')); } catch (Throwable) { return $startsAt; } }
function display_value(mixed $value): mixed { if (!is_string($value)) return $value; if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return date_only($value); if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) return dt($value); return $value; }
function money(float|int|string $value, string $currency = 'TRY'): string { return number_format((float) $value, 2, ',', '.') . ' ' . e($currency); }
function status_label(string $status): string { return ['pending' => 'Bekliyor', 'confirmed' => 'Onaylı', 'cancelled' => 'İptal', 'completed' => 'Geldi', 'no_show' => 'Gelmedi'][$status] ?? $status; }
function status_account_label(string $status): string { return ['active' => 'Aktif', 'pending' => 'Bekliyor', 'suspended' => 'Askıda', 'passive' => 'Pasif'][$status] ?? $status; }
function payment_label(string $status): string { return PaymentService::STATUSES[$status] ?? $status; }
function clinical_type_label(string $type): string { return ['assessment' => 'İlk değerlendirme', 'treatment' => 'Seans / uygulama notu', 'progress' => 'İlerleme değerlendirmesi', 'discharge' => 'Süreç sonu notu'][$type] ?? $type; }
function personnel_role_options(string $current, array $actor): void { $roles = ['consultant' => 'Fizyoterapist', 'staff' => 'Resepsiyon / Personel']; if ($actor['role'] === 'super_admin') $roles = ['super_admin' => 'Süper Yönetici', 'admin' => 'Yönetici'] + $roles; foreach ($roles as $value => $label) echo '<option value="' . e($value) . '" ' . selected($current, $value) . '>' . e($label) . '</option>'; }
