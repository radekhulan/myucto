<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException;
use PHPUnit\Framework\TestCase;

final class JmhzEldpEvidenceBuilderTest extends TestCase
{
    public function testBuildsOneOrdinarySectionFromApprovedEvidence(): void
    {
        $snapshot = (new JmhzEldpEvidenceBuilder())->build(
            7,
            101,
            $this->source(),
            $this->confirmation(),
        );

        self::assertSame('payroll-jmhz-eldp-evidence.v1', $snapshot->payload['schema_reference']);
        self::assertSame('1++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
        self::assertSame(10_000, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(
            [
                'docasNeschopnost' => 0,
                'penezitaPomocMaterstvi' => 0,
                'osetrovaniClenaRodiny' => 0,
                'otcovska' => 0,
                'vyloucenePar16' => 0,
            ],
            $snapshot->payload['eldp_sections'][0]['excluded_days'],
        );
        self::assertSame(0, $snapshot->payload['eldp_sections'][0]['excluded_days_total']);
        self::assertNull($snapshot->payload['eldp_sections'][0]['deducted_days_total']);
        self::assertSame(
            'b78f8fef6e2b4c54b33d1ce5116c89b1ac229458c38e5d5dc482fb955f0d476f',
            $snapshot->payload['specification']['eldp_code_row_sha256'],
        );
    }

    public function testBuildsEvidenceFromCurrentApprovedCorrectionRevision(): void
    {
        $source = $this->source();
        $source['revision']['revision_no'] = 2;
        $source['revision']['current_revision_no'] = 2;
        $source['revision']['revision_kind'] = 'correction';

        $snapshot = (new JmhzEldpEvidenceBuilder())->build(
            7,
            101,
            $source,
            $this->confirmation(),
        );

        self::assertSame(401, $snapshot->payload['scope']['source_revision_id']);
        self::assertSame('1++', $snapshot->payload['eldp_sections'][0]['code']);
    }

    /**
     * Kód D (činnost po dovršení důchodového věku, předčasný důchod) skládá
     * měsíční hlášení stejným pravidlem jako roční evidenční list. Bez
     * potvrzených důchodových údajů zůstává „++" a snapshot se nemění.
     */
    public function testPensionAgeCodeDIsComposedByTheSharedRule(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->source();

        $plain = $builder->build(7, 101, $source, $this->confirmation());
        self::assertSame('1++', $plain->payload['eldp_sections'][0]['code']);
        self::assertArrayNotHasKey('pension_age_code_from', $plain->payload['source_evidence']);

        $reached = [...$source, 'pension_status' => ['pension_age_reached_on' => '2026-03-15', 'early_pension_from' => null]];
        $confirmation = $builder->deriveOrdinaryConfirmation(7, 101, $reached);
        self::assertSame('1D+', $confirmation['code']);
        $snapshot = $builder->build(7, 101, $reached, $confirmation);
        self::assertSame('1D+', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame('2026-03-15', $snapshot->payload['source_evidence']['pension_age_code_from']);

        // Předčasný důchod dřív než dovršení věku rozhoduje; "++" při něm neprojde.
        $early = [...$source, 'pension_status' => ['pension_age_reached_on' => '2027-01-01', 'early_pension_from' => '2026-07-01']];
        self::assertSame('1D+', $builder->deriveOrdinaryConfirmation(7, 101, $early)['code']);
        try {
            $builder->build(7, 101, $early, $this->confirmation());
            self::fail('Kód ++ u předčasného důchodce se nesmí přijmout.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_code_activity_mismatch', $exception->validationCode);
        }

        // Dovršení věku až po měsíci hlášení: kód se nemění.
        $later = [...$source, 'pension_status' => ['pension_age_reached_on' => '2026-08-01', 'early_pension_from' => null]];
        self::assertSame('1++', $builder->deriveOrdinaryConfirmation(7, 101, $later)['code']);

        // Předčasný důchod uprostřed měsíce: rozdělení základu pravidla nestanoví.
        $earlyMid = [...$source, 'pension_status' => ['pension_age_reached_on' => '2027-01-01', 'early_pension_from' => '2026-07-16']];
        try {
            $builder->deriveOrdinaryConfirmation(7, 101, $earlyMid);
            self::fail('Předčasný důchod uprostřed měsíce se na sekce nedělí.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('eldp_pension_age_mid_month_unsupported', $exception->validationCode);
        }
    }

    /**
     * Pravidla podání JMHZ 1.4.5, kap. 4: změní-li se kód ELDP v měsíci, má
     * každý kód vlastní záznam. Dovršení důchodového věku 16. 7. dělí měsíc na
     * 1++ (1.–15. 7., 15 dnů) a 1D+ (16.–31. 7., 16 dnů). Kontrola 59 (3. část)
     * dává sekci před dovršením základ 0 a celý základ sekci D.
     */
    public function testPensionAgeReachedMidMonthSplitsTheMonthIntoTwoSections(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $mid = [...$this->source(), 'pension_status' => [
            'pension_age_reached_on' => '2026-07-16',
            'early_pension_from' => null,
        ]];

        $confirmation = $builder->deriveOrdinaryConfirmation(7, 101, $mid);
        self::assertSame('1D+', $confirmation['code']);
        self::assertSame(31, $confirmation['insurance_days']);
        $sections = $builder->build(7, 101, $mid, $confirmation)->payload['eldp_sections'];

        self::assertSame(
            [
                ['1++', '2026-07-01', '2026-07-15', 15, 0],
                ['1D+', '2026-07-16', '2026-07-31', 16, 10_000],
            ],
            array_map(
                static fn (array $section): array => [
                    $section['code'],
                    $section['valid_from'],
                    $section['valid_to'],
                    $section['insurance_days'],
                    $section['assessment_base_czk'],
                ],
                $sections,
            ),
        );
        self::assertSame([1, 2], array_column($sections, 'ordinal'));
        self::assertSame([0, 0], array_column($sections, 'excluded_days_total'));
    }

    /**
     * Po dovršení důchodového věku bez starobního důchodu tvoří omluvné
     * důvody i volno bez náhrady odečítané doby a IN04 (10375, 10462–10469)
     * je povinná. Měsíc s takovou nepřítomností se nesmí sestavit bez nich;
     * předčasný důchodce důchod pobírá, takže se ho to netýká.
     */
    public function testPensionAgeMonthWithDeductedAbsenceIsRefused(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $paternity = $this->absenceSource('paternity', '2026-07-13', '2026-07-26', [
            'paternity_millihours' => 80_000,
        ]);

        $reached = [...$paternity, 'pension_status' => [
            'pension_age_reached_on' => '2026-03-15',
            'early_pension_from' => null,
        ]];
        try {
            $builder->deriveOrdinaryConfirmation(7, 101, $reached);
            self::fail('Měsíc s odečítanými dobami nesmí projít bez IN04.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_deducted_days_unsupported', $exception->validationCode);
        }

        $early = [...$paternity, 'pension_status' => [
            'pension_age_reached_on' => '2027-01-01',
            'early_pension_from' => '2026-03-01',
        ]];
        $snapshot = $builder->build(7, 101, $early, $builder->deriveOrdinaryConfirmation(7, 101, $early));
        self::assertSame('1D+', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(14, $snapshot->payload['eldp_sections'][0]['excluded_days_total']);
    }

    public function testRejectsOffByOneCalendarDay(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['insurance_days'] = 30;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('inkluzivnímu intervalu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $this->source(), $confirmation);
    }

    public function testUsesUncappedAssessmentBaseForEldp(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 999_900;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $snapshot = (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());

        self::assertSame(10_000, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
    }

    public function testRejectsImplicitOrActiveInteractions(): void
    {
        $confirmation = $this->confirmation();
        unset($confirmation['in04_active']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('explicitní Ne');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $this->source(), $confirmation);
    }

    public function testRejectsUnsupportedActivityAndCodeInference(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['term']['activity_code'] = '15';
        $input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code'] = '1';
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('neodpovídá druhu pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testDerivesParticipatingDpcWithFullInsuranceMonth(): void
    {
        $source = $this->agreementSource('dpc', 'A', 'dpc', 'participates', 1_200_000, 1_200_000, 1_200_000);

        $confirmation = (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);

        self::assertSame('A++', $confirmation['code']);
        self::assertSame(31, $confirmation['insurance_days']);
        self::assertSame(12_000, $confirmation['assessment_base_czk']);
    }

    public function testDerivesStatutoryBodyAsSPlusPlusInScenarioThree(): void
    {
        $source = $this->agreementSource(
            'statutory_body',
            'S',
            'corporate_body',
            'participates',
            450_000,
            450_000,
            450_000,
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 31;
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('scenario_3', $snapshot->payload['scope']['scenario_key']);
        self::assertSame('S++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(4_500, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
    }

    public function testDerivesPartnerDependentActivityAsSPlusPlusInScenarioThree(): void
    {
        $source = $this->agreementSource(
            'partner_dependent',
            'S',
            'corporate_body',
            'participates',
            450_000,
            450_000,
            450_000,
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 31;
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('scenario_3', $snapshot->payload['scope']['scenario_key']);
        self::assertSame('S++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(4_500, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
    }

    public function testDerivesNonParticipatingDppAsCodeLessZeroDaySection(): void
    {
        $source = $this->agreementSource('dpp', 'T', 'dpp', 'does_not_participate', 640_000, 0, 0);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
        $section = $snapshot->payload['eldp_sections'][0];

        self::assertSame('2026-07-01', $snapshot->payload['insurance_interval']['insurance_from']);
        self::assertSame('2026-07-31', $snapshot->payload['insurance_interval']['insurance_to']);
        self::assertNull($section['valid_from']);
        self::assertNull($section['valid_to']);
        self::assertSame(0, $section['insurance_days']);
        self::assertNull($section['code']);
        self::assertNull($section['assessment_base_czk']);
        self::assertNull($snapshot->payload['specification']['eldp_code_row_sha256']);
    }

    public function testRejectsRelationAndActivityFamilyMismatch(): void
    {
        $source = $this->agreementSource('dpp', 'A', 'dpp', 'does_not_participate', 640_000, 0, 0);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('neodpovídá druhu pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    public function testDerivesParticipatingDppWithPinnedTPlusPlusCode(): void
    {
        $source = $this->agreementSource('dpp', 'T', 'dpp', 'participates', 640_000, 640_000, 640_000);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));

        self::assertSame('T++', $snapshot->payload['eldp_sections'][0]['code']);
        self::assertSame(6_400, $snapshot->payload['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame(31, $snapshot->payload['eldp_sections'][0]['insurance_days']);
        self::assertNotNull($snapshot->payload['specification']['eldp_code_row_sha256']);
    }

    /**
     * Podlimitní DPČ (pod rozhodnou částkou) není nemocensky pojištěná, takže
     * jí nevzniká záznam ELDP — stejně jako podlimitní DPP. Dřív se bezkódová
     * sekce připouštěla jen u DPP a DPČ shodila hlášení celé firmy; asymetrie
     * neměla oporu, o ELDP rozhoduje účast, ne druh vztahu.
     */
    public function testDerivesNonParticipatingDpcAsCodelessSection(): void
    {
        $source = $this->agreementSource('dpc', 'A', 'dpc', 'does_not_participate', 300_000, 0, 0);

        $confirmation = (new JmhzEldpEvidenceBuilder())
            ->deriveOrdinaryConfirmation(7, 101, $source);

        self::assertNull($confirmation['code']);
        self::assertSame(0, $confirmation['insurance_days']);
        self::assertNull($confirmation['valid_from']);
        self::assertNull($confirmation['assessment_base_czk']);
    }

    public function testRejectsFractionalCzechCrownWithoutRounding(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['assessment_base_minor_units'] = 1_000_001;
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 1_000_001;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('celé Kč');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsMismatchedNestedParticipationRelationship(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['participation']['relationship_id'] = 'employment:999';
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('jednoznačný výsledek účasti');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsEvidenceDaysDifferentFromFrozenWorkSummary(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['evidence_days'] = 30;
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Pracovní souhrn');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testRejectsUnworkedInteractionInOrdinarySlice(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['interactions']['IN07'] = true;
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Pracovní souhrn');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $this->confirmation());
    }

    public function testAllowsConfirmedVacationWithoutEldpExcludedOrDeductedDays(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $entry = &$input['people'][0]['employments'][0];
        $entry['absences'] = [[
            'id' => 901,
            'absence_type' => 'vacation',
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-14',
        ]];
        $summary = &$entry['time_month']['jmhz_work_summary'];
        $summary['interactions']['IN07'] = true;
        $summary['values']['unworked_total_millihours'] = 16_000;
        $summary['values']['unworked_paid_millihours'] = 16_000;
        $summary['values']['vacation_millihours'] = 16_000;
        unset($summary, $entry);
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );

        self::assertSame(0, $snapshot->payload['eldp_sections'][0]['excluded_days_total']);
        self::assertSame(
            0,
            $snapshot->payload['eldp_sections'][0]['excluded_days']['docasNeschopnost'],
        );
        self::assertSame([], $snapshot->payload['eldp_sections'][0]['excluded_days_provenance']);
        self::assertNull($snapshot->payload['eldp_sections'][0]['deducted_days_total']);
        self::assertFalse($snapshot->payload['confirmation']['in03_active']);
        self::assertFalse($snapshot->payload['confirmation']['in04_active']);
    }

    public function testDerivesSicknessAsExcludedDaysAgainstFrozenWorkSummary(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->sicknessSource();

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );
        $section = $snapshot->payload['eldp_sections'][0];

        // Doba pojištění nemocí nepřerušená: 10356 zůstává celý měsíc a nemoc
        // se vykáže jen jako vyloučená doba podle § 16 odst. 4 písm. a).
        self::assertSame(31, $section['insurance_days']);
        self::assertSame('1++', $section['code']);
        self::assertSame(12, $section['excluded_days_total']);
        self::assertSame(
            [
                'docasNeschopnost' => 12,
                'penezitaPomocMaterstvi' => 0,
                'osetrovaniClenaRodiny' => 0,
                'otcovska' => 0,
                'vyloucenePar16' => 0,
            ],
            $section['excluded_days'],
        );
        self::assertNull($section['deducted_days_total']);
        self::assertSame(
            [[
                'absence_id' => 903,
                'absence_type' => 'dpn',
                'attribute' => 'docasNeschopnost',
                'absence_from' => '2026-07-07',
                'absence_to' => '2026-07-18',
                'counted_from' => '2026-07-07',
                'counted_to' => '2026-07-18',
                'days' => 12,
            ]],
            $section['excluded_days_provenance'],
        );
    }

    public function testMonthlyAndAnnualEldpDeriveTheSameSicknessDays(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->sicknessSource();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );
        $annual = (new EldpExcludedPeriodDeriver())->derive(
            $input['people'][0]['employments'][0]['absences'],
            '2026-07-01',
            '2026-07-31',
            '2026-07',
        );

        self::assertSame(
            $annual['components'],
            $snapshot->payload['eldp_sections'][0]['excluded_days'],
        );
        self::assertSame(
            $annual['total'],
            $snapshot->payload['eldp_sections'][0]['excluded_days_total'],
        );
    }

    public function testKeepsSicknessFailClosedWhenWorkSummaryReportsNoSickHours(): void
    {
        $source = $this->sicknessSource();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $summary = &$input['people'][0]['employments'][0]['time_month']['jmhz_work_summary'];
        $summary['values']['dpn_with_employer_compensation_millihours'] = null;
        unset($summary);
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('nedokládá žádné neodpracované hodiny');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    public function testKeepsSicknessFailClosedWhenUnworkedTotalsDisagree(): void
    {
        $source = $this->sicknessSource();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['unworked_paid_millihours'] = 0;
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Úhrny neodpracovaných hodin');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    /**
     * PPM před porodem: vyloučenou dobou 10359 je průnik s intervalem od
     * začátku osmého týdne před očekávaným porodem (25. 6. 2026) do dne před
     * porodem. Porod v červenci nenastal, měsíc končí před očekávaným dnem
     * (20. 8.), takže se počítá 7.–31. 7. Příjem za 1.–6. 7. nechává plnou
     * dobu pojištění.
     */
    public function testDerivesPreBirthMaternityAsExcludedDays(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->maternitySource('2026-07-07', '2026-12-31', '2026-08-20', null, 144_000);

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(25, $section['excluded_days']['penezitaPomocMaterstvi']);
        self::assertSame(25, $section['excluded_days_total']);
        self::assertSame('2026-07-31', $section['excluded_days_provenance'][0]['counted_to']);
        // Dny peněžité pomoci v mateřství jsou vyloučené dny § 18 odst. 7
        // s vyplacenou dávkou (10475), stejně je vykazují jiné mzdové systémy.
        self::assertSame(25, $section['section18_days_total']);
        self::assertSame(25, $section['section18_days']['vyplaceniDavek']);
    }

    /**
     * Měsíc po porodu bez příjmu: mimo dobu pojištění (§ 11 odst. 2), nula
     * vyloučených dnů. Hodiny PPM v pracovním souhrnu bez vyloučeného dne jsou
     * legitimní, protože příčná kontrola je u 10359 jen jednosměrná.
     */
    public function testPostBirthMaternityMonthWithoutIncomeIsOutsideInsurance(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->withZeroAssessmentBase(
            $this->maternitySource('2026-05-01', '2026-12-31', '2026-06-20', '2026-06-15', 184_000),
        );

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(0, $section['insurance_days']);
        self::assertSame('1++', $section['code']);
        self::assertSame(0, $section['assessment_base_czk']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame(0, $section['excluded_days']['penezitaPomocMaterstvi']);
    }

    /**
     * Měsíc porodu bez příjmu: tvar, který ČSSZ přijala v řádném i opravném
     * hlášení jiného systému — celý měsíc je dobou pojištění (10356),
     * vyloučenou dobou 10357 = 10359 jsou dny před porodem a celý měsíc je
     * vyloučenými dny s vyplacenou dávkou (10475).
     */
    public function testBirthMonthWithoutIncomeIsReportedAsInsuredMonth(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->withZeroAssessmentBase(
            $this->maternitySource('2026-06-01', '2026-12-31', '2026-07-20', '2026-07-15', 176_000),
        );

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame('1++', $section['code']);
        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['assessment_base_czk']);
        // 1.–14. 7. před porodem 15. 7.
        self::assertSame(14, $section['excluded_days_total']);
        self::assertSame(14, $section['excluded_days']['penezitaPomocMaterstvi']);
        self::assertSame(31, $section['section18_days_total']);
        self::assertSame(31, $section['section18_days']['vyplaceniDavek']);
    }

    /** Bez dne porodu nerozhodne měsíc, který sahá na očekávaný den porodu. */
    public function testMaternityMonthReachingExpectedBirthNeedsTheBirthDate(): void
    {
        $source = $this->maternitySource('2026-06-01', '2026-12-31', '2026-07-20', null, 176_000);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('den porodu');
        $builder = new JmhzEldpEvidenceBuilder();
        $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
    }

    /** Souhrn v2 hodinový blok PPM nemá, takže PPM tam zůstává fail-closed. */
    public function testKeepsMaternityFailClosedOnTheOlderWorkSummary(): void
    {
        $source = $this->maternitySource('2026-07-07', '2026-12-31', '2026-08-20', null, 144_000);
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['derivation_version'] = 'jmhz-work-month.v2';
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('dovolenou, nemoc, karanténu, ošetřovné');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    /** @return array<string,mixed> */
    private function maternitySource(
        string $from,
        string $to,
        string $expected,
        ?string $childbirth,
        int $millihours,
    ): array {
        $source = $this->absenceSource('ppm', $from, $to, ['maternity_millihours' => $millihours]);
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'][0]['expected_childbirth_date'] = $expected;
        $input['people'][0]['employments'][0]['absences'][0]['childbirth_date'] = $childbirth;

        return $this->withInput($source, $input);
    }

    /**
     * Souhrn v2 pro nepřítomnosti bez atributu hlášení nemá hodinový blok,
     * takže by se den nedal proti ničemu ověřit. Zmrazené v2 měsíce proto
     * zůstávají fail-closed přesně jako dřív.
     */
    public function testKeepsUnpaidLeaveFailClosedOnTheOlderWorkSummary(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'] = [[
            'id' => 902,
            'absence_type' => 'unpaid_leave',
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-14',
        ]];
        $source = $this->withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('dovolenou, nemoc, karanténu, ošetřovné');
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    /**
     * Otcovská je vyloučenou dobou v celé podpůrčí době — § 16 odst. 4 věta
     * třetí písm. a) zákona č. 155/1995 Sb. jmenuje „dobu, po kterou trvala
     * podpůrčí doba u dávky otcovské poporodní péče". Doba pojištění se nekrátí.
     */
    public function testDerivesPaternityAsExcludedDaysFromTheNewerWorkSummary(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->absenceSource('paternity', '2026-07-13', '2026-07-26', [
            'paternity_millihours' => 80_000,
        ]);

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );
        $section = $snapshot->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(14, $section['excluded_days']['otcovska']);
        self::assertSame(14, $section['excluded_days_total']);
        self::assertSame(0, $section['excluded_days']['docasNeschopnost']);
    }

    /**
     * Neplacené volno, neomluvená absence ani rodičovská nejsou vyloučenou
     * dobou (uzavřený výčet § 16 odst. 4 věty třetí písm. a) je neuvádí) a
     * pojištění nepřerušují — § 10 odst. 9 zákona č. 187/2006 Sb. zná jediné
     * přerušení, a to výkon trestu. V měsíci se zúčtovaným příjmem tedy ELDP
     * vykáže plnou dobu pojištění a nulové vyloučené doby.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incomeLessAbsenceKinds')]
    public function testIncomeLessAbsenceKeepsFullInsuranceMonthWithoutExcludedDays(
        string $absenceType,
        string $field,
    ): void {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->absenceSource($absenceType, '2026-07-13', '2026-07-14', [
            $field => 16_000,
        ]);

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );
        $section = $snapshot->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame('1++', $section['code']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame([], $section['excluded_days_provenance']);
    }

    /** @return array<string,array{string,string}> */
    public static function incomeLessAbsenceKinds(): array
    {
        return [
            'neplacené volno' => ['unpaid_leave', 'unpaid_leave_millihours'],
            'neomluvená absence' => ['unexcused', 'unexcused_millihours'],
            'rodičovská dovolená' => ['parental', 'parental_millihours'],
        ];
    }

    /**
     * Měsíc bez započitatelného příjmu se podle § 11 odst. 2 zákona
     * č. 155/1995 Sb. za dobu pojištění nepovažuje. Hlášení ho vyjádří sekcí
     * s kódem a nulou dnů i základu, jako přijatá hlášení jiných systémů.
     * Vyloučené dny § 18 odst. 7 se vykazují dál: nemocenské pojištění trvá.
     */
    public function testWholeMonthOfUnpaidLeaveIsReportedAsZeroInsuranceDays(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->withZeroAssessmentBase($this->absenceSource(
            'unpaid_leave',
            '2026-07-01',
            '2026-07-31',
            ['unpaid_leave_millihours' => 184_000],
        ));

        $confirmation = $builder->deriveOrdinaryConfirmation(7, 101, $source);
        $section = $builder->build(7, 101, $source, $confirmation)
            ->payload['eldp_sections'][0];

        self::assertSame(0, $section['insurance_days']);
        self::assertSame('1++', $section['code']);
        self::assertSame('2026-07-01', $section['valid_from']);
        self::assertSame('2026-07-31', $section['valid_to']);
        self::assertSame(0, $section['assessment_base_czk']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame(31, $section['section18_days_total']);
        self::assertSame(31, $section['section18_days']['omluvenaNepritomnost']);
    }

    /**
     * Rodičovská ve formě celého měsíce bez příjmu je týž případ § 11
     * odst. 2. Její dny jsou vyloučenými dny § 18 odst. 7 v 10473 (Pokyny
     * MPSV k vyplnění MH 1.4.13 ji tam jmenují výslovně), stejně jako celý
     * měsíc neplaceného volna výš.
     */
    public function testWholeMonthOfParentalLeaveIsReportedAsZeroInsuranceDays(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->withZeroAssessmentBase($this->absenceSource(
            'parental',
            '2026-07-01',
            '2026-07-31',
            ['parental_millihours' => 160_000],
        ));

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(0, $section['insurance_days']);
        self::assertSame('1++', $section['code']);
        self::assertSame(0, $section['assessment_base_czk']);
        self::assertSame(31, $section['section18_days_total']);
        self::assertSame(31, $section['section18_days']['omluvenaNepritomnost']);
    }

    /**
     * Na rodičovské není zaměstnanec v evidenčním stavu (10265), souhrn proto
     * nese nulu. Souhrn potvrzený dřív s celým trváním vztahu (31) projde
     * tak, jak byl potvrzen; jiný počet dní ne.
     */
    public function testEvidenceDaysOfParentalLeaveMonthExcludeTheLeave(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $build = function (int $evidenceDays) use ($builder): void {
            $source = $this->withZeroAssessmentBase($this->absenceSource(
                'parental',
                '2026-07-01',
                '2026-07-31',
                ['parental_millihours' => 160_000],
            ));
            $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($input);
            $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
                ['values']['evidence_days'] = $evidenceDays;
            $source = $this->withInput($source, $input);
            $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
        };

        $build(0);
        $build(31);

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Pracovní souhrn');
        $build(15);
    }

    /**
     * Celý měsíc nemoci je omluvný důvod podle § 16 odst. 4 věty třetí
     * písm. a): dobou pojištění zůstává s plným počtem dnů, vyloučenou dobou
     * a nulovým základem. Dřív ho odmítla kontrola kladného základu.
     */
    public function testWholeMonthOfSicknessKeepsInsuranceDaysWithZeroBase(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->withZeroAssessmentBase($this->absenceSource(
            'dpn',
            '2026-07-01',
            '2026-07-31',
            [
                'dpn_with_employer_compensation_millihours' => 80_000,
                'dpn_without_employer_compensation_millihours' => 104_000,
            ],
            paidMillihours: 80_000,
        ));

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['assessment_base_czk']);
        self::assertSame(31, $section['excluded_days_total']);
        self::assertSame(31, $section['excluded_days']['docasNeschopnost']);
    }

    /**
     * Příloha č. 3 Všeobecných zásad ELDP bod a) a kontrola 59 část 2: nemoc
     * po celý měsíc a v měsíci zúčtovaný příjem — krytí, vyloučená doba se
     * nevykáže. Hodiny nemoci v pracovním souhrnu přitom zůstávají doložené.
     * Dřív měsíc odešel s 31 vyloučenými dny a nenulovým základem.
     */
    public function testWholeMonthOfSicknessWithIncomeIsCoveredByIncome(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->absenceSource(
            'dpn',
            '2026-07-01',
            '2026-07-31',
            [
                'dpn_with_employer_compensation_millihours' => 80_000,
                'dpn_without_employer_compensation_millihours' => 104_000,
            ],
            paidMillihours: 80_000,
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'][0] += [
            'compensation_window_from' => '2026-07-01',
            'compensation_window_to' => '2026-07-14',
            'insurance_eligibility_confirmed' => true,
        ];
        $source = $this->withInput($source, $input);

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(10_000, $section['assessment_base_czk']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame(31, $section['section18_days_total']);
    }

    /**
     * § 38 odst. 4 písm. h) zákona č. 582/1991 Sb. a logický test 39: měsíc
     * s kódem D a nemocí má odečtené doby. Měsíční hlášení je zatím zapsat
     * neumí (IN04), takže měsíc zastaví; dřív odešel s vyloučenými dobami
     * a bez odečtených, což ČSSZ odmítne.
     */
    public function testPensionAgeMonthWithDeductedDaysStops(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = [...$this->sicknessSource(), 'pension_status' => ['pension_age_reached_on' => '2026-01-01', 'early_pension_from' => null]];

        try {
            $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
            self::fail('Měsíc s kódem D a odečtenými dobami se bez nich vykázat nesmí.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_deducted_days_unsupported', $exception->validationCode);
        }
    }

    /**
     * Kontrola 133 část 3 a Pravidla podání JMHZ kap. 6 bod 1c: zaměstnání
     * malého rozsahu nemá kód s druhou pozicí P; příjem po skončení patří do
     * posledního měsíce výkonu (oprava hlášení), ne do odloženého příjmu.
     */
    public function testDeferredIncomeOfSmallScaleEmploymentIsRefused(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $entry = &$input['people'][0]['employments'][0];
        $entry['employment']['relation_type'] = 'small_scale_employment';
        $entry['employment']['end_date'] = '2026-06-15';
        $entry['deferred_income'] = ['deferred_type' => '1'];
        unset($entry);
        $source = $this->withInput($source, $input);

        try {
            (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
            self::fail('Odložený příjem ZMR s kódem P nesmí vzniknout.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_deferred_small_scale_unsupported', $exception->validationCode);
        }
    }

    /**
     * Náhradní volno za přesčas: hodiny jdou jen do úhrnu 10275 (mzda za ně
     * nepřísluší, § 114 odst. 1 ZP), vyloučenou dobou ELDP není, ale celé dny
     * jsou vyloučenými dny § 18 odst. 7 písm. a). Umí to až souhrn v5.
     */
    public function testCompensatoryTimeOffPassesOnVersionFiveWorkSummary(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->compensatoryTimeOffSource('jmhz-work-month.v5');

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['excluded_days_total']);
        self::assertSame(2, $section['section18_days_total']);
        self::assertSame(2, $section['section18_days']['omluvenaNepritomnost']);
    }

    /**
     * Měsíc jen s nepřítomností bez náhrady mzdy nese 10276 nevyplněné:
     * souhrn nulu navrhuje jako prázdnou a XSD prvek nevyžaduje. Dřív to ELDP
     * odmítlo jako nesouhlasné úhrny a hlášení se nesestavilo.
     */
    public function testUnpaidOnlyAbsenceAcceptsUnfilledPaidHours(): void
    {
        $source = $this->compensatoryTimeOffSource('jmhz-work-month.v5');
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['unworked_paid_millihours'] = null;
        $source = $this->withInput($source, $input);
        $builder = new JmhzEldpEvidenceBuilder();

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(2, $section['section18_days']['omluvenaNepritomnost']);
    }

    public function testCompensatoryTimeOffStaysBlockedOnOlderWorkSummary(): void
    {
        $source = $this->compensatoryTimeOffSource('jmhz-work-month.v4');

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('Ordinary ELDP automaticky podporuje');
        $builder = new JmhzEldpEvidenceBuilder();
        $builder->build(7, 101, $source, $builder->deriveOrdinaryConfirmation(7, 101, $source));
    }

    /**
     * Souhrn z importu docházky (v6) nese dovolenou a překážky jen jako
     * měsíční hodiny. Vyloučenou dobu ani vyloučený den netvoří, takže řez
     * projde i bez evidované nepřítomnosti.
     */
    public function testImportSummaryVacationAndObstacleHoursPassWithoutAbsenceRecords(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->importSummarySource(
            ['vacation_millihours' => 16_000, 'employee_obstacle_paid_millihours' => 2_000],
            obstacles: true,
        );

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['excluded_days_total']);
    }

    /**
     * Nemoc z importu bez dat od–do: vyloučené dny z hodin spočítat nejde,
     * tichá nula by byla nepravda. Zastaví vlastní kód.
     */
    public function testImportSummarySicknessHoursWithoutDatesAreAClearBlocker(): void
    {
        $source = $this->importSummarySource(['dpn_with_employer_compensation_millihours' => 16_000]);

        try {
            (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
            self::fail('Nemoc bez dat nesmí projít jako běžný měsíc.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_import_absence_dates_missing', $exception->validationCode);
        }
    }

    /** Uvolnění platí jen pro souhrn z importu, ne pro souhrn ze směn. */
    public function testShiftBasedSummaryStillNeedsAbsenceRecordForVacationHours(): void
    {
        $source = $this->importSummarySource(['vacation_millihours' => 16_000], version: 'jmhz-work-month.v5');

        try {
            (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
            self::fail('Souhrn ze směn musí mít k dovolené evidovanou nepřítomnost.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_work_summary_mismatch', $exception->validationCode);
        }
    }

    /**
     * Svátek v jinak pracovní den (souhrn v7) je neodpracovaná placená hodina
     * i bez evidované nepřítomnosti: úhrny 10275/10276 ho nesou, interakce
     * IN07 je aktivní a ELDP řez to přijme jako běžný měsíc.
     */
    public function testHolidayHoursPassWithoutAbsenceOnVersionSeven(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->holidaySource(8_000, 8_000, 8_000);

        $section = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        )->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['excluded_days_total']);
    }

    /** Úhrn, který svátek nenese, nebo ho nenese mezi placenými, neprojde. */
    public function testHolidayHoursMustBeCarriedByBothTotals(): void
    {
        foreach ([[8_000, 0, 8_000], [8_000, 8_000, null], [16_000, 8_000, 8_000]] as [$holiday, $total, $paid]) {
            try {
                (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(
                    7,
                    101,
                    $this->holidaySource($holiday, $total, $paid),
                );
                self::fail('Úhrny bez svátku musely řez zastavit.');
            } catch (JmhzEldpEvidenceException $exception) {
                self::assertSame('jmhz_eldp_work_summary_mismatch', $exception->validationCode);
            }
        }
    }

    /** Starší souhrn svátky nezná, klíč v něm tedy nesmí nic tvrdit. */
    public function testHolidayHoursAreIgnoredOnOlderWorkSummary(): void
    {
        $source = $this->holidaySource(8_000, 8_000, 8_000, 'jmhz-work-month.v5');

        try {
            (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
            self::fail('Souhrn v5 nemá svátky a úhrny bez nepřítomnosti musí být prázdné.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_work_summary_mismatch', $exception->validationCode);
        }
    }

    /** @return array<string,mixed> */
    private function holidaySource(
        int $holiday,
        int $total,
        ?int $paid,
        string $version = 'jmhz-work-month.v7',
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $summary = &$input['people'][0]['employments'][0]['time_month']['jmhz_work_summary'];
        $summary['derivation_version'] = $version;
        $summary['interactions'] = ['IN07' => true, 'IN08' => false];
        $summary['values'] += [
            'maternity_millihours' => null,
            'paternity_millihours' => null,
            'parental_millihours' => null,
            'unpaid_leave_millihours' => null,
            'unexcused_millihours' => null,
            'compensatory_time_off_millihours' => null,
            'holiday_millihours' => $holiday,
        ];
        $summary['values']['unworked_total_millihours'] = $total;
        $summary['values']['unworked_paid_millihours'] = $paid;
        unset($summary);

        return $this->withInput($source, $input);
    }

    /**
     * @param array<string,int> $extraValues
     * @return array<string,mixed>
     */
    private function importSummarySource(
        array $extraValues,
        bool $obstacles = false,
        string $version = 'jmhz-work-month.v6',
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $summary = &$input['people'][0]['employments'][0]['time_month']['jmhz_work_summary'];
        $summary['derivation_version'] = $version;
        $summary['interactions'] = ['IN07' => array_sum($extraValues) > 0, 'IN08' => $obstacles];
        $summary['values'] += [
            'maternity_millihours' => null,
            'paternity_millihours' => null,
            'parental_millihours' => null,
            'unpaid_leave_millihours' => null,
            'unexcused_millihours' => null,
            'compensatory_time_off_millihours' => null,
        ];
        $summary['values'] = array_merge($summary['values'], $extraValues);
        $paid = 0;
        foreach ([
            'vacation_millihours',
            'dpn_with_employer_compensation_millihours',
            'employee_obstacle_paid_millihours',
            'employer_obstacle_millihours',
        ] as $field) {
            $paid += $extraValues[$field] ?? 0;
        }
        $summary['values']['unworked_total_millihours'] = array_sum($extraValues) ?: null;
        $summary['values']['unworked_paid_millihours'] = $paid ?: null;
        unset($summary);

        return $this->withInput($source, $input);
    }

    /** @return array<string,mixed> */
    private function compensatoryTimeOffSource(string $version): array
    {
        $source = $this->absenceSource(
            'compensatory_time_off',
            '2026-07-13',
            '2026-07-14',
            ['compensatory_time_off_millihours' => 16_000],
        );
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['derivation_version'] = $version;

        return $this->withInput($source, $input);
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function withZeroAssessmentBase(array $source): array
    {
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $relationship = &$result['people'][0]['statutory']['social_insurance']['relationships'][0];
        $relationship['assessment_base_minor_units'] = 0;
        $relationship['capped_assessment_base_minor_units'] = 0;
        unset($relationship);
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash(
            'sha256',
            $source['revision']['result_snapshot_json'],
        );

        return $source;
    }

    /**
     * Překážka na straně zaměstnance je v aplikaci vždy placená, náhrada mzdy
     * vstupuje do vyměřovacího základu, a vyloučená doba by se s příjmem kryla
     * (§ 16 odst. 4 věta třetí návětí). Blok 10471 se ale vykázat musí, a to
     * s aktivní interakcí IN08.
     */
    public function testAllowsPaidEmployeeObstacleWithActiveInteractionIn08(): void
    {
        $builder = new JmhzEldpEvidenceBuilder();
        $source = $this->absenceSource(
            'employee_obstacle',
            '2026-07-13',
            '2026-07-13',
            ['employee_obstacle_paid_millihours' => 8_000],
            paidMillihours: 8_000,
            obstacles: true,
        );

        $snapshot = $builder->build(
            7,
            101,
            $source,
            $builder->deriveOrdinaryConfirmation(7, 101, $source),
        );
        $section = $snapshot->payload['eldp_sections'][0];

        self::assertSame(31, $section['insurance_days']);
        self::assertSame(0, $section['excluded_days_total']);
    }

    /**
     * Měsíc s nepřítomností, kterou v3 zná, sestaví ordinary řez ve stejném
     * tvaru jako roční evidenční list: jeden zdroj vyloučených dob pro obojí.
     *
     * @param array<string,int> $extraValues
     * @return array<string,mixed>
     */
    private function absenceSource(
        string $absenceType,
        string $from,
        string $to,
        array $extraValues,
        ?int $paidMillihours = null,
        bool $obstacles = false,
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $entry = &$input['people'][0]['employments'][0];
        $entry['absences'] = [[
            'id' => 910,
            'absence_type' => $absenceType,
            'date_from' => $from,
            'date_to' => $to,
        ]];
        $summary = &$entry['time_month']['jmhz_work_summary'];
        $summary['derivation_version'] = 'jmhz-work-month.v3';
        $summary['interactions'] = ['IN07' => true, 'IN08' => $obstacles];
        $summary['values'] += [
            'maternity_millihours' => null,
            'paternity_millihours' => null,
            'parental_millihours' => null,
            'unpaid_leave_millihours' => null,
            'unexcused_millihours' => null,
        ];
        $summary['values'] = array_merge($summary['values'], $extraValues);
        $summary['values']['unworked_total_millihours'] = array_sum($extraValues);
        $summary['values']['unworked_paid_millihours'] = $paidMillihours ?? 0;
        unset($summary, $entry);

        return $this->withInput($source, $input);
    }

    public function testRejectsIntervalShorterThanFrozenEmploymentMonth(): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']
            ['values']['evidence_days'] = 29;
        $source = $this->withInput($source, $input);
        $confirmation = $this->confirmation();
        $confirmation['insurance_from'] = '2026-07-02';
        $confirmation['insurance_to'] = '2026-07-30';
        $confirmation['valid_from'] = '2026-07-02';
        $confirmation['valid_to'] = '2026-07-30';
        $confirmation['insurance_days'] = 29;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('průniku pracovního vztahu');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $confirmation);
    }

    public function testRejectsZeroAssessmentBaseForPositiveOrdinarySection(): void
    {
        $source = $this->source();
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['assessment_base_minor_units'] = 0;
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]
            ['capped_assessment_base_minor_units'] = 0;
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        $confirmation = $this->confirmation();
        $confirmation['assessment_base_czk'] = 0;

        $this->expectException(JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('evidovanou nepřítomnost');
        (new JmhzEldpEvidenceBuilder())->build(7, 101, $source, $confirmation);
    }

    /**
     * Měsíc s dočasnou pracovní neschopností 7.–18. 7. 2026.
     *
     * Dvanáct kalendářních dnů nemoci proti čtyřiceti neodpracovaným hodinám
     * v pracovním souhrnu — hodiny drží směny, dny kalendář, a ordinary řez
     * musí obojí spárovat.
     *
     * @return array<string,mixed>
     */
    private function sicknessSource(): array
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $entry = &$input['people'][0]['employments'][0];
        $entry['absences'] = [[
            'id' => 903,
            'absence_type' => 'dpn',
            'date_from' => '2026-07-07',
            'date_to' => '2026-07-18',
        ]];
        $summary = &$entry['time_month']['jmhz_work_summary'];
        $summary['interactions']['IN07'] = true;
        $summary['values']['unworked_total_millihours'] = 40_000;
        $summary['values']['unworked_paid_millihours'] = 40_000;
        $summary['values']['dpn_with_employer_compensation_millihours'] = 40_000;
        unset($summary, $entry);

        return $this->withInput($source, $input);
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function withInput(array $source, array $input): array
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] = hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        return $source;
    }

    /** @return array<string,mixed> */
    private function confirmation(): array
    {
        return [
            'insurance_from' => '2026-07-01',
            'insurance_to' => '2026-07-31',
            'valid_from' => '2026-07-01',
            'valid_to' => '2026-07-31',
            'insurance_days' => 31,
            'code' => '1++',
            'assessment_base_czk' => 10_000,
            'in03_active' => false,
            'in04_active' => false,
            'confirmation_note' => 'Syntetické potvrzení běžného měsíce bez zvláštností.',
        ];
    }

    /**
     * Příprava JMHZ volá builder za KAŽDÝ vztah revize nad týmž zdrojem.
     *
     * Dřív každé volání znovu ověřilo a dekódovalo oba snímky revize a znovu
     * načetlo resolver scénářů z disku, takže příprava rostla s kvadrátem
     * velikosti firmy (226 vztahů ≈ 5,5 min). Test srovnává cenu dalšího
     * vztahu s prvním. Pětinásobný odstup ponechává rezervu pro kolísání
     * sdíleného CI runneru a stále odhalí opakované dekódování celé revize.
     */
    public function testDerivingEveryEmploymentOfALargeRevisionDoesNotRedoTheWholeRevision(): void
    {
        $count = 120;
        $source = $this->sourceWithPeople($count);
        $builder = new JmhzEldpEvidenceBuilder();

        $started = hrtime(true);
        $builder->deriveOrdinaryConfirmation(7, 101, $source);
        $first = hrtime(true) - $started;

        $started = hrtime(true);
        for ($ordinal = 2; $ordinal <= $count; ++$ordinal) {
            $confirmation = $builder->deriveOrdinaryConfirmation(7, 100 + $ordinal, $source);
            self::assertSame('1++', $confirmation['code']);
        }
        $averageNext = (hrtime(true) - $started) / ($count - 1);

        self::assertLessThan(
            $first / 5,
            $averageNext,
            sprintf(
                'Další vztah téže revize stojí %.2f ms, první %.2f ms — builder zřejmě znovu zpracovává celou revizi.',
                $averageNext / 1e6,
                $first / 1e6,
            ),
        );
    }

    /**
     * Revize s `$count` osobami po jednom vztahu (101, 102, …) s výplní,
     * aby snímek měl velikost srovnatelnou se skutečnou firmou.
     *
     * @return array<string,mixed>
     */
    private function sourceWithPeople(int $count): array
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        self::assertIsArray($result);
        $templatePerson = $input['people'][0];
        $templateResult = $result['people'][0];
        $input['people'] = [];
        $resultPeople = [];
        for ($ordinal = 1; $ordinal <= $count; ++$ordinal) {
            $employeeId = 10 + $ordinal;
            $employmentId = 100 + $ordinal;
            $person = $templatePerson;
            $person['employee']['id'] = $employeeId;
            $person['employments'][0]['employment']['id'] = $employmentId;
            $person['employments'][0]['employment']['employee_id'] = $employeeId;
            $person['employments'][0]['synthetic_padding'] = str_repeat('x', 4000);
            $input['people'][] = $person;

            $resultPerson = $templateResult;
            $resultPerson['employee_id'] = $employeeId;
            $resultPerson['employments'][0]['employment_id'] = $employmentId;
            $relationship = &$resultPerson['statutory']['social_insurance']['relationships'][0];
            $relationship['relationship_id'] = "employment:{$employmentId}";
            $relationship['participation']['relationship_id'] = "employment:{$employmentId}";
            unset($relationship);
            $resultPerson['synthetic_padding'] = str_repeat('y', 4000);
            $resultPeople[] = $resultPerson;
        }
        $source = $this->withInput($source, $input);
        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $result['people'] = $resultPeople;
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);

        return $source;
    }

    /** @return array<string,mixed> */
    private function agreementSource(
        string $relationType,
        string $activityCode,
        string $kind,
        string $participationStatus,
        int $participationIncomeMinor,
        int $assessmentBaseMinor,
        int $cappedAssessmentBaseMinor,
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['employment']['relation_type'] = $relationType;
        $input['people'][0]['employments'][0]['term']['activity_code'] = $activityCode;
        $input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code'] =
            in_array($relationType, ['dpc', 'dpp'], true) ? null : '1';
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] = 0;
        $source = $this->withInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $relationship = &$result['people'][0]['statutory']['social_insurance']['relationships'][0];
        $relationship['kind'] = $kind;
        $relationship['participation']['status'] = $participationStatus;
        $relationship['participation']['participation_income_minor_units'] = $participationIncomeMinor;
        $relationship['participation']['group_income_minor_units'] = $participationIncomeMinor;
        $relationship['assessment_base_minor_units'] = $assessmentBaseMinor;
        $relationship['capped_assessment_base_minor_units'] = $cappedAssessmentBaseMinor;
        unset($relationship);
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] = hash('sha256', $source['revision']['result_snapshot_json']);
        return $source;
    }

    /** @return array<string,mixed> */
    private function source(): array
    {
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => 7,
            'period_start' => '2026-07-01',
            'people' => [[
                'employee' => ['id' => 11],
                'employments' => [[
                    'employment' => [
                        'id' => 101,
                        'employee_id' => 11,
                        'relation_type' => 'employment',
                        'start_date' => '2026-01-01',
                        'actual_start_date' => '2026-01-01',
                        'end_date' => null,
                    ],
                    'term' => [
                        'id' => 201,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'time_month' => [
                        'jmhz_work_summary' => [
                            'id' => 301,
                            'derivation_version' => 'jmhz-work-month.v2',
                            'summary_sha256' => str_repeat('d', 64),
                            'conditional_blocks_confirmed' => true,
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'evidence_days' => 31,
                                'unworked_total_millihours' => null,
                                'unworked_paid_millihours' => null,
                                'dpn_without_employer_compensation_millihours' => null,
                                'dpn_with_employer_compensation_millihours' => null,
                                'vacation_millihours' => null,
                                'care_millihours' => null,
                                'employee_obstacle_paid_millihours' => null,
                                'employer_obstacle_millihours' => null,
                            ],
                        ],
                    ],
                    'absences' => [],
                    'inputs' => [],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => 11,
                'employments' => [[
                    'employment_id' => 101,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'relationships' => [[
                            'relationship_id' => 'employment:101',
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => 'employment:101',
                                'status' => 'participates',
                                'reason_codes' => [],
                                'participation_income_minor_units' => 1_000_000,
                                'group_income_minor_units' => 1_000_000,
                            ],
                            'assessment_base_minor_units' => 1_000_000,
                            'capped_assessment_base_minor_units' => 1_000_000,
                        ]],
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);
        return [
            'revision' => [
                'id' => 401,
                'run_id' => 501,
                'revision_no' => 1,
                'current_revision_no' => 1,
                'revision_kind' => 'regular',
                'status' => 'approved',
                'period_start' => '2026-07-01',
                'ruleset_manifest_hash' => str_repeat('a', 64),
                'input_snapshot_json' => $inputJson,
                'input_snapshot_hash' => hash('sha256', $inputJson),
                'result_snapshot_json' => $resultJson,
                'result_snapshot_hash' => hash('sha256', $resultJson),
            ],
        ];
    }
}
