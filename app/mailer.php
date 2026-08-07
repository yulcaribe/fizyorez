<?php

declare(strict_types=1);

final class Mailer
{
    public static function queue(string $toEmail, string $toName, string $subject, string $body, ?int $reservationId = null, string $eventType = 'general', ?string $sendAfter = null): void
    {
        if (!$toEmail) {
            return;
        }

        DB::insert(
            'INSERT INTO mail_queue (to_email, to_name, subject, body, reservation_id, event_type, send_after, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW())',
            [$toEmail, $toName, $subject, $body, $reservationId, $eventType, $sendAfter]
        );
    }

    public static function queueReservationMail(int $reservationId, string $eventType): void
    {
        $reservation = DB::fetch(
            'SELECT r.*, s.name AS service_name, c.name AS customer_name, c.email AS customer_email, k.name AS consultant_name, k.email AS consultant_email
             FROM reservations r
             INNER JOIN services s ON s.id = r.service_id
             INNER JOIN users c ON c.id = r.customer_id
             INNER JOIN users k ON k.id = r.consultant_id
             WHERE r.id = ?',
            [$reservationId]
        );

        if (!$reservation) {
            return;
        }

        $starts = (new DateTimeImmutable((string) $reservation['starts_at']))->format('d-m-Y H:i');
        $titles = [
            'reservation_created' => 'Rezervasyon oluşturuldu',
            'reservation_updated' => 'Rezervasyon güncellendi',
            'reservation_cancelled' => 'Rezervasyon iptal edildi',
            'reservation_completed' => 'Rezervasyon tamamlandı',
            'reservation_no_show' => 'Rezervasyon gelmedi olarak işaretlendi',
            'reservation_reminder' => 'Rezervasyon hatırlatma',
            'reservation_payment_pending' => 'Rezervasyon ödemesi bekliyor',
        ];
        $subject = ($titles[$eventType] ?? 'Rezervasyon bildirimi') . ' - ' . config('app.name', 'FizyoRez');
        $body = sprintf(
            "%s\n\nHizmet: %s\nDanışan: %s\nFizyoterapist: %s\nTarih: %s\nDurum: %s\n\n%s",
            $titles[$eventType] ?? 'Rezervasyon bildirimi',
            $reservation['service_name'],
            $reservation['customer_name'],
            $reservation['consultant_name'],
            $starts,
            $reservation['status'],
            app_url('/login')
        );

        self::queue((string) $reservation['customer_email'], (string) $reservation['customer_name'], $subject, $body, $reservationId, $eventType);
        self::queue((string) $reservation['consultant_email'], (string) $reservation['consultant_name'], $subject, $body, $reservationId, $eventType);
    }

    public static function queueReminderMails(): int
    {
        $hours = (int) setting('reservation_reminder_hours', '24');
        $targetStart = (new DateTimeImmutable('+' . $hours . ' hours'))->format('Y-m-d H:i:s');
        $targetEnd = (new DateTimeImmutable('+' . ($hours + 1) . ' hours'))->format('Y-m-d H:i:s');

        $reservations = DB::fetchAll(
            'SELECT r.id FROM reservations r
             WHERE r.status IN ("pending", "confirmed")
             AND r.reminder_queued_at IS NULL
             AND r.starts_at BETWEEN ? AND ?',
            [$targetStart, $targetEnd]
        );

        foreach ($reservations as $reservation) {
            self::queueReservationMail((int) $reservation['id'], 'reservation_reminder');
            DB::execute('UPDATE reservations SET reminder_queued_at = NOW() WHERE id = ?', [$reservation['id']]);
        }

        return count($reservations);
    }

