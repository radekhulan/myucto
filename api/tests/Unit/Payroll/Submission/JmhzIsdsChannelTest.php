<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsAgendaCatalog;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsMessage;
use MyInvoice\Service\Payroll\Submission\Isds\PayrollIsdsMessageBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsRecipientCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzIsdsResponseMatcher;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzTransportException;
use MyInvoice\Service\Submission\Channel\SubmissionChannelException;
use PHPUnit\Framework\TestCase;

/**
 * Podání JMHZ datovou schránkou.
 *
 * Testy jsou psané tak, aby držely DOLOŽENÉ hodnoty a doložený tvar zprávy —
 * ne aby opsaly implementaci. Sedm znaků ID schránky nemá kontrolní číslici,
 * takže překlep se jinak neodhalí než porovnáním se zdrojem.
 */
final class JmhzIsdsChannelTest extends TestCase
{
    private const PAYLOAD = '<jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0"/>';

    // ───────────────────────── adresáti ─────────────────────────

    /**
     * ČSSZ zřídila pro JMHZ vlastní schránku. Hodnota je doslovná citace ze
     * stránky komunikačních kanálů e-Podání.
     */
    public function testProductionRecipientIsDedicatedJmhzBox(): void
    {
        $recipient = JmhzIsdsRecipientCatalog::forEnvironment('production');

        self::assertSame('iie254d', $recipient->boxId);
        self::assertSame('production', $recipient->environment);
    }

    /**
     * Testovací prostředí NESMÍ mířit na ostrou schránku JMHZ — cvičné podání
     * by odešlo doopravdy a vzít zpět se nedá.
     */
    public function testTestEnvironmentUsesTestBoxNotProductionJmhzBox(): void
    {
        $recipient = JmhzIsdsRecipientCatalog::forEnvironment('test');

        self::assertSame('9tsaf6s', $recipient->boxId);
        self::assertNotSame('iie254d', $recipient->boxId);
        self::assertNotSame('5ffu6xk', $recipient->boxId);
    }

    public function testGeneralFallbackIsTheDocumentedEpodaniBox(): void
    {
        self::assertSame('5ffu6xk', JmhzIsdsRecipientCatalog::generalFallback()->boxId);
    }

    public function testUnknownEnvironmentIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(JmhzTransportException::class);

