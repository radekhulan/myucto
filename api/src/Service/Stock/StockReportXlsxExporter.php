<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX export skladových sestav (Epic SKLAD, vzor F2 {@see \MyInvoice\Service\Accounting\Reports\ReportXlsxExporter}).
 * Vlastní třída (ne rozšíření F2 exporteru) — Sklad je samostatný opt-in modul.
 */
final class StockReportXlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param array<string,mixed> $data výstup StockReportService::status()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function status(array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Stav zásob');
        $sheet->setCellValue('A1', 'Stav zásob k ' . (new \DateTimeImmutable())->format('d.m.Y'));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Sloupce podle výběru v sestavě; bez výběru původních osm (StockStatusColumns).
        $layout = StockStatusColumns::layout($data);
        $headers = array_column($layout, 'label');
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach (StockStatusColumns::exportItems($data) as $row) {
            foreach ($layout as $i => $column) {
                $value = $row[$column['key']] ?? null;
                if ($value === null) {
                    continue;
                }
                if ($column['numeric']) {
                    $sheet->setCellValue([$i + 1, $r], (float) $value);
                } else {
                    $sheet->setCellValueExplicit([$i + 1, $r], (string) $value, DataType::TYPE_STRING);
                }
            }
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($t['count'] ?? 0) . ' položek)');
        $valueCol = array_search('value_total', array_column($layout, 'key'), true);
        if ($valueCol !== false) {
            $sheet->setCellValue([$valueCol + 1, $r], (float) ($t['value_total'] ?? 0));
        }
        $this->boldRow($sheet, $r, $cols);

        $numeric = array_keys(array_filter($layout, static fn (array $c): bool => $c['numeric']));
        $this->finishTable($sheet, $head, $r, $cols, min($numeric) + 1, max($numeric) + 1);

        return $this->out($ss, 'stav-zasob-' . (new \DateTimeImmutable())->format('Y-m-d') . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup StockReportService::valuation()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function valuation(array $data): array
    {
        $date = (string) ($data['date'] ?? '');
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Ocenění zásob');
        $sheet->setCellValue('A1', 'Ocenění zásob k ' . $this->czDate($date));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Sklad', 'SKU', 'Název', 'MJ', 'Množství', 'Hodnota'];
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['unit'], DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['qty']);
            $sheet->setCellValue([6, $r], (float) $row['value_total']);
            $r++;
        }

        $t = $data['totals'] ?? [];
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($t['count'] ?? 0) . ' položek)');
        $sheet->setCellValue([6, $r], (float) ($t['value_total'] ?? 0));
        $this->boldRow($sheet, $r, $cols);

        $this->finishTable($sheet, $head, $r, $cols, 5);

        if (is_array($data['category_groups'] ?? null)) {
            $this->valuationCategorySheet($ss, $data['category_groups'], $date);
            $ss->setActiveSheetIndex(0);
        }

        return $this->out($ss, 'oceneni-zasob-' . $date . '.xlsx');
    }

    /**
     * @param array<string,mixed> $data výstup StockHistoryImportService::endState()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function historyEndState(array $data): array
    {
        $cutover = $data['cutover_date'];
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Koncový stav historie');
        $sheet->setCellValue('A1', $cutover !== null
            ? 'Koncový stav převzaté historie proti počátečnímu stavu v MyÚčtu k ' . $this->czDate($cutover)
            : 'Koncový stav převzaté historie');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Sklad', 'SKU', 'Název', 'MJ', 'Historie', 'MyÚčto', 'Rozdíl'];
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] as $row) {
            $sheet->setCellValueExplicit([1, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], (string) $row['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['unit'], DataType::TYPE_STRING);
            $sheet->setCellValue([5, $r], (float) $row['history_qty']);
            if ($row['stock_qty'] !== null) {
                $sheet->setCellValue([6, $r], (float) $row['stock_qty']);
                $sheet->setCellValue([7, $r], (float) $row['difference']);
            }
            $r++;
        }
        $sheet->setCellValue([1, $r], 'CELKEM (' . (int) $data['totals']['count'] . ' položek, s rozdílem ' . (int) $data['totals']['differences'] . ')');
        $this->boldRow($sheet, $r, $cols);
        $this->finishTable($sheet, $head, $r, $cols, 5);

        return $this->out($ss, 'koncovy-stav-historie-' . ($cutover ?? date('Y-m-d')) . '.xlsx');
    }

    /**
     * List „Podle kategorií“: skupiny ocenění (StockValuationCategoryGroups),
     * součtový řádek = celkové ocenění a poznámka o dnešním zařazení karet.
     *
     * @param array<string,mixed> $grouping
     */
    private function valuationCategorySheet(Spreadsheet $ss, array $grouping, string $date): void
    {
        $sheet = $ss->createSheet();
        $sheet->setTitle('Podle kategorií');
        $parent = $grouping['parent'] ?? null;
        $sheet->setCellValue('A1', 'Ocenění zásob k ' . $this->czDate($date) . ' podle kategorií'
            . (is_array($parent) ? ': ' . $parent['name'] : ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $head = 3;
        $this->headerRow($sheet, $head, ['Kategorie', 'Položek', 'Hodnota']);
        $r = $head + 1;
        foreach ($grouping['groups'] ?? [] as $group) {
            $sheet->setCellValueExplicit([1, $r], self::valuationGroupLabel($group, $parent), DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], (int) $group['count']);
            $sheet->setCellValue([3, $r], (float) $group['value_total']);
            $r++;
        }
        $sheet->setCellValue([1, $r], 'CELKEM');
        $sheet->setCellValue([2, $r], (int) ($grouping['count'] ?? 0));
        $sheet->setCellValue([3, $r], (float) ($grouping['value_total'] ?? 0));
        $this->boldRow($sheet, $r, 3);
        $this->finishTable($sheet, $head, $r, 3, 2);
        $sheet->setCellValue([1, $r + 2], self::VALUATION_GROUPS_NOTE);
    }

    /** Poznámka k seskupení ocenění (stejný text v PDF). */
    public const VALUATION_GROUPS_NOTE = 'Karty jsou zařazené podle dnešních kategorií, ne podle kategorií k datu ocenění. '
        . 'Karta ve více kategoriích se počítá jen jednou (primární kategorie, jinak první v pořadí karty).';

    /**
     * Popisek skupiny ocenění pro export.
     *
     * @param array<string,mixed> $group
     * @param array<string,mixed>|null $parent
     */
    public static function valuationGroupLabel(array $group, ?array $parent): string
    {
        return match ($group['kind'] ?? '') {
            'parent' => 'Přímo v kategorii ' . (string) ($parent['name'] ?? ''),
            'uncategorized' => is_array($parent) ? 'Mimo kategorii ' . (string) $parent['name'] : 'Bez kategorie',
            default => (string) ($group['name'] ?? ''),
        };
    }

    /**
     * Prodeje skladových karet: list Řádky (1 řádek faktury = 1 řádek) a při
     * seskupení i list Souhrn. Částky v měně dokladu, součty po měnách.
     *
     * @param array<string,mixed> $data výstup StockSalesReportService::export()
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function sales(array $data): array
    {
        $f = $data['filters'] ?? [];
        $period = $this->czDate((string) ($f['date_from'] ?? '')) . ' – ' . $this->czDate((string) ($f['date_to'] ?? ''));
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Řádky');
        $sheet->setCellValue('A1', 'Prodeje skladových karet ' . $period);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['DUZP', 'Doklad', 'Typ', 'Odběratel', 'SKU', 'Název', 'Sériová čísla / šarže', 'Množství', 'MJ', 'Cena/MJ', 'Celkem bez DPH', 'Měna'];
        $cols = count($headers);
        $head = 3;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValue([1, $r], $this->czDate((string) $row['tax_date']));
            $sheet->setCellValueExplicit([2, $r], (string) ($row['invoice_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], $row['invoice_type'] === 'credit_note' ? 'Dobropis' : 'Faktura', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) $row['client_name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $r], (string) $row['sku'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $r], (string) $row['name'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([7, $r], implode(', ', $row['identifiers'] ?? []), DataType::TYPE_STRING);
            $sheet->setCellValue([8, $r], (float) $row['qty']);
            $sheet->setCellValueExplicit([9, $r], (string) $row['unit'], DataType::TYPE_STRING);
            $sheet->setCellValue([10, $r], (float) $row['unit_price']);
            $sheet->setCellValue([11, $r], (float) $row['total_without_vat']);
            $sheet->setCellValueExplicit([12, $r], (string) $row['currency'], DataType::TYPE_STRING);
            $r++;
        }
        $t = $data['totals'] ?? [];
        foreach ($t['amounts'] ?? [] as $amount) {
            $sheet->setCellValue([1, $r], 'CELKEM (' . (int) ($t['lines'] ?? 0) . ' řádků)');
            if (count($t['units'] ?? []) === 1) {
                $sheet->setCellValue([8, $r], (float) ($t['qty'] ?? 0));
            }
            $sheet->setCellValue([11, $r], (float) $amount['total_without_vat']);
            $sheet->setCellValueExplicit([12, $r], (string) $amount['currency'], DataType::TYPE_STRING);
            $this->boldRow($sheet, $r, $cols);
            $r++;
        }
        $this->finishTable($sheet, $head, $r - 1, $cols, 8);

        if (($data['groups'] ?? []) !== []) {
            $byClient = ($f['group_by'] ?? '') === 'client';
            $summary = $ss->createSheet();
            $summary->setTitle('Souhrn');
            $summary->setCellValue('A1', ($byClient ? 'Prodeje podle odběratelů ' : 'Prodeje podle karet ') . $period);
            $summary->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $groupHeaders = $byClient
                ? ['Odběratel', 'Dokladů', 'Řádků', 'Množství', 'Celkem bez DPH', 'Měna']
                : ['SKU', 'Název', 'Dokladů', 'Řádků', 'Množství', 'Celkem bez DPH', 'Měna'];
            $this->headerRow($summary, 3, $groupHeaders);
            $r = 4;
            foreach ($data['groups'] as $group) {
                foreach ($group['amounts'] as $i => $amount) {
                    $values = $byClient ? [(string) $group['label']] : [(string) $group['code'], (string) $group['label']];
                    foreach ($values as $c => $value) {
                        $summary->setCellValueExplicit([$c + 1, $r], $value, DataType::TYPE_STRING);
                    }
                    $c = count($values) + 1;
                    if ($i === 0) {
                        $summary->setCellValue([$c, $r], (int) $group['documents']);
                        $summary->setCellValue([$c + 1, $r], (int) $group['lines']);
                        $summary->setCellValue([$c + 2, $r], (float) $group['qty']);
                    }
                    $summary->setCellValue([$c + 3, $r], (float) $amount['total_without_vat']);
                    $summary->setCellValueExplicit([$c + 4, $r], (string) $amount['currency'], DataType::TYPE_STRING);
                    $r++;
                }
            }
            $this->finishTable($summary, 3, $r - 1, count($groupHeaders), count($groupHeaders) - 4);
            // Seskupení si uživatel vybral kvůli souhrnu, proto se sešit otevře na něm.
            $ss->setActiveSheetIndexByName('Souhrn');
        }

        return $this->out($ss, 'prodeje-skladovych-karet-' . (string) ($f['date_from'] ?? '') . '-' . (string) ($f['date_to'] ?? '') . '.xlsx');
    }

    /**
     * @param array<string,mixed> $item skladová karta {sku,name,unit}
     * @param array<string,mixed> $data výstup StockItemAction::movements() (items, opening_balance)
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function itemMovements(array $item, array $data): array
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Skladová karta');
        $sheet->setCellValue('A1', 'Skladová karta ' . (string) ($item['sku'] ?? '') . ' — ' . (string) ($item['name'] ?? ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', 'Počáteční stav: ' . (string) ($data['opening_balance'] ?? '0'));

        $headers = ['Datum', 'Doklad', 'Typ', 'Faktura', 'Partner', 'Sklad', 'Množství', 'Cena/MJ', 'Hodnota', 'Stav po pohybu'];
        $cols = count($headers);
        $head = 4;
        $this->headerRow($sheet, $head, $headers);

        $r = $head + 1;
        foreach ($data['items'] ?? [] as $row) {
            $sheet->setCellValue([1, $r], $this->czDate((string) $row['doc_date']));
            $sheet->setCellValueExplicit([2, $r], (string) ($row['doc_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([3, $r], (string) $row['doc_type'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([4, $r], (string) ($row['invoice_number'] ?? $row['purchase_invoice_number'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([5, $r], (string) ($row['partner']['name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([6, $r], (string) $row['warehouse_code'], DataType::TYPE_STRING);
            $sheet->setCellValue([7, $r], (float) $row['qty_signed']);
            $sheet->setCellValue([8, $r], (float) $row['unit_cost']);
            $sheet->setCellValue([9, $r], (float) $row['value_total']);
            $sheet->setCellValue([10, $r], (float) $row['balance_after']);
            $r++;
        }

        $this->finishTable($sheet, $head, $r - 1, $cols, 7);

        return $this->out($ss, 'skladova-karta-' . (string) ($item['sku'] ?? '') . '.xlsx');
    }

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

    /** Čísla zarovná doprava od `$firstNumCol` do `$lastNumCol` (výchozí poslední sloupec). */
    private function finishTable(Worksheet $sheet, int $headRow, int $lastRow, int $cols, int $firstNumCol, ?int $lastNumCol = null): void
    {
        if ($lastRow < $headRow) {
            $lastRow = $headRow;
        }
        $last = Coordinate::stringFromColumnIndex($cols);
        $sheet->getStyle("A{$headRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $numFirst = Coordinate::stringFromColumnIndex($firstNumCol);
        $numLast = Coordinate::stringFromColumnIndex($lastNumCol ?? $cols);
        $sheet->getStyle("{$numFirst}{$headRow}:{$numLast}{$lastRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        for ($i = 1; $i <= $cols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
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

    /** @return array{bytes:string, filename:string, mime:string} */
    private function out(Spreadsheet $ss, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'stockexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }
}
