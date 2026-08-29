<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Net;

use MyInvoice\Service\Payroll\Calculation\Money;

final class PayrollNetCalculator
{
    public function __construct(
        private readonly DeductionPriorityResolver $deductionResolver =
            new DeductionPriorityResolver(),
    ) {}

    public function calculate(PayrollNetInput $input): PayrollNetResult
    {
        $cash = new Money(0);
        $nonCash = new Money(0);
        foreach ($input->relationships as $relationship) {
            $cash = $cash->add(new Money($relationship->cashIncomeMinorUnits));
            $nonCash = $nonCash->add(new Money($relationship->nonCashIncomeMinorUnits));
        }
        $netBeforeDeductions = $cash
            ->add(new Money($input->correctionMinorUnits))
            ->subtract(new Money($input->employeeSocialMinorUnits))
            ->subtract(new Money($input->employeeHealthMinorUnits))
            ->subtract(new Money($input->advanceTaxMinorUnits))
            ->subtract(new Money($input->withholdingTaxMinorUnits))
            // MĚSÍČNÍ daňový bonus do základu srážek PATŘÍ (rozhodnuto 8/2026,
            // nález E-14). § 277 odst. 1 o. s. ř. definuje čistou mzdu tak, že
            // se od mzdy odečte „záloha na daň z příjmů fyzických osob srážená
            // z příjmů ze závislé činnosti" a pojistné. Měsíční daňový bonus
            // podle § 35d odst. 4 zákona o daních z příjmů je součástí téhož
            // zúčtování zálohy: plátce ho vyplácí spolu se zálohou a o jeho
            // částku se sražená záloha snižuje, případně jde do záporu.
            // Odečtená „záloha" je tedy záloha PO bonusu — bonus proto základ
            // srážek zvyšuje a exekuci podléhá. Shodně to počítá kalkulačka
            // Exekutorské komory i příručka MPSV.
            //
            // Asymetrie s doplatkem z ročního zúčtování níž je záměrná a stojí
            // na jiném důvodu než na povaze bonusu: ten doplatek NENÍ součástí
            // měsíčního zúčtování zálohy za tenhle měsíc, ale vrácením přeplatku
            // na dani za rok minulý (§ 35d odst. 8 ZDP). Není to plnění za
            // práci a čistou mzdu podle § 277 odst. 1 nezvyšuje. Nejde tedy
            // o dvojí metr na tutéž veličinu — jeden je zúčtování běžného
            // měsíce, druhý vypořádání uzavřeného roku.
            ->add(new Money($input->taxBonusMinorUnits));
        $deductions = $this->deductionResolver->resolve(
            $input->deductions,
            $input->voluntaryDeductionCapacityMinorUnits,
        );
        $deducted = new Money(0);
        foreach ($deductions as $deduction) {
            $deducted = $deducted->add(new Money($deduction->appliedMinorUnits));
        }
        // Doplatek ze zúčtování se připočítá až za srážkami. Je to vrácená
        // záloha na daň, ne mzda (§ 35d odst. 8), takže do základu srážek podle
        // § 277 odst. 1 OSŘ ani do kapacity dobrovolných srážek nepatří.
        $netPayable = $netBeforeDeductions
            ->subtract($deducted)
            ->add(new Money($input->annualSettlementMinorUnits));

        return new PayrollNetResult(
            $input->personReference,
            $input->relationships,
            $cash->minorUnits,
            $nonCash->minorUnits,
            $input->employeeSocialMinorUnits,
            $input->employeeHealthMinorUnits,
            $input->advanceTaxMinorUnits,
            $input->withholdingTaxMinorUnits,
            $input->taxBonusMinorUnits,
            $input->correctionMinorUnits,
            $netBeforeDeductions->minorUnits,
            $deducted->minorUnits,
            $netPayable->minorUnits,
            $deductions,
            $input->annualSettlementMinorUnits,
        );
    }
}
