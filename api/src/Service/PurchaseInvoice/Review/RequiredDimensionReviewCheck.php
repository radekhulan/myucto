<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice\Review;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleEngine;
use MyInvoice\Service\Accounting\Dimension\DimensionRuleService;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Doklad, kterému chybí dimenze povinná podle pravidla (Firma → Dimenze → Pravidla,
 * vynucení `error`) na účtu některé položky — zaúčtování by ho odmítlo
 * (`dimension_required`). Okno kontroly ho proto nabídne hned po vytěžení, ne až
 * při účtování.
 *
 * Dimenze položky se skládají stejně jako při zaúčtování ({@see \MyInvoice\Service\Accounting\Dimension\DimensionStamper}):
 * položka > produkt > kategorie > hlavička dokladu > zakázka > dodavatel > výchozí
 * hodnota pravidla (vč. vozidla podle karty). Rozpad typu (hlavička i položka)
 * povinnost splňuje.
 *
 * Rozsah je vědomě omezený na **nákladové řádky položek**, tedy účty, které dává SSOT
 * {@see PostingService::purchaseItemExpenseAccounts()} (náklad, u majetku pořízení):
 *   • Řádky závazku (321…) a DPH (343) se nekontrolují. Jejich účet určuje předkontace
 *     uvnitř zaúčtování a druhá kopie toho rozhodnutí by se dřív nebo později rozešla;
 *     nesou navíc jen hlavičku, kterou okno kontroly stejně ukazuje. Pravidlo na ně
 *     dál vynutí samo zaúčtování.
 *   • Ruční přebití účtu v dialogu zaúčtování se nezná — vzniká až při účtování.
 * Nekontroluje se zaúčtovaný, stornovaný doklad, zálohová výzva (neúčtuje se jako
 * předpis) ani daňový doklad k záloze (343/314, žádný náklad).
 *
 * Dávka ({@see evaluateMany()}) načte pravidla, účtovou osnovu, jména typů, položky,
 * dimenze dokladů a výchozí dimenze produktů jednou; po dokladu zbývá jen účet položek
 * (SSOT PostingService) a výchozí dimenze dvojice dodavatel + zakázka (jednou na dvojici).
 */
final class RequiredDimensionReviewCheck implements PurchaseReviewCheck
{
    public const REASON = 'missing_required_dimension';

    /** Druhy dokladu, které nevytvářejí nákladový řádek. */
    private const SKIPPED_KINDS = ['advance', 'tax_document'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
    ) {}

    public function reason(): string
    {
        return self::REASON;
    }

