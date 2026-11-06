<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\AutoIssueAndSendService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/invoices/{id}/approval-status (admin only)
 *
 * Body: { status: 'none' | 'requested' | 'approved' | 'rejected', rejection_reason?: string }
 *
 * - status='approved' → spustí AutoIssueAndSendService (vystaví fakturu + pošle klientovi)
 * - status='rejected' → uloží rejection_reason
 * - status='none'     → reset (zruší token, vymaže timestamps)
 * - status='requested' → tady NE — k tomu slouží RequestApprovalAction (potřebuje token+email)
 *
 * Decided_by_email = email aktuálního admin usera.
 */
final class UpdateApprovalStatusAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly AutoIssueAndSendService $autoIssue,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'invoices.approval', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Změnu stavu schválení může provést jen admin.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $newStatus = (string) ($body['status'] ?? '');
        if (!in_array($newStatus, ['none', 'approved', 'rejected'], true)) {
            return Json::error($response, 'invalid_status', 'Status musí být none|approved|rejected.', 422);
        }

        $reason = isset($body['rejection_reason']) ? trim((string) $body['rejection_reason']) : null;

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $ua = $request->getHeaderLine('User-Agent');

        if ($newStatus === 'none') {
            $this->repo->resetApproval($id);
            $this->logger->log('invoice.approval_reset', $user['id'] ?? null, 'invoice', $id, [
                'previous_status' => $invoice['approval_status'],
                'by' => 'admin',
            ], $ip, $ua);
            return Json::ok($response, ['invoice' => $this->repo->find($id)]);
        }

        if ($newStatus === 'rejected') {
            if ($reason === null || $reason === '') {
                return Json::error($response, 'reason_required', 'Důvod zamítnutí je povinný.', 422);
            }
            $this->repo->setApprovalDecision($id, 'rejected', (string) ($user['email'] ?? null), $reason);
            $this->logger->log('invoice.approval_rejected', $user['id'] ?? null, 'invoice', $id, [
                'reason' => $reason,
                'by' => 'admin',
                'decided_by_email' => $user['email'] ?? null,
            ], $ip, $ua);
            return Json::ok($response, ['invoice' => $this->repo->find($id)]);
        }

        // Schválení konceptu ho rovnou vystaví (číslo, DUZP, zaúčtování), proto platí
        // stejný zámek období jako u ručního vystavení: klient 403 v uzavíraném
        // i uzavřeném období, účetní 409 v uzavřeném, správce jen s ?force=1 z relace.
        // Kontrola je před zápisem rozhodnutí, aby odmítnutí nezanechalo schválený koncept.
        if (($invoice['status'] ?? '') === 'draft'
            && ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id))) {
            return $deny;
        }

        // approved → uložit status (volitelný komentář sdílí sloupec rejection_reason),
        // vystavit + poslat fakturu
        $approveComment = $reason !== '' ? $reason : null;
        $this->repo->setApprovalDecision($id, 'approved', (string) ($user['email'] ?? null), $approveComment);
        $this->logger->log('invoice.approval_approved', $user['id'] ?? null, 'invoice', $id, [
            'by' => 'admin',
            'decided_by_email' => $user['email'] ?? null,
            'comment' => $approveComment,
        ], $ip, $ua);

        // Auto-issue + send (idempotentní pokud faktura už není draft)
        try {
            $autoResult = $this->autoIssue->run($id, $user['id'] ?? null, $ip, $ua);
        } catch (\Throwable $e) {
            // Schválení proběhlo, ale auto-send selhal — vrátíme 200 s chybou v payload,
            // admin si může poslat ručně přes "Odeslat fakturu".
            return Json::ok($response, [
                'invoice' => $this->repo->find($id),
                'auto_send_error' => $e->getMessage(),
            ]);
        }

        return Json::ok($response, [
            'invoice'    => $this->repo->find($id),
            'auto_send'  => $autoResult,
        ]);
    }
}
