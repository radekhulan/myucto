<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use Rikudou\Iban\Iban\CzechIbanAdapter;

/**
 * Bankovní účty finančních úřadů pro placení daní.
 *
 * Číslo účtu = předčíslí podle druhu daně + matrika úřadu, vše u ČNB (0710).
 * Zdroj: Finanční správa, Placení daní → Bankovní účty finančních úřadů,
 * Příloha č. 1 (matriky FÚ a SFÚ) a č. 2 (předčíslí), stav k 1. 1. 2026.
 * Klíčem je kód FÚ z EPO číselníku `ufo` (atribut `c_ufo` ve větě D/P).
 *
 * DPH ve zvláštním režimu jednoho správního místa (OSS) se na předčíslí 705
 * platit nesmí, proto ho tady nikdo pro OSS/MOSS nevolá.
 */
final class TaxOfficeBankAccounts
{
    public const BANK_CODE = '0710';

    public const PREFIX_VAT = '705';
    public const PREFIX_DPPO = '7704';
    public const PREFIX_DPFO = '721';
    public const PREFIX_EMPLOYMENT = '713';
    public const PREFIX_WITHHOLDING = '7720';

    /** kód FÚ → matrika; název úřadu je v TaxOfficeCatalog */
    private const OFFICES = [
        '451' => '77628031',
        '452' => '77628111',
        '453' => '77627231',
        '454' => '77627311',
        '455' => '77629341',
        '456' => '77621411',
        '457' => '77628461',
        '458' => '77626511',
        '459' => '77622561',
        '460' => '67626681',
        '461' => '77628621',
        '462' => '47623811',
        '463' => '77621761',
        '464' => '47620661',
        '13' => '77620021',
    ];

    /**
     * Účet úřadu pro daný druh daně, nebo null u neznámého kódu FÚ.
     *
     * @return array{office_code:string,office_name:string,prefix:string,number:string,bank_code:string,account:string,iban:string}|null
     */
    public static function account(?string $officeCode, string $prefix): ?array
    {
        $code = ltrim(trim((string) $officeCode), '0');
        $office = self::OFFICES[$code] ?? null;
        if ($office === null) {
            return null;
        }
        $number = $office;
        $name = (string) TaxOfficeCatalog::officeName($code);
        $accountNumber = $prefix . '-' . $number;

        return [
            'office_code' => $code,
            'office_name' => $name,
            'prefix' => $prefix,
            'number' => $number,
            'bank_code' => self::BANK_CODE,
            'account' => $accountNumber . '/' . self::BANK_CODE,
            'iban' => (new CzechIbanAdapter($accountNumber, self::BANK_CODE))->asString(),
        ];
    }
}
