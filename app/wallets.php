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

    public static function reservedBalance(int $customerId): float
    {
        self::assertCustomer($customerId);
        $row = DB::fetch(
            'SELECT COALESCE(SUM(reserved_amount), 0) AS total
             FROM financial_transactions
             WHERE customer_id = ? AND direction = "debit" AND status = "awaiting_approval"',
            [$customerId]
        );

        return round((float) ($row['total'] ?? 0), 2);
    }

    public static function availableBalance(int $customerId): float
    {
        return max(0, round(self::balance($customerId) - self::reservedBalance($customerId), 2));
    }

    public static function balancesForCustomers(): array
    {
        return DB::fetchAll(
            'SELECT u.id, u.name,
                    COALESCE(w.balance, 0) AS wallet_balance,
                    COALESCE(pending.reserved_balance, 0) AS reserved_balance,
                    GREATEST(COALESCE(w.balance, 0) - COALESCE(pending.reserved_balance, 0), 0) AS available_balance
             FROM users u
             LEFT JOIN customer_wallets w ON w.customer_id = u.id
             LEFT JOIN (
                SELECT customer_id, SUM(reserved_amount) AS reserved_balance
                FROM financial_transactions
                WHERE direction = "debit" AND status = "awaiting_approval"
                GROUP BY customer_id
             ) pending ON pending.customer_id = u.id
             WHERE u.role = "customer"
             ORDER BY u.name'
        );
    }

    public static function transactions(array $actor, ?int $customerId = null): array
    {
        if ($actor['role'] === 'customer') {
            return PaymentService::list($actor);
        }
        if (!can($actor, 'payments.view_all') && !can($actor, 'wallets.adjust')) {
            throw new RuntimeException('Bakiye hareketlerini görüntüleme yetkiniz yok.');
        }
        if (can($actor, 'payments.view_all')) {
            if ($customerId === null) {
                return PaymentService::list($actor);
            }
            self::assertCustomer($customerId);
            return DB::fetchAll(
                'SELECT ft.*, u.name AS customer_name, creator.name AS created_by_name, reviewer.name AS reviewed_by_name
                 FROM financial_transactions ft
                 INNER JOIN users u ON u.id = ft.customer_id
                 LEFT JOIN users creator ON creator.id = ft.created_by
                 LEFT JOIN users reviewer ON reviewer.id = ft.reviewed_by
                 WHERE ft.customer_id = ? ORDER BY ft.id DESC LIMIT 500',
                [$customerId]
            );
        }

        return DB::fetchAll(
            'SELECT ft.*, u.name AS customer_name, creator.name AS created_by_name, reviewer.name AS reviewed_by_name
             FROM financial_transactions ft
             INNER JOIN users u ON u.id = ft.customer_id
             LEFT JOIN users creator ON creator.id = ft.created_by
             LEFT JOIN users reviewer ON reviewer.id = ft.reviewed_by
             WHERE ft.transaction_type = "manual_adjustment"
             ORDER BY ft.id DESC LIMIT 200'
        );
    }

    public static function adjust(array $actor, int $customerId, float $amount, string $note): array
    {
        return PaymentService::createAdjustment($actor, $customerId, $amount, $note);
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
        $accepted = hash_equals($testCard['number'], $cardNumber)
            && hash_equals($testCard['expiry'], $expiry)
            && hash_equals($testCard['cvv'], $cvv);
        $reference = 'TEST-' . strtoupper(bin2hex(random_bytes(5)));
        $lastFour = substr($cardNumber, -4);
        $cardBrand = str_starts_with($cardNumber, '4') ? 'Visa' : (str_starts_with($cardNumber, '5') ? 'Mastercard' : 'Test kartı');
        $transaction = PaymentService::recordCustomerCardTopUp($actor, $amount, $cardBrand, $lastFour, $accepted, $reference);

        if (!$accepted) {
            throw new RuntimeException('Kart doğrulanamadı; bakiye değişmedi.');
        }

        return [
            'transaction_id' => $transaction['id'],
            'reference_no' => $reference,
            'status' => 'awaiting_approval',
            'balance' => self::balance((int) $actor['id']),
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
