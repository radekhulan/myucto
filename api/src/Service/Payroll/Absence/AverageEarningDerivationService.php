<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Absence;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Document\AverageEarningsMonthlyMath;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverReader;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder;
use MyInvoice\Service\Payroll\Time\PayrollWorkedTimeSource;
use PDO;

/**
 * Odvození vstupů průměrného výdělku ze zmrazených mzdových běhů.
 *
 * Účetní dosud opisovala tři čísla, která aplikace už má: započitatelnou mzdu,
 * odpracovanou dobu a odpracované dny za rozhodné čtvrtletí. Průměr je přitom
 * povinný pro náhradu za dovolenou, pro náhradu při DPN i pro JMHZ (atribut
 * 10345), takže se opisovalo každé čtvrtletí u každého, kdo něco čerpal.
 *
 * Služba jen NAVRHUJE. Nic neukládá, průměr nepočítá (to je
 * {@see AverageEarningCalculator}) a schválení nenahrazuje — potvrdit čísla
 * musí pořád člověk, stejně jako u automatického nároku na dovolenou
 * ({@see AutomaticLeaveEntitlementService}), podle kterého je postavená.
 *
 * ── Odkud se která hodnota bere ─────────────────────────────────────────────
 *
 *  * **Započitatelná mzda** (`gross_earnings_minor`) — součet
 *    `totals.average_earning_base_minor` ze zmrazeného výsledku běhu
 *    (`payroll_run_employments.result_json`) za všechny tři měsíce rozhodného
 *    období. Tohle číslo NEODHADUJE tahle služba: vyrábí ho
 *    {@see \MyInvoice\Service\Payroll\Run\PayrollRunCalculator} z
 *    `average_earning_treatment` KAŽDÉ mzdové složky, a to z klasifikace
 *    zmrazené ve `payroll_inputs.component_snapshot_json` v okamžiku schválení
 *    vstupu. Do základu tedy vstupuje jen to, co je označené `included`
 *    (mzda, odměna, provize, zákonné příplatky § 114 až § 118, doplatek mzdy);
 *    náhrada mzdy, náhrada při DPN, odstupné, benefity, nepeněžní příjem
 *    ani cestovní náhrady tam nejsou. Složka s klasifikací `manual_review`
 *    výpočet běhu shodí ({@see
 *    \MyInvoice\Service\Payroll\Component\PayrollComponentDefinition::impact()}),
 *    takže SCHVÁLENÁ revize je sama o sobě důkazem, že v období nezůstala
 *    složka, kterou aplikace neumí zařadit.
 *
 *  * **Odpracovaná doba** (`worked_minutes`) — potvrzené
 *    `values.worked_millihours` z pracovního souhrnu JMHZ zmrazeného v
 *    `payroll_run_employments.input_json` (`time_month.jmhz_work_summary`).
 *    Je to TÁŽ hodnota, kterou účetní odklepla při schválení docházky a která
 *    odchází do měsíčního hlášení ČSSZ — a protože byla zmrazená do běhu, je
 *    to i doba, ze které se počítala mzda za daný měsíc.
 *
 *  * **Odpracované dny** (`worked_days`) — počet různých místních kalendářních
 *    dnů s odpracovaným intervalem, spočítaný ze zmrazeného
 *    `source_snapshot_json.time_entries` téhož souhrnu. Ten seznam je zdroj,
 *    ze kterého vznikly potvrzené hodiny; služba ho projde stejným postupem
 *    jako {@see \MyInvoice\Service\Payroll\Time\PayrollJmhzWorkMonthSummaryBuilder}
 *    (kategorie `regular` a `overtime`, minus přestávka) a MUSÍ dojít na
 *    minutu ke stejnému číslu jako potvrzené hodiny. Když nedojde, návrh
 *    nevznikne — dny by pak stály na jiné evidenci než hodiny.
 *
 * ── Co se NEODVOZUJE ────────────────────────────────────────────────────────
 *
 *  * **Poměrná část mzdy za delší období než čtvrtletí (§ 358 ZP)** —
 *    `longer_period_allocated_minor` zůstává vždy `null`. Aplikace u mzdové
 *    složky nevede údaj „za jaké období se poskytuje"; `frequency_kind`
 *    rozlišuje jen pravidelnou a jednorázovou složku, a jednorázový je i
 *    příplatek za noční práci. Roční prémii tedy od měsíční odměny nerozezná
 *    nic. Číslo proto zadává účetní a formulář to u pole říká.
 *
 *  * **Pravděpodobný výdělek (§ 355 ZP)** — když v rozhodném období nebyl
 *    odpracován zákonný minimální počet dnů (nováček, dlouhá nemoc), stanoví
 *    se průměr jinou úvahou, kterou z evidence odvodit nejde: „Jestliže
 *    zaměstnanec v rozhodném období neodpracoval alespoň 21 dnů, použije se
 *    pravděpodobný výdělek" (§ 355 odst. 1 zákona č. 262/2006 Sb.), a ten se
 *    podle odst. 2 stanoví z hrubé mzdy, které by zaměstnanec zřejmě dosáhl,
 *    s přihlédnutím k obvyklé výši složek mzdy nebo k odměně srovnatelných
 *    zaměstnanců. Metodika MPSV, příručka pro personální agendu, kap. XXI.8:
 *    https://ppropo.mpsv.cz/xxi8prumernyvydelek.
 *
 *    Vymyslet takové číslo aplikace nesmí, ale OPSAT ho umí: účetní ho zadává
 *    do podmínek pracovního vztahu (`payroll_employment_terms.probable_hourly_earning_minor`
 *    plus povinné odůvodnění), kde se zmrazuje do revize podmínek stejně jako
 *    sjednaná mzda. Když je zadané, návrh z něj vznikne se `source_kind` =
 *    `probable`; když zadané není, vrátí se blokátor
 *    `probable_earning_not_recorded`, který účetní pošle na kartu vztahu.
 *
 *    Doslovné znění § 355 odst. 2 (zákon č. 262/2006 Sb., znění účinné od
 *    29. 8. 2026): „Pravděpodobný výdělek zjistí zaměstnavatel z hrubé mzdy
 *    nebo platu, které zaměstnanec dosáhl od počátku rozhodného období,
 *    popřípadě z hrubé mzdy nebo platu, které by zřejmě dosáhl; přitom se
 *    přihlédne zejména k obvyklé výši jednotlivých složek mzdy nebo platu
 *    zaměstnance …". Bez zadané hodnoty proto aplikace NAVRHNE číslo
 *    z evidence, kterou už má, v tomhle pořadí:
 *
 *      1. hodnota zadaná v podmínkách vztahu (rozhodnutí účetní má přednost),
 *      2. hrubá mzda dosažená od počátku rozhodného období — započitatelná
 *         mzda a odpracovaná doba z uzavřených běhů od vzniku zaměstnání,
 *         tedy i z měsíců čtvrtletí, pro které se průměr zjišťuje (věta
 *         první: „od počátku", ne „v" rozhodném období),
 *      3. sjednaná měsíční mzda z podmínek vztahu přepočtená na hodinu
 *         koeficientem § 356 odst. 2 (věta druhá: obvyklá výše složek mzdy),
 *      4. spodní mez, hodinová minimální mzda (§ 357 odst. 1) — dohoda bez
 *         sjednané měsíční mzdy v prvním měsíci, měsíc bez příjmu.
 *
 *    Odvozuje se JEN u nového vztahu: každý měsíc rozhodného období bez běhu
 *    musí celý ležet před vznikem zaměstnání. Chybějící běh uprostřed trvání
 *    vztahu je vadná evidence a číslo odjinud by ji zakrylo. Návrh zůstává
 *    návrhem — průměr z něj vznikne až potvrzením účetní.
 *
 *    Substituce platí JEN pro důvody, na které § 355 míří — chybějící běh,
 *    málo odpracovaných dnů, nulová odpracovaná doba
 *    ({@see PROBABLE_ELIGIBLE_BLOCKERS}). Vadná evidence (dva běhy za měsíc,
 *    neschválená docházka, rozejité hodiny) se pravděpodobným výdělkem
 *    nepřebíjí — tam se má opravit evidence, ne stanovit náhradní číslo.
 *
 * ── Proč jsou tři metody statické ───────────────────────────────────────────
 *
 * `monthFromRow()`, `combine()` a `workedFromEntries()` jsou čisté převody bez
 * databáze. Veřejné a statické jsou ze stejného důvodu jako
 * `PayrollJmhzWorkMonthSummaryBuilder::conditionalSuggestions()`: dají se ověřit
 * testem přímo, bez sestavování celého mzdového běhu. Právě v nich sedí
 * všechna rozhodnutí „tohle se navrhnout nedá", a ta se testovat musí.
 */
