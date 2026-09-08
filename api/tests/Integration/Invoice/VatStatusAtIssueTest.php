<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\IssueInvoiceAction;
use MyInvoice\Action\Invoice\RebuildInvoiceSnapshotsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Plátcovství DPH v čase (EPIC VH-02/VH-03) — vznik dokladů podle stavu K DATU.
 *
 * Firma je plátcem do $cutoffDate (exkluzivně), pak neplátcem; živá cache
 * supplier.is_vat_payer proto říká „neplátce". Doklady se ale posuzují k svému
 * ROZHODNÉMU datu (tax_date ?? issue_date, u platby paid_on):
 *
 *   (a) zpětně datovaná faktura do období plátcovství → vystaví se s DPH
 *       a supplier_snapshot mrazí is_vat_payer=true (z historie, ne z cache),
 *   (b) faktura s DPH v období neplátcovství → 422,
 *   (c) rebuild snapshotů nemění historický stav (supplier ani klient),
 *   (d) DDKP se nevystaví k platbě přijaté v období neplátcovství
 *       (a naopak vystaví k platbě z období plátcovství i přes živou cache),
 *   (e) client_snapshot nově nese is_vat_payer klienta.
 *
 * Izolovaný supplier BEZ obalové transakce — IssueInvoiceAction si řídí vlastní
 * transakci (SET TRANSACTION + beginTransaction, vnořená by spadla). Úklid je
 * explicitní v tearDown v pořadí FK závislostí (supplier FK jsou RESTRICT).
 */
