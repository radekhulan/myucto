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
use MyInvoice\Service\Payroll\Garnishment\InsolvencyMode;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentCalculation;
use MyInvoice\Service\Payroll\Garnishment\PayrollGarnishmentRunIntegration;
use MyInvoice\Service\Payroll\Garnishment\SeveranceMultiple;

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
            [$input, $result, $income, $cashReduction] = $this->evaluate(
                $context,
                $person,
                $employeeId,
                $netCashPayable,
                self::statutorySharesFromPerson($person),
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
                    ) - $cashReduction,
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
     * @param array<int,array<string,int>> $statutoryShares daň a pojistné osoby
     *   (klíče jako v `net_pay`), podle kterých se přiřadí odstupnému
     * @return array<int,int>
     */
    public function voluntaryDeductionCapacities(
        array $snapshot,
        array $baseResult,
        array $netCashPayableByEmployee,
        array $statutoryShares = [],
    ): array {
        $context = $this->context($snapshot, $baseResult, true);
        $capacities = [];
        foreach (self::rows($baseResult['people'] ?? null, 'result.people') as $person) {
            $employeeId = self::positiveInt($person, 'employee_id');
            $netCashPayable = $netCashPayableByEmployee[$employeeId] ?? null;
            if ($netCashPayable === null) {
                continue;
            }
            [$input, $result, $income, $cashReduction] = $this->evaluate(
                $context,
                $person,
                $employeeId,
                $netCashPayable,
                $statutoryShares[$employeeId] ?? null,
            );
            $payableBeforeAgreements = self::add(
                $result->employeePaymentMinorUnits,
                $income->excludedMinorUnits,
            ) - $cashReduction;
            // Dohody o srážkách se berou jen ze mzdy měsíce výplaty, ne
            // z násobků odstupného — viz severanceMultiples(). Kapacita se proto
            // počítá z výsledku samotné mzdy; pořadí mzda → násobky zaručuje,
            // že je to přesně táž mzdová část, kterou spočítá i celý výsledek.
            if ($input->severanceMultiples !== []) {
                $result = $this->calculator->calculate($input->withoutSeverance());
            }
            $capacities[$employeeId] = $cashReduction > 0
                ? min(
                    $this->calculator->voluntaryDeductionCapacity($result),
                    max(0, $payableBeforeAgreements),
                )
                : $this->calculator->voluntaryDeductionCapacity($result);
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
     *     agreements:array<int,list<DeductionClaim>>,
     *     severance:array<int,array<int,array{employment_id:int,multiple:?int,end_date:?string,other_income_from:?string,other_payer_applies_protected_amount:bool}>>
     * }
     */
    private function context(
        array $snapshot,
        array $baseResult,
        bool $requiresNetPay = false,
    ): array {
        $evidenceByEmployee = [];
        $agreementsByEmployee = [];
        $severanceByEmployee = [];
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
            $severanceByEmployee[$employeeId] = self::severanceInputs($person);
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
            'severance' => $severanceByEmployee,
        ];
    }

    /**
     * Vstupy druhu `severance` (odstupné a obdobná plnění při skončení,
     * § 299 odst. 1 písm. g) o. s. ř.) podle ID vstupu, s počtem násobků
     * průměrného výdělku z množství vstupu a s okolnostmi skončení vztahu.
     *
     * @param array<string,mixed> $person
     * @return array<int,array{employment_id:int,multiple:?int,end_date:?string,other_income_from:?string,other_payer_applies_protected_amount:bool}>
     */
    private static function severanceInputs(array $person): array
    {
        $employments = $person['employments'] ?? null;
        if (!is_array($employments) || !array_is_list($employments)) {
            return [];
        }
        $result = [];
        foreach ($employments as $employmentSnapshot) {
            if (!is_array($employmentSnapshot)) {
                continue;
            }
            $employment = $employmentSnapshot['employment'] ?? null;
            $inputs = $employmentSnapshot['inputs'] ?? null;
            if (!is_array($employment) || !is_array($inputs)) {
                continue;
            }
            $facts = is_array($employmentSnapshot['severance_garnishment'] ?? null)
                ? $employmentSnapshot['severance_garnishment']
                : [];
            foreach ($inputs as $input) {
                if (!is_array($input)
                    || !is_array($input['component'] ?? null)
                    || ($input['component']['component_kind'] ?? null) !== 'severance'
                    || !is_int($input['id'] ?? null)
                ) {
                    continue;
                }
                $quantity = $input['quantity_milliunits'] ?? null;
                $multiple = is_int($quantity) && $quantity > 0 && $quantity % 1000 === 0
                    && intdiv($quantity, 1000) <= SeveranceMultiple::MAX_MULTIPLES
                        ? intdiv($quantity, 1000)
                        : null;
                $endDate = $employment['end_date'] ?? null;
                $otherIncomeFrom = $facts['other_income_from'] ?? null;
                $result[$input['id']] = [
                    'employment_id' => (int) ($employment['id'] ?? 0),
                    'multiple' => $multiple,
                    'end_date' => is_string($endDate) && $endDate !== '' ? $endDate : null,
                    'other_income_from' => is_string($otherIncomeFrom) && $otherIncomeFrom !== ''
                        ? $otherIncomeFrom
                        : null,
                    'other_payer_applies_protected_amount' =>
                        ($facts['other_payer_applies_protected_amount'] ?? false) === true,
                ];
            }
        }

        return $result;
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
     * Stejnou cestou jde srážka ze zákona podle § 147 odst. 1 písm. c) až e)
     * zákoníku práce (`legal_basis` ≠ `agreement`): den zahájení srážek je
     * u ní povinný a soutěží jím o obecnou část s exekucemi stejně jako dohoda
     * dnem doručení. Pro exekuční jádro jde o týž druh přemostění — nepřednostní
     * srážka mimo rejstřík, kterou provádí čistá mzda.
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
        ?array $statutoryShares = null,
    ): array {
        $supplierId = $context['supplier_id'];
        $totals = self::row($person['totals'] ?? null, 'result.person.totals');
        $grossCashPayable = self::int($totals, 'cash_payable_minor');
        $grossEnforcementBase = self::int($totals, 'enforcement_base_minor');
        /*
         * NEPENĚŽNÍ příjem se ze základu srážek vyjímá.
         *
         * Zdanitelný nepeněžní příjem (stravování, benefit) vstupuje do základu
         * daně i pojistného, ale VYPLÁCÍ se v naturáliích — `cash_payable` je
         * u něj nula, kdežto `enforcement_base` nese celou částku. Základ srážek
         * tím přeroste peněžní výplatu a rozdíl `cash_payable − enforcement_base`
         * vyjde ZÁPORNÝ, takže se níž nepozná od rozporu podkladů a osoba spadne
         * do ručního posouzení.
         *
         * Srazit přitom nejde nic: § 299 OSŘ postihuje příjem, který se
         * zaměstnanci vyplácí, a z obědu se exekuci neodvede. Nepeněžní část se
         * proto ze základu odečte — je to rozdíl `source_amount − cash_payable`,
         * tedy doložené číslo, ne odhad. Kontrola pod tím zůstává: skutečný
         * rozpor (základ větší než peněžní výplata i po odečtení naturálií) se
         * pořád pozná a pořád zastaví běh.
         */
        $sourceAmount = self::intOrNull($totals, 'source_amount_minor');
        $nonCash = $sourceAmount === null ? 0 : max(0, $sourceAmount - $grossCashPayable);
        if ($nonCash > 0) {
            $grossEnforcementBase = max(0, $grossEnforcementBase - $nonCash);
        }
        /*
         * ZÁPORNÁ PENĚŽNÍ složka mimo základ srážek (vrácená nebo dobropisovaná
         * náhrada výdajů, odpočet zálohy na pracovní cestu) snižuje výplatu, ale
         * základ srážek ne. Náhrada výdajů podle § 6 odst. 7 ZDP není mzdou ani
         * jiným příjmem podle § 299 OSŘ: kladná se do čisté mzdy pro srážky
         * (§ 277 OSŘ) nepočítá, a záporná ji proto nemůže snižovat. Exekuce se
         * počítá ze mzdy, vrácení náhrady jde jen proti výplatě.
         *
         * Základ srážek tak smí převýšit výplatu právě o tuto doloženou částku,
         * ne víc. Kontrola rozporu pod tím zůstává.
         */
        $excludedCashReduction = self::excludedCashReduction($person);
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
        $evidence = $context['evidence'][$employeeId]
            ?? throw new \UnexpectedValueException(
                'Snapshot neobsahuje exekuční důkazy zaměstnance.',
            );
        $cashReduction = min(
            $excludedCashReduction,
            max(0, $enforcementBase - $cashPayable),
        );
        $consistent = !$statutoryUnavailable
            && $cashPayable >= 0
            && $enforcementBase >= 0
            && $enforcementBase <= $cashPayable + $cashReduction;
        $wageBase = $enforcementBase;
        $severanceMultiples = [];
        $severanceItems = [];
        if ($consistent && $enforcementBase > 0) {
            [$wageBase, $severanceMultiples, $severanceItems] = $this->severanceMultiples(
                $context,
                $person,
                $employeeId,
                $enforcementBase,
                $evidence,
                $statutoryShares,
            );
        }
        $income = $statutoryUnavailable
            ? new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                0,
                ['net_pay_result_missing_or_unverified'],
                [],
            )
            : (!$consistent
            ? new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                0,
                ['cash_payable_enforcement_base_inconsistent'],
                [],
            )
            : $this->incomeResolver->resolve(array_values(array_filter([
                $wageBase === 0 ? null : new GarnishableIncomeItem(
                    "revision-person-{$employeeId}-garnishable",
                    GarnishableIncomeKind::Wage,
                    $wageBase,
                    "supplier-{$supplierId}",
                ),
                $cashPayable + $cashReduction === $enforcementBase
                    ? null
                    : new GarnishableIncomeItem(
                        "revision-person-{$employeeId}-excluded",
                        GarnishableIncomeKind::TravelReimbursement,
                        $cashPayable + $cashReduction - $enforcementBase,
                        "supplier-{$supplierId}",
                    ),
                ...$severanceItems,
            ])), true));
        $buildInput = static fn (GarnishableIncomeResult $income): GarnishmentInput => new GarnishmentInput(
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
            $income->status === GarnishmentStatus::Supported ? $severanceMultiples : [],
        );
        $input = $buildInput($income);
        $result = $this->calculator->calculate($input);
        if ($income->status !== GarnishmentStatus::Supported) {
            return [$input, $result, $income, 0];
        }
        // Vrácení náhrady se bere z toho, co po exekuci zbylo zaměstnanci.
        // Na to, aby ho přesáhlo, zákon odpověď nedává (pořadí s exekucí,
        // nezabavitelná částka) — rozhodne účetní, ne odhad.
        if ($cashReduction > 0
            && self::add($result->employeePaymentMinorUnits, $income->excludedMinorUnits) < $cashReduction
        ) {
            $income = new GarnishableIncomeResult(
                GarnishmentStatus::ManualReview,
                0,
                0,
                ['excluded_cash_reduction_exceeds_payment'],
                [],
            );
            $input = $buildInput($income);

            return [$input, $this->calculator->calculate($input), $income, 0];
        }

        return [$input, $result, $income, $cashReduction];
    }

    /**
     * O kolik výplatu snižují záporné peněžní složky, které do základu srážek
     * nepatří. Bere se z rozpadu po vstupech: peněžní vstup (výplata = částka)
     * se základem srážek vyšším než výplata. Snímek bez rozpadu vrací nulu
     * a počítá se jako dřív.
     *
     * @param array<string,mixed> $person
     */
    private static function excludedCashReduction(array $person): int
    {
        $employments = $person['employments'] ?? null;
        if (!is_array($employments) || !array_is_list($employments)) {
            return 0;
        }
        $reduction = 0;
        foreach ($employments as $employment) {
            $inputs = is_array($employment) ? ($employment['inputs'] ?? null) : null;
            if (!is_array($inputs) || !array_is_list($inputs)) {
                continue;
            }
            foreach ($inputs as $input) {
                $totals = is_array($input) && is_array($input['totals'] ?? null)
                    ? $input['totals']
                    : [];
                $source = self::intOrNull($totals, 'source_amount_minor');
                $cash = self::intOrNull($totals, 'cash_payable_minor');
                $base = self::intOrNull($totals, 'enforcement_base_minor');
                if ($source === null || $cash === null || $base === null
                    || $source !== $cash || $cash >= $base
                ) {
                    continue;
                }
                $reduction = self::add($reduction, $base - $cash);
            }
        }

        return $reduction;
    }

    /**
     * Odstupné vyplacené v tomto běhu jako násobky průměrného výdělku
     * (§ 299 odst. 4 o. s. ř.).
     *
     * Srážky se počítají z ČISTÉHO odstupného (§ 277 odst. 1 o. s. ř.: od
     * příjmu se odečte záloha na daň a pojistné). Odstupné nepodléhá pojistnému
     * (§ 5 odst. 2 písm. b) z. č. 589/1992 Sb., § 3 odst. 2 písm. b) z. č.
     * 592/1992 Sb.),
     * ale je zdanitelným příjmem ze závislé činnosti, takže záloha na daň
     * osoby je společná pro mzdu i odstupné. Připadne mu poměrná část zálohy
     * podle jeho podílu na základu daně; pojistné stejně podle podílu na
     * vyměřovacím základu (u výchozí složky nula). Poměr je deterministický
     * a nezávisí na pořadí slev na dani.
     *
     * Čisté odstupné se rozdělí na tolik stejných násobků, kolika násobkům
     * průměrného výdělku odpovídá (množství vstupu). Počet násobků zadává
     * účetní při založení odstupného v kartě Skončení vztahu, předvyplní ho
     * návrh podle § 67 ZP.
     *
     * Rozdělení se dělá jen tam, kde se vůbec může srážet (pohledávka,
     * insolvence nebo dohoda o srážkách). Jinde zůstává výpočet beze změny —
     * nic se nesráží a kanonický vstup se nemá čím lišit.
     *
     * Dohody o srážkách se provádějí jen ze mzdy měsíce výplaty, ne z násobků.
     * Dohoda je sjednaná na mzdu za trvání vztahu a měsíční částka dohody se
     * tak nevynásobí počtem násobků odstupného; je to výklad ve prospěch
     * zaměstnance.
     *
     * @param array<string,mixed> $person
     * @param array<string,int>|null $shares
     * @return array{0:int,1:list<SeveranceMultiple>,2:list<GarnishableIncomeItem>}
     */
    private function severanceMultiples(
        array $context,
        array $person,
        int $employeeId,
        int $enforcementBase,
        EnforcementPersonMonthEvidence $evidence,
        ?array $shares,
    ): array {
        $inputs = $context['severance'][$employeeId] ?? [];
        if ($inputs === [] || !self::withholdingPossible($evidence, $context['agreements'][$employeeId] ?? [])) {
            return [$enforcementBase, [], []];
        }
        $supplierId = $context['supplier_id'];
        $resultInputs = [];
        foreach (self::rows($person['employments'] ?? [], 'result.employments') as $employment) {
            foreach (self::rows($employment['inputs'] ?? [], 'result.employment.inputs') as $input) {
                $resultInputs[self::positiveInt($input, 'input_id')] = self::row(
                    $input['totals'] ?? null,
                    'result.input.totals',
                );
            }
        }
        $personTotals = self::row($person['totals'] ?? null, 'result.person.totals');
        $wageBase = $enforcementBase;
        /** @var array<int,array{amount:int,overlap:bool,other_payer:bool}> $parts */
        $parts = [];
        $manual = [];
        foreach ($inputs as $inputId => $facts) {
            $totals = $resultInputs[$inputId] ?? null;
            if ($totals === null) {
                continue;
            }
            $gross = self::intOrNull($totals, 'enforcement_base_minor') ?? 0;
            if ($gross <= 0) {
                continue;
            }
            $net = $context['requires_net_pay']
                ? self::severanceNet($gross, $totals, $personTotals, $shares)
                : $gross;
            $itemId = "employment:{$facts['employment_id']}:severance-input-{$inputId}";
            if ($net === null || $net > $wageBase) {
                // Bez daně osoby nebo s rozporným základem nejde čisté
                // odstupné spočítat — do ručního posouzení, ne odhadem.
                $manual[] = new GarnishableIncomeItem(
                    $itemId,
                    GarnishableIncomeKind::Severance,
                    min($gross, $wageBase),
                    "supplier-{$supplierId}",
                );
                $wageBase -= min($gross, $wageBase);
                continue;
            }
            $wageBase -= $net;
            if ($facts['multiple'] === null) {
                $manual[] = new GarnishableIncomeItem(
                    $itemId,
                    GarnishableIncomeKind::Severance,
                    $net,
                    "supplier-{$supplierId}",
                );
                continue;
            }
            foreach (SeveranceMultiple::split($net, $facts['multiple']) as $offset => $amount) {
                $index = $offset + 1;
                $part = $parts[$index] ?? ['amount' => 0, 'overlap' => false, 'other_payer' => true];
                $overlap = self::overlapsOtherIncome($facts['end_date'], $facts['other_income_from'], $index);
                $parts[$index] = [
                    'amount' => self::add($part['amount'], $amount),
                    'overlap' => $part['overlap'] || $overlap,
                    'other_payer' => $part['other_payer']
                        && (!$overlap || $facts['other_payer_applies_protected_amount']),
                ];
            }
        }
        ksort($parts);
        $multiples = [];
        foreach ($parts as $index => $part) {
            $multiples[] = new SeveranceMultiple(
                $index,
                $part['amount'],
                $part['overlap'],
                $part['overlap'] && $part['other_payer'],
            );
        }

        return [$wageBase, $multiples, $manual];
    }

    /**
     * @param list<DeductionClaim> $agreements
     */
    private static function withholdingPossible(
        EnforcementPersonMonthEvidence $evidence,
        array $agreements,
    ): bool {
        if ($evidence->insolvency->mode !== InsolvencyMode::None || $agreements !== []) {
            return true;
        }
        foreach ($evidence->claims as $claim) {
            if ($claim->active && $claim->outstandingMinorUnits > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Čisté odstupné: hrubá částka minus jeho poměrná část zálohy na daň
     * a pojistného zaměstnance. `null` = daň osoby není k dispozici nebo jí
     * chybí základ, ze kterého by se poměr spočítal.
     *
     * @param array<string,mixed> $inputTotals
     * @param array<string,mixed> $personTotals
     * @param array<string,int>|null $shares
     */
    private static function severanceNet(
        int $gross,
        array $inputTotals,
        array $personTotals,
        ?array $shares,
    ): ?int {
        if ($shares === null) {
            return null;
        }
        $deductions = 0;
        foreach ([
            'tax_base_minor' => ($shares['advance_tax_minor_units'] ?? 0)
                + ($shares['withholding_tax_minor_units'] ?? 0),
            'social_base_minor' => $shares['employee_social_minor_units'] ?? 0,
            'health_base_minor' => $shares['employee_health_minor_units'] ?? 0,
        ] as $baseKey => $amount) {
            $part = self::intOrNull($inputTotals, $baseKey) ?? 0;
            if ($amount <= 0 || $part <= 0) {
                continue;
            }
            $whole = self::intOrNull($personTotals, $baseKey);
            if ($whole === null || $whole < $part) {
                return null;
            }
            $deductions = self::add(
                $deductions,
                intdiv($amount * $part * 2 + $whole, 2 * $whole),
            );
        }

        return max(0, $gross - $deductions);
    }

    /**
     * Připadá násobek `$index` do doby, kdy má povinný jiný příjem?
     *
     * Doba poskytování odstupného se počítá ode dne po skončení: násobek 1
     * je první měsíc po skončení, násobek 2 druhý atd. Jiný příjem vzniklý
     * nejpozději posledním dnem toho měsíce se s násobkem sčítá (§ 299 odst. 4
     * věta druhá o. s. ř.).
     */
    private static function overlapsOtherIncome(?string $endDate, ?string $otherIncomeFrom, int $index): bool
    {
        if ($otherIncomeFrom === null || $endDate === null) {
            return false;
        }

        return $otherIncomeFrom <= SeveranceMultiple::periodEnd($endDate, $index);
    }

    /**
     * Daň a pojistné osoby ze zákonného výsledku, ze kterých se přiřadí část
     * odstupnému. `null`, dokud zákonný výsledek není vypočtený.
     *
     * @param array<string,mixed> $person
     * @return array<string,int>|null
     */
    private static function statutorySharesFromPerson(array $person): ?array
    {
        $statutory = $person['statutory'] ?? null;
        if (!is_array($statutory) || ($statutory['status'] ?? null) !== 'calculated') {
            return null;
        }
        $netPay = $statutory['net_pay'] ?? null;
        if (!is_array($netPay)) {
            return null;
        }
        $shares = [];
        foreach ([
            'advance_tax_minor_units',
            'withholding_tax_minor_units',
            'employee_social_minor_units',
            'employee_health_minor_units',
        ] as $key) {
            $shares[$key] = is_int($netPay[$key] ?? null) ? $netPay[$key] : 0;
        }

        return $shares;
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

    /**
     * Nepovinný celočíselný údaj souhrnu.
     *
     * `null` znamená „souhrn tenhle klíč nenese", ne nulu: starší snímky
     * `source_amount_minor` neměly a nepeněžní část se z nich odvodit nedá.
     * Takový snímek se proto musí počítat přesně jako dřív.
     *
     * @param array<string,mixed> $data
     */
    private static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
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
