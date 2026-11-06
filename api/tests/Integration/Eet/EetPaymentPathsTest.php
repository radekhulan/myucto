<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Invoice\CancelInvoiceAction;
use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Action\Invoice\DeleteInvoiceAction;
use MyInvoice\Action\Invoice\DeletePaymentAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Action\Invoice\UnmarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetHistoryExistsException;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Service\Invoice\InvoiceAlreadySettledException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * Napojení existujících cest plateb na EET (EET-05) a blokace destruktivních cest
 * (EET-07): ruční úhrada a mark-paid, pokladní doklad, bankovní párování, storno
 * a mazání. Skutečné akce a služby, transport skriptovaný, čas z MockClocku.
 */
#[Group('integration')]
final class EetPaymentPathsTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private ScriptedEetTransport $transport;
    private MockClock $clock;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $deviceId = 0;
    /** @var list<int> */
    private array $bankStatements = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->transport = new ScriptedEetTransport();
        $this->clock = new MockClock(new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('Europe/Prague')));
        $this->container->set(EetTransportInterface::class, $this->transport);
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, $this->clock);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->currencyId, $vat, $this->userId));

        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tax_evidence')"
        )->execute(['Synthetic EET cesty s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eetp-') . '@example.com', $this->currencyId, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 11, 'Provozovna', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Web')")
            ->execute([$this->supplierId, $unitId, 'P' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        $this->deviceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO eet_settings (supplier_id, enabled, environment, eic, active_from, regime, default_device_id)
             VALUES (?, 1, 'playground', 'CZ00000019', '2026-01-01', 'standard', ?)"
        )->execute([$this->supplierId, $this->deviceId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->supplierId !== 0) {
            $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL, bank_transaction_id = NULL WHERE supplier_id = ?')
                ->execute([$this->supplierId]);
            $pdo->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
            foreach ($this->bankStatements as $statementId) {
                $pdo->prepare('DELETE FROM bank_transactions WHERE statement_id = ?')->execute([$statementId]);
                $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$statementId]);
            }
            self::deleteRows($pdo, 'supplier', $this->supplierId, 0);
        }
        $this->db->close();
    }

    public function testCreatePaymentWithCashContextCreatesOneEventOnePaymentOneRecord(): void
    {
        $invoiceId = $this->invoice(1210);
        $body = ['amount' => '1210', 'paid_on' => '2026-11-02', 'channel' => 'cash', 'contact_context' => 'in_person'];
        $response = $this->createPayment($invoiceId, $body, 'pay-key-0001');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertTrue($json['became_paid']);
        self::assertSame('confirmed', $json['eet']['eet']['status']);
        self::assertSame('manual', $json['payment']['source'], 'Bez PPD jako ruční úhrada, jinak by příjem vypadl z peněžního deníku.');

        $replay = $this->json($this->createPayment($invoiceId, $body, 'pay-key-0001'));
        self::assertTrue($replay['eet']['idempotent_replay']);

        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(1, $this->rows('invoice_payments'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    }

    public function testActiveEetWithoutContextIsRejectedWithoutWrites(): void
    {
        $invoiceId = $this->invoice(1210);
        $response = $this->createPayment($invoiceId, ['amount' => '1210', 'paid_on' => '2026-11-02']);
        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('eet_context_required', $error['code']);
        self::assertSame('remote_transfer', $error['extra']['shortcut']['eet_context'] ?? $error['shortcut']['eet_context'] ?? null);

        $card = $this->createPayment($invoiceId, ['amount' => '1210', 'channel' => 'card_terminal'], 'card-key-0001');
        self::assertSame(422, $card->getStatusCode());
        self::assertSame('eet_context_required', $this->json($card)['error']['code']);

        $markPaid = $this->container->get(MarkPaidAction::class)(
            $this->request('POST', ['paid_at' => '2026-11-02']), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(422, $markPaid->getStatusCode());
        self::assertSame('eet_context_required', $this->json($markPaid)['error']['code']);

        $cashNoKey = $this->createPayment($invoiceId, ['amount' => '1210', 'channel' => 'cash', 'contact_context' => 'in_person']);
        self::assertSame(400, $cashNoKey->getStatusCode());
        self::assertSame('idempotency_key_required', $this->json($cashNoKey)['error']['code']);

        self::assertSame(0, $this->rows('invoice_payments'));
        self::assertSame(0, $this->rows('payment_events'));
    }

    public function testRemoteTransferShortcutKeepsOriginalPath(): void
    {
        $invoiceId = $this->invoice(1210);
        $response = $this->createPayment($invoiceId, ['amount' => '1210', 'paid_on' => '2026-10-20', 'eet_context' => 'remote_transfer']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame('not_subject', $json['eet_decision']['decision']);
        self::assertSame('manual', $json['payment']['source']);
        self::assertSame('2026-10-20', $json['payment']['paid_on']);
        self::assertSame(0, $this->rows('payment_events'));
        self::assertSame(0, $this->rows('eet_records'));
    }

    /** Firma bez aktivní EET: ruční úhrada beze změny, EET pole v těle se ignorují. */
    public function testCompanyWithoutActiveEetKeepsLegacyResponse(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET enabled = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $invoiceId = $this->invoice(1210);
        $response = $this->createPayment($invoiceId, ['amount' => '1000', 'paid_on' => '2026-10-20', 'channel' => 'cash', 'contact_context' => 'in_person']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame(['invoice', 'payments', 'payment', 'became_paid', 'remaining', 'payment_thanks'], array_keys($json));
        self::assertSame('manual', $json['payment']['source']);
        self::assertSame('2026-10-20', $json['payment']['paid_on']);

        $markPaid = $this->container->get(MarkPaidAction::class)(
            $this->request('POST', ['paid_at' => '2026-10-21']), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(200, $markPaid->getStatusCode());
        self::assertArrayNotHasKey('eet', $this->json($markPaid));
        self::assertArrayNotHasKey('eet_decision', $this->json($markPaid));
        self::assertSame(['manual', 'mark_paid'], $this->db->pdo()->query(
            'SELECT source FROM invoice_payments WHERE supplier_id = ' . $this->supplierId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(0, $this->rows('payment_events'));
    }

    public function testMarkPaidWithContextRecordsRemainingAsSale(): void
    {
        $invoiceId = $this->invoice(1210);
        $response = $this->container->get(MarkPaidAction::class)(
            $this->request('POST', ['channel' => 'cash', 'contact_context' => 'in_person'], ['Idempotency-Key' => 'mark-key-0001']),
            new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('paid', $this->json($response)['status']);
        self::assertSame('1210.00', $this->scalar('SELECT total_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame(1, $this->rows('invoice_payments'));
    }

    /** Zakázková záloha a doplatek přes ruční úhradu: dvě tržby, finální částka se nehlásí celá. */
    public function testProformaAndFinalPaymentThroughActionAreTwoSales(): void
    {
        $proforma = $this->invoice(5000, 'proforma');
        $deposit = $this->createPayment($proforma, ['amount' => '5000', 'channel' => 'cash', 'contact_context' => 'in_person'], 'dep-key-00001');
        self::assertSame(200, $deposit->getStatusCode(), (string) $deposit->getBody());
        $final = $this->invoice(8000, 'invoice', $proforma, 5000);
        $rest = $this->createPayment($final, ['amount' => '3000', 'channel' => 'cash', 'contact_context' => 'in_person'], 'fin-key-00001');
        self::assertSame(200, $rest->getStatusCode(), (string) $rest->getBody());

        $stmt = $this->db->pdo()->prepare('SELECT e.business_purpose, r.total_amount FROM eet_records r JOIN payment_events e ON e.id = r.payment_event_id WHERE r.supplier_id = ? ORDER BY r.id');
        $stmt->execute([$this->supplierId]);
        self::assertSame([['order_deposit', '5000.00'], ['sale', '3000.00']], $stmt->fetchAll(PDO::FETCH_NUM));
    }

    /** PPD k faktuře → právě jedna tržba sdílená s úhradou; banka pak nic nepřidá. */
    public function testCashDocumentInvoicePaymentCreatesSingleRecordAndBankAddsNothing(): void
    {
        $invoiceId = $this->invoice(1210);
        $registerId = $this->mappedRegister();
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'invoice_payment', 'invoice_id' => $invoiceId,
            'issue_date' => '2026-11-02', 'description' => 'Úhrada faktury', 'total_amount' => 1210.00,
        ], $this->userId);
        self::assertSame('posted', $created['status']);

        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('invoice_payments'));
        $allocation = $this->row('SELECT a.cash_document_id, a.invoice_payment_id, p.source FROM payment_event_allocations a JOIN invoice_payments p ON p.id = a.invoice_payment_id WHERE a.supplier_id = ?', [$this->supplierId]);
        self::assertSame([(int) $created['id'], 'cash'], [(int) $allocation['cash_document_id'], $allocation['source']]);
        self::assertSame('confirmed', $this->scalar('SELECT status FROM eet_records WHERE supplier_id = ?', [$this->supplierId]),
            'Odeslání po commitu zaúčtování.');

        $txId = $this->bankTransaction(1210);
        try {
            $this->container->get(InvoicePaymentService::class)->recordPayment($invoiceId, 1210, '2026-11-03',
                ['source' => 'bank', 'bank_transaction_id' => $txId]);
            self::fail('Bankovní úhrada uhrazené faktury prošla.');
        } catch (InvoiceAlreadySettledException) {
        }
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('invoice_payments'));

        // Opakované zaúčtování (dvojklik) druhou tržbu nezaloží.
        $this->container->get(CashDocumentService::class)->post($this->supplierId, (int) $created['id'], $this->userId);
        self::assertSame(1, $this->rows('eet_records'));
    }

    /** Nenamapovaná pokladna eviduje na výchozí zařízení; jiný účel než tržba nic nezakládá. */
    public function testCashSaleOnUnmappedRegisterUsesDefaultDeviceAndNonSalePurposesCreateNothing(): void
    {
        $service = $this->container->get(CashDocumentService::class);
        $mapped = $this->mappedRegister('211.100');
        $service->create($this->supplierId, [
            'register_id' => $mapped, 'doc_type' => 'in', 'purpose' => 'other', 'issue_date' => '2026-11-02',
            'description' => 'Výběr z banky', 'total_amount' => 800.00, 'counter_account_code' => '221',
        ], $this->userId);
        self::assertSame(0, $this->rows('eet_records'));

        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = NULL WHERE id = ?')->execute([$this->deviceId]);
        $unmapped = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Bez mapování', 'account_code' => '211', 'is_default' => true]);
        $service->create($this->supplierId, $this->sale($unmapped, 500.00), $this->userId);
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame($this->deviceId, (int) $this->scalar('SELECT device_id FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('cash_document', $this->scalar('SELECT origin FROM payment_events WHERE supplier_id = ?', [$this->supplierId]));
    }

    /** Storno PPD = vrácení peněz: záporná oprava celé tržby, úhrada faktury padá. */
    public function testCashDocumentReversalCreatesNegativeCorrection(): void
    {
        $invoiceId = $this->invoice(1210);
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $created = $service->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'invoice_payment', 'invoice_id' => $invoiceId,
            'issue_date' => '2026-11-02', 'description' => 'Úhrada faktury', 'total_amount' => 1210.00,
        ], $this->userId);

        try {
            $service->deleteDocument($this->supplierId, (int) $created['id']);
            self::fail('Smazání PPD s tržbou EET prošlo.');
        } catch (CashException $e) {
            self::assertSame(['eet_history_exists', 409], [$e->errorCode, $e->httpStatus]);
        }

        $this->clock->modify('+1 hour');
        $service->reverse($this->supplierId, (int) $created['id'], ['reason' => 'Zákazník vrátil zboží'], $this->userId);
        $stmt = $this->db->pdo()->prepare('SELECT kind, total_amount, correction_reason, status FROM eet_records WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        self::assertSame([['sale', '1210.00', null, 'confirmed'], ['correction', '-1210.00', 'refund', 'confirmed']], $stmt->fetchAll(PDO::FETCH_NUM));
        self::assertSame(0, $this->rows('invoice_payments'));
        self::assertSame('settled', $this->scalar("SELECT accounting_settlement_status FROM payment_events WHERE supplier_id = ? AND kind = 'refund'", [$this->supplierId]));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM payment_event_allocations a JOIN payment_events e ON e.id = a.payment_event_id WHERE a.supplier_id = ? AND e.kind = 'refund' AND a.cash_document_id = ?", [$this->supplierId, (int) $created['id']]));
    }

    /** Filtr cash_document_id vrací jen záznamy daného dokladu (tržbu i opravu) a nikdy cizí firmy. */
    public function testRecordListFiltersByCashDocumentWithinSupplier(): void
    {
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $first = $service->create($this->supplierId, $this->sale($registerId, 100.00), $this->userId);
        $second = $service->create($this->supplierId, $this->sale($registerId, 200.00), $this->userId);
        $this->clock->modify('+1 hour');
        $service->reverse($this->supplierId, (int) $first['id'], ['reason' => 'Vrácení'], $this->userId);

        $query = $this->container->get(\MyInvoice\Service\Eet\EetRecordQuery::class);
        $own = $query->list($this->supplierId, ['cash_document_id' => (string) $first['id']], null, 50);
        self::assertSame(['correction', 'sale'], array_column($own['items'], 'kind'));
        $other = $query->list($this->supplierId, ['cash_document_id' => (string) $second['id']], null, 50);
        self::assertCount(1, $other['items']);
        self::assertCount(0, $query->list($this->supplierId + 1000000, ['cash_document_id' => (string) $first['id']], null, 50)['items']);
        $this->expectException(\MyInvoice\Service\Eet\EetCommandException::class);
        $query->list($this->supplierId, ['cash_document_id' => 'x'], null, 50);
    }

    /** QR potvrzení → bankovní párování: jedna úhrada, jeden záznam, ověřeno a vypořádáno. */
    public function testQrConfirmThenBankPairingSettlesTheSameEvent(): void
    {
        $invoiceId = $this->invoice(1210);
        $command = $this->container->get(EetPaymentCommand::class);
        $intent = $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);
        $command->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'qr-confirm-01', []);
        self::assertSame(0, $this->rows('invoice_payments'));

        $txId = $this->bankTransaction(1210);
        $payments = $this->container->get(InvoicePaymentService::class);
        $payments->recordPayment($invoiceId, 1210, '2026-11-03', ['source' => 'bank', 'bank_transaction_id' => $txId]);

        self::assertSame(1, $this->rows('invoice_payments'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('payment_event_allocations'), 'Zamýšlená alokace se doplní, ne zdvojí.');
        $event = $this->row('SELECT payment_order_status, bank_verification_status, accounting_settlement_status FROM payment_events WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['settled', 'verified', 'settled'], array_values($event));
        $allocation = $this->row('SELECT invoice_payment_id, bank_transaction_id FROM payment_event_allocations WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame($txId, (int) $allocation['bank_transaction_id']);

        // Zrušení spárování vrací QR příkaz do stavu „čeká na banku", tržba zůstává.
        $payments->deleteForBankTransaction($txId);
        $event = $this->row('SELECT payment_order_status, bank_verification_status, accounting_settlement_status FROM payment_events WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['operator_confirmed', 'pending', 'pending'], array_values($event));
        self::assertSame(1, $this->rows('eet_records'));
    }

    /**
     * Nový QR záměr nahradí dřívější nepotvrzený, takže otevřený záměr nikdy nepřesáhne dluh.
     * Odmítnutý nový záměr (nad dluh) dřívější záměr nezruší: zrušení se vrátí s transakcí.
     */
    public function testRejectedQrIntentOverDebtKeepsPreviousIntent(): void
    {
        $invoiceId = $this->invoice(1000);
        $command = $this->container->get(EetPaymentCommand::class);
        $first = $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);
        try {
            $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId, 'amount' => '1001']);
            self::fail('QR záměr nad dluh faktury prošel.');
        } catch (\MyInvoice\Service\Eet\EetCommandException $e) {
            self::assertSame(422, $e->httpStatus);
        }
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM payment_events WHERE supplier_id = ? AND origin = 'payment_intent'", [$this->supplierId]));
        self::assertSame('initiated', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$first['payment_event_id']]));
    }

    /** Potvrzení QR záměru pod zámkem faktury znovu ověří dluh: hotovost mezitím fakturu uhradila. */
    public function testConfirmIntentRechecksDebtUnderInvoiceLock(): void
    {
        $invoiceId = $this->invoice(1000);
        $command = $this->container->get(EetPaymentCommand::class);
        $intent = $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);
        $command->accept($this->supplierId, $this->userId, 'cash-before-qr1', [
            'invoice_id' => $invoiceId, 'amount' => '1000', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        try {
            $command->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'qr-late-conf01', []);
            self::fail('Potvrzení QR záměru na uhrazenou fakturu prošlo.');
        } catch (\MyInvoice\Service\Eet\EetCommandException $e) {
            self::assertSame(['eet_validation', '0.00'], [$e->errorCode, $e->extra['remaining'] ?? null]);
        }
        self::assertSame(1, $this->rows('eet_records'), 'Na jedny peníze jen jedna tržba.');
        self::assertSame('initiated', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$intent['payment_event_id']]));
    }

    public function testDestructivePathsAreBlockedForInvoiceWithEet(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->json($this->createPayment($invoiceId, ['amount' => '1210', 'channel' => 'cash', 'contact_context' => 'in_person'], 'block-key-001'));
        $paymentId = (int) $this->scalar('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);

        $delete = $this->container->get(DeletePaymentAction::class)(
            $this->request('DELETE'), new Psr7Response(), ['id' => (string) $invoiceId, 'paymentId' => (string) $paymentId]);
        self::assertSame(409, $delete->getStatusCode());
        self::assertSame('eet_history_exists', $this->json($delete)['error']['code']);

        $unmark = $this->container->get(UnmarkPaidAction::class)($this->request('POST'), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(409, $unmark->getStatusCode());
        self::assertSame('eet_history_exists', $this->json($unmark)['error']['code']);

        $deleteInvoice = $this->container->get(DeleteInvoiceAction::class)(
            $this->request('DELETE', [], [], ['force' => '1']), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(409, $deleteInvoice->getStatusCode(), (string) $deleteInvoice->getBody());
        self::assertSame('eet_history_exists', $this->json($deleteInvoice)['error']['code']);

        $this->expectException(EetHistoryExistsException::class);
        try {
            $this->container->get(InvoiceRepository::class)->delete($invoiceId);
        } finally {
            self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoices WHERE id = ?', [$invoiceId]));
            self::assertSame(1, $this->rows('invoice_payments'));
        }
    }

    /** Storno faktury EET samo neopravuje, jen upozorní. */
    public function testInvoiceCancellationOnlyWarnsAboutEet(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->createPayment($invoiceId, ['amount' => '1210', 'channel' => 'cash', 'contact_context' => 'in_person'], 'cancel-key-01');
        $response = $this->container->get(CancelInvoiceAction::class)(
            $this->request('POST', ['mode' => 'internal', 'reason' => 'Chyba']), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('opravu EET', (string) $this->json($response)['eet_warning']);
        self::assertSame(1, $this->rows('eet_records'), 'Storno faktury nesmí vytvořit opravu EET.');
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @param array<string,mixed> $body */
    private function createPayment(int $invoiceId, array $body, ?string $key = null): ResponseInterface
    {
        return $this->container->get(CreatePaymentAction::class)(
            $this->request('POST', $body, $key !== null ? ['Idempotency-Key' => $key] : []),
            new Psr7Response(),
            ['id' => (string) $invoiceId],
        );
    }

    private function mappedRegister(string $account = '211'): int
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$this->supplierId]) === 0) {
            $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        }
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Pokladna ' . $account, 'account_code' => $account, 'is_default' => true]);
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$registerId, $this->deviceId]);
        return $registerId;
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total): array
    {
        return ['register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'sale', 'issue_date' => '2026-11-02',
            'description' => 'Tržba', 'total_amount' => $total];
    }

    private function bankTransaction(float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (file_name, file_hash, account_number, statement_date) VALUES ('eet.gpc', ?, '1000000005/0100', '2026-11-03')")
            ->execute([hash('sha256', uniqid('eet', true))]);
        $statementId = (int) $pdo->lastInsertId();
        $this->bankStatements[] = $statementId;
        $pdo->prepare('INSERT INTO bank_transactions (statement_id, posted_at, amount) VALUES (?, ?, ?)')->execute([$statementId, '2026-11-03', $amount]);
        return (int) $pdo->lastInsertId();
    }

    private function invoice(float $total, string $type = 'invoice', ?int $parentId = null, float $advance = 0): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 parent_invoice_id, bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, 0, ?, 0, ?, ?, ?, ?, "issued", "1", ?)'
        )->execute([
            $this->supplierId, (string) random_int(1000000, 9999999), $type, $this->clientId, $this->currencyId,
            $total, $total, $advance, $parentId,
            json_encode(['account_number' => '1000000005', 'bank_code' => '0100']), $this->userId,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                        total_without_vat, total_vat, total_with_vat)
             SELECT ?, "Synthetic item", 1, ?, id, 0, ?, 0, ? FROM vat_rates ORDER BY id LIMIT 1'
        )->execute([$invoiceId, $total, $total, $total]);
        return $invoiceId;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    private function request(string $method, array $body = [], array $headers = [], array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $body !== [] ? $request->withParsedBody($body) : $request;
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }

    private function rows(string $table): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE supplier_id = ?", [$this->supplierId]);
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
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
