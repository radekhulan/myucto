<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;

/**
 * Odeslání děkovného e-mailu za úhradu faktury (issue #57).
 *
 * Volá se z MarkPaidAction (ruční/bulk) i z bankovního párování. Sama si ověří
 * podmínky (paid, ne-cancellation, příjemce, per-supplier zapnutí) a je
 * idempotentní (auto odeslání jen pokud `payment_thanks_sent_at IS NULL`).
 * V neukončené transakci ukládá pouze intent. Chyba uložení nebo úplný abort
 * transakce se propaguje; SMTP selhání po commitu vrací strukturovaný výsledek.
 */
final class PaymentThanksMailer
{
    public function __construct(
        private readonly Connection $db,
        private readonly InvoiceRepository $repo,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly InvoicePdfRenderer $renderer,
        private readonly ActivityLogger $logger,
        private readonly RecipientResolver $recipients,
    ) {}

    /**
     * @param 'manual'|'bulk'|'bank_match' $trigger
     * @return array{status:'sent'|'skipped'|'failed'|'queued', reason?:string, recipients?:list<string>}
     */
    public function sendForInvoice(
        int $invoiceId,
        string $trigger,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        bool $requireUnsent = false,
    ): array {
        $outbox = new PaymentThanksOutbox($this->db);
        if ($this->db->pdo()->inTransaction()) {
            return $outbox->enqueue($invoiceId, $trigger, $userId, $ip, $userAgent, $requireUnsent);
        }
        return $outbox->withInvoiceLock($invoiceId, fn (): array => $this->sendCommitted(
            $invoiceId, $trigger, $userId, $ip, $userAgent, $requireUnsent,
        )) ?? $outbox->enqueue($invoiceId, $trigger, $userId, $ip, $userAgent, $requireUnsent);
    }

    /** Chyby persistence fronty a zámků se propagují; nejde o best-effort consumer. */
    public function flushDeferred(?int $invoiceId = null, int $max = 25): array
    {
        return (new PaymentThanksOutbox($this->db, $this->logger))->flush(fn (array $intent): array => $this->sendCommitted(
            $intent['invoice_id'], $intent['trigger'], $intent['user_id'], $intent['ip'],
            $intent['user_agent'], $intent['require_unsent'],
        ), $invoiceId, $max);
    }

