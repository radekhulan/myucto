<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Assets;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Účetní a daňová zůstatková cena majetku vyřazeného v období. Jediné místo, kde se
 * obě ZC k vyřazení určují: čte ho přiznání DPPO ({@see \MyInvoice\Service\Tax\Return\DppoReturnDataProvider})
 * i evidenční podklad úprav základu daně ({@see \MyInvoice\Action\Accounting\Closing\TaxBaseReportAction}),
 * zaúčtování vyřazení v modulu majetku ({@see AssetService::dispose()}) bere účetní ZC
 * z {@see self::bookResidual()}.
 *
 * Účetní ZC:
 *   - karta vyřazená v modulu majetku: debetní nákladové řádky jejího zápisu vyřazení
 *     (`source_type = 'asset_disposal'`), tak jak se skutečně zaúčtovaly;
 *   - karta vyřazená bez zaúčtování s odkazem na zápis deníku, který vyřazení zaúčtoval
 *     (`assets.disposal_entry_id`, {@see AssetService::disposeFromJournal()}): debetní
 *     nákladové řádky toho zápisu. Nese-li zápis vyřazení více karet, rozdělit ho nejde:
 *     ZC je z karet a zápis je kontrolou jejich součtu;
 *   - karta vyřazená mimo modul bez odkazu, jejíž zápis vyřazení jde určit jednoznačně podle
 *     čísla dokladu ({@see self::documentEntries()}): MD 54x toho zápisu, stejně jako s odkazem;
 *   - karta vyřazená mimo modul bez odkazu (převod z jiného systému, kde vyřazení zaúčtoval
 *     převzatý deník, nebo ruční zápis 54x): z karty, stejným výpočtem jako při vyřazení
 *     v modulu (zvýšená vstupní cena − oprávky). Kontrolou je deník: MD 54x v dokladech ke
 *     dni vyřazení, které připisují na oprávky karty (u neodpisovaného majetku na majetkový
 *     účet). Nesedí-li, přiznání jede z karty a varování nese obě čísla.
 *
 * Daňová ZC:
 *   - poslední daňový řádek karty (`depreciation_entries.kind = 'tax'`);
 *   - bez daňových řádků u karty „daňový = účetní" (§ 24/2/v nehmotný majetek) = účetní ZC;
 *   - neodpisovaný majetek (§ 27, bez oprávkového účtu, typicky pozemek) = vstupní cena
 *     + technická zhodnocení − počáteční daňový stav;
 *   - karta s počátečním daňovým stavem (převzaté roky odpisů) = vstupní cena + TZ − ten stav;
 *   - odpisovaný majetek bez jakékoli daňové historie = NEZNÁMÁ (null). Dosazení celé
 *     vstupní ceny by u plně odepsaného majetku vyrobilo fiktivní daňový výdaj ve výši
 *     vstupní ceny; přiznání proto nic nedopočítá a varuje.
 */
final class DisposalResiduals
{
    public const BOOK_SOURCE_ENTRY = 'disposal_entry';
    public const BOOK_SOURCE_LINKED_ENTRY = 'linked_entry';
    public const BOOK_SOURCE_CARD = 'card';

    public const TAX_SOURCE_ENTRIES = 'tax_entries';
    public const TAX_SOURCE_BY_ACCOUNTING = 'by_accounting';
    public const TAX_SOURCE_NON_DEPRECIABLE = 'non_depreciable';
    public const TAX_SOURCE_OPENING = 'opening';
    public const TAX_SOURCE_UNKNOWN = 'unknown';

    /** Účtová skupina nákladů, na kterou převzaté vyřazení účtuje ZC (541, 543, 549). */
    private const JOURNAL_EXPENSE_PREFIX = '54';

    public function __construct(private readonly Connection $db) {}

    /**
     * Daňová uznatelnost zůstatkové ceny podle způsobu vyřazení. Prodej a likvidace
     * plně (§ 24 odst. 2 písm. b) ZDP), dar vůbec, škoda jen do výše náhrad nebo při
     * živelní pohromě či neznámém pachateli (§ 24 odst. 2 písm. l) ZDP), tedy jen ruční
     * položkou. Platí stejně pro DPPO (můstek ZC) i pro DPFO v daňové evidenci.
     *
     * @return 'full'|'none'|'limited'
     */
    public static function deductibility(string $disposalType): string
    {
        return match ($disposalType) {
            'sold', 'liquidated' => 'full',
            'donated' => 'none',
            default => 'limited',
        };
    }

