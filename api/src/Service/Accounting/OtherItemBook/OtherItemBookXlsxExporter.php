<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\OtherItemBook;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX knihy ostatních pohledávek a závazků. Částky a data jsou čísla, součty tytéž jako v PDF.
 *
 * Za lidskými sloupci jsou strojové: uložený kód směru, druhu a stavu a ID vlastního druhu.
 * Drží hodnotu z databáze beze změny (podle nich se dá párovat a filtrovat), popisky
 * vlevo se smějí měnit.
 */
final class OtherItemBookXlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY = '#,##0.00';
    private const DATE = 'd.m.yyyy';

    /** @return array{bytes:string, mime:string} */
    public function export(OtherItemBook $book): array
    {
        $meta = $book->meta;
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Kniha ostatních položek');

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
        $this->text($sheet, 1, $r, (string) $meta['scope_note']);
        $sheet->getStyle([1, $r])->getFont()->setItalic(true);
        $r++;
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

        $headers = ['Doklad', 'Směr', 'Druh', 'Položka', 'Protistrana', 'Datum vzniku', 'Splatnost',
            'Částka', 'Uhrazeno', 'Zbývá uhradit', 'Měna', 'Stav'];
        if ($book->showPosting) {
            $headers[] = 'Zaúčtováno';
        }
        $machineStart = count($headers) + 1;
        array_push($headers, 'side', 'kind', 'kind_id', 'status');
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
            $this->text($sheet, 1, $r, (string) ($row['document_no'] ?? ''));
            $this->text($sheet, 2, $r, (string) $row['side_label']);
            $this->text($sheet, 3, $r, (string) $row['kind_label']);
            $this->text($sheet, 4, $r, (string) $row['title']);
            $this->text($sheet, 5, $r, (string) ($row['partner_name'] ?? ''));
            $this->date($sheet, 6, $r, $row['issued_on'] ?? null);
            $this->date($sheet, 7, $r, $row['due_on'] ?? null);
            $this->money($sheet, 8, $r, (float) $row['amount']);
            $this->money($sheet, 9, $r, (float) $row['paid_amount']);
            $this->money($sheet, 10, $r, (float) $row['remaining_payment']);
            $this->text($sheet, 11, $r, (string) $row['currency']);
            $this->text($sheet, 12, $r, (string) $row['status_label']);
            if ($book->showPosting) {
                $this->date($sheet, 13, $r, $row['posted_on'] ?? null);
            }
            $this->text($sheet, $machineStart, $r, (string) $row['side']);
            $this->text($sheet, $machineStart + 1, $r, (string) $row['kind']);
            if ($row['kind_id'] !== null) {
                $sheet->setCellValue([$machineStart + 2, $r], (int) $row['kind_id']);
            }
            $this->text($sheet, $machineStart + 3, $r, (string) $row['status']);
            $r++;
        }
        if ($r > $firstRow) {
            $sheet->setAutoFilter('A' . ($firstRow - 1) . ":{$last}" . ($r - 1));
        }

        $r++;
        $this->text($sheet, 1, $r, 'Součty');
        $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(11);
        $r++;
        foreach (['Strana', 'Měna', 'Počet položek', 'Částka', 'Uhrazeno', 'Zbývá uhradit'] as $i => $h) {
            $this->text($sheet, $i + 1, $r, $h);
        }
        $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
        $r++;
        foreach ($book->totals() as $t) {
            $this->text($sheet, 1, $r, (string) $t['side_label']);
            $this->text($sheet, 2, $r, (string) $t['currency']);
            $sheet->setCellValue([3, $r], (int) $t['count']);
            $this->money($sheet, 4, $r, (float) $t['amount']);
            $this->money($sheet, 5, $r, (float) $t['paid_amount']);
            $this->money($sheet, 6, $r, (float) $t['remaining_amount']);
            $r++;
        }
        $this->text($sheet, 1, $r + 1, 'Součty jsou po straně a měně, bez přepočtu kurzem. Vstupují do nich jen potvrzené '
            . 'a zaúčtované položky; zbývá uhradit je saldo jistiny a přeplatek nesnižuje jiné položky.');
        $sheet->getStyle([1, $r + 1])->getFont()->setItalic(true);

        $widths = [14, 11, 24, 40, 28, 12, 12, 14, 14, 14, 7, 18];
        if ($book->showPosting) {
            $widths[] = 12;
        }
        array_push($widths, 11, 10, 8, 10);
        foreach ($widths as $i => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'oibook_');
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
