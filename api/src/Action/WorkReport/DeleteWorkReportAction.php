<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SessionOnlyAccountingAct;
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
        private readonly \MyInvoice\Infrastructure\Database\Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int) ($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Tentýž zámek používá uložení výkazu i přenos cen. Pojistky čteme až po něm.
            $lock = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([SupplierGuard::currentId($request), $invoiceId]);
            $invoice = $this->invoices->find($invoiceId);
            if (!SupplierGuard::owns($request, $invoice)) {
                return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
            }
            $result = $this->deleteLocked($request, $response, $invoiceId, $invoice);
            if ($result->getStatusCode() >= 400) return $result;
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        try {
            $this->pdf->invalidate($invoiceId, 'invalidate_workreport');
        } catch (\Throwable $e) {
            error_log('work_report.delete_pdf_failed: ' . $e->getMessage());
        }
        try {
            $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
            $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
            $action = ($invoice['status'] !== 'draft') ? 'work_report.force_deleted' : 'work_report.deleted';
            $this->logger->log($action, $user['id'] ?? null, 'invoice', $invoiceId, null, $ip, $request->getHeaderLine('User-Agent'));
        } catch (\Throwable $e) {
            error_log('work_report.delete_log_failed: ' . $e->getMessage());
        }
        return $result;
    }

    private function deleteLocked(Request $request, Response $response, int $invoiceId, array $invoice): Response
    {
        $canEditIssued = RequestAuthorization::canEditIssuedInvoice($request);
        $isForce = !empty($request->getQueryParams()['force']);

        // Zámek dokladu (Epic F6) — PŘED status guardem: výkaz je součást dokladu.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $invoiceId)) {
            return $deny;
        }

        // Vynucená úprava výkazu vystaveného dokladu jen z relace, stejně jako úprava faktury.
        if ($invoice['status'] !== 'draft' && $isForce
            && ($deny = SessionOnlyAccountingAct::deny($request, $response, 'Vynucené smazání výkazu vystavené faktury'))) {
            return $deny;
        }
        if ($invoice['status'] !== 'draft' && !($canEditIssued && $isForce)) {
            return Json::error($response, 'not_editable', 'Výkaz lze smazat pouze v draftu (vystavenou s oprávněním: ?force=1).', 409);
        }
        if ($this->repo->hasBillingItems($invoiceId, SupplierGuard::currentId($request))) {
            return Json::error($response, 'billing_conflict', 'Nejprve odeberte z faktury propojené položky účtování, potom smažte výkaz.', 409);
        }
        $this->repo->deleteByInvoice($invoiceId, SupplierGuard::currentId($request));
        return Json::ok($response, ['deleted' => true]);
    }
}
