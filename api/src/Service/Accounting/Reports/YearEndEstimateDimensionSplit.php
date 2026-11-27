<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;

/**
 * Rozpad nezaúčtovaných položek odhadu do konce roku ({@see YearEndTaxEstimateService},
 * `closing_items`) po hodnotách jednoho typu dimenze pro výsledovku po dimenzi. Nic
 * nepočítá znovu, jen přiřazuje částky, které už spočítaly náhledy uzávěrky a modul
 * majetku, hodnotám dimenze tak, jak by je přiřadilo zaúčtování:
 *
 *   • náklady a výnosy příštích období: řádky zápisu odkladu z náhledu uzávěrky
 *     ({@see ClosingService::prepaidExpenseAccrualLines()}, {@see ClosingService::deferredRevenueAccrualLines()}),
 *     tedy dimenze zdrojových řádků dokladů, se kterými krok uzávěrky zápis účtuje;
 *   • odpisy: nezaúčtovaný odpis po kartách ({@see DepreciationPostingService::previewYear()})
 *     s dimenzemi, které zaúčtování odpisu dá řádku nákladu ({@see DimensionStamper::stamp()}
 *     nad kartou, stejná cesta jako `postDocument('depreciation', …)`);
 *   • ostatní kroky (drobný majetek, rozpuštění z minulého období, agenda časového
 *     rozlišení, kurzové rozdíly, zásoby) náhledy po dimenzích nerozkládají, jdou do
 *     řádku „bez hodnoty" (klíč '').
 *
 * Rozpad hodnoty mezi víc hodnot typu se dělí po haléřích stejným pravidlem jako ve výkazech
 * ({@see DimensionStamper::distributeCents()}, zrcadlo {@see \MyInvoice\Service\Accounting\Dimension\DimensionSplitAllocation}).
 * Nesedí-li součet rozpadu položky na částku položky v odhadu (náhled se mezi voláními
 * změnil), jde celá položka do řádku bez hodnoty a zaloguje se.
 *
 * Návrhy k potvrzení (`optional`: opravné položky, dohady) se nerozkládají, odhad VH je
 * nesčítá.
 */
final class YearEndEstimateDimensionSplit
{
    /** Kroky uzávěrky ve sloupci „Odhad časového rozlišení". */
    public const ACCRUAL_KEYS = ['small_asset_accrual', 'prepaid_expense_accrual', 'deferred_revenue_accrual', 'prior_deferral_release', 'accrual_agenda_release'];

    public function __construct(
        private readonly ClosingService $closing,
        private readonly DepreciationPostingService $depreciation,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly DimensionStamper $stamper,
    ) {}

