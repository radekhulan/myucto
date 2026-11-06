<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Eet\EetPaymentAction;
use MyInvoice\Action\Eet\EetRecordAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetDispatchService;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\EetQueueService;
use MyInvoice\Service\Eet\IdempotencyConflictException;
use MyInvoice\Service\Eet\PaymentEventService;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * Platby s evidencí EET od přijetí po potvrzení FS: platební událost, účetní úhrada,
 * EET záznam, idempotence, odesílání s lease a retry, QR záměr, opravy a ochrana
 * historie. Transport je skriptovaný (bez sítě), čas řízený MockClockem.
 */
#[Group('integration')]
final class EetPaymentFlowTest extends TestCase
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
    private string $deviceCode = '';
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

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->currencyId = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->currencyId, $vat, $this->userId));

        $pdo->prepare(
            'INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(['Synthetic EET s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eet-') . '@example.com', $this->currencyId, $vat]);
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
        $this->deviceCode = 'T' . substr(bin2hex(random_bytes(6)), 0, 10);
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Web')")
            ->execute([$this->supplierId, $unitId, $this->deviceCode]);
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
        foreach ($this->extraConnections as $extra) {
            $extra->close();
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($this->supplierId !== 0) {
            foreach ([
                'DELETE FROM eet_attempts WHERE supplier_id = ?',
                'DELETE FROM eet_records WHERE supplier_id = ?',
                'DELETE FROM payment_event_allocations WHERE supplier_id = ?',
                'DELETE FROM payment_events WHERE supplier_id = ?',
                'DELETE FROM eet_settings WHERE supplier_id = ?',
                'DELETE FROM eet_devices WHERE supplier_id = ?',
                'DELETE FROM eet_units WHERE supplier_id = ?',
                'DELETE FROM activity_log WHERE supplier_id = ?',
                'UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE supplier_id = ?',
                'DELETE FROM invoice_payments WHERE supplier_id = ?',
                'DELETE FROM invoices WHERE supplier_id = ? AND parent_invoice_id IS NOT NULL',
                'DELETE FROM invoices WHERE supplier_id = ?',
                'DELETE FROM clients WHERE supplier_id = ?',
                'DELETE FROM supplier WHERE id = ?',
            ] as $sql) {
                $pdo->prepare($sql)->execute([$this->supplierId]);
            }
        }
        $this->db->close();
    }

    public function testCashPaymentOfInvoiceCreatesOneEventOnePaymentOneRecord(): void
    {
        $invoiceId = $this->invoice(1210);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'cash-key-0001', $this->cash($invoiceId, '1210'));

        self::assertSame('required', $result['eet_decision']['decision']);
        self::assertSame('settled', $result['payment_order_status']);
        self::assertSame('not_applicable', $result['bank_verification_status']);
        self::assertSame('settled', $result['accounting_settlement_status']);
        self::assertSame('confirmed', $result['eet']['status']);
        self::assertSame('TEST-POK-0001-ff', $result['eet']['pok']);
        self::assertSame('playground', $result['eet']['environment']);
        self::assertFalse($result['idempotent_replay']);

        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('payment_event_allocations'));
        $payment = $this->row('SELECT source, amount FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);
        self::assertSame(['manual', '1210.00'], [$payment['source'], $payment['amount']]);
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));

        $record = $this->row('SELECT * FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame('2026/000001', $record['sequence_number']);
        self::assertSame('2026-11-02 10:00:00', $record['sale_at']);
        self::assertSame('2026-11-04 10:00:00', $record['deadline_at']);
        self::assertSame('1210.00', $record['total_amount']);
        self::assertSame(11, (int) $record['unit_number']);

        $calls = $this->transport->callsFor($this->deviceCode);
        self::assertCount(1, $calls);
        self::assertTrue($calls[0]['header']->firstAttempt);
        self::assertSame(121000, $calls[0]['data']->totalCents);
        self::assertSame('CZ00000019', $calls[0]['data']->eic);
    }

    public function testSameKeyReturnsOriginalAndDifferentBodyConflicts(): void
    {
        $invoiceId = $this->invoice(1210);
        $first = $this->command()->accept($this->supplierId, $this->userId, 'same-key-0001', $this->cash($invoiceId, '500'));
        $second = $this->command()->accept($this->supplierId, $this->userId, 'same-key-0001', $this->cash($invoiceId, '500.00'));

        self::assertTrue($second['idempotent_replay']);
        self::assertSame($first['payment_event_id'], $second['payment_event_id']);
        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));

        $this->expectException(IdempotencyConflictException::class);
        $this->command()->accept($this->supplierId, $this->userId, 'same-key-0001', $this->cash($invoiceId, '600'));
    }

    /**
     * Souběh: druhý požadavek se stejným klíčem nevidí nepotvrzenou událost prvního,
     * počká na unikátním indexu, dostane duplicitu, svou transakci (včetně úhrady)
     * odvolá a vrátí výsledek prvního.
     */
    public function testConcurrentSameKeyCreatesSingleEvent(): void
    {
        $invoiceId = $this->invoice(1210);
        $hash = PaymentEventService::requestHash('payments.accept', [
            'amount' => '1210.00', 'currency' => 'CZK', 'channel' => 'cash', 'payment_method' => 'cash',
            'contact_context' => 'in_person', 'business_purpose' => 'sale', 'invoice_id' => $invoiceId,
        ]);
        $other = Connection::withoutSharedTestConnection(static fn () => Bootstrap::buildContainer()->get(Connection::class));
        $this->extraConnections[] = $other;
        $otherPdo = $other->pdo();
        $otherPdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $thread = (int) $otherPdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $publicId = '7c38c5f1-22aa-49ad-9a7c-' . substr(bin2hex(random_bytes(6)), 0, 12);
        $pending = $otherPdo->query(implode('; ', [
            'START TRANSACTION',
            "INSERT INTO payment_events (public_id, supplier_id, idempotency_key, request_hash, kind, origin, channel,
                contact_context, amount, recorded_at, payment_order_status, eet_decision)
             VALUES ('{$publicId}', {$this->supplierId}, 'race-key-0001', '{$hash}', 'payment', 'invoice_payment', 'cash',
                'in_person', 1210.00, NOW(6), 'settled', 'required')",
            'DO SLEEP(2)',
            'COMMIT',
        ]));
        self::assertInstanceOf(PDOStatement::class, $pending);
        $this->waitUntilSleeping($thread);

        $result = $this->command()->accept($this->supplierId, $this->userId, 'race-key-0001', $this->cash($invoiceId, '1210'));
        while ($pending->nextRowset()) {
        }

        self::assertTrue($result['idempotent_replay']);
        self::assertSame($publicId, $result['payment_event_id']);
        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]),
            'Odvolaný souběžný požadavek nesmí nechat účetní úhradu.');
        self::assertSame(0, $this->rows('eet_records'));
    }

    public function testRetryAfterTimeoutKeepsDataAndChangesUuid(): void
    {
        $this->transport->script = [ScriptedEetTransport::result('timeout', errorMessage: 'Vypršel čas')];
        $invoiceId = $this->invoice(1210);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'retry-key-001', $this->cash($invoiceId, '1210'));
        self::assertSame('retry_wait', $result['eet']['status']);
        $next = new \DateTimeImmutable((string) $result['eet']['next_attempt_at']);
        $delay = $next->getTimestamp() - $this->clock->now()->getTimestamp();
        self::assertGreaterThanOrEqual(30, $delay);
        self::assertLessThanOrEqual(33, $delay);

        self::assertSame(0, $this->dispatch()->processDue(50), 'Před uplynutím backoffu se neodesílá.');
        $this->clock->modify('+40 seconds');
        self::assertSame(1, $this->dispatch()->processDue(50));

        $calls = $this->transport->callsFor($this->deviceCode);
        self::assertCount(2, $calls);
        self::assertEquals($calls[0]['data'], $calls[1]['data'], 'Retry musí odeslat neměnná Data.');
        self::assertNotSame($calls[0]['header']->uuid, $calls[1]['header']->uuid);
        self::assertTrue($calls[0]['header']->firstAttempt);
        self::assertFalse($calls[1]['header']->firstAttempt);

        $record = $this->row('SELECT status, pok, attempt_count FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['confirmed', 'TEST-POK-0001-ff', 2], [$record['status'], $record['pok'], (int) $record['attempt_count']]);
        $attempts = $this->db->pdo()->prepare('SELECT attempt_no, first_attempt, outcome FROM eet_attempts WHERE supplier_id = ? ORDER BY attempt_no');
        $attempts->execute([$this->supplierId]);
        self::assertEquals([[1, 1, 'timeout'], [2, 0, 'confirmed']], $attempts->fetchAll(PDO::FETCH_NUM));
    }

    public function testRejectedNeedsActionAndIsNotRetriedAutomatically(): void
    {
        $this->transport->script = [ScriptedEetTransport::result('rejected', errorCode: 4, errorMessage: 'Neplatný podpis')];
        $result = $this->command()->accept($this->supplierId, $this->userId, 'reject-key-01', $this->cash($this->invoice(100), '100'));
        self::assertSame('action_required', $result['eet']['status']);
        self::assertSame('rejected', $result['eet']['action_code']);
        self::assertSame(4, $result['eet']['last_error']['code']);
        self::assertNull($result['eet']['next_attempt_at']);

        $this->clock->modify('+2 hours');
        $this->dispatch()->processDue(50);
        self::assertCount(1, $this->transport->callsFor($this->deviceCode));

        $retried = $this->dispatch()->retryNow($this->supplierId, (int) $result['eet']['record_id']);
        self::assertSame('confirmed', $retried['status']);
        self::assertCount(2, $this->transport->callsFor($this->deviceCode));
    }

    /** Dva claimy téhož záznamu: druhý (vnořený během odesílání) neodešle nic. */
    public function testLeaseAllowsOnlyOneConcurrentSend(): void
    {
        $nested = null;
        $dispatch = $this->dispatch();
        $this->transport->script = [function () use (&$nested, $dispatch) {
            $id = (int) $this->scalar('SELECT id FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
            $nested = $dispatch->dispatchNow($id);
            $this->clock->modify('+1 second');
            self::assertSame(0, $dispatch->processDue(50));
            return ScriptedEetTransport::confirmed();
        }];
        $result = $this->command()->accept($this->supplierId, $this->userId, 'lease-key-001', $this->cash($this->invoice(100), '100'));

        self::assertIsArray($nested);
        self::assertFalse($nested['attempted']);
        self::assertSame('sending', $nested['status']);
        self::assertSame('confirmed', $result['eet']['status']);
        self::assertCount(1, $this->transport->callsFor($this->deviceCode));
    }

    public function testOverdueAfter48HoursKeepsSending(): void
    {
        $this->transport->script = array_fill(0, 3, ScriptedEetTransport::result('temporary_error', errorCode: 8, errorMessage: 'Dočasná chyba'));
        $this->command()->accept($this->supplierId, $this->userId, 'overdue-key-1', $this->cash($this->invoice(100), '100'));
        self::assertEquals(0, $this->scalar('SELECT overdue FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));

        $this->clock->modify('+49 hours');
        self::assertSame(1, $this->dispatch()->processDue(50));
        $record = $this->row('SELECT status, overdue FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertEquals(['retry_wait', 1], [$record['status'], $record['overdue']]);

        $this->clock->modify('+2 minutes');
        self::assertSame(1, $this->dispatch()->processDue(50));
        $this->clock->modify('+3 minutes');
        self::assertSame(1, $this->dispatch()->processDue(50));
        $record = $this->row('SELECT status, overdue FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertEquals(['confirmed', 1], [$record['status'], $record['overdue']], 'Pozdní potvrzení zůstává označené po lhůtě.');
        self::assertCount(4, $this->transport->callsFor($this->deviceCode));
    }

    public function testMissingCertificateWaitsInQueueAndResumes(): void
    {
        $credentials = ScriptedEetTransport::credentials(null);
        $this->container->set(EetCredentialProvider::class, $credentials);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'nocert-key-01', $this->cash($this->invoice(100), '100'));
        self::assertSame('action_required', $result['eet']['status']);
        self::assertSame('certificate_missing', $result['eet']['action_code']);
        self::assertCount(0, $this->transport->callsFor($this->deviceCode));
        self::assertSame(1, $this->container->get(EetQueueService::class)->summary($this->supplierId)['action_required_by_code']['certificate_missing']);

        $credentials->certificateId = 9;
        $this->clock->modify('+6 minutes');
        self::assertSame(1, $this->dispatch()->processDue(50));
        self::assertSame('confirmed', $this->scalar('SELECT status FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
        self::assertEquals(9, $this->scalar('SELECT certificate_id FROM eet_attempts WHERE supplier_id = ?', [$this->supplierId]));
        self::assertTrue($this->transport->callsFor($this->deviceCode)[0]['header']->firstAttempt);
    }

    public function testDispatchBlockedSendsNothing(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET dispatch_blocked = 1 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'blocked-key-1', $this->cash($this->invoice(100), '100'));
        self::assertSame('pending', $result['eet']['status']);
        $this->clock->modify('+1 hour');
        self::assertSame(0, $this->dispatch()->processDue(50));
        self::assertCount(0, $this->transport->callsFor($this->deviceCode));
    }

    public function testQrIntentUsesCurrencyAccountWhenInvoiceHasNoBankSnapshot(): void
    {
        $pdo = $this->db->pdo();
        $original = $pdo->prepare('SELECT account_number, bank_code, iban FROM currencies WHERE id = ?');
        $original->execute([$this->currencyId]);
        $before = $original->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($before);
        $pdo->prepare("UPDATE currencies SET account_number = '1000000005', bank_code = '0100' WHERE id = ?")->execute([$this->currencyId]);
        try {
            $invoiceId = $this->invoice(1210);
            $pdo->prepare('UPDATE invoices SET bank_snapshot = NULL WHERE id = ?')->execute([$invoiceId]);

            $intent = $this->command()->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);

            self::assertNotNull($intent['qr']['spd'] ?? null, 'Účet měny doplní QR i bez snapshotu dokladu.');
            self::assertStringStartsWith('SPD*1.0*', $intent['qr']['spd']);
            // Údaje pro ruční přepis jsou ze stejného zdroje jako QR.
            self::assertSame(['1000000005', '0100', '1210.00'],
                [$intent['qr']['account_number'], $intent['qr']['bank_code'], $intent['qr']['amount']]);
            self::assertStringContainsString('*ACC:CZ', $intent['qr']['spd']);
            self::assertStringContainsString('01000000001000000005', $intent['qr']['spd']);
            self::assertStringContainsString('*AM:1210.00', $intent['qr']['spd']);
            if ($intent['qr']['variable_symbol'] !== null) {
                self::assertStringContainsString('*X-VS:' . $intent['qr']['variable_symbol'], $intent['qr']['spd']);
            }
        } finally {
            $pdo->prepare('UPDATE currencies SET account_number = ?, bank_code = ?, iban = ? WHERE id = ?')
                ->execute([$before['account_number'], $before['bank_code'], $before['iban'], $this->currencyId]);
        }
    }

    public function testQrIntentConfirmRecordsEetWithoutAccountingPayment(): void
    {
        $invoiceId = $this->invoice(1210);
        $intent = $this->command()->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);
        self::assertSame('initiated', $intent['payment_order_status']);
        self::assertSame('1210.00', $intent['amount']);
        self::assertStringStartsWith('SPD*1.0*', $intent['qr']['spd']);
        self::assertStringStartsWith('data:image/png;base64,', (string) $intent['qr']['image_data_uri']);
        self::assertNull($intent['eet']);
        self::assertSame(0, $this->rows('eet_records'), 'Zobrazení QR není tržba.');

        $this->clock->modify('+2 minutes');
        $confirmed = $this->command()->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'confirm-key-01', []);
        self::assertSame('operator_confirmed', $confirmed['payment_order_status']);
        self::assertSame('pending', $confirmed['bank_verification_status']);
        self::assertSame('pending', $confirmed['accounting_settlement_status']);
        self::assertSame('confirmed', $confirmed['eet']['status']);
        self::assertSame('2026-11-02T10:02:00+01:00', $confirmed['ordered_at']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
        self::assertSame('0.00', $this->scalar('SELECT paid_total FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame('2026-11-02 10:02:00', $this->scalar('SELECT sale_at FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));

        $again = $this->command()->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'confirm-key-01', []);
        self::assertTrue($again['idempotent_replay']);
        self::assertSame(1, $this->rows('eet_records'));

        try {
            $this->command()->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'confirm-key-02', []);
            self::fail('Druhé potvrzení jiným klíčem prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame(409, $e->httpStatus);
        }
        try {
            $this->command()->cancelIntent($this->supplierId, $this->userId, $intent['payment_event_id']);
            self::fail('Zrušení potvrzeného záměru prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame('eet_state_conflict', $e->errorCode);
            self::assertSame('failed_payment', $e->extra['correction']);
        }
        try {
            $this->command()->accept($this->supplierId, $this->userId, 'after-qr-key1', $this->cash($invoiceId, '1210'));
            self::fail('Potvrzený QR příkaz se neodečetl ze zbývajícího dluhu.');
        } catch (EetCommandException $e) {
            self::assertSame('0.00', $e->extra['remaining']);
        }
    }

    public function testInitiatedIntentCanBeCancelled(): void
    {
        $intent = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-01', ['invoice_id' => $this->invoice(500), 'amount' => '200']);
        $cancelled = $this->command()->cancelIntent($this->supplierId, $this->userId, $intent['payment_event_id']);
        self::assertSame('cancelled', $cancelled['payment_order_status']);
        self::assertSame(0, $this->rows('eet_records'));
        $this->expectException(EetCommandException::class);
        $this->command()->confirmIntent($this->supplierId, $this->userId, $intent['payment_event_id'], 'confirm-key-03', []);
    }

    public function testNewQrIntentCancelsPreviousInitiatedIntentOfInvoice(): void
    {
        $invoiceId = $this->invoice(500);
        $first = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-11', ['invoice_id' => $invoiceId]);
        self::assertSame('500.00', $first['amount']);

        $second = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-12', ['invoice_id' => $invoiceId]);
        self::assertSame('500.00', $second['amount'], 'Nepotvrzený záměr nesmí blokovat nový QR na celou částku.');
        self::assertSame('initiated', $second['payment_order_status']);
        self::assertSame('cancelled', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$first['payment_event_id']]));

        $replay = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-11', ['invoice_id' => $invoiceId]);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame($first['payment_event_id'], $replay['payment_event_id']);
        self::assertSame('initiated', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$second['payment_event_id']]),
            'Opakování starého klíče nesmí zrušit aktuální záměr.');
        self::assertSame(0, $this->rows('eet_records'));
    }

    public function testChangedAmountReplacesInitiatedIntent(): void
    {
        $invoiceId = $this->invoice(500);
        $first = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-21', ['invoice_id' => $invoiceId]);
        $second = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-22', ['invoice_id' => $invoiceId, 'amount' => '120']);
        self::assertSame('120.00', $second['amount']);
        self::assertSame('initiated', $second['payment_order_status']);
        self::assertSame('cancelled', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$first['payment_event_id']]));
        self::assertSame(1, (int) $this->scalar(
            "SELECT COUNT(*) FROM payment_events WHERE invoice_id = ? AND payment_order_status = 'initiated'", [$invoiceId]));
    }

    public function testNewQrIntentKeepsConfirmedIntent(): void
    {
        $invoiceId = $this->invoice(500);
        $confirmedIntent = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-31', ['invoice_id' => $invoiceId, 'amount' => '200']);
        $this->command()->confirmIntent($this->supplierId, $this->userId, $confirmedIntent['payment_event_id'], 'confirm-key-31', []);

        $next = $this->command()->createIntent($this->supplierId, $this->userId, 'intent-key-32', ['invoice_id' => $invoiceId]);
        self::assertSame('300.00', $next['amount'], 'Potvrzený příkaz dál blokuje svou část dluhu.');
        self::assertSame('operator_confirmed', $this->scalar('SELECT payment_order_status FROM payment_events WHERE public_id = ?', [$confirmedIntent['payment_event_id']]));
        self::assertSame(1, $this->rows('eet_records'));
    }

    public function testCorrectionOfPartOfSaleAndLimit(): void
    {
        $sale = $this->command()->accept($this->supplierId, $this->userId, 'corr-sale-001', $this->cash($this->invoice(1210), '1210'));
        $recordId = (int) $sale['eet']['record_id'];
        $corrections = $this->container->get(EetCorrectionService::class);

        $preview = $corrections->preview($this->supplierId, $recordId, ['amount' => '210', 'reason' => 'refund']);
        self::assertTrue($preview['allowed']);
        self::assertSame('-210.00', $preview['proposed']['total_amount']);

        $this->clock->modify('+1 hour');
        $created = $corrections->create($this->supplierId, $this->userId, $recordId, 'corr-key-0001', ['amount' => '210', 'reason' => 'refund']);
        self::assertSame('-210.00', $created['correction']['total_amount']);
        self::assertSame('correction', $created['correction']['kind']);
        self::assertSame('2026/000002', $created['correction']['sequence_number']);
        self::assertSame('2026-11-02T11:00:00+01:00', $created['correction']['sale_at']);
        self::assertSame($recordId, $created['correction']['corrects_record_id']);
        self::assertSame('refund', $created['kind']);
        self::assertSame('-210.00', $created['amount']);
        self::assertSame('pending', $created['accounting_settlement_status']);
        self::assertSame('1000.00', $created['original_remaining']);
        self::assertSame('confirmed', $created['correction']['status']);

        $replay = $corrections->create($this->supplierId, $this->userId, $recordId, 'corr-key-0001', ['amount' => '210.00', 'reason' => 'refund']);
        self::assertTrue($replay['idempotent_replay']);
        self::assertSame(2, $this->rows('eet_records'));

        try {
            $corrections->create($this->supplierId, $this->userId, $recordId, 'corr-key-0002', ['amount' => '1000.01', 'reason' => 'refund']);
            self::fail('Oprava nad neopravenou částku prošla.');
        } catch (EetCommandException $e) {
            self::assertSame('1000.00', $e->extra['remaining']);
        }
        self::assertSame(2, $this->rows('eet_records'));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM payment_events WHERE supplier_id = ? AND idempotency_key = 'corr-key-0002'", [$this->supplierId]));
    }

    /** Úhradu zachycenou jako tržbu nejde smazat ani jednotlivě, ani hromadně. */
    public function testPaymentWithEetRecordCannotBeDeleted(): void
    {
        $invoiceId = $this->invoice(1210);
        $this->command()->accept($this->supplierId, $this->userId, 'delete-key-01', $this->cash($invoiceId, '1210'));
        $paymentId = (int) $this->scalar('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);
        $payments = $this->container->get(InvoicePaymentService::class);

        foreach ([fn () => $payments->deletePayment($paymentId), fn () => $payments->deleteAllForInvoice($invoiceId)] as $delete) {
            $message = null;
            try {
                $delete();
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            self::assertNotNull($message, 'Úhrada s evidencí EET byla smazána.');
            self::assertStringContainsString('evidenci EET', $message);
            self::assertStringContainsString('opravu EET', $message);
        }
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
    }

    /** Úhrada bez EET záznamu (vzdálený převod) se maže dál běžně. */
    public function testRemoteTransferIsNotRecordedAndStaysDeletable(): void
    {
        $invoiceId = $this->invoice(1210);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'remote-key-01', [
            'invoice_id' => $invoiceId, 'amount' => '1210', 'channel' => 'bank_transfer', 'contact_context' => 'remote',
            'eet_required' => true,
        ]);
        self::assertSame('not_subject', $result['eet_decision']['decision']);
        self::assertNull($result['eet']);
        self::assertSame(0, $this->rows('eet_records'));
        self::assertSame('manual', $this->scalar('SELECT source FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
        self::assertCount(0, $this->transport->calls);

        $paymentId = (int) $this->scalar('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);
        $this->container->get(InvoicePaymentService::class)->deletePayment($paymentId);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]));
    }

    public function testCardWithoutContactContextIsRejectedWithoutWrites(): void
    {
        try {
            $this->command()->accept($this->supplierId, $this->userId, 'card-key-0001', [
                'invoice_id' => $this->invoice(100), 'amount' => '100', 'channel' => 'card_terminal',
            ]);
            self::fail('Bezhotovostní platba bez kontextu prošla.');
        } catch (EetCommandException $e) {
            self::assertSame(['eet_validation', 422, 'contact_context_required'], [$e->errorCode, $e->httpStatus, $e->extra['reason_code']]);
        }
        self::assertSame(0, $this->rows('payment_events'));
    }

    /** Zakázková záloha a doplatek jsou dvě běžné tržby; kreditní pole zůstávají prázdná. */
    public function testProformaAndFinalPaymentAreTwoSales(): void
    {
        $proforma = $this->invoice(5000, 'proforma');
        $this->command()->accept($this->supplierId, $this->userId, 'deposit-key-1', $this->cash($proforma, '5000') + ['business_purpose' => 'order_deposit']);
        $final = $this->invoice(8000, 'invoice', $proforma, 5000);
        $this->command()->accept($this->supplierId, $this->userId, 'final-key-001', $this->cash($final, '3000'));

        $stmt = $this->db->pdo()->prepare('SELECT total_amount, credit_topup_amount, credit_draw_amount FROM eet_records WHERE supplier_id = ? ORDER BY id');
        $stmt->execute([$this->supplierId]);
        self::assertSame([['5000.00', null, null], ['3000.00', null, null]], $stmt->fetchAll(PDO::FETCH_NUM));
        $calls = $this->transport->callsFor($this->deviceCode);
        self::assertNull($calls[0]['data']->creditTopUpCents);
        self::assertNull($calls[1]['data']->creditDrawCents);
    }

    public function testHttpContract(): void
    {
        $action = $this->container->get(EetPaymentAction::class);
        $invoiceId = $this->invoice(1210);
        $missing = $action->accept($this->request('POST', ['invoice_id' => $invoiceId, 'amount' => '100', 'channel' => 'cash']), new Psr7Response());
        self::assertSame(400, $missing->getStatusCode());
        self::assertSame('idempotency_key_required', $this->json($missing)['error']['code']);
        self::assertSame(0, $this->rows('payment_events'));

        $ok = $action->accept($this->request('POST', ['invoice_id' => $invoiceId, 'amount' => '100', 'channel' => 'cash'], ['Idempotency-Key' => 'http-key-0001']), new Psr7Response());
        self::assertSame(200, $ok->getStatusCode());
        $body = $this->json($ok);
        self::assertSame('100.00', $body['amount']);

        $conflict = $action->accept($this->request('POST', ['invoice_id' => $invoiceId, 'amount' => '101', 'channel' => 'cash'], ['Idempotency-Key' => 'http-key-0001']), new Psr7Response());
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('idempotency_conflict', $this->json($conflict)['error']['code']);

        $foreign = $action->accept($this->request('POST', ['invoice_id' => 999999999, 'amount' => '1', 'channel' => 'cash'], ['Idempotency-Key' => 'http-key-0002']), new Psr7Response());
        self::assertSame(422, $foreign->getStatusCode());

        $records = $this->container->get(EetRecordAction::class);
        $this->command()->accept($this->supplierId, $this->userId, 'http-key-0003', $this->cash($invoiceId, '50'));
        $page = $this->json($records->list($this->request('GET', [], [], ['limit' => '1']), new Psr7Response()));
        self::assertCount(1, $page['items']);
        self::assertNotNull($page['next_cursor']);
        $page2 = $this->json($records->list($this->request('GET', [], [], ['limit' => '1', 'cursor' => $page['next_cursor']]), new Psr7Response()));
        self::assertCount(1, $page2['items']);
        self::assertNull($page2['next_cursor']);
        self::assertNotSame($page['items'][0]['record_id'], $page2['items'][0]['record_id']);

        $recordId = (int) $page['items'][0]['record_id'];
        $bearer = $this->request('GET')->withAttribute(AuthMiddleware::ATTR_METHOD, 'bearer')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'superadmin', 'is_superadmin' => 1]);
        $detail = $this->json($records->detail($bearer, new Psr7Response(), ['id' => (string) $recordId]));
        self::assertNotEmpty($detail['attempts']);
        self::assertArrayNotHasKey('request_xml', $detail['attempts'][0], 'Bearer token nesmí dostat XML pokusu.');
        $readonly = $this->json($records->detail($this->request('GET', [], [], [], 'readonly'), new Psr7Response(), ['id' => (string) $recordId]));
        self::assertArrayNotHasKey('request_xml', $readonly['attempts'][0]);

        $csv = $records->export($this->request('GET', [], [], ['from' => '2026-11-01', 'to' => '2026-11-30']), new Psr7Response());
        self::assertSame(200, $csv->getStatusCode());
        $content = (string) $csv->getBody();
        self::assertStringContainsString('Pořadové číslo', $content);
        self::assertSame(2, substr_count(trim($content), "\n"), 'Hlavička a dva záznamy.');

        $invoice = $this->json($records->invoice($this->request('GET'), new Psr7Response(), ['id' => (string) $invoiceId]));
        self::assertCount(2, $invoice['items']);
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function command(): EetPaymentCommand
    {
        return $this->container->get(EetPaymentCommand::class);
    }

    private function dispatch(): EetDispatchService
    {
        return $this->container->get(EetDispatchService::class);
    }

    /** @return array<string,mixed> */
    private function cash(int $invoiceId, string $amount): array
    {
        return ['invoice_id' => $invoiceId, 'amount' => $amount, 'channel' => 'cash', 'contact_context' => 'in_person'];
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
    private function request(string $method, array $body = [], array $headers = [], array $query = [], string $role = 'admin'): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/eet')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withQueryParams($query);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $body !== [] ? $request->withParsedBody($body) : $request;
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
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

    private function waitUntilSleeping(int $thread): void
    {
        $probe = $this->db->pdo()->prepare('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = ?');
        $deadline = microtime(true) + 5;
        do {
            $probe->execute([$thread]);
            if ($probe->fetchColumn() === 'User sleep') {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::fail('Souběžná transakce nedošla k čekání.');
    }
}
