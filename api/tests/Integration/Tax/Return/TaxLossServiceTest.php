<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\Return;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\Return\TaxLossService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Fáze E nález E2/E3 (§34 ZDP): evidence daňových ztrát + FIFO uplatnění.
 * Rollbackovaná transakce; soft-skip bez cfg.php / DB / migrace 1042.
 */
#[Group('integration')]
final class TaxLossServiceTest extends TestCase
{
    private Connection $db;
    private TaxLossService $service;
    private int $supplierId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->service = $c->get(TaxLossService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'tax_losses'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1042 (tax_losses) neproběhla.');
        }
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($czId === 0 || $currencyId === 0 || $vatRateId === 0) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, taxpayer_type)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, ?, ?, ?, "fo")'
        )->execute(['E2 ztráty', $czId, 'e2-loss@example.com', $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testRegisterFifoApplyAndRelease(): void
    {
        // 2022: vznik ztráty 100 000.
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2022, 100000.0, 0.0, null);
        $card2023 = $this->service->card($this->supplierId, 2023, 'fo');
        self::assertCount(1, $card2023['losses']);
        self::assertSame(100000.0, $card2023['losses'][0]['remaining']);
        self::assertSame(2027, $card2023['losses'][0]['expires_year'], 'Expirace = rok vzniku + 5.');
        self::assertTrue($card2023['losses'][0]['applicable']);
        self::assertSame(100000.0, $card2023['suggested']);

        // 2023: uplatní se 60 000 z ní.
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2023, 0.0, 60000.0, null);
        $card2024 = $this->service->card($this->supplierId, 2024, 'fo');
        self::assertSame(40000.0, $card2024['losses'][0]['remaining'], 'Zbývá 100k − 60k.');
        self::assertSame(40000.0, $card2024['suggested']);

        // Re-finalizace 2023 s jiným uplatněním (idempotence — nesčítá se).
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2023, 0.0, 30000.0, null);
        $card = $this->service->card($this->supplierId, 2024, 'fo');
        self::assertSame(70000.0, $card['losses'][0]['remaining'], 'Re-finalizace přepíše, nesčítá (100k − 30k).');

        // Reopen 2023 → uvolní uplatnění.
        $this->service->releaseReturn($this->supplierId, 'fo', 2023);
        $card = $this->service->card($this->supplierId, 2024, 'fo');
        self::assertSame(100000.0, $card['losses'][0]['remaining'], 'Po reopen je ztráta opět celá k dispozici.');
    }

    public function testFifoConsumesOldestFirstAndRespectsWindow(): void
    {
        // Ztráty: 2019 (50k, mimo 5leté okno pro 2025), 2021 (30k), 2022 (40k).
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2019, 50000.0, 0.0, null);
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2021, 30000.0, 0.0, null);
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2022, 40000.0, 0.0, null);

        // Pro 2025 je uplatnitelných jen 2021+2022 (2020..2024) = 70k; 2019 vypršelo.
        $card = $this->service->card($this->supplierId, 2025, 'fo');
        self::assertSame(70000.0, $card['suggested'], '2019 mimo 5leté okno.');

        // Uplatní 50k v 2025 → FIFO: celá 2021 (30k) + 20k z 2022.
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2025, 0.0, 50000.0, null);
        $byYear = [];
        foreach ($this->service->card($this->supplierId, 2026, 'fo')['losses'] as $l) {
            $byYear[$l['origin_year']] = $l['remaining'];
        }
        self::assertSame(0.0, $byYear[2021], '2021 vyčerpána první (FIFO).');
        self::assertSame(20000.0, $byYear[2022], 'Z 2022 zbývá 40k − 20k.');
        self::assertSame(50000.0, $byYear[2019], '2019 nedotčena.');
    }

    public function testLaterFinalizationReplacesLossAmount(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2023, 100000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2023, 140000.0, 0.0, 2);

        $card = $this->service->card($this->supplierId, 2024, 'po');
        self::assertSame(140000.0, $card['losses'][0]['amount']);
        self::assertSame(140000.0, $card['available_total']);
    }

    public function testLossCannotBeReducedBelowAlreadyAppliedAmount(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2022, 100000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2023, 0.0, 80000.0, 2);

        try {
            $this->service->reconcileFinalize($this->supplierId, 'po', 2022, 50000.0, 0.0, 3);
            self::fail('Snížení pod již uplatněných 80 000 Kč musí být blokováno.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('80 000,00', $e->getMessage());
        }

        $card = $this->service->card($this->supplierId, 2024, 'po');
        self::assertSame(100000.0, $card['losses'][0]['amount']);
        self::assertSame(20000.0, $card['losses'][0]['remaining']);
    }

    // ── zpětné uplatnění § 34 odst. 1 (novela 299/2020) ───────────────────────

    /**
     * Ztráta roku N jde odečíst i ve dvou PŘEDCHÁZEJÍCÍCH obdobích. Okno bylo natvrdo
     * `origin_year <= year - 1`, takže poplatník neměl jak snížit už zaplacenou daň —
     * a šlo o peníze navíc, které systém neuměl ani nabídnout.
     */
    public function testLossCanBeCarriedBackTwoYears(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 500000.0, 0.0, 1);

        $card = $this->service->card($this->supplierId, 2023, 'po');
        self::assertTrue($card['losses'][0]['carryback_applicable'], '2025 → 2023 jsou dvě období zpět.');
        self::assertSame(500000.0, $card['carryback_available_total']);
        self::assertSame(0.0, $card['available_total'], 'Dopředu se ztráta z budoucnosti uplatnit nedá.');
    }

    /** Třetí rok zpět už mimo okno — jinak by se odečetlo, co zákon nedovoluje. */
    public function testCarrybackWindowIsTwoYearsOnly(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 500000.0, 0.0, 1);

        $card = $this->service->card($this->supplierId, 2022, 'po');
        self::assertFalse($card['losses'][0]['carryback_applicable']);
        self::assertSame(0.0, $card['carryback_available_total']);
    }

    /** Zpětné uplatnění se zapíše jako běžná aplikace, jen s dřívějším rokem. */
    public function testCarrybackConsumesLoss(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 500000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2024, 0.0, 200000.0, 2);

        $card = $this->service->card($this->supplierId, 2023, 'po');
        self::assertSame(200000.0, $card['losses'][0]['applied']);
        self::assertSame(300000.0, $card['losses'][0]['remaining']);
    }

    /**
     * Souhrnný strop 30 mil. Kč na jednotlivou ztrátu. Bez něj by se odečetla celá ztráta
     * a přiznání by prošlo s částkou, kterou zákon nepřipouští.
     */
    public function testCarrybackIsCappedAtThirtyMillion(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 50000000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2024, 0.0, 50000000.0, 2);

        $card = $this->service->card($this->supplierId, 2023, 'po');
        self::assertSame(30000000.0, $card['losses'][0]['applied'], 'Zpětně nejvýše 30 mil. Kč.');
        self::assertSame(20000000.0, $card['losses'][0]['remaining'], 'Zbytek zůstává k dopřednému uplatnění.');
    }

    /** Strop platí na SOUHRN obou zpětných období, ne na každé zvlášť. */
    public function testCarrybackCapIsAggregateAcrossBothYears(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 50000000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2024, 0.0, 25000000.0, 2);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2023, 0.0, 20000000.0, 3);

        $card = $this->service->card($this->supplierId, 2022, 'po');
        self::assertSame(30000000.0, $card['losses'][0]['applied'],
            '25 mil. + jen 5 mil. zbytku stropu, ne 45 mil.');
    }

    /** Zpětné uplatnění strop dopředného uplatnění NEsnižuje — jsou to jiné režimy. */
    public function testForwardApplicationIsNotLimitedByCarrybackCap(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 50000000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2024, 0.0, 30000000.0, 2);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2026, 0.0, 20000000.0, 3);

        $card = $this->service->card($this->supplierId, 2027, 'po');
        self::assertSame(50000000.0, $card['losses'][0]['applied']);
        self::assertSame(0.0, $card['losses'][0]['remaining']);
    }

    /** Starší dopředu přenášená ztráta má přednost před zpětným uplatněním té budoucí. */
    public function testForwardLossIsConsumedBeforeFutureLoss(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'po', 2023, 100000.0, 0.0, 1);
        $this->service->reconcileFinalize($this->supplierId, 'po', 2026, 400000.0, 0.0, 2);

        // Rok 2025: k dispozici ztráta 2023 (dopředu) i 2026 (zpětně). Uplatní se 250 000.
        $this->service->reconcileFinalize($this->supplierId, 'po', 2025, 0.0, 250000.0, 3);

        $byYear = array_column($this->service->card($this->supplierId, 2027, 'po')['losses'], null, 'origin_year');
        self::assertSame(100000.0, $byYear[2023]['applied'], 'Nejstarší ztráta se spotřebuje celá.');
        self::assertSame(150000.0, $byYear[2026]['applied'], 'Zbytek doplní zpětné uplatnění.');
    }

    public function testPartialReconcileFailurePreservesExistingApplicationInsideCallerTransaction(): void
    {
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2022, 100000, 0, null);
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2023, 100000, 30000, null);
        $this->service->reconcileFinalize($this->supplierId, 'fo', 2024, 0, 160000, null);
        $q = $this->db->pdo()->prepare('SELECT * FROM tax_loss_applications WHERE supplier_id=? ORDER BY id');
        $q->execute([$this->supplierId]); $before = $q->fetchAll(\PDO::FETCH_ASSOC);
        try { $this->service->reconcileFinalize($this->supplierId, 'fo', 2023, 50000, 0, null); self::fail('Used origin loss cannot be reduced.'); }
        catch (\DomainException) {}
        $q->execute([$this->supplierId]); self::assertSame($before, $q->fetchAll(\PDO::FETCH_ASSOC));
        self::assertTrue($this->db->pdo()->inTransaction());
    }

    public function testExactFoCalendarAllocationNeedsNoDoubleEntryAccountingPeriods(): void
    {
        $this->service->registerOpeningLoss($this->supplierId, 'fo', 2024, 100000);
        $return = (new \MyInvoice\Repository\TaxReturnRepository($this->db))->create($this->supplierId, 2025, 'fo', [], null);
        $plan = $this->service->allocationPlan($this->supplierId, 'fo', 2025, 60000, null, (int) $return['id']);
        $this->service->reconcileExact($plan, 0);
        self::assertSame('6000000', $plan['allocated_cents']);
        $this->service->assertPersistedPlan($plan);
        self::assertSame(40000.0, $this->service->card($this->supplierId, 2026, 'fo')['available_total']);
    }
}
