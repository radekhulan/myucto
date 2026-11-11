<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationCodebooks;

/**
 * Údaje pro registraci ČSSZ ze šablony Vzoru MyÚčto: adresa trvalého pobytu,
 * občanství, CZ-ISCO, druh činnosti, místo výkonu práce, daňová rezidence,
 * prohlášení poplatníka, vzdělání a postavení v zaměstnání.
 *
 * Tady se jen čtou a převádějí na skupiny zápisu věty registrace
 * ({@see \MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter::supplement()});
 * zapisuje se stejnou cestou jako import registrace. U existující osoby
 * {@see self::gaps()} nechá jen to, co v evidenci chybí: import nic nepřepisuje.
 *
 * @phpstan-type RegistrationSteps array{
 *   terms?:array<string,string>,
 *   identity_facts?:array<string,string>,
 *   addresses?:array<string,array<string,string>>,
 *   tax_residence?:array{residence:string,country_code:string,identifier:?string},
 *   a1_profile?:array<string,mixed>
 * }
 * @phpstan-type RegistrationPlan array{
 *   steps:RegistrationSteps,
 *   declaration:?array{signed_on:string},
 *   warnings:list<string>
 * }
 * @phpstan-type RegistrationState array{
 *   residence_address:bool,
 *   citizenship:?string,
 *   cz_isco_code:?string,
 *   activity_code:?string,
 *   workplace:bool,
 *   tax_residence:bool,
 *   tax_declaration:bool,
 *   a1_profile:bool
 * }
 */
final class AttendanceRegistrationData
{
    private const YES = ['ano', 'a', 'yes', 'y', '1', 'x', 'true', 'podepsano', 'podepsane'];
    private const NO = ['ne', 'n', 'no', '0', 'false', 'nepodepsano', 'nepodepsane'];

