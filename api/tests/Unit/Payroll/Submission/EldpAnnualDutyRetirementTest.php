<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException;
use PHPUnit\Framework\TestCase;

/**
 * Roční ELDP workflow zrušeno — výpočet dob NE.
 *
 * Test hlídá obě strany jedné hranice, protože splést si je stojí buď
 * vymyšlenou povinnost, nebo rozbité měsíční hlášení:
 *
 * 1. **Roční povinnost zaměstnavatele od roku 2026 neexistuje.** Údaje pro
 *    důchodové pojištění se sdělují jednotným měsíčním hlášením a evidenční
 *    list z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona č. 582/1991 Sb. ve
 *    znění zák. č. 360/2025 Sb.). Řádná roční lhůta 30. dubna se proto pro
 *    takový rok nesmí vůbec spočítat.
 * 2. **Tiskopis zrušen nebyl** a úzká ruční cesta zůstává: období před rokem
 *    2026, zaměstnání skončená před 1. 4. 2026 a výzva ČSSZ/ÚSSZ podle
 *    § 38a odst. 2 a 3.
 * 3. **Výpočet dob a vyloučených dob se používá KAŽDÝ MĚSÍC.** Vyloučené doby
 *    z `EldpExcludedPeriodDeriver` a ELDP sekce z `JmhzEldpEvidenceBuilder`
 *    tečou do měsíčního hlášení (atributy 10240 kód, 10241/10242 platnost,
 *    10354/10355 pojištění od/do, 10356 počet dnů, 10245 vyměřovací základ,
 *    10357 vyloučené doby a jejich složky). Smazat je s ročním workflow by
 *    znamenalo vzít ČSSZ podklad, ze kterého evidenční list sestavuje.
 */
