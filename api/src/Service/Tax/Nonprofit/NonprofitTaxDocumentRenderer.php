<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Nonprofit;

use MyInvoice\Service\Pdf\ReportPdfRendererBase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class NonprofitTaxDocumentRenderer extends ReportPdfRendererBase
{
    public function tables(array $payload): array
    {
        $revision = $payload['revision'];
        $result = $revision['result'];
        foreach (['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as $key) {
            if (!is_int($result[$key] ?? null)) throw new \UnexpectedValueException('Missing exact financial total: ' . $key);
        }
        if ($result['accounting_result_cents'] - $result['excluded_revenue_cents'] + $result['cost_addbacks_cents'] !== $result['assessed_result_cents']) {
            throw new \UnexpectedValueException('Assessment bridge does not reconcile.');
        }
        if (($payload['dppo_supported'] ?? null) !== false) throw new \UnexpectedValueException('Unsupported assessment export scope.');
        $bridge = [
            ['Účetní výsledek před daní', self::money($result['accounting_result_cents'])],
            ['Vyloučené příjmy (odečíst)', self::money($result['excluded_revenue_cents'])],
            ['Přičitatelná část nákladů', self::money($result['cost_addbacks_cents'])],
            ['Výsledek klasifikačního posouzení', self::money($result['assessed_result_cents'])],
        ];
        $meta = [
            ['Rozsah', 'Podklad pro daňové přiznání před obecnými úpravami základu daně. Nejde o dokončené DPPO.'],
            ['Firma / období', $revision['supplier_id'] . ' / ' . $revision['snapshot']['period']['starts_on'] . ' - ' . $revision['snapshot']['period']['ends_on']],
            ['Posouzení / revize', $revision['id'] . ' / ' . $revision['revision_no']],
            ['Stav revize', $revision['status'] === 'confirmed' ? 'Potvrzená' : 'Odvolaná'],
            ['Aktuálnost při exportu', $payload['current_confirmed'] ? 'Aktuální potvrzené posouzení' : 'Historická nebo neaktuální revize'],
            ['DPPO podporováno', 'Ne'],
            ['Potvrzení uživatelem / čas', $revision['created_by'] . ' / ' . $revision['created_at']],
            ['Důvod', $revision['reason']],
            ['Postavení poplatníka', ['narrow_vpp' => 'Veřejně prospěšný poplatník s úzkým základem daně', 'broad_vpp' => 'Veřejně prospěšný poplatník s širokým základem daně', 'non_vpp' => 'Mimo režim veřejně prospěšného poplatníka',
                'documented' => 'Uživatelem doložené postavení pro dané období'][$revision['assessment']['profile']['kind']]],
            ['Doložení postavení', $revision['assessment']['profile']['evidence']],
            ['Metodika', $revision['ruleset']],
            ['Otisk podkladů revize', $revision['input_hash']],
            ['Otisk aktuálních podkladů', $payload['current_check']['input_hash'] ?? 'Nedostupný'],
            ['Kontrola aktuálních podkladů', $payload['current_check']['complete'] ? 'Bez zjištěných nedostatků' : implode('; ', array_map(static fn (array $finding): string => $finding['message'] ?? match ($finding['code']) {
                'assessment_stale' => 'Potvrzení neodpovídá současným podkladům', 'assessment_withdrawn' => 'Posouzení bylo odvoláno', 'assessment_missing' => 'Chybí potvrzené posouzení', 'mixed_framework' => 'Smíšené účetní rámce', default => $finding['code'],
            }, $payload['current_check']['findings']))],
        ];
        if (($result['assessment_method'] ?? null) === 'documented_manual') {
            $method = $revision['assessment']['method'];
            $meta[] = ['Způsob posouzení', 'Uživatelem doložený podklad bez automatického ověření daňového režimu'];
            $meta[] = ['Právní důvod ruční metody', $method['legal_basis']];
            $meta[] = ['Účinnost ruční metody', $method['valid_from'] . ' - ' . $method['valid_to']];
            $meta[] = ['Podklad ruční metody', $method['evidence']];
        }
        $rows = [];
        $evidence = [];
        foreach ($result['rows'] as $row) {
            foreach (['amount_cents', 'deductible_cents', 'adjustment_cents'] as $key) {
                if (!is_int($row[$key] ?? null)) throw new \UnexpectedValueException('Missing exact source amount: ' . $key);
            }
            $rows[] = [$row['id'], $row['account_code'], $row['date'], $row['axes']['activity_kind']['name'] ?? (string) $row['activity_kind_id'],
                self::regime($row['classification']['regime']), self::money($row['amount_cents']), self::money($row['deductible_cents']), self::money($row['adjustment_cents'])];
            $evidence[] = [$row['id'], $row['decision']['evidence'], $row['decision']['legal_basis'] ?? $row['classification']['legal_basis'],
                'Původní řádek ' . ($row['origin']['line_id'] ?? $row['line_id'])];
            foreach ($row['decision']['facts'] ?? [] as $key => $fact) {
                if (is_scalar($fact)) $evidence[] = [$row['id'], self::factLabel($key), is_bool($fact) ? ($fact ? 'Ano' : 'Ne') : (string) $fact, ''];
            }
        }
        $activities = [];
        foreach ($result['activities'] as $activity) $activities[] = [(string) $activity['activity_kind_id'], $activity['cohort'], self::regime($activity['regime']),
            self::money($activity['revenue_cents']), self::money($activity['cost_cents']), self::money($activity['contribution_cents'])];
        foreach ($revision['assessment']['activities'] as $kind => $activity) {
            $evidence[] = ['Činnost ' . $kind, $activity['evidence'], '', ''];
            foreach ($activity['cohorts'] ?? [] as $cohort => $proof) $evidence[] = ['Cenová kohorta ' . $cohort, $proof, '', ''];
        }
        $funding = [];
        foreach ($result['funding'] as $link) $funding[] = [$link['source_id'], $link['cost_id'], self::money($link['amount_cents']), $link['evidence'],
            isset($link['deductible_funded_cents']) ? self::money($link['deductible_funded_cents']) : ''];
        foreach (['funding_sources', 'funding_costs'] as $key) foreach ($revision['assessment'][$key] ?? [] as $pool) {
            $evidence[] = [$pool['anchor'], $pool['evidence'], 'Členové financování', implode(', ', $pool['members'])];
        }
        $usage = [];
        foreach ($result['funding_usage'] as $pool) $usage[] = [$pool['anchor'], implode(', ', $pool['members']), self::money($pool['net_source_cents']),
            self::money($pool['prior_drawdown_cents']), self::money($pool['drawdown_cents']), self::money($pool['remaining_cents'])];
        $external = [];
        foreach ($result['external_adjustments'] as $item) $external[] = [$item['funding_anchor'], self::money($item['adjustment_cents']), $item['evidence'], $item['legal_basis'], 'Nezahrnuto do klasifikačního výsledku'];
        return [
            'Posouzení' => [['Údaj', 'Hodnota'], $meta], 'Přechod výsledku' => [['Položka', 'Kč'], $bridge],
            'Druhy činnosti' => [['Činnost', 'Cenová kohorta', 'Režim', 'Příjmy Kč', 'Náklady Kč', 'Příspěvek Kč'], $activities],
            'Zdrojové řádky' => [['Řádek', 'Účet', 'Datum', 'Činnost', 'Režim', 'Částka Kč', 'Uznatelné Kč', 'Úprava Kč'], $rows],
            'Důkazy' => [['Předmět', 'Podklad', 'Právní důvod / skutečnost', 'Původ'], $evidence],
            'Vazby financování' => [['Zdroj', 'Náklad', 'Čerpání Kč', 'Podklad', 'Doložený uznatelný překryv Kč'], $funding],
            'Čerpání zdrojů' => [['Zdroj', 'Členové', 'Čistý zdroj Kč', 'Dřívější čerpání Kč', 'Čerpání období Kč', 'Zůstatek Kč'], $usage],
            'Oddělené úpravy' => [['Zdroj', 'Doložená částka Kč', 'Podklad', 'Právní důvod', 'Vztah k výsledku'], $external],
        ];
    }

    public function render(array $data): string
    {
        $html = '<style>body{font-size:9pt;color:#172b4d}h1{font-size:17pt}h2{font-size:12pt;margin-top:16pt}.assessment{border-collapse:collapse;width:100%;table-layout:fixed}.assessment th,.assessment td{border:1px solid #ccd4df;padding:5pt;overflow-wrap:break-word}.assessment th{background:#edf2f8;text-align:left}.assessment td{vertical-align:top}</style><h1>Podklad daňového posouzení</h1>';
        foreach ($this->tables($data) as $title => [$headers, $rows]) {
            $html .= '<h2>' . self::escape($title) . '</h2><table class="assessment"' . ($title === 'Přechod výsledku' ? ' style="page-break-inside:avoid"' : '') . '><thead><tr>';
            foreach ($headers as $header) $html .= '<th>' . self::escape($header) . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) $html .= '<td>' . self::escape($cell) . '</td>';
                $html .= '</tr>';
            }
            if ($rows === []) $html .= '<tr><td colspan="' . count($headers) . '">Bez položek</td></tr>';
            $html .= '</tbody></table>';
        }
        $pdf = $this->mpdf();
        $pdf->SetTitle('Podklad daňového posouzení');
        $this->withPageNumbers($pdf, 'Daňové posouzení, revize ' . $data['revision']['revision_no']);
        $pdf->WriteHTML($html);
        return $pdf->Output('', 'S');
    }

    public function xlsx(array $data): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($this->tables($data) as $title => [$headers, $rows]) {
            $sheet = $book->createSheet()->setTitle($title);
            foreach ([$headers, ...$rows] as $r => $row) foreach ($row as $c => $value) {
                $numeric = $r > 0 && str_ends_with($headers[$c], 'Kč') && preg_match('/^-?\d+\.\d{2}$/D', (string) $value)
                    && strlen(str_replace(['-', '.'], '', (string) $value)) <= 15;
                $sheet->setCellValueExplicit([$c + 1, $r + 1], (string) $value, $numeric ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                if ($numeric) $sheet->getStyle([$c + 1, $r + 1])->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $sheet->freezePane('A2');
            $sheet->setShowGridlines(false);
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getFont()->setName('Arial')->setSize(10);
            foreach (range(1, count($headers)) as $col) $sheet->getColumnDimensionByColumn($col)->setWidth($col === 2 ? 52 : 28);
            foreach ([$headers, ...$rows] as $r => $row) {
                $lines = 1;
                foreach ($row as $c => $value) $lines = max($lines, (int) ceil(mb_strlen((string) $value) / ($c === 1 ? 48 : 24)));
                $sheet->getRowDimension($r + 1)->setRowHeight(min(409, 14 * $lines + 8));
            }
            $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setWrapText(true);
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

    private static function regime(string $value): string
    {
        return ['subject' => 'Předmět daně', 'out_of_scope' => 'Mimo předmět daně', 'exempt' => 'Osvobozeno'][$value] ?? $value;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function factLabel(string $key): string
    {
        return ['claim_exemption' => 'Uplatněné osvobození daru', 'qualifying_purpose' => 'Splněný zákonný účel',
            'election_evidence' => 'Doložení volby osvobození', 'income_attribution_evidence' => 'Příslušnost příjmu k činnosti',
            'income_treatment' => 'Posouzený druh příjmu', 'qualifying_membership' => 'Splněné podmínky členského příspěvku',
            'public_budget' => 'Veřejný rozpočet', 'domestic_bank_account' => 'Účet u tuzemské banky',
            'foundation_endowment' => 'Nadační jistina', 'foundation_purpose_only' => 'Výhradně účel nadace',
            'gross_interest_cents' => 'Hrubý úrok v haléřích', 'withholding_cents' => 'Sražená daň v haléřích'][$key] ?? $key;
    }
}
