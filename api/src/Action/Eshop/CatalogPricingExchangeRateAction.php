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

final class CatalogPricingExchangeRateAction
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
        $limit = filter_var($request->getQueryParams()['limit'] ?? 500, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            return Json::error($response, 'validation_failed', 'Neplatný limit kurzů.', 400);
        }
        return Json::ok($response, $this->pricing->exchangeRates(
            $this->currentSupplierId($request),
            $limit,
        ));
    }

    public function save(Request $request, Response $response): Response
    {
        if (($error = $this->requirePricingAccess($request, $response)) !== null) {
            return $error;
        }
        try {
            $result = $this->pricing->saveExchangeRate(
                $this->currentSupplierId($request),
                (array) ($request->getParsedBody() ?? []),
            );
            $rate = $result['exchange_rate'];
            $this->logPricing($request, 'eshop.pricing_exchange_rate_saved', null, [
                'currency_code' => $rate['currency_code'],
                'rate_date' => $rate['rate_date'],
                'source' => $rate['source'],
            ]);
            return Json::ok($response, $result);
        } catch (\Throwable $error) {
            return $this->pricingError($response, $error);
        }
    }
}
