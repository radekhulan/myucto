<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Expense;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\DocumentRepostService;
use MyInvoice\Service\Accounting\DocumentPostingGroup;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\SmallAsset\SmallAssetService;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Přeúčtování už zaúčtovaných přijatých faktur, u kterých se slevový řádek zaúčtoval
 * samostatně (typicky Dal 518) místo do ceny zlevněného zboží
 * ({@see PurchaseDiscountAllocation}). Ručně spouštěné (api/bin/purchase-discount-reclass.php),
 * NE plošný auto-backfill: jde o kontaci konkrétních dokladů a účetní ji má vidět.
 *
 * Dva stavy dokladu:
 *   (a) původní zápis je živý → přepočte se kontace stejnou cestou jako zaúčtování
 *       a zápis se přepíše NA MÍSTĚ k jeho datu přes {@see DocumentRepostService}
 *       (v zamčeném datu jen jako daňově neutrální přepis; když by to znamenalo storno,
 *       doklad se jen vypíše a nic se nezmění),
 *   (b) doklad už někdo přeúčtoval stornem a nový zápis existuje → zápisy se nepřepisují,
 *       jen se doplní chybějící cizoměnová stopa na saldokontní řádky nového zápisu.
 * V obou stavech se srovná druh výdaje slevového řádku na druh zlevněné položky
 * a sesynchronizuje evidence drobného majetku (cena karty po slevě).
 *
 * Doklad převzatý z jiného účetního programu ({@see TakenOverRecord}) se jen vypíše ve
 * stavu `taken_over`: jeho kontaci určil zdroj a převedený deník s ním musí souhlasit.
 */
