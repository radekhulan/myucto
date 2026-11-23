<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Export;

use MyInvoice\Service\Export\CsvWriter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export sestavy podrobného zaúčtování mezd
 * ({@see \MyInvoice\Service\Payroll\Report\PayrollPostingDetailReportService}).
 *
 * XLSX má jeden list: nahoře A. souhrn za firmu po druzích mezd a dvojicích účtů,
 * pod ním B. rozpad po zaměstnancích a druzích mezd, každý oddíl se součtem.
 * CSV nese jen podrobné řádky (jedna tabulka), souhrn si z něj Excel spočte.
 */
final class PayrollPostingDetailExporter
{
    public const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const MIME_CSV = 'text/csv; charset=utf-8';
    public const DETAIL_HEADERS = [
        'Osobní číslo',
        'Jméno',
        'Druh mzdy',
        'Účet MD',
        'Účet Dal',
        'Středisko',
        'Hodiny',
        'Částka Kč',
    ];
    private const SUMMARY_HEADERS = ['Druh mzdy', 'Účet MD', 'Účet Dal', 'Hodiny', 'Částka Kč'];
    private const MONEY_FORMAT = '#,##0.00';
    private const WIDTHS = [14, 28, 38, 12, 12, 14, 10, 16];

    /**
     * @param array<string,mixed> $report
     * @return array{bytes:string,filename:string,mime:string}
     */
    public function xlsx(array $report, string $companyName): array
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MyÚčto.cz')
            ->setTitle('Zaúčtování mezd ' . $report['period']);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Zaúčtování mezd');

        $this->text($sheet, 1, 1, sprintf('Zaúčtování mezd %s, %s', self::periodLabel($report['period']), $companyName));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $r = 3;
        $this->text($sheet, 1, $r, 'A. Souhrnné zaúčtování za firmu');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $this->headers($sheet, $r, self::SUMMARY_HEADERS);
        $r++;
        $first = $r;
        foreach ($report['summary'] as $row) {
            $this->text($sheet, 1, $r, (string) $row['label']);
            $this->text($sheet, 2, $r, (string) ($row['debit_account'] ?? ''));
            $this->text($sheet, 3, $r, (string) ($row['credit_account'] ?? ''));
            $this->number($sheet, 4, $r, $row['hours']);
            $this->money($sheet, 5, $r, (int) $row['amount_minor']);
            $r++;
        }
        $this->total($sheet, $r, 5, $first, (int) $report['totals']['amount_minor']);

        $r += 3;
        $this->text($sheet, 1, $r, 'B. Podrobné zaúčtování po zaměstnancích a druzích mezd');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $this->headers($sheet, $r, self::DETAIL_HEADERS);
        $r++;
        $first = $r;
        foreach ($report['rows'] as $row) {
            $this->text($sheet, 1, $r, (string) ($row['personal_number'] ?? ''));
            $this->text($sheet, 2, $r, (string) ($row['name'] ?? ''));
            $this->text($sheet, 3, $r, (string) $row['label']);
            $this->text($sheet, 4, $r, (string) ($row['debit_account'] ?? ''));
            $this->text($sheet, 5, $r, (string) ($row['credit_account'] ?? ''));
            $this->text($sheet, 6, $r, (string) ($row['cost_center'] ?? ''));
            $this->number($sheet, 7, $r, $row['hours']);
            $this->money($sheet, 8, $r, (int) $row['amount_minor']);
            $r++;
        }
        $this->total($sheet, $r, 8, $first, (int) $report['totals']['amount_minor']);

        foreach (self::WIDTHS as $index => $width) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'paypost_') . '.xlsx';
        try {
            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($tmp);
            $bytes = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $spreadsheet->disconnectWorksheets();
        }

        return ['bytes' => $bytes, 'filename' => self::filename($report['period'], 'xlsx'), 'mime' => self::MIME_XLSX];
    }

    /**
     * @param array<string,mixed> $report
     * @return array{bytes:string,filename:string,mime:string}
     */
    public function csv(array $report): array
    {
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = [
                CsvWriter::safe($row['personal_number']),
                CsvWriter::safe($row['name']),
                CsvWriter::safe($row['label']),
                CsvWriter::safe($row['debit_account']),
                CsvWriter::safe($row['credit_account']),
                CsvWriter::safe($row['cost_center']),
                $row['hours'] === null ? '' : number_format((float) $row['hours'], 2, ',', ''),
                number_format(((int) $row['amount_minor']) / 100, 2, ',', ''),
            ];
        }

        return [
            'bytes' => CsvWriter::build(self::DETAIL_HEADERS, $rows),
            'filename' => self::filename($report['period'], 'csv'),
            'mime' => self::MIME_CSV,
        ];
    }

    private static function filename(string $period, string $extension): string
    {
        return sprintf('zauctovani-mezd-%s.%s', $period, $extension);
    }

    private static function periodLabel(string $period): string
    {
        return substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    /** @param list<string> $headers */
    private function headers(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $this->text($sheet, $index + 1, $row, $header);
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $style = $sheet->getStyle("A{$row}:{$last}{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    }

    private function total(Worksheet $sheet, int $row, int $amountColumn, int $first, int $totalMinor): void
    {
        $this->text($sheet, 1, $row, sprintf('Celkem (%d řádků)', max(0, $row - $first)));
        $this->money($sheet, $amountColumn, $row, $totalMinor);
        $sheet->getStyle('A' . $row . ':' . Coordinate::stringFromColumnIndex($amountColumn) . $row)
            ->getFont()->setBold(true);
    }

    private function money(Worksheet $sheet, int $column, int $row, int $minor): void
    {
        $sheet->setCellValueExplicit([$column, $row], $minor / 100, DataType::TYPE_NUMERIC);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($column) . $row)
            ->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }

    private function number(Worksheet $sheet, int $column, int $row, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        $sheet->setCellValueExplicit([$column, $row], (float) $value, DataType::TYPE_NUMERIC);
    }

    private function text(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
        if (PayrollInputExportFormatter::isFormulaLike($value)) {
            $sheet->getStyle(Coordinate::stringFromColumnIndex($column) . $row)->setQuotePrefix(true);
        }
    }
}
