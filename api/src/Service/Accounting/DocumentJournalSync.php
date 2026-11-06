<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Ai\AiPostingOverrideResolver;

/**
 * DocumentJournalSync — konzistenční brána mezi dokladem a účetním deníkem (audit
 * 2026-07, nálezy H4/H5, Fáze A3). Když se zaúčtovaný doklad (vydaná/přijatá faktura)
 * MAŽE nebo INTERNĚ STORNUJE, jeho aktivní zápis v deníku nesmí osiřet ani zůstat
 * viset jako přebytečný výnos/náklad. Tahle služba proto ve STEJNÉ transakci, kterou
 * drží volající Action (mazání/storno dokladu), stornuje aktivní zápis přes
 * {@see PostingService::reverse} (§35 ZoÚ — neměnnost po zaúčtování: opravuje se
 * protizápisem, ne mutací/smazáním).
 *
 * INVARIANTY
 * ------------------------------------------------------------------------------
 * - Běží VÝHRADNĚ uvnitř transakce volajícího (reverse() detekuje inTransaction a
 *   neotevírá vlastní commit). Buď projde reverze i vlastní operace dokladu, nebo
 *   se celá transakce rollbackne — sirotčí (aktivní zápis bez dokladu) NIKDY nevznikne.
 * - Storno se účtuje do období PŮVODNÍHO zápisu (reverse() defaultuje entry_date na
 *   original.entry_date). Když je toto období uzavřené, reverse() vyhodí
 *   {@see PostingException} 'period_not_open' → volající operaci zastaví (409) a
 *   doklad i zápis zůstanou beze změny (§35 — do uzavřeného období nelze zasáhnout).
 * - "Aktivní zápis" = posted_at NOT NULL AND reversed_by NULL (shodně s
 *   PurchaseInvoiceRepository::hasActivePostedEntry a JournalAction::unlockSourceAfterReverse).
 *   Koncept (posted_at NULL) ani už stornovaný zápis se nestornuje znovu.
 */
final class DocumentJournalSync
{
    public function __construct(
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly Connection $db,
        private readonly AiPostingOverrideResolver $aiOverrides,
    ) {}

    /**
     * Doklad se MAŽE. Aktivní zápis stornuj (§35) a zbylý zápis odpoj od zdroje
     * (detachSource — ať v deníku nezůstane řádek se source_id na neexistující doklad
     * a uvolní se unique slot [supplier, source_type, source_id]).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param array{posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string, description?:?string} $meta
     *
     * @throws PostingException storno nelze zaúčtovat (uzavřené období apod.) → volající MUSÍ mazání zastavit
     */
    public function onDelete(int $supplierId, string $sourceType, int $sourceId, array $meta = []): void
    {
        $this->reverseActive($supplierId, $sourceType, $sourceId, $meta, detachRemaining: true);
    }

    /**
     * Atomicky připraví smazání celé skupiny dokladů (rodič + CASCADE potomci).
     * Volající drží společnou transakci a vlastní DELETE provede až po úspěchu všech
     * reverzí; jediný uzamčený child tak bezpečně zastaví smazání celé skupiny.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param list<int> $sourceIds
     * @param array{posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string, description?:?string} $meta
     */
    public function onDeleteMany(int $supplierId, string $sourceType, array $sourceIds, array $meta = []): void
    {
        foreach (array_values(array_unique(array_map('intval', $sourceIds))) as $sourceId) {
            if ($sourceId > 0) {
                $this->onDelete($supplierId, $sourceType, $sourceId, $meta);
            }
        }
    }

    /**
     * Doklad se INTERNĚ STORNUJE (zůstává v DB, status → cancelled). Aktivní zápis
     * stornuj (§35) ve stejné transakci. source_id se NEodpojuje — doklad existuje dál,
     * vazba stornovaného zápisu na cancelled doklad je součást auditní stopy.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param array{posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string, description?:?string} $meta
     *
     * @throws PostingException storno nelze zaúčtovat (uzavřené období apod.) → volající MUSÍ storno dokladu zastavit
     */
    public function onCancel(int $supplierId, string $sourceType, int $sourceId, array $meta = []): void
    {
        $this->reverseActive($supplierId, $sourceType, $sourceId, $meta, detachRemaining: false);
    }

