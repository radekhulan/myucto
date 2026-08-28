<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\RoundingMode;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Zákonné příplatky § 115 až § 118 ZP z hodin zadaných RUČNĚ, bez docházky.
 *
 * ── Proč se nestaví {@see PayrollSurchargeSegment} ───────────────────────────
 *
 * Nabízelo by se postavit z ručně zadaných hodin segmenty a pustit na ně
 * {@see PayrollSurchargeCalculator}. Nejde to, a není to detail:
 *
 *  - Segment je JEDEN DEN a má tvrdý strop 1 440 minut. Ruční zadání je naopak
 *    MĚSÍČNÍ souhrn — sto hodin noční práce se do segmentu nevejde a rozpustit
 *    je do vymyšlených dnů by znamenalo tvrdit v auditní stopě data, která
 *    uživatel nikdy nezadal.
 *  - Segment nese minuty, ruční zadání milihodiny. Milihodina je 0,06 minuty,
 *    takže převod není bezezbytkový a zaokrouhlení na minuty by se zadaným
 *    hodinám nerovnalo.
 *
 * Znovu se proto používá to, co znovupoužitelné JE a kde by druhá pravda
 * bolela: sazby a základy ze sady ({@see PayrollSurchargeRuleset}), sjednané
 * odchylky ({@see PayrollSurchargePolicy}), minimální mzda pro § 117
 * ({@see MinimumWageFloor}) a mapování na mzdové složky
 * ({@see PayrollSurchargeKind::componentCode()}). Vlastní je jen aritmetika nad
 * milihodinami, a ta je záměrně týž JEDEN zlomek jako v
 * {@see PayrollSurchargeLine} — jen s jmenovatelem 1 000 (milihodiny) místo 60
 * (minuty).
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 *
 * Chybí-li podklad, vyhodí to {@see PayrollSurchargeException} s hláškou, co
 * doplnit. Nikdy nula: nulový příplatek na mzdovém listu tvrdí, že nárok byl
 * posouzen a nevznikl, což je něco jiného než „nešlo ho spočítat".
 */
final class PayrollQuickSurchargeCalculator
{
    /** Strop ručně zadaných hodin za měsíc v milihodinách (744 h = nejdelší měsíc). */
    public const MAX_HOURS_MILLI = 744_000;

    public const SCHEMA_VERSION = 'payroll-quick-surcharge-source.v1';

    /**
     * Základní hodinová minimální mzda podle období.
     *
     * Bez téhle paměti by se sada pravidel četla pro KAŽDÝ řádek stránky —
     * u dvou set vztahů dvě stě průchodů kvůli jednomu číslu, které je pro celý
     * měsíc stejné. Klíčem je období, takže se nedá splést měsíc s měsícem.
     *
     * @var array<string,?int>
     */
    private array $minimumWageCache = [];

    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * Lze druh za daných podmínek vůbec ručně zadat, a když ne, proč?
     *
     * Vrací se to VŽDY, i pro nedostupný druh, protože formulář musí umět říct
     * důvod. Šedé pole bez vysvětlení posílá uživatele hádat, a u zákonného
     * nároku je to horší než chybová hláška.
     *
     * @param int $averageHourlyMinor schválený hodinový průměrný výdělek; 0 = žádný
     * @return array{
     *   available:bool, reason:?string, section:string, component_code:string,
     *   basis:string, basis_hourly_minor:?int, rate_basis_points:?int,
     *   rate_is_agreed:bool, requires_factors:bool, default_factors:?int
     * }
     */
    public function availability(
        PayrollSurchargeKind $kind,
        string $periodStart,
        PayrollSurchargePolicy $policy,
        PayrollSurchargeRuleset $ruleset,
        int $averageHourlyMinor,
    ): array {
        $basis = $ruleset->basis($kind);
        $effective = $policy->effectiveRate($kind, $ruleset);
        $basisHourly = $this->basisHourlyOrNull($basis, $periodStart, $averageHourlyMinor);
        $requiresFactors = $kind === PayrollSurchargeKind::DifficultEnvironment;
        $shape = [
            'section' => $kind->section(),
            'component_code' => $kind->componentCode(),
            'basis' => $basis->value,
            'basis_hourly_minor' => $basisHourly,
            'rate_basis_points' => self::basisPoints($effective['rate']),
            'rate_is_agreed' => $effective['agreed'],
            'requires_factors' => $requiresFactors,
            'default_factors' => $policy->difficultEnvironmentFactors,
        ];

        $reason = $this->unavailableReason($kind, $policy, $basisHourly);

        return ['available' => $reason === null, 'reason' => $reason, ...$shape];
    }

