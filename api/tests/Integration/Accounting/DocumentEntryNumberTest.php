<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Service\Accounting\DocumentEntryNumberBackfill;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Číslo dokladu v hlavičce zápisu faktury (journal_entries.document_no).
 *
 * Automatické zaúčtování po vystavení i přeúčtování ho nedodávají — dřív ho doplnilo
 * až doúčtování, které přepisovalo i zaúčtované doklady. Od chvíle, kdy doúčtování
 * bere jen doklady bez zápisu, zůstávaly zápisy z automatiky bez čísla. Číslo proto
 * určuje PostingService sám (DocumentEntryNumber) a staré díry dorovná backfill.
 */
#[Group('integration')]
final class DocumentEntryNumberTest extends BankPostingTestCase
{
    public function testInvoiceAndCreditNoteEntriesCarryVarsymbol(): void
    {
        $client = $this->client('Odběratel čísla dokladu');
        $invoice = $this->saleInvoice('FV-DOCNO-1', $client, 1000.0);
        $credit = $this->saleInvoice('DB-DOCNO-1', $client, -200.0, 'credit_note');

        self::assertSame('FV-DOCNO-1', $this->documentNo($this->post('invoice', $invoice, '311', '602', 1000.0)));
        self::assertSame('DB-DOCNO-1', $this->documentNo($this->post('invoice', $credit, '602', '311', 200.0)));
    }

