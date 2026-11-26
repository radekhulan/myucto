<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Integration\InvoiceWebhookEvents;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;
use MyInvoice\Service\Validation\InvoiceAmountPolicy;

/**
 * Vrácení vydaného dokladu ze stavu „uhrazeno" (SSOT pro „Vrátit na neuhrazený"
 * {@see \MyInvoice\Action\Invoice\UnmarkPaidAction} i opravu dokladů uhrazených bez
 * úhrady `api/bin/repair-paid-without-payment.php`).
 *
 * Volá se až po úpravě plateb (smazání nebo přepočet `paid_total`) a mimo transakci
 * volajícího: stav vrátí na `sent`/`issued` a vyčistí `paid_at`, zahodí PDF s razítkem
 * UHRAZENO, zapíše `invoice.unmark_paid` do auditu a přepočte statistiky. Stav reminded se
 * nezachovává, upomínku jde poslat znovu.
 */
final class InvoiceUnmarkPaid
{
    public const AUDIT_ACTION = 'invoice.unmark_paid';

    public function __construct(
        private readonly InvoicePaymentService $payments,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly StatsRecomputer $stats,
    ) {}

    /**
     * @param array<string,mixed> $invoice řádek dokladu před změnou (id, supplier_id, invoice_type,
     *                                     amount_to_pay, paid_at a pole pro {@see InvoiceAmountPolicy})
     * @param array<string,mixed> $audit   doplňující údaje auditního záznamu
     * @param bool $publishWebhook false = webhook o vrácení úhrady se nevydá (hromadná oprava dat,
     *                             která by integracím poslala stovky falešných událostí)
     */
    public function revert(array $invoice, ?int $userId, array $audit = [], ?string $ip = null, ?string $userAgent = null, bool $publishWebhook = true): void
    {
        $id = (int) $invoice['id'];
        // Finální doklad krytý zálohou (amount_to_pay <= 0, žádné platby) přepočet plateb
        // nerevertuje, vrací ho až tenhle krok.
        $reason = match (true) {
            !$publishWebhook => null,
            InvoiceAmountPolicy::shouldAutoMarkPaidOnIssue($invoice) => InvoiceWebhookEvents::ADVANCE,
            (float) $invoice['amount_to_pay'] > 0 => InvoiceWebhookEvents::PAYMENT,
            default => null,
        };
        $this->payments->setSettledStatus($id, (int) $invoice['supplier_id'], null, $reason);

        $previousPaidAt = (string) ($invoice['paid_at'] ?? '');
        $this->logger->log(self::AUDIT_ACTION, $userId, 'invoice', $id, [
            'previous_paid_at' => $previousPaidAt ?: null,
        ] + $audit, $ip, $userAgent);

        // Vedlejší přepočty po zápisu: jejich chyba nesmí změnit výsledek provedeného vrácení.
        // Cached PDF nese „UHRAZENO" stamp — bez invalidace by se po unmark vracel.
        try {
            $this->pdf->invalidate($id, 'invalidate_unmark_paid');
        } catch (\Throwable $e) {
            error_log('Invalidace PDF faktury #' . $id . ' po vrácení úhrady selhala: ' . $e->getMessage());
        }
        try {
            $this->stats->recomputeForInvoiceId($id);
        } catch (\Throwable $e) {
            error_log('Přepočet statistik faktury #' . $id . ' po vrácení úhrady selhal: ' . $e->getMessage());
        }
    }
}
