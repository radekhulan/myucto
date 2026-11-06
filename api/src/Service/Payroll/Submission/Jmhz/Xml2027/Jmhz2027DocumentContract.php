<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Jmhz\Xml2027;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;

/**
 * Kontrakt klíčů normalizovaného dokumentu scénáře 1
 * (`payroll-jmhz-scenario1-document.v2`), které datová věta 2027 potřebuje
 * NAVÍC proti verzi 2026. Klíče plní resolver (WP-5); serializér 2027 je jen
 * čte a nic z nich nedopočítává.
 *
 * Pravidla kontraktu:
 *
 * - **povinný klíč** musí v dokumentu BÝT (`array_key_exists`). Chybí-li,
 *   hlášení se nesestaví a výjimka {@see self::MISSING_CODE} vyjmenuje
 *   všechny chybějící atributy najednou,
 * - u povinného klíče s hodnotou `null` záleží na atributu: `null` je
 *   doložené „nemá" jen tam, kde to níž výslovně stojí (číslo popisné, PSČ,
 *   sleva na studenta, samostatný základ § 6 odst. 4); jinde je to vada
 *   jako chybějící klíč,
 * - **nepovinný klíč** může chybět; atribut se pak nevykáže.
 *
 * Cesty (vše uvnitř `payload`):
 *
 * | cesta | atributy | povinnost |
 * |---|---|---|
 * | `employer.collective_agreement_types` (list kódů) | 10214 | vždy (souhrn 2027 nese kolektivní smlouvy každý měsíc) |
 * | `people[].summary.advance_tax_multi_month` (bool) | 10579 | vždy (na primárním vztahu) |
 * | `people[].summary.taxpayer_declaration_signed_on` (`Y-m-d`) | 10575 | při `taxpayer_declaration_signed = true` (IN16) |
 * | `people[].summary.student_credit` (`{amount_czk:int, doctoral:bool}` nebo `null`) | 10565, 10566 | klíč při podepsaném prohlášení; `null` = sleva neuplatněna |
 * | `people[].summary.paragraph6_tax_czk` (`{base:int, withheld:int}` nebo `null`) | 10307, 10309 | nepovinný; povinný, nese-li dokument ještě 2026 `withholding_tax_czk` |
 * | `people[].summary.nonresident_board_fee_czk` (int nebo `null`) | 10308 | nepovinný |
 * | `people[].summary.annual.monthly_bonus_total_czk` (int nebo `null`) | 10577 | nepovinný (jen leden) |
 * | `people[].summary.ozp_wage.social_insurance_paid_on` (`Y-m-d`) | 10570 | při vyplněném `ozp_wage` (IN28) |
 * | `people[].summary.ozp_wage.health_insurance_paid_on` (`Y-m-d`) | 10571 | při vyplněném `ozp_wage` (IN28) |
 * | `people[].employments[].paragraph6_income_czk` (int) | 10555 | vždy |
 * | `people[].employments[].state_prosecutor` (bool) | 10587 | formulář činnosti K–S (scénář 3) |
 * | `people[].employments[].risk_work.categories` (list `{code, worked_millihours, shift_count}`) | 10273, 10274, 10580 | při rizikové práci (IN29); `shift_count` při kódu 1 a hodinách > 0 (IN42) |
 * | `people[].employments[].sickness_insurance` (`{excluded_days_total, excluded_days:{omluvenaNepritomnost, pracovniNeschopnost, vyplaceniDavek}}` nebo `null`) | 10366, 10473–10475 | nepovinný; bez klíče se § 18 sečte ze sekcí ELDP |
 * | `people[].employments[].term.jmhz_workplace_house_number` (číslo nebo `null`) | 10552 | klíč u formulářů s místem výkonu práce mimo „Mimo ČR" (111111) |
 * | `people[].employments[].term.jmhz_workplace_postal_code` (`\d{5}` nebo `null`) | 10554 | dtto |
 * | `people[].employments[].term.jmhz_workplace_address_point_code` | 10550 | nepovinný |
 * | `people[].employments[].term.jmhz_workplace_street` | 10551 | nepovinný |
 * | `people[].employments[].term.jmhz_workplace_orientation_number` | 10553 | nepovinný |
 *
 * Význam existujících klíčů se ve verzi 2027 mění u těchto atributů:
 *
 * - `employments[].taxable_income_czk` je 10535 „příjem pro výpočet zálohy
 *   bez osvobozených" a u příjmu zdaněného samostatným základem § 6 odst. 4
 *   je nulový (kontrola 304: 10535 a 10555 nesmí být kladné oba),
 * - `summary.annual.result.*` jsou ve verzi 2027 NEZÁPORNÉ: 10321 doplatek
 *   z ročního zúčtování = 10322 přeplatek na dani + 10323 doplatek bonusu
 *   (kontrola 78),
 * - `summary.ozp_wage.payment_date` je 10589 (dříve 10347),
 * - `social_base.reported_income_czk` je 10476 „započitatelný příjem".
 *
 * Zrušené atributy (10267, 10292–10296, 10310, 10347, 10411, 10412, 10416,
 * 10418, 10531–10533) serializér 2027 nepíše, i když je dokument nese.
 * 10311 a 10312 se za vykazovaná období roku 2027 nepíšou (aktualita MPSV
 * 7. 10. 2026: poprvé za leden 2028).
 */
