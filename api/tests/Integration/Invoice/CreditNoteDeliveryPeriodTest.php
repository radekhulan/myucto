<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * § 42 odst. 3 ZDPH — období opravy základu daně určuje DORUČENÍ opravného dokladu.
 *
 * Dobropis se zakládal s `tax_date = CURDATE()`, tedy datem VYTVOŘENÍ. Období opravy se
 * ale řídí dnem doručení odběrateli, a ten se od vytvoření běžně liší — typicky přes
 * přelom měsíce. Oprava pak spadla do nesprávného zdaňovacího období a jedinou pojistkou
 * bylo neblokující varování ve výkazu.
 *
 * `effective_tax_date` je generovaný sloupec `COALESCE(tax_date, issue_date)`, takže
 * období řídí `tax_date` — proto se při vystavení odvozuje z data doručení. Samotné
 * datum doručení zůstává v samostatném sloupci, aby šlo doložit, PROČ doklad spadl do
 * daného období.
 *
 * Varování `credit_note_delivery_date_missing` tady ověřené NENÍ: vzniká až v odpovědi,
 * ke které se uvnitř testovací transakce nedá dojít (StatsRecomputer si otevírá vlastní
 * transakci). Testy proto pokrývají to podstatné — že datum doručení skutečně mění
 * období opravy.
 */
#[Group('integration')]
final class CreditNoteDeliveryPeriodTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IssueInvoiceAction $action;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
    private int $seq = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(IssueInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        if (!$this->db->hasColumn('invoices', 'corrective_delivered_on')) {
            $this->markTestSkipped('Migrace 1159 neproběhla.');
        }

        $pdo = $this->db->pdo();
        $source = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$source, $this->userId, $this->currencyId, $this->vatRateId, $czId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        // Vystavení se skladem si nastavuje úroveň izolace, kterou MariaDB v otevřené
        // transakci testu odmítne. Sklad tenhle test neověřuje, takže se vypne.
        $pdo->prepare('UPDATE supplier SET stock_enabled = 0 WHERE id = ?')->execute([$this->supplierId]);

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, dic, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel", "Test 1", "Praha", "11000", ?, "CZ11111111", "o@example.com", "cs", ?, 1, 0)'
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
     * Datum doručení přepíše `tax_date`, takže oprava spadne do SPRÁVNÉHO období.
     * Přes přelom měsíce je to jiné zdaňovací období, než ve kterém se dobropis vytvořil.
     */
    public function testDeliveryDateDrivesTheCorrectionPeriod(): void
    {
        $id = $this->creditNote(taxDate: '2099-03-31', deliveredOn: '2099-04-02');

        $this->issue($id);

        $row = $this->row($id);
        self::assertSame('2099-04-02', $row['tax_date'], 'Období opravy = den doručení.');
        self::assertSame('2099-04-02', $row['effective_tax_date'], 'Generovaný sloupec následuje.');
    }

    /** Bez data doručení zůstává `tax_date` beze změny — chování se nemění zpětně. */
    public function testWithoutDeliveryDateTaxDateStays(): void
    {
        $id = $this->creditNote(taxDate: '2099-03-31', deliveredOn: null);

        $this->issue($id);

        self::assertSame('2099-03-31', $this->row($id)['tax_date']);
    }

    /** Běžné faktury se netýká — datum doručení má smysl jen u opravného dokladu. */
    public function testRegularInvoiceIsUnaffected(): void
    {
        $id = $this->creditNote(taxDate: '2099-03-31', deliveredOn: '2099-04-02', type: 'invoice');

        $this->issue($id);

        self::assertSame('2099-03-31', $this->row($id)['tax_date'],
            'U běžné faktury se DUZP z data doručení neodvozuje.');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Znaménko částek se řídí typem dokladu, ne konstantou: opravný doklad je záporný,
     * běžná faktura kladná. Se zápornou „fakturou" skončí vystavení na `invalid_amount`
     * (409) — a protože se pak `tax_date` nezmění, tvrzení „zůstalo beze změny" projde,
     * aniž by se testovaná větev vůbec spustila.
     */
    private function creditNote(string $taxDate, ?string $deliveredOn, string $type = 'credit_note'): int
    {
        $this->seq++;
        $sign = $type === 'invoice' ? 1 : -1;
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, corrective_delivered_on,
                 due_date, currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "{}", "{}", ?, ?, ?, "draft", ?)'
        )->execute([
            $this->supplierId, $this->clientId, $type,
            $taxDate, $taxDate, $deliveredOn, $taxDate, $this->currencyId,
            $sign * 1000, $sign * 210, $sign * 1210, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Oprava", ?, 1000, ?, 21.00, ?, ?, ?, 1)'
        )->execute([$id, $sign, $this->vatRateId, $sign * 1000, $sign * 210, $sign * 1210]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT tax_date, effective_tax_date, corrective_delivered_on FROM invoices WHERE id = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Vystaví doklad a vrátí varování. Vystavení se uvnitř testovací transakce dokončit
     * nedá (akce si nastavuje úroveň izolace), ale UPDATE `tax_date` proběhne dřív —
     * což je právě to, co se ověřuje.
     *
     * @return list<string>
     */
    private function issue(int $id): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);

        try {
            $res = ($this->action)($req, new Psr7Response(), ['id' => (string) $id]);
        } catch (\PDOException $e) {
            // Akce po UPDATE pokračuje prací, která si otevírá vlastní transakci; uvnitř
            // testovací transakce to nejde. `tax_date` je v tu chvíli UŽ nastavený, což je
            // přesně to, co se ověřuje.
            if ((int) ($e->errorInfo[1] ?? 0) === 1568
                || str_contains($e->getMessage(), 'already an active transaction')) {
                return [];
            }
            throw $e;
        }

        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        // Status se ověřuje TADY, ne až na tax_date: když akce skončí chybou, UPDATE
        // vůbec neproběhne a test pak hlásí „očekáváno 2099-04-02, dostal 2099-03-31" —
        // což o skutečné příčině neřekne nic. Takhle je v hlášce rovnou důvod.
        self::assertSame(200, $res->getStatusCode(), 'Vystavení dobropisu selhalo: ' . (string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return is_array($body['data']['warnings'] ?? null) ? $body['data']['warnings'] : [];
    }
}