final class AverageEarningDerivationService
{
    /**
     * Stavy mzdového běhu, které se dají považovat za uzavřené.
     *
     * `draft`, `inputs_locked`, `calculated` a `reviewed` jsou rozpracované;
     * `correction_pending` a `reopened` znamenají opravu v běhu, takže dnešní
     * schválená revize nemusí být tou poslední; `cancelled` je zrušený běh.
     * Z ničeho z toho se průměrný výdělek odvozovat nesmí — jsou to peníze
     * pro náhradu a údaj do hlášení ČSSZ.
     */
    public const CLOSED_RUN_STATUSES = [
        'approved', 'posted', 'payment_ready', 'paid', 'closed',
    ];

    /**
     * Verze odvození pracovního souhrnu, ze kterých umíme číst odpracovanou dobu.
     *
     * v2 až v5 nesou zdrojový snapshot směn (`time_entries`), v6 souhrn
     * z importu docházky (`import_summary`). Co ze kterého zdroje plyne,
     * rozhoduje jediná funkce {@see PayrollWorkedTimeSource::fromSnapshot()}.
     */
    public const SUPPORTED_WORK_SUMMARY_VERSIONS = PayrollJmhzWorkMonthSummaryBuilder::CONDITIONAL_VERSIONS;

    /**
     * Důvody, kvůli kterým se místo skutečného průměru smí použít pravděpodobný
     * výdělek podle § 355 ZP.
     *
     * Jsou to právě ty situace, kdy zaměstnanec v rozhodném období neodpracoval
     * dost — nový vztah bez běhu, málo odpracovaných dnů, žádná odpracovaná
     * doba. Zbývající blokátory (`multiple_runs_for_month`,
     * `run_not_approved`, `time_month_not_approved`, `work_summary_*`) znamenají
     * VADNOU evidenci: tam § 355 nedopadá a náhradní číslo by jen zakrylo, že
     * se má opravit podklad.
     */
    public const PROBABLE_ELIGIBLE_BLOCKERS = [
        'run_missing',
        'probable_earning_required',
        'worked_time_missing',
    ];

