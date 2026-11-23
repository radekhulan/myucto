<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

/**
 * Číselné řady interního čísla přijatých dokladů — jediné místo, které ví, jakou
 * šablonou a v jaké řadě počítadla se doklad daného typu čísluje.
 *
 * Režim `shared` (výchozí, dosavadní chování): všechny typy dokladů jedou jednou řadou
 * podle `supplier.purchase_invoice_number_format`. Režim `by_kind`: faktura zůstává
 * na `purchase_invoice_number_format` a v téže řadě počítadla, ostatní typy mají vlastní
 * šablonu (`purchase_{kind}_number_format`, prázdná = vestavěná výchozí) i vlastní řadu.
 * Řada typu se v `purchase_invoice_counters.period` odliší předponou klíče
 * (`receipt:202610`), takže primární klíč počítadla ani pojistka proti dírám
 * ({@see \MyInvoice\Service\Invoice\NumberSeriesGapGuard}) se nemění.
 */
final class PurchaseNumberSeries
{
    public const MODE_SHARED  = 'shared';
    public const MODE_BY_KIND = 'by_kind';

    public const DEFAULT_TEMPLATE = '{PP}{YY}{MM}{CCC}';

    /** Typy dokladu s vlastní řadou v režimu by_kind; faktura jede na výchozí šabloně. */
    public const KIND_DEFAULT_TEMPLATES = [
        'receipt'      => 'UC{YY}{MM}{CCC}',
        'credit_note'  => 'DD{YY}{MM}{CCC}',
        'advance'      => 'ZD{YY}{MM}{CCC}',
        'tax_document' => 'DZ{YY}{MM}{CCC}',
    ];

    public const KINDS = ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'];

    /** Daňové prefixy {PP} (PurchaseInvoiceRepository::varsymbolPrefix). */
    public const TAX_PREFIXES = ['PF', 'PN', 'KU', 'KN', 'KR', 'RN', 'NU', 'NN'];

    public static function formatColumn(string $kind): string
    {
        return $kind === 'invoice' || !isset(self::KIND_DEFAULT_TEMPLATES[$kind])
            ? 'purchase_invoice_number_format'
            : "purchase_{$kind}_number_format";
    }

    /** @return list<string> sloupce supplier, které řady čtou */
    public static function supplierColumns(): array
    {
        $cols = ['purchase_invoice_number_format', 'purchase_invoice_numbering'];
        foreach (array_keys(self::KIND_DEFAULT_TEMPLATES) as $kind) {
            $cols[] = self::formatColumn($kind);
        }
        return $cols;
    }

    public static function normalizeKind(?string $kind): string
    {
        return in_array($kind, self::KINDS, true) ? (string) $kind : 'invoice';
    }

    /**
     * Řada, do které doklad daného typu patří.
     *
     * @param array<string,mixed> $supplier řádek supplier se sloupci {@see supplierColumns()}
     * @return array{key: string, template: string}  key = '' pro společnou řadu / faktury
     */
    public static function resolve(array $supplier, ?string $kind): array
    {
        $shared = trim((string) ($supplier['purchase_invoice_number_format'] ?? ''));
        $shared = $shared !== '' ? $shared : self::DEFAULT_TEMPLATE;
        $kind = self::normalizeKind($kind);
        if (($supplier['purchase_invoice_numbering'] ?? self::MODE_SHARED) !== self::MODE_BY_KIND || $kind === 'invoice') {
            return ['key' => '', 'template' => $shared];
        }
        $own = trim((string) ($supplier[self::formatColumn($kind)] ?? ''));
        return ['key' => $kind, 'template' => $own !== '' ? $own : self::KIND_DEFAULT_TEMPLATES[$kind]];
    }

