<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop;

use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CatalogJobAccessPolicy
{
    private const KINDS = [
        'price_recompute', 'stock_valuation', 'stock_cycle_prepare', 'sales_order_expiry', 'catalog_export',
        'catalog_bulk_preview', 'catalog_bulk_apply', 'catalog_bulk_restore',
        'catalog_import_stage', 'catalog_import_apply', 'catalog_import_media',
        'price_matrix_preview', 'price_matrix_apply',
        'product_content_transfer_preview', 'product_content_transfer_apply',
        'integration_reconcile',
        'stock_opening_import_stage', 'stock_opening_import_apply',
    ];

    public static function allows(Request $request, string $kind, bool $write = false, bool $audit = false): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            return false;
        }
        if ($kind === 'stock_cycle_prepare' || $kind === 'sales_order_expiry') {
            return !$write && RequestAuthorization::allows($request, 'stock', AccessLevel::READ);
        }
        if ($kind === 'stock_valuation') {
            return RequestAuthorization::allows($request, 'stock', $write ? AccessLevel::WRITE : AccessLevel::READ);
        }
        if (str_starts_with($kind, 'stock_opening_import_')) {
            return (!$write || RequestAuthorization::isSessionAuth($request))
                && RequestAuthorization::allows($request, 'stock.documents.write', AccessLevel::WRITE);
        }
        if ($kind === 'integration_reconcile') {
            return (!$write || RequestAuthorization::isSessionAuth($request))
                && RequestAuthorization::allows($request, 'eshop.integrations', $write ? AccessLevel::WRITE : AccessLevel::READ);
        }
        if (str_starts_with($kind, 'catalog_bulk_') || str_starts_with($kind, 'catalog_import_') || str_starts_with($kind, 'price_matrix_')
            || str_starts_with($kind, 'product_content_transfer_')) {
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
