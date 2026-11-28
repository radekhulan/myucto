<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLocationRepository;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stock\ExactUnitConversion;
use MyInvoice\Service\Stock\StockDocumentService;
use MyInvoice\Service\Stock\StockException;
use MyInvoice\Service\Stock\StockLocationService;
use MyInvoice\Service\Stock\TrackingUnitRenameService;
use MyInvoice\Support\Pagination;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StockTrackingAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockTrackingRepository $tracking,
        private readonly TrackingUnitRenameService $unitRename,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly StockLocationRepository $locationBalances,
        private readonly WarehouseRepository $warehouses,
        private readonly StockDocumentService $documents,
    ) {}

    public function item(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $item = $this->tracking->item($supplierId, (int) $args['id']);
        if ($item === null) return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        $data = [
            'tracking_mode' => $item['tracking_mode'],
            'base_unit' => $item['unit'],
            'units' => $this->tracking->units($supplierId, (int) $item['id']),
            'inventory' => $this->tracking->inventory($supplierId, (int) $item['id']),
            'history' => RequestAuthorization::isClientType($request) ? [] : $this->tracking->history($supplierId, (int) $item['id']),
        ];
        if ($item['tracking_mode'] === 'serial'
            && !RequestAuthorization::isClientType($request)
            && RequestAuthorization::allows($request, 'stock.documents.write', AccessLevel::WRITE)) {
            $data['serial_assignment_stock'] = [];
            foreach ($this->tracking->serialRemainders($supplierId, (int) $item['id']) as $row) {
                if ($row['is_active'] && $row['remainderT'] >= 1000) {
                    $data['serial_assignment_stock'][] = [
                        'warehouse_id' => $row['warehouse_id'],
                        'warehouse_code' => $row['warehouse_code'],
                        'quantity' => intdiv($row['remainderT'], 1000),
                    ];
                }
            }
        }
        return Json::ok($response, $data);
    }

    public function assignSerials(Request $request, Response $response, array $args): Response
    {
        if (RequestAuthorization::isClientType($request)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáte oprávnění.', 403);
        }
        if (!$this->requirePermission($request, $response, 'stock.documents.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $warehouseId = $body['warehouse_id'] ?? null;
        $serials = $body['serial_numbers'] ?? null;
        if (!is_int($warehouseId) || $warehouseId <= 0 || !is_array($serials)) {
            return Json::error($response, 'validation_failed', 'Zadejte sklad a seznam sériových čísel.', 422);
        }
        $itemId = (int) $args['id'];
        $userId = $this->userId($request);
        try {
            $locationId = StockLocationService::id($body['location_id'] ?? null);
            $result = $this->documents->assignSerials($supplierId, $itemId, $warehouseId, $locationId, $serials, $userId);
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        $this->logger->log(
            'stock.serials_assigned',
            $userId,
            'stock_document',
            $result['document_id'],
            ['stock_item_id' => $itemId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'assigned' => $result['assigned']],
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $supplierId,
        );
        return Json::ok($response, $result, 201);
    }

    /**
     * Nabídka kusů pro výběr na řádku faktury: kusy skladem ve skladu (sečtené přes
     * umístění, bez historie pohybů) a u dobropisu k faktuře kusy, které původní faktura
     * vydala a jiné dobropisy ještě nevrátily (`returnable`; jinak null). Stejné pravidlo
     * vymáhá vratka ({@see \MyInvoice\Service\Stock\StockIssueService}).
     */
    public function choices(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $item = $this->tracking->item($supplierId, (int) $args['id']);
        if ($item === null) return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        $query = $request->getQueryParams();
        $warehouseId = (int) ($query['warehouse_id'] ?? 0);
        $invoiceId = (int) ($query['invoice_id'] ?? 0);
        return Json::ok($response, [
            'tracking_mode' => $item['tracking_mode'],
            'base_unit' => $item['unit'],
            'in_stock' => $warehouseId > 0 ? $this->tracking->inStockByUnit($supplierId, (int) $item['id'], $warehouseId) : [],
            'returnable' => $invoiceId > 0 ? $this->returnable($supplierId, (int) $item['id'], $invoiceId) : null,
        ]);
    }

    /** @return list<array{serial_number:?string, lot_code:?string, expires_on:?string, quantity:string}>|null */
    private function returnable(int $supplierId, int $itemId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT parent_invoice_id FROM invoices WHERE id = ? AND supplier_id = ? AND invoice_type = 'credit_note'"
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $parentId = (int) ($stmt->fetchColumn() ?: 0);
        if ($parentId <= 0) {
            return null;
        }
        $siblings = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE supplier_id = ? AND parent_invoice_id = ? AND id <> ?');
        $siblings->execute([$supplierId, $parentId, $invoiceId]);
        $issued = $this->tracking->movedUnitsByInvoices($supplierId, $itemId, [$parentId], 'issue', 'out');
        $returned = $this->tracking->movedUnitsByInvoices(
            $supplierId, $itemId, array_map('intval', $siblings->fetchAll(\PDO::FETCH_COLUMN)), 'receipt', 'in',
        );
        $out = [];
        foreach ($issued as $key => $unit) {
            $leftT = $unit['qtyT'] - ($returned[$key]['qtyT'] ?? 0);
            if ($leftT > 0) {
                $out[] = [
                    'serial_number' => $unit['serial_number'],
                    'lot_code' => $unit['lot_code'],
                    'expires_on' => $unit['expires_on'],
                    'quantity' => \MyInvoice\Service\Stock\StockValuation::tToDecimal($leftT),
                ];
            }
        }
        return $out;
    }

    public function replaceUnits(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $itemId = (int) $args['id'];
        $item = $this->tracking->item($supplierId, $itemId);
        if ($item === null) return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        $body = (array) ($request->getParsedBody() ?? []);
        $rawUnits = $body['units'] ?? null;
        if (!is_array($rawUnits) || !array_is_list($rawUnits) || count($rawUnits) > 100) {
            return Json::error($response, 'validation_failed', 'units musí být seznam nejvýše 100 převodů.', 422);
        }
        try {
            $units = [];
            $seen = [];
            foreach ($rawUnits as $raw) {
                if (!is_array($raw)) throw new StockException('invalid_unit_ratio', 'Neplatný převod jednotky.', 422);
                $code = trim((string) ($raw['unit_code'] ?? ''));
                if ($code === '' || mb_strlen($code) > 20 || $code === (string) $item['unit'] || isset($seen[mb_strtolower($code)])) {
                    throw new StockException('invalid_unit_ratio', 'Kód převodní jednotky je prázdný, duplicitní nebo shodný se základní jednotkou.', 422);
                }
                $seen[mb_strtolower($code)] = true;
                [$num, $den] = ExactUnitConversion::reduce((int) ($raw['numerator'] ?? 0), (int) ($raw['denominator'] ?? 0));
                $units[] = ['unit_code' => $code, 'numerator' => $num, 'denominator' => $den];
            }
            // Balení karty (issue #17, `is_sales_unit = 1`) spravuje jen editor balení;
            // tady se jeho kód použít nesmí — obě sady sdílí unikátní klíč kódu.
            $sales = [];
            foreach ($this->tracking->units($supplierId, $itemId, true) as $salesUnit) {
                $sales[mb_strtolower((string) $salesUnit['unit_code'])] = true;
            }
            foreach ($units as $unit) {
                if (isset($sales[mb_strtolower($unit['unit_code'])])) {
                    throw new StockException('packaging_unit_code_clash', 'Kód převodní jednotky už používá balení karty.', 422, ['unit_code' => $unit['unit_code']]);
                }
            }
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            $this->tracking->replaceUnits($supplierId, $itemId, $units);
            $pdo->commit();
            return Json::ok($response, $this->tracking->units($supplierId, $itemId));
        } catch (StockException $e) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\Throwable $e) {
            if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
            throw $e;
        }
    }

    /**
     * Šarže karty po stránkách (id, kód, datum spotřeby), volitelně hledání v kódu.
     * Lehká alternativa k `item()`, který vrací i celou historii pohybů.
     */
    public function units(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $item = $this->tracking->item($supplierId, (int) $args['id']);
        if ($item === null) return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        $query = $request->getQueryParams();
        $search = isset($query['q']) && is_string($query['q']) ? mb_substr(trim($query['q']), 0, 100) : null;
        $p = Pagination::fromQuery($query, 50);
        [$rows, $total] = $this->tracking->lotUnitsPaged($supplierId, (int) $item['id'], $search, $p['per_page'], $p['offset']);
        return Json::ok($response, Pagination::envelope($rows, $total, $p['page'], $p['per_page']));
    }

    /**
     * Oprava kódu šarže a data spotřeby. Chybějící klíč v těle hodnotu ponechá,
     * `expires_on: null` expiraci smaže. Propsání do konceptů a zaúčtovaných skladových
     * dokladů řeší {@see TrackingUnitRenameService}; audit se zapisuje až po commitu.
     */
    public function updateUnit(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $itemId = (int) $args['id'];
        if ($this->tracking->item($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $changes = array_intersect_key($body, ['lot_code' => true, 'expires_on' => true]);
        try {
            $result = $this->unitRename->rename($supplierId, $itemId, (int) $args['unitId'], $changes);
        } catch (StockException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        }
        // Cache PDF přepsaných konceptů faktur je po opravě zastaralá (stejně jako po úpravě
        // konceptu); soubory se mažou až po commitu, aby rollback nenechal smazané PDF.
        foreach ($result['invoice_ids'] as $invoiceId) {
            try {
                $this->invoicePdf->invalidate($invoiceId, 'invalidate_update');
            } catch (\Throwable $e) {
                error_log('Neplatnost PDF konceptu ' . $invoiceId . ' po opravě šarže selhala: ' . $e->getMessage());
            }
        }
        if ($result['changed']) {
            $this->logger->log(
                'stock.tracking_unit_updated',
                $this->userId($request),
                'stock_item',
                $itemId,
                [
                    'stock_tracking_unit_id' => $result['unit']['id'],
                    'from' => $result['previous'],
                    'to' => ['lot_code' => $result['unit']['lot_code'], 'expires_on' => $result['unit']['expires_on']],
                    'updated' => $result['updated'],
                ],
                $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
                $request->getHeaderLine('User-Agent'),
                $supplierId,
            );
        }
        return Json::ok($response, ['unit' => $result['unit'], 'updated' => $result['updated']]);
    }

    public function locations(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $warehouseId = isset($request->getQueryParams()['warehouse_id']) ? (int) $request->getQueryParams()['warehouse_id'] : null;
        return Json::ok($response, $this->tracking->locations($supplierId, $warehouseId));
    }

    public function locationContents(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $location = $this->tracking->location($supplierId, (int) $args['id']);
        if ($location === null) return Json::error($response, 'not_found', 'Lokace nenalezena.', 404);

        return $this->locationPage($request, $response, $supplierId, (int) $location['id'], null, (int) $location['warehouse_id']);
    }

    public function itemLocations(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $itemId = (int) $args['id'];
        if ($this->tracking->item($supplierId, $itemId) === null) {
            return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        }
        try {
            $warehouseId = StockLocationService::id($request->getQueryParams()['warehouse_id'] ?? null);
        } catch (StockException $e) {
            return Json::error($response, 'validation_failed', 'ID skladu musí být kladné celé číslo.', 422);
        }
        if ($warehouseId !== null && $this->warehouses->find($supplierId, $warehouseId) === null) {
            return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        }

        return $this->locationPage($request, $response, $supplierId, null, $itemId, $warehouseId);
    }

    private function locationPage(Request $request, Response $response, int $supplierId, ?int $locationId, ?int $itemId, ?int $warehouseId): Response
    {
        $page = Pagination::fromQuery($request->getQueryParams());
        $result = $this->locationBalances->balances($supplierId, $locationId, $itemId, $warehouseId, $page['per_page'], $page['offset']);
        return Json::ok($response, Pagination::envelope($result['rows'], $result['total'], $page['page'], $page['per_page']));
    }

    public function saveLocation(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        $existing = null;
        if ($id !== null) {
            $existing = $this->tracking->location($supplierId, $id);
            if ($existing === null) return Json::error($response, 'not_found', 'Lokace nenalezena.', 404);
        }
        $warehouseId = (int) (array_key_exists('warehouse_id', $body) ? $body['warehouse_id'] : ($existing['warehouse_id'] ?? 0));
        $code = trim((string) (array_key_exists('code', $body) ? $body['code'] : ($existing['code'] ?? '')));
        $name = trim((string) (array_key_exists('name', $body) ? $body['name'] : ($existing['name'] ?? '')));
        if ($warehouseId <= 0 || $code === '' || $name === '' || mb_strlen($code) > 50 || mb_strlen($name) > 100) {
            return Json::error($response, 'validation_failed', 'Sklad, kód a název lokace jsou povinné.', 422);
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM warehouses WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $warehouseId]);
        if ($stmt->fetchColumn() === false) return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        if ($existing !== null && (int) $existing['warehouse_id'] !== $warehouseId) {
            return Json::error($response, 'invalid_location', 'Lokaci nelze přesunout do jiného skladu.', 409);
        }
        $isActive = array_key_exists('is_active', $body)
            ? (bool) ($body['is_active'] ?? true)
            : (bool) ($existing['is_active'] ?? true);
        try {
            $saved = $this->tracking->saveLocation($supplierId, $warehouseId, $id, $code, $name, $isActive);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') return Json::error($response, 'location_code_taken', 'Kód lokace už ve skladu existuje.', 409);
            throw $e;
        }
        if ($saved === 0) return Json::error($response, 'not_found', 'Lokace nenalezena.', 404);
        return Json::ok($response, $this->tracking->location($supplierId, $saved), $id === null ? 201 : 200);
    }
}
