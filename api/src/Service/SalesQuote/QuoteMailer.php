<?php

declare(strict_types=1);

namespace MyInvoice\Service\SalesQuote;

use MyInvoice\Service\Mail\MailDeliveredArchiveException;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Mail\SupplierEmailContext;

final class QuoteMailer
{
    public const TEMPLATE = 'quote_send';

    public function __construct(
        private readonly Mailer $mailer,
        private readonly RecipientResolver $recipients,
        private readonly SupplierEmailContext $supplierContext,
    ) {}

    /** @return array{to:string,subject:string,message:string} */
    public function defaults(int $supplierId, array $quote): array
    {
        $client = $quote['client_snapshot'] ?? [];
        $resolved = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, [
            'client_id' => $quote['client_id'] ?? null,
            'client_main_email' => $client['main_email'] ?? $client['email'] ?? $quote['client_main_email'] ?? '',
            'project_id' => $quote['project_id'] ?? null,
            'supplier_id' => $supplierId,
        ], supplierCopy: false);

        $locale = $this->locale($quote);
        $message = $this->defaultMessage($quote);
        $vars = $this->templateVars($supplierId, $quote, $message);
        $resolvedTemplate = $this->mailer->templateResolver()->resolve(self::TEMPLATE, $locale, $supplierId);
        $rendered = $this->mailer->previewUserTemplate(self::TEMPLATE, $locale, [
            'subject' => $resolvedTemplate['subject'],
            'body_html' => '',
            'body_text' => '',
        ], $vars);
        $subject = html_entity_decode($rendered['subject'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $subject = mb_substr(trim((string) preg_replace('/[\r\n\x00]+/u', ' ', $subject)), 0, 255);

        return ['to' => $resolved['to'][0] ?? '', 'subject' => $subject, 'message' => $message];
    }

    /**
     * @param array{to:string,subject:string,message:string,attempt_id:int} $recipient
     * @param list<array{mode:string,path:string,name:string,mime_type:string}> $files
     * @return array{status:'sent'|'deferred',smtp_response:string,outbox_id:?int,imap_append:array}
     */
    public function send(int $supplierId, int $userId, array $quote, array $recipient, string $archivePath, array $files): array
    {
        $to = trim($recipient['to']);
        $subject = trim($recipient['subject']);
        $message = $recipient['message'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || mb_strlen($subject) > 255
            || preg_match('/[\r\n\x00]/', $subject) === 1 || mb_strlen($message) > 10_000
            || $recipient['attempt_id'] < 1) {
            throw new \InvalidArgumentException('Zkontrolujte e-mail příjemce, předmět a text zprávy.');
        }
        if (!is_file($archivePath) || !is_readable($archivePath)) {
            throw new \RuntimeException('Archivované PDF nabídky není dostupné.');
        }

        $fileNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($quote['number'] ?? ''));
        $filePrefix = $this->locale($quote) === 'en' ? 'Quote' : 'Nabidka';
        $name = $filePrefix . ($fileNumber ? '-' . $fileNumber : '') . '.pdf';
        $attachments = [['path' => $archivePath, 'name' => $name, 'contentType' => 'application/pdf']];
        foreach ($files as $file) {
            if ($file['mode'] !== 'email') {
                continue;
            }
            if (!is_file($file['path']) || !is_readable($file['path'])) {
                throw new \RuntimeException('Příloha nabídky není dostupná: ' . $file['name']);
            }
            $attachments[] = ['path' => $file['path'], 'name' => $file['name'], 'contentType' => $file['mime_type']];
        }

        $vars = $this->templateVars($supplierId, $quote, $message) + [
            'subject' => $subject,
            'client_subject' => $subject,
            'quote_delivery_attempt_id' => $recipient['attempt_id'],
        ];
        try {
            $result = $this->mailer->sendTemplateDetailed(
                self::TEMPLATE, $this->locale($quote), [$to], $vars,
                attachments: $attachments, userId: $userId, templateSupplierId: $supplierId,
            );
        } catch (MailDeliveredArchiveException $error) {
            return [
                'status' => 'sent',
                'smtp_response' => $error->smtpResponse(),
                'outbox_id' => null,
                'imap_append' => $error->imapAppend(),
            ];
        }

        return [
            'status' => !empty($result['deferred']) ? 'deferred' : 'sent',
            'smtp_response' => $result['smtp_response'],
            'outbox_id' => isset($result['outbox_id']) ? (int) $result['outbox_id'] : null,
            'imap_append' => $result['imap_append'],
        ];
    }

    private function templateVars(int $supplierId, array $quote, string $message): array
    {
        $supplier = $this->supplierContext->forSupplier($supplierId);
        if ($supplier === null || (isset($quote['supplier_id']) && (int) $quote['supplier_id'] !== $supplierId)) {
            throw new \InvalidArgumentException('Firma nabídky nebyla nalezena.');
        }
        return [
            'supplier' => $supplier,
            'quote_number' => (string) ($quote['number'] ?? ''),
            'quote_title' => (string) $quote['title'],
            'client_name' => (string) $quote['client_name'],
            'valid_until' => $quote['valid_until'] ?? null,
            'message' => $message,
        ];
    }

    private function locale(array $quote): string
    {
        return ($quote['language'] ?? '') === 'en' ? 'en' : 'cs';
    }

    private function defaultMessage(array $quote): string
    {
        if ($this->locale($quote) === 'en') {
            return "Hello,\n\nPlease find our quote attached.\n\nKind regards";
        }
        return "Dobrý den,\n\nv příloze Vám posíláme cenovou nabídku.\n\nS pozdravem";
    }
}
