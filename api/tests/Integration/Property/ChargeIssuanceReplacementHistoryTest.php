<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\DimensionAssignmentRepository;
use MyInvoice\Repository\Property\Charges\ChargeRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Property\Charges\ChargePreviewService;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class ChargeIssuanceReplacementHistoryTest extends TestCase
{
    use ChargeIssuanceFixture;

    private Connection $db;
    private array $f;
    private array $request;
    private int $version = 1;
    private int $token = 0;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
        $this->request = $this->f['issue_request'];
        $this->request['units'] = array_values(array_filter($this->request['units'], fn (array $unit): bool => $unit['unit_id'] === $this->f['units']['G12']));
        $this->request['accounts'] = array_values(array_filter($this->request['accounts'], fn (array $account): bool => $account['component_id'] === $this->f['components']['ADMIN']));
        $dimensions = $container->get(DimensionService::class);
        foreach (['purpose', 'building_part'] as $code) {
            $type = $dimensions->createType($this->f['supplier'], ['code' => $code, 'name' => 'Synthetic '.$code]);
            $value = $dimensions->createValue($this->f['supplier'], (int) $type['id'], ['code' => 'A', 'name' => 'Synthetic A']);
            $this->request['accounts'][0]['dimensions'][(int) $type['id']] = (int) $value['id'];
        }
        $this->request['version_id'] = $this->saveVersion('1000');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function histories(): array
    {
        return ['reversed difference' => [true, false], 'cancelled difference' => [false, false],
            'multiple posted ancestors, reversed difference' => [true, true], 'multiple posted ancestors, cancelled difference' => [false, true]];
    }

    #[DataProvider('histories')]
    public function testReplacementRejectsEveryOutstandingAncestor(bool $postLast, bool $multiple): void
    {
        [$family, $last, $replacement] = $this->history($postLast, $multiple);
        $before = $this->ledgerBalance();
        self::assertSame($multiple ? '1100.00' : '1000.00', $before);
        $counts = $this->counts();
        try {
            $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $replacement);
            $batch = $this->repository()->issue($this->f['supplier'], ['request' => $replacement,
                'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'unsafe-replacement'], $this->f['actor']);
            $this->post($batch);
            self::fail('Outstanding ancestor accepted: receivable '.$before.' became '.$this->ledgerBalance().'.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_reverse_first', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        try {
            $this->repository()->issue($this->f['supplier'], ['request' => $replacement,
                'preview_hash' => str_repeat('0', 64), 'idempotency_key' => 'direct-replacement'], $this->f['actor']);
            self::fail('Current writer accepted an outstanding ancestor.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_reverse_first', $e->errorCode);
            self::assertSame(409, $e->httpStatus);
        }
        self::assertSame($before, $this->ledgerBalance());
        self::assertSame($counts, $this->counts());
        self::assertSame('posted', $this->reader()->find($this->f['supplier'], $family[0]['id'])['status']);
        self::assertSame($postLast ? 'reversed' : 'cancelled', $last['status']);
    }

    #[DataProvider('histories')]
    public function testFullyNeutralizedFamilyAllowsOneFullReplacementWithExactTuple(bool $postLast, bool $multiple): void
    {
        [$family, , $replacement] = $this->history($postLast, $multiple);
        foreach (array_reverse($family) as $charge) $this->reverse($charge);
        self::assertSame('0.00', $this->ledgerBalance());
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $replacement);
        self::assertSame($multiple ? '120000' : '110000', $preview['snapshot']['total_cents']);
        $input = ['request' => $replacement, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'valid-replacement'];
        $batch = $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']);
        self::assertSame($batch, $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']));
        $posted = $this->post($batch);
        $counts = $this->counts();
        self::assertSame($posted, $this->post($batch));
        self::assertSame($counts, $this->counts());
        self::assertSame($multiple ? '1200.00' : '1100.00', $this->ledgerBalance());
        $current = array_values(array_filter($this->reader()->charges($this->f['supplier']), static fn (array $charge): bool => $charge['status'] === 'posted'));
        self::assertCount(1, $current);
        self::assertSame($posted['charges'][0]['id'], $current[0]['id']);
        $charge = $current[0];
        (new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'], $charge['journal_entry_id'], $charge);
        $stmt = $this->db->pdo()->prepare('SELECT id,amount FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id');
        $stmt->execute([$this->f['supplier'], $charge['journal_entry_id']]);
        $lines = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $lines);
        $dimensions = (new DimensionAssignmentRepository($this->db))->lineDimensions($this->f['supplier'], array_column($lines, 'id'), true);
        $expected = $charge['snapshot']['lines'][0]['dimensions'];
        self::assertCount(3, $expected);
        foreach ($lines as $line) {
            self::assertSame($multiple ? '1200.00' : '1100.00', $line['amount']);
            self::assertEquals($expected, $dimensions[$line['id']]);
        }
    }

    private function history(bool $postLast, bool $multiple): array
    {
        $initial = $this->post($this->issue($this->request))['charges'][0];
        self::assertSame('100000', $initial['amount_cents']);
        $family = [$initial];
        $previous = $initial;
        if ($multiple) {
            $previous = $this->post($this->issue($this->nextRequest($previous, '1100')))['charges'][0];
            self::assertSame('10000', $previous['amount_cents']);
            $family[] = $previous;
        }
        $request = $this->nextRequest($previous, $multiple ? '1200' : '1100');
        $batch = $this->issue($request);
        if ($postLast) $batch = $this->post($batch);
        self::assertSame('10000', $batch['charges'][0]['amount_cents']);
        $last = $this->reverse($batch['charges'][0]);
        $request['issue_mode'] = 'replacement';
        $request['units'][0]['expected_revision'] = $last['revision_no'];
        $request['units'][0]['previous_charge_id'] = $last['id'];
        return [$family, $last, $request];
    }

    private function nextRequest(array $previous, string $amount): array
    {
        $request = $this->request;
        $request['version_id'] = $this->saveVersion($amount);
        $request['issue_mode'] = 'difference';
        $request['units'][0]['expected_revision'] = $previous['revision_no'];
        $request['units'][0]['previous_charge_id'] = $previous['id'];
        return $request;
    }

    private function saveVersion(string $amount): int
    {
        $version = self::chargeRequest($this->f);
        $version['expected_revision'] = $this->version++;
        $version['rates'] = array_values(array_filter($version['rates'], fn (array $rate): bool => $rate['component_id'] === $this->f['components']['ADMIN']));
        $version['rates'][0]['amount'] = $amount;
        $preview = (new ChargePreviewService($this->db))->preview($this->f['supplier'], $version);
        return (new ChargeRepository($this->db))->saveVersion($this->f['supplier'], ['request' => $version,
            'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'version-'.$this->version], $this->f['actor'])['id'];
    }

    private function issue(array $request): array
    {
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $request);
        return $this->repository()->issue($this->f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'],
            'idempotency_key' => 'issue-'.++$this->token], $this->f['actor']);
    }

    private function post(array $batch): array
    {
        return $this->repository()->post($this->f['supplier'], $batch['id'], array_map(static fn (array $charge): array =>
            ['id' => $charge['id'], 'row_version' => $charge['row_version']], $batch['charges']), $this->f['actor']);
    }

    private function reverse(array $charge): array
    {
        return $this->repository()->reverse($this->f['supplier'], $charge['id'], ['row_version' => $charge['row_version'],
            'accounting_date' => '2026-02-01', 'effective_at' => '2026-02-01T10:00:00+01:00',
            'reason' => 'Synthetic complete correction', 'evidence_ref' => 'Synthetic correction deed',
            'idempotency_key' => 'reverse-'.$charge['id']], $this->f['actor']);
    }

    private function ledgerBalance(): string
    {
        $stmt = $this->db->pdo()->prepare("SELECT SUM(CASE l.side WHEN 'debit' THEN l.amount ELSE -l.amount END) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.supplier_id=? AND a.account_code='315'");
        $stmt->execute([$this->f['supplier']]);
        return (string) $stmt->fetchColumn();
    }

    private function counts(): array
    {
        $counts = [];
        foreach (['unit_charge_batches', 'unit_charges', 'unit_charge_adjustments', 'unit_charge_issue_audit', 'journal_entries', 'journal_entry_lines'] as $table) {
            $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM '.$table.' WHERE supplier_id=?');
            $stmt->execute([$this->f['supplier']]);
            $counts[$table] = (int) $stmt->fetchColumn();
        }
        return $counts;
    }

    private function reader(): IssuanceReadRepository { return new IssuanceReadRepository($this->db); }
    private function repository(): IssuanceRepository { return Bootstrap::buildContainer()->get(IssuanceRepository::class); }
}
