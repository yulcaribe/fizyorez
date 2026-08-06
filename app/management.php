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
        ];
    }

    public static function createUser(array $data): array
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

    public static function saveService(?int $id, array $data): array
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

    public static function savePackage(?int $id, array $data): array
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

    public static function assignPackage(array $data): array
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

        return DB::fetch(self::customerPackageSql() . ' WHERE cp.id = ?', [$id]);
    }

    public static function customerPackageSql(): string
    {
        return 'SELECT cp.*, p.name AS package_name, u.name AS customer_name
                FROM customer_packages cp
                INNER JOIN packages p ON p.id = cp.package_id
                INNER JOIN users u ON u.id = cp.customer_id';
    }

    public static function reportSummary(array $user): array
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
}
