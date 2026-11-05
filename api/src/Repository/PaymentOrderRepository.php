<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Support\Sql\PayablePredicate;
use PDO;

/**
 * Úložiště platebních příkazů (dávek) a jejich položek — snapshot v čase exportu,
 * aby opětovné stažení bylo deterministické a nezávislé na pozdějších změnách faktur.
 *
 * Tenant scope: vždy filtrovat WHERE supplier_id = ?.
 */
final class PaymentOrderRepository
{
    public function __construct(private readonly Connection $db) {}

    public function archiveAfterBankCancellation(int $id, int $supplierId, int $userId): bool
    {
        $query = $this->db->pdo()->prepare('UPDATE payment_orders
            SET archived_at = CURRENT_TIMESTAMP, archived_by_user_id = ?
            WHERE id = ? AND supplier_id = ? AND archived_at IS NULL
              AND EXISTS (SELECT 1 FROM bank_payment_order_submissions s
                          WHERE s.payment_order_id = payment_orders.id AND s.supplier_id = payment_orders.supplier_id)');
        $query->execute([$userId, $id, $supplierId]);
        if ($query->rowCount() === 1) return true;
        $existing = $this->db->pdo()->prepare('SELECT 1 FROM payment_orders
            WHERE id = ? AND supplier_id = ? AND archived_at IS NOT NULL
              AND EXISTS (SELECT 1 FROM bank_payment_order_submissions s
                          WHERE s.payment_order_id = payment_orders.id AND s.supplier_id = payment_orders.supplier_id)');
        $existing->execute([$id, $supplierId]);
        return $existing->fetchColumn() !== false;
    }

    public function deleteUnsubmitted(int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare(
                'DELETE FROM payment_orders WHERE id = ? AND supplier_id = ?
                  AND NOT EXISTS (SELECT 1 FROM bank_payment_order_submissions s
                                   WHERE s.payment_order_id = payment_orders.id
                                     AND s.supplier_id = payment_orders.supplier_id)'
            );
            $delete->execute([$id, $supplierId]);
            if ($delete->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT id FROM payment_orders WHERE id = ? AND supplier_id = ?');
                $exists->execute([$id, $supplierId]);
                $result = $exists->fetchColumn() === false ? 'not_found' : 'submitted';
                $pdo->rollBack();
                return $result;
            }
            $pdo->prepare('DELETE FROM payment_order_items WHERE payment_order_id = ?')->execute([$id]);
            $pdo->commit();
            return 'deleted';
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') return 'submitted';
            throw $e;
        }
    }

    /**
     * Bankovní účty plátce (z `currencies`) pro výběr „z jakého účtu platit".
     * Default = `is_default` účet dané měny.
     *
     * @return list<array<string,mixed>>
     */
    public function payerAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, account_number, bank_code, bank_name, iban, bic,
                    is_default, is_active
               FROM currencies
              WHERE supplier_id = ?
           ORDER BY code, is_default DESC, label'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_default'] = (bool) $r['is_default'];
            $r['is_active']  = (bool) $r['is_active'];
        }
        return $rows;
    }

    /** Jeden účet plátce (currencies) v rámci tenanta, nebo null. */
    public function payerAccount(int $currencyId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, account_number, bank_code, bank_name, iban, bic, is_default
               FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /**
     * Uloží dávku + položky v transakci. Vrací ID nové dávky.
     *
     * @param array<string,mixed>        $order hlavička
     * @param list<array<string,mixed>>  $items položky
     */
    public function create(array $order, array $items): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_orders
                   (supplier_id, currency, payer_currency_id, payer_account_number, payer_bank_code,
                    payer_iban, payer_bic, payer_account_label, payment_date, total_amount, item_count,
                    note, mark_paid, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $order['supplier_id'],
                (string) $order['currency'],
                $order['payer_currency_id'] !== null ? (int) $order['payer_currency_id'] : null,
                $order['payer_account_number'] ?? null,
                $order['payer_bank_code'] ?? null,
                $order['payer_iban'] ?? null,
                $order['payer_bic'] ?? null,
                $order['payer_account_label'] ?? null,
                (string) $order['payment_date'],
                (float) $order['total_amount'],
                count($items),
                $order['note'] ?? null,
                !empty($order['mark_paid']) ? 1 : 0,
                $order['created_by_user_id'] !== null ? (int) $order['created_by_user_id'] : null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO payment_order_items
                   (payment_order_id, purchase_invoice_id, invoice_id, tax_submission_id, tax_advance_schedule_id,
                    other_item_id, other_item_installment_id,
                    payee_name, payee_account_number, payee_bank_code, payee_iban, payee_bic, amount, currency,
                    variable_symbol, constant_symbol, specific_symbol, message, account_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $it) {
                $itemStmt->execute([
                    $orderId,
                    isset($it['purchase_invoice_id']) ? (int) $it['purchase_invoice_id'] : null,
                    isset($it['invoice_id']) ? (int) $it['invoice_id'] : null,
                    isset($it['tax_submission_id']) ? (int) $it['tax_submission_id'] : null,
                    isset($it['tax_advance_schedule_id']) ? (int) $it['tax_advance_schedule_id'] : null,
                    isset($it['other_item_id']) ? (int) $it['other_item_id'] : null,
                    isset($it['other_item_installment_id']) ? (int) $it['other_item_installment_id'] : null,
                    $it['payee_name'] ?? null,
                    $it['payee_account_number'] ?? null,
                    $it['payee_bank_code'] ?? null,
                    $it['payee_iban'] ?? null,
                    $it['payee_bic'] ?? null,
                    (float) $it['amount'],
                    (string) $it['currency'],
                    $it['variable_symbol'] ?? null,
                    $it['constant_symbol'] ?? null,
                    $it['specific_symbol'] ?? null,
                    $it['message'] ?? null,
                    (string) ($it['account_verified'] ?? 'na'),
                ]);
            }

            $pdo->commit();
            return $orderId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Hlavička dávky v rámci tenanta, nebo null. */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_orders WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = $this->castOrder($row);
        $row['items'] = $this->itemsFor($id);
        return $row;
    }

    /**
     * Historie dávek tenanta (bez položek; položky jen v detailu/downloadu), stránkovaně.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, int $limit = 50, int $offset = 0): array
    {
        // LIMIT/OFFSET inlinujeme jako validované inty (vzor StockItemRepository::list) —
        // native prepared statements neumí LIMIT/OFFSET s parametrem typu string.
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_orders WHERE supplier_id = ? AND archived_at IS NULL ORDER BY created_at DESC, id DESC'
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute([$supplierId]);
        return array_map([$this, 'castOrder'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** COUNT(*) dávek tenanta (bez LIMIT), pro paginaci historie. */
    public function countHistory(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM payment_orders WHERE supplier_id = ? AND archived_at IS NULL');
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    public function allItemsStillPayable(int $orderId, int $supplierId, string $currency): bool
    {
        $outstanding = PayablePredicate::outstandingBalanceCondition('pi');
        $remaining = '(' . PayablePredicate::remainingExpression('pi') . ') + COALESCE(pi.rounding, 0)';
        $payableDocument = PayablePredicate::advanceVatDocumentCondition('pi');
        $openRefund = RefundDocument::openRefundSql('inv');
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total_count,
                    SUM(CASE WHEN pi.id IS NOT NULL
                                  AND pi.status IN ('received','booked')
                                  AND {$payableDocument}
                                  AND pi.payment_method = 'bank_transfer'
                                  AND cur.code = ?
                                  AND {$outstanding}
                                  AND GREATEST({$remaining}, 0) + 0.005 >= poi.amount
                             THEN 1
                             WHEN inv.id IS NOT NULL
                                  AND {$openRefund}
                                  AND inv.payment_method <> 'cash'
                                  AND icur.code = ?
                                  AND -inv.amount_to_pay + 0.005 >= poi.amount
                             THEN 1
                             -- Daň FÚ: zda už ji výpis uhradil, ověřuje seznam kandidátů; tady
                             -- stačí, že jde o korunový příkaz (daně se platí jen v CZK).
                             WHEN (poi.tax_submission_id IS NOT NULL OR poi.tax_advance_schedule_id IS NOT NULL)
                                  AND ? = 'CZK'
                             THEN 1
                             -- Ostatní závazek: pořád otevřený a zbytek pokryje platbu. Zbytek
                             -- splátky dokontroluje otherItemInstallmentsStillPayable().
                             WHEN oi.id IS NOT NULL
                                  AND oi.side = 'payable'
                                  AND oi.status IN ('confirmed','posted')
                                  AND oi.deleted_at IS NULL
                                  AND oi.currency = ?
                                  AND oi.amount - oi.settled_outside_amount
                                      - COALESCE((SELECT SUM(a.amount) FROM other_item_allocations a
                                                   WHERE a.other_item_id = oi.id AND a.supplier_id = oi.supplier_id
                                                     AND a.reversed_on IS NULL), 0) + 0.005 >= poi.amount
                             THEN 1 ELSE 0 END) AS payable_count
               FROM payment_order_items poi
          LEFT JOIN purchase_invoices pi
                 ON pi.id = poi.purchase_invoice_id AND pi.supplier_id = ?
          LEFT JOIN currencies cur ON cur.id = pi.currency_id AND cur.supplier_id = pi.supplier_id
          LEFT JOIN invoices inv
                 ON inv.id = poi.invoice_id AND inv.supplier_id = ?
          LEFT JOIN currencies icur ON icur.id = inv.currency_id AND icur.supplier_id = inv.supplier_id
          LEFT JOIN other_items oi
                 ON oi.id = poi.other_item_id AND oi.supplier_id = ?
              WHERE poi.payment_order_id = ?"
        );
        $code = strtoupper($currency);
        $stmt->execute([$code, $code, $code, $code, $supplierId, $supplierId, $supplierId, $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total_count'] ?? 0);
        return $total > 0 && $total === (int) ($row['payable_count'] ?? 0)
            && $this->otherItemInstallmentsStillPayable($orderId, $supplierId);
    }

    /**
     * Položka na splátku ostatního závazku smí do banky, jen dokud zbytek té splátky
     * ({@see \MyInvoice\Service\Accounting\OtherItemInstallmentCoverage}) platbu pokryje.
     * Kontrola zbytku celé položky by nepoznala, že splátku mezitím uhradila jiná platba.
     */
    private function otherItemInstallmentsStillPayable(int $orderId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT other_item_id, other_item_installment_id, amount FROM payment_order_items
              WHERE payment_order_id = ? AND other_item_installment_id IS NOT NULL'
        );
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return true;
        }
        $coverage = (new OtherItemRepository($this->db))->installmentCoverage(
            $supplierId,
            array_map(static fn (array $r): int => (int) $r['other_item_id'], $rows),
        );
        foreach ($rows as $r) {
            $remaining = null;
            foreach ($coverage[(int) $r['other_item_id']] ?? [] as $installment) {
                if ($installment['id'] === (int) $r['other_item_installment_id']) {
                    $remaining = (float) $installment['remaining_amount'];
                }
            }
            if ($remaining === null || $remaining + 0.005 < (float) $r['amount']) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_order_items WHERE payment_order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']                  = (int) $r['id'];
            $r['payment_order_id']    = (int) $r['payment_order_id'];
            $r['purchase_invoice_id'] = $r['purchase_invoice_id'] !== null ? (int) $r['purchase_invoice_id'] : null;
            $r['invoice_id']          = isset($r['invoice_id']) ? (int) $r['invoice_id'] : null;
            $r['tax_submission_id']   = isset($r['tax_submission_id']) ? (int) $r['tax_submission_id'] : null;
            $r['tax_advance_schedule_id'] = isset($r['tax_advance_schedule_id']) ? (int) $r['tax_advance_schedule_id'] : null;
            $r['other_item_id']       = isset($r['other_item_id']) ? (int) $r['other_item_id'] : null;
            $r['other_item_installment_id'] = isset($r['other_item_installment_id']) ? (int) $r['other_item_installment_id'] : null;
            $r['amount']              = (float) $r['amount'];
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function castOrder(array $row): array
    {
        $row['id']                = (int) $row['id'];
        $row['supplier_id']       = (int) $row['supplier_id'];
        $row['payer_currency_id'] = $row['payer_currency_id'] !== null ? (int) $row['payer_currency_id'] : null;
        $row['total_amount']      = (float) $row['total_amount'];
        $row['item_count']        = (int) $row['item_count'];
        $row['mark_paid']         = (bool) $row['mark_paid'];
        return $row;
    }
}
