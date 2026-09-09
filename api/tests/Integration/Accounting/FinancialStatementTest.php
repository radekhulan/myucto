<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\FinancialStatementService;
use MyInvoice\Service\Accounting\Reports\TrialBalanceService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační testy rozvahy a VZZ (Epic F2, T8–T14): bilanční rovnice
 * Σ aktiva netto == Σ pasiva (haléře), VH rozvaha == VH VZZ, sloupec korekce,
 * saldové účty (343, R8), vyloučení closing/offbalance (R2), rozsahy dle R12
 * a minulé období (R13).
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class FinancialStatementTest extends TestCase
{
    private const YEAR = 2099;
    private const AS_OF = self::YEAR . '-12-31';

    private Connection $db;
    private PostingService $posting;
    private FinancialStatementService $statements;
    private TrialBalanceService $trialBalance;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db           = $container->get(Connection::class);
            $this->posting      = $container->get(PostingService::class);
            $this->statements   = $container->get(FinancialStatementService::class);
            $this->trialBalance = $container->get(TrialBalanceService::class);
            $this->periods      = $container->get(AccountingPeriodRepository::class);
            $seeder             = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $currencyId   = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $vatRateId    = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId         = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->userId === 0 || $currencyId === 0 || $vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (user/currency/vat_rate/country) v DB.');
        }
        $hasSeed = (int) $pdo->query(
            "SELECT COUNT(*) FROM statement_versions WHERE version_code = 'vyhl500-2002/2024'"
        )->fetchColumn();
        if ($hasSeed < 2) {
            $this->markTestSkipped('Seed výkazů 1012 není aplikovaný (statement_versions).');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier: rozvahové PS jsou kumulativní přes celou historii
        // deníku (R6), takže sdílený supplier s reálnými dev daty by rozbil
        // bilanční asserty (výnosy mimo fiskální okno vs. kumulativní aktiva).
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, "Testovací 1", "Praha", "11000", ?, "f2-vykazy@example.com", ?, ?)'
        );
        $stmt->execute(['F2 výkazy test s.r.o.', $czId, $currencyId, $vatRateId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');
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

    // ── T8 ────────────────────────────────────────────────────────────────

    public function testAssetsNetEqualLiabilities(): void
    {
        $this->seedBaseScenario();

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        self::assertTrue($bs['checks']['balanced'], 'Σ aktiva netto == Σ pasiva.');
        self::assertSame(
            self::cents($bs['checks']['assets_net']),
            self::cents($bs['checks']['liabilities_total']),
            'Bilanční rovnice v haléřích.',
        );
        self::assertSame(self::cents(810.00), self::cents($bs['checks']['assets_net']), 'AKTIVA netto = 1110 (C.II) − 300 (B.II korekce).');

        $pav = $this->rowByCode($bs['liabilities'], 'P.A.V.');
        self::assertNotNull($pav);
        self::assertSame(self::cents(100.00), self::cents($pav['amount']), 'P.A.V. = profit_current (1000 − 900).');
        self::assertSame('A.V.', $pav['display_code'], 'display_code pasiv bez prefixu P.');
    }

    // ── T9 ────────────────────────────────────────────────────────────────

    public function testProfitInBalanceSheetEqualsIncomeStatement(): void
    {
        $this->seedBaseScenario();

        $bs  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');

        $pav = $this->rowByCode($bs['liabilities'], 'P.A.V.');
        $vh  = $this->rowByCode($vzz['rows'], 'VH');
        self::assertNotNull($pav);
        self::assertNotNull($vh);
        self::assertSame(self::cents($vh['amount']), self::cents($pav['amount']), 'VH v rozvaze (P.A.V.) == VH ve VZZ (řádek VH).');
        self::assertSame(self::cents(100.00), self::cents($vh['amount']));
        self::assertSame(self::cents(100.00), self::cents($vzz['checks']['profit_current']));
        self::assertSame(self::cents(1000.00), self::cents($vzz['checks']['net_turnover']), 'OBRAT = I. (601+602) = 1000.');
        self::assertTrue($bs['checks']['profit_matches']);
        self::assertTrue($vzz['checks']['profit_matches']);
        self::assertSame([], $bs['checks']['unmapped_accounts']);
        self::assertSame([], $vzz['checks']['unmapped_accounts']);
    }

    public function testMidYearBalanceSheetMoves431ToPriorYearsProfit(): void
    {
        $this->manual([
            self::l('221', 'debit', 100.00),
            self::l('431', 'credit', 100.00),
        ], self::YEAR . '-01-01');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::YEAR . '-06-30', 'full');
        $prior = $this->rowByCode($bs['liabilities'], 'P.A.IV.1.');
        $current = $this->rowByCode($bs['liabilities'], 'P.A.V.');

        self::assertSame(self::cents(100.00), self::cents($prior['amount']));
        self::assertSame(self::cents(0.00), self::cents($current['amount']));
        self::assertSame('431', $prior['accounts'][0]['account_code']);
    }

    /**
     * P-6 — účet 426 „Jiný výsledek hospodaření minulých let" plní VLASTNÍ řádek
     * A.IV.2. (migrace 1779), ne nadřazený A.IV.1.
     *
     * Před opravou 426 v osnově vůbec nebyl a žádný řádek na něj neukazoval: firma,
     * která si ho založila ručně, měla jeho zůstatek v `unmapped_accounts` a rozvaha
     * se jí o tu částku NEVYROVNALA. Test proto kontroluje trojici najednou —
     * bilanční rovnici, prázdné `unmapped_accounts` a křížovou kontrolu
     * A.IV. = A.IV.1. + A.IV.2.
     */
    public function testOtherRetainedEarningsHasOwnRow(): void
    {
        // Oprava nesprávnosti minulého období (§ 15a vyhl. 500/2002 Sb.) vedle
        // běžného nerozděleného zisku — obojí je „výsledek hospodaření minulých let",
        // ale vyhláška je vykazuje na dvou různých řádcích.
        $this->manual([
            self::l('221', 'debit', 700.00),
            self::l('428', 'credit', 400.00),
            self::l('426', 'credit', 300.00),
        ], self::YEAR . '-01-01');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        $group = $this->rowByCode($bs['liabilities'], 'P.A.IV.');
        $prior = $this->rowByCode($bs['liabilities'], 'P.A.IV.1.');
        $other = $this->rowByCode($bs['liabilities'], 'P.A.IV.2.');

        self::assertNotNull($other, 'Řádek A.IV.2. musí ve výkazu existovat (migrace 1779).');
        self::assertSame('A.IV.2.', $other['display_code']);
        self::assertSame(self::cents(300.00), self::cents($other['amount']), 'A.IV.2. = zůstatek 426.');
        self::assertSame('426', $other['accounts'][0]['account_code']);
        self::assertSame(self::cents(400.00), self::cents($prior['amount']), '428 zůstává na A.IV.1.');
        self::assertSame(
            self::cents($group['amount']),
            self::cents($prior['amount']) + self::cents($other['amount']),
            'Křížová kontrola: A.IV. = A.IV.1. + A.IV.2.',
        );
        self::assertSame([], $bs['checks']['unmapped_accounts'], '426 už nesmí zůstat nenamapovaný.');
        self::assertTrue($bs['checks']['balanced'], 'Rozvaha se se zůstatkem na 426 musí vyrovnat.');
    }

    // ── T10 ───────────────────────────────────────────────────────────────

    public function testCorrectionColumnGrossMinusCorrection(): void
    {
        $this->seedBaseScenario();
        // pořízení stavby → brutto na B.II.1.2.
        $this->manual([
            self::l('021', 'debit', 2000.00),
            self::l('321', 'credit', 2000.00),
        ], self::YEAR . '-07-01');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        $b2 = $this->rowByCode($bs['assets'], 'B.II.');
        self::assertNotNull($b2);
        self::assertSame(self::cents(2000.00), self::cents($b2['gross']), 'B.II. brutto = stavba 021.');
        self::assertSame(self::cents(300.00), self::cents($b2['correction']), 'Oprávky 081 ve sloupci korekce B.II.');
        self::assertSame(self::cents(1700.00), self::cents($b2['net']), 'Netto = brutto − korekce.');
        self::assertSame(
            self::cents($b2['gross']) - self::cents($b2['correction']),
            self::cents($b2['net']),
        );

        $b212 = $this->rowByCode($bs['assets'], 'B.II.1.2.');
        self::assertNotNull($b212);
        self::assertSame(self::cents(2000.00), self::cents($b212['gross']), 'Stavby (021) na řádku B.II.1.2.');
    }

    // ── T11 ───────────────────────────────────────────────────────────────

    public function testVatSaldoSwitchesSideByBalanceCondition(): void
    {
        $this->seedBaseScenario();

        // kreditní saldo 343 (210 D − 105 MD = 105 D) → pasiva P.C.II.8.5.
        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $liabilityRow = $this->rowByCode($bs['liabilities'], 'P.C.II.8.5.');
        $assetRow     = $this->rowByCode($bs['assets'], 'C.II.2.4.3.');
        self::assertNotNull($liabilityRow);
        self::assertNotNull($assetRow);
        self::assertSame(self::cents(105.00), self::cents($liabilityRow['amount']), 'Kreditní saldo 343 → Stát — daňové závazky.');
        self::assertSame(0, self::cents($assetRow['net']), 'Debetní řádek daňových pohledávek je nulový.');

        // přetočení salda na debetní (105 D − 400 MD = 295 MD) → aktiva C.II.2.4.3.
        $this->manual([
            self::l('343', 'debit', 400.00),
            self::l('321', 'credit', 400.00),
        ], self::YEAR . '-09-01');

        $bs2 = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $liabilityRow2 = $this->rowByCode($bs2['liabilities'], 'P.C.II.8.5.');
        $assetRow2     = $this->rowByCode($bs2['assets'], 'C.II.2.4.3.');
        self::assertNotNull($liabilityRow2);
        self::assertNotNull($assetRow2);
        self::assertSame(self::cents(295.00), self::cents($assetRow2['net']), 'Debetní saldo 343 → Stát — daňové pohledávky.');
        self::assertSame(0, self::cents($liabilityRow2['amount']), 'Pasivní řádek DPH po přetočení salda je nulový.');
    }

    // ── T12 ───────────────────────────────────────────────────────────────

    public function testClosingAndOffbalanceAccountsExcludedFromStatements(): void
    {
        // 701 je závěrkový účet (audit B7b) — resolveLines ho mimo 'closing'/'opening'
        // odmítne, fixture proto simuluje závěrkový zápis (701/702, source_type
        // 'closing') zvlášť; LedgerReportRepository navíc CELÝ 'closing' zápis
        // vylučuje ze statementů (R2 openingAnchor), takže cash 211 jde samostatným
        // ručním zápisem, ať zůstane ve výkazu měřitelný.
        $this->manual([
            self::l('211', 'debit', 500.00),
            self::l('602', 'credit', 500.00),
        ], self::YEAR . '-01-01');
        $this->posting->postDocument(
            $this->supplierId,
            'closing',
            null,
            [
                self::l('702', 'debit', 500.00),
                self::l('701', 'credit', 500.00),
            ],
            ['entry_date' => self::YEAR . '-01-01', 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );
        $this->manual([
            self::l('751', 'debit', 200.00),
            self::l('799', 'credit', 200.00),
        ], self::YEAR . '-02-01');

        $bs  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');

        $statementCodes = [];
        foreach (array_merge($bs['assets'], $bs['liabilities'], $vzz['rows']) as $row) {
            foreach ($row['accounts'] as $acc) {
                $statementCodes[] = (string) $acc['account_code'];
            }
        }
        foreach (['701', '751', '799'] as $excluded) {
            self::assertNotContains($excluded, $statementCodes, "Účet {$excluded} (closing/offbalance) NESMÍ být ve výkazech.");
        }

        $cash = $this->rowByCode($bs['assets'], 'C.IV.1.');
        self::assertNotNull($cash);
        self::assertSame(self::cents(500.00), self::cents($cash['gross']), 'Pokladna 211 ve výkazu zůstává.');

        // Předvaha ve výchozím zobrazení ukazuje stav PŘED uzavřením knih (rozhodnutí
        // R-1 auditu): uzávěrkový zápis je vyloučen, protože jinak vyjdou konečné
        // stavy uzavřeného roku jako nuly a účetní nemá jak dostat zůstatky
        // k rozvahovému dni. Účet 701 se v tomhle testu objevuje POUZE v uzávěrkovém
        // zápisu, takže ve výchozím pohledu být nemá.
        $tbCodes = static fn (array $tb): array => array_map(
            static fn (array $r): string => (string) $r['account_code'], $tb['rows']);

        $default = $tbCodes($this->trialBalance->build($this->supplierId, $this->periodId, null, null));
        self::assertNotContains('701', $default, 'Ve výchozím pohledu (před uzavřením) uzávěrkový zápis není.');
        foreach (['751', '799'] as $included) {
            self::assertContains($included, $default, "Podrozvahový účet {$included} v předvaze JE.");
        }

        // Po přepnutí na stav PO uzavření je předvaha úplným opisem deníku (R2)
        // a třída 7 v ní je — přesně tím lze ověřit, že uzávěrka proběhla.
        $after = $tbCodes($this->trialBalance->build($this->supplierId, $this->periodId, null, null, false, true));
        foreach (['701', '751', '799'] as $included) {
            self::assertContains($included, $after, "Účet {$included} v předvaze po uzavření JE.");
        }
    }

    // ── T13 ───────────────────────────────────────────────────────────────

    public function testScopeFiltersLevelsAndKeepsLetterRowValues(): void
    {
        $this->seedBaseScenario();

        $full  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $small = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'small');
        $micro = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'micro');

        self::assertSame('full', $full['scope']);
        self::assertSame('small', $small['scope']);
        self::assertSame('micro', $micro['scope']);

        foreach (array_merge($micro['assets'], $micro['liabilities']) as $row) {
            self::assertLessThanOrEqual(1, (int) $row['level'], 'Mikro rozvaha = jen level ≤ 1 (' . $row['row_code'] . ').');
        }
        foreach (array_merge($small['assets'], $small['liabilities']) as $row) {
            // D1 (H8, §3a odst. 2 vyhl. 500/2002): C.II.1./C.II.2. (level 3) jsou u malé
            // ÚJ povolená výjimka nad rámec level ≤ 2 — jinak vše do úrovně 2.
            if (in_array((string) $row['row_code'], ['C.II.1.', 'C.II.2.'], true)) {
                continue;
            }
            self::assertLessThanOrEqual(2, (int) $row['level'], 'Malá rozvaha = level ≤ 2 (' . $row['row_code'] . ').');
        }
        $fullMaxLevel = max(array_map(static fn (array $r): int => (int) $r['level'], $full['assets']));
        self::assertGreaterThan(2, $fullMaxLevel, 'Plný rozsah obsahuje i hlubší levely.');

        // hodnoty písmenných řádků shodné s plným rozsahem
        foreach (['AKTIVA', 'B.', 'C.'] as $code) {
            $f = $this->rowByCode($full['assets'], $code);
            $m = $this->rowByCode($micro['assets'], $code);
            $s = $this->rowByCode($small['assets'], $code);
            self::assertNotNull($f);
            self::assertNotNull($m);
            self::assertNotNull($s);
            self::assertSame(self::cents($f['net']), self::cents($m['net']), "Mikro {$code} netto == plný rozsah.");
            self::assertSame(self::cents($f['net']), self::cents($s['net']), "Malá {$code} netto == plný rozsah.");
        }
        self::assertSame(self::cents($full['checks']['assets_net']), self::cents($micro['checks']['assets_net']));

        // VZZ zkrácená (mikro i malá) = level ≤ 1, VH shodné
        $vzzFull  = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzzMicro = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'micro');
        foreach ($vzzMicro['rows'] as $row) {
            self::assertLessThanOrEqual(1, (int) $row['level'], 'Zkrácená VZZ = level ≤ 1 (' . $row['row_code'] . ').');
        }
        $vhFull  = $this->rowByCode($vzzFull['rows'], 'VH');
        $vhMicro = $this->rowByCode($vzzMicro['rows'], 'VH');
        self::assertNotNull($vhFull);
        self::assertNotNull($vhMicro);
        self::assertSame(self::cents($vhFull['amount']), self::cents($vhMicro['amount']), 'VH shodné napříč rozsahy.');
    }

    // ── T14 ───────────────────────────────────────────────────────────────

    public function testPreviousPeriodColumn(): void
    {
        $prevYear = self::YEAR - 1;
        $this->periods->create($this->supplierId, $prevYear, $prevYear . '-01-01', $prevYear . '-12-31');
        $this->manual([
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], $prevYear . '-05-10');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        self::assertNotNull($bs['prev_period'], 'Předchozí období existuje.');
        self::assertSame($prevYear, (int) $bs['prev_period']['fiscal_year']);

        $receivables = $this->rowByCode($bs['assets'], 'C.II.2.1.');
        self::assertNotNull($receivables);
        self::assertSame(self::cents(1210.00), self::cents($receivables['prev_net']), 'Loňské netto 311 v prev_net.');
        self::assertSame(self::cents(1210.00), self::cents($receivables['net']), 'Rozvahové účty kumulativně (R6) — 311 i letos.');

        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $revenueRow = $this->rowByCode($vzz['rows'], 'I.');
        self::assertNotNull($revenueRow);
        self::assertSame(self::cents(1000.00), self::cents($revenueRow['prev_amount']), 'Loňské tržby v prev_amount.');
        self::assertSame(0, self::cents($revenueRow['amount']), 'Výsledkové účty jen za běžný fiskální rok (R6).');
    }

    // ── C1 (audit 2026-07): nákladové úroky 562 → řádek J. finančního VH ──────

    /**
     * Regrese C1: financial_profit MUSÍ odečíst řádek J. (nákladové úroky, 562).
     * Scénář: tržby 602 = 1000, úrok z úvěru 562 = 200. FVH = −200, VH = 800.
     * Rozvahové P.A.V. (profitFromBalances přes všechny výsledkové účty vč. 562)
     * = 800; bez odečtu J. by VZZ VH vyšlo 1000 a nesedělo by s rozvahou o 200.
     */
    public function testFinancialResultDeductsInterestExpenseAccount562(): void
    {
        // provozní tržba za služby (602 → řádek I.)
        $this->manual([
            self::l('311', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-03-01');
        // nákladové úroky z úvěru (562 → řádek J. finančního VH)
        $this->manual([
            self::l('562', 'debit', 200.00),
            self::l('321', 'credit', 200.00),
        ], self::YEAR . '-06-30');

        $bs  = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');
        $vzz = $this->statements->incomeStatement($this->supplierId, $this->periodId, self::AS_OF, 'full');

        // řádek J. nese úrokový náklad 562
        $jRow = $this->rowByCode($vzz['rows'], 'J.');
        self::assertNotNull($jRow, 'Řádek J. (Nákladové úroky a podobné náklady) ve VZZ existuje.');
        self::assertSame(self::cents(200.00), self::cents($jRow['amount']), 'Řádek J. = úrok 562 = 200.');

        // finanční výsledek hospodaření je o úrok záporný (dřív chybně 0)
        $fvh = $this->rowByCode($vzz['rows'], 'FVH');
        self::assertNotNull($fvh);
        self::assertSame(self::cents(-200.00), self::cents($fvh['amount']), 'FVH = −J. = −200 (odečet nákladových úroků).');

        // VH ve VZZ == VH v rozvaze (P.A.V.); bez opravy by se lišily o úrok 200
        $vh  = $this->rowByCode($vzz['rows'], 'VH');
        $pav = $this->rowByCode($bs['liabilities'], 'P.A.V.');
        self::assertNotNull($vh);
        self::assertNotNull($pav);
        self::assertSame(self::cents(800.00), self::cents($vh['amount']), 'VZZ VH = 1000 − 200 úrok = 800.');
        self::assertSame(
            self::cents($pav['amount']),
            self::cents($vh['amount']),
            'VH ve VZZ == VH v rozvaze (A.V.) i pro firmu s úrokovým nákladem 562.',
        );
    }

    // ── D1 (audit 2026-07, H8): zkrácená rozvaha malé ÚJ — C.II.1./C.II.2. ─────

    /**
     * Regrese D1: §3a odst. 2 písm. b) vyhl. 500/2002 Sb. — zkrácená rozvaha malé ÚJ
     * MUSÍ obsahovat povinné položky C.II.1. (Dlouhodobé pohledávky) a C.II.2.
     * (Krátkodobé pohledávky) na úrovni 3, ačkoli jinak filtruje jen do úrovně 2.
     * Mikro rozsah (level ≤ 1) tuto výjimku nemá.
     */
    public function testSmallBalanceSheetIncludesMandatoryReceivableRows(): void
    {
        $this->seedBaseScenario(); // 311 = 1210 → C.II.2.1. (krátkodobá pohledávka)

        $small = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'small');
        $micro = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'micro');

        $cii1 = $this->rowByCode($small['assets'], 'C.II.1.');
        $cii2 = $this->rowByCode($small['assets'], 'C.II.2.');
        self::assertNotNull($cii1, 'C.II.1. (Dlouhodobé pohledávky) je v malé rozvaze — §3a/2 výjimka.');
        self::assertNotNull($cii2, 'C.II.2. (Krátkodobé pohledávky) je v malé rozvaze — §3a/2 výjimka.');
        self::assertSame(3, (int) $cii1['level'], 'C.II.1. je level 3 (nad hranicí ≤ 2).');
        self::assertSame(3, (int) $cii2['level'], 'C.II.2. je level 3 (nad hranicí ≤ 2).');
        self::assertSame(self::cents(1210.00), self::cents($cii2['net']), 'C.II.2. nese krátkodobou pohledávku 311.');

        // hlubší detail (level 4) v malé rozvaze zůstává vyřazen
        self::assertNull($this->rowByCode($small['assets'], 'C.II.2.1.'), 'Level 4 (C.II.2.1.) v malé rozvaze není.');

        // mikro rozsah výjimku §3a/2 nemá — C.II.1./C.II.2. (level 3) tam nejsou
        self::assertNull($this->rowByCode($micro['assets'], 'C.II.1.'), 'Mikro rozvaha C.II.1. nezobrazuje.');
        self::assertNull($this->rowByCode($micro['assets'], 'C.II.2.'), 'Mikro rozvaha C.II.2. nezobrazuje.');
    }

    // ── D2 (audit 2026-07, H9): zákaz kompenzace analytik přes syntetiku ──────

    /**
     * Regrese D2: §58 vyhl. 500/2002 Sb. — dvě analytiky téže syntetiky (221) opačného
     * salda se NESMÍ znettovat před vyhodnocením strany. Běžný účet (+500 000) patří
     * do aktiv, kontokorent (−200 000) do pasiv — každý zvlášť, ne jedno netto 300 000.
     */
    public function testNoCompensationSplitsSyntheticAcrossAnalytics(): void
    {
        $parentId = (int) $this->db->pdo()->query(
            "SELECT id FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '221'"
        )->fetchColumn();
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
             VALUES (?, ?, ?, "asset", "debit", 0, ?)'
        );
        $ins->execute([$this->supplierId, '221001', 'Běžný účet', $parentId]);
        $ins->execute([$this->supplierId, '221002', 'Kontokorent', $parentId]);

        // 221001 debetní saldo +500 000 (běžný účet), 221002 kreditní saldo −200 000 (kontokorent)
        $this->manual([
            self::l('221001', 'debit', 500000.00),
            self::l('321', 'credit', 500000.00),
        ], self::YEAR . '-03-01');
        $this->manual([
            self::l('321', 'debit', 200000.00),
            self::l('221002', 'credit', 200000.00),
        ], self::YEAR . '-03-02');

        $bs = $this->statements->balanceSheet($this->supplierId, $this->periodId, self::AS_OF, 'full');

        $bankAsset = $this->rowByCode($bs['assets'], 'C.IV.2.');       // Peněžní prostředky na účtech (221 debet)
        $bankLiab  = $this->rowByCode($bs['liabilities'], 'P.C.II.2.'); // Závazky k úvěrovým institucím (221 kredit)
        self::assertNotNull($bankAsset);
        self::assertNotNull($bankLiab);
        self::assertSame(self::cents(500000.00), self::cents($bankAsset['gross']), 'Běžný účet 221001 v aktivech (bez kompenzace).');
        self::assertSame(self::cents(200000.00), self::cents($bankLiab['amount']), 'Kontokorent 221002 v pasivech (§58).');

        // bilanční rovnice drží i po rozdělení salda
        self::assertTrue($bs['checks']['balanced'], 'Aktiva netto == pasiva i po per-analytickém rozdělení.');
        self::assertSame(
            self::cents($bs['checks']['assets_net']),
            self::cents($bs['checks']['liabilities_total']),
        );
        self::assertSame(self::cents(500000.00), self::cents($bs['checks']['assets_net']), 'Aktiva = 500 000 (bez kompenzace kontokorentu).');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Syntetická sada dle T8: výnos 602, náklad 518, odpisy 551+081,
     * OP 559+391, DPH 343 obě salda.
     */
    private function seedBaseScenario(): void
    {
        $this->manual([
            self::l('311', 'debit', 1210.00),
            self::l('602', 'credit', 1000.00),
            self::l('343', 'credit', 210.00),
        ], self::YEAR . '-03-01');
        $this->manual([
            self::l('518', 'debit', 500.00),
            self::l('343', 'debit', 105.00),
            self::l('321', 'credit', 605.00),
        ], self::YEAR . '-03-05');
        $this->manual([
            self::l('551', 'debit', 300.00),
            self::l('081', 'credit', 300.00),
        ], self::YEAR . '-06-30');
        $this->manual([
            self::l('559', 'debit', 100.00),
            self::l('391', 'credit', 100.00),
        ], self::YEAR . '-06-30');
    }

    /**
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @param array<string,mixed> $meta
     */
    private function manual(array $lines, string $date, array $meta = []): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'manual',
            null,
            $lines,
            array_merge(['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId], $meta),
        );
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private static function l(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => $amount];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    private function rowByCode(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ((string) $row['row_code'] === $code) {
                return $row;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
