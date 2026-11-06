<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Sample\SampleDimensionGenerator;
use MyInvoice\Service\Sample\SampleDimensionPurger;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[Group('integration')]
final class SampleDimensionGeneratorTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $supplierId;
    private int $clientId;
    private int $projectId;
    private int $carId;
    private int $currencyId;
    private int $periodId;

    protected function setUp(): void
    {
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $this->pdo->beginTransaction();
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, (int) $this->scalar('SELECT MIN(id) FROM supplier'));
        $this->pdo->prepare('UPDATE supplier SET company_name="Ukázkové dimenze", display_name="Ukázkové dimenze", supplier_group_id=NULL, dimensions_enabled=0, accounting_mode="double_entry" WHERE id=?')->execute([$this->supplierId]);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->currencyId = (int) $this->scalar('SELECT id FROM currencies WHERE code="CZK" LIMIT 1');
        $this->pdo->prepare('INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id) VALUES (?, "Ukázkový zákazník", "Modelová 1", "Praha", "10000", (SELECT id FROM countries WHERE iso2="CZ" LIMIT 1), "demo@example.invalid", ?)')->execute([$this->supplierId, $this->currencyId]);
        $this->clientId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, "Ukázková instalace", ?)')->execute([$this->clientId, $this->currencyId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->track('project', $this->projectId);
        $this->pdo->prepare('INSERT INTO cars (supplier_id, registration, name, fuel_type, odometer_start, odometer_start_date) VALUES (?, "DEMO-001", "Ukázkový vůz", "diesel", 0, "2098-01-01")')->execute([$this->supplierId]);
        $this->carId = (int) $this->pdo->lastInsertId();
        $this->track('car', $this->carId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->periodId = (int) $this->container->get(AccountingPeriodRepository::class)->findForDate($this->supplierId, '2098-06-01')['id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function testLinkedDocumentsAndJournalRoundTrip(): void
    {
        $invoice = $this->invoice($this->projectId);
        $other = $this->invoice(null);
        $entry = $this->journal($invoice);
        $otherEntry = $this->journal($other);
        $before = $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]);
        $counts = $this->generate();
        self::assertSame(5, $counts['dimension_types']);
        self::assertSame(3, $counts['dimension_values']);
        self::assertSame(2, $counts['dimension_documents']);
        self::assertSame(2, $counts['dimension_journal_lines']);
        self::assertSame(2, $counts['dimension_defaults']);
        $values = $this->values();
        self::assertSame($this->projectId, (int) $values['project']['project_id']);
        self::assertSame($this->carId, (int) $values['vehicle']['car_id']);
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        self::assertCount(2, $assignments->documentDimensions($this->supplierId, 'invoice', $invoice)['header']);
        self::assertCount(1, $assignments->documentDimensions($this->supplierId, 'invoice', $other)['header']);
        self::assertCount(2, array_values($assignments->entryLineDimensions($this->supplierId, $entry))[0]);
        self::assertCount(1, array_values($assignments->entryLineDimensions($this->supplierId, $otherEntry))[0]);
        self::assertSame($before, $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]));
        $removed = $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
        self::assertSame(3, $removed['dimension_values']);
        self::assertSame(5, $removed['dimension_types']);
        self::assertSame(0, (int) $this->scalar('SELECT dimensions_enabled FROM supplier WHERE id=?', [$this->supplierId]));
        self::assertSame([], $this->container->get(DimensionRepository::class)->listTypes($this->supplierId));
        self::assertSame([], $assignments->documentDimensions($this->supplierId, 'invoice', $invoice)['header']);
        self::assertSame([], $assignments->entryLineDimensions($this->supplierId, $entry));
        self::assertTrue($this->pdo->inTransaction());
    }

    public function testPreexistingTypesValuesAndEnabledFlagRemainUntouched(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId);
        $value = $dimensions->createValue($this->supplierId, $types['cost_center'], ['code' => 'PUVODNI', 'name' => 'Původní ukázkové středisko']);
        $invoice = $this->invoice($this->projectId);
        $dimensions->saveDocument($this->supplierId, 'invoice', $invoice, [$types['cost_center'] => $value['id']], []);
        $beforeTypes = $this->container->get(DimensionRepository::class)->listTypes($this->supplierId);
        $counts = $this->generate();
        self::assertSame(0, $counts['dimension_types']);
        $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
        self::assertSame($beforeTypes, $this->container->get(DimensionRepository::class)->listTypes($this->supplierId));
        self::assertSame($value, $this->container->get(DimensionRepository::class)->findValue($this->supplierId, $value['id']));
        self::assertTrue($dimensions->enabled($this->supplierId));
        self::assertSame([$types['cost_center'] => $value['id']], $dimensions->documentDimensions($this->supplierId, 'invoice', $invoice)['header']);
    }

    public function testJournalAssignmentReadsDoNotGrowPerLineAndAmountsSurvivePurge(): void
    {
        $invoice = $this->invoice($this->projectId);
        $entry = $this->journal($invoice);
        $this->pdo->exec('SAVEPOINT sample_dimension_single');
        $beforeSelects = $this->selectCount();
        $single = $this->generate();
        $singleSelects = $this->selectCount() - $beforeSelects;
        self::assertSame(1, $single['dimension_journal_lines']);
        $this->pdo->exec('ROLLBACK TO SAVEPOINT sample_dimension_single');
        $this->pdo->exec('RELEASE SAVEPOINT sample_dimension_single');
        $manualEntry = $this->journal(null, revenueLines: 19);
        $before = $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]);
        $beforeSelects = $this->selectCount();
        $many = $this->generate();
        $manySelects = $this->selectCount() - $beforeSelects;
        self::assertSame(20, $many['dimension_journal_lines']);
        self::assertSame(20, (int) $this->scalar('SELECT COUNT(*) FROM sample_data_entries WHERE supplier_id=? AND entity_type="dim_line"', [$this->supplierId]));
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        self::assertCount(1, $assignments->entryLineDimensions($this->supplierId, $entry));
        self::assertCount(19, $assignments->entryLineDimensions($this->supplierId, $manualEntry));
        self::assertSame($before, $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]));
        self::assertSame([], $this->rows('SELECT entry_id FROM journal_entry_lines WHERE supplier_id=? GROUP BY entry_id HAVING SUM(CASE WHEN side="debit" THEN amount ELSE -amount END)<>0', [$this->supplierId]));
        $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
        self::assertSame([], $assignments->entryLineDimensions($this->supplierId, $entry));
        self::assertSame([], $assignments->entryLineDimensions($this->supplierId, $manualEntry));
        self::assertSame($before, $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]));
        self::assertLessThanOrEqual($singleSelects + 25, $manySelects, "SELECT při 1/20 řádcích: {$singleSelects}/{$manySelects}");
    }

    public function testJournalBatchPreservesExistingValuesAndSplits(): void
    {
        $dimensions = $this->container->get(DimensionService::class);
        $dimensions->setEnabled($this->supplierId, true);
        $types = $dimensions->ensureDefaultTypes($this->supplierId);
        $centerType = $types['cost_center'];
        $a = $dimensions->createValue($this->supplierId, $centerType, ['code' => 'PUVODNI-A', 'name' => 'Původní středisko A']);
        $b = $dimensions->createValue($this->supplierId, $centerType, ['code' => 'PUVODNI-B', 'name' => 'Původní středisko B']);
        $entry = $this->journal(null, revenueLines: 3);
        $lineIds = array_map('intval', array_column($this->rows('SELECT l.id FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND l.entry_id=? AND a.account_code="602" ORDER BY l.id', [$this->supplierId, $entry]), 'id'));
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $originalDimensions = [$lineIds[0] => [$centerType => (int) $a['id']]];
        $originalSplits = [$lineIds[1] => [$centerType => [(int) $a['id'] => 0.25, (int) $b['id'] => 0.75]]];
        $assignments->insertLineAssignments($this->supplierId, [
            $lineIds[0] => ['dimensions' => $originalDimensions[$lineIds[0]]],
            $lineIds[1] => ['dimension_splits' => $originalSplits[$lineIds[1]]],
        ]);
        $before = $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]);
        self::assertSame(1, $this->generate()['dimension_journal_lines']);
        $sampleCenter = (int) $this->values()['cost_center']['id'];
        self::assertSame($originalDimensions + [$lineIds[2] => [$centerType => $sampleCenter]], $assignments->lineDimensions($this->supplierId, $lineIds));
        self::assertSame($originalSplits, $assignments->lineSplits($this->supplierId, $lineIds));
        $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
        self::assertSame($originalDimensions, $assignments->lineDimensions($this->supplierId, $lineIds));
        self::assertSame($originalSplits, $assignments->lineSplits($this->supplierId, $lineIds));
        self::assertSame($before, $this->rows('SELECT id, account_id, side, amount FROM journal_entry_lines WHERE supplier_id=? ORDER BY id', [$this->supplierId]));
    }

    public function testExternalDocumentsChildrenAndJournalBlockButVerifiedLaterJournalCanBeRemoved(): void
    {
        $this->generate();
        $values = $this->values();
        $center = $values['cost_center'];
        $externalInvoice = $this->invoice(null, false);
        $assignments = $this->container->get(DimensionAssignmentRepository::class);
        $assignments->addHeaderDimensionIfMissing($this->supplierId, 'invoice', $externalInvoice, (int) $center['type_id'], (int) $center['id']);
        $this->assertBlocked();
        $assignments->deleteDocument($this->supplierId, 'invoice', $externalInvoice);
        $child = $this->container->get(DimensionService::class)->createValue($this->supplierId, (int) $center['type_id'], ['code' => 'NOVY-POTOMEK', 'name' => 'Nový uživatelský potomek', 'parent_id' => (int) $center['id'], 'cost_center_id' => null]);
        $this->assertBlocked();
        $this->container->get(DimensionRepository::class)->deleteValue($this->supplierId, $child['id']);
        $this->pdo->prepare('INSERT INTO projects (client_id, name, currency_id) VALUES (?, "Vlastní projekt po školení", ?)')->execute([$this->clientId, $this->currencyId]);
        $externalProject = (int) $this->pdo->lastInsertId();
        $defaultId = (int) $this->scalar('SELECT MIN(id) FROM dimension_defaults WHERE supplier_id=?', [$this->supplierId]);
        $this->pdo->prepare('UPDATE dimension_defaults SET project_id=? WHERE supplier_id=? AND id=?')->execute([$externalProject, $this->supplierId, $defaultId]);
        $this->assertBlocked();
        $this->pdo->prepare('UPDATE dimension_defaults SET project_id=? WHERE supplier_id=? AND id=?')->execute([$this->projectId, $this->supplierId, $defaultId]);
        $entry = $this->journal(null, false);
        $line = (int) $this->scalar('SELECT MIN(id) FROM journal_entry_lines WHERE supplier_id=? AND entry_id=?', [$this->supplierId, $entry]);
        $assignments->insertLineDimensions($this->supplierId, $line, [(int) $center['type_id'] => (int) $center['id']]);
        $this->assertBlocked();
        $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId, [$entry]);
        self::assertSame([], $assignments->entryLineDimensions($this->supplierId, $entry));
    }

    public function testGroupDefaultsAndSharedValuesSurvive(): void
    {
        $repo = $this->container->get(DimensionRepository::class);
        $group = $repo->createGroup('Ukázková skupina');
        $repo->setSupplierGroup($this->supplierId, $group);
        $types = $this->container->get(DimensionService::class)->ensureDefaultTypes($this->supplierId);
        $shared = $this->container->get(DimensionService::class)->createValue($this->supplierId, $types['project'], ['code' => 'SDILENY', 'name' => 'Původní sdílený projekt']);
        $before = $repo->listTypes($this->supplierId);
        self::assertSame(1, $this->generate()['dimension_types']);
        $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
        self::assertSame($before, $repo->listTypes($this->supplierId));
        self::assertSame($shared, $repo->findValue($this->supplierId, $shared['id']));
        self::assertSame($group, $repo->groupIdOf($this->supplierId));
    }

    private function invoice(?int $projectId, bool $tracked = true): int
    {
        $number = 'DEMO-DIM-' . bin2hex(random_bytes(5));
        $this->pdo->prepare('INSERT INTO invoices (supplier_id, client_id, project_id, varsymbol, invoice_type, issue_date, tax_date, due_date, currency_id, total_without_vat, total_vat, total_with_vat, status, supplier_snapshot, client_snapshot) VALUES (?, ?, ?, ?, "invoice", "2098-06-01", "2098-06-01", "2098-06-15", ?, 1000, 0, 1000, "sent", "{}", "{}")')->execute([$this->supplierId, $this->clientId, $projectId, $number, $this->currencyId]);
        $id = (int) $this->pdo->lastInsertId();
        if ($tracked) $this->track('invoice', $id);
        return $id;
    }

    private function journal(?int $invoiceId, bool $tracked = true, int $revenueLines = 1): int
    {
        $revenue = (int) $this->scalar('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code="602"', [$this->supplierId]);
        $receivable = (int) $this->scalar('SELECT id FROM chart_of_accounts WHERE supplier_id=? AND account_code="311"', [$this->supplierId]);
        $lines = [['account_id' => $receivable, 'side' => 'debit', 'amount' => 1000.0 * $revenueLines]];
        for ($line = 0; $line < $revenueLines; $line++) {
            $lines[] = ['account_id' => $revenue, 'side' => 'credit', 'amount' => 1000.0];
        }
        $id = $this->container->get(JournalEntryRepository::class)->insert(['supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => '2098-06-01', 'source_type' => $invoiceId === null ? 'manual' : 'invoice', 'source_id' => $invoiceId], $lines);
        if ($tracked) $this->track('journal_entry', $id);
        return $id;
    }

    private function generate(): array
    {
        return $this->container->get(SampleDimensionGenerator::class)->generate($this->supplierId, $this->track(...));
    }

    private function values(): array
    {
        $out = [];
        foreach ($this->rows('SELECT v.*, t.kind FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id JOIN sample_data_entries s ON s.supplier_id=? AND s.entity_type="dimension_value" AND s.entity_id=v.id', [$this->supplierId]) as $value) $out[$value['kind']] = $value;
        return $out;
    }

    private function assertBlocked(): void
    {
        try {
            $this->container->get(SampleDimensionPurger::class)->purge($this->supplierId);
            self::fail('Externí použití hodnoty musí blokovat odebrání.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('neevidovanými daty', $e->getMessage());
        }
        self::assertCount(3, $this->values());
    }

    private function track(string $type, int $id): void
    {
        $this->pdo->prepare('INSERT INTO sample_data_entries (supplier_id, entity_type, entity_id) VALUES (?, ?, ?)')->execute([$this->supplierId, $type, $id]);
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function selectCount(): int
    {
        return (int) $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
    }

    private function rows(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
