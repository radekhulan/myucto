<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\Expense\ExpenseAutoClassifier;
use MyInvoice\Service\Ai\AiPostingOverrideResolver;
use MyInvoice\Service\Ai\EmbeddingWriter;
use MyInvoice\Service\License\CommercialFeatureAccess;

/**
 * Zaúčtování dokladu (vydaná/přijatá faktura) z jednoho místa — sdílená logika pro
 * hromadné zaúčtování ze seznamů (A2) a per-firma auto-post hook (A2). Obojí staví
 * na jediném zdroji pravdy {@see PostingService} (podvojnost, období, idempotence).
 *
 * Dvě vstupní brány se liší jen kontraktem chyb:
 *   - {@see post()}          — TVRDÁ cesta: chyba probublá jako PostingException /
 *                              UnbalancedEntryException. Volá ji bulk endpoint, který
 *                              chybu zachytí PER DOKLAD a poskládá souhrnný report.
 *   - {@see maybeAutoPost()} — MĚKKÁ cesta pro hook po vystavení/přijetí: nejdřív
 *                              ověří firemní flag + režim účetnictví, a případnou
 *                              chybu zaúčtování SPOLKNE do audit warningu — vystavení
 *                              faktury nesmí spadnout kvůli deníku (doklad zůstane
 *                              vystavený nezaúčtovaný, uživatel ho dožene ručně).
 *
 * Po úspěšném postu doklad dostane booked_at/booked_by (zámek F6, §4.7) — stejná
 * sémantika jako jednotlivé endpointy JournalAction::postInvoice/postPurchase.
 */
