<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Doklady z let před „Převést od roku", které byly k začátku prvního převáděného období
 * otevřené: vzniknou jako doklady počátečního stavu navázané na otevírací zápis a jejich
 * úhrady z převedené banky se spárují. Doklad bez data úhrady s nenulovým zbytkem se jen
 * ohlásí, uhrazený před začátkem období se nepřevádí.
 */
#[Group('integration')]
final class MoneyS3OpeningDocumentsTest extends MoneyS3ImportTestCase
{
    public function testOpenDocumentsBeforeFromYearBecomeOpeningDocuments(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear());
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $issued = $this->invoice($supplierId, 'FV23001');
        self::assertNotNull($issued, $this->explain($protocol));
        $pdo = $this->db->pdo();
        self::assertTrue(OpeningBalanceDocuments::isInOpening($pdo, $supplierId, 'invoice', (int) $issued['id']));
        self::assertSame('paid', $issued['status']);
        self::assertSame('1210.00', (string) $pdo->query("SELECT SUM(amount) FROM invoice_payments WHERE invoice_id = {$issued['id']} AND source = 'bank'")->fetchColumn(),
            'Úhrada dokladu počátečního stavu je spárovaná s převedeným pohybem.');
        $purchase = $this->purchase($supplierId, 'FP23001');
        self::assertNotNull($purchase);
        self::assertTrue(OpeningBalanceDocuments::isInOpening($pdo, $supplierId, 'purchase_invoice', (int) $purchase['id']));
        self::assertSame(1, $this->rowCount('payment_matches', $supplierId, "purchase_invoice_id = {$purchase['id']} AND amount = 2420.00"));
        // Bez data úhrady (k ověření) a uhrazený před rokem 2024 doklad nevznikne.
        self::assertNull($this->invoice($supplierId, 'FV23002'));
        self::assertNull($this->invoice($supplierId, 'FV23003'));
        self::assertContains('opening_documents_unverified', $this->codes($protocol, 'issued_invoices'));
        // Vlastní zápis doklad počátečního stavu nemá, deník roku 2023 se nepřevádí.
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "entry_date < '2024-01-01'"));
        self::assertSame(0, $this->rowCount('journal_entries', $supplierId, "source_type = 'invoice' AND source_id = {$issued['id']}"));

        // Opakovaný převod nic nezdvojí.
        $before = $this->snapshotCounts($supplierId) + ['payments' => $this->rowCount('invoice_payments', $supplierId)];
        $again = $this->convert($supplierId);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->snapshotCounts($supplierId) + ['payments' => $this->rowCount('invoice_payments', $supplierId)]);
    }

    /**
     * Firma převedená dřív bez dokladů počátečního stavu, rok 2024 už uzavřený: opakovaný
     * převod doklady doplní a jejich úhradu spáruje i v uzavřeném roce (deník se nemění).
     */
    public function testRepeatedImportAddsOpeningDocumentsToClosedYear(): void
    {
        $files = SyntheticAgenda::filesWithDocumentsBeforeFirstYear();
        $without = $files;
        foreach (array_keys($without) as $path) {
            if (str_starts_with($path, 'ROK.003/')) {
                unset($without[$path]);
            }
        }
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $without);
        $supplierId = $this->supplier();
        self::assertFalse($this->convert($supplierId)->hasErrors());
        self::assertSame('closed', (string) $this->db->pdo()->query("SELECT status FROM accounting_periods WHERE supplier_id = {$supplierId} AND fiscal_year = 2024")->fetchColumn());
        $journal = $this->journalTotals($supplierId);

        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
        $protocol = $this->convert($supplierId);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        $issued = $this->invoice($supplierId, 'FV23001');
        self::assertNotNull($issued, $this->explain($protocol));
        self::assertSame('paid', $issued['status']);
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$issued['id']} AND source = 'bank' AND amount = 1210.00"), $this->explain($protocol));
        self::assertSame($journal, $this->journalTotals($supplierId), 'Deník uzavřeného roku se nemění.');
    }

    /** Pojistka: otevřené doklady převyšují počáteční stav 311 - doklady vydané strany nevzniknou. */
    public function testOpeningDocumentsExceedingOpeningBalanceAreNotCreated(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear(1000.0));
        $supplierId = $this->supplier();

        $protocol = $this->convert($supplierId);

        // Money s počátečním stavem nižším, než nesou doklady, nesedí ani v rekonciliaci (K3).
        self::assertNull($this->invoice($supplierId, 'FV23001'), $this->explain($protocol));
        self::assertContains('opening_documents_exceed_balance', $this->codes($protocol, 'issued_invoices'));
        self::assertNotNull($this->purchase($supplierId, 'FP23001'), 'Přijatá strana sedí na počáteční stav 321.');
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function convert(int $supplierId): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, fromYear: 2024));
    }

    /** @return list<string> */
    private function codes(ImportProtocol $protocol, string $step): array
    {
        return array_column(array_column($protocol->toArray()['steps'], null, 'key')[$step]['messages'] ?? [], 'code');
    }

    /** @return array<string,mixed>|null */
    private function invoice(int $supplierId, string $number): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, status, paid_total FROM invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, $number]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function purchase(int $supplierId, string $number): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, status FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$supplierId, $number]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,string> */
    private function journalTotals(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.amount ELSE -l.amount END)
               FROM journal_entry_lines l JOIN chart_of_accounts a ON a.id = l.account_id WHERE l.supplier_id = ? GROUP BY a.account_code ORDER BY a.account_code"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
