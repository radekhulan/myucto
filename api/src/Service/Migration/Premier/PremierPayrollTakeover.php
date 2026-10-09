<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Premier;

use MyInvoice\Service\Geo\CountryNameMatcher;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmployment;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPayoutAccount;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPerson;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPolicy;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRecord;

/**
 * Překlad vztahu z PREMIER ({@see PremierPayroll}) do kanonické podoby převzatých mezd
 * ({@see PayrollTakeoverRecord}). Nic nečte ani nezapisuje.
 *
 * PREMIER zatím plní jen část kanonické podoby (identita, karta, jeden výplatní účet,
 * zákonná evidence, skončení); ostatní pole zůstávají prázdná a zápis je přeskočí.
 */
final class PremierPayrollTakeover
{
    public const LABEL = 'PREMIER';
    private const NOTE = 'Převzato z PREMIER: ';

    /**
     * Pravidla zápisu převodu z PREMIER (viz {@see PayrollTakeoverPolicy}); kde se liší
     * od PAMICA, je to vědomě zachované dosavadní chování převodu z PREMIER.
     */
    public static function policy(): PayrollTakeoverPolicy
    {
        return new PayrollTakeoverPolicy(
            sourceKey: 'premier',
            label: self::LABEL,
            // Chybějící identita, karta, zákonná evidence nebo vztah je nekonzistence, kterou
            // protokol ohlásí u osoby (stejně jako u PAMICA), ne důvod údaj mlčky přeskočit.
            strict: true,
            // Kontaktní adresa (`PER_ADR`) se doplňuje vedle trvalé stejně jako u PAMICA.
            addressesPerType: true,
            birthSurnameOnCurrentVersion: false,
            verifyPayoutAccounts: false,
            countPlannedTermination: false,
            ignoreEndBeforeStart: true,
            rewriteOwnOpenings: true,
        );
    }

