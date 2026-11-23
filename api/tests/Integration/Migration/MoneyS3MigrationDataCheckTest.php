<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Migration\Check\Checks\PurchaseSettledUncoveredCheck;
use MyInvoice\Service\Migration\Check\Checks\SettledWithoutPaymentCheck;
use MyInvoice\Service\Migration\Check\MigrationDataCheckException;
use MyInvoice\Service\Migration\Check\MigrationDataCheckService;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
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
        self::assertEqualsCanonicalizing([$replaced, $open], array_values(array_unique(array_column($preview['changes'], 'entity_id'))), 'Náhled ukazuje všechny skutečné změny.');

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
        self::assertNotNull(self::linkChange($preview['changes'], $taxDocument), 'Náhled ukazuje i vazbu platby na DDKP.');

        $this->filedVat((int) substr($taxDate, 0, 4), (int) substr($taxDate, 5, 2));
        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertFalse($preview['can_fix']);
        self::assertSame('locked', self::linkChange($preview['skipped'], $taxDocument)['state'] ?? null);
        $this->assertRefused(SettledWithoutPaymentCheck::KEY, $preview['fingerprint']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE tax_document_invoice_id = {$taxDocument}")->fetchColumn());
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$gap} AND source = 'settlement'")->fetchColumn());
    }

    /** Zápočet se zapisuje k datu interního zápisu: zámek se ověřuje nad ním, ne nad datem úhrady dokladu. */
    public function testSettlementIsCheckedAtJournalEntryDate(): void
    {
        $pdo = $this->db->pdo();
        $invoice = $this->invoice('FV25020');
        $entry = (int) $pdo->query("SELECT journal_entry_id FROM invoice_settlements WHERE doc_type = 'invoice' AND doc_id = {$invoice}")->fetchColumn();
        self::assertGreaterThan(0, $entry);
        $pdo->prepare("DELETE FROM invoice_settlements WHERE supplier_id = ? AND doc_type = 'invoice' AND doc_id = ?")->execute([$this->supplierId, $invoice]);
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement'")->execute([$this->supplierId, $invoice]);
        $paid = $this->paidAtInvoice($invoice);
        $entryDate = (new \DateTimeImmutable($paid))->modify('-10 days')->format('Y-m-d');
        $pdo->prepare('UPDATE journal_entries SET entry_date = ? WHERE id = ?')->execute([$entryDate, $entry]);
        $this->lockUntil($entryDate);

        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        $settlement = array_values(array_filter(array_merge($preview['changes'], $preview['skipped']),
            static fn (array $i): bool => ($i['details']['table'] ?? '') === 'settlements' && $i['entity_id'] === $invoice));
        self::assertNotSame([], $settlement, 'Náhled ukazuje zápočet interním dokladem.');
        self::assertSame($entryDate, $settlement[0]['details']['after']['settled_on']);
        self::assertSame($entry, (int) $settlement[0]['details']['after']['journal_entry_id']);
        self::assertSame('locked', $settlement[0]['state']);
        self::assertFalse($preview['can_fix']);
    }

    /** Zrušená výplň odnese vazbu platby na DDKP: i zánik vazby je v náhledu a hlídá se období DDKP. */
    public function testRemovedTaxDocumentLinkIsPreviewedAndChecked(): void
    {
        $pdo = $this->db->pdo();
        $advance = $this->invoice('ZV25051');
        $taxDocument = $this->invoice('DD25051');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$advance} AND tax_document_invoice_id = {$taxDocument}")->fetchColumn());
        // Úhrada zálohy je výplň bez dokladu úhrady s vazbou na DDKP. Úhrada podle Money zmizela
        // (opravený doklad): výplň se zruší a vazbu nemá kdo převzít.
        $pdo->prepare("DELETE FROM invoice_settlements WHERE supplier_id = ? AND doc_type = 'invoice' AND doc_id = ?")->execute([$this->supplierId, $advance]);
        $pdo->prepare('UPDATE invoice_payments SET note = ? WHERE invoice_id = ? AND tax_document_invoice_id = ?')
            ->execute([MoneyS3SettledPayments::note(), $advance, $taxDocument]);
        $pdo->prepare('UPDATE invoices SET paid_total = 0 WHERE id = ?')->execute([$advance]);
        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        $removal = self::linkChange(array_merge($preview['changes'], $preview['skipped']), $taxDocument);
        self::assertNotNull($removal, 'Náhled ukazuje zánik vazby platby na DDKP.');
        self::assertSame('remove', $removal['details']['op']);

        $taxDate = (string) $pdo->query("SELECT COALESCE(tax_date, issue_date) FROM invoices WHERE id = {$taxDocument}")->fetchColumn();
        $this->filedVat((int) substr($taxDate, 0, 4), (int) substr($taxDate, 5, 2));
        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        self::assertSame('locked', self::linkChange($preview['skipped'], $taxDocument)['state'] ?? null);
        self::assertFalse($preview['can_fix']);
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

    /**
     * Zápočet, který oprava najde jen podle dne a částky (ID25040 bez čísla dokladu, partneři
     * vydaného a přijatého dokladu se liší), kontrola ukáže jako návrh ke kontrole a oprava ho
     * nikdy nezapíše: doklad dostane výplň.
     */
    public function testSettlementProposalIsReviewNeverWritten(): void
    {
        $pdo = $this->db->pdo();
        $fv40 = $this->invoice('FV25040');
        $entry = (int) $pdo->query("SELECT id FROM journal_entries WHERE supplier_id = {$this->supplierId} AND document_no = 'ID25040'")->fetchColumn();
        // Stav firmy převedené dřív: zápočty ID25040 ani výplň vydaného dokladu nejsou.
        $pdo->prepare('DELETE s, p FROM invoice_settlements s LEFT JOIN invoice_payments p ON p.id = s.invoice_payment_id WHERE s.supplier_id = ? AND s.journal_entry_id = ?')
            ->execute([$this->supplierId, $entry]);
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND invoice_id = ? AND source = 'settlement'")->execute([$this->supplierId, $fv40]);

        $preview = $this->service->preview($this->supplierId, SettledWithoutPaymentCheck::KEY);
        $proposals = array_values(array_filter([...($preview['skipped'] ?? []), ...($preview['changes'] ?? [])],
            static fn (array $i): bool => ($i['details']['settlement_proposal']['entry_no'] ?? null) === 'ID25040'));
        self::assertNotEmpty($proposals, json_encode($preview, JSON_UNESCAPED_UNICODE));
        self::assertSame(['review'], array_values(array_unique(array_column($proposals, 'state'))));
        self::assertContains($fv40, array_column($proposals, 'entity_id'));

        $this->service->fix($this->supplierId, SettledWithoutPaymentCheck::KEY, $this->userId, [], $preview['fingerprint']);
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM invoice_settlements WHERE journal_entry_id = {$entry}")->fetchColumn(), 'Návrh se nezapíše.');
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = {$fv40} AND source = 'settlement' AND amount = 1210.00")->fetchColumn());
    }

    /**
     * Změna platby, která ji naváže na DDKP nebo z něj odváže.
     *
     * @param list<array<string,mixed>> $items
     * @return ?array<string,mixed>
     */
    private static function linkChange(array $items, int $taxDocument): ?array
    {
        foreach ($items as $i) {
            $d = $i['details'];
            if (($d['table'] ?? '') === 'payments' && in_array($taxDocument, [(int) ($d['before']['tax_document_invoice_id'] ?? 0), (int) ($d['after']['tax_document_invoice_id'] ?? 0)], true)) {
                return $i;
            }
        }
        return null;
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
