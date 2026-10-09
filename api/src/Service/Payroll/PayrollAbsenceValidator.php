<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Service\Payroll\Absence\AbsenceRuleset;
use MyInvoice\Service\Payroll\Absence\PayrollObstacleKind;
use MyInvoice\Service\Payroll\Absence\SicknessCompensationReduction;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetYearCoverage;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver;

final class PayrollAbsenceValidator
{
    /**
     * `unexcused` = neomluvené zameškání směny nebo její části (§ 223 odst. 1
     * ZP, o kterém rozhoduje zaměstnavatel podle § 348 odst. 3). Je to JEDINÝ
     * druh absence, o který se smí krátit dovolená — kniha dovolené proti němu
     * krácení poměřuje. Vědomě je oddělený od `employee_obstacle`: překážka
     * v práci je nepřítomnost OMLUVENÁ a krátit se za ni nesmí.
     *
     * `public_function` (výkon veřejné funkce, § 200 až 202 ZP) a
     * `employee_obstacle_unpaid` (překážka na straně zaměstnance, za kterou
     * náhrada mzdy nepřísluší) jsou omluvené nepřítomnosti BEZ náhrady mzdy.
     * `employee_obstacle` zůstává placenou překážkou — jen tak ji čte hlášení
     * (10471 „s náhradou mzdy") i ELDP. Obě nové se proto vedou jako pracovní
     * volno bez náhrady příjmu, stejně jako neplacené volno
     * ({@see \MyInvoice\Service\Payroll\Submission\Eldp\EldpExcludedPeriodDeriver::UNPAID_EXCUSED_TYPES}).
     *
     * `invalid_termination` je doba, po kterou podle pravomocného rozhodnutí
     * soudu (nebo mimosoudní dohody) vztah trval po neplatném skončení, aniž
     * byla přiznána náhrada mzdy (§ 16 odst. 4 písm. j) zákona č. 155/1995 Sb.).
     * Mzda za ni nenáleží; v hlášení je vyloučenou dobou 10536.
     */
    private const TYPES = [
        'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
        'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
        'employer_obstacle', 'compensatory_time_off', 'unexcused', 'other',
        'public_function', 'employee_obstacle_unpaid', 'invalid_termination',
    ];

    private const DOMAIN = PayrollRulesetDomain::CompensationAverages;

    /**
     * Druhy absence, jejichž náhrada se počítá z průměrného výdělku. Uložit je
     * lze i bez něj; bez něj je nelze SCHVÁLIT, protože ze schválení vzniká
     * mzdový vstup a ten by neměl z čeho počítat.
     */
    public const TYPES_REQUIRING_AVERAGE = [
        'vacation', 'dpn', 'quarantine', 'employee_obstacle', 'employer_obstacle',
    ];

