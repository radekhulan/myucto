<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF výkazu zisku a ztráty (A4 portrait).
 *
 * Data = výstup FinancialStatementService::incomeStatement() (spec F2 §2.7) —
 * hlavička s náležitostmi §18 ZoÚ, řádky Běžné|Minulé období, zvýrazněné
 * mezisoučty (computed/subtotal).
 *
 * PDF je oficiální výkaz → hodnoty VŽDY v celých tisících Kč (§4 odst. 3
 * vyhl. 500/2002 Sb., Epic F4 R17): zaokrouhlení per řádek nezávisle (možný
 * rozdíl ±1 tis. u součtů — poznámka pod výkazem). JSON API zůstává v Kč.
 */
final class IncomeStatementPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('income_statement.twig', $this->toThousands($data));
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle('Výkaz zisku a ztráty k ' . (string) ($data['as_of'] ?? ''));
        $this->withPageNumbers($mpdf, 'Výkaz zisku a ztráty');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function toThousands(array $data): array
    {
        foreach ($data['rows'] ?? [] as $i => $row) {
            foreach (['amount', 'prev_amount'] as $k) {
                $data['rows'][$i][$k] = (int) round(((float) ($row[$k] ?? 0)) / 1000);
            }
        }
        foreach (['profit_current', 'net_turnover'] as $k) {
            if (isset($data['checks'][$k])) {
                $data['checks'][$k] = (int) round(((float) $data['checks'][$k]) / 1000);
            }
        }
        $data['unit'] = 'thousands';
        return $data;
    }
}
