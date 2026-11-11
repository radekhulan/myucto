<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Component;

use MyInvoice\Service\Payroll\Component\PayrollBenefitBasketService;
use MyInvoice\Service\Payroll\Component\PayrollBenefitExemptionBasket;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2027;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stravné na rok 2027 do vyhlášky MPSV chybí: schválení příspěvku na
 * stravování to pozná a místo odmítnutí plnění celé zdaní (audit ř. 20).
 */
final class PayrollMealLimitAwaitsPublicationTest extends TestCase
{
    public function testMealLimitAwaitsPublicationOnlyWhileTheRulesetSaysSo(): void
    {
        $service = new PayrollBenefitBasketService(new PayrollRulesetProvider([
            ...CzechPayrollRulesets2026::provider()->versions(),
            ...CzechPayrollRulesets2027::provider()->versions(),
        ]));

        self::assertTrue($service->limitAwaitsPublication(PayrollBenefitExemptionBasket::MealPerShift, '2027-01-01'));
        self::assertFalse($service->limitAwaitsPublication(PayrollBenefitExemptionBasket::MealPerShift, '2026-07-01'));
        self::assertFalse($service->limitAwaitsPublication(PayrollBenefitExemptionBasket::NonCashHealth, '2027-01-01'));
    }
}
