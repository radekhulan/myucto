<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\RiskySavings;

use MyInvoice\Repository\Payroll\PayrollRiskySavingsRepository;

final readonly class PayrollRiskySavingsApprover
{
    public function __construct(private PayrollRiskySavingsRepository $repository) {}

    /** @param array<string,mixed> $result */
    public function storeApproved(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $statutory = $result['statutory'] ?? null;
        if (!is_array($statutory) || array_is_list($statutory)) {
            throw new \DomainException('Schválený běh nemá zákonný výsledek.');
        }
        if (($statutory['status'] ?? null) !== 'calculated') {
            throw new \DomainException(
                'Povinné spoření nelze uložit z neúplného zákonného výpočtu.',
            );
        }
        if (!array_key_exists('risky_savings', $statutory)) {
            return;
        }
        $periodStart = $statutory['risky_savings_period_start'] ?? null;
        $rows = $statutory['risky_savings'] ?? null;
        if (!is_string($periodStart) || !is_array($rows) || !array_is_list($rows)) {
            throw new \DomainException('Výsledek povinného spoření není úplný.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)
                || ($row['status'] ?? null) === 'manual_review'
            ) {
                throw new \DomainException(
                    'Povinné spoření vyžaduje doplnění nebo schválení podkladů.',
                );
            }
        }
        $this->repository->storeApproved(
            $supplierId,
            $revisionId,
            $periodStart,
            $rows,
        );
    }
}
