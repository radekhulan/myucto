<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\SocialInsurance;

use MyInvoice\Service\Payroll\Employment\PayrollRelationType;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Založí vztah účast na pojištění už při nástupu — bez spočítané mzdy?
 *
 * Jediné pravidlo pro oznámení nástupu zdravotní pojišťovně (HOZ) i pro
 * varování běhu o chybějící přihlášce (registraci ČSSZ navíc dluží každá DPP
 * a DPČ, {@see self::registrationExpected()}). Rozhoduje ÚČAST NA POJIŠTĚNÍ z podmínek
 * vztahu:
 *
 *  - `included` ano, `excluded` a `foreign` ne,
 *  - `automatic` u pracovního poměru ano,
 *  - `automatic` u DPČ, zaměstnání malého rozsahu a člena orgánu ano, když
 *    SJEDNANÝ měsíční příjem dosahuje rozhodného příjmu — přesně jako výpočet
 *    ({@see SocialParticipationResolver}, důvod `agreed_income_threshold_met`),
 *    který takový vztah pojistí bez ohledu na skutečný příjem měsíce. Dřív tu
 *    platilo „dohoda = rozhodne až běh", takže oznámení nástupu DPČ s odměnou
 *    nad hranicí šlo připravit až nad mzdovým během, po lhůtě osmi dnů,
 *  - `automatic` u DPP ne: účast rozhoduje úhrn všech DPP za měsíc, ten při
 *    nástupu znát nejde.
 */
final class PayrollExpectedParticipation
{
    private const RULESET_KEY = 'participation.small_scale.minimum';

    /** Od JMHZ (zákon č. 323/2025 Sb.) patří do evidence zaměstnanců u ČSSZ každá DPP a DPČ. */
    public const AGREEMENT_REGISTRATION_FROM = '2026-01-01';

    /**
     * Dluží vztah registraci zaměstnance u ČSSZ (REGZEC)?
     *
     * Od 2026 se do evidence zaměstnanců přihlašuje každá DPP a DPČ bez ohledu
     * na účast na pojištění, i dohoda pod rozhodným příjmem a zaměstnanec
     * pojištěný v cizině (§ 3 odst. 1 písm. d) a § 19 zákona č. 323/2025 Sb.).
     * Ostatní vztahy se řídí účastí na pojištění ({@see self::expected()}).
     */
    public static function registrationExpected(
        ?string $participation,
        string $relationType,
        ?int $agreedMonthlyMinor,
        ?int $smallScaleThresholdMinor,
        string $onDate,
    ): bool {
        if ($onDate >= self::AGREEMENT_REGISTRATION_FROM
            && in_array($relationType, [PayrollRelationType::Dpp->value, PayrollRelationType::Dpc->value], true)
        ) {
            return true;
        }

        return self::expected($participation, $relationType, $agreedMonthlyMinor, $smallScaleThresholdMinor);
    }

    public static function expected(
        ?string $participation,
        string $relationType,
        ?int $agreedMonthlyMinor,
        ?int $smallScaleThresholdMinor,
    ): bool {
        $value = $participation ?? 'automatic';
        if ($value === 'included') {
            return true;
        }
        if ($value === 'excluded' || $value === 'foreign') {
            return false;
        }
        try {
            $group = (new SocialRelationshipKindMapper())->fromRelationType($relationType)->aggregationGroup;
        } catch (\InvalidArgumentException) {
            return false;
        }

        return match ($group) {
            SocialParticipationAggregationGroup::RegularRelationship => true,
            SocialParticipationAggregationGroup::SmallScaleCandidate => self::agreedIncomeMeetsThreshold(
                $agreedMonthlyMinor,
                $smallScaleThresholdMinor,
            ) === true,
            default => false,
        };
    }

    /**
     * Dosahuje SJEDNANÝ měsíční příjem rozhodného příjmu? `null`, když sjednaný
     * příjem nebo rozhodný příjem k datu neznáme. Totéž porovnání rozhoduje
     * o příznaku zaměstnání malého rozsahu u DPČ v registraci (REGZEC `sme`).
     */
    public static function agreedIncomeMeetsThreshold(
        ?int $agreedMonthlyMinor,
        ?int $smallScaleThresholdMinor,
    ): ?bool {
        if ($agreedMonthlyMinor === null || $smallScaleThresholdMinor === null) {
            return null;
        }

        return $agreedMonthlyMinor >= $smallScaleThresholdMinor;
    }

    /** Rozhodný příjem k datu z pravidel sociálního pojištění; `null`, když pravidla pro datum nejsou. */
    public static function smallScaleThreshold(PayrollRulesetProvider $rulesets, string $onDate): ?int
    {
        try {
            $parameter = $rulesets->forCalculation(PayrollRulesetDomain::SocialInsurance, $onDate)
                ->parameter(self::RULESET_KEY);
        } catch (\Throwable) {
            return null;
        }

        return $parameter->type === 'money_minor' && is_int($parameter->value) ? $parameter->value : null;
    }
}
