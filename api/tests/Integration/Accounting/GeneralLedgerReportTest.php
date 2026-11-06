<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\GeneralLedgerService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Integrační test hlavní knihy (Epic F2, T5): měsíční obraty MD/D sedí per účet
 * a měsíc, KS = PS + obraty a totals MD == D. Kontroly rovnosti v haléřích.
 * Dále T6 (hledání dle protistrany/položky, follow-up feature): EXISTS filtry na
 * dodavatele/odběratele/text položky zdrojového dokladu musí omezit agregaci
 * (turnover/PS) jen na odpovídající zápisy a přitom zachovat Σ MD == Σ D.
 *
 * Vše běží v jedné transakci, kterou tearDown rollbackne. Soft-skip bez cfg.php.
 */
#[Group('integration')]
final class GeneralLedgerReportTest extends TestCase
{
    private const YEAR = 2099;

    private Connection $db;
    private PostingService $posting;
    private GeneralLedgerService $generalLedger;
    private AccountingPeriodRepository $periods;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private int $countryId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private float $vatRatePercent = 0.0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db            = $container->get(Connection::class);
            $this->posting       = $container->get(PostingService::class);
            $this->generalLedger = $container->get(GeneralLedgerService::class);
            $this->periods       = $container->get(AccountingPeriodRepository::class);
            $seeder              = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        // Izolovaný supplier (kopie FK hodnot z prvního): kumulativní PS rozvahových
        // účtů (R6) jde přes celou historii deníku, sdílený dev supplier s reálnými
        // zápisy by rozbil bilanční asserty a previousPeriod.
        $isoStmt = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $isoStmt->execute(['Izolovaný test s.r.o.', 'izolace@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $seeder->seedForSupplier($this->supplierId);
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR, self::YEAR . '-01-01', self::YEAR . '-12-31');

        // Referenční číselníky pro T6 fixtures (klienti/faktury) — country_id a
        // currency_default_id zdědil izolovaný supplier z prvního (FK bez tenant
        // vazby, viz komentář výše), vat_rate jen první existující kód.
        $supRow = $pdo->query(
            "SELECT country_id, default_currency_id, default_vat_rate_id FROM supplier WHERE id = {$this->supplierId}"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->countryId = (int) ($supRow['country_id'] ?? 0);
        $this->currencyId = (int) ($supRow['default_currency_id'] ?? 0);
        $this->vatRateId = (int) ($supRow['default_vat_rate_id'] ?? 0);
        if ($this->vatRateId === 0) {
            $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        }
        $this->vatRatePercent = (float) ($pdo->query(
            "SELECT rate_percent FROM vat_rates WHERE id = {$this->vatRateId}"
        )->fetchColumn() ?: 0);
        if ($this->countryId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí referenční číselník (country/currency/vat_rate).');
        }
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

    // ── T5 ────────────────────────────────────────────────────────────────

    public function testMonthlyTurnoversClosingBalancesAndTotals(): void
    {
        $this->manual([
            self::l('211', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-01-10');
        $this->manual([
            self::l('518', 'debit', 400.00),
            self::l('211', 'credit', 400.00),
        ], self::YEAR . '-02-05');
        $this->manual([
            self::l('211', 'debit', 250.00),
            self::l('602', 'credit', 250.00),
        ], self::YEAR . '-02-20');

        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);

        self::assertCount(12, $data['months'], 'Default rozsah = celé období (12 měsíců).');
        self::assertSame(self::YEAR . '-01', $data['months'][0]);
        self::assertSame(self::YEAR . '-12', $data['months'][11]);

        $a211 = $this->accountByCode($data['accounts'], '211');
        self::assertNotNull($a211);
        self::assertSame(self::cents(1000.00), self::cents($a211['months'][self::YEAR . '-01']['md']), '211 leden MD.');
        self::assertSame(0, self::cents($a211['months'][self::YEAR . '-01']['d']), '211 leden D.');
        self::assertSame(self::cents(250.00), self::cents($a211['months'][self::YEAR . '-02']['md']), '211 únor MD.');
        self::assertSame(self::cents(400.00), self::cents($a211['months'][self::YEAR . '-02']['d']), '211 únor D.');
        self::assertSame(0, self::cents($a211['months'][self::YEAR . '-03']['md']), 'Březen bez pohybu.');

        $a602 = $this->accountByCode($data['accounts'], '602');
        self::assertNotNull($a602);
        self::assertSame(self::cents(1000.00), self::cents($a602['months'][self::YEAR . '-01']['d']));
        self::assertSame(self::cents(250.00), self::cents($a602['months'][self::YEAR . '-02']['d']));

        // per účet: Σ měsíců == obrat; KS = PS + obraty
        foreach ($data['accounts'] as $acc) {
            $mMd = 0;
            $mD  = 0;
            foreach ($acc['months'] as $m) {
                $mMd += self::cents($m['md']);
                $mD  += self::cents($m['d']);
            }
            self::assertSame(self::cents($acc['turnover_md']), $mMd, 'Σ měsíčních MD == obrat MD (' . $acc['account_code'] . ').');
            self::assertSame(self::cents($acc['turnover_d']), $mD, 'Σ měsíčních D == obrat D (' . $acc['account_code'] . ').');

            $delta = self::cents($acc['opening_md']) - self::cents($acc['opening_d'])
                   + self::cents($acc['turnover_md']) - self::cents($acc['turnover_d']);
            $ksMd = $delta > 0 ? $delta : 0;
            $ksD  = $delta > 0 ? 0 : -$delta;
            self::assertSame($ksMd, self::cents($acc['closing_md']), 'KS MD = PS + obraty (' . $acc['account_code'] . ').');
            self::assertSame($ksD, self::cents($acc['closing_d']), 'KS D = PS + obraty (' . $acc['account_code'] . ').');
        }

        self::assertSame(self::cents(850.00), self::cents($a211['closing_md']), '211 KS = 1000 − 400 + 250.');
        self::assertSame(self::cents(1250.00), self::cents($a602['closing_d']), '602 KS D.');

        // totals MD == D (obraty i KS)
        self::assertSame(self::cents($data['totals']['turnover_md']), self::cents($data['totals']['turnover_d']), 'Totals obrat MD == D.');
        self::assertSame(self::cents($data['totals']['closing_md']), self::cents($data['totals']['closing_d']), 'Totals KS MD == D.');
        self::assertSame(self::cents(1650.00), self::cents($data['totals']['turnover_md']));
        self::assertSame(0, $data['draft_count']);
    }

    public function testOpeningBalanceWindowFromMidPeriod(): void
    {
        $this->manual([
            self::l('211', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-01-10');

        $data = $this->generalLedger->build($this->supplierId, $this->periodId, self::YEAR . '-02-01', null);

        self::assertSame(self::YEAR . '-02', $data['months'][0], 'Měsíce začínají únorem.');
        $a211 = $this->accountByCode($data['accounts'], '211');
        self::assertNotNull($a211, 'Účet s PS bez pohybu v rozsahu zůstává v knize.');
        self::assertSame(self::cents(1000.00), self::cents($a211['opening_md']), 'PS 211 = lednový pohyb.');
        self::assertSame(0, self::cents($a211['turnover_md']));
        self::assertSame(self::cents(1000.00), self::cents($a211['closing_md']), 'KS = PS + (nulové) obraty.');
    }

    public function testAllPeriodsCombinesYearsWithoutTechnicalClosingAndOpeningEntries(): void
    {
        $this->manual([
            self::l('211', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-06-10');
        $this->insertJournalEntry('closing', $this->periodId, '702', '211', 1000.00, self::YEAR . '-12-31');

        $secondPeriodId = $this->periods->create(
            $this->supplierId,
            self::YEAR + 1,
            (self::YEAR + 1) . '-01-01',
            (self::YEAR + 1) . '-12-31',
        );
        $this->periodId = $secondPeriodId;
        $this->insertJournalEntry('opening', $this->periodId, '211', '701', 1000.00, (self::YEAR + 1) . '-01-01');
        $this->manual([
            self::l('211', 'debit', 200.00),
            self::l('602', 'credit', 200.00),
        ], (self::YEAR + 1) . '-03-15');

        $data = $this->generalLedger->buildAllPeriods($this->supplierId);

        self::assertTrue($data['all_periods']);
        self::assertNull($data['period']);
        self::assertSame(self::YEAR . '-01-01', $data['from']);
        self::assertSame((self::YEAR + 1) . '-12-31', $data['to']);
        self::assertCount(24, $data['months']);
        $account = $this->accountByCode($data['accounts'], '211');
        self::assertNotNull($account);
        self::assertSame(self::cents(1200.00), self::cents($account['turnover_md']));
        self::assertSame(0, self::cents($account['turnover_d']));
        self::assertSame(self::cents(1200.00), self::cents($account['closing_md']));
    }

    /**
     * Otevírací zápis prvního dne období patří do PS, ne do lednového obratu —
     * a to i v MĚSÍČNÍM rozpadu. Dokud měsíční agregace technický zápis
     * nevylučovala, hlásil leden obrat o celý počáteční stav vyšší, než kolik
     * dával obratový sloupec téhož řádku; nesrovnalost se projevila až při
     * rozkliknutí měsíce na jednotlivé řádky deníku.
     */
    public function testOpeningEntryStaysOutOfMonthlyTurnover(): void
    {
        $this->insertJournalEntry('opening', $this->periodId, '211', '411', 5000.00, self::YEAR . '-01-01');
        $this->manual([
            self::l('211', 'debit', 1000.00),
            self::l('602', 'credit', 1000.00),
        ], self::YEAR . '-01-10');

        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);

        $a211 = $this->accountByCode($data['accounts'], '211');
        self::assertNotNull($a211);
        self::assertSame(self::cents(5000.00), self::cents($a211['opening_md']), 'Otevírací zápis = PS.');
        self::assertSame(self::cents(1000.00), self::cents($a211['turnover_md']), 'Obrat za období bez otevíracího zápisu.');
        self::assertSame(
            self::cents(1000.00),
            self::cents($a211['months'][self::YEAR . '-01']['md']),
            'Lednový obrat nesmí obsahovat otevírací zápis.',
        );

        foreach ($data['accounts'] as $acc) {
            $mMd = 0;
            $mD  = 0;
            foreach ($acc['months'] as $m) {
                $mMd += self::cents($m['md']);
                $mD  += self::cents($m['d']);
            }
            self::assertSame(self::cents($acc['turnover_md']), $mMd, 'Σ měsíčních MD == obrat MD (' . $acc['account_code'] . ').');
            self::assertSame(self::cents($acc['turnover_d']), $mD, 'Σ měsíčních D == obrat D (' . $acc['account_code'] . ').');
        }
    }

    // ── T6: hledání dle dodavatele/odběratele/položky faktury ─────────────────

    public function testVendorFilterLimitsAggregationToMatchingPurchaseInvoice(): void
    {
        $vendorId = $this->insertClient('Beta Dodavatel a.s.');
        $clientId = $this->insertClient('Alfa Klient s.r.o.');

        $invoiceId = $this->insertInvoiceWithItem($clientId, 'Konzultace Alfa', 1000.00, self::YEAR . '-03-10');
        $this->insertJournalEntry('invoice', $invoiceId, '311', '602', 1000.00, self::YEAR . '-03-10');

        $purchaseId = $this->insertPurchaseWithItem($vendorId, 'Software licence', 700.00, self::YEAR . '-04-05');
        $this->insertJournalEntry('purchase_invoice', $purchaseId, '518', '321', 700.00, self::YEAR . '-04-05');

        // Bez filtru — obě strany (vydaná i přijatá) jsou v knize.
        $all = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);
        self::assertNotNull($this->accountByCode($all['accounts'], '311'));
        self::assertNotNull($this->accountByCode($all['accounts'], '518'));

        // Filtr na dodavatele „Beta" — jen přijatá faktura (518/321); vydaná (311/602) zmizí,
        // protože EXISTS gate je binární per CELÝ zápis (žádné znásobení/rozbití agregace).
        $filtered = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['vendor' => 'beta']);
        self::assertNull($this->accountByCode($filtered['accounts'], '311'), '311 (odběratel) nesmí zůstat po filtru na dodavatele.');
        self::assertNull($this->accountByCode($filtered['accounts'], '602'), '602 (výnos vydané FV) nesmí zůstat po filtru na dodavatele.');
        $acc518 = $this->accountByCode($filtered['accounts'], '518');
        $acc321 = $this->accountByCode($filtered['accounts'], '321');
        self::assertNotNull($acc518);
        self::assertNotNull($acc321);
        self::assertSame(self::cents(700.00), self::cents($acc518['turnover_md']));
        self::assertSame(self::cents(700.00), self::cents($acc321['turnover_d']));

        // Zápis zůstává vyvážený i po filtru (Σ MD == Σ D nad přeživší podmnožinou zápisů).
        self::assertSame(
            self::cents($filtered['totals']['turnover_md']),
            self::cents($filtered['totals']['turnover_d']),
            'EXISTS na úrovni zápisu nesmí rozbít Σ MD == Σ D.',
        );

        // Neexistující dodavatel → žádný účet v knize.
        $noMatch = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['vendor' => 'Neexistuje s.r.o.']);
        self::assertSame([], $noMatch['accounts']);
    }

    public function testClientFilterLimitsAggregationToMatchingInvoice(): void
    {
        $vendorId = $this->insertClient('Gama Dodavatel s.r.o.');
        $clientId = $this->insertClient('Delta Klient a.s.');

        $invoiceId = $this->insertInvoiceWithItem($clientId, 'Vývoj software', 500.00, self::YEAR . '-05-12');
        $this->insertJournalEntry('invoice', $invoiceId, '311', '602', 500.00, self::YEAR . '-05-12');

        $purchaseId = $this->insertPurchaseWithItem($vendorId, 'Kancelářské potřeby', 300.00, self::YEAR . '-06-01');
        $this->insertJournalEntry('purchase_invoice', $purchaseId, '518', '321', 300.00, self::YEAR . '-06-01');

        // Filtr na odběratele „Delta" — jen vydaná faktura (311/602); přijatá (518/321) zmizí.
        $filtered = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['client' => 'delta']);
        self::assertNull($this->accountByCode($filtered['accounts'], '518'), '518 (dodavatel) nesmí zůstat po filtru na odběratele.');
        $acc311 = $this->accountByCode($filtered['accounts'], '311');
        $acc602 = $this->accountByCode($filtered['accounts'], '602');
        self::assertNotNull($acc311);
        self::assertNotNull($acc602);
        self::assertSame(self::cents(500.00), self::cents($acc311['turnover_md']));
        self::assertSame(self::cents(500.00), self::cents($acc602['turnover_d']));
        self::assertSame(
            self::cents($filtered['totals']['turnover_md']),
            self::cents($filtered['totals']['turnover_d']),
        );
    }

    public function testItemFilterMatchesInvoiceOrPurchaseItemText(): void
    {
        $vendorId = $this->insertClient('Epsilon Dodavatel s.r.o.');
        $clientId = $this->insertClient('Zeta Klient a.s.');

        $invoiceId = $this->insertInvoiceWithItem($clientId, 'Konzultace Lenovo notebooky', 800.00, self::YEAR . '-07-15');
        $this->insertJournalEntry('invoice', $invoiceId, '311', '602', 800.00, self::YEAR . '-07-15');

        $purchaseId = $this->insertPurchaseWithItem($vendorId, 'Software licence Adobe', 450.00, self::YEAR . '-08-20');
        $this->insertJournalEntry('purchase_invoice', $purchaseId, '518', '321', 450.00, self::YEAR . '-08-20');

        // „lenovo" hledá jen v položce vydané faktury → jen 311/602.
        $byInvoiceItem = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['item' => 'lenovo']);
        self::assertNotNull($this->accountByCode($byInvoiceItem['accounts'], '311'));
        self::assertNull($this->accountByCode($byInvoiceItem['accounts'], '518'));

        // „licence" hledá jen v položce přijaté faktury → jen 518/321.
        $byPurchaseItem = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, ['item' => 'licence']);
        self::assertNotNull($this->accountByCode($byPurchaseItem['accounts'], '518'));
        self::assertNull($this->accountByCode($byPurchaseItem['accounts'], '311'));
        self::assertSame(
            self::cents($byPurchaseItem['totals']['turnover_md']),
            self::cents($byPurchaseItem['totals']['turnover_d']),
        );
    }

    public function testDetailedExportAcrossBatchesMatchesSummaryAndChronologicalBalances(): void
    {
        $this->manual([self::l('518', 'debit', 10.11), self::l('211', 'credit', 10.11)], '2099-01-01');
        // Záměrně obrácené pořadí vložení; dva řádky stejného účtu v jednom zápisu.
        $this->manual([self::l('518', 'debit', 4.44), self::l('211', 'credit', 4.44)], '2099-03-02');
        $this->manual([self::l('518', 'debit', 1.11), self::l('518', 'debit', 2.22), self::l('211', 'credit', 3.33)], '2099-03-01');
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, '2099-02-01', null);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $events = iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
        self::assertSame(['start', 'line', 'line', 'line', 'end'], array_column($events, 'kind'));
        self::assertEquals(10.11, $events[0]['account']['opening_md']);
        self::assertEquals([11.22, 13.44, 17.88], array_column(array_column(array_slice($events, 1, 3), 'item'), 'balance'));
        self::assertSame('211', $events[1]['item']['counter_accounts']);
        self::assertEquals(17.88, $events[4]['account']['closing_md']);
    }

    public function testDetailedExportUsesSameSourceAndDimensionFilters(): void
    {
        $vendor = $this->insertClient('Syntetický dodavatel exportu');
        $purchase = $this->insertPurchaseWithItem($vendor, 'Testovaný materiál', 12.34, '2099-03-01');
        $entry = $this->insertJournalEntry('purchase_invoice', $purchase, '518', '321', 12.34, '2099-03-01');
        $this->manual([self::l('518', 'debit', 99), self::l('211', 'credit', 99)], '2099-03-02');
        $this->db->pdo()->prepare('UPDATE journal_entry_lines SET cost_center = ? WHERE supplier_id = ? AND entry_id = ? AND side = ?')
            ->execute(['DEMO', $this->supplierId, $entry, 'debit']);
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        foreach ([['vendor' => 'Syntetický dodavatel exportu'], ['item' => 'Testovaný materiál'],
            ['dimension' => new \MyInvoice\Service\Accounting\Dimension\DimensionFilter(999999, 999999, [999999], ['DEMO'])]] as $filters) {
            $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, $filters);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
            $events = iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1));
            self::assertCount(3, $events);
            self::assertEquals(12.34, $events[1]['item']['amount']);
        }
    }

    public function testDetailedExportAllPeriodsAndEmptyRange(): void
    {
        $this->manual([self::l('518', 'debit', 10), self::l('211', 'credit', 10)], '2099-01-01');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        foreach ([false, true] as $afterClosing) {
            $data = $this->generalLedger->buildAllPeriods($this->supplierId, null, null, false, [], $afterClosing);
            $events = iterator_to_array($detail->events($this->supplierId, $data, [], $afterClosing, 1));
            self::assertSame(['start', 'line', 'end', 'start', 'line', 'end'], array_column($events, 'kind'));
            $empty = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '9', '9');
            self::assertSame([], iterator_to_array($detail->events($this->supplierId, $empty, [], $afterClosing)));
        }
    }

    /**
     * Za všechna období patří otevírací zápis druhého roku do rozpisu jen v knize po
     * uzávěrce (vedle závěrkového zápisu prvního roku). Před uzávěrkou ho souhrn
     * vynechává, rozpis tedy taky, jinak by rozpis 211 hlásil nesoulad.
     */
    public function testDetailedExportAllPeriodsKeepsSecondYearOpeningOnlyAfterClosing(): void
    {
        $this->manual([self::l('211', 'debit', 1000.00), self::l('602', 'credit', 1000.00)], self::YEAR . '-06-10');
        $this->insertJournalEntry('closing', $this->periodId, '702', '211', 1000.00, self::YEAR . '-12-31');
        $this->periodId = $this->periods->create($this->supplierId, self::YEAR + 1, (self::YEAR + 1) . '-01-01', (self::YEAR + 1) . '-12-31');
        $this->insertJournalEntry('opening', $this->periodId, '211', '701', 1000.00, (self::YEAR + 1) . '-01-01');
        $this->manual([self::l('211', 'debit', 200.00), self::l('602', 'credit', 200.00)], (self::YEAR + 1) . '-03-15');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $expected = [
            false => [[self::YEAR . '-06-10', 'debit', 1000.0], [(self::YEAR + 1) . '-03-15', 'debit', 200.0]],
            true => [
                [self::YEAR . '-06-10', 'debit', 1000.0],
                [self::YEAR . '-12-31', 'credit', 1000.0],
                [(self::YEAR + 1) . '-01-01', 'debit', 1000.0],
                [(self::YEAR + 1) . '-03-15', 'debit', 200.0],
            ],
        ];
        foreach ([false, true] as $afterClosing) {
            $data = $this->generalLedger->buildAllPeriods($this->supplierId, null, null, false, [], $afterClosing);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '211', '211');
            $lines = array_map(
                static fn (array $e): array => [$e['item']['entry_date'], $e['item']['side'], (float) $e['item']['amount']],
                array_values(array_filter(
                    iterator_to_array($detail->events($this->supplierId, $data, [], $afterClosing, 1), false),
                    static fn (array $e): bool => $e['kind'] === 'line',
                )),
            );
            self::assertSame($expected[$afterClosing], $lines, $afterClosing ? 'po uzávěrce' : 'před uzávěrkou');
        }
    }

    public function testDetailedExportKeepsRedStornoEvenWhenAccountTurnoverIsZero(): void
    {
        $this->manual([self::l('518', 'debit', 12.34), self::l('211', 'credit', 12.34)], '2099-03-01');
        $this->manual([
            self::l('518', 'debit', 12.34) + ['is_red_storno' => true],
            self::l('211', 'credit', 12.34) + ['is_red_storno' => true],
        ], '2099-03-02');
        $filters = [];
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, $filters, false, true);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $events = iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1));
        self::assertSame(['start', 'line', 'line', 'end'], array_column($events, 'kind'));
        self::assertEquals(-12.34, $events[2]['item']['amount']);
        self::assertEquals(0, $events[3]['account']['closing_md']);
    }

    public function testDetailedExportShowsOwnPairAsCounterAccount(): void
    {
        // Pokladní doklad o dvou dvojicích: rozpis 211 má u každého řádku jen protiúčet svého páru.
        $this->manual([self::l('501', 'debit', 300), self::l('211', 'credit', 300), self::l('513', 'debit', 700), self::l('211', 'credit', 700)], '2099-07-31');
        $filters = [];
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, $filters, false, true);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '211', '211');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $lines = array_column(array_column(array_filter(iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1)), static fn (array $e): bool => $e['kind'] === 'line'), 'item'), 'counter_accounts', 'amount');
        self::assertSame(['300' => '501', '700' => '513'], array_combine(array_map(static fn ($k) => (string) (int) $k, array_keys($lines)), array_values($lines)));
    }

    public function testDetailedExportCounterAccountOfRedStornoUsesEffectiveSide(): void
    {
        $this->manual([
            self::l('518', 'debit', 10) + ['is_red_storno' => true],
            self::l('501', 'debit', 5) + ['is_red_storno' => true],
            self::l('211', 'credit', 15) + ['is_red_storno' => true],
        ], '2099-03-02');
        $filters = [];
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, $filters, false, true);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $events = iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1));
        self::assertEquals(-10, $events[1]['item']['amount']);
        // Účinně je 518 na straně D, protiúčtem je tedy 211 (účinně MD), ne 501.
        self::assertSame('211', $events[1]['item']['counter_accounts']);
    }

    public function testDetailedExportRedStornoUnderDimensionWithZeroShareKeepsEffectiveSide(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,'test_split','Synthetic split','custom')")->execute([$this->supplierId]);
        $type = (int) $pdo->lastInsertId();
        $values = [];
        foreach (['a', 'b'] as $code) {
            $pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                ->execute([$type, $this->supplierId, $code, 'Synthetic ' . $code]);
            $values[$code] = (int) $pdo->lastInsertId();
        }
        $entry = $this->manual([
            self::l('518', 'debit', 0.01) + ['is_red_storno' => true],
            self::l('501', 'debit', 5) + ['is_red_storno' => true],
            self::l('211', 'credit', 5.01) + ['is_red_storno' => true],
        ], '2099-03-02');
        // 0,01 Kč napůl: jeden díl se zaokrouhlí na 0,01, druhý na 0,00.
        foreach ($values as $value) {
            $pdo->prepare("INSERT INTO journal_entry_line_dimension_splits (line_id,dimension_type_id,dimension_value_id,supplier_id,share)
                SELECT l.id, ?, ?, l.supplier_id, 0.5 FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
                 WHERE l.entry_id = ? AND a.account_code = '518'")->execute([$type, $value, $entry]);
        }
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $amounts = [];
        foreach ($values as $value) {
            $filters = ['dimension' => new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($type, $value, [$value])];
            $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, $filters, false, true);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
            foreach (iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1)) as $event) {
                if ($event['kind'] !== 'line') continue;
                $amounts[] = (float) $event['item']['amount'];
                self::assertSame('211', $event['item']['counter_accounts']);
            }
        }
        self::assertContains(0.0, $amounts, 'Test musí projít i díl rozpadu 0,00 Kč.');
    }

    public function testSummaryWithoutExportOptionIsUnchanged(): void
    {
        $this->manual([self::l('518', 'debit', 12.34), self::l('211', 'credit', 12.34)], '2099-03-01');
        $this->manual([self::l('518', 'debit', 12.34) + ['is_red_storno' => true], self::l('211', 'credit', 12.34) + ['is_red_storno' => true]], '2099-03-02');
        $this->manual([self::l('501', 'debit', 7), self::l('211', 'credit', 7)], '2099-04-01');
        foreach ([false, true] as $analytics) {
            $plain = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, $analytics);
            $withZero = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, $analytics, [], false, true);
            // Souhrn bez volby exportu: žádné nové klíče a celé rozmezí účtů = beze změny.
            $selected = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($plain);
            self::assertSame($plain['accounts'], $selected['accounts']);
            self::assertEquals($plain['totals'], $selected['totals']);
            foreach ($plain['accounts'] as $a) self::assertArrayNotHasKey('line_count', $a);
            // Volba exportu jen přidá účty s nulovým obratem a pohybem (storno) a počet řádků.
            $strip = static fn (array $a): array => array_diff_key($a, ['line_count' => true]);
            $extra = array_values(array_udiff(array_map($strip, $withZero['accounts']), $plain['accounts'], static fn ($x, $y) => strcmp($x['account_code'], $y['account_code'])));
            self::assertSame(['518'], array_column($extra, 'account_code'));
            self::assertSame(array_column($plain['accounts'], null, 'account_code'), array_intersect_key(array_column(array_map($strip, $withZero['accounts']), null, 'account_code'), array_column($plain['accounts'], null, 'account_code')));
            self::assertEquals($plain['totals'], $withZero['totals']);
        }
    }

    public function testDetailedExportRejectsRowsOutOfSummaryOrder(): void
    {
        $this->manual([self::l('518', 'debit', 10), self::l('211', 'credit', 10)], '2099-03-01');
        $this->manual([self::l('501', 'debit', 5), self::l('211', 'credit', 5)], '2099-03-02');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
        // Rozpis jde po kódech účtů jako souhrn; řádek účtu, který v pořadí souhrnu už prošel, je nesoulad.
        $data['accounts'] = array_reverse($data['accounts']);
        try {
            iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
            self::fail('Řádek mimo pořadí souhrnu musí skončit chybou.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('ledger_detail_mismatch', $e->errorCode);
        }
    }

    public function testDetailedExportOpeningClosingAndAccountWithOpeningOnly(): void
    {
        $this->insertJournalEntry('opening', $this->periodId, '211', '701', 100, '2099-01-01');
        $this->insertJournalEntry('closing', $this->periodId, '702', '211', 100, '2099-12-31');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        foreach ([false, true] as $afterClosing) {
            $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, [], $afterClosing);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '211', '211');
            $events = iterator_to_array($detail->events($this->supplierId, $data, [], $afterClosing, 1));
            self::assertCount($afterClosing ? 3 : 2, $events);
            self::assertEquals(100, $events[0]['account']['opening_md']);
            self::assertEquals($afterClosing ? 0 : 100, $events[count($events) - 1]['account']['closing_md']);
        }
    }

    public function testDetailedExportAnalyticsAndSyntheticRollupAgree(): void
    {
        $this->db->pdo()->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
            SELECT supplier_id, ?, ?, account_type, normal_side, 0, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?")
            ->execute(['518.123', 'Syntetická analytika pro test', $this->supplierId, '518']);
        $this->manual([self::l('518.123', 'debit', 12.34), self::l('211', 'credit', 12.34)], '2099-03-01');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        foreach ([false, true] as $analytics) {
            $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, $analytics);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
            $events = iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
            self::assertCount(3, $events);
            self::assertSame($analytics ? '518.123' : '518', $events[0]['account']['account_code']);
            self::assertSame('518.123', $events[1]['item']['account_code']);
            self::assertEquals(12.34, $events[2]['account']['closing_md']);
        }
    }

    public function testDetailedExportReadsChartDefinitionsLikeSummary(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
            SELECT supplier_id, ?, ?, account_type, normal_side, 0, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?")
            ->execute(['518.124', 'Syntetická analytika s pozdější definicí', $this->supplierId, '518']);
        $pdo->prepare("INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, parent_id)
            SELECT supplier_id, ?, ?, account_type, normal_side, 0, id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?")
            ->execute(['518.123', 'Syntetická analytika bez historie', $this->supplierId, '518']);
        $this->manual([self::l('518.123', 'debit', 10), self::l('211', 'credit', 10)], '2099-03-01');
        $this->manual([self::l('518.124', 'debit', 5), self::l('211', 'credit', 5)], '2099-03-02');
        // Definice analytiky platí až po konci období: souhrn ji k datu `to` nevidí.
        $row = $pdo->query("SELECT * FROM chart_of_accounts WHERE supplier_id = {$this->supplierId} AND account_code = '518.124'")->fetch(PDO::FETCH_ASSOC);
        $definition = \MyInvoice\Repository\AccountDefinitionRepository::definition($row);
        $pdo->prepare('INSERT INTO accounting_account_definition_history (supplier_id, account_id, effective_from, name, normal_side, tax_deductibility, is_active, definition_json, definition_hash, evidence, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))')->execute([
            $this->supplierId, (int) $row['id'], '2100-01-01', ...array_values($definition),
            \MyInvoice\Repository\AccountDefinitionRepository::encode($definition),
            \MyInvoice\Repository\AccountDefinitionRepository::definitionHash($this->supplierId, (int) $row['id'], '2100-01-01', $definition),
            'Syntetický test',
        ]);
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
        $events = iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
        self::assertSame(['start', 'line', 'end'], array_column($events, 'kind'));
        self::assertEquals(10, $events[2]['account']['closing_md']);
    }

    public function testDetailedExportMismatchCarriesAccountForLog(): void
    {
        $this->manual([self::l('518', 'debit', 10), self::l('211', 'credit', 10)], '2099-03-01');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
        $data['accounts'][0]['turnover_md'] += 0.01;
        try {
            iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
            self::fail('Nesoulad rozpisu se souhrnem musí skončit chybou.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('ledger_detail_mismatch', $e->errorCode);
            self::assertSame('518', $e->context['account_code']);
            self::assertSame(1000, $e->context['detail_cents']['md']);
        }
    }

    public function testDetailedExportMismatchCatchesLineCountWhenAmountsCancelOut(): void
    {
        // Zápis a jeho červené storno: částky účtu jsou nulové, chybějící nebo zdvojenou dvojici
        // pozná jen počet řádků.
        $this->manual([self::l('518', 'debit', 10), self::l('211', 'credit', 10)], '2099-03-01');
        $this->manual([self::l('518', 'debit', 10) + ['is_red_storno' => true], self::l('211', 'credit', 10) + ['is_red_storno' => true]], '2099-03-02');
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, [], false, true);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
        self::assertSame(2, $data['accounts'][0]['line_count']);
        iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
        $data['accounts'][0]['line_count'] = 4;
        try {
            iterator_to_array($detail->events($this->supplierId, $data, [], false, 1));
            self::fail('Nesoulad počtu řádků musí skončit chybou i při sedících částkách.');
        } catch (\MyInvoice\Service\Accounting\Reports\ReportException $e) {
            self::assertSame('ledger_detail_mismatch', $e->errorCode);
            self::assertSame(['detail' => 2, 'summary' => 4], $e->context['lines']);
        }
    }

    public function testDetailedExportCountsLinesForSizeLimit(): void
    {
        $this->manual([self::l('518', 'debit', 1), self::l('211', 'credit', 1)], '2099-03-01');
        $this->manual([self::l('518', 'debit', 2), self::l('211', 'credit', 2)], '2099-03-02');
        $this->manual([self::l('518', 'debit', 3), self::l('211', 'credit', 3)], '2099-03-03');
        $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, false, [], false, true);
        $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '518', '518');
        self::assertSame(3, $data['accounts'][0]['line_count']);
        $summary = $this->generalLedger->build($this->supplierId, $this->periodId, null, null);
        self::assertArrayNotHasKey('line_count', $summary['accounts'][0], 'Souhrn hlavní knihy se nemění.');
    }

    public function testDetailedExportWithTwoDimensionsMatchesSummary(): void
    {
        $pdo = $this->db->pdo();
        $ids = [];
        foreach (['test_project' => ['a', 'b'], 'test_centre' => ['x', 'y']] as $typeCode => $values) {
            $pdo->prepare("INSERT INTO dimension_types (supplier_id,code,name,kind) VALUES (?,?,?,'custom')")
                ->execute([$this->supplierId, $typeCode, 'Synthetic ' . $typeCode]);
            $ids[$typeCode] = ['id' => (int) $pdo->lastInsertId()];
            foreach ($values as $code) {
                $pdo->prepare('INSERT INTO dimension_values (type_id,supplier_id,code,name) VALUES (?,?,?,?)')
                    ->execute([$ids[$typeCode]['id'], $this->supplierId, $code, 'Synthetic ' . $code]);
                $ids[$typeCode][$code] = (int) $pdo->lastInsertId();
            }
        }
        foreach ([[100, 'a', 'x'], [40, 'a', 'y'], [7, 'b', 'x']] as $i => [$amount, $project, $centre]) {
            $entry = $this->manual([self::l('518', 'debit', $amount), self::l('211', 'credit', $amount)], sprintf('2099-03-%02d', $i + 1));
            foreach (['test_project' => $project, 'test_centre' => $centre] as $typeCode => $value) {
                $pdo->prepare("INSERT INTO journal_entry_line_dimensions (supplier_id,line_id,dimension_type_id,dimension_value_id)
                    SELECT supplier_id, id, ?, ? FROM journal_entry_lines WHERE entry_id = ? AND side = 'debit'")
                    ->execute([$ids[$typeCode]['id'], $ids[$typeCode][$value], $entry]);
            }
        }
        $filter = \MyInvoice\Service\Accounting\Dimension\DimensionFilter::all([
            new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($ids['test_project']['id'], $ids['test_project']['a'], [$ids['test_project']['a']]),
            new \MyInvoice\Service\Accounting\Dimension\DimensionFilter($ids['test_centre']['id'], $ids['test_centre']['x'], [$ids['test_centre']['x']]),
        ]);
        $filters = ['dimension' => $filter];
        $detail = Bootstrap::buildContainer()->get(\MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::class);
        foreach ([false, true] as $analytics) {
            $data = $this->generalLedger->build($this->supplierId, $this->periodId, null, null, $analytics, $filters, false, true);
            $data = \MyInvoice\Service\Accounting\Reports\GeneralLedgerDetailService::selectAccounts($data, '5', '5');
            self::assertSame(1, $data['accounts'][0]['line_count']);
            $events = iterator_to_array($detail->events($this->supplierId, $data, $filters, false, 1));
            self::assertSame(['start', 'line', 'end'], array_column($events, 'kind'));
            self::assertEquals(100, $events[1]['item']['amount']);
            self::assertEquals(100, $events[2]['account']['closing_md']);
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    // ── T6 fixtures — přímé INSERTy (mimo PostingService, mimo skutečnou VAT
    // konzistenci — testuje se jen filtr GL agregace, ne účetní validita dokladu). ──

    private function insertClient(string $companyName): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $companyName, 'Testovací 1', 'Praha', '11000',
            $this->countryId, 'gl-test-' . (++$this->seq) . '@example.com', $this->currencyId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertInvoiceWithItem(int $clientId, string $itemDescription, float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $seq = ++$this->seq;
        $pdo->prepare(
            'INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, booked_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'invoice', 'GLI' . $seq, $clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, 'issued', $amount, 0.00, $amount, $date . ' 10:00:00', $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, 0, ?, 0)'
        )->execute([$invoiceId, $itemDescription, $amount, $this->vatRateId, $this->vatRatePercent, $amount, $amount]);

        return $invoiceId;
    }

    private function insertPurchaseWithItem(int $vendorId, string $itemDescription, float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $seq = ++$this->seq;
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, vendor_snapshot, total_without_vat, total_vat,
                 total_with_vat, status, booked_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $vendorId, 'GLP' . $seq, 'VEN-' . $seq, 'invoice', $date, $date,
            $date, $date, $this->currencyId, '{}', $amount, 0.00,
            $amount, 'booked', $date . ' 10:00:00', $this->userId,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, "ks", ?, ?, ?, ?, 0, ?, 0)'
        )->execute([$purchaseId, $itemDescription, $amount, $this->vatRateId, $this->vatRatePercent, $amount, $amount]);

        return $purchaseId;
    }

    private function accountId(string $code): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ?');
        $stmt->execute([$this->supplierId, $code]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** Přímo vložený vyvážený zápis navázaný na zdrojový doklad (mimo PostingService). */
    private function insertJournalEntry(string $sourceType, int $sourceId, string $debitCode, string $creditCode, float $amount, string $date): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO journal_entries (supplier_id, period_id, entry_date, source_type, source_id, posted_at, posted_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $this->periodId, $date, $sourceType, $sourceId, $date . ' 10:00:00', $this->userId]);
        $entryId = (int) $pdo->lastInsertId();

        $debitAccountId = $this->accountId($debitCode);
        $creditAccountId = $this->accountId($creditCode);
        self::assertGreaterThan(0, $debitAccountId, "Účet {$debitCode} nenalezen v osnově testovacího suppliera.");
        self::assertGreaterThan(0, $creditAccountId, "Účet {$creditCode} nenalezen v osnově testovacího suppliera.");

        $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, "debit", ?, 1)')
            ->execute([$entryId, $this->supplierId, $debitAccountId, $amount]);
        $pdo->prepare('INSERT INTO journal_entry_lines (entry_id, supplier_id, account_id, side, amount, line_no) VALUES (?, ?, ?, "credit", ?, 2)')
            ->execute([$entryId, $this->supplierId, $creditAccountId, $amount]);

        return $entryId;
    }

    /**
     * @param list<array<string,mixed>> $accounts
     * @return array<string,mixed>|null
     */
    private function accountByCode(array $accounts, string $code): ?array
    {
        foreach ($accounts as $acc) {
            if ((string) $acc['account_code'] === $code) {
                return $acc;
            }
        }
        return null;
    }

    private static function cents(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }
}
