<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogJobAccessPolicy
{
    private const KINDS = [
        'price_recompute', 'stock_valuation', 'catalog_export',
        'catalog_bulk_preview', 'catalog_bulk_apply', 'catalog_bulk_restore',
        'catalog_import_stage', 'catalog_import_apply',
        'price_matrix_preview', 'price_matrix_apply',
    ];

    public static function allows(Request $request, string $kind, bool $write = false, bool $audit = false): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            return false;
        }
        if ($kind === 'stock_valuation') {
            return RequestAuthorization::allows($request, 'stock', $write ? AccessLevel::WRITE : AccessLevel::READ);
        }
        if (str_starts_with($kind, 'catalog_bulk_') || str_starts_with($kind, 'catalog_import_') || str_starts_with($kind, 'price_matrix_')) {
            if ($write && !RequestAuthorization::isSessionAuth($request)) {
                return false;
            }
            return RequestAuthorization::allows($request, 'eshop.write', AccessLevel::WRITE)
                && RequestAuthorization::allows($request, 'stock.items.write', AccessLevel::WRITE);
        }
        if ($write || ($audit && $kind !== 'catalog_export')) {
            return RequestAuthorization::allows($request, 'eshop.write', AccessLevel::WRITE);
        }
        return RequestAuthorization::allows($request, 'eshop', AccessLevel::READ);
    }

    public static function readableKinds(Request $request): array
    {
        return array_values(array_filter(self::KINDS, static fn (string $kind): bool => self::allows($request, $kind)));
    }
}
