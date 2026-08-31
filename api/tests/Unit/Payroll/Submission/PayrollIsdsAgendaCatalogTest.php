<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsMessageBuilder;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsRecipient;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PHPUnit\Framework\TestCase;

/**
 * Které mzdové agendy jdou odeslat datovou schránkou a KAM.
 *
 * Testy drží DOLOŽENÉ hodnoty, ne implementaci: ID datové schránky je sedm
 * znaků bez kontrolní číslice, takže překlep se neodhalí jinak než porovnáním
 * se zdrojem, a odeslané podání se nedá vzít zpět.
 */
final class PayrollIsdsAgendaCatalogTest extends TestCase
{
    public function testSicknessAgendasAreDispatchableAtAll(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();

        self::assertTrue($catalog->has('NEMPRI'));
        self::assertTrue($catalog->has('HZUPN'));
        self::assertTrue($catalog->has('JMHZ25'));
    }

    /**
     * `agenda_code` povinnosti je u JMHZ psaný dvěma způsoby (starší `JMHZ`,
     * novější `JMHZ25`). Bez normalizace by starší povinnost dostala „agendu
     * neznáme" a odeslání by selhalo na kódu, který se jinde běžně používá.
     */
    public function testLegacyJmhzCodeResolvesToTheCanonicalOne(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();

        self::assertTrue($catalog->has('jmhz'));
        self::assertSame('JMHZ25', $catalog->require('JMHZ')->code);
    }

    /**
     * ELDP obsahuje jen kontrolní XML, odesílatelná datová věta k němu
     * připnutá není — nesmí se tvářit, že ho appka odešle.
     */
    public function testEldpIsNotDispatchable(): void
    {
        self::assertFalse((new PayrollIsdsAgendaCatalog())->has('ELDP'));
    }

    /** Nedoložená agenda končí konkrétní větou, ne obecným „nepodporováno". */
    public function testUndocumentedAgendaIsRefusedWithAReason(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();

        try {
            $catalog->require('ELDP');
            self::fail('Nedoložená agenda měla být odmítnuta.');
        } catch (SubmissionChannelException $exception) {
            self::assertSame('payroll_isds_agenda_undocumented', $exception->errorCode);
            self::assertStringContainsString('ELDP', $exception->getMessage());
        }
    }

    /**
     * NEMPRI a HZUPN mají doloženou OBECNOU schránku e-Podání ČSSZ, ne
     * vlastní schránku JMHZ. Kdyby hlášení o nemoci odešlo na `iie254d`,
     * míří mimo agendu, pro kterou byla schránka zřízena.
     */
    public function testSicknessAgendasTargetTheGeneralEpodaniBoxInProduction(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();

        foreach (['NEMPRI', 'HZUPN'] as $code) {
            $agenda = $catalog->require($code);
            self::assertSame('5ffu6xk', $agenda->documentedBoxId('production'), $code);
            self::assertNotSame('iie254d', $agenda->documentedBoxId('production'), $code);
            self::assertSame('cssz_epodani_obecna', $agenda->recipientCode('production'), $code);
        }

        self::assertSame('iie254d', $catalog->require('JMHZ25')->documentedBoxId('production'));
    }

    /**
     * Testovací prostředí NESMÍ mířit na ostrou schránku — cvičné podání by
     * dorazilo ČSSZ doopravdy.
     */
    public function testTestEnvironmentUsesTheTestBoxForEveryAgenda(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();

        foreach ($catalog->codes() as $code) {
            $agenda = $catalog->require($code);
            self::assertSame('9tsaf6s', $agenda->documentedBoxId('test'), $code);
            self::assertSame('cssz_epodani_test', $agenda->recipientCode('test'), $code);
        }
    }

    public function testUnknownEnvironmentIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(SubmissionChannelException::class);

        (new PayrollIsdsAgendaCatalog())->require('NEMPRI')->documentedBoxId('staging');
    }

    /**
     * Věc zprávy je pro ČLOVĚKA ve schránce — musí nést TU agendu, která
     * odchází. Builder dřív psal „Jednotné měsíční hlášení zaměstnavatele"
     * natvrdo, takže by hlášení o nemoci dorazilo pod cizím názvem a účetní
     * by ho ve schránce hledala podle nesprávného textu.
     */
    public function testSubjectCarriesTheAgendaItActuallySends(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();
        $builder = new PayrollIsdsMessageBuilder();

        $nempri = $builder->subject($catalog->require('NEMPRI'), '08/2026', '1234567890');
        self::assertStringStartsWith('NEMPRI - ', $nempri);
        self::assertStringContainsString('o žádosti zaměstnance o dávku', $nempri);
        self::assertStringNotContainsString('Jednotné měsíční hlášení', $nempri);
        self::assertStringContainsString('08/2026', $nempri);
        self::assertStringContainsString('1234567890', $nempri);

        $hzupn = $builder->subject($catalog->require('HZUPN'), '08/2026', '1234567890');
        self::assertStringStartsWith('HZUPN - ', $hzupn);
        self::assertStringContainsString('ukončení pracovní neschopnosti', $hzupn);

        $jmhz = $builder->subject($catalog->require('JMHZ25'), '07/2026', '1234567890');
        self::assertStringContainsString('Jednotné měsíční hlášení zaměstnavatele', $jmhz);
    }

    /**
     * Chybějící variabilní symbol nesmí vyrobit věc s prázdnou hodnotou —
     * to se čte jako chyba přenosu. Věc ČSSZ podle protokolu v1.47 (str. 24)
     * nezpracovává, takže bez symbolu se prostě vynechá.
     */
    public function testSubjectWithoutVariableSymbolHasNoDanglingLabel(): void
    {
        $subject = (new PayrollIsdsMessageBuilder())->subject(
            (new PayrollIsdsAgendaCatalog())->require('HZUPN'),
            '08/2026',
            null,
        );

        self::assertStringNotContainsString('VS', $subject);
        self::assertStringEndsWith('08/2026', $subject);
    }

    /** Zpráva nese doloženou schránku a holé XML, ne cokoliv jiného. */
    public function testMessageCarriesTheDocumentedRecipientAndBarePayload(): void
    {
        $catalog = new PayrollIsdsAgendaCatalog();
        $agenda = $catalog->require('NEMPRI');
        $payload = '<NEMPRI xmlns="http://schemas.cssz.cz/nem/NEMPRI25"/>';

        $message = (new PayrollIsdsMessageBuilder())->build(
            $payload,
            $agenda,
            new PayrollIsdsRecipient(
                $agenda->documentedBoxId('test'),
                'ČSSZ — e-Podání TEST',
                'test',
                $agenda->sourceNote,
            ),
            '1234567890',
            '08/2026',
            'NEMPRI-000123',
        );

        self::assertSame('9tsaf6s', $message->recipient->boxId);
        self::assertSame($payload, $message->attachmentBytes);
        self::assertSame('application/xml', $message->attachmentMimeType);
        self::assertStringStartsWith('NEMPRI_', $message->attachmentFilename);
        self::assertSame('NEMPRI-000123', $message->senderIdent);
    }
}
