<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Import\Jmhz;

/**
 * Jeden formulář osoby (`formularOsoby`) z měsíčního hlášení JMHZ tak, jak ho
 * přečetl {@see JmhzReportReader}. Nic se tu nedomýšlí: částky jsou celé koruny
 * přesně podle souboru, chybějící element je `null`.
 *
 * Tvary polí:
 *  - `advance`: `{base:?int, computed:?int, after_credits:?int, bonus:?int}` (10297, 10298, 10305, 10306),
 *  - `withholding`: `{base:?int, tax:?int}` (10307, 10309),
 *  - `credits`: druh slevy evidence (`taxpayer`, `disability-basic`, …) => částka nároku (10299–10302),
 *  - `childCredit`: `{monthly:?int, applied:?int, other_caregiver:?bool, caregivers:list<Person>, children:list<Child>}`,
 *    kde Person = `{given_name:string, family_name:string, birth_date:?string, birth_number:?string}`
 *    a Child = Person + `{ztp_p:bool, order:string}` (10435–10440),
 *  - `workplace`: `{city:string, municipality_code:string, country_code:string}` (10229–10231),
 *  - `fund`: `{standard:string, agreed:string, weekly:string}` (10259–10261, desetinný zápis),
 *  - `eldp`: `{code:?string, insurance_days:int, excluded_days:int}` — kód první sekce ELDP
 *    a součty dnů přes sekce (10240, 10356); vyloučené dny jsou úhrn 10357, a když ho
 *    program nevyplnil, součet podpoložek (nemoc, PPM, ošetřovné, otcovská, omluvená
 *    nepřítomnost, dávky — 10358–10536, 10473–10475); `null`, když formulář seznam ELDP nemá.
 *    `sickness_excluded_days` jsou vyloučené dny § 18 odst. 7 zák. č. 187/2006 Sb. (10366,
 *    jinak 10473 + 10474 + 10475), `null` když je hlášení nevydalo; `absence_days` dny nemoci,
 *    PPM, ošetřovného a výplaty dávek po prvcích (10358–10360, 10474, 10475),
 *  - `uninsuredIncome`: příjem včetně nepojištěné činnosti (10476).
 *
 * Údaje z bloků, které měkký režim čtení smí přejít (pojištění do, pojistné, čistá mzda,
 * zdravotní pojištění, neodpracované hodiny, ELDP), se čtou tolerantně: nečitelná hodnota
 * je `null`. Spolehnout se na ně jde jen u souboru, který prošel XSD
 * ({@see JmhzReportFile::$lenient}).
 */
