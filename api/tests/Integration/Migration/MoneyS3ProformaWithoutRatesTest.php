<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentNotApplicableException;
use MyInvoice\Service\Invoice\ProformaVatRates;
use MyInvoice\Service\Invoice\ProformaWithoutVatRatesException;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Konečná faktura k zálohové faktuře převzaté z Money S3, která nenese sazby DPH
 * (`Zaklad_0`), a k jejímu daňovému dokladu k přijaté platbě se sazbou 21 %
 * ({@see SyntheticAgenda::filesWithSettledWithoutPaymentDocument()}, ZV25050 a DD25050).
 * Faktura nesmí vyjít se zápornou DPH: položky nesou sazby DDKP. Záloha z Money bez DDKP
 * se automaticky nevyúčtuje, záloha s nulovou sazbou z jiného zdroje ano.
 */
final class MoneyS3ProformaWithoutRatesTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $this->db->pdo()->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    public function testFinalInvoiceFromMoneyProformaWithTaxDocumentHasNoNegativeVat(): void
    {
        $proforma = $this->invoice('ZV25050');
        self::assertSame(0.0, (float) $this->scalar('SELECT SUM(total_vat) FROM invoice_items WHERE invoice_id = ?', [$proforma]));

        $finalId = $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29');

        $final = $this->db->pdo()->query("SELECT total_without_vat, total_vat, total_with_vat, amount_to_pay FROM invoices WHERE id = {$finalId}")->fetch(PDO::FETCH_ASSOC);
        self::assertGreaterThanOrEqual(0.0, (float) $final['total_vat'], json_encode($final));
        $taxDocumentVat = (float) $this->scalar('SELECT total_vat FROM invoices WHERE id = ?', [$this->invoice('DD25050')]);
        self::assertSame(210.0, round((float) $final['total_vat'] + $taxDocumentVat, 2), 'DPH z DDKP a konečné faktury je DPH plnění.');
        $lines = $this->db->pdo()->query("SELECT vat_rate_snapshot, total_without_vat, total_vat FROM invoice_items WHERE invoice_id = {$finalId} ORDER BY order_index")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['21.00', '1000.00', '210.00'], array_values($lines[0]), json_encode($lines));
        self::assertSame(0.0, (float) $final['total_with_vat']);
    }

    public function testMoneyProformaWithoutTaxDocumentIsNotSettledWithoutVat(): void
    {
        $proforma = $this->invoice('ZV25050');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$this->invoice('DD25050')]);
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE invoice_id = ?')->execute([$proforma]);

        try {
            $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29');
            self::fail('Konečná faktura k záloze z Money bez sazeb a bez DDKP by vyšla bez DPH.');
        } catch (ProformaWithoutVatRatesException $e) {
            self::assertStringContainsString('ZV25050', $e->getMessage());
        }
        self::assertSame(0, $this->rowCount('invoices', $this->supplierId, "invoice_type = 'invoice' AND parent_invoice_id = {$proforma}"));

        // Záloha s nulovou sazbou odjinud (0 % je tam sazba plnění) se vyúčtuje jako dřív.
        $pdo->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'invoice' AND target_id = ?")->execute([$this->supplierId, $proforma]);
        $finalId = $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29');
        self::assertSame(0.0, (float) $this->scalar('SELECT total_vat FROM invoices WHERE id = ?', [$finalId]));
    }

    /**
     * Nová platba zálohy bez sazeb, ke které DDKP z Money sazbu neurčuje (žádný není):
     * DDKP na 0 % nevznikne. Server si vyžádá sazbu plnění a se zvolenou sazbou ho vystaví.
     * V automatickém režimu je odmítnutí vidět jako úkol CRM.
     */
    public function testTaxDocumentForNewPaymentOfMoneyProformaWithoutRatesNeedsChosenRate(): void
    {
        $proforma = $this->invoice('ZV25050');
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$this->invoice('DD25050')]);
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE invoice_id = ?')->execute([$proforma]);
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source) VALUES (?, ?, '2026-01-10', 121.00, 'CZK', 'manual')")
            ->execute([$this->supplierId, $proforma]);
        $payment = (int) $pdo->lastInsertId();

        $creator = $this->container(PaymentTaxDocumentCreator::class);
        try {
            $creator->createForPayment($payment);
            self::fail('DDKP k záloze bez sazeb by vyšel bez daně.');
        } catch (PaymentTaxDocumentNotApplicableException $e) {
            self::assertSame(ProformaVatRates::RATE_REQUIRED, $e->getCode());
            self::assertStringContainsString('bez sazeb DPH', $e->getMessage());
        }

        $crm = $this->container(CrmAggregationService::class)->actionItems($this->supplierId, $this->userId);
        self::assertContains('proforma_awaiting_document', array_column((array) ($crm['items'] ?? $crm), 'type'),
            'Odmítnutý DDKP v automatickém režimu musí být vidět v úkolech.');

        $rate21 = (int) $pdo->query("SELECT id FROM vat_rates WHERE rate_percent = 21 AND is_reverse_charge = 0 AND valid_from <= '2026-01-10'
                                       AND (valid_to IS NULL OR valid_to >= '2026-01-10') ORDER BY valid_from DESC LIMIT 1")->fetchColumn();
        $taxDocId = $creator->createForPayment($payment, $this->userId, $rate21);
        self::assertSame(21.0, (float) $this->scalar('SELECT total_vat FROM invoices WHERE id = ?', [$taxDocId]));
    }

    private function invoice(string $no): int
    {
        $id = (int) $this->scalar('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$this->supplierId, $no]);
        self::assertGreaterThan(0, $id, "{$no} nenalezen");
        return $id;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
