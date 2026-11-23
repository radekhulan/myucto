<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\Check\Checks\PurchaseSettledUncoveredCheck;
use MyInvoice\Service\Migration\Check\Checks\SettledWithoutPaymentCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Kontroly 1 a 6 nad firmou převedenou z Money S3 ({@see SyntheticAgenda::filesWithSettledWithoutPaymentDocument()}):
 * náhled ukáže všechny změny, které oprava převodu Money S3 opravdu udělá (i doklad s nulovým
 * rozdílem, kterému jen nahradí úhradu mimo evidenci zápočtem, a vazbu platby zálohy na DDKP),
 * a oprava nezapíše nic, co leží za zámkem nebo v podaném období DPH.
 */
final class MoneyS3MigrationDataCheckTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;
    private MigrationDataCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        // Importér v testu běží bez úlohy, záznam dokončeného převodu zakládá až úloha převodu.
        $this->db->pdo()->prepare("INSERT INTO money_s3_imports (supplier_id, mode, status) VALUES (?, 'import', 'completed')")->execute([$this->supplierId]);
        $this->service = $this->container(MigrationDataCheckService::class);
    }

    /** Doklad, kterému oprava jen nahradí úhradu mimo evidenci zápočtem, je v náhledu i za zámkem. */
    public function testPreviewShowsZeroGapReplacementAndLockStopsWholeFix(): void
    {
        $pdo = $this->db->pdo();
        $replaced = $this->purchase('DF-2025-300');
        $open = $this->purchase('DF-2025-641');
        // Stav firmy převedené dřív: zápočet chybí, krytí nese celé úhrada mimo evidenci.
        $pdo->prepare("DELETE FROM invoice_settlements WHERE supplier_id = ? AND doc_type = 'purchase_invoice' AND doc_id = ?")->execute([$this->supplierId, $replaced]);
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = amount_to_pay WHERE id = ?')->execute([$replaced]);
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = 0 WHERE id = ?')->execute([$open]);
        [$replacedPaid, $openPaid] = [$this->paidAt($replaced), $this->paidAt($open)];
        self::assertLessThan($openPaid, $replacedPaid, 'Fixture: doklad s náhradou krytí je uhrazený dřív než nekrytý doklad.');

        $detail = $this->service->detail($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        self::assertSame([$open], array_column($detail['items'], 'entity_id'), 'Detekce vidí jen chybějící krytí.');
        $preview = $this->service->preview($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        self::assertEqualsCanonicalizing([$replaced, $open], array_column($preview['changes'], 'entity_id'), 'Náhled ukazuje všechny skutečné změny.');

        $this->lockUntil($replacedPaid);
        $preview = $this->service->preview($this->supplierId, PurchaseSettledUncoveredCheck::KEY);
        self::assertFalse($preview['can_fix']);
        self::assertSame('locked', array_column($preview['skipped'], 'state', 'entity_id')[$replaced]);
        $this->assertRefused(PurchaseSettledUncoveredCheck::KEY, $preview['fingerprint']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_settlements WHERE doc_type = 'purchase_invoice' AND doc_id = {$replaced}")->fetchColumn());
        self::assertSame('0.00', (string) $pdo->query("SELECT settled_outside_amount FROM purchase_invoices WHERE id = {$open}")->fetchColumn());
    }

    /** Vazba platby zálohy na DDKP v podaném období DPH zastaví celou opravu Money S3. */
    public function testTaxDocumentLinkInFiledPeriodBlocksMoneyFix(): void
    {
        $pdo = $this->db->pdo();
        $taxDocument = $this->invoice('DD25050');
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE supplier_id = ? AND tax_document_invoice_id = ?')->execute([$this->supplierId, $taxDocument]);
        $gap = $this->invoice('FV25030');
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement'")->execute([$this->supplierId, $gap]);
        $taxDate = (string) $pdo->query("SELECT COALESCE(tax_date, issue_date) FROM invoices WHERE id = {$taxDocument}")->fetchColumn();
        self::assertNotSame(substr($taxDate, 0, 7), substr($this->paidAtInvoice($gap), 0, 7), 'Fixture: DDKP a úhrada v různých měsících.');

        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertTrue($preview['can_fix']);
        self::assertContains($gap, array_column($preview['changes'], 'entity_id'));
        self::assertContains($taxDocument, array_column($preview['changes'], 'entity_id'), 'Náhled ukazuje i vazbu platby na DDKP.');

        $this->filedVat((int) substr($taxDate, 0, 4), (int) substr($taxDate, 5, 2));
        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertFalse($preview['can_fix']);
        self::assertSame('locked', array_column($preview['skipped'], 'state', 'entity_id')[$taxDocument]);
        $this->assertRefused(SettledWithoutPaymentCheck::KEY, $preview['fingerprint']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE tax_document_invoice_id = {$taxDocument}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$gap} AND source = 'settlement'")->fetchColumn());
    }

    /** Oprava zapíše přesně plán z náhledu a opakovaná oprava nic nenajde. */
    public function testFixWritesPreviewedPlanOnce(): void
    {
        $pdo = $this->db->pdo();
        $gap = $this->invoice('FV25030');
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement'")->execute([$this->supplierId, $gap]);

        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        $result = $this->service->fix($this->supplierId, SettledWithoutPaymentCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame($preview['count'], $result['fixed_count']);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$gap} AND source = 'settlement'")->fetchColumn());
        $again = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertFalse($again['can_fix']);
        self::assertSame(0, $again['count']);
    }

    private function assertRefused(string $key, string $fingerprint): void
    {
        try {
            $this->service->fix($this->supplierId, $key, $this->userId, [], $fingerprint);
            self::fail('Oprava s nálezem v chráněném období musí skončit odmítnutím.');
        } catch (MigrationDataCheckException $e) {
            self::assertSame('nothing_to_fix', $e->errorCode);
        }
    }

    private function lockUntil(string $date): void
    {
        $this->db->pdo()->prepare('INSERT INTO accounting_supplier_settings (supplier_id, locked_until) VALUES (?, ?) ON DUPLICATE KEY UPDATE locked_until = VALUES(locked_until)')
            ->execute([$this->supplierId, $date]);
    }

    private function filedVat(int $year, int $month): void
    {
        $this->db->pdo()->prepare("INSERT INTO tax_submissions (supplier_id, form_code, period_year, period_month, xml_content, xml_size_bytes, xml_sha256, status, submitted_at)
            VALUES (?, 'dphdp3', ?, ?, '<x/>', 4, ?, 'submitted', NOW())")->execute([$this->supplierId, $year, $month, hash('sha256', "ms3{$year}{$month}")]);
    }

    private function paidAt(int $purchaseId): string
    {
        return substr((string) $this->db->pdo()->query("SELECT paid_at FROM purchase_invoices WHERE id = {$purchaseId}")->fetchColumn(), 0, 10);
    }

    private function paidAtInvoice(int $invoiceId): string
    {
        return substr((string) $this->db->pdo()->query("SELECT paid_at FROM invoices WHERE id = {$invoiceId}")->fetchColumn(), 0, 10);
    }

    private function invoice(string $no): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $no]);
        return (int) $stmt->fetchColumn();
    }

    private function purchase(string $vendorNo): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?');
        $stmt->execute([$this->supplierId, $vendorNo]);
        return (int) $stmt->fetchColumn();
    }
}
