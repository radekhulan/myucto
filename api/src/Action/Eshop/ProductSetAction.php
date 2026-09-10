<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Service\Eshop\EshopException;
use MyInvoice\Service\Eshop\Sets\ProductSetService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ProductSetAction
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    public function __construct(private readonly Connection $db, private readonly ProductSetService $sets) {}

    public function get(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        try {
            return Json::ok($response, $this->sets->configuration($this->currentSupplierId($request), (int) $args['id']));
        } catch (EshopException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, true, $error)) return $error;
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_int($body['row_version'] ?? null) || $body['row_version'] < 0 || !is_array($body['definition'] ?? null)) {
            return Json::error($response, 'validation_failed', 'Zadejte verzi a definici setu.', 422);
        }
        try {
            return Json::ok($response, $this->sets->save($this->currentSupplierId($request), (int) $args['id'], $body['row_version'], $body['definition']));
        } catch (EshopException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
    }

    public function quote(Request $request, Response $response, array $args): Response
    {
        if (!$this->authorize($request, $response, false, $error)) return $error;
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_string($body['currency_code'] ?? null) || !is_string($body['quantity'] ?? null) || !is_array($body['selections'] ?? [])) {
            return Json::error($response, 'validation_failed', 'Zadejte měnu, množství a konfiguraci.', 422);
        }
        try {
            return Json::ok($response, $this->sets->quote($this->currentSupplierId($request), (int) $args['id'], $body['currency_code'], $body['quantity'], $body['selections'] ?? []));
        } catch (EshopException $error) {
            return Json::error($response, $error->errorCode, $error->getMessage(), $error->httpStatus, $error->details);
        }
    }

    private function authorize(Request $request, Response $response, bool $write, ?Response &$error): bool
    {
        if (!RequestAuthorization::isSessionAuth($request)) {
            $error = Json::error($response, 'forbidden', 'Tato operace vyžaduje přihlášenou relaci.', 403);
            return false;
        }
        if (!$this->requirePermission($request, $response, $write ? 'eshop.write' : 'eshop.read', $write ? AccessLevel::WRITE : AccessLevel::READ, $error)) return false;
        if ($write && !$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $error)) return false;
        return $this->guardStockEnabled($this->db, $this->currentSupplierId($request), $response, $error);
    }
}
