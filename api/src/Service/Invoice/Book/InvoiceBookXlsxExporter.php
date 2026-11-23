<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice\Book;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX knihy vystavených / přijatých faktur. Částky a data jsou čísla (ne text), aby se
 * daly dál sčítat a filtrovat; součty jsou tytéž hodnoty jako v PDF.
 */
final class InvoiceBookXlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY = '#,##0.00';
    private const DATE = 'd.m.yyyy';

    /** @return array{bytes:string, mime:string} */
    public function export(InvoiceBook $book): array
    {
        $meta = $book->meta;
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle($book->kind === InvoiceBook::ISSUED ? 'Kniha vystavených' : 'Kniha přijatých');

        $sheet->setCellValue('A1', (string) $meta['title']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $supplier = $meta['supplier'];
        $info = [
            'Firma' => (string) $supplier['company_name'],
            'IČ' => (string) $supplier['ic'],
            'Období' => (string) $meta['period_label'],
            'Vytiskl' => (string) $meta['printed_by'] . ', ' . (new \DateTimeImmutable((string) $meta['printed_at']))->format('d.m.Y H:i'),
        ];
        $r = 2;
        foreach ($info as $label => $value) {
            if ($value === '') {
                continue;
            }
            $this->text($sheet, 1, $r, $label . ':');
            $this->text($sheet, 2, $r, $value);
            $r++;
        }
        if ($meta['selection'] !== []) {
            $r++;
            $this->text($sheet, 1, $r, 'Nastavení výběru');
            $sheet->getStyle([1, $r])->getFont()->setBold(true);
            $r++;
            foreach ($meta['selection'] as $item) {
                $this->text($sheet, 1, $r, (string) $item['label'] . ':');
                $this->text($sheet, 2, $r, (string) $item['value']);
                $r++;
            }
        }
        $r++;

        $headers = ['Doklad', 'Datum vyst.', 'Datum splat.', 'DUZP', 'Částka', 'Zbývá uhradit', 'Měna',
            $book->kind === InvoiceBook::ISSUED ? 'Odběratel' : 'Dodavatel', 'Variabilní symbol', 'Popis', 'Poznámka'];
        $last = Coordinate::stringFromColumnIndex(count($headers));
        foreach ($headers as $i => $h) {
            $this->text($sheet, $i + 1, $r, $h);
        }
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        $sheet->freezePane('A' . ($r + 1));
        $r++;
        $firstRow = $r;

        foreach ($book->rows() as $row) {
            $this->text($sheet, 1, $r, (string) $row['number']);
            $this->date($sheet, 2, $r, $row['issue_date'] ?? null);
            $this->date($sheet, 3, $r, $row['due_date'] ?? null);
            $this->date($sheet, 4, $r, $row['duzp'] ?? null);
            $this->money($sheet, 5, $r, (float) $row['amount']);
            $this->money($sheet, 6, $r, (float) $row['remaining']);
            $this->text($sheet, 7, $r, (string) $row['currency']);
            $this->text($sheet, 8, $r, (string) $row['partner_name']);
            $this->text($sheet, 9, $r, (string) $row['variable_symbol']);
            $this->text($sheet, 10, $r, (string) $row['description']);
            $this->text($sheet, 11, $r, $row['cancelled'] ? 'storno' : ($row['draft'] ? 'koncept' : ''));
            $r++;
        }
        if ($r > $firstRow) {
            $sheet->setAutoFilter('A' . ($firstRow - 1) . ":{$last}" . ($r - 1));
        }

        $r++;
        $this->text($sheet, 1, $r, 'Součty');
        $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(11);
        $r++;
        $totalHeaders = ['Měna', 'Počet dokladů', 'Celkem k úhradě', 'Zbývá uhradit (faktury)',
            'Zbývá uhradit (dobropisy)', 'Daňové doklady k přijaté platbě', 'Celkem doklady'];
        foreach ($totalHeaders as $i => $h) {
            $this->text($sheet, $i + 1, $r, $h);
        }
        $sheet->getStyle("A{$r}:G{$r}")->getFont()->setBold(true);
        $r++;
        foreach ($book->totals() as $t) {
            $this->text($sheet, 1, $r, (string) $t['currency']);
            $sheet->setCellValue([2, $r], (int) $t['count']);
            foreach (['to_pay', 'remaining_invoices', 'remaining_credit_notes', 'tax_documents', 'total'] as $i => $k) {
                $this->money($sheet, 3 + $i, $r, (float) $t[$k]);
            }
            $r++;
        }
        $this->text($sheet, 1, $r + 1, 'Součty jsou v měně dokladů, bez přepočtu kurzem. Koncepty a stornované doklady do součtů nevstupují.');
        $sheet->getStyle([1, $r + 1])->getFont()->setItalic(true);

        foreach ([1 => 16, 2 => 12, 3 => 12, 4 => 12, 5 => 14, 6 => 14, 7 => 8, 8 => 32, 9 => 14, 10 => 50, 11 => 10] as $col => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth($width);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'invbook_');
        try {
            (new XlsxWriter($ss))->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $ss->disconnectWorksheets();
        }
        return ['bytes' => $bytes, 'mime' => self::MIME];
    }

    private function text(Worksheet $sheet, int $col, int $row, string $value): void
    {
        $sheet->setCellValueExplicit([$col, $row], $value, DataType::TYPE_STRING);
    }

    private function money(Worksheet $sheet, int $col, int $row, float $value): void
    {
        $sheet->setCellValue([$col, $row], $value);
        $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::MONEY);
    }

    private function date(Worksheet $sheet, int $col, int $row, mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($d === false) {
            return;
        }
        $sheet->setCellValue([$col, $row], ExcelDate::PHPToExcel($d));
        $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::DATE);
    }
}
