<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockTrackingRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Stock\ExactUnitConversion;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class StockTrackingAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly StockTrackingRepository $tracking,
    ) {}

    public function item(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $item = $this->tracking->item($supplierId, (int) $args['id']);
        if ($item === null) return Json::error($response, 'not_found', 'Skladová karta nenalezena.', 404);
        return Json::ok($response, [
            'tracking_mode' => $item['tracking_mode'],
            'base_unit' => $item['unit'],
            'units' => $this->tracking->units($supplierId, (int) $item['id']),
            'inventory' => $this->tracking->inventory($supplierId, (int) $item['id']),
            'history' => $this->tracking->history($supplierId, (int) $item['id']),
        ]);
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

    public function locations(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $warehouseId = isset($request->getQueryParams()['warehouse_id']) ? (int) $request->getQueryParams()['warehouse_id'] : null;
        return Json::ok($response, $this->tracking->locations($supplierId, $warehouseId));
    }

    public function saveLocation(Request $request, Response $response, array $args = []): Response
    {
        if (!$this->requirePermission($request, $response, 'stock', AccessLevel::WRITE, $err)) return $err;
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $warehouseId = (int) ($body['warehouse_id'] ?? 0);
        $code = trim((string) ($body['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        if ($warehouseId <= 0 || $code === '' || $name === '' || mb_strlen($code) > 50 || mb_strlen($name) > 100) {
            return Json::error($response, 'validation_failed', 'Sklad, kód a název lokace jsou povinné.', 422);
        }
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM warehouses WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId, $warehouseId]);
        if ($stmt->fetchColumn() === false) return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null) {
            $existing = $this->tracking->location($supplierId, $id);
            if ($existing === null) return Json::error($response, 'not_found', 'Lokace nenalezena.', 404);
            if ((int) $existing['warehouse_id'] !== $warehouseId) return Json::error($response, 'invalid_location', 'Lokaci nelze přesunout do jiného skladu.', 409);
        }
        try {
            $saved = $this->tracking->saveLocation($supplierId, $warehouseId, $id, $code, $name, (bool) ($body['is_active'] ?? true));
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') return Json::error($response, 'location_code_taken', 'Kód lokace už ve skladu existuje.', 409);
            throw $e;
        }
        if ($saved === 0) return Json::error($response, 'not_found', 'Lokace nenalezena.', 404);
        return Json::ok($response, $this->tracking->location($supplierId, $saved), $id === null ? 201 : 200);
    }
}
