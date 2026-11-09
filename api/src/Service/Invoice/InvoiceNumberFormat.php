<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Service\NumberSeries\NumberTemplateRules;

/**
 * Validace číselné řady faktur — jediné volatelné místo pro pravidla, která musí
 * platit všude, kde jde template/období nastavit.
 *
 * Osy, na kterých se řada dá přepsat, přibývají (dodavatel → klient → kategorie
 * tržby) a každá má vlastní repository. Kdyby pravidlo zůstalo jako `private`
 * helper jedné z nich, druhá by si ho zkopírovala — a rozdíl by se projevil až
 * tím, že neplatný template projde uložením a spadne teprve při vystavení
 * dokladu (viz AGENTS.md: „SSOT musí jít ZAVOLAT").
 *
 * Placeholdery zrcadlí {@see VarsymbolGenerator::render()}.
 */
final class InvoiceNumberFormat
{
    public const MAX_TEMPLATE_LENGTH = NumberTemplateRules::MAX_LENGTH;

    public const PERIODS = ['year', 'month', 'none'];

    /** Řady vydaných dokladů na firmě, které renderuje {@see VarsymbolGenerator}. */
    public const SUPPLIER_COLUMNS = ['invoice_number_format', 'proforma_number_format', 'credit_note_number_format', 'simplified_number_format'];

    /**
     * Datumové tokeny řady včetně volitelného posunu — `{YY+30}`, `{MM-1}`, `{YYYY+1}`.
     * Posun je čistě ZOBRAZOVACÍ: neovlivňuje `invoice_number_period`, tedy kdy se čítač
     * resetuje. `{YY+30}` v roční řadě pořád přeskočí na 1 k 1. lednu skutečného roku.
     *
     * Sémantika posunu se drží {@see DescriptionPlaceholders} (pravidelné fakturace):
     * rok se posouvá po letech, měsíc po měsících včetně přetečení roku, a měsíční
     * tokeny jsou kotvené na 1. den měsíce, takže 31. 1. `{MM+1}` je 02 a ne 03.
     */
    public const DATE_TOKEN_RE = '/\{(YYYY|YY|MM)([+-]\d{1,3})?\}/';

    /** Počet číslic, které token vyprodukuje — šířka je na posunu nezávislá. */
    public static function tokenWidth(string $token): int
    {
        return $token === 'YYYY' ? 4 : 2;
    }

    /**
     * Hodnota jednoho tokenu. `$year`/`$month` = null znamená „období to nefixuje"
     * (např. roční řada nezná měsíc) → vrací null a volající si dosadí wildcard.
     */
    public static function tokenValue(string $token, int $offset, ?int $year, ?int $month): ?string
    {
        if ($year === null) {
            return null;
        }
        if ($token === 'YYYY' || $token === 'YY') {
            $shifted = sprintf('%04d', $year + $offset);
            return $token === 'YYYY' ? $shifted : substr($shifted, -2);
        }
        if ($month === null) {
            return null;
        }
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify(sprintf('%+d months', $offset))
            ->format('m');
    }

    /**
     * Dosadí datumové tokeny konkrétním datem; `{C+}` zůstává netknutý pro volajícího.
     * Jediná cesta, kterou se řada renderuje — generátor, náhled i report úplnosti
     * musí vidět tentýž řetězec, jinak si číslování přestane rozumět s vlastní historií.
     */
    public static function expandDateTokens(string $template, \DateTimeInterface $date): string
    {
        $year  = (int) $date->format('Y');
        $month = (int) $date->format('n');

        return preg_replace_callback(
            self::DATE_TOKEN_RE,
            static fn (array $m): string
                => self::tokenValue($m[1], (int) ($m[2] ?? 0), $year, $month) ?? $m[0],
            $template,
        ) ?? $template;
    }

    /**
     * Template override číselné řady. Prázdná hodnota → NULL (dědí se z nadřazené
     * úrovně). Závorky `()` a `[]` převede na složené a vrací už normalizovaný tvar;
     * pravidla (symboly, mezery, právě jeden čítač, délka) drží {@see NumberTemplateRules}
     * stejně jako číselník řad, takže hlášky jsou všude shodné. `$allowPp` jen u přijatých dokladů.
     *
     * @throws \InvalidArgumentException neplatný tvar čísla
     */
    public static function templateOrNull(mixed $value, string $key, bool $allowPp = false): ?string
    {
        $v = self::trimmedOrNull($value);
        if ($v === null) {
            return null;
        }
        $v = NumberTemplateRules::normalize($v);
        $errors = NumberTemplateRules::check($v, false, $allowPp);
        if ($errors !== []) {
            throw new \InvalidArgumentException(NumberTemplateRules::message($errors[0], false, $allowPp));
        }
        return $v;
    }

    /**
     * Kontrola už uložené šablony při vystavení dokladu: jen zástupné symboly, aby číslo
     * nedostalo doslovné závorky. Mezery ani počet čítačů tu nevadí (uloženo před validací).
     *
     * @throws \InvalidArgumentException neznámý zástupný symbol
     */
    public static function assertRenderable(string $template, string $key): void
    {
        if (NumberTemplateRules::placeholderErrors($template, false, false) !== []) {
            throw new \InvalidArgumentException($key . ': ' . NumberTemplateRules::message('placeholder', false, false));
        }
    }

    /**
     * Období counteru. NULL = dědí se z nadřazené úrovně.
     *
     * @throws \InvalidArgumentException neznámé období
     */
    public static function periodOrNull(mixed $value, string $key): ?string
    {
        $v = self::trimmedOrNull($value);
        if ($v === null) {
            return null;
        }
        if (!in_array($v, self::PERIODS, true)) {
            throw new \InvalidArgumentException("{$key} musí být year, month nebo none.");
        }
        return $v;
    }

    private static function trimmedOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }
}
