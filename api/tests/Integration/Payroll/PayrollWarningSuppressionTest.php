<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollWarningSuppressionsAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Service\Payroll\Run\PayrollRunReadinessService;
use MyInvoice\Service\Payroll\Run\PayrollRunSnapshotBuilder;
use MyInvoice\Service\Payroll\Run\PayrollRunValidation;
use MyInvoice\Service\Payroll\Run\PayrollWarningSuppressionService;
use MyInvoice\Tests\Fixtures\Payroll\PayrollRunScaleFixture;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Trvalé skrytí mzdových varování: zápis, audit, obnovení a to, že se skrytá
 * varování do kontroly před zahájením běhu nepočítají. Pouze syntetická data.
 */
#[Group('integration')]
final class PayrollWarningSuppressionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const CODE = 'tax_declaration_not_signed_summary';

    private Connection $db;
    private PayrollWarningSuppressionService $service;
    private PayrollRunSnapshotBuilder $builder;
    private PayrollRunReadinessService $readiness;
    private PayrollEmployerPolicyRepository $policies;
    private PayrollWarningSuppressionsAction $action;
    private int $userId;
    private int $supplierId;
    private int $otherSupplierId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PayrollWarningSuppressionService::class);
            $this->builder = $container->get(PayrollRunSnapshotBuilder::class);
            $this->readiness = $container->get(PayrollRunReadinessService::class);
            $this->policies = $container->get(PayrollEmployerPolicyRepository::class);
            $this->action = $container->get(PayrollWarningSuppressionsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasTable('payroll_warning_suppressions')) {
            $this->markTestSkipped('Migrace 1957 neproběhla.');
        }
        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT MIN(id) FROM users')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier nebo uživatel.');
        }
        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id IN (?, ?)')
            ->execute([$this->supplierId, $this->otherSupplierId]);
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

    public function testHidingPeopleNarrowsTheSummaryAndHidingAllOfThemRemovesIt(): void
    {
        $fixture = $this->seedUnsigned(3);
        [$first, $second, $third] = $fixture->employeeIds;

        $summary = $this->summary();
        self::assertNotNull($summary);
        $ids = $summary->subjectIds;
        sort($ids);
        self::assertSame([$first, $second, $third], $ids);
        self::assertStringContainsString('prohlášením poplatníka k dani: 3.', $summary->message);

        $result = $this->service->hide($this->supplierId, self::CODE, [$first], 'Student, prohlášení podepsal jinde', $this->userId);
        self::assertSame(['created' => 1, 'skipped' => 0], $result);
        $again = $this->service->hide($this->supplierId, self::CODE, [$first], null, $this->userId);
        self::assertSame(['created' => 0, 'skipped' => 1], $again);

        $narrowed = $this->visibleSummary();
        self::assertNotNull($narrowed);
        self::assertStringContainsString('prohlášením poplatníka k dani: 2.', $narrowed->message);
        $narrowedIds = $narrowed->subjectIds;
        sort($narrowedIds);
        self::assertSame([$second, $third], $narrowedIds);

        $this->service->hide($this->supplierId, self::CODE, [$second, $third], null, $this->userId);
        self::assertNull($this->visibleSummary());
        self::assertSame([], $this->readinessCodes(self::CODE));

        // Stav uložené kontroly se nemění — skrývá se jen při čtení.
        self::assertNotNull($this->summary());
        self::assertSame(3, $this->activityCount('payroll.warning_suppression.hidden'));
    }

    public function testHidingTheTypeHidesItForTheWholeCompanyAndRestoreBringsItBack(): void
    {
        $this->seedUnsigned(2);
        self::assertSame([self::CODE], $this->readinessCodes(self::CODE));

        $this->service->hide($this->supplierId, self::CODE, null, '  ', $this->userId);
        self::assertNull($this->visibleSummary());
        self::assertSame([], $this->readinessCodes(self::CODE));

        $items = $this->service->list($this->supplierId);
        self::assertCount(1, $items);
        self::assertSame('supplier', $items[0]['subject_type']);
        self::assertNull($items[0]['subject_id']);
        self::assertNull($items[0]['reason'], 'Prázdný důvod se neukládá jako mezery.');
        self::assertSame($this->userId, $items[0]['created_by']);

        // Cizí firma skrytí nevidí a obnovit ho nemůže.
        self::assertSame([], $this->service->list($this->otherSupplierId));
        self::assertSame(0, $this->service->restore($this->otherSupplierId, [$items[0]['id']], $this->userId));

        self::assertSame(1, $this->service->restore($this->supplierId, [$items[0]['id']], $this->userId));
        self::assertNotNull($this->visibleSummary());
        self::assertSame(1, $this->activityCount('payroll.warning_suppression.restored'));
    }

    public function testPersonHideIsListedWithTheName(): void
    {
        $fixture = $this->seedUnsigned(1);
        $this->service->hide($this->supplierId, self::CODE, [$fixture->employeeIds[0]], 'Důvod', $this->userId);

        $items = $this->service->list($this->supplierId);
        self::assertCount(1, $items);
        self::assertSame('employee', $items[0]['subject_type']);
        self::assertSame($fixture->employeeIds[0], $items[0]['subject_id']);
        self::assertNotEmpty($items[0]['subject_label']);
        self::assertSame('Důvod', $items[0]['reason']);
    }

    public function testBlockingAndUnknownChecksCannotBeHidden(): void
    {
        foreach (['time_month_not_approved', 'draft_inputs_present', 'employment_social_registration_missing', 'dpp_annual_hours_exceeded', 'nesmysl'] as $code) {
            try {
                $this->service->hide($this->supplierId, $code, null, null, $this->userId);
                self::fail('Kontrolu ' . $code . ' nesmí jít skrýt.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->service->list($this->supplierId));
    }

    public function testSubjectsOfAnotherCompanyAreRejected(): void
    {
        $fixture = $this->seedUnsigned(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->hide($this->otherSupplierId, self::CODE, [$fixture->employeeIds[0]], null, $this->userId);
    }

    public function testEndpointsHideListAndRestore(): void
    {
        $fixture = $this->seedUnsigned(1);
        $action = $this->action;

        $hidden = $action->hide(
            $this->request('POST', ['code' => self::CODE, 'subject_ids' => [$fixture->employeeIds[0]], 'reason' => 'Důvod']),
            new Response(),
        );
        self::assertSame(200, $hidden->getStatusCode());
        self::assertSame(1, $this->json($hidden)['created']);

        $blocked = $action->hide($this->request('POST', ['code' => 'draft_inputs_present']), new Response());
        self::assertSame(422, $blocked->getStatusCode());

        $listed = $this->json($action->list($this->request('GET', []), new Response()));
        self::assertCount(1, $listed['items']);
        self::assertContains(self::CODE, $listed['hideable_codes']);

        $restored = $action->restoreOne($this->request('DELETE', []), new Response(), ['id' => (string) $listed['items'][0]['id']]);
        self::assertSame(1, $this->json($restored)['restored']);
        self::assertSame([], $this->service->list($this->supplierId));
    }

    // --- pomocníci ---------------------------------------------------------

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, '/api/payroll/warning-suppressions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function seedUnsigned(int $count): PayrollRunScaleFixture
    {
        $this->policies->create($this->supplierId, [
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'payday_day' => 10,
            'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day',
            'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used',
            'travel_expense_policy' => 'not_used',
            'leave_entitlement_weeks' => 4,
            'automatic_posting_enabled' => true,
            'delivery_channel' => 'disabled',
            'delivery_verified_on' => null,
            'source_kind' => 'manual',
            'source_reference' => 'synthetic:warning-suppression-policy',
        ], $this->userId);
        $fixture = new PayrollRunScaleFixture($this->db, $this->supplierId, $this->userId, 7_960_000_000);
        $fixture->seed($count);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_tax_declarations WHERE supplier_id = ?'
        )->execute([$this->supplierId]);
        foreach ($fixture->employeeIds as $employeeId) {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_person_tax_declarations
                    (supplier_id, employee_id, status, effective_from)
                 VALUES (?, ?, "not-signed", "2026-01-01")'
            )->execute([$this->supplierId, $employeeId]);
        }

        return $fixture;
    }

    private function summary(): ?PayrollRunValidation
    {
        return self::firstSummary($this->builder->build(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        )->validations);
    }

    private function visibleSummary(): ?PayrollRunValidation
    {
        $validations = $this->builder->build(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        )->validations;

        return self::firstSummary(
            $this->service->activeSet($this->supplierId)->filterValidations($validations),
        );
    }

    /** @return list<string> */
    private function readinessCodes(string $code): array
    {
        $result = $this->readiness->inspect(
            $this->supplierId,
            PayrollRunScaleFixture::PERIOD_START,
            PayrollRunScaleFixture::PAYMENT_DATE,
        );
        $codes = [];
        foreach ($result['findings'] as $finding) {
            if ($finding['code'] === $code) {
                self::assertTrue($finding['hideable']);
                self::assertSame('employee', $finding['subject_type']);
                self::assertNotSame([], $finding['subject_ids']);
                $codes[] = $finding['code'];
            }
        }

        return $codes;
    }

    /** @param list<PayrollRunValidation> $validations */
    private static function firstSummary(array $validations): ?PayrollRunValidation
    {
        foreach ($validations as $validation) {
            if ($validation->code === self::CODE) {
                return $validation;
            }
        }

        return null;
    }

    private function activityCount(string $action): int
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM activity_log WHERE supplier_id = ? AND action = ?'
        );
        $statement->execute([$this->supplierId, $action]);

        return (int) $statement->fetchColumn();
    }
}