final class Jmhz2027DocumentContract
{
    public const MISSING_CODE = 'jmhz_xml_2027_attribute_missing';

    /** Hodnoty dokumentu, které datová věta 2027 nepřipouští (kontroly 78, 304, IN34). */
    public const INCONSISTENT_CODE = 'jmhz_xml_2027_value_inconsistent';

    /**
     * První vykazované období, za které se v ročních úhrnech vyplňují 10311
     * a 10312 (aktualita MPSV 7. 10. 2026: za leden 2027 ne, poprvé leden 2028).
     */
    public const ANNUAL_WITHHOLDING_FIRST_PERIOD = '2028-01';

    public const EMPLOYER_COLLECTIVE_AGREEMENTS = 'collective_agreement_types';

    public const SUMMARY_MULTI_MONTH_ADVANCE = 'advance_tax_multi_month';
    public const SUMMARY_DECLARATION_SIGNED_ON = 'taxpayer_declaration_signed_on';
    public const SUMMARY_STUDENT_CREDIT = 'student_credit';
    public const SUMMARY_PARAGRAPH6_TAX = 'paragraph6_tax_czk';
    public const SUMMARY_NONRESIDENT_BOARD_FEE = 'nonresident_board_fee_czk';
    public const ANNUAL_MONTHLY_BONUS_TOTAL = 'monthly_bonus_total_czk';
    public const OZP_SOCIAL_PAID_ON = 'social_insurance_paid_on';
    public const OZP_HEALTH_PAID_ON = 'health_insurance_paid_on';

    public const EMPLOYMENT_PARAGRAPH6_INCOME = 'paragraph6_income_czk';
    public const EMPLOYMENT_STATE_PROSECUTOR = 'state_prosecutor';
    public const EMPLOYMENT_SICKNESS_INSURANCE = 'sickness_insurance';
    public const RISK_CATEGORIES = 'categories';

    public const TERM_ADDRESS_POINT = 'jmhz_workplace_address_point_code';
    public const TERM_STREET = 'jmhz_workplace_street';
    public const TERM_HOUSE_NUMBER = 'jmhz_workplace_house_number';
    public const TERM_ORIENTATION_NUMBER = 'jmhz_workplace_orientation_number';
    public const TERM_POSTAL_CODE = 'jmhz_workplace_postal_code';

    /** Kategorizace rizika, u které se vykazuje počet směn 10580 (IN42). */
    public const RISK_SHIFT_CODE = '1';

    /** Specifická právní skutečnost 10408 „účinnost rozhodnutí o úpadku" (CIS Rozhodná skutečnost). */
    public const INSOLVENCY_LEGAL_FACT_CODE = '8';

    /** Kód obce „Mimo ČR" (CISOB, interakce IN41): adresa se nevyplňuje. */
    public const MUNICIPALITY_ABROAD = '111111';

    /** Scénáře, jejichž formulář nese místo výkonu práce (`vykonavanaPozice`). */
    private const POSITION_SCENARIOS = ['scenario_1', 'scenario_3', 'scenario_4', 'scenario_6'];

    /**
     * Celý dokument: chybějící povinné klíče verze 2027 najednou, aby účetní
     * (a WP-5) viděli úplný seznam, ne jen první vadu.
     *
     * @param array<string,mixed> $payload
     */
    public static function assertComplete(array $payload): void
    {
        $missing = [];
        $employer = self::object($payload['employer'] ?? null);
        if (!array_key_exists(self::EMPLOYER_COLLECTIVE_AGREEMENTS, $employer)) {
            $missing['10214'] = [];
        }
        foreach (self::rows($payload['people'] ?? null) as $person) {
            $summary = self::object($person['summary'] ?? null);
            foreach (self::rows($person['employments'] ?? null) as $employment) {
                $employmentId = is_int($employment['employment_id'] ?? null)
                    ? $employment['employment_id']
                    : null;
                foreach (self::missingForForm($summary, $employment) as $attributeId) {
                    $missing[$attributeId] ??= [];
                    if ($employmentId !== null) {
                        $missing[$attributeId][$employmentId] = true;
                    }
                }
            }
        }
        if ($missing !== []) {
            self::throwMissing($missing);
        }
    }

