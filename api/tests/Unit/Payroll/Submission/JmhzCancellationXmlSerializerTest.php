<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use DOMDocument;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationRequest;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzCancellationXmlSerializerTest extends TestCase
{
    private const REGULAR_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F';

    public function testCancellationCarriesOnlyTheHeaderAndPassesThePinnedSchema(): void
    {
        $xml = $this->serialize();

        self::assertStringContainsString('<typPodani>S</typPodani>', $xml);
        // Jmenné prostory na kořeni zůstávají (deklaruje je schéma), ale žádná
        // z částí podání se nesmí objevit jako element.
        self::assertStringNotContainsString('<so:souhrn', $xml);
        self::assertStringNotContainsString('<pvpoj:PVPOJ', $xml);
        self::assertStringNotContainsString('<formulareOsob', $xml);
        // Počty balíků a formulářů se u storna neuvádějí — nic se neposílá.
        self::assertStringNotContainsString('balikPoradi', $xml);
        self::assertStringNotContainsString('formularePocet', $xml);
        $this->assertSchemaValid($xml);
    }

    /**
     * Storno se váže na GUID rušeného řádného podání. Vygenerovat nový by
     * znamenalo stornovat něco, co u ČSSZ neexistuje.
     */
    public function testCancellationReusesTheGuidOfTheCancelledSubmission(): void
    {
        self::assertStringContainsString(
            '<idPodani>' . self::REGULAR_GUID . '</idPodani>',
            $this->serialize(),
        );
    }

    public function testCancellationWithAForeignGuidIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/GUID rušeného řádného podání/');
        (new JmhzCancellationXmlSerializer())->serialize(
            $this->request(),
            JmhzSubmissionEnvelope::create(
                '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E11',
                [],
                '2026-08-05T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
        );
    }

    public function testCancellationCarriesNoFormGuids(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/neobsahuje součásti/');
        (new JmhzCancellationXmlSerializer())->serialize(
            $this->request(),
            JmhzSubmissionEnvelope::create(
                self::REGULAR_GUID,
                [5 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
                '2026-08-05T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
        );
    }

    public function testSerializationIsByteStable(): void
    {
        self::assertSame($this->serialize(), $this->serialize());
    }

    /**
     * Po dvacátém už storno nejde — napravit se to dá jen opravným hlášením.
     * Odeslané storno po lhůtě by u ČSSZ zrušilo víc, než uživatel čeká.
     */
    public function testCancellationAfterTheDeadlineIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/Lhůta pro storno/');
        JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '1234567890',
            2026,
            7,
            deadlines: $this->deadlines(),
            today: '2026-08-25',
        );
    }

    public function testCancellationWithinTheWindowIsAccepted(): void
    {
        $request = JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '1234567890',
            2026,
            7,
            deadlines: $this->deadlines(),
            today: '2026-08-20',
        );

        self::assertSame(7, $request->month);
        self::assertSame(2026, $request->year);
    }

    public function testCancellationOfTransitionMonthsIsAlwaysRefused(): void
    {
        foreach ([1, 2, 3] as $month) {
            try {
                JmhzCancellationRequest::create(
                    self::REGULAR_GUID,
                    '1234567890',
                    2026,
                    $month,
                    deadlines: $this->deadlines(),
                    today: '2026-05-15',
                );
                self::fail("Storno za {$month}/2026 musí být odmítnuto.");
            } catch (JmhzXmlException $exception) {
                self::assertSame(
                    'jmhz_cancellation_transition_period_forbidden',
                    $exception->validationCode,
                );
            }
        }
    }

    public function testInvalidVariableSymbolIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '12345',
            2026,
            7,
            deadlines: $this->deadlines(),
            today: '2026-08-10',
        );
    }

    private function request(): JmhzCancellationRequest
    {
        return JmhzCancellationRequest::create(
            self::REGULAR_GUID,
            '1234567890',
            2026,
            7,
            deadlines: $this->deadlines(),
            today: '2026-08-10',
        );
    }

    private function deadlines(): JmhzDeadlinePolicy
    {
        return new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider());
    }

    private function serialize(): string
    {
        return (new JmhzCancellationXmlSerializer())->serialize(
            $this->request(),
            JmhzSubmissionEnvelope::create(
                self::REGULAR_GUID,
                [],
                '2026-08-05T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
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
