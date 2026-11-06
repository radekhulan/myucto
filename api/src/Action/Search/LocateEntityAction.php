<?php

declare(strict_types=1);

namespace MyInvoice\Action\Search;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Tenant\SupplierAccessResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/locate/{type}/{id} — ke které firmě doklad patří.
 *
 * Odkaz na doklad jiné firmy (sdílená adresa, záložka, e-mail) jinak skončí na 404,
 * protože detail je vždy scopovaný na zvolenou firmu. Frontend se sem zeptá dřív, než
 * detail otevře, a patří-li doklad firmě, do které uživatel smí, přepne ji.
 *
 * Firmu prozradí jen tehdy, když by do ní uživatel smel přepnout: přístup se ověřuje
 * týmž resolverem jako každý požadavek s `X-Supplier-Id` (membership, globální admin,
 * uzamčená doména, token vázaný na firmu). Jinak odpověď nerozliší „neexistuje" od
 * „patří cizí firmě" — obojí je 404.
 */
final class LocateEntityAction
{
    /** Typ entity → tabulka s `supplier_id`. */
    private const TABLES = [
        'invoice'          => 'invoices',
        'purchase_invoice' => 'purchase_invoices',
        'journal_entry'    => 'journal_entries',
        'cash_document'    => 'cash_documents',
        'other_item'       => 'other_items',
        'internal_document' => 'internal_documents',
        'bank_statement'   => 'bank_statements',
        'document'         => 'documents',
        'stock_document'   => 'stock_documents',
        'sales_order'      => 'sales_orders',
        'purchase_order'   => 'purchase_orders',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly SupplierAccessResolver $access,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $table = self::TABLES[(string) ($args['type'] ?? '')] ?? null;
        $id = (int) ($args['id'] ?? 0);
        if ($table === null || $id <= 0) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        $stmt = $this->db->pdo()->prepare("SELECT supplier_id FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $owner = (int) ($stmt->fetchColumn() ?: 0);
        if ($owner <= 0 || !$this->canAccess($request, $owner)) {
            return Json::error($response, 'not_found', 'Doklad nenalezen.', 404);
        }

        return Json::ok($response, ['supplier_id' => $owner]);
    }

    private function canAccess(Request $request, int $supplierId): bool
    {
        $probe = $request
            ->withHeader(SupplierScopeMiddleware::HEADER_NAME, (string) $supplierId)
            ->withQueryParams([]);
        $resolved = $this->access->resolve($probe);

        return !$resolved->denied && $resolved->supplierId === $supplierId;
    }
}
