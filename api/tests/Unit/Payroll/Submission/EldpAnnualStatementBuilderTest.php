<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatement;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Všechna data jsou zjevně syntetická (firma 7, osoba 11, vztah 101,
 * 10 000 Kč měsíčně) a žádný test nesahá na síť.
 */
final class EldpAnnualStatementBuilderTest extends TestCase
{
    public function testExcludedPeriodBlockerCarriesMachineReadableMonthAndEmployment(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[2] = $this->revision(2025, 3, absences: [[
            'id' => 9001, 'absence_type' => 'other',
            'date_from' => '2025-03-05', 'date_to' => '2025-03-09',
        ]]);
        try {
            $this->build($revisions);
            self::fail('Unsupported absence must remain blocked.');
        } catch (EldpValidationException $exception) {
            $blockers = array_values(array_filter($exception->blockers, static fn (array $blocker): bool => $blocker['code'] === 'eldp_absence_kind_unsupported'));
            self::assertCount(1, $blockers);
            self::assertSame('2025-03-01', $blockers[0]['detail']['period_start']);
            self::assertSame(self::EMPLOYMENT_ID, $blockers[0]['detail']['employment_id']);
            self::assertSame(9001, $blockers[0]['detail']['absence_id']);
        }
    }

    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;

    public function testBuildsWholeYearAsSingleSection(): void
    {
        $statement = $this->build($this->wholeYear(2025));

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('1++', $sections[0]['code']);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-12-31', $sections[0]['valid_to']);
        self::assertSame(365, $sections[0]['insurance_days']);
        self::assertSame(120_000, $sections[0]['assessment_base_czk']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
        self::assertSame(0, $sections[0]['deducted_days_total']);
        self::assertSame('annual', $statement->scope()['statement_kind']);
        self::assertSame(
            '2026-04-30',
            $statement->payload['deadline']['due_on'],
        );
        self::assertSame(
            'transitional_before_2026',
            $statement->payload['eligibility']['rule'],
        );
    }

    public function testBuildsPartialYearWhenEmploymentEnds(): void
    {
        $statement = $this->build(
            $this->months(2025, 1, 8, employmentEnd: '2025-08-15'),
        );

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-08-15', $sections[0]['valid_to']);
        self::assertSame(227, $sections[0]['insurance_days']);
        self::assertSame(80_000, $sections[0]['assessment_base_czk']);
        self::assertSame('termination', $statement->scope()['statement_kind']);
        self::assertSame(
            '2025-09-30',
            $statement->payload['deadline']['due_on'],
        );
        self::assertStringContainsString(
            'do jednoho měsíce po konečném vyúčtování',
            $statement->payload['deadline']['legal_basis'],
        );
    }

    public function testCurrentApprovedCorrectiveRevisionIsAcceptedAsSource(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[7]['revision_no'] = 2;
        $revisions[7]['current_revision_no'] = 2;
        $revisions[7]['revision_kind'] = 'correction';

        $statement = $this->build($revisions);

        self::assertSame(408, $statement->payload['source_revisions'][7]['revision_id']);
        self::assertSame('2025-08-01', $statement->payload['source_revisions'][7]['period_start']);
    }

    public function testExcludedDaysAreTraceableToTheAbsenceTheyComeFrom(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[2] = $this->revision(
            2025,
            3,
            absences: [[
                'id' => 9001,
                'absence_type' => 'dpn',
                'date_from' => '2025-03-05',
                'date_to' => '2025-03-18',
            ]],
        );

        $statement = $this->build($revisions);

        $section = $statement->sections()[0];
        self::assertSame(14, $section['excluded_days_total']);
        self::assertSame(14, $section['excluded_days']['docasNeschopnost']);
        self::assertSame(0, $section['excluded_days']['penezitaPomocMaterstvi']);
        self::assertCount(1, $section['excluded_days_provenance']);
        self::assertSame([
            'absence_id' => 9001,
            'absence_type' => 'dpn',
            'attribute' => 'docasNeschopnost',
            'absence_from' => '2025-03-05',
            'absence_to' => '2025-03-18',
            'counted_from' => '2025-03-05',
            'counted_to' => '2025-03-18',
            'days' => 14,
            'period_start' => '2025-03-01',
        ], $section['excluded_days_provenance'][0]);
        self::assertSame(365, $section['insurance_days']);
    }

    public function testExcludedDaysAreClippedToTheInsurancePeriod(): void
    {
        $revisions = $this->months(2025, 1, 2, employmentEnd: '2025-02-10');
        $revisions[1] = $this->revision(
            2025,
            2,
            employmentEnd: '2025-02-10',
            absences: [[
                'id' => 9002,
                'absence_type' => 'paternity',
                'date_from' => '2025-02-05',
                'date_to' => '2025-02-28',
            ]],
        );

        $section = $this->build($revisions)->sections()[0];

        self::assertSame(6, $section['excluded_days']['otcovska']);
        self::assertSame('2025-02-05', $section['excluded_days_provenance'][0]['counted_from']);
        self::assertSame('2025-02-10', $section['excluded_days_provenance'][0]['counted_to']);
    }

    public function testMissingMonthBlocksAndNamesIt(): void
    {
        $revisions = $this->wholeYear(2025);
        unset($revisions[2]);

        try {
            $this->build(array_values($revisions));
            self::fail('Chybějící měsíc musel evidenční list zablokovat.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_source_incomplete', $exception->validationCode);
            self::assertStringContainsString('březen 2025', $exception->getMessage());
            self::assertStringContainsString('schválená mzdová revize', $exception->getMessage());
            self::assertSame(
                'eldp_month_source_missing',
                $exception->blockers[0]['code'],
            );
        }
    }

    /**
     * Peněžitá pomoc v mateřství se na vyloučené doby nepřevádí celá.
     *
     * Vyloučenou dobou je podle § 16 odst. 4 věty třetí písm. a) zákona
     * č. 155/1995 Sb. jen doba PŘED PORODEM (a nejdříve od osmého týdne před
     * očekávaným dnem porodu); zbytek podpůrčí doby vyloučenou dobou není.
     *
     * Syntetický rok: PPM od 1. 4. 2025, očekávaný porod 20. 5., porod 18. 5.
     * Duben je celý před porodem (30 dnů 10359, bez příjmu, ale omluvný, takže
     * dobou pojištění zůstává). V květnu je 17 dnů před porodem a zúčtovaný
     * příjem. Červen až listopad jsou po porodu bez příjmu, a tedy podle § 11
     * odst. 2 mimo dobu pojištění s nulou dnů.
     */
    public function testMaternityCountsOnlyThePreBirthPartAndPostBirthMonthsAddNoDays(): void
    {
        $statement = $this->build($this->maternityYear());

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame(31 + 28 + 31 + 30 + 31 + 31, $sections[0]['insurance_days']);
        self::assertSame(50_000, $sections[0]['assessment_base_czk']);
        self::assertSame(47, $sections[0]['excluded_days']['penezitaPomocMaterstvi']);
        self::assertSame(47, $sections[0]['excluded_days_total']);
        $provenance = array_column($sections[0]['excluded_days_provenance'], null, 'period_start');
        self::assertSame('2025-05-17', $provenance['2025-05-01']['counted_to']);
        self::assertSame(17, $provenance['2025-05-01']['days']);
        self::assertArrayNotHasKey('2025-06-01', $provenance);

        $xml = (new EldpXmlSerializer())->serialize($statement);
        self::assertStringContainsString('<penezitaPomocMaterstvi>47</penezitaPomocMaterstvi>', $xml);
        self::assertStringContainsString('<vylouceneDobyCelkem>47</vylouceneDobyCelkem>', $xml);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /** Měsíc porodu bez příjmu je souběh omluvné a neomluvné části. */
    public function testMaternityBirthMonthWithoutIncomeBlocks(): void
    {
        $revisions = $this->maternityYear();
        $revisions[4] = $this->revision(2025, 5, absences: [$this->maternityAbsence()], baseMinor: 0);

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('§ 11 odst. 2');
        $this->build($revisions);
    }

    /** @return list<array<string,mixed>> */
    private function maternityYear(): array
    {
        $revisions = $this->wholeYear(2025);
        foreach (range(4, 11) as $month) {
            $revisions[$month - 1] = $this->revision(
                2025,
                $month,
                absences: [$this->maternityAbsence()],
                baseMinor: $month === 5 ? 1_000_000 : 0,
            );
        }

        return $revisions;
    }

    /** @return array<string,mixed> */
    private function maternityAbsence(): array
    {
        return [
            'id' => 9100,
            'absence_type' => 'ppm',
            'date_from' => '2025-04-01',
            'date_to' => '2025-11-30',
            'expected_childbirth_date' => '2025-05-20',
            'childbirth_date' => '2025-05-18',
        ];
    }

    public function testCompensatoryTimeOffIsNeutralAndDoesNotBlockTheStatement(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[5] = $this->revision(
            2025,
            6,
            absences: [[
                'id' => 9200,
                'absence_type' => 'compensatory_time_off',
                'date_from' => '2025-06-02',
                'date_to' => '2025-06-06',
            ]],
        );

        $statement = $this->build($revisions);

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame(365, $sections[0]['insurance_days']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
        foreach ($sections[0]['excluded_days'] as $component => $days) {
            self::assertSame(0, $days, "Náhradní volno se promítlo do {$component}.");
        }
        self::assertSame([], $sections[0]['excluded_days_provenance']);
    }

    /**
     * Neplacené volno, neomluvená absence ani rodičovská nejsou vyloučenou
     * dobou: výčet § 16 odst. 4 věty třetí písm. a) zákona č. 155/1995 Sb. je
     * uzavřený a ani jedna z nich v něm není. Doba pojištění se u nich krátí
     * jinak — celým měsícem podle § 11 odst. 2 (znak „X"), ne po dnech.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incomeLessAbsenceKinds')]
    public function testIncomeLessAbsenceIsNeutralAndDoesNotBlockTheStatement(
        string $absenceType,
    ): void {
        $revisions = $this->wholeYear(2025);
        $revisions[5] = $this->revision(
            2025,
            6,
            absences: [[
                'id' => 9300,
                'absence_type' => $absenceType,
                'date_from' => '2025-06-02',
                'date_to' => '2025-06-06',
            ]],
        );

        $sections = $this->build($revisions)->sections();

        self::assertCount(1, $sections);
        self::assertSame(365, $sections[0]['insurance_days']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
        self::assertSame([], $sections[0]['excluded_days_provenance']);
    }

    /** @return array<string,array{string}> */
    public static function incomeLessAbsenceKinds(): array
    {
        return [
            'neplacené volno' => ['unpaid_leave'],
            'neomluvená absence' => ['unexcused'],
            'rodičovská dovolená' => ['parental'],
            'překážka na straně zaměstnance' => ['employee_obstacle'],
        ];
    }

    /**
     * § 11 odst. 2: celý červen neplaceného volna bez příjmu není dobou
     * pojištění. Sekce pokračuje s kódem, ale měsíc přidá nula dnů a nulový
     * základ. Dřív se započítal jako třicet dnů pojištění.
     */
    public function testMonthWithoutIncomeBecauseOfUnpaidLeaveAddsNoInsuranceDays(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[5] = $this->revision(
            2025,
            6,
            absences: [[
                'id' => 9310,
                'absence_type' => 'unpaid_leave',
                'date_from' => '2025-06-01',
                'date_to' => '2025-06-30',
            ]],
            baseMinor: 0,
        );

        $sections = $this->build($revisions)->sections();

        self::assertCount(1, $sections);
        self::assertSame('1++', $sections[0]['code']);
        self::assertSame(335, $sections[0]['insurance_days']);
        self::assertSame(110_000, $sections[0]['assessment_base_czk']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
    }

    public function testMonthWithoutIncomeMixingSicknessAndUnpaidLeaveBlocks(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[5] = $this->revision(
            2025,
            6,
            absences: [
                [
                    'id' => 9311,
                    'absence_type' => 'unpaid_leave',
                    'date_from' => '2025-06-01',
                    'date_to' => '2025-06-15',
                ],
                [
                    'id' => 9312,
                    'absence_type' => 'dpn',
                    'date_from' => '2025-06-16',
                    'date_to' => '2025-06-30',
                ],
            ],
            baseMinor: 0,
        );

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('§ 11 odst. 2');
        $this->build($revisions);
    }

    public function testDeductedDaysMustBeConfirmedExplicitly(): void
    {
        $confirmation = $this->confirmation();
        unset($confirmation['deducted_days_none']);

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('odečítané doby');
        (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->wholeYear(2025),
            $confirmation,
        );
    }

    /**
     * Regrese: poznámka bývala povinná (5–500 znaků) a bez ní se evidenční list
     * nedal sestavit vůbec. ČSSZ ji přitom nikde nepřijímá — do XML se
     * nedostane — takže zákonná povinnost stála na našem interním zápisu.
     */
    public function testStatementIsBuiltWithoutAnyNote(): void
    {
        $confirmation = $this->confirmation();
        unset($confirmation['note']);

        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->wholeYear(2025),
            $confirmation,
        );

        self::assertSame('', $statement->payload['confirmation']['note']);
        self::assertCount(1, $statement->sections());
    }

    public function testShortNoteNoLongerBlocksTheStatement(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['note'] = 'ok';

        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->wholeYear(2025),
            $confirmation,
        );

        self::assertSame('ok', $statement->payload['confirmation']['note']);
    }

    /** Horní mez zůstává — text se musí vejít do sloupce. */
    public function testOverlongNoteIsStillRefused(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['note'] = str_repeat('a', 501);

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('nejvýše 500 znaků');
        (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->wholeYear(2025),
            $confirmation,
        );
    }

    public function testYearFrom2026IsAssembledByCsszUnlessTransitionalRuleApplies(): void
    {
        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('nevyhotovuje ani nepředkládá');
        (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2026,
            $this->wholeYear(2026),
            $this->confirmation(),
        );
    }

    public function testParticipationEndedBeforeApril2026KeepsTheOldWay(): void
    {
        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2026,
            $this->months(2026, 1, 2, employmentEnd: '2026-02-28'),
            $this->confirmation(),
        );

        self::assertSame(
            'transitional_participation_ended_before_april_2026',
            $statement->payload['eligibility']['rule'],
        );
    }

    public function testAuthorityRequestDuringYearEndsAtLastAccountedMonth(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['requested_by_authority'] = true;
        $confirmation['authority_request_received_on'] = '2026-08-25';

        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2026,
            $this->months(2026, 1, 8),
            $confirmation,
        );

        self::assertSame('2026-08-31', $statement->scope()['period_to']);
        self::assertSame(243, $statement->sections()[0]['insurance_days']);
        self::assertSame(80_000, $statement->sections()[0]['assessment_base_czk']);
        self::assertSame(
            'on_authority_request',
            $statement->payload['eligibility']['rule'],
        );
        self::assertSame(
            'cz-eldp-deadlines.authority-request.v1',
            $statement->payload['deadline']['ruleset_id'],
        );
        self::assertSame(
            '2026-08-25',
            $statement->payload['deadline']['earliest_submission_on'],
        );
        self::assertSame('2026-09-02', $statement->payload['deadline']['due_on']);
    }