    /**
     * Doklad se FORCE-EDITOVAL v UZAVŘENÉM období (admin ?force=1, audit 2026-07 B11).
     * Zaúčtovaný zápis nelze v uzavřeném období přepsat (§35), takže by doklad a deník
     * tiše rozešly. Tahle metoda proto aktivní zápis STORNUJE k dnešnímu (otevřenému)
     * datu a opravený doklad ZNOVU zaúčtuje do AKTUÁLNÍHO otevřeného období — čísla
     * uzavřeného roku (a už podané sestavy) zůstávají nedotčená, oprava se promítne do
     * běžného období (standardní oprava minulých období).
     *
     * Vrací null, pokud doklad nemá aktivní posted zápis (není co dorovnávat).
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param array{posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return array{reversed_entry_id:int, reversal_entry_id:int, new_entry_id:int}|null
     *
     * @throws PostingException storno/re-post nelze zaúčtovat (dnešek zamčený / bez období) → volající to vrátí jako chybu
     */
    public function reconcileForceEdit(int $supplierId, string $sourceType, int $sourceId, array $meta = []): ?array
    {
        return TransactionFailure::retrying($this->db->pdo(), fn (): ?array =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $sourceType, $sourceId, $meta): ?array {
                $members = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $sourceId, true);
                if ($members === []) return null;
                $owned = DocumentPostingGroup::hasOwnedEntries($members);
                $entryId = (int) $members[0]['id'];
                $today = date('Y-m-d');
                // Storno starého zápisu do DNEŠNÍHO otevřeného období (ne do uzavřeného
                //    období dokladu). Explicitní entry_date=dnešek obchází default reverse()
                //    (období originálu), které je tu uzavřené.
                $reverseMeta = $meta;
                $reverseMeta['entry_date']  = $today;
                $reverseMeta['description'] = 'Storno zápisu při opravě dokladu v uzavřeném období (force reconcile)';
                if ($owned) {
                    $reversals = $this->posting->reverseDocument($supplierId, $sourceType, $sourceId, $reverseMeta);
                    $reversalId = $reversals[$entryId] ?? null;
                } else {
                    $reversalId = $this->posting->reverse($supplierId, $entryId, $reverseMeta);
                    // Nativní větev zachovává dosavadní odpojení opraveného originálu.
                    // Převzatá skupina si uchová historické vlastnictví pro smír i audit.
                    $this->journal->detachSource($entryId, $supplierId);
                }

                // Znovu zaúčtuj opravený doklad do AKTUÁLNÍHO otevřeného období (dnešek).
                $lines = $sourceType === 'invoice'
                    ? $this->posting->buildFromInvoice($supplierId, $sourceId, ['entry_date' => $today])
                    : $this->posting->buildFromPurchaseInvoice($supplierId, $sourceId, array_filter([
                        'entry_date' => $today,
                        'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $sourceId),
                    ], static fn (mixed $value): bool => $value !== null));
                $postMeta = $meta;
                $postMeta['entry_date']  = $today;
                $postMeta['description'] = 'Oprava dokladu v uzavřeném období — přeúčtování do běžného období';
                $postMeta['posted']      = true;
                if ($owned) {
                    // Nativní zápis se znovu staví z dokladu: stopa starého zápisu by
                    // vrátila odebrané středisko nebo měnu platnou před opravou.
                    $postMeta['inherit_line_trace_from'] = array_column($members, 'id');
                }
                $newEntryId = $this->posting->postDocument($supplierId, $sourceType, $sourceId, $lines, $postMeta);

                return [
                    'reversed_entry_id' => $entryId,
                    'reversal_entry_id' => $reversalId,
                    'new_entry_id'      => $newEntryId,
                ];
            }),
        );
    }

    /**
     * Force-edit v otevřeném období přepíše aktivní zápis in-place přes standardní
     * PostingService. Převzatou skupinu opravuje společným stornem a náhradou;
     * nativnímu zápisu zachová ID. Obě větve použijí nové datum dokladu.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param array{entry_date?:string, document_date?:?string, document_no?:?string, description?:?string, posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     */
    public function repostForceEdit(int $supplierId, string $sourceType, int $sourceId, array $meta = []): ?int
    {
        return TransactionFailure::retrying($this->db->pdo(), fn (): ?int =>
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $sourceType, $sourceId, $meta): ?int {
                $members = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $sourceId, true);
                if ($members === []) return null;
                $existing = $members[0];

                $lines = $sourceType === 'invoice'
                    ? $this->posting->buildFromInvoice($supplierId, $sourceId, ['entry_date' => $meta['entry_date'] ?? (string) $existing['entry_date']])
                    : $this->posting->buildFromPurchaseInvoice($supplierId, $sourceId, array_filter([
                        'entry_date' => $meta['entry_date'] ?? (string) $existing['entry_date'],
                        'debit_account_code' => $this->aiOverrides->debitOverrideForPurchase($supplierId, $sourceId),
                    ], static fn (mixed $value): bool => $value !== null));
                $postMeta = $meta + [
                    'entry_date' => (string) $existing['entry_date'],
                    'document_date' => $existing['document_date'] ?? null,
                    'document_no' => $existing['document_no'] ?? null,
                    'description' => $existing['description'] ?? null,
                    'posted' => true,
                    // Datum zamčené podaným DPH: přepis projde jen jako daňově neutrální přesun,
                    // podmínky ověří PostingService (TaxNeutralReclassification). Jinak date_locked.
                    'tax_neutral_rewrite' => true,
                ];
                if (DocumentPostingGroup::hasOwnedEntries($members)
                    && !DocumentPostingGroup::isSingleDirectEntry($members, $sourceType, $sourceId)) {
                    // Převzatý předpis může být rozdělený i ponechaný jako jediný ruční člen.
                    // Force-edit je výslovná oprava; automatický post ho úmyslně nepřepisuje.
                    // Jediný přímý zápis se přepíše na místě jako nativní (PostingService).
                    $reverseMeta = $meta;
                    unset($reverseMeta['entry_date']);
                    $reverseMeta['description'] = 'Storno převzatého předpisu při opravě dokladu';
                    $this->posting->reverseDocument($supplierId, $sourceType, $sourceId, $reverseMeta);
                    unset($postMeta['tax_neutral_rewrite']);
                    $postMeta['inherit_line_trace_from'] = array_column($members, 'id');
                }
                return $this->posting->postDocument($supplierId, $sourceType, $sourceId, $lines, $postMeta);
            }),
        );
    }

    /**
     * @param array{posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string, description?:?string} $meta
     */
    private function reverseActive(int $supplierId, string $sourceType, int $sourceId, array $meta, bool $detachRemaining): void
    {
        $members = (new DocumentPostingGroup($this->db))->active($supplierId, $sourceType, $sourceId, true);
        if (DocumentPostingGroup::hasOwnedEntries($members)) {
            $reverseMeta = $meta;
            unset($reverseMeta['entry_date']);
            $reverseMeta['description'] ??= 'Storno skupiny při ' . ($detachRemaining ? 'smazání' : 'stornu') . ' dokladu';
            $this->posting->reverseDocument($supplierId, $sourceType, $sourceId, $reverseMeta);
            if ($detachRemaining) {
                $this->detachDocumentSources($supplierId, $sourceType, $sourceId);
            }
            return;
        }
        $existing = $this->journal->findBySourceForUpdate($supplierId, $sourceType, $sourceId);
        if ($existing === null) {
            return; // doklad nebyl zaúčtován — v deníku není co řešit
        }
        $entryId    = (int) $existing['id'];
        $isPosted   = ($existing['posted_at'] ?? null) !== null;
        $isReversed = ($existing['reversed_by'] ?? null) !== null;

        if ($isPosted && !$isReversed) {
            // Aktivní zaúčtovaný zápis → protizápis ve stejné transakci. entry_date
            // ZÁMĚRNĚ nepředáváme — reverse() ho zařadí do období původního zápisu,
            // takže uzavřené období storno (a tím i mazání/storno dokladu) zablokuje.
            $reverseMeta = $meta;
            unset($reverseMeta['entry_date']);
            $reverseMeta['description'] ??= 'Storno zápisu při ' . ($detachRemaining ? 'smazání' : 'stornu') . ' dokladu';
            $this->posting->reverse($supplierId, $entryId, $reverseMeta);
        }

        if ($detachRemaining) {
            // Doklad mizí — odpoj případný zbylý zápis (právě stornovaný originál nebo
            // nezaúčtovaný koncept), ať nezůstane dangling source_id na smazaný doklad.
            // Doklad s historií převzaté skupiny odpojí i její stornované členy.
            if ($this->hasPostingGroupHistory($supplierId, $sourceType, $sourceId)) {
                $this->detachDocumentSources($supplierId, $sourceType, $sourceId);
            } else {
                $this->journal->detachSource($entryId, $supplierId);
            }
        }
    }

    /** Doklad měl (i stornovanou) převzatou skupinu zaúčtování. */
    private function hasPostingGroupHistory(int $supplierId, string $sourceType, int $sourceId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM journal_document_postings
            WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $sourceType, $sourceId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Po smazání dokladu nesmí source_id viset ani na starších stornovaných předpisech. */
    private function detachDocumentSources(int $supplierId, string $sourceType, int $sourceId): void
    {
        $this->db->pdo()->prepare('UPDATE journal_entries SET source_id = NULL
            WHERE supplier_id = ? AND source_type = ? AND source_id = ?')
            ->execute([$supplierId, $sourceType, $sourceId]);
    }
}