    public function evaluateMany(int $supplierId, array $invoices): array
    {
        $candidates = [];
        foreach ($invoices as $inv) {
            $id = (int) ($inv['id'] ?? 0);
            if ($id > 0 && (string) ($inv['status'] ?? '') !== 'cancelled'
                && !in_array((string) ($inv['document_kind'] ?? 'invoice'), self::SKIPPED_KINDS, true)) {
                $candidates[$id] = $inv;
            }
        }
        if ($candidates === []) {
            return [];
        }
        $ruleService = new DimensionRuleService($this->db);
        $rules = $ruleService->usableRules($supplierId);
        if (!array_filter($rules, static fn (array $r): bool => $r['enforcement'] === 'error')) {
            return [];
        }
        foreach ($this->postedIds($supplierId, array_keys($candidates)) as $postedId) {
            unset($candidates[$postedId]);
        }
        if ($candidates === []) {
            return [];
        }
        $ids = array_keys($candidates);
        $items = $this->items($supplierId, $ids);
        [$own, $splits] = $this->documentDimensions($supplierId, $ids);
        $defaults = new DimensionDefaults($this->db);
        $stockIds = [];
        foreach ($items as $rows) {
            foreach ($rows as $row) {
                $stockIds[] = (int) ($row['stock_item_id'] ?? 0);
            }
        }
        $products = $defaults->forProducts($supplierId, $stockIds);
        $accountIds = $this->accountIds($supplierId);
        $fromCard = (bool) array_filter($rules, static fn (array $r): bool => $r['default_from_card']);
        $partyDefaults = [];
        $typeNames = null;

        $out = [];
        foreach ($candidates as $id => $inv) {
            if (($items[$id] ?? []) === []) {
                continue;
            }
            try {
                $itemAccounts = $this->posting->purchaseItemExpenseAccounts($supplierId, $id);
            } catch (\Throwable) {
                continue;
            }
            $vendorId = (int) ($inv['vendor_id'] ?? 0) ?: null;
            $projectId = (int) ($inv['project_id'] ?? 0) ?: null;
            $partyKey = $vendorId . '|' . $projectId;
            $partyDefaults[$partyKey] ??= $defaults->resolve($supplierId, $vendorId, $projectId)['header'];
            $header = DimensionDefaults::fill($own[$id][0] ?? [], $partyDefaults[$partyKey]);

            $lines = [];
            foreach ($items[$id] as $i => $row) {
                $code = $itemAccounts[(int) $row['id']] ?? null;
                if ($code === null) {
                    continue;
                }
                $lines[] = [
                    'account_id' => $accountIds[$code] ?? 0,
                    'account_code' => $code,
                    'side' => 'debit',
                    'amount' => (float) $row['total_without_vat'],
                    'dimensions' => DimensionDefaults::fill(
                        DimensionDefaults::fill($own[$id][$i + 1] ?? [], $products[(int) ($row['stock_item_id'] ?? 0)]['header'] ?? []),
                        $header,
                    ),
                    'dimension_splits' => ($splits[$id][$i + 1] ?? []) + ($splits[$id][0] ?? []),
                ];
            }
            if ($lines === []) {
                continue;
            }
            $date = (string) ($inv['tax_date'] ?? '') !== '' ? (string) $inv['tax_date']
                : ((string) ($inv['issue_date'] ?? '') !== '' ? (string) $inv['issue_date'] : date('Y-m-d'));
            // Vozidlo podle platební karty umí doplnit jen služba pravidel (čte kartu dokladu);
            // bez takového pravidla stačí čisté jádro s výchozími hodnotami pravidel.
            if ($fromCard) {
                $lines = $ruleService->applyDefaults($supplierId, 'purchase_invoice', $id, $lines, $date);
            }
            $violations = array_filter(
                DimensionRuleEngine::apply($rules, $lines, $date, null, !$fromCard)['violations'],
                static fn (array $v): bool => $v['enforcement'] === 'error',
            );
            if ($violations === []) {
                continue;
            }
            $typeNames ??= $this->typeNames($supplierId);
            $missing = [];
            foreach ($violations as $v) {
                $typeId = (int) $v['type_id'];
                $missing[$typeId] ??= ['type_id' => $typeId, 'type_name' => $typeNames[$typeId] ?? ('#' . $typeId), 'account_codes' => []];
                if (!in_array((string) $v['account_code'], $missing[$typeId]['account_codes'], true)) {
                    $missing[$typeId]['account_codes'][] = (string) $v['account_code'];
                }
            }
            ksort($missing);
            $out[$id] = ['missing_dimensions' => array_values($missing)];
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function postedIds(int $supplierId, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT pi.id FROM purchase_invoices pi
              WHERE pi.supplier_id = ? AND pi.id IN ({$marks})
                AND " . \MyInvoice\Service\Accounting\DocumentPostingGroup::activePostingExistsSql("'purchase_invoice'", 'pi.id', 'pi.supplier_id', false)
        );
        $stmt->execute([$supplierId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Položky v pořadí, v jakém je čísluje document_dimensions (order_index, od 1).
     *
     * @param list<int> $ids
     * @return array<int,list<array<string,mixed>>>
     */
    private function items(int $supplierId, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT pii.purchase_invoice_id, pii.id, pii.stock_item_id, pii.total_without_vat
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id AND pi.supplier_id = ?
              WHERE pii.purchase_invoice_id IN ({$marks})
              ORDER BY pii.purchase_invoice_id, pii.order_index, pii.id"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['purchase_invoice_id']][] = $r;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array{0:array<int,array<int,array<int,int>>>, 1:array<int,array<int,array<int,array<int,float>>>>}
     *         [doklad => pořadí => typ => hodnota, doklad => pořadí => typ => (rozpad)]
     */
    private function documentDimensions(int $supplierId, array $ids): array
    {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $pdo = $this->db->pdo();
        $own = [];
        $stmt = $pdo->prepare(
            "SELECT doc_id, item_no, dimension_type_id, dimension_value_id FROM document_dimensions
              WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $own[(int) $r['doc_id']][(int) $r['item_no']][(int) $r['dimension_type_id']] = (int) $r['dimension_value_id'];
        }
        $splits = [];
        $stmt = $pdo->prepare(
            "SELECT doc_id, item_no, dimension_type_id, dimension_value_id, share FROM document_dimension_splits
              WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id IN ({$marks})"
        );
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $splits[(int) $r['doc_id']][(int) $r['item_no']][(int) $r['dimension_type_id']][(int) $r['dimension_value_id']] = (float) $r['share'];
        }
        return [$own, $splits];
    }

    /** @return array<string,int> kód účtu => id */
    private function accountIds(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, account_code FROM chart_of_accounts WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $out[(string) $a['account_code']] = (int) $a['id'];
        }
        return $out;
    }

    /** @return array<int,string> */
    private function typeNames(int $supplierId): array
    {
        $out = [];
        foreach ((new DimensionRepository($this->db))->listTypes($supplierId) as $t) {
            $out[$t['id']] = (string) $t['name'];
        }
        return $out;
    }
}
