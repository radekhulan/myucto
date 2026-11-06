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
final class ChargeIssuanceComponentDateTest extends TestCase
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
        $dimensions = $container->get(DimensionService::class);
        foreach (['purpose', 'building_part'] as $code) {
            $type = $dimensions->createType($this->f['supplier'], ['code' => $code, 'name' => 'Synthetic '.$code]);
            $value = $dimensions->createValue($this->f['supplier'], (int) $type['id'], ['code' => 'A', 'name' => 'Synthetic A']);
            foreach ($this->request['accounts'] as &$account) $account['dimensions'][(int) $type['id']] = (int) $value['id'];
            unset($account);
        }
        $this->request['version_id'] = $this->saveVersion('1000');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function removals(): array
    {
        return ['unit removal, outstanding amount' => ['unit', false], 'global removal, outstanding amount' => ['global', false],
            'unit removal, zero net amount' => ['unit', true], 'global removal, zero net amount' => ['global', true]];
    }

    #[DataProvider('removals')]
    public function testRemovalUsesThisUnitsCalculationAndOutstandingAmount(string $removal, bool $zeroNet): void
    {
        $original = $this->post($this->issue($this->request))['charges'][0];
        self::assertSame('3400.00', $this->ledgerBalance());
        $previous = $original;
        $request = $this->request;
        $request['issue_mode'] = 'difference';
        if ($zeroNet) {
            $request['version_id'] = $this->saveVersion('0');
            $request['units'][0]['expected_revision'] = $previous['revision_no'];
            $request['units'][0]['previous_charge_id'] = $previous['id'];
            $previous = $this->post($this->issue($request))['charges'][0];
            self::assertSame('-100000', $previous['amount_cents']);
            self::assertSame('2400.00', $this->ledgerBalance());
        }
        $request['version_id'] = $this->saveVersion('1000', $removal, '150000');
        $request['units'][0]['expected_revision'] = $previous['revision_no'];
        $request['units'][0]['previous_charge_id'] = $previous['id'];
        $before = $this->counts();
        if (!$zeroNet) {
            try {
                $batch = $this->issue($request);
                $this->post($batch);
                self::fail('Removed unit component kept old1000 while another component posted600; balance='.$this->ledgerBalance());
            } catch (IssuanceException $e) {
                self::assertSame('property_charge_issue_full_reversal_required', $e->errorCode);
                self::assertSame(409, $e->httpStatus);
            }
            try {
                $this->repository()->issue($this->f['supplier'], ['request' => $request, 'preview_hash' => str_repeat('0', 64), 'idempotency_key' => 'direct-removal'], $this->f['actor']);
                self::fail('Current writer ignored removed unit component.');
            } catch (IssuanceException $e) { self::assertSame('property_charge_issue_full_reversal_required', $e->errorCode); }
            self::assertSame($before, $this->counts());
            self::assertSame('3400.00', $this->ledgerBalance());
            return;
        }
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $request);
        self::assertSame($before, $this->counts());
        self::assertSame('60000', $preview['snapshot']['total_cents']);
        $input = ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'zero-component-removal'];
        $batch = $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']);
        self::assertSame($batch, $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']));
        $posted = $this->post($batch);
        $counts = $this->counts();
        self::assertSame($posted, $this->post($batch));
        self::assertSame($counts, $this->counts());
        self::assertSame('3000.00', $this->ledgerBalance());
        $this->assertTuple($posted['charges'][0], 2);
    }

    public static function instants(): array
    {
        return ['winter midnight' => ['2026-01-02T00:30:00+01:00', '2026-01-02', '2026-01-01 23:30:00.000000'],
            'summer midnight' => ['2026-07-02T00:30:00+02:00', '2026-07-02', '2026-07-01 22:30:00.000000'],
            'winter equivalent UTC' => ['2026-01-01T23:30:00Z', '2026-01-02', '2026-01-01 23:30:00.000000'],
            'summer equivalent UTC' => ['2026-07-01T22:30:00Z', '2026-07-02', '2026-07-01 22:30:00.000000']];
    }

    #[DataProvider('instants')]
    public function testDocumentDateIsPragueDayWhileAccountingDateAndInstantRemainIndependent(string $instant, string $day, string $utc): void
    {
        $request = $this->request;
        $request['issued_at'] = $instant;
        $request['accounting_date'] = '2026-08-10';
        $before = $this->counts();
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $request);
        self::assertSame($before, $this->counts());
        $input = ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'midnight-case'];
        $batch = $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']);
        self::assertSame($batch, $this->repository()->issue($this->f['supplier'], $input, $this->f['actor']));
        $posted = $this->post($batch);
        $charge = $posted['charges'][0];
        self::assertSame($utc, $charge['issued_at']);
        $stmt = $this->db->pdo()->prepare('SELECT entry_date,document_date FROM journal_entries WHERE supplier_id=? AND id=?');
        $stmt->execute([$this->f['supplier'], $charge['journal_entry_id']]);
        self::assertSame(['entry_date' => '2026-08-10', 'document_date' => $day], $stmt->fetch(\PDO::FETCH_ASSOC));
        self::assertSame('3400.00', $this->ledgerBalance());
        $this->assertTuple($charge, 4);
        $counts = $this->counts();
        self::assertSame($posted, $this->post($batch));
        self::assertSame($counts, $this->counts());
    }

    private function assertTuple(array $charge, int $lineCount): void
    {
        (new IssuanceJournalProof($this->db))->assertEntry($this->f['supplier'], $charge['journal_entry_id'], $charge);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM journal_entry_lines WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id');
        $stmt->execute([$this->f['supplier'], $charge['journal_entry_id']]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount($lineCount, $ids);
        $dimensions = (new DimensionAssignmentRepository($this->db))->lineDimensions($this->f['supplier'], $ids, true);
        foreach ($ids as $id) {
            self::assertCount(3, $dimensions[$id]);
            self::assertEquals($charge['snapshot']['lines'][0]['dimensions'], $dimensions[$id]);
        }
    }

    private function saveVersion(string $amount, string $removal = 'none', string $long = '120000'): int
    {
        $version = self::chargeRequest($this->f);
        $version['expected_revision'] = $this->version++;
        $version['rates'] = array_values(array_filter($version['rates'], fn (array $rate): bool => $rate['component_id'] !== $this->f['components']['WATER'] && ($removal !== 'global' || $rate['component_id'] !== $this->f['components']['ADMIN'])));
        foreach ($version['rates'] as &$rate) {
            if ($rate['component_id'] === $this->f['components']['LONG']) $rate['amount'] = $long;
            else {
                $rate['amount'] = $amount;
                if ($removal === 'unit') {
                    $rate['unit_ids'] = array_values(array_diff($rate['unit_ids'], [$this->f['units']['G12']]));
                    $rate['legal_basis'] = 'declaration_override';
                    $rate['scope_ref'] = 'Synthetic explicit declaration excluding garage';
                }
            }
        }
        unset($rate);
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
            ['id' => $charge['id'], 'row_version' => $charge['row_version']], $batch['charges']), $this->f['actor'],
            \MyInvoice\Repository\Property\Receivables\ChargeDebtRegistrar::MODE_MANUAL);
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
