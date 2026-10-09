<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Absence\PayrollWageReplacementTitle;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use PDO;

final class PayrollJmhzWorkMonthSummaryBuilder
{
    public const DERIVATION_VERSION = 'jmhz-work-month.v9';

    /**
     * Souhrn měsíce, který bere odpracovanou dobu ze souhrnu importu docházky
     * (`payroll_time_months.work_source = 'import_summary'`), ne z intervalů.
     *
     * Vlastní verze proto, že zdrojový snapshot nese `import_summary` místo
     * `time_entries` a počet odpracovaných dnů (10267) smí zůstat NEUVEDENÝ:
     * podklady ho nenesou a dopočítat ho dělením hodin by bylo vymyšlené číslo.
     * Hodinové bloky nese stejné jako souhrn z intervalů téže generace
     * (v6 jako v5, v8 jako v7).
     */
    public const IMPORT_SUMMARY_DERIVATION_VERSION = 'jmhz-work-month.v8';

    /** Všechny verze souhrnu ze souhrnu importu docházky. */
    public const IMPORT_SUMMARY_VERSIONS = ['jmhz-work-month.v6', 'jmhz-work-month.v8'];

    /**
     * Neodpracované hodiny svátků v jinak pracovní dny.
     *
     * Pokyny MPSV k vyplnění MH 1.4.13 je zahrnují do celkového počtu
     * neodpracovaných hodin 10275 („dovolenou, svátky v jinak pracovní dny
     * (zahrnují se i neodpracované hodiny, kdy se měsíční mzda … nekrátí)")
     * i do hodin s náhradou či nekrácením mzdy 10276. Sjednaný fond 10260
     * svátky také obsahuje, takže bez nich neplatí 10268 + 10275 = 10260.
     *
     * Hodiny nezadává účetní, odvozují se z pracovního kalendáře vztahu:
     * svátek v den, na který rozvrh plánuje práci, mimo nepřítomnost (dovolená
     * svátek nečerpá, § 219 ZP) a bez odpracované doby. Svátek uvnitř nemoci
     * nese hodinový blok nemoci; uvnitř nepřítomnosti bez příjmu se mzda za
     * svátek neposkytuje, takže do 10276 nepatří a souhrn ho neodvozuje.
     * Nese je až v7 (a v8 z importu): přidat klíč do starší verze by změnilo
     * obsahový otisk už zmrazených souhrnů.
     */
    private const HOLIDAY_FIELDS = ['holiday_millihours'];

    /** Verze souhrnu, které nesou {@see HOLIDAY_FIELDS}. */
    public const VERSIONS_WITH_HOLIDAYS = ['jmhz-work-month.v7', 'jmhz-work-month.v8', 'jmhz-work-month.v9'];

    /** @return list<string> */
    public static function holidayFields(): array
    {
        return self::HOLIDAY_FIELDS;
    }

    /**
     * Neodpracované hodiny svátků v jinak pracovní dny uvnitř nepřítomnosti, za
     * kterou se mzda za svátek krátí (rodičovská, PPM, otcovská, ošetřovné,
     * neplacené volno, § 115 odst. 3 ZP).
     *
     * Pokyny k 10275 svátky do celkového počtu neodpracovaných hodin počítají,
     * mzda ani náhrada za ně ale nenáleží, takže do 10276 nepatří. Celý měsíc
     * rodičovské se svátkem tak dává 10268 + 10275 = 10260. Nese je až v9
     * (z intervalů): přidat klíč do starší verze by změnilo obsahový otisk už
     * zmrazených souhrnů.
     */
    private const UNPAID_HOLIDAY_FIELDS = ['holiday_unpaid_millihours'];

    /** Verze souhrnu, které nesou {@see UNPAID_HOLIDAY_FIELDS}. */
    public const VERSIONS_WITH_UNPAID_HOLIDAYS = ['jmhz-work-month.v9'];

    /** @return list<string> */
    public static function unpaidHolidayFields(): array
    {
        return self::UNPAID_HOLIDAY_FIELDS;
    }

    /**
     * Verze s potvrzenými podmíněnými bloky 10275–10280 a 10471/10472.
     *
     * Jediný výčet pro všechny čtenáře souhrnu (snímek běhu, zákonné vstupy,
     * příprava JMHZ, ELDP, průměrný výdělek). Dřív měl každý vlastní kopii
     * a nová verze se musela dopisovat na šest míst.
     */
    public const CONDITIONAL_VERSIONS = [
        'jmhz-work-month.v2',
        'jmhz-work-month.v3',
        'jmhz-work-month.v4',
        'jmhz-work-month.v5',
        'jmhz-work-month.v6',
        'jmhz-work-month.v7',
        'jmhz-work-month.v8',
        'jmhz-work-month.v9',
    ];

    /** Provenience souhrnu potvrzeného hromadným schválením dávky importu. */
    public const IMPORT_BULK_CONFIRMATION = 'import_bulk_confirmation';

    /**
     * Hodiny z importu docházky, které jdou do podmíněných bloků bez dat
     * nepřítomnosti.
     *
     * Dovolená a obě překážky v práci netvoří vyloučenou dobu evidenčního
     * listu (§ 16 odst. 4 zákona č. 155/1995 Sb.) ani vyloučený den podle
     * § 18 odst. 7 zákona č. 187/2006 Sb. — náhrada mzdy za ně náleží. ELDP
     * proto k nim dny od–do nepotřebuje a měsíční hodiny stačí. Návštěva
     * lékaře je placená překážka na straně zaměstnance (§ 199 ZP), stejně jako
     * ji aplikace vede u evidované nepřítomnosti.
     *
     * Svátek (`holiday_hours`) má vlastní blok {@see HOLIDAY_FIELDS} a do
     * úhrnů 10275/10276 se přičítá až při potvrzení, stejně jako svátek
     * odvozený z kalendáře. Služební cesta a práce z domova jsou odpracovaná
     * doba.
     */
    public const IMPORT_DATE_FREE_BLOCKS = [
        'vacation_hours' => 'vacation',
        'doctor_hours' => 'employee_obstacle_paid',
        'obstacle_employee_hours' => 'employee_obstacle_paid',
        'obstacle_employer_hours' => 'employer_obstacle',
    ];

