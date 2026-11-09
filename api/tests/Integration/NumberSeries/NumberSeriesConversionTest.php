<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\NumberSeries;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NumberSeriesRepository;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\NumberSeries\NumberSeriesCatalog;
use MyInvoice\Service\NumberSeries\NumberSeriesService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Převod stávajících číselných řad do číselníku (migrace 2268 + NumberSeriesCatalog,
 * spouští ho migrate.php přes api/bin/number-series-convert.php).
 *
 * Akceptace: po převodu se nezmění číslo žádného vystaveného dokladu ani žádné
 * počítadlo, další vydané číslo v každé řadě je stejné jako před převodem a kontrola
 * úplnosti nehlásí mezery, které v řadách před převodem nebyly. Dvě firmy s různým
 * nastavením (řady klienta a kategorie tržby, vlastní řada pokladny, přijaté doklady
 * po druzích, měsíční řada) a víc druhů dokladů. Vše v transakci → rollback.
 */
#[Group('integration')]
final class NumberSeriesConversionTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private NumberSeriesCatalog $catalog;
    private NumberSeriesService $service;
    private NumberSeriesRepository $repo;
    private VarsymbolGenerator $varsymbol;
    private InvoiceSeriesCompletenessService $completeness;
    private bool $inTx = false;

    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $year = 0;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->catalog = $container->get(NumberSeriesCatalog::class);
            $this->service = $container->get(NumberSeriesService::class);
            $this->repo = $container->get(NumberSeriesRepository::class);
            $this->varsymbol = $container->get(VarsymbolGenerator::class);
            $this->completeness = $container->get(InvoiceSeriesCompletenessService::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->today = new \DateTimeImmutable('today');
        $this->year = (int) $this->today->format('Y');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $this->inTx = true;
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

    public function testConversionKeepsNumbersNextNumbersAndCompleteness(): void
    {
        $pdo = $this->db->pdo();
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();

        // Firma A: podvojné účetnictví, roční řady, řada klienta a kategorie tržby,
        // pokladna s vlastní řadou, rozjetá řada ID.
        $a = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET accounting_mode = 'double_entry', invoice_number_format = 'FV{YYYY}{CCCC}',
                    proforma_number_format = 'ZF{YY}{CCC}', credit_note_number_format = 'DB{YYYY}{CCCC}',
                    invoice_number_period = 'year', simplified_number_format = NULL,
                    purchase_invoice_numbering = 'shared', purchase_invoice_number_format = NULL
              WHERE id = ?"
        )->execute([$a]);
        $clientA = $this->client($a, 'K{YY}{CCC}');
        $clientPlain = $this->client($a, null);
        $pdo->prepare("INSERT INTO revenue_categories (supplier_id, code, label, proforma_number_format) VALUES (?, 'SERV', 'Servis', 'ZS{YY}{CCC}')")
            ->execute([$a]);
        $categoryA = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO cash_registers (supplier_id, name, own_series) VALUES (?, 'Pobočka', 1)")->execute([$a]);
        $registerA = (int) $pdo->lastInsertId();
        $ads = $pdo->prepare(
            'INSERT INTO accounting_document_series (supplier_id, series_code, fiscal_year, register_id, prefix, number_format, next_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ads->execute([$a, 'cash_in', $this->year, 0, 'PPD', null, 5]);
        $ads->execute([$a, 'cash_in', $this->year, $registerA, 'PPD2', null, 3]);
        $ads->execute([$a, 'manual', $this->year, 0, 'ID', '{PREFIX}{YY}{CCCCC}', 12]);
        $ads->execute([$a, 'stock_in', $this->year, 0, 'PRI', null, 2]);

        // Faktury: tři v řadě firmy, prostřední smazaná (díra, která tu byla už před převodem).
        $f1 = $this->issue($a, 'invoice', $clientPlain);
        $f2 = $this->issue($a, 'invoice', $clientPlain);
        $this->issue($a, 'invoice', $clientPlain);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')->execute([$a, $f2]);
        $this->issue($a, 'invoice', $clientA);
        $this->issue($a, 'credit_note', $clientPlain);
        $this->issue($a, 'proforma', $clientPlain, $categoryA);
        self::assertSame('FV' . $this->year . '0001', $f1);

        // Firma B: daňová evidence, měsíční řada, přijaté doklady po druzích.
        $b = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET accounting_mode = 'tax_evidence', invoice_number_format = '{YYYY}{MM}{CCC}',
                    proforma_number_format = NULL, credit_note_number_format = NULL, invoice_number_period = 'month',
                    simplified_number_format = 'UC{YY}{CCCCC}',
                    purchase_invoice_numbering = 'by_kind', purchase_receipt_number_format = 'UC{YY}{MM}{CCC}'
              WHERE id = ?"
        )->execute([$b]);
        $clientB = $this->client($b, null);
        $this->issue($b, 'invoice', $clientB);
        $this->issue($b, 'invoice', $clientB);
        $pdo->prepare('INSERT INTO purchase_invoice_counters (supplier_id, period, last_number) VALUES (?, ?, 4)')
            ->execute([$b, 'receipt:' . $this->today->format('Ym')]);
        $ads->execute([$b, 'manual', $this->year, 0, 'ID', null, 9]);

        // ── stav PŘED převodem ──
        $before = [
            'documents' => $this->documents([$a, $b]),
            'counters'  => $this->counters([$a, $b]),
            'reportA'   => json_encode($this->completeness->build($a, $this->year)),
            'reportB'   => json_encode($this->completeness->build($b, $this->year)),
        ];
        $nextBefore = [
            'A:invoice'     => $this->varsymbol->peek($a, 'invoice', $this->today)['preview'],
            'A:proforma'    => $this->varsymbol->peek($a, 'proforma', $this->today)['preview'],
            'A:credit_note' => $this->varsymbol->peek($a, 'credit_note', $this->today)['preview'],
            'A:client'      => $this->varsymbol->peek($a, 'invoice', $this->today, $clientA)['preview'],
            'A:category'    => $this->varsymbol->peek($a, 'proforma', $this->today, 0, $categoryA)['preview'],
            'B:invoice'     => $this->varsymbol->peek($b, 'invoice', $this->today)['preview'],
            'B:simplified'  => $this->varsymbol->peek($b, 'simplified', $this->today)['preview'],
        ];
        self::assertSame([], $this->repo->listForSupplier($a), 'Před převodem je číselník prázdný.');

        // ── převod (totéž, co pustí migrate.php) ──
        $result = $this->catalog->syncAll();
        self::assertGreaterThan(0, $result['series']);
        self::assertSame(0, $this->catalog->syncAll()['series'], 'Převod je idempotentní.');

        $keysA = array_column($this->repo->listForSupplier($a), 'legacy_key');
        foreach (['issued:invoice', 'issued:proforma', 'issued:credit_note', "client:{$clientA}:invoice",
            "revenue_category:{$categoryA}:proforma", 'purchase', 'doc:cash_in:0', 'doc:cash_out:0',
            "doc:cash_in:{$registerA}", "doc:cash_out:{$registerA}", 'doc:manual:0', 'doc:stock_in:0'] as $key) {
            self::assertContains($key, $keysA, "Firma A: chybí převedená řada {$key}.");
        }
        self::assertNotContains("client:{$clientPlain}:invoice", $keysA, 'Klient bez vlastní šablony vlastní řadu nemá.');
        $keysB = array_column($this->repo->listForSupplier($b), 'legacy_key');
        foreach (['issued:invoice', 'issued:simplified', 'purchase', 'purchase:receipt', 'purchase:credit_note', 'doc:cash_in:0'] as $key) {
            self::assertContains($key, $keysB, "Firma B: chybí převedená řada {$key}.");
        }
        self::assertNotContains('doc:manual:0', $keysB, 'Daňová evidence deníkové řady nemá.');

        // Čísla dokladů ani počítadla se nezměnila.
        self::assertSame($before['documents'], $this->documents([$a, $b]));
        self::assertSame($before['counters'], $this->counters([$a, $b]));

        // Kontrola úplnosti vydaných dokladů je bajtově stejná; jediná díra je ta původní.
        self::assertSame($before['reportA'], json_encode($this->completeness->build($a, $this->year)));
        self::assertSame($before['reportB'], json_encode($this->completeness->build($b, $this->year)));
        $bySeries = $this->completenessBySeries($a);
        $invoiceSeries = $this->seriesId($a, 'issued:invoice');
        self::assertSame([2], $bySeries[$invoiceSeries] ?? null, 'Řada faktur hlásí jen díru, která tu byla před převodem.');
        foreach ($bySeries as $seriesId => $missing) {
            if ($seriesId !== $invoiceSeries) {
                self::assertSame([], $missing, "Převedená řada #{$seriesId} nesmí hlásit novou mezeru.");
            }
        }

        // Číselník ukazuje stejné příští číslo jako dosavadní nastavení…
        $listA = array_column($this->service->list($a), null, 'legacy_key');
        self::assertSame($nextBefore['A:invoice'], $listA['issued:invoice']['next_preview']);
        self::assertSame($nextBefore['A:client'], $listA["client:{$clientA}:invoice"]['next_preview']);
        self::assertSame($nextBefore['A:category'], $listA["revenue_category:{$categoryA}:proforma"]['next_preview']);
        self::assertSame('PPD-' . $this->year . '-0005', $listA['doc:cash_in:0']['next_preview']);
        self::assertSame('PPD2-' . $this->year . '-0003', $listA["doc:cash_in:{$registerA}"]['next_preview']);
        self::assertSame('ID' . substr((string) $this->year, 2) . '00012', $listA['doc:manual:0']['next_preview']);
        $listB = array_column($this->service->list($b), null, 'legacy_key');
        self::assertSame('UC' . $this->today->format('ym') . '005', $listB['purchase:receipt']['next_preview']);
        self::assertSame($nextBefore['B:simplified'], $listB['issued:simplified']['next_preview']);

        // …a vystavení po převodu ho opravdu dostane (řady zůstávají ve výchozím stavu).
        self::assertSame($nextBefore['A:invoice'], $this->varsymbol->nextForInvoice($a, ['invoice_type' => 'invoice', 'client_id' => $clientPlain], $this->today)[0]);
        self::assertSame($nextBefore['A:proforma'], $this->varsymbol->nextForInvoice($a, ['invoice_type' => 'proforma', 'client_id' => $clientPlain], $this->today)[0]);
        self::assertSame($nextBefore['A:credit_note'], $this->varsymbol->nextForInvoice($a, ['invoice_type' => 'credit_note', 'client_id' => $clientPlain], $this->today)[0]);
        self::assertSame($nextBefore['A:client'], $this->varsymbol->nextForInvoice($a, ['invoice_type' => 'invoice', 'client_id' => $clientA], $this->today)[0]);
        self::assertSame($nextBefore['A:category'], $this->varsymbol->nextForInvoice($a, ['invoice_type' => 'proforma', 'client_id' => $clientPlain, 'revenue_category_id' => $categoryA], $this->today)[0]);
        self::assertSame($nextBefore['B:invoice'], $this->varsymbol->nextForInvoice($b, ['invoice_type' => 'invoice', 'client_id' => $clientB], $this->today)[0]);
        self::assertSame($nextBefore['B:simplified'], $this->varsymbol->nextForInvoice($b, ['invoice_type' => 'invoice', 'is_simplified' => 1, 'client_id' => $clientB], $this->today)[0]);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    private function client(int $supplierId, ?string $invoiceFormat): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor, invoice_number_format)
             VALUES (?, "Řady s.r.o.", "Test 1", "Praha", "11000", ?, "10000004", "rady@example.com", "cs", ?, 1, 0, ?)'
        )->execute([$supplierId, $this->czId, $this->currencyId, $invoiceFormat]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Vystaví doklad dosavadní cestou (číslo z generátoru, zápis na doklad v téže transakci). */
    private function issue(int $supplierId, string $type, int $clientId, int $categoryId = 0): string
    {
        $vs = $this->varsymbol->next($supplierId, $type, $this->today, $clientId, $categoryId);
        $date = $this->today->format('Y-m-d');
        $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, revenue_category_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "issued", "1", ?)'
        )->execute([$supplierId, $vs, $type, $clientId, $categoryId ?: null, $date, $type === 'proforma' ? null : $date, $date,
            $this->currencyId, $this->userId]);
        return $vs;
    }

    /** @param list<int> $suppliers @return list<array<string,mixed>> */
    private function documents(array $suppliers): array
    {
        $in = implode(',', array_map('intval', $suppliers));
        return $this->db->pdo()->query("SELECT id, supplier_id, varsymbol, number_series_id FROM invoices WHERE supplier_id IN ({$in}) ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<int> $suppliers @return array<string,list<array<string,mixed>>> */
    private function counters(array $suppliers): array
    {
        $in = implode(',', array_map('intval', $suppliers));
        $pdo = $this->db->pdo();
        return [
            'invoice'  => $pdo->query("SELECT * FROM invoice_counters WHERE supplier_id IN ({$in}) ORDER BY supplier_id, client_id, revenue_category_id, invoice_type, period")->fetchAll(PDO::FETCH_ASSOC),
            'purchase' => $pdo->query("SELECT * FROM purchase_invoice_counters WHERE supplier_id IN ({$in}) ORDER BY supplier_id, period")->fetchAll(PDO::FETCH_ASSOC),
            'document' => $pdo->query("SELECT supplier_id, series_code, fiscal_year, register_id, prefix, number_format, next_number FROM accounting_document_series WHERE supplier_id IN ({$in}) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** @return array<int, list<int>> id řady → chybějící čísla */
    private function completenessBySeries(int $supplierId): array
    {
        $out = [];
        $report = $this->service->completeness($supplierId, $this->year);
        self::assertSame([], $report['unassigned'], 'Každá řada vydaných dokladů se musí přiřadit převedené řadě číselníku.');
        foreach ($report['series'] as $series) {
            foreach ($series['buckets'] as $bucket) {
                $out[$series['number_series_id']] = array_merge($out[$series['number_series_id']] ?? [], $bucket['missing']);
            }
        }
        return $out;
    }

    private function seriesId(int $supplierId, string $legacyKey): int
    {
        return (int) ($this->repo->findByLegacyKey($supplierId, $legacyKey)['id'] ?? 0);
    }
}
