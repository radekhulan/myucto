<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Výplň bez dokladu úhrady nevznikne u dokladu, jehož úhrada bankou v převedených datech
 * je, jen k ní převod zatím nezaložil evidovanou platbu: dřívější běh spároval pohyb
 * (payment_matches + mapa) a platbu nezaložil (větev `existing` v DocumentLinker), nebo
 * byla úhrada nejednoznačná (`payment_ambiguous`). Opakovaný převod doplní bankovní platbu
 * a ruční spárování pohybu výplň nahradí.
 */
final class MoneyS3SettledFillerTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;
    private int $proforma = 0;
    private int $txId = 0;

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
        $tx = $pdo->prepare("SELECT bank_transaction_id FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'");
        $tx->execute([$this->proforma]);
        $this->txId = (int) $tx->fetchColumn();
        self::assertGreaterThan(0, $this->txId, 'ZV25050 má mít bankovní platbu z převodu');

        // Stav firmy převedené dřív: párování a mapa úhrady jsou, evidovaná platba ne.
        $pdo->prepare("DELETE FROM invoice_payments WHERE invoice_id = ? AND source = 'bank'")->execute([$this->proforma]);
    }

    /** Opravné CLI výplň nezaloží, doklad ohlásí; opakovaný převod doplní bankovní platbu. */
    public function testRerunBackfillsBankPaymentInsteadOfFiller(): void
    {
        $rows = $this->container(MoneyS3SettledPaymentRepair::class)->run([$this->supplierId], true, $this->userId);
        self::assertContains($this->proforma, array_column($rows[0]['left_for_matching'], 'id'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame([], $this->payments());

        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $rows = $this->payments();
        self::assertContains('bank', array_column($rows, 'source'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertNotContains(true, array_map(static fn (array $r): bool => TakenOverPayment::isProtected($r), $rows), json_encode($rows, JSON_UNESCAPED_UNICODE));
    }

    /** Výplň z dřívější opravy bankovní platbě nebrání: opakovaný převod ji založí a výplň zruší. */
    public function testRerunReplacesEarlierFillerWithBankPayment(): void
    {
        $this->filler();

        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));

        $rows = $this->payments();
        self::assertSame(['bank'], array_column($rows, 'source'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame('1210.00', (string) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$this->proforma}")->fetchColumn());
    }

    /** Ruční spárování pohybu se zálohou (rekonciliace zaplaceného dokladu) výplň nahradí. */
    public function testUserCanReconcileTheRealBankMovement(): void
    {
        $this->filler();
        $result = $this->container(InvoicePaymentService::class)->reconcileToBankTransaction($this->proforma, $this->txId);
        self::assertGreaterThan(0, $result['payment_id']);

        $rows = $this->payments();
        self::assertSame(['bank'], array_column($rows, 'source'), json_encode($rows, JSON_UNESCAPED_UNICODE));
        self::assertSame($this->txId, (int) $rows[0]['bank_transaction_id']);
        self::assertSame('1210.00', (string) $this->db->pdo()->query("SELECT paid_total FROM invoices WHERE id = {$this->proforma}")->fetchColumn());
    }

    /** Výplň, kterou dřívější verze opravy založila i u dokladu s úhradou v bance. */
    private function filler(): void
    {
        $this->db->pdo()->prepare("INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source) VALUES (?, ?, '2025-09-05', 1210.00, 'CZK', ?, 'settlement')")
            ->execute([$this->supplierId, $this->proforma, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . ' (BP25050)']);
    }

    /** @return list<array<string,mixed>> */
    private function payments(): array
    {
        $s = $this->db->pdo()->prepare('SELECT id, source, note, amount, bank_transaction_id FROM invoice_payments WHERE invoice_id = ? ORDER BY id');
        $s->execute([$this->proforma]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }
}