    /**
     * Sloupce souhrnu, které u souhrnu z importu smí nést hodiny bez dat
     * nepřítomnosti ({@see IMPORT_DATE_FREE_BLOCKS}).
     *
     * @return list<string>
     */
    public static function importDateFreeSummaryFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $block): string => $block . '_millihours',
            array_values(self::IMPORT_DATE_FREE_BLOCKS),
        )));
    }

    /**
     * Hodiny z importu, ke kterým hlášení potřebuje DATA nepřítomnosti.
     *
     * Nemoc se dělí oknem náhrady mzdy (§ 192 ZP) na 10278 a 10277, ošetřovné
     * a otcovská jsou vyloučenou dobou ELDP, neplacené volno a náhradní volno
     * tvoří vyloučené dny § 18 odst. 7 a neomluvená absence rozhoduje o době
     * pojištění. Ze samotného měsíčního součtu hodin nic z toho odvodit nejde,
     * proto se k nim nic nenavrhuje a hromadné schválení je vrací k ručnímu
     * zpracování.
     *
     * @var list<string>
     */
    public const IMPORT_HOURS_REQUIRING_DATES = [
        'sick_hours',
        'care_hours',
        'paternity_hours',
        'unpaid_leave_hours',
        'unexcused_hours',
        'compensatory_time_off_hours',
    ];

    /**
     * Neodpracované hodiny bez vlastního atributu hlášení.
     *
     * PPM, otcovská, rodičovská dovolená, neplacené volno a neomluvená absence
     * se ČSSZ nevykazují po hodinách — hlášení pro ně žádný blok 10275–10280
     * ani 10471/10472 nemá. Do souhrnu patří ze dvou důvodů:
     *
     * 1. **Úhrn 10275 má být pravdivý.** „Celkový počet neodpracovaných hodin"
     *    je úhrn VŠECH neodpracovaných hodin měsíce, ne jen těch, které mají
     *    vlastní rozpad. Kdyby se hodiny rodičovské z úhrnu vypustily, hlásilo
     *    by se menší číslo, než jaké odpovídá evidenci.
     * 2. **Dny evidenčního listu potřebují protistranu.** Ordinary ELDP řez
     *    páruje DNY z evidence absencí s HODINAMI z publikovaných směn; bez
     *    tohohle rozpadu se PPM ani otcovská nedají proti ničemu ověřit, a
     *    proto dosud blokovaly celé měsíční hlášení firmy.
     *
     * Do 10276 (hodiny s náhradou či nekrácením mzdy) NEPATŘÍ ani jedna z nich:
     * PPM, otcovskou a ošetřovné platí dávka nemocenského pojištění, rodičovská,
     * neplacené volno i neomluvená absence jsou bez příjmu.
     *
     * @var list<string>
     */
    private const LOCAL_EVIDENCE_FIELDS = [
        'maternity_millihours',
        'paternity_millihours',
        'parental_millihours',
        'unpaid_leave_millihours',
        'unexcused_millihours',
    ];

    /** Verze souhrnu, které nesou {@see LOCAL_EVIDENCE_FIELDS}. */
    public const VERSIONS_WITH_LOCAL_EVIDENCE = [
        'jmhz-work-month.v3',
        'jmhz-work-month.v4',
        'jmhz-work-month.v5',
        'jmhz-work-month.v6',
        'jmhz-work-month.v7',
        'jmhz-work-month.v8',
        'jmhz-work-month.v9',
    ];

    /**
     * Hodiny čerpaného náhradního volna za práci přesčas (§ 114 odst. 1 ZP).
     *
     * Za dobu čerpání mzda nepřísluší (u měsíční mzdy se krátí), takže hodiny
     * patří jen do úhrnu 10275, ne do 10276 („s náhradou či nekrácením
     * mzdy"), a vlastní blok 10277–10280 nemají. Nese je až v5: přidat klíč
     * do starší verze by změnilo obsahový otisk už zmrazených souhrnů.
     *
     * @var list<string>
     */
    private const COMPENSATORY_TIME_OFF_FIELDS = ['compensatory_time_off_millihours'];

    /** Verze souhrnu, které nesou {@see COMPENSATORY_TIME_OFF_FIELDS}. */
    public const VERSIONS_WITH_COMPENSATORY_TIME_OFF = [
        'jmhz-work-month.v5',
        'jmhz-work-month.v6',
        'jmhz-work-month.v7',
        'jmhz-work-month.v8',
        'jmhz-work-month.v9',
    ];

    /** @return list<string> */
    public static function compensatoryTimeOffFields(): array
    {
        return self::COMPENSATORY_TIME_OFF_FIELDS;
    }

    /**
     * Rozpad odpracované doby: dny (10267) a přesčas (10269).
     *
     * Oba atributy jsou v matici povinností NEPOVINNÉ, ale v přijatých
     * hlášeních jsou vyplněné vždy — účetní je jinak musí dopisovat ručně,
     * přestože je aplikace umí odvodit ze stejných časových záznamů, ze
     * kterých počítá odpracované hodiny (10268).
     *
     * Dny se počítají jako RŮZNÉ kalendářní dny s alespoň jedním záznamem
     * kategorie `regular` nebo `overtime` — dvě směny v jednom dni jsou
     * jeden odpracovaný den, ne dva. Přesčas je podmnožina odpracovaných
     * hodin, ne přičítaná položka.
     *
     * @var list<string>
     */
    private const WORKED_BREAKDOWN_FIELDS = [
        'worked_days',
        'overtime_millihours',
    ];

    /**
     * Verze souhrnu, které nesou {@see WORKED_BREAKDOWN_FIELDS}.
     *
     * v4, v5, v7 a v9 mají dny vždy vyplněné; v6 a v8 (souhrn z importu) je smí
     * mít NEUVEDENÉ, protože podklady docházky dny nenesou.
     */
    public const VERSIONS_WITH_WORKED_BREAKDOWN = [
        'jmhz-work-month.v4',
        'jmhz-work-month.v5',
        'jmhz-work-month.v6',
        'jmhz-work-month.v7',
        'jmhz-work-month.v8',
        'jmhz-work-month.v9',
    ];

    /** @return list<string> */
    public static function localEvidenceFields(): array
    {
        return self::LOCAL_EVIDENCE_FIELDS;
    }

    /** @return list<string> */
    public static function workedBreakdownFields(): array
    {
        return self::WORKED_BREAKDOWN_FIELDS;
    }

    /**
     * Zákonná týdenní doba podle § 79 odst. 1 zákoníku práce.
     *
     * Z ní se počítá NÁVRH stanoveného měsíčního fondu (atribut 10259).
     * Kratší zákonná doba podle § 79 odst. 2 (37,5 h u podzemní a třísměnné
     * práce, 38,75 h u dvousměnné) je výjimka, kterou aplikace u vztahu
     * nevede — proto je to návrh k přepsání, ne dopočtená hodnota.
     */
    private const STATUTORY_WEEKLY_MINUTES = 2400;

    /** § 79 odst. 2 písm. a) zákoníku práce: 37,5 h týdně. */
    private const SHORTEST_STATUTORY_WEEKLY_MINUTES = 2250;

    /** Pokyny MPSV k 10261: „missingová hodnota 99", viz weeklyWorkMissingValue(). */
    private const WEEKLY_WORK_MISSING_VALUE = '99';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollCalendarFundService $fund,
        private readonly PayrollJmhzAbsenceHoursDeriver $absenceHours,
    ) {}

    /**
     * Návrh stanoveného měsíčního fondu (10259) v hodinách.
     *
     * Why: dřív se nenavrhoval vůbec a účetní ho musela do dialogu opsat
     * ručně — u stovky vztahů stokrát totéž číslo, které aplikace umí spočítat.
     * Prázdné pole navíc odcházelo na server a vracelo se jako
     * „standard_fund_hours musí být nezáporné desetinné číslo".
     *
     * Počítá se ze zákonné týdenní doby rozvržené na pondělí až pátek. Svátky
     * v jinak pracovní dny se do fondu ZAPOČÍTÁVAJÍ: pokyny MPSV k 10259
     * chtějí „celkový počet hodin za pracovní dny (včetně svátky v jinak
     * pracovní dny)", resp. „8 hodin za každý pracovní den včetně svátků
     * připadajících na jinak pracovní den". Přijatá hlášení jiného systému
     * mají v dubnu se dvěma svátky 176 hodin, ne 160. Stejně se počítá
     * sjednaný fond 10260 z rozvrhu vztahu. Není to fond TOHOTO zaměstnance
     * (ten je 10260) — je to doba stanovená pro profesi, tedy plný úvazek
     * v daném měsíci.
     *
     * Výjimka je člen orgánu a společník (formulář `cinnostKS`, druhy činnosti
     * K a N až S): pokyny MPSV k 10259 i 10260 doslova „V případě zaměstnanců
     * s druhem činnosti K–S … se uvede nulová hodnota". Sjednaný fond 10260 už
     * nulový byl (kalendář se u nich nepočítá), stanovený se dřív navrhoval
     * plný. Jde o návrh: potvrzení účetní dovolí výjimečně zapsat dobu ze
     * smlouvy o výkonu funkce.
     *
     * Týdenní základ je stanovená týdenní doba vztahu (viz statedWeeklyHours()),
     * pokud leží v rozmezí § 79 odst. 1 a 2 (37,5 až 40 h) — profese v třísměnném
     * nebo nepřetržitém režimu má fond z 37,5 h, ne ze 40 h. Mimo to rozmezí
     * (vztah bez vyplněného úvazku, stanovená doba zkrácená kolektivní smlouvou)
     * zůstává zákonných 40 h.
     */
    private function standardFundSuggestion(string $periodStart, string $relationType, ?string $statedWeeklyHours = null): string
    {
        if (!self::requiresShiftCalendar($relationType)) {
            return '0';
        }
        $weeklyMinutes = self::STATUTORY_WEEKLY_MINUTES;
        if ($statedWeeklyHours !== null && is_numeric($statedWeeklyHours)) {
            $stated = (int) round((float) $statedWeeklyHours * 60);
            if ($stated >= self::SHORTEST_STATUTORY_WEEKLY_MINUTES && $stated <= self::STATUTORY_WEEKLY_MINUTES) {
                $weeklyMinutes = $stated;
            }
        }
        $daily = (int) ($weeklyMinutes / 5);
        $month = $this->fund->month(
            substr($periodStart, 0, 7),
            array_fill_keys([1, 2, 3, 4, 5], $daily),
        );
        $minutes = 0;
        foreach ($month['days'] as $day) {
            $minutes += $day['weekday'] <= 5 ? $daily : 0;
        }

        return (string) self::minutesSuggestion($minutes);
    }

    /** @return array<string,mixed> */
    public function preview(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources = false,
    ): array
    {
        $period = self::period($periodStart);
        $importSource = $this->importSource($supplierId, $employmentId, $periodStart, $lockSources);
        if ($importSource['work_source'] === 'import_summary') {
            return $this->previewFromImportSummary(
                $supplierId,
                $employmentId,
                $periodStart,
                $importSource['summary'],
                $lockSources,
            );
        }
        $periodEnd = $period->modify('first day of next month');
        $employment = $this->employment(
            $supplierId,
            $employmentId,
            $periodStart,
            $lockSources,
        );
        $calendars = $this->calendars(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        $entries = $this->entries(
            $supplierId,
            $employmentId,
            $periodStart,
            $lockSources,
        );
        $absences = $this->absences(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        [$evidenceFrom, $evidenceTo, $evidenceDays] = self::evidenceInterval(
            $employment,
            $period,
            $periodEnd,
            $absences,
        );
        $workedSource = PayrollWorkedTimeSource::fromEntries($entries, $periodStart);
        $worked = [
            'minutes' => (int) $workedSource['worked_minutes'],
            'days' => (int) $workedSource['worked_days'],
            'overtime_minutes' => (int) $workedSource['overtime_minutes'],
        ];
        $entryIssues = $workedSource['issues'];
        $derivedAbsences = $this->absenceHours->derive(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $absences,
        );
        [$agreedMinutes, $calendarIssues] = match (true) {
            !self::requiresShiftCalendar($employment['relation_type']) => [0, []],
            self::isAgreement($employment['relation_type']) => self::agreementFundMinutes(
                $employment,
                $period,
                $periodEnd,
                $calendars,
                $worked,
                $derivedAbsences['supported'] ? (int) ($derivedAbsences['minutes']['vacation'] ?? 0) : 0,
            ),
            default => self::agreedFundMinutes($calendars, $evidenceFrom, $evidenceTo),
        };
        [$holidayMinutes, $unpaidHolidayMinutes] = !self::requiresShiftCalendar($employment['relation_type'])
            || self::isAgreement($employment['relation_type'])
                ? [0, 0]
                : $this->holidayMinutes($calendars, $evidenceFrom, $evidenceTo, $entries, $absences, $periodStart);
        $employmentIssues = self::employmentIssues($employment);
        $absenceIssues = self::absenceIssues($absences);
        $source = [
            'schema_version' => self::DERIVATION_VERSION,
            'specification' => self::specification(),
            'supplier_id' => $supplierId,
            'employment' => $employment,
            'period_start' => $periodStart,
            'calendars' => $calendars,
            'time_entries' => $entries,
            'absences' => $absences,
        ];
        $sourceJson = CanonicalJson::encode($source);

        return [
            'derivation_version' => self::DERIVATION_VERSION,
            'work_source' => 'entries',
            'relation_type' => $employment['relation_type'],
            'source_snapshot_json' => $sourceJson,
            'source_snapshot_sha256' => hash('sha256', $sourceJson),
            'suggestions' => [
                'standard_fund_hours' => $this->standardFundSuggestion($periodStart, $employment['relation_type'], $employment['stated_weekly_hours']),
                'agreed_fund_hours' => self::minutesSuggestion($agreedMinutes),
                'weekly_work_hours' => self::weeklyWorkMissingValue($employment['relation_type'])
                    ? self::WEEKLY_WORK_MISSING_VALUE
                    : $employment['stated_weekly_hours'],
                'evidence_days' => $evidenceDays,
                'worked_hours' => self::minutesSuggestion($worked['minutes']),
                'worked_days' => $worked['days'],
                'overtime_hours' => self::minutesSuggestion($worked['overtime_minutes']),
            ] + self::conditionalSuggestions($derivedAbsences),
            'issues' => array_merge(
                $employmentIssues,
                $calendarIssues,
                $entryIssues,
                $absenceIssues,
            ),
            // Svátky v jinak pracovní dny; do 10275 a 10276 se přičtou při
            // potvrzení, ne v dialogu (viz HOLIDAY_FIELDS). `null` = svátek
            // nejde vyjádřit v celých tisícinách hodiny a souhrn nejde potvrdit.
            'holiday_millihours' => self::minutesToMillihours($holidayMinutes),
            // Svátek uvnitř nepřítomnosti, za kterou se mzda krátí: do 10275 ano
            // (pokyny k 10275 svátky počítají), do 10276 ne (viz UNPAID_HOLIDAY_FIELDS).
            'holiday_unpaid_millihours' => self::minutesToMillihours($unpaidHolidayMinutes),
            'requires_unworked_hours_followup' => $absences !== [],
            /*
             * Druhy nepřítomnosti, které v měsíci opravdu jsou.
             *
             * Dialog podle nich ukáže jen ta doplňková pole, ke kterým existuje
             * evidovaná nepřítomnost. Rodičovská, neplacené volno ani otcovská
             * jsou vzácné — kdyby se na ně ptal každý měsíc každé firmy, byl by
             * to krok navíc pro všechny kvůli menšině.
             */
            'absence_types' => self::absenceTypes($absences),
        ];
    }

    /**
     * Náhled souhrnu měsíce, který bere odpracovanou dobu ze souhrnu importu.
     *
     * Zdroj se zamyká a otiskuje stejně jako u intervalů, jen místo
     * `time_entries` nese neměnný souhrn z dávky (hodnoty, původ každého čísla
     * a jeho otisk). Dny (10267) zůstávají neuvedené, přesčas (10269) se bere
     * z podkladů a neodpracované hodiny z bloků, které se dají převzít bez dat
     * nepřítomnosti ({@see importConditionalSuggestions()}).
     *
     * @param array<string,mixed>|null $summary
     * @return array<string,mixed>
     */
    private function previewFromImportSummary(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        ?array $summary,
        bool $lockSources,
    ): array {
        $period = self::period($periodStart);
        $periodEnd = $period->modify('first day of next month');
        $employment = $this->employment($supplierId, $employmentId, $periodStart, $lockSources);
        $calendars = $this->calendars(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        $entries = $this->entries($supplierId, $employmentId, $periodStart, $lockSources);
        $absences = $this->absences(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd->format('Y-m-d'),
            $lockSources,
        );
        [$evidenceFrom, $evidenceTo, $evidenceDays] = self::evidenceInterval($employment, $period, $periodEnd, $absences);
        $worked = PayrollWorkedTimeSource::fromImportSummary($summary);
        $workedSuggestion = $worked['worked_millihours'] === null
            ? null
            : self::millihoursSuggestion($worked['worked_millihours']);
        [$agreedSuggestion, $calendarIssues] = match (true) {
            !self::requiresShiftCalendar($employment['relation_type']) => ['0', []],
            self::isAgreement($employment['relation_type']) => self::importAgreementFund(
                $employment,
                $period,
                $periodEnd,
                $calendars,
                $worked['worked_millihours'],
                is_int($summary['values']['vacation_hours'] ?? null) ? $summary['values']['vacation_hours'] : 0,
            ),
            default => (static function () use ($calendars, $evidenceFrom, $evidenceTo): array {
                [$minutes, $issues] = self::agreedFundMinutes($calendars, $evidenceFrom, $evidenceTo);

                return [self::minutesSuggestion($minutes), $issues];
            })(),
        };
        $sourceIssues = self::entriesStartInPeriod($entries, $periodStart)
            ? [[
                'code' => 'work_source_conflict',
                'message' => 'Pracovní měsíc bere docházku ze souhrnu importu, ale má i časové záznamy. '
                    . 'Dva zdroje téže doby se neslučují.',
            ]]
            : [];
        $values = is_array($summary['values'] ?? null) ? $summary['values'] : [];
        $conditional = self::importConditionalSuggestions($values);
        $source = [
            'schema_version' => self::IMPORT_SUMMARY_DERIVATION_VERSION,
            'specification' => self::specification(),
            'supplier_id' => $supplierId,
            'employment' => $employment,
            'period_start' => $periodStart,
            'calendars' => $calendars,
            PayrollWorkedTimeSource::KIND_IMPORT_SUMMARY => $summary,
            'absences' => $absences,
        ];
        $sourceJson = CanonicalJson::encode($source);

        return [
            'derivation_version' => self::IMPORT_SUMMARY_DERIVATION_VERSION,
            'work_source' => 'import_summary',
            'relation_type' => $employment['relation_type'],
            'source_snapshot_json' => $sourceJson,
            'source_snapshot_sha256' => hash('sha256', $sourceJson),
            'suggestions' => [
                'standard_fund_hours' => $this->standardFundSuggestion($periodStart, $employment['relation_type'], $employment['stated_weekly_hours']),
                'agreed_fund_hours' => $agreedSuggestion,
                'weekly_work_hours' => self::weeklyWorkMissingValue($employment['relation_type'])
                    ? self::WEEKLY_WORK_MISSING_VALUE
                    : $employment['stated_weekly_hours'],
                'evidence_days' => $evidenceDays,
                'worked_hours' => $workedSuggestion,
                'worked_days' => $worked['worked_days'],
                'overtime_hours' => $worked['overtime_millihours'] === null
                    ? null
                    : self::millihoursSuggestion($worked['overtime_millihours']),
            ] + $conditional,
            'issues' => array_merge(
                self::employmentIssues($employment),
                $calendarIssues,
                $worked['issues'],
                $sourceIssues,
                self::absenceIssues($absences),
            ),
            // Placené hodiny svátku z podkladů docházky („Svátek (h)").
            'holiday_millihours' => is_int($values['holiday_hours'] ?? null) && $values['holiday_hours'] > 0
                ? $values['holiday_hours']
                : 0,
            'requires_unworked_hours_followup' => $absences !== []
                || $conditional['unworked_hours_occurred'] !== false,
            'absence_types' => self::absenceTypes($absences),
            'import_hours_requiring_dates' => self::importHoursRequiringDates($values),
        ];
    }

    /**
     * Zdroj odpracované doby měsíce a souhrn importu k jeho aktuální revizi.
     *
     * @return array{work_source:string,summary:array<string,mixed>|null}
     */
    private function importSource(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources,
    ): array {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT month_row.work_source,
                    summary.id AS summary_id, summary.time_month_id,
                    summary.time_month_revision_no, summary.attendance_import_id,
                    summary.values_json, summary.worked_days, summary.sources_json,
                    summary.content_sha256
               FROM payroll_time_months month_row
               LEFT JOIN payroll_time_month_import_summaries summary
                 ON summary.supplier_id = month_row.supplier_id
                AND summary.time_month_id = month_row.id
                AND summary.time_month_revision_no = month_row.revision_no
              WHERE month_row.supplier_id = ? AND month_row.employment_id = ?
                AND month_row.period_start = ?' . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['work_source' => 'entries', 'summary' => null];
        }
        $workSource = (string) $row['work_source'];
        if ($row['summary_id'] === null) {
            return ['work_source' => $workSource, 'summary' => null];
        }
        $values = json_decode((string) $row['values_json'], true, flags: JSON_THROW_ON_ERROR);
        $sources = json_decode((string) $row['sources_json'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($values) || !is_array($sources)) {
            throw new \UnexpectedValueException('Souhrn pracovního měsíce z importu je neplatný.');
        }
        $typedValues = [];
        foreach ($values as $meaning => $millihours) {
            $typedValues[(string) $meaning] = (int) $millihours;
        }
        ksort($typedValues);

        return [
            'work_source' => $workSource,
            'summary' => [
                'id' => (int) $row['summary_id'],
                'time_month_id' => (int) $row['time_month_id'],
                'time_month_revision_no' => (int) $row['time_month_revision_no'],
                'attendance_import_id' => (int) $row['attendance_import_id'],
                'values' => $typedValues,
                'worked_days' => $row['worked_days'] === null ? null : (int) $row['worked_days'],
                'sources' => $sources,
                'content_sha256' => (string) $row['content_sha256'],
            ],
        ];
    }

    /**
     * Návrh podmíněných bloků ze souhrnu importu docházky.
     *
     * Převezme jen hodiny, ke kterým hlášení data nepřítomnosti nepotřebuje
     * ({@see IMPORT_DATE_FREE_BLOCKS}). Jakmile podklady nesou hodiny, které
     * je potřebují ({@see IMPORT_HOURS_REQUIRING_DATES}), nenavrhne se NIC:
     * částečný návrh by v úhrnu 10275 tiše chyběl, stejně jako u
     * {@see conditionalSuggestions()}.
     *
     * @param array<string,mixed> $values význam → millihodiny
     * @return array<string,string|bool|null>
     */
    public static function importConditionalSuggestions(array $values): array
    {
        $buckets = [
            'unworked_total_hours' => 0,
            'unworked_paid_hours' => 0,
            'dpn_without_employer_compensation_hours' => 0,
            'dpn_with_employer_compensation_hours' => 0,
            'vacation_hours' => 0,
            'care_hours' => 0,
            'employee_obstacle_paid_hours' => 0,
            'employer_obstacle_hours' => 0,
            'maternity_hours' => 0,
            'paternity_hours' => 0,
            'parental_hours' => 0,
            'unpaid_leave_hours' => 0,
            'unexcused_hours' => 0,
            'compensatory_time_off_hours' => 0,
        ];
        if (self::importHoursRequiringDates($values) !== []) {
            return array_map(static fn (): null => null, $buckets) + [
                'unworked_hours_occurred' => null,
                'work_obstacles_occurred' => null,
            ];
        }
        foreach (self::IMPORT_DATE_FREE_BLOCKS as $meaning => $block) {
            $millihours = $values[$meaning] ?? 0;
            $buckets[$block . '_hours'] += is_int($millihours) && $millihours > 0 ? $millihours : 0;
        }
        $obstacles = $buckets['employee_obstacle_paid_hours'] + $buckets['employer_obstacle_hours'];
        $buckets['unworked_paid_hours'] = $buckets['vacation_hours'] + $obstacles;
        $buckets['unworked_total_hours'] = $buckets['unworked_paid_hours'];

        return array_map(
            static fn (int $millihours): ?string => $millihours === 0 ? null : self::millihoursSuggestion($millihours),
            $buckets,
        ) + [
            'unworked_hours_occurred' => $buckets['unworked_total_hours'] > 0,
            'work_obstacles_occurred' => $obstacles > 0,
        ];
    }

    /**
     * Významy z importu s kladnými hodinami, ke kterým chybí data nepřítomnosti.
     *
     * @param array<string,mixed> $values
     * @return list<string>
     */
    public static function importHoursRequiringDates(array $values): array
    {
        return array_values(array_filter(
            self::IMPORT_HOURS_REQUIRING_DATES,
            static fn (string $meaning): bool => is_int($values[$meaning] ?? null) && $values[$meaning] > 0,
        ));
    }

    /**
     * Sjednaný fond (10260) u dohody ze souhrnu importu — týž postup jako
     * {@see agreementFundMinutes()}: plán směn, bez jednoznačného rozvrhu
     * odpracovaná doba a dovolená z podkladů (`vacation_hours`).
     *
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $calendars
     * @return array{?string,list<array<string,string>>}
     */
    private static function importAgreementFund(
        array $employment,
        \DateTimeImmutable $period,
        \DateTimeImmutable $periodEnd,
        array $calendars,
        ?int $workedMillihours,
        int $vacationMillihours,
    ): array {
        $fallback = $workedMillihours === null
            ? null
            : self::millihoursSuggestion(self::agreementFallbackFund($workedMillihours, $vacationMillihours));
        [$from, $to] = self::employmentInterval($employment, $period, $periodEnd);
        if ($from === null || $calendars === []) {
            return [$fallback, []];
        }
        [$planned, $issues] = self::agreedFundMinutes($calendars, $from, $to);

        return $issues === [] ? [self::minutesSuggestion($planned), []] : [$fallback, []];
    }

    /**
     * @param array<string,mixed> $employment
     * @return list<array<string,string>>
     */
    private static function employmentIssues(array $employment): array
    {
        return ($employment['term_values_consistent'] ?? false) === true
            ? []
            : [[
                'code' => 'employment_terms_not_unique_for_month',
                'message' => 'Měsíc nemá jedinou konzistentní verzi týdenní pracovní doby.',
            ]];
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @return list<array<string,string>>
     */
    private static function absenceIssues(array $absences): array
    {
        foreach ($absences as $absence) {
            if ($absence['status'] === 'requested'
                || ($absence['correction_pending'] ?? false) === true
            ) {
                return [[
                    'code' => 'absence_not_final',
                    'message' => 'Měsíc obsahuje neuzavřenou absenci nebo čekající opravu.',
                ]];
            }
        }

        return [];
    }

    /** @param list<array<string,mixed>> $entries */
    private static function entriesStartInPeriod(array $entries, string $periodStart): bool
    {
        $utc = new \DateTimeZone('UTC');
        foreach ($entries as $entry) {
            $start = (new \DateTimeImmutable((string) $entry['starts_at_utc'], $utc))
                ->setTimezone(new \DateTimeZone((string) $entry['timezone_name']));
            if ($start->format('Y-m') === substr($periodStart, 0, 7)) {
                return true;
            }
        }

        return false;
    }

    private static function millihoursSuggestion(int $millihours): string
    {
        $whole = intdiv($millihours, 1000);
        $fraction = $millihours % 1000;

        return $fraction === 0
            ? (string) $whole
            : rtrim(sprintf('%d.%03d', $whole, $fraction), '0');
    }

    /**
     * @param list<array<string,mixed>> $absences
     * @return list<string>
     */
    private static function absenceTypes(array $absences): array
    {
        $types = [];
        foreach ($absences as $absence) {
            $type = $absence['absence_type'] ?? null;
            if (is_string($type) && $type !== '') {
                $types[$type] = true;
            }
        }
        $list = array_keys($types);
        sort($list);

        return $list;
    }

    /**
     * Návrh podmíněných bloků 10275–10280 a 10471/10472 z evidovaných absencí.
     *
     * Why: bez návrhu musela účetní osm čísel opsat ručně z evidence, kterou
     * aplikace už má — a když je nechala prázdná a měsíc schválila jako
     * bezabsenční, shodilo to o krok dál celé měsíční hlášení na
     * `jmhz_eldp_work_summary_mismatch`. Jedna dovolená tak zablokovala JMHZ
     * celé firmy.
     *
     * Návrh se jen předvyplní; potvrdit ho musí pořád člověk (potvrzení je
     * `explicit_confirmation` v provenienci). Hodiny počítá
     * {@see PayrollJmhzAbsenceHoursDeriver} ze stejných publikovaných směn,
     * ze kterých vznikla náhrada mzdy.
     *
     * Nula se navrhuje jako `null`, ne jako „0": kontrola 286 hlášení i
     * `validateConditionalValues()` berou vyplněnou nulu jako tvrzení, a
     * ELDP řez s dovolenou vyžaduje ostatní bloky výslovně nevyplněné.
     *
     * Metoda je veřejná a statická záměrně: je to čistý převod minut na
     * desetinné hodiny bez databáze, takže se dá ověřit testem přímo, bez
     * sestavování celého náhledu měsíce.
     *
     * @param array{supported:bool,minutes:array<string,int>,total:int,paid:int} $derived
     * @return array<string,string|bool|null>
     */
    public static function conditionalSuggestions(array $derived): array
    {
        $blocks = [
            'unworked_total_hours' => $derived['total'],
            'unworked_paid_hours' => $derived['paid'],
            'dpn_without_employer_compensation_hours' =>
                $derived['minutes']['dpn_without_employer_compensation'],
            'dpn_with_employer_compensation_hours' =>
                $derived['minutes']['dpn_with_employer_compensation'],
            'vacation_hours' => $derived['minutes']['vacation'],
            'care_hours' => $derived['minutes']['care'],
            'employee_obstacle_paid_hours' => $derived['minutes']['employee_obstacle_paid'],
            'employer_obstacle_hours' => $derived['minutes']['employer_obstacle'],
            'maternity_hours' => $derived['minutes']['maternity'],
            'paternity_hours' => $derived['minutes']['paternity'],
            'parental_hours' => $derived['minutes']['parental'],
            'unpaid_leave_hours' => $derived['minutes']['unpaid_leave'],
            'unexcused_hours' => $derived['minutes']['unexcused'],
            'compensatory_time_off_hours' => $derived['minutes']['compensatory_time_off'],
        ];
        $suggestions = [];
        $expressible = true;
        foreach ($blocks as $field => $minutes) {
            $value = $minutes === 0 ? null : self::minutesSuggestion($minutes);
            $expressible = $expressible && ($minutes === 0 || $value !== null);
            $suggestions[$field] = $value;
        }
        $obstacleMinutes = $derived['minutes']['employee_obstacle_paid']
            + $derived['minutes']['employer_obstacle'];
        /*
         * Nedoložený měsíc zůstává nezodpovězený. `supported = false` znamená
         * druh absence, který hlášení nemá kam zapsat, nebo ještě neschválenou
         * absenci; nevyjádřitelná hodnota znamená minuty, které se na přesnou
         * millihodinu nepřevedou. V obou případech by částečný návrh v součtu
         * 10275 tiše chyběl, takže se nenavrhuje nic.
         */
        if (!$derived['supported'] || !$expressible) {
            return array_map(static fn (): null => null, $suggestions) + [
                'unworked_hours_occurred' => null,
                'work_obstacles_occurred' => null,
            ];
        }

        return $suggestions + [
            'unworked_hours_occurred' => $derived['total'] > 0,
            'work_obstacles_occurred' => $obstacleMinutes > 0,
        ];
    }

    /**
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function confirm(array $preview, array $input, ?string $confirmationKind = null): array
    {
        $version = $preview['derivation_version'] ?? self::DERIVATION_VERSION;
        if (!in_array($version, [self::DERIVATION_VERSION, self::IMPORT_SUMMARY_DERIVATION_VERSION], true)) {
            throw new \InvalidArgumentException('Náhled pracovního souhrnu má nepodporovanou verzi.');
        }
        $importSource = $version === self::IMPORT_SUMMARY_DERIVATION_VERSION;
        if ($confirmationKind !== null
            && ($confirmationKind !== self::IMPORT_BULK_CONFIRMATION || !$importSource)
        ) {
            throw new \InvalidArgumentException(
                'Hromadně lze potvrdit jen pracovní souhrn ze souhrnu importu docházky.',
            );
        }
        $expectedHash = $input['source_snapshot_sha256'] ?? null;
        if (!is_string($expectedHash)
            || preg_match('/^[0-9a-f]{64}$/D', $expectedHash) !== 1
            || !hash_equals((string) $preview['source_snapshot_sha256'], $expectedHash)
        ) {
            throw new PayrollJmhzWorkSummaryConflictException();
        }
        if (($preview['issues'] ?? []) !== []) {
            throw new \InvalidArgumentException(
                'Pracovní souhrn obsahuje neúplné nebo nejednoznačné zdroje.',
            );
        }
        $values = [
            'standard_fund_millihours' => self::scaledDecimal(
                $input['standard_fund_hours'] ?? null,
                'standard_fund_hours',
                3,
                7,
            ),
            'agreed_fund_millihours' => self::scaledDecimal(
                $input['agreed_fund_hours'] ?? null,
                'agreed_fund_hours',
                3,
                7,
            ),
            'weekly_work_centihours' => self::scaledDecimal(
                $input['weekly_work_hours'] ?? null,
                'weekly_work_hours',
                2,
                7,
            ),
            'evidence_days' => self::nonNegativeInt(
                $preview['suggestions']['evidence_days'] ?? null,
                'evidence_days',
            ),
            'worked_millihours' => self::scaledDecimal(
                $input['worked_hours'] ?? null,
                'worked_hours',
                3,
                8,
            ),
            /*
             * Dny (10267) a přesčas (10269) se neberou ze vstupu dialogu, ale
             * z náhledu — stejně jako evidence_days (10265). Jsou to čistá
             * odvození z časových záznamů, které náhled zamkl hashem; kdyby
             * šly přes vstup, dala by se odpracovaná doba popsat jinými dny
             * a jiným přesčasem, než z jakých vznikly hodiny 10268.
             */
            // U souhrnu z importu smí dny zůstat NEUVEDENÉ: podklady je nenesou.
            'worked_days' => $importSource
                ? self::nullableNonNegativeInt($preview['suggestions']['worked_days'] ?? null, 'worked_days')
                : self::nonNegativeInt($preview['suggestions']['worked_days'] ?? null, 'worked_days'),
            /*
             * Přesčas, který nejde vyjádřit na celé millihodiny (minuty
             * nedělitelné třemi), zůstává NEUVEDENÝ. Atribut je nepovinný,
             * takže prázdno je pravdivější než zaokrouhlená hodnota —
             * politika souhrnu je `exact_..._without_rounding`.
             */
            'overtime_millihours' => self::nullableScaledDecimal(
                $preview['suggestions']['overtime_hours'] ?? null,
                'overtime_hours',
            ),
        ];
        self::assertScaledMaximum(
            $values['standard_fund_millihours'],
            9999999,
            'standard_fund_hours',
        );
        self::assertScaledMaximum(
            $values['agreed_fund_millihours'],
            9999999,
            'agreed_fund_hours',
        );
        self::assertScaledMaximum(
            $values['weekly_work_centihours'],
            9999999,
            'weekly_work_hours',
        );
        self::assertScaledMaximum(
            $values['worked_millihours'],
            99999999,
            'worked_hours',
        );
        if (self::weeklyWorkMissingValue($preview['relation_type'] ?? null)
            && $values['weekly_work_centihours'] !== 9900
        ) {
            throw new \InvalidArgumentException(
                'U dohody a člena orgánu se stanovená týdenní pracovní doba'
                    . ' neuvádí; podle pokynů MPSV k 10261 patří do hlášení'
                    . ' hodnota 99.',
            );
        }
        /*
         * Přesčas je částí odpracovaných hodin (10269 je rozpad 10268), takže
         * ho nesmí přerůst. Hodiny 10268 jde v dialogu přepsat, dny a přesčas
         * ne — bez téhle kontroly by snížené hodiny tiše popřely rozpad.
         */
        if ($values['overtime_millihours'] !== null
            && $values['overtime_millihours'] > $values['worked_millihours']
        ) {
            throw new \InvalidArgumentException(
                'Přesčasové hodiny nesmí překročit odpracované hodiny.',
            );
        }
        $unworkedHoursOccurred = self::strictBool(
            $input['unworked_hours_occurred'] ?? null,
            'unworked_hours_occurred',
        );
        $workObstaclesOccurred = self::strictBool(
            $input['work_obstacles_occurred'] ?? null,
            'work_obstacles_occurred',
        );
        $conditionalValues = [
            'unworked_total_millihours' => self::nullableScaledDecimal(
                $input['unworked_total_hours'] ?? null,
                'unworked_total_hours',
            ),
            'unworked_paid_millihours' => self::nullableScaledDecimal(
                $input['unworked_paid_hours'] ?? null,
                'unworked_paid_hours',
            ),
            'dpn_without_employer_compensation_millihours' => self::nullableScaledDecimal(
                $input['dpn_without_employer_compensation_hours'] ?? null,
                'dpn_without_employer_compensation_hours',
            ),
            'dpn_with_employer_compensation_millihours' => self::nullableScaledDecimal(
                $input['dpn_with_employer_compensation_hours'] ?? null,
                'dpn_with_employer_compensation_hours',
            ),
            'vacation_millihours' => self::nullableScaledDecimal(
                $input['vacation_hours'] ?? null,
                'vacation_hours',
            ),
            'care_millihours' => self::nullableScaledDecimal(
                $input['care_hours'] ?? null,
                'care_hours',
            ),
            'employee_obstacle_paid_millihours' => self::nullableScaledDecimal(
                $input['employee_obstacle_paid_hours'] ?? null,
                'employee_obstacle_paid_hours',
            ),
            'employer_obstacle_millihours' => self::nullableScaledDecimal(
                $input['employer_obstacle_hours'] ?? null,
                'employer_obstacle_hours',
            ),
            'maternity_millihours' => self::nullableScaledDecimal(
                $input['maternity_hours'] ?? null,
                'maternity_hours',
            ),
            'paternity_millihours' => self::nullableScaledDecimal(
                $input['paternity_hours'] ?? null,
                'paternity_hours',
            ),
            'parental_millihours' => self::nullableScaledDecimal(
                $input['parental_hours'] ?? null,
                'parental_hours',
            ),
            'unpaid_leave_millihours' => self::nullableScaledDecimal(
                $input['unpaid_leave_hours'] ?? null,
                'unpaid_leave_hours',
            ),
            'unexcused_millihours' => self::nullableScaledDecimal(
                $input['unexcused_hours'] ?? null,
                'unexcused_hours',
            ),
            'compensatory_time_off_millihours' => self::nullableScaledDecimal(
                $input['compensatory_time_off_hours'] ?? null,
                'compensatory_time_off_hours',
            ),
        ];
        self::validateConditionalValues(
            $unworkedHoursOccurred,
            $workObstaclesOccurred,
            $conditionalValues,
            $values['agreed_fund_millihours'],
        );
        /*
         * Svátky v jinak pracovní dny (HOLIDAY_FIELDS) nezadává účetní, jsou
         * odvozené z kalendáře (resp. z podkladů importu) a zamčené hashem
         * zdroje. Do úhrnů 10275 a 10276 se přičtou až tady, takže dialog
         * i hromadné schválení posílají neodpracované hodiny jen za
         * nepřítomnosti a měsíc jen se svátkem se schválí stejně jako dřív —
         * interakce IN07 vznikne sama.
         */
        $holiday = $preview['holiday_millihours'] ?? 0;
        if (!is_int($holiday) || $holiday < 0) {
            throw new \InvalidArgumentException(
                'Hodiny svátků v jinak pracovní dny nejde vyjádřit v celých tisícinách hodiny.',
            );
        }
        $values += $conditionalValues;
        $values['holiday_millihours'] = $holiday > 0 ? $holiday : null;
        if ($holiday > 0) {
            $values['unworked_total_millihours'] = ($values['unworked_total_millihours'] ?? 0) + $holiday;
            $values['unworked_paid_millihours'] = ($values['unworked_paid_millihours'] ?? 0) + $holiday;
            $unworkedHoursOccurred = true;
            self::assertScaledMaximum($values['unworked_total_millihours'], 99999999, 'unworked_total_hours');
        }
        // Svátek uvnitř nepřítomnosti bez mzdy jen do 10275 (UNPAID_HOLIDAY_FIELDS).
        // Nese ho jen verze z intervalů v9; souhrn z importu svátky dodává sám.
        $unpaidHoliday = 0;
        if (in_array($version, self::VERSIONS_WITH_UNPAID_HOLIDAYS, true)) {
            $unpaidHoliday = $preview['holiday_unpaid_millihours'] ?? 0;
            if (!is_int($unpaidHoliday) || $unpaidHoliday < 0) {
                throw new \InvalidArgumentException(
                    'Hodiny svátků v jinak pracovní dny nejde vyjádřit v celých tisícinách hodiny.',
                );
            }
            $values['holiday_unpaid_millihours'] = $unpaidHoliday > 0 ? $unpaidHoliday : null;
            if ($unpaidHoliday > 0) {
                $values['unworked_total_millihours'] = ($values['unworked_total_millihours'] ?? 0) + $unpaidHoliday;
                $unworkedHoursOccurred = true;
                self::assertScaledMaximum($values['unworked_total_millihours'], 99999999, 'unworked_total_hours');
            }
        }
        $note = $input['confirmation_note'] ?? '';
        if (!is_string($note) || mb_strlen(trim($note)) > 500) {
            throw new \InvalidArgumentException(
                'Volitelná poznámka k potvrzeným hodnotám smí mít nejvýše 500 znaků.',
            );
        }
        $note = trim($note);
        $provenance = [
            'attributes' => [
                '10259' => 'explicit_confirmation',
                '10260' => 'explicit_confirmation_with_calendar_suggestion',
                '10261' => 'explicit_confirmation_with_term_suggestion',
                '10265' => 'employment_interval_derivation',
                '10267' => match (true) {
                    !$importSource => 'time_entry_derivation',
                    $values['worked_days'] === null => 'not_provided_by_import',
                    default => 'import_summary',
                },
                '10268' => $importSource
                    ? 'explicit_confirmation_with_import_summary_suggestion'
                    : 'explicit_confirmation_with_time_entry_suggestion',
                '10269' => match (true) {
                    $importSource && $values['overtime_millihours'] === null => 'not_provided_by_import',
                    $importSource => 'import_summary',
                    $values['overtime_millihours'] === null => 'not_expressible_in_millihours',
                    default => 'time_entry_derivation',
                },
                '10275' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10276' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10277' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10278' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10279' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10280' => $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
                '10471' => $workObstaclesOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN08',
                '10472' => $workObstaclesOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN08',
            ],
            /*
             * Hodiny bez atributu hlášení. Klíč není ID atributu datového
             * slovníku, protože žádné nemají — ČSSZ se hlásí po dnech
             * v evidenčním listu. Provenience to říká výslovně, aby se
             * nepletly s bloky 10275–10280.
             */
            'local_evidence' => array_fill_keys(
                [...self::LOCAL_EVIDENCE_FIELDS, ...self::COMPENSATORY_TIME_OFF_FIELDS],
                $unworkedHoursOccurred
                    ? 'explicit_confirmation'
                    : 'not_applicable_by_IN07',
            ),
            // Svátky se nepotvrzují v dialogu; jsou odvozené a přičtené
            // k úhrnům 10275 a 10276 (viz HOLIDAY_FIELDS).
            'holidays' => $holiday > 0
                ? ($importSource ? 'import_summary_added_to_10275_10276' : 'work_calendar_added_to_10275_10276')
                : 'none',
            'suggestions' => $preview['suggestions'],
            'source_contains_absences' =>
                (bool) ($preview['requires_unworked_hours_followup'] ?? false),
            'decimal_policy' => 'exact_user_confirmed_value_without_rounding',
            'validated_controls' => [23, 144, 145, 286],
        ];
        if (in_array($version, self::VERSIONS_WITH_UNPAID_HOLIDAYS, true)) {
            $provenance['holidays_unpaid'] = $unpaidHoliday > 0 ? 'work_calendar_added_to_10275' : 'none';
        }
        if ($importSource) {
            /*
             * Hromadné schválení dávky nepotvrzuje nikdo po jednom poli; hodnoty
             * jsou návrhy náhledu převzaté beze změny. Provenience to musí říct,
             * jinak by souhrn tvrdil výslovné potvrzení účetní, které nebylo.
             */
            if ($confirmationKind === self::IMPORT_BULK_CONFIRMATION) {
                foreach (['attributes', 'local_evidence'] as $group) {
                    foreach ($provenance[$group] as $attribute => $origin) {
                        if (str_starts_with((string) $origin, 'explicit_confirmation')) {
                            $provenance[$group][$attribute] = self::IMPORT_BULK_CONFIRMATION;
                        }
                    }
                }
            }
            $provenance['confirmation_kind'] = $confirmationKind ?? 'explicit_confirmation';
            $provenance['work_source'] = 'import_summary';
        }
        $summaryPayload = [
            'derivation_version' => $version,
            'specification' => self::specification(),
            'source_snapshot_sha256' => $preview['source_snapshot_sha256'],
            'conditional_blocks_confirmed' => true,
            'interactions' => [
                'IN07' => $unworkedHoursOccurred,
                'IN08' => $workObstaclesOccurred,
            ],
            'values' => $values,
            'provenance' => $provenance,
            'confirmation_note' => $note,
        ];

        return $summaryPayload + [
            'source_snapshot_json' => $preview['source_snapshot_json'],
            'summary_sha256' => hash('sha256', CanonicalJson::encode($summaryPayload)),
        ];
    }

    /** @return array<string,mixed> */
    private function employment(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources,
    ): array
    {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.supplier_id, employment.relation_type,
                    employment.start_date, employment.actual_start_date, employment.end_date,
                    terms.id AS term_id, terms.row_version AS term_row_version,
                    terms.effective_from AS term_effective_from,
                    terms.effective_to AS term_effective_to, terms.weekly_hours,
                    terms.workload_basis_points
               FROM payroll_employments employment
               LEFT JOIN payroll_employment_terms terms
                 ON terms.supplier_id = employment.supplier_id
                AND terms.employment_id = employment.id
                AND terms.effective_from <= LAST_DAY(?)
                AND (terms.effective_to IS NULL OR terms.effective_to >= ?)
              WHERE employment.supplier_id = ? AND employment.id = ?
              ORDER BY terms.effective_from, terms.id' . $lock
        );
        $periodEnd = (new \DateTimeImmutable($periodStart))
            ->modify('first day of next month')
            ->modify('-1 day')
            ->format('Y-m-d');
        $stmt->execute([$periodEnd, $periodStart, $supplierId, $employmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }
        $termVersions = [];
        $weeklyHours = [];
        $statedWeeklyHours = [];
        foreach ($rows as $termRow) {
            if ($termRow['term_id'] === null) {
                continue;
            }
            $term = [
                'id' => (int) $termRow['term_id'],
                'row_version' => (int) $termRow['term_row_version'],
                'effective_from' => (string) $termRow['term_effective_from'],
                'effective_to' => $termRow['term_effective_to'],
                'weekly_hours' => $termRow['weekly_hours'] === null
                    ? null
                    : (string) $termRow['weekly_hours'],
                'workload_basis_points' => $termRow['workload_basis_points'] === null
                    ? null
                    : (int) $termRow['workload_basis_points'],
            ];
            $termVersions[] = $term;
            $weeklyHours[json_encode($term['weekly_hours'], JSON_THROW_ON_ERROR)] =
                $term['weekly_hours'];
            $stated = self::statedWeeklyHours($term['weekly_hours'], $term['workload_basis_points']);
            $statedWeeklyHours[json_encode($stated, JSON_THROW_ON_ERROR)] = $stated;
        }
        $termValuesConsistent = count($weeklyHours) <= 1;
        return [
            'id' => (int) $row['id'],
            'supplier_id' => (int) $row['supplier_id'],
            'relation_type' => (string) $row['relation_type'],
            'start_date' => $row['start_date'],
            'actual_start_date' => $row['actual_start_date'],
            'end_date' => $row['end_date'],
            'term_id' => count($termVersions) === 1 ? $termVersions[0]['id'] : null,
            'term_row_version' => count($termVersions) === 1
                ? $termVersions[0]['row_version']
                : null,
            'weekly_hours' => $termVersions !== [] && $termValuesConsistent
                ? $termVersions[0]['weekly_hours']
                : null,
            'stated_weekly_hours' => $termVersions !== [] && $termValuesConsistent && count($statedWeeklyHours) === 1
                ? array_values($statedWeeklyHours)[0]
                : null,
            'term_values_consistent' => $termValuesConsistent,
            'term_versions' => $termVersions,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function calendars(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        bool $lockSources,
    ): array {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, timezone_name, schedule_type, week_pattern,
                    weekly_minutes, valid_from, valid_to, row_version
               FROM payroll_work_calendars
              WHERE supplier_id = ? AND employment_id = ?
                AND valid_from < ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from, id' . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $calendars = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $calendarId = (int) $row['id'];
            $dayStmt = $this->db->pdo()->prepare(
                'SELECT day_date, day_kind, planned_minutes, holiday_code, holiday_name, note, row_version
                   FROM payroll_calendar_days
                  WHERE supplier_id = ? AND calendar_id = ?
                    AND day_date >= ? AND day_date < ? ORDER BY day_date, id' . $lock
            );
            $dayStmt->execute([$supplierId, $calendarId, $periodStart, $periodEnd]);
            $days = [];
            foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) as $day) {
                $day['planned_minutes'] = (int) $day['planned_minutes'];
                $day['row_version'] = (int) $day['row_version'];
                $days[] = $day;
            }
            $pattern = json_decode((string) $row['week_pattern'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($pattern)) {
                throw new \UnexpectedValueException('Týdenní vzor kalendáře je neplatný.');
            }
            $row['id'] = $calendarId;
            $row['weekly_minutes'] = (int) $row['weekly_minutes'];
            $row['row_version'] = (int) $row['row_version'];
            $row['week_pattern'] = $pattern;
            $row['days'] = $days;
            $calendars[] = $row;
        }
        return $calendars;
    }

    /** @return list<array<string,mixed>> */
    private function entries(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        bool $lockSources,
    ): array {
        [$from, $to] = self::utcBounds($periodStart);
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, series_key, revision_no, category, starts_at_utc, ends_at_utc,
                    timezone_name, break_minutes, source_kind, source_reference,
                    LOWER(HEX(source_hash)) AS source_sha256
              FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id = ? AND status <> 'superseded'
                AND ends_at_utc > ? AND starts_at_utc < ?
              ORDER BY starts_at_utc, id" . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $from, $to]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['revision_no'] = (int) $row['revision_no'];
            $row['break_minutes'] = (int) $row['break_minutes'];
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function absences(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $periodEnd,
        bool $lockSources,
    ): array {
        $lock = $lockSources ? ' FOR UPDATE' : '';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, absence_type, date_from, date_to, timezone_name,
                    partial_first_minutes, partial_last_minutes, compensation_policy,
                    compensation_rate_basis_points, average_snapshot_id, support_status,
                    status, correction_pending, row_version
               FROM payroll_absences
              WHERE supplier_id = ? AND employment_id = ?
                AND status IN ('requested','approved')
                AND date_from < ? AND date_to >= ? ORDER BY date_from, id" . $lock
        );
        $stmt->execute([$supplierId, $employmentId, $periodEnd, $periodStart]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ([
                'id', 'partial_first_minutes', 'partial_last_minutes',
                'compensation_rate_basis_points', 'average_snapshot_id', 'row_version',
            ] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            $row['correction_pending'] = (bool) $row['correction_pending'];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Interval vztahu v měsíci (z něj se počítají fondy 10259/10260) a počet
     * dní v evidenčním stavu (10265), který je o mateřskou, rodičovskou
     * a otcovskou kratší, viz {@see PayrollJmhzEvidenceStateDays}.
     *
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $absences
     * @return array{?\DateTimeImmutable,?\DateTimeImmutable,int}
     */
    private static function evidenceInterval(
        array $employment,
        \DateTimeImmutable $period,
        \DateTimeImmutable $periodEnd,
        array $absences,
    ): array {
        if (self::isAgreement($employment['relation_type'])) {
            return [null, null, 0];
        }
        [$from, $to] = self::employmentInterval($employment, $period, $periodEnd);
        if ($from === null || $to === null) {
            return [null, null, 0];
        }

        return [
            $from,
            $to,
            PayrollJmhzEvidenceStateDays::days(
                $employment['relation_type'],
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                $absences,
            ),
        ];
    }

    private static function isAgreement(mixed $relationType): bool
    {
        return in_array($relationType, ['dpp', 'dpc'], true);
    }

    /**
     * Průnik trvání vztahu s měsícem, bez ohledu na evidenční stav.
     *
     * @param array<string,mixed> $employment
     * @return array{?\DateTimeImmutable,?\DateTimeImmutable,int}
     */
    private static function employmentInterval(
        array $employment,
        \DateTimeImmutable $period,
        \DateTimeImmutable $periodEnd,
    ): array {
        $startRaw = $employment['actual_start_date'] ?? $employment['start_date'];
        if (!is_string($startRaw) || $startRaw === '') {
            return [null, null, 0];
        }
        $start = max(new \DateTimeImmutable($startRaw), $period);
        $end = $periodEnd->modify('-1 day');
        if (is_string($employment['end_date']) && $employment['end_date'] !== '') {
            $end = min($end, new \DateTimeImmutable($employment['end_date']));
        }
        if ($end < $start) {
            return [null, null, 0];
        }
        return [$start, $end, $start->diff($end)->days + 1];
    }

    /**
     * Sjednaný fond (10260) u dohody.
     *
     * Pokyny MPSV: „v případě zaměstnanců pracujících na základě dohody
     * o pracích konaných mimo pracovní poměr se uvede předpokládaný rozsah
     * pracovní doby v příslušném měsíci včetně plánované dovolené". Dohoda
     * nemá evidenční interval (10265 = 0), takže se plán směn sčítá přes
     * trvání vztahu v měsíci. Bez jednoznačného rozvrhu je nejlepším
     * předpokladem odpracovaná doba a k ní vyčerpaná dovolená — rozsah se
     * podle Pokynů uvádí „včetně plánované dovolené", takže dohoda s dovolenou
     * nesmí mít fond menší o dobu dovolené ({@see agreementFallbackFund()}).
     * Návrh potvrzuje účetní jako každý jiný a dohoda bez rozvrhu kvůli němu
     * nesmí zůstat neschválitelná.
     *
     * @param array<string,mixed> $employment
     * @param list<array<string,mixed>> $calendars
     * @param array{minutes:int,days:int,overtime_minutes:int} $worked
     * @return array{int,list<array<string,string>>}
     */
    private static function agreementFundMinutes(
        array $employment,
        \DateTimeImmutable $period,
        \DateTimeImmutable $periodEnd,
        array $calendars,
        array $worked,
        int $vacationMinutes,
    ): array {
        $fallback = self::agreementFallbackFund($worked['minutes'], $vacationMinutes);
        [$from, $to] = self::employmentInterval($employment, $period, $periodEnd);
        if ($from === null || $calendars === []) {
            return [$fallback, []];
        }
        [$planned, $issues] = self::agreedFundMinutes($calendars, $from, $to);

        return $issues === [] ? [$planned, []] : [$fallback, []];
    }

    /**
     * Sjednaný fond dohody bez rozvrhu: odpracovaná doba a dovolená.
     *
     * Pokyny MH 1.4.14 kap. 3.2.7 k 10260: „V případě zaměstnanců pracujících
     * na základě dohody o pracích konaných mimo pracovní poměr se uvede
     * předpokládaný rozsah pracovní doby v příslušném měsíci včetně plánované
     * dovolené." Jediné místo pro souhrn z intervalů i ze souhrnu importu
     * (jednotky jsou na volajícím: minuty, nebo tisíciny hodiny).
     */
    public static function agreementFallbackFund(int $worked, int $vacation): int
    {
        return $worked + max(0, $vacation);
    }

    /**
     * @param list<array<string,mixed>> $calendars
     * @return array{int,list<array<string,string>>}
     */
    private static function agreedFundMinutes(
        array $calendars,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): array {
        if ($from === null || $to === null) {
            return [0, []];
        }
        $minutes = 0;
        $issues = [];
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $iso = $date->format('Y-m-d');
            $matching = array_values(array_filter(
                $calendars,
                static fn (array $calendar): bool => $calendar['valid_from'] <= $iso
                    && ($calendar['valid_to'] === null || $calendar['valid_to'] >= $iso),
            ));
            if (count($matching) !== 1) {
                $issues[] = [
                    'code' => 'calendar_day_not_uniquely_covered',
                    'message' => "Datum {$iso} není pokryto právě jedním pracovním kalendářem.",
                ];
                continue;
            }
            $calendar = $matching[0];
            $overrides = [];
            foreach ($calendar['days'] as $day) {
                $overrides[$day['day_date']] = $day;
            }
            $override = $overrides[$iso] ?? null;
            $weekday = $date->format('N');
            $planned = (int) ($calendar['week_pattern'][$weekday]
                ?? $calendar['week_pattern'][(int) $weekday]
                ?? 0);
            if (is_array($override) && $override['day_kind'] !== 'holiday') {
                $planned = (int) $override['planned_minutes'];
            }
            $minutes += $planned;
        }
        return [$minutes, $issues];
    }

    /**
     * Minuty svátků v jinak pracovní dny, které zaměstnanec neodpracoval
     * a za které se mzda nekrátí nebo náleží náhrada (viz HOLIDAY_FIELDS).
     *
     * Rozsah i plán dne jsou tytéž jako u sjednaného fondu 10260
     * ({@see agreedFundMinutes()}): interval vztahu v měsíci a týdenní vzor
     * kalendáře. Svátek je státní svátek podle {@see CzechHolidayCalendar}
     * nebo den kalendáře výslovně označený jako svátek; den kalendáře
     * označený jinak svátkem není. Den se vynechá, když na něj připadá
     * odpracovaná doba, nemoc (nese ho ve svém bloku) nebo nepřítomnost, kvůli
     * které se mzda za svátek krátí (§ 115 odst. 3 ZP, týž výklad jako krácení
     * mzdy: {@see PayrollWageReplacementTitle::holidayCutsMonthlyWage()}).
     * Dovolená svátek nečerpá (§ 219 odst. 1 ZP) a u placené překážky se mzda
     * za svátek nekrátí, takže svátek v nich do 10275/10276 patří.
     *
     * Svátek uvnitř nepřítomnosti, kvůli které se mzda za svátek krátí, vrací
     * zvlášť jako druhou hodnotu: je neodpracovaná hodina (10275), ale bez náhrady
     * (10276 ne), viz UNPAID_HOLIDAY_FIELDS.
     *
     * @param list<array<string,mixed>> $calendars
     * @param list<array<string,mixed>> $entries
     * @param list<array<string,mixed>> $absences
     * @return array{0:int,1:int} svátky s náhradou nebo bez krácení, svátky bez mzdy
     */
    private function holidayMinutes(
        array $calendars,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        array $entries,
        array $absences,
        string $periodStart,
    ): array {
        if ($from === null || $to === null) {
            return [0, 0];
        }
        $publicHolidays = [];
        foreach ($this->fund->month(substr($periodStart, 0, 7), [])['days'] as $day) {
            if ($day['is_holiday'] === true) {
                $publicHolidays[$day['date']] = true;
            }
        }
        $workedDates = [];
        foreach ($entries as $entry) {
            if (!in_array($entry['category'] ?? null, ['regular', 'overtime'], true)) {
                continue;
            }
            $start = (new \DateTimeImmutable((string) $entry['starts_at_utc'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone((string) $entry['timezone_name']));
            $workedDates[$start->format('Y-m-d')] = true;
        }
        $minutes = 0;
        $unpaid = 0;
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $iso = $date->format('Y-m-d');
            if (isset($workedDates[$iso])) {
                continue;
            }
            $matching = array_values(array_filter(
                $calendars,
                static fn (array $calendar): bool => $calendar['valid_from'] <= $iso
                    && ($calendar['valid_to'] === null || $calendar['valid_to'] >= $iso),
            ));
            if (count($matching) !== 1) {
                continue;
            }
            $override = null;
            foreach ($matching[0]['days'] as $day) {
                if ($day['day_date'] === $iso) {
                    $override = $day;
                }
            }
            $isHoliday = is_array($override)
                ? $override['day_kind'] === 'holiday'
                : isset($publicHolidays[$iso]);
            if (!$isHoliday) {
                continue;
            }
            $weekday = $date->format('N');
            $planned = (int) ($matching[0]['week_pattern'][$weekday]
                ?? $matching[0]['week_pattern'][(int) $weekday]
                ?? 0);
            foreach ($absences as $absence) {
                $type = (string) ($absence['absence_type'] ?? '');
                if ((string) $absence['date_from'] > $iso || (string) $absence['date_to'] < $iso) {
                    continue;
                }
                if (in_array($type, ['dpn', 'quarantine'], true)) {
                    // Svátek uvnitř nemoci nese hodinový blok nemoci.
                    continue 2;
                }
                if (PayrollWageReplacementTitle::holidayCutsMonthlyWage($type)) {
                    // Mzda za svátek se neposkytuje: neodpracovaná hodina bez náhrady
                    // (10275 ano, 10276 ne).
                    $unpaid += $planned;
                    continue 2;
                }
            }
            $minutes += $planned;
        }

        return [$minutes, $unpaid];
    }

    private static function minutesToMillihours(int $minutes): ?int
    {
        return ($minutes * 1000) % 60 === 0 ? intdiv($minutes * 1000, 60) : null;
    }

    private static function period(string $periodStart): \DateTimeImmutable
    {
        $period = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($period === false
            || $period->format('Y-m-d') !== $periodStart
            || $period->format('d') !== '01'
        ) {
            throw new \InvalidArgumentException('period_start musí být první den měsíce.');
        }
        return $period;
    }

    /** @return array{string,string} */
    private static function utcBounds(string $periodStart): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($periodStart, $utc);
        $end = $start->modify('first day of next month');
        return [
            $start->modify('-1 day')->format('Y-m-d H:i:s'),
            $end->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    private static function minutesSuggestion(int $minutes): ?string
    {
        $millihoursNumerator = $minutes * 1000;
        if ($millihoursNumerator % 60 !== 0) {
            return null;
        }
        $millihours = intdiv($millihoursNumerator, 60);
        $whole = intdiv($millihours, 1000);
        $fraction = $millihours % 1000;
        return $fraction === 0
            ? (string) $whole
            : rtrim(sprintf('%d.%03d', $whole, $fraction), '0');
    }

    /**
     * Stanovená týdenní doba (10261) se sbírá pro povinný podíl OZP a jen
     * u zaměstnanců v pracovním poměru. Kdo se do podílu nezapočítává, má
     * podle pokynů MPSV „missingovou hodnotu 99" — vzorové příklady ji tak
     * uvádějí u dohod a přijatá hlášení jiných systémů i u jednatele.
     * Zaměstnání malého rozsahu je pracovní poměr, tam patří skutečná doba.
     */
    /**
     * Stanovená týdenní pracovní doba (10261) z podmínek vztahu.
     *
     * 10261 je doba podle § 79 zákoníku práce, tedy plná doba zaměstnavatele pro
     * daný režim — ne kratší pracovní doba sjednaná podle § 80. Zaměstnanec na
     * poloviční úvazek u zaměstnavatele se 40 h týdně má 10261 = 40, jeho
     * kratší doba se projeví jen ve sjednaném fondu 10260. Stejně to čte import
     * přijatých hlášení ({@see \MyInvoice\Service\Payroll\Import\Jmhz\JmhzReportForm::workload()}),
     * který sjednanou týdenní dobu dopočítává jako 10261 × 10260 / 10259.
     *
     * Podmínky vedou sjednanou týdenní dobu a úvazek (podíl sjednané a stanovené
     * doby), stanovená je tedy sjednaná / úvazek. Plný úvazek vrací sjednanou
     * dobu beze změny — dřívější chování pro vztahy bez vyplněného úvazku.
     */
    public static function statedWeeklyHours(?string $weeklyHours, ?int $workloadBasisPoints): ?string
    {
        if ($weeklyHours === null || !is_numeric($weeklyHours)) {
            return $weeklyHours;
        }
        if ($workloadBasisPoints === null || $workloadBasisPoints <= 0 || $workloadBasisPoints >= 10_000) {
            return $weeklyHours;
        }
        $centihours = (int) round((float) $weeklyHours * 100);
        $stated = (int) round($centihours * 10_000 / $workloadBasisPoints);
        $whole = intdiv($stated, 100);
        $fraction = $stated % 100;

        return $fraction === 0
            ? (string) $whole
            : rtrim(sprintf('%d.%02d', $whole, $fraction), '0');
    }

    private static function weeklyWorkMissingValue(mixed $relationType): bool
    {
        return in_array(
            $relationType,
            ['dpp', 'dpc', 'partner_dependent', 'statutory_body'],
            true,
        );
    }

    private static function requiresShiftCalendar(string $relationType): bool
    {
        return !in_array(
            $relationType,
            ['partner_dependent', 'statutory_body'],
            true,
        );
    }

    private static function scaledDecimal(
        mixed $value,
        string $field,
        int $scale,
        int $totalDigits,
    ): int {
        if (!is_string($value)
            || preg_match('/^(0|[1-9]\d*)(?:\.(\d+))?$/D', trim($value), $matches) !== 1
        ) {
            throw new \InvalidArgumentException("{$field} musí být nezáporné desetinné číslo.");
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale) {
            throw new \InvalidArgumentException("{$field} smí mít nejvýše {$scale} desetinná místa.");
        }
        $digits = ltrim($matches[1], '0') . $fraction;
        if (strlen(ltrim($digits, '0')) > $totalDigits) {
            throw new \InvalidArgumentException("{$field} překračuje rozsah JMHZ.");
        }
        return ((int) $matches[1] * (10 ** $scale))
            + (int) str_pad($fraction, $scale, '0');
    }

    private static function nullableScaledDecimal(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }
        $scaled = self::scaledDecimal($value, $field, 3, 8);
        if ($scaled > 99999999) {
            throw new \InvalidArgumentException("{$field} překračuje podporovaný měsíční rozsah.");
        }
        return $scaled;
    }

    private static function assertScaledMaximum(int $value, int $maximum, string $field): void
    {
        if ($value > $maximum) {
            throw new \InvalidArgumentException(
                "{$field} překračuje podporovaný měsíční rozsah.",
            );
        }
    }

    private static function strictBool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$field} musí být výslovně ano nebo ne.");
        }
        return $value;
    }

    /**
     * @param array<string,?int> $values
     */
    private static function validateConditionalValues(
        bool $unworkedHoursOccurred,
        bool $workObstaclesOccurred,
        array $values,
        int $agreedFund,
    ): void {
        $unworkedFields = [
            'unworked_total_millihours',
            'unworked_paid_millihours',
            'dpn_without_employer_compensation_millihours',
            'dpn_with_employer_compensation_millihours',
            'vacation_millihours',
            'care_millihours',
        ];
        /*
         * Hodiny bez atributu hlášení visí na téže interakci IN07 jako bloky
         * 10275–10280: jsou to taky neodpracované hodiny a vstupují do úhrnu
         * 10275. Bez IN07 tedy nesmí být vyplněné ani ony, jinak by úhrn
         * a rozpad tvrdily každý něco jiného.
         */
        $unworkedFields = array_merge(
            $unworkedFields,
            self::LOCAL_EVIDENCE_FIELDS,
            self::COMPENSATORY_TIME_OFF_FIELDS,
        );
        if (!$unworkedHoursOccurred) {
            foreach ($unworkedFields as $field) {
                if ($values[$field] !== null) {
                    throw new \InvalidArgumentException(
                        'Hodnoty 10275–10280 nelze uvést, pokud interakce IN07 nenastala.',
                    );
                }
            }
        }
        $total = $values['unworked_total_millihours'];
        if ($unworkedHoursOccurred && ($total === null || $total <= 0)) {
            throw new \InvalidArgumentException(
                'Při aktivní IN07 musí být celkové neodpracované hodiny kladné.',
            );
        }
        if ($values['unworked_paid_millihours'] !== null
            && $values['vacation_millihours'] !== null
            && $values['unworked_paid_millihours'] < $values['vacation_millihours']
        ) {
            throw new \InvalidArgumentException(
                'Placené neodpracované hodiny 10276 nesmí být nižší než dovolená 10279.',
            );
        }
        $obstacleFields = [
            'employee_obstacle_paid_millihours',
            'employer_obstacle_millihours',
        ];
        if (!$workObstaclesOccurred) {
            foreach ($obstacleFields as $field) {
                if ($values[$field] !== null) {
                    throw new \InvalidArgumentException(
                        'Hodnoty 10471/10472 nelze uvést, pokud interakce IN08 nenastala.',
                    );
                }
            }
            return;
        }
        if (!$unworkedHoursOccurred) {
            throw new \InvalidArgumentException('Interakce IN08 vyžaduje aktivní IN07.');
        }
        if ($values['employee_obstacle_paid_millihours'] === null
            && $values['employer_obstacle_millihours'] === null
        ) {
            throw new \InvalidArgumentException(
                'Při aktivní IN08 musí být uveden alespoň jeden atribut 10471/10472.',
            );
        }
        foreach ($obstacleFields as $field) {
            $value = $values[$field];
            if ($value !== null && $value > $agreedFund) {
                throw new \InvalidArgumentException(
                    'Hodiny překážek nesmí překročit sjednaný fond 10260.',
                );
            }
        }
    }

    private static function nonNegativeInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        throw new \InvalidArgumentException("{$field} musí být nezáporné celé číslo.");
    }

    private static function nullableNonNegativeInt(mixed $value, string $field): ?int
    {
        return $value === null ? null : self::nonNegativeInt($value, $field);
    }

    /** @return array<string,string> */
    private static function specification(): array
    {
        return [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
    }
}