    public function testAuthorityRequestFromFollowingYearStillRequiresWholeYear(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['requested_by_authority'] = true;
        $confirmation['authority_request_received_on'] = '2027-01-10';

        try {
            (new EldpAnnualStatementBuilder())->build(
                self::SUPPLIER_ID,
                self::EMPLOYMENT_ID,
                2026,
                $this->months(2026, 1, 8),
                $confirmation,
            );
            self::fail('Výzva po skončení roku nesmí zakrýt chybějící měsíce.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_source_incomplete', $exception->validationCode);
            self::assertStringContainsString('září 2026', $exception->getMessage());
        }
    }

    public function testAuthorityRequestStillBlocksMissingMonthInsideReportedPeriod(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['requested_by_authority'] = true;
        $confirmation['authority_request_received_on'] = '2026-08-25';
        $revisions = $this->months(2026, 1, 8);
        unset($revisions[4]);

        try {
            (new EldpAnnualStatementBuilder())->build(
                self::SUPPLIER_ID,
                self::EMPLOYMENT_ID,
                2026,
                array_values($revisions),
                $confirmation,
            );
            self::fail('Chybějící měsíc uvnitř období musí výzvu zablokovat.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_source_incomplete', $exception->validationCode);
            self::assertStringContainsString('květen 2026', $exception->getMessage());
            self::assertStringNotContainsString('září 2026', $exception->getMessage());
        }
    }

