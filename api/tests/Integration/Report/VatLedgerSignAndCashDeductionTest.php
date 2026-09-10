<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Audit VAT klasifikací 2026-08, nálezy C-1 a C-2 — dvě díry v evidenci DPH, které
 * měly přijaté doklady zavřené a vydané / pokladní ne:
 *
 *   C-1 `VatLedgerService::fetchCash()` vracel natvrdo `'full' AS vat_deduction`,
 *       takže výdajový pokladní doklad označený jako bez nároku na odpočet
 *       (reprezentace, osobní spotřeba) prošel jako plný odpočet na ř. 40/41 + KH B.2.
 *       Tentýž doklad v podobě přijaté faktury `fetchPurchases()` korektně vyloučí.
 *
 *   C-2 `fetchSales()` neměl znaménkovou normalizaci dobropisu, kterou přijatá strana
 *       má. Opravný daňový doklad s kladnými částkami (import z cizího systému —
 *       InvoiceAmountPolicy ho nezakazuje) daň na výstupu místo snížení NAVÝŠIL.
 *
 * Přibyly sem i nálezy C-3 (cizí sazba se nesmí přes fallback stát českým odpočtem)
 * a M-1 (řádek přiznání se řídí skutečnou sazbou, ne ručně zvoleným kódem) — všechny
 * čtou tentýž ledger, takže dávají smysl v jednom souboru.
 *
 * Izolovaný rok 2093 pod existujícím supplierem (vynucen plátce), úklid v tearDown.
 */
#[Group('integration')]
final class VatLedgerSignAndCashDeductionTest extends TestCase
{
    private const YEAR = 2093;

    private Connection $db;
    private DphPriznaniBuilder $dph;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $registerId = 0;

