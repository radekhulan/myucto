<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Tests\Support\PurchaseVatFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Duplicita se stornovaným dokladem: ruční zadání to řekne a nabídne vrácení ze storna,
 * import (AI, ISDOC, fronta) stornovaný doklad za duplikát nepovažuje. Data jsou syntetická.
 */
#[Group('integration')]
final class PurchaseCancelledDuplicateTest extends TestCase
{
    use PurchaseVatFixtureTrait;

    protected function setUp(): void
    {
        $this->setUpPurchaseVatFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownPurchaseVatFixture();
    }

    public function testManualEntryNamesCancelledDuplicateAndAllowsSavingAnyway(): void
    {
        $number = 'STORNO-' . bin2hex(random_bytes(3));
        $first = $this->createPurchase(['vendor_invoice_number' => $number]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$first]);

        $res = $this->call($this->container->get(\MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction::class), 'POST', [
            'vendor_id' => $this->vendorId, 'vendor_invoice_number' => $number, 'document_kind' => 'invoice',
            'issue_date' => self::VAT_DATE, 'tax_date' => self::VAT_DATE, 'due_date' => '2096-05-10',
            'currency_id' => $this->currencyId, 'items' => [$this->item('Služba', 100.0)],
        ]);

        self::assertSame(409, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame('vendor_invoice_duplicate', $res['body']['error']['code'] ?? null);
        self::assertSame($first, $res['body']['error']['cancelled_duplicate_id'] ?? null);
        self::assertStringContainsString('stornovaná', (string) ($res['body']['error']['message'] ?? ''));
        self::assertStringContainsString('vrátit ze storna', (string) ($res['body']['error']['message'] ?? ''));

        $again = $this->createPurchase(['vendor_invoice_number' => $number, 'allow_duplicate_number' => true]);
        self::assertNotSame($first, $again);
    }

    public function testActiveDuplicateKeepsGenericMessage(): void
    {
        $number = 'AKTIVNI-' . bin2hex(random_bytes(3));
        $this->createPurchase(['vendor_invoice_number' => $number]);

        $res = $this->call($this->container->get(\MyInvoice\Action\PurchaseInvoice\CreatePurchaseInvoiceAction::class), 'POST', [
            'vendor_id' => $this->vendorId, 'vendor_invoice_number' => $number, 'document_kind' => 'invoice',
            'issue_date' => self::VAT_DATE, 'tax_date' => self::VAT_DATE, 'due_date' => '2096-05-10',
            'currency_id' => $this->currencyId, 'items' => [],
        ]);

        self::assertSame(409, $res['status']);
        self::assertArrayNotHasKey('cancelled_duplicate_id', $res['body']['error'] ?? []);
        self::assertStringNotContainsString('stornovaná', (string) ($res['body']['error']['message'] ?? ''));
    }

    /** Dohledání pro import: stornovaný doklad se za duplikát nepovažuje, nový dostane další pořadí. */
    public function testImportLookupsIgnoreCancelledDocuments(): void
    {
        $number = 'IMPORT-' . bin2hex(random_bytes(3));
        $hash = hash('sha256', $number);
        $id = $this->createPurchase(['vendor_invoice_number' => $number]);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET pdf_hash = ?, source_hash = ? WHERE id = ?')->execute([$hash, $hash, $id]);

        self::assertSame($id, $this->repo->findIdByPdfHash($this->supplierId, $hash, activeOnly: true));
        self::assertSame($id, $this->repo->findIdByVendorInvoice($this->supplierId, $this->vendorId, $number, self::VAT_DATE, activeOnly: true));
        self::assertSame(1, $this->repo->importDuplicateSeq($this->supplierId, $this->vendorId, $number, self::VAT_DATE));

        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'cancelled' WHERE id = ?")->execute([$id]);

        self::assertNull($this->repo->findIdByPdfHash($this->supplierId, $hash, activeOnly: true));
        self::assertNull($this->repo->findIdBySourceHash($this->supplierId, $hash, activeOnly: true));
        self::assertNull($this->repo->findIdByVendorInvoice($this->supplierId, $this->vendorId, $number, self::VAT_DATE, activeOnly: true));
        self::assertSame($id, $this->repo->findIdByPdfHash($this->supplierId, $hash), 'Bez příznaku dál vidí i storno (úklid souborů).');
    }
}
