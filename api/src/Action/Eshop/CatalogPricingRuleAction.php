<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eshop;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eshop\Pricing\CatalogPricingPolicyService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogPricingRuleAction
{
    use CatalogPricingActionSupport;

    public function __construct(
        private readonly Connection $db,
        private readonly CatalogPricingPolicyService $pricing,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        return Json::ok($response, $this->pricing->rules($this->currentSupplierId($request)));
    }

    public function create(Request $request, Response $response): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $result = $this->pricing->saveRule(
                $this->currentSupplierId($request),
                null,
                (array) ($request->getParsedBody() ?? []),
            );
            $this->logPricing($request, 'eshop.pricing_rule_created', (int) $result['rule']['id'], [
                'match_type' => $result['rule']['match_type'],
                'match_id' => $result['rule']['match_id'],
            ]);
            return Json::ok($response, $result, 201);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $result = $this->pricing->saveRule(
                $this->currentSupplierId($request),
                (int) $args['id'],
                (array) ($request->getParsedBody() ?? []),
            );
            $this->logPricing($request, 'eshop.pricing_rule_updated', (int) $args['id'], [
                'match_type' => $result['rule']['match_type'],
                'match_id' => $result['rule']['match_id'],
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $result = $this->pricing->deleteRule(
                $this->currentSupplierId($request),
                (int) $args['id'],
            );
            $this->logPricing($request, 'eshop.pricing_rule_deleted', (int) $args['id'], []);
            return Json::ok($response, $result);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }
}
