<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ELDP řez: které druhy vztahů a nepřítomností projdou a které zastaví
 * odvození — a tím (podle nálezu v commitu c5591dd48) měsíční hlášení
 * CELÉ FIRMY. Fixtura převzatá z JmhzEldpEvidenceBuilderTest, hodnoty
 * syntetické.
 *
 * Druh vztahu už odvození nezastaví: o ELDP rozhoduje účast na nemocenském
 * pojištění (nález N-01, oprava). Nepodporované druhy absencí blokují dál,
 * a to záměrně — viz komentář u příslušného testu.
 */
final class JmhzAuditEldpRelationshipKindsTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string,string,string,int}>
     */
    /**
     * @return iterable<string,array{string,string,string,string,int}>
     */
    public static function supportedRelationships(): iterable
    {
        yield 'zaměstnání malého rozsahu (účastné, 5 000 Kč)' => [
            'small_scale_employment', '1', 'employment', 'participates', 500_000,
        ];
        yield 'zaměstnání malého rozsahu (neúčastné, 3 000 Kč)' => [
            'small_scale_employment', '1', 'employment', 'does_not_participate', 300_000,
        ];
        yield 'DPČ pod rozhodnou částkou (3 000 Kč)' => [
            'dpc', 'A', 'dpc', 'does_not_participate', 300_000,
        ];
        yield 'jednatel s odměnou pod rozhodnou částkou (3 000 Kč)' => [
            'statutory_body', 'S', 'corporate_body', 'does_not_participate', 300_000,
        ];
        yield 'společník pod rozhodnou částkou (3 000 Kč)' => [
            'partner_dependent', 'S', 'corporate_body', 'does_not_participate', 300_000,
        ];
    }

    /**
     * O ELDP rozhoduje účast na nemocenském pojištění, ne druh vztahu:
     * účastný měsíc má kód, neúčastný je bezkódová sekce s nulou dnů. Žádná
     * z těchto kombinací nesmí shodit hlášení celé firmy.
     */
    #[DataProvider('supportedRelationships')]
    public function testRelationshipKindIsDerivedInsteadOfFailingClosed(
        string $relationType,
        string $activityCode,
        string $kind,
        string $status,
        int $incomeMinor,
    ): void {
        $source = $this->agreementSource(
            $relationType,
            $activityCode,
            $kind,
            $status,
            $incomeMinor,
            $status === 'participates' ? $incomeMinor : 0,
        );

        $confirmation = (new JmhzEldpEvidenceBuilder())
            ->deriveOrdinaryConfirmation(7, 101, $source);

        if ($status === 'participates') {
            self::assertSame($activityCode . '++', $confirmation['code']);
            self::assertSame(31, $confirmation['insurance_days']);
            self::assertNotNull($confirmation['valid_from']);
        } else {
            // Bezkódová sekce s nulou dnů — vztah trvá, pojištěný ale není.
            self::assertNull($confirmation['code']);
            self::assertSame(0, $confirmation['insurance_days']);
            self::assertNull($confirmation['valid_from']);
            self::assertNull($confirmation['assessment_base_czk']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function unsupportedAbsences(): iterable
    {
        yield 'rodičovská dovolená' => ['parental'];
        yield 'neplacené volno' => ['unpaid_leave'];
        yield 'neomluvená absence' => ['unexcused'];
        yield 'jiná překážka na straně zaměstnance' => ['employee_obstacle'];
        yield 'peněžitá pomoc v mateřství (má atribut 10359, přesto blokuje)' => ['ppm'];
    }

    /**
     * ZNÁMÁ MEZERA, ne regrese. Ordinary řez ELDP podporuje jen absence, které
     * umí křížově ověřit proti zmrazenému pracovnímu souhrnu
     * (`ABSENCE_WORK_SUMMARY_FIELDS`: dovolená, DPN, karanténa, OČR,
     * dlouhodobé ošetřovné). Ostatní druhy blokují ZÁMĚRNĚ — chybí jim pole
     * v souhrnu, takže vykázaný počet dnů by nešel doložit.
     *
     * Rozšíření není uvolnění tohohle guardu: vyžaduje doplnit pracovní souhrn
     * o odpovídající millihodinová pole a u rodičovské navíc přerušení
     * pojištění kódem ELDP (§ 16 zákona č. 155/1995 Sb.), ne vyloučenou dobu.
     * Test drží stav, aby se mezera nezavřela omylem a bez důkazu.
     */
    #[DataProvider('unsupportedAbsences')]
    public function testAbsenceKindFailsClosedInEldpDerivation(string $absenceType): void
    {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['absences'] = [[
            'id' => 902,
            'absence_type' => $absenceType,
            'date_from' => '2026-07-13',
            'date_to' => '2026-07-14',
        ]];
        $source = $this->withInput($source, $input);

        try {
            (new JmhzEldpEvidenceBuilder())->deriveOrdinaryConfirmation(7, 101, $source);
            self::fail('Odvození ELDP mělo skončit výjimkou.');
        } catch (JmhzEldpEvidenceException $exception) {
            self::assertSame('jmhz_eldp_absences_unsupported', $exception->validationCode);
        }
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
    private function agreementSource(
        string $relationType,
        string $activityCode,
        string $kind,
        string $participationStatus,
        int $participationIncomeMinor,
        int $cappedAssessmentBaseMinor,
    ): array {
        $source = $this->source();
        $input = json_decode($source['revision']['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($input);
        $input['people'][0]['employments'][0]['employment']['relation_type'] = $relationType;
        $input['people'][0]['employments'][0]['term']['activity_code'] = $activityCode;
        $input['people'][0]['employments'][0]['term']['jmhz_relationship_detail_code'] =
            in_array($relationType, ['dpc', 'dpp'], true) ? null : '1';
        $input['people'][0]['employments'][0]['time_month']['jmhz_work_summary']['values']['evidence_days'] =
            in_array($relationType, ['dpc', 'dpp'], true) ? 0 : 31;
        $source = $this->withInput($source, $input);

        $result = json_decode($source['revision']['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        $relationship = &$result['people'][0]['statutory']['social_insurance']['relationships'][0];
        $relationship['kind'] = $kind;
        $relationship['participation']['status'] = $participationStatus;
        $relationship['participation']['participation_income_minor_units'] = $participationIncomeMinor;
        $relationship['participation']['group_income_minor_units'] = $participationIncomeMinor;
        $relationship['assessment_base_minor_units'] = $participationIncomeMinor;
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
