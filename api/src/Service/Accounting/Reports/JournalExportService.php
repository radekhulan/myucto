<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\AutomationProvenanceService;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\JournalLineAmount;
use PDO;

/**
 * Export účetního deníku (audit 2026-07, nález „Export a tisk účetního deníku" —
 * §13 ZoÚ, deník je zákonná kniha). Sestavuje data pro PDF/XLSX export přesně nad
 * filtry, které dnes podporuje GET /accounting/journal (období, rozsah dat, zdroj,
 * stav) — žádné vlastní filtrování navíc.
 *
 * Export NENÍ stránkovaný (na rozdíl od listu deníku) — kniha musí obsahovat
 * všechny odpovídající zápisy. MAX_ROWS je tvrdý strop proti obřímu/neúmyslnému
 * exportu celé historie firmy v jednom requestu; při překročení Action vrátí 422
 * s pokynem zúžit rozsah dat (stejný vzor jako BULK_POST_LIMIT v JournalAction).
 *
 * Jednoduchý zápis (firma má {@see EntryFormService::hasAnySingle()}): export má
 * `layout = 'pairs'` a zápis nese `pairs` = jeden řádek na dvojici {pair_no, účet MD,
 * účet Dal, částka, text, středisko} a `pair_status`. Zápisy s `pair_no` jdou po svých
 * dvojicích, starší zápisy rozložené za běhu ({@see JournalLineContext::derivedPairs()}),
 * M : N zůstane s řádky v původním tvaru a se stavem `composite_mn`. Limit zůstává na
 * počtu ZÁPISŮ, ne řádků. Firma bez jednoduchého zápisu dostane přesně dnešní data.
 */
final class JournalExportService
{
    public const MAX_ROWS = 5000;

    /**
     * Strop jednoho souboru deníku v uzávěrkovém balíčku. Balíček běží ve workeru a
     * deník se sází po kusech (ChunkedHtmlWriter): 13 tis. zápisů ~ 260 MB a dvě minuty.
     * Nad strop balíček sestaví deník po měsících.
     */
    public const PACKAGE_MAX_ROWS = 20000;

    public function __construct(
        private readonly JournalEntryRepository $journal,
        private readonly Connection $db,
        private readonly AutomationProvenanceService $automationProvenance,
        private readonly EntryFormService $entryForms,
    ) {}

