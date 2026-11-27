<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Accounting\Assets\DisposalResiduals;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Účetní ZC karty vyřazené bez zaúčtování s odkazem na zápis deníku, který vyřazení
 * zaúčtoval (`assets.disposal_entry_id`).
 */
final class DisposalResidualsJournalLinkTest extends TestCase
{
    private PDO $pdo;
    private DisposalResiduals $residuals;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE chart_of_accounts (id INTEGER PRIMARY KEY, account_code TEXT, account_type TEXT)');
        $this->pdo->exec('CREATE TABLE journal_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_date TEXT, source_type TEXT, source_id INTEGER, posted_at TEXT, reversed_by INTEGER, document_no TEXT)');
        $this->pdo->exec('CREATE TABLE journal_entry_lines (id INTEGER PRIMARY KEY, supplier_id INTEGER, entry_id INTEGER, account_id INTEGER, side TEXT, amount REAL, is_red_storno INTEGER NOT NULL DEFAULT 0, signed_amount REAL GENERATED ALWAYS AS (CASE WHEN is_red_storno = 1 THEN -amount ELSE amount END) VIRTUAL)');
        $this->pdo->exec('CREATE TABLE assets (id INTEGER PRIMARY KEY, supplier_id INTEGER, inventory_number TEXT, name TEXT, disposal_date TEXT,
            disposal_type TEXT, disposal_price REAL, input_price REAL, opening_tax_years INTEGER DEFAULT 0, opening_tax_amount REAL DEFAULT 0,
            opening_acc_amount REAL DEFAULT 0, tax_method TEXT, asset_account_code TEXT, accumulated_account_code TEXT, status TEXT, disposal_entry_id INTEGER)');
        $this->pdo->exec('CREATE TABLE asset_improvements (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, completed_on TEXT, amount REAL)');
        $this->pdo->exec('CREATE TABLE depreciation_entries (id INTEGER PRIMARY KEY, supplier_id INTEGER, asset_id INTEGER, kind TEXT, fiscal_year INTEGER, amount REAL, residual_value_end REAL)');
        $this->pdo->exec("INSERT INTO chart_of_accounts VALUES (1,'541','expense'),(2,'082','asset'),(3,'022','asset'),(4,'518','expense'),(5,'221','asset')");

