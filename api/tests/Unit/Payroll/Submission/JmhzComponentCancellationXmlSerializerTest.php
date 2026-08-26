<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationRequest;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellation;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzComponentCancellationXmlSerializerTest extends TestCase
{
    private const REGULAR_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F';
    private const FORM_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10';
    private const OTHER_FORM_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E11';

    public function testAmendmentCancelsComponentsAndPassesThePinnedSchema(): void
    {
        $xml = $this->serialize();

        self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
        self::assertStringContainsString('<typFormulare>S</typFormulare>', $xml);
        self::assertStringContainsString(
            '<idFormulare>' . self::FORM_GUID . '</idFormulare>',
            $xml,
        );
        $this->assertSchemaValid($xml);
    }

    /**
     * Stornující součást nese jen hlavičku (kontrola 237). Datová část by
     * tvrdila, že se něco vykazuje, přitom se ruší.
     */
    public function testCancellingComponentCarriesHeaderOnly(): void
    {
        $xml = $this->serialize();

        self::assertStringNotContainsString('<form:', $xml);
        self::assertStringNotContainsString('bezPriznaku', $xml);
        self::assertStringNotContainsString('identifikace', $xml);
    }

    /**
     * Opravné podání se váže na GUID opravovaného řádného podání; nové GUID by
     * znamenalo opravovat něco, co u ČSSZ neexistuje.
     */
    public function testAmendmentReusesTheRegularSubmissionGuid(): void
    {
        self::assertStringContainsString(
            '<idPodani>' . self::REGULAR_GUID . '</idPodani>',
            $this->serialize(),
        );
    }

    public function testEmptyAmendmentIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/neopravuje nic/');
        (new JmhzComponentCancellationXmlSerializer())->serialize(
            $this->request(),
            [],
            $this->envelope(),
        );
    }

    public function testSameEmploymentCannotBeCancelledTwiceInOneAmendment(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/víc než jednou/');
        (new JmhzComponentCancellationXmlSerializer())->serialize(
            $this->request(),
            [
                JmhzComponentCancellation::create(
                    self::FORM_GUID,
                    '1000000001',
                    '2000000000000000000001',
                ),
                JmhzComponentCancellation::create(
                    self::OTHER_FORM_GUID,
                    '1000000001',
                    '2000000000000000000001',
                ),
            ],
            $this->envelope(),
        );
    }

    public function testTwoDifferentEmploymentsCanBeCancelledTogether(): void
    {
        $xml = (new JmhzComponentCancellationXmlSerializer())->serialize(
            $this->request(),
            [
                JmhzComponentCancellation::create(
                    self::FORM_GUID,
                    '1000000001',
                    '2000000000000000000001',
                ),
                JmhzComponentCancellation::create(
                    self::OTHER_FORM_GUID,
                    '1000000012',
                    '2000000000000000000002',
                ),
            ],
            $this->envelope(),
        );

        self::assertSame(2, substr_count($xml, '<typFormulare>S</typFormulare>'));
        self::assertStringContainsString(
            '<formularePocetCelkem>2</formularePocetCelkem>',
            $xml,
        );
        $this->assertSchemaValid($xml);
    }

    public function testSerializationIsByteStable(): void
    {
        self::assertSame($this->serialize(), $this->serialize());
    }

    public function testMalformedFormGuidIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzComponentCancellation::create(
            '0195E2C41A2B4C3D8E4F5A6B7C8D9E10',
            '1000000001',
            '2000000000000000000001',
        );
    }

    public function testExistingCanonicalUuidDoesNotHaveToBeVersionSeven(): void
    {
        $component = JmhzComponentCancellation::create(
            '0195E2C4-1A2B-4C3D-8E4F-5A6B7C8D9E10',
            '1000000001',
            '2000000000000000000001',
        );

        self::assertSame(
            '0195E2C4-1A2B-4C3D-8E4F-5A6B7C8D9E10',
            $component->formGuid,
        );
    }

    public function testComponentCancellationAfterTheDeadlineIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/Lhůta pro storno/');
        JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '1234567890',
            2026,
            7,
            today: '2026-08-25',
        );
    }

    private function request(): JmhzCancellationRequest
    {
        return JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '1234567890',
            2026,
            7,
            today: '2026-08-10',
        );
    }

    private function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            self::REGULAR_GUID,
            [],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    private function serialize(): string
    {
        return (new JmhzComponentCancellationXmlSerializer())->serialize(
            $this->request(),
            [
                JmhzComponentCancellation::create(
                    self::FORM_GUID,
                    '1000000001',
                    '2000000000000000000001',
                ),
            ],
            $this->envelope(),
        );
    }

    private function assertSchemaValid(string $xml): void
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        $valid = $loaded
            && $dom->schemaValidate((new JmhzSchemaCatalog())->entryPoint()['path']);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertTrue($valid, implode('; ', array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            $errors,
        )));
    }
}
