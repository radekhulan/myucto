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
        \MyInvoice\Service\Accounting\EntryForm\EntryFormService::forgetShared($supplierId);
    }

    /**
     * Celý deník firmy je po dvojicích: žádný zápis nezůstal složený (`journal_entry_pair_status`)
     * a každý zápis je rozdělený na platné dvojice se součtem rovným obratu MD zápisu.
     * Zápisy s výslovně povoleným důvodem posouzení (např. přeúčtování mezi buňkami
     * dimenzí téhož účtu, které dvojici tvořit nemůže) se jen spočítají a vynechají.
     *
     * @param list<string> $allowedReasons důvody `journal_entry_pair_status`, které scénář očekává
     * @return int počet ověřených zápisů po dvojicích
     */
    protected function assertSupplierJournalPaired(PDO $pdo, int $supplierId, array $allowedReasons = []): int
    {
        $stmt = $pdo->prepare(
            'SELECT s.entry_id, s.status, s.reason, e.source_type, e.description FROM journal_entry_pair_status s
               JOIN journal_entries e ON e.supplier_id = s.supplier_id AND e.id = s.entry_id
              WHERE s.supplier_id = ? ORDER BY s.entry_id'
        );
        $stmt->execute([$supplierId]);
        $unpaired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $skip = [];
        foreach ($unpaired as $i => $row) {
            if (in_array($row['reason'], $allowedReasons, true)) {
                $skip[(int) $row['entry_id']] = true;
                unset($unpaired[$i]);
            }
        }
        self::assertSame([], array_values($unpaired), 'Zápisy bez dvojic: ' . json_encode(array_values($unpaired), JSON_UNESCAPED_UNICODE));
        $stmt = $pdo->prepare(
            "SELECT e.id, SUM(CASE WHEN (l.side = 'debit') <> (l.is_red_storno = 1) THEN l.amount ELSE 0 END) turnover
               FROM journal_entries e JOIN journal_entry_lines l ON l.supplier_id = e.supplier_id AND l.entry_id = e.id
              WHERE e.supplier_id = ? GROUP BY e.id ORDER BY e.id"
        );
        $stmt->execute([$supplierId]);
        $entries = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertNotSame([], $entries, 'Firma nemá žádný zápis.');
        $entries = array_diff_key($entries, $skip);
        foreach ($entries as $entryId => $turnover) {
            $this->assertEntryPaired($pdo, $supplierId, (int) $entryId, (string) $turnover);
        }
        return count($entries);
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
