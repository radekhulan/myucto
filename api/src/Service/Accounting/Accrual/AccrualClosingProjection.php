<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

use MyInvoice\Http\IsoDate;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\AccrualDeferralService;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\PostingException;

/**
 * Položky spravované uzávěrkou (`managed_by = closing`): řádky dokladů s obdobím od-do, které
 * se účtují celé do nákladu či výnosu a na 381/384 je odkládá až krok uzávěrky. Během roku
 * proto na 381/384 nejsou vidět; přehled ukazuje, co uzávěrka účetního období zaúčtuje.
 * Nic se neukládá ani neúčtuje.
 *
 * Výběr řádků, částky i výsledkové účty počítá {@see AccrualDeferralService} stejně jako krok
 * uzávěrky a průběžné časové rozlišení ve výkazech ({@see \MyInvoice\Service\Accounting\Reports\InterimAccrualAdjustment}):
 * doklady s nákladem či výnosem v účetním období dne `as_of`, jejichž období přesahuje jeho
 * konec, bez dokladů, které odklad nesou samy, a bez řádků spravovaných agendou. Období celé
 * uvnitř účetního období uzávěrka nerozlišuje, proto v přehledu nejsou.
 *
 * U každého řádku je část, která zůstane v nákladech či výnosech účetního období, část
 * odložená na 381/384 a její rozpuštění po dalších účetních obdobích (poměrem dnů,
 * {@see AccrualScheduleCalculator}). Náklady (381) a výnosy (384) jsou oddělené.
 */
final class AccrualClosingProjection
{
    private const LIMIT = 500;

    public function __construct(
        private readonly AccrualDeferralService $accruals,
        private readonly AccountingPeriodRepository $periods,
    ) {}

    /** @return array<string,mixed> */
    public function list(int $supplierId, ?string $asOf = null): array
    {
        $asOf = $asOf === null || $asOf === '' ? date('Y-m-d') : substr($asOf, 0, 10);
        if (!IsoDate::isValid($asOf)) {
            throw new AccrualException('invalid_date', 'Neplatné datum.', 422, 'as_of');
        }
        $calendar = $this->periods->fiscalCalendar($supplierId);
        $year = $calendar->fiscalYearOfDate($asOf);
        $yearStart = $calendar->periodStart($year);
        $yearEnd = $calendar->periodEnd($year);

        try {
            $prepaid = [];
            $costs = [];
            $accountsByInvoice = [];
            foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->prepaidExpenseRows($supplierId, $yearEnd, $yearStart, $yearEnd)) as $row) {
                $amount = $this->accruals->prepaidExpenseCostCzk($supplierId, $row, $costs);
                $accounts = $this->accruals->prepaidExpenseAccounts($supplierId, $row, $accountsByInvoice);
                $prepaid[] = $this->item($row, $amount, array_keys($accounts), $calendar, $year, $yearEnd, true);
            }
            $revenue = [];
            $contexts = [];
            foreach ($this->accruals->withoutAgendaItems($supplierId, $this->accruals->deferredRevenueRows($supplierId, $yearEnd, $yearStart, $yearEnd)) as $row) {
                [$amount, $accounts] = $this->accruals->deferredRevenueItem($supplierId, $row, $contexts);
                $revenue[] = $this->item($row, $amount, array_keys($accounts), $calendar, $year, $yearEnd, false);
            }
            $kinds = [
                'prepaid_expense' => $this->kind($prepaid, $this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_PREPAID_EXPENSE)),
                'deferred_revenue' => $this->kind($revenue, $this->accruals->deferralAccount($supplierId, AccrualDeferralService::RULE_DEFERRED_REVENUE)),
            ];
        } catch (ClosingException | PostingException $e) {
            throw new AccrualException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $years = [];
        foreach ($kinds as $kind) {
            $years = [...$years, ...array_keys($kind['totals']['years'])];
        }
        $years = array_values(array_unique($years));
        sort($years);

        return [
            'as_of' => $asOf,
            'fiscal_year' => $year,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
            'future_years' => $years,
            'kinds' => $kinds,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<int|string> $accounts
     * @return array<string,mixed>|null
     */
    private function item(array $row, float $amount, array $accounts, FiscalCalendar $calendar, int $year, string $yearEnd, bool $expense): ?array
    {
        $from = substr((string) $row['accrual_from'], 0, 10);
        $to = substr((string) $row['accrual_to'], 0, 10);
        $amount = round($amount, 2);
        $deferred = AccrualScheduleCalculator::deferredAmount($amount, $from, $to, $yearEnd);
        if ((int) round($deferred * 100) === 0) {
            return null;
        }
        $years = [];
        $rest = $deferred;
        for ($fy = $year + 1; $calendar->periodStart($fy) <= $to; $fy++) {
            $part = $calendar->periodEnd($fy) >= $to
                ? $rest
                : AccrualScheduleCalculator::recognizedBetween($amount, $from, $to, $calendar->periodEnd($fy - 1), $calendar->periodEnd($fy));
            $part = round($part, 2);
            if ((int) round($part * 100) !== 0) {
                $years[$fy] = $part;
            }
            $rest = round($rest - $part, 2);
        }
        [$sourceType, $sourceId, $documentNo] = match ($row['source'] ?? null) {
            'other_item' => ['other_item', (int) $row['other_item_id'], $expense ? $row['vendor_invoice_number'] : $row['varsymbol']],
            'nonprofit_membership' => ['nonprofit_membership', (int) $row['membership_case_id'], $row['varsymbol']],
            'purchase_invoice' => ['purchase_invoice', (int) $row['purchase_invoice_id'], $row['vendor_invoice_number']],
            default => ['invoice', (int) $row['invoice_id'], $row['varsymbol']],
        };
        $accounts = array_values(array_map('strval', $accounts));
        sort($accounts);

        return [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_item_id' => isset($row['item_id']) ? (int) $row['item_id'] : null,
            'document_no' => (string) ($documentNo ?? ''),
            'title' => (string) ($row['description'] ?? ''),
            'period_from' => $from,
            'period_to' => $to,
            'accounts' => $accounts,
            'amount' => $amount,
            'current_year' => round($amount - $deferred, 2),
            'deferred' => $deferred,
            'years' => $years,
        ];
    }

    /**
     * @param list<array<string,mixed>|null> $items
     * @return array<string,mixed>
     */
    private function kind(array $items, string $account): array
    {
        $items = array_values(array_filter($items));
        usort($items, static fn (array $a, array $b): int => [$a['period_to'], $a['document_no']] <=> [$b['period_to'], $b['document_no']]);
        $totals = ['amount' => 0.0, 'current_year' => 0.0, 'deferred' => 0.0, 'years' => []];
        foreach ($items as $item) {
            foreach (['amount', 'current_year', 'deferred'] as $key) {
                $totals[$key] = round($totals[$key] + $item[$key], 2);
            }
            foreach ($item['years'] as $fy => $part) {
                $totals['years'][$fy] = round(($totals['years'][$fy] ?? 0.0) + $part, 2);
            }
        }
        ksort($totals['years']);

        return [
            'account' => $account,
            'count' => count($items),
            'items' => array_slice($items, 0, self::LIMIT),
            'totals' => $totals,
            'truncated' => count($items) > self::LIMIT,
        ];
    }
}
