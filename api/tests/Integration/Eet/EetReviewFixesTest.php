<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Invoice\CreatePaymentAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetDispatchService;
use MyInvoice\Service\Eet\EetHistoryExistsException;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\PaymentEventService;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Service\Invoice\InvoicePaymentService;
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
 * Nálezy z review EET 2.0: peněžní deník hotovosti přijaté k faktuře, uvolnění QR
 * příkazu opravou, čas a zámek workeru, potvrzené QR vůči PPD a ruční úhradě, čas
 * tržby z PPD, zařízení opravy, interní klíče idempotence, pojistky mazání a párování
 * banky. Každý test bez příslušné opravy padá.
 */
#[Group('integration')]
final class EetReviewFixesTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private ScriptedEetTransport $transport;
    private MockClock $clock;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $unitId = 0;
    private int $deviceId = 0;
    /** @var list<int> */
    private array $bankStatements = [];
    /** @var list<Connection> */
    private array $extraConnections = [];

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
        )->execute(['Synthetic EET review s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eetr-') . '@example.com', $this->currencyId, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 11, 'Provozovna', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $this->unitId = (int) $pdo->lastInsertId();
        $this->deviceId = $this->device();
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
        foreach ($this->extraConnections as $extra) {
            try {
                if ($extra->pdo()->inTransaction()) {
                    $extra->pdo()->rollBack();
                }
            } catch (\Throwable) {
            }
            $extra->close();
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
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

    // ── 1: hotovost k faktuře bez PPD patří do peněžního deníku ─────────────

    public function testCashAcceptedForInvoiceWithoutCashDocumentIsInCashJournal(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->command()->accept($this->supplierId, $this->userId, 'journal-key-01', $this->cash($invoiceId, '1210'));
        self::assertSame('manual', $this->scalar('SELECT source FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));

        $markPaidInvoice = $this->invoice(500);
        $response = $this->container->get(MarkPaidAction::class)(
            $this->request('POST', ['channel' => 'cash', 'contact_context' => 'in_person'], ['Idempotency-Key' => 'journal-key-02']),
            new Psr7Response(), ['id' => (string) $markPaidInvoice]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('mark_paid', $this->scalar('SELECT source FROM invoice_payments WHERE invoice_id = ?', [$markPaidInvoice]));

        $movements = $this->container->get(CashJournalRepository::class)->movements($this->supplierId, '2026-11-01', '2026-11-30');
        $incomes = array_values(array_map(
            static fn (array $m): string => number_format((float) $m['amount'], 2, '.', ''),
            array_filter($movements, static fn (array $m): bool => $m['source_type'] === 'invoice_payment' && $m['direction'] === 'in'),
        ));
        sort($incomes, SORT_STRING);
        self::assertSame(['1210.00', '500.00'], $incomes, 'Příjem hotovosti k faktuře bez PPD musí být v peněžním deníku.');
    }

    // ── 2 + 10: oprava uvolní QR příkaz, banka páruje i jinou částku ───────

    public function testFullFailedPaymentCorrectionReleasesConfirmedQrOrder(): void
    {
        $invoiceId = $this->invoice(1000);
        $eventId = $this->confirmedQr($invoiceId, 'qr-full-conf01');
        self::assertSame('0.00', $this->remaining($invoiceId));

        $recordId = (int) $this->scalar("SELECT id FROM eet_records WHERE payment_event_id = ? AND kind = 'sale'", [$eventId]);
        $this->container->get(EetCorrectionService::class)->create($this->supplierId, $this->userId, $recordId, 'qr-full-corr01',
            ['amount' => '1000', 'reason' => 'failed_payment']);

        $event = $this->row('SELECT payment_order_status, bank_verification_status, accounting_settlement_status FROM payment_events WHERE id = ?', [$eventId]);
        self::assertSame(['failed', 'not_applicable', 'not_applicable'], array_values($event));
        self::assertSame('1000.00', $this->remaining($invoiceId), 'Vynulovaný QR příkaz nesmí dál blokovat dluh faktury.');

        $txId = $this->bankTransaction(1000);
        $this->container->get(InvoicePaymentService::class)->recordPayment($invoiceId, 1000, '2026-11-03',
            ['source' => 'bank', 'bank_transaction_id' => $txId]);
        self::assertSame('failed', $this->scalar('SELECT payment_order_status FROM payment_events WHERE id = ?', [$eventId]),
            'Banka se k vynulované tržbě nepřiváže.');
        self::assertNull($this->scalar('SELECT bank_transaction_id FROM payment_event_allocations WHERE payment_event_id = ?', [$eventId]));
    }

    public function testPartialCorrectionLowersBlockedAmountAndSmallerBankPaymentSettlesIt(): void
    {
        $invoiceId = $this->invoice(1000);
        $eventId = $this->confirmedQr($invoiceId, 'qr-part-conf01');
        $recordId = (int) $this->scalar("SELECT id FROM eet_records WHERE payment_event_id = ? AND kind = 'sale'", [$eventId]);
        $this->container->get(EetCorrectionService::class)->create($this->supplierId, $this->userId, $recordId, 'qr-part-corr01',
            ['amount' => '300', 'reason' => 'wrong_data']);

        self::assertSame('operator_confirmed', $this->scalar('SELECT payment_order_status FROM payment_events WHERE id = ?', [$eventId]));
        self::assertSame('300.00', $this->remaining($invoiceId), 'Blokuje jen neopravená část QR příkazu.');

        $txId = $this->bankTransaction(700);
        $this->container->get(InvoicePaymentService::class)->recordPayment($invoiceId, 700, '2026-11-03',
            ['source' => 'bank', 'bank_transaction_id' => $txId]);
        $event = $this->row('SELECT payment_order_status, bank_verification_status, accounting_settlement_status FROM payment_events WHERE id = ?', [$eventId]);
        self::assertSame(['settled', 'verified', 'settled'], array_values($event));
    }

    public function testBankPaymentHigherThanQrSettlesItAndSmallerLeavesItPending(): void
    {
        $invoiceId = $this->invoice(1500);
        $eventId = $this->confirmedQr($invoiceId, 'qr-more-conf01', '1000');

        $small = $this->bankTransaction(400);
        $payments = $this->container->get(InvoicePaymentService::class);
        $payments->recordPayment($invoiceId, 400, '2026-11-03', ['source' => 'bank', 'bank_transaction_id' => $small]);
        self::assertSame('operator_confirmed', $this->scalar('SELECT payment_order_status FROM payment_events WHERE id = ?', [$eventId]));

        $big = $this->bankTransaction(1100);
        $payments->recordPayment($invoiceId, 1100, '2026-11-04', ['source' => 'bank', 'bank_transaction_id' => $big]);
        $event = $this->row('SELECT payment_order_status, bank_verification_status, accounting_settlement_status FROM payment_events WHERE id = ?', [$eventId]);
        self::assertSame(['settled', 'verified', 'settled'], array_values($event));
        self::assertSame($big, (int) $this->scalar('SELECT bank_transaction_id FROM payment_event_allocations WHERE payment_event_id = ?', [$eventId]));
    }

    // ── 3: worker bere čas pro každý záznam a nesouběží sám se sebou ───────

    public function testWorkerTakesFreshTimeForEachRecord(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET dispatch_blocked = 1 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->command()->accept($this->supplierId, $this->userId, 'worker-key-001', $this->cash($this->invoice(100), '100'));
        $this->command()->accept($this->supplierId, $this->userId, 'worker-key-002', $this->cash($this->invoice(200), '200'));
        $this->db->pdo()->prepare('UPDATE eet_settings SET dispatch_blocked = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->clock->modify('+1 minute');

        $leaseSeenBySecond = null;
        $clockAtSecond = null;
        $this->transport->script = [
            function () {
                $this->clock->modify('+90 seconds'); // pomalý FS: déle než lease workeru
                return ScriptedEetTransport::confirmed();
            },
            function () use (&$leaseSeenBySecond, &$clockAtSecond) {
                $leaseSeenBySecond = (string) $this->scalar("SELECT lease_until FROM eet_records WHERE supplier_id = ? AND status = 'sending'", [$this->supplierId]);
                $clockAtSecond = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Prague'));
                return ScriptedEetTransport::confirmed('TEST-POK-0002-ff');
            },
        ];
        self::assertSame(2, $this->container->get(EetDispatchService::class)->processDue(50));

        self::assertNotNull($clockAtSecond);
        self::assertGreaterThan($clockAtSecond->format('Y-m-d H:i:s.u'), $leaseSeenBySecond,
            'Lease druhého záznamu nesmí být při odesílání už vypršelý.');
        self::assertSame($clockAtSecond->format(DATE_ATOM), $this->transport->calls[1]['header']->sentAt->setTimezone(new \DateTimeZone('Europe/Prague'))->format(DATE_ATOM),
            'dat_odesl musí odpovídat skutečnému okamžiku odeslání.');
    }

    public function testConcurrentWorkerRunIsSkipped(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET dispatch_blocked = 1 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->command()->accept($this->supplierId, $this->userId, 'worker-lock-01', $this->cash($this->invoice(100), '100'));
        $this->db->pdo()->prepare('UPDATE eet_settings SET dispatch_blocked = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $this->clock->modify('+1 minute');

        $other = $this->otherConnection();
        $lock = $other->pdo()->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([NamedLockName::for($this->db, EetDispatchService::WORKER_LOCK_SCOPE)]);
        self::assertSame(1, (int) $lock->fetchColumn());

        self::assertSame(0, $this->container->get(EetDispatchService::class)->processDue(50));
        self::assertCount(0, $this->transport->calls, 'Běžící worker drží zámek, druhý běh nic neodešle.');

        $other->pdo()->prepare('SELECT RELEASE_LOCK(?)')->execute([NamedLockName::for($this->db, EetDispatchService::WORKER_LOCK_SCOPE)]);
        self::assertSame(1, $this->container->get(EetDispatchService::class)->processDue(50));
    }

    // ── 4: potvrzený QR příkaz vůči PPD a ruční úhradě mimo EET ───────────

    public function testCashDocumentOverConfirmedQrIsRejected(): void
    {
        $invoiceId = $this->invoice(1000);
        $this->confirmedQr($invoiceId, 'qr-ppd-conf001');
        $registerId = $this->mappedRegister();
        try {
            $this->container->get(CashDocumentService::class)->create($this->supplierId, [
                'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'invoice_payment', 'invoice_id' => $invoiceId,
                'issue_date' => '2026-11-02', 'description' => 'Úhrada faktury', 'total_amount' => 1000.00,
            ], $this->userId);
            self::fail('PPD přes dluh blokovaný potvrzeným QR příkazem prošel.');
        } catch (CashException $e) {
            self::assertSame(['eet_qr_pending', 422], [$e->errorCode, $e->httpStatus]);
        }
        self::assertSame(1, $this->rows('eet_records'), 'Na jedny peníze jen jedna tržba.');
        self::assertSame(0, $this->rows('invoice_payments'));
    }

    public function testRemoteTransferOverConfirmedQrIsRejected(): void
    {
        $invoiceId = $this->invoice(1000);
        $this->confirmedQr($invoiceId, 'qr-rem-conf001');
        $response = $this->container->get(CreatePaymentAction::class)(
            $this->request('POST', ['amount' => '1000', 'paid_on' => '2026-11-02', 'eet_context' => 'remote_transfer']),
            new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('eet_qr_pending', $this->json($response)['error']['code']);

        $markPaid = $this->container->get(MarkPaidAction::class)(
            $this->request('POST', ['eet_context' => 'remote_transfer']), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(422, $markPaid->getStatusCode(), (string) $markPaid->getBody());
        self::assertSame(0, $this->rows('invoice_payments'));
    }

    // ── 5: čas tržby z PPD ────────────────────────────────────────────────

    public function testCashDocumentSaleTimeComesFromDocument(): void
    {
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);

        $yesterday = $service->create($this->supplierId, $this->sale($registerId, 300.00, '2026-11-01') + ['post' => false], $this->userId);
        $this->db->pdo()->prepare("UPDATE cash_documents SET created_at = '2026-11-01 15:30:00' WHERE id = ?")->execute([$yesterday['id']]);
        $service->post($this->supplierId, (int) $yesterday['id'], $this->userId);

        $old = $service->create($this->supplierId, $this->sale($registerId, 400.00, '2026-10-29') + ['post' => false], $this->userId);
        // Pevné created_at mimo den dokladu: z výchozího CURRENT_TIMESTAMP by test spadl
        // právě v den, kdy reálné datum = datum dokladu (hodiny testu jsou zafixované).
        $this->db->pdo()->prepare("UPDATE cash_documents SET created_at = '2026-10-31 09:00:00' WHERE id = ?")->execute([$old['id']]);
        $service->post($this->supplierId, (int) $old['id'], $this->userId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT a.cash_document_id, r.sale_at, r.deadline_at, r.overdue
               FROM eet_records r JOIN payment_event_allocations a ON a.payment_event_id = r.payment_event_id
              WHERE r.supplier_id = ? ORDER BY r.id'
        );
        $stmt->execute([$this->supplierId]);
        self::assertEquals([
            [(int) $yesterday['id'], '2026-11-01 15:30:00', '2026-11-03 15:30:00', 0],
            [(int) $old['id'], '2026-10-29 12:00:00', '2026-10-31 12:00:00', 1],
        ], array_map(static fn (array $r): array => [(int) $r[0], $r[1], $r[2], (int) $r[3]], $stmt->fetchAll(PDO::FETCH_NUM)));
    }

    // ── 6: oprava na deaktivovaném zařízení ──────────────────────────────

    public function testCorrectionOnDeactivatedDeviceUsesAnotherActiveDevice(): void
    {
        $second = $this->device();
        $first = $this->command()->accept($this->supplierId, $this->userId, 'device-key-001', $this->cash(null, '100'));
        $other = $this->command()->accept($this->supplierId, $this->userId, 'device-key-002', $this->cash(null, '200'));
        $this->db->pdo()->prepare('UPDATE eet_devices SET active = 0 WHERE id = ?')->execute([$this->deviceId]);

        $corrections = $this->container->get(EetCorrectionService::class);
        $corrections->create($this->supplierId, $this->userId, (int) $first['eet']['record_id'], 'device-corr-01', ['amount' => '100', 'reason' => 'refund']);
        self::assertSame($second, (int) $this->scalar("SELECT device_id FROM eet_records WHERE supplier_id = ? AND kind = 'correction'", [$this->supplierId]));

        $this->db->pdo()->prepare('UPDATE eet_devices SET active = 0 WHERE id = ?')->execute([$second]);
        try {
            $corrections->create($this->supplierId, $this->userId, (int) $other['eet']['record_id'], 'device-corr-02', ['amount' => '200', 'reason' => 'refund']);
            self::fail('Oprava bez aktivního zařízení prošla.');
        } catch (EetCommandException $e) {
            self::assertSame([422, 'no_active_device'], [$e->httpStatus, $e->extra['reason_code'] ?? null]);
        }
    }

    public function testCashDocumentReversalAcceptsExplicitDevice(): void
    {
        $registerId = $this->mappedRegister();
        $second = $this->device();
        $service = $this->container->get(CashDocumentService::class);
        $created = $service->create($this->supplierId, $this->sale($registerId, 300.00), $this->userId);
        $service->reverse($this->supplierId, (int) $created['id'], ['reason' => 'Vrácení zboží', 'eet_device_id' => $second], $this->userId);
        self::assertSame($second, (int) $this->scalar("SELECT device_id FROM eet_records WHERE supplier_id = ? AND kind = 'correction'", [$this->supplierId]));
    }

    // ── 7: interní klíče idempotence ─────────────────────────────────────

    public function testClientCannotOccupyInternalCashDocumentKey(): void
    {
        try {
            PaymentEventService::validateKey('sys:cash-document:1');
            self::fail('Klient obsadil vyhrazenou předponu klíče.');
        } catch (EetCommandException $e) {
            self::assertSame(['idempotency_key_reserved', 400], [$e->errorCode, $e->httpStatus]);
        }

        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $draft = $service->create($this->supplierId, $this->sale($registerId, 300.00) + ['post' => false], $this->userId);
        // Klíč ve tvaru dřívějšího interního klíče dokladu, poslaný z klienta.
        $this->command()->accept($this->supplierId, $this->userId, 'cash-document:' . $draft['id'], $this->cash(null, '50'));
        $service->post($this->supplierId, (int) $draft['id'], $this->userId);

        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM payment_event_allocations a JOIN eet_records r ON r.payment_event_id = a.payment_event_id AND r.kind = \'sale\'
              WHERE a.supplier_id = ? AND a.cash_document_id = ?', [$this->supplierId, (int) $draft['id']]),
            'Tržba PPD vznikne bez ohledu na klíč, který si zvolil klient.');
    }

    // ── 8: pojistka mazání úhrady pod zámkem faktury ─────────────────────

    public function testDeletePaymentChecksEetHistoryUnderInvoiceLock(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->command()->accept($this->supplierId, $this->userId, 'lock-del-key01', $this->cash($invoiceId, '1210'));
        $paymentId = (int) $this->scalar('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);

        $other = $this->otherConnection();
        $other->pdo()->beginTransaction();
        $other->pdo()->prepare('SELECT id FROM invoices WHERE id = ? FOR UPDATE')->execute([$invoiceId]);
        $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->container->get(InvoicePaymentService::class)->deletePayment($paymentId);
            self::fail('Mazání úhrady neprošlo ani pojistkou, ani zámkem.');
        } catch (EetHistoryExistsException) {
            self::fail('Pojistka EET běžela před zámkem faktury (souběžný příkaz by ji obešel).');
        } catch (\PDOException $e) {
            self::assertSame(1205, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
        } finally {
            $other->pdo()->rollBack();
            $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        $this->expectException(EetHistoryExistsException::class);
        $this->container->get(InvoicePaymentService::class)->deletePayment($paymentId);
    }

    // ── 9: nepoužitý QR záměr neblokuje smazání faktury ──────────────────

    public function testUnusedQrIntentsDoNotBlockInvoiceDeletion(): void
    {
        $invoiceId = $this->invoice(1000);
        $command = $this->command();
        $cancelled = $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId, 'amount' => '400']);
        $command->cancelIntent($this->supplierId, $this->userId, $cancelled['payment_event_id']);
        $command->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId, 'amount' => '600']);

        $this->container->get(InvoiceRepository::class)->delete($invoiceId);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM payment_events WHERE supplier_id = ? AND invoice_id = ?', [$this->supplierId, $invoiceId]));
    }

    // ── 11: pokladna bez namapovaného zařízení ───────────────────────────

    public function testUnmappedRegisterFallsBackToDefaultDeviceOrRejects(): void
    {
        $service = $this->container->get(CashDocumentService::class);
        $this->ensureChart();
        $unmapped = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Bez mapování', 'account_code' => '211', 'is_default' => true]);
        $service->create($this->supplierId, $this->sale($unmapped, 300.00), $this->userId);
        self::assertSame($this->deviceId, (int) $this->scalar("SELECT device_id FROM eet_records WHERE supplier_id = ? AND kind = 'sale'", [$this->supplierId]));

        $this->db->pdo()->prepare('UPDATE eet_settings SET default_device_id = NULL WHERE supplier_id = ?')->execute([$this->supplierId]);
        try {
            $service->create($this->supplierId, $this->sale($unmapped, 200.00), $this->userId);
            self::fail('Povinná hotovost bez zařízení se zaúčtovala bez evidence.');
        } catch (CashException $e) {
            self::assertSame(['eet_device_required', 422], [$e->errorCode, $e->httpStatus]);
        }
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM cash_documents WHERE supplier_id = ? AND status = 'posted'", [$this->supplierId]));
    }

    // ── 12: párování banky u firmy bez EET nezamyká payment_events ───────

    public function testBankPaymentOfCompanyWithoutEetDoesNotLockPaymentEvents(): void
    {
        $this->db->pdo()->prepare('DELETE FROM eet_settings WHERE supplier_id = ?')->execute([$this->supplierId]);
        $invoiceId = $this->invoice(1000);
        $txId = $this->bankTransaction(1000);

        $other = $this->otherConnection();
        $other->pdo()->beginTransaction();
        $other->pdo()->prepare(
            "INSERT INTO payment_events (public_id, supplier_id, kind, origin, contact_context, amount, recorded_at, payment_order_status, eet_decision, invoice_id)
             VALUES (?, ?, 'intent', 'payment_intent', 'in_person', 1000.00, NOW(6), 'initiated', 'required', ?)"
        )->execute(['5d1c1f5e-3b0a-4c7e-9f00-' . substr(bin2hex(random_bytes(6)), 0, 12), $this->supplierId, $invoiceId]);
        $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->container->get(InvoicePaymentService::class)->recordPayment($invoiceId, 1000, '2026-11-03',
                ['source' => 'bank', 'bank_transaction_id' => $txId]);
        } finally {
            $other->pdo()->rollBack();
            $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function command(): EetPaymentCommand
    {
        return $this->container->get(EetPaymentCommand::class);
    }

    /** @return array<string,mixed> */
    private function cash(?int $invoiceId, string $amount): array
    {
        return ['invoice_id' => $invoiceId, 'amount' => $amount, 'channel' => 'cash', 'contact_context' => 'in_person'];
    }

    private function confirmedQr(int $invoiceId, string $key, ?string $amount = null): int
    {
        $intent = $this->command()->createIntent($this->supplierId, $this->userId, null,
            ['invoice_id' => $invoiceId] + ($amount !== null ? ['amount' => $amount] : []));
        $this->command()->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], $key, []);
        return (int) $this->scalar('SELECT id FROM payment_events WHERE public_id = ?', [$intent['payment_event_id']]);
    }

    private function remaining(int $invoiceId): string
    {
        return (string) $this->command()->preview($this->supplierId, [
            'invoice_id' => $invoiceId, 'amount' => '1', 'channel' => 'cash', 'contact_context' => 'in_person',
        ])['invoice']['remaining'];
    }

    private function device(): int
    {
        $this->db->pdo()->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Pokladna')")
            ->execute([$this->supplierId, $this->unitId, 'R' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function ensureChart(): void
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$this->supplierId]) === 0) {
            $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        }
    }

    private function mappedRegister(string $account = '211'): int
    {
        $this->ensureChart();
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Pokladna ' . $account, 'account_code' => $account, 'is_default' => true]);
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$registerId, $this->deviceId]);
        return $registerId;
    }

    /** @return array<string,mixed> */
    private function sale(int $registerId, float $total, string $issueDate = '2026-11-02'): array
    {
        return ['register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'sale', 'issue_date' => $issueDate,
            'description' => 'Tržba', 'total_amount' => $total];
    }

    private function bankTransaction(float $amount): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO bank_statements (file_name, file_hash, account_number, statement_date) VALUES ('eet.gpc', ?, '1000000005/0100', '2026-11-03')")
            ->execute([hash('sha256', uniqid('eetr', true))]);
        $statementId = (int) $pdo->lastInsertId();
        $this->bankStatements[] = $statementId;
        $pdo->prepare('INSERT INTO bank_transactions (statement_id, posted_at, amount) VALUES (?, ?, ?)')->execute([$statementId, '2026-11-03', $amount]);
        return (int) $pdo->lastInsertId();
    }

    private function invoice(float $total): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, 0, ?, 0, ?, 0, ?, "issued", "1", ?)'
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

    private function otherConnection(): Connection
    {
        $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $this->extraConnections[] = $other;
        return $other;
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    private function request(string $method, array $body = [], array $headers = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
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
