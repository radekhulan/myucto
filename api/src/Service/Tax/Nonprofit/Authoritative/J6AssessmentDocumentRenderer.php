<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit\Authoritative;

use MyInvoice\Service\Pdf\ReportPdfRendererBase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class J6AssessmentDocumentRenderer extends ReportPdfRendererBase
{
    public function tables(array $payload): array
    {
        if (($payload['schema'] ?? null) !== 'myucto-nonprofit-tax-summary.v2'
            || ($payload['scope'] ?? null) !== 'classification_bridge_before_general_adjustments') {
            throw new \UnexpectedValueException('Unsupported paged J6 document payload.');
        }
        $assessment = $payload['assessment'] ?? null;
        $period = $payload['period'] ?? null;
        $profile = $payload['profile'] ?? null;
        $bridge = $payload['bridge'] ?? null;
        if (!is_array($assessment) || !is_array($period) || !is_array($profile) || !is_array($bridge)) {
            throw new \UnexpectedValueException('Paged J6 document summary is incomplete.');
        }
        foreach (['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $field) {
            if (!is_int($bridge[$field] ?? null)) throw new \UnexpectedValueException('Missing exact financial total: ' . $field);
        }
        if ($bridge['accounting_result_cents'] - $bridge['excluded_revenue_cents'] + $bridge['cost_addbacks_cents']
            !== $bridge['assessed_result_cents']) {
            throw new \UnexpectedValueException('Paged J6 assessment bridge does not reconcile.');
        }
        $profileName = ['narrow_vpp' => 'Veřejně prospěšný poplatník s úzkým základem daně',
            'broad_vpp' => 'Veřejně prospěšný poplatník s širokým základem daně',
            'non_vpp' => 'Mimo režim veřejně prospěšného poplatníka',
            'documented' => 'Uživatelem doložené postavení pro dané období'][$profile['kind'] ?? ''] ?? ($profile['kind'] ?? '');
        return [
            'Posouzení' => [['Údaj', 'Hodnota'], [
                ['Rozsah', 'Souhrn klasifikačního posouzení před obecnými úpravami základu daně.'],
                ['Firma / období', $assessment['supplier_id'] . ' / ' . $period['starts_on'] . ' - ' . $period['ends_on']],
                ['Posouzení / revize', $assessment['id'] . ' / ' . $assessment['revision_no']],
                ['Stav revize', $assessment['status'] === 'confirmed' ? 'Potvrzená' : 'Odvolaná'],
                ['Aktuálnost při exportu', $payload['current_confirmed'] ? 'Aktuální potvrzené posouzení' : 'Historická nebo neaktuální revize'],
                ['Potvrzení uživatelem / čas', $assessment['created_by'] . ' / ' . $assessment['created_at']],
                ['Důvod', $assessment['reason']], ['Postavení poplatníka', $profileName],
                ['Doložení postavení', (string) ($profile['evidence'] ?? '')], ['Metodika', $assessment['ruleset']],
                ['Otisk zdrojových podkladů', $assessment['source_input_hash']],
                ['Otisk zmrazeného podkladu', $assessment['snapshot_input_hash']],
                ['Otisk potvrzení', $assessment['receipt_hash']],
                ['Úplný důkaz', 'Stránkovaný NDJSON export ověřeného neměnného grafu'],
            ]],
            'Přechod výsledku' => [['Položka', 'Kč'], [
                ['Účetní výsledek před daní', self::money($bridge['accounting_result_cents'])],
                ['Vyloučené příjmy (odečíst)', self::money($bridge['excluded_revenue_cents'])],
                ['Přičitatelná část nákladů', self::money($bridge['cost_addbacks_cents'])],
                ['Výsledek klasifikačního posouzení', self::money($bridge['assessed_result_cents'])],
            ]],
        ];
    }

    public function render(array $payload): string
    {
        $html = '<style>body{font-size:9pt;color:#172b4d}h1{font-size:17pt}h2{font-size:12pt;margin-top:16pt}.assessment{border-collapse:collapse;width:100%;table-layout:fixed}.assessment th,.assessment td{border:1px solid #ccd4df;padding:5pt;overflow-wrap:break-word}.assessment th{background:#edf2f8;text-align:left}.assessment td{vertical-align:top}</style><h1>Souhrn daňového posouzení</h1>';
        foreach ($this->tables($payload) as $title => [$headers, $rows]) {
            $html .= '<h2>' . self::escape($title) . '</h2><table class="assessment"><thead><tr>';
            foreach ($headers as $header) $html .= '<th>' . self::escape($header) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) $html .= '<td>' . self::escape((string) $cell) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
        $pdf = $this->mpdf();
        $pdf->SetTitle('Souhrn daňového posouzení');
        $this->withPageNumbers($pdf, 'Daňové posouzení, revize ' . $payload['assessment']['revision_no']);
        $pdf->WriteHTML($html);
        return $pdf->Output('', 'S');
    }

    public function xlsx(array $payload): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($this->tables($payload) as $title => [$headers, $rows]) {
            $sheet = $book->createSheet()->setTitle($title);
            foreach ([$headers, ...$rows] as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    $numeric = $rowIndex > 0 && str_ends_with($headers[$columnIndex], 'Kč')
                        && preg_match('/^-?\d+\.\d{2}$/D', (string) $value) === 1;
                    $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], (string) $value,
                        $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                    if ($numeric) $sheet->getStyle([$columnIndex + 1, $rowIndex + 1])->getNumberFormat()->setFormatCode('#,##0.00');
                }
            }
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            $sheet->setShowGridlines(false);
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
            foreach (range(1, count($headers)) as $column) $sheet->getColumnDimensionByColumn($column)->setWidth($column === 2 ? 52 : 28);
        }
        $stream = fopen('php://temp', 'w+b');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    private static function money(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
