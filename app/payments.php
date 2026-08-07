<?php

declare(strict_types=1);

final class PaymentService
{
    public const METHODS = [
        'wallet' => 'FizyoRez bakiyesi',
        'cash' => 'Nakit',
        'bank_transfer' => 'Banka Havalesi / EFT',
        'card_manual' => 'Kart / POS (manuel)',
        'customer_card' => 'Danışan kartı',
        'paypal_beta' => 'PayPal (beta)',
        'manual_adjustment' => 'Manuel bakiye düzeltmesi',
        'other' => 'Diğer',
        'legacy' => 'Eski sistem kaydı',
    ];

    public const STAFF_METHODS = [
        'cash' => 'Nakit',
        'bank_transfer' => 'Banka Havalesi / EFT',
        'card_manual' => 'Kart / POS (manuel)',
        'paypal_beta' => 'PayPal (beta)',
        'other' => 'Diğer',
    ];

    public const STATUSES = [
        'awaiting_approval' => 'Onay Bekliyor',
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'cancelled' => 'İptal',
        'refunded' => 'İade Edildi',
        // Paket ve rezervasyonların eski payment_status alanlarında kullanılmaya devam eder.
        'pending' => 'Bekliyor',
        'paid' => 'Ödendi',
    ];

    public const TYPES = [
        'topup' => 'Bakiye yükleme',
        'package_purchase' => 'Paket / hak satın alma',
        'session_purchase' => 'Tek seans satın alma',
        'manual_adjustment' => 'Bakiye düzeltmesi',
        'refund' => 'İade / ters işlem',
        'legacy_payment' => 'Eski ödeme kaydı',
    ];

    public static function list(array $actor, ?string $status = null, ?string $view = null): array
    {
        $conditions = [];
        $params = [];
        if ($actor['role'] === 'customer') {
            $conditions[] = 'ft.customer_id = ?';
            $params[] = $actor['id'];
        } else {
            if (!can($actor, 'payments.view_all') && !can($actor, 'payments.approve') && !can($actor, 'payments.refund')) {
                throw new RuntimeException('Finans hareketlerini görüntüleme yetkiniz yok.');
            }
        }

        if ($status !== null && $status !== '') {
            $status = $status === 'paid' ? 'approved' : ($status === 'pending' ? 'awaiting_approval' : $status);
            if (!in_array($status, ['awaiting_approval', 'approved', 'rejected', 'cancelled', 'refunded'], true)) {
                throw new RuntimeException('Ödeme durum filtresi geçersiz.');
            }
            $conditions[] = 'ft.status = ?';
            $params[] = $status;
        }

        if ($view !== null && $view !== '') {
            if (!in_array($view, ['deductions', 'topups', 'adjustments', 'refunds'], true)) {
                throw new RuntimeException('Finans hareketi filtresi geçersiz.');
            }
            if ($view === 'deductions') {
                $conditions[] = 'ft.direction = "debit"';
            } elseif ($view === 'topups') {
                $conditions[] = 'ft.transaction_type = "topup"';
            } elseif ($view === 'adjustments') {
                $conditions[] = 'ft.transaction_type = "manual_adjustment"';
            } else {
                $conditions[] = 'ft.transaction_type = "refund"';
            }
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return DB::fetchAll(
            'SELECT ft.*, customer.name AS customer_name,
                    creator.name AS created_by_name, reviewer.name AS reviewed_by_name,
                    COALESCE(package_definition.name, legacy_package.name, service.name) AS target_name
             FROM financial_transactions ft
             INNER JOIN users customer ON customer.id = ft.customer_id
             LEFT JOIN users creator ON creator.id = ft.created_by
             LEFT JOIN users reviewer ON reviewer.id = ft.reviewed_by
             LEFT JOIN packages package_definition
                ON ft.target_type = "package_definition" AND package_definition.id = ft.target_id
             LEFT JOIN customer_packages legacy_customer_package
                ON ft.target_type = "package" AND legacy_customer_package.id = ft.target_id
             LEFT JOIN packages legacy_package ON legacy_package.id = legacy_customer_package.package_id
             LEFT JOIN reservations reservation
                ON ft.target_type = "reservation" AND reservation.id = ft.target_id
             LEFT JOIN services service ON service.id = reservation.service_id
             ' . $where . '
             ORDER BY ft.id DESC LIMIT 500',
            $params
        );
    }

    /** Personelin aldığı ödemeyi danışan bakiyesine yükleme talebi olarak kaydeder. */
    public static function create(array $actor, array $data): array
    {
        Authorization::require($actor, 'payments.create');
        $customerId = (int) ($data['customer_id'] ?? 0);
        self::assertCustomer($customerId);
        $amount = self::validAmount($data['amount'] ?? 0);
        $method = self::normalizeMethod((string) ($data['method'] ?? 'cash'));
        if (!isset(self::STAFF_METHODS[$method])) {
            throw new RuntimeException('Bakiye yükleme yöntemi geçersiz.');
        }
        $note = trim((string) ($data['note'] ?? ''));
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('Ödeme açıklaması zorunludur.');
        }

        $id = self::insertTransaction([
            'customer_id' => $customerId,
            'transaction_type' => 'topup',
            'direction' => 'credit',
            'amount' => $amount,
            'method' => $method,
            'source' => 'staff',
            'status' => 'awaiting_approval',
            'provider' => $method,
            'reference_no' => trim((string) ($data['reference_no'] ?? '')) ?: self::reference('PAY'),
            'note' => $note,
            'created_by' => (int) $actor['id'],
        ]);
        Audit::record((int) $actor['id'], 'financial.topup_requested', 'financial_transaction', $id, ['customer_id' => $customerId, 'amount' => $amount, 'method' => $method]);

        return self::find($id);
    }

