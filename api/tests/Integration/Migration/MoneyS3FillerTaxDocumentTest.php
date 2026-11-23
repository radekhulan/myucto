<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\PaymentTaxDocumentCreator;
use MyInvoice\Service\Invoice\PaymentTaxDocumentNotApplicableException;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Výplň bez dokladu úhrady u zálohy s DDKP z Money (ZV25050, DD25050, pohyb BP25050,
 * {@see SyntheticAgenda::filesWithSettledWithoutPaymentDocument()}): platba, která výplň
 * nahradí, zůstává zdaněnou platbou Money a vazba na DDKP se neztratí. Krytí platby DDKP
 * se počítá jen z DDKP v měně zálohy.
 */
final class MoneyS3FillerTaxDocumentTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;
    private int $proforma = 0;
    private int $taxDocument = 0;
    private int $txId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettledWithoutPaymentDocument());
        $this->supplierId = $this->supplier();
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE supplier SET is_vat_payer = 1 WHERE id = ?')->execute([$this->supplierId]);
        $pdo->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $s = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $s->execute([$this->supplierId, 'ZV25050']);
        $this->proforma = (int) $s->fetchColumn();
        $s->execute([$this->supplierId, 'DD25050']);
        $this->taxDocument = (int) $s->fetchColumn();
        $tx = $pdo->prepare("SELECT bank_transaction_id FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'");
        $tx->execute([$this->proforma]);
        $this->txId = (int) $tx->fetchColumn();
        self::assertGreaterThan(0, $this->txId);
        $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'")->execute([$this->proforma]);
    }

    /**
     * Výplň (dřívější verze převodu, UDoklad prázdný) k záloze s DDKP z Money: rekonciliace
     * s pohybem ji nahradí bankovní platbou. Platba je dál krytá DDKP z Money, druhý DDKP
     * k ní nejde vystavit.
     */
    public function testReconciledFillerKeepsTaxDeclaredStatus(): void
    {
        $pdo = $this->db->pdo();
        $filler = $this->filler(null);
        $payments = $this->container(InvoicePaymentService::class);
        self::assertTrue(TakenOverPayment::isTaxDeclaredByOrigin($payments->findPayment($filler), $pdo), 'výplň kryje DDKP z Money');

        $paid = $pdo->query("SELECT paid_total, status FROM invoices WHERE id = {$this->proforma}")->fetch(PDO::FETCH_ASSOC);
        $result = $payments->reconcileToBankTransaction($this->proforma, $this->txId);
        self::assertSame($paid, $pdo->query("SELECT paid_total, status FROM invoices WHERE id = {$this->proforma}")->fetch(PDO::FETCH_ASSOC));

        $payment = $payments->findPayment($result['payment_id']);
        self::assertTrue($payment['tax_declared_by_origin'], json_encode($this->payments(), JSON_UNESCAPED_UNICODE));
        self::assertStringContainsString(TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE, (string) $payment['note'], 'Poznámka nese odkaz na nahrazenou výplň.');
        try {
            $this->container(PaymentTaxDocumentCreator::class)->createForPayment($result['payment_id']);
            self::fail('Druhý DDKP k úplatě, kterou zdanil DDKP z Money: ' . json_encode($this->payments(), JSON_UNESCAPED_UNICODE));
        } catch (PaymentTaxDocumentNotApplicableException $e) {
            self::assertStringContainsString('zdaněná', $e->getMessage());
        }
    }

    /** Výplň navázaná na DDKP: rekonciliace i opakovaný převod vazbu přenesou na bankovní platbu. */
    public function testTaxDocumentLinkSurvivesFillerReplacement(): void
    {
        $this->filler($this->taxDocument);
        $result = $this->container(InvoicePaymentService::class)->reconcileToBankTransaction($this->proforma, $this->txId);
        self::assertSame([[(int) $result['payment_id'], $this->taxDocument]], $this->links());

        // Opakovaný převod: platbu založí převod, výplň zruší a vazba přejde na platbu.
        $this->db->pdo()->prepare('DELETE FROM invoice_payments WHERE invoice_id = ?')->execute([$this->proforma]);
        $this->filler($this->taxDocument);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $rows = $this->payments();
        self::assertSame(['bank'], array_column($rows, 'source'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame($this->taxDocument, (int) $rows[0]['tax_document_invoice_id']);
    }

    /** DDKP v jiné měně než záloha platbu zálohy nekryje (částky v různých měnách se nesčítají). */
    public function testTaxDocumentInOtherCurrencyDoesNotCoverPayment(): void
    {
        $pdo = $this->db->pdo();
        $filler = $this->filler(null);
        $payments = $this->container(InvoicePaymentService::class);
        self::assertTrue($payments->findPayment($filler)['tax_declared_by_origin']);

        $eur = (int) $pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'EUR'")->fetchColumn();
        $pdo->prepare('UPDATE invoices SET currency_id = ?, exchange_rate = 25 WHERE id = ?')->execute([$eur, $this->proforma]);
        $pdo->prepare("UPDATE invoice_payments SET currency = 'EUR', amount = 48.40 WHERE id = ?")->execute([$filler]);
        self::assertFalse($payments->findPayment($filler)['tax_declared_by_origin'], 'Korunový DDKP 1 210 Kč nekryje eurovou platbu 48,40 EUR podle částky.');
    }

    private function filler(?int $taxDocument): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, tax_document_invoice_id)
            VALUES (?, ?, '2025-09-05', 1210, 'CZK', ?, 'settlement', ?)")
            ->execute([$this->supplierId, $this->proforma, MoneyS3SettledPayments::note(), $taxDocument]);
        return (int) $pdo->lastInsertId();
    }

    /** @return list<array{0:int,1:int}> */
    private function links(): array
    {
        $s = $this->db->pdo()->prepare('SELECT id, tax_document_invoice_id FROM invoice_payments WHERE invoice_id = ? AND tax_document_invoice_id IS NOT NULL ORDER BY id');
        $s->execute([$this->proforma]);
        return array_map(static fn (array $r): array => [(int) $r[0], (int) $r[1]], $s->fetchAll(PDO::FETCH_NUM));
    }

    /** @return list<array<string,mixed>> */
    private function payments(): array
    {
        $s = $this->db->pdo()->prepare('SELECT id, source, note, amount, paid_on, bank_transaction_id, tax_document_invoice_id FROM invoice_payments WHERE invoice_id = ? ORDER BY id');
        $s->execute([$this->proforma]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
