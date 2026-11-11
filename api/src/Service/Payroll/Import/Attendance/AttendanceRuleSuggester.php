<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Attendance;

/**
 * Automatický návrh významu sloupce podle obecných českých popisků docházky.
 *
 * Pořadí vzorů je priorita: specifičtější popisky („Volno za přesčas",
 * „Příplatek noční") musí vyhrát nad obecnými („Přesčas", „Noční").
 * Neznámý popisek = `ignore`; návrh nikdy nehádá.
 */
final class AttendanceRuleSuggester
{
    /** @var list<array{0:string,1:string,2:?string}> vzor nad normalizovanou hlavičkou, význam, složka */
    private const PATTERNS = [
        ['/volno za prescas|nahradni volno|cerpani prescasu/', 'compensatory_time_off_hours', null],
        ['/priplat/', 'component', 'PREMIE_PRIPLATKY'],
        ['/ukol/', 'component', 'MZDA_UKOLOVA'],
        ['/odmen|bonus|premie|premii/', 'component', 'ODMENA'],
        ['/hrub(a|y|e) (mzda|prijem|mzdy)|^hruba$|^hrube$/', 'reference_gross', null],
        ['/cist(a|y) (mzda|prijem)|k vyplate|^cista$/', 'reference_net', null],
        ['/osobni cislo|^os\.? ?c(\.|islo)?$|^osc$|cislo zamestnance|kod zamestnance|personal number/', 'personal_number', null],
        ['/rodne cislo|^r\.? ?c\.?$/', 'birth_number', null],
        ['/datum narozeni|^narozen/', 'birth_date', null],
        ['/zdravotni pojistovn|^pojistovna$|kod pojistovny|^zp$/', 'health_insurer_code', null],
        ['/jmeno|prijmeni|zamestnanec|^osoba$|^pracovnik$|^name$|^employee$/', 'person_name', null],
        ['/(druh|typ).*(pomer|vztah|smlouv)|pracovni pomer|pracovnepravni/', 'relation_label', null],
        ['/oddeleni|utvar|department/', 'department', null],
        ['/stredisk|cost cent/', 'cost_center', null],
        ['/pozice|funkce|profese|pracovni zarazeni/', 'position', null],
        ['/tydenni|uvazek|hodin tydne/', 'weekly_hours', null],
        ['/nastup|ukonceni|vystup/', 'start_end_note', null],
        ['/fond/', 'fund_hours', null],
        ['/neplacen/', 'unpaid_leave_hours', null],
        ['/neomluven|absence/', 'unexcused_hours', null],
        ['/prekazk.*zamestnavatel|strane zamestnavatele|prestoj/', 'obstacle_employer_hours', null],
        ['/paragraf|prekazk|§/', 'obstacle_employee_hours', null],
        ['/lekar/', 'doctor_hours', null],
        ['/^ocr\b|ocr$|osetrovan|osetreni clena/', 'care_hours', null],
        ['/otcovsk/', 'paternity_hours', null],
        ['/nemoc|^dpn|dpn$|pracovni neschopnost/', 'sick_hours', null],
        ['/dovolen/', 'vacation_hours', null],
        ['/prace (ve|v|o) svat|svatek odprac|prace svatek/', 'holiday_work_hours', null],
        ['/svat/', 'holiday_hours', null],
        ['/nocni|\bnoc\b/', 'night_hours', null],
        ['/vikend|sobot|nedel/', 'weekend_hours', null],
        ['/odpoledn/', 'afternoon_hours', null],
        ['/prescas/', 'overtime_hours', null],
        ['/sluzebni cest|pracovni cest/', 'business_trip_hours', null],
        ['/home ?office|prace z domova/', 'home_office_hours', null],
        ['/prace celkem|odprac|^prace$/', 'worked_hours', null],
        ['/^hodiny$|^hodin$|pocet hodin|hodiny celkem/', 'reference_hours', null],
    ];

    /**
     * Údaje osoby, které vlastní sešit firmy pojmenuje stejně jako šablona
     * Vzoru MyÚčto (sazba, konec dohody, účet a údaje pro registraci ČSSZ).
     * Hlavičky se berou ze seznamu sloupců šablony, aby návrh a šablona
     * zůstaly jedním zdrojem ({@see AttendanceMyUctoSampleProfile::columns()}).
     */
    private const TEMPLATE_MEANINGS = ['hourly_rate', 'end_date', 'bank_account', ...AttendanceMeaning::REGISTRATION];

    /** @var list<array{0:string,1:string}>|null vzor hlavičky, význam */
    private static ?array $templatePatterns = null;

    /** @return array{meaning:string,component_code:?string} */
    public function suggest(string $header): array
    {
        $normalized = AttendanceText::normalize($header);
        if ($normalized === '') {
            return ['meaning' => AttendanceMeaning::IGNORE, 'component_code' => null];
        }
        foreach (self::templatePatterns() as [$pattern, $meaning]) {
            if (AttendanceRules::like($pattern, $normalized)) {
                return ['meaning' => $meaning, 'component_code' => null];
            }
        }
        foreach (self::PATTERNS as [$pattern, $meaning, $component]) {
            if (preg_match($pattern . 'u', $normalized) === 1) {
                return ['meaning' => $meaning, 'component_code' => $component];
            }
        }

        return ['meaning' => AttendanceMeaning::IGNORE, 'component_code' => null];
    }

    /** @return list<array{0:string,1:string}> */
    private static function templatePatterns(): array
    {
        if (self::$templatePatterns === null) {
            self::$templatePatterns = [];
            foreach (AttendanceMyUctoSampleProfile::columns() as $column) {
                if (!in_array($column['meaning'], self::TEMPLATE_MEANINGS, true)) {
                    continue;
                }
                foreach ($column['patterns'] as $pattern) {
                    self::$templatePatterns[] = [$pattern, $column['meaning']];
                }
            }
        }

        return self::$templatePatterns;
    }

    public function isPersonHeader(string $header): bool
    {
        return $this->suggest($header)['meaning'] === AttendanceMeaning::PERSON_NAME;
    }
}