    /**
     * @param array{id:int, fiscal_year:int, starts_on:string, ends_on:string} $period
     * @param list<array<string,mixed>> $closingItems položky `closing_items` odhadu
     * @return array{accruals:array<string,int>, depreciation:array<string,int>, other:array<string,int>} hodnota ('' = bez hodnoty) => haléře dopadu na VH
     */
    public function split(int $supplierId, int $typeId, array $period, array $closingItems): array
    {
        $out = ['accruals' => [], 'depreciation' => [], 'other' => []];
        foreach ($closingItems as $item) {
            if ((bool) ($item['optional'] ?? false)) {
                continue;
            }
            $key = (string) $item['key'];
            $cents = (int) round((float) $item['amount'] * 100) * ((int) $item['sign'] >= 0 ? 1 : -1);
            if ($cents === 0) {
                continue;
            }
            $column = $key === 'depreciation' ? 'depreciation' : (in_array($key, self::ACCRUAL_KEYS, true) ? 'accruals' : 'other');
            try {
                $parts = match ($key) {
                    'prepaid_expense_accrual' => $this->accrualParts($typeId, $this->closing->prepaidExpenseAccrualLines($supplierId, (int) $period['id'])['lines'], 1),
                    'deferred_revenue_accrual' => $this->accrualParts($typeId, $this->closing->deferredRevenueAccrualLines($supplierId, (int) $period['id'])['lines'], -1),
                    'depreciation' => $this->depreciationParts($supplierId, $typeId, $period),
                    default => ['' => $cents],
                };
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e)) {
                    throw $e;
                }
                // Odhad položku má (náhled v odhadu prošel), jen rozpad selhal: částka zůstane v součtu.
                error_log(sprintf('Odhad po dimenzích (firma %d): rozpad %s selhal: %s: %s', $supplierId, $key, $e::class, $e->getMessage()));
                $parts = ['' => $cents];
            }
            if (array_sum($parts) !== $cents) {
                error_log(sprintf('Odhad po dimenzích (firma %d): rozpad %s %.2f nesouhlasí s odhadem %.2f, celý do řádku bez hodnoty.',
                    $supplierId, $key, array_sum($parts) / 100, $cents / 100));
                $parts = ['' => $cents];
            }
            foreach ($parts as $value => $part) {
                $out[$column][(string) $value] = ($out[$column][(string) $value] ?? 0) + $part;
            }
        }
        return $out;
    }

    /**
     * Řádky zápisu odkladu: `amount` je částka přesunutá z nákladu (+VH) resp. výnosu (−VH).
     *
     * @param list<array<string,mixed>> $lines
     * @return array<string,int>
     */
    private function accrualParts(int $typeId, array $lines, int $vhSign): array
    {
        $out = [];
        foreach ($lines as $line) {
            $cents = (int) round((float) $line['amount'] * 100) * $vhSign;
            foreach (self::byValue($line, $typeId, $cents) as $value => $part) {
                $out[$value] = ($out[$value] ?? 0) + $part;
            }
        }
        return $out;
    }

    /**
     * Nezaúčtovaný odpis po kartách s dimenzemi, které dostane řádek nákladu při zaúčtování.
     * Razítko se čte po kartách s nenulovým nezaúčtovaným odpisem, stejně jako je po kartách
     * účtuje {@see DepreciationPostingService::bookYear()}.
     *
     * @param array{fiscal_year:int, ends_on:string} $period
     * @return array<string,int>
     */
    private function depreciationParts(int $supplierId, int $typeId, array $period): array
    {
        $preview = $this->depreciation->previewYear($supplierId, (int) $period['fiscal_year']);
        $byAsset = (array) ($preview['pending_accounting_by_asset'] ?? []);
        if ($byAsset === []) {
            return [];
        }
        $endsOn = (string) $period['ends_on'];
        $code = $this->depreciation->accountingExpenseAccount($supplierId);
        $accountId = $this->accounts->codeToIdMap($supplierId, $endsOn)[$code]['id'] ?? null;
        $out = [];
        foreach ($byAsset as $assetId => $amount) {
            $cents = -(int) round((float) $amount * 100);
            $line = ['account_id' => $accountId, 'account_code' => $code, 'side' => 'debit', 'amount' => abs((float) $amount)];
            // Zdroj 'asset' s id karty = tytéž dimenze jako zápis 'depreciation' (řádek odpisu
            // dohledá kartu, {@see \MyInvoice\Service\Accounting\Dimension\DimensionDefaults::assetId()});
            // řádek odpisu roku ještě nemusí existovat.
            $stamped = $accountId === null ? [$line] : $this->stamper->stamp($supplierId, 'asset', (int) $assetId, [$line], null, $endsOn);
            foreach ($stamped as $stampedLine) {
                $lineCents = (int) round((float) $stampedLine['amount'] * 100);
                foreach (self::byValue($stampedLine, $typeId, $cents < 0 ? -$lineCents : $lineCents) as $value => $part) {
                    $out[$value] = ($out[$value] ?? 0) + $part;
                }
            }
        }
        return $out;
    }

    /**
     * Díl řádku pro hodnoty typu: jediná hodnota, rozpad po haléřích, jinak bez hodnoty.
     *
     * @param array<string,mixed> $line
     * @return array<string,int>
     */
    public static function byValue(array $line, int $typeId, int $cents): array
    {
        $value = $line['dimensions'][$typeId] ?? null;
        if ($value !== null && (int) $value > 0) {
            return [(string) (int) $value => $cents];
        }
        $shares = (array) ($line['dimension_splits'][$typeId] ?? []);
        if ($shares === []) {
            return ['' => $cents];
        }
        // Pořadí jako SQL rozpadu: nejvyšší podíl, pak nižší id hodnoty.
        $ids = array_map('intval', array_keys($shares));
        usort($ids, static fn (int $a, int $b): int => ((float) $shares[$b] <=> (float) $shares[$a]) ?: ($a <=> $b));
        $weights = array_map(static fn (int $id): float => (float) $shares[$id], $ids);
        if (array_sum($weights) <= 0) {
            return [(string) $ids[0] => $cents];
        }
        $out = [];
        foreach (DimensionStamper::distributeCents($cents, $weights) as $i => $part) {
            $out[(string) $ids[$i]] = $part;
        }
        return $out;
    }
}
