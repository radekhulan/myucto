<?php

declare(strict_types=1);

namespace MyInvoice\Service\TaxEvidence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Tax\Return\DpfoReturnCalculator;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\Return\HealthInsuranceCalculator;
use MyInvoice\Service\Tax\Return\Section10Codebook;
use MyInvoice\Service\Tax\Return\SocialInsuranceCalculator;
use MyInvoice\Service\Tax\Return\TaxAdvanceScheduleService;
use MyInvoice\Service\Tax\Return\TaxReturnService;

/**
 * Odhad daně z příjmů a pojistného OSVČ v daňové evidenci během roku.
 *
 * Nic nepočítá vlastními pravidly, jen skládá vrstvu přiznání DPFO:
 *   - podklady § 7 z {@see DpfoReturnDataProvider::gather()} (peněžní deník, potvrzené
 *     daňové odpisy, daňové zůstatkové ceny vyřazeného majetku, úpravy z roční uzávěrky),
 *     jednou se skutečnými výdaji a jednou s výdajovým paušálem;
 *   - daň, slevy a zvýhodnění z {@see DpfoReturnCalculator} nad uloženými vstupy přiznání
 *     (§ 6, § 8–§ 10, § 15, ztráta) a zaplacenými zálohami podle
 *     {@see TaxReturnService::readOnlyInputs()};
 *   - pojistné z {@see SocialInsuranceCalculator} a {@see HealthInsuranceCalculator}
 *     (stejné jako přehledy ČSSZ a zdravotní pojišťovny).
 *
 * Navíc oproti přiznání přičítá ke skutečným výdajům daňové odpisy roku, které ještě
 * nejsou potvrzené ({@see DepreciationPostingService::previewYear()}): v daňové evidenci
 * se odpisy potvrzují až k roční uzávěrce, takže by je odhad během roku jinak vůbec neviděl.
 * Příjmy a výdaje se nepromítají do konce roku, odhad pracuje s dosud zapsanými pohyby.
 */
