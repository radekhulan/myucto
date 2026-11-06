<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Catalog;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Catalog\PropertyLegalRoleRepository;
use MyInvoice\Repository\Property\PropertyRegistryRepository;
use MyInvoice\Service\Property\Catalog\PropertyLegalRoleCatalog;
use MyInvoice\Service\Property\PropertyException;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\PropertyState;
use MyInvoice\Tests\Support\PropertyLegalRoleFixture;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class PropertyLegalRoleCatalogNativeTest extends TestCase
{
    use PropertyLegalRoleFixture;

    private Connection $db;
    private array $fixture;
    private PropertyLegalRoleCatalog $catalog;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->fixture = $this->legalRoleFixture($this->db);
        $this->catalog = new PropertyLegalRoleCatalog(new PropertyLegalRoleRepository($this->db));
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testCuratedProjectionUsesOnlyMaterializedOpaqueIdsAndStrictEnums(): void
    {
        $result = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 100);
        self::assertCount(12, $result['items']);
        self::assertFalse($result['financial_authorization']);
        self::assertSame('native_append_only_revision_chain_and_materialized_roles', $result['proof_basis']);
        foreach ($result['items'] as $item) {
            self::assertSame('verified', $item['role_proof_status']);
            self::assertSame(1, $item['verified_revision_count']);
            foreach ($item['ownership_groups'] as $group) {
                self::assertGreaterThan(0, $group['ownership_group_id']);
                self::assertContains($group['kind'], ['single', 'coownership_share', 'marital_community']);
                self::assertNotEmpty($group['member_ids']);
            }
            foreach ($item['delegated_roles'] as $role) {
                self::assertContains($role['role'], ['payer', 'representative', 'delivery']);
                self::assertSame([], array_diff($role['scopes'], ['communication', 'delivery', 'voting', 'payment_instruction', 'setoff', 'refund_instruction']));
            }
        }
        $this->assertRedacted($result);
    }

    public function testKnownAtCannotUseRevisionRecordedLater(): void
    {
        $unitId = $this->fixture['units']['U02'];
        $stmt = $this->db->pdo()->prepare('SELECT recorded_at FROM property_revisions WHERE supplier_id = ? AND object_id = ? AND revision_no = 1');
        $stmt->execute([$this->fixture['supplier'], $unitId]);
        $oldKnownAt = str_replace(' ', 'T', (string) $stmt->fetchColumn()) . 'Z';
        usleep(2000);
        $state = $this->fixture['states']['U02'];
        $state['active'] = false;
        (new PropertyRegistryRepository($this->db))->revise($this->fixture['supplier'], $unitId, 'unit', [
            'expected_revision' => 1, 'effective_from' => '2026-02-01T00:00:00+01:00', 'effective_to' => null,
            'status' => 'confirmed', 'state' => $state, 'reason' => 'Synthetic later legal revision',
            'title_ref' => 'Synthetic later deed', 'idempotency_key' => 'catalog-later-revision',
        ], $this->fixture['actor']);
        $stmt->execute([$this->fixture['supplier'], $unitId]);
        $old = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', $oldKnownAt, null, 1, $unitId)['items'][0];
        $new = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 1, $unitId)['items'][0];
        self::assertSame(1, $old['revision_no']);
        self::assertTrue($old['active']);
        self::assertSame(2, $new['revision_no']);
        self::assertFalse($new['active']);
        self::assertSame(2, $new['verified_revision_count']);
    }

    public function testRequestHashCorruptionFailsClosed(): void
    {
        $unitId = $this->fixture['units']['U02'];
        $this->db->pdo()->prepare("INSERT INTO property_revisions
            (supplier_id, object_id, revision_no, status, effective_from, effective_to, state_json, state_hash, idempotency_key, request_hash, reason, title_ref, created_by, recorded_at)
            SELECT supplier_id, object_id, 2, status, effective_from, effective_to, state_json, state_hash, 'catalog-corrupt-request', REPEAT('0', 64), reason, title_ref, created_by, '2026-10-19 12:00:00.000000'
            FROM property_revisions WHERE supplier_id = ? AND object_id = ? AND revision_no = 1")->execute([$this->fixture['supplier'], $unitId]);
        $this->expectException(PropertyException::class);
        $this->expectExceptionMessage('nelze ověřit');
        $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 1, $unitId);
    }

    public function testMaterializedOwnershipMismatchFailsClosed(): void
    {
        $unitId = $this->fixture['units']['U02'];
        $stmt = $this->db->pdo()->prepare('SELECT id FROM property_revisions WHERE supplier_id=? AND object_id=? AND revision_no=1');
        $stmt->execute([$this->fixture['supplier'], $unitId]);
        $revisionId = (int) $stmt->fetchColumn();
        $this->db->pdo()->prepare("INSERT INTO property_revision_groups(supplier_id,revision_id,group_key,kind,share_num,share_den,title_ref)
            VALUES(?,?,'UNEXPECTED','single','1','1','Synthetic unexpected title')")->execute([$this->fixture['supplier'], $revisionId]);
        $group = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare('INSERT INTO property_revision_members(supplier_id,group_id,client_id) VALUES(?,?,?)')
            ->execute([$this->fixture['supplier'], $group, $this->fixture['clients'][1]]);
        $this->expectException(PropertyException::class);
        $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 1, $unitId);
    }

    public function testRevisionProofCrossesFiveHundredRowBatchWithoutDroppingHistory(): void
    {
        $unitId = $this->fixture['units']['U02'];
        $state = PropertyState::normalize('unit', $this->fixture['states']['U02']);
        $stateJson = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $stateHash = PropertyInput::hash($state);
        $insert = $this->db->pdo()->prepare("INSERT INTO property_revisions
            (supplier_id,object_id,revision_no,status,effective_from,effective_to,state_json,state_hash,idempotency_key,request_hash,reason,title_ref,created_by,recorded_at)
            VALUES(?,?,?,'pending','2030-01-01 00:00:00.000000',NULL,?,?,?,?,?,?,?,'2026-10-19 12:00:00.000000')");
        for ($revision = 2; $revision <= 501; ++$revision) {
            $key = 'catalog-batch-' . $revision;
            $request = ['expected_revision' => $revision - 1, 'effective_from' => '2030-01-01T00:00:00.000000Z', 'effective_to' => null,
                'status' => 'pending', 'state' => $state, 'reason' => 'Synthetic batched pending revision',
                'title_ref' => 'Synthetic batched title', 'idempotency_key' => $key];
            $requestHash = PropertyInput::hash(['kind' => 'unit', 'id' => $unitId, 'request' => $request, 'actor' => $this->fixture['actor']]);
            $insert->execute([$this->fixture['supplier'], $unitId, $revision, $stateJson, $stateHash, $key, $requestHash,
                'Synthetic batched pending revision', 'Synthetic batched title', $this->fixture['actor']]);
        }
        $item = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 1, $unitId)['items'][0];
        self::assertSame(501, $item['verified_revision_count']);
        self::assertSame(1, $item['revision_no']);
        self::assertSame(0, $item['pending_revision_count']);
    }

    public function testListUsesConstantBoundedSelectCount(): void
    {
        $pdo = $this->db->pdo();
        $original = $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PropertyLegalRoleCountingStatement::class]);
        try {
            PropertyLegalRoleCountingStatement::$selects = 0;
            $one = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 1);
            $oneCount = PropertyLegalRoleCountingStatement::$selects;
            PropertyLegalRoleCountingStatement::$selects = 0;
            $many = $this->catalog->read($this->fixture['supplier'], '2026-06-01T10:00:00Z', '2099-01-01T00:00:00Z', null, 100);
            $manyCount = PropertyLegalRoleCountingStatement::$selects;
        } finally {
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, $original);
        }
        self::assertCount(1, $one['items']);
        self::assertCount(12, $many['items']);
        self::assertLessThanOrEqual(6, $manyCount);
        self::assertSame($oneCount, $manyCount);
    }

    private function assertRedacted(mixed $value): void
    {
        $forbidden = ['code', 'unit_no', 'name', 'group_key', 'title_ref', 'evidence', 'reason', 'request_hash', 'state_hash', 'idempotency_key', 'created_by', 'actor'];
        if (!is_array($value)) return;
        foreach ($value as $key => $item) {
            if (is_string($key)) self::assertNotContains($key, $forbidden);
            $this->assertRedacted($item);
        }
    }
}

final class PropertyLegalRoleCountingStatement extends PDOStatement
{
    public static int $selects = 0;

    protected function __construct() {}

    public function execute(?array $params = null): bool
    {
        if (preg_match('/^\s*SELECT\b/i', $this->queryString)) self::$selects++;
        return parent::execute($params);
    }
}
