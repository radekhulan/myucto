<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * DDKP z Money má DPH spočtenou shora z celé úplaty (429 000 / 1,21 → základ
 * 354 545,45, DPH 74 454,55). Konečná faktura převezme základ DDKP a DPH položky
 * přepočte zdola (354 545,45 × 21 % = 74 454,54). Odpočtový řádek DDKP nese 74 454,55.
 * Očekávání: konečná faktura má DPH 0,00 a celkem 0,00 (celou úplatu zdanil DDKP).
 */
final class MoneyS3ProformaTaxDocumentRoundingTest extends MoneyS3ImportTestCase
{
    public function testFinalFromTaxDocumentWithVatFromGrossHasNoNegativeCent(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$supplierId]);
        $protocol = $this->import($supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $id = static function (string $no) use ($pdo, $supplierId): int {
            $s = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
            $s->execute([$supplierId, $no]);
            return (int) $s->fetchColumn();
        };
        $proforma = $id('ZV25050');
        $ddkp = $id('DD25050');
        // Záloha 429 000 bez sazby, DDKP 354 545,45 + 74 454,55 (DPH shora z úplaty).
        $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat = 429000, total_without_vat = 429000, total_with_vat = 429000 WHERE invoice_id = ?')->execute([$proforma]);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 429000, total_with_vat = 429000, paid_total = 429000 WHERE id = ?')->execute([$proforma]);
        $pdo->prepare('UPDATE invoice_payments SET amount = 429000 WHERE invoice_id = ?')->execute([$proforma]);
        $pdo->prepare('UPDATE invoice_items SET unit_price_without_vat = 354545.45, total_without_vat = 354545.45, total_vat = 74454.55, total_with_vat = 429000 WHERE invoice_id = ?')->execute([$ddkp]);
        $pdo->prepare('UPDATE invoices SET total_without_vat = 354545.45, total_vat = 74454.55, total_with_vat = 429000 WHERE id = ?')->execute([$ddkp]);

        $finalId = $this->container(FinalFromProformaCreator::class)->create($proforma, $this->userId, '2026-01-15', '2026-01-29', requirePostedReceipt: false);

        $final = $pdo->query("SELECT total_without_vat, total_vat, total_with_vat, amount_to_pay FROM invoices WHERE id = {$finalId}")->fetch(PDO::FETCH_ASSOC);
        $lines = $pdo->query("SELECT description, vat_rate_snapshot, total_without_vat, total_vat, total_with_vat FROM invoice_items WHERE invoice_id = {$finalId} ORDER BY order_index")->fetchAll(PDO::FETCH_ASSOC);
        $dump = json_encode(['final' => $final, 'lines' => $lines], JSON_UNESCAPED_UNICODE);
        self::assertSame('0.00', $final['total_vat'], $dump);
        self::assertSame('0.00', $final['total_with_vat'], $dump);
    }
}