    public static function queuePackageAlerts(): int
    {
        $queued = 0;
        $lowCreditPackages = DB::fetchAll(
            'SELECT cp.*, p.name AS package_name, u.email, u.name
             FROM customer_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN users u ON u.id = cp.customer_id
             WHERE cp.status = "active" AND cp.credits_remaining <= 2 AND cp.low_credit_mail_queued_at IS NULL'
        );
        foreach ($lowCreditPackages as $package) {
            self::queue(
                (string) $package['email'],
                (string) $package['name'],
                'Paket hakkınız azalıyor - ' . config('app.name', 'FizyoRez'),
                sprintf("%s paketinizde %s hak kaldı.\n\n%s", $package['package_name'], $package['credits_remaining'], app_url('/customer/packages')),
                null,
                'package_low_credit'
            );
            DB::execute('UPDATE customer_packages SET low_credit_mail_queued_at = NOW() WHERE id = ?', [$package['id']]);
            $queued++;
        }

        $expiringPackages = DB::fetchAll(
            'SELECT cp.*, p.name AS package_name, u.email, u.name
             FROM customer_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN users u ON u.id = cp.customer_id
             WHERE cp.status = "active"
             AND cp.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
             AND cp.expiry_mail_queued_at IS NULL'
        );
        foreach ($expiringPackages as $package) {
            self::queue(
                (string) $package['email'],
                (string) $package['name'],
                'Paket süreniz yaklaşıyor - ' . config('app.name', 'FizyoRez'),
                sprintf("%s paketiniz %s tarihinde bitecek.\n\n%s", $package['package_name'], $package['expires_at'], app_url('/customer/packages')),
                null,
                'package_expiring'
            );
            DB::execute('UPDATE customer_packages SET expiry_mail_queued_at = NOW() WHERE id = ?', [$package['id']]);
            $queued++;
        }

        $pendingPackages = DB::fetchAll(
            'SELECT cp.*, p.name AS package_name, u.email, u.name
             FROM customer_packages cp
             INNER JOIN packages p ON p.id = cp.package_id
             INNER JOIN users u ON u.id = cp.customer_id
             WHERE cp.payment_status = "pending" AND cp.payment_mail_queued_at IS NULL'
        );
        foreach ($pendingPackages as $package) {
            self::queue(
                (string) $package['email'],
                (string) $package['name'],
                'Ödeme bekliyor - ' . config('app.name', 'FizyoRez'),
                sprintf("%s paketiniz için ödeme bekliyor.\n\n%s", $package['package_name'], app_url('/customer/packages')),
                null,
                'package_payment_pending'
            );
            DB::execute('UPDATE customer_packages SET payment_mail_queued_at = NOW() WHERE id = ?', [$package['id']]);
            $queued++;
        }

        $pendingReservations = DB::fetchAll(
            'SELECT r.id FROM reservations r
             WHERE r.reservation_type = "single"
             AND r.payment_status = "pending"
             AND r.status IN ("pending", "confirmed")
             AND r.payment_mail_queued_at IS NULL'
        );
        foreach ($pendingReservations as $reservation) {
            self::queueReservationMail((int) $reservation['id'], 'reservation_payment_pending');
            DB::execute('UPDATE reservations SET payment_mail_queued_at = NOW() WHERE id = ?', [$reservation['id']]);
            $queued++;
        }

        return $queued;
    }

    public static function sendPending(int $limit = 25): array
    {
        self::queueReminderMails();
        self::queuePackageAlerts();

        $items = DB::fetchAll(
            'SELECT * FROM mail_queue WHERE status = "pending" AND (send_after IS NULL OR send_after <= NOW()) ORDER BY id ASC LIMIT ' . max(1, $limit)
        );

        $sent = 0;
        $failed = 0;
        foreach ($items as $item) {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'From: ' . sprintf('%s <%s>', config('mail.from_name'), config('mail.from_email')),
            ];

            $ok = @mail((string) $item['to_email'], (string) $item['subject'], (string) $item['body'], implode("\r\n", $headers));
            if ($ok) {
                DB::execute('UPDATE mail_queue SET status = "sent", sent_at = NOW(), attempts = attempts + 1 WHERE id = ?', [$item['id']]);
                $sent++;
            } else {
                DB::execute('UPDATE mail_queue SET status = IF(attempts >= 2, "failed", "pending"), attempts = attempts + 1, error_message = ? WHERE id = ?', ['mail() gönderimi başarısız.', $item['id']]);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($items)];
    }
}
