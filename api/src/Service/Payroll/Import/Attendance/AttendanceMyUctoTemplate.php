<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Šablona XLSX pro Vzor MyÚčto: list „Podklady" se sloupci a dvěma ukázkovými
 * řádky smyšlených osob a list „Návod" s popisem sloupců. Sloupce, formáty
 * i texty návodu se berou z {@see AttendanceMyUctoSampleProfile::columns()},
 * odkud vznikají i pravidla vzoru.
 */
final class AttendanceMyUctoTemplate
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const FILENAME = 'podklady-mzdy-myucto.xlsx';

    /** Kódy zdravotních pojišťoven pro návod. */
    public const HEALTH_INSURERS = [
        '111' => 'Všeobecná zdravotní pojišťovna ČR',
        '201' => 'Vojenská zdravotní pojišťovna ČR',
        '205' => 'Česká průmyslová zdravotní pojišťovna',
        '207' => 'Oborová zdravotní pojišťovna',
        '209' => 'Zaměstnanecká pojišťovna Škoda',
        '211' => 'Zdravotní pojišťovna ministerstva vnitra ČR',
        '213' => 'RBP, zdravotní pojišťovna',
    ];

    private const DATE_FORMAT = 'd.m.yyyy';
    private const AMOUNT_FORMAT = '#,##0.00';
    /** Prázdné řádky pod ukázkou, které už mají formát sloupců. */
    private const FORMATTED_ROWS = 200;

    /** @return array{bytes:string,filename:string,mime:string} */
    public function build(): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MyÚčto.cz')
            ->setTitle('Podklady pro mzdy (' . AttendanceMyUctoSampleProfile::NAME . ')');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(AttendanceMyUctoSampleProfile::SHEET);
        $this->dataSheet($sheet);
        $this->guideSheet($spreadsheet->createSheet());
        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'payatt_') . '.xlsx';
        try {
            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $spreadsheet->disconnectWorksheets();
        }

        return ['bytes' => $bytes, 'filename' => self::FILENAME, 'mime' => self::MIME];
    }

    private function dataSheet(Worksheet $sheet): void
    {
        $columns = AttendanceMyUctoSampleProfile::columns();
        $lastRow = 1 + self::FORMATTED_ROWS;
        foreach ($columns as $index => $column) {
            $col = $index + 1;
            $letter = Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValueExplicit([$col, 1], $column['header'], DataType::TYPE_STRING);
            $sheet->getComment("{$letter}1")->getText()->createText($column['help']);
            // Hodiny zůstávají ve formátu Obecný: Excel pak čas „7:30" sám uloží
            // jako čas a import ho pozná; pevný číselný formát by z něj udělal 0,31.
            $format = match ($column['format']) {
                AttendanceMyUctoSampleProfile::FORMAT_DATE => self::DATE_FORMAT,
                AttendanceMyUctoSampleProfile::FORMAT_AMOUNT => self::AMOUNT_FORMAT,
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
        $sheet->freezePane('C2');
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
        if (is_string($value)) {
            $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);

            return;
        }
        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_NUMERIC);
    }

    private function guideSheet(Worksheet $guide): void
    {
        $guide->setTitle(AttendanceMyUctoSampleProfile::GUIDE_SHEET);
        $lines = [
            ['Podklady pro mzdy: ' . AttendanceMyUctoSampleProfile::NAME, '', ''],
            ['', '', ''],
            ['Jak na to', 'Do listu „' . AttendanceMyUctoSampleProfile::SHEET . '“ zapište jeden řádek za každou osobu a měsíc. '
                . 'Dva ukázkové řádky smyšlených osob před importem smažte. Název listu ani hlavičky sloupců neměňte; '
                . 'sloupce, které nepotřebujete, nechte prázdné. Vlastní sloupce navíc import ignoruje.', ''],
            ['Import', 'V MyÚčtu otevřete Mzdy → Importy → Docházka, vyberte měsíc, nahrajte soubor a zvolte profil '
                . '„' . AttendanceMyUctoSampleProfile::NAME . '“. Osoby, které v evidenci chybí, import nabídne založit.', ''],
            ['Datum', 'Jako datum v Excelu nebo text ve tvaru den. měsíc. rok, např. 1. 6. 2026.', ''],
            ['Hodiny', 'Desetinné číslo (7,5 = sedm a půl hodiny) nebo čas v Excelu (7:30), v jednom sloupci vždy stejně. Hodiny noci, víkendu, '
                . 'svátku a přesčasu jsou část odpracovaných hodin, nepřičítají se k nim.', ''],
            ['Částky', 'V Kč za celý měsíc, bez měny (např. 7650 nebo 7 650,50).', ''],
            ['Druh vztahu', 'HPP = pracovní poměr, DPP = dohoda o provedení práce, DPČ = dohoda o pracovní činnosti.', ''],
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
        foreach (AttendanceMyUctoSampleProfile::columns() as $column) {
            $lines[] = [
                $column['header'] . ($column['required'] ? ' (povinné)' : ''),
                $column['help'],
                $formats[$column['format']] ?? 'text',
            ];
        }
        $lines[] = ['', '', ''];
        $lines[] = ['Kód pojišťovny', 'Zdravotní pojišťovna', ''];
        $insurersHeader = count($lines);
        foreach (self::HEALTH_INSURERS as $code => $name) {
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
        $guide->getStyle('A3:A8')->getFont()->setBold(true);
        $guide->getStyle("A{$tableHeader}:C{$tableHeader}")->getFont()->setBold(true);
        $guide->getStyle("A{$insurersHeader}:B{$insurersHeader}")->getFont()->setBold(true);
        $guide->getColumnDimension('A')->setWidth(28);
        $guide->getColumnDimension('B')->setWidth(95);
        $guide->getColumnDimension('C')->setWidth(14);
        $guide->getStyle('B1:B' . count($lines))->getAlignment()->setWrapText(true);
    }
}
