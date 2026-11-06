<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class ChartOfAccounts504Policy
{
    private const GROUP_TYPES = [
        '01' => ['asset'], '02' => ['asset'], '03' => ['asset'], '04' => ['asset'],
        '05' => ['asset'], '06' => ['asset'], '07' => ['asset'], '08' => ['asset'],
        '11' => ['asset'], '12' => ['asset'], '13' => ['asset'],
        '21' => ['asset'], '22' => ['asset'], '23' => ['liability'], '24' => ['liability'],
        '25' => ['asset', 'liability'], '26' => ['asset'],
        '31' => ['asset'], '32' => ['liability'], '33' => ['asset', 'liability'],
        '34' => ['asset', 'liability'], '35' => ['asset'], '36' => ['liability'],
        '37' => ['asset', 'liability'], '38' => ['asset', 'liability'], '39' => ['asset', 'liability'],
        '50' => ['expense'], '51' => ['expense'], '52' => ['expense'], '53' => ['expense'],
        '54' => ['expense'], '55' => ['expense'], '56' => ['expense'], '57' => ['expense'],
        '58' => ['expense'], '59' => ['expense'],
        '60' => ['revenue'], '64' => ['revenue'], '65' => ['revenue'], '68' => ['revenue'], '69' => ['revenue'],
        '90' => ['equity'], '91' => ['equity'], '92' => ['equity'], '93' => ['equity'],
        '94' => ['liability'], '95' => ['liability'], '96' => ['closing'],
        '97' => ['offbalance'], '98' => ['offbalance'], '99' => ['offbalance'],
    ];

    public static function defaultAccount(string $code): ?array
    {
        static $byCode = null;
        $byCode ??= array_column(ChartOfAccountsTemplate504::ACCOUNTS, null, 'code');
        return $byCode[$code] ?? null;
    }

    public static function validationError(string $code, ?string $type, ?string $side, ?string $parentCode = null): ?string
    {
        if ($parentCode === null) {
            if (preg_match('/^[0-9]{3}$/D', $code) !== 1) {
                return 'Syntetický účet rámce 504 musí mít třímístný číselný kód.';
            }
        } elseif (preg_match('/^[0-9]{3}$/D', $parentCode) !== 1
            || preg_match('/^' . preg_quote($parentCode, '/') . '(?:\.?[0-9A-Za-z]+)$/D', $code) !== 1) {
            return 'Analytika rámce 504 musí rozvíjet kód svého syntetického rodiče.';
        }

        $syntheticCode = $parentCode ?? $code;
        $group = substr($syntheticCode, 0, 2);
        $allowed = in_array($syntheticCode[0], ['4', '7', '8'], true)
            ? ['offbalance']
            : (self::GROUP_TYPES[$group] ?? []);
        if (!in_array($type, $allowed, true)) {
            return 'Účet ' . $code . ' má nepřípustnou skupinu nebo typ pro rámec 504.';
        }

        $default = self::defaultAccount($syntheticCode);
        if ($default !== null && ($default['type'] !== $type || $default['normal_side'] !== $side)) {
            return 'Typ nebo normální strana účtu ' . $code . ' neodpovídá jeho roli v rámci 504.';
        }
        return null;
    }
}