#[Group('integration')]
final class VatStatusAtIssueTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private IssueInvoiceAction $issueAction;
    private RebuildInvoiceSnapshotsAction $rebuildAction;
    private PaymentTaxDocumentCreator $ddkp;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;

    /** Datum uvnitř období plátcovství (dnes − 45 dní). */
    private string $payerDate = '';
    /** Od tohoto data (dnes − 30 dní) firma NENÍ plátce. */
    private string $cutoffDate = '';
    /** Datum uvnitř období neplátcovství (dnes − 15 dní). */
    private string $nonPayerDate = '';

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            $this->db = $container->get(Connection::class);
            $this->issueAction = $container->get(IssueInvoiceAction::class);
            $this->rebuildAction = $container->get(RebuildInvoiceSnapshotsAction::class);
            $this->ddkp = $container->get(PaymentTaxDocumentCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $sourceSupplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query(
            'SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 ORDER BY valid_from DESC LIMIT 1'
        )->fetchColumn() ?: 0);
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($sourceSupplierId === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $czId === 0) {
            $this->markTestSkipped('Chybí základní data (supplier/user/currency/vat_rate/country).');
        }

        $today = new \DateTimeImmutable('today');
        $this->payerDate    = $today->modify('-45 days')->format('Y-m-d');
        $this->cutoffDate   = $today->modify('-30 days')->format('Y-m-d');
        $this->nonPayerDate = $today->modify('-15 days')->format('Y-m-d');

        $this->supplierId = $this->createIsolatedSupplier($pdo, $sourceSupplierId);
        // Hermetika: bez skladu a bez auto-postu — testujeme gate/snapshoty, ne účtování.
        $pdo->prepare("UPDATE supplier SET stock_enabled = 0, accounting_mode = 'tax_evidence' WHERE id = ?")
            ->execute([$this->supplierId]);

        // Plátce od nepaměti, neplátce od cutoffDate → živá cache = neplátce.
        $this->setVatPayerAt($pdo, $this->supplierId, '1900-01-01', true);
        $this->setVatPayerAt($pdo, $this->supplierId, $this->cutoffDate, false);

        $client = $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, currency_default_id,
                 ic, dic, main_email, language, is_customer, is_vendor, is_vat_payer)
             VALUES (?, "Odběratel plátce s.r.o.", "Testovací 1", "Praha", "11000", ?, ?, "12345678",
                     "CZ12345678", "odberatel@example.com", "cs", 1, 0, 1)'
        );
        $client->execute([$this->supplierId, $czId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->supplierId === 0) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $sid = $this->supplierId;
        // Pořadí dle FK (supplier FK jsou RESTRICT): položky → platby → DDKP → faktury
        // → čítače → klienti → supplier (historie plátcovství cascaduje).
        $pdo->prepare('DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id WHERE i.supplier_id = ?')
            ->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_payments WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare("DELETE FROM invoices WHERE supplier_id = ? AND invoice_type = 'tax_document'")->execute([$sid]);
        $pdo->prepare('DELETE FROM invoices WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM invoice_counters WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM clients WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM supplier WHERE id = ?')->execute([$sid]);
        $this->db->close();
    }

    // (a) + (e) — zpětně datovaná faktura do období plátcovství projde s DPH,
    // snapshot mrazí historický stav plátce a client_snapshot nese is_vat_payer.
    public function testBackdatedInvoiceIntoPayerPeriodFreezesPayerSnapshot(): void
    {
        $live = $this->db->pdo()->prepare('SELECT is_vat_payer FROM supplier WHERE id = ?');
        $live->execute([$this->supplierId]);
        self::assertSame(0, (int) $live->fetchColumn(), 'Živá cache musí říkat „neplátce" — jinak test nic nedokazuje.');

        $id = $this->draftInvoice('invoice', $this->payerDate, $this->payerDate);
        $res = $this->issue($id);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));

        $row = $this->invoiceRow($id);
        $supplierSnap = json_decode((string) $row['supplier_snapshot'], true);
        self::assertIsArray($supplierSnap);
        self::assertTrue($supplierSnap['is_vat_payer'], 'Snapshot má nést stav k rozhodnému datu (plátce), ne dnešní cache.');

        $clientSnap = json_decode((string) $row['client_snapshot'], true);
        self::assertIsArray($clientSnap);
        self::assertArrayHasKey('is_vat_payer', $clientSnap, 'client_snapshot nově nese plátcovství klienta.');
        self::assertTrue($clientSnap['is_vat_payer']);
    }

    // (b) — DPH > 0 v období neplátcovství → 422 (server-side vynucení sazeb).
    public function testInvoiceWithVatInNonPayerPeriodIsRejected(): void
    {
        $id = $this->draftInvoice('invoice', $this->nonPayerDate, $this->nonPayerDate);
        $res = $this->issue($id);

        self::assertSame(422, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('not_vat_payer_at_date', $res['body']['error']['code'] ?? null);
        self::assertSame('draft', $this->invoiceRow($id)['status'], 'Faktura musí zůstat konceptem.');
    }

    // Reverse charge doklad daň nenese a nesmí být gate-em blokován (IO/RC výjimka).
    public function testReverseChargeWithoutVatIsNotBlockedInNonPayerPeriod(): void
    {
        $id = $this->draftInvoice('invoice', $this->nonPayerDate, $this->nonPayerDate, vat: 0.0, reverseCharge: true);
        $res = $this->issue($id);
        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
    }

    // (c) — rebuild snapshotů vystaveného dokladu nemění historický stav:
    // supplier část se staví k datu DOKLADU, client část drží stávající snapshot.
    public function testRebuildSnapshotsKeepsHistoricalVatStatus(): void
    {
        $id = $this->draftInvoice('invoice', $this->payerDate, $this->payerDate);
        self::assertSame(200, $this->issue($id)['status']);

        // Klient mezitím přestal být plátcem (živá data) — rebuild to nesmí propsat.
        $this->db->pdo()->prepare('UPDATE clients SET is_vat_payer = 0 WHERE id = ?')
            ->execute([$this->clientId]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/rebuild-snapshots')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = ($this->rebuildAction)($request, new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(200, $response->getStatusCode());

        $row = $this->invoiceRow($id);
        $supplierSnap = json_decode((string) $row['supplier_snapshot'], true);
        self::assertIsArray($supplierSnap);
        self::assertTrue($supplierSnap['is_vat_payer'], 'Rebuild staví supplier stav k rozhodnému datu dokladu.');

        $clientSnap = json_decode((string) $row['client_snapshot'], true);
        self::assertIsArray($clientSnap);
        self::assertTrue($clientSnap['is_vat_payer'], 'Rebuild u vystaveného dokladu drží plátcovství klienta ze stávajícího snapshotu.');
    }

    // (d) — DDKP se nevystaví k platbě přijaté v období neplátcovství.
    public function testPaymentTaxDocumentRefusedForPaymentInNonPayerPeriod(): void
    {
        $proformaId = $this->issuedProforma();
        $paymentId = $this->payment($proformaId, $this->nonPayerDate, 605.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plátce DPH');
        $this->ddkp->createForPayment($paymentId, $this->userId);
    }

    // (d) kontrapříklad — platba z období plátcovství DDKP založí i přes živou
    // cache „neplátce" (rozhoduje paid_on, ne dnešek).
    public function testPaymentTaxDocumentCreatedForPaymentInPayerPeriod(): void
    {
        $proformaId = $this->issuedProforma();
        $paymentId = $this->payment($proformaId, $this->payerDate, 605.0);

        $taxDocId = $this->ddkp->createForPayment($paymentId, $this->userId);
        self::assertGreaterThan(0, $taxDocId);

        $stmt = $this->db->pdo()->prepare('SELECT invoice_type, tax_date FROM invoices WHERE id = ?');
        $stmt->execute([$taxDocId]);
        $row = (array) $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tax_document', $row['invoice_type'] ?? null);
        self::assertSame($this->payerDate, (string) ($row['tax_date'] ?? ''), 'DUZP = den přijetí úplaty.');
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private function issue(int $id): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/invoices/' . $id . '/issue')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin']);
        $response = ($this->issueAction)($request, new Psr7Response(), ['id' => (string) $id]);
        $response->getBody()->rewind();
        $decoded = json_decode((string) $response->getBody(), true);
        return ['status' => $response->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @return array<string,mixed> */
    private function invoiceRow(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, varsymbol, supplier_snapshot, client_snapshot FROM invoices WHERE id = ?'
        );
        $stmt->execute([$id]);
        return (array) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function testVendorBecomesCustomerOnlyAfterSuccessfulIssue(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE clients SET is_customer = 0, is_vendor = 1 WHERE id = ?')->execute([$this->clientId]);
        $id = $this->draftInvoice('invoice', $this->payerDate, $this->payerDate);
        $flags = fn (): array => $pdo->query('SELECT is_customer, is_vendor FROM clients WHERE id = ' . $this->clientId)->fetch(PDO::FETCH_ASSOC);
        self::assertSame(0, (int) $flags()['is_customer']);
        $res = $this->issue($id);
        self::assertSame(200, $res['status'], json_encode($res['body']));
        self::assertSame(1, (int) $flags()['is_customer']);
        self::assertSame(1, (int) $flags()['is_vendor']);
    }

    public function testRejectedIssueDoesNotPromoteVendor(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE clients SET is_customer = 0, is_vendor = 1 WHERE id = ?')->execute([$this->clientId]);
        $id = $this->draftInvoice('invoice', $this->nonPayerDate, $this->nonPayerDate);
        self::assertSame(422, $this->issue($id)['status']);
        self::assertSame(0, (int) $pdo->query('SELECT is_customer FROM clients WHERE id = ' . $this->clientId)->fetchColumn());
    }

    private function draftInvoice(
        string $type,
        string $issueDate,
        ?string $taxDate,
        float $vat = 210.0,
        bool $reverseCharge = false,
    ): int {
        $pdo = $this->db->pdo();
        $withVat = 1000.0 + $vat;
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, reverse_charge, language, status,
                 total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "cs", "draft", 1000, ?, ?, ?)'
        )->execute([
            $this->supplierId,
            'VHT-' . substr(bin2hex(random_bytes(6)), 0, 12),
            $type,
            $this->clientId,
            $issueDate,
            $taxDate,
            $issueDate,
            $this->currencyId,
            $reverseCharge ? 1 : 0,
            $vat,
            $withVat,
            $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Služby", 1, "ks", 1000, ?, ?, 1000, ?, ?, 0)'
        )->execute([$id, $this->vatRateId, $vat > 0 ? 21 : 0, $vat, $withVat]);

        return $id;
    }

    /** Vystavená proforma s jednou 21% položkou (podklad pro DDKP). */
    private function issuedProforma(): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, due_date,
                 currency_id, reverse_charge, language, status,
                 total_without_vat, total_vat, total_with_vat, created_by)
             VALUES (?, ?, "proforma", ?, ?, ?, ?, 0, "cs", "issued", 1000, 210, 1210, ?)'
        )->execute([
            $this->supplierId,
            'VHP-' . substr(bin2hex(random_bytes(6)), 0, 12),
            $this->clientId,
            $this->payerDate,
            $this->payerDate,
            $this->currencyId,
            $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, "Záloha na služby", 1, "ks", 1000, ?, 21, 1000, 210, 1210, 0)'
        )->execute([$id, $this->vatRateId]);

        return $id;
    }

    private function payment(int $invoiceId, string $paidOn, float $amount): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments
                (supplier_id, invoice_id, paid_on, amount, currency, source, created_by)
             VALUES (?, ?, ?, ?, "CZK", "manual", ?)'
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount, $this->userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
