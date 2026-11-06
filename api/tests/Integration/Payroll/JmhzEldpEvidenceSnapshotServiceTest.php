<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceSnapshotService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class JmhzEldpEvidenceSnapshotServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private JmhzEldpEvidenceSnapshotService $service;
    private JmhzPreparationSnapshotService $preparations;
    private int $supplierId;
    private int $employmentId;
    private int $revisionId;
    private int $createdBy;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_jmhz_eldp_evidence_snapshots')) {
            $this->markTestSkipped('Migrace 1363 neproběhla.');
        }
        $service = $container->get(JmhzEldpEvidenceSnapshotService::class);
        self::assertInstanceOf(JmhzEldpEvidenceSnapshotService::class, $service);
        $this->service = $service;
        $preparations = $container->get(JmhzPreparationSnapshotService::class);
        self::assertInstanceOf(JmhzPreparationSnapshotService::class, $preparations);
        $this->preparations = $preparations;
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $this->createdBy = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        self::assertGreaterThan(0, $this->createdBy);
        $sourceSupplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        [$this->employmentId, $this->revisionId] = $this->source($pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public function testFreezeIsEncryptedIdempotentAndImmutable(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-evidence',
            $this->createdBy,
        );
        $second = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-evidence',
            $this->createdBy,
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, $first['section_count']);
        $statement = $this->db->pdo()->prepare(
            'SELECT evidence.snapshot_ciphertext, evidence.source_manifest_json,
                    claim.confirmation_fingerprint
               FROM payroll_jmhz_eldp_evidence_snapshots evidence
               JOIN payroll_jmhz_eldp_idempotency_claims claim
                 ON claim.supplier_id = evidence.supplier_id
                AND claim.environment = evidence.environment
                AND claim.evidence_snapshot_id = evidence.id
              WHERE evidence.supplier_id = ? AND evidence.id = ?'
        );
        $statement->execute([$this->supplierId, $first['id']]);
        $stored = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertStringStartsWith('enc:v2:', (string) $stored['snapshot_ciphertext']);
        self::assertStringNotContainsString('Syntetické potvrzení', (string) $stored['source_manifest_json']);
        self::assertStringNotContainsString('assessment_base_czk', (string) $stored['source_manifest_json']);
        self::assertNotSame(
            hash('sha256', CanonicalJson::encode($this->confirmation())),
            $stored['confirmation_fingerprint'],
        );

        $preparation = $this->preparations->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-preparation-after-eldp',
            $this->createdBy,
        );
        $issueCodes = array_column($preparation['issues'], 'code');
        self::assertNotContains('jmhz_eldp_evidence_missing', $issueCodes);

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_jmhz_eldp_evidence_snapshots SET section_count = 2
                  WHERE supplier_id = ? AND id = ?',
            )->execute([$this->supplierId, $first['id']]);
            self::fail('ELDP evidence musí být immutable.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
    }

    public function testPreparationAutomaticallyFreezesOrdinaryEldpEvidence(): void
    {
        $preparation = $this->preparations->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            'synthetic-preparation-derived-eldp',
            $this->createdBy,
        );

        $issueCodes = array_column($preparation['issues'], 'code');
        self::assertNotContains(
            'jmhz_eldp_evidence_missing',
            $issueCodes,
            CanonicalJson::encode($issueCodes),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_jmhz_eldp_evidence_snapshots
              WHERE supplier_id = ? AND environment = "test"
                AND source_revision_id = ? AND employment_id = ?
                AND created_by = ?',
        );
        $statement->execute([
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            $this->createdBy,
        ]);
        self::assertSame(1, (int) $statement->fetchColumn());

        $snapshot = $this->service->snapshotForPreparation(
            $this->supplierId,
            'test',
            $this->revisionId,
            $this->employmentId,
        );
        self::assertIsArray($snapshot);
        self::assertSame('1++', $snapshot['payload']['eldp_sections'][0]['code']);
        self::assertSame(10_000, $snapshot['payload']['eldp_sections'][0]['assessment_base_czk']);
        self::assertSame('', $snapshot['payload']['confirmation']['note']);

        $audit = $this->db->pdo()->prepare(
            'SELECT payload FROM activity_log
              WHERE supplier_id = ? AND action = "payroll.jmhz_eldp_evidence.frozen"
              ORDER BY id DESC LIMIT 1',
        );
        $audit->execute([$this->supplierId]);
        $payload = json_decode((string) $audit->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('derived_from_frozen_payroll_sources', $payload['source_kind']);
        self::assertSame($this->revisionId, $payload['source_revision_id']);
        self::assertSame($this->employmentId, $payload['employment_id']);
    }

    /**
     * Zaměstnanec po dosažení důchodového věku, který důchod nepobírá: měsíční
     * hlášení nese kód D. Den dosažení věku je jen v zákonné evidenci osoby —
     * dřív ho zdroj řezu nenesl a hlášení odešlo s „1++".
     */
    public function testPensionAgeFromStatutoryEvidenceGivesCodeD(): void
    {
        $this->insertPensionAge($this->employeeId(), '2026-03-15');

        $snapshot = $this->preparedSnapshot('synthetic-preparation-pension-age');

        $section = $snapshot['payload']['eldp_sections'][0];
        self::assertSame('1D+', $section['code']);
        self::assertNull($section['deducted_days_total']);
        self::assertSame(
            ['early_pension_from' => null, 'full_pension_paid_from' => null, 'pension_age_reached_on' => '2026-03-15'],
            $snapshot['payload']['source_evidence']['pension_status'],
        );
    }

    /**
     * Poživatel předčasného starobního důchodu před dosažením důchodového věku:
     * kód D nemá (D jen po splnění podmínky nároku podle § 29 odst. 1 nebo 3,
     * Zadání ELDP12 v1.04) a odečtené doby také ne.
     */
    public function testEarlyPensionerFromStatutoryEvidenceHasNoCodeDBeforePensionAge(): void
    {
        $employeeId = $this->employeeId();
        $this->insertPensionAge($employeeId, '2027-05-01');
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_pensions
                (supplier_id, employee_id, pension_type_code, early_retirement,
                 reduced_retirement_age, effective_from)
             VALUES (?, ?, "1", 1, 0, "2026-02-01")',
        )->execute([$this->supplierId, $employeeId]);

        $snapshot = $this->preparedSnapshot('synthetic-preparation-early-pension');

        $section = $snapshot['payload']['eldp_sections'][0];
        self::assertSame('1++', $section['code']);
        self::assertNull($section['deducted_days_total']);
        self::assertSame('2026-02-01', $snapshot['payload']['source_evidence']['pension_status']['early_pension_from']);
    }

    /**
     * Zmrazený řez se změnou evidence nemění: hlášení za už připravený měsíc
     * zůstane, jak bylo, i když účetní důchodový věk doplní později.
     */
    public function testFrozenSnapshotIgnoresLaterPensionEvidence(): void
    {
        $first = $this->preparedSnapshot('synthetic-preparation-before-pension-age');
        self::assertSame('1++', $first['payload']['eldp_sections'][0]['code']);

        $this->insertPensionAge($this->employeeId(), '2026-03-15');
        $again = $this->service->snapshotForPreparation($this->supplierId, 'test', $this->revisionId, $this->employmentId);

        self::assertIsArray($again);
        self::assertSame($first['id'], $again['id']);
        self::assertSame('1++', $again['payload']['eldp_sections'][0]['code']);
    }

    /**
     * Poživatel starobního důchodu v plné výši: ELDP se vyplňuje „pouze do
     * měsíce, od něhož náleží výplata starobního důchodu v plné výši" včetně
     * něj (diskuse JMHZ #363, Metodická pomůcka ELDP, příklad 8a). Duben, od
     * kterého důchod náleží, nese třídu ELDP i s ověřenou slevou pracujícího
     * důchodce, ať důchod začíná 1. nebo 15. dubna; květen už jen 10354,
     * 10355 a 10356 = 0. Dřív sleva sama vypustila ELDP už za duben.
     */
    #[DataProvider('fullPensionStarts')]
    public function testFullPensionStartMonthStillCarriesEldpAndNextMonthDoesNot(string $year, string $day): void
    {
        $pdo = $this->db->pdo();
        $employeeId = $this->employeeId();
        $this->insertPensionAge($employeeId, "{$year}-03-20");
        $pdo->prepare(
            'INSERT INTO payroll_person_pensions
                (supplier_id, employee_id, pension_type_code, early_retirement,
                 reduced_retirement_age, effective_from)
             VALUES (?, ?, "1", 0, 0, ?)',
        )->execute([$this->supplierId, $employeeId, "{$year}-04-{$day}"]);
        $existing = [$employeeId, $this->employmentId];
        [, $aprilRevision] = $this->source($pdo, false, "{$year}-04-01", true, $existing);
        [, $mayRevision] = $this->source($pdo, false, "{$year}-05-01", true, $existing);

        $april = $this->preparedSnapshotFor($aprilRevision, "synthetic-full-pension-april-{$year}-{$day}");
        $aprilSection = $april['payload']['eldp_sections'][0];
        self::assertFalse($april['payload']['source_evidence']['working_pensioner']);
        self::assertSame("{$year}-04", $april['payload']['source_evidence']['pension_status']['full_pension_paid_from']);
        self::assertSame('1D+', $aprilSection['code']);
        self::assertSame("{$year}-04-01", $aprilSection['valid_from']);
        self::assertSame("{$year}-04-30", $aprilSection['valid_to']);
        self::assertSame(30, $aprilSection['insurance_days']);
        self::assertSame(10_000, $aprilSection['assessment_base_czk']);

        $may = $this->preparedSnapshotFor($mayRevision, "synthetic-full-pension-may-{$year}-{$day}");
        $maySection = $may['payload']['eldp_sections'][0];
        self::assertTrue($may['payload']['source_evidence']['working_pensioner']);
        self::assertSame("{$year}-05-01", $may['payload']['insurance_interval']['insurance_from']);
        self::assertSame("{$year}-05-31", $may['payload']['insurance_interval']['insurance_to']);
        self::assertNull($maySection['code']);
        self::assertNull($maySection['valid_from']);
        self::assertNull($maySection['valid_to']);
        self::assertSame(0, $maySection['insurance_days']);
        self::assertNull($maySection['assessment_base_czk']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function fullPensionStarts(): iterable
    {
        yield '2026, důchod od 1. 4.' => ['2026', '01'];
        yield '2026, důchod od 15. 4.' => ['2026', '15'];
        yield '2027, důchod od 1. 4.' => ['2027', '01'];
        yield '2027, důchod od 15. 4.' => ['2027', '15'];
    }

    /**
     * Ověřená sleva bez starobního důchodu v zákonné evidenci rozhoduje
     * sama jako dřív: měsíc, od kterého výplata náleží, se nedá poznat.
     */
    public function testWorkingPensionerWithoutPensionEvidenceStaysWithoutEldp(): void
    {
        $pdo = $this->db->pdo();
        [, $revision] = $this->source($pdo, false, '2026-04-01', true, [$this->employeeId(), $this->employmentId]);

        $snapshot = $this->preparedSnapshotFor($revision, 'synthetic-pensioner-without-evidence');

        self::assertTrue($snapshot['payload']['source_evidence']['working_pensioner']);
        self::assertNull($snapshot['payload']['eldp_sections'][0]['code']);
        self::assertSame(0, $snapshot['payload']['eldp_sections'][0]['insurance_days']);
    }

    /** @return array<string,mixed> */
    private function preparedSnapshotFor(int $revisionId, string $key): array
    {
        $preparation = $this->preparations->freeze($this->supplierId, $revisionId, 'test', $key, $this->createdBy);
        self::assertNotContains(
            'jmhz_eldp_evidence_missing',
            array_column($preparation['issues'], 'code'),
            CanonicalJson::encode(array_column($preparation['issues'], 'code')),
        );
        $snapshot = $this->service->snapshotForPreparation($this->supplierId, 'test', $revisionId, $this->employmentId);
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function preparedSnapshot(string $key): array
    {
        $preparation = $this->preparations->freeze(
            $this->supplierId,
            $this->revisionId,
            'test',
            $key,
            $this->createdBy,
        );
        self::assertNotContains(
            'jmhz_eldp_evidence_missing',
            array_column($preparation['issues'], 'code'),
            CanonicalJson::encode(array_column($preparation['issues'], 'code')),
        );
        $snapshot = $this->service->snapshotForPreparation(
            $this->supplierId,
            'test',
            $this->revisionId,
            $this->employmentId,
        );
        self::assertIsArray($snapshot);

        return $snapshot;
    }

    private function employeeId(): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$this->supplierId, $this->employmentId]);

        return (int) $statement->fetchColumn();
    }

    private function insertPensionAge(int $employeeId, string $reachedOn): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_person_pension_age
                (supplier_id, employee_id, basis, effective_from)
             VALUES (?, ?, "statutory_table", ?)',
        )->execute([$this->supplierId, $employeeId, $reachedOn]);
    }

    public function testPreparationKeepsAbsenceExceptionFailClosedAndActionable(): void
    {
        [, $revisionId] = $this->source($this->db->pdo(), true, '2026-08-01');

        $preparation = $this->preparations->freeze(
            $this->supplierId,
            $revisionId,
            'test',
            'synthetic-preparation-eldp-absence',
            $this->createdBy,
        );

        $issueCodes = array_column($preparation['issues'], 'code');
        self::assertContains('jmhz_eldp_absences_unsupported', $issueCodes);
        self::assertNotContains('jmhz_eldp_evidence_missing', $issueCodes);
        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_jmhz_eldp_evidence_snapshots
              WHERE supplier_id = ? AND source_revision_id = ?',
        );
        $count->execute([$this->supplierId, $revisionId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testDifferentConfirmationCannotReplaceFrozenScope(): void
    {
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-original',
            $this->createdBy,
        );
        $changed = $this->confirmation();
        $changed['confirmation_note'] = 'Jiné syntetické potvrzení téhož měsíce.';

        $this->expectException(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('již zmrazené');
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $changed,
            'synthetic-eldp-changed',
            $this->createdBy,
        );
    }

    public function testSameIdempotencyKeyRejectsChangedConfirmation(): void
    {
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-stable-key',
            $this->createdBy,
        );
        $changed = $this->confirmation();
        $changed['confirmation_note'] = 'Změněné syntetické potvrzení se stejným klíčem.';

        $this->expectException(\MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceException::class);
        $this->expectExceptionMessage('jiný obsah');
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $changed,
            'synthetic-eldp-stable-key',
            $this->createdBy,
        );
    }

    public function testReplayRemainsStableAfterSourceRevisionIsSuperseded(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-superseded-replay',
            $this->createdBy,
        );
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_runs run
                JOIN payroll_run_revisions revision
                  ON revision.supplier_id = run.supplier_id AND revision.run_id = run.id
               SET run.current_revision_no = 2
             WHERE revision.supplier_id = ? AND revision.id = ?',
        );
        $statement->execute([$this->supplierId, $this->revisionId]);

        $replayed = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-superseded-replay',
            $this->createdBy,
        );

        self::assertFalse($replayed['created']);
        self::assertSame($first['id'], $replayed['id']);
    }

    public function testReplayNormalizesConfirmationNoteAndIgnoresUnknownInputKeys(): void
    {
        $first = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-normalized-replay',
            $this->createdBy,
        );
        $equivalent = $this->confirmation();
        $equivalent['confirmation_note'] = '  ' . $equivalent['confirmation_note'] . '  ';
        $equivalent['ignored_future_client_field'] = 'ignored';

        $replayed = $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $equivalent,
            'synthetic-eldp-normalized-replay',
            $this->createdBy,
        );

        self::assertFalse($replayed['created']);
        self::assertSame($first['id'], $replayed['id']);
    }

    public function testFreezeRequiresAuditableActor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uživatel potvrzení ELDP');
        $this->service->freeze(
            $this->supplierId,
            $this->revisionId,
            $this->employmentId,
            'test',
            $this->confirmation(),
            'synthetic-eldp-without-actor',
            null,
        );
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
     * @param array{int,int}|null $existing zaměstnanec a vztah dalšího měsíce téže osoby
     * @return array{int,int}
     */
    private function source(
        PDO $pdo,
        bool $withAbsence = false,
        string $periodStart = '2026-07-01',
        bool $workingPensioner = false,
        ?array $existing = null,
    ): array
    {
        if ($existing !== null) {
            [$employeeId, $employmentId] = $existing;
        } else {
            $pdo->prepare(
                'INSERT INTO payroll_employees
                    (supplier_id, full_name, taxpayer_type, employment_type,
                     tax_declaration_signed, tax_credit_taxpayer, child_count,
                     monthly_gross, auto_post, is_active)
                 VALUES (?, "Syntetická osoba ELDP", "employee", "hpp",
                         1, 1, 0, 10000, 0, 1)',
            )->execute([$this->supplierId]);
            $employeeId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO payroll_employments
                    (supplier_id, employee_id, code, relation_type, status,
                     start_date, monthly_gross_minor)
                 VALUES (?, ?, "eldp-synthetic", "employment", "active",
                         "2026-01-01", 1000000)',
            )->execute([$this->supplierId, $employeeId]);
            $employmentId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status, current_revision_no)
             VALUES (?, ?, DATE_ADD(?, INTERVAL 40 DAY), "approved", 1)',
        )->execute([$this->supplierId, $periodStart, $periodStart]);
        $runId = (int) $pdo->lastInsertId();
        $input = [
            'schema_version' => 'payroll-run-input.v2',
            'supplier_id' => $this->supplierId,
            'period_start' => $periodStart,
            'people' => [[
                'employee' => ['id' => $employeeId],
                ...($workingPensioner
                    ? ['statutory_evidence' => ['social' => ['working_pensioner_discount' => ['status' => 'verified']]]]
                    : []),
                'employments' => [[
                    'employment' => [
                        'id' => $employmentId,
                        'employee_id' => $employeeId,
                        'relation_type' => 'employment',
                        'start_date' => '2026-01-01',
                        'actual_start_date' => '2026-01-01',
                        'end_date' => null,
                    ],
                    'term' => [
                        'id' => 801,
                        'row_version' => 1,
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                    ],
                    'time_month' => [
                        'jmhz_work_summary' => [
                            'id' => 901,
                            'derivation_version' => 'jmhz-work-month.v2',
                            'summary_sha256' => str_repeat('d', 64),
                            'conditional_blocks_confirmed' => true,
                            'interactions' => ['IN07' => false, 'IN08' => false],
                            'values' => [
                                'evidence_days' => (int) (new \DateTimeImmutable($periodStart))->format('t'),
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
                    'absences' => $withAbsence ? [[
                        'type' => 'sickness',
                        'date_from' => substr($periodStart, 0, 8) . '10',
                        'date_to' => substr($periodStart, 0, 8) . '10',
                    ]] : [],
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
                            'relationship_id' => "employment:{$employmentId}",
                            'kind' => 'employment',
                            'participation' => [
                                'relationship_id' => "employment:{$employmentId}",
                                'status' => 'participates',
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
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json, result_snapshot_hash,
                 idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved", "payroll-run-input.v2",
                     ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $inputJson,
            hash('sha256', $inputJson),
            $resultJson,
            hash('sha256', $resultJson),
            hash('sha256', "synthetic-eldp:{$this->supplierId}:{$periodStart}", true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $emptyJson = CanonicalJson::encode([]);
        $pdo->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, employee_id, employment_id,
                 input_json, input_hash, result_json, result_hash, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "calculated")',
        )->execute([
            $this->supplierId,
            $revisionId,
            $employeeId,
            $employmentId,
            $emptyJson,
            hash('sha256', $emptyJson),
            $emptyJson,
            hash('sha256', $emptyJson),
        ]);
        return [$employmentId, $revisionId];
    }
}