    public static function createAdjustment(array $actor, int $customerId, float $signedAmount, string $note): array
    {
        Authorization::require($actor, 'wallets.adjust');
        self::assertCustomer($customerId);
        $signedAmount = round($signedAmount, 2);
        $note = trim($note);
        if ($signedAmount === 0.0 || abs($signedAmount) > 1000000) {
            throw new RuntimeException('Bakiye düzeltmesi sıfırdan farklı ve en fazla 1.000.000 olmalıdır.');
        }
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('Bakiye düzeltme nedeni zorunludur.');
        }
        $direction = $signedAmount > 0 ? 'credit' : 'debit';
        $amount = abs($signedAmount);
        if ($direction === 'debit' && WalletService::availableBalance($customerId) < $amount) {
            throw new RuntimeException('Danışanın kullanılabilir bakiyesi bu düşüm için yetersiz.');
        }

        $id = self::insertTransaction([
            'customer_id' => $customerId,
            'transaction_type' => 'manual_adjustment',
            'direction' => $direction,
            'amount' => $amount,
            'reserved_amount' => $direction === 'debit' ? $amount : 0,
            'method' => 'manual_adjustment',
            'source' => 'staff',
            'status' => 'awaiting_approval',
            'provider' => 'manual_adjustment',
            'reference_no' => self::reference('ADJ'),
            'note' => $note,
            'created_by' => (int) $actor['id'],
        ]);
        Audit::record((int) $actor['id'], 'financial.adjustment_requested', 'financial_transaction', $id, ['customer_id' => $customerId, 'amount' => $signedAmount, 'reason' => $note]);

