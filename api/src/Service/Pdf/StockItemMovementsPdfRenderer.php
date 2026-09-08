<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Renderer PDF skladové karty — pohyby (Epic SKLAD §7.2 ItemDetail export).
 * Data = {item: stock_items řádek, movements: výstup StockItemAction::movements()}.
 */
final class StockItemMovementsPdfRenderer extends ReportPdfRendererBase
{
    public function render(array $data): string
    {
        $body = $this->renderTemplate('stock_item_movements.twig', $data);
        $mpdf = $this->mpdf();
        $item = $data['item'] ?? [];
        $mpdf->SetTitle('Skladová karta ' . (string) ($item['sku'] ?? ''));
        $this->withPageNumbers($mpdf, 'Skladová karta');
        $mpdf->WriteHTML($body);
        return $mpdf->Output('', 'S');
    }
}
