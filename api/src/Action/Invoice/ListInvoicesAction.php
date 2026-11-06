<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\InvoiceListFilters;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\InvoiceListDetailsRepository;
use MyInvoice\Repository\InvoiceListExtrasRepository;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Repository\DimensionListSummaryRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Report\InvoiceKhSections;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListInvoicesAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Config $config,
        private readonly DocumentLockService $locks,
        private readonly InvoiceKhSections $khSections,
        private readonly InvoiceListDetailsRepository $listDetails,
        private readonly InvoiceListExtrasRepository $listExtras,
        private readonly DimensionListSummaryRepository $dimensionSummaries,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);
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

        $filters = InvoiceListFilters::issued($request);
        if (is_string($filters)) {
            return Json::error($response, 'validation_failed', $filters, 422);
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        $result = $this->repo->listGroupedByMonth($filters, $page, $perPage);

        // Jednotný kontrakt zámku per-row (Epic F6, §4.5) — batch přes lockedMapForSources,
        // jeden IN dotaz na posted zápisy, žádné N+1.
        $ids = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids !== []) {
            $map = $this->locks->lockedMapForSources((int) $filters['supplier_id'], 'invoice', $ids);
            $includeVat = ($filter['include_vat_breakdown'] ?? null) === '1';
            $includePosting = ($filter['include_posting_accounts'] ?? null) === '1';
            $details = $includeVat || $includePosting
                ? $this->listDetails->forDocuments((int) $filters['supplier_id'], 'invoice', $ids, $includeVat, $includePosting)
                : [];
            $extras = $this->listExtras->forDocuments(
                (int) $filters['supplier_id'], 'invoice', $ids,
                ($filter['include_journal_notes'] ?? null) === '1',
                ($filter['include_document_tags'] ?? null) === '1' ? DocumentViewerResolver::fromRequest($request) : null,
            );
            $dimensionLabels = ($filter['include_dimensions'] ?? null) === '1'
                ? $this->dimensionSummaries->forDocuments((int) $filters['supplier_id'], 'invoice', $ids)
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
                }
            }
            unset($group, $row);
        }

        if (($filter['include_kh'] ?? null) === '1' || ($filter['include_vat_classification'] ?? null) === '1') {
            $this->khSections->addToGroups((int) $filters['supplier_id'], $result['data'], 'issued', ($filter['include_vat_classification'] ?? null) === '1');
        }

        return Json::ok($response, $result);
    }
}
