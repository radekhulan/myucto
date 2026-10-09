<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInstitutionWriter;

/**
 * Údaje osob a pracovních vztahů z `91_mzdy.xml` (POHODA Mzdy / PAMICA), které
 * měsíční sešity převodu nenesou: adresa, kontakt, občanství, daňová rezidence,
 * prohlášení poplatníka, pracoviště a CZ-ISCO, OIČ a ID PPV, skončení vztahu,
 * evidence už podaných hlášení a úhrny mezd pro počáteční stavy kumulací.
 *
 * Třída jen čte a normalizuje, nic nezapisuje ({@see PohodaPayrollPeopleWriter}).
 *
 * Kódy PAMICA, na které převod spoléhá, jsou ověřené na datech, ne převzaté z
 * dokumentace (ta k nim není):
 *
 * - `ZAMzp.RelKod` 1 = oznámení pojišťovně o nástupu (datum sedí na nástup),
 *   2 = o skončení (datum sedí na skončení vztahu),
 * - `RegZAMitems.RelTyp` 1 = přihláška při nástupu, 3 = registrace trvajícího
 *   vztahu, 2 = odhláška; `ONZpol.RelTyp` 1 = přihláška, 2 = odhláška,
 *   5 = přihláška i odhláška; odeslání nese `ElOdeslano` hlavičky podání,
 * - daňové úhrny `MZ`: `KcZdaM` základ zálohy, `KcDanPrS` daň před slevami,
 *   `KcDanZal` po slevách na poplatníka, `KcZalDan` záloha po slevě na dítě,
 *   `KcDanBon` bonus (vztahy mezi nimi platí na všech zpracovaných mzdách).
 *
 * @phpstan-type Evidence array{date:?string,submitted:?string,accepted:?string,state:?string,source:string}
 */
final class PohodaPayrollPeople
{
    /** Tabulky, které čtení potřebuje (kromě mezd `MZ`). */
    /** Podání s pracovištěm OSSZ v pořadí, ve kterém se kód a název hledají. */
    private const OSSZ_COLUMNS = ['ONZpol' => 'OSSZ', 'NEMPRIpol' => 'KodOSSZ', 'HZUPNpol' => 'KodOSSZ'];

    private const TABLES = ['ZAM', 'ZAMpomer', 'sMzMist', 'sMzPoj', 'ZAMzp', 'RegZAM', 'RegZAMitems', 'ONZ', 'ONZpol', 'ELDP', 'ELDPpol', 'ZAMpDet',
        'ZAMucet', 'sMZneprit', 'sMZslozky', 'SocPojSleva'];

    /**
     * Důvod slevy zaměstnavatele na pojistném (`SocPojSleva.RelDuvod`) => důvod podle § 7a odst. 1
     * zák. č. 589/1992 Sb. PAMICA čísluje písmena a) až g) od 1; ověřeno na přijatém hlášení
     * (`RelDuvod` 2 = `duvodUplatneni` B, péče o dítě do 10 let).
     */
    private const PART_TIME_DISCOUNT_REASONS = [
        '1' => 'age_55_plus', '2' => 'child_care_under_10', '3' => 'dependent_close_person_care', '4' => 'study_under_26',
        '5' => 'retraining_jobseeker', '6' => 'disabled_person', '7' => 'under_21',
    ];

    /**
     * Druh nepřítomnosti MyÚčta podle ČÍSLA složky nepřítomnosti v PAMICA. Číslo je
     * spolehlivější než význam hodinového sloupce: číselník rozlišuje i druhy, které se
     * do hodin sešitu nepromítají (rodičovská dovolená, peněžitá pomoc v mateřství,
     * dlouhodobé ošetřovné). Co druh evidence nezná (svátek, pracovní cesta, vazba),
     * se jako nepřítomnost nepřevádí.
     */
    private const ABSENCE_CODES = [
        'V01' => 'vacation', 'V01D' => 'vacation',
        'N01' => 'dpn', 'H01' => 'dpn', 'N03' => 'dpn', 'H03' => 'dpn', 'N04' => 'dpn', 'H04' => 'dpn',
        'H11' => 'dpn', 'H12' => 'dpn', 'H13' => 'dpn', 'H14' => 'dpn',
        'N02' => 'quarantine', 'H02' => 'quarantine',
        'N05' => 'ocr', 'N06' => 'ocr', 'H05' => 'ocr', 'H06' => 'ocr', 'H17' => 'ocr',
        'H16' => 'long_term_care',
        'N07' => 'ppm', 'H07' => 'ppm',
        'N08' => 'parental', 'H08' => 'parental', 'H08D' => 'parental',
        'H15' => 'paternity',
        'V04' => 'unpaid_leave', 'V14' => 'unpaid_leave', 'V15' => 'unpaid_leave',
        'V16' => 'unpaid_leave', 'V17' => 'unpaid_leave', 'V10' => 'unpaid_leave',
        'V05' => 'unexcused', 'V11' => 'unexcused',
        'V03' => 'employee_obstacle', 'V18' => 'employee_obstacle', 'V18A' => 'employee_obstacle', 'V18B' => 'employee_obstacle',
        'V06' => 'employer_obstacle', 'V06A' => 'employer_obstacle', 'V06B' => 'employer_obstacle', 'V06C' => 'employer_obstacle',
        'V19' => 'compensatory_time_off', 'V20' => 'compensatory_time_off',
    ];

    /** Čísla složek hodinové a úkolové mzdy: takový vztah měsíční mzdu nepobírá. */
    private const HOURLY_WAGE_CODES = ['C01', 'C02', 'C08', 'U01', 'U02', 'U03', 'U04', 'U05'];

    /**
     * Základní měsíční mzda. `M09` je táž mzda při zkráceném úvazku, ne plnění navíc:
     * ve všech mzdách exportu se jí rovná `MZ.KcZaklM` a s `M01` se nikdy nepotkává,
     * takže pravidlo pro sjednanou měsíční mzdu pokrývá obě.
     */
    private const WAGE_CODES = ['M01', 'M09'];

    /** Od kolika měsíců z odpracovaných se plnění považuje za pravidelné. */
    private const REGULAR_SHARE = 0.8;

    /** Kód banky ČNB; odvody státu chodí jen na její účty. */
    private const CNB_BANK_CODE = PayrollTakeoverInstitutionWriter::CNB_BANK_CODE;

    /**
     * Předčíslí účtu u ČNB => instituce MyÚčta ({@see PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS}).
     * Registr institucí PAMICA nese jen zdravotní pojišťovny; účet ČSSZ a finančního
     * úřadu je pouze na vystavených závazcích a předčíslí je tam jediné, co příjemce
     * spolehlivě rozliší (`Doklady.Firma` je volný text účetní, číselník úřadů v exportu není).
     */
    private const LEVY_ACCOUNTS = PayrollTakeoverInstitutionWriter::LEVY_ACCOUNTS;

    /**
     * Daňové zvýhodnění na dítě v `ZAMpDet.RelOdpoc` => pořadí dítěte. Ověřené na mzdách:
     * roční částka `KcOdec` = 12 × měsíční zvýhodnění daného pořadí a součet aktivních
     * dětí sedí na měsíční nárok `MZ.KcNzdDet`. Jiné kódy (36 = sleva na poplatníka,
     * 169 = dítě bez nároku u zaměstnance) se jako dítě nepřevádějí.
     */
    private const CHILD_CODES = ['34' => 1, '163' => 2, '165' => 3];

