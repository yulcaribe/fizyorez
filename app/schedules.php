<?php

declare(strict_types=1);

final class ScheduleService
{
    public static function calendarSummaries(array $actor): array
    {
        self::upgradeDefaultHours();
        $consultants = self::visibleConsultants($actor);
        foreach ($consultants as $consultant) {
            self::ensureDefaultRange((int) $consultant['id'], new DateTimeImmutable('today'), new DateTimeImmutable('+180 days'));
        }

        $ids = array_map(static fn (array $item): int => (int) $item['id'], $consultants);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return DB::fetchAll(
            'SELECT u.id AS consultant_id, u.name AS consultant_name,
                    COUNT(DISTINCT CASE WHEN d.work_date >= CURDATE() AND d.is_working = 1 THEN d.work_date END) AS working_days,
                    COUNT(CASE WHEN d.work_date >= CURDATE() AND d.is_working = 1 THEN s.id END) AS active_periods,
                    MIN(CASE WHEN d.work_date >= CURDATE() AND d.is_working = 1 THEN d.work_date END) AS next_working_date,
                    MAX(d.work_date) AS generated_until
             FROM users u
             LEFT JOIN consultant_calendar_days d ON d.consultant_id = u.id
             LEFT JOIN consultant_calendar_slots s ON s.calendar_day_id = d.id
             WHERE u.id IN (' . $placeholders . ')
             GROUP BY u.id, u.name
             ORDER BY u.name',
            $ids
        );
    }

    public static function calendarDays(array $actor, int $consultantId, string $from, string $to): array
    {
        $consultantId = self::resolveConsultantId($actor, $consultantId);
        $start = self::normalizeDate($from);
        $end = self::normalizeDate($to);
        if ($start > $end || $start->diff($end)->days > 62) {
            throw new RuntimeException('Takvim görünümü en fazla 63 günlük bir aralık olabilir.');
        }

        self::ensureDefaultRange($consultantId, $start, $end);
        $rows = DB::fetchAll(
            'SELECT d.id AS day_id, d.work_date, d.is_working, d.source,
                    s.id AS slot_id, s.period, s.start_time, s.end_time
             FROM consultant_calendar_days d
             LEFT JOIN consultant_calendar_slots s ON s.calendar_day_id = d.id
             WHERE d.consultant_id = ? AND d.work_date BETWEEN ? AND ?
             ORDER BY d.work_date, s.start_time',
            [$consultantId, $start->format('Y-m-d'), $end->format('Y-m-d')]
        );

        $days = [];
        foreach ($rows as $row) {
            $date = (string) $row['work_date'];
            if (!isset($days[$date])) {
                $days[$date] = [
                    'day_id' => (int) $row['day_id'],
                    'work_date' => $date,
                    'is_working' => (int) $row['is_working'],
                    'source' => (string) $row['source'],
                    'slots' => [],
                ];
            }
            if ($row['slot_id'] !== null) {
                $days[$date]['slots'][] = [
                    'id' => (int) $row['slot_id'],
                    'period' => (string) $row['period'],
                    'start_time' => (string) $row['start_time'],
                    'end_time' => (string) $row['end_time'],
                ];
            }
        }

        return array_values($days);
    }

    public static function bookingMatrix(string $from, string $to): array
    {
        $start = self::normalizeDate($from);
        $end = self::normalizeDate($to);
        if ($start > $end || $start->diff($end)->days > 14) {
            throw new RuntimeException('Rezervasyon takvimi en fazla 15 günlük bir aralık olabilir.');
        }

        self::upgradeDefaultHours();
        $consultants = DB::fetchAll(
            'SELECT id, name FROM users WHERE role = "consultant" AND status = "active" ORDER BY name'
        );
        foreach ($consultants as $consultant) {
            self::ensureDefaultRange((int) $consultant['id'], $start, $end);
        }

        $days = [];
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $days[$date] = ['work_date' => $date, 'consultants' => []];
            foreach ($consultants as $consultant) {
                $days[$date]['consultants'][(int) $consultant['id']] = [
                    'is_working' => 0,
                    'slots' => [],
                ];
            }
        }

