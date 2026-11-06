<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\JointAllocationKeyRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\Allocation\JointAllocationException;
use MyInvoice\Service\Accounting\Allocation\JointAllocationPreviewService;
use MyInvoice\Service\Accounting\Allocation\JointAllocationService;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class ChargeIssuanceAllocationGuardTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $fixture;
    private array $charge;
    private array $key;
    private int $line;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $f = $this->fixture = $this->issuanceFixture($this->db);
        $components = new ChargeRepository($this->db);
        $component = $components->createComponent($f['supplier'], ['code' => 'CONTRIBUTION', 'name' => 'Synthetic contribution', 'kind' => 'contribution',
            'service_id' => null, 'effective_at' => '2026-01-01T00:00:00+01:00', 'account_code' => '', 'idempotency_key' => 'CONTRIBUTION'], $f['actor']);
        $request = self::chargeRequest($f);
        $request['expected_revision'] = 1;
        $request['rates'] = [$request['rates'][1]];
        $request['rates'][0]['component_id'] = $component['id'];
        $preview = (new ChargePreviewService($this->db))->preview($f['supplier'], $request);
        $version = $components->saveVersion($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'contribution-version'], $f['actor']);
        $catalog = (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog($this->db))->ensureForFramework($f['supplier'], '2026-01-01');
        $kind = (new \MyInvoice\Repository\DimensionRepository($this->db))->createValue(['id' => $catalog['activity_kind']['id'], 'supplier_id' => $f['supplier'], 'supplier_group_id' => null], ['code' => 'MANAGEMENT', 'name' => 'Synthetic management']);
        $dims = [$catalog['activity']['id'] => $catalog['activity']['values']['main'], $catalog['activity_kind']['id'] => $kind, $catalog['tax_regime']['id'] => $catalog['tax_regime']['values']['subject']];
        $request = $f['issue_request'];
        $request['version_id'] = $version['id'];
        $request['source_key'] = 'SYNTHETIC-CONTRIBUTION';
        $request['units'] = [$request['units'][0]];
        $request['units'][0]['notices'] = [];
        $request['accounts'] = [['component_id' => $component['id'], 'receivable_account' => '315', 'counter_account' => '649', 'basis_ref' => 'Synthetic definitive contribution decision', 'settlement_due_on' => null, 'dimensions' => $dims]];
        $preview = (new IssuancePreviewService($this->db))->preview($f['supplier'], $request);
        $writer = Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $batch = $writer->issue($f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'contribution-issue'], $f['actor']);
        $this->charge = $writer->post($f['supplier'], $batch['id'], [['id' => $batch['charges'][0]['id'], 'row_version' => 1]], $f['actor'])['charges'][0];
        $q = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='credit'");
        $q->execute([$this->charge['journal_entry_id']]); $this->line = (int) $q->fetchColumn();
        $this->key = (new JointAllocationKeyRepository($this->db))->create($f['supplier'], 'OWNER', 'Synthetic key', [
            'valid_from' => '2026-01-01', 'valid_to' => '2027-01-01', 'basis' => 'hours', 'explanation' => 'Synthetic allocation',
            'cells' => [['activity' => $catalog['activity']['values']['main'], 'activity_kind' => $kind, 'tax_regime' => $catalog['tax_regime']['values']['subject'], 'weight' => '1']],
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testNativeIssuedRevenueCannotBecomeAnotherOwnersAllocationSource(): void
    {
        try {
            $this->allocate($this->line);
            self::fail('J5 created an allocation claim over an issued revenue source.');
        } catch (JointAllocationException $e) {
            self::assertSame('property_charge_issue_source_immutable', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertTrue($this->db->pdo()->inTransaction());
        $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM joint_allocation_batches WHERE supplier_id=?');
        $q->execute([$this->fixture['supplier']]); self::assertSame(0, (int) $q->fetchColumn());
        $result = Bootstrap::buildContainer()->get(IssuanceRepository::class)->reverse($this->fixture['supplier'], $this->charge['id'], [
            'row_version' => 2, 'accounting_date' => '2026-03-01', 'effective_at' => '2026-03-01T10:00:00+01:00', 'reason' => 'Synthetic correction',
            'evidence_ref' => 'Synthetic deed', 'idempotency_key' => 'reverse-after-refusal',
        ], $this->fixture['actor']);
        self::assertSame('reversed', $result['status']);
    }

    public function testOrdinaryRevenueWithSameJointTupleRemainsAllocatable(): void
    {
        $supplier = $this->fixture['supplier'];
        $journal = Bootstrap::buildContainer()->get(JournalEntryRepository::class);
        $original = $journal->findForUpdate($this->charge['journal_entry_id'], $supplier);
        $dims = (new \MyInvoice\Repository\DimensionAssignmentRepository($this->db))->entryLineDimensions($supplier, $this->charge['journal_entry_id'], true);
        $lines = array_map(static fn (array $l): array => ['account_id' => $l['account_id'], 'side' => $l['side'], 'amount' => $l['amount'], 'dimensions' => $dims[$l['id']]], $original['lines']);
        $id = $journal->insert(['supplier_id' => $supplier, 'period_id' => $original['period_id'], 'entry_date' => '2026-01-02', 'source_type' => 'manual', 'posted_at' => '2026-01-02 12:00:00'], $lines);
        $q = $this->db->pdo()->prepare("SELECT id FROM journal_entry_lines WHERE entry_id=? AND side='credit'"); $q->execute([$id]);
        $result = $this->allocate((int) $q->fetchColumn());
        self::assertSame('applied', $result['status']);
        self::assertGreaterThan(0, $result['journal_entry_id']);
    }

    private function allocate(int $line): array
    {
        $supplier = $this->fixture['supplier'];
        $preview = (new JointAllocationPreviewService($this->db))->preview($supplier, $this->key['id'], '2026-02-01', [$line]);
        return (new JointAllocationService($this->db, new \MyInvoice\Service\Accounting\Allocation\JointAllocationJournalAdapter(Bootstrap::buildContainer()->get(JournalEntryRepository::class))))->apply($supplier, ['key_version_id' => $this->key['id'], 'target_date' => '2026-02-01',
            'source_line_ids' => [$line], 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'owner-allocation', 'reason' => 'Synthetic allocation']);
    }
}
