<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalDraftToTaxDocument;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentNotApplicableException;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\ProformaPaymentDocuments;
use MyInvoice\Service\Migration\MoneyS3\IssuedPaymentRecorder;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPayments;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Firma s režimem „vyúčtovací faktura po doplacení zálohy" dostane po úhradě koncept
 * faktury. Je-li záloha jen akontací na budoucí plnění, mění ho uživatel na daňový
 * doklad k přijaté platbě — bez ručního mazání a hledání tlačítka na zálohové faktuře.
 */
#[Group('integration')]
final class FinalDraftToTaxDocumentTest extends TestCase
{
    private const MARKER = '__final_draft_to_tax_doc_test__';
    private const PAID_ON = '2098-04-10';

    private Connection $db;
    private InvoicePaymentService $payments;
    private FinalDraftToTaxDocument $converter;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private ?string $originalMode = null;
    /** @var array<string,mixed>|null */
    private ?array $originalVatStatus = null;

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildContainer();
            $this->db        = $c->get(Connection::class);
            $this->payments  = $c->get(InvoicePaymentService::class);
            $this->converter = $c->get(FinalDraftToTaxDocument::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí dodavatel.');
        }
        $this->vatRateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE UPPER(COALESCE(country, 'CZ')) = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->vatRateId === 0) {
            $this->markTestSkipped('Chybí sazby DPH.');
        }

        $stmt = $pdo->prepare('SELECT proforma_payment_document FROM supplier WHERE id = ?');
        $stmt->execute([$this->supplierId]);
        $this->originalMode = (string) $stmt->fetchColumn();
        $pdo->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
            ->execute([ProformaPaymentDocuments::MODE_FINAL_ON_FULL_PAYMENT, $this->supplierId]);

        $status = $pdo->prepare(
            'SELECT is_vat_payer, is_identified FROM supplier_vat_status_history
              WHERE supplier_id = ? AND effective_from = ?'
        );
        $status->execute([$this->supplierId, self::PAID_ON]);
        $this->originalVatStatus = $status->fetch(PDO::FETCH_ASSOC) ?: null;
        $pdo->prepare(
            'INSERT INTO supplier_vat_status_history (supplier_id, effective_from, is_vat_payer, is_identified)
             VALUES (?, ?, 1, 0)
             ON DUPLICATE KEY UPDATE is_vat_payer = 1, is_identified = 0'
        )->execute([$this->supplierId, self::PAID_ON]);

        $this->currencyId = $this->currency();
        $this->clientId   = $this->client();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        if ($this->originalMode !== null) {
            $pdo->prepare('UPDATE supplier SET proforma_payment_document = ? WHERE id = ?')
                ->execute([$this->originalMode, $this->supplierId]);
        }
        if ($this->originalVatStatus === null) {
            $pdo->prepare('DELETE FROM supplier_vat_status_history WHERE supplier_id = ? AND effective_from = ?')
                ->execute([$this->supplierId, self::PAID_ON]);
        } else {
            $pdo->prepare(
                'UPDATE supplier_vat_status_history SET is_vat_payer = ?, is_identified = ?
                  WHERE supplier_id = ? AND effective_from = ?'
            )->execute([
                $this->originalVatStatus['is_vat_payer'], $this->originalVatStatus['is_identified'],
                $this->supplierId, self::PAID_ON,
            ]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)')
                ->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ? AND parent_invoice_id IS NOT NULL')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM client_revenue_cache WHERE client_id = ?')->execute([$this->clientId]);
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        $this->db->close();
    }

    public function testDraftFinalIsReplacedByTaxDocumentForPayment(): void
    {
        $proformaId = $this->proforma(70000.0);
        $result = $this->payments->recordPayment($proformaId, 84700.0, self::PAID_ON);
        $draftId = (int) ($result['final_draft_id'] ?? 0);
        self::assertGreaterThan(0, $draftId, 'Režim vyúčtovací faktury musí po doplacení založit koncept.');

        $converted = $this->converter->convert($draftId, 0);

        self::assertSame($proformaId, $converted['proforma_id']);
        self::assertCount(1, $converted['tax_document_ids']);
        self::assertSame(0, $this->countChildren($proformaId, 'invoice'), 'Koncept faktury musí zmizet.');

        $taxDocId = $converted['tax_document_ids'][0];
        $doc = $this->db->pdo()->prepare(
            'SELECT invoice_type, status, parent_invoice_id, tax_date, total_without_vat, total_vat, total_with_vat
               FROM invoices WHERE id = ?'
        );
        $doc->execute([$taxDocId]);
        $row = $doc->fetch(PDO::FETCH_ASSOC);
        self::assertSame('tax_document', $row['invoice_type']);
        self::assertSame('draft', $row['status']);
        self::assertSame($proformaId, (int) $row['parent_invoice_id']);
        self::assertSame(self::PAID_ON, substr((string) $row['tax_date'], 0, 10), 'DUZP = den přijetí platby.');
        self::assertEqualsWithDelta(84700.0, (float) $row['total_with_vat'], 0.01);
        self::assertEqualsWithDelta(70000.0, (float) $row['total_without_vat'], 0.01);
        self::assertEqualsWithDelta(14700.0, (float) $row['total_vat'], 0.01);

        $payment = $this->payments->findPayment((int) $result['payment_id']);
        self::assertSame($taxDocId, (int) ($payment['tax_document_invoice_id'] ?? 0), 'Platba musí ukazovat na daňový doklad.');
    }

    /** Vystavená faktura už plnění zdanila — nahradit ji jde jen stornem. */
    public function testIssuedFinalIsRefused(): void
    {
        $proformaId = $this->proforma(70000.0);
        $result = $this->payments->recordPayment($proformaId, 84700.0, self::PAID_ON);
        $draftId = (int) ($result['final_draft_id'] ?? 0);
        $this->db->pdo()->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$draftId]);

        try {
            $this->converter->convert($draftId, 0);
            self::fail('Vystavenou fakturu nesmí jít změnit na daňový doklad.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('koncept', $e->getMessage());
        }
        self::assertSame(0, $this->countChildren($proformaId, 'tax_document'));
        self::assertSame(1, $this->countChildren($proformaId, 'invoice'));
    }

    /**
     * Úhrada minulého roku převzatá z POHODY: daň z ní přiznala POHODA. Daňový doklad k ní
     * nevznikne ručně ani převodem konceptu, ten zdaní jen platbu z MyÚčta.
     */
    public function testPriorYearPohodaPaymentGetsNoTaxDocument(): void
    {
        $proformaId = $this->proforma(70000.0);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source) VALUES (?, ?, ?, 24200, ?, ?, ?)')
            ->execute([$this->supplierId, $proformaId, '2098-03-31', 'CZK', PohodaPriorYearPayments::NOTE, PohodaPriorYearPayments::SOURCE]);
        $prior = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET paid_total = 24200 WHERE id = ?')->execute([$proformaId]);
        $creator = Bootstrap::buildContainer()->get(PaymentTaxDocumentCreator::class);
        try {
            $creator->createForPayment($prior);
            self::fail('K úhradě minulého roku z POHODY nesmí vzniknout daňový doklad.');
        } catch (PaymentTaxDocumentNotApplicableException $e) {
            self::assertStringContainsString('POHODY', $e->getMessage());
        }

        $result = $this->payments->recordPayment($proformaId, 60500.0, self::PAID_ON);
        $draftId = (int) ($result['final_draft_id'] ?? 0);
        self::assertGreaterThan(0, $draftId);
        self::assertSame([(int) $result['payment_id']], array_column($this->converter->pendingPayments($proformaId), 'id'));
        $converted = $this->converter->convert($draftId, 0);
        self::assertCount(1, $converted['tax_document_ids']);
        self::assertNull($this->payments->findPayment($prior)['tax_document_invoice_id']);
    }

    /**
     * Platba zálohy převzatá z Money S3 v zamčeném období: daň z ní přiznala Money. Daňový
     * doklad k ní nevznikne ručně ani převodem konceptu, ten zdaní jen platbu z MyÚčta.
     */
    public function testMoneyS3PaymentGetsNoTaxDocument(): void
    {
        $proformaId = $this->proforma(70000.0);
        $pdo = $this->db->pdo();
        $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source) VALUES (?, ?, ?, 24200, ?, ?, ?)')
            ->execute([$this->supplierId, $proformaId, '2098-03-31', 'CZK', IssuedPaymentRecorder::NOTE_PREFIX . 'úhrada BP98001', 'bank']);
        $money = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE invoices SET paid_total = 24200 WHERE id = ?')->execute([$proformaId]);
        // Záloha z mapy převodu, platba v zamčeném období. Test běží nad sdílenou firmou bez
        // transakce: zámek a mapa se po kontrole vrátí, jinak by zamkly ostatní testy.
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$this->supplierId]);
        $lockedBefore = $lock->fetchColumn();
        $pdo->prepare("INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id) VALUES (?, 'invoice', ?, ?)")
            ->execute([$this->supplierId, '2098|ZV' . $proformaId, $proformaId]);
        $pdo->prepare("INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, '2098-03-31')
                        ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)")->execute([$this->supplierId]);
        try {
            $creator = Bootstrap::buildContainer()->get(PaymentTaxDocumentCreator::class);
            try {
                $creator->createForPayment($money);
                self::fail('K platbě zálohy převzaté z Money S3 nesmí vzniknout daňový doklad.');
            } catch (PaymentTaxDocumentNotApplicableException $e) {
                self::assertStringContainsString('zdaněná', $e->getMessage());
            }

            // Nová platba po datu zámku DDKP dostane.
            $result = $this->payments->recordPayment($proformaId, 60500.0, self::PAID_ON);
            $draftId = (int) ($result['final_draft_id'] ?? 0);
            self::assertGreaterThan(0, $draftId);
            self::assertSame([(int) $result['payment_id']], array_column($this->converter->pendingPayments($proformaId), 'id'));
            $converted = $this->converter->convert($draftId, 0);
            self::assertCount(1, $converted['tax_document_ids']);
            self::assertNull($this->payments->findPayment($money)['tax_document_invoice_id']);
        } finally {
            $pdo->prepare('UPDATE accounting_supplier_settings SET locked_until = ? WHERE supplier_id = ?')
                ->execute([$lockedBefore === false ? null : $lockedBefore, $this->supplierId]);
            $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'invoice' AND target_id = ?")
                ->execute([$this->supplierId, $proformaId]);
        }
    }

    /** Koncept faktury bez zálohové faktury (běžná faktura) se nemění. */
    public function testDraftWithoutProformaIsRefused(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices (invoice_type, client_id, supplier_id, issue_date, tax_date, due_date, currency_id, status, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, 'draft', NULL)"
        )->execute([$this->clientId, $this->supplierId, self::PAID_ON, self::PAID_ON, self::PAID_ON, $this->currencyId]);
        $draftId = (int) $pdo->lastInsertId();

        $this->expectException(\RuntimeException::class);
        $this->converter->convert($draftId, 0);
    }

    private function countChildren(int $proformaId, string $type): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM invoices WHERE parent_invoice_id = ? AND invoice_type = ?');
        $stmt->execute([$proformaId, $type]);

        return (int) $stmt->fetchColumn();
    }

    private function proforma(float $base): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_vat, total_with_vat, paid_total, created_by)
             VALUES ('proforma', ?, ?, ?, ?, ?, ?, ?, 'issued', ?, ?, ?, 0, NULL)"
        )->execute([
            '9' . random_int(1000000, 9999999), $this->clientId, $this->supplierId,
            self::PAID_ON, self::PAID_ON, self::PAID_ON, $this->currencyId,
            $base, round($base * 0.21, 2), round($base * 1.21, 2),
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, item_kind)
             VALUES (?, "Akontace zakázky", 1, "ks", ?, ?, 21, ?, ?, ?, 0, "standard")'
        )->execute([$id, $base, $this->vatRateId, $base, round($base * 0.21, 2), round($base * 1.21, 2)]);

        return $id;
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
             VALUES (?, ?, "Testovaci 1", "Praha", "11000", ?, "final-draft-td@example.test", "cs", ?, 1, 0)'
        )->execute([$this->supplierId, self::MARKER, $countryId, $this->currencyId]);

        return (int) $pdo->lastInsertId();
    }
}
