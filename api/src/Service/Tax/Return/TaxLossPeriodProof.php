<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax\Return;

use MyInvoice\Service\Tax\Nonprofit\NonprofitTaxRules;

final class TaxLossPeriodProof
{
    public static function native(int $supplierId, string $type, int $year, array $period, float $amount, ?int $returnId): array
    {
        $proof = [
            'version' => 1,
            'kind' => 'native_final',
            'supplier_id' => $supplierId,
            'taxpayer_type' => $type,
            'origin_year' => $year,
            'starts_on' => $period['starts_on'] ?? null,
            'ends_on' => $period['ends_on'] ?? null,
            'amount_cents' => TaxLossAllocationPlan::cents($amount),
            'source_return_id' => $returnId,
        ];
        self::assertCalendar($proof);
        return $proof + ['proof_hash' => NonprofitTaxRules::hash($proof)];
    }

    public static function verifyRow(int $supplierId, string $type, array $row): void
    {
        $proof = [
            'version' => 1,
            'kind' => $row['period_proof_kind'] ?? null,
            'supplier_id' => $supplierId,
            'taxpayer_type' => $type,
            'origin_year' => (int) ($row['origin_year'] ?? 0),
            'starts_on' => $row['origin_starts_on'] ?? null,
            'ends_on' => $row['origin_ends_on'] ?? null,
            'amount_cents' => TaxLossAllocationPlan::cents($row['amount'] ?? 0),
            'source_return_id' => $row['source_return_id'] === null ? null : (int) $row['source_return_id'],
        ];
        self::assertCalendar($proof);
        if (!is_string($row['period_proof_hash'] ?? null) || !hash_equals($row['period_proof_hash'], NonprofitTaxRules::hash($proof))) {
            self::invalid();
        }
    }

    private static function assertCalendar(array $proof): void
    {
        $year = $proof['origin_year'] ?? 0;
        if (($proof['kind'] ?? null) !== 'native_final' || !is_int($year) || $year < 2000
            || ($proof['starts_on'] ?? null) !== sprintf('%04d-01-01', $year)
            || ($proof['ends_on'] ?? null) !== sprintf('%04d-12-31', $year)
            || !in_array($proof['taxpayer_type'] ?? null, ['fo', 'po'], true)
            || !is_int($proof['source_return_id'] ?? null) || $proof['source_return_id'] <= 0
            || bccomp((string) ($proof['amount_cents'] ?? '-1'), '0', 0) <= 0) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new TaxReturnException('loss_appendix_period_proof_required', 'Příloha daňových ztrát vyžaduje neměnný podklad přesného kalendářního období a finálního přiznání, které ztrátu stanovilo.', 422);
    }
}
