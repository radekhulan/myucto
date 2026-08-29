<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\IncomeTax;

use MyInvoice\Service\Payroll\Calculation\CalculationStep;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxCalculator;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxInput;
use MyInvoice\Service\Payroll\Calculation\MonthlyAdvanceTaxResult;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;

final class MonthlyEmploymentIncomeTaxCalculator
{
    private readonly MonthlyAdvanceTaxCalculator $advanceTaxCalculator;

    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
    ) {
        $this->advanceTaxCalculator = new MonthlyAdvanceTaxCalculator($rulesets);
    }

    public function calculate(
        MonthlyEmploymentIncomeTaxInput $input,
    ): MonthlyEmploymentIncomeTaxResult {
        $ruleset = $this->rulesets->forCalculation(
            PayrollRulesetDomain::IncomeTax,
            $input->calculationDate,
        );
        // Fasáda nad účinným rulesetem, ne druhá kopie hodnot: ověří se ÚPLNOST
        // parametrů (fail-closed), nikdy shoda s literálem v kódu. Změna sazby
        // nebo slevy v administraci se tak projeví ve výpočtu bez nasazení.
        $policy = EmploymentIncomeTaxPolicy2026::forRuleset($ruleset);

        $issues = [];
        // Podporovaný zdaňovací rok = rok, který má účinný ruleset po celou svou
        // délku. Roční akumulátor sčítá celý rok, takže částečné pokrytí je
        // stejná chyba jako žádné.
        if (!PayrollRulesetYearCoverage::coversYear(
            $this->rulesets,
            PayrollRulesetDomain::IncomeTax,
            (int) substr($input->calculationDate, 0, 4),
        )) {
            $issues[] = 'unsupported-tax-year';
        }

        $declarations = array_values(array_filter(
            $input->declarations,
            static fn (TaxDeclarationEvidence $evidence): bool => $evidence->isEffective(
                $input->calculationDate,
            ),
        ));
        if ($declarations === []) {
            $issues[] = 'tax-declaration-evidence-missing';
        } elseif (count($declarations) > 1) {
            $issues[] = 'tax-declaration-conflict';
        }
        $declaration = count($declarations) === 1 ? $declarations[0] : null;
        if ($declaration?->status === TaxDeclarationStatus::Unverified) {
            $issues[] = 'tax-declaration-unverified';
        }
        if ($input->residence->residence === TaxResidence::Unverified) {
            $issues[] = 'tax-residence-unverified';
        } elseif (!$input->residence->isEffective($input->calculationDate)) {
            $issues[] = 'tax-residence-evidence-not-effective';
        }

        $signed = $declaration?->status === TaxDeclarationStatus::Signed;
        $bases = [];
        $groups = [];
        $relationshipReferences = [];
        foreach ($input->relationships as $index => $relationship) {
            if (isset($relationshipReferences[$relationship->relationshipReference])) {
                $issues[] = 'duplicate-employment-relationship-reference';
            }
            $relationshipReferences[$relationship->relationshipReference] = true;
            $base = $relationship->includedBaseMinorUnits();
            $bases[$index] = $base;
            if ($base < 0) {
                $issues[] = 'negative-relationship-tax-base';
            }
            foreach ($relationship->components as $component) {
                if ($component->treatment === IncomeTaxComponentTreatment::ManualReview) {
                    $issues[] = 'income-component-tax-treatment-unverified';
                }
                if (
                    $component->treatment === IncomeTaxComponentTreatment::Exempt
                    && !$component->hasVerifiedTreatmentEvidence($input->calculationDate)
                ) {
                    $issues[] = 'income-component-exemption-evidence-unverified';
                }
                if ($component->correctionTreatment !== TaxCorrectionTreatment::CurrentMonth) {
                    $issues[] = 'prior-period-tax-correction-requires-revision';
                }
            }
            $classification = $this->candidateGroup(
                $relationship,
                $input->residence->residence,
                $signed,
            );
            $groups[$index] = $classification['group'];
            if ($classification['issue'] !== null) {
                $issues[] = $classification['issue'];
            }
        }

        $creditResolution = $this->resolveCredits($input, $declaration, $policy);
        $issues = [...$issues, ...$creditResolution['issues']];
        $childResolution = $this->resolveChildren($input, $declaration, $policy);
        $issues = [...$issues, ...$childResolution['issues']];
        $issues = array_values(array_unique($issues));

        if ($issues !== []) {
            $relationships = [];
            foreach ($input->relationships as $index => $relationship) {
                $relationships[] = new RelationshipTaxResult(
                    $relationship->relationshipReference,
                    $relationship->kind,
                    $bases[$index],
                    TaxRegime::ManualReview,
                    $groups[$index],
                );
            }

            return new MonthlyEmploymentIncomeTaxResult(
                TaxCalculationStatus::ManualReview,
                $input->calculationDate,
                $input->employeeReference,
                $input->payerReference,
                $relationships,
                null,
                [],
                0,
                0,
                $creditResolution['amount'],
                0,
                $creditResolution['breakdown'],
                $childResolution['amount'],
                0,
                $this->annualResult($input, $policy, null, 0, 0, 0, 0),
                $issues,
                EmploymentIncomeTaxPolicy2026::ID,
                EmploymentIncomeTaxPolicy2026::contractHash(),
                $ruleset->id,
                $ruleset->canonicalHash,
            );
        }

        $groupTotals = ['dpp' => 0, 'other' => 0];
        foreach ($groups as $index => $group) {
            if ($group !== null) {
                $groupTotals[$group] = TaxIntegerMath::add(
                    $groupTotals[$group],
                    $bases[$index],
                );
            }
        }

        $relationships = [];
        $advanceBase = 0;
        $withholdingBases = ['dpp' => 0, 'other' => 0];
        foreach ($input->relationships as $index => $relationship) {
            $group = $groups[$index];
            $regime = $this->regime(
                $signed,
                $group,
                $group === null ? 0 : $groupTotals[$group],
                $policy,
            );
            if ($regime === TaxRegime::Advance) {
                $advanceBase = TaxIntegerMath::add($advanceBase, $bases[$index]);
            } elseif ($group !== null) {
                $withholdingBases[$group] = TaxIntegerMath::add(
                    $withholdingBases[$group],
                    $bases[$index],
                );
            }
            $relationships[] = new RelationshipTaxResult(
                $relationship->relationshipReference,
                $relationship->kind,
                $bases[$index],
                $regime,
                $regime === TaxRegime::Withholding ? $group : null,
            );
        }

        $advanceTax = $this->advanceTaxCalculator->calculate(
            $input->calculationDate,
            new MonthlyAdvanceTaxInput(
                taxableIncomeMinorUnits: $advanceBase,
                signedDeclaration: $signed,
                claimTaxpayerCredit: $creditResolution['taxpayer'],
                otherNonRefundableCreditsMinorUnits: $creditResolution['other'],
                childCreditMinorUnits: $childResolution['amount'],
            ),
        );
        $withholdingGroups = [];
        $roundedWithholdingBases = [];
        foreach ($withholdingBases as $group => $base) {
            if ($base === 0) {
                continue;
            }
            // § 36 odst. 3 věta třetí: „Základ daně se nesnižuje o nezdanitelnou
            // část základu daně (§ 15) a zaokrouhluje se na celé koruny dolů…"
            // a věta pátá: „Daň z příjmů vybíraná zvláštní sazbou se zaokrouhluje
            // na celé koruny dolů." Zaokrouhluje se tedy DVAKRÁT — nejdřív
            // základ, teprve pak daň z něj vypočtená.
            // Zaokrouhlením až daně by vykázaný základ neodpovídal přepočtu
            // finančního úřadu a rozcházel by se o korunu.
            //
            // Zaokrouhluje se ÚHRN za skupinu (dohody do limitu / ostatní
            // příjmy), protože právě z něj se v jednom měsíci sráží jedna daň;
            // per vztah by se zaokrouhlovalo tolikrát, kolik má poplatník
            // dohod, a odchylka by se násobila.
            $roundedBase = intdiv($base, 100) * 100;
            $roundedWithholdingBases[$group] = $roundedBase;
            if ($roundedBase === 0) {
                continue;
            }
            $step = CalculationStep::calculate(
                "monthly-withholding-tax-{$group}",
                $roundedBase,
                DecimalRate::fromString($policy->rate('withholding.rate')),
                RoundingMode::Floor,
            );
            $withholdingGroups[] = new WithholdingTaxGroupResult(
                $group,
                $roundedBase,
                intdiv($step->outputMinorUnits, 100) * 100,
                $step,
                $base,
            );
        }
        // Do ročního úhrnu jde ZAOKROUHLENÝ základ — je to částka, kterou
        // plátce vykázal a ze které daň skutečně srazil.
        $withholdingBase = 0;
        foreach ($roundedWithholdingBases as $base) {
            $withholdingBase = TaxIntegerMath::add($withholdingBase, $base);
        }
        $withholdingTax = 0;
        foreach ($withholdingGroups as $group) {
            $withholdingTax = TaxIntegerMath::add(
                $withholdingTax,
                $group->taxMinorUnits,
            );
        }
        $appliedNonRefundable = min(
            $advanceTax->taxBeforeCreditsMinorUnits,
            $creditResolution['amount'],
        );
        $taxAfterNonRefundable = max(
            0,
            $advanceTax->taxBeforeCreditsMinorUnits - $creditResolution['amount'],
        );
        $appliedChild = min($taxAfterNonRefundable, $childResolution['amount']);

        return new MonthlyEmploymentIncomeTaxResult(
            TaxCalculationStatus::Calculated,
            $input->calculationDate,
            $input->employeeReference,
            $input->payerReference,
            $relationships,
            $advanceTax,
            $withholdingGroups,
            $withholdingBase,
            $withholdingTax,
            $creditResolution['amount'],
            $appliedNonRefundable,
            $creditResolution['breakdown'],
            $childResolution['amount'],
            $appliedChild,
            $this->annualResult(
                $input,
                $policy,
                $advanceTax,
                $withholdingBase,
                $withholdingTax,
                $appliedNonRefundable,
                $appliedChild,
            ),
            [],
            EmploymentIncomeTaxPolicy2026::ID,
            EmploymentIncomeTaxPolicy2026::contractHash(),
            $ruleset->id,
            $ruleset->canonicalHash,
        );
    }

    /**
     * @return array{group:?string,issue:?string}
     */
    private function candidateGroup(
        EmploymentRelationshipTaxInput $relationship,
        TaxResidence $residence,
        bool $signed,
    ): array {
        if (
            $relationship->kind === EmploymentRelationshipKind::Dpp
            && $relationship->otherWithholdingEligibility
                !== OtherWithholdingEligibility::Automatic
        ) {
            return [
                'group' => null,
                'issue' => 'relationship-tax-classification-conflict',
            ];
        }
        if (
            $relationship->kind === EmploymentRelationshipKind::Employment
            && $relationship->otherWithholdingEligibility
                === OtherWithholdingEligibility::EligibleVerified
        ) {
            return [
                'group' => null,
                'issue' => 'relationship-tax-classification-conflict',
            ];
        }
        if (
            $relationship->kind === EmploymentRelationshipKind::SmallScaleEmployment
            && $relationship->otherWithholdingEligibility
                === OtherWithholdingEligibility::IneligibleVerified
        ) {
            return [
                'group' => null,
                'issue' => 'relationship-tax-classification-conflict',
            ];
        }
        if (
            $relationship->kind === EmploymentRelationshipKind::StatutoryBody
            && $residence === TaxResidence::NonResident
            && $relationship->otherWithholdingEligibility
                === OtherWithholdingEligibility::EligibleVerified
        ) {
            return [
                'group' => null,
                'issue' => 'relationship-tax-classification-conflict',
            ];
        }
        if ($signed) {
            return ['group' => null, 'issue' => null];
        }
        if (
            $relationship->kind === EmploymentRelationshipKind::StatutoryBody
            && $residence === TaxResidence::NonResident
        ) {
            return ['group' => null, 'issue' => null];
        }

        return match ($relationship->otherWithholdingEligibility) {
            OtherWithholdingEligibility::EligibleVerified => [
                'group' => 'other',
                'issue' => null,
            ],
            OtherWithholdingEligibility::IneligibleVerified => [
                'group' => null,
                'issue' => null,
            ],
            OtherWithholdingEligibility::Unverified => [
                'group' => null,
                'issue' => 'other-withholding-eligibility-unverified',
            ],
            // `Automatic` = „zařaď to podle druhu vztahu". Kde to podle druhu
            // vztahu zařadit nejde, je jediná bezpečná odpověď ruční posouzení;
            // které druhy to jsou, ví enum vztahu, protože se podle toho řídí
            // i sestavovač vstupů (PayrollRunStatutoryInputAssembler). Kdyby
            // pravidlo žilo na dvou místech, rozešlo by se: sestavovač by
            // poslal `Automatic` u vztahu, který vyžaduje prohlášení plátce,
            // a výpočet by ho zařadil beze slova.
            OtherWithholdingEligibility::Automatic => [
                'group' => $relationship->kind->automaticWithholdingGroup(),
                'issue' => $relationship->kind->requiresOtherWithholdingStatement()
                    ? 'other-withholding-eligibility-unverified'
                    : null,
            ],
        };
    }

    private function regime(
        bool $signed,
        ?string $group,
        int $groupBase,
        EmploymentIncomeTaxPolicy2026 $policy,
    ): TaxRegime {
        if ($signed || $group === null) {
            return TaxRegime::Advance;
        }
        // § 6 odst. 4 ZDP (znění zák. č. 470/2024 Sb. od 1. 1. 2025): srážka platí,
        // jen když úhrn rozhodné částky NEDOSÁHNE. Test je proto ostrý — příjem
        // PŘESNĚ na rozhodné částce zakládá účast na nemocenském pojištění
        // (§ 7a z. č. 187/2006 Sb. „aspoň ve výši“) a daní se zálohou, ne srážkou.
        // Obě hranice tak na sebe navazují bez díry i bez překryvu.
        $threshold = $group === 'dpp'
            ? $policy->money('dpp.withholding.threshold')
            : $policy->money('other.withholding.threshold');

        return $groupBase < $threshold
            ? TaxRegime::Withholding
            : TaxRegime::Advance;
    }

    /**
     * @return array{
     *   amount:int,other:int,taxpayer:bool,
     *   breakdown:array<string,int>,issues:list<string>
     * }
     */
    private function resolveCredits(
        MonthlyEmploymentIncomeTaxInput $input,
        ?TaxDeclarationEvidence $declaration,
        EmploymentIncomeTaxPolicy2026 $policy,
    ): array {
        $active = array_values(array_filter(
            $input->creditClaims,
            static fn (TaxCreditClaim $claim): bool => $claim->isEffective($input->calculationDate),
        ));
        $issues = [];
        $kinds = [];
        foreach ($active as $claim) {
            if ($claim->evidenceStatus !== TaxEvidenceStatus::Verified) {
                $issues[] = 'tax-credit-evidence-unverified';
            }
            if (isset($kinds[$claim->kind->value])) {
                $issues[] = 'duplicate-tax-credit-claim';
            }
            $kinds[$claim->kind->value] = true;
            if (
                $input->residence->residence === TaxResidence::NonResident
                && $claim->kind !== TaxCreditKind::Taxpayer
            ) {
                $issues[] = 'nonresident-monthly-credit-not-supported';
            }
        }
        if (
            isset($kinds[TaxCreditKind::DisabilityBasic->value])
            && isset($kinds[TaxCreditKind::DisabilityExtended->value])
        ) {
            $issues[] = 'disability-credit-conflict';
        }
        if ($active !== [] && $declaration?->status !== TaxDeclarationStatus::Signed) {
            $issues[] = 'tax-credit-requires-signed-declaration';
        }

        $taxpayer = isset($kinds[TaxCreditKind::Taxpayer->value]);
        $other = 0;
        // Rozpad po druzích slevy potřebuje JMHZ (atributy 10299-10302), kde se
        // každá sleva vykazuje samostatně. Úhrn sám o sobě je nerozložitelný.
        $breakdown = [];
        foreach ($active as $claim) {
            $claimAmount = match ($claim->kind) {
                TaxCreditKind::Taxpayer
                    => $policy->money('credit.taxpayer.monthly'),
                TaxCreditKind::DisabilityBasic
                    => $policy->money('credit.disability.basic.monthly'),
                TaxCreditKind::DisabilityExtended
                    => $policy->money('credit.disability.extended.monthly'),
                TaxCreditKind::ZtpP => $policy->money('credit.ztp_p.monthly'),
            };
            $breakdown[$claim->kind->value] = TaxIntegerMath::add(
                $breakdown[$claim->kind->value] ?? 0,
                $claimAmount,
            );
            if ($claim->kind !== TaxCreditKind::Taxpayer) {
                $other = TaxIntegerMath::add($other, $claimAmount);
            }
        }
        $amount = TaxIntegerMath::add($other, $taxpayer
            ? $policy->money('credit.taxpayer.monthly')
            : 0);
        ksort($breakdown, SORT_STRING);

        return [
            'amount' => $amount,
            'other' => $other,
            'taxpayer' => $taxpayer,
            'breakdown' => $breakdown,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    /** @return array{amount:int,issues:list<string>} */
    private function resolveChildren(
        MonthlyEmploymentIncomeTaxInput $input,
        ?TaxDeclarationEvidence $declaration,
        EmploymentIncomeTaxPolicy2026 $policy,
    ): array {
        $active = array_values(array_filter(
            $input->childClaims,
            static fn (TaxChildClaim $claim): bool => $claim->isEffective($input->calculationDate),
        ));
        $issues = [];
        $orders = [];
        $references = [];
        foreach ($active as $claim) {
            if ($claim->evidenceStatus !== TaxEvidenceStatus::Verified) {
                $issues[] = 'tax-child-evidence-unverified';
            }
            if (!$claim->sharedHouseholdConfirmed) {
                $issues[] = 'tax-child-shared-household-unverified';
            }
            if (!$claim->otherClaimantExcluded) {
                $issues[] = 'tax-child-concurrent-claim-unresolved';
            }
            if (isset($orders[$claim->order])) {
                $issues[] = 'tax-child-order-conflict';
            }
            if (isset($references[$claim->childReference])) {
                $issues[] = 'duplicate-tax-child-claim';
            }
            $orders[$claim->order] = true;
            $references[$claim->childReference] = true;
        }
        if ($active !== [] && $declaration?->status !== TaxDeclarationStatus::Signed) {
            $issues[] = 'tax-child-requires-signed-declaration';
        }
        if ($active !== [] && $input->residence->residence !== TaxResidence::CzechResident) {
            $issues[] = 'nonresident-monthly-child-credit-not-supported';
        }
        if ($orders !== []) {
            ksort($orders);
            if (array_keys($orders) !== range(1, count($orders))) {
                $issues[] = 'tax-child-order-gap';
            }
        }

        $amount = 0;
        foreach ($active as $claim) {
            $credit = $policy->money(
                ChildCreditRateKey::forOrder($claim->order),
            );
            $amount = TaxIntegerMath::add(
                $amount,
                $claim->ztpP ? TaxIntegerMath::add($credit, $credit) : $credit,
            );
        }

        return [
            'amount' => $amount,
            'issues' => array_values(array_unique($issues)),
        ];
    }

    private function annualResult(
        MonthlyEmploymentIncomeTaxInput $input,
        EmploymentIncomeTaxPolicy2026 $policy,
        ?MonthlyAdvanceTaxResult $advanceTax,
        int $withholdingBase,
        int $withholdingTax,
        int $appliedNonRefundable,
        int $appliedChild,
    ): AnnualTaxAccumulatorResult {
        $prior = $input->annualAccumulator
            ?? AnnualTaxAccumulatorInput::empty((int) substr($input->calculationDate, 0, 4));
        $calculated = $advanceTax !== null;
        $currentAdvanceBase = $advanceTax === null
            ? 0
            : $advanceTax->taxableIncomeMinorUnits;
        $currentAdvanceTax = $advanceTax === null
            ? 0
            : $advanceTax->taxAfterCreditsMinorUnits;
        $currentTaxBonus = $advanceTax === null
            ? 0
            : $advanceTax->taxBonusMinorUnits;
        $bonusQualifyingIncome = TaxIntegerMath::add(
            $prior->bonusQualifyingIncomeMinorUnits,
            $currentAdvanceBase,
        );

        return new AnnualTaxAccumulatorResult(
            $prior->year,
            TaxIntegerMath::add($prior->completedMonths, $calculated ? 1 : 0),
            TaxIntegerMath::add($prior->advanceBaseMinorUnits, $currentAdvanceBase),
            TaxIntegerMath::add($prior->withholdingBaseMinorUnits, $withholdingBase),
            TaxIntegerMath::add($prior->advanceTaxMinorUnits, $currentAdvanceTax),
            TaxIntegerMath::add($prior->withholdingTaxMinorUnits, $withholdingTax),
            TaxIntegerMath::add(
                $prior->appliedNonRefundableCreditsMinorUnits,
                $appliedNonRefundable,
            ),
            TaxIntegerMath::add(
                $prior->appliedChildCreditMinorUnits,
                $appliedChild,
            ),
            TaxIntegerMath::add($prior->taxBonusMinorUnits, $currentTaxBonus),
            $bonusQualifyingIncome,
            $bonusQualifyingIncome >= $policy->money('bonus.minimum_income.yearly'),
            $input->externalCertificates,
            false,
            false,
        );
    }
}
