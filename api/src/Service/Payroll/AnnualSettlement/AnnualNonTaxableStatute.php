<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

/**
 * Limity nezdanitelných částí základu daně (§ 15 ZDP) tak, jak stojí v zákoně.
 *
 * Nejsou v rulesetu ze stejného důvodu jako lhůty v {@see AnnualSettlementStatute}:
 * jsou to pevná čísla zákona, ne ročníkové parametry mezd. Platí ve znění od
 * zdaňovacího období 2024 (společný limit 48 000 Kč pro produkty spoření na stáří
 * i pojištění dlouhodobé péče, strop darů 15 %). Pro starší rok vrací výjimku —
 * dřívější znění měla jiné limity a zúčtování za ně modul nedělá.
 *
 * Bytová potřeba obstaraná před 1. 1. 2021 má podle přechodného ustanovení
 * zákona č. 609/2020 Sb. limit 300 000 Kč; nový limit 150 000 Kč platí pro
 * bytové potřeby obstarané později. Při souběhu se nový úvěr vejde nejvýš do
 * 150 000 Kč a oba dohromady nejvýš do 300 000 Kč.
 */
final class AnnualNonTaxableStatute
{
    public const FIRST_SUPPORTED_YEAR = 2024;

    /** § 15 odst. 1: „anebo činí alespoň 1000 Kč". */
    public const DONATION_MINIMUM_MINOR_UNITS = 100_000;

    /** § 15 odst. 1: „přesáhne 2 % ze základu daně". */
    public const DONATION_MINIMUM_PERCENT = 2;

    /** § 15 odst. 1: „V úhrnu lze odečíst nejvýše 15 % ze základu daně". */
    public const DONATION_MAXIMUM_PERCENT = 15;

    /** § 15 odst. 1: hodnota jednoho odběru krve nebo jejích složek. */
    public const BLOOD_DONATION_VALUE_MINOR_UNITS = 300_000;

    /** § 15 odst. 1: hodnota odběru orgánu od žijícího dárce. */
    public const ORGAN_DONATION_VALUE_MINOR_UNITS = 2_000_000;

    /** § 15 odst. 1: hodnota jednoho odběru krvetvorných buněk. */
    public const STEM_CELL_DONATION_VALUE_MINOR_UNITS = 2_000_000;

    /** § 15 odst. 4: úhrn úroků v téže domácnosti. */
    public const HOUSING_INTEREST_LIMIT_MINOR_UNITS = 15_000_000;

    /** § 15 odst. 4 ve znění před z. č. 609/2020 Sb. — bytová potřeba obstaraná před 1. 1. 2021. */
    public const HOUSING_INTEREST_LEGACY_LIMIT_MINOR_UNITS = 30_000_000;

    /** § 15 odst. 6: produkty spoření na stáří a pojištění dlouhodobé péče v úhrnu. */
    public const RETIREMENT_AND_CARE_LIMIT_MINOR_UNITS = 4_800_000;

    public static function assertSupportedYear(int $taxYear): void
    {
        if ($taxYear < self::FIRST_SUPPORTED_YEAR) {
            throw new \InvalidArgumentException(
                'Nezdanitelné části základu daně modul počítá až od roku '
                . self::FIRST_SUPPORTED_YEAR . '.',
            );
        }
    }

    public static function countedDonationValue(AnnualNonTaxableKind $kind): int
    {
        return match ($kind) {
            AnnualNonTaxableKind::BloodDonation => self::BLOOD_DONATION_VALUE_MINOR_UNITS,
            AnnualNonTaxableKind::OrganDonation => self::ORGAN_DONATION_VALUE_MINOR_UNITS,
            AnnualNonTaxableKind::StemCellDonation => self::STEM_CELL_DONATION_VALUE_MINOR_UNITS,
            default => throw new \LogicException('Položka se neoceňuje počtem odběrů.'),
        };
    }

    /** @return array<string,mixed> */
    public static function toArray(): array
    {
        return [
            'source' => AnnualSettlementStatute::SOURCE,
            'donation_minimum_minor_units' => self::DONATION_MINIMUM_MINOR_UNITS,
            'donation_minimum_percent' => self::DONATION_MINIMUM_PERCENT,
            'donation_maximum_percent' => self::DONATION_MAXIMUM_PERCENT,
            'blood_donation_value_minor_units' => self::BLOOD_DONATION_VALUE_MINOR_UNITS,
            'organ_donation_value_minor_units' => self::ORGAN_DONATION_VALUE_MINOR_UNITS,
            'stem_cell_donation_value_minor_units' => self::STEM_CELL_DONATION_VALUE_MINOR_UNITS,
            'housing_interest_limit_minor_units' => self::HOUSING_INTEREST_LIMIT_MINOR_UNITS,
            'housing_interest_legacy_limit_minor_units' =>
                self::HOUSING_INTEREST_LEGACY_LIMIT_MINOR_UNITS,
            'retirement_and_care_limit_minor_units' =>
                self::RETIREMENT_AND_CARE_LIMIT_MINOR_UNITS,
        ];
    }
}
