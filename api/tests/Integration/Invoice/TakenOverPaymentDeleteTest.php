<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use DI\Container;
use MyInvoice\Action\Invoice\DeletePaymentAction;
use MyInvoice\Action\Invoice\UnmarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Invoice\TakenOverPaymentException;
use MyInvoice\Service\Migration\MoneyS3\IssuedPaymentRecorder;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPayments;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Úhrada převzatá z POHODY bez protějšku v MyÚčtu (úhrada minulého roku, dorovnání úhrady)
 * se nesmaže žádnou cestou: DELETE platby vrátí 409, „Zrušit úhradu" ji ponechá a doklad,
 * který sama pokrývá, na neuhrazený nevrátí. Platby převzaté z Money S3 a ruční platby
 * se mažou dál.
 */
#[Group('integration')]
final class TakenOverPaymentDeleteTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->currencyId, $vat, $this->userId));

        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tax_evidence')"
        )->execute(['Synthetic převzaté úhrady s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('tko-') . '@example.com', $this->currencyId, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        if ($this->supplierId !== 0) {
            self::deleteRows($this->db->pdo(), 'supplier', $this->supplierId, 0);
        }
        $this->db->close();
    }

    public function testDeleteOfPriorYearPaymentIsRefusedAndKeepsPaidTotal(): void
    {
        $invoiceId = $this->invoice(1210);
        $paymentId = $this->payment($invoiceId, 1210, 'settlement', PohodaPriorYearPayments::NOTE, '2025-12-31');
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);

        $response = $this->deletePayment($invoiceId, $paymentId);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('taken_over_payment', $this->json($response)['error']['code']);
        self::assertStringContainsString('nemaže', $this->json($response)['error']['message']);

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE id = ?', [$paymentId]));
        $invoice = $this->invoiceRow($invoiceId);
        self::assertSame(1210.0, (float) $invoice['paid_total']);
        self::assertSame('paid', $invoice['status']);
    }

    public function testServiceRefusesRemainderPaymentForEveryCaller(): void
    {
        $invoiceId = $this->invoice(1210);
        $paymentId = $this->payment($invoiceId, 1210, 'settlement', DocumentLinker::REMAINDER_NOTE, '2026-03-01');

        $this->expectException(TakenOverPaymentException::class);
        $this->container->get(InvoicePaymentService::class)->deletePayment($paymentId, skipBankGuard: true);
    }

    public function testManualAndMoneyPaymentsStayDeletable(): void
    {
        $invoiceId = $this->invoice(1210);
        $prior = $this->payment($invoiceId, 1000, 'settlement', PohodaPriorYearPayments::NOTE, '2025-12-31');
        $manual = $this->payment($invoiceId, 110, 'manual', null, '2026-02-01');
        $money = $this->payment($invoiceId, 100, 'settlement', IssuedPaymentRecorder::NOTE_PREFIX . 'zápočet interním dokladem ID-1', '2026-02-02');

        self::assertSame(200, $this->deletePayment($invoiceId, $manual)->getStatusCode());
        $response = $this->deletePayment($invoiceId, $money);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $listed = $this->json($response)['payments'];
        self::assertSame([$prior], array_column($listed, 'id'));
        self::assertTrue($listed[0]['taken_over']);
        self::assertTrue($listed[0]['taken_over_locked']);
        self::assertTrue($listed[0]['prior_year']);
        self::assertSame(1000.0, (float) $this->invoiceRow($invoiceId)['paid_total']);
    }

    public function testPaymentFlagsDistinguishTakenOverKinds(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->payment($invoiceId, 100, 'settlement', DocumentLinker::REMAINDER_NOTE, '2026-01-10');
        $this->payment($invoiceId, 100, 'settlement', IssuedPaymentRecorder::NOTE_PREFIX . 'zápočet interním dokladem ID-2', '2026-01-11');
        $this->payment($invoiceId, 100, 'settlement', 'Zápočet proti účtu 395', '2026-01-12');

        $flags = array_map(
            static fn (array $p): array => [$p['taken_over'], $p['taken_over_locked'], $p['prior_year']],
            $this->container->get(InvoicePaymentService::class)->listFor($invoiceId),
        );
        self::assertSame([[true, true, false], [true, false, false], [false, false, false]], $flags);
    }

    /**
     * Úhrada, kterou Money S3 vede jen datem úhrady bez dokladu úhrady v převedených datech,
     * nemá v MyÚčtu protějšek: nesmaže se, „Zrušit úhradu" ji ponechá a doklad nevrátí.
     */
    public function testMoneyS3PaymentWithoutDocumentIsProtected(): void
    {
        $invoiceId = $this->invoice(1210);
        $paymentId = $this->payment($invoiceId, 1210, 'settlement', TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . ' (ID25001)', '2025-11-30');

        $response = $this->deletePayment($invoiceId, $paymentId);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('taken_over_payment', $this->json($response)['error']['code']);
        self::assertSame(409, $this->unmarkPaid($invoiceId)->getStatusCode());

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE id = ?', [$paymentId]));
        $invoice = $this->invoiceRow($invoiceId);
        self::assertSame(1210.0, (float) $invoice['paid_total']);
        self::assertSame('paid', $invoice['status']);
    }

    public function testTaxDeclaredByOriginFlag(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->payment($invoiceId, 100, 'settlement', PohodaPriorYearPayments::NOTE, '2025-10-31');
        $this->payment($invoiceId, 100, 'bank', IssuedPaymentRecorder::NOTE_PREFIX . 'úhrada BP25001', '2025-11-11');
        $this->payment($invoiceId, 100, 'settlement', TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE, '2025-11-12');
        $this->payment($invoiceId, 100, 'bank', 'Převzato z POHODY, úhrada 25BV0001', '2025-11-13');
        $this->payment($invoiceId, 100, 'manual', null, '2025-11-14');

        $flags = array_map(
            static fn (array $p): array => [$p['taken_over_locked'], $p['tax_declared_by_origin']],
            $this->container->get(InvoicePaymentService::class)->listFor($invoiceId),
        );
        self::assertSame([[true, true], [false, true], [true, true], [false, false], [false, false]], $flags);
    }

    public function testUnmarkPaidRefusesInvoiceCoveredByPriorYearPayment(): void
    {
        $invoiceId = $this->invoice(1210);
        $paymentId = $this->payment($invoiceId, 1210, 'settlement', PohodaPriorYearPayments::NOTE, '2025-12-31');

        $response = $this->unmarkPaid($invoiceId);
        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('taken_over_payment', $this->json($response)['error']['code']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE id = ?', [$paymentId]));
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);
    }

    public function testUnmarkPaidKeepsPriorYearPaymentAndDeletesTheRest(): void
    {
        $invoiceId = $this->invoice(1210);
        $prior = $this->payment($invoiceId, 1000, 'settlement', PohodaPriorYearPayments::NOTE, '2025-12-31');
        $this->payment($invoiceId, 210, 'manual', null, '2026-02-01');
        self::assertSame('paid', $this->invoiceRow($invoiceId)['status']);

        $response = $this->unmarkPaid($invoiceId);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        self::assertSame([$prior], array_map('intval', $this->column('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId])));
        $invoice = $this->invoiceRow($invoiceId);
        self::assertSame(1000.0, (float) $invoice['paid_total']);
        self::assertSame('issued', $invoice['status']);
    }

    private function deletePayment(int $invoiceId, int $paymentId): ResponseInterface
    {
        return $this->container->get(DeletePaymentAction::class)(
            $this->request('DELETE'), new Psr7Response(), ['id' => (string) $invoiceId, 'paymentId' => (string) $paymentId]);
    }

    private function unmarkPaid(int $invoiceId): ResponseInterface
    {
        return $this->container->get(UnmarkPaidAction::class)($this->request('POST'), new Psr7Response(), ['id' => (string) $invoiceId]);
    }

    private function payment(int $invoiceId, float $amount, string $source, ?string $note, string $paidOn): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
             VALUES (?, ?, ?, ?, "CZK", ?, ?, ?)'
        )->execute([$this->supplierId, $invoiceId, $paidOn, number_format($amount, 2, '.', ''), $note, $source, $this->userId]);
        $id = (int) $pdo->lastInsertId();
        $this->container->get(InvoicePaymentService::class)->recompute($invoiceId, false);
        return $id;
    }

    private function invoice(float $total): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2025-11-01", "2025-11-01", "2025-11-15", ?, 0, ?, 0, ?, 0, ?, "issued", "1", ?)'
        )->execute([
            $this->supplierId, (string) random_int(1000000, 9999999), $this->clientId, $this->currencyId,
            $total, $total, json_encode(['account_number' => '1000000005', 'bank_code' => '0100']), $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                        total_without_vat, total_vat, total_with_vat)
             SELECT ?, "Synthetic item", 1, ?, id, 0, ?, 0, ? FROM vat_rates ORDER BY id LIMIT 1'
        )->execute([$invoiceId, $total, $total, $total]);
        return $invoiceId;
    }

    private function request(string $method): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT status, paid_total FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param list<mixed> $params @return list<mixed> */
    private function column(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Smaže firmu; tabulky blokující cizím klíčem uklidí podle chyby 1451. */
    private static function deleteRows(PDO $pdo, string $table, int $supplierId, int $depth): void
    {
        for ($attempt = 0; $attempt < 64; $attempt++) {
            try {
                $pdo->prepare('DELETE FROM `' . $table . '` WHERE ' . ($table === 'supplier' ? 'id' : 'supplier_id') . ' = ?')->execute([$supplierId]);
                return;
            } catch (\PDOException $e) {
                if ($depth > 16 || (int) ($e->errorInfo[1] ?? 0) !== 1451
                    || preg_match('/`[^`]+`\.`([^`]+)`, CONSTRAINT/', (string) $e->getMessage(), $m) !== 1) {
                    throw $e;
                }
                self::deleteRows($pdo, $m[1], $supplierId, $depth + 1);
            }
        }
    }
}
