<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

/**
 * Záloha z Money S3 uhrazená zápočtem interním dokladem (ZV25051, ID25051 MD 325 / D 324,
 * {@see SyntheticAgenda::filesWithSettledWithoutPaymentDocument()}): přijatou zálohu nese
 * převzatý deník na 324. Konečná faktura v dalším roce k ní s DDKP DD25051 nesmí skončit
 * na „Úhrada zálohy není zaúčtovaná".
 */
final class MoneyS3AdvanceReceiptTest extends MoneyS3ImportTestCase
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

    public function testAdvanceSettledByInternalDocumentAllowsFinalInvoiceNextYear(): void
    {
        $proforma = $this->lookupId('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', 'ZV25051');
        $entry = $this->lookupId("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND document_no = ?", 'ID25051');
        // Úhrada zálohy je zápočet navázaný na zápis interního dokladu, ne platba bez dokladu.
        self::assertSame(1, $this->rowCount('invoice_settlements', $this->supplierId,
            "doc_type = 'invoice' AND doc_id = {$proforma} AND journal_entry_id = {$entry} AND amount = 605.00 AND invoice_payment_id IS NOT NULL"));

        $finalId = $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29');
        self::assertSame(605.0, (new AdvanceSettlementEvidenceReader($this->db))->advanceReceived($this->supplierId, $proforma));

        self::assertGreaterThan(0, $finalId);
        self::assertSame(1, $this->rowCount('invoices', $this->supplierId, "id = {$finalId} AND parent_invoice_id = {$proforma} AND total_vat >= 0"));
    }

    private function lookupId(string $sql, string $value): int
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$this->supplierId, $value]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "{$value} nenalezen");
        return $id;
    }
}
