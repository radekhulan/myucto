<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF rozvahy (A4 portrait).
 *
 * Data = výstup FinancialStatementService::balanceSheet() (spec F2 §2.7) —
 * hlavička s náležitostmi §18 ZoÚ, aktiva Brutto|Korekce|Netto|Minulé,
 * pasiva Běžné|Minulé, kontrola bilanční rovnosti.
 *
 * PDF je oficiální výkaz → hodnoty VŽDY v celých tisících Kč (§4 odst. 3
 * vyhl. 500/2002 Sb., Epic F4 R17): zaokrouhlení per řádek nezávisle, součtové
 * řádky se počítají z Kč a pak zaokrouhlí (možný rozdíl ±1 tis. — poznámka
 * pod výkazem). JSON API i obrazovka zůstávají v Kč.
 */
final class BalanceSheetPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('balance_sheet.twig', $this->toThousands($data));
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle('Rozvaha k ' . (string) ($data['as_of'] ?? ''));
        $this->withPageNumbers($mpdf, 'Rozvaha');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function toThousands(array $data): array
    {
        foreach ($data['assets'] ?? [] as $i => $row) {
            foreach (['gross', 'correction', 'net', 'prev_net'] as $k) {
                $data['assets'][$i][$k] = (int) round(((float) ($row[$k] ?? 0)) / 1000);
            }
        }
        foreach ($data['liabilities'] ?? [] as $i => $row) {
            foreach (['amount', 'prev_amount'] as $k) {
                $data['liabilities'][$i][$k] = (int) round(((float) ($row[$k] ?? 0)) / 1000);
            }
        }
        foreach (['assets_net', 'liabilities_total'] as $k) {
            if (isset($data['checks'][$k])) {
                $data['checks'][$k] = (int) round(((float) $data['checks'][$k]) / 1000);
            }
        }
        $data['unit'] = 'thousands';
        return $data;
    }
}
