<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Garnishment\ClaimCategory;
use MyInvoice\Service\Payroll\Garnishment\DeductionClaim;
use MyInvoice\Service\Payroll\Garnishment\DeductionLegalBasis;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthEvidence;
use MyInvoice\Service\Payroll\Garnishment\EnforcementPersonMonthRequest;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeItem;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeKind;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResolver;
use MyInvoice\Service\Payroll\Garnishment\GarnishableIncomeResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentCalculator;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentInput;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentResult;
use MyInvoice\Service\Payroll\Garnishment\GarnishmentStatus;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;

final class PayrollRunGarnishmentProcessor
{
    public function __construct(
        private readonly GarnishmentCalculator $calculator,
        private readonly PayrollGarnishmentRunIntegration $integration,
        private readonly GarnishableIncomeResolver $incomeResolver =
            new GarnishableIncomeResolver(),
    ) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $baseResult
     * @return array<string,mixed>
     */
    public function calculate(array $snapshot, array $baseResult): array
    {
        $context = $this->context($snapshot, $baseResult);
        $people = self::rows($baseResult['people'] ?? null, 'result.people');
        $withheldTotal = 0;
        $payableTotal = 0;
        foreach ($people as &$person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            [$netCashPayable, $voluntaryDeducted, $annualSettlement] = $this->netPay(
                $person,
                $context['requires_net_pay'],
            );
            [$input, $result, $income] = $this->evaluate(
                $context,
                $person,
                $employeeId,
                $netCashPayable,
            );
            $person['enforcement'] = [
                'input' => $input->toCanonicalArray(),
                'result' => $result->jsonSerialize(),
            ];
            // Osoba se zápornou čistou mzdou (celý měsíc neplacené volno
            // a doplatek ZP do minimálního vyměřovacího základu podle § 3
            // odst. 10 z. č. 592/1992 Sb.) nemá postižitelný příjem — exekuce
            // z ní nesrazí nic a `evaluate()` jí proto dá nulový výsledek.
            // Základem výplaty pak není „co exekuce nechala", ale sama záporná
            // čistá mzda: jinak by se dluh zaměstnance tiše ztratil a účetní
            // můstek by ohlásil rozpor mezi předpisem a čistou výplatou.
            $netOverdrawn = $netCashPayable !== null && $netCashPayable < 0;
            // Doplatek ze zúčtování se přičítá až za exekučními srážkami —
            // není mzdou ani jiným postižitelným příjmem podle § 299 OSŘ.
            $payable = self::add(
                $netOverdrawn
                    ? $netCashPayable
                    : self::add(
                        $result->employeePaymentMinorUnits,
                        $income->excludedMinorUnits,
                    ),
                $annualSettlement,
            ) - $voluntaryDeducted;
            // Záporná výplata je přípustná JEN u záporné čisté mzdy. Tam, kde
            // příjem byl, znamená záporný zůstatek pořád jediné: dobrovolná
            // srážka snědla víc, než exekuce nechala.
            if (!$netOverdrawn && $payable < 0) {
                throw new \DomainException(
                    'Dobrovolná srážka přesáhla výplatu po exekučních srážkách.',
                );
            }
            $person['payable_after_enforcement_minor'] = $payable;
            $withheldTotal = self::add(
                $withheldTotal,
                $result->totalWithheldMinorUnits,
            );
            $payableTotal = self::add($payableTotal, $payable);
        }
        unset($person);
        $baseResult['people'] = $people;
        $totals = self::row($baseResult['totals'] ?? null, 'result.totals');
        $totals['enforcement_withheld_minor'] = $withheldTotal;
        $totals['payable_after_enforcement_minor'] = $payableTotal;
        $baseResult['totals'] = $totals;

        return $baseResult;
    }