        return self::find($id);
    }

    public static function recordCustomerCardTopUp(array $actor, float $amount, string $cardBrand, string $lastFour, bool $cardAccepted, string $reference): array
    {
        if ($actor['role'] !== 'customer') {
            throw new RuntimeException('Kartla bakiye yükleme yalnızca danışan portalından yapılabilir.');
        }
        $amount = self::validAmount($amount, 100000);
        $id = self::insertTransaction([
            'customer_id' => (int) $actor['id'],
            'transaction_type' => 'topup',
            'direction' => 'credit',
            'amount' => $amount,
            'method' => 'customer_card',
            'source' => 'customer_portal',
            'status' => $cardAccepted ? 'awaiting_approval' : 'rejected',
            'provider' => 'test_card',
            'reference_no' => $reference,
            'card_brand' => $cardBrand,
            'card_last_four' => $lastFour,
            'note' => $cardAccepted ? 'Kart doğrulandı; bakiye yükleme yetkili onayı bekliyor.' : 'Kart doğrulanamadı; bakiye değişmedi.',
            'created_by' => (int) $actor['id'],
        ]);
        Audit::record((int) $actor['id'], $cardAccepted ? 'financial.card_topup_requested' : 'financial.card_topup_rejected', 'financial_transaction', $id, ['amount' => $amount, 'card_last_four' => $lastFour]);

        return self::find($id);
    }

    public static function purchaseWithWallet(array $actor, string $targetType, int $targetId): array
    {
        if ($actor['role'] !== 'customer') {
            throw new RuntimeException('Bakiye ile satın alma yalnızca danışan portalından yapılabilir.');
        }
        $customerId = (int) $actor['id'];
        if ($targetType === 'package') {
            $target = DB::fetch('SELECT id, name, price FROM packages WHERE id = ? AND active = 1', [$targetId]);
            if (!$target) {
                throw new RuntimeException('Satın alınacak paket bulunamadı.');
            }
            $transactionType = 'package_purchase';
            $storedTargetType = 'package_definition';
            $note = (string) $target['name'] . ' paketi için bakiye kesintisi.';
        } elseif ($targetType === 'reservation') {
            $target = DB::fetch(
                'SELECT r.id, r.price, r.payment_status, s.name
                 FROM reservations r
                 INNER JOIN services s ON s.id = r.service_id
                 WHERE r.id = ? AND r.customer_id = ? AND r.reservation_type = "single"
                   AND r.status IN ("pending", "confirmed")',
                [$targetId, $customerId]
            );
            if (!$target || $target['payment_status'] === 'paid') {
                throw new RuntimeException('Ödenecek tek seans bulunamadı veya daha önce ödenmiş.');
            }
            if (DB::fetch('SELECT id FROM financial_transactions WHERE customer_id = ? AND target_type = "reservation" AND target_id = ? AND status IN ("awaiting_approval", "approved") LIMIT 1', [$customerId, $targetId])) {
                throw new RuntimeException('Bu seans için zaten bekleyen veya onaylanmış bir bakiye işlemi var.');
            }
            $transactionType = 'session_purchase';
            $storedTargetType = 'reservation';
            $note = (string) $target['name'] . ' tek seansı için bakiye kesintisi.';
        } else {
            throw new RuntimeException('Satın alma türü geçersiz.');
        }

        $amount = $storedTargetType === 'package_definition'
            ? self::validPurchaseAmount($target['price'] ?? 0)
            : self::validAmount($target['price'] ?? 0);
        if (WalletService::availableBalance($customerId) < $amount) {
            throw new RuntimeException('Kullanılabilir bakiyeniz bu satın alma için yetersiz.');
        }

        DB::pdo()->beginTransaction();
        try {
            $id = self::insertTransaction([
                'customer_id' => $customerId,
                'transaction_type' => $transactionType,
                'direction' => 'debit',
                'amount' => $amount,
                'reserved_amount' => $amount,
                'method' => 'wallet',
                'source' => 'customer_portal',
                'target_type' => $storedTargetType,
                'target_id' => $targetId,
                'effective_date' => $storedTargetType === 'package_definition' ? date('Y-m-d') : null,
                'status' => 'awaiting_approval',
                'provider' => 'fizyorez_wallet',
                'reference_no' => self::reference('BUY'),
                'note' => $note,
                'created_by' => $customerId,
            ]);
            if ($storedTargetType === 'reservation') {
                DB::execute('UPDATE reservations SET payment_status = "awaiting_approval", updated_at = NOW() WHERE id = ?', [$targetId]);
            }
            Audit::record($customerId, 'financial.purchase_requested', 'financial_transaction', $id, ['type' => $transactionType, 'target_id' => $targetId, 'amount' => $amount]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($id);
    }

    public static function requestPackageForCustomer(array $actor, int $customerId, int $packageId, string $startsAt): array
    {
        Authorization::require($actor, 'packages.manage');
        self::assertCustomer($customerId);
        $package = DB::fetch('SELECT id, name, price FROM packages WHERE id = ? AND active = 1', [$packageId]);
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startsAt);
        if (!$package || !$start || $start->format('Y-m-d') !== $startsAt) {
            throw new RuntimeException('Paket, danışan veya başlangıç tarihi geçersiz.');
        }
        $amount = self::validPurchaseAmount($package['price'] ?? 0);

        $id = self::insertTransaction([
            'customer_id' => $customerId,
            'transaction_type' => 'package_purchase',
            'direction' => 'debit',
            'amount' => $amount,
            'reserved_amount' => $amount,
            'method' => 'wallet',
            'source' => 'staff',
            'target_type' => 'package_definition',
            'target_id' => $packageId,
            'effective_date' => $startsAt,
            'status' => 'awaiting_approval',
            'provider' => 'fizyorez_wallet',
            'reference_no' => self::reference('PKG'),
            'note' => (string) $package['name'] . ' paketi yönetim tarafından onaya gönderildi.',
            'created_by' => (int) $actor['id'],
        ]);
        Audit::record((int) $actor['id'], 'financial.package_requested', 'financial_transaction', $id, ['customer_id' => $customerId, 'package_id' => $packageId, 'amount' => $amount]);

        return self::find($id);
    }

    public static function approve(array $actor, int $transactionId): array
    {
        Authorization::require($actor, 'payments.approve');
        DB::pdo()->beginTransaction();
        try {
            $transaction = DB::fetch('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE', [$transactionId]);
            if (!$transaction || $transaction['status'] !== 'awaiting_approval') {
                throw new RuntimeException('Bu finans hareketi onaylanamaz.');
            }
            self::assertCanReviewOwn($actor, $transaction);

            if ($transaction['legacy_source'] === 'payments') {
                self::approveLegacyPayment($transaction);
                $before = null;
                $after = null;
                $walletApplied = 0;
            } else {
                [$before, $after] = self::applyWalletEffect($transaction);
                self::fulfilTarget($transaction, (int) $actor['id']);
                $walletApplied = 1;
            }

            DB::execute(
                'UPDATE financial_transactions
                 SET status = "approved", reserved_amount = 0, balance_before = ?, balance_after = ?,
                     wallet_applied = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = "Onaylandı", updated_at = NOW()
                 WHERE id = ?',
                [$before, $after, $walletApplied, $actor['id'], $transactionId]
            );
            Audit::record((int) $actor['id'], 'financial.approved', 'financial_transaction', $transactionId, ['balance_before' => $before, 'balance_after' => $after]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($transactionId);
    }

    public static function cancel(array $actor, int $transactionId, string $note = ''): array
    {
        Authorization::require($actor, 'payments.approve');
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('Reddetme nedeni zorunludur.');
        }
        DB::pdo()->beginTransaction();
        try {
            $transaction = DB::fetch('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE', [$transactionId]);
            if (!$transaction || $transaction['status'] !== 'awaiting_approval') {
                throw new RuntimeException('Yalnızca onay bekleyen finans hareketi reddedilebilir.');
            }
            self::assertCanReviewOwn($actor, $transaction);
            DB::execute(
                'UPDATE financial_transactions
                 SET status = "rejected", reserved_amount = 0, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, updated_at = NOW()
                 WHERE id = ?',
                [$actor['id'], $note, $transactionId]
            );
            self::resetRejectedTarget($transaction);
            Audit::record((int) $actor['id'], 'financial.rejected', 'financial_transaction', $transactionId, ['reason' => $note]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($transactionId);
    }

    /** Onaylanmış kaydı değiştirmez; ayrıca onaylanacak ters hareket oluşturur. */
    public static function refund(array $actor, int $transactionId, string $note = ''): array
    {
        Authorization::require($actor, 'payments.refund');
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new RuntimeException('İade veya ters işlem nedeni zorunludur.');
        }

        DB::pdo()->beginTransaction();
        try {
            $parent = DB::fetch('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE', [$transactionId]);
            if (!$parent || $parent['status'] !== 'approved') {
                throw new RuntimeException('Yalnızca onaylanmış hareket için ters işlem oluşturulabilir.');
            }
            if (DB::fetch('SELECT id FROM financial_transactions WHERE parent_transaction_id = ? AND status IN ("awaiting_approval", "approved") LIMIT 1', [$transactionId])) {
                throw new RuntimeException('Bu hareket için zaten bekleyen veya onaylanmış bir ters işlem var.');
            }
            self::assertRefundableTarget($parent);
            $direction = $parent['direction'] === 'credit' ? 'debit' : 'credit';
            $amount = (float) $parent['amount'];
            if ($direction === 'debit' && WalletService::availableBalance((int) $parent['customer_id']) < $amount) {
                throw new RuntimeException('İade için danışanın kullanılabilir bakiyesi yetersiz.');
            }
            $id = self::insertTransaction([
                'customer_id' => (int) $parent['customer_id'],
                'transaction_type' => 'refund',
                'direction' => $direction,
                'amount' => $amount,
                'reserved_amount' => $direction === 'debit' ? $amount : 0,
                'method' => 'wallet',
                'source' => 'staff',
                'target_type' => $parent['target_type'],
                'target_id' => $parent['target_id'],
                'parent_transaction_id' => $transactionId,
                'status' => 'awaiting_approval',
                'provider' => 'fizyorez_wallet',
                'reference_no' => self::reference('REV'),
                'note' => $note,
                'created_by' => (int) $actor['id'],
            ]);
            Audit::record((int) $actor['id'], 'financial.reversal_requested', 'financial_transaction', $id, ['parent_id' => $transactionId, 'reason' => $note]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return self::find($id);
    }

    public static function reopen(array $actor, int $transactionId, string $note): array
    {
        return self::refund($actor, $transactionId, 'Ters işlem: ' . trim($note));
    }

    public static function find(int $transactionId): array
    {
        $transaction = DB::fetch('SELECT * FROM financial_transactions WHERE id = ?', [$transactionId]);
        if (!$transaction) {
            throw new RuntimeException('Finans hareketi bulunamadı.');
        }

        return $transaction;
    }

    private static function applyWalletEffect(array $transaction): array
    {
        $customerId = (int) $transaction['customer_id'];
        WalletService::ensureWallet($customerId);
        $wallet = DB::fetch('SELECT balance FROM customer_wallets WHERE customer_id = ? FOR UPDATE', [$customerId]);
        $before = round((float) ($wallet['balance'] ?? 0), 2);
        $signedAmount = $transaction['direction'] === 'credit' ? (float) $transaction['amount'] : -(float) $transaction['amount'];
        $after = round($before + $signedAmount, 2);
        $allowsDebt = $transaction['direction'] === 'debit'
            && $transaction['transaction_type'] === 'package_purchase'
            && $transaction['target_type'] === 'package_definition'
            && $transaction['source'] === 'staff';
        if ($after < 0 && !$allowsDebt) {
            throw new RuntimeException('Onay sırasında kullanılabilir bakiye yetersiz kaldı. Önce başka bekleyen hareketleri kontrol edin.');
        }
        DB::execute('UPDATE customer_wallets SET balance = ?, updated_at = NOW() WHERE customer_id = ?', [$after, $customerId]);

        return [$before, $after];
    }

    private static function fulfilTarget(array $transaction, int $actorId): void
    {
        if ($transaction['transaction_type'] === 'package_purchase' && $transaction['target_type'] === 'package_definition') {
            $package = DB::fetch('SELECT * FROM packages WHERE id = ? AND active = 1 FOR UPDATE', [$transaction['target_id']]);
            if (!$package) {
                throw new RuntimeException('Satın alınan paket artık aktif değil veya bulunamadı.');
            }
            $startsAt = $transaction['effective_date'] ?: date('Y-m-d');
            $expiresAt = (new DateTimeImmutable($startsAt))->modify('+' . (int) $package['validity_days'] . ' days')->format('Y-m-d');
            $customerPackageId = DB::insert(
                'INSERT INTO customer_packages
                    (customer_id, package_id, credits_total, credits_remaining, starts_at, expires_at, status, payment_status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, "active", "paid", NOW())',
                [$transaction['customer_id'], $package['id'], $package['total_credits'], $package['total_credits'], $startsAt, $expiresAt]
            );
            CreditLedger::recordOpeningBalance($customerPackageId, $actorId, 'wallet_package_purchase');
            DB::execute('UPDATE financial_transactions SET result_target_id = ? WHERE id = ?', [$customerPackageId, $transaction['id']]);
            return;
        }

        if ($transaction['transaction_type'] === 'session_purchase' && $transaction['target_type'] === 'reservation') {
            $reservation = DB::fetch('SELECT id, customer_id, payment_status FROM reservations WHERE id = ? FOR UPDATE', [$transaction['target_id']]);
            if (!$reservation || (int) $reservation['customer_id'] !== (int) $transaction['customer_id'] || $reservation['payment_status'] === 'paid') {
                throw new RuntimeException('Tek seans ödeme hedefi artık uygun değil.');
            }
            DB::execute('UPDATE reservations SET payment_status = "paid", updated_at = NOW() WHERE id = ?', [$transaction['target_id']]);
            return;
        }

        if ($transaction['transaction_type'] === 'package_purchase' && $transaction['target_type'] === 'package') {
            $customerPackage = DB::fetch('SELECT id, customer_id, payment_status FROM customer_packages WHERE id = ? FOR UPDATE', [$transaction['target_id']]);
            if (!$customerPackage || (int) $customerPackage['customer_id'] !== (int) $transaction['customer_id'] || $customerPackage['payment_status'] === 'paid') {
                throw new RuntimeException('Bekleyen paket ödeme hedefi artık uygun değil.');
            }
            DB::execute('UPDATE customer_packages SET payment_status = "paid" WHERE id = ?', [$transaction['target_id']]);
            return;
        }

        if ($transaction['transaction_type'] === 'refund' && !empty($transaction['parent_transaction_id'])) {
            $parent = DB::fetch('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE', [$transaction['parent_transaction_id']]);
            if (!$parent || $parent['status'] !== 'approved') {
                throw new RuntimeException('Ters işlemin asıl kaydı artık uygun değil.');
            }
            DB::execute('UPDATE financial_transactions SET status = "refunded", updated_at = NOW() WHERE id = ?', [$parent['id']]);
            if ($parent['transaction_type'] === 'session_purchase' && $parent['target_type'] === 'reservation') {
                DB::execute('UPDATE reservations SET payment_status = "refunded", updated_at = NOW() WHERE id = ?', [$parent['target_id']]);
            } elseif ($parent['transaction_type'] === 'package_purchase' && !empty($parent['result_target_id'])) {
                DB::execute('UPDATE customer_packages SET status = "cancelled", payment_status = "refunded" WHERE id = ?', [$parent['result_target_id']]);
            } elseif ($parent['transaction_type'] === 'package_purchase' && $parent['target_type'] === 'package') {
                DB::execute('UPDATE customer_packages SET status = "cancelled", payment_status = "refunded" WHERE id = ?', [$parent['target_id']]);
            }
        }
    }

    private static function approveLegacyPayment(array $transaction): void
    {
        if ($transaction['target_type'] === 'package') {
            $target = DB::fetch('SELECT id, payment_status FROM customer_packages WHERE id = ? FOR UPDATE', [$transaction['target_id']]);
            if (!$target || $target['payment_status'] === 'paid') {
                throw new RuntimeException('Eski paket ödeme hedefi bulunamadı veya daha önce ödenmiş.');
            }
            DB::execute('UPDATE customer_packages SET payment_status = "paid" WHERE id = ?', [$transaction['target_id']]);
        } elseif ($transaction['target_type'] === 'reservation') {
            $target = DB::fetch('SELECT id, payment_status FROM reservations WHERE id = ? FOR UPDATE', [$transaction['target_id']]);
            if (!$target || $target['payment_status'] === 'paid') {
                throw new RuntimeException('Eski seans ödeme hedefi bulunamadı veya daha önce ödenmiş.');
            }
            DB::execute('UPDATE reservations SET payment_status = "paid", updated_at = NOW() WHERE id = ?', [$transaction['target_id']]);
        }
    }

    private static function resetRejectedTarget(array $transaction): void
    {
        if ($transaction['transaction_type'] === 'session_purchase' && $transaction['target_type'] === 'reservation') {
            DB::execute('UPDATE reservations SET payment_status = "pending", updated_at = NOW() WHERE id = ? AND payment_status = "awaiting_approval"', [$transaction['target_id']]);
        }
        if ($transaction['transaction_type'] === 'package_purchase' && $transaction['target_type'] === 'package' && $transaction['legacy_source'] === 'customer_packages') {
            DB::execute('UPDATE customer_packages SET status = "cancelled", payment_status = "cancelled" WHERE id = ?', [$transaction['target_id']]);
        }
        if ($transaction['legacy_source'] === 'payments') {
            if ($transaction['target_type'] === 'package') {
                DB::execute('UPDATE customer_packages SET payment_status = "cancelled" WHERE id = ?', [$transaction['target_id']]);
            } elseif ($transaction['target_type'] === 'reservation') {
                DB::execute('UPDATE reservations SET payment_status = "cancelled", updated_at = NOW() WHERE id = ?', [$transaction['target_id']]);
            }
        }
    }

    private static function assertRefundableTarget(array $parent): void
    {
        if ($parent['transaction_type'] !== 'package_purchase') {
            return;
        }
        $customerPackageId = !empty($parent['result_target_id'])
            ? (int) $parent['result_target_id']
            : ($parent['target_type'] === 'package' ? (int) $parent['target_id'] : 0);
        if ($customerPackageId < 1) {
            return;
        }
        $package = DB::fetch('SELECT credits_total, credits_remaining FROM customer_packages WHERE id = ?', [$customerPackageId]);
        if (!$package || (int) $package['credits_remaining'] !== (int) $package['credits_total']) {
            throw new RuntimeException('Kullanılmış hak bulunan paket için doğrudan iade oluşturulamaz.');
        }
        if (DB::fetch('SELECT id FROM reservations WHERE customer_package_id = ? AND status IN ("pending", "confirmed", "completed", "no_show") LIMIT 1', [$customerPackageId])) {
            throw new RuntimeException('Randevuya bağlanmış paket için önce ilgili rezervasyonları düzenleyin.');
        }
    }

    private static function assertCanReviewOwn(array $actor, array $transaction): void
    {
        if ((int) $transaction['created_by'] === (int) $actor['id'] && $actor['role'] !== 'super_admin') {
            throw new RuntimeException('Kendi oluşturduğunuz finans hareketini yalnızca süper yönetici kendi hesabında onaylayabilir.');
        }
    }

    private static function insertTransaction(array $values): int
    {
        return DB::insert(
            'INSERT INTO financial_transactions
                (customer_id, transaction_type, direction, amount, reserved_amount, currency, method, source,
                 target_type, target_id, effective_date, parent_transaction_id, status, provider, reference_no, card_brand,
                 card_last_four, note, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $values['customer_id'], $values['transaction_type'], $values['direction'], $values['amount'],
                $values['reserved_amount'] ?? 0, (string) setting('currency', 'TRY'), $values['method'], $values['source'],
                $values['target_type'] ?? null, $values['target_id'] ?? null, $values['effective_date'] ?? null,
                $values['parent_transaction_id'] ?? null,
                $values['status'], $values['provider'] ?? null, $values['reference_no'] ?? null,
                $values['card_brand'] ?? null, $values['card_last_four'] ?? null, $values['note'] ?? null,
                $values['created_by'] ?? null,
            ]
        );
    }

    private static function validAmount(mixed $value, float $maximum = 1000000): float
    {
        $amount = round((float) $value, 2);
        if ($amount <= 0 || $amount > $maximum) {
            throw new RuntimeException('Tutar sıfırdan büyük ve izin verilen sınırlar içinde olmalıdır.');
        }

        return $amount;
    }

    private static function validPurchaseAmount(mixed $value): float
    {
        $amount = round((float) $value, 2);
        if ($amount < 0 || $amount > 1000000) {
            throw new RuntimeException('Paket tutarı geçersiz.');
        }

        return $amount;
    }

    private static function assertCustomer(int $customerId): void
    {
        if (!DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer" AND status = "active"', [$customerId])) {
            throw new RuntimeException('Aktif danışan bulunamadı.');
        }
    }

    private static function normalizeMethod(string $method): string
    {
        $method = strtolower(trim($method));
        $aliases = ['bank' => 'bank_transfer', 'eft' => 'bank_transfer', 'card' => 'card_manual', 'pos' => 'card_manual', 'paypal' => 'paypal_beta'];

        return $aliases[$method] ?? $method;
    }

    private static function reference(string $prefix): string
    {
        return $prefix . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
