<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlVerdict;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Kontrola 57 (Katalog kontrol MH 1.4.2.10): hodiny rizikové práce 10273 nesmí
 * překročit odpracované hodiny 10268, přičemž desetinné 10268 se pro účely
 * kontroly zaokrouhluje nahoru. 10273 se podle Pokynů MH 1.4.14 (revize) vykazuje
 * v celých hodinách se zbytkem minut nahoru, takže 165,5 h odpracovaných
 * a 166 h rizikové práce je v pořádku.
 */
final class JmhzScenario1ControlEvaluatorRiskHoursTest extends TestCase
{
    public function testWholeRiskHoursRoundedUpPassControl57(): void
    {
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomes('165.500', '166'));
    }

    public function testRiskHoursAboveRoundedWorkedHoursFailControl57(): void
    {
        self::assertSame([JmhzControlOutcome::Failed], $this->outcomes('165.500', '167'));
        self::assertSame([JmhzControlOutcome::Failed], $this->outcomes('165.000', '166'));
    }

    /**
     * IN33 (Datové scénáře 1.4.0.2) a kontrola 282: nulové odpracované hodiny
     * 10268 odebírají rozpad 10269 až 10274, nesmí být vyplněný ani nulou.
     */
    public function testZeroWorkedHoursWithBreakdownFailControl282(): void
    {
        self::assertSame([JmhzControlOutcome::Failed], $this->outcomes('0.000', '0', 282));
        self::assertSame(
            [JmhzControlOutcome::Failed],
            $this->outcomesFor('<form:pocet>0.000</form:pocet><form:rozpad><form:prescas>0.000</form:prescas></form:rozpad>', 282),
        );
    }

    public function testZeroWorkedHoursWithoutBreakdownPassControl282(): void
    {
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomesFor('<form:pocet>0.000</form:pocet>', 282));
        self::assertSame([JmhzControlOutcome::Passed], $this->outcomes('165.500', '166', 282));
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomes(string $worked, string $risk, int $control = 57): array
    {
        return $this->outcomesFor(
            "<form:pocet>{$worked}</form:pocet><form:rozpad><form:riziko>"
                . "<form:hodinyOdpracovanePocet>{$risk}</form:hodinyOdpracovanePocet>"
                . '<form:kategorizaceRizika>1</form:kategorizaceRizika>'
                . '</form:riziko></form:rozpad>',
            $control,
        );
    }

    /** @return list<JmhzControlOutcome> */
    private function outcomesFor(string $hours, int $control): array
    {
        $xml = str_replace(
            '<form:pocet>184.000</form:pocet>',
            $hours,
            JmhzXmlSample::minimal(),
            $count,
        );
        self::assertSame(1, $count);
        $evaluator = new JmhzScenario1ControlEvaluator(
            JmhzControlSourceCatalog::load()->parameters(),
            new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
        );

        return array_map(
            static fn (JmhzControlVerdict $verdict): JmhzControlOutcome => $verdict->outcome,
            $evaluator->evaluate(
                $control,
                JmhzAttributeProjection::fromXml($xml),
                new JmhzControlContext('2026-08-14'),
            ),
        );
    }
}
