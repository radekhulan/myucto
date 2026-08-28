<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

final readonly class RepositoryPayrollGarnishmentPort implements PayrollGarnishmentPort
{
    public function __construct(
        private EnforcementCaseSource $source,
        private GarnishmentCalculator $calculator,
        private GarnishableIncomeResolver $incomeResolver = new GarnishableIncomeResolver(),
    ) {}

    public function calculate(
        EnforcementPersonMonthRequest $request,
    ): PayrollGarnishmentCalculation
    {
        $evidence = $this->source->evidenceFor(
            $request->supplierId,
            $request->employeeId,
            $request->period,
            $request->paymentDate,
        );
        $income = $this->incomeResolver->resolve(
            $request->incomeItems,
            $request->incomeEvidenceComplete,
        );

        $input = new GarnishmentInput(
            $request->period,
            $request->paymentDate,
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
        );

        return new PayrollGarnishmentCalculation(
            $request->supplierId,
            $request->employeeId,
            $input,
            $this->calculator->calculate($input),
        );
    }
}
