<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Xml2027;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets2026;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationRequest;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzCancellationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellation;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentCancellationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionForm;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzContentCorrectionPlan;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPackageSplitter;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1NormalizedDocument;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroAmendmentXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\Jmhz2027DocumentContract;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\Jmhz2027Schema;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\JmhzScenario1Xml2027Serializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Datová věta měsíčního hlášení 2027 (XSD 1.5.0.3) nad syntetickým
 * normalizovaným dokumentem, který už nese klíče kontraktu 2027
 * ({@see Jmhz2027DocumentContract}). Všechny hodnoty jsou smyšlené.
 *
 * Pojistka {@see JmhzSpecPackageCatalog::requireBuildable()} zůstává
 * v provozu zapnutá (verze 2027 není mezi sestavitelnými); testy ji
 * v validátoru nahrazují prázdnou bránou.
 */
final class JmhzScenario1Xml2027SerializerTest extends TestCase
{
    private const SUBMISSION_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E0F';
    private const FORM_GUID = '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10';

    public function testFullRegularFormIsSchemaValidAndByteStable(): void
    {
        $result = $this->validator()->dryRun(self::resolution(self::fullPayload()), self::envelope());
        $xml = $result['xml'];

        self::assertSame(JmhzSchemaCatalog::PACKAGE_KEY_2027, $result['schema']['package_key']);
        self::assertStringContainsString('xmlns="http://schemas.cssz.cz/JMHZ/podani/2027"', $xml);
        self::assertStringContainsString('verze="' . JmhzSchemaCatalog::DATA_VERSION_2027 . '"', $xml);
        self::assertStringContainsString('<form:formularZamestnani', $xml);
        self::assertStringNotContainsString('formularOsoby', $xml);
        $xpath = self::xpath($xml);
        foreach ([
            '/p:jmhz/p:formulareZamestnani/f:formularZamestnani/f:hlavicka/f:primarniPpv' => 'true',
            '//f:zalohaNaDan/f:zalohaPar38' => 'false',
            '//f:zalohaNaDan/f:zakladDanePar6' => '400',
            '//f:zalohaNaDan/f:srazenaDanPar6' => '60',
            '//f:zalohaNaDan/f:prohlaseniPoplatnika' => 'true',
            '//f:prohlaseniPoplatnikaDane/f:datumPodpisu' => '2026-12-15',
            '//f:prohlaseniPoplatnikaDane/f:slevaStudent/f:vyseSlevy' => '335',
            '//f:prohlaseniPoplatnikaDane/f:slevaStudent/f:doktorant' => 'false',
            '//f:prijmy/f:prispevekZelSporeniOsvob' => '1000',
            '//f:rocniUhrny/f:uhrnBonusu' => '0',
            '//f:vysledekRocnihoZuctovani/f:doplatekRok' => '1500',
            '//f:vydelekOZP/f:mzdaOzpUhradaDatum' => '2027-02-10',
            '//f:vydelekOZP/f:mzdaOzpSocPojDatum' => '2027-02-16',
            '//f:vydelekOZP/f:mzdaOzpZdravPojDatum' => '2027-02-17',
            '//f:nemocenskePojisteni/f:vyloucenePar18' => '3',
            '//f:mistoVykonuPrace/f:cisloPopisne' => '120',
            '//f:mistoVykonuPrace/f:psc' => '60200',
            '//f:riziko/f:smenyOdpracovanePocet' => '23',
            '//f:prijem/f:dan/f:prijem' => '1000',
            '//f:prijem/f:dan/f:prijemPar6' => '0',
            '/p:jmhz/so:souhrn/so:kolektivniSmlouvy/so:kolektivniSmlouva/so:typKolektSmlouvy' => '1',
        ] as $path => $expected) {
            self::assertSame($expected, self::value($xpath, $path), $path);
        }
        // Zrušené atributy (ZP 108/115) a 10311/10312 za rok 2027 (aktualita 7. 10. 2026).
        foreach ([
            'dnyOdpracovanePocet', 'prispevekPenzPripoj', 'prispevekZelPojDlPece', 'zvlastniSazbaDane',
            'odmenyNerezident', 'srazenaDanNerezident', 'prijemSrazkDanZvlSazba', 'danSrazenaZvlSazba',
            'prijemNepojistenaCinnost', 'preplatekRok',
        ] as $abolished) {
            self::assertStringNotContainsString('<form:' . $abolished, $xml, $abolished);
        }
        self::assertSame(0, $xpath->query('//f:eldp/f:vylouceneDny/f:vyloucenePar18')?->length);
    }

    /** @return iterable<string,array{string,string}> */
    public static function formProvider(): iterable
    {
        yield 'činnost K–S' => ['scenario_3', 'cinnostKS'];
        yield 'vězeň' => ['scenario_4', 'vezen'];
        yield 'jiný příjem' => ['scenario_5', 'jinyPrijem'];
        yield 'pronájem pracovní síly' => ['scenario_6', 'mezinarodniPronajemSily'];
        yield 'odložený příjem' => ['scenario_8', 'odlozenyPrijem'];
    }

