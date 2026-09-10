<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\StockLevelRepository;
use MyInvoice\Repository\WarehouseRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Sklady — číselník (Epic SKLAD).
 *
 *   GET    /api/stock/warehouses          — seznam (vč. hodnoty skladu)
 *   POST   /api/stock/warehouses          — nový sklad
 *   GET    /api/stock/warehouses/{id}     — detail
 *   PUT    /api/stock/warehouses/{id}     — úprava
 *   DELETE /api/stock/warehouses/{id}     — smazání (jen bez stavu/pohybů; jinak deaktivace)
 */
final class WarehouseAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly WarehouseRepository $warehouses,
        private readonly StockLevelRepository $levels,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $q = $request->getQueryParams();
        $activeOnly = !empty($q['active']);
        $rows = $this->warehouses->listForSupplier($supplierId, $activeOnly);
        foreach ($rows as &$r) {
            $r['value'] = $this->levels->warehouseValue($supplierId, $r['id']);
        }
        unset($r);
        return Json::ok($response, $rows);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $warehouse = $this->warehouses->find($supplierId, (int) $args['id']);
        if ($warehouse === null) {
            return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        }
        $warehouse['value'] = $this->levels->warehouseValue($supplierId, $warehouse['id']);
        return Json::ok($response, $warehouse);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $validationErr] = $this->validate($response, $body);
        if ($validationErr !== null) {
            return $validationErr;
        }
        if ($this->warehouses->findByCode($supplierId, $data['code']) !== null) {
            return Json::error($response, 'warehouse_code_taken', 'Sklad s tímto kódem už existuje.', 409);
        }
        if (!empty($data['is_default'])) {
            $this->warehouses->clearDefault($supplierId);
        }
        $id = $this->warehouses->insert($supplierId, $data);
        $this->log($request, 'stock.warehouse_created', $id, ['code' => $data['code']]);
        $warehouse = $this->warehouses->find($supplierId, $id) ?? [];
        $warehouse['value'] = $this->levels->warehouseValue($supplierId, $id);
        return Json::ok($response, $warehouse, 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->warehouses->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        }
        $body = (array) ($request->getParsedBody() ?? []);
        [$data, $validationErr] = $this->validate($response, $body, $existing);
        if ($validationErr !== null) {
            return $validationErr;
        }
        $byCode = $this->warehouses->findByCode($supplierId, $data['code']);
        if ($byCode !== null && (int) $byCode['id'] !== $id) {
            return Json::error($response, 'warehouse_code_taken', 'Sklad s tímto kódem už existuje.', 409);
        }
        if (!empty($data['is_default']) && empty($existing['is_default'])) {
            $this->warehouses->clearDefault($supplierId);
        }
        $this->warehouses->update($supplierId, $id, $data);
        $this->log($request, 'stock.warehouse_updated', $id, ['code' => $data['code']]);
        $warehouse = $this->warehouses->find($supplierId, $id) ?? [];
        $warehouse['value'] = $this->levels->warehouseValue($supplierId, $id);
        return Json::ok($response, $warehouse);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!$this->requireWrite($request, $response, $err)) {
            return $err;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        $id = (int) $args['id'];
        $existing = $this->warehouses->find($supplierId, $id);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Sklad nenalezen.', 404);
        }
        if ($this->warehouses->hasStockOrMovements($supplierId, $id)) {
            return Json::error(
                $response,
                'warehouse_in_use',
                'Sklad nelze smazat — má nenulový stav nebo skladové pohyby. Deaktivujte jej místo mazání.',
                409,
                ['suggestion' => 'deactivate'],
            );
        }
        $this->warehouses->delete($supplierId, $id);
        $this->log($request, 'stock.warehouse_deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed>|null $existing
     * @return array{0:array<string,mixed>, 1:?Response}
     */
    private function validate(Response $response, array $body, ?array $existing = null): array
    {
        $code = trim((string) ($body['code'] ?? $existing['code'] ?? ''));
        $name = trim((string) ($body['name'] ?? $existing['name'] ?? ''));
        if ($code === '' || mb_strlen($code) > 20) {
            return [[], Json::error($response, 'validation_failed', 'Kód skladu je povinný (max 20 znaků).', 400)];
        }
        if ($name === '' || mb_strlen($name) > 100) {
            return [[], Json::error($response, 'validation_failed', 'Název skladu je povinný (max 100 znaků).', 400)];
        }
        $data = [
            'code'       => $code,
            'name'       => $name,
            'is_default' => array_key_exists('is_default', $body) ? (bool) $body['is_default'] : (bool) ($existing['is_default'] ?? false),
            'is_active'  => array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) ($existing['is_active'] ?? true),
            'is_sellable' => array_key_exists('is_sellable', $body) ? (bool) $body['is_sellable'] : (bool) ($existing['is_sellable'] ?? true),
            'note'       => array_key_exists('note', $body)
                ? (trim((string) $body['note']) !== '' ? trim((string) $body['note']) : null)
                : ($existing['note'] ?? null),
        ];
        if ($data['is_default'] && !$data['is_sellable']) {
            return [[], Json::error($response, 'validation_failed', 'Výchozí sklad musí být prodejný.', 400)];
        }
        return [$data, null];
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'warehouse',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
