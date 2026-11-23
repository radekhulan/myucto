<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tax\BadDebt;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Tax\BadDebt\Section46Service;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 46 až § 46g ZDPH — oprava základu daně u nedobytné pohledávky (VĚŘITEL).
 *
 * Matice DPH vedla věřitelskou stranu jako CHYBÍ (nález N-021) a platilo to doslova:
 * existoval jen ručně nastavitelný příznak `kh_bad_debt='P'` do KH A.4, zatímco řádek 33
 * `opr_verit` neměl v `$lineMap` klíč. Šlo tedy podat kontrolní hlášení s příznakem opravy,
 * aniž by v přiznání vznikla jakákoli částka — a nic na ten rozpor neupozornilo.
 *
 * Testy chrání tři věci, kvůli kterým to není jen zrcadlo § 74b:
 *   1. oprava se NEODVOZUJE z aging — je právem věřitele vázaným na právní skutečnost
 *      (insolvence, exekuce, smrt, likvidace) a na doručení opravného dokladu (§ 46f),
 *   2. jediný důvod s početně ověřitelnými podmínkami je malá nedobytná pohledávka
 *      (§ 46 odst. 1 písm. f) — limit, lhůta i roční strop na dlužníka se kontrolují,
 *   3. obnova po úhradě (§ 46e) automatická JE, protože plyne jen z evidovaných úhrad.
 *
 * Izolace: vše v transakci s rollbackem.
 */
#[Group('integration')]
final class Section46ServiceTest extends TestCase
{
    private Connection $db;
    private Section46Service $service;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $czId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db      = $c->get(Connection::class);
            $this->service = $c->get(Section46Service::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        if ($pdo->query("SHOW TABLES LIKE 'vat_s46_corrections'")->fetch() === false) {
            $this->markTestSkipped('Migrace 1150 neproběhla.');
        }
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Dlužník §46", "Test 1", "Praha", "11000", ?, "CZ46464646", "d@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $this->czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    // ── zadání opravy ─────────────────────────────────────────────────────────

    /** Základní případ: neuhrazená pohledávka, insolvence dlužníka, doručený opravný doklad. */
    public function testRegisterCorrectionOnUnpaidInvoice(): void
    {
        $id = $this->seed('2025001', '2025-01-15', vat: 2100.0, withVat: 12100.0);

        $res = $this->service->registerCorrection(
            $this->supplierId, $id, 'insolvency', '2025-09-20', 'OD-2025001', null, $this->userId
        );

        self::assertSame(2100.0, $res['vat_amount'], 'Celá daň — pohledávka je neuhrazená v plné výši.');
        self::assertSame(['year' => 2025, 'month' => 9], $res['period'],
            'Období určuje DORUČENÍ opravného dokladu (§ 46f), ne splatnost.');
    }

    /** Částečná úhrada → opravit lze jen neuhrazený podíl. */
    public function testCorrectionIsProportionalToUnpaidPart(): void
    {
        // Uhrazeno 3 025 Kč z 12 100 → neuhrazeno 75 % → 1 575 Kč z 2 100 Kč daně.
        $id = $this->seed('2025002', '2025-01-15', vat: 2100.0, withVat: 12100.0, paid: 3025.0);

        $res = $this->service->registerCorrection(
            $this->supplierId, $id, 'execution', '2025-09-20', null, null, $this->userId
        );

        self::assertEqualsWithDelta(1575.0, $res['vat_amount'], 0.01);
    }

    /** Uhrazená pohledávka nedobytná není. */
    public function testPaidInvoiceCannotBeCorrected(): void
    {
        $id = $this->seed('2025003', '2025-01-15', vat: 2100.0, withVat: 12100.0, paid: 12100.0);

        $this->expectExceptionMessageMatches('/uhrazená/i');
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);
    }

