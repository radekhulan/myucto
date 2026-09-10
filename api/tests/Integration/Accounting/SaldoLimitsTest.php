<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Strop, stránkování a SQL filtr otevřenosti saldokonta.
 *
 * Saldokonto bylo jediná sestava bez horní hranice počtu řádků: `SaldoRepository`
 * načetl do PHP KAŽDÝ doklad, který firma kdy zaúčtovala na 311/321, a uzavřené
 * položky zahazoval až `SaldoService`. Nad ~400 tis. doklady to znamenalo >750 MB
 * a pád na `memory_limit` (v Dockeru s 256 MB už kolem 200 tis.), a s ním padala
 * i kontrola úplnosti dokladů, která saldo volá dvakrát.
 *
 * Testy tady hlídají tři věci, které to řeší, a každá z nich BEZ opravy padá:
 *   1. uzavřenou položku odfiltruje už SQL (dřív dorazila do PHP),
 *   2. `openItems()` respektuje `$limit` (dřív žádný neexistoval),
 *   3. filtr partnera je v SQL PŘED limitem (jinak by strop spotřebovali cizí
 *      partneři a položky hledaného by tiše vypadly),
 * plus strop sestavy (422 `too_many_rows`) a stránkování partnerů, které nesmí
 * zkreslit součty ani konfrontaci s hlavní knihou.
 */
#[Group('integration')]
final class SaldoLimitsTest extends TestCase
{
    private const YEAR = 2098;

