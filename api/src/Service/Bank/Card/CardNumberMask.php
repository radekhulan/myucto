<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Card;

/**
 * Jediné místo, které rozumí číslu platební karty.
 *
 * Aplikace smí znát jen koncovku (poslední čtyři číslice). Bankovní výpisy nesou
 * číslo maskované („PK: 000000******1234", „516872XXXXXX7989",
 * „**** **** **** 1234") a z něj se bere právě koncovka. Uživatelský vstup se
 * sanitizuje stejně: kdo do formuláře vloží celé číslo, uloží se jen poslední
 * čtyři číslice.
 *
 * Vzor {@see MASKED_PATTERN} je PCRE a MariaDB (PCRE2) ho umí v `REGEXP` beze
 * změny — backfill ho proto používá i v SQL předvýběru, ať obě strany rozhodují
 * podle téhož pravidla.
 */
final class CardNumberMask
{
    /**
     * Maskované číslo karty: volitelný prefix BIN (4–6 číslic), aspoň čtyři maskovací
     * znaky (hvězdička nebo X, volitelně oddělené mezerou či pomlčkou) a čtyři
     * číslice koncovky. Lookbehind brání záchytu uvnitř slova („TAXXXX1234").
     */
    public const MASKED_PATTERN = '(?<![0-9A-Za-z])(?:[0-9]{4,6} ?)?(?:[*Xx][ -]?){4,15}([0-9]{4})(?![0-9])';

    /**
     * Maskovaný (i nemaskovaný) IBAN: kód země, dvě kontrolní číslice nebo maska
     * a skupiny BBAN. Bankovní API ho v datech pohybu nesou u každé transakce
     * („CZ** **** **** **** **** 1234") a konec vypadá jako koncovka karty.
     * „XX" kódem země není — to je maska karty („XXXX-XXXX-XXXX-5678").
     */
    private const IBAN_PATTERN = '(?<![0-9A-Za-z])(?!XX)[A-Z]{2}[0-9*Xx]{2}(?:[ -]?[0-9A-Z*]{1,4}){2,8}';

    /** Číslo účtu s kódem banky („******1234/0100", „19-******1234/0800"). */
    private const ACCOUNT_PATTERN = '(?<![0-9A-Za-z])(?:[0-9]{1,6}-)?[0-9*Xx]{2,10} ?\/ ?[0-9]{4}(?![0-9])';

    /** Klíč v datech bankovního API, pod kterým leží účet, ne karta. */
    private const ACCOUNT_KEY = '/iban|bban|account|acct|ucet|účet/iu';
    private const CARD_KEY = '/card|karta|karty|pan$/iu';

    /**
     * Koncovka z maskovaného čísla v textu. Víc RŮZNÝCH koncovek v jednom pohybu
     * je nejednoznačné → null (radši nic než špatná karta). IBAN a číslo účtu
     * s kódem banky se předem vyřadí — jejich maskovaná podoba jinak projde vzorem
     * karty a všem pohybům účtu by přidělila falešnou koncovku.
     */
    public static function last4FromText(?string ...$texts): ?string
    {
        $found = [];
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }
            $text = (string) preg_replace(
                ['/' . self::IBAN_PATTERN . '/u', '/' . self::ACCOUNT_PATTERN . '/u'],
                ' ',
                $text,
            );
            if (preg_match_all('/' . self::MASKED_PATTERN . '/u', $text, $m) > 0) {
                foreach ($m[1] as $last4) {
                    $found[$last4] = true;
                }
            }
        }
        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /**
     * Koncovka ze strukturovaných dat bankovního API (libovolně vnořené pole) —
     * prochází všechny textové hodnoty. API různých bank pojmenovávají pole s kartou
     * různě, maskované číslo je ale vždy rozpoznatelné samo o sobě. Hodnoty pod
     * klíčem účtu (IBAN, číslo účtu protistrany i vlastního účtu) se přeskočí,
     * pokud cesta k nim zároveň nemluví o kartě.
     *
     * @param array<mixed> $data
     */
    public static function last4FromStructured(array $data): ?string
    {
        $texts = [];
        self::collectTexts($data, false, false, $texts);
        return self::last4FromText(...$texts);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $texts
     */
    private static function collectTexts(array $data, bool $accountPath, bool $cardPath, array &$texts): void
    {
        foreach ($data as $key => $value) {
            $isAccount = $accountPath || (is_string($key) && preg_match(self::ACCOUNT_KEY, $key) === 1);
            $isCard = $cardPath || (is_string($key) && preg_match(self::CARD_KEY, $key) === 1);
            if (is_array($value)) {
                self::collectTexts($value, $isAccount, $isCard, $texts);
            } elseif (is_string($value) && $value !== '' && (!$isAccount || $isCard)) {
                $texts[] = $value;
            }
        }
    }

    /**
     * Koncovka pro transakci z libovolného parseru výpisu. Přednost má koncovka,
     * kterou parser vytáhl z vlastního sloupce karty; jinak se hledá maskované číslo
     * v textu pohybu a nakonec v surových datech bankovního API (`metadata`).
     *
     * @param array<string,mixed> $tx
     */
    public static function forParsedTransaction(array $tx): ?string
    {
        $explicit = $tx['card_last4'] ?? null;
        if (is_string($explicit) && self::isValidLast4($explicit)) {
            return $explicit;
        }
        $fromText = self::last4FromText(
            is_string($tx['counterparty_name'] ?? null) ? $tx['counterparty_name'] : null,
            is_string($tx['description'] ?? null) ? $tx['description'] : null,
        );
        if ($fromText !== null) {
            return $fromText;
        }
        return is_array($tx['metadata'] ?? null) ? self::last4FromStructured($tx['metadata']) : null;
    }

    /** Text bez maskovaného čísla karty a jeho štítku „PK:" (pro jméno obchodníka). */
    public static function stripMasked(string $text): string
    {
        $out = (string) preg_replace('/(?:\bPK:\s*)?' . self::MASKED_PATTERN . '/u', ' ', $text);
        return trim((string) preg_replace('/\s{2,}/u', ' ', $out));
    }

    /**
     * Sanitizace vstupu koncovky: z libovolného textu vezme číslice a vrátí posledních
     * čtyři. Celé číslo karty vložené do formuláře se tak NIKDY neuloží. Méně než čtyři
     * číslice = neplatný vstup (null).
     */
    public static function normalizeLast4(mixed $input): ?string
    {
        if ($input === null || is_array($input) || is_object($input) || is_bool($input)) {
            return null;
        }
        $digits = (string) preg_replace('/[^0-9]/', '', (string) $input);
        if (strlen($digits) < 4) {
            return null;
        }
        return substr($digits, -4);
    }

    public static function isValidLast4(?string $value): bool
    {
        return $value !== null && preg_match('/^[0-9]{4}$/D', $value) === 1;
    }

    /**
     * Obsahuje text celé číslo karty? 13–19 číslic (volitelně s mezerami/pomlčkami),
     * které projdou Luhnovým součtem. Používá se pro volné textové pole karty
     * (název, držitel, poznámka) — celé číslo tam nesmí skončit ani omylem.
     */
    public static function containsFullPan(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }
        if (preg_match_all('/(?<![0-9])(?:[0-9][ -]?){12,18}[0-9](?![0-9])/', $text, $m) === 0) {
            return false;
        }
        foreach ($m[0] as $candidate) {
            $digits = (string) preg_replace('/[^0-9]/', '', $candidate);
            $len = strlen($digits);
            if ($len >= 13 && $len <= 19 && self::luhn($digits)) {
                return true;
            }
        }
        return false;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return $sum % 10 === 0;
    }
}
