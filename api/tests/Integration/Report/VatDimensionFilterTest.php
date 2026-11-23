<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Bootstrap;
use MyInvoice\Http\InvoiceListFilters;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Report\DphBookBuilder;
use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KhEvidenceService;
use MyInvoice\Service\Report\VatDimensionReportService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Filtry podle dimenzí v seznamech faktur, v knize DPH a soupisu KH a sestava „DPH podle
 * dimenze": doklad vyhovuje seznamu podle hlavičky, položky i rozpadu (i podřízené hodnoty),
 * evidence DPH bere jen díl řádků hodnoty, součet sestavy sedí na evidenci i přiznání a obrat
 * 343 nepočítá úhrady finančnímu úřadu ani počáteční stavy. Vše v jedné transakci.
 */
#[Group('integration')]
final class VatDimensionFilterTest extends TestCase
{
    private const YEAR = 2093;
    private const MONTH = 10;

    private Connection $db;
    private DimensionService $dimensions;
    private \Psr\Container\ContainerInterface $container;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $typeId = 0;
    private bool $inTx = false;

    /** @var array<string,int> */
    private array $v = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->dimensions = $this->container->get(DimensionService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare("UPDATE supplier SET is_vat_payer = 1, is_identified = 0, dic = 'CZ12345678', vat_period = 'monthly',
                              accounting_mode = 'double_entry', supplier_group_id = NULL WHERE id = ?")
            ->execute([$this->supplierId]);
        $this->dimensions->setEnabled($this->supplierId, true);
        $this->typeId = $this->dimensions->ensureDefaultTypes($this->supplierId, ['stredisko'])['cost_center'];
        $this->v['parent'] = $this->value('VDF-RODIC');
        $this->v['child'] = $this->value('VDF-DITE', $this->v['parent']);
        $this->v['x'] = $this->value('VDF-X');
    }

    protected function tearDown(): void
    {
        \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared();
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testInvoiceListsMatchHeaderItemAndSplitIncludingDescendants(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $vend = $this->client('Dodavatel VDF', 'CZ22222220', vendor: true);
        $date = $this->date(5);
        $header = $this->sale('VDF-I1', $cust, $date, [[1000, 210]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $header, [$this->typeId => $this->v['child']], null);
        $item = $this->sale('VDF-I2', $cust, $date, [[100, 21], [200, 42]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $item, [], [2 => [$this->typeId => $this->v['x']]]);
        $split = $this->sale('VDF-I3', $cust, $date, [[400, 84]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $split, [], null, false,
            [0 => [$this->typeId => [['value_id' => $this->v['x'], 'share' => 0.25], ['value_id' => $this->v['child'], 'share' => 0.75]]]]);
        $parent = $this->sale('VDF-I4', $cust, $date, [[50, 10.5]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $parent, [$this->typeId => $this->v['parent']], null);
        $none = $this->sale('VDF-I5', $cust, $date, [[70, 14.7]]);

        $issued = fn (string $ids, ?string $descendants = null): array => $this->issuedIds($ids, $descendants);
        self::assertSame($this->sorted([$header, $split, $parent]), $issued((string) $this->v['parent']), 'Rodič včetně podřízených: hlavička, rozpad i rodič sám.');
        self::assertSame([$parent], $issued((string) $this->v['parent'], '0'), 'Bez podřízených jen doklad s hodnotou rodiče.');
        self::assertSame($this->sorted([$item, $split]), $issued((string) $this->v['x']), 'Hodnota na položce i v rozpadu.');
        self::assertSame($this->sorted([$header, $item, $split]), $issued($this->v['x'] . ',' . $this->v['child']), 'Hodnoty stejného typu stačí kterákoli.');
        self::assertNotContains($none, $issued((string) $this->v['parent']));

        $p1 = $this->purchase('VDF-P1', $vend, $date, [[300, 63], [100, 21]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $p1, [], [1 => [$this->typeId => $this->v['child']]]);
        $p2 = $this->purchase('VDF-P2', $vend, $date, [[500, 105]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $p2, [$this->typeId => $this->v['x']], null);
        self::assertSame([$p1], $this->purchaseIds((string) $this->v['parent']));
        self::assertSame([$p2], $this->purchaseIds((string) $this->v['x']));

        // Volající bez dimenzí filtr neztratí potichu, neplatný vstup je validační chyba.
        $request = $this->listRequest((string) $this->v['x'], null);
        self::assertIsString(InvoiceListFilters::issued($request));
        self::assertIsString(InvoiceListFilters::issued($this->listRequest('abc', null), $this->dimensions));
        self::assertIsString(InvoiceListFilters::purchase($this->listRequest('999999999', null), $this->dimensions));
    }

    public function testVatBookAndKhSoupisTakeOnlyTheShareOfASplitDocument(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        // Položka 1 na X, položka 2 bez vlastní hodnoty (hlavička = dítě).
        $items = $this->sale('VDF-K1', $cust, $this->date(3), [[10000, 2100], [5000, 1050]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $items, [$this->typeId => $this->v['child']], [1 => [$this->typeId => $this->v['x']]]);
        // Rozpad hlavičky 60 % X / 40 % dítě.
        $split = $this->sale('VDF-K2', $cust, $this->date(4), [[1000.01, 210.01]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $split, [], null, false,
            [0 => [$this->typeId => [['value_id' => $this->v['x'], 'share' => 0.6], ['value_id' => $this->v['child'], 'share' => 0.4]]]]);

        $book = $this->container->get(DphBookBuilder::class);
        $all = $book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $x = $book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null, $this->dimensions->filter($this->supplierId, $this->v['x']));
        $rest = $book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null, $this->dimensions->filter($this->supplierId, $this->v['parent']));

        self::assertSame(10600.01, round($x['totals']['issued']['base'], 2), 'X = celá položka 1 + 60 % rozpadu.');
        self::assertSame(2226.01, round($x['totals']['issued']['vat'], 2));
        self::assertSame(5400.00, round($rest['totals']['issued']['base'], 2), 'Dítě (přes rodiče) = položka 2 + 40 % rozpadu.');
        self::assertSame(1134.00, round($rest['totals']['issued']['vat'], 2));
        self::assertSame(round($all['totals']['issued']['vat'], 2), round($x['totals']['issued']['vat'] + $rest['totals']['issued']['vat'], 2), 'Díly hodnot dávají celý doklad na haléř.');
        self::assertSame($this->v['x'], $x['dimension']['value_id']);
        self::assertNull($all['dimension']);

        // Soupis KH: zařazení podle CELÉHO dokladu (oba nad 10 000 Kč jdou do A.4 jen VDF-K1),
        // částky jen díl hodnoty.
        $kh = $this->container->get(KhEvidenceService::class);
        $soupis = $kh->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'all', $this->dimensions->filter($this->supplierId, $this->v['parent']));
        $a4 = array_column($soupis['sections']['A.4']['rows'], null, 'doc_number');
        self::assertSame(5000.0, (float) $a4['VDF-K1']['base21'], 'A.4 nese jen díl dokladu připadající na hodnotu.');
        self::assertSame($this->v['parent'], $soupis['dimension']['value_id']);
        $a5 = array_column($soupis['sections']['A.5']['rows'], null, 'doc_number');
        self::assertSame(400.0, (float) $a5['VDF-K2']['base21'], 'Doklad pod limitem zůstává v A.5 i s dílem.');

        // Filtr přes víc typů evidence DPH neumí — chyba, ne tiché ignorování.
        $project = $this->dimensions->ensureDefaultTypes($this->supplierId, ['projekt'])['project'];
        $projectValue = (int) $this->dimensions->createValue($this->supplierId, $project, ['code' => 'VDF-PRJ', 'name' => 'Projekt VDF'])['id'];
        $this->expectException(DimensionException::class);
        $book->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null, $this->dimensions->filters($this->supplierId, [$this->v['x'], $projectValue]));
    }

    public function testReportSumsToTheReturnIncludingDocumentsWithoutDimension(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $vend = $this->client('Dodavatel VDF', 'CZ22222220', vendor: true);
        $s1 = $this->sale('VDF-R1', $cust, $this->date(2), [[1234.56, 259.26], [99.99, 21.0]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $s1, [$this->typeId => $this->v['x']], [2 => [$this->typeId => $this->v['child']]]);
        $s2 = $this->sale('VDF-R2', $cust, $this->date(3), [[333.33, 70.0]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $s2, [], null, false, [0 => [$this->typeId => [
            ['value_id' => $this->v['x'], 'share' => 0.5], ['value_id' => $this->v['child'], 'share' => 0.25], ['value_id' => $this->v['parent'], 'share' => 0.25],
        ]]]);
        $this->sale('VDF-R3', $cust, $this->date(4), [[500, 105]]);
        $p1 = $this->purchase('VDF-RP1', $vend, $this->date(5), [[800, 168]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $p1, [$this->typeId => $this->v['child']], null);
        $this->purchase('VDF-RP2', $vend, $this->date(6), [[100, 21]]);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        $rows = self::byCode($report['rows']);
        $none = $report['rows'][array_key_last($report['rows'])];
        self::assertNull($none['value_id']);
        // Bez dimenze: vydaný VDF-R3 a přijatý VDF-RP2 celé.
        self::assertSame(105.0, $none['vat_output'], 'Bez dimenze nese doklady bez dimenze.');
        self::assertSame(21.0, $none['vat_input']);
        self::assertSame(2, $none['documents'], 'Bez dimenze: jeden vydaný a jeden přijatý doklad.');
        // Položka 2 dokladu VDF-R1 má vlastní hodnotu, rozpad VDF-R2 dělí 70 Kč 50/25/25 %.
        self::assertSame(round(259.26 + 35.0, 2), $rows['VDF-X']['vat_output']);
        self::assertSame(round(21.0 + 17.5, 2), $rows['VDF-DITE']['vat_output']);
        self::assertSame(168.0, $rows['VDF-DITE']['vat_input']);
        self::assertSame(17.5, $rows['VDF-RODIC']['vat_output'], 'Řádek hodnoty nese jen její vlastní díl, ne podřízené.');

        self::assertTrue($report['control']['ok'], json_encode($report['control'], JSON_UNESCAPED_UNICODE));
        foreach ($report['control']['difference'] as $diff) {
            self::assertSame(0.0, $diff);
        }
        $summary = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['summary'];
        $outputLines = 0.0;
        foreach ($summary['lines'] as $line => $d) {
            if ((int) $line >= 1 && (int) $line <= 13) {
                $outputLines += (float) $d['vat'];
            }
        }
        self::assertSame(round($outputLines, 2), $report['total']['vat_output'], 'Součet sestavy = DPH na výstupu z přiznání (před zaokrouhlením).');
        self::assertSame((float) $summary['total_vat_output'], $report['control']['return']['vat_output']);
        self::assertSame(round((float) $summary['total_vat_output'] - $report['total']['vat_output'], 2), $report['control']['return']['bridge']['vat_output']);

        // Řádek hodnoty sedí na knihu DPH filtrovanou na tutéž hodnotu bez podřízených.
        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null,
            $this->dimensions->filter($this->supplierId, $this->v['child'], false));
        self::assertSame($rows['VDF-DITE']['vat_output'], round($book['totals']['issued']['vat'], 2));
        self::assertSame($rows['VDF-DITE']['vat_input'], round($book['totals']['received']['vat'], 2));
    }

    public function testAccount343TurnoverExcludesTaxOfficePaymentsAndOpeningBalance(): void
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $from = sprintf('%04d-01-01', self::YEAR);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, $from, sprintf('%04d-12-31', self::YEAR));
        $posting = $this->container->get(PostingService::class);
        $post = fn (string $source, string $date, array $lines): int => $posting->postDocument($this->supplierId, $source, null, $lines, ['entry_date' => $date, 'posted_by' => $this->userId]);
        $dims = fn (int $value): array => ['dimensions' => [$this->typeId => $value]];

        // Předpis daně na výstupu po střediscích (ruční zápis 311/343).
        $post('manual', $this->date(10), [['account_code' => '311', 'side' => 'debit', 'amount' => 121.0],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 21.0] + $dims($this->v['x']), ['account_code' => '604', 'side' => 'credit', 'amount' => 100.0]]);
        // Úhrada vlastní daně finančnímu úřadu z banky a počáteční stav 343 se nepočítají.
        $post('bank', $this->date(20), [['account_code' => '343', 'side' => 'debit', 'amount' => 5000.0] + $dims($this->v['x']), ['account_code' => '221', 'side' => 'credit', 'amount' => 5000.0]]);
        $post('opening', $this->date(1), [['account_code' => '343', 'side' => 'credit', 'amount' => 777.0], ['account_code' => '701', 'side' => 'debit', 'amount' => 777.0]]);
        // DPH z bankovního poplatku protistranu nákladu má, zůstává (bez dimenze).
        $post('bank', $this->date(21), [['account_code' => '568', 'side' => 'debit', 'amount' => 10.0], ['account_code' => '343', 'side' => 'debit', 'amount' => 2.1],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 12.1]]);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertNull($report['account343_unavailable']);
        $account = $report['account343'];
        $rows = self::byCode($account['rows']);
        self::assertSame(21.0, $rows['VDF-X']['credit']);
        self::assertSame(0.0, $rows['VDF-X']['debit'], 'Úhrada daně FÚ z banky do obratu nepatří.');
        $none = $account['rows'][array_key_last($account['rows'])];
        self::assertNull($none['value_id']);
        self::assertSame(2.1, $none['debit'], 'DPH z bankovního poplatku zůstává.');
        self::assertSame(0.0, $none['credit'], 'Počáteční stav 343 do obratu nepatří.');
        self::assertSame(1, $account['excluded']['payments']['count']);
        self::assertSame(-5000.0, $account['excluded']['payments']['net']);
        self::assertSame(1, $account['excluded']['opening']['count']);
        self::assertSame(777.0, $account['excluded']['opening']['net']);
        self::assertSame(round(21.0 - 2.1, 2), $account['total']['net']);
    }

    /**
     * Nález 1: nefiltrované výkazy nesmí dělat práci navíc (řádek evidence bez zdrojového
     * řádku, žádné pořadí položek) a pořadí položky pro rozpad se počítá ze VŠECH položek
     * dokladu včetně textových, jako v editoru.
     */
    public function testUnfilteredLedgerCarriesNoSourceLinesAndTextRowsKeepItemPosition(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $doc = $this->sale('VDF-T1', $cust, $this->date(7), [[0, 0, 'kind' => 'text'], [1000, 210], [500, 105]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $doc, [], [2 => [$this->typeId => $this->v['x']]]);

        $ledger = $this->container->get(VatLedgerService::class);
        $rows = iterator_to_array($ledger->iterateRows($this->supplierId, $this->date(1), $this->date(28)), false);
        self::assertNotSame([], $rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('line_id', $row, 'Nefiltrovaná evidence nečte zdrojové řádky.');
            self::assertArrayNotHasKey('item_no', $row, 'Nefiltrovaná evidence nepočítá pořadí položek.');
        }
        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null,
            $this->dimensions->filter($this->supplierId, $this->v['x']));
        self::assertSame(1000.0, round($book['totals']['issued']['base'], 2), 'Položka 2 (po textovém řádku) patří X.');
    }

    /**
     * Nález 2: oddíl KH a vyřazení se určí podle všech položek dokladu, teprve pak se částky
     * omezí na hodnotu. Plně předplacené vyúčtování (§ 37a) v KH není a doklad s rozdílným
     * režimem položek je vyřazený, i když problémová položka patří jiné hodnotě.
     */
    public function testFilteredKhClassifiesByWholeDocument(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $prepaid = $this->sale('VDF-Z1', $cust, $this->date(8), [[12000, 2520], [-12000, -2520]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $prepaid, [$this->typeId => $this->v['child']], [1 => [$this->typeId => $this->v['x']]]);
        $conflict = $this->sale('VDF-Z2', $cust, $this->date(9), [[20000, 4200, 'code' => '1'], [10000, 2100, 'code' => '1c']]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $conflict, [$this->typeId => $this->v['child']], [1 => [$this->typeId => $this->v['x']]]);

        $soupis = $this->container->get(KhEvidenceService::class)->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'all',
            $this->dimensions->filter($this->supplierId, $this->v['x']));
        $docs = [];
        foreach (['A.4', 'A.5'] as $section) {
            foreach ($soupis['sections'][$section]['rows'] as $row) {
                $docs[] = $row['doc_number'];
            }
        }
        self::assertNotContains('VDF-Z1', $docs, 'Vyúčtování vynulované odpočtem zálohy do KH nepatří ani za díl hodnoty.');
        self::assertNotContains('VDF-Z2', $docs, 'Doklad s rozdílným režimem položek KH vyřadí i ve výpisu za hodnotu.');
        self::assertContains('VDF-Z2', array_column($soupis['excluded'], 'doc_number'));
    }

    /**
     * Nález 3: oprava § 43 přesune opravené plnění z A.4 do A.5 i ve výpisu za hodnotu
     * a sestava ji vykáže v řádku oprav, takže součet dál sedí na úplnou evidenci.
     */
    public function testSection43CorrectionMovesSectionAndCountsInReport(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $doc = $this->sale('VDF-S43', $cust, $this->date(5), [[8347.11, 1752.89]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $doc, [$this->typeId => $this->v['x']], null);
        $this->container->get(\MyInvoice\Service\Tax\Vat\Section43Service::class)->register(
            $this->supplierId, 'invoice', $doc, self::YEAR, self::MONTH, 'basic', 0.0, -178.0, $this->date(20), 'Chybný výpočet daně',
        );

        $soupis = $this->container->get(KhEvidenceService::class)->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'all',
            $this->dimensions->filter($this->supplierId, $this->v['x']));
        self::assertNotContains('VDF-S43', array_column($soupis['sections']['A.4']['rows'], 'doc_number'));
        $a5 = array_column($soupis['sections']['A.5']['rows'], null, 'doc_number');
        self::assertArrayHasKey('VDF-S43', $a5, 'Opravené plnění pod limit patří do A.5 jako ve skutečném KH.');
        self::assertSame(1752.89, (float) $a5['VDF-S43']['vat21'], 'Částka za hodnotu je díl dokladu bez opravy.');

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        $corrections = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['kind'] === 'corrections'));
        self::assertCount(1, $corrections);
        self::assertSame(-178.0, $corrections[0]['vat_output']);
        self::assertTrue($report['control']['ok'], json_encode($report['control'], JSON_UNESCAPED_UNICODE));
        $summary = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['summary'];
        self::assertSame(round((float) $summary['lines']['1']['vat'], 2), $report['total']['vat_output'], 'Součet včetně oprav = ř. 1 přiznání.');
    }

    /** Nález 4: chybějící kurz cizí měny sestavu označí jako předběžnou s varováním. */
    public function testMissingExchangeRateMarksReportPreliminary(): void
    {
        $eur = (int) $this->db->pdo()->query("SELECT id FROM currencies WHERE code = 'EUR' AND supplier_id = {$this->supplierId} LIMIT 1")->fetchColumn();
        if ($eur === 0) {
            self::markTestSkipped('Chybí měna EUR firmy.');
        }
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $doc = $this->sale('VDF-EUR', $cust, $this->date(6), [[1000, 210]], $eur);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $doc, [$this->typeId => $this->v['x']], null);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertTrue($report['preliminary']);
        self::assertSame([$doc], array_column($report['missing_rates'], 'invoice_id'));
        self::assertNotSame([], array_filter($report['warnings'], static fn (string $w): bool => str_contains($w, 'Chybí kurz')));
    }

    /**
     * Nález 5: identifikovaná osoba podává vždy za měsíc a jen samovyměření — sestava za
     * „čtvrtletí" počítá jako přiznání za zvolený měsíc a bez zrcadlového odpočtu.
     */
    public function testIdentifiedPersonUsesTheSamePeriodAndLinesAsReturn(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 0, is_identified = 1 WHERE id = ?')->execute([$this->supplierId]);
        $this->db->pdo()->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE is_vat_payer = 0, is_identified = 1'
        )->execute([$this->supplierId, self::YEAR . '-01-01']);
        $vend = $this->client('EU dodavatel VDF', 'DE303030303', vendor: true, country: 'DE');
        $april = sprintf('%04d-04-10', self::YEAR);
        $doc = $this->purchase('VDF-IO', $vend, $april, [[10000, 0, 'code' => '24']], ['rc' => true, 'code' => '24']);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $doc, [$this->typeId => $this->v['x']], null);