    /**
     * Kolik smí zaměstnavatel v tomto běhu strhnout na dobrovolné dohody
     * o srážkách — až z toho, co exekuce nechala v obecné (nepřednostní)
     * kapacitě. Volá se PŘED výpočtem čisté mzdy se srážkami, takže dostane
     * čistou mzdu před dohodami a exekuci počítá ze správného základu.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $baseResult
     * @param array<int,int> $netCashPayableByEmployee
     * @return array<int,int>
     */
    public function voluntaryDeductionCapacities(
        array $snapshot,
        array $baseResult,
        array $netCashPayableByEmployee,
    ): array {
        $context = $this->context($snapshot, $baseResult, true);
        $capacities = [];
        foreach (self::rows($baseResult['people'] ?? null, 'result.people') as $person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            $netCashPayable = $netCashPayableByEmployee[$employeeId] ?? null;
            if ($netCashPayable === null) {
                continue;
            }
            [, $result] = $this->evaluate(
                $context,
                $person,
                $employeeId,
                $netCashPayable,
            );
            $capacities[$employeeId] =
                $this->calculator->voluntaryDeductionCapacity($result);
        }

        return $capacities;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $baseResult
     * @return array{
     *     supplier_id:int,
     *     period:string,
     *     payment_date:string,
     *     requires_net_pay:bool,
     *     evidence:array<int,EnforcementPersonMonthEvidence>,
     *     agreements:array<int,list<DeductionClaim>>
     * }
     */
    private function context(
        array $snapshot,
        array $baseResult,
        bool $requiresNetPay = false,
    ): array {
        $evidenceByEmployee = [];
        $agreementsByEmployee = [];
        foreach (self::rows($snapshot['people'] ?? null, 'snapshot.people') as $person) {
            $employee = self::row($person['employee'] ?? null, 'snapshot.employee');
            $evidence = self::row(
                $person['enforcement_evidence'] ?? null,
                'snapshot.enforcement_evidence',
            );
            $employeeId = self::positiveInt($employee, 'id');
            $evidenceByEmployee[$employeeId] =
                EnforcementPersonMonthEvidence::fromCanonicalArray($evidence);
            $agreementsByEmployee[$employeeId] = self::bridgedAgreements($person);
        }

        return [
            'supplier_id' => self::positiveInt($snapshot, 'supplier_id'),
            'period' => substr(self::string($snapshot, 'period_start'), 0, 7),
            'payment_date' => self::string($snapshot, 'payment_date'),
            'requires_net_pay' => $requiresNetPay
                || (($snapshot['schema_version'] ?? null) === 'payroll-run-input.v2'
                    && isset($baseResult['statutory'])),
            'evidence' => $evidenceByEmployee,
            'agreements' => $agreementsByEmployee,
        ];
    }

    /**
     * Dohody o srážkách ze mzdy přeložené do jazyka rozvrhu pořadí.
     *
     * NEJSOU to pohledávky rejstříku a exekuční jádro je nesráží — vstupují jen
     * proto, aby se obecná (nepřednostní) část rozdělila podle § 280 odst. 5
     * o. s. ř., tedy podle dne doručení plátci mzdy, a exekuce doručená POZDĚJI
     * než dohoda dostala až druhé místo. Vlastní srážku provádí čistá mzda
     * z kapacity dobrovolných srážek
     * ({@see \MyInvoice\Service\Payroll\Net\DeductionPriorityResolver}), takže se
     * částka nikde nezapočte dvakrát.
     *
     * Zůstatek je částka nárokovaná v TOMHLE měsíci, ne celý dluh: dohoda bez
     * stropu (`total_limit_minor = null`) je opakující se měsíční srážka a víc
     * než `requested_minor` z ní nikdy vzít nelze. Kdyby se sem dosadil dluh,
     * dohoda by v prvním měsíci spolkla celou obecnou část.
     *
     * Dohoda bez dne doručení se sem nedostane — pořadí by neměla čím doložit
     * a zůstává jí dosavadní chování, tedy zbytek po exekucích (§ 148 odst. 2
     * zákoníku práce).
     *
     * @param array<string,mixed> $person
     * @return list<DeductionClaim>
     */
    private static function bridgedAgreements(array $person): array
    {
        $agreements = $person['deduction_agreements'] ?? null;
        if ($agreements === null) {
            return [];
        }
        $result = [];
        foreach (self::rows($agreements, 'snapshot.deduction_agreements') as $agreement) {
            $deliveredOn = $agreement['delivered_on'] ?? null;
            if (!is_string($deliveredOn) || $deliveredOn === '') {
                continue;
            }
            $requested = self::int($agreement, 'requested_minor');
            $limit = $agreement['total_limit_minor'] ?? null;
            $outstanding = $limit === null
                ? $requested
                : max(0, min(
                    $requested,
                    self::int($agreement, 'total_limit_minor')
                        - self::int($agreement, 'withheld_total_minor'),
                ));
            if ($outstanding <= 0) {
                continue;
            }
            $result[] = new DeductionClaim(
                'agreement:' . self::positiveInt($agreement, 'id'),
                DeductionLegalBasis::VoluntaryAgreement,
                // Dohoda o srážkách nemůže být přednostní pohledávkou —
                // § 279 odst. 2 o. s. ř. vypočítává přednostní pohledávky
                // taxativně a dohoda mezi nimi není.
                ClaimCategory::NonPriority,
                $outstanding,
                $deliveredOn,
                legalTitleVerified: false,
                orderOrNoticeDelivered: true,
                orderIssuedOn: null,
                priorityClassificationVerified: true,
                agreementVerified: true,
            );
        }

        return $result;
    }

    /**
     * Čistá mzda PŘED dobrovolnými srážkami, částka, kterou dohody nakonec
     * dostaly, a doplatek ze zúčtování. `null` znamená, že zákonný výsledek
     * osoby není uzavřený.
     *
     * Doplatek ze zúčtování je třetí položkou schválně: do čisté mzdy, ze které
     * se počítají srážky podle § 277 odst. 1 OSŘ, nepatří — vrácená záloha na
     * daň mzdou není — ale k výplatě se připočítat musí.
     *
     * @param array<string,mixed> $person
     * @return array{0:?int,1:int,2:int}
     */
    private function netPay(array $person, bool $requiresNetPay): array
    {
        if (!$requiresNetPay) {
            return [null, 0, 0];
        }
        $statutory = self::row(
            $person['statutory'] ?? null,
            'result.person.statutory',
        );
        if (($statutory['status'] ?? null) !== 'calculated'
            || !is_int($statutory['net_payable_minor_units'] ?? null)
        ) {
            return [null, 0, 0];
        }
        $netPay = self::row(
            $statutory['net_pay'] ?? null,
            'result.person.statutory.net_pay',
        );

        return [
            self::int($netPay, 'net_before_deductions_minor_units'),
            self::int($netPay, 'deducted_minor_units'),
            self::int(
                $netPay + ['annual_settlement_minor_units' => 0],
                'annual_settlement_minor_units',
            ),
        ];
    }

    /**
     * @param array{
     *     supplier_id:int,
     *     period:string,
     *     payment_date:string,
     *     requires_net_pay:bool,
     *     evidence:array<int,EnforcementPersonMonthEvidence>,
     *     agreements:array<int,list<DeductionClaim>>
     * } $context
     * @param array<string,mixed> $person
     * @return array{0:GarnishmentInput,1:GarnishmentResult,2:GarnishableIncomeResult}
     */
    private function evaluate(
        array $context,
        array $person,
        int $employeeId,
        ?int $netCashPayable,
    ): array {
        $supplierId = $context['supplier_id'];
        $totals = self::row($person['totals'] ?? null, 'result.person.totals');
        $grossCashPayable = self::int($totals, 'cash_payable_minor');
        $grossEnforcementBase = self::int($totals, 'enforcement_base_minor');
        $cashPayable = $grossCashPayable;
        $enforcementBase = $grossEnforcementBase;
        $statutoryUnavailable = false;
        if ($context['requires_net_pay']) {
            if ($netCashPayable === null) {
                $statutoryUnavailable = true;
            } elseif ($netCashPayable < 0) {
                // Není z čeho srážet: § 299 OSŘ postihuje mzdu a jiné příjmy,
                // a osoba, jejíž čistá mzda je záporná (neplacené volno
                // + doplatek ZP do minimálního vyměřovacího základu), žádný
                // nemá. Je to REGULÉRNÍ nulový výsledek, ne rozpor podkladů —
                // kdyby propadl níž do větve `cash_payable < 0`, dostal by
                // stav „k ručnímu posouzení" a zablokoval by celý běh kvůli
                // situaci, která je zákonem předvídaná a jednoznačná.
                $cashPayable = 0;
                $enforcementBase = 0;
            } else {
                $excluded = $grossCashPayable - $grossEnforcementBase;
                $cashPayable = $netCashPayable;
                $enforcementBase = $cashPayable - $excluded;
            }
        }
        $income = $statutoryUnavailable
            ? new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                0,
                ['net_pay_result_missing_or_unverified'],
                [],
            )
            : ($cashPayable < 0
                || $enforcementBase < 0
                || $enforcementBase > $cashPayable
            ? new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                0,
                ['cash_payable_enforcement_base_inconsistent'],
                [],
            )
            : $this->incomeResolver->resolve(array_values(array_filter([
                $enforcementBase === 0 ? null : new GarnishableIncomeItem(
                    "revision-person-{$employeeId}-garnishable",
                    GarnishableIncomeKind::Wage,
                    $enforcementBase,
                    "supplier-{$supplierId}",
                ),
                $cashPayable === $enforcementBase
                    ? null
                    : new GarnishableIncomeItem(
                        "revision-person-{$employeeId}-excluded",
                        GarnishableIncomeKind::TravelReimbursement,
                        $cashPayable - $enforcementBase,
                        "supplier-{$supplierId}",
                    ),
            ])), true));
        $evidence = $context['evidence'][$employeeId]
            ?? throw new \UnexpectedValueException(
                'Snapshot neobsahuje exekuční důkazy zaměstnance.',
            );
        $input = new GarnishmentInput(
            $context['period'],
            $context['payment_date'],
            $income,
            $evidence->claims,
            $evidence->eligibleDependants,
            $evidence->dependantsEvidenceComplete,
            $evidence->eligibleSpouse,
            $evidence->spouseEvidenceComplete,
            $evidence->pensionEvidence,
            $evidence->hasMultiplePayers,
            $evidence->protectedAmountOverrideMinorUnits,
            $evidence->insolvency,
            $evidence->protectedAmountOverrideVerified,
            $evidence->claimRegisterEvidenceComplete,
            $evidence->spousePensionEvidence,
            $context['agreements'][$employeeId] ?? [],
        );

