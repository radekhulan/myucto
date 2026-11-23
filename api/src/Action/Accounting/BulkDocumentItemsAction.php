<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Accounting\Bulk\BulkActionException;
use MyInvoice\Service\Accounting\Bulk\BulkSelection;
use MyInvoice\Service\Accounting\Bulk\DocumentItemSearch;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Agenda Položky dokladů v Hromadných akcích: položky vydaných nebo přijatých faktur
 * podle filtru jako výběr pro hromadnou akci nad položkami.
 *
 *   GET /api/accounting/bulk-actions/document-items
 *       ?target=invoice_item|purchase_invoice_item
 *       &filter[q]=&filter[partner_id]=&filter[date_field]=tax|issue&filter[date_from]=&filter[date_to]=
 *       &filter[vat_code]=<kód>|none&filter[vat_rate_id]=&filter[account]=&filter[product_code]=&filter[doc_number]=
 *       &cursor=&per_page=
 *
 * Vrací stránku položek (nejnovější doklady první, stránkuje se klíčem `next_cursor`)
 * a počet odpovídajících položek nejvýš do limitu hromadné akce + 1 (`count_capped`):
 * víc jich jedna akce stejně nezpracuje. Seznam vidí, kdo smí číst vydané, resp. přijaté
 * faktury; spustit akci nad výběrem smí jen ten, komu to dovolí `POST /bulk-actions/start`.
 */
final class BulkDocumentItemsAction
{
    public function __construct(private readonly DocumentItemSearch $search) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí kontext firmy.', 400);
        }
        $query = $request->getQueryParams();
        $target = (string) ($query['target'] ?? '');
        if (!DocumentItemSearch::isItemTarget($target)) {
            return Json::error($response, 'validation_failed', 'Zvolte položky vydaných, nebo přijatých faktur.', 422);
        }
        $module = DocumentItemSearch::documentType($target) === 'invoice' ? 'invoices' : 'purchase_invoices';
        if (!RequestAuthorization::allows($request, $module, AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Chybí oprávnění k seznamu dokladů.', 403);
        }
        try {
            $filters = DocumentItemSearch::filters($query);
            $perPage = (int) ($query['per_page'] ?? DocumentItemSearch::PER_PAGE);
            $cursor = is_string($query['cursor'] ?? null) ? $query['cursor'] : null;
            $page = $this->search->page($supplierId, $target, $filters, $cursor, $perPage);
            $count = $cursor === null ? $this->search->count($supplierId, $target, $filters, BulkSelection::LIMIT + 1) : null;
        } catch (BulkActionException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        return Json::ok($response, [
            'items'        => $page['items'],
            'next_cursor'  => $page['next_cursor'],
            'count'        => $count['count'] ?? null,
            'count_capped' => $count['capped'] ?? null,
            'limit'        => BulkSelection::LIMIT,
        ]);
    }
}
