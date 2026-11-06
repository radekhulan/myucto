<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\FinalDraftToTaxDocument;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Stats\StatsRecomputer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/convert-to-tax-document
 *
 * Koncept vyúčtovací faktury k zálohové faktuře nahradí daňovým dokladem k přijaté
 * platbě (koncept, DUZP = den platby). Koncept faktury se odstraní, proto route
 * vyžaduje právo mazat faktury a akce navíc právo evidovat úhrady, pod kterým
 * daňový doklad k platbě vzniká na zálohové faktuře.
 */
final class ConvertFinalDraftToTaxDocumentAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly FinalDraftToTaxDocument $converter,
        private readonly DocumentLockService $locks,
        private readonly StatsRecomputer $stats,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $draft = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $draft)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if (!RequestAuthorization::allows($request, 'invoices.mark_paid', AccessLevel::WRITE)) {
            return Json::error($response, 'forbidden', 'Nemáte oprávnění evidovat úhrady faktur.', 403);
        }

        $supplierId = SupplierGuard::currentId($request);
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($draft)->forDeletion('draft', false), 'invoice', $id)) {
            return $deny;
        }
        // DUZP daňového dokladu = den platby; v uzavřeném období nesmí vzniknout zpětně.
        $proformaId = (int) ($draft['parent_invoice_id'] ?? 0);
        foreach ($proformaId > 0 ? $this->converter->pendingPayments($proformaId) : [] as $payment) {
            if ($deny = $this->denyIfLocked($request, $response, $this->locks->forDate($supplierId, $payment['paid_on']), 'invoice', $proformaId)) {
                return $deny;
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        try {
            $result = $this->converter->convert($id, (int) ($user['id'] ?? 0));
        } catch (\RuntimeException $e) {
            return Json::error($response, 'tax_document_failed', $e->getMessage(), 409);
        }

        try {
            $this->stats->recomputeForIds(
                (int) $draft['client_id'],
                !empty($draft['project_id']) ? (int) $draft['project_id'] : null,
            );
        } catch (\Throwable $e) {
            error_log('ConvertFinalDraftToTaxDocumentAction: recompute stats cache selhal: ' . $e->getMessage());
        }

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.final_draft_converted_to_tax_document', $user['id'] ?? null, 'invoice', $result['proforma_id'], [
            'deleted_draft_id' => $id,
            'tax_document_ids' => $result['tax_document_ids'],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $result);
    }
}
