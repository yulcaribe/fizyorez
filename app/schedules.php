<?php

declare(strict_types=1);

final class ScheduleService
{
    public static function availability(array $actor): array
    {
        if ($actor['role'] === 'consultant' && !can($actor, 'schedules.view_all')) {
            return DB::fetchAll(
                'SELECT a.*, u.name AS consultant_name FROM consultant_availability a
                 INNER JOIN users u ON u.id = a.consultant_id WHERE a.consultant_id = ? ORDER BY a.weekday, a.start_time',
                [$actor['id']]
            );
        }
        Authorization::require($actor, 'schedules.view_all');

        return DB::fetchAll(
            'SELECT a.*, u.name AS consultant_name FROM consultant_availability a
             INNER JOIN users u ON u.id = a.consultant_id ORDER BY u.name, a.weekday, a.start_time'
        );
    }

    public static function addAvailability(array $actor, array $data): void
    {
        $consultantId = self::resolveConsultantId($actor, (int) ($data['consultant_id'] ?? 0));
        $weekday = (int) ($data['weekday'] ?? 0);
        $start = self::normalizeTime((string) ($data['start_time'] ?? ''));
        $end = self::normalizeTime((string) ($data['end_time'] ?? ''));
        if ($weekday < 1 || $weekday > 7 || $start >= $end) {
            throw new RuntimeException('Çalışma günü veya saat aralığı geçersiz.');
        }

        $overlap = DB::fetch(
            'SELECT id FROM consultant_availability
             WHERE consultant_id = ? AND weekday = ? AND is_active = 1 AND start_time < ? AND end_time > ? LIMIT 1',
            [$consultantId, $weekday, $end, $start]
        );
        if ($overlap) {
            throw new RuntimeException('Bu çalışma aralığı mevcut programla çakışıyor.');
        }

        $id = DB::insert(
            'INSERT INTO consultant_availability (consultant_id, weekday, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)',
            [$consultantId, $weekday, $start, $end]
        );
        Audit::record((int) $actor['id'], 'schedule.availability_added', 'consultant_availability', $id, ['consultant_id' => $consultantId]);
    }

    public static function deleteAvailability(array $actor, int $availabilityId): void
    {
        $item = DB::fetch('SELECT * FROM consultant_availability WHERE id = ?', [$availabilityId]);
        if (!$item) {
            throw new RuntimeException('Müsaitlik bulunamadı.');
        }
        self::assertCanManage($actor, (int) $item['consultant_id']);
        DB::execute('DELETE FROM consultant_availability WHERE id = ?', [$availabilityId]);
        Audit::record((int) $actor['id'], 'schedule.availability_deleted', 'consultant_availability', $availabilityId, ['consultant_id' => $item['consultant_id']]);
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

    private static function assertCanManage(array $actor, int $consultantId): void
    {
        if ($actor['role'] === 'consultant' && (int) $actor['id'] === $consultantId && can($actor, 'schedules.manage_own')) {
            return;
        }
        Authorization::require($actor, 'schedules.manage_all');
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

    private static function normalizeTime(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('H:i', substr($value, 0, 5));
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
