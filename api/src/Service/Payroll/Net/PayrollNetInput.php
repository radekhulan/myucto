<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\Calculation\Money;

final readonly class PayrollNetInput
{
    /**
     * @param list<NetRelationshipIncome> $relationships
     * @param list<PayrollDeductionRequest> $deductions
     */
    public function __construct(
        public string $personReference,
        public array $relationships,
        public int $employeeSocialMinorUnits,
        public int $employeeHealthMinorUnits,
        public int $advanceTaxMinorUnits,
        public int $withholdingTaxMinorUnits,
        public int $taxBonusMinorUnits,
        public int $correctionMinorUnits,
        public int $voluntaryDeductionCapacityMinorUnits,
        public array $deductions,
        /**
         * Doplatek ze zúčtování podle § 35d odst. 8. Vyplácí se s výplatou, ale
         * mzdou není — proto stojí mimo čistou mzdu před srážkami, ze které se
         * počítá kapacita srážek i základ podle § 277 odst. 1 OSŘ.
         */
        public int $annualSettlementMinorUnits = 0,
    ) {
        if ($personReference === '' || $relationships === []) {
            throw new \InvalidArgumentException('Čistá mzda vyžaduje osobu a alespoň jeden vztah.');
        }
        foreach ([
            $employeeSocialMinorUnits,
            $employeeHealthMinorUnits,
            $advanceTaxMinorUnits,
            $withholdingTaxMinorUnits,
            $taxBonusMinorUnits,
            $voluntaryDeductionCapacityMinorUnits,
        ] as $amount) {
            if ($amount < 0) {
                throw new \InvalidArgumentException('Odvody, daň, bonus ani kapacita nesmí být záporné.');
            }
        }
        // § 38ch odst. 5 věta poslední: „Případný nedoplatek … se poplatníkovi
        // nesráží." Záporná částka by z vrácení udělala srážku.
        if ($annualSettlementMinorUnits < 0) {
            throw new \InvalidArgumentException(
                'Doplatek ze zúčtování se nesráží, a nesmí být proto záporný.',
            );
        }
        $this->assertUniqueReferences($relationships, $deductions);
        $cash = new Money(0);
        foreach ($relationships as $relationship) {
            $cash = $cash->add(new Money($relationship->cashIncomeMinorUnits));
        }
        $net = $cash
            ->add(new Money($correctionMinorUnits))
            ->subtract(new Money($employeeSocialMinorUnits))
            ->subtract(new Money($employeeHealthMinorUnits))
            ->subtract(new Money($advanceTaxMinorUnits))
            ->subtract(new Money($withholdingTaxMinorUnits))
            ->add(new Money($taxBonusMinorUnits));
        // Záporná čistá peněžní mzda NENÍ chyba vstupu. Zaměstnanec celý měsíc
        // na neplaceném volnu nemá peněžní příjem, ale zaměstnavatel za něj
        // odvádí doplatek zdravotního pojištění do minimálního vyměřovacího
        // základu (§ 3 odst. 10 z. č. 592/1992 Sb.) a podle § 3 odst. 12 téhož
        // zákona ho platí ZAMĚSTNANEC prostřednictvím zaměstnavatele. Výsledek
        // je legitimní dluh zaměstnance vůči zaměstnavateli, který se účtuje
        // jako pohledávka (MD 335), ne důvod k pádu výpočtu.
        //
        // Fail-closed zůstává v tom, co z toho smí vyplynout: srážet se
        // z takové mzdy nedá nic. Kapacita dobrovolných srážek se proto
        // porovnává s nulou, ne se zápornou částkou — jinak by nulová kapacita
        // sama shodila výpočet a nenulová by se tvářila jako přípustná.
        if ($voluntaryDeductionCapacityMinorUnits > max(0, $net->minorUnits)) {
            throw new \InvalidArgumentException(
                'Kapacita dobrovolných srážek nesmí překročit čistou mzdu.',
            );
        }
    }

    /**
     * @param list<NetRelationshipIncome> $relationships
     * @param list<PayrollDeductionRequest> $deductions
     */
    private function assertUniqueReferences(array $relationships, array $deductions): void
    {
        $relationshipReferences = [];
        foreach ($relationships as $relationship) {
            if (isset($relationshipReferences[$relationship->relationshipReference])) {
                throw new \InvalidArgumentException('Vztah je ve vstupu uveden vícekrát.');
            }
            $relationshipReferences[$relationship->relationshipReference] = true;
        }
        $deductionReferences = [];
        foreach ($deductions as $deduction) {
            if (isset($deductionReferences[$deduction->deductionReference])) {
                throw new \InvalidArgumentException('Srážka je ve vstupu uvedena vícekrát.');
            }
            $deductionReferences[$deduction->deductionReference] = true;
        }
    }
}