    /**
     * @param array<string,mixed> $relation vztah z {@see PremierPayroll::$relations}
     * @param string $until poslední den převáděného období (prohlášení po měsících mezd do něj)
     * @param ?string $moduleStart první měsíc vedení mezd v MyÚčtu (`YYYY-MM`); časové evidence
     *        (nepřítomnosti, dovolená, průměry) se berou jen z měsíců před ním
     * @param ?string $personFrom začátek evidence osoby přes všechny její vztahy
     *        ({@see PayrollTakeoverEvidencePeriod::earliestByPerson()}); bez něj nástup tohoto vztahu
     */
    public static function record(array $relation, string $until, ?CountryNameMatcher $countries = null, ?string $moduleStart = null, ?string $personFrom = null): PayrollTakeoverRecord
    {
        $start = (string) $relation['start'];
        // Zákonná evidence má účinnost po celých měsících (čte se k prvnímu dni měsíce);
        // nástup uprostřed měsíce by uložení celé evidence odmítl.
        $from = substr($start, 0, 7) . '-01';
        if ($personFrom !== null && $personFrom < $from) {
            $from = $personFrom;
        }
        $surname = $relation['birth_surname'];
        $declarations = [];
        foreach (self::declarations($relation, $until) as $run) {
            $declarations[] = new PayrollTakeoverEvidencePeriod(
                $run['status'],
                $run['from'],
                $run['to'],
                'premier:mzdy:' . $run['period'],
                self::NOTE . ($run['status'] === 'signed' ? 'podepsané' : 'nepodepsané') . ' prohlášení poplatníka od mzdy za ' . $run['period'] . '.',
            );
        }
        $account = $relation['account'];
        $children = self::children($relation, $until);
        $person = new PayrollTakeoverPerson(
            key: (string) $relation['person_key'],
            identity: ['birth_date' => $relation['birth_date']] + (array) $relation['identity'],
            // PREMIER rodné příjmení vyplňuje i u osob bez změny jména a přihláška ČSSZ ho vyžaduje vždy
            // (`birth/@nam`), takže se bere tak, jak je, i když je shodné s příjmením.
            birthSurname: is_string($surname) ? $surname : null,
            residence: self::address(is_array($relation['residence']) ? $relation['residence'] : null, $countries),
            mailing: self::address(is_array($relation['mailing'] ?? null) ? $relation['mailing'] : null, $countries),
            email: is_string($relation['email']) ? $relation['email'] : null,
            phone: is_string($relation['phone']) ? $relation['phone'] : null,
            payoutAccounts: array_key_exists('payout_accounts', $relation)
                ? array_map(static fn (array $a): PayrollTakeoverPayoutAccount => new PayrollTakeoverPayoutAccount($a['account'], $a['bank_code'], $a['active']),
                    (array) $relation['payout_accounts'])
                : (is_array($account) ? [new PayrollTakeoverPayoutAccount($account['account'], $account['bank_code'])] : []),
            taxResidence: $relation['non_resident'] === true
                ? new PayrollTakeoverEvidencePeriod('non-resident', $from, null, 'premier:per_nerz:n_stat',
                    self::NOTE . 'osoba je v PREMIER vedená jako daňový nerezident.', $relation['tax_residence_country'] ?? null)
                : new PayrollTakeoverEvidencePeriod('czech-resident', $from, null, 'premier:per_main:rezident',
                    self::NOTE . 'osoba není v PREMIER vedená jako daňový nerezident.'),
            healthCoverage: is_string($relation['insurer_code'])
                ? new PayrollTakeoverEvidencePeriod($relation['insurer_code'], $from, null, null,
                    self::NOTE . 'zdravotní pojišťovna ' . $relation['insurer_code'] . '.')
                : null,
            socialJurisdiction: $relation['foreign_legislation'] === true
                ? new PayrollTakeoverEvidencePeriod('foreign', $from)
                : new PayrollTakeoverEvidencePeriod('czech', $from, null, null, self::NOTE . 'osoba nepodléhá v PREMIER cizím právním předpisům.'),
            taxDeclarations: $declarations,
            socialDiscountClaims: self::socialDiscounts($relation, $until),
            children: $children['children'],
            childrenWithoutCredit: $children['without_credit'],
            firstSignedPeriod: self::firstSignedPeriod($relation, $until),
            healthCoverageHistory: array_map(
                static fn (array $run): PayrollTakeoverEvidencePeriod => new PayrollTakeoverEvidencePeriod($run['code'], $run['from'], $run['to'], $run['reference'],
                    self::NOTE . 'zdravotní pojišťovna ' . $run['code'] . ' podle oznámení pojišťovně.'),
                (array) ($relation['insurer_history'] ?? []),
            ),
        );
        $jmhz = $relation['registry']['jmhz'] ?? null;
        $identifiers = self::identifiers($relation);
        $employment = new PayrollTakeoverEmployment(
            personalNumber: (string) $relation['personal_number'],
            relationKey: (string) $relation['key'],
            start: $start,
            end: is_string($relation['end']) ? $relation['end'] : null,
            workplace: is_array($jmhz) && is_string($jmhz['municipality_code']) && is_string($jmhz['municipality']) && is_string($jmhz['country'])
                ? ['work_place' => mb_substr($jmhz['municipality'], 0, 255), 'municipality_code' => $jmhz['municipality_code'],
                    'country_code' => $jmhz['country'], 'regular_workplace' => null]
                : null,
            czIsco: is_string($relation['registry']['cz_isco'] ?? null) ? $relation['registry']['cz_isco'] : null,
            oic: $identifiers['confirmed'] ? $identifiers['oic'] : null,
            idPpv: $identifiers['confirmed'] ? $identifiers['id_ppv'] : null,
            checklistNotes: self::checklistNotes($relation, $until),
            averages: self::averages($relation, $until, $moduleStart),
            absences: self::absences($relation, $until, $moduleStart)['absences'],
            leave: self::leave($relation, $until, $moduleStart),
            transferStart: self::transferStart($relation, $until),
            recurringComponents: self::recurringComponents($relation, $until, $moduleStart),
        );
        return new PayrollTakeoverRecord($person, $employment);
    }

