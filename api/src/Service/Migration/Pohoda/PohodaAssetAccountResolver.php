<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

final class PohodaAssetAccountResolver
{
    public const REVIEW_PREFIX = 'Převod z POHODY - ke kontrole: ';
    public const UNDETERMINED_NOTE = 'majetkový účet se z počátečních stavů nepodařilo určit';

    /**
     * Majetkový účet: počáteční stav 01x-03x ve výši vstupní ceny. Shoduje-li se víc účtů,
     * rozhodne analytika shodná s účtem oprávek (022007 ↔ 082007). Vede-li účet víc karet
     * (počáteční stav je jejich součet), určí ho účet oprávek z odpisů karty: majetkový účet
     * se stejnou analytikou, je-li v počátečních stavech a pokryje vstupní cenu.
     *
     * @param array<string,float> $opening
     */
    public static function resolve(array $opening, float $inputPrice, ?string $accumulated): ?string
    {
        $matched = self::matchingOpening($opening, $inputPrice, $accumulated);
        if ($matched !== false || $accumulated === null || preg_match('/^0[78]/', $accumulated) !== 1) {
            return $matched === false ? null : $matched;
        }
        $derived = '0' . ((int) substr($accumulated, 1, 1) - 6) . substr($accumulated, 2);
        return isset($opening[$derived]) && $opening[$derived] + 1.0 >= $inputPrice ? $derived : null;
    }

    /**
     * @param array<string,float> $opening zůstatky ve směru účtu (MD u majetku, Dal u oprávek)
     * @param list<string> $journalAccounts účty ze zápisů přiřazených ke kartě
     */
    public static function resolveAnalytic(string $synthetic, array $opening, array $journalAccounts): ?string
    {
        $prefix = $synthetic . '.';
        if ($journalAccounts !== []) {
            $candidates = array_values(array_unique($journalAccounts));
            return count($candidates) === 1 && str_starts_with($candidates[0], $prefix) ? $candidates[0] : null;
        }
        if (($opening[$synthetic] ?? 0.0) != 0.0) {
            return null;
        }
        $candidates = array_keys(array_filter($opening, static fn (float $balance, string $code): bool =>
            $balance != 0.0 && str_starts_with($code, $prefix), ARRAY_FILTER_USE_BOTH));
        return count($candidates) === 1 && $opening[$candidates[0]] > 0.0 ? $candidates[0] : null;
    }

    /**
     * Účet s počátečním stavem ve výši vstupní ceny; null = víc kandidátů, false = žádný.
     *
     * @param array<string,float> $opening
     */
    private static function matchingOpening(array $opening, float $inputPrice, ?string $accumulated): string|false|null
    {
        // Nejdřív přesná shoda; karta v POHODĚ může mít vstupní cenu zaokrouhlenou na koruny
        // proti haléřovému zůstatku účtu, proto pak shoda do 1 Kč - vždy jen jednoznačná.
        foreach ([0.005, 1.0] as $tolerance) {
            $candidates = array_keys(array_filter($opening, static fn (float $amount): bool => abs($amount - $inputPrice) < $tolerance));
            if (count($candidates) > 1 && $accumulated !== null) {
                $candidates = array_values(array_filter($candidates, static fn (string $code): bool => substr($code, 3) === substr($accumulated, 3)));
            }
            if (count($candidates) === 1) {
                return (string) $candidates[0];
            }
            if ($candidates !== []) {
                return null;
            }
        }
        return false;
    }
}
