<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;

/**
 * Položky spravované uzávěrkou (`managed_by = closing`): řádky dokladů s obdobím od-do, které
 * se účtují celé do nákladu či výnosu a na 381/384 je odkládá krok uzávěrky. Agenda je jen
 * zobrazuje, nic se neukládá ani neúčtuje.
 *
 * Výběr řádků, částka v Kč a poměr dnů jsou tytéž jako v kroku uzávěrky
 * ({@see AccrualDeferralService}, {@see AccrualScheduleCalculator}); řádky dokladů spravovaných
 * agendou se vynechají stejně jako v uzávěrce.
 */
final class AccrualClosingProjection
{
    private const LIMIT = 500;

    public function __construct(private readonly AccrualDeferralService $accruals) {}

    /** @return array{as_of:string, items: list<array<string,mixed>>, totals: array<string,float>, truncated: bool} */
    public function list(int $supplierId, ?string $asOf = null): array
    {
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : substr($asOf, 0, 10);
        if (!IsoDate::isValid($asOf)) {
            throw new AccrualException('invalid_date', 'Neplatné datum.', 422, 'as_of');
        }
        $items = [];
        $totals = ['prepaid_expense' => 0.0, 'deferred_revenue' => 0.0];
        $costs = [];
        foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->prepaidExpenseRows($supplierId, $asOf, '1000-01-01', $asOf)) as $row) {
            $net = $this->accruals->prepaidExpenseCostCzk($supplierId, $row, $costs);
            $items[] = self::item('prepaid_expense', $row, $net, $asOf, $row['source'] === 'other_item' ? 'other_item' : 'purchase_invoice',
                (int) ($row['other_item_id'] ?? $row['purchase_invoice_id']), (string) ($row['vendor_invoice_number'] ?? ''));
        }
        $contexts = [];
        foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->deferredRevenueRows($supplierId, $asOf, null, $asOf)) as $row) {
            [$net] = $this->accruals->deferredRevenueItem($supplierId, $row, $contexts);
            $docType = $row['source'] === 'other_item' ? 'other_item' : 'invoice';
            $docId = $docType === 'other_item' ? (int) $row['other_item_id'] : (int) ($row['invoice_id'] ?? 0);
            $items[] = self::item('deferred_revenue', $row, (float) $net, $asOf, $docType, $docId, (string) ($row['varsymbol'] ?? ''));
        }
        $items = array_values(array_filter($items, static fn (array $i): bool => (int) round($i['balance'] * 100) !== 0));
        foreach ($items as $i) {
            $totals[$i['kind']] = round($totals[$i['kind']] + $i['balance'], 2);
        }
        usort($items, static fn (array $a, array $b): int => [$a['period_to'], $a['document_no']] <=> [$b['period_to'], $b['document_no']]);
        return ['as_of' => $asOf, 'items' => array_slice($items, 0, self::LIMIT), 'totals' => $totals, 'truncated' => count($items) > self::LIMIT];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function item(string $kind, array $row, float $net, string $asOf, string $docType, int $docId, string $docNo): array
    {
        $from = substr((string) $row['accrual_from'], 0, 10);
        $to = substr((string) $row['accrual_to'], 0, 10);
        return [
            'managed_by' => 'closing',
            'kind' => $kind,
            'source_type' => $docType,
            'source_id' => $docId,
            'source_item_id' => isset($row['item_id']) ? (int) $row['item_id'] : null,
            'document_no' => $docNo,
            'title' => (string) ($row['description'] ?? ''),
            'period_from' => $from,
            'period_to' => $to,
            'basis_amount' => round($net, 2),
            'balance' => AccrualScheduleCalculator::deferredAmount($net, $from, $to, $asOf),
        ];
    }
}
