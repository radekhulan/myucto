<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Overtime;

use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetDomain;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Čtecí cesta k limitům § 93 přes registr rulesetů.
 *
 * Projekt má tvrdé pravidlo, že zákonné hranice žijí v rulesetu a ne v kódu.
 * Chybějící, neúčinný, ručně-posuzovaný nebo špatně typovaný parametr proto
 * výpočet zastaví. Zákonný limit nelze bezpečně nahradit „poslední známou"
 * konstantou: po změně práva by tak kontrola tiše hlásila špatný stav.
 *
 * Očekávané klíče (doména `employment_thresholds`, typ `integer`):
 *   overtime.ordered.weekly_max_minutes            480   (§ 93 odst. 2)
 *   overtime.ordered.yearly_max_minutes           9000   (§ 93 odst. 2)
 *   overtime.averaging.weekly_average_max_minutes  480   (§ 93 odst. 4)
 *   overtime.averaging.max_weeks                    26   (§ 93 odst. 4)
 *   overtime.annual.early_warning_basis_points     8000   (bez opory v zákoně,
 *                                                          jen práh upozornění)
 */
final class OvertimeLimitRules
{
    public const KEY_WEEKLY = 'overtime.ordered.weekly_max_minutes';
    public const KEY_YEARLY = 'overtime.ordered.yearly_max_minutes';
    public const KEY_AVERAGING_WEEKLY = 'overtime.averaging.weekly_average_max_minutes';
    public const KEY_AVERAGING_WEEKS = 'overtime.averaging.max_weeks';
    public const KEY_EARLY_WARNING = 'overtime.annual.early_warning_basis_points';

    /**
     * Registr je POVINNÝ — jako volitelný parametr by ho PHP-DI nevyplnilo a
     * hlídání by tiše počítalo z vestavěných hodnot místo z administrátorského
     * nastavení. Hlídá to i architektonická brána
     * {@see \MyInvoice\Tests\Architecture\PayrollRulesetSingleSourceGuardTest}.
     */
    public function __construct(
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    public function forDate(string $date): OvertimeLimits
    {
        $version = $this->rulesets->forCalculation(
            PayrollRulesetDomain::EmploymentThresholds,
            $date,
        );
        $read = static function (string $key) use ($version): int {
            $parameter = $version->parameter($key);
            if ($parameter->type !== 'integer' || !is_int($parameter->value)) {
                throw new PayrollRulesetException(
                    "Parametr rulesetu {$key} musí být celé číslo.",
                );
            }

            return $parameter->value;
        };

        return new OvertimeLimits(
            $read(self::KEY_WEEKLY),
            $read(self::KEY_YEARLY),
            $read(self::KEY_AVERAGING_WEEKLY),
            $read(self::KEY_AVERAGING_WEEKS),
            $read(self::KEY_EARLY_WARNING),
            true,
        );
    }
}