    /**
     * Chybějící povinné klíče jednoho formuláře (vztahu). Souhrn osoby se
     * posuzuje jen na primárním vztahu, který ho ve formuláři nese.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $employment
     * @return list<string> ID atributů
     */
    public static function missingForForm(array $summary, array $employment): array
    {
        $missing = [];
        if (($employment['primary'] ?? null) === true) {
            if (!array_key_exists(self::SUMMARY_MULTI_MONTH_ADVANCE, $summary)) {
                $missing[] = '10579';
            }
            if (($summary['taxpayer_declaration_signed'] ?? null) === true) {
                if (!array_key_exists(self::SUMMARY_DECLARATION_SIGNED_ON, $summary)) {
                    $missing[] = '10575';
                }
                if (!array_key_exists(self::SUMMARY_STUDENT_CREDIT, $summary)) {
                    $missing[] = '10565';
                }
            }
            if (($summary['withholding_tax_czk'] ?? null) !== null
                && !array_key_exists(self::SUMMARY_PARAGRAPH6_TAX, $summary)
            ) {
                $missing[] = '10307';
                $missing[] = '10309';
            }
            $ozp = $summary['ozp_wage'] ?? null;
            if (is_array($ozp)) {
                if (!array_key_exists(self::OZP_SOCIAL_PAID_ON, $ozp)) {
                    $missing[] = '10570';
                }
                if (!array_key_exists(self::OZP_HEALTH_PAID_ON, $ozp)) {
                    $missing[] = '10571';
                }
            }
        }
        if (!array_key_exists(self::EMPLOYMENT_PARAGRAPH6_INCOME, $employment)) {
            $missing[] = '10555';
        }
        if (self::stateProsecutorRequired($employment)
            && !array_key_exists(self::EMPLOYMENT_STATE_PROSECUTOR, $employment)
        ) {
            $missing[] = '10587';
        }
        if (self::workplaceAddressRequired($employment)) {
            $term = self::object($employment['term'] ?? null);
            if (!array_key_exists(self::TERM_HOUSE_NUMBER, $term)) {
                $missing[] = '10552';
            }
            if (!array_key_exists(self::TERM_POSTAL_CODE, $term)) {
                $missing[] = '10554';
            }
        }
        $risk = self::object($employment['risk_work'] ?? null);
        $codes = $risk['categorization_codes'] ?? null;
        if (is_array($codes) && $codes !== []
            && !array_key_exists(self::RISK_CATEGORIES, $risk)
        ) {
            $missing[] = '10273';
            $missing[] = '10580';
        }

        return array_values(array_unique($missing));
    }

    /**
     * Nese formulář vztahu příznak státního zástupce (10587)? Jen formulář
     * činnosti K až S (scénář 3). Volá ho i resolver, aby o povinnosti
     * rozhodovalo jedno místo.
     *
     * @param array<string,mixed> $employment
     */
    public static function stateProsecutorRequired(array $employment): bool
    {
        return (self::object($employment['selector'] ?? null)['scenario_key'] ?? null) === 'scenario_3';
    }

    /**
     * Musí formulář vztahu nést adresu místa výkonu práce v ČR (10552,
     * 10554)? Formuláře s `vykonavanaPozice` mimo kód obce „Mimo ČR" (IN41).
     *
     * @param array<string,mixed> $employment
     */
    public static function workplaceAddressRequired(array $employment): bool
    {
        $scenario = self::object($employment['selector'] ?? null)['scenario_key'] ?? null;
        if (!in_array($scenario, self::POSITION_SCENARIOS, true)) {
            return false;
        }

        return (self::object($employment['term'] ?? null)['jmhz_workplace_municipality_code'] ?? null)
            !== self::MUNICIPALITY_ABROAD;
    }

    /** Vykazuje kategorie rizika počet směn (IN42: 10274 = 1 a 10273 > 0)? */
    public static function riskShiftCountRequired(string $categorizationCode, int $workedMillihours): bool
    {
        return $categorizationCode === self::RISK_SHIFT_CODE && $workedMillihours > 0;
    }

    /**
     * Nese podání výplatní termín 10410 na každém formuláři (IN38, kontrola 82
     * katalogu 1.5.0.4)? Jen při specifické právní skutečnosti 10408 = 8
     * „účinnost rozhodnutí o úpadku (§ 244 odst. 1 daňového řádu)"; u jiného
     * kódu a bez skutečnosti 10410 být nesmí. Ve verzi 2026 (katalog
     * 1.4.2.11) se 10410 žádá při jakékoli skutečnosti.
     */
    public static function payoutDateRequired(?string $specificLegalFactCode): bool
    {
        return $specificLegalFactCode === self::INSOLVENCY_LEGAL_FACT_CODE;
    }

    /** @param array<string,array<int,true>> $missing atribut => vztahy */
    private static function throwMissing(array $missing): never
    {
        ksort($missing, SORT_STRING);
        $employments = [];
        foreach ($missing as $ids) {
            foreach (array_keys($ids) as $id) {
                $employments[$id] = true;
            }
        }
        ksort($employments);
        $message = 'Datovou větu JMHZ 2027 nelze sestavit: normalizovaný dokument nenese'
            . ' povinné atributy ' . implode(', ', array_keys($missing)) . '.';
        if ($employments !== []) {
            $message .= ' Dotčené pracovní vztahy: ' . implode(', ', array_keys($employments)) . '.';
        }

        throw new JmhzXmlException(self::MISSING_CODE, $message);
    }

    /** @param list<string> $attributeIds */
    public static function throwMissingForForm(array $attributeIds): never
    {
        $missing = [];
        foreach ($attributeIds as $attributeId) {
            $missing[$attributeId] = [];
        }
        self::throwMissing($missing);
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $row): bool => is_array($row) && !array_is_list($row),
        ));
    }
}
