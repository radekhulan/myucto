<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Action\Accounting\JournalAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\DimensionDefaultRepository;
use MyInvoice\Repository\DimensionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Dimension\DimensionDefaults;
use MyInvoice\Service\Accounting\Dimension\DimensionStamper;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class DimensionAssignmentProvenanceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private DimensionAssignmentRepository $assignments;
    private JournalEntryRepository $journal;
    private JournalAction $journalAction;
    private PostingService $posting;
    private DimensionService $dimensions;
    private int $supplierId;
    private int $typeId;
    private int $valueA;
    private int $valueB;
    private int $periodId;
    private int $accountId;
    private bool $committedFixture = false;

    protected function setUp(): void
    {
        $config = Config::load(dirname(__DIR__, 4));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+_test$/D', (string) $config->get('db.name'));
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->assignments = new DimensionAssignmentRepository($this->db);
        $this->journal = $container->get(JournalEntryRepository::class);
        $this->journalAction = $container->get(JournalAction::class);
        $this->posting = $container->get(PostingService::class);
        $this->dimensions = $container->get(DimensionService::class);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $source, 'Synthetic CI seed is required.');
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $repo = new DimensionRepository($this->db);
        $this->typeId = $repo->createType($this->supplierId, false, ['code' => 'provenance', 'name' => 'Provenance', 'kind' => 'custom']);
        $type = ['id' => $this->typeId, 'supplier_id' => $this->supplierId, 'supplier_group_id' => null];
        $this->valueA = $repo->createValue($type, ['code' => 'A', 'name' => 'Synthetic A']);
        $this->valueB = $repo->createValue($type, ['code' => 'B', 'name' => 'Synthetic B']);
        $periods = new AccountingPeriodRepository($this->db);
        $periods->create($this->supplierId, 2098, '2098-01-01', '2098-12-31');
        $this->periodId = (int) $periods->findForDate($this->supplierId, '2098-06-01')['id'];
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $stmt = $pdo->prepare("SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = '518'");
        $stmt->execute([$this->supplierId]);
        $this->accountId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $this->accountId);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        if ($this->committedFixture) {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM activity_log WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM journal_entries WHERE supplier_id = ?')->execute([$this->supplierId]);
            $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$this->supplierId]);
        }
    }

    #[DataProvider('retiredAssignments')]
    public function testNewJournalRejectsRetiredSystemValueBeforeWritingHeader(bool $split): void
    {
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $line = $this->plainLines()[0];
        if ($split) {
            $line['dimension_splits'] = [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]];
            $line['dimension_split_origins'] = [$this->typeId => [$this->valueA => ['origin' => 'module', 'ref' => 'legacy']]];
        } else {
            $line['dimensions'] = [$this->typeId => $this->valueA];
            $line['dimension_origins'] = [$this->typeId => ['origin' => 'unknown', 'ref' => 'legacy']];
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $before = (int) $stmt->fetchColumn();
        try {
            $this->journal->insert($this->header(true), [$line]);
            self::fail('Retired value must not be assigned to a new journal row.');
        } catch (PostingException $e) {
            self::assertSame('dimension_closed', $e->errorCode);
        }
        $stmt->execute([$this->supplierId]);
        self::assertSame($before, (int) $stmt->fetchColumn());
    }

    public static function retiredAssignments(): array
    {
        return [[false], [true]];
    }

    public function testSystemValueBatchValidationIsConstantForRepeatedAssignments(): void
    {
        $policy = new SystemDimensionWritePolicy($this->db);
        $count = fn (): int => (int) $this->db->pdo()->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_NUM)[1];
        $line = $this->plainLines()[0] + ['dimensions' => [$this->typeId => $this->valueA]];
        $before = $count();
        $policy->assertJournal($this->header(true), [$line]);
        self::assertSame(8, $count() - $before, 'Explicit IDs and conversion require current context and target-period reads.');
        $this->enable504ActivityKind();
        $before = $count();
        $policy->assertJournal($this->header(true), [$line]);
        $single = $count() - $before;
        $before = $count();
        $policy->assertJournal($this->header(true), array_fill(0, 1000, $line));
        // Opakované volání smí díky paměti aktuálních čtení v transakci číst i méně, nikdy víc.
        self::assertLessThanOrEqual($single, $count() - $before, 'Repeated pairs must be deduplicated in one current value query.');
        self::assertLessThanOrEqual(12, $single, 'Conversion adds three constant reads to the nine-read system-value budget.');
    }

    public function testCurrentRetainedPolicyDoesNotAuthorizeAnotherRowValueOrChangedAmount(): void
    {
        $entryId = $this->journal->insert($this->header(true), array_map(fn (array $line): array => $line + [
            'dimensions' => [$this->typeId => $this->valueA]], $this->plainLines()));
        $this->enable504ActivityKind();
        $repo = new DimensionRepository($this->db);
        $repo->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $repo->updateValue($this->supplierId, $this->valueB, ['is_active' => false]);
        $current = $this->currentAssignmentLines($entryId);
        $policy = new SystemDimensionWritePolicy($this->db);
        $policy->assertJournal($this->header(true), $current, $current);
        foreach (['id', 'amount', 'value', 'duplicate'] as $change) {
            $requested = $current;
            if ($change === 'id') $requested[0]['id'] += 100000;
            if ($change === 'amount') $requested[0]['amount'] += 1;
            if ($change === 'value') $requested[0]['dimensions'][$this->typeId] = $this->valueB;
            if ($change === 'duplicate') $requested = [$current[0], $current[0]];
            try {
                $policy->assertJournal($this->header(true), $requested, $current);
                self::fail('Current retained assignment cannot authorize change: ' . $change);
            } catch (PostingException $e) {
                self::assertSame('dimension_closed', $e->errorCode);
            }
        }
    }

    #[DataProvider('retiredAssignments')]
    public function testRetiredHistoricalReverseAndReplaceNoOpPreserveCurrentAssignment(bool $split): void
    {
        $origin = ['origin' => 'unknown', 'ref' => 'legacy-retired'];
        $lines = $this->plainLines();
        foreach ($lines as $i => $_) {
            if ($split) {
                $lines[$i]['dimension_splits'] = [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]];
                $lines[$i]['dimension_split_origins'] = [$this->typeId => [$this->valueA => $origin, $this->valueB => $origin]];
            } else {
                $lines[$i]['dimensions'] = [$this->typeId => $this->valueA];
                $lines[$i]['dimension_origins'] = [$this->typeId => $origin];
            }
        }
        $entryId = $this->journal->insert($this->header(true), $lines);
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $current = $this->currentAssignmentLines($entryId);
        $this->journal->replace($entryId, $this->header(true), $current);
        $after = $this->currentAssignmentLines($entryId);
        foreach ($after as $i => $line) {
            self::assertSame($current[$i]['dimensions'], $line['dimensions']);
            self::assertSame($current[$i]['dimension_splits'], $line['dimension_splits']);
            self::assertSame($current[$i]['dimension_origins'], $line['dimension_origins']);
            self::assertSame($current[$i]['dimension_split_origins'], $line['dimension_split_origins']);
        }
        $reversalId = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => '2098-06-02']);
        $mirror = $this->currentAssignmentLines($reversalId);
        foreach ($mirror as $i => $line) {
            self::assertSame($after[$i]['dimensions'], $line['dimensions']);
            self::assertSame($after[$i]['dimension_splits'], $line['dimension_splits']);
            self::assertSame($after[$i]['dimension_origins'], $line['dimension_origins']);
            self::assertSame($after[$i]['dimension_split_origins'], $line['dimension_split_origins']);
        }
    }

    public function testPartialRetainsInactiveCurrentRowButDoesNotAuthorizeNewInactiveUse(): void
    {
        $lines = $this->plainLines();
        $lines[0]['dimensions'] = [$this->typeId => $this->valueA];
        $lines[1]['dimensions'] = [$this->typeId => $this->valueB];
        $entryId = $this->journal->insert($this->header(true), $lines);
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $current = $this->journal->find($entryId, $this->supplierId);
        $this->journal->replaceSomeLines($entryId, $this->supplierId, $current['row_version'], [(int) $current['lines'][1]['id']], [$lines[1]]);
        $current = $this->journal->find($entryId, $this->supplierId);
        $new = $lines[1];
        $new['dimensions'][$this->typeId] = $this->valueA;
        try {
            $this->journal->replaceSomeLines($entryId, $this->supplierId, $current['row_version'], [(int) $current['lines'][1]['id']], [$new]);
            self::fail('A retained inactive assignment must not authorize a newly added line.');
        } catch (PostingException $e) {
            self::assertSame('dimension_closed', $e->errorCode);
        }
        self::assertSame($current, $this->journal->find($entryId, $this->supplierId));
    }

    #[DataProvider('retiredAssignments')]
    public function testVerifiedReversalPreservesMissingHistoricalDimensionsAfterNewRequirement(bool $builtin): void
    {
        $this->enable504ActivityKind();
        $source = $this->journal->insert($this->header(true), $this->plainLines());
        if ($builtin) {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($this->supplierId, '2098-06-01');
        } else {
            $this->db->pdo()->prepare("INSERT INTO dimension_account_rules (supplier_id, dimension_type_id, account_mask, enforcement) VALUES (?, ?, '5', 'error')")
                ->execute([$this->supplierId, $this->typeId]);
        }
        try {
            $this->journal->insert($this->header(true) + ['verified_reversal' => true], $this->plainLines());
            self::fail('A generic insert cannot bypass newly required dimensions.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }
        $before = $this->currentAssignmentLines($source);
        $reversal = $this->posting->reverse($this->supplierId, $source, ['entry_date' => '2098-06-02']);
        $after = $this->currentAssignmentLines($reversal);
        self::assertCount(count($before), $after);
        foreach ($after as $index => $line) {
            self::assertTrue(SystemDimensionWritePolicy::sameLedgerLine($line, array_replace($before[$index], JournalLineAmount::reversal($before[$index]))));
            self::assertSame([], $line['dimensions']);
            self::assertSame([], $line['dimension_splits']);
        }
        self::assertSame($reversal, $this->journal->find($source, $this->supplierId)['reversed_by']);
    }

    private function currentAssignmentLines(int $entryId): array
    {
        $lines = $this->journal->linesForEntry($entryId, $this->supplierId, true);
        $dimensions = $this->assignments->entryLineDimensions($this->supplierId, $entryId, true);
        $splits = $this->assignments->entryLineSplits($this->supplierId, $entryId, true);
        $origins = $this->assignments->entryLineOrigins($this->supplierId, $entryId, true);
        $splitOrigins = $this->assignments->entryLineSplitOrigins($this->supplierId, $entryId, true);
        foreach ($lines as $i => $line) {
            $lineId = (int) $line['id'];
            $lines[$i]['dimensions'] = $dimensions[$lineId] ?? [];
            $lines[$i]['dimension_splits'] = $splits[$lineId] ?? [];
            $lines[$i]['dimension_origins'] = $origins[$lineId] ?? [];
            $lines[$i]['dimension_split_origins'] = $splitOrigins[$lineId] ?? [];
        }
        return $lines;
    }

    public function testBatchValueIdentityRejectsForeignMissingAndWrongTypeWithoutExposure(): void
    {
        $this->enable504ActivityKind();
        $pdo = $this->db->pdo();
        $repo = new DimensionRepository($this->db);
        $otherSupplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        $foreignType = $repo->createType($otherSupplier, false, ['code' => 'foreign', 'name' => 'Foreign synthetic type', 'kind' => 'custom']);
        $foreign = $repo->createValue(['id' => $foreignType, 'supplier_id' => $otherSupplier, 'supplier_group_id' => null], ['code' => 'FOREIGN', 'name' => 'Private synthetic name']);
        $localType = $repo->createType($this->supplierId, false, ['code' => 'other', 'name' => 'Other synthetic type', 'kind' => 'custom']);
        $wrongType = $repo->createValue(['id' => $localType, 'supplier_id' => $this->supplierId, 'supplier_group_id' => null], ['code' => 'OTHER', 'name' => 'Other synthetic value']);
        $messages = [];
        foreach ([$foreign, $wrongType, 1000000000] as $valueId) {
            try {
                $this->journal->insert($this->header(true), [$this->plainLines()[0] + ['dimensions' => [$this->typeId => $valueId]]]);
                self::fail('Foreign, wrong-type and missing values must all be refused.');
            } catch (PostingException $e) {
                self::assertSame('invalid_dimension_value', $e->errorCode);
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages));
    }

    public function testPostingRepostUsesCurrentRetainedProofAfterSourceDiscovery(): void
    {
        $purchase = $this->purchase();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', dimensions_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $origin = ['origin' => 'unknown', 'ref' => 'legacy-repost'];
        $lines = array_map(fn (array $line): array => $line + ['dimensions' => [$this->typeId => $this->valueA],
            'dimension_origins' => [$this->typeId => $origin]], $this->plainLines());
        $entry = $this->journal->insert($this->header(true) + ['source_type' => 'purchase_invoice', 'source_id' => $purchase], $lines);
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $input = array_map(fn (array $line): array => ['account_code' => '518', 'side' => $line['side'], 'amount' => 100,
            'dimensions' => [$this->typeId => $this->valueA]], $lines);
        self::assertSame($entry, $this->posting->postDocument($this->supplierId, 'purchase_invoice', $purchase, $input, ['entry_date' => '2098-06-01']));
        foreach ($this->assignments->entryLineOrigins($this->supplierId, $entry) as $origins) {
            self::assertSame([$this->typeId => $origin], $origins);
        }
    }

    #[DataProvider('fakeReversals')]
    public function testReversalReceiptRejectsForgedSourceSupplierAndMirror(string $change): void
    {
        $origin = ['origin' => 'unknown', 'ref' => 'legacy'];
        $lines = array_map(fn (array $line): array => $line + ['dimensions' => [$this->typeId => $this->valueA],
            'dimension_origins' => [$this->typeId => $origin]], $this->plainLines());
        $sourceHeader = $this->header(true);
        $source = $this->journal->insert($sourceHeader, $lines);
        $otherLines = array_map(static fn (array $line): array => array_replace($line, ['amount' => 200]), $lines);
        $other = $this->journal->insert($this->header($change !== 'draft'), $otherLines);
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $mirror = array_map([JournalLineAmount::class, 'reversal'], $this->currentAssignmentLines($source));
        $header = $sourceHeader;
        $chosen = $source;
        if ($change === 'source' || $change === 'draft') $chosen = $other;
        if ($change === 'supplier') $header['supplier_id'] = $this->createIsolatedSupplier($this->db->pdo(), (int) $this->db->pdo()->query('SELECT MIN(id) FROM supplier')->fetchColumn());
        if ($change === 'amount') $mirror[0]['amount'] += 1;
        if ($change === 'side') $mirror[0]['side'] = $lines[0]['side'];
        if ($change === 'red') $mirror[0]['is_red_storno'] = true;
        if ($change === 'currency') $mirror[0]['currency_code'] = 'USD';
        if ($change === 'dimensions') $mirror[0]['dimensions'][$this->typeId] = $this->valueB;
        if ($change === 'splits') {
            unset($mirror[0]['dimensions']);
            $mirror[0]['dimension_splits'] = [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]];
        }
        if ($change === 'duplicate') $mirror[] = $mirror[0];
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        $before = (int) $stmt->fetchColumn();
        try {
            $this->journal->insertReversal($header, $mirror, $chosen);
            self::fail('Forged reversal receipt must be rejected: ' . $change);
        } catch (PostingException $e) {
            self::assertSame('invalid_reversal_source', $e->errorCode);
        }
        $stmt->execute([$this->supplierId]);
        self::assertSame($before, (int) $stmt->fetchColumn());
        self::assertNull($this->journal->find($source, $this->supplierId)['reversed_by']);
    }

    public static function fakeReversals(): array
    {
        return array_map(static fn (string $case): array => [$case], ['source', 'supplier', 'draft', 'amount', 'side', 'red', 'currency', 'dimensions', 'splits', 'duplicate']);
    }

    public function testReversalReceiptCopiesCurrentOriginsLinksSourceAndRejectsReplay(): void
    {
        $origin = ['origin' => 'unknown', 'ref' => 'legacy'];
        $source = $this->journal->insert($this->header(true), array_map(fn (array $line): array => $line + [
            'dimensions' => [$this->typeId => $this->valueA], 'dimension_origins' => [$this->typeId => $origin]], $this->plainLines()));
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $mirror = array_map([JournalLineAmount::class, 'reversal'], $this->currentAssignmentLines($source));
        foreach ($mirror as $i => $_) $mirror[$i]['dimension_origins'][$this->typeId] = ['origin' => 'explicit', 'ref' => 'forged'];
        $reversal = $this->journal->insertReversal($this->header(true), $mirror, $source);
        self::assertSame($reversal, $this->journal->find($source, $this->supplierId)['reversed_by']);
        foreach ($this->assignments->entryLineOrigins($this->supplierId, $reversal) as $origins) self::assertSame([$this->typeId => $origin], $origins);
        try {
            $this->journal->insertReversal($this->header(true), $mirror, $source);
            self::fail('A receipt cannot reverse the same source twice.');
        } catch (PostingException $e) {
            self::assertSame('invalid_reversal_source', $e->errorCode);
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    public function testReversalReceiptRollsBackMirrorWhenLinkWriteFailsInsideCallerTransaction(): void
    {
        $source = $this->journal->insert($this->header(true), $this->plainLines());
        $mirror = array_map([JournalLineAmount::class, 'reversal'], $this->currentAssignmentLines($source));
        $pdo = $this->db->pdo();
        $this->committedFixture = true;
        $pdo->commit();
        $trigger = 'test_dim_reversal_' . bin2hex(random_bytes(6));
        $created = false;
        try {
            $pdo->exec("CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF NEW.reversed_by IS NOT NULL AND OLD.reversed_by IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic reversal link failure'; END IF; END");
            $created = true;
            $pdo->beginTransaction();
            try {
                $this->journal->insertReversal($this->header(true), $mirror, $source);
                self::fail('Synthetic link failure must reach the caller.');
            } catch (\PDOException $e) {
                self::assertStringContainsString('synthetic reversal link failure', $e->getMessage());
            }
            self::assertTrue($pdo->inTransaction());
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ?');
            $stmt->execute([$this->supplierId]);
            self::assertSame(1, (int) $stmt->fetchColumn());
            self::assertNull($this->journal->find($source, $this->supplierId)['reversed_by']);
        } finally {
            if ($created) $pdo->exec("DROP TRIGGER `{$trigger}`");
        }
    }

    public function testDocumentAndRestampRetainInactiveAssignmentWhenAnotherTypeChanges(): void
    {
        [$purchase, $entry, $otherType, $otherValue] = $this->retiredPurchaseEntry();
        $result = $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchase,
            [$this->typeId => $this->valueA, $otherType => $otherValue], []);
        self::assertSame(2, $result['restamp']['lines']);
        foreach ($this->assignments->entryLineDimensions($this->supplierId, $entry) as $dimensions) {
            self::assertSame([$this->typeId => $this->valueA, $otherType => $otherValue], $dimensions);
        }
    }

    public function testJournalEditorRetainsInactiveCurrentAssignmentWhenAnotherTypeChanges(): void
    {
        [, $entry, $otherType, $otherValue] = $this->retiredPurchaseEntry();
        $line = $this->journal->linesForEntry($entry, $this->supplierId)[0];
        self::assertSame(1, $this->dimensions->saveEntryLines($this->supplierId, $entry,
            [(int) $line['id'] => [$this->typeId => $this->valueA, $otherType => $otherValue]]));
        self::assertSame([$this->typeId => $this->valueA, $otherType => $otherValue],
            $this->assignments->lineDimensions($this->supplierId, [(int) $line['id']])[(int) $line['id']]);
    }

    private function retiredPurchaseEntry(): array
    {
        $purchase = $this->purchase();
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $repo = new DimensionRepository($this->db);
        $otherType = $repo->createType($this->supplierId, false, ['code' => 'retained_other', 'name' => 'Other type', 'kind' => 'custom']);
        $otherValue = $repo->createValue(['id' => $otherType, 'supplier_id' => $this->supplierId, 'supplier_group_id' => null], ['code' => 'OTHER', 'name' => 'Other value']);
        $origin = ['origin' => 'unknown', 'ref' => 'legacy'];
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchase, [$this->typeId => $this->valueA], [], [$this->typeId => $origin]);
        $lines = array_map(fn (array $line): array => $line + ['dimensions' => [$this->typeId => $this->valueA],
            'dimension_origins' => [$this->typeId => $origin]], $this->plainLines());
        $entry = $this->journal->insert($this->header(true) + ['source_type' => 'purchase_invoice', 'source_id' => $purchase], $lines);
        $this->enable504ActivityKind();
        $repo->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        return [$purchase, $entry, $otherType, $otherValue];
    }

    #[DataProvider('changedEntryContexts')]
    public function testRetainedInactiveValueCannotAuthorizeNewEntryContext(string $change): void
    {
        $entry = $this->journal->insert($this->header($change !== 'posting'), array_map(fn (array $line): array => $line + [
            'dimensions' => [$this->typeId => $this->valueA]], $this->plainLines()));
        $current = $this->currentAssignmentLines($entry);
        $before = $this->journal->find($entry, $this->supplierId);
        $this->enable504ActivityKind();
        (new DimensionRepository($this->db))->updateValue($this->supplierId, $this->valueA, ['is_active' => false]);
        $header = $this->header(true);
        if ($change === 'date') {
            $header['period_id'] = (new AccountingPeriodRepository($this->db))->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
            $header['entry_date'] = '2099-06-01';
        }
        if ($change === 'source') $header['source_type'] = 'depreciation';
        try {
            $this->journal->replace($entry, $header, $current);
            self::fail('Retained values cannot authorize a changed entry context: ' . $change);
        } catch (PostingException $e) {
            self::assertSame('dimension_closed', $e->errorCode);
        }
        self::assertSame($before, $this->journal->find($entry, $this->supplierId));
    }

    public static function changedEntryContexts(): array
    {
        return [['date'], ['source'], ['posting']];
    }

    private function enable504ActivityKind(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE dimension_types SET system_key = 'activity_kind' WHERE id = ?")->execute([$this->typeId]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplierId]);
    }

    public function testReversePreservesScalarAndSplitOriginsIncludingUnknownForRedStorno(): void
    {
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $rule = ['origin' => 'rule_default', 'ref' => 'rule:31'];
        $entryId = $this->journal->insert($this->header(true), [
            ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100, 'is_red_storno' => true,
                'dimensions' => [$this->typeId => $this->valueA], 'dimension_origins' => [$this->typeId => $unknown]],
            ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100, 'is_red_storno' => true,
                'dimension_splits' => [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]],
                'dimension_split_origins' => [$this->typeId => [$this->valueA => $rule, $this->valueB => $unknown]]],
        ]);
        $original = $this->journal->linesForEntry($entryId, $this->supplierId);
        $reversal = $this->posting->reverse($this->supplierId, $entryId, ['entry_date' => '2098-06-02']);
        $mirror = $this->journal->linesForEntry($reversal, $this->supplierId);
        self::assertSame(array_column($original, 'side'), array_column($mirror, 'side'));
        self::assertSame([false, false], array_column($mirror, 'is_red_storno'));
        self::assertSame([$this->typeId => $unknown], $this->assignments->lineOrigins($this->supplierId, [(int) $mirror[0]['id']])[(int) $mirror[0]['id']]);
        self::assertSame([$this->typeId => [$this->valueA => $rule, $this->valueB => $unknown]], $this->assignments->lineSplitOrigins($this->supplierId, [(int) $mirror[1]['id']])[(int) $mirror[1]['id']]);
    }

    public function testPartialReplacementChecksRetainedLinesBeforeAnyMutation(): void
    {
        $entryId = $this->journal->insert($this->header(true), $this->plainLines());
        $before = $this->journal->find($entryId, $this->supplierId);
        $this->enable504Activity();
        try {
            $this->journal->replaceSomeLines($entryId, $this->supplierId, $before['row_version'], [(int) $before['lines'][1]['id']], [
                ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100, 'dimensions' => [$this->typeId => $this->valueA]],
            ]);
            self::fail('A retained expense without required activity must be refused.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }
        self::assertSame($before, $this->journal->find($entryId, $this->supplierId));
    }

    public function testPartialReplacementAcceptsCompleteNewValidState(): void
    {
        $entryId = $this->journal->insert($this->header(true), $this->plainLines());
        $before = $this->journal->find($entryId, $this->supplierId);
        $this->enable504Activity();
        $lines = array_map(fn (array $line): array => $line + ['dimensions' => [$this->typeId => $this->valueA],
            'dimension_origins' => [$this->typeId => ['origin' => 'explicit', 'ref' => null]]], $this->plainLines());
        $this->journal->replaceSomeLines($entryId, $this->supplierId, $before['row_version'], array_column($before['lines'], 'id'), $lines);
        $after = $this->journal->find($entryId, $this->supplierId);
        self::assertSame($before['row_version'] + 1, $after['row_version']);
        self::assertCount(2, $this->assignments->entryLineOrigins($this->supplierId, $entryId));
    }

    public function testMarkPostedRejectsMissing504ActivityAndKeepsDraftUntouched(): void
    {
        $entryId = $this->journal->insert($this->header(false), $this->plainLines());
        $before = $this->journal->find($entryId, $this->supplierId);
        $this->enable504Activity();
        try {
            $this->journal->markPosted($entryId, $this->supplierId, null);
            self::fail('A draft without required activity must not become posted.');
        } catch (PostingException $e) {
            self::assertSame('dimension_required', $e->errorCode);
        }
        self::assertSame($before, $this->journal->find($entryId, $this->supplierId));
    }

    public function testAlreadyPostedHistoricalNoOpDoesNotRevalidateNew504Rule(): void
    {
        $entryId = $this->journal->insert($this->header(true), $this->plainLines());
        $this->enable504Activity();
        self::assertTrue($this->journal->markPosted($entryId, $this->supplierId, null));
        self::assertCount(2, $this->journal->linesForEntry($entryId, $this->supplierId));
    }

    public function testMarkPostedKeeps500BehaviorAndAcceptsValid504Draft(): void
    {
        $entryId = $this->journal->insert($this->header(false), $this->plainLines());
        self::assertTrue($this->journal->markPosted($entryId, $this->supplierId, null));
        self::assertNotNull($this->journal->find($entryId, $this->supplierId)['posted_at']);
        $lines = array_map(fn (array $line): array => $line + ['dimensions' => [$this->typeId => $this->valueA]], $this->plainLines());
        $valid = $this->journal->insert($this->header(false), $lines);
        $this->enable504Activity();
        self::assertTrue($this->journal->markPosted($valid, $this->supplierId, null));
        self::assertNotNull($this->journal->find($valid, $this->supplierId)['posted_at']);
    }

    private function enable504Activity(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE dimension_types SET system_key = 'activity' WHERE id = ?")->execute([$this->typeId]);
        $pdo->prepare("UPDATE dimension_values SET semantic_key = 'main' WHERE id = ?")->execute([$this->valueA]);
        $pdo->prepare("UPDATE dimension_values SET semantic_key = 'economic' WHERE id = ?")->execute([$this->valueB]);
        $pdo->prepare("INSERT INTO supplier_accounting_frameworks (supplier_id, effective_from, accounting_framework) VALUES (?, '1900-01-01', 'cz_504')")->execute([$this->supplierId]);
    }

    private function header(bool $posted): array
    {
        return ['supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => '2098-06-01',
            'posted_at' => $posted ? '2098-06-01 12:00:00' : null];
    }

    private function plainLines(): array
    {
        return [
            ['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100],
            ['account_id' => $this->accountId, 'side' => 'credit', 'amount' => 100],
        ];
    }

    public function testJournalCreatePersistsExplicitRequestOriginsForScalarAndSplit(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry', dimensions_enabled = 1 WHERE id = ?")->execute([$this->supplierId]);
        $userId = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/accounting/journal')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $userId, 'role' => 'accountant'])
            ->withAttribute('auth.effective_role', new EffectiveRole(99, 'Synthetic accountant', 'staff', true, ['accounting' => AccessLevel::WRITE->value]))
            ->withParsedBody(['entry_date' => '2098-06-01', 'lines' => [
                ['account_code' => '518', 'side' => 'debit', 'amount' => 100, 'dimensions' => [$this->typeId => $this->valueA],
                    'dimension_origins' => [$this->typeId => ['origin' => 'rule_default', 'ref' => 'rule:999']]],
                ['account_code' => '321', 'side' => 'credit', 'amount' => 100,
                    'dimension_splits' => [$this->typeId => [['value_id' => $this->valueA, 'share' => 0.4], ['value_id' => $this->valueB, 'share' => 0.6]]]],
            ]]);
        $response = $this->journalAction->create($request, new Response());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(201, $response->getStatusCode(), json_encode($body));
        $entryId = (int) ($body['id'] ?? $body['data']['id'] ?? 0);
        self::assertGreaterThan(0, $entryId);
        $stmt = $pdo->prepare('SELECT d.assignment_origin, d.assignment_origin_ref FROM journal_entry_line_dimensions d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ? AND l.supplier_id = ?');
        $stmt->execute([$entryId, $this->supplierId]);
        self::assertSame([['assignment_origin' => 'explicit', 'assignment_origin_ref' => null]], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $pdo->prepare('SELECT d.assignment_origin, d.assignment_origin_ref FROM journal_entry_line_dimension_splits d JOIN journal_entry_lines l ON l.id = d.line_id WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY d.dimension_value_id');
        $stmt->execute([$entryId, $this->supplierId]);
        self::assertSame([['assignment_origin' => 'explicit', 'assignment_origin_ref' => null], ['assignment_origin' => 'explicit', 'assignment_origin_ref' => null]], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testNoOpKeepsUnknownAndCopyCarriesStoredOriginsExactly(): void
    {
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $explicit = ['origin' => 'explicit', 'ref' => null];
        $rule = ['origin' => 'rule_default', 'ref' => 'rule:19'];
        $dims = [$this->typeId => $this->valueA];
        $splits = [0 => [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]]];
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'invoice', 101, $dims, [], [$this->typeId => $unknown]);
        $this->assignments->replaceDocumentSplits($this->supplierId, 'invoice', 102, $splits, [0 => [$this->typeId => [$this->valueA => $rule, $this->valueB => $unknown]]]);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'invoice', 101, $dims, [], [$this->typeId => $explicit]);
        $this->assignments->replaceDocumentSplits($this->supplierId, 'invoice', 102, [0 => [$this->typeId => [$this->valueB => 0.6, $this->valueA => 0.4]]], [0 => [$this->typeId => [$this->valueA => $explicit, $this->valueB => $explicit]]]);
        $stored = $this->db->pdo()->prepare('SELECT assignment_origin, assignment_origin_ref FROM document_dimensions WHERE supplier_id = ? AND doc_id = 101');
        $stored->execute([$this->supplierId]);
        self::assertSame(['assignment_origin' => 'unknown', 'assignment_origin_ref' => 'legacy'], $stored->fetch(PDO::FETCH_ASSOC));
        self::assertSame([$this->typeId => $unknown], $this->assignments->documentOrigins($this->supplierId, 'invoice', 101)['header']);
        $this->assignments->copyDocument($this->supplierId, 'invoice', 101, 201);
        $this->assignments->copyDocument($this->supplierId, 'invoice', 102, 202);
        self::assertSame($this->assignments->documentOrigins($this->supplierId, 'invoice', 101), $this->assignments->documentOrigins($this->supplierId, 'invoice', 201));
        self::assertSame($this->assignments->documentSplitOrigins($this->supplierId, 'invoice', 102), $this->assignments->documentSplitOrigins($this->supplierId, 'invoice', 202));
        self::assertSame([$this->valueA => $rule, $this->valueB => $unknown], $this->assignments->documentSplitOrigins($this->supplierId, 'invoice', 202)[0][$this->typeId]);
        self::assertSame(['header' => [], 'items' => []], $this->assignments->documentOrigins($this->supplierId + 100000, 'invoice', 201));
    }

    public function testJournalSplitOriginsSurviveNoOpAndChangedShareUsesActualInput(): void
    {
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $rule = ['origin' => 'rule_default', 'ref' => 'rule:7'];
        $shares = [$this->typeId => [$this->valueA => 0.4, $this->valueB => 0.6]];
        $entryId = $this->journal->insert(
            ['supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => '2098-06-01'],
            [['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100, 'dimension_splits' => $shares,
                'dimension_split_origins' => [$this->typeId => [$this->valueA => $unknown, $this->valueB => $rule]]]],
        );
        $lineId = (int) $this->journal->linesForEntry($entryId, $this->supplierId)[0]['id'];
        self::assertFalse($this->assignments->replaceLineSplits($this->supplierId, $lineId, $shares, [$this->typeId => [$this->valueA => $rule]]));
        self::assertSame([$this->typeId => [$this->valueA => $unknown, $this->valueB => $rule]], $this->assignments->lineSplitOrigins($this->supplierId, [$lineId])[$lineId]);
        $explicit = ['origin' => 'explicit', 'ref' => null];
        self::assertTrue($this->assignments->replaceLineSplits($this->supplierId, $lineId, [$this->typeId => [$this->valueA => 0.3, $this->valueB => 0.7]], [$this->typeId => [$this->valueA => $explicit, $this->valueB => $explicit]]));
        self::assertSame([$this->typeId => [$this->valueA => $explicit, $this->valueB => $explicit]], $this->assignments->lineSplitOrigins($this->supplierId, [$lineId])[$lineId]);
    }

    public function testRestampCarriesChangedDocumentOriginAndClosedNoOpPreservesUnknown(): void
    {
        $purchaseId = $this->purchase();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $unknown = ['origin' => 'unknown', 'ref' => 'legacy'];
        $explicit = ['origin' => 'explicit', 'ref' => 'document:purchase_invoice:' . $purchaseId . ':0'];
        $entryId = $this->journal->insert(
            ['supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => '2098-06-01', 'source_type' => 'purchase_invoice', 'source_id' => $purchaseId],
            [['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100, 'dimensions' => [$this->typeId => $this->valueA], 'dimension_origins' => [$this->typeId => $unknown]]],
        );
        $lineId = (int) $this->journal->linesForEntry($entryId, $this->supplierId)[0]['id'];
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchaseId, [$this->typeId => $this->valueB], [], [$this->typeId => $explicit]);
        $stamper = new DimensionStamper($this->db);
        self::assertSame(1, $stamper->restamp($this->supplierId, 'purchase_invoice', $purchaseId)['lines']);
        self::assertSame([$this->typeId => $explicit], $this->assignments->lineOrigins($this->supplierId, [$lineId])[$lineId]);
        $pdo->prepare("UPDATE journal_entry_line_dimensions SET assignment_origin = 'unknown', assignment_origin_ref = 'legacy' WHERE line_id = ?")->execute([$lineId]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$this->periodId]);
        self::assertSame(0, $stamper->restamp($this->supplierId, 'purchase_invoice', $purchaseId)['lines']);
        self::assertSame([$this->typeId => $unknown], $this->assignments->lineOrigins($this->supplierId, [$lineId])[$lineId]);
    }

    public function testDocumentOwnerRejectsAmbiguousHeaderAndItemBeforeWritingAnything(): void
    {
        $purchaseId = $this->purchase();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET dimensions_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $repo = new DimensionRepository($this->db);
        $otherType = $repo->createType($this->supplierId, false, ['code' => 'activity_kind', 'name' => 'Activity kind', 'kind' => 'custom']);
        $pdo->prepare("UPDATE dimension_types SET system_key = 'activity' WHERE id = ?")->execute([$this->typeId]);
        $pdo->prepare("UPDATE dimension_types SET system_key = 'activity_kind' WHERE id = ?")->execute([$otherType]);
        $type = ['id' => $otherType, 'supplier_id' => $this->supplierId, 'supplier_group_id' => null];
        $a = $repo->createValue($type, ['code' => 'X', 'name' => 'Synthetic X']);
        $b = $repo->createValue($type, ['code' => 'Y', 'name' => 'Synthetic Y']);
        $this->assignments->replaceDocumentDimensions($this->supplierId, 'purchase_invoice', $purchaseId, [$this->typeId => $this->valueA], []);
        try {
            $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $purchaseId, [], [], false, [
                0 => [$this->typeId => [['value_id' => $this->valueA, 'share' => 0.4], ['value_id' => $this->valueB, 'share' => 0.6]]],
                1 => [$otherType => [['value_id' => $a, 'share' => 0.3], ['value_id' => $b, 'share' => 0.7]]],
            ]);
            self::fail('Ambiguous independent allocations must be refused.');
        } catch (DimensionException $e) {
            self::assertSame('system_dimension_allocation_ambiguous', $e->errorCode);
        }
        self::assertSame(['header' => [$this->typeId => $this->valueA], 'items' => []], $this->assignments->documentDimensions($this->supplierId, 'purchase_invoice', $purchaseId));
        self::assertSame([], $this->assignments->documentSplits($this->supplierId, 'purchase_invoice', $purchaseId));
    }

    private function purchase(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
            SELECT ?, 'Synthetic vendor', 'Test', 'Test', '10000', country_id, 'vendor@example.invalid', 'cs', default_currency_id, 0, 1 FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $this->supplierId]);
        $vendorId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO purchase_invoices
            (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, vat_deduction, created_by)
            SELECT ?, ?, 'SYNTHETIC-PROVENANCE', 'invoice', '2098-06-01', '2098-06-01', '2098-06-01', '2098-06-01', default_currency_id, 0, '{}', 100, 0, 100, 'received', '40', 'full', (SELECT MIN(id) FROM users) FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $vendorId, $this->supplierId]);
        return (int) $pdo->lastInsertId();
    }

    public function testActualEntityDefaultReachesStoredJournalAndLineNoOpPreservesIt(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
            SELECT ?, 'Synthetic provenance client', 'Test', 'Test', '10000', country_id, 'test@example.invalid', 'cs', default_currency_id, 1, 0 FROM supplier WHERE id = ?")
            ->execute([$this->supplierId, $this->supplierId]);
        $clientId = (int) $pdo->lastInsertId();
        (new DimensionDefaultRepository($this->db))->replace($this->supplierId, 'client', $clientId, [$this->typeId => $this->valueA]);
        $state = (new DimensionDefaults($this->db))->resolveWithOrigins($this->supplierId, $clientId, null);
        $origin = ['origin' => 'entity_default', 'ref' => 'client:' . $clientId];
        self::assertSame([$this->typeId => $origin], $state['origins']);
        $lines = DimensionStamper::assign([['account_id' => $this->accountId, 'side' => 'debit', 'amount' => 100]], $state['header'], [], [$this->accountId => 'expense'], true, $state['origins'])['lines'];
        $entryId = $this->journal->insert(['supplier_id' => $this->supplierId, 'period_id' => $this->periodId, 'entry_date' => '2098-06-01'], $lines);
        $lineId = (int) $this->journal->linesForEntry($entryId, $this->supplierId)[0]['id'];
        self::assertSame([$this->typeId => $origin], $this->assignments->lineOrigins($this->supplierId, [$lineId])[$lineId]);
        self::assertFalse($this->assignments->replaceLineDimensions($this->supplierId, $lineId, [$this->typeId => $this->valueA], [$this->typeId => ['origin' => 'explicit', 'ref' => null]]));
        self::assertSame([$this->typeId => $origin], $this->assignments->lineOrigins($this->supplierId, [$lineId])[$lineId]);
        self::assertTrue($this->assignments->replaceLineDimensions($this->supplierId, $lineId, [$this->typeId => $this->valueB], [$this->typeId => ['origin' => 'rule_default', 'ref' => 'rule:23']]));
        self::assertSame(['origin' => 'rule_default', 'ref' => 'rule:23'], $this->assignments->lineOrigins($this->supplierId, [$lineId])[$lineId][$this->typeId]);
    }
}
