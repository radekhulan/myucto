<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DppoFinalSnapshotAuditTest extends TestCase
{
    private const YEAR = 2049;

    private Connection $db;
    private \MyInvoice\Service\Tax\Return\TaxReturnService $returns;
    private AccountingPeriodRepository $periods;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    private array $accounts = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->returns = $container->get(\MyInvoice\Service\Tax\Return\TaxReturnService::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->userId === 0 || $czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $constants = \MyInvoice\Service\Tax\TaxConstants::forYear(2026);
        $constants['year'] = self::YEAR;
        $pdo->prepare('INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')
            ->execute([self::YEAR, json_encode($constants, JSON_UNESCAPED_UNICODE)]);
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id,
                                   taxpayer_type, ic, dic, financial_office_code, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni)
             VALUES (?, "Zkušební 123/4", "Vzorov", "10000", ?, "dp-api@example.com", ?, ?,
                     "po", "12345678", "CZ12345678", "451", "62020", "Jan", "Novák", "jednatel")'
        );
        $stmt->execute(['DP API test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $period = $this->periods->findByYear($this->supplierId, self::YEAR);
        $this->periodId = (int) $period['id'];

        foreach ($pdo->query("SELECT account_code, id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId}")->fetchAll(\PDO::FETCH_KEY_PAIR) as $code => $id) {
            $this->accounts[(string) $code] = (int) $id;
        }

        $this->postEntry(self::YEAR . '-03-15', [['311', 'debit', 1000000], ['602', 'credit', 1000000]]);
        $this->postEntry(self::YEAR . '-04-10', [['518', 'debit', 300000], ['321', 'credit', 300000]]);
        $this->postEntry(self::YEAR . '-05-20', [['513', 'debit', 50000], ['321', 'credit', 50000]]);

        $this->postEntry(self::YEAR . '-12-31', [
            ['602', 'debit', 1000000], ['710', 'credit', 650000],
            ['518', 'credit', 300000], ['513', 'credit', 50000],
        ], 'closing');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    private function postEntry(string $date, array $lines, string $sourceType = 'manual'): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, posted_at, source_type, source_id)
             VALUES (?, ?, ?, NOW(), ?, ?)'
        );
        $stmt->execute([$this->supplierId, $this->periodId, $date, $sourceType, $sourceType === 'closing' ? $this->periodId : null]);
        $entryId = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare(
            'INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as [$code, $side, $amount]) {
            $ins->execute([$entryId, $this->supplierId, $this->accounts[$code], $side, $amount]);
        }
    }

    public function testFinalDppoDetailAndXmlStayFrozenAfterLedgerAndSupplierChanges(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'po', $draft['return']['row_version'], $this->userId);
        $xml = $this->returns->buildXml($this->supplierId, self::YEAR, 'po');
        $this->postEntry(self::YEAR . '-11-01', [['518', 'debit', 100000], ['321', 'credit', 100000]]);
        $this->db->pdo()->prepare('UPDATE supplier SET company_name = ?, tax_entity_status = "liquidation", tax_entity_status_date = ? WHERE id = ?')
            ->execute(['Jiná syntetická firma', '2049-10-01', $this->supplierId]);
        $after = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        self::assertSame($final['computed'], $after['computed']);
        self::assertSame($final['podklady'], $after['podklady']);
        self::assertSame($xml['xml'], $this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml']);
        self::assertNotNull($final['snapshot']);
        self::assertEqualsWithDelta($final['computed']['balance_due'], $this->returns->balanceDuePreview($this->supplierId, self::YEAR, 'po')['balance_due'], 0.001);
    }

    public function testFinalDppoXmlDoesNotRecomputeBeforeOtherAssertions(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $this->returns->finalize($this->supplierId, self::YEAR, 'po', $draft['return']['row_version'], $this->userId);
        $xml = $this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml'];
        $this->postEntry(self::YEAR . '-11-01', [['518', 'debit', 100000], ['321', 'credit', 100000]]);
        self::assertSame($xml, $this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml']);
    }

    public function testLegacyFinalExportsStoredTaxAndWarnsInDetail(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $repo = new \MyInvoice\Repository\TaxReturnRepository($this->db);
        $repo->finalize($this->supplierId, self::YEAR, 'po', [
            'computed' => $draft['computed'], 'podklady' => $draft['podklady'], 'warnings' => [],
            'prefinalize_check' => $draft['prefinalize_check'],
        ], $draft['return']['row_version']);
        $this->postEntry(self::YEAR . '-11-01', [['518', 'debit', 100000], ['321', 'credit', 100000]]);
        $after = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        self::assertSame($repo->find($this->supplierId, self::YEAR, 'po')['computed']['computed'], $after['computed']);
        self::assertStringContainsString('Starší finální DPPO', implode(' ', $after['warnings']));
        $built = $this->returns->buildXml($this->supplierId, self::YEAR, 'po');
        self::assertEqualsWithDelta($after['computed']['tax'], $built['summary']['total_tax'], 0.001);
        self::assertStringContainsString('Starší finální DPPO', implode(' ', $built['warnings']));
        self::assertStringContainsString('kc_ii_340="147000"', $built['xml']);
        self::assertGreaterThan(0, $this->returns->generateXml($this->supplierId, self::YEAR, 'po', $this->userId)['submission_id']);
    }
    public function testAdvanceRegenerationUsesFinalizedTaxDespiteLedgerChange(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $this->returns->finalize($this->supplierId, self::YEAR, 'po', $draft['return']['row_version'], $this->userId);
        $query = $this->db->pdo()->prepare('SELECT amount, due_date, source_return_id FROM tax_advance_schedules WHERE supplier_id = ? AND period_year = ? ORDER BY due_date');
        $query->execute([$this->supplierId, self::YEAR + 1]);
        $before = $query->fetchAll(\PDO::FETCH_ASSOC);
        self::assertNotEmpty($before);
        $this->postEntry(self::YEAR . '-11-01', [['518', 'debit', 100000], ['321', 'credit', 100000]]);
        $this->returns->generateAdvanceSchedules($this->supplierId, self::YEAR, 'po');
        $query->execute([$this->supplierId, self::YEAR + 1]);
        self::assertSame($before, $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testReopenAndRefinalizeCreatesNewSnapshotAndKeepsOldXml(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'po', $draft['return']['row_version'], $this->userId);
        self::assertNotNull($final['snapshot']);
        $oldXml = $this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml'];
        $reopened = $this->returns->reopen($this->supplierId, self::YEAR, 'po', $final['return']['row_version'], $this->userId);
        $this->postEntry(self::YEAR . '-11-01', [['518', 'debit', 100000], ['321', 'credit', 100000]]);
        $next = $this->returns->finalize($this->supplierId, self::YEAR, 'po', $reopened['return']['row_version'], $this->userId);
        self::assertNotSame($oldXml, $this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml']);
        self::assertSame(2, $next['snapshot']['revision_no']);
        $repo = new \MyInvoice\Repository\TaxReturnRepository($this->db);
        self::assertSame($oldXml, $repo->snapshot($this->supplierId, $final['snapshot']['id'])['xml_content']);
    }

    public function testLegacyFinalWithoutComputedExportsWithExplicitLiveWarning(): void
    {
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $repo = new \MyInvoice\Repository\TaxReturnRepository($this->db);
        $repo->finalize($this->supplierId, self::YEAR, 'po', [], $draft['return']['row_version']);
        $after = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        self::assertStringContainsString('výpočet', implode(' ', $after['warnings']));
        self::assertStringContainsString('aktuálních', implode(' ', $after['warnings']));
        $built = $this->returns->buildXml($this->supplierId, self::YEAR, 'po');
        self::assertNotEmpty($built['xml']);
        self::assertStringContainsString('aktuálních', implode(' ', $built['warnings']));
    }

    public function testDppoXsdErrorDoesNotPreventFinalSnapshot(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET financial_office_code = "BAD" WHERE id = ?')->execute([$this->supplierId]);
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'po', $this->userId);
        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'po', $draft['return']['row_version'], $this->userId);
        self::assertSame('final', $final['return']['status']);
        self::assertSame('failed', $final['snapshot']['business_status']);
        self::assertStringContainsString('XSD', implode(' ', $final['warnings']));
        self::assertNotEmpty($this->returns->buildXml($this->supplierId, self::YEAR, 'po')['xml']);
        $export = $this->returns->generateXml($this->supplierId, self::YEAR, 'po', $this->userId);
        self::assertSame('failed', $export['validation_status']);
        self::assertGreaterThan(0, $export['submission_id']);
    }

    public function testUnsupportedDppoCanBeExported(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET tax_public_benefit = 1 WHERE id = ?')->execute([$this->supplierId]);
        $built = $this->returns->buildXml($this->supplierId, self::YEAR, 'po');
        self::assertNotEmpty($built['warnings']);
        self::assertGreaterThan(0, $this->returns->generateXml($this->supplierId, self::YEAR, 'po', $this->userId)['submission_id']);
    }

    public function testFoValidationFindingsDoNotPreventFinalSnapshot(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET taxpayer_type = "fo", financial_office_code = "BAD" WHERE id = ?')->execute([$this->supplierId]);
        $draft = $this->returns->getReturn($this->supplierId, self::YEAR, 'fo', $this->userId);
        $repo = new \MyInvoice\Repository\TaxReturnRepository($this->db);
        $saved = $repo->updateInputs($this->supplierId, self::YEAR, 'fo', [
            's10_items' => [['income' => 1000, 'expenses' => 0, 'text' => 'Testovací příjem']],
        ], $draft['return']['row_version']);
        $final = $this->returns->finalize($this->supplierId, self::YEAR, 'fo', $saved['row_version'], $this->userId);
        self::assertSame('final', $final['return']['status']);
        self::assertSame('failed', $final['snapshot']['business_status']);
        self::assertStringContainsString('kód druhu příjmu', implode(' ', $final['warnings']));
        self::assertNotEmpty($this->returns->buildXml($this->supplierId, self::YEAR, 'fo')['xml']);
    }

}
