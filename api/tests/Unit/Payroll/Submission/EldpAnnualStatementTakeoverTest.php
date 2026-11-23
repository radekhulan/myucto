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
 * Rok přechodu z jiného mzdového programu v evidenčním listu.
 *
 * Všechna data jsou zjevně syntetická (firma 7, osoba 11, vztah 101, 10 000 Kč
 * měsíčně, identita v původním systému „SYN-11") a žádný test nesahá na síť
 * ani na databázi.
 */
final class EldpAnnualStatementTakeoverTest extends TestCase
{
    private const SUPPLIER_ID = 7;
    private const EMPLOYEE_ID = 11;
    private const EMPLOYMENT_ID = 101;
    private const YEAR = 2025;
    /** Dny v měsících roku 2025, aby převzatý měsíc nemusel nic dopočítávat. */
    private const DAYS = [1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
        7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31];

    /**
     * Jádro PAM-10: leden až červenec vedl jiný program, srpen až prosinec
     * MyÚčto. Dřív se takový rok nedal sestavit vůbec.
     */
    public function testYearStartedInAnotherPayrollSystemIsCompletedFromTakeoverMonths(): void
    {
        $statement = $this->build(
            $this->revisions(8, 12),
            $this->takeoverYear($this->takeoverMonths(1, 7)),
        );

        $sections = $statement->sections();
        self::assertCount(1, $sections);
        self::assertSame('1++', $sections[0]['code']);
        self::assertSame('2025-01-01', $sections[0]['valid_from']);
        self::assertSame('2025-12-31', $sections[0]['valid_to']);
        self::assertSame(365, $sections[0]['insurance_days']);
        self::assertSame(120_000, $sections[0]['assessment_base_czk']);
        self::assertSame(0, $sections[0]['excluded_days_total']);

        $xml = (new EldpXmlSerializer())->serialize($statement);
        self::assertStringContainsString('<pocetDnu>365</pocetDnu>', $xml);
        (new EldpXmlValidator())->validate($statement, $xml);
    }

    /** Společník s.r.o.: převzaté měsíce se nesmí zastavit na druhu vztahu a nesou kód S++. */
    public function testCompanyPartnerTakeoverMonthsBuildWithCorporateCode(): void
    {
        $months = [];
        for ($month = 1; $month <= 7; ++$month) {
            $months[] = $this->takeoverMonth($month, ['relation_type' => 'partner_dependent', 'activity_code' => 'S']);
        }
        $revisions = array_map(fn (int $month): array => $this->partnerRevision($month), range(8, 12));

        $statement = $this->build($revisions, $this->takeoverYear($months));

        self::assertSame('S++', $statement->sections()[0]['code']);
    }

    /** @return array<string,mixed> */
    private function partnerRevision(int $month): array
    {
        $revision = $this->revision($month);
        $input = json_decode((string) $revision['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $input['people'][0]['employments'][0]['employment']['relation_type'] = 'partner_dependent';
        $input['people'][0]['employments'][0]['term']['activity_code'] = 'S';
        $inputJson = CanonicalJson::encode($input);
        $result = json_decode((string) $revision['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $result['source_snapshot_hash'] = hash('sha256', $inputJson);
        $result['people'][0]['statutory']['social_insurance']['relationships'][0]['kind'] = 'corporate_body';
        $resultJson = CanonicalJson::encode($result);

        return [
            'input_snapshot_json' => $inputJson,
            'input_snapshot_hash' => hash('sha256', $inputJson),
            'result_snapshot_json' => $resultJson,
            'result_snapshot_hash' => hash('sha256', $resultJson),
        ] + $revision;
    }

    /** Převzatá část musí být poznat v podkladu i v seznamu zdrojů. */
    public function testTakeoverPartIsVisibleInTheStatementAndItsSources(): void
    {
        $statement = $this->build(
            $this->revisions(8, 12),
            $this->takeoverYear($this->takeoverMonths(1, 7)),
        );

        $lines = $statement->payload['monthly_lines'];
        self::assertSame('takeover', $lines[0]['source']);
        self::assertSame('pamica', $lines[0]['takeover_source']);
        self::assertSame('revision', $lines[7]['source']);
        self::assertArrayNotHasKey('takeover_source', $lines[7]);

        // Zdroje se nemíchají: revize zůstávají v `source_revisions`, převzaté
        // měsíce mají vlastní seznam s otiskem řádku.
        self::assertCount(5, $statement->payload['source_revisions']);
        self::assertSame(
            '2025-08-01',
            $statement->payload['source_revisions'][0]['period_start'],
        );
        $takeovers = $statement->payload['source_takeovers'];
        self::assertCount(7, $takeovers);
        self::assertSame('2025-01-01', $takeovers[0]['period_start']);
        self::assertSame('pamica', $takeovers[0]['source']);
        self::assertSame('SYN-11', $takeovers[0]['external_person_ref']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $takeovers[0]['row_sha256']);
        self::assertSame(
            hash('sha256', CanonicalJson::encode(
                $this->takeoverMonth(1)->toArray(),
            )),
            $takeovers[0]['row_sha256'],
        );
    }

    /**
     * Měsíc z obou stran vyhrává schválená revize, a rozpor se zapíše.
     *
     * Převzatý srpen tady nese trojnásobný vyměřovací základ; kdyby se sečetl
     * nebo dokonce vyhrál, bylo by to v listu vidět jako jiné číslo.
     */
    public function testMonthPresentOnBothSidesIsTakenFromTheApprovedRevision(): void
    {
        $months = $this->takeoverMonths(1, 7);
        $months[] = $this->takeoverMonth(8, ['social_base_minor' => 3_000_000]);

        $statement = $this->build(
            $this->revisions(8, 12),
            $this->takeoverYear($months),
        );

        self::assertSame(120_000, $statement->sections()[0]['assessment_base_czk']);
        self::assertSame(
            ['2025-08-01'],
            $statement->payload['takeover_overridden_periods'],
        );
        self::assertCount(7, $statement->payload['source_takeovers']);
        self::assertSame('revision', $statement->payload['monthly_lines'][7]['source']);
    }

    /**
     * Měsíc, který MyÚčto počítá, ale nemá schválenou revizi, se převzatými
     * daty nepřepíše — jinak by se schoval rozpracovaný běh s jinými čísly.
     */
    public function testCalculatedMonthWithoutApprovedRevisionIsNotSubstituted(): void
    {
        $revisions = $this->revisions(8, 12);
        unset($revisions[2]);

        try {
            $this->build(
                array_values($revisions),
                $this->takeoverYear(
                    [...$this->takeoverMonths(1, 7), $this->takeoverMonth(10)],
                ),
            );
            self::fail('Neschválená revize se nesmí nahradit převzatým měsícem.');
        } catch (EldpValidationException $exception) {
            $codes = array_column($exception->blockers, 'code');
            self::assertContains('eldp_takeover_month_not_substitutable', $codes);
            self::assertStringContainsString('říjen 2025', $exception->getMessage());
            self::assertStringContainsString('Revizi měsíce schvalte', $exception->getMessage());
        }
    }

    /** Dny účasti se nedopočítávají z ničeho jiného. */
    public function testTakeoverMonthWithoutInsuranceDaysStaysBlocked(): void
    {
        $months = $this->takeoverMonths(1, 7);
        $months[2] = $this->takeoverMonth(3, ['insurance_days' => 0]);

        try {
            $this->build($this->revisions(8, 12), $this->takeoverYear($months));
            self::fail('Převzatý měsíc bez dnů účasti musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            self::assertSame('eldp_source_incomplete', $exception->validationCode);
            $blocker = $this->blocker($exception, 'eldp_takeover_insurance_days_missing');
            self::assertStringContainsString('březen 2025', $blocker['message']);
            self::assertStringContainsString('Kontrola převodu mezd', $blocker['message']);
            self::assertSame('2025-03-01', $blocker['detail']['period_start']);
            self::assertSame(self::EMPLOYMENT_ID, $blocker['detail']['employment_id']);
            self::assertSame('pamica', $blocker['detail']['takeover_source']);
        }
    }

    /** Vyloučené doby bez rozpadu na složky § 16 odst. 4 se nevymýšlejí. */
    public function testTakeoverMonthWithExcludedDaysWithoutBreakdownStaysBlocked(): void
    {
        $months = $this->takeoverMonths(1, 7);
        $months[4] = $this->takeoverMonth(5, ['excluded_days' => 14]);

        try {
            $this->build($this->revisions(8, 12), $this->takeoverYear($months));
            self::fail('Vyloučené doby bez rozpadu musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker(
                $exception,
                'eldp_takeover_excluded_days_breakdown_missing',
            );
            self::assertStringContainsString('květen 2025', $blocker['message']);
            self::assertStringContainsString('§ 16 odst. 4', $blocker['message']);
            self::assertSame(14, $blocker['detail']['excluded_days']);
        }
    }

    /** Druh činnosti ČSSZ převzatá data mít musí; kód sekce se neodvozuje. */
    public function testTakeoverMonthWithoutActivityCodeStaysBlocked(): void
    {
        $months = $this->takeoverMonths(1, 7);
        $months[0] = $this->takeoverMonth(1, ['activity_code' => null]);

        try {
            $this->build($this->revisions(8, 12), $this->takeoverYear($months));
            self::fail('Převzatý měsíc bez druhu činnosti musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_takeover_activity_missing');
            self::assertStringContainsString('leden 2025', $blocker['message']);
            self::assertNull($blocker['detail']['activity_code']);
        }
    }

    /** Trvání vztahu drží zmrazená revize; rozpor se nesjednocuje. */
    public function testTakeoverMonthWithOtherEmploymentDatesStaysBlocked(): void
    {
        $months = $this->takeoverMonths(1, 7);
        $months[1] = $this->takeoverMonth(2, ['relationship_start_date' => '2024-06-01']);

        try {
            $this->build($this->revisions(8, 12), $this->takeoverYear($months));
            self::fail('Jiné trvání vztahu v převzatých datech musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker(
                $exception,
                'eldp_takeover_employment_dates_inconsistent',
            );
            self::assertStringContainsString('nesourodé podklady', $blocker['message']);
            self::assertSame('2024-06-01', $blocker['detail']['takeover_start_date']);
        }
    }

    /** Dva převzaté řádky za jeden měsíc nejsou podklad, ale nejednoznačnost. */
    public function testTwoTakeoverRowsForOneMonthStayBlocked(): void
    {
        $months = [...$this->takeoverMonths(1, 7), $this->takeoverMonth(4)];

        try {
            $this->build($this->revisions(8, 12), $this->takeoverYear($months));
            self::fail('Dva převzaté řádky za měsíc musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_takeover_month_ambiguous');
            self::assertStringContainsString('duben 2025', $blocker['message']);
        }
    }

    /** Měsíc bez podkladu z obou stran pojmenuje obě cesty k nápravě. */
    public function testMonthWithoutAnySourceNamesBothWaysToFixIt(): void
    {
        try {
            $this->build(
                $this->revisions(8, 12),
                $this->takeoverYear($this->takeoverMonths(1, 6)),
            );
            self::fail('Měsíc bez podkladu musí zůstat blokátorem.');
        } catch (EldpValidationException $exception) {
            $blocker = $this->blocker($exception, 'eldp_month_source_missing');
            self::assertStringContainsString('červenec 2025', $blocker['message']);
            self::assertStringContainsString('ani převzatý mzdový měsíc', $blocker['message']);
            self::assertStringContainsString('Kontrola převodu mezd', $blocker['message']);
        }
    }

    /**
     * REGRESE: firma, která vede mzdy v MyÚčtu celý rok, se nesmí chovat ani
     * o kousek jinak. Evidenční list je zmrazený otiskem, takže „skoro stejně"
     * je změna dokumentu.
     */
    public function testCompanyWithoutTakeoverDataBuildsAByteIdenticalStatement(): void
    {
        $reference = $this->build($this->revisions(1, 12), null);
        $withEmptyTakeover = $this->build(
            $this->revisions(1, 12),
            $this->takeoverYear([], []),
        );

        self::assertSame(
            $reference->canonicalJson(),
            $withEmptyTakeover->canonicalJson(),
        );
        self::assertArrayNotHasKey('source_takeovers', $reference->payload);
        self::assertArrayNotHasKey('takeover_overridden_periods', $reference->payload);
        self::assertArrayNotHasKey('source', $reference->payload['monthly_lines'][0]);
        self::assertSame(
            ['period_start', 'insurance_from', 'insurance_to', 'insurance_days',
                'assessment_base_czk', 'code', 'excluded_days', 'excluded_days_total',
                'excluded_days_provenance'],
            array_keys($reference->payload['monthly_lines'][0]),
        );
    }

    /** REGRESE: znění blokátoru bez převzatých dat zůstává doslova stejné. */
    public function testMissingMonthMessageIsUnchangedWithoutTakeoverData(): void
    {
        $revisions = $this->revisions(1, 12);
        unset($revisions[2]);
        $expected = 'Chybí schválená mzdová revize za březen 2025 — '
            . 'bez ní nelze doložit dobu pojištění ani vyměřovací základ.';

        foreach ([null, $this->takeoverYear([], [])] as $takeover) {
            try {
                $this->build(array_values($revisions), $takeover);
                self::fail('Chybějící měsíc musel evidenční list zablokovat.');
            } catch (EldpValidationException $exception) {
                self::assertSame(
                    $expected,
                    $this->blocker($exception, 'eldp_month_source_missing')['message'],
                );
            }
        }
    }

    /** Převzatá data cizího pracovního vztahu se sem nesmí připlést. */
    public function testTakeoverOfAnotherEmploymentDoesNotChangeAnything(): void
    {
        $foreign = $this->takeoverMonth(1, [
            'employment_id' => self::EMPLOYMENT_ID + 1,
            'employee_id' => self::EMPLOYEE_ID + 1,
        ]);

        self::assertSame(
            $this->build($this->revisions(1, 12), null)->canonicalJson(),
            $this->build($this->revisions(1, 12), $this->takeoverYear([$foreign], []))
                ->canonicalJson(),
        );
    }

    /** @return array{code:string,message:string,detail:array<string,mixed>} */
    private function blocker(EldpValidationException $exception, string $code): array
    {
        $matched = array_values(array_filter(
            $exception->blockers,
            static fn (array $blocker): bool => $blocker['code'] === $code,
        ));
        self::assertCount(1, $matched, "Blokátor {$code} nepřišel právě jednou.");

        /** @var array{code:string,message:string,detail:array<string,mixed>} $first */
        $first = $matched[0];

        return $first;
    }

    /** @param list<array<string,mixed>> $revisions */
    private function build(
        array $revisions,
        ?PayrollTakeoverYear $takeover,
    ): EldpAnnualStatement {
        return (new EldpAnnualStatementBuilder())->build(
            self::SUPPLIER_ID,
            self::EMPLOYMENT_ID,
            self::YEAR,
            $revisions,
            [
                'excluded_days_confirmed' => true,
                'deducted_days_none' => true,
                'pension_status' => ['pension_age_reached_on' => null, 'early_pension_from' => null, 'full_pension_paid_from' => null, 'foreign_insurance' => false],
                'requested_by_authority' => false,
                'note' => 'Syntetický evidenční list pro test, žádná reálná data.',
            ],
            $takeover,
        );
    }

    /**
     * @param list<PayrollTakeoverMonth> $months
     * @param list<string>|null $calculated `null` = měsíce se schválenými revizemi 8–12
     */
    private function takeoverYear(array $months, ?array $calculated = null): PayrollTakeoverYear
    {
        return new PayrollTakeoverYear(
            self::SUPPLIER_ID,
            self::YEAR,
            '2025-08',
            $months,
            $calculated ?? ['2025-08', '2025-09', '2025-10', '2025-11', '2025-12'],
            self::EMPLOYEE_ID,
            self::EMPLOYMENT_ID,
        );
    }

    /** @return list<PayrollTakeoverMonth> */
    private function takeoverMonths(int $from, int $to): array
    {
        $months = [];
        for ($month = $from; $month <= $to; ++$month) {
            $months[] = $this->takeoverMonth($month);
        }

        return $months;
    }

    /** @param array<string,mixed> $overrides */
    private function takeoverMonth(int $month, array $overrides = []): PayrollTakeoverMonth
    {
        return PayrollTakeoverMonth::fromRow([
            'period' => sprintf('%04d-%02d', self::YEAR, $month),
            'source' => 'pamica',
            'external_person_ref' => 'SYN-11',
            'external_relationship_ref' => 'SYN-11/1',
            'employee_id' => self::EMPLOYEE_ID,
            'employment_id' => self::EMPLOYMENT_ID,
            'relationship_start_date' => sprintf('%04d-01-01', self::YEAR),
            'relationship_end_date' => null,
            'relation_type' => 'employment',
            'activity_code' => '1',
            'pension_participation' => 1,
            'insurance_days' => self::DAYS[$month],
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
            'payout_date' => sprintf('%04d-%02d-15', self::YEAR, $month),
            'import_reference' => 'synteticky-import-1',
            ...$overrides,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function revisions(int $from, int $to): array
    {
        $revisions = [];
        for ($month = $from; $month <= $to; ++$month) {
            $revisions[] = $this->revision($month);
        }

        return $revisions;
    }

    /** @return array<string,mixed> */
    private function revision(int $month): array
    {
        $periodStart = sprintf('%04d-%02d-01', self::YEAR, $month);
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
                        'start_date' => sprintf('%04d-01-01', self::YEAR),
                        'actual_start_date' => sprintf('%04d-01-01', self::YEAR),
                        'end_date' => null,
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
