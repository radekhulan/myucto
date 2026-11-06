<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\Property\Services\Settlement\{ServiceLifecycleRepository,ServiceLifecyclePolicyRepository};
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Service\Property\Services\Reconciliation\ServiceReconciliation;
use MyInvoice\Service\Property\Services\Settlement\ServiceLifecycleWorkflow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P1-7: dohad roku N nesmí zmizet v jiném účetním období, než ve kterém proběhne konečné
 * zúčtování. P1-9: profil služby je politika služby × období, ne volba jednotky.
 */
final class ServiceLifecycleUnwindPeriodTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;

    protected function setUp(): void
    {
        $this->fixture = new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        (new \ReflectionMethod($this->fixture, 'setUp'))->invoke($this->fixture);
        $this->db = (new \ReflectionProperty($this->fixture, 'db'))->getValue($this->fixture);
    }

    protected function tearDown(): void
    {
        (new \ReflectionMethod($this->fixture, 'tearDown'))->invoke($this->fixture);
    }

    public static function policies(): array
    {
        return [['service_balance_sheet'], ['service_profit_and_loss']];
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$args);
    }

    private function container(): \DI\Container
    {
        return (new \ReflectionProperty($this->fixture, 'container'))->getValue($this->fixture);
    }

    private function reject(callable $work, string $code): void
    {
        try {
            $work();
            self::fail('Accepted: '.$code);
        } catch (\DomainException $e) {
            self::assertSame('settlement_'.$code, $e->getMessage());
        }
    }

    private function otherUnit(array $f): int
    {
        foreach ($f['units'] as $code => $id) if ($code !== 'U02') return $id;
        self::fail('Fixture has a single unit');
    }

    #[DataProvider('policies')]
    public function testFinalInLaterPeriodThanUnwindIsRejectedAndClosingSeesGap(string $policy): void
    {
        $d = $this->invoke('base', $policy);
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        (new AccountingPeriodRepository($this->db))->create($f['supplier'], 2028, '2028-01-01', '2028-12-31');
        $d['owner']->unwind($f['supplier'], $d['case'], '2027-04-01', $f['actor']);

        $this->reject(
            fn () => $d['owner']->finalize($f['supplier'], $d['case'], $invoice['invoice'], $invoice['line']['id'], '2028-01-10', 'Synthetic final in later year', $f['actor']),
            'lifecycle_unwind_period'
        );

        $sources = $d['owner']->reconciliationSources($f['supplier'], $d['case']);
        $gaps = array_values(array_filter($sources['pending'], fn ($p) => $p['code'] === 'lifecycle_unwind_without_final'));
        self::assertNotEmpty($gaps);
        self::assertSame('2027-12-31', $gaps[0]['accounting_date']);
        $closing = (new ServiceReconciliation($this->db))->at($f['supplier'], '2027-12-31');
        self::assertContains('lifecycle_unwind_without_final', array_column($closing['incomplete'], 'code'));
        self::assertSame('incomplete', $closing['coverage']);
    }

    public function testStandaloneUnwindInsideConsumptionPeriodIsRejected(): void
    {
        $d = $this->invoke('base', 'service_balance_sheet');
        $f = $d['f'];
        $this->reject(
            fn () => $d['owner']->unwind($f['supplier'], $d['case'], '2026-12-31', $f['actor']),
            'lifecycle_unwind_with_final_required'
        );
    }

    #[DataProvider('policies')]
    public function testFinalizeCommandUnwindsInTheSamePeriod(string $policy): void
    {
        $d = $this->invoke('base', $policy);
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        $workflow = new ServiceLifecycleWorkflow($this->db, $d['owner']);
        $command = ['operation' => 'finalize', 'case_id' => $d['case'], 'accounting_date' => '2027-04-01', 'invoice_id' => $invoice['invoice'], 'line_id' => $invoice['line']['id'], 'title' => 'Synthetic final allocation title'];
        $preview = $workflow->preview($f['supplier'], $command, $f['actor']);
        $done = $workflow->execute($f['supplier'], $command, $f['actor'], $preview['expected_state'], $preview['preview_hash'], 'finalize-with-unwind');

        self::assertSame($d['case'], $done['case_id']);
        $state = $d['owner']->current($f['supplier'], $d['case']);
        self::assertSame('1120000', $state['final']['amount_cents']);
        foreach ($d['entries'] as $entry) {
            $reversal = (new PaymentJournalProof($this->db))->current($f['supplier'], $entry)['entry']['reversed_by'];
            self::assertNotNull($reversal);
            self::assertSame('2027-04-01', (new PaymentJournalProof($this->db))->current($f['supplier'], $reversal)['entry']['entry_date']);
        }
        $sources = $d['owner']->reconciliationSources($f['supplier'], $d['case']);
        self::assertSame([], array_values(array_filter($sources['pending'], fn ($p) => $p['code'] === 'lifecycle_unwind_without_final')));
    }

    public function testServicePolicyIsSharedByAllUnitsOfThePeriod(): void
    {
        $d = $this->invoke('base', 'service_balance_sheet');
        $f = $d['f'];
        $owner = new ServiceLifecycleRepository($this->db);
        $other = $this->otherUnit($f);

        $this->reject(
            fn () => $owner->openCase($f['supplier'], $other, $f['service']['id'], '2026-01-01', '2026-12-31', 'service_profit_and_loss', 'Synthetic per-unit policy choice', $f['actor'], '315.210'),
            'lifecycle_policy_mismatch'
        );

        $policies = new ServiceLifecyclePolicyRepository($this->db);
        $this->reject(
            fn () => $policies->declare($f['supplier'], $f['service']['id'], '2026-07-01', 'service_profit_and_loss', 'Synthetic mid-period change', $f['actor']),
            'lifecycle_policy_period_split'
        );
        $version = $policies->declare($f['supplier'], $f['service']['id'], '2027-01-01', 'service_profit_and_loss', 'Synthetic approved policy change from 2027', $f['actor']);
        self::assertSame('declared', $version['origin']);

        $this->reject(
            fn () => $owner->openCase($f['supplier'], $other, $f['service']['id'], '2027-01-01', '2027-12-31', 'service_balance_sheet', 'Synthetic stale policy', $f['actor'], '315.210'),
            'lifecycle_policy_mismatch'
        );
        $this->reject(
            fn () => $owner->openCase($f['supplier'], $other, $f['service']['id'], '2026-07-01', '2027-06-30', 'service_profit_and_loss', 'Synthetic straddling case', $f['actor'], '315.210'),
            'lifecycle_policy_period_split'
        );
        self::assertGreaterThan(0, $owner->openCase($f['supplier'], $other, $f['service']['id'], '2027-01-01', '2027-12-31', 'service_profit_and_loss', 'Synthetic consistent policy', $f['actor'], '315.210'));

        $listed = $policies->list($f['supplier'], $f['service']['id']);
        self::assertSame(['first_case', 'declared'], array_column($listed['items'], 'origin'));
        self::assertSame(['service_balance_sheet', 'service_profit_and_loss'], array_column($listed['items'], 'policy'));
    }

    public function testMigrationConvertsOnlyConsistentServiceHistory(): void
    {
        $data = (new \ReflectionMethod($this->fixture, 'invoke'))->invoke($this->fixture, 'nativeFixture', '800000');
        $f = $data['f'];
        $units = array_values($f['units']);
        $insert = $this->db->pdo()->prepare('INSERT INTO property_service_lifecycle_cases(supplier_id,unit_id,service_id,period_from,period_to,policy,facts_json,facts_hash,created_by,recorded_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))');
        $raw = function (int $unit, string $from, string $to, string $policy) use ($insert, $f): void {
            $insert->execute([$f['supplier'], $unit, $f['service']['id'], $from, $to, $policy, '{}', str_repeat('0', 64), $f['actor']]);
        };
        $count = fn () => (int)$this->db->pdo()->query('SELECT COUNT(*) FROM property_service_policy_versions WHERE supplier_id='.$f['supplier'])->fetchColumn();

        $sql = file_get_contents(dirname(__DIR__, 6).'/db/migrations/2117_property_service_policy_versions.sql');
        $statement = substr($sql, strpos($sql, 'INSERT IGNORE INTO property_service_policy_versions'));

        $raw($units[0], '2025-01-01', '2025-12-31', 'service_profit_and_loss');
        $raw($units[1], '2025-01-01', '2025-12-31', 'service_balance_sheet');
        $this->db->pdo()->exec($statement);
        self::assertSame(0, $count(), 'Inconsistent per-unit profiles must not become a policy');

        $this->db->pdo()->exec('SAVEPOINT consistent_history');
        $this->db->pdo()->prepare('DELETE FROM property_service_lifecycle_cases WHERE supplier_id=? AND unit_id=?')->execute([$f['supplier'], $units[1]]);
        $raw($units[1], '2024-01-01', '2024-12-31', 'service_profit_and_loss');
        $this->db->pdo()->exec($statement);
        $this->db->pdo()->exec($statement);
        $row = $this->db->pdo()->query('SELECT valid_from,policy,origin FROM property_service_policy_versions WHERE supplier_id='.$f['supplier'])->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['valid_from' => '2024-01-01', 'policy' => 'service_profit_and_loss', 'origin' => 'migrated']], $row);
        $this->db->pdo()->exec('ROLLBACK TO SAVEPOINT consistent_history');
    }
}
