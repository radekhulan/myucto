<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentNotApplicableException;
use MyInvoice\Service\Report\VatCrossCheckService;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

/**
 * Predikát „daň z platby přiznal původní program": platba zálohy převzatá z Money,
 * ke které Money žádný DDKP nevystavilo (ani konečnou fakturu), v otevřeném období.
 * Daň z ní přiznaná není: kontrola DPH ji hlásí a hláška při vystavení DDKP netvrdí,
 * že daň přiznal původní program.
 */
final class MoneyS3TaxDeclaredPredicateTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;
    private int $proforma = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $s = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $s->execute([$this->supplierId, 'ZV25050']);
        $this->proforma = (int) $s->fetchColumn();
        $s->execute([$this->supplierId, 'DD25050']);
        $ddkp = (int) $s->fetchColumn();
        // Money k platbě DDKP nevystavilo.
        $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE invoice_id = ?')->execute([$this->proforma]);
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$ddkp]);
        $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$ddkp]);
    }

    public function testVatCrossCheckReportsMoneyPaymentWithoutAnyTaxDocument(): void
    {
        $findings = $this->container(VatCrossCheckService::class)->check($this->supplierId, 2025, 9, 'monthly');
        $ids = [];
        foreach ($findings as $f) {
            if (($f['check'] ?? null) === 'draft_advance_tax_documents') {
                $ids = array_column($f['documents'], 'invoice_id');
            }
        }
        self::assertContains($this->proforma, $ids, 'Platba zálohy bez DDKP (ani z Money) zmizela z kontroly DPH.');
    }

    public function testTaxDocumentRefusalDoesNotClaimOriginDeclaredTax(): void
    {
        $s = $this->db->pdo()->prepare('SELECT id FROM invoice_payments WHERE invoice_id = ?');
        $s->execute([$this->proforma]);
        try {
            $this->container(PaymentTaxDocumentCreator::class)->createForPayment((int) $s->fetchColumn());
            self::fail('bez výjimky');
        } catch (PaymentTaxDocumentNotApplicableException $e) {
            self::assertStringNotContainsString('přiznal původní program', $e->getMessage());
        }
    }
}
