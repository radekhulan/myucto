<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceRepository;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceException;
use MyInvoice\Service\Property\Charges\Issuance\IssuancePreviewService;
use MyInvoice\Tests\Support\ChargeIssuanceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChargeIssuanceLegacyContextTest extends TestCase
{
    use ChargeIssuanceFixture;
    private Connection $db;
    private array $f;

    protected function setUp(): void
    {
        $this->db = Bootstrap::buildContainer()->get(Connection::class);
        self::assertStringEndsWith('_test', (string) $this->db->pdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->db->pdo()->beginTransaction();
        $this->f = $this->issuanceFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public static function alteredContexts(): array
    {
        return [['cost_center', 'post_retry'], ['project_id', 'post_retry'], ['cost_center', 'reverse'], ['project_id', 'reverse'],
            ['cost_center', 'reversal_retry'], ['project_id', 'reversal_retry']];
    }

    #[DataProvider('alteredContexts')]
    public function testNativeProofRejectsChangedLegacyContext(string $column, string $operation): void
    {
        $repo = Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $this->f['issue_request']);
        $batch = $repo->issue($this->f['supplier'], ['request' => $this->f['issue_request'], 'preview_hash' => $preview['preview_hash'],
            'idempotency_key' => 'synthetic-legacy-proof'], $this->f['actor']);
        $posted = $repo->post($this->f['supplier'], $batch['id'], self::expected($batch), $this->f['actor']);
        self::assertSame($posted, $repo->post($this->f['supplier'], $batch['id'], self::expected($posted), $this->f['actor']));
        $charge = $posted['charges'][0];
        $input = ['row_version' => 2, 'accounting_date' => '2026-02-01', 'effective_at' => '2026-02-01T10:00:00+01:00',
            'reason' => 'Synthetic correction', 'evidence_ref' => 'Synthetic correction deed', 'idempotency_key' => 'synthetic-legacy-reverse'];
        $entry = $charge['journal_entry_id'];
        if ($operation === 'reversal_retry') {
            $reversed = $repo->reverse($this->f['supplier'], $charge['id'], $input, $this->f['actor']);
            self::assertSame($reversed, $repo->reverse($this->f['supplier'], $charge['id'], $input, $this->f['actor']));
            $entry = $reversed['reversal_entry_id'];
        }
        $value = 'Synthetic legacy centre';
        if ($column === 'project_id') {
            $currency = $this->db->pdo()->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn();
            $this->db->pdo()->prepare("INSERT INTO projects (client_id,name,currency_id) VALUES (?,'Synthetic legacy project',?)")
                ->execute([$this->f['clients'][1], $currency]);
            $value = (int) $this->db->pdo()->lastInsertId();
        }
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET ' . $column . '=? WHERE supplier_id=? AND entry_id=? ORDER BY line_no,id LIMIT 1')
            ->execute([$value, $this->f['supplier'], $entry]);
        $before = $this->state();
        try {
            if ($operation === 'post_retry') $repo->post($this->f['supplier'], $batch['id'], self::expected($posted), $this->f['actor']);
            else $repo->reverse($this->f['supplier'], $charge['id'], $input, $this->f['actor']);
            self::fail('A different legacy accounting tuple was accepted by the native charge proof.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_ledger_mismatch', $e->errorCode);
        }
        self::assertSame($before, $this->state(), 'Rejection must roll back a pending correction, audit and all ledger writes.');
    }

    private static function expected(array $batch): array
    {
        return array_map(static fn (array $charge): array => ['id' => $charge['id'], 'row_version' => $charge['row_version']], $batch['charges']);
    }

    private function state(): array
    {
        $out = [];
        foreach (['unit_charges', 'unit_charge_adjustments', 'unit_charge_issue_audit', 'journal_entries', 'journal_entry_lines'] as $table) {
            $q = $this->db->pdo()->prepare('SELECT * FROM ' . $table . ' WHERE supplier_id=? ORDER BY id');
            $q->execute([$this->f['supplier']]); $out[$table] = $q->fetchAll(\PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
