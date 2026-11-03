<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Middleware\SupplierScopeMiddleware;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Filtry seznamu vystavených a přijatých faktur z query stringu (`q`, `filter[...]`,
 * `sort_key`, `sort_dir`). Jediné místo, odkud je čte seznam i kniha faktur, aby
 * export vybral přesně to, co uživatel vidí v seznamu.
 *
 * Vrací pole filtrů pro `InvoiceRepository::listWhere()` / `PurchaseInvoiceRepository::listWhere()`,
 * nebo text validační chyby (422).
 */
final class InvoiceListFilters
{
    /** @return array<string,mixed>|string */
    public static function issued(Request $request): array|string
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);

        // Neuhrazené K DATU X (task #4) — na rozdíl od `unpaid_only` (dnešní status) jde
        // o historický dotaz: doklad vystavený do X, u kterého k X nebyl uhrazen celý
        // amount_to_pay. Chybný formát je 422, ne tiché "nefiltrovat" (uživatel by si
        // myslel, že vidí správný řez).
        $unpaidAsOf = self::unpaidAsOf($filter);
        if ($unpaidAsOf === false) {
            return 'filter[unpaid_as_of] musí být datum (YYYY-MM-DD).';
        }

        $filters = [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']      ?? null,
            'type'          => $filter['type']        ?? null,
            'client_id'     => $filter['client_id']   ?? null,
            'project_id'    => $filter['project_id']  ?? null,
            'year'          => $filter['year']        ?? null,
            'month'         => $filter['month']       ?? null,
            'date_from'     => $filter['date_from']   ?? null,
            'date_to'       => $filter['date_to']     ?? null,
            'currency'      => $filter['currency']    ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'unpaid_as_of'  => $unpaidAsOf,
            'booked'        => $filter['booked']      ?? null,
            // Kategorie tržby, čárkou oddělené seznamy: `revenue_category_id` = ponechat jen
            // tyhle, `revenue_category_exclude` = tyhle skrýt. Sentinel `none` = doklad bez
            // kategorie. Ověření vlastnictví i sémantiku NULL řeší repository, ať filtr
            // a jeho SQL nemají dvě různá pravidla.
            'revenue_category_id'      => $filter['revenue_category_id']      ?? null,
            'revenue_category_exclude' => $filter['revenue_category_exclude'] ?? null,
            // Doklady s řádkem k ručnímu posouzení místa plnění (OSS). Rozsah říká, kde
            // řádek leží: 'domestic' = mimo OSS (tiše v přiznání na ř. 1/2), 'oss' = v OSS
            // podání s otazníkem nad zemí či typem sazby, 'any' = obojí. Legacy `1`/`true`
            // se mapuje na 'any' (viz InvoiceRepository::ossReviewScope()) — bez rozsahu
            // by uživatel viděl jen půlku sporných řádků a druhou by měl jen v reportu
            // importu, který po zavření stránky zmizí.
            'oss_review'  => is_scalar($filter['oss_review'] ?? null) && !empty($filter['oss_review'])
                ? (string) $filter['oss_review']
                : null,
        ] + self::common($request, $q, $filter);

        return self::splitLists($filters, ['status', 'type']);
    }

    /** @return array<string,mixed>|string */
    public static function purchase(Request $request): array|string
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);
        foreach (['has_visual_document', 'has_structured_source'] as $key) {
            if (array_key_exists($key, $filter) && !in_array($filter[$key], ['0', '1'], true)) {
                return "filter[{$key}] musí být 0 nebo 1.";
            }
        }

        // Neuhrazené K DATU X (task #4) — historický protějšek `unpaid_only`.
        $unpaidAsOf = self::unpaidAsOf($filter);
        if ($unpaidAsOf === false) {
            return 'filter[unpaid_as_of] musí být datum (YYYY-MM-DD).';
        }

        $filters = [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']        ?? null,
            'document_kind' => $filter['document_kind'] ?? null,
            'vendor_id'     => $filter['vendor_id']     ?? null,
            'project_id'    => $filter['project_id']    ?? null,
            'year'          => $filter['year']          ?? null,
            'month'         => $filter['month']         ?? null,
            'date_from'     => $filter['date_from']     ?? null,
            'date_to'       => $filter['date_to']       ?? null,
            'currency'      => $filter['currency']      ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'unpaid_as_of'  => $unpaidAsOf,
            'unmatched'     => !empty($filter['unmatched']),
            'needs_review'  => !empty($filter['needs_review']),
            'approval_status' => is_scalar($filter['approval_status'] ?? $q['approval_status'] ?? null)
                ? (string) ($filter['approval_status'] ?? $q['approval_status']) : null,
            'paid_shortfall' => !empty($filter['paid_shortfall']),
            'payment_ordered' => $filter['payment_ordered'] ?? null,
            'booked'        => $filter['booked'] ?? null,
            'has_visual_document' => $filter['has_visual_document'] ?? null,
            'has_structured_source' => $filter['has_structured_source'] ?? null,
            'import_batch_id' => $filter['import_batch_id'] ?? null,
        ] + self::common($request, $q, $filter);

        return self::splitLists($filters, ['status', 'document_kind']);
    }

    /**
     * @param array<string,mixed> $q
     * @param array<string,mixed> $filter
     * @return array<string,mixed>
     */
    private static function common(Request $request, array $q, array $filter): array
    {
        return [
            'supplier_id'    => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
            'group_by_month' => !is_scalar($filter['group_by_month'] ?? null) || (string) $filter['group_by_month'] !== '0',
            'sort_key'       => is_scalar($q['sort_key'] ?? null) ? (string) $q['sort_key'] : '',
            'sort_dir'       => is_scalar($q['sort_dir'] ?? null) ? (string) $q['sort_dir'] : '',
        ];
    }

    /** @param array<string,mixed> $filter */
    private static function unpaidAsOf(array $filter): string|false|null
    {
        $value = isset($filter['unpaid_as_of']) && is_scalar($filter['unpaid_as_of'])
            ? trim((string) $filter['unpaid_as_of'])
            : '';
        if ($value === '') {
            return null;
        }
        return IsoDate::isValid($value) ? $value : false;
    }

    /**
     * Multi-select se posílá čárkou oddělený.
     *
     * @param array<string,mixed> $filters
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function splitLists(array $filters, array $keys): array
    {
        foreach ($keys as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }
        return $filters;
    }
}
