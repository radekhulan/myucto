<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Zjistí, jestli otevřená revize počítá se zastaralými podklady.
 *
 * Aktuální stav čte {@see PayrollRunSnapshotBuilder::currentSources()} —
 * tytéž dotazy a táž normalizace jako při stavbě snímku, takže „změněno"
 * znamená přesně „nový snímek by vypadal jinak", ne vlastní výklad.
 */
final class PayrollRunSourceDriftDetector
{
    /** Části aktuálních podkladů klíčované id vztahu. */
    private const EMPLOYMENT_KEYED = [
        'employment_facts',
        'worked_time_evidence',
        'hourly_entitlement_context',
        'hourly_average_input_sources',
        'hourly_wage_claims',
        'manual_time_month_state',
        'hourly_remuneration_terms',
        'hourly_settlement_terms',
        'inputs',
        'absences',
        'average_earnings',
    ];

    public function __construct(
        private readonly PayrollRunSnapshotBuilder $builder,
    ) {}

    /**
     * Vztahy osob, které účetní z běhu vyřadila ({@see PayrollRunExclusionFilter}),
     * se neporovnávají: snímek je vědomě nemá, takže by je kontrola jinak
     * hlásila jako nové a zastavila schválení.
     *
     * @param array<string,mixed> $inputSnapshot
     * @param list<int> $excludedEmploymentIds
     */
    public function detect(int $supplierId, array $inputSnapshot, array $excludedEmploymentIds = []): PayrollRunSourceDrift
    {
        $periodStart = $inputSnapshot['period_start'] ?? null;
        $paymentDate = $inputSnapshot['payment_date'] ?? null;
        $officeId = $inputSnapshot['office_id'] ?? null;
        if (!is_string($periodStart) || !is_string($paymentDate)
            || ($officeId !== null && !is_int($officeId))
        ) {
            // Snímek bez období nebo data výplaty (vznikl ve starší verzi) nejde
            // porovnat s aktuálním stavem. Detekce je jen pojistka navíc — neumí-li
            // snímek porovnat, nesmí zablokovat schválení ani seznam běhů.
            return new PayrollRunSourceDrift();
        }

        $employmentIds = [];
        $employeeIds = [];
        $people = is_array($inputSnapshot['people'] ?? null) ? $inputSnapshot['people'] : [];
        foreach ($people as $person) {
            if (!is_array($person) || !is_array($person['employee'] ?? null)) {
                continue;
            }
            $employeeIds[] = (int) ($person['employee']['id'] ?? 0);
            $employments = is_array($person['employments'] ?? null) ? $person['employments'] : [];
            foreach ($employments as $employment) {
                if (is_array($employment) && is_array($employment['employment'] ?? null)) {
                    $employmentIds[] = (int) ($employment['employment']['id'] ?? 0);
                }
            }
        }

        $current = $this->builder->currentSources(
            $supplierId,
            $periodStart,
            $paymentDate,
            $officeId,
            $employmentIds,
            $employeeIds,
        );
        if ($excludedEmploymentIds !== []) {
            $current['eligible_employment_ids'] = array_values(array_diff(
                $current['eligible_employment_ids'],
                $excludedEmploymentIds,
            ));
            $excluded = array_fill_keys($excludedEmploymentIds, true);
            foreach (self::EMPLOYMENT_KEYED as $key) {
                if (is_array($current[$key] ?? null)) {
                    $current[$key] = array_diff_key($current[$key], $excluded);
                }
            }
        }

        return PayrollRunSourceDrift::between($inputSnapshot, $current);
    }
}
