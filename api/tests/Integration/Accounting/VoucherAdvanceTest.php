<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use DI\Container;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\CashJournalRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Invoice\NewInvoiceVatClassification;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer;
use MyInvoice\Service\Accounting\Closing\ClosingService;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Eet\EetCredentialProvider;
use MyInvoice\Service\Eet\EetPaymentCommand;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use MyInvoice\Tests\Support\ScriptedEetTransport;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Víceúčelový poukaz a kredit (§ 37c ZDPH) přes přijaté zálohy:
 *
 *   • prodej (skladová karta `stored_value`, řádek bez daně) jde na analytiku přijatých záloh
 *     „Prodané poukazy a kredit" (324.900) místo výnosu,
 *     daň a pohledávka ostatních řádků se nemění,
 *   • řádek s daní (jednoúčelový poukaz § 37b, nebo karta `stored_value` se sazbou z doby
 *     před validací) zůstává výnosem; nový zdaněný víceúčelový poukaz uložení odmítne,
 *   • řádek víceúčelového poukazu nikdy nenese klasifikaci DPH a o kódu hlavičky nerozhoduje,
 *   • uplatnění kanálem „Poukaz / kredit" je v podvojném účetnictví zápočet 324.900 MD / 311 D,
 *     v daňové evidenci a u jiných kanálů zůstává ruční úhrada.
 *
 * Vše v jedné transakci, tearDown rollbackne.
 */
