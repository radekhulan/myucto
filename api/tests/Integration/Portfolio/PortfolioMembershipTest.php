<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Portfolio;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Portfolio\PortfolioAggregationService;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Přehled firem pro účetní kancelář (Fáze F, audit 2026-07 P2/M) — musí vrátit
 * JEN firmy z `user_suppliers` přihlášeného uživatele (tenant/membership izolace,
 * stejná sémantika jako SupplierAccessResolver F0):
 *   - membership {A}      → vidí jen A (ne B ani jiné existující firmy)
 *   - bez membership řádků → BC, vidí vše (accountant/readonly)
 *   - role 'admin'         → vidí vše bez ohledu na vlastní membership řádky
 *
 * Fixtures: vlastní testovací uživatelé a dvě syntetické firmy v transakci,
 * kterou tearDown vrací.
 */
#[Group('integration')]
final class PortfolioMembershipTest extends TestCase
{
    use IsolatedSupplierTrait;
    private Connection $db;
    private PortfolioAggregationService $portfolio;

    private int $supplierA = 0;
    private int $supplierB = 0;
    private array $currencyIds = [];

    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->portfolio = $container->get(PortfolioAggregationService::class);
        $pdo = $this->db->pdo();
        $sourceId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $sourceId);
        $pdo->beginTransaction();
        $this->supplierA = $this->fixtureSupplier($sourceId, 'double_entry');
        $this->supplierB = $this->fixtureSupplier($sourceId, 'tax_evidence');
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        $this->userIds = [];
    }

    private function fixtureSupplier(int $sourceId, string $mode): int
    {
        $pdo = $this->db->pdo();
        $supplier = $this->createIsolatedSupplier($pdo, $sourceId);
        $pdo->prepare("UPDATE supplier SET company_name = 'Syntetická firma portfolia', display_name = 'Syntetická firma portfolia', accounting_mode = ? WHERE id = ?")
            ->execute([$mode, $supplier]);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default) VALUES (?, 'CZK', 'CZK', 'Kč', 'Česká koruna', 'Czech Koruna', 2, 1, 1)")
            ->execute([$supplier]);
        $this->currencyIds[$supplier] = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([$this->currencyIds[$supplier], $supplier]);
        return $supplier;
    }

    private function mkUser(string $role): int
    {
        $email = '__test_portfolio_' . bin2hex(random_bytes(6)) . '@example.com';
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
             VALUES (?, '\$2y\$10\$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', '__TEST Portfolio', ?, 'cs', 1)"
        );
        $stmt->execute([$email, $this->roleId($role)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @param list<int> $supplierIds */
    private function assign(int $userId, array $supplierIds): void
    {
        $ins = $this->db->pdo()->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, NULL)');
        foreach ($supplierIds as $sid) {
            $ins->execute([$userId, $sid]);
        }
    }

    public function testMembershipRestrictsToAssignedSuppliersOnly(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA]);

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertContains($this->supplierA, $ids, 'Přiřazená firma A musí být v přehledu.');
        self::assertNotContains($this->supplierB, $ids, 'Firma B mimo membership NESMÍ uniknout do přehledu.');
        self::assertCount(1, $ids, 'S membershipem {A} vidí uživatel jen jednu firmu.');
    }

    /**
     * Souhrn měsíční kontroly bere `supplier_id` z URL, ne z X-Supplier-Id hlavičky —
     * musí tedy stát na TÉŽE membership bráně jako přehled sám, jinak by šlo přečíst
     * stav účetnictví cizí firmy pouhou změnou čísla v adrese.
     */
    public function testMonthlyCheckAccessHonoursMembership(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA]);

        self::assertTrue($this->portfolio->userCanAccess($userId, false, $this->supplierA));
        self::assertFalse($this->portfolio->userCanAccess($userId, false, $this->supplierB),
            'Firma mimo membership nesmí být přístupná ani přes supplier_id v URL.');
        self::assertTrue($this->portfolio->userCanAccess($userId, true, $this->supplierB),
            'Globální admin projde i mimo vlastní membership.');
        self::assertFalse($this->portfolio->userCanAccess($userId, true, 0));
    }

    /**
     * Token vázaný na firmu A nesmí přes přehled firem ani měsíční kontrolu číst
     * další firmy svého vlastníka, ať je to člen více firem, nebo globální admin.
     */
    public function testBoundTokenSeesOnlyItsCompany(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA, $this->supplierB]);
        $now = new \DateTimeImmutable();

        $ids = static fn (array $res): array => array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);
        self::assertSame([$this->supplierA], $ids($this->portfolio->overview($userId, false, $now, $this->supplierA)));
        self::assertSame([$this->supplierA], $ids($this->portfolio->overview(0, true, $now, $this->supplierA)));
        self::assertTrue($this->portfolio->userCanAccess($userId, false, $this->supplierA, $this->supplierA));
        self::assertFalse($this->portfolio->userCanAccess($userId, false, $this->supplierB, $this->supplierA));
        self::assertFalse($this->portfolio->userCanAccess($userId, true, $this->supplierB, $this->supplierA));

        // Bez vazby (webová relace) vidí člen dál obě své firmy.
        $unbound = $ids($this->portfolio->overview($userId, false, $now));
        self::assertContains($this->supplierA, $unbound);
        self::assertContains($this->supplierB, $unbound);
    }

    public function testBoundTokenRequestIsNarrowedInAction(): void
    {
        $action = Bootstrap::buildContainer()->get(\MyInvoice\Action\Portfolio\PortfolioAction::class);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/portfolio/overview')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_USER, ['id' => 0, 'role' => 'admin'])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_API_TOKEN, ['scope' => 'read', 'supplier_id' => $this->supplierA]);

        $overview = json_decode((string) $action->overview($request, new \Slim\Psr7\Response())->getBody(), true);
        self::assertSame([$this->supplierA], array_column($overview['companies'], 'supplier_id'));

        $foreign = $action->monthlyCheck($request, new \Slim\Psr7\Response(), ['supplierId' => (string) $this->supplierB]);
        self::assertSame(404, $foreign->getStatusCode());
    }

    public function testUserWithoutMembershipSeesNoCompanies(): void
    {
        $userId = $this->mkUser('accountant');
        // Žádné membership řádky.

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertSame([], $ids, 'Non-superadmin bez membershipu nesmí vidět žádnou firmu.');
    }

    public function testGlobalAdminSeesAllCompaniesDespiteOwnMembership(): void
    {
        $adminId = $this->mkUser('admin');
        $this->assign($adminId, [$this->supplierA]);

        $res = $this->portfolio->overview($adminId, true, new \DateTimeImmutable());
        $ids = array_map(static fn (array $c) => $c['supplier_id'], $res['companies']);

        self::assertContains($this->supplierA, $ids);
        self::assertContains($this->supplierB, $ids, 'Globální admin vidí i firmu mimo vlastní membership.');
    }

    public function testCompanyRowHasExpectedShape(): void
    {
        $userId = $this->mkUser('accountant');
        $this->assign($userId, [$this->supplierA]);

        $res = $this->portfolio->overview($userId, false, new \DateTimeImmutable());
        self::assertCount(1, $res['companies']);
        $row = $res['companies'][0];

        self::assertSame($this->supplierA, $row['supplier_id']);
        self::assertArrayHasKey('company_name', $row);
        self::assertArrayHasKey('is_vat_payer', $row);
        self::assertArrayHasKey('accounting_mode', $row);
        self::assertArrayHasKey('next_deadline', $row);
        self::assertArrayHasKey('unbooked_documents', $row);
        self::assertArrayHasKey('unbooked_breakdown', $row);
        self::assertArrayHasKey('unmatched_bank_transactions', $row);
        self::assertArrayHasKey('purchase_drafts', $row);
        self::assertArrayHasKey('inbox_pending', $row);
        self::assertArrayHasKey('period_status', $row);
        self::assertArrayHasKey('last_bank_import_at', $row);
        self::assertSame($res['total'], count($res['companies']));
    }

    /**
     * Regrese: „K doúčtování" sčítá tři různé entity, ale proklik byl natvrdo
     * `/invoices?booked=0`. Na ostrých datech tvořilo číslo 7 sedm bankovních pohybů
     * a seznam vydaných faktur pod prokliknutím byl prázdný. Číslo proto musí přijít
     * s rozpadem, jehož každá položka nese vlastní (neprázdný) cíl a jehož součet
     * sedí na celek — bez toho se UI nemá čeho chytit.
     */
    public function testUnbookedBreakdownMaSoucetAVlastniProklikNaTyp(): void
    {
        $expectedLinks = [
            'invoices' => '/invoices?booked=0',
            'purchase_invoices' => '/purchase-invoices?booked=0',
            'bank' => '/bank?tab=posting',
        ];

        foreach ([$this->supplierA, $this->supplierB] as $supplier) {
            $this->unbookedFixture($supplier);
        }
        $now = new \DateTimeImmutable('2094-06-30');
        $res = $this->portfolio->overview(0, true, $now, $this->supplierA);
        $taxEvidence = $this->portfolio->overview(0, true, $now, $this->supplierB);
        self::assertCount(1, $res['companies']);
        self::assertCount(1, $taxEvidence['companies']);
        self::assertSame('double_entry', $res['companies'][0]['accounting_mode']);
        self::assertSame('tax_evidence', $taxEvidence['companies'][0]['accounting_mode']);
        $res['companies'] = array_merge($res['companies'], $taxEvidence['companies']);
        self::assertNotSame([], $res['companies'], 'Superadmin musí vidět aspoň jednu firmu.');
        self::assertSame([$this->supplierA, $this->supplierB], array_column($res['companies'], 'supplier_id'));

        foreach ($res['companies'] as $row) {
            if ($row['supplier_id'] === $this->supplierA) {
                self::assertSame(array_keys($expectedLinks), array_column($row['unbooked_breakdown'], 'key'));
                self::assertSame([1, 1, 1], array_column($row['unbooked_breakdown'], 'count'));
            }
            $sum = 0;
            $seen = [];
            foreach ($row['unbooked_breakdown'] as $part) {
                self::assertArrayHasKey($part['key'], $expectedLinks, 'Neznámý typ v rozpadu.');
                self::assertSame($expectedLinks[$part['key']], $part['link'],
                    'Každý typ vede na vlastní seznam, ne na natvrdo zadrátované faktury.');
                self::assertGreaterThan(0, $part['count'], 'Nulový typ se do rozpadu nedává.');
                self::assertNotContains($part['key'], $seen, 'Typ se v rozpadu nesmí opakovat.');
                $seen[] = $part['key'];
                $sum += $part['count'];
            }
            self::assertSame($row['unbooked_documents'], $sum,
                'Součet rozpadu musí sedět na číslo, které se v přehledu zobrazuje.');
            if ($row['accounting_mode'] !== 'double_entry') {
                self::assertSame([], $row['unbooked_breakdown'],
                    'V daňové evidenci se do deníku neúčtuje — rozpad je prázdný.');
            }
        }
    }

    private function unbookedFixture(int $supplier): void
    {
        $pdo = $this->db->pdo();
        $actor = (int) $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        self::assertGreaterThan(0, $actor);
        self::assertGreaterThan(0, $country);
        $pdo->prepare("INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, main_email, is_customer, is_vendor) VALUES (?, 'Syntetická protistrana portfolia', 'Testovací 1', 'Praha', '11000', ?, ?, 'portfolio-party@example.test', 1, 1)")
            ->execute([$supplier, $country, $this->currencyIds[$supplier]]);
        $client = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, total_without_vat, total_vat, total_with_vat, status, created_by) VALUES (?, 'SYN-PORTFOLIO-1', 'invoice', ?, '2094-06-15', '2094-06-15', '2094-06-30', ?, 100, 21, 121, 'issued', ?)")
            ->execute([$supplier, $client, $this->currencyIds[$supplier], $actor]);
        $pdo->prepare("INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date, due_date, received_at, currency_id, vendor_snapshot, total_without_vat, total_vat, total_with_vat, status, created_by) VALUES (?, ?, 'SYN-PORTFOLIO-1', 'invoice', '2094-06-15', '2094-06-15', '2094-06-30', '2094-06-15', ?, '{}', 100, 21, 121, 'received', ?)")
            ->execute([$supplier, $client, $this->currencyIds[$supplier], $actor]);
        $pdo->prepare("INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, currency, statement_date) VALUES (?, 'synthetic-portfolio.gpc', ?, '1000000005', '0100', 'CZK', '2094-06-15')")
            ->execute([$supplier, hash('sha256', 'synthetic-portfolio-' . $supplier)]);
        $statement = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO bank_transactions (statement_id, posted_at, amount, currency, source, match_status) VALUES (?, '2094-06-15', 121, 'CZK', 'statement', 'unmatched')")
            ->execute([$statement]);
    }

    private function roleId(string $legacy): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM roles WHERE system_key = ?');
        $stmt->execute([$legacy === 'admin' ? 'superadmin' : $legacy]);
        return (int) $stmt->fetchColumn();
    }
}
