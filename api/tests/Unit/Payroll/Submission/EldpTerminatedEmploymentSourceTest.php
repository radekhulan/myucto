<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatement;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpAnnualStatementBuilder;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpValidationException;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * Evidenční list vztahu, který skončil dřív, než ho zachytila pozdější
 * zmrazená revize, nebo celý leží v převzatých mzdách.
 *
 * Revize jsou za celou firmu: měsíc po skončení (a před nástupem) vztah
 * neobsahuje, protože do snapshotu běhu patří jen vztahy, které v měsíci
 * trvají. Dřív každý takový měsíc zablokoval celý list.
 *
 * Data jsou syntetická (firma 7, osoba 11, vztah 101, kolega 12/102,
 * 10 000 Kč měsíčně, identita v původním systému „SYN-11").
 */
final class EldpTerminatedEmploymentSourceTest extends TestCase
{
    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;
    private const OTHER_EMPLOYEE_ID = 12;
    private const OTHER_EMPLOYMENT_ID = 102;

    /**
     * Vztah skončil v únoru, MyÚčto počítá až od září a zářijová revize ho
     * nezná. Leden až únor leží jen v převzatých mzdách, datum skončení nese
     * převzatý únor. Převzatý březen bez vyměřovacího základu do listu nepatří.
     */
    public function testEmploymentEndedInTakeoverPeriodIsBuiltFromTakeoverMonths(): void
    {
        $statement = $this->build(
            2026,
            [$this->revision(2026, 9, null)],
            $this->takeoverYear(2026, '2026-09', [
                $this->takeoverMonth(2026, 1, ['insurance_days' => 31]),
                $this->takeoverMonth(2026, 2, [
                    'relationship_end_date' => '2026-02-10',
                    'insurance_days' => 10,
                ]),
                $this->takeoverMonth(2026, 3, [
                    'relationship_end_date' => '2026-02-10',
                    'insurance_days' => 0,
                    'social_base_minor' => 0,
                ]),
            ], ['2026-09']),
        );

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('1++', $sections[0]['code']);
        self::assertSame('2026-01-01', $sections[0]['valid_from']);
        self::assertSame('2026-02-10', $sections[0]['valid_to']);
        self::assertSame(41, $sections[0]['insurance_days']);
        self::assertSame(20_000, $sections[0]['assessment_base_czk']);
        self::assertSame(0, $sections[0]['excluded_days_total']);
        self::assertSame('termination', $statement->payload['scope']['statement_kind']);
        self::assertSame([], $statement->payload['source_revisions']);
        self::assertCount(2, $statement->payload['source_takeovers']);
        self::assertSame('takeover', $statement->payload['employment_dates_source']);

        $xml = (new EldpXmlSerializer())->serialize($statement);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /** Bez jediné revize v roce stačí převzaté měsíce s doloženým skončením. */
    public function testEmploymentWithoutAnyRevisionInTheYearIsBuiltFromTakeoverMonths(): void
    {
        $statement = $this->build(
            2026,
            [],
            $this->takeoverYear(2026, '2026-09', [
                $this->takeoverMonth(2026, 1, [
                    'relationship_end_date' => '2026-01-20',
                    'insurance_days' => 20,
                ]),
            ], []),
        );

        self::assertSame(20, $statement->sections()[0]['insurance_days']);
        self::assertSame('2026-01-20', $statement->sections()[0]['valid_to']);
    }

    /**
     * Vztah skončil v březnu a firma schválila revize až do prosince. Revize
     * od dubna vztah neobsahují a list se přesto sestaví ze tří měsíců.
     */
    public function testEmploymentEndedBeforeLaterApprovedRevisionsIsBuilt(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 12; ++$month) {
            $revisions[] = $this->revision(
                2025,
                $month,
                $month <= 3 ? ['2025-01-01', '2025-03-15'] : null,
            );
        }

        $statement = $this->build(2025, $revisions, null);

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-03-15', $sections[0]['valid_to']);
        self::assertSame(31 + 28 + 15, $sections[0]['insurance_days']);
        self::assertSame(30_000, $sections[0]['assessment_base_czk']);
        self::assertCount(3, $statement->payload['source_revisions']);
        self::assertArrayNotHasKey('employment_dates_source', $statement->payload);
    }

    /** Totéž před nástupem: revize leden až květen vztah ještě nemají. */
    public function testEmploymentStartedMidYearAfterEarlierRevisionsIsBuilt(): void
    {
        $revisions = [];
        for ($month = 1; $month <= 12; ++$month) {
            $revisions[] = $this->revision(
                2025,
                $month,
                $month >= 6 ? ['2025-06-01', null] : null,
            );
        }

        $statement = $this->build(2025, $revisions, null);

        self::assertSame('2025-06-01', $statement->sections()[0]['valid_from']);
        self::assertSame(214, $statement->sections()[0]['insurance_days']);
        self::assertCount(7, $statement->payload['source_revisions']);
    }

    /** Revize UVNITŘ trvání vztahu, která ho nezná, blokuje dál. */
    public function testRevisionWithinEmploymentWithoutTheEmploymentStaysBlocked(): void
    {
        try {
            $this->build(2025, [
                $this->revision(2025, 1, ['2025-01-01', '2025-03-15']),
                $this->revision(2025, 2, null),
                $this->revision(2025, 3, ['2025-01-01', '2025-03-15']),
            ], null);
            self::fail('Revize uvnitř trvání vztahu bez vztahu musí blokovat.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_employment_not_in_revision');
            self::assertSame('Pracovní vztah není ve zmrazené revizi za únor 2025.', $blocker['message']);
        }
    }

    /**
     * Prázdné datum skončení v převzatých datech znamená „trvá" i „původní
     * systém ho nevydal". Bez revize, která trvání doloží, se list nesestaví.
     */
    public function testTakeoverOnlyEmploymentWithoutEndDateStaysBlocked(): void
    {
        try {
            $this->build(
                2026,
                [$this->revision(2026, 9, null)],
                $this->takeoverYear(2026, '2026-09', [
                    $this->takeoverMonth(2026, 1, ['insurance_days' => 31]),
                ], ['2026-09']),
            );
            self::fail('Převzatý vztah bez data skončení musí blokovat.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_takeover_employment_end_unknown');
            self::assertStringContainsString('Kontrola převodu mezd', $blocker['message']);
            self::assertSame(self::EMPLOYMENT_ID, $blocker['detail']['employment_id']);
        }
    }

    /**
     * Původní program skončení vztahu dodatečně opravil (23. 1. → 31. 1.):
     * lednový převzatý řádek nese staré datum a 23 dnů, pozdější řádek nové
     * datum. Platí poslední verze (týž vzor jako původní a opravný evidenční
     * list s rozdílným koncem vztahu v reálném převodu): list 1. 1.–31. 1.,
     * 31 dnů, základ ledna. Dřív rozporná data list zablokovala.
     */
    public function testTakeoverOnlyEmploymentWithLaterCorrectedEndDateTakesTheLatestRow(): void
    {
        $statement = $this->build(
            2026,
            [],
            $this->takeoverYear(2026, '2026-09', [
                $this->takeoverMonth(2026, 1, [
                    'relationship_end_date' => '2026-01-23',
                    'insurance_days' => 23,
                ]),
                $this->takeoverMonth(2026, 3, [
                    'relationship_end_date' => '2026-01-31',
                    'insurance_days' => 0,
                    'social_base_minor' => 0,
                ]),
            ], []),
        );

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('2026-01-01', $sections[0]['valid_from']);
        self::assertSame('2026-01-31', $sections[0]['valid_to']);
        self::assertSame(31, $sections[0]['insurance_days']);
        self::assertSame(10_000, $sections[0]['assessment_base_czk']);
        self::assertTrue($statement->payload['source_takeovers'][0]['dates_superseded_by_later_row']);
        self::assertSame('02', $statement->payload['form']['eldp_type']);
    }

    /** Pozdější řádek bez data skončení opravou není: „trvá" i „nevydáno". */
    public function testTakeoverOnlyEmploymentWithLatestRowWithoutEndStaysBlocked(): void
    {
        try {
            $this->build(
                2026,
                [],
                $this->takeoverYear(2026, '2026-09', [
                    $this->takeoverMonth(2026, 1, [
                        'relationship_end_date' => '2026-01-23',
                        'insurance_days' => 23,
                    ]),
                    $this->takeoverMonth(2026, 2, [
                        'relationship_end_date' => '2026-02-20',
                        'insurance_days' => 20,
                    ]),
                    $this->takeoverMonth(2026, 3, [
                        'relationship_end_date' => null,
                        'relationship_start_date' => '2025-01-01',
                        'insurance_days' => 31,
                    ]),
                ], []),
            );
            self::fail('Rozporná data bez doloženého posledního skončení musí blokovat.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_takeover_employment_dates_ambiguous');
            self::assertSame(['2026-01-23', '2026-02-20'], $blocker['detail']['takeover_end_dates']);
        }
    }

    /**
     * Převzatý vyměřovací základ zúčtovaný po skončení vztahu by v listu
     * tiše chyběl. Řádek „P+" z převzatých dat modul nedoloží, proto blokuje.
     */
    public function testTakeoverIncomeAfterTerminationStaysBlocked(): void
    {
        try {
            $this->build(
                2026,
                [],
                $this->takeoverYear(2026, '2026-09', [
                    $this->takeoverMonth(2026, 1, [
                        'relationship_end_date' => '2026-01-20',
                        'insurance_days' => 20,
                    ]),
                    $this->takeoverMonth(2026, 2, [
                        'relationship_end_date' => '2026-01-20',
                        'insurance_days' => 0,
                        'social_base_minor' => 500_000,
                    ]),
                ], []),
            );
            self::fail('Převzatý příjem po skončení vztahu musí blokovat.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker(
                $exception,
                'eldp_takeover_post_termination_income_unsupported',
            );
            self::assertStringContainsString('únor 2026', $blocker['message']);
            self::assertSame('2026-02-01', $blocker['detail']['period_start']);
        }
    }

    /** REGRESE: bez revize i bez převzatých dat zůstává blokátor doslova. */
    public function testNoRevisionAndNoTakeoverKeepsTheOriginalBlocker(): void
    {
        foreach ([null, $this->takeoverYear(2025, null, [], [])] as $takeover) {
            try {
                $this->build(2025, [], $takeover);
                self::fail('Bez podkladu se list sestavit nesmí.');
            } catch (EldpValidationException $exception) {
                self::assertSame(
                    'Za rok 2025 není k pracovnímu vztahu žádná schválená mzdová revize.',
                    $this->blocker($exception, 'eldp_no_source_revision')['message'],
                );
            }
        }
    }

    /** @return array{code:string,message:string,detail:array<string,mixed>} */
    private function blocker(EldpValidationException $exception, string $code): array
    {
        $matched = array_values(array_filter(
            $exception->blockers,
            static fn (array $blocker): bool => $blocker['code'] === $code,
        ));
        self::assertCount(
            1,
            $matched,
            "Blokátor {$code} nepřišel právě jednou: "
                . implode(', ', array_column($exception->blockers, 'code')),
        );

        /** @var array{code:string,message:string,detail:array<string,mixed>} $first */
        $first = $matched[0];

        return $first;
    }

    /** @param list<array<string,mixed>> $revisions */
    private function build(
        int $year,
        array $revisions,
        ?PayrollTakeoverYear $takeover,
    ): EldpAnnualStatement {
        return (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            $year,
            $revisions,
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => [
                    'pension_age_reached_on' => null,
                    'early_pension_from' => null,
                    'full_pension_paid_from' => null,
                    'foreign_insurance' => false,
                ],
                'requested_by_authority' => false,
                'note' => 'Syntetický evidenční list pro test, žádná reálná data.',
            ],
            $takeover,
        );
    }

    /**
     * Rok vedl celý původní program a skončení vztahu nevydal. Vztah ale
     * doložitelně běží i v lednu následujícího roku, takže za rok trval celý:
     * roční list 1. 1.–31. 12. (§ 38 odst. 3 zákona č. 582/1991 Sb. ve znění
     * do 31. 12. 2025). Dřív blokoval `eldp_takeover_employment_end_unknown`.
     */
    public function testContinuingTakeoverEmploymentWithNextYearEvidenceIsAnnualStatement(): void
    {
        foreach (['takeover', 'calculated'] as $source) {
            $statement = $this->build(
                2025,
                [],
                $this->continuingYear(2025, [
                    'period' => '2026-01',
                    'source' => $source,
                    'relationship_start_date' => '2024-01-01',
                    'relationship_end_date' => null,
                ]),
            );

            $sections = $statement->sections();
            self::assertCount(1, $sections);
            self::assertSame('1++', $sections[0]['code']);
            self::assertSame('2025-01-01', $sections[0]['valid_from']);
            self::assertSame('2025-12-31', $sections[0]['valid_to']);
            self::assertSame(365, $sections[0]['insurance_days']);
            self::assertSame(120_000, $sections[0]['assessment_base_czk']);
            self::assertSame('01', $statement->payload['form']['eldp_type']);
            self::assertSame('takeover', $statement->payload['employment_dates_source']);
            self::assertSame(
                [['employment_id' => self::EMPLOYMENT_ID, 'period' => '2026-01', 'source' => $source]],
                $statement->payload['employment_continuation_evidence'],
            );
            $xml = (new EldpXmlSerializer())->serialize($statement);
            (new EldpXmlValidator())->validate($statement, $xml);
        }
    }

    /** Totéž trvání skládá potvrzení o době pojištění (§ 42), týž sestavovač. */
    public function testContinuingTakeoverEmploymentFeedsInsuranceCertificate(): void
    {
        $periods = (new EldpAnnualStatementBuilder())->insurancePeriods(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            2025,
            [],
            [
                'pension_age_reached_on' => null,
                'early_pension_from' => null,
                'full_pension_paid_from' => null,
                'foreign_insurance' => false,
            ],
            $this->continuingYear(2025, [
                'period' => '2026-03',
                'source' => 'takeover',
                'relationship_start_date' => null,
                'relationship_end_date' => '2026-06-30',
            ]),
        );

        self::assertSame('2025-01-01', $periods['periods'][0]['from']);
        self::assertSame('2025-12-31', $periods['periods'][0]['to']);
        self::assertSame(365, $periods['insurance_days']);
    }

    /**
     * Doklad o trvání, který neodpovídá vztahu (jiný nástup, skončení do
     * 31. 12., nebo leží ve vykazovaném roce), trvání nedoloží: blokace zůstává.
     */
    public function testContinuationEvidenceThatDoesNotProveDurationStaysBlocked(): void
    {
        $cases = [
            'jiný nástup' => ['period' => '2026-01', 'source' => 'takeover', 'relationship_start_date' => '2025-06-01', 'relationship_end_date' => null],
            'skončení do konce roku' => ['period' => '2026-01', 'source' => 'calculated', 'relationship_start_date' => '2024-01-01', 'relationship_end_date' => '2025-12-31'],
            'měsíc téhož roku' => ['period' => '2025-12', 'source' => 'takeover', 'relationship_start_date' => '2024-01-01', 'relationship_end_date' => null],
        ];
        foreach ($cases as $case => $evidence) {
            try {
                $this->build(2025, [], $this->continuingYear(2025, $evidence));
                self::fail("Doklad „{$case}\" nesmí trvání doložit.");
            } catch (EldpValidationException $exception) {
                $blocker = $this->blocker($exception, 'eldp_takeover_employment_end_unknown');
                self::assertStringContainsString('31. 12.', $blocker['message'], $case);
            }
        }
    }

    /**
     * Převzaté měsíce 1–12 roku bez data skončení, nástup 1. 1. 2024,
     * plná doba pojištění a 10 000 Kč měsíčně.
     *
     * @param array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string} $evidence
     */
    private function continuingYear(int $year, array $evidence): PayrollTakeoverYear
    {
        $months = [];
        for ($month = 1; $month <= 12; ++$month) {
            $months[] = $this->takeoverMonth($year, $month, [
                'relationship_start_date' => '2024-01-01',
                'insurance_days' => (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'),
            ]);
        }

        return $this->takeoverYear($year, sprintf('%04d-01', $year + 1), $months, [], [self::EMPLOYMENT_ID => $evidence]);
    }

    /**
     * @param list<PayrollTakeoverMonth> $months
     * @param list<string> $calculated
     * @param array<int,array{period:string,source:string,relationship_start_date:?string,relationship_end_date:?string}> $continuations
     */
    private function takeoverYear(
        int $year,
        ?string $startPeriod,
        array $months,
        array $calculated,
        array $continuations = [],
    ): PayrollTakeoverYear {
        return new PayrollTakeoverYear(
            self::SUPPLIER_ID,
            $year,
            $startPeriod,
            $months,
            $calculated,
            self::EMPLOYEE_ID,
            self::EMPLOYMENT_ID,
            continuations: $continuations,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function takeoverMonth(int $year, int $month, array $overrides = []): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period' => sprintf('%04d-%02d', $year, $month),
            'source' => 'pamica',
            'external_person_ref' => 'SYN-11',
            'external_relationship_ref' => 'SYN-11/1',
            'employee_id' => self::EMPLOYEE_ID,
            'employment_id' => self::EMPLOYMENT_ID,
            'relationship_start_date' => '2025-03-01',
            'relationship_end_date' => null,
            'relation_type' => 'employment',
            'activity_code' => '1',
            'pension_participation' => 1,
            'insurance_days' => 0,
            'excluded_days' => 0,
            'worked_days_hundredths' => 2_000,
            'worked_minutes' => 9_600,
            'gross_minor' => 1_000_000,
            'net_minor' => 800_000,
            'deductions_minor' => 0,
            'net_payable_minor' => 800_000,
            'social_base_minor' => 1_000_000,
            'health_base_minor' => 1_000_000,
            'employee_social_minor' => 71_000,
            'employee_health_minor' => 45_000,
            'employer_social_minor' => 248_000,
            'employer_health_minor' => 90_000,
            'advance_tax_minor' => 150_000,
            'withholding_tax_minor' => 0,
            'tax_bonus_minor' => 0,
            'payout_date' => sprintf('%04d-%02d-15', $year, $month),
            'import_reference' => 'synteticky-import-1',
            ...$overrides,
        ]);
    }

    /**
     * Schválená revize firmy za měsíc. `$dates` = [nástup, skončení] vztahu
     * 101; `null` = revize vztah 101 neobsahuje, jen kolegu 102.
     *
     * @param array{0:string,1:?string}|null $dates
     * @return array<string,mixed>
     */
    private function revision(int $year, int $month, ?array $dates): array
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        [$employeeId, $employmentId, $start, $end] = $dates === null
            ? [self::OTHER_EMPLOYEE_ID, self::OTHER_EMPLOYMENT_ID, '2024-01-01', null]
            : [self::EMPLOYEE_ID, self::EMPLOYMENT_ID, $dates[0], $dates[1]];
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => self::SUPPLIER_ID,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => $employeeId],
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'relation_type' => 'employment',
                        'start_date' => $start,
                        'actual_start_date' => $start,
                        'end_date' => $end,
                    ],
                    'term' => [
                        'id' => 201,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
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
                'employee_id' => $employeeId,
                'employments' => [[
                    'employment_id' => $employmentId,
                    'totals' => [],
                ]],
                'statutory' => [
                    'social_insurance' => [
                        'status' => 'calculated',
                        'relationships' => [[
                            'relationship_id' => 'employment:' . $employmentId,
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => 'employment:' . $employmentId,
                                'status' => 'participates',
                                'reason_codes' => [],
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
