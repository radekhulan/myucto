<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use Mpdf\HTMLParserMode;

/**
 * Účty jdou plynule za sebou jako v Money S3 a Premieru. Pokračuje-li účet na další
 * straně, hlavička strany nese jeho název a hlavičku sloupců; tabulky se sází po dávkách.
 */
final class GeneralLedgerDetailPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        // Na výšku jako v Money S3 a Premieru: sloupce rozpisu se vejdou a stran je méně než na šířku.
        $mpdf = $this->mpdf(['margin_top' => 37, 'format' => 'A4', 'orientation' => 'P']);
        $mpdf->setAutoTopMargin = 'stretch';
        $mpdf->SetTitle('Hlavní kniha včetně dokladů');
        $this->withPageNumbers($mpdf, 'Hlavní kniha včetně dokladů');
        $mpdf->WriteHTML($this->renderTemplate('general_ledger_detail_style.twig', []), HTMLParserMode::HEADER_CSS);
        // Hlavička se změní až od další strany: během účtu nese jeho pokračování, mezi účty jen sestavu.
        $plainHeader = $this->renderTemplate('general_ledger_detail_header.twig', $data + ['account' => null]);
        $mpdf->SetHTMLHeader($plainHeader);
        $first = true;
        $items = [];
        $offset = 0;
        $account = null;
        foreach ($data['events'] as $event) {
            if ($event['kind'] === 'start') {
                $account = $event['account'];
                $first = false;
                $offset = 0;
                $mpdf->WriteHTML($this->renderTemplate('general_ledger_detail_opening.twig', ['account' => $account]));
                $mpdf->SetHTMLHeader($this->renderTemplate('general_ledger_detail_header.twig', $data + ['account' => $account]));
            } elseif ($event['kind'] === 'line') {
                if (count($items) >= 100) {
                    $mpdf->WriteHTML($this->renderTemplate('general_ledger_detail_rows.twig', ['items' => $items, 'account' => $account, 'offset' => $offset]));
                    $offset += count($items);
                    $items = [];
                }
                $items[] = $event['item'];
            } else {
                $mpdf->WriteHTML($this->renderTemplate('general_ledger_detail_rows.twig', ['items' => $items, 'account' => $account, 'offset' => $offset, 'last' => true]));
                $mpdf->SetHTMLHeader($plainHeader);
                $items = [];
            }
        }
        if ($first) $mpdf->WriteHTML('<p>Ve zvoleném rozsahu nejsou žádné účty s počátečním stavem nebo pohybem.</p>');
        return $mpdf->Output('', 'S');
    }
}
