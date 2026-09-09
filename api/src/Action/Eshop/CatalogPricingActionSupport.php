<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Action\Accounting\AccountingActionSupport;
use MyInvoice\Action\Stock\GuardsStockEnabled;
use MyInvoice\Http\Json;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eshop\EshopException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

trait CatalogPricingActionSupport
{
    use AccountingActionSupport;
    use GuardsStockEnabled;

    private function requirePricingAccess(Request $request, Response $response): ?Response
    {
        if (!$this->requirePermission($request, $response, 'eshop.write', AccessLevel::WRITE, $error)) {
            return $error;
        }
        if (!$this->requirePermission($request, $response, 'stock.items.write', AccessLevel::WRITE, $error)) {
            return $error;
        }
        $supplierId = $this->currentSupplierId($request);
        if (!$this->guardStockEnabled($this->db, $supplierId, $response, $error)) {
            return $error;
        }
        return null;
    }

    private function pricingError(Response $response, \Throwable $error): Response
    {
        if ($error instanceof EshopException) {
            return Json::error(
                $response,
                $error->errorCode,
                $error->getMessage(),
                $error->httpStatus,
                $error->details,
            );
        }
        if ($error instanceof \InvalidArgumentException) {
            return Json::error($response, 'validation_failed', $error->getMessage(), 400);
        }
        throw $error;
    }

    private function logPricing(Request $request, string $action, ?int $id, array $payload): void
    {
        $this->logger->log(
            $action,
            $this->userId($request),
            'stock_pricing',
            $id,
            $payload,
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()),
            $request->getHeaderLine('User-Agent'),
            $this->currentSupplierId($request),
        );
    }
}
