<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Eet;

use DI\Container;
use MyInvoice\Action\Stock\StockItemAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\Cash\CashDocumentService;
use MyInvoice\Service\Accounting\Cash\CashRegisterService;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Eet\EetCommandException;
use MyInvoice\Service\Eet\EetCorrectionService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\EetStoredValueCalculator;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;
use Symfony\Component\Clock\MockClock;

/**
 * Poukazy a kredit (metodika ZoET k položkám urceno_cerp_zuct a cerp_zuct): hodnotový
 * poukaz / dobití kreditu jako druh skladové karty, uplatnění kanálem „Poukaz / kredit",
 * vratky na kartu i v hotovosti. Skutečné služby, transport ze skriptu.
 */
#[Group('integration')]
final class EetStoredValueTest extends TestCase
{
    private const DAY = '2026-11-02';

    private Container $container;
    private Connection $db;
    private MockClock $clock;
    private ScriptedEetTransport $transport;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $czk = 0;
    private int $vat = 0;
    private int $userId = 0;
    private int $deviceId = 0;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->clock = new MockClock(new \DateTimeImmutable(self::DAY . ' 10:00:00', new \DateTimeZone('Europe/Prague')));
        $this->transport = new ScriptedEetTransport();
        $this->container->set(EetTransportInterface::class, $this->transport);
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, $this->clock);
        $this->db = $this->container->get(Connection::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, min($country, $this->czk, $this->vat, $this->userId));

        $pdo->prepare(
            "INSERT INTO supplier (company_name, street, city, zip, country_id, email, default_currency_id, default_vat_rate_id, accounting_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'tax_evidence')"
        )->execute(['Synthetic EET kredit s.r.o.', 'Test 1', 'Praha', '11000', $country, uniqid('eetsv-') . '@example.com', $this->czk, $this->vat]);
        $this->supplierId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel s.r.o.", "Test 1", "Praha", "11000", ?, "test@example.com", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->czk]);
        $this->clientId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 1551, 'Fitness', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Recepce')")
            ->execute([$this->supplierId, $unitId, 'K' . substr(bin2hex(random_bytes(6)), 0, 10)]);
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
            self::deleteRows($pdo, 'supplier', $this->supplierId, 0);
        }
        $this->db->close();
    }

    /** Příklad 1: nápoj 50 Kč a dobití kreditu 1 000 Kč hotově → celk_trzba 1050, urceno_cerp_zuct 1000. */
    public function testMixedInvoiceSaleRecordsTopUpForStoredValueItemsOnly(): void
    {
        $invoiceId = $this->invoice([[50, 'standard'], [1000, 'stored_value']]);
        $result = $this->command()->accept($this->supplierId, $this->userId, 'sv-mixed-0001', [
            'invoice_id' => $invoiceId, 'amount' => '1050', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);

        self::assertSame('confirmed', $result['eet']['status']);
        self::assertSame([['1050.00', '1000.00', null]], $this->records());
        $data = $this->transport->calls[0]['data'];
        self::assertSame([105000, 100000, null], [$data->totalCents, $data->creditTopUpCents, $data->creditDrawCents]);
    }

    /** Stejný doklad uhrazený příjmovým pokladním dokladem nese stejný podíl kreditu. */
    public function testMixedInvoicePaidByCashDocumentCarriesTopUp(): void
    {
        $invoiceId = $this->invoice([[50, 'standard'], [1000, 'stored_value']]);
        $registerId = $this->mappedRegister();
        $created = $this->container->get(CashDocumentService::class)->create($this->supplierId, [
            'register_id' => $registerId, 'doc_type' => 'in', 'purpose' => 'invoice_payment', 'invoice_id' => $invoiceId,
            'issue_date' => self::DAY, 'description' => 'Úhrada faktury', 'total_amount' => 1050,
        ], $this->userId);

        self::assertSame('posted', $created['status']);
        self::assertSame([['1050.00', '1000.00', null]], $this->records());
        self::assertSame(100000, $this->transport->calls[0]['data']->creditTopUpCents);
    }

    /**
     * Tři úhrady po 350 Kč: kredit se dělí poměrem kumulativně (333,33 + 333,34 + 333,33),
     * součet je přesně 1 000 Kč a nikdy ho nepřesáhne. Úhrada převodem z domova (bez
     * tržby) svůj podíl také spotřebuje.
     */
    public function testPartialPaymentsSplitTopUpCumulatively(): void
    {
        $invoiceId = $this->invoice([[50, 'standard'], [1000, 'stored_value']]);
        $command = $this->command();
        foreach (['sv-part-0001', 'sv-part-0002', 'sv-part-0003'] as $i => $key) {
            $this->clock->modify('+1 minute');
            $command->accept($this->supplierId, $this->userId, $key, [
                'invoice_id' => $invoiceId, 'amount' => '350', 'channel' => 'cash', 'contact_context' => 'in_person',
            ]);
        }
        self::assertSame([['350.00', '333.33', null], ['350.00', '333.34', null], ['350.00', '333.33', null]], $this->records());

        $other = $this->invoice([[50, 'standard'], [1000, 'stored_value']]);
        $command->accept($this->supplierId, $this->userId, 'sv-part-0004', [
            'invoice_id' => $other, 'amount' => '525', 'channel' => 'bank_transfer', 'contact_context' => 'remote',
        ]);
        $command->accept($this->supplierId, $this->userId, 'sv-part-0005', [
            'invoice_id' => $other, 'amount' => '525', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        self::assertSame(['525.00', '500.00', null], $this->records()[3], 'Druhá polovina nese druhou polovinu kreditu.');
    }

    /** Příklad 1, uplatnění: hodina za 290 Kč odečtená z kreditu → celk_trzba 290, cerp_zuct 290. */
    public function testStoredValueRedemptionRecordsCreditDraw(): void
    {
        $preview = $this->command()->preview($this->supplierId, ['amount' => '290', 'channel' => 'stored_value', 'contact_context' => 'in_person']);
        self::assertTrue($preview['creates_record']);
        self::assertSame(['290.00', null], [$preview['credit_draw_amount'], $preview['credit_topup_amount']]);

        $result = $this->command()->accept($this->supplierId, $this->userId, 'sv-draw-0001', [
            'amount' => '290', 'channel' => 'stored_value', 'contact_context' => 'in_person',
        ]);
        self::assertSame(['credit_draw', 'offset', 'stored_value_contact'],
            [$result['business_purpose'], $result['payment_method'], $result['eet_decision']['reason_code']]);
        self::assertSame([['290.00', null, '290.00']], $this->records());
        $data = $this->transport->calls[0]['data'];
        self::assertSame([29000, null, 29000], [$data->totalCents, $data->creditTopUpCents, $data->creditDrawCents]);

        try {
            $this->command()->accept($this->supplierId, $this->userId, 'sv-draw-0002', [
                'amount' => '290', 'channel' => 'cash', 'contact_context' => 'in_person', 'business_purpose' => 'credit_draw',
            ]);
            self::fail('Čerpání kreditu hotovostí prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame(['eet_validation', 'channel'], [$e->errorCode, $e->extra['field'] ?? null]);
        }
    }

    /**
     * Příklad 3: zboží za 121 Kč z přednabité karty se vrací zpět na kartu → −121 / −121.
     * Vratka v hotovosti je běžná záporná tržba bez kreditových polí.
     */
    public function testRefundToStoredValueNegatesCreditDrawAndCashRefundDoesNot(): void
    {
        $this->command()->accept($this->supplierId, $this->userId, 'sv-ref-0001', [
            'amount' => '121', 'channel' => 'stored_value', 'contact_context' => 'in_person',
        ]);
        $this->command()->accept($this->supplierId, $this->userId, 'sv-ref-0002', [
            'amount' => '121', 'channel' => 'stored_value', 'contact_context' => 'in_person',
        ]);
        [$first, $second] = $this->saleIds();
        $corrections = $this->container->get(EetCorrectionService::class);
        $this->clock->modify('+1 day');

        $preview = $corrections->preview($this->supplierId, $first, ['amount' => '121', 'reason' => 'refund', 'refund_to_stored_value' => true]);
        self::assertTrue($preview['stored_value_payment']);
        self::assertSame('-121.00', $preview['proposed']['credit_draw_amount']);

        $card = $corrections->create($this->supplierId, $this->userId, $first, 'sv-ref-card-01', [
            'amount' => '121', 'reason' => 'refund', 'refund_to_stored_value' => true,
        ]);
        self::assertSame(['-121.00', null, '-121.00'], [$card['correction']['total_amount'], $card['correction']['credit_topup_amount'], $card['correction']['credit_draw_amount']]);
        $data = end($this->transport->calls)['data'];
        self::assertSame([-12100, -12100], [$data->totalCents, $data->creditDrawCents]);

        $cash = $corrections->create($this->supplierId, $this->userId, $second, 'sv-ref-cash-01', ['amount' => '121', 'reason' => 'refund']);
        self::assertSame(['-121.00', null, null], [$cash['correction']['total_amount'], $cash['correction']['credit_topup_amount'], $cash['correction']['credit_draw_amount']]);
        self::assertNull(end($this->transport->calls)['data']->creditDrawCents);
    }

    /** Vrácení na kartu u tržby, která kreditem placená nebyla, se odmítne. */
    public function testRefundToStoredValueRequiresCreditPayment(): void
    {
        $this->command()->accept($this->supplierId, $this->userId, 'sv-ref-0003', [
            'amount' => '121', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        try {
            $this->container->get(EetCorrectionService::class)->create($this->supplierId, $this->userId, $this->saleIds()[0], 'sv-ref-bad-01', [
                'amount' => '121', 'reason' => 'refund', 'refund_to_stored_value' => true,
            ]);
            self::fail('Vrácení na kartu u hotovostní tržby prošlo.');
        } catch (EetCommandException $e) {
            self::assertSame('refund_to_stored_value', $e->extra['field'] ?? null);
        }
    }

    /** Vrácení peněz za nevyčerpaný kredit: oprava tržby s kreditem ho snižuje úměrně, zbytek dorovná přesně. */
    public function testCorrectionOfTopUpSaleReducesTopUpProportionally(): void
    {
        $invoiceId = $this->invoice([[50, 'standard'], [1000, 'stored_value']]);
        $this->command()->accept($this->supplierId, $this->userId, 'sv-topref-01', [
            'invoice_id' => $invoiceId, 'amount' => '1050', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        $saleId = $this->saleIds()[0];
        $corrections = $this->container->get(EetCorrectionService::class);
        $half = $corrections->create($this->supplierId, $this->userId, $saleId, 'sv-topref-02', ['amount' => '525', 'reason' => 'refund']);
        self::assertSame(['-525.00', '-500.00', null], [$half['correction']['total_amount'], $half['correction']['credit_topup_amount'], $half['correction']['credit_draw_amount']]);
        self::assertSame(-50000, end($this->transport->calls)['data']->creditTopUpCents);
        $corrections->create($this->supplierId, $this->userId, $saleId, 'sv-topref-03', ['amount' => '0.01', 'reason' => 'refund']);
        $rest = $corrections->create($this->supplierId, $this->userId, $saleId, 'sv-topref-04', ['amount' => '524.99', 'reason' => 'refund']);
        self::assertSame('0.00', $rest['original_remaining']);
        self::assertSame(['0.00', '0.00'], array_values($this->row(
            'SELECT SUM(total_amount), SUM(credit_topup_amount) FROM eet_records WHERE supplier_id = ?', [$this->supplierId])));
    }

    /** Příklad 4: dárkový poukaz na masáž (konkrétní služba) je běžný prodej bez kreditových polí. */
    public function testSpecificVoucherIsOrdinarySale(): void
    {
        $invoiceId = $this->invoice([[1000, 'specific_voucher']]);
        $this->command()->accept($this->supplierId, $this->userId, 'sv-gift-0001', [
            'invoice_id' => $invoiceId, 'amount' => '1000', 'channel' => 'cash', 'contact_context' => 'in_person',
        ]);
        self::assertSame([['1000.00', null, null]], $this->records());
        self::assertNull($this->transport->calls[0]['data']->creditTopUpCents);
    }

    /** Druh pro EET se ukládá přes skladovou kartu; neplatná hodnota je validační chyba. */
    public function testStockItemEetKindIsEditable(): void
    {
        $this->db->pdo()->prepare('UPDATE supplier SET stock_enabled = 1 WHERE id = ?')->execute([$this->supplierId]);
        $action = $this->container->get(StockItemAction::class);
        $created = $this->json($action->create($this->request(['name' => 'Kredit 1000', 'sku' => 'KREDIT-1000', 'eet_item_kind' => 'stored_value']), new Psr7Response()));
        self::assertSame('stored_value', $created['eet_item_kind'] ?? null, json_encode($created));
        $updated = $action->update($this->request(['name' => 'Kredit 1000', 'sku' => 'KREDIT-1000', 'eet_item_kind' => 'specific_voucher']), new Psr7Response(), ['id' => (string) $created['id']]);
        self::assertSame('specific_voucher', $this->json($updated)['eet_item_kind'] ?? null);
        $bad = $action->update($this->request(['name' => 'Kredit 1000', 'sku' => 'KREDIT-1000', 'eet_item_kind' => 'credit']), new Psr7Response(), ['id' => (string) $created['id']]);
        self::assertSame(400, $bad->getStatusCode());
    }

    public function testShareRoundsCumulativelyAndNeverExceedsStoredValue(): void
    {
        $parts = [];
        $paid = 0;
        foreach ([1, 1, 1, 104997] as $amount) {
            $parts[] = EetStoredValueCalculator::share(105000, 100000, $paid, $amount);
            $paid += $amount;
        }
        self::assertSame(100000, array_sum($parts));
        self::assertSame(0, EetStoredValueCalculator::share(105000, 100000, 105000, 500), 'Přeplatek kredit nenese.');
        self::assertSame(0, EetStoredValueCalculator::share(0, 0, 0, 500));
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    private function command(): EetPaymentCommand
    {
        return $this->container->get(EetPaymentCommand::class);
    }

    /** @return list<array{0:string, 1:?string, 2:?string}> total, urceno, cerp tržeb (bez oprav) */
    private function records(): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT total_amount, credit_topup_amount, credit_draw_amount FROM eet_records WHERE supplier_id = ? AND kind = 'sale' ORDER BY id");
        $stmt->execute([$this->supplierId]);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    /** @return list<int> */
    private function saleIds(): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM eet_records WHERE supplier_id = ? AND kind = 'sale' ORDER BY id");
        $stmt->execute([$this->supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<array{0:float|int, 1:string}> $lines částka s DPH a druh skladové karty */
    private function invoice(array $lines): int
    {
        $pdo = $this->db->pdo();
        $total = array_sum(array_column($lines, 0));
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount, bank_snapshot, status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, "2026-11-01", "2026-11-01", "2026-11-15", ?, 0, ?, 0, ?, 0, ?, "issued", "1", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000, 9999999), $this->clientId, $this->czk, $total, $total,
            json_encode(['account_number' => '1000000005', 'bank_code' => '0100']), $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        foreach ($lines as $i => [$amount, $kind]) {
            $pdo->prepare('INSERT INTO stock_items (supplier_id, sku, name, eet_item_kind) VALUES (?, ?, ?, ?)')
                ->execute([$this->supplierId, 'SV-' . $invoiceId . '-' . $i, 'Synthetic ' . $kind, $kind]);
            $stockItemId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                            total_without_vat, total_vat, total_with_vat, order_index, stock_item_id)
                 VALUES (?, ?, 1, ?, ?, 0, ?, 0, ?, ?, ?)'
            )->execute([$invoiceId, 'Synthetic ' . $kind, $amount, $this->vat, $amount, $amount, $i, $stockItemId]);
        }
        return $invoiceId;
    }

    private function mappedRegister(): int
    {
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $registerId = $this->container->get(CashRegisterService::class)->create($this->supplierId, ['name' => 'Pokladna', 'account_code' => '211', 'is_default' => true]);
        $this->db->pdo()->prepare('UPDATE eet_devices SET cash_register_id = ? WHERE id = ?')->execute([$registerId, $this->deviceId]);
        return $registerId;
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/stock/items')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return (array) json_decode((string) $response->getBody(), true);
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

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
