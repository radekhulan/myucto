<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\RefundDocument;
use MyInvoice\Service\PurchaseInvoice\AdvanceCoveredPaidStatus;
use MyInvoice\Service\PurchaseInvoice\PurchasePaidStatusRestore;
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
        private readonly PurchasePaidStatusRestore $paidStatusRestore,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Pohyby výpisu, které před smazáním potřebují uvolnit: spárované, s platbou,
     * s vazbou na přijatou fakturu nebo s živým zápisem v deníku.
     *
     * @return list<int>
     */
    public function releasableTransactionIds(int $supplierId, int $statementId, bool $currentRead = false): array
    {
        $lock = $currentRead ? ' FOR UPDATE' : '';
        $types = self::sourceTypesSql();
        $stmt = $this->db->pdo()->prepare(
            "SELECT bt.id
               FROM bank_transactions bt
              WHERE bt.statement_id = ?
                AND (bt.match_status IN " . self::MATCHED_STATUSES . "
                     OR bt.matched_invoice_id IS NOT NULL
                     OR EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id{$lock})
                     OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id{$lock})
                     OR EXISTS (SELECT 1 FROM journal_entries je
                                 WHERE je.supplier_id = ? AND je.source_type IN ($types)
                                   AND je.source_id = bt.id AND je.reversed_by IS NULL{$lock}))
              ORDER BY bt.id{$lock}"
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
            $tx = $this->bankPosting->lockTransaction($supplierId, $txId);
            if ($tx === null) {
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
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
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

        // Zálohy, které pohyb hradil — po zrušení párování už vazba neexistuje.
        $advances = $this->bankPosting->advancesOfTransaction($supplierId, $txId);

        $journal = $this->releaseJournal($supplierId, $txId, $mode, $userId);
        // Úhrada ostatní položky: storno bankovního zápisu ji stornuje samo
        // (PostingService::reverse), bez zápisu (daňová evidence, položka bez účtu) se storno
        // zapíše tady. Stornované přiřazení automatika znovu nepřiřadí, ručně jde obnovit
        // jen k původní položce.
        $pdo->prepare(
            'UPDATE other_item_allocations SET reversed_on = CURDATE()
              WHERE supplier_id = ? AND bank_transaction_id = ? AND reversed_on IS NULL'
        )->execute([$supplierId, $txId]);

        $purchaseStmt = $pdo->prepare(
            'SELECT purchase_invoice_id FROM payment_matches
              WHERE bank_transaction_id = ? AND supplier_id = ? AND purchase_invoice_id IS NOT NULL'
        );
        $purchaseStmt->execute([$txId, $supplierId]);
        $purchaseInvoiceIds = array_values(array_unique(array_map('intval', $purchaseStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $refundStmt = $pdo->prepare(
            'SELECT invoice_id FROM payment_matches
              WHERE bank_transaction_id = ? AND supplier_id = ? AND invoice_id IS NOT NULL'
        );
        $refundStmt->execute([$txId, $supplierId]);
        $refundInvoiceIds = array_values(array_unique(array_map('intval', $refundStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));

        $pdo->prepare(
            "UPDATE bank_transactions
                SET matched_invoice_id = NULL,
                    match_status       = 'unmatched',
                    ignore_note        = NULL,
                    ignore_origin      = NULL,
                    matched_at         = NULL,
                    matched_by         = NULL
              WHERE id = ?"
        )->execute([$txId]);

        // Smazaný výpis se typicky nahraje znovu a táž platba se spáruje podruhé —
        // automatické poděkování za ni už nemá odejít ({@see \MyInvoice\Service\Mail\PaymentThanksMailer}).
        $paymentsStmt = $pdo->prepare('SELECT invoice_id, source FROM invoice_payments WHERE bank_transaction_id = ?');
        $paymentsStmt->execute([$txId]);
        $paidInvoices = [];
        foreach ($paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $paidInvoices[(int) $p['invoice_id']] = ($paidInvoices[(int) $p['invoice_id']] ?? false) || $p['source'] === 'bank';
        }
        $bankPaidInvoiceIds = array_keys(array_filter($paidInvoices));
        if ($mode === self::MODE_DELETE && $bankPaidInvoiceIds !== []) {
            $placeholders = implode(',', array_fill(0, count($bankPaidInvoiceIds), '?'));
            $pdo->prepare(
                "UPDATE invoices SET payment_thanks_suppressed_at = NOW()
                  WHERE payment_thanks_sent_at IS NULL AND id IN ($placeholders)"
            )->execute($bankPaidInvoiceIds);
        }

        // Platbu založenou párováním smaže, dřívější platbu jen odpojí; stav faktury
        // se přepočte z plateb (může se vrátit ze stavu 'paid').
        $deletedPayment = $this->payments->deleteForBankTransaction($txId);

        $pdo->prepare('DELETE FROM payment_matches WHERE bank_transaction_id = ? AND supplier_id = ?')
            ->execute([$txId, $supplierId]);
        $this->restorePurchaseInvoices($pdo, $supplierId, $purchaseInvoiceIds, $postedAt);
        if ((float) ($tx['amount'] ?? 0) > 0) {
            $this->paidStatusRestore->afterRefundRemoved($supplierId, $purchaseInvoiceIds);
        }
        // Konečná faktura krytá zálohou přestává být uhrazená spolu se zálohou.
        (new AdvanceCoveredPaidStatus($this->db))->afterPaymentsChanged($supplierId, $purchaseInvoiceIds);
        $this->restoreRefundDocuments($pdo, $supplierId, $refundInvoiceIds, $postedAt);
        // Konečná faktura zálohy už nesmí čerpat 314/324 za úhradu, která tu není.
        $this->bankPosting->syncAdvanceSettlements($supplierId, $advances, $userId);

        $legacyReleased = !$deletedPayment && $invoiceId > 0 && $postedAt !== ''
            && $this->releaseLegacyInvoiceMatch($pdo, $txId, $invoiceId, $postedAt, $tx['matched_at'] ?? null);

        // Faktura, které pohyb zrušil nebo odpojil úhradu, to musí mít v aktivitě —
        // jinak není vidět, proč se tatáž platba později „zaplatila" znovu.
        $affected = $paidInvoices;
        if ($invoiceId > 0) {
            $affected[$invoiceId] = $affected[$invoiceId] ?? false;
        }
        foreach ($affected as $affectedId => $bankPayment) {
            $this->activity->log('invoice.bank_payment_released', $userId, 'invoice', $affectedId, [
                'mode'                => $mode,
                'bank_transaction_id' => $txId,
                'statement_id'        => $statementId > 0 ? $statementId : null,
                'posted_at'           => $postedAt !== '' ? $postedAt : null,
                'payment_deleted'     => $bankPayment || ($legacyReleased && $affectedId === $invoiceId),
                'thanks_suppressed'   => $mode === self::MODE_DELETE && $bankPayment,
            ]);
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
            $restoredStatus = (new DocumentPostingGroup($this->db))->hasActive($supplierId, 'purchase_invoice', $purchaseInvoiceId)
                ? 'booked' : 'received';
            $pdo->prepare(
                "UPDATE purchase_invoices pi
                    SET pi.status = ?, pi.paid_at = NULL
                  WHERE pi.id = ? AND pi.supplier_id = ? AND pi.status = 'paid' AND pi.paid_at = ?
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.purchase_invoice_id = pi.id)"
            )->execute([$restoredStatus, $purchaseInvoiceId, $supplierId, $postedAt]);
        }
    }

    /**
     * Doklad k vyplacení (dobropis, záporná faktura), který vyplatila jen tahle odchozí
     * platba, se vrací mezi otevřené. Vratka nemá řádek v invoice_payments, přepočet
     * z plateb by ho nechal `paid`. Ručně nastavený stav (jiné paid_at) nemění, stejně
     * jako fakturu se zálohou (přeplatek zálohy), která je `paid` už od vystavení.
     *
     * @param list<int> $invoiceIds
     */
    private function restoreRefundDocuments(PDO $pdo, int $supplierId, array $invoiceIds, string $postedAt): void
    {
        foreach ($invoiceIds as $invoiceId) {
            $pdo->prepare(
                "UPDATE invoices i
                    SET i.status = IF(i.sent_at IS NOT NULL, 'sent', 'issued'), i.paid_at = NULL
                  WHERE i.id = ? AND i.supplier_id = ? AND i.status = 'paid' AND DATE(i.paid_at) = ?
                    AND " . RefundDocument::refundDocumentSql('i') . "
                    AND (i.invoice_type = 'credit_note' OR i.parent_invoice_id IS NULL)
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.invoice_id = i.id)"
            )->execute([$invoiceId, $supplierId, $postedAt]);
        }
    }

    /**
     * Párování z doby před evidencí plateb (žádná platba navázaná na pohyb): fakturu
     * označenou jako zaplacenou k datu tohoto pohybu, kterou nedrží jiný spárovaný
     * pohyb, vrátí do nezaplacených — ale jen když ji nekryjí jiné platby. O stavu
     * rozhoduje přepočet z plateb, ne ruční UPDATE: faktura zaplacená platbou, která
     * ztratila vazbu na pohyb (smazaný výpis), musí zůstat zaplacená.
     *
     * @return bool true = legacy platba smazána
     */
    private function releaseLegacyInvoiceMatch(PDO $pdo, int $txId, int $invoiceId, string $postedAt, ?string $matchedAt): bool
    {
        $other = $pdo->prepare(
            "SELECT COUNT(*) FROM bank_transactions
              WHERE matched_invoice_id = ?
                AND match_status IN " . self::MATCHED_STATUSES . "
                AND id <> ?"
        );
        $other->execute([$invoiceId, $txId]);
        if ((int) $other->fetchColumn() > 0) {
            return false;
        }

        $inv = $pdo->prepare("SELECT 1 FROM invoices WHERE id = ? AND status = 'paid' AND paid_at = ? FOR UPDATE");
        $inv->execute([$invoiceId, $postedAt]);
        if ($inv->fetchColumn() === false) {
            return false;
        }

        // Backfill 'legacy' platba (migrace 0108) odpovídá právě tomuto historickému
        // spárování — zmizí s ním. Ostatní platby zůstávají a rozhodnou o stavu.
        // Párování mladší než backfill platbu nezaložilo: pohyb jen navázal fakturu
        // zaplacenou už dřív (import, ruční úhrada) a její úhrada musí zůstat.
        if ($matchedAt !== null) {
            $older = $pdo->prepare(
                "SELECT 1 FROM invoice_payments WHERE invoice_id = ? AND source = 'legacy' AND created_at < ? LIMIT 1"
            );
            $older->execute([$invoiceId, $matchedAt]);
            if ($older->fetchColumn() !== false) {
                return false;
            }
        }
        $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'legacy'")
            ->execute([$invoiceId]);
        $this->payments->recompute($invoiceId);
        return true;
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
