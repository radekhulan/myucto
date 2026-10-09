<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Tax\Return\TaxReturnService;
use MyInvoice\Service\Tax\TaxConstants;
use MyInvoice\Service\TaxEvidence\TaxEstimateService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Odhad daně a pojistného OSVČ v daňové evidenci během roku. Konstanty roku 2025.
 *
 * Peněžní deník 2099: příjem 1 000 000, výdaj 300 000. Dodávka (skupina 2, rovnoměrně,
 * 200 000) zařazená 2098 s potvrzeným odpisem 22 000; odpis 2099 = 22,25 % = 44 500 zatím
 * nepotvrzený.
 *   skutečné výdaje: 300 000 + 44 500 = 344 500 → § 7 = 655 500
 *     daň 15 % = 98 325 − sleva 30 840 = 67 485
 *     sociální 55 % = 360 525 × 29,2 % = 105 273,30 → 105 274
 *     zdravotní 50 % = 327 750 × 13,5 % = 44 246,25 → 44 247
 *   paušál 60 %: 600 000 → § 7 = 400 000
 *     daň 60 000 − 30 840 = 29 160
 *     sociální 220 000 × 29,2 % = 64 240
 *     zdravotní minimum 279 342 × 13,5 % = 37 711,17 → 37 712
 */
#[Group('integration')]
final class TaxEstimateTest extends CashJournalTestCase
{
    private TaxEstimateService $estimates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimates = $this->container->get(TaxEstimateService::class);
        foreach ([self::YEAR - 1, self::YEAR] as $year) {
            $this->db->pdo()->prepare(
                'INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
            )->execute([$year, json_encode(TaxConstants::forYear(2025), JSON_THROW_ON_ERROR)]);
        }
        $this->db->pdo()->prepare("UPDATE supplier SET taxpayer_type = 'fo' WHERE id = ?")->execute([$this->supplierId]);
        $this->setVatPayer($this->supplierId, false);
        $this->container->get(TaxProfileRepository::class)->upsert($this->supplierId, self::YEAR, [
            'activity_rate' => 60, 'flat_tax_band' => 'none', 'use_actual_expenses' => true,
            'is_secondary' => false, 'activities' => [],
        ]);
        $this->cashDoc('in', 'sale', 1000000.0, [], null, self::YEAR . '-03-10');
        $this->cashDoc('out', 'purchase', 300000.0, [], null, self::YEAR . '-04-10');
    }

    public function testComparesActualAndFlatRateWithPendingDepreciation(): void
    {
        $this->vanInUse();

        $e = $this->estimates->estimate($this->supplierId, self::YEAR, self::YEAR . '-10-01');

        self::assertTrue($e['applicable']);
        self::assertSame('actual', $e['selected_mode']);
        self::assertSame(44500.0, $e['sources']['pending_depreciation']);
        self::assertSame(300000.0, $e['sources']['cash_journal_expenses']);

        $actual = $e['variants']['actual'];
        self::assertSame([344500.0, 655500.0, 67485.0], [$actual['expenses'], $actual['s7_base'], $actual['tax']]);
        self::assertSame([105274.0, 44247.0], [$actual['social']['insurance'], $actual['health']['insurance']]);

        $pausal = $e['variants']['pausal'];
        self::assertSame([600000.0, 400000.0, 29160.0], [$pausal['expenses'], $pausal['s7_base'], $pausal['tax']]);
        self::assertSame([64240.0, 37712.0], [$pausal['social']['insurance'], $pausal['health']['insurance']]);

        self::assertSame('pausal', $e['recommended_mode']);
        self::assertFalse($e['pausal']['cap_reached']);
    }

    /** Po potvrzení odpisů musí odhad dát stejnou daň a pojistné jako náhled přiznání a přehledů. */
    public function testMatchesDpfoReturnAndInsuranceOverviewOnceDepreciationIsConfirmed(): void
    {
        $this->vanInUse();
        $this->container->get(DepreciationPostingService::class)->bookYear($this->supplierId, self::YEAR, ['user_id' => $this->userId]);

        $e = $this->estimates->estimate($this->supplierId, self::YEAR);
        $preview = $this->container->get(TaxReturnService::class)->previewReadOnly($this->supplierId, self::YEAR, 'fo');
        $insurance = $this->container->get(TaxReturnService::class)->getInsurance($this->supplierId, self::YEAR, 'fo');

        self::assertSame(0.0, $e['sources']['pending_depreciation']);
        self::assertSame((float) $preview['result']['summary']['final_tax'], $e['variants']['actual']['tax']);
        self::assertSame((float) $preview['result']['s7']['base'], $e['variants']['actual']['s7_base']);
        self::assertSame((float) $insurance['social']['insurance'], $e['variants']['actual']['social']['insurance']);
        self::assertSame((float) $insurance['health']['insurance'], $e['variants']['actual']['health']['insurance']);
        self::assertSame(67485.0, $e['variants']['actual']['tax']);
    }

    public function testAdvancesAndSettlement(): void
    {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            "INSERT INTO tax_advance_schedules
                (supplier_id, taxpayer_type, advance_kind, period_year, seq_no, amount, due_date, status, paid_amount, paid_on, match_confidence, paid_source)
             VALUES (?, 'fo', ?, ?, ?, ?, ?, ?, ?, ?, 'exact', 'manual')"
        );
        for ($m = 1; $m <= 12; $m++) {
            $paid = $m <= 9;
            $insert->execute([$this->supplierId, 'social', self::YEAR, $m, 5000, sprintf('%04d-%02d-20', self::YEAR, $m),
                $paid ? 'paid' : 'planned', $paid ? 5000 : 0, $paid ? sprintf('%04d-%02d-20', self::YEAR, $m) : null]);
        }

        $e = $this->estimates->estimate($this->supplierId, self::YEAR, self::YEAR . '-10-01');

        self::assertSame(['paid' => 45000.0, 'source' => 'schedules', 'remaining' => 15000.0], $e['advances']['social']);
        // Pojistné bez odpisu (nezařazený majetek): § 7 = 700 000 → 385 000 × 29,2 % = 112 420.
        self::assertSame(112420.0, $e['variants']['actual']['social']['insurance']);
        self::assertSame(112420.0 - 45000.0 - 15000.0, $e['settlement']['social']);
    }

    public function testDoubleEntryCompanyIsRefusedByEndpoint(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $action = $this->container->get(\MyInvoice\Action\TaxEvidence\TaxEstimateAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/tax-evidence/tax-estimate?year=' . self::YEAR)
            ->withQueryParams(['year' => (string) self::YEAR])
            ->withAttribute(\MyInvoice\Middleware\SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $response = $action->get($request, (new \Slim\Psr7\Factory\ResponseFactory())->createResponse());

        self::assertSame(403, $response->getStatusCode());
    }

    private function vanInUse(): void
    {
        $assets = $this->container->get(AssetService::class);
        $created = $assets->create($this->supplierId, [
            'inventory_number' => 'DE-DODAVKA',
            'name' => 'Dodávka',
            'kind' => 'tangible',
            'asset_account_code' => '022',
            'input_price' => 200000,
            'acquisition_date' => (self::YEAR - 1) . '-03-01',
            'tax_method' => 'straight',
            'tax_group' => 2,
        ], ['user_id' => $this->userId]);
        $id = (int) $created['asset']['id'];
        $assets->putIntoUse($this->supplierId, $id, (self::YEAR - 1) . '-03-01', true, ['user_id' => $this->userId]);
        $this->container->get(DepreciationPostingService::class)->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
    }
}
