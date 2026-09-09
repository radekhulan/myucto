<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export knihy jízd (jízd) do XLSX a PDF za zvolené období (datum od/do, volitelně auto).
 * Seskupeno po vozidlech (každé vozidlo = samostatná evidence), se subtotály a Σ km.
 */
final class TripExportService
{
    private const HEADERS = ['Datum', 'Auto', 'Odkud', 'Kam', 'Účel cesty', 'Kategorie', 'Tachometr od', 'Tachometr do', 'Ujeto (km)'];

    public function __construct(
        private readonly TripRepository $trips,
        private readonly Connection $db,
    ) {}

    /** @return array{bytes:string, filename:string, mime:string} */
    public function export(int $supplierId, string $format, array $filters): array
    {
        $rows = $this->collect($supplierId, $filters);
        $period = $this->periodLabel($filters);
        $supplier = $this->supplierName($supplierId);
        $base = 'kniha-jizd' . ($period['file'] !== '' ? '-' . $period['file'] : '');

        if ($format === 'pdf') {
            return ['bytes' => $this->pdf($rows, $period['human'], $supplier),
                    'filename' => $base . '.pdf', 'mime' => 'application/pdf'];
        }
        return ['bytes' => $this->xlsx($rows, $period['human'], $supplier),
                'filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    /** @return list<array<string,mixed>> jízdy seřazené po autě a datu vzestupně */
    private function collect(int $supplierId, array $filters): array
    {
        $f = array_intersect_key($filters, array_flip(['car_id', 'date_from', 'date_to', 'category_id']));
        $rows = $this->trips->listForTenant($supplierId, $f);
        usort($rows, function ($a, $b) {
            $c = strcmp((string) ($a['car_registration'] ?? ''), (string) ($b['car_registration'] ?? ''));
            return $c !== 0 ? $c : strcmp((string) $a['trip_date'], (string) $b['trip_date']);
        });
        return $rows;
    }

    // ─── XLSX ────────────────────────────────────────────────────────────────
    private function xlsx(array $rows, string $period, string $supplier): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Kniha jízd');

        $sheet->setCellValue('A1', 'Kniha jízd');
        $sheet->setCellValueExplicit('A2', $supplier, DataType::TYPE_STRING);
        $sheet->setCellValue('A3', $period !== '' ? 'Období: ' . $period : '');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headRow = 5;
        foreach (self::HEADERS as $i => $h) {
            $sheet->setCellValue([$i + 1, $headRow], $h); // [colIndex(1-based), row]
        }
        $sheet->getStyle("A{$headRow}:I{$headRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headRow}:I{$headRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        $r = $headRow + 1;
        $total = 0.0;
        $perCarTotal = 0.0; $currentCar = null; $carStartRow = $r;
        foreach ($rows as $t) {
            $car = (string) ($t['car_registration'] ?? '');
            if ($currentCar !== null && $car !== $currentCar) {
                $this->xlsxSubtotal($sheet, $r, $currentCar, $perCarTotal);
                $r++; $perCarTotal = 0.0;
            }
            $currentCar = $car;
            $sheet->setCellValue("A{$r}", $this->dateCell($t));
            $sheet->setCellValueExplicit("B{$r}", $car, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$r}", (string) ($t['origin'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$r}", (string) ($t['destination'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$r}", (string) ($t['purpose'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$r}", (string) ($t['category_label'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue("G{$r}", $t['odometer_start'] !== null ? (int) $t['odometer_start'] : '');
            $sheet->setCellValue("H{$r}", $t['odometer_end'] !== null ? (int) $t['odometer_end'] : '');
            $sheet->setCellValue("I{$r}", (float) $t['distance_km']);
            $total += (float) $t['distance_km'];
            $perCarTotal += (float) $t['distance_km'];
            $r++;
        }
        if ($currentCar !== null) {
            $this->xlsxSubtotal($sheet, $r, $currentCar, $perCarTotal);
            $r++;
        }
        // Celkový součet
        $sheet->setCellValue("H{$r}", 'CELKEM');
        $sheet->setCellValue("I{$r}", $total);
        $sheet->getStyle("H{$r}:I{$r}")->getFont()->setBold(true);

        // Borders + sloupce
        $sheet->getStyle("A{$headRow}:I" . ($r))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getStyle("G{$headRow}:I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $tmp = tempnam(sys_get_temp_dir(), 'kjexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $bytes;
    }

    private function xlsxSubtotal($sheet, int $r, string $car, float $sum): void
    {
        $sheet->setCellValueExplicit("F{$r}", 'Σ ' . $car, DataType::TYPE_STRING);
        $sheet->setCellValue("I{$r}", $sum);
        $sheet->getStyle("F{$r}:I{$r}")->getFont()->setItalic(true)->setBold(true);
        $sheet->getStyle("A{$r}:I{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F6F6F6');
    }

    // ─── PDF (mPDF) ──────────────────────────────────────────────────────────
    private function pdf(array $rows, string $period, string $supplier): string
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L', 'orientation' => 'L',
            'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 12, 'margin_bottom' => 12,
            'tempDir' => $tmpDir, 'autoPageBreak' => true, ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Kniha jízd' . ($period !== '' ? ' ' . $period : ''));
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->WriteHTML($this->pdfHtml($rows, $period, $supplier));
        return $mpdf->Output('', 'S');
    }

    private function pdfHtml(array $rows, string $period, string $supplier): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $css = '<style>
            body{font-family:montserrat,dejavusans;font-size:8.5pt;color:#222}
            h1{font-size:15pt;margin:0 0 2px 0}
            .sub{color:#666;font-size:9pt;margin:0 0 8px 0}
            table{width:100%;border-collapse:collapse}
            th{background:#eee;text-align:left;padding:3px 4px;border:0.5px solid #bbb;font-size:8pt}
            td{padding:2px 4px;border:0.5px solid #ccc}
            td.r,th.r{text-align:right}
            tr.sub td{background:#f5f5f5;font-style:italic;font-weight:bold}
            tr.tot td{font-weight:bold;border-top:1px solid #555}
        </style>';

        $head = '<h1>Kniha jízd</h1><p class="sub">' . $e($supplier)
            . ($period !== '' ? ' &nbsp;·&nbsp; Období: ' . $e($period) : '') . '</p>';

        $h = '<tr><th>Datum</th><th>Auto</th><th>Odkud</th><th>Kam</th><th>Účel cesty</th>'
           . '<th>Kategorie</th><th class="r">Tach. od</th><th class="r">Tach. do</th><th class="r">Ujeto (km)</th></tr>';

        $body = ''; $total = 0.0; $perCar = 0.0; $currentCar = null;
        foreach ($rows as $t) {
            $car = (string) ($t['car_registration'] ?? '');
            if ($currentCar !== null && $car !== $currentCar) {
                $body .= $this->pdfSubtotalRow($currentCar, $perCar);
                $perCar = 0.0;
            }
            $currentCar = $car;
            $body .= '<tr>'
                . '<td>' . $e($this->dateCell($t)) . '</td>'
                . '<td>' . $e($car) . '</td>'
                . '<td>' . $e($t['origin'] ?? '') . '</td>'
                . '<td>' . $e($t['destination'] ?? '') . '</td>'
                . '<td>' . $e($t['purpose'] ?? '') . '</td>'
                . '<td>' . $e($t['category_label'] ?? '') . '</td>'
                . '<td class="r">' . ($t['odometer_start'] !== null ? $e($t['odometer_start']) : '') . '</td>'
                . '<td class="r">' . ($t['odometer_end'] !== null ? $e($t['odometer_end']) : '') . '</td>'
                . '<td class="r">' . $e($this->km((float) $t['distance_km'])) . '</td>'
                . '</tr>';
            $total += (float) $t['distance_km'];
            $perCar += (float) $t['distance_km'];
        }
        if ($currentCar !== null) $body .= $this->pdfSubtotalRow($currentCar, $perCar);
        $body .= '<tr class="tot"><td colspan="8" class="r">CELKEM</td><td class="r">' . $e($this->km($total)) . '</td></tr>';

        if ($rows === []) {
            $body = '<tr><td colspan="9" style="text-align:center;color:#888;padding:12px">Žádné jízdy ve zvoleném období.</td></tr>';
        }

        return $css . $head . '<table>' . $h . $body . '</table>';
    }

    private function pdfSubtotalRow(string $car, float $sum): string
    {
        return '<tr class="sub"><td colspan="8" class="r">Σ ' . htmlspecialchars($car, ENT_QUOTES, 'UTF-8') . '</td>'
             . '<td class="r">' . htmlspecialchars($this->km($sum), ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }

    private function km(float $n): string { return number_format($n, 1, ',', ' '); }

    private function czDate(string $iso): string
    {
        try { return (new \DateTimeImmutable($iso))->format('d.m.Y'); }
        catch (\Throwable) { return $iso; }
    }

    /** Datum + případný čas odjezdu/příjezdu (jen pokud existuje). */
    private function dateCell(array $t): string
    {
        $s = $this->czDate((string) $t['trip_date']);
        $times = array_values(array_filter([
            (string) ($t['time_start'] ?? ''),
            (string) ($t['time_end'] ?? ''),
        ], static fn ($v) => $v !== ''));
        return $times !== [] ? $s . ' ' . implode('–', $times) : $s;
    }

    /** @return array{human:string, file:string} */
    private function periodLabel(array $filters): array
    {
        $from = trim((string) ($filters['date_from'] ?? ''));
        $to = trim((string) ($filters['date_to'] ?? ''));
        if ($from === '' && $to === '') return ['human' => '', 'file' => ''];
        $human = ($from !== '' ? $this->czDate($from) : '…') . ' – ' . ($to !== '' ? $this->czDate($to) : '…');
        $file = ($from !== '' ? $from : '') . ($to !== '' ? '_' . $to : '');
        return ['human' => $human, 'file' => $file];
    }

    private function supplierName(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
