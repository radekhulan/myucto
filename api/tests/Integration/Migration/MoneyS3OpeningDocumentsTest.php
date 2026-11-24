<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Repository\SaldoRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocumentMarker;
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

    /**
     * Po převodu bez dokladů počátečního stavu účetní pohledávku FV23001 doplnila ručně
     * a označila jako doklad počátečního stavu. Opakovaný převod ji převezme do mapy,
     * nový doklad (FV23001/2023) nezaloží a saldokonto 311 ji nezdvojí.
     */
    public function testRepeatedImportAdoptsManuallyMarkedOpeningDocument(): void
    {
        $supplierId = $this->convertWithoutOpeningDocuments();
        $manual = $this->manualOpeningInvoice($supplierId, 'FV23001', 1210.0);

        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear());
        $protocol = $this->convert($supplierId, closeHistory: false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol LIKE 'FV23001%'"), $this->explain($protocol));
        self::assertSame($manual, $this->map->all($supplierId, MoneyS3ImportRepository::KIND_INVOICE)['2023|FV23001'] ?? null);
        self::assertContains('opening_documents_adopted', $this->codes($protocol, 'issued_invoices'));
        self::assertSame(1210.0, $this->openingReceivables($supplierId), 'Saldokonto 311 k začátku roku nese pohledávku jednou.');
        self::assertSame(1, $this->rowCount('invoice_payments', $supplierId, "invoice_id = {$manual} AND source = 'bank' AND amount = 1210.00"),
            'Úhradu z převedené banky převod spáruje s převzatým ručním dokladem.');
        self::assertNotNull($this->purchase($supplierId, 'FP23001'), 'Přijatá strana se převádí jako dřív.');
    }

    /** Ručně navázaný jiný doklad zmenší zbývající krytí počátečního stavu 311: FV23001 se už nevejde. */
    public function testManuallyLinkedDocumentReducesRemainingOpeningBalance(): void
    {
        $supplierId = $this->convertWithoutOpeningDocuments();
        $this->manualOpeningInvoice($supplierId, 'RUCNI-001', 500.0);

        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear());
        $protocol = $this->convert($supplierId, closeHistory: false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(0, $this->rowCount('invoices', $supplierId, "varsymbol LIKE 'FV23001%'"), $this->explain($protocol));
        self::assertContains('opening_documents_exceed_balance', $this->codes($protocol, 'issued_invoices'));
        self::assertSame(500.0, $this->openingReceivables($supplierId));
    }

    /** Ručně navázaný doklad se stejným číslem, ale jinou částkou: kandidát se ohlásí a nezaloží. */
    public function testConflictingManualOpeningDocumentIsReported(): void
    {
        $supplierId = $this->convertWithoutOpeningDocuments();
        $manual = $this->manualOpeningInvoice($supplierId, 'FV23001', 1000.0);

        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear());
        $protocol = $this->convert($supplierId, closeHistory: false);

        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertSame(1, $this->rowCount('invoices', $supplierId, "varsymbol LIKE 'FV23001%'"), $this->explain($protocol));
        self::assertArrayNotHasKey('2023|FV23001', $this->map->all($supplierId, MoneyS3ImportRepository::KIND_INVOICE));
        self::assertContains('opening_documents_ambiguous', $this->codes($protocol, 'issued_invoices'));
        self::assertTrue(OpeningBalanceDocuments::isInOpening($this->db->pdo(), $supplierId, 'invoice', $manual));
    }

    /**
     * Počáteční stav 311 nese víc než doklady počátečního stavu (pohledávka bez dokladu):
     * rekonciliace dokladů proti deníku (K4) doklad počátečního stavu ani otevírací zápis
     * za předpis roku nebere a nesedí-li jen o zůstatek bez dokladu, nehlásí chybu.
     */
    public function testOpeningDocumentsDoNotBreakDocumentReconciliation(): void
    {
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithDocumentsBeforeFirstYear(1500.0));
        $supplierId = $this->supplier();

        $protocol = $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, fromYear: 2024, toYear: 2024));

        self::assertNotNull($this->invoice($supplierId, 'FV23001'), $this->explain($protocol));
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    // ── pomocníci ─────────────────────────────────────────────────────────────

    private function convert(int $supplierId, bool $closeHistory = true): ImportProtocol
    {
        return $this->importer->run($supplierId, $this->userId, $this->backup(), new ImportOptions(ImportOptions::MODE_IMPORT, $closeHistory, fromYear: 2024));
    }

    /** Převod bez let před rokem 2024 (bez dokladů počátečního stavu), rok 2024 zůstane otevřený. */
    private function convertWithoutOpeningDocuments(): int
    {
        $files = SyntheticAgenda::filesWithDocumentsBeforeFirstYear();
        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'ROK.003/')) {
                unset($files[$path]);
            }
        }
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', $files);
        $supplierId = $this->supplier();
        $protocol = $this->convert($supplierId, closeHistory: false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
        self::assertNull($this->invoice($supplierId, 'FV23001'));
        return $supplierId;
    }

    /** Ručně založená pohledávka odběratele z Money, označená jako doklad počátečního stavu. */
    private function manualOpeningInvoice(int $supplierId, string $number, float $total): int
    {
        $pdo = $this->db->pdo();
        $client = $pdo->prepare('SELECT id FROM clients WHERE supplier_id = ? AND ic = ?');
        $client->execute([$supplierId, SyntheticAgenda::CUSTOMER_ICO]);
        $currency = (int) $pdo->query("SELECT default_currency_id FROM supplier WHERE id = {$supplierId}")->fetchColumn();
        $base = round($total / 1.21, 2);
        $pdo->prepare(
            "INSERT INTO invoices (supplier_id, invoice_type, status, client_id, varsymbol, issue_date, tax_date, due_date, currency_id, total_without_vat, total_vat, total_with_vat)
             VALUES (?, 'invoice', 'sent', ?, ?, '2023-11-10', '2023-11-10', '2023-11-24', ?, ?, ?, ?)"
        )->execute([$supplierId, (int) $client->fetchColumn(), $number, $currency, $base, round($total - $base, 2), $total]);
        $id = (int) $pdo->lastInsertId();
        self::assertTrue($this->container(OpeningBalanceDocumentMarker::class)->mark($supplierId, 'invoice', $id, $this->userId)['in_opening']);
        return $id;
    }

    /** Neuhrazené pohledávky saldokonta 311 k 1. 1. 2024. */
    private function openingReceivables(int $supplierId): float
    {
        $saldo = $this->container(SaldoRepository::class);
        $account = $saldo->resolveAccount($supplierId, '311', '2024-01-01');
        self::assertNotNull($account);
        $sum = 0.0;
        foreach ($saldo->openItems($supplierId, (int) $account['id'], '2024-01-01', '311') as $item) {
            $sum += $item['booked_signed'] * (1 - $item['paid_ratio']);
        }
        return round($sum, 2);
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
