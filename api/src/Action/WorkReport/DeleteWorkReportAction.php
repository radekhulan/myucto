<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteWorkReportAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly DocumentLockService $locks,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        $invoice = $this->invoices->find($invoiceId);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = RequestAuthorization::isCompanyAdmin($request);
        $isForce = !empty($request->getQueryParams()['force']);

        // Zámek dokladu (Epic F6) — PŘED status guardem: výkaz je součást dokladu.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $invoiceId)) {
            return $deny;
        }

        if ($invoice['status'] !== 'draft' && !($isAdmin && $isForce)) {
            return Json::error($response, 'not_editable', 'Výkaz lze smazat pouze v draftu (admin: ?force=1).', 409);
        }
        $this->repo->deleteByInvoice($invoiceId);
        $this->pdf->invalidate($invoiceId, 'invalidate_workreport');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($invoice['status'] !== 'draft') ? 'work_report.force_deleted' : 'work_report.deleted';
        $this->logger->log($action, $user['id'] ?? null, 'invoice', $invoiceId, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['deleted' => true]);
    }
}