        $service = $this->container->get(VatDimensionReportService::class);
        $june = $service->build($this->supplierId, self::YEAR, 6, 'quarterly', $this->typeId);
        self::assertSame('monthly', $june['period']['period_type']);
        self::assertTrue($june['period']['forced_monthly']);
        self::assertSame(0.0, $june['total']['vat_output'], 'Za červen (přiznání IO) nic není.');
        self::assertSame(0.0, $june['control']['return']['bridge']['vat_output']);

        $aprilReport = $service->build($this->supplierId, self::YEAR, 4, 'monthly', $this->typeId);
        self::assertSame(2100.0, $aprilReport['total']['vat_output']);
        self::assertSame(0.0, $aprilReport['total']['vat_input'], 'Identifikovaná osoba nemá nárok na odpočet (bez ř. 43).');
        self::assertSame(0.0, $aprilReport['control']['return']['bridge']['vat_input']);
    }

    /**
     * Nález 6: zrcadlový odpočet u samovyměření s poměrným nárokem počítá Kniha stejně
     * jako přiznání a sestava (krácená daň), přiznání se nemění.
     */
    public function testBookMirrorUsesProportionalDeduction(): void
    {
        $vend = $this->client('EU dodavatel VDF', 'DE303030303', vendor: true, country: 'DE');
        $doc = $this->purchase('VDF-RC50', $vend, $this->date(12), [[10000, 0, 'code' => '24']], ['rc' => true, 'code' => '24', 'deduction' => 'proportional', 'percent' => 50]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $doc, [$this->typeId => $this->v['x']], null);

        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $mirror = array_values(array_filter($book['sections'], static fn (array $s): bool => $s['dphdp3_line'] === '43'));
        self::assertCount(1, $mirror);
        self::assertSame(1050.0, round($mirror[0]['subtotal_vat'], 2), 'Zrcadlový ř. 43 Knihy = krácený odpočet.');
        $summary = $this->container->get(DphPriznaniBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly')['summary'];
        self::assertSame(1050.0, (float) $summary['lines']['43']['vat']);
        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertSame(1050.0, self::byCode($report['rows'])['VDF-X']['vat_input']);
    }

    /**
     * Nález 7: rozpis DPH přijatého dokladu převezme dimenzi, je-li jednoznačná; u položek
     * v různých dimenzích je jeho DPH výslovně nepřiřazená v sestavě i ve filtrované Knize.
     */
    public function testPurchaseVatAllocationFollowsUnambiguousDimension(): void
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $vend = $this->client('Dodavatel VDF', 'CZ22222220', vendor: true);
        $single = $this->purchase('VDF-RZ1', $vend, $this->date(14), [[1000, 210]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $single, [], [1 => [$this->typeId => $this->v['x']]]);
        $this->allocation($single, 1000, 210);
        $mixed = $this->purchase('VDF-RZ2', $vend, $this->date(15), [[600, 126], [400, 84]]);
        $this->dimensions->saveDocument($this->supplierId, 'purchase_invoice', $mixed, [], [1 => [$this->typeId => $this->v['x']], 2 => [$this->typeId => $this->v['child']]]);
        $this->allocation($mixed, 1000, 210);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertSame(210.0, self::byCode($report['rows'])['VDF-X']['vat_input'], 'Jediná položka v X: rozpis patří X.');
        $unassigned = array_values(array_filter($report['rows'], static fn (array $r): bool => $r['kind'] === 'vat_allocation'));
        self::assertCount(1, $unassigned);
        self::assertSame(210.0, $unassigned[0]['vat_input']);
        self::assertTrue($report['control']['ok']);

        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', null,
            $this->dimensions->filter($this->supplierId, $this->v['x']));
        self::assertSame(210.0, round($book['totals']['received']['vat'], 2));
        self::assertSame(['VDF-RZ2'], array_column($book['dimension_unassigned'], 'doc_number'));
        self::assertSame(210.0, $book['dimension_unassigned'][0]['vat']);
    }

    /**
     * Nález 8: úhrada daně finančnímu úřadu z banky s poplatkem ve stejném zápisu (protiúčet
     * u ČNB) do obratu 343 nepatří; ruční zápis 343/221 bez doložené úhrady a DPH z poplatku
     * bez vazby na FÚ ano (jako dosud). Stejné pravidlo čte kontrola obratu 343 proti přiznání.
     */
    public function testAccount343SettlementWithFeeAndManualPaymentAreExcluded(): void
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, sprintf('%04d-01-01', self::YEAR), sprintf('%04d-12-31', self::YEAR));
        $posting = $this->container->get(PostingService::class);
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, statement_date) VALUES (?, 'vdf.gpc', ?, '1000000005/0100', ?)")
            ->execute([$this->supplierId, hash('sha256', uniqid('vdf', true)), $this->date(25)]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, counterparty_account, counterparty_bank) VALUES (?, ?, -5020, '705-77628461', '0710')")
            ->execute([$statement, $this->date(25)]);
        $tx = (int) $pdo->lastInsertId();
        $dims = fn (int $value): array => ['dimensions' => [$this->typeId => $value]];
        $post = fn (string $source, ?int $sourceId, array $lines): int => $posting->postDocument($this->supplierId, $source, $sourceId, $lines, ['entry_date' => $this->date(25), 'posted_by' => $this->userId]);

        $post('bank', $tx, [['account_code' => '343', 'side' => 'debit', 'amount' => 5000.0] + $dims($this->v['x']),
            ['account_code' => '568', 'side' => 'debit', 'amount' => 20.0], ['account_code' => '221', 'side' => 'credit', 'amount' => 5020.0]]);
        $post('manual', null, [['account_code' => '343', 'side' => 'debit', 'amount' => 700.0] + $dims($this->v['x']), ['account_code' => '221', 'side' => 'credit', 'amount' => 700.0]]);
        $post('bank', null, [['account_code' => '568', 'side' => 'debit', 'amount' => 10.0], ['account_code' => '343', 'side' => 'debit', 'amount' => 2.1] + $dims($this->v['x']),
            ['account_code' => '221', 'side' => 'credit', 'amount' => 12.1]]);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        $x = self::byCode($report['account343']['rows'])['VDF-X'];
        self::assertSame(702.1, $x['debit'], 'Ruční zápis 343/221 bez doložené úhrady a DPH z poplatku v obratu zůstanou (jako dosud).');
        self::assertSame(1, $report['account343']['excluded']['payments']['count'], 'Vyloučená je jen doložená úhrada s protiúčtem u ČNB.');
        self::assertSame(-5000.0, $report['account343']['excluded']['payments']['net']);
    }

    /** Drobný nález: vyloučená úhrada s řádkem 343 rozděleným mezi dvě hodnoty je jeden zápis. */
    public function testExcludedPaymentWithSplitLineCountsOnce(): void
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, sprintf('%04d-01-01', self::YEAR), sprintf('%04d-12-31', self::YEAR));
        $this->container->get(PostingService::class)->postDocument($this->supplierId, 'bank', null, [
            ['account_code' => '343', 'side' => 'debit', 'amount' => 1000.0,
                'dimension_splits' => [$this->typeId => [$this->v['x'] => 0.5, $this->v['child'] => 0.5]]],
            ['account_code' => '221', 'side' => 'credit', 'amount' => 1000.0],
        ], ['entry_date' => $this->date(26), 'posted_by' => $this->userId]);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertSame(1, $report['account343']['excluded']['payments']['count']);
        self::assertSame(-1000.0, $report['account343']['excluded']['payments']['net']);
    }

    /** Regrese review 2: Kniha DPH ukazuje ř. 51 (prodej majetku) i ř. 47 (krácený nárok) jako dosud. */
    public function testBookKeepsLine51AndLine47AmountsOfPrimaryRow(): void
    {
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $vend = $this->client('Dodavatel VDF', 'CZ22222220', vendor: true);
        $this->sale('VDF-1M', $cust, $this->date(16), [[10000, 2100, 'code' => '1m']], null, '1m');
        $this->purchase('VDF-40K', $vend, $this->date(17), [[10000, 2100]], ['asset' => true, 'deduction' => 'reduced']);

        $book = $this->container->get(DphBookBuilder::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly');
        $byLine = [];
        foreach ($book['sections'] as $section) {
            $byLine[$section['dphdp3_line']] = ($byLine[$section['dphdp3_line']] ?? 0.0) + $section['subtotal_vat'];
        }
        self::assertSame(2100.0, round($byLine['51'] ?? 0.0, 2), 'Ř. 51 Knihy nese daň primárního řádku.');
        self::assertSame(2100.0, round($byLine['47'] ?? 0.0, 2), 'Ř. 47 Knihy nese daň i u kráceného nároku.');
    }

    /**
     * Regrese review 2: v jednoduchém zápisu se hotovostní prodej rozpadne na 211/602 a 211/343.
     * Dvojice peněžní účet / 343 úhradou finančnímu úřadu není, DPH zůstane v obratu 343.
     */
    public function testAccount343KeepsCashSaleVatInSimpleEntryForm(): void
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, self::YEAR, sprintf('%04d-01-01', self::YEAR), sprintf('%04d-12-31', self::YEAR));
        $this->container->get(\MyInvoice\Service\Accounting\EntryForm\EntryFormService::class)
            ->schedule($this->supplierId, sprintf('%04d-01-01', self::YEAR), \MyInvoice\Service\Accounting\EntryForm\EntryForm::Single, $this->userId);
        $this->container->get(PostingService::class)->postDocument($this->supplierId, 'cash', null, [
            ['account_code' => '211', 'side' => 'debit', 'amount' => 100.0, 'pair_no' => 1],
            ['account_code' => '602', 'side' => 'credit', 'amount' => 100.0, 'pair_no' => 1],
            ['account_code' => '211', 'side' => 'debit', 'amount' => 21.0, 'pair_no' => 2],
            ['account_code' => '343', 'side' => 'credit', 'amount' => 21.0, 'pair_no' => 2, 'dimensions' => [$this->typeId => $this->v['x']]],
        ], ['entry_date' => $this->date(18), 'posted_by' => $this->userId]);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, self::MONTH, 'monthly', $this->typeId);
        self::assertSame(21.0, self::byCode($report['account343']['rows'])['VDF-X']['credit']);
        self::assertSame(0, $report['account343']['excluded']['payments']['count']);
    }

    /** Review 2, bod 4: vyřazený doklad KH se ve výpisu za hodnotu ukáže jen dílem hodnoty. */
    public function testExcludedKhDocumentShowsOnlyTheDimensionShare(): void
    {
        $cust = $this->client('Odběratel bez DIČ', null, customer: true);
        $doc = $this->sale('VDF-A1', $cust, $this->date(19), [[7000, 0, 'code' => '25s'], [3000, 0, 'code' => '25s']], null, '25s', true);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $doc, [$this->typeId => $this->v['child']], [2 => [$this->typeId => $this->v['x']]]);

        $kh = $this->container->get(KhEvidenceService::class);
        $x = $kh->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'all', $this->dimensions->filter($this->supplierId, $this->v['x']));
        $excluded = array_column($x['excluded'], null, 'doc_number');
        self::assertArrayHasKey('VDF-A1', $excluded);
        self::assertSame(3000.0, (float) $excluded['VDF-A1']['base_total'], 'Vyřazený doklad jen dílem hodnoty.');
        $other = $this->value('VDF-JINA');
        $none = $kh->current($this->supplierId, self::YEAR, self::MONTH, 'monthly', 'all', $this->dimensions->filter($this->supplierId, $other));
        self::assertNotContains('VDF-A1', array_column($none['excluded'], 'doc_number'), 'Hodnota bez dílu doklad nevypíše.');
    }

    /**
     * Review 2, bod 5: firma je v dubnu plátce a ke konci čtvrtletí identifikovaná osoba.
     * Sestava i srovnávací přiznání počítají ve stejném režimu, most proto nevykáže rozdíl.
     */
    public function testReturnBridgeUsesTheSameScopeWhenStatusChangesInQuarter(): void
    {
        $pdo = $this->db->pdo();
        $history = $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_vat_payer = VALUES(is_vat_payer), is_identified = VALUES(is_identified)'
        );
        $history->execute([$this->supplierId, self::YEAR . '-01-01', 1, 0]);
        $history->execute([$this->supplierId, self::YEAR . '-06-01', 0, 1]);
        $cust = $this->client('Odběratel VDF', 'CZ11111118', customer: true);
        $doc = $this->sale('VDF-PI', $cust, sprintf('%04d-04-10', self::YEAR), [[10000, 2100]]);
        $this->dimensions->saveDocument($this->supplierId, 'invoice', $doc, [$this->typeId => $this->v['x']], null);

        $report = $this->container->get(VatDimensionReportService::class)->build($this->supplierId, self::YEAR, 4, 'quarterly', $this->typeId);
        self::assertTrue($report['period']['forced_monthly']);
        self::assertSame(0.0, $report['total']['vat_output'], 'Identifikovaná osoba tuzemské plnění nevykazuje.');
        self::assertSame(0.0, $report['control']['return']['bridge']['vat_output'], 'Přiznání ve stejném režimu jako sestava.');
    }

    private function allocation(int $purchaseId, float $base, float $vat): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_vat_allocations
                (supplier_id, purchase_invoice_id, description, vat_rate, base_amount, vat_amount, total_amount, account_code, vat_classification_code)
             VALUES (?, ?, 'Rozpis', 21, ?, ?, ?, '501', '40')"
        )->execute([$this->supplierId, $purchaseId, $base, $vat, $base + $vat]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private static function byCode(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row['code'] !== null) {
                $out[(string) $row['code']] = $row;
            }
        }
        return $out;
    }

    private function value(string $code, ?int $parent = null): int
    {
        return (int) $this->dimensions->createValue($this->supplierId, $this->typeId, ['code' => $code, 'name' => 'Hodnota ' . $code, 'parent_id' => $parent])['id'];
    }

    private function date(int $day): string
    {
        return sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day);
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);
        return $ids;
    }

    private function listRequest(string $valueIds, ?string $descendants): \Psr\Http\Message\ServerRequestInterface
    {
        $filter = ['dimension_value_id' => $valueIds, 'year' => (string) self::YEAR];
        if ($descendants !== null) {
            $filter['dimension_descendants'] = $descendants;
        }
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/invoices')
            ->withQueryParams(['filter' => $filter])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
    }

    /** @return list<int> */
    private function issuedIds(string $valueIds, ?string $descendants): array
    {
        $filters = InvoiceListFilters::issued($this->listRequest($valueIds, $descendants), $this->dimensions);
        self::assertIsArray($filters);
        $ids = array_map('intval', $this->container->get(InvoiceRepository::class)->listIds($filters, 100)['ids']);
        return $this->sorted($ids);
    }

    /** @return list<int> */
    private function purchaseIds(string $valueIds): array
    {
        $filters = InvoiceListFilters::purchase($this->listRequest($valueIds, null), $this->dimensions);
        self::assertIsArray($filters);
        $ids = array_map('intval', $this->container->get(PurchaseInvoiceRepository::class)->listIds($filters, 100)['ids']);
        return $this->sorted($ids);
    }

    private function client(string $name, ?string $dic, bool $customer = false, bool $vendor = false, ?string $country = null): int
    {
        $countryId = $country === null ? $this->czId
            : (int) $this->db->pdo()->query("SELECT id FROM countries WHERE iso2 = '{$country}' LIMIT 1")->fetchColumn();
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "test@example.com", "cs", ?, ?, ?)'
        )->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId, $customer ? 1 : 0, $vendor ? 1 : 0]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param list<array{0:float|int,1:float|int}> $items */
    private function sale(string $varsymbol, int $clientId, string $date, array $items, ?int $currencyId = null, string $code = '1', bool $rc = false): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, "issued", ?, ?)'
        )->execute([$this->supplierId, $varsymbol, $clientId, $date, $date, $date, $currencyId ?? $this->currencyId, $rc ? 1 : 0, $base, $vat, $base + $vat, $code, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->items('invoice_items', 'invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float|int,1:float|int}> $items */
    /** @param array{rc?:bool, code?:string, deduction?:string, percent?:float} $opt */
    private function purchase(string $number, int $vendorId, string $date, array $items, array $opt = []): int
    {
        [$base, $vat] = $this->sum($items);
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, exchange_rate, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 is_fixed_asset, vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, NULL, ?, "{}", ?, ?, ?, "received", ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, $vendorId, $number, $date, $date, $date, $date, $this->currencyId, !empty($opt['rc']) ? 1 : 0,
            $base, $vat, $base + $vat, $opt['code'] ?? '40', !empty($opt['asset']) ? 1 : 0, $opt['deduction'] ?? 'full', $opt['percent'] ?? 100, $this->userId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->items('purchase_invoice_items', 'purchase_invoice_id', $id, $items);
        return $id;
    }

    /** @param list<array{0:float|int,1:float|int}> $items */
    private function items(string $table, string $fk, int $id, array $items): void
    {
        $kind = $table === 'invoice_items' ? ', item_kind' : '';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO {$table}
                ({$fk}, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code{$kind})
             VALUES (?, 'Test položka', 1, 'ks', ?, ?, ?, ?, ?, ?, ?, ?" . ($kind !== '' ? ', ?' : '') . ')'
        );
        foreach ($items as $i => $item) {
            [$base, $vat] = $item;
            $params = [$id, $base, $this->vatRateId, $item['rate'] ?? 21, $base, $vat, $base + $vat, $i, $item['code'] ?? null];
            if ($kind !== '') {
                $params[] = $item['kind'] ?? 'standard';
            }
            $stmt->execute($params);
        }
    }

    /** @param list<array{0:float|int,1:float|int}> $items @return array{0:float,1:float} */
    private function sum(array $items): array
    {
        $b = 0.0;
        $v = 0.0;
        foreach ($items as $item) {
            [$base, $vat] = $item;
            $b += $base;
            $v += $vat;
        }
        return [round($b, 2), round($v, 2)];
    }
}