    /**
     * Trvalé příjmy z karty vztahu (`MZ_SRAZ`, {@see PremierPayrollDeductions::recurringIncomes()}),
     * které trvají na konci posledního převáděného měsíce: z nich vznikne opakovaná složka pro
     * měsíce počítané MyÚčtem. Skončená karta se nepřebírá.
     *
     * @param array<string,mixed> $relation
     * @return list<array{code:string,name:string,kind:string,amount:float,from:string,to:?string,allocation:string,
     *     calculation:string,definition:array<string,string>,note:?string}>
     */
    public static function recurringComponents(array $relation, string $until, ?string $moduleStart = null): array
    {
        $last = self::lastPeriod($until, $moduleStart);
        // Vztah skončený před posledním převzatým měsícem už nic nedostane, i když karta
        // příjmu konec nemá (stejně jako u srážek).
        if (is_string($relation['end'] ?? null) && substr($relation['end'], 0, 7) < $last) {
            return [];
        }
        $out = [];
        foreach ((array) ($relation['recurring_incomes'] ?? []) as $card) {
            if (($card['from'] !== null && $card['from'] > $last) || ($card['to'] !== null && $card['to'] < $last) || $card['amount'] <= 0) {
                continue;
            }
            $out[] = [
                'code' => $card['code'],
                'name' => $card['name'],
                'kind' => $card['kind'],
                'amount' => (float) $card['amount'],
                'from' => ($card['from'] ?? substr((string) $relation['start'], 0, 7)) . '-01',
                'to' => $card['to'] === null ? null : (new \DateTimeImmutable($card['to'] . '-01'))->format('Y-m-t'),
                'allocation' => (string) ($card['allocation'] ?? 'calendar_days'),
                'calculation' => (string) ($card['calculation'] ?? 'fixed_amount'),
                'definition' => (array) ($card['definition'] ?? []),
                'note' => is_string($card['note'] ?? null) ? $card['note'] : null,
            ];
        }

        return $out;
    }

