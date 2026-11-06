<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Mobile;

use DI\Container;
use MyInvoice\Action\Eet\EetRecordAction;
use MyInvoice\Action\Invoice\PublicReceiptAction;
use MyInvoice\Action\Invoice\ReceiptLinkAction;
use MyInvoice\Action\Mobile\QuickReceiptAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Service\Mobile\QuickReceiptException;
use MyInvoice\Service\Mobile\QuickReceiptService;
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
 * Rychlá účtenka (POST /api/mobile/receipts), veřejná účtenka /r/{token} a souhrn
 * EET pro Domů. Skutečné akce a služby nad izolovanou firmou plátce DPH, transport
 * EET skriptovaný, čas z MockClocku. Syntetická data.
 */
#[Group('integration')]
final class QuickReceiptTest extends TestCase
{
    private Container $container;
    private Connection $db;
    private MockClock $clock;
    private int $supplierId = 0;
    private int $otherSupplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $deviceId = 0;
    private int $registerId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->clock = new MockClock(new \DateTimeImmutable('2026-11-02 10:00:00', new \DateTimeZone('Europe/Prague')));
        $this->container->set(EetTransportInterface::class, new ScriptedEetTransport());
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, $this->clock);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $this->userId);

        $this->supplierId = $this->supplier('Synthetic Účtenky s.r.o.');
        $this->otherSupplierId = $this->supplier('Synthetic Cizí s.r.o.');
        $this->currencyId = (int) $this->scalar("SELECT id FROM currencies WHERE supplier_id = ? AND code = 'CZK'", [$this->supplierId]);

        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 11, 'Provozovna', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Mobil')")
            ->execute([$this->supplierId, $unitId, 'M' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        $this->deviceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO eet_settings (supplier_id, enabled, environment, eic, active_from, regime, default_device_id)
             VALUES (?, 1, 'playground', 'CZ00000019', '2026-01-01', 'standard', ?)"
        )->execute([$this->supplierId, $this->deviceId]);

        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->registerId = $this->container->get(CashRegisterService::class)
            ->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $pdo->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$this->registerId, $this->deviceId]);
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
        foreach ([$this->supplierId, $this->otherSupplierId] as $supplierId) {
            if ($supplierId !== 0) {
                $pdo->prepare('UPDATE invoices SET parent_invoice_id = NULL WHERE supplier_id = ?')->execute([$supplierId]);
                // Vlastní měna firmy je zároveň její výchozí; kruh se rozpojí cizí CZK.
                $pdo->prepare("UPDATE supplier SET default_currency_id = (SELECT id FROM currencies WHERE code = 'CZK' AND supplier_id NOT IN (?, ?) ORDER BY id LIMIT 1) WHERE id = ?")
                    ->execute([$this->supplierId, $this->otherSupplierId, $supplierId]);
                self::deleteRows($pdo, 'supplier', $supplierId, 0);
            }
        }
        $this->db->close();
    }

    /** Hotovost, dvě sazby: jeden doklad s řádky, DPH z řádků, jedna úhrada, jeden PPD, jedna tržba. */
    public function testCashWithTwoRatesCreatesOneDocumentPaymentCashDocumentAndSale(): void
    {
        $response = $this->post($this->cashBody(), 'rcpt-cash-0001');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);

        $invoiceId = (int) $json['invoice']['id'];
        $invoice = $this->row('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        self::assertSame('1', (string) $invoice['is_simplified'], 'Bez odběratele zjednodušený daňový doklad.');
        self::assertSame('paid', $invoice['status']);
        self::assertSame('cash', $invoice['payment_method']);
        self::assertNotSame('', (string) $invoice['varsymbol']);
        self::assertSame('298.00', $invoice['total_with_vat']);
        self::assertSame('48.00', $invoice['total_vat'], 'DPH shora z řádků: 42 + 6.');
        self::assertSame([['21.00', '42.00', '242.00'], ['12.00', '6.00', '56.00']], $this->db->pdo()->query(
            'SELECT vat_rate_snapshot, total_vat, total_with_vat FROM invoice_items WHERE invoice_id = ' . $invoiceId . ' ORDER BY order_index, id'
        )->fetchAll(PDO::FETCH_NUM));
        self::assertSame('1', (string) $this->scalar('SELECT is_walk_in FROM clients WHERE id = ?', [(int) $invoice['client_id']]));

        self::assertSame(1, $this->rows('invoices'));
        self::assertSame(1, $this->rows('invoice_payments'));
        self::assertSame(1, $this->rows('cash_documents'));
        self::assertSame(1, $this->rows('payment_events'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame('298.00', $this->scalar('SELECT total_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('cash', $this->scalar('SELECT source FROM invoice_payments WHERE supplier_id = ?', [$this->supplierId]),
            'Úhrada z pokladního dokladu, ne druhá ruční úhrada.');
        self::assertSame($this->deviceId, (int) $this->scalar('SELECT device_id FROM eet_records WHERE supplier_id = ?', [$this->supplierId]));

        self::assertSame('298.00', $json['payment']['amount']);
        self::assertSame('500.00', $json['payment']['received_amount']);
        self::assertSame('202.00', $json['payment']['change_amount']);
        self::assertNotNull($json['cash_document']);
        self::assertSame('confirmed', $json['states']['eet'], 'Odeslání po commitu.');
        self::assertSame('settled', $json['states']['money']);
        self::assertSame('paid', $json['states']['document']);
        self::assertStringContainsString('/r/', (string) $json['public_receipt_url']);
    }

    public function testCardPaymentHasNoCashDocument(): void
    {
        $response = $this->post(['channel' => 'card_terminal'] + $this->cashBody(withReceived: false), 'rcpt-card-0001');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertNull($json['cash_document']);
        self::assertSame(0, $this->rows('cash_documents'));
        self::assertSame(1, $this->rows('invoice_payments'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame('manual', $this->scalar('SELECT source FROM invoice_payments WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('card', $this->scalar('SELECT payment_method FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        self::assertSame('card_terminal', $json['payment']['event']['channel']);
    }

    public function testRepeatedKeyReturnsOriginalReceiptAndDifferentBodyConflicts(): void
    {
        $first = $this->json($this->post($this->cashBody(), 'rcpt-repeat-01'));
        $replay = $this->post($this->cashBody(), 'rcpt-repeat-01');
        self::assertSame(200, $replay->getStatusCode(), (string) $replay->getBody());
        $replayJson = $this->json($replay);
        self::assertTrue($replayJson['idempotent_replay']);
        self::assertSame($first['invoice']['id'], $replayJson['invoice']['id']);
        self::assertSame(1, $this->rows('invoices'));
        self::assertSame(1, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('cash_documents'));

        $conflict = $this->post(['note' => 'jiné tělo'] + $this->cashBody(), 'rcpt-repeat-01');
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('idempotency_conflict', $this->json($conflict)['error']['code']);
        self::assertSame(1, $this->rows('invoices'));
    }

    /** Úhrada poukazem / kreditem jde celou částkou do čerpání (cerp_zuct). */
    public function testStoredValuePaymentIsCreditDraw(): void
    {
        $response = $this->post(['channel' => 'stored_value'] + $this->cashBody(withReceived: false), 'rcpt-voucher-1');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('offset', $this->scalar('SELECT payment_method FROM invoices WHERE supplier_id = ?', [$this->supplierId]));
        $record = $this->row('SELECT total_amount, credit_draw_amount, credit_topup_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['298.00', '298.00', null], [$record['total_amount'], $record['credit_draw_amount'], $record['credit_topup_amount']]);
    }

    /** Prodej hodnotového poukazu (skladová karta druhu stored_value) je platba určená k čerpání. */
    public function testStoredValueItemSaleIsCreditTopup(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO stock_items (supplier_id, sku, name, unit, eet_item_kind) VALUES (?, 'VOUCHER', 'Dárkový kredit', 'ks', 'stored_value')"
        )->execute([$this->supplierId]);
        $stockItemId = (int) $this->db->pdo()->lastInsertId();
        $body = ['channel' => 'card_terminal', 'items' => [
            ['stock_item_id' => $stockItemId, 'quantity' => 1, 'unit_price' => '500', 'vat_rate' => 0],
            ['description' => 'Káva', 'quantity' => 1, 'unit_price' => '100', 'vat_rate' => 0],
        ]];
        $response = $this->post($body, 'rcpt-topup-001');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('Dárkový kredit', $this->scalar('SELECT description FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ? AND ii.stock_item_id = ?', [$this->supplierId, $stockItemId]));
        $record = $this->row('SELECT total_amount, credit_topup_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId]);
        self::assertSame(['600.00', '500.00'], [$record['total_amount'], $record['credit_topup_amount']]);
    }

    /** Víceúčelový poukaz se sazbou DPH je chyba vstupu, účtenka nevznikne a nic se nepřepočítá. */
    public function testTaxedStoredValueItemIsRejected(): void
    {
        $this->db->pdo()->prepare(
            "INSERT INTO stock_items (supplier_id, sku, name, unit, eet_item_kind) VALUES (?, 'VOUCHER-TAX', 'Dárkový poukaz', 'ks', 'stored_value')"
        )->execute([$this->supplierId]);
        $stockItemId = (int) $this->db->pdo()->lastInsertId();
        $body = ['channel' => 'card_terminal', 'items' => [
            ['stock_item_id' => $stockItemId, 'quantity' => 1, 'unit_price' => '1210', 'vat_rate' => 21],
        ]];
        $response = $this->post($body, 'rcpt-voucher-taxed-1');
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('jednoúčelový poukaz', (string) json_encode($this->json($response), JSON_UNESCAPED_UNICODE));
        self::assertSame(0, $this->rows('invoices'));
    }

    public function testCompanyWithoutEetIssuesReceiptWithoutSale(): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET enabled = 0 WHERE supplier_id = ?')->execute([$this->supplierId]);
        $response = $this->post($this->cashBody(), 'rcpt-noeet-001');
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame('inactive', $json['eet']['decision']);
        self::assertNull($json['eet']['record']);
        self::assertSame(0, $this->rows('eet_records'));
        self::assertSame(1, $this->rows('cash_documents'));
        self::assertSame(1, $this->rows('invoice_payments'));

        $card = $this->post(['channel' => 'card_terminal'] + $this->cashBody(withReceived: false), 'rcpt-noeet-002');
        self::assertSame(201, $card->getStatusCode(), (string) $card->getBody());
        self::assertSame('inactive', $this->json($card)['eet']['decision']);
        self::assertSame(0, $this->rows('eet_records'));
    }

    public function testForeignDeviceIsNotFoundAndWritesNothing(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 12, 'Cizí', 'premises', '2026-01-01')")
            ->execute([$this->otherSupplierId]);
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Cizí')")
            ->execute([$this->otherSupplierId, (int) $pdo->lastInsertId(), 'X' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        $foreignDevice = (int) $pdo->lastInsertId();

        $response = $this->post(['device_id' => $foreignDevice] + $this->cashBody(), 'rcpt-foreign-1');
        self::assertSame(404, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('device_not_found', $this->json($response)['error']['code']);
        self::assertSame(0, $this->rows('invoices'));
        self::assertSame(0, $this->rows('quick_receipts'));
    }

    /** Nad limit zjednodušeného dokladu bez odběratele: 422, žádný zápis ani spálené číslo. */
    public function testOverSimplifiedLimitWithoutClientRequiresClient(): void
    {
        $body = ['channel' => 'card_terminal', 'items' => [['description' => 'Kolo', 'quantity' => 1, 'unit_price' => '12100', 'vat_rate' => 21]]];
        $response = $this->post($body, 'rcpt-limit-001');
        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('client_required', $this->json($response)['error']['code']);
        self::assertSame(0, $this->rows('invoices'));
        self::assertSame(0, $this->rows('quick_receipts'));

        $clientId = $this->client();
        $ok = $this->post(['client_id' => $clientId] + $body, 'rcpt-limit-002');
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        $json = $this->json($ok);
        self::assertFalse($json['invoice']['is_simplified'], 'S odběratelem běžný daňový doklad.');
        self::assertSame($clientId, (int) $this->scalar('SELECT client_id FROM invoices WHERE id = ?', [(int) $json['invoice']['id']]));
    }

    public function testUserWithoutEetPermissionIsRejectedWhenCompanyRecords(): void
    {
        try {
            $this->container->get(QuickReceiptService::class)->create($this->supplierId, $this->userId, 'rcpt-perm-0001', $this->cashBody(), false);
            self::fail('Účtenka s EET prošla bez oprávnění eet.accept_payment.');
        } catch (QuickReceiptException $e) {
            self::assertSame(403, $e->httpStatus);
        }
        self::assertSame(0, $this->rows('invoices'));
    }

    public function testMissingIdempotencyKeyIsRejected(): void
    {
        $response = $this->post($this->cashBody(), null);
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $this->rows('invoices'));
    }

    /** Veřejná účtenka: data bez interních údajů, v DB jen otisk tokenu. */
    public function testPublicReceiptShowsReceiptWithoutInternalDataAndStoresOnlyHash(): void
    {
        $json = $this->json($this->post(['note' => 'Interní poznámka obsluhy XYZ'] + $this->cashBody(), 'rcpt-public-01'));
        $token = $this->tokenFrom((string) $json['public_receipt_url']);

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM receipt_links WHERE supplier_id = ? AND token_hash = ?', [$this->supplierId, hash('sha256', $token)]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM receipt_links WHERE token_hash = ?', [$token]), 'Token se neukládá v plaintextu.');

        $response = $this->container->get(PublicReceiptAction::class)->show($this->publicRequest(), new Psr7Response(), ['token' => $token]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = (string) $response->getBody();
        $data = $this->json($response);
        self::assertSame($json['invoice']['number'], $data['receipt']['number']);
        self::assertTrue($data['receipt']['is_simplified']);
        self::assertCount(2, $data['receipt']['items']);
        self::assertCount(2, $data['receipt']['vat_breakdown']);
        self::assertNull($data['client'], 'Koncový zákazník se nezobrazuje.');
        self::assertSame('CZ00000019', $data['eet'][0]['eic']);
        self::assertSame(11, $data['eet'][0]['premises']);
        self::assertTrue($data['eet'][0]['playground']);
        self::assertSame('TEST-POK-0001-ff', $data['eet'][0]['pok']);
        self::assertStringNotContainsString('Interní poznámka', $body);
        foreach (['created_by', 'issued_by', 'user_id', 'supplier_id', 'client_id', 'public_token', 'token_hash', 'note_below_items'] as $internal) {
            self::assertStringNotContainsString('"' . $internal . '"', $body, $internal);
        }
    }

    public function testPublicReceiptExpiresAndCanBeRevoked(): void
    {
        $json = $this->json($this->post($this->cashBody(), 'rcpt-expire-01'));
        $token = $this->tokenFrom((string) $json['public_receipt_url']);
        $invoiceId = (int) $json['invoice']['id'];
        $show = fn (string $t): int => $this->container->get(PublicReceiptAction::class)
            ->show($this->publicRequest(), new Psr7Response(), ['token' => $t])->getStatusCode();

        self::assertSame(200, $show($token));
        self::assertSame(404, $show(bin2hex(random_bytes(32))), 'Cizí token.');

        $this->clock->modify('+91 days');
        self::assertSame(404, $show($token), 'Prošlý odkaz.');
        $this->clock->modify('-91 days');

        $create = $this->container->get(ReceiptLinkAction::class)->create($this->request('POST', ['ttl_days' => 7]), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $second = (string) $this->json($create)['token'];
        self::assertSame(200, $show($second));

        $revoke = $this->container->get(ReceiptLinkAction::class)->revoke($this->request('DELETE'), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(2, $this->json($revoke)['revoked']);
        self::assertSame(404, $show($token));
        self::assertSame(404, $show($second));
    }

    public function testReceiptLinkRequiresPaidInvoice(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id,
                                   total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "invoice", ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, 100, 0, 100, "issued", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000, 9999999), $this->client(), $this->currencyId, $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        $response = $this->container->get(ReceiptLinkAction::class)->create($this->request('POST'), new Psr7Response(), ['id' => (string) $invoiceId]);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('receipt_not_paid', $this->json($response)['error']['code']);
    }

    /** Souhrn pro Domů: dnešní a měsíční tržby, poslední potvrzení, certifikát, fronta. */
    public function testEetSummaryCountsTodayAndMonth(): void
    {
        $this->post($this->cashBody(), 'rcpt-sum-00001');
        $this->clock->modify('-1 day');
        $this->post(['channel' => 'card_terminal'] + $this->cashBody(withReceived: false), 'rcpt-sum-00002');
        $this->clock->modify('+1 day');

        $response = $this->container->get(EetRecordAction::class)->summary($this->request('GET'), new Psr7Response());
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $json = $this->json($response);
        self::assertSame('2026-11-02', $json['date']);
        self::assertSame(1, $json['today']['count']);
        self::assertSame('298.00', $json['today']['confirmed_total']);
        self::assertSame(2, $json['month']['count']);
        self::assertSame('596.00', $json['month']['confirmed_total']);
        self::assertSame(0, $json['month']['waiting']);
        self::assertSame('playground', $json['environment']);
        self::assertTrue($json['active']);
        self::assertNotNull($json['last_confirmed_at']);
        self::assertSame(0, $json['queue']['open_total']);

        $bad = $this->container->get(EetRecordAction::class)->summary($this->request('GET', [], [], ['date' => '2026-02-31']), new Psr7Response());
        self::assertSame(422, $bad->getStatusCode());
    }

    /** @return array<string,mixed> */
    private function cashBody(bool $withReceived = true): array
    {
        return [
            'channel' => 'cash',
            'items' => [
                ['description' => 'Káva', 'quantity' => 2, 'unit_price' => '121', 'vat_rate' => 21, 'prices_include_vat' => true],
                ['description' => 'Koláč', 'quantity' => 1, 'unit_price' => '56', 'vat_rate' => 12, 'prices_include_vat' => true],
            ],
        ] + ($withReceived ? ['received_amount' => '500'] : []);
    }

    /** @param array<string,mixed> $body */
    private function post(array $body, ?string $key): ResponseInterface
    {
        return $this->container->get(QuickReceiptAction::class)(
            $this->request('POST', $body, $key !== null ? ['Idempotency-Key' => $key] : []),
            new Psr7Response(),
        );
    }

    private function supplier(string $name): int
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $vat = (int) $pdo->query("SELECT id FROM vat_rates WHERE code = 'CZ-21' LIMIT 1")->fetchColumn();
        // Firma potřebuje výchozí měnu dřív, než může mít vlastní; dočasně cizí CZK, hned se přepíše.
        $anyCzk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, ic, dic, is_vat_payer, default_currency_id, default_vat_rate_id, accounting_mode, stock_enabled)
             VALUES (?, 'Test 1', 'Praha', '11000', ?, ?, '00000019', 'CZ00000019', 1, ?, ?, 'tax_evidence', 0)"
        )->execute([$name, $country, uniqid('rcpt-') . '@example.com', $anyCzk, $vat]);
        $supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, is_default) VALUES (?, 'CZK', 'CZK', 'Kč', 'Koruna', 'Koruna', 1)")
            ->execute([$supplierId]);
        $pdo->prepare('UPDATE supplier SET default_currency_id = ? WHERE id = ?')->execute([(int) $pdo->lastInsertId(), $supplierId]);
        return $supplierId;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->currencyId]);
        return (int) $pdo->lastInsertId();
    }

    private function tokenFrom(string $url): string
    {
        self::assertSame(1, preg_match('#/r/([a-f0-9]{64})$#', $url, $m), $url);
        return $m[1];
    }

    private function publicRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/public/receipt/x', ['HTTP_HOST' => 'localhost']);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    private function request(string $method, array $body = [], array $headers = [], array $query = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, '/api/mobile/receipts')
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