    public function testAuthorityRequestRequiresItsReceiptDate(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['requested_by_authority'] = true;

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('datum doručení výzvy');
        (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2026,
            $this->months(2026, 1, 8),
            $confirmation,
        );
    }

    public function testInconsistentEmploymentDatesBlockTheStatement(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[7] = $this->revision(2025, 8, employmentStart: '2025-02-01');

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('nesourodé podklady');
        $this->build($revisions);
    }

    public function testXmlMatchesThePinnedOfficialSchema(): void
    {
        $revisions = $this->wholeYear(2025);
        $revisions[2] = $this->revision(
            2025,
            3,
            absences: [[
                'id' => 9001,
                'absence_type' => 'ocr',
                'date_from' => '2025-03-05',
                'date_to' => '2025-03-09',
            ]],
        );
        $statement = $this->build($revisions);
        $xml = (new EldpXmlSerializer())->serialize($statement);

        self::assertStringContainsString('<kod>1++</kod>', $xml);
        self::assertStringContainsString('<vylouceneDobyCelkem>5</vylouceneDobyCelkem>', $xml);
        self::assertStringContainsString('<osetrovaniClenaRodiny>5</osetrovaniClenaRodiny>', $xml);
        self::assertStringContainsString('<odecitaneDobyCelkem>0</odecitaneDobyCelkem>', $xml);

        $evidence = (new EldpXmlValidator())->validate($statement, $xml);
        self::assertSame('jmhz-1.4.3.6', $evidence['package_key']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evidence['bundle_sha256']);
    }

