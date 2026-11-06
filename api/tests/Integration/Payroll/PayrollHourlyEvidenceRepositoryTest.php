<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollHourlyEvidenceAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Repository\Payroll\PayrollHourlyEvidenceRepository;
use MyInvoice\Repository\Payroll\PayrollTimeConflictException;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeEvidence;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class PayrollHourlyEvidenceRepositoryTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollHourlyEvidenceRepository $repository;
    private PayrollHourlyEvidenceAction $action;
    private int $supplierId;
    private int $employmentId;
    private int $userId;

    protected function setUp(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        $this->action = $container->get(PayrollHourlyEvidenceAction::class);
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source);
        self::assertGreaterThan(0, $this->userId);
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare('INSERT INTO payroll_employees (supplier_id, full_name, taxpayer_type, is_active) VALUES (?, "Syntetická evidence hodin", "employee", 1)')->execute([$this->supplierId]);
        $person = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employments (supplier_id, employee_id, code, relation_type, status, start_date) VALUES (?, ?, "SYN-HOUR-EVIDENCE", "employment", "active", "2026-01-01")')->execute([$this->supplierId, $person]);
        $this->employmentId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO payroll_employment_terms
            (supplier_id, employment_id, effective_from, planned_start_on, actual_start_on, weekly_hours,
             remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis)
            VALUES (?, ?, "2026-01-01", "2026-01-01", "2026-01-01", 40, "hourly", 20000, "contract", "Syntetická smlouva")')->execute([$this->supplierId, $this->employmentId]);
        $container->get(PayrollTimeRepository::class)->saveManualSummary($this->supplierId, $this->employmentId,
            '2026-06-01', 0, 'Doložená práce', true, [['date_from' => '2026-06-01', 'date_to' => '2026-06-30',
                'worked_duration_units' => 480053, 'overtime_duration_units' => 0, 'worked_days' => 21]], $this->userId);
        $this->repository = new PayrollHourlyEvidenceRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testRevisionsKeepOriginalEvidenceAndRejectStaleWriter(): void
    {
        $body = $this->body();
        $first = $this->save($body);
        self::assertSame(1, $first['revision_no']);
        $body['row_version'] = 1;
        $body['reason'] = 'Doložená oprava noci';
        $body['premium_segments'][0]['night_duration_units'] = 53;
        $second = $this->save($body);
        self::assertSame(2, $second['revision_no']);
        self::assertNotSame($first['id'], $second['id']);
        self::assertNotSame($first['content_sha256'], $second['content_sha256']);
        $history = $this->repository->history($this->supplierId, $this->employmentId, '2026-06-01', 0);
        self::assertSame([2, 1], array_column($history['history'], 'revision_no'));
        self::assertFalse($history['history_has_more']);
        $stmt = $this->db->pdo()->prepare('SELECT payload_json FROM payroll_hourly_evidence_revisions WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $first['id']]);
        self::assertSame(0, json_decode((string) $stmt->fetchColumn(), true)['premium_segments'][0]['night_duration_units']);
        try {
            $this->save($body);
            self::fail('Zastaralý zápis nesmí vytvořit další revizi.');
        } catch (PayrollTimeConflictException $e) {
            self::assertSame(2, $e->currentVersion);
        }
        self::assertCount(2, $this->repository->history($this->supplierId, $this->employmentId, '2026-06-01', 0)['history']);
    }

    public function testApprovedTimeMonthDoesNotLockIndependentPremiumEvidence(): void
    {
        $body = $this->body();
        $this->db->pdo()->prepare('UPDATE payroll_time_months SET status = "approved", approved_at = NOW(), row_version = row_version + 1 WHERE supplier_id = ? AND employment_id = ? AND period_start = "2026-06-01"')->execute([$this->supplierId, $this->employmentId]);
        $saved = $this->save($body);
        self::assertSame($body['work_source_hash'], $saved['work_source_hash']);
    }

    public function testHistoryIsBoundedAndStoredRevisionsCannotBeChanged(): void
    {
        $body = $this->body();
        for ($version = 0; $version < 21; $version++) {
            $body['row_version'] = $version;
            $body['reason'] = 'Syntetická revize ' . ($version + 1);
            $saved = $this->save($body);
        }
        $first = $this->repository->history($this->supplierId, $this->employmentId, '2026-06-01', 0);
        $second = $this->repository->history($this->supplierId, $this->employmentId, '2026-06-01', 20);
        self::assertCount(20, $first['history']);
        self::assertTrue($first['history_has_more']);
        self::assertSame([1], array_column($second['history'], 'revision_no'));
        self::assertFalse($second['history_has_more']);
        foreach (['UPDATE payroll_hourly_evidence_revisions SET reason = "Přepis" WHERE supplier_id = ? AND id = ?',
            'DELETE FROM payroll_hourly_evidence_revisions WHERE supplier_id = ? AND id = ?'] as $sql) {
            try {
                $this->db->pdo()->prepare($sql)->execute([$this->supplierId, $saved['id']]);
                self::fail('Neměnná revize nesmí jít přepsat ani smazat.');
            } catch (\PDOException $e) {
                self::assertSame('45000', $e->getCode());
            }
        }
        self::assertSame(21, $this->repository->latestMany($this->supplierId, [$this->employmentId], '2026-06-01')[$this->employmentId]['revision_no']);
    }

    public function testForeignTenantCannotReadOrWriteEvidence(): void
    {
        $this->save($this->body());
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        self::assertSame([], $this->repository->latestMany($other, [$this->employmentId], '2026-06-01'));
        self::assertSame([], $this->repository->history($other, $this->employmentId, '2026-06-01', 0)['history']);
        $this->expectException(\OutOfBoundsException::class);
        $this->repository->save($other, $this->employmentId, '2026-06-01', $this->body(), $this->userId, static fn() => null);
    }

    public function testHolidayTimeOffRevalidatesRemunerationBeforeApproval(): void
    {
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $data = $container->get(\MyInvoice\Service\Payroll\PayrollAbsenceValidator::class)->absence([
            'employment_id' => $this->employmentId, 'absence_type' => 'holiday_compensatory_time_off',
            'date_from' => '2026-06-08', 'date_to' => '2026-06-08',
        ]);
        $repository = $container->get(\MyInvoice\Repository\Payroll\PayrollAbsenceRepository::class);
        $absence = $repository->create($this->supplierId, $data, $this->userId);
        self::assertSame('hourly_claim', $absence['compensation_policy']);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET remuneration_type = "monthly", hourly_rate_minor = NULL,
            monthly_gross_minor = 3000000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $this->employmentId]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hodinové odměňování');
        $repository->decide($this->supplierId, (int) $absence['id'], 1, 'approved', $this->userId);
    }

    public function testChangedWorkSourceOrOversizedPremiumCannotBeSaved(): void
    {
        $body = $this->body();
        $body['work_source_hash'] = str_repeat('f', 64);
        try {
            $this->save($body);
            self::fail('Změněný zdroj musí odmítnout zápis.');
        } catch (PayrollTimeConflictException $e) {
            self::assertSame(0, $e->currentVersion);
        }
        $body = $this->body();
        $body['premium_segments'][0]['night_duration_units'] = 480054;
        $this->expectException(\InvalidArgumentException::class);
        $this->save($body);
    }

    public function testSelectionProofIsValidatedWithinLockedTransaction(): void
    {
        $called = false;
        $this->repository->save($this->supplierId, $this->employmentId, '2026-06-01', $this->body(), $this->userId,
            function (array $payload, array $source) use (&$called): void {
                $called = true;
                self::assertTrue($this->db->pdo()->inTransaction());
                self::assertSame([], $payload['holiday_time_off_grants']);
                self::assertSame('manual_summary', $source['work_source']);
            });
        self::assertTrue($called);
    }

    public function testApiSeparatesReadWriteAndSessionPermissions(): void
    {
        $read = new EffectiveRole(0, 'Test', 'staff', true, ['payroll' => AccessLevel::READ->value]);
        $write = new EffectiveRole(0, 'Test', 'staff', true, ['payroll.time.write' => AccessLevel::WRITE->value]);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/payroll/employments/' . $this->employmentId . '/hourly-evidence')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
        $args = ['id' => (string) $this->employmentId];
        $view = $this->action->show($request->withQueryParams(['period' => '2026-06'])->withAttribute('auth.effective_role', $read), new Response(), $args);
        self::assertSame(200, $view->getStatusCode(), (string) $view->getBody());
        $data = json_decode((string) $view->getBody(), true);
        self::assertFalse($data['can_write']);
        self::assertSame('manual_summary', $data['work_source']);
        self::assertSame(0, $data['row_version']);
        $body = $this->body() + ['period' => '2026-06'];
        $post = $request->withMethod('POST')->withParsedBody($body);
        self::assertSame(403, $this->action->save($post->withAttribute('auth.effective_role', $read), new Response(), $args)->getStatusCode());
        self::assertSame(403, $this->action->save($post->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response(), $args)->getStatusCode());
        $saved = $this->action->save($post->withAttribute('auth.effective_role', $write), new Response(), $args);
        self::assertSame(201, $saved->getStatusCode(), (string) $saved->getBody());
        $data = json_decode((string) $saved->getBody(), true);
        self::assertSame(1, $data['row_version']);
        self::assertSame([], $data['evidence']['nonworked_holidays']);
        self::assertTrue($data['can_write']);
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->supplierId);
        self::assertSame(404, $this->action->show($request->withQueryParams(['period' => '2026-06'])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $other), new Response(), $args)->getStatusCode());
    }

    private function save(array $body): array
    {
        return $this->repository->save($this->supplierId, $this->employmentId, '2026-06-01', $body, $this->userId, static fn() => null);
    }

    private function body(): array
    {
        $source = (new PayrollWorkedTimeEvidence($this->db))->loadMany($this->supplierId, [$this->employmentId], '2026-06-01')[$this->employmentId];
        return ['row_version' => 0, 'reason' => 'Výslovně doložené nulové vrstvy',
            'work_source_hash' => PayrollHourlyEvidenceRepository::bindingHash($source),
            'premium_segments' => [['date_from' => '2026-06-01', 'date_to' => '2026-06-30', 'night_duration_units' => 0,
                'weekend_duration_units' => 0, 'holiday_work_duration_units' => 0, 'difficult_exposures' => [],
                'holiday_work_days' => [], 'overtime_work_days' => []]],
            'nonworked_holidays' => [], 'holiday_time_off_grants' => [], 'overtime_time_off_allocations' => []];
    }
}