    /** @var int[] */
    private array $clientIds = [];
    /** @var int[] */
    private array $invoiceIds = [];
    /** @var int[] */
    private array $cashIds = [];
    /** @var int[] */
    private array $purchaseIds = [];
    private bool $ownRegister = false;
    private ?array $origVatFlags = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db  = $container->get(Connection::class);
            $this->dph = $container->get(DphPriznaniBuilder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        // Sazba 21 % — pokladní řádky nesou sazbu jako číslo, faktury přes vat_rate_id.
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates WHERE ABS(rate_percent - 21) < 0.5 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->registerId = $this->ensureCashRegister();

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0
            || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $flags = $pdo->query("SELECT is_vat_payer, is_identified FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(\PDO::FETCH_ASSOC) ?: [];
        $this->origVatFlags = $flags;
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1, is_identified = 0 WHERE id = ?')
            ->execute([$this->supplierId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->origVatFlags !== null && $this->supplierId > 0) {
            $pdo->prepare('UPDATE supplier SET is_vat_payer = ?, is_identified = ? WHERE id = ?')
                ->execute([
                    (int) ($this->origVatFlags['is_vat_payer'] ?? 1),
                    (int) ($this->origVatFlags['is_identified'] ?? 0),
                    $this->supplierId,
                ]);
        }
        foreach ($this->invoiceIds as $id) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->cashIds as $id) {
            $pdo->prepare('DELETE FROM cash_document_vat_lines WHERE cash_document_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM cash_documents WHERE id = ?')->execute([$id]);
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        if ($this->ownRegister && $this->registerId > 0) {
            $pdo->prepare('DELETE FROM cash_registers WHERE id = ?')->execute([$this->registerId]);
        }
        $this->db->close();
    }

    /**
     * C-2: vydaný dobropis uložený s KLADNÝMI částkami musí daň snížit, ne navýšit.
     * Bez dokladové normalizace vyjde ř. 1 jako 10 000 / 2 100 místo 6 000 / 1 260.
     */
    public function testIssuedCreditNoteWithPositiveAmountsReducesOutputTax(): void
    {
        $cust = $this->client('Odběratel dobropis', 'CZ90000200');
        $this->sale('S-CN-1', $cust, 'invoice', $this->d(4, 5), [[10000, 2100, 21]]);
        // Dobropis na 4 000 Kč uložený kladně (typicky import z cizího systému).
        $this->sale('S-CN-2', $cust, 'credit_note', $this->d(4, 20), [[4000, 840, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 4, 'monthly');
        $line1 = $result['summary']['lines']['1'];

        $this->assertSame(6000.0, (float) $line1['base'], 'dobropis musí základ SNÍŽIT (10 000 − 4 000)');
        $this->assertSame(1260.0, (float) $line1['vat'], 'dobropis musí daň SNÍŽIT (2 100 − 840)');
    }

    /** C-2 regrese: správně uložený (záporný) dobropis se chová beze změny. */
    public function testIssuedCreditNoteStoredNegativeIsUnchanged(): void
    {
        $cust = $this->client('Odběratel dobropis záporný', 'CZ90000219');
        $this->sale('S-CN-3', $cust, 'invoice', $this->d(5, 5), [[10000, 2100, 21]]);
        $this->sale('S-CN-4', $cust, 'credit_note', $this->d(5, 20), [[-4000, -840, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 5, 'monthly');
        $line1 = $result['summary']['lines']['1'];

        $this->assertSame(6000.0, (float) $line1['base']);
        $this->assertSame(1260.0, (float) $line1['vat']);
    }

    /**
     * C-2: opravný doklad legitimně nese řádky obou znamének (vrácené zboží záporně,
     * proti tomu kladný storno poplatek). Normalizuje se CELÝ DOKLAD jedním znaménkem
     * podle jeho součtu — per-položkové −ABS() by kladný řádek otočilo.
     */
    public function testCreditNoteKeepsInternalSignRatio(): void
    {
        $cust = $this->client('Odběratel smíšený dobropis', 'CZ90000227');
        // Součet dokladu je kladný (5 000 − 1 000 = 4 000) → normalizuje se celý na −4 000.
        $this->sale('S-CN-5', $cust, 'credit_note', $this->d(6, 10), [[5000, 1050, 21], [-1000, -210, 21]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 6, 'monthly');
        $line1 = $result['summary']['lines']['1'];

        $this->assertSame(-4000.0, (float) $line1['base'], 'vnitřní poměr znamének zůstává zachován');
        $this->assertSame(-840.0, (float) $line1['vat']);
    }

    /**
     * C-1: výdajový pokladní doklad označený jako bez nároku na odpočet nesmí do evidence.
     * Kontrolní doklad s plným nárokem ve stejném období dokládá, že filtr netrefuje všechno.
     */
    public function testCashOutgoingWithoutDeductionRightIsExcluded(): void
    {
        $this->cash('out', $this->d(7, 3), 12100, [[21.0, 10000, 2100, 'full']]);
        $this->cash('out', $this->d(7, 4), 6050, [[21.0, 5000, 1050, 'none']]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 7, 'monthly');
        $line40 = $result['summary']['lines']['40'];

        $this->assertSame(10000.0, (float) $line40['base'], 'doklad bez nároku nesmí přidat základ');
        $this->assertSame(2100.0, (float) $line40['vat'], 'doklad bez nároku nesmí přidat odpočet');
    }

    /**
     * C-1: rozsah nároku na odpočet se u pokladny týká jen VÝDAJE (přijaté plnění).
     * Příjmový doklad je tržba — daň na výstupu se neškrtá bez ohledu na hodnotu sloupce.
     */
    public function testCashIncomingIsNotAffectedByDeductionColumn(): void
    {
        $this->cash('in', $this->d(8, 3), 12100, [[21.0, 10000, 2100, 'none']]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 8, 'monthly');
        $line1 = $result['summary']['lines']['1'];

        $this->assertSame(10000.0, (float) $line1['base'], 'tržba se nesmí ztratit');
        $this->assertSame(2100.0, (float) $line1['vat']);
    }

    /**
     * L-4: hotovostní pořízení dlouhodobého majetku patří i na ř. 47 přiznání
     * (hodnota pořízeného majetku — doplňující údaj k odpočtu, migrace 1784).
     *
     * `fetchCash()` měl v SELECTu natvrdo `0 AS is_fixed_asset`, takže stroj koupený
     * za hotové skončil jen v odpočtu na ř. 40 a ř. 47 zůstal prázdný — u přijaté
     * faktury přitom tentýž nákup ř. 47 naplní.
     */
    public function testCashFixedAssetPurchaseReachesLine47(): void
    {
        if (!$this->db->hasColumn('cash_document_vat_lines', 'is_fixed_asset')) {
            $this->markTestSkipped('Migrace 1784 na téhle DB neproběhla.');
        }
        $this->cash('out', $this->d(11, 3), 121000, [[21.0, 100000, 21000, 'full', 100.0, true]]);
        // Běžný nákup ve stejném období — ř. 47 sumuje JEN majetkovou část základu.
        $this->cash('out', $this->d(11, 4), 12100, [[21.0, 10000, 2100, 'full']]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 11, 'monthly');
        $lines = $result['summary']['lines'];

        self::assertArrayHasKey('47', $lines, 'Hotovostní pořízení majetku se na ř. 47 vůbec nedostalo.');
        self::assertSame(100000.0, (float) $lines['47']['base'], 'Ř. 47 nese jen hodnotu majetku.');
        self::assertSame(110000.0, (float) $lines['40']['base'], 'Odpočet na ř. 40 zůstává za oba doklady.');
    }

    /**
     * L-4: příznak majetku má smysl jen u výdaje. U příjmového dokladu je to tržba,
     * ne pořízení — ř. 47 (doplňující údaj k ODPOČTU) by z ní vzniknout nesměl.
     */
    public function testCashIncomingNeverReachesLine47(): void
    {
        if (!$this->db->hasColumn('cash_document_vat_lines', 'is_fixed_asset')) {
            $this->markTestSkipped('Migrace 1784 na téhle DB neproběhla.');
        }
        $this->cash('in', $this->d(12, 3), 121000, [[21.0, 100000, 21000, 'full', 100.0, true]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');

        self::assertArrayNotHasKey('47', $result['summary']['lines'], 'Tržba nesmí naplnit ř. 47.');
    }

    /** C-1: § 75 poměrný odpočet u pokladny — daň se krátí zadaným procentem. */
    public function testCashProportionalDeductionIsApplied(): void
    {
        $this->cash('out', $this->d(9, 3), 12100, [[21.0, 10000, 2100, 'proportional', 70.0]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 9, 'monthly');
        $line40 = $result['summary']['lines']['40'];

        $this->assertSame(7000.0, (float) $line40['base'], 'základ krácen na 70 %');
        $this->assertSame(1470.0, (float) $line40['vat'], 'odpočet krácen na 70 %');
    }

    /**
     * C-3: sazba, kterou český číselník nezná (DE 19 %), se nesmí přes SQL fallback
     * proměnit v tuzemský odpočet. Fallback dřív bral všechno mezi 0 a základní sazbou
     * jako '41' → ř. 41 + KH B.3, tedy ODPOČET NĚMECKÉ DANĚ. SSOT tohle pásmo vědomě
     * nemapuje a fallback ho nesmí přebít.
     */
    public function testForeignRateWithoutClassificationDoesNotBecomeDomesticDeduction(): void
    {
        $vendor = $this->client('Německý dodavatel', 'DE123456789');
        // Řádek bez klasifikace se sazbou 19 % (snapshot je autoritativní pro výkazy).
        $this->purchaseWithRateSnapshot('P-DE-19', $vendor, $this->d(10, 5), 10000, 1900, 19.0);

        $result = $this->dph->build($this->supplierId, self::YEAR, 10, 'monthly');

        // Řádek do evidence vůbec nevstoupí, takže klíč v souhrnu klidně chybí.
        $this->assertSame(0.0, (float) ($result['summary']['lines']['41']['vat'] ?? 0), 'cizí daň nesmí na ř. 41');
        $this->assertSame(0.0, (float) ($result['summary']['lines']['40']['vat'] ?? 0), 'ani na ř. 40');
    }

    /** Kontrolní protipól: česká snížená sazba fallbackem projde dál. */
    public function testDomesticReducedRateStillFallsBackToLine41(): void
    {
        $vendor = $this->client('Tuzemský dodavatel 12 %', 'CZ29200002');
        $this->purchaseWithRateSnapshot('P-CZ-12', $vendor, $this->d(11, 5), 10000, 1200, 12.0);

        $result = $this->dph->build($this->supplierId, self::YEAR, 11, 'monthly');

        $this->assertSame(1200.0, (float) ($result['summary']['lines']['41']['vat'] ?? 0));
    }

    /**
     * M-1: kód pro základní sazbu na 12% řádku (překlep v editoru, import s pevným kódem)
     * nesmí poslat základ na ř. 1, zatímco KH ho podle skutečné sazby dá do 12% sloupce.
     * Rozhoduje sazba na řádku — tu daň dodavatel skutečně účtoval.
     */
    public function testLineFollowsActualRateNotTheChosenCode(): void
    {
        $cust = $this->client('Odběratel překlep', 'CZ90000235');
        // Kód '1' (základní sazba) na položce se sazbou 12 %.
        $this->sale('S-RATE-MIX', $cust, 'invoice', $this->d(12, 3), [[10000, 1200, 12]]);

        $result = $this->dph->build($this->supplierId, self::YEAR, 12, 'monthly');

        $this->assertSame(10000.0, (float) ($result['summary']['lines']['2']['base'] ?? 0), 'základ patří na ř. 2');
        $this->assertSame(1200.0, (float) ($result['summary']['lines']['2']['vat'] ?? 0));
        $this->assertSame(0.0, (float) ($result['summary']['lines']['1']['base'] ?? 0), 'ř. 1 zůstane prázdný');
    }

    /**
     * H-4: platební kalendář se přizná JEDNORÁZOVĚ celý, ačkoli § 31a ZDPH dělá
     * z každé splátky samostatné zdanitelné plnění (daň ke dni splatnosti nebo přijetí
     * úplaty, co nastane dřív). Rozpad do období systém zatím neumí a vyloučit doklad
     * z evidence by daň zatajilo — jediná poctivá odpověď je HLASITÉ varování.
     * Bez něj doklad vypadá jako běžná faktura a nesoulad KH s odběratelem se pozná
     * až z výzvy finančního úřadu.
     */
    public function testPaymentCalendarSpanningPeriodsIsWarnedAbout(): void
    {
        $cust = $this->client('Odběratel kalendář', 'CZ90000236');
        $id = $this->sale('S-KALENDAR', $cust, 'payment_calendar', $this->d(2, 1), [[120000, 25200, 21]]);
        // 12 měsíčních splátek od února — 11 z nich má splatnost mimo únor.
        $this->paymentSchedule($id, $this->d(2, 1), 12, 10000.0, 2100.0);

        $result = $this->dph->build($this->supplierId, self::YEAR, 2, 'monthly');
        $warnings = implode("\n", $result['warnings']);

        self::assertStringContainsString('S-KALENDAR', $warnings, 'Kalendář přes víc období musí být vidět.');
        self::assertStringContainsString('§ 31a', $warnings);
        // Čísla se NEMĚNÍ — varování daň nesmí ani přidat, ani ubrat.
        self::assertSame(120000.0, (float) ($result['summary']['lines']['1']['base'] ?? 0));
    }

    /** Jednorázový kalendář uvnitř období je v pořádku — kontrola nesmí křičet zbytečně. */
    public function testSingleInstalmentCalendarInsidePeriodIsNotWarnedAbout(): void
    {
        $cust = $this->client('Odběratel jednorázový', 'CZ90000237');
        $id = $this->sale('S-KAL-1', $cust, 'payment_calendar', $this->d(3, 1), [[10000, 2100, 21]]);
        $this->paymentSchedule($id, $this->d(3, 20), 1, 10000.0, 2100.0);

        $result = $this->dph->build($this->supplierId, self::YEAR, 3, 'monthly');

        self::assertStringNotContainsString('S-KAL-1', implode("\n", $result['warnings']));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Pokladna tenanta; když žádná není, založí testovací (úklid v tearDown). */
    private function ensureCashRegister(): int
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$this->supplierId]);
        $existing = (int) ($stmt->fetchColumn() ?: 0);
        if ($existing > 0) {
            return $existing;
        }
        $pdo->prepare(
            'INSERT INTO cash_registers (supplier_id, name, currency_code, account_code, is_default, is_active)
             VALUES (?, "Testovací pokladna", "CZK", "211999", 0, 1)'
        )->execute([$this->supplierId]);
        $this->ownRegister = true;
        return (int) $pdo->lastInsertId();
    }

    /**
     * Přijatá faktura s jedním řádkem BEZ klasifikace a s daným snapshotem sazby.
     * `vat_rate_id` míří na 21% sazbu (FK), rozhoduje ale `vat_rate_snapshot` — přesně
     * tak vzniká doklad s cizí sazbou po importu, který ji neuměl namapovat.
     */
    private function purchaseWithRateSnapshot(
        string $number,
        int $vendorId,
        string $date,
        float $base,
        float $vat,
        float $rateSnapshot,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1, 0, "{}", ?, ?, ?, "received", NULL, "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $vendorId, $number, $date, $date, $date, $date,
            $this->currencyId, $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->purchaseIds[] = $id;

        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, 0, NULL)'
        )->execute([$id, $base, $this->vatRateId, $rateSnapshot, $base, $vat, $base + $vat]);
    }

    private function d(int $month, int $day): string
    {
        return sprintf('%04d-%02d-%02d', self::YEAR, $month, $day);
    }

    private function client(string $name, string $dic): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->clientIds[] = $id;
        return $id;
    }

    /**
     * @param list<array{0:float,1:float,2:float}> $items [base, vat, vat_rate_snapshot]
     */
    private function sale(string $varsymbol, int $clientId, string $type, string $date, array $items): int
    {
        $base = 0.0;
        $vat  = 0.0;
        foreach ($items as $it) {
            $base += $it[0];
            $vat  += $it[1];
        }
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, "issued", "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, $varsymbol, $type, $clientId, $date, $date, $date,
            $this->currencyId, $base, $vat, $base + $vat, $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->invoiceIds[] = $id;

        $itemStmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Test položka", 1, "ks", ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $i => $it) {
            [$itemBase, $itemVat, $snapshot] = $it;
            $itemStmt->execute([$id, $itemBase, $this->vatRateId, $snapshot, $itemBase, $itemVat, $itemBase + $itemVat, $i]);
        }

        return $id;
    }

    /**
     * Rozpis plateb platebního kalendáře (§ 31a) — splátky po měsících od `$firstDue`.
     * Mazání jde kaskádou přes `invoices` (FK ON DELETE CASCADE), stejně jako u položek.
     */
    private function paymentSchedule(int $invoiceId, string $firstDue, int $count, float $base, float $vat): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_payment_schedule
                (supplier_id, invoice_id, due_on, base_amount, vat_amount, total_amount, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $due = new \DateTimeImmutable($firstDue);
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                $this->supplierId,
                $invoiceId,
                $due->modify('+' . $i . ' month')->format('Y-m-d'),
                $base,
                $vat,
                $base + $vat,
                $i,
            ]);
        }
    }

    /**
     * Pokladní doklad se sazbovými řádky.
     *
     * @param list<array{0:float,1:float,2:float,3:string,4?:float,5?:bool}> $lines
     *        [vat_rate, base, vat, vat_deduction, vat_deduction_percent, is_fixed_asset]
     */
    private function cash(string $docType, string $date, float $total, array $lines): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO cash_documents
                (supplier_id, register_id, doc_type, purpose, doc_number, issue_date, tax_date,
                 partner_name, description, vat_mode, total_amount, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, "Protistrana", "Test pokladní doklad", "vat", ?, "posted", ?)'
        );
        $stmt->execute([
            $this->supplierId,
            $this->registerId,
            $docType,
            $docType === 'in' ? 'sale' : 'purchase',
            'T' . self::YEAR . substr($date, 5, 2) . substr($date, 8, 2) . $docType,
            $date,
            $date,
            $total,
            $this->userId,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->cashIds[] = $id;

        $lineStmt = $this->db->pdo()->prepare(
            'INSERT INTO cash_document_vat_lines
                (cash_document_id, vat_rate, base_amount, vat_amount, vat_deduction,
                 vat_deduction_percent, is_fixed_asset)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $lineStmt->execute([$id, $l[0], $l[1], $l[2], $l[3], $l[4] ?? 100.0, !empty($l[5]) ? 1 : 0]);
        }
    }
}
