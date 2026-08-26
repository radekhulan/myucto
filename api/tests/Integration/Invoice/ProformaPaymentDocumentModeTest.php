<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #39 — co má vzniknout po DOPLACENÍ zálohové faktury.
 *
 * „Doplacená proforma" není totéž co „uskutečněné plnění": u zakázkové výroby je
 * proforma dílčí akontace na budoucí dílo (70 000 Kč ze zakázky za 100 000 Kč),
 * takže její plná úhrada nic nedokončuje a odběratel potřebuje daňový doklad
 * k přijaté platbě. U rychlého prodeje naopak proforma kryje celou objednávku
 * a vyúčtovací faktura je správně. Volí to `supplier.proforma_payment_document`
 * (migrace 1565).
 *
 * Test hlídá právě to rozhodnutí, ne vznik DDKP jako takový: ten má vlastní
 * podmínky (plátcovství DPH, ne-RC) a testuje se jinde. Podstatné je, že
 * v režimu `always_tax_document` finální faktura NEVZNIKNE — protože právě
 * ta uzavírala zakázku, kterou dodavatel teprve začne vyrábět.
 */
#[Group('integration')]
final class ProformaPaymentDocumentModeTest extends TestCase
{
    private const MARKER = '__proforma_mode_test__';

    private Connection $db;
    private FinalFromProformaCreator $finalCreator;
    private PaymentTaxDocumentCreator $taxDocCreator;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private ?string $originalMode = null;
    private bool $columnExists = false;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db            = $c->get(Connection::class);
            $this->finalCreator  = $c->get(FinalFromProformaCreator::class);
            $this->taxDocCreator = $c->get(PaymentTaxDocumentCreator::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }

        try {
            $stmt = $pdo->prepare('SELECT proforma_payment_document FROM supplier WHERE id = ?');
            $stmt->execute([$this->supplierId]);
            $this->originalMode = (string) $stmt->fetchColumn();
            $this->columnExists = true;
        } catch (\PDOException) {
            $this->markTestSkipped('Migrace 1565 zatím neproběhla.');
        }

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->columnExists && $this->originalMode !== null) {
            $pdo->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
                ->execute([$this->originalMode, $this->supplierId]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    /** Výchozí režim = dnešní chování; existujícím firmám se nesmí nic změnit pod rukama. */
    public function testFullPaymentCreatesFinalInvoiceInDefaultMode(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT);
        $proformaId = $this->proforma();

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $proformaId,
            'proforma',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNotNull($result['final_draft_id'], 'Rychlý prodej má dál dostat vyúčtovací fakturu.');
        self::assertSame(1, $this->countChildren($proformaId, 'invoice'));
    }

    /**
     * BEZ OPRAVY PADÁ: zakázková výroba dostávala vyúčtovací fakturu na nepředané
     * dílo, kterou účetní odběratele odmítne převzít (issue #39).
     */
    public function testFullPaymentCreatesNoFinalInvoiceInTaxDocumentMode(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT);
        $proformaId = $this->proforma();

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $proformaId,
            'proforma',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNull($result['final_draft_id'], 'Zakázka se nesmí uzavřít zálohou.');
        self::assertSame(0, $this->countChildren($proformaId, 'invoice'));
    }

    /** Režim se čte sám z firmy — volající ho nemusí (a nesmí muset) předávat. */
    public function testModeIsResolvedFromSupplier(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT);
        $proformaId = $this->proforma();

        self::assertSame(
            ProformaPaymentDocuments::MODE_ALWAYS_TAX_DOCUMENT,
            ProformaPaymentDocuments::modeForInvoice($this->db->pdo(), $proformaId),
        );
    }

    /** Jiný typ dokladu než proforma se automatiky netýká. */
    public function testNonProformaIsUntouched(): void
    {
        $this->setMode(ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT);

        $result = ProformaPaymentDocuments::afterPayment(
            $this->finalCreator,
            $this->taxDocCreator,
            $this->proforma(),
            'invoice',
            true,
            null,
            0,
            '2098-03-10',
            $this->db->pdo(),
        );

        self::assertNull($result['final_draft_id']);
        self::assertNull($result['tax_document_id']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function setMode(string $mode): void
    {
        $this->db->pdo()
            ->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
            ->execute([$mode, $this->supplierId]);
    }

    private function countChildren(int $proformaId, string $type): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = ? AND invoice_type = ?'
        );
        $stmt->execute([$proformaId, $type]);

        return (int) $stmt->fetchColumn();
    }

    private function proforma(): int
    {
        $pdo = $this->db->pdo();
        $d = '2098-03-10';
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, paid_total, created_by)
             VALUES ('proforma', ?, ?, ?, ?, ?, ?, ?, 'issued', 70000.00, 84700.00, 0, NULL)"
        )->execute([
            '9' . random_int(1000000, 9999999),
            $this->clientId, $this->supplierId, $d, $d, $d, $this->currencyId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function currency(): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM currencies WHERE supplier_id = ? AND is_active = 1
              ORDER BY (code = 'CZK') DESC, is_default DESC, id LIMIT 1"
        );
        $stmt->execute([$this->supplierId]);
        $id = (int) $stmt->fetchColumn();
        if ($id === 0) {
            self::markTestSkipped('Dodavatel nemá aktivní měnu.');
        }

        return $id;
    }

    private function client(): int
    {
        $pdo = $this->db->pdo();
        $countryId = (int) ($pdo->query("SELECT id FROM countries WHERE UPPER(iso2) = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($countryId === 0) {
            self::markTestSkipped('Stát CZ není v číselníku zemí.');
        }
        $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Testovaci 1", "Praha", "11000", ?,
                     "proforma-mode@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::MARKER, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
