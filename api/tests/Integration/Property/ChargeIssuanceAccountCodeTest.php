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

final class ChargeIssuanceAccountCodeTest extends TestCase
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

    public static function chartCodes(): array { return [['315001','324001','955001'], ['315A','324A','955A'], ['315.001','324.001','955.001']]; }

    #[DataProvider('chartCodes')]
    public function testNativeIssuanceUsesTheActualTenantChartCode(string $receivable, string $counter, string $long): void
    {
        $this->account($this->f['supplier'], $receivable, 'asset');
        $this->account($this->f['supplier'], $counter);
        $this->account($this->f['supplier'], $long);
        $request = $this->request($counter, $receivable, $long);
        $preview = (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $request);
        $repo = Bootstrap::buildContainer()->get(IssuanceRepository::class);
        $batch = $repo->issue($this->f['supplier'], ['request' => $request, 'preview_hash' => $preview['preview_hash'], 'idempotency_key' => 'synthetic-chart-code'], $this->f['actor']);
        $posted = $repo->post($this->f['supplier'], $batch['id'], array_map(static fn (array $charge): array => ['id' => $charge['id'], 'row_version' => 1], $batch['charges']), $this->f['actor']);
        self::assertCount(12, $posted['charges']);
        $q = $this->db->pdo()->prepare('SELECT SUM(l.amount) FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id WHERE l.supplier_id=? AND a.account_code=?');
        foreach ([$receivable => '264000.00', $counter => '144000.00', $long => '120000.00'] as $code => $expected) {
            $q->execute([$this->f['supplier'], $code]);
            self::assertSame($expected, $q->fetchColumn());
        }
    }

    public function testAnExistingForeignTenantAccountDoesNotAuthorizeIssuance(): void
    {
        $other = $this->createIsolatedSupplier($this->db->pdo(), $this->f['supplier']);
        $this->account($other, '324001');
        try {
            (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $this->request('324001'));
            self::fail('A different tenant account was accepted.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_account_invalid', $e->errorCode);
        }
    }

    public static function invalidRoles(): array { return [['315001','liability'], ['324001','revenue'], ['955001','revenue']]; }

    #[DataProvider('invalidRoles')]
    public function testAccountCodeDoesNotSubstituteForItsAccountingRole(string $invalid, string $type): void
    {
        foreach (['315001' => 'asset', '324001' => 'liability', '955001' => 'liability'] as $code => $role)
            $this->account($this->f['supplier'], (string) $code, (string) $code === $invalid ? $type : $role);
        try {
            (new IssuancePreviewService($this->db))->preview($this->f['supplier'], $this->request('324001', '315001', '955001'));
            self::fail('An analytic code replaced the required accounting role.');
        } catch (IssuanceException $e) {
            self::assertSame('property_charge_issue_account_invalid', $e->errorCode);
        }
    }

    private function account(int $supplier, string $code, string $type = 'liability'): void
    {
        $this->db->pdo()->prepare("INSERT INTO chart_of_accounts (supplier_id,account_code,name,account_type,normal_side,is_synthetic,is_active) VALUES (?,?,'Synthetic analytic',?,?,0,1)")->execute([$supplier, $code, $type, $type === 'asset' ? 'debit' : 'credit']);
    }

    private function request(string $code, string $receivable = '315', string $long = '955'): array
    {
        $request = $this->f['issue_request'];
        foreach ($request['accounts'] as &$account) {
            $account['receivable_account'] = $receivable;
            $account['counter_account'] = $account['component_id'] === $this->f['components']['LONG'] ? $long : $code;
        }
        unset($account);
        return $request;
    }
}