        return [$input, $this->calculator->calculate($input), $income];
    }

    /** @param array<string,mixed> $result */
    public function storeApproved(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        foreach (self::rows($result['people'] ?? null, 'result.people') as $person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            $enforcement = self::row(
                $person['enforcement'] ?? null,
                'result.person.enforcement',
            );
            $inputData = self::row(
                $enforcement['input'] ?? null,
                'result.person.enforcement.input',
            );
            $resultData = self::row(
                $enforcement['result'] ?? null,
                'result.person.enforcement.result',
            );
            $input = GarnishmentInput::fromCanonicalArray($inputData);
            $calculated = GarnishmentResult::fromCanonicalArray($resultData);
            if ($calculated->status !== GarnishmentStatus::Supported) {
                throw new \DomainException(
                    'Mzdový běh obsahuje srážku vyžadující ruční kontrolu.',
                );
            }
            $request = new EnforcementPersonMonthRequest(
                $supplierId,
                $employeeId,
                $input->period,
                $input->paymentDate,
                [],
                true,
            );
            $this->integration->storeCalculation(
                $request,
                new PayrollGarnishmentCalculation(
                    $supplierId,
                    $employeeId,
                    $input,
                    $calculated,
                ),
                $revisionId,
                "payroll-revision:{$revisionId}:employee:{$employeeId}:enforcement:v1",
            );
        }
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException("{$key} musí být řetězec.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException("{$key} musí být celé číslo.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function positiveInt(array $data, string $key): int
    {
        $value = self::int($data, $key);
        if ($value <= 0) {
            throw new \UnexpectedValueException("{$key} musí být kladné.");
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být objekt.");
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "{$field} musí mít textové klíče.",
                );
            }
            $result[$key] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException("{$field} musí být seznam.");
        }
        return array_map(
            static fn (mixed $row): array => self::row($row, $field),
            $value,
        );
    }

    private static function add(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new \OverflowException('Součet srážek přesahuje celočíselný rozsah.');
        }
        return $left + $right;
    }
}
