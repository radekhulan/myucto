<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Rozsah účtů „od – do“ ve filtru deníku a hlavní knihy. Meze jsou prefixové včetně krajů:
 * 5 až 5 = celá třída 5, 501 až 518 včetně analytik 518.xxx, 501.100 až 501.100
 * = analytika 501.100 (a případné kódy, které na ni začínají). Prázdná mez = bez omezení.
 *
 * Kódy se porovnávají jako sloupec account_code v kolaci utf8mb4_unicode_ci:
 * bez ohledu na velikost písmen a s pořadím znaků _ - . / < číslice < písmena.
 */
final class AccountCodeRange
{
    /** Abeceda kódu účtu včetně importů (Abra, StereoNx: podtržítko, pomlčka, lomítko); sloupec má 10 znaků. */
    private const CODE = '/^[0-9A-Za-z._\/-]{1,10}$/D';
    /**
     * Doplněk horní meze pro SQL: kód začínající mezí má za ní nejvýš 10 dalších
     * znaků z abecedy kódu a „Z“ je v ní v kolaci největší.
     */
    private const SQL_UPPER_PAD = 'ZZZZZZZZZZ';

    private function __construct(public readonly string $from, public readonly string $to)
    {
    }

    /**
     * @throws AccountCodeRangeException neplatný kód nebo dolní mez za horní
     */
    public static function parse(string $from, string $to): self
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        foreach ([$from, $to] as $code) {
            if ($code !== '' && !preg_match(self::CODE, $code)) {
                throw new AccountCodeRangeException('Rozsah účtů musí obsahovat číslo účtu nebo jeho začátek (např. 5, 501, 501.100).');
            }
        }
        // 501.100 až 501 je platný rozsah: horní mez je začátek dolní.
        if ($from !== '' && $to !== '' && self::compare($from, $to) > 0 && !str_starts_with($from, $to)) {
            throw new AccountCodeRangeException('Počáteční účet nesmí být větší než koncový.');
        }
        return new self($from, $to);
    }

    /**
     * Patří účet do rozsahu? Vybírá totéž co sqlBounds() v databázi. `$syntheticOnly` =
     * porovnávají se jen syntetiky (hlavní kniha bez rozpadu po analytikách): dolní mez
     * s analytikou (501.100) pak vybere celou syntetiku 501, jinak by ji vyřadila,
     * i když její analytika do rozsahu patří.
     */
    public function contains(string $code, bool $syntheticOnly = false): bool
    {
        $code = strtoupper($code);
        return ($this->from === '' || self::compare($code, $this->from) >= 0 || ($syntheticOnly && str_starts_with($this->from, $code)))
            && ($this->to === '' || self::compare(substr($code, 0, strlen($this->to)), $this->to) <= 0);
    }

    /**
     * Meze pro `account_code BETWEEN ? AND ?`. Holý sloupec v podmínce nechává
     * použít index kódu účtu.
     *
     * @return array{0:string, 1:string}
     */
    public function sqlBounds(): array
    {
        return [$this->from, $this->to . self::SQL_UPPER_PAD];
    }

    /**
     * Porovnání kódů v pořadí kolace sloupce: v ASCII je podtržítko za písmeny,
     * v utf8mb4_unicode_ci před pomlčkou, proto se pro porovnání převede na čárku.
     */
    private static function compare(string $a, string $b): int
    {
        return strcmp(strtr(strtoupper($a), '_', ','), strtr(strtoupper($b), '_', ','));
    }
}
