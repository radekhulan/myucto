<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payment;

use MyInvoice\Action\Invoice\RefundPaymentOrderAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\ClientBankAccountRepository;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Service\Payment\PaymentOrderService;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vratky odběratelům v platebních příkazech: doklad k vyplacení (faktura nebo
 * dobropis s amount_to_pay < 0) jde do příkazu jen se zapnutým
 * `supplier.allow_refund_invoices`, příjemcem je klient dokladu a VS je číslo dokladu.
 */
#[Group('integration')]
final class RefundPaymentOrderTest extends TestCase
{
    private const CLIENT_MARKER = '__refund_payment_order_client__';
    private const VS_INVOICE = '2099880001';
    private const VS_CREDIT = '2099880002';
    private const VS_CASH = '2099880003';

    private Connection $db;
    private PaymentOrderService $service;
    private PaymentOrderRepository $orders;
    private RefundPaymentOrderAction $action;
    private ClientBankAccountRepository $clientAccounts;
    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $clientId = 0;
    private int $userId = 0;
    private int $originalFlag = 0;
    /** @var list<int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->service = $container->get(PaymentOrderService::class);
            $this->orders = $container->get(PaymentOrderRepository::class);
            $this->action = $container->get(RefundPaymentOrderAction::class);
            $this->clientAccounts = $container->get(ClientBankAccountRepository::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $account = $pdo->query(
            "SELECT id, supplier_id FROM currencies
              WHERE code = 'CZK' AND is_active = 1 AND account_number IS NOT NULL AND account_number <> ''
                AND bank_code IS NOT NULL AND bank_code <> ''
              ORDER BY id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $this->markTestSkipped('Chybí CZK měna s bankovním účtem.');
        }
        $this->supplierId = (int) $account['supplier_id'];
        $this->currencyId = (int) $account['id'];
        $countryId = (int) ($pdo->query('SELECT id FROM countries ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($countryId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí country/user pro integrační test.');
        }
        $this->originalFlag = (int) $pdo->query('SELECT allow_refund_invoices FROM supplier WHERE id = ' . $this->supplierId)->fetchColumn();

        $this->cleanup();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Praha", "11000", ?, "", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::CLIENT_MARKER, $countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
            $this->db->pdo()->prepare('UPDATE supplier SET allow_refund_invoices = ? WHERE id = ?')
                ->execute([$this->originalFlag, $this->supplierId]);
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        $vs = [self::VS_INVOICE, self::VS_CREDIT, self::VS_CASH];
        $place = implode(',', array_fill(0, count($vs), '?'));
        $orderIds = $pdo->prepare(
            "SELECT DISTINCT poi.payment_order_id FROM payment_order_items poi
               JOIN invoices i ON i.id = poi.invoice_id
              WHERE i.supplier_id = ? AND i.varsymbol IN ($place)"
        );
        $orderIds->execute([$this->supplierId, ...$vs]);
        $ids = array_merge($this->orderIds, array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN) ?: []));
        foreach (array_unique($ids) as $orderId) {
            $pdo->prepare('DELETE FROM payment_order_items WHERE payment_order_id = ?')->execute([$orderId]);
            $pdo->prepare('DELETE FROM payment_orders WHERE id = ?')->execute([$orderId]);
            $pdo->prepare("DELETE FROM activity_log WHERE entity_type = 'payment_order' AND entity_id = ?")->execute([$orderId]);
        }
        $this->orderIds = [];
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND varsymbol IN ($place)")
            ->execute([$this->supplierId, ...$vs]);
        $pdo->prepare(
            'DELETE cba FROM client_bank_accounts cba JOIN clients c ON c.id = cba.client_id
              WHERE c.supplier_id = ? AND c.company_name = ?'
        )->execute([$this->supplierId, self::CLIENT_MARKER]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ? AND company_name = ?')
            ->execute([$this->supplierId, self::CLIENT_MARKER]);
    }

    private function setFlag(bool $on): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET allow_refund_invoices = ? WHERE id = ?')
            ->execute([$on ? 1 : 0, $this->supplierId]);
    }

    private function seedDocument(string $type, string $varsymbol, float $total, string $method = 'bank_transfer', string $status = 'issued'): int
    {
        $pdo = $this->db->pdo();
        $date = '2099-07-15';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, exchange_rate_date, status, payment_method,
                 total_without_vat, total_with_vat, paid_total, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, 0, ?)"
        )->execute([
            $type, $varsymbol, $this->clientId, $this->supplierId, $date, $date, $date,
            $this->currencyId, $date, $status, $method, $total, $total, $this->userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function seedAccounts(): void
    {
        $this->clientAccounts->captureFromBank($this->clientId, $this->supplierId, '1000000013', '0300');
        $this->clientAccounts->addManual($this->clientId, $this->supplierId, ['account_number' => '1000000005', 'bank_code' => '0100']);
    }

    /** @return list<int> */
    private function refundCandidateIds(array $result): array
    {
        return array_map(static fn (array $c): int => (int) $c['id'], $result['refund_candidates'] ?? []);
    }

