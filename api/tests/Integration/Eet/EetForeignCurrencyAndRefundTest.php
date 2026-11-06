<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Eet\EetRecordAction;
use MyInvoice\Action\Invoice\MarkPaidAction;
use MyInvoice\Action\Invoice\UnmarkPaidAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashException;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\PaymentExchangeRate;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
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
 * Tržba v cizí měně (částka EET vždy v CZK, kurz úhrady) a vrácení peněz k dobropisu
 * jako záporná tržba (EET-08). Skutečné služby a akce, kurz ČNB ze stubu, čas z MockClocku.
 */
#[Group('integration')]
final class EetForeignCurrencyAndRefundTest extends TestCase
{
    private const DAY = '2026-11-02';

    private Container $container;
    private Connection $db;
    private MockClock $clock;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $czk = 0;
    private int $eur = 0;
    private int $userId = 0;
    private int $deviceId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->clock = new MockClock(new \DateTimeImmutable(self::DAY . ' 10:00:00', new \DateTimeZone('Europe/Prague')));
        $this->container->set(EetTransportInterface::class, new ScriptedEetTransport());
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, $this->clock);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->czk, $vat, $this->userId));

        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode, allow_refund_invoices)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tax_evidence', 1)"
        )->execute(['Synthetic EET měna s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eetfx-') . '@example.com', $this->czk, $vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en) VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro')")
            ->execute([$this->supplierId]);
        $this->eur = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->czk]);
        $this->clientId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 12, 'Provozovna', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Web')")
            ->execute([$this->supplierId, $unitId, 'F' . substr(bin2hex(random_bytes(6)), 0, 10)]);
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
            self::deleteRows($pdo, 'supplier', $this->supplierId, 0);
        }
        $this->db->close();
    }

    // ── cizí měna ─────────────────────────────────────────────────────────────

    /**
     * Hotovost 180,98 EUR při kurzu 24,25: přesný součin 4 388,765 dá 4 388,77 Kč
     * (round() nad plovoucími korunami by dal 4 388,76). Kurz je tentýž, jakým
     * účetnictví oceňuje úhradu ke dni platby.
     */
    public function testForeignCashPaymentIsRecordedInCzkAtThePaymentRate(): void
    {
        $this->stubRates(['EUR' => [self::DAY => 24.25]]);
        $invoiceId = $this->invoice(180.98, 'invoice', null, $this->eur);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'fx-cash-0001', [
            'invoice_id' => $invoiceId, 'amount' => '180.98', 'currency' => 'EUR', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);

        self::assertSame(['180.98', 'EUR', '4388.77', '24.25'], [$result['amount'], $result['currency'], $result['amount_czk'], $result['exchange_rate']]);
        self::assertSame('4388.77', $this->scalar('SELECT total_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
        $event = $this->row('SELECT amount, currency, original_currency, original_amount, exchange_rate, exchange_rate_source FROM payment_events WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['4388.77', 'CZK', 'EUR', '180.98', '24.25000000', 'cnb:' . self::DAY], array_values($event));
        self::assertSame('180.98', $this->scalar('SELECT amount FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]), 'Úhrada faktury zůstává v měně faktury.');
        self::assertSame('180.98', $this->scalar('SELECT amount FROM payment_event_allocations WHERE supplier_id = ?', [$this->supplierId]));

        $accountingRate = $this->container->get(BankPostingService::class)->czkRateFor($this->supplierId, 'EUR', self::DAY);
        self::assertSame(24.25, $accountingRate);
        self::assertSame(438877, PaymentExchangeRate::toCzkCents(18098, (float) $accountingRate));
    }

    /** Chybí kurz pro den platby: EET odmítne stejně jako zaúčtování, jiný kurz se tiše nepoužije. */
    public function testMissingRateRejectsPaymentLikeAccounting(): void
    {
        $this->stubRates(['EUR' => []]);
        $invoiceId = $this->invoice(100, 'invoice', null, $this->eur);
        try {
            $this->command()->accept($this->supplierId, $this->userId, 'fx-cash-0002', [
                'invoice_id' => $invoiceId, 'amount' => '100', 'currency' => 'EUR', 'channel' => 'cash', 'contact_context' => 'in_person',
            ]);
            self::fail('Platba v EUR bez kurzu prošla.');
        } catch (EetCommandException $e) {
            self::assertSame(['eet_fx_rate_unavailable', 422], [$e->errorCode, $e->httpStatus]);
        }
        self::assertNull($this->container->get(BankPostingService::class)->czkRateFor($this->supplierId, 'EUR', self::DAY),
            'Účetnictví kurz pro tentýž den také nemá.');
        self::assertSame(0, $this->rows('payment_events'));
        self::assertSame(0, $this->rows('invoice_payments'));
    }

    /**
     * Částečné vratky v EUR se přepočtou kurzem původní tržby kumulativně: 2 × 0,02 EUR
     * při kurzu 24,25 dá −0,49 a −0,48 Kč (součet = přepočet 0,04 EUR), vratka zbytku
     * dorovná přesně celou tržbu.
     */
    public function testPartialForeignRefundsUseOriginalRateAndNeverOvershoot(): void
    {
        $this->stubRates(['EUR' => [self::DAY => 24.25, '2026-11-03' => 30.0]]);
        $invoiceId = $this->invoice(180.98, 'invoice', null, $this->eur);
        $this->command()->accept($this->supplierId, $this->userId, 'fx-cash-0003', [
            'invoice_id' => $invoiceId, 'amount' => '180.98', 'currency' => 'EUR', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        $recordId = (int) $this->scalar("SELECT id FROM eet_records WHERE supplier_id = ? AND kind = 'sale'", [$this->supplierId]);
        $this->clock->modify('+1 day');
        $corrections = $this->container->get(EetCorrectionService::class);
        $first = $corrections->create($this->supplierId, $this->userId, $recordId, 'fx-refund-01', ['original_amount' => '0.02', 'reason' => 'refund']);
        $second = $corrections->create($this->supplierId, $this->userId, $recordId, 'fx-refund-02', ['original_amount' => '0.02', 'reason' => 'refund']);
        self::assertSame(['-0.49', '-0.48'], [$first['correction']['total_amount'], $second['correction']['total_amount']]);
        self::assertSame(['-0.02', 'EUR', '24.25'], [$second['amount'], $second['currency'], $second['exchange_rate']]);

        try {
            $corrections->create($this->supplierId, $this->userId, $recordId, 'fx-refund-03', ['original_amount' => '180.95', 'reason' => 'refund']);
            self::fail('Vratka nad zbývající cizí částku prošla.');
        } catch (EetCommandException $e) {
            self::assertSame('180.94', $e->extra['remaining_original'] ?? null);
        }
        $rest = $corrections->create($this->supplierId, $this->userId, $recordId, 'fx-refund-04', ['original_amount' => '180.94', 'reason' => 'refund']);
        self::assertSame('0.00', $rest['original_remaining']);
        self::assertSame('0.00', $this->scalar("SELECT SUM(total_amount) FROM eet_records WHERE supplier_id = ?", [$this->supplierId]),
            'Součet tržby a oprav je přesně nula.');
    }

    /** QR na místě zůstává jen pro faktury v Kč (SPAYD), faktura v EUR dostane srozumitelné odmítnutí. */
    public function testQrInPersonForForeignInvoiceIsRejectedClearly(): void
    {
        $this->stubRates(['EUR' => [self::DAY => 24.25]]);
        $invoiceId = $this->invoice(100, 'invoice', null, $this->eur);
        try {
            $this->command()->createIntent($this->supplierId, $this->userId, null, ['invoice_id' => $invoiceId]);
            self::fail('QR záměr pro EUR fakturu prošel.');
        } catch (EetCommandException $e) {
            self::assertSame(['eet_unsupported_scenario', 'qr_foreign_currency'], [$e->errorCode, $e->extra['reason_code'] ?? null]);
            self::assertStringContainsString('hotovosti nebo kartou', $e->getMessage());
        }
    }

    // ── vrácení peněz k dobropisu ─────────────────────────────────────────────

    public function testCashRefundVoucherForCreditNoteCreatesNegativeCorrectionOnce(): void
    {
        $this->stubRates([]);
        [$invoiceId, $creditNoteId] = $this->paidInvoiceWithCreditNote(1210, 1210);
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $this->clock->modify('+1 hour');
        $created = $service->create($this->supplierId, $this->refundVoucher($registerId, $creditNoteId, 1210), $this->userId);
        self::assertSame('posted', $created['status']);
        self::assertSame('1210.00', $created['eet_refund']['refunded_czk'] ?? null);

        $corrections = $this->corrections();
        self::assertSame([['correction', '-1210.00', 'refund']], array_map(static fn (array $r): array => [$r['kind'], $r['total_amount'], $r['correction_reason']], $corrections));
        $allocation = $this->row("SELECT a.invoice_id, a.cash_document_id, e.invoice_id AS event_invoice FROM payment_event_allocations a JOIN payment_events e ON e.id = a.payment_event_id WHERE a.supplier_id = ? AND e.kind = 'refund'", [$this->supplierId]);
        self::assertSame([$creditNoteId, (int) $created['id'], $invoiceId], [(int) $allocation['invoice_id'], (int) $allocation['cash_document_id'], (int) $allocation['event_invoice']]);

        // Opakované zaúčtování (dvojklik) i opakované volání EET druhou opravu nezaloží.
        $service->post($this->supplierId, (int) $created['id'], $this->userId);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        $replay = $this->container->get(EetCorrectionService::class)->refundInvoiceSales(
            $this->supplierId, $this->userId, $invoiceId, 'CZK', 121000, 'refund-cash:' . $created['id'], null, null, []);
        $pdo->commit();
        self::assertTrue($replay['replayed']);
        self::assertCount(1, $this->corrections());

        // Box EET dobropisu ukáže vrácení i zbývající evidovanou část faktury.
        $box = $this->json($this->container->get(EetRecordAction::class)->invoice($this->request('GET'), new Psr7Response(), ['id' => (string) $creditNoteId]));
        self::assertSame(['refund'], array_column($box['items'], 'kind'));
        self::assertSame('0.00', $box['refund']['evidenced']['remaining_czk']);
    }

    /** Faktura zaplacená převodem z domova nemá evidovanou tržbu: vrácení k dobropisu se do EET nehlásí. */
    public function testCreditNoteForRemotelyPaidInvoiceRecordsNothing(): void
    {
        $this->stubRates([]);
        $invoiceId = $this->invoice(1210);
        $remote = $this->markPaid($invoiceId, ['eet_context' => 'remote_transfer', 'paid_at' => self::DAY]);
        self::assertSame(200, $remote->getStatusCode(), (string) $remote->getBody());
        $creditNoteId = $this->invoice(-1210, 'credit_note', $invoiceId);
        $registerId = $this->mappedRegister();
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, $this->refundVoucher($registerId, $creditNoteId, 1210), $this->userId);
        self::assertSame('posted', $created['status']);
        self::assertSame([], $created['eet_refund']['corrections'] ?? []);
        self::assertSame(0, $this->rows('eet_records'));
        self::assertSame(0, $this->rows('payment_events'));
    }

    /** Vrácení nad evidovanou část: zaeviduje se jen evidovaná část, odpověď nese varování. */
    public function testRefundAboveEvidencedPartRecordsOnlyEvidencedPartWithWarning(): void
    {
        $this->stubRates([]);
        $invoiceId = $this->invoice(1210);
        $this->command()->accept($this->supplierId, $this->userId, 'part-cash-01', [
            'invoice_id' => $invoiceId, 'amount' => '500', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        $this->markPaid($invoiceId, ['eet_context' => 'remote_transfer', 'paid_at' => self::DAY]);
        $creditNoteId = $this->invoice(-1210, 'credit_note', $invoiceId);

        $missing = $this->markPaid($creditNoteId, []);
        self::assertSame(422, $missing->getStatusCode());
        self::assertSame('eet_context_required', $this->json($missing)['error']['code']);

        $response = $this->markPaid($creditNoteId, ['channel' => 'cash', 'contact_context' => 'in_person']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame('paid', $json['status']);
        self::assertSame(['500.00', '500.00', '710.00'], [$json['eet']['refunded_czk'], $json['eet']['refunded'], $json['eet']['not_recorded']]);
        self::assertStringContainsString('jen 500.00', (string) $json['eet']['warning']);
        self::assertSame(['-500.00'], array_column($this->corrections(), 'total_amount'));

        $unmark = $this->container->get(UnmarkPaidAction::class)($this->request('POST'), new Psr7Response(), ['id' => (string) $creditNoteId]);
        self::assertSame(409, $unmark->getStatusCode());
        self::assertSame('eet_history_exists', $this->json($unmark)['error']['code']);
    }

    /** Více tržeb faktury: oprava se rozpouští od nejnovější tržby. */
    public function testRefundSpansSalesFromNewest(): void
    {
        $this->stubRates([]);
        $invoiceId = $this->invoice(700);
        $command = $this->command();
        $command->accept($this->supplierId, $this->userId, 'multi-cash-01', ['invoice_id' => $invoiceId, 'amount' => '300', 'channel' => 'cash', 'contact_context' => 'in_person']);
        $this->clock->modify('+1 hour');
        $command->accept($this->supplierId, $this->userId, 'multi-cash-02', ['invoice_id' => $invoiceId, 'amount' => '400', 'channel' => 'cash', 'contact_context' => 'in_person']);
        $creditNoteId = $this->invoice(-500, 'credit_note', $invoiceId);
        $this->clock->modify('+1 hour');
        $response = $this->markPaid($creditNoteId, ['channel' => 'card_terminal', 'contact_context' => 'in_person']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNull($this->json($response)['eet']['warning']);

        $stmt = $this->db->pdo()->prepare(
            "SELECT s.total_amount, c.total_amount FROM eet_records c JOIN eet_records s ON s.id = c.corrects_record_id
              WHERE c.supplier_id = ? AND c.kind = 'correction' ORDER BY c.id"
        );
        $stmt->execute([$this->supplierId]);
        self::assertSame([['400.00', '-400.00'], ['300.00', '-100.00']], $stmt->fetchAll(PDO::FETCH_NUM));
    }

    /** Vrácení převodem na účet je bezkontaktní: dobropis se označí jako dřív, bez EET. */
    public function testRemoteRefundKeepsLegacyPath(): void
    {
        $this->stubRates([]);
        [, $creditNoteId] = $this->paidInvoiceWithCreditNote(1210, 1210);
        $response = $this->markPaid($creditNoteId, ['eet_context' => 'remote_transfer', 'paid_at' => '2026-10-30']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame(['paid', '2026-10-30'], [$json['status'], $json['paid_at']]);
        self::assertSame('not_subject', $json['eet_decision']['decision']);
        self::assertSame([], $this->corrections());
    }

    /** Storno výdajového dokladu k dobropisu: peníze jsou zpět v pokladně → nová kladná tržba. */
    public function testRefundVoucherReversalRecordsPositiveSale(): void
    {
        $this->stubRates([]);
        [$invoiceId, $creditNoteId] = $this->paidInvoiceWithCreditNote(1210, 1210);
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $created = $service->create($this->supplierId, $this->refundVoucher($registerId, $creditNoteId, 1210), $this->userId);
        $this->clock->modify('+1 hour');
        $service->reverse($this->supplierId, (int) $created['id'], ['reason' => 'Vráceno omylem'], $this->userId);

        $stmt = $this->db->pdo()->prepare('SELECT r.kind, r.total_amount, r.policy_reason, e.invoice_id FROM eet_records r JOIN payment_events e ON e.id = r.payment_event_id WHERE r.supplier_id = ? ORDER BY r.id');
        $stmt->execute([$this->supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        self::assertSame(['sale', '1210.00', 'refund_reversal', (string) $invoiceId], array_map('strval', $rows[2]));
        self::assertCount(3, $rows);
        self::assertSame('issued', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$creditNoteId]));
    }

    /**
     * Storno vrácení k tržbě v pověření: kladná tržba vrací opravy, takže nese jejich
     * pověření, i když jde přes zařízení pokladny bez pověření.
     */
    public function testRefundVoucherReversalKeepsDelegationOfTheCorrections(): void
    {
        $this->stubRates([]);
        $delegated = $this->delegatedDevice();
        $invoiceId = $this->invoice(1210);
        $this->command()->accept($this->supplierId, $this->userId, 'pov-paid-01', [
            'invoice_id' => $invoiceId, 'amount' => '1210', 'channel' => 'cash', 'contact_context' => 'in_person', 'device_id' => $delegated,
        ]);
        $creditNoteId = $this->invoice(-1210, 'credit_note', $invoiceId);
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $created = $service->create($this->supplierId, $this->refundVoucher($registerId, $creditNoteId, 1210), $this->userId);
        $this->clock->modify('+1 hour');
        $service->reverse($this->supplierId, (int) $created['id'], ['reason' => 'Vráceno omylem'], $this->userId);

        $rows = $this->db->pdo()->prepare(
            'SELECT kind, total_amount, delegating_eic, device_id, policy_reason FROM eet_records WHERE supplier_id = ? ORDER BY id'
        );
        $rows->execute([$this->supplierId]);
        $records = $rows->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(3, $records);
        self::assertSame(['correction', '-1210.00', 'CZ683555118'], [$records[1]['kind'], $records[1]['total_amount'], $records[1]['delegating_eic']]);
        self::assertSame(['sale', '1210.00', 'CZ683555118', $this->deviceId, 'refund_reversal'], [
            $records[2]['kind'], $records[2]['total_amount'], $records[2]['delegating_eic'], (int) $records[2]['device_id'], $records[2]['policy_reason'],
        ]);
    }

    /** Vrácení opravilo tržby různého pověření: jedna kladná tržba je nevrátí, storno se odmítne. */
    public function testRefundVoucherReversalWithMixedDelegationIsRefused(): void
    {
        $this->stubRates([]);
        $delegated = $this->delegatedDevice();
        $invoiceId = $this->invoice(1000);
        $this->command()->accept($this->supplierId, $this->userId, 'pov-mix-01', [
            'invoice_id' => $invoiceId, 'amount' => '600', 'channel' => 'cash', 'contact_context' => 'in_person', 'device_id' => $delegated,
        ]);
        $this->clock->modify('+1 minute');
        $this->command()->accept($this->supplierId, $this->userId, 'pov-mix-02', [
            'invoice_id' => $invoiceId, 'amount' => '400', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        $creditNoteId = $this->invoice(-1000, 'credit_note', $invoiceId);
        $registerId = $this->mappedRegister();
        $service = $this->container->get(CashDocumentService::class);
        $created = $service->create($this->supplierId, $this->refundVoucher($registerId, $creditNoteId, 1000), $this->userId);
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM eet_records WHERE supplier_id = ? AND kind = 'correction'", [$this->supplierId]));

        try {
            $service->reverse($this->supplierId, (int) $created['id'], ['reason' => 'Vráceno omylem'], $this->userId);
            self::fail('Storno oprav různého pověření prošlo jednou tržbou.');
        } catch (CashException $e) {
            self::assertSame('eet_device_id', $e->extra['field'] ?? null);
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM eet_records WHERE supplier_id = ? AND policy_reason = 'refund_reversal'", [$this->supplierId]));
    }

    /**
     * Příjmový doklad pokladny jde na zařízení pokladny bez pověření; zařízení v pověření
     * přiřazené pokladně mimo validaci se automaticky nevybere. Chyba zařízení při
     * zaúčtování dokladu nese pole volajícího (`eet_device_id`).
     */
    public function testCashIncomeNeverPicksDelegatedDeviceImplicitly(): void
    {
        $this->stubRates([]);
        $delegated = $this->delegatedDevice();
        $registerId = $this->mappedRegister();
        // Stav mimo validaci: zařízení v pověření je přiřazené pokladně a má nižší id než zařízení pokladny.
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$registerId, $delegated]);
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = NULL WHERE id = ?')->execute([$this->deviceId]);

        $service = $this->container->get(CashDocumentService::class);
        $service->create($this->supplierId, $this->incomeVoucher($registerId, 300), $this->userId);
        $record = $this->row('SELECT device_id, delegating_eic FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame([$this->deviceId, null], [(int) $record['device_id'], $record['delegating_eic']]);

        // Výslovně zvolené zařízení v pověření s EIČ shodným s firmou: chyba míří na eet_device_id.
        $this->db->pdo()->prepare("UPDATE eet_settings SET eic = 'CZ683555118' WHERE supplier_id = ?")->execute([$this->supplierId]);
        try {
            $service->create($this->supplierId, $this->incomeVoucher($registerId, 200) + ['eet_device_id' => $delegated], $this->userId);
            self::fail('Tržba s pověřujícím shodným s poplatníkem prošla.');
        } catch (CashException $e) {
            self::assertSame('eet_device_id', $e->extra['field'] ?? null);
        }
    }

    /** Firma bez aktivní EET: vrácení k dobropisu beze změny chování. */
    public function testCompanyWithoutEetKeepsCreditNoteRefundUnchanged(): void
    {
        $this->stubRates([]);
        $this->db->pdo()->prepare('UPDATE eet_settings SET enabled = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $invoiceId = $this->invoice(1210);
        $creditNoteId = $this->invoice(-1210, 'credit_note', $invoiceId);
        $response = $this->markPaid($creditNoteId, ['paid_at' => '2026-10-30']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame(['paid', '2026-10-30'], [$json['status'], $json['paid_at']]);
        self::assertArrayNotHasKey('eet', $json);
        self::assertArrayNotHasKey('eet_decision', $json);
        self::assertSame(0, $this->rows('payment_events'));
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @param array<string,array<string,float>> $rates měna => den => kurz */
    private function stubRates(array $rates): void
    {
        $stub = $this->createStub(CnbExchangeRateClient::class);
        $stub->method('getRate')->willReturnCallback(static function (string $code, \DateTimeImmutable $day) use ($rates): ?array {
            $rate = $rates[strtoupper($code)][$day->format('Y-m-d')] ?? null;
            return $rate === null ? null : ['rate' => $rate, 'rate_date' => $day->format('Y-m-d'), 'fallback_used' => false, 'source' => 'cache'];
        });
        $this->container->set(CnbExchangeRateClient::class, $stub);
    }

    private function command(): EetPaymentCommand
    {
        return $this->container->get(EetPaymentCommand::class);
    }

    /** @return array{0:int, 1:int} faktura zaplacená hotově (EET) a dobropis k ní */
    private function paidInvoiceWithCreditNote(float $total, float $credit): array
    {
        $invoiceId = $this->invoice($total);
        $this->command()->accept($this->supplierId, $this->userId, 'paid-cash-' . $invoiceId, [
            'invoice_id' => $invoiceId, 'amount' => number_format($total, 2, '.', ''), 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        return [$invoiceId, $this->invoice(-$credit, 'credit_note', $invoiceId)];
    }

    /** @return array<string,mixed> */
    private function refundVoucher(int $registerId, int $creditNoteId, float $amount): array
    {
        return ['register_id' => $registerId, 'doc_type' => 'out', 'purpose' => 'invoice_payment', 'invoice_id' => $creditNoteId,
            'issue_date' => self::DAY, 'description' => 'Vrácení k dobropisu', 'total_amount' => $amount];
    }

    /** @return array<string,mixed> */
    private function incomeVoucher(int $registerId, float $amount): array
    {
        return ['register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'sale',
            'issue_date' => self::DAY, 'description' => 'Prodej za hotové', 'total_amount' => $amount];
    }

    /** Zařízení pověřené poplatníkem CZ683555118 (situace 4), bez pokladny a ne výchozí. */
    private function delegatedDevice(): int
    {
        $unitId = (int) $this->scalar('SELECT unit_id FROM eet_devices WHERE id = ?', [$this->deviceId]);
        $this->db->pdo()->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name, delegation, delegating_eic) VALUES (?, ?, ?, 'Pověření', 'single', 'CZ683555118')")
            ->execute([$this->supplierId, $unitId, 'D' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    private function corrections(): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT kind, total_amount, correction_reason, corrects_record_id FROM eet_records WHERE supplier_id = ? AND kind = 'correction' ORDER BY id");
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $body */
    private function markPaid(int $invoiceId, array $body): ResponseInterface
    {
        return $this->container->get(MarkPaidAction::class)($this->request('POST', $body), new Psr7Response(), ['id' => (string) $invoiceId]);
    }

    private function mappedRegister(): int
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM chart_of_accounts WHERE supplier_id = ?', [$this->supplierId]) === 0) {
            $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        }
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$registerId, $this->deviceId]);
        return $registerId;
    }

    private function invoice(float $total, string $type = 'invoice', ?int $parentId = null, ?int $currencyId = null): int
    {
        $pdo = $this->db->pdo();
        $currencyId ??= $this->czk;
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat, advance_paid_amount,
                 parent_invoice_id, bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, ?, ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, ?, 0, ?, 0, ?, 0, ?, ?, "issued", "1", ?)'
        )->execute([
            $this->supplierId, (string) random_int(1000000, 9999999), $type, $this->clientId, $currencyId,
            $currencyId === $this->czk ? null : 24.0, $total, $total, $parentId,
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

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/invoices')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
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