#[Group('integration')]
final class VoucherAdvanceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private const DAY = '2026-11-02';

    private Container $container;
    private Connection $db;
    private PostingService $posting;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $czk = 0;
    private int $vat = 0;
    private int $userId = 0;
    private int $periodId = 0;
    private bool $inTx = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje, test vyžaduje DB.');
        }
        $this->container = Bootstrap::buildContainer();
        $this->container->set(EetTransportInterface::class, new ScriptedEetTransport());
        $this->container->set(EetCredentialProvider::class, ScriptedEetTransport::credentials());
        $this->container->set(ClockInterface::class, new MockClock(new \DateTimeImmutable(self::DAY . ' 10:00:00', new \DateTimeZone('Europe/Prague'))));
        $this->db = $this->container->get(Connection::class);
        $this->posting = $this->container->get(PostingService::class);
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $source = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $country = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn();
        $this->czk = (int) $pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn();
        $this->vat = (int) $pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if (min($source, $country, $this->czk, $this->vat, $this->userId) <= 0) {
            $this->markTestSkipped('Chybí základní data v DB.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;
        $this->supplierId = $this->createIsolatedSupplier($pdo, $source);
        $pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")->execute([$this->supplierId]);
        $this->setVatPayerAt($pdo, $this->supplierId, '2000-01-01', true);
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $this->periodId = $this->container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2026, '2026-01-01', '2026-12-31');

        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, "Odběratel poukazu", "Test 1", "Praha", "11000", ?, "poukaz@example.invalid", "cs", ?, 1)'
        )->execute([$this->supplierId, $country, $this->czk]);
        $this->clientId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from) VALUES (?, 1552, 'Recepce', 'premises', '2026-01-01')")
            ->execute([$this->supplierId]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO eet_devices (supplier_id, unit_id, code, name) VALUES (?, ?, ?, 'Pokladna')")
            ->execute([$this->supplierId, $unitId, 'V' . substr(bin2hex(random_bytes(6)), 0, 10)]);
        $deviceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO eet_settings (supplier_id, enabled, environment, eic, active_from, regime, default_device_id)
             VALUES (?, 1, 'playground', 'CZ00000019', '2026-01-01', 'standard', ?)"
        )->execute([$this->supplierId, $deviceId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            $pdo = $this->db->pdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->db->close();
        }
    }

    public function testMultiPurposeVoucherSaleGoesToReceivedAdvanceWithoutVat(): void
    {
        $invoiceId = $this->invoice([[100.00, 21.00, 21, 'standard'], [1000.00, 0.00, 0, 'stored_value']]);

        $byAccount = $this->byAccount($this->post($invoiceId));

        self::assertEqualsWithDelta(1121.00, $byAccount['311']['debit'], 0.001);
        self::assertEqualsWithDelta(1000.00, $byAccount['324.900']['credit'], 0.001, 'Poukaz je přijatá záloha na analytice poukazů.');
        self::assertEqualsWithDelta(100.00, $byAccount['602']['credit'], 0.001, 'Výnosem je jen nápoj.');
        self::assertEqualsWithDelta(21.00, $this->vatCredit($byAccount), 0.001, 'Poukaz daň nenese.');
        self::assertSame(['324.900', '602'], $this->sorted(array_values($this->posting->issuedItemRevenueAccounts($this->supplierId, $invoiceId)['accounts'])));
    }

    /** Obrana nad daty z doby před validací sazby: zapsáno přímo, editor by řádek odmítl. */
    public function testTaxedVoucherLinesStayRevenue(): void
    {
        $invoiceId = $this->invoice([[1000.00, 210.00, 21, 'stored_value'], [500.00, 105.00, 21, 'specific_voucher']]);

        $byAccount = $this->byAccount($this->post($invoiceId));

        self::assertArrayNotHasKey('324.900', $byAccount, 'Zdaněný řádek se při uplatnění znovu nedaní, nesmí tedy čekat na 324.');
        self::assertEqualsWithDelta(1500.00, $byAccount['602']['credit'], 0.001);
        self::assertEqualsWithDelta(315.00, $this->vatCredit($byAccount), 0.001);
    }

    public function testRedemptionDrawsReceivedAdvanceBySettlement(): void
    {
        $invoiceId = $this->invoice([[239.67, 50.33, 21, 'standard']]);

        $this->accept($invoiceId, 'stored_value', '290');

        $payment = $this->row('SELECT source, amount FROM invoice_payments WHERE invoice_id = ?', [$invoiceId]);
        self::assertSame(['settlement', '290.00'], [$payment['source'], $payment['amount']]);
        $settlement = $this->row(
            "SELECT s.journal_entry_id, a.account_code FROM invoice_settlements s JOIN chart_of_accounts a ON a.id = s.account_id
              WHERE s.supplier_id = ? AND s.doc_type = 'invoice' AND s.doc_id = ? AND s.status = 'confirmed'",
            [$this->supplierId, $invoiceId],
        );
        self::assertSame('324.900', $settlement['account_code']);
        $byAccount = $this->byAccount((int) $settlement['journal_entry_id']);
        self::assertEqualsWithDelta(290.00, $byAccount['324.900']['debit'], 0.001);
        self::assertEqualsWithDelta(290.00, $byAccount['311']['credit'], 0.001);
        self::assertSame('paid', $this->row('SELECT status FROM invoices WHERE id = ?', [$invoiceId])['status']);
        self::assertSame('290.00', $this->row('SELECT credit_draw_amount FROM eet_records WHERE supplier_id = ?', [$this->supplierId])['credit_draw_amount']);
        $allocation = $this->row('SELECT invoice_payment_id FROM payment_event_allocations WHERE supplier_id = ? AND invoice_id = ?', [$this->supplierId, $invoiceId]);
        self::assertSame((int) $this->row('SELECT id FROM invoice_payments WHERE invoice_id = ?', [$invoiceId])['id'], (int) $allocation['invoice_payment_id']);
    }

    public function testCashPaymentKeepsManualPayment(): void
    {
        $cash = $this->invoice([[239.67, 50.33, 21, 'standard']]);
        $this->accept($cash, 'cash', '290');
        self::assertSame('manual', $this->row('SELECT source FROM invoice_payments WHERE invoice_id = ?', [$cash])['source']);
    }

    /**
     * Daňová evidence: příjem vznikl při prodeji poukazu. Uplatnění poukazu vyrovná fakturu
     * bez peněžního pohybu a peněžní deník ho podruhé jako příjem nezapočte.
     */
    public function testTaxEvidenceRedemptionIsNotIncomeAgain(): void
    {
        $this->db->pdo()->prepare("UPDATE supplier SET accounting_mode = 'tax_evidence' WHERE id = ?")->execute([$this->supplierId]);
        $sale = $this->invoice([[1000.00, 0.00, 0, 'stored_value']]);
        $this->accept($sale, 'cash', '1000');
        $redemption = $this->invoice([[239.67, 50.33, 21, 'standard']]);
        $this->accept($redemption, 'stored_value', '290');

        $income = [];
        foreach ($this->container->get(CashJournalRepository::class)->movements($this->supplierId, self::DAY, self::DAY) as $movement) {
            if ($movement['direction'] === 'in' && $movement['invoice_id'] !== null) {
                $income[(int) $movement['invoice_id']] = ($income[(int) $movement['invoice_id']] ?? 0.0) + (float) $movement['amount'];
            }
        }
        self::assertEqualsWithDelta(1000.00, $income[$sale] ?? 0.0, 0.001, 'Příjmem je prodej poukazu.');
        self::assertArrayNotHasKey($redemption, $income, 'Uplatnění poukazu není druhý příjem.');
        self::assertSame('settlement', $this->row('SELECT source FROM invoice_payments WHERE invoice_id = ?', [$redemption])['source']);
        self::assertSame('paid', $this->row('SELECT status FROM invoices WHERE id = ?', [$redemption])['status']);
        self::assertSame('0', (string) $this->row('SELECT COUNT(*) AS n FROM invoice_settlements WHERE supplier_id = ?', [$this->supplierId])['n']);
    }

    public function testVoucherLineNeverCarriesVatClassification(): void
    {
        $pdo = $this->db->pdo();
        $de = (int) $pdo->query("SELECT id FROM countries WHERE iso2 = 'DE' LIMIT 1")->fetchColumn();
        $pdo->prepare('UPDATE clients SET country_id = ? WHERE id = ?')->execute([$de, $this->clientId]);
        $invoiceId = $this->invoice([]);
        $voucher = $this->stockItem('stored_value');

        $this->container->get(InvoiceRepository::class)->replaceItems($invoiceId, [
            ['description' => 'Poukaz', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->rateId(0), 'stock_item_id' => $voucher],
            ['description' => 'Poukaz s kódem', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 500, 'vat_rate_id' => $this->rateId(0),
                'vat_classification_code' => '1', 'stock_item_id' => $voucher],
            ['description' => 'Služba do EU', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 300, 'vat_rate_id' => $this->rateId(0)],
        ]);

        $stmt = $pdo->prepare('SELECT vat_classification_code FROM invoice_items WHERE invoice_id = ? ORDER BY order_index');
        $stmt->execute([$invoiceId]);
        self::assertSame([null, null, '22'], $stmt->fetchAll(PDO::FETCH_COLUMN), 'Poukaz nedostane ani výchozí 22, ani ručně zadaný kód.');
    }

    public function testTaxedMultiPurposeVoucherLineIsRejected(): void
    {
        $invoiceId = $this->invoice([[100.00, 21.00, 21, 'standard']]);
        $voucher = $this->stockItem('stored_value');

        try {
            $this->container->get(InvoiceRepository::class)->replaceItems($invoiceId, [
                ['description' => 'Poukaz', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->rateId(21), 'stock_item_id' => $voucher],
            ]);
            self::fail('Zdaněný víceúčelový poukaz musí být odmítnut.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('jednoúčelový poukaz', $e->getMessage());
        }
        self::assertSame('1', (string) $this->row('SELECT COUNT(*) AS n FROM invoice_items WHERE invoice_id = ?', [$invoiceId])['n'],
            'Odmítnutí nechá původní řádky beze změny.');
    }

    public function testHeaderClassificationIgnoresVoucherLines(): void
    {
        $voucher = $this->stockItem('stored_value');
        $body = ['client_id' => $this->clientId, 'issue_date' => self::DAY, 'tax_date' => self::DAY, 'items' => [
            ['description' => 'Káva', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate_id' => $this->rateId(21)],
            ['description' => 'Poukaz', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000, 'vat_rate_id' => $this->rateId(0), 'stock_item_id' => $voucher],
        ]];

        $this->container->get(NewInvoiceVatClassification::class)->apply($body, $this->supplierId);

        self::assertSame('1', $body['vat_classification_code'], 'O hlavičce rozhoduje plnění, ne převažující poukaz.');
        self::assertNull($body['items'][1]['vat_classification_code']);
    }

    public function testVoucherAnalyticIsCreatedUnderAdvanceSyntheticFromPostingRule(): void
    {
        self::assertNull($this->accountRow('324.900'), 'Analytiku zakládá až první poukaz.');
        $this->post($this->invoice([[1000.00, 0.00, 0, 'stored_value']]));

        $analytic = $this->accountRow('324.900');
        self::assertNotNull($analytic);
        self::assertSame(['Prodané poukazy a kredit', '324'], [$analytic['name'], $analytic['parent_code']]);

        // Firma, která přijaté zálohy účtuje jinam, dostane analytiku pod svou syntetikou.
        $this->container->get(PostingRuleRepository::class)->upsertOverride($this->supplierId, 'advance.received.collection', '221', '325', 'Zálohy na 325');
        $byAccount = $this->byAccount($this->post($this->invoice([[500.00, 0.00, 0, 'stored_value']])));
        self::assertEqualsWithDelta(500.00, $byAccount['325.900']['credit'] ?? 0.0, 0.001);
        self::assertSame('325', $this->accountRow('325.900')['parent_code'] ?? null);
    }

    /** Jediná analytika 324 je vyhrazená poukazům: běžná záloha na holé 324 se na ni nepřesměruje. */
    public function testAdvanceOnBare324IsNotRedirectedToVoucherAnalytic(): void
    {
        $this->post($this->invoice([[1000.00, 0.00, 0, 'stored_value']]));

        $entryId = $this->posting->postDocument($this->supplierId, 'manual', 0, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => 700.00],
            ['account_code' => '324', 'side' => 'credit', 'amount' => 700.00],
        ], ['entry_date' => self::DAY, 'description' => 'Záloha z proformy', 'posted_by' => $this->userId]);

        $byAccount = $this->byAccount($entryId);
        self::assertArrayHasKey('324', $byAccount, 'Záloha z proformy zůstává na syntetice.');
        self::assertArrayNotHasKey('324.900', $byAccount);
    }

    public function testRedemptionOverVoucherBalanceWarnsButPays(): void
    {
        $command = $this->container->get(EetPaymentCommand::class);
        $redemption = $this->invoice([[239.67, 50.33, 21, 'standard']]);
        $body = ['invoice_id' => $redemption, 'amount' => '290', 'currency' => 'CZK', 'channel' => 'stored_value', 'contact_context' => 'in_person'];

        $preview = $command->preview($this->supplierId, $body);
        self::assertSame(['voucher_balance_insufficient'], array_column($preview['warnings'], 'code'));

        $result = $command->acceptWithinTransaction($this->supplierId, $this->userId, 'sys:voucher-over-' . $redemption, $body);
        self::assertSame(['voucher_balance_insufficient'], array_column($result['warnings'], 'code'));
        self::assertStringContainsString('jednoúčelový poukaz', $result['warnings'][0]['message']);
        self::assertSame('paid', $this->row('SELECT status FROM invoices WHERE id = ?', [$redemption])['status'], 'Platba neblokuje.');

        $this->post($this->invoice([[1000.00, 0.00, 0, 'stored_value']]));
        $second = $this->invoice([[239.67, 50.33, 21, 'standard']]);
        $covered = $command->acceptWithinTransaction($this->supplierId, $this->userId, 'sys:voucher-ok-' . $second, ['invoice_id' => $second] + $body);
        self::assertSame([], $covered['warnings'], 'Zůstatek 710 Kč na úhradu 290 Kč stačí.');
    }

    public function testClosingShowsVoucherBalanceAndFlagsNegative(): void
    {
        $this->post($this->invoice([[1000.00, 0.00, 0, 'stored_value']]));
        $checks = $this->checks(['deposits_314_324_open', 'voucher_balance_negative', 'clearing_accounts_open']);
        self::assertTrue($checks['deposits_314_324_open']['ok'], 'Nevyčerpané poukazy nejsou zapomenutá záloha.');
        self::assertSame(['account' => '324.900', 'balance' => 1000.0], $checks['deposits_314_324_open']['value']['vouchers']);
        self::assertSame(0.0, $checks['deposits_314_324_open']['value']['324']);
        self::assertTrue($checks['voucher_balance_negative']['ok']);
        self::assertTrue($checks['clearing_accounts_open']['ok'], 'Poukazy nejsou otevřený zůstatek průběžného účtu 324.');

        foreach ([1, 2, 3, 4] as $i) {
            $this->accept($this->invoice([[239.67, 50.33, 21, 'standard']]), 'stored_value', '290');
        }
        $checks = $this->checks(['voucher_balance_negative']);
        self::assertSame('error', $checks['voucher_balance_negative']['severity']);
        self::assertFalse($checks['voucher_balance_negative']['ok'], 'Uplatněno 1 160 Kč z prodaných 1 000 Kč.');
        self::assertSame(-160.0, $checks['voucher_balance_negative']['value']['balance']);
    }

    // ── pomocníci ───────────────────────────────────────────────────────────

    /** @param list<string> $keys @return array<string,array<string,mixed>> */
    private function checks(array $keys): array
    {
        $period = $this->container->get(AccountingPeriodRepository::class)->findById($this->supplierId, $this->periodId);
        $out = [];
        foreach ($this->container->get(ClosingService::class)->buildChecks(
            $this->supplierId, (array) $period, '2026-01-01', '2026-12-31', CheckFindingNormalizer::CAP, $keys,
        ) as $check) {
            $out[$check['key']] = $check;
        }
        return $out;
    }

    /** @return array{name:string, parent_code:?string}|null */
    private function accountRow(string $code): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.name, p.account_code AS parent_code FROM chart_of_accounts a
               LEFT JOIN chart_of_accounts p ON p.id = a.parent_id
              WHERE a.supplier_id = ? AND a.account_code = ?'
        );
        $stmt->execute([$this->supplierId, $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function stockItem(string $kind): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO stock_items (supplier_id, sku, name, eet_item_kind) VALUES (?, ?, ?, ?)')
            ->execute([$this->supplierId, 'VA-' . bin2hex(random_bytes(4)), 'Synthetic ' . $kind, $kind]);
        return (int) $pdo->lastInsertId();
    }

    private function rateId(int $rate): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates WHERE country = 'CZ' AND is_reverse_charge = 0 AND valid_to IS NULL AND rate_percent = ? ORDER BY id LIMIT 1"
        );
        $stmt->execute([$rate]);
        return (int) $stmt->fetchColumn();
    }

    private function accept(int $invoiceId, string $channel, string $amount): void
    {
        $this->container->get(EetPaymentCommand::class)->acceptWithinTransaction($this->supplierId, $this->userId, 'sys:voucher-' . $invoiceId, [
            'invoice_id' => $invoiceId, 'amount' => $amount, 'channel' => $channel, 'contact_context' => 'in_person',
        ]);
    }

    private function post(int $invoiceId): int
    {
        return $this->posting->postDocument(
            $this->supplierId,
            'invoice',
            $invoiceId,
            $this->posting->buildFromInvoice($this->supplierId, $invoiceId),
            ['entry_date' => self::DAY, 'posted_by' => $this->userId],
        );
    }

    /** @param list<array{0:float, 1:float, 2:int, 3:string}> $lines základ, daň, sazba, druh skladové karty */
    private function invoice(array $lines): int
    {
        $pdo = $this->db->pdo();
        $base = round(array_sum(array_column($lines, 0)), 2);
        $vat = round(array_sum(array_column($lines, 1)), 2);
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date, currency_id, reverse_charge,
                 total_without_vat, total_vat, total_with_vat, advance_paid_amount, bank_snapshot, status, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 0, ?, ?, ?, 0, ?, "issued", ?)'
        )->execute([$this->supplierId, (string) random_int(1000000, 9999999), $this->clientId, self::DAY, self::DAY, self::DAY, $this->czk,
            $base, $vat, round($base + $vat, 2), json_encode(['account_number' => '1000000005', 'bank_code' => '0100']), $this->userId]);
        $invoiceId = (int) $pdo->lastInsertId();
        foreach ($lines as $i => [$net, $tax, $rate, $kind]) {
            $pdo->prepare('INSERT INTO stock_items (supplier_id, sku, name, eet_item_kind) VALUES (?, ?, ?, ?)')
                ->execute([$this->supplierId, 'VA-' . $invoiceId . '-' . $i, 'Synthetic ' . $kind, $kind]);
            $stockItemId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                                            total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code, stock_item_id)
                 VALUES (?, ?, 1, "ks", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$invoiceId, 'Synthetic ' . $kind, $net, $this->vat, $rate, $net, $tax, round($net + $tax, 2), $i,
                $rate > 0 ? '1' : null, $stockItemId]);
        }
        return $invoiceId;
    }

    /** @return array<string,array{debit:float,credit:float}> */
    private function byAccount(int $entryId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.account_code, l.side, l.amount FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ?'
        );
        $stmt->execute([$entryId]);
        $out = [];
        $balance = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $code = (string) $line['account_code'];
            $out[$code] ??= ['debit' => 0.0, 'credit' => 0.0];
            $out[$code][$line['side']] += (float) $line['amount'];
            $balance += ($line['side'] === 'debit' ? 1 : -1) * (int) round((float) $line['amount'] * 100);
        }
        self::assertSame(0, $balance, 'Σ MD == Σ D (v haléřích).');
        self::assertNotSame([], $out);
        return $out;
    }

    /** @param array<string,array{debit:float,credit:float}> $byAccount */
    private function vatCredit(array $byAccount): float
    {
        $sum = 0.0;
        foreach ($byAccount as $code => $sides) {
            if (str_starts_with((string) $code, '343')) {
                $sum += $sides['credit'] - $sides['debit'];
            }
        }
        return $sum;
    }

    /** @param list<string> $codes @return list<string> */
    private function sorted(array $codes): array
    {
        $codes = array_values(array_unique(array_map('strval', $codes)));
        sort($codes);
        return $codes;
    }

    /** @param list<mixed> $params @return array<string,mixed> */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
