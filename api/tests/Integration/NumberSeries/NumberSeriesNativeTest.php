<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\NumberSeries;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\NumberSeriesRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Invoice\InvoiceSeriesCompletenessService;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use MyInvoice\Service\NumberSeries\NumberSeriesAllocator;
use MyInvoice\Service\NumberSeries\NumberSeriesCatalog;
use MyInvoice\Service\NumberSeries\NumberSeriesException;
use MyInvoice\Service\NumberSeries\NumberSeriesService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Nové řady číselníku: výdej čísla v transakci (rollback číslo vrací), výchozí řada,
 * ukončení, kontrola překryvu šablon, úplnost po řadě, víc řad v jedné pokladně,
 * a firma bez nových řad, která nesmí poznat žádnou změnu. Vše v transakci → rollback.
 */
#[Group('integration')]
final class NumberSeriesNativeTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private NumberSeriesService $service;
    private NumberSeriesCatalog $catalog;
    private NumberSeriesAllocator $allocator;
    private NumberSeriesRepository $repo;
    private VarsymbolGenerator $varsymbol;
    private InvoiceSeriesCompletenessService $completeness;
    private CashDocumentService $cash;
    private CashRegisterService $registers;
    private bool $inTx = false;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private \DateTimeImmutable $today;
    private string $yy = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(NumberSeriesService::class);
            $this->catalog = $container->get(NumberSeriesCatalog::class);
            $this->allocator = $container->get(NumberSeriesAllocator::class);
            $this->repo = $container->get(NumberSeriesRepository::class);
            $this->varsymbol = $container->get(VarsymbolGenerator::class);
            $this->completeness = $container->get(InvoiceSeriesCompletenessService::class);
            $this->cash = $container->get(CashDocumentService::class);
            $this->registers = $container->get(CashRegisterService::class);
            $seeder = $container->get(ChartOfAccountsSeeder::class);
            $periods = $container->get(AccountingPeriodRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($source === 0 || $this->currencyId === 0 || $this->userId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }
        $this->today = new \DateTimeImmutable('today');
        $this->yy = $this->today->format('y');

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare(
            "UPDATE supplier SET invoice_number_format = '{YYYY}{CCCC}', credit_note_number_format = 'D{YYYY}{CCCC}',
                    proforma_number_format = 'Z{YYYY}{CCCC}', invoice_number_period = 'year', simplified_number_format = NULL
              WHERE id = ?"
        )->execute([$this->supplierId]);
        $seeder->seedForSupplier($this->supplierId);
        $periods->create($this->supplierId, (int) $this->today->format('Y'), $this->today->format('Y') . '-01-01', $this->today->format('Y') . '-12-31');
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic, main_email, language,
                                  currency_default_id, is_customer, is_vendor)
             VALUES (?, "Řady s.r.o.", "Test 1", "Praha", "11000", ?, "10000004", "rady@example.com", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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

    /**
     * Firma, která v číselníku nic nenastaví, nepozná změnu: stejná čísla, žádná řada na
     * dokladu, editor bez výběru řady a bajtově stejný report úplnosti.
     */
    public function testFirmWithoutNewSeriesSeesNoChange(): void
    {
        $first = $this->issue('invoice');
        $second = $this->issue('invoice');
        $this->issue('credit_note');
        $reportBefore = json_encode($this->completeness->build($this->supplierId, (int) $this->today->format('Y')));
        $previewBefore = $this->varsymbol->preview($this->supplierId, 'invoice', $this->today);

        $this->catalog->sync($this->supplierId);

        self::assertSame($this->today->format('Y') . '0001', $first);
        self::assertSame($this->today->format('Y') . '0002', $second);
        self::assertSame($previewBefore, $this->varsymbol->previewForInvoice($this->supplierId, ['invoice_type' => 'invoice'], $this->today));
        $third = $this->issue('invoice');
        self::assertSame($previewBefore, $third);
        self::assertSame(0, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM invoices WHERE supplier_id = {$this->supplierId} AND number_series_id IS NOT NULL"
        )->fetchColumn());
        self::assertFalse($this->service->options($this->supplierId, 'invoice')['multiple'], 'S jedinou řadou editor výběr neukazuje.');
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')->execute([$this->supplierId, $third]);
        self::assertSame($reportBefore, json_encode($this->completeness->build($this->supplierId, (int) $this->today->format('Y'))));
    }

    public function testNewSeriesAllocatesInTransactionAndRollbackReturnsNumber(): void
    {
        $series = $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'E-shop', 'prefix' => 'FVE'], $this->userId);
        self::assertSame('FVE' . $this->yy . '0001', $series['next_preview']);
        self::assertTrue($this->service->options($this->supplierId, 'invoice')['multiple']);

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT alloc');
        $n1 = $this->issue('invoice', $series['id']);
        self::assertSame('FVE' . $this->yy . '0001', $n1);
        $pdo->exec('ROLLBACK TO SAVEPOINT alloc');
        self::assertSame('FVE' . $this->yy . '0001', $this->issue('invoice', $series['id']), 'Rollback vrátí číslo do řady.');
        self::assertSame('FVE' . $this->yy . '0002', $this->issue('invoice', $series['id']));

        // Dosavadní řada firmy jede dál nezávisle.
        self::assertSame($this->today->format('Y') . '0001', $this->issue('invoice'));

        // Číslo smazaného posledního dokladu se vrací do své řady.
        $row = $this->repo->find($this->supplierId, $series['id']);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')->execute([$this->supplierId, 'FVE' . $this->yy . '0002']);
        self::assertTrue($this->allocator->releaseIfLatest($row, 'FVE' . $this->yy . '0002', $this->today));
        self::assertSame('FVE' . $this->yy . '0002', $this->allocator->peek($this->supplierId, $row, $this->today));
    }

    public function testDefaultSeriesIsUsedAndExplicitLegacyChoiceWins(): void
    {
        $series = $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'Provozovna', 'prefix' => 'FP', 'is_default' => true], $this->userId);
        self::assertSame('FP' . $this->yy . '0001', $this->issue('invoice'));
        $legacy = $this->repo->findByLegacyKey($this->supplierId, 'issued:invoice');
        self::assertSame($this->today->format('Y') . '0001', $this->issue('invoice', (int) $legacy['id']));
        // Dobropis má vlastní výchozí řadu, nová řada faktur ho nepřebírá.
        self::assertSame('D' . $this->today->format('Y') . '0001', $this->issue('credit_note'));

        $this->expectException(NumberSeriesException::class);
        $this->service->update($this->supplierId, $series['id'], ['valid_to' => $this->today->format('Y-m-d')], $this->userId);
    }

    public function testEndedSeriesIsNotOfferedAndRefusesLaterDocuments(): void
    {
        $series = $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'Stará', 'prefix' => 'ST'], $this->userId);
        $yesterday = $this->today->modify('-1 day')->format('Y-m-d');
        $this->service->update($this->supplierId, $series['id'], ['valid_to' => $yesterday], $this->userId);
        self::assertNotContains($series['id'], array_column($this->service->options($this->supplierId, 'invoice')['series'], 'id'));

        $this->expectException(NumberSeriesException::class);
        $this->expectExceptionMessageMatches('/ukončena/');
        $this->issue('invoice', $series['id']);
    }

    public function testOverlappingTemplatesAreRejected(): void
    {
        $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'E-shop', 'prefix' => 'FVE'], $this->userId);
        try {
            $this->service->create($this->supplierId, ['doc_kind' => 'proforma', 'name' => 'Kolize', 'prefix' => 'FVE'], $this->userId);
            self::fail('Dvě řady se stejným tvarem čísla by vydaly totéž číslo.');
        } catch (NumberSeriesException $e) {
            self::assertSame('template_overlap', $e->errorCode);
        }
        try {
            // Tvar dosavadní řady faktur {YYYY}{CCCC}.
            $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'Kolize', 'template' => '{YYYY}{CCCC}', 'period' => 'year'], $this->userId);
            self::fail('Nová řada se nesmí plést s převedenou.');
        } catch (NumberSeriesException $e) {
            self::assertSame('template_overlap', $e->errorCode);
            // Převedená řada nemá název: hláška ji pojmenuje druhem dokladu jako seznam řad.
            self::assertStringStartsWith('Čísla řady by se mohla plést s řadou „Faktury" (', $e->getMessage());
        }
        try {
            $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'Bez roku', 'prefix' => 'X', 'template' => '{PREFIX}{CCCC}', 'period' => 'year'], $this->userId);
            self::fail('Roční řada bez roku v čísle by po resetu opakovala čísla.');
        } catch (NumberSeriesException $e) {
            self::assertSame('template_invalid', $e->errorCode);
        }
    }

    public function testCompletenessIsReportedPerNewSeries(): void
    {
        $series = $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'E-shop', 'prefix' => 'FVE'], $this->userId);
        $this->issue('invoice', $series['id']);
        $gap = $this->issue('invoice', $series['id']);
        $this->issue('invoice', $series['id']);
        $this->issue('invoice');
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE supplier_id = ? AND varsymbol = ?')->execute([$this->supplierId, $gap]);

        $report = $this->service->completeness($this->supplierId, (int) $this->today->format('Y'));
        $missing = [];
        foreach ($report['series'] as $s) {
            foreach ($s['buckets'] as $bucket) {
                $missing[$s['number_series_id']] = array_merge($missing[$s['number_series_id']] ?? [], $bucket['missing']);
            }
        }
        self::assertSame([2], $missing[$series['id']]);
        $legacy = (int) $this->repo->findByLegacyKey($this->supplierId, 'issued:invoice')['id'];
        self::assertSame([], $missing[$legacy], 'Doklady nové řady se do dosavadní řady nepočítají.');
        self::assertSame([], $report['unassigned'], 'Čísla nové řady nejsou „neznámá řada".');
    }

    /** Víc řad v jedné pokladně: jedna pokladní kniha, číslo podle zvolené řady. */
    public function testCashRegisterWithTwoSeriesSharesOneBook(): void
    {
        $register = $this->registers->create($this->supplierId, ['name' => 'Hlavní', 'account_code' => '211', 'is_default' => true]);
        $hp = $this->service->create($this->supplierId, [
            'doc_kind' => 'cash_in', 'scope_id' => $register, 'name' => 'Hotovostní účtenky', 'prefix' => 'HP',
        ], $this->userId);
        $options = $this->service->options($this->supplierId, 'cash_in', $register);
        self::assertTrue($options['multiple']);

        $receipt = $this->cash->create($this->supplierId, $this->sale($register, 100.0, $hp['id']), $this->userId);
        $payment = $this->cash->create($this->supplierId, $this->sale($register, 200.0), $this->userId);
        $receipt2 = $this->cash->create($this->supplierId, $this->sale($register, 50.0, $hp['id']), $this->userId);

        self::assertSame('HP' . $this->yy . '0001', $receipt['doc_number']);
        self::assertSame('HP' . $this->yy . '0002', $receipt2['doc_number']);
        self::assertSame('PPD-' . $this->today->format('Y') . '-0001', $payment['doc_number']);
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(DISTINCT register_id), SUM(total_amount) FROM cash_documents WHERE supplier_id = ?');
        $stmt->execute([$this->supplierId]);
        [$books, $sum] = $stmt->fetch(PDO::FETCH_NUM);
        self::assertSame(1, (int) $books);
        self::assertEqualsWithDelta(350.0, (float) $sum, 0.001);

        // Řada jiné pokladny pro tuto pokladnu neplatí.
        $this->db->pdo()->prepare(
            "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
             VALUES (?, '211.200', 'Pokladna vedlejší', 'asset', 'debit', 0, 1) ON DUPLICATE KEY UPDATE is_active = 1"
        )->execute([$this->supplierId]);
        $other = $this->registers->create($this->supplierId, ['name' => 'Vedlejší', 'account_code' => '211.200']);
        $this->expectException(CashException::class);
        $this->cash->create($this->supplierId, $this->sale($other, 10.0, $hp['id']), $this->userId);
    }

    public function testUserDefaultPreselectsSeries(): void
    {
        $series = $this->service->create($this->supplierId, ['doc_kind' => 'invoice', 'name' => 'Moje', 'prefix' => 'MJ'], $this->userId);
        $this->service->setUserDefault($this->supplierId, $this->userId, 'invoice', 0, $series['id']);
        self::assertSame($series['id'], $this->service->options($this->supplierId, 'invoice', 0, $this->userId)['default_id']);
        $legacy = (int) $this->repo->findByLegacyKey($this->supplierId, 'issued:invoice')['id'];
        self::assertSame($legacy, $this->service->options($this->supplierId, 'invoice', 0, null)['default_id']);
    }

    // ── fixtury ──────────────────────────────────────────────────────────────

    /** Vystaví doklad tak jako IssueInvoiceAction: číslo + zápis řady v téže transakci. */
    private function issue(string $type, ?int $seriesId = null): string
    {
        $date = $this->today->format('Y-m-d');
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code, created_by, number_series_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1000.00, 210.00, 1210.00, "draft", "1", ?, ?)'
        )->execute([$this->supplierId, $type, $this->clientId, $date, $type === 'proforma' ? null : $date, $date,
            $this->currencyId, $this->userId, $seriesId]);
        $id = (int) $pdo->lastInsertId();
        $invoice = ['invoice_type' => $type, 'client_id' => $this->clientId, 'number_series_id' => $seriesId];
        [$vs, $used] = $this->varsymbol->nextForInvoice($this->supplierId, $invoice, $this->today);
        $pdo->prepare('UPDATE invoices SET varsymbol = ?, status = "issued" WHERE id = ?')->execute([$vs, $id]);
        $this->varsymbol->recordSeries($id, $invoice, $used);
        return $vs;
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total, ?int $seriesId = null): array
    {
        $doc = [
            'register_id' => $registerId, 'issue_date' => $this->today->format('Y-m-d'), 'description' => 'Tržba',
            'post' => true, 'purpose' => 'sale', 'doc_type' => 'in', 'total_amount' => $total,
        ];
        if ($seriesId !== null) {
            $doc['number_series_id'] = $seriesId;
        }
        return $doc;
    }
}
