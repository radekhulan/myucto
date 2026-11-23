<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\CashFlowStatementService;
use MyInvoice\Service\Accounting\Reports\EquityChangesStatementService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu.
 *
 * U velké a střední ÚJ (a u každé s povinným auditem) jsou součástí závěrky stejně jako
 * rozvaha a výsledovka. Systém je neuměl vůbec, takže balíček závěrky takové firmě hlásil
 * „hotovo" u závěrky, které dvě povinné části chyběly.
 *
 * ── Co testy hlídají především ──────────────────────────────────────────────
 * Že výkaz SEDÍ NA SKUTEČNOST. U cash flow se každý pohyb peněžních účtů klasifikuje
 * podle protiúčtu, takže součet toků se rovná skutečné změně stavu peněz konstrukčně —
 * a `reconciles` to musí potvrdit. To je celý důvod, proč je zvolená přímá klasifikace
 * místo nepřímé metody: v ní se nesoulad schová do zbytkové položky a výkaz formálně
 * „sedí", i když je špatně.
 */
#[Group('integration')]
final class Section18StatementsTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const YEAR = 2088;
    private const STARTS_ON = self::YEAR . '-01-01';
    private const ENDS_ON = self::YEAR . '-12-31';

    private Connection $db;
    private CashFlowStatementService $cashFlow;
    private EquityChangesStatementService $equity;
    private PostingService $posting;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $periodId = 0;
    private int $userId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db       = $c->get(Connection::class);
            $this->cashFlow = $c->get(CashFlowStatementService::class);
            $this->equity   = $c->get(EquityChangesStatementService::class);
            $this->posting  = $c->get(PostingService::class);
            $this->periods  = $c->get(AccountingPeriodRepository::class);
            $seeder         = $c->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($source === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier / user.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::STARTS_ON, self::ENDS_ON);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    // ── Přehled o peněžních tocích ───────────────────────────────────────────

    /**
     * JÁDRO: součet toků se musí rovnat skutečné změně stavu peněz. Tenhle test je
     * důvod, proč je zvolená přímá klasifikace — u nepřímé metody by tahle rovnost
     * platila i tehdy, když je zařazení špatně.
     */
    public function testCashFlowReconcilesWithActualCashMovement(): void
    {
        $this->post('221', '602', 500_000.0, '-03-10');   // inkaso tržby — provozní
        $this->post('518', '221', 120_000.0, '-04-15');   // úhrada služeb — provozní
        $this->post('042', '221', 300_000.0, '-05-20');   // pořízení majetku — investiční
        $this->post('221', '411', 200_000.0, '-06-01');   // vklad kapitálu — finanční

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertTrue($cf['reconciles'], 'Součet toků musí sedět na změnu stavu peněz.');
        self::assertEqualsWithDelta(280_000.0, $cf['net_change'], 0.01);
        self::assertEqualsWithDelta(
            $cf['operating'] + $cf['investing'] + $cf['financing'] + $cf['unclassified'],
            $cf['net_change'],
            0.01,
        );
    }

    /** Zařazení podle protiúčtu: 0xx investiční, 4xx finanční, zbytek provozní. */
    public function testMovementsAreClassifiedByCounterAccount(): void
    {
        $this->post('221', '602', 500_000.0, '-03-10');
        $this->post('042', '221', 300_000.0, '-05-20');
        $this->post('221', '411', 200_000.0, '-06-01');

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(500_000.0, $cf['operating'], 0.01);
        self::assertEqualsWithDelta(-300_000.0, $cf['investing'], 0.01, 'Výdaj na majetek je záporný tok.');
        self::assertEqualsWithDelta(200_000.0, $cf['financing'], 0.01);
    }

    /**
     * Převod mezi vlastními peněžními účty NENÍ peněžní tok. Bez vyloučení by se výběr
     * z účtu do pokladny objevil jako příjem i výdaj zároveň a obraty by se nafoukly,
     * i když se celkový stav peněz nezměnil.
     */
    public function testTransferBetweenOwnCashAccountsIsNotAFlow(): void
    {
        $this->post('221', '602', 100_000.0, '-02-01');
        $this->post('211', '221', 50_000.0, '-03-01');   // výběr z účtu do pokladny

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(100_000.0, $cf['net_change'], 0.01, 'Převod stav peněz nemění.');
        self::assertEqualsWithDelta(100_000.0, $cf['operating'], 0.01, 'A neobjeví se ani v tocích.');
        self::assertTrue($cf['reconciles']);
    }

    /** Úvěr je financování, přestože číslem účtu patří do třídy 2. */
    public function testLoanIsClassifiedAsFinancing(): void
    {
        $this->post('221', '461', 1_000_000.0, '-04-01');

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(1_000_000.0, $cf['financing'], 0.01);
        self::assertEqualsWithDelta(0.0, $cf['operating'], 0.01);
    }

    /**
     * VÍCEŘÁDKOVÝ zápis: jeden peněžní řádek proti N protiúčtům.
     *
     * Přesně tady žila chyba, kterou dosavadní testy nemohly chytit — všechny účtovaly
     * dvouřádkové zápisy. Původní dotaz spojoval peněžní řádek s KAŽDÝM protiřádkem
     * a sčítal peněžní částku, takže se celý příjem započetl u každého protiúčtu zvlášť.
     * V produkci z toho vyšlo číslo o dva řády vedle, navíc s obráceným znaménkem.
     */
    public function testMultiLineEntrySplitsCashAmountAcrossCounterAccounts(): void
    {
        // Úhrada faktury 121 000 = tržba 100 000 + DPH 21 000 (jeden peněžní řádek).
        $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit',  'amount' => 121_000.0],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100_000.0],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 21_000.0],
        ], [
            'entry_date'  => self::YEAR . '-03-10',
            'description' => 'inkaso s DPH',
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(121_000.0, $cf['net_change'], 0.01);
        self::assertEqualsWithDelta(121_000.0, $cf['operating'], 0.01, 'Ne 242 000 — částka se nesmí zdvojit.');
        self::assertTrue($cf['reconciles']);
        self::assertEqualsWithDelta(100_000.0, $this->rowFor($cf['breakdown']['operating'], '602')['amount'], 0.01);
        self::assertEqualsWithDelta(21_000.0, $this->rowFor($cf['breakdown']['operating'], '343')['amount'], 0.01);
    }

    /**
     * Uzávěrkový zápis NENÍ peněžní tok a nesmí srazit konečný stav peněz na nulu.
     *
     * Uzávěrka převádí zůstatek peněžního účtu na 702. Bez vyloučení se objevila mezi
     * provozní činností v plné výši zůstatku a konečný stav uzavřeného roku vyšel jako
     * nula — přesně to výkaz za rok 2025 na ostrých datech ukazoval.
     */
    public function testClosingEntryIsNeitherFlowNorPartOfClosingBalance(): void
    {
        $this->post('221', '602', 500_000.0, '-03-10');

        $this->posting->postDocument($this->supplierId, 'closing', null, [
            ['account_code' => '702', 'side' => 'debit',  'amount' => 500_000.0],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 500_000.0],
        ], [
            'entry_date'  => self::ENDS_ON,
            'description' => 'uzavření peněžního účtu',
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(500_000.0, $cf['closing'], 0.01, 'Uzávěrka stav peněz nemění.');
        self::assertEqualsWithDelta(500_000.0, $cf['operating'], 0.01);
        self::assertSame([], array_filter(
            $cf['breakdown']['operating'],
            static fn (array $r): bool => str_starts_with((string) $r['account_code'], '7'),
        ), 'Uzávěrkové účty třídy 7 do výkazu nepatří.');
        self::assertTrue($cf['reconciles']);
    }

    /**
     * Nákup cenných papírů je INVESTIČNÍ činnost (§ 42 vyhlášky), ne provozní.
     *
     * Dokud 25x spadalo do provozní činnosti, ukázal výkaz za 2024 nákup podílových
     * listů za 1 000 000 Kč mezi provozem a investiční činnost nechal prázdnou — tedy
     * tvrdil, že firma neinvestovala, přestože milion na investici odešel.
     */
    public function testShortTermSecuritiesArePurchasedAsInvestingActivity(): void
    {
        $this->post('221', '602', 2_000_000.0, '-02-01');
        $this->post('251', '221', 1_000_000.0, '-09-26');   // nákup podílových listů

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(-1_000_000.0, $cf['investing'], 0.01);
        self::assertEqualsWithDelta(2_000_000.0, $cf['operating'], 0.01, 'Nákup CP do provozu nepatří.');
        self::assertTrue($cf['reconciles']);
    }

    /**
     * Uzávěrkový zápis nesmí vynulovat VLASTNÍ KAPITÁL.
     *
     * Táž vada, jakou měl přehled o peněžních tocích — a zůstala v druhém výkazu.
     * V produkci vyšel za uzavřený rok konečný stav vlastního kapitálu
     * 0 Kč a základní kapitál „snížený" na nulu, přestože firma kapitál má.
     */
    public function testClosingEntryDoesNotWipeEquity(): void
    {
        $this->openingPost('701', '411', 100_000.0);   // převzetí kapitálu otevíracím zápisem

        $this->posting->postDocument($this->supplierId, 'closing', null, [
            ['account_code' => '411', 'side' => 'debit',  'amount' => 100_000.0],
            ['account_code' => '702', 'side' => 'credit', 'amount' => 100_000.0],
        ], [
            'entry_date'  => self::ENDS_ON,
            'description' => 'uzavření účtu 411',
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);

        $eq = $this->equity->build($this->supplierId, $this->periodId);

        self::assertEqualsWithDelta(100_000.0, $eq['totals']['closing'], 0.01, 'Uzávěrka kapitál nemění.');
        self::assertEqualsWithDelta(100_000.0, $this->rowFor($eq['rows'], '411')['closing'], 0.01);
        self::assertTrue($eq['reconciles']);
    }

    /**
     * Zápis datovaný na PRVNÍ den období se nesmí započítat dvakrát.
     *
     * Rozdělení výsledku hospodaření bývá datované 1. 1. Počáteční stav se bral jako
     * „vše do prvního dne včetně" a pohyby jako „od prvního dne" — týž zápis tedy spadl
     * do obojího. V součtu se to vyrušilo s protistranou, po složkách ne, takže výkaz
     * hlásil, že sedí. Proto se kontroluje i každá složka zvlášť.
     */
    public function testEntryOnFirstDayIsNotCountedTwice(): void
    {
        // Zisk minulého roku (431) se 1. 1. převádí na 428.
        $this->openingPost('701', '431', 500_000.0);
        $this->post('431', '428', 500_000.0, '-01-01');

        $eq = $this->equity->build($this->supplierId, $this->periodId);

        $row428 = $this->rowFor($eq['rows'], '428');
        self::assertEqualsWithDelta(500_000.0, $row428['closing'], 0.01);
        self::assertEqualsWithDelta(
            $row428['opening'] + $row428['increase'] - $row428['decrease'],
            $row428['closing'],
            0.01,
            'Složka musí sedět sama o sobě, ne až v součtu.',
        );
        self::assertTrue($eq['reconciles']);
    }

    /**
     * Stornovaný zápis a jeho storno se ve výkazech vyruší stejně jako v hlavní knize.
     * Filtr „jen nestornované zápisy" vyřadil originál, ale storno (samo nestornované)
     * nechal, takže každé storno přičetlo minus originál.
     */
    public function testReversedEntryNetsOutInCashFlowAndEquity(): void
    {
        $this->post('221', '602', 100_000.0, '-03-10');
        $wrong = $this->post('221', '602', 50_000.0, '-03-11');
        $this->posting->reverse($this->supplierId, $wrong, ['entry_date' => self::YEAR . '-03-12', 'user_id' => $this->userId]);
        $capital = $this->post('221', '411', 200_000.0, '-04-01');
        $this->posting->reverse($this->supplierId, $capital, ['entry_date' => self::YEAR . '-04-02', 'user_id' => $this->userId]);

        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(100_000.0, $cf['net_change'], 0.01, 'Storno se v peněžních tocích započítalo bez originálu.');
        self::assertEqualsWithDelta(100_000.0, $cf['operating'], 0.01);
        self::assertEqualsWithDelta(0.0, $cf['financing'], 0.01);
        self::assertTrue($cf['reconciles']);

        $eq = $this->equity->build($this->supplierId, $this->periodId);
        self::assertEqualsWithDelta(0.0, $eq['totals']['closing'], 0.01, 'Storno vkladu ubralo kapitál, který nikdy nepřibyl.');
        self::assertTrue($eq['reconciles']);
    }

    /** Bez pohybů je výkaz prázdný, ale pořád konzistentní. */
    public function testEmptyPeriodStillReconciles(): void
    {
        $cf = $this->cashFlow->build($this->supplierId, $this->periodId);

        self::assertSame(0.0, $cf['net_change']);
        self::assertTrue($cf['reconciles']);
    }

    // ── Přehled o změnách vlastního kapitálu ─────────────────────────────────

    /**
     * Zvýšení a snížení se vykazují ZVLÁŠŤ. Čistá změna by informaci ztratila — vklad
     * a výplata ve stejné výši by vyšly jako nula, ačkoli se stalo obojí.
     */
    public function testEquityIncreaseAndDecreaseAreReportedSeparately(): void
    {
        $this->post('221', '411', 1_000_000.0, '-03-01');   // vklad
        $this->post('411', '221', 400_000.0, '-09-01');     // snížení kapitálu

        $eq = $this->equity->build($this->supplierId, $this->periodId);
        $row = $this->rowFor($eq['rows'], '411');

        self::assertEqualsWithDelta(1_000_000.0, $row['increase'], 0.01);
        self::assertEqualsWithDelta(400_000.0, $row['decrease'], 0.01);
        self::assertEqualsWithDelta(600_000.0, $row['closing'], 0.01);
        self::assertTrue($eq['reconciles'], 'Počátek + zvýšení − snížení = konec.');
    }

    /**
     * Vlastní kapitál má kreditní zůstatek, ale ve výkazu se uvádí KLADNĚ. Bez otočení
     * by základní kapitál vyšel záporně a čtenář by to četl jako ztrátu.
     */
    public function testEquityIsReportedAsPositive(): void
    {
        $this->post('221', '411', 500_000.0, '-03-01');

        self::assertGreaterThan(0, $this->rowFor($this->equity->build($this->supplierId, $this->periodId)['rows'], '411')['closing']);
    }

    /** Složky, které firma nemá, se do výkazu nedávají — prázdné řádky jen matou. */
    public function testUnusedEquityComponentsAreOmitted(): void
    {
        $this->post('221', '411', 100_000.0, '-03-01');

        $codes = array_column($this->equity->build($this->supplierId, $this->periodId)['rows'], 'account_code');

        self::assertContains('411', $codes);
        self::assertNotContains('421', $codes, 'Rezervní fond firma nemá — do výkazu nepatří.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /** Otevírací zápis — jediný, kde se smí použít 701 (guard PostingService). */
    private function openingPost(string $debit, string $credit, float $amount): void
    {
        $this->posting->postDocument($this->supplierId, 'opening', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => self::STARTS_ON,
            'description' => 'otevření knih',
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }

    private function post(string $debit, string $credit, float $amount, string $mmdd): int
    {
        return $this->posting->postDocument($this->supplierId, 'manual', null, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], [
            'entry_date'  => self::YEAR . $mmdd,
            'description' => $debit . '/' . $credit,
            'posted'      => true,
            'user_id'     => $this->userId,
        ]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function rowFor(array $rows, string $code): array
    {
        foreach ($rows as $r) {
            if ($r['account_code'] === $code) {
                return $r;
            }
        }
        self::fail('Řádek ' . $code . ' ve výkazu chybí.');
    }
}
