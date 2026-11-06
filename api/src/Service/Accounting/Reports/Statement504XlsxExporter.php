<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class Statement504XlsxExporter
{
    public function export(array $data, string $unit = 'czk', string $part = 'balance'): array
    {
        $view = Statement504Presentation::build($data, $unit, $part);
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($data['statement_type'] === 'balance_sheet' ? 'Rozvaha' : ($data['statement_type'] === 'account_view' ? 'Průběžný přehled' : 'Výsledovka'));
        $rows = [[$view['title']], [$view['legal'] . ' | ' . $view['scope_label'] . ' | ' . $view['unit_label']],
            ['Období: ' . $view['period_label'] . '; k ' . $view['date_label']],
            [(string) ($view['entity']['name'] ?? ''), (string) ($view['entity']['ico'] ?? ''), (string) ($view['entity']['address'] ?? '')],
            ['Okamžik sestavení: ' . (string) ($view['entity']['prepared_at'] ?? '')]];
        if ($view['management']) $rows[] = ['Pracovní přehled. Kreditní zůstatky jsou záporné.'];
        if ($unit === 'czk' && !$view['management']) $rows[] = ['Pracovní výkaz v Kč. Účetní závěrka se vykazuje v celých tisících.'];
        if (($view['checks']['balanced'] ?? true) === false) $rows[] = ['Upozornění: aktiva a pasiva se nerovnají.'];
        if (($view['checks']['unresolved_pending_result'] ?? 0) != 0) $rows[] = ['Nevypořádaný výsledek ve schvalovacím řízení (Kč)', $view['checks']['unresolved_pending_result']];
        if (($view['checks']['reconciliation_difference'] ?? 0) != 0) $rows[] = ['Rozdíl účetního odsouhlasení (Kč)', $view['checks']['reconciliation_difference']];
        if ($view['dimension'] !== null) $rows[] = ['Dimenze: ' . ($view['dimension']['label'] ?? '')];
        foreach ($view['tables'] as $table) {
            $rows[] = [$table['title']];
            $rows[] = $table['headers'];
            array_push($rows, ...$table['rows']);
        }
        if ($view['rounding_note'] !== null) $rows[] = [$view['rounding_note']];
        if (isset($view['deferred_tax_note'])) $rows[] = [$view['deferred_tax_note']];
        foreach ($rows as $r => $row) foreach ($row as $c => $value) {
            if ($value === null) continue;
            $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:E1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(70);
        foreach (['C','D','E'] as $column) $sheet->getColumnDimension($column)->setWidth(24);
        $sheet->getStyle('C5:E' . count($rows))->getNumberFormat()->setFormatCode($unit === 'thousands' ? '#,##0;[Red]-#,##0;0' : '#,##0.00;[Red]-#,##0.00;0.00');
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
        return ['bytes' => $bytes, 'filename' => 'vykaz-504-' . $data['statement_type'] . '-' . $data['as_of'] . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }
}
