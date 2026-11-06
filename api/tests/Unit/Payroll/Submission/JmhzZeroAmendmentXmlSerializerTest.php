<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroAmendmentXmlSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Anulační formulář ze zmrazeného řádného formuláře a jeho fail-closed
 * předpoklady. Výchozí data jsou syntetické řádné hlášení s jediným nulovým
 * formulářem (HPP na rodičovské dovolené).
 */
final class JmhzZeroAmendmentXmlSerializerTest extends TestCase
{
    private const SUBMISSION_GUID = '01A18238-0000-7000-8000-000000000001';
    private const FORM_GUID = '01A18238-0000-7000-8000-0000000000F1';

    public function testAnnulmentAndEffectiveSetAreSchemaValid(): void
    {
        $serializer = new JmhzZeroAmendmentXmlSerializer();
        $validator = new JmhzScenario1XmlValidator();

        $amendment = $serializer->serialize(self::regular(), [self::FORM_GUID], self::envelope());
        $validator->validateFrozen($amendment);
        $effective = $serializer->effectiveSet(self::regular(), [self::FORM_GUID]);
        $validator->validateFrozen($effective);

        self::assertStringContainsString('<typPodani>O</typPodani>', $amendment);
        self::assertStringContainsString('<typFormulare>O</typFormulare>', $amendment);
        self::assertStringContainsString(
            '<form:pojisteniOd>2026-07-01</form:pojisteniOd>' . "\n"
                . '            <form:pojisteniDo>2026-07-01</form:pojisteniDo>',
            $amendment,
        );
        self::assertStringNotContainsString('<form:kod>', $amendment);
        self::assertStringContainsString('<typPodani>R</typPodani>', $effective);
        self::assertStringContainsString('<typFormulare>R</typFormulare>', $effective);
        self::assertStringNotContainsString('<form:kod>', $effective);
    }

    public function testOriginalWithIncomeIsNotAnnulled(): void
    {
        $this->expectRefusal(
            'jmhz_zero_amendment_original_not_zero',
            str_replace('<form:mzdaZuctovana>0</form:mzdaZuctovana>', '<form:mzdaZuctovana>15000</form:mzdaZuctovana>', self::regular()),
        );
    }

    public function testOriginalWithInsuranceContributionIsNotAnnulled(): void
    {
        $this->expectRefusal(
            'jmhz_zero_amendment_original_not_zero',
            str_replace(
                '<form:pojisteniZamestnanec>' . "\n" . '            <form:socialniPojisteni>0',
                '<form:pojisteniZamestnanec>' . "\n" . '            <form:socialniPojisteni>710',
                self::regular(),
            ),
        );
    }

    public function testOtherDataScenarioIsNotAnnulled(): void
    {
        $this->expectRefusal(
            'jmhz_zero_amendment_scenario_unsupported',
            str_replace(['<form:bezPriznaku ', '</form:bezPriznaku>'], ['<form:cinnostKS ', '</form:cinnostKS>'], self::regular()),
        );
    }

    public function testPersonWithConcurrentEmploymentIsNotAnnulled(): void
    {
        $xml = self::regular();
        $start = strpos($xml, '<formularOsoby');
        $end = strpos($xml, '</formularOsoby>') + strlen('</formularOsoby>');
        self::assertNotFalse($start);
        $second = str_replace(
            [self::FORM_GUID, '400000000000000000001'],
            ['01A18238-0000-7000-8000-0000000000F2', '400000000000000000002'],
            substr($xml, $start, $end - $start),
        );

        $this->expectRefusal(
            'jmhz_zero_amendment_concurrent_employment_unsupported',
            substr($xml, 0, $end) . "\n" . $second . substr($xml, $end),
        );
    }

    public function testFormWithAnnualSummaryIsNotAnnulled(): void
    {
        $this->expectRefusal(
            'jmhz_zero_amendment_annual_summary_unsupported',
            str_replace(
                '<form:mzdaCista>' . "\n",
                '<form:rocniUhrny><form:rocniZuctovaniProvedeno>false</form:rocniZuctovaniProvedeno></form:rocniUhrny>'
                    . '<form:mzdaCista>' . "\n",
                self::regular(),
            ),
        );
    }

    public function testUnknownFormIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessageMatches('/není mezi součástmi původního podání/');
        (new JmhzZeroAmendmentXmlSerializer())->serialize(
            self::regular(),
            ['01A18238-0000-7000-8000-0000000000FF'],
            self::envelope(),
        );
    }

    private function expectRefusal(string $code, string $xml): void
    {
        try {
            (new JmhzZeroAmendmentXmlSerializer())->serialize($xml, [self::FORM_GUID], self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());

            return;
        }
        self::fail("Anulace měla skončit kódem {$code}.");
    }

    private static function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::createForExistingSubmission(
            self::SUBMISSION_GUID,
            [],
            '2026-10-28T10:00:00+01:00',
            'MyÚčto.cz',
            '0',
        );
    }

    private static function regular(): string
    {
        $xml = file_get_contents(__DIR__ . '/../../../Fixtures/jmhz/zero-month-regular.xml');
        self::assertIsString($xml);

        return str_replace("\r\n", "\n", $xml);
    }
}
