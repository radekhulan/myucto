<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use PDO;

/**
 * Úhrady faktury navázané na ostatní pohledávku (migrace 2246).
 *
 * Peníze patří pohledávce: banka a pokladna se párují na ni a deník je účtuje proti
 * jejímu účtu. Faktura je jen zrcadlí platbami se zdrojem `other_item`, bez bankovní
 * vazby a bez zápisu v deníku. Zdroj není v {@see InvoicePaymentService::BANK_RECONCILABLE_SOURCES},
 * takže je párování banky znovu nenaváže.
 *
 * Volá se uvnitř transakce každé cesty, která mění přiřazení úhrad pohledávky
 * (přiřazení, odpojení, storno bankovního či pokladního zápisu, zrušení párování),
 * a při navázání či odpojení faktury. PDF se znovu vygeneruje při příštím zobrazení.
 */
final class InvoiceOtherItemPayments
{
    public const SOURCE = 'other_item';

    /** Faktury, jejichž platby lze evidovat ({@see InvoicePaymentService::recordPayment()}). */
    private const PAYABLE_STATUSES = ['issued', 'sent', 'reminded', 'paid'];

    /**
     * SQL podmínka „faktura není krytá ostatní pohledávkou" pro výběry plateb k párování.
     */
    public static function notLinkedSql(string $alias): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias faktury.');
        }
        return "{$alias}.other_item_id IS NULL";
    }

    /**
     * Zesynchronizuje platby faktur navázaných na dané pohledávky.
     *
     * @param list<int> $otherItemIds
     * @return list<int> faktury, kterým se platby změnily
     */
    public static function syncForItems(PDO $pdo, int $supplierId, array $otherItemIds): array
    {
        $otherItemIds = array_values(array_unique(array_filter(array_map('intval', $otherItemIds), static fn (int $id): bool => $id > 0)));
        if ($otherItemIds === []) return [];
        $in = implode(',', array_fill(0, count($otherItemIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM invoices WHERE supplier_id = ? AND other_item_id IN ({$in}) ORDER BY id");
        $stmt->execute([$supplierId, ...$otherItemIds]);
        $changed = [];
        foreach (array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) as $invoiceId) {
            if (self::syncInvoice($pdo, $supplierId, $invoiceId)) $changed[] = $invoiceId;
        }
        return $changed;
    }

    /**
     * Pohledávky, kterým bankovní pohyb nebo pokladní doklad hradí úhradu. Volající je
     * načte před změnou přiřazení a po ní předá do {@see syncForItems()}.
     *
     * @param 'bank_transaction_id'|'cash_document_id' $column
     * @return list<int>
     */
    public static function itemsOfPayment(PDO $pdo, int $supplierId, string $column, int $sourceId): array
    {
        if (!in_array($column, ['bank_transaction_id', 'cash_document_id'], true)) {
            throw new \InvalidArgumentException('Neplatný zdroj úhrady.');
        }
        $stmt = $pdo->prepare("SELECT DISTINCT other_item_id FROM other_item_allocations WHERE supplier_id = ? AND {$column} = ?");
        $stmt->execute([$supplierId, $sourceId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Přepíše platby `other_item` faktury podle aktivních úhrad její pohledávky. Faktura
     * bez vazby, koncept ani stornovaná faktura je nemají. Vrací true, když se změnily.
     */
    public static function syncInvoice(PDO $pdo, int $supplierId, int $invoiceId): bool
    {
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Synchronizace úhrad ostatní pohledávky vyžaduje transakci.');
        }
        $lock = $pdo->prepare(
            'SELECT i.id, i.status, i.other_item_id, cur.code AS currency, oi.document_no
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
               LEFT JOIN other_items oi ON oi.id = i.other_item_id AND oi.supplier_id = i.supplier_id
              WHERE i.id = ? AND i.supplier_id = ? FOR UPDATE'
        );
        $lock->execute([$invoiceId, $supplierId]);
        $invoice = $lock->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) return false;

        $wanted = [];
        if ($invoice['other_item_id'] !== null && in_array((string) $invoice['status'], self::PAYABLE_STATUSES, true)) {
            $allocations = $pdo->prepare(
                'SELECT payment_on, amount FROM other_item_allocations
                  WHERE supplier_id = ? AND other_item_id = ? AND reversed_on IS NULL AND amount > 0
                  ORDER BY payment_on, id'
            );
            $allocations->execute([$supplierId, (int) $invoice['other_item_id']]);
            foreach ($allocations->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $wanted[] = [substr((string) $row['payment_on'], 0, 10), number_format((float) $row['amount'], 2, '.', '')];
            }
        }
        $current = $pdo->prepare('SELECT paid_on, amount FROM invoice_payments
            WHERE invoice_id = ? AND supplier_id = ? AND source = ? ORDER BY paid_on, id');
        $current->execute([$invoiceId, $supplierId, self::SOURCE]);
        $existing = array_map(static fn (array $row): array => [
            substr((string) $row['paid_on'], 0, 10), number_format((float) $row['amount'], 2, '.', ''),
        ], $current->fetchAll(PDO::FETCH_ASSOC));
        if ($existing === $wanted) return false;

        $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source = ?')
            ->execute([$invoiceId, $supplierId, self::SOURCE]);
        if ($wanted !== []) {
            $note = mb_substr('Úhrada ostatní pohledávky ' . ($invoice['document_no'] ?? ('#' . $invoice['other_item_id'])), 0, 255);
            $insert = $pdo->prepare(
                'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($wanted as [$paidOn, $amount]) {
                $insert->execute([$supplierId, $invoiceId, $paidOn, $amount, (string) $invoice['currency'], $note, self::SOURCE]);
            }
        }
        InvoicePaymentService::recomputeLocked($pdo, $invoiceId);
        // Řádek „Uhrazeno" a QR na PDF: soubor se při příštím zobrazení vygeneruje znovu.
        $pdo->prepare('UPDATE invoices SET pdf_path = NULL, pdf_generated_at = NULL WHERE id = ? AND supplier_id = ?')
            ->execute([$invoiceId, $supplierId]);
        return true;
    }
}