    /**
     * @param array{document_no?:string, period_id?:int, date_from?:string, date_to?:string, source_type?:string, source_id?:int, posted?:bool, automation?:string} $filters
     * @return array<string,mixed>
     * @throws ReportException 422 too_many_rows
     */
    public function build(int $supplierId, array $filters, int $maxRows = self::MAX_ROWS): array
    {
        $entries = $this->journal->forExport($supplierId, $filters, $maxRows);
        if (count($entries) > $maxRows) {
            throw new ReportException(
                'too_many_rows',
                'Filtru odpovídá příliš mnoho zápisů (nad ' . $maxRows . ') pro jeden export — zúžte rozsah dat (období/datum).',
                422,
            );
        }

        $ids = array_map(static fn (array $e): int => (int) $e['id'], $entries);
        $single = $this->entryForms->hasAnySingle($supplierId);
        $linesByEntry = $this->journal->linesForEntries($ids, $supplierId, $single);
        $provenance = $this->automationProvenance->forJournalEntries($supplierId, $ids);
        $pairsByEntry = null;
        if ($single) {
            $pairsByEntry = self::pairsForEntries($linesByEntry);
            // Řádky zápisu v exportu mají tvar jako dřív, id a cizí částka sloužily jen dvojicím.
            $linesByEntry = array_map(static fn (array $lines): array => array_map(
                static fn (array $l): array => array_diff_key($l, ['line_id' => 1, 'account_id' => 1, 'amount_decimal' => 1, 'amount_foreign' => 1]),
                $lines,
            ), $linesByEntry);
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        foreach ($entries as &$entry) {
            $lines = $linesByEntry[$entry['id']] ?? [];
            $entry['lines'] = $lines;
            $entry['automation'] = $provenance[(int) $entry['id']] ?? null;
            $entry['automation_origin'] = match ($entry['automation']['mode'] ?? null) {
                'auto' => 'automaticky',
                'approved' => 'potvrzeno',
                default => 'ručně',
            };
            if ($pairsByEntry !== null) {
                $entry += $pairsByEntry[(int) $entry['id']] ?? ['pair_status' => JournalLineContext::PAIR_STATUS_COMPOSITE, 'pairs' => []];
            }
            foreach ($lines as $line) {
                $amount = JournalLineAmount::signed($line);
                if ($line['side'] === 'debit') {
                    $totalDebit += $amount;
                } else {
                    $totalCredit += $amount;
                }
            }
        }
        unset($entry);

        $out = [
            'entity'  => $this->loadEntity($supplierId),
            'filters' => [
                'document_no' => $filters['document_no'] ?? null,
                'date_from'   => $filters['date_from'] ?? null,
                'date_to'     => $filters['date_to'] ?? null,
                'source_type' => $filters['source_type'] ?? null,
                'posted'      => array_key_exists('posted', $filters) ? $filters['posted'] : null,
                'automation'  => $filters['automation'] ?? null,
            ],
            'entries' => $entries,
            'totals'  => [
                'debit'  => round($totalDebit, 2),
                'credit' => round($totalCredit, 2),
                'count'  => count($entries),
            ],
        ];
        if ($pairsByEntry !== null) {
            $out['layout'] = 'pairs';
        }
        return $out;
    }

    /**
     * Dvojice zápisů exportu z řádků {@see JournalEntryRepository::linesForEntries()}
     * (bez dalšího dotazu). Stav: `paired` (řádky nesou `pair_no`), `derived` (starší
     * zápis rozložený za běhu), `composite_mn` (zůstává po řádcích).
     *
     * @param array<int, list<array<string,mixed>>> $linesByEntry řádky s `line_id`, `account_id`, `amount_decimal`, `amount_foreign`
     * @return array<int, array{pair_status:string, pairs:list<array<string,mixed>>}>
     */
    private static function pairsForEntries(array $linesByEntry): array
    {
        $lines = [];
        foreach ($linesByEntry as $entryId => $entryLines) {
            foreach ($entryLines as $r) {
                $red = (bool) $r['is_red_storno'];
                $lines[$entryId][$r['line_id']] = [
                    'line_id'        => $r['line_id'],
                    'account_id'     => $r['account_id'],
                    'account_code'   => (string) $r['account_code'],
                    'account_name'   => (string) $r['account_name'],
                    'side'           => $red ? ($r['side'] === 'debit' ? 'credit' : 'debit') : (string) $r['side'],
                    'raw_side'       => (string) $r['side'],
                    'is_red_storno'  => $red,
                    'amount'         => $r['amount_decimal'],
                    'amount_foreign' => $r['amount_foreign'],
                    'amount_cents'   => JournalLineAmount::cents($r['amount_decimal']),
                    'cost_center'    => $r['cost_center'],
                    'pair_no'        => $r['pair_no'] ?? null,
                    'line_text'      => $r['line_text'] ?? null,
                ];
            }
        }

        $out = [];
        foreach ($lines as $entryId => $byId) {
            $byPairNo = [];
            foreach ($byId as $l) {
                if ($l['pair_no'] !== null) {
                    $byPairNo[$l['pair_no']][] = $l;
                }
            }
            if ($byPairNo !== [] && count($byPairNo) * 2 === count($byId)) {
                ksort($byPairNo);
                $rows = [];
                foreach ($byPairNo as $pairNo => $pair) {
                    if (count($pair) !== 2 || $pair[0]['raw_side'] === $pair[1]['raw_side']) {
                        $rows = null;
                        break;
                    }
                    [$debit, $credit] = $pair[0]['raw_side'] === 'debit' ? [$pair[0], $pair[1]] : [$pair[1], $pair[0]];
                    $rows[] = self::pairRow($pairNo, $debit, $credit, $debit['amount']);
                }
                if ($rows !== null) {
                    $out[$entryId] = ['pair_status' => JournalLineContext::PAIR_STATUS_PAIRED, 'pairs' => $rows];
                    continue;
                }
            }
            $derived = $byPairNo === [] ? JournalLineContext::derivedPairs(array_values($byId)) : null;
            if ($derived === null) {
                $out[$entryId] = ['pair_status' => JournalLineContext::PAIR_STATUS_COMPOSITE, 'pairs' => []];
                continue;
            }
            $rows = [];
            foreach ($derived as $pair) {
                $rows[] = self::pairRow($pair['pair_no'], $byId[$pair['debit_line_id']], $byId[$pair['credit_line_id']], $pair['amount']);
            }
            $out[$entryId] = ['pair_status' => JournalLineContext::PAIR_STATUS_DERIVED, 'pairs' => $rows];
        }
        return $out;
    }

    /**
     * Řádek exportu za dvojici. Částka je se znaménkem (červené storno záporně), text
     * dvojice z `line_text`, středisko z řádku, který ho nese (automatika ho razítkuje jen
     * na výsledkový řádek).
     *
     * @param array<string,mixed> $debit
     * @param array<string,mixed> $credit
     * @return array<string,mixed>
     */
    private static function pairRow(int $pairNo, array $debit, array $credit, string $amount): array
    {
        $text = trim((string) ($debit['line_text'] ?? '')) !== '' ? (string) $debit['line_text'] : (string) ($credit['line_text'] ?? '');
        $costCenter = $debit['cost_center'] !== null && $debit['cost_center'] !== '' ? $debit['cost_center'] : $credit['cost_center'];
        return [
            'pair_no'             => $pairNo,
            'debit_account_code'  => $debit['account_code'],
            'debit_account_name'  => $debit['account_name'],
            'credit_account_code' => $credit['account_code'],
            'credit_account_name' => $credit['account_name'],
            'amount'              => JournalLineAmount::signed(['amount' => $amount, 'is_red_storno' => $debit['is_red_storno']]),
            'is_red_storno'       => $debit['is_red_storno'],
            'text'                => $text !== '' ? $text : null,
            'cost_center'         => $costCenter !== null && $costCenter !== '' ? (string) $costCenter : null,
        ];
    }

    /**
     * @return array{name:string, ico:?string, address:string, prepared_at:string}
     */
    private function loadEntity(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name, street, city, zip, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $addressParts = array_filter([
            trim((string) ($row['street'] ?? '')),
            trim((string) ($row['zip'] ?? '') . ' ' . (string) ($row['city'] ?? '')),
        ], static fn (string $p): bool => $p !== '');

        return [
            'name'        => (string) ($row['company_name'] ?? ''),
            'ico'         => $row['ic'] !== null && $row['ic'] !== '' ? (string) $row['ic'] : null,
            'address'     => implode(', ', $addressParts),
            'prepared_at' => (new \DateTimeImmutable())->format('d.m.Y H:i'),
        ];
    }
}