    /**
     * Důvod, proč druh zadat nejde — nebo `null`, když jde.
     *
     * Pořadí je pořadím toho, co má uživatel vyřešit dřív: bez sjednané zásady
     * se u svátku nemá o čem počítat, teprve pak dává smysl ptát se na základ.
     */
    private function unavailableReason(
        PayrollSurchargeKind $kind,
        PayrollSurchargePolicy $policy,
        ?int $basisHourly,
    ): ?string {
        if (!$kind->allowsQuickManualEntry()) {
            return 'kind_not_manually_enterable';
        }
        if ($kind === PayrollSurchargeKind::Holiday) {
            // § 115 odst. 1 dává jako VÝCHOZÍ náhradní volno. Bez sjednané
            // zásady podle odst. 2 tedy příplatek nenáleží a zpřístupnit pole
            // by znamenalo nabídnout vyplacení něčeho, na co nárok není.
            if ($policy->isStatutoryDefault) {
                return 'holiday_arrangement_missing';
            }
            if ($policy->mode($kind) === PayrollSurchargeCompensationMode::CompensatoryTimeOff) {
                return 'holiday_compensatory_time_off';
            }
        }
        if ($policy->mode($kind) === PayrollSurchargeCompensationMode::IncludedInWage) {
            // § 114 odst. 3. Sem se dostane jen přesčas a ten se ručně nezadává,
            // ale kdyby zásada takový režim někdy dovolila i jinde, nesmí to
            // skončit tichým výpočtem.
            return 'wage_includes_surcharge';
        }
        if ($basisHourly === null || $basisHourly <= 0) {
            return 'basis_missing';
        }

        return null;
    }

