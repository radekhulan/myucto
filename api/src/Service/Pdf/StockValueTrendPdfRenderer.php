<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Stock\StockReportXlsxExporter;

/**
 * PDF sestavy „Vývoj hodnoty zásob“ (A4 na šířku). Částky v celých Kč (přesné jsou v XLSX);
 * víc než {@see PER_TABLE} dat se rozdělí do více tabulek pod sebou. Data = výstup StockValueTrendService::result().
 */
final class StockValueTrendPdfRenderer extends ReportPdfRendererBase
{
    public const PER_TABLE = 12;

    public function render(array $data): string
    {
        $parent = $data['parent'] ?? null;
        $round = static fn (string $v): int => (int) round((float) $v);
        $fmt = static fn (int $v): string => number_format($v, 0, ',', ' ');
        $tables = [];
        foreach (array_chunk($data['dates'], self::PER_TABLE) as $dates) {
            $rows = [];
            foreach ($data['groups'] as $g) {
                $rows[] = ['label' => StockReportXlsxExporter::valuationGroupLabel($g, $parent),
                    'cells' => array_map(static fn (string $d): string => $fmt($round($g['values'][$d])), $dates)];
            }
            $tables[] = [
                'heads' => $dates,
                'rows' => $rows,
                // Celkem je součet zaokrouhlených řádků, ať v tabulce sedí; bez řádků zaokrouhlený součet.
                'total' => array_map(static fn (string $d): string => $fmt($data['groups'] === []
                    ? $round($data['totals'][$d]['value_total'])
                    : array_sum(array_map(static fn (array $g): int => $round($g['values'][$d]), $data['groups']))), $dates),
            ];
        }
        $body = $this->renderTemplate('stock_value_trend.twig', [
            'parent' => $parent, 'tables' => $tables, 'has_groups' => $data['groups'] !== [],
            'group_note' => StockReportXlsxExporter::VALUATION_GROUPS_NOTE, 'per_table' => self::PER_TABLE,
        ]);
        $mpdf = $this->mpdf();
        $mpdf->SetTitle('Vývoj hodnoty zásob');
        $this->withPageNumbers($mpdf, 'Vývoj hodnoty zásob');
        ChunkedHtmlWriter::write($mpdf, $body);
        return $mpdf->Output('', 'S');
    }
}
