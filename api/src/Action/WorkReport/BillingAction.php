<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\WorkReport\WorkReportBilling;
use MyInvoice\Service\WorkReport\WorkReportBillingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BillingAction
{
    use GuardsDocumentLock;

    public function __construct(private readonly Connection $db, private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $reports, private readonly WorkReportBillingService $billing,
        private readonly DocumentLockService $locks, private readonly InvoicePdfRenderer $pdf,
        private readonly \MyInvoice\Service\ActivityLogger $logger,
        private readonly \MyInvoice\Service\IpMatcher $ipMatcher,
        private readonly \MyInvoice\Service\Oss\OssItemPlanner $ossPlanner) {}

    public function previewUnsaved(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            if (isset($body['vat_rate_id']) && (!filter_var($body['vat_rate_id'], FILTER_VALIDATE_INT) || (int) $body['vat_rate_id'] <= 0)) throw new \InvalidArgumentException('Neplatná sazba DPH.');
            $stmt = $this->db->pdo()->prepare('SELECT vr.id, vr.rate_percent FROM supplier s JOIN vat_rates vr ON vr.id = COALESCE(?, s.default_vat_rate_id) WHERE s.id = ?');
            $stmt->execute([$body['vat_rate_id'] ?? null, SupplierGuard::currentId($request)]);
            $rate = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$rate) throw new \InvalidArgumentException('Neplatná sazba DPH.');
            $body['vat_rate_id'] = (int) $rate['id'];
            return Json::ok($response, WorkReportBilling::calculate($body, $body, (float) $rate['rate_percent']));
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if (!SupplierGuard::owns($request, $this->invoices->find($id))) return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        $report = $this->reports->findByInvoice($id, SupplierGuard::currentId($request));
        if ($report === null || $report['billing_snapshot'] === null) return Json::error($response, 'validation_failed', 'Výkaz nemá cenové ujednání.', 400);
        if ($report['billing_preview'] === null) return Json::error($response, 'validation_failed', (string) $report['billing_error'], 400);
        return Json::ok($response, $report['billing_preview']);
    }

    public function transfer(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $supplierId = SupplierGuard::currentId($request);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $lock->execute([$supplierId, $id]);
            if (!$lock->fetchColumn()) {
                $pdo->rollBack();
                return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
            }
            $invoice = $this->invoices->find($id);
            if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $id)) {
                $pdo->rollBack();
                return $deny;
            }
            if ($invoice['status'] !== 'draft') throw new \DomainException('Účtování lze přenést pouze do konceptu faktury.');
            $body = (array) ($request->getParsedBody() ?? []);
            if (isset($body['legacy_item_id']) && (!filter_var($body['legacy_item_id'], FILTER_VALIDATE_INT) || (int) $body['legacy_item_id'] <= 0)) throw new \InvalidArgumentException('Neplatná nahrazovaná položka.');
            if (!is_string($body['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $body['fingerprint'])) throw new \InvalidArgumentException('Neplatný otisk náhledu účtování.');
            $result = $this->billing->transfer($id, $supplierId, $body['fingerprint'], $this->ossPlanner, isset($body['legacy_item_id']) ? (int) $body['legacy_item_id'] : null);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) return Json::error($response, 'billing_conflict', $e->getMessage(), 409);
            if ($e instanceof \InvalidArgumentException) return Json::error($response, 'validation_failed', $e->getMessage(), 400);
            throw $e;
        }
        try {
            $this->pdf->invalidate($id, 'invalidate_workreport_billing');
        } catch (\Throwable $e) {
            error_log('work_report.billing_pdf_invalidation_failed: ' . $e->getMessage());
        }
        return Json::ok($response, $result);
    }
}
