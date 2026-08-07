<?php

declare(strict_types=1);

final class PaymentService
{
    public const METHODS = [
        'cash' => 'Nakit',
        'bank_transfer' => 'Banka Havalesi / EFT',
        'card_manual' => 'Kart / POS (manuel)',
        'paypal_beta' => 'PayPal (beta)',
        'other' => 'Diğer',
    ];

    public const STATUSES = [
        'pending' => 'Bekliyor',
        'awaiting_approval' => 'Onay Bekliyor',
        'paid' => 'Ödendi',
        'cancelled' => 'İptal',
        'refunded' => 'İade',
    ];

    public static function list(array $actor, ?string $status = null): array
    {
        $conditions = [];
        $params = [];
        if ($actor['role'] === 'customer') {
            $conditions[] = 'p.customer_id = ?';
            $params[] = $actor['id'];
        } else {
            Authorization::require($actor, 'payments.view_all');
        }
        if ($status !== null && $status !== '') {
            if (!isset(self::STATUSES[$status])) {
                throw new RuntimeException('Ödeme durum filtresi geçersiz.');
            }
            $conditions[] = 'p.status = ?';
            $params[] = $status;
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return DB::fetchAll(
            'SELECT p.*, u.name AS customer_name, creator.name AS created_by_name, approver.name AS approved_by_name
             FROM payments p
             INNER JOIN users u ON u.id = p.customer_id
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users approver ON approver.id = p.approved_by
             ' . $where . ' ORDER BY p.id DESC LIMIT 300',
            $params
        );
    }

    public static function create(array $actor, array $data): array
    {
        Authorization::require($actor, 'payments.create');
        $method = self::normalizeMethod((string) ($data['method'] ?? 'cash'));
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $targetType = (string) ($data['target_type'] ?? 'package');
        $targetId = (int) ($data['target_id'] ?? 0);

        if (!isset(self::METHODS[$method])) {
            throw new RuntimeException('Seçilen ödeme yöntemi geçersiz.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Ödeme tutarı sıfırdan büyük olmalıdır.');
        }
        if (!in_array($targetType, ['package', 'reservation'], true) || $targetId < 1) {
            throw new RuntimeException('Ödeme yapılacak paket veya seans seçimi geçersiz.');
        }

        $target = self::findTarget($targetType, $targetId);
        $customerId = (int) $target['customer_id'];
        $customer = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer" AND status = "active"', [$customerId]);
        if (!$customer) {
            throw new RuntimeException('Ödeme kaydının danışanı aktif değil veya bulunamadı.');
        }
        if (($target['payment_status'] ?? '') === 'paid') {
            throw new RuntimeException('Bu paket veya seans zaten ödenmiş görünüyor.');
        }
        $status = in_array($method, ['bank_transfer', 'paypal_beta'], true) ? 'awaiting_approval' : 'pending';
        DB::pdo()->beginTransaction();
        try {
            $id = DB::insert(
                'INSERT INTO payments
                    (customer_id, target_type, target_id, amount, currency, method, status, reference_no, note, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $customerId, $targetType, $targetId, $amount, (string) setting('currency', 'TRY'), $method, $status,
                    trim((string) ($data['reference_no'] ?? '')) ?: null,
                    trim((string) ($data['note'] ?? '')) ?: null,
                    $actor['id'],
                ]
            );
            self::event($id, null, $status, 'Ödeme kaydı oluşturuldu.', (int) $actor['id']);
            Audit::record((int) $actor['id'], 'payment.created', 'payment', $id, ['amount' => $amount, 'method' => $method]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($id);
    }

    public static function approve(array $actor, int $paymentId): array
    {
        Authorization::require($actor, 'payments.approve');
        DB::pdo()->beginTransaction();
        try {
            $payment = DB::fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || !in_array($payment['status'], ['pending', 'awaiting_approval'], true)) {
                throw new RuntimeException('Bu ödeme onaylanamaz.');
            }

            $targetTable = $payment['target_type'] === 'package' ? 'customer_packages' : 'reservations';
            $target = DB::fetch('SELECT id, payment_status FROM ' . $targetTable . ' WHERE id = ? FOR UPDATE', [$payment['target_id']]);
            if (!$target || $target['payment_status'] === 'paid') {
                throw new RuntimeException('Bu paket veya seans için daha önce onaylanmış ödeme var.');
            }

            DB::execute('UPDATE payments SET status = "paid", approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?', [$actor['id'], $paymentId]);
            self::syncTargetStatus($payment, 'paid');
            self::event($paymentId, (string) $payment['status'], 'paid', 'Ödeme onaylandı.', (int) $actor['id']);
            Audit::record((int) $actor['id'], 'payment.approved', 'payment', $paymentId);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($paymentId);
    }

    public static function cancel(array $actor, int $paymentId): array
    {
        Authorization::require($actor, 'payments.approve');
        DB::pdo()->beginTransaction();
        try {
            $payment = DB::fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || !in_array($payment['status'], ['pending', 'awaiting_approval'], true)) {
                throw new RuntimeException('Yalnızca bekleyen ödeme kaydı iptal edilebilir.');
            }
            DB::execute('UPDATE payments SET status = "cancelled", updated_at = NOW() WHERE id = ?', [$paymentId]);
            self::event($paymentId, (string) $payment['status'], 'cancelled', 'Ödeme kaydı iptal edildi.', (int) $actor['id']);
            Audit::record((int) $actor['id'], 'payment.cancelled', 'payment', $paymentId);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($paymentId);
    }

    public static function refund(array $actor, int $paymentId, string $note = ''): array
    {
        Authorization::require($actor, 'payments.refund');
        if (trim($note) === '') {
            throw new RuntimeException('İade nedeni zorunludur.');
        }
        DB::pdo()->beginTransaction();
        try {
            $payment = DB::fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || $payment['status'] !== 'paid') {
                throw new RuntimeException('Yalnızca onaylanmış ödeme iade edilebilir.');
            }
            DB::execute("UPDATE payments SET status = 'refunded', note = CONCAT_WS(' | ', note, ?), updated_at = NOW() WHERE id = ?", [trim($note), $paymentId]);
            self::syncTargetStatus($payment, 'refunded');
            self::event($paymentId, 'paid', 'refunded', trim($note), (int) $actor['id']);
            Audit::record((int) $actor['id'], 'payment.refunded', 'payment', $paymentId, ['reason' => trim($note)]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($paymentId);
    }

    public static function reopen(array $actor, int $paymentId, string $note): array
    {
        Authorization::require($actor, 'payments.approve');
        $note = trim($note);
        if ($note === '') {
            throw new RuntimeException('Ödendi işaretini geri alma nedeni zorunludur.');
        }

        DB::pdo()->beginTransaction();
        try {
            $payment = DB::fetch('SELECT * FROM payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if (!$payment || $payment['status'] !== 'paid') {
                throw new RuntimeException('Yalnızca ödendi durumundaki kayıt yeniden beklemeye alınabilir.');
            }

            $targetTable = $payment['target_type'] === 'package' ? 'customer_packages' : 'reservations';
            $target = DB::fetch('SELECT id FROM ' . $targetTable . ' WHERE id = ? FOR UPDATE', [$payment['target_id']]);
            if (!$target) {
                throw new RuntimeException('Ödeme hedefi bulunamadı.');
            }

            DB::execute(
                'UPDATE payments
                 SET status = "pending", approved_by = NULL, approved_at = NULL,
                     note = CONCAT_WS(" | ", NULLIF(note, ""), ?), updated_at = NOW()
                 WHERE id = ?',
                ['Ödendi işareti geri alındı: ' . $note, $paymentId]
            );
            self::syncTargetStatus($payment, 'pending');
            self::event($paymentId, 'paid', 'pending', 'Ödendi işareti geri alındı: ' . $note, (int) $actor['id']);
            Audit::record((int) $actor['id'], 'payment.reopened', 'payment', $paymentId, ['reason' => $note]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($paymentId);
    }

    public static function find(int $paymentId): array
    {
        $payment = DB::fetch('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$payment) {
            throw new RuntimeException('Ödeme bulunamadı.');
        }

        return $payment;
    }

    private static function findTarget(string $targetType, int $targetId): array
    {
        $table = $targetType === 'package' ? 'customer_packages' : 'reservations';
        $target = DB::fetch('SELECT id, customer_id, payment_status FROM ' . $table . ' WHERE id = ?', [$targetId]);
        if (!$target) {
            throw new RuntimeException('Ödeme yapılacak paket veya seans bulunamadı.');
        }

        return $target;
    }

    private static function normalizeMethod(string $method): string
    {
        $method = strtolower(trim($method));
        $aliases = [
            'bank' => 'bank_transfer',
            'eft' => 'bank_transfer',
            'card' => 'card_manual',
            'pos' => 'card_manual',
            'paypal' => 'paypal_beta',
        ];

        return $aliases[$method] ?? $method;
    }

    private static function syncTargetStatus(array $payment, string $status): void
    {
        if ($payment['target_type'] === 'package') {
            DB::execute('UPDATE customer_packages SET payment_status = ? WHERE id = ?', [$status, $payment['target_id']]);
            return;
        }
        DB::execute('UPDATE reservations SET payment_status = ?, updated_at = NOW() WHERE id = ?', [$status, $payment['target_id']]);
    }

    private static function event(int $paymentId, ?string $from, string $to, string $note, int $actorId): void
    {
        DB::insert(
            'INSERT INTO payment_events (payment_id, from_status, to_status, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$paymentId, $from, $to, $note, $actorId]
        );
    }
}