    /**
     * Částka příplatku za ručně zadané hodiny, i s auditní stopou.
     *
     * @param int $milliHours počet hodin v milihodinách
     * @param int|null $factors počet ztěžujících vlivů § 117; jinde `null`
     * @param array{id:?int,row_version:?int} $averageSnapshot identifikace
     *        schváleného průměru, aby se z mzdového listu poznalo, z čeho se
     *        počítalo; u § 117 (základ je minimální mzda) zůstává prázdná
     * @return array{amount_minor:int, source:array<string,mixed>}
     */
    public function calculate(
        PayrollSurchargeKind $kind,
        string $periodStart,
        PayrollSurchargePolicy $policy,
        PayrollSurchargeRuleset $ruleset,
        int $averageHourlyMinor,
        int $milliHours,
        ?int $factors,
        array $averageSnapshot = ['id' => null, 'row_version' => null],
    ): array {
        if ($milliHours <= 0 || $milliHours > self::MAX_HOURS_MILLI) {
            throw PayrollSurchargeException::of(
                'invalid_hours',
                sprintf(
                    'Počet hodin příplatku %s musí být kladný a nejvýše %d.',
                    $kind->section(),
                    intdiv(self::MAX_HOURS_MILLI, 1_000),
                ),
            );
        }

        $basis = $ruleset->basis($kind);
        $basisHourly = $this->basisHourlyOrNull($basis, $periodStart, $averageHourlyMinor);
        $reason = $this->unavailableReason($kind, $policy, $basisHourly);
        if ($reason !== null || $basisHourly === null) {
            throw PayrollSurchargeException::of($reason ?? 'basis_missing', match ($reason) {
                'holiday_arrangement_missing' => sprintf(
                    'Za práci ve svátek se podle § 115 odst. 1 poskytuje náhradní volno; '
                    . 'příplatek náleží jen tehdy, byl-li sjednán. U tohoto pracovního '
                    . 'vztahu sjednán není, takže hodiny o svátku (%s) tu zadat nelze — '
                    . 'nejdřív doplňte zásadu příplatků na kartě vztahu.',
                    $periodStart,
                ),
                'holiday_compensatory_time_off' =>
                    'U tohoto pracovního vztahu je za práci ve svátek sjednáno náhradní '
                    . 'volno (§ 115 odst. 1), ne příplatek. Vyplatit ho vedle volna by '
                    . 'byl týž nárok dvakrát.',
                'wage_includes_surcharge' =>
                    'U tohoto pracovního vztahu je mzda sjednána už s přihlédnutím '
                    . 'k této práci (§ 114 odst. 3), takže příplatek nenáleží.',
                'kind_not_manually_enterable' =>
                    'Tenhle druh příplatku se v rychlém měsíčním vstupu ručně nezadává.',
                default => sprintf(
                    'Příplatek %s se počítá z veličiny „%s", která pro období %s není '
                    . 'zjištěná. Doplňte ji a výpočet zopakujte.',
                    $kind->section(),
                    $basis->label(),
                    $periodStart,
                ),
            });
        }

        $factors = $this->factorsFor($kind, $policy, $factors);
        $effective = $policy->effectiveRate($kind, $ruleset);
        $weighted = self::multiplyExactly($milliHours, $factors);

        // Jeden zlomek, jedno zaokrouhlení — jako {@see PayrollSurchargeLine}.
        // Jmenovatel je 1 000, protože se násobí MILIhodinami; tamní 60 patří
        // k minutám. Dvě zaokrouhlení (nejdřív hodinová sazba, pak násobek) by
        // se přes sto hodin měsíčně sečetla v neprospěch zaměstnance.
        $numerator = self::multiplyExactly(
            self::multiplyExactly($basisHourly, $effective['rate']->numerator),
            $weighted,
        );
        $denominator = self::multiplyExactly($effective['rate']->denominator, 1_000);
        $amount = RoundingMode::HalfUp->roundFraction($numerator, $denominator);

        return [
            'amount_minor' => $amount,
            'source' => [
                'schema_version' => self::SCHEMA_VERSION,
                'surcharge_kind' => $kind->value,
                'section' => $kind->section(),
                'component_code' => $kind->componentCode(),
                'entry_source' => 'quick_manual',
                'period_start' => $periodStart,
                'basis' => $basis->value,
                'basis_hourly_minor' => $basisHourly,
                'average_snapshot_id' => $basis === PayrollSurchargeBasis::AverageEarning
                    ? $averageSnapshot['id']
                    : null,
                'average_snapshot_row_version' => $basis === PayrollSurchargeBasis::AverageEarning
                    ? $averageSnapshot['row_version']
                    : null,
                'hours_milli' => $milliHours,
                'difficulty_factors' => $factors,
                'weighted_milli_hours' => $weighted,
                'rate_basis_points' => self::basisPoints($effective['rate']),
                'rate_is_agreed' => $effective['agreed'],
                'compensation_mode' => $policy->mode($kind)->value,
                'ruleset_id' => $ruleset->version->id,
                'ruleset_content_hash' => $ruleset->version->contentHash,
                'unrounded_numerator' => $numerator,
                'unrounded_denominator' => $denominator,
                'rounding' => 'half-up-minor-unit',
                'amount_minor' => $amount,
            ],
        ];
    }

