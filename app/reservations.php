<?php

declare(strict_types=1);

final class ReservationService
{
    private const ACTIVE_STATUSES = ['pending', 'confirmed'];

    public static function list(array $actor, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if ($actor['role'] === 'customer') {
            $where[] = 'r.customer_id = ?';
            $params[] = $actor['id'];
        } elseif ($actor['role'] === 'consultant' && !can($actor, 'reservations.view_all')) {
            $where[] = 'r.consultant_id = ?';
            $params[] = $actor['id'];
        } else {
            Authorization::require($actor, 'reservations.view_all');
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'r.starts_at >= ?';
            $params[] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'r.starts_at <= ?';
            $params[] = $filters['to'];
        }

        return DB::fetchAll(
            'SELECT r.*, s.name AS service_name, s.type AS service_type,
                    c.name AS customer_name, c.email AS customer_email,
                    k.name AS consultant_name, k.email AS consultant_email
             FROM reservations r
             INNER JOIN services s ON s.id = r.service_id
             INNER JOIN users c ON c.id = r.customer_id
             INNER JOIN users k ON k.id = r.consultant_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY r.starts_at DESC
             LIMIT 300',
            $params
        );
    }

    public static function create(array $actor, array $data): array
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $consultantId = (int) ($data['consultant_id'] ?? 0);
        $serviceId = (int) ($data['service_id'] ?? 0);
        $reservationType = (string) ($data['reservation_type'] ?? 'package');
        $startsAt = self::normalizeDateTime((string) ($data['starts_at'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        if ($actor['role'] === 'customer') {
            $customerId = (int) $actor['id'];
        }
        if ($actor['role'] === 'consultant') {
            $consultantId = (int) $actor['id'];
        }

        self::assertCanManagePair($actor, $customerId, $consultantId);

        $customer = self::findActiveUser($customerId, 'customer');
        $consultant = self::findActiveUser($consultantId, 'consultant');
        $service = DB::fetch('SELECT * FROM services WHERE id = ? AND active = 1', [$serviceId]);
        if (!$customer || !$consultant || !$service) {
            throw new RuntimeException('Danışan, fizyoterapist veya hizmet bulunamadı.');
        }

        $start = new DateTimeImmutable($startsAt);
        if ($start < new DateTimeImmutable('-5 minutes')) {
            throw new RuntimeException('Geçmiş tarihli rezervasyon oluşturulamaz.');
        }

        $end = $start->modify('+' . (int) $service['duration_minutes'] . ' minutes');
        $endsAt = $end->format('Y-m-d H:i:s');

        DB::pdo()->beginTransaction();
        try {
            self::lockActors($customerId, $consultantId);
            self::assertSlotAvailable($customerId, $consultantId, $service, $startsAt, $endsAt);

            $customerPackageId = null;
            $creditsDeducted = 0;
            $paymentStatus = 'pending';
            $price = (float) $service['price'];

            if ($reservationType === 'package') {
                $package = self::resolveCustomerPackage($customerId, (int) ($data['customer_package_id'] ?? 0));
                $customerPackageId = (int) $package['id'];
                $paymentStatus = (string) $package['payment_status'];
                $price = 0.0;

                if (self::creditPolicy() !== 'on_booking' && self::availablePackageCredits($package) < 1) {
                    throw new RuntimeException('Bu paket için kullanılabilir hak bulunmuyor.');
                }
            } elseif ($reservationType !== 'single') {
                throw new RuntimeException('Rezervasyon tipi geçersiz.');
            }

            $reservationId = DB::insert(
                'INSERT INTO reservations
                    (customer_id, consultant_id, service_id, customer_package_id, reservation_type, starts_at, ends_at, status, payment_status, price, credits_deducted, created_by, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "confirmed", ?, ?, ?, ?, ?, NOW(), NOW())',
                [$customerId, $consultantId, $serviceId, $customerPackageId, $reservationType, $startsAt, $endsAt, $paymentStatus, $price, $creditsDeducted, $actor['id'], $notes]
            );

            if ($reservationType === 'package' && self::creditPolicy() === 'on_booking') {
                CreditLedger::debit($customerPackageId, 1, 'reservation_booking', (int) $actor['id'], $reservationId, 'Rezervasyon oluşturulurken düşüldü.');
                DB::execute('UPDATE reservations SET credits_deducted = 1 WHERE id = ?', [$reservationId]);
            }
            Audit::record((int) $actor['id'], 'reservation.created', 'reservation', $reservationId, ['type' => $reservationType]);

            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        Mailer::queueReservationMail($reservationId, 'reservation_created');

        return self::find($reservationId);
    }

    public static function reschedule(array $actor, int $reservationId, string $newStartsAt): array
    {
        $reservation = self::find($reservationId);
        if (!$reservation) {
            throw new RuntimeException('Rezervasyon bulunamadı.');
        }

        self::assertReservationAccess($actor, $reservation);
        if (!can($actor, 'reservations.manage_all') && self::isPastChangeDeadline((string) $reservation['starts_at'], (int) $reservation['consultant_id'])) {
            throw new RuntimeException('Bu rezervasyon için değişiklik süresi geçmiş.');
        }
        if (!in_array($reservation['status'], self::ACTIVE_STATUSES, true)) {
            throw new RuntimeException('Tamamlanmış veya iptal edilmiş rezervasyon taşınamaz.');
        }

        $startsAt = self::normalizeDateTime($newStartsAt);
        $service = DB::fetch('SELECT * FROM services WHERE id = ?', [$reservation['service_id']]);
        if (!$service) {
            throw new RuntimeException('Hizmet bulunamadı.');
        }

        $start = new DateTimeImmutable($startsAt);
        if ($start < new DateTimeImmutable('-5 minutes')) {
            throw new RuntimeException('Geçmiş tarihli rezervasyon oluşturulamaz.');
        }
        $endsAt = $start->modify('+' . (int) $service['duration_minutes'] . ' minutes')->format('Y-m-d H:i:s');

        DB::pdo()->beginTransaction();
        try {
            DB::fetch('SELECT id FROM reservations WHERE id = ? FOR UPDATE', [$reservationId]);
            self::lockActors((int) $reservation['customer_id'], (int) $reservation['consultant_id']);
            self::assertSlotAvailable((int) $reservation['customer_id'], (int) $reservation['consultant_id'], $service, $startsAt, $endsAt, $reservationId);
            DB::execute(
                'UPDATE reservations SET starts_at = ?, ends_at = ?, updated_at = NOW() WHERE id = ?',
                [$startsAt, $endsAt, $reservationId]
            );
            Audit::record((int) $actor['id'], 'reservation.rescheduled', 'reservation', $reservationId, ['starts_at' => $startsAt]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        Mailer::queueReservationMail($reservationId, 'reservation_updated');

        return self::find($reservationId);
    }

    public static function cancel(array $actor, int $reservationId): array
    {
        $reservation = self::find($reservationId);
        if (!$reservation) {
            throw new RuntimeException('Rezervasyon bulunamadı.');
        }

        self::assertReservationAccess($actor, $reservation);
        if (in_array($reservation['status'], ['cancelled', 'completed', 'no_show'], true)) {
            throw new RuntimeException('Bu rezervasyon artık iptal edilemez.');
        }

        $late = self::isPastChangeDeadline((string) $reservation['starts_at'], (int) $reservation['consultant_id']);
        $burnLateCredit = (bool) (int) setting('late_cancel_burn_credit', '1');

        DB::pdo()->beginTransaction();
        try {
            $locked = DB::fetch('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$reservationId]);
            if (!$locked || !in_array($locked['status'], self::ACTIVE_STATUSES, true)) {
                throw new RuntimeException('Bu rezervasyon artık iptal edilemez.');
            }
            if ((int) $locked['credits_deducted'] === 1 && (!$late || !$burnLateCredit)) {
                CreditLedger::credit((int) $locked['customer_package_id'], 1, 'reservation_cancelled', (int) $actor['id'], $reservationId, 'İptal edilen rezervasyon hakkı iade edildi.');
            }

            DB::execute(
                'UPDATE reservations SET status = "cancelled", late_cancelled = ?, updated_at = NOW() WHERE id = ?',
                [$late ? 1 : 0, $reservationId]
            );
            Audit::record((int) $actor['id'], 'reservation.cancelled', 'reservation', $reservationId, ['late' => $late, 'credit_returned' => !$late || !$burnLateCredit]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        Mailer::queueReservationMail($reservationId, 'reservation_cancelled');

        return self::find($reservationId);
    }

    public static function setStatus(array $actor, int $reservationId, string $status): array
    {
        if (!can($actor, 'reservations.manage_all') && !can($actor, 'reservations.manage_own')) {
            throw new RuntimeException('Bu işlem için yetkiniz yok.');
        }

        $allowed = ['pending', 'confirmed', 'completed', 'no_show'];
        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Rezervasyon durumu geçersiz.');
        }

        $reservation = self::find($reservationId);
        if (!$reservation) {
            throw new RuntimeException('Rezervasyon bulunamadı.');
        }

        self::assertReservationAccess($actor, $reservation);
        if ($reservation['status'] === 'cancelled') {
            throw new RuntimeException('İptal edilmiş rezervasyon güncellenemez.');
        }
        if (in_array($reservation['status'], ['completed', 'no_show'], true) && $reservation['status'] !== $status) {
            throw new RuntimeException('Sonuçlandırılmış seans durumu doğrudan geri alınamaz; gerekirse hak düzeltme kaydı oluşturun.');
        }

        DB::pdo()->beginTransaction();
        try {
            $locked = DB::fetch('SELECT * FROM reservations WHERE id = ? FOR UPDATE', [$reservationId]);
            if (!$locked || $locked['status'] === 'cancelled') {
                throw new RuntimeException('İptal edilmiş rezervasyon güncellenemez.');
            }
            if (in_array($locked['status'], ['completed', 'no_show'], true) && $locked['status'] !== $status) {
                throw new RuntimeException('Sonuçlandırılmış seans durumu doğrudan geri alınamaz.');
            }
            if (in_array($status, ['completed', 'no_show'], true) && $locked['reservation_type'] === 'package' && (int) $locked['credits_deducted'] === 0) {
                $reason = $status === 'completed' ? 'session_completed' : 'session_no_show';
                CreditLedger::debit((int) $locked['customer_package_id'], 1, $reason, (int) $actor['id'], $reservationId, 'Katılım durumuna göre hak düşüldü.');
                DB::execute('UPDATE reservations SET credits_deducted = 1 WHERE id = ?', [$reservationId]);
            }

            DB::execute('UPDATE reservations SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $reservationId]);
            Audit::record((int) $actor['id'], 'reservation.status_updated', 'reservation', $reservationId, ['status' => $status]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        if ($status === 'completed') {
            Mailer::queueReservationMail($reservationId, 'reservation_completed');
        } elseif ($status === 'no_show') {
            Mailer::queueReservationMail($reservationId, 'reservation_no_show');
        }

        return self::find($reservationId);
    }

    public static function find(int $reservationId): ?array
    {
        return DB::fetch(
            'SELECT r.*, s.name AS service_name, s.type AS service_type, s.duration_minutes,
                    c.name AS customer_name, c.email AS customer_email,
                    k.name AS consultant_name, k.email AS consultant_email
             FROM reservations r
             INNER JOIN services s ON s.id = r.service_id
             INNER JOIN users c ON c.id = r.customer_id
             INNER JOIN users k ON k.id = r.consultant_id
             WHERE r.id = ?',
            [$reservationId]
        );
    }

    private static function assertCanManagePair(array $actor, int $customerId, int $consultantId): void
    {
        if ($actor['role'] === 'customer' && (int) $actor['id'] !== $customerId) {
            throw new RuntimeException('Sadece kendi hesabınız için rezervasyon oluşturabilirsiniz.');
        }

        if ($actor['role'] === 'consultant' && (int) $actor['id'] !== $consultantId) {
            throw new RuntimeException('Sadece kendi takviminiz için rezervasyon oluşturabilirsiniz.');
        }

        if (!in_array($actor['role'], ['customer', 'consultant'], true)) {
            Authorization::require($actor, 'reservations.manage_all');
        } elseif ($actor['role'] === 'consultant') {
            Authorization::require($actor, 'reservations.manage_own');
        }
    }

    private static function assertReservationAccess(array $actor, array $reservation): void
    {
        if (can($actor, 'reservations.manage_all')) {
            return;
        }

        if ($actor['role'] === 'customer' && (int) $actor['id'] === (int) $reservation['customer_id']) {
            return;
        }

        if ($actor['role'] === 'consultant' && (int) $actor['id'] === (int) $reservation['consultant_id']) {
            return;
        }

        throw new RuntimeException('Bu rezervasyona erişim yetkiniz yok.');
    }

    private static function assertSlotAvailable(int $customerId, int $consultantId, array $service, string $startsAt, string $endsAt, ?int $ignoreReservationId = null): void
    {
        self::assertConsultantAvailability($consultantId, $startsAt, $endsAt);

        $ignoreSql = $ignoreReservationId ? ' AND id != ?' : '';
        $params = [$customerId, $endsAt, $startsAt];
        if ($ignoreReservationId) {
            $params[] = $ignoreReservationId;
        }

        $customerConflict = DB::fetch(
            'SELECT id FROM reservations
             WHERE customer_id = ?
             AND status IN ("pending", "confirmed")
             AND starts_at < ? AND ends_at > ?' . $ignoreSql . '
             LIMIT 1',
            $params
        );
        if ($customerConflict) {
            throw new RuntimeException('Danışanın bu saat aralığında başka rezervasyonu var.');
        }

        $serviceType = (string) $service['type'];
        if ($serviceType === 'group') {
            $conflictParams = [$consultantId, $endsAt, $startsAt, $service['id'], $startsAt, $endsAt];
            $conflictSql = 'SELECT id FROM reservations
                WHERE consultant_id = ?
                AND status IN ("pending", "confirmed")
                AND starts_at < ? AND ends_at > ?
                AND NOT (service_id = ? AND starts_at = ? AND ends_at = ?)';
            if ($ignoreReservationId) {
                $conflictSql .= ' AND id != ?';
                $conflictParams[] = $ignoreReservationId;
            }
            $conflictSql .= ' LIMIT 1';

            if (DB::fetch($conflictSql, $conflictParams)) {
                throw new RuntimeException('Fizyoterapistin bu saat aralığında başka bir seansı var.');
            }

            $capacityParams = [$service['id'], $consultantId, $startsAt, $endsAt];
            $capacitySql = 'SELECT COUNT(*) AS total FROM reservations
                WHERE service_id = ? AND consultant_id = ? AND starts_at = ? AND ends_at = ?
                AND status IN ("pending", "confirmed")';
            if ($ignoreReservationId) {
                $capacitySql .= ' AND id != ?';
                $capacityParams[] = $ignoreReservationId;
            }

            $count = (int) (DB::fetch($capacitySql, $capacityParams)['total'] ?? 0);
            if ($count >= (int) $service['capacity']) {
                throw new RuntimeException('Grup dersi kapasitesi dolu.');
            }

            return;
        }

        $consultantParams = [$consultantId, $endsAt, $startsAt];
        if ($ignoreReservationId) {
            $consultantParams[] = $ignoreReservationId;
        }
        $consultantConflict = DB::fetch(
            'SELECT id FROM reservations
             WHERE consultant_id = ?
             AND status IN ("pending", "confirmed")
             AND starts_at < ? AND ends_at > ?' . $ignoreSql . '
             LIMIT 1',
            $consultantParams
        );
        if ($consultantConflict) {
            throw new RuntimeException('Fizyoterapistin bu saat aralığında başka rezervasyonu var.');
        }
    }

    private static function assertConsultantAvailability(int $consultantId, string $startsAt, string $endsAt): void
    {
        $start = new DateTimeImmutable($startsAt);
        $end = new DateTimeImmutable($endsAt);
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            throw new RuntimeException('Rezervasyon aynı takvim günü içinde başlayıp bitmelidir.');
        }
        ScheduleService::ensureDate($consultantId, $start->format('Y-m-d'));
        $startTime = $start->format('H:i:s');
        $endTime = $end->format('H:i:s');

        $availableSlots = DB::fetchAll(
            'SELECT s.start_time, s.end_time
             FROM consultant_calendar_days d
             INNER JOIN consultant_calendar_slots s ON s.calendar_day_id = d.id
             WHERE d.consultant_id = ? AND d.work_date = ? AND d.is_working = 1
             ORDER BY s.start_time',
            [$consultantId, $start->format('Y-m-d')]
        );
        $coveredUntil = null;
        foreach ($availableSlots as $slot) {
            $slotStart = (string) $slot['start_time'];
            $slotEnd = (string) $slot['end_time'];
            if ($slotStart <= $startTime && $slotEnd > $startTime) {
                $coveredUntil = $coveredUntil === null || $slotEnd > $coveredUntil ? $slotEnd : $coveredUntil;
            } elseif ($coveredUntil !== null && $slotStart <= $coveredUntil && $slotEnd > $coveredUntil) {
                $coveredUntil = $slotEnd;
            }
            if ($coveredUntil !== null && $coveredUntil >= $endTime) {
                break;
            }
        }
        if ($coveredUntil === null || $coveredUntil < $endTime) {
            throw new RuntimeException('Fizyoterapist seçtiğiniz tarih ve saat aralığında müsait değil.');
        }

        $timeOff = DB::fetch(
            'SELECT id FROM consultant_time_off
             WHERE consultant_id = ? AND start_at < ? AND end_at > ?
             LIMIT 1',
            [$consultantId, $endsAt, $startsAt]
        );
        if ($timeOff) {
            throw new RuntimeException('Fizyoterapistin bu saat aralığında izin/molası var.');
        }
    }

    private static function resolveCustomerPackage(int $customerId, int $requestedPackageId = 0): array
    {
        if ($requestedPackageId > 0) {
            $package = DB::fetch(
                'SELECT cp.*, p.name AS package_name
                 FROM customer_packages cp
                 INNER JOIN packages p ON p.id = cp.package_id
                 WHERE cp.id = ? AND cp.customer_id = ? AND cp.status = "active" AND cp.expires_at >= CURDATE()
                 LIMIT 1',
                [$requestedPackageId, $customerId]
            );
        } else {
            $package = DB::fetch(
                'SELECT cp.*, p.name AS package_name
                 FROM customer_packages cp
                 INNER JOIN packages p ON p.id = cp.package_id
                 WHERE cp.customer_id = ? AND cp.status = "active" AND cp.expires_at >= CURDATE()
                 ORDER BY cp.expires_at ASC, cp.id ASC
                 LIMIT 1',
                [$customerId]
            );
        }

        if (!$package || self::availablePackageCredits($package) < 1) {
            throw new RuntimeException('Danışanın aktif ve kullanılabilir paketi yok.');
        }

        return $package;
    }

    private static function availablePackageCredits(array $package): int
    {
        $remaining = (int) $package['credits_remaining'];
        if (self::creditPolicy() === 'on_booking') {
            return $remaining;
        }

        $reserved = (int) (DB::fetch(
            'SELECT COUNT(*) AS total FROM reservations
             WHERE customer_package_id = ? AND credits_deducted = 0
             AND status IN ("pending", "confirmed")',
            [$package['id']]
        )['total'] ?? 0);

        return max(0, $remaining - $reserved);
    }

    private static function lockActors(int $customerId, int $consultantId): void
    {
        $ids = [$customerId, $consultantId];
        sort($ids);
        DB::fetchAll('SELECT id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE', $ids);
    }

    private static function findActiveUser(int $id, string $role): ?array
    {
        return DB::fetch('SELECT * FROM users WHERE id = ? AND role = ? AND status = "active"', [$id, $role]);
    }

    private static function normalizeDateTime(string $value): string
    {
        if (!$value) {
            throw new RuntimeException('Tarih bilgisi zorunlu.');
        }

        $value = str_replace('T', ' ', $value);
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);

        if (!$date) {
            throw new RuntimeException('Tarih formatı geçersiz.');
        }

        return $date->format('Y-m-d H:i:s');
    }

    private static function isPastChangeDeadline(string $startsAt, int $consultantId): bool
    {
        $deadlineHours = self::deadlineHours($consultantId);
        $deadline = (new DateTimeImmutable($startsAt))->modify('-' . $deadlineHours . ' hours');

        return new DateTimeImmutable() > $deadline;
    }

    private static function deadlineHours(int $consultantId): int
    {
        $profile = DB::fetch('SELECT booking_deadline_hours FROM consultant_profiles WHERE user_id = ?', [$consultantId]);
        if ($profile && $profile['booking_deadline_hours'] !== null && $profile['booking_deadline_hours'] !== '') {
            return max(0, (int) $profile['booking_deadline_hours']);
        }

        return max(0, (int) setting('booking_change_deadline_hours', '12'));
    }

    private static function creditPolicy(): string
    {
        $policy = (string) setting('credit_deduction_policy', 'on_booking');

        return in_array($policy, ['on_booking', 'on_attendance'], true) ? $policy : 'on_booking';
    }
}
