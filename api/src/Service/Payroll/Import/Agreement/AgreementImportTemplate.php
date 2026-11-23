<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Agreement;

use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoSampleProfile;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMyUctoTemplate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Šablona XLSX importu dohod: list „Dohody" se sloupci
 * {@see AgreementImportColumns::columns()} a dvěma ukázkovými řádky
 * smyšlených osob, list „Návod" s popisem sloupců.
 */
final class AgreementImportTemplate
{
    public const FILENAME = 'dohody-myucto.xlsx';

    private const FORMATTED_ROWS = 600;

    /** @return array{bytes:string,filename:string,mime:string} */
    public function build(): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('MyÚčto.cz')->setTitle('Import dohod');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(AgreementImportColumns::SHEET);
        $this->dataSheet($sheet);
        $this->guideSheet($spreadsheet->createSheet());
        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'payagr_') . '.xlsx';
        try {
            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $spreadsheet->disconnectWorksheets();
        }

        return ['bytes' => $bytes, 'filename' => self::FILENAME, 'mime' => AttendanceMyUctoTemplate::MIME];
    }

    private function dataSheet(Worksheet $sheet): void
    {
        $columns = AgreementImportColumns::columns();
        $lastRow = 1 + self::FORMATTED_ROWS;
        foreach ($columns as $index => $column) {
            $col = $index + 1;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValueExplicit([$col, 1], $column['header'], DataType::TYPE_STRING);
            $sheet->getComment("{$letter}1")->getText()->createText($column['help']);
            $format = match ($column['format']) {
                AttendanceMyUctoSampleProfile::FORMAT_DATE => 'd.m.yyyy',
                AttendanceMyUctoSampleProfile::FORMAT_AMOUNT => '#,##0.00',
                AttendanceMyUctoSampleProfile::FORMAT_HOURS => null,
                default => NumberFormat::FORMAT_TEXT,
            };
            if ($format !== null) {
                $sheet->getStyle("{$letter}2:{$letter}{$lastRow}")->getNumberFormat()->setFormatCode($format);
            }
            foreach ($column['examples'] as $offset => $value) {
                $this->value($sheet, $col, 2 + $offset, $column['format'], $value);
            }
            $sheet->getColumnDimension($letter)->setWidth(max(12, min(28, mb_strlen($column['header']) + 4)));
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $header = $sheet->getStyle("A1:{$lastColumn}1");
        $header->getFont()->setBold(true);
        $header->getAlignment()->setWrapText(true);
        $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        $sheet->freezePane('D2');
    }

    private function value(Worksheet $sheet, int $column, int $row, string $format, string|int|float|null $value): void
    {
        if ($value === null) {
            return;
        }
        if ($format === AttendanceMyUctoSampleProfile::FORMAT_DATE && is_string($value)) {
            $serial = (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($value))->days;
            $sheet->setCellValueExplicit([$column, $row], $serial, DataType::TYPE_NUMERIC);

            return;
        }
        $sheet->setCellValueExplicit([$column, $row], $value, is_string($value) ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC);
    }

    private function guideSheet(Worksheet $guide): void
    {
        $guide->setTitle(AgreementImportColumns::GUIDE_SHEET);
        $lines = [
            ['Import dohod do MyÚčta', '', ''],
            ['', '', ''],
            ['Jak na to', 'Do listu „' . AgreementImportColumns::SHEET . '“ zapište jeden řádek za každou dohodu nebo pracovní poměr. '
                . 'Jedna osoba může mít víc řádků (víc dohod). Dva ukázkové řádky smyšlených osob před importem smažte. '
                . 'Hlavičky sloupců neměňte; sloupce, které nepotřebujete, nechte prázdné nebo smažte.', ''],
            ['Import', 'V MyÚčtu otevřete Mzdy → Importy → Dohody, nahrajte soubor a zkontrolujte náhled. Osobě, která už v evidenci je, '
                . 'se založí jen nová dohoda, nové osobě karta i dohoda. Dohodu, kterou osoba už má, import nezaloží znovu.', ''],
            ['Přihláška ČSSZ', 'Vyplněné sloupce od Ulice po Vedoucí pozici stačí k přípravě přihlášky A1 bez ručního doplňování karet '
                . '(Mzdová podání → Mimořádná podání → Přihlášky k podání).', ''],
            ['Datum', 'Jako datum v Excelu nebo text ve tvaru den. měsíc. rok, např. 1. 12. 2026.', ''],
            ['', '', ''],
            ['Sloupec', 'Co do něj patří', 'Formát'],
        ];
        $formats = [
            AttendanceMyUctoSampleProfile::FORMAT_TEXT => 'text',
            AttendanceMyUctoSampleProfile::FORMAT_DATE => 'datum',
            AttendanceMyUctoSampleProfile::FORMAT_HOURS => 'hodiny',
            AttendanceMyUctoSampleProfile::FORMAT_AMOUNT => 'částka v Kč',
        ];
        $tableHeader = count($lines);
        foreach (AgreementImportColumns::columns() as $column) {
            $lines[] = [
                $column['header'] . ($column['required'] ? ' (povinné)' : ''),
                $column['help'],
                $formats[$column['format']] ?? 'text',
            ];
        }
        $lines[] = ['', '', ''];
        $lines[] = ['Kód pojišťovny', 'Zdravotní pojišťovna', ''];
        $insurersHeader = count($lines);
        foreach (AttendanceMyUctoTemplate::HEALTH_INSURERS as $code => $name) {
            $lines[] = [(string) $code, $name, ''];
        }
        foreach ($lines as $index => $line) {
            foreach ($line as $offset => $text) {
                if ($text !== '') {
                    $guide->setCellValueExplicit([$offset + 1, $index + 1], $text, DataType::TYPE_STRING);
                }
            }
        }
        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $guide->getStyle('A3:A6')->getFont()->setBold(true);
        $guide->getStyle("A{$tableHeader}:C{$tableHeader}")->getFont()->setBold(true);
        $guide->getStyle("A{$insurersHeader}:B{$insurersHeader}")->getFont()->setBold(true);
        $guide->getColumnDimension('A')->setWidth(28);
        $guide->getColumnDimension('B')->setWidth(95);
        $guide->getColumnDimension('C')->setWidth(14);
        $guide->getStyle('B1:B' . count($lines))->getAlignment()->setWrapText(true);
    }
}
