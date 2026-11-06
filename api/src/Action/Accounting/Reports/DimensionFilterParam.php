<?php

declare(strict_types=1);

namespace MyInvoice\Action\Accounting\Reports;

use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\Dimension\DimensionException;
use MyInvoice\Service\Accounting\Dimension\DimensionFilter;
use MyInvoice\Service\Accounting\Dimension\DimensionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Query parametry filtru sestavy na dimenzi: `dimension_value_id` (hodnota, nebo víc hodnot
 * oddělených čárkou) a volitelně `dimension_descendants=0` (jen hodnoty samy, bez podřízených).
 * Hodnoty různých typů musí platit zároveň, hodnoty stejného typu stačí kterákoli.
 */
trait DimensionFilterParam
{
    private const DIMENSION_FILTER_MAX_VALUES = 10;

    /** @return DimensionFilter|null|false false = chyba v $err */
    protected function dimensionFilterParam(DimensionService $dimensions, Request $request, Response $response, int $supplierId, ?Response &$err): DimensionFilter|null|false
    {
        $err = null;
        $q = $request->getQueryParams();
        $raw = $q['dimension_value_id'] ?? '';
        if (!is_string($raw) && !is_int($raw)) {
            $err = Json::error($response, 'dimension_value_invalid', 'Neplatná hodnota dimenze ve filtru.', 422);
            return false;
        }
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '0') {
            return null;
        }
        $valueIds = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^[1-9][0-9]{0,9}$/', $part) !== 1) {
                $err = Json::error($response, 'dimension_value_invalid', 'Neplatná hodnota dimenze ve filtru.', 422);
                return false;
            }
            $valueIds[] = (int) $part;
        }
        if (count($valueIds) > self::DIMENSION_FILTER_MAX_VALUES) {
            $err = Json::error($response, 'dimension_value_invalid', 'Filtr může obsahovat nejvýše ' . self::DIMENSION_FILTER_MAX_VALUES . ' hodnot dimenzí.', 422);
            return false;
        }
        try {
            return $dimensions->filters($supplierId, $valueIds, (string) ($q['dimension_descendants'] ?? '1') !== '0');
        } catch (DimensionException $e) {
            $err = Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus);
            return false;
        }
    }
}