final class EldpAnnualDutyRetirementTest extends TestCase
{
    public function testAnnualEmployerDeadlineDoesNotExistFromTwentyTwentySix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nevyhotovuje');
        (new EldpDeadlinePolicy())->forYear(EldpDeadlinePolicy::LAST_ANNUAL_YEAR + 1);
    }

    public function testAnnualDeadlineStaysForTheYearsItLegallyExisted(): void
    {
        $window = (new EldpDeadlinePolicy())
            ->forYear(EldpDeadlinePolicy::LAST_ANNUAL_YEAR);

        self::assertSame('annual', $window->statementKind);
        self::assertSame(EldpDeadlinePolicy::ANNUAL_RULESET, $window->rulesetId);
        self::assertSame('2026-04-30', $window->dueOn);
    }

    public function testStandaloneStatementIsRefusedAsRoutineFromTwentyTwentySix(): void
    {
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            2026,
            null,
            false,
        );

        self::assertFalse($eligibility['allowed']);
        self::assertFalse($eligibility['routine']);
        self::assertSame('assembled_by_cssz_from_monthly_report', $eligibility['rule']);
        self::assertStringContainsString('měsíčním hlášením', $eligibility['reason']);
    }

    /**
     * @return list<array{0:int,1:string|null,2:bool,3:string}>
     */
    public static function narrowManualPaths(): array
    {
        return [
            'období před 2026' => [2025, null, false, 'transitional_before_2026'],
            'skončení před 1. 4. 2026' => [
                2026,
                '2026-03-31',
                false,
                'transitional_participation_ended_before_april_2026',
            ],
            'výzva ČSSZ/ÚSSZ' => [2026, null, true, 'on_authority_request'],
        ];
    }

    /**
     * Tiskopis zrušen nebyl — tyhle tři cesty musí zůstat průchozí.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('narrowManualPaths')]
    public function testNarrowManualPathsStayAvailable(
        int $year,
        ?string $participationEndOn,
        bool $requestedByAuthority,
        string $expectedRule,
    ): void {
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            $year,
            $participationEndOn,
            $requestedByAuthority,
        );

        self::assertTrue($eligibility['allowed']);
        self::assertSame($expectedRule, $eligibility['rule']);
        self::assertSame(
            $year < 2026,
            $eligibility['routine'],
            'Od roku 2026 nesmí být žádná cesta označená jako roční rutina.',
        );
    }

    /**
     * Zaměstnání skončené až po přechodném datu spadá zpátky pod ČSSZ.
     */
    public function testEmploymentEndedAfterTransitionStaysWithCssz(): void
    {
        $eligibility = EldpDeadlinePolicy::standaloneStatementAllowed(
            2026,
            '2026-04-01',
            false,
        );

        self::assertFalse($eligibility['allowed']);
    }

    public function testExcludedPeriodDeriverStillDerivesSickLeave(): void
    {
        $derived = (new EldpExcludedPeriodDeriver())->derive(
            [[
                'id' => 901,
                'absence_type' => 'dpn',
                'date_from' => '2026-07-06',
                'date_to' => '2026-07-10',
            ]],
            '2026-07-01',
            '2026-07-31',
            '2026-07',
        );

        self::assertSame([], $derived['blockers']);
        self::assertSame(5, $derived['total']);
        self::assertSame(5, $derived['components']['docasNeschopnost']);
        self::assertSame(0, $derived['components']['penezitaPomocMaterstvi']);
        self::assertCount(1, $derived['provenance']);
        self::assertSame('docasNeschopnost', $derived['provenance'][0]['attribute']);
    }

    /**
     * Součet 10357 = 10358 + 10359 + 10360 + 10362 + 10536. Kdyby se složka
     * ztratila, vyloučené doby by v hlášení tiše klesly a zaměstnanci by se
     * o tutéž dobu zvedl jmenovatel osobního vyměřovacího základu.
     */
    public function testExcludedPeriodComponentsCoverTheWholeEldpSum(): void
    {
        self::assertSame(
            [
                'docasNeschopnost',
                'penezitaPomocMaterstvi',
                'osetrovaniClenaRodiny',
                'otcovska',
                'vyloucenePar16',
            ],
            EldpExcludedPeriodDeriver::COMPONENTS,
        );
    }

    /**
     * Měsíční hlášení si drží ELDP atributy i sekci s dobou pojištění.
     */
    public function testMonthlyEvidenceKeepsEldpAttributesAndSection(): void
    {
        $snapshot = (new JmhzEldpEvidenceBuilder())->build(
            7,
            101,
            self::source(),
            self::confirmation(),
        );

        $attributes = $snapshot->payload['source_evidence']['attribute_ids'];
        foreach ([
            '10240', '10241', '10242', '10354', '10355', '10356', '10245',
            '10357', '10358', '10359', '10360', '10362', '10536',
        ] as $attributeId) {
            self::assertContains(
                $attributeId,
                $attributes,
                "Měsíční hlášení přišlo o ELDP atribut {$attributeId}.",
            );
        }

        $section = $snapshot->payload['eldp_sections'][0];
        self::assertSame('1++', $section['code']);
        self::assertSame('2026-07-01', $section['valid_from']);
        self::assertSame('2026-07-31', $section['valid_to']);
        self::assertSame(31, $section['insurance_days']);
        self::assertSame(10_000, $section['assessment_base_czk']);
        self::assertSame(
            ['insurance_from' => '2026-07-01', 'insurance_to' => '2026-07-31'],
            $snapshot->payload['insurance_interval'],
        );
    }

    /**
     * Měsíční větev opravdu volá `EldpExcludedPeriodDeriver` — nemoc uvnitř
     * měsíce z ní udělá vyloučenou dobu, a automatické potvrzení běžného řezu
     * proto musí selhat (ordinary řez vyloučené doby neumí). Kdyby se deriver
     * z JMHZ větve vytrhl, tenhle případ by tiše prošel jako bezabsenční.
     */
    public function testMonthlyEvidenceRunsThroughTheExcludedPeriodDeriver(): void
    {
        $source = self::source();
        $input = json_decode(
            (string) $source['revision']['input_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'] = [[
            'id' => 903,
            'absence_type' => 'dpn',
            'date_from' => '2026-07-06',
            'date_to' => '2026-07-10',
        ]];
        $source = self::withInput($source, $input);

        $this->expectException(JmhzEldpEvidenceException::class);
        (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
    }

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private static function withInput(array $source, array $input): array
    {
        $source['revision']['input_snapshot_json'] = CanonicalJson::encode($input);
        $source['revision']['input_snapshot_hash'] =
            hash('sha256', $source['revision']['input_snapshot_json']);
        $result = json_decode(
            (string) $source['revision']['result_snapshot_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $result['source_snapshot_hash'] = $source['revision']['input_snapshot_hash'];
        $source['revision']['result_snapshot_json'] = CanonicalJson::encode($result);
        $source['revision']['result_snapshot_hash'] =
            hash('sha256', $source['revision']['result_snapshot_json']);

        return $source;
    }

    /** @return array<string,mixed> */
    private static function confirmation(): array
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

    /** @return array<string,mixed> */
    private static function source(): array
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
