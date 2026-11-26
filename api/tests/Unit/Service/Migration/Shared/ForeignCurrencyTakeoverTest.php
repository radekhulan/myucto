<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\Shared;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use PDO;
use PHPUnit\Framework\TestCase;

final class ForeignCurrencyTakeoverTest extends TestCase
{
    private PDO $pdo;
    private ForeignCurrencyTakeover $takeover;

    protected function setUp(): void
    {
        $this->pdo = new \Pdo\Sqlite('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INT, code TEXT, label TEXT, symbol TEXT, name_cs TEXT, name_en TEXT,
            decimals INT, is_active INT, is_default INT)');
        $this->pdo->exec("INSERT INTO currencies (id, supplier_id, code, is_default) VALUES (1, 5, 'CZK', 1), (2, 5, 'EUR', 0), (3, 6, 'USD', 0)");
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        $this->takeover = new ForeignCurrencyTakeover($db);
    }

    public function testDocumentInCrownsIsNotForeign(): void
    {
        $d = $this->takeover->decide(5, 'CZK', 1.0, 1.0, [['base' => 100.0, 'vat' => 21.0]], 121.0);
        self::assertFalse($d->isForeignDocument());
        self::assertFalse($d->inForeignCurrency());
        self::assertNull($d->note());
        self::assertFalse($this->takeover->decide(5, '', 0.0, 1.0, [], 0.0)->isForeignDocument());
    }

    public function testExactConversionIsTakenInForeignCurrency(): void
    {
        // 100 EUR × 25,125 = 2 512,50; 21 EUR × 25,125 = 527,625 → 527,63 (HALF_UP obě cesty).
        $items = [['base' => 2512.50, 'vat' => 527.63, 'foreign_base' => 100.0, 'foreign_vat' => 21.0]];
        $d = $this->takeover->decide(5, 'eur', 25.125, 1.0, $items, 3040.13);
        self::assertTrue($d->inForeignCurrency());
        self::assertSame(2, $d->currencyId);
        self::assertSame(25.125, $d->rate);
        self::assertSame('doklad v EUR, převzat v měně dokladu kurzem 25,125 Kč', $d->note());
    }

    public function testRatePerHundredUnitsIsPerUnit(): void
    {
        self::assertSame(0.0752, ForeignCurrencyTakeover::rate(7.52, 100.0));
        self::assertNull(ForeignCurrencyTakeover::rate(0.0, 1.0));
        self::assertNull(ForeignCurrencyTakeover::rate(25.0, 0.0));
        $items = [['base' => 752.0, 'vat' => 0.0, 'foreign_base' => 10000.0, 'foreign_vat' => 0.0]];
        self::assertNull(ForeignCurrencyTakeover::check(ForeignCurrencyTakeover::rate(7.52, 100.0), $items, 752.0));
    }

    public function testIssuedDocumentWithSmallDifferenceKeepsSourceCrownsAndOriginalCurrency(): void
    {
        $amounts = [
            'items' => [['base' => 251.2, 'vat' => 52.76, 'foreign_base' => 10.0, 'foreign_vat' => 2.1]],
            'base' => 251.2, 'vat' => 52.76, 'total' => 303.96, 'rounding' => 0.0,
        ];
        self::assertFalse($this->takeover->decide(5, 'EUR', 25.12, 1.0, $amounts['items'], $amounts['total'])->inForeignCurrency());
        $issued = $this->takeover->decideIssued(5, 'EUR', 25.12, 1.0, $amounts, 12.10);
        self::assertSame(2, $issued->currencyId);
        self::assertSame(25.12, $issued->rate);
        self::assertSame(12.10, $amounts['total']);
        self::assertSame(52.76, $amounts['items'][0]['home_vat']);
        self::assertSame(303.96, $amounts['snapshot']['import_total_with_vat_czk']);
    }

    public function testItemThatDoesNotConvertToSourceCrownsKeepsDocumentInCrowns(): void
    {
        // Zdroj spočítal daň z korunového základu (2 512,50 × 21 % = 527,63) - to sedí; ale
        // základ 2 512,49 kurzem nevyjde.
        $items = [['base' => 2512.49, 'vat' => 527.63, 'foreign_base' => 100.0, 'foreign_vat' => 21.0]];
        $d = $this->takeover->decide(5, 'EUR', 25.125, 1.0, $items, 3040.12);
        self::assertFalse($d->inForeignCurrency());
        self::assertTrue($d->isForeignDocument());
        self::assertStringContainsString('základ 1. položky 100,00 × kurz 25,125 nedává 2 512,49 Kč ze zdroje', (string) $d->reason);
        self::assertStringStartsWith('doklad v EUR, převzat v Kč (základ 1. položky', (string) $d->note());
    }

    public function testBothApplicationRoundingsMustGiveSourceCrowns(): void
    {
        // 3,00 × 25,305 = 75,915: bcmath HALF_UP dá 75,92, float round() evidence DPH 75,91.
        // Kdyby pojistka hlídala jen jednu cestu, evidence DPH by se od zdroje lišila o haléř.
        $items = [['base' => 75.92, 'vat' => 0.0, 'foreign_base' => 3.0, 'foreign_vat' => 0.0]];
        self::assertNotNull(ForeignCurrencyTakeover::check(25.305, $items, 75.92));
        $items[0]['base'] = 75.91;
        self::assertNotNull(ForeignCurrencyTakeover::check(25.305, $items, 75.91));
    }

