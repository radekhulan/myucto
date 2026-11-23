<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\InvoiceIssueTaxGate;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Dobropis z faktury (detail faktury, MCP `cancel_invoice`, `POST /api/invoices/{id}/cancel`)
 * drobnému zákazníkovi (#310): vzniká rovnou jako zjednodušený opravný daňový doklad
 * (§ 45 odst. 2 ZDPH) a projde bránou vystavení. Běžnému odběrateli se příznak nedědí.
 *
 * Akce dobropisu otevírá vlastní transakci, proto test neběží v obalové transakci
 * jako {@see SimplifiedOnlyClientTest} a po sobě uklízí.
 */
#[Group('integration')]
final class SimplifiedOnlyCreditNoteFromInvoiceTest extends TestCase
{
    private ContainerInterface $container;
    private Connection $db;
    private InvoiceRepository $repo;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $rateId = 0;
    private int $currencyId = 0;
    private int $countryId = 0;
    /** @var list<int> */
    private array $invoiceIds = [];
    /** @var list<int> */
    private array $clientIds = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB connection.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(InvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query(
            "SELECT id FROM supplier WHERE accounting_mode = 'tax_evidence' AND is_vat_payer = 1 ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE country = 'CZ' AND rate_percent = 21 AND is_reverse_charge = 0 AND valid_to IS NULL ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->userId, $this->rateId, $this->currencyId, $this->countryId], true)) {
            $this->markTestSkipped('Chybí plátce v daňové evidenci, uživatel, sazba 21 %, CZK nebo země CZ.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->invoiceIds !== []) {
            $ids = implode(',', array_map('intval', $this->invoiceIds));
            $children = $pdo->query("SELECT id FROM invoices WHERE parent_invoice_id IN ({$ids})")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ([...$children, ...array_reverse($this->invoiceIds)] as $id) {
                $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([(int) $id]);
            }
        }
        foreach ($this->clientIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    public function testCreditNoteForSmallCustomerIsSimplifiedAndPassesGate(): void
    {
        $original = $this->issuedInvoice($this->client(simplifiedOnly: true), simplified: true);

        $creditNote = $this->repo->find($this->creditNoteFrom($original));

        self::assertTrue($creditNote['is_simplified'], 'Dobropis z faktury je zjednodušený opravný doklad.');
        self::assertSame(-1210.0, (float) $creditNote['total_with_vat']);
        $gate = $this->container->get(InvoiceIssueTaxGate::class);
        self::assertNull($gate->simplifiedOnlyClientRejection($creditNote));
        self::assertNull($gate->simplifiedRejection($creditNote));
    }

    public function testCreditNoteForRegularCustomerStaysRegular(): void
    {
        $original = $this->issuedInvoice($this->client(simplifiedOnly: false), simplified: true);

        $creditNote = $this->repo->find($this->creditNoteFrom($original));

        self::assertFalse($creditNote['is_simplified'], 'Známému odběrateli se vystaví běžný opravný doklad.');
    }

    private function client(bool $simplifiedOnly): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email,
                                  language, currency_default_id, is_customer, is_vendor, simplified_only)
             VALUES (?, ?, ?, ?, ?, ?, "dobropis@example.invalid", "cs", ?, 1, 0, ?)'
        )->execute([
            $this->supplierId,
            $simplifiedOnly ? 'Pepa Novák' : 'Stálý odběratel s.r.o.',
            $simplifiedOnly ? '' : 'Test 1',
            $simplifiedOnly ? '' : 'Praha',
            $simplifiedOnly ? '' : '11000',
            $this->countryId,
            $this->currencyId,
            $simplifiedOnly ? 1 : 0,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->clientIds[] = $id;

        return $id;
    }

    private function issuedInvoice(int $clientId, bool $simplified): int
    {
        $today = date('Y-m-d');
        $id = $this->repo->createDraft([
            'invoice_type' => 'invoice', 'client_id' => $clientId, 'issue_date' => $today,
            'tax_date' => $today, 'due_date' => $today, 'currency_id' => $this->currencyId,
            'reverse_charge' => false, 'language' => 'cs', 'is_simplified' => $simplified,
        ], $this->userId);
        $this->invoiceIds[] = $id;
        $this->repo->replaceItems($id, [
            ['description' => 'Drobný prodej', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->rateId],
        ]);
        $this->container->get(InvoiceCalculator::class)->recompute($id);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued', varsymbol = ?, vat_classification_code = '1' WHERE id = ?")
            ->execute(['ZD' . random_int(1000000, 9999999), $id]);

        return $id;
    }

    private function creditNoteFrom(int $originalId): int
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $originalId . '/cancel')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody(['mode' => 'credit_note', 'reason' => 'Vrácení zboží']);
        $res = ($this->container->get(CancelInvoiceAction::class))($req, new Psr7Response(), ['id' => (string) $originalId]);
        $res->getBody()->rewind();
        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());

        $stmt = $this->db->pdo()->prepare("SELECT id FROM invoices WHERE parent_invoice_id = ? AND invoice_type = 'credit_note'");
        $stmt->execute([$originalId]);
        $creditNoteId = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $creditNoteId);

        return $creditNoteId;
    }
}