    /**
     * Všechny řady firmy (v režimu shared jediná).
     *
     * @param array<string,mixed> $supplier
     * @return array<string, array{template: string, kinds: list<string>}> klíč řady → šablona a typy
     */
    public static function all(array $supplier): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $s = self::resolve($supplier, $kind);
            $out[$s['key']] ??= ['template' => $s['template'], 'kinds' => []];
            $out[$s['key']]['kinds'][] = $kind;
        }
        return $out;
    }

    /** Klíč počítadla: období řady, u vlastní řady typu s předponou `{kind}:`. */
    public static function counterKey(string $seriesKey, string $template, string $period): string
    {
        if (str_contains($template, '{MM}')) {
            $scope = $period;
        } elseif (str_contains($template, '{YYYY}') || str_contains($template, '{YY}')) {
            $scope = substr($period, 0, 4);
        } else {
            $scope = 'ALL';
        }
        return $seriesKey === '' ? $scope : "{$seriesKey}:{$scope}";
    }

    /**
     * Regex, který z interního čísla vyparsuje čítač (skupina 1). Datum dosazené za
     * $period (YYYYMM), {PP} jen jako skutečný daňový prefix — jinak by šablona faktur
     * `{PP}{YY}{MM}{CCC}` brala za své i čísla řady účtenek `UC{YY}{MM}{CCC}`.
     * Null = šablona bez čítače.
     */
    public static function counterRegex(string $template, string $period): ?string
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return null;
        }
        $withDate = strtr($template, [
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        $parts = preg_split('/(\{PP\}|\{C+\})/', $withDate, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $p) {
            $regex .= match (true) {
                $p === '{PP}'                       => '(?:' . implode('|', self::TAX_PREFIXES) . ')',
                (bool) preg_match('/^\{C+\}$/', $p) => '(\d+)',
                default                             => preg_quote($p, '/'),
            };
        }
        return '/^' . $regex . '$/';
    }

    /**
     * Období, které nese číslo podle šablony (opak {@see render()}): rok a měsíc z čísla,
     * null u části, kterou šablona nemá. Null = číslo šabloně neodpovídá (ruční nebo cizí
     * číslo, číslo jiné řady).
     *
     * @return array{year: ?string, month: ?string}|null rok YYYY, měsíc MM
     */
    public static function numberPeriod(string $template, string $number): ?array
    {
        if (!preg_match('/\{C+\}/', $template)) {
            return null;
        }
        $parts = preg_split('/(\{PP\}|\{YYYY\}|\{YY\}|\{MM\}|\{C+\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $p) {
            $regex .= match (true) {
                $p === '{PP}'                       => '(?:' . implode('|', self::TAX_PREFIXES) . ')',
                $p === '{YYYY}'                     => '(?<y4>\d{4})',
                $p === '{YY}'                       => '(?<y2>\d{2})',
                $p === '{MM}'                       => '(?<mm>0[1-9]|1[0-2])',
                (bool) preg_match('/^\{C+\}$/', $p) => '\d+',
                default                             => preg_quote($p, '/'),
            };
        }
        if (!preg_match('/^' . $regex . '$/J', $number, $m)) {
            return null;
        }
        $year = ($m['y4'] ?? '') !== '' ? $m['y4'] : (($m['y2'] ?? '') !== '' ? '20' . $m['y2'] : null);
        return ['year' => $year, 'month' => ($m['mm'] ?? '') !== '' ? $m['mm'] : null];
    }

    /** Vyrenderuje číslo ze šablony: {PP} prefix, {YYYY}/{YY}/{MM} z období, {C+} čítač. */
    public static function render(string $template, string $prefix, string $period, int $counter): string
    {
        $out = strtr($template, [
            '{PP}'   => $prefix,
            '{YYYY}' => substr($period, 0, 4),
            '{YY}'   => substr($period, 2, 2),
            '{MM}'   => substr($period, 4, 2),
        ]);
        return preg_replace_callback('/\{(C+)\}/', static function (array $m) use ($counter): string {
            return str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT);
        }, $out) ?? $out;
    }

    /**
     * Dvojice řad, jejichž čísla se mohou plést (číslo jedné řady projde vzorem druhé).
     * Prázdný výsledek = řady jsou od sebe poznatelné. Kontroluje se vzorkem čísla
     * každé řady proti vzoru ostatních pro jeden pevný měsíc.
     *
     * @param array<string,mixed> $supplier
     * @return list<array{0: string, 1: string}> páry klíčů řad
     */
    public static function overlaps(array $supplier): array
    {
        $series = self::all($supplier);
        $period = '209912';
        $out = [];
        foreach ($series as $a => $sa) {
            foreach ($series as $b => $sb) {
                if ($a === $b) {
                    continue;
                }
                $regex = self::counterRegex($sb['template'], $period);
                if ($regex === null) {
                    continue;
                }
                foreach (['PF', 'NN'] as $prefix) {
                    if (preg_match($regex, self::render($sa['template'], $prefix, $period, 7))) {
                        $pair = [(string) min($a, $b), (string) max($a, $b)];
                        if (!in_array($pair, $out, true)) {
                            $out[] = $pair;
                        }
                        break;
                    }
                }
            }
        }
        return $out;
    }
}