    /**
     * Vztahy, za které jsou v roce zpracované mzdy, v pořadí osobních čísel.
     *
     * @return list<array<string,mixed>>
     */
    public static function read(string $file, int $year): array
    {
        $byId = [];
        /** @var array<string,array<string,list<array<string,mixed>>>> $payslips vztah => období => mzdy */
        $payslips = [];
        /** @var array<string,array<int,array<string,int|bool>>> $personMonths osoba => měsíc => úhrny */
        $personMonths = [];
        /** @var array<string,bool> $socialParticipation vztah => účast na nemocenském pojištění v některé mzdě roku */
        $socialParticipation = [];
        /** @var array<string,array<int,array<string,float>>> $relationMonths vztah => měsíc => mzda, fond a průměr */
        $relationMonths = [];
        /** @var array<string,array{relation:string,month:int}> $payslipOf mzda => vztah a měsíc (pro nepřítomnosti) */
        $payslipOf = [];
        $transferStart = null;
        /** @var array<string,string> $lastPaid osoba => den poslední výplaty (`MZ.Datum` mzdy s výplatou) */
        $lastPaid = [];
        // Číselníky, karty a mzdy jedním průchodem souborem (desítky MB), nepřítomnosti
        // a složky mzdy druhým - potřebují už znát mzdy roku.
        $leaveCards = [];
        /** @var array<string,array{requested?:bool,granted?:bool}> $partTime vztah => žádost a nárok na slevu zaměstnavatele */
        $partTime = [];
        foreach (PohodaXml::scan($file, [...self::TABLES, 'MZ', 'Dovolena']) as $table => $mz) {
            if ($table === 'Dovolena') {
                self::leaveCard($leaveCards, $mz, $year);
                continue;
            }
            if ($table !== 'MZ') {
                $byId[$table][PohodaXml::text($mz, 'ID')] = $mz;
                continue;
            }
            if ((int) PohodaXml::text($mz, 'Rok') !== $year) {
                continue;
            }
            $month = (int) PohodaXml::text($mz, 'RelMes');
            if ($month < 1 || $month > 12) {
                continue;
            }
            $period = sprintf('%04d-%02d', $year, $month);
            $transferStart = $transferStart === null || $period < $transferStart ? $period : $transferStart;
            $relationKey = PohodaXml::text($mz, 'RefPomer');
            // Ze mzdy se dál čte jen příznak prohlášení (`first_signed`); celý řádek má stovky sloupců.
            $payslips[$relationKey][$period][] = array_intersect_key($mz, ['Prohlas' => true]);
            // Účast na nemocenském pojištění: příznak mzdy, případně sražené pojistné zaměstnance.
            $socialParticipation[$relationKey] = ($socialParticipation[$relationKey] ?? false)
                || self::bool(PohodaXml::text($mz, 'JeSocPP')) || PohodaXml::num($mz, 'KcSoc') > 0;
            $payslipOf[PohodaXml::text($mz, 'ID')] = ['relation' => $relationKey, 'month' => $month];
            $relationMonths[$relationKey][$month] = [
                // Sjednaná měsíční mzda: `KcZaklM` je za odpracovanou část měsíce, proto se
                // bere jen z měsíce odpracovaného celý (jinak by se přenesla krácená mzda).
                'wage' => PohodaXml::num($mz, 'KcZaklM'),
                'full_month' => PohodaXml::num($mz, 'DnyOdpra') + 0.01 >= PohodaXml::num($mz, 'DnyPrac'),
                // Čtvrtletní průměrný výdělek pro náhrady; `KcPrumU` je záložní pole.
                'average' => PohodaXml::num($mz, 'KcPrum') > 0 ? PohodaXml::num($mz, 'KcPrum') : PohodaXml::num($mz, 'KcPrumU'),
                'gross' => PohodaXml::num($mz, 'KcHrubaM'),
                'worked' => PohodaXml::num($mz, 'HodOdpra'),
                'worked_days' => PohodaXml::num($mz, 'DnyOdpra'),
            ];
            $person = PohodaXml::text($mz, 'RefZAM');
            // Den, kdy PAMICA mzdu opravdu vyplatila. `Datum` je den výplaty (v exportu vždy
            // 10. následujícího měsíce), `KcVyplat` odděluje mzdy, ze kterých se platilo.
            $paidOn = self::realDate(PohodaXml::date($mz, 'Datum'));
            // Jen den, který už nastal: mzda posledního zpracovaného měsíce má den výplaty
            // v budoucnu a doklad o výplatě z ní ještě není.
            if ($paidOn !== null && $paidOn <= date('Y-m-d') && PohodaXml::num($mz, 'KcVyplat') > 0
                && ($lastPaid[$person] ?? '') < $paidOn
            ) {
                $lastPaid[$person] = $paidOn;
            }
            $sums = $personMonths[$person][$month] ?? [
                'social' => 0, 'advance_base' => 0, 'advance_tax' => 0, 'withholding_base' => 0,
                'withholding_tax' => 0, 'non_refundable' => 0, 'child' => 0, 'bonus' => 0, 'signed' => false,
                'pensioner_discount' => false,
            ];
            // `SocPojSlevaZadost` a `SocPojSlevaNarok` jsou v PAMICA sleva ZAMĚSTNAVATELE na kratší
            // úvazek (§ 7a zák. č. 589/1992 Sb.: žádost a přiznaný nárok), ne sleva pracujícího
            // důchodce (§ 7d). Ověřeno na přijatých hlášeních: měsíc s nárokem má 10372 = ano,
            // 10490 = ne. Slevu důchodce převod bere z podaného hlášení ({@see self::withSubmittedDiscounts()}).
            $partTime[$relationKey]['requested'] = ($partTime[$relationKey]['requested'] ?? false) || self::bool(PohodaXml::text($mz, 'SocPojSlevaZadost'));
            $partTime[$relationKey]['granted'] = ($partTime[$relationKey]['granted'] ?? false) || self::bool(PohodaXml::text($mz, 'SocPojSlevaNarok'));
            $sums['social'] += self::minor(PohodaXml::num($mz, 'KcSocZak'));
            $sums['advance_base'] += self::minor(PohodaXml::num($mz, 'KcZdaM'));
            $sums['advance_tax'] += self::minor(PohodaXml::num($mz, 'KcZalDan'));
            $sums['withholding_base'] += self::minor(PohodaXml::num($mz, 'KcSraDanZak'));
            $sums['withholding_tax'] += self::minor(PohodaXml::num($mz, 'KcSraDan'));
            $sums['non_refundable'] += max(0, self::minor(PohodaXml::num($mz, 'KcDanPrS') - PohodaXml::num($mz, 'KcDanZal')));
            $sums['child'] += max(0, self::minor(PohodaXml::num($mz, 'KcDanZal') - PohodaXml::num($mz, 'KcZalDan')));
            $sums['bonus'] += self::minor(PohodaXml::num($mz, 'KcDanBon'));
            $sums['signed'] = $sums['signed'] || self::bool(PohodaXml::text($mz, 'Prohlas'));
            $personMonths[$person][$month] = $sums;
        }
        $relationCount = [];
        foreach ($byId['ZAMpomer'] ?? [] as $relation) {
            $person = PohodaXml::text($relation, 'RefZAM');
            $relationCount[$person] = ($relationCount[$person] ?? 0) + 1;
        }

        /** @var array<string,list<array<string,mixed>>> $children osoba => děti s nárokem na zvýhodnění */
        $children = [];
        $childrenWithoutCredit = [];
        foreach ($byId['ZAMpDet'] ?? [] as $row) {
            $code = PohodaXml::text($row, 'RelOdpoc');
            $person = PohodaXml::text($row, 'RefAg');
            if ($code === '169') {
                $childrenWithoutCredit[$person] = ($childrenWithoutCredit[$person] ?? 0) + 1;
            }
            if (!isset(self::CHILD_CODES[$code])) {
                continue;
            }
            $children[$person][] = [
                'order' => self::CHILD_CODES[$code],
                'code' => $code,
                'given_name' => self::limited(PohodaXml::text($row, 'Jmeno'), 100),
                'family_name' => self::limited(PohodaXml::text($row, 'Prijmeni'), 100),
                'birth_number' => PohodaXml::text($row, 'RodCisl') !== '' ? PohodaXml::text($row, 'RodCisl') : null,
                'from' => self::realDate(PohodaXml::date($row, 'DatOd')),
                'to' => self::realDate(PohodaXml::date($row, 'DatDo')),
            ];
        }

        /** @var array<string,list<array<string,mixed>>> $absences vztah => nepřítomnosti s daty */
        $absences = [];
        /** @var array<string,int> $undated vztah => nepřítomnosti vyžadující data, které je v exportu nemají */
        $undated = [];
        /** @var array<string,bool> $hourlyWage vztah => mzda za hodiny nebo úkol (měsíční mzdu nepobírá) */
        $hourlyWage = [];
        /** @var array<string,array<string,int>> $benefitMonths vztah => plnění => počet měsíců s částkou */
        $benefitMonths = [];
        foreach (PohodaXml::scan($file, ['MZneprit', 'MZslozky']) as $table => $row) {
            if ($table === 'MZneprit') {
                $payslip = $payslipOf[PohodaXml::text($row, 'RefAg')] ?? null;
                if ($payslip === null) {
                    continue;
                }
                $catalog = $byId['sMZneprit'][PohodaXml::text($row, 'RefSlozka')] ?? [];
                $number = PohodaXml::text($catalog, 'Cislo');
                $code = strtoupper(trim($number));
                $childbirth = self::realDate(PohodaXml::date($row, 'DatPorod'));
                $type = self::ABSENCE_CODES[$code] ?? null;
                if ($type === null && $childbirth !== null) {
                    $type = 'ppm';
                }
                if ($type === null) {
                    continue;
                }
                $dates = self::absenceDates($row, $year);
                if ($dates === null) {
                    // Doba, kterou z hodin dopočítat nejde. U druhu, který evidence vede jedině
                    // s daty, zůstanou hodiny v měsíčním souhrnu (neztratí se) a měsíc si vyžádá
                    // ruční dořešení; protokol ho hlásí s osobním číslem.
                    if (PohodaPayrollCatalog::absenceNeedsDates($number, PohodaXml::text($catalog, 'Nazev'))) {
                        $undated[$payslip['relation']] = ($undated[$payslip['relation']] ?? 0) + 1;
                    }
                    continue;
                }
                $absences[$payslip['relation']][] = [
                    'type' => $type,
                    'from' => $dates['from'],
                    'to' => $dates['to'],
                    'childbirth' => $childbirth,
                    'hours' => PohodaXml::num($row, 'HodPrac'),
                ];
                continue;
            }
            $item = $row;
            $payslip = $payslipOf[PohodaXml::text($item, 'RefAg')] ?? null;
            if ($payslip === null || PohodaXml::num($item, 'KcMzda') <= 0) {
                continue;
            }
            // Sběrný kód (O01) nese víc různých plnění, rozlišuje je až název položky číselníku.
            $catalog = $byId['sMZslozky'][PohodaXml::text($item, 'RefSlozka')] ?? [];
            $code = strtoupper(trim(PohodaXml::text($catalog, 'Cislo')));
            if (in_array($code, self::HOURLY_WAGE_CODES, true)) {
                $hourlyWage[$payslip['relation']] = true;
                continue;
            }
            if (in_array($code, self::WAGE_CODES, true)) {
                // `Hodnota1` je měsíční sazba, ze které PAMICA základní mzdu krátí na
                // odpracovanou část; `KcZaklM` nese až krácenou částku.
                $rate = PohodaXml::num($item, 'Hodnota1');
                if ($rate > 0 && isset($relationMonths[$payslip['relation']][$payslip['month']])) {
                    $current = (float) ($relationMonths[$payslip['relation']][$payslip['month']]['rate'] ?? 0);
                    $relationMonths[$payslip['relation']][$payslip['month']]['rate'] = max($current, $rate);
                }
                continue;
            }
            $name = PohodaXml::text($catalog, 'Nazev');
            // Příplatky a odměny (i ty pod sběrným kódem O01) chodí do běhu z podkladů docházky
            // a v porovnání s PAMICA sedí, takže do seznamu k ručnímu doplnění nepatří. Zůstanou
            // jen ostatní plnění, hlavně zdanitelná část stravování.
            $component = PohodaPayrollCatalog::component($code, $name, true);
            if ($component['meaning'] !== 'component' || in_array($component['kind'], ['premium', 'bonus'], true)) {
                continue;
            }
            $label = trim($code . ' ' . $name);
            $benefitMonths[$payslip['relation']][$label] = ($benefitMonths[$payslip['relation']][$label] ?? 0) + 1;
        }

        /** @var array<string,list<array{account:string,bank_code:string,active:bool}>> $accounts osoba => výplatní účty */
        $accounts = [];
        foreach ($byId['ZAMucet'] ?? [] as $row) {
            $account = PohodaXml::text($row, 'Ucet');
            $bankCode = PohodaXml::text($row, 'KodBanky');
            if ($account === '' || preg_match('/^[0-9]{4}$/D', $bankCode) !== 1) {
                continue;
            }
            // `Active` je jediné, čím PAMICA odliší účet, na který se vyplácí, od účtu jen
            // vedeného v evidenci: vazbu mezi mzdou a účtem export nenese.
            $accounts[PohodaXml::text($row, 'RefAg')][] = [
                'account' => $account,
                'bank_code' => $bankCode,
                'active' => PohodaXml::text($row, 'Active') !== '0',
            ];
        }
        foreach ($accounts as $personKey => $rows) {
            usort($rows, static fn (array $a, array $b): int => ($b['active'] ? 1 : 0) <=> ($a['active'] ? 1 : 0));
            $accounts[$personKey] = $rows;
        }

        $health = self::healthNotices($byId);
        $social = self::socialSubmissions($byId);
        $eldp = self::eldp($byId);

        $records = [];
        foreach ($payslips as $relationId => $periods) {
            $relation = $byId['ZAMpomer'][$relationId] ?? null;
            $personId = $relation === null ? '' : PohodaXml::text($relation, 'RefZAM');
            $person = $byId['ZAM'][$personId] ?? null;
            if ($relation === null || $person === null) {
                continue;
            }
            ksort($periods);
            $months = $personMonths[$personId] ?? [];
            ksort($months);
            $start = PohodaXml::date($relation, 'DatNast') ?? PohodaXml::date($relation, 'DatVstup');
            $start = self::realDate($start);
            $end = self::realDate(PohodaXml::date($relation, 'DatOdch'));
            $place = $byId['sMzMist'][PohodaXml::text($person, 'RefMist')] ?? null;
            $insurer = $byId['sMzPoj'][PohodaXml::text($person, 'RefPoj')] ?? null;
            $insurerCode = $insurer === null ? '' : PohodaXml::text($insurer, 'Kod');
            $firstSigned = null;
            foreach ($months as $month => $sums) {
                if ($sums['signed'] === true && $month >= (int) substr((string) array_key_first($periods), 5, 2)) {
                    $firstSigned = sprintf('%04d-%02d', $year, $month);
                    break;
                }
            }
            // Karta dovolené je v PAMICA na OSOBĚ, ne na vztahu. U souběžných vztahů
            // by nešlo poznat, kterému z nich zůstatek patří, a rozdělit ho napůl by
            // bylo vymýšlení - taková osoba zůstane na účetní.
            $shared = ($relationCount[$personId] ?? 1) > 1 && isset($leaveCards[$personId]);
            $leave = $shared ? null : self::leaveBalance($leaveCards[$personId] ?? null, $year, self::dailyHours($relation));
            $records[] = [
                'personal_number' => self::personalNumber($person, $relation, $relationCount[$personId] ?? 1),
                'person_key' => $personId,
                'relation_key' => (string) $relationId,
                'first_period' => (string) array_key_first($periods),
                // První měsíc, za který převod přebírá mzdy (nejstarší zpracovaná mzda roku).
                'transfer_start' => (string) $transferStart,
                'social_participation' => $socialParticipation[$relationId] ?? false,
                // Pojišťovna osoby; 999 (cizinec bez českého pojištění) a prázdný kód neplatí.
                'insurer_code' => preg_match('/^[0-9]{3}$/D', $insurerCode) === 1 && $insurerCode !== '999' ? $insurerCode : null,
                'foreign_legislation' => self::bool(PohodaXml::text($person, 'PrislCizimPrav')),
                'children' => $children[$personId] ?? [],
                'children_without_credit' => $childrenWithoutCredit[$personId] ?? 0,
                'monthly_wages' => self::monthlyWages($relationMonths[$relationId] ?? [], $year, $start, $end),
                'hourly_wage' => $hourlyWage[$relationId] ?? false,
                // Plnění, která se u vztahu opakují skoro každý měsíc (zdanitelná část
                // stravování a podobně). Převod je nezakládá jako pravidelnou složku, protože
                // částka se měsíc od měsíce mění; protokol je vypíše účetní.
                'regular_benefits' => self::regularBenefits($benefitMonths[$relationId] ?? [], count($periods)),
                'averages' => self::averages($relationMonths[$relationId] ?? [], $year),
                'absences' => $absences[$relationId] ?? [],
                'absences_without_dates' => $undated[$relationId] ?? 0,
                // Zůstatek dovolené z karty PAMICA; čerpání se nepřenáší, kniha dovolené
                // ho ručně zapsat neumí (vzniká jen ze schválené nepřítomnosti).
                'leave' => $leave,
                'leave_shared' => $shared,
                'accounts' => $accounts[$personId] ?? [],
                // Den poslední mzdy vyplacené v PAMICA: doklad, že se na účet opravdu platilo.
                'accounts_paid_on' => $lastPaid[$personId] ?? null,
                'first_signed_period' => $firstSigned,
                'start' => $start,
                'end' => $end,
                'ended_by_code' => PohodaXml::text($relation, 'RelUkonc') !== '',
                'identity' => [
                    'title_prefix' => self::limited(PohodaXml::text($person, 'Titul'), 64),
                    'title_suffix' => self::limited(PohodaXml::text($person, 'TitulZa'), 64),
                    'birth_place' => self::limited(PohodaXml::text($person, 'MistoNar'), 128),
                    'citizenship_country_code' => self::country(PohodaXml::text($person, 'StatPris')),
                ],
                'birth_surname' => self::limited(PohodaXml::text($person, 'Rozena'), 128),
                'residence' => self::address($person, ''),
                'mailing' => self::address($person, 'Kon'),
                'email' => self::email(PohodaXml::text($person, 'Email')),
                'phone' => self::phone(PohodaXml::text($person, 'Tel')),
                'tax_residence' => match (PohodaXml::text($person, 'Nerezident')) {
                    '0', 'false' => 'czech-resident',
                    '1', '-1', 'true' => 'non-resident',
                    default => null,
                },
                'tax_residence_country' => self::country(PohodaXml::text($person, 'ResCisSTOBC')),
                'declarations' => self::declarations($months, $year),
                // Sleva pracujícího důchodce: bez podaného hlášení ji převod zná jen u osoby, která
                // důchod nemá (neuplatňuje se). Důchodci ji doplní hlášení, jinak zůstane k ověření.
                'pensioner' => self::pensioner($person, $relation),
                'pensioner_discounts' => self::pensioner($person, $relation) ? [] : self::declarations($months, $year, 'pensioner_discount', 'verified', 'not_claimed'),
                'birth_date' => self::realDate(PohodaXml::date($person, 'DatNar')),
                'part_time_discount' => self::partTimeDiscount($byId['SocPojSleva'] ?? [], (string) $relationId, $partTime[$relationId] ?? []),
                'first_signed' => self::bool(PohodaXml::text($periods[array_key_first($periods)][0], 'Prohlas'))
                    || (bool) ($months[(int) substr((string) array_key_first($periods), 5, 2)]['signed'] ?? false),
                'months' => $months,
                'workplace' => $place === null ? null : self::workplace($place),
                'cz_isco' => preg_match('/^[0-9]{4,5}$/D', PohodaXml::text($relation, 'ResCisCZISCO')) === 1
                    ? PohodaXml::text($relation, 'ResCisCZISCO')
                    : null,
                'oic' => self::digits(PohodaXml::text($person, 'OIC')) ?? $social['oic'][$relationId] ?? null,
                'id_ppv' => self::digits(PohodaXml::text($relation, 'IDPPV')) ?? $social['id_ppv'][$relationId] ?? null,
                'evidence' => [
                    'health_start' => self::matching($health['start'][$relationId] ?? [], $start),
                    'health_end' => self::matching($health['end'][$relationId] ?? [], $end),
                    'social_start' => $social['start'][$relationId] ?? null,
                    'social_end' => $social['end'][$relationId] ?? null,
                    'eldp' => $eldp[$relationId] ?? null,
                ],
            ];
        }
        usort($records, static fn (array $a, array $b): int => strcmp((string) $a['personal_number'], (string) $b['personal_number']));

        return $records;
    }

