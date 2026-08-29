<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionForm;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionPlan;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use PHPUnit\Framework\TestCase;

final class JmhzScenario1XmlSerializerTest extends TestCase
{
    public function testAcceptedFormCorrectionKeepsBothGuidsAndEmitsCompleteBody(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
            $this->resolution(),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [101 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD'],
                '2026-08-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::amendAccepted(
                    101,
                    'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD',
                    affectsSummary: true,
                    affectsPvpoj: true,
                ),
            ]),
        );

        self::assertStringContainsString(
            '<idPodani>AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB</idPodani>',
            $result['xml'],
        );
        self::assertStringContainsString('<typPodani>O</typPodani>', $result['xml']);
        self::assertStringContainsString(
            '<idFormulare>CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD</idFormulare>',
            $result['xml'],
        );
        self::assertStringContainsString('<typFormulare>O</typFormulare>', $result['xml']);
        self::assertStringContainsString('<form:bezPriznaku', $result['xml']);
        self::assertStringContainsString('<form:zuctovanoCelkem>1000</form:zuctovanoCelkem>', $result['xml']);
        self::assertStringContainsString('<so:souhrn>', $result['xml']);
        self::assertStringContainsString('<pvpoj:PVPOJ>', $result['xml']);
        self::assertStringContainsString('<formularePocetVBaliku>3</formularePocetVBaliku>', $result['xml']);
    }

    /**
     * Měsíční hlášení nese ELDP údaje — a musí je nést dál.
     *
     * Od roku 2026 zaměstnavatel samostatný evidenční list nevyhotovuje;
     * evidenční list sestaví ČSSZ právě z těchto atributů měsíčního hlášení
     * (§ 38 odst. 2 zákona č. 582/1991 Sb.). Kdyby někdo při rušení ročního
     * ELDP workflow zrušil i tenhle blok, přestala by ČSSZ mít z čeho
     * evidenční list sestavit a doba pojištění by v hlášení zmizela.
     */
    public function testMonthlyReportCarriesEldpAttributes(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringContainsString('<form:eldpSeznam>', $result['xml']);
        // 10240 kód, 10241/10242 platnost, 10356 počet dnů, 10245 vyměřovací základ
        self::assertStringContainsString('<form:kod>1++</form:kod>', $result['xml']);
        self::assertStringContainsString('<form:platnostOd>2026-07-01</form:platnostOd>', $result['xml']);
        self::assertStringContainsString('<form:platnostDo>2026-07-31</form:platnostDo>', $result['xml']);
        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $result['xml']);
        self::assertStringContainsString('<form:vymerovaciZaklad>1000</form:vymerovaciZaklad>', $result['xml']);
    }

    public function testContentCorrectionHasNoLocalBlockingControlCoverageGap(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
            $this->resolution(),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [101 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD'],
                '2026-08-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::amendAccepted(
                    101,
                    'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD',
                    affectsSummary: true,
                    affectsPvpoj: true,
                ),
            ]),
        );

        $report = JmhzScenario1ControlValidator::create(
            CzechPayrollRulesets2026::provider(),
        )->validate(
            $result['xml'],
            new JmhzControlContext('2026-08-26', schemaValidated: true),
        );

        self::assertSame([], array_map(
            static fn (JmhzControlFinding $finding): int => $finding->controlId,
            $report->coverageGaps(),
        ));
        self::assertTrue($report->submittable());
    }

    public function testRejectedFormIsResubmittedAsRWithNewGuidAndCompleteBody(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
            $this->resolution(),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [101 => '019A0000-0000-7000-8000-000000000001'],
                '2026-08-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::replaceRejected(
                    101,
                    affectsSummary: false,
                    affectsPvpoj: false,
                ),
            ]),
        );

        self::assertStringContainsString('<typPodani>O</typPodani>', $result['xml']);
        self::assertStringContainsString(
            '<idFormulare>019A0000-0000-7000-8000-000000000001</idFormulare>',
            $result['xml'],
        );
        self::assertStringContainsString('<typFormulare>R</typFormulare>', $result['xml']);
        self::assertStringContainsString('<form:bezPriznaku', $result['xml']);
        self::assertStringNotContainsString('<so:souhrn>', $result['xml']);
        self::assertStringNotContainsString('<pvpoj:PVPOJ>', $result['xml']);
        self::assertStringContainsString('<formularePocetVBaliku>1</formularePocetVBaliku>', $result['xml']);
    }

    public function testCorrectionHeaderRefusesMoreThan1502Components(): void
    {
        $method = new \ReflectionMethod(JmhzScenario1XmlSerializer::class, 'correctionHeader');

        try {
            $method->invoke(
                new JmhzScenario1XmlSerializer(),
                new \DOMDocument('1.0', 'UTF-8'),
                $this->resolution()->requireResolvedDocument()->payload,
                JmhzSubmissionEnvelope::createForExistingSubmission(
                    'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                    [101 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD'],
                    '2026-08-26T09:30:00Z',
                    'MyÚčto.cz',
                    '5.6.0',
                ),
                1503,
            );
            self::fail('Opravný balík nad 1502 součástí musí být odmítnut.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_form_limit_exceeded', $exception->validationCode);
        }
    }

    public function testCorrectionAggregatesComeFromTheWholePreparationNotSelectedForms(): void
    {
        $payload = $this->resolution()->requireResolvedDocument()->payload;
        $secondPerson = $payload['people'][0];
        $secondPerson['employee_id'] = 12;
        $secondPerson['employments'][0]['employment_id'] = 102;
        $payload['people'][] = $secondPerson;
        $payload['employer']['summary_totals']['advance_tax_after_credits'] = 999;
        $payload['employer']['pvpoj']['values']['pojistne']['pojistneCelkem'] = 888;
        $resolution = new JmhzScenario1Resolution(
            new JmhzScenario1NormalizedDocument($payload),
            [],
        );

        $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
            $resolution,
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [101 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD'],
                '2026-08-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::amendAccepted(
                    101,
                    'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD',
                    affectsSummary: true,
                    affectsPvpoj: true,
                ),
            ]),
        );

        self::assertSame(1, substr_count($result['xml'], '<formularOsoby'));
        self::assertStringContainsString(
            '<so:danZalohaPoSleve>999</so:danZalohaPoSleve>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<pvpoj:pojistneCelkem>888</pvpoj:pojistneCelkem>',
            $result['xml'],
        );
    }

    public function testAcceptedCorrectionRefusesAChangedFormGuid(): void
    {
        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('původní GUID');

        (new JmhzScenario1XmlValidator())->dryRunCorrection(
            $this->resolution(),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [101 => 'EEEEEEEE-7777-8888-8999-FFFFFFFFFFFF'],
                '2026-08-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::amendAccepted(
                    101,
                    'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD',
                    affectsSummary: false,
                    affectsPvpoj: false,
                ),
            ]),
        );
    }

    public function testResolvedProfileProducesByteStableXmlValidAgainstPinnedSchema(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertSame($this->golden(), $result['xml']);
        self::assertSame(
            hash('sha256', $this->golden()),
            $result['sha256'],
        );
        self::assertSame('jmhz-1.4.3.4', $result['schema']['package_key']);
        self::assertSame('1.4.3', $result['schema']['data_version']);
    }

    public function testRepeatedSerializationIsIdentical(): void
    {
        $validator = new JmhzScenario1XmlValidator();

        self::assertSame(
            $validator->dryRun($this->resolution(), $this->envelope())['sha256'],
            $validator->dryRun($this->resolution(), $this->envelope())['sha256'],
        );
    }

    /**
     * XSD hlídá jen tvar. Že se element jmenuje tak, jak ho pojmenoval datový
     * slovník ČSSZ, ověří až porovnání proti připnutému manifestu — jinak by
     * překlep v názvu prošel, kdyby náhodou seděl na jiný platný element.
     */
    public function testEveryEmittedFormElementMatchesPinnedDictionaryPath(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4)
                    . '/resources/payroll/jmhz/dictionary-1.4.1.6/manifest.json',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $known = [];
        foreach ($manifest['payload']['dictionary_attributes'] as $attribute) {
            $mapping = $attribute['xsd_mapping'] ?? null;
            if (!is_string($mapping)) {
                continue;
            }
            $path = preg_replace('/\s*\(ID \d+\)$/D', '', $mapping);
            if (is_string($path)) {
                $known[$path] = true;
            }
        }

        $dom = new \DOMDocument();
        $dom->loadXML(
            (new JmhzScenario1XmlValidator())
                ->dryRun($this->resolution(), $this->envelope())['xml'],
            LIBXML_NONET | LIBXML_NOBLANKS,
        );
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('form', 'http://schemas.cssz.cz/JMHZ/form/1.0');
        $leaves = $xpath->query('//form:bezPriznaku//form:*[not(*)]');
        self::assertInstanceOf(\DOMNodeList::class, $leaves);
        self::assertGreaterThan(20, $leaves->length);
        $paths = [];
        foreach ($leaves as $leaf) {
            self::assertInstanceOf(\DOMElement::class, $leaf);
            $segments = [];
            for (
                $node = $leaf;
                $node instanceof \DOMElement && $node->localName !== 'bezPriznaku';
                $node = $node->parentNode
            ) {
                array_unshift($segments, $node->localName);
            }
            $paths[] = implode('.', $segments);
        }

        $unknown = array_values(array_filter(
            array_unique($paths),
            static fn (string $path): bool => !isset($known[$path]),
        ));
        self::assertSame([], $unknown);
    }

    /**
     * Zaměstnanec s podepsaným prohlášením je běžný případ, ne okrajový —
     * dokud rozpad slev nešel vykázat, blokoval se prakticky každý.
     */
    public function testSignedDeclarationWithCreditEmitsBreakdownAndStaysXsdValid(): void
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
        $tax['applied_non_refundable_credits_minor_units'] = 257_000;
        $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
        $tax['advance_tax']['tax_before_credits_minor_units'] = 272_000;
        $tax['advance_tax']['tax_after_credits_minor_units'] = 15_000;
        unset($tax);
        $payload['people'][0]['employments'][0]['term']
            ['tax_declaration_signed'] = true;

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:prohlaseniPoplatnika>true</form:prohlaseniPoplatnika>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:zakladniSleva>2570</form:zakladniSleva>',
            $result['xml'],
        );
        self::assertStringNotContainsString('zakladniSlevaInvalidita12', $result['xml']);
    }

    public function testCreditWithoutSignedDeclarationIsRefused(): void
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['claimed_non_refundable_credits_minor_units'] = 257_000;
        $tax['applied_non_refundable_credits_minor_units'] = 257_000;
        $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 257_000];
        $tax['advance_tax']['non_refundable_credits_minor_units'] = 257_000;
        unset($tax);

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Sleva bez podepsaného prohlášení musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_credit_without_declaration',
                $exception->validationCode,
            );
        }
    }

    public function testBlockedResolutionIsNeverSerialized(): void
    {
        $payload = $this->payload();
        unset($payload['ordinary_evidence']);

        $this->expectException(JmhzXmlException::class);
        $this->expectExceptionMessage('Blokovaný dokument nelze serializovat');
        (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );
    }

    public function testUnverifiedTristateIsNotTreatedAsNo(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']
            ['jmhz_functional_benefits_status'] = 'unverified';

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Neověřený tri-state musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10247', $exception->getMessage());
        }
    }

    public function testMissingFrozenAttributeIsNeverFilledWithZero(): void
    {
        $payload = $this->payload();
        unset(
            $payload['people'][0]['employments'][0]['work_month']
                ['jmhz_work_summary']['values']['evidence_days'],
        );

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('Chybějící zmrazený atribut musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10265', $exception->getMessage());
        }
    }

    public function testEldpSectionWithDaysRequiresCode(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'] = null;

        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
            self::fail('ELDP sekce s dny bez kódu musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_eldp_code_required', $exception->validationCode);
        }
    }

    public function testSubthresholdDppSerializesIncomeAndCodeLessZeroDayEldp(): void
    {
        $payload = $this->payload();
        $person = &$payload['people'][0];
        $person['person_summary']['totals']['jmhz_amount_minor'] = 640_000;
        $person['person_summary']['statutory']['social_insurance']['capped_assessment_base_minor_units'] = 0;
        $person['person_summary']['statutory']['social_insurance']['employee_contribution_minor_units'] = 0;
        $person['person_summary']['statutory']['social_insurance']['employer_contribution_minor_units'] = 0;
        $employment = &$person['employments'][0];
        $employment['term']['activity_code'] = 'T';
        $employment['term']['jmhz_relationship_detail_code'] = null;
        $employment['insurance'] = [
            'relationship_id' => 'employment:101',
            'kind' => 'dpp',
            'participation' => [
                'relationship_id' => 'employment:101',
                'status' => 'does_not_participate',
                'participation_income_minor_units' => 640_000,
            ],
            'assessment_base_minor_units' => 0,
            'capped_assessment_base_minor_units' => 0,
        ];
        $section = &$employment['eldp']['eldp_sections'][0];
        $section['code'] = null;
        $section['valid_from'] = null;
        $section['valid_to'] = null;
        $section['insurance_days'] = 0;
        $section['assessment_base_czk'] = null;
        unset($section, $employment, $person);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload, $this->zeroPvpoj()),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:prijemNepojistenaCinnost>6400</form:prijemNepojistenaCinnost>',
            $result['xml'],
        );
        self::assertStringContainsString('<form:pocetDnu>0</form:pocetDnu>', $result['xml']);
        self::assertStringNotContainsString('<form:castkaOdvodPojistneho>', $result['xml']);
        self::assertStringNotContainsString('<form:kod>', $result['xml']);
        self::assertStringNotContainsString('<form:platnostOd>', $result['xml']);
        self::assertStringNotContainsString('<form:pojisteniZamestnanec>', $result['xml']);
        self::assertStringNotContainsString('<form:pojisteniZamestnavatel>', $result['xml']);
    }

    public function testNonUuidV7GuidIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-4c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    public function testSharedGuidBetweenSubmissionAndFormIsRefused(): void
    {
        $guid = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F';

        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            $guid,
            [101 => $guid],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    public function testNonCanonicalFilledAtIsRefused(): void
    {
        $this->expectException(JmhzXmlException::class);
        JmhzSubmissionEnvelope::create(
            '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05 09:30:00',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    /**
     * Uplatněná sleva podle § 7a musí projít až do XML. Bez rozpadu 10372,
     * 10373 a 10374 se podání zastavilo v přípravě, takže zaměstnavatel, který
     * měl na slevu nárok, nemohl měsíční hlášení podat vůbec.
     */
    public function testAppliedPartTimeDiscountIsSerializedAndValidAgainstSchema(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payloadWithDiscount(), $this->discountPvpoj()),
            $this->envelope(),
        );

        self::assertStringContainsString(
            <<<'XML'
                          <form:slevaZamestnavatele>
                            <form:slevaZamestnavateleEvidovana>true</form:slevaZamestnavateleEvidovana>
                            <form:slevaZamestnavateleRozpad>
                              <form:pracovniDobaKratsi>20.00</form:pracovniDobaKratsi>
                              <form:duvodUplatneni>A</form:duvodUplatneni>
                            </form:slevaZamestnavateleRozpad>
                          </form:slevaZamestnavatele>
                XML,
            $result['xml'],
        );
        // § 7c odst. 1 odečítá slevu z pojistného za všechny kategorie § 5a
        // dohromady, takže částka patří jen do pojistné části, ne k součásti.
        self::assertStringNotContainsString('form:pojistneSleva', $result['xml']);
        self::assertStringContainsString(
            '<pvpoj:pojistneSleva>50</pvpoj:pojistneSleva>',
            $result['xml'],
        );
    }

    /**
     * § 7a odst. 2 váže podmínku kratší pracovní doby jen na písmena a) až f).
     * Zaměstnanci mladšímu 21 let podle písmene g) sleva náleží i při plném
     * úvazku a kontrola 138 ČSSZ u něj rozsah zakazuje.
     */
    public function testUnder21DiscountOmitsShorterWorkingTime(): void
    {
        $payload = $this->payloadWithDiscount();
        $payload['people'][0]['employments'][0]['insurance']
            ['part_time_employer_discount_reason'] = 'under_21';

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:duvodUplatneni>G</form:duvodUplatneni>',
            $result['xml'],
        );
        self::assertStringNotContainsString('pracovniDobaKratsi', $result['xml']);
    }

    public function testDiscountWithoutAgreedWeeklyWorkingTimeIsBlocked(): void
    {
        $payload = $this->payloadWithDiscount();
        unset(
            $payload['people'][0]['employments'][0]['insurance']
                ['agreed_weekly_working_millihours'],
        );

        $resolution = $this->resolutionFor($payload);

        self::assertContains(
            'jmhz_employer_part_time_discount_working_time_unresolved',
            array_map(
                static fn (object $blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    /**
     * Kontrola 42 ČSSZ pouští slevu jen k druhu činnosti „1" až „9", tedy
     * k pracovnímu poměru. Dohoda o pracovní činnosti ji uplatnit nesmí
     * a z hotového XML se to už poznat nedá.
     */
    public function testDiscountOutsideEmploymentActivityIsBlocked(): void
    {
        $payload = $this->payloadWithDiscount();
        $payload['people'][0]['employments'][0]['scenario_resolution'] = [
            'scenario_key' => 'scenario_1',
            'activity_code' => 'A',
            'relationship_detail_code' => null,
        ];

        $resolution = $this->resolutionFor($payload);

        self::assertContains(
            'jmhz_employer_part_time_discount_activity_unsupported',
            array_map(
                static fn (object $blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    /**
     * Souběh sazbových kategorií § 5a odst. 1 se slevou: rozpad základu jde
     * u každé součásti pod jiné písmeno, ale sleva zůstane u té jediné, která
     * ji uplatňuje.
     */
    public function testTwoRateCategoriesWithDiscountStayValidAgainstSchema(): void
    {
        $payload = $this->payloadWithDiscount();
        $second = $payload['people'][0];
        $second['employee_id'] = 12;
        $second['employments'][0]['employment_id'] = 102;
        $second['employments'][0]['identity']['person_external_identifier']['value']
            = '1000000012';
        $second['employments'][0]['identity']['jmhz_employment_external_identifier']['value']
            = '2000000000000000000002';
        $second['employments'][0]['insurance'] = [
            'relationship_id' => 'employment:102',
            'capped_assessment_base_minor_units' => 100_000,
            'employer_rate_category' => 'risk_employment',
            'part_time_employer_discount' => 'not_claimed',
        ];
        $payload['people'][] = $second;
        $payload['ordinary_evidence'][] = [
            'scope' => ['employee_id' => 12, 'employment_id' => 102],
            'attribute_values' => ['10116' => false, '10546' => false],
        ];

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            JmhzSubmissionEnvelope::create(
                '0195e2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
                [
                    101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10',
                    102 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E11',
                ],
                '2026-08-05T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
        );

        self::assertStringContainsString('<form:pismenoA>1000</form:pismenoA>', $result['xml']);
        self::assertStringContainsString('<form:pismenoC>1000</form:pismenoC>', $result['xml']);
        self::assertSame(
            1,
            substr_count($result['xml'], '<form:slevaZamestnavateleEvidovana>true</form:slevaZamestnavateleEvidovana>'),
        );
    }

    /** @return array<string,mixed> */
    private function payloadWithDiscount(): array
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['scenario_resolution'] = [
            'scenario_key' => 'scenario_1',
            'activity_code' => '1',
            'relationship_detail_code' => '1',
        ];
        $payload['people'][0]['employments'][0]['insurance'] += [
            'part_time_employer_discount' => 'verified',
            'part_time_employer_discount_outcome' => 'applied',
            'part_time_employer_discount_reason' => 'age_55_plus',
            'part_time_employer_discount_evidence_reference' => 'employment:101:2026-07',
            'agreed_weekly_working_millihours' => 20_000,
        ];

        return $payload;
    }

    private function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2026-08-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    private function resolution(): \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution
    {
        return $this->resolutionFor($this->payload());
    }

    /** @param array<string,mixed> $payload */
    private function resolutionFor(
        array $payload,
        ?JmhzPvpojPreview $pvpoj = null,
    ): \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution {
        $preparation = new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            '2026-07-01',
            '2026-07-31',
            'scenario_1',
            JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
            [],
            [
                'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
                'status' => 'source_ready',
                'issue_count' => 0,
                'issues' => [],
                'official_submission_supported' => false,
            ],
            $payload,
        );

        return (new JmhzScenario1DocumentResolver())->resolve(
            $preparation,
            $pvpoj ?? $this->pvpoj(),
        );
    }

    /**
     * Pojistná část s uplatněnou slevou: 5 % z vyměřovacího základu 1 000 Kč
     * zaokrouhlených nahoru je 50 Kč a o tutéž částku klesá pojistné k úhradě.
     */
    private function discountPvpoj(): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            [
                'office_id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'variable_symbol' => '1234567890',
            ],
            [[
                'office_id' => 4,
                'employee_contribution_minor_units' => 7_100,
                'employer_contribution_minor_units' => 24_800,
                'amount_minor_units' => 31_900,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnavateleCelkem' => 248,
                    'pojistneZamestnance' => 71,
                    'pojistneCelkem' => 319,
                ],
                'slevaZamestnavatele' => [
                    'pocetZamestnancu' => 1,
                    'uhrnVymerovacichZakladu' => 1_000,
                    'pojistneSleva' => 50,
                ],
                'pojistneUhrada' => 269,
            ],
            [['employee_id' => 11]],
        );
    }

    private function pvpoj(): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            [
                'office_id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'variable_symbol' => '1234567890',
            ],
            [[
                'office_id' => 4,
                'employee_contribution_minor_units' => 7_100,
                'employer_contribution_minor_units' => 24_800,
                'amount_minor_units' => 31_900,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnavateleCelkem' => 248,
                    'pojistneZamestnance' => 71,
                    'pojistneCelkem' => 319,
                ],
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }

    private function zeroPvpoj(): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            '2026-07',
            [
                'office_id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'variable_symbol' => '1234567890',
            ],
            [[
                'office_id' => 4,
                'employee_contribution_minor_units' => 0,
                'employer_contribution_minor_units' => 0,
                'amount_minor_units' => 0,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'pojistneZamestnavateleCelkem' => 0,
                    'pojistneZamestnance' => 0,
                    'pojistneCelkem' => 0,
                ],
                'pojistneUhrada' => 0,
            ],
            [['employee_id' => 11]],
        );
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'schema_reference' => 'payroll-jmhz-preparation-source.v5',
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scenario_set' => ['scenario_1'],
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'ordinary_evidence' => [[
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ]],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [['relationship_id' => 'employment:101']],
                            'net_before_deductions_minor_units' => 73_400,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 73_400,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => [
                            'value' => '2000000000000000000001',
                        ],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                        'work_place' => 'Brno',
                        'jmhz_workplace_municipality_code' => '582786',
                        'jmhz_workplace_country_code' => 'CZ',
                        'jmhz_apz_contribution_status' => 'no',
                        'jmhz_functional_benefits_status' => 'no',
                        'jmhz_temporary_assignment_status' => 'no',
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => [
                        'confirmation' => ['in03_active' => false, 'in04_active' => false],
                        'insurance_interval' => [
                            'insurance_from' => '2026-07-01',
                            'insurance_to' => '2026-07-31',
                        ],
                        'eldp_sections' => [[
                            'ordinal' => 1,
                            'code' => '1++',
                            'valid_from' => '2026-07-01',
                            'valid_to' => '2026-07-31',
                            'insurance_days' => 31,
                            'assessment_base_czk' => 1_000,
                            'excluded_days' => null,
                            'deducted_days' => null,
                        ]],
                    ],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'derivation_version' => 'jmhz-work-month.v2',
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'standard_fund_millihours' => 184_000,
                                'agreed_fund_millihours' => 184_000,
                                'weekly_work_centihours' => 4_000,
                                'evidence_days' => 31,
                                'worked_millihours' => 184_000,
                                'unworked_total_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => [
                        'relationship_id' => 'employment:101',
                        'kind' => 'employment',
                        'participation' => [
                            'relationship_id' => 'employment:101',
                            'status' => 'participates',
                            'participation_income_minor_units' => 100_000,
                        ],
                        'assessment_base_minor_units' => 100_000,
                        'capped_assessment_base_minor_units' => 100_000,
                        'employer_rate_category' => 'ordinary',
                    ],
                ]],
            ]],
            'source_versions' => [
                'office_id' => 9,
                'employments' => [],
                'ordinary_evidence' => [[
                    'employment_id' => 101,
                    'id' => 601,
                    'source_manifest_sha256' => str_repeat('4', 64),
                    'snapshot_fingerprint' => str_repeat('5', 64),
                ]],
            ],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
    }

    private function golden(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <jmhz xmlns="http://schemas.cssz.cz/JMHZ/podani/1.0" xmlns:so="http://schemas.cssz.cz/JMHZ/souhrn/1.0" xmlns:pvpoj="http://schemas.cssz.cz/JMHZ/PVPOJ/1.0" xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0" verze="1.4.3">
              <VENDOR productName="MyÚčto.cz" productVersion="5.6.0"/>
              <hlavicka>
                <idPodani>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F</idPodani>
                <typPodani>R</typPodani>
                <variabilniSymbol>1234567890</variabilniSymbol>
                <mesic>7</mesic>
                <rok>2026</rok>
                <datumVyplneni>2026-08-05T09:30:00Z</datumVyplneni>
                <balikPoradi>1</balikPoradi>
                <balikyPocet>1</balikyPocet>
                <formularePocetVBaliku>3</formularePocetVBaliku>
                <formularePocetCelkem>3</formularePocetCelkem>
              </hlavicka>
              <so:souhrn>
                <so:danUdajeMesic>
                  <so:danZalohaPoSleve>150</so:danZalohaPoSleve>
                  <so:danBonus>0</so:danBonus>
                </so:danUdajeMesic>
              </so:souhrn>
              <pvpoj:PVPOJ>
                <pvpoj:pojistne>
                  <pvpoj:zakladZamestnavateleA>1000</pvpoj:zakladZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleA>248</pvpoj:pojistneZamestnavateleA>
                  <pvpoj:pojistneZamestnavateleCelkem>248</pvpoj:pojistneZamestnavateleCelkem>
                  <pvpoj:pojistneZamestnance>71</pvpoj:pojistneZamestnance>
                  <pvpoj:pojistneCelkem>319</pvpoj:pojistneCelkem>
                </pvpoj:pojistne>
                <pvpoj:pojistneUhrada>319</pvpoj:pojistneUhrada>
              </pvpoj:PVPOJ>
              <formulareOsob>
                <formularOsoby xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0">
                  <hlavicka>
                    <idFormulare>0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10</idFormulare>
                    <typFormulare>R</typFormulare>
                    <primarniPpv>true</primarniPpv>
                  </hlavicka>
                  <form:bezPriznaku xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0">
                    <form:identifikace>
                      <form:ikMpsv>1000000001</form:ikMpsv>
                      <form:idPpv>2000000000000000000001</form:idPpv>
                    </form:identifikace>
                    <form:souhrnDataZec>
                      <form:prijmy>
                        <form:zuctovanoCelkem>1000</form:zuctovanoCelkem>
                      </form:prijmy>
                      <form:zalohaNaDan>
                        <form:zakladDane>1000</form:zakladDane>
                        <form:vypoctenaZaloha>150</form:vypoctenaZaloha>
                        <form:danZalohaPoSleve>150</form:danZalohaPoSleve>
                        <form:danBonus>0</form:danBonus>
                      </form:zalohaNaDan>
                      <form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>
                      <form:mzdaCista>
                        <form:mzdaCista>734</form:mzdaCista>
                        <form:srazkyZeMzdyEvidovany>false</form:srazkyZeMzdyEvidovany>
                      </form:mzdaCista>
                      <form:zdravPojZamestnavatel>
                        <form:zdravotniPojisteni>90</form:zdravotniPojisteni>
                      </form:zdravPojZamestnavatel>
                      <form:zdravPojZamestnanec>
                        <form:zdravotniPojisteni>45</form:zdravotniPojisteni>
                      </form:zdravPojZamestnanec>
                    </form:souhrnDataZec>
                    <form:pojisteni>
                      <form:trvani>
                        <form:pojisteniOd>2026-07-01</form:pojisteniOd>
                        <form:pojisteniDo>2026-07-31</form:pojisteniDo>
                      </form:trvani>
                      <form:vymerovaciZaklad>
                        <form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>
                        <form:prijemNepojistenaCinnost>1000</form:prijemNepojistenaCinnost>
                      </form:vymerovaciZaklad>
                      <form:vymerovaciZakladParagraf5>
                        <form:pismenoA>1000</form:pismenoA>
                      </form:vymerovaciZakladParagraf5>
                      <form:eldpSeznam>
                        <form:eldp>
                          <form:kod>1++</form:kod>
                          <form:platnostOd>2026-07-01</form:platnostOd>
                          <form:platnostDo>2026-07-31</form:platnostDo>
                          <form:pocetDnu>31</form:pocetDnu>
                          <form:vymerovaciZaklad>1000</form:vymerovaciZaklad>
                        </form:eldp>
                      </form:eldpSeznam>
                      <form:pojisteniZamestnanec>
                        <form:socialniPojisteni>71</form:socialniPojisteni>
                      </form:pojisteniZamestnanec>
                      <form:pojisteniZamestnavatel>
                        <form:socialniPojisteni>248</form:socialniPojisteni>
                      </form:pojisteniZamestnavatel>
                    </form:pojisteni>
                    <form:vykonavanaPozice>
                      <form:mistoVykonuPrace>
                        <form:obec>Brno</form:obec>
                        <form:kodObce>582786</form:kodObce>
                        <form:kodStatu>CZ</form:kodStatu>
                      </form:mistoVykonuPrace>
                      <form:uplatnujiPrispevekApz>false</form:uplatnujiPrispevekApz>
                      <form:funkcniPozitky>false</form:funkcniPozitky>
                      <form:docasnePrideleniEvidovano>false</form:docasnePrideleniEvidovano>
                      <form:fondPracovniDoby>
                        <form:stanovenyFond>184.000</form:stanovenyFond>
                        <form:sjednanyFond>184.000</form:sjednanyFond>
                        <form:stanovenaTydenniDoba>40.00</form:stanovenaTydenniDoba>
                      </form:fondPracovniDoby>
                    </form:vykonavanaPozice>
                    <form:prubehZamestnani>
                      <form:odpracovaneDny>
                        <form:dnyEvidencniStav>31</form:dnyEvidencniStav>
                      </form:odpracovaneDny>
                      <form:odpracovaneHodiny>
                        <form:pocet>184.000</form:pocet>
                      </form:odpracovaneHodiny>
                    </form:prubehZamestnani>
                    <form:prijem>
                      <form:dan>
                        <form:zakladDane>1000</form:zakladDane>
                      </form:dan>
                    </form:prijem>
                    <form:mzda>
                      <form:mzdaZuctovana>1000</form:mzdaZuctovana>
                      <form:mzdaRozpad>
                        <form:tarif>1000</form:tarif>
                        <form:odmenyPravidelne>0</form:odmenyPravidelne>
                        <form:odmenyNepravidelne>0</form:odmenyNepravidelne>
                      </form:mzdaRozpad>
                      <form:vydelek>
                        <form:vydelekPrumernyHod>275.50</form:vydelekPrumernyHod>
                      </form:vydelek>
                    </form:mzda>
                  </form:bezPriznaku>
                </formularOsoby>
              </formulareOsob>
            </jmhz>
            XML;
    }
}