    /**
     * Účetní ZC ke dni vyřazení: neodpisovaný majetek celou (zvýšenou) vstupní cenou,
     * odpisovaný zvýšenou vstupní cenou po odečtení oprávek, nejméně nula.
     */
    public static function bookResidual(float $increasedPrice, float $accumulatedDepreciation, bool $depreciable): float
    {
        if (!$depreciable) {
            return round($increasedPrice, 2);
        }
        return max(0.0, round($increasedPrice - $accumulatedDepreciation, 2));
    }

    /**
     * Účet, na který zápis vyřazení připisuje zůstatkovou cenu: oprávky karty, u
     * neodpisovaného majetku (bez oprávek) majetkový účet.
     *
     * @param array<string,mixed> $asset
     */
    public static function residualCreditAccount(array $asset): string
    {
        $accumulated = $asset['accumulated_account_code'] ?? null;
        return $accumulated !== null && (string) $accumulated !== ''
            ? (string) $accumulated
            : (string) $asset['asset_account_code'];
    }

    /**
     * @return array{
     *   rows: list<array{
     *     asset_id:int, inventory_number:string, name:string, disposal_date:string,
     *     disposal_type:string, disposal_price:?float,
     *     book_residual_value:float, book_residual_source:string, journal_residual_value:?float,
     *     tax_residual_value:?float, tax_residual_source:string, expense_group:string
     *   }>,
     *   warnings: list<string>
     * }
     */
    public function forPeriod(int $supplierId, string $startsOn, string $endsOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.inventory_number, a.name, a.disposal_date, a.disposal_type, a.disposal_price,
                    a.input_price, a.opening_tax_years, a.opening_tax_amount, a.opening_acc_amount,
                    a.tax_method, a.asset_account_code, a.accumulated_account_code,
                    (SELECT COALESCE(SUM(ai.amount), 0) FROM asset_improvements ai
                      WHERE ai.supplier_id = a.supplier_id AND ai.asset_id = a.id
                        AND ai.completed_on <= a.disposal_date) AS improvements_total,
                    (SELECT de.residual_value_end FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'tax\'
                      ORDER BY de.fiscal_year DESC LIMIT 1) AS tax_residual,
                    (SELECT COALESCE(SUM(de.amount), 0) FROM depreciation_entries de
                      WHERE de.supplier_id = a.supplier_id AND de.asset_id = a.id AND de.kind = \'accounting\') AS accounting_total,
                    (SELECT SUM(jl.signed_amount)
                       FROM journal_entries je
                       JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                        AND jl.side = \'debit\' AND ca.account_type = \'expense\') AS entry_residual,
                    (SELECT MIN(ca.account_code)
                       FROM journal_entries je
                       JOIN journal_entry_lines jl ON jl.entry_id = je.id AND jl.supplier_id = je.supplier_id
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                        AND jl.side = \'debit\' AND ca.account_type = \'expense\') AS entry_expense_account,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.supplier_id = a.supplier_id AND je.source_type = \'asset_disposal\'
                        AND je.source_id = a.id AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                      ORDER BY je.id DESC LIMIT 1) AS disposal_entry_id,
                    (SELECT je.id FROM journal_entries je
                      WHERE je.id = a.disposal_entry_id AND je.supplier_id = a.supplier_id
                        AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL) AS linked_entry_id,
                    (SELECT SUM(jl.signed_amount)
                       FROM journal_entry_lines jl
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE jl.entry_id = a.disposal_entry_id AND jl.supplier_id = a.supplier_id
                        AND jl.side = \'debit\' AND ca.account_code LIKE \'' . self::JOURNAL_EXPENSE_PREFIX . '%\') AS linked_residual,
                    (SELECT SUM(jl.signed_amount)
                       FROM journal_entry_lines jl
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE jl.entry_id = a.disposal_entry_id AND jl.supplier_id = a.supplier_id AND jl.side = \'credit\'
                        AND (ca.account_code = COALESCE(NULLIF(a.accumulated_account_code, \'\'), a.asset_account_code)
                             OR ca.account_code LIKE CONCAT(COALESCE(NULLIF(a.accumulated_account_code, \'\'), a.asset_account_code), \'.%\'))
                    ) AS linked_account_credit,
                    (SELECT MIN(ca.account_code)
                       FROM journal_entry_lines jl
                       JOIN chart_of_accounts ca ON ca.id = jl.account_id
                      WHERE jl.entry_id = a.disposal_entry_id AND jl.supplier_id = a.supplier_id
                        AND jl.side = \'debit\' AND ca.account_code LIKE \'' . self::JOURNAL_EXPENSE_PREFIX . '%\') AS linked_expense_account,
                    (SELECT COUNT(*) FROM assets la
                      WHERE la.supplier_id = a.supplier_id AND la.disposal_entry_id = a.disposal_entry_id
                        AND la.status = \'disposed\') AS linked_share
               FROM assets a
              WHERE a.supplier_id = ? AND a.status = \'disposed\'
                AND a.disposal_date BETWEEN ? AND ?
              ORDER BY a.disposal_date, a.inventory_number'
        );
        $stmt->execute([$supplierId, $startsOn, $endsOn]);

        $cards = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $byDocument = $this->documentEntries($supplierId, array_values(array_filter(
            $cards,
            static fn (array $r): bool => $r['disposal_entry_id'] === null && $r['linked_entry_id'] === null,
        )));

        $rows = [];
        $warnings = [];
        $groups = [];
        foreach ($cards as $r) {
            $number = (string) $r['inventory_number'];
            $depreciable = $r['accumulated_account_code'] !== null && (string) $r['accumulated_account_code'] !== '';
            $increased = round((float) $r['input_price'] + (float) $r['improvements_total'], 2);

            $journal = null;
            if ($r['disposal_entry_id'] !== null) {
                $book = round((float) ($r['entry_residual'] ?? 0), 2);
                $bookSource = self::BOOK_SOURCE_ENTRY;
                $expenseAccount = $r['entry_expense_account'] !== null ? (string) $r['entry_expense_account'] : null;
            } else {
                $book = self::bookResidual(
                    $increased,
                    (float) $r['opening_acc_amount'] + (float) $r['accounting_total'],
                    $depreciable,
                );
                $bookSource = self::BOOK_SOURCE_CARD;
                $expenseAccount = null;
                if ($r['linked_entry_id'] === null && isset($byDocument[(int) $r['id']])) {
                    // Zápis vyřazení bez odkazu, ale jednoznačně podle čísla dokladu: ZC je
                    // MD 54x zápisu, nejvýš to, co zápis připsal na účet karty.
                    $entry = $byDocument[(int) $r['id']];
                    $journal = $entry['residual'];
                    if (abs($journal - $book) >= 0.01) {
                        $warnings[] = 'Účetní ZC vyřazeného majetku ' . $number . ' ke dni ' . (string) $r['disposal_date']
                            . ': v zápisu vyřazení (deník, zápis č. ' . $entry['entry'] . ' s číslem dokladu karty) '
                            . self::money($journal) . ', podle karty ' . self::money($book)
                            . '. Přiznání počítá se ZC ze zápisu; kartu ověřte.';
                    }
                    $book = $journal;
                    $bookSource = self::BOOK_SOURCE_LINKED_ENTRY;
                    $expenseAccount = $entry['expense_account'];
                } elseif ($r['linked_entry_id'] !== null && (int) $r['linked_share'] === 1) {
                    // Doklad vyřazení může nést i jiné řádky (Money: roční interní doklad s odpisy
                    // dalších karet): ZC karty je MD 54x zápisu, nejvýš to, co zápis připsal na
                    // oprávky (majetkový účet) karty.
                    $journal = round(min((float) ($r['linked_residual'] ?? 0), (float) ($r['linked_account_credit'] ?? 0)), 2);
                    if (abs($journal - $book) >= 0.01) {
                        $warnings[] = 'Účetní ZC vyřazeného majetku ' . $number . ' ke dni ' . (string) $r['disposal_date']
                            . ': v zápisu vyřazení (deník, zápis č. ' . (int) $r['linked_entry_id'] . ') ' . self::money($journal)
                            . ', podle karty ' . self::money($book) . '. Přiznání počítá se ZC ze zápisu; kartu ověřte.';
                    }
                    $book = $journal;
                    $bookSource = self::BOOK_SOURCE_LINKED_ENTRY;
                    $expenseAccount = $r['linked_expense_account'] !== null ? (string) $r['linked_expense_account'] : null;
                } elseif ($r['linked_entry_id'] !== null) {
                    // Jeden zápis vyřadil více karet: ZC z karet, zápis kontroluje jejich součet.
                    $key = 'entry|' . (int) $r['linked_entry_id'];
                    $groups[$key]['entry'] = (int) $r['linked_entry_id'];
                    $groups[$key]['journal'] = round((float) ($r['linked_residual'] ?? 0), 2);
                } else {
                    $creditAccount = self::residualCreditAccount($r);
                    $key = (string) $r['disposal_date'] . '|' . $creditAccount;
                    $groups[$key]['account'] = $creditAccount;
                }
                if ($bookSource === self::BOOK_SOURCE_CARD) {
                    $groups[$key]['date'] = (string) $r['disposal_date'];
                    $groups[$key]['numbers'][] = $number;
                    $groups[$key]['card'] = round(($groups[$key]['card'] ?? 0.0) + $book, 2);
                    $groups[$key]['rows'][] = count($rows);
                }
            }

            [$tax, $taxSource] = $this->taxResidual($r, $book, $increased, $depreciable);
            if ($taxSource === self::TAX_SOURCE_UNKNOWN) {
                $warnings[] = 'U majetku ' . $number . ' (' . (string) $r['name'] . ') není známa daňová zůstatková cena: '
                    . 'karta nemá daňové odpisy ani počáteční daňový stav. Rozdíl účetní a daňové ZC se proto '
                    . 'nedopočítal; daňovou ZC ověřte a rozdíl zadejte ruční položkou přiznání.';
            }

            $rows[] = [
                'asset_id' => (int) $r['id'],
                'inventory_number' => $number,
                'name' => (string) $r['name'],
                'disposal_date' => (string) $r['disposal_date'],
                'disposal_type' => (string) $r['disposal_type'],
                'disposal_price' => $r['disposal_price'] !== null ? (float) $r['disposal_price'] : null,
                'book_residual_value' => $book,
                'book_residual_source' => $bookSource,
                'journal_residual_value' => $journal,
                'tax_residual_value' => $tax,
                'tax_residual_source' => $taxSource,
                'expense_group' => $expenseAccount !== null ? substr($expenseAccount, 0, 2) : self::JOURNAL_EXPENSE_PREFIX,
            ];
        }

        foreach ($groups as $g) {
            $journal = isset($g['entry'])
                ? $g['journal']
                : $this->journalResidual($supplierId, $g['date'], $g['account'], array_column($byDocument, 'entry'));
            foreach ($g['rows'] as $i) {
                $rows[$i]['journal_residual_value'] = count($g['rows']) === 1 ? $journal : null;
            }
            if (abs($journal - $g['card']) >= 0.01) {
                $warnings[] = 'Účetní ZC vyřazeného majetku ' . implode(', ', $g['numbers']) . ' ke dni ' . $g['date']
                    . ': podle karty ' . self::money($g['card']) . ', v deníku ('
                    . (isset($g['entry'])
                        ? 'zápis vyřazení č. ' . $g['entry']
                        : 'MD ' . self::JOURNAL_EXPENSE_PREFIX . 'x proti účtu ' . $g['account'])
                    . ') ' . self::money($journal)
                    . '. Přiznání počítá se ZC podle karty; rozdíl ověřte.';
            }
        }

        return ['rows' => $rows, 'warnings' => $warnings];
    }

    /**
     * @param array<string,mixed> $r
     * @return array{0:?float,1:string}
     */
    private function taxResidual(array $r, float $book, float $increased, bool $depreciable): array
    {
        if ($r['tax_residual'] !== null) {
            return [round((float) $r['tax_residual'], 2), self::TAX_SOURCE_ENTRIES];
        }
        if ((string) $r['tax_method'] === 'by_accounting') {
            return [$book, self::TAX_SOURCE_BY_ACCOUNTING];
        }
        $fromCard = max(0.0, round($increased - (float) $r['opening_tax_amount'], 2));
        if (!$depreciable) {
            return [$fromCard, self::TAX_SOURCE_NON_DEPRECIABLE];
        }
        if ((int) $r['opening_tax_years'] > 0 || (float) $r['opening_tax_amount'] > 0.0) {
            return [$fromCard, self::TAX_SOURCE_OPENING];
        }
        return [null, self::TAX_SOURCE_UNKNOWN];
    }

    /**
     * Zápisy deníku, které ke dni vyřazení zaúčtovaly zůstatkovou cenu karty: MD 54x
     * a připsání na účet karty (oprávky, u neodpisovaného majetku majetkový účet) včetně
     * jeho analytik. Podle nich se karta vyřazená bez zaúčtování naváže na zápis
     * ({@see AssetService::disposeFromJournal()}); jediný kandidát = jednoznačná vazba.
     * Vyřadil-li deník ten den víc karet proti stejnému účtu (POHODA: jeden zápis na kartu,
     * číslo dokladu = inventární číslo), rozhodne číslo dokladu: zůstane-li jediný zápis
     * s číslem dokladu rovným inventárnímu číslu karty, je to ten její.
     *
     * @return list<int>
     */
    public function journalEntriesFor(int $supplierId, string $date, string $account, ?string $inventoryNumber = null): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT je.id, je.document_no' . self::journalDisposalFrom('je.entry_date = ?') . ' ORDER BY je.id'
        );
        $stmt->execute([$supplierId, $date, self::JOURNAL_EXPENSE_PREFIX . '%', $account, $account . '.%']);
        $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $number = self::documentKey($inventoryNumber);
        if (count($candidates) > 1 && $number !== '') {
            $own = array_values(array_filter(
                $candidates,
                static fn (array $c): bool => self::documentKey($c['document_no']) === $number,
            ));
            if (count($own) === 1) {
                return [(int) $own[0]['id']];
            }
        }
        return array_map(static fn (array $c): int => (int) $c['id'], $candidates);
    }

    /**
     * Zápisy vyřazení karet bez odkazu, které jdou určit podle čísla dokladu: zaúčtovaný
     * a nestornovaný zápis ke dni vyřazení s číslem dokladu rovným inventárnímu číslu karty,
     * MD 54x a připsáním na účet karty ({@see residualCreditAccount()}), na který není
     * navázaná jiná karta. Karta, které vyjde víc zápisů, a zápis, který by připadl víc
     * kartám, zůstanou bez vazby (ZC z karty a kontrola deníkem).
     *
     * @param list<array<string,mixed>> $cards vyřazené karty bez odkazu na zápis
     * @return array<int,array{entry:int, residual:float, expense_account:?string}> podle id karty
     */
    private function documentEntries(int $supplierId, array $cards): array
    {
        $numbers = [];
        $dates = [];
        foreach ($cards as $card) {
            if (self::documentKey($card['inventory_number']) !== '') {
                $numbers[(string) $card['inventory_number']] = true;
                $dates[(string) $card['disposal_date']] = true;
            }
        }
        if ($numbers === []) {
            return [];
        }
        $numbers = array_keys($numbers);
        $dates = array_keys($dates);
        $stmt = $this->db->pdo()->prepare(
            'SELECT je.id, je.entry_date, je.document_no, l.side, ca.account_code, SUM(l.signed_amount) AS total
               FROM journal_entries je
               JOIN journal_entry_lines l ON l.entry_id = je.id AND l.supplier_id = je.supplier_id
               JOIN chart_of_accounts ca ON ca.id = l.account_id
              WHERE je.supplier_id = ?
                AND je.entry_date IN (' . implode(',', array_fill(0, count($dates), '?')) . ')
                AND je.document_no IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.source_type NOT IN (\'asset_disposal\', \'closing\')
                AND NOT EXISTS (
                    SELECT 1 FROM assets la WHERE la.supplier_id = je.supplier_id AND la.disposal_entry_id = je.id
                )
              GROUP BY je.id, je.entry_date, je.document_no, l.side, ca.account_code'
        );
        $stmt->execute([$supplierId, ...$dates, ...$numbers]);
        $entries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $entries[$id]['date'] = substr((string) $row['entry_date'], 0, 10);
            $entries[$id]['doc'] = self::documentKey($row['document_no']);
            $entries[$id]['lines'][] = [(string) $row['side'], (string) $row['account_code'], (float) $row['total']];
        }

        $found = [];
        $claims = [];
        foreach ($cards as $card) {
            $number = self::documentKey($card['inventory_number']);
            $account = self::residualCreditAccount($card);
            $matches = [];
            foreach ($entries as $id => $entry) {
                if ($number === '' || $entry['doc'] !== $number || $entry['date'] !== (string) $card['disposal_date']) {
                    continue;
                }
                $expense = 0.0;
                $expenseAccount = null;
                $credit = 0.0;
                foreach ($entry['lines'] as [$side, $code, $total]) {
                    if ($side === 'debit' && str_starts_with($code, self::JOURNAL_EXPENSE_PREFIX)) {
                        $expense += $total;
                        $expenseAccount = $expenseAccount === null || strcmp($code, $expenseAccount) < 0 ? $code : $expenseAccount;
                    } elseif ($side === 'credit' && ($code === $account || str_starts_with($code, $account . '.'))) {
                        $credit += $total;
                    }
                }
                if ($expense >= 0.005 && $credit >= 0.005) {
                    $matches[] = ['entry' => $id, 'residual' => round(min($expense, $credit), 2), 'expense_account' => $expenseAccount];
                }
            }
            if (count($matches) === 1) {
                $found[(int) $card['id']] = $matches[0];
                $claims[$matches[0]['entry']] = ($claims[$matches[0]['entry']] ?? 0) + 1;
            }
        }
        return array_filter($found, static fn (array $m): bool => $claims[$m['entry']] === 1);
    }

    private static function documentKey(mixed $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /**
     * Zaúčtoval zápis deníku zůstatkovou cenu karty? Zaúčtovaný a nestornovaný zápis firmy
     * s MD 54x proti účtu karty (stejná kritéria jako {@see journalEntriesFor()}).
     */
    public function isJournalDisposalEntry(int $supplierId, int $entryId, string $account): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1' . self::journalDisposalFrom('je.id = ?') . ' LIMIT 1');
        $stmt->execute([$supplierId, $entryId, self::JOURNAL_EXPENSE_PREFIX . '%', $account, $account . '.%']);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * MD 54x v dokladech ke dni vyřazení, které připisují na daný účet (oprávky, u
     * neodpisovaného majetku majetkový účet) včetně jeho analytik. Zápisy, na které je
     * navázaná vyřazená karta nebo které jí patří podle čísla dokladu ($own), se nepočítají.
     *
     * @param list<int> $own
     */
    private function journalResidual(int $supplierId, string $date, string $account, array $own = []): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(dl.signed_amount), 0)' . self::journalDisposalFrom('je.entry_date = ?') . '
                AND NOT EXISTS (
                    SELECT 1 FROM assets la WHERE la.supplier_id = je.supplier_id AND la.disposal_entry_id = je.id
                )' . ($own !== [] ? ' AND je.id NOT IN (' . implode(',', array_fill(0, count($own), '?')) . ')' : '')
        );
        $stmt->execute([$supplierId, $date, self::JOURNAL_EXPENSE_PREFIX . '%', $account, $account . '.%', ...$own]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Zápisy deníku s MD 54x a připsáním na účet karty. Zápisy vyřazení z modulu majetku
     * a uzávěrkové zápisy se nepočítají. Parametry: firma, $key, maska 54x, účet, účet.%.
     */
    private static function journalDisposalFrom(string $key): string
    {
        return '
               FROM journal_entries je
               JOIN journal_entry_lines dl ON dl.entry_id = je.id AND dl.supplier_id = je.supplier_id AND dl.side = \'debit\'
               JOIN chart_of_accounts dca ON dca.id = dl.account_id
              WHERE je.supplier_id = ? AND ' . $key . '
                AND je.posted_at IS NOT NULL AND je.reversed_by IS NULL
                AND je.source_type NOT IN (\'asset_disposal\', \'closing\')
                AND dca.account_code LIKE ?
                AND EXISTS (
                    SELECT 1 FROM journal_entry_lines cl
                      JOIN chart_of_accounts cca ON cca.id = cl.account_id
                     WHERE cl.entry_id = je.id AND cl.supplier_id = je.supplier_id AND cl.side = \'credit\'
                       AND (cca.account_code = ? OR cca.account_code LIKE ?)
                )';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' Kč';
    }
}
