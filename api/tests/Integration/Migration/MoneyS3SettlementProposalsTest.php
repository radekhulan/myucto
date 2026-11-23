<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;

/**
 * Opravné CLI bez zálohy Money hledá zápis zápočtu dokladu bez čísla úhrady podle dne úhrady
 * a částky ({@see SyntheticAgenda::filesWithSettlementProposals()}). Den a částka samy zápočet
 * nepotvrdí: zápis bez čísla dokladu a bez vazby na partnera zůstane jen návrhem a doklad
 * dostane výplň, zápis s číslem dokladu v popisu se stane zápočtem.
 */
final class MoneyS3SettlementProposalsTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithSettlementProposals());
        $this->supplierId = $this->supplier();
        $this->db->pdo()->prepare("INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, 'EUR', 'EUR', '€', 'Euro', 'Euro', 2, 1, 0)")->execute([$this->supplierId]);
        $protocol = $this->import($this->supplierId);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        // Stav firmy převedené dřív: zápočty interním dokladem ani výplně nejsou, úhrada jen v paid_total.
        $pdo = $this->db->pdo();
        // Převod zápisy ID25043 a ID25044 navázal na faktury podle čísla v popisu řádku Money; firma
        // převedená dřív vazbu nemá a CLI číslo dokladu najde jen v převzatém popisu zápisu.
        foreach (['ID25043', 'ID25044', 'ID25099'] as $entry) {
            $pdo->prepare('DELETE s, p FROM invoice_settlements s LEFT JOIN invoice_payments p ON p.id = s.invoice_payment_id
                            WHERE s.supplier_id = ? AND s.journal_entry_id = ?')->execute([$this->supplierId, $this->entry($entry)]);
            $pdo->prepare('DELETE FROM journal_entry_document_links WHERE supplier_id = ? AND entry_id = ?')->execute([$this->supplierId, $this->entry($entry)]);
        }
        $pdo->prepare("DELETE FROM invoice_payments WHERE supplier_id = ? AND source = 'settlement' AND note LIKE ?")
            ->execute([$this->supplierId, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%']);
    }

    /**
     * FV25045 Odběratele Beta je podle Money uhrazená 15. 12. bez čísla úhrady. Jediný zápis
     * interního dokladu toho dne na stejnou částku (ID25099) přeúčtovává pohledávku jiného
     * odběratele z 311 na 395: zápočtem faktury není, CLI ho jen navrhne.
     */
    public function testSameDayAndAmountReclassificationIsOnlyProposed(): void
    {
        $sid = $this->supplierId;
        $fv45 = $this->invoice('FV25045');
        $id99 = $this->entry('ID25099');
        $repair = $this->container(MoneyS3SettledPaymentRepair::class);
        $dry = $repair->run([$sid], false)[0];
        $done = $repair->run([$sid], true)[0];
        self::assertSame(0, $this->rowCount('invoice_settlements', $sid, "journal_entry_id = {$id99}"));
        self::assertSame(1, $this->rowCount('invoice_payments', $sid,
            "invoice_id = {$fv45} AND source = 'settlement' AND amount = 605.00 AND paid_on = '2025-12-15' AND note = " . $this->db->pdo()->quote(MoneyS3SettledPayments::note())));
        foreach ([$dry, $done] as $row) {
            $proposal = array_values(array_filter($row['proposals'] ?? [], static fn (array $p): bool => $p['id'] === $fv45));
            self::assertCount(1, $proposal, json_encode($row));
            self::assertSame(['invoice', 'FV25045', $id99, 'ID25099', '2025-12-15', 605.0],
                [$proposal[0]['doc_type'], $proposal[0]['document_no'], $proposal[0]['entry_id'], $proposal[0]['entry_no'], $proposal[0]['date'], $proposal[0]['amount_czk']]);
        }
        self::assertSame('paid', $this->scalar('SELECT status FROM invoices WHERE id = ?', [$fv45]));
    }

    /**
     * FV25043 a FV25044 (obě 605, uhrazené 12. 12.) a týž den dva zápočty na 605: den a částka
     * je nerozliší, číslo faktury v popisu zápisu ano. Každá dostane zápočet svým zápisem.
     */
    public function testEntryCarryingDocumentNumberIsConfirmedAsSettlement(): void
    {
        $sid = $this->supplierId;
        $done = $this->container(MoneyS3SettledPaymentRepair::class)->run([$sid], true)[0];
        foreach (['FV25043' => 'ID25043', 'FV25044' => 'ID25044'] as $no => $entry) {
            $fv = $this->invoice($no);
            self::assertSame(1, $this->rowCount('invoice_settlements', $sid,
                "doc_type = 'invoice' AND doc_id = {$fv} AND amount = 605.00 AND journal_entry_id = {$this->entry($entry)} AND invoice_payment_id IS NOT NULL"), $no);
            self::assertSame(1, $this->rowCount('invoice_payments', $sid, "invoice_id = {$fv} AND note = 'Převzato z Money S3, zápočet interním dokladem {$entry}'"), $no);
            self::assertSame(0, $this->rowCount('invoice_payments', $sid,
                "invoice_id = {$fv} AND note LIKE " . $this->db->pdo()->quote(TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%')), $no);
            self::assertSame([], array_values(array_filter($done['proposals'] ?? [], static fn (array $p): bool => $p['id'] === $fv)), $no);
        }
    }

    /**
     * Vzájemný zápočet ID25040 (MD 321 / D 311) bez čísel dokladů v popisu potvrdí partner:
     * vydaná FV25040 a přijatá DF-2025-640 téhož partnera uhrazené podle Money týž den.
     */
    public function testMutualOffsetWithSamePartnerIsConfirmed(): void
    {
        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        $id40 = $this->entry('ID25040');
        $fv40 = $this->invoice('FV25040');
        $fp40 = (int) $this->scalar('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND vendor_invoice_number = ?', [$sid, 'DF-2025-640']);
        $pdo->prepare('DELETE s, p FROM invoice_settlements s LEFT JOIN invoice_payments p ON p.id = s.invoice_payment_id
                        WHERE s.supplier_id = ? AND s.journal_entry_id = ?')->execute([$sid, $id40]);
        $pdo->prepare('UPDATE purchase_invoices SET settled_outside_amount = 0 WHERE supplier_id = ?')->execute([$sid]);
        $pdo->prepare('UPDATE purchase_invoices SET vendor_id = (SELECT client_id FROM invoices WHERE id = ?) WHERE id = ?')->execute([$fv40, $fp40]);

        $done = $this->container(MoneyS3SettledPaymentRepair::class)->run([$sid], true)[0];
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid, "doc_type = 'invoice' AND doc_id = {$fv40} AND amount = 1210.00 AND journal_entry_id = {$id40}"));
        self::assertSame(1, $this->rowCount('invoice_settlements', $sid, "doc_type = 'purchase_invoice' AND doc_id = {$fp40} AND amount = 1210.00 AND journal_entry_id = {$id40}"));
        self::assertSame(0.0, (float) $this->scalar('SELECT settled_outside_amount FROM purchase_invoices WHERE id = ?', [$fp40]));
        self::assertSame([], array_values(array_filter($done['proposals'] ?? [], static fn (array $p): bool => $p['entry_id'] === $id40)));
    }

    private function invoice(string $no): int
    {
        $id = (int) $this->scalar('SELECT id FROM invoices WHERE supplier_id = ? AND varsymbol = ?', [$this->supplierId, $no]);
        self::assertGreaterThan(0, $id, "{$no} nenalezena");
        return $id;
    }

    private function entry(string $no): int
    {
        $id = (int) $this->scalar("SELECT id FROM journal_entries WHERE supplier_id = ? AND source_type = 'manual' AND document_no = ?", [$this->supplierId, $no]);
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
