<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentSurchargePolicyAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Oprava a ukončení sjednané zásady příplatků § 114 až § 118 ZP.
 *
 * Než tyhle dvě operace vznikly, šlo zásadu jen ZALOŽIT: překlep v sazbě se
 * „opravoval" novou verzí od dalšího dne a v historii po sobě nechal den, kdy
 * prý platila sazba, kterou nikdo nesjednal. Test drží hranici, kde oprava
 * končí a začíná přepis historie.
 */
#[Group('integration')]
final class PayrollEmploymentSurchargePolicyApiTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentSurchargePolicyAction $action;
    private int $userId;
    private int $supplierId;
    private int $employmentId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        self::assertInstanceOf(ContainerInterface::class, $container);
        $connection = $container->get(Connection::class);
        $action = $container->get(PayrollEmploymentSurchargePolicyAction::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(PayrollEmploymentSurchargePolicyAction::class, $action);
        $this->db = $connection;
        $this->action = $action;

        $pdo = $connection->pdo();
        $sourceSupplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $sourceSupplierId);
        self::assertGreaterThan(0, $this->userId);

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')
            ->execute([$this->supplierId]);
        $this->employmentId = $this->createEmployment($this->supplierId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if (isset($this->db)) {
            $this->db->close();
        }
    }

    public function testUpdateFixesOpenVersionUnderOptimisticLock(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01', 'overtime_rate_bp' => 2500]);
        self::assertSame(1, (int) $created['row_version']);

        $update = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 3000,
                'note' => 'Oprava překlepu v sazbě.',
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $update->getStatusCode());
        $policy = $this->row($this->json($update)['policy'] ?? null);
        self::assertSame(3000, (int) $policy['overtime_rate_bp']);
        self::assertSame('2026-01-01', (string) $policy['valid_from']);
        self::assertSame(2, (int) $policy['row_version']);

        // Zastaralá verze musí narazit, jinak by dva uživatelé tiše přepsali
        // jeden druhého a na výplatní pásce by skončila sazba, kterou nikdo nevidí.
        $stale = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 4000,
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(409, $stale->getStatusCode());
        $error = $this->row($this->json($stale)['error'] ?? null);
        self::assertSame('row_version_conflict', $error['code']);
    }

    /**
     * Účinnost je hranice proti předchozí verzi, jejíž konec je z ní dopočítaný.
     * Oprava ji proto nesmí posunout ani tehdy, když ji klient v těle pošle.
     */
    public function testUpdateIgnoresValidFromFromRequestBody(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01']);

        $update = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'valid_from' => '2026-09-01',
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $update->getStatusCode());
        self::assertSame(
            '2026-01-01',
            (string) $this->row($this->json($update)['policy'] ?? null)['valid_from'],
        );
    }

    public function testClosedAndSupersededVersionsRefuseCorrection(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        // Novou verzí se ta první uzavře; od té chvíle je to historie.
        $second = $this->create(['valid_from' => '2026-04-01']);

        $locked = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 9000,
                'row_version' => 2,
            ])),
            new Response(),
            $this->args((int) $first['id']),
        );
        self::assertSame(409, $locked->getStatusCode());
        self::assertSame(
            'surcharge_policy_history_locked',
            $this->row($this->json($locked)['error'] ?? null)['code'],
        );

        // Poslední, otevřená verze se opravit dá — hranice je právě tady.
        $open = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload([
                'overtime_rate_bp' => 3000,
                'row_version' => 1,
            ])),
            new Response(),
            $this->args((int) $second['id']),
        );
        self::assertSame(200, $open->getStatusCode());
    }

    public function testCloseSetsValidToAndRefusesInvertedInterval(): void
    {
        $created = $this->create(['valid_from' => '2026-01-01']);

        $inverted = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2025-12-31', 'row_version' => 1]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(422, $inverted->getStatusCode());
        self::assertSame(
            'validation_failed',
            $this->row($this->json($inverted)['error'] ?? null)['code'],
        );

        $closed = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2026-06-30', 'row_version' => 1]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(200, $closed->getStatusCode());
        $policy = $this->row($this->json($closed)['policy'] ?? null);
        self::assertSame('2026-06-30', (string) $policy['valid_to']);
        self::assertSame(2, (int) $policy['row_version']);

        // Podruhé už ne: posunout konec zpětně by změnilo období, podle kterého
        // se počítaly hotové mzdy.
        $again = $this->action->close(
            $this->request('POST')
                ->withParsedBody(['valid_to' => '2026-07-31', 'row_version' => 2]),
            new Response(),
            $this->args((int) $created['id']),
        );
        self::assertSame(409, $again->getStatusCode());
        self::assertSame(
            'surcharge_policy_history_locked',
            $this->row($this->json($again)['error'] ?? null)['code'],
        );
    }

    public function testHourlyContractMetadataSurvivesCorrectionWithoutResubmission(): void
    {
        $this->addHourlyTerms();
        $metadata = self::hourlyMetadata();
        $created = $this->create(['overtime_mode' => 'included_in_wage', 'hourly_terms' => $metadata]);
        self::assertSame(0, $created['hourly_terms']['included_overtime']['opening_used_duration_units']);
        self::assertArrayNotHasKey('prior_consumed_duration_units', $created['hourly_terms']['included_overtime']);
        $updated = $this->action->update($this->request('PUT')->withParsedBody($this->payload([
            'overtime_mode' => 'included_in_wage', 'row_version' => 1, 'note' => 'Oprava poznámky',
        ])), new Response(), $this->args((int) $created['id']));
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getBody());
        self::assertSame($created['hourly_terms'], $this->json($updated)['policy']['hourly_terms']);
        $history = $this->create(['valid_from' => '2026-04-01']);
        self::assertArrayNotHasKey('hourly_terms', $history);
        $old = $this->db->pdo()->prepare('SELECT hourly_terms_json FROM payroll_employment_surcharge_policies WHERE supplier_id = ? AND id = ?');
        $old->execute([$this->supplierId, $created['id']]);
        self::assertSame($created['hourly_terms'], json_decode((string) $old->fetchColumn(), true));
    }

    public function testAgreementCannotAcquireStatutoryOvertimeAndMonthlyHasNoHourlyMetadata(): void
    {
        $payload = $this->payload(['overtime_mode' => 'included_in_wage', 'hourly_terms' => self::hourlyMetadata()]);
        $response = $this->action->create($this->request('POST')->withParsedBody($payload), new Response(), ['id' => (string) $this->employmentId]);
        self::assertSame(422, $response->getStatusCode());
        $this->addHourlyTerms();
        $this->db->pdo()->prepare('UPDATE payroll_employments SET relation_type = "dpp" WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $this->employmentId]);
        $response = $this->action->create($this->request('POST')->withParsedBody($payload), new Response(), ['id' => (string) $this->employmentId]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('§ 114', $this->json($response)['error']['message']);
    }

    private function addHourlyTerms(): void
    {
        $this->db->pdo()->prepare('INSERT INTO payroll_employment_terms
            (supplier_id, employment_id, effective_from, planned_start_on, actual_start_on, weekly_hours,
             remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis)
            VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01", 40, "hourly", 20000, "contract", "Syntetická smlouva")')
            ->execute([$this->supplierId, $this->employmentId]);
    }

    public function testHourlyPolicyCannotRewriteSettledPayroll(): void
    {
        $this->addHourlyTerms();
        $policy = $this->create(['overtime_mode' => 'included_in_wage', 'hourly_terms' => self::hourlyMetadata()]);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
            VALUES (?, "2026-06-01", "2026-07-15", "posted", 1)')->execute([$this->supplierId]);
        $run = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_run_revisions
            (supplier_id, run_id, revision_no, status, schema_version, ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
            VALUES (?, ?, 1, "approved", "test", ?, "{}", ?, ?)')
            ->execute([$this->supplierId, $run, str_repeat('a', 64), hash('sha256', '{}'), random_bytes(32)]);
        $revision = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_run_employments (supplier_id, revision_id, employee_id, employment_id, period_start, input_json, input_hash)
            SELECT supplier_id, ?, employee_id, id, "2026-06-01", "{}", ? FROM payroll_employments WHERE supplier_id = ? AND id = ?')
            ->execute([$revision, hash('sha256', '{}'), $this->supplierId, $this->employmentId]);
        $response = $this->action->update($this->request('PUT')->withParsedBody($this->payload([
            'row_version' => 1, 'overtime_mode' => 'included_in_wage', 'hourly_terms' => self::hourlyMetadata(),
        ])), new Response(), $this->args((int) $policy['id']));
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('2026', $this->json($response)['error']['message']);
        $response = $this->action->close($this->request('POST')->withParsedBody([
            'row_version' => 1, 'valid_to' => '2026-05-31',
        ]), new Response(), $this->args((int) $policy['id']));
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $response = $this->action->create($this->request('POST')->withParsedBody($this->payload([
            'valid_from' => '2026-05-01',
        ])), new Response(), ['id' => (string) $this->employmentId]);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $stored = $pdo->prepare('SELECT row_version, valid_to FROM payroll_employment_surcharge_policies WHERE supplier_id = ? AND id = ?');
        $stored->execute([$this->supplierId, $policy['id']]);
        self::assertSame(['row_version' => 1, 'valid_to' => null], $stored->fetch(\PDO::FETCH_ASSOC));
    }

    /** B2-08: začátek verze, podle které se nepočítalo, jde posunout i s hranicí předchozí verze. */
    public function testMovingStartShiftsTheJoinedPreviousBoundaryBothWays(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);

        $later = $this->moveStart((int) $second['id'], '2026-04-15', 1);
        self::assertSame(200, $later->getStatusCode(), (string) $later->getBody());
        self::assertSame(['2026-01-01', '2026-04-14', 3], $this->stored((int) $first['id']));
        self::assertSame(['2026-04-15', null, 2], $this->stored((int) $second['id']));

        $earlier = $this->moveStart((int) $second['id'], '2026-03-01', 2);
        self::assertSame(200, $earlier->getStatusCode(), (string) $earlier->getBody());
        self::assertSame(['2026-01-01', '2026-02-28', 4], $this->stored((int) $first['id']));
        self::assertSame(['2026-03-01', null, 3], $this->stored((int) $second['id']));
        self::assertSame('2026-03-01', (string) $this->json($earlier)['policy']['valid_from']);
    }

    public function testMovingStartRefusesToSwallowThePreviousVersion(): void
    {
        $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);

        foreach (['2026-01-01', '2025-12-01'] as $date) {
            $response = $this->moveStart((int) $second['id'], $date, 1);
            self::assertSame(422, $response->getStatusCode(), $date);
        }
        $first = $this->moveStart((int) $second['id'], 'not-a-date', 1);
        self::assertSame(422, $first->getStatusCode());
        self::assertSame(['2026-04-01', null, 1], $this->stored((int) $second['id']));
    }

    public function testClosedVersionInTheMiddleMovesOnlyItsStart(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        $middle = $this->create(['valid_from' => '2026-04-01']);
        $last = $this->create(['valid_from' => '2026-07-01']);

        $moved = $this->moveStart((int) $middle['id'], '2026-05-01', 2);
        self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());
        self::assertSame(['2026-01-01', '2026-04-30', 3], $this->stored((int) $first['id']));
        self::assertSame(['2026-05-01', '2026-06-30', 3], $this->stored((int) $middle['id']));
        self::assertSame(['2026-07-01', null, 1], $this->stored((int) $last['id']));

        foreach (['2026-07-01', '2026-08-01'] as $date) {
            $beyond = $this->moveStart((int) $middle['id'], $date, 3);
            self::assertSame(422, $beyond->getStatusCode(), $date);
        }
        self::assertSame(['2026-05-01', '2026-06-30', 3], $this->stored((int) $middle['id']));
    }

    public function testGapBeforeTheVersionStaysAGapAndIsNotOverlapped(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        $closed = $this->action->close(
            $this->request('POST')->withParsedBody(['valid_to' => '2026-02-28', 'row_version' => 1]),
            new Response(),
            $this->args((int) $first['id']),
        );
        self::assertSame(200, $closed->getStatusCode());
        $second = $this->create(['valid_from' => '2026-04-01']);

        $overlap = $this->moveStart((int) $second['id'], '2026-02-15', 1);
        self::assertSame(422, $overlap->getStatusCode());

        $later = $this->moveStart((int) $second['id'], '2026-05-01', 1);
        self::assertSame(200, $later->getStatusCode(), (string) $later->getBody());
        self::assertSame(['2026-01-01', '2026-02-28', 2], $this->stored((int) $first['id']));

        $intoGap = $this->moveStart((int) $second['id'], '2026-03-01', 2);
        self::assertSame(200, $intoGap->getStatusCode(), (string) $intoGap->getBody());
        self::assertSame(['2026-01-01', '2026-02-28', 2], $this->stored((int) $first['id']));
        self::assertSame(['2026-03-01', null, 3], $this->stored((int) $second['id']));
    }

    public function testFrozenMonthKeepsItsPolicyAndOnlyUnfrozenDaysMove(): void
    {
        $first = $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);
        $this->freezeMonth('2026-03-01', 'approved');

        $intoFrozen = $this->moveStart((int) $second['id'], '2026-03-20', 1);
        self::assertSame(409, $intoFrozen->getStatusCode());
        self::assertSame('surcharge_policy_history_locked', $this->json($intoFrozen)['error']['code']);
        self::assertStringContainsString('03/2026', $this->json($intoFrozen)['error']['message']);

        $afterFrozen = $this->moveStart((int) $second['id'], '2026-04-15', 1);
        self::assertSame(200, $afterFrozen->getStatusCode(), (string) $afterFrozen->getBody());
        self::assertSame(['2026-01-01', '2026-04-14', 3], $this->stored((int) $first['id']));
    }

    public function testVersionUsedByARevisionCannotMoveUnlessTheRevisionWasDropped(): void
    {
        $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);
        $this->freezeMonth('2026-05-01', 'snapshot');

        $used = $this->moveStart((int) $second['id'], '2026-04-10', 1);
        self::assertSame(409, $used->getStatusCode());
        self::assertSame(['2026-04-01', null, 1], $this->stored((int) $second['id']));
    }

    public function testAbandonedRevisionDoesNotFreezeTheVersion(): void
    {
        $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);
        $this->freezeMonth('2026-05-01', 'abandoned');

        $dropped = $this->moveStart((int) $second['id'], '2026-04-10', 1);
        self::assertSame(200, $dropped->getStatusCode(), (string) $dropped->getBody());
    }

    public function testCancelledRunDoesNotFreezeTheVersion(): void
    {
        $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);
        $this->freezeMonth('2026-04-01', 'snapshot');
        $this->db->pdo()->prepare('UPDATE payroll_runs SET status = "cancelled" WHERE supplier_id = ?')
            ->execute([$this->supplierId]);

        $moved = $this->moveStart((int) $second['id'], '2026-04-10', 1);
        self::assertSame(200, $moved->getStatusCode(), (string) $moved->getBody());
    }

    public function testMovingStartChecksRowVersion(): void
    {
        $this->create(['valid_from' => '2026-01-01']);
        $second = $this->create(['valid_from' => '2026-04-01']);

        $stale = $this->moveStart((int) $second['id'], '2026-04-10', 7);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('row_version_conflict', $this->json($stale)['error']['code']);
        self::assertSame(1, $this->json($stale)['error']['current_row_version']);
        self::assertSame(['2026-04-01', null, 1], $this->stored((int) $second['id']));
    }

    private function moveStart(int $policyId, string $validFrom, int $rowVersion): ResponseInterface
    {
        return $this->action->moveStart(
            $this->request('POST')->withParsedBody(['valid_from' => $validFrom, 'row_version' => $rowVersion]),
            new Response(),
            $this->args($policyId),
        );
    }

    /** @return array{0:string,1:?string,2:int} */
    private function stored(int $policyId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT valid_from, valid_to, row_version FROM payroll_employment_surcharge_policies WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$this->supplierId, $policyId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return [(string) $row['valid_from'], $row['valid_to'] === null ? null : (string) $row['valid_to'], (int) $row['row_version']];
    }

    private function freezeMonth(string $periodStart, string $revisionStatus): void
    {
        $pdo = $this->db->pdo();
        $payment = (new \DateTimeImmutable($periodStart))->modify('+1 month')->format('Y-m-15');
        $pdo->prepare('INSERT INTO payroll_runs (supplier_id, period_start, payment_date, status, current_revision_no)
            VALUES (?, ?, ?, ?, 1)')->execute([$this->supplierId, $periodStart, $payment, $revisionStatus === 'approved' ? 'approved' : 'inputs_locked']);
        $run = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_run_revisions
            (supplier_id, run_id, revision_no, status, schema_version, ruleset_manifest_hash, input_snapshot_json, input_snapshot_hash, idempotency_key_hash, superseded_at)
            VALUES (?, ?, 1, ?, "test", ?, "{}", ?, ?, ?)')
            ->execute([$this->supplierId, $run, $revisionStatus, str_repeat('a', 64), hash('sha256', '{}'), random_bytes(32), $revisionStatus === 'abandoned' ? date('Y-m-d H:i:s') : null]);
        $revision = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_run_employments (supplier_id, revision_id, employee_id, employment_id, period_start, input_json, input_hash)
            SELECT supplier_id, ?, employee_id, id, ?, "{}", ? FROM payroll_employments WHERE supplier_id = ? AND id = ?')
            ->execute([$revision, $periodStart, hash('sha256', '{}'), $this->supplierId, $this->employmentId]);
    }

    private static function hourlyMetadata(): array
    {
        return ['included_overtime' => ['agreement_kind' => 'contract', 'agreement_reference' => 'Syntetická smlouva',
            'scope_kind' => 'annual_hours', 'calendar_year' => 2026, 'annual_limit_duration_units' => 450000,
            'opening_used_duration_units' => 0, 'opening_as_of' => '2026-01-01', 'opening_reference' => 'Doložený začátek roku',
            'prior_consumed_duration_units' => 123]];
    }

    public function testMissingPolicyIsNotFound(): void
    {
        $response = $this->action->update(
            $this->request('PUT')->withParsedBody($this->payload(['row_version' => 1])),
            new Response(),
            $this->args(987654321),
        );
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'not_found',
            $this->row($this->json($response)['error'] ?? null)['code'],
        );
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function create(array $overrides): array
    {
        $response = $this->action->create(
            $this->request('POST')->withParsedBody($this->payload($overrides)),
            new Response(),
            ['id' => (string) $this->employmentId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return $this->row($this->json($response)['policy'] ?? null);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'valid_from' => '2026-01-01',
            'overtime_mode' => 'surcharge',
            'holiday_mode' => 'surcharge',
            'difficult_environment_factors' => 2,
            'overtime_rate_bp' => null,
            'holiday_rate_bp' => null,
            'night_rate_bp' => null,
            'weekend_rate_bp' => null,
            'difficult_environment_rate_bp' => null,
            'agreement_reference' => 'KS čl. 12',
            'note' => null,
        ];
    }

    /** @return array<string,string> */
    private function args(int $policyId): array
    {
        return ['id' => (string) $this->employmentId, 'policyId' => (string) $policyId];
    }

    private function createEmployment(int $supplierId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active)
             VALUES (?, "Syntetická osoba příplatků", "employee", 1)'
        )->execute([$supplierId]);
        $employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO payroll_employments
                (supplier_id, employee_id, code, relation_type, status, start_date)
             VALUES (?, ?, "surcharge-policy-synthetic", "employment", "active", "2026-01-01")'
        )->execute([$supplierId, $employeeId]);

        return (int) $pdo->lastInsertId();
    }

    private function request(string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest(
                $method,
                '/api/payroll/employments/' . $this->employmentId . '/surcharge-policies',
            )
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, [
                'id' => $this->userId,
                'role' => 'admin',
            ])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return $this->row(json_decode((string) $response->getBody(), true));
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Testovací HTTP DTO není pole.');
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Testovací HTTP DTO nemá textové klíče.');
            }
            $row[$key] = $item;
        }

        return $row;
    }
}
