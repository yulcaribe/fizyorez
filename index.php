<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$path = current_path();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

try {
    if ($path === '/health' || $path === '/setup-check') {
        render_health();
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
            redirect_to(role_home($user['role']));
        }
        render_login($flash);
        exit;
    }

    if ($path === '/logout') {
        Auth::logout();
        redirect_to('/login');
    }

    $user = Auth::requireWeb();
    if ($path === '/' || $path === '/index.php') {
        redirect_to(role_home($user['role']));
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
    return match ($role) {
        'admin' => '/admin',
        'consultant' => '/consultant',
        default => '/customer',
    };
}

function render_health(): void
{
    $checks = setup_checks();
    $allOk = !in_array(false, array_column($checks, 'ok'), true);
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e(config('app.name')) ?> Kurulum Kontrolu</title>
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
    </head>
    <body class="auth-page">
        <main class="auth-card wide">
            <h1>Kurulum kontrolu</h1>
            <p class="muted"><?= $allOk ? 'Sistem calismaya hazir.' : 'Asagidaki eksikleri duzeltip tekrar deneyin.' ?></p>
            <div class="table-wrap"><table><thead><tr><th>Kontrol</th><th>Durum</th><th>Detay</th></tr></thead><tbody>
                <?php foreach ($checks as $check): ?>
                    <tr>
                        <td><?= e($check['label']) ?></td>
                        <td><span class="badge <?= $check['ok'] ? 'completed' : 'cancelled' ?>"><?= $check['ok'] ? 'OK' : 'Hata' ?></span></td>
                        <td><?= e($check['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <p class="muted">Login adresi: <a href="<?= e(url_for('/login')) ?>"><?= e(url_for('/login')) ?></a></p>
        </main>
    </body>
    </html>
    <?php
}

function handle_web_action(array $user, string $path): never
{
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        match ($action) {
            'create_user' => require_admin($user) && Management::createUser($_POST),
            'create_service' => require_admin($user) && Management::saveService(null, $_POST),
            'create_package' => require_admin($user) && Management::savePackage(null, $_POST),
            'assign_package' => require_admin($user) && Management::assignPackage($_POST),
            'save_settings' => save_settings_action($user),
            'create_reservation' => ReservationService::create($user, $_POST),
            'cancel_reservation' => ReservationService::cancel($user, (int) $_POST['reservation_id']),
            'reschedule_reservation' => ReservationService::reschedule($user, (int) $_POST['reservation_id'], (string) $_POST['starts_at']),
            'set_reservation_status' => ReservationService::setStatus($user, (int) $_POST['reservation_id'], (string) $_POST['status']),
            'set_payment_status' => set_payment_status_action($user),
            'add_availability' => add_availability_action($user),
            'delete_availability' => delete_availability_action($user),
            'add_time_off' => add_time_off_action($user),
            default => throw new RuntimeException('İşlem bulunamadı.'),
        };

        flash('success', 'İşlem tamamlandı.');
    } catch (Throwable $e) {
        flash('error', friendly_error_message($e));
    }

    redirect_to($path);
}

function require_admin(array $user): bool
{
    if ($user['role'] !== 'admin') {
        throw new RuntimeException('Bu işlem için admin yetkisi gerekli.');
    }

    return true;
}

function save_settings_action(array $user): bool
{
    require_admin($user);
    foreach (['booking_change_deadline_hours', 'late_cancel_burn_credit', 'credit_deduction_policy', 'reservation_reminder_hours'] as $key) {
        if (isset($_POST[$key])) {
            save_setting($key, (string) $_POST[$key]);
        }
    }

    return true;
}

function set_payment_status_action(array $user): bool
{
    require_admin($user);
    $status = (string) ($_POST['payment_status'] ?? '');
    if (!in_array($status, ['paid', 'pending', 'cancelled'], true)) {
        throw new RuntimeException('Ödeme durumu geçersiz.');
    }
    DB::execute('UPDATE reservations SET payment_status = ?, updated_at = NOW() WHERE id = ?', [$status, (int) $_POST['reservation_id']]);

    return true;
}

function add_availability_action(array $user): bool
{
    $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($_POST['consultant_id'] ?? 0);
    if ($user['role'] !== 'admin' && $consultantId !== (int) $user['id']) {
        throw new RuntimeException('Bu işlem için yetkiniz yok.');
    }

    DB::insert(
        'INSERT INTO consultant_availability (consultant_id, weekday, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)',
        [$consultantId, (int) $_POST['weekday'], (string) $_POST['start_time'], (string) $_POST['end_time']]
    );

    return true;
}

function delete_availability_action(array $user): bool
{
    $item = DB::fetch('SELECT * FROM consultant_availability WHERE id = ?', [(int) $_POST['availability_id']]);
    if (!$item) {
        throw new RuntimeException('Müsaitlik bulunamadı.');
    }
    if ($user['role'] !== 'admin' && (int) $item['consultant_id'] !== (int) $user['id']) {
        throw new RuntimeException('Bu işlem için yetkiniz yok.');
    }

    DB::execute('DELETE FROM consultant_availability WHERE id = ?', [(int) $_POST['availability_id']]);

    return true;
}

function add_time_off_action(array $user): bool
{
    if (!in_array($user['role'], ['admin', 'consultant'], true)) {
        throw new RuntimeException('Bu işlem için yetkiniz yok.');
    }

    $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : (int) ($_POST['consultant_id'] ?? 0);
    DB::insert(
        'INSERT INTO consultant_time_off (consultant_id, start_at, end_at, reason) VALUES (?, ?, ?, ?)',
        [$consultantId, normalize_form_datetime((string) $_POST['start_at']), normalize_form_datetime((string) $_POST['end_at']), (string) ($_POST['reason'] ?? '')]
    );

    return true;
}

function normalize_form_datetime(string $value): string
{
    $value = str_replace('T', ' ', $value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
    if (!$date) {
        throw new RuntimeException('Tarih formatı geçersiz.');
    }

    return $date->format('Y-m-d H:i:s');
}

function render_login(?array $flash): void
{
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e(config('app.name')) ?> Giriş</title>
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
    </head>
    <body class="auth-page">
        <main class="auth-shell">
            <section class="auth-hero">
                <div class="brand-mark">FR</div>
                <h1><?= e(config('app.name')) ?></h1>
                <p>Rezervasyon, paket hakkı ve danışman takvimi tek panelde.</p>
            </section>
            <form method="post" class="auth-card">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <h2>Giriş Yap</h2>
                <?php render_flash($flash); ?>
                <label>E-posta
                    <input type="email" name="email" required placeholder="admin@demo.local">
                </label>
                <label>Şifre
                    <input type="password" name="password" required placeholder="password">
                </label>
                <button class="btn btn-primary" type="submit">Giriş Yap</button>
                <p class="muted">Demo kullanıcıları: admin@demo.local, danisman@demo.local, musteri@demo.local / password</p>
            </form>
        </main>
    </body>
    </html>
    <?php
}

function render_page(array $user, string $path, ?array $flash): void
{
    assert_role_path($user, $path);
    $title = page_title($path);
    ?>
    <!doctype html>
    <html lang="tr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= e($title) ?> - <?= e(config('app.name')) ?></title>
        <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <a href="<?= e(url_for(role_home($user['role']))) ?>" class="brand">
                    <span class="brand-mark">FR</span>
                    <span><?= e(config('app.name')) ?></span>
                </a>
                <nav class="nav"><?= nav_links($user, $path) ?></nav>
            </aside>
            <div class="main-shell">
                <header class="topbar">
                    <div>
                        <p class="eyebrow"><?= e(role_label($user['role'])) ?></p>
                        <h1><?= e($title) ?></h1>
                    </div>
                    <div class="user-box">
                        <span><?= e($user['name']) ?></span>
                        <a class="btn btn-ghost" href="<?= e(url_for('/logout')) ?>">Çıkış</a>
                    </div>
                </header>
                <?php render_flash($flash); ?>
                <main class="content">
                    <?php render_route($user, $path); ?>
                </main>
            </div>
        </div>
        <script src="<?= e(asset_url('assets/js/app.js')) ?>"></script>
    </body>
    </html>
    <?php
}

function assert_role_path(array $user, string $path): void
{
    $allowedPrefix = role_home($user['role']);
    if (!str_starts_with($path, $allowedPrefix)) {
        redirect_to($allowedPrefix);
    }
}

function page_title(string $path): string
{
    return match ($path) {
        '/admin/users' => 'Kullanıcılar',
        '/admin/services' => 'Hizmetler',
        '/admin/packages' => 'Paketler',
        '/admin/reservations', '/consultant/reservations', '/customer/reservations' => 'Rezervasyonlar',
        '/admin/availability', '/consultant/availability' => 'Müsaitlik',
        '/consultant/time-off' => 'İzin ve Mola',
        '/customer/book' => 'Rezervasyon Al',
        '/customer/packages' => 'Paketlerim',
        '/admin/reports' => 'Raporlar',
        '/admin/settings' => 'Ayarlar',
        default => 'Dashboard',
    };
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin Paneli',
        'consultant' => 'Danışman Paneli',
        default => 'Müşteri Paneli',
    };
}

function nav_links(array $user, string $path): string
{
    $links = match ($user['role']) {
        'admin' => [
            '/admin' => 'Dashboard',
            '/admin/reservations' => 'Rezervasyonlar',
            '/admin/users' => 'Kullanıcılar',
            '/admin/services' => 'Hizmetler',
            '/admin/packages' => 'Paketler',
            '/admin/availability' => 'Müsaitlik',
            '/admin/reports' => 'Raporlar',
            '/admin/settings' => 'Ayarlar',
        ],
        'consultant' => [
            '/consultant' => 'Dashboard',
            '/consultant/reservations' => 'Rezervasyonlar',
            '/consultant/availability' => 'Müsaitlik',
            '/consultant/time-off' => 'İzin ve Mola',
        ],
        default => [
            '/customer' => 'Dashboard',
            '/customer/book' => 'Rezervasyon Al',
            '/customer/reservations' => 'Rezervasyonlarım',
            '/customer/packages' => 'Paketlerim',
        ],
    };

    $html = '';
    foreach ($links as $href => $label) {
        $active = $path === $href ? 'active' : '';
        $html .= '<a class="' . $active . '" href="' . e(url_for($href)) . '">' . e($label) . '</a>';
    }

    return $html;
}

function render_route(array $user, string $path): void
{
    match ($path) {
        '/admin' => render_dashboard($user),
        '/consultant' => render_dashboard($user),
        '/customer' => render_dashboard($user),
        '/admin/users' => render_users(),
        '/admin/services' => render_services(),
        '/admin/packages' => render_packages(),
        '/admin/reservations', '/consultant/reservations', '/customer/reservations', '/customer/book' => render_reservations($user, $path),
        '/admin/availability', '/consultant/availability' => render_availability($user),
        '/consultant/time-off' => render_time_off($user),
        '/admin/reports' => render_reports($user),
        '/admin/settings' => render_settings(),
        '/customer/packages' => render_customer_packages($user),
        default => render_dashboard($user),
    };
}

function render_dashboard(array $user): void
{
    $summary = in_array($user['role'], ['admin', 'consultant'], true) ? Management::reportSummary($user) : null;
    if ($user['role'] === 'customer') {
        $packages = DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? ORDER BY cp.expires_at ASC', [$user['id']]);
        $upcoming = ReservationService::list($user, ['from' => date('Y-m-d H:i:s')]);
        ?>
        <section class="grid cards-3">
            <div class="metric"><span>Aktif paket</span><strong><?= count(array_filter($packages, fn ($p) => $p['status'] === 'active')) ?></strong></div>
            <div class="metric"><span>Kalan hak</span><strong><?= array_sum(array_map(fn ($p) => (int) $p['credits_remaining'], $packages)) ?></strong></div>
            <div class="metric"><span>Yaklaşan randevu</span><strong><?= count($upcoming) ?></strong></div>
        </section>
        <?php render_reservation_table($user, array_slice($upcoming, 0, 8)); ?>
        <?php
        return;
    }
    ?>
    <section class="grid cards-5">
        <div class="metric"><span>Bugün</span><strong><?= e($summary['today_reservations']) ?></strong></div>
        <div class="metric"><span>Yaklaşan</span><strong><?= e($summary['upcoming_reservations']) ?></strong></div>
        <div class="metric"><span>30 gün tamamlanan</span><strong><?= e($summary['completed_last_30_days']) ?></strong></div>
        <div class="metric"><span>30 gün gelmedi</span><strong><?= e($summary['no_show_last_30_days']) ?></strong></div>
        <div class="metric"><span>Tek seans gelir</span><strong><?= money($summary['single_session_revenue_last_30_days']) ?></strong></div>
    </section>
    <?php
    $today = ReservationService::list($user, ['from' => date('Y-m-d 00:00:00'), 'to' => date('Y-m-d 23:59:59')]);
    render_reservation_table($user, $today);
}

function render_users(): void
{
    $users = DB::fetchAll('SELECT id, role, name, email, phone, status, created_at FROM users ORDER BY role, name');
    ?>
    <section class="panel">
        <h2>Yeni Kullanıcı</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <label>Rol<select name="role"><option value="customer">Müşteri</option><option value="consultant">Danışman</option><option value="admin">Admin</option></select></label>
            <label>Ad Soyad<input name="name" required></label>
            <label>E-posta<input type="email" name="email" required></label>
            <label>Telefon<input name="phone"></label>
            <label>Şifre<input name="password" value="password"></label>
            <label>Danışman unvanı<input name="title" placeholder="Danışman"></label>
            <button class="btn btn-primary">Ekle</button>
        </form>
    </section>
    <section class="panel">
        <h2>Kullanıcı Listesi</h2>
        <div class="table-wrap"><table><thead><tr><th>Rol</th><th>Ad</th><th>E-posta</th><th>Telefon</th><th>Durum</th></tr></thead><tbody>
        <?php foreach ($users as $item): ?>
            <tr><td><?= e(role_label_short($item['role'])) ?></td><td><?= e($item['name']) ?></td><td><?= e($item['email']) ?></td><td><?= e($item['phone']) ?></td><td><span class="badge"><?= e($item['status']) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
}

function render_services(): void
{
    $services = DB::fetchAll('SELECT * FROM services ORDER BY active DESC, name');
    ?>
    <section class="panel">
        <h2>Yeni Hizmet / Ders</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_service">
            <label>Ad<input name="name" required></label>
            <label>Tip<select name="type"><option value="one_to_one">Birebir</option><option value="group">Grup</option></select></label>
            <label>Süre dk<input type="number" name="duration_minutes" value="60" min="10" required></label>
            <label>Kapasite<input type="number" name="capacity" value="1" min="1"></label>
            <label>Tek seans fiyat<input type="number" step="0.01" name="price" value="0"></label>
            <label>Açıklama<input name="description"></label>
            <button class="btn btn-primary">Ekle</button>
        </form>
    </section>
    <?php render_simple_table($services, ['name' => 'Ad', 'type' => 'Tip', 'duration_minutes' => 'Süre', 'capacity' => 'Kapasite', 'price' => 'Fiyat', 'active' => 'Aktif']); ?>
    <?php
}

function render_packages(): void
{
    $packages = DB::fetchAll('SELECT * FROM packages ORDER BY active DESC, name');
    $customers = customers();
    ?>
    <section class="grid two-col">
        <div class="panel">
            <h2>Yeni Paket</h2>
            <form method="post" class="form-grid single">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_package">
                <label>Ad<input name="name" required></label>
                <label>Hak sayısı<input type="number" name="total_credits" value="10" min="1"></label>
                <label>Geçerlilik günü<input type="number" name="validity_days" value="30" min="1"></label>
                <label>Fiyat<input type="number" step="0.01" name="price" value="0"></label>
                <label>Açıklama<input name="description"></label>
                <button class="btn btn-primary">Paket Ekle</button>
            </form>
        </div>
        <div class="panel">
            <h2>Müşteriye Paket Tanımla</h2>
            <form method="post" class="form-grid single">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="assign_package">
                <label>Müşteri<select name="customer_id"><?php options($customers); ?></select></label>
                <label>Paket<select name="package_id"><?php options($packages); ?></select></label>
                <label>Başlangıç<input type="date" name="starts_at" value="<?= e(date('Y-m-d')) ?>"></label>
                <label>Ödeme<select name="payment_status"><option value="pending">Bekliyor</option><option value="paid">Ödendi</option></select></label>
                <button class="btn btn-primary">Tanımla</button>
            </form>
        </div>
    </section>
    <?php render_simple_table($packages, ['name' => 'Paket', 'total_credits' => 'Hak', 'validity_days' => 'Gün', 'price' => 'Fiyat', 'active' => 'Aktif']); ?>
    <?php
}

function render_reservations(array $user, string $path): void
{
    $reservations = ReservationService::list($user, $path === '/customer/book' ? ['from' => date('Y-m-d H:i:s')] : []);
    ?>
    <section class="panel">
        <h2><?= $path === '/customer/book' ? 'Yeni Rezervasyon' : 'Rezervasyon Oluştur' ?></h2>
        <?php render_reservation_form($user); ?>
    </section>
    <?php render_reservation_table($user, $reservations); ?>
    <?php
}

function render_reservation_form(array $user): void
{
    $services = DB::fetchAll('SELECT * FROM services WHERE active = 1 ORDER BY name');
    $consultants = consultants();
    $customers = customers();
    $customerPackages = $user['role'] === 'customer'
        ? DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? AND cp.status = "active" ORDER BY cp.expires_at ASC', [$user['id']])
        : [];
    ?>
    <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_reservation">
        <?php if ($user['role'] === 'admin'): ?>
            <label>Müşteri<select name="customer_id"><?php options($customers); ?></select></label>
            <label>Danışman<select name="consultant_id"><?php options($consultants); ?></select></label>
        <?php elseif ($user['role'] === 'consultant'): ?>
            <label>Müşteri<select name="customer_id"><?php options($customers); ?></select></label>
        <?php else: ?>
            <label>Danışman<select name="consultant_id"><?php options($consultants); ?></select></label>
        <?php endif; ?>
        <label>Hizmet<select name="service_id"><?php options($services); ?></select></label>
        <label>Tip<select name="reservation_type" data-toggle-package><option value="package">Paket hakkı</option><option value="single">Tek seans</option></select></label>
        <?php if ($user['role'] === 'customer'): ?>
            <label>Paket<select name="customer_package_id"><option value="">Otomatik seç</option><?php options($customerPackages, 'package_name'); ?></select></label>
        <?php endif; ?>
        <label>Başlangıç<input type="datetime-local" name="starts_at" required></label>
        <label>Ödeme<select name="payment_status"><option value="pending">Bekliyor</option><option value="paid">Ödendi</option></select></label>
        <label>Not<input name="notes"></label>
        <button class="btn btn-primary">Rezervasyon Oluştur</button>
    </form>
    <?php
}

function render_reservation_table(array $user, array $reservations): void
{
    ?>
    <section class="panel">
        <h2>Rezervasyonlar</h2>
        <div class="table-wrap"><table><thead><tr><th>Tarih</th><th>Hizmet</th><th>Müşteri</th><th>Danışman</th><th>Tip</th><th>Durum</th><th>Ödeme</th><th>İşlem</th></tr></thead><tbody>
        <?php foreach ($reservations as $item): ?>
            <tr>
                <td><?= e(dt($item['starts_at'])) ?></td>
                <td><?= e($item['service_name']) ?></td>
                <td><?= e($item['customer_name']) ?></td>
                <td><?= e($item['consultant_name']) ?></td>
                <td><?= e($item['reservation_type'] === 'single' ? 'Tek seans' : 'Paket') ?></td>
                <td><span class="badge <?= e($item['status']) ?>"><?= e(status_label($item['status'])) ?></span></td>
                <td><span class="badge"><?= e(payment_label($item['payment_status'])) ?></span></td>
                <td class="actions">
                    <?php if (in_array($item['status'], ['pending', 'confirmed'], true)): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><button class="btn btn-small btn-danger">İptal</button></form>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reschedule_reservation"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><input type="datetime-local" name="starts_at" required><button class="btn btn-small">Değiştir</button></form>
                    <?php endif; ?>
                    <?php if (in_array($user['role'], ['admin', 'consultant'], true) && in_array($item['status'], ['pending', 'confirmed'], true)): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_reservation_status"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><input type="hidden" name="status" value="completed"><button class="btn btn-small">Geldi</button></form>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_reservation_status"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><input type="hidden" name="status" value="no_show"><button class="btn btn-small">Gelmedi</button></form>
                    <?php endif; ?>
                    <?php if ($user['role'] === 'admin'): ?>
                        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set_payment_status"><input type="hidden" name="reservation_id" value="<?= e($item['id']) ?>"><select name="payment_status"><option value="pending">Bekliyor</option><option value="paid">Ödendi</option><option value="cancelled">İptal</option></select><button class="btn btn-small">Ödeme</button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$reservations): ?><tr><td colspan="8" class="empty">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php
}

function render_availability(array $user): void
{
    $consultantId = $user['role'] === 'consultant' ? (int) $user['id'] : 0;
    $items = $consultantId
        ? DB::fetchAll('SELECT a.*, u.name AS consultant_name FROM consultant_availability a INNER JOIN users u ON u.id = a.consultant_id WHERE consultant_id = ? ORDER BY weekday, start_time', [$consultantId])
        : DB::fetchAll('SELECT a.*, u.name AS consultant_name FROM consultant_availability a INNER JOIN users u ON u.id = a.consultant_id ORDER BY u.name, weekday, start_time');
    ?>
    <section class="panel">
        <h2>Müsaitlik Ekle</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_availability">
            <?php if ($user['role'] === 'admin'): ?><label>Danışman<select name="consultant_id"><?php options(consultants()); ?></select></label><?php endif; ?>
            <label>Gün<select name="weekday"><?php foreach (weekdays() as $key => $day): ?><option value="<?= e($key) ?>"><?= e($day) ?></option><?php endforeach; ?></select></label>
            <label>Başlangıç<input type="time" name="start_time" value="09:00"></label>
            <label>Bitiş<input type="time" name="end_time" value="18:00"></label>
            <button class="btn btn-primary">Ekle</button>
        </form>
    </section>
    <section class="panel">
        <h2>Haftalık Müsaitlik</h2>
        <div class="table-wrap"><table><thead><tr><th>Danışman</th><th>Gün</th><th>Saat</th><th></th></tr></thead><tbody>
        <?php foreach ($items as $item): ?>
            <tr><td><?= e($item['consultant_name']) ?></td><td><?= e(weekdays()[(int) $item['weekday']] ?? '-') ?></td><td><?= e(substr($item['start_time'], 0, 5) . ' - ' . substr($item['end_time'], 0, 5)) ?></td><td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_availability"><input type="hidden" name="availability_id" value="<?= e($item['id']) ?>"><button class="btn btn-small btn-danger">Sil</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <?php
}

function render_time_off(array $user): void
{
    $items = DB::fetchAll('SELECT * FROM consultant_time_off WHERE consultant_id = ? ORDER BY start_at DESC', [$user['id']]);
    ?>
    <section class="panel">
        <h2>İzin / Mola Ekle</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_time_off">
            <label>Başlangıç<input type="datetime-local" name="start_at" required></label>
            <label>Bitiş<input type="datetime-local" name="end_at" required></label>
            <label>Neden<input name="reason"></label>
            <button class="btn btn-primary">Ekle</button>
        </form>
    </section>
    <?php render_simple_table($items, ['start_at' => 'Başlangıç', 'end_at' => 'Bitiş', 'reason' => 'Neden']); ?>
    <?php
}

function render_reports(array $user): void
{
    $summary = Management::reportSummary($user);
    render_dashboard($user);
    $popular = DB::fetchAll('SELECT s.name, COUNT(*) AS total FROM reservations r INNER JOIN services s ON s.id = r.service_id GROUP BY s.id ORDER BY total DESC LIMIT 10');
    render_simple_table($popular, ['name' => 'Hizmet', 'total' => 'Rezervasyon']);
}

function render_settings(): void
{
    $settings = Management::publicSettings();
    ?>
    <section class="panel">
        <h2>Rezervasyon Kuralları</h2>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <label>Değişiklik son süresi saat<input type="number" name="booking_change_deadline_hours" value="<?= e($settings['booking_change_deadline_hours']) ?>" min="0"></label>
            <label>Geç iptalde hak yansın mı<select name="late_cancel_burn_credit"><option value="1" <?= selected($settings['late_cancel_burn_credit'], '1') ?>>Evet</option><option value="0" <?= selected($settings['late_cancel_burn_credit'], '0') ?>>Hayır</option></select></label>
            <label>Hak düşme<select name="credit_deduction_policy"><option value="on_booking" <?= selected($settings['credit_deduction_policy'], 'on_booking') ?>>Rezervasyonda</option><option value="on_attendance" <?= selected($settings['credit_deduction_policy'], 'on_attendance') ?>>Geldi/Gelmedi işaretinde</option></select></label>
            <label>Hatırlatma saati<input type="number" name="reservation_reminder_hours" value="<?= e($settings['reservation_reminder_hours']) ?>" min="1"></label>
            <button class="btn btn-primary">Kaydet</button>
        </form>
    </section>
    <?php
}

function render_customer_packages(array $user): void
{
    $packages = DB::fetchAll(Management::customerPackageSql() . ' WHERE cp.customer_id = ? ORDER BY cp.expires_at DESC', [$user['id']]);
    render_simple_table($packages, ['package_name' => 'Paket', 'credits_total' => 'Toplam', 'credits_remaining' => 'Kalan', 'starts_at' => 'Başlangıç', 'expires_at' => 'Bitiş', 'status' => 'Durum', 'payment_status' => 'Ödeme']);
}

function render_simple_table(array $rows, array $columns): void
{
    ?>
    <section class="panel">
        <div class="table-wrap"><table><thead><tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><?php foreach ($columns as $key => $label): ?><td><?= e($row[$key] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="empty">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php
}

function render_flash(?array $flash): void
{
    if (!$flash) {
        return;
    }
    echo '<div class="flash ' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
}

function render_error(Throwable $e): void
{
    ?>
    <!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>"><title>Hata</title></head>
    <body class="auth-page"><main class="auth-card wide"><h1>Kurulum kontrolu gerekli</h1><p><?= e(friendly_error_message($e)) ?></p><p class="muted">Detayli kontrol icin <a href="<?= e(url_for('/health')) ?>">/health</a> adresini acin.</p></main></body></html>
    <?php
}

function customers(): array
{
    return DB::fetchAll('SELECT id, name FROM users WHERE role = "customer" AND status = "active" ORDER BY name');
}

function consultants(): array
{
    return DB::fetchAll('SELECT id, name FROM users WHERE role = "consultant" AND status = "active" ORDER BY name');
}

function options(array $rows, string $labelKey = 'name'): void
{
    foreach ($rows as $row) {
        echo '<option value="' . e($row['id']) . '">' . e($row[$labelKey] ?? $row['name'] ?? $row['id']) . '</option>';
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function selected(mixed $left, mixed $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function weekdays(): array
{
    return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar'];
}

function dt(string $value): string
{
    return (new DateTimeImmutable($value))->format('d.m.Y H:i');
}

function money(float|int|string $value): string
{
    return number_format((float) $value, 2, ',', '.') . ' TL';
}

function status_label(string $status): string
{
    return ['pending' => 'Bekliyor', 'confirmed' => 'Onaylı', 'cancelled' => 'İptal', 'completed' => 'Geldi', 'no_show' => 'Gelmedi'][$status] ?? $status;
}

function payment_label(string $status): string
{
    return ['paid' => 'Ödendi', 'pending' => 'Bekliyor', 'cancelled' => 'İptal'][$status] ?? $status;
}

function role_label_short(string $role): string
{
    return ['admin' => 'Admin', 'consultant' => 'Danışman', 'customer' => 'Müşteri'][$role] ?? $role;
}
