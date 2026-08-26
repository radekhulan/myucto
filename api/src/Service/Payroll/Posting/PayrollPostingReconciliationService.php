<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Posting;

use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\Payroll\PayrollPostingReconciliationRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Service\Payroll\ControlTotals\PayrollControlTotalsService;

/**
 * MZ-18-W07 — odvozený read model, nic nemění. Pro dané období porovná tři
 * nezávislé zdroje pravdy:
 *  - schválenou mzdovou revizi (kontrolní součty {@see PayrollControlTotalsService},
 *    MZ-13-W06 — znovupoužité, ne přepočítané);
 *  - skutečně zaúčtovaný deník (`journal_entries`/`journal_entry_lines`,
 *    source_type='payroll'), napříč VŠEMI revizemi běhu (oprava účtuje jen
 *    rozdíl, takže teprve součet odpovídá aktuální mzdě);
 *  - platební závazky a jejich vypořádání z MZ-17.
 *
 * Kategorie kopírují kontační matici `private/Mzdy/04-UCETNI-MUSTEK.md`:
 * hrubé mzdy (521/522/523), zákonné náklady zaměstnavatele (524), sociální +
 * zdravotní pojištění (336), daň (342), ostatní srážky a exekuce (obě 379,
 * rozlišené analytickou dimenzí MZ-SR-/MZ-EX-) a čistá mzda (331/366).
 *
 * Období, kde se ještě neúčtovalo (daňová evidence nebo neuzavřený/neschválený
 * měsíc), NENÍ rozdíl — vrací se stav bez kategorií a s vysvětlujícím stavem.
 */
final class PayrollPostingReconciliationService
{
    /**
     * @var array<string,array{prefixes:list<string>,dimension:?string,nature:'expense'|'liability'}>
     */
    private const CATEGORIES = [
        'gross_wages' => [
            'prefixes' => PayrollPostingAccountPolicy::GROSS_WAGE_PREFIXES,
            'dimension' => null,
            'nature' => 'expense',
        ],
        'employer_contributions' => [
            'prefixes' => PayrollPostingAccountPolicy::EMPLOYER_CONTRIBUTION_PREFIXES,
            'dimension' => null,
            'nature' => 'expense',
        ],
        'social_health_insurance' => [
            'prefixes' => PayrollPostingAccountPolicy::SOCIAL_HEALTH_INSURANCE_PREFIXES,
            'dimension' => null,
            'nature' => 'liability',
        ],
        'income_tax' => [
            'prefixes' => PayrollPostingAccountPolicy::INCOME_TAX_PREFIXES,
            'dimension' => null,
            'nature' => 'liability',
        ],
        'other_deductions' => [
            'prefixes' => PayrollPostingAccountPolicy::OTHER_DEDUCTION_PREFIXES,
            'dimension' => 'deduction',
            'nature' => 'liability',
        ],
        'enforcement' => [
            'prefixes' => PayrollPostingAccountPolicy::OTHER_DEDUCTION_PREFIXES,
            'dimension' => 'enforcement',
            'nature' => 'liability',
        ],
        'net_wage' => [
            'prefixes' => PayrollPostingAccountPolicy::NET_WAGE_PREFIXES,
            'dimension' => null,
            'nature' => 'liability',
        ],
    ];

    /** @var array<string,list<string>> */
    private const PAYMENT_LIABILITY_KINDS = [
        'social_health_insurance' => ['social_insurance', 'health_insurance'],
        'income_tax' => ['advance_tax', 'withholding_tax'],
        'other_deductions' => ['deduction'],
        'enforcement' => ['enforcement', 'insolvency'],
        'net_wage' => ['net_wage'],
    ];

    public function __construct(
        private readonly PayrollPostingReconciliationRepository $repository,
        private readonly PayrollControlTotalsService $controlTotals,
        private readonly PayrollStatutoryResultRepository $statutoryResults,
        private readonly AccountingModeRepository $accountingModes,
    ) {}

