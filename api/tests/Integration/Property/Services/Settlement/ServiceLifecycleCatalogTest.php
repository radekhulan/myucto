<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Services\Settlement\SettlementReadRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleCatalogTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;
    private \DI\Container $container;

    protected function setUp(): void
    {
        $this->fixture = new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        $this->invoke('setUp');
        $this->db = (new \ReflectionProperty($this->fixture, 'db'))->getValue($this->fixture);
        $this->container = (new \ReflectionProperty($this->fixture, 'container'))->getValue($this->fixture);
    }

    protected function tearDown(): void { $this->invoke('tearDown'); }
    private function invoke(string $method, mixed ...$args): mixed { return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$args); }

    public static function profiles(): array
    {
        return ['balance' => ['service_balance_sheet'], 'profit and loss' => ['service_profit_and_loss']];
    }

    private function assertCanonicalReceiptRequired(array $data, array $args): void
    {
        $pdo = $this->db->pdo();
        $supplier = $data['f']['supplier'];
        $query = $pdo->prepare("SELECT id,receipt_json FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND event_key='final'");
        $query->execute([$supplier, $data['case']]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        $receipt = json_decode($row['receipt_json'], true, flags: JSON_THROW_ON_ERROR);
        $receipt['amount_cents'] = '1';
        $pdo->exec('SAVEPOINT catalog_receipt_tamper');
        try {
            $pdo->prepare('UPDATE property_service_lifecycle_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($receipt, JSON_THROW_ON_ERROR), \MyInvoice\Service\Property\PropertyInput::hash($receipt), $supplier, $row['id']]);
            try {
                $this->container->get(SettlementReadRepository::class)->catalog(...$args);
                self::fail('Catalog trusted a rehashed receipt without canonical financial proof.');
            } catch (\DomainException $error) {
                self::assertSame('settlement_lifecycle_coverage_changed', $error->getMessage());
            }
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT catalog_receipt_tamper');
            $pdo->exec('RELEASE SAVEPOINT catalog_receipt_tamper');
        }
    }

    public static function openingModes(): array { return ['native' => [false], 'opening' => [true]]; }

    #[DataProvider('openingModes')]
    public function testBalanceFinalCannotBeUsedBeforeItsAccountingDate(bool $opening): void
    {
        $d = $opening ? $this->invoke('openingBase', 'service_balance_sheet', false) : $this->invoke('base', 'service_balance_sheet');
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        $d['owner']->unwind($f['supplier'], $d['case'], '2027-04-02', $f['actor']);
        $final = $d['owner']->finalize($f['supplier'], $d['case'], $invoice['invoice'], $invoice['line']['id'], '2027-04-02', 'Synthetic final after invoice', $f['actor']);
        $read = $this->container->get(SettlementReadRepository::class);
        $args = [$f['supplier'], $opening ? 'opening' : 'costs', $f['units']['U02'], $f['service']['id'], '2027-04-01', 0, 1];
        if (!$opening) { $args[] = 0; $args[] = $d['selector']['id']; }
        self::assertSame([], $read->catalog(...$args)['items']);
        $selector = [...$d['selector'], 'cost_line_id' => $final['cost_line_id']];
        try {
            $this->container->get(\MyInvoice\Service\Property\Services\Settlement\SettlementOriginAdapter::class)->current($f['supplier'], $f['units']['U02'], $f['service']['id'], '2027-04-01', [$selector]);
            self::fail('A settlement used final coverage before its accounting date.');
        } catch (\DomainException $error) {
            self::assertSame('settlement_lifecycle_final_after_settlement', $error->getMessage());
        }
    }

    #[DataProvider('profiles')]
    public function testIndependentManualCostCannotHideCanonicalFinalCandidate(string $policy): void
    {
        $d = $this->invoke('base', $policy);
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        $d['owner']->unwind($f['supplier'], $d['case'], '2027-04-01', $f['actor']);
        $final = $d['owner']->finalize($f['supplier'], $d['case'], $invoice['invoice'], $invoice['line']['id'], '2027-04-01', 'Synthetic final alongside manual cost', $f['actor']);
        $lines = [];
        foreach ([['315.210', 'debit'], ['321', 'credit']] as [$account, $side]) {
            $lines[] = ['account_code' => $account, 'side' => $side, 'amount' => '50.00', 'dimensions' => $d['dims'], 'dimension_origins' => array_fill_keys(array_keys($d['dims']), ['origin' => 'explicit', 'ref' => null])];
        }
        $this->container->get(\MyInvoice\Service\Accounting\PostingService::class)->postDocument($f['supplier'], 'manual', null, $lines, ['entry_date' => '2027-04-01', 'posted_by' => $f['actor']]);
        $page = $this->container->get(SettlementReadRepository::class)->catalog($f['supplier'], 'costs', $f['units']['U02'], $f['service']['id'], '2027-04-01', 0, 1, 0, $d['selector']['id']);
        self::assertCount(1, $page['items']);
        self::assertSame($final['cost_line_id'], $page['items'][0]['cost_source_id']);
    }

    #[DataProvider('profiles')]
    public function testNativeFinalCostIsDiscoverableThroughOwnerVerifiedCatalog(string $policy): void
    {
        $d = $this->invoke('base', $policy);
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        $read = $this->container->get(SettlementReadRepository::class);
        $args = [$f['supplier'], 'costs', $f['units']['U02'], $f['service']['id'], '2027-04-01', 0, 1, 0, $d['selector']['id']];
        self::assertSame([], $read->catalog(...$args)['items']);
        $d['owner']->unwind($f['supplier'], $d['case'], '2027-04-01', $f['actor']);
        $final = $d['owner']->finalize($f['supplier'], $d['case'], $invoice['invoice'], $invoice['line']['id'], '2027-04-01', 'Synthetic proved allocation', $f['actor']);
        $page = $read->catalog(...$args);
        self::assertCount(1, $page['items']);
        self::assertSame($final['cost_line_id'], $page['items'][0]['cost_source_id']);
        self::assertSame($final['cost_line_id'], $page['items'][0]['selector']['cost_line_id']);
        self::assertSame('1120000', $page['items'][0]['cost_cents']);
        $this->assertCanonicalReceiptRequired($d, $args);
        $this->invoke('applySettlement', $d, $page['items'][0]['cost_source_id'], '0', false);
        self::assertSame([], $read->catalog(...$args)['items']);
    }

    #[DataProvider('profiles')]
    public function testOpeningAccrualRequiresFinalProofAndPreservesExactCostSelector(string $policy): void
    {
        $d = $this->invoke('openingBase', $policy, false);
        $f = $d['f'];
        $invoice = $this->invoke('invoice', $d);
        $read = $this->container->get(SettlementReadRepository::class);
        $args = [$f['supplier'], 'opening', $f['units']['U02'], $f['service']['id'], '2027-04-01', 0, 1];
        self::assertSame([], $read->catalog(...$args)['items']);
        $d['owner']->unwind($f['supplier'], $d['case'], '2027-04-01', $f['actor']);
        $final = $d['owner']->finalize($f['supplier'], $d['case'], $invoice['invoice'], $invoice['line']['id'], '2027-04-01', 'Synthetic proved opening allocation', $f['actor']);
        $page = $read->catalog(...$args);
        self::assertCount(1, $page['items']);
        self::assertSame($d['selector'] + ['cost_line_id' => $final['cost_line_id']], $page['items'][0]['selector']);
        self::assertSame($final['cost_line_id'], $page['items'][0]['cost_source_id']);
        self::assertSame('11200000', $page['items'][0]['cost_cents']);
        $this->assertCanonicalReceiptRequired($d, $args);
        $this->invoke('applySettlement', $d, $page['items'][0]['cost_source_id'], $d['paid'], false);
        self::assertSame([], $read->catalog(...$args)['items']);
    }
}
