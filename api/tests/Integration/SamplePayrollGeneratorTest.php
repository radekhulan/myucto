<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use DateTimeImmutable;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollEmployerPolicyRepository;
use MyInvoice\Repository\Payroll\PayrollInstitutionAccountRepository;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Repository\Payroll\PayrollQuickInputRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Sample\SamplePayrollGenerator;
use MyInvoice\Service\Sample\SamplePayrollPurger;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class SamplePayrollGeneratorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $supplierId;
    private int $userId;

    protected function setUp(): void
    {
        $this->container = \MyInvoice\Tests\Support\PayrollTestContainer::build();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare('UPDATE supplier SET company_name = "Ukázková školící společnost", display_name = "Ukázková školící společnost", ic = "00000019", accounting_mode = "double_entry", payroll_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->pdo->prepare('INSERT INTO users (email, password_hash, name, role, locale, is_active) VALUES (?, ?, "Ukázková účetní", "readonly", "cs", 1)')->execute([
            'sample-payroll-' . bin2hex(random_bytes(8)) . '@example.invalid',
            '$2y$10$uses.only.synthetic.placeholder.hash00000000000000000',
        ]);
        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        }
    }

    public function testLinkedPayrollAndPurgePreserveExistingComponents(): void
    {
        $this->container->get(PayrollComponentRepository::class)->ensureDefaults($this->supplierId);
        $before = SamplePayrollPurger::snapshot($this->pdo, $this->supplierId);
        $counts = $this->generate();
        self::assertSame(4, $counts['payroll_persons']);
        self::assertSame(4, $counts['payroll_runs']);
        self::assertSame(12, $counts['payroll_results']);
        self::assertSame(3, $counts['payroll_posting_batches']);
        self::assertCount(3, $counts['journal_entry_ids']);
        self::assertSame('active', $this->scalar('SELECT status FROM payroll_module_state WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(24, (int) $this->scalar('SELECT COUNT(*) FROM payroll_payment_liabilities WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM payroll_absences WHERE supplier_id = ? AND status = "approved"', [$this->supplierId]));
        self::assertSame(2_600_000, (int) $this->scalar('SELECT amount_minor FROM payroll_inputs WHERE supplier_id = ? AND external_id = "quick-monthly:sample:2026-08:base:1"', [$this->supplierId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payroll_run_validations WHERE supplier_id = ? AND (severity = "blocker" OR requires_override = 1)', [$this->supplierId]));
        $removed = $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame(4, $removed['payroll_employees']);
        self::assertSame(4, $removed['payroll_period_ownership']);
        self::assertSame(array_filter($before), array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
        self::assertSame(0, (int) $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', [$this->supplierId]));
        self::assertNull($this->scalar('SELECT @payroll_sample_purge_supplier_id', []));
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND source_type = "payroll"', [$this->supplierId]));
    }

    public function testSampleEmploymentLifecycleMakesStaffVisibleInQuickInputs(): void
    {
        $this->generate(false);
        $quickInputs = $this->container->get(PayrollQuickInputRepository::class);
        foreach (['2026-07', '2026-09', '2026-10'] as $period) {
            $month = $quickInputs->month($this->supplierId, $period);
            self::assertCount(4, $month['items'], $period);
        }
        self::assertSame(4, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employment_events WHERE supplier_id = ? AND event_type = "status_changed" AND from_status = "planned" AND to_status = "active" AND effective_on = "2026-07-01"',
            [$this->supplierId],
        ));
        self::assertSame(4, (int) $this->scalar(
            'SELECT COUNT(*) FROM payroll_employments WHERE supplier_id = ? AND status = "active" AND actual_start_date = "2026-07-01"',
            [$this->supplierId],
        ));
        self::assertSame([], $quickInputs->month($this->supplierId, '2026-06')['items']);
    }

    /** Ukázková firma musí jít zaregistrovat: kód OSSZ z číselníku ČSSZ, ne zkratka „P". */
    public function testSampleEmployerHasSocialSecurityOfficeFromCodebook(): void
    {
        $this->generate(false);
        $code = (string) $this->scalar(
            'SELECT social_security_office_code FROM payroll_employer_settings WHERE supplier_id = ?',
            [$this->supplierId],
        );
        self::assertTrue(\MyInvoice\Service\Payroll\PayrollEmployerSettingsValidator::isValidSocialSecurityOfficeCode($code), $code);
    }

    public function testImmutableGuardRequiresExactOwnershipAndSupplier(): void
    {
        $this->generate();
        $auditId = (int) $this->scalar('SELECT MIN(id) FROM payroll_employer_policy_audit WHERE supplier_id = ?', [$this->supplierId]);
        self::assertGreaterThan(0, $auditId);
        $this->assertDeleteBlocked($auditId);
        $this->pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$this->supplierId + 1]);
        $this->assertDeleteBlocked($auditId);
        $type = SamplePayrollPurger::ownershipType('payroll_employer_policy_audit');
        $this->pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id = ? AND entity_type = ? AND entity_id = ?')->execute([$this->supplierId, $type, $auditId]);
        $this->pdo->prepare('SET @payroll_sample_purge_supplier_id = ?')->execute([$this->supplierId]);
        $this->assertDeleteBlocked($auditId);
        $this->pdo->exec('SET @payroll_sample_purge_supplier_id = NULL');
        try {
            $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
            self::fail('Odebrání nesmí odstranit neevidovaný závislý řádek.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('neevidovanými daty', $e->getMessage());
        }
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', [$this->supplierId]));
        $this->pdo->prepare('INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $auditId]);
        $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame([], array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
    }

    public function testExistingPayrollSetupSurvivesRepeatedSampleGenerationAndPurge(): void
    {
        $this->container->get(PayrollModuleStateRepository::class)->setActivation($this->supplierId, true, '2026-10-01', 0, $this->userId);
        $this->pdo->prepare('UPDATE supplier SET payroll_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->pdo->prepare('INSERT INTO payroll_offices (supplier_id, code, name, is_active) VALUES (?, "SKOLENI", "Vlastní syntetická účtárna", 1)')->execute([$this->supplierId]);
        $officeId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO payroll_employer_settings (supplier_id, default_office_id, social_security_office_code, payroll_contact_name) VALUES (?, ?, "P", "Vlastní syntetický kontakt")')->execute([$this->supplierId, $officeId]);
        $policyData = [
            'valid_from' => '2026-10-11', 'valid_to' => '2026-10-20', 'payday_day' => 10, 'payday_month_offset' => 1,
            'payday_business_day_rule' => 'previous_business_day', 'balance_rounding_mode' => 'exact_minor_units',
            'home_office_policy' => 'not_used', 'travel_expense_policy' => 'not_used', 'leave_entitlement_weeks' => 5,
            'automatic_posting_enabled' => false, 'delivery_channel' => 'disabled', 'delivery_verified_on' => null,
            'source_kind' => 'manual', 'source_reference' => 'test:vlastni-nastaveni',
        ];
        $this->container->get(PayrollEmployerPolicyRepository::class)->create($this->supplierId, $policyData, $this->userId);
        $policyData['valid_from'] = '2027-01-01';
        $policyData['valid_to'] = null;
        $this->container->get(PayrollEmployerPolicyRepository::class)->create($this->supplierId, $policyData, $this->userId);
        $policyData['valid_from'] = '2026-09-01';
        $policyData['valid_to'] = '2026-09-30';
        $policyData['payday_day'] = 23;
        $this->container->get(PayrollEmployerPolicyRepository::class)->create($this->supplierId, $policyData, $this->userId);
        $this->container->get(PayrollInstitutionAccountRepository::class)->create($this->supplierId, [
            'institution_type' => 'health_insurer', 'institution_code' => '111', 'institution_name' => 'Vlastní syntetická pojišťovna',
            'bank_account' => '1000000005/0100', 'currency_code' => 'CZK', 'variable_symbol' => '0000001901',
            'specific_symbol' => null, 'constant_symbol' => null, 'valid_from' => '2026-08-15', 'valid_to' => '2026-09-15',
            'source_kind' => 'official_document', 'source_reference' => 'test:vlastni-nastaveni', 'verified_on' => '2026-08-15',
        ], $this->userId);
        $tables = ['payroll_module_state', 'payroll_employer_settings', 'payroll_offices', 'payroll_employer_policies', 'payroll_employer_policy_audit', 'payroll_institutions', 'payroll_institution_accounts'];
        $before = $this->configurationRows($tables);
        for ($iteration = 0; $iteration < 2; $iteration++) {
            $counts = $this->generate();
            self::assertSame(12, $counts['payroll_results']);
            self::assertSame(3, $counts['payroll_posting_batches']);
            self::assertSame('active', $this->scalar('SELECT status FROM payroll_module_state WHERE supplier_id = ?', [$this->supplierId]));
            self::assertSame('2026-07-01', $this->scalar('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?', [$this->supplierId]));
            self::assertSame('2026-10-23', $this->scalar('SELECT payment_date FROM payroll_runs WHERE supplier_id = ? AND period_start = "2026-09-01"', [$this->supplierId]));
            self::assertSame('2026-10-31', $this->scalar('SELECT MAX(valid_to) FROM payroll_employer_policies WHERE supplier_id = ? AND source_reference = "sample:skoleni"', [$this->supplierId]));
            self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payroll_payment_liabilities WHERE supplier_id = ?', [$this->supplierId]));
            self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM payroll_runs WHERE supplier_id = ? AND status = "posted"', [$this->supplierId]));
            $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
            self::assertSame($before, $this->configurationRows($tables));
            self::assertSame(1, (int) $this->scalar('SELECT payroll_enabled FROM supplier WHERE id = ?', [$this->supplierId]));
            $this->pdo->prepare('DELETE FROM sample_data_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
        }
    }

    public function testChangedModuleConfigurationBlocksPurgeWithoutDeletingPayroll(): void
    {
        $this->container->get(PayrollModuleStateRepository::class)->setActivation($this->supplierId, false, null, 0, $this->userId);
        $before = $this->configurationRows(['payroll_module_state']);
        $this->generate();
        $generated = $this->configurationRows(['payroll_module_state'])['payroll_module_state'][0];
        $this->pdo->prepare('UPDATE payroll_module_state SET row_version = row_version + 1 WHERE supplier_id = ?')->execute([$this->supplierId]);
        try {
            $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
            self::fail('Změněné vlastní nastavení nesmí být přepsáno.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('se od generování ukázek změnilo', $e->getMessage());
        }
        self::assertSame(4, (int) $this->scalar('SELECT COUNT(*) FROM payroll_employees WHERE supplier_id = ?', [$this->supplierId]));
        $this->pdo->prepare('UPDATE payroll_module_state SET row_version = ?, updated_at = ? WHERE supplier_id = ?')->execute([$generated['row_version'], $generated['updated_at'], $this->supplierId]);
        $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame($before, $this->configurationRows(['payroll_module_state']));
    }

    public function testExistingPayrollCannotBeGeneratedOver(): void
    {
        $this->generate();
        $before = SamplePayrollPurger::snapshot($this->pdo, $this->supplierId);
        try {
            $this->generate();
            self::fail('Existující mzdová evidence musí generování zastavit.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('existujících zaměstnanců a mzdových běhů', $e->getMessage());
        }
        self::assertSame($before, SamplePayrollPurger::snapshot($this->pdo, $this->supplierId));
    }

    private function configurationRows(array $tables): array
    {
        $rows = [];
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SELECT * FROM `$table` WHERE supplier_id = ? ORDER BY 1, 2");
            $stmt->execute([$this->supplierId]);
            $rows[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $rows;
    }

    public function testTaxEvidencePayrollDoesNotCreateJournalEntries(): void
    {
        $this->pdo->prepare('UPDATE supplier SET accounting_mode = "tax_evidence" WHERE id = ?')->execute([$this->supplierId]);
        $counts = $this->generate(false);
        self::assertSame(0, $counts['payroll_posting_batches']);
        self::assertSame([], $counts['journal_entry_ids']);
        self::assertSame(3, (int) $this->scalar('SELECT COUNT(*) FROM payroll_runs WHERE supplier_id = ? AND status = "approved"', [$this->supplierId]));
        $this->container->get(SamplePayrollPurger::class)->purge($this->supplierId);
        self::assertSame([], array_filter(SamplePayrollPurger::snapshot($this->pdo, $this->supplierId)));
    }

    private function generate(bool $advanced = true): array
    {
        return $this->container->get(SamplePayrollGenerator::class)->generate($this->supplierId, $this->userId, new DateTimeImmutable('2026-10-11'), $advanced, function (string $type, int $id): void {
            if ($id <= 0) return;
            $this->pdo->prepare('INSERT IGNORE INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $id]);
        });
    }

    private function assertDeleteBlocked(int $auditId): void
    {
        try {
            $this->pdo->prepare('DELETE FROM payroll_employer_policy_audit WHERE supplier_id = ? AND id = ?')->execute([$this->supplierId, $auditId]);
            self::fail('Neměnný řádek nesmí být odstraněn bez přesné evidence a rozsahu.');
        } catch (PDOException $e) {
            self::assertSame('45000', $e->getCode());
        }
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
