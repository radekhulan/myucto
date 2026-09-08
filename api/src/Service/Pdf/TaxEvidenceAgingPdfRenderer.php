<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\TaxEvidence\ReceivablesPayablesService;

/**
 * Renderer PDF pohledávek a závazků daňové evidence (A4 portrait, Epic DE A3).
 *
 * Data = výstup ReceivablesPayablesService::build() (ploché řádky per měna+kbelík).
 * Renderer je před předáním do šablony seskupí po měně (nativní částky, bez CZK
 * přepočtu, R13) a doplní kanonické pořadí kbelíků + KPI (DSO/DPO/punktualita).
 */
final class TaxEvidenceAgingPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $view = [
            'currencies'   => $this->groupByCurrency($data),
            'kpis'         => $data['kpis'] ?? [],
            'bucket_order' => ReceivablesPayablesService::BUCKET_ORDER,
        ];
        $body = $this->renderTemplate('receivables_payables.twig', $view);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P']);
        $mpdf->SetTitle('Pohledávky a závazky');
        $this->withPageNumbers($mpdf, 'Pohledávky a závazky');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string, array{receivables: array<string,array{count:int,total:float}>, payables: array<string,array{count:int,total:float}>}>
     */
    private function groupByCurrency(array $data): array
    {
        $blocks = [];
        foreach (['receivables', 'payables'] as $side) {
            foreach (($data[$side] ?? []) as $row) {
                $cur = (string) $row['currency'];
                if (!isset($blocks[$cur])) {
                    $blocks[$cur] = ['receivables' => [], 'payables' => []];
                }
                $blocks[$cur][$side][(string) $row['bucket']] = [
                    'count' => (int) $row['count'],
                    'total' => (float) $row['total'],
                ];
            }
        }
        ksort($blocks);
        return $blocks;
    }
}
