<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Deletion;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Deletion\BankStatementDeletionGuard;
use MyInvoice\Repository\Deletion\CashDocumentDeletionGuard;
use MyInvoice\Repository\Deletion\ForeignKeyDeletionGuard;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\PayrollPaymentEvidenceTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[Group('integration')]
final class UnitPaymentDeletionGuardTest extends TestCase
{
    use IsolatedSupplierTrait;
    use PayrollPaymentEvidenceTrait;

    private Connection $db;
    private int $supplierId;
    private int $otherSupplierId;
    private int $actorId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        $pdo->beginTransaction();
        $baseSupplier = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->actorId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $baseSupplier);
        self::assertGreaterThan(0, $this->actorId);
        $this->supplierId = $this->createIsolatedSupplier($pdo, $baseSupplier);
        $this->otherSupplierId = $this->createIsolatedSupplier($pdo, $baseSupplier);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
    }

    public static function references(): array
    {
        return [
            'bank source' => ['bank', 'unit_payment_sources'],
            'bank pending workflow' => ['bank', 'unit_payment_workflows'],
            'cash source' => ['cash', 'unit_payment_sources'],
            'cash pending workflow' => ['cash', 'unit_payment_workflows'],
        ];
    }

    #[DataProvider('references')]
    public function testOwnedReferenceBlocksOnlyItsParentAndSingleMatchesBatch(string $kind, string $table): void
    {
        [$parent, $source] = $this->seedParent($kind, $this->supplierId);
        [$plain] = $this->seedParent($kind, $this->supplierId);
        [$foreignParent, $foreignSource] = $this->seedParent($kind, $this->otherSupplierId);
        $guard = $this->guard($kind);
        self::assertNull($guard->conflict($this->supplierId, $parent));
        self::assertNull($guard->conflict($this->supplierId, $plain));
        $this->seedReference($table, $kind, $source, $this->supplierId);
        $this->seedReference($table, $kind, $foreignSource, $this->otherSupplierId);

        $expected = ['unit_payment_evidence' => 1];
        $conflict = $guard->conflict($this->supplierId, $parent);
        self::assertNotNull($conflict);
        self::assertSame('has_dependencies', $conflict->code);
        self::assertSame($expected, $conflict->counts);
        self::assertStringContainsString('plateb jednotek', $conflict->message);
        self::assertNull($guard->conflict($this->supplierId, $plain));
        self::assertNull($guard->conflict($this->supplierId, $foreignParent));
        self::assertNull($guard->conflict($this->otherSupplierId, $parent));

        $ids = [$parent, $plain, $foreignParent];
        if ($kind === 'bank') {
            self::assertNotSame($parent, $source);
            if ($source !== $foreignParent) {
                $ids[] = $source;
            }
        }
        $single = new ReflectionMethod(ForeignKeyDeletionGuard::class, 'countBlockers');
        $batch = new ReflectionMethod(ForeignKeyDeletionGuard::class, 'countBlockersForIds');
        $actual = $batch->invoke($guard, $this->supplierId, [...$ids, $parent]);
        self::assertSame([$parent => $expected], $actual);
        foreach ($ids as $id) {
            self::assertSame($actual[$id] ?? [], $single->invoke($guard, $this->supplierId, $id));
        }
        self::assertSame([], $batch->invoke($guard, $this->supplierId, []));
        self::assertSame([$foreignParent => $expected], $batch->invoke($guard, $this->otherSupplierId, $ids));
    }

    public function testBankReferenceSupplierCannotOverrideStatementOwnership(): void
    {
        [$foreignParent, $foreignSource] = $this->seedParent('bank', $this->otherSupplierId);
        $this->seedReference('unit_payment_workflows', 'bank', $foreignSource, $this->supplierId);
        $guard = $this->guard('bank');
        $single = new ReflectionMethod(ForeignKeyDeletionGuard::class, 'countBlockers');
        $batch = new ReflectionMethod(ForeignKeyDeletionGuard::class, 'countBlockersForIds');
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplier) {
            self::assertSame([], $single->invoke($guard, $supplier, $foreignParent));
            self::assertSame([], $batch->invoke($guard, $supplier, [$foreignParent]));
        }
    }

    private function guard(string $kind): BankStatementDeletionGuard|CashDocumentDeletionGuard
    {
        return Bootstrap::buildContainer()->get($kind === 'bank' ? BankStatementDeletionGuard::class : CashDocumentDeletionGuard::class);
    }

    private function seedParent(string $kind, int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $seed = 'unit-deletion-' . $supplierId . '-' . ++$this->sequence;
        if ($kind === 'bank') {
            $parent = $this->seedBankStatement($pdo, $supplierId, $seed);
            $source = $this->seedBankTransaction($pdo, $parent, $seed);
            if ($source === $parent) {
                $source = $this->seedBankTransaction($pdo, $parent, $seed . '-distinct');
            }
            return [$parent, $source];
        }
        $q = $pdo->prepare("SELECT id FROM cash_registers WHERE supplier_id=? AND account_code='211999' LIMIT 1");
        $q->execute([$supplierId]);
        $register = (int) $q->fetchColumn();
        if ($register === 0) $register = $this->seedCashRegister($pdo, $supplierId, $seed);
        $id = $this->seedCashDocument($pdo, $supplierId, $register, substr(hash('sha256', $seed), 0, 12), 'draft');
        return [$id, $id];
    }

    private function seedReference(string $table, string $kind, int $source, int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $bank = $kind === 'bank' ? $source : null;
        $cash = $kind === 'cash' ? $source : null;
        $json = '{"fixture":"synthetic-unit-deletion"}';
        $hash = hash('sha256', $json);
        if ($table === 'unit_payment_workflows') {
            $pdo->prepare(
                "INSERT INTO unit_payment_workflows
                    (supplier_id, source_kind, bank_transaction_id, cash_document_id, status,
                     request_json, plan_json, request_hash, preview_hash, idempotency_key, created_by)
                 VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)"
            )->execute([$supplierId, $kind, $bank, $cash, $json, $json, $hash, $hash, 'synthetic-' . ++$this->sequence, $this->actorId]);
            return;
        }
        $period = (new AccountingPeriodRepository($this->db))->create($supplierId, 2099, '2099-01-01', '2099-12-31');
        $pdo->prepare(
            "INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, description)
             VALUES (?, ?, '2099-01-15', 'manual', 'Synthetic payment source evidence')"
        )->execute([$supplierId, $period]);
        $entry = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO unit_payment_sources
                (supplier_id, bank_transaction_id, cash_document_id, journal_entry_id, direction,
                 amount_cents, receipt_json, receipt_hash)
             VALUES (?, ?, ?, ?, 'out', 100000, ?, ?)"
        )->execute([$supplierId, $bank, $cash, $entry, $json, $hash]);
    }
}
