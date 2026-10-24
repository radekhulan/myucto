<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use PDO;

/** Fyzické platby převzaté z účetního programu, bez přepočtu zdrojové částky. */
final class MigratedPaymentWriter
{
    public const OPENING_CASH_NOTE = OpeningBalanceDocuments::NOTE;

    public function __construct(private readonly Connection $db) {}

    public function cashRegister(int $supplierId, string $name): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND currency_code = "CZK" ORDER BY is_default DESC, id LIMIT 1');
        $stmt->execute([$supplierId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $this->db->pdo()->prepare('INSERT INTO cash_registers (supplier_id, name, currency_code, is_active, is_default)
            VALUES (?, ?, "CZK", 1, 1)')->execute([$supplierId, $name]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertCash(int $supplierId, int $registerId, string $number, string $date,
        string $description, float $signedAmount, bool $draft, ?int $userId): int
    {
        $this->db->pdo()->prepare('INSERT INTO cash_documents
            (supplier_id, register_id, doc_number, doc_type, issue_date, description, purpose,
             total_amount, currency_code, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, "other", ?, "CZK", ?, ?)')->execute([
            $supplierId, $registerId, $number, $signedAmount >= 0 ? 'in' : 'out', $date,
            $description, abs($signedAmount), $draft ? 'draft' : 'posted', $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Potvrdí evidenční koncept a informativně naváže již ověřené otevírací zápisy.
     * Důkaz zdroje a zámky dokladu i deníku zajistí adaptér před zavoláním celé dávky.
     * @param non-empty-list<int> $entryIds
     */
    public function confirmOpeningCash(int $supplierId, int $cashId, array $entryIds, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) throw new \LogicException('Potvrzení počátečního stavu pokladny vyžaduje transakci převodu.');
        if ($entryIds === []) throw new \InvalidArgumentException('Chybí ověřená otevírací kontace.');
        $insert = $pdo->prepare("INSERT INTO journal_entry_document_links (supplier_id,entry_id,doc_type,doc_id,note,created_by) VALUES (?,?,'cash',?,?,?)");
        foreach ($entryIds as $entryId) $insert->execute([$supplierId, $entryId, $cashId, self::OPENING_CASH_NOTE, $userId]);
        // journal_entry_id se NESMÍ nastavit: storno dokladu by stornovalo celý otevírací zápis.
        $promote = $pdo->prepare("UPDATE cash_documents SET status='posted' WHERE supplier_id=? AND id=? AND status='draft' AND journal_entry_id IS NULL");
        $promote->execute([$supplierId, $cashId]);
        if ($promote->rowCount() !== 1) throw new \InvalidArgumentException('Evidenční koncept pokladny byl změněn.');
    }

    /** @return int ID payment_matches nebo hotovostního dokladu pro mapu převodu. */
    public function attach(int $supplierId, ?int $userId, string $documentKind, string $movementKind,
        int $documentId, int $movementId, float $amount): int
    {
        $pdo = $this->db->pdo();
        $this->assertHomeDocument($supplierId, $documentKind, $documentId);
        if ($movementKind === 'bank') {
            $currency = $pdo->prepare('SELECT bt.currency FROM bank_transactions bt
                JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE bt.id = ? AND bs.supplier_id = ?');
            $currency->execute([$movementId, $supplierId]);
            $movementCurrency = $currency->fetchColumn();
            if ($movementCurrency === false) {
                throw new \InvalidArgumentException('payment_target_missing');
            }
            if ($movementCurrency !== 'CZK') throw new \InvalidArgumentException('payment_currency_unverified');
            $pdo->prepare('INSERT INTO payment_matches
                (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
                VALUES (?, ?, ?, ?, ?, "manual", ?)')->execute([
                $supplierId, $movementId, $documentKind === 'issued' ? $documentId : null,
                $documentKind === 'purchase' ? $documentId : null, $amount, $userId,
            ]);
            $linkId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE bank_transactions SET match_status = "manual", matched_at = NOW(), matched_by = ?
                WHERE id = ? AND statement_id IN (SELECT id FROM bank_statements WHERE supplier_id = ?)')
                ->execute([$userId, $movementId, $supplierId]);
        } else {
            if ($movementKind !== 'cash') throw new \InvalidArgumentException('payment_target_missing');
            $column = $documentKind === 'issued' ? 'invoice_id' : 'purchase_invoice_id';
            $purpose = $documentKind === 'issued' ? 'invoice_payment' : 'purchase_payment';
            $stmt = $pdo->prepare('SELECT invoice_id, purchase_invoice_id, currency_code, total_amount
                FROM cash_documents WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$movementId, $supplierId]);
            $cash = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cash === false) throw new \InvalidArgumentException('payment_target_missing');
            if ($cash['currency_code'] !== 'CZK') throw new \InvalidArgumentException('payment_currency_unverified');
            if ($cash['invoice_id'] !== null || $cash['purchase_invoice_id'] !== null) {
                throw new \InvalidArgumentException('cash_payment_ambiguous');
            }
            if (abs((float) $cash['total_amount'] - $amount) > 0.01) {
                throw new \InvalidArgumentException('cash_payment_ambiguous');
            }
            $pdo->prepare("UPDATE cash_documents SET {$column} = ?, purpose = ?, vat_mode = 'none' WHERE id = ? AND supplier_id = ?")
                ->execute([$documentId, $purpose, $movementId, $supplierId]);
            $linkId = $movementId;
        }
        if ($documentKind === 'issued') {
            $date = $this->movementDate($movementKind, $movementId, $supplierId);
            $pdo->prepare('INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, bank_transaction_id, created_by)
                VALUES (?, ?, ?, ?, "CZK", ?, ?, ?)')->execute([
                $supplierId, $documentId, $date, $amount, $movementKind,
                $movementKind === 'bank' ? $movementId : null, $userId,
            ]);
            if ($movementKind === 'cash') {
                $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ?')
                    ->execute([(int) $pdo->lastInsertId(), $movementId, $supplierId]);
            }
        }
        return $linkId;
    }

    public function statementId(int $supplierId, int $bankTransactionId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT bt.statement_id FROM bank_transactions bt
            JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE bt.id = ? AND bs.supplier_id = ?');
        $stmt->execute([$bankTransactionId, $supplierId]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $id : null;
    }

    public function movementDate(string $kind, int $id, int $supplierId): string
    {
        $stmt = $kind === 'bank'
            ? $this->db->pdo()->prepare('SELECT bt.posted_at FROM bank_transactions bt
                JOIN bank_statements bs ON bs.id = bt.statement_id WHERE bt.id = ? AND bs.supplier_id = ?')
            : $this->db->pdo()->prepare('SELECT issue_date FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        $date = $stmt->fetchColumn();
        if ($date === false) throw new \InvalidArgumentException('movement_missing');
        return (string) $date;
    }

    /** @param list<int> $issued @param list<int> $purchases @param list<int> $statements */
    public function refreshBalances(int $supplierId, array $issued, array $purchases, array $statements): void
    {
        $pdo = $this->db->pdo();
        foreach (array_unique($issued) as $id) {
            $this->assertHomeDocument($supplierId, 'issued', $id);
            $pdo->prepare('UPDATE invoices SET paid_total =
                (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?),
                paid_at = CASE WHEN
                    (SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?)
                    >= ABS(total_with_vat) - 0.01
                    THEN (SELECT MAX(paid_on) FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ?)
                    ELSE NULL END
                WHERE supplier_id = ? AND id = ? AND status <> "cancelled"')->execute([
                $supplierId, $id, $supplierId, $id, $supplierId, $id, $supplierId, $id,
            ]);
            $pdo->prepare('UPDATE invoices SET status = "paid" WHERE supplier_id = ? AND id = ?
                AND status NOT IN ("draft", "cancelled")
                AND paid_total >= ABS(total_with_vat) - 0.01')->execute([$supplierId, $id]);
        }
        foreach (array_unique($purchases) as $id) {
            $this->assertHomeDocument($supplierId, 'purchase', $id);
            $pdo->prepare('UPDATE purchase_invoices SET paid_amount_invoice_ccy =
                (SELECT COALESCE(SUM(pm.amount), 0) FROM payment_matches pm
                  WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?)
                + (SELECT COALESCE(SUM(cd.total_amount), 0) FROM cash_documents cd
                    WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND cd.status = "posted")
                WHERE supplier_id = ? AND id = ? AND status <> "cancelled"')->execute([
                $supplierId, $id, $supplierId, $id, $supplierId, $id,
            ]);
            $pdo->prepare('UPDATE purchase_invoices SET status = "paid",
                paid_at = NULLIF(GREATEST(
                    COALESCE((SELECT MAX(bt.posted_at) FROM payment_matches pm
                        JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
                        WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ?), "1000-01-01"),
                    COALESCE((SELECT MAX(cd.issue_date) FROM cash_documents cd
                        WHERE cd.supplier_id = ? AND cd.purchase_invoice_id = ? AND cd.status = "posted"), "1000-01-01")
                ), "1000-01-01")
                WHERE supplier_id = ? AND id = ? AND status NOT IN ("draft", "cancelled")
                  AND paid_amount_invoice_ccy >= ABS(total_with_vat + rounding) - 0.01')->execute([
                $supplierId, $id, $supplierId, $id, $supplierId, $id,
            ]);
        }
        foreach (array_unique($statements) as $id) {
            $pdo->prepare('UPDATE bank_statements SET transaction_count =
                (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ?),
                credit_total = (SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE statement_id = ? AND amount > 0),
                debit_total = (SELECT COALESCE(SUM(-amount), 0) FROM bank_transactions WHERE statement_id = ? AND amount < 0),
                matched_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = ? AND match_status <> "unmatched")
                WHERE id = ? AND supplier_id = ?')->execute([$id, $id, $id, $id, $id, $supplierId]);
        }
    }

    private function assertHomeDocument(int $supplierId, string $kind, int $documentId): void
    {
        $table = match ($kind) {
            'issued' => 'invoices', 'purchase' => 'purchase_invoices',
            default => throw new \InvalidArgumentException('payment_target_missing'),
        };
        $owner = $this->db->pdo()->prepare("SELECT c.code FROM {$table} d
            JOIN currencies c ON c.id = d.currency_id AND c.supplier_id = d.supplier_id
            WHERE d.id = ? AND d.supplier_id = ?");
        $owner->execute([$documentId, $supplierId]);
        $currency = $owner->fetchColumn();
        if ($currency === false) throw new \InvalidArgumentException('payment_target_missing');
        if ($currency !== 'CZK') throw new \InvalidArgumentException('payment_currency_unverified');
    }
}
