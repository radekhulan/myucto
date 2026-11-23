<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * XLSX sestavy DPH podle dimenze. Částky jsou čísla s haléři, tytéž hodnoty jako na
 * obrazovce a v PDF ({@see VatDimensionReportService::build()}).
 */
final class VatDimensionReportXlsxExporter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY = '#,##0.00';

    /** Sloupce částek v pořadí tabulky. */
    public const COLUMNS = [
        'vat_output' => 'DPH na výstupu',
        'vat_input' => 'DPH na vstupu (nárok)',
        'vat_result' => 'Výsledek',
        'base_output_standard' => 'Základ výstup zákl. sazba',
        'base_output_reduced' => 'Základ výstup sníž. sazba',
        'base_output_other' => 'Základ výstup ostatní',
        'base_input_standard' => 'Základ vstup zákl. sazba',
        'base_input_reduced' => 'Základ vstup sníž. sazba',
        'base_input_other' => 'Základ vstup ostatní',
    ];

    /** @param array<string,mixed> $report */
    public function export(array $report): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('DPH podle dimenze');
        $sheet->setCellValue('A1', 'DPH podle dimenze');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $supplier = $report['supplier'];
        $period = $report['period'];
        $meta = [
            'Firma' => $supplier['company_name'] . ($supplier['ic'] !== '' ? ', IČO ' . $supplier['ic'] : '') . ($supplier['dic'] !== '' ? ', DIČ ' . $supplier['dic'] : ''),
            'Období' => $period['label'] . ' (' . self::czDate($period['start']) . ' - ' . self::czDate($period['end']) . ')',
            'Typ dimenze' => (string) $report['dimension_type']['name'],
            'Sestaveno' => self::czDateTime((string) $report['generated_at']),
        ] + (!empty($report['preliminary']) ? ['Stav' => 'PŘEDBĚŽNÉ - u dokladů v cizí měně chybí kurz (viz upozornění)'] : []);
        $r = 2;
        foreach ($meta as $label => $value) {
            $sheet->setCellValueExplicit([1, $r], $label . ':', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], $value, DataType::TYPE_STRING);
            $r++;
        }
        $r++;

        $headers = ['Hodnota', 'Dokladů', ...array_values(self::COLUMNS)];
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $head = $r;
        foreach ($headers as $i => $h) {
            $sheet->setCellValue([$i + 1, $r], $h);
        }
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        $r++;
        foreach ($report['rows'] as $row) {
            $sheet->setCellValueExplicit([1, $r], str_repeat('  ', (int) $row['depth']) . $row['label'], DataType::TYPE_STRING);
            $sheet->setCellValue([2, $r], (int) $row['documents']);
            $this->money($sheet, $r, $row);
            $r++;
        }
        $lines = [
            ['Celkem', $report['total'], true],
            ['Evidence DPH za období (stejná data jako přiznání)', $report['control']['evidence'], false],
            ['Rozdíl', $report['control']['difference'], true],
        ];
        foreach ($lines as [$label, $values, $bold]) {
            $sheet->setCellValueExplicit([1, $r], $label, DataType::TYPE_STRING);
            $this->money($sheet, $r, $values);
            if ($bold) {
                $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            }
            $r++;
        }
        $sheet->getStyle("A{$head}:{$last}" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $r++;

        $return = $report['control']['return'];
        if ($return !== null) {
            $sheet->setCellValueExplicit([1, $r], 'Přiznání k DPH (ř. 62, 63, 64)', DataType::TYPE_STRING);
            $this->money($sheet, $r, $return);
            $r++;
            $sheet->setCellValueExplicit([1, $r], 'Úpravy přiznání (§ 79, § 76, § 78) a zaokrouhlení (přiznání − celkem)', DataType::TYPE_STRING);
            $this->money($sheet, $r, $return['bridge']);
            $r++;
        } elseif ($report['control']['return_error'] !== null) {
            $sheet->setCellValueExplicit([1, $r], (string) $report['control']['return_error'], DataType::TYPE_STRING);
            $r++;
        }
        foreach ($report['control']['line_differences'] as $d) {
            $sheet->setCellValueExplicit([1, $r], sprintf('Rozdíl na ř. %s: základ %s, DPH %s', $d['line'], self::num($d['base']), self::num($d['vat'])), DataType::TYPE_STRING);
            $r++;
        }
        $r++;

        $account = $report['account343'];
        if ($account !== null) {
            $sheet->setCellValueExplicit([1, $r], 'Kontrola obratů účtu 343', DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(11);
            $r++;
            $head = $r;
            foreach (['Hodnota', 'MD', 'D', 'Saldo (D − MD)', 'Výsledek sestavy', 'Rozdíl'] as $i => $h) {
                $sheet->setCellValue([$i + 1, $r], $h);
            }
            $sheet->getStyle("A{$r}:F{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:F{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
            $r++;
            $rows = [...$account['rows'], ['label' => 'Celkem'] + $account['total']];
            foreach ($rows as $i => $row) {
                $cmp = $account['comparison'][$i];
                $sheet->setCellValueExplicit([1, $r], (string) $row['label'], DataType::TYPE_STRING);
                foreach ([(float) $row['debit'], (float) $row['credit'], (float) $row['net'], (float) $cmp['report_result'], (float) $cmp['difference']] as $j => $v) {
                    $sheet->setCellValue([$j + 2, $r], $v);
                    $sheet->getStyle([$j + 2, $r])->getNumberFormat()->setFormatCode(self::MONEY);
                }
                $r++;
            }
            $sheet->getStyle("A" . ($r - 1) . ":F" . ($r - 1))->getFont()->setBold(true);
            $sheet->getStyle("A{$head}:F" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->setCellValueExplicit([1, $r], sprintf(
                'Mimo obrat: úhrady a vratky DPH finančnímu úřadu %d zápisů (saldo %s), počáteční stavy a uzávěrka %d zápisů (saldo %s).',
                $account['excluded']['payments']['count'], self::num($account['excluded']['payments']['net']),
                $account['excluded']['opening']['count'], self::num($account['excluded']['opening']['net']),
            ), DataType::TYPE_STRING);
            $r++;
            $sheet->setCellValueExplicit([1, $r], (string) $account['note'], DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
            $r++;
        } elseif ($report['account343_unavailable'] !== null) {
            $sheet->setCellValueExplicit([1, $r], (string) $report['account343_unavailable'], DataType::TYPE_STRING);
            $r++;
        }
        foreach ($report['warnings'] as $w) {
            $sheet->setCellValueExplicit([1, $r], (string) $w, DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(48);
        for ($i = 2; $i <= count($headers); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'vatdim_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $bytes;
    }

    /** @param array<string,mixed> $values */
    private function money(Worksheet $sheet, int $row, array $values): void
    {
        $col = 3;
        foreach (array_keys(self::COLUMNS) as $key) {
            if (array_key_exists($key, $values)) {
                $sheet->setCellValue([$col, $row], (float) $values[$key]);
                $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode(self::MONEY);
            }
            $col++;
        }
    }

    private static function num(float|int $v): string
    {
        return number_format((float) $v, 2, ',', ' ');
    }

    private static function czDate(string $date): string
    {
        return $date === '' ? '' : (new \DateTimeImmutable($date))->format('d.m.Y');
    }

    private static function czDateTime(string $date): string
    {
        return $date === '' ? '' : (new \DateTimeImmutable($date))->format('d.m.Y H:i');
    }
}