    public function testDocumentTotalMustConvertToo(): void
    {
        // Položky sedí, ale zdroj zaokrouhlil celkem v Kč na celé koruny.
        $items = [['base' => 2512.50, 'vat' => 527.63, 'foreign_base' => 100.0, 'foreign_vat' => 21.0]];
        $reason = ForeignCurrencyTakeover::check(25.125, $items, 3040.0);
        self::assertSame('celkem 121,00 × kurz 25,125 nedává celkem 3 040,00 Kč ze zdroje', $reason);
    }

    public function testHeaderBaseAndVatMustConvertEvenWhenTotalDoes(): void
    {
        // Každá položka i celkem sedí (55,28), ale základ hlavičky 2,00 × 25,126 = 50,25
        // místo 50,26 a daň 0,20 × 25,126 = 5,03 místo 5,02 - obrat § 4a z hlavičky by se lišil.
        $items = [
            ['base' => 25.13, 'vat' => 2.51, 'foreign_base' => 1.0, 'foreign_vat' => 0.1],
            ['base' => 25.13, 'vat' => 2.51, 'foreign_base' => 1.0, 'foreign_vat' => 0.1],
        ];
        self::assertSame('základ dokladu celkem 2,00 × kurz 25,126 nedává 50,26 Kč ze zdroje', ForeignCurrencyTakeover::check(25.126, $items, 55.28));
    }

    /**
     * Evidence DPH přepočítává doklad v cizí měně po sazbách (součet sazby, ne položky).
     * Položky i součty dokladu tu kurzem dávají Kč zdroje, sazby ale ne: 21 % po položkách
     * 11 596,34 Kč, za sazbu 11 596,33; 12 % po položkách 30 238,55, za sazbu 30 238,56.
     * Doklad v měně by přiznání posunul o haléř proti zdroji v každé sazbě.
     */
    public function testEachRateMustConvertAsGroup(): void
    {
        $items = [];
        foreach ([[21.0, [[467.35, 11384.65], [8.35, 203.41], [0.34, 8.28]]], [12.0, [[1218.75, 29688.75], [21.79, 530.80], [0.78, 19.0]]]] as [$rate, $lines]) {
            foreach ($lines as [$foreign, $home]) {
                $items[] = ['rate' => $rate, 'base' => $home, 'vat' => 0.0, 'foreign_base' => $foreign, 'foreign_vat' => 0.0];
            }
        }
        self::assertSame('základ sazby 21 % celkem 476,04 × kurz 24,36 nedává 11 596,34 Kč ze zdroje', ForeignCurrencyTakeover::check(24.36, $items, 41834.89));
        $withoutRates = array_map(static fn (array $i): array => array_diff_key($i, ['rate' => true]), $items);
        self::assertNull(ForeignCurrencyTakeover::check(24.36, $withoutRates, 41834.89), 'Bez sazeb zůstává kontrola po položkách a dokladu.');
    }

    public function testItemsWithoutForeignAmountsKeepCrowns(): void
    {
        $items = [['base' => 2512.50, 'vat' => 527.63, 'foreign_base' => 100.0, 'foreign_vat' => 21.0], ['base' => 1.0, 'vat' => 0.0]];
        self::assertSame('položky dokladu nemají částky v měně dokladu', ForeignCurrencyTakeover::check(25.125, $items, 3041.13));
        self::assertSame('doklad nemá kurz', ForeignCurrencyTakeover::check(null, $items, 0.0));
        self::assertSame('doklad nemá položky', ForeignCurrencyTakeover::check(25.0, [], 0.0));
    }

    public function testCurrencyMissingInCompanyCodebookKeepsCrowns(): void
    {
        $items = [['base' => 2300.0, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0]];
        $d = $this->takeover->decide(5, 'USD', 23.0, 1.0, $items, 2300.0);
        self::assertFalse($d->inForeignCurrency());
        self::assertSame('měna USD není v číselníku měn firmy', $d->reason);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM currencies WHERE supplier_id = 5 AND code = 'USD'")->fetchColumn());
        self::assertTrue($this->takeover->decide(6, 'USD', 23.0, 1.0, $items, 2300.0)->inForeignCurrency());
    }

    public function testMissingCurrencyIsCreatedOnceWhenEnabled(): void
    {
        $takeover = $this->creating();
        $items = [['base' => 2300.0, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0]];
        $d = $takeover->decide(5, 'USD', 23.0, 1.0, $items, 2300.0);
        self::assertTrue($d->inForeignCurrency());
        self::assertTrue($d->currencyCreated);
        self::assertSame(['5', 'USD', 'Americký dolar', '1', '1'], array_map('strval', array_values($this->pdo->query(
            "SELECT supplier_id, code, name_cs, is_active, is_default FROM currencies WHERE id = {$d->currencyId}")->fetch(PDO::FETCH_ASSOC))));

        $again = $takeover->decide(5, 'USD', 23.0, 1.0, $items, 2300.0);
        self::assertSame($d->currencyId, $again->currencyId);
        self::assertFalse($again->currencyCreated);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM currencies WHERE supplier_id = 5 AND code = 'USD'")->fetchColumn());
        self::assertFalse($takeover->decide(6, 'USD', 23.0, 1.0, $items, 2300.0)->currencyCreated);
    }