    /**
     * @param array<string,string> $raw význam => text z podkladů
     * @param string $start den nástupu, od kterého údaje platí
     * @param AttendanceRegistrationCodebooks|null $codebooks ověření CZ-ISCO, druhu činnosti
     *        a obce proti číselníkům; bez nich se kontroluje jen tvar
     * @return RegistrationPlan
     */
    public static function plan(array $raw, string $start, ?AttendanceRegistrationCodebooks $codebooks = null): array
    {
        $steps = [];
        $warnings = [];
        $text = static fn (string $key): string => trim((string) ($raw[$key] ?? ''));

        $country = self::country($text('address_country'));
        if ($text('address_country') !== '' && $country === null) {
            $warnings[] = self::countryWarning('Stát', $text('address_country'), 'adresa se nezapíše');
        }
        $street = trim($text('address_street') . ' ' . $text('address_house_number'));
        $city = $text('address_city');
        $postal = $text('address_postal_code');
        if ($street !== '' || $city !== '' || $postal !== '') {
            $country ??= $text('address_country') === '' ? 'CZ' : null;
            if ($street === '' || $city === '' || $postal === '') {
                $warnings[] = 'Adresa trvalého pobytu je neúplná (ulice a číslo, obec, PSČ); nezapíše se.';
            } elseif ($country !== null) {
                $digits = preg_replace('/\s+/', '', $postal) ?? $postal;
                $steps['addresses'] = ['residence' => [
                    'street_line' => mb_substr($street, 0, 255),
                    'city' => mb_substr($city, 0, 255),
                    'postal_code' => $country === 'CZ' && preg_match('/^\d{5}$/', $digits) === 1 ? $digits : mb_substr($postal, 0, 24),
                    'country_code' => $country,
                ]];
            }
        }

        if ($text('citizenship') !== '') {
            $citizenship = self::country($text('citizenship'));
            if ($citizenship === null) {
                $warnings[] = self::countryWarning('Státní občanství', $text('citizenship'), 'nezapíše se');
            } else {
                $steps['identity_facts'] = ['citizenship_country_code' => $citizenship];
            }
        }

        $terms = [];
        if ($text('cz_isco_code') !== '') {
            $isco = preg_replace('/\D+/', '', $text('cz_isco_code')) ?? '';
            if (preg_match('/^\d{4,5}$/', $isco) !== 1) {
                $warnings[] = "CZ-ISCO: kód „{$text('cz_isco_code')}“ nemá čtyři nebo pět číslic; nezapíše se.";
            } elseif ($codebooks !== null && !$codebooks->czIscoKnown($isco)) {
                $warnings[] = "CZ-ISCO: kód {$isco} v klasifikaci zaměstnání ČSÚ neexistuje; nezapíše se. "
                    . 'Opravte ho v podkladech, nebo ho po importu vyberte z našeptávače na kartě vztahu.';
            } else {
                $terms['cz_isco_code'] = $isco;
            }
        }
        if ($text('activity_code') !== '') {
            $activity = mb_strtoupper($text('activity_code'));
            if (preg_match('/^[0-9A-Z]{1,2}$/', $activity) !== 1) {
                $warnings[] = "Druh činnosti: „{$text('activity_code')}“ není kód ČSSZ (např. T u DPP); nezapíše se.";
            } elseif ($codebooks !== null && !$codebooks->activityKnown($activity)) {
                $warnings[] = "Druh činnosti: kód {$activity} není v číselníku druhů činnosti JMHZ; nezapíše se "
                    . 'a aplikace doplní výchozí kód podle druhu vztahu.';
            } else {
                $terms['activity_code'] = $activity;
            }
        }
        if ($text('workplace') !== '') {
            $workplace = $text('workplace');
            if (preg_match('/^\d{6}$/', $workplace) === 1) {
                $problem = $codebooks?->municipalityProblem($workplace, $start);
                if ($problem === null) {
                    $terms['jmhz_workplace_municipality_code'] = $workplace;
                    $terms['jmhz_workplace_country_code'] = 'CZ';
                } else {
                    $warnings[] = "Místo výkonu práce: kód obce {$workplace} k datu nástupu v číselníku obcí ČSSZ (CISOB) "
                        . 'neplatí; nezapíše se. Zadejte šestimístný kód obce, např. 554782 = Praha.';
                }
            } else {
                // Název obce z podkladů: jednoznačná shoda v CISOB doplní kód
                // obce i úřední název, jinak by měsíční hlášení JMHZ skončilo
                // na neověřeném pracovišti (10229, 10230).
                $municipality = $codebooks?->municipalityByName($workplace, $start);
                if ($municipality !== null) {
                    $terms['work_place'] = $municipality['label'];
                    $terms['jmhz_workplace_municipality_code'] = $municipality['code'];
                    $terms['jmhz_workplace_country_code'] = 'CZ';
                } else {
                    $terms['work_place'] = mb_substr($workplace, 0, 255);
                    if ($codebooks !== null && $codebooks->municipalitiesCover($start)) {
                        $warnings[] = "Místo výkonu práce: „{$workplace}“ nejde jednoznačně najít v číselníku obcí ČSSZ; "
                            . 'zapíše se jen jako název. Pro měsíční hlášení JMHZ zadejte šestimístný kód obce '
                            . '(např. 554782 = Praha), v podkladech nebo na kartě vztahu.';
                    }
                }
            }
        }
        if ($terms !== []) {
            $steps['terms'] = $terms;
        }

        $residence = $text('tax_residence');
        // Bez sloupce platí rezidence ČR jen u osoby s českou adresou z podkladů.
        $residenceCountry = $residence === ''
            ? (($steps['addresses']['residence']['country_code'] ?? null) === 'CZ' ? 'CZ' : null)
            : self::country($residence);
        if ($residence !== '' && $residenceCountry === null) {
            $warnings[] = self::countryWarning('Daňová rezidence', $residence, 'nezapíše se');
        }
        if ($residenceCountry !== null) {
            $steps['tax_residence'] = [
                'residence' => $residenceCountry === 'CZ' ? 'czech-resident' : 'non-resident',
                'country_code' => $residenceCountry,
                'identifier' => null,
            ];
        }

        $declaration = null;
        if ($text('tax_declaration') !== '') {
            $flag = AttendanceText::normalize($text('tax_declaration'));
            if (in_array($flag, self::YES, true)) {
                $from = $text('tax_declaration_from');
                $signedOn = $from === '' ? $start : AttendancePersonAggregator::isoDate($from);
                if ($signedOn === null) {
                    $warnings[] = "Datum prohlášení „{$from}“ nejde přečíst; prohlášení se nezapíše.";
                } else {
                    $declaration = ['signed_on' => $signedOn];
                }
            } elseif (!in_array($flag, self::NO, true)) {
                $warnings[] = "Prohlášení poplatníka „{$text('tax_declaration')}“ není ano ani ne; nezapíše se.";
            }
        }

        $a1 = [];
        $status = $text('employment_status_code');
        if ($status !== '') {
            if (preg_match('/^\d{4}$/', $status) === 1) {
                $a1['employment']['employment_status_code'] = $status;
            } else {
                $warnings[] = "Postavení v zaměstnání „{$status}“ není čtyřmístný kód NKPZ (např. 1111); do profilu registrace se nezapíše.";
            }
        }
        $education = mb_strtoupper($text('education_code'));
        if ($education !== '') {
            if (in_array($education, PayrollRegistrationCodebooks::EDUCATION, true)) {
                $a1['facts']['highest_education_code'] = $education;
            } else {
                $warnings[] = "Vzdělání „{$text('education_code')}“ není kód KKOV (např. K, M, R); do profilu registrace se nezapíše.";
            }
        }
        if ($a1 !== []) {
            $steps['a1_profile'] = $a1;
        }

        return ['steps' => $steps, 'declaration' => $declaration, 'warnings' => $warnings];
    }

