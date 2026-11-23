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
 * § 29 odst. 2 ZDPH — doklad v režimu přenesené daňové povinnosti musí nést DIČ odběratele.
 *
 * Matice DPH to vedla jako CHYBÍ (blokující validace): dosud se na chybějící DIČ jen
 * upozorňovalo ve výkazu, tedy AŽ PO odeslání dokladu. Navíc v kontrolním hlášení takový
 * řádek vůbec nevznikne (`cleanDic() === ''` ho vyřadí), takže plnění z KH tiše vypadne
 * a nesedí na přiznání — a to se pozná až při podání.
 *
 * Blokuje se při VYSTAVENÍ, ne při uložení konceptu: rozpracovaný doklad smí být neúplný,
 * vystavený daňový doklad ne.
 */
#[Group('integration')]
final class ReverseChargeDicRequiredTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IssueInvoiceAction $action;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
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
            $this->db = $c->get(Connection::class);
            $this->action = $c->get(IssueInvoiceAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
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

        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Odběratel bez DIČ", "Test 1", "Praha", "11000", ?, "o@example.com", "cs", ?, 1, 0)'
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

    /** RC doklad bez DIČ odběratele se nesmí vystavit. */
    public function testReverseChargeWithoutDicIsBlocked(): void
    {
        $id = $this->draft(reverseCharge: true);

        $res = $this->issue($id);

        self::assertSame(422, $res['status']);
        self::assertSame('reverse_charge_dic_missing', $res['code']);
        self::assertSame('draft', $this->statusOf($id), 'Doklad musí zůstat konceptem.');
    }

    /** S doplněným DIČ projde — validace nesmí blokovat legitimní doklad. */
    public function testReverseChargeWithDicPasses(): void
    {
        $this->setClientDic('CZ12345678');
        $id = $this->draft(reverseCharge: true);

        $res = $this->issue($id);

        self::assertNotSame(422, $res['status'], 'S DIČ nesmí padat na § 29.');
        self::assertNotSame('reverse_charge_dic_missing', $res['code']);
    }

    /**
     * Běžný doklad BEZ přenesené povinnosti se neblokuje — DIČ u něj povinné není
     * a plošná validace by znemožnila fakturovat neplátcům.
     */
    public function testNonReverseChargeWithoutDicIsAllowed(): void
    {
        $id = $this->draft(reverseCharge: false);

        $res = $this->issue($id);

        self::assertNotSame('reverse_charge_dic_missing', $res['code']);
    }

    /** Samotné DIČ bez číslic (jen prefix) je stejně nepoužitelné jako prázdné. */
    public function testPrefixOnlyDicIsTreatedAsMissing(): void
    {
        $this->setClientDic('CZ');
        $id = $this->draft(reverseCharge: true);

        self::assertSame('reverse_charge_dic_missing', $this->issue($id)['code']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function setClientDic(string $dic): void
    {
        $this->db->pdo()->prepare('UPDATE clients SET dic = ? WHERE id = ?')
            ->execute([$dic, $this->clientId]);
    }

    private function draft(bool $reverseCharge): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, client_id, invoice_type, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, client_snapshot, supplier_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", "2099-03-01", "2099-03-01", "2099-03-15",
                     ?, ?, "{}", "{}", 10000, 0, 10000, "draft", ?)'
        )->execute([$this->supplierId, $this->clientId, $this->currencyId, $reverseCharge ? 1 : 0, $this->userId]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Plnění", 1, 10000, ?, 0, 10000, 0, 10000, 1)'
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    private function statusOf(int $id): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM invoices WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * Vystavení dokladu. Vrací kód chyby, nebo `passed_validation`.
     *
     * Vlastní zaúčtování se uvnitř testovací transakce dokončit NEDÁ — akce si nastavuje
     * úroveň izolace, což MariaDB v otevřené transakci odmítne (chyba 1568; text hlášky se mezi 11.8 a 12.3 liší). Pro tenhle test to nevadí,
     * naopak: dojít až tam znamená, že validace § 29 doklad PUSTILA, což je přesně to, co
     * pozitivní případy dokazují. Chybu tedy nepolykám, jen ji odliším od zamítnutí.
     *
     * @return array{status:int, code:?string}
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
            if ((int) ($e->errorInfo[1] ?? 0) === 1568) {
                return ['status' => 0, 'code' => 'passed_validation'];
            }
            throw $e;
        }

        $res->getBody()->rewind();
        $body = json_decode((string) $res->getBody(), true);

        return [
            'status' => $res->getStatusCode(),
            'code' => is_array($body) ? ($body['error']['code'] ?? null) : null,
        ];
    }
}