    #[DataProvider('formProvider')]
    public function testEveryFormOfVersion2026IsSchemaValidIn2027(string $scenario, string $element): void
    {
        $xml = $this->validator()->dryRun(
            self::resolution(self::payloadFor($scenario)),
            self::envelope(),
        )['xml'];

        self::assertStringContainsString('<form:' . $element . '>', $xml);
    }

    public function testStateProsecutorCarriesWorkCourseAndWage(): void
    {
        $payload = self::payloadFor('scenario_3');
        $payload['people'][0]['employments'][0]['state_prosecutor'] = true;
        $xpath = self::xpath($this->validator()->dryRun(self::resolution($payload), self::envelope())['xml']);

        self::assertSame('true', self::value($xpath, '//f:cinnostKS/f:prubehZamestnani/f:statniZastupce'));
        self::assertSame('31', self::value($xpath, '//f:cinnostKS/f:prubehZamestnani/f:odpracovaneDny/f:dnyEvidencniStav'));
        self::assertSame('1000', self::value($xpath, '//f:cinnostKS/f:mzda/f:mzdaRozpad/f:tarif'));
    }

    /**
     * Formulář bez účasti na pojištění (DPP v souběhu, pojistné osoby nese
     * jiný vztah) vykazuje 10370 i 10481 nulou stejně jako verze 2026:
     * scénáře 1.5.0.3 je vedou jako „P" (rozhodnutí Q5).
     */
    public function testFormWithoutParticipationReportsZeroSocialContributions(): void
    {
        $payload = self::fullPayload();
        $employment = &$payload['people'][0]['employments'][0];
        $employment['reports_social_contributions'] = false;
        $employment['social_contributions'] = null;
        $employment['social_base'] = ['assessment_base_czk' => null, 'reported_income_czk' => 1_000, 'paragraph5_letter' => null];
        unset($employment);
        $xpath = self::xpath($this->validator()->dryRun(self::resolution($payload), self::envelope())['xml']);

        self::assertSame('0', self::value($xpath, '//f:pojisteni/f:pojisteniZamestnanec/f:socialniPojisteni'));
        self::assertSame('0', self::value($xpath, '//f:pojisteni/f:pojisteniZamestnavatel/f:socialniPojisteni'));
    }

    /**
     * Náhradní volno za přesčas se odečte od 10268 i 10269 podle
     * JmhzReportedWorkHours (stejně jako hodiny rizikové práce v resolveru):
     * ve verzi 2027 je přesčas se znaménkem, 4 h − 8 h volna = −4 h.
     */
    public function testCompensatoryTimeOffGivesSignedOvertime(): void
    {
        $payload = self::fullPayload();
        $values = &$payload['people'][0]['employments'][0]['work_month']['jmhz_work_summary']['values'];
        $values['worked_millihours'] = 150_500;
        $values['overtime_millihours'] = 4_000;
        $values['compensatory_time_off_millihours'] = 8_000;
        unset($values);
        $payload['people'][0]['employments'][0]['risk_work']['categories'][0]['worked_millihours'] = 142_500;
        $xpath = self::xpath($this->validator()->dryRun(self::resolution($payload), self::envelope())['xml']);

        self::assertSame('142.500', self::value($xpath, '//f:odpracovaneHodiny/f:pocet'));
        self::assertSame('-4.000', self::value($xpath, '//f:odpracovaneHodiny/f:rozpad/f:prescas'));
        self::assertSame('143', self::value($xpath, '//f:riziko/f:hodinyOdpracovanePocet'));
    }

