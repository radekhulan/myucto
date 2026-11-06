<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Anonymization;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Anonymization\FinancialCloneHashKind;
use MyInvoice\Service\Anonymization\FinancialCloneHashMap;
use PHPUnit\Framework\TestCase;

final class FinancialCloneHashMapTest extends TestCase
{
    private Connection $db;

    private FinancialCloneHashMap $map;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertSame(getenv('MYINVOICE_DB_NAME'), $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->map = FinancialCloneHashMap::create($this->db->pdo());
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->map->drop();
    }

    public function testBoundedResolverSharesOneExactMappingAcrossTypedOwners(): void
    {
        $old = hash('sha256', 'old-profile-hash');
        $new = hash('sha256', 'new-profile-hash');
        $this->db->pdo()->beginTransaction();
        $this->map->record(FinancialCloneHashKind::PROFILE_RECORD, 10, $old, $new);
        $this->map->record(FinancialCloneHashKind::SAVING_SOURCE, 20, $old, $new);
        self::assertSame($new, $this->map->resolve($old));
        self::assertNull($this->map->resolve(hash('sha256', 'unknown-hash')));
        $this->db->pdo()->commit();

        self::assertSame(1, $this->map->mappingCount());
        self::assertSame(2, $this->map->ownerCount());
    }

    public function testConflictingReplacementFailsAndRollbackDoesNotPublishMapping(): void
    {
        $old = hash('sha256', 'conflicting-old-hash');
        $this->db->pdo()->beginTransaction();
        $this->map->record(FinancialCloneHashKind::ASSESSMENT_RESULT, 30, $old, hash('sha256', 'target-a'));
        $this->db->pdo()->commit();

        $this->db->pdo()->beginTransaction();
        try {
            $this->map->record(FinancialCloneHashKind::ARCHIVE_SOURCE, 40, $old, hash('sha256', 'target-b'));
            self::fail('Konfliktní náhrada stejného zdrojového hashe byla přijata.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('konfliktní', $e->getMessage());
        } finally {
            $this->db->pdo()->rollBack();
        }

        $rolledBack = hash('sha256', 'rolled-back-old');
        $this->db->pdo()->beginTransaction();
        $this->map->record(FinancialCloneHashKind::SAVING_EVENT, 50, $rolledBack, hash('sha256', 'rolled-back-new'));
        $this->db->pdo()->rollBack();
        $this->db->pdo()->beginTransaction();
        self::assertNull($this->map->resolve($rolledBack));
        $this->db->pdo()->rollBack();
    }

    public function testOwnedBatchResolverRequiresExactTenantKindAndStableId(): void
    {
        $old = hash('sha256', 'membership-old-revision');
        $new = hash('sha256', 'membership-new-revision');
        $this->db->pdo()->beginTransaction();
        $this->map->recordOwned(FinancialCloneHashKind::MEMBERSHIP_REVISION, 11, 31, $old, $new);
        $resolved = $this->map->resolveOwnedMany([
            ['kind' => FinancialCloneHashKind::MEMBERSHIP_REVISION, 'supplier_id' => 11, 'owner_id' => 31, 'old_hash' => $old],
            ['kind' => FinancialCloneHashKind::MEMBERSHIP_REVISION, 'supplier_id' => 12, 'owner_id' => 31, 'old_hash' => $old],
            ['kind' => FinancialCloneHashKind::MEMBERSHIP_REVISION, 'supplier_id' => 11, 'owner_id' => 32, 'old_hash' => $old],
            ['kind' => FinancialCloneHashKind::MEMBERSHIP_OPERATION, 'supplier_id' => 11, 'owner_id' => 31, 'old_hash' => $old],
        ]);
        self::assertSame([$new, null, null, null], array_values($resolved));
        $this->db->pdo()->commit();
    }
}