    private Connection $db;
    private PostingService $posting;
    private SaldoService $saldo;
    private SaldoRepository $repo;
    private AccountingPeriodRepository $periods;
    private ChartOfAccountsSeeder $seeder;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
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
            $this->db      = $container->get(Connection::class);
            $this->posting = $container->get(PostingService::class);
            $this->saldo   = $container->get(SaldoService::class);
            $this->repo    = $container->get(SaldoRepository::class);
            $this->periods = $container->get(AccountingPeriodRepository::class);
            $this->seeder  = $container->get(ChartOfAccountsSeeder::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0 || $this->czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/currency/user/country) v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $iso = $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             SELECT ?, "Testovací", "Praha", "11000", country_id, ?, default_currency_id, default_vat_rate_id
               FROM supplier WHERE id = ?'
        );
        $iso->execute(['Saldo limity s.r.o.', 'saldo-limits@example.com', $this->supplierId]);
        $this->supplierId = (int) $pdo->lastInsertId();

        $this->seeder->seedForSupplier($this->supplierId);
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

    /**
     * Uzavřenou položku nesmí repository vůbec načíst.
     *
     * BEZ OPRAVY PADÁ: `fetchOpenInvoices()` neměl filtr otevřenosti, takže plně
     * uhrazená faktura z SQL přišla a zahodil ji až `SaldoService`. Právě tím se
     * do paměti tahalo desetinásobek toho, co sestava zobrazí.
     */
    public function testFullySettledInvoiceIsNotEvenFetched(): void
    {
        $client = $this->client('Alfa s.r.o.');
        $open = $this->postedInvoice($client, 1210.00, '-03-10');
        $paid = $this->postedInvoice($client, 605.00, '-03-11');
        $this->payInFull($paid, 605.00, self::YEAR . '-03-15');

        $ids = $this->openDocIds(self::YEAR . '-12-31');

        self::assertContains($open, $ids, 'Neuhrazená faktura je otevřená položka.');
        self::assertNotContains($paid, $ids, 'Plně uhrazená faktura se ze SQL vracet nesmí.');
    }

    /**
     * `openItems()` musí respektovat strop počtu řádků.
     *
     * BEZ OPRAVY PADÁ: parametr `$limit` neexistoval, sestava neměla horní hranici
     * a načítala celou historii firmy.
     */
    public function testLimitCapsFetchedRows(): void
    {
        $client = $this->client('Alfa s.r.o.');
        $this->postedInvoice($client, 1210.00, '-03-10');
        $this->postedInvoice($client, 1210.00, '-03-11');
        $this->postedInvoice($client, 1210.00, '-03-12');

        self::assertCount(3, $this->openDocIds(self::YEAR . '-12-31'), 'Bez limitu přijdou všechny tři.');
        self::assertCount(2, $this->openDocIds(self::YEAR . '-12-31', 2), 'S limitem 2 přijdou dvě.');
    }

    /**
     * Filtr partnera musí být v SQL, ne až v PHP nad už oříznutým výsledkem.
     *
     * BEZ OPRAVY PADÁ: `openItems()` filtr partnera neznal a `SaldoService` ho
     * aplikoval až na načtené řádky. V kombinaci se stropem by limit spotřebovali
     * partneři před hledaným v abecedě a jeho položky by ze sestavy TIŠE vypadly —
     * účetní by u konkrétního odběratele viděla prázdné saldo.
     */
    public function testPartnerFilterIsAppliedInSqlBeforeLimit(): void
    {
        $alfa = $this->client('Alfa s.r.o.');
        $zeta = $this->client('Zeta s.r.o.');
        $this->postedInvoice($alfa, 1210.00, '-03-10');
        $this->postedInvoice($alfa, 1210.00, '-03-11');
        $zetaFirst = $this->postedInvoice($zeta, 605.00, '-03-12');
        $zetaSecond = $this->postedInvoice($zeta, 605.00, '-03-13');

        $ids = $this->openDocIds(self::YEAR . '-12-31', 2, $zeta);

        self::assertSame([$zetaFirst, $zetaSecond], $ids, 'Limit se má vyčerpat na hledaném partnerovi, ne na Alfě.');
    }

    /**
     * Nad stropem otevřených položek sestava skončí 422, ne useknutým seznamem:
     * konfrontace Σ položek se zůstatkem hlavní knihy dává smysl jen nad úplným
     * seznamem, jinak by se chybějící řádky vykázaly jako inventarizační rozdíl.
     *
     * Testuje se přes `buildAccount()` s rozpočtem 1 — `MAX_OPEN_ITEMS` je 25 000
     * a založit tolik dokladů v testu je nesmysl. Hlídá se ta samá brána, kterou
     * projde i celá sestava.
     *
     * BEZ OPRAVY PADÁ: žádná brána neexistovala, sestava místo 422 spadla na
     * `memory_limit` (fatal, prázdná odpověď).
     */
    public function testReportRefusesAboveOpenItemsCap(): void
    {
        $client = $this->client('Alfa s.r.o.');
        $this->postedInvoice($client, 1210.00, '-03-10');
        $this->postedInvoice($client, 1210.00, '-03-11');

        $build = new \ReflectionMethod(SaldoService::class, 'buildAccount');

        try {
            $build->invoke($this->saldo, $this->supplierId, self::YEAR . '-12-31', '311', null, [], 1);
            self::fail('Očekávána ReportException too_many_rows.');
        } catch (ReportException $e) {
            self::assertSame('too_many_rows', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    public function testRoundedZeroCandidatesDoNotConsumeTheDefinitiveOpenItemBudget(): void
    {
        $client = $this->client('Alfa s.r.o.');
        $roundedZero = $this->postedInvoice($client, 1000.00, '-03-01');
        $this->db->pdo()->prepare(
            'UPDATE journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
                SET l.amount = ROUND(l.amount / 100, 2)
              WHERE e.supplier_id = ? AND e.source_type = "invoice" AND e.source_id = ?'
        )->execute([$this->supplierId, $roundedZero]);
        $this->payInFull($roundedZero, 999.53, self::YEAR . '-03-15');
        $this->postedInvoice($client, 1210.00, '-03-02');
        $this->postedInvoice($client, 1210.00, '-03-03');

        $build = new \ReflectionMethod(SaldoService::class, 'buildAccount');

        try {
            $build->invoke($this->saldo, $this->supplierId, self::YEAR . '-12-31', '311', null, [], 1);
            self::fail('SQL nadmnožina nesmí skrýt druhou skutečně otevřenou položku za haléřově nulovým kandidátem.');
        } catch (ReportException $e) {
            self::assertSame('too_many_rows', $e->errorCode);
            self::assertSame(422, $e->httpStatus);
        }
    }

    /**
     * Stránkování řeže JEN seznam partnerů k zobrazení. `gl_balance`,
     * `open_items_total`, `difference` a `matches` musí zůstat za CELOU sestavu —
     * číslo spočítané ze stránky by bylo účetně nepravdivé.
     *
     * BEZ OPRAVY PADÁ: `build()` stránkování neumělo a `partners_pagination`
     * v odpovědi nebylo.
     */
    public function testPartnersPaginationKeepsWholeReportTotals(): void
    {
        foreach (['Alfa s.r.o.', 'Beta a.s.', 'Cedr s.r.o.'] as $i => $name) {
            $this->postedInvoice($this->client($name), 1210.00, '-03-1' . $i);
        }

        $full = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311'));
        self::assertNotNull($full);
        self::assertCount(3, $full['partners'], 'Bez stránkování přijdou všichni partneři.');
        self::assertSame(3, $full['open_items_count']);
        self::assertSame(1, $full['partners_pagination']['pages'], 'Bez stránkování je vše na jedné stránce.');

        $page2 = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311', null, 2, 1));
        self::assertNotNull($page2);
        self::assertCount(1, $page2['partners'], 'Stránka nese jednoho partnera.');
        self::assertSame('Beta a.s.', $page2['partners'][0]['partner_name'], 'Druhý partner v abecedním pořadí.');
        self::assertSame(
            ['page' => 2, 'per_page' => 1, 'total' => 3, 'pages' => 3],
            $page2['partners_pagination'],
        );

        self::assertSame(
            self::cents($full['open_items_total']),
            self::cents($page2['open_items_total']),
            'Σ otevřených položek je za celou sestavu, ne za stránku.',
        );
        self::assertSame(self::cents($full['gl_balance']), self::cents($page2['gl_balance']));
        self::assertSame(self::cents($full['difference']), self::cents($page2['difference']));
        self::assertSame($full['matches'], $page2['matches']);
        self::assertSame(3, $page2['open_items_count'], 'Počet otevřených položek je za celou sestavu.');
    }

    /** Stránka za posledním partnerem se srovná na poslední existující, ne na prázdno. */
    public function testPaginationClampsPageToRange(): void
    {
        $this->postedInvoice($this->client('Alfa s.r.o.'), 1210.00, '-03-10');

        $block = $this->accBlock($this->saldo->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '311', null, 99, 1));
        self::assertNotNull($block);
        self::assertCount(1, $block['partners']);
        self::assertSame(1, $block['partners_pagination']['page']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function client(string $name): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, currency_default_id)
             VALUES (?, ?, "Ulice 1", "Praha", "11000", ?, ?, ?)'
        );
        $stmt->execute([$this->supplierId, $name, $this->czId, 'c' . uniqid() . '@example.com', $this->currencyId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Vydaná faktura zaúčtovaná 311 MD / 602 + 343 D k `self::YEAR . $dayOffset`. */
    private function postedInvoice(int $clientId, float $total, string $monthDay): int
    {
        $date = self::YEAR . $monthDay;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, client_id, issue_date, due_date, currency_id, created_by, total_with_vat, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, "issued")'
        );
        $stmt->execute([
            $this->supplierId, (string) random_int(1000000000, 1999999999), $clientId,
            $date, $date, $this->currencyId, $this->userId, $total,
        ]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $base = round($total / 1.21, 2);
        $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $id,
            [
                ['account_code' => '311', 'side' => 'debit', 'amount' => $total],
                ['account_code' => '602', 'side' => 'credit', 'amount' => $base],
                ['account_code' => '343', 'side' => 'credit', 'amount' => round($total - $base, 2)],
            ],
            ['entry_date' => $date, 'posted_by' => $this->userId, 'user_id' => $this->userId],
        );

        return $id;
    }

    /** Úhrada bez bankovního pohybu — datum uznání padne na `invoice_payments.paid_on`. */
    private function payInFull(int $invoiceId, float $amount, string $paidOn): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, ?, "CZK", "manual")'
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount]);
    }

    /**
     * ID otevřených položek 311 v pořadí, v jakém je vrací repository.
     *
     * @return list<int>
     */
    private function openDocIds(string $asOf, ?int $limit = null, ?int $partnerId = null): array
    {
        $acc = $this->repo->resolveAccount($this->supplierId, '311');
        self::assertNotNull($acc, 'Účet 311 chybí v osnově.');

        return array_map(
            static fn (array $it): int => (int) $it['doc_id'],
            $this->repo->openItems($this->supplierId, (int) $acc['id'], $asOf, '311', $limit, $partnerId),
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function accBlock(array $data): ?array
    {
        foreach ($data['accounts'] as $acc) {
            if ((string) $acc['account']['code'] === '311') {
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
