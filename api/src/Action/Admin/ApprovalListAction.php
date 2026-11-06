<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/admin/approvals?status=requested|approved|rejected|all&overdue_days=5
 *
 * Vrací faktury filtrované podle approval_status pro admin „Approval inbox".
 * Scope = aktuální supplier (X-Supplier-Id). Default status='requested'.
 *
 * Query:
 *   ?status=requested|approved|rejected|all  (default: 'requested')
 *   ?overdue_days=N  (jen requested starší než N dní bez decize)
 *   ?page=N, ?per_page=N (page size z cfg.pagination.invoices_per_page, default 50)
 */
final class ApprovalListAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Config $config,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!RequestAuthorization::allows($request, 'invoices.approval', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Admin only.', 403);
        }

        $supplierId = (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0);
        if ($supplierId <= 0) {
            return Json::error($response, 'no_supplier', 'Není vybrán dodavatel.', 400);
        }

        $q = $request->getQueryParams();
        $status = (string) ($q['status'] ?? 'requested');
        if (!in_array($status, ['requested', 'approved', 'rejected', 'all'], true)) {
            return Json::error($response, 'invalid_status', 'Status musí být requested|approved|rejected|all.', 422);
        }
        $statusFilter = $status === 'all' ? null : $status;

        $overdueDays = isset($q['overdue_days']) && ctype_digit((string) $q['overdue_days'])
            ? (int) $q['overdue_days']
            : null;

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        // Repository vrací ['data'=>..., 'meta'=>...] když perPage>0.
        $result = $this->repo->listForApprovalInbox(
            supplierId: $supplierId,
            statusFilter: $statusFilter,
            minDaysSince: $overdueDays,
            maxReminders: null,
            page: $page,
            perPage: $perPage,
        );
        // Token odkazu ke schválení potřebuje jen cron připomínek. Přehled stačí číst
        // s právem READ a token by z něj dělal schválení (= vystavení a odeslání faktury).
        foreach ($result['data'] ?? [] as $i => $row) {
            unset($result['data'][$i]['approval_token']);
        }
        return Json::ok($response, $result);
    }
}
