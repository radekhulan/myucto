<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Přepis identity šarže v kopiích alokací uložených jako JSON (řádky konceptů faktur
 * a skladových dokladů, snapshoty expedice). Kmen šarže se opravuje jinde
 * ({@see TrackingUnitRenameService}); tahle čistá funkce jen říká, jak má po opravě vypadat
 * seznam alokací jedné kopie.
 */
final class TrackingIdentityRewrite
{
    /**
     * Řádek patří opravované šarži, když nese její `stock_tracking_unit_id`, nebo když id
     * nemá a sedí kód šarže a datum expirace. Kód se porovnává bez ohledu na velikost písmen
     * (`mb_strtolower`); kolace unikátního klíče kmene (`utf8mb4_unicode_ci`) ignoruje navíc
     * diakritiku. Kopie zadaná s jinou diakritikou než šarže (třeba koncept zadaný přes API
     * jako „sarze1“ k šarži „Šarže1“, text se ukládá tak, jak ho klient poslal) se proto
     * nepřepíše: zaúčtovaný řádek se už nepáruje, ale vystavení takového konceptu po opravě
     * skončí `tracking_not_found` a šarži je v něm třeba vybrat znovu.
     * Řádek s cizím id se nepřepíše ani při shodě textu.
     *
     * @param array<int,mixed> $rows alokace jedné kopie (už dekódované z JSON)
     * @return list<mixed>|null přepsaný seznam, null když se nic nemění
     */
    public static function rewrite(
        array $rows,
        int $unitId,
        string $oldLot,
        ?string $oldExpires,
        string $newLot,
        ?string $newExpires,
    ): ?array {
        $changed = false;
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && self::matches($row, $unitId, $oldLot, $oldExpires)) {
                $new = $row;
                $new['lot_code'] = $newLot;
                // Klíč `expires_on` se nezakládá zbytečně: kopie ho u šarže bez expirace nemají.
                if (array_key_exists('expires_on', $new) || $newExpires !== null) {
                    $new['expires_on'] = $newExpires;
                }
                if ($new !== $row) {
                    $changed = true;
                    $row = $new;
                }
            }
            $out[] = $row;
        }
        return $changed ? $out : null;
    }

    /** @param array<string,mixed> $row */
    private static function matches(array $row, int $unitId, string $oldLot, ?string $oldExpires): bool
    {
        $rowUnitId = (int) ($row['stock_tracking_unit_id'] ?? 0);
        if ($rowUnitId > 0) {
            return $rowUnitId === $unitId;
        }
        if (self::text($row['serial_number'] ?? null) !== null) {
            return false;
        }
        $lot = self::text($row['lot_code'] ?? null);
        return $lot !== null
            && mb_strtolower($lot) === mb_strtolower($oldLot)
            && self::text($row['expires_on'] ?? null) === $oldExpires;
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