final class PurchaseDiscountReclass
{
    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly DocumentRepostService $repost,
        private readonly SmallAssetService $smallAssets,
    ) {}

    /**
     * Přijaté faktury v otevřeném účetním roce se slevovým řádkem, jehož rozpad by změnil
     * zaúčtování. Jen čte.
     *
     * @return list<array<string,mixed>>
     */
    public function candidates(?int $supplierId): array
    {
        $sql = "SELECT DISTINCT pi.supplier_id, pi.id
                  FROM purchase_invoices pi
                  JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
                  JOIN journal_entries je ON je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                                         AND je.source_id = pi.id
                  JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id AND p.status = 'open'
                 WHERE pii.total_without_vat < 0";
        $params = [];
        if ($supplierId !== null) {
            $sql .= ' AND pi.supplier_id = ?';
            $params[] = $supplierId;
        }
        // Výběr zahrnuje i historii přímého zápisu (nativní přeúčtování stornem).
        // Vlastněné členy přidáváme samostatně, aby obě cesty používaly svůj index.
        $sql .= " UNION SELECT DISTINCT pi.supplier_id, pi.id
                   FROM purchase_invoices pi
                   JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
                   JOIN journal_document_postings jdp ON jdp.supplier_id = pi.supplier_id
                        AND jdp.doc_type = 'purchase_invoice' AND jdp.doc_id = pi.id
                   JOIN journal_entries je ON je.supplier_id = jdp.supplier_id AND je.id = jdp.entry_id
                   JOIN accounting_periods p ON p.id = je.period_id AND p.supplier_id = je.supplier_id AND p.status = 'open'
                  WHERE pii.total_without_vat < 0";
        if ($supplierId !== null) {
            $sql .= ' AND pi.supplier_id = ?';
            $params[] = $supplierId;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY supplier_id, id');
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $report = $this->run((int) $row['supplier_id'], (int) $row['id'], false);
            if ($report['discount_lines'] > 0 && (in_array($report['state'], ['taken_over', 'posting_group_requires_repost'], true) || $report['posting_changes']
                || $report['classification_changes'] > 0 || $report['trace_fixes'] > 0)) {
                $out[] = $report;
            }
        }
        return $out;
    }

    /**
     * @return array{supplier_id:int, purchase_invoice_id:int, state:string, discount_lines:int,
     *   posting_changes:bool, strategy:?string, message:?string, entry_id:?int, entry_date:?string,
     *   before:list<string>, after:list<string>, classification_changes:int, trace_fixes:int,
     *   cards:?array<string,mixed>, applied:bool}
     */
    public function run(int $supplierId, int $purchaseInvoiceId, bool $apply, ?int $userId = null): array
    {
        $items = $this->items($purchaseInvoiceId, $supplierId);
        $allocation = PurchaseDiscountAllocation::allocate(
            PurchaseDiscountAllocation::withReturns($this->db->pdo(), $supplierId, $purchaseInvoiceId, $items),
        );
        $report = [
            'supplier_id' => $supplierId, 'purchase_invoice_id' => $purchaseInvoiceId, 'state' => 'none',
            'discount_lines' => count($allocation), 'posting_changes' => false, 'strategy' => null,
            'message' => null, 'entry_id' => null, 'entry_date' => null, 'before' => [], 'after' => [],
            'classification_changes' => 0, 'trace_fixes' => 0, 'cards' => null, 'applied' => false,
        ];
        if ($allocation === []) {
            return $report;
        }

        // Náhled (bez --apply) jen čte: nic nezapisuje ani v transakci, takže ho lze
        // pustit i proti ostrým datům.
        $pdo = $this->db->pdo();
        $ownTx = $apply && !$pdo->inTransaction();
        if ($apply) {
            $ownTx ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT purchase_discount_reclass');
        }
        $commit = false;
        try {
            if ($apply) {
                (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertSourcesMutable($supplierId, [['purchase_invoice', $purchaseInvoiceId]]);
            }
            $active = (new DocumentPostingGroup($this->db))->active($supplierId, 'purchase_invoice', $purchaseInvoiceId, $apply);
            $owned = DocumentPostingGroup::hasOwnedEntries($active);
            $live = $active === [] ? null : $active[array_key_last($active)];
            $reversed = false;
            foreach ($this->entries($supplierId, $purchaseInvoiceId, $apply) as $entry) {
                if ($entry['reversed_by'] === null) {
                    // Původní nativní chování zahrnuje i koncept zápisu.
                    if (!$owned) $live = $entry;
                } else {
                    $reversed = true;
                }
            }
            if ($live === null) {
                $report['state'] = 'not_posted';
                return $report;
            }
            $report['entry_id'] = (int) $live['id'];
            $report['entry_date'] = (string) $live['entry_date'];
            if ((new TakenOverRecord($this->db))->isDocumentOrItsEntry($supplierId, 'purchase_invoice', $purchaseInvoiceId)) {
                $report['state'] = 'taken_over';
                $report['message'] = 'Doklad je převzatý z jiného účetního programu, jeho kontace se nepřepisuje.';
                return $report;
            }

            if ($owned
                && !DocumentPostingGroup::isSingleDirectEntry($active, 'purchase_invoice', $purchaseInvoiceId)) {
                $report['state'] = 'posting_group_requires_repost';
                $report['message'] = 'Přeúčtujte celou skupinu zápisů z detailu dokladu.';
                return $report;
            }
            $built = $this->posting->buildFromPurchaseInvoice($supplierId, $purchaseInvoiceId, ['entry_date' => (string) $live['entry_date']]);
            $report['before'] = $this->describe($this->liveLines($supplierId, (int) $live['id']));
            $report['after'] = $this->describe($built, true, $supplierId);
            $report['posting_changes'] = $this->key($report['before']) !== $this->key($report['after']);

            if ($reversed) {
                // (b) přeúčtováno stornem: nový zápis nepřepisujeme, jen mu vrátíme měnu.
                $report['state'] = 'reposted_by_reversal';
                $report['trace_fixes'] = $this->fillForeignTrace($supplierId, (int) $live['id'], $built, $apply);
            } else {
                $report['state'] = 'live';
                if ($report['posting_changes']) {
                    $plan = $this->repost->previewPlan($supplierId, 'purchase_invoice', $purchaseInvoiceId, $built);
                    $report['strategy'] = (string) $plan['strategy'];
                    if ($plan['strategy'] !== DocumentRepostService::STRATEGY_REPLACE) {
                        $report['message'] = 'Přepis na místě nejde (' . (string) ($plan['tax_neutral_violation'] ?? $plan['reason_code'] ?? '?')
                            . '), doklad je potřeba přeúčtovat ručně.';
                        return $report;
                    }
                    if ($apply) {
                        $this->repost->repost($supplierId, 'purchase_invoice', $purchaseInvoiceId, $built, [
                            'user_id'     => $userId,
                            'description' => $live['description'] ?? null,
                        ]);
                    }
                }
            }
            $report['classification_changes'] = $this->alignDiscountKinds($items, $allocation, $apply);
            if ($apply) {
                $report['cards'] = $this->smallAssets->syncFromPurchaseInvoice($supplierId, $purchaseInvoiceId, $userId);
                $commit = true;
                $report['applied'] = true;
            }
            return $report;
        } catch (PostingException $e) {
            $report['message'] = $e->getMessage();
            return $report;
        } catch (\Throwable $e) {
            // Odvolaná transakce (1020/1213) savepoint nemá; ROLLBACK TO ve finally by
            // původní chybu přemaskoval chybou 1305.
            TransactionFailure::rollbackIfAborted($pdo, $e);
            throw $e;
        } finally {
            if ($apply && $pdo->inTransaction()) {
                if ($ownTx) {
                    $commit ? $pdo->commit() : $pdo->rollBack();
                } else {
                    if (!$commit) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT purchase_discount_reclass');
                    }
                    $pdo->exec('RELEASE SAVEPOINT purchase_discount_reclass');
                }
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function items(int $purchaseInvoiceId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pii.id, pii.description, pii.total_without_vat, pii.vat_rate_snapshot,
                    pii.expense_kind, pii.expense_account_code
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
              WHERE pi.id = ? AND pi.supplier_id = ?
              ORDER BY pii.order_index, pii.id'
        );
        $stmt->execute([$purchaseInvoiceId, $supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Přímá historie slouží jen k rozpoznání nativního přeúčtování stornem a konceptu;
     * aktivní zaúčtování včetně vlastněných členů určuje DocumentPostingGroup.
     * @return list<array<string,mixed>>
     */
    private function entries(int $supplierId, int $purchaseInvoiceId, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, entry_date, description, reversed_by FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
              ORDER BY id" . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Druh výdaje slevového řádku = druh položky, na kterou připadá největší díl slevy.
     * Na zaúčtování to vliv nemá (rozpad jde podle cílů), jen detail dokladu pak ukazuje
     * slevu tam, kam patří.
     *
     * @param list<array<string,mixed>> $items
     * @param array<int,array<int,float>> $allocation
     */
    private function alignDiscountKinds(array $items, array $allocation, bool $apply): int
    {
        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['id']] = $item;
        }
        $changed = 0;
        $update = $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET expense_kind = ? WHERE id = ?');
        foreach ($allocation as $discountId => $shares) {
            arsort($shares);
            $target = $byId[(int) array_key_first($shares)] ?? null;
            $kind = $target['expense_kind'] ?? null;
            if ($kind === null || $kind === ($byId[$discountId]['expense_kind'] ?? null)) {
                continue;
            }
            if ($apply) {
                $update->execute([$kind, $discountId]);
            }
            $changed++;
        }
        return $changed;
    }

    /**
     * Doplní cizoměnovou stopu saldokontním řádkům živého zápisu, které ji nemají, podle
     * řádku téhož účtu, strany a částky z předpisu dokladu.
     *
     * @param list<array<string,mixed>> $built
     */
    private function fillForeignTrace(int $supplierId, int $entryId, array $built, bool $apply): int
    {
        $fixed = 0;
        $guard = new DimensionPeriodGuard($this->db);
        if ($apply) (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->lockClaims($supplierId);
        $entry = $apply ? ($guard->lockEntries($supplierId, [$entryId])[$entryId] ?? null) : null;
        $update = $this->db->pdo()->prepare(
            'UPDATE journal_entry_lines SET currency_code = ?, fx_rate = ?, amount_foreign = ?
              WHERE id = ? AND supplier_id = ? AND currency_code IS NULL'
        );
        foreach ($this->liveLines($supplierId, $entryId, $apply) as $line) {
            if ($line['currency_code'] !== null) {
                continue;
            }
            foreach ($built as $b) {
                if (($b['currency_code'] ?? null) === null
                    || $this->posting->redirectedAccountCode($supplierId, (string) $b['account_code']) !== $line['account_code']
                    || (string) $b['side'] !== (string) $line['side']
                    || JournalLineAmount::cents($b['amount']) !== JournalLineAmount::cents($line['amount'])
                ) {
                    continue;
                }
                if ($apply) {
                    if ($entry === null) {
                        throw new PostingException('entry_not_found', 'Účetní zápis nenalezen.', 404);
                    }
                    try {
                        $guard->assertMutable($entry);
                    } catch (DimensionException $e) {
                        throw new PostingException($e->errorCode, 'Uzavřené účetní knihy nelze měnit. Nejprve je znovu otevřete.', $e->httpStatus);
                    }
                    (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingLinesMutable($supplierId, [(int) $line['id']]);
                    (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertLinesMutable($supplierId, [(int) $line['id']]);
                    $update->execute([$b['currency_code'], $b['fx_rate'] ?? null,
                        isset($b['amount_foreign']) ? JournalLineAmount::decimal($b['amount_foreign']) : null,
                        (int) $line['id'], $supplierId]);
                }
                $fixed++;
                break;
            }
        }
        return $fixed;
    }

    /** @return list<array<string,mixed>> */
    private function liveLines(int $supplierId, int $entryId, bool $currentRead = false): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, a.account_code, l.side, l.amount, l.currency_code, l.fx_rate, l.amount_foreign
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              ORDER BY l.line_no, l.id' . ($currentRead ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$entryId, $supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return list<string>
     */
    private function describe(array $lines, bool $redirect = false, int $supplierId = 0): array
    {
        $sums = [];
        foreach ($lines as $l) {
            $code = $redirect
                ? $this->posting->redirectedAccountCode($supplierId, (string) $l['account_code'])
                : (string) $l['account_code'];
            $signed = (int) round((float) $l['amount'] * 100) * ((string) $l['side'] === 'credit' ? -1 : 1);
            $sums[$code] = ($sums[$code] ?? 0) + $signed;
        }
        ksort($sums, SORT_STRING);
        $out = [];
        foreach ($sums as $code => $cents) {
            if ($cents === 0) {
                continue;
            }
            $out[] = sprintf('%s %s %s', $cents > 0 ? 'MD' : 'D', $code, number_format(abs($cents) / 100, 2, ',', ' '));
        }
        return $out;
    }

    /** @param list<string> $described */
    private function key(array $described): string
    {
        return implode(';', $described);
    }
}