final class TaxEstimateService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TaxConstantsRepository $constants,
        private readonly DpfoReturnDataProvider $dpfoData,
        private readonly DpfoReturnCalculator $dpfoCalc,
        private readonly SocialInsuranceCalculator $social,
        private readonly HealthInsuranceCalculator $health,
        private readonly TaxReturnService $taxReturns,
        private readonly TaxAdvanceScheduleService $advanceSchedules,
        private readonly DepreciationPostingService $depreciation,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function estimate(int $supplierId, int $year, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $out = [
            'applicable' => false,
            'reason' => null,
            'year' => $year,
        ];
        if ($this->taxpayerType($supplierId) === 'po') {
            return ['reason' => 'taxpayer_po'] + $out;
        }
        try {
            $c = $this->constants->forExactYear($year);
        } catch (\OutOfRangeException) {
            return ['reason' => 'missing_tax_constants'] + $out;
        }

        $read = $this->taxReturns->readOnlyInputs($supplierId, $year, 'fo');
        $inputs = Section10Codebook::mergeLegacyAggregate($read['inputs']);

        $warnings = [];
        try {
            $preview = $this->depreciation->previewYear($supplierId, $year);
            $pendingDepreciation = round((float) $preview['pending_tax'], 2);
            $plannedDepreciation = round((float) $preview['planned_tax'], 2);
        } catch (\Throwable $e) {
            $pendingDepreciation = 0.0;
            $plannedDepreciation = null;
            $warnings[] = 'Plán daňových odpisů roku se nepodařilo načíst: ' . $e->getMessage();
        }

        $base = $this->dpfoData->gather($supplierId, $year, $inputs);
        $profile = (array) $base['profile'];
        $selected = (string) $base['expense_mode'] === 'actual' ? 'actual' : 'pausal';
        $hasActivities = (array) ($profile['activities'] ?? []) !== [];

        $paid = $this->paidAdvances($supplierId, $year, $read, $inputs);
        $remaining = $this->remainingAdvances($supplierId, $year);

        $variants = [];
        if ($hasActivities) {
            // Činnosti na profilu nesou vlastní příjmy, výdaje a způsob jejich uplatnění;
            // srovnání paušálu se skutečnými výdaji za celou daňovou evidenci tu nedává smysl.
            $variants[$selected] = $this->variant($base, $inputs, $c, 0.0, $paid);
            if ($pendingDepreciation != 0.0) {
                $warnings[] = 'Odhad počítá s činnostmi zadanými v profilu; nepotvrzené daňové odpisy roku ('
                    . number_format($pendingDepreciation, 2, ',', ' ') . ' Kč) do nich nejsou rozpočítané.';
            }
        } else {
            foreach (['actual', 'pausal'] as $mode) {
                $data = $mode === $selected ? $base : $this->dpfoData->gather($supplierId, $year, $inputs, $mode);
                $variants[$mode] = $this->variant($data, $inputs, $c, $mode === 'actual' ? $pendingDepreciation : 0.0, $paid);
            }
        }

        $recommended = null;
        if (isset($variants['actual'], $variants['pausal'])) {
            $recommended = $variants['pausal']['total_burden'] < $variants['actual']['total_burden'] ? 'pausal' : 'actual';
        }

        $current = $variants[$selected];
        $parts = is_array($base['cash_expense_parts'] ?? null) ? $base['cash_expense_parts'] : null;
        $rate = (int) ($profile['activity_rate'] ?? 60);
        $pausalIncome = (float) ($variants['pausal']['income'] ?? $current['income']);
        $cap = (float) ($c['expense_caps'][$rate] ?? 0);

        $warnings = array_values(array_unique(array_merge($warnings, (array) $base['warnings'], $current['warnings'])));

        return [
            'applicable' => true,
            'reason' => null,
            'year' => $year,
            'as_of' => min($today, sprintf('%04d-12-31', $year)),
            'year_closed' => is_array($base['closing'] ?? null) && ($base['closing']['status'] ?? '') === 'final',
            'return_status' => $read['status'],
            'selected_mode' => $selected,
            'recommended_mode' => $recommended,
            'has_activities' => $hasActivities,
            'flat_tax_band' => (string) ($profile['flat_tax_band'] ?? 'none'),
            'is_secondary' => !empty($profile['is_secondary']),
            'sources' => [
                'income' => round((float) $base['s7_income'], 2),
                'cash_journal_expenses' => $parts !== null ? (float) $parts['cash_journal'] : null,
                'confirmed_depreciation' => $parts !== null ? (float) $parts['confirmed_depreciation'] : null,
                'disposal_residuals' => $parts !== null ? (float) $parts['disposal_residuals'] : null,
                'pending_depreciation' => $pendingDepreciation,
                'planned_depreciation' => $plannedDepreciation,
                'increase' => round((float) $base['s7_increase'], 2),
                'decrease' => round((float) $base['s7_decrease'], 2),
            ],
            'pausal' => [
                'rate' => $rate,
                'cap' => $cap,
                'cap_reached' => $cap > 0 && $pausalIncome * $rate / 100 > $cap,
            ],
            'variants' => $variants,
            'advances' => [
                'tax' => ['paid' => $paid['tax'], 'source' => $paid['tax_source'], 'remaining' => $remaining['tax']],
                'social' => ['paid' => $paid['social'], 'source' => $paid['social_source'], 'remaining' => $remaining['social']],
                'health' => ['paid' => $paid['health'], 'source' => $paid['health_source'], 'remaining' => $remaining['health']],
            ],
            'settlement' => [
                'tax' => round($current['tax'] - $current['tax_advances'] - $remaining['tax'], 2),
                'social' => round($current['social']['insurance'] - $paid['social'] - $remaining['social'], 2),
                'health' => round($current['health']['insurance'] - $paid['health'] - $remaining['health'], 2),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string,mixed> $data podklady {@see DpfoReturnDataProvider::gather()}
     * @param array<string,mixed> $inputs
     * @param array<string,mixed> $c
     * @param array{tax:float,social:float,health:float} $paid
     * @return array<string,mixed>
     */
    private function variant(array $data, array $inputs, array $c, float $extraExpenses, array $paid): array
    {
        if ($extraExpenses != 0.0) {
            $data['s7_expenses'] = round((float) $data['s7_expenses'] + $extraExpenses, 2);
            $withoutBase = $data;
            unset($withoutBase['s7_base']);
            $data['s7_base'] = DpfoReturnCalculator::section7($withoutBase, $c)['base'];
        }
        $profile = (array) $data['profile'];
        $result = $this->dpfoCalc->compute($data, $inputs, $profile, $c);
        $base7 = (float) $result['s7']['base'];
        $months = (array) ($profile['osvc_months'] ?? []);
        $isSecondary = !empty($profile['is_secondary']);

        $social = $this->social->compute(
            $base7,
            $isSecondary,
            $paid['social'],
            !empty($profile['sickness_insured']),
            isset($profile['sickness_monthly_base']) ? (int) $profile['sickness_monthly_base'] : null,
            $c,
            $months,
        );
        $health = $this->health->compute($base7, $isSecondary, $paid['health'], $c, $months);
        $summary = (array) $result['summary'];
        $tax = round((float) $summary['final_tax'], 2);

        return [
            'mode' => (string) $data['expense_mode'],
            'expense_rate' => (int) $data['expense_rate'],
            'income' => (float) $result['s7']['income'],
            'expenses' => (float) $result['s7']['expenses'],
            's7_base' => $base7,
            'tax_base' => (float) $summary['rounded_base'],
            'tax_before_credits' => (float) $summary['tax16'],
            'credits' => round((float) $summary['tax16'] - (float) $summary['tax_after_credits'], 2),
            'child_benefit' => (float) $summary['child_credit'],
            'tax' => $tax,
            'tax_advances' => round((float) $result['advances'], 2),
            'social' => [
                'assessment_base' => (float) $social['assessment_base'],
                'min_base' => (float) $social['min_base'],
                'insurance' => (float) $social['insurance'],
                'monthly_advance' => (float) $social['monthly_advance'],
                'participates' => (bool) $social['participates'],
            ],
            'health' => [
                'assessment_base' => (float) $health['assessment_base'],
                'min_base' => (float) $health['min_base'],
                'insurance' => (float) $health['insurance'],
                'monthly_advance' => (float) $health['monthly_advance'],
            ],
            'total_burden' => round(max(0.0, $tax) + (float) $social['insurance'] + (float) $health['insurance'], 2),
            'warnings' => (array) $result['warnings'],
        ];
    }

    /**
     * Zaplacené zálohy: ruční údaj přiznání má přednost, jinak jisté spárované zálohy
     * z evidence předpisů (stejná zásada jako {@see TaxReturnService::readOnlyInputs()}).
     *
     * @param array{inputs:array<string,mixed>,status:string,advances_source:string} $read
     * @param array<string,mixed> $inputs
     * @return array{tax:float,social:float,health:float,tax_source:string,social_source:string,health_source:string}
     */
    private function paidAdvances(int $supplierId, int $year, array $read, array $inputs): array
    {
        $exact = (array) ($this->advanceSchedules->paidTotals($supplierId, 'fo', $year)['exact'] ?? []);
        $out = [
            'tax' => round(max(0.0, (float) ($inputs['tax_paid_advances'] ?? 0)), 2),
            'tax_source' => $read['advances_source'],
        ];
        foreach (['social', 'health'] as $kind) {
            $manual = round(max(0.0, (float) ($inputs[$kind . '_paid_advances'] ?? 0)), 2);
            $matched = round((float) ($exact[$kind] ?? 0), 2);
            if ($manual > 0.0) {
                $out[$kind] = $manual;
                $out[$kind . '_source'] = 'return';
            } elseif ($matched > 0.0) {
                $out[$kind] = $matched;
                $out[$kind . '_source'] = 'schedules';
            } else {
                $out[$kind] = 0.0;
                $out[$kind . '_source'] = 'none';
            }
        }

        return $out;
    }

    /**
     * Zálohy roku, které jsou v předpisu a ještě nejsou zaplacené.
     *
     * @return array{tax:float,social:float,health:float}
     */
    private function remainingAdvances(int $supplierId, int $year): array
    {
        $out = ['tax' => 0.0, 'social' => 0.0, 'health' => 0.0];
        foreach ($this->advanceSchedules->listForYear($supplierId, $year, 'fo') as $row) {
            $kind = (string) ($row['advance_kind'] ?? '');
            if (isset($out[$kind]) && ($row['status'] ?? '') === 'planned') {
                $out[$kind] = round($out[$kind] + (float) ($row['amount'] ?? 0), 2);
            }
        }

        return $out;
    }

    private function taxpayerType(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT taxpayer_type FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return strtolower(trim((string) $stmt->fetchColumn()));
    }
}
