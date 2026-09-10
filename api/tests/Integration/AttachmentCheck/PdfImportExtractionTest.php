<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\AttachmentCheck;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AiPdfExtractor;
use MyInvoice\Service\Import\ClientResolver;
use MyInvoice\Tests\Support\FakeLlmGateway;
use PDO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AI import PDF přijaté faktury si vytěžení PDF, ze kterého doklad vznikl, uloží
 * stejně jako připojení skenů — jinak by kontrola dokladů proti přílohám měla data
 * jen u skenů. Koncovka karty z účtenky se propíše na doklad.
 */
#[Group('integration')]
#[AllowMockObjectsWithoutExpectations]
final class PdfImportExtractionTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private \DI\Container $c;
    private int $sid = 0;
    private int $userId = 0;
    private int $vendorId = 0;
    private string $ownIco = '';
    private ?int $invoiceId = null;
    private string $sha = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            self::markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildApp()->getContainer();
            if (!$container instanceof \DI\Container) {
                self::markTestSkipped('Kontejner neumí make().');
            }
            $this->c = $container;
            $this->db = $this->c->get(Connection::class);
        } catch (\Throwable $e) {
            self::markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }
        $this->pdo = $this->db->pdo();
        $this->sid = (int) ($this->pdo->query('SELECT id FROM supplier WHERE is_vat_payer = 1 AND ic IS NOT NULL ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($this->pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->ownIco = (string) ($this->pdo->query("SELECT ic FROM supplier WHERE id = {$this->sid}")->fetchColumn() ?: '');
        $currency = (int) ($this->pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->sid} AND code = 'CZK' LIMIT 1")->fetchColumn() ?: 0);
        $country = (int) ($this->pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($this->sid === 0 || $this->userId === 0 || $this->ownIco === '' || $currency === 0 || $country === 0) {
            self::markTestSkipped('Chybí plátce DPH s IČO, uživatel, CZK nebo země.');
        }
        $this->pdo->prepare(
            "INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, currency_default_id, ic, main_email, is_customer, is_vendor, is_vat_payer)
             VALUES (?, 'Syntetická čerpací stanice s.r.o.', 'Testovací 2', 'Brno', '60200', ?, ?, '11220044', 'stanice@example.test', 0, 1, 1)"
        )->execute([$this->sid, $country, $currency]);
        $this->vendorId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->invoiceId !== null) {
            $this->pdo->exec("DELETE FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$this->invoiceId}");
            $this->pdo->exec("DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = {$this->invoiceId}");
            $this->pdo->exec("DELETE FROM purchase_invoices WHERE id = {$this->invoiceId}");
        }
        if ($this->sha !== '') {
            $this->pdo->prepare('DELETE FROM document_extractions WHERE sha256 = ?')->execute([$this->sha]);
        }
        if ($this->vendorId > 0) {
            $this->pdo->exec("DELETE FROM clients WHERE id = {$this->vendorId}");
        }
        $this->db->close();
    }

    public function testImportStoresExtractionOfSourcePdfAndCardLast4(): void
    {
        $pdf = "%PDF-1.4\n% SYNTHETIC-IMPORT-" . bin2hex(random_bytes(6)) . "\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
        $this->sha = hash('sha256', $pdf);
        $number = 'AICHK-IMP-' . strtoupper(bin2hex(random_bytes(3)));

        $llm = new FakeLlmGateway(fn (string $bytes): array => [
            'vendor' => ['company_name' => 'Syntetická čerpací stanice s.r.o.', 'ic' => '11220044', 'dic' => null, 'is_vat_payer' => true],
            'customer' => ['company_name' => 'Syntetický odběratel', 'ic' => $this->ownIco, 'dic' => null],
            'vendor_invoice_number' => $number,
            'document_kind' => 'invoice',
            'issue_date' => '2091-06-15',
            'tax_date' => '2091-06-15',
            'due_date' => '2091-06-15',
            'currency' => 'CZK',
            'total_without_vat' => 1000.0,
            'total_with_vat' => 1210.0,
            'payment' => ['variable_symbol' => '20910077'],
            'card_last4' => '**** **** **** 4242',
            'company_role' => null,
            'items' => [['description' => 'Syntetické zboží', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 1000.0, 'vat_rate' => 21]],
        ]);
        $resolver = $this->createMock(ClientResolver::class);
        $resolver->method('resolveVendor')->willReturn(['id' => $this->vendorId, 'created' => false, 'role_added' => false, 'is_vat_payer' => true]);

        $extractor = $this->c->make(AiPdfExtractor::class, ['anthropic' => $llm, 'clientResolver' => $resolver]);
        $res = $extractor->extractAndCreate($this->sid, $this->userId, $pdf, null, 'uctenka.pdf');
        self::assertTrue($res['ok'], (string) ($res['error'] ?? ''));
        $this->invoiceId = (int) $res['purchase_invoice_id'];

        $pi = $this->pdo->query("SELECT pdf_hash, card_last4 FROM purchase_invoices WHERE id = {$this->invoiceId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame($this->sha, $pi['pdf_hash']);
        self::assertSame('4242', $pi['card_last4'], 'koncovka karty z vytěžení je na dokladu');

        $ext = $this->pdo->prepare("SELECT * FROM document_extractions WHERE supplier_id = ? AND sha256 = ? AND status = 'ok'");
        $ext->execute([$this->sid, $this->sha]);
        $row = $ext->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'vytěžení zdrojového PDF je uložené');
        self::assertSame('2091-06-15', $row['tax_date']);
        self::assertSame('1210.00', $row['total_with_vat']);
        self::assertSame('buyer', $row['company_role'], 'import je vždy přijatý doklad');
        self::assertSame('4242', $row['card_last4']);

        $check = $this->pdo->query("SELECT status, findings FROM attachment_checks WHERE entity_type = 'purchase_invoice' AND entity_id = {$this->invoiceId}")->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($check, 'doklad je po importu rovnou zkontrolovaný proti svému PDF');
        self::assertSame('match', $check['status'], 'rozdíly: ' . $check['findings']);
    }
}
