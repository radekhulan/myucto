<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Termination;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollAverageEarningRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentTerminationRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollLeaveRepository;
use MyInvoice\Service\Payroll\Absence\LeaveCompensationCalculator;
use MyInvoice\Service\Payroll\PayrollHourlyAverageFloorResolver;
use MyInvoice\Service\Payroll\Calculation\PayrollWageRoundingPolicy;
use MyInvoice\Service\Payroll\Document\AverageEarningsMonthlyConverter;
use MyInvoice\Service\Payroll\Document\EmploymentExitReadinessException;
use MyInvoice\Service\Payroll\Garnishment\SeveranceMultiple;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;

/**
 * Skončení pracovního vztahu na jednom místě: způsob a důvod skončení,
 * vyrovnání dovolené (§ 222 odst. 2 ZP a § 147 odst. 1 písm. e) ZP), návrh
 * odstupného (§ 67 ZP) nebo jednorázové náhrady (§ 271ca ZP), předvyplnění
 * odhlášky REGZEC A2 a úmrtí zaměstnance (§ 328 ZP).
 *
 * Peníze vznikají jako mzdové VSTUPY běhu za měsíc skončení — běh je
 * zpracuje stejně jako jiné vstupy (daň, pojistné, JMHZ, účtování). Nic se
 * nepočítá mimo běh a nic se nezapisuje bez výslovné akce účetní.
 *
 * Průměrný výdělek je vždy schválený průměr za čtvrtletí, do kterého spadá
 * den skončení (§ 351 a § 360 ZP), stejně jako u potvrzení pro Úřad práce.
 */
final class PayrollEmploymentTerminationService
{
    public const LEAVE_COMPONENT = 'NAHRADA_MZDY_DOVOLENA';
    public const SEVERANCE_COMPONENT = 'ODSTUPNE';
    public const WORK_INJURY_COMPONENT = 'NAHRADA_271CA';
    public const LEAVE_PAYOUT_REASON =
        'Proplacení nevyčerpané dovolené při skončení (§ 222 odst. 2 ZP)';

