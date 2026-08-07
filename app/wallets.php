<?php

declare(strict_types=1);

final class WalletService
{
    public static function balance(int $customerId): float
    {
        self::assertCustomer($customerId);
        self::ensureWallet($customerId);
        $wallet = DB::fetch('SELECT balance FROM customer_wallets WHERE customer_id = ?', [$customerId]);

        return round((float) ($wallet['balance'] ?? 0), 2);
    }

    public static function balancesForCustomers(): array
    {
        return DB::fetchAll(
            'SELECT u.id, u.name, COALESCE(w.balance, 0) AS wallet_balance
             FROM users u
             LEFT JOIN customer_wallets w ON w.customer_id = u.id
             WHERE u.role = "customer"
             ORDER BY u.name'
        );
    }

    public static function transactions(array $actor, ?int $customerId = null): array
    {
        if ($actor['role'] === 'customer') {
            $customerId = (int) $actor['id'];
        } else {
            Authorization::require($actor, 'payments.view_all');
        }

        $where = $customerId ? 'WHERE wt.customer_id = ?' : '';
        $params = $customerId ? [$customerId] : [];

        return DB::fetchAll(
            'SELECT wt.*, u.name AS customer_name
             FROM wallet_transactions wt
             INNER JOIN users u ON u.id = wt.customer_id
             ' . $where . '
             ORDER BY wt.id DESC LIMIT 200',
            $params
        );
    }

    public static function testCard(): array
    {
        return [
            'number' => preg_replace('/\D+/', '', (string) setting('test_card_number', '4242424242424242')),
            'expiry' => trim((string) setting('test_card_expiry', '12/30')),
            'cvv' => preg_replace('/\D+/', '', (string) setting('test_card_cvv', '123')),
        ];
    }

    public static function simulateTopUp(array $actor, array $data): array
    {
        if ($actor['role'] !== 'customer') {
            throw new RuntimeException('Test bakiye yükleme yalnızca danışan portalından yapılabilir.');
        }

        $customerId = (int) $actor['id'];
        $amount = round((float) ($data['amount'] ?? 0), 2);
        $cardholder = trim((string) ($data['cardholder'] ?? ''));
        $cardNumber = preg_replace('/\D+/', '', (string) ($data['card_number'] ?? ''));
        $expiry = preg_replace('/\s+/', '', (string) ($data['expiry'] ?? ''));
        $cvv = preg_replace('/\D+/', '', (string) ($data['cvv'] ?? ''));

        if ($amount < 1 || $amount > 100000) {
            throw new RuntimeException('Yüklenecek bakiye 1 ile 100.000 arasında olmalıdır.');
        }
        if (mb_strlen($cardholder) < 3 || strlen($cardNumber) < 12 || strlen($cardNumber) > 19 || !preg_match('#^\d{2}/\d{2}$#', $expiry) || strlen($cvv) < 3 || strlen($cvv) > 4) {
            throw new RuntimeException('Kart bilgilerini eksiksiz ve geçerli formatta girin.');
        }

        $testCard = self::testCard();
        $approved = hash_equals($testCard['number'], $cardNumber)
            && hash_equals($testCard['expiry'], $expiry)
            && hash_equals($testCard['cvv'], $cvv);
        $reference = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
        $lastFour = substr($cardNumber, -4);

        self::ensureWallet($customerId);
        DB::pdo()->beginTransaction();
        try {
            $wallet = DB::fetch('SELECT balance FROM customer_wallets WHERE customer_id = ? FOR UPDATE', [$customerId]);
            $before = round((float) ($wallet['balance'] ?? 0), 2);
            $after = $approved ? round($before + $amount, 2) : $before;

            if ($approved) {
                DB::execute('UPDATE customer_wallets SET balance = ?, updated_at = NOW() WHERE customer_id = ?', [$after, $customerId]);
            }

            $id = DB::insert(
                'INSERT INTO wallet_transactions
                    (customer_id, transaction_type, amount, balance_before, balance_after, status, provider, reference_no, card_last_four, note, created_by, created_at)
                 VALUES (?, "topup", ?, ?, ?, ?, "test_card", ?, ?, ?, ?, NOW())',
                [
                    $customerId,
                    $amount,
                    $before,
                    $after,
                    $approved ? 'approved' : 'rejected',
                    $reference,
                    $lastFour,
                    $approved ? 'Test kartı ile bakiye yükleme onaylandı.' : 'Test kartı doğrulanamadı; bakiye değişmedi.',
                    $customerId,
                ]
            );
            Audit::record($customerId, $approved ? 'wallet.topup_approved' : 'wallet.topup_rejected', 'wallet_transaction', $id, [
                'amount' => $amount,
                'reference' => $reference,
                'card_last_four' => $lastFour,
            ]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        if (!$approved) {
            throw new RuntimeException('Kart reddedildi. Yalnızca ekranda gösterilen test kartı onay verir; bakiye değişmedi.');
        }

        return [
            'transaction_id' => $id,
            'reference_no' => $reference,
            'balance' => $after,
        ];
    }

    public static function ensureWallet(int $customerId): void
    {
        DB::execute(
            'INSERT IGNORE INTO customer_wallets (customer_id, balance, updated_at) VALUES (?, 0, NOW())',
            [$customerId]
        );
    }

    private static function assertCustomer(int $customerId): void
    {
        $customer = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer"', [$customerId]);
        if (!$customer) {
            throw new RuntimeException('Danışan bulunamadı.');
        }
    }
}
