<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use DragonOfMercy\PhpPdf\PdfEditor;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollSubmissionRepository;
use MyInvoice\Repository\Payroll\PayrollStatutoryResultRepository;
use MyInvoice\Repository\Submission\SubmissionRecipientRepository;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthInsuranceSubmissionService;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationDeadlinePolicy;
use MyInvoice\Service\Payroll\Submission\HealthInsurance\HealthNotificationException;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveField;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Životní cyklus podání přehledu o platbě zdravotní pojišťovně: povinnost →
 * podání → část → artefakt → výhrada nebo připravenost.
 */
#[Group('integration')]
final class PayrollHealthInsuranceSubmissionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollSubmissionRepository $repository;
    private HealthInsuranceSubmissionService $service;
    private HealthInsuranceSchemaCatalog $schemas;
    private PayrollSubmissionService $submissions;
    private PayrollSensitiveData $sensitive;
    private int $supplierId;
    private int $revisionId;
    private int $employmentId;
    private int $employeeId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $db = $container->get(Connection::class);
        if (!$db instanceof Connection) {
            throw new \RuntimeException('Databázové spojení není dostupné.');
        }
        $this->db = $db;
        foreach ([
            'payroll_runs',
            'payroll_run_revisions',
            'payroll_statutory_results',
            'payroll_obligations',
            'payroll_submissions',
            'payroll_submission_artifacts',
            'payroll_person_health_coverage_history',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí tabulka {$table}.");
            }
        }
        $service = $container->get(HealthInsuranceSubmissionService::class);
        $submissions = $container->get(PayrollSubmissionService::class);
        $sensitive = $container->get(PayrollSensitiveData::class);
        if (!$service instanceof HealthInsuranceSubmissionService
            || !$submissions instanceof PayrollSubmissionService
            || !$sensitive instanceof PayrollSensitiveData
        ) {
            throw new \RuntimeException('Služby podání nejsou dostupné.');
        }
        $this->service = $service;
        $this->submissions = $submissions;
        $this->sensitive = $sensitive;
        $this->repository = $container->get(PayrollSubmissionRepository::class);
        $this->schemas = new HealthInsuranceSchemaCatalog();

        $pdo = $this->db->pdo();
        $statement = $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1');
        if ($statement === false) {
            throw new \RuntimeException('Výchozí firmu nelze načíst.');
        }
        $sourceSupplierId = (int) $statement->fetchColumn();
        if ($sourceSupplierId <= 0) {
            $this->markTestSkipped('Chybí výchozí firma.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier(
            $pdo,
            $sourceSupplierId,
        );
        $pdo->prepare(
            'UPDATE supplier
                SET ic = "12345678", company_name = "Syntetický plátce s.r.o.",
                    street = "Zkušební", street_number_pop = "12",
                    zip = "110 00", city = "Praha 1", phone = "+420111222333"
              WHERE id = ?',
        )->execute([$this->supplierId]);
        $employeeId = $this->employee($pdo);
        $this->employeeId = $employeeId;
        $this->employmentId = $this->employment($pdo, $employeeId);
        $this->coverage($pdo, $employeeId);
        $this->identity($pdo, $employeeId, 'Jana', 'Nováková');
        $this->insertIdentifier($pdo, $employeeId, 'birth_number', '9052224321');
        $this->healthInsurerAccount($pdo);
        $this->revisionId = $this->revision($pdo, $employeeId);
        $this->storeResult($employeeId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testCapabilityNamesWhatIsPinnedAndWhatIsNot(): void
    {
        $capability = $this->service->capability($this->supplierId);

        self::assertSame('2026-01-01', $capability['shared_data_message_since']);
        self::assertCount(7, $capability['channels']);
        self::assertFalse($capability['automated_dispatch']['supported']);
        self::assertSame(
            ['205', '207', '213'],
            array_values(array_map(
                'strval',
                array_keys(array_filter(
                    $capability['channels'],
                    static fn (array $channel): bool =>
                        $channel['accepts_shared_data_message'],
                )),
            )),
        );
        self::assertSame(25, $capability['change_codes']['total']);
        // Mapování druh → kód dokládá anotace připnutého XSD, ale jen tam,
        // kde schéma určuje jediný kód; opravy a přestup zůstávají otevřené.
        self::assertSame(
            [
                'employment_start',
                'employment_end',
                'maternity_leave_start',
                'parental_leave_start',
                'maternity_or_parental_leave_end',
            ],
            $capability['change_codes']['mapping_from_duty_documented'],
        );
        foreach ($capability['channels'] as $code => $channel) {
            self::assertFalse(
                $channel['automated_dispatch_documented'],
                "Kanál {$code} se nesmí tvářit jako doložený.",
            );
            self::assertNotSame('', $channel['undocumented_reason_code']);
        }
        foreach ($capability['documents'] as $documentType => $document) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{64}$/D',
                $document['schema_sha256'],
                $documentType,
            );
        }
    }

    public function testCapabilityUsesTheSameCompanyOverrideAsDispatch(): void
    {
        (new SubmissionRecipientRepository($this->db))->upsertForSupplier(
            $this->supplierId,
            [
                'code' => 'zp_cpzp_205',
                'name' => 'Firemní příjemce ČPZP',
                'business_id' => '47672234',
                'address' => 'Syntetická firemní adresa',
                'kind' => 'health_insurer',
                'isds_box_id' => 'zzzzzzz',
                'source_url' => null,
                'source_note' => null,
                'is_active' => true,
            ],
            null,
        );

        $channel = $this->service->capability($this->supplierId)['channels']['205'];

        self::assertSame(
            'Firemní příjemce ČPZP',
            $channel['insurer_name'],
        );
        self::assertSame('zzzzzzz', $channel['data_box_id']);
        self::assertSame('company', $channel['recipient_source']);
    }

    /**
     * Povinnost a lhůta vznikají i tehdy, když aplikace podání odeslat neumí.
     */
    public function testEmploymentStartRegistersAnObligationWithAnEightDayDeadline(): void
    {
        $registered = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );

        self::assertCount(1, $registered);
        self::assertNull($registered[0]['skipped_reason_code']);
        self::assertGreaterThan(0, $registered[0]['obligation_id']);
        self::assertSame(
            'employment_start',
            $registered[0]['duty']['kind'],
        );
        self::assertSame(
            '2026-03-09',
            $registered[0]['duty']['deadline']['due_on'],
        );

        $row = $this->db->pdo()->prepare(
            'SELECT agenda_code, subject_reference, preferred_channel
               FROM payroll_obligations
              WHERE supplier_id = ? AND id = ?',
        );
        $row->execute([$this->supplierId, $registered[0]['obligation_id']]);
        $obligation = $row->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($obligation);
        self::assertSame('HOZ_2026', $obligation['agenda_code']);
        self::assertSame(
            'employment:' . $this->employmentId,
            $obligation['subject_reference'],
        );
        self::assertSame('health_portal', $obligation['preferred_channel']);
    }

    public function testRegisteringTheSameDutyTwiceIsIdempotent(): void
    {
        $first = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );
        $second = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );

        self::assertSame(
            $first[0]['obligation_id'],
            $second[0]['obligation_id'],
        );
    }

    public function testPeriodBulkSyncPersistsItsStateAndIsIdempotent(): void
    {
        $before = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-03',
            ['reported' => true],
        );
        self::assertCount(1, $before['items']);
        self::assertNull($before['items'][0]['obligation_id']);

        $first = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );
        self::assertSame(1, $first['total']);
        self::assertSame(1, $first['created']);

        $after = $this->service->dutiesForPeriod(
            $this->supplierId,
            'production',
            '2026-03',
            ['reported' => true],
        );
        self::assertSame(
            $first['items'][0]['obligation_id'],
            $after['items'][0]['obligation_id'],
        );

        $replay = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );
        self::assertSame(1, $replay['total']);
        self::assertSame(0, $replay['created']);
        self::assertSame(
            $first['items'][0]['obligation_id'],
            $replay['items'][0]['obligation_id'],
        );
    }

    public function testPeriodBulkSyncFailsClosedWhenAnEmploymentIsUnresolved(): void
    {
        $pdo = $this->db->pdo();
        $employeeId = $this->employee($pdo, 'Syntetická osoba bez pojišťovny');
        $this->employment($pdo, $employeeId, 'ZP-2');

        try {
            $this->service->registerPeriodObligations(
                $this->supplierId,
                'production',
                '2026-03',
            );
            self::fail('Neúplný měsíc se nesmí částečně zaevidovat.');
        } catch (HealthNotificationException $e) {
            self::assertSame(
                'zp_period_contains_unresolved_employments',
                $e->errorCode,
            );
        }

        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM payroll_obligations
              WHERE supplier_id = ? AND agenda_code = "HOZ_2026"',
        );
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testPeriodBulkSyncDoesNotReviveACancelledObligation(): void
    {
        $first = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_obligations SET status = "cancelled"
              WHERE supplier_id = ? AND id = ?',
        )->execute([
            $this->supplierId,
            $first['items'][0]['obligation_id'],
        ]);

        try {
            $this->service->registerPeriodObligations(
                $this->supplierId,
                'production',
                '2026-03',
            );
            self::fail('Zrušenou povinnost nelze tiše obnovit.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_registered_duty_changed', $e->errorCode);
        }
    }

    public function testPeriodBulkSyncDetectsChangedInsurer(): void
    {
        $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_person_health_coverage_history
                SET insurer_code = "205"
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        try {
            $this->service->registerPeriodObligations(
                $this->supplierId,
                'production',
                '2026-03',
            );
            self::fail('Změna pojišťovny nesmí použít starou povinnost.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_registered_duty_changed', $e->errorCode);
        }
    }

    public function testPeriodBulkSyncSeparatesTestAndProduction(): void
    {
        $test = $this->service->registerPeriodObligations(
            $this->supplierId,
            'test',
            '2026-03',
        );
        $production = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );

        self::assertNotSame(
            $test['items'][0]['obligation_id'],
            $production['items'][0]['obligation_id'],
        );
        self::assertSame(1, $test['created']);
        self::assertSame(1, $production['created']);
    }

    public function testLegacyDutyReplaysWithoutDuplication(): void
    {
        $obligationId = $this->insertLegacyObligation('2026-03-09');

        $individual = $this->service->registerObligations(
            $this->supplierId,
            'production',
            $this->employmentId,
            '2026-06-30',
        );
        $bulk = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );
        self::assertSame($obligationId, $individual[0]['obligation_id']);
        self::assertSame($obligationId, $bulk['items'][0]['obligation_id']);
        self::assertFalse($individual[0]['created']);
        self::assertSame(0, $bulk['created']);
    }

    public function testLegacyDutyWithDifferentStoredDeadlineFailsClosed(): void
    {
        $this->insertLegacyObligation('2026-03-10');

        try {
            $this->service->registerPeriodObligations(
                $this->supplierId,
                'production',
                '2026-03',
            );
            self::fail('Legacy otisk nesmí skrýt změnu uložené lhůty.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_registered_duty_changed', $e->errorCode);
        }
    }

    public function testLegacyDutyWithDifferentDeadlineTriggerFailsClosed(): void
    {
        $this->insertLegacyObligation(
            '2026-03-09',
            str_repeat('d', 64),
        );

        try {
            $this->service->registerPeriodObligations(
                $this->supplierId,
                'production',
                '2026-03',
            );
            self::fail('Lhůta musí navazovat na stejný otisk události.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_registered_duty_changed', $e->errorCode);
        }
    }

    public function testPeriodBulkSyncHandlesFiveHundredEmployees(): void
    {
        $pdo = $this->db->pdo();
        for ($index = 2; $index <= 500; $index++) {
            $employeeId = $this->employee(
                $pdo,
                sprintf('Syntetická osoba ZP %03d', $index),
            );
            $this->employment($pdo, $employeeId, 'ZP-' . $index);
            $this->coverage($pdo, $employeeId);
        }

        $result = $this->service->registerPeriodObligations(
            $this->supplierId,
            'production',
            '2026-03',
        );

        self::assertSame(500, $result['total']);
        self::assertSame(500, $result['created']);
        self::assertCount(500, array_unique(array_column(
            $result['items'],
            'obligation_id',
        )));
        $count = $pdo->prepare(
            'SELECT COUNT(*) AS obligations,
                    COUNT(DISTINCT d.obligation_id) AS deadlines,
                    SUM(d.trigger_event_hash = o.source_event_hash) AS matching
               FROM payroll_obligations o
               JOIN payroll_submission_deadlines d
                 ON d.supplier_id = o.supplier_id
                AND d.environment = o.environment
                AND d.obligation_id = o.id
              WHERE o.supplier_id = ? AND o.agenda_code = "HOZ_2026"',
        );
        $count->execute([$this->supplierId]);
        $counts = $count->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($counts);
        self::assertSame(500, (int) $counts['obligations']);
        self::assertSame(500, (int) $counts['deadlines']);
        self::assertSame(500, (int) $counts['matching']);
    }

    public function testBatchInsertRollsBackWhenLaterChunkFails(): void
    {
        $rows = [];
        for ($index = 1; $index <= 201; $index++) {
            $hashIndex = $index === 201 ? 1 : $index;
            $sourceHash = hash('sha256', 'batch-source-' . $index);
            $rows[] = [
                'supplier_id' => $this->supplierId,
                'environment' => 'test',
                'agenda_code' => 'HOZ_BATCH_TEST',
                'subject_type' => 'employment',
                'subject_reference' => 'employment:' . $index,
                'period_start' => '2026-03-01',
                'period_end' => '2026-03-09',
                'obligation_kind' => 'regular',
                'preferred_channel' => 'health_portal',
                'responsible_user_id' => null,
                'source_event_type' => 'health_batch_test',
                'source_event_reference' => 'health_batch_test:' . $index,
                'source_event_hash' => $sourceHash,
                'request_fingerprint' => hash(
                    'sha256',
                    'batch-request-' . $index,
                ),
                'idempotency_key_hash' => hash(
                    'sha256',
                    'batch-idempotency-' . $hashIndex,
                    true,
                ),
                'created_by' => null,
            ];
        }

        try {
            $this->repository->transaction(function () use ($rows): void {
                $this->repository->insertObligationsBatch($rows);
            });
            self::fail('Kolize ve druhém chunku musí zrušit i první chunk.');
        } catch (\PDOException) {
        }

        $count = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_obligations
              WHERE supplier_id = ? AND agenda_code = "HOZ_BATCH_TEST"',
        );
        $count->execute([$this->supplierId]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    /**
     * Jádro řezu: artefakt vznikne a uloží se, ale bez připnutého XSD se
     * podání nesmí označit za ověřené — zůstane v `draft` s blokující
     * výhradou ve fázi `xsd`.
     */
    public function testPaymentOverviewFreezesTheArtefactAndStopsBeforeValidated(): void
    {
        $result = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );

        self::assertTrue($result['created']);
        self::assertSame('PPZ_2026', $result['agenda_code']);
        self::assertSame('2026-06', $result['period']);
        self::assertSame('2026-07-20', $result['deadline']['due_on']);
        self::assertGreaterThan(0, $result['artifact_id']);
        self::assertGreaterThan(0, $result['pdf_artifact_id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $result['artifact_sha256'],
        );
        self::assertFalse($result['dispatch']['supported']);

        $bundleAvailable = $this->schemas->isBundleAvailable();
        self::assertSame($bundleAvailable, $result['schema_validated']);
        self::assertSame(
            $bundleAvailable ? 'ready' : 'draft',
            $result['status'],
        );

        // Artefakt je uložený a čitelný bez ohledu na to, jestli XSD je.
        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            (int) $result['artifact_id'],
        );
        self::assertStringContainsString(
            '<prehledPlatbyZamestnavatele',
            $xml,
        );
        self::assertStringContainsString(
            '<identifikacniCisloPlatce>1234567800</identifikacniCisloPlatce>',
            $xml,
        );
        self::assertStringContainsString(
            '<soucetPojistneho>1350</soucetPojistneho>',
            $xml,
        );
        self::assertSame(
            $result['artifact_sha256'],
            hash('sha256', $xml),
        );
        $pdf = $this->submissions->artifactBytes(
            $this->supplierId,
            (int) $result['pdf_artifact_id'],
        );
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(
            $result['pdf_artifact_sha256'],
            hash('sha256', $pdf),
        );
        self::assertSame([
            false,
            'Praha 1',
            '420111222333',
            'Syntetický plátce s.r.o.',
            'Zkušební',
            '12',
            '1234567800',
            '11000',
            date('j.n.Y'),
            '1',
            '10000',
            '1350',
            '06/2026',
            true,
        ], array_map(
            static fn ($field): string|bool|array|null => $field->value,
            PdfEditor::fromBytes($pdf)->formFields(),
        ));

        if (!$bundleAvailable) {
            $issues = $this->db->pdo()->prepare(
                'SELECT severity, validation_stage, issue_code
                   FROM payroll_submission_issues
                  WHERE supplier_id = ? AND submission_id = ?',
            );
            $issues->execute([
                $this->supplierId,
                $result['submission_id'],
            ]);
            $issue = $issues->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray(
                $issue,
                'Chybějící XSD musí zůstat zapsané jako výhrada.',
            );
            self::assertSame('blocker', $issue['severity']);
            self::assertSame('xsd', $issue['validation_stage']);
            self::assertSame(
                'zp_schema_bundle_missing',
                $issue['issue_code'],
            );
        }
    }

    public function testPreparingTheSameOverviewTwiceReplaysInsteadOfDuplicating(): void
    {
        $first = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );
        $second = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame($first['artifact_id'], $second['artifact_id']);
        self::assertSame(
            $first['pdf_artifact_id'],
            $second['pdf_artifact_id'],
        );
        self::assertSame(
            $first['artifact_sha256'],
            $second['artifact_sha256'],
        );
        self::assertSame(
            $first['pdf_artifact_sha256'],
            $second['pdf_artifact_sha256'],
        );
    }

    public function testCorrectionRevisionBeforeFirstFilingCreatesAndReplaysRegularOverview(): void
    {
        $this->revisionId = $this->correctionRevision();
        $employeeId = (int) $this->db->pdo()->query(
            'SELECT employee_id FROM payroll_employments WHERE id = ' . $this->employmentId,
        )->fetchColumn();
        $this->storeResult($employeeId);

        $first = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );
        $submission = $this->repository->findSubmission(
            $this->supplierId,
            $first['submission_id'],
        );
        self::assertIsArray($submission);
        self::assertSame('regular', $submission['submission_kind']);
        self::assertNull($submission['corrects_submission_id']);
        self::assertStringContainsString(
            '<typPrehledu>radny</typPrehledu>',
            $this->submissions->artifactBytes(
                $this->supplierId,
                (int) $first['artifact_id'],
            ),
        );

        $replayed = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );
        self::assertSame($first['submission_id'], $replayed['submission_id']);
        self::assertFalse($replayed['created']);
    }

    public function testCorrectionRevisionCreatesCorrectiveOverviewLinkedToAcceptedPredecessor(): void
    {
        $regular = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '111',
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "accepted", submitted_at = UTC_TIMESTAMP(),
                    decided_at = UTC_TIMESTAMP(), row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $regular['submission_id']]);
        $this->db->pdo()->prepare(
            'UPDATE payroll_obligations
                SET status = "fulfilled", row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $regular['obligation_id']]);

        $correctionRevisionId = $this->correctionRevision();
        $this->revisionId = $correctionRevisionId;
        $employeeId = (int) $this->db->pdo()->query(
            'SELECT employee_id FROM payroll_employments WHERE id = ' . $this->employmentId,
        )->fetchColumn();
        $this->storeResult($employeeId);

        $correction = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $correctionRevisionId,
            '111',
        );
        $submission = $this->repository->findSubmission(
            $this->supplierId,
            $correction['submission_id'],
        );
        self::assertIsArray($submission);
        self::assertSame('correction', $submission['submission_kind']);
        self::assertSame($regular['submission_id'], $submission['corrects_submission_id']);
        self::assertStringContainsString(
            '<typPrehledu>opravny</typPrehledu>',
            $this->submissions->artifactBytes(
                $this->supplierId,
                (int) $correction['artifact_id'],
            ),
        );
        $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET status = "accepted", submitted_at = UTC_TIMESTAMP(),
                    decided_at = UTC_TIMESTAMP(), row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, $correction['submission_id']]);
        $replayed = $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $correctionRevisionId,
            '111',
        );
        self::assertSame($correction['submission_id'], $replayed['submission_id']);
        self::assertFalse($replayed['created']);
    }

    public function testInsurerWithoutAnOverviewIsRefused(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->service->preparePaymentOverview(
            $this->supplierId,
            'production',
            $this->revisionId,
            '205',
        );
    }

    public function testUnknownInsurerCodeIsRefusedBeforeAnythingIsWritten(): void
    {
        try {
            $this->service->preparePaymentOverview(
                $this->supplierId,
                'production',
                $this->revisionId,
                '999',
            );
            self::fail('Kód mimo datovou větu nesmí projít.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_insurer_code_unknown', $e->errorCode);
        }
    }

    public function testMissingBusinessIdStopsTheSubmissionWithAnActionableReason(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE supplier SET ic = "" WHERE id = ?',
        )->execute([$this->supplierId]);

        try {
            $this->service->preparePaymentOverview(
                $this->supplierId,
                'production',
                $this->revisionId,
                '111',
            );
            self::fail('Bez IČO nelze sestavit číslo plátce.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_payer_business_id_missing', $e->errorCode);
        }
    }

    public function testMissingInsurerPayerNumberStopsTheSubmission(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_institution_accounts
                SET variable_symbol = NULL, row_version = row_version + 1
              WHERE supplier_id = ?',
        )->execute([$this->supplierId]);

        try {
            $this->service->preparePaymentOverview(
                $this->supplierId,
                'production',
                $this->revisionId,
                '111',
            );
            self::fail('Bez čísla plátce u konkrétní pojišťovny nesmí podání vzniknout.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_payer_number_missing', $e->errorCode);
        }
    }

    /**
     * Jádro cesty HOZ: povinnost → payload → XML → artefakt → stažení.
     * Bez připnutého XSD zůstane v `draft` s výhradou, stejně jako PPZ.
     */
    public function testBulkNotificationFreezesTheArtefactAndStopsBeforeValidated(): void
    {
        $result = $this->service->prepareBulkNotification(
            $this->supplierId,
            'production',
            '2026-03',
            '111',
        );

        self::assertTrue($result['created']);
        self::assertSame('HOZ_2026', $result['agenda_code']);
        self::assertSame('2026-03', $result['period']);
        self::assertSame('111', $result['insurer_code']);
        self::assertSame(1, $result['changes_count']);
        self::assertSame('2026-03-09', $result['deadline']['due_on']);
        self::assertGreaterThan(0, $result['artifact_id']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $result['artifact_sha256'],
        );

        $bundleAvailable = $this->schemas->isBundleAvailable();
        self::assertSame($bundleAvailable, $result['schema_validated']);
        self::assertSame(
            $bundleAvailable ? 'ready' : 'draft',
            $result['status'],
        );

        $xml = $this->submissions->artifactBytes(
            $this->supplierId,
            (int) $result['artifact_id'],
        );
        self::assertStringContainsString(
            '<hromadneOznameniZamestnavatele',
            $xml,
        );
        self::assertStringContainsString(
            '<kodzmeny>P</kodzmeny>',
            $xml,
        );
        self::assertStringContainsString(
            '<cisloPojistence>9052224321</cisloPojistence>',
            $xml,
        );
        self::assertStringContainsString('<jmeno>Jana</jmeno>', $xml);
        self::assertStringContainsString('<prijmeni>Nováková</prijmeni>', $xml);
        self::assertSame($result['artifact_sha256'], hash('sha256', $xml));

        if (!$bundleAvailable) {
            $issues = $this->db->pdo()->prepare(
                'SELECT severity, validation_stage, issue_code
                   FROM payroll_submission_issues
                  WHERE supplier_id = ? AND submission_id = ?',
            );
            $issues->execute([$this->supplierId, $result['submission_id']]);
            $issue = $issues->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($issue);
            self::assertSame('blocker', $issue['severity']);
            self::assertSame('xsd', $issue['validation_stage']);
        }
    }

    public function testPreparingTheSameBulkNotificationTwiceReplaysInsteadOfDuplicating(): void
    {
        $first = $this->service->prepareBulkNotification(
            $this->supplierId,
            'production',
            '2026-03',
            '111',
        );
        $second = $this->service->prepareBulkNotification(
            $this->supplierId,
            'production',
            '2026-03',
            '111',
        );

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame($first['submission_id'], $second['submission_id']);
        self::assertSame($first['artifact_id'], $second['artifact_id']);
        self::assertSame($first['artifact_sha256'], $second['artifact_sha256']);
    }

    public function testBulkNotificationDownloadRebuildsFromSourceWithoutPreparing(): void
    {
        $artifact = $this->service->bulkNotificationDownload(
            $this->supplierId,
            '2026-03',
            '111',
        );

        self::assertSame('application/xml', $artifact['mime_type']);
        self::assertStringContainsString(
            '<hromadneOznameniZamestnavatele',
            $artifact['bytes'],
        );
        self::assertSame(
            $artifact['sha256'],
            hash('sha256', $artifact['bytes']),
        );
    }

    public function testBulkNotificationExcludesInsurerWithoutMatchingDuties(): void
    {
        try {
            $this->service->prepareBulkNotification(
                $this->supplierId,
                'production',
                '2026-03',
                '205',
            );
            self::fail('Pojišťovna bez zahrnuté povinnosti nesmí vyrobit prázdnou dávku.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_bulk_notification_empty', $e->errorCode);
        }
    }

    public function testBulkNotificationFailsClosedWithoutIdentityFirstAndLastName(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_identity_history
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $this->employeeId]);

        try {
            $this->service->prepareBulkNotification(
                $this->supplierId,
                'production',
                '2026-03',
                '111',
            );
            self::fail('Bez historické identity nelze sestavit větu HOZ.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_change_identity_missing', $e->errorCode);
        }
    }

    public function testBulkNotificationFailsClosedWithoutInsuranceNumber(): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?',
        )->execute([$this->supplierId, $this->employeeId]);

        try {
            $this->service->prepareBulkNotification(
                $this->supplierId,
                'production',
                '2026-03',
                '111',
            );
            self::fail('Bez rodného čísla ani EČP nelze sestavit číslo pojištěnce.');
        } catch (HealthNotificationException $e) {
            self::assertSame('zp_change_insurance_number_missing', $e->errorCode);
        }
    }

    // --- fixtures --------------------------------------------------------

    private function insertLegacyObligation(
        string $dueOn,
        ?string $deadlineTriggerHash = null,
    ): int {
        $sourceReference = 'payroll_health_notification:'
            . $this->employmentId . ':employment_start:2026-03-01';
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-health-notification-obligation.v1',
            'employment_id' => $this->employmentId,
            'kind' => 'employment_start',
            'insurer_code' => '111',
            'occurred_on' => '2026-03-01',
        ]));
        $policy = new HealthNotificationDeadlinePolicy();
        $rulesetHash = $policy->rulesetHash();
        $requestFingerprint = hash('sha256', CanonicalJson::encode([
            'schema_reference' => 'payroll-obligation-register.v1',
            'supplier_id' => $this->supplierId,
            'environment' => 'production',
            'agenda_code' => 'HOZ_2026',
            'subject_type' => 'employment',
            'subject_reference' => 'employment:' . $this->employmentId,
            'period_start' => '2026-03-01',
            'period_end' => $dueOn,
            'obligation_kind' => 'regular',
            'channel' => 'health_portal',
            'source_event_type' => 'payroll_health_notification',
            'source_event_reference' => $sourceReference,
            'source_event_hash' => $sourceHash,
            'earliest_submission_on' => '2026-03-01',
            'due_on' => $dueOn,
            'calendar_basis' => 'calendar_days',
            'ruleset_id' => HealthNotificationDeadlinePolicy::RULESET_ID,
            'ruleset_hash' => $rulesetHash,
            'responsible_user_id' => null,
            'fiction_delivery_days' => null,
        ]));
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_obligations
                (supplier_id, environment, agenda_code, subject_type,
                 subject_reference, period_start, period_end,
                 obligation_kind, preferred_channel, source_event_type,
                 source_event_reference, source_event_hash,
                 request_fingerprint, idempotency_key_hash)
             VALUES (?, "production", "HOZ_2026", "employment", ?,
                     "2026-03-01", ?, "regular", "health_portal",
                     "payroll_health_notification", ?, ?, ?, ?)',
        )->execute([
            $this->supplierId,
            'employment:' . $this->employmentId,
            $dueOn,
            $sourceReference,
            $sourceHash,
            $requestFingerprint,
            hash(
                'sha256',
                'health-notification:production:' . $sourceHash,
                true,
            ),
        ]);
        $obligationId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis,
                 ruleset_id, ruleset_hash, trigger_event_hash)
             VALUES (?, "production", ?, "regular", "2026-03-01",
                     ?, "calendar_days", ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $obligationId,
            $dueOn,
            HealthNotificationDeadlinePolicy::RULESET_ID,
            $rulesetHash,
            $deadlineTriggerHash ?? $sourceHash,
        ]);

        return $obligationId;
    }

    private function employee(
        PDO $pdo,
        string $fullName = 'Syntetická osoba ZP',
    ): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, ?, "employee", "hpp",
                     1, 1, 0, 10000, 0, 1)',
        )->execute([$this->supplierId, $fullName]);

        return (int) $pdo->lastInsertId();
    }

    private function employment(
        PDO $pdo,
        int $employeeId,
        string $code = 'ZP-1',
    ): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status,
                 is_primary, start_date)
             VALUES (?, ?, ?, "employment", "active", 1, "2026-03-01")',
        )->execute([$this->supplierId, $employeeId, $code]);

        return (int) $pdo->lastInsertId();
    }

    private function identity(
        PDO $pdo,
        int $employeeId,
        string $firstName,
        string $lastName,
    ): void {
        $pdo->prepare(
            'INSERT INTO payroll_person_identity_history
                (supplier_id, employee_id, full_name, first_name, last_name,
                 effective_from)
             VALUES (?, ?, ?, ?, ?, "2026-01-01")'
        )->execute([
            $this->supplierId,
            $employeeId,
            $firstName . ' ' . $lastName,
            $firstName,
            $lastName,
        ]);
    }

    private function insertIdentifier(
        PDO $pdo,
        int $employeeId,
        string $type,
        string $value,
    ): void {
        $pdo->prepare(
            "INSERT INTO payroll_person_identifiers
                (supplier_id, employee_id, identifier_type,
                 value_ciphertext, value_hash, value_masked)
             VALUES (?, ?, ?, 'enc:v2:pending', ?, '')"
        )->execute([
            $this->supplierId,
            $employeeId,
            $type,
            random_bytes(32),
        ]);
        $id = (int) $pdo->lastInsertId();
        $field = $type === 'foreign_tax_identifier'
            ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
            : PayrollSensitiveField::PERSONAL_IDENTIFIER;
        $sealed = $this->sensitive->seal($value, $field, $this->supplierId, $id);
        $pdo->prepare(
            'UPDATE payroll_person_identifiers
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $sealed->ciphertext,
            $sealed->lookupHash,
            $sealed->masked,
            $this->supplierId,
            $id,
        ]);
    }

    private function coverage(PDO $pdo, int $employeeId): void
    {
        $pdo->prepare(
            'INSERT INTO payroll_person_health_coverage_history
                (supplier_id, employee_id, jurisdiction, insurer_status,
                 insurer_code, insurer_evidence_reference, effective_from)
             VALUES (?, ?, "czech_regime_verified", "verified", "111",
                     "synteticky-doklad", "2026-01-01")',
        )->execute([$this->supplierId, $employeeId]);
    }

    private function healthInsurerAccount(PDO $pdo): void
    {
        $actorId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')
            ->fetchColumn();
        if ($actorId <= 0) {
            throw new \RuntimeException('Chybí syntetický uživatel pro ověření účtu.');
        }
        $pdo->prepare(
            'INSERT INTO payroll_institutions
                (supplier_id, institution_type, institution_code)
             VALUES (?, "health_insurer", "111")',
        )->execute([$this->supplierId]);
        $institutionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_institution_accounts
                (supplier_id, institution_id, institution_name,
                 bank_account_ciphertext, bank_account_hash,
                 bank_account_masked, currency_code, variable_symbol,
                 valid_from, source_kind, source_reference, verified_on,
                 verified_by, created_by, updated_by)
             VALUES (?, ?, "VZP", "synthetic", ?, "synthetic",
                     "CZK", "1234567800", "2026-01-01",
                     "user_verified", "synthetic-test", "2026-01-01",
                     ?, ?, ?)',
        )->execute([
            $this->supplierId,
            $institutionId,
            hash('sha256', 'synthetic-vzp-account', true),
            $actorId,
            $actorId,
            $actorId,
        ]);
    }

    private function revision(PDO $pdo, int $employeeId): int
    {
        $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, period_start, payment_date, status,
                 current_revision_no)
             VALUES (?, "2026-06-01", "2026-07-10", "approved", 1)',
        )->execute([$this->supplierId]);
        $runId = (int) $pdo->lastInsertId();
        $input = '{"schema_version":"payroll-run-input.v2"}';
        $result = '{"schema_version":"payroll-run-result.v2"}';
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind, status,
                 schema_version, ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 1, "regular", "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())',
        )->execute([
            $this->supplierId,
            $runId,
            str_repeat('a', 64),
            $input,
            hash('sha256', $input),
            $result,
            hash('sha256', $result),
            hash(
                'sha256',
                "synthetic-zp-submission:{$this->supplierId}:{$runId}",
                true,
            ),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, employee_id, status)
             VALUES (?, ?, ?, "calculated")',
        )->execute([$this->supplierId, $revisionId, $employeeId]);

        return $revisionId;
    }

    private function correctionRevision(): int
    {
        $pdo = $this->db->pdo();
        $regular = $pdo->query(
            'SELECT run_id, input_snapshot_json, input_snapshot_hash,
                    result_snapshot_json, result_snapshot_hash
               FROM payroll_run_revisions WHERE id = ' . $this->revisionId,
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($regular);
        $pdo->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, revision_kind,
                 previous_revision_id, status, schema_version,
                 ruleset_manifest_hash, input_snapshot_json,
                 input_snapshot_hash, result_snapshot_json,
                 result_snapshot_hash, idempotency_key_hash, approved_at)
             VALUES (?, ?, 2, "correction", ?, "approved",
                     "payroll-run-input.v2", ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $this->supplierId,
            (int) $regular['run_id'],
            $this->revisionId,
            str_repeat('a', 64),
            $regular['input_snapshot_json'],
            $regular['input_snapshot_hash'],
            $regular['result_snapshot_json'],
            $regular['result_snapshot_hash'],
            hash('sha256', 'synthetic-zp-correction:' . $this->revisionId, true),
        ]);
        $revisionId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'UPDATE payroll_runs
                SET current_revision_no = 2
              WHERE supplier_id = ? AND id = ?'
        )->execute([$this->supplierId, (int) $regular['run_id']]);
        $pdo->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, period_start, employee_id, status)
             SELECT supplier_id, ?, period_start, employee_id, "calculated"
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ?'
        )->execute([$revisionId, $this->supplierId, $this->revisionId]);

        return $revisionId;
    }

    private function storeResult(int $employeeId): void
    {
        (new PayrollStatutoryResultRepository($this->db))->store(
            $this->supplierId,
            $this->revisionId,
            'health_insurance',
            'payroll-health-result.v1',
            'calculated',
            'cz-health-2026',
            str_repeat('b', 64),
            ['schema_version' => 'payroll-run-input.v2'],
            [
                'calculation_date' => '2026-06-30',
                'status' => 'calculated',
                'assessment_base_minor_units' => 1_000_000,
                'employee_contribution_minor_units' => 45_000,
                'employer_contribution_minor_units' => 90_000,
                'total_contribution_minor_units' => 135_000,
                'insurer_liabilities' => [[
                    'insurer_code' => '111',
                    'person_count' => 1,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ]],
                'issues' => [],
                'ruleset_id' => 'cz-health-2026',
                'ruleset_hash' => str_repeat('b', 64),
            ],
            [[
                'employee_id' => $employeeId,
                'result_status' => 'calculated',
                'input_snapshot' => [
                    'employee' => [
                        'id' => $employeeId,
                        'full_name' => 'Syntetická osoba ZP',
                    ],
                ],
                'result_snapshot' => [
                    'person_id' => "employee:{$employeeId}",
                    'status' => 'calculated',
                    'insurer_status' => 'verified',
                    'insurer_code' => '111',
                    'ppz_counted' => true,
                    'assessment_base_minor_units' => 1_000_000,
                    'employee_contribution_minor_units' => 45_000,
                    'employer_contribution_minor_units' => 90_000,
                    'total_contribution_minor_units' => 135_000,
                ],
                'relationships' => [],
            ]],
            null,
        );
    }
}
