<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use PDO;

/**
 * Uvolnění bankovního pohybu z párování a zaúčtování — jediné místo pro „Zrušit
 * spárování" i pro smazání výpisu.
 *
 * Smazání výpisu dřív jen smazalo řádek a spolehlo na cizí klíče: pohyb zmizel
 * kaskádou, platba faktury zůstala bez vazby (SET NULL) a bankovní zápis v deníku
 * zůstal viset jako sirotek (`journal_entries.source_id` je polymorfní, bez FK).
 * Po opětovném importu se táž úhrada spárovala a zaúčtovala podruhé. Proto výpis
 * před smazáním každý navázaný pohyb uvolní stejně jako ruční zrušení párování.
 *
 * Režimy se liší jen v deníku:
 *   - `unmatch` — {@see BankPostingService::releaseMatch()}: platba kartou přes
 *     mezičlen si bankovní zápis 378.x/221 ponechá (platba proběhla, chybí jen doklad);
 *   - `delete`  — {@see BankPostingService::unpost()}: pohyb přestane existovat,
 *     takže se stornuje i bankovní zápis včetně vypořádání karty.
 *
 * Běží v transakci volajícího; storno v uzavřeném nebo zamčeném období vyhodí
 * {@see BankTransactionReleaseException} a volající musí celou operaci vrátit.
 */
final class BankTransactionReleaseService
{
    public const MODE_UNMATCH = 'unmatch';
    public const MODE_DELETE = 'delete';

    /** Zápisy deníku, jejichž `source_id` je id bankovního pohybu. */
    public const TRANSACTION_SOURCE_TYPES = ['bank', 'card_settlement', 'card_writeoff'];

    private const MATCHED_STATUSES = "('auto_exact', 'auto_partial', 'manual')";

    public function __construct(
        private readonly Connection $db,
        private readonly InvoicePaymentService $payments,
        private readonly BankPostingService $bankPosting,
        private readonly AutoPostingPolicyService $policy,
    ) {}

