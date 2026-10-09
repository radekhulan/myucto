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
 */
final class PayrollRegistrationEducationRule
{
    public const NOT_RELEVANT = 'Z';

    public static function mustBeNotRelevant(?string $citizenship, ?string $activityCode): bool
    {
        return $citizenship === 'CZ'
            && (
                ($activityCode !== null && preg_match('/^[A-J]$/D', $activityCode) === 1)
                || PayrollEmploymentJmhzActivityFamily::isAgreementToCompleteJobActivity($activityCode)
            );
    }
}
