<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Service\Tax\TaxOfficeCatalog;

final class RegzelTaxOfficeCode
{
    /**
     * REGZEL kód úřadu → kódy jeho územních pracovišť, odvozené z číselníku FÚ.
     * Specializovaný FÚ (4000) pracoviště nemá.
     *
     * @return array<string,list<string>>
     */
    private static function workplacesByOffice(): array
    {
        static $byOffice = null;
        if ($byOffice !== null) return $byOffice;
        $byOffice = [];
        foreach (TaxOfficeCatalog::offices() as $office) {
            if ($office['code'] === TaxOfficeCatalog::SPECIALIZED_OFFICE) continue;
            $byOffice[$office['regzel_code']] = array_column(TaxOfficeCatalog::workplaces($office['code']), 'code');
        }
        return $byOffice;
    }

    public static function required(mixed $value): string
    {
        if (!is_string($value)) {
            throw self::invalidOffice();
        }
        $code = trim($value);
        if ($code === '') {
            throw new RegzelValidationException(
                'regzel_tax_office_required',
                'Doplňte čtyřmístný kód finančního úřadu pro REGZEL (kodFU). '
                . 'Nejde o tříčíselný kód EPO, například 451.',
            );
        }
        if (!self::isValidOffice($code)) {
            throw self::invalidOffice();
        }
        return $code;
    }

    public static function optional(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw self::invalidWorkplace();
        }
        $code = trim($value);
        if ($code === '') {
            return null;
        }
        if (!self::isValidWorkplace($code)) {
            throw self::invalidWorkplace();
        }
        return $code;
    }

    public static function suggestion(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $code = trim($value);
        return self::isValidWorkplace($code) ? $code : null;
    }

    public static function isValidOffice(string $code): bool
    {
        return $code === '4000'
            || array_key_exists($code, self::workplacesByOffice());
    }

    /** @return list<string> */
    public static function officeCodes(): array
    {
        return [
            ...array_map('strval', array_keys(self::workplacesByOffice())),
            '4000',
        ];
    }

    /** @return list<string> */
    public static function workplacesForOffice(string $taxOfficeCode): array
    {
        return self::workplacesByOffice()[$taxOfficeCode] ?? [];
    }

    public static function isValidWorkplace(string $code): bool
    {
        foreach (self::workplacesByOffice() as $workplaces) {
            if (in_array($code, $workplaces, true)) {
                return true;
            }
        }
        return false;
    }

    public static function requiresWorkplace(string $taxOfficeCode): bool
    {
        return $taxOfficeCode !== '4000';
    }

    public static function validatePair(
        string $taxOfficeCode,
        ?string $workplaceCode,
    ): void {
        if ($taxOfficeCode === '4000') {
            if ($workplaceCode !== null) {
                throw new RegzelValidationException(
                    'regzel_tax_office_workplace_forbidden',
                    'Specializovaný finanční úřad (4000) nemá územní pracoviště; '
                    . 'kód pracoviště ponechte prázdný.',
                );
            }
            return;
        }
        if ($workplaceCode === null) {
            throw new RegzelValidationException(
                'regzel_tax_office_workplace_required',
                'Doplňte kód územního pracoviště finančního úřadu pro REGZEL. '
                . 'Prázdný smí zůstat jen u Specializovaného finančního úřadu (4000).',
            );
        }
        if (!in_array(
            $workplaceCode,
            self::workplacesForOffice($taxOfficeCode),
            true,
        )) {
            throw new RegzelValidationException(
                'regzel_tax_office_workplace_mismatch',
                'Vybrané územní pracoviště nepatří pod zadaný finanční úřad '
                . 'nebo není v aktuálním číselníku finanční správy.',
            );
        }
    }

    private static function invalidOffice(): RegzelValidationException
    {
        return new RegzelValidationException(
            'regzel_tax_office_invalid',
            'Vyberte platný čtyřmístný kód finančního úřadu pro REGZEL. '
            . 'Nejde o tříčíselný kód EPO ani o kód jeho územního pracoviště.',
        );
    }

    private static function invalidWorkplace(): RegzelValidationException
    {
        return new RegzelValidationException(
            'regzel_tax_office_workplace_invalid',
            'Kód územního pracoviště finančního úřadu pro REGZEL musí mít '
            . 'platný kód z číselníku finanční správy.',
        );
    }
}
