<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

use MyInvoice\Infrastructure\Database\Connection;

final class StockItemIntrastatValidator
{
    private const SUPPLEMENTARY_UNITS = [
        'CTM', 'NEL', 'CCT', 'GRM', 'GFI', 'KHO', 'KPO', 'KPH', 'KMA', 'KNI', 'KSH', 'KNE', 'KPP', 'KSD',
        'KUR', 'MWH', 'LTR', 'LPA', 'MTR', 'MTK', 'MTQ', 'MQM', 'NPR', 'PCE', 'CEN', 'MIL', 'TJO', 'ZZZ',
    ];

    /** Kódy původu Intrastatu mimo číselník zemí (QU = neznámá země, QV = neznámý stát EU). */
    public const NON_ISO_ORIGIN_CODES = ['QU', 'QV'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array{intrastat_cn8_code:?string,intrastat_country_of_origin:?string,
     *     intrastat_net_mass_kg:?string,intrastat_supplementary_unit:?string,
     *     intrastat_supplementary_unit_coefficient:?string}
     */
    public function normalize(array $input, ?array $existing = null): array
    {
        $cn8 = $this->text($input, 'intrastat_cn8_code', $existing);
        if ($cn8 === false || ($cn8 !== null && preg_match('/^[0-9]{8}$/', $cn8) !== 1)) {
            throw new \InvalidArgumentException('Kód KN8 musí obsahovat přesně 8 číslic.');
        }

        $countryOfOrigin = $this->text($input, 'intrastat_country_of_origin', $existing, true);
        if ($countryOfOrigin === false
            || ($countryOfOrigin !== null && preg_match('/^[A-Z]{2}$/', $countryOfOrigin) !== 1)
            || ($countryOfOrigin !== null
                && !in_array($countryOfOrigin, self::NON_ISO_ORIGIN_CODES, true)
                && !$this->countryExists($countryOfOrigin))) {
            throw new \InvalidArgumentException('Země původu musí být platný dvoupísmenný ISO kód.');
        }

        $netMassKg = $this->decimal($input, 'intrastat_net_mass_kg', $existing, 11, 3);
        if ($netMassKg === false || ($netMassKg !== null && self::isZeroDecimal($netMassKg))) {
            throw new \InvalidArgumentException('Čistá hmotnost musí být kladné číslo s nejvýše 3 desetinnými místy.');
        }

        $supplementaryUnit = $this->text($input, 'intrastat_supplementary_unit', $existing, true);
        if ($supplementaryUnit === false
            || ($supplementaryUnit !== null && !in_array($supplementaryUnit, self::SUPPLEMENTARY_UNITS, true))) {
            throw new \InvalidArgumentException('Doplňková měrná jednotka není v platném číselníku Intrastatu.');
        }
        $supplementaryCoefficient = $this->decimal(
            $input,
            'intrastat_supplementary_unit_coefficient',
            $existing,
            12,
            6,
        );
        if ($supplementaryCoefficient === false
            || ($supplementaryCoefficient !== null && self::isZeroDecimal($supplementaryCoefficient))) {
            throw new \InvalidArgumentException('Koeficient doplňkové jednotky musí být kladné číslo s nejvýše 6 desetinnými místy.');
        }
        if ($supplementaryUnit === 'ZZZ' && $supplementaryCoefficient !== null) {
            throw new \InvalidArgumentException('U doplňkové jednotky ZZZ se koeficient nevyplňuje.');
        }
        if ($supplementaryUnit !== 'ZZZ' && (($supplementaryUnit === null) !== ($supplementaryCoefficient === null))) {
            throw new \InvalidArgumentException('Doplňková jednotka a její koeficient musí být vyplněny společně.');
        }

        return [
            'intrastat_cn8_code' => $cn8,
            'intrastat_country_of_origin' => $countryOfOrigin,
            'intrastat_net_mass_kg' => $netMassKg,
            'intrastat_supplementary_unit' => $supplementaryUnit,
            'intrastat_supplementary_unit_coefficient' => $supplementaryCoefficient,
        ];
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing */
    private function text(array $input, string $field, ?array $existing, bool $uppercase = false): string|false|null
    {
        $value = array_key_exists($field, $input) ? $input[$field] : ($existing[$field] ?? null);
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return $uppercase ? strtoupper($value) : $value;
    }

    /** @param array<string,mixed> $input @param array<string,mixed>|null $existing */
    private function decimal(
        array $input,
        string $field,
        ?array $existing,
        int $integerDigits,
        int $decimalDigits,
    ): string|false|null {
        $value = array_key_exists($field, $input) ? $input[$field] : ($existing[$field] ?? null);
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || is_bool($value)) {
            return false;
        }
        if (is_float($value) && (!is_finite($value) || $value < 0)) {
            return false;
        }
        $value = is_float($value) ? self::fixedFloat($value) : trim((string) $value);
        if ($value === false) {
            return false;
        }
        if ($value === '') {
            return null;
        }
        $pattern = '/^[0-9]{1,' . $integerDigits . '}(?:\.[0-9]{1,' . $decimalDigits . '})?$/';
        return preg_match($pattern, $value) === 1 ? $value : false;
    }

    private static function fixedFloat(float $value): string|false
    {
        $text = (string) $value;
        if (!str_contains(strtolower($text), 'e')) {
            return $text;
        }
        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)$/D', $text, $matches) !== 1) {
            return false;
        }

        $digits = $matches[2] . ($matches[3] ?? '');
        $decimalPosition = strlen($matches[2]) + (int) $matches[4];
        if ($decimalPosition <= 0) {
            $fixed = '0.' . str_repeat('0', -$decimalPosition) . $digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $fixed = $digits . str_repeat('0', $decimalPosition - strlen($digits));
        } else {
            $fixed = substr($digits, 0, $decimalPosition) . '.' . substr($digits, $decimalPosition);
        }
        if (str_contains($fixed, '.')) {
            $fixed = rtrim(rtrim($fixed, '0'), '.');
        }

        return $matches[1] . $fixed;
    }

    private static function isZeroDecimal(string $value): bool
    {
        return preg_match('/^0+(?:\.0+)?$/', $value) === 1;
    }

    private function countryExists(string $iso2): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT EXISTS (SELECT 1 FROM countries WHERE iso2 = ?)');
        $stmt->execute([$iso2]);
        return (bool) $stmt->fetchColumn();
    }
}
