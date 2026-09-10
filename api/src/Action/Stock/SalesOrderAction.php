<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Stock\SalesOrderException;
use MyInvoice\Service\Stock\SalesOrderExpiryJobService;
use MyInvoice\Service\Stock\SalesOrderInvoiceService;
use MyInvoice\Service\Stock\SalesOrderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SalesOrderAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly SalesOrderService $orders,
        private readonly SalesOrderInvoiceService $invoices,
        private readonly SalesOrderExpiryJobService $expiryJobs,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->stock($request, $response, $error)) return $error;
        $q = $request->getQueryParams();
        return Json::ok($response, $this->orders->list($this->currentSupplierId($request), [
            'q' => (string) ($q['q'] ?? ''),
            'commercial_status' => (string) ($q['commercial_status'] ?? ''),
            'payment_status' => (string) ($q['payment_status'] ?? ''),
            'fulfillment_status' => (string) ($q['fulfillment_status'] ?? ''),
            'shortage' => !empty($q['shortage']),
        ], (int) ($q['limit'] ?? 100), (int) ($q['offset'] ?? 0)));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->stock($request, $response, $error)) return $error;
        $order = $this->orders->detail($this->currentSupplierId($request), (int) $args['id']);
        return $order === null ? Json::error($response, 'not_found', 'Objednávka nenalezena.', 404) : Json::ok($response, $order);
    }

    public function shortages(Request $request, Response $response): Response
    {
        if (!$this->stock($request, $response, $error)) return $error;
        $rows = $this->orders->shortageQueue($this->currentSupplierId($request), (int) ($request->getQueryParams()['limit'] ?? 200));
        return Json::ok($response, ['items' => $rows, 'total' => count($rows)]);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        try {
            return Json::ok($response, $this->orders->create(
                $this->currentSupplierId($request),
                (array) ($request->getParsedBody() ?? []),
                $this->userId($request),
            ), 201);
        } catch (\Throwable $exception) {
            return $this->error($response, $exception);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            return Json::ok($response, $this->orders->update(
                $this->currentSupplierId($request), (int) $args['id'],
                (int) ($body['row_version'] ?? 0), $body,
            ));
        } catch (\Throwable $exception) {
            return $this->error($response, $exception);
        }
    }

    public function confirm(Request $request, Response $response, array $args): Response
    {
        return $this->operation($request, $response, $args, fn (int $supplierId, int $id, array $body): array =>
            $this->orders->confirm($supplierId, $id, $this->idempotencyKey($request, $body))
        );
    }

    public function cancel(Request $request, Response $response, array $args): Response
    {
        return $this->operation($request, $response, $args, fn (int $supplierId, int $id, array $body): array =>
            $this->orders->cancel($supplierId, $id, $this->idempotencyKey($request, $body))
        );
    }

    public function payment(Request $request, Response $response, array $args): Response
    {
        return $this->operation($request, $response, $args, fn (int $supplierId, int $id, array $body): array =>
            $this->orders->setPaymentStatus($supplierId, $id, (string) ($body['payment_status'] ?? ''), (int) ($body['row_version'] ?? 0))
        );
    }

    public function invoice(Request $request, Response $response, array $args): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        if (!RequestAuthorization::allows($request, 'invoices.create', AccessLevel::WRITE)) return Json::error($response, 'forbidden', 'Nemáte oprávnění vytvářet faktury.', 403);
        $body = (array) ($request->getParsedBody() ?? []);
        try {
            return Json::ok($response, $this->invoices->createDraft(
                $this->currentSupplierId($request), (int) $args['id'], (int) $this->userId($request),
                $this->idempotencyKey($request, $body),
            ), 201);
        } catch (\Throwable $exception) {
            return $this->error($response, $exception);
        }
    }

    public function createReturn(Request $request, Response $response, array $args): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        try {
            return Json::ok($response, $this->orders->createReturn(
                $this->currentSupplierId($request), (int) $args['id'],
                (array) ($request->getParsedBody() ?? []), $this->userId($request),
            ), 201);
        } catch (\Throwable $exception) {
            return $this->error($response, $exception);
        }
    }

    public function enqueueExpiry(Request $request, Response $response): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        return Json::ok($response, $this->expiryJobs->enqueue($this->currentSupplierId($request), $this->userId($request)), 202);
    }

    public function expiryJob(Request $request, Response $response, array $args): Response
    {
        if (!$this->stock($request, $response, $error)) return $error;
        $job = $this->expiryJobs->find($this->currentSupplierId($request), (int) $args['id']);
        return $job === null ? Json::error($response, 'not_found', 'Úloha nenalezena.', 404) : Json::ok($response, $job);
    }

    public function runExpiry(Request $request, Response $response): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        return Json::ok($response, $this->expiryJobs->runNextBatch($this->currentSupplierId($request)) ?? ['status' => 'idle']);
    }

    private function operation(Request $request, Response $response, array $args, callable $operation): Response
    {
        if (!$this->write($request, $response, $error)) return $error;
        try {
            return Json::ok($response, $operation(
                $this->currentSupplierId($request), (int) $args['id'], (array) ($request->getParsedBody() ?? []),
            ));
        } catch (\Throwable $exception) {
            return $this->error($response, $exception);
        }
    }

    private function stock(Request $request, Response $response, ?Response &$error): bool
    {
        return $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $error);
    }

    private function write(Request $request, Response $response, ?Response &$error): bool
    {
        return $this->requirePermission($request, $response, 'stock.orders.write', AccessLevel::WRITE, $error)
            && $this->stock($request, $response, $error);
    }

    private function idempotencyKey(Request $request, array $body): string
    {
        return $request->getHeaderLine('Idempotency-Key') ?: (string) ($body['idempotency_key'] ?? '');
    }

    private function error(Response $response, \Throwable $exception): Response
    {
        if ($exception instanceof SalesOrderException) {
            return Json::error($response, 'sales_order.' . $exception->errorCode, $exception->getMessage(), $exception->httpStatus, ['items' => $exception->details]);
        }
        if ($exception instanceof \PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
            return Json::error($response, 'sales_order.conflict', 'Objednávka nebo operace už existuje.', 409);
        }
        throw $exception;
    }
}