    public function testXmlIsByteStable(): void
    {
        $statement = $this->build($this->wholeYear(2025));
        $serializer = new EldpXmlSerializer();

        self::assertSame(
            $serializer->serialize($statement),
            $serializer->serialize($statement),
        );
    }

    public function testTamperedXmlIsRejectedBeforeSchemaValidation(): void
    {
        $statement = $this->build($this->wholeYear(2025));
        $xml = str_replace(
            '<pocetDnu>365</pocetDnu>',
            '<pocetDnu>366</pocetDnu>',
            (new EldpXmlSerializer())->serialize($statement),
        );

        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('neodpovídá přesnému zdrojovému snapshotu');
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    public function testStandaloneSubmissionSchemaIsFailClosed(): void
    {
        $this->expectException(EldpValidationException::class);
        $this->expectExceptionMessage('neobsahuje XSD samostatného ELDP podání');
        (new \MyInvoice\Service\Payroll\Submission\Eldp\EldpSchemaCatalog())
            ->submissionSchema();
    }

    /** @param list<array<string,mixed>> $revisions */
    private function build(array $revisions, int $year = 2025): EldpAnnualStatement
    {
        return (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            $year,
            $revisions,
            $this->confirmation(),
        );
    }

    /** @return array<string,mixed> */
    private function confirmation(): array
    {
        return [
            'excluded_days_confirmed' => true,
            'deducted_days_none' => true,
            'requested_by_authority' => false,
            'note' => 'Syntetický evidenční list pro test, žádná reálná data.',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function wholeYear(int $year): array
    {
        return $this->months($year, 1, 12);
    }

    /** @return list<array<string,mixed>> */
    private function months(
        int $year,
        int $from,
        int $to,
        ?string $employmentEnd = null,
    ): array {
        $revisions = [];
        for ($month = $from; $month <= $to; ++$month) {
            $revisions[] = $this->revision($year, $month, employmentEnd: $employmentEnd);
        }

        return $revisions;
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @return array<string,mixed>
     */
    private function revision(
        int $year,
        int $month,
        ?string $employmentStart = null,
        ?string $employmentEnd = null,
        array $absences = [],
        int $baseMinor = 1_000_000,
    ): array {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => self::SUPPLIER_ID,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => self::EMPLOYEE_ID],
                'employments' => [[
                    'employment' => [
                        'id' => self::EMPLOYMENT_ID,
                        'employee_id' => self::EMPLOYEE_ID,
                        'relation_type' => 'employment',
                        'start_date' => $employmentStart ?? sprintf('%04d-01-01', $year),
                        'actual_start_date' => $employmentStart ?? sprintf('%04d-01-01', $year),
                        'end_date' => $employmentEnd,
                    ],
                    'term' => [
                        'id' => 201,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'absences' => $absences,
                    'inputs' => [],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $result = [
            'schema_version' => 'payroll-run-result.v2',
            'source_snapshot_hash' => hash('sha256', $inputJson),
            'people' => [[
                'employee_id' => self::EMPLOYEE_ID,
                'employments' => [[
                    'employment_id' => self::EMPLOYMENT_ID,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'relationships' => [[
                            'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                                'status' => 'participates',
                                'reason_codes' => [],
                            ],
                            'assessment_base_minor_units' => $baseMinor,
                            'capped_assessment_base_minor_units' => $baseMinor,
                        ]],
                    ],
                ],
            ]],
        ];
        $resultJson = CanonicalJson::encode($result);

        return [
            'id' => 400 + $month,
            'run_id' => 500 + $month,
            'revision_no' => 1,
            'current_revision_no' => 1,
            'revision_kind' => 'regular',
            'status' => 'approved',
            'period_start' => $periodStart,
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
        ];
    }
}