final readonly class JmhzReportForm
{
    public const VARIANTS = [
        'bezPriznaku',
        'pestoun',
        'cinnostKS',
        'vezen',
        'mezinarodniPronajemSily',
        'jinyPrijem',
        'ozpTpp',
        'odlozenyPrijem',
    ];

    /**
     * @param array{base:?int,computed:?int,after_credits:?int,bonus:?int}|null $advance
     * @param array{base:?int,tax:?int}|null $withholding
     * @param array<string,int> $credits
     * @param array<string,mixed>|null $childCredit
     * @param array{city:string,municipality_code:string,country_code:string}|null $workplace
     * @param array{standard:string,agreed:string,weekly:string}|null $fund
     */
    public function __construct(
        public int $position,
        public string $formGuid,
        public string $formType,
        public ?bool $primary,
        public ?string $variant,
        public ?string $personIdentifier = null,
        public ?string $employmentIdentifier = null,
        public ?string $lastName = null,
        public ?string $firstName = null,
        public ?string $birthDate = null,
        public ?string $startDate = null,
        public ?string $activityCode = null,
        public bool $hasSummary = false,
        public ?int $incomeTotal = null,
        public ?array $advance = null,
        public ?array $withholding = null,
        public ?bool $declarationSigned = null,
        public array $credits = [],
        public ?array $childCredit = null,
        public ?int $socialBase = null,
        public ?bool $socialDiscount = null,
        public ?bool $orchardDiscount = null,
        public bool $hasPosition = false,
        public ?array $workplace = null,
        public ?bool $apz = null,
        public ?string $apzInstrument = null,
        public ?bool $functionalBenefits = null,
        public ?bool $temporaryAssignment = null,
        public ?array $fund = null,
        public ?int $evidenceDays = null,
        public ?int $workedDays = null,
        public ?int $workedMillihours = null,
        public ?int $taxableIncome = null,
        public ?int $wage = null,
        public ?int $irregularBonuses = null,
        public ?int $standbyPay = null,
        public ?int $averageHourlyMilli = null,
        public ?string $insuranceFrom = null,
        public ?string $insuranceTo = null,
        public ?array $eldp = null,
        public ?int $employeeSocial = null,
        public ?int $employerSocial = null,
        public ?int $employeeHealth = null,
        public ?int $employerHealth = null,
        public ?int $netWage = null,
        public ?bool $deductionsRecorded = null,
        public ?int $tariff = null,
        public ?int $unworkedMillihours = null,
        public ?int $leaveMillihours = null,
        /** Neodpracované hodiny pro nemoc (s náhradou i bez ní) a OČR — snižují tarif měsíční mzdy. */
        public ?int $absenceMillihours = null,
        /** Příjem z nepojištěné činnosti (10476) — dohoda nebo malý rozsah pod rozhodným příjmem. */
        public ?int $uninsuredIncome = null,
        /**
         * Sleva na pojistném zaměstnavatele (10372, § 7a zák. č. 589/1992 Sb.): příznak, který
         * předchozí program vykázal. Čte se tolerantně, blok měkký režim smí přejít.
         */
        public ?bool $employerDiscount = null,
        /**
         * Důvody uplatnění slevy zaměstnavatele (`slevaZamestnavateleRozpad/duvodUplatneni`,
         * kód skupiny osob § 7a odst. 2), jak je předchozí program vykázal.
         *
         * @var list<string>
         */
        public array $employerDiscountReasons = [],
        /**
         * Příspěvky zaměstnavatele z osvobozených příjmů (`prispevekZamestnavatele`, jen
         * formulář se souhrnnými daty): penzijní připojištění 10292, doplňkové penzijní spoření
         * 10293, penzijní pojištění 10294, soukromé životní pojištění 10295, DIP 10296 a pojištění
         * dlouhodobé péče 10418, vše v Kč. `null` = blok chybí. Čte se tolerantně.
         *
         * @var array{pension_supplementary:int,supplementary_savings:int,pension_insurance:int,life_insurance:int,dip:int,long_term_care:int}|null
         */
        public ?array $employerContributions = null,
    ) {}

    /**
     * Příspěvek na produkty spoření na stáří (10292 až 10296) v Kč: čerpání koše § 6 odst. 9
     * písm. m) ZDP. Pojištění dlouhodobé péče (10418) do něj nepatří, má vlastní posouzení.
     */
    public function oldAgeSavingsContribution(): ?int
    {
        if ($this->employerContributions === null) {
            return null;
        }
        $c = $this->employerContributions;

        return $c['pension_supplementary'] + $c['supplementary_savings'] + $c['pension_insurance'] + $c['life_insurance'] + $c['dip'];
    }

    /**
     * Klíč pracovního vztahu v dávce: ID PPV, u větve B (osoba ještě bez OIČ)
     * jméno s datem narození. Bez obojího formulář ke vztahu přiřadit nejde.
     */
    public function relationKey(): ?string
    {
        return self::relationKeyOf($this->employmentIdentifier, $this->lastName, $this->firstName, $this->birthDate);
    }

    public static function relationKeyOf(
        ?string $employmentIdentifier,
        ?string $lastName,
        ?string $firstName,
        ?string $birthDate,
    ): ?string {
        if ($employmentIdentifier !== null) {
            return 'ppv:' . $employmentIdentifier;
        }
        if ($lastName === null || $firstName === null || $birthDate === null) {
            return null;
        }

        return 'person:' . mb_strtolower($lastName . '|' . $firstName) . '|' . $birthDate;
    }

    /**
     * Úvazek podle fondu pracovní doby: sjednaný fond / stanovený fond (10260/10259)
     * a z něj týdenní pracovní doba ze stanovené týdenní doby (10261). `null`, když
     * měsíc úvazek nedokládá (stanovený fond 0 při celoměsíční nepřítomnosti,
     * sjednaný vyšší než stanovený, chybějící údaj, dohoda s „missingovou"
     * týdenní dobou 99).
     *
     * @return array{workload_basis_points:int,weekly_hours:string}|null
     */
    public function workload(): ?array
    {
        if ($this->fund === null || JmhzEmploymentHistory::weeklyMissing($this->fund['weekly'])) {
            return null;
        }
        $standard = self::milli($this->fund['standard']);
        $agreed = self::milli($this->fund['agreed']);
        $weekly = self::milli($this->fund['weekly']);
        if ($standard === null || $agreed === null || $weekly === null
            || $standard <= 0 || $agreed <= 0 || $weekly <= 0 || $agreed > $standard
        ) {
            return null;
        }
        $basisPoints = (int) round($agreed * 10_000 / $standard);
        $weeklyCenti = (int) round($weekly * $agreed / $standard / 10);

        return [
            'workload_basis_points' => $basisPoints,
            'weekly_hours' => sprintf('%d.%02d', intdiv($weeklyCenti, 100), $weeklyCenti % 100),
        ];
    }

    /** Hodiny v tisícinách; desetinný zápis s tečkou, nejvýš tři místa. */
    private static function milli(string $value): ?int
    {
        if (preg_match('/^(\d{1,6})(?:\.(\d{1,3}))?$/D', trim($value), $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 1000 + (int) str_pad($match[2] ?? '', 3, '0');
    }

    public function hasBody(): bool
    {
        return $this->variant !== null;
    }

    /** Větev A `identifikaceType`: OIČ i ID PPV. */
    public function hasIdentifierBranch(): bool
    {
        return $this->personIdentifier !== null && $this->employmentIdentifier !== null;
    }

    public function fullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name === '' ? null : $name;
    }

    /** Průměrný hodinový výdělek (10345) v haléřích, zaokrouhlený půl nahoru. */
    public function averageHourlyMinor(): ?int
    {
        return $this->averageHourlyMilli === null ? null : intdiv($this->averageHourlyMilli + 5, 10);
    }

    public function workedHoursText(): ?string
    {
        if ($this->workedMillihours === null) {
            return null;
        }

        return intdiv($this->workedMillihours, 1000) . '.'
            . str_pad((string) ($this->workedMillihours % 1000), 3, '0', STR_PAD_LEFT);
    }
}