        $ids = array_map(static fn (array $item): int => (int) $item['id'], $consultants);
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::fetchAll(
                'SELECT d.consultant_id, d.work_date, d.is_working, s.start_time, s.end_time
                 FROM consultant_calendar_days d
                 LEFT JOIN consultant_calendar_slots s ON s.calendar_day_id = d.id
                 WHERE d.consultant_id IN (' . $placeholders . ') AND d.work_date BETWEEN ? AND ?
                 ORDER BY d.work_date, d.consultant_id, s.start_time',
                [...$ids, $start->format('Y-m-d'), $end->format('Y-m-d')]
            );
            foreach ($rows as $row) {
                $date = (string) $row['work_date'];
                $consultantId = (int) $row['consultant_id'];
                if (!isset($days[$date]['consultants'][$consultantId])) {
                    continue;
                }
                $days[$date]['consultants'][$consultantId]['is_working'] = (int) $row['is_working'];
                if ($row['start_time'] !== null && $row['end_time'] !== null) {
                    $days[$date]['consultants'][$consultantId]['slots'][] = [
                        'start_time' => (string) $row['start_time'],
                        'end_time' => (string) $row['end_time'],
                    ];
                }
            }
        }

        return ['consultants' => $consultants, 'days' => array_values($days)];
    }

    public static function saveDate(array $actor, array $data): void
    {
        $consultantId = self::resolveConsultantId($actor, (int) ($data['consultant_id'] ?? 0));
        $date = self::normalizeDate((string) ($data['work_date'] ?? ''));
        $slots = [];
        $workingRequested = !array_key_exists('is_working', $data) || !empty($data['is_working']);
        if ($workingRequested && !empty($data['morning'])) {
            $morningStart = self::normalizeTime((string) ($data['morning_start'] ?? '08:00'));
            $morningEnd = self::normalizeTime((string) ($data['morning_end'] ?? '12:00'));
            if ($morningStart >= $morningEnd) {
                throw new RuntimeException('Sabah çalışma başlangıcı bitişten önce olmalıdır.');
            }
            $slots[] = ['morning', $morningStart, $morningEnd];
        }
        if ($workingRequested && !empty($data['afternoon'])) {
            $afternoonStart = self::normalizeTime((string) ($data['afternoon_start'] ?? '13:00'));
            $afternoonEnd = self::normalizeTime((string) ($data['afternoon_end'] ?? '18:00'));
            if ($afternoonStart >= $afternoonEnd) {
                throw new RuntimeException('Öğleden sonra çalışma başlangıcı bitişten önce olmalıdır.');
            }
            $slots[] = ['afternoon', $afternoonStart, $afternoonEnd];
        }

        $customStartRaw = trim((string) ($data['custom_start'] ?? ''));
        $customEndRaw = trim((string) ($data['custom_end'] ?? ''));
        if ($workingRequested && ($customStartRaw !== '' || $customEndRaw !== '')) {
            if ($customStartRaw === '' || $customEndRaw === '') {
                throw new RuntimeException('Özel saat için başlangıç ve bitiş birlikte girilmelidir.');
            }
            $customStart = self::normalizeTime($customStartRaw);
            $customEnd = self::normalizeTime($customEndRaw);
            if ($customStart >= $customEnd) {
                throw new RuntimeException('Özel çalışma başlangıcı bitişten önce olmalıdır.');
            }
            $slots[] = ['custom', $customStart, $customEnd];
        }
        if ($workingRequested && $slots === []) {
            throw new RuntimeException('Çalışma günü için en az bir saat aralığı seçin.');
        }
        self::assertSlotsDoNotOverlap($slots);
        self::assertReservationsFit($consultantId, $date->format('Y-m-d'), $slots);

        DB::pdo()->beginTransaction();
        try {
            DB::execute(
                'INSERT INTO consultant_calendar_days (consultant_id, work_date, is_working, source, updated_by, created_at, updated_at)
                 VALUES (?, ?, ?, "manual", ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE is_working = VALUES(is_working), source = "manual", updated_by = VALUES(updated_by), updated_at = NOW()',
                [$consultantId, $date->format('Y-m-d'), $slots === [] ? 0 : 1, $actor['id']]
            );
            $day = DB::fetch('SELECT id FROM consultant_calendar_days WHERE consultant_id = ? AND work_date = ? FOR UPDATE', [$consultantId, $date->format('Y-m-d')]);
            DB::execute('DELETE FROM consultant_calendar_slots WHERE calendar_day_id = ?', [$day['id']]);
            foreach ($slots as [$period, $start, $end]) {
                DB::insert(
                    'INSERT INTO consultant_calendar_slots (calendar_day_id, period, start_time, end_time) VALUES (?, ?, ?, ?)',
                    [$day['id'], $period, $start, $end]
                );
            }
            Audit::record((int) $actor['id'], 'schedule.date_saved', 'consultant_calendar_day', (int) $day['id'], [
                'consultant_id' => $consultantId,
                'work_date' => $date->format('Y-m-d'),
                'periods' => array_column($slots, 0),
            ]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }
    }

    public static function clearDate(array $actor, array $data): void
    {
        $consultantId = self::resolveConsultantId($actor, (int) ($data['consultant_id'] ?? 0));
        $date = self::normalizeDate((string) ($data['work_date'] ?? ''));
        $workDate = $date->format('Y-m-d');
        self::assertReservationsFit($consultantId, $workDate, []);

        DB::pdo()->beginTransaction();
        try {
            DB::execute(
                'INSERT INTO consultant_calendar_days (consultant_id, work_date, is_working, source, updated_by, created_at, updated_at)
                 VALUES (?, ?, 0, "manual", ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE is_working = 0, source = "manual", updated_by = VALUES(updated_by), updated_at = NOW()',
                [$consultantId, $workDate, $actor['id']]
            );
            $day = DB::fetch(
                'SELECT id FROM consultant_calendar_days WHERE consultant_id = ? AND work_date = ? FOR UPDATE',
                [$consultantId, $workDate]
            );
            DB::execute('DELETE FROM consultant_calendar_slots WHERE calendar_day_id = ?', [$day['id']]);
            Audit::record((int) $actor['id'], 'schedule.date_cleared', 'consultant_calendar_day', (int) $day['id'], [
                'consultant_id' => $consultantId,
                'work_date' => $workDate,
            ]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }
    }

    public static function generateCalendar(array $actor, int $requestedId, string $from, string $to): void
    {
        $consultantId = self::resolveConsultantId($actor, $requestedId);
        $start = self::normalizeDate($from);
        $end = self::normalizeDate($to);
        if ($start > $end || $start->diff($end)->days > 366) {
            throw new RuntimeException('Takvim tek işlemde en fazla 367 gün için oluşturulabilir.');
        }
        self::ensureDefaultRange($consultantId, $start, $end);
        Audit::record((int) $actor['id'], 'schedule.calendar_generated', 'user', $consultantId, [
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
        ]);
    }

    public static function ensureDate(int $consultantId, string $date): void
    {
        $day = self::normalizeDate($date);
        self::ensureDefaultRange($consultantId, $day, $day);
    }

    public static function primeCalendar(int $consultantId, int $days = 180): void
    {
        $days = max(1, min(367, $days));
        self::ensureDefaultRange(
            $consultantId,
            new DateTimeImmutable('today'),
            new DateTimeImmutable('today +' . $days . ' days')
        );
    }

    public static function addTimeOff(array $actor, array $data): void
    {
        $consultantId = self::resolveTimeOffConsultantId($actor, (int) ($data['consultant_id'] ?? 0));
        $start = self::normalizeDateTime((string) ($data['start_at'] ?? ''));
        $end = self::normalizeDateTime((string) ($data['end_at'] ?? ''));
        if ($start >= $end) {
            throw new RuntimeException('İzin başlangıcı bitişten önce olmalıdır.');
        }

        $conflicts = DB::fetch(
            'SELECT COUNT(*) AS total FROM reservations
             WHERE consultant_id = ? AND status IN ("pending", "confirmed") AND starts_at < ? AND ends_at > ?',
            [$consultantId, $end, $start]
        );
        if ((int) ($conflicts['total'] ?? 0) > 0 && empty($data['confirm_conflicts'])) {
            throw new RuntimeException('Bu aralıkta aktif randevu var. Kontrol edip “çakışmaya rağmen kaydet” seçeneğini işaretleyin.');
        }

        $id = DB::insert(
            'INSERT INTO consultant_time_off (consultant_id, start_at, end_at, reason) VALUES (?, ?, ?, ?)',
            [$consultantId, $start, $end, trim((string) ($data['reason'] ?? ''))]
        );
        Audit::record((int) $actor['id'], 'schedule.time_off_added', 'consultant_time_off', $id, ['consultant_id' => $consultantId, 'conflicts' => (int) ($conflicts['total'] ?? 0)]);
    }

    public static function timeOff(array $actor): array
    {
        if ($actor['role'] === 'consultant' && !can($actor, 'time_off.manage_all')) {
            return DB::fetchAll(
                'SELECT t.*, u.name AS consultant_name FROM consultant_time_off t
                 INNER JOIN users u ON u.id = t.consultant_id WHERE t.consultant_id = ? ORDER BY t.start_at DESC',
                [$actor['id']]
            );
        }
        Authorization::require($actor, 'time_off.manage_all');

        return DB::fetchAll(
            'SELECT t.*, u.name AS consultant_name FROM consultant_time_off t
             INNER JOIN users u ON u.id = t.consultant_id ORDER BY t.start_at DESC'
        );
    }

    public static function deleteTimeOff(array $actor, int $timeOffId): void
    {
        $item = DB::fetch('SELECT * FROM consultant_time_off WHERE id = ?', [$timeOffId]);
        if (!$item) {
            throw new RuntimeException('İzin kaydı bulunamadı.');
        }
        self::assertCanManageTimeOff($actor, (int) $item['consultant_id']);
        DB::execute('DELETE FROM consultant_time_off WHERE id = ?', [$timeOffId]);
        Audit::record((int) $actor['id'], 'schedule.time_off_deleted', 'consultant_time_off', $timeOffId, ['consultant_id' => $item['consultant_id']]);
    }

    private static function visibleConsultants(array $actor): array
    {
        if ($actor['role'] === 'consultant' && !can($actor, 'schedules.view_all')) {
            Authorization::require($actor, 'schedules.manage_own');
            return DB::fetchAll('SELECT id, name FROM users WHERE id = ? AND role = "consultant" AND status = "active"', [$actor['id']]);
        }
        Authorization::require($actor, 'schedules.view_all');

        return DB::fetchAll('SELECT id, name FROM users WHERE role = "consultant" AND status = "active" ORDER BY name');
    }

    private static function ensureDefaultRange(int $consultantId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $expectedDays = (int) $start->diff($end)->days + 1;
        $existing = DB::fetch(
            'SELECT COUNT(*) AS total FROM consultant_calendar_days
             WHERE consultant_id = ? AND work_date BETWEEN ? AND ?',
            [$consultantId, $start->format('Y-m-d'), $end->format('Y-m-d')]
        );
        if ((int) ($existing['total'] ?? 0) === $expectedDays) {
            return;
        }

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $weekday = (int) $day->format('N');
            $working = $weekday <= 5 ? 1 : 0;
            $created = DB::execute(
                'INSERT IGNORE INTO consultant_calendar_days
                    (consultant_id, work_date, is_working, source, created_at, updated_at)
                 VALUES (?, ?, ?, "default", NOW(), NOW())',
                [$consultantId, $date, $working]
            );
            if ($created === 0 || $working === 0) {
                continue;
            }
            $calendarDay = DB::fetch('SELECT id FROM consultant_calendar_days WHERE consultant_id = ? AND work_date = ?', [$consultantId, $date]);
            DB::insert(
                'INSERT IGNORE INTO consultant_calendar_slots (calendar_day_id, period, start_time, end_time) VALUES (?, "morning", "08:00:00", "12:00:00")',
                [$calendarDay['id']]
            );
            DB::insert(
                'INSERT IGNORE INTO consultant_calendar_slots (calendar_day_id, period, start_time, end_time) VALUES (?, "afternoon", "13:00:00", "18:00:00")',
                [$calendarDay['id']]
            );
        }
    }

    private static function upgradeDefaultHours(): void
    {
        if (setting('calendar_default_hours_version', '1') === '2') {
            return;
        }

        DB::pdo()->beginTransaction();
        try {
            DB::execute(
                'UPDATE consultant_calendar_slots s
                 INNER JOIN consultant_calendar_days d ON d.id = s.calendar_day_id
                 SET s.start_time = CASE WHEN s.period = "morning" THEN "08:00:00" ELSE "13:00:00" END,
                     s.end_time = CASE WHEN s.period = "morning" THEN "12:00:00" ELSE "18:00:00" END
                 WHERE d.source = "default" AND s.period IN ("morning", "afternoon")'
            );
            save_setting('calendar_default_hours_version', '2');
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }
    }

    private static function assertReservationsFit(int $consultantId, string $date, array $slots): void
    {
        $reservations = DB::fetchAll(
            'SELECT starts_at, ends_at FROM reservations
             WHERE consultant_id = ? AND DATE(starts_at) = ? AND status IN ("pending", "confirmed")',
            [$consultantId, $date]
        );
        foreach ($reservations as $reservation) {
            $start = (new DateTimeImmutable((string) $reservation['starts_at']))->format('H:i:s');
            $end = (new DateTimeImmutable((string) $reservation['ends_at']))->format('H:i:s');
            if (!self::slotsCover($slots, $start, $end)) {
                throw new RuntimeException('Bu tarihte yeni çalışma saatlerinin dışında kalan aktif randevu var. Önce randevuyu taşıyın veya iptal edin.');
            }
        }
    }

    private static function slotsCover(array $slots, string $start, string $end): bool
    {
        usort($slots, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
        $coveredUntil = null;
        foreach ($slots as [, $slotStart, $slotEnd]) {
            if ($slotStart <= $start && $slotEnd > $start) {
                $coveredUntil = $coveredUntil === null || $slotEnd > $coveredUntil ? $slotEnd : $coveredUntil;
            } elseif ($coveredUntil !== null && $slotStart <= $coveredUntil && $slotEnd > $coveredUntil) {
                $coveredUntil = $slotEnd;
            }
            if ($coveredUntil !== null && $coveredUntil >= $end) {
                return true;
            }
        }

        return false;
    }

    private static function assertSlotsDoNotOverlap(array $slots): void
    {
        usort($slots, static fn (array $a, array $b): int => strcmp($a[1], $b[1]));
        $previousEnd = null;
        foreach ($slots as $slot) {
            if ($previousEnd !== null && $slot[1] < $previousEnd) {
                throw new RuntimeException('Çalışma saatleri birbiriyle çakışamaz.');
            }
            $previousEnd = $slot[2];
        }
    }

    private static function resolveConsultantId(array $actor, int $requestedId): int
    {
        if ($actor['role'] === 'consultant' && can($actor, 'schedules.manage_own')) {
            return (int) $actor['id'];
        }
        Authorization::require($actor, 'schedules.manage_all');
        $consultant = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "consultant" AND status = "active"', [$requestedId]);
        if (!$consultant) {
            throw new RuntimeException('Fizyoterapist bulunamadı.');
        }

        return $requestedId;
    }

    private static function resolveTimeOffConsultantId(array $actor, int $requestedId): int
    {
        if ($actor['role'] === 'consultant' && can($actor, 'time_off.manage_own')) {
            return (int) $actor['id'];
        }
        Authorization::require($actor, 'time_off.manage_all');
        $consultant = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "consultant" AND status = "active"', [$requestedId]);
        if (!$consultant) {
            throw new RuntimeException('Fizyoterapist bulunamadı.');
        }

        return $requestedId;
    }

    private static function assertCanManageTimeOff(array $actor, int $consultantId): void
    {
        if ($actor['role'] === 'consultant' && (int) $actor['id'] === $consultantId && can($actor, 'time_off.manage_own')) {
            return;
        }
        Authorization::require($actor, 'time_off.manage_all');
    }

    private static function normalizeDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Tarih formatı geçersiz.');
        }

        return $date;
    }

    private static function normalizeTime(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!H:i', substr($value, 0, 5));
        if (!$date) {
            throw new RuntimeException('Saat formatı geçersiz.');
        }

        return $date->format('H:i:s');
    }

    private static function normalizeDateTime(string $value): string
    {
        $value = str_replace('T', ' ', $value);
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
        if (!$date) {
            throw new RuntimeException('Tarih formatı geçersiz.');
        }

        return $date->format('Y-m-d H:i:s');
    }
}
