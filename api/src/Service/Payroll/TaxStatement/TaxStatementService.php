<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\TaxStatement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollTaxStatementRepository;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzExternalCodebookCatalog;
use MyInvoice\Service\Report\EpoSupplierBlockBuilder;

/**
 * Sestaví roční vyúčtování daně ze závislé činnosti (DPZVD6) a daně vybírané
 * srážkou (DPSVD2) za jedno zdaňovací období.
 *
 * Obě písemnosti čerpají z jednoho podkladu, ale podávají se odděleně a v jiné
 * lhůtě: DPZVD6 do dvou měsíců po konci roku (elektronicky do 20. března,
 * § 38j odst. 5 ZDP), DPSVD2 do tří měsíců (§ 137 odst. 2 daňového řádu).
 * Lhůty nelze prodloužit.
 */
final class TaxStatementService
{
    public const FORM_DEPENDENT_ACTIVITY = 'dpzvd6';
    public const FORM_WITHHOLDING_TAX = 'dpsvd2';

    public const FORMS = [self::FORM_DEPENDENT_ACTIVITY, self::FORM_WITHHOLDING_TAX];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTaxStatementRepository $repository,
        private readonly TaxStatementCalculator $calculator,
        private readonly TaxStatementXmlBuilder $xmlBuilder,
        private readonly JmhzExternalCodebookCatalog $codebook,
    ) {}

    /**
     * Podklad obou vyúčtování bez generování XML.
     *
     * @param array{variant?:string} $meta
     * @return array{dpzvd6:array<string,mixed>,dpsvd2:array<string,mixed>}
     */
    public function preview(int $supplierId, int $year, array $meta = []): array
    {
        $basis = $this->basis($supplierId, $year);

        return [
            self::FORM_DEPENDENT_ACTIVITY =>
                $this->calculator->dependentActivity($basis, $meta)->toSummary(),
            self::FORM_WITHHOLDING_TAX =>
                $this->calculator->withholdingTax($basis, $meta)->toSummary(),
        ];
    }

    /**
     * XML jedné písemnosti.
     *
     * @param array{variant?:string,verze_sw?:string,verze_pis?:string,d_zjist?:string} $meta
     * @return array{xml:string,summary:array<string,mixed>,warnings:list<string>}
     */
    public function build(
        int $supplierId,
        int $year,
        string $formCode,
        array $meta = [],
    ): array {
        if (!in_array($formCode, self::FORMS, true)) {
            throw new \InvalidArgumentException(
                'Neznámý tiskopis vyúčtování: ' . $formCode,
            );
        }
        $basis = $this->basis($supplierId, $year);
        $supplier = EpoSupplierBlockBuilder::loadSupplier($this->db->pdo(), $supplierId);

        if ($formCode === self::FORM_DEPENDENT_ACTIVITY) {
            $statement = $this->calculator->dependentActivity($basis, $meta);
            $built = $this->xmlBuilder->buildDependentActivity($supplier, $statement, $meta);
        } else {
            $statement = $this->calculator->withholdingTax($basis, $meta);
            $built = $this->xmlBuilder->buildWithholdingTax($supplier, $statement, $meta);
        }

        return [
            'xml' => $built['xml'],
            'summary' => $statement->toSummary(),
            'warnings' => $built['warnings'],
        ];
    }

    private function basis(int $supplierId, int $year): TaxStatementBasis
    {
        if ($year < 2010 || $year > 2199) {
            throw new \InvalidArgumentException(
                'Vyúčtování lze sestavit za zdaňovací období 2010 až 2199.',
            );
        }

        $warnings = [];
        /** @var array<int,array{headcount:int,advance:int,bonus:int,withholding:int,has_run:bool}> $byMonth */
        $byMonth = [];
        for ($month = 1; $month <= 12; $month++) {
            $byMonth[$month] = [
                'headcount' => 0,
                'advance' => 0,
                'bonus' => 0,
                'withholding' => 0,
                'has_run' => false,
            ];
        }

        foreach ($this->repository->monthlyTaxTotals($supplierId, $year) as $row) {
            $month = $this->monthOf((string) $row['period_start'], $year);
            $byMonth[$month]['has_run'] = true;
            $byMonth[$month]['headcount'] += $this->amount($row['headcount'], 'počet osob');
            $byMonth[$month]['advance'] += $this->amount(
                $row['advance_tax_minor'],
                'úhrn záloh na daň',
            );
            $byMonth[$month]['bonus'] += $this->amount(
                $row['monthly_bonus_minor'],
                'úhrn měsíčních bonusů',
            );
            $byMonth[$month]['withholding'] += $this->amount(
                $row['withholding_tax_minor'],
                'úhrn srážkové daně',
            );
        }

        $overpayments = [];
        $bonusTopUps = [];
        foreach ($this->repository->annualSettlementPayouts($supplierId, $year) as $row) {
            $month = $this->monthOf((string) $row['payout_period_start'], $year);
            $overpayments[$month] = ($overpayments[$month] ?? 0) + $this->amount(
                $row['tax_overpayment_minor'],
                'přeplatek z ročního zúčtování',
            );
            $bonusTopUps[$month] = ($bonusTopUps[$month] ?? 0) + $this->amount(
                $row['bonus_topup_minor'],
                'doplatek na daňovém bonusu',
            );
        }

        $remitted = ['advance_tax' => [], 'withholding_tax' => []];
        foreach ($this->repository->remittedTaxTotals($supplierId, $year) as $row) {
            $kind = (string) $row['liability_kind'];
            if (!isset($remitted[$kind])) {
                continue;
            }
            $month = $this->monthOf((string) $row['period_start'], $year);
            $remitted[$kind][$month] = ($remitted[$kind][$month] ?? 0)
                + (int) $row['settled_minor'];
        }

        $months = [];
        foreach ($byMonth as $month => $values) {
            $months[] = new TaxStatementMonth(
                $month,
                $values['headcount'],
                $values['advance'],
                $values['bonus'],
                $values['withholding'],
                $overpayments[$month] ?? 0,
                $bonusTopUps[$month] ?? 0,
                max(0, $remitted['advance_tax'][$month] ?? 0),
                max(0, $remitted['withholding_tax'][$month] ?? 0),
                $values['has_run'],
            );
        }

        return new TaxStatementBasis(
            $year,
            $months,
            $this->workplaces($supplierId, $year),
            $this->repository->nonResidentEmployeeCount($supplierId, $year),
            $warnings,
        );
    }

    /**
     * Příloha č. 1 k 1. prosinci. Okres tiskopis chce jménem, ale evidence
     * vztahu ho nenese — dopočítá se z připnutého číselníku obcí. Když číselník
     * rozhodný den nepokrývá (starší rok, jiná verze), zůstane prázdný: je to
     * volitelná položka a vymyslet ji nejde.
     *
     * @return list<WorkplaceHeadcount>
     */
    private function workplaces(int $supplierId, int $year): array
    {
        $onDate = sprintf('%04d-12-01', $year);
        $places = [];
        foreach ($this->repository->workplaceHeadcount($supplierId, $onDate) as $row) {
            $code = $row['municipality_code'] !== null
                ? trim((string) $row['municipality_code'])
                : null;
            $name = $row['municipality_name'] !== null
                ? trim((string) $row['municipality_name'])
                : null;
            $district = null;
            if ($code !== null && $code !== '' && $name !== null && $name !== '') {
                try {
                    $entry = $this->codebook->requireMunicipality($code, $name, $onDate);
                    $metadata = $entry['metadata'] ?? [];
                    if (is_array($metadata) && isset($metadata['district_name'])) {
                        $district = (string) $metadata['district_name'];
                    }
                } catch (\Throwable) {
                    $district = null;
                }
            }
            $places[] = new WorkplaceHeadcount(
                $code === '' ? null : $code,
                $name === '' ? null : $name,
                $district,
                (int) $row['headcount'],
            );
        }

        return $places;
    }

    private function monthOf(string $date, int $year): int
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || (int) $parsed->format('Y') !== $year) {
            throw new \UnexpectedValueException(
                'Podklad vyúčtování obsahuje období mimo vykazovaný rok: ' . $date,
            );
        }

        return (int) $parsed->format('n');
    }

    private function amount(mixed $value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value)) {
            throw new \DomainException(
                'Zmrazený mzdový výsledek nenese ' . $label . ' jako číslo.',
            );
        }
        $amount = (int) $value;
        if ($amount < 0) {
            throw new \DomainException(ucfirst($label) . ' vyšel záporně.');
        }

        return $amount;
    }
}