    private function sendCommitted(int $invoiceId, string $trigger, ?int $userId, ?string $ip, ?string $userAgent, bool $requireUnsent): array
    {
        $invoice = $this->repo->find($invoiceId);
        if (!$invoice) {
            return ['status' => 'skipped', 'reason' => 'not_found'];
        }
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $settings = $this->supplierSettings($supplierId);
        // Párování z banky volá poděkování u každé firmy; bez zapnuté funkce
        // nepatří přeskočení do aktivity faktury.
        if ($trigger === 'bank_match' && !$settings['enabled']) {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }
        if (($invoice['invoice_type'] ?? '') === 'cancellation') {
            return $this->logSkip($invoiceId, $invoice, 'cancellation', $trigger, $userId, $ip, $userAgent);
        }
        if (($invoice['status'] ?? '') !== 'paid') {
            return $this->logSkip($invoiceId, $invoice, 'not_paid', $trigger, $userId, $ip, $userAgent);
        }

        if (!$settings['enabled']) {
            return $this->logSkip($invoiceId, $invoice, 'disabled', $trigger, $userId, $ip, $userAgent);
        }
        if ($trigger === 'bank_match' && !$settings['auto_send']) {
            return $this->logSkip($invoiceId, $invoice, 'auto_disabled', $trigger, $userId, $ip, $userAgent);
        }

        // Idempotence — auto odeslání jen jednou (ruční „odeslat znovu" by byla
        // samostatná vědomá akce; mark-paid stejně nejde na už zaplacené faktuře).
        if ($requireUnsent && !empty($this->sentAt($invoiceId))) {
            return $this->logSkip($invoiceId, $invoice, 'already_sent', $trigger, $userId, $ip, $userAgent);
        }
        // Auto poděkování patří čerstvé platbě. `paid_at` nese datum platby z banky,
        // ne datum spárování — pozdě nahraný nebo znovu nahraný výpis by jinak
        // děkoval za týdny staré úhrady. Ruční odeslání zůstává vědomou volbou.
        if ($trigger === 'bank_match') {
            $paidOn = substr((string) ($invoice['paid_at'] ?? ''), 0, 10);
            $maxAge = $settings['max_age_days'];
            if ($paidOn !== '' && $maxAge > 0 && $paidOn < date('Y-m-d', strtotime("-{$maxAge} days"))) {
                return $this->logSkip($invoiceId, $invoice, 'stale_payment', $trigger, $userId, $ip, $userAgent);
            }
            $suppressedAt = $this->suppressedAt($invoiceId);
            if ($suppressedAt !== null && $paidOn !== '' && $paidOn <= substr($suppressedAt, 0, 10)) {
                return $this->logSkip($invoiceId, $invoice, 'suppressed_after_release', $trigger, $userId, $ip, $userAgent);
            }
        }

        // Jednotný resolver (#86) — poděkování se váže k dokladu, účel `documents`.
        // Bez kopie dodavateli (supplierCopy: false) — poděkování ji historicky
        // nemá a dodavatel o úhradě ví (sám ji označil / přišla z banky).
        $r = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, $invoice, supplierCopy: false);
        $to = $r['to'];
        $cc = $r['cc'];
        $bcc = $r['bcc'];
        if (empty($to)) {
            return $this->logSkip($invoiceId, $invoice, 'no_recipient', $trigger, $userId, $ip, $userAgent);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->buildVars($invoice, $locale);

        $attachments = [];
        if ($settings['attach_paid_pdf']) {
            try {
                $pdfPath = $this->renderer->render($invoiceId, false, $userId);
                $attachments[] = $this->varsBuilder->pdfAttachment($invoice, $pdfPath);
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e)) throw $e;
                // PDF příloha je volitelná — když selže, pošli e-mail bez ní.
                $attachments = [];
            }
        }

        try {
            $delivery = $this->mailer->sendTemplateDetailed('invoice_payment_thanks', $locale, $to, $vars, null, $cc, $bcc, $attachments, $userId, templateSupplierId: (int) $invoice['supplier_id']);
        } catch (MailDeliveredArchiveException $e) {
            $delivery = ['smtp_response' => $e->smtpResponse(), 'deferred' => false];
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            $this->logger->log('invoice.payment_thanks_failed', $userId, 'invoice', $invoiceId, [
                'varsymbol' => $invoice['varsymbol'] ?? null,
                'paid_at'   => $invoice['paid_at'] ?? null,
                'recipients'=> $to,
                'reason'    => $e->getMessage(),
                'trigger'   => $trigger,
            ], $ip, $userAgent);
            return ['status' => 'failed', 'reason' => $e->getMessage(), 'recipients' => $to];
        }

        $sentTo = mb_substr(implode(', ', $to), 0, 512);
        $this->db->pdo()
            ->prepare('UPDATE invoices SET payment_thanks_sent_at = NOW(), payment_thanks_sent_to = ? WHERE id = ?')
            ->execute([$sentTo, $invoiceId]);

        $queued = ($delivery['deferred'] ?? false) === true;
        $this->logger->log($queued ? 'invoice.payment_thanks_queued' : 'invoice.payment_thanks_sent', $userId, 'invoice', $invoiceId, [
            'varsymbol'     => $invoice['varsymbol'] ?? null,
            'paid_at'       => $invoice['paid_at'] ?? null,
            'recipients'    => $to,
            'trigger'       => $trigger,
            'smtp_response' => $delivery['smtp_response'],
        ], $ip, $userAgent);

        return $queued
            ? ['status' => 'queued', 'reason' => 'rate_limit', 'recipients' => $to]
            : ['status' => 'sent', 'recipients' => $to];
    }

    /** @return array{enabled:bool, auto_send:bool, attach_paid_pdf:bool, max_age_days:int} */
    private function supplierSettings(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return ['enabled' => false, 'auto_send' => false, 'attach_paid_pdf' => false, 'max_age_days' => 0];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT payment_thanks_enabled, payment_thanks_auto_send, payment_thanks_attach_paid_pdf, payment_thanks_max_age_days
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'enabled'         => (bool) ($row['payment_thanks_enabled'] ?? false),
            'auto_send'       => (bool) ($row['payment_thanks_auto_send'] ?? false),
            'attach_paid_pdf' => (bool) ($row['payment_thanks_attach_paid_pdf'] ?? false),
            'max_age_days'    => (int) ($row['payment_thanks_max_age_days'] ?? 0),
        ];
    }

    private function suppressedAt(int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payment_thanks_suppressed_at FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : ($v ?: null);
    }

    private function sentAt(int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT payment_thanks_sent_at FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : ($v ?: null);
    }

    /** @return array<string,mixed> */
    private function buildVars(array $invoice, string $locale): array
    {
        // Reuse stávajícího builderu pro `supplier` (patička + branding) a `invoice`.
        $vars = $this->varsBuilder->build($invoice, false, $locale);

        $varsymbol = (string) ($invoice['varsymbol'] ?? '');
        $supName = '';
        if (is_array($vars['supplier'] ?? null)) {
            $supName = (string) ($vars['supplier']['display_name'] ?: ($vars['supplier']['company_name'] ?? ''));
        }
        $subject = $locale === 'en'
            ? "Thank you for your payment — invoice {$varsymbol}"
            : "Děkujeme za úhradu faktury {$varsymbol}";
        if ($supName !== '') {
            $subject .= " — {$supName}";
        }

        $vars['subject']        = $subject;
        // Předmět podle klienta (#277) patří e-mailu s fakturou, ne poděkování.
        $vars['client_subject'] = null;
        $vars['paid_at']        = (string) ($invoice['paid_at'] ?? date('Y-m-d'));
        $vars['amount_paid']    = (float) ($invoice['amount_to_pay'] ?? $invoice['total_with_vat'] ?? 0);
        $vars['currency']       = (string) ($invoice['currency'] ?? 'CZK');
        $vars['payment_method'] = (string) ($invoice['payment_method'] ?? 'bank_transfer');
        $vars['is_proforma']    = ($invoice['invoice_type'] ?? '') === 'proforma';
        $vars['is_test']        = false;
        return $vars;
    }

    /** Stejná logika jako SendEmailAction::resolveRecipients (klient + fakturační e-maily zakázky). */
    /** @return array{status:'skipped', reason:string} */
    private function logSkip(int $invoiceId, array $invoice, string $reason, string $trigger, ?int $userId, ?string $ip, ?string $ua): array
    {
        $this->logger->log('invoice.payment_thanks_skipped', $userId, 'invoice', $invoiceId, [
            'varsymbol' => $invoice['varsymbol'] ?? null,
            'paid_at'   => $invoice['paid_at'] ?? null,
            'reason'    => $reason,
            'trigger'   => $trigger,
        ], $ip, $ua);
        return ['status' => 'skipped', 'reason' => $reason];
    }
}
