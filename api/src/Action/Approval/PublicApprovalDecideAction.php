<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Approval\ApprovalTokenValidator;
use MyInvoice\Service\Captcha\TurnstileVerifier;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\PublicTenantGuard;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/public/approval/{token}/decide
 *
 * Veřejný (bez auth) endpoint. Body:
 *   {
 *     decision: 'approve' | 'reject',
 *     decided_by_email?: string,     // email osoby, která rozhoduje (volitelné, pro audit)
 *     rejection_reason?: string,     // povinné při reject
 *     cf_turnstile_response?: string // captcha token
 *   }
 *
 * Effects:
 *  - approve  → status='approved', spustí AutoIssueAndSendService (vystaví+pošle fakturu)
 *  - reject   → status='rejected', uloží reason
 *  - audit:    invoice.approval_approved | invoice.approval_rejected
 *  - token zneplatněn (single-use)
 */
final class PublicApprovalDecideAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly TurnstileVerifier $captcha,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly AutoIssueAndSendService $autoIssue,
        private readonly PublicTenantGuard $tenantGuard,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if (!ApprovalTokenValidator::isValidFormat($token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $invoice = $this->repo->findByApprovalToken($token);
        if ($invoice === null || $invoice['approval_status'] !== 'requested') {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }
        if (!$this->tenantGuard->allows($request, (int) $invoice['supplier_id'])) {
            return Json::error($response, 'not_found', 'Tento odkaz není platný.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $decision = (string) ($body['decision'] ?? '');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            return Json::error($response, 'invalid_decision', 'Decision musí být approve nebo reject.', 422);
        }

        // CAPTCHA — povinná pro public actions
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $captchaToken = (string) ($body['cf_turnstile_response'] ?? '');
        if (!$this->captcha->verify($captchaToken, $ip, 'approval')) {
            return Json::error($response, 'captcha_failed',
                'Ověření CAPTCHA selhalo, zkuste to prosím znovu.', 422);
        }

        $decidedBy = isset($body['decided_by_email']) ? trim((string) $body['decided_by_email']) : null;
        if ($decidedBy !== null && $decidedBy !== '' && !filter_var($decidedBy, FILTER_VALIDATE_EMAIL)) {
            return Json::error($response, 'invalid_email', 'Email rozhodujícího je neplatný.', 422);
        }
        if ($decidedBy === '') $decidedBy = null;

        $invoiceId = (int) $invoice['id'];
        $ua = $request->getHeaderLine('User-Agent');

        if ($decision === 'reject') {
            $reason = isset($body['rejection_reason']) ? trim((string) $body['rejection_reason']) : '';
            if ($reason === '') {
                return Json::error($response, 'reason_required', 'Důvod zamítnutí je povinný.', 422);
            }
            // Limit délky aby se zabránilo DoS přes obří texty
            if (mb_strlen($reason) > 2000) {
                $reason = mb_substr($reason, 0, 2000);
            }
            // Atomicky — jen vítěz závodu pokračuje (TOCTOU guard).
            if (!$this->repo->decideIfRequested($invoiceId, $token, 'rejected', $decidedBy, $reason)) {
                return Json::error($response, 'token_invalid_or_expired',
                    'Tento odkaz byl již použit nebo není platný.', 404);
            }
            $this->logger->log('invoice.approval_rejected', null, 'invoice', $invoiceId, [
                'reason' => $reason,
                'by' => 'public',
                'decided_by_email' => $decidedBy,
            ], $ip, $ua);

            return Json::ok($response, [
                'decision' => 'rejected',
                'message'  => 'Výkaz byl zamítnut. Zákazník bude o tom informován.',
            ]);
        }

        // approve — komentář je volitelný (sdílí sloupec approval_rejection_reason)
        $approveComment = isset($body['comment']) ? trim((string) $body['comment']) : '';
        if ($approveComment !== '' && mb_strlen($approveComment) > 2000) {
            $approveComment = mb_substr($approveComment, 0, 2000);
        }
        // Atomicky — jen vítěz závodu pokračuje (jinak by AutoIssueAndSendService
        // mohl fakturu vystavit a odeslat dvakrát při souběžných requestech).
        if (!$this->repo->decideIfRequested($invoiceId, $token, 'approved', $decidedBy, $approveComment !== '' ? $approveComment : null)) {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }
        $this->logger->log('invoice.approval_approved', null, 'invoice', $invoiceId, [
            'by' => 'public',
            'decided_by_email' => $decidedBy,
            'comment' => $approveComment !== '' ? $approveComment : null,
        ], $ip, $ua);

        // Koncept v uzavřeném účetním období nevystaví ani schválení zákazníkem: ruční
        // vystavení tam odmítne i účetní (409) a přebít ho smí jen správce. Schválení
        // zůstane zapsané, vystavení dokončí účetní ručně.
        if (($invoice['status'] ?? '') === 'draft' && $this->locks->forInvoice($invoice)->inClosedPeriod) {
            $this->logger->log('invoice.approval_auto_issue_blocked', null, 'invoice', $invoiceId, [
                'reason' => 'period_closed',
                'by'     => 'public',
            ], $ip, $ua);
            return Json::ok($response, [
                'decision' => 'approved',
                'message'  => 'Výkaz byl schválen. Faktura bude obratem zaslána.',
            ]);
        }

        try {
            $autoResult = $this->autoIssue->run($invoiceId, null, $ip, $ua);
        } catch (\Throwable $e) {
            // Schválení proběhlo ale auto-send selhal — admin se o tom dozví z activity logu.
            // Klientovi vrátíme úspěch — ze strany klienta je vše OK.
            // Důležité: NELEAKUJEME message ($e->getMessage()) ven — public endpoint může
            // odhalit interní detaily (DB error, filesystem path, mailer error).
            $this->logger->log('invoice.approval_auto_send_failed', null, 'invoice', $invoiceId, [
                'error' => $e->getMessage(),
                'by'    => 'public',
            ], $ip, $ua);
            return Json::ok($response, [
                'decision' => 'approved',
                'message'  => 'Výkaz byl schválen. Faktura bude obratem zaslána.',
            ]);
        }

        return Json::ok($response, [
            'decision' => 'approved',
            'message'  => 'Výkaz byl schválen. Faktura již byla odeslána.',
            'sent_to'  => $autoResult['sent_to'],
        ]);
    }
}
