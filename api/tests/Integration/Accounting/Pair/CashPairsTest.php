<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use PHPUnit\Framework\Attributes\Group;

/** Kritérium c: pokladní doklad s DPH = dvojice proti pokladně, součet = částka dokladu. */
#[Group('integration')]
#[Group('pairs-e2e')]
final class CashPairsTest extends PairsTestCase
{
    public function testCashSaleAndPurchaseArePairedAgainstCashAccount(): void
    {
        $register = $this->container->get(CashRegisterService::class)
            ->create($this->supplierId, ['name' => 'Pokladna dvojic', 'account_code' => '211', 'is_default' => true]);
        $service = $this->container->get(CashDocumentService::class);
        $doc = fn (array $over): array => array_merge([
            'register_id' => $register, 'issue_date' => self::YEAR . '-06-15', 'description' => 'Pokladní pohyb', 'post' => true,
        ], $over);

        $sale = $service->create($this->supplierId, $doc([
            'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => 1322.00, 'vat_mode' => 'vat', 'partner_dic' => 'CZ12345678',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00], ['vat_rate' => 12, 'base_amount' => 100.00, 'vat_amount' => 12.00]],
        ]), $this->userId);
        $pairs = $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $sale['journal_entry_id'], '1322.00');
        self::assertSame(['211'], array_values(array_unique(array_map(static fn (array $p): string => substr($p[0], 0, 3), $pairs))));

        $purchase = $service->create($this->supplierId, $doc([
            'purpose' => 'purchase', 'doc_type' => 'out', 'total_amount' => 1210.00, 'vat_mode' => 'vat',
            'vat_lines' => [['vat_rate' => 21, 'base_amount' => 1000.00, 'vat_amount' => 210.00]],
        ]), $this->userId);
        self::assertSame(
            [['501', '211', '1000.00'], ['343.100', '211', '210.00']],
            $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $purchase['journal_entry_id'], '1210.00'),
        );
    }
}