    /**
     * Druhy s náhradou z průměru čtvrtletí (náhrada 100 % průměru, překážky), které
     * nesmí přejít přes konec kalendářního čtvrtletí; zbytek {@see self::absence()}.
     */
    public const TYPES_WITHIN_QUARTER = [
        'vacation', 'employee_obstacle', 'employer_obstacle',
    ];

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * `$takeover` = nepřítomnost převzatá ze zpracovaných mezd předchozího
     * programu. Náhradu za ni už obsahuje převzatá mzda a zdroj druh překážky
     * nenese, takže se u ní druh nevyžaduje; bez druhu se ale v MyÚčtu nikdy
     * nematerializuje náhrada (`PayrollAbsenceAction::decision()`).
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function absence(array $body, bool $takeover = false): array
    {
        $employmentId = $this->positiveInt($body, 'employment_id');
        $type = trim((string) ($body['absence_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Druh absence není platný.');
        }
        $from = $this->date($body['date_from'] ?? null, 'date_from');
        $to = $this->date($body['date_to'] ?? null, 'date_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Konec absence nesmí předcházet začátku.');
        }
        /*
         * Převzatá nepřítomnost z doby PŘED prvním rulesetem náhrad je historická
         * evidence, ne vstup výpočtu: náhradu za ni nese převzatá mzda a mzdový běh
         * takové období nikdy nespočítá (bez rulesetu selže). Potřebuje ji ELDP,
         * které z ní skládá rozpad vyloučených dob § 16 odst. 4 převzatého měsíce.
         * Proto se u ní pokrytí nekontroluje a sazba náhrady se nedosazuje.
         * Ručně zadaná nepřítomnost, převzatá přes začátek rulesetů nebo v roce PO
         * posledním rulesetu zůstávají fail-closed.
         */
        $historical = $takeover && $this->predatesRulesets($to);
        if (!$historical) {
            PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $from);
            PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $to);
        }
        [$expectedChildbirth, $childbirth] = $this->childbirthDates($type, $from, $body);
        $timezone = trim((string) ($body['timezone_name'] ?? 'Europe/Prague'));
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Časové pásmo není platné.');
        }
        [$obstacleKind, $obstacleRate, $obstacleRateReason] = $takeover
            && PayrollObstacleKind::isObstacleType($type)
            && self::blank($body['obstacle_kind'] ?? null)
            ? [null, null, null]
            : $this->obstacle($type, $body);
        $policy = match ($type) {
            'dpn', 'quarantine' => 'dpn',
            'vacation' => 'average_100',
            'employee_obstacle', 'employer_obstacle' => match (true) {
                $obstacleKind === null => 'statutory_manual_review',
                $obstacleRate === PayrollObstacleKind::FULL_RATE_BASIS_POINTS => 'average_100',
                default => 'average_custom',
            },
            // Náhradní volno: za dobu jeho čerpání mzda nepřísluší
            // (§ 114 odst. 1 zákoníku práce) — přesčas se už zaplatil mzdou,
            // volnem se nahrazuje jen příplatek. Proto `none`, ne přehlédnutí.
            'compensatory_time_off' => 'none',
            // Za neomluveně zameškanou dobu mzda ani náhrada nepřísluší —
            // zaměstnanec v ní nepracoval a žádná překážka v práci to nekryje.
            'unexcused' => 'none',
            // Pracovní volno bez náhrady mzdy (§ 199 odst. 1, § 200 až 202 ZP).
            // Náhradu, kterou zákon u některých veřejných funkcí přiznává,
            // tahle evidence nepočítá — takový případ zatím vede účetní ručním
            // mzdovým vstupem.
            'public_function', 'employee_obstacle_unpaid' => 'none',
            // Náhrada mzdy za dobu po neplatném skončení přiznaná nebyla —
            // jinak by nešlo o vyloučenou dobu § 16 odst. 4 písm. j).
            'invalid_termination' => 'none',
            default => 'none',
        };
        if (in_array($type, self::TYPES_WITHIN_QUARTER, true)
            && $this->calendarQuarter($from) !== $this->calendarQuarter($to)
        ) {
            /*
             * ZŮSTÁVÁ. Průměrný výdělek se zjišťuje vždy k prvnímu dni
             * kalendářního čtvrtletí (§ 354 odst. 1 ZP) — jedna nepřítomnost
             * přes přelom čtvrtletí by se počítala dvěma různými průměry
             * a v jednom řádku by to nešlo poctivě uložit. Hláška ale musí
             * říct, KDE ten řez vede, ne jen „rozděl to".
             */
            $boundary = self::nextQuarterStart($from);
            $lastOfQuarter = $boundary->modify('-1 day')->format('Y-m-d');
            throw new \InvalidArgumentException(sprintf(
                'Nepřítomnost s náhradou mzdy nesmí přejít přes konec čtvrtletí — '
                . 'průměrný výdělek se zjišťuje k prvnímu dni čtvrtletí '
                . '(§ 354 odst. 1 ZP). Zapište ji dvakrát: %s – %s a %s – %s.',
                $from,
                $lastOfQuarter,
                $boundary->format('Y-m-d'),
                $to,
            ));
        }
        /*
         * Průměrný výdělek se tu ZÁMĚRNĚ nevyžaduje. Je to podmínka VÝPOČTU
         * náhrady, ne podmínka zápisu nepřítomnosti: účetní se o dovolené nebo
         * neschopence dozví dřív, než je spočítaný a schválený čtvrtletní
         * průměr, a evidence, která ji do té doby nepustí nic uložit, ji nutí
         * držet papír na stole. Kontrola zůstává — přesunula se na schválení
         * absence, kde teprve vzniká mzdový vstup. Do té doby je absence rozdělaná
         * práce, která se nesmí ztratit. Seznam dotčených druhů drží konstanta
         * {@see self::TYPES_REQUIRING_AVERAGE}, podle které se kontroluje
         * schválení.
         */
        $averageId = $this->nullablePositiveInt($body['average_snapshot_id'] ?? null, 'average_snapshot_id');
        /*
         * Osamělý zaměstnanec s dítětem do 16 let má podpůrčí dobu ošetřovného
         * 16 dnů místo 9 (§ 40 odst. 1 písm. b) zákona č. 187/2006 Sb.). Na ní
         * stojí vyloučené doby i vyloučené dny měsíčního hlášení, a jinde než
         * u ošetřování člena rodiny nemá význam.
         */
        $loneCarer = $body['lone_carer'] ?? false;
        if (!is_bool($loneCarer)) {
            throw new \InvalidArgumentException('Osamělý zaměstnanec musí být ano, nebo ne.');
        }
        if ($loneCarer && $type !== 'ocr') {
            throw new \InvalidArgumentException(
                'Osamělého zaměstnance (16 dnů ošetřovného) lze označit jen u ošetřování člena rodiny.',
            );
        }
        return [
            'employment_id' => $employmentId,
            'absence_type' => $type,
            'date_from' => $from,
            'date_to' => $to,
            'expected_childbirth_date' => $expectedChildbirth,
            'childbirth_date' => $childbirth,
            'lone_carer' => $loneCarer,
            'timezone_name' => $timezone,
            'partial_first_minutes' => $this->nullablePositiveInt(
                $body['partial_first_minutes'] ?? null,
                'partial_first_minutes',
            ),
            'partial_last_minutes' => $this->nullablePositiveInt(
                $body['partial_last_minutes'] ?? null,
                'partial_last_minutes',
            ),
            'note' => $this->nullableText($body['note'] ?? null, 1000),
            'compensation_policy' => $policy,
            // Sazba náhrady při DPN je zákonná a mění se — bere se z rulesetu,
            // ať absence a výpočet náhrady nikdy nepracují s jiným číslem.
            // 10 000 bp u ostatních politik je definice „average_100", ne sazba.
            'compensation_rate_basis_points' => match (true) {
                $policy === 'none' => null,
                $policy === 'dpn' && $historical => null,
                $policy === 'dpn' => AbsenceRuleset::forDate($this->rulesets, $from)
                    ->compensationRateBasisPoints(),
                $obstacleRate !== null => $obstacleRate,
                default => 10_000,
            },
            'obstacle_kind' => $obstacleKind?->value,
            'compensation_rate_reason' => $obstacleRateReason,
            'average_snapshot_id' => $averageId,
        ];
    }

    /**
     * Končí převzatá nepřítomnost před prvním dnem rulesetu náhrad, tedy v době,
     * kterou mzdový běh nikdy nepočítá? Pak je historickou evidencí
     * ({@see self::absence()}) a převod ji schvaluje bez průměru.
     */
    public function predatesRulesets(string $date): bool
    {
        return PayrollRulesetYearCoverage::predatesCoverage($this->rulesets, self::DOMAIN, $date);
    }

    /**
     * Druh placené překážky a sazba její náhrady.
     *
     * Sazba se bere z tabulky druhu ({@see PayrollObstacleKind}). Účetní ji smí
     * přepsat jen v mezích zákona a jen s důvodem: sazba, kterou by šlo změnit
     * bez vysvětlení, by za rok nešla obhájit. Druh je povinný — bez něj není
     * jasné, jestli a v jaké výši náhrada přísluší, a do které kolonky
     * měsíčního hlášení patří.
     *
     * @param array<string,mixed> $body
     * @return array{0:?PayrollObstacleKind,1:?int,2:?string}
     */
    private function obstacle(string $type, array $body): array
    {
        $rawKind = $body['obstacle_kind'] ?? null;
        $rawRate = $body['compensation_rate_basis_points'] ?? null;
        $rawReason = $body['compensation_rate_reason'] ?? null;
        if (!PayrollObstacleKind::isObstacleType($type)) {
            if (!self::blank($rawKind) || !self::blank($rawRate) || !self::blank($rawReason)) {
                throw new \InvalidArgumentException(
                    'Druh překážky a sazba náhrady se vyplňují jen u placené překážky v práci.',
                );
            }

            return [null, null, null];
        }
        $kind = is_string($rawKind) ? PayrollObstacleKind::tryFrom(trim($rawKind)) : null;
        if ($kind === null) {
            throw new \InvalidArgumentException(
                'Vyberte druh překážky — podle něj se určí, jaká náhrada mzdy přísluší.',
            );
        }
        if ($kind->absenceType() !== $type) {
            throw new \InvalidArgumentException($type === PayrollObstacleKind::EMPLOYER_SIDE_TYPE
                ? 'Vybraný druh je překážkou na straně zaměstnance, ne zaměstnavatele.'
                : 'Vybraný druh je překážkou na straně zaměstnavatele, ne zaměstnance.');
        }
        $reason = $this->nullableText($rawReason, 500);
        [$minimum, $maximum] = $kind->rateBounds();
        if (self::blank($rawRate)) {
            $rate = $kind->defaultRateBasisPoints();
        } else {
            $rate = filter_var($rawRate, FILTER_VALIDATE_INT);
            if ($rate === false || $rate < $minimum || $rate > $maximum) {
                throw new \InvalidArgumentException($minimum === $maximum
                    ? sprintf('Náhrada za tuto překážku je ze zákona %s %% průměrného výdělku.', self::percent($minimum))
                    : sprintf(
                        'Sazba náhrady za tuto překážku musí být %s až %s %% průměrného výdělku.',
                        self::percent($minimum),
                        self::percent($maximum),
                    ));
            }
        }
        if ($reason === null && ($rate !== $kind->defaultRateBasisPoints() || $kind->requiresReason())) {
            throw new \InvalidArgumentException($kind === PayrollObstacleKind::PartialUnemployment
                ? 'U částečné nezaměstnanosti uveďte dohodu s odborovou organizací nebo vnitřní předpis, '
                    . 'který výši náhrady stanoví (§ 209 odst. 2 ZP). Bez něj jde o jinou překážku '
                    . 'se 100 % průměru (§ 208 ZP).'
                : ($kind === PayrollObstacleKind::OtherPaidEmployee
                    ? 'U jiné placené překážky uveďte, co ji zakládá (vnitřní předpis, kolektivní smlouva, '
                        . 'zvláštní zákon).'
                    : 'Sazba se liší od tabulkové — uveďte důvod (například vnitřní předpis nebo dohodu).'));
        }

        return [$kind, $rate, $reason];
    }

    private static function percent(int $basisPoints): string
    {
        return $basisPoints % 100 === 0
            ? (string) intdiv($basisPoints, 100)
            : str_replace('.', ',', rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.'));
    }

    /**
     * Den porodu doplňovaný k už zapsané peněžité pomoci v mateřství.
     *
     * @param array<string,mixed> $absence uložená absence
     */
    public function childbirthDate(array $absence, mixed $value): string
    {
        if (($absence['absence_type'] ?? null) !== 'ppm') {
            throw new \InvalidArgumentException(
                'Den porodu se doplňuje jen u peněžité pomoci v mateřství.',
            );
        }
        if (!is_string($absence['expected_childbirth_date'] ?? null)) {
            throw new \InvalidArgumentException(
                'Nepřítomnost nemá očekávaný den porodu, ke kterému by šel den porodu doplnit. '
                . 'Zrušte ji a zapište znovu i s očekávaným dnem porodu.',
            );
        }
        if (self::blank($value)) {
            throw new \InvalidArgumentException('Den porodu je povinný.');
        }

        return $this->date($value, 'childbirth_date');
    }

    /**
     * Očekávaný a skutečný den porodu. Vyplňují se jen u peněžité pomoci
     * v mateřství.
     *
     * Očekávaný den porodu je povinný: bez něj nejde určit, od kdy je PPM
     * vyloučenou dobou evidenčního listu (§ 16 odst. 4 věta třetí písm. a)
     * zákona č. 155/1995 Sb.). Skutečný den porodu je nepovinný: zapisuje se
     * typicky dopředu a porod se doplní, až nastane.
     *
     * Den porodu SMÍ ležet před začátkem nepřítomnosti. Nástup na PPM po
     * porodu je zákonný případ (převzetí dítěte do péče, § 34 odst. 1
     * písm. c) zákona č. 187/2006 Sb.) a stejně tak druhá část podpůrčí doby
     * zapsaná zvlášť. Bez dne porodu by u nich výpočet vyloučených dob neměl
     * podle čeho poznat, že před porodem není ani den.
     *
     * @param array<string,mixed> $body
     * @return array{0:?string,1:?string}
     */
    private function childbirthDates(string $type, string $from, array $body): array
    {
        $expectedRaw = $body['expected_childbirth_date'] ?? null;
        $childbirthRaw = $body['childbirth_date'] ?? null;
        if ($type !== 'ppm') {
            if (!self::blank($expectedRaw) || !self::blank($childbirthRaw)) {
                throw new \InvalidArgumentException(
                    'Očekávaný den porodu a den porodu se vyplňují jen u peněžité pomoci v mateřství.',
                );
            }

            return [null, null];
        }
        if (self::blank($expectedRaw)) {
            throw new \InvalidArgumentException(
                'U peněžité pomoci v mateřství je očekávaný den porodu povinný, bez něj nejde '
                . 'určit, která část je vyloučenou dobou evidenčního listu.',
            );
        }
        $expected = $this->date($expectedRaw, 'expected_childbirth_date');
        $childbirth = self::blank($childbirthRaw) ? null : $this->date($childbirthRaw, 'childbirth_date');
        self::assertMaternityStart($from, $expected, $childbirth);

        return [$expected, $childbirth];
    }

    /**
     * Na PPM před porodem se nastupuje nejdříve od začátku osmého týdne před
     * očekávaným dnem porodu: § 32 odst. 1 písm. a) zákona č. 187/2006 Sb.
     * („před porodem má v době nejdříve od počátku osmého týdne před
     * očekávaným dnem porodu nárok … těhotná pojištěnka") a § 34 odst. 1
     * písm. a). Dřívější začátek je zákonný jen tehdy, když nepřítomnost
     * začíná až porodem nebo po něm (§ 34 odst. 1 písm. b) a c)).
     */
    private static function assertMaternityStart(string $from, string $expected, ?string $childbirth): void
    {
        $earliest = (new \DateTimeImmutable($expected))
            ->modify('-' . EldpExcludedPeriodDeriver::PPM_PRE_BIRTH_DAYS . ' days')
            ->format('Y-m-d');
        if ($from >= $earliest || ($childbirth !== null && $from >= $childbirth)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Peněžitou pomoc v mateřství před porodem nelze čerpat dřív než od začátku osmého '
            . 'týdne před očekávaným dnem porodu (§ 32 odst. 1 písm. a) a § 34 odst. 1 zákona '
            . 'č. 187/2006 Sb.). Při očekávaném porodu %s je to nejdříve %s.',
            $expected,
            $earliest,
        ));
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Snížení náhrady mzdy při DPN ze schválení (§ 192 odst. 4 a 5 ZP).
     *
     * `compensation_reduction`: `none` (výchozí), `half_192_4` (na polovinu, případy
     * § 31 zák. č. 187/2006 Sb.), nebo `reduced_192_5` (porušení režimu) s právě jedním
     * z `compensation_reduction_basis_points` (o kolik, 1–10 000, 10 000 = neposkytnout)
     * a `compensation_reduction_minor` (o kolik haléřů). Důvod
     * `compensation_reduction_reason` je povinný u obou snížení.
     *
     * @param array<string,mixed> $body
     */
    public function sicknessReduction(array $body): SicknessCompensationReduction
    {
        $kind = trim((string) ($body['compensation_reduction'] ?? ''));
        $reason = (string) ($body['compensation_reduction_reason'] ?? '');
        $share = $body['compensation_reduction_basis_points'] ?? null;
        $amount = $body['compensation_reduction_minor'] ?? null;
        if ($kind === '' || $kind === SicknessCompensationReduction::NONE) {
            if (!self::blank($share) || !self::blank($amount)) {
                throw new \InvalidArgumentException('Výše snížení náhrady se zadává jen spolu s druhem snížení.');
            }

            return SicknessCompensationReduction::none();
        }
        if ($kind === SicknessCompensationReduction::HALF) {
            if (!self::blank($share) || !self::blank($amount)) {
                throw new \InvalidArgumentException(
                    'Snížení podle § 192 odst. 4 ZP je vždy na polovinu, výše se nezadává.',
                );
            }

            return SicknessCompensationReduction::half($reason);
        }
        if ($kind !== SicknessCompensationReduction::DISCRETIONARY) {
            throw new \InvalidArgumentException('Druh snížení náhrady mzdy není platný.');
        }
        if (self::blank($share) === self::blank($amount)) {
            throw new \InvalidArgumentException(
                'U snížení podle § 192 odst. 5 ZP zadejte buď procento, nebo částku, o kterou se náhrada snižuje.',
            );
        }
        if (!self::blank($share)) {
            $basisPoints = filter_var($share, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10_000]]);
            if ($basisPoints === false) {
                throw new \InvalidArgumentException('Snížení náhrady musí být od 0,01 do 100 %.');
            }

            return SicknessCompensationReduction::byShare((int) $basisPoints, $reason);
        }
        $minor = filter_var($amount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($minor === false) {
            throw new \InvalidArgumentException('Snížení náhrady částkou musí být kladné.');
        }

        return SicknessCompensationReduction::byAmount((int) $minor, $reason);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function average(array $body): array
    {
        $year = $this->positiveInt($body, 'applicable_year');
        $quarter = $this->positiveInt($body, 'applicable_quarter');
        if ($quarter > 4) {
            throw new \InvalidArgumentException('Čtvrtletí průměru musí být 1–4.');
        }
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $year);
        $from = $this->date($body['decisive_from'] ?? null, 'decisive_from');
        $to = $this->date($body['decisive_to'] ?? null, 'decisive_to');
        if ($to < $from) {
            throw new \InvalidArgumentException('Rozhodné období průměru není platné.');
        }
        $applicationStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-01',
            $year,
            (($quarter - 1) * 3) + 1,
        ));
        $expectedFrom = $applicationStart->modify('-3 months')->format('Y-m-d');
        $expectedTo = $applicationStart->modify('-1 day')->format('Y-m-d');
        if ($from !== $expectedFrom || $to !== $expectedTo) {
            throw new \InvalidArgumentException(
                "Rozhodné období pro {$year}/Q{$quarter} musí být {$expectedFrom} až {$expectedTo}."
            );
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'applicable_year' => $year,
            'applicable_quarter' => $quarter,
            'decisive_from' => $from,
            'decisive_to' => $to,
            'gross_earnings_minor' => $this->nonNegativeInt($body, 'gross_earnings_minor'),
            'longer_period_allocated_minor' => $this->nonNegativeInt($body, 'longer_period_allocated_minor'),
            'worked_minutes' => $this->nonNegativeInt($body, 'worked_minutes'),
            'worked_days' => $this->nonNegativeInt($body, 'worked_days'),
            'probable_hourly_minor' => $this->nullablePositiveInt(
                $body['probable_hourly_minor'] ?? null,
                'probable_hourly_minor',
            ),
            'rationale' => $this->nullableText($body['rationale'] ?? null, 1000),
        ];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function entitlement(array $body): array
    {
        $rationale = trim((string) ($body['rationale'] ?? ''));
        if ($rationale === '' || mb_strlen($rationale) > 1000) {
            throw new \InvalidArgumentException('Odůvodnění nároku je povinné a smí mít nejvýše 1000 znaků.');
        }
        $year = $this->positiveInt($body, 'leave_year');
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $year);
        $minimumWeeks = AbsenceRuleset::forYear($this->rulesets, $year)
            ->leaveStatutoryMinimumWeeks();
        $entitlementWeeks = $this->positiveInt($body, 'entitlement_weeks');
        if ($entitlementWeeks < $minimumWeeks) {
            throw new \InvalidArgumentException(
                "Výměra dovolené nesmí být nižší než zákonné minimum {$minimumWeeks} týdny."
            );
        }
        return [
            'employment_id' => $this->positiveInt($body, 'employment_id'),
            'leave_year' => $year,
            'weekly_minutes' => $this->positiveInt($body, 'weekly_minutes'),
            'entitlement_weeks' => $entitlementWeeks,
            'continuous_calendar_days' => $this->positiveInt($body, 'continuous_calendar_days'),
            'worked_equivalent_minutes' => $this->positiveInt($body, 'worked_equivalent_minutes'),
            'rationale' => $rationale,
        ];
    }

    /**
     * Ruční položka knihy dovolené smí vzniknout jen v roce, který má účinný
     * ruleset, a její datum účinnosti musí do téhož roku patřit.
     */
    public function assertLeaveEntryPeriod(int $leaveYear, string $effectiveDate): void
    {
        PayrollRulesetYearCoverage::assertYear($this->rulesets, self::DOMAIN, $leaveYear);
        if ((int) substr($effectiveDate, 0, 4) !== $leaveYear) {
            throw new \InvalidArgumentException(
                'Datum účinnosti položky dovolené musí ležet v roce nároku.'
            );
        }
        PayrollRulesetYearCoverage::assertDate($this->rulesets, self::DOMAIN, $effectiveDate);
    }

    private function date(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        if ($date === false || $date->format('Y-m-d') !== $text) {
            throw new \InvalidArgumentException(self::label($field) . ' musí být platné datum ve tvaru RRRR-MM-DD.');
        }
        return $text;
    }

    /** @param array<string,mixed> $body */
    /**
     * Lidský název pole pro chybovou hlášku.
     *
     * Why: validátor pojmenovával sloupce („gross_earnings_minor musí být
     * nezáporné celé číslo"), jenže ve formuláři se to pole jmenuje
     * „Započitatelná mzda (Kč)" a zadává se v korunách, ne v minorech. Účetní
     * z hlášky nepoznala ani které pole má opravit, ani proč. Klíč bez překladu
     * se vypíše tak, jak je — neúplný slovník nesmí zamlčet, že něco chybí.
     */
    private const FIELD_LABELS = [
        'gross_earnings_minor' => 'Započitatelná mzda',
        'worked_hours_milli' => 'Odpracované hodiny',
        'worked_days' => 'Odpracované dny',
        'employment_id' => 'Pracovní vztah',
        'employee_id' => 'Zaměstnanec',
        'average_snapshot_id' => 'Snímek průměrného výdělku',
        'applicable_year' => 'Rok',
        'applicable_quarter' => 'Čtvrtletí',
        'date_from' => 'Datum od',
        'date_to' => 'Datum do',
        'expected_childbirth_date' => 'Očekávaný den porodu',
        'childbirth_date' => 'Den porodu',
        'effective_date' => 'Datum účinnosti',
        'leave_entitlement_weeks' => 'Nárok na dovolenou v týdnech',
        'compensation_rate_basis_points' => 'Sazba náhrady',
        'partial_first_minutes' => 'Zameškané minuty prvního dne',
        'partial_last_minutes' => 'Zameškané minuty posledního dne',
    ];

    private static function label(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    private function positiveInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            throw new \InvalidArgumentException(self::label($field) . ' musí být kladné celé číslo.');
        }
        return (int) $value;
    }

    /** @param array<string,mixed> $body */
    private function nonNegativeInt(array $body, string $field): int
    {
        $value = filter_var($body[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) {
            throw new \InvalidArgumentException(self::label($field) . ' musí být nezáporné celé číslo.');
        }
        return (int) $value;
    }

    private function nullablePositiveInt(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $result = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($result === false) {
            throw new \InvalidArgumentException(self::label($field) . ' musí být kladné celé číslo.');
        }
        return (int) $result;
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("Text smí mít nejvýše {$max} znaků.");
        }
        return $text;
    }

    /** První den čtvrtletí, které následuje po čtvrtletí daného data. */
    private static function nextQuarterStart(string $date): \DateTimeImmutable
    {
        $value = new \DateTimeImmutable($date);
        $quarter = intdiv((int) $value->format('n') - 1, 3) + 1;

        return $quarter === 4
            ? new \DateTimeImmutable(sprintf('%04d-01-01', (int) $value->format('Y') + 1))
            : new \DateTimeImmutable(sprintf(
                '%04d-%02d-01',
                (int) $value->format('Y'),
                ($quarter * 3) + 1,
            ));
    }

    private function calendarQuarter(string $date): string
    {
        $value = new \DateTimeImmutable($date);
        return $value->format('Y') . '-Q' . (intdiv((int) $value->format('n') - 1, 3) + 1);
    }
}