    /** @return array<string,mixed> */
    public function forPeriod(int $supplierId, string $period): array
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma reconciliace mzdového účtování musí být kladné číslo.',
            );
        }
        if (preg_match(
            '/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D',
            $period,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $periodStart = $period . '-01';
        $year = (int) substr($period, 0, 4);
        $accountingMode = $this->accountingModes->forYear($supplierId, $year);

        $envelope = [
            'schema_version' => 'payroll-posting-reconciliation.v1',
            'supplier_id' => $supplierId,
            'period' => $period,
            'accounting_mode' => $accountingMode,
            'run' => null,
            'revision' => null,
            'journal_state' => 'no_revision',
            'payments_state' => 'not_materialized',
            'overall_status' => 'info',
            'categories' => [],
        ];

        $run = $this->repository->findRun($supplierId, $periodStart);
        if ($run === null || $run['current_revision_no'] <= 0) {
            return $envelope;
        }
        $envelope['run'] = [
            'id' => $run['id'],
            'status' => $run['status'],
        ];

        $revision = $this->repository->findRevisionByNo(
            $supplierId,
            $run['id'],
            $run['current_revision_no'],
        );
        if ($revision === null) {
            return $envelope;
        }
        $envelope['revision'] = [
            'id' => $revision['id'],
            'revision_no' => $run['current_revision_no'],
            'status' => $revision['status'],
        ];
        if (!in_array($revision['status'], ['approved', 'superseded'], true)) {
            // Otevřený/neschválený mzdový měsíc — legitimní „nezaúčtováno", ne rozdíl.
            return $envelope;
        }

        $controlTotals = $this->controlTotals->forApprovedRevision(
            $supplierId,
            $revision['id'],
        );
        $enforcementTotal = $this->enforcementWithheldTotal($revision);
        $employerContributions = $this->employerContributions(
            $supplierId,
            $revision['id'],
        );

        $liabilitiesByKind = [];
        foreach ($controlTotals->liabilities as $liability) {
            $liabilitiesByKind[(string) $liability['liability_kind']] =
                (int) $liability['amount_minor'];
        }
        $payrollByCategory = [
            'gross_wages' => (int) $controlTotals->company['source_amount_minor'],
            'employer_contributions' => $employerContributions,
            'social_health_insurance' =>
                ($liabilitiesByKind['social_insurance'] ?? 0)
                + ($liabilitiesByKind['health_insurance'] ?? 0),
            'income_tax' =>
                ($liabilitiesByKind['advance_tax'] ?? 0)
                + ($liabilitiesByKind['withholding_tax'] ?? 0),
            'other_deductions' => $liabilitiesByKind['standard_deduction'] ?? 0,
            'enforcement' => $enforcementTotal,
            'net_wage' => ($liabilitiesByKind['net_wage'] ?? 0) - $enforcementTotal,
        ];

        $revisionIds = $this->repository->revisionIdsForRun(
            $supplierId,
            $run['id'],
        );
        $journalPosted = $this->repository->currentRevisionHasEffectivePostingBatch(
            $supplierId,
            $revision['id'],
        );
        $journalState = $accountingMode !== 'double_entry'
            ? 'not_applicable'
            : ($journalPosted ? 'posted' : 'unposted');
        $journalByCategory = $journalState === 'posted'
            ? $this->journalByCategory(
                $this->repository->journalTotals($supplierId, $revisionIds),
                $this->repository->grossDebitAccounts($supplierId, $revisionIds),
            )
            : [];

        $liabilityRows = $this->repository->liabilityTotals(
            $supplierId,
            $revisionIds,
        );
        $paymentsMaterialized = $liabilityRows !== [];
        $paymentsByCategory = $this->paymentsByCategory($liabilityRows);

        $categories = [];
        $hasDiff = false;
        $hasMatch = false;
        foreach (self::CATEGORIES as $key => $_definition) {
            $payrollMinor = $payrollByCategory[$key];
            $journalMinor = $journalState === 'posted'
                ? ($journalByCategory[$key] ?? 0)
                : null;
            $liabilityApplicable = isset(self::PAYMENT_LIABILITY_KINDS[$key])
                && $paymentsMaterialized;
            $paymentsLiabilityMinor = $liabilityApplicable
                ? ($paymentsByCategory[$key]['liability'] ?? 0)
                : null;
            $paymentsPaidMinor = $liabilityApplicable
                ? ($paymentsByCategory[$key]['paid'] ?? 0)
                : null;

            $diffJournal = $journalMinor === null
                ? null
                : $payrollMinor - $journalMinor;
            $diffPayments = $paymentsLiabilityMinor === null
                ? null
                : $payrollMinor - $paymentsLiabilityMinor;

            if (($diffJournal !== null && $diffJournal !== 0)
                || ($diffPayments !== null && $diffPayments !== 0)
            ) {
                $status = 'diff';
                $hasDiff = true;
            } elseif ($diffJournal === null && $diffPayments === null) {
                $status = 'not_applicable';
            } else {
                $status = 'match';
                $hasMatch = true;
            }

            $categories[] = [
                'key' => $key,
                'payroll_minor' => $payrollMinor,
                'journal_minor' => $journalMinor,
                'payments_liability_minor' => $paymentsLiabilityMinor,
                'payments_paid_minor' => $paymentsPaidMinor,
                'diff_payroll_journal_minor' => $diffJournal,
                'diff_payroll_payments_minor' => $diffPayments,
                'status' => $status,
            ];
        }

        $envelope['journal_state'] = $journalState;
        $envelope['payments_state'] = $paymentsMaterialized
            ? 'materialized'
            : 'not_materialized';
        $envelope['overall_status'] = $hasDiff
            ? 'diff'
            : ($hasMatch ? 'reconciled' : 'info');
        $envelope['categories'] = $categories;

        return $envelope;
    }

    private function employerContributions(
        int $supplierId,
        int $revisionId,
    ): int {
        $social = $this->statutoryResults->find(
            $supplierId,
            $revisionId,
            'social_insurance',
        );
        $health = $this->statutoryResults->find(
            $supplierId,
            $revisionId,
            'health_insurance',
        );
        if ($social === null || $health === null) {
            throw new \DomainException(
                'Schválená revize nemá zákonný výsledek pojistného.',
            );
        }

        return $this->nonNegativeMinorField(
            $social['result_snapshot'] ?? null,
            'employer_contribution_minor_units',
        ) + $this->nonNegativeMinorField(
            $health['result_snapshot'] ?? null,
            'employer_contribution_minor_units',
        );
    }

    private function nonNegativeMinorField(mixed $snapshot, string $field): int
    {
        if (!is_array($snapshot)) {
            throw new \UnexpectedValueException(
                "Zákonný výsledek nemá platný výsledný snapshot ({$field}).",
            );
        }
        $value = $snapshot[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new \UnexpectedValueException(
                "Zákonný výsledek nemá platné pole {$field}.",
            );
        }

        return $value;
    }

    /**
     * Exekuční/insolvenční srážky nejsou součástí MZ-13 kontrolních součtů
     * (ty počítají čistou mzdu PŘED exekucí) — částka se čte přímo z už
     * hash-ověřeného výsledného snapshotu schválené revize, ne přepočítává.
     *
     * @param array{result_snapshot_json:?string,result_snapshot_hash:?string} $revision
     */
    private function enforcementWithheldTotal(array $revision): int
    {
        $json = $revision['result_snapshot_json'];
        $hash = $revision['result_snapshot_hash'];
        if ($json === null || $hash === null
            || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1
            || !hash_equals($hash, hash('sha256', $json))
        ) {
            throw new \DomainException(
                'Výsledný snapshot schválené revize nemá platný otisk.',
            );
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['people'] ?? null)) {
            throw new \UnexpectedValueException(
                'Výsledný snapshot revize nemá seznam osob.',
            );
        }

        $total = 0;
        foreach ($decoded['people'] as $person) {
            if (!is_array($person)) {
                throw new \UnexpectedValueException(
                    'Výsledný snapshot revize má neplatnou osobu.',
                );
            }
            $enforcement = $person['enforcement'] ?? null;
            if (!is_array($enforcement)) {
                continue;
            }
            $result = $enforcement['result'] ?? null;
            if (!is_array($result) || ($result['status'] ?? null) !== 'supported') {
                continue;
            }
            $withheld = $result['total_withheld_minor_units'] ?? null;
            if (!is_int($withheld) || $withheld < 0) {
                throw new \UnexpectedValueException(
                    'Exekuční výsledek osoby má neplatnou částku.',
                );
            }
            $total += $withheld;
        }

        return $total;
    }

    /**
     * @param list<array{account_code:string,prefix:string,dimension:string,side:string,amount_minor:int}> $rows
     * @param list<string> $grossDebitAccounts
     * @return array<string,int>
     */
    private function journalByCategory(array $rows, array $grossDebitAccounts): array
    {
        $result = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $grossDebitAccountSet = array_fill_keys($grossDebitAccounts, true);
        foreach ($grossDebitAccounts as $account) {
            PayrollPostingAccountPolicy::assertGrossCostAccountIsUnambiguous($account);
        }
        foreach ($rows as $row) {
            foreach (self::CATEGORIES as $key => $definition) {
                $matchesGrossAllocation = $key === 'gross_wages'
                    && isset($grossDebitAccountSet[$row['account_code']]);
                if (!$matchesGrossAllocation
                    && !in_array($row['prefix'], $definition['prefixes'], true)
                ) {
                    continue;
                }
                if ($definition['dimension'] !== null
                    && $definition['dimension'] !== $row['dimension']
                ) {
                    continue;
                }
                $signed = $row['side'] === 'debit'
                    ? $row['amount_minor']
                    : -$row['amount_minor'];
                $natural = $definition['nature'] === 'expense'
                    ? $signed
                    : -$signed;
                $result[$key] += $natural;
            }
        }

        return $result;
    }

    /**
     * @param list<array{liability_kind:string,liability_minor:int,paid_minor:int}> $rows
     * @return array<string,array{liability:int,paid:int}>
     */
    private function paymentsByCategory(array $rows): array
    {
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['liability_kind']] = $row;
        }
        $result = [];
        foreach (self::PAYMENT_LIABILITY_KINDS as $category => $kinds) {
            $liability = 0;
            $paid = 0;
            foreach ($kinds as $kind) {
                $liability += $byKind[$kind]['liability_minor'] ?? 0;
                $paid += $byKind[$kind]['paid_minor'] ?? 0;
            }
            $result[$category] = ['liability' => $liability, 'paid' => $paid];
        }

        return $result;
    }
}