    /** Odkud pravděpodobný výdělek pochází — viz docblock třídy, § 355 ZP. */
    public const PROBABLE_SOURCE_TERMS = 'terms';
    public const PROBABLE_SOURCE_ACHIEVED_WAGE = 'achieved_wage';
    public const PROBABLE_SOURCE_AGREED_MONTHLY_GROSS = 'agreed_monthly_gross';
    public const PROBABLE_SOURCE_MINIMUM_WAGE = 'minimum_wage';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRulesetProvider $rulesets,
        private readonly PayrollTakeoverReader $takeover,
    ) {}

    /**
     * Návrh vstupů průměru pro jeden pracovní vztah a jedno použité čtvrtletí.
     *
     * Rozhodné období je podle § 354 odst. 1 ZP předchozí kalendářní čtvrtletí;
     * počítá se stejně jako v
     * {@see \MyInvoice\Service\Payroll\PayrollAbsenceValidator::average()},
     * aby navržené `decisive_from`/`decisive_to` prošly validací beze změny.
     *
     * @return array<string,mixed>
     */
    public function suggest(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
    ): array {
        if ($employmentId <= 0) {
            throw new \InvalidArgumentException('Pracovní vztah není platný.');
        }
        if ($year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Rok použití průměru není platný.');
        }
        if ($quarter < 1 || $quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        $employmentStart = $this->employmentStart($supplierId, $employmentId);

        $applicationStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-01',
            $year,
            (($quarter - 1) * 3) + 1,
        ));
        $decisiveStart = $applicationStart->modify('-3 months');

        $months = [];
        $takeover = null;
        foreach ([0, 1, 2] as $offset) {
            $periodStart = $decisiveStart->modify("+{$offset} months")->format('Y-m-d');
            $run = $this->latestRunResult($supplierId, $employmentId, $periodStart);
            if ($run === null) {
                // Měsíc bez běhu, který vedl předchozí program: průměr se navrhne
                // z převzaté mzdy (hrubá mzda a odpracované hodiny), jinak by
                // první čtvrtletí po přechodu skončilo u ručního opisu.
                $takeover ??= $this->takeover->forEmployment(
                    $supplierId,
                    $employmentId,
                    (int) $decisiveStart->format('Y'),
                );
                $takenOver = self::takeoverMonthFor($takeover, $periodStart);
                if ($takenOver !== null) {
                    $months[] = ['period_start' => $periodStart] + self::monthFromTakeover($takenOver);
                    continue;
                }
            }
            $months[] = ['period_start' => $periodStart] + self::monthFromRow($run, $periodStart);
        }
        $minimumWorkedDays = AbsenceRuleset::forDate($this->rulesets, $applicationStart->format('Y-m-d'))
            ->averageEarningMinimumWorkedDays();

        $terms = $this->terms($supplierId, $employmentId);
        $probable = self::probableFromTerms($terms, $applicationStart->format('Y-m-d'));
        if ($probable !== null) {
            $probable['source'] = self::PROBABLE_SOURCE_TERMS;
        } elseif (self::needsProbable(self::combine($months, $minimumWorkedDays, false))
            && self::derivedProbableAllowed($months, $employmentStart)
        ) {
            $probable = self::probableFromAchievedWage([
                ...self::monthsSinceStart($months, (string) $employmentStart),
                ...$this->applicationMonths(
                    $supplierId,
                    $employmentId,
                    $applicationStart,
                    (string) $employmentStart,
                ),
            ]) ?? self::probableFromAgreedGross($terms, $applicationStart->format('Y-m-d'))
                ?? self::probableFromMinimumWage(
                    MinimumWageFloor::forDate($this->rulesets, $applicationStart->format('Y-m-d')),
                );
        }

        return [
            'employment_id' => $employmentId,
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $decisiveStart->format('Y-m-d'),
            'decisive_to' => $applicationStart->modify('-1 day')->format('Y-m-d'),
        ] + self::combine(
            $months,
            $minimumWorkedDays,
            $this->existingSnapshot($supplierId, $employmentId, $year, $quarter) !== null,
            $probable,
        );
    }

    /**
     * Chybí skutečný průměr z důvodu, na který dopadá § 355 ZP?
     *
     * @param array<string,mixed> $combined výstup {@see combine()} bez
     *        pravděpodobného výdělku
     */
    public static function needsProbable(array $combined): bool
    {
        $actualBlockers = (array) ($combined['actual_blockers'] ?? []);

        return $actualBlockers !== []
            && array_diff($actualBlockers, self::PROBABLE_ELIGIBLE_BLOCKERS) === [];
    }

    /**
     * Smí se pravděpodobný výdělek odvodit z evidence?
     *
     * Jen u nového vztahu: měsíc rozhodného období bez běhu musí celý ležet
     * před vznikem zaměstnání. Chybějící běh v měsíci, kdy vztah už trval,
     * je vadná evidence — číslo odjinud by ji jen zakrylo.
     *
     * @param list<array<string,mixed>> $months výstupy {@see monthFromRow()}
     *        doplněné o `period_start`
     */
    public static function derivedProbableAllowed(array $months, ?string $employmentStart): bool
    {
        if ($employmentStart === null) {
            return false;
        }
        foreach ($months as $month) {
            $blockers = array_values((array) ($month['blockers'] ?? []));
            if ($blockers === []) {
                continue;
            }
            if ($blockers !== ['run_missing']
                || self::monthEnd((string) $month['period_start']) >= $employmentStart
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pravděpodobný výdělek z hrubé mzdy dosažené od počátku rozhodného
     * období (§ 355 odst. 2 věta první ZP): započitatelná mzda a odpracovaná
     * doba uzavřených běhů od vzniku zaměstnání, tedy tatáž čísla, ze kterých
     * vzniká skutečný průměr.
     *
     * Blokovaný měsíc (neschválený běh, vadný souhrn docházky) návrh zruší
     * celý — částečný součet by vypadal jako hotové číslo.
     *
     * @param list<array<string,mixed>> $months výstupy {@see monthFromRow()}
     *        doplněné o `period_start`, jen měsíce od vzniku zaměstnání
     * @return array<string,mixed>|null
     */
    public static function probableFromAchievedWage(array $months): ?array
    {
        $grossMinor = 0;
        $workedMinutes = 0;
        $used = [];
        foreach ($months as $month) {
            if ((array) ($month['blockers'] ?? []) !== []
                || !is_int($month['gross_earnings_minor'] ?? null)
                || !is_int($month['worked_minutes'] ?? null)
            ) {
                return null;
            }
            $grossMinor += $month['gross_earnings_minor'];
            $workedMinutes += $month['worked_minutes'];
            $used[] = [
                'period_start' => $month['period_start'] ?? null,
                'revision_id' => $month['revision_id'] ?? null,
                'result_hash' => $month['result_hash'] ?? null,
                'work_summary_sha256' => $month['work_summary_sha256'] ?? null,
            ];
        }
        if ($used === [] || $grossMinor <= 0 || $workedMinutes <= 0) {
            return null;
        }
        if ($grossMinor > intdiv(PHP_INT_MAX, 60)) {
            return null;
        }

        return [
            // Stejná aritmetika jako skutečný průměr v AverageEarningCalculator.
            'hourly_minor' => RoundingMode::HalfUp->roundFraction($grossMinor * 60, $workedMinutes),
            'rationale' => sprintf(
                'Pravděpodobný výdělek podle § 355 odst. 2 zákoníku práce z hrubé mzdy dosažené'
                    . ' od počátku rozhodného období: započitatelná mzda %s Kč za %s odpracovaných'
                    . ' hodin (uzavřené mzdové běhy za %s).',
                self::czk($grossMinor),
                self::hours($workedMinutes),
                implode(', ', array_map(
                    static fn (array $month): string => self::monthLabel((string) $month['period_start']),
                    $used,
                )),
            ),
            'term_id' => null,
            'effective_from' => null,
            'source' => self::PROBABLE_SOURCE_ACHIEVED_WAGE,
            'months' => $used,
        ];
    }

    /**
     * Pravděpodobný výdělek ze sjednané měsíční mzdy (§ 355 odst. 2 věta druhá
     * ZP — obvyklá výše složek mzdy) přepočtené na hodinu koeficientem
     * § 356 odst. 2. Bere se z téže revize podmínek jako zadaný pravděpodobný
     * výdělek ({@see termForApplication()}).
     *
     * @param list<array<string,mixed>> $terms
     * @return array<string,mixed>|null
     */
    public static function probableFromAgreedGross(array $terms, string $applicationStart): ?array
    {
        $chosen = self::termForApplication($terms, $applicationStart);
        if ($chosen === null) {
            return null;
        }
        $monthlyGross = self::nullableInt($chosen['monthly_gross_minor'] ?? null);
        $weeklyHours = $chosen['weekly_hours'] ?? null;
        $weeklyMilli = is_string($weeklyHours) || is_int($weeklyHours)
            ? AverageEarningsMonthlyMath::weeklyHoursMilli((string) $weeklyHours)
            : null;
        if ($monthlyGross === null || $monthlyGross <= 0 || $weeklyMilli === null) {
            return null;
        }

        return [
            'hourly_minor' => AverageEarningsMonthlyMath::hourlyMinorUnitsFromMonthly(
                $monthlyGross,
                $weeklyMilli,
            ),
            'rationale' => sprintf(
                'Pravděpodobný výdělek podle § 355 odst. 2 zákoníku práce ze sjednané měsíční mzdy'
                    . ' %s Kč při týdenní pracovní době %s h, přepočteno na hodinu koeficientem 4,348'
                    . ' (§ 356 odst. 2 zákoníku práce).',
                self::czk($monthlyGross),
                str_replace('.', ',', rtrim(rtrim(number_format($weeklyMilli / 1000, 3, '.', ''), '0'), '.')),
            ),
            'term_id' => self::nullableInt($chosen['id'] ?? null),
            'effective_from' => (string) $chosen['effective_from'],
            'source' => self::PROBABLE_SOURCE_AGREED_MONTHLY_GROSS,
        ];
    }

    /**
     * Spodní mez pravděpodobného výdělku: minimální mzda (§ 357 odst. 1 ZP).
     *
     * Poslední návrh, když vztah nemá ani mzdu dosaženou od nástupu, ani
     * sjednanou měsíční mzdu — typicky dohoda v prvním měsíci nebo měsíc bez
     * příjmu. Atribut 10345 je v XSD povinný a výdělek pod minimální mzdou
     * zákon nepřipouští, takže minimální mzda je jediné číslo, které z
     * evidence plyne bez odhadu. Přijatá hlášení jiného systému ji v takovém
     * měsíci vykazují (2026: 134,40 Kč). Je to NÁVRH: vyšší pravděpodobný
     * výdělek zadá účetní do podmínek vztahu a ten má přednost.
     *
     * Bere se sazba pro obecnou týdenní dobu 40 hodin. Kratší STANOVENOU
     * týdenní dobu (vyšší hodinové minimum) zohlední výpočet průměru sám při
     * potvrzení ({@see AverageEarningCalculator}); sjednaný kratší úvazek ani
     * dohoda hodinové minimum nezvyšují.
     *
     * @return array<string,mixed>
     */
    public static function probableFromMinimumWage(MinimumWageFloor $floor): array
    {
        return [
            'hourly_minor' => $floor->hourlyMinor,
            'rationale' => sprintf(
                'Pravděpodobný výdělek podle § 355 odst. 2 zákoníku práce nejde odvodit'
                    . ' z dosažené ani sjednané mzdy; navržena spodní mez, hodinová'
                    . ' minimální mzda %s Kč (§ 357 odst. 1 zákoníku práce).',
                self::czk($floor->hourlyMinor),
            ),
            'term_id' => null,
            'effective_from' => null,
            'source' => self::PROBABLE_SOURCE_MINIMUM_WAGE,
        ];
    }

    /**
     * Měsíce rozhodného období, ve kterých už zaměstnání trvalo.
     *
     * @param list<array<string,mixed>> $months
     * @return list<array<string,mixed>>
     */
    private static function monthsSinceStart(array $months, string $employmentStart): array
    {
        return array_values(array_filter(
            $months,
            static fn (array $month): bool =>
                self::monthEnd((string) $month['period_start']) >= $employmentStart,
        ));
    }

    /**
     * Uzavřené měsíce čtvrtletí, pro které se průměr zjišťuje, od vzniku
     * zaměstnání. Končí na prvním měsíci bez běhu — pozdější měsíc bez
     * předchozího by byl díra v evidenci, ne „mzda dosažená od počátku".
     *
     * @return list<array<string,mixed>>
     */
    private function applicationMonths(
        int $supplierId,
        int $employmentId,
        \DateTimeImmutable $applicationStart,
        string $employmentStart,
    ): array {
        $months = [];
        foreach ([0, 1, 2] as $offset) {
            $periodStart = $applicationStart->modify("+{$offset} months")->format('Y-m-d');
            if (self::monthEnd($periodStart) < $employmentStart) {
                continue;
            }
            $row = $this->latestRunResult($supplierId, $employmentId, $periodStart);
            if ($row === null) {
                break;
            }
            $months[] = ['period_start' => $periodStart] + self::monthFromRow($row, $periodStart);
        }

        return $months;
    }

    private static function monthEnd(string $periodStart): string
    {
        return (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
    }

    private static function monthLabel(string $periodStart): string
    {
        return (int) substr($periodStart, 5, 2) . '/' . substr($periodStart, 0, 4);
    }

    private static function czk(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }

    private static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, ',', ' ');
    }

    /**
     * Pravděpodobný výdělek zmrazený v revizi podmínek platné pro použité období.
     *
     * Bere poslední revizi účinnou k prvnímu dni čtvrtletí, ve kterém se průměr
     * používá — to je tatáž revize, ze které v tom období platí sjednaná mzda.
     * Když k tomu dni ještě žádná účinná není (vztah začíná uprostřed
     * čtvrtletí, což je typický důvod, proč se pravděpodobný výdělek vůbec
     * stanovuje), použije se NEJSTARŠÍ revize, tedy podmínky, se kterými vztah
     * vznikl. Pozdější revizí se zpětně nic nepřepisuje.
     *
     * @param list<array<string,mixed>> $terms revize seřazené libovolně
     * @return array{hourly_minor:int,rationale:string,term_id:?int,effective_from:?string}|null
     */
    public static function probableFromTerms(array $terms, string $applicationStart): ?array
    {
        $chosen = self::termForApplication($terms, $applicationStart);
        if ($chosen === null) {
            return null;
        }

        $hourly = self::nullableInt($chosen['probable_hourly_earning_minor'] ?? null);
        $rationale = is_string($chosen['probable_earning_rationale'] ?? null)
            ? trim($chosen['probable_earning_rationale'])
            : '';
        if ($hourly === null || $hourly <= 0 || $rationale === '') {
            return null;
        }

        return [
            'hourly_minor' => $hourly,
            'rationale' => $rationale,
            'term_id' => self::nullableInt($chosen['id'] ?? null),
            'effective_from' => (string) $chosen['effective_from'],
        ];
    }

    /**
     * Revize podmínek, ze které se pro použité období bere pravděpodobný
     * výdělek i sjednaná mzda: poslední účinná k prvnímu dni čtvrtletí, jinak
     * nejstarší (vztah vznikl uprostřed čtvrtletí) — viz {@see probableFromTerms()}.
     *
     * @param list<array<string,mixed>> $terms revize seřazené libovolně
     * @return array<string,mixed>|null
     */
    public static function termForApplication(array $terms, string $applicationStart): ?array
    {
        $rows = array_values(array_filter(
            $terms,
            static fn (array $row): bool => is_string($row['effective_from'] ?? null),
        ));
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                [$left['effective_from'], self::nullableInt($left['id'] ?? null) ?? 0]
                <=> [$right['effective_from'], self::nullableInt($right['id'] ?? null) ?? 0],
        );
        if ($rows === []) {
            return null;
        }

        $chosen = $rows[0];
        foreach ($rows as $row) {
            if ((string) $row['effective_from'] <= $applicationStart) {
                $chosen = $row;
            }
        }

        return $chosen;
    }

    /**
     * Sečte tři měsíce rozhodného období do návrhu, nebo do blokátorů.
     *
     * Částečný výsledek se nevrací nikdy: sečtený neúplný základ vypadá jako
     * hotové číslo a nikdo na něm nepozná, že měsíc chybí. Jakmile má aspoň
     * jeden měsíc blokátor, jsou všechna tři čísla `null`.
     *
     * Když skutečný průměr nevznikne z důvodu, na který dopadá § 355 ZP, a u
     * pracovního vztahu je zmrazený pravděpodobný výdělek, vrátí se návrh
     * `source_kind` = `probable`. Tři čísla skutečného průměru zůstávají
     * `null`, protože se nepoužila — a `actual_blockers` říká proč. Bez
     * zadaného pravděpodobného výdělku je návrh blokovaný kódem
     * `probable_earning_not_recorded`, ne mlčením.
     *
     * @param list<array<string,mixed>> $months výstupy {@see monthFromRow()}
     *        doplněné o `period_start`
     * @param array{hourly_minor:int,rationale:string,term_id:?int,effective_from:?string}|null $probable
     *        výstup {@see probableFromTerms()}
     * @return array<string,mixed>
     */
    public static function combine(
        array $months,
        int $minimumWorkedDays,
        bool $hasExistingSnapshot,
        ?array $probable = null,
    ): array {
        /** @var array<string,bool> $blockers */
        $blockers = [];
        $grossMinor = 0;
        $workedMinutes = 0;
        $workedDays = 0;
        $sources = [];
        $takeoverPeriods = [];

        foreach ($months as $month) {
            foreach ((array) $month['blockers'] as $code) {
                $blockers[(string) $code] = true;
            }
            $sources[] = [
                'period_start' => $month['period_start'] ?? null,
                'revision_id' => $month['revision_id'] ?? null,
                'result_hash' => $month['result_hash'] ?? null,
                'work_summary_sha256' => $month['work_summary_sha256'] ?? null,
                'time_month_row_version' => $month['time_month_row_version'] ?? null,
            ];
            if (($month['takeover'] ?? false) === true) {
                $takeoverPeriods[] = substr((string) ($month['period_start'] ?? ''), 0, 7);
            }
            if ($month['blockers'] !== []) {
                continue;
            }
            // Měsíc ze souhrnu importu docházky dny nenese. Bez nich nejde
            // posoudit zákonné minimum § 355 odst. 1 ZP — a nula dnů by tiše
            // poslala průměr na pravděpodobný výdělek, na který nárok není.
            if ($month['worked_days'] === null) {
                $blockers['worked_days_not_provided'] = true;
                continue;
            }
            $grossMinor += (int) $month['gross_earnings_minor'];
            $workedMinutes += (int) $month['worked_minutes'];
            $workedDays += (int) $month['worked_days'];
        }

        if ($blockers === []) {
            // § 355 odst. 1 ZP — pod zákonným minimem odpracovaných dnů se
            // průměr nezjišťuje, ale STANOVUJE jako pravděpodobný výdělek.
            // To je jiná úvaha (čeho by zaměstnanec pravděpodobně dosáhl), ne
            // podíl dvou čísel z evidence — návrh se proto nedělá vůbec.
            if ($workedDays < $minimumWorkedDays) {
                $blockers['probable_earning_required'] = true;
            }
            // Kalkulátor skutečný průměr bez kladné mzdy a kladné doby odmítne;
            // navrhnout nulu, se kterou formulář spadne, je horší než mlčet.
            if ($grossMinor <= 0 || $workedMinutes <= 0) {
                $blockers['worked_time_missing'] = true;
            }
        }

        // § 355 ZP — skutečný průměr nevyšel, ale ne kvůli vadné evidenci.
        // Teprve tady se sáhne po pravděpodobném výdělku; když ho nikdo
        // nezadal, blokátor pojmenuje PRÁVĚ TOHLE, ne jen „nejde to".
        $actualBlockers = array_keys($blockers);
        $sourceKind = $blockers === [] ? 'actual' : null;
        if ($blockers !== []
            && array_diff($actualBlockers, self::PROBABLE_ELIGIBLE_BLOCKERS) === []
        ) {
            if ($probable !== null) {
                $sourceKind = 'probable';
                $blockers = [];
            } else {
                $blockers = ['probable_earning_not_recorded' => true];
            }
        }

        if ($hasExistingSnapshot) {
            $blockers['average_already_exists'] = true;
        }

        $ready = $blockers === [];
        $actual = $ready && $sourceKind === 'actual';

        return [
            'minimum_worked_days' => $minimumWorkedDays,
            'ready' => $ready,
            'blockers' => array_keys($blockers),
            'source_kind' => $ready ? $sourceKind : null,
            'actual_blockers' => $actualBlockers,
            'probable_hourly_minor' => $sourceKind === 'probable' && $probable !== null
                ? $probable['hourly_minor']
                : null,
            'probable_rationale' => $sourceKind === 'probable' && $probable !== null
                ? $probable['rationale']
                : null,
            'probable_term_id' => $sourceKind === 'probable' && $probable !== null
                ? $probable['term_id']
                : null,
            // Odkud se pravděpodobný výdělek vzal (viz docblock třídy). Návrh
            // z evidence má jiné odůvodnění než hodnota zadaná účetní.
            'probable_source' => $sourceKind === 'probable' && $probable !== null
                ? ($probable['source'] ?? self::PROBABLE_SOURCE_TERMS)
                : null,
            'gross_earnings_minor' => $actual ? $grossMinor : null,
            // § 358 ZP se z evidence odvodit nedá — viz docblock třídy.
            'longer_period_allocated_minor' => null,
            'worked_minutes' => $actual ? $workedMinutes : null,
            'worked_days' => $actual ? $workedDays : null,
            // Měsíce vzaté z převzatých mezd předchozího programu. Jejich hrubá
            // mzda není započitatelná mzda podle § 354 ZP — účetní ji musí před
            // schválením zkontrolovat (náhrady mzdy do průměru nepatří).
            'takeover_periods' => $takeoverPeriods,
            'months' => array_map(
                static fn (array $month): array => [
                    'period_start' => $month['period_start'] ?? null,
                    'run_id' => $month['run_id'] ?? null,
                    'revision_id' => $month['revision_id'] ?? null,
                    'revision_no' => $month['revision_no'] ?? null,
                    'gross_earnings_minor' => $month['gross_earnings_minor'] ?? null,
                    'worked_minutes' => $month['worked_minutes'] ?? null,
                    'worked_days' => $month['worked_days'] ?? null,
                    'work_summary_id' => $month['work_summary_id'] ?? null,
                    'takeover' => ($month['takeover'] ?? false) === true,
                    'blockers' => $month['blockers'],
                ],
                $months,
            ),
            // Do otisku patří i pravděpodobný výdělek: změní-li se v podmínkách
            // vztahu, je to jiný vstup, i když se měsíce nezměnily.
            'input_version' => hash('sha256', CanonicalJson::encode([
                'months' => $sources,
                'probable' => $probable,
            ])),
        ];
    }

    /**
     * Převzatý měsíc vztahu za dané období — jen když leží před začátkem vedení
     * mezd v MyÚčtu. Měsíc, který MyÚčto počítá, se z převzatých dat nebere
     * nikdy: chybějící běh tam je vadná evidence, ne přechod.
     */
    public static function takeoverMonthFor(
        \MyInvoice\Service\Payroll\Migration\PayrollTakeoverYear $year,
        string $periodStart,
    ): ?PayrollTakeoverMonth {
        $period = substr($periodStart, 0, 7);
        if (!$year->isHistorical($period)) {
            return null;
        }
        $rows = $year->forPeriod($period);

        return count($rows) === 1 ? $rows[0] : null;
    }

    /**
     * Podklady měsíce rozhodného období z převzaté mzdy předchozího programu.
     *
     * Převzatá data nenesou započitatelnou mzdu podle § 354 ZP, jen hrubou
     * mzdu. Ta může obsahovat náhrady mzdy (dovolená, svátek), které do průměru
     * nepatří — proto je měsíc označený `takeover` a návrh to uživateli řekne.
     * Průměr z něj vznikne až potvrzením účetní, stejně jako z vlastních běhů.
     *
     * @return array<string,mixed>
     */
    public static function monthFromTakeover(PayrollTakeoverMonth $month): array
    {
        $context = [
            'blockers' => [],
            'run_id' => null,
            'revision_id' => null,
            'revision_no' => null,
            'gross_earnings_minor' => $month->grossMinor,
            'worked_minutes' => $month->workedMinutes,
            // Půldny se do zákonného minima počítají dolů; rozhodující je
            // celý odpracovaný den.
            'worked_days' => intdiv($month->workedDaysHundredths, 100),
            'work_summary_id' => null,
            'work_summary_sha256' => null,
            'result_hash' => hash('sha256', CanonicalJson::encode($month->toArray())),
            'time_month_row_version' => null,
            'takeover' => true,
        ];
        if ($month->grossMinor < 0) {
            return ['blockers' => ['takeover_gross_invalid']] + $context;
        }
        if ($month->workedMinutes <= 0) {
            // Bez odpracovaných hodin se hodinový průměr spočítat nedá — a nula
            // by tiše poslala návrh na pravděpodobný výdělek.
            return ['blockers' => ['takeover_worked_time_missing']] + $context;
        }

        return $context;
    }

    /**
     * Podklady jednoho měsíce rozhodného období z jednoho řádku výsledku běhu.
     *
     * `$row === null` znamená, že za měsíc není žádný běh, ve kterém by vztah
     * figuroval — typicky nováček, u kterého se průměr stanovuje jako
     * pravděpodobný výdělek. Domýšlet se za něj nic nesmí.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    public static function monthFromRow(?array $row, string $periodStart): array
    {
        $context = [
            'blockers' => [],
            'run_id' => null,
            'revision_id' => null,
            'revision_no' => null,
            'gross_earnings_minor' => null,
            'worked_minutes' => null,
            'worked_days' => null,
            'work_summary_id' => null,
            'work_summary_sha256' => null,
            'result_hash' => null,
            'time_month_row_version' => null,
        ];
        if ($row === null) {
            return ['blockers' => ['run_missing']] + $context;
        }
        if (($row['ambiguous_runs'] ?? false) === true) {
            // Jeden vztah patří do jedné mzdové účtárny, takže dva běhy za týž
            // měsíc znamenají, že nevíme, který z nich mzdu opravdu vyplatil.
            return ['blockers' => ['multiple_runs_for_month']] + $context;
        }

        $context['run_id'] = self::nullableInt($row['run_id'] ?? null);
        $context['revision_id'] = self::nullableInt($row['revision_id'] ?? null);
        $context['revision_no'] = self::nullableInt($row['revision_no'] ?? null);
        $context['result_hash'] = is_string($row['result_hash'] ?? null)
            ? $row['result_hash']
            : null;

        if (!in_array((string) ($row['run_status'] ?? ''), self::CLOSED_RUN_STATUSES, true)
            || ($row['revision_status'] ?? null) !== 'approved'
        ) {
            return ['blockers' => ['run_not_approved']] + $context;
        }
        if (!is_string($row['result_json'] ?? null)
            || ($row['result_status'] ?? null) !== 'calculated'
        ) {
            return ['blockers' => ['run_result_missing']] + $context;
        }

        $result = self::decode((string) $row['result_json']);
        $input = is_string($row['input_json'] ?? null)
            ? self::decode((string) $row['input_json'])
            : null;
        if ($result === null || $input === null) {
            return ['blockers' => ['run_result_missing']] + $context;
        }

        $gross = $result['totals']['average_earning_base_minor'] ?? null;
        if (!is_int($gross) || $gross < 0) {
            return ['blockers' => ['average_earning_base_missing']] + $context;
        }
        $context['gross_earnings_minor'] = $gross;

        $timeMonth = $input['time_month'] ?? null;
        if (!is_array($timeMonth)) {
            return ['blockers' => ['time_month_missing']] + $context;
        }
        $context['time_month_row_version'] = self::nullableInt($timeMonth['row_version'] ?? null);
        if (($timeMonth['status'] ?? null) !== 'approved') {
            return ['blockers' => ['time_month_not_approved']] + $context;
        }

        $summary = $timeMonth['jmhz_work_summary'] ?? null;
        if (!is_array($summary)) {
            return ['blockers' => ['work_summary_missing']] + $context;
        }
        $context['work_summary_id'] = self::nullableInt($summary['id'] ?? null);
        $context['work_summary_sha256'] = is_string($summary['summary_sha256'] ?? null)
            ? $summary['summary_sha256']
            : null;
        if (!in_array($summary['derivation_version'] ?? null, self::SUPPORTED_WORK_SUMMARY_VERSIONS, true)) {
            return ['blockers' => ['work_summary_version_unsupported']] + $context;
        }

        $confirmedMillihours = $summary['values']['worked_millihours'] ?? null;
        $sourceJson = $summary['source_snapshot_json'] ?? null;
        $sourceHash = $summary['source_snapshot_sha256'] ?? null;
        if (!is_int($confirmedMillihours)
            || $confirmedMillihours < 0
            || !is_string($sourceJson)
            || !is_string($sourceHash)
            || !hash_equals($sourceHash, hash('sha256', $sourceJson))
        ) {
            return ['blockers' => ['work_summary_source_corrupt']] + $context;
        }

        $source = self::decode($sourceJson);
        if ($source === null
            || !in_array($source['schema_version'] ?? null, self::SUPPORTED_WORK_SUMMARY_VERSIONS, true)
            || (!is_array($source[PayrollWorkedTimeSource::KIND_TIME_ENTRIES] ?? null)
                && !is_array($source[PayrollWorkedTimeSource::KIND_IMPORT_SUMMARY] ?? null))
        ) {
            return ['blockers' => ['work_summary_source_corrupt']] + $context;
        }

        $worked = PayrollWorkedTimeSource::fromSnapshot($source, $periodStart);
        if ($worked['issues'] !== []) {
            return ['blockers' => ['worked_time_not_derivable']] + $context;
        }

        if ($worked['kind'] === PayrollWorkedTimeSource::KIND_IMPORT_SUMMARY) {
            // Souhrn z importu nese millihodiny; porovnávají se přímo, bez
            // převodu na minuty. Rozejít se můžou, když účetní navržené hodiny
            // při potvrzení přepsala — pak potvrzené číslo nestojí na podkladu.
            if ($worked['worked_millihours'] !== $confirmedMillihours) {
                return ['blockers' => ['work_summary_hours_mismatch']] + $context;
            }
            // Průměr vede dobu v celých minutách. Millihodiny, které celým
            // minutám neodpovídají, se nezaokrouhlují — to by byl jiný údaj,
            // než jaký odešel do hlášení.
            if ($worked['worked_minutes'] === null) {
                return ['blockers' => ['worked_time_not_whole_minutes']] + $context;
            }
            $context['worked_minutes'] = $worked['worked_minutes'];
            // Podklady docházky dny nenesou; `null` = neuvedeno, ne nula.
            $context['worked_days'] = $worked['worked_days'];

            return $context;
        }

        // Kontrola, že odpracované DNY stojí na téže evidenci jako odpracované
        // HODINY: minuty přepočtené ze zmrazených směn se musí do poslední
        // milihodiny shodovat s hodnotou, kterou účetní potvrdila do hlášení.
        // Porovnává se v celých číslech (minuty × 1000 proti milihodinám × 60),
        // takže se nikde nezaokrouhluje. Rozejít se můžou tehdy, když účetní
        // navrženou hodinu přepsala — pak jsou dny odjinud než hodiny a návrh
        // by tvrdil souvislost, která tam není.
        if ((int) $worked['worked_minutes'] * 1000 !== $confirmedMillihours * 60) {
            return ['blockers' => ['work_summary_hours_mismatch']] + $context;
        }

        $context['worked_minutes'] = $worked['worked_minutes'];
        $context['worked_days'] = $worked['worked_days'];

        return $context;
    }

    /**
     * Odpracované minuty a dny ze zmrazeného seznamu směn.
     *
     * Počítá je {@see PayrollWorkedTimeSource::fromEntries()} — TÁŽ funkce,
     * ze které pracovní souhrn navrhl potvrzené hodiny. Kdyby měl průměr
     * vlastní kopii postupu, kontrola shody s potvrzenými hodinami by neměla
     * žádnou vypovídací hodnotu.
     *
     * @param array<mixed> $entries
     * @return array{minutes:int,days:int}|null `null` = evidenci nelze bez
     *         posouzení sečíst (překryv, interval přes měsíc, záporná doba)
     */
    public static function workedFromEntries(array $entries, string $periodStart): ?array
    {
        $worked = PayrollWorkedTimeSource::fromEntries($entries, $periodStart);
        if ($worked['issues'] !== []) {
            return null;
        }

        return ['minutes' => (int) $worked['worked_minutes'], 'days' => (int) $worked['worked_days']];
    }

    /**
     * Nejnovější revize výsledku běhu za měsíc, ve které vztah figuruje.
     *
     * Vrací `null`, když za měsíc žádný běh není. Když jich je víc (dvě mzdové
     * účtárny), vrací příznak `ambiguous_runs` místo dat — vybrat jeden z nich
     * by znamenalo hádat, který mzdu vyplatil.
     *
     * @return array<string,mixed>|null
     */
    private function latestRunResult(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.id AS revision_id,
                    revision.run_id,
                    revision.revision_no,
                    revision.status AS revision_status,
                    run.status AS run_status,
                    result.input_json,
                    result.result_json,
                    result.result_hash,
                    result.status AS result_status
               FROM payroll_run_employments result
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = result.supplier_id
                AND revision.id = result.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE result.supplier_id = ?
                AND result.employment_id = ?
                AND result.period_start = ?
              ORDER BY revision.run_id, revision.revision_no',
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }

        /** @var array<int,array<string,mixed>> $latestByRun */
        $latestByRun = [];
        foreach ($rows as $row) {
            $runId = (int) $row['run_id'];
            $row['run_id'] = $runId;
            $row['revision_id'] = (int) $row['revision_id'];
            $row['revision_no'] = (int) $row['revision_no'];
            if (!isset($latestByRun[$runId])
                || $row['revision_no'] > $latestByRun[$runId]['revision_no']
            ) {
                $latestByRun[$runId] = $row;
            }
        }
        if (count($latestByRun) > 1) {
            return ['ambiguous_runs' => true];
        }

        return array_values($latestByRun)[0];
    }

    /**
     * Revize podmínek vztahu s pravděpodobným výdělkem.
     *
     * Čte se celá řada revizí (jsou jich jednotky) a vybírá se v čisté statické
     * {@see probableFromTerms()}, aby šel výběr ověřit testem bez databáze —
     * ze stejného důvodu jako u ostatních statických převodů této třídy.
     *
     * @return list<array<string,mixed>>
     */
    private function terms(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, effective_from,
                    probable_hourly_earning_minor, probable_earning_rationale,
                    monthly_gross_minor, weekly_hours
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1)
            ? (int) $value
            : null;
    }

    /** @return array<string,mixed>|null */
    private static function decode(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }

    /** Den vzniku zaměstnání (skutečný nástup má přednost před sjednaným). */
    private function employmentStart(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, COALESCE(actual_start_date, start_date) AS start_on
               FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \InvalidArgumentException('Pracovní vztah nebyl nalezen.');
        }

        return is_string($row['start_on'] ?? null) && $row['start_on'] !== ''
            ? (string) $row['start_on']
            : null;
    }

    /**
     * Nejnovější revize průměru pro totéž použité čtvrtletí, ať už čeká na
     * schválení, nebo je schválená.
     *
     * Návrh existující výpočet nepřepisuje ze stejného důvodu jako u nároku
     * na dovolenou: nové číslo by tiše nahradilo to, ze kterého už mohla odejít
     * náhrada nebo hlášení. Opravit se dá ručně.
     *
     * @return array<string,mixed>|null
     */
    private function existingSnapshot(
        int $supplierId,
        int $employmentId,
        int $year,
        int $quarter,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, revision_no, status
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status IN ('manual_review', 'approved')
              ORDER BY revision_no DESC
              LIMIT 1",
        );
        $stmt->execute([$supplierId, $employmentId, $year, $quarter]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
