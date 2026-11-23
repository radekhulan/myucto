<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzBlockerRemediationPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Blocker;
use PHPUnit\Framework\TestCase;

/**
 * Odkazy z blokací měsíčního hlášení, které musí nést rok nebo období hlášení.
 */
final class JmhzBlockerRemediationPathTest extends TestCase
{
    /**
     * Roční údaje zaměstnavatele se vedou po letech. Bez roku otevřel odkaz
     * výchozí (už vyplněný) rok a účetní musela rok hlášení přepsat ručně.
     */
    public function testEmployerAnnualLinkCarriesTheReportedYear(): void
    {
        $blocker = new JmhzScenario1Blocker('jmhz_employer_annual_collective_agreement_missing', 'supplier', 8, ['10214']);

        self::assertSame(
            '/payroll/settings?tab=submissions&year=2027#jmhz-employer-annual-evidence',
            JmhzBlockerRemediationPath::forBlocker($blocker, '2027-01-01'),
        );
        self::assertSame(
            '/payroll/settings?tab=submissions&year=2026#jmhz-employer-annual-evidence',
            JmhzBlockerRemediationPath::forReadinessCode($blocker->code, null, '2026-12-01', 'supplier'),
        );
        self::assertSame(
            '/payroll/settings?tab=submissions#jmhz-employer-annual-evidence',
            JmhzBlockerRemediationPath::forBlocker($blocker),
        );
    }

    /** Nález, který účetní sama neopraví, vede na podporu, ne do prázdna. */
    public function testSupportKindLeadsToTheSupportPage(): void
    {
        self::assertSame(
            '/admin/support',
            JmhzBlockerRemediationPath::forBlocker(new JmhzScenario1Blocker('jmhz_xsd_validation_failed', 'revision', 5)),
        );
        // Zopakovat nebo zpracovat ručně: v aplikaci není kam jít.
        self::assertNull(JmhzBlockerRemediationPath::forBlocker(
            new JmhzScenario1Blocker('jmhz_workplace_codebooks_not_published', 'employment', 7),
        ));
    }

    /** Nález čtený ze schváleného běhu vede po opravě údaje na mzdové běhy hlášeného měsíce. */
    public function testFrozenSourceBlockerCarriesThePathToTheRunOfThePeriod(): void
    {
        $rows = JmhzBlockerRemediationPath::payload(
            [
                new JmhzScenario1Blocker('jmhz_office_variable_symbol_invalid', 'office', 4, ['10221']),
                new JmhzScenario1Blocker('component_jmhz_mapping_missing', 'component', 3),
            ],
            null,
            1,
            '2026-07-01',
        );

        self::assertTrue($rows[0]['needs_revision']);
        self::assertSame('/payroll/runs?period=2026-07', $rows[0]['revision_path']);
        self::assertSame('/payroll/settings?tab=employer#payroll-employer-offices', $rows[0]['remediation_path']);
        self::assertFalse($rows[1]['needs_revision']);
        self::assertNull($rows[1]['revision_path']);
    }
}
