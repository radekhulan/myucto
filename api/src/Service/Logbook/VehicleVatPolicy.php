<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

/**
 * Režim užívání vozidla a z něj plynoucí odpočet DPH (§ 72, § 75 ZDPH).
 *
 * Jediné místo, kde žije pravidlo „jaký odpočet smí mít vozidlo v daném režimu" —
 * volá ho validace číselníku aut (CarsAction), kontrola tankování proti dokladu
 * (FuelingOdometerWarnings) i databázový CHECK v migraci 1803, který ho zrcadlí.
 *
 * Slovník odpočtu je tentýž jako na řádcích dokladů (`purchase_invoice_vat_allocations`,
 * `cash_document_vat_lines`, migrace 1120): full / proportional / none. Vozidlo NEZNÁ
 * `reduced` — koeficient krácení podle § 76 je celofiremní a roční, ne vlastnost auta.
 * Pravidlo „smíšené užívání ⇒ poměrný odpočet" je stejné jako u účetních alokací
 * přijatých faktur (PurchaseInvoiceRepository, validace alokací). Vozidlo ho nepřebírá
 * voláním, protože tam jde o jeden řádek jednoho dokladu, kdežto tady o trvalé
 * nastavení, se kterým se doklady porovnávají.
 */
final class VehicleVatPolicy
{
    public const USAGE_MODES = ['business', 'private', 'mixed'];
    public const DEDUCTIONS = ['full', 'proportional', 'none'];

    /**
     * Normalizuje a ověří nastavení vozidla.
     *
     * Vozidlo nese režim ve sloupci `vat_deduction_mode` — jiné jméno než `vat_deduction`
     * řádků dokladů, protože doména je užší (bez `reduced`).
     *
     * @return array{usage_mode:string, vat_deduction_mode:string, vat_deduction_percent:float}
     * @throws \InvalidArgumentException s českou hláškou pro uživatele
     */
    public static function normalize(?string $usage, ?string $deduction, mixed $percent): array
    {
        $usage = $usage === null || $usage === '' ? 'business' : $usage;
        if (!in_array($usage, self::USAGE_MODES, true)) {
            throw new \InvalidArgumentException('Neplatný režim užívání vozidla.');
        }
        $deduction = $deduction === null || $deduction === '' ? self::defaultDeduction($usage) : $deduction;
        if (!in_array($deduction, self::DEDUCTIONS, true)) {
            throw new \InvalidArgumentException('Neplatný režim odpočtu DPH.');
        }

        if ($usage === 'private' && $deduction !== 'none') {
            throw new \InvalidArgumentException('Soukromé vozidlo nemá nárok na odpočet DPH.');
        }
        if ($usage === 'mixed' && $deduction === 'full') {
            throw new \InvalidArgumentException('Smíšené užívání musí používat poměrný odpočet podle § 75.');
        }

        $p = match ($deduction) {
            'full' => 100.0,
            'none' => 0.0,
            default => is_numeric($percent) ? round((float) $percent, 2) : -1.0,
        };
        if ($deduction === 'proportional' && ($p <= 0.0 || $p >= 100.0)) {
            throw new \InvalidArgumentException('Poměrný odpočet musí mít podíl mezi 0 a 100 %.');
        }

        return ['usage_mode' => $usage, 'vat_deduction_mode' => $deduction, 'vat_deduction_percent' => $p];
    }

    public static function defaultDeduction(string $usage): string
    {
        return match ($usage) {
            'private' => 'none',
            'mixed'   => 'proportional',
            default   => 'full',
        };
    }

    /**
     * Efektivní podíl odpočtu v % pro režim dokladu; null = nelze porovnat
     * (`reduced` řídí roční koeficient § 76, ne vozidlo).
     */
    public static function effectivePercent(string $deduction, float $percent): ?float
    {
        return match ($deduction) {
            'full' => 100.0,
            'none' => 0.0,
            'proportional' => round($percent, 2),
            default => null,
        };
    }

    /**
     * Uplatňuje doklad jiný odpočet, než dovoluje nastavení vozidla?
     *
     * Vrací null (souhlasí / nelze porovnat), nebo 'over' (doklad uplatňuje víc, než
     * vozidlo dovoluje — riziko doměrku) či 'under' (uplatňuje méně — ztracený nárok).
     */
    public static function documentMismatch(string $carDeduction, float $carPercent, string $docDeduction, float $docPercent): ?string
    {
        $car = self::effectivePercent($carDeduction, $carPercent);
        $doc = self::effectivePercent($docDeduction, $docPercent);
        if ($car === null || $doc === null || abs($car - $doc) < 0.01) {
            return null;
        }
        return $doc > $car ? 'over' : 'under';
    }
}
