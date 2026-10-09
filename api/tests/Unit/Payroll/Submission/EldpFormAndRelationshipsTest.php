<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatement;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementCopyService;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Evidenční listy, které přijatá podání jiných mzdových programů obsahují
 * a MyÚčto je dřív sestavit neumělo: příjem zúčtovaný po skončení zaměstnání
 * („1P+"), dohoda o pracovní činnosti a dohoda o provedení práce s účastí,
 * a údaje tiskopisu, které kontrolní XML nenese (typ, „zaměstnán od", datum
 * vyhotovení, měsíce „X").
 *
 * Všechna data jsou syntetická (firma 7, osoba 11, vztah 101).
 */
final class EldpFormAndRelationshipsTest extends TestCase
{
    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;

    /**
     * Revize za měsíc po skončení vztahu s vyměřovacím základem je dodatečně
     * zúčtovaný příjem. Dřív shodila celý list blokátorem
     * `eldp_month_outside_employment`.
     */
    public function testIncomeAccountedAfterTerminationBecomesAnUndatedOnePPlusRow(): void
    {
        $revisions = [
            $this->revision(2026, 1, employmentEnd: '2026-02-28'),
            $this->revision(2026, 2, employmentEnd: '2026-02-28'),
            $this->revision(2026, 3, employmentEnd: '2026-02-28', baseMinor: 5_335_000),
        ];

        $statement = $this->build($revisions, 2026);

        $sections = $statement->sections();
        self::assertCount(2, $sections);
        self::assertSame('1++', $sections[0]['code']);
        self::assertSame('2026-02-28', $sections[0]['valid_to']);
        self::assertSame(20_000, $sections[0]['assessment_base_czk']);
        self::assertSame('1P+', $sections[1]['code']);
        self::assertNull($sections[1]['valid_from']);
        self::assertNull($sections[1]['valid_to']);
        self::assertSame(0, $sections[1]['insurance_days']);
        self::assertSame(53_350, $sections[1]['assessment_base_czk']);
        self::assertSame(['2026-03-01'], $sections[1]['post_termination_periods']);
        self::assertSame('2026-02-28', $statement->scope()['period_to']);
        self::assertSame('termination', $statement->scope()['statement_kind']);
        // Konečné vyúčtování je březnový dodatečný příjem, ne únorový konec.
        self::assertSame('2026-03-31', $statement->payload['deadline']['earliest_submission_on']);

        $xml = (new EldpXmlSerializer())->serialize($statement);
        self::assertStringContainsString("<kod>1P+</kod>\n    <pocetDnu>0</pocetDnu>", $xml);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /** Měsíc po skončení bez vyměřovacího základu do listu nepatří vůbec. */
    public function testMonthAfterTerminationWithoutBaseIsIgnored(): void
    {
        $statement = $this->build([
            $this->revision(2025, 1, employmentEnd: '2025-01-31'),
            $this->revision(2025, 2, employmentEnd: '2025-01-31', baseMinor: 0),
        ]);

        self::assertCount(1, $statement->sections());
        self::assertSame('1++', $statement->sections()[0]['code']);
    }

    /**
     * Zaměstnání skončilo v předchozím roce a v novém roce přišel jen doplatek:
     * list má jediný řádek 1P+ a nulu dnů pojištění.
     */
    public function testPostTerminationIncomeAloneInTheFollowingYear(): void
    {
        $statement = $this->build(
            [$this->revision(2026, 1, employmentStart: '2025-01-01', employmentEnd: '2025-12-31', baseMinor: 1_200_000)],
            2026,
        );

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('1P+', $sections[0]['code']);
        self::assertSame(12_000, $sections[0]['assessment_base_czk']);
        self::assertSame('2026-01-01', $statement->scope()['period_from']);
        self::assertSame('2026-01-31', $statement->scope()['period_to']);
        self::assertSame('02', $statement->payload['form']['eldp_type']);
        self::assertStringContainsString(
            'příjem zúčtovaný po skončení zaměstnání',
            $statement->payload['deadline']['legal_basis'],
        );
        self::assertSame('2026-01-31', $statement->payload['deadline']['earliest_submission_on']);
        (new EldpXmlValidator())->validate($statement, (new EldpXmlSerializer())->serialize($statement));
    }

    /**
     * DPČ se účastní jen v měsících s příjmem nad rozhodnou částkou. Měsíce
     * bez účasti nejsou dobou pojištění a vyznačí se „X". Dřív list pro DPČ
     * vůbec nevznikl (`eldp_relationship_kind_unsupported`).
     */
    public function testAgreementToCompleteAJobWithParticipationBuildsAnAPlusPlusSection(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 12; ++$month) {
            $revisions[] = $this->revision(
                2025,
                $month,
                relationType: 'dpc',
                activityCode: 'A',
                detailCode: null,
                participation: $month <= 6 ? 'participates' : 'does_not_participate',
                baseMinor: $month <= 6 ? 500_000 : 200_000,
            );
        }

        $statement = $this->build($revisions);

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('A++', $sections[0]['code']);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-12-31', $sections[0]['valid_to']);
        self::assertSame(181, $sections[0]['insurance_days']);
        self::assertSame(30_000, $sections[0]['assessment_base_czk']);
        self::assertSame([7, 8, 9, 10, 11, 12], $sections[0]['months_without_insurance']);
        (new EldpXmlValidator())->validate($statement, (new EldpXmlSerializer())->serialize($statement));
    }

    /** DPP, která se v roce ani jednou neúčastnila, evidenční list nemá. */
    public function testAgreementToPerformWorkWithoutAnyParticipationHasNoStatement(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 3; ++$month) {
            $revisions[] = $this->revision(
                2025,
                $month,
                employmentEnd: '2025-03-31',
                relationType: 'dpp',
                activityCode: 'T',
                detailCode: null,
                participation: 'does_not_participate',
                baseMinor: 0,
            );
        }

        try {
            $this->build($revisions);
            self::fail('DPP bez účasti nesmí vyrobit evidenční list samých „X".');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_no_insurance_period', $exception->validationCode);
        }
    }

    /** DPP s účastí dostane svůj kód z druhu činnosti (T++). */
    public function testAgreementToPerformWorkWithParticipationBuildsATPlusPlusSection(): void
    {
        $statement = $this->build([
            $this->revision(2025, 1, employmentEnd: '2025-01-31', relationType: 'dpp', activityCode: 'T', detailCode: null, baseMinor: 1_200_000),
        ]);

        self::assertSame('T++', $statement->sections()[0]['code']);
        self::assertSame(31, $statement->sections()[0]['insurance_days']);
    }

    /** Pracovní poměr bez účasti zůstává rozporem, ne měsícem „X". */
    public function testEmploymentWithoutParticipationStillBlocks(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 12; ++$month) {
            $revisions[] = $this->revision(2025, $month, participation: $month === 5 ? 'does_not_participate' : 'participates');
        }

        try {
            $this->build($revisions);
            self::fail('Pracovní poměr bez účasti musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_social_participation_missing', $exception->blockers[0]['code']);
        }
    }

    /**
     * Údaje tiskopisu: typ 01 pro roční list, „zaměstnán od" a datum
     * vyhotovení nejdříve v den „Do".
     */
    public function testFormCarriesTypeEmployedFromAndPreparedOn(): void
    {
        $statement = $this->build($this->months(2025, 1, 12, employmentStart: '2019-05-01'));

        self::assertSame([
            'eldp_type' => '01',
            'employed_from' => '2019-05-01',
            'prepared_on' => '2025-12-31',
            'corrects' => null,
        ], $statement->payload['form']);
        self::assertSame([], $statement->sections()[0]['months_without_insurance']);
    }

    public function testTerminationStatementHasTypeTwoAndCustomPreparedOn(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['prepared_on'] = '2025-09-05';

        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->months(2025, 1, 8, employmentEnd: '2025-08-31'),
            $confirmation,
        );

        self::assertSame('02', $statement->payload['form']['eldp_type']);
        self::assertSame('2025-09-05', $statement->payload['form']['prepared_on']);
    }

    /**
     * ČSSZ odmítá evidenční list, jehož datum vyhotovení předchází údaji „Do"
     * (chyba 251). Aplikace ho nesmí sestavit.
     */
    public function testPreparedOnBeforePeriodEndIsRefused(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['prepared_on'] = '2025-08-25';

        try {
            (new EldpAnnualStatementBuilder())->build(
                self::SUPPLIER_ID,
                self::EMPLOYMENT_ID,
                2025,
                $this->months(2025, 1, 8, employmentEnd: '2025-08-31'),
                $confirmation,
            );
            self::fail('Datum vyhotovení před „Do" ČSSZ odmítá chybou 251.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_prepared_on_before_period_end', $exception->validationCode);
            self::assertStringContainsString('251', $exception->getMessage());
        }
    }

    /** Měsíc bez započitatelného příjmu se v tiskopisu vyznačí „X". */
    public function testMonthWithoutIncomeIsMarkedX(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[5] = $this->revision(2025, 6, absences: [[
            'id' => 9310,
            'absence_type' => 'unpaid_leave',
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-30',
        ]], baseMinor: 0);

        self::assertSame([6], $this->build($revisions)->sections()[0]['months_without_insurance']);
    }

    /**
     * ELDP12 údaj 37 (třináctý prostor „1-12"): celoroční rodičovská dovolená
     * nedává ani jeden den pojištění, a tiskopis proto nese X v prostoru „1-12"
     * místo dvanácti X u jednotlivých měsíců. Stejnopis ukáže „1-12".
     */
    public function testWholeYearWithoutInsuranceIsMarkedInTheThirteenthSpace(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 12; ++$month) {
            $periodStart = sprintf('2025-%02d-01', $month);
            $revisions[] = $this->revision(2025, $month, absences: [[
                'id' => 9400 + $month,
                'absence_type' => 'parental',
                'date_from' => $periodStart,
                'date_to' => (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d'),
            ]], employmentStart: '2019-05-01', baseMinor: 0);
        }

        $statement = $this->build($revisions);

        $section = $statement->sections()[0];
        self::assertCount(1, $statement->sections());
        self::assertSame(0, $section['insurance_days']);
        self::assertTrue($section['whole_year_without_insurance'] ?? false);
        $copy = EldpStatementCopyService::sections($statement->payload);
        self::assertTrue($copy[0]['whole_year_without_insurance']);
        $html = $this->copyHtml($copy);
        self::assertStringContainsString('<td>1-12</td>', $html);
        self::assertStringNotContainsString('1, 2, 3', $html);
    }

    /** Měsíce X jen v části roku třináctý prostor nevyplní. */
    public function testPartYearWithoutInsuranceKeepsIndividualMonths(): void
    {
        $revisions = $this->months(2025, 1, 12);
        $revisions[5] = $this->revision(2025, 6, absences: [[
            'id' => 9310,
            'absence_type' => 'unpaid_leave',
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-30',
        ]], baseMinor: 0);

        $statement = $this->build($revisions);

        self::assertArrayNotHasKey('whole_year_without_insurance', $statement->sections()[0]);
        self::assertStringContainsString('<td>6</td>', $this->copyHtml(EldpStatementCopyService::sections($statement->payload)));
    }

    /** Opravný list nese typ 5x podle opravovaného listu a odkaz na něj. */
    public function testCorrectiveStatementCarriesTypeAndReference(): void
    {
        $confirmation = $this->confirmation();
        $confirmation['corrects'] = [
            'statement_id' => 42,
            'eldp_type' => '02',
            'prepared_on' => '2025-09-01',
        ];

        $statement = (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            $this->months(2025, 1, 8, employmentEnd: '2025-08-31'),
            $confirmation,
        );

        self::assertSame('52', $statement->payload['form']['eldp_type']);
        self::assertSame(42, $statement->payload['form']['corrects']['statement_id']);
    }

    /**
     * Hláška u nepřípustného samostatného listu za 2026 jmenuje, PROČ výjimka
     * nedopadá — dřív to byl jen obecný výčet pravidel.
     */
    public function testNotApplicableReasonNamesTheConcreteCause(): void
    {
        $running = EldpDeadlinePolicy::standaloneStatementAllowed(2026, null, false);
        self::assertFalse($running['allowed']);
        self::assertStringContainsString('Za rok 2026', $running['reason']);
        self::assertStringContainsString('trvá', $running['reason']);
        self::assertStringContainsString('opravným měsíčním hlášením', $running['reason']);
        self::assertStringContainsString('nevyhotovuje ani nepředkládá', $running['reason']);

        $endedLater = EldpDeadlinePolicy::standaloneStatementAllowed(2026, '2026-06-30', false);
        self::assertStringContainsString('30. 6. 2026', $endedLater['reason']);
        self::assertStringContainsString('po 31. 3. 2026', $endedLater['reason']);

        $later = EldpDeadlinePolicy::standaloneStatementAllowed(2027, null, false);
        self::assertStringContainsString('Za rok 2027', $later['reason']);
    }

    /** @param list<array<string,mixed>> $sections */
    private function copyHtml(array $sections): string
    {
        $copies = (new \ReflectionClass(EldpStatementCopyService::class))->newInstanceWithoutConstructor();

        return $copies->html([
            'statement_id' => 1,
            'year' => 2025,
            'environment' => 'test',
            'eldp_type' => '01',
            'employed_from' => '2019-05-01',
            'prepared_on' => '2025-12-31',
            'period_from' => '2025-01-01',
            'period_to' => '2025-12-31',
            'sections' => $sections,
            'employer' => ['name' => 'Syntetická s.r.o.', 'identification_number' => '00000001', 'address' => 'Zkušební 1, 100 00 Testov'],
            'employee' => ['name' => 'Dana Testovací', 'birth_date' => '1991-02-03'],
            'renderer_version' => EldpStatementCopyService::VERSION,
            'manifest_sha256' => str_repeat('0', 64),
            'totals' => ['insurance_days' => 0, 'excluded_days_total' => 0, 'deducted_days_total' => 0, 'assessment_base_czk' => 0],
        ]);
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
            'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
            'requested_by_authority' => false,
            'note' => 'Syntetický evidenční list pro test.',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function months(
        int $year,
        int $from,
        int $to,
        ?string $employmentStart = null,
        ?string $employmentEnd = null,
    ): array {
        $revisions = [];
        for ($month = $from; $month <= $to; ++$month) {
            $revisions[] = $this->revision(
                $year,
                $month,
                employmentStart: $employmentStart,
                employmentEnd: $employmentEnd,
            );
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
        string $relationType = 'employment',
        string $activityCode = '1',
        ?string $detailCode = '1',
        string $participation = 'participates',
    ): array {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $start = $employmentStart ?? sprintf('%04d-01-01', $year);
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
                        'relation_type' => $relationType,
                        'start_date' => $start,
                        'actual_start_date' => $start,
                        'end_date' => $employmentEnd,
                    ],
                    'term' => [
                        'id' => 201,
                        'row_version' => 1,
                        'activity_code' => $activityCode,
                        'jmhz_relationship_detail_code' => $detailCode,
                    ],
                    'absences' => $absences,
                    'inputs' => [],
                ]],
            ]],
        ];
        $inputJson = CanonicalJson::encode($input);
        $socialKind = $relationType === 'employment' ? 'employment' : $relationType;
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
                            'kind' => $socialKind,
                            'participation' => [
                                'relationship_id' => 'employment:' . self::EMPLOYMENT_ID,
                                'status' => $participation,
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
