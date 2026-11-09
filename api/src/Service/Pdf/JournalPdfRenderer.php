<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF účetního deníku (A4 landscape — řádkový výpis s mnoha sloupci
 * MD/D/částka/popis, na šířku se vejde bez zalomení). Data = výstup
 * JournalExportService::build() (audit 2026-07). Číslování stran přes
 * {@see ReportPdfRendererBase::withPageNumbers()} (§13 ZoÚ — deník je zákonná kniha).
 * Jednoduchý zápis (`layout = 'pairs'`) má vlastní šablonu s řádkem na dvojici; deník
 * firmy bez jednoduchého zápisu se sází beze změny.
 */
final class JournalPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate(($data['layout'] ?? null) === 'pairs' ? 'journal_pairs.twig' : 'journal.twig', $data);
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'L']);
        $mpdf->SetTitle('Účetní deník');
        $this->withPageNumbers($mpdf, 'Účetní deník');
        // Velké firmy mívají desítky tisíc zápisů/rok — jedno WriteHTML() na celý
        // dokument naráží na pcre.backtrack_limit a jedna tabulka na paměť, proto po
        // kusech (viz ChunkedHtmlWriter); zápis se svými řádky zůstane v jednom kusu.
        ChunkedHtmlWriter::write($mpdf, $body, 400, '/^<tr class="entry-head"/');
        return $mpdf->Output('', 'S');
    }
}
