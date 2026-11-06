<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property\Services\Settlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Property\PropertyInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleEstimateIntegrityTest extends TestCase
{
    private ServiceLifecycleNativeTest $fixture;
    private Connection $db;
    protected function setUp(): void
    {
        $this->fixture = new ServiceLifecycleNativeTest('testNativeBalanceYearRolloverUnwindsExactEstimateAndFinalizes');
        $this->invoke('setUp');
        $this->db = (new \ReflectionProperty($this->fixture, 'db'))->getValue($this->fixture);
    }
    protected function tearDown(): void { $this->invoke('tearDown'); }
    private function invoke(string $method, mixed ...$args): mixed { return (new \ReflectionMethod($this->fixture, $method))->invoke($this->fixture, ...$args); }
    public static function profiles(): array
    {
        return [
            'native balance' => ['service_balance_sheet', false],
            'native profit and loss' => ['service_profit_and_loss', false],
            'opening balance' => ['service_balance_sheet', true],
            'opening profit and loss' => ['service_profit_and_loss', true],
        ];
    }
    private function data(string $policy, bool $opening): array { return $opening ? $this->invoke('openingBase', $policy, false) : $this->invoke('base', $policy); }
    private function assertRejected(array $data, string $code): void
    {
        try {
            $data['owner']->current($data['f']['supplier'], $data['case']);
            self::fail('An unfinalized lifecycle accepted changed estimate evidence.');
        } catch (\DomainException $error) {
            self::assertSame($code, $error->getMessage());
        }
    }
    #[DataProvider('profiles')]
    public function testUnfinalizedEstimateCannotUseRehashedAmount(string $policy, bool $opening): void
    {
        $data = $this->data($policy, $opening);
        $supplier = $data['f']['supplier'];
        $query = $this->db->pdo()->prepare("SELECT id,receipt_json FROM property_service_lifecycle_events WHERE supplier_id=? AND case_id=? AND event_key='estimate'");
        $query->execute([$supplier, $data['case']]);
        $event = $query->fetch(\PDO::FETCH_ASSOC);
        $receipt = json_decode($event['receipt_json'], true, flags: JSON_THROW_ON_ERROR);
        $receipt['amount_cents'] = '1';
        $hash = PropertyInput::hash($receipt);
        $this->db->pdo()->prepare('UPDATE property_service_lifecycle_events SET receipt_json=?,receipt_hash=? WHERE supplier_id=? AND id=?')->execute([json_encode($receipt, JSON_THROW_ON_ERROR), $hash, $supplier, $event['id']]);
        $this->assertRejected($data, 'settlement_lifecycle_estimate_coverage');
        $query->execute([$supplier, $data['case']]);
        self::assertSame($hash, PropertyInput::hash(json_decode($query->fetch(\PDO::FETCH_ASSOC)['receipt_json'], true, flags: JSON_THROW_ON_ERROR)));
    }
    #[DataProvider('profiles')]
    public function testUnfinalizedEstimateRequiresItsClaimBindings(string $policy, bool $opening): void
    {
        $data = $this->data($policy, $opening);
        $this->db->pdo()->prepare("DELETE FROM property_service_lifecycle_claims WHERE supplier_id=? AND claim_role='estimate'")->execute([$data['f']['supplier']]);
        $this->assertRejected($data, 'settlement_lifecycle_source_binding');
    }
    public function testUnfinalizedNativeEstimateRequiresCurrentOriginalLedger(): void
    {
        $data = $this->data('service_balance_sheet', false);
        $this->db->pdo()->prepare("UPDATE journal_entry_lines SET cost_center='SYNTHETIC-CHANGED' WHERE supplier_id=? AND entry_id=?")->execute([$data['f']['supplier'], $data['entries'][0]]);
        $this->assertRejected($data, 'settlement_lifecycle_source_changed');
    }
}
