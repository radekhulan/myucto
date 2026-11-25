<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Repository\StatementDefinitionRepository;

final class NetTurnoverReadService
{
    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly StatementDefinitionRepository $definitions,
        private readonly StatementMapResolver $maps,
        private readonly StatementMapper $mapper,
        private readonly AccountingSupplierSettingsRepository $settings,
        private readonly NetTurnoverProjection $projection,
    ) {}

    public function forPeriod(int $supplierId, array $period): float
    {
        $asOf = (string) $period['ends_on'];
        $forms = [];
        $split = [];
        $preserve = [];
        foreach (array_keys(NetTurnoverPolicy::EXTRA_ROWS) as $type) {
            $version = $this->definitions->findVersion($type, $asOf);
            if ($version === null) {
                throw new ReportException('statement_version_missing', 'Pro rozvahový den ' . $asOf . ' neexistuje verze mapování výkazu.');
            }
            $map = $this->maps->accountMap($version, $supplierId, (int) $period['fiscal_year']);
            $forms[$type] = ['rows' => $this->definitions->rows((int) $version['id']), 'map' => $map];
            $split = array_merge($split, $this->mapper->noCompensationPrefixes($map));
            $preserve = array_merge($preserve, $this->mapper->analyticPrefixes($map));
        }
        $balances = $this->ledger->syntheticBalances(
            $supplierId, $asOf, (string) $period['starts_on'],
            array_values(array_unique($split)), array_values(array_unique($preserve)),
        );
        $selected = $this->settings->getNetTurnoverExtraRows($supplierId);
        $projections = [];
        foreach ($forms as $type => $form) {
            $projections[$type] = $this->projection->project(
                $form['rows'], $form['map'], $balances, $type, (string) $period['starts_on'], $selected[$type] ?? [],
            );
        }
        $nature = $projections['income_statement'];
        $purpose = $projections[FinancialStatementService::TYPE_PURPOSE];
        if ($nature['findings'] !== [] || $purpose['findings'] !== [] || $nature['members'] !== $purpose['members']) {
            throw new ReportException('net_turnover_membership_conflict',
                'Čistý obrat pro kategorizaci nelze určit. V nastavení „Čistý obrat: výnosy obchodního modelu“ ověřte shodné zahrnutí výnosů v druhovém i účelovém členění výsledovky a úplnost mapování účtů do výkazů.',
                context: [
                    'accounts' => self::conflictingAccounts($nature, $purpose, $balances),
                    'mapping_period_id' => (int) $period['id'],
                    'mapping_fiscal_year' => (int) $period['fiscal_year'],
                ]);
        }
        return $nature['amount'];
    }

    private static function conflictingAccounts(array $nature, array $purpose, array $balances): array
    {
        $issues = [];
        foreach (['nature' => $nature, 'purpose' => $purpose] as $form => $projection) {
            foreach ($projection['findings'] as $finding) {
                $issues[$finding['account_code']][] = 'turnover_' . $finding['code'] . '_' . $form;
            }
        }
        $natureMembers = $nature['members'];
        $purposeMembers = $purpose['members'];
        foreach (array_keys($natureMembers + $purposeMembers) as $member) {
            $byNature = $natureMembers[$member] ?? null;
            $byPurpose = $purposeMembers[$member] ?? null;
            if ($byNature === $byPurpose) {
                continue;
            }
            [, $code] = explode(':', $member, 3);
            $issues[$code][] = match (true) {
                $byNature === null => 'turnover_only_purpose',
                $byPurpose === null => 'turnover_only_nature',
                default => 'turnover_amount_differs',
            };
        }

        $accounts = array_column($balances, null, 'code');
        ksort($issues, SORT_NATURAL);
        $findings = [];
        foreach ($issues as $code => $reasons) {
            $findings[] = [
                'account_code' => (string) $code,
                'account_id' => (int) $accounts[$code]['account_id'],
                'name' => $accounts[$code]['name'],
                'issues' => array_values(array_unique($reasons)),
            ];
        }
        return $findings;
    }
}