    /**
     * U existující osoby jen to, co v evidenci chybí.
     *
     * @param RegistrationPlan $plan
     * @param RegistrationState $state
     * @return RegistrationPlan
     */
    public static function gaps(array $plan, array $state): array
    {
        $steps = $plan['steps'];
        if ($state['residence_address']) {
            unset($steps['addresses']);
        }
        if ($state['citizenship'] !== null) {
            unset($steps['identity_facts']);
        }
        if (isset($steps['terms'])) {
            $terms = $steps['terms'];
            if ($state['cz_isco_code'] !== null) {
                unset($terms['cz_isco_code']);
            }
            if ($state['activity_code'] !== null) {
                unset($terms['activity_code']);
            }
            if ($state['workplace']) {
                unset($terms['work_place'], $terms['jmhz_workplace_municipality_code'], $terms['jmhz_workplace_country_code']);
            }
            if ($terms === []) {
                unset($steps['terms']);
            } else {
                $steps['terms'] = $terms;
            }
        }
        if ($state['tax_residence']) {
            unset($steps['tax_residence']);
        }
        if ($state['a1_profile']) {
            unset($steps['a1_profile']);
        }

        return [
            'steps' => $steps,
            'declaration' => $state['tax_declaration'] ? null : $plan['declaration'],
            'warnings' => $plan['warnings'],
        ];
    }

    public static function isEmpty(array $plan): bool
    {
        return $plan['steps'] === [] && $plan['declaration'] === null;
    }

    /**
     * Co se zapíše, pro náhled. Ulici ani PSČ klient nedostane, stačí obec.
     *
     * @param RegistrationPlan $plan
     * @return list<array{key:string,value:string}>
     */
    public static function display(array $plan): array
    {
        $steps = $plan['steps'];
        $items = [];
        if (isset($steps['addresses']['residence'])) {
            $address = $steps['addresses']['residence'];
            $items[] = ['key' => 'address', 'value' => $address['city'] . ' (' . $address['country_code'] . ')'];
        }
        if (isset($steps['identity_facts']['citizenship_country_code'])) {
            $items[] = ['key' => 'citizenship', 'value' => $steps['identity_facts']['citizenship_country_code']];
        }
        $terms = $steps['terms'] ?? [];
        foreach (['cz_isco_code', 'activity_code'] as $field) {
            if (isset($terms[$field])) {
                $items[] = ['key' => $field, 'value' => $terms[$field]];
            }
        }
        if (isset($terms['work_place']) || isset($terms['jmhz_workplace_municipality_code'])) {
            $items[] = ['key' => 'workplace', 'value' => $terms['work_place'] ?? $terms['jmhz_workplace_municipality_code']];
        }
        if (isset($steps['tax_residence'])) {
            $items[] = ['key' => 'tax_residence', 'value' => $steps['tax_residence']['country_code']];
        }
        if ($plan['declaration'] !== null) {
            $items[] = ['key' => 'tax_declaration', 'value' => $plan['declaration']['signed_on']];
        }
        if (isset($steps['a1_profile']['employment']['employment_status_code'])) {
            $items[] = ['key' => 'employment_status_code', 'value' => (string) $steps['a1_profile']['employment']['employment_status_code']];
        }
        if (isset($steps['a1_profile']['facts']['highest_education_code'])) {
            $items[] = ['key' => 'education_code', 'value' => (string) $steps['a1_profile']['facts']['highest_education_code']];
        }

        return $items;
    }

    private static function country(string $value): ?string
    {
        $code = mb_strtoupper(trim($value));
        if ($code === '') {
            return null;
        }

        return preg_match('/^[A-Z]{2}$/', $code) === 1 && in_array($code, PayrollRegistrationCodebooks::COUNTRY, true)
            ? $code
            : null;
    }

    private static function countryWarning(string $column, string $value, string $consequence): string
    {
        $code = mb_strtoupper(trim($value));

        return preg_match('/^[A-Z]{2}$/', $code) === 1
            ? "{$column}: kód „{$code}“ není v číselníku států ČSÚ (CZEM); {$consequence}."
            : "{$column}: „{$value}“ není dvoupísmenný kód státu (CZ, SK, UA…); {$consequence}.";
    }
}
