<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX export účetních sestav (Epic F2 §4.2).
 *
 * Vstupem každé metody je výstup příslušné report service (spec §2.4–2.7),
 * výstupem {bytes, filename, mime}. Vzor stylování dle LogbookSummaryExportService
 * (titulek bold 14, hlavička bold + fill EEEEEE, thin borders, čísla vpravo,
 * autosize sloupců).
 */
final class ReportXlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const THOUSANDS_NOTE = 'Hodnoty jsou v celých tisících Kč, zaokrouhleny po řádcích (§4 odst. 3 vyhl. č. 500/2002 Sb.);'
        . ' součtové řádky se počítají z hodnot v Kč — proti součtu zaokrouhlených položek může vzniknout rozdíl ±1 tis. Kč.';

    /**
     * @param array<string,mixed> $data výstup GeneralLedgerService::build()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function generalLedger(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $fy = !empty($data['all_periods']) ? 'všechna období' : (string) ($data['period']['fiscal_year'] ?? '');
        $sheet->setTitle('Hlavní kniha');
        $sheet->setCellValue('A1', 'Hlavní kniha — fiskální rok ' . $fy);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sub = 'Období: ' . $this->czDate((string) ($data['from'] ?? '')) . ' – ' . $this->czDate((string) ($data['to'] ?? ''));
        if (!empty($data['analytics'])) {
            $sub .= ' (rozpad po analytikách)';
        }
        $sheet->setCellValue('A2', $sub);
        if ((int) ($data['draft_count'] ?? 0) > 0) {
            $sheet->setCellValue('A3', 'V rozsahu je ' . (int) $data['draft_count'] . ' nezaúčtovaných konceptů — nejsou zahrnuty.');
            $sheet->getStyle('A3')->getFont()->setItalic(true);
        }

        $months = array_values($data['months'] ?? []);
        $headers = ['Účet', 'Název', 'PS MD', 'PS D'];
        foreach ($months as $m) {
            $headers[] = $m . ' MD';
            $headers[] = $m . ' D';
        }
        array_push($headers, 'Obrat MD', 'Obrat D', 'KS MD', 'KS D');
        $cols = count($headers);
        $head = 5;
        $this->headerRow($sheet, $head, $headers);

        $monthTotals = [];
        $r = $head + 1;
        foreach ($data['accounts'] ?? [] as $acc) {
            $sheet->setCellValueExplicit([1, $r], (string) $acc['account_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $acc['name'], DataType::TYPE_STRING);
            $sheet->setCellValue([3, $r], (float) $acc['opening_md']);
            $sheet->setCellValue([4, $r], (float) $acc['opening_d']);
            $c = 5;
            foreach ($months as $m) {
                $md = (float) ($acc['months'][$m]['md'] ?? 0);
                $d = (float) ($acc['months'][$m]['d'] ?? 0);
                $sheet->setCellValue([$c, $r], $md);
                $sheet->setCellValue([$c + 1, $r], $d);
                $monthTotals[$m]['md'] = ($monthTotals[$m]['md'] ?? 0.0) + $md;
                $monthTotals[$m]['d'] = ($monthTotals[$m]['d'] ?? 0.0) + $d;
                $c += 2;
            }
            $sheet->setCellValue([$c, $r], (float) $acc['turnover_md']);
            $sheet->setCellValue([$c + 1, $r], (float) $acc['turnover_d']);
            $sheet->setCellValue([$c + 2, $r], (float) $acc['closing_md']);
            $sheet->setCellValue([$c + 3, $r], (float) $acc['closing_d']);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'SOUČTY');
        $sheet->setCellValue([3, $r], (float) ($t['opening_md'] ?? 0));
        $sheet->setCellValue([4, $r], (float) ($t['opening_d'] ?? 0));
        $c = 5;
        foreach ($months as $m) {
            $sheet->setCellValue([$c, $r], (float) ($monthTotals[$m]['md'] ?? 0));
            $sheet->setCellValue([$c + 1, $r], (float) ($monthTotals[$m]['d'] ?? 0));
            $c += 2;
        }
        $sheet->setCellValue([$c, $r], (float) ($t['turnover_md'] ?? 0));
        $sheet->setCellValue([$c + 1, $r], (float) ($t['turnover_d'] ?? 0));
        $sheet->setCellValue([$c + 2, $r], (float) ($t['closing_md'] ?? 0));
        $sheet->setCellValue([$c + 3, $r], (float) ($t['closing_d'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 3);

        return $this->out($ss, 'hlavni-kniha-' . (!empty($data['all_periods']) ? 'vse' : $fy) . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup TrialBalanceService::build()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function trialBalance(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $fy = (string) ($data['period']['fiscal_year'] ?? '');
        $sheet->setTitle('Obratová předvaha');
        $sheet->setCellValue('A1', 'Obratová předvaha — fiskální rok ' . $fy);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Období: ' . $this->czDate((string) ($data['from'] ?? '')) . ' – ' . $this->czDate((string) ($data['to'] ?? '')));

        $headers = ['Účet', 'Název', 'PS MD', 'PS D', 'Obrat MD', 'Obrat D', 'KS MD', 'KS D'];
        $cols = count($headers);
        $head = 4;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['account_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValue([3, $r], (float) $row['ps_md']);
            $sheet->setCellValue([4, $r], (float) $row['ps_d']);
            $sheet->setCellValue([5, $r], (float) $row['turnover_md']);
            $sheet->setCellValue([6, $r], (float) $row['turnover_d']);
            $sheet->setCellValue([7, $r], (float) $row['ks_md']);
            $sheet->setCellValue([8, $r], (float) $row['ks_d']);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'SOUČTY');
        $sheet->setCellValue([3, $r], (float) ($t['ps_md'] ?? 0));
        $sheet->setCellValue([4, $r], (float) ($t['ps_d'] ?? 0));
        $sheet->setCellValue([5, $r], (float) ($t['turnover_md'] ?? 0));
        $sheet->setCellValue([6, $r], (float) ($t['turnover_d'] ?? 0));
        $sheet->setCellValue([7, $r], (float) ($t['ks_md'] ?? 0));
        $sheet->setCellValue([8, $r], (float) ($t['ks_d'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 3);

        $checks = $data['checks'] ?? [];
        $cr = $r + 2;
        $sheet->setCellValue("A{$cr}", 'Kontroly');
        $sheet->getStyle("A{$cr}")->getFont()->setBold(true);
        $cr++;
        $sheet->setCellValue("A{$cr}", 'Obraty vyrovnány (Σ obrat MD = Σ obrat D): ' . $this->yesNo((bool) ($checks['turnover_balanced'] ?? false)));
        $cr++;
        $sheet->setCellValue("A{$cr}", 'Shoda s obratem deníku (deník MD ' . $this->czMoney((float) ($checks['journal_turnover_md'] ?? 0))
            . ' / D ' . $this->czMoney((float) ($checks['journal_turnover_d'] ?? 0)) . '): ' . $this->yesNo((bool) ($checks['matches_journal'] ?? false)));
        $cr++;
        $sheet->setCellValue("A{$cr}", 'Bilanční kontinuita počátečních stavů (Σ PS MD = Σ PS D): ' . $this->yesNo((bool) ($checks['opening_balanced'] ?? false)));
        if ((int) ($data['draft_count'] ?? 0) > 0) {
            $cr++;
            $sheet->setCellValue("A{$cr}", 'V rozsahu je ' . (int) $data['draft_count'] . ' nezaúčtovaných konceptů — nejsou zahrnuty.');
            $sheet->getStyle("A{$cr}")->getFont()->setItalic(true);
        }

        return $this->out($ss, 'obratova-predvaha-' . $fy . '.xlsx');
    }

    /**
     * Inventarizace rozvahových účtů (§29–30 ZoÚ, T2) — soupis účtů tříd 0–4 s KZ MD/D,
     * návrhem způsobu doložení a prázdnými sloupci pro ruční doplnění skutečného stavu
     * a rozdílu po provedené inventuře.
     *
     * @param array<string,mixed> $data výstup BalanceInventoryService::build()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function balanceInventory(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $asOf = (string) ($data['as_of'] ?? '');
        $fy = (string) ($data['period']['fiscal_year'] ?? '');
        $title = (string) ($data['report_title'] ?? 'Inventarizace rozvahových účtů');
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->setCellValue('A1', $title . ' k rozvahovému dni ' . $this->czDate($asOf));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);
        $sheet->setCellValue('A5', 'Datum provedení inventarizace: _________________ · Inventarizační komise: _________________');
        $sheet->getStyle('A5')->getFont()->setItalic(true);

        $headers = ['Účet', 'Název', 'KZ MD', 'KZ D', 'Skutečný stav', 'Rozdíl', 'Způsob doložení'];
        $cols = count($headers);
        $head = 7;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['account_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValue([3, $r], (float) $row['ks_md']);
            $sheet->setCellValue([4, $r], (float) $row['ks_d']);
            $sheet->setCellValueExplicit([5, $r], '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $r], '', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([7, $r], (string) $row['documentation_hint'], DataType::TYPE_STRING);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($data['count'] ?? 0) . ' účtů)');
        $sheet->setCellValue([3, $r], (float) ($t['ks_md'] ?? 0));
        $sheet->setCellValue([4, $r], (float) ($t['ks_d'] ?? 0));
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 3);

        if ((int) ($data['draft_count'] ?? 0) > 0) {
            $nr = $r + 2;
            $sheet->setCellValue("A{$nr}", 'V období je ' . (int) $data['draft_count'] . ' nezaúčtovaných konceptů — nejsou zahrnuty do zůstatků.');
            $sheet->getStyle("A{$nr}")->getFont()->setItalic(true);
        }

        return $this->out($ss, 'inventarizace-uctu-' . $fy . '.xlsx');
    }

    /**
     * Inventarizace dlouhodobého majetku k rozvahovému dni (§29–30 ZoÚ, uzávěrkový
     * balíček #33) — soupis karet majetku s pořizovací cenou, oprávkami a ZC.
     *
     * @param array<string,mixed> $data výstup AssetInventoryReportService::build()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function assetInventory(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $asOf = (string) ($data['as_of'] ?? '');
        $fy = (string) ($data['period']['fiscal_year'] ?? '');
        $sheet->setTitle('Dlouhodobý majetek');
        $sheet->setCellValue('A1', 'Inventarizace dlouhodobého majetku k rozvahovému dni ' . $this->czDate($asOf));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $headers = ['Inv. číslo', 'Název', 'Pořízeno', 'Zařazeno', 'Pořizovací cena', 'Oprávky', 'Zůstatková cena', 'Stav'];
        $cols = count($headers);
        $head = 6;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) ($row['inventory_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], $this->czDate((string) $row['acquisition_date']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], $this->czDate((string) ($row['put_into_use_date'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['input_price']);
            $sheet->setCellValue([6, $r], (float) $row['acc_amount']);
            $sheet->setCellValue([7, $r], (float) $row['net_book_value']);
            $sheet->setCellValueExplicit([8, $r], (string) $row['status'], DataType::TYPE_STRING);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($data['count'] ?? 0) . ' karet)');
        $sheet->setCellValue([5, $r], (float) ($t['input_price'] ?? 0));
        $sheet->setCellValue([6, $r], (float) ($t['acc_amount'] ?? 0));
        $sheet->setCellValue([7, $r], (float) ($t['net_book_value'] ?? 0));
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 5);

        return $this->out($ss, 'inventarizace-majetku-' . $fy . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup AccountStatementService::build()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function accountStatement(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $acc = $data['account'] ?? [];
        $code = (string) ($acc['code'] ?? '');
        $from = (string) ($data['from'] ?? '');
        $to = (string) ($data['to'] ?? '');
        $sheet->setTitle('Opis účtu ' . $code);
        $sheet->setCellValue('A1', 'Opis účtu ' . $code . ' — ' . (string) ($acc['name'] ?? ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Období: ' . $this->czDate($from) . ' – ' . $this->czDate($to));
        $sheet->setCellValue('A3', 'Počáteční zůstatek: ' . $this->czMoney((float) ($data['opening_balance'] ?? 0)));

        $headers = ['Datum', 'Doklad', 'Popis', 'MD', 'D', 'Zůstatek'];
        $cols = count($headers);
        $head = 5;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $item) {
            $sheet->setCellValue([1, $r], $this->czDate((string) $item['entry_date']));
            $sheet->setCellValueExplicit([2, $r], (string) $item['document_no'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $item['description'], DataType::TYPE_STRING);
            if (($item['side'] ?? '') === 'debit') {
                $sheet->setCellValue([4, $r], (float) $item['amount']);
            } else {
                $sheet->setCellValue([5, $r], (float) $item['amount']);
            }
            $sheet->setCellValue([6, $r], (float) $item['balance']);
            $r++;
        }

        $sheet->setCellValue([1, $r], 'Obraty / konečný zůstatek');
        $sheet->setCellValue([4, $r], (float) ($data['turnover_md'] ?? 0));
        $sheet->setCellValue([5, $r], (float) ($data['turnover_d'] ?? 0));
        $sheet->setCellValue([6, $r], (float) ($data['closing_balance'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 4);

        return $this->out($ss, 'opis-uctu-' . $code . '-' . $from . '-' . $to . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup FinancialStatementService::balanceSheet()
     * @param 'czk'|'thousands' $unit pracovní Kč (default) / celé tisíce Kč (§4/3 vyhl. 500/2002, F4 R17)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function balanceSheet(array $data, string $unit = 'czk'): array
    {
        $thousands = $unit === 'thousands';
        if ($thousands) {
            foreach ($data['assets'] ?? [] as $i => $row) {
                foreach (['gross', 'correction', 'net', 'prev_net'] as $k) {
                    $data['assets'][$i][$k] = $this->toThousands($row[$k] ?? 0);
                }
            }
            foreach ($data['liabilities'] ?? [] as $i => $row) {
                foreach (['amount', 'prev_amount'] as $k) {
                    $data['liabilities'][$i][$k] = $this->toThousands($row[$k] ?? 0);
                }
            }
            foreach (['assets_net', 'liabilities_total'] as $k) {
                if (isset($data['checks'][$k])) {
                    $data['checks'][$k] = $this->toThousands($data['checks'][$k]);
                }
            }
        }

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $asOf = (string) ($data['as_of'] ?? '');
        $sheet->setTitle('Rozvaha');
        $sheet->setCellValue('A1', 'ROZVAHA ' . $this->scopeLabel((string) ($data['scope'] ?? 'full')) . ' k ' . $this->czDate($asOf)
            . ($thousands ? ' (v celých tisících Kč)' : ' (v Kč)'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $head = 6;
        $headers = ['Označení', 'Položka', 'Brutto', 'Korekce', 'Netto', 'Minulé obd.'];
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['assets'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['display_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], str_repeat('    ', (int) $row['level']) . (string) $row['label']);
            $sheet->setCellValue([3, $r], (float) $row['gross']);
            $sheet->setCellValue([4, $r], (float) $row['correction']);
            $sheet->setCellValue([5, $r], (float) $row['net']);
            $sheet->setCellValue([6, $r], (float) $row['prev_net']);
            if (($row['row_type'] ?? 'detail') !== 'detail') {
                $this->boldRow($sheet, $r, 6);
            }
            $r++;
        }
        $this->finishTable($sheet, $head, $r - 1, 6, 3);

        $head2 = $r + 1;
        $headers2 = ['Označení', 'Položka', 'Běžné obd.', 'Minulé obd.'];
        $this->headerRow($sheet, $head2, $headers2);

        $r = $head2 + 1;
        foreach ($data['liabilities'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['display_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], str_repeat('    ', (int) $row['level']) . (string) $row['label']);
            $sheet->setCellValue([3, $r], (float) $row['amount']);
            $sheet->setCellValue([4, $r], (float) $row['prev_amount']);
            if (($row['row_type'] ?? 'detail') !== 'detail') {
                $this->boldRow($sheet, $r, 4);
            }
            $r++;
        }
        $this->finishTable($sheet, $head2, $r - 1, 4, 3);

        $checks = $data['checks'] ?? [];
        $cr = $r + 1;
        $sheet->setCellValue("A{$cr}", 'Kontrola: AKTIVA netto ' . $this->czAmount((float) ($checks['assets_net'] ?? 0), $thousands)
            . ' = PASIVA ' . $this->czAmount((float) ($checks['liabilities_total'] ?? 0), $thousands) . ': ' . $this->yesNo((bool) ($checks['balanced'] ?? false)));
        $sheet->getStyle("A{$cr}")->getFont()->setBold(true);
        if ($thousands) {
            $cr++;
            $sheet->setCellValue("A{$cr}", self::THOUSANDS_NOTE);
            $sheet->getStyle("A{$cr}")->getFont()->setItalic(true);
        }

        return $this->out($ss, 'rozvaha-' . $asOf . '.xlsx');
    }

    /**
     * Přehled o peněžních tocích (§ 18/2 ZoÚ) — souhrn + rozpis po činnostech.
     *
     * Volbu jednotky `thousands` tahle sestava nemá: peněžní toky se sestavují v Kč
     * a zaokrouhlení na tisíce by rozbilo právě tu rovnici, kvůli které výkaz existuje
     * (počáteční stav + čistý tok = konečný stav).
     *
     * @param array<string,mixed> $data výstup CashFlowStatementService::build() + `entity`
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function cashFlow(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $from = (string) ($data['period']['starts_on'] ?? '');
        $to = (string) ($data['period']['ends_on'] ?? '');
        $sheet->setTitle('Peněžní toky');
        $sheet->setCellValue('A1', 'PŘEHLED O PENĚŽNÍCH TOCÍCH za ' . $this->czDate($from) . ' – ' . $this->czDate($to) . ' (v Kč)');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $head = 6;
        $this->headerRow($sheet, $head, ['Položka', 'Částka']);
        $summary = [
            ['P. Počáteční stav peněžních prostředků', (float) ($data['opening'] ?? 0), true],
            ['Z. Čistý peněžní tok z provozní činnosti', (float) ($data['operating'] ?? 0), false],
            ['A. Čistý peněžní tok z investiční činnosti', (float) ($data['investing'] ?? 0), false],
            ['B. Čistý peněžní tok z finanční činnosti', (float) ($data['financing'] ?? 0), false],
            ['N. Nezařazeno', (float) ($data['unclassified'] ?? 0), false],
            ['F. Čisté zvýšení / snížení peněžních prostředků', (float) ($data['net_change'] ?? 0), true],
            ['R. Konečný stav peněžních prostředků', (float) ($data['closing'] ?? 0), true],
        ];
        $r = $head + 1;
        foreach ($summary as [$label, $value, $strong]) {
            $sheet->setCellValue([1, $r], $label);
            $sheet->setCellValue([2, $r], $value);
            if ($strong) {
                $this->boldRow($sheet, $r, 2);
            }
            $r++;
        }
        $this->finishTable($sheet, $head, $r - 1, 2, 2);

        $groups = [
            'operating'    => 'Provozní činnost',
            'investing'    => 'Investiční činnost',
            'financing'    => 'Finanční činnost',
            'unclassified' => 'Nezařazeno',
        ];
        foreach ($groups as $key => $label) {
            $rows = $data['breakdown'][$key] ?? [];
            if (!is_array($rows) || $rows === []) {
                continue;
            }
            $r++;
            $sheet->setCellValue([1, $r], $label);
            $this->boldRow($sheet, $r, 3);
            $head2 = ++$r;
            $this->headerRow($sheet, $head2, ['Účet', 'Položka', 'Částka']);
            $r = $head2 + 1;
            foreach ($rows as $row) {
                $sheet->setCellValueExplicit([1, $r], (string) $row['account_code'], DataType::TYPE_STRING);
                $sheet->setCellValue([2, $r], (string) $row['name']);
                $sheet->setCellValue([3, $r], (float) $row['amount']);
                $r++;
            }
            $this->finishTable($sheet, $head2, $r - 1, 3, 3);
        }

        $r += 2;
        $sheet->setCellValue("A{$r}", 'Kontrola: počáteční stav + čistý tok = konečný stav: '
            . $this->yesNo((bool) ($data['reconciles'] ?? false)));
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);

        return $this->out($ss, 'penezni-toky-' . $from . '.xlsx');
    }

    /**
     * Přehled o změnách vlastního kapitálu (§ 18/2 ZoÚ, § 44 vyhl. 500/2002 Sb.).
     *
     * @param array<string,mixed> $data výstup EquityChangesStatementService::build() + `entity`
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function equityChanges(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $from = (string) ($data['period']['starts_on'] ?? '');
        $to = (string) ($data['period']['ends_on'] ?? '');
        $sheet->setTitle('Změny vlastního kapitálu');
        $sheet->setCellValue('A1', 'PŘEHLED O ZMĚNÁCH VLASTNÍHO KAPITÁLU za ' . $this->czDate($from) . ' – ' . $this->czDate($to) . ' (v Kč)');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $head = 6;
        $this->headerRow($sheet, $head, ['Účet', 'Složka', 'Počáteční stav', 'Zvýšení', 'Snížení', 'Konečný stav']);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['account_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], (string) $row['name']);
            $sheet->setCellValue([3, $r], (float) $row['opening']);
            $sheet->setCellValue([4, $r], (float) $row['increase']);
            $sheet->setCellValue([5, $r], (float) $row['decrease']);
            $sheet->setCellValue([6, $r], (float) $row['closing']);
            $r++;
        }

        $totals = $data['totals'] ?? [];
        $sheet->setCellValue([2, $r], 'Celkem');
        $sheet->setCellValue([3, $r], (float) ($totals['opening'] ?? 0));
        $sheet->setCellValue([4, $r], (float) ($totals['increase'] ?? 0));
        $sheet->setCellValue([5, $r], (float) ($totals['decrease'] ?? 0));
        $sheet->setCellValue([6, $r], (float) ($totals['closing'] ?? 0));
        $this->boldRow($sheet, $r, 6);
        $this->finishTable($sheet, $head, $r, 6, 3);

        $cr = $r + 2;
        $sheet->setCellValue("A{$cr}", 'Kontrola po složkách (PS + zvýšení − snížení = KS): '
            . $this->yesNo((bool) ($data['reconciles'] ?? false)));
        $sheet->getStyle("A{$cr}")->getFont()->setBold(true);

        return $this->out($ss, 'zmeny-vlastniho-kapitalu-' . $from . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup FinancialStatementService::incomeStatement()
     * @param 'czk'|'thousands' $unit pracovní Kč (default) / celé tisíce Kč (§4/3 vyhl. 500/2002, F4 R17)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function incomeStatement(array $data, string $unit = 'czk'): array
    {
        $thousands = $unit === 'thousands';
        if ($thousands) {
            foreach ($data['rows'] ?? [] as $i => $row) {
                foreach (['amount', 'prev_amount'] as $k) {
                    $data['rows'][$i][$k] = $this->toThousands($row[$k] ?? 0);
                }
            }
            foreach (['profit_current', 'net_turnover'] as $k) {
                if (isset($data['checks'][$k])) {
                    $data['checks'][$k] = $this->toThousands($data['checks'][$k]);
                }
            }
        }

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $asOf = (string) ($data['as_of'] ?? '');
        // Metoda slouží OBĚMA členěním výsledovky — účelové má jiné řádky i jinou přílohu
        // vyhlášky, takže se musí poznat i z hlavičky sešitu.
        $isPurpose = ($data['statement_type'] ?? '') === 'income_statement_purpose';
        $sheet->setTitle($isPurpose ? 'VZZ účelové' : 'Výkaz zisku a ztráty');
        $sheet->setCellValue('A1', 'VÝKAZ ZISKU A ZTRÁTY ' . $this->scopeLabel((string) ($data['scope'] ?? 'full'))
            . ($isPurpose ? ', účelové členění' : ', druhové členění')
            . ' ke dni ' . $this->czDate($asOf)
            . ($thousands ? ' (v celých tisících Kč)' : ' (v Kč)'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $head = 6;
        $headers = ['Označení', 'Položka', 'Běžné obd.', 'Minulé obd.'];
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['display_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], str_repeat('    ', max(0, (int) $row['level'] - 1)) . (string) $row['label']);
            $sheet->setCellValue([3, $r], (float) $row['amount']);
            $sheet->setCellValue([4, $r], (float) $row['prev_amount']);
            if (($row['row_type'] ?? 'detail') !== 'detail') {
                $this->boldRow($sheet, $r, 4);
            }
            $r++;
        }
        $this->finishTable($sheet, $head, $r - 1, 4, 3);

        $checks = $data['checks'] ?? [];
        $cr = $r + 1;
        $sheet->setCellValue("A{$cr}", 'Výsledek hospodaření za účetní období: ' . $this->czAmount((float) ($checks['profit_current'] ?? 0), $thousands));
        $cr++;
        $sheet->setCellValue("A{$cr}", 'Čistý obrat za účetní období: ' . $this->czAmount((float) ($checks['net_turnover'] ?? 0), $thousands));
        if ($thousands) {
            $cr++;
            $sheet->setCellValue("A{$cr}", self::THOUSANDS_NOTE);
            $sheet->getStyle("A{$cr}")->getFont()->setItalic(true);
        }

        return $this->out($ss, 'vysledovka-' . $asOf . '.xlsx');
    }

    /**
     * Peněžní deník daňové evidence (Epic DE A3) — výstup CashJournalService::build().
     * List řádků (datum/doklad/protistrana/popis/příjem/výdaj/zůstatek/klasifikace),
     * pod nimi součtové kbelíky a prominentní blok blokujících varování (R10).
     *
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function cashJournal(array $data): array
    {
        $labels = [
            'income_taxable' => 'Daňový příjem', 'income_exempt' => 'Osvobozený příjem',
            'income_nontax' => 'Nedaňový příjem', 'expense_taxable' => 'Daňový výdaj',
            'expense_nontax' => 'Nedaňový výdaj', 'transfer' => 'Převod',
            'private' => 'Soukromé', 'nezarazeno' => 'NEZAŘAZENO',
        ];
        $year = (string) ($data['year'] ?? '');

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Peněžní deník');
        $sheet->setCellValue('A1', 'Peněžní deník — rok ' . $year);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Období: ' . $this->czDate((string) ($data['from'] ?? '')) . ' – ' . $this->czDate((string) ($data['to'] ?? '')));

        $warnings = $data['warnings'] ?? [];
        $head = 4;
        if (!empty($warnings)) {
            $sheet->setCellValue('A4', '⚠ Nezařazené pohyby (' . count($warnings) . ') — mimo daňový základ, riziko podhodnocení příjmu:');
            $sheet->getStyle('A4')->getFont()->setBold(true)->getColor()->setRGB('B91C1C');
            $wr = 5;
            foreach ($warnings as $w) {
                $line = ($w['blocking'] ?? false ? '● ' : '○ ')
                    . $this->czDate((string) ($w['date'] ?? ''))
                    . (isset($w['count']) ? ' (' . (int) $w['count'] . '×)' : '')
                    . ' ' . (string) ($w['message'] ?? '');
                $sheet->setCellValueExplicit("A{$wr}", $line, DataType::TYPE_STRING);
                $sheet->getStyle("A{$wr}")->getFont()->getColor()->setRGB('7F1D1D');
                $wr++;
            }
            $head = $wr + 1;
        }

        $headers = ['Datum', 'Doklad', 'Protistrana', 'Popis', 'Příjem', 'Výdaj', 'Zůstatek', 'Klasifikace'];
        $cols = count($headers);
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['rows'] ?? [] as $row) {
            $sheet->setCellValue([1, $r], $this->czDate((string) ($row['date'] ?? '')));
            $sheet->setCellValueExplicit([2, $r], (string) ($row['doc_no'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) ($row['partner'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) ($row['description'] ?? ''), DataType::TYPE_STRING);
            if (($row['income'] ?? null) !== null) {
                $sheet->setCellValue([5, $r], (float) $row['income']);
            }
            if (($row['expense'] ?? null) !== null) {
                $sheet->setCellValue([6, $r], (float) $row['expense']);
            }
            $sheet->setCellValue([7, $r], (float) ($row['running_balance'] ?? 0));
            $sheet->setCellValueExplicit([8, $r], $labels[$row['bucket'] ?? ''] ?? (string) ($row['bucket'] ?? ''), DataType::TYPE_STRING);
            $r++;
        }
        $this->finishTable($sheet, $head, $r - 1, $cols, 5);

        $t = $data['totals'] ?? [];
        $tr = $r + 1;
        $sheet->setCellValue("A{$tr}", 'Součty za období');
        $sheet->getStyle("A{$tr}")->getFont()->setBold(true);
        foreach ([
            'Počáteční zůstatek' => (float) ($data['opening_balance'] ?? 0),
            'Daňové příjmy'      => (float) ($t['prijem_danovy'] ?? 0),
            'Osvobozené příjmy'  => (float) ($t['prijem_osvobozeny'] ?? 0),
            'Nedaňové příjmy'    => (float) ($t['prijem_nedanovy'] ?? 0),
            'Daňové výdaje'      => (float) ($t['vydaj_danovy'] ?? 0),
            'Nedaňové výdaje'    => (float) ($t['vydaj_nedanovy'] ?? 0),
            'Převody'            => (float) ($t['prevody'] ?? 0),
            'Soukromé'           => (float) ($t['private'] ?? 0),
            'Nezařazeno'         => (float) ($t['nezarazeno'] ?? 0),
            'Konečný zůstatek'   => (float) ($data['closing_balance'] ?? 0),
        ] as $label => $val) {
            $tr++;
            $sheet->setCellValue("A{$tr}", $label);
            $sheet->setCellValue("B{$tr}", $val);
            $sheet->getStyle("B{$tr}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $tr++;
        $sheet->setCellValue("A{$tr}", 'Daňový základ (příjmy − výdaje)');
        $sheet->setCellValue("B{$tr}", (float) ($t['net'] ?? 0));
        $sheet->getStyle("A{$tr}:B{$tr}")->getFont()->setBold(true);
        $sheet->getStyle("B{$tr}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        return $this->out($ss, 'penezni-denik-' . $year . '.xlsx');
    }

    /**
     * Pohledávky a závazky (Epic DE A3) — výstup ReceivablesPayablesService::build().
     * Řádky per (měna, strana, kbelík); nativní částky bez CZK přepočtu (R13). KPI pod tabulkou.
     *
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function receivablesPayables(array $data): array
    {
        $bucketLabels = [
            'not_due' => 'Do splatnosti', '1-30' => '1–30 dní po splatnosti',
            '31-90' => '31–90 dní po splatnosti', '90+' => '90+ dní po splatnosti',
        ];

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Pohledávky a závazky');
        $sheet->setCellValue('A1', 'Pohledávky a závazky');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Aging podle splatnosti, nativně per měna (bez CZK přepočtu)');

        $head = 4;
        $headers = ['Strana', 'Měna', 'Kbelík', 'Počet', 'Částka'];
        $cols = count($headers);
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach (['receivables' => 'Pohledávky', 'payables' => 'Závazky'] as $side => $sideLabel) {
            foreach ($data[$side] ?? [] as $row) {
                $sheet->setCellValueExplicit([1, $r], $sideLabel, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([2, $r], (string) $row['currency'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([3, $r], $bucketLabels[$row['bucket']] ?? (string) $row['bucket'], DataType::TYPE_STRING);
                $sheet->setCellValue([4, $r], (int) $row['count']);
                $sheet->setCellValue([5, $r], (float) $row['total']);
                $r++;
            }
        }
        $this->finishTable($sheet, $head, max($head, $r - 1), $cols, 4);

        $kpis = $data['kpis'] ?? [];
        $kr = $r + 1;
        $sheet->setCellValue("A{$kr}", 'Ukazatele');
        $sheet->getStyle("A{$kr}")->getFont()->setBold(true);
        foreach ([
            'DSO — průměrná doba inkasa (dní)'          => (float) ($kpis['dso']['avg_days'] ?? 0),
            'DPO — průměrná doba úhrady závazků (dní)'   => (float) ($kpis['dpo']['avg_days'] ?? 0),
            'Platební morálka (% včas)'                  => (float) ($kpis['punctuality']['on_time_pct'] ?? 0),
        ] as $label => $val) {
            $kr++;
            $sheet->setCellValue("A{$kr}", $label);
            $sheet->setCellValue("B{$kr}", $val);
            $sheet->getStyle("B{$kr}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        return $this->out($ss, 'pohledavky-zavazky.xlsx');
    }

    /**
     * Saldokonto / inventarizační protokol (audit 2026-07, D6/1) — výstup
     * SaldoService::build(). Per účet: blok konfrontace (zůstatek HK vs. Σ otevřených
     * položek + rozdíl) a rozpad otevřených položek per partner.
     *
     * `$flat` (task #2, D6/2 — plochý seznam dokladů): jeden list se všemi
     * otevřenými položkami napříč účty/partnery jako řádky (bez seskupení),
     * seřazený dle splatnosti — pracovní export k saldokontu, ne zákonný
     * inventarizační protokol (ten zůstává jen v grouped podobě, viz `saldo()`
     * bez příznaku a `SaldoPdfRenderer`).
     *
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function saldo(array $data, bool $flat = false): array
    {
        return $flat ? $this->saldoFlat($data) : $this->saldoGrouped($data);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function saldoGrouped(array $data): array
    {
        $asOf = (string) ($data['as_of'] ?? '');
        $title = (string) ($data['report_title'] ?? 'Saldokonto');
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $sheet->setCellValue('A1', $title . ' — inventarizační protokol k ' . $this->czDate($asOf));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $entity = $data['entity'] ?? [];
        $sheet->setCellValueExplicit('A2', (string) ($entity['name'] ?? '') . ' — IČO: ' . (string) ($entity['ico'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue('A3', 'Sestaveno: ' . (string) ($entity['prepared_at'] ?? ''));
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true);

        $headers = ['Doklad', 'Vystaveno', 'Splatnost', 'Dní po spl.', 'Částka', 'Uhrazeno', 'Zbývá (Kč)'];
        $cols = count($headers);
        $r = 5;

        foreach ($data['accounts'] ?? [] as $acc) {
            $account = $acc['account'] ?? [];
            $sheet->setCellValue("A{$r}", 'Účet ' . (string) ($account['code'] ?? '') . ' — ' . (string) ($account['name'] ?? ''));
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11);
            $r++;

            $matches = (bool) ($acc['matches'] ?? false);
            $sheet->setCellValue("A{$r}", 'Zůstatek HK: ' . $this->czMoney((float) ($acc['gl_balance'] ?? 0))
                . '  |  Σ otevřených položek: ' . $this->czMoney((float) ($acc['open_items_total'] ?? 0))
                . '  |  Rozdíl: ' . ($matches ? '✓ ' : '✗ ') . $this->czMoney((float) ($acc['difference'] ?? 0)));
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->getColor()->setRGB($matches ? '166534' : 'B91C1C');
            $r++;

            $head = $r;
            $this->headerRow($sheet, $head, $headers);
            $r++;

            $first = $r;
            foreach ($acc['partners'] ?? [] as $p) {
                $sheet->setCellValueExplicit([1, $r], (string) ($p['partner_name'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValue([7, $r], (float) ($p['total_remaining'] ?? 0));
                $this->boldRow($sheet, $r, $cols);
                $r++;
                foreach ($p['items'] ?? [] as $it) {
                    $ccy = (string) ($it['currency_code'] ?? 'CZK');
                    $docNo = (string) ($it['doc_no'] ?? '');
                    if ($ccy !== 'CZK') {
                        $docNo .= ' (' . $this->czMoney((float) ($it['amount_foreign'] ?? 0)) . ' ' . $ccy . ')';
                    }
                    $sheet->setCellValueExplicit([1, $r], $docNo, DataType::TYPE_STRING);
                    $sheet->setCellValue([2, $r], $this->czDate((string) ($it['issue_date'] ?? '')));
                    $sheet->setCellValue([3, $r], $this->czDate((string) ($it['due_date'] ?? '')));
                    $sheet->setCellValue([4, $r], (int) ($it['days_overdue'] ?? 0) > 0 ? (int) $it['days_overdue'] : '');
                    $sheet->setCellValue([5, $r], (float) ($it['booked_czk'] ?? 0));
                    $sheet->setCellValue([6, $r], (float) ($it['paid_czk'] ?? 0));
                    $sheet->setCellValue([7, $r], (float) ($it['remaining_czk'] ?? 0));
                    $r++;
                }
            }
            if ($r > $first) {
                $this->finishTable($sheet, $head, $r - 1, $cols, 4);
            }
            $r += 1; // mezera mezi účty
        }

        return $this->out($ss, 'saldokonto-' . $asOf . '.xlsx');
    }

    /**
     * Plochý seznam otevřených položek napříč účty/partnery (task #2, D6/2) —
     * jeden řádek na doklad, bez seskupení, seřazeno dle splatnosti (stejné
     * výchozí řazení jako flat pohled ve Saldokonto.vue).
     *
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function saldoFlat(array $data): array
    {
        $asOf = (string) ($data['as_of'] ?? '');
        $title = (string) ($data['report_title'] ?? 'Saldokonto');
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle(mb_substr($title . ' — doklady', 0, 31));
        $sheet->setCellValue('A1', $title . ' — otevřené položky k ' . $this->czDate($asOf));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $entity = $data['entity'] ?? [];
        $sheet->setCellValueExplicit('A2', (string) ($entity['name'] ?? '') . ' — IČO: ' . (string) ($entity['ico'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue('A3', 'Sestaveno: ' . (string) ($entity['prepared_at'] ?? ''));
        $sheet->getStyle('A3')->getFont()->setSize(9)->setItalic(true);

        // Pohledávky a závazky jsou opačné strany salda — jeden seznam řazený dle
        // splatnosti je stavěl vedle sebe a odlišoval jen kódem účtu v prvním sloupci.
        // Každá strana proto dostane vlastní blok se součtem, shodně s UI.
        /** @var array{receivable:list<array<string,mixed>>, payable:list<array<string,mixed>>} $bySide */
        $bySide = ['receivable' => [], 'payable' => []];
        foreach ($data['accounts'] ?? [] as $acc) {
            $accountCode = (string) ($acc['account']['code'] ?? '');
            $side = (string) ($acc['account']['normal_side'] ?? 'debit') === 'credit' ? 'payable' : 'receivable';
            foreach ($acc['partners'] ?? [] as $p) {
                $partnerName = (string) ($p['partner_name'] ?? '');
                foreach ($p['items'] ?? [] as $it) {
                    $bySide[$side][] = $it + ['account_code' => $accountCode, 'partner_name' => $partnerName];
                }
            }
        }

        $headers = ['Účet', 'Partner', 'Doklad', 'Vystaveno', 'Splatnost', 'Dní po spl.', 'Měna', 'Částka', 'Uhrazeno', 'Zbývá (Kč)'];
        $cols = count($headers);
        $labels = [
            'receivable' => ['Pohledávky — co dluží nám', 'Pohledávky celkem'],
            'payable'    => ['Závazky — co dlužíme my', 'Závazky celkem'],
        ];

        $r = 5;
        foreach ($bySide as $side => $rows) {
            if ($rows === []) {
                continue;
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['due_date'] ?? ''), (string) ($b['due_date'] ?? '')));

            $sheet->setCellValue("A{$r}", $labels[$side][0]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11);
            $r++;

            $head = $r;
            $this->headerRow($sheet, $head, $headers);
            $r++;

            $total = 0.0;
            foreach ($rows as $it) {
                $ccy = (string) ($it['currency_code'] ?? 'CZK');
                $sheet->setCellValueExplicit([1, $r], (string) ($it['account_code'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([2, $r], (string) ($it['partner_name'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([3, $r], (string) ($it['doc_no'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValue([4, $r], $this->czDate((string) ($it['issue_date'] ?? '')));
                $sheet->setCellValue([5, $r], $this->czDate((string) ($it['due_date'] ?? '')));
                $sheet->setCellValue([6, $r], (int) ($it['days_overdue'] ?? 0) > 0 ? (int) $it['days_overdue'] : '');
                $sheet->setCellValueExplicit([7, $r], $ccy, DataType::TYPE_STRING);
                $sheet->setCellValue([8, $r], (float) ($it['booked_czk'] ?? 0));
                $sheet->setCellValue([9, $r], (float) ($it['paid_czk'] ?? 0));
                $sheet->setCellValue([10, $r], (float) ($it['remaining_czk'] ?? 0));
                $total += (float) ($it['remaining_czk'] ?? 0);
                $r++;
            }

            $sheet->setCellValueExplicit([1, $r], $labels[$side][1], DataType::TYPE_STRING);
            $sheet->setCellValue([10, $r], round($total, 2));
            $this->boldRow($sheet, $r, $cols);
            $this->finishTable($sheet, $head, $r, $cols, 6);
            $r += 2; // mezera mezi stranami salda
        }

        return $this->out($ss, 'saldokonto-doklady-' . $asOf . '.xlsx');
    }

    /**
     * Účetní deník (audit 2026-07, nález „Export a tisk účetního deníku") — výstup
     * JournalExportService::build(). Jeden řádek per zápis (hlavička), následovaný
     * řádkem MD/Dal per účet — jako zákonná kniha (§13 ZoÚ).
     *
     * @param array<string,mixed> $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function journal(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Účetní deník');
        $sheet->setCellValue('A1', 'Účetní deník');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $entity = $data['entity'] ?? [];
        $sheet->setCellValueExplicit('A2', (string) ($entity['name'] ?? '') . ' — IČO: ' . (string) ($entity['ico'] ?? ''), DataType::TYPE_STRING);
        $filters = $data['filters'] ?? [];
        $sub = 'Období: ' . $this->czDate((string) ($filters['date_from'] ?? '')) . ' – ' . $this->czDate((string) ($filters['date_to'] ?? ''));
        if (!empty($filters['source_type'])) {
            $sub .= ' · Zdroj: ' . (string) $filters['source_type'];
        }
        $sheet->setCellValue('A3', $sub);

        $headers = ['Datum', 'Doklad', 'Popis', 'Původ', 'Účet', 'Název', 'MD', 'Dal'];
        $cols = count($headers);
        $head = 5;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['entries'] ?? [] as $entry) {
            $desc = (string) ($entry['description'] ?? '');
            if (!empty($entry['reversed_by'])) $desc .= ' [stornováno #' . (int) $entry['reversed_by'] . ']';
            if (empty($entry['posted_at'])) $desc .= ' [koncept]';

            $sheet->setCellValue([1, $r], $this->czDate((string) $entry['entry_date']));
            $sheet->setCellValueExplicit([2, $r], (string) ($entry['document_no'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], $desc, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) ($entry['automation_origin'] ?? 'ručně'), DataType::TYPE_STRING);
            // Bez filtru na účet je entry['amount'] Σ MD = Σ Dal celého (vyváženého)
            // zápisu — duplikace do obou sloupců je tedy pravdivá. S filtrem na účet
            // (amount_side != null, JournalEntryRepository::forExport) jde o NETTO
            // částku a stranu JEN filtrovaného účtu — psát ji do obou sloupců by
            // tvrdilo Σ MD = Σ Dal i pro tenhle jeden účet, což u zápisu s víc nohama
            // na různých účtech neplatí (nález „ČÁSTKA u filtru na účet"). Proto jde
            // vždy jen do sloupce odpovídajícího straně; řádky pod ní ukazují detail
            // za VŠECHNY účty zápisu beze změny.
            $amountSide = $entry['amount_side'] ?? null;
            if ($amountSide === null || $amountSide === 'debit') {
                $sheet->setCellValue([7, $r], (float) $entry['amount']);
            }
            if ($amountSide === null || $amountSide === 'credit') {
                $sheet->setCellValue([8, $r], (float) $entry['amount']);
            }
            $this->boldRow($sheet, $r, $cols);
            $r++;

            foreach ($entry['lines'] ?? [] as $line) {
                $sheet->setCellValueExplicit([5, $r], (string) ($line['account_code'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([6, $r], (string) ($line['account_name'] ?? ''), DataType::TYPE_STRING);
                if (($line['side'] ?? '') === 'debit') {
                    $sheet->setCellValue([7, $r], (float) $line['amount']);
                } else {
                    $sheet->setCellValue([8, $r], (float) $line['amount']);
                }
                $r++;
            }
        }
        $this->finishTable($sheet, $head, max($head, $r - 1), $cols, 7);

        $t = $data['totals'] ?? [];
        $tr = $r + 1;
        $sheet->setCellValue("A{$tr}", 'Počet zápisů: ' . (int) ($t['count'] ?? 0));
        $sheet->getStyle("A{$tr}")->getFont()->setBold(true);
        $sheet->setCellValue("G{$tr}", (float) ($t['debit'] ?? 0));
        $sheet->setCellValue("H{$tr}", (float) ($t['credit'] ?? 0));
        $sheet->getStyle("G{$tr}:H{$tr}")->getFont()->setBold(true);

        return $this->out($ss, 'ucetni-denik-' . (string) ($filters['date_from'] ?? '') . '_' . (string) ($filters['date_to'] ?? '') . '.xlsx');
    }

    /**
     * Soupis drobného majetku k datu (§DM Sestavy 1) — podklad k inventarizaci.
     *
     * @param array<string,mixed> $data výstup SmallAssetReportService::inventory()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function smallAssetInventory(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $asOf = (string) ($data['as_of'] ?? '');
        $sheet->setTitle('Drobný majetek');
        $sheet->setCellValue('A1', 'Soupis drobného majetku ke dni ' . $this->czDate($asOf));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $headers = ['Umístění', 'Název', 'Inv. číslo', 'Doklad', 'Dodavatel', 'Pořízeno', 'Zařazeno', 'Množství', 'Cena bez DPH', 'Odpovědná osoba'];
        $cols = count($headers);
        $head = 6;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['groups'] ?? [] as $group) {
            foreach ($group['rows'] ?? [] as $row) {
                $sheet->setCellValueExplicit([1, $r], (string) ($group['location'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([3, $r], (string) ($row['inventory_number'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([4, $r], (string) ($row['document_ref'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([5, $r], (string) ($row['vendor_name'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([6, $r], $this->czDate((string) $row['acquisition_date']), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([7, $r], $this->czDate((string) ($row['put_into_use_date'] ?? '')), DataType::TYPE_STRING);
                $sheet->setCellValue([8, $r], (float) $row['quantity']);
                $sheet->setCellValue([9, $r], (float) $row['price']);
                $sheet->setCellValueExplicit([10, $r], (string) ($row['responsible_person'] ?? ''), DataType::TYPE_STRING);
                $r++;
            }
        }

        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($data['count'] ?? 0) . ' položek)');
        $sheet->setCellValue([9, $r], (float) ($data['total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 8);

        $nr = $r + 2;
        $sheet->setCellValue("A{$nr}", 'Evidence drobného majetku dle §28 odst. 5 ZoÚ a ČÚS 013;'
            . ' majetek pod hranicí 80 000 Kč (§26 odst. 2 ZDP) účtovaný jednorázově na účet 501.');
        $sheet->getStyle("A{$nr}")->getFont()->setSize(9)->setItalic(true);

        return $this->out($ss, 'soupis-drobneho-majetku-' . $asOf . '.xlsx');
    }

    /**
     * Přírůstky a úbytky drobného majetku za období (§DM Sestavy 2).
     *
     * @param array<string,mixed> $data výstup SmallAssetReportService::movements()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function smallAssetMovements(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $from = (string) ($data['from'] ?? '');
        $to = (string) ($data['to'] ?? '');
        $sheet->setTitle('Přírůstky a úbytky');
        $sheet->setCellValue('A1', 'Přírůstky a úbytky drobného majetku ' . $this->czDate($from) . ' – ' . $this->czDate($to));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $headers = ['Datum', 'Název', 'Doklad', 'Dodavatel / důvod', 'Množství', 'Cena bez DPH'];
        $cols = count($headers);

        $r = 6;
        $sheet->setCellValue("A{$r}", 'Přírůstky (' . (int) ($data['additions_count'] ?? 0) . ')');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $head = ++$r;
        $this->headerRow($sheet, $head, $headers);
        $r++;
        foreach ($data['additions'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], $this->czDate((string) $row['acquisition_date']), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) ($row['document_ref'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) ($row['vendor_name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['quantity']);
            $sheet->setCellValue([6, $r], (float) $row['price']);
            $r++;
        }
        $sheet->setCellValue([1, $r], 'PŘÍRŮSTKY CELKEM');
        $sheet->setCellValue([6, $r], (float) ($data['additions_total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 5);

        $r += 2;
        $sheet->setCellValue("A{$r}", 'Úbytky (' . (int) ($data['disposals_count'] ?? 0) . ')');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $head = ++$r;
        $this->headerRow($sheet, $head, $headers);
        $r++;
        foreach ($data['disposals'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], $this->czDate((string) ($row['disposed_at'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) ($row['document_ref'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) ($row['disposal_reason'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['quantity']);
            $sheet->setCellValue([6, $r], (float) $row['price']);
            $r++;
        }
        $sheet->setCellValue([1, $r], 'ÚBYTKY CELKEM');
        $sheet->setCellValue([6, $r], (float) ($data['disposals_total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 5);

        return $this->out($ss, 'drobny-majetek-pohyby-' . $from . '_' . $to . '.xlsx');
    }

    /**
     * Rozpis 501 dle druhu výdaje (§DM Sestavy 3) — porovnatelné s 501.100 / 501.200.
     *
     * @param array<string,mixed> $data výstup SmallAssetReportService::expenseBreakdown()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function smallAssetExpenseBreakdown(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $from = (string) ($data['from'] ?? '');
        $to = (string) ($data['to'] ?? '');
        $sheet->setTitle('Rozpis 501');
        $sheet->setCellValue('A1', 'Rozpis účtu 501 dle druhu výdaje ' . $this->czDate($from) . ' – ' . $this->czDate($to));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->entityHeader($sheet, $data);

        $headers = ['Datum', 'Doklad', 'Dodavatel', 'Popis položky', 'Množství', 'Částka bez DPH'];
        $cols = count($headers);

        $r = 6;
        foreach ($data['groups'] ?? [] as $group) {
            $kind = (string) ($group['expense_kind'] ?? '');
            $sheet->setCellValue("A{$r}", $this->expenseKindLabel($kind) . ' — ' . (int) ($group['document_count'] ?? 0) . ' dokladů');
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $head = ++$r;
            $this->headerRow($sheet, $head, $headers);
            $r++;
            foreach ($group['rows'] ?? [] as $row) {
                $sheet->setCellValueExplicit([1, $r], $this->czDate((string) $row['doc_date']), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([2, $r], (string) ($row['document_ref'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([3, $r], (string) ($row['vendor_name'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([4, $r], (string) $row['description'], DataType::TYPE_STRING);
                $sheet->setCellValue([5, $r], (float) $row['quantity']);
                $sheet->setCellValue([6, $r], (float) $row['amount']);
                $r++;
            }
            $sheet->setCellValue([1, $r], mb_strtoupper($this->expenseKindLabel($kind), 'UTF-8') . ' CELKEM');
            $sheet->setCellValue([6, $r], (float) ($group['total'] ?? 0));
            $this->boldRow($sheet, $r, $cols);
            $this->finishTable($sheet, $head, $r, $cols, 5);
            $r += 2;
        }

        $sheet->setCellValue([1, $r], 'ÚČET 501 CELKEM');
        $sheet->setCellValue([6, $r], (float) ($data['total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $nr = $r + 2;
        $sheet->setCellValue("A{$nr}", 'Zdrojem jsou řádky přijatých faktur (expense_kind), ne karty evidence;'
            . ' stornované doklady nejsou zahrnuty.');
        $sheet->getStyle("A{$nr}")->getFont()->setSize(9)->setItalic(true);

        return $this->out($ss, 'rozpis-501-' . $from . '_' . $to . '.xlsx');
    }

    private function expenseKindLabel(string $kind): string
    {
        return match ($kind) {
            'small_asset' => 'Drobný majetek (501.200)',
            'material' => 'Materiál včetně PHM (501.100)',
            default => $kind,
        };
    }

    /**
     * @param array<string,mixed> $data
     */
    private function entityHeader(Worksheet $sheet, array $data): void
    {
        $entity = $data['entity'] ?? [];
        $sheet->setCellValueExplicit('A2', (string) ($entity['name'] ?? '') . ' — IČO: ' . (string) ($entity['ico'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('A3', (string) ($entity['address'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue('A4', 'Okamžik sestavení: ' . (string) ($entity['prepared_at'] ?? '')
            . ' · verze výkazu: ' . (string) ($data['version_code'] ?? ''));
        $sheet->getStyle('A4')->getFont()->setSize(9)->setItalic(true);
    }

    /**
     * @param list<string> $headers
     */
    private function headerRow(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $row], $h);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    }

    private function boldRow(Worksheet $sheet, int $row, int $cols): void
    {
        $last = Coordinate::stringFromColumnIndex($cols);
        $sheet->getStyle("A{$row}:{$last}{$row}")->getFont()->setBold(true);
    }

    private function finishTable(Worksheet $sheet, int $headRow, int $lastRow, int $cols, int $firstNumCol): void
    {
        if ($lastRow < $headRow) {
            $lastRow = $headRow;
        }
        $last = Coordinate::stringFromColumnIndex($cols);
        $sheet->getStyle("A{$headRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $numFirst = Coordinate::stringFromColumnIndex($firstNumCol);
        $sheet->getStyle("{$numFirst}{$headRow}:{$last}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        for ($i = 1; $i <= $cols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
    }

    /**
     * @return array{bytes:string, filename:string, mime:string}
     */
    private function out(Spreadsheet $ss, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'repexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }

    private function scopeLabel(string $scope): string
    {
        return match ($scope) {
            'small' => 've zkráceném rozsahu (malá účetní jednotka)',
            'micro' => 've zkráceném rozsahu (mikro účetní jednotka)',
            default => 'v plném rozsahu',
        };
    }

    private function yesNo(bool $v): string
    {
        return $v ? 'ANO' : 'NE';
    }

    private function czMoney(float $v): string
    {
        return number_format($v, 2, ',', ' ');
    }

    /** Zaokrouhlení na celé tisíce Kč per hodnota nezávisle (F4 R17). */
    private function toThousands(mixed $v): int
    {
        return (int) round(((float) $v) / 1000);
    }

    /** Kč s halíři / celé tisíce bez desetinných míst — dle režimu výkazu. */
    private function czAmount(float $v, bool $thousands): string
    {
        return $thousands ? number_format($v, 0, ',', ' ') : $this->czMoney($v);
    }

    private function czDate(string $v): string
    {
        if ($v === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($v))->format('d.m.Y');
        } catch (\Throwable) {
            return $v;
        }
    }
}
