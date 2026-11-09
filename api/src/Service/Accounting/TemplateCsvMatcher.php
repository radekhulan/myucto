<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Service\Accounting\Codebooks\AbstractCodebookImportService;

/**
 * Napáruje CSV mzdovou/jinou rekapitulaci (2 sloupce: název položky NEBO kód účtu;
 * částka) na řádky šablony ručního zápisu (Fáze F, mzdový můstek). Čistá funkce bez
 * DB — testovatelná přímo na poli řádků šablony. Používá {@see AbstractCodebookImportService}
 * jen pro sdílené statické parsery (normalize/parseDecimal — vzor F5 číselníkových importů),
 * bez závislosti na jeho DB/report infrastruktuře (tady se nic nezapisuje, jen preview).
 *
 * Řádek CSV se považuje za datový, jen když druhý sloupec parsuje jako číslo — jinak
 * (typicky hlavička „Položka;Částka") se tiše přeskočí. Shoda: nejdřív přesný kód účtu
 * (case-insensitive), pak normalizovaný název řádku šablony (diakritika/velikost/mezery
 * sjednocené). Nenapárované řádky CSV se vrací zvlášť, ať je účetní může doplnit ručně.
 *
 * Šablona po dvojicích (jednoduchý zápis) navíc přijme řádek `MD;Dal;Částka[;Text;Středisko]`
 * (druhý sloupec kód účtu, třetí částka): páruje se na dvojici šablony se stejnými účty,
 * opakovaná dvojice po pořadí, nenalezená se připojí jako nová dvojice a dvojice se
 * stejným účtem na obou stranách skončí mezi nenapárovanými. Částka z dvousloupcového
 * řádku vyplní obě strany dvojice, aby zůstala vyrovnaná. Složená šablona se čte
 * přesně jako dřív.
 */
final class TemplateCsvMatcher
{
    private const MAX_ROWS = 500;

    /** Tvar kódu účtu ve sloupci Dal (bez mezer a desetinné čárky, ty má jen částka). */
    private const ACCOUNT_CODE = '/^[0-9A-Za-z][0-9A-Za-z.\-\/]{0,19}$/';

    /**
     * @param list<array{line_no:int,label:?string,account_code:string,side:string,default_amount:?float,cost_center:?string,pair_no?:int,line_text?:string}> $templateLines
     * @return array{
     *     lines:list<array{line_no:int,label:?string,account_code:string,side:string,cost_center:?string,amount:?float,pair_no?:int,line_text?:string}>,
     *     unmatched:list<array{value:string,amount:float,debit_account_code?:string,credit_account_code?:string}>,
     *     matched_count:int,
     * }
     */
    public function match(array $templateLines, string $csvContent): array
    {
        $rows = $this->readCsv($csvContent);

        $byCode = [];
        $byLabel = [];
        /** @var array<int,list<int>> $pairLines pair_no => line_no řádků dvojice */
        $pairLines = [];
        foreach ($templateLines as $line) {
            $byCode[strtoupper(trim($line['account_code']))] = $line['line_no'];
            $label = $line['label'] ?? '';
            if ($label !== '') {
                $byLabel[AbstractCodebookImportService::normalize($label)] = $line['line_no'];
            }
            if (isset($line['pair_no'])) {
                $pairLines[$line['pair_no']][] = $line['line_no'];
            }
        }
        $paired = $pairLines !== [];
        $pairOfLine = [];
        foreach ($pairLines as $pairNo => $lineNos) {
            foreach ($lineNos as $lineNo) {
                $pairOfLine[$lineNo] = $pairNo;
            }
        }
        $pairKeys = $this->pairKeys($templateLines);

        /** @var array<int,float> $amounts line_no => amount */
        $amounts = [];
        /** @var array<int,array{line_text?:string,cost_center?:string}> $overrides line_no => pole z CSV */
        $overrides = [];
        $usedPairs = [];
        $added = [];
        $unmatched = [];
        foreach ($rows as $row) {
            $pairRow = $paired ? $this->pairRow($row) : null;
            if ($pairRow !== null) {
                $pairNo = null;
                foreach ($pairKeys as $candidate => $key) {
                    if ($key === $pairRow['key'] && !isset($usedPairs[$candidate])) {
                        $pairNo = $candidate;
                        break;
                    }
                }
                if ($pairNo !== null) {
                    $usedPairs[$pairNo] = true;
                    foreach ($pairLines[$pairNo] as $lineNo) {
                        $amounts[$lineNo] = $pairRow['amount'];
                    }
                    $this->applyPairFields($templateLines, $pairLines[$pairNo], $pairRow, $overrides);
                    continue;
                }
                if (strtoupper($pairRow['debit']) !== strtoupper($pairRow['credit'])) {
                    $added[] = $pairRow;
                    continue;
                }
                $unmatched[] = [
                    'value' => $pairRow['debit'] . '/' . $pairRow['credit'],
                    'amount' => $pairRow['amount'],
                    'debit_account_code' => $pairRow['debit'],
                    'credit_account_code' => $pairRow['credit'],
                ];
                continue;
            }

            if (count($row) < 2) {
                continue;
            }
            $key = trim((string) $row[0]);
            if ($key === '') {
                continue;
            }
            $amount = AbstractCodebookImportService::parseDecimal((string) $row[1]);
            if ($amount === null) {
                continue; // hlavička nebo neparsovatelný řádek — tiše přeskočit
            }

            $lineNo = $byCode[strtoupper($key)] ?? $byLabel[AbstractCodebookImportService::normalize($key)] ?? null;
            if ($lineNo === null) {
                $unmatched[] = ['value' => $key, 'amount' => $amount];
                continue;
            }
            if (isset($pairOfLine[$lineNo])) {
                foreach ($pairLines[$pairOfLine[$lineNo]] as $partnerLineNo) {
                    $amounts[$partnerLineNo] = $amount;
                }
                continue;
            }
            $amounts[$lineNo] = $amount;
        }

        $lines = [];
        $maxLineNo = 0;
        foreach ($templateLines as $line) {
            $out = [
                'line_no'      => $line['line_no'],
                'label'        => $line['label'],
                'account_code' => $line['account_code'],
                'side'         => $line['side'],
                'cost_center'  => $overrides[$line['line_no']]['cost_center'] ?? $line['cost_center'],
                'amount'       => $amounts[$line['line_no']] ?? $line['default_amount'],
            ];
            if (isset($line['pair_no'])) {
                $out['pair_no'] = $line['pair_no'];
                $text = $overrides[$line['line_no']]['line_text'] ?? $line['line_text'] ?? null;
                if ($text !== null) {
                    $out['line_text'] = $text;
                }
            }
            $lines[] = $out;
            $maxLineNo = max($maxLineNo, $line['line_no']);
        }

        $nextPairNo = $pairLines === [] ? 1 : max(array_keys($pairLines)) + 1;
        foreach ($added as $pairRow) {
            $sides = ManualJournalEntryService::pairAnalyticsSides($pairRow['debit'], $pairRow['credit']);
            foreach (['debit', 'credit'] as $side) {
                $out = [
                    'line_no'      => ++$maxLineNo,
                    'label'        => null,
                    'account_code' => $pairRow[$side],
                    'side'         => $side,
                    'cost_center'  => $sides[$side] ? $pairRow['cost_center'] : null,
                    'amount'       => $pairRow['amount'],
                    'pair_no'      => $nextPairNo,
                ];
                if ($pairRow['line_text'] !== null) {
                    $out['line_text'] = $pairRow['line_text'];
                }
                $lines[] = $out;
                $amounts[$maxLineNo] = $pairRow['amount'];
            }
            $nextPairNo++;
        }

        return ['lines' => $lines, 'unmatched' => $unmatched, 'matched_count' => count($amounts)];
    }

