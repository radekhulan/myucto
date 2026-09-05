<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Http\TenantReferenceGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\ProjectRepository;
use MyInvoice\Repository\WorkReportRepository;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/invoices/{id}/work-report/materials
 * body: {
 *   material_title: string,           // default „Materiál"
 *   material_vat_rate_id: int|null,   // sazba DPH materiálu (12/21); povinná, je-li ≥1 řádek
 *   materials: [{ description, quantity, unit, unit_price, order_index? }]
 * }
 *
 * Ukládá část MATERIÁL téže work_reports řádky (sdílí ji s výkazem práce). Oba editory
 * ukládají nezávisle — tato akce nesahá na práci ani na invoice_items (řádek „Materiál"
 * sync řeší frontend přes PUT /api/invoices/{id}, stejně jako u výkazu práce).
 */
final class SaveWorkReportMaterialsAction
{
    use GuardsDocumentLock;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly WorkReportRepository $repo,
        private readonly ProjectRepository $projects,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $pdf,
        private readonly DocumentLockService $locks,
        private readonly TenantReferenceGuard $tenantRefs,
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

        // Zámek dokladu (Epic F6) — PŘED status guardem: výkaz materiálu je součást dokladu.
        if ($deny = $this->denyIfLocked($request, $response, $this->locks->forInvoice($invoice), 'invoice', $invoiceId)) {
            return $deny;
        }

        if ($invoice['status'] !== 'draft' && !($isAdmin && $isForce)) {
            return Json::error($response, 'not_editable', 'Výkaz lze upravit pouze v draftu (admin: ?force=1).', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $projectIdRaw = $body['project_id'] ?? ($invoice['project_id'] ?? null);
        $projectId = ($projectIdRaw !== null && $projectIdRaw !== '' && (int) $projectIdRaw > 0)
            ? (int) $projectIdRaw
            : null;

        // BOLA guard (security report 2026-08, R2 #9 / sweep F6) — sesterský
        // SaveWorkReportAction:88 tuhle kontrolu má (varianta MS-P1-1), tady chyběla,
        // takže se do `work_reports.project_id` dal natrvalo zapsat projekt cizí firmy.
        // Kontrolujeme jen hodnotu z TĚLA — fallback z už guardnuté faktury je bezpečný.
        if (array_key_exists('project_id', $body)) {
            $badRefs = $this->tenantRefs->violations(
                SupplierGuard::currentId($request),
                $body,
                ['project_id'],
            );
            if ($badRefs !== []) {
                return Json::error($response, 'invalid_reference', TenantReferenceGuard::message($badRefs), 400);
            }
        }

        $materialTitle = trim((string) ($body['material_title'] ?? ''));
        if ($materialTitle === '') {
            $materialTitle = 'Materiál';
        }

        $materials = array_values((array) ($body['materials'] ?? []));

        $vatRateIdRaw = $body['material_vat_rate_id'] ?? null;
        $materialVatRateId = ($vatRateIdRaw !== null && $vatRateIdRaw !== '' && (int) $vatRateIdRaw > 0)
            ? (int) $vatRateIdRaw
            : null;

        // Validace řádků materiálu.
        foreach ($materials as $idx => $m) {
            $row = $idx + 1;
            if (trim((string) ($m['description'] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "Řádek $row: chybí popis.", 400);
            }
            if ((float) ($m['quantity'] ?? 0) <= 0) {
                return Json::error($response, 'validation_failed', "Řádek $row: množství musí být větší než 0.", 400);
            }
            if (trim((string) ($m['unit'] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "Řádek $row: chybí jednotka.", 400);
            }
            if ((float) ($m['unit_price'] ?? 0) < 0) {
                return Json::error($response, 'validation_failed', "Řádek $row: cena nesmí být záporná.", 400);
            }
        }

        // Je-li aspoň jeden materiál, sazba DPH je povinná a musí existovat.
        if (count($materials) > 0) {
            if ($materialVatRateId === null) {
                return Json::error($response, 'validation_failed', 'Chybí sazba DPH výkazu materiálu.', 400);
            }
            if (!$this->repo->vatRateExists($materialVatRateId)) {
                return Json::error($response, 'validation_failed', 'Neplatná sazba DPH výkazu materiálu.', 400);
            }
        }

        $wrId = $this->repo->saveMaterials($invoiceId, $projectId, $materialTitle, $materialVatRateId, $materials);
        $wr = $this->repo->findByInvoice($invoiceId);
        $this->pdf->invalidate($invoiceId, 'invalidate_workreport');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($invoice['status'] !== 'draft') ? 'work_report_material.force_saved' : 'work_report_material.saved';
        $this->logger->log($action, $user['id'] ?? null, 'work_report', $wrId, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $wr);
    }
}
