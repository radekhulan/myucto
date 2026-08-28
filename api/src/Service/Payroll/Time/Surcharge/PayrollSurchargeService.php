<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use DateTimeImmutable;
use DateTimeZone;
use MyInvoice\Repository\Payroll\PayrollSurchargeRepository;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetProvider;

/**
 * Spojí evidenci docházky, sjednanou zásadu a průměrný výdělek do částek.
 *
 * Jediná vrstva téhle skupiny tříd, která mluví s databází. Výpočet
 * ({@see PayrollSurchargeCalculator}) i zjištění skutkového stavu
 * ({@see PayrollSurchargeEvidence}) zůstávají čisté, aby se hraniční případy
 * daly testovat bez schématu.
 *
 * ── Průměrný výdělek se sem PŘEDÁVÁ ─────────────────────────────────────────
 *
 * Neurčuje se tady. Zjišťuje ho {@see \MyInvoice\Service\Payroll\Absence\AverageEarningCalculator}
 * z rozhodného období a schvaluje ho účetní; kdyby si ho příplatky počítaly
 * samy, měl by mzdový list dvě různá čísla pro totéž a nikdo by nepoznal, které
 * z nich je to schválené.
 */
final class PayrollSurchargeService
{
    public function __construct(
        private readonly PayrollSurchargeRepository $repository,
        private readonly PayrollSurchargeEvidence $evidence,
        private readonly PayrollSurchargeCalculator $calculator,
        private readonly PayrollRulesetProvider $rulesets,
    ) {}

    /**
     * @param string|null $assessedOn den posouzení lhůty § 114 odst. 2; výchozí
     *        je poslední den zpracovávaného měsíce, ne „dnes" — mzda za červen
     *        se nesmí lišit podle toho, kdy se přepočte.
     */
    public function forPeriod(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $averageHourlyMinor,
        ?string $assessedOn = null,
    ): PayrollSurchargeResult {
        if (preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            throw PayrollSurchargeException::of(
                'invalid_period',
                'Období příplatků musí být první den měsíce.',
            );
        }

        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($periodStart . ' 00:00:00', $utc);
        $end = $start->modify('+1 month');
        // Okno se rozšiřuje o dva dny na každou stranu: zápis se do měsíce řadí
        // podle MÍSTNÍHO začátku, ale ukládá se v UTC, takže poslední noční
        // směna měsíce může mít UTC začátek až v následujícím dni a naopak.
        // Vlastní zúžení na měsíc dělá evidence nad místním datem.
        $windowFrom = $start->modify('-2 days')->format('Y-m-d H:i:s');
        $windowTo = $end->modify('+2 days')->format('Y-m-d H:i:s');
        $periodEnd = $end->modify('-1 day')->format('Y-m-d');

        $entries = $this->repository->entries($supplierId, $employmentId, $windowFrom, $windowTo);
        $compensations = $this->repository->overtimeCompensations(
            $supplierId,
            $employmentId,
            $periodStart,
            $periodEnd,
        );

        $ruleset = PayrollSurchargeRuleset::forDate($this->rulesets, $periodStart);
        $policy = $this->policy($supplierId, $employmentId, $periodStart, $ruleset);

        $evidence = $this->evidence->collect(
            $entries,
            $compensations,
            $policy,
            $ruleset,
            $periodStart,
            $assessedOn ?? $periodEnd,
        );

        return $this->calculator->calculate(
            $periodStart,
            $averageHourlyMinor,
            $policy,
            $evidence->segments,
            $evidence->findings,
        );
    }

    /**
     * Sjednaná zásada příplatků účinná k danému dni.
     *
     * Veřejná proto, že zásadu potřebuje i rychlé zadání mzdy: přesčas zadaný
     * hodinami se musí počítat TOUTÉŽ sazbou a v TOMTÉŽ režimu odměnění jako
     * přesčas z docházky. Dokud si rychlé zadání drželo 25 % natvrdo, sjednaná
     * vyšší sazba se do něj nepropsala a dvě obrazovky téže firmy tvrdily
     * o témž nároku dvě různá čísla.
     */
    public function policyFor(
        int $supplierId,
        int $employmentId,
        string $effectiveOn,
        PayrollSurchargeRuleset $ruleset,
    ): PayrollSurchargePolicy {
        return $this->policy($supplierId, $employmentId, $effectiveOn, $ruleset);
    }

    private function policy(
        int $supplierId,
        int $employmentId,
        string $effectiveOn,
        PayrollSurchargeRuleset $ruleset,
    ): PayrollSurchargePolicy {
        $row = $this->repository->policy($supplierId, $employmentId, $effectiveOn);
        if ($row === null) {
            return PayrollSurchargePolicy::statutoryDefault();
        }

        $overtime = PayrollSurchargeCompensationMode::tryFrom(
            is_string($row['overtime_mode'] ?? null) ? $row['overtime_mode'] : '',
        );
        $holiday = PayrollSurchargeCompensationMode::tryFrom(
            is_string($row['holiday_mode'] ?? null) ? $row['holiday_mode'] : '',
        );
        if ($overtime === null || $holiday === null) {
            throw PayrollSurchargeException::of(
                'policy_corrupt',
                'Zásada příplatků pracovního vztahu obsahuje neznámý režim odměnění.',
            );
        }

        return PayrollSurchargePolicy::agreed(
            $overtime,
            $holiday,
            $this->nullableInt($row, 'difficult_environment_factors'),
            [
                PayrollSurchargeKind::Overtime->value => $this->nullableInt($row, 'overtime_rate_bp'),
                PayrollSurchargeKind::Holiday->value => $this->nullableInt($row, 'holiday_rate_bp'),
                PayrollSurchargeKind::Night->value => $this->nullableInt($row, 'night_rate_bp'),
                PayrollSurchargeKind::Weekend->value => $this->nullableInt($row, 'weekend_rate_bp'),
                PayrollSurchargeKind::DifficultEnvironment->value =>
                    $this->nullableInt($row, 'difficult_environment_rate_bp'),
            ],
            $ruleset,
        );
    }

    /** @param array<string,mixed> $row */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
