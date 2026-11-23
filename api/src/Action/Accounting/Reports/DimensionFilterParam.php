<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionFilterQuery;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Query parametry filtru sestavy na dimenzi: `dimension_value_id` (hodnota, nebo víc hodnot
 * oddělených čárkou) a volitelně `dimension_descendants=0` (jen hodnoty samy, bez podřízených).
 * Hodnoty různých typů musí platit zároveň, hodnoty stejného typu stačí kterákoli.
 * Parametr čte {@see DimensionFilterQuery}, tady se jen převádí chyba na odpověď.
 */
trait DimensionFilterParam
{
    /** @return DimensionFilter|null|false false = chyba v $err */
    protected function dimensionFilterParam(DimensionService $dimensions, Request $request, Response $response, int $supplierId, ?Response &$err): DimensionFilter|null|false
    {
        $err = null;
        $q = $request->getQueryParams();
        try {
            return DimensionFilterQuery::parse($dimensions, $supplierId, $q['dimension_value_id'] ?? null, $q['dimension_descendants'] ?? null);
        } catch (DimensionException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return false;
        }
    }
}
