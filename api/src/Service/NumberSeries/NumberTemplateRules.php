<?php

declare(strict_types=1);

namespace MyInvoice\Service\NumberSeries;

/**
 * Pravidla tvaru čísla dokladu (šablony typu `{YY}{CCCC}`) - jediné místo pro řady
 * číselníku, formáty vydaných dokladů (firma, klient, kategorie tržeb) i přijatých dokladů.
 * Frontend je zrcadlí v `web/src/utils/numberTemplate.ts`; shodu hlídá společná sada
 * vzorků `api/tests/Fixtures/number-template-cases.json` (PHPUnit i Vitest).
 */
final class NumberTemplateRules
{
    public const MAX_LENGTH = 60;

    public const DATE_TOKEN = '/\{(YYYY|YY|MM)([+-]\d{1,3})?\}/';

    /** Kulaté a hranaté závorky se při zadání tvaru čísla převádějí na složené. */
    public static function normalize(string $template): string
    {
        return strtr($template, ['(' => '{', '[' => '{', ')' => '}', ']' => '}']);
    }

    /**
     * @param ?string $period year|month|none; null = období se tu neposuzuje
     * @return list<string> kódy chyb (length, placeholder, whitespace, counter, period)
     */
    public static function check(string $template, bool $allowPrefix, bool $allowPp, ?string $period = null): array
    {
        $errors = [];
        if ($template === '' || strlen($template) > self::MAX_LENGTH) {
            $errors[] = 'length';
        }
        $errors = [...$errors, ...self::placeholderErrors($template, $allowPrefix, $allowPp)];
        if (preg_match('/\s/', $template)) {
            $errors[] = 'whitespace';
        }
        if (preg_match_all('/\{C+\}/', $template) !== 1) {
            $errors[] = 'counter';
        }
        if ($period !== null && self::periodMissing($template, $period)) {
            $errors[] = 'period';
        }
        return $errors;
    }

    /**
     * Jen kontrola zástupných symbolů - pro šablonu už uloženou (vystavení dokladu nesmí
     * spadnout kvůli mezeře nebo chybějícímu čítači, jen kvůli symbolu, který by prošel do čísla doslova).
     *
     * @return list<string>
     */
    public static function placeholderErrors(string $template, bool $allowPrefix, bool $allowPp): array
    {
        $allowed = [self::DATE_TOKEN, '/\{C+\}/'];
        if ($allowPrefix) {
            $allowed[] = '/\{PREFIX\}/';
        }
        if ($allowPp) {
            $allowed[] = '/\{PP\}/';
        }
        $stripped = preg_replace($allowed, '', $template) ?? '';
        return preg_match('/[{}]/', $stripped) ? ['placeholder'] : [];
    }

    public static function periodMissing(string $template, string $period): bool
    {
        $hasYear  = (bool) preg_match('/\{(YYYY|YY)([+-]\d{1,3})?\}/', $template);
        $hasMonth = (bool) preg_match('/\{MM([+-]\d{1,3})?\}/', $template);
        return ($period === 'year' && !$hasYear) || ($period === 'month' && (!$hasYear || !$hasMonth));
    }

    /** Výčet dovolených symbolů do hlášky (shodný text je ve `web/src/utils/numberTemplate.ts`). */
    public static function allowedSymbols(bool $allowPrefix, bool $allowPp): string
    {
        return ($allowPrefix ? '{PREFIX} ' : '') . '{YYYY} {YY} {MM} {C…}' . ($allowPp ? ' {PP}' : '');
    }

    /** Hláška k chybě; texty musí odpovídat `number_template.errors` v `web/src/i18n/cs.json`. */
    public static function message(string $code, bool $allowPrefix, bool $allowPp): string
    {
        return match ($code) {
            'length'      => 'Tvar čísla musí mít 1 až ' . self::MAX_LENGTH . ' znaků.',
            'placeholder' => 'Tvar čísla obsahuje neznámý zástupný symbol. Dovolené: '
                . self::allowedSymbols($allowPrefix, $allowPp)
                . ', u data lze přidat posun, např. {YY+1}.',
            'whitespace'  => 'Tvar čísla nesmí obsahovat mezery.',
            'counter'     => 'Tvar čísla musí obsahovat právě jeden čítač {C…}, např. {CCCC}.',
            'period'      => 'Tvar čísla musí obsahovat rok (u roční řady) nebo rok i měsíc (u měsíční řady), jinak by se čísla po resetu opakovala.',
            default       => 'Neplatný tvar čísla.',
        };
    }
}
