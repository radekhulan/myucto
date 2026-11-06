<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Payments\ClearingRevisionRepository;
use MyInvoice\Repository\Property\Payments\PaymentRepository;
use MyInvoice\Service\Property\Payments\PaymentSourceProof;
use MyInvoice\Service\Property\Payments\Workflow\ClearingCells;
use MyInvoice\Service\Property\Payments\Workflow\PaymentWorkflowFacade;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\TestCase;

final class PaymentSourceRevisionTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction(); $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testOnlyFreeCapacityMovesAndOldPaymentReversalRestoresItsOriginalCell(): void
    {
        [$native, $source] = $this->source();
        $repo = Bootstrap::buildContainer()->get(ClearingRevisionRepository::class);
        $request = $this->request($source);
        $before = $this->counts();
        $preview = $repo->preview($this->f['supplier'], $request);
        self::assertSame($before, $this->counts());
        $result = $repo->apply($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-free-revision', $this->f['actor']);
        self::assertSame($result, $repo->apply($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-free-revision', $this->f['actor']));
        $current = (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        self::assertSame($source['receipt'], $current['receipt'], 'Original receipt and native journal stay immutable.');
        self::assertSame('6000', $current['consumed_cents']);
        self::assertSame(['A' => '0', 'B' => '4000'], ClearingCells::available($current['clearing_cells'], $current['cell_consumed']));
        $payments = Bootstrap::buildContainer()->get(PaymentRepository::class);
        $payments->reverse($this->f['supplier'], (int) $native['parts'][0]['operation_id'], 2, '2026-03-02', 'Synthetic later reversal', 'synthetic-revision-reverse', $this->f['actor']);
        $current = (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        self::assertSame(['A' => '6000', 'B' => '4000'], ClearingCells::available($current['clearing_cells'], $current['cell_consumed']));
        self::assertSame('0', $current['consumed_cents']);
    }

    public function testConsumedCapacityAndStaleSourceVersionAreRejectedBeforeWriting(): void
    {
        [, $source] = $this->source(); $repo = Bootstrap::buildContainer()->get(ClearingRevisionRepository::class);
        $request = $this->request($source); $request['moves'][0]['amount_cents'] = '4001'; $before = $this->counts();
        try { $repo->preview($this->f['supplier'], $request); self::fail('Consumed capacity moved.'); }
        catch (\MyInvoice\Service\Property\Payments\PaymentException $e) { self::assertSame('property_payment_clearing_capacity', $e->errorCode); }
        $request = $this->request($source); $request['expected_source_version']++;
        try { $repo->preview($this->f['supplier'], $request); self::fail('Stale source version accepted.'); }
        catch (\MyInvoice\Service\Property\Payments\PaymentException $e) { self::assertSame('property_payment_source_version_conflict', $e->errorCode); }
        self::assertSame($before, $this->counts());
    }

    public function testRevisedCellCanBeSettledWithItsExactCurrentDimensions(): void
    {
        [$native, $source] = $this->source(); $repo = Bootstrap::buildContainer()->get(ClearingRevisionRepository::class);
        $request = $this->request($source); $preview = $repo->preview($this->f['supplier'], $request);
        $repo->apply($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-second-cell', $this->f['actor']);
        $current = (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        $payment = ['kind' => 'allocation', 'source' => ['kind' => 'cash', 'id' => $native['source']['id'], 'expected_version' => $current['version']],
            'amount_cents' => '4000', 'accounting_date' => '2026-03-02', 'reason' => 'Synthetic revised cell settlement', 'debts' => [],
            'clearing_selection' => [['key' => 'B', 'amount_cents' => '4000']],
            'credit_entitlement' => ['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id' => $this->f['units']['G12'], 'party_ids' => [$this->f['clients'][1]], 'evidence' => 'Synthetic separate entitlement', 'due_date' => '2026-03-03']];
        $payments = Bootstrap::buildContainer()->get(PaymentRepository::class); $preview = $payments->preview($this->f['supplier'], $payment);
        $operation = $payments->apply($this->f['supplier'], $payment, $preview['preview_hash'], 'synthetic-revised-payment', $this->f['actor']);
        self::assertSame($request['moves'][0]['to']['dimensions'], $operation['snapshot']['lines'][0]['dimensions']);
        $current = (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        self::assertSame('10000', $current['consumed_cents']); self::assertSame('0', $current['available_cents']);
        self::assertSame(['A' => '0', 'B' => '0'], ClearingCells::available($current['clearing_cells'], $current['cell_consumed']));
    }

    public function testClosedOriginalIsPreservedByAnOpenYearRevisionAndClosedDestinationIsRejected(): void
    {
        [$native, $source] = $this->source();
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status='closed' WHERE supplier_id=?")->execute([$this->f['supplier']]);
        (new \MyInvoice\Repository\AccountingPeriodRepository($this->db))->create($this->f['supplier'], 2027, '2027-01-01', '2027-12-31');
        $repo = Bootstrap::buildContainer()->get(ClearingRevisionRepository::class); $request = $this->request($source); $before = $this->counts();
        try { $repo->preview($this->f['supplier'], $request); self::fail('Closed correction date accepted.'); }
        catch (\MyInvoice\Service\Property\Payments\PaymentException $e) { self::assertSame('property_payment_target_period_not_open', $e->errorCode); }
        self::assertSame($before, $this->counts());
        $request['accounting_date'] = '2027-01-02'; $request['effective_at'] = '2027-01-02T10:00:00+01:00';
        $preview = $repo->preview($this->f['supplier'], $request);
        $repo->apply($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-closed-original', $this->f['actor']);
        $current = (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']);
        self::assertSame($source['receipt'], $current['receipt']); self::assertSame('2027-01-02', $current['revision_date']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFailureAfterTheRealRevisionRollsBackLedgerHeadAndSourceCapacity(): void
    {
        [$native, $source] = $this->source();
        $journal = Bootstrap::buildContainer()->get(\MyInvoice\Repository\JournalEntryRepository::class);
        $fault = $this->createMock(\MyInvoice\Repository\JournalEntryRepository::class);
        $fault->expects(self::once())->method('insertPropertySourceRevision')->willReturnCallback(function ($intent) use ($journal): never {
            $entry = $journal->insertPropertySourceRevision($intent);
            $q = $this->db->pdo()->prepare("SELECT status,journal_entry_id FROM unit_payment_source_revisions WHERE supplier_id=? AND id=?");
            $q->execute([$this->f['supplier'], $intent->revisionId]); $row = $q->fetch(\PDO::FETCH_ASSOC);
            self::assertSame('posted', $row['status']); self::assertSame($entry, (int) $row['journal_entry_id']);
            throw new \RuntimeException('Synthetic late revision failure');
        });
        $repo = new ClearingRevisionRepository($this->db, $fault); $request = $this->request($source);
        $preview = $repo->preview($this->f['supplier'], $request); $before = $this->counts();
        try { $repo->apply($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-revision-rollback', $this->f['actor']); self::fail('Expected late failure.'); }
        catch (\RuntimeException $e) { self::assertSame('Synthetic late revision failure', $e->getMessage()); }
        self::assertSame($before, $this->counts());
        self::assertSame($source, (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']));
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public static function legacyContexts(): array { return [['cost_center'], ['project_id']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyContexts')]
    public function testSourceProofRejectsChangedLegacyContextOnAnyCell(string $column): void
    {
        [$native, $source] = $this->source(); $value = 'Synthetic changed centre';
        if ($column === 'project_id') {
            $currency = $this->db->pdo()->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
            $this->db->pdo()->prepare("INSERT INTO projects (client_id,name,currency_id) VALUES (?,'Synthetic payment project',?)")->execute([$this->f['clients'][1], $currency]);
            $value = (int) $this->db->pdo()->lastInsertId();
        }
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET ' . $column . '=? WHERE supplier_id=? AND id=?')
            ->execute([$value, $this->f['supplier'], $source['receipt']['clearing_cells'][0]['line']['id']]);
        try { (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id']); self::fail('A changed legacy source tuple was accepted.'); }
        catch (\MyInvoice\Service\Property\Payments\PaymentException $e) { self::assertSame('property_payment_source_clearing_mismatch', $e->errorCode); }
    }

    private function source(): array
    {
        $this->db->pdo()->prepare("INSERT INTO cash_registers (supplier_id,name,account_code) VALUES (?,'Synthetic source revision','211')")->execute([$this->f['supplier']]);
        $request = ['source' => ['kind' => 'cash', 'draft' => ['register_id' => (int) $this->db->pdo()->lastInsertId(),
            'doc_type' => 'in', 'issue_date' => '2026-02-01', 'partner_name' => 'Synthetic payer', 'description' => 'Synthetic source', 'total_amount' => '100.00']],
            'clearing_cells' => [['key' => 'A', 'amount_cents' => '10000', 'dimensions' => []]],
            'parts' => [['key' => 'credit', 'request' => ['kind' => 'allocation', 'amount_cents' => '6000', 'accounting_date' => '2026-02-01', 'reason' => 'Synthetic credit', 'debts' => [],
                'credit_entitlement' => ['third_party' => ['basis' => 'other_documented', 'evidence' => 'Synthetic documented creditor title'], 'unit_id' => $this->f['units']['G12'], 'party_ids' => [$this->f['clients'][1]], 'evidence' => 'Synthetic entitlement deed', 'due_date' => '2026-03-01']]]]];
        $facade = Bootstrap::buildContainer()->get(PaymentWorkflowFacade::class);
        $preview = $facade->preview($this->f['supplier'], $request);
        $native = $facade->confirm($this->f['supplier'], $request, $preview['preview_hash'], 'synthetic-revision-native', $this->f['actor']);
        return [$native, (new PaymentSourceProof($this->db))->current($this->f['supplier'], 'cash', $native['source']['id'])];
    }

    private function request(array $source): array
    {
        $q = $this->db->pdo()->prepare("SELECT v.type_id,v.id FROM property_objects o JOIN dimension_values v ON v.id=o.dimension_value_id AND v.supplier_id=o.supplier_id WHERE o.supplier_id=? AND o.id=?");
        $q->execute([$this->f['supplier'], $this->f['units']['G12']]); $value = $q->fetch(\PDO::FETCH_ASSOC);
        return ['source_id' => $source['id'], 'expected_source_version' => $source['version'], 'expected_revision_id' => null,
            'accounting_date' => '2026-03-01', 'effective_at' => '2026-03-01T10:00:00+01:00', 'reason' => 'Synthetic analytic correction', 'evidence' => 'Synthetic documented attribution',
            'moves' => [['from_key' => 'A', 'amount_cents' => '4000', 'to' => ['key' => 'B', 'dimensions' => [(int) $value['type_id'] => (int) $value['id']]]]]];
    }

    private function counts(): array
    {
        $out = [];
        foreach (['unit_payment_source_revisions', 'unit_payment_source_heads', 'unit_payment_audit', 'journal_entries', 'journal_entry_lines'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE supplier_id=?');
            $q->execute([$this->f['supplier']]); $out[$table] = (int) $q->fetchColumn();
        }
        return $out;
    }
}
