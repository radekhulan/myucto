<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use Mpdf\Mpdf;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Pdf\MpdfFontConfig;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Export ročního souhrnu knihy jízd (per vozidlo) do XLSX a PDF.
 */
final class LogbookSummaryExportService
{
    private const HEADERS = [
        'Auto', 'Jízd', 'Ujeto (km)', 'Služební (km)', 'Soukromé (km)', 'Soukr. %',
        'Tach. od', 'Tach. do', 'Palivo (l)', 'l/100km', 'Nabito (kWh)', 'kWh/100km', 'Náklad energie (Kč)',
        'Návaznost', 'Paušál (Kč)',
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array{vehicles:list<array<string,mixed>>, totals:array<string,mixed>} $data
     * @return array{bytes:string, filename:string, mime:string}
     */
    public function export(int $supplierId, string $format, int $year, array $data): array
    {
        $supplier = $this->supplierName($supplierId);
        $base = 'kniha-jizd-souhrn-' . $year;
        if ($format === 'pdf') {
            return ['bytes' => $this->pdf($data, $year, $supplier), 'filename' => $base . '.pdf', 'mime' => 'application/pdf'];
        }
        return ['bytes' => $this->xlsx($data, $year, $supplier),
                'filename' => $base . '.xlsx', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    private function xlsx(array $data, int $year, string $supplier): string
    {
        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Souhrn ' . $year);
        $sheet->setCellValue('A1', 'Kniha jízd — roční souhrn ' . $year);
        $sheet->setCellValueExplicit('A2', $supplier, DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $head = 4;
        foreach (self::HEADERS as $i => $h) $sheet->setCellValue([$i + 1, $head], $h);
        $sheet->getStyle("A{$head}:O{$head}")->getFont()->setBold(true);
        $sheet->getStyle("A{$head}:O{$head}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        $r = $head + 1;
        foreach ($data['vehicles'] as $v) {
            $this->xlsxRow($sheet, $r, $this->carLabel($v), $v);
            $r++;
        }
        // Totals
        $t = $data['totals'];
        $sheet->setCellValueExplicit("A{$r}", 'CELKEM', DataType::TYPE_STRING);
        $sheet->setCellValue("B{$r}", (int) $t['trips_count']);
        $sheet->setCellValue("C{$r}", (float) $t['km']);
        $sheet->setCellValue("D{$r}", (float) $t['business_km'] + (float) $t['uncategorized_km']);
        $sheet->setCellValue("E{$r}", (float) $t['private_km']);
        $sheet->setCellValue("F{$r}", (float) $t['private_ratio']);
        $sheet->setCellValue("I{$r}", (float) $t['liters']);
        $sheet->setCellValue("J{$r}", $t['avg_consumption'] !== null ? (float) $t['avg_consumption'] : '');
        $sheet->setCellValue("K{$r}", (float) ($t['kwh'] ?? 0));
        $sheet->setCellValue("L{$r}", isset($t['avg_consumption_kwh']) && $t['avg_consumption_kwh'] !== null ? (float) $t['avg_consumption_kwh'] : '');
        $sheet->setCellValue("M{$r}", (float) $t['fuel_cost']);
        $sheet->setCellValue("N{$r}", (int) $t['continuity_issues']);
        $sheet->getStyle("A{$r}:O{$r}")->getFont()->setBold(true);

        $sheet->getStyle("A{$head}:O{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (range('A', 'O') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
        $sheet->getStyle("B{$head}:O{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Poznámka
        $note = $r + 2;
        $sheet->setCellValue("A{$note}", 'Pozn.: paušál na dopravu (5000/4000 Kč/měs) je informativní srovnání, max 3 vozidla, vzájemně vylučuje skutečné výdaje. Spotřeba (l/100km i kWh/100km) je orientační (chybí-li u tankování/nabíjení množství). Souhrny jsou počítané z jízd a tankování/nabíjení za rok.');
        $sheet->getStyle("A{$note}")->getFont()->setSize(8)->setItalic(true);

        $tmp = tempnam(sys_get_temp_dir(), 'sumexp_') . '.xlsx';
        (new XlsxWriter($ss))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        $ss->disconnectWorksheets();
        return $bytes;
    }

    private function xlsxRow($sheet, int $r, string $car, array $v): void
    {
        $sheet->setCellValueExplicit("A{$r}", $car, DataType::TYPE_STRING);
        $sheet->setCellValue("B{$r}", (int) $v['trips_count']);
        $sheet->setCellValue("C{$r}", (float) $v['km']);
        $sheet->setCellValue("D{$r}", (float) $v['business_km'] + (float) $v['uncategorized_km']);
        $sheet->setCellValue("E{$r}", (float) $v['private_km']);
        $sheet->setCellValue("F{$r}", (float) $v['private_ratio']);
        $sheet->setCellValue("G{$r}", $v['odometer_start'] !== null ? (int) $v['odometer_start'] : '');
        $sheet->setCellValue("H{$r}", $v['odometer_end'] !== null ? (int) $v['odometer_end'] : '');
        $sheet->setCellValue("I{$r}", (float) $v['liters']);
        $sheet->setCellValue("J{$r}", $v['avg_consumption'] !== null ? (float) $v['avg_consumption'] : '');
        $sheet->setCellValue("K{$r}", (float) ($v['kwh'] ?? 0));
        $sheet->setCellValue("L{$r}", isset($v['avg_consumption_kwh']) && $v['avg_consumption_kwh'] !== null ? (float) $v['avg_consumption_kwh'] : '');
        $sheet->setCellValue("M{$r}", (float) $v['fuel_cost']);
        $sheet->setCellValue("N{$r}", (int) $v['continuity_issues'] === 0 ? 'OK' : (string) $v['continuity_issues']);
        $sheet->setCellValue("O{$r}", (int) $v['pausal_year']);
    }

    private function pdf(array $data, int $year, string $supplier): string
    {
        $tmpDir = RuntimePaths::storage('cache/mpdf');
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $mpdf = new Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L', 'orientation' => 'L',
            'margin_left' => 8, 'margin_right' => 8, 'margin_top' => 12, 'margin_bottom' => 12,
            'tempDir' => $tmpDir, 'autoPageBreak' => true, ...MpdfFontConfig::options(),
        ]);
        $mpdf->SetTitle('Kniha jízd — souhrn ' . $year);
        $mpdf->SetCreator('MyÚčto.cz');
        $mpdf->WriteHTML($this->pdfHtml($data, $year, $supplier));
        return $mpdf->Output('', 'S');
    }

    private function pdfHtml(array $data, int $year, string $supplier): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $css = '<style>
            body{font-family:montserrat,dejavusans;font-size:7.5pt;color:#222}
            h1{font-size:14pt;margin:0 0 2px 0}
            .sub{color:#666;font-size:9pt;margin:0 0 8px 0}
            table{width:100%;border-collapse:collapse}
            th{background:#eee;text-align:right;padding:2px 3px;border:0.5px solid #bbb;font-size:7pt}
            th.l,td.l{text-align:left}
            td{padding:2px 3px;border:0.5px solid #ccc;text-align:right}
            tr.tot td{font-weight:bold;border-top:1px solid #555}
            .note{color:#666;font-size:7.5pt;margin-top:8px;font-style:italic}
            .warn{color:#b45309}
        </style>';
        $head = '<h1>Kniha jízd — roční souhrn ' . $e($year) . '</h1><p class="sub">' . $e($supplier) . '</p>';

        $h = '<tr><th class="l">Auto</th><th>Jízd</th><th>Ujeto km</th><th>Služební</th><th>Soukromé</th><th>Soukr. %</th>'
           . '<th>Tach. od</th><th>Tach. do</th><th>Palivo l</th><th>l/100km</th><th>Nabito kWh</th><th>kWh/100km</th><th>Náklad energie</th><th>Návaznost</th><th>Paušál</th></tr>';

        $body = '';
        foreach ($data['vehicles'] as $v) {
            $body .= '<tr>'
                . '<td class="l">' . $e($this->carLabel($v)) . '</td>'
                . '<td>' . $e($v['trips_count']) . '</td>'
                . '<td>' . $e($this->n((float) $v['km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $v['business_km'] + (float) $v['uncategorized_km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $v['private_km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $v['private_ratio'], 1)) . ' %</td>'
                . '<td>' . ($v['odometer_start'] !== null ? $e($v['odometer_start']) : '—') . '</td>'
                . '<td>' . ($v['odometer_end'] !== null ? $e($v['odometer_end']) : '—') . '</td>'
                . '<td>' . ((float) $v['liters'] > 0 ? $e($this->n((float) $v['liters'], 2)) . ($v['liters_incomplete'] ? '<span class="warn">*</span>' : '') : '—') . '</td>'
                . '<td>' . ($v['avg_consumption'] !== null ? $e($this->n((float) $v['avg_consumption'], 1)) : '—') . '</td>'
                . '<td>' . ((float) ($v['kwh'] ?? 0) > 0 ? $e($this->n((float) $v['kwh'], 2)) . (!empty($v['kwh_incomplete']) ? '<span class="warn">*</span>' : '') : '—') . '</td>'
                . '<td>' . (isset($v['avg_consumption_kwh']) && $v['avg_consumption_kwh'] !== null ? $e($this->n((float) $v['avg_consumption_kwh'], 1)) : '—') . '</td>'
                . '<td>' . $e($this->n((float) $v['fuel_cost'], 2)) . '</td>'
                . '<td' . ((int) $v['continuity_issues'] > 0 ? ' class="warn"' : '') . '>' . ((int) $v['continuity_issues'] === 0 ? 'OK' : $e($v['continuity_issues'])) . '</td>'
                . '<td>' . $e($this->n((float) $v['pausal_year'], 0)) . '</td>'
                . '</tr>';
        }
        if ($data['vehicles'] === []) {
            $body = '<tr><td colspan="15" class="l" style="text-align:center;color:#888;padding:12px">Žádné jízdy v roce ' . $e($year) . '.</td></tr>';
        } else {
            $t = $data['totals'];
            $body .= '<tr class="tot">'
                . '<td class="l">CELKEM</td>'
                . '<td>' . $e($t['trips_count']) . '</td>'
                . '<td>' . $e($this->n((float) $t['km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $t['business_km'] + (float) $t['uncategorized_km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $t['private_km'], 1)) . '</td>'
                . '<td>' . $e($this->n((float) $t['private_ratio'], 1)) . ' %</td>'
                . '<td></td><td></td>'
                . '<td>' . $e($this->n((float) $t['liters'], 2)) . '</td>'
                . '<td>' . ($t['avg_consumption'] !== null ? $e($this->n((float) $t['avg_consumption'], 1)) : '—') . '</td>'
                . '<td>' . $e($this->n((float) ($t['kwh'] ?? 0), 2)) . '</td>'
                . '<td>' . (isset($t['avg_consumption_kwh']) && $t['avg_consumption_kwh'] !== null ? $e($this->n((float) $t['avg_consumption_kwh'], 1)) : '—') . '</td>'
                . '<td>' . $e($this->n((float) $t['fuel_cost'], 2)) . '</td>'
                . '<td>' . $e($t['continuity_issues']) . '</td>'
                . '<td></td>'
                . '</tr>';
        }

        $note = '<p class="note">Paušál na dopravu (5 000 / 4 000 Kč/měs) je informativní srovnání — max 3 vozidla, vzájemně se vylučuje se skutečnými výdaji. '
            . '„Návaznost" = počet nesouladů stavu tachometru mezi po sobě jdoucími jízdami (0 = v pořádku). '
            . 'Hvězdička u množství (l/kWh) = u některých tankování/nabíjení není známé množství, spotřeba je proto orientační.</p>';

        return $css . $head . '<table>' . $h . $body . '</table>' . $note;
    }

    private function carLabel(array $v): string
    {
        $reg = (string) ($v['registration'] ?? '');
        $name = (string) ($v['name'] ?? '');
        return $name !== '' ? $reg . ' — ' . $name : $reg;
    }

    private function n(float $v, int $dec): string
    {
        return number_format($v, $dec, ',', ' ');
    }

    private function supplierName(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return (string) ($stmt->fetchColumn() ?: '');
    }
}
