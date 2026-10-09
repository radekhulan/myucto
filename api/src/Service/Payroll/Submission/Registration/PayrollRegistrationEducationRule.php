<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;

/**
 * Nejvyšší dosažené vzdělání (`fact/@highedu`, ID 10091) u dohod.
 *
 * Zásady REGZEC 1.4.6, atribut 10091: u občanů ČR (10067 = CZ) s druhem
 * činnosti A až J (DPČ) nebo T až ZC (DPP) se vzdělání nesleduje a uvede
 * se Z = Nerelevantní. Cizinci s dohodou vzdělání uvádějí normálně.
 *
 * Je to pokyn k vyplnění, ne logická kontrola EDV (ta u 10091 hlídá jen
 * číselník KKOV) a ČSSZ věty s jiným kódem přijímá. Návrh profilu proto
 * „Z" dosadí, jinou hodnotu ale sestavení neodmítne, jen na ni upozorní:
 * převzatý profil s přijatým vzděláním musí jít podat dál.
 */
final class PayrollRegistrationEducationRule
{
    public const NOT_RELEVANT = 'Z';

    /** @return array{code:string,field:string,message:string}|null */
    public static function warning(
        ?string $citizenship,
        ?string $activityCode,
        mixed $education,
    ): ?array {
        if (!is_string($education) || $education === ''
            || $education === self::NOT_RELEVANT
            || !self::mustBeNotRelevant($citizenship, $activityCode)
        ) {
            return null;
        }

        return [
            'code' => 'registration_education_not_relevant',
            'field' => 'facts.highest_education_code',
            'message' => 'U občana ČR s dohodou (druh činnosti „' . $activityCode
                . '") se nejvyšší dosažené vzdělání nesleduje a podle pokynů'
                . ' k REGZEC se uvádí „Z" (nerelevantní), teď je „' . $education
                . '". ČSSZ registraci přijme i tak; při nové registraci'
                . ' zvolte „Z".',
        ];
    }

    public static function mustBeNotRelevant(?string $citizenship, ?string $activityCode): bool
    {
        return $citizenship === 'CZ'
            && (
                ($activityCode !== null && preg_match('/^[A-J]$/D', $activityCode) === 1)
                || PayrollEmploymentJmhzActivityFamily::isAgreementToCompleteJobActivity($activityCode)
            );
    }
}
