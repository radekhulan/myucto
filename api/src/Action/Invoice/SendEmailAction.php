<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Mail\RecipientResolver;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PdfArchiveService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SendEmailAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PdfArchiveService $pdfArchive,
        private readonly InvoiceAttachmentRepository $attachments,
        private readonly RecipientResolver $recipients,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!in_array($invoice['status'], ['issued', 'sent', 'reminded', 'paid'], true)) {
            return Json::error($response, 'invalid_state', 'Lze poslat jen vystavenou fakturu.', 409);
        }
        if ($invoice['invoice_type'] === 'cancellation') {
            return Json::error($response, 'cannot_send_cancellation', 'Interní storno se klientovi neposílá.', 409);
        }
        if (!empty($invoice['calendar_id'])) {
            return Json::error($response, 'calendar_instalment_not_sendable', 'Doklad splátky je interní, odběrateli se posílá kalendář.', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $overrideTo = isset($body['to']) && is_array($body['to']) ? array_filter(array_map('trim', $body['to'])) : null;
        $cc = isset($body['cc']) && is_array($body['cc']) ? array_filter(array_map('trim', $body['cc'])) : [];
        $bcc = isset($body['bcc']) && is_array($body['bcc']) ? array_filter(array_map('trim', $body['bcc'])) : [];
        $subjectOverride = isset($body['subject_override']) ? (string) $body['subject_override'] : null;

        // Volitelná poznámka od uživatele přidaná do těla emailu (NE do PDF).
        // Plain text; v HTML šabloně se každý neprázdný řádek vyrenderuje jako <p>
        // s Twig autoescapem (žádný |raw → bez HTML injection). V TXT šabloně se
        // vloží jak je.
        $noteRaw = isset($body['note']) ? trim((string) $body['note']) : '';
        if ($noteRaw !== '' && mb_strlen($noteRaw) > 5000) {
            $noteRaw = mb_substr($noteRaw, 0, 5000);
        }
        $noteLines = [];
        if ($noteRaw !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $noteRaw) as $line) {
                $line = trim((string) $line);
                if ($line !== '') $noteLines[] = $line;
            }
        }

        // Jednotný resolver (#86): kontakty klienta dle účelu `documents`,
        // bez kontaktů legacy chování (main_email + e-maily zakázky), včetně
        // kopie dodavateli (supplier.self_copy / cfg). Když UI pošle explicitní
        // `to` (uživatel editoval v modalu), je autoritativní a nic z resolveru
        // se nepřidává — modal mu předvyplnil i cc/bcc s kopií dodavateli,
        // takže co poslal, je celý seznam, který viděl a schválil.
        $resolvedRecipients = [];
        if ($overrideTo !== null) {
            $to = $overrideTo;
        } else {
            $r = $this->recipients->resolve(RecipientResolver::TYPE_DOCUMENTS, $invoice);
            $to = $r['to'];
            $cc = array_values(array_unique(array_merge($r['cc'], $cc)));
            $bcc = array_values(array_unique(array_merge($r['bcc'], $bcc)));
            $resolvedRecipients = $r['resolved'];
        }
        if (empty($to)) {
            return Json::error($response, 'no_recipients', 'Žádný platný příjemce (chybí email klienta).', 400);
        }

        foreach ([...$to, ...$cc, ...$bcc] as $em) {
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
                return Json::error($response, 'invalid_email', "Neplatný email: $em", 400);
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : null;

        try {
            $pdfPath = $this->renderer->render($id, false, $userId);
        } catch (\Throwable $e) {
            return Json::internalError($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF: ' . $e->getMessage(), 500);
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, false, $locale);
        $vars['note_lines'] = $noteLines;
        $vars['note_text']  = $noteRaw;

        // Hlavní PDF + volitelné uživatelské přílohy (jen invoice/proforma/credit_note,
        // ne upomínky — tento send flow se použije jen tady).
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $emailAttachments = [
            $this->varsBuilder->pdfAttachment($invoice, $pdfPath),
        ];
        $extraAttachments = $this->attachments->listForInvoice($id);
        $sentAttachmentIds = [];
        foreach ($extraAttachments as $att) {
            $path = $this->attachments->pathFor($supplierId, $id, (string) $att['filename']);
            if (!is_file($path)) continue;
            $emailAttachments[] = [
                'path'        => $path,
                'name'        => (string) $att['original_name'],
                'contentType' => (string) $att['mime_type'],
            ];
            $sentAttachmentIds[] = (int) $att['id'];
        }

        $smtpResponse = '';
        try {
            $smtpResponse = $this->mailer->sendTemplate(
                'invoice_send',
                $locale,
                $to,
                $vars,
                $subjectOverride,
                $cc,
                $bcc,
                $emailAttachments,
                $userId,
                templateSupplierId: (int) $invoice['supplier_id'],
            );
        } catch (\Throwable $e) {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $this->logger->log('invoice.send_failed', $user['id'] ?? null, 'invoice', $id, [
                'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ], $ip, $request->getHeaderLine('User-Agent'));
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $newStatus = $invoice['status'] === 'issued' ? 'sent' : $invoice['status'];
        $this->db->pdo()->prepare('UPDATE invoices SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $id]);

        // Archivuj kopii PDF jako 'sent' verzi — důkaz toho, co klient skutečně dostal
        // (zachová se i kdyby se faktura později editovala). Aktivní cache zůstává nedotčená.
        $sentToAll = array_values(array_unique(array_merge($to, $cc, $bcc)));
        $archiveId = $this->pdfArchive->archiveCopy($id, $pdfPath, 'sent', wasSent: true, sentTo: $sentToAll);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.sent', $user['id'] ?? null, 'invoice', $id, [
            'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'resolved_recipients' => $resolvedRecipients,
            'pdf_path' => basename($pdfPath),
            'pdf_archive_id' => $archiveId,
            'attachment_ids' => $sentAttachmentIds,
            'smtp_response'  => $smtpResponse,
            'note_chars'     => mb_strlen($noteRaw),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'resolved_recipients' => $resolvedRecipients,
            'sent_at' => date('Y-m-d H:i:s'), 'is_test' => false,
        ]);
    }
}
