<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Payment\PayrollEnforcementLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollHealthInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollIncomeTaxLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollInsolvencyLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollNetWageLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollRiskySavingsLiabilityMaterializer;
use MyInvoice\Service\Payroll\Payment\PayrollSocialInsuranceLiabilityMaterializer;
use MyInvoice\Service\Payroll\PayrollProductionGate;

/**
 * Orchestrace platebních závazků schválené revize pro příkaz
 * `prepare_payments`.
 *
 * Rozdíl proti endpointu `POST /api/payroll/revisions/{id}/payments/liabilities`
 * je záměrný: ten je shovívavý a doménové chyby jednotlivých druhů závazků jen
 * posbírá do `preparation_issues`, protože slouží k opakovanému doplňování.
 * Přechod běhu do `payment_ready` ale tvrdí, že platby JSOU připravené — proto
 * je tahle cesta fail-closed: první druh závazku, který se nepodaří vytvořit,
 * shodí celou přípravu a běh zůstane v `posted`.
 */
final class PayrollRunPaymentPreparationService
{
    private const KIND_LABELS = [
        'net_wage' => 'čisté mzdy',
        'health_insurance' => 'zdravotního pojištění',
        'social_insurance' => 'sociálního pojištění',
        'income_tax' => 'daně ze závislé činnosti',
        'insolvency' => 'srážek ve standardním oddlužení',
        'enforcement' => 'exekučních srážek',
        'risky_savings' => 'povinného spoření u rizikové práce',
    ];

    public function __construct(
        private readonly PayrollNetWageLiabilityMaterializer $netWages,
        private readonly PayrollHealthInsuranceLiabilityMaterializer $healthInsurance,
        private readonly PayrollSocialInsuranceLiabilityMaterializer $socialInsurance,
        private readonly PayrollIncomeTaxLiabilityMaterializer $incomeTax,
        private readonly PayrollInsolvencyLiabilityMaterializer $insolvency,
        private readonly PayrollEnforcementLiabilityMaterializer $enforcement,
        private readonly PayrollRiskySavingsLiabilityMaterializer $riskySavings,
        private readonly PayrollProductionGate $productionGate,
    ) {}

    /**
     * @param array<string,mixed> $inputSnapshot zmrazený vstupní snapshot revize
     * @return array{liability_ids:list<int>,created_count:int}
     */
    public function prepare(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
        array $inputSnapshot,
    ): array {
        if ($supplierId <= 0 || $revisionId <= 0 || $actorUserId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a uživatel přípravy plateb musí být kladná čísla.',
            );
        }
        $this->productionGate->assertActive($supplierId);
        $this->assertPayoutRules($inputSnapshot);

        $liabilityIds = [];
        $createdCount = 0;
        foreach ([
            'net_wage' => fn (): array => $this->netWages->materialize(
                $supplierId,
                $revisionId,
                $actorUserId,
            ),
            'health_insurance' => fn (): array =>
                $this->healthInsurance->materialize(
                    $supplierId,
                    $revisionId,
                    $actorUserId,
                ),
            'social_insurance' => fn (): array =>
                $this->socialInsurance->materialize(
                    $supplierId,
                    $revisionId,
                    $actorUserId,
                ),
            'income_tax' => fn (): array => $this->incomeTax->materialize(
                $supplierId,
                $revisionId,
                $actorUserId,
            ),
            'insolvency' => fn (): array => $this->insolvency->materialize(
                $supplierId,
                $revisionId,
                $actorUserId,
            ),
            'enforcement' => fn (): array => $this->enforcement->materialize(
                $supplierId,
                $revisionId,
                $actorUserId,
            ),
            'risky_savings' => fn (): array => $this->riskySavings->materialize(
                $supplierId,
                $revisionId,
                $actorUserId,
            ),
        ] as $liabilityKind => $materialize) {
            try {
                $result = $materialize();
            } catch (\InvalidArgumentException|\DomainException $exception) {
                throw new \DomainException(sprintf(
                    'Platby nelze připravit: závazky %s se nepodařilo vytvořit. %s',
                    self::KIND_LABELS[$liabilityKind],
                    $exception->getMessage(),
                ), previous: $exception);
            }
            $liabilityIds = [...$liabilityIds, ...$result['liability_ids']];
            $createdCount += $result['created_count'];
        }
        sort($liabilityIds, SORT_NUMERIC);

        return [
            'liability_ids' => array_values(array_unique($liabilityIds)),
            'created_count' => $createdCount,
        ];
    }

    /**
     * Zaměstnanec bez výplatního pravidla shodí alokaci čisté mzdy hluboko
     * uvnitř materializeru vývojářskou hláškou („Výplata musí být nezáporná a
     * mít alokační pravidla."). Účetní z ní nepozná ani koho se to týká, ani co
     * má udělat — proto to zachytíme dopředu nad zmrazeným snapshotem a
     * jmenujeme lidi.
     *
     * @param array<string,mixed> $inputSnapshot
     */
    private function assertPayoutRules(array $inputSnapshot): void
    {
        $people = $inputSnapshot['people'] ?? null;
        if (!is_array($people)) {
            return;
        }
        $missing = [];
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)) {
                continue;
            }
            $employee = $person['employee'] ?? null;
            if (!is_array($employee) || array_is_list($employee)) {
                continue;
            }
            $employeeId = $employee['id'] ?? null;
            if (!is_int($employeeId) || $employeeId <= 0) {
                continue;
            }
            $rules = $person['payout_rules'] ?? null;
            if (is_array($rules) && $rules !== []) {
                continue;
            }
            $name = $employee['full_name'] ?? null;
            $missing[] = is_string($name) && trim($name) !== ''
                ? trim($name)
                : "zaměstnanec č. {$employeeId}";
        }
        if ($missing === []) {
            return;
        }
        sort($missing, SORT_STRING);

        throw new \DomainException(sprintf(
            count($missing) === 1
                ? 'Platby nelze připravit: %s nemá nastavené výplatní pravidlo, '
                    . 'takže není kam poslat čistou mzdu. Doplňte mu výplatní '
                    . 'pravidlo v kartě zaměstnance, pak u mzdového běhu '
                    . 'vyžádejte opravu a znovu ho spočítejte — do zmrazených '
                    . 'podkladů se nové pravidlo dostane až novou revizí.'
                : 'Platby nelze připravit: výplatní pravidlo chybí těmto lidem: %s. '
                    . 'Není kam poslat jejich čistou mzdu. Doplňte jim výplatní '
                    . 'pravidlo v kartě zaměstnance, pak u mzdového běhu '
                    . 'vyžádejte opravu a znovu ho spočítejte — do zmrazených '
                    . 'podkladů se nová pravidla dostanou až novou revizí.',
            implode(', ', $missing),
        ));
    }
}
