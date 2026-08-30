<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog;
use PHPUnit\Framework\TestCase;

final class PayrollStatutoryAgendaCatalogTest extends TestCase
{
    public function testJmhzOnlyPartiallyReplacesNempriAndNeverHzupn(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame(
            'partially_replaced',
            $agendas['NEMPRI']['replacement_mode'],
        );
        self::assertSame('prepared_only', $agendas['NEMPRI']['capability']);
        self::assertTrue($agendas['NEMPRI']['evidence_supported']);
        self::assertSame('standalone', $agendas['HZUPN']['replacement_mode']);
        self::assertSame('prepared_only', $agendas['HZUPN']['capability']);
        self::assertTrue($agendas['HZUPN']['evidence_supported']);
    }

    /**
     * Datovou schránku má doloženou ČSSZ v tabulce komunikačních kanálů
     * e-Podání; VREP/APEP zůstává zavřený, protože identifikátor třídy podání
     * pro tyhle dvě agendy v připnutém protokolu není.
     */
    public function testNempriAndHzupnDeclareDataBoxTransport(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame('isds', $agendas['NEMPRI']['transport_capability']);
        self::assertSame('isds', $agendas['HZUPN']['transport_capability']);
    }

    /**
     * Datová věta NEMPRI15/16/17/18/20 je jiný tiskopis než NEMPRI25 a připnuté
     * XSD pro ni nemáme. Připravit ji tedy nejde ani po přestavbě agendy.
     */
    public function testLegacyNempriKeepsTransportClosed(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2024-12');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame('not_supported', $agendas['NEMPRI']['transport_capability']);
    }

    public function testAccidentInsuranceIsCalculatedAndMaterializedOnPayments(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2026-08');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');
        $accident = $agendas['STATUTORY_ACCIDENT_INSURANCE'];

        self::assertSame('standalone', $accident['replacement_mode']);
        self::assertSame('prepared_only', $accident['capability']);
        self::assertSame('not_supported', $accident['transport_capability']);
        self::assertTrue($accident['evidence_supported']);
        self::assertSame(
            'accident_insurance_calculated_and_materialized',
            $accident['reason_code'],
        );
    }

    public function testLegacyNempriCannotBeFalselyRecordedThroughNewWorkflow(): void
    {
        $matrix = (new PayrollStatutoryAgendaCatalog())->forPeriod('2024-12');
        $agendas = array_column($matrix['agendas'], null, 'agenda_code');

        self::assertSame('not_supported', $agendas['NEMPRI']['capability']);
        self::assertFalse($agendas['NEMPRI']['evidence_supported']);
    }
}
