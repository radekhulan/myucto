<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Repository\DepreciationEntryRepository;
use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Accounting\Assets\AssetException;
use MyInvoice\Service\Accounting\Assets\AssetService;
use MyInvoice\Service\Accounting\Assets\DepreciationPostingService;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use MyInvoice\Service\Tax\Return\DpfoReturnDataProvider;
use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\Attributes\Group;

/**
 * Majetek a daňové odpisy v daňové evidenci (§ 7b ZDP). Firma nemá účtovou osnovu ani
 * deník: karta je evidence pro daňové odpisy (§ 26 až 33 ZDP), zařazení i vyřazení se
 * nikam neúčtují a odpisy i daňová zůstatková cena prodaného majetku vstupují do výdajů
 * § 7 přiznání DPFO, ne do peněžního deníku.
 *
 * Výpočet (skupina 2, rovnoměrně § 31, vstupní cena 200 000 Kč, první odpisovatel ne):
 *   rok 1 (2098): 11 % = 22 000
 *   rok 2 (2099): prodej → polovina ročního odpisu (§ 26 odst. 7): 22,25 % / 2 = 22 250
 *   daňová ZC při prodeji: 200 000 − 22 000 − 22 250 = 155 750 (§ 24 odst. 2 písm. b)
 */
#[Group('integration')]
final class AssetTaxEvidenceTest extends CashJournalTestCase
{
    private AssetService $assets;
    private DepreciationPostingService $depreciation;
    private DepreciationEntryRepository $entries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assets = $this->container->get(AssetService::class);
        $this->depreciation = $this->container->get(DepreciationPostingService::class);
        $this->entries = $this->container->get(DepreciationEntryRepository::class);
        foreach ([self::YEAR - 1, self::YEAR] as $year) {
            $this->db->pdo()->prepare(
                'INSERT INTO tax_constants (year, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
            )->execute([$year, json_encode(TaxConstants::forYear(2025), JSON_THROW_ON_ERROR)]);
        }
    }

    public function testCardLifecycleWithoutChartOfAccountsAndJournal(): void
    {
        self::assertSame(0, $this->rowCount('chart_of_accounts'), 'Předpoklad: firma v daňové evidenci nemá osnovu.');

        $assetId = $this->carInUse();
        $booked = $this->depreciation->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
        self::assertSame([1, 0.0, 22000.0, []], [$booked['booked'], $booked['total_accounting'], $booked['total_tax'], $booked['errors']]);
        self::assertNull($this->entries->findYear($assetId, 'accounting', self::YEAR - 1), 'Účetní odpis se v daňové evidenci nevede.');

        $result = $this->assets->dispose($this->supplierId, $assetId,
            ['date' => self::YEAR . '-06-30', 'type' => 'sold', 'price' => 150000], ['user_id' => $this->userId]);
        self::assertSame('disposed', $result['asset']['status']);
        $tax = $this->entries->findYear($assetId, 'tax', self::YEAR);
        self::assertNotNull($tax);
        self::assertSame([22250.0, true, 155750.0], [(float) $tax['amount'], (bool) $tax['is_half'], (float) $tax['residual_value_end']]);
        self::assertSame(0, $this->rowCount('journal_entries'), 'Daňová evidence nemá deník.');

        $rows = (new DisposalResiduals($this->db))->forPeriod($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31')['rows'];
        self::assertSame([155750.0, 'full'], [$rows[0]['tax_residual_value'], DisposalResiduals::deductibility($rows[0]['disposal_type'])]);

        $reverted = $this->assets->revertDisposal($this->supplierId, $assetId, ['user_id' => $this->userId]);
        self::assertSame('in_use', $reverted['asset']['status']);
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR), 'Odpis dopočtený k vyřazení se vrátil.');
    }

    public function testFinalAnnualClosingLocksDisposal(): void
    {
        $assetId = $this->carInUse();
        $this->depreciation->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, ?, 'final')"
        )->execute([$this->supplierId, self::YEAR]);

        try {
            $this->assets->dispose($this->supplierId, $assetId, ['date' => self::YEAR . '-06-30', 'type' => 'liquidated'], []);
            self::fail('Rok s dokončenou roční uzávěrkou se nesmí měnit.');
        } catch (AssetException $e) {
            self::assertSame('closing_final', $e->errorCode);
        }
    }

    /**
     * Hromadné potvrzení odpisů, přerušení odpisu a technické zhodnocení mění daňové odpisy
     * roku, ze kterých je dokončená uzávěrka. Musí proto respektovat její zámek stejně jako
     * vyřazení a ruční přepis.
     */
    public function testFinalAnnualClosingLocksBookingPauseAndImprovement(): void
    {
        $assetId = $this->carInUse();
        $this->depreciation->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
        $improvement = $this->assets->addImprovement($this->supplierId, $assetId,
            ['completed_on' => self::YEAR . '-02-01', 'amount' => 90000], ['user_id' => $this->userId]);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, ?, 'final')"
        )->execute([$this->supplierId, self::YEAR]);

        $attempts = [
            'bookYear' => fn () => $this->depreciation->bookYear($this->supplierId, self::YEAR, ['user_id' => $this->userId]),
            'pauseYear' => fn () => $this->assets->pauseYear($this->supplierId, $assetId, self::YEAR),
            'addImprovement' => fn () => $this->assets->addImprovement($this->supplierId, $assetId,
                ['completed_on' => self::YEAR . '-05-01', 'amount' => 95000], ['user_id' => $this->userId]),
            'deleteImprovement' => fn () => $this->assets->deleteImprovement($this->supplierId, $assetId,
                (int) $improvement['improvement']['id']),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail($name . ': rok s dokončenou roční uzávěrkou se nesmí měnit.');
            } catch (AssetException $e) {
                self::assertSame('closing_final', $e->errorCode, $name);
            }
        }
        self::assertNull($this->entries->findYear($assetId, 'tax', self::YEAR), 'Odpis roku nevznikl.');
    }

    public function testFinalAnnualClosingLocksUnpause(): void
    {
        $assetId = $this->carInUse();
        $this->depreciation->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
        $this->assets->pauseYear($this->supplierId, $assetId, self::YEAR);
        $this->db->pdo()->prepare(
            "INSERT INTO tax_evidence_closings (supplier_id, year, status) VALUES (?, ?, 'final')"
        )->execute([$this->supplierId, self::YEAR]);

        try {
            $this->assets->unpauseYear($this->supplierId, $assetId, self::YEAR);
            self::fail('Přerušení v roce s dokončenou uzávěrkou se nesmí zrušit.');
        } catch (AssetException $e) {
            self::assertSame('closing_final', $e->errorCode);
        }
        self::assertTrue((bool) $this->entries->findYear($assetId, 'tax', self::YEAR)['is_paused']);
    }

    /** Odpisy roku i daňová ZC prodaného majetku jsou výdajem § 7, ZC jen u prodeje a likvidace. */
    public function testDpfoExpensesIncludeTaxResidualOfSoldAsset(): void
    {
        $this->setVatPayer($this->supplierId, false);
        $this->container->get(TaxProfileRepository::class)->upsert($this->supplierId, self::YEAR, [
            'activity_rate' => 60, 'flat_tax_band' => 'none', 'use_actual_expenses' => true,
            'is_secondary' => false, 'activities' => [],
        ]);
        $this->cashDoc('in', 'sale', 500000.0, [], null, self::YEAR . '-03-10');
        $this->cashDoc('out', 'purchase', 100000.0, [], null, self::YEAR . '-04-10');

        $sold = $this->carInUse('DE-AUTO-1');
        $donated = $this->carInUse('DE-AUTO-2');
        $this->depreciation->bookYear($this->supplierId, self::YEAR - 1, ['user_id' => $this->userId]);
        $this->assets->dispose($this->supplierId, $sold, ['date' => self::YEAR . '-06-30', 'type' => 'sold'], []);
        $this->assets->dispose($this->supplierId, $donated, ['date' => self::YEAR . '-06-30', 'type' => 'donated'], []);

        $data = $this->container->get(DpfoReturnDataProvider::class)->gather($this->supplierId, self::YEAR);

        // 100 000 výdaj z deníku + 2 × 22 250 odpis roku vyřazení + 155 750 ZC prodaného auta.
        // ZC darovaného auta výdajem není (§ 25 ZDP).
        self::assertEqualsWithDelta(300250.0, $data['s7_expenses'], 0.001);
    }

    private function carInUse(string $number = 'DE-AUTO'): int
    {
        $created = $this->assets->create($this->supplierId, [
            'inventory_number' => $number,
            'name' => 'Dodávka',
            'kind' => 'tangible',
            'asset_account_code' => '022',
            'input_price' => 200000,
            'acquisition_date' => (self::YEAR - 1) . '-03-01',
            'tax_method' => 'straight',
            'tax_group' => 2,
        ], ['user_id' => $this->userId]);
        $id = (int) $created['asset']['id'];
        $this->assets->putIntoUse($this->supplierId, $id, (self::YEAR - 1) . '-03-01', true, ['user_id' => $this->userId]);
        return $id;
    }

    private function rowCount(string $table): int
    {
        $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?");
        $stmt->execute([$this->supplierId]);
        return (int) $stmt->fetchColumn();
    }
}
