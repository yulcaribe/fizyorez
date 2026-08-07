<?php

declare(strict_types=1);

final class Management
{
    public static function publicSettings(): array
    {
        return [
            'booking_change_deadline_hours' => setting('booking_change_deadline_hours', '12'),
            'late_cancel_burn_credit' => setting('late_cancel_burn_credit', '1'),
            'credit_deduction_policy' => setting('credit_deduction_policy', 'on_booking'),
            'reservation_reminder_hours' => setting('reservation_reminder_hours', '24'),
            'currency' => setting('currency', 'TRY'),
            'bank_name' => setting('bank_name', ''),
            'bank_iban' => setting('bank_iban', ''),
            'bank_account_name' => setting('bank_account_name', ''),
            'test_card_number' => setting('test_card_number', '4242424242424242'),
            'test_card_expiry' => setting('test_card_expiry', '12/30'),
            'test_card_cvv' => setting('test_card_cvv', '123'),
        ];
    }

    public static function createUser(array $actor, array $data): array
    {
        Authorization::require($actor, 'users.create');
        $role = (string) ($data['role'] ?? '');
        if (!in_array($role, ['admin', 'consultant', 'staff', 'customer'], true)) {
            throw new RuntimeException('Kullanıcı rolü geçersiz.');
        }
        if ($role === 'admin' && $actor['role'] !== 'super_admin') {
            throw new RuntimeException('Yönetici hesabını yalnızca süper yönetici oluşturabilir.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            throw new RuntimeException('Ad, geçerli e-posta ve en az 8 karakterli şifre zorunludur.');
        }
        if (DB::fetch('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new RuntimeException('Bu e-posta adresi zaten kullanılıyor.');
        }

        DB::pdo()->beginTransaction();
        try {
            $id = DB::insert(
                'INSERT INTO users (role, name, email, phone, password_hash, status, created_at) VALUES (?, ?, ?, ?, ?, "active", NOW())',
                [$role, $name, $email, trim((string) ($data['phone'] ?? '')), password_hash($password, PASSWORD_DEFAULT)]
            );
            if ($role === 'consultant') {
                DB::execute(
                    'INSERT INTO consultant_profiles (user_id, title, bio, color, booking_deadline_hours) VALUES (?, ?, ?, ?, ?)',
                    [$id, trim((string) ($data['title'] ?? 'Fizyoterapist')), '', (string) ($data['color'] ?? '#0f766e'), $data['booking_deadline_hours'] ?: null]
                );
            }
            Audit::record((int) $actor['id'], 'user.created', 'user', $id, ['role' => $role]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        if ($role === 'consultant') {
            ScheduleService::primeCalendar($id);
        } elseif ($role === 'customer') {
            WalletService::ensureWallet($id);
        }

        return (array) DB::fetch('SELECT id, role, name, email, phone, status, created_at FROM users WHERE id = ?', [$id]);
    }

    public static function updateUser(array $actor, int $userId, array $data): array
    {
        Authorization::require($actor, 'users.update');
        $target = DB::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$target) {
            throw new RuntimeException('Kullanıcı bulunamadı.');
        }
        if ($target['role'] === 'super_admin' && (int) $actor['id'] !== $userId) {
            throw new RuntimeException('Süper yönetici hesabı başka bir kullanıcı tarafından değiştirilemez.');
        }
        if ($target['role'] === 'admin' && $actor['role'] !== 'super_admin') {
            throw new RuntimeException('Yönetici hesabını yalnızca süper yönetici değiştirebilir.');
        }

        $role = (string) ($data['role'] ?? $target['role']);
        $status = (string) ($data['status'] ?? $target['status']);
        if ($target['role'] === 'super_admin') {
            $role = 'super_admin';
            $status = 'active';
        }
        if (!in_array($role, ['super_admin', 'admin', 'consultant', 'staff', 'customer'], true)
            || !in_array($status, ['pending', 'active', 'suspended', 'passive'], true)) {
            throw new RuntimeException('Rol veya hesap durumu geçersiz.');
        }
        if ($role !== $target['role']) {
            Authorization::require($actor, 'users.change_role');
            if ($actor['role'] !== 'super_admin' && in_array($role, ['super_admin', 'admin'], true)) {
                throw new RuntimeException('Bu yönetici rolünü atama yetkiniz yok.');
            }
        }
        if ($status !== $target['status']) {
            Authorization::require($actor, 'users.change_status');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ad soyad ve geçerli e-posta zorunludur.');
        }
        $duplicate = DB::fetch('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $userId]);
        if ($duplicate) {
            throw new RuntimeException('Bu e-posta adresi başka bir kullanıcıya ait.');
        }

        $params = [$name, $email, trim((string) ($data['phone'] ?? '')), $role, $status];
        $passwordSql = '';
        if (!empty($data['password'])) {
            if (strlen((string) $data['password']) < 8) {
                throw new RuntimeException('Yeni şifre en az 8 karakter olmalıdır.');
            }
            $passwordSql = ', password_hash = ?';
            $params[] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        $params[] = $userId;
        DB::execute('UPDATE users SET name = ?, email = ?, phone = ?, role = ?, status = ?' . $passwordSql . ' WHERE id = ?', $params);

        if ($role === 'consultant' && !DB::fetch('SELECT user_id FROM consultant_profiles WHERE user_id = ?', [$userId])) {
            DB::execute('INSERT INTO consultant_profiles (user_id, title, bio, color) VALUES (?, "Fizyoterapist", "", "#0f766e")', [$userId]);
        }
        if ($role === 'consultant') {
            ScheduleService::primeCalendar($userId);
        } elseif ($role === 'customer') {
            WalletService::ensureWallet($userId);
        }
        Audit::record((int) $actor['id'], 'user.updated', 'user', $userId, ['role' => $role, 'status' => $status]);

        return (array) DB::fetch('SELECT id, role, name, email, phone, status, created_at FROM users WHERE id = ?', [$userId]);
    }

    public static function saveService(array $actor, ?int $id, array $data): array
    {
        Authorization::require($actor, 'services.manage');
        $name = trim((string) ($data['name'] ?? ''));
        $duration = (int) ($data['duration_minutes'] ?? 0);
        $type = in_array(($data['type'] ?? 'one_to_one'), ['one_to_one', 'group'], true) ? $data['type'] : 'one_to_one';
        $capacity = $type === 'group' ? max(2, (int) ($data['capacity'] ?? 2)) : 1;
        if ($name === '' || $duration < 10 || $duration > 480) {
            throw new RuntimeException('Hizmet adı ve geçerli seans süresi zorunludur.');
        }

        if ($id) {
            DB::execute(
                'UPDATE services SET name = ?, description = ?, type = ?, duration_minutes = ?, capacity = ?, price = ?, active = ? WHERE id = ?',
                [$name, trim((string) ($data['description'] ?? '')), $type, $duration, $capacity, max(0, (float) ($data['price'] ?? 0)), (int) ($data['active'] ?? 1), $id]
            );
        } else {
            $id = DB::insert(
                'INSERT INTO services (name, description, type, duration_minutes, capacity, price, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$name, trim((string) ($data['description'] ?? '')), $type, $duration, $capacity, max(0, (float) ($data['price'] ?? 0)), (int) ($data['active'] ?? 1)]
            );
        }
        Audit::record((int) $actor['id'], 'service.saved', 'service', $id);

        return (array) DB::fetch('SELECT * FROM services WHERE id = ?', [$id]);
    }

    public static function savePackage(array $actor, ?int $id, array $data): array
    {
        Authorization::require($actor, 'packages.manage');
        $name = trim((string) ($data['name'] ?? ''));
        $credits = (int) ($data['total_credits'] ?? 0);
        $days = (int) ($data['validity_days'] ?? 0);
        if ($name === '' || $credits < 1 || $days < 1) {
            throw new RuntimeException('Paket adı, hak sayısı ve geçerlilik süresi zorunludur.');
        }
        $values = [$name, trim((string) ($data['description'] ?? '')), $credits, $days, max(0, (float) ($data['price'] ?? 0)), (int) ($data['active'] ?? 1)];
        if ($id) {
            DB::execute('UPDATE packages SET name = ?, description = ?, total_credits = ?, validity_days = ?, price = ?, active = ? WHERE id = ?', [...$values, $id]);
        } else {
            $id = DB::insert('INSERT INTO packages (name, description, total_credits, validity_days, price, active, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())', $values);
        }
        Audit::record((int) $actor['id'], 'package.saved', 'package', $id);

        return (array) DB::fetch('SELECT * FROM packages WHERE id = ?', [$id]);
    }

    public static function assignPackage(array $actor, array $data): array
    {
        Authorization::require($actor, 'packages.manage');
        $startsAt = (string) ($data['starts_at'] ?? date('Y-m-d'));

        return PaymentService::requestPackageForCustomer(
            $actor,
            (int) ($data['customer_id'] ?? 0),
            (int) ($data['package_id'] ?? 0),
            $startsAt
        );
    }

    public static function customerPackageSql(): string
    {
        return 'SELECT cp.*, p.name AS package_name, p.price AS package_price, u.name AS customer_name
                FROM customer_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN users u ON u.id = cp.customer_id';
    }

    public static function reportSummary(array $user): array
    {
        $consultantFilter = '';
        $params = [];
        if ($user['role'] === 'consultant' && !can($user, 'reservations.view_all')) {
            $consultantFilter = ' AND consultant_id = ?';
            $params[] = $user['id'];
        }

        $today = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE DATE(starts_at) = CURDATE() AND status IN ("pending", "confirmed")' . $consultantFilter, $params);
        $upcoming = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE starts_at >= NOW() AND status IN ("pending", "confirmed")' . $consultantFilter, $params);
        $completed = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status = "completed" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $consultantFilter, $params);
        $cancelled = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status = "cancelled" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $consultantFilter, $params);
        $noShow = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status = "no_show" AND starts_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $consultantFilter, $params);
        $unpaid = DB::fetch('SELECT COUNT(*) AS total FROM reservations WHERE status IN ("pending", "confirmed") AND payment_status = "pending"' . $consultantFilter, $params);
        $netMovement = can($user, 'payments.view_all')
            ? DB::fetch(
                'SELECT COALESCE(SUM(
                    CASE
                        WHEN direction = "credit" THEN amount
                        ELSE -amount
                    END
                 ), 0) AS total
                 FROM financial_transactions
                 WHERE wallet_applied = 1
                   AND status IN ("approved", "refunded")
                   AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            )
            : ['total' => 0];

        return [
            'today_reservations' => (int) ($today['total'] ?? 0),
            'upcoming_reservations' => (int) ($upcoming['total'] ?? 0),
            'completed_last_30_days' => (int) ($completed['total'] ?? 0),
            'cancelled_last_30_days' => (int) ($cancelled['total'] ?? 0),
            'no_show_last_30_days' => (int) ($noShow['total'] ?? 0),
            'unpaid_reservations' => (int) ($unpaid['total'] ?? 0),
            'net_wallet_movement_last_30_days' => (float) ($netMovement['total'] ?? 0),
        ];
    }
}
