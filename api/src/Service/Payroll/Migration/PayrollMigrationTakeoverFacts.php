<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Service\Migration\Pohoda\PohodaXml;

/**
 * Nepeněžní část převzatého měsíce: doby, druh vztahu a platba.
 *
 * ── Proč zvlášť od {@see PayrollMigrationReferenceTotals} ───────────────────
 * Úhrny jsou PENÍZE a slouží kontrolní sestavě. Tohle jsou veličiny, které
 * kontrolní sestava neporovnává, ale bez kterých nejde za rok přechodu sestavit
 * evidenční list důchodového pojištění ani zpětně zaevidovat platby. Oddělením
 * zůstane konstruktor úhrnů čitelný a nová veličina se přidává na jedno místo.
 *
 * ── Odkud se vzal výběr položek ─────────────────────────────────────────────
 * Ne z přání, ale z toho, co obě navazující strany reálně čtou:
 *
 *  - `EldpAnnualStatementBuilder::requiredMonths()` bere rok a trvání vztahu
 *    ⇒ {@see self::$relationshipStartDate} a {@see self::$relationshipEndDate}.
 *  - `EldpAnnualStatementBuilder::monthLine()` blokuje vše, co není
 *    `relation_type === 'employment'` s druhem činnosti 1–9
 *    ⇒ {@see self::$relationType} a {@see self::$activityCode}.
 *  - Tentýž `monthLine()` skládá `insurance_days` a `excluded_days`, a podle
 *    § 11 odst. 2 zákona č. 155/1995 Sb. dává měsíci bez účasti nula dnů
 *    ⇒ {@see self::$insuranceDays}, {@see self::$excludedDays},
 *    {@see self::$pensionParticipation}.
 *  - `payroll_payment_liabilities` eviduje závazek jako částku se splatností
 *    v členění `liability_kind` (`net_wage`, `deduction`, …)
 *    ⇒ {@see self::$deductionsMinor}, {@see self::$netPayableMinor},
 *    {@see self::$payoutDate}.
 *
 * Odpracovaná doba není ani v jednom z nich povinná, ale bez ní se převzatý
 * měsíc nedá zkontrolovat proti docházce a u dohod je to jediná měřitelná
 * veličina ⇒ {@see self::$workedDaysHundredths}, {@see self::$workedMinutes}.
 *
 * ── Jednotky ────────────────────────────────────────────────────────────────
 * Peníze v haléřích, dny v setinách dne, hodiny v minutách. Vše celá čísla:
 * půlden odpracované doby je běžný a jako float by se rozpadl.
 */
