<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CzechPayrollRulesets;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlContext;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1ControlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1XmlValidator;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSubmissionEnvelope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027\Jmhz2027DocumentContract;
use PHPUnit\Framework\TestCase;

/**
 * Normalizovaný dokument JMHZ verze 2027 (vykazované období od 01/2027):
 * resolver skládá klíče přesně podle {@see Jmhz2027DocumentContract}
 * (jediné místo pravdy pro serializér 2027) a tam, kde jádro podklad nemá,
 * vrací fail-closed nález místo klíče.
 */
final class JmhzScenario1Document2027Test extends TestCase
{
    private const SOURCE_MISSING = 'jmhz_2027_source_missing';

    /**
     * Otisk dokumentu 2026 ze stejného přípravku před zavedením verze 2027.
     * Změna znamená, že se změnil výstup za období 2026.
     */
    private const DOCUMENT_2026_SHA256 = 'ebe042f53405c4469bff0cd1eb9284e1538d0b55aaaada809c0c85b6f5843d86';

    public function testDocument2026IsByteIdenticalAndCarriesNo2027Keys(): void
    {
        $resolution = $this->resolve($this->payload('2026-07-01'), '2026-07-01');

        self::assertSame('resolved', $resolution->status());
        $payload = $resolution->candidate?->payload ?? [];
        self::assertArrayNotHasKey('spec_version', $payload);
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::EMPLOYER_COLLECTIVE_AGREEMENTS, $payload['employer']);
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::SUMMARY_MULTI_MONTH_ADVANCE, $payload['people'][0]['summary']);
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME, $payload['people'][0]['employments'][0]);
        self::assertSame(self::DOCUMENT_2026_SHA256, $resolution->candidate?->sha256());
    }

    /**
     * Dokument 2027 nese klíče kontraktu přímo (žádný vedlejší blok s jinými
     * názvy). Kontrakt pak nenajde nic chybějícího a serializér 2027 ho
     * sestaví do XML, které projde XSD 1.5.0.3.
     */
    public function testResolved2027DocumentMeetsTheContractAndIsSchemaValid(): void
    {
        $resolution = $this->resolveForSubmission($this->completePayload('2027-04-01'), '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        $payload = $resolution->candidate?->payload ?? [];
        self::assertSame('2027', $payload['spec_version']);
        self::assertArrayNotHasKey('jmhz_2027', $payload['people'][0]['summary']);
        self::assertArrayNotHasKey('jmhz_2027', $payload['people'][0]['employments'][0]);
        Jmhz2027DocumentContract::assertComplete($payload);
        self::assertSame(['1'], $payload['employer'][Jmhz2027DocumentContract::EMPLOYER_COLLECTIVE_AGREEMENTS]);
        $summary = $payload['people'][0]['summary'];
        self::assertFalse($summary[Jmhz2027DocumentContract::SUMMARY_MULTI_MONTH_ADVANCE]);
        self::assertNull($summary[Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX]);
        $employment = $payload['people'][0]['employments'][0];
        self::assertSame(0, $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        self::assertSame(1000, $employment['social_base']['reported_income_czk']);

        $xml = (new JmhzScenario1XmlValidator(buildableGate: static function (JmhzSpecVersion $version): void {}))
            ->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('xmlns="http://schemas.cssz.cz/JMHZ/podani/2027"', $xml);
        self::assertStringContainsString('<form:prijemPar6>0</form:prijemPar6>', $xml);
        self::assertStringContainsString('<so:typKolektSmlouvy>1</so:typKolektSmlouvy>', $xml);
        self::assertStringContainsString('<form:zapocitatelnyPrijem>1000</form:zapocitatelnyPrijem>', $xml);
    }

    /**
     * Datová věta 2027 z resolveru projde kontrolami katalogu 1.5.0.4. Jediné
     * nepropustné nálezy jsou kontroly obce a státu místa výkonu (152, 153,
     * 335): číselníky obcí a států s účinností pro rok 2027 připnuté nejsou
     * (fail-closed), proto hlášení za 2027 zatím nejde zmrazit.
     */
    public function testDataSentence2027PassesControlsExceptUnpinnedCodebooks(): void
    {
        $abroad = $this->completePayload('2027-04-01');
        $domestic = $this->completePayload('2027-04-01');
        $term = &$domestic['people'][0]['employments'][0]['term'];
        $term['work_place'] = 'Brno';
        $term['jmhz_workplace_municipality_code'] = '582786';
        $term['jmhz_workplace_country_code'] = 'CZ';
        $term['jmhz_workplace_house_number'] = '120';
        $term['jmhz_workplace_postal_code'] = '60200';
        unset($term);

        self::assertSame([153 => 'unverifiable'], $this->controlStoppers($abroad));
        self::assertSame(
            [152 => 'unverifiable', 153 => 'unverifiable', 335 => 'unverifiable'],
            $this->controlStoppers($domestic),
        );
    }

    /**
     * Nepropustné nálezy kontrol 2027 nad datovou větou z resolveru.
     *
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function controlStoppers(array $payload): array
    {
        $resolution = $this->resolveForSubmission($payload, '2027-04-01');
        self::assertSame([], $this->blockers($resolution));
        $xml = $this->validator()->dryRun($resolution, $this->envelope())['xml'];
        $report = JmhzScenario1ControlValidator::create(CzechPayrollRulesets::provider(), null, JmhzSpecVersion::V2027)
            ->validate($xml, new JmhzControlContext('2027-05-12', schemaValidated: true));
        $stoppers = [];
        foreach ([...$report->blocking(), ...$report->coverageGaps()] as $finding) {
            $stoppers[$finding->controlId] = $finding->outcome->value;
        }
        ksort($stoppers);

        return $stoppers;
    }

    /**
     * Formuláře zkouší serializér ročníku dokumentu. Záporný rozpad mzdy
     * (10329) verze 2027 připouští; serializér 2026 by ho odmítl a vztah
     * by dostal nález, který pro období 2027 neplatí.
     */
    public function testFormProbeOf2027DocumentUsesThe2027Serializer(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10329'] = -10_000;
        $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10330'] = 110_000;

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        self::assertSame(
            -100,
            $resolution->candidate?->payload['people'][0]['employments'][0]['earnings_by_attribute_czk']['10329'],
        );
    }

    /**
     * Bez prohlášení a bez samostatného základu § 6 odst. 4 je celý příjem
     * v záloze: 10555 = 0. V lednu chybí úhrn měsíčních bonusů (10577),
     * bez roční evidence zaměstnavatele typy kolektivních smluv (10214)
     * a bez plné adresy místa výkonu 10550 až 10554.
     */
    public function testJanuary2027WithoutSourcesBlocksCollectiveAgreementsAddressAndBonusTotal(): void
    {
        $resolution = $this->resolve($this->payload('2027-01-01'), '2027-01-01');

        self::assertSame('blocked', $resolution->status());
        self::assertSame(
            [
                [self::SOURCE_MISSING, 'employment', 101, ['10550', '10551', '10552', '10553', '10554']],
                [self::SOURCE_MISSING, 'person', 11, ['10577']],
                [self::SOURCE_MISSING, 'revision', 301, ['10214']],
            ],
            $this->blockersWithCode($resolution, self::SOURCE_MISSING),
        );
        $payload = $resolution->candidate?->payload ?? [];
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::EMPLOYER_COLLECTIVE_AGREEMENTS, $payload['employer']);
        self::assertSame(0, $payload['people'][0]['employments'][0][Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
    }

    /**
     * Výsledek daně se samostatným základem § 6 odst. 4, ale bez rozpadu po
     * vztazích: 10555 zůstává neuvedený a stojí, neodhaduje se. Základ
     * a sražená daň (10307, 10309) jdou do kontraktu z téhož výsledku.
     */
    public function testParagraph6BaseWithoutDeclarationBlocksRelationshipSplit(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['withholding_tax_minor_units'] = 6_000;
        $tax['withholding_base_minor_units'] = 40_000;
        unset($tax);

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains([self::SOURCE_MISSING, 'person', 11, ['10535', '10555']], $this->blockers($resolution));
        $document = $resolution->candidate?->payload ?? [];
        self::assertSame(
            ['base' => 400, 'withheld' => 60],
            $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX],
        );
        self::assertArrayNotHasKey(
            Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME,
            $document['people'][0]['employments'][0],
        );
    }

    /**
     * Záloha ze samostatného základu (§ 6 odst. 4 ZDP od 2027): příjem vztahu
     * v samostatném základu jde do 10555, 10535 vztahu je 0 (kontroly 304,
     * 380). Souhrn 10307/10309 nese zaokrouhlený základ a zálohu z téhož
     * výsledku daně. Datová věta projde XSD i kontrolami katalogu.
     */
    public function testSeparateBaseIncomeGoesToParagraph6AttributeOfTheRelationship(): void
    {
        $payload = $this->withSeparateBase($this->completePayload('2027-04-01'), 100_000);

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        $document = $resolution->candidate?->payload ?? [];
        $employment = $document['people'][0]['employments'][0];
        self::assertSame(0, $employment['taxable_income_czk']);
        self::assertSame(1000, $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        self::assertSame(
            ['base' => 1000, 'withheld' => 150],
            $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX],
        );
        $xml = $this->validator()->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:prijemPar6>1000</form:prijemPar6>', $xml);
        self::assertStringContainsString('<form:prijem>0</form:prijem>', $xml);
        $report = JmhzScenario1ControlValidator::create(CzechPayrollRulesets::provider(), null, JmhzSpecVersion::V2027)
            ->validate($xml, new JmhzControlContext('2027-05-12', schemaValidated: true));
        $failed = [];
        foreach ($report->blocking() as $finding) {
            $failed[] = $finding->controlId;
        }
        self::assertNotContains(304, $failed);
    }

    /**
     * 10555 je podle slovníku 1.5.0.3 „celé číslo" bez pravidla zaokrouhlení:
     * haléře nezaokrouhleného příjmu jsou nález vztahu, ne zaokrouhlení.
     * Zaokrouhlení nahoru (§ 6 odst. 18) patří jen samostatnému základu 10307.
     */
    public function testParagraph6IncomeWithHalereIsAFindingNotRounding(): void
    {
        $payload = $this->withSeparateBase($this->completePayload('2027-04-01'), 100_050);

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains(
            ['jmhz_scenario1_whole_czk_required', 'employment', 101, ['10555']],
            $this->blockers($resolution),
        );
        self::assertSame([], $this->blockersWithCode($resolution, self::SOURCE_MISSING));
        $document = $resolution->candidate?->payload ?? [];
        self::assertArrayNotHasKey(
            Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME,
            $document['people'][0]['employments'][0],
        );
        self::assertSame(
            ['base' => 1001, 'withheld' => 151],
            $document['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_PARAGRAPH6_TAX],
        );
    }

    /**
     * Rozpad, který neodpovídá souhrnu výsledku daně (příjem vztahu jiný než
     * jeho podíl na samostatném základu, nebo výsledek 2027 bez režimu
     * samostatného základu), se nevykáže: 10535 ani 10555 se neodhadují.
     */
    public function testInconsistentSeparateBaseBreakdownBlocksBothIncomeAttributes(): void
    {
        $mismatch = $this->withSeparateBase($this->completePayload('2027-04-01'), 100_000);
        $mismatch['people'][0]['person_summary']['statutory']['income_tax']['relationships'][0]
            ['separate_base_income_minor_units'] = 0;
        $withoutRegime = $this->withSeparateBase($this->completePayload('2027-04-01'), 100_000);
        unset($withoutRegime['people'][0]['person_summary']['statutory']['income_tax']['separate_base_regime']);

        foreach (['mismatch' => $mismatch, 'without-regime' => $withoutRegime] as $case => $payload) {
            $resolution = $this->resolve($payload, '2027-04-01');

            self::assertContains(
                ['jmhz_scenario1_income_tax_result_not_calculated', 'person', 11, ['10535', '10555']],
                $this->blockers($resolution),
                $case,
            );
            self::assertArrayNotHasKey(
                Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME,
                $resolution->candidate?->payload['people'][0]['employments'][0] ?? [],
                $case,
            );
        }
    }

    /**
     * Vztah v záloze: 10535 nese jeho příjem a 10555 = 0, i když výsledek daně
     * nese rozpad po vztazích.
     */
    public function testAdvanceRelationshipFromBreakdownReportsZeroParagraph6Income(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['relationships'] = [[
            'relationship_reference' => 'employment:101',
            'kind' => 'employment',
            'taxable_base_minor_units' => 100_000,
            'regime' => 'advance',
            'withholding_group' => null,
            'separate_base_income_minor_units' => 0,
        ]];
        $tax['withholding_base_minor_units'] = 0;
        $tax['separate_base_regime'] = 'separate-advance';
        $tax['separate_base_income_minor_units'] = 0;
        unset($tax);

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        $employment = $resolution->candidate?->payload['people'][0]['employments'][0] ?? [];
        self::assertSame(1000, $employment['taxable_income_czk']);
        self::assertSame(0, $employment[Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
    }

    /**
     * Výsledek daně se samostatným základem podle § 6 odst. 4 (2027) u jediného
     * vztahu: celý příjem v samostatném základu, záloha 15 % nahoru.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withSeparateBase(array $payload, int $incomeMinor): array
    {
        $roundedBase = (int) (ceil($incomeMinor / 100) * 100);
        $taxMinor = (int) (ceil($roundedBase * 15 / 10_000) * 100);
        $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
        $tax['relationships'] = [[
            'relationship_reference' => 'employment:101',
            'kind' => 'dpp',
            'taxable_base_minor_units' => $incomeMinor,
            'regime' => 'withholding',
            'withholding_group' => 'dpp',
            'separate_base_income_minor_units' => $incomeMinor,
        ]];
        $tax['advance_tax'] = [
            'taxable_income_minor_units' => 0,
            'rounded_tax_base_minor_units' => 0,
            'tax_before_credits_minor_units' => 0,
            'non_refundable_credits_minor_units' => 0,
            'child_credit_minor_units' => 0,
            'tax_after_credits_minor_units' => 0,
            'tax_bonus_minor_units' => 0,
        ];
        $tax['withholding_groups'] = [[
            'group' => 'dpp',
            'base_minor_units' => $roundedBase,
            'tax_minor_units' => $taxMinor,
        ]];
        $tax['withholding_base_minor_units'] = $roundedBase;
        $tax['withholding_tax_minor_units'] = $taxMinor;
        $tax['separate_base_regime'] = 'separate-advance';
        $tax['separate_base_income_minor_units'] = $incomeMinor;
        unset($tax);
        $payload['people'][0]['person_summary']['statutory']['net_pay']['net_before_deductions_minor_units'] = 100_000 - 4_500 - 7_100 - $taxMinor;
        $payload['people'][0]['person_summary']['statutory']['net_pay']['net_payable_minor_units'] = 100_000 - 4_500 - 7_100 - $taxMinor;

        return $payload;
    }

    /**
     * S prohlášením se § 6 odst. 4 neuplatní: 10555 = 0. Den podpisu (10575,
     * IN16) evidence zatím nenese.
     */
    public function testDeclarantReportsZeroSection64AndBlocksSignatureDate(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertSame(
            [[self::SOURCE_MISSING, 'person', 11, ['10575']]],
            $this->blockers($resolution),
        );
        $document = $resolution->candidate?->payload ?? [];
        self::assertSame(0, $document['people'][0]['employments'][0][Jmhz2027DocumentContract::EMPLOYMENT_PARAGRAPH6_INCOME]);
        self::assertArrayNotHasKey(
            Jmhz2027DocumentContract::SUMMARY_DECLARATION_SIGNED_ON,
            $document['people'][0]['summary'],
        );
    }

    /**
     * Sleva na studenta (10565, 10566) ze zmrazeného rozpadu nároku přes
     * StudentTaxCredit::fromCreditBreakdown(). Rozpad slev 10299 až 10302
     * ji zná, takže úhrn nároku sedí a nevzniká
     * `tax_credit_breakdown_unavailable`.
     */
    public function testStudentCreditComesFromFrozenCreditBreakdown(): void
    {
        foreach (['student' => false, 'student-doctoral' => true] as $kind => $doctoral) {
            $payload = $this->completePayload('2027-04-01');
            $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
            $tax = &$payload['people'][0]['person_summary']['statutory']['income_tax'];
            $tax['claimed_non_refundable_credits_minor_units'] = 307_000;
            $tax['applied_non_refundable_credits_minor_units'] = 15_000;
            $tax['claimed_non_refundable_credit_breakdown'] = ['taxpayer' => 273_500, $kind => 33_500];
            unset($tax);

            $resolution = $this->resolve($payload, '2027-04-01');

            self::assertSame(
                [[self::SOURCE_MISSING, 'person', 11, ['10575']]],
                $this->blockers($resolution),
                $kind,
            );
            $summary = $resolution->candidate?->payload['people'][0]['summary'] ?? [];
            self::assertSame(
                ['amount_czk' => 335, 'doctoral' => $doctoral],
                $summary[Jmhz2027DocumentContract::SUMMARY_STUDENT_CREDIT],
                $kind,
            );
            self::assertSame(2735, $summary['tax_credits_czk']['basic'], $kind);
        }
    }

    /** Prohlášení bez slevy na studenta: klíč je doložené „neuplatněna" (`null`). */
    public function testDeclarantWithoutStudentCreditCarriesNullStudentCredit(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;

        $summary = $this->resolve($payload, '2027-04-01')->candidate?->payload['people'][0]['summary'] ?? [];

        self::assertArrayHasKey(Jmhz2027DocumentContract::SUMMARY_STUDENT_CREDIT, $summary);
        self::assertNull($summary[Jmhz2027DocumentContract::SUMMARY_STUDENT_CREDIT]);
    }

    /** Bez spočtené měsíční zálohy je 10579 neuvedený (nález nese záloha). */
    public function testMultiMonthAdvanceIsUnsetWithoutCalculatedAdvance(): void
    {
        $payload = $this->completePayload('2027-04-01');
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']['advance_tax']);

        $resolution = $this->resolve($payload, '2027-04-01');

        $summary = $resolution->candidate?->payload['people'][0]['summary'] ?? [];
        self::assertArrayHasKey(Jmhz2027DocumentContract::SUMMARY_MULTI_MONTH_ADVANCE, $summary);
        self::assertNull($summary[Jmhz2027DocumentContract::SUMMARY_MULTI_MONTH_ADVANCE]);
    }

    /**
     * Riziková práce: kategorie s odpracovanými hodinami po odečtu
     * náhradního volna (150,5 h − 8 h = 142,5 h), ze kterých serializér
     * vykáže celé hodiny nahoru. Kategorie 1 (práce kategorie 4) vyžaduje
     * počet směn 10580 (IN42), který jádro neeviduje.
     */
    public function testRiskWorkCategoryFourReportsCategoryHoursAndBlocksShifts(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['term']['social_employer_rate_category'] = 'risk_employment';
        $employment['work_month']['jmhz_work_summary']['values'] = array_replace(
            $employment['work_month']['jmhz_work_summary']['values'],
            [
                'worked_millihours' => 150_500,
                'overtime_millihours' => 4_000,
                'compensatory_time_off_millihours' => 8_000,
            ],
        );
        unset($employment);

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains([self::SOURCE_MISSING, 'employment', 101, ['10580']], $this->blockers($resolution));
        self::assertSame(
            [
                'categorization_codes' => ['1'],
                'categories' => [['code' => '1', 'worked_millihours' => 142_500, 'shift_count' => null]],
            ],
            $resolution->candidate?->payload['people'][0]['employments'][0]['risk_work'],
        );
    }

    /** Záchranář (kategorie 6) počet směn nevykazuje (kontrola 386). */
    public function testRescueWorkReportsHoursWithoutShiftBlocker(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['term']['social_employer_rate_category'] = 'rescue_and_company_fire_service';
        $employment['term']['jmhz_risk_categorization_code'] = '6';
        unset($employment);

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        self::assertSame(
            [['code' => '6', 'worked_millihours' => 160_000, 'shift_count' => null]],
            $resolution->candidate?->payload['people'][0]['employments'][0]['risk_work']['categories'],
        );
    }

    /**
     * Místo výkonu v ČR: plná adresa (10552, 10554 povinné) ve vztahu není.
     * Místo výkonu mimo ČR (kód obce 111111) adresu mít nesmí (kontrola 385).
     * O povinnosti rozhoduje kontrakt, stejně jako v serializéru.
     */
    public function testDomesticWorkplaceBlocksFullAddressAbroadDoesNot(): void
    {
        $domestic = $this->completePayload('2027-04-01');
        $domestic['people'][0]['employments'][0]['term']['jmhz_workplace_municipality_code'] = '582786';
        $domestic['people'][0]['employments'][0]['term']['jmhz_workplace_country_code'] = 'CZ';

        self::assertSame(
            [[self::SOURCE_MISSING, 'employment', 101, ['10550', '10551', '10552', '10553', '10554']]],
            $this->blockers($this->resolve($domestic, '2027-04-01')),
        );
        self::assertSame([], $this->blockers($this->resolve($this->completePayload('2027-04-01'), '2027-04-01')));
    }

    /** Státní zástupce (10587) je povinný jen ve scénáři K až S. */
    public function testStateProsecutorFlagBlocksOnlyInCorporateBodyScenario(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['scope']['scenario_set'] = ['scenario_3'];
        $payload['people'][0]['employments'][0]['scenario_resolution'] = ['scenario_key' => 'scenario_3'];

        $blockers = $this->blockers($this->resolve($payload, '2027-04-01'));

        self::assertContains([self::SOURCE_MISSING, 'employment', 101, ['10587']], $blockers);
        self::assertNotContains(
            [self::SOURCE_MISSING, 'employment', 101, ['10587']],
            $this->blockers($this->resolve($this->completePayload('2027-04-01'), '2027-04-01')),
        );
    }

    /**
     * Výdělek OZP: 10347 se ve verzi 2027 dělí na 10589, 10570 a 10571.
     * Ruční evidence nese jen jedno datum „mzdy včetně pojistného".
     */
    public function testOzpWageBlocksTheThreeSplitDates(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['ordinary_evidence'][0]['ozp_wage'] = [
            'payment_date' => '2027-04-10',
            'cash_paid' => false,
            'employer_deductions_made' => false,
            'employer_deductions_minor' => [],
        ];

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains(
            [self::SOURCE_MISSING, 'person', 11, ['10570', '10571', '10589']],
            $this->blockers($resolution),
        );
        $ozp = $resolution->candidate?->payload['people'][0]['summary']['ozp_wage'] ?? [];
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::OZP_SOCIAL_PAID_ON, $ozp);
        self::assertArrayNotHasKey(Jmhz2027DocumentContract::OZP_HEALTH_PAID_ON, $ozp);
    }

    /**
     * 10476 (`social_base.reported_income_czk`, „započitatelný příjem"): u DPP
     * a zaměstnání malého rozsahu bez účasti se uvádí dosažený podlimitní
     * příjem (§ 19 odst. 9 ZNP, Pokyny MH 1.4.14 str. 108), tedy 8 000 Kč,
     * ne 0; vztah bez příjmu má 0, ne neuvedeno. Stejně jako verze 2026.
     */
    public function testSicknessCountableIncomeOfNonParticipatingRelationshipIsItsIncome(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['insurance']['participation']['status'] = 'not_participating';
        $payload['people'][0]['employments'][0]['insurance']['participation']['participation_income_minor_units'] = 800_000;

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertSame(
            8000,
            $resolution->candidate?->payload['people'][0]['employments'][0]['social_base']['reported_income_czk'],
        );

        $payload['people'][0]['employments'][0]['insurance']['participation']['participation_income_minor_units'] = 0;
        $zero = $this->resolve($payload, '2027-04-01');
        self::assertSame(
            0,
            $zero->candidate?->payload['people'][0]['employments'][0]['social_base']['reported_income_czk'],
        );
    }

    /**
     * Den podpisu prohlášení (10575) jde z podmínek vztahu do souhrnu osoby
     * a do datové věty (`datumPodpisu`). Podpis po konci vykazovaného měsíce
     * prohlášení za ten měsíc nedokládá.
     */
    public function testDeclarationSignatureDateComesFromTheTerm(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed_on'] = '2026-12-15';

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        self::assertSame(
            '2026-12-15',
            $resolution->candidate?->payload['people'][0]['summary'][Jmhz2027DocumentContract::SUMMARY_DECLARATION_SIGNED_ON],
        );
        $xml = $this->validator()->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:datumPodpisu>2026-12-15</form:datumPodpisu>', $xml);

        $late = $payload;
        $late['people'][0]['employments'][0]['term']['tax_declaration_signed_on'] = '2027-05-02';
        self::assertSame(
            [[self::SOURCE_MISSING, 'person', 11, ['10575']]],
            $this->blockers($this->resolve($late, '2027-04-01')),
        );
    }

    /**
     * Plná adresa místa výkonu v ČR (10550 až 10554) z podmínek vztahu;
     * nezadané části jsou doložené „nemá" a v XML chybí.
     */
    public function testDomesticWorkplaceAddressFromTheTermIsSchemaValid(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $term = &$payload['people'][0]['employments'][0]['term'];
        $term['work_place'] = 'Brno';
        $term['jmhz_workplace_municipality_code'] = '582786';
        $term['jmhz_workplace_country_code'] = 'CZ';
        $term['jmhz_workplace_address_point_code'] = null;
        $term['jmhz_workplace_street'] = 'Náměstí Svobody';
        $term['jmhz_workplace_house_number'] = '120';
        $term['jmhz_workplace_orientation_number'] = null;
        $term['jmhz_workplace_postal_code'] = '60200';
        unset($term);

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        $xml = $this->validator()->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:cisloPopisne>120</form:cisloPopisne>', $xml);
        self::assertStringContainsString('<form:psc>60200</form:psc>', $xml);
        self::assertStringNotContainsString('cisloOrientacni', $xml);
    }

    /** Státní zástupce (10587) z podmínek vztahu formuláře K až S. */
    public function testStateProsecutorComesFromTheTerm(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['scope']['scenario_set'] = ['scenario_3'];
        $payload['people'][0]['employments'][0]['scenario_resolution'] = ['scenario_key' => 'scenario_3'];
        $payload['people'][0]['employments'][0]['term']['jmhz_state_prosecutor'] = false;

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertNotContains([self::SOURCE_MISSING, 'employment', 101, ['10587']], $this->blockers($resolution));
        self::assertFalse(
            $resolution->candidate?->payload['people'][0]['employments'][0][Jmhz2027DocumentContract::EMPLOYMENT_STATE_PROSECUTOR],
        );
    }

    /** Počet směn rizikové práce (10580) z ruční evidence měsíce. */
    public function testRiskShiftCountComesFromOrdinaryEvidence(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['people'][0]['employments'][0]['term']['social_employer_rate_category'] = 'risk_employment';
        $payload['ordinary_evidence'][0]['risk_shift_count'] = 20;

        $resolution = $this->resolveForSubmission($payload, '2027-04-01');

        self::assertSame([], $this->blockers($resolution));
        self::assertSame(
            [['code' => '1', 'worked_millihours' => 160_000, 'shift_count' => 20]],
            $resolution->candidate?->payload['people'][0]['employments'][0]['risk_work']['categories'],
        );
        $xml = $this->validator()->dryRun($resolution, $this->envelope())['xml'];
        self::assertStringContainsString('<form:smenyOdpracovanePocet>20</form:smenyOdpracovanePocet>', $xml);
    }

    /** Výdělek OZP: data odvodů 10570 a 10571 z ruční evidence, 10589 je datum úhrady. */
    public function testOzpWageContributionDatesComeFromOrdinaryEvidence(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['ordinary_evidence'][0]['ozp_wage'] = [
            'payment_date' => '2027-05-10',
            'social_insurance_paid_on' => '2027-05-12',
            'health_insurance_paid_on' => '2027-05-13',
            'cash_paid' => false,
            'employer_deductions_made' => false,
            'employer_deductions_minor' => [],
        ];

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertNotContains(
            [self::SOURCE_MISSING, 'person', 11, ['10570', '10571', '10589']],
            $this->blockers($resolution),
        );
        $ozp = $resolution->candidate?->payload['people'][0]['summary']['ozp_wage'] ?? [];
        self::assertSame('2027-05-10', $ozp['payment_date']);
        self::assertSame('2027-05-12', $ozp[Jmhz2027DocumentContract::OZP_SOCIAL_PAID_ON]);
        self::assertSame('2027-05-13', $ozp[Jmhz2027DocumentContract::OZP_HEALTH_PAID_ON]);
    }

    /**
     * Úhrn měsíčních bonusů za minulý rok (10577) v lednu z ročních podkladů
     * přípravy (roční zákonná kumulace daně); bez podkladu nález.
     */
    public function testJanuaryMonthlyBonusTotalComesFromAnnualEvidence(): void
    {
        $payload = $this->withAnnualCertificate($this->completePayload('2027-01-01'), 2026);
        $payload['people'][0]['annual_evidence']['request_evidence']['proof'] = 'request_absent_under_unique_key_lock';
        $withoutTotal = $this->resolve($payload, '2027-01-01');
        $payload['people'][0]['annual_evidence']['monthly_bonus_total_minor_units'] = 1_200_000;
        $withTotal = $this->resolve($payload, '2027-01-01');

        self::assertContains([self::SOURCE_MISSING, 'person', 11, ['10577']], $this->blockers($withoutTotal));
        self::assertNotContains([self::SOURCE_MISSING, 'person', 11, ['10577']], $this->blockers($withTotal));
        self::assertSame(
            12_000,
            $withTotal->candidate?->payload['people'][0]['summary']['annual'][Jmhz2027DocumentContract::ANNUAL_MONTHLY_BONUS_TOTAL],
        );
    }

    /** Roční evidence zaměstnavatele jiného roku typy kolektivních smluv za 2027 nedoloží. */
    public function testCollectiveAgreementsFromAnotherYearBlock(): void
    {
        $payload = $this->completePayload('2027-04-01');
        $payload['employer_annual_evidence']['report_year'] = 2026;

        self::assertSame(
            [[self::SOURCE_MISSING, 'revision', 301, ['10214']]],
            $this->blockers($this->resolve($payload, '2027-04-01')),
        );
    }

    /**
     * Datový slovník 1.5.0.3 připouští zápor u rozpadu mzdy (10329 až 10343):
     * ve verzi 2027 se neořezává. Mzda zúčtovaná (10328) zápornost nemá a
     * dál se vykazuje nulou. Ve verzi 2026 se ořezávají obě.
     */
    public function testNegativeWageBreakdownIsSignedOnlyIn2027(): void
    {
        $build = function (string $periodStart): array {
            $payload = $this->payload($periodStart);
            $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10328'] = -100_000;
            $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10329'] = -100_000;

            return $this->resolve($payload, $periodStart)->candidate?->payload['people'][0]
                ['employments'][0]['earnings_by_attribute_czk'] ?? [];
        };

        $earnings2026 = $build('2026-07-01');
        $earnings2027 = $build('2027-04-01');

        self::assertSame(0, $earnings2026['10328']);
        self::assertSame(0, $earnings2026['10329']);
        self::assertSame(0, $earnings2027['10328']);
        self::assertSame(-1000, $earnings2027['10329']);
    }

    /** Ořez 2027 odpovídá přesně atributům bez „zápornost" ve slovníku 1.5.0.3. */
    public function testClamp2027MatchesDictionarySignedness(): void
    {
        $manifest = (new JmhzSpecPackageCatalog(dirname(__DIR__, 4) . '/resources/payroll/jmhz'))->load(
            JmhzSpecPackageCatalog::PACKAGE_KEY_2027,
            JmhzSpecPackageCatalog::MANIFEST_SHA256_2027,
        );
        $unsigned = [];
        foreach ($manifest['payload']['dictionary_attributes'] as $attribute) {
            $id = $attribute['attribute_id'];
            if (in_array($id, JmhzScenario1DocumentResolver::NEGATIVE_INCOME_REPORTED_AS_ZERO_2027, true)
                || in_array($id, ['10329', '10330', '10331', '10337', '10338', '10269'], true)
            ) {
                $unsigned[$id] = !str_contains((string) $attribute['data_type_refinement'], 'zápornost');
            }
        }
        ksort($unsigned);

        self::assertSame(
            [
                '10269' => false,
                '10286' => true,
                '10328' => true,
                '10329' => false,
                '10330' => false,
                '10331' => false,
                '10337' => false,
                '10338' => false,
                '10344' => true,
            ],
            $unsigned,
        );
    }

    /**
     * Aktualita MPSV 7. 10. 2026: 10311 a 10312 se za leden 2027 nevyplňují.
     * Za leden 2026 se z potvrzení o srážkové dani dál vykazují.
     */
    public function testWithholdingAnnualTotalsAreOmittedInJanuary2027Only(): void
    {
        $january2026 = $this->resolve($this->withAnnualCertificate($this->payload('2026-01-01'), 2025), '2026-01-01');
        $january2027 = $this->resolve($this->withAnnualCertificate($this->payload('2027-01-01'), 2026), '2027-01-01');

        self::assertSame(
            ['paid_income_czk' => 5000, 'withholding_tax_czk' => 750],
            $january2026->candidate?->payload['people'][0]['summary']['annual']['withholding'],
        );
        self::assertNull($january2027->candidate?->payload['people'][0]['summary']['annual']['withholding']);
        // Osoba bez zaměstnání v roce 2026 bonus mít nemohla: bez nálezu 10577.
        self::assertNotContains(
            [self::SOURCE_MISSING, 'person', 11, ['10577']],
            $this->blockers($january2027),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withAnnualCertificate(array $payload, int $taxYear): array
    {
        $payload['people'][0]['annual_evidence'] = [
            'tax_year' => $taxYear,
            'request' => null,
            'request_evidence' => [
                'present' => false,
                'proof' => JmhzAnnualEvidenceService::PROOF_NO_EMPLOYMENT_IN_TAX_YEAR,
                'tax_year' => $taxYear,
            ],
            'settlement' => null,
            'settlement_evidence' => [
                'performed' => false,
                'proof' => 'outcome_absent_under_unique_key_lock',
                'tax_year' => $taxYear,
            ],
            'withholding_certificate' => [
                'paid_income_minor_units' => 500_000,
                'withholding_tax_minor_units' => 75_000,
            ],
        ];

        return $payload;
    }

    /** @return list<array{0:string,1:string,2:?int,3:list<string>}> */
    private function blockers(JmhzScenario1Resolution $resolution): array
    {
        return array_map(
            static fn ($blocker): array => [
                $blocker->code,
                $blocker->entityType,
                $blocker->entityId,
                $blocker->attributeIds,
            ],
            $resolution->blockers,
        );
    }

    /** @return list<array{0:string,1:string,2:?int,3:list<string>}> */
    private function blockersWithCode(JmhzScenario1Resolution $resolution, string $code): array
    {
        return array_values(array_filter(
            $this->blockers($resolution),
            static fn (array $blocker): bool => $blocker[0] === $code,
        ));
    }

    /** @param array<string,mixed> $payload */
    private function resolve(array $payload, string $periodStart): JmhzScenario1Resolution
    {
        return (new JmhzScenario1DocumentResolver())->resolve(
            $this->snapshot($payload, $periodStart),
            $this->pvpoj(substr($periodStart, 0, 7)),
        );
    }

    /** @param array<string,mixed> $payload */
    private function resolveForSubmission(array $payload, string $periodStart): JmhzScenario1Resolution
    {
        return (new JmhzScenario1DocumentResolver())->resolveForSubmission(
            $this->snapshot($payload, $periodStart),
            $this->pvpoj(substr($periodStart, 0, 7), true),
        );
    }

    /** @param array<string,mixed> $payload */
    private function snapshot(array $payload, string $periodStart): JmhzVerifiedPreparationSnapshot
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $payload['scope']['period_start'] = $periodStart;
        $payload['scope']['period_end'] = $periodEnd;

        return new JmhzVerifiedPreparationSnapshot(
            501,
            7,
            'test',
            401,
            301,
            1,
            $periodStart,
            $periodEnd,
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
    }

    private function validator(): JmhzScenario1XmlValidator
    {
        return new JmhzScenario1XmlValidator(buildableGate: static function (JmhzSpecVersion $version): void {});
    }

    private function envelope(): JmhzSubmissionEnvelope
    {
        return JmhzSubmissionEnvelope::create(
            '0195e2c4-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            [101 => '0195E2C4-1A2B-7C3D-8E4F-5A6B7C8D9E10'],
            '2027-05-05T09:30:00Z',
            'MyÚčto.cz',
            '6.0.0',
        );
    }

    /**
     * Přípravek s podklady, které dokument 2027 potřebuje: roční evidence
     * zaměstnavatele roku 2027 (10214), místo výkonu mimo ČR (adresa se
     * nevyplňuje), pracovní souhrn, ELDP a náležitosti formuláře.
     *
     * @return array<string,mixed>
     */
    private function completePayload(string $periodStart): array
    {
        $payload = $this->payload($periodStart);
        $payload['employer_annual_evidence'] = [
            'id' => 71,
            'schema_reference' => 'payroll-jmhz-employer-annual-evidence.v1',
            'report_year' => (int) substr($periodStart, 0, 4),
            'revision_no' => 1,
            'collective_agreement_types' => ['1'],
            'ownership_form' => '1',
            'average_headcount_hundredths' => 100,
            'average_disabled_headcount_hundredths' => 0,
            'disabled_share_hundredths' => 0,
            'ozp_reporting_office_id' => null,
        ];
        $employment = &$payload['people'][0]['employments'][0];
        $employment['term'] += [
            'work_place' => 'Wien',
            'jmhz_workplace_municipality_code' => Jmhz2027DocumentContract::MUNICIPALITY_ABROAD,
            'jmhz_workplace_country_code' => 'AT',
            'jmhz_apz_contribution_status' => 'no',
            'jmhz_functional_benefits_status' => 'no',
            'jmhz_temporary_assignment_status' => 'no',
        ];
        $employment['work_month']['jmhz_work_summary']['values'] = [
            'standard_fund_millihours' => 160_000,
            'agreed_fund_millihours' => 160_000,
            'weekly_work_centihours' => 4_000,
            'evidence_days' => 30,
            'worked_millihours' => 160_000,
            'overtime_millihours' => 0,
            'unworked_total_millihours' => null,
            'employee_obstacle_paid_millihours' => null,
            'employer_obstacle_millihours' => null,
        ];
        $month = substr($periodStart, 0, 7);
        $employment['eldp'] = [
            'confirmation' => ['in03_active' => false, 'in04_active' => false],
            'insurance_interval' => [
                'insurance_from' => $periodStart,
                'insurance_to' => (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d'),
            ],
            'eldp_sections' => [[
                'ordinal' => 1,
                'code' => '1++',
                'valid_from' => $periodStart,
                'valid_to' => (new \DateTimeImmutable($month . '-01'))->modify('last day of this month')->format('Y-m-d'),
                'insurance_days' => (int) (new \DateTimeImmutable($periodStart))->format('t'),
                'assessment_base_czk' => 1_000,
                'excluded_days_total' => 0,
                'excluded_days' => [
                    'docasNeschopnost' => 0,
                    'penezitaPomocMaterstvi' => 0,
                    'osetrovaniClenaRodiny' => 0,
                    'otcovska' => 0,
                    'vyloucenePar16' => 0,
                ],
                'section18_days_total' => 0,
                'section18_days' => [
                    'omluvenaNepritomnost' => 0,
                    'pracovniNeschopnost' => 0,
                    'vyplaceniDavek' => 0,
                ],
                'deducted_days' => null,
            ]],
        ];
        unset($employment);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function payload(string $periodStart): array
    {
        return [
            'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => $periodStart,
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
                            'net_before_deductions_minor_units' => 86_500,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 86_500,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => ['value' => '2000000000000000000001'],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => ['confirmation' => ['in03_active' => false, 'in04_active' => false]],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'interactions' => ['IN07' => false, 'IN08' => false],
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
            'ordinary_evidence' => [[
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
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

    /**
     * @param bool $complete přehled se všemi úhrny, které vyžaduje XSD;
     *        bez nich zůstává otisk dokumentu 2026 stejný jako dřív
     */
    private function pvpoj(string $period, bool $complete = false): JmhzPvpojPreview
    {
        $contributions = [
            'zakladZamestnavateleA' => 1_000,
            'pojistneZamestnavateleA' => 248,
            ...($complete ? ['pojistneZamestnavateleCelkem' => 248] : []),
            'pojistneZamestnance' => 71,
            ...($complete ? ['pojistneCelkem' => 319] : []),
        ];

        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            $period,
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
                'pojistne' => $contributions,
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }
}