    /**
     * Pohyby výpisu, které před smazáním potřebují uvolnit: spárované, s platbou,
     * s vazbou na přijatou fakturu nebo s živým zápisem v deníku.
     *
     * @return list<int>
     */
    public function releasableTransactionIds(int $supplierId, int $statementId): array
    {
        $types = self::sourceTypesSql();
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id
               FROM bank_transactions bt
              WHERE bt.statement_id = ?
                AND (bt.match_status IN " . self::MATCHED_STATUSES . "
                     OR bt.matched_invoice_id IS NOT NULL
                     OR EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = ? AND je.source_type IN ($types)
                                   AND je.source_id = bt.id AND je.reversed_by IS NULL))
              ORDER BY bt.id"
        );
        $stmt->execute([$statementId, $supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Co brání pohyby výpisu uvolnit — počty pohybů po důvodech, jen nenulové.
     * Kontrola předem pro srozumitelnou hlášku; vynucení drží {@see release()}.
     *
     * @return array<string,int> tax_document | closed_period | draft_entry => počet pohybů
     */
    public function deletionBlockers(int $supplierId, int $statementId): array
    {
        $txIds = $this->releasableTransactionIds($supplierId, $statementId);
        if ($txIds === []) {
            return [];
        }

        $counts = [];
        $taxDocuments = $this->transactionsWithTaxDocument($txIds);
        if ($taxDocuments > 0) {
            $counts['tax_document'] = $taxDocuments;
        }

        $placeholders = implode(',', array_fill(0, count($txIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT source_id, entry_date, posted_at
               FROM journal_entries
              WHERE supplier_id = ? AND source_type IN (' . self::sourceTypesSql() . ")
                AND source_id IN ($placeholders) AND reversed_by IS NULL"
        );
        $stmt->execute(array_merge([$supplierId], $txIds));
        $closed = [];
        $drafts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $txId = (int) $row['source_id'];
            if ($row['posted_at'] === null) {
                $drafts[$txId] = true;
            } elseif (!$this->policy->isOpenDate($supplierId, (string) $row['entry_date'])) {
                $closed[$txId] = true;
            }
        }
        if ($closed !== []) {
            $counts['closed_period'] = count($closed);
        }
        if ($drafts !== []) {
            $counts['draft_entry'] = count($drafts);
        }

        return $counts;
    }

    /**
     * Uvolní pohyb: storno/odpojení zápisu v deníku, zrušení platby faktury (nebo
     * odpojení dřívější platby, kterou párování jen navázalo), zrušení vazby na
     * přijatou fakturu, přepočet stavu dokladů a návrat pohybu mezi nespárované.
     *
     * @return array{previous_invoice_id:?int, previous_status:string, deleted_payment:bool, journal:string}
     * @throws BankTransactionReleaseException pohyb nelze bezpečně uvolnit
     */
    public function release(int $supplierId, int $txId, string $mode, ?int $userId = null): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT id, statement_id, matched_invoice_id, posted_at, match_status
                   FROM bank_transactions WHERE id = ?'
            );
            $stmt->execute([$txId]);
            $tx = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($tx === false) {
                throw new BankTransactionReleaseException('not_found', 'Transakce nenalezena.', 404);
            }

            // Guard (#89): nestornovaný daňový doklad k přijaté platbě — zrušení platby
            // by rozbilo daňovou stopu. Doklad je třeba nejdřív smazat (koncept) nebo stornovat.
            if ($this->transactionsWithTaxDocument([$txId]) > 0) {
                throw new BankTransactionReleaseException(
                    'has_tax_document',
                    'K platbě z této transakce je vystavený daňový doklad k přijaté platbě. Nejdřív ho smaž (koncept) nebo stornuj.',
                );
            }

            $result = $this->releaseLocked($pdo, $supplierId, $tx, $mode, $userId);
            if ($ownTx) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{previous_invoice_id:?int, previous_status:string, deleted_payment:bool, journal:string}
     */
    private function releaseLocked(PDO $pdo, int $supplierId, array $tx, string $mode, ?int $userId): array
    {
        $txId = (int) $tx['id'];
        $statementId = (int) $tx['statement_id'];
        $invoiceId = $tx['matched_invoice_id'] !== null ? (int) $tx['matched_invoice_id'] : 0;
        $postedAt = (string) ($tx['posted_at'] ?? '');

        $journal = $this->releaseJournal($supplierId, $txId, $mode, $userId);

        $purchaseStmt = $pdo->prepare(
            'SELECT purchase_invoice_id FROM payment_matches
              WHERE bank_transaction_id = ? AND supplier_id = ? AND purchase_invoice_id IS NOT NULL'
        );
        $purchaseStmt->execute([$txId, $supplierId]);
        $purchaseInvoiceIds = array_values(array_unique(array_map('intval', $purchaseStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        $pdo->prepare(
            "UPDATE bank_transactions
                SET matched_invoice_id = NULL,
                    match_status       = 'unmatched',
                    ignore_note        = NULL,
                    matched_at         = NULL,
                    matched_by         = NULL
              WHERE id = ?"
        )->execute([$txId]);

        // Platbu založenou párováním smaže, dřívější platbu jen odpojí; stav faktury
        // se přepočte z plateb (může se vrátit ze stavu 'paid').
        $deletedPayment = $this->payments->deleteForBankTransaction($txId);

        $pdo->prepare('DELETE FROM payment_matches WHERE bank_transaction_id = ? AND supplier_id = ?')
            ->execute([$txId, $supplierId]);
        $this->restorePurchaseInvoices($pdo, $supplierId, $purchaseInvoiceIds, $postedAt);

        if (!$deletedPayment && $invoiceId > 0 && $postedAt !== '') {
            $this->releaseLegacyInvoiceMatch($pdo, $txId, $invoiceId, $postedAt);
        }

        if ($statementId > 0) {
            $pdo->prepare(
                "UPDATE bank_statements
                    SET matched_count = (
                        SELECT COUNT(*) FROM bank_transactions
                         WHERE statement_id = ?
                           AND match_status IN " . self::MATCHED_STATUSES . "
                    )
                  WHERE id = ?"
            )->execute([$statementId, $statementId]);
        }

        return [
            'previous_invoice_id' => $invoiceId > 0 ? $invoiceId : null,
            'previous_status'     => (string) ($tx['match_status'] ?? ''),
            'deleted_payment'     => $deletedPayment,
            'journal'             => $journal,
        ];
    }

    /** @return string reversed | released | none */
    private function releaseJournal(int $supplierId, int $txId, string $mode, ?int $userId): string
    {
        $meta = ['user_id' => $userId, 'reason' => $mode];
        try {
            if ($mode === self::MODE_DELETE) {
                $this->bankPosting->unpost($supplierId, $txId, $meta + [
                    'description' => 'Storno bankovního zápisu — smazání výpisu',
                ]);
                return 'reversed';
            }
            $this->bankPosting->releaseMatch($supplierId, $txId, $meta);
            return 'released';
        } catch (PostingException $e) {
            if ($e->errorCode === 'not_found') {
                return 'none';
            }
            throw new BankTransactionReleaseException($e->errorCode, $e->getMessage(), 409, $e);
        }
    }

    /**
     * Přijatá faktura, kterou uhradilo jen tohle párování, se vrací do stavu podle
     * zaúčtování. Ručně nastavený stav (jiné paid_at) ani fakturu s další vazbou
     * na platbu nemění.
     *
     * @param list<int> $purchaseInvoiceIds
     */
    private function restorePurchaseInvoices(PDO $pdo, int $supplierId, array $purchaseInvoiceIds, string $postedAt): void
    {
        foreach ($purchaseInvoiceIds as $purchaseInvoiceId) {
            $hasPosting = $pdo->prepare(
                "SELECT 1 FROM journal_entries
                  WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                    AND posted_at IS NOT NULL AND reversed_by IS NULL LIMIT 1"
            );
            $hasPosting->execute([$supplierId, $purchaseInvoiceId]);
            $restoredStatus = $hasPosting->fetchColumn() === false ? 'received' : 'booked';
            $pdo->prepare(
                "UPDATE purchase_invoices pi
                    SET pi.status = ?, pi.paid_at = NULL
                  WHERE pi.id = ? AND pi.supplier_id = ? AND pi.status = 'paid' AND pi.paid_at = ?
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id)"
            )->execute([$restoredStatus, $purchaseInvoiceId, $supplierId, $postedAt]);
        }
    }

    /**
     * Párování z doby před evidencí plateb (žádná platba navázaná na pohyb): fakturu
     * označenou jako zaplacenou k datu tohoto pohybu, kterou nedrží jiný spárovaný
     * pohyb, vrátí do nezaplacených — ale jen když ji nekryjí jiné platby. O stavu
     * rozhoduje přepočet z plateb, ne ruční UPDATE: faktura zaplacená platbou, která
     * ztratila vazbu na pohyb (smazaný výpis), musí zůstat zaplacená.
     */
    private function releaseLegacyInvoiceMatch(PDO $pdo, int $txId, int $invoiceId, string $postedAt): void
    {
        $other = $pdo->prepare(
            "SELECT COUNT(*) FROM bank_transactions
              WHERE matched_invoice_id = ?
                AND match_status IN " . self::MATCHED_STATUSES . "
                AND id <> ?"
        );
        $other->execute([$invoiceId, $txId]);
        if ((int) $other->fetchColumn() > 0) {
            return;
        }

        $inv = $pdo->prepare("SELECT 1 FROM invoices WHERE id = ? AND status = 'paid' AND paid_at = ? FOR UPDATE");
        $inv->execute([$invoiceId, $postedAt]);
        if ($inv->fetchColumn() === false) {
            return;
        }

        // Backfill 'legacy' platba (migrace 0108) odpovídá právě tomuto historickému
        // spárování — zmizí s ním. Ostatní platby zůstávají a rozhodnou o stavu.
        $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'legacy'")
            ->execute([$invoiceId]);
        $this->payments->recompute($invoiceId);
    }

    /** @param list<int> $txIds */
    private function transactionsWithTaxDocument(array $txIds): int
    {
        if ($txIds === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($txIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT p.bank_transaction_id)
               FROM invoice_payments p
               JOIN invoices td ON td.id = p.tax_document_invoice_id
              WHERE p.bank_transaction_id IN ($placeholders) AND td.status <> 'cancelled'"
        );
        $stmt->execute($txIds);
        return (int) $stmt->fetchColumn();
    }

    private static function sourceTypesSql(): string
    {
        return implode(',', array_map(static fn (string $t): string => "'{$t}'", self::TRANSACTION_SOURCE_TYPES));
    }
}