    /**
     * Doba nepřítomnosti z řádku `MZneprit` oříznutá na převáděný rok, nebo null, když
     * ji zapsat nejde: nulové datum Accessu, obrácené pořadí, nebo doba mimo převáděný rok.
     *
     * **Jediné místo, které o datovatelnosti rozhoduje.** Podle něj převod nepřítomnost
     * zapisuje s daty ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverAbsenceWriter::absences()}) a měsíční sešit tytéž
     * hodiny vypouští ({@see PohodaPayrollConverter::month()}). Kdyby to každá strana
     * posuzovala po svém, vedla by se doba dvakrát, nebo nikde.
     *
     * @param array<string,mixed> $row řádek `MZneprit`
     * @return array{from:string,to:string}|null
     */
    public static function absenceDates(array $row, int $year): ?array
    {
        $from = self::realDate(PohodaXml::date($row, 'DatZac'));
        $to = self::realDate(PohodaXml::date($row, 'DatKon'));
        if ($from === null || $to === null || $to < $from) {
            return null;
        }
        // Dlouhá nepřítomnost se ořízne na převáděný rok; pokračování patří dalšímu roku.
        $from = max($from, sprintf('%04d-01-01', $year));
        $to = min($to, sprintf('%04d-12-31', $year));

        return $from > $to ? null : ['from' => $from, 'to' => $to];
    }

