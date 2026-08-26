<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

final class RegzelTaxOfficeCode
{
    /** @var array<string,list<string>> */
    private const WORKPLACES_BY_OFFICE = [
        '2000' => [
            '2001', '2002', '2003', '2004', '2005', '2006', '2007',
            '2008', '2009', '2010', '2011', '2012',
        ],
        '2100' => [
            '2101', '2102', '2103', '2104', '2105', '2106', '2109',
            '2110', '2111', '2112', '2113', '2114', '2115', '2118',
            '2119', '2120', '2121', '2122', '2124', '2125',
        ],
        '2200' => ['2201', '2203', '2205', '2208', '2209', '2211', '2212'],
        '2300' => ['2301', '2302', '2303', '2305', '2308', '2312', '2313'],
        '2400' => ['2401', '2403', '2407'],
        '2500' => [
            '2501', '2503', '2504', '2505', '2507', '2509', '2510',
            '2512', '2513', '2514', '2515',
        ],
        '2600' => ['2601', '2602', '2604', '2607', '2609'],
        '2700' => ['2701', '2707', '2709', '2712', '2713'],
        '2800' => ['2801', '2804', '2808', '2809', '2810', '2811'],
        '2900' => ['2901', '2903', '2910', '2912', '2913', '2914'],
        '3000' => [
            '3001', '3002', '3003', '3004', '3005', '3006', '3007',
            '3008', '3010', '3011', '3013', '3018', '3019', '3020',
        ],
        '3100' => [
            '3101', '3102', '3103', '3106', '3107', '3108', '3109', '3110',
        ],
        '3200' => [
            '3201', '3202', '3203', '3205', '3207', '3210', '3212',
            '3213', '3214', '3215', '3216', '3218',
        ],
        '3300' => [
            '3301', '3304', '3306', '3307', '3308', '3309', '3310', '3312',
        ],
    ];

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
            || array_key_exists($code, self::WORKPLACES_BY_OFFICE);
    }

    /** @return list<string> */
    public static function officeCodes(): array
    {
        return [
            ...array_map('strval', array_keys(self::WORKPLACES_BY_OFFICE)),
            '4000',
        ];
    }

    /** @return list<string> */
    public static function workplacesForOffice(string $taxOfficeCode): array
    {
        return self::WORKPLACES_BY_OFFICE[$taxOfficeCode] ?? [];
    }

    public static function isValidWorkplace(string $code): bool
    {
        foreach (self::WORKPLACES_BY_OFFICE as $workplaces) {
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
