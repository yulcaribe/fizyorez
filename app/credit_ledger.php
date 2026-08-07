<?php

declare(strict_types=1);

final class CreditLedger
{
    public static function recordOpeningBalance(int $customerPackageId, ?int $actorId, string $reason = 'package_assigned'): void
    {
        $package = self::lockPackage($customerPackageId);
        $balance = (int) $package['credits_remaining'];
        DB::insert(
            'INSERT INTO credit_transactions
                (customer_package_id, customer_id, reservation_id, amount, balance_before, balance_after, reason, note, created_by, created_at)
             VALUES (?, ?, NULL, ?, 0, ?, ?, NULL, ?, NOW())',
            [$customerPackageId, $package['customer_id'], $balance, $balance, $reason, $actorId]
        );
    }

    public static function debit(int $customerPackageId, int $amount, string $reason, ?int $actorId, ?int $reservationId = null, string $note = ''): void
    {
        if ($amount < 1) {
            throw new RuntimeException('Düşülecek hak miktarı geçersiz.');
        }

        $package = self::lockPackage($customerPackageId);
        $before = (int) $package['credits_remaining'];
        $after = $before - $amount;
        if ($after < 0) {
            throw new RuntimeException('Paket hakkı düşürülemedi; yeterli hak yok.');
        }

        DB::execute('UPDATE customer_packages SET credits_remaining = ? WHERE id = ?', [$after, $customerPackageId]);
        self::record($package, -$amount, $before, $after, $reason, $actorId, $reservationId, $note);
    }

    public static function credit(int $customerPackageId, int $amount, string $reason, ?int $actorId, ?int $reservationId = null, string $note = ''): void
    {
        if ($amount < 1) {
            return;
        }

        $package = self::lockPackage($customerPackageId);
        $before = (int) $package['credits_remaining'];
        $after = min((int) $package['credits_total'], $before + $amount);
        $actual = $after - $before;
        if ($actual < 1) {
            return;
        }

        DB::execute('UPDATE customer_packages SET credits_remaining = ? WHERE id = ?', [$after, $customerPackageId]);
        self::record($package, $actual, $before, $after, $reason, $actorId, $reservationId, $note);
    }

    public static function adjust(array $actor, int $customerPackageId, int $amount, string $note): void
    {
        Authorization::require($actor, 'credits.adjust');
        if ($amount === 0 || trim($note) === '') {
            throw new RuntimeException('Hak düzeltme miktarı ve açıklaması zorunludur.');
        }

        DB::pdo()->beginTransaction();
        try {
            if ($amount < 0) {
                self::debit($customerPackageId, abs($amount), 'manual_adjustment', (int) $actor['id'], null, $note);
            } else {
                $package = self::lockPackage($customerPackageId);
                $requiredTotal = (int) $package['credits_remaining'] + $amount;
                if ($requiredTotal > (int) $package['credits_total']) {
                    DB::execute('UPDATE customer_packages SET credits_total = ? WHERE id = ?', [$requiredTotal, $customerPackageId]);
                }
                self::credit($customerPackageId, $amount, 'manual_adjustment', (int) $actor['id'], null, $note);
            }
            Audit::record((int) $actor['id'], 'credits.adjusted', 'customer_package', $customerPackageId, ['amount' => $amount, 'note' => $note]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }
    }

    public static function history(int $customerPackageId): array
    {
        return DB::fetchAll(
            'SELECT ct.*, u.name AS actor_name FROM credit_transactions ct
             LEFT JOIN users u ON u.id = ct.created_by
             WHERE ct.customer_package_id = ? ORDER BY ct.id DESC',
            [$customerPackageId]
        );
    }

    private static function lockPackage(int $customerPackageId): array
    {
        $package = DB::fetch('SELECT * FROM customer_packages WHERE id = ? FOR UPDATE', [$customerPackageId]);
        if (!$package) {
            throw new RuntimeException('Müşteri paketi bulunamadı.');
        }

        return $package;
    }

    private static function record(array $package, int $amount, int $before, int $after, string $reason, ?int $actorId, ?int $reservationId, string $note): void
    {
        DB::insert(
            'INSERT INTO credit_transactions
                (customer_package_id, customer_id, reservation_id, amount, balance_before, balance_after, reason, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$package['id'], $package['customer_id'], $reservationId, $amount, $before, $after, $reason, $note ?: null, $actorId]
        );
    }
}
