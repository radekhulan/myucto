<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Support;

use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\JournalLineAmount;
use MyInvoice\Service\Accounting\Pair\JournalPairInvariant;
use PDO;

/**
 * Společné kroky end-to-end testů jednoduchého (párového) zápisu: zapnutí formy
 * přímo v historii (bez pravidel naplánování, aby šly i moduly, které je zatím
 * odmítají) a kontrola, že zaúčtovaný zápis je celý rozdělený na platné dvojice
 * a jejich součet je částka dokladu.
 */
trait PairedJournalAssertions
{
    protected function enableSingleEntryForm(PDO $pdo, int $supplierId, string $validFrom): void
    {
        $pdo->prepare(
            "INSERT INTO accounting_entry_form_history (supplier_id, valid_from, entry_form, note)
             VALUES (?, ?, 'single', 'test') ON DUPLICATE KEY UPDATE entry_form = 'single'"
        )->execute([$supplierId, $validFrom]);
    }

    /**
     * @return list<array{string,string,string}> dvojice [účet MD, účet Dal, částka] v pořadí čísel dvojic
     */
    protected function assertEntryPaired(PDO $pdo, int $supplierId, int $entryId, ?string $expectedTotal = null): array
    {
        $stmt = $pdo->prepare(
            'SELECT l.account_id, a.account_code, l.side, l.amount, l.is_red_storno, l.pair_no, l.line_no, e.entry_date
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.supplier_id = ? AND l.entry_id = ? ORDER BY l.line_no, l.id'
        );
        $stmt->execute([$supplierId, $entryId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotSame([], $lines, 'Zápis #' . $entryId . ' nemá řádky.');
        foreach ($lines as $line) {
            self::assertNotNull(
                $line['pair_no'],
                'Zápis #' . $entryId . ' není rozdělený na dvojice: zaúčtování ve formě single řádky nepáruje '
                    . '(PostingService::prepareDocumentLines() / JournalEntryRepository::insertLines()).',
            );
        }
        $typed = array_map(static fn (array $l): array => [
            'account_id' => (int) $l['account_id'],
            'side' => (string) $l['side'],
            'amount' => (string) $l['amount'],
            'is_red_storno' => (bool) $l['is_red_storno'],
            'pair_no' => (int) $l['pair_no'],
            'line_no' => (int) $l['line_no'],
        ], $lines);
        JournalPairInvariant::assert(['entry_date' => (string) $lines[0]['entry_date']], $typed, EntryForm::Single);

        $pairs = [];
        $total = 0;
        foreach ($lines as $line) {
            $no = (int) $line['pair_no'];
            $effective = JournalPairInvariant::effectiveSide(['side' => $line['side'], 'is_red_storno' => (bool) $line['is_red_storno']]);
            $pairs[$no][$effective === 'debit' ? 0 : 1] = (string) $line['account_code'];
            $pairs[$no][2] = number_format(JournalLineAmount::cents($line['amount']) / 100, 2, '.', '');
            if ($effective === 'debit') {
                $total += JournalLineAmount::cents($line['amount']);
            }
        }
        ksort($pairs);
        if ($expectedTotal !== null) {
            self::assertSame(JournalLineAmount::cents($expectedTotal), $total, 'Součet dvojic = částka dokladu.');
        }
        return array_values(array_map(static fn (array $p): array => [$p[0], $p[1], $p[2]], $pairs));
    }
}