    /**
     * Počet ztěžujících vlivů podle § 117 („za každý ztěžující vliv").
     *
     * Zadaný počet má přednost před tím na zásadě: zásada říká, co je na
     * pracovišti obvyklé, kdežto zadání říká, co bylo v TOMHLE měsíci. Chybí-li
     * obojí, je to fail-closed — vlivy neurčuje zákon, ale nařízení vlády
     * a konkrétní pracoviště, a odhadnout je nelze.
     */
    private function factorsFor(
        PayrollSurchargeKind $kind,
        PayrollSurchargePolicy $policy,
        ?int $factors,
    ): int {
        if ($kind !== PayrollSurchargeKind::DifficultEnvironment) {
            return 1;
        }
        $value = $factors ?? $policy->difficultEnvironmentFactors;
        if ($value === null || $value < 1) {
            throw PayrollSurchargeException::of(
                'difficulty_factors_missing',
                'Příplatek § 117 náleží za KAŽDÝ ztěžující vliv, takže bez jejich počtu '
                . 'ho spočítat nelze. Doplňte počet vlivů u zadaných hodin, nebo obvyklý '
                . 'počet v zásadě příplatků na kartě pracovního vztahu.',
            );
        }
        if ($value > 255) {
            throw PayrollSurchargeException::of(
                'difficulty_factors_invalid',
                'Počet ztěžujících vlivů podle § 117 musí být 1 až 255.',
            );
        }

        return $value;
    }

    private function basisHourlyOrNull(
        PayrollSurchargeBasis $basis,
        string $periodStart,
        int $averageHourlyMinor,
    ): ?int {
        return match ($basis) {
            PayrollSurchargeBasis::AverageEarning => $averageHourlyMinor > 0
                ? $averageHourlyMinor
                : null,
            PayrollSurchargeBasis::MinimumWageHourly => $this->minimumWageHourly($periodStart),
        };
    }

    /**
     * § 117 odst. 2 mluví o ZÁKLADNÍ sazbě minimální mzdy, tedy o sazbě pro
     * čtyřicetihodinový týden. Přepočet na kratší úvazek patří k § 357, ne sem:
     * příplatek za ztížené prostředí je kompenzace vlivu prostředí, ne odměna
     * za odpracovaný čas.
     *
     * Chybějící nebo neúplná sada pravidel se překládá na `null`, ne na výjimku.
     * Volající si to vyloží jako chybějící podklad — a to je pravda. Kdyby to
     * letělo výš, jedno prázdné místo v sadě by zavřelo celý seznam rychlého
     * zadání, včetně lidí, kterých se § 117 vůbec netýká. Fail-closed to
     * zůstává: bez základu se příplatek nespočítá, jen se to řekne u pole místo
     * na celé obrazovce.
     */
    private function minimumWageHourly(string $periodStart): ?int
    {
        if (!array_key_exists($periodStart, $this->minimumWageCache)) {
            try {
                $this->minimumWageCache[$periodStart] = MinimumWageFloor::forDate(
                    $this->rulesets,
                    $periodStart,
                )->baseHourlyMinor;
            } catch (\Throwable) {
                $this->minimumWageCache[$periodStart] = null;
            }
        }

        return $this->minimumWageCache[$periodStart];
    }

    private static function basisPoints(DecimalRate $rate): int
    {
        return RoundingMode::HalfUp->roundFraction(
            self::multiplyExactly($rate->numerator, 10_000),
            $rate->denominator,
        );
    }

    private static function multiplyExactly(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw PayrollSurchargeException::of(
                'negative_factor',
                'Výpočet příplatku nepracuje se zápornými činiteli.',
            );
        }
        if ($left !== 0 && $right > intdiv(PHP_INT_MAX, $left)) {
            throw PayrollSurchargeException::of(
                'overflow',
                'Výpočet příplatku překročil celočíselný rozsah.',
            );
        }

        return $left * $right;
    }
}
