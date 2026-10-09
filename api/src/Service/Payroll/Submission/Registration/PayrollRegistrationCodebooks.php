<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

/**
 * Číselníky REGZEC25 převzaté z EDV 1.4.0.6 (listy CIS_* a C_*), proti kterým
 * se ověřují kódy profilu registrace. XSD je nehlídá (atributy jsou volné
 * řetězce), ČSSZ podání s kódem mimo číselník zamítne.
 *
 * Stát je C_STAT (číselník zemí CZEM ČSÚ 1186) v rozsahu, který EDV uvádí.
 */
final class PayrollRegistrationCodebooks
{
    /** Stát (C_STAT, CZEM ČSÚ 1186) */
    public const COUNTRY = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW',
        'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN',
        'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD', 'CF', 'CG',
        'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI',
        'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL',
        'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR',
        'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM',
        'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA',
        'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME',
        'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU',
        'MV', 'MW', 'MX', 'MY', 'MZ', 'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP',
        'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR',
        'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW', 'SA', 'SB', 'SC', 'SD',
        'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV',
        'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO',
        'TR', 'TT', 'TV', 'TW', 'TZ', 'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE',
        'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'XK', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /** Druh důchodu (C_DUCH) */
    public const PENSION_TYPE = [
        '1', '2', '8', 'A', 'B', 'C',
    ];

    /** Kategorie dosaženého vzdělání (KKOV) */
    public const EDUCATION = [
        'A', 'B', 'C', 'D', 'E', 'H', 'J', 'K', 'L', 'M', 'N', 'P', 'R', 'T', 'V', 'Z',
    ];

    /** Pracovní režim */
    public const WORK_MODE = [
        '1', '2', '3', '4',
    ];

    /** Průběh práce (Práce probíhá převážně) */
    public const WORK_PLACE = [
        '1', '2', '3', '4',
    ];

    /** Typ dokladu (CIS Typ dokladu) */
    public const PROOF_TYPE = [
        'I', 'P', 'O',
    ];

    /** Typ daňové identifikace */
    public const TAX_IDENTIFIER_TYPE = [
        'D', 'R', 'S', 'J',
    ];

    /** Zdravotní omezení */
    public const HEALTH_RESTRICTION = [
        '1', '2', '3', '4', '5',
    ];

    /** Druh pracovního oprávnění */
    public const PERMIT_TYPE = [
        '1', '2', '3', '4',
    ];

    /** Důvod pro volný přístup na trh práce */
    public const FREE_ACCESS_REASON = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16',
        '17', '18', '19', '20', '21',
    ];

    /** Krajské pobočky ÚP ČR */
    public const LABOUR_OFFICE = [
        'HMP', 'JMK', 'JCK', 'HKK', 'VYK', 'KVK', 'LBK', 'OLK', 'MSK', 'PAK', 'PMK', 'SCK',
        'ULK', 'ZLK',
    ];

    /** Důvody ukončení zaměstnání (pro 10534) */
    public const EARLY_TERMINATION = [
        '1', '2', '3',
    ];

    /** Důvod ukončení služebního poměru pro ÚP (C_DUVUKSLUZPOM) */
    public const SERVICE_TERMINATION = [
        '1', '2', '3', '4', '5', '6',
    ];

    /** @param list<string> $codebook */
    public static function contains(array $codebook, ?string $code): bool
    {
        return $code !== null && in_array($code, $codebook, true);
    }
}