    /**
     * Plnění, která se u vztahu opakují aspoň v {@see self::REGULAR_SHARE} zpracovaných měsíců.
     *
     * @param array<string,int> $benefits plnění => počet měsíců s částkou
     * @return list<string>
     */
    private static function regularBenefits(array $benefits, int $months): array
    {
        if ($months < 2) {
            return [];
        }
        $out = [];
        foreach ($benefits as $label => $count) {
            if ($count / $months >= self::REGULAR_SHARE) {
                $out[] = $label;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Příjemci odvodů z mezd, které export zná: zdravotní pojišťovny z registru
     * `sMzPoj` (účet, variabilní symbol, datová schránka), ČSSZ a finanční úřad.
     *
     * ČSSZ ani finanční úřad v PAMICA číselník nemají (nastavení úřadů sedí v binárním
     * blobu `sKonfig.Settings`, ten se neexportuje), takže se jejich účet odvozuje
     * z vystavených závazků podle předčíslí ČNB - {@see self::LEVY_ACCOUNTS}. Když
     * závazky v exportu nejsou nebo se pod předčíslím najde víc různých účtů, vrátí se
     * příjemce s `account = null` a důvodem v `issue`; účet se nedomýšlí.
     *
     * @return list<array{type:string,code:?string,name:string,account:?string,bank_code:?string,
     *     variable_symbol:?string,data_box:?string,source:?string,issue:?string,candidates:int}>
     */
    public static function institutions(string $file): array
    {
        $out = [];
        // Pojišťovny, závazky i podání s pracovištěm OSSZ jedním průchodem souborem.
        $documents = [];
        $offices = [];
        foreach (PohodaXml::scan($file, ['sMzPoj', 'Doklady', ...array_keys(self::OSSZ_COLUMNS)]) as $table => $row) {
            if ($table === 'Doklady') {
                $documents[] = $row;
                continue;
            }
            if ($table !== 'sMzPoj') {
                $offices[$table][] = $row;
                continue;
            }
            $code = PohodaXml::text($row, 'Kod');
            $account = PohodaXml::text($row, 'Ucet');
            $bankCode = PohodaXml::text($row, 'KodBanky');
            if (preg_match('/^[0-9]{3}$/D', $code) !== 1 || $code === '999'
                || $account === '' || preg_match('/^[0-9]{4}$/D', $bankCode) !== 1) {
                continue;
            }
            $variable = (string) preg_replace('/\D/', '', PohodaXml::text($row, 'VarSym'));
            $out[] = [
                'type' => 'health_insurer',
                'code' => $code,
                'name' => mb_substr(PohodaXml::text($row, 'IDS'), 0, 190),
                'account' => $account,
                'bank_code' => $bankCode,
                'variable_symbol' => $variable !== '' ? mb_substr($variable, 0, 10) : null,
                'data_box' => self::limited(PohodaXml::text($row, 'DataBox'), 20),
                'source' => 'sMzPoj',
                'issue' => null,
                'candidates' => 1,
            ];
        }
        foreach (self::levyInstitutions($documents, self::socialSecurityOffice($offices)) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /**
     * ČSSZ a finanční úřad z vystavených závazků (`Doklady`). Vrací vždy všechny tři
     * příjemce, které mzdový běh potřebuje, i když se pro ně účet nenašel - protokol
     * pak umí říct, co přesně účetní chybí.
     *
     * @return list<array{type:string,code:?string,name:string,account:?string,bank_code:?string,
     *     variable_symbol:?string,data_box:?string,source:?string,issue:?string,candidates:int}>
     */
    /**
     * @param list<array<string,mixed>> $documents řádky `Doklady`
     * @param array{code:?string,name:?string} $office
     */
    private static function levyInstitutions(array $documents, array $office): array
    {
        /** @var array<string,array{accounts:list<string>,variables:list<string>,document:string,count:int}> $found */
        $found = [];
        foreach ($documents as $document) {
            // Jen vystavený závazek (1); interní doklad (2) cizí účet nenese.
            if (PohodaXml::text($document, 'RelTpDokl') !== '1'
                || PohodaXml::text($document, 'KodBanky') !== self::CNB_BANK_CODE) {
                continue;
            }
            $account = PohodaXml::text($document, 'Ucet');
            if (preg_match('/^([0-9]{1,6})-([0-9]{2,10})$/D', $account, $match) !== 1
                || !isset(self::LEVY_ACCOUNTS[$match[1]])) {
                continue;
            }
            $prefix = $match[1];
            $found[$prefix] ??= ['accounts' => [], 'variables' => [], 'document' => '', 'count' => 0];
            if (!in_array($account, $found[$prefix]['accounts'], true)) {
                $found[$prefix]['accounts'][] = $account;
            }
            $variable = (string) preg_replace('/\D/', '', PohodaXml::text($document, 'VarSym'));
            if ($variable !== '' && strlen($variable) <= 10 && !in_array($variable, $found[$prefix]['variables'], true)) {
                $found[$prefix]['variables'][] = $variable;
            }
            if ($found[$prefix]['document'] === '') {
                $found[$prefix]['document'] = PohodaXml::text($document, 'Cislo');
            }
            $found[$prefix]['count']++;
        }

        $out = [];
        foreach (self::LEVY_ACCOUNTS as $prefix => [$type, $code, $name]) {
            $hit = $found[(string) $prefix] ?? null;
            $accounts = $hit['accounts'] ?? [];
            $variables = $hit['variables'] ?? [];
            $issue = match (true) {
                $accounts === [] => 'missing',
                count($accounts) > 1 => 'ambiguous',
                default => null,
            };
            $out[] = [
                'type' => $type,
                'code' => $code ?? $office['code'],
                'name' => $type === 'social_security' && $office['name'] !== null
                    ? mb_substr($name . ' ' . $office['name'], 0, 190)
                    : $name,
                'account' => $issue === null ? $accounts[0] : null,
                'bank_code' => $issue === null ? self::CNB_BANK_CODE : null,
                // Jediný symbol, na kterém se všechny závazky shodnou. Dva různé symboly
                // znamenají dva různé plátce nebo opravu; hádat mezi nimi nejde.
                'variable_symbol' => $issue === null && count($variables) === 1 ? $variables[0] : null,
                'data_box' => null,
                'source' => $issue === null
                    ? sprintf('PAMICA, tabulka Doklady, závazek %s (%d dokladů s předčíslím %s)', $hit['document'], $hit['count'], $prefix)
                    : null,
                'issue' => $issue,
                'candidates' => count($accounts),
            ];
        }
        return $out;
    }

    /**
     * Pracoviště OSSZ z podání: kód nese `ONZpol.OSSZ`, název `NEMPRIpol`/`HZUPNpol`.
     * Číselník OSSZ v exportu není a účet pracoviště tady nehledej, ten je jen
     * na závazcích.
     *
     * @param array<string,list<array<string,mixed>>> $rows řádky tabulek {@see OSSZ_COLUMNS}
     * @return array{code:?string,name:?string}
     */
    private static function socialSecurityOffice(array $rows): array
    {
        $code = null;
        $name = null;
        foreach (self::OSSZ_COLUMNS as $table => $column) {
            foreach ($rows[$table] ?? [] as $row) {
                $value = strtoupper(trim(PohodaXml::text($row, $column)));
                // Táž podoba kódu, jakou vyžaduje platební cesta u účtu instituce.
                if ($code === null && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/D', $value) === 1) {
                    $code = $value;
                }
                $name ??= self::limited(PohodaXml::text($row, 'NazevOSSZ'), 100);
                if ($code !== null && $name !== null) {
                    return ['code' => $code, 'name' => $name];
                }
            }
        }
        return ['code' => $code, 'name' => $name];
    }

    /**
     * Karty dovolené (`Dovolena`) převáděného roku po osobách. Tabulka je vedená
     * na osobě a roce, ne na pracovním vztahu.
     *
     * @param array<string,array<string,mixed>> $cards MĚNÍ SE: osoba => karta
     */
    private static function leaveCard(array &$cards, array $row, int $year): void
    {
        if ((int) PohodaXml::text($row, 'Rok') === $year) {
            $cards[PohodaXml::text($row, 'RefAg')] = $row;
        }
    }

    /**
     * Zůstatek dovolené: nárok, převod z minulého roku a dodatková dovolená bez krácení
     * a čerpání.
     *
     * Vedoucí jsou HODINOVÉ sloupce. Dovolená se od roku 2021 čerpá v hodinách podle
     * rozvrhu (§ 216 odst. 4 ZP) a kniha dovolené ji v minutách i vede, takže hodiny
     * jsou tatáž veličina a nic se nepřepočítává. Dny se použijí, jen když hodinové
     * sloupce v exportu nejsou; přepočtou se DENNÍM ÚVAZKEM vztahu, protože při
     * zkráceném úvazku je den jinak dlouhý než osm hodin.
     *
     * @param array<string,mixed>|null $row
     * @return array{year:int,balance_hours:float,balance_days:?float,taken_hours:float,daily_hours:?float,from_days:bool}|null
     */
    private static function leaveBalance(?array $row, int $year, ?float $dailyHours): ?array
    {
        if ($row === null) {
            return null;
        }
        $sum = static function (array $columns) use ($row): float {
            $total = 0.0;
            foreach ($columns as $column) {
                $total += PohodaXml::num($row, $column);
            }
            return $total;
        };
        $hours = $sum(['NarokHod', 'StaraHod', 'DodatkovaHod']) - $sum(['KraceniHod', 'RucniKraceniHod', 'CerpanoHod']);
        $fromDays = false;
        if ($sum(['NarokHod', 'StaraHod', 'DodatkovaHod', 'KraceniHod', 'RucniKraceniHod', 'CerpanoHod']) <= 0) {
            $days = $sum(['Narok', 'Stara', 'Dodatkova']) - $sum(['Kraceni', 'RucniKraceni', 'Cerpano']);
            if ($dailyHours === null || $days === 0.0) {
                return null;
            }
            $hours = $days * $dailyHours;
            $fromDays = true;
        }
        return [
            'year' => $year,
            'balance_hours' => round($hours, 4),
            'balance_days' => $dailyHours === null ? null : round($hours / $dailyHours, 2),
            'taken_hours' => round(PohodaXml::num($row, 'CerpanoHod'), 4),
            'daily_hours' => $dailyHours,
            'from_days' => $fromDays,
        ];
    }

    /**
     * Denní úvazek vztahu: `DUvazek` je hodin denně, `TUvazek` týdně (pětidenní týden).
     *
     * @param array<string,mixed> $relation
     */
    private static function dailyHours(array $relation): ?float
    {
        $daily = PohodaXml::num($relation, 'DUvazek');
        if ($daily > 0) {
            return $daily;
        }
        $weekly = PohodaXml::num($relation, 'TUvazek');
        return $weekly > 0 ? $weekly / 5 : null;
    }

    /**
     * Verze sjednané měsíční mzdy podle historie v PAMICA. Přednost má měsíční sazba
     * složky základní mzdy (`M01`/`M09`, `Hodnota1`): je to sjednaná mzda, kterou PAMICA
     * teprve krátí na odpracovanou část. `KcZaklM` je až krácená částka a nekrácená
     * nebývá ani v měsíci, kdy `DnyOdpra` = `DnyPrac` (placené svátky, lékař v hodinách).
     * Bez sazby se bere `KcZaklM` jen z měsíce odpracovaného celý; vztah bez takového
     * měsíce dostane nejvyšší hodnotu roku, aby mzda nechyběla úplně.
     *
     * @param array<int,array<string,float|bool>> $months
     * @return list<array{from:string,amount:float,prorated:bool}>
     */
    private static function monthlyWages(array $months, int $year, ?string $start, ?string $end): array
    {
        ksort($months);
        if ($months === []) {
            return [];
        }
        // První verze platí od prvního převáděného měsíce vztahu, ne až od měsíce, ve kterém
        // byla poprvé odpracovaná celá. Jinak by měsíce před ní zůstaly bez sjednané mzdy.
        $first = sprintf('%04d-%02d-01', $year, (int) array_key_first($months));
        $runs = [];
        $last = null;
        foreach ($months as $month => $data) {
            $rate = (float) ($data['rate'] ?? 0);
            if ($rate <= 0) {
                continue;
            }
            if ($last === null || abs($last - $rate) > 0.5) {
                $runs[] = [
                    'from' => $runs === [] ? $first : sprintf('%04d-%02d-01', $year, $month),
                    'amount' => $rate,
                    'prorated' => false,
                ];
                $last = $rate;
            }
        }
        if ($runs !== []) {
            return $runs;
        }
        $last = null;
        foreach ($months as $month => $data) {
            $wage = (float) $data['wage'];
            // Nástupní a výstupní měsíc sjednanou mzdu neukazuje: `KcZaklM` je v něm krácený
            // na odpracovanou část a PAMICA k tomu sníží i `DnyPrac` na dny existence vztahu,
            // takže `full_month` by takový měsíc prohlásil za odpracovaný celý. Ověřeno na
            // vztahu od 20. 4. do 3. 6.: 28 636 za 9 dnů a 9 545 za 3 dny je 70 000 × 9/22
            // a 70 000 × 3/22, tedy táž sjednaná mzda 70 000, ne tři různé verze.
            if ($wage <= 0 || $data['full_month'] !== true || !self::wholeMonth($year, (int) $month, $start, $end)) {
                continue;
            }
            if ($last === null || abs($last - $wage) > 0.5) {
                $runs[] = [
                    'from' => $runs === [] ? $first : sprintf('%04d-%02d-01', $year, $month),
                    'amount' => $wage,
                    'prorated' => false,
                ];
                $last = $wage;
            }
        }
        if ($runs !== []) {
            return $runs;
        }
        // Vztah bez jediného celého měsíce: nejvyšší hodnota roku platí od začátku,
        // krácené měsíce nejsou sjednaná mzda.
        $values = [];
        foreach ($months as $data) {
            if ((float) $data['wage'] > 0) {
                $values[] = (float) $data['wage'];
            }
        }
        if ($values === []) {
            return [];
        }
        return [['from' => $first, 'amount' => max($values), 'prorated' => true]];
    }

    /** Leží celý měsíc uvnitř vztahu? Jen takový měsíc nese nekrácenou sjednanou mzdu. */
    private static function wholeMonth(int $year, int $month, ?string $start, ?string $end): bool
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = sprintf('%04d-%02d-%02d', $year, $month, (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year)));

        return ($start === null || $start <= $first) && ($end === null || $end >= $last);
    }

    /**
     * Průměrný výdělek pro náhrady po čtvrtletích: hodnota, se kterou PAMICA počítala mzdy
     * daného čtvrtletí, spolu s rozhodným obdobím (předchozí čtvrtletí) a výdělkem v něm.
     *
     * @param array<int,array<string,float|bool>> $months
     * @return list<array{year:int,quarter:int,hourly:float,from:string,to:string,gross:float,worked:float,days:float}>
     */
    private static function averages(array $months, int $year): array
    {
        ksort($months);
        $out = [];
        foreach ([1, 2, 3, 4] as $quarter) {
            $hourly = 0.0;
            foreach ([$quarter * 3 - 2, $quarter * 3 - 1, $quarter * 3] as $month) {
                $value = (float) ($months[$month]['average'] ?? 0);
                if ($value > 0) {
                    $hourly = $value;
                    break;
                }
            }
            if ($hourly <= 0) {
                continue;
            }
            $gross = 0.0;
            $worked = 0.0;
            $days = 0.0;
            $first = $quarter === 1 ? null : ($quarter - 1) * 3 - 2;
            if ($first !== null) {
                foreach ([$first, $first + 1, $first + 2] as $month) {
                    $gross += (float) ($months[$month]['gross'] ?? 0);
                    $worked += (float) ($months[$month]['worked'] ?? 0);
                    $days += (float) ($months[$month]['worked_days'] ?? 0);
                }
            }
            $out[] = [
                'year' => $year,
                'quarter' => $quarter,
                'hourly' => $hourly,
                'from' => $quarter === 1 ? sprintf('%04d-10-01', $year - 1) : sprintf('%04d-%02d-01', $year, (int) $first),
                'to' => $quarter === 1 ? sprintf('%04d-12-31', $year - 1) : sprintf('%04d-%02d-%02d', $year, $first + 2, (int) date('t', (int) mktime(0, 0, 0, $first + 2, 1, $year))),
                'gross' => $gross,
                'worked' => $worked,
                'days' => $days,
            ];
        }
        return $out;
    }

    /**
     * Osoba pobírá důchod podle karty PAMICA (`ZAM.DDrDuch` druh důchodu, příznak `JeDuch`).
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $relation
     */
    private static function pensioner(array $person, array $relation): bool
    {
        return !in_array(trim(PohodaXml::text($person, 'DDrDuch')), ['', '0'], true)
            || self::bool(PohodaXml::text($person, 'JeDuch')) || self::bool(PohodaXml::text($relation, 'JeDuch'));
    }

    /**
     * Sleva zaměstnavatele na pojistném (§ 7a zák. č. 589/1992 Sb.) u vztahu: důvod a den,
     * od kterého ho PAMICA vede (`SocPojSleva`), a jestli ji v roce žádala a přiznala
     * (`MZ.SocPojSlevaZadost` / `SocPojSlevaNarok`).
     *
     * @param array<string,array<string,mixed>> $rows `SocPojSleva` podle ID
     * @param array{requested?:bool,granted?:bool} $flags
     * @return array{reason:?string,source_reason:string,from:?string,requested:bool,granted:bool}|null
     */
    private static function partTimeDiscount(array $rows, string $relationId, array $flags): ?array
    {
        $found = null;
        foreach ($rows as $row) {
            if (PohodaXml::text($row, 'RefPomer') !== $relationId) {
                continue;
            }
            $from = self::realDate(PohodaXml::date($row, 'DatumOd'));
            if ($found === null || (string) $from >= (string) $found['from']) {
                $code = trim(PohodaXml::text($row, 'RelDuvod'));
                $found = ['reason' => self::PART_TIME_DISCOUNT_REASONS[$code] ?? null, 'source_reason' => $code, 'from' => $from];
            }
        }
        if ($found === null && ($flags['requested'] ?? false) === false && ($flags['granted'] ?? false) === false) {
            return null;
        }

        return ($found ?? ['reason' => null, 'source_reason' => '', 'from' => null])
            + ['requested' => $flags['requested'] ?? false, 'granted' => $flags['granted'] ?? false];
    }

    /**
     * Sleva pracujícího důchodce (§ 7d zák. č. 589/1992 Sb.) po měsících tak, jak ji PAMICA
     * podala v měsíčním hlášení (10490) a ČSSZ přijala. Měsíce s hlášením přepíší odhad
     * z karty; měsíc bez hlášení se nedomýšlí.
     *
     * Sleva důchodce u osoby, která podle karty důchod nemá a podle data narození ho mít
     * nemůže (mladší 55 let), se NEPŘEVEZME: je to chyba dat předchozího programu a tichý
     * přenos by z ní v MyÚčtu udělal dluh na pojistném (§ 7c odst. 3). Záznam dostane
     * příznak `pensioner_discount_doubtful` a převod ho ohlásí k ověření.
     *
     * @param list<array<string,mixed>> $records {@see self::read()}
     * @param array<string,array<string,array{form:\MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm,report:array<string,mixed>}>> $effective
     *        {@see PohodaPayrollJmhzReports::effective()}
     * @return list<array<string,mixed>>
     */
    public static function withSubmittedDiscounts(array $records, array $effective, int $year): array
    {
        foreach ($records as $index => $record) {
            $forms = $effective[(string) $record['relation_key']] ?? [];
            $months = [];
            foreach ($forms as $period => $entry) {
                if ((int) substr((string) $period, 0, 4) !== $year || !is_bool($entry['form']->socialDiscount)) {
                    continue;
                }
                $months[(int) substr((string) $period, 5, 2)] = ['pensioner_discount' => $entry['form']->socialDiscount];
            }
            if ($months === []) {
                continue;
            }
            ksort($months);
            $claimed = in_array(true, array_column($months, 'pensioner_discount'), true);
            $birth = is_string($record['birth_date'] ?? null) ? (string) $record['birth_date'] : null;
            $tooYoung = $birth !== null && (int) substr($birth, 0, 4) > $year - 55;
            if ($claimed && ($record['pensioner'] ?? false) !== true && $tooYoung) {
                $records[$index]['pensioner_discounts'] = [];
                $records[$index]['pensioner_discount_doubtful'] = true;
                continue;
            }
            $records[$index]['pensioner_discounts'] = self::declarations($months, $year, 'pensioner_discount', 'verified', 'not_claimed');
            $records[$index]['pensioner_discount_source'] = 'jmhz';
        }

        return $records;
    }

    /**
     * Stejné osobní číslo, jaké dostane vztah v měsíčním sešitu převodu.
     *
     * @param array<string,mixed> $person
     * @param array<string,mixed> $relation
     */
    public static function personalNumber(array $person, array $relation, int $relationCount): string
    {
        $number = PohodaXml::text($person, 'OsCislo');
        $order = (int) (PohodaXml::text($relation, 'Poradi') ?: '1');
        if ($relationCount > 1 && $order > 1) {
            $number .= '-' . $order;
        }
        return $number;
    }

    /**
     * Měsíční příznak (prohlášení poplatníka, žádost o slevu důchodce) jako souvislé úseky
     * stejného stavu. Poslední úsek zůstává otevřený, měsíc bez mzdy prodlužuje předchozí.
     *
     * @param array<int,array<string,int|bool>> $months
     * @return list<array{from:string,to:?string,status:string,period:string}>
     */
    private static function declarations(array $months, int $year, string $flag = 'signed', string $yes = 'signed', string $no = 'not-signed'): array
    {
        $runs = [];
        foreach ($months as $month => $sums) {
            $status = ($sums[$flag] ?? false) === true ? $yes : $no;
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last]['status'] === $status) {
                continue;
            }
            $from = sprintf('%04d-%02d-01', $year, $month);
            if ($last !== null) {
                $runs[$last]['to'] = (new \DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            }
            $runs[] = ['from' => $from, 'to' => null, 'status' => $status, 'period' => sprintf('%04d-%02d', $year, $month)];
        }
        return $runs;
    }

    /**
     * Oznámení zdravotní pojišťovně po vztazích (`ZAMzp`).
     *
     * @param array<string,array<string,array<string,mixed>>> $byId
     * @return array{start:array<string,list<Evidence>>,end:array<string,list<Evidence>>}
     */
    private static function healthNotices(array $byId): array
    {
        $out = ['start' => [], 'end' => []];
        foreach ($byId['ZAMzp'] ?? [] as $row) {
            $kind = match (PohodaXml::text($row, 'RelKod')) {
                '1' => 'start',
                '2' => 'end',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $out[$kind][PohodaXml::text($row, 'RefPomer')][] = [
                'date' => PohodaXml::date($row, 'Datum'),
                'submitted' => PohodaXml::date($row, 'DatStav'),
                'accepted' => null,
                'state' => PohodaXml::text($row, 'RefStav'),
                'source' => 'ZAMzp',
            ];
        }
        return $out;
    }

    /**
     * Odeslané přihlášky a odhlášky ČSSZ po vztazích: registrace JMHZ (`RegZAM`),
     * starší přihlášky (`ONZ`). Z registrací i OIČ a ID PPV, pokud je karta nemá.
     *
     * @param array<string,array<string,array<string,mixed>>> $byId
     * @return array{start:array<string,Evidence>,end:array<string,Evidence>,oic:array<string,string>,id_ppv:array<string,string>}
     */
    private static function socialSubmissions(array $byId): array
    {
        $out = ['start' => [], 'end' => [], 'oic' => [], 'id_ppv' => []];
        $sources = [
            ['RegZAMitems', 'RegZAM', ['1' => ['start'], '3' => ['start'], '2' => ['end']]],
            ['ONZpol', 'ONZ', ['1' => ['start'], '2' => ['end'], '5' => ['start', 'end']]],
        ];
        foreach ($sources as [$itemTable, $headerTable, $kinds]) {
            foreach ($byId[$itemTable] ?? [] as $item) {
                $header = $byId[$headerTable][PohodaXml::text($item, 'RefAg')] ?? null;
                if ($header === null || !self::bool(PohodaXml::text($header, 'ElOdeslano'))) {
                    continue;
                }
                $relationId = PohodaXml::text($item, 'RefPomer');
                foreach ($kinds[PohodaXml::text($item, 'RelTyp')] ?? [] as $kind) {
                    $out[$kind][$relationId] ??= [
                        'date' => null,
                        'submitted' => PohodaXml::date($header, 'DatPod'),
                        'accepted' => PohodaXml::date($header, 'DatPrij'),
                        'state' => PohodaXml::text($header, 'RelStavDP'),
                        'source' => $headerTable,
                    ];
                }
                if ($itemTable === 'RegZAMitems') {
                    if (($oic = self::digits(PohodaXml::text($item, 'OIC'))) !== null) {
                        $out['oic'][$relationId] = $oic;
                    }
                    if (($ppv = self::digits(PohodaXml::text($item, 'IDPPV'))) !== null) {
                        $out['id_ppv'][$relationId] = $ppv;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Odeslané evidenční listy důchodového pojištění po vztazích.
     *
     * @param array<string,array<string,array<string,mixed>>> $byId
     * @return array<string,Evidence>
     */
    private static function eldp(array $byId): array
    {
        $out = [];
        foreach ($byId['ELDPpol'] ?? [] as $item) {
            $header = $byId['ELDP'][PohodaXml::text($item, 'RefAg')] ?? null;
            if ($header === null || !self::bool(PohodaXml::text($header, 'ElOdeslano'))) {
                continue;
            }
            foreach (['RefZAMpomer1', 'RefZAMpomer2', 'RefZAMpomer3'] as $column) {
                $relationId = PohodaXml::text($item, $column);
                if ($relationId !== '') {
                    $out[$relationId] ??= [
                        'date' => null,
                        'submitted' => PohodaXml::date($header, 'DatPod'),
                        'accepted' => PohodaXml::date($header, 'DatPrij'),
                        'state' => PohodaXml::text($header, 'Rok'),
                        'source' => 'ELDP',
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Oznámení k datu události; bez data události nebo s jiným datem nic.
     *
     * @param list<Evidence> $notices
     * @return Evidence|null
     */
    private static function matching(array $notices, ?string $date): ?array
    {
        if ($date === null) {
            return null;
        }
        foreach ($notices as $notice) {
            if ($notice['date'] === $date) {
                return $notice;
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $person
     * @return array{street_line:string,city:string,postal_code:string,country_code:string}|null
     */
    private static function address(array $person, string $prefix): ?array
    {
        $city = PohodaXml::text($person, $prefix . 'Obec');
        $postal = PohodaXml::text($person, $prefix . 'PSC');
        $country = self::country(PohodaXml::text($person, $prefix . 'Stat'))
            ?? ($prefix === '' ? null : self::country(PohodaXml::text($person, 'Stat')));
        if ($city === '' || $postal === '' || $country === null) {
            return null;
        }
        $street = trim(PohodaXml::text($person, $prefix . 'Ulice') . ' ' . PohodaXml::text($person, $prefix . 'CP'));
        return [
            'street_line' => mb_substr($street !== '' ? $street : $city, 0, 191),
            'city' => mb_substr($city, 0, 128),
            'postal_code' => mb_substr($postal, 0, 24),
            'country_code' => $country,
        ];
    }

    /**
     * @param array<string,mixed> $place
     * @return array{municipality_code:string,work_place:string,regular_workplace:?string,country_code:string}|null
     */
    private static function workplace(array $place): ?array
    {
        $code = PohodaXml::text($place, 'Cislo');
        $city = PohodaXml::text($place, 'Obec');
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1 || $city === '') {
            return null;
        }
        $name = PohodaXml::text($place, 'Misto');
        return [
            'municipality_code' => $code,
            'work_place' => mb_substr($city, 0, 255),
            'regular_workplace' => $name !== '' ? mb_substr($name, 0, 255) : null,
            'country_code' => self::country(PohodaXml::text($place, 'Stat')) ?? 'CZ',
        ];
    }

    private static function country(string $value): ?string
    {
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{2}$/D', $value) === 1 ? $value : null;
    }

    private static function email(string $value): ?string
    {
        return $value !== '' && strlen($value) <= 191 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }

    private static function phone(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^\+?[0-9][0-9 ()\/.-]{4,39}$/', $value) === 1 ? $value : null;
    }

    private static function digits(string $value): ?string
    {
        $value = (string) preg_replace('/\s+/u', '', $value);
        return preg_match('/^[0-9]{1,22}$/D', $value) === 1 ? $value : null;
    }

    private static function limited(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Nulové datum Accessu (před rokem 1901) = žádné datum. */
    private static function realDate(?string $date): ?string
    {
        return $date !== null && (int) substr($date, 0, 4) >= 1901 ? $date : null;
    }

    private static function bool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', '-1', 'true'], true);
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }
}