    /**
     * Reverse-charge: daň odvedl odběratel, věřitel nemá co opravovat. Bez téhle pojistky
     * by oprava vygenerovala zápornou daň na výstupu, která nikdy nevznikla.
     */
    public function testReverseChargeInvoiceIsRejected(): void
    {
        $id = $this->seed('2025004', '2025-01-15', vat: 0.0, withVat: 10000.0, reverseCharge: true);

        $this->expectExceptionMessageMatches('/Reverse-charge|daň na výstupu/i');
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);
    }

    /** Dvojí oprava téže pohledávky se nesmí sečíst nad rámec dlužné daně. */
    public function testSecondCorrectionOfSameInvoiceIsRejected(): void
    {
        $id = $this->seed('2025005', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $this->expectExceptionMessageMatches('/už je zaevidovaná/i');
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-10-20', null, null, $this->userId);
    }

    /** Neznámý právní důvod se odmítne — ledger nesmí nést nedoložitelnou hodnotu. */
    public function testUnknownLegalGroundIsRejected(): void
    {
        $id = $this->seed('2025006', '2025-01-15', vat: 2100.0, withVat: 12100.0);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->registerCorrection($this->supplierId, $id, 'protoze_chci', '2025-09-20', null, null, $this->userId);
    }

    // ── malá nedobytná pohledávka (§ 46 odst. 1 písm. f) ──────────────────────

    /** Nad 10 000 Kč včetně daně to malá pohledávka není. */
    public function testSmallReceivableOverLimitIsRejected(): void
    {
        $id = $this->seed('2025007', '2025-01-15', vat: 2100.0, withVat: 12100.0);

        $this->expectExceptionMessageMatches('/nejvýše/i');
        $this->service->registerCorrection($this->supplierId, $id, 'small_receivable', '2025-09-20', null, null, $this->userId);
    }

    /** Do šesti měsíců po splatnosti nárok ještě nevznikl. */
    public function testSmallReceivableBeforeSixMonthsIsRejected(): void
    {
        $id = $this->seed('2025008', '2025-01-15', vat: 1050.0, withVat: 6050.0);

        // Splatnost 15. 1. 2025 → nejdříve 15. 7. 2025; 30. 6. je brzy.
        $this->expectExceptionMessageMatches('/aspoň 6 měsíců|nejdříve/i');
        $this->service->registerCorrection($this->supplierId, $id, 'small_receivable', '2025-06-30', null, null, $this->userId);
    }

    /** Po šesti měsících a pod limitem projde. */
    public function testSmallReceivableWithinLimitsPasses(): void
    {
        $id = $this->seed('2025009', '2025-01-15', vat: 1050.0, withVat: 6050.0);

        $res = $this->service->registerCorrection(
            $this->supplierId, $id, 'small_receivable', '2025-07-20', null, null, $this->userId
        );

        self::assertSame(1050.0, $res['vat_amount']);
    }

    /**
     * Roční strop 20 000 Kč na dlužníka — třetí pohledávka po 8 000 Kč už nesmí projít.
     * Tohle je jediná podmínka § 46, kterou nelze ověřit z jednoho dokladu; musí se
     * sčítat přes ledger, jinak by strop nefungoval.
     */
    public function testSmallReceivableDebtorYearCapIsEnforced(): void
    {
        foreach (['2025010', '2025011'] as $i => $num) {
            $id = $this->seed($num, '2025-01-15', vat: 1388.43, withVat: 8000.0);
            $this->service->registerCorrection(
                $this->supplierId, $id, 'small_receivable', '2025-08-' . (10 + $i), null, null, $this->userId
            );
        }

        $third = $this->seed('2025012', '2025-01-15', vat: 1388.43, withVat: 8000.0);

        $this->expectExceptionMessageMatches('/Roční strop/i');
        $this->service->registerCorrection($this->supplierId, $third, 'small_receivable', '2025-08-20', null, null, $this->userId);
    }

    /**
     * Od 1. 1. 2027 (zák. č. 180/2026 Sb.) je malá pohledávka do 20 000 Kč a stačí
     * 3 měsíce po splatnosti. O znění rozhoduje rok opravy (§ 33 bod 2 téhož zákona),
     * takže 12 100 Kč tři měsíce po splatnosti projde v lednu 2027, ale ne v prosinci 2026.
     */
    public function testSmallReceivableFollows2027LimitsByCorrectionYear(): void
    {
        $id = $this->seed('2026101', '2026-09-15', vat: 2100.0, withVat: 12100.0);

        try {
            $this->service->registerCorrection(
                $this->supplierId, $id, 'small_receivable', '2026-12-20', null, null, $this->userId
            );
            self::fail('V roce 2026 platí limit 10 000 Kč a lhůta 6 měsíců.');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression('/nejvýše 10 000/u', $e->getMessage());
        }

        $res = $this->service->registerCorrection(
            $this->supplierId, $id, 'small_receivable', '2027-01-20', null, null, $this->userId
        );

        self::assertSame(2100.0, $res['vat_amount']);
    }

    // ── obnova po úhradě (§ 46e) ─────────────────────────────────────────────

    /** Bez úhrady není co obnovovat. */
    public function testNoRestorationWithoutPayment(): void
    {
        $id = $this->seed('2025013', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $preview = $this->service->previewRestorations($this->supplierId, 2025, 10);

        self::assertSame([], $preview['rows']);
        self::assertSame(0.0, $preview['total']);
    }

    /** Úhrada po opravě vrací daň zpět ve stejném poměru — automaticky, z evidovaných úhrad. */
    public function testPaymentAfterCorrectionProducesRestoration(): void
    {
        $id = $this->seed('2025014', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $this->pay($id, 6050.0); // polovina → obnova poloviny opravené daně

        $preview = $this->service->previewRestorations($this->supplierId, 2025, 10);

        self::assertCount(1, $preview['rows']);
        self::assertEqualsWithDelta(1050.0, $preview['total'], 0.01);
        self::assertSame('restoration', $preview['rows'][0]['movement']);
    }

    /** Úplná úhrada vrátí celou opravu; zaevidování vynuluje čistý stav. */
    public function testFullPaymentRestoresEntireCorrection(): void
    {
        $id = $this->seed('2025015', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $this->pay($id, 12100.0);
        $res = $this->service->recordRestorations($this->supplierId, 2025, 10, $this->userId);

        self::assertSame(1, $res['recorded']);
        self::assertEqualsWithDelta(2100.0, $res['total'], 0.01);

        // Po zaevidování už není co obnovovat — netting je v nule.
        self::assertSame([], $this->service->previewRestorations($this->supplierId, 2025, 11)['rows']);
    }

    // ── promítnutí do výkazů ─────────────────────────────────────────────────

    /**
     * Znaménka pro DPHDP3: oprava snižuje daň na výstupu (ř. 1 základ i daň ZÁPORNĚ)
     * a informativní ř. 33 `opr_verit` nese KLADNOU hodnotu — přesně jak říká anotace XSD
     * („věřitel uvede kladnou hodnotu opravy daně").
     */
    public function testCorrectionLinesCarryMirroredSigns(): void
    {
        $id = $this->seed('2025016', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->seedItem($id, rate: 21.0, base: 10000.0, vat: 2100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2025, 9);

        self::assertEqualsWithDelta(-10000.0, $lines['basic']['base'], 0.01);
        self::assertEqualsWithDelta(-2100.0, $lines['basic']['vat'], 0.01);
        self::assertEqualsWithDelta(2100.0, $lines['opr_verit'], 0.01);
        self::assertSame(0.0, $lines['reduced']['vat']);
        self::assertCount(1, $lines['invoices']);
        self::assertSame('correction', $lines['invoices'][0]['movement']);
    }

    /** Obnova má opačná znaménka — jinak by se oprava nikdy nevrátila zpět. */
    public function testRestorationLinesCarryOppositeSigns(): void
    {
        $id = $this->seed('2025017', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->seedItem($id, rate: 21.0, base: 10000.0, vat: 2100.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);
        $this->pay($id, 12100.0);
        $this->service->recordRestorations($this->supplierId, 2025, 10, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2025, 10);

        self::assertEqualsWithDelta(10000.0, $lines['basic']['base'], 0.01);
        self::assertEqualsWithDelta(2100.0, $lines['basic']['vat'], 0.01);
        self::assertEqualsWithDelta(-2100.0, $lines['opr_verit'], 0.01);
        self::assertSame('restoration', $lines['invoices'][0]['movement']);
    }

    /** Snížená sazba míří na ř. 2, ne na ř. 1 — rozpad podle položek dokladu. */
    public function testReducedRateLandsOnSecondLine(): void
    {
        $id = $this->seed('2025018', '2025-01-15', vat: 1200.0, withVat: 11200.0);
        $this->seedItem($id, rate: 12.0, base: 10000.0, vat: 1200.0);
        $this->service->registerCorrection($this->supplierId, $id, 'insolvency', '2025-09-20', null, null, $this->userId);

        $lines = $this->service->periodCorrectionLines($this->supplierId, 2025, 9);

        self::assertSame(0.0, $lines['basic']['vat']);
        self::assertEqualsWithDelta(-1200.0, $lines['reduced']['vat'], 0.01);
        self::assertEqualsWithDelta(-10000.0, $lines['reduced']['base'], 0.01);
    }

    /** Kvartální období sečte tři měsíce — jinak by čtvrtletní plátce opravu ztratil. */
    public function testQuarterlyPeriodAggregatesThreeMonths(): void
    {
        $a = $this->seed('2025019', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->seedItem($a, rate: 21.0, base: 10000.0, vat: 2100.0);
        $b = $this->seed('2025020', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->seedItem($b, rate: 21.0, base: 10000.0, vat: 2100.0);

        $this->service->registerCorrection($this->supplierId, $a, 'insolvency', '2025-07-10', null, null, $this->userId);
        $this->service->registerCorrection($this->supplierId, $b, 'insolvency', '2025-09-10', null, null, $this->userId);

        $q3 = $this->service->periodCorrectionLines($this->supplierId, 2025, 8, 'quarterly');

        self::assertEqualsWithDelta(4200.0, $q3['opr_verit'], 0.01);
        self::assertCount(2, $q3['invoices']);
    }

    // ── pracovní seznam ──────────────────────────────────────────────────────

    /**
     * Seznam kandidátů je POMŮCKA, ne nárok: obsahuje neuhrazené pohledávky po splatnosti
     * bez ohledu na právní důvod, protože ten systém ověřit neumí.
     */
    public function testCandidatesListUnpaidOverdueOnly(): void
    {
        $unpaid = $this->seed('2025021', '2025-01-15', vat: 2100.0, withVat: 12100.0);
        $this->seed('2025022', '2025-01-15', vat: 2100.0, withVat: 12100.0, paid: 12100.0);
        $this->seed('2025023', '2027-01-15', vat: 2100.0, withVat: 12100.0);

        $ids = array_column($this->service->previewCandidates($this->supplierId, '2025-06-30'), 'invoice_id');

        self::assertContains($unpaid, $ids);
        self::assertCount(1, $ids, 'Uhrazená ani nesplatná pohledávka do seznamu nepatří.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function seed(
        string $varsymbol,
        string $dueDate,
        float $vat,
        float $withVat,
        float $paid = 0.0,
        bool $reverseCharge = false,
    ): int {
        $base = round($withVat - $vat, 2);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, varsymbol, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, paid_total, status, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, "{}", "{}", ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->clientId, $varsymbol, $dueDate, $dueDate, $dueDate,
            $this->currencyId, $reverseCharge ? 1 : 0, $base, $vat, $withVat, $paid,
            $paid >= $withVat ? 'paid' : 'issued', $this->userId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    private function seedItem(int $invoiceId, float $rate, float $base, float $vat): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM vat_rates WHERE rate_percent = ? AND country = 'CZ' ORDER BY id LIMIT 1");
        $stmt->execute([$rate]);
        $rateId = (int) ($stmt->fetchColumn() ?: 0);
        if ($rateId === 0) {
            self::markTestSkipped('V číselníku chybí sazba ' . $rate . ' %.');
        }

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Plnění", 1, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$invoiceId, $base, $rateId, $rate, $base, $vat, $base + $vat]);
    }

    /** Úhrada vydané faktury — `paid_total` je SSOT uhrazené částky. */
    private function pay(int $invoiceId, float $amount): void
    {
        // Status se odvozuje z hodnoty PŘED navýšením: MariaDB vyhodnocuje přiřazení
        // v UPDATE zleva doprava, takže `paid_total` v druhém výrazu už nese novou částku.
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET status = IF(paid_total + ? >= total_with_vat, "paid", status),
                    paid_total = paid_total + ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([$amount, $amount, $invoiceId, $this->supplierId]);
    }
}
