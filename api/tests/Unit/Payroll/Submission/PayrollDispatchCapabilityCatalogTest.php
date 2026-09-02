<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionBridgeService;
use MyInvoice\Service\Payroll\Submission\Ozuspoj\OzuspojSubmissionService;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapability;
use MyInvoice\Service\Payroll\Submission\PayrollDispatchCapabilityCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSubmissionService;
use PHPUnit\Framework\TestCase;

final class PayrollDispatchCapabilityCatalogTest extends TestCase
{
    /**
     * Neodesílatelná agenda MUSÍ nést důvod. Bez něj by fronta ukázala řádek
     * se zašedlým tlačítkem a bez vysvětlení — přesně ten stav, kvůli kterému
     * účetní neví, jestli na podání zapomněla, nebo ho appka neumí.
     */
    public function testEveryNonDispatchableAgendaExplainsWhy(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        $checked = 0;
        foreach ($catalog->codes() as $code) {
            $capability = $catalog->forAgenda($code);
            if ($capability->isDispatchable()) {
                self::assertNull(
                    $capability->reason,
                    "Odesílatelná agenda {$code} nemá co vysvětlovat.",
                );
                continue;
            }
            ++$checked;
            self::assertIsString($capability->reason);
            self::assertNotSame('', trim((string) $capability->reason));
            // Věta, ne kód chyby: musí končit tečkou a nést mezery.
            self::assertStringEndsWith(
                '.',
                (string) $capability->reason,
                "Důvod u agendy {$code} není celá věta.",
            );
            self::assertStringContainsString(' ', (string) $capability->reason);
        }
        self::assertGreaterThan(
            0,
            $checked,
            'Test nezkontroloval žádnou neodesílatelnou agendu.',
        );
    }

    /**
     * Neznámý kód nesmí skončit výjimkou ani tichým „umíme": `agenda_code` je
     * u povinnosti volný text, takže sem může přijít cokoliv.
     */
    public function testUnknownAgendaFailsClosedWithReason(): void
    {
        $capability = (new PayrollDispatchCapabilityCatalog())
            ->forAgenda('NECO_UPLNE_NOVEHO');

        self::assertFalse($capability->isDispatchable());
        self::assertNotNull($capability->reason);
    }

    /** Ročník v kódu nesmí zařazení rozbít — historické `JMHZ` je totéž. */
    public function testYearAliasResolvesToSameCapability(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();

        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            $catalog->forAgenda('JMHZ')->mode,
        );
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            $catalog->forAgenda(JmhzSubmissionBridgeService::AGENDA_CODE)->mode,
        );
        self::assertSame(
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            $catalog->forAgenda('  jmhz25  ')->mode,
        );
    }

    /**
     * Konkrétní tvrzení o tom, co dnes odeslat JDE a co ne. Kdyby se to
     * změnilo, musí to být VĚDOMÉ rozhodnutí, ne vedlejší efekt refaktoringu.
     */
    public function testDispatchModesMatchDocumentedChannels(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();
        $expected = [
            JmhzSubmissionBridgeService::AGENDA_CODE
                => PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            PayrollRegistrationSubmissionService::AGENDA_PREZEC
                => PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION,
            PayrollRegistrationSubmissionService::AGENDA_REGZEC
                => PayrollDispatchCapabilityCatalog::MODE_VREP_REGISTRATION,
            'NEMPRI' => PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL,
            'HZUPN' => PayrollDispatchCapabilityCatalog::MODE_ISDS_PAYROLL,
            HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW
                => PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH,
            HealthInsuranceSubmissionService::AGENDA_BULK_NOTIFICATION
                => PayrollDispatchCapabilityCatalog::MODE_ISDS_HEALTH,
            // Tyhle tři aplikace NEODESÍLÁ — a tvrdit opak by znamenalo
            // tlačítko, které vždycky selže.
            EldpStatementService::AGENDA_CODE
                => PayrollDispatchCapabilityCatalog::MODE_NONE,
            OzuspojSubmissionService::AGENDA_CODE
                => PayrollDispatchCapabilityCatalog::MODE_NONE,
            PayrollRegistrationSubmissionService::AGENDA_EMPLOYER_REGISTRATION
                => PayrollDispatchCapabilityCatalog::MODE_NONE,
        ];

        foreach ($expected as $code => $mode) {
            self::assertSame(
                $mode,
                $catalog->forAgenda((string) $code)->mode,
                "Agenda {$code} má jiný režim odeslání, než je doložený.",
            );
        }
    }

    /** Schránky pojišťoven jsou doložené jen pro ostré prostředí. */
    public function testHealthInsuranceIsProductionOnly(): void
    {
        $catalog = new PayrollDispatchCapabilityCatalog();

        self::assertTrue(
            $catalog->forAgenda(
                HealthInsuranceSubmissionService::AGENDA_PAYMENT_OVERVIEW,
            )->productionOnly,
        );
        self::assertFalse(
            $catalog->forAgenda(
                JmhzSubmissionBridgeService::AGENDA_CODE,
            )->productionOnly,
        );
    }

    /**
     * Invariant hodnotového objektu: „umíme" s důvodem i „neumíme" bez důvodu
     * jsou obojí lež, kterou nesmí jít sestavit.
     */
    public function testCapabilityRejectsContradictoryShape(): void
    {
        $this->expectException(\LogicException::class);

        new PayrollDispatchCapability(
            'JMHZ25',
            PayrollDispatchCapabilityCatalog::MODE_VREP_JMHZ,
            'Tenhle důvod tu nemá co dělat.',
        );
    }

    public function testCapabilityRejectsMissingReason(): void
    {
        $this->expectException(\LogicException::class);

        new PayrollDispatchCapability(
            'ELDP',
            PayrollDispatchCapabilityCatalog::MODE_NONE,
            null,
        );
    }
}