    private const SEVERANCE_RELATIONS = PayrollTerminationReason::SEVERANCE_RELATIONS;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentTerminationRepository $terminations,
        private readonly PayrollLeaveRepository $leave,
        private readonly PayrollAverageEarningRepository $averages,
        private readonly AverageEarningsMonthlyConverter $converter,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollDeferredIncomeRepository $deferredIncomes,
    ) {}

    /**
     * Identita vstupu vyrovnání dovolené. Pořadí `$generation` dovoluje po
     * zpětvzetí (korekce) založit vyrovnání znovu — unikátní klíč vstupů
     * by jinak druhé založení se stejnou identitou odmítl, protože schválený
     * původní vstup zůstává jako doklad.
     */
    public static function leaveExternalId(int $employmentId, int $year, int $generation, string $kind): string
    {
        return "leave:termination:{$employmentId}:{$year}:{$generation}:{$kind}";
    }

    /**
     * @return array{next:int,active:?array{generation:int,kind:string,input:array<string,mixed>}}
     */
    private function leaveInputs(int $supplierId, int $employmentId, int $year): array
    {
        $prefix = "leave:termination:{$employmentId}:{$year}:";
        $generations = [];
        foreach ($this->terminations->liveInputsWithPrefix($supplierId, $employmentId, $prefix) as $externalId => $row) {
            $parts = explode(':', substr($externalId, strlen($prefix)), 2);
            if (count($parts) !== 2 || !ctype_digit($parts[0])) {
                continue;
            }
            $generations[(int) $parts[0]][$parts[1]] = $row;
        }
        ksort($generations);
        $active = null;
        foreach ($generations as $generation => $kinds) {
            if (isset($kinds['reversal'])) {
                continue;
            }
            foreach (['payout', 'overdraft'] as $kind) {
                if (isset($kinds[$kind])) {
                    $active = ['generation' => $generation, 'kind' => $kind, 'input' => $kinds[$kind]];
                }
            }
        }

        return [
            'next' => $generations === [] ? 1 : max(array_keys($generations)) + 1,
            'active' => $active,
        ];
    }

    public static function severanceExternalId(int $employmentId): string
    {
        return "termination:severance:{$employmentId}";
    }

    public static function workInjuryExternalId(int $employmentId): string
    {
        return "termination:work-injury-271ca:{$employmentId}";
    }

    /**
     * Záznam o skončení, pokud je vyplněný, jako hodnota domény.
     */
    public function reason(int $supplierId, int $employmentId): ?PayrollTerminationReason
    {
        $row = $this->terminations->find($supplierId, $employmentId);

        return $row === null
            ? null
            : new PayrollTerminationReason(
                (string) $row['termination_method'],
                (string) $row['legal_ground'],
            );
    }

    /** @return array<string,mixed> */
    public function overview(int $supplierId, int $employmentId): array
    {
        $employment = $this->requireEndedEmployment($supplierId, $employmentId);
        $record = $this->terminations->find($supplierId, $employmentId);
        $reason = $record === null
            ? null
            : new PayrollTerminationReason((string) $record['termination_method'], (string) $record['legal_ground']);
        // Přehled je čtecí GET bez transakce: podmínky úvazku jen čte, zámek
        // si berou až zápisové cesty (odstupné, dovolená, A2) v transactional().
        $average = $this->average($supplierId, $employment, false);
        $blockers = [];
        if ($reason === null) {
            $blockers[] = self::issue('reason_missing', 'blocker');
        }
        if ($average['snapshot'] === null) {
            $blockers[] = self::issue('average_missing', 'blocker', [
                'year' => $average['year'],
                'quarter' => $average['quarter'],
            ]);
        } elseif ($average['error'] !== null) {
            $blockers[] = self::issue('average_conversion_failed', 'blocker', [
                'reason' => $average['error'],
            ]);
        }

        $leave = $this->leaveSettlement($supplierId, $employment, $reason, $average);
        $severance = $this->severance($supplierId, $employment, $record, $reason, $average);
        $death = $reason?->endedByDeath() === true
            ? $this->death($supplierId, $employment, $record, $average)
            : null;

        return [
            'employment' => [
                'id' => $employment['id'],
                'employee_id' => $employment['employee_id'],
                'relation_type' => $employment['relation_type'],
                'status' => $employment['status'],
                'start_date' => $this->startDate($employment),
                'end_date' => $employment['end_date'],
            ],
            'termination' => $record,
            'derived' => $reason === null ? null : [
                'regzec_reason_code' => $reason->regzecReasonCode(),
                'unemployment_office_kind' => $reason->unemploymentOfficeKind(),
                'ended_by_death' => $reason->endedByDeath(),
                'settlement_reportable' => $reason->settlementReportable(),
                'severance_basis' => $reason->severanceBasis(),
                'work_injury_compensation' => $reason->workInjuryCompensation(),
                'stated_reason_allowed' => $reason->statedReasonAllowed(),
            ],
            'average' => [
                'year' => $average['year'],
                'quarter' => $average['quarter'],
                'snapshot_id' => $average['snapshot']['id'] ?? null,
                'hourly_minor' => $average['snapshot']['average_hourly_minor'] ?? null,
                'monthly_gross_minor' => $average['monthly_gross'],
                'monthly_net_minor' => $average['monthly_net'],
            ],
            'leave_settlement' => $leave,
            'severance' => $severance,
            'death' => $death,
            'a2_prefill' => $this->a2Prefill($employment, $reason, $average, $severance),
            // Chybějící průměr blokuje přehled i dovolenou, odstupné a úmrtí;
            // každá sekce ho hlásí sama, souhrn ho ale smí říct jen jednou
            // (dřív se tatáž věta ukázala dvakrát až třikrát).
            'issues' => self::uniqueIssues(array_merge(
                $blockers,
                $leave['issues'],
                $severance['issues'],
                $death['issues'] ?? [],
            )),
            'options' => [
                'methods' => PayrollTerminationReason::METHODS,
                'allowed_grounds' => PayrollTerminationReason::ALLOWED_GROUNDS,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(int $supplierId, int $employmentId, array $input, ?int $userId): array
    {
        $this->requireEndedEmployment($supplierId, $employmentId);
        $method = self::requiredString($input, 'termination_method');
        $ground = is_string($input['legal_ground'] ?? null) && $input['legal_ground'] !== ''
            ? $input['legal_ground']
            : 'none';
        $reason = new PayrollTerminationReason($method, $ground);
        $stated = self::optionalText($input['employee_stated_reason'] ?? null, 1000, 'Důvod uvedený zaměstnancem');
        if ($stated !== null && !$reason->statedReasonAllowed()) {
            throw new \InvalidArgumentException(
                'Důvod uvedený zaměstnancem se vyplňuje jen u výpovědi zaměstnance'
                . ' nebo dohody bez zákonného důvodu.',
            );
        }
        $override = $input['severance_multiple_override'] ?? null;
        $overrideReason = self::optionalText($input['severance_override_reason'] ?? null, 500, 'Důvod přepisu násobku');
        if ($override !== null && $override !== '') {
            if (!is_int($override) && !(is_string($override) && ctype_digit($override))) {
                throw new \InvalidArgumentException('Násobek odstupného musí být celé číslo.');
            }
            $override = (int) $override;
            if ($reason->severanceBasis() === null && !$reason->workInjuryCompensation()) {
                throw new \InvalidArgumentException(
                    'Násobek odstupného lze přepsat jen u skončení, které odstupné zakládá'
                    . ' (výpověď nebo dohoda z organizačních či zdravotních důvodů).',
                );
            }
            if ($overrideReason === null) {
                throw new \InvalidArgumentException(
                    'U přepsaného násobku odstupného uveďte, o co se opírá (kolektivní smlouva, vnitřní předpis, smlouva).',
                );
            }
            if ($override > PayrollSeverancePolicy::MAX_OVERRIDE_MULTIPLE) {
                throw new \InvalidArgumentException('Násobek odstupného je nepřiměřeně vysoký.');
            }
        } else {
            $override = null;
            $overrideReason = null;
        }
        $workingTimeAccount = ($input['working_time_account_applies'] ?? false) === true;
        if ($workingTimeAccount && $reason->severanceBasis() !== 'organizational') {
            throw new \InvalidArgumentException(
                'Navýšení odstupného za konto pracovní doby (§ 67 odst. 1 písm. d) ZP)'
                . ' se týká jen skončení z organizačních důvodů.',
            );
        }
        $employment = $this->requireEndedEmployment($supplierId, $employmentId);
        $otherIncomeFrom = self::optionalDate($input['other_income_from'] ?? null, 'Den nástupu k jinému plátci');
        $otherPayer = ($input['other_payer_applies_protected_amount'] ?? false) === true;
        if ($otherPayer && $otherIncomeFrom === null) {
            throw new \InvalidArgumentException(
                'Potvrzení, že nezabavitelnou částku započítává jiný plátce, se váže'
                . ' ke dni, kdy povinnému vznikl jiný příjem. Vyplňte nejdřív ten den.',
            );
        }
        if ($otherIncomeFrom !== null && $otherIncomeFrom < $this->startDate($employment)) {
            throw new \InvalidArgumentException(
                'Den vzniku jiného příjmu nemůže předcházet začátku tohoto pracovního vztahu.',
            );
        }
        if ($override !== null) {
            $statutory = PayrollSeverancePolicy::statutory(
                $reason,
                $this->startDate($employment),
                (string) $employment['end_date'],
                $this->terminations->previousEmployments($supplierId, (int) $employment['employee_id'], $employmentId),
                $workingTimeAccount,
            );
            if ($override < $statutory['multiple']) {
                throw new \InvalidArgumentException(sprintf(
                    'Kolektivní smlouva ani vnitřní předpis nesmí odstupné snížit pod'
                    . ' zákonné minimum (%d× průměrný měsíční výdělek).',
                    $statutory['multiple'],
                ));
            }
        }
        $expected = $input['row_version'] ?? null;
        $this->terminations->save($supplierId, $employmentId, [
            'termination_method' => $method,
            'legal_ground' => $ground,
            'employee_stated_reason' => $stated,
            'severance_multiple_override' => $override,
            'severance_override_reason' => $overrideReason,
            'working_time_account_applies' => $workingTimeAccount,
            'other_income_from' => $otherIncomeFrom,
            'other_payer_applies_protected_amount' => $otherPayer,
        ], is_int($expected) ? $expected : null, $userId);

        return $this->overview($supplierId, $employmentId);
    }

    /**
     * Proplacení nevyčerpané dovolené (§ 222 odst. 2 a 3 ZP), nebo srážka
     * náhrady za dovolenou, na kterou právo nevzniklo (§ 147 odst. 1 písm. e)
     * ZP), jako vstup běhu za měsíc skončení.
     *
     * Proplacení zapíše do knihy dovolené položku `payout`, aby zůstatek po
     * vyplacení seděl na nulu; oba zápisy jsou v jedné transakci.
     *
     * @return array<string,mixed>
     */
    public function settleLeave(int $supplierId, int $employmentId, ?int $userId): array
    {
        return $this->transactional(function () use ($supplierId, $employmentId, $userId): array {
            $employment = $this->requireEndedEmployment($supplierId, $employmentId);
            $reason = $this->reason($supplierId, $employmentId);
            $average = $this->average($supplierId, $employment);
            $plan = $this->leaveSettlement($supplierId, $employment, $reason, $average);
            if ($plan['state'] === 'settled') {
                return $this->overview($supplierId, $employmentId);
            }
            if (!in_array($plan['state'], ['payout', 'overdraft'], true)) {
                throw new \DomainException(self::leaveBlockMessage($plan));
            }
            $this->components->ensureDefaults($supplierId);
            $period = substr((string) $employment['end_date'], 0, 7) . '-01';
            $componentId = $this->terminations->componentId($supplierId, self::LEAVE_COMPONENT, $period)
                ?? throw new \DomainException('Chybí účinná mzdová složka NAHRADA_MZDY_DOVOLENA.');
            $year = (int) $plan['year'];
            $minutes = abs((int) $plan['minutes']);
            $kind = $plan['state'];
            $trace = CanonicalJson::encode([
                'kind' => 'termination_leave_settlement.v1',
                'employment_id' => $employmentId,
                'leave_year' => $year,
                'end_date' => $employment['end_date'],
                'settlement' => $kind,
                'minutes' => $minutes,
                'average_snapshot_id' => $average['snapshot']['id'] ?? null,
                'average_hourly_minor' => $plan['average_hourly_minor'],
                'amount_minor' => $plan['amount_minor'],
                'rounding' => 'ceil-to-czk-on-total',
                'entitlement_basis' => $kind === 'payout' ? 'zp-222-2+222-3' : 'zp-147-1-e',
                ...(isset($average['hourly_average_floor']) ? ['hourly_average_floor' => [$average['hourly_average_floor']], 'hourly_average_segments' => [['local_date' => $employment['end_date'], 'eligible_minutes' => $minutes]]] : []),
            ]);
            $this->inputs->createApproved($supplierId, [
                'employee_id' => $employment['employee_id'],
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $period,
                'source_period_start' => null,
                'amount_minor' => (int) $plan['amount_minor'],
                'quantity_milliunits' => intdiv(($kind === 'payout' ? $minutes : -$minutes) * 1000, 60),
                'source_kind' => $kind === 'payout' ? 'absence' : 'correction',
                'external_id' => self::leaveExternalId(
                    $employmentId,
                    $year,
                    $this->leaveInputs($supplierId, $employmentId, $year)['next'],
                    $kind,
                ),
                'source_snapshot_json' => $trace,
                'source_snapshot_hash' => hash('sha256', $trace, true),
            ], $userId);
            if ($kind === 'payout') {
                $this->leave->appendManual(
                    $supplierId,
                    $employmentId,
                    $year,
                    (string) $employment['end_date'],
                    'payout',
                    -$minutes,
                    self::LEAVE_PAYOUT_REASON . sprintf(
                        ': %d h %02d min, průměr %s Kč/h.',
                        intdiv($minutes, 60),
                        $minutes % 60,
                        number_format((int) $plan['average_hourly_minor'] / 100, 2, ',', ' '),
                    ),
                    $userId,
                );
            }

            return $this->overview($supplierId, $employmentId);
        });
    }

    /**
     * Zpětvzetí vyrovnání dovolené (omyl, změna data skončení): záporný
     * korekční vstup ve stejném měsíci a storno položky knihy dovolené.
     * Původní vstup se nemaže — schválený vstup je doklad.
     *
     * @return array<string,mixed>
     */
    public function reverseLeaveSettlement(int $supplierId, int $employmentId, ?int $userId): array
    {
        return $this->transactional(function () use ($supplierId, $employmentId, $userId): array {
            $employment = $this->requireEndedEmployment($supplierId, $employmentId);
            $year = (int) substr((string) $employment['end_date'], 0, 4);
            $done = false;
            $active = $this->leaveInputs($supplierId, $employmentId, $year)['active'];
            if ($active !== null) {
                $original = $active['input'];
                $reversalId = self::leaveExternalId($employmentId, $year, $active['generation'], 'reversal');
                $trace = CanonicalJson::encode([
                    'kind' => 'termination_leave_settlement_reversal.v1',
                    'reverses_input_id' => $original['id'],
                    'amount_minor' => -$original['amount_minor'],
                ]);
                $componentId = $this->terminations->componentId(
                    $supplierId,
                    self::LEAVE_COMPONENT,
                    (string) $original['period_start'],
                ) ?? throw new \DomainException('Chybí účinná mzdová složka NAHRADA_MZDY_DOVOLENA.');
                $this->inputs->createApproved($supplierId, [
                    'employee_id' => $employment['employee_id'],
                    'employment_id' => $employmentId,
                    'component_id' => $componentId,
                    'period_start' => (string) $original['period_start'],
                    'source_period_start' => (string) $original['period_start'],
                    'amount_minor' => -$original['amount_minor'],
                    'quantity_milliunits' => $original['quantity_milliunits'] === null
                        ? null
                        : -$original['quantity_milliunits'],
                    'source_kind' => 'correction',
                    'external_id' => $reversalId,
                    'source_snapshot_json' => $trace,
                    'source_snapshot_hash' => hash('sha256', $trace, true),
                ], $userId);
                $done = true;
            }
            $ledgerId = $this->terminations->settlementLedgerEntry(
                $supplierId,
                $employmentId,
                $year,
                self::LEAVE_PAYOUT_REASON,
            );
            if ($ledgerId !== null) {
                $this->terminations->insertLedgerReversal(
                    $supplierId,
                    $ledgerId,
                    'Zpětvzetí proplacení nevyčerpané dovolené při skončení.',
                    $userId,
                );
                $done = true;
            }
            if (!$done) {
                throw new \DomainException('Vyrovnání dovolené při skončení nebylo založené, není co vzít zpět.');
            }

            return $this->overview($supplierId, $employmentId);
        });
    }

    /**
     * Odstupné podle návrhu jako schválený vstup `ODSTUPNE` za měsíc skončení
     * (§ 67 odst. 5 ZP — vyplácí se v nejbližším výplatním termínu po
     * skončení, tedy s mzdou za poslední měsíc).
     *
     * Množství vstupu je počet násobků průměrného výdělku, ze kterých je
     * odstupné odvozené. Podle něj se z odstupného sráží zvlášť po násobcích
     * (§ 299 odst. 4 o. s. ř.). Předvyplní ho návrh podle § 67 ZP; účetní ho
     * může změnit (např. odstupné sjednané v kolektivní smlouvě jako jiný
     * násobek).
     *
     * @return array<string,mixed>
     */
    public function createSeveranceInput(
        int $supplierId,
        int $employmentId,
        ?int $userId,
        ?int $garnishmentMultiple = null,
    ): array {
        if ($garnishmentMultiple !== null
            && ($garnishmentMultiple < 1 || $garnishmentMultiple > SeveranceMultiple::MAX_MULTIPLES)
        ) {
            throw new \InvalidArgumentException(
                'Počet násobků průměrného výdělku pro srážky z odstupného musí být 1 až '
                . SeveranceMultiple::MAX_MULTIPLES . '.',
            );
        }

        return $this->transactional(function () use ($supplierId, $employmentId, $userId, $garnishmentMultiple): array {
            $employment = $this->requireEndedEmployment($supplierId, $employmentId);
            $record = $this->terminations->find($supplierId, $employmentId);
            $reason = $this->reason($supplierId, $employmentId);
            $average = $this->average($supplierId, $employment);
            $plan = $this->severance($supplierId, $employment, $record, $reason, $average);
            if ($plan['state'] === 'created') {
                return $this->overview($supplierId, $employmentId);
            }
            if ($plan['state'] !== 'ready' || $plan['kind'] !== 'severance') {
                throw new \DomainException(
                    $plan['kind'] === 'work_injury_compensation'
                        ? 'Jednorázovou náhradu podle § 271ca ZP založte tlačítkem Založit náhradu —'
                            . ' aplikace se zeptá, kdo ji vyplácí a kdy.'
                        : 'Odstupné teď založit nelze — nejdřív odstraňte překážky uvedené u návrhu.',
                );
            }
            $this->components->ensureDefaults($supplierId);
            $period = substr((string) $employment['end_date'], 0, 7) . '-01';
            $componentId = $this->terminations->componentId($supplierId, self::SEVERANCE_COMPONENT, $period)
                ?? throw new \DomainException('Chybí účinná mzdová složka ODSTUPNE.');
            $createdInput = $this->inputs->createApproved($supplierId, [
                'employee_id' => $employment['employee_id'],
                'employment_id' => $employmentId,
                'component_id' => $componentId,
                'period_start' => $period,
                'source_period_start' => null,
                'amount_minor' => (int) $plan['amount_minor'],
                'quantity_milliunits' => ($garnishmentMultiple ?? (int) $plan['multiple']) * 1000,
                'source_kind' => 'manual',
                'external_id' => self::severanceExternalId($employmentId),
            ], $userId, false, false, true);
            $this->writeFloorProof($supplierId, $employment, $createdInput, $average, $plan);

            return $this->overview($supplierId, $employmentId);
        });
    }

    /**
     * Jednorázová náhrada při skončení pracovního poměru (§ 271ca ZP).
     *
     * Náhrada 12× průměrného výdělku náleží při výpovědi podle § 52 písm. d)
     * ZP nebo dohodě z téhož důvodu (pracovní úraz, nemoc z povolání).
     * Kdo ji vyplácí, aplikace z dat nepozná: kryje ji zákonné pojištění
     * odpovědnosti zaměstnavatele a pojišťovna platí buď přímo zaměstnanci,
     * nebo zaměstnavateli refunduje. Proto se ptá účetní:
     *
     *  - vyplácí POJIŠŤOVNA → mzdový vstup nevzniká (zdvojil by výplatu);
     *    zůstane záznam pro odhlášku A2 (10530) a JMHZ ji registruje
     *    pojišťovna (Pravidla podání JMHZ, kap. 6.2.2);
     *  - vyplácí ZAMĚSTNAVATEL v den skončení (jen na základě písemné dohody,
     *    § 271ca odst. 2 ZP) nebo v měsíci skončení → vstup do posledního
     *    měsíce vztahu;
     *  - vyplácí ZAMĚSTNAVATEL až v některém z dalších měsíců (nejbližší
     *    výplatní termín po skončení, případně po vydání posudku podle
     *    § 271ca odst. 3) → vstup do měsíce výplaty jako odložený příjem
     *    typu 1 (JMHZ scénář 8), který se rovnou potvrdí.
     *
     * Náhrada se zdaňuje jako příjem ze závislé činnosti a nepodléhá pojistnému;
     * viz složka NAHRADA_271CA v {@see \MyInvoice\Service\Payroll\Component\PayrollComponentDefaults}.
     * Množství vstupu je 12 násobků — srážky se z ní počítají po násobcích
     * jako z odstupného (§ 299 odst. 1 písm. g) a odst. 4 o. s. ř.).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createWorkInjuryCompensation(int $supplierId, int $employmentId, array $input, ?int $userId): array
    {
        $payer = $input['payer'] ?? null;
        if (!in_array($payer, ['employer', 'insurer'], true)) {
            throw new \InvalidArgumentException(
                'Uveďte, kdo náhradu vyplácí: zaměstnavatel, nebo přímo pojišťovna.',
            );
        }
        $paidOn = self::optionalDate($input['paid_on'] ?? null, 'Den výplaty náhrady')
            ?? throw new \InvalidArgumentException('Vyplňte den výplaty náhrady.');

        return $this->transactional(function () use ($supplierId, $employmentId, $userId, $payer, $paidOn): array {
            $employment = $this->requireEndedEmployment($supplierId, $employmentId);
            $record = $this->terminations->find($supplierId, $employmentId);
            $reason = $this->reason($supplierId, $employmentId);
            $average = $this->average($supplierId, $employment);
            $plan = $this->severance($supplierId, $employment, $record, $reason, $average);
            if ($plan['kind'] !== 'work_injury_compensation') {
                throw new \DomainException(
                    'Jednorázová náhrada podle § 271ca ZP náleží jen při výpovědi nebo dohodě'
                    . ' pro pracovní úraz nebo nemoc z povolání (§ 52 písm. d) ZP).'
                    . ' Zkontrolujte způsob a důvod skončení v této kartě.',
                );
            }
            if (in_array($plan['state'], ['created', 'insurer'], true)) {
                throw new \DomainException('Jednorázová náhrada je už založená.');
            }
            if ($plan['state'] !== 'ready') {
                throw new \DomainException(
                    'Náhradu teď založit nelze — nejdřív odstraňte překážky uvedené u návrhu'
                    . ' (typicky chybějící průměrný výdělek).',
                );
            }
            $endDate = (string) $employment['end_date'];
            if ($paidOn < $endDate) {
                throw new \InvalidArgumentException(
                    'Náhrada vzniká skončením pracovního poměru — den výplaty nemůže'
                    . ' předcházet dni skončení (§ 271ca odst. 2 ZP).',
                );
            }
            if ($payer === 'employer') {
                $endMonth = substr($endDate, 0, 7);
                $paidMonth = substr($paidOn, 0, 7);
                $period = ($paidMonth === $endMonth ? $endMonth : $paidMonth) . '-01';
                if ($paidMonth !== $endMonth) {
                    // Výplata po měsíci skončení = odložený příjem (JMHZ
                    // scénář 8, typ 1). Potvrzení se zapíše rovnou, jinak by ho
                    // výpočet pojistného odmítl a účetní by musela dohledávat,
                    // kde se potvrzuje.
                    $this->deferredIncomes->save(
                        $supplierId,
                        $employmentId,
                        $period,
                        '1',
                        'Jednorázová náhrada podle § 271ca ZP vyplacená po skončení pracovního poměru.',
                        $userId,
                    );
                }
                $this->components->ensureDefaults($supplierId);
                $componentId = $this->terminations->componentId($supplierId, self::WORK_INJURY_COMPONENT, $period)
                    ?? throw new \DomainException('Chybí účinná mzdová složka NAHRADA_271CA.');
                $createdInput = $this->inputs->createApproved($supplierId, [
                    'employee_id' => $employment['employee_id'],
                    'employment_id' => $employmentId,
                    'component_id' => $componentId,
                    'period_start' => $period,
                    'source_period_start' => null,
                    'amount_minor' => (int) $plan['amount_minor'],
                    'quantity_milliunits' => (int) $plan['multiple'] * 1000,
                    'source_kind' => 'manual',
                    'external_id' => self::workInjuryExternalId($employmentId),
                ], $userId, false, false, true);
                $this->writeFloorProof($supplierId, $employment, $createdInput, $average, $plan);
            }
            $this->terminations->saveWorkInjuryCompensation(
                $supplierId,
                $employmentId,
                $payer,
                $paidOn,
                $userId,
            );

            return $this->overview($supplierId, $employmentId);
        });
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function addSurvivor(int $supplierId, int $employmentId, array $input, ?int $userId): array
    {
        $this->requireDeath($supplierId, $employmentId);
        $relationship = self::requiredString($input, 'relationship');
        if (!in_array($relationship, ['spouse_partner', 'child', 'parent'], true)) {
            throw new \InvalidArgumentException(
                'Nárok podle § 328 ZP mají jen manžel nebo partner, děti a rodiče.',
            );
        }
        if (!is_bool($input['shared_household'] ?? null)) {
            throw new \InvalidArgumentException(
                'Uveďte, zda osoba žila se zaměstnancem v době smrti ve společné domácnosti.',
            );
        }
        $name = self::optionalText($input['full_name'] ?? null, 255, 'Jméno osoby blízké')
            ?? throw new \InvalidArgumentException('Jméno osoby blízké je povinné.');
        $this->terminations->addSurvivor($supplierId, $employmentId, [
            'full_name' => $name,
            'relationship' => $relationship,
            'shared_household' => $input['shared_household'],
            'bank_account' => self::optionalText($input['bank_account'] ?? null, 64, 'Bankovní účet'),
            'note' => self::optionalText($input['note'] ?? null, 500, 'Poznámka'),
        ], $userId);

        return $this->overview($supplierId, $employmentId);
    }

    /** @return array<string,mixed> */
    public function removeSurvivor(int $supplierId, int $employmentId, int $survivorId): array
    {
        $this->requireDeath($supplierId, $employmentId);
        if (!$this->terminations->deleteSurvivor($supplierId, $employmentId, $survivorId)) {
            throw new \OutOfBoundsException('Osoba blízká nebyla nalezena.');
        }

        return $this->overview($supplierId, $employmentId);
    }

    /**
     * Ruční daňové posouzení výplaty pozůstalým — aplikace zdanění nároků
     * přešlých podle § 328 ZP sama neurčuje (viz {@see self::death()}),
     * účetní ho potvrdí vlastním záznamem.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function assessDeathTax(int $supplierId, int $employmentId, array $input, ?int $userId): array
    {
        $record = $this->requireDeath($supplierId, $employmentId);
        $assessment = self::optionalText($input['assessment'] ?? null, 1000, 'Daňové posouzení')
            ?? throw new \InvalidArgumentException('Popište, jak se výplata pozůstalým zdaní a z jakého podkladu.');
        $expected = $input['row_version'] ?? null;
        $this->terminations->assessDeathTax(
            $supplierId,
            $employmentId,
            $assessment,
            is_int($expected) ? $expected : (int) $record['row_version'],
            $userId,
        );

        return $this->overview($supplierId, $employmentId);
    }

    /**
     * @param array<string,mixed> $employment
     * @param array{year:int,quarter:int,snapshot:?array<string,mixed>,monthly_gross:?int,monthly_net:?int,error:?string,net_error?:string} $average
     * @return array<string,mixed>
     */
    private function leaveSettlement(
        int $supplierId,
        array $employment,
        ?PayrollTerminationReason $reason,
        array $average,
    ): array {
        $employmentId = (int) $employment['id'];
        $year = (int) substr((string) $employment['end_date'], 0, 4);
        $issues = [];
        $result = [
            'year' => $year,
            'state' => 'nothing',
            'minutes' => 0,
            'balance_minutes' => 0,
            'average_hourly_minor' => $average['snapshot']['average_hourly_minor'] ?? null,
            'amount_minor' => 0,
            'input' => null,
            'issues' => [],
        ];
        $active = $this->leaveInputs($supplierId, $employmentId, $year)['active'];
        if ($active !== null) {
            $input = $active['input'];
            $result['state'] = 'settled';
            $result['settlement'] = $active['kind'];
            $result['input'] = $input;
            $result['amount_minor'] = $input['amount_minor'];
            $result['minutes'] = intdiv(abs((int) ($input['quantity_milliunits'] ?? 0)) * 60, 1000);

            return $result;
        }
        $balance = $this->leave->balance($supplierId, $employmentId, $year);
        $result['balance_minutes'] = $balance;
        if (!$this->terminations->hasLeaveEntitlement($supplierId, $employmentId, $year)) {
            $result['state'] = 'blocked';
            $issues[] = self::issue('leave_entitlement_missing', 'blocker', ['year' => $year]);
            $result['issues'] = $issues;

            return $result;
        }
        $previous = $this->leave->balance($supplierId, $employmentId, $year - 1);
        if ($previous > 0 && !$this->hasCarryover($supplierId, $employmentId, $year)) {
            $issues[] = self::issue('leave_previous_year_balance', 'warning', [
                'year' => $year - 1,
                'minutes' => $previous,
            ]);
        }
        if ($balance === 0) {
            $result['issues'] = $issues;

            return $result;
        }
        $hourly = $average['snapshot']['average_hourly_minor'] ?? null;
        $supported = ($average['snapshot']['support_status'] ?? null) === 'supported';
        if (!is_int($hourly) || $hourly <= 0 || !$supported) {
            $result['state'] = 'blocked';
            $result['minutes'] = $balance;
            $issues[] = self::issue(
                $average['snapshot'] === null ? 'average_missing' : 'average_not_supported',
                'blocker',
                ['year' => $average['year'], 'quarter' => $average['quarter']],
            );
            $result['issues'] = $issues;

            return $result;
        }
        $amount = LeaveCompensationCalculator::calculateMinutes(
            $hourly,
            abs($balance),
            100,
            PayrollWageRoundingPolicy::forSupplier($this->db, $supplierId),
        );
        if ($balance > 0) {
            $result['state'] = 'payout';
            $result['minutes'] = $balance;
            $result['amount_minor'] = $amount;
            $result['issues'] = $issues;

            return $result;
        }
        $result['minutes'] = $balance;
        $result['amount_minor'] = -$amount;
        if ($reason?->endedByDeath() === true) {
            // § 328 odst. 2 ZP: peněžitá práva zaměstnavatele smrtí zaměstnance
            // zanikají, s výjimkou pravomocně přiznaných nebo písemně uznaných.
            $result['state'] = 'not_recoverable';
            $issues[] = self::issue('leave_overdraft_death', 'info');
        } else {
            $result['state'] = 'overdraft';
            $issues[] = self::issue('leave_overdraft_limit', 'warning');
        }
        $result['issues'] = $issues;

        return $result;
    }

    private function hasCarryover(int $supplierId, int $employmentId, int $year): bool
    {
        foreach ($this->leave->list($supplierId, $employmentId, $year) as $row) {
            if (($row['entry_type'] ?? null) === 'carryover') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $record
     * @param array{year:int,quarter:int,snapshot:?array<string,mixed>,monthly_gross:?int,monthly_net:?int,error:?string,net_error?:string} $average
     * @return array<string,mixed>
     */
    private function severance(
        int $supplierId,
        array $employment,
        ?array $record,
        ?PayrollTerminationReason $reason,
        array $average,
    ): array {
        $employmentId = (int) $employment['id'];
        $result = [
            'state' => 'not_applicable',
            'kind' => null,
            'statutory_multiple' => 0,
            'multiple' => 0,
            'rule' => 'none',
            'tenure_start' => null,
            'counted_previous' => [],
            'monthly_average_minor' => $average['monthly_gross'],
            'amount_minor' => 0,
            'garnishment_multiple' => null,
            'garnishment_period_to' => null,
            'work_injury_payer' => null,
            'work_injury_paid_on' => null,
            'input' => null,
            'issues' => [],
        ];
        if ($reason === null) {
            $result['state'] = 'reason_missing';

            return $result;
        }
        if ($reason->severanceBasis() === null && !$reason->workInjuryCompensation()) {
            return $result;
        }
        if (!in_array((string) $employment['relation_type'], self::SEVERANCE_RELATIONS, true)) {
            $result['issues'][] = self::issue('severance_relation_not_applicable', 'info');

            return $result;
        }
        $statutory = PayrollSeverancePolicy::statutory(
            $reason,
            $this->startDate($employment),
            (string) $employment['end_date'],
            $this->terminations->previousEmployments($supplierId, (int) $employment['employee_id'], $employmentId),
            (bool) ($record['working_time_account_applies'] ?? false),
        );
        $override = $record['severance_multiple_override'] ?? null;
        $multiple = is_int($override) ? max($override, $statutory['multiple']) : $statutory['multiple'];
        $result = array_merge($result, [
            'kind' => $reason->workInjuryCompensation() ? 'work_injury_compensation' : 'severance',
            'statutory_multiple' => $statutory['multiple'],
            'multiple' => $multiple,
            'rule' => $statutory['rule'],
            'tenure_start' => $statutory['tenure_start'],
            'counted_previous' => $statutory['counted_previous'],
            'override_reason' => $record['severance_override_reason'] ?? null,
        ]);
        $result['work_injury_payer'] = $record['work_injury_compensation_payer'] ?? null;
        $result['work_injury_paid_on'] = $record['work_injury_compensation_paid_on'] ?? null;
        if ($average['monthly_gross'] === null) {
            $result['state'] = 'blocked';
            $result['issues'][] = self::issue(
                $average['snapshot'] === null ? 'average_missing' : 'average_conversion_failed',
                'blocker',
                ['year' => $average['year'], 'quarter' => $average['quarter'], 'reason' => $average['error']],
            );

            return $result;
        }
        // Odstupné ani náhrada § 271ca (obě druhu severance) do rozpadu mzdy
        // v JMHZ nepatří a zařazení nepotřebují
        // (JmhzComponentSourceRule::TAXED_KINDS_OUTSIDE_WAGE_BREAKDOWN).
        $result['amount_minor'] = PayrollSeverancePolicy::amount($multiple, $average['monthly_gross']);
        $existing = $this->terminations->liveInput(
            $supplierId,
            $employmentId,
            'manual',
            $result['kind'] === 'severance'
                ? self::severanceExternalId($employmentId)
                : self::workInjuryExternalId($employmentId),
        );
        if ($existing === null && $result['work_injury_payer'] === 'insurer') {
            // Náhradu vyplácí pojišťovna přímo: mzdový vstup nevzniká, v A2
            // se uvede jako jednorázová náhrada (10530).
            $result['state'] = 'insurer';

            return $result;
        }
        $quantity = $existing['quantity_milliunits'] ?? null;
        $garnishmentMultiple = $existing === null
            ? $multiple
            : (is_int($quantity) && $quantity > 0 && $quantity % 1000 === 0 ? intdiv($quantity, 1000) : null);
        $result['garnishment_multiple'] = $garnishmentMultiple;
        $result['garnishment_period_to'] = $garnishmentMultiple === null
            ? null
            : SeveranceMultiple::periodEnd((string) $employment['end_date'], $garnishmentMultiple);
        if ($existing !== null && $garnishmentMultiple === null) {
            $result['issues'][] = self::issue('severance_garnishment_multiple_missing', 'warning');
        }
        if ($existing !== null) {
            $result['state'] = 'created';
            $result['input'] = $existing;
            if ($existing['amount_minor'] !== $result['amount_minor']) {
                $result['issues'][] = self::issue('severance_input_differs', 'warning', [
                    'input_amount_minor' => $existing['amount_minor'],
                ]);
            }

            return $result;
        }
        $result['state'] = 'ready';
        if ($result['kind'] === 'work_injury_compensation') {
            $result['issues'][] = self::issue('work_injury_compensation_ready', 'info');
        }

        return $result;
    }

    /**
     * Úmrtí zaměstnance (§ 328 ZP).
     *
     * Kdo nárok nabývá: POSTUPNĚ manžel nebo partner, děti, rodiče — jen ti,
     * kdo se zaměstnancem v době smrti žili ve společné domácnosti. Nárok nabude
     * první neprázdná skupina v tomto pořadí; uvnitř skupiny rovným dílem.
     * Horní mez je trojnásobek průměrného měsíčního výdělku, zbytek (a vše,
     * není-li oprávněné osoby) se stává předmětem dědictví.
     *
     * Zdanění: zákon o daních z příjmů výplatu nároků přešlých podle § 328 ZP
     * výslovně neupravuje a aplikace ho neodhaduje. Vyžaduje se ruční
     * daňové posouzení účetní (zaznamenané u skončení); dokud chybí, karta
     * hlásí překážku.
     *
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $record
     * @param array{year:int,quarter:int,snapshot:?array<string,mixed>,monthly_gross:?int,monthly_net:?int,error:?string,net_error?:string} $average
     * @return array<string,mixed>
     */
    private function death(int $supplierId, array $employment, ?array $record, array $average): array
    {
        $employmentId = (int) $employment['id'];
        $survivors = $this->terminations->survivors($supplierId, $employmentId);
        $limit = $average['monthly_gross'] === null ? null : 3 * $average['monthly_gross'];
        $entitledGroup = null;
        foreach (['spouse_partner', 'child', 'parent'] as $group) {
            foreach ($survivors as $survivor) {
                if ($survivor['relationship'] === $group && $survivor['shared_household']) {
                    $entitledGroup = $group;
                    break 2;
                }
            }
        }
        $entitledCount = 0;
        foreach ($survivors as $survivor) {
            if ($survivor['relationship'] === $entitledGroup && $survivor['shared_household']) {
                ++$entitledCount;
            }
        }
        $rows = [];
        foreach ($survivors as $survivor) {
            $entitled = $entitledGroup !== null
                && $survivor['relationship'] === $entitledGroup
                && $survivor['shared_household'];
            $rows[] = $survivor + [
                'entitled' => $entitled,
                'share_basis_points' => $entitled ? intdiv(10000, max(1, $entitledCount)) : 0,
                'limit_share_minor' => $entitled && $limit !== null
                    ? intdiv($limit, max(1, $entitledCount))
                    : 0,
            ];
        }
        $issues = [];
        if ($entitledGroup === null) {
            $issues[] = self::issue('death_no_entitled_survivor', 'warning');
        }
        if ($limit === null) {
            $issues[] = self::issue('average_missing', 'blocker', [
                'year' => $average['year'],
                'quarter' => $average['quarter'],
            ]);
        }
        if (($record['death_tax_assessment'] ?? null) === null) {
            $issues[] = self::issue('death_tax_assessment_missing', 'blocker');
        }
        $deductions = $this->terminations->activeDeductions($supplierId, (int) $employment['employee_id']);
        if ($deductions['enforcement_cases'] > 0) {
            $issues[] = self::issue('death_enforcement_active', 'blocker', ['count' => $deductions['enforcement_cases']]);
        }
        if ($deductions['deduction_agreements'] > 0) {
            $issues[] = self::issue('death_deduction_agreements_active', 'blocker', ['count' => $deductions['deduction_agreements']]);
        }

        return [
            'limit_minor' => $limit,
            'entitled_group' => $entitledGroup,
            'survivors' => $rows,
            'active_deductions' => $deductions,
            'tax_assessment' => $record['death_tax_assessment'] ?? null,
            'tax_assessed_at' => $record['death_tax_assessed_at'] ?? null,
            'issues' => $issues,
        ];
    }

    /**
     * Předvyplnění podkladů pro Úřad práce v odhlášce A2 ze záznamu
     * o skončení. Formulář A2 hodnoty jen nabídne — schvaluje je účetní —
     * a schválení pak hlídá, že kód důvodu sedí na záznam.
     *
     * @param array<string,mixed> $employment
     * @param array{year:int,quarter:int,snapshot:?array<string,mixed>,monthly_gross:?int,monthly_net:?int,error:?string,net_error?:string} $average
     * @param array<string,mixed> $severance
     * @return array<string,mixed>|null
     */
    private function a2Prefill(
        array $employment,
        ?PayrollTerminationReason $reason,
        array $average,
        array $severance,
    ): ?array {
        if ($reason === null) {
            return null;
        }
        if ($reason->endedByDeath()) {
            return ['ended_by_death' => true, 'unemployment' => null];
        }
        $unemployment = [
            'mode' => 'provided',
            'employment_type' => '1',
            'termination_reason' => $reason->regzecReasonCode(),
            'average_net_earnings' => $average['monthly_net'] === null
                ? null
                : (string) intdiv($average['monthly_net'], 100),
        ];
        if ($reason->settlementReportable()) {
            // Nárok (10378) plyne ze záznamu o skončení, ne z toho, jestli se
            // už podařilo spočítat částku: chybějící průměr dřív udělal
            // z „náleží" „nenáleží". Do 10531/10530 jde zúčtovaná částka
            // (vstup posledního běhu), bez něj návrh podle průměru.
            $kind = $reason->a2SettlementKind((string) $employment['relation_type']);
            $unemployment['entitlement'] = $kind !== null;
            if ($kind !== null) {
                $unemployment['settlement_kind'] = $kind;
                $amount = is_array($severance['input'] ?? null)
                    ? (int) $severance['input']['amount_minor']
                    : (int) $severance['amount_minor'];
                if ($amount > 0) {
                    $unemployment['settlement_amount'] = (string) intdiv($amount, 100);
                }
            }
        }

        return [
            'ended_by_death' => false,
            'average_net_note' => $average['monthly_net'] === null
                ? ($average['net_error'] ?? ($average['snapshot'] === null
                    ? 'Chybí schválený průměrný výdělek pro čtvrtletí skončení.'
                    : $average['error']))
                : null,
            'unemployment' => $unemployment,
        ];
    }

    /**
     * @param array<string,mixed> $employment
     * @return array{year:int,quarter:int,snapshot:?array<string,mixed>,monthly_gross:?int,monthly_net:?int,error:?string,net_error?:string}
     */
    /** @param array<string,mixed> $employment @param array<string,mixed> $input @param array<string,mixed> $average @param array<string,mixed> $plan */
    private function writeFloorProof(int $supplierId, array $employment, array $input, array $average, array $plan): void
    {
        if (!isset($average['hourly_average_floor'])) return;
        $snapshot = CanonicalJson::encode(['kind' => 'termination_hourly_average.v1', 'end_date' => $employment['end_date'],
            'hourly_average_floor' => $average['hourly_average_floor'], 'average_snapshot_id' => $average['snapshot']['id'],
            'monthly_average_minor' => $average['monthly_gross'], 'amount_minor' => $plan['amount_minor'], 'multiple' => $plan['multiple']]);
        $this->db->pdo()->prepare('INSERT INTO payroll_termination_floor_proofs (supplier_id, employment_id, input_id, period_start, source_snapshot_json, source_snapshot_hash) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$supplierId, $employment['id'], $input['id'], $input['period_start'], $snapshot, hash('sha256', $snapshot, true)]);
    }

    private function average(int $supplierId, array $employment, bool $lockTerms = true): array
    {
        $end = (string) $employment['end_date'];
        $year = (int) substr($end, 0, 4);
        $quarter = intdiv((int) substr($end, 5, 2) - 1, 3) + 1;
        $snapshot = $this->averages->findApproved($supplierId, (int) $employment['id'], $year, $quarter);
        $result = [
            'year' => $year,
            'quarter' => $quarter,
            'snapshot' => $snapshot,
            'monthly_gross' => null,
            'monthly_net' => null,
            'error' => null,
        ];
        if ($snapshot === null) {
            return $result;
        }
        try {
            $raw = $snapshot['average_hourly_minor'] ?? null;
            if (is_int($raw) && $raw > 0) {
                $intervals = (new PayrollHourlyAverageFloorResolver($this->db))->forMany($supplierId, [(int) $employment['id']], $end, $end, $lockTerms)[(int) $employment['id']] ?? [];
                $applied = PayrollHourlyAverageFloorResolver::apply($intervals, $end, $raw);
                if ($applied['hourly_proof'] !== null) {
                    $snapshot['average_hourly_minor'] = $applied['hourly_minor'];
                    $result['snapshot'] = $snapshot;
                    $result['hourly_average_floor'] = $applied['hourly_proof'];
                }
            }
        } catch (\InvalidArgumentException $e) {
            $result['snapshot']['support_status'] = 'unsupported';
            $result['error'] = $e->getMessage();
            return $result;
        }
        try {
            $gross = $this->converter->convert(
                $supplierId,
                (int) $employment['employee_id'],
                (int) $employment['id'],
                $snapshot,
                $end,
                false,
                $lockTerms,
            );
            $result['monthly_gross'] = $gross->grossMonthlyMinorUnits;
        } catch (EmploymentExitReadinessException|PayrollRulesetException|\DomainException|\InvalidArgumentException $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }
        try {
            $net = $this->converter->convert(
                $supplierId,
                (int) $employment['employee_id'],
                (int) $employment['id'],
                $snapshot,
                $this->today(),
                true,
                $lockTerms,
            );
            $result['monthly_net'] = $net->netMonthlyMinorUnits;
        } catch (EmploymentExitReadinessException|PayrollRulesetException|\DomainException|\InvalidArgumentException $e) {
            // Čistý průměr jen předvyplňuje A2; bez něj zůstane pole prázdné,
            // ale formulář musí říct proč (tlačítko slibuje „čistý průměr").
            $result['net_error'] = $e->getMessage();
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function requireEndedEmployment(int $supplierId, int $employmentId): array
    {
        $employment = $this->terminations->employment($supplierId, $employmentId)
            ?? throw new \OutOfBoundsException('Pracovní vztah nebyl nalezen.');
        if (!in_array($employment['status'], ['ended', 'archived'], true) || $employment['end_date'] === null) {
            throw new \DomainException(
                'Skončení lze vyplnit až u ukončeného vztahu. Nejdřív vztah ukončete'
                . ' na jeho kartě akcí Ukončit s datem skončení.',
            );
        }

        return $employment;
    }

    /** @return array<string,mixed> */
    private function requireDeath(int $supplierId, int $employmentId): array
    {
        $this->requireEndedEmployment($supplierId, $employmentId);
        $record = $this->terminations->find($supplierId, $employmentId);
        if ($record === null || $record['termination_method'] !== 'death') {
            throw new \DomainException('Osoby blízké a daňové posouzení se vedou jen u skončení úmrtím.');
        }

        return $record;
    }

    /** @param array<string,mixed> $employment */
    private function startDate(array $employment): string
    {
        return (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? $employment['end_date']);
    }

    private function today(): string
    {
        $value = $this->db->pdo()->query('SELECT DATE(NOW())')?->fetchColumn();

        return is_string($value) ? $value : (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** @param array<string,mixed> $plan */
    private static function leaveBlockMessage(array $plan): string
    {
        return match ($plan['state']) {
            'nothing' => 'Zůstatek dovolené k datu skončení je nulový, není co proplatit ani srazit.',
            'not_recoverable' => 'Náhradu za přečerpanou dovolenou po úmrtí zaměstnance srazit nelze'
                . ' (§ 328 odst. 2 ZP — peněžitá práva zaměstnavatele smrtí zanikají).',
            default => 'Vyrovnání dovolené teď založit nelze — nejdřív odstraňte překážky uvedené u dovolené.',
        };
    }

    /**
     * @param array<string,mixed> $params
     * @return array{code:string,severity:string,params:array<string,mixed>}
     */
    /**
     * @param list<array{code:string,severity:string,params:array<string,mixed>}> $issues
     * @return list<array{code:string,severity:string,params:array<string,mixed>}>
     */
    private static function uniqueIssues(array $issues): array
    {
        $seen = [];
        $unique = [];
        foreach ($issues as $issue) {
            if (isset($seen[$issue['code']])) {
                continue;
            }
            $seen[$issue['code']] = true;
            $unique[] = $issue;
        }

        return $unique;
    }

    private static function issue(string $code, string $severity, array $params = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'params' => $params];
    }

    /** @param array<string,mixed> $input */
    private static function requiredString(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Pole {$key} je povinné.");
        }

        return $value;
    }

    private static function optionalDate(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("{$label} musí být datum ve tvaru RRRR-MM-DD.");
        }

        return $value;
    }

    private static function optionalText(mixed $value, int $max, string $label): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException("{$label} musí být text.");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new \InvalidArgumentException("{$label} smí mít nejvýše {$max} znaků.");
        }

        return $value;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_termination');
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_termination');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_termination');
                $pdo->exec('RELEASE SAVEPOINT payroll_termination');
            }
            throw $e;
        }
    }
}
