<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use PDO;

/**
 * Položky spravované uzávěrkou (`managed_by = closing`): řádky dokladů s obdobím od-do, které
 * se účtují celé do nákladu či výnosu a na 381/384 je odkládá krok uzávěrky. Agenda je jen
 * zobrazuje, nic se neukládá ani neúčtuje.
 *
 * Výběr jde jedním dotazem pro všechny doklady (bez dotazů po dokladech). Vyřazuje tytéž
 * doklady jako krok uzávěrky: doklady, které nesou odklad samy
 * ({@see AccrualDeferralService::deferredOnDocumentFilter()}), a řádky spravované agendou.
 * Zůstatek je základ řádku v Kč (kurz dokladu) rozpuštěný poměrem dnů
 * ({@see AccrualScheduleCalculator}); u přijatých faktur s neuplatněným DPH počítá krok
 * uzávěrky z nákladu včetně DPH, přesná částka je proto v kroku Časové rozlišení.
 */
final class AccrualClosingProjection
{
    private const LIMIT = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly AccrualDeferralService $accruals,
    ) {}

    /** @return array{as_of:string, items: list<array<string,mixed>>, totals: array<string,float>, truncated: bool} */
    public function list(int $supplierId, ?string $asOf = null): array
    {
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : substr($asOf, 0, 10);
        if (!IsoDate::isValid($asOf)) {
            throw new AccrualException('invalid_date', 'Neplatné datum.', 422, 'as_of');
        }
        [$piOwn, $piParams] = $this->accruals->deferredOnDocumentFilter($supplierId, 'purchase_invoice', 'd.supplier_id', 'd.id');
        [$inOwn, $inParams] = $this->accruals->deferredOnDocumentFilter($supplierId, 'invoice', 'd.supplier_id', 'd.id');
        $agenda = static fn (string $type, string $docSql, ?string $lineSql): string => "NOT EXISTS (SELECT 1 FROM accrual_items ai
                    WHERE ai.supplier_id = d.supplier_id AND ai.managed_by = 'agenda' AND ai.status IN ('active', 'completed', 'terminated')
                      AND ai.source_type = '{$type}' AND ai.source_id = {$docSql}"
            . ($lineSql === null ? '' : " AND (ai.source_item_id IS NULL OR ai.source_item_id = {$lineSql})") . ')';
        $sql = "SELECT * FROM (
                  SELECT 'prepaid_expense' AS kind, 'purchase_invoice' AS source_type, d.id AS source_id, it.id AS source_item_id,
                         COALESCE(NULLIF(d.vendor_invoice_number, ''), d.varsymbol) AS document_no, it.description AS title,
                         it.accrual_from, it.accrual_to, ROUND(it.total_without_vat * COALESCE(IF(cur.code = 'CZK', 1, d.exchange_rate), 1), 2) AS net
                    FROM purchase_invoices d
                    JOIN purchase_invoice_items it ON it.purchase_invoice_id = d.id
                    JOIN currencies cur ON cur.id = d.currency_id
                   WHERE d.supplier_id = ? AND d.status NOT IN ('draft', 'cancelled')
                     AND it.accrual_from IS NOT NULL AND it.accrual_to > ? AND d.effective_cost_date <= ?
                     AND NOT {$piOwn} AND " . $agenda('purchase_invoice', 'd.id', 'it.id') . "
                  UNION ALL
                  SELECT 'deferred_revenue', 'invoice', d.id, it.id, d.varsymbol, it.description,
                         it.accrual_from, it.accrual_to, ROUND(it.total_without_vat * COALESCE(IF(cur.code = 'CZK', 1, d.exchange_rate), 1), 2)
                    FROM invoices d
                    JOIN invoice_items it ON it.invoice_id = d.id
                    JOIN currencies cur ON cur.id = d.currency_id
                   WHERE d.supplier_id = ? AND d.status NOT IN ('draft', 'cancelled')
                     AND it.accrual_from IS NOT NULL AND it.accrual_to > ? AND d.effective_tax_date <= ?
                     AND NOT {$inOwn} AND " . $agenda('invoice', 'd.id', 'it.id') . "
                  UNION ALL
                  SELECT CASE WHEN d.side = 'payable' THEN 'prepaid_expense' ELSE 'deferred_revenue' END, 'other_item', d.id, NULL,
                         d.document_no, d.title, d.accrual_from, d.accrual_to, COALESCE(d.amount_czk, d.amount)
                    FROM other_items d
                   WHERE d.supplier_id = ? AND d.deleted_at IS NULL AND d.status = 'posted'
                     AND d.accrual_from IS NOT NULL AND d.accrual_to > ? AND COALESCE(d.accounting_on, d.issued_on) <= ?
                     AND " . $agenda('other_item', 'd.id', null) . "
                ) x ORDER BY x.accrual_to, x.document_no LIMIT " . (self::LIMIT * 4);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $asOf, $asOf, ...$piParams, $supplierId, $asOf, $asOf, ...$inParams, $supplierId, $asOf, $asOf]);
        $items = [];
        $totals = ['prepaid_expense' => 0.0, 'deferred_revenue' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $from = substr((string) $r['accrual_from'], 0, 10);
            $to = substr((string) $r['accrual_to'], 0, 10);
            $balance = AccrualScheduleCalculator::deferredAmount((float) $r['net'], $from, $to, $asOf);
            if ((int) round($balance * 100) === 0) {
                continue;
            }
            $items[] = [
                'managed_by' => 'closing',
                'kind' => (string) $r['kind'],
                'source_type' => (string) $r['source_type'],
                'source_id' => (int) $r['source_id'],
                'source_item_id' => $r['source_item_id'] === null ? null : (int) $r['source_item_id'],
                'document_no' => (string) ($r['document_no'] ?? ''),
                'title' => (string) ($r['title'] ?? ''),
                'period_from' => $from,
                'period_to' => $to,
                'basis_amount' => round((float) $r['net'], 2),
                'balance' => $balance,
            ];
            $totals[(string) $r['kind']] = round($totals[(string) $r['kind']] + $balance, 2);
        }
        return ['as_of' => $asOf, 'items' => array_slice($items, 0, self::LIMIT), 'totals' => $totals, 'truncated' => count($items) > self::LIMIT];
    }
}
