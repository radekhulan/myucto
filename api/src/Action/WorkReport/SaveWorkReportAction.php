<?php

declare(strict_types=1);

namespace MyInvoice\Action\WorkReport;

use MyInvoice\Http\GuardsDocumentLock;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
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
 * PUT /api/invoices/{id}/work-report
 * body: { project_id: int, title: string, items: [{description, hours, rate, order_index?}] }
 */
final class SaveWorkReportAction
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
            return Json::error($response, 'not_editable', 'Výkaz lze upravit pouze v draftu (admin: ?force=1).', 409);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        // project_id je volitelné — faktura nemusí mít zakázku, výkaz pak také ne.
        $projectIdRaw = $body['project_id'] ?? null;
        $projectId = ($projectIdRaw !== null && $projectIdRaw !== '' && (int) $projectIdRaw > 0)
            ? (int) $projectIdRaw
            : null;
        $title = trim((string) ($body['title'] ?? ''));
        $items = (array) ($body['items'] ?? []);

        if ($title === '') {
            return Json::error($response, 'validation_failed', 'Chybí název výkazu.', 400);
        }

        // Sazba DPH práce (12/21) — volitelná; NULL = fallback default faktury.
        $vatRateIdRaw = $body['vat_rate_id'] ?? null;
        $vatRateId = ($vatRateIdRaw !== null && $vatRateIdRaw !== '' && (int) $vatRateIdRaw > 0)
            ? (int) $vatRateIdRaw
            : null;
        if ($vatRateId !== null && !$this->repo->vatRateExists($vatRateId)) {
            return Json::error($response, 'validation_failed', 'Neplatná sazba DPH výkazu práce.', 400);
        }

        // Project ownership check — varianta MS-P1-1 (Invoice→Project) pro WR edge.
        // Bez tohohle by accountant z S1 mohl uložit WR s project_id ze S2 — cross-tenant
        // FK drift v `work_reports.project_id` (security report @andrejtomci #4, CWE-639 BOLA).
        if ($projectId !== null) {
            $project = $this->projects->find($projectId);
            if (!SupplierGuard::owns($request, $project)) {
                return Json::error($response, 'validation_failed',
                    'Zakázka neexistuje nebo nepatří k aktuálnímu dodavateli.', 400);
            }
            // Belt-and-braces: project musí patřit i ke stejnému klientovi jako faktura.
            if ((int) ($project['client_id'] ?? 0) !== (int) ($invoice['client_id'] ?? 0)) {
                return Json::error($response, 'validation_failed',
                    'Zakázka nepatří k odběrateli této faktury.', 400);
            }
        }

        // Validace — popisujeme řádky 1-based (uživatelsky srozumitelné). Frontend
        // by měl prázdné řádky filtrovat před odesláním (inv. položka totals by se
        // jinak nesedla s uloženým výkazem).
        foreach ($items as $idx => $it) {
            $row = $idx + 1;
            if (trim((string) ($it['description'] ?? '')) === '') {
                return Json::error($response, 'validation_failed', "Řádek $row: chybí popis.", 400);
            }
            if ((float) ($it['hours'] ?? 0) <= 0) {
                return Json::error($response, 'validation_failed', "Řádek $row: počet hodin musí být větší než 0.", 400);
            }
            if ((float) ($it['rate'] ?? 0) < 0) {
                return Json::error($response, 'validation_failed', "Řádek $row: sazba nesmí být záporná.", 400);
            }
            $wd = trim((string) ($it['work_date'] ?? ''));
            if ($wd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $wd)) {
                return Json::error($response, 'validation_failed', "Řádek $row: datum musí být ve formátu YYYY-MM-DD.", 400);
            }
        }

        $id = $this->repo->save($invoiceId, $projectId, $title, $items, $vatRateId);
        $wr = $this->repo->findByInvoice($invoiceId);
        $this->pdf->invalidate($invoiceId, 'invalidate_workreport');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($invoice['status'] !== 'draft') ? 'work_report.force_saved' : 'work_report.saved';
        $this->logger->log($action, $user['id'] ?? null, 'work_report', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $wr);
    }
}
