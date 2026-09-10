<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\ProductContentTransferService;
use MyInvoice\Service\Eshop\ProductMasterService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductMasterAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(
        private readonly Connection $db,
        private readonly ProductMasterService $masters,
        private readonly ProductContentTransferService $transfers,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->list($supplierId, $request->getQueryParams()));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->detail($supplierId, (int) $args['id']));
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->create($supplierId, $this->body($request)), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->update($supplierId, (int) $args['id'], $this->body($request)));
    }

    public function status(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        $body = $this->body($request);
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->changeStatus(
            $supplierId, (int) $args['id'], $this->version($body, 'row_version'), $args['operation'] === 'archive' ? 'archived' : 'active'
        ));
    }

    public function attach(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->attach($supplierId, (int) $args['id'], $this->body($request)));
    }

    public function updateVariant(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->updateVariant(
            $supplierId, (int) $args['id'], (int) $args['itemId'], $this->body($request)
        ));
    }

    public function detachPreview(Request $request, Response $response, array $args): Response
    {
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->detachPreview($supplierId, (int) $args['id'], (int) $args['itemId']));
    }

    public function detach(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->masters->detach(
            $supplierId, (int) $args['id'], (int) $args['itemId'], $this->body($request)
        ));
    }

    public function transferPreview(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->transfers->preview(
            $supplierId, (int) $args['id'], $this->body($request), $this->userId($request)
        ), 202);
    }

    public function transferApply(Request $request, Response $response, array $args): Response
    {
        if (!$this->writeAllowed($request, $response, $err)) {
            return $err;
        }
        return $this->run($request, $response, fn (int $supplierId): array => $this->transfers->apply(
            $supplierId, (int) $args['id'], $this->body($request), $this->userId($request)
        ), 202);
    }

    private function run(Request $request, Response $response, callable $callback, int $status = 200): Response
    {
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $err)) {
            return $err;
        }
        try {
            return Json::ok($response, $callback($supplierId), $status);
        } catch (EshopException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }
    }

    private function writeAllowed(Request $request, Response $response, ?Response &$error): bool
    {
        return $this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $error)
            && $this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $error);
    }

    private function body(Request $request): array
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Požadavek musí obsahovat objekt JSON.');
        }
        return $body;
    }

    private function version(array $body, string $field): int
    {
        if (!is_int($body[$field] ?? null) || $body[$field] < 1) {
            throw new \InvalidArgumentException($field . ' musí být kladné celé číslo.');
        }
        return $body[$field];
    }
}
