<?php

declare(strict_types=1);

namespace MyInvoice\Action\Stock;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Sets\ProductAssemblyService;
use MyInvoice\Service\Stock\StockException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductAssemblyAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly ProductAssemblyService $assemblies) {}

    public function list(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        $params = $request->getQueryParams();
        return Json::ok($response, $this->assemblies->list($this->currentSupplierId($request), min(1000000, max(1, (int) ($params['page'] ?? 1))), min(100, max(1, (int) ($params['limit'] ?? 30)))));
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        $row = $this->assemblies->get($this->currentSupplierId($request), (int) $args['id']);
        return $row === null ? Json::error($response, 'not_found', 'Kompletace nenalezena.', 404) : Json::ok($response, $row);
    }

    public function create(Request $request, Response $response): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        $body = $request->getParsedBody();
        if (!is_array($body)) return Json::error($response, 'validation_failed', 'Neplatné zadání kompletace.', 422);
        try {
            return Json::ok($response, $this->assemblies->create($this->currentSupplierId($request), $body, $this->userId($request)), 201);
        } catch (EshopException|StockException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
    }

    public function reverse(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        try {
            return Json::ok($response, $this->assemblies->reverse($this->currentSupplierId($request), (int) $args['id'], $this->userId($request)));
        } catch (EshopException|StockException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
    }

    private function authorize(Request $request, Response $response, bool $write, ?Response &$error): bool
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::error($response, 'forbidden', 'Tato operace vyžaduje přihlášenou relaci.', 403);
            return false;
        }
        return $this->requirePermission($request, $response, $write ? 'stock.documents.write' : 'stock', $write ? AccessLevel::WRITE : AccessLevel::READ, $error)
            && $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $error);
    }
}