        $db = new Connection(new Config([]));
        (new \ReflectionClass($db))->getProperty('pdo')->setValue($db, $this->pdo);
        $this->residuals = new DisposalResiduals($db);
    }

    public function testLinkedEntryIsTheBookResidualEvenWhenCardDiffers(): void
    {
        $this->entry(10, [['541', 'debit', 1200], ['082', 'credit', 1200]]);
        $this->card(1, 'A', 100000, 99000, 10); // karta by dala 1 000

        $result = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31');
        $row = $result['rows'][0];
        self::assertSame([1200.0, 'linked_entry', 1200.0, '54'],
            [$row['book_residual_value'], $row['book_residual_source'], $row['journal_residual_value'], $row['expense_group']]);
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('zápis č. 10) 1 200,00 Kč, podle karty 1 000,00 Kč', $warnings);
    }

    public function testLinkedEntryNetsRedStornoAgainstBookResidual(): void
    {
        $this->entry(10, [['541', 'debit', 1200], ['082', 'credit', 1200],
            ['541', 'debit', 200, true], ['082', 'credit', 200, true]]);
        $this->card(1, 'A', 100000, 99000, 10);

        $row = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31')['rows'][0];
        self::assertSame([1000.0, 'linked_entry', 1000.0],
            [$row['book_residual_value'], $row['book_residual_source'], $row['journal_residual_value']]);
    }

    /** Roční interní doklad nese i odpisy jiných karet: ZC karty je jen MD 54x proti jejím oprávkám. */
    public function testLinkedEntryWithOtherLinesTakesOnlyResidualOfTheCard(): void
    {
        $this->pdo->exec("INSERT INTO chart_of_accounts VALUES (6,'551','expense'),(7,'079','asset'),(8,'548','expense')");
        $this->entry(10, [['551', 'debit', 2960], ['079', 'credit', 2960], ['541', 'debit', 1000], ['082', 'credit', 1000],
            ['082', 'debit', 100000], ['022', 'credit', 100000]]);
        $this->card(1, 'A', 100000, 99000, 10);

        $result = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31');
        self::assertSame([1000.0, 'linked_entry'], [$result['rows'][0]['book_residual_value'], $result['rows'][0]['book_residual_source']]);
        self::assertSame([], $result['warnings']);

        // 54x jiné karty v témže dokladu: ZC nejvýš to, co zápis připsal na oprávky karty.
        $this->entry(11, [['548', 'debit', 700], ['221', 'credit', 700], ['541', 'debit', 500], ['082', 'credit', 500]]);
        $this->pdo->exec('UPDATE assets SET disposal_entry_id = 11, opening_acc_amount = 99500 WHERE id = 1');
        self::assertSame(500.0, $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31')['rows'][0]['book_residual_value']);
    }

    public function testEntrySharedByMoreCardsChecksTheirSumAgainstTheEntry(): void
    {
        $this->entry(10, [['541', 'debit', 1800], ['082', 'credit', 1800]]);
        $this->card(1, 'A', 100000, 99000, 10);
        $this->card(2, 'B', 50000, 49500, 10);

        $result = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31');
        self::assertSame([[1000.0, 'card'], [500.0, 'card']],
            array_map(static fn (array $r): array => [$r['book_residual_value'], $r['book_residual_source']], $result['rows']));
        self::assertStringContainsString('A, B ke dni 2025-06-30: podle karty 1 500,00 Kč, v deníku (zápis vyřazení č. 10) 1 800,00 Kč',
            implode("\n", $result['warnings']));
    }

    public function testUnlinkedCardDoesNotCountEntryOfAnotherLinkedCard(): void
    {
        $this->entry(10, [['541', 'debit', 1000], ['082', 'credit', 1000]]);
        $this->entry(11, [['541', 'debit', 500], ['082', 'credit', 500]]);
        $this->card(1, 'A', 100000, 99000, 10);
        $this->card(2, 'B', 50000, 49500, null);

        $result = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31');
        $byNumber = array_column($result['rows'], null, 'inventory_number');
        self::assertSame([500.0, 500.0], [$byNumber['B']['book_residual_value'], $byNumber['B']['journal_residual_value']]);
        self::assertSame([], $result['warnings']);
    }

    public function testJournalEntriesForFindsOnlyResidualEntriesAgainstTheCardAccount(): void
    {
        $this->entry(10, [['541', 'debit', 1000], ['082', 'credit', 1000], ['082', 'debit', 100000], ['022', 'credit', 100000]]);
        $this->entry(11, [['518', 'debit', 300], ['221', 'credit', 300]]);

        self::assertSame([10], $this->residuals->journalEntriesFor(1, '2025-06-30', '082'));
        self::assertTrue($this->residuals->isJournalDisposalEntry(1, 10, '082'));
        self::assertFalse($this->residuals->isJournalDisposalEntry(1, 11, '082'));
        self::assertFalse($this->residuals->isJournalDisposalEntry(2, 10, '082'), 'Zápis jiné firmy.');
    }

    public function testJournalEntriesForPicksEntryWithCardDocumentNumberAmongSameDayCandidates(): void
    {
        $this->entry(10, [['541', 'debit', 1000], ['082', 'credit', 1000]], 'DHM-1');
        $this->entry(11, [['541', 'debit', 500], ['082', 'credit', 500]], 'dhm-2 ');

        self::assertSame([10, 11], $this->residuals->journalEntriesFor(1, '2025-06-30', '082'));
        self::assertSame([11], $this->residuals->journalEntriesFor(1, '2025-06-30', '082', 'DHM-2'));
        self::assertSame([10, 11], $this->residuals->journalEntriesFor(1, '2025-06-30', '082', 'DHM-3'), 'Bez shody zůstane nejednoznačné.');
    }

    public function testUnlinkedCardsTakeResidualFromEntryWithTheirDocumentNumber(): void
    {
        $this->entry(10, [['541', 'debit', 1000], ['082', 'credit', 1000], ['551', 'debit', 200], ['082', 'credit', 200]], 'A');
        $this->entry(11, [['541', 'debit', 500], ['082', 'credit', 500]], 'B');
        $this->card(1, 'A', 100000, 99500, null);
        $this->card(2, 'B', 50000, 49000, null);

        $result = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31');
        $byNumber = array_column($result['rows'], null, 'inventory_number');
        self::assertSame([1000.0, 'linked_entry', 1000.0], [$byNumber['A']['book_residual_value'], $byNumber['A']['book_residual_source'], $byNumber['A']['journal_residual_value']]);
        self::assertSame([500.0, 'linked_entry', 500.0], [$byNumber['B']['book_residual_value'], $byNumber['B']['book_residual_source'], $byNumber['B']['journal_residual_value']]);
        self::assertCount(2, $result['warnings'], 'ZC karty (500 a 1 000) se liší od zápisu, karta k ověření.');
    }

    public function testDocumentNumberSharedByTwoCardsKeepsCardResidual(): void
    {
        $this->entry(10, [['541', 'debit', 1000], ['082', 'credit', 1000]], 'A');
        $this->card(1, 'A', 100000, 99000, null);
        $this->card(2, 'A', 100000, 99000, null);

        $rows = $this->residuals->forPeriod(1, '2025-01-01', '2025-12-31')['rows'];
        self::assertSame(['card', 'card'], array_column($rows, 'book_residual_source'));
    }

    /** @param list<array{0:string,1:string,2:float|int,3?:bool}> $lines */
    private function entry(int $id, array $lines, ?string $documentNo = null): void
    {
        $this->pdo->prepare("INSERT INTO journal_entries VALUES (?,1,'2025-06-30','manual',NULL,'2025-06-30',NULL,?)")->execute([$id, $documentNo]);
        foreach ($lines as $line) {
            [$code, $side, $amount] = $line;
            $red = $line[3] ?? false;
            $account = (int) $this->pdo->query("SELECT id FROM chart_of_accounts WHERE account_code = '{$code}'")->fetchColumn();
            $this->pdo->prepare('INSERT INTO journal_entry_lines (supplier_id, entry_id, account_id, side, amount, is_red_storno) VALUES (1,?,?,?,?,?)')
                ->execute([$id, $account, $side, $amount, (int) ($red ?? false)]);
        }
    }

    private function card(int $id, string $number, float $price, float $openingAcc, ?int $entryId): void
    {
        $this->pdo->prepare(
            "INSERT INTO assets (id, supplier_id, inventory_number, name, disposal_date, disposal_type, input_price, opening_acc_amount,
                                 tax_method, asset_account_code, accumulated_account_code, status, disposal_entry_id)
             VALUES (?,1,?,?,'2025-06-30','liquidated',?,?,'straight','022','082','disposed',?)"
        )->execute([$id, $number, $number, $price, $openingAcc, $entryId]);
        $this->pdo->prepare("INSERT INTO depreciation_entries (supplier_id, asset_id, kind, fiscal_year, amount, residual_value_end) VALUES (1,?,'tax',2025,0,0)")
            ->execute([$id]);
    }
}
