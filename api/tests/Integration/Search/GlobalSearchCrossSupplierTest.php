<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Search;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\CrossSupplierAuthorization;
use MyInvoice\Service\Search\GlobalSearch;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Globální hledání (Alt+Q, Ctrl+K) hledá klienty a doklady v ostatních firmách
 * uživatele, když je aktivní firma nenajde. Hlídá tím hranici tenantů: výsledek
 * přijde jen z firmy, kde má uživatel členství a právo ke čtení dané agendy.
 * Firma, která neprojde, nesmí ve výsledku zanechat ani stopu.
 */
#[Group('integration')]
final class GlobalSearchCrossSupplierTest extends TestCase
{
    use IsolatedSupplierTrait;

    private ContainerInterface $container;
    private PDO $pdo;
    private int $userId;
    private int $currencyId;
    private int $countryId;
    private string $token;
    /** @var array<string,int> */
    private array $suppliers = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->pdo = $this->container->get(Connection::class)->pdo();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        $this->currencyId = (int) $this->pdo->query("SELECT id FROM currencies WHERE code = 'CZK' LIMIT 1")->fetchColumn();
        $this->countryId = (int) $this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        if ($source <= 0 || $this->currencyId <= 0 || $this->countryId <= 0) {
            $this->markTestSkipped('Chybí zdrojová firma, CZK nebo CZ.');
        }
        $this->pdo->beginTransaction();

        $documentsRole = $this->role(['clients', 'invoices', 'purchase_invoices']);
        $payrollRole = $this->role(['payroll']);
        $clientsOnlyRole = $this->role(['clients']);
        $this->pdo->prepare("INSERT INTO users (email, password_hash, name, role_id, locale, is_active)
                             VALUES (?, 'disabled-test-password', 'Syntetický hledající', ?, 'cs', 1)")
            ->execute(['global-search-' . bin2hex(random_bytes(6)) . '@example.invalid', $documentsRole]);
        $this->userId = (int) $this->pdo->lastInsertId();

        $this->token = 'Qx' . bin2hex(random_bytes(4));
        foreach (['current', 'allowed', 'no_permission', 'not_member', 'clients_only'] as $key) {
            $this->suppliers[$key] = $this->createIsolatedSupplier($this->pdo, $source);
        }
        $assign = $this->pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id, role_id) VALUES (?, ?, ?)');
        $assign->execute([$this->userId, $this->suppliers['current'], null]);
        $assign->execute([$this->userId, $this->suppliers['allowed'], null]);
        $assign->execute([$this->userId, $this->suppliers['no_permission'], $payrollRole]);
        $assign->execute([$this->userId, $this->suppliers['clients_only'], $clientsOnlyRole]);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testFallsBackOnlyToCompaniesWithReadAccess(): void
    {
        foreach (['allowed', 'no_permission', 'not_member'] as $key) {
            $this->documents($key, $this->token);
        }

        $result = $this->search('session', 'current', $this->token);

        foreach (['clients', 'invoices', 'purchase_invoices'] as $group) {
            self::assertNotSame([], $result[$group], "Skupina $group se má najít v povolené firmě.");
            self::assertSame(
                [$this->suppliers['allowed']],
                array_values(array_unique(array_column($result[$group], 'supplier_id'))),
                "Skupina $group smí přijít jen z firmy s právem ke čtení.",
            );
            self::assertNotSame('', $result[$group][0]['supplier_name'], 'Výsledek z jiné firmy musí říct, ze které je.');
        }
    }

    public function testHitInCurrentCompanySuppressesOtherCompanies(): void
    {
        $this->documents('current', $this->token);
        $this->documents('allowed', $this->token);

        $result = $this->search('session', 'current', $this->token);

        foreach (['clients', 'invoices', 'purchase_invoices'] as $group) {
            self::assertCount(1, $result[$group], $group);
            self::assertArrayNotHasKey('supplier_name', $result[$group][0], "$group z aktivní firmy nemá přepínat firmu.");
        }
    }

    public function testApiTokenAndShortQueryStayInCurrentCompany(): void
    {
        $this->documents('allowed', $this->token);

        $bearer = $this->search('bearer', 'current', $this->token);
        $short = $this->search('session', 'current', substr($this->token, 0, 2));

        foreach (['clients', 'invoices', 'purchase_invoices'] as $group) {
            self::assertSame([], $bearer[$group], "API token nesmí hledat v jiné firmě ($group).");
            self::assertSame([], array_filter($short[$group], fn (array $hit): bool => isset($hit['supplier_id'])
                && $hit['supplier_id'] !== $this->suppliers['current']), "Dva znaky do jiné firmy nehledají ($group).");
        }
    }

    public function testCurrentCompanyNeedsAgendaPermission(): void
    {
        $this->documents('clients_only', $this->token);

        $result = $this->search('session', 'clients_only', $this->token);

        self::assertCount(1, $result['clients']);
        self::assertSame([], $result['invoices'], 'Bez práva invoices se čísla faktur nevydají.');
        self::assertSame([], $result['purchase_invoices'], 'Bez práva purchase_invoices se přijaté doklady nevydají.');
    }

    /** @return array<string,mixed> */
    private function search(string $method, string $current, string $query): array
    {
        $authorization = $this->container->get(CrossSupplierAuthorization::class);
        $user = $authorization->activeUser($this->userId);
        self::assertNotNull($user);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/search')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
        $scoped = $authorization->scopedRequest($request, $this->suppliers[$current]);
        self::assertNotNull($scoped);

        return $this->container->get(GlobalSearch::class)->search($scoped, $this->suppliers[$current], $query);
    }

    /** Klient, vydaná a přijatá faktura, všechny dohledatelné podle $token. */
    private function documents(string $key, string $token): void
    {
        $supplierId = $this->suppliers[$key];
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, 'Testovací 1', 'Praha', '11000', ?, 'hledani@example.test', 'cs', ?, 1, 1)"
        )->execute([$supplierId, 'Syntetický ' . $token . ' ' . $key, $this->countryId, $this->currencyId]);
        $clientId = (int) $this->pdo->lastInsertId();
        $today = date('Y-m-d');
        $this->pdo->prepare(
            "INSERT INTO invoices (supplier_id, client_id, invoice_type, issue_date, due_date, currency_id, created_by, status, varsymbol)
             VALUES (?, ?, 'invoice', ?, ?, ?, ?, 'draft', ?)"
        )->execute([$supplierId, $clientId, $today, $today, $this->currencyId, $this->userId, $token . '1']);
        $this->pdo->prepare(
            'INSERT INTO purchase_invoices (supplier_id, vendor_id, vendor_invoice_number, varsymbol, vendor_snapshot, document_kind, vat_deduction,
                issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset, total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, ?, ?, ?, "invoice", "full", ?, ?, ?, ?, ?, 0, 0, 100, 0, 100, "received", ?)'
        )->execute([$supplierId, $clientId, $token . 'P', 'PV' . bin2hex(random_bytes(4)), json_encode(['company_name' => 'Syntetický dodavatel']),
            $today, $today, $today, $today, $this->currencyId, $this->userId]);
    }

    /** @param list<string> $keys */
    private function role(array $keys): int
    {
        $this->pdo->prepare("INSERT INTO roles (name, role_type, is_active) VALUES ('Syntetická role hledání', 'staff', 1)")->execute();
        $id = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_key, access_level) VALUES (?, ?, ?)');
        foreach ($keys as $key) {
            $insert->execute([$id, $key, AccessLevel::READ->value]);
        }

        return $id;
    }
}