    public function testWorkplaceAbroadOmitsCzechAddress(): void
    {
        $payload = self::fullPayload();
        $term = &$payload['people'][0]['employments'][0]['term'];
        $term['jmhz_workplace_municipality_code'] = '111111';
        $term['work_place'] = 'Wien';
        $term['jmhz_workplace_country_code'] = 'AT';
        unset(
            $term[Jmhz2027DocumentContract::TERM_ADDRESS_POINT],
            $term[Jmhz2027DocumentContract::TERM_STREET],
            $term[Jmhz2027DocumentContract::TERM_HOUSE_NUMBER],
            $term[Jmhz2027DocumentContract::TERM_ORIENTATION_NUMBER],
            $term[Jmhz2027DocumentContract::TERM_POSTAL_CODE],
        );
        unset($term);
        $xpath = self::xpath($this->validator()->dryRun(self::resolution($payload), self::envelope())['xml']);

        self::assertSame('111111', self::value($xpath, '//f:mistoVykonuPrace/f:kodObce'));
        self::assertSame(0, $xpath->query('//f:mistoVykonuPrace/f:psc')?->length);
        // Slovník 1.5.0.3: číselník obcí = CISOB + 111111 „Mimo ČR"; obec se
        // odvozuje z kódu, zahraniční město ze smlouvy do 10229 nejde.
        self::assertSame('Mimo ČR', self::value($xpath, '//f:mistoVykonuPrace/f:obec'));
        self::assertSame('AT', self::value($xpath, '//f:mistoVykonuPrace/f:kodStatu'));

        $payload['people'][0]['employments'][0]['term']['jmhz_workplace_country_code'] = 'CZ';
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
            self::fail('Místo výkonu mimo ČR se státem CZ nesmí projít.');
        } catch (\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_workplace_address_invalid', $exception->validationCode);
        }
    }

    public function testContentCorrectionAndPackagesAreSchemaValid(): void
    {
        $validator = $this->validator();
        $correction = $validator->dryRunCorrection(
            self::resolution(self::fullPayload()),
            JmhzSubmissionEnvelope::createForExistingSubmission(
                self::SUBMISSION_GUID,
                [101 => self::FORM_GUID],
                '2027-02-26T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
            JmhzContentCorrectionPlan::create([
                JmhzContentCorrectionForm::amendAccepted(101, self::FORM_GUID, affectsSummary: true, affectsPvpoj: true),
            ]),
        );
        self::assertStringContainsString('<typPodani>O</typPodani>', $correction['xml']);
        self::assertStringContainsString('<form:typFormulare>O</form:typFormulare>', $correction['xml']);

        $payload = self::fullPayload();
        $second = $payload['people'][0];
        $second['employee_id'] = 12;
        $second['employments'][0]['employment_id'] = 102;
        $second['employments'][0]['identity']['person_external_identifier'] = '1000000012';
        $second['employments'][0]['identity']['employment_external_identifier'] = '2000000000000000000002';
        $payload['people'][] = $second;
        $serializer = new JmhzScenario1Xml2027Serializer(new JmhzPackageSplitter(1));
        $packages = $serializer->serializePackages(
            new JmhzScenario1NormalizedDocument($payload),
            JmhzSubmissionEnvelope::create(
                strtolower(self::SUBMISSION_GUID),
                [101 => self::FORM_GUID, 102 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E11'],
                '2027-02-05T09:30:00Z',
                'MyÚčto.cz',
                '5.6.0',
            ),
        );
        self::assertCount(2, $packages);
        self::assertStringContainsString('<so:souhrn>', $packages[0]);
        self::assertStringNotContainsString('<so:souhrn>', $packages[1]);
        self::assertStringContainsString('<formularePocetCelkem>4</formularePocetCelkem>', $packages[1]);
        foreach ($packages as $xml) {
            // Diskuse JMHZ #627: všechny dílčí balíky jednoho hlášení nesou totéž datum a čas vyplnění.
            self::assertStringContainsString('<datumVyplneni>2027-02-05T09:30:00Z</datumVyplneni>', $xml);
            $validator->validateFrozen($xml);
        }
    }

    public function testCancellationsOf2027PeriodAreSchemaValid(): void
    {
        $validator = $this->validator();
        $request = self::cancellationRequest();
        $envelope = JmhzSubmissionEnvelope::create(self::SUBMISSION_GUID, [], '2027-02-05T09:30:00Z', 'MyÚčto.cz', '5.6.0');

        $cancellation = (new JmhzCancellationXmlSerializer())->serialize($request, $envelope);
        $validator->validateFrozen($cancellation);
        self::assertStringContainsString('<typPodani>S</typPodani>', $cancellation);
        self::assertStringContainsString('podani/2027', $cancellation);

        $components = (new JmhzComponentCancellationXmlSerializer())->serialize(
            $request,
            [JmhzComponentCancellation::create(self::FORM_GUID, '1000000001', '2000000000000000000001')],
            $envelope,
        );
        $validator->validateFrozen($components);
        self::assertStringContainsString('<form:typFormulare>S</form:typFormulare>', $components);
        self::assertStringNotContainsString('bezPriznaku', $components);
    }

    public function testZeroAmendmentOf2027FormIsSchemaValid(): void
    {
        $validator = $this->validator();
        $original = $validator->dryRun(self::resolution(self::zeroPayload()), self::envelope())['xml'];
        $serializer = new JmhzZeroAmendmentXmlSerializer();
        $envelope = JmhzSubmissionEnvelope::createForExistingSubmission(
            self::SUBMISSION_GUID,
            [],
            '2027-02-26T10:00:00+01:00',
            'MyÚčto.cz',
            '0',
        );

        self::assertSame([self::FORM_GUID => null], $serializer->assess($original));
        $amendment = $serializer->serialize($original, [self::FORM_GUID], $envelope);
        $validator->validateFrozen($amendment);
        $validator->validateFrozen($serializer->effectiveSet($original, [self::FORM_GUID]));
        self::assertStringContainsString('<form:prijemPar6>0</form:prijemPar6>', $amendment);
        self::assertStringContainsString('<form:datumPodpisu>2026-12-15</form:datumPodpisu>', $amendment);
    }

    /** @return iterable<string,array{\Closure(array<string,mixed>):array<string,mixed>,list<string>}> */
    public static function missingAttributeProvider(): iterable
    {
        yield '10579 záloha za více měsíců' => [
            static function (array $p): array {
                unset($p['people'][0]['summary']['advance_tax_multi_month']);

                return $p;
            },
            ['10579'],
        ];
        yield '10575 datum podpisu prohlášení' => [
            static function (array $p): array {
                unset($p['people'][0]['summary']['taxpayer_declaration_signed_on']);

                return $p;
            },
            ['10575'],
        ];
        yield '10555 příjem § 6 odst. 4' => [
            static function (array $p): array {
                unset($p['people'][0]['employments'][0]['paragraph6_income_czk']);

                return $p;
            },
            ['10555'],
        ];
        yield '10214 kolektivní smlouvy' => [
            static function (array $p): array {
                unset($p['employer']['collective_agreement_types']);

                return $p;
            },
            ['10214'],
        ];
        yield '10570 a 10571 data odvodů OZP' => [
            static function (array $p): array {
                unset(
                    $p['people'][0]['summary']['ozp_wage']['social_insurance_paid_on'],
                    $p['people'][0]['summary']['ozp_wage']['health_insurance_paid_on'],
                );

                return $p;
            },
            ['10570', '10571'],
        ];
        yield '10552 a 10554 adresa' => [
            static function (array $p): array {
                unset(
                    $p['people'][0]['employments'][0]['term']['jmhz_workplace_house_number'],
                    $p['people'][0]['employments'][0]['term']['jmhz_workplace_postal_code'],
                );

                return $p;
            },
            ['10552', '10554'],
        ];
        yield '10273 a 10580 riziková práce' => [
            static function (array $p): array {
                unset($p['people'][0]['employments'][0]['risk_work']['categories']);

                return $p;
            },
            ['10273', '10580'],
        ];
        yield '10565 sleva na studenta' => [
            static function (array $p): array {
                unset($p['people'][0]['summary']['student_credit']);

                return $p;
            },
            ['10565'],
        ];
        yield '10307 a 10309 samostatný základ při starém tvaru srážkové daně' => [
            static function (array $p): array {
                unset($p['people'][0]['summary']['paragraph6_tax_czk']);

                return $p;
            },
            ['10307', '10309'],
        ];
    }

    /**
     * @param \Closure(array<string,mixed>):array<string,mixed> $mutate
     * @param list<string> $attributes
     */
    #[DataProvider('missingAttributeProvider')]
    public function testMissingMandatory2027AttributeFailsClosed(\Closure $mutate, array $attributes): void
    {
        try {
            $this->validator()->dryRun(self::resolution($mutate(self::fullPayload())), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame(Jmhz2027DocumentContract::MISSING_CODE, $exception->validationCode);
            self::assertStringContainsString('atributy ' . implode(', ', $attributes) . '.', $exception->getMessage());

            return;
        }
        self::fail('Chybějící povinný atribut 2027 musí sestavení zastavit.');
    }

    public function testMissingAttributesAreListedTogetherAndPerEmploymentInProbe(): void
    {
        $payload = self::fullPayload();
        unset(
            $payload['employer']['collective_agreement_types'],
            $payload['people'][0]['summary']['advance_tax_multi_month'],
            $payload['people'][0]['employments'][0]['paragraph6_income_czk'],
        );
        try {
            (new JmhzScenario1Xml2027Serializer())->serialize(new JmhzScenario1NormalizedDocument($payload), self::envelope());
            self::fail('Neúplný dokument se nesmí serializovat.');
        } catch (JmhzXmlException $exception) {
            self::assertSame(Jmhz2027DocumentContract::MISSING_CODE, $exception->validationCode);
            self::assertStringContainsString('atributy 10214, 10555, 10579.', $exception->getMessage());
            self::assertStringContainsString('vztahy: 101.', $exception->getMessage());
        }

        $failures = (new JmhzScenario1Xml2027Serializer())->probeForms(new JmhzScenario1NormalizedDocument($payload));
        self::assertCount(1, $failures);
        self::assertSame(101, $failures[0]['employment_id']);
        self::assertSame(['10555', '10579'], $failures[0]['attribute_ids']);
    }

    public function testNegativeAnnualBonusDifferenceIsRefusedIn2027(): void
    {
        $payload = self::fullPayload();
        $payload['people'][0]['summary']['annual']['result']['bonus_difference_czk'] = -500;
        $payload['people'][0]['summary']['annual']['result']['settlement_difference_czk'] = 500;
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame(Jmhz2027DocumentContract::INCONSISTENT_CODE, $exception->validationCode);
            self::assertStringContainsString('nezáporně', $exception->getMessage());

            return;
        }
        self::fail('Záporný výsledek ročního zúčtování nemá ve verzi 2027 místo.');
    }

    public function testBothTaxIncomesPositiveAreRefused(): void
    {
        $payload = self::fullPayload();
        $payload['people'][0]['employments'][0]['paragraph6_income_czk'] = 500;
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame(Jmhz2027DocumentContract::INCONSISTENT_CODE, $exception->validationCode);
            self::assertStringContainsString('kontrola 304', $exception->getMessage());

            return;
        }
        self::fail('Kontrola 304: 10535 a 10555 nesmějí být kladné oba.');
    }

    public function testAnnualResultThatIsNotTheSumIsRefused(): void
    {
        $payload = self::fullPayload();
        $payload['people'][0]['summary']['annual']['result']['settlement_difference_czk'] = 1_499;
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame(Jmhz2027DocumentContract::INCONSISTENT_CODE, $exception->validationCode);
            self::assertStringContainsString('kontrola 78', $exception->getMessage());

            return;
        }
        self::fail('Kontrola 78: 10321 = 10322 + 10323.');
    }

    /** @return iterable<string,array{string,string}> */
    public static function invalidAddressProvider(): iterable
    {
        yield 'PSČ' => ['jmhz_workplace_postal_code', '602 00'];
        yield 'číslo popisné' => ['jmhz_workplace_house_number', '0'];
        yield 'adresa v ČR u místa mimo ČR' => ['jmhz_workplace_municipality_code', '111111'];
    }

    #[DataProvider('invalidAddressProvider')]
    public function testInvalidWorkplaceAddressIsRefused(string $key, string $value): void
    {
        $payload = self::fullPayload();
        $payload['people'][0]['employments'][0]['term'][$key] = $value;
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_workplace_address_invalid', $exception->validationCode);

            return;
        }
        self::fail('Neplatná adresa místa výkonu práce se nesmí dostat do datové věty.');
    }

    /**
     * 10214 ve verzi 2027 (ZP 102): zaměstnavatel bez kolektivní smlouvy uvádí
     * KAŽDÝ měsíc jednu položku „0 neexistuje" (souhrn.xsd: kolektivniSmlouvy
     * povinný, aspoň jedna položka; scénář 1.5.0.3 „P"). Atribut se
     * nevynechává ani mimo prosinec.
     */
    public function testEmployerWithoutCollectiveAgreementReportsZeroEveryMonth(): void
    {
        $payload = self::fullPayload();
        $payload['employer']['collective_agreement_types'] = ['0'];
        $payload['employer']['annual']['collective_agreement_types'] = ['0'];
        $xpath = self::xpath($this->validator()->dryRun(self::resolution($payload), self::envelope())['xml']);

        $types = $xpath->query('/p:jmhz/so:souhrn/so:kolektivniSmlouvy/so:kolektivniSmlouva/so:typKolektSmlouvy');
        self::assertSame(1, $types?->length);
        self::assertSame('0', $types?->item(0)?->textContent);
    }

    /**
     * Kontrola 386 (katalog 1.5.0.4): počet směn 10580 jen u kategorie rizika 1
     * s hodinami, „v ostatních případech nesmí být vyplněn". Dokument, který by
     * směny nesl u jiné kategorie, serializér odmítne, místo aby je zapsal.
     */
    public function testShiftCountOutsideRiskCategoryOneIsRefused(): void
    {
        $payload = self::fullPayload();
        $payload['people'][0]['employments'][0]['risk_work']['categorization_codes'] = ['6'];
        $payload['people'][0]['employments'][0]['risk_work']['categories'][0]['code'] = '6';
        try {
            $this->validator()->dryRun(self::resolution($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_xml_risk_shift_count_forbidden', $exception->validationCode);

            return;
        }
        self::fail('Směny u kategorie rizika 6 se nesmí dostat do datové věty.');
    }

    public function testDocumentOf2026PeriodIsNotSerializedAs2027(): void
    {
        $payload = self::fullPayload();
        $payload['header']['year'] = 2026;
        $payload['header']['month'] = 12;
        try {
            (new JmhzScenario1Xml2027Serializer())->serialize(new JmhzScenario1NormalizedDocument($payload), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame('jmhz_spec_version_mismatch', $exception->validationCode);

            return;
        }
        self::fail('Období 12/2026 se podává verzí 2026.');
    }

    /**
     * Pojistka sestavitelnosti zůstává: bez injektované brány validátor
     * hlášení za 01/2027 nesestaví, dokud integrátor nepřidá 2027 mezi
     * sestavitelné verze.
     */
    public function testDefaultValidatorKeepsTheSerializableVersionGuard(): void
    {
        try {
            (new JmhzScenario1XmlValidator())->dryRun(self::resolution(self::fullPayload()), self::envelope());
        } catch (JmhzXmlException $exception) {
            self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $exception->validationCode);

            return;
        }
        self::fail('Bez injektované brány se verze 2027 sestavit nesmí.');
    }

    /**
     * Bajtová regrese verze 2026: storno, storno součásti a nulová oprava za
     * 07/2026 mají tytéž bajty jako před zapojením verze 2027 (otisky
     * spočtené nad commitem 909b742a0). Řádné hlášení 2026 hlídá zlatý
     * soubor v JmhzScenario1XmlSerializerTest.
     */
    public function testVersion2026OutputsAreByteIdentical(): void
    {
        $guid = self::SUBMISSION_GUID;
        $request = JmhzCancellationRequest::create(
            $guid,
            '1234567890',
            2026,
            7,
            deadlines: new JmhzDeadlinePolicy(CzechPayrollRulesets2026::provider()),
            today: '2026-08-10',
        );
        $envelope = JmhzSubmissionEnvelope::create($guid, [], '2026-08-05T09:30:00Z', 'MyÚčto.cz', '5.6.0');
        self::assertSame(
            '1d4c526402e65ad28e3c72e842b479a45dcaaf4090f9ee90d7c36cc9aaa019f3',
            hash('sha256', (new JmhzCancellationXmlSerializer())->serialize($request, $envelope)),
        );
        self::assertSame(
            '19f95ba01ded71cac88f003f5daaa05bc8204a2229addd03cf8ef12ddb8ab4a9',
            hash('sha256', (new JmhzComponentCancellationXmlSerializer())->serialize(
                $request,
                [JmhzComponentCancellation::create(self::FORM_GUID, '1000000001', '2000000000000000000001')],
                $envelope,
            )),
        );
        $fixture = file_get_contents(__DIR__ . '/../../../../Fixtures/jmhz/zero-month-regular.xml');
        self::assertIsString($fixture);
        $fixture = str_replace("\r\n", "\n", $fixture);
        $zero = new JmhzZeroAmendmentXmlSerializer();
        $formGuid = '01A18238-0000-7000-8000-0000000000F1';
        self::assertSame(
            '5d7736cff8899f5a3b65b69f3e7b36e4c4f106ae76a627fb2988cc26edc8b50f',
            hash('sha256', $zero->serialize(
                $fixture,
                [$formGuid],
                JmhzSubmissionEnvelope::createForExistingSubmission(
                    '01A18238-0000-7000-8000-000000000001',
                    [],
                    '2026-10-28T10:00:00+01:00',
                    'MyÚčto.cz',
                    '0',
                ),
            )),
        );
        self::assertSame(
            'bd872fd809c88a082ef76e0ade300b2c6fe5637a5fa7a4f20b0233dc1a189354',
            hash('sha256', $zero->effectiveSet($fixture, [$formGuid])),
        );
    }

    private function validator(): JmhzScenario1XmlValidator
    {
        return new JmhzScenario1XmlValidator(buildableGate: static function (JmhzSpecVersion $version): void {});
    }

    private static function resolution(array $payload): JmhzScenario1Resolution
    {
        return new JmhzScenario1Resolution(new JmhzScenario1NormalizedDocument($payload), []);
    }

    private static function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            strtolower(self::SUBMISSION_GUID),
            [101 => self::FORM_GUID],
            '2027-02-05T09:30:00Z',
            'MyÚčto.cz',
            '5.6.0',
        );
    }

    /**
     * Žádost o storno za 01/2027. Lhůty za rok 2027 zatím nemají připnutý
     * kalendář, proto se tu žádost staví přímo a tvar storna se testuje
     * nezávisle na lhůtě.
     */
    private static function cancellationRequest(): JmhzCancellationRequest
    {
        $factory = \Closure::bind(
            static fn (string $guid): JmhzCancellationRequest => new JmhzCancellationRequest($guid, '1234567890', 2027, 1),
            null,
            JmhzCancellationRequest::class,
        );

        return $factory(self::SUBMISSION_GUID);
    }

    private static function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('p', Jmhz2027Schema::NS_PODANI);
        $xpath->registerNamespace('f', Jmhz2027Schema::NS_FORM);
        $xpath->registerNamespace('so', Jmhz2027Schema::NS_SOUHRN);

        return $xpath;
    }

    private static function value(DOMXPath $xpath, string $path): ?string
    {
        $nodes = $xpath->query($path);

        return $nodes === false || $nodes->length === 0 ? null : $nodes->item(0)?->textContent;
    }

    /** @return array<string,mixed> */
    private static function payloadFor(string $scenario): array
    {
        $payload = self::fullPayload();
        $person = &$payload['people'][0];
        $employment = &$person['employments'][0];
        $employment['selector']['scenario_key'] = $scenario;
        unset($person['summary']['ozp_wage']);
        if ($scenario === 'scenario_3') {
            $employment['selector']['activity_code'] = 'K';
            $employment['state_prosecutor'] = false;
        }
        if (in_array($scenario, ['scenario_5', 'scenario_6'], true)) {
            $employment['social_base'] = ['assessment_base_czk' => null, 'reported_income_czk' => null, 'paragraph5_letter' => null];
        }
        if ($scenario === 'scenario_4') {
            $employment['risk_work'] = null;
        }
        if ($scenario === 'scenario_8') {
            $employment['eldp']['deferred_income'] = ['type' => '4', 'periods' => [['month' => 12, 'year' => 2026]]];
        }
        unset($employment, $person);

        return $payload;
    }

    /**
     * Nulový formulář (rodičovská dovolená) jako zdroj anulace.
     *
     * @return array<string,mixed>
     */
    private static function zeroPayload(): array
    {
        $payload = self::fullPayload();
        $payload['employer']['summary_totals'] = ['advance_tax_after_credits' => 0, 'tax_bonus' => 0];
        $person = &$payload['people'][0];
        $person['summary'] = array_replace($person['summary'], [
            'income_total_czk' => 0,
            'employer_contributions_czk' => [],
            'exempt_income_czk' => null,
            'net_income_czk' => 0,
            'employee_health_czk' => 0,
            'employer_health_czk' => 0,
            'employee_social_czk' => 0,
            'employer_social_czk' => 0,
            'advance_tax_czk' => ['base' => 0, 'computed' => 0, 'after_credits' => 0, 'bonus' => 0, 'taxable_income' => 0],
            'withholding_tax_czk' => null,
            'paragraph6_tax_czk' => null,
            'tax_credits_czk' => ['basic' => null, 'disability_basic' => null, 'disability_extended' => null, 'ztp_p' => null],
            'student_credit' => null,
            'child_credit' => null,
            'annual' => null,
            'ozp_wage' => null,
        ]);
        $employment = &$person['employments'][0];
        $employment['social_base'] = ['assessment_base_czk' => 0, 'reported_income_czk' => 0, 'paragraph5_letter' => 'a'];
        $employment['social_contributions'] = ['employee_social_czk' => 0, 'employer_social_czk' => 0];
        $employment['taxable_income_czk'] = 0;
        $employment['risk_work'] = null;
        $employment['eldp']['eldp_sections'][0] = [
            'ordinal' => 1,
            'code' => '1++',
            'valid_from' => '2027-01-01',
            'valid_to' => '2027-01-31',
            'insurance_days' => 31,
            'assessment_base_czk' => 0,
        ];
        $employment['work_month']['jmhz_work_summary']['values'] = array_replace(
            $employment['work_month']['jmhz_work_summary']['values'],
            ['worked_millihours' => 0, 'overtime_millihours' => null],
        );
        $employment['earnings_by_attribute_czk'] = ['10328' => 0, '10329' => 0, '10330' => 0, '10331' => 0];
        $employment['average_hourly'] = ['minor_units' => 0, 'scale' => 2];
        unset($employment, $person);

        return $payload;
    }

    /**
     * Formulář bez příznaku za leden 2027 se všemi bloky, které verze 2027
     * mění: prohlášení s datem podpisu a slevou na studenta, samostatný
     * základ § 6 odst. 4, roční úhrny s úhrnem bonusů, výdělek OZP s daty
     * odvodů, adresa místa výkonu práce, riziková práce se směnami a § 18
     * v ELDP sekci (serializér ho přesune do `nemocenskePojisteni`).
     *
     * @return array<string,mixed>
     */
    private static function fullPayload(): array
    {
        return [
            'schema_reference' => JmhzScenario1NormalizedDocument::SCHEMA_REFERENCE,
            'scope' => [
                'period_start' => '2027-01-01',
                'period_end' => '2027-01-31',
                'scenario_set' => ['scenario_1'],
                'scenario_key' => 'scenario_1',
                'submission_kind' => 'regular',
                'office_id' => null,
            ],
            'header' => [
                'type' => 'R',
                'variable_symbol' => '1234567890',
                'year' => 2027,
                'month' => 1,
            ],
            'employer' => [
                'pvpoj' => ['values' => [
                    'pojistne' => [
                        'zakladZamestnavateleA' => 1_000,
                        'pojistneZamestnavateleA' => 248,
                        'pojistneZamestnavateleCelkem' => 248,
                        'pojistneZamestnance' => 71,
                        'pojistneCelkem' => 319,
                    ],
                    'pojistneUhrada' => 319,
                ]],
                'summary_totals' => ['advance_tax_after_credits' => 150, 'tax_bonus' => 0],
                'annual' => [
                    'ownership_form' => '1',
                    'ozp' => [],
                    'collective_agreement_types' => ['1'],
                ],
                'collective_agreement_types' => ['1'],
            ],
            'people' => [[
                'employee_id' => 11,
                'summary' => [
                    'income_total_czk' => 1_400,
                    'employer_contributions_czk' => ['10417' => 1_000, '10292' => 1_000],
                    'exempt_income_czk' => 1_000,
                    'net_income_czk' => 734,
                    'employee_health_czk' => 45,
                    'employer_health_czk' => 90,
                    'employee_social_czk' => 71,
                    'employer_social_czk' => 248,
                    'deductions_recorded' => false,
                    'taxpayer_declaration_signed' => true,
                    'taxpayer_declaration_signed_on' => '2026-12-15',
                    'tax_residence' => null,
                    'advance_tax_multi_month' => false,
                    'advance_tax_czk' => [
                        'base' => 1_000,
                        'computed' => 150,
                        'after_credits' => 0,
                        'bonus' => 0,
                        'taxable_income' => 1_000,
                    ],
                    'withholding_tax_czk' => ['base' => 400, 'tax' => 60],
                    'paragraph6_tax_czk' => ['base' => 400, 'withheld' => 60],
                    'tax_credits_czk' => [
                        'basic' => 150,
                        'disability_basic' => null,
                        'disability_extended' => null,
                        'ztp_p' => null,
                    ],
                    'student_credit' => ['amount_czk' => 335, 'doctoral' => false],
                    'child_credit' => null,
                    'annual' => [
                        'withholding' => ['paid_income_czk' => 400, 'withholding_tax_czk' => 60],
                        'monthly_bonus_total_czk' => 0,
                        'requested' => true,
                        'performed' => true,
                        'result' => [
                            'settlement_difference_czk' => 1_500,
                            'tax_difference_czk' => 1_000,
                            'bonus_difference_czk' => 500,
                            'spouse_credit_claimed' => false,
                            'child_credit_claimed' => false,
                        ],
                    ],
                    'ozp_wage' => [
                        'payment_date' => '2027-02-10',
                        'social_insurance_paid_on' => '2027-02-16',
                        'health_insurance_paid_on' => '2027-02-17',
                        'cash_paid' => false,
                        'employer_deductions_made' => true,
                        'employer_deductions_czk' => ['10352' => 500],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'social_base' => [
                        'assessment_base_czk' => 1_000,
                        'reported_income_czk' => 1_000,
                        'paragraph5_letter' => 'a',
                    ],
                    'social_contributions' => null,
                    'part_time_discount' => null,
                    'primary' => true,
                    'taxable_income_czk' => 1_000,
                    'paragraph6_income_czk' => 0,
                    'reports_social_contributions' => true,
                    'employee_social_discount' => null,
                    'identity' => [
                        'person_external_identifier' => '1000000001',
                        'employment_external_identifier' => '2000000000000000000001',
                    ],
                    'selector' => [
                        'scenario_key' => 'scenario_1',
                        'activity_code' => '1',
                        'relationship_detail_code' => '1',
                    ],
                    'term' => [
                        'work_place' => 'Brno',
                        'jmhz_workplace_municipality_code' => '582786',
                        'jmhz_workplace_country_code' => 'CZ',
                        'jmhz_workplace_address_point_code' => '21000001',
                        'jmhz_workplace_street' => 'Náměstí Svobody',
                        'jmhz_workplace_house_number' => '120',
                        'jmhz_workplace_orientation_number' => '7a',
                        'jmhz_workplace_postal_code' => '60200',
                        'jmhz_apz_contribution_status' => 'no',
                        'jmhz_functional_benefits_status' => 'no',
                        'jmhz_temporary_assignment_status' => 'no',
                    ],
                    'risk_work' => [
                        'categorization_codes' => ['1'],
                        'categories' => [['code' => '1', 'worked_millihours' => 184_000, 'shift_count' => 23]],
                    ],
                    'jmhz_default_interpretations' => null,
                    'work_month' => ['jmhz_work_summary' => ['values' => [
                        'standard_fund_millihours' => 184_000,
                        'agreed_fund_millihours' => 184_000,
                        'weekly_work_centihours' => 4_000,
                        'evidence_days' => 31,
                        'worked_millihours' => 184_000,
                        'overtime_millihours' => 8_000,
                        'unworked_total_millihours' => null,
                        'employee_obstacle_paid_millihours' => null,
                        'employer_obstacle_millihours' => null,
                    ]]],
                    'eldp' => [
                        'insurance_interval' => [
                            'insurance_from' => '2027-01-01',
                            'insurance_to' => '2027-01-31',
                        ],
                        'eldp_sections' => [[
                            'ordinal' => 1,
                            'code' => '1++',
                            'valid_from' => '2027-01-01',
                            'valid_to' => '2027-01-31',
                            'insurance_days' => 31,
                            'assessment_base_czk' => 1_000,
                            'excluded_days_total' => 0,
                            'excluded_days' => [
                                'docasNeschopnost' => 0,
                                'penezitaPomocMaterstvi' => 0,
                                'osetrovaniClenaRodiny' => 0,
                                'otcovska' => 0,
                                'vyloucenePar16' => 0,
                            ],
                            'section18_days_total' => 3,
                            'section18_days' => [
                                'omluvenaNepritomnost' => 3,
                                'pracovniNeschopnost' => 0,
                                'vyplaceniDavek' => 0,
                            ],
                            'deducted_days' => null,
                        ]],
                    ],
                    'average_hourly' => ['minor_units' => 27_550, 'scale' => 2],
                    'earnings_by_attribute_czk' => [
                        '10328' => 1_000,
                        '10329' => 1_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                ]],
            ]],
        ];
    }
}
