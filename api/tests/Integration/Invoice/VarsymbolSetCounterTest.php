<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Explicitní nastavení counteru číselné řady (API endpoint
 * PUT /api/settings/supplier/invoice-counter → VarsymbolGenerator::setCounter()):
 * příští vystavený doklad má dostat přesně zadané číslo, včetně SNÍŽENÍ counteru
 * (na rozdíl od syncCounter). Kolize s existujícími čísly řeší self-heal v next().
 *
 * Izolace: rok 2099 (viz VarsymbolCounterSyncTest). Soft-skip bez cfg.php / DB / template.
 */
#[Group('integration')]
final class VarsymbolSetCounterTest extends TestCase
{
    private Connection $db;
    private VarsymbolGenerator $gen;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $template = '';
    private \DateTimeImmutable $date;
    /** @var int[] */
    private array $created = [];
    /** @var int[] */
    private array $createdCategories = [];
    /** @var int[] */
    private array $createdClients = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->gen = $c->get(VarsymbolGenerator::class);
            $config = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->template = trim((string) ($pdo->query(
            "SELECT invoice_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn() ?: ''));
        if ($this->template === '') {
            $this->template = trim((string) $config->get('varsymbol.templates.invoice', ''));
        }
        if ($this->template === '' || !str_contains($this->template, '{C')) {
            $this->markTestSkipped('Není template s counterem ({C+}) pro vydanou fakturu.');
        }

        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/CZK currency/user.');
        }

        $this->date = new \DateTimeImmutable('2099-06-15');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period LIKE '2099%'")
            ->execute([$this->supplierId]);
        foreach ($this->createdCategories as $id) {
            $pdo->prepare('DELETE FROM invoice_counters WHERE revenue_category_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM revenue_categories WHERE id = ?')->execute([$id]);
        }
        $this->createdCategories = [];
        foreach ($this->createdClients as $id) {
            $pdo->prepare('DELETE FROM invoice_counters WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->createdClients = [];
    }

    /** Kategorie tržby s vlastní řadou; prázdná šablona = zdědí řadu dodavatele. */
    private function createCategory(string $code, string $invoiceTemplate): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format, invoice_number_period)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $this->supplierId, $code, "SetCounter {$code}",
            $invoiceTemplate === '' ? null : $invoiceTemplate, 'year',
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->createdCategories[] = $id;
        return $id;
    }

    /** Kategorie JINÉHO dodavatele — fixtura druhé firmy zakládá tests/bootstrap.php. */
    private function createForeignCategory(): int
    {
        $pdo = $this->db->pdo();
        $foreignSupplierId = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE id <> {$this->supplierId} ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        self::assertGreaterThan(0, $foreignSupplierId, 'Test izolace tenantů potřebuje druhého dodavatele.');

        $pdo->prepare(
            'INSERT INTO revenue_categories (supplier_id, code, label, invoice_number_format, invoice_number_period)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$foreignSupplierId, 'SC2099FRGN', 'SetCounter cizí', '8{YYYY}{CCC}', 'year']);
        $id = (int) $pdo->lastInsertId();
        $this->createdCategories[] = $id;
        return $id;
    }

    private function createClientWithOwnSeries(string $invoiceTemplate): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, ic,
                                  main_email, language, currency_default_id, is_customer, is_vendor,
                                  invoice_number_format, invoice_number_period)
             SELECT ?, "SetCounter vlastní řada", "Test 9", "Praha", "11000", c.country_id, "10000004",
                    "setcounter@example.com", "cs", ?, 1, 0, ?, "year"
               FROM supplier c WHERE c.id = ?'
        )->execute([$this->supplierId, $this->currencyId, $invoiceTemplate, $this->supplierId]);
        $id = (int) $pdo->lastInsertId();
        $this->createdClients[] = $id;
        return $id;
    }

    private function insertIssued(int $counter): string
    {
        $varsymbol = $this->gen->render($this->template, $this->date, $counter);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', 0, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId,
            $this->date->format('Y-m-d'), $this->date->format('Y-m-d'), $this->date->format('Y-m-d'),
            $this->currencyId, $this->userId,
        ]);
        $this->created[] = (int) $pdo->lastInsertId();
        return $varsymbol;
    }

    public function testSetCounterMakesNextIssueUseExactNumber(): void
    {
        $result = $this->gen->setCounter($this->supplierId, 'invoice', 42, $this->date);

        self::assertSame(41, $result['counter'], 'last_number = next_number - 1');
        self::assertSame($this->gen->render($this->template, $this->date, 42), $result['preview']);

        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);
        self::assertSame($result['preview'], $next, 'Příští vystavení dostane přesně nastavené číslo.');
    }

    public function testSetCounterCanLowerCounter(): void
    {
        // Counter vepředu na 50 (např. po testovacích fakturách, které byly smazané).
        $this->gen->setCounter($this->supplierId, 'invoice', 51, $this->date);
        // Na rozdíl od syncCounter jde explicitně snížit.
        $result = $this->gen->setCounter($this->supplierId, 'invoice', 5, $this->date);
        self::assertSame(4, $result['counter']);

        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);
        self::assertSame($this->gen->render($this->template, $this->date, 5), $next);
    }

    public function testNextSelfHealsWhenSetCounterCollides(): void
    {
        // Číslo 7 už je obsazené vystavenou fakturou.
        $occupied = $this->insertIssued(7);

        // Admin přesto nastaví řadu na 7 → vystavení nesmí číslo zduplikovat.
        $this->gen->setCounter($this->supplierId, 'invoice', 7, $this->date);
        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);

        self::assertNotSame($occupied, $next, 'Kolize se musí self-healnout, ne zduplikovat.');
        self::assertSame($this->gen->render($this->template, $this->date, 8), $next);
    }

    public function testPeekShowsStoredNextNumberWithoutWriting(): void
    {
        $this->gen->setCounter($this->supplierId, 'invoice', 42, $this->date);

        $peek = $this->gen->peek($this->supplierId, 'invoice', $this->date);
        self::assertNotNull($peek);
        self::assertSame(42, $peek['next_number']);
        self::assertSame(41, $peek['counter']);

        // Peek nic nezapisuje: opakované volání i vystavení dají totéž číslo.
        self::assertSame($peek, $this->gen->peek($this->supplierId, 'invoice', $this->date));
        self::assertSame($peek['preview'], $this->gen->next($this->supplierId, 'invoice', $this->date));
    }

    public function testPeekSkipsOccupiedNumberLikeNext(): void
    {
        $this->insertIssued(7);
        $this->gen->setCounter($this->supplierId, 'invoice', 7, $this->date);

        $peek = $this->gen->peek($this->supplierId, 'invoice', $this->date);
        self::assertSame(8, $peek['next_number'], 'UI má ukázat číslo, které vystavení opravdu použije.');
        self::assertSame($peek['preview'], $this->gen->next($this->supplierId, 'invoice', $this->date));
    }

    /**
     * Přechod z jiného software se netýká jen řady dodavatele — přebít šablonu jde na
     * třech osách (klient > kategorie tržby > dodavatel) a na každé z nich může běžet
     * vlastní rozjetá řada. Counter proto musí jít nastavit na TÉ ose, které šablona
     * patří, a zapsat se na její řádek `invoice_counters`.
     */
    public function testSetCounterTargetsRevenueCategorySeries(): void
    {
        $categoryId = $this->createCategory('SC2099CAT', '9{YYYY}{CCC}');

        $result = $this->gen->setCounter($this->supplierId, 'invoice', 56, $this->date, 0, $categoryId);

        self::assertSame(55, $result['counter']);
        self::assertSame($categoryId, $result['revenue_category_id'], 'Counter vzniká na ose kategorie.');
        self::assertSame(0, $result['client_id']);
        self::assertSame('92099056', $result['preview'], 'Náhled musí vycházet ze šablony kategorie.');

        $row = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = 0 AND revenue_category_id = ? AND invoice_type = ? AND period = ?'
        );
        $row->execute([$this->supplierId, $categoryId, 'invoice', $result['period']]);
        self::assertSame(55, (int) $row->fetchColumn(), 'Zápis jde na řádek řady kategorie.');

        $next = $this->gen->next($this->supplierId, 'invoice', $this->date, 0, $categoryId);
        self::assertSame('92099056', $next);
    }

    public function testSetCounterTargetsClientSeries(): void
    {
        $clientId = $this->createClientWithOwnSeries('K{YYYY}{CCCC}');

        $result = $this->gen->setCounter($this->supplierId, 'invoice', 56, $this->date, $clientId);

        self::assertSame($clientId, $result['client_id']);
        self::assertSame(0, $result['revenue_category_id']);
        self::assertSame('K20990056', $result['preview']);
        self::assertSame('K20990056', $this->gen->next($this->supplierId, 'invoice', $this->date, $clientId));
    }

    /**
     * Cizí kategorie (jiný dodavatel) nesmí skončit tichým zápisem do řady dodavatele
     * přihlášeného uživatele — resolver ji nevidí, osa nevyhraje a požadavek padá.
     * Multi-tenant izolace, ne kosmetika.
     */
    public function testSetCounterRejectsForeignRevenueCategory(): void
    {
        $foreignId = $this->createForeignCategory();

        try {
            $this->gen->setCounter($this->supplierId, 'invoice', 56, $this->date, 0, $foreignId);
            self::fail('Cizí kategorie tržby musí být odmítnuta.');
        } catch (\InvalidArgumentException) {
            // očekáváno
        }

        $row = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM invoice_counters WHERE supplier_id = ? AND revenue_category_id = ?'
        );
        $row->execute([$this->supplierId, $foreignId]);
        self::assertSame(0, (int) $row->fetchColumn(), 'Do cizí scope se nesmí nic zapsat.');

        // A hlavně: nesmí to spadnout do supplier-wide řady, kterou uživatel needitoval.
        $row = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoice_counters
              WHERE supplier_id = ? AND client_id = 0 AND revenue_category_id = 0 AND period LIKE '2099%'"
        );
        $row->execute([$this->supplierId]);
        self::assertSame(0, (int) $row->fetchColumn(), 'Cizí id nesmí přepsat řadu dodavatele.');
    }

    /** Zděděná šablona = společná řada s dodavatelem; vlastní počítadlo by nikdo nečetl. */
    public function testSetCounterRejectsAxisWithoutOwnTemplate(): void
    {
        $categoryId = $this->createCategory('SC2099INH', '');

        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'invoice', 56, $this->date, 0, $categoryId);
    }

    public function testSetCounterRejectsBothAxesAtOnce(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'invoice', 56, $this->date, 1, 1);
    }

    public function testSetCounterRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'invoice', 0, $this->date);
    }

    public function testSetCounterRejectsUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'nonsense', 1, $this->date);
    }
}
