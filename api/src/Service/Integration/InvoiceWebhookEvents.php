<?php

declare(strict_types=1);

namespace MyInvoice\Service\Integration;

use PDO;

final class InvoiceWebhookEvents
{
    public const ISSUED = 'invoice.issued';
    public const PAID = 'invoice.paid';
    public const PAYMENT_REVERSED = 'invoice.payment_reversed';
    public const PUBLISHED = 'invoice.published';
    public const CANCELLED = 'invoice.cancelled';

    public const TYPES = [self::ISSUED, self::PAID, self::PAYMENT_REVERSED, self::PUBLISHED, self::CANCELLED];
    public const PAYMENT = 'payment';
    public const ADVANCE = 'covered_by_advance';

    public static function publish(PDO $pdo, int $invoiceId, string $type, ?string $reason = null): void
    {
        $stmt = $pdo->prepare(
            "SELECT i.supplier_id, i.invoice_type, COALESCE(i.recurring_template_id, p.recurring_template_id) AS template_id
               FROM invoices i
          LEFT JOIN invoices p ON p.id = i.parent_invoice_id AND p.supplier_id = i.supplier_id
                    AND p.invoice_type = 'proforma' AND i.invoice_type = 'invoice'
              WHERE i.id = ?
                AND EXISTS (SELECT 1 FROM webhook_endpoints h WHERE h.supplier_id = i.supplier_id AND h.enabled = 1)"
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false || ($reason !== null && !in_array($invoice['invoice_type'], ['invoice', 'proforma'], true))) {
            return;
        }
        $templateId = $invoice['template_id'] === null ? null : (int) $invoice['template_id'];
        $data = ['invoice_id' => $invoiceId, 'invoice_type' => $invoice['invoice_type'], 'recurring_template_id' => $templateId];
        if ($reason !== null) {
            $data['reason'] = $reason;
        }
        WebhookEvents::enqueue($pdo, (int) $invoice['supplier_id'], $type, $data, $templateId, $reason === self::ADVANCE);
    }
}
