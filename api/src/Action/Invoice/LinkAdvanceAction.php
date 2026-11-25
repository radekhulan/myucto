<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedInvoiceAdvanceDrawdown;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/link-advance  body: {advance_id}
 *
 * Propojí daňový doklad se zálohovou fakturou (proforma) — uloží parent_invoice_id
 * a doplní advance_paid_amount, pokud na faktuře žádná záloha ještě není. Zaplacení
 * (status) se nemění. Vrací aktualizovaný invoice payload.
 */
final class LinkAdvanceAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly DocumentLockService $locks,
        private readonly Connection $db,
        private readonly IssuedInvoiceAdvanceDrawdown $issued,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        // Zámek dokladu (Epic F6): vazba na zálohu mění doklad.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
            return $deny;
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $advanceId = (int) ($body['advance_id'] ?? 0);
        if ($advanceId <= 0) {
            return Json::error($response, 'invalid_advance', 'Chybí advance_id.', 400);
        }

        if (!empty($invoice['booked_at']) && !RequestAuthorization::canRepostIssuedInvoice($request)) {
            return Json::error(
                $response,
                'journal_post_forbidden',
                'Propojení zaúčtované faktury se zálohou přeúčtuje deník. K tomu je potřeba oprávnění „Zaúčtovat doklad“.',
                403,
            );
        }

        $supplierId = SupplierGuard::currentId($request);
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        try {
            // Zaúčtovaná faktura: zápis převezme zúčtování zálohy, stav úhrady se nemění.
            (new DimensionPeriodGuard($this->db))->transaction(function () use ($id, $advanceId, $supplierId, $user, $ip, $request): void {
                $this->repo->linkAdvance($id, $advanceId, $supplierId);
                $this->issued->resync($supplierId, $id, [
                    'user_id' => isset($user['id']) ? (int) $user['id'] : null, 'ip' => $ip,
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                ], false);
            });
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) {
                throw $e;
            }
            return Json::error($response, 'link_failed', $e->getMessage(), 409);
        }

        $this->logger->log('invoice.advance_linked', $user['id'] ?? null, 'invoice', $id, [
            'advance_id' => $advanceId,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id));
    }
}
