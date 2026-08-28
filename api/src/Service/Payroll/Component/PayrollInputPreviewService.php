<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

use MyInvoice\Repository\Payroll\PayrollComponentRepository;
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
        private readonly PayrollBenefitBasketService $baskets,
        private readonly PayrollMealShiftEvidenceService $mealEvidence,
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
        $taxYear = (int) substr($input['period_start'], 0, 4);

        return [
                ...$this->baskets->split(
                    $definition->exemptionBasket,
                    $input['period_start'],
                    $this->inputs->basketTotal(
                        $supplierId,
                        $input['employee_id'],
                        $definition->exemptionBasket,
                        $taxYear,
                        $input['period_start'],
                    ),
                    $input['amount_minor'],
                    $entitlement?->count() ?? 0,
                    $definition->exemptionBasket->scalesWithShifts()
                        ? $this->inputs->mealBasketEntitlements(
                            $supplierId,
                            $input['employee_id'],
                            $definition->exemptionBasket,
                            $input['period_start'],
                        )
                        : null,
                )->jsonSerialize(),
                'entitlement' => $entitlement?->jsonSerialize(),
            ];
    }
}