final readonly class PayrollMigrationTakeoverFacts
{
    public function __construct(
        /** `YYYY-MM-DD` nebo `null`, když původní systém nástup nevydal. */
        public ?string $relationshipStartDate = null,
        /** `YYYY-MM-DD` nebo `null` u trvajícího vztahu. */
        public ?string $relationshipEndDate = null,
        /** Hodnoty `payroll_employments.relation_type`; `null` = neurčeno. */
        public ?string $relationType = null,
        /** Druh činnosti ČSSZ jako `payroll_employment_terms.activity_code`. */
        public ?string $activityCode = null,
        /** Účast na důchodovém pojištění v měsíci; `false` = měsíc není dobou pojištění. */
        public bool $pensionParticipation = true,
        public int $insuranceDays = 0,
        public int $excludedDays = 0,
        public int $workedDaysHundredths = 0,
        public int $workedMinutes = 0,
        public int $deductionsMinor = 0,
        public int $netPayableMinor = 0,
        /** Výplatní termín původního systému, `YYYY-MM-DD`. */
        public ?string $payoutDate = null,
        /**
         * Vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb. (rozhodné období
         * nemocenských dávek). Jiná veličina než `$excludedDays` (§ 16 odst. 4
         * zák. č. 155/1995 Sb.); `null` = zdroj ji nevydal.
         */
        public ?int $sicknessExcludedDays = null,
        /** Příjem včetně nepojištěné činnosti (JMHZ 10476) v haléřích; `null` = zdroj ho nevydal. */
        public ?int $uninsuredIncomeMinor = null,
    ) {
        foreach ([
            'relationship_start_date' => $relationshipStartDate,
            'relationship_end_date' => $relationshipEndDate,
            'payout_date' => $payoutDate,
        ] as $field => $value) {
            if ($value !== null && !self::isDate($value)) {
                throw new \InvalidArgumentException("Pole {$field} musí být datum ve tvaru YYYY-MM-DD.");
            }
        }
        if ($relationshipStartDate !== null && $relationshipEndDate !== null
            && $relationshipEndDate < $relationshipStartDate
        ) {
            throw new \InvalidArgumentException(
                'Skončení pracovního vztahu nesmí předcházet jeho vzniku.',
            );
        }
        if ($relationType !== null && trim($relationType) === '') {
            throw new \InvalidArgumentException('Druh pracovního vztahu nesmí být prázdný řetězec.');
        }
        if ($activityCode !== null && trim($activityCode) === '') {
            throw new \InvalidArgumentException('Druh činnosti nesmí být prázdný řetězec.');
        }
        // Horní mez 31 je táž jako CHECK v migraci 1851: víc dnů v kalendářním
        // měsíci není, a číslo nad ní je vždycky chyba přepisu, ne extrém.
        if ($insuranceDays < 0 || $insuranceDays > 31) {
            throw new \InvalidArgumentException('Dny účasti na pojištění musí být 0 až 31.');
        }
        if ($excludedDays < 0 || $excludedDays > 31) {
            throw new \InvalidArgumentException('Vyloučené doby musí být 0 až 31.');
        }
        if ($sicknessExcludedDays !== null && ($sicknessExcludedDays < 0 || $sicknessExcludedDays > 31)) {
            throw new \InvalidArgumentException('Vyloučené dny podle § 18 odst. 7 musí být 0 až 31.');
        }
        if ($uninsuredIncomeMinor !== null && $uninsuredIncomeMinor < 0) {
            throw new \InvalidArgumentException('Příjem z nepojištěné činnosti nesmí být záporný.');
        }
        if ($workedDaysHundredths < 0 || $workedMinutes < 0) {
            throw new \InvalidArgumentException('Odpracovaná doba nesmí být záporná.');
        }
        if ($deductionsMinor < 0) {
            throw new \InvalidArgumentException('Srážky ze mzdy nesmí být záporné.');
        }
        // Rovnost `net - srážky = k výplatě` se schválně NEVYNUCUJE: v reálném
        // exportu neplatí kvůli naturálnímu plnění, které se zdaní, ale nevyplácí.
        if (!$pensionParticipation && $insuranceDays !== 0) {
            throw new \InvalidArgumentException(
                'Měsíc bez účasti na důchodovém pojištění nemůže mít dny pojištění '
                    . '(§ 11 odst. 2 zákona č. 155/1995 Sb.).',
            );
        }
    }

    /**
     * Doby a platba z jednoho záznamu `MZ` exportu `91_mzdy.xml`.
     *
     * Mapování ověřené nad reálným exportem:
     *  - `JeSocPP` je účast na pojištění v daném vztahu; `JeDuchPP` NE — to je
     *    příznak poživatele důchodu a u účastnícího se zaměstnance je 0.
     *  - `NahrDobyDP` jsou náhradní (vyloučené) doby pro důchodové pojištění,
     *    `NahrDoby` je širší veličina včetně dob mimo § 16 odst. 4.
     *  - `KcCistaM` je čistá PŘED srážkami (uložená jako `net_minor`),
     *    `KcVyuct` je částka po srážkách, `KcSrazky` srážky. `KcVyplat` je něco
     *    třetího (čistá snížená o naturální plnění) a nepoužívá se.
     *  - `Datum` je výplatní termín měsíce, `DatZauct` datum zaúčtování.
     *
     * Druh vztahu ani druh činnosti se z `MZ` NEODVOZUJÍ: `RelDruhM` je vlastní
     * číselník PAMICA, ne kód ČSSZ, a jeho ztotožnění by vyrobilo nedoložený
     * kód ELDP. Předává je volající, který zná napárovaný pracovní vztah.
     *
     * @param array<string,mixed> $mz
     */
    public static function fromPohodaMz(
        array $mz,
        ?string $relationType = null,
        ?string $activityCode = null,
    ): self {
        $participates = PohodaXml::num($mz, 'JeSocPP') > 0.0;
        $calendarDays = (int) PohodaXml::num($mz, 'DnyKal');

        return new self(
            relationshipStartDate: PohodaXml::date($mz, 'DatNast')
                ?? PohodaXml::date($mz, 'DatVstup'),
            relationshipEndDate: PohodaXml::date($mz, 'DatOdch'),
            relationType: $relationType,
            activityCode: $activityCode,
            pensionParticipation: $participates,
            // Dny účasti se v `MZ` samostatně nevykazují. Kalendářní dny měsíce
            // v záznamu už jsou zkrácené trváním vztahu, takže při účasti jsou
            // dobou pojištění; bez účasti je měsíc nula dnů. Navazující sestavení
            // ELDP si je stejně ověřuje proti datům trvání vztahu.
            insuranceDays: $participates ? max(0, min($calendarDays, 31)) : 0,
            excludedDays: max(0, min((int) round(PohodaXml::num($mz, 'NahrDobyDP')), 31)),
            workedDaysHundredths: max(0, (int) round(PohodaXml::num($mz, 'DnyOdpra') * 100)),
            workedMinutes: max(0, (int) round(PohodaXml::num($mz, 'HodOdpra') * 60)),
            deductionsMinor: max(0, (int) round(PohodaXml::num($mz, 'KcSrazky') * 100)),
            netPayableMinor: (int) round(PohodaXml::num($mz, 'KcVyuct') * 100),
            payoutDate: PohodaXml::date($mz, 'Datum'),
            // `NahrDoby` jsou vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb.: ověřeno na všech
            // 157 měsících rozhodných období, která PAMICA podala v NEMPRI (`NEMPRIpol.VyldnyR*`),
            // s nemocí, ošetřovným i celými měsíci mateřské a rodičovské. `NahrDobyDP`
            // (vyloučené doby důchodového pojištění) je užší a rodičovskou nenese.
            sicknessExcludedDays: $mz === [] || PohodaXml::text($mz, 'NahrDoby') === ''
                ? null
                : max(0, min((int) round(PohodaXml::num($mz, 'NahrDoby')), 31)),
        );
    }

    /**
     * Doby a platba z měsíce, který zdrojová čtečka už přeložila do obecných veličin
     * (PREMIER). Odpracované dny a peníze jdou v přirozených jednotkách (dny, Kč) a na
     * celá čísla je převádí tahle metoda; ostatní jsou už celá čísla.
     *
     * @param array{pension_participation:bool,insurance_days:int,excluded_days:int,worked_days:float|int,
     *     worked_minutes:int,deductions:float|int,net_payable:float|int,payout_date?:?string} $month
     */
    public static function fromMonth(
        array $month,
        ?string $relationshipStartDate = null,
        ?string $relationshipEndDate = null,
        ?string $relationType = null,
        ?string $activityCode = null,
    ): self {
        return new self(
            relationshipStartDate: $relationshipStartDate,
            relationshipEndDate: $relationshipEndDate,
            relationType: $relationType,
            activityCode: $activityCode,
            pensionParticipation: $month['pension_participation'],
            insuranceDays: $month['insurance_days'],
            excludedDays: $month['excluded_days'],
            workedDaysHundredths: (int) round($month['worked_days'] * 100),
            workedMinutes: $month['worked_minutes'],
            deductionsMinor: (int) round($month['deductions'] * 100),
            netPayableMinor: (int) round($month['net_payable'] * 100),
            payoutDate: $month['payout_date'] ?? null,
        );
    }

    /** @return array<string,mixed> tvar sloupců `payroll_migration_reference_totals` */
    public function toColumns(): array
    {
        return [
            'relationship_start_date' => $this->relationshipStartDate,
            'relationship_end_date' => $this->relationshipEndDate,
            'relation_type' => $this->relationType,
            'activity_code' => $this->activityCode,
            'pension_participation' => $this->pensionParticipation ? 1 : 0,
            'insurance_days' => $this->insuranceDays,
            'excluded_days' => $this->excludedDays,
            'worked_days_hundredths' => $this->workedDaysHundredths,
            'worked_minutes' => $this->workedMinutes,
            'deductions_minor' => $this->deductionsMinor,
            'net_payable_minor' => $this->netPayableMinor,
            'payout_date' => $this->payoutDate,
            'sickness_excluded_days' => $this->sicknessExcludedDays,
            'uninsured_income_minor' => $this->uninsuredIncomeMinor,
        ];
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
