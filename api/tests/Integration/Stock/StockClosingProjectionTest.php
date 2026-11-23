<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Stock;

use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Repository\TaxConstantsRepository;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Tax\Return\DppoReturnCalculator;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Nezaúčtovaný krok „Zásoby" (způsob B) je v projekci uzávěrky náhledu DPPO; po jeho
 * zaúčtování už je ve VH, projekce ho nesmí přičíst znovu a projektovaný VH se nemění.
 */
#[Group('integration')]
final class StockClosingProjectionTest extends StockTestCase
{
    private const YEAR = 2097;

    public function testUnpostedStockClosingIsProjectedOnceAndNotAfterPosting(): void
    {
        /** @var ClosingService $closing */
        $closing = $this->container->get(ClosingService::class);
        /** @var AccountingPeriodRepository $periods */
        $periods = $this->container->get(AccountingPeriodRepository::class);
        /** @var DppoReturnDataProvider $dppo */
        $dppo = $this->container->get(DppoReturnDataProvider::class);

        $supplierId = $this->createSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $whId = $this->warehouse($supplierId);
        $periodId = $periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->receiveStock($supplierId, $whId, $this->item($supplierId, 'MAT-P', 'material'), '10.000', 10.0, self::YEAR . '-03-01');
        $this->receiveStock($supplierId, $whId, $this->item($supplierId, 'GDS-P', 'goods'), '5.000', 20.0, self::YEAR . '-03-02');

        $before = $dppo->gather($supplierId, self::YEAR)['closing_projection'];
        $items = array_column($before['items'], null, 'key');
        self::assertArrayHasKey('stock_closing', $items);
        self::assertSame([200.0, 1, false], [$items['stock_closing']['amount'], $items['stock_closing']['sign'], $items['stock_closing']['optional']]);
        self::assertTrue($before['is_projection']);

        $meta = ['user_id' => $this->userId, 'posted_by' => $this->userId];
        $closing->start($supplierId, $periodId, (int) $periods->findById($supplierId, $periodId)['row_version'], $meta);
        $closing->runStockValuation($supplierId, $periodId, (int) $periods->findById($supplierId, $periodId)['row_version'], $meta);

        $after = $dppo->gather($supplierId, self::YEAR)['closing_projection'];
        self::assertArrayNotHasKey('stock_closing', array_column($after['items'], null, 'key'), 'Zaúčtovaný krok se znovu neprojektuje.');
        self::assertSame(round($before['vh_posted'] + 200.0, 2), $after['vh_posted'], 'Po zaúčtování je stav zásob ve VH právě jednou.');
        self::assertSame($before['vh_projected'], $after['vh_projected']);
    }

    /**
     * Návrh daně v kroku Daň z příjmů u firmy (500) počítá se stejnou projekcí uzávěrky
     * jako náhled DPPO. Krok Zásoby jde až po dani, takže bez projekce by průvodce
     * navrhl daň bez dopadu nezaúčtovaných zásob a rozešel se s náhledem přiznání.
     */
    public function testIncomeTaxSuggestionIncludesPendingStockLikeDppoPreview(): void
    {
        /** @var ClosingService $closing */
        $closing = $this->container->get(ClosingService::class);
        /** @var AccountingPeriodRepository $periods */
        $periods = $this->container->get(AccountingPeriodRepository::class);
        /** @var DppoReturnDataProvider $dppo */
        $dppo = $this->container->get(DppoReturnDataProvider::class);

        $supplierId = $this->createSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $whId = $this->warehouse($supplierId);
        $periodId = $periods->create($supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
        $this->receiveStock($supplierId, $whId, $this->item($supplierId, 'GDS-T', 'goods'), '50.000', 1000.0, self::YEAR . '-03-02');
        $this->container->get(PostingService::class)->postDocument($supplierId, 'manual', null, [
            ['account_code' => '311', 'side' => 'debit', 'amount' => 300000.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 300000.0],
        ], ['entry_date' => self::YEAR . '-06-30', 'posted_by' => $this->userId, 'user_id' => $this->userId]);

        $data = $dppo->gather($supplierId, self::YEAR);
        $result = $this->container->get(DppoReturnCalculator::class)
            ->compute($data, [], $this->container->get(TaxConstantsRepository::class)->forYear(self::YEAR));
        self::assertTrue($result['projection']['is_projection'] ?? false);
        $projectedTax = round((float) $result['projection']['projected_tax'], 2);
        self::assertGreaterThan((float) $result['tax'], $projectedTax, 'Nezaúčtované zásoby zvyšují projektovaný VH.');

        $preview = $closing->incomeTaxPreview($supplierId, $periodId);
        self::assertSame('computed_from_ledger', $preview['suggested_source']);
        self::assertSame($projectedTax, round((float) $preview['suggested_amount'], 2), 'Průvodce ukazuje stejnou daň jako náhled DPPO.');
    }
}
