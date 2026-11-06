<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax\Return;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Tax\Return\DppoReturnDataProvider;
use MyInvoice\Service\Tax\Return\NonDeductibleCostsService;
use PDO;
use PHPUnit\Framework\TestCase;

final class DppoReturnDataProviderTest extends TestCase
{
    private PDO $pdo;
    private Connection $db;
    private DppoReturnDataProvider $provider;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        \MyInvoice\Tests\Support\AccountDefinitionSqliteFixture::install($this->pdo);

        $config = $this->createStub(\MyInvoice\Infrastructure\Config\Config::class);
        $db = new Connection($config);
        (new \ReflectionClass($db))->getProperty('pdo')->setValue($db, $this->pdo);
        $this->db = $db;
        // ClosingService (4. arg) se v unit testu nepředává → projekce VH se přeskočí (null).
        $this->provider = new DppoReturnDataProvider(
            $db,
            new AccountingPeriodRepository($db),
            new NonDeductibleCostsService($db),
        );
    }

    /** Uznatelnost dokladu je samostatná osa: nedaňová služba smí zůstat na účtu 518. */
    public function testPurchaseHeaderNonDeductibleFlagIsAddedBackIndependentlyOfAccount(): void
    {
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (1,'518','expense','deductible','Ostatní služby'),
            (2,'513','expense','non_deductible','Reprezentace')");
        $this->pdo->exec('INSERT INTO purchase_invoices (id, supplier_id, tax_deductible) VALUES (100,1,0),(101,1,1),(102,1,0)');

        $this->plEntry(100, '2025-06-01', 'purchase_invoice', 1, 'debit', 773.50);
        $this->plEntry(101, '2025-06-02', 'purchase_invoice', 1, 'debit', 100.00);
        $this->plEntry(102, '2025-06-03', 'purchase_invoice', 2, 'debit', 50.00);

        self::assertSame(
            823.50,
            (new NonDeductibleCostsService($this->db))->sum(1, '2025-01-01', '2025-12-31'),
            'Nedaňový příznak dokladu přičte 518, nedaňový účet 513 se přitom nesmí započítat dvakrát.',
        );
    }

    public function testDisposalBridgeDoesNotDoubleTaxGiftOrDamageAndAdjustsSaleBothWays(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (1,'543','expense','non_deductible','Dary'),(2,'549','expense','non_deductible','Manka a škody'),
            (3,'541','expense','deductible','ZC prodaného majetku'),(4,'551','expense','deductible','Odpisy'),(5,'082','asset','deductible','Oprávky')");

        $this->asset(1, 'DAR', 'donated', 50000, 50000, 1);
        $this->asset(2, 'SKODA', 'damaged', 40000, 40000, 2);
        $this->asset(3, 'PRODEJ', 'sold', 30000, 20000, 3);
        $this->asset(4, 'LIKVIDACE', 'liquidated', 10000, 25000, 4);
        $this->asset(5, 'PLNE-ODEPSANY', 'sold', 0, 25000, 3);

        $result = $this->provider->gather(1, 2025);
        self::assertSame(0.0, $result['disposal_nondeductible_residual']);
        self::assertSame(10000.0, $result['disposal_tax_increase']);
        self::assertSame(40000.0, $result['disposal_tax_decrease']);
        self::assertSame(90000.0, $result['non_deductible_costs'], 'Dar a škoda jsou přičteny jen přes 543/549.');

        $byNumber = [];
        foreach ($result['disposals'] as $row) {
            $byNumber[$row['inventory_number']] = $row;
        }
        self::assertSame(0.0, $byNumber['DAR']['non_deductible_part']);
        self::assertSame(10000.0, $byNumber['PRODEJ']['tax_increase']);
        self::assertSame(15000.0, $byNumber['LIKVIDACE']['tax_decrease']);
        self::assertSame(0.0, $byNumber['PLNE-ODEPSANY']['book_residual_value']);
        self::assertSame(25000.0, $byNumber['PLNE-ODEPSANY']['tax_decrease']);
    }

    /**
     * EP-1: majetek vyřazený mimo modul majetku (převzatý deník, ruční zápis 541/08x) nemá
     * zápis `asset_disposal`. Účetní ZC se vezme z karty a zkontroluje proti deníku; můstek
     * vznikne stejně jako u vyřazení v modulu.
     */
    public function testDisposalWithoutModuleEntryTakesBookResidualFromCard(): void
    {
        $this->disposalFixture();
        // vstupní cena 100 000, oprávky 60 000 počáteční + 39 000 odpisy = ZC 1 000; daňová ZC 600
        $this->migratedAsset(1, 'PREVZATY', 'sold', 'straight', 100000, 60000, 39000, 600.0, '082');
        $this->ledgerEntry(10, '2025-06-30', '541', '082', 1000);

        $result = $this->provider->gather(1, 2025);
        self::assertSame(400.0, $result['disposal_tax_increase'], 'Účetní ZC 1 000 − daňová 600.');
        self::assertSame(0.0, $result['disposal_tax_decrease']);
        $row = $result['disposals'][0];
        self::assertSame([1000.0, 'card', 1000.0], [$row['book_residual_value'], $row['book_residual_source'], $row['journal_residual_value']]);
        self::assertSame([], $this->residualWarnings($result['warnings']), 'Karta sedí na deník, varování žádné.');
    }

    public function testDisposalCardResidualDifferentFromLedgerWarnsWithBothAmounts(): void
    {
        $this->disposalFixture();
        $this->migratedAsset(1, 'PREVZATY', 'liquidated', 'straight', 100000, 60000, 39000, 600.0, '082');
        $this->ledgerEntry(10, '2025-06-30', '541', '082', 1500);

        $result = $this->provider->gather(1, 2025);
        self::assertSame(400.0, $result['disposal_tax_increase'], 'Přiznání jede ze ZC podle karty.');
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('podle karty 1 000,00 Kč', $warnings);
        self::assertStringContainsString('v deníku (MD 54x proti účtu 082) 1 500,00 Kč', $warnings);
    }

    /**
     * Ochrana: odpisovaný majetek bez daňové historie má daňovou ZC neznámou. Dosadit
     * vstupní cenu by u plně odepsaného softwaru za 1 mil. Kč vyrobilo odpočet 1 mil. Kč.
     */
    public function testDepreciableCardWithoutTaxHistoryNeverDeductsInputPrice(): void
    {
        $this->disposalFixture();
        $this->migratedAsset(1, 'SOFTWARE', 'liquidated', 'none', 1000000, 1000000, 0, null, '073', 'intangible');

        $result = $this->provider->gather(1, 2025);
        self::assertSame(0.0, $result['disposal_tax_decrease'], 'Žádný fiktivní odpočet vstupní ceny.');
        self::assertSame(0.0, $result['disposal_tax_increase']);
        $row = $result['disposals'][0];
        self::assertSame([0.0, null, 'unknown'], [$row['book_residual_value'], $row['tax_residual_value'], $row['tax_residual_source']]);
        self::assertStringContainsString('SOFTWARE', implode("\n", $result['warnings']));
        self::assertStringContainsString('není známa daňová zůstatková cena', implode("\n", $result['warnings']));
    }

    public function testNonDepreciableLandAndByAccountingCardsGiveNoBridge(): void
    {
        $this->disposalFixture();
        $this->migratedAsset(1, 'POZEMEK', 'sold', 'none', 32112, 0, 0, null, null);
        $this->ledgerEntry(10, '2025-06-30', '541', '031', 32112);
        $this->migratedAsset(2, 'LICENCE', 'liquidated', 'by_accounting', 50000, 45000, 0, null, '073', 'intangible');
        $this->ledgerEntry(11, '2025-06-30', '541', '073', 5000);

        $result = $this->provider->gather(1, 2025);
        self::assertSame([0.0, 0.0], [$result['disposal_tax_increase'], $result['disposal_tax_decrease']]);
        $byNumber = array_column($result['disposals'], null, 'inventory_number');
        self::assertSame([32112.0, 32112.0, 'non_depreciable'], [
            $byNumber['POZEMEK']['book_residual_value'], $byNumber['POZEMEK']['tax_residual_value'], $byNumber['POZEMEK']['tax_residual_source'],
        ]);
        self::assertSame([5000.0, 5000.0, 'by_accounting'], [
            $byNumber['LICENCE']['book_residual_value'], $byNumber['LICENCE']['tax_residual_value'], $byNumber['LICENCE']['tax_residual_source'],
        ]);
        self::assertSame([], $this->residualWarnings($result['warnings']));
    }

    /**
     * #13 (hranice období): VH se počítá STRIKTNĚ za zdaňovací období vybraného roku —
     * záznamy jiných let (i sousedních period téže firmy) se do součtu NESMÍ přimíchat.
     * Regrese k záměně roku v náhledu (5 685 370 = správný FY2024, chybně zobrazený místo 2025).
     */
    public function testProfitBeforeTaxIsIsolatedToTheSelectedFiscalYear(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES
            (1,1,2024,'2024-01-01','2024-12-31','open',NULL,'2024-01-01',1),
            (2,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (10,'602','revenue','deductible','Tržby'),(11,'501','expense','deductible','Spotřeba'),(12,'591','expense','non_deductible','Daň z příjmů')");

        // FY2024: výnos 5000 / náklad 1000 → VH 4000 (nesmí se objevit ve 2025).
        $this->plEntry(100, '2024-06-30', 'invoice', 10, 'credit', 5000);
        $this->plEntry(101, '2024-06-30', 'purchase_invoice', 11, 'debit', 1000);
        // FY2025: výnos 3000 / náklad 800 → VH 2200.
        $this->plEntry(200, '2025-06-30', 'invoice', 10, 'credit', 3000);
        $this->plEntry(201, '2025-06-30', 'purchase_invoice', 11, 'debit', 800);
        // FY2026 (mimo horní hranici) — nesmí se počítat.
        $this->plEntry(300, '2026-01-15', 'invoice', 10, 'credit', 9999);
        // 591 (daň z příjmů) — vyloučeno filtrem NOT LIKE '59%' i uvnitř 2025.
        $this->plEntry(202, '2025-12-31', 'income_tax', 12, 'debit', 462);
        // uzavírací zápis (source_type='closing') — do VH se nezapočítává.
        $this->plEntry(203, '2025-12-31', 'closing', 10, 'credit', 12345);

        self::assertSame(2200.0, $this->provider->gather(1, 2025)['vh'], 'FY2025 VH = 3000 − 800, bez 2024/2026, bez 591 a bez closing.');
        self::assertSame(4000.0, $this->provider->gather(1, 2024)['vh'], 'FY2024 VH = 5000 − 1000, izolovaně.');
    }

    /**
     * Feature 2 — auto-návrhy připočitatelných (§25) a odečitatelných (§20) položek pro účetní:
     * skenuje obraty 513/543/545/549/528 (i účty NEoznačené jako nedaňové), vylučuje uzávěrkové
     * zápisy a účty mimo skupiny (501). Návrhy jsou seřazené sestupně podle částky; 543 se navíc
     * nabídne jako možný odečet daru §20/8.
     */
    public function testSuggestionsSurfaceLikelyAddbacksAndDonationDeduction(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (20,'513','expense','deductible','Reprezentace'),
            (21,'543','expense','non_deductible','Dary'),
            (22,'549','expense','deductible','Manka a škody'),
            (23,'501','expense','deductible','Spotřeba materiálu'),
            (24,'602','revenue','deductible','Tržby')");

        $this->plEntry(1, '2025-03-01', 'purchase_invoice', 20, 'debit', 5000);   // 513 reprezentace
        $this->plEntry(2, '2025-04-01', 'purchase_invoice', 21, 'debit', 12000);  // 543 dary
        $this->plEntry(3, '2025-05-01', 'internal', 22, 'debit', 3000);           // 549 manka
        $this->plEntry(4, '2025-06-01', 'purchase_invoice', 23, 'debit', 99999);  // 501 — NESMÍ být návrh
        $this->plEntry(5, '2025-12-31', 'closing', 20, 'debit', 1000);            // closing — vyloučeno
        $this->plEntry(6, '2025-06-01', 'invoice', 24, 'credit', 200000);         // výnos

        $result = $this->provider->gather(1, 2025);
        $addbacks = $result['suggestions']['addbacks'];
        $codes = array_column($addbacks, 'account_code');
        self::assertSame(['543', '513', '549'], $codes, 'Návrhy seřazené sestupně dle částky, bez 501.');

        $byCode = [];
        foreach ($addbacks as $a) {
            $byCode[$a['account_code']] = $a;
        }
        self::assertSame(12000.0, $byCode['543']['amount']);
        self::assertSame(5000.0, $byCode['513']['amount']);
        self::assertSame(3000.0, $byCode['549']['amount']);
        self::assertTrue($byCode['543']['already_non_deductible'], '543 je nedaňový → už v auto-ř.40.');
        self::assertFalse($byCode['513']['already_non_deductible'], '513 není označen → jen návrh k ověření.');
        self::assertSame('taxReturn.suggest_513', $byCode['513']['hint_key']);

        $deductions = $result['suggestions']['deductions'];
        self::assertCount(1, $deductions);
        self::assertSame('donation_543', $deductions[0]['key']);
        self::assertSame(12000.0, $deductions[0]['amount']);
    }

    /**
     * EP-2 (daňově kritické): VH i §25 addback musí VYLOUČIT jen close_books zápis
     * (source_type='closing', source_id = period_id < STOCK_SLOT_BASE), ale POČÍTAT
     * slotované skladové zápisy §3.4 (source_id >= STOCK_SLOT_BASE) — snížení spotřeby
     * 501 (MD 112 / D 501) i inventurní manko na 549. Plošný filtr `source_type <> 'closing'`
     * je omylem vyhazoval a podhodnocoval základ DPPO.
     */
    public function testStockClosingSlotsCountIntoVhWhileCloseBooksIsExcluded(): void
    {
        $periodId = 1;
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (40,'602','revenue','deductible','Tržby'),
            (41,'501','expense','deductible','Spotřeba materiálu'),
            (42,'549','expense','non_deductible','Manka a škody')");

        // Operativní VH: výnos 10000 − náklad 6000 = 4000.
        $this->plEntry(1, '2025-06-30', 'invoice', 40, 'credit', 10000);
        $this->plEntry(2, '2025-06-30', 'purchase_invoice', 41, 'debit', 6000);

        // Skladový slot §3.4 (source_type='closing', source_id >= STOCK_SLOT_BASE):
        // konečný stav zásob MD 112 / D 501 → snížení spotřeby o 1500 (jen řádek 501 je výsledkový).
        $this->plEntry(3, '2025-12-31', 'closing', 41, 'credit', 1500, ClosingSourceId::stockClosing($periodId));
        // Inventurní manko §3.4 MD 549 / D 112 → nedaňový náklad 800 (musí vstoupit do VH i do ř.40).
        $this->plEntry(4, '2025-12-31', 'closing', 42, 'debit', 800, ClosingSourceId::stockShortage($periodId));

        // close_books (source_id = period_id < STOCK_SLOT_BASE): převod 602→710 a 710→501,
        // který by při plošném filtru vynuloval operativní VH — MUSÍ být vyloučen.
        $this->plEntry(5, '2025-12-31', 'closing', 40, 'debit', 10000, $periodId);
        $this->plEntry(6, '2025-12-31', 'closing', 41, 'credit', 6000, $periodId);

        $result = $this->provider->gather(1, 2025);
        // VH = 4000 (operativní) + 1500 (snížení 501) − 800 (manko 549) = 4700.
        self::assertSame(4700.0, $result['vh'], 'Skladové sloty §3.4 do VH patří; close_books se vyloučí.');
        // §25 nedaňové náklady: manko 549 slotem 'closing' (source_id >= base) se počítá.
        self::assertSame(800.0, $result['non_deductible_costs'], 'Skladové manko 549 (slot closing) vstupuje do ř.40.');

        // §25 addback návrh 549 musí obsahovat skladové manko (dřív ho plošný filtr vyhazoval).
        $byCode = [];
        foreach ($result['suggestions']['addbacks'] as $a) {
            $byCode[$a['account_code']] = $a;
        }
        self::assertArrayHasKey('549', $byCode, 'Skladové manko 549 se musí objevit v návrzích §25.');
        self::assertSame(800.0, $byCode['549']['amount']);
    }

    /**
     * § 24 odst. 2 písm. w) ZDP: nabývací cena prodaných podílů (561P) je daňová jen do výše
     * příjmů z prodeje (661), převis jde na ř. 40. Úhrn se sčítá přes všechny prodeje roku.
     *
     * @return iterable<string,array{0:list<array{0:string,1:string,2:float}>,1:float}>
     */
    public static function sharesSaleCases(): iterable
    {
        yield 'prodej se ztrátou' => [[['561P', 'debit', 1000.0], ['661', 'credit', 800.0]], 200.0];
        yield 'prodej se ziskem' => [[['561P', 'debit', 800.0], ['661', 'credit', 1000.0]], 0.0];
        yield 'bez prodeje' => [[['602', 'credit', 5000.0]], 0.0];
        yield 'více prodejů v roce' => [[
            ['561P', 'debit', 600.0], ['661', 'credit', 300.0],
            ['561P', 'debit', 400.0], ['661', 'credit', 500.0],
        ], 200.0];
    }

    /** @param list<array{0:string,1:string,2:float}> $lines */
    #[\PHPUnit\Framework\Attributes\DataProvider('sharesSaleCases')]
    public function testSharesSaleCostAboveProceedsIsAddedBackOnLine40(array $lines, float $expected): void
    {
        $this->securitiesFixture();
        foreach ($lines as $i => [$code, $side, $amount]) {
            $this->plEntry(500 + $i, '2025-06-30', 'manual', $this->accountId($code), $side, $amount);
        }

        $result = $this->provider->gather(1, 2025);
        self::assertSame($expected, $result['securities_cost_excess']);
        self::assertSame($expected, $result['securities_sale']['addback']);
        self::assertSame($expected, \MyInvoice\Service\Tax\Return\DppoReturnCalculator::accountingAdjustments($result)[40],
            'Převis 561P nad 661 je jedinou částí ř. 40 z účetnictví v tomto scénáři.');
        self::assertSame([], array_values(array_filter($result['suggestions']['addbacks'], static fn (array $a): bool => $a['account_code'] === '561')));
    }

    /**
     * Ostatní cenné papíry (561C) mohou být dluhopisy nebo papíry v reálné hodnotě, jejichž
     * náklad je daňový celý (§ 24/2/r) — převis se proto jen nabídne k posouzení, ř. 40 nemění.
     */
    public function testOtherSecuritiesExcessIsOnlySuggestedNotAddedBack(): void
    {
        $this->securitiesFixture();
        $this->plEntry(500, '2025-06-30', 'manual', $this->accountId('561C'), 'debit', 1000.0);
        $this->plEntry(501, '2025-06-30', 'manual', $this->accountId('661'), 'credit', 800.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame(0.0, $result['securities_cost_excess']);
        $suggestion = array_values(array_filter($result['suggestions']['addbacks'], static fn (array $a): bool => $a['account_code'] === '561'));
        self::assertCount(1, $suggestion);
        self::assertSame(200.0, $suggestion[0]['amount']);
        self::assertSame('taxReturn.suggest_561', $suggestion[0]['hint_key']);
    }

    /** Nedaňově označený 561P už je celý v nedaňových nákladech — na ř. 40 se nesmí objevit dvakrát. */
    public function testNonDeductibleSharesAccountIsNotCountedTwice(): void
    {
        $this->securitiesFixture();
        $this->pdo->exec("UPDATE chart_of_accounts SET tax_deductibility = 'non_deductible' WHERE account_code = '561P'");
        $this->plEntry(500, '2025-06-30', 'manual', $this->accountId('561P'), 'debit', 1000.0);
        $this->plEntry(501, '2025-06-30', 'manual', $this->accountId('661'), 'credit', 800.0);

        $result = $this->provider->gather(1, 2025);
        self::assertSame(1000.0, $result['non_deductible_costs']);
        self::assertSame(0.0, $result['securities_cost_excess']);
        self::assertSame(1000.0, \MyInvoice\Service\Tax\Return\DppoReturnCalculator::accountingAdjustments($result)[40]);
    }

    /** Stejný koncept u FO s podvojným účetnictvím: převis 561P nad 661 se vyloučí z výdajů § 7. */
    public function testDpfoDoubleEntryExcludesSharesExcessFromSection7Expenses(): void
    {
        $this->securitiesFixture();
        $this->plEntry(500, '2025-06-30', 'manual', $this->accountId('561P'), 'debit', 1000.0);
        $this->plEntry(501, '2025-06-30', 'manual', $this->accountId('661'), 'credit', 800.0);

        $fo = (new \ReflectionClass(\MyInvoice\Service\Tax\Return\DpfoReturnDataProvider::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($fo, 'db'))->setValue($fo, $this->db);
        (new \ReflectionProperty($fo, 'nonDeductibleCostsService'))->setValue($fo, new NonDeductibleCostsService($this->db));
        [$revenues, $expenses] = (new \ReflectionMethod($fo, 'vhBase'))->invoke($fo, 1, 2025);
        self::assertSame(800.0, $revenues);
        self::assertSame(800.0, $expenses, 'Náklad 1 000 − nedaňový převis 200.');
    }

    private function securitiesFixture(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (60,'561P','expense','deductible','Prodané podíly'),
            (61,'561C','expense','deductible','Prodané ostatní cenné papíry'),
            (62,'661','revenue','deductible','Tržby z prodeje cenných papírů a podílů'),
            (63,'602','revenue','deductible','Tržby')");
    }

    private function accountId(string $code): int
    {
        return (int) $this->pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '{$code}'")->fetchColumn();
    }

    /** Bez ClosingService (unit test / autowire fallback) je projekce prázdná: vh_projected == vh_posted. */
    public function testProjectionIsEmptyWithoutClosingService(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES (30,'602','revenue','deductible','Tržby')");
        $this->plEntry(1, '2025-06-30', 'invoice', 30, 'credit', 7000);

        $projection = $this->provider->gather(1, 2025)['closing_projection'];
        self::assertFalse($projection['is_projection']);
        self::assertSame(7000.0, $projection['vh_posted']);
        self::assertSame(7000.0, $projection['vh_projected']);
        self::assertSame([], $projection['items']);
    }

    private function plEntry(int $id, string $date, string $sourceType, int $accountId, string $side, float $amount, ?int $sourceId = null): void
    {
        $this->pdo->prepare("INSERT INTO journal_entries VALUES (?,1,?,?,?,?,NULL)")
            ->execute([$id, $date, $sourceType, $sourceId ?? $id, $date]);
        $this->pdo->prepare("INSERT INTO journal_entry_lines VALUES (?,1,?,?,?,?)")
            ->execute([$id, $id, $accountId, $side, $amount]);
    }

    private function asset(int $id, string $number, string $type, float $bookResidual, float $taxResidual, int $expenseAccount): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO assets (id, supplier_id, inventory_number, name, kind, disposal_date, disposal_type, input_price,
                                 opening_tax_amount, status, tax_method, asset_account_code, accumulated_account_code)
             VALUES (?,1,?,?,'tangible','2025-06-30',?,100000,0,'disposed','straight','022','082')"
        );
        $stmt->execute([$id, $number, $number, $type]);
        $this->pdo->prepare("INSERT INTO depreciation_entries VALUES (?,1,?,'tax',2025,0,?)")
            ->execute([$id, $id, $taxResidual]);
        $this->pdo->prepare("INSERT INTO journal_entries VALUES (?,1,'2025-06-30','asset_disposal',?,'2025-06-30',NULL)")
            ->execute([$id, $id]);
        if ($bookResidual > 0) {
            $this->pdo->prepare("INSERT INTO journal_entry_lines VALUES (?,1,?,?, 'debit',?)")
                ->execute([$id * 2 - 1, $id, $expenseAccount, $bookResidual]);
        }
        $this->pdo->prepare("INSERT INTO journal_entry_lines VALUES (?,1,?,?, 'credit',?)")
            ->execute([$id * 2, $id, 5, $bookResidual]);
    }

    private function disposalFixture(): void
    {
        $this->pdo->exec("INSERT INTO accounting_periods (id, supplier_id, fiscal_year, starts_on, ends_on, status, closed_at, created_at, row_version) VALUES (1,1,2025,'2025-01-01','2025-12-31','open',NULL,'2025-01-01',1)");
        $this->pdo->exec("INSERT INTO chart_of_accounts (id, account_code, account_type, tax_deductibility, name) VALUES
            (1,'541','expense','deductible','ZC prodaného majetku'),(2,'082','asset','deductible','Oprávky'),
            (3,'031','asset','deductible','Pozemky'),(4,'073','asset','deductible','Oprávky k software'),
            (5,'551','expense','deductible','Odpisy')");
    }

    /** Karta převedená z jiného systému: vyřazená bez zápisu `asset_disposal`. */
    private function migratedAsset(
        int $id,
        string $number,
        string $type,
        string $taxMethod,
        float $inputPrice,
        float $openingAcc,
        float $accountingTotal,
        ?float $taxResidual,
        ?string $accumulated,
        string $kind = 'tangible',
    ): void {
        $this->pdo->prepare(
            "INSERT INTO assets (id, supplier_id, inventory_number, name, kind, disposal_date, disposal_type, input_price,
                                 opening_acc_amount, status, tax_method, asset_account_code, accumulated_account_code)
             VALUES (?,1,?,?,?,'2025-06-30',?,?,?,'disposed',?,?,?)"
        )->execute([$id, $number, $number, $kind, $type, $inputPrice, $openingAcc, $taxMethod,
            $accumulated === null ? '031' : ($kind === 'intangible' ? '013' : '022'), $accumulated]);
        if ($accountingTotal > 0) {
            $this->pdo->prepare("INSERT INTO depreciation_entries (supplier_id, asset_id, kind, fiscal_year, amount, residual_value_end) VALUES (1,?,'accounting',2025,?,?)")
                ->execute([$id, $accountingTotal, max(0.0, $inputPrice - $openingAcc - $accountingTotal)]);
        }
        if ($taxResidual !== null) {
            $this->pdo->prepare("INSERT INTO depreciation_entries (supplier_id, asset_id, kind, fiscal_year, amount, residual_value_end) VALUES (1,?,'tax',2025,0,?)")
                ->execute([$id, $taxResidual]);
        }
    }

    /** Ruční (převzatý) zápis MD $debit / D $credit — účty podle kódu z disposalFixture(). */
    private function ledgerEntry(int $id, string $date, string $debit, string $credit, float $amount): void
    {
        $account = fn (string $code): int => (int) $this->pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '{$code}'")->fetchColumn();
        $this->pdo->prepare("INSERT INTO journal_entries VALUES (?,1,?,'manual',NULL,?,NULL)")->execute([$id, $date, $date]);
        $this->pdo->prepare("INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount) VALUES (1,?,?,'debit',?)")
            ->execute([$id, $account($debit), $amount]);
        $this->pdo->prepare("INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount) VALUES (1,?,?,'credit',?)")
            ->execute([$id, $account($credit), $amount]);
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function residualWarnings(array $warnings): array
    {
        return array_values(array_filter($warnings, static fn (string $w): bool => str_contains($w, 'ZC') || str_contains($w, 'zůstatková')));
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE supplier_accounting_modes (supplier_id INTEGER, effective_from TEXT, accounting_mode TEXT)');
        $this->pdo->exec("INSERT INTO supplier_accounting_modes VALUES (1, '1900-01-01', 'double_entry'), (2, '1900-01-01', 'double_entry')");
        $this->pdo->exec('CREATE TABLE supplier_accounting_frameworks (id INTEGER, supplier_id INTEGER, effective_from TEXT, accounting_framework TEXT, created_at TEXT, created_by INTEGER)');
        $this->pdo->exec('CREATE TABLE accounting_periods (id INTEGER, supplier_id INTEGER, fiscal_year INTEGER, starts_on TEXT, ends_on TEXT, status TEXT, closed_at TEXT, created_at TEXT, row_version INTEGER, closed_by INTEGER, approved_at TEXT, approved_by INTEGER, reviewed_at TEXT, reviewed_by INTEGER, approval_body TEXT, approval_decision_ref TEXT, approval_document_hash TEXT, created_reason TEXT)');
        $this->pdo->exec('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT, tax_deductibility TEXT, name TEXT)');
        $this->pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_date TEXT, source_type TEXT, source_id INTEGER, posted_at TEXT, reversed_by INTEGER)');
        $this->pdo->exec('CREATE TABLE journal_entry_lines (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_id INTEGER, account_id INTEGER, side TEXT, amount REAL, is_red_storno INTEGER GENERATED ALWAYS AS (0) VIRTUAL, signed_amount REAL GENERATED ALWAYS AS (amount) VIRTUAL)');
        $this->pdo->exec('CREATE TABLE purchase_invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, tax_deductible INTEGER, vendor_id INTEGER, status TEXT, document_kind TEXT, effective_cost_date TEXT, total_without_vat REAL)');
        $this->pdo->exec('CREATE TABLE assets (id INTEGER PRIMARY KEY, supplier_id INTEGER, inventory_number TEXT, name TEXT, kind TEXT, tax_group INTEGER,
            disposal_date TEXT, disposal_type TEXT, disposal_price REAL, input_price REAL, opening_tax_years INTEGER DEFAULT 0, opening_tax_amount REAL DEFAULT 0,
            opening_acc_amount REAL DEFAULT 0, tax_method TEXT, asset_account_code TEXT, accumulated_account_code TEXT, status TEXT,
            disposal_entry_id INTEGER)');
        $this->pdo->exec('CREATE TABLE asset_improvements (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, completed_on TEXT, amount REAL)');
        $this->pdo->exec('CREATE TABLE depreciation_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, kind TEXT, fiscal_year INTEGER, amount REAL, residual_value_end REAL)');
        // Podklady pro VetaD/spoj_zahr (relatedPartyCountryFlag) a VetaNP (bankAccount) —
        // v téhle testovací třídě prázdné, gather() je ale pořád volá, tabulky musí existovat.
        $this->pdo->exec('CREATE TABLE invoices (id INTEGER PRIMARY KEY, supplier_id INTEGER, client_id INTEGER, status TEXT, invoice_type TEXT, effective_tax_date TEXT, total_without_vat REAL)');
        $this->pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, supplier_id INTEGER, country_id INTEGER, related_party INTEGER, company_name TEXT, ic TEXT)');
        $this->pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY, iso2 TEXT)');
        $this->pdo->exec('CREATE TABLE currencies (id INTEGER PRIMARY KEY, supplier_id INTEGER, code TEXT, account_number TEXT, bank_code TEXT, bank_name TEXT, iban TEXT, is_default INTEGER, is_active INTEGER)');
    }
}
