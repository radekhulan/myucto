<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

use MyInvoice\Service\Invoice\InvoiceNumberFormat;
use MyInvoice\Service\PurchaseInvoice\PurchaseNumberSeries;

/**
 * Šablona čísla nové (nativní) řady číselníku — čisté funkce bez DB.
 *
 * Placeholdery: {PREFIX} = prefix řady, {YYYY}/{YY}/{MM} včetně posunu (`{YY+1}`) se
 * stejnou sémantikou jako u faktur ({@see InvoiceNumberFormat::expandDateTokens()}),
 * {C+} = čítač s doplněním nul, {PP} = daňový prefix přijatého dokladu (jen druh
 * `purchase`, stejně jako {@see PurchaseNumberSeries}).
 *
 * Období řady musí jít ze šablony poznat, jinak by čítač po resetu vydal číslo, které
 * už existuje: měsíční řada potřebuje rok i měsíc, roční rok, řada bez resetu nic.
 */
final class NumberSeriesTemplate
{
    public const MAX_LENGTH = NumberTemplateRules::MAX_LENGTH;

    public const PERIODS = ['year', 'month', 'none'];

    private const PREFIX_PATTERN = '/^[A-Z0-9]{1,10}$/';

    /**
     * Ověří šablonu, prefix a období nové řady.
     *
     * @return list<string> kódy chyb (prázdné = v pořádku)
     */
    public static function validate(string $kind, string $template, ?string $prefix, string $period): array
    {
        $errors = [];
        $period_ = in_array($period, self::PERIODS, true) ? $period : null;
        if ($period_ === null) {
            $errors[] = 'period_invalid';
        }
        foreach (NumberTemplateRules::check($template, true, $kind === 'purchase', $period_) as $code) {
            $errors[] = 'template_' . $code;
        }
        if (str_contains($template, '{PREFIX}') && ($prefix === null || preg_match(self::PREFIX_PATTERN, $prefix) !== 1)) {
            $errors[] = 'prefix_invalid';
        }
        if ($prefix !== null && $prefix !== '' && preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            $errors[] = 'prefix_invalid';
        }
        return array_values(array_unique($errors));
    }

    /**
     * Klíč období počítadla: roční řada `2026`, měsíční `202604`, bez resetu `ALL`.
     * `$fiscalYear` přebije kalendářní rok u druhů číslovaných po účetních letech.
     */
    public static function periodKey(string $period, \DateTimeInterface $date, ?int $fiscalYear = null): string
    {
        $year = $fiscalYear ?? (int) $date->format('Y');
        return match ($period) {
            'year'  => sprintf('%04d', $year),
            'none'  => 'ALL',
            default => $date->format('Ym'),
        };
    }

    /** Vyrenderuje číslo dokladu. */
    public static function render(
        string $template,
        ?string $prefix,
        \DateTimeInterface $date,
        int $counter,
        string $taxPrefix = 'PF',
        ?int $fiscalYear = null,
    ): string {
        $out = str_replace(['{PREFIX}', '{PP}'], [(string) $prefix, $taxPrefix], $template);
        $year = $fiscalYear ?? (int) $date->format('Y');
        $month = (int) $date->format('n');
        $out = preg_replace_callback(
            InvoiceNumberFormat::DATE_TOKEN_RE,
            static fn (array $m): string
                => InvoiceNumberFormat::tokenValue($m[1], (int) ($m[2] ?? 0), $year, $month) ?? $m[0],
            $out,
        ) ?? $out;
        return preg_replace_callback(
            '/\{(C+)\}/',
            static fn (array $m): string => str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT),
            $out,
        ) ?? $out;
    }

    /**
     * Regex, který z čísla dokladu vyparsuje čítač (skupina 1). Rok a měsíc dosadí, když
     * je období zná (null = libovolné číslice), {PP} jen jako skutečný daňový prefix.
     */
    public static function counterRegex(string $template, ?string $prefix, ?int $year, ?int $month): string
    {
        $marked = str_replace(['{PREFIX}', '{PP}'], [(string) $prefix, "\x00P\x00"], $template);
        $marked = preg_replace_callback(
            InvoiceNumberFormat::DATE_TOKEN_RE,
            static function (array $m) use ($year, $month): string {
                $value = InvoiceNumberFormat::tokenValue($m[1], (int) ($m[2] ?? 0), $year, $month);
                return $value ?? "\x00D" . InvoiceNumberFormat::tokenWidth($m[1]) . "\x00";
            },
            $marked,
        ) ?? $marked;
        $marked = preg_replace('/\{C+\}/', "\x00C\x00", $marked) ?? $marked;
        $parts = preg_split('/(\x00P\x00|\x00C\x00|\x00D\d\x00)/', $marked, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            $regex .= match (true) {
                $part === "\x00P\x00" => '(?:' . implode('|', PurchaseNumberSeries::TAX_PREFIXES) . ')',
                $part === "\x00C\x00" => '(\d+)',
                (bool) preg_match('/^\x00D(\d)\x00$/', $part, $w) => '\d{' . $w[1] . '}',
                default => preg_quote($part, '/'),
            };
        }
        return '/^' . $regex . '$/D';
    }

    /** Doslovný začátek čísla před prvním proměnným místem (zúží LIKE sken). */
    public static function literalPrefix(string $template, ?string $prefix): string
    {
        $resolved = str_replace('{PREFIX}', (string) $prefix, $template);
        $cut = strcspn($resolved, '{');
        return substr($resolved, 0, $cut);
    }
}
