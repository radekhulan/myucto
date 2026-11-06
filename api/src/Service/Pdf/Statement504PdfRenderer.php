<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Accounting\Reports\Statement504Presentation;

final class Statement504PdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $view = Statement504Presentation::build($data, $data['statement_type'] === 'account_view' ? ($data['unit'] ?? 'czk') : 'thousands', $data['part'] ?? 'balance');
        $mpdf = $this->mpdf(['format' => 'A4', 'orientation' => 'P', 'margin_left' => 12, 'margin_right' => 12,
            'margin_bottom' => 20, 'shrink_tables_to_fit' => 1]);
        $mpdf->SetTitle($view['title'] . ' k ' . $view['date_label']);
        $this->withPageNumbers($mpdf, $view['title']);
        $mpdf->WriteHTML($this->renderTemplate('statement_504.twig', $view));
        return $mpdf->Output('', 'S');
    }
}
