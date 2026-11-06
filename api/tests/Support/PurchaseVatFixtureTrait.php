<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Report\VatLedgerService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Sdílené přípravy testů DPH zařazení přijaté faktury: syntetický dodavatel, doklad
 * přes skutečnou akci založení a otisk evidence DPH dokladu. Vše v transakci
 * s rollbackem (supplier s nejnižším id = podvojné účetnictví, plátce DPH).
 */
trait PurchaseVatFixtureTrait
{
    private const VAT_DATE = '2096-04-10';

    private ContainerInterface $container;
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private int $currencyId = 0;
    private int $rate21 = 0;
    private int $rate12 = 0;
    private bool $inTx = false;

    private function setUpPurchaseVatFixture(): void
    {
        if (!is_file(dirname(__DIR__, 3) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $this->container = Bootstrap::buildContainer();
            $this->db = $this->container->get(Connection::class);
            $this->repo = $this->container->get(PurchaseInvoiceRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->rate21 = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21'")->fetchColumn() ?: 0);
        $this->rate12 = (int) ($pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-12'")->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->rate21 === 0 || $this->rate12 === 0
            || $czId === 0 || $this->currencyId === 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id,
                                  main_email, language, currency_default_id, is_vendor, is_vat_payer)
             VALUES (?, "TEST DPH zařazení dodavatel (PHPUnit)", "Testovací 1", "Praha", "11000", ?,
                     "dph-zarazeni@example.test", "cs", ?, 1, 1)'
        )->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->vendorId = (int) $pdo->lastInsertId();

        // Rozpis DPH odkazuje na účet 518. Čistá DB z migrací (CI) ho u první firmy
        // nemusí mít; v transakci testu se doplní a rollback ho zase odebere.
        $account = $pdo->prepare('SELECT id, is_active FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? LIMIT 1');
        $account->execute([$this->supplierId, '518']);
        $row = $account->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $pdo->prepare(
                "INSERT INTO chart_of_accounts (supplier_id, account_code, name, account_type, normal_side, is_synthetic, is_active)
                 VALUES (?, '518', 'Ostatní služby', 'expense', 'debit', 1, 1)"
            )->execute([$this->supplierId]);
        } elseif ((int) $row['is_active'] !== 1) {
            $pdo->prepare('UPDATE chart_of_accounts SET is_active = 1 WHERE id = ?')->execute([(int) $row['id']]);
        }
    }

    private function tearDownPurchaseVatFixture(): void
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
     * Založí doklad skutečnou akcí (koncept).
     *
     * @param array<string,mixed> $overrides
     * @param list<array<string,mixed>>|null $items
     */
    private function createPurchase(array $overrides = [], ?array $items = null): int
    {
        $items ??= [$this->item('Služba (PHPUnit)', 1000.0)];
        $res = $this->call($this->container->get(CreatePurchaseInvoiceAction::class), 'POST', $overrides + [
            'vendor_id' => $this->vendorId,
            'vendor_invoice_number' => 'DPHPI-' . bin2hex(random_bytes(4)),
            'document_kind' => 'invoice',
            'issue_date' => self::VAT_DATE,
            'tax_date' => self::VAT_DATE,
            'due_date' => '2096-05-10',
            'received_at' => self::VAT_DATE,
            'currency_id' => $this->currencyId,
            'reverse_charge' => false,
            'prices_include_vat' => false,
            'items' => $items,
        ]);
        self::assertSame(201, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        return (int) $res['body']['id'];
    }

    /** @return array<string,mixed> */
    private function item(string $description, float $price, ?int $rateId = null, ?string $code = null): array
    {
        return [
            'description' => $description, 'quantity' => 1, 'unit' => 'ks',
            'unit_price_without_vat' => $price, 'vat_rate_id' => $rateId ?? $this->rate21,
            'vat_classification_code' => $code,
        ];
    }

    /** @return array<string,mixed> */
    private function allocation(string $description, float $base, float $vat, string $deduction, float $percent = 100.0): array
    {
        return [
            'description' => $description, 'usage_type' => 'business', 'vat_rate' => 21,
            'base_amount' => $base, 'vat_amount' => $vat, 'total_amount' => $base + $vat,
            'vat_deduction' => $deduction, 'vat_deduction_percent' => $percent,
            'tax_treatment' => 'deductible', 'account_code' => '518',
        ];
    }

    /**
     * Tělo, jaké pošle editor: celý doklad tak, jak je uložený, s položkami i rozpisem.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed>
     */
    private function editorBody(int $id, array $changes = []): array
    {
        $inv = $this->repo->find($id, $this->supplierId);
        self::assertNotNull($inv);
        $items = array_map(static fn (array $it): array => [
            'description' => $it['description'], 'quantity' => $it['quantity'], 'unit' => $it['unit'],
            'unit_price_without_vat' => $it['unit_price_without_vat'], 'vat_rate_id' => $it['vat_rate_id'],
            'vat_classification_code' => $it['vat_classification_code'] ?? null,
            'expense_kind' => $it['expense_kind'] ?? null, 'order_index' => $it['order_index'] ?? 0,
        ], $inv['items']);
        $allocations = array_map(static fn (array $a): array => array_intersect_key($a, array_flip([
            'description', 'usage_type', 'vat_rate', 'base_amount', 'vat_amount', 'total_amount',
            'vat_deduction', 'vat_deduction_percent', 'tax_treatment', 'account_code', 'vat_classification_code', 'order_index',
        ])), $inv['vat_allocations']);

        return $changes + [
            'vendor_id' => $inv['vendor_id'],
            'vendor_invoice_number' => $inv['vendor_invoice_number'],
            'issue_date' => $inv['issue_date'],
            'tax_date' => $inv['tax_date'],
            'due_date' => $inv['due_date'],
            'received_at' => $inv['received_at'],
            'currency_id' => $inv['currency_id'],
            'reverse_charge' => $inv['reverse_charge'],
            'prices_include_vat' => $inv['prices_include_vat'],
            'vat_deduction' => $inv['vat_deduction'],
            'vat_deduction_percent' => $inv['vat_deduction_percent'],
            'vat_classification_code' => $inv['vat_classification_code'],
            'items' => $items,
            'vat_allocations' => $allocations,
        ];
    }

    /** Evidence DPH dokladu tak, jak ji vidí přiznání i KH (koncepty včetně). */
    private function ledgerRows(int $id): string
    {
        $rows = $this->container->get(VatLedgerService::class)
            ->documentRows($this->supplierId, 'purchase', $id, '2096-04-01', '2096-04-30', true);

        return (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return list<array<string,mixed>> */
    private function ledgerList(int $id): array
    {
        return $this->container->get(VatLedgerService::class)
            ->documentRows($this->supplierId, 'purchase', $id, '2096-04-01', '2096-04-30', true);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function call(
        callable $action,
        string $method,
        array $body,
        array $args = [],
        string $role = 'admin',
        array $query = [],
        ?EffectiveRole $effectiveRole = null,
    ): array {
        $request = $this->request($method, $body, $role, $query, $effectiveRole);

        return self::decodeResponse($action($request, new Psr7Response(), $args));
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $query
     */
    private function request(
        string $method,
        array $body,
        string $role = 'admin',
        array $query = [],
        ?EffectiveRole $effectiveRole = null,
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, '/api/purchase-invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams($query)
            ->withParsedBody($body);
        if ($effectiveRole !== null) {
            $request = $request->withAttribute('auth.effective_role', $effectiveRole);
        }

        return $request;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function decodeResponse(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
