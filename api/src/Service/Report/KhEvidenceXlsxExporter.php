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
 * XLSX soupisu dokladů oddílu KH (issue #142). Částky jsou čísla (ne text) s haléři,
 * součty jsou tytéž hodnoty jako na obrazovce a v PDF ({@see KontrolniHlaseniBuilder::sectionDocuments()}).
 */
final class KhEvidenceXlsxExporter
{
    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const MONEY = '#,##0.00';
    private const STATUS_LABELS = KhEvidenceService::STATUS_LABELS;

    /**
     * @param array<string,mixed> $report výstup KhEvidenceService
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function export(array $report, string $filename): array
    {
        $comparison = !empty($report['comparison']);
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Soupis KH');
        $sheet->setCellValue('A1', 'Soupis dokladů kontrolního hlášení');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $supplier = $report['supplier'] ?? [];
        $firm = (string) ($supplier['company_name'] ?? '')
            . (($supplier['ic'] ?? '') !== '' ? ', IČO ' . $supplier['ic'] : '')
            . (($supplier['dic'] ?? '') !== '' ? ', DIČ ' . $supplier['dic'] : '');
        $period = $report['period'] ?? [];
        $meta = [
            'Firma'     => $firm,
            'Období'    => (string) ($period['label'] ?? '') . ' (' . self::czDate($period['start'] ?? '') . ' - ' . self::czDate($period['end'] ?? '') . ')',
            'Filtr'     => KhEvidenceService::filterLabel((string) ($report['section'] ?? 'all')),
            'Zdroj'     => KhEvidenceService::sourceLabel($report),
            'Sestaveno' => self::czDateTime((string) ($report['generated_at'] ?? '')),
        ];
        $r = 2;
        foreach ($meta as $label => $value) {
            $sheet->setCellValueExplicit([1, $r], $label . ':', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([2, $r], $value, DataType::TYPE_STRING);
            $r++;
        }
        $r++;

        $headers = ['Číslo dokladu (v KH)', 'Interní číslo', 'Odběratel / dodavatel', 'DIČ', 'Rozhodné datum',
            'Základ zákl. sazba', 'DPH zákl. sazba', 'Základ sníž. sazba', 'DPH sníž. sazba', 'Základ celkem', 'DPH celkem'];
        if ($comparison) {
            array_unshift($headers, 'Stav');
        }
        $offset = $comparison ? 1 : 0;
        $cols = count($headers);
        $last = Coordinate::stringFromColumnIndex($cols);
        $firstMoney = 6 + $offset;

        foreach ($report['sections'] ?? [] as $key => $section) {
            $title = ($key === 'none' ? 'Mimo KH' : 'Oddíl ' . $key) . ' - ' . (KhEvidenceService::SECTION_LABELS[$key] ?? '');
            $sheet->setCellValueExplicit([1, $r], $title, DataType::TYPE_STRING);
            $sheet->getStyle([1, $r])->getFont()->setBold(true)->setSize(11);
            $r++;
            $head = $r;
            foreach ($headers as $i => $h) {
                $sheet->setCellValue([$i + 1, $r], $h);
            }
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
            $r++;
            foreach ($section['rows'] as $row) {
                if ($comparison) {
                    $sheet->setCellValueExplicit([1, $r], self::STATUS_LABELS[$row['status'] ?? ''] ?? (string) ($row['status'] ?? ''), DataType::TYPE_STRING);
                }
                $sheet->setCellValueExplicit([1 + $offset, $r], (string) $row['doc_number'] . (!empty($row['is_correction']) ? ' (oprava)' : ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([2 + $offset, $r], (string) ($row['internal_number'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([3 + $offset, $r], (string) ($row['counterparty_name'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([4 + $offset, $r], (string) ($row['counterparty_dic'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([5 + $offset, $r], self::czDate($row['tax_date'] ?? ''), DataType::TYPE_STRING);
                $this->money($sheet, $firstMoney, $r, $row);
                $r++;
            }
            $sheet->setCellValueExplicit([1, $r], 'Celkem ' . (int) $section['totals']['count'] . ' dokladů', DataType::TYPE_STRING);
            $this->money($sheet, $firstMoney, $r, $section['totals']);
            $sheet->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$head}:{$last}{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $r++;
            $sheet->setCellValueExplicit([1, $r], 'Zaokrouhleno na celé Kč (jako v přiznání k DPH)', DataType::TYPE_STRING);
            $sheet->setCellValue([$firstMoney + 4, $r], (float) $section['totals']['base_total_whole']);
            $sheet->setCellValue([$firstMoney + 5, $r], (float) $section['totals']['vat_total_whole']);
            $sheet->getStyle([$firstMoney + 4, $r, $firstMoney + 5, $r])->getNumberFormat()->setFormatCode(self::MONEY);
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
            if ((float) ($section['totals']['rounding_difference'] ?? 0) != 0.0) {
                $r++;
                $sheet->setCellValueExplicit([1, $r], 'Souhrnná věta oddílu se v KH počítá z nezaokrouhlených částek dokladů; proti součtu řádků rozdíl '
                    . number_format((float) $section['totals']['rounding_difference'], 2, ',', ' ') . ' Kč.', DataType::TYPE_STRING);
                $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
            }
            $r += 2;
        }

        foreach ($report['excluded'] ?? [] as $row) {
            $sheet->setCellValueExplicit([1, $r], 'Vyřazeno z KH (' . $row['section'] . '): ' . $row['doc_number'] . ' '
                . ($row['reason'] === 'missing_dic' ? 'chybí platné DIČ protistrany' : 'rozdílný režim plnění nebo příznak opravy'), DataType::TYPE_STRING);
            $r++;
        }
        foreach ($report['warnings'] ?? [] as $w) {
            $sheet->setCellValueExplicit([1, $r], (string) $w, DataType::TYPE_STRING);
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true);
            $r++;
        }

        for ($i = 1; $i <= $cols; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'khexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return ['bytes' => $bytes, 'filename' => $filename, 'mime' => self::MIME];
    }

    /** @param array<string,mixed> $values */
    private function money(Worksheet $sheet, int $col, int $row, array $values): void
    {
        foreach (['base21', 'vat21', 'base12', 'vat12', 'base_total', 'vat_total'] as $i => $k) {
            if (($values[$k] ?? null) === null) {
                continue;
            }
            $sheet->setCellValue([$col + $i, $row], (float) $values[$k]);
            $sheet->getStyle([$col + $i, $row])->getNumberFormat()->setFormatCode(self::MONEY);
        }
    }

    private static function czDate(mixed $v): string
    {
        if (!$v) return '';
        try {
            return (new \DateTimeImmutable((string) $v))->format('d.m.Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function czDateTime(string $v): string
    {
        if ($v === '') return '';
        try {
            return (new \DateTimeImmutable($v))->format('d.m.Y H:i');
        } catch (\Throwable) {
            return '';
        }
    }
}
