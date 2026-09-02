<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollAbsenceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Payroll\Absence\PayrollLeaveInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollSicknessInputMaterializer;
use MyInvoice\Service\Payroll\Absence\PayrollWageProrationService;
use MyInvoice\Service\Payroll\Run\PayrollRunCalculator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollAbsenceApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollAbsenceAction $action;
    private PayrollSicknessInputMaterializer $sicknessInputs;
    private PayrollLeaveInputMaterializer $leaveInputs;
    private PayrollRunCalculator $runCalculator;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->action = $container->get(PayrollAbsenceAction::class);
            $this->sicknessInputs = $container->get(PayrollSicknessInputMaterializer::class);
            $this->leaveInputs = $container->get(PayrollLeaveInputMaterializer::class);
            $this->runCalculator = $container->get(PayrollRunCalculator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        foreach ([
            'payroll_employments', 'payroll_shifts', 'payroll_absences',
            'payroll_average_earning_snapshots', 'payroll_leave_ledger',
        ] as $table) {
            if (!$this->db->hasTable($table)) {
                $this->markTestSkipped("Chybí integrační tabulka {$table}.");
            }
        }
        $pdo = $this->db->pdo();
        $sourceSupplier = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplier === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplier);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
        $this->employmentId = $this->createEmployment($this->supplierId, 'Syntetický zaměstnanec');
        $this->createEmployment($this->otherSupplierId, 'Jiný syntetický zaměstnanec');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
            $this->db->close();
        }
    }

    public function testAverageAbsenceVacationLedgerAndCancellationAreAuditable(): void
    {
        $averageId = $this->createApprovedAverage();

        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);
        $created = $this->createAbsence($averageId);
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        $overlap = $this->createAbsence($averageId);
        self::assertSame(409, $overlap->getStatusCode());
        self::assertSame('absence_overlap', $this->json($overlap)['error']['code']);

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $approved->getStatusCode());
        $approvedAbsence = $this->json($approved)['absence'];

        $balance = $this->db->pdo()->prepare(
            'SELECT SUM(minutes_delta) FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = 2026'
        );
        $balance->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(-480, (int) $balance->fetchColumn());

        $cancelled = $this->action->cancel(
            $this->request('POST')->withParsedBody(['row_version' => $approvedAbsence['row_version']]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode());
        self::assertTrue($this->json($cancelled)['absence']['correction_pending']);
        $balance->execute([$this->supplierId, $this->employmentId]);
        self::assertSame(0, (int) $balance->fetchColumn());

        $entryTypes = $this->db->pdo()->prepare(
            'SELECT entry_type FROM payroll_leave_ledger
              WHERE supplier_id = ? AND source_absence_id = ? ORDER BY id'
        );
        $entryTypes->execute([$this->supplierId, $absence['id']]);
        self::assertSame(['taken', 'reversal'], $entryTypes->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testDpnRequiresManualEligibilityFlagsAndStoresShiftTrace(): void
    {
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);
        $payload = $this->absencePayload($averageId);
        $payload['absence_type'] = 'dpn';
        $payload['date_to'] = '2026-06-28';
        $created = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        $missingReview = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(422, $missingReview->getStatusCode());

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $approved->getStatusCode());
        $calculation = $this->json($approved)['calculation'];
        self::assertSame('manual_review', $calculation['support_status']);
        self::assertSame(480, $calculation['segments'][0]['eligible_minutes']);
        self::assertGreaterThan(0, $calculation['compensation_minor']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $calculation['ruleset_hash']);
    }

    public function testApprovedDpnMaterializesIdempotentCanonicalInputAndItsRunBases(): void
    {
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);
        $payload = $this->absencePayload($averageId);
        $payload['absence_type'] = 'dpn';
        $created = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        $calculation = $this->json($approved)['calculation'];

        $materialized = $this->db->pdo()->prepare(
            'SELECT input.*, materialization.id AS materialization_id,
                    materialization.source_snapshot_json
               FROM payroll_sickness_input_materializations materialization
               JOIN payroll_inputs input
                 ON input.supplier_id = materialization.supplier_id
                AND input.id = materialization.input_id
              WHERE materialization.supplier_id = ?
                AND materialization.sickness_event_id = ?
                AND materialization.materialization_kind = "original"',
        );
        $materialized->execute([$this->supplierId, $calculation['id']]);
        $input = $materialized->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($input);
        self::assertSame('absence', $input['source_kind']);
        self::assertSame('approved', $input['status']);
        self::assertSame((int) $calculation['compensation_minor'], (int) $input['amount_minor']);
        self::assertNotNull($input['component_snapshot_json']);
        $source = json_decode((string) $input['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('sickness_compensation.v1', $source['kind']);
        self::assertSame($calculation['id'], $source['sickness_event_id']);

        $component = json_decode((string) $input['component_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $run = $this->runCalculator->calculate([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => (int) $input['employee_id']],
                'employments' => [[
                    'employment' => ['id' => $this->employmentId],
                    'inputs' => [[
                        'id' => (int) $input['id'],
                        'amount_minor' => (int) $input['amount_minor'],
                        'component' => $component,
                    ]],
                ]],
            ]],
        ]);
        self::assertSame('NAHRADA_MZDY_DPN', $component['code']);
        self::assertSame('exempt', $component['tax_treatment']);
        self::assertSame('statutory_exempt', $component['exemption_basis']);
        self::assertSame((int) $input['amount_minor'], $run['totals']['cash_payable_minor']);
        self::assertSame(0, $run['totals']['tax_base_minor']);
        self::assertSame(0, $run['totals']['social_base_minor']);
        self::assertSame(0, $run['totals']['health_base_minor']);
        self::assertSame((int) $input['amount_minor'], $run['totals']['enforcement_base_minor']);
        self::assertSame((int) $input['amount_minor'], $run['totals']['jmhz_amount_minor']);

        $replay = $this->sicknessInputs->materialize(
            $this->supplierId,
            (int) $calculation['id'],
            $this->userId,
        );
        self::assertSame(0, $replay['created_count']);
        self::assertSame(1, $replay['replayed_count']);

        $approvedAbsence = $this->json($approved)['absence'];
        $cancelled = $this->action->cancel(
            $this->request('POST')->withParsedBody([
                'row_version' => $approvedAbsence['row_version'],
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());
        self::assertTrue($this->json($cancelled)['absence']['correction_pending']);

        $reversal = $this->db->pdo()->prepare(
            'SELECT reversal_input.id, reversal_input.amount_minor, reversal_input.status,
                    reversal_input.period_start, reversal_input.source_period_start,
                    original_input.component_snapshot_hash = reversal_input.component_snapshot_hash
                        AS keeps_component_snapshot
               FROM payroll_sickness_input_materializations reversal
               JOIN payroll_sickness_input_materializations original
                 ON original.supplier_id = reversal.supplier_id
                AND original.id = reversal.reverses_materialization_id
               JOIN payroll_inputs original_input
                 ON original_input.supplier_id = original.supplier_id
                AND original_input.id = original.input_id
               JOIN payroll_inputs reversal_input
                 ON reversal_input.supplier_id = reversal.supplier_id
                AND reversal_input.id = reversal.input_id
              WHERE reversal.supplier_id = ?
                AND reversal.sickness_event_id = ?
                AND reversal.materialization_kind = "reversal"',
        );
        $reversal->execute([$this->supplierId, $calculation['id']]);
        $reversalInput = $reversal->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($reversalInput);
        self::assertSame(-(int) $input['amount_minor'], (int) $reversalInput['amount_minor']);
        self::assertSame('approved', $reversalInput['status']);
        self::assertSame(1, (int) $reversalInput['keeps_component_snapshot']);
        // Oprava nároku patří MĚSÍCI, ve kterém nárok vznikl. Přesunout korekci
        // do dalšího období by zkreslilo oba měsíce i měsíční hlášení, které se
        // za původní měsíc podává opravným hlášením.
        self::assertSame((string) $input['period_start'], (string) $reversalInput['period_start']);
        self::assertSame((string) $input['period_start'], (string) $reversalInput['source_period_start']);

        // Původní vstup a jeho reverzace ve stejné revizi se vyruší do nuly
        // a nezanechají ani vyměřovací základ, ani závazek k výplatě.
        $corrective = $this->runCalculator->calculate([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => (int) $input['employee_id']],
                'employments' => [[
                    'employment' => ['id' => $this->employmentId],
                    'inputs' => [
                        [
                            'id' => (int) $input['id'],
                            'amount_minor' => (int) $input['amount_minor'],
                            'component' => $component,
                        ],
                        [
                            'id' => (int) $reversalInput['id'],
                            'amount_minor' => (int) $reversalInput['amount_minor'],
                            'component' => $component,
                        ],
                    ],
                ]],
            ]],
        ]);
        self::assertSame(0, $corrective['totals']['cash_payable_minor']);
        self::assertSame(0, $corrective['totals']['tax_base_minor']);
        self::assertSame(0, $corrective['totals']['social_base_minor']);
        self::assertSame(0, $corrective['totals']['health_base_minor']);

        try {
            $this->db->pdo()->prepare(
                'UPDATE payroll_sickness_input_materializations
                    SET period_start = "2026-07-01"
                  WHERE supplier_id = ? AND id = ?',
            )->execute([$this->supplierId, $input['materialization_id']]);
            self::fail('Auditní vazbu DPN nelze přepsat.');
        } catch (\PDOException $exception) {
            self::assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    /**
     * § 222 odst. 1 ZP — za dobu čerpání dovolené náleží náhrada mzdy ve výši
     * průměrného výdělku. Dřív z ní na pásce nebylo nic: kniha dovolené odepsala
     * hodiny a peněžní půlka schválení chyběla úplně.
     *
     * Průměr testu je 12 000 000 h / 9 600 minut = 750 Kč/h, směna 480 minut,
     * takže náhrada je 6 000 Kč. Zrušení dovolené vytvoří zápornou korekci ve
     * STEJNÉM měsíci — oprava nároku patří období, ve kterém nárok vznikl.
     */
    public function testApprovedVacationMaterializesCompensationInputAndReversesIt(): void
    {
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);

        $created = $this->createAbsence($averageId);
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        $input = $this->leaveInput("leave:{$absence['id']}:2026-06-01:original");
        self::assertIsArray($input, 'Schválená dovolená nezaložila mzdový vstup náhrady.');
        self::assertSame('absence', $input['source_kind']);
        self::assertSame('approved', $input['status']);
        self::assertSame('2026-06-01', (string) $input['period_start']);
        self::assertSame(600_000, (int) $input['amount_minor']);
        self::assertSame(8_000, (int) $input['quantity_milliunits']);

        $source = json_decode((string) $input['source_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('leave_compensation.v1', $source['kind']);
        self::assertSame(480, $source['minutes']);
        self::assertSame(75_000, $source['average_hourly_minor']);
        self::assertSame($averageId, $source['average_snapshot_id']);
        self::assertSame('zp-222-1', $source['entitlement_basis']);

        $component = json_decode((string) $input['component_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('NAHRADA_MZDY_DOVOLENA', $component['code']);
        self::assertSame('included', $component['tax_treatment']);
        // § 353 ZP — průměrný výdělek se zjišťuje z hrubé mzdy za ODPRACOVANOU
        // dobu, takže náhrada za dovolenou do dalšího průměru nevstupuje.
        self::assertSame('excluded', $component['average_earning_treatment']);

        $run = $this->runCalculator->calculate([
            'schema_version' => 'payroll-run-input.v2',
            'people' => [[
                'employee' => ['id' => (int) $input['employee_id']],
                'employments' => [[
                    'employment' => ['id' => $this->employmentId],
                    'inputs' => [[
                        'id' => (int) $input['id'],
                        'amount_minor' => (int) $input['amount_minor'],
                        'component' => $component,
                    ]],
                ]],
            ]],
        ]);
        self::assertSame(600_000, $run['totals']['tax_base_minor']);
        self::assertSame(600_000, $run['totals']['social_base_minor']);
        self::assertSame(600_000, $run['totals']['health_base_minor']);

        $replay = $this->leaveInputs->materialize(
            $this->supplierId,
            $this->db->pdo()->query(
                'SELECT * FROM payroll_absences WHERE id = ' . (int) $absence['id'],
            )->fetch(\PDO::FETCH_ASSOC) + ['average_hourly_minor' => 75_000],
            $this->userId,
        );
        self::assertSame([], $replay['created']);
        self::assertCount(1, $replay['replayed']);

        $approvedAbsence = $this->json($approved)['absence'];
        $cancelled = $this->action->cancel(
            $this->request('POST')->withParsedBody(['row_version' => $approvedAbsence['row_version']]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $cancelled->getStatusCode(), (string) $cancelled->getBody());

        $reversal = $this->leaveInput("leave:{$absence['id']}:2026-06-01:reversal");
        self::assertIsArray($reversal, 'Zrušená dovolená nezaložila korekci náhrady.');
        self::assertSame('correction', $reversal['source_kind']);
        self::assertSame('approved', $reversal['status']);
        self::assertSame('2026-06-01', (string) $reversal['period_start']);
        self::assertSame('2026-06-01', (string) $reversal['source_period_start']);
        self::assertSame(-600_000, (int) $reversal['amount_minor']);
        self::assertSame(
            (string) $input['component_snapshot_hash'],
            (string) $reversal['component_snapshot_hash'],
        );
    }

    /**
     * Svátek uvnitř okna náhrady při DPN musí ze základní mzdy vypadnout.
     *
     * § 192 odst. 1 ZP za něj náhradu přiznává, i když na něj směna rozvržená
     * není. Kdyby zůstal i v základní mzdě, byl by zaplacený dvakrát. Fond ho
     * naopak neobsahuje — mzda se za svátek jinak nekrátí (§ 115 odst. 3 ZP) —
     * takže nahrazené minuty jsou širší než fond o právě ten svátek.
     *
     * Červenec 2026 má 23 rozvržených dnů; 6. 7. je svátek, takže fond je
     * 22 x 480 = 10 560 minut. Nemoc 6. až 7. 7. vezme 960 minut (svátek
     * i směnu), zbývá 9 600 a 42 000 x 9 600/10 560 = 38 181,81 → 38 182 Kč.
     */
    public function testHolidayInsideSicknessLeavesTheBaseWageByTheObligationFund(): void
    {
        $container = Bootstrap::buildApp()->getContainer();
        $proration = $container->get(PayrollWageProrationService::class);
        $this->workCalendar();
        $this->insertPublishedShift('2026-07-07 06:00:00', '2026-07-07 14:30:00', 30);

        $averageId = $this->createApprovedAverage(3);
        $payload = $this->absencePayload($averageId);
        $payload['absence_type'] = 'dpn';
        $payload['date_from'] = '2026-07-06';
        $payload['date_to'] = '2026-07-07';
        $created = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
                'first_day_fully_worked' => false,
                'insurance_eligibility_confirmed' => true,
                'conflicting_benefit_excluded' => true,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());

        $result = $proration->forMonth($this->supplierId, $this->employmentId, '2026-07', 4_200_000);

        self::assertTrue($result['supported']);
        self::assertSame(10_560, $result['fund_minutes']);
        self::assertSame(['sickness_compensation' => 960], $result['replaced_minutes_by_title']);
        self::assertSame(3_818_200, $result['amount_minor']);
    }

    private function workCalendar(): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_work_calendars
                (supplier_id, employment_id, name, week_pattern, weekly_minutes, valid_from)
             VALUES (?, ?, "Pondělí až pátek", ?, 2400, "2026-01-01")'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            json_encode([1 => 480, 2 => 480, 3 => 480, 4 => 480, 5 => 480, 6 => 0, 7 => 0]),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function leaveInput(string $externalId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs WHERE supplier_id = ? AND external_id = ?',
        );
        $stmt->execute([$this->supplierId, $externalId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function testVacationRejectsAverageFromDifferentQuarter(): void
    {
        $averageId = $this->createApprovedAverage();
        $payload = $this->absencePayload($averageId);
        $payload['date_from'] = '2026-07-01';
        $payload['date_to'] = '2026-07-01';

        $response = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
    }

    public function testVacationCrossingQuarterMustBeSplit(): void
    {
        $averageId = $this->createApprovedAverage();
        $payload = $this->absencePayload($averageId);
        $payload['date_from'] = '2026-06-30';
        $payload['date_to'] = '2026-07-01';

        $response = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
    }

    public function testLongVacationDeductsPublishedShiftsAfterFourteenthDay(): void
    {
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-01 06:00:00', '2026-06-01 14:30:00', 30);
        $this->insertPublishedShift('2026-06-16 06:00:00', '2026-06-16 14:30:00', 30);
        $payload = $this->absencePayload($averageId);
        $payload['date_from'] = '2026-06-01';
        $payload['date_to'] = '2026-06-16';
        $created = $this->action->create(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        $absence = $this->json($created)['absence'];

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );

        self::assertSame(200, $approved->getStatusCode());
        self::assertSame(
            -960,
            $this->json($approved)['calculation']['minutes_delta'],
        );
    }

    public function testSessionRbacAndTenantScopeFailClosed(): void
    {
        $bearer = $this->action->context(
            $this->request('GET', authMethod: 'bearer'),
            new Response(),
        );
        self::assertSame(403, $bearer->getStatusCode());
        self::assertSame('session_required', $this->json($bearer)['error']['code']);

        $readonly = $this->action->create(
            $this->request('POST', role: 'readonly')->withParsedBody($this->absencePayload(1)),
            new Response(),
        );
        self::assertSame(403, $readonly->getStatusCode());

        $otherTenant = $this->action->list(
            $this->request('GET', supplierId: $this->otherSupplierId)->withQueryParams([
                'from' => '2026-06-01',
                'to' => '2026-06-30',
            ]),
            new Response(),
        );
        self::assertSame([], $this->json($otherTenant)['absences']);

        $this->db->pdo()->prepare('UPDATE supplier SET payroll_enabled = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
        $disabled = $this->action->context($this->request('GET'), new Response());
        self::assertSame(403, $disabled->getStatusCode());
        self::assertSame('payroll_disabled', $this->json($disabled)['error']['code']);
    }

    public function testBearerCanEnterAbsenceButCannotApproveOrCancelIt(): void
    {
        $averageId = $this->createApprovedAverage();

        $listed = $this->action->list(
            $this->request('GET', authMethod: 'bearer')->withQueryParams([
                'from' => '2026-06-01',
                'to' => '2026-06-30',
            ]),
            new Response(),
        );
        self::assertSame(200, $listed->getStatusCode());

        $averages = $this->action->averages(
            $this->request('GET', authMethod: 'bearer')->withQueryParams([
                'employment_id' => $this->employmentId,
            ]),
            new Response(),
        );
        self::assertSame(200, $averages->getStatusCode());

        $payload = $this->absencePayload($averageId);
        $payload['date_from'] = '2026-06-17';
        $payload['date_to'] = '2026-06-17';
        $created = $this->action->create(
            $this->request('POST', authMethod: 'bearer')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];
        self::assertSame('requested', $absence['status']);

        $decision = $this->action->decision(
            $this->request('POST', authMethod: 'bearer')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(403, $decision->getStatusCode());
        self::assertSame('session_required', $this->json($decision)['error']['code']);

        $cancel = $this->action->cancel(
            $this->request('POST', authMethod: 'bearer')->withParsedBody([
                'row_version' => $absence['row_version'],
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(403, $cancel->getStatusCode());
        self::assertSame('session_required', $this->json($cancel)['error']['code']);
    }

    /**
     * Stav 3 — nárok na rok vůbec není určený. Zůstatek není nula, je neznámý,
     * takže se dovolená schválí bez ptaní a odpověď nese jen upozornění. Tohle je
     * stav každé firmy hned po zavedení modulu; ptát se tu na potvrzení by
     * znamenalo proklikat u 500 zaměstnanců 500 dialogů.
     */
    public function testVacationWithoutDeterminedEntitlementPassesWithWarning(): void
    {
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);

        $approved = $this->approveVacation($averageId);

        self::assertSame(200, $approved->getStatusCode());
        $calculation = $this->json($approved)['calculation'];
        self::assertSame(-480, (int) $calculation['minutes_delta']);
        self::assertSame(['leave_entitlement_not_determined'], $calculation['warnings']);
    }

    /** Stav 1 — nárok je určený a zůstatek stačí: projde a nevaruje. */
    public function testVacationWithinDeterminedEntitlementPassesWithoutWarning(): void
    {
        $this->createEntitlement(124_800);
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);

        $approved = $this->approveVacation($averageId);

        self::assertSame(200, $approved->getStatusCode());
        self::assertSame([], $this->json($approved)['calculation']['warnings']);
    }

    /**
     * Stav 2 — nárok je určený, ale je vyčerpaný. Tady je potvrzení na místě:
     * přeplatek by se jinak poznal až při vypořádání a strhl by se zaměstnanci
     * ze mzdy.
     */
    public function testVacationBeyondDeterminedEntitlementNeedsConfirmation(): void
    {
        $this->createEntitlement(124_800);
        $this->drainLeaveBalance(9_600);
        $averageId = $this->createApprovedAverage();
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);

        $created = $this->createAbsence($averageId);
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        $blocked = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(409, $blocked->getStatusCode());
        $error = $this->json($blocked)['error'];
        self::assertSame('leave_overdraw_confirmation_required', $error['code']);
        self::assertSame(0, $error['balance_minutes']);
        self::assertSame(480, $error['requested_minutes']);

        $confirmed = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
                'overdraw_confirmed' => true,
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $confirmed->getStatusCode());
        self::assertSame(-480, (int) $this->json($confirmed)['calculation']['minutes_delta']);
    }

    /**
     * W28 / V-16 — § 223 odst. 1 ZP: „Zaměstnavatel může dovolenou krátit jen
     * za neomluveně zameškanou směnu, a to o počet neomluveně zameškaných
     * hodin."
     *
     * Bez evidované neomluvené absence není o co krátit. Dřív kniha dovolené
     * přijala libovolné záporné číslo, protože `payroll_absences` druh
     * `unexcused` vůbec neznal (doplnila ho migrace 1636).
     */
    public function testShorteningWithoutRecordedUnexcusedAbsenceIsRefused(): void
    {
        $this->createEntitlement(124_800);

        $refused = $this->action->createLeaveEntry(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'effective_date' => '2026-06-30',
                'entry_type' => 'shortening',
                'minutes_delta' => -480,
                'reason' => 'Krácení bez evidované neomluvené absence.',
            ]),
            new Response(),
        );

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString(
            'neomluveně zameškané hodiny',
            (string) $refused->getBody(),
        );
    }

    /**
     * S evidovanou neomluvenou absencí krácení projde — ale jen do počtu
     * skutečně zameškaných hodin. Osmihodinová směna (8:00–16:30 s půlhodinovou
     * přestávkou) dá 480 minut; 481 už ne.
     */
    public function testShorteningIsCappedByTheRecordedUnexcusedHours(): void
    {
        $this->createEntitlement(124_800);
        // § 223 odst. 3 potřebuje stanovenou týdenní pracovní dobu, aby šlo
        // ověřit minimum dvou týdnů.
        $this->insertEmploymentTerms(40);
        $this->insertPublishedShift('2026-06-15 06:00:00', '2026-06-15 14:30:00', 30);
        $this->recordApprovedUnexcusedAbsence('2026-06-15');

        $ok = $this->action->createLeaveEntry(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'effective_date' => '2026-06-30',
                'entry_type' => 'shortening',
                'minutes_delta' => -480,
                'reason' => 'Krácení za neomluveně zameškanou směnu 15. 6.',
            ]),
            new Response(),
        );
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());

        // Druhé krácení už nemá krytí — § 223 odst. 1 je strop, ne návod.
        $refused = $this->action->createLeaveEntry(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'effective_date' => '2026-06-30',
                'entry_type' => 'shortening',
                'minutes_delta' => -60,
                'reason' => 'Krácení nad rámec zameškaných hodin.',
            ]),
            new Response(),
        );

        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString(
            'neomluveně zameškané hodiny',
            (string) $refused->getBody(),
        );
    }

    private function insertEmploymentTerms(int $weeklyHours): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, effective_from, planned_start_on, weekly_hours)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            $this->employmentId,
            '2026-01-01',
            '2026-01-01',
            $weeklyHours,
        ]);
    }

    private function recordApprovedUnexcusedAbsence(string $date): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO payroll_absences
                (supplier_id, employment_id, absence_type, date_from, date_to,
                 timezone_name, compensation_policy, support_status, status,
                 requested_by, decided_by, decided_at)
             VALUES (?, ?, 'unexcused', ?, ?, 'Europe/Prague', 'none',
                     'manual_review', 'approved', ?, ?, NOW())"
        )->execute([
            $this->supplierId,
            $this->employmentId,
            $date,
            $date,
            $this->userId,
            $this->userId,
        ]);
    }

    private function approveVacation(int $averageId): Response
    {
        $created = $this->createAbsence($averageId);
        self::assertSame(201, $created->getStatusCode());
        $absence = $this->json($created)['absence'];

        return $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
    }

    private function createEntitlement(int $workedEquivalentMinutes): void
    {
        $response = $this->action->createEntitlement(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'relation_type' => 'employment',
                'weekly_minutes' => 2_400,
                'entitlement_weeks' => 4,
                'continuous_calendar_days' => 365,
                'worked_equivalent_minutes' => $workedEquivalentMinutes,
                'rationale' => 'Synteticky ověřené započitatelné doby.',
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode());
    }

    private function drainLeaveBalance(int $minutes): void
    {
        $response = $this->action->createLeaveEntry(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'effective_date' => '2026-01-31',
                'entry_type' => 'payout',
                'minutes_delta' => -$minutes,
                'reason' => 'Syntetické vyčerpání zůstatku pro test přečerpání.',
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode());
    }

    public function testNewEntitlementRevisionReversesPreviousLedgerEntry(): void
    {
        $payload = [
            'employment_id' => $this->employmentId,
            'leave_year' => 2026,
            'relation_type' => 'employment',
            'weekly_minutes' => 2_400,
            'entitlement_weeks' => 4,
            'continuous_calendar_days' => 365,
            'worked_equivalent_minutes' => 124_800,
            'rationale' => 'Synteticky ověřené započitatelné doby.',
        ];
        $first = $this->action->createEntitlement(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode());

        $payload['worked_equivalent_minutes'] = 62_400;
        $second = $this->action->createEntitlement(
            $this->request('POST')->withParsedBody($payload),
            new Response(),
        );
        self::assertSame(201, $second->getStatusCode());

        $ledger = $this->db->pdo()->prepare(
            'SELECT entry_type, minutes_delta FROM payroll_leave_ledger
              WHERE supplier_id = ? AND employment_id = ? AND leave_year = 2026
              ORDER BY id'
        );
        $ledger->execute([$this->supplierId, $this->employmentId]);
        self::assertSame([
            ['entry_type' => 'entitlement', 'minutes_delta' => 9_600],
            ['entry_type' => 'reversal', 'minutes_delta' => -9_600],
            ['entry_type' => 'entitlement', 'minutes_delta' => 4_800],
        ], $ledger->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testEntitlementUsesStoredEmploymentRelationType(): void
    {
        $response = $this->action->createEntitlement(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'leave_year' => 2026,
                'relation_type' => 'dpp',
                'weekly_minutes' => 2_400,
                'entitlement_weeks' => 4,
                'continuous_calendar_days' => 365,
                'worked_equivalent_minutes' => 124_800,
                'rationale' => 'Synteticky ověřené započitatelné doby.',
            ]),
            new Response(),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            9_600,
            $this->json($response)['entitlement']['ledger_entry']['minutes_delta'],
        );
    }

    public function testDeletingSupplierCascadesMZ07History(): void
    {
        $averageId = $this->createApprovedAverage();
        $created = $this->createAbsence($averageId);
        self::assertSame(201, $created->getStatusCode());
        $absenceId = (int) $this->json($created)['absence']['id'];

        $this->db->pdo()->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        foreach ([
            ['payroll_average_earning_snapshots', $averageId],
            ['payroll_absences', $absenceId],
        ] as [$table, $id]) {
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND id = ?");
            $stmt->execute([$this->supplierId, $id]);
            self::assertSame(0, (int) $stmt->fetchColumn());
        }
    }

    private function createEmployment(int $supplierId, string $name): int
    {
        $employee = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, ?, 'employee', 1)"
        );
        $employee->execute([$supplierId, $name]);
        $employeeId = (int) $this->db->pdo()->lastInsertId();
        $employment = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date, is_legacy_projection)
             VALUES (?, ?, 'SYNTH-HPP', 'employment', 'active', '2026-01-01', 0)"
        );
        $employment->execute([$supplierId, $employeeId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertPublishedShift(string $from, string $to, int $breakMinutes): void
    {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO payroll_shifts
                (supplier_id, employment_id, series_key, starts_at_utc, ends_at_utc,
                 timezone_name, break_minutes, status, published_by, published_at)
             VALUES (?, ?, ?, ?, ?, 'Europe/Prague', ?, 'published', ?, NOW())"
        );
        $stmt->execute([
            $this->supplierId,
            $this->employmentId,
            md5($from . '|' . $to),
            $from,
            $to,
            $breakMinutes,
            $this->userId,
        ]);
    }

    private function createApprovedAverage(int $quarter = 2): int
    {
        $response = $this->action->createAverage(
            $this->request('POST')->withParsedBody([
                'employment_id' => $this->employmentId,
                'applicable_year' => 2026,
                'applicable_quarter' => $quarter,
                'decisive_from' => $quarter === 3 ? '2026-04-01' : '2026-01-01',
                'decisive_to' => $quarter === 3 ? '2026-06-30' : '2026-03-31',
                'gross_earnings_minor' => 12_000_000,
                'longer_period_allocated_minor' => 0,
                'worked_minutes' => 9_600,
                'worked_days' => 60,
                'probable_hourly_minor' => null,
                'rationale' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $response->getStatusCode());
        $average = $this->json($response)['snapshot'];
        self::assertSame('manual_review', $average['status']);
        $approved = $this->action->approveAverage(
            $this->request('POST')->withParsedBody(['row_version' => $average['row_version']]),
            new Response(),
            ['id' => (string) $average['id']],
        );
        self::assertSame(200, $approved->getStatusCode());
        $approvedSnapshot = $this->json($approved)['snapshot'];
        self::assertSame('approved', $approvedSnapshot['status']);
        self::assertSame('supported', $approvedSnapshot['support_status']);
        return (int) $average['id'];
    }

    /**
     * Nepřítomnost se ULOŽÍ i bez schváleného průměru — účetní se o dovolené
     * dozví dřív, než je čtvrtletní průměr spočítaný, a rozdělaná evidence se
     * nesmí ztratit. Kontrola je až na schválení, kde vzniká mzdový vstup.
     */
    public function testAbsenceSavesWithoutAverageAndBlocksOnlyApproval(): void
    {
        $created = $this->action->create(
            $this->request('POST')->withParsedBody([
                ...$this->absencePayload(0),
                'average_snapshot_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];
        self::assertNull($absence['average_snapshot_id']);

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(422, $approved->getStatusCode());
        self::assertStringContainsString(
            'průměrného výdělku',
            (string) ($this->json($approved)['error']['message'] ?? ''),
        );

        // A pořád je tam, uložená a čekající.
        $still = $this->action->list(
            $this->request('GET')->withQueryParams([
                'from' => '2026-06-01',
                'to' => '2026-06-30',
                'employment_id' => $this->employmentId,
            ]),
            new Response(),
        );
        self::assertSame(200, $still->getStatusCode());
        self::assertSame(1, $this->json($still)['total']);
    }

    /** Zamítnutí nic nematerializuje, takže překlik smí jít zpátky. */
    public function testRejectedAbsenceCanBeApprovedAfterAll(): void
    {
        $created = $this->action->create(
            $this->request('POST')->withParsedBody([
                ...$this->absencePayload(0),
                'absence_type' => 'unpaid_leave',
                'average_snapshot_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $absence = $this->json($created)['absence'];

        $rejected = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $absence['row_version'],
                'decision' => 'rejected',
            ]),
            new Response(),
            ['id' => (string) $absence['id']],
        );
        self::assertSame(200, $rejected->getStatusCode(), (string) $rejected->getBody());
        $rejectedAbsence = $this->json($rejected)['absence'];
        self::assertSame('rejected', $rejectedAbsence['status']);

        $approved = $this->action->decision(
            $this->request('POST')->withParsedBody([
                'row_version' => $rejectedAbsence['row_version'],
                'decision' => 'approved',
            ]),
            new Response(),
            ['id' => (string) $rejectedAbsence['id']],
        );
        self::assertSame(200, $approved->getStatusCode(), (string) $approved->getBody());
        self::assertSame('approved', $this->json($approved)['absence']['status']);
    }

    /** Kolizní nepřítomnost se v hlášce pojmenuje, ne jen ohlásí. */
    public function testOverlapErrorNamesTheConflictingAbsence(): void
    {
        $first = $this->action->create(
            $this->request('POST')->withParsedBody([
                ...$this->absencePayload(0),
                'absence_type' => 'unpaid_leave',
                'average_snapshot_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());

        $second = $this->action->create(
            $this->request('POST')->withParsedBody([
                ...$this->absencePayload(0),
                'absence_type' => 'other',
                'average_snapshot_id' => null,
            ]),
            new Response(),
        );
        self::assertSame(409, $second->getStatusCode());
        $message = (string) ($this->json($second)['error']['message'] ?? '');
        self::assertStringContainsString('2026-06-15', $message);
        self::assertStringContainsString('neplacené volno', $message);
    }

    private function createAbsence(int $averageId): Response
    {
        return $this->action->create(
            $this->request('POST')->withParsedBody($this->absencePayload($averageId)),
            new Response(),
        );
    }

    /** @return array<string,mixed> */
    private function absencePayload(int $averageId): array
    {
        return [
            'employment_id' => $this->employmentId,
            'absence_type' => 'vacation',
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-15',
            'timezone_name' => 'Europe/Prague',
            'partial_first_minutes' => null,
            'partial_last_minutes' => null,
            'average_snapshot_id' => $averageId,
            'note' => 'Syntetický integrační test.',
        ];
    }

    private function request(
        string $method,
        string $role = 'admin',
        string $authMethod = 'session',
        ?int $supplierId = null,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/time')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $supplierId ?? $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $authMethod);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
