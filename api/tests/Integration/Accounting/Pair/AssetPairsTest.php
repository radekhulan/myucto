<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting\Pair;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use PHPUnit\Framework\Attributes\Group;

/** Majetek: zařazení, odpis a vyřazení po dvojicích (vyřazení 54x/08x a 08x/02x). */
#[Group('integration')]
#[Group('pairs-e2e')]
final class AssetPairsTest extends PairsTestCase
{
    public function testAssetLifecycleIsPaired(): void
    {
        $this->container->get(AccountingPeriodRepository::class)
            ->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $assets = $this->container->get(AssetService::class);
        $journal = $this->container->get(JournalEntryRepository::class);
        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];

        $assetId = (int) $assets->create($this->supplierId, [
            'inventory_number' => 'DV-001', 'name' => 'Stroj dvojic', 'input_price' => 120000.00,
            'acquisition_date' => self::YEAR . '-01-10', 'tax_method' => 'straight', 'tax_group' => 2, 'acc_useful_life_months' => 60,
        ], ['user_id' => $this->userId])['asset']['id'];
        $assets->putIntoUse($this->supplierId, $assetId, self::YEAR . '-01-20', true, $meta);
        $use = $journal->findBySource($this->supplierId, 'asset', $assetId);
        self::assertSame([['022', '042', '120000.00']], $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $use['id'], '120000.00'));

        $this->container->get(DepreciationPostingService::class)->bookYear($this->supplierId, self::YEAR, ['posted_by' => $this->userId]);
        $accounting = $this->container->get(DepreciationEntryRepository::class)->findYear($assetId, 'accounting', self::YEAR);
        $depreciation = $journal->findBySource($this->supplierId, 'depreciation', (int) $accounting['id']);
        $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $depreciation['id']);

        $assets->dispose($this->supplierId, $assetId, ['date' => (self::YEAR + 1) . '-03-15', 'type' => 'liquidated'], $meta);
        $disposal = $journal->findBySource($this->supplierId, 'asset_disposal', $assetId);
        $pairs = $this->assertEntryPaired($this->db->pdo(), $this->supplierId, (int) $disposal['id']);
        self::assertCount(2, $pairs);
        self::assertSame(['082', '022', '120000.00'], $pairs[1]);
        self::assertSame('082', $pairs[0][1]);
    }
}