    public function testWithoutSwitchCandidatesAndCreateStayUnchanged(): void
    {
        $this->setFlag(false);
        $invoiceId = $this->seedDocument('invoice', self::VS_INVOICE, -608.0);
        $this->seedAccounts();

        $result = $this->service->candidates($this->supplierId, 'CZK');
        self::assertSame(['payer_accounts', 'candidates', 'total', 'tax_candidates', 'other_candidates'], array_keys($result));

        try {
            $this->service->create($this->supplierId, [
                'refund_invoice_ids' => [$invoiceId],
                'payer_currency_id'  => $this->currencyId,
            ], $this->userId);
            self::fail('Bez přepínače se vratka do příkazu dostat nesmí.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Žádná z vybraných faktur není pro příkaz použitelná.', $e->getMessage());
        }
    }

    public function testCandidatesListOpenRefundsWithManualAccountFirst(): void
    {
        $this->setFlag(true);
        $invoiceId = $this->seedDocument('invoice', self::VS_INVOICE, -608.0);
        $creditId = $this->seedDocument('credit_note', self::VS_CREDIT, -1500.0);
        $cashId = $this->seedDocument('invoice', self::VS_CASH, -200.0, 'cash');
        $this->seedAccounts();

        $result = $this->service->candidates($this->supplierId, 'CZK');
        $ids = $this->refundCandidateIds($result);
        self::assertContains($invoiceId, $ids);
        self::assertContains($creditId, $ids);
        self::assertNotContains($cashId, $ids, 'Hotovostní vratku řeší pokladna, ne příkaz.');
        self::assertSame([], $this->service->candidates($this->supplierId, 'EUR')['refund_candidates']);

        $row = array_values(array_filter($result['refund_candidates'], static fn (array $c): bool => $c['id'] === $invoiceId))[0];
        self::assertSame(608.0, $row['amount_to_pay']);
        self::assertSame('1000000005', $row['account_number']);
        self::assertSame('0100', $row['bank_code']);
        self::assertSame('manual', $row['payment_account_source']);
        self::assertSame(self::VS_INVOICE, $row['variable_symbol']);
        self::assertTrue($row['abo_eligible']);
    }