    public function testInvalidCurrencyCodeIsNotCreated(): void
    {
        $items = [['base' => 2300.0, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0]];
        $d = $this->creating()->decide(5, 'US', 23.0, 1.0, $items, 2300.0);
        self::assertFalse($d->inForeignCurrency());
        self::assertSame('měna US není v číselníku měn firmy', $d->reason);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM currencies WHERE code = 'US'")->fetchColumn());
    }

    /** Pojistka platí i pro zakládání: doklad, který kurzem nevyjde, měnu nezaloží. */
    public function testBlockedDocumentDoesNotCreateCurrency(): void
    {
        $items = [['base' => 2300.0, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0]];
        self::assertFalse($this->creating()->decide(5, 'USD', 23.0, 1.0, $items, 2300.0, 'samovyměření DPH')->inForeignCurrency());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM currencies WHERE supplier_id = 5 AND code = 'USD'")->fetchColumn());
    }

    private function creating(): ForeignCurrencyTakeover
    {
        $db = new Connection(new Config([]));
        (new \ReflectionProperty($db, 'pdo'))->setValue($db, $this->pdo);
        return new ForeignCurrencyTakeover($db, createMissingCurrency: true);
    }

    public function testReasonFromSourceWins(): void
    {
        $items = [['base' => 2512.50, 'vat' => 0.0, 'foreign_base' => 100.0, 'foreign_vat' => 0.0]];
        $d = $this->takeover->decide(5, 'EUR', 25.125, 1.0, $items, 2512.50, 'samovyměření DPH');
        self::assertFalse($d->inForeignCurrency());
        self::assertSame('doklad v EUR, převzat v Kč podle zaúčtování (samovyměření DPH)', $d->note('převzat v Kč podle zaúčtování'));
    }

    public function testSourceBlocksAndPayment(): void
    {
        self::assertStringStartsWith('samovyměření DPH', (string) ForeignCurrencyTakeover::purchaseBlock(true, 'full'));
        self::assertSame('poměrný nárok na odpočet', ForeignCurrencyTakeover::purchaseBlock(false, 'proportional'));
        self::assertNull(ForeignCurrencyTakeover::purchaseBlock(false, 'reduced'));
        self::assertStringStartsWith('odpočet nedaňové zálohy', (string) ForeignCurrencyTakeover::paymentBlock(-1210.0, 0.0, 0.0));
        self::assertStringStartsWith('doklad je uhrazený jen částečně', (string) ForeignCurrencyTakeover::paymentBlock(0.0, 1000.0, 3040.13));
        self::assertNull(ForeignCurrencyTakeover::paymentBlock(0.0, 3040.13, 3040.13));
        self::assertNull(ForeignCurrencyTakeover::paymentBlock(0.0, 0.0, 3040.13));
        self::assertSame(121.0, ForeignCurrencyTakeover::paidInForeignCurrency(3040.13, 121.0));
        self::assertSame(0.0, ForeignCurrencyTakeover::paidInForeignCurrency(0.0, 121.0));
    }

    public function testForeignItemsAndTotals(): void
    {
        $items = ForeignCurrencyTakeover::foreignItems([
            ['quantity' => 4.0, 'unit_price' => 628.125, 'base' => 2512.50, 'vat' => 527.63, 'foreign_base' => 100.0, 'foreign_vat' => 21.0],
            ['quantity' => 0.0, 'unit_price' => -25.13, 'base' => -25.13, 'vat' => 0.0, 'foreign_base' => -1.0, 'foreign_vat' => 0.0],
        ]);
        self::assertSame(100.0, $items[0]['base']);
        self::assertSame(21.0, $items[0]['vat']);
        self::assertSame(25.0, $items[0]['unit_price']);
        self::assertSame(2512.50, $items[0]['home_base']);
        self::assertSame(-1.0, $items[1]['unit_price']);
        self::assertSame(['base' => 99.0, 'vat' => 21.0, 'total' => 120.0], ForeignCurrencyTakeover::totals($items));
    }

    public function testHomeAmountHelpersMatchSqlAndRecap(): void
    {
        self::assertSame(527.63, ForeignCurrencyTakeover::toHome(21.0, 25.125));
        self::assertSame(-527.63, ForeignCurrencyTakeover::toHome(-21.0, 25.125));
        self::assertSame(121.0, ForeignCurrencyTakeover::toHome(121.004, null));
        self::assertSame(
            '(CASE WHEN d.exchange_rate IS NULL THEN d.total_with_vat ELSE ROUND(d.total_with_vat * d.exchange_rate, 2) END)',
            ForeignCurrencyTakeover::homeAmountSql('d.total_with_vat', 'd.exchange_rate'),
        );
    }
}
