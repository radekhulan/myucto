<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerExplainer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use PHPUnit\Framework\TestCase;

final class JmhzBlockerExplainerTest extends TestCase
{
    public function testGroupsBlockersAndHidesInternalIdentifiers(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker(
                'component_jmhz_mapping_missing',
                'component',
                1,
            ),
            new JmhzScenario1Blocker(
                'component_jmhz_mapping_missing',
                'component',
                4,
            ),
            new JmhzScenario1Blocker(
                'jmhz_work_month_not_approved',
                'employment',
                10228,
            ),
        ]);

        self::assertStringContainsString('2 mzdové složky', $message);
        self::assertStringContainsString('Mzdy → Mzdové složky', $message);
        self::assertStringContainsString('1 pracovní vztah', $message);
        self::assertStringContainsString('Mzdy → Pracovní doba', $message);
        self::assertStringNotContainsString('component_jmhz_mapping_missing', $message);
        self::assertStringNotContainsString('10228', $message);
    }

    public function testUnknownBlockerStillHasActionableCzechFallback(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker('future_rule', 'employment', 77),
        ]);

        self::assertSame(
            'Chybí zákonný údaj potřebný pro měsíční hlášení. '
                . 'Dotčeno: 1 pracovní vztah. '
                . 'Otevřete Mzdy → Zaměstnanci a doplňte zvýrazněná pole.',
            $message,
        );
    }

    public function testExplainsWhyAbsenceCannotUseAutomaticEldpEvidence(): void
    {
        $message = JmhzBlockerExplainer::describe([
            new JmhzScenario1Blocker(
                'jmhz_eldp_absences_unsupported',
                'employment',
                10228,
            ),
            new JmhzScenario1Blocker(
                'jmhz_eldp_evidence_missing',
                'employment',
                10228,
            ),
        ]);

        self::assertStringContainsString('Měsíc obsahuje absenci', $message);
        self::assertStringContainsString('Mzdy → Pracovní doba', $message);
        self::assertStringContainsString('zpracujte jej individuálně', $message);
        self::assertStringNotContainsString('10228', $message);
        self::assertStringNotContainsString('Evidenční list DP', $message);
    }
}
