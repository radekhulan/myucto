<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Calculation\Money;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;

final class PayrollInputPreviewService
{
    public function __construct(
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollComponentDefinitionFactory $factory,
        private readonly PayrollMealShiftEvidenceService $mealEvidence,
        private readonly Connection $db,
        private readonly PayrollDeferredIncomeRepository $deferredIncomes,
    ) {}

    /**
     * @param array{
     *   employee_id:int,
     *   employment_id:int,
     *   component_id:int,
     *   period_start:string,
     *   amount_minor:int
     * } $input
     * @return array<string,mixed>
     */
    public function preview(int $supplierId, array $input): array
    {
        $this->inputs->assertValidReferences($supplierId, $input);
        $component = $this->components->find(
            $supplierId,
            $input['component_id'],
        );
        if ($component === null || !$component['is_active']) {
            throw new \InvalidArgumentException('Mzdová složka není aktivní.');
        }
        if (PayrollTimeValue::string(
            $component['valid_from'] ?? null,
            'valid_from',
        ) > $input['period_start']
            || (
                ($component['valid_to'] ?? null) !== null
                && PayrollTimeValue::string(
                    $component['valid_to'],
                    'valid_to',
                ) < $input['period_start']
            )
        ) {
            throw new \InvalidArgumentException(
                'Mzdová složka není v zadaném měsíci účinná.'
            );
        }

        $definition = $this->factory->fromArray($component);
        try {
            $impact = $definition->impact(new Money($input['amount_minor']));
            $support = 'supported';
            $blocker = null;
        } catch (\DomainException $e) {
            $impact = null;
            $support = 'manual_review';
            $blocker = $e->getMessage();
        }

        $limit = $definition->annualLimitMinor;
        $used = $definition->kind->isBenefit()
            ? $this->inputs->annualBenefitTotal(
                $supplierId,
                $input['employee_id'],
                $input['component_id'],
                (int) substr($input['period_start'], 0, 4),
            )
            : 0;
        $after = $used + max(0, $input['amount_minor']);

        $basket = null;
        $mealEntitlement = null;
        try {
            $mealEntitlement = $definition->exemptionBasket?->scalesWithShifts() === true
                ? $this->mealEvidence->forPeriod(
                    $supplierId,
                    $input['employee_id'],
                    $input['period_start'],
                )
                : null;
            if ($mealEntitlement !== null && !$mealEntitlement->complete) {
                $support = 'manual_review';
                $blocker = 'Chybí úplný podklad pro nárok na příspěvek na stravování: '
                    . implode(', ', $mealEntitlement->missing) . '.';
            } else {
                $basket = $this->basketSplit(
                    $supplierId,
                    $definition,
                    $input,
                    $mealEntitlement,
                );
            }
        } catch (PayrollRulesetException $e) {
            $support = 'manual_review';
            $blocker = $e->getMessage();
        }

        return [
            'support_status' => $support,
            'blocker' => $blocker,
            'component_snapshot' => $definition->snapshot(),
            'impact' => $impact?->jsonSerialize(),
            'annual_limit_minor' => $limit,
            'annual_used_minor' => $used,
            'annual_after_minor' => $after,
            'annual_limit_exceeded' => $limit !== null && $after > $limit,
            'exemption_basket' => $basket,
            'meal_entitlement' => $mealEntitlement?->jsonSerialize(),
            'employment_warning' => $this->employmentPeriodWarning($supplierId, $input),
        ];
    }

    /**
     * Varování, že vztah v měsíci vstupu neplatí (skončil před ním nebo
     * začne až po něm).
     *
     * Případ z podpory: účetní zadala květnový vstup na dubnovou, už skončenou
     * dohodu (výběr vztahu ji nerozlišil od nové) a náhled hlásil „lze
     * bezpečně zpracovat". Kontrola běhu to pak zastavila nálezem
     * `input_after_employment_end` ({@see \MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder::inputsAfterEmploymentEndValidations()}),
     * a kdyby běh vstup mezitím uzamkl, cesta zpět byla dlouhá. Uložení se
     * nezakazuje: doplatek po skončení je legitimní, s potvrzeným odloženým
     * příjmem za měsíc se varování neukáže (stejné pravidlo jako v kontrole).
     *
     * @param array{employment_id:int, period_start:string, ...} $input
     * @return array{code:string,message:string,start_date:?string,end_date:?string,status:string}|null
     */
    private function employmentPeriodWarning(int $supplierId, array $input): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT code, status, start_date, actual_start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $input['employment_id']]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $periodStart = substr($input['period_start'], 0, 10);
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $start = $row['actual_start_date'] ?? $row['start_date'];
        $end = $row['end_date'];
        $period = substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4);
        $code = (string) $row['code'];
        $describe = static fn (?string $date): string => $date === null
            ? '?'
            : (new \DateTimeImmutable($date))->format('j. n. Y');
        $warning = null;
        if (is_string($end) && $end < $periodStart) {
            if (isset($this->deferredIncomes->forPeriod($supplierId, $periodStart)[$input['employment_id']])) {
                return null;
            }
            $warning = [
                'code' => 'employment_ended_before_period',
                'message' => sprintf(
                    'Pracovní vztah %s skončil %s, v %s už neplatí. Mzdový běh vstup zastaví. '
                        . 'Zadejte vstup na trvající vztah; jde-li o doplatek po skončení, potvrďte '
                        . 'nejdřív na kartě vztahu odložený příjem.',
                    $code,
                    $describe($end),
                    $period,
                ),
            ];
        } elseif (is_string($start) && $start > $periodEnd) {
            $warning = [
                'code' => 'employment_starts_after_period',
                'message' => sprintf(
                    'Pracovní vztah %s začíná až %s, v %s ještě neplatí. Do mzdového běhu za %s '
                        . 'nevstoupí; zadejte vstup za měsíc, kdy vztah trvá.',
                    $code,
                    $describe($start),
                    $period,
                    $period,
                ),
            ];
        }

        return $warning === null ? null : $warning + [
            'start_date' => is_string($start) ? $start : null,
            'end_date' => is_string($end) ? $end : null,
            'status' => (string) $row['status'],
        ];
    }

    /**
     * Čerpání zákonného koše osvobození, kdyby se vstup schválil teď.
     *
     * Bez tohohle náhledu je koš past: účetní zjistí překročení až tehdy, když
     * z prosincového benefitu vyskočí daň a pojistné. Vrací se i pro plnění,
     * které se do koše ještě vejde — smysl má vidět zbytek, ne jen překročení.
     *
     * U příspěvku na stravování náhled navíc ukáže, KOLIK směn s nárokem za měsíc
     * eviduje (`shift_entitlements`) a jestli je podklad úplný
     * (`entitlement`). Bez toho by účetní viděla jen nižší strop a nevěděla proč.
     *
     * @param array{employee_id:int, period_start:string, amount_minor:int, ...} $input
     * @return array<string,mixed>|null
     */
    private function basketSplit(
        int $supplierId,
        PayrollComponentDefinition $definition,
        array $input,
        ?PayrollMealShiftEntitlement $entitlement,
    ): ?array {
        if ($definition->exemptionBasket === null) {
            return null;
        }
        return [
                ...$this->inputs->basketSplit(
                    $supplierId,
                    $input['employee_id'],
                    $definition->exemptionBasket,
                    $input['period_start'],
                    $input['amount_minor'],
                    $entitlement?->count() ?? 0,
                )->jsonSerialize(),
                'entitlement' => $entitlement?->jsonSerialize(),
            ];
    }
}
