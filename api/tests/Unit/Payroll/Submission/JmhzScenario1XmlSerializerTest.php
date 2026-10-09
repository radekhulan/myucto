<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionForm;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionPlan;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlFinding;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzWholeHours;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Tests\Support\JmhzControlValidatorFactory;
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

    /**
     * Vyloučené doby § 16 odst. 4 písm. a) zákona č. 155/1995 Sb.
     *
     * Nemoc dobu pojištění nepřerušuje — 10356 zůstává celý měsíc — a do
     * evidenčního listu jde jako vyloučená doba. Kdyby v hlášení chyběla,
     * dvanáct dnů bez příjmu by ČSSZ započetla do osobního vyměřovacího
     * základu jako vydělané nula a snížila by tím důchod.
     */
    public function testSicknessIsReportedAsEldpExcludedDays(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['excluded_days'] = [
            'docasNeschopnost' => 12,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 0,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        $section['excluded_days_total'] = 12;
        $section['deducted_days_total'] = null;
        unset($section);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString('<form:pocetDnu>31</form:pocetDnu>', $result['xml']);
        self::assertStringContainsString(
            '<form:vylouceneDny><form:vylouceneDobyCelkem>12</form:vylouceneDobyCelkem>'
                . '<form:docasNeschopnost>12</form:docasNeschopnost>'
                . '<form:penezitaPomocMaterstvi>0</form:penezitaPomocMaterstvi>'
                . '<form:osetrovaniClenaRodiny>0</form:osetrovaniClenaRodiny>'
                . '<form:otcovska>0</form:otcovska>'
                . '<form:vyloucenePar16>0</form:vyloucenePar16></form:vylouceneDny>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
        // Odečítané doby po důchodovém věku modul neodvozuje, a proto je
        // neuvádí — nula by tvrdila víc, než čím je doložená.
        self::assertStringNotContainsString('<form:odecitaneDny>', $result['xml']);
    }

    /**
     * Nulový úhrn se uvádí bez rozpadu: kontrola 329 ČSSZ zakazuje vyplněné
     * složky při 10357 = 0 a nulový rozpad nenese žádnou informaci.
     */
    public function testZeroExcludedDaysAreReportedWithoutBreakdown(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['excluded_days'] = [
            'docasNeschopnost' => 0,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 0,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        $section['excluded_days_total'] = 0;
        unset($section);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:vylouceneDny><form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem></form:vylouceneDny>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
        self::assertStringNotContainsString('<form:docasNeschopnost>', $result['xml']);
    }

    public function testExcludedDaysSumMismatchBlocksSubmission(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['excluded_days'] = [
            'docasNeschopnost' => 12,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 0,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        $section['excluded_days_total'] = 11;
        unset($section);

        try {
            $this->refuse($payload);
            self::fail('Rozporný úhrn vyloučených dob musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_eldp_excluded_days_sum_mismatch', $exception->validationCode);
        }
    }

    /**
     * Vyloučené dny podle § 18 odst. 7 zákona č. 187/2006 Sb. krátí rozhodné
     * období denního vyměřovacího základu nemocenských dávek. Bez nich počítá
     * ČSSZ dávku i z měsíce, ve kterém zaměstnanec kvůli neplacenému volnu
     * nevydělával, a vyplatí méně, než na kolik má zaměstnanec nárok.
     */
    public function testUnpaidLeaveIsReportedAsSection18ExcludedDays(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        // Neplacené volno není omluvným důvodem podle § 16 odst. 4 zákona
        // č. 155/1995 Sb., takže vyloučené DOBY zůstávají nulové a celý údaj
        // nese až § 18 odst. 7.
        $section['excluded_days'] = [
            'docasNeschopnost' => 0,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 0,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        $section['excluded_days_total'] = 0;
        $section['section18_days'] = [
            'omluvenaNepritomnost' => 3,
            'pracovniNeschopnost' => 0,
            'vyplaceniDavek' => 0,
        ];
        $section['section18_days_total'] = 3;
        unset($section);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:vylouceneDobyCelkem>0</form:vylouceneDobyCelkem>'
                . '<form:vyloucenePar18>3</form:vyloucenePar18>'
                . '<form:omluvenaNepritomnost>3</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>0</form:pracovniNeschopnost>'
                . '<form:vyplaceniDavek>0</form:vyplaceniDavek>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
    }

    /**
     * Sekce bez kódu ELDP (poživatel starobního důchodu) vyloučené doby § 16
     * nenese (kontrola 307), vyloučené dny § 18 odst. 7 ale ano — jsou to
     * údaje nemocenského pojištění. Dřív je serializér v sekci bez kódu tiše
     * zahodil.
     */
    public function testCodelessSectionStillCarriesSection18Days(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['code'] = null;
        $section['valid_from'] = null;
        $section['valid_to'] = null;
        $section['insurance_days'] = 0;
        $section['assessment_base_czk'] = null;
        $section['excluded_days'] = null;
        $section['excluded_days_total'] = null;
        $section['section18_days'] = [
            'omluvenaNepritomnost' => 0,
            'pracovniNeschopnost' => 12,
            'vyplaceniDavek' => 0,
        ];
        $section['section18_days_total'] = 12;
        unset($section);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:eldp><form:pocetDnu>0</form:pocetDnu><form:vylouceneDny>'
                . '<form:vyloucenePar18>12</form:vyloucenePar18>'
                . '<form:omluvenaNepritomnost>0</form:omluvenaNepritomnost>'
                . '<form:pracovniNeschopnost>12</form:pracovniNeschopnost>'
                . '<form:vyplaceniDavek>0</form:vyplaceniDavek></form:vylouceneDny></form:eldp>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
        self::assertSame([], $this->failedControls($result['xml'], [307, 330]));
    }

    /**
     * `null` v řezu znamená NEUVEDENO. Datový slovník předepisuje
     * 10366 = 10473 + 10474 + 10475, takže vykázat část rozpadu by tvrdilo,
     * že zbytek je nula — a to je stejná chyba jako mlčení, jen hůř
     * rozpoznatelná.
     */
    public function testSection18DaysAreOmittedWhenTheBreakdownIsNotDerivable(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<form:vyloucenePar18>', $result['xml']);
        self::assertStringNotContainsString('<form:omluvenaNepritomnost>', $result['xml']);
    }

    public function testSection18DaysSumMismatchBlocksSubmission(): void
    {
        $payload = $this->payload();
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['section18_days'] = [
            'omluvenaNepritomnost' => 3,
            'pracovniNeschopnost' => 0,
            'vyplaceniDavek' => 0,
        ];
        $section['section18_days_total'] = 4;
        unset($section);

        try {
            $this->refuse($payload);
            self::fail('Rozporný úhrn vyloučených dnů musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_eldp_section18_days_sum_mismatch',
                $exception->validationCode,
            );
        }
    }

    /**
     * Příplatky, náhrady a odměna za pohotovost mají v hlášení vlastní bloky.
     * Bez nich se měsíc s dovolenou vykáže jako měsíc s nulovou mzdou a beze
     * stopy po tom, co zaměstnanec dostal — MPSV ani ČSSZ nedostanou nic, co
     * by odpovídalo výplatní pásce.
     */
    public function testSurchargesCompensationsAndStandbyPayAreReported(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payloadWithWageBreakdown()),
            $this->envelope(),
        );
        $xml = preg_replace('/>\s+</', '><', $result['xml']) ?? '';

        self::assertStringContainsString(
            '<form:mzdaRozpad><form:tarif>800</form:tarif>'
                . '<form:odmenyPravidelne>0</form:odmenyPravidelne>'
                . '<form:odmenyNepravidelne>0</form:odmenyNepravidelne>'
                . '<form:priplatky><form:celkem>200</form:celkem>'
                . '<form:prescas>120</form:prescas>'
                . '<form:nocni>80</form:nocni>'
                . '<form:sobotaNedele>0</form:sobotaNedele>'
                . '<form:svatek>0</form:svatek></form:priplatky></form:mzdaRozpad>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:nahrady><form:mzdyZuctovane>500</form:mzdyZuctovane>'
                . '<form:dovolena>500</form:dovolena>'
                . '<form:svatky>0</form:svatky>'
                . '<form:prekazkyZamestnavatel>0</form:prekazkyZamestnavatel>'
                . '<form:prekazkyZamestnanec>0</form:prekazkyZamestnanec>'
                . '<form:docasnaNeschopnost>0</form:docasnaNeschopnost></form:nahrady>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:odmeny><form:pohotovost>150</form:pohotovost></form:odmeny>',
            $xml,
        );
    }

    /**
     * Náhrada při dočasné pracovní neschopnosti stojí VEDLE úhrnu zúčtovaných
     * náhrad, ne pod ním: měsíc, ve kterém byla zúčtovaná jen ona, má
     * `mzdyZuctovane` nula.
     */
    public function testSicknessCompensationStaysOutsideTheCompensationTotal(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10342']
            = 120_000;

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:nahrady><form:mzdyZuctovane>0</form:mzdyZuctovane>'
                . '<form:docasnaNeschopnost>1200</form:docasnaNeschopnost></form:nahrady>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
    }

    /**
     * Kontrola 267 zakazuje vyplněný rozpad při nulové zúčtované mzdě, a
     * „vyplněný" je pro ČSSZ už samotná přítomnost elementu. Příplatky jsou
     * v jejím výčtu (10332–10336) stejně jako tarif.
     */
    public function testSurchargeWithoutWageIsRefused(): void
    {
        $payload = $this->payload();
        $earnings = &$payload['people'][0]['employments'][0]['earnings_by_attribute_minor'];
        $earnings['10328'] = 0;
        $earnings['10329'] = 0;
        $earnings['10332'] = 20_000;
        unset($earnings);

        try {
            $this->refuse($payload);
            self::fail('Příplatek při nulové zúčtované mzdě musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_wage_breakdown_without_wage',
                $exception->validationCode,
            );
        }
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

        $report = JmhzControlValidatorFactory::create()->validate(
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

    public function testSinglePackageSerializationMatchesSingleDocument(): void
    {
        $single = $this->resolution();
        $envelope = $this->envelope();
        self::assertSame(
            [(new JmhzScenario1XmlSerializer())->serialize($single->requireResolvedDocument(), $envelope)],
            (new JmhzScenario1XmlSerializer())->serializePackages($single->requireResolvedDocument(), $envelope),
        );
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

    /**
     * 10321 = 10322 + 10323 (kontrola 78). Bez přeplatku na dani (10322 = 0)
     * a s přeplaceným bonusem, který zaměstnanec vrací (10323 = −230), je
     * výsledek záporný. XSD `cisloN14Type` i Pokyny MH (kap. 2.4.8) zápornou
     * hodnotu připouštějí; dřív ji serializér odmítl jako nevyřešený atribut.
     */
    public function testNegativeAnnualSettlementResultIsSerializedWithSign(): void
    {
        $payload = $this->resolution()->requireResolvedDocument()->payload;
        $payload['people'][0]['summary']['annual'] = [
            'performed' => true,
            'result' => [
                'settlement_difference_czk' => -230,
                'tax_difference_czk' => 0,
                'bonus_difference_czk' => -230,
                'spouse_credit_claimed' => false,
                'child_credit_claimed' => false,
            ],
        ];

        $xml = (new JmhzScenario1XmlValidator())->dryRun(
            new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($payload), []),
            $this->envelope(),
        )['xml'];

        self::assertStringContainsString('<form:preplatekRok>-230</form:preplatekRok>', $xml);
        self::assertStringContainsString('<form:danPreplatekRok>0</form:danPreplatekRok>', $xml);
        self::assertStringContainsString(
            '<form:danBonusPreplatekRok>-230</form:danBonusPreplatekRok>',
            $xml,
        );
        self::assertSame([], $this->failedControls($xml, [78]));
    }

    /**
     * Pravidla podání JMHZ 1.4.5, kap. 3: opravné hlášení nad limit součástí
     * balíku se dělí do dílčích podání. Souhrn a pojistnou část nese jen první
     * balík, všechny nesou GUID řádného podání a úhrn formulářů celkem.
     * Limit je tu snížený na 1, aby šly dva balíky postavit ze dvou vztahů.
     */
    public function testContentCorrectionAboveThePackageLimitIsSplit(): void
    {
        $payload = $this->resolution()->requireResolvedDocument()->payload;
        $secondPerson = $payload['people'][0];
        $secondPerson['employee_id'] = 12;
        $secondPerson['employments'][0]['employment_id'] = 102;
        $payload['people'][] = $secondPerson;

        $result = (new JmhzScenario1XmlValidator(
            serializer: new JmhzScenario1XmlSerializer(new JmhzPackageSplitter(1)),
        ))->dryRunCorrectionPackages(
            new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($payload), []),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                'AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB',
                [
                    101 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDD',
                    102 => 'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDE',
                ],
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
                JmhzContentCorrectionForm::amendAccepted(
                    102,
                    'CCCCCCCC-4444-5555-8666-DDDDDDDDDDDE',
                    affectsSummary: true,
                    affectsPvpoj: true,
                ),
            ]),
        );

        self::assertCount(2, $result['packages']);
        [$first, $second] = array_column($result['packages'], 'xml');
        foreach ([$first, $second] as $xml) {
            self::assertStringContainsString('<idPodani>AAAAAAAA-1111-2222-8333-BBBBBBBBBBBB</idPodani>', $xml);
            self::assertStringContainsString('<typPodani>O</typPodani>', $xml);
            self::assertStringContainsString('<balikyPocet>2</balikyPocet>', $xml);
            self::assertStringContainsString('<formularePocetCelkem>4</formularePocetCelkem>', $xml);
            self::assertSame(1, substr_count($xml, '<formularOsoby'));
        }
        self::assertStringContainsString('<balikPoradi>1</balikPoradi>', $first);
        self::assertStringContainsString('<formularePocetVBaliku>3</formularePocetVBaliku>', $first);
        self::assertStringContainsString('<so:souhrn', $first);
        self::assertStringContainsString('<pvpoj:PVPOJ', $first);
        self::assertStringContainsString('<balikPoradi>2</balikPoradi>', $second);
        self::assertStringContainsString('<formularePocetVBaliku>1</formularePocetVBaliku>', $second);
        self::assertStringNotContainsString('<so:souhrn', $second);
        self::assertStringNotContainsString('<pvpoj:PVPOJ', $second);
    }

    /**
     * Limit balíku se poměřuje s opravovanými součástmi, ne s celou přípravou:
     * oprava jediného vztahu ve firmě s 1 501 zaměstnanci je jeden balík.
     */
    public function testCorrectionOfOneFormInALargePreparationIsOnePackage(): void
    {
        $payload = $this->resolution()->requireResolvedDocument()->payload;
        $template = $payload['people'][0];
        for ($index = 1; $index <= 1500; ++$index) {
            $person = $template;
            $person['employee_id'] = 10_000 + $index;
            $person['employments'][0]['employment_id'] = 20_000 + $index;
            $payload['people'][] = $person;
        }

        $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
            new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($payload), []),
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
                    affectsSummary: false,
                    affectsPvpoj: false,
                ),
            ]),
        );

        self::assertSame(1, substr_count($result['xml'], '<formularOsoby'));
        self::assertStringContainsString('<formularePocetCelkem>1</formularePocetCelkem>', $result['xml']);
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
        self::assertSame('jmhz-1.4.3.6', $result['schema']['package_key']);
        self::assertSame('1.4.3', $result['schema']['data_version']);
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
     * BRÁNA: atribut, který matice povinností vede jako NEPODMÍNĚNĚ povinný,
     * musí být ve výstupu i u zcela běžné součásti.
     *
     * Vzniklo z opakovaného nálezu. Nejdřív chyběly příznaky slevy na
     * pojistném zaměstnance (10490, 10546), potom se ukázalo, že táž chyba je
     * i u slevy zaměstnavatele (10372): element se uměl zapsat, ale jen když
     * ho zapnula podmínka, kterou běžná součást nesplní. Ruční hledání „umíme
     * ten název vypsat?" na to nestačí — v obou případech název ve zdrojovém
     * kódu byl.
     *
     * Kontrola proto porovnává SKUTEČNÝ výstup se seznamem z připnutého
     * manifestu: povinnost `required` v jádru `CORE DATA`, tedy bez interakce,
     * která by ji podmiňovala. Atributy bez XSD mapování (třeba kanál podání)
     * se přeskočí, protože v datové větě žádný element nemají.
     */
    public function testEveryUnconditionallyMandatoryCoreFieldIsAlwaysSerialized(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4)
                    . '/resources/payroll/jmhz/dictionary-1.4.1.6'
                    . '/scenario-requirement-manifest.json',
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertSame(
            JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            $manifest['manifest_sha256'],
        );

        $required = [];
        foreach ($manifest['payload']['matrices'] as $matrix) {
            if (($matrix['matrix_key'] ?? null) !== 'scenario_1') {
                continue;
            }
            foreach ($matrix['requirements'] as $requirement) {
                if (($requirement['requirement_kind'] ?? null) !== 'required'
                    || ($requirement['translation_raw'] ?? null) !== 'CORE DATA'
                ) {
                    continue;
                }
                $mapping = $requirement['xsd_mapping_raw'] ?? null;
                if (!is_string($mapping)) {
                    continue;
                }
                $path = preg_replace('/\s*\(ID \d+\)$/D', '', $mapping);
                self::assertIsString($path);
                $required[$path] = (string) $requirement['attribute_id'];
            }
        }
        // Pojistka proti tichému rozpadu filtru: kdyby se manifest načetl
        // prázdný nebo se klíč matice přejmenoval, prošlo by cokoliv.
        self::assertGreaterThan(25, count($required));

        $dom = new \DOMDocument();
        $dom->loadXML(
            (new JmhzScenario1XmlValidator())
                ->dryRun($this->resolution(), $this->envelope())['xml'],
            LIBXML_NONET | LIBXML_NOBLANKS,
        );
        $emitted = self::emittedLeafPaths($dom);

        $missing = [];
        foreach ($required as $path => $attributeId) {
            if (!isset($emitted[$path])) {
                $missing[$path] = $attributeId;
            }
        }
        self::assertSame([], $missing);
    }

    /**
     * Listové cesty výstupu ve tvaru datového slovníku: hlavička podání pod
     * `hlavicka.…`, součást relativně ke kořeni `bezPriznaku`.
     *
     * @return array<string, true>
     */
    private static function emittedLeafPaths(\DOMDocument $dom): array
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://schemas.cssz.cz/JMHZ/podani/1.0');
        $xpath->registerNamespace('form', 'http://schemas.cssz.cz/JMHZ/form/1.0');
        $paths = [];
        foreach ([
            'hlavicka' => '/n:jmhz/n:hlavicka//*[not(*)]',
            'bezPriznaku' => '//form:bezPriznaku//form:*[not(*)]',
        ] as $root => $query) {
            $leaves = $xpath->query($query);
            self::assertInstanceOf(\DOMNodeList::class, $leaves);
            foreach ($leaves as $leaf) {
                self::assertInstanceOf(\DOMElement::class, $leaf);
                $segments = [];
                for (
                    $node = $leaf;
                    $node instanceof \DOMElement && $node->localName !== $root;
                    $node = $node->parentNode
                ) {
                    array_unshift($segments, $node->localName);
                }
                if ($root === 'hlavicka') {
                    array_unshift($segments, 'hlavicka');
                }
                $paths[implode('.', $segments)] = true;
            }
        }

        return $paths;
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

    /**
     * N-05: zaměstnanec s dětmi. Před opravou resolver hlásil
     * `jmhz_scenario1_child_credit_breakdown_unavailable` a serializér blok
     * 10303/10439/10440/10453 vůbec neuměl, takže tenhle test bez opravy padá
     * na blokovaném dokumentu.
     */
    public function testMonthlyChildCreditEmitsFrozenBlockAndStaysXsdValid(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payloadWithChildCredit()),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:danoveZvyhodneniDetiMesic>1617</form:danoveZvyhodneniDetiMesic>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:vyzivujeJinaOsoba>false</form:vyzivujeJinaOsoba>',
            $result['xml'],
        );
        self::assertStringContainsString('<form:jmeno>Jana</form:jmeno>', $result['xml']);
        self::assertStringContainsString(
            '<form:prijmeni>Nováková</form:prijmeni>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:datumNarozeni>2015-04-11</form:datumNarozeni>',
            $result['xml'],
        );
        self::assertStringContainsString('<form:poradi>1</form:poradi>', $result['xml']);
        self::assertStringContainsString(
            '<form:slevaDite>1500</form:slevaDite>',
            $result['xml'],
        );
        self::assertStringNotContainsString('form:jineOsoby', $result['xml']);
        // Rodné číslo není potřeba posílat, protože podmíněnou povinnost
        // identity i kontrolu věku pokryje datum narození.
        self::assertStringNotContainsString('form:rodneCislo', $result['xml']);

        $report = JmhzControlValidatorFactory::create()
            ->validate($result['xml'], new JmhzControlContext('2026-08-14', schemaValidated: true));
        self::assertSame([], $report->coverageGaps());
        self::assertTrue($report->submittable());
    }

    /**
     * Kontrola 127 (blocking): u 10453 = ANO musí být jiná osoba pojmenovaná.
     */
    public function testMonthlyChildCreditNamesTheOtherHouseholdCaregiver(): void
    {
        $payload = $this->payloadWithChildCredit();
        $payload['people'][0]['child_credit_evidence'] = [
            'other_household_caregiver_status' => 'present',
            'other_household_caregivers' => [[
                'given_name' => 'Petr',
                'family_name' => 'Novák',
                'birth_date' => '1990-04-11',
            ]],
            'children' => $payload['people'][0]['child_credit_evidence']['children'],
        ];

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:vyzivujeJinaOsoba>true</form:vyzivujeJinaOsoba>',
            $result['xml'],
        );
        self::assertStringContainsString('<form:jinaOsoba>', $result['xml']);
        self::assertStringContainsString(
            '<form:datumNarozeni>1990-04-11</form:datumNarozeni>',
            $result['xml'],
        );
    }

    /**
     * Dítě „N" (10440): první dítě domácnosti uplatňuje partner, zaměstnanec
     * druhé. Podání musí projít XSD a kontrolami 110 (pořadí 2 jen spolu
     * s nižším pořadím nebo „N") a 127 (u 10453 = ANO jmenovaná osoba).
     * Protipříklad bez „N" ukazuje, že kontrola 110 stejné podání odmítne.
     */
    public function testChildClaimedByOtherIsSerializedAsNAndPassesControls110And127(): void
    {
        $payload = $this->payloadWithChildCredit();
        $claimed = $payload['people'][0]['child_credit_evidence']['children'][0];
        $claimed['order'] = 2;
        $payload['people'][0]['child_credit_evidence'] = [
            'other_household_caregiver_status' => 'present',
            'other_household_caregivers' => [[
                'given_name' => 'Petr',
                'family_name' => 'Novák',
                'birth_date' => '1990-04-11',
            ]],
            'children' => [
                [
                    'reference' => 'dependant-2',
                    'identity' => [
                        'given_name' => 'Eva',
                        'family_name' => 'Nováková',
                        'birth_date' => '2013-01-01',
                    ],
                    'order' => 1,
                    'ztp_p' => false,
                    'credit_claimed' => false,
                ],
                $claimed,
            ],
        ];

        $xml = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        )['xml'];

        self::assertStringContainsString('<form:poradi>N</form:poradi>', $xml);
        self::assertStringContainsString('<form:poradi>2</form:poradi>', $xml);
        self::assertStringContainsString('<form:vyzivujeJinaOsoba>true</form:vyzivujeJinaOsoba>', $xml);
        self::assertSame([], $this->failedControls($xml, [110, 127]));

        $withoutN = $this->payloadWithChildCredit();
        $withoutN['people'][0]['child_credit_evidence']['children'][0]['order'] = 2;
        $gapXml = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($withoutN),
            $this->envelope(),
        )['xml'];

        self::assertSame([110], $this->failedControls($gapXml, [110, 127]));
    }

    /**
     * @param list<int> $controlIds
     * @return list<int>
     */
    private function failedControls(string $xml, array $controlIds): array
    {
        $report = JmhzControlValidatorFactory::create()
            ->validate($xml, new JmhzControlContext('2026-08-14', schemaValidated: true));
        $evaluated = [];
        $failed = [];
        foreach ($report->findings as $finding) {
            if (!in_array($finding->controlId, $controlIds, true)) {
                continue;
            }
            $evaluated[$finding->controlId] = true;
            if ($finding->outcome === \MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlOutcome::Failed) {
                $failed[$finding->controlId] = true;
            }
        }
        self::assertSame(
            $controlIds,
            array_values(array_intersect($controlIds, array_keys($evaluated))),
            'Obě kontroly se musí na formulář skutečně vyhodnotit.',
        );
        ksort($failed);

        return array_keys($failed);
    }

    /** @return array<string,mixed> */
    private function payloadWithChildCredit(): array
    {
        $payload = $this->payload();
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['advance_tax']['child_credit_minor_units'] = 161_700;
        $tax['advance_tax']['tax_before_credits_minor_units'] = 15_000;
        $tax['advance_tax']['tax_after_credits_minor_units'] = 0;
        $tax['applied_child_credit_minor_units'] = 150_000;
        unset($tax);
        $payload['people'][0]['employments'][0]['term']
            ['tax_declaration_signed'] = true;
        $payload['people'][0]['child_credit_evidence'] = [
            'other_household_caregiver_status' => 'none',
            'other_household_caregivers' => [],
            'children' => [[
                'reference' => 'dependant-1',
                'identity' => [
                    'given_name' => 'Jana',
                    'family_name' => 'Nováková',
                    'birth_date' => '2015-04-11',
                ],
                'order' => 1,
                'ztp_p' => false,
            ]],
        ];

        return $payload;
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
            $this->refuse($payload);
            self::fail('Sleva bez podepsaného prohlášení musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_credit_without_declaration',
                $exception->validationCode,
            );
        }
    }

    /**
     * Kontrola 244 bere za „vyplněný" atribut samotnou přítomnost elementu.
     * Hlášení za 08/2026 (VS 4442070407) neslo `form:danBonus` = 0 u zaměstnance
     * bez prohlášení a ČSSZ celý formulář odmítla chybou 40244 s odkazem na
     * atribut 10306. Bez prohlášení se element proto neuvádí vůbec.
     */
    public function testTaxBonusElementIsOmittedWithoutSignedDeclaration(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payload()),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:prohlaseniPoplatnika>false</form:prohlaseniPoplatnika>',
            $result['xml'],
        );
        self::assertStringContainsString('<form:danZalohaPoSleve>', $result['xml']);
        self::assertStringNotContainsString('<form:danBonus>', $result['xml']);
    }

    public function testTaxBonusElementStaysWithSignedDeclaration(): void
    {
        $payload = $this->payload();
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
        self::assertStringContainsString('<form:danBonus>', $result['xml']);
    }

    /**
     * N-13: souhrnný `so:danBonus` (10035) se psal vždy, i s nulou, přestože
     * XSD ho má `minOccurs=0` a formulářový protějšek 10306 se po opravě
     * kontroly 244 řídí přítomností elementu. Bez opravy tenhle test padá na
     * prvním tvrzení.
     */
    public function testSummaryTaxBonusIsOmittedWhenNobodyGotOne(): void
    {
        $zero = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payload()),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<so:danBonus>', $zero['xml']);
        self::assertStringContainsString('<so:danZalohaPoSleve>', $zero['xml']);

        $resolved = $this->resolution()->requireResolvedDocument()->payload;
        $resolved['employer']['summary_totals']['tax_bonus'] = 1_450;
        $paid = (new JmhzScenario1XmlValidator())->dryRun(
            new JmhzScenario1Resolution(
                new JmhzScenario1NormalizedDocument($resolved),
                [],
            ),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<so:danBonus>1450</so:danBonus>',
            $paid['xml'],
        );
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

    /**
     * Nevyplněno se vykládá jako „ne", ale JEN když to snímek doloží. Bez
     * záznamu o výkladu (starší snímek do v11) by podání tvrdilo něco, co
     * nikde není zapsané — a proto se nepostaví.
     */
    public function testUnverifiedTristateWithoutRecordedInterpretationBlocks(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']
            ['jmhz_functional_benefits_status'] = 'unverified';

        try {
            $this->refuse($payload);
            self::fail('Nedoložený výklad nevyplněného tri-state musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10247', $exception->getMessage());
        }
    }

    /**
     * Doložený výklad výchozího stavu se do formuláře promítne jako `false` —
     * tedy stejně, jako kdyby účetní klikla „ne". Rozdíl zůstává v evidenci
     * a ve zmrazeném snímku, ne ve výsledném XML.
     */
    public function testUnverifiedTristateWithRecordedInterpretationSerializesAsFalse(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']
            ['jmhz_functional_benefits_status'] = 'unverified';
        $payload['people'][0]['employments'][0]['jmhz_default_interpretations'] = [[
            'field' => 'jmhz_functional_benefits_status',
            'attribute_id' => '10247',
            'stored_value' => 'unverified',
            'applied_value' => 'no',
            'basis' => JmhzPreparationSnapshotBuilder::DEFAULT_TRISTATE_BASIS,
        ]];

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:funkcniPozitky>false</form:funkcniPozitky>',
            $result['xml'],
        );
    }

    /**
     * Přidělené identifikátory = větev A, a jmenné údaje se do podání nepletou.
     *
     * Po doručení OIČ a ID PPV je jejich uvádění povinné; kdyby se vedle nich
     * objevila i jmenná větev, `xs:choice` by podání odmítl.
     */
    public function testAssignedIdentifiersKeepTheIdentifierBranch(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:ikMpsv>1000000001</form:ikMpsv>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:idPpv>2000000000000000000001</form:idPpv>',
            $result['xml'],
        );
        self::assertStringNotContainsString('<form:prijmeni>', $result['xml']);
        self::assertStringNotContainsString('<form:druhCinnosti>', $result['xml']);
    }

    /**
     * Bez přiděleného OIČ se zaměstnanec hlásí JMÉNEM, ne blokací.
     *
     * OIČ i ID PPV přiděluje ČSSZ až protokolem o přijetí registrace, takže
     * první hlášení za nově registrovaného zaměstnance je nemá odkud vzít.
     * `identifikaceType` na to má druhou větev `xs:choice` a její pořadí
     * elementů je závazné — proto se ověřuje celý blok najednou i to, že
     * výsledek projde připnutým XSD.
     */
    public function testEmployeeWithoutCsszIdentifiersIsReportedByName(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($this->payloadWithoutCsszIdentifiers()),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<form:ikMpsv>', $result['xml']);
        self::assertStringNotContainsString('<form:idPpv>', $result['xml']);
        self::assertStringContainsString(
            '<form:identifikace>'
                . '<form:prijmeni>Nováková</form:prijmeni>'
                . '<form:jmeno>Jana</form:jmeno>'
                . '<form:datumNarozeni>1990-04-12</form:datumNarozeni>'
                . '<form:datumNastupu>2026-03-01</form:datumNastupu>'
                . '<form:druhCinnosti>1</form:druhCinnosti>'
                . '</form:identifikace>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
    }

    /**
     * Skutečný den nástupu má přednost před sjednaným — 10223 se ptá na den,
     * kdy zaměstnanec do zaměstnání nastoupil, ne na den podpisu smlouvy.
     */
    public function testNameBranchPrefersActualStartDate(): void
    {
        $payload = $this->payloadWithoutCsszIdentifiers();
        $payload['people'][0]['employments'][0]['employment']['actual_start_date']
            = '2026-03-16';

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:datumNastupu>2026-03-16</form:datumNastupu>',
            $result['xml'],
        );
    }

    /**
     * Teprve chybějící údaj JMENNÉ větve je skutečný blokátor — a hlásí se
     * jmenovitě on, ne chybějící OIČ.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteNameBranchProvider')]
    public function testIncompleteNameBranchBlocksNamingTheMissingAttribute(
        string $path,
        string $attributeId,
    ): void {
        $payload = $this->payloadWithoutCsszIdentifiers();
        $employment = &$payload['people'][0]['employments'][0];
        match ($path) {
            'family_name' => $employment['identity']['identity']['last_name'] = null,
            'given_name' => $employment['identity']['identity']['first_name'] = null,
            'birth_date' => $employment['identity']['identity']['birth_date'] = null,
            'start_date' => $employment['employment'] = ['is_primary' => true],
            'activity_code' => $employment['scenario_resolution'] = [
                'scenario_key' => 'scenario_1',
            ],
        };
        unset($employment);

        try {
            $this->refuse($payload);
            self::fail('Neúplná jmenná větev musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_identity_name_incomplete',
                $exception->validationCode,
            );
            self::assertStringContainsString($attributeId, $exception->getMessage());
        }
    }

    /** @return iterable<string,array{string,string}> */
    public static function incompleteNameBranchProvider(): iterable
    {
        yield 'prijmeni' => ['family_name', '10053'];
        yield 'jmeno' => ['given_name', '10054'];
        yield 'datum narozeni' => ['birth_date', '10056'];
        yield 'datum nastupu' => ['start_date', '10223'];
        yield 'druh cinnosti' => ['activity_code', '10239'];
    }

    /**
     * Půlka větve A není větev A: kdyby se OIČ uvedlo bez ID PPV, `xs:choice`
     * by takový blok neznal. Hlásí se proto nedoložený protějšek, ne tichý
     * přeskok na jmennou větev.
     */
    public function testHalfOfTheIdentifierBranchIsRefused(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['identity']
            ['jmhz_employment_external_identifier'] = null;

        try {
            $this->refuse($payload);
            self::fail('Neúplná dvojice identifikátorů musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10228', $exception->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function payloadWithoutCsszIdentifiers(): array
    {
        $payload = $this->payload();
        $identity = &$payload['people'][0]['employments'][0]['identity'];
        $identity['person_external_identifier'] = null;
        $identity['jmhz_employment_external_identifier'] = null;
        unset($identity);

        return $payload;
    }

    public function testMissingFrozenAttributeIsNeverFilledWithZero(): void
    {
        $payload = $this->payload();
        unset(
            $payload['people'][0]['employments'][0]['work_month']
                ['jmhz_work_summary']['values']['evidence_days'],
        );

        try {
            $this->refuse($payload);
            self::fail('Chybějící zmrazený atribut musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_attribute_unresolved', $exception->validationCode);
            self::assertStringContainsString('10265', $exception->getMessage());
        }
    }

    /**
     * Chybějící průměrný hodinový výdělek (10345) je jediný důvod, na který
     * zákon zná náhradní postup — § 355 ZP a pravděpodobný výdělek. Hlášku
     * „atribut není doložený" účetní neumí použít, takže tenhle atribut má
     * vlastní kód i návod, kam jít a co vyplnit.
     */
    public function testMissingAverageHourlyEarningExplainsTheProbableEarningProcedure(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['average_earning'] = null;

        try {
            $this->refuse($payload);
            self::fail('Chybějící průměrný výdělek musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_average_hourly_earning_probable_missing',
                $exception->validationCode,
            );
            self::assertStringContainsString('§ 355', $exception->getMessage());
            self::assertStringContainsString('Pravděpodobný hodinový výdělek', $exception->getMessage());
        }
    }

    public function testEldpSectionWithDaysRequiresCode(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'] = null;

        try {
            $this->refuse($payload);
            self::fail('ELDP sekce s dny bez kódu musela podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_eldp_code_required', $exception->validationCode);
        }
    }

    /**
     * ČSSZ potvrdila: atribut 10476 („Vykázaný příjem VČETNĚ nepojištěné
     * činnosti", `form:prijemNepojistenaCinnost`) se vyplňuje PLNOU
     * hodnotou, nikdy nulou — a ukázková XML ČSSZ, která mají 10476 = 0,
     * jsou potvrzeně chybná. U běžného HPP pod maximálním vyměřovacím
     * základem (žádná nepojištěná činnost, žádné zastropování) proto 10476
     * vychází STEJNĚ jako 10477 (`form:castkaOdvodPojistneho`) — obě čerpají
     * ze stejného, nezastropovaného příjmu.
     *
     * Nejde o duplicitu k zápisu níž (10476 se liší od 10477 u DPP pod
     * hranicí účasti): tenhle test kryje opačný a běžnější případ — shodu —
     * aby ho nikdo „neopravil" podle chybné ukázky ČSSZ zpátky na nulu.
     */
    public function testOrdinaryEmploymentBelowCapReportsSameNoninsuredActivityIncomeAsAssessmentBase(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:castkaOdvodPojistneho>1000</form:castkaOdvodPojistneho>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:prijemNepojistenaCinnost>1000</form:prijemNepojistenaCinnost>',
            $result['xml'],
        );
    }

    /**
     * 10476 je celý vykázaný příjem (DV 1.4.5: "Vykázaný příjem včetně
     * nepojištěné činnosti"), 10477 je příjem zastropovaný maximálním
     * vyměřovacím základem. U pojištěného zaměstnance nad stropem se proto
     * obě hodnoty liší a 10476 se nesmí srazit na strop.
     */
    public function testInsuredEmploymentAboveCapReportsFullIncomeIn10476(): void
    {
        $payload = $this->payload();
        $insurance = &$payload['people'][0]['employments'][0]['insurance'];
        $insurance['participation']['participation_income_minor_units'] = 5_000_000;
        $insurance['assessment_base_minor_units'] = 5_000_000;
        $insurance['capped_assessment_base_minor_units'] = 4_000_000;
        unset($insurance);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:castkaOdvodPojistneho>40000</form:castkaOdvodPojistneho>',
            $result['xml'],
        );
        self::assertStringContainsString(
            '<form:prijemNepojistenaCinnost>50000</form:prijemNepojistenaCinnost>',
            $result['xml'],
        );
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
     * Pokyny MPSV k 10276: „Neuvádí se hodiny neodpracované z důvodu dočasné
     * pracovní neschopnosti." Nemoc s náhradou mzdy je překážkou na straně
     * zaměstnance (ZP část osmá, hlava I), takže patří do 10471. Pracovní
     * souhrn ji v placených hodinách nese dál (sleva § 7a ZPSZ), převádí se
     * až v hlášení.
     */
    public function testSicknessWithWageCompensationMovesFromPaidHoursToEmployeeObstacles(): void
    {
        $payload = $this->payload();
        $summary = &$payload['people'][0]['employments'][0]['work_month']['jmhz_work_summary'];
        $summary['interactions'] = ['IN07' => true, 'IN08' => true];
        $summary['values'] = array_merge($summary['values'], [
            'worked_millihours' => 136_000,
            'unworked_total_millihours' => 48_000,
            'unworked_paid_millihours' => 48_000,
            'dpn_with_employer_compensation_millihours' => 24_000,
            'vacation_millihours' => 16_000,
            'employee_obstacle_paid_millihours' => 8_000,
        ]);
        unset($summary);

        $xml = (string) preg_replace(
            '/>\s+</',
            '><',
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            )['xml'],
        );

        self::assertStringContainsString(
            '<form:neodpracovaneHodiny><form:hodinyNeodpracCelkem>48.000</form:hodinyNeodpracCelkem>'
                . '<form:hodinyNeodpracNahrada>24.000</form:hodinyNeodpracNahrada>'
                . '<form:hodinyNeodpracNeschop>24.000</form:hodinyNeodpracNeschop>'
                . '<form:hodinyNeodpracDovol>16.000</form:hodinyNeodpracDovol></form:neodpracovaneHodiny>'
                . '<form:prekazkyVPraci><form:prekazkaZamestnanec>32.000</form:prekazkaZamestnanec></form:prekazkyVPraci>',
            $xml,
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
     * 10373 je týdenní doba ze všech pracovních poměrů osoby u zaměstnavatele
     * dohromady (Pokyny k vyplnění MH kap. 3.6.9). Má-li osoba vedle vztahu se
     * slevou (20 h) ještě druhý pracovní poměr (8 h), vykazuje se 28 h, tedy
     * úhrn, který výsledek pojistného nese u vztahu se slevou.
     */
    public function testShorterWorkingTimeIsTheTotalOfAllEmploymentsOfThePerson(): void
    {
        $payload = $this->payloadWithDiscount();
        $payload['people'][0]['employments'][0]['insurance']
            ['part_time_discount_weekly_working_millihours_total'] = 28_000;

        $xml = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload, $this->discountPvpoj()),
            $this->envelope(),
        )['xml'];

        self::assertStringContainsString(
            '<form:pracovniDobaKratsi>28.00</form:pracovniDobaKratsi>',
            $xml,
        );
    }

    /**
     * Výsledek zmrazený dřív úhrn nenese. Vlastní sjednanou dobu lze vzít jen
     * u osoby s jediným pracovním poměrem; s druhým by hlášení vykázalo
     * kratší rozsah, než jaký zaměstnanec u zaměstnavatele má.
     */
    public function testLegacyResultWithoutTotalIsRefusedForMoreEmployments(): void
    {
        $payload = $this->payloadWithDiscount();
        $second = $payload['people'][0]['employments'][0];
        $second['employment_id'] = 102;
        $second['insurance'] = [
            'relationship_id' => 'employment:102',
            'kind' => 'employment',
        ] + $second['insurance'];
        unset(
            $second['insurance']['part_time_employer_discount'],
            $second['insurance']['part_time_employer_discount_outcome'],
            $second['insurance']['part_time_employer_discount_reason'],
        );
        $payload['people'][0]['employments'][] = $second;

        $resolution = $this->resolutionFor($payload, $this->discountPvpoj());

        self::assertContains(
            'jmhz_employer_part_time_discount_working_time_unresolved',
            array_map(
                static fn ($blocker): string => $blocker->code,
                $resolution->blockers,
            ),
        );
    }

    /**
     * Měsíc dovršení důchodového věku jako dvě sekce ELDP (pravidla podání
     * JMHZ 1.4.5, kap. 4): obě jdou do hlášení a projdou XSD i kontrolami
     * sekcí, včetně 3. části kontroly 59 (sekce před dovršením má základ 0).
     */
    public function testPensionAgeSplitSectionsAreSerializedAndPassSectionControls(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['eldp']['eldp_sections'] = [
            [
                'ordinal' => 1,
                'code' => '1++',
                'valid_from' => '2026-07-01',
                'valid_to' => '2026-07-15',
                'insurance_days' => 15,
                'assessment_base_czk' => 0,
                'excluded_days' => null,
                'deducted_days' => null,
            ],
            [
                'ordinal' => 2,
                'code' => '1D+',
                'valid_from' => '2026-07-16',
                'valid_to' => '2026-07-31',
                'insurance_days' => 16,
                'assessment_base_czk' => 1_000,
                'excluded_days' => null,
                'deducted_days' => null,
            ],
        ];

        $xml = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        )['xml'];

        self::assertSame(2, substr_count($xml, '<form:eldp>'));
        self::assertStringContainsString('<form:kod>1D+</form:kod>', $xml);
        self::assertSame([], $this->failedControls($xml, [59, 99, 100, 134, 135, 330]));
    }

    /**
     * Osvobozené příjmy (10289) jsou PODMNOŽINOU zúčtovaného příjmu (10286),
     * ne veličina vedle něj — kontrola 97 ČSSZ zní „(10289) =< (10286)".
     *
     * Plnění osvobozené podle § 6 odst. 9 ZDP (stravenka, přechodné ubytování)
     * je příjmem ze závislé činnosti, jen se nezdaňuje. Do úhrnu proto patří
     * a v hlášení se vykáže jako osvobozená část.
     */
    public function testExemptIncomeIsReportedAsPartOfTheAccruedTotal(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['exempt_income_minor'] = 20_000;

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:prijmy><form:zuctovanoCelkem>1000</form:zuctovanoCelkem>'
                . '<form:osvobozenoCelkem>200</form:osvobozenoCelkem></form:prijmy>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
    }

    /**
     * Příspěvek zaměstnavatele na produkty spoření na stáří se vykazuje
     * rozepsaný podle druhu produktu. Je to část osvobozeného příjmu, takže se
     * tatáž částka objeví i v úhrnu osvobozených příjmů — dva pohledy na jedny
     * peníze, ne dvojí vykázání.
     */
    public function testEmployerPensionContributionsAreReportedByProduct(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['exempt_income_minor'] = 20_000;
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor'] += [
            '10417' => 20_000,
            '10292' => 15_000,
            '10296' => 5_000,
        ];

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:osvobozenoCelkem>200</form:osvobozenoCelkem>'
                . '<form:prispevekZamestnavatele>'
                . '<form:prispevekZelSporeniOsvob>200</form:prispevekZelSporeniOsvob>'
                . '<form:prispevekPenzPripoj>150</form:prispevekPenzPripoj>'
                . '<form:prispevekDip>50</form:prispevekDip>'
                . '</form:prispevekZamestnavatele>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
    }

    /**
     * Zaměstnavatel, který na penzijní produkty nepřispívá, nemá složku vůbec
     * zavedenou. Blok se sedmi nulami by tvrdil víc, než z čeho plyne.
     */
    public function testEmployerContributionBlockIsOmittedWithoutContributions(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<form:prispevekZamestnavatele>', $result['xml']);
    }

    /**
     * Počet odpracovaných dnů (10267) a přesčas (10269) nese až pracovní
     * souhrn v4. Přesčas je PODMNOŽINOU odpracovaných hodin, ne časem navíc.
     */
    public function testWorkedDaysAndOvertimeAreReportedFromTheWorkSummary(): void
    {
        $payload = $this->payload();
        $values = &$payload['people'][0]['employments'][0]['work_month']
            ['jmhz_work_summary']['values'];
        $values['worked_days'] = 16;
        $values['overtime_millihours'] = 3_000;
        unset($values);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );
        $xml = preg_replace('/>\s+</', '><', $result['xml']) ?? '';

        self::assertStringContainsString(
            '<form:dnyEvidencniStav>31</form:dnyEvidencniStav>'
                . '<form:dnyOdpracovanePocet>16</form:dnyOdpracovanePocet>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:pocet>184.000</form:pocet><form:rozpad>'
                . '<form:prescas>3.000</form:prescas></form:rozpad>',
            $xml,
        );
    }

    /**
     * Pokyny k vyplnění MH 1.4.14 (revize) u 10273: hodiny rizikové práce jako
     * celé nezáporné číslo, zbytek minut pod 60 se počítá za 1 hodinu. 165 h
     * 30 min je proto 166, ne „165.500“. Kontrolu 57 to neporuší, protože
     * katalog kontrol desetinné 10268 pro ni zaokrouhluje také nahoru.
     */
    public function testRiskWorkHoursAreWholeHoursRoundedUp(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['term']['social_employer_rate_category'] = 'risk_employment';
        $values = &$payload['people'][0]['employments'][0]['work_month']
            ['jmhz_work_summary']['values'];
        $values['worked_millihours'] = 165_500;
        unset($values);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );
        $xml = preg_replace('/>\s+</', '><', $result['xml']) ?? '';

        self::assertStringContainsString('<form:pocet>165.500</form:pocet>', $xml);
        self::assertStringContainsString(
            '<form:riziko><form:hodinyOdpracovanePocet>166</form:hodinyOdpracovanePocet>'
                . '<form:kategorizaceRizika>1</form:kategorizaceRizika></form:riziko>',
            $xml,
        );
    }

    public function testWholeHoursRoundUpOnlyTheRemainder(): void
    {
        self::assertSame(0, JmhzWholeHours::fromMillihours(0));
        self::assertSame(1, JmhzWholeHours::fromMillihours(1));
        self::assertSame(168, JmhzWholeHours::fromMillihours(168_000));
        self::assertSame(169, JmhzWholeHours::fromMillihours(168_001));
        self::assertSame([166, 0], JmhzWholeHours::ceilScaled([165_500, 3]));
        self::assertSame([165, 0], JmhzWholeHours::ceilScaled([165_000, 3]));
        self::assertSame([165, 0], JmhzWholeHours::ceilScaled([165, 0]));
    }

    /**
     * Starší zmrazený souhrn obě veličiny nemá. Nula by tvrdila, že zaměstnanec
     * neodpracoval ani den; oba atributy jsou nepovinné, takže se vynechají.
     */
    public function testWorkedDaysAndOvertimeAreOmittedForOlderWorkSummaries(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<form:dnyOdpracovanePocet>', $result['xml']);
        self::assertStringNotContainsString('<form:rozpad>', $result['xml']);
    }

    public function testOvertimeAboveWorkedHoursIsRefused(): void
    {
        $payload = $this->payload();
        $values = &$payload['people'][0]['employments'][0]['work_month']
            ['jmhz_work_summary']['values'];
        $values['worked_days'] = 16;
        $values['overtime_millihours'] = 200_000;
        unset($values);

        try {
            $this->refuse($payload);
            self::fail('Přesčas nad odpracované hodiny musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_overtime_exceeds_worked_hours',
                $exception->validationCode,
            );
        }
    }

    /**
     * Kontrola 36: přesčas bez složky příplatku za přesčas. Pokyny MPSV u 10333
     * chtějí „pokud nebyly příplatky proplaceny, uvést 0"; bez zápisu nuly
     * blokující kontrola 36 podání odmítne.
     */
    public function testOvertimeWithoutSurchargeComponentReportsZeroSurcharge(): void
    {
        $payload = $this->payload();
        $values = &$payload['people'][0]['employments'][0]['work_month']
            ['jmhz_work_summary']['values'];
        $values['worked_days'] = 16;
        $values['overtime_millihours'] = 3_000;
        unset($values);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );
        $xml = preg_replace('/>\s+</', '><', $result['xml']) ?? '';

        self::assertStringContainsString(
            '<form:odmenyNepravidelne>0</form:odmenyNepravidelne>'
                . '<form:priplatky><form:celkem>0</form:celkem>'
                . '<form:prescas>0</form:prescas></form:priplatky></form:mzdaRozpad>',
            $xml,
        );
        self::assertSame([], $this->failedControls($result['xml'], [36]));
    }

    /**
     * Firma se složkou příplatku za noc, ale bez příplatku za přesčas: úhrn
     * zůstává ze zdroje a doplní se jen nulový příplatek za přesčas na jeho
     * místo v sekvenci `priplatkyType`.
     */
    public function testOvertimeKeepsFrozenSurchargeTotalAndAddsZeroOvertimeSurcharge(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor'] = [
            '10328' => 100_000,
            '10329' => 90_000,
            '10330' => 0,
            '10331' => 0,
            '10332' => 10_000,
            '10334' => 10_000,
        ];
        $values = &$payload['people'][0]['employments'][0]['work_month']
            ['jmhz_work_summary']['values'];
        $values['worked_days'] = 16;
        $values['overtime_millihours'] = 3_000;
        unset($values);

        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolutionFor($payload),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:priplatky><form:celkem>100</form:celkem>'
                . '<form:prescas>0</form:prescas><form:nocni>100</form:nocni></form:priplatky>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
        self::assertSame([], $this->failedControls($result['xml'], [29, 36]));
    }

    /**
     * Řez zmrazený dřív, než se úhrn osvobozených příjmů odvozoval, ho nenese.
     * Nula by tvrdila, že zaměstnanec žádný osvobozený příjem neměl — a to
     * z takového řezu neplyne, takže se element vynechá.
     */
    public function testExemptIncomeIsOmittedWhenTheFrozenSliceDoesNotCarryIt(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringNotContainsString('<form:osvobozenoCelkem>', $result['xml']);
    }

    public function testExemptIncomeAboveTheAccruedTotalIsRefused(): void
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['exempt_income_minor'] = 200_000;

        try {
            $this->refuse($payload);
            self::fail('Osvobozený příjem nad úhrnem musel podání zablokovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(
                'jmhz_xml_exempt_income_exceeds_total',
                $exception->validationCode,
            );
        }
    }

    /**
     * Příznak slevy zaměstnavatele (10372) je povinné jádro scénáře, ne údaj
     * podmíněný interakcí: vztah BEZ slevy ho vykazuje jako „ne".
     *
     * Vynechávat celý blok nešlo. Kontrola 1 ČSSZ čte hodnotu příznaku, ne
     * přítomnost bloku, a mlčení na formuláři při vyplněné pojistné části je
     * rozpor uvnitř jednoho podání — tentýž tvar jako u slevy zaměstnance.
     * Rozpad (10373, 10374) patří pod interakci IN02, takže tady být nesmí.
     */
    public function testRelationshipWithoutDiscountStillReportsTheEmployerFlag(): void
    {
        $result = (new JmhzScenario1XmlValidator())->dryRun(
            $this->resolution(),
            $this->envelope(),
        );

        self::assertStringContainsString(
            '<form:slevaZamestnavatele><form:slevaZamestnavateleEvidovana>false'
                . '</form:slevaZamestnavateleEvidovana></form:slevaZamestnavatele>',
            preg_replace('/>\s+</', '><', $result['xml']) ?? '',
        );
        self::assertStringNotContainsString(
            '<form:slevaZamestnavateleRozpad>',
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

    /**
     * Scénář 4 (`formVezen.xsd`): podmnožina scénáře 1 bez zdravotního
     * pojištění, vyměřovacích základů § 5a, pojistného, pozice a rozpadu mzdy.
     * Vyloučené doby jen úhrn, DPN, PPM a úhrn § 18 odst. 7.
     */
    public function testPrisonerIsSerializedOnVezenFormValidAgainstSchema(): void
    {
        $payload = $this->specialScenarioPayload('scenario_4', '1', '2');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['eldp']['eldp_sections'][0]['excluded_days_total'] = 3;
        $employment['eldp']['eldp_sections'][0]['excluded_days'] = [
            'docasNeschopnost' => 3,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 0,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        $employment['eldp']['eldp_sections'][0]['section18_days_total'] = 3;
        $employment['eldp']['eldp_sections'][0]['section18_days'] = [
            'omluvenaNepritomnost' => 0,
            'pracovniNeschopnost' => 3,
            'vyplaceniDavek' => 0,
        ];
        $employment['earnings_by_attribute_minor']['10342'] = 30_000;
        unset($employment);

        $xml = (string) preg_replace(
            '/>\s+</',
            '><',
            (new JmhzScenario1XmlValidator())->dryRun($this->resolutionFor($payload), $this->envelope())['xml'],
        );

        self::assertStringContainsString('<form:vezen xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0"><form:identifikace>', $xml);
        self::assertStringNotContainsString('zdravPoj', $xml);
        self::assertStringNotContainsString('<form:vymerovaciZakladParagraf5>', $xml);
        self::assertStringNotContainsString('<form:pojisteniZamestnanec>', $xml);
        self::assertStringNotContainsString('<form:vykonavanaPozice>', $xml);
        self::assertStringContainsString(
            '<form:vylouceneDny><form:vylouceneDobyCelkem>3</form:vylouceneDobyCelkem>'
                . '<form:docasNeschopnost>3</form:docasNeschopnost>'
                . '<form:penezitaPomocMaterstvi>0</form:penezitaPomocMaterstvi>'
                . '<form:vyloucenePar18>3</form:vyloucenePar18></form:vylouceneDny>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:mzda><form:nahrady><form:docasnaNeschopnost>300</form:docasnaNeschopnost></form:nahrady></form:mzda>',
            $xml,
        );
        self::assertStringContainsString('<form:mzdaCista><form:mzdaCista>734</form:mzdaCista>', $xml);
        self::assertSame([], $this->failedControls($xml, [36, 165]));
    }

    /**
     * Ošetřování člena rodiny formulář vězně nevede: hodnota by se ztratila,
     * proto je to nález na vztahu, ne mlčení.
     */
    public function testPrisonerCareDaysAreRefused(): void
    {
        $payload = $this->specialScenarioPayload('scenario_4', '1', '2');
        $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
        $section['excluded_days_total'] = 2;
        $section['excluded_days'] = [
            'docasNeschopnost' => 0,
            'penezitaPomocMaterstvi' => 0,
            'osetrovaniClenaRodiny' => 2,
            'otcovska' => 0,
            'vyloucenePar16' => 0,
        ];
        unset($section);

        $this->expectExceptionObject(new JmhzXmlException(
            'jmhz_xml_prisoner_excluded_days_unsupported',
            'Formulář vězně nemá kam zapsat vyloučené doby atributu 10360.',
        ));
        $this->refuse($payload);
    }

    /** Scénář 5 (`formJinyPrijem.xsd`): identifikace, souhrn a daňový základ. */
    public function testOtherIncomeIsSerializedOnJinyPrijemFormValidAgainstSchema(): void
    {
        $xml = (string) preg_replace(
            '/>\s+</',
            '><',
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($this->uninsuredPayload('scenario_5', '13')),
                $this->envelope(),
            )['xml'],
        );

        self::assertStringContainsString(
            '<form:jinyPrijem xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0"><form:identifikace>',
            $xml,
        );
        self::assertStringContainsString(
            '</form:souhrnDataZec><form:prijem><form:dan><form:zakladDane>1000</form:zakladDane></form:dan></form:prijem></form:jinyPrijem>',
            $xml,
        );
        self::assertStringNotContainsString('<form:pojisteni>', $xml);
        self::assertStringNotContainsString('<form:mzdaCista>', $xml);
        self::assertStringNotContainsString('zdravPoj', $xml);
    }

    /**
     * Formulář jiného příjmu pojištění nenese. Vztah s vyměřovacím základem
     * by v něm pojistné zamlčel - je to nález na vztahu.
     */
    public function testInsuredOtherIncomeIsRefused(): void
    {
        $payload = $this->specialScenarioPayload('scenario_5', '14', '1');

        $this->expectExceptionObject(new JmhzXmlException(
            'jmhz_scenario_social_insurance_unreportable',
            'Formulář jiného příjmu a mezinárodního pronájmu pracovní síly'
                . ' nenese pojištění (atributy 10477, 10370, 10481), vztah ale'
                . ' vyměřovací základ nebo pojistné má.',
        ));
        $this->refuse($payload);
    }

    /**
     * Scénář 6 (`formMezinarodniPronajemSily.xsd`): zúžený souhrn bez
     * osvobozeného příjmu a bonusu, z výsledku ročního zúčtování jen 10321.
     */
    public function testInternationalHireCarriesTheReducedSummaryValidAgainstSchema(): void
    {
        $payload = $this->uninsuredPayload('scenario_6', '12');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['exempt_income_minor'] = 0;
        unset($employment);

        $xml = (string) preg_replace(
            '/>\s+</',
            '><',
            (new JmhzScenario1XmlValidator())->dryRun($this->resolutionFor($payload), $this->envelope())['xml'],
        );

        self::assertStringContainsString(
            '<form:mezinarodniPronajemSily xmlns:form="http://schemas.cssz.cz/JMHZ/form/1.0"><form:identifikace>',
            $xml,
        );
        self::assertStringContainsString(
            '<form:prijmy><form:zuctovanoCelkem>1000</form:zuctovanoCelkem></form:prijmy>',
            $xml,
        );
        self::assertStringNotContainsString('<form:danBonus>', $xml);
        self::assertStringNotContainsString('<form:pojisteni>', $xml);
    }

    /**
     * Obsahová oprava a dílčí balíky staví formulář součásti stejnou cestou
     * jako řádné hlášení, takže opravený formulář vězně i jiného příjmu je
     * týž typ formuláře s typem O.
     */
    public function testContentCorrectionKeepsTheSpecialFormType(): void
    {
        foreach ([
            'vezen' => $this->specialScenarioPayload('scenario_4', '1', '2'),
            'jinyPrijem' => $this->uninsuredPayload('scenario_5', '11'),
            'mezinarodniPronajemSily' => $this->uninsuredPayload('scenario_6', '12'),
        ] as $body => $payload) {
            $result = (new JmhzScenario1XmlValidator())->dryRunCorrection(
                $this->resolutionFor($payload),
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
                        affectsSummary: false,
                        affectsPvpoj: false,
                    ),
                ]),
            );

            self::assertStringContainsString("<form:{$body} ", $result['xml'], $body);
            self::assertStringContainsString('<typFormulare>O</typFormulare>', $result['xml']);
            self::assertStringContainsString('<formularePocetVBaliku>1</formularePocetVBaliku>', $result['xml']);
        }
    }

    /**
     * Formuláře vězně, jiného příjmu a mezinárodního pronájmu síly staví
     * souhrnná data zaměstnance toutéž cestou jako formulář bez příznaku.
     * Test projde všechny daňové údaje souhrnu (příjmy, záloha, srážková daň,
     * slevy, měsíční a roční zvýhodnění na děti, výsledek ročního zúčtování)
     * a u každého typu formuláře ověří, že hodnota stojí pod jeho elementem.
     * Pronájem síly vede zúžený souhrn, takže se u něj ověřuje i vynechání.
     */
    public function testSpecialFormsCarryTheEmployeeTaxSummary(): void
    {
        foreach ($this->specialFormSummaryCases() as $body => [$source, $international]) {
            $payload = $this->payloadWithChildCredit();
            $payload['scope'] = $source['scope'];
            $payload['people'][0]['employments'][0] = $source['people'][0]['employments'][0];
            $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
            $payload['people'][0]['person_summary']['statutory']['social_insurance']
                = $source['people'][0]['person_summary']['statutory']['social_insurance'];
            $document = $this->resolutionFor($payload)->requireResolvedDocument()->payload;
            $document['people'][0]['summary'] = self::richTaxSummary(
                $document['people'][0]['summary'],
                $international,
            );

            $xml = (new JmhzScenario1XmlValidator())->dryRun(
                new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($document), []),
                $this->envelope(),
            )['xml'];
            $dom = new \DOMDocument();
            $dom->loadXML($xml);
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('j', 'http://schemas.cssz.cz/JMHZ/podani/1.0');
            $xpath->registerNamespace('form', 'http://schemas.cssz.cz/JMHZ/form/1.0');

            foreach (self::expectedSummaryValues($international) as $path => $expected) {
                $query = '/j:jmhz/j:formulareOsob/j:formularOsoby/form:' . $body . '/'
                    . implode('/', array_map(static fn (string $step): string => 'form:' . $step, explode('/', $path)));
                $nodes = $xpath->query($query);
                if ($expected === null) {
                    self::assertSame(0, $nodes->length, "{$body}: {$path} se nemá vykázat.");
                    continue;
                }
                self::assertSame(
                    (array) $expected,
                    array_map(static fn (\DOMNode $node): string => $node->textContent, iterator_to_array($nodes)),
                    "{$body}: {$path}",
                );
            }
        }
    }

    /** @return array<string, array{array<string,mixed>, bool}> */
    private function specialFormSummaryCases(): array
    {
        return [
            'vezen' => [$this->specialScenarioPayload('scenario_4', '1', '2'), false],
            'jinyPrijem' => [$this->uninsuredPayload('scenario_5', '13'), false],
            'mezinarodniPronajemSily' => [$this->uninsuredPayload('scenario_6', '12'), true],
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function richTaxSummary(array $summary, bool $international): array
    {
        $summary['income_total_czk'] = 20_000;
        $summary['exempt_income_czk'] = $international ? null : 500;
        $summary['advance_tax_czk'] = [
            'base' => 19_500,
            'computed' => 2_925,
            'after_credits' => 355,
            'bonus' => $international ? 0 : 1_200,
            'taxable_income' => 19_500,
        ];
        $summary['withholding_tax_czk'] = ['base' => 3_000, 'tax' => 450];
        $summary['tax_credits_czk'] = [
            'basic' => 2_570,
            'disability_basic' => $international ? null : 210,
            'disability_extended' => $international ? null : 420,
            'ztp_p' => $international ? null : 1_345,
        ];
        $summary['child_credit'] = $international ? null : [
            'monthly_credit_czk' => 2_000,
            'applied_credit_czk' => 1_800,
            'other_household_caregiver' => true,
            'other_household_caregivers' => [
                ['given_name' => 'Petr', 'family_name' => 'Novák', 'birth_number' => '9004110000'],
            ],
            'children' => [
                [
                    'identity' => ['given_name' => 'Jana', 'family_name' => 'Nováková', 'birth_date' => '2015-04-11'],
                    'ztp_p' => true,
                    'order' => '1',
                ],
                [
                    'identity' => ['given_name' => 'Eva', 'family_name' => 'Nováková', 'birth_number' => '1752030000'],
                    'ztp_p' => false,
                    'order' => '2',
                ],
            ],
        ];
        $summary['annual'] = [
            'withholding' => ['paid_income_czk' => 36_000, 'withholding_tax_czk' => 5_400],
            'requested' => true,
            'performed' => true,
            'result' => [
                'settlement_difference_czk' => 1_500,
                'tax_difference_czk' => 1_000,
                'bonus_difference_czk' => 500,
                'spouse_credit_claimed' => false,
                'child_credit_claimed' => true,
                'child_credit_details' => [
                    'other_household_caregiver' => true,
                    'other_household_caregivers' => [[
                        'identity' => ['given_name' => 'Petr', 'family_name' => 'Novák', 'birth_date' => '1990-04-11'],
                        'months_mask' => 'AAAAAANNNNNN',
                    ]],
                    'children' => [[
                        'identity' => ['given_name' => 'Jana', 'family_name' => 'Nováková', 'birth_number' => '1554110000'],
                        'ztp_p_months_mask' => 'NNNNNNAAAAAA',
                        'order_months_mask' => '111111111111',
                    ]],
                ],
            ],
        ];

        return $summary;
    }

    /**
     * Cesta pod souhrnem => očekávané hodnoty (výskyty v pořadí), null = nesmí být.
     *
     * @return array<string, list<string>|string|null>
     */
    private static function expectedSummaryValues(bool $international): array
    {
        $base = 'souhrnDataZec/';
        $declaration = $base . 'prohlaseniPoplatnikaDane/';
        $monthly = $declaration . 'zvyhodneniDetiMesic/';
        $annual = $base . 'rocniUhrny/';
        $result = $annual . 'vysledekRocnihoZuctovani/';
        $annualChildren = $result . 'zvyhodneniNaDeti/';
        $common = [
            $base . 'prijmy/zuctovanoCelkem' => '20000',
            $base . 'zalohaNaDan/zakladDane' => '19500',
            $base . 'zalohaNaDan/vypoctenaZaloha' => '2925',
            $base . 'zalohaNaDan/danZalohaPoSleve' => '355',
            $base . 'zvlastniSazbaDane/zakladDane' => '3000',
            $base . 'zvlastniSazbaDane/srazenaDan' => '450',
            $base . 'prohlaseniPoplatnika' => 'true',
            $declaration . 'zakladniSleva' => '2570',
            $annual . 'prijemSrazkDanZvlSazba' => '36000',
            $annual . 'danSrazenaZvlSazba' => '5400',
            $annual . 'rocniZuctovaniZadost' => 'true',
            $annual . 'rocniZuctovaniProvedeno' => 'true',
            $result . 'preplatekRok' => '1500',
        ];
        if ($international) {
            return $common + [
                $base . 'prijmy/osvobozenoCelkem' => null,
                $base . 'zalohaNaDan/danBonus' => null,
                $declaration . 'zakladniSlevaInvalidita12' => null,
                $monthly . 'vyzivujeJinaOsoba' => null,
                $result . 'danPreplatekRok' => null,
                $result . 'uplatnenoZvyhodneniNaDeti' => null,
            ];
        }

        return $common + [
            $base . 'prijmy/osvobozenoCelkem' => '500',
            $base . 'zalohaNaDan/danBonus' => '1200',
            $declaration . 'zakladniSlevaInvalidita12' => '210',
            $declaration . 'rozsirenaSlevaInvalidita3' => '420',
            $declaration . 'slevaZTPP' => '1345',
            $declaration . 'danoveZvyhodneniDetiMesic' => '2000',
            $declaration . 'slevaDite' => '1800',
            $monthly . 'vyzivujeJinaOsoba' => 'true',
            $monthly . 'jineOsoby/jinaOsoba/jmeno' => 'Petr',
            $monthly . 'jineOsoby/jinaOsoba/prijmeni' => 'Novák',
            $monthly . 'jineOsoby/jinaOsoba/rodneCislo' => '9004110000',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/jmeno' => ['Jana', 'Eva'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/prijmeni' => ['Nováková', 'Nováková'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/datumNarozeni' => '2015-04-11',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/rodneCislo' => '1752030000',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/prukazZtpp' => ['true', 'false'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/poradi' => ['1', '2'],
            $result . 'danPreplatekRok' => '1000',
            $result . 'danBonusPreplatekRok' => '500',
            $result . 'uplatnenaSlevaNaPartnera' => 'false',
            $result . 'uplatnenoZvyhodneniNaDeti' => 'true',
            $annualChildren . 'vyzivujeJinaOsoba' => 'true',
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/jmeno' => 'Petr',
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/prijmeni' => 'Novák',
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/datumNarozeni' => '1990-04-11',
            $annualChildren . 'jineOsoby/jinaOsoba/mesiceVyzivovani' => 'AAAAAANNNNNN',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/jmeno' => 'Jana',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/prijmeni' => 'Nováková',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/rodneCislo' => '1554110000',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/prukazZtpp' => 'NNNNNNAAAAAA',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/poradi' => '111111111111',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function summaryFormBodies(): iterable
    {
        foreach (['bezPriznaku', 'cinnostKS', 'odlozenyPrijem', 'vezen', 'jinyPrijem'] as $body) {
            yield $body => [$body];
        }
    }

    /**
     * Matice souhrnných dat zaměstnance přes všechny formuláře, které souhrn
     * vedou celý: každý údaj souhrnu (příjmy s příspěvky zaměstnavatele, záloha
     * a srážková daň, slevy, měsíční a roční zvýhodnění na děti se dvěma jinými
     * vyživujícími osobami, výsledek ročního zúčtování, čistá mzda a zdravotní
     * pojištění) má pod elementem daného formuláře přesně očekávanou hodnotu,
     * nebo tam podle typu souhrnu není vůbec. Výstup projde připnutým XSD.
     *
     * Jiná osoba a dítě se identifikují buď datem narození, nebo rodným číslem:
     * ten z údajů, který chybí, se nevypíše (datum 10433/10443/10437/10448
     * a rodné číslo 10434/10444/10438/10449 jsou nepovinné, pokud je druhý
     * vyplněn). Průkaz ZTP/P bez jediného měsíce se v roční části neuvádí.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('summaryFormBodies')]
    public function testEveryFormBodyCarriesTheFullEmployeeSummary(string $body): void
    {
        $xpath = $this->summaryMatrixXpath($this->summaryMatrixDocument($body));

        foreach (self::fullSummaryExpectations($body) as $path => $expected) {
            $nodes = $xpath->query(self::formQuery($body, $path));
            if ($expected === null) {
                self::assertSame(0, $nodes->length, "{$body}: {$path} se nemá vykázat.");
                continue;
            }
            self::assertSame(
                (array) $expected,
                array_map(static fn (\DOMNode $node): string => $node->textContent, iterator_to_array($nodes)),
                "{$body}: {$path}",
            );
        }
    }

    /**
     * Bez jiné vyživující osoby (10453 a 10455 = ne) se seznam jiných osob
     * nevede ani v měsíční, ani v roční části: jméno, příjmení, datum
     * narození, rodné číslo a měsíce vyživování jsou povinné jen v rámci
     * interakcí IN23 (roční) a obdobné měsíční.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('summaryFormBodies')]
    public function testOtherHouseholdCaregiversAreOmittedWithoutTheInteraction(string $body): void
    {
        $document = $this->summaryMatrixDocument($body);
        $summary = &$document['people'][0]['summary'];
        $summary['child_credit']['other_household_caregiver'] = false;
        $summary['child_credit']['other_household_caregivers'] = [];
        $summary['annual']['result']['child_credit_details']['other_household_caregiver'] = false;
        $summary['annual']['result']['child_credit_details']['other_household_caregivers'] = [];
        unset($summary);
        $xpath = $this->summaryMatrixXpath($document);

        $declaration = 'souhrnDataZec/prohlaseniPoplatnikaDane/zvyhodneniDetiMesic/';
        $annual = 'souhrnDataZec/rocniUhrny/vysledekRocnihoZuctovani/zvyhodneniNaDeti/';
        foreach ([
            $declaration . 'vyzivujeJinaOsoba' => ['false'],
            $declaration . 'jineOsoby' => [],
            $annual . 'vyzivujeJinaOsoba' => ['false'],
            $annual . 'jineOsoby' => [],
            $annual . 'vyzivovaneDeti/vyzivovaneDite/dite/jmeno' => ['Jana', 'Eva'],
        ] as $path => $expected) {
            self::assertSame(
                $expected,
                array_map(
                    static fn (\DOMNode $node): string => $node->textContent,
                    iterator_to_array($xpath->query(self::formQuery($body, $path))),
                ),
                "{$body}: {$path}",
            );
        }
    }

    /**
     * Roční údaje souhrnu patří jen do hlášení za určené měsíce: výsledek
     * ročního zúčtování a jeho rozpad do ledna až března (kontrola 191),
     * žádost o roční zúčtování do ledna a února (kontrola 192) a roční úhrny
     * srážkové daně jen do ledna (kontrola 193). Hlášení za jiný měsíc
     * s týmiž údaji kontroly zamítnou na každém formuláři.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('summaryFormBodies')]
    public function testAnnualSummaryDataAreAcceptedOnlyInTheirMonths(string $body): void
    {
        foreach ([1 => [], 2 => [193], 3 => [192, 193], 7 => [191, 192, 193]] as $month => $failed) {
            $xml = $this->summaryMatrixXml($this->summaryMatrixDocument($body, $month));

            self::assertSame($failed, $this->failedControls($xml, [191, 192, 193]), "{$body}: měsíc {$month}");
        }
    }

    /**
     * Rozpad příspěvku zaměstnavatele (10292–10296, 10418) a úhrn 10417 jsou
     * v XSD typu `cislo14Type`/`cisloN14Type` celé číslo bez desetinné části:
     * hodnota mimo vzor se nedostane ani přes serializér, ani přes schéma.
     */
    public function testEmployerContributionOutsideTheNumericPatternFailsTheSchema(): void
    {
        $document = $this->summaryMatrixDocument('bezPriznaku');
        $xml = $this->summaryMatrixXml($document);
        $broken = str_replace(
            '<form:prispevekZivotPoj>30</form:prispevekZivotPoj>',
            '<form:prispevekZivotPoj>-30</form:prispevekZivotPoj>',
            $xml,
        );
        self::assertNotSame($xml, $broken);

        $this->expectException(JmhzXmlException::class);
        (new JmhzScenario1XmlValidator())->validateFrozen($broken);
    }

    /** @return iterable<string, array{string, string}> */
    public static function identifiedFormBodies(): iterable
    {
        foreach ([
            'bezPriznaku' => '1',
            'cinnostKS' => 'K',
            'odlozenyPrijem' => '1',
            'vezen' => '1',
            'jinyPrijem' => '13',
            'mezinarodniPronajemSily' => '12',
        ] as $body => $activity) {
            yield $body => [$body, $activity];
        }
    }

    /**
     * Identifikace zaměstnance na každém typu formuláře: s přidělenými čísly
     * ČSSZ jen OIČ (10051) a ID PPV (10228), bez nich jmenná větev
     * s příjmením, jménem, datem narození, datem nástupu a druhem činnosti
     * vztahu (10053, 10054, 10056, 10223, 10239).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('identifiedFormBodies')]
    public function testEveryFormBodyIdentifiesTheEmployee(string $body, string $activity): void
    {
        $document = $this->summaryMatrixDocument($body);
        $xpath = $this->summaryMatrixXpath($document);
        $read = static fn (\DOMXPath $xpath, string $path): array => array_map(
            static fn (\DOMNode $node): string => $node->textContent,
            iterator_to_array($xpath->query(self::formQuery($body, $path))),
        );
        self::assertSame(['1000000001'], $read($xpath, 'identifikace/ikMpsv'), $body);
        self::assertSame(['2000000000000000000001'], $read($xpath, 'identifikace/idPpv'), $body);
        self::assertSame([], $read($xpath, 'identifikace/prijmeni'), $body);
        self::assertSame(1, $xpath->query(self::formQuery($body, 'souhrnDataZec'))->length, $body);
        foreach ([
            'idFormulare' => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10',
            'typFormulare' => 'R',
            'primarniPpv' => 'true',
        ] as $element => $value) {
            self::assertSame(
                [$value],
                array_map(
                    static fn (\DOMNode $node): string => $node->textContent,
                    iterator_to_array($xpath->query('/j:jmhz/j:formulareOsob/j:formularOsoby/j:hlavicka/j:' . $element)),
                ),
                "{$body}: hlavicka/{$element}",
            );
        }
        self::assertSame(['1000'], $read($xpath, 'prijem/dan/zakladDane'), $body);

        $named = $document;
        $named['people'][0]['employments'][0]['identity']['person_external_identifier'] = null;
        $named['people'][0]['employments'][0]['identity']['employment_external_identifier'] = null;
        $xpath = $this->summaryMatrixXpath($named);
        foreach ([
            'identifikace/ikMpsv' => [],
            'identifikace/idPpv' => [],
            'identifikace/prijmeni' => ['Nováková'],
            'identifikace/jmeno' => ['Jana'],
            'identifikace/datumNarozeni' => ['1990-04-12'],
            'identifikace/datumNastupu' => ['2026-03-01'],
            'identifikace/druhCinnosti' => [$activity],
        ] as $path => $expected) {
            self::assertSame($expected, $read($xpath, $path), "{$body}: {$path}");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function insuredFormBodies(): iterable
    {
        foreach (['bezPriznaku', 'cinnostKS', 'odlozenyPrijem', 'vezen'] as $body) {
            yield $body => [$body];
        }
    }

    /**
     * Blok pojištění podle typu formuláře: trvání pojištění (10354, 10355)
     * mimo odložený příjem, vyměřovací základ a příjem z nepojištěné činnosti
     * (10477, 10476) a pojistné (10370, 10481) mimo vězně, rozpad podle § 5a
     * (10478–10480) jen bez příznaku a u odloženého příjmu, ELDP s kódem,
     * platností, dny, vyměřovacím základem a vyloučenými dobami (u vězně
     * zúžený rozpad), sleva zaměstnance s výší a příznak slevy zaměstnavatele.
     * Odložený příjem nese typ (10548) a ELDP po období (10537, 10538).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('insuredFormBodies')]
    public function testEveryFormBodyCarriesItsInsuranceBlock(string $body): void
    {
        $prisoner = $body === 'vezen';
        $document = $this->summaryMatrixDocument($body, 7, static function (array &$payload) use ($prisoner): void {
            $section = &$payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0];
            $section['excluded_days'] = [
                'docasNeschopnost' => 5,
                'penezitaPomocMaterstvi' => 4,
                'osetrovaniClenaRodiny' => $prisoner ? 0 : 3,
                'otcovska' => $prisoner ? 0 : 2,
                'vyloucenePar16' => $prisoner ? 0 : 1,
            ];
            $section['excluded_days_total'] = $prisoner ? 9 : 15;
            $section['section18_days'] = [
                'omluvenaNepritomnost' => 2,
                'pracovniNeschopnost' => 1,
                'vyplaceniDavek' => 0,
            ];
            $section['section18_days_total'] = 3;
            unset($section);
        });
        $employment = &$document['people'][0]['employments'][0];
        $employment['social_base']['reported_income_czk'] = 1_250;
        $employment['employee_social_discount'] = ['amount_czk' => 120];
        unset($employment);

        $deferred = $body === 'odlozenyPrijem';
        $eldp = $deferred ? 'pojisteni/eldpObdobi/obdobi/eldpSeznam/eldp/' : 'pojisteni/eldpSeznam/eldp/';
        $withBase = !$prisoner;
        $withSplit = in_array($body, ['bezPriznaku', 'odlozenyPrijem'], true);
        $expected = [
            'typ' => $deferred ? '1' : null,
            'pojisteni/trvani/pojisteniOd' => $deferred ? null : '2026-07-01',
            'pojisteni/trvani/pojisteniDo' => $deferred ? null : '2026-07-31',
            'pojisteni/vymerovaciZaklad/castkaOdvodPojistneho' => $withBase ? '1000' : null,
            'pojisteni/vymerovaciZaklad/prijemNepojistenaCinnost' => $withBase ? '1250' : null,
            'pojisteni/vymerovaciZakladParagraf5/pismenoA' => $withSplit ? '1000' : null,
            'pojisteni/eldpObdobi/obdobi/mesic' => $deferred ? '6' : null,
            'pojisteni/eldpObdobi/obdobi/rok' => $deferred ? '2026' : null,
            $eldp . 'kod' => '1++',
            $eldp . 'platnostOd' => '2026-07-01',
            $eldp . 'platnostDo' => '2026-07-31',
            $eldp . 'pocetDnu' => '31',
            $eldp . 'vymerovaciZaklad' => '1000',
            $eldp . 'vylouceneDny/vylouceneDobyCelkem' => $prisoner ? '9' : '15',
            $eldp . 'vylouceneDny/docasNeschopnost' => '5',
            $eldp . 'vylouceneDny/penezitaPomocMaterstvi' => '4',
            $eldp . 'vylouceneDny/osetrovaniClenaRodiny' => $prisoner ? null : '3',
            $eldp . 'vylouceneDny/otcovska' => $prisoner ? null : '2',
            $eldp . 'vylouceneDny/vyloucenePar16' => $prisoner ? null : '1',
            $eldp . 'vylouceneDny/vyloucenePar18' => '3',
            'pojisteni/pojisteniZamestnanec/socialniPojisteni' => $withBase ? '71' : null,
            'pojisteni/pojisteniZamestnavatel/socialniPojisteni' => $withBase ? '248' : null,
            'pojisteni/slevaZamestnance/slevaZamestnanceEvidovana' => 'true',
            'pojisteni/slevaZamestnance/slevaZamestnance/vyseSlevy' => '120',
            'pojisteni/slevaZamestnavatele/slevaZamestnavateleEvidovana' => $withSplit ? 'false' : null,
        ];
        $xpath = $this->summaryMatrixXpath($document);
        foreach ($expected as $path => $value) {
            self::assertSame(
                $value === null ? [] : [$value],
                array_map(
                    static fn (\DOMNode $node): string => $node->textContent,
                    iterator_to_array($xpath->query(self::formQuery($body, $path))),
                ),
                "{$body}: {$path}",
            );
        }

        if (!$withSplit) {
            return;
        }
        foreach (['b' => 'pismenoB', 'c' => 'pismenoC'] as $letter => $element) {
            $split = $document;
            $split['people'][0]['employments'][0]['social_base']['paragraph5_letter'] = $letter;
            $xpath = $this->summaryMatrixXpath($split);
            foreach (['pismenoA', 'pismenoB', 'pismenoC'] as $candidate) {
                self::assertSame(
                    $candidate === $element ? ['1000'] : [],
                    array_map(
                        static fn (\DOMNode $node): string => $node->textContent,
                        iterator_to_array($xpath->query(
                            self::formQuery($body, 'pojisteni/vymerovaciZakladParagraf5/' . $candidate),
                        )),
                    ),
                    "{$body}: § 5a písm. {$letter}",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function summaryMatrixDocument(string $body, int $month = 7, ?callable $adjust = null): array
    {
        $international = $body === 'mezinarodniPronajemSily';
        $payload = $international ? $this->uninsuredPayload('scenario_6', '12') : $this->payloadWithChildCredit();
        $source = match ($body) {
            'vezen' => $this->specialScenarioPayload('scenario_4', '1', '2'),
            'jinyPrijem' => $this->uninsuredPayload('scenario_5', '13'),
            default => null,
        };
        if ($adjust !== null) {
            $adjust($payload);
            if ($source !== null) {
                $adjust($source);
            }
        }
        if ($source !== null) {
            $payload['scope'] = $source['scope'];
            $payload['people'][0]['employments'][0] = $source['people'][0]['employments'][0];
            $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
            $payload['people'][0]['person_summary']['statutory']['social_insurance']
                = $source['people'][0]['person_summary']['statutory']['social_insurance'];
        }
        $document = $this->resolutionFor($payload)->requireResolvedDocument()->payload;
        $employment = &$document['people'][0]['employments'][0];
        if ($body === 'cinnostKS') {
            $employment['selector'] = [
                'scenario_key' => 'scenario_3',
                'activity_code' => 'K',
                'relationship_detail_code' => '1',
            ];
        }
        if ($body === 'odlozenyPrijem') {
            $employment['selector']['scenario_key'] = 'scenario_8';
            $employment['eldp']['deferred_income'] = [
                'type' => '1',
                'periods' => [['month' => 6, 'year' => 2026]],
            ];
        }
        unset($employment);
        $document['header']['month'] = $month;
        if (!$international) {
            $document['people'][0]['summary'] = self::fullTaxSummary($document['people'][0]['summary']);
        }

        return $document;
    }

    /** @param array<string,mixed> $document */
    private function summaryMatrixXml(array $document): string
    {
        return (new JmhzScenario1XmlValidator())->dryRun(
            new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($document), []),
            $this->envelope(),
        )['xml'];
    }

    /** @param array<string,mixed> $document */
    private function summaryMatrixXpath(array $document): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->summaryMatrixXml($document));
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('j', 'http://schemas.cssz.cz/JMHZ/podani/1.0');
        $xpath->registerNamespace('form', 'http://schemas.cssz.cz/JMHZ/form/1.0');

        return $xpath;
    }

    private static function formQuery(string $body, string $path): string
    {
        return '/j:jmhz/j:formulareOsob/j:formularOsoby/form:' . $body . '/'
            . implode('/', array_map(static fn (string $step): string => 'form:' . $step, explode('/', $path)));
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private static function fullTaxSummary(array $summary): array
    {
        $summary = self::richTaxSummary($summary, false);
        $summary['employer_contributions_czk'] = [
            '10417' => 300,
            '10418' => 40,
            '10292' => 100,
            '10293' => 60,
            '10294' => 50,
            '10295' => 30,
            '10296' => 20,
        ];
        $summary['net_income_czk'] = 15_000;
        $summary['deductions_recorded'] = true;
        $summary['employee_health_czk'] = 900;
        $summary['employer_health_czk'] = 1_800;
        $summary['child_credit']['other_household_caregivers'] = [
            ['given_name' => 'Petr', 'family_name' => 'Novák', 'birth_date' => '1990-04-11'],
            ['given_name' => 'Karel', 'family_name' => 'Dvořák', 'birth_number' => '8501010000'],
        ];
        $details = &$summary['annual']['result']['child_credit_details'];
        $details['other_household_caregivers'] = [
            [
                'identity' => ['given_name' => 'Petr', 'family_name' => 'Novák', 'birth_date' => '1990-04-11'],
                'months_mask' => 'AAAAAANNNNNN',
            ],
            [
                'identity' => ['given_name' => 'Karel', 'family_name' => 'Dvořák', 'birth_number' => '8501010000'],
                'months_mask' => 'NNNNNNAAAAAA',
            ],
        ];
        $details['children'][] = [
            'identity' => ['given_name' => 'Eva', 'family_name' => 'Nováková', 'birth_date' => '2017-02-03'],
            'ztp_p_months_mask' => 'NNNNNNNNNNNN',
            'order_months_mask' => '222222222222',
        ];
        unset($details);

        return $summary;
    }

    /**
     * Cesta pod formulářem => očekávané hodnoty (výskyty v pořadí), null = nesmí být.
     *
     * @return array<string, list<string>|string|null>
     */
    private static function fullSummaryExpectations(string $body): array
    {
        $base = 'souhrnDataZec/';
        $contributions = $base . 'prijmy/prispevekZamestnavatele/';
        $declaration = $base . 'prohlaseniPoplatnikaDane/';
        $monthly = $declaration . 'zvyhodneniDetiMesic/';
        $annual = $base . 'rocniUhrny/';
        $result = $annual . 'vysledekRocnihoZuctovani/';
        $annualChildren = $result . 'zvyhodneniNaDeti/';
        $withContributions = in_array($body, ['bezPriznaku', 'cinnostKS', 'odlozenyPrijem'], true);
        $withNetPay = in_array($body, ['bezPriznaku', 'vezen', 'odlozenyPrijem'], true);
        $withEmployerHealth = in_array($body, ['bezPriznaku', 'odlozenyPrijem'], true);
        $withEmployeeHealth = in_array($body, ['bezPriznaku', 'cinnostKS', 'odlozenyPrijem'], true);

        return [
            $base . 'prijmy/zuctovanoCelkem' => '20000',
            $base . 'prijmy/osvobozenoCelkem' => '500',
            $contributions . 'prispevekZelSporeniOsvob' => $withContributions ? '300' : null,
            $contributions . 'prispevekZelPojDlPece' => $withContributions ? '40' : null,
            $contributions . 'prispevekPenzPripoj' => $withContributions ? '100' : null,
            $contributions . 'prispevekDoplnPenzPripoj' => $withContributions ? '60' : null,
            $contributions . 'prispevekPenzPoj' => $withContributions ? '50' : null,
            $contributions . 'prispevekZivotPoj' => $withContributions ? '30' : null,
            $contributions . 'prispevekDip' => $withContributions ? '20' : null,
            $base . 'zalohaNaDan/zakladDane' => '19500',
            $base . 'zalohaNaDan/vypoctenaZaloha' => '2925',
            $base . 'zalohaNaDan/danZalohaPoSleve' => '355',
            $base . 'zalohaNaDan/danBonus' => '1200',
            $base . 'zvlastniSazbaDane/zakladDane' => '3000',
            $base . 'zvlastniSazbaDane/srazenaDan' => '450',
            $base . 'prohlaseniPoplatnika' => 'true',
            $declaration . 'zakladniSleva' => '2570',
            $declaration . 'zakladniSlevaInvalidita12' => '210',
            $declaration . 'rozsirenaSlevaInvalidita3' => '420',
            $declaration . 'slevaZTPP' => '1345',
            $declaration . 'danoveZvyhodneniDetiMesic' => '2000',
            $declaration . 'slevaDite' => '1800',
            $monthly . 'vyzivujeJinaOsoba' => 'true',
            $monthly . 'jineOsoby/jinaOsoba/jmeno' => ['Petr', 'Karel'],
            $monthly . 'jineOsoby/jinaOsoba/prijmeni' => ['Novák', 'Dvořák'],
            $monthly . 'jineOsoby/jinaOsoba/datumNarozeni' => '1990-04-11',
            $monthly . 'jineOsoby/jinaOsoba/rodneCislo' => '8501010000',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/jmeno' => ['Jana', 'Eva'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/prijmeni' => ['Nováková', 'Nováková'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/datumNarozeni' => '2015-04-11',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/dite/rodneCislo' => '1752030000',
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/prukazZtpp' => ['true', 'false'],
            $monthly . 'vyzivovaneDeti/vyzivovaneDite/poradi' => ['1', '2'],
            $annual . 'prijemSrazkDanZvlSazba' => '36000',
            $annual . 'danSrazenaZvlSazba' => '5400',
            $annual . 'rocniZuctovaniZadost' => 'true',
            $annual . 'rocniZuctovaniProvedeno' => 'true',
            $result . 'preplatekRok' => '1500',
            $result . 'danPreplatekRok' => '1000',
            $result . 'danBonusPreplatekRok' => '500',
            $result . 'uplatnenaSlevaNaPartnera' => 'false',
            $result . 'uplatnenoZvyhodneniNaDeti' => 'true',
            $annualChildren . 'vyzivujeJinaOsoba' => 'true',
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/jmeno' => ['Petr', 'Karel'],
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/prijmeni' => ['Novák', 'Dvořák'],
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/datumNarozeni' => '1990-04-11',
            $annualChildren . 'jineOsoby/jinaOsoba/osoba/rodneCislo' => '8501010000',
            $annualChildren . 'jineOsoby/jinaOsoba/mesiceVyzivovani' => ['AAAAAANNNNNN', 'NNNNNNAAAAAA'],
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/jmeno' => ['Jana', 'Eva'],
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/prijmeni' => ['Nováková', 'Nováková'],
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/rodneCislo' => '1554110000',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/dite/datumNarozeni' => '2017-02-03',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/prukazZtpp' => 'NNNNNNAAAAAA',
            $annualChildren . 'vyzivovaneDeti/vyzivovaneDite/poradi' => ['111111111111', '222222222222'],
            $base . 'mzdaCista/mzdaCista' => $withNetPay ? '15000' : null,
            $base . 'mzdaCista/srazkyZeMzdyEvidovany' => $withNetPay ? 'true' : null,
            $base . 'zdravPojZamestnavatel/zdravotniPojisteni' => $withEmployerHealth ? '1800' : null,
            $base . 'zdravPojZamestnanec/zdravotniPojisteni' => $withEmployeeHealth ? '900' : null,
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function positionFormBodies(): iterable
    {
        foreach (['bezPriznaku', 'cinnostKS'] as $body) {
            foreach (['ico', 'foreign'] as $kind) {
                yield "{$body}-{$kind}" => [$body, $kind];
            }
        }
    }

    /**
     * Vykonávaná pozice na formuláři bez příznaku i činnosti K–S: místo výkonu
     * práce (obec, kód obce, stát), mzdový příspěvek APZ s nástrojem (10233
     * jen při 10232 = ANO, interakce IN05) a dočasné přidělení s uživatelem
     * identifikovaným IČO, nebo zahraniční osobou (stát, identifikace, název).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('positionFormBodies')]
    public function testPositionCarriesWorkplaceApzAndTemporaryAssignment(string $body, string $kind): void
    {
        $document = $this->positionDocument($body, $kind);
        $read = function (array $document, string $path) use ($body): array {
            return array_map(
                static fn (\DOMNode $node): string => $node->textContent,
                iterator_to_array($this->summaryMatrixXpath($document)->query(self::formQuery($body, $path))),
            );
        };
        $position = 'vykonavanaPozice/';
        $user = $position . 'docasnePrideleni/uzivatel/';
        foreach ([
            $position . 'mistoVykonuPrace/obec' => ['Brno'],
            $position . 'mistoVykonuPrace/kodObce' => ['582786'],
            $position . 'mistoVykonuPrace/kodStatu' => ['CZ'],
            $position . 'uplatnujiPrispevekApz' => ['true'],
            $position . 'nastrojApzKod' => ['2'],
            $position . 'docasnePrideleniEvidovano' => ['true'],
            $user . 'ico' => $kind === 'ico' ? ['00000019'] : [],
            $user . 'zahranicniOsoba/kodStatu' => $kind === 'foreign' ? ['DE'] : [],
            $user . 'zahranicniOsoba/identifikace' => $kind === 'foreign' ? ['12345678'] : [],
            $user . 'zahranicniOsoba/nazev' => $kind === 'foreign' ? ['Muster GmbH'] : [],
        ] as $path => $expected) {
            self::assertSame($expected, $read($document, $path), "{$body}: {$path}");
        }

        $document['people'][0]['employments'][0]['term']['jmhz_apz_contribution_status'] = 'no';
        self::assertSame(['false'], $read($document, $position . 'uplatnujiPrispevekApz'), $body);
        self::assertSame([], $read($document, $position . 'nastrojApzKod'), $body);
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function codebookControlCases(): iterable
    {
        yield 'stát pracoviště' => [153, 'assignment-ico', '<form:kodStatu>CZ</form:kodStatu>', '<form:kodStatu>QQ</form:kodStatu>'];
        yield 'nástroj APZ' => [154, 'assignment-ico', '<form:nastrojApzKod>2</form:nastrojApzKod>', '<form:nastrojApzKod>9</form:nastrojApzKod>'];
        yield 'stát zahraničního uživatele' => [302, 'assignment-foreign', '<form:kodStatu>DE</form:kodStatu>', '<form:kodStatu>QQ</form:kodStatu>'];
        yield 'kategorizace rizika' => [156, 'risk', '<form:kategorizaceRizika>1</form:kategorizaceRizika>', '<form:kategorizaceRizika>5</form:kategorizaceRizika>'];
        yield 'typ odloženého příjmu' => [331, 'deferred', '<form:typ>1</form:typ>', '<form:typ>9</form:typ>'];
        yield 'druhá pozice kódu ELDP' => [338, 'deferred', '<form:kod>1P+</form:kod>', '<form:kod>1++</form:kod>'];
    }

    /**
     * Číselníkové a odvozené kontroly nad hodnotou, kterou serializér zapsal:
     * zapsaná hodnota kontrolou projde, stejné podání s hodnotou mimo číselník
     * (nebo u odloženého příjmu typu 1 s kódem ELDP bez „P" na druhé pozici)
     * kontrola zamítne.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('codebookControlCases')]
    public function testCodebookControlsJudgeTheSerializedValue(
        int $controlId,
        string $case,
        string $emitted,
        string $broken,
    ): void {
        $document = match ($case) {
            'assignment-ico' => $this->positionDocument('bezPriznaku', 'ico'),
            'assignment-foreign' => $this->positionDocument('bezPriznaku', 'foreign'),
            'risk' => $this->summaryMatrixDocument('bezPriznaku', 7, static function (array &$payload): void {
                $payload['people'][0]['employments'][0]['term']['social_employer_rate_category'] = 'risk_employment';
            }),
            'deferred' => $this->summaryMatrixDocument('odlozenyPrijem', 7, static function (array &$payload): void {
                $payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'] = '1P+';
            }),
        };
        $xml = preg_replace('/>\s+</', '><', $this->summaryMatrixXml($document)) ?? '';
        self::assertStringContainsString($emitted, $xml);

        self::assertSame([JmhzControlOutcome::Passed], $this->controlOutcomes($xml, $controlId));
        self::assertContains(
            JmhzControlOutcome::Failed,
            $this->controlOutcomes(str_replace($emitted, $broken, $xml), $controlId),
        );
    }

    /**
     * Kontroly 336, 337 a 339 porovnávají odložený příjem se stavem vztahu
     * v registru ČSSZ. Lokálně je nelze rozhodnout: u typů, kterých se týkají,
     * se předají cJMHZ (nevyhodnotitelné), u ostatních typů se neuplatní.
     * Aplikace sama vykazuje jen typ 1 po skončení vztahu.
     */
    public function testRegistryDeferredIncomeControlsAreLeftToCssz(): void
    {
        $xml = preg_replace('/>\s+</', '><', $this->summaryMatrixXml(
            $this->summaryMatrixDocument('odlozenyPrijem', 7, static function (array &$payload): void {
                $payload['people'][0]['employments'][0]['eldp']['eldp_sections'][0]['code'] = '1P+';
            }),
        )) ?? '';
        $applies = [336 => ['1', '2', '4', '5', '6'], 337 => ['3'], 339 => ['2', '3', '6']];
        foreach (['1', '2', '3', '4', '5', '6'] as $type) {
            $typed = str_replace('<form:typ>1</form:typ>', "<form:typ>{$type}</form:typ>", $xml);
            foreach ($applies as $controlId => $types) {
                self::assertSame(
                    [in_array($type, $types, true) ? JmhzControlOutcome::NotEvaluable : JmhzControlOutcome::NotApplicable],
                    $this->controlOutcomes($typed, $controlId),
                    "Kontrola {$controlId}, typ {$type}",
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function positionDocument(string $body, string $kind): array
    {
        $document = $this->summaryMatrixDocument($body);
        $term = &$document['people'][0]['employments'][0]['term'];
        $term['jmhz_apz_contribution_status'] = 'yes';
        $term['jmhz_apz_instrument_code'] = '2';
        $term['jmhz_temporary_assignment_status'] = 'yes';
        $term['jmhz_assignment_user_kind'] = $kind;
        if ($kind === 'ico') {
            $term['jmhz_assignment_user_ico'] = '00000019';
        } else {
            $term['jmhz_assignment_user_country_code'] = 'DE';
            $term['jmhz_assignment_user_foreign_id'] = '12345678';
            $term['jmhz_assignment_user_name'] = 'Muster GmbH';
        }
        unset($term);

        return $document;
    }

    /** @return list<JmhzControlOutcome> */
    private function controlOutcomes(string $xml, int $controlId): array
    {
        $report = JmhzControlValidatorFactory::create()
            ->validate($xml, new JmhzControlContext('2026-08-14', schemaValidated: true));
        $outcomes = [];
        foreach ($report->findings as $finding) {
            if ($finding->controlId === $controlId) {
                $outcomes[] = $finding->outcome;
            }
        }

        return $outcomes;
    }

    public function testInternationalHireWithChildCreditIsRefused(): void
    {
        $payload = $this->payloadWithChildCredit();
        $source = $this->uninsuredPayload('scenario_6', '12');
        $payload['scope'] = $source['scope'];
        $payload['people'][0]['employments'][0] = $source['people'][0]['employments'][0];
        $payload['people'][0]['person_summary']['statutory']['social_insurance']
            = $source['people'][0]['person_summary']['statutory']['social_insurance'];
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;

        $this->expectExceptionObject(new JmhzXmlException(
            'jmhz_xml_international_hire_attribute_unsupported',
            'Formulář mezinárodního pronájmu pracovní síly nese ze slev'
                . ' jen základní slevu na poplatníka (10299).',
        ));
        $this->refuse($payload);
    }

    /**
     * Příprava se zařazením scénáře podle druhu činnosti; vztah zůstává
     * pojištěný jako v základním vzorku.
     *
     * @return array<string,mixed>
     */
    private function specialScenarioPayload(
        string $scenarioKey,
        string $activityCode,
        string $detailCode,
    ): array {
        $payload = $this->payload();
        $payload['scope']['scenario_set'] = [$scenarioKey];
        $employment = &$payload['people'][0]['employments'][0];
        $employment['employment']['relation_type'] = 'employment';
        $employment['term']['activity_code'] = $activityCode;
        $employment['term']['jmhz_relationship_detail_code'] = $detailCode;
        $employment['scenario_resolution'] = [
            'scenario_key' => $scenarioKey,
            'activity_code' => $activityCode,
            'relationship_detail_code' => $detailCode,
        ];
        unset($employment);

        return $payload;
    }

    /**
     * Druh činnosti 11 až 14: vztah mimo pojištění, bez vyměřovacího základu,
     * pojistného i dnů ELDP.
     *
     * @return array<string,mixed>
     */
    private function uninsuredPayload(string $scenarioKey, string $activityCode): array
    {
        $payload = $this->specialScenarioPayload($scenarioKey, $activityCode, '1');
        $payload['people'][0]['person_summary']['statutory']['social_insurance'] = [
            'status' => 'calculated',
            'issues' => [],
            'capped_assessment_base_minor_units' => 0,
            'employee_contribution_minor_units' => 0,
            'employer_contribution_minor_units' => 0,
        ];
        $employment = &$payload['people'][0]['employments'][0];
        $employment['insurance']['participation']['status'] = 'does_not_participate';
        $employment['insurance']['capped_assessment_base_minor_units'] = 0;
        $employment['eldp']['eldp_sections'][0] = [
            'ordinal' => 1,
            'code' => null,
            'valid_from' => null,
            'valid_to' => null,
            'insurance_days' => 0,
            'assessment_base_czk' => null,
            'excluded_days' => null,
            'deducted_days' => null,
        ];
        unset($employment);

        return $payload;
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

    /**
     * Vektor výdělků s příplatky (10332–10336), náhradami (10337–10342)
     * a odměnou za pohotovost (10343). Tarif 800 Kč a příplatky 200 Kč dávají
     * dohromady zúčtovanou mzdu 1 000 Kč; náhrada za dovolenou stojí mimo ni.
     *
     * @return array<string,mixed>
     */
    private function payloadWithWageBreakdown(): array
    {
        $payload = $this->payload();
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor'] = [
            '10328' => 100_000,
            '10329' => 80_000,
            '10330' => 0,
            '10331' => 0,
            '10332' => 20_000,
            '10333' => 12_000,
            '10334' => 8_000,
            '10335' => 0,
            '10336' => 0,
            '10337' => 50_000,
            '10338' => 50_000,
            '10339' => 0,
            '10340' => 0,
            '10341' => 0,
            '10342' => 0,
            '10343' => 15_000,
        ];

        return $payload;
    }

    /**
     * Vada formuláře, kterou pozná až serializér, se od resolveru vrací jako
     * nález NA VZTAHU - aby šel vztah odložit z řádného hlášení a proklik vedl
     * na něj. Serializér ji dál odmítá sám, kdyby se k němu dokument dostal
     * jinou cestou; vrátí se jeho výjimka.
     *
     * @param array<string,mixed> $payload
     */
    private function refuse(array $payload): never
    {
        try {
            (new JmhzScenario1XmlValidator())->dryRun(
                $this->resolutionFor($payload),
                $this->envelope(),
            );
        } catch (JmhzXmlException $exception) {
            $probed = $this->resolutionFor($payload, forSubmission: true);
            self::assertSame('blocked', $probed->status());
            $matching = array_values(array_filter(
                $probed->blockers,
                static fn ($blocker): bool => $blocker->code === $exception->validationCode,
            ));
            self::assertCount(1, $matching, 'Vada formuláře musí být nálezem na vztahu.');
            self::assertSame('employment', $matching[0]->entityType);
            self::assertSame(101, $matching[0]->entityId);
            self::assertSame($exception->getMessage(), $matching[0]->message);
            self::assertTrue($matching[0]->deferrable());
            throw $exception;
        }
        self::fail('Serializér musel dokument odmítnout.');
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
        bool $forSubmission = false,
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

        $resolver = new JmhzScenario1DocumentResolver();

        return $forSubmission
            ? $resolver->resolveForSubmission($preparation, $pvpoj ?? $this->pvpoj())
            : $resolver->resolve($preparation, $pvpoj ?? $this->pvpoj());
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
                        // Zdroj jmenné větve `identifikaceType` — zmrazená
                        // historie identity osoby k rozhodnému dni.
                        'identity' => [
                            'first_name' => 'Jana',
                            'last_name' => 'Nováková',
                            'birth_date' => '1990-04-12',
                        ],
                    ],
                    'employment' => [
                        'is_primary' => true,
                        'start_date' => '2026-03-01',
                        'actual_start_date' => null,
                    ],
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
                    'scenario_resolution' => [
                        'scenario_key' => 'scenario_1',
                        // 10239 nese jmenná větev `identifikaceType`, takže
                        // zmrazený výběr scénáře musí druh činnosti doložit.
                        'activity_code' => '1',
                        'relationship_detail_code' => '1',
                    ],
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
                      <form:slevaZamestnance>
                        <form:slevaZamestnanceEvidovana>false</form:slevaZamestnanceEvidovana>
                        <form:slevaZamestnanceOvoZelEvidovana>false</form:slevaZamestnanceOvoZelEvidovana>
                      </form:slevaZamestnance>
                      <form:slevaZamestnavatele>
                        <form:slevaZamestnavateleEvidovana>false</form:slevaZamestnavateleEvidovana>
                      </form:slevaZamestnavatele>
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
