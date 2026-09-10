<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Stock\CycleCountService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CycleCountAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly CycleCountService $cycles) {}

    public function list(Request $request, Response $response): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        return Json::ok($response, $this->cycles->list($supplierId));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->call($request, $response, fn (int $supplierId) => $this->cycles->get($supplierId, (int) $args['id']));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.take', AccessLevel::WRITE, $err)) return $err;
        return $this->call($request, $response, fn (int $supplierId) => $this->cycles->create($supplierId, (array) ($request->getParsedBody() ?? []), $this->userId($request)), 202);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.take', AccessLevel::WRITE, $err)) return $err;
        $body = (array) ($request->getParsedBody() ?? []);
        $lines = is_array($body['lines'] ?? null) ? $body['lines'] : [];
        return $this->call($request, $response, fn (int $supplierId) => $this->cycles->updateCounts($supplierId, (int) $args['id'], $lines));
    }

    public function close(Request $request, Response $response, array $args): Response
    {
        if (!$this->requirePermission($request, $response, 'stock.take', AccessLevel::WRITE, $err)) return $err;
        return $this->call($request, $response, fn (int $supplierId) => $this->cycles->close($supplierId, (int) $args['id'], $this->userId($request)));
    }

    private function call(Request $request, Response $response, callable $callback, int $status = 200): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) return $err;
        try { return Json::ok($response, $callback($supplierId), $status); }
        catch (StockException $e) { return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details); }
    }
}