final class DocumentAutoPoster
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly Connection $db,
        private readonly ActivityLogger $activity,
        private readonly AutoPostingPolicyService $policy,
        private readonly AiPostingOverrideResolver $aiOverrides,
        private readonly EmbeddingWriter $embeddingWriter,
        private readonly ExpenseAutoClassifier $autoClassifier,
        private readonly CommercialFeatureAccess $commercialFeatures,
        // Zaúčtovaný doklad může být úhradou platby kartou, která čekala na předpis 321 —
        // po zaúčtování se dorovná vypořádání 321/378.x (viz BankPostingService).
        private readonly \MyInvoice\Service\Accounting\Bank\BankPostingService $bankPosting,
    ) {}

    /**
     * Zaúčtuje jeden doklad a označí ho jako zaúčtovaný (booked_at/booked_by).
     * Idempotentní (PostingService dedupuje přes source_type+source_id).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string, entry_date?:?string} $meta
     * @return int id účetního zápisu
     *
     * @throws PostingException|UnbalancedEntryException při nemožnosti zaúčtovat
     */
    public function post(int $supplierId, string $sourceType, int $docId, array $meta = [], ?int $bookedBy = null): int
    {
        $table = $sourceType === 'invoice' ? 'invoices' : 'purchase_invoices';

        $docDate = $this->fetchDocDate($table, $supplierId, $docId);
        if ($docDate === null) {
            throw new PostingException('entry_not_found', 'Doklad #' . $docId . ' nenalezen.', 404);
        }

        $lines = $sourceType === 'invoice'
            ? $this->posting->buildFromInvoice($supplierId, $docId)
            : $this->posting->buildFromPurchaseInvoice($supplierId, $docId, array_filter([
                'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $docId),
            ], static fn (mixed $value): bool => $value !== null));

        $meta['entry_date'] = $meta['entry_date'] ?? $docDate;
        $entryId = $this->posting->postDocument($supplierId, $sourceType, $docId, $lines, $meta);

        // Zámek dokladu (F6, §4.7): po úspěšném postu doklad zamkni. Existující
        // booked_at/booked_by se nepřepisuje (COALESCE) — ruční book dřív má přednost.
        $this->db->pdo()->prepare(
            "UPDATE {$table}
                SET booked_at = COALESCE(booked_at, NOW()),
                    booked_by = COALESCE(booked_by, ?)
              WHERE id = ? AND supplier_id = ?"
        )->execute([$bookedBy, $docId, $supplierId]);
        if ($sourceType === 'purchase_invoice') {
            $this->embeddingWriter->enqueueFromDecision($supplierId, 'purchase_invoice', $docId);
            $this->bankPosting->syncCardSettlementsForPurchase($supplierId, $docId, $meta['user_id'] ?? null);
        }

        return $entryId;
    }

    /**
     * Auto-post hook: pokud má firma zapnutý příslušný flag A je v režimu podvojného
     * účetnictví, zaúčtuje doklad hned po vystavení/přijetí. Chyba zaúčtování se jen
     * zaloguje (accounting.auto_post_failed) a spolkne — NESMÍ zablokovat volající tok.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     */
    public function maybeAutoPost(
        int $supplierId,
        string $sourceType,
        int $docId,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        if (!$this->commercialFeatures->isAvailable() || !$this->isEnabled($supplierId, $sourceType)) {
            return;
        }

        // Před zaúčtováním doplnit JISTOU klasifikaci nákladu na položky, které ji nemají.
        // Bez tohoto kroku čte purchaseExpenseWeights() prázdné sloupce a celý řádek spadne
        // na default 518 — i když pravidlo tenanta sedí se 100% jistotou (PHM → 501.100).
        // Selhání klasifikace nesmí zablokovat zaúčtování, proto vlastní try/catch.
        if ($sourceType === 'purchase_invoice') {
            try {
                $this->autoClassifier->applyToInvoice($supplierId, $docId, [], $userId);
            } catch (\Throwable $e) {
                $this->activity->log(
                    'accounting.expense_auto_classify_failed',
                    $userId,
                    $sourceType,
                    $docId,
                    ['message' => $e->getMessage()],
                    $ip,
                    $userAgent,
                    $supplierId,
                );
            }
        }

        try {
            $entryId = $this->post($supplierId, $sourceType, $docId, [
                'user_id'    => $userId,
                'posted_by'  => $userId,
                'ip'         => $ip,
                'user_agent' => $userAgent,
            ], $userId);
            $this->activity->log(
                'accounting.auto_posted',
                $userId,
                $sourceType,
                $docId,
                ['journal_entry_id' => $entryId],
                $ip,
                $userAgent,
                $supplierId,
            );
        } catch (PostingException | UnbalancedEntryException $e) {
            // Doklad zůstává vystavený/přijatý, jen nezaúčtovaný — audit warning,
            // FE (dashboard fronta A4 / seznam) ho ukáže k ručnímu dořešení.
            $this->activity->log(
                'accounting.auto_post_failed',
                $userId,
                $sourceType,
                $docId,
                [
                    'error_code' => $e instanceof PostingException ? $e->errorCode : 'unbalanced_entry',
                    'message'    => $e->getMessage(),
                ],
                $ip,
                $userAgent,
                $supplierId,
            );
        } catch (\Throwable $e) {
            // Infrastrukturní selhání (DB deadlock, ztráta spojení apod.) — invariant
            // „auto-post NIKDY nezablokuje vystavení/přijetí" platí i tady (adversariální
            // review Fáze A). post() si drží vlastní transakci, takže zůstává čistě
            // rollbacknutá; jen to zalogujeme se stejným audit typem.
            $this->activity->log(
                'accounting.auto_post_failed',
                $userId,
                $sourceType,
                $docId,
                ['error_code' => 'internal', 'message' => $e->getMessage()],
                $ip,
                $userAgent,
                $supplierId,
            );
        }
    }

    /**
     * Má firma zapnutý auto-post pro daný typ dokladu A běží v podvojném účetnictví?
     * Daňová evidence doklady do deníku neúčtuje → flag je no-op.
     */
    private function isEnabled(int $supplierId, string $sourceType): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $mode = $stmt->fetchColumn();
        if ($mode === false) {
            return false;
        }
        $operation = $sourceType === 'invoice' ? OperationType::DOCUMENT_INVOICE : OperationType::DOCUMENT_PURCHASE;
        return $mode === 'double_entry' && $this->policy->levelFor($supplierId, $operation) === 'auto';
    }

    /** Datum účetního případu z dokladu (DUZP / vystavení). NULL = doklad neexistuje. */
    private function fetchDocDate(string $table, int $supplierId, int $id): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(tax_date, issue_date) AS d FROM {$table} WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $d = $stmt->fetchColumn();
        return $d === false || $d === null ? null : (string) $d;
    }
}
