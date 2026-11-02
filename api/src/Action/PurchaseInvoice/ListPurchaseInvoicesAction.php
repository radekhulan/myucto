<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Repository\InvoiceListDetailsRepository;
use MyInvoice\Repository\InvoiceListExtrasRepository;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Repository\DimensionListSummaryRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\PurchaseInvoice\Review\PurchaseInvoiceReviewNeeds;
use MyInvoice\Service\Report\InvoiceKhSections;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/purchase-invoices
 *
 * Vrací seznam přijatých faktur seskupený po měsících (per tenant).
 * Filtry: status, document_kind, vendor_id, project_id (id | 'none' = bez zakázky),
 * year, month, date_from, date_to, currency, q, unpaid_only, overdue,
 * unpaid_as_of (YYYY-MM-DD — stav úhrady K DATU X, ne dnešní status; viz PurchaseInvoiceRepository::listGroupedByMonth),
 * paid_shortfall (uhrazené doklady, které evidované úhrady nepokrývají).
 * Řádek nese paid_amount / remaining_amount v měně dokladu (SSOT PurchaseSettledExpr).
 */
final class ListPurchaseInvoicesAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly Config $config,
        private readonly DocumentLockService $locks,
        private readonly InvoiceKhSections $khSections,
        private readonly InvoiceListDetailsRepository $listDetails,
        private readonly InvoiceListExtrasRepository $listExtras,
        private readonly DimensionListSummaryRepository $dimensionSummaries,
        private readonly PurchaseInvoiceReviewNeeds $reviewNeeds,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);
        foreach (['has_visual_document', 'has_structured_source'] as $key) {
            if (array_key_exists($key, $filter) && !in_array($filter[$key], ['0', '1'], true)) {
                return Json::error($response, 'validation_failed', "filter[{$key}] musí být 0 nebo 1.", 422);
            }
        }
        if (($filter['include_dimensions'] ?? null) === '1'
            && !RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        if ((($filter['include_journal_notes'] ?? null) === '1'
            || ($filter['include_posting_accounts'] ?? null) === '1'
            || in_array($q['sort_key'] ?? '', ['journal_notes', 'debit_accounts', 'credit_accounts'], true))
            && !RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        if (($filter['include_document_tags'] ?? null) === '1'
            && !RequestAuthorization::allows($request, 'documents', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        // Neuhrazené K DATU X (task #4) — historický protějšek `unpaid_only`. Validace
        // shodná se SaldoAction::isDate — chybný formát je 422, ne tiché "nefiltrovat".
        $unpaidAsOf = isset($filter['unpaid_as_of']) && is_scalar($filter['unpaid_as_of'])
            ? trim((string) $filter['unpaid_as_of'])
            : '';
        if ($unpaidAsOf !== '' && !$this->isDate($unpaidAsOf)) {
            return Json::error($response, 'validation_failed', "filter[unpaid_as_of] musí být datum (YYYY-MM-DD).", 422);
        }

        $filters = [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']        ?? null,
            'document_kind' => $filter['document_kind'] ?? null,
            'vendor_id'     => $filter['vendor_id']     ?? null,
            'project_id'    => $filter['project_id']    ?? null,
            'year'          => $filter['year']          ?? null,
            'month'         => $filter['month']         ?? null,
            'date_from'     => $filter['date_from']     ?? null,
            'date_to'       => $filter['date_to']       ?? null,
            'currency'      => $filter['currency']      ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'unpaid_as_of'  => $unpaidAsOf !== '' ? $unpaidAsOf : null,
            'unmatched'     => !empty($filter['unmatched']),
            'needs_review'  => !empty($filter['needs_review']),
            'approval_status' => is_scalar($filter['approval_status'] ?? $q['approval_status'] ?? null)
                ? (string) ($filter['approval_status'] ?? $q['approval_status']) : null,
            'paid_shortfall' => !empty($filter['paid_shortfall']),
            'payment_ordered' => $filter['payment_ordered'] ?? null,
            'booked'        => $filter['booked'] ?? null,
            'has_visual_document' => $filter['has_visual_document'] ?? null,
            'has_structured_source' => $filter['has_structured_source'] ?? null,
            'import_batch_id' => $filter['import_batch_id'] ?? null,
            'supplier_id'   => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
            'group_by_month' => !is_scalar($filter['group_by_month'] ?? null) || (string) $filter['group_by_month'] !== '0',
            'sort_key' => is_scalar($q['sort_key'] ?? null) ? (string) $q['sort_key'] : '',
            'sort_dir' => is_scalar($q['sort_dir'] ?? null) ? (string) $q['sort_dir'] : '',
        ];

        // CSV split pro multi-select
        foreach (['status', 'document_kind'] as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        // Bez práva číst Dokumenty se počítá jen vlastní archiv faktury.
        $documentViewer = isset($filter['has_visual_document'])
            && RequestAuthorization::allows($request, 'documents', AccessLevel::READ)
            ? DocumentViewerResolver::fromRequest($request) : null;
        $result = $this->repo->listGroupedByMonth($filters, $page, $perPage, $documentViewer);

        // Jednotný kontrakt zámku per-row (Epic F6, §4.5) — batch přes lockedMapForSources,
        // jeden IN dotaz na posted zápisy, žádné N+1.
        $ids = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids !== []) {
            $map = $this->locks->lockedMapForSources((int) $filters['supplier_id'], 'purchase_invoice', $ids);
            $includeVat = ($filter['include_vat_breakdown'] ?? null) === '1';
            $includePosting = ($filter['include_posting_accounts'] ?? null) === '1';
            $details = $includeVat || $includePosting
                ? $this->listDetails->forDocuments((int) $filters['supplier_id'], 'purchase_invoice', $ids, $includeVat, $includePosting)
                : [];
            $extras = $this->listExtras->forDocuments(
                (int) $filters['supplier_id'], 'purchase_invoice', $ids,
                ($filter['include_journal_notes'] ?? null) === '1',
                ($filter['include_document_tags'] ?? null) === '1' ? DocumentViewerResolver::fromRequest($request) : null,
            );
            $dimensionLabels = ($filter['include_dimensions'] ?? null) === '1'
                ? $this->dimensionSummaries->forDocuments((int) $filters['supplier_id'], 'purchase_invoice', $ids)
                : [];
            $review = ($filter['include_review'] ?? null) === '1'
                ? $this->reviewNeeds->forListRows(
                    (int) $filters['supplier_id'],
                    array_merge(...array_map(static fn (array $g): array => $g['invoices'], $result['data'])),
                )
                : [];
            foreach ($result['data'] as &$group) {
                foreach ($group['invoices'] as &$row) {
                    $lock = $map[(int) $row['id']] ?? null;
                    if ($lock !== null) {
                        $row['locked'] = $lock->toArray();
                    }
                    $row += $extras[(int) $row['id']] ?? [];
                    if (isset($details[(int) $row['id']])) {
                        $row += $details[(int) $row['id']];
                    }
                    if (($filter['include_dimensions'] ?? null) === '1') {
                        $row['dimension_labels'] = $dimensionLabels[(int) $row['id']] ?? [];
                    }
                    if (isset($review[(int) $row['id']])) {
                        $row['review'] = PurchaseInvoiceReviewNeeds::toApi($review[(int) $row['id']]);
                    }
                }
            }
            unset($group, $row);
        }

        if (($filter['include_kh'] ?? null) === '1' || ($filter['include_vat_classification'] ?? null) === '1') {
            $this->khSections->addToGroups((int) $filters['supplier_id'], $result['data'], 'received', ($filter['include_vat_classification'] ?? null) === '1');
        }

        return Json::ok($response, $result);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
