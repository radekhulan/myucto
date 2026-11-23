<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Filtr výpisu ostatních pohledávek a závazků z query, sdílený výpisem
 * (`GET /api/accounting/other-items`) a knihou (`GET /api/accounting/other-items/book`).
 *
 * `filters` jde do {@see \MyInvoice\Repository\OtherItemRepository} (WHERE ručních položek),
 * `window_from`/`window_to` je okno splatnosti převzatých předpisů z mezd a daní, které platí vždy.
 */
final class OtherItemListFilters
{
    /**
     * `default_window` = bez data v požadavku platí výchozí okno splatnosti (kniha ho uvádí v hlavičce).
     *
     * @return array{filters: array<string,mixed>, window_from: string, window_to: string, default_window: bool}|array{error: string, message: string}
     */
    public static function fromRequest(Request $request): array
    {
        $q = $request->getQueryParams();
        $filters = [];
        foreach (['side', 'status', 'kind', 'q', 'from', 'to', 'sort_by', 'sort_dir'] as $field) {
            if (isset($q[$field]) && is_scalar($q[$field])) $filters[$field] = trim((string) $q[$field]);
        }
        if (isset($q['kind_id']) && is_scalar($q['kind_id']) && trim((string) $q['kind_id']) !== '') {
            $kindId = filter_var($q['kind_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($kindId === false) {
                return ['error' => 'other_items.error.invalid_kind', 'message' => 'Neplatný druh dokladu.'];
            }
            $filters['kind_id'] = $kindId;
        }
        $hasFrom = isset($filters['from']);
        $hasTo = isset($filters['to']);
        $from = $hasFrom ? $filters['from'] : ($hasTo ? '1000-01-01' : date('Y-m-d', strtotime('-1 year')));
        $to = $hasTo ? $filters['to'] : ($hasFrom ? '9999-12-31' : date('Y-m-d', strtotime('+1 year')));
        $fromDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($fromDate === false || $fromDate->format('Y-m-d') !== $from
            || $toDate === false || $toDate->format('Y-m-d') !== $to || $from > $to) {
            return ['error' => 'other_items.error.invalid_date', 'message' => 'Neplatný rozsah splatnosti.'];
        }
        // Bez data platí okno splatnosti rok zpět až rok dopředu, aby součty a počet nešly přes celou
        // historii firmy. Výjimky jen pro ruční položky: výběr Otevřené nemá dolní mez (starý neuhrazený
        // dluh z něj nesmí zmizet) a hledání okno nemá (číslo dokladu z předloňska se musí najít).
        // Převzaté předpisy z mezd a daní mají okno vždy, hledání se na ně nevztahuje.
        $searching = ($filters['q'] ?? '') !== '' && !$hasFrom && !$hasTo;
        $filters['from'] = $searching || (($filters['status'] ?? '') === 'open' && !$hasFrom) ? null : $from;
        $filters['to'] = $searching ? null : $to;

        return ['filters' => $filters, 'window_from' => $from, 'window_to' => $to, 'default_window' => !$hasFrom && !$hasTo];
    }
}