    public function testRefundOrderWritesAboLineAndLeavesDocumentOpen(): void
    {
        $this->setFlag(true);
        $invoiceId = $this->seedDocument('invoice', self::VS_INVOICE, -608.0);
        $this->seedAccounts();

        $created = $this->service->create($this->supplierId, [
            'refund_invoice_ids' => [$invoiceId],
            'payer_currency_id'  => $this->currencyId,
            'payment_date'       => date('Y-m-d'),
            'mark_paid'          => true,
        ], $this->userId);
        $orderId = $created['order_id'];
        $this->orderIds[] = $orderId;

        self::assertSame([], $created['skipped']);
        self::assertSame($invoiceId, $created['view']['items'][0]['invoice_id']);
        self::assertNull($created['view']['items'][0]['purchase_invoice_id']);
        self::assertSame(608.0, $created['view']['total_amount']);

        $doc = $this->db->pdo()->query("SELECT status, payment_ordered_at FROM invoices WHERE id = {$invoiceId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('issued', $doc['status'], 'Vratka je vyplacená až spárováním nebo ručně.');
        self::assertNotNull($doc['payment_ordered_at']);

        $abo = (string) $this->service->download($orderId, $this->supplierId, 'abo')['bytes'];
        self::assertStringContainsString('000000-1000000005 000000060800 ' . self::VS_INVOICE . ' 01000000 0000000000 AV:', $abo);

        self::assertTrue($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));
        $this->db->pdo()->exec("UPDATE invoices SET status = 'paid' WHERE id = {$invoiceId}");
        self::assertFalse($this->orders->allItemsStillPayable($orderId, $this->supplierId, 'CZK'));
        $this->db->pdo()->exec("UPDATE invoices SET status = 'issued' WHERE id = {$invoiceId}");

        self::assertSame('deleted', $this->service->delete($orderId, $this->supplierId));
        self::assertNull($this->db->pdo()->query("SELECT payment_ordered_at FROM invoices WHERE id = {$invoiceId}")->fetchColumn());
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function call(string $method, int $invoiceId, array $body = [], string $role = 'admin'): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method === 'prefill' ? 'GET' : 'POST', '/api/invoices/' . $invoiceId . '/refund-order')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withParsedBody($body);
        $args = ['id' => (string) $invoiceId];
        $response = $method === 'prefill'
            ? $this->action->prefill($request, new Psr7Response(), $args)
            : $this->action->create($request, new Psr7Response(), $args);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    public function testRefundOrderEndpoint(): void
    {
        $invoiceId = $this->seedDocument('invoice', self::VS_INVOICE, -608.0);
        $this->setFlag(false);
        self::assertSame(409, $this->call('prefill', $invoiceId)['status']);

        $this->setFlag(true);
        self::assertSame(403, $this->call('create', $invoiceId, [], 'client')['status']);

        $prefill = $this->call('prefill', $invoiceId);
        self::assertSame(200, $prefill['status'], json_encode($prefill['body']));
        $data = $prefill['body']['data'] ?? $prefill['body'];
        self::assertSame(608.0, (float) $data['amount']);
        self::assertSame(self::VS_INVOICE, $data['variable_symbol']);
        self::assertSame([], $data['accounts']);

        $bad = $this->call('create', $invoiceId, [
            'payer_currency_id' => $this->currencyId, 'account_number' => '1000000006', 'bank_code' => '0100',
        ]);
        self::assertSame(422, $bad['status'], 'Účet bez kontroly modulo 11 neprojde.');

        $created = $this->call('create', $invoiceId, [
            'payer_currency_id' => $this->currencyId,
            'payment_date'      => date('Y-m-d'),
            'account_number'    => '1000000005',
            'bank_code'         => '0100',
            'save_to_client'    => true,
        ]);
        self::assertSame(201, $created['status'], json_encode($created['body']));
        $result = $created['body']['data'] ?? $created['body'];
        $this->orderIds[] = (int) $result['order_id'];
        self::assertSame($invoiceId, $result['view']['items'][0]['invoice_id']);
        self::assertSame('1000000005', $result['view']['items'][0]['account_number']);
        self::assertNotNull($result['saved_account']);
        self::assertSame('1000000005', $this->service->suggestedRefundAccounts($this->clientId, $this->supplierId)[0]['account_number']);

        $this->db->pdo()->exec("UPDATE invoices SET status = 'paid' WHERE id = {$invoiceId}");
        self::assertSame(409, $this->call('prefill', $invoiceId)['status']);
    }
}