    /**
     * Klíč „MD/Dal" každé dvojice šablony (velká písmena), v pořadí čísel dvojic.
     *
     * @param list<array<string,mixed>> $templateLines
     * @return array<int,string> pair_no => klíč
     */
    private function pairKeys(array $templateLines): array
    {
        $sides = [];
        foreach ($templateLines as $line) {
            if (isset($line['pair_no'])) {
                $sides[$line['pair_no']][$line['side']] = strtoupper(trim((string) $line['account_code']));
            }
        }
        ksort($sides);
        $keys = [];
        foreach ($sides as $pairNo => $codes) {
            if (isset($codes['debit'], $codes['credit'])) {
                $keys[$pairNo] = $codes['debit'] . '/' . $codes['credit'];
            }
        }
        return $keys;
    }

    /**
     * Řádek dvojice `MD;Dal;Částka[;Text;Středisko]`: aspoň tři sloupce, druhý má tvar
     * kódu účtu a třetí je částka. Dvousloupcový tvar i hlavička vrací null.
     *
     * @param list<string> $row
     * @return array{debit:string,credit:string,key:string,amount:float,line_text:?string,cost_center:?string}|null
     */
    private function pairRow(array $row): ?array
    {
        if (count($row) < 3) {
            return null;
        }
        $debit = trim($row[0]);
        $credit = trim($row[1]);
        if ($debit === '' || !preg_match(self::ACCOUNT_CODE, $credit)) {
            return null;
        }
        $amount = AbstractCodebookImportService::parseDecimal($row[2]);
        if ($amount === null) {
            return null;
        }
        $text = trim($row[3] ?? '');
        $costCenter = trim($row[4] ?? '');
        return [
            'debit' => $debit,
            'credit' => $credit,
            'key' => strtoupper($debit) . '/' . strtoupper($credit),
            'amount' => $amount,
            'line_text' => $text === '' ? null : mb_substr($text, 0, 255),
            'cost_center' => $costCenter === '' ? null : $costCenter,
        ];
    }

    /**
     * Text a středisko z CSV na napárovanou dvojici šablony. Text jde na oba řádky,
     * středisko na řádek podle {@see ManualJournalEntryService::pairAnalyticsSides()}.
     *
     * @param list<array<string,mixed>> $templateLines
     * @param list<int> $lineNos
     * @param array{debit:string,credit:string,line_text:?string,cost_center:?string} $pairRow
     * @param array<int,array{line_text?:string,cost_center?:string}> $overrides
     */
    private function applyPairFields(array $templateLines, array $lineNos, array $pairRow, array &$overrides): void
    {
        $sides = ManualJournalEntryService::pairAnalyticsSides($pairRow['debit'], $pairRow['credit']);
        foreach ($templateLines as $line) {
            if (!in_array($line['line_no'], $lineNos, true)) {
                continue;
            }
            if ($pairRow['line_text'] !== null) {
                $overrides[$line['line_no']]['line_text'] = $pairRow['line_text'];
            }
            if ($pairRow['cost_center'] !== null && $sides[$line['side']]) {
                $overrides[$line['line_no']]['cost_center'] = $pairRow['cost_center'];
            }
        }
    }

    /**
     * Zjednodušená verze {@see AbstractCodebookImportService::readCsv} (bez XLSX větve,
     * bez CodebookImportException) — jen BOM strip + detekce oddělovače + fgetcsv.
     *
     * @return list<list<string>>
     */
    private function readCsv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(static fn ($v) => (string) ($v ?? ''), $row);
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }
        fclose($stream);
        return $rows;
    }
}
