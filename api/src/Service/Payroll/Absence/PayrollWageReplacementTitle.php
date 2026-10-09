<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

/**
 * Titul, kterým je nahrazena doba vyňatá ze základní měsíční mzdy.
 *
 * Do aritmetiky krácení nevstupuje — ta se zajímá jen o součet minut. Nese ale
 * důkaz, PROČ doba ze základní mzdy vypadla, a to je přesně to, co musí jít
 * doložit z mzdového listu (§ 142 odst. 5 ZP).
 *
 * Rozpad je JINÝ než u {@see \MyInvoice\Service\Payroll\Time\PayrollJmhzAbsenceHoursDeriver},
 * a záměrně. Měsíční hlášení potřebuje pro každý druh absence vlastní vykazovaný
 * atribut, a proto se u druhu bez atributu zastaví a nenavrhne nic. Krácení mzdy
 * se ptá na něco jiného a jednodušší: zůstává tahle doba v základní mzdě? U KAŽDÉ
 * evidované absence je odpověď „ne", protože mzda přísluší za vykonanou práci
 * (§ 109 odst. 1 ZP). Sdílená je proto mechanika hodin
 * ({@see \MyInvoice\Repository\Payroll\PayrollAbsenceRepository::publishedShiftSegments()}),
 * ne seznam podporovaných druhů.
 */
enum PayrollWageReplacementTitle: string
{
    /** § 222 ZP — náhrada mzdy ve výši průměrného výdělku. */
    case Vacation = 'vacation';

    /** § 192 ZP — náhrada mzdy poskytovaná zaměstnavatelem v okně DPN. */
    case SicknessCompensation = 'sickness_compensation';

    /**
     * Dávka nemocenského pojištění: nemoc za oknem § 192, ošetřovné,
     * dlouhodobé ošetřovné, peněžitá pomoc v mateřství, otcovská a rodičovská.
     * Zaměstnavatel za tu dobu neplatí nic, ale mzda za ni stejně nepřísluší.
     */
    case StateBenefit = 'state_benefit';

    /** § 199 a § 208 ZP — jiná placená překážka v práci. */
    case PaidObstacle = 'paid_obstacle';

    /**
     * Doba, za kterou mzda ani náhrada nepřísluší: neplacené volno, náhradní
     * volno za přesčas (§ 114 odst. 1 ZP — přesčas se už zaplatil dosaženou
     * mzdou, volnem se nahrazuje jen příplatek), neomluvené zameškání,
     * pracovní volno bez náhrady mzdy (výkon veřejné funkce, neplacená
     * překážka na straně zaměstnance) a nerozlišené „jiné".
     */
    case Unpaid = 'unpaid';

    /**
     * Druh absence → titul náhrady. `dpn` a `quarantine` se dělí oknem § 192
     * na dvě části a nemají tu proto jednoznačnou hodnotu.
     */
    public static function forAbsenceType(string $absenceType): ?self
    {
        return match ($absenceType) {
            'vacation' => self::Vacation,
            'ocr', 'long_term_care', 'ppm', 'paternity', 'parental' => self::StateBenefit,
            'employee_obstacle', 'employer_obstacle' => self::PaidObstacle,
            'unpaid_leave', 'compensatory_time_off', 'unexcused', 'other',
            'public_function', 'employee_obstacle_unpaid', 'invalid_termination' => self::Unpaid,
            default => null,
        };
    }

    /**
     * Krátí se měsíční mzda za svátek, který leží uvnitř celodenní nepřítomnosti
     * tohoto druhu?
     *
     * § 115 odst. 3 ZP chrání jen zaměstnance, který nepracoval PROTO, že svátek
     * připadl na jeho obvyklý pracovní den. Kdo byl ten den nepřítomen z jiného
     * důvodu, za který mu mzda ani náhrada od zaměstnavatele nepřísluší (dávka
     * nemocenského pojištění, rodičovská, pracovní volno bez náhrady mzdy,
     * neomluvená absence), nepracoval kvůli té nepřítomnosti a mzda se mu za
     * svátek krátí stejně jako za ostatní dny.
     *
     * Dovolená svátek nečerpá (§ 219 odst. 1 ZP), placená překážka za svátek
     * náhradu nedává, ale mzda se nekrátí, a náhradní volno za přesčas
     * (§ 114 odst. 1 ZP) ani doba po neplatném rozvázání (§ 69 ZP) se ve
     * svátek nečerpají — u nich svátek v mzdě zůstává. Nemoc se dělí oknem
     * § 192 ZP a řeší ji volající: v okně svátek platí náhrada, za oknem a bez
     * nároku na nemocenské se krátí jako tady.
     *
     * Jediné místo pro krácení mzdy i pro svátek v hodinách měsíčního hlášení
     * (10275/10276), aby mzda a hlášení stály na témž výkladu.
     */
    public static function holidayCutsMonthlyWage(string $absenceType): bool
    {
        return match ($absenceType) {
            'ocr', 'long_term_care', 'ppm', 'paternity', 'parental',
            'unpaid_leave', 'unexcused', 'other',
            'public_function', 'employee_obstacle_unpaid' => true,
            default => false,
        };
    }

    /**
     * Zacházení se svátkem uvnitř absence. Musí být DOSLOVA to, které při
     * schválení absence rozhodlo o penězích — jinak by se krátilo za jiné
     * hodiny, než za které se náhrada vyplatila.
     */
    public static function holidayTreatment(string $absenceType): AbsenceHolidayTreatment
    {
        return match ($absenceType) {
            'vacation' => AbsenceHolidayTreatment::ExcludeFromLeave,
            'dpn', 'quarantine' => AbsenceHolidayTreatment::CompensateSickness,
            default => AbsenceHolidayTreatment::Ignore,
        };
    }
}