        JmhzIsdsRecipientCatalog::forEnvironment('staging');
    }

    public function testOnlyDocumentedBoxesAreRecognised(): void
    {
        self::assertTrue(JmhzIsdsRecipientCatalog::isKnownRecipient('iie254d'));
        self::assertTrue(JmhzIsdsRecipientCatalog::isKnownRecipient('5ffu6xk'));
        // Jedno písmeno vedle je pořád platné ID schránky — jen cizí.
        self::assertFalse(JmhzIsdsRecipientCatalog::isKnownRecipient('iie254e'));
    }

    // ───────────────────────── obsah zprávy ─────────────────────────

    /**
     * Protokol ČSSZ v1.47, str. 24: obsahem je „holé XML podání". Žádná GovTalk
     * obálka, žádný podpis, žádná šifra — ty patří výhradně cestě VREP.
     */
    public function testAttachmentIsTheFrozenPayloadVerbatim(): void
    {
        $message = $this->build();

        self::assertSame(self::PAYLOAD, $message->attachmentBytes);
        self::assertSame('application/xml', $message->attachmentMimeType);
        self::assertStringEndsWith('.xml', $message->attachmentFilename);
        self::assertStringNotContainsStringIgnoringCase('GovTalk', $message->attachmentBytes);
    }

    /** Zmrazený artefakt = tentýž soubor při každém sestavení. */
    public function testMessageIsDeterministic(): void
    {
        self::assertEquals($this->build(), $this->build());
    }

    /** Věc je pro člověka ve schránce — musí nést období i variabilní symbol. */
    public function testSubjectCarriesPeriodAndVariableSymbol(): void
    {
        $message = $this->build();

        self::assertStringContainsString('07/2026', $message->subject);
        self::assertStringContainsString('1234567890', $message->subject);
    }

    /**
     * Spisová značka je jediný údaj, který si volíme sami a dostaneme zpět
     * (protokol str. 24, `RecipientIdent`). Uříznout ji by tiše rozbilo
     * dohledání odeslané zprávy i párování odpovědi.
     */
    public function testTooLongSenderIdentIsRefusedNotTruncated(): void
    {
        $this->expectException(SubmissionChannelException::class);

        $this->buildWith(self::PAYLOAD, str_repeat('A', 51));
    }

    public function testNonXmlAttachmentIsRefused(): void
    {
        $this->expectException(SubmissionChannelException::class);

        $this->buildWith("PK\x03\x04zip", 'JMHZ25-1');
    }

    public function testEmptyPayloadIsRefused(): void
    {
        $this->expectException(SubmissionChannelException::class);

        $this->buildWith('   ', 'JMHZ25-1');
    }

    // ───────────────────────── párování odpovědi ─────────────────────────

    /** Tvar věci odpovědi slibuje protokol ČSSZ v1.47 na straně 24. */
    public function testDocumentedResponseSubjectIsParsed(): void
    {
        $reference = (new JmhzIsdsResponseMatcher())->parseSubject(
            'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-CID0000000001-987654321]',
        );

        self::assertNotNull($reference);
        self::assertSame('CSSZ_JMHZ', $reference->className);
        self::assertSame('CID0000000001', $reference->correlationId);
        self::assertSame('987654321', $reference->originalMessageId);
    }

    /** CorrelationID smí obsahovat pomlčku — krajní prvky se berou zvenčí. */
    public function testCorrelationIdMayContainHyphens(): void
    {
        $reference = (new JmhzIsdsResponseMatcher())->parseSubject(
            'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-a-b-c-987654321]',
        );

        self::assertNotNull($reference);
        self::assertSame('a-b-c', $reference->correlationId);
        self::assertSame('987654321', $reference->originalMessageId);
    }

    /**
     * Rozhoduje dmId NAŠÍ zprávy. Odpověď na cizí podání se nesmí přiřadit —
     * u zaměstnavatele, který podává každý měsíc, by to uzavřelo jiné období.
     */
    public function testResponseToAnotherSubmissionDoesNotMatch(): void
    {
        $matcher = new JmhzIsdsResponseMatcher();
        $subject = 'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-CID1-987654321]';

        self::assertTrue($matcher->matches($subject, '987654321', 'CSSZ_JMHZ'));
        self::assertFalse($matcher->matches($subject, '111111111', 'CSSZ_JMHZ'));
    }

    /** Odpověď na jinou agendu ČSSZ se k JMHZ nepřiřadí. */
    public function testResponseForAnotherAgendaDoesNotMatch(): void
    {
        self::assertFalse((new JmhzIsdsResponseMatcher())->matches(
            'ČSSZ - Odpověď na e-Podání. [CSSZ_REGZEC-CID1-987654321]',
            '987654321',
            'CSSZ_JMHZ',
        ));
    }

    /** Bez ID odeslané zprávy nemáme s čím porovnávat — nesmí projít nic. */
    public function testWithoutSentMessageIdNothingMatches(): void
    {
        self::assertFalse((new JmhzIsdsResponseMatcher())->matches(
            'ČSSZ - Odpověď na e-Podání. [CSSZ_JMHZ-CID1-987654321]',
            '',
        ));
    }

    public function testUnrelatedSubjectIsNotAResponse(): void
    {
        $matcher = new JmhzIsdsResponseMatcher();

        self::assertNull($matcher->parseSubject('Výzva k úhradě'));
        self::assertNull($matcher->parseSubject(null));
        self::assertNull($matcher->parseSubject('ČSSZ - Odpověď na e-Podání. [jen-dva]'));
    }

    private function build(): PayrollIsdsMessage
    {
        return $this->buildWith(self::PAYLOAD, 'JMHZ25-000123');
    }

    private function buildWith(string $payload, string $correlation): PayrollIsdsMessage
    {
        return (new PayrollIsdsMessageBuilder())->build(
            $payload,
            (new PayrollIsdsAgendaCatalog())->require('JMHZ25'),
            JmhzIsdsRecipientCatalog::forEnvironment('test'),
            '1234567890',
            '07/2026',
            $correlation,
        );
    }
}
