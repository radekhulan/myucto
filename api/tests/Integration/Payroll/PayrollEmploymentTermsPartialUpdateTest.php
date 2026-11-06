<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Action\Payroll\PayrollEmploymentAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollEmploymentLifecycleSql;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollAttendanceImportRepository;
use MyInvoice\Repository\Payroll\PayrollHealthNotificationRepository;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Issue #113 — podmínky pracovního vztahu: klíč, který v těle chybí, drží
 * uloženou hodnotu platné verze; výslovný null ji maže.
 */
#[Group('integration')]
final class PayrollEmploymentTermsPartialUpdateTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PayrollEmploymentAction $action;
    private PayrollEmploymentRepository $employments;
    private PayrollComponentRepository $components;
    private PayrollAttendanceImportRepository $attendance;
    private PayrollHealthNotificationRepository $healthNotifications;
    private int $supplierId;
    private int $employeeId;
    private int $officeId;
    private int $userId;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->db = $container->get(Connection::class);
        if (!$this->db->hasTable('payroll_employment_terms')) {
            $this->markTestSkipped('Migrace 1195 neproběhla.');
        }
        $this->action = $container->get(PayrollEmploymentAction::class);
        $this->employments = $container->get(PayrollEmploymentRepository::class);
        $this->components = $container->get(PayrollComponentRepository::class);
        $this->attendance = $container->get(PayrollAttendanceImportRepository::class);
        $this->healthNotifications = $container->get(PayrollHealthNotificationRepository::class);

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí výchozí firma nebo uživatel.');
        }

        $pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        $pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare(
            'INSERT INTO payroll_employees
                (supplier_id, full_name, taxpayer_type, employment_type,
                 tax_declaration_signed, tax_credit_taxpayer, child_count,
                 monthly_gross, auto_post, is_active)
             VALUES (?, "Syntetický Pracovník", "employee", "hpp", 0, 0, 0, NULL, 0, 1)'
        )->execute([$this->supplierId]);
        $this->employeeId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO payroll_offices (supplier_id, code, name, is_active)
             VALUES (?, 'MAIN', 'Hlavní účtárna', 1)"
        )->execute([$this->supplierId]);
        $this->officeId = (int) $pdo->lastInsertId();
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

    public function testCorrectionWithPartialBodyKeepsStoredTermsAndNullClears(): void
    {
        $employment = $this->activeEmployment();

        $corrected = $this->call('correctTerms', $employment, [
            'row_version' => $employment['row_version'],
            'weekly_hours' => '30',
        ]);
        $terms = $corrected['terms'][0];
        self::assertSame('30.00', $terms['weekly_hours']);
        self::assertSame(7500, $terms['workload_basis_points']);
        self::assertSame('Praha', $terms['work_place']);
        self::assertSame('2026-12-31', $terms['fixed_term_end_on']);
        self::assertSame('2025-12-20', $terms['contract_signed_on']);
        self::assertSame($this->officeId, $terms['office_id']);
        self::assertSame('1', $terms['activity_code']);
        self::assertCount(1, $corrected['terms']);

        $cleared = $this->call('correctTerms', $corrected, [
            'row_version' => $corrected['row_version'],
            'fixed_term_end_on' => null,
        ]);
        self::assertNull($cleared['terms'][0]['fixed_term_end_on']);
        self::assertSame(7500, $cleared['terms'][0]['workload_basis_points']);
    }

    public function testNewVersionWithPartialBodyInheritsCurrentTerms(): void
    {
        $employment = $this->activeEmployment();

        $changed = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'],
            'effective_from' => '2026-03-01',
            'weekly_hours' => '20',
        ]);
        self::assertCount(2, $changed['terms']);
        $terms = $changed['terms'][0];
        self::assertSame('2026-03-01', $terms['effective_from']);
        self::assertSame('20.00', $terms['weekly_hours']);
        self::assertSame(7500, $terms['workload_basis_points']);
        self::assertSame('Praha', $terms['regular_workplace']);
        self::assertSame('2026-12-31', $terms['fixed_term_end_on']);
        self::assertSame($this->officeId, $terms['office_id']);
        // Důvod změny patří k zápisu, ze staré verze se nepřebírá.
        self::assertNull($terms['change_reason']);
    }

    public function testModeAndRateCanChangeMidMonthWithoutLosingHistory(): void
    {
        $employment = $this->activeEmployment();
        $payload = ['row_version' => $employment['row_version'], 'effective_from' => '2026-03-15',
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement', 'remuneration_basis' => 'Syntetický výměr'];
        $hourly = $this->call('addTerms', $employment, $payload);
        self::assertSame('2026-03-15', $hourly['terms'][0]['effective_from']);
        self::assertSame('2026-03-14', $hourly['terms'][1]['effective_to']);
        self::assertSame(4000000, $hourly['terms'][1]['monthly_gross_minor']);
        self::assertNull($hourly['terms'][0]['monthly_gross_minor']);
        $stmt = $this->db->pdo()->prepare('SELECT monthly_gross_minor FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employment['id']]);
        self::assertNull($stmt->fetchColumn());
        $hourly = $this->call('correctTerms', $hourly, [
            'row_version' => $hourly['row_version'], 'correct_effective_from' => true,
            'effective_from' => '2026-03-16', 'change_reason' => 'Syntetická oprava data',
        ]);
        self::assertSame('2026-03-16', $hourly['terms'][0]['effective_from']);
        self::assertSame('2026-03-15', $hourly['terms'][1]['effective_to']);
        $bearer = $this->action->addTerms($this->request('PUT', '/terms', [
            'row_version' => $hourly['row_version'], 'effective_from' => '2026-04-01',
            'monthly_gross_minor' => 3000000, 'change_reason' => 'Syntetický pokus',
        ])->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer'), new Response(), ['id' => (string) $employment['id']]);
        self::assertSame(422, $bearer->getStatusCode());
        self::assertStringContainsString('U hodinového vztahu', (string) $bearer->getBody());
        $rate = $this->call('addTerms', $hourly, ['row_version' => $hourly['row_version'], 'effective_from' => '2026-03-20', 'hourly_rate_minor' => 21000]);
        self::assertSame(21000, $rate['terms'][0]['hourly_rate_minor']);
        self::assertSame(20000, $rate['terms'][1]['hourly_rate_minor']);
        $monthly = $this->call('addTerms', $rate, [
            'row_version' => $rate['row_version'], 'effective_from' => '2026-04-10',
            'remuneration_type' => 'monthly', 'hourly_rate_minor' => null, 'monthly_gross_minor' => 3000000,
        ]);
        self::assertSame('2026-04-10', $monthly['terms'][0]['effective_from']);
        self::assertSame(3000000, $monthly['terms'][0]['monthly_gross_minor']);
        self::assertNull($monthly['terms'][0]['hourly_rate_minor']);
    }

    public function testExplicitRemunerationCannotReturnToLegacyMode(): void
    {
        $employment = $this->activeEmployment();
        $hourly = $this->call('addTerms', $employment, ['row_version' => $employment['row_version'], 'effective_from' => '2026-03-01',
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement', 'remuneration_basis' => 'Syntetický výměr']);
        $legacy = ['remuneration_type' => null, 'hourly_rate_minor' => null, 'remuneration_basis_kind' => null, 'remuneration_basis' => null];
        foreach (['addTerms' => ['effective_from' => '2026-04-01', 'monthly_gross_minor' => 3000000],
            'correctTerms' => ['change_reason' => 'Syntetická oprava', 'monthly_gross_minor' => 3000000]] as $method => $body) {
            $response = $this->action->{$method}($this->request('PUT', '/terms', ['row_version' => $hourly['row_version'], ...$legacy, ...$body]),
                new Response(), ['id' => (string) $employment['id']]);
            self::assertSame(422, $response->getStatusCode(), $method . ': ' . (string) $response->getBody());
            self::assertStringContainsString('dosavadní režim', (string) $response->getBody());
        }
        $monthly = $this->call('addTerms', $hourly, ['row_version' => $hourly['row_version'], 'effective_from' => '2026-04-01',
            'remuneration_type' => 'monthly', 'hourly_rate_minor' => null, 'monthly_gross_minor' => 3000000]);
        $response = $this->action->addTerms($this->request('PUT', '/terms', ['row_version' => $monthly['row_version'], ...$legacy,
            'effective_from' => '2026-05-01', 'monthly_gross_minor' => 3000000]), new Response(), ['id' => (string) $employment['id']]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testFirstTermsOfMidMonthStartCanBeHourlyFromStart(): void
    {
        $response = $this->action->create($this->request('POST', "/api/payroll/people/{$this->employeeId}/employments", [
            'code' => 'SYN-HOD-STRED',
            'relation_type' => 'employment',
            'terms' => [
                'office_id' => $this->officeId, 'effective_from' => '2026-03-16', 'contract_signed_on' => '2026-03-02',
                'planned_start_on' => '2026-03-16', 'actual_start_on' => null, 'fixed_term_end_on' => null,
                'weekly_hours' => '40', 'workload_basis_points' => 10000, 'work_place' => 'Praha', 'regular_workplace' => 'Praha',
                'jmhz_apz_contribution_status' => 'unverified', 'jmhz_functional_benefits_status' => 'unverified',
                'jmhz_temporary_assignment_status' => 'unverified', 'cz_isco_code' => '43111', 'activity_code' => '1',
                'jmhz_relationship_detail_code' => '1', 'social_insurance_participation' => 'automatic',
                'health_insurance_participation' => 'automatic', 'tax_regime' => 'advance', 'tax_declaration_signed' => true,
                'is_primary' => true, 'change_reason' => 'Syntetické podmínky',
                'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000,
                'remuneration_basis_kind' => 'contract', 'remuneration_basis' => 'Syntetická smlouva',
            ],
        ]), new Response(), ['id' => (string) $this->employeeId]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $employment = $this->json($response)['employment'];
        self::assertSame('hourly', $employment['terms'][0]['remuneration_type']);
        self::assertSame('2026-03-16', $employment['terms'][0]['effective_from']);
        $employment = $this->transition($employment, 'preregistered', '2026-03-16');
        $employment = $this->transition($employment, 'active', '2026-03-16');
        $corrected = $this->call('correctTerms', $employment, ['row_version' => $employment['row_version'],
            'hourly_rate_minor' => 21000, 'change_reason' => 'Syntetická oprava sazby']);
        self::assertSame(21000, $corrected['terms'][0]['hourly_rate_minor']);
        self::assertSame('2026-03-16', $corrected['terms'][0]['effective_from']);
    }

    public function testLegacyToExplicitMonthlyCanStartMidMonth(): void
    {
        $employment = $this->activeEmployment();
        $changed = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'], 'effective_from' => '2026-02-15',
            'remuneration_type' => 'monthly',
        ]);
        self::assertSame('monthly', $changed['terms'][0]['remuneration_type']);
        self::assertSame(4000000, $changed['terms'][0]['monthly_gross_minor']);
        self::assertNull($changed['terms'][1]['remuneration_type']);
    }

    public function testConcurrentEmploymentKeepsItsOwnRemuneration(): void
    {
        $monthly = $this->activeEmployment();
        $terms = [...$monthly['terms'][0],
            'actual_start_on' => null, 'is_primary' => false, 'activity_code' => null,
            'jmhz_relationship_detail_code' => null, 'monthly_gross_minor' => null,
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 25000,
            'remuneration_basis_kind' => 'agreement', 'remuneration_basis' => 'Syntetická dohoda',
        ];
        $response = $this->action->create($this->request('POST', '/employments', [
            'code' => 'SYN-DPP', 'relation_type' => 'dpp', 'terms' => $terms,
        ]), new Response(), ['id' => (string) $this->employeeId]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $hourly = $this->json($response)['employment'];
        self::assertSame('hourly', $hourly['terms'][0]['remuneration_type']);
        self::assertSame(25000, $hourly['terms'][0]['hourly_rate_minor']);
        self::assertNull($hourly['monthly_gross_minor']);
        $relationships = $this->employments->listForEmployee($this->supplierId, $this->employeeId);
        $first = array_values(array_filter($relationships, static fn (array $item): bool => $item['id'] === $monthly['id']))[0];
        self::assertNull($first['terms'][0]['remuneration_type']);
        self::assertSame(4000000, $first['terms'][0]['monthly_gross_minor']);
    }

    public function testHourlyModeRejectsActiveBaseWageAndAmbiguousLegacyProjection(): void
    {
        $employment = $this->activeEmployment();
        $this->components->ensureDefaults($this->supplierId);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = "MZDA_MESICNI"');
        $stmt->execute([$this->supplierId]);
        $component = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $component);
        $this->db->pdo()->prepare('INSERT INTO payroll_recurring_components (supplier_id, employment_id, component_id, calculation_kind, amount_minor, valid_from, allocation_rule) VALUES (?, ?, ?, "fixed_amount", 4000000, "2026-01-01", "full_month")')
            ->execute([$this->supplierId, $employment['id'], $component]);
        $payload = ['row_version' => $employment['row_version'], 'effective_from' => '2026-03-01',
            'remuneration_type' => 'hourly', 'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement', 'remuneration_basis' => 'Syntetický výměr'];
        $response = $this->action->addTerms($this->request('PUT', '/terms', $payload), new Response(), ['id' => (string) $employment['id']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('předpis', (string) $response->getBody());
        $this->db->pdo()->prepare('UPDATE payroll_recurring_components SET is_active = 0 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $employment['id']]);
        // Syntetická starší instalace: pouze projekce nese mzdu, stará verze ji nemá.
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET monthly_gross_minor = NULL WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $employment['id']]);
        $response = $this->action->addTerms($this->request('PUT', '/terms', $payload), new Response(), ['id' => (string) $employment['id']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Starší podmínky', (string) $response->getBody());
        $stmt = $this->db->pdo()->prepare('SELECT monthly_gross_minor FROM payroll_employments WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$this->supplierId, $employment['id']]);
        self::assertSame(4000000, (int) $stmt->fetchColumn());
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET monthly_gross_minor = 4000000 WHERE supplier_id = ? AND employment_id = ?')->execute([$this->supplierId, $employment['id']]);
        $hourly = $this->call('addTerms', $employment, $payload);
        self::assertSame('hourly', $hourly['terms'][0]['remuneration_type']);
        self::assertSame(4000000, $hourly['terms'][1]['monthly_gross_minor']);
    }

    public function testDatabaseRejectsNullModeWithHourlyRate(): void
    {
        $employment = $this->activeEmployment();
        $this->expectException(\PDOException::class);
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET remuneration_type = NULL, hourly_rate_minor = 20000, remuneration_basis_kind = "agreement", remuneration_basis = "Syntetický podklad", monthly_gross_minor = NULL WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $employment['id']]);
    }

    public function testHourlyVersionAndPartialCorrectionsPreserveHistoryAndDoNotInheritMonthlyWage(): void
    {
        $employment = $this->activeEmployment();
        $changed = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'],
            'effective_from' => '2026-03-01',
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement',
            'remuneration_basis' => 'Syntetický mzdový výměr',
        ]);
        self::assertCount(2, $changed['terms']);
        self::assertSame('hourly', $changed['terms'][0]['remuneration_type']);
        self::assertSame(20000, $changed['terms'][0]['hourly_rate_minor']);
        self::assertSame('wage_statement', $changed['terms'][0]['remuneration_basis_kind']);
        self::assertNull($changed['terms'][0]['monthly_gross_minor']);
        self::assertNull($changed['terms'][1]['remuneration_type']);
        self::assertSame(4000000, $changed['terms'][1]['monthly_gross_minor']);
        self::assertSame('2026-02-28', $changed['terms'][1]['effective_to']);
        $salary = $this->db->pdo()->prepare(
            'SELECT ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossAtPlaceholder() . '
               FROM payroll_employments employment WHERE supplier_id = ? AND id = ?',
        );
        $salary->execute(['2026-02-28', '2026-02-28', $this->supplierId, $employment['id']]);
        self::assertSame(4000000, $salary->fetchColumn());
        $salary->execute(['2026-03-01', '2026-03-01', $this->supplierId, $employment['id']]);
        self::assertNull($salary->fetchColumn());

        $corrected = $this->call('correctTerms', $changed, [
            'row_version' => $changed['row_version'],
            'weekly_hours' => '30',
        ]);
        self::assertSame('hourly', $corrected['terms'][0]['remuneration_type']);
        self::assertSame(20000, $corrected['terms'][0]['hourly_rate_minor']);
        self::assertSame('wage_statement', $corrected['terms'][0]['remuneration_basis_kind']);
        self::assertSame('Syntetický mzdový výměr', $corrected['terms'][0]['remuneration_basis']);
        self::assertNull($corrected['terms'][0]['monthly_gross_minor']);

        $next = $this->call('addTerms', $corrected, [
            'row_version' => $corrected['row_version'],
            'effective_from' => '2026-04-01',
            'hourly_rate_minor' => 21000,
        ]);
        self::assertSame(21000, $next['terms'][0]['hourly_rate_minor']);
        self::assertSame(20000, $next['terms'][1]['hourly_rate_minor']);
        self::assertNull($next['terms'][0]['monthly_gross_minor']);
    }

    public function testLegacyRepositoryCallerPreservesHourlyTermsWhenKeysAreMissing(): void
    {
        $employment = $this->activeEmployment();
        $changed = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'],
            'effective_from' => '2026-03-01',
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement',
            'remuneration_basis' => 'Syntetický výměr',
        ]);
        $repository = $this->employments;
        $body = $repository->currentTerms($this->supplierId, (int) $employment['id']);
        self::assertIsArray($body);
        unset($body['remuneration_type'], $body['hourly_rate_minor'], $body['remuneration_basis_kind'], $body['remuneration_basis']);
        $body['effective_from'] = '2026-04-01';
        $next = $repository->addTerms(
            $this->supplierId, (int) $employment['id'], $body, (int) $changed['row_version'],
            $this->userId, null, null,
        );
        self::assertSame('hourly', $next['terms'][0]['remuneration_type']);
        self::assertSame(20000, $next['terms'][0]['hourly_rate_minor']);
        self::assertSame('wage_statement', $next['terms'][0]['remuneration_basis_kind']);
        self::assertSame('Syntetický výměr', $next['terms'][0]['remuneration_basis']);
        self::assertNull($next['terms'][0]['monthly_gross_minor']);
    }

    public function testReturnToMonthlyRequiresExplicitWageAndHourlyReadersNeverUseOldProjection(): void
    {
        $employment = $this->activeEmployment();
        $hourly = $this->call('addTerms', $employment, [
            'row_version' => $employment['row_version'],
            'effective_from' => '2026-03-01',
            'remuneration_type' => 'hourly',
            'hourly_rate_minor' => 20000,
            'remuneration_basis_kind' => 'wage_statement',
            'remuneration_basis' => 'Syntetický výměr',
        ]);
        $attendance = $this->attendance->termsAt($this->supplierId, [(int) $employment['id']], '2026-03-01', '2026-03-31');
        self::assertNull($attendance[(int) $employment['id']]['monthly_gross_minor']);

        // Člen družstva se podle předpokládané účasti opírá o sjednaný měsíční příjem.
        $this->db->pdo()->prepare('UPDATE payroll_employment_terms SET health_association_member = 1 WHERE supplier_id = ? AND employment_id = ?')
            ->execute([$this->supplierId, $employment['id']]);
        $monthlyFacts = $this->healthNotifications->findNotificationFacts($this->supplierId, (int) $employment['id'], '2026-02-15');
        $hourlyFacts = $this->healthNotifications->findNotificationFacts($this->supplierId, (int) $employment['id'], '2026-03-15');
        self::assertTrue($monthlyFacts['participates']);
        self::assertFalse($hourlyFacts['participates']);
        $listed = $this->healthNotifications->listNotificationFacts($this->supplierId, '2026-03-01', '2026-03-31');
        self::assertFalse($listed[0]['participates']);

        foreach (['addTerms', 'correctTerms'] as $method) {
            foreach ([[], ['monthly_gross_minor' => null]] as $amount) {
                $response = $this->action->{$method}(
                    $this->request('PUT', '/api/payroll/employments/' . $employment['id'] . '/terms', [
                        'row_version' => $hourly['row_version'],
                        'effective_from' => '2026-04-01',
                        'remuneration_type' => 'monthly',
                        'hourly_rate_minor' => null,
                        ...$amount,
                    ]),
                    new Response(),
                    ['id' => (string) $employment['id']],
                );
                self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
            }
        }
        $monthly = $this->call('addTerms', $hourly, [
            'row_version' => $hourly['row_version'],
            'effective_from' => '2026-04-01',
            'remuneration_type' => 'monthly',
            'hourly_rate_minor' => null,
            'monthly_gross_minor' => 3500000,
        ]);
        self::assertSame(3500000, $monthly['terms'][0]['monthly_gross_minor']);
        self::assertNull($monthly['terms'][1]['monthly_gross_minor']);
    }

    /** @return array<string,mixed> */
    private function activeEmployment(): array
    {
        $response = $this->action->create(
            $this->request('POST', "/api/payroll/people/{$this->employeeId}/employments", [
                'code' => 'SYN-PART-1',
                'relation_type' => 'employment',
                'monthly_gross_minor' => 4000000,
                'terms' => [
                    'office_id' => $this->officeId,
                    'effective_from' => '2026-01-01',
                    'contract_signed_on' => '2025-12-20',
                    'planned_start_on' => '2026-01-01',
                    'actual_start_on' => null,
                    'fixed_term_end_on' => '2026-12-31',
                    'weekly_hours' => '40',
                    'workload_basis_points' => 7500,
                    'work_place' => 'Praha',
                    'regular_workplace' => 'Praha',
                    'jmhz_apz_contribution_status' => 'unverified',
                    'jmhz_functional_benefits_status' => 'unverified',
                    'jmhz_temporary_assignment_status' => 'unverified',
                    'cz_isco_code' => '43111',
                    'activity_code' => '1',
                    'jmhz_relationship_detail_code' => '1',
                    'social_insurance_participation' => 'automatic',
                    'health_insurance_participation' => 'automatic',
                    'tax_regime' => 'advance',
                    'tax_declaration_signed' => true,
                    'is_primary' => true,
                    'change_reason' => 'Syntetické podmínky',
                ],
            ]),
            new Response(),
            ['id' => (string) $this->employeeId],
        );
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $employment = $this->json($response)['employment'];
        $employment = $this->transition($employment, 'preregistered', '2026-01-01');

        return $this->transition($employment, 'active', '2026-01-02');
    }

    /**
     * @param array<string,mixed> $employment
     * @return array<string,mixed>
     */
    private function transition(array $employment, string $target, string $effectiveOn): array
    {
        $response = $this->action->transition(
            $this->request(
                'POST',
                "/api/payroll/employments/{$employment['id']}/transitions/{$target}",
                ['row_version' => $employment['row_version'], 'effective_on' => $effectiveOn],
            ),
            new Response(),
            ['id' => (string) $employment['id'], 'target' => $target],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    /**
     * @param array<string,mixed> $employment
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function call(string $method, array $employment, array $body): array
    {
        $response = $this->action->{$method}(
            $this->request('PUT', "/api/payroll/employments/{$employment['id']}/terms", $body),
            new Response(),
            ['id' => (string) $employment['id']],
        );
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response)['employment'];
    }

    /** @param array<string,mixed> $body */
    private function request(
        string $method,
        string $path,
        array $body,
    ): \Psr\Http\Message\ServerRequestInterface {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
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
}