    /**
     * Poslední měsíc (`YYYY-MM`), za který převod přebírá časové evidence roku: konec
     * převáděného období, ale nejpozději měsíc před začátkem vedení mezd v MyÚčtu
     * (od něj eviduje nepřítomnosti a dovolenou MyÚčto samo).
     */
    private static function lastPeriod(string $until, ?string $moduleStart): string
    {
        $last = substr($until, 0, 7);
        if (is_string($moduleStart) && preg_match('/^\d{4}-\d{2}/', $moduleStart) === 1) {
            $before = (new \DateTimeImmutable(substr($moduleStart, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
            $last = min($last, $before);
        }
        return $last;
    }

    /**
     * Nepřítomnosti převáděného roku (`$until`) s daty z `DNY`. Pracovní neschopnost, která
     * trvá přes poslední převáděný měsíc (`MZ_HDPN` s pozdějším koncem), se prodlouží až do
     * konce případu, aby MyÚčto navázalo tentýž případ a nepočítalo období náhrady mzdy
     * znovu. Případ bez známého konce se jen spočítá (`open_sickness`).
     *
     * @param array<string,mixed> $relation
     * @return array{absences:list<array{type:string,from:string,to:string,childbirth:?string}>,open_sickness:int}
     */
    public static function absences(array $relation, string $until, ?string $moduleStart = null): array
    {
        $year = substr($until, 0, 4);
        $last = self::lastPeriod($until, $moduleStart);
        $out = [];
        $lastSick = null;
        foreach ((array) ($relation['absences'] ?? []) as $absence) {
            if (substr($absence['period'], 0, 4) !== $year || $absence['period'] > $last) {
                continue;
            }
            $out[] = ['type' => $absence['type'], 'from' => $absence['from'], 'to' => $absence['to'], 'childbirth' => $absence['childbirth']];
            if ($absence['type'] === 'dpn' && ($lastSick === null || $absence['to'] > $lastSick)) {
                $lastSick = $absence['to'];
            }
        }
        $open = 0;
        $monthEnd = (new \DateTimeImmutable($last . '-01'))->format('Y-m-t');
        foreach ((array) ($relation['sickness'] ?? []) as $case) {
            if ($case['kind'] !== 'DPN' || $lastSick === null || $case['from'] > $lastSick || $lastSick < $monthEnd) {
                continue;
            }
            if ($case['to'] === null) {
                $open++;
                continue;
            }
            if ($case['to'] > $lastSick) {
                $out[] = ['type' => 'dpn', 'from' => (new \DateTimeImmutable($lastSick))->modify('+1 day')->format('Y-m-d'), 'to' => $case['to'], 'childbirth' => null];
            }
        }
        return ['absences' => $out, 'open_sickness' => $open];
    }

    /**
     * Zůstatek dovolené roku ke konci posledního převáděného měsíce (`DOV_DNY`, hodiny;
     * viz {@see PremierPayrollTime}). Dny se dopočtou denním úvazkem vztahu.
     *
     * @param array<string,mixed> $relation
     * @return array{year:int,balance_hours:float,balance_days:?float,taken_hours:float,daily_hours:?float,from_days:bool}|null
     */
    private static function leave(array $relation, string $until, ?string $moduleStart): ?array
    {
        $year = substr($until, 0, 4);
        $last = self::lastPeriod($until, $moduleStart);
        $found = null;
        foreach ((array) ($relation['leave_months'] ?? []) as $period => $state) {
            if (substr((string) $period, 0, 4) === $year && (string) $period <= $last && ($found === null || (string) $period > $found)) {
                $found = (string) $period;
            }
        }
        if ($found === null) {
            return null;
        }
        $state = $relation['leave_months'][$found];
        $daily = null;
        foreach ((array) ($relation['working_time'] ?? []) as $from => $time) {
            if ($from <= $found . '-31') {
                $daily = $time['daily'] > 0 ? (float) $time['daily'] : ($time['weekly'] > 0 ? round($time['weekly'] / 5, 4) : null);
            }
        }
        return [
            'year' => (int) $year,
            'balance_hours' => (float) $state['balance'],
            'balance_days' => $daily === null ? null : round($state['balance'] / $daily, 2),
            'taken_hours' => (float) $state['taken'],
            'daily_hours' => $daily,
            'from_days' => false,
        ];
    }

    /**
     * Průměrné výdělky čtvrtletí převáděného roku (`PER_PRU`): hodnota z prvního měsíce
     * čtvrtletí, pro které PREMIER průměr vede, s rozhodným obdobím a výdělkem v něm.
     * Odpracované dny rozhodného období se sečtou ze zpracovaných mezd vztahu.
     *
     * @param array<string,mixed> $relation
     * @return list<array{year:int,quarter:int,hourly:float,from:string,to:string,gross:float,worked:float,days:float}>
     */
    private static function averages(array $relation, string $until, ?string $moduleStart): array
    {
        $year = (int) substr($until, 0, 4);
        $last = self::lastPeriod($until, $moduleStart);
        $byQuarter = [];
        foreach ((array) ($relation['average_months'] ?? []) as $period => $average) {
            $period = (string) $period;
            if ((int) substr($period, 0, 4) !== $year || !is_string($average['from']) || !is_string($average['to'])) {
                continue;
            }
            $quarter = (int) ceil(((int) substr($period, 5, 2)) / 3);
            $quarterStart = sprintf('%04d-%02d', $year, ($quarter - 1) * 3 + 1);
            if ($quarterStart > $last || (isset($byQuarter[$quarter]) && $byQuarter[$quarter]['period'] < $period)) {
                continue;
            }
            $byQuarter[$quarter] = ['period' => $period] + $average;
        }
        ksort($byQuarter);
        $out = [];
        foreach ($byQuarter as $quarter => $average) {
            $days = 0.0;
            foreach ($relation['months'] as $period => $m) {
                if ($period . '-01' >= substr($average['from'], 0, 7) . '-01' && $period . '-01' <= $average['to']) {
                    $days += (float) $m['worked_days'];
                }
            }
            $out[] = [
                'year' => $year,
                'quarter' => $quarter,
                'hourly' => (float) $average['hourly'],
                'from' => $average['from'],
                'to' => $average['to'],
                'gross' => (float) $average['gross'],
                'worked' => (float) $average['worked'],
                'days' => $days,
            ];
        }
        return $out;
    }

    /**
     * První měsíc (`YYYY-MM`) převáděného roku se zpracovanou mzdou vztahu.
     *
     * @param array<string,mixed> $relation
     */
    private static function transferStart(array $relation, string $until): string
    {
        $year = substr($until, 0, 4);
        foreach (array_keys($relation['months']) as $period) {
            if (substr((string) $period, 0, 4) === $year) {
                return (string) $period;
            }
        }
        return $year . '-01';
    }

    /**
     * OIČ a ID pracovněprávního vztahu. PREMIER je nese ve formuláři JMHZ za vztah
     * (`X10051`, `X10228`) a OIČ i na kartě osoby (`PER_MAIN.IK_MPSV`; na reálné záloze
     * se obě hodnoty shodují ve všech formulářích).
     *
     * Převzít je smí převod jen doložené: převod z PAMICA na to má potvrzení uživatele
     * v průvodci, převod z PREMIER takové potvrzení nemá. Doklad je tu přijetí formuláře
     * ČSSZ ({@see PremierPayrollRegistry}): ČSSZ ho s těmi čísly zpracovala. Čísla bez
     * přijatého formuláře (jen z karty osoby nebo z neodeslaného hlášení) zůstávají
     * k ověření a protokol je spočítá.
     *
     * @param array<string,mixed> $relation
     * @return array{oic:?string,id_ppv:?string,confirmed:bool}
     */
    public static function identifiers(array $relation): array
    {
        $jmhz = $relation['registry']['jmhz'] ?? null;
        $accepted = is_array($jmhz) && $jmhz['accepted'] === true;
        return [
            'oic' => (is_array($jmhz) ? $jmhz['oic'] : null) ?? (is_string($relation['oic'] ?? null) ? $relation['oic'] : null),
            'id_ppv' => is_array($jmhz) ? $jmhz['id_ppv'] : null,
            'confirmed' => $accepted,
        ];
    }

    /**
     * Doklady k položkám Zákonných termínů, které proběhly v PREMIER. Doklad o skončení
     * přidává orchestrátor až podle stavu vztahu v MyÚčtu.
     *
     * @param array<string,mixed> $relation
     * @return array<string,string>
     */
    private static function checklistNotes(array $relation, string $until): array
    {
        $notes = ['employment_contract' => self::NOTE . 'vztah vedený v předchozím mzdovém systému, nástup ' . self::czechDate((string) $relation['start']) . '.'];
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            if ($m['signed'] === true) {
                $notes['tax_declaration'] = self::NOTE . 'podepsané prohlášení poplatníka, mzda za ' . $period . '.';
                break;
            }
        }
        $registry = (array) ($relation['registry'] ?? []);
        $health = (array) ($registry['health_notices'] ?? []);
        if ($relation['insurer_registered'] === true || self::accepted($health, 'P') !== null) {
            $notes['health_insurance_registration'] = self::NOTE . 'přihláška zdravotní pojišťovně přijatá v PREMIER.';
        }
        $deregistration = self::accepted($health, 'O');
        if ($deregistration !== null) {
            $notes['health_insurance_deregistration'] = self::NOTE . 'odhláška zdravotní pojišťovně'
                . (is_string($deregistration['date']) ? ' k ' . self::czechDate($deregistration['date']) : '') . ' přijatá v PREMIER.';
        }
        $social = (array) ($registry['social_notices'] ?? []);
        $start = self::accepted($social, '1');
        $jmhz = $registry['jmhz'] ?? null;
        if ($start !== null) {
            $notes['social_jmhz_registration'] = self::NOTE . 'oznámení o nástupu ČSSZ přijaté'
                . (is_string($start['accepted_on']) ? ' ' . self::czechDate($start['accepted_on']) : '') . '.';
        } elseif (is_array($jmhz) && $jmhz['accepted'] === true) {
            $notes['social_jmhz_registration'] = self::NOTE . 'měsíční hlášení JMHZ za vztah za ' . $jmhz['period'] . ' přijaté ČSSZ.';
        }
        $end = self::accepted($social, '2');
        if ($end !== null) {
            $notes['social_jmhz_deregistration'] = self::NOTE . 'oznámení o skončení ČSSZ přijaté'
                . (is_string($end['accepted_on']) ? ' ' . self::czechDate($end['accepted_on']) : '') . '.';
        }
        $eldp = $registry['eldp'] ?? null;
        if (is_array($eldp)) {
            $notes['eldp_submission'] = self::NOTE . 'evidenční list důchodového pojištění za rok ' . $eldp['year'] . ' přijatý ČSSZ.';
        }
        return $notes;
    }

    /**
     * Poslední přijaté oznámení daného druhu.
     *
     * @param list<array<string,mixed>> $notices
     * @return array<string,mixed>|null
     */
    private static function accepted(array $notices, string $kind): ?array
    {
        $found = null;
        foreach ($notices as $notice) {
            if ($notice['kind'] === $kind && $notice['accepted'] === true
                && ($found === null || (string) ($notice['date'] ?? $notice['accepted_on'] ?? '') >= (string) ($found['date'] ?? $found['accepted_on'] ?? ''))) {
                $found = $notice;
            }
        }
        return $found;
    }

    private static function czechDate(string $iso): string
    {
        return (new \DateTimeImmutable($iso))->format('j. n. Y');
    }

    /**
     * Adresa v podobě karty osoby. Stát zapsaný v PREMIER volným textem („Slovenská
     * republika", „Německo") se převede na kód číselníkem zemí ({@see CountryNameMatcher});
     * adresa, jejíž stát nejde určit, se nezapíše (špatně přiřazená země je horší než
     * chybějící adresa).
     *
     * @param array<string,mixed>|null $address {@see PremierPayroll} (`country_code`, `country_text`)
     * @return array{street_line:string,city:string,postal_code:string,country_code:string}|null
     */
    public static function address(?array $address, ?CountryNameMatcher $countries): ?array
    {
        if ($address === null) {
            return null;
        }
        $code = $address['country_code'] ?? null;
        if (!is_string($code) && $countries !== null) {
            $code = $countries->match((string) ($address['country_text'] ?? ''));
        }
        if (!is_string($code)) {
            return null;
        }
        return [
            'street_line' => (string) $address['street_line'],
            'city' => (string) $address['city'],
            'postal_code' => (string) $address['postal_code'],
            'country_code' => $code,
        ];
    }

    /**
     * Děti s uplatněným daňovým zvýhodněním ({@see PremierPayrollPersonCard}): pořadí
     * z posledního uplatněného měsíce, nárok od prvního do posledního uplatněného měsíce;
     * uplatňuje-li se dosud (poslední měsíc mezd osoby), je nárok otevřený. Dítě bez
     * uplatnění do `$until` je jen v počtu dětí bez zvýhodnění. Dítě bez rodného čísla
     * se nezapisuje (zápis ho bez něj založit nesmí), vrací se zvlášť.
     *
     * @param array<string,mixed> $relation
     * @return array{children:list<array<string,mixed>>,without_credit:int,without_birth_number:int,other_caregiver:int}
     */
    public static function children(array $relation, string $until): array
    {
        $untilPeriod = substr($until, 0, 7);
        $lastPayroll = is_string($relation['person_last_period'] ?? null) ? min($relation['person_last_period'], $untilPeriod) : $untilPeriod;
        $out = ['children' => [], 'without_credit' => 0, 'without_birth_number' => 0, 'other_caregiver' => 0];
        foreach ((array) ($relation['children'] ?? []) as $child) {
            $periods = array_filter((array) $child['periods'], static fn (string $p): bool => $p <= $untilPeriod, ARRAY_FILTER_USE_KEY);
            if ($periods === []) {
                $out['without_credit']++;
                continue;
            }
            if (!is_string($child['birth_number'])) {
                $out['without_birth_number']++;
                continue;
            }
            if ($child['other_caregiver'] === true) {
                $out['other_caregiver']++;
            }
            $first = (string) array_key_first($periods);
            $last = (string) array_key_last($periods);
            $out['children'][] = [
                'order' => (int) $periods[$last],
                'code' => (string) $child['id'],
                'reference' => 'premier:mz_deti:' . $child['id'],
                'given_name' => $child['given_name'],
                'family_name' => $child['family_name'],
                'birth_number' => $child['birth_number'],
                'from' => $first . '-01',
                'to' => $last < $lastPayroll ? (new \DateTimeImmutable($last . '-01'))->format('Y-m-t') : null,
            ];
        }
        return $out;
    }

    /**
     * Sleva na pojistném pracujícího důchodce po úsecích měsíců mezd (`MZDY.SLEVA_SOC`).
     * U osoby, která podle PREMIER pobírá důchod (`MZ_DUCHOD`), poznámka řekne i to.
     *
     * @param array<string,mixed> $relation
     * @return list<PayrollTakeoverEvidencePeriod>
     */
    private static function socialDiscounts(array $relation, string $until): array
    {
        $pension = is_array($relation['pension'] ?? null) ? $relation['pension'] : null;
        $out = [];
        foreach (self::runs($relation, $until, static fn (array $m): string => ($m['pensioner_discount'] ?? false) === true ? 'verified' : 'not_claimed') as $run) {
            $claimed = $run['status'] === 'verified';
            $out[] = new PayrollTakeoverEvidencePeriod(
                $run['status'],
                $run['from'],
                $run['to'],
                $claimed ? 'premier:mzdy:sleva_soc:' . $run['period'] : null,
                self::NOTE . ($claimed ? 'sleva pracujícího důchodce uplatněná' : 'sleva pracujícího důchodce se neuplatňuje')
                    . ' od mzdy za ' . $run['period']
                    . ($pension !== null ? '; PREMIER vede pobírání důchodu' . (is_string($pension['from']) ? ' od ' . self::czechDate($pension['from']) : '') : '') . '.',
            );
        }
        return $out;
    }

    /**
     * První měsíc (`YYYY-MM`) s podepsaným prohlášením poplatníka do `$until`. Prohlášení
     * i nárok na dítě jsou údaje osoby: rozhodují všechny její vztahy, ne jen ten, přes
     * který se osoba zapisuje (souběžná dohoda prohlášení podepsané nemívá).
     *
     * @param array<string,mixed> $relation
     */
    private static function firstSignedPeriod(array $relation, string $until): ?string
    {
        if (array_key_exists('person_signed_periods', $relation)) {
            foreach ((array) $relation['person_signed_periods'] as $period) {
                return $period . '-01' <= $until ? (string) $period : null;
            }
            return null;
        }
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            if ($m['signed'] === true) {
                return (string) $period;
            }
        }
        return null;
    }

    /**
     * Prohlášení poplatníka jako souvislé úseky stejného stavu po měsících mezd.
     *
     * @param array<string,mixed> $relation
     * @return list<array{from:string,to:?string,status:string,period:string}>
     */
    private static function declarations(array $relation, string $until): array
    {
        return self::runs($relation, $until, static fn (array $m): string => $m['signed'] === true ? 'signed' : 'not-signed');
    }

    /**
     * Souvislé úseky stejného stavu po měsících mezd (do `$until`), po celých měsících.
     *
     * @param array<string,mixed> $relation
     * @param callable(array<string,mixed>):string $statusOf
     * @return list<array{from:string,to:?string,status:string,period:string}>
     */
    private static function runs(array $relation, string $until, callable $statusOf): array
    {
        $runs = [];
        foreach ($relation['months'] as $period => $m) {
            if ($period . '-01' > $until) {
                break;
            }
            $status = $statusOf($m);
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['status'] === $status) {
                continue;
            }
            $from = max($period . '-01', substr((string) $relation['start'], 0, 7) . '-01');
            if ($last !== null) {
                $runs[$last]['to'] = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            }
            $runs[] = ['from' => $from, 'to' => null, 'status' => $status, 'period' => (string) $period];
        }
        return $runs;
    }
}
