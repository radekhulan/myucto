<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

/**
 * Kanonická podoba PRACOVNÍHO VZTAHU převzatého z předchozího mzdového systému.
 *
 * Protějšek {@see PayrollTakeoverPerson}: čtečka zdroje sem přeloží, co o vztahu ví,
 * a zápis ({@see PayrollTakeoverEmploymentWriter}, {@see PayrollTakeoverAbsenceWriter})
 * doplní do MyÚčta jen to, co tam chybí. Prázdné pole zápis přeskočí.
 */
final readonly class PayrollTakeoverEmployment
{
    /**
     * @param string $personalNumber osobní číslo; pod ním se vztah hledá a hlásí v protokolu
     * @param string $relationKey klíč vztahu ve zdroji (párovací mapa srovnávací sestavy)
     * @param list<array{from:string,amount:float,prorated:bool}> $monthlyWages sjednaná měsíční mzda po verzích
     * @param bool $hourlyWage vztah má v převáděných měsících hodinovou nebo úkolovou mzdu
     * @param list<string> $regularBenefits pravidelná plnění, která převod nezakládá (jen do protokolu)
     * @param list<array{year:int,quarter:int,hourly:float,from:string,to:string,gross:float,worked:float,days:float}> $averages
     *        průměrné výdělky čtvrtletí, se kterými počítal zdroj
     * @param list<array{type:string,from:string,to:string,childbirth:?string,lone_carer?:bool}> $absences nepřítomnosti s daty
     *        (`lone_carer`: ošetřovné osamělého zaměstnance)
     * @param int $absencesWithoutDates nepřítomnosti, které evidence vede s daty a zdroj je nemá
     * @param array{year:int,balance_hours:float,balance_days:?float,taken_hours:float,daily_hours:?float,from_days:bool}|null $leave
     *        zůstatek dovolené ke dni převodu
     * @param bool $leaveShared zůstatek nejde přiřadit vztahu (souběžné vztahy osoby)
     * @param string $transferStart první převáděný měsíc (`YYYY-MM`); od něj platí zůstatek dovolené
     * @param array{work_place:string,municipality_code:string,country_code:string,regular_workplace:?string}|null $workplace pracoviště JMHZ
     * @param array<string,string> $checklistNotes položka Zákonných termínů => poznámka dokladu
     *        ze zdroje; položka bez dokladu chybí a zůstane otevřená
     * @param list<array{period:string,minutes:int}> $leaveTaken čerpání dovolené po měsících před
     *        zahájením vedení mezd v MyÚčtu, pro zdroj, který nenese zůstatek (`$leave`), jen čerpání
     * @param list<string> $followUps úkoly na vztahu, které převzetí zakládá, protože zdroj
     *        něco dokládá jen příznakem ({@see PayrollTakeoverEmploymentWriter::followUps()})
     * @param list<array{code:string,name:string,kind:string,amount:float,from:string,to:?string}> $recurringComponents
     *        pravidelná zdanitelná plnění pevnou částkou (osobní ohodnocení…), která zdroj vede na
     *        kartě vztahu a která trvají na konci převáděného období; zápis z nich udělá opakovanou
     *        složku pro měsíce počítané MyÚčtem ({@see PayrollTakeoverEmploymentWriter::recurringComponents()})
     */
    public function __construct(
        public string $personalNumber,
        public string $relationKey,
        public ?string $start = null,
        public ?string $end = null,
        public array $monthlyWages = [],
        public bool $hourlyWage = false,
        public array $regularBenefits = [],
        public array $averages = [],
        public array $absences = [],
        public int $absencesWithoutDates = 0,
        public ?array $leave = null,
        public bool $leaveShared = false,
        public string $transferStart = '',
        public ?array $workplace = null,
        public ?string $czIsco = null,
        public ?string $oic = null,
        public ?string $idPpv = null,
        public array $checklistNotes = [],
        public array $leaveTaken = [],
        public array $followUps = [],
        public array $recurringComponents = [],
    ) {}
}
