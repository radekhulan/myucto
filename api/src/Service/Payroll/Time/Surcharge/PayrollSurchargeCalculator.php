<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use MyInvoice\Service\Payroll\Absence\MinimumWageFloor;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Zákonné příplatky ke mzdě podle § 114 až § 118 zákoníku práce.
 *
 * Deterministický a bez závislosti na databázi: dostane doloženou dobu
 * ({@see PayrollSurchargeEvidence}), hodinový průměrný výdělek a sjednanou
 * zásadu, vrátí částky se stopou. Kdo doloženou dobu opatří, je věc volajícího.
 *
 * ── Základy ─────────────────────────────────────────────────────────────────
 *
 *  - Průměrný výdělek (§ 114, § 115, § 116, § 118) přichází zvenčí. Nepočítá se
 *    tady schválně: zjišťuje ho {@see \MyInvoice\Service\Payroll\Absence\AverageEarningCalculator}
 *    z rozhodného období, už s podlahou minimální mzdy podle § 357 odst. 1,
 *    a druhý výpočet téhož čísla by byl druhá pravda.
 *  - Minimální mzda (§ 117) se bere ze {@see MinimumWageFloor}, a to ZÁKLADNÍ
 *    SAZBA pro čtyřicetihodinový týden (`baseHourlyMinor`), ne přepočtená na
 *    kratší úvazek. § 117 odst. 2 mluví o „základní sazbě minimální mzdy";
 *    přepočet podle § 79 patří k § 357, ne sem. Příplatek za ztížené prostředí
 *    je stejný pro plný i pro poloviční úvazek — je to kompenzace vlivu
 *    prostředí, ne odměna za odpracovaný čas.
 *
 * ── Zaokrouhlení ────────────────────────────────────────────────────────────
 *
 * Jednou za druh příplatku a měsíc, half-up na haléř, z JEDNOHO nezaokrouhleného
 * zlomku — viz {@see PayrollSurchargeLine}. Ne po hodinách a ne po dnech:
 * u dvousměnného provozu je to přes stovku hodin měsíčně a chyba by se sečetla.
 */
final class PayrollSurchargeCalculator
{
    public function __construct(private readonly PayrollRulesetProvider $rulesets) {}

    /**
     * @param list<PayrollSurchargeSegment> $segments z {@see PayrollSurchargeEvidenceResult::$segments}
     * @param list<array{reason:string,message:string,local_date:?string}> $findings
     */
    public function calculate(
        string $periodStart,
        int $averageHourlyMinor,
        PayrollSurchargePolicy $policy,
        array $segments,
        array $findings = [],
    ): PayrollSurchargeResult {
        if (preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            throw PayrollSurchargeException::of(
                'invalid_period',
                'Období příplatků musí být první den měsíce.',
            );
        }

        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
        $minimumWage = MinimumWageFloor::forDate($this->rulesets, $periodStart);

        /** @var array<string,list<PayrollSurchargeSegment>> $byKind */
        $byKind = [];
        foreach ($segments as $segment) {
            $byKind[$segment->kind->value][] = $segment;
        }

        $lines = [];
        $total = 0;
        foreach (PayrollSurchargeKind::all() as $kind) {
            $kindSegments = $byKind[$kind->value] ?? [];
            if ($kindSegments === []) {
                continue;
            }
            $basis = $ruleset->basis($kind);
            $basisHourly = match ($basis) {
                PayrollSurchargeBasis::AverageEarning => $averageHourlyMinor,
                PayrollSurchargeBasis::MinimumWageHourly => $minimumWage->baseHourlyMinor,
            };
            if ($basis === PayrollSurchargeBasis::AverageEarning && $averageHourlyMinor <= 0) {
                throw PayrollSurchargeException::of(
                    'average_earning_missing',
                    sprintf(
                        'Příplatek %s se počítá z průměrného výdělku, který pro období %s '
                        . 'není zjištěný. Zjistěte ho a výpočet zopakujte.',
                        $kind->section(),
                        $periodStart,
                    ),
                );
            }
            $effective = $policy->effectiveRate($kind, $ruleset);
            $line = PayrollSurchargeLine::calculate(
                $kind,
                $basis,
                $basisHourly,
                $effective['rate'],
                $effective['agreed'],
                $kindSegments,
            );
            $lines[] = $line;
            $total += $line->amountMinor;
        }

        return new PayrollSurchargeResult(
            $periodStart,
            $total,
            $lines,
            $findings,
            $ruleset->version->id,
            $ruleset->version->contentHash,
        );
    }
}
