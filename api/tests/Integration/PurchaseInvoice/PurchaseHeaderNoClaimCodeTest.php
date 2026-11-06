<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Action\PurchaseInvoice\UpdatePurchaseInvoiceAction;
use MyInvoice\Tests\Support\PurchaseVatFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hlavička s kódem „bez nároku" (42): položka bez vlastního kódu ho převezme, místo aby
 * dostala výchozí 40/41 a uplatnila odpočet, který hlavička odepírá. Data jsou syntetická.
 */
#[Group('integration')]
final class PurchaseHeaderNoClaimCodeTest extends TestCase
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

    public function testNewItemsInheritNoClaimHeaderCode(): void
    {
        $id = $this->createPurchase(['vat_classification_code' => '42'], [
            $this->item('Oběd', 300.0),
            $this->item('Kniha', 100.0, $this->rate12),
        ]);

        self::assertSame(['42', '42'], array_column($this->repo->find($id, $this->supplierId)['items'], 'vat_classification_code'));
        foreach ($this->ledgerList($id) as $row) {
            self::assertNull($row['dphdp3_line'], 'Kód 42 do přiznání nevstupuje, odpočet se neuplatní.');
        }
    }

    public function testExplicitItemCodeIsKeptAndConflictIsReported(): void
    {
        $id = $this->createPurchase(['vat_classification_code' => '42'], [
            $this->item('Oběd', 300.0),
            $this->item('Materiál', 100.0, null, '40'),
        ]);

        $inv = $this->repo->find($id, $this->supplierId);
        self::assertSame(['42', '40'], array_column($inv['items'], 'vat_classification_code'));
        self::assertContains('header_no_claim_code_with_claim_items', $inv['vat_treatment']['warnings']);
    }

    /** Kód s nárokem v hlavičce se nepřebírá: 12% položka pod hlavičkou 40 patří na ř. 41. */
    public function testClaimHeaderCodeIsNotInherited(): void
    {
        $id = $this->createPurchase(['vat_classification_code' => '40'], [$this->item('Kniha', 100.0, $this->rate12)]);

        self::assertSame(['41'], array_column($this->repo->find($id, $this->supplierId)['items'], 'vat_classification_code'));
    }

    /** Nová položka přidaná v editoru k dokladu s hlavičkou 42 dostane 42, uložené položky se nemění. */
    public function testItemAddedOnEditInheritsAndStoredItemsStay(): void
    {
        $id = $this->createPurchase([], [$this->item('Oběd', 300.0)]);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET vat_classification_code = '42' WHERE id = ?")->execute([$id]);
        $body = $this->editorBody($id);
        $body['items'][] = $this->item('Dezert', 50.0) + ['order_index' => 1];

        $res = $this->call($this->container->get(UpdatePurchaseInvoiceAction::class), 'PUT', $body, ['id' => (string) $id]);

        self::assertSame(200, $res['status'], json_encode($res['body'], JSON_UNESCAPED_UNICODE));
        self::assertSame(['40', '42'], array_column($res['body']['items'], 'vat_classification_code'),
            'Uložená položka si kód 40 nechá (mění se jen vědomou opravou), nová převezme 42.');
    }
}