    public function testPurchaseEntryCarriesOurNumberWithFallbackToVendorNumber(): void
    {
        $vendor = $this->client('Dodavatel čísla dokladu');
        $withOurNo = $this->purchaseInvoice('DOD-2099-17', $vendor, 500.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET varsymbol = 'PF-DOCNO-2' WHERE id = ?")
            ->execute([$withOurNo]);
        $withoutOurNo = $this->purchaseInvoice('DOD-2099-18', $vendor, 300.0);

        self::assertSame('PF-DOCNO-2', $this->documentNo($this->post('purchase_invoice', $withOurNo, '518', '321', 500.0)));
        self::assertSame('DOD-2099-18', $this->documentNo($this->post('purchase_invoice', $withoutOurNo, '518', '321', 300.0)));
    }

    /** Starý zápis s číslem dodavatele backfill nepřepisuje, přeúčtování bez čísla dá naše. */
    public function testHistoricalVendorNumberStaysUntilEntryIsReposted(): void
    {
        $invoice = $this->purchaseInvoice('DOD-2099-19', $this->client('Dodavatel historie'), 400.0);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE purchase_invoices SET varsymbol = 'PF-DOCNO-7' WHERE id = ?")->execute([$invoice]);
        $entry = $this->post('purchase_invoice', $invoice, '518', '321', 400.0);
        $pdo->prepare("UPDATE journal_entries SET document_no = 'DOD-2099-19' WHERE id = ?")->execute([$entry]);

        (new DocumentEntryNumberBackfill($this->db))->run($this->supplierId, true);
        self::assertSame('DOD-2099-19', $this->documentNo($entry));

        $again = $this->post('purchase_invoice', $invoice, '518', '321', 400.0);
        self::assertSame($entry, $again, 'Přeúčtování přepisuje týž zápis.');
        self::assertSame('PF-DOCNO-7', $this->documentNo($entry));
    }

    public function testRepostOfEntryWithoutNumberFillsIt(): void
    {
        $client = $this->client('Odběratel přeúčtování');
        $invoice = $this->saleInvoice('FV-DOCNO-3', $client, 800.0);
        $entryId = $this->post('invoice', $invoice, '311', '602', 800.0);
        $this->db->pdo()->prepare('UPDATE journal_entries SET document_no = NULL WHERE id = ?')->execute([$entryId]);

        // Přeúčtování předává číslo z existujícího zápisu — tady prázdné.
        $again = $this->post('invoice', $invoice, '311', '604', 800.0, ['document_no' => null]);
        self::assertSame($entryId, $again, 'Přeúčtování přepisuje týž zápis.');
        self::assertSame('FV-DOCNO-3', $this->documentNo($entryId));
    }

    public function testBackfillFillsOnlyOpenPeriods(): void
    {
        $client = $this->client('Odběratel backfill');
        $open = $this->post('invoice', $this->saleInvoice('FV-DOCNO-4', $client, 100.0), '311', '602', 100.0);
        $closedPeriod = $this->periods->create($this->supplierId, self::YEAR - 1, (self::YEAR - 1) . '-01-01', (self::YEAR - 1) . '-12-31');
        $closed = $this->post('invoice', $this->saleInvoice('FV-DOCNO-5', $client, 100.0), '311', '602', 100.0, ['entry_date' => (self::YEAR - 1) . '-06-10']);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE journal_entries SET document_no = NULL WHERE id IN (?, ?)')->execute([$open, $closed]);
        $pdo->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ?")->execute([$closedPeriod]);

        $backfill = new DocumentEntryNumberBackfill($this->db);
        $dry = $backfill->run($this->supplierId, false);
        $ids = array_column($dry['changes'], 'entry_id');
        self::assertContains($open, $ids);
        self::assertNotContains($closed, $ids, 'Uzavřené období zůstává beze změny.');
        self::assertNull($this->documentNo($open), 'Dry-run nic nezapisuje.');

        $backfill->run($this->supplierId, true);
        self::assertSame('FV-DOCNO-4', $this->documentNo($open));
        self::assertNull($this->documentNo($closed));
        self::assertSame(0, $backfill->run($this->supplierId, false)['changed'], 'Druhý běh nemá co měnit.');
    }

    /** Zápis převzatý z jiného programu nese číslo dokladu zdroje (i prázdné) a nedoplňuje se. */
    public function testBackfillLeavesTakenOverEntryNumberAsTheSourceHadIt(): void
    {
        $client = $this->client('Odběratel převzatý');
        $entry = $this->post('invoice', $this->saleInvoice('FV-DOCNO-6', $client, 100.0), '311', '602', 100.0);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE journal_entries SET document_no = NULL WHERE id = ?')->execute([$entry]);
        $pdo->prepare("INSERT INTO money_s3_import_map (supplier_id, kind, money_key, target_id) VALUES (?, 'journal_entry', 'synth|ZAP-6', ?)")
            ->execute([$this->supplierId, $entry]);

        $result = (new DocumentEntryNumberBackfill($this->db))->run($this->supplierId, true);

        self::assertNotContains($entry, array_column($result['changes'], 'entry_id'));
        self::assertNull($this->documentNo($entry));
    }

    /** Saldokonto ukáže vedle doc_no druhé číslo a doc_no samotné nemění. */
    public function testSaldoShowsOtherNumberBesideDocNo(): void
    {
        $vendor = $this->client('Dodavatel saldokonta');
        $both = $this->purchaseInvoice('DOD-2099-30', $vendor, 500.0);
        $vendorOnly = $this->purchaseInvoice('DOD-2099-31', $vendor, 300.0);
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET varsymbol = 'PF-DOCNO-30' WHERE id = ?")->execute([$both]);
        $this->post('purchase_invoice', $both, '518', '321', 500.0);
        $this->post('purchase_invoice', $vendorOnly, '518', '321', 300.0);

        $items = [];
        $saldo = $this->container->get(SaldoService::class)->build($this->supplierId, $this->periodId, self::YEAR . '-12-31', '321');
        foreach ($saldo['accounts'] as $account) {
            foreach ($account['partners'] as $partner) {
                foreach ($partner['items'] as $item) {
                    $items[$item['doc_id']] = $item;
                }
            }
        }

        self::assertSame('PF-DOCNO-30', $items[$both]['doc_no']);
        self::assertSame('DOD-2099-30', $items[$both]['other_doc_no']);
        self::assertSame('#' . $vendorOnly, $items[$vendorOnly]['doc_no'], 'doc_no otevřené faktury zůstává podle SaldoRepository.');
        self::assertSame('DOD-2099-31', $items[$vendorOnly]['other_doc_no']);
    }

    /** @param array<string,mixed> $meta */
    private function post(string $type, int $id, string $debit, string $credit, float $amount, array $meta = []): int
    {
        return $this->posting->postDocument($this->supplierId, $type, $id, [
            ['account_code' => $debit, 'side' => 'debit', 'amount' => $amount],
            ['account_code' => $credit, 'side' => 'credit', 'amount' => $amount],
        ], $meta + ['entry_date' => self::YEAR . '-06-10', 'posted_by' => $this->userId, 'user_id' => $this->userId]);
    }

    private function documentNo(int $entryId): ?string
    {
        $no = $this->db->pdo()->query("SELECT document_no FROM journal_entries WHERE id = {$entryId}")->fetchColumn();
        return $no === false || $no === null ? null : (string) $no;
    }
}
