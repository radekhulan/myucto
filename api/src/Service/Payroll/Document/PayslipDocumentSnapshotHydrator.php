<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Service\Payroll\Component\PayrollExemptionBasis;

final class PayslipDocumentSnapshotHydrator
{
    /**
     * Snapshoty v1 zůstávají čitelné. Nedopočítávají se — podklad nezdanění
     * složky v nich není a zpětně se dá jen hádat, takže se hydratují jako
     * neevidovaný údaj a doklad ho pojmenuje slovem, ne nulou.
     */
    private const SUPPORTED_SCHEMA_VERSIONS = [
        'payroll-payslip-document.v1',
        'payroll-payslip-document.v2',
        'payroll-payslip-document.v3',
    ];

    private const DETAIL_SCHEMA_VERSION = 'payroll-payslip-document.v2';

    /** @param array<string,mixed> $snapshot */
    public function hydrate(
        array $snapshot,
        string $revisionId,
        string $sourceSnapshotHash,
        string $period,
        ?int $revisionNumber = null,
    ): PayslipDocumentData {
        $schemaVersion = $snapshot['schema_version'] ?? null;
        if (!is_string($schemaVersion)
            || !in_array($schemaVersion, self::SUPPORTED_SCHEMA_VERSIONS, true)
        ) {
            throw new \DomainException('Výsledek osoby nemá podporovaný snapshot výplatní pásky.');
        }
        $detailRecorded = $schemaVersion !== 'payroll-payslip-document.v1';
        $hourly = $schemaVersion === 'payroll-payslip-document.v3';
        if ($hourly && (!is_array($snapshot['hourly_claims'] ?? null) || !array_is_list($snapshot['hourly_claims']) || $snapshot['hourly_claims'] === [])) {
            throw new \DomainException('Hodinová páska nemá zmrazené nároky.');
        }

        return new PayslipDocumentData(
            revisionId: $revisionId,
            sourceSnapshotSha256: $sourceSnapshotHash,
            employerName: $this->text($snapshot, 'employer_name'),
            employerIdentificationNumber:
                $this->text($snapshot, 'employer_identification_number'),
            employeeDisplayName: $this->text($snapshot, 'employee_display_name'),
            period: $period,
            employmentLabel: $this->text($snapshot, 'employment_label'),
            incomeLines: $this->lines($snapshot, 'income_lines', $detailRecorded, $hourly),
            grossMinorUnits: $this->integer($snapshot, 'gross_minor_units'),
            employeeSocialMinorUnits:
                $this->integer($snapshot, 'employee_social_minor_units'),
            employeeHealthMinorUnits:
                $this->integer($snapshot, 'employee_health_minor_units'),
            healthMinimumTopUpMinorUnits:
                $this->integer($snapshot, 'health_minimum_top_up_minor_units'),
            taxBaseMinorUnits: $this->integer($snapshot, 'tax_base_minor_units'),
            taxBeforeCreditsMinorUnits:
                $this->integer($snapshot, 'tax_before_credits_minor_units'),
            taxNonRefundableCreditsMinorUnits:
                $this->integer($snapshot, 'tax_non_refundable_credits_minor_units'),
            taxChildCreditMinorUnits:
                $this->integer($snapshot, 'tax_child_credit_minor_units'),
            taxBonusEligible: $this->boolean($snapshot, 'tax_bonus_eligible'),
            taxAfterCreditsMinorUnits:
                $this->integer($snapshot, 'tax_after_credits_minor_units'),
            taxBonusMinorUnits: $this->integer($snapshot, 'tax_bonus_minor_units'),
            otherDeductionLines: $this->lines($snapshot, 'other_deduction_lines', false),
            roundingAdjustmentMinorUnits:
                $this->integer($snapshot, 'rounding_adjustment_minor_units'),
            netMinorUnits: $this->integer($snapshot, 'net_minor_units'),
            employerSocialMinorUnits:
                $this->integer($snapshot, 'employer_social_minor_units'),
            employerHealthMinorUnits:
                $this->integer($snapshot, 'employer_health_minor_units'),
            grossExpenseAccount: $this->text($snapshot, 'gross_expense_account'),
            grossLiabilityAccount: $this->text($snapshot, 'gross_liability_account'),
            insuranceExpenseAccount:
                $this->text($snapshot, 'insurance_expense_account'),
            insuranceLiabilityAccount:
                $this->text($snapshot, 'insurance_liability_account'),
            currency: $this->text($snapshot, 'currency'),
            // Starší archivované pásky klíč nemají a mít nemohou — vznikly
            // dřív, než se doplatek ze zúčtování vyplácel.
            annualSettlementMinorUnits: $this->integer(
                $snapshot + ['annual_settlement_minor_units' => 0],
                'annual_settlement_minor_units',
            ),
            incomeDetailStatus: $detailRecorded
                ? PayslipDocumentData::INCOME_DETAIL_RECORDED
                : PayslipDocumentData::INCOME_DETAIL_NOT_RECORDED,
            revisionNumber: $revisionNumber,
            hourlyClaims: $hourly ? $snapshot['hourly_claims'] : [],
        );
    }

    /** @param array<string,mixed> $snapshot */
    private function text(array $snapshot, string $key): string
    {
        $value = $snapshot[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \DomainException("Snapshot výplatní pásky nemá pole {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function integer(array $snapshot, string $key): int
    {
        $value = $snapshot[$key] ?? null;
        if (!is_int($value)) {
            throw new \DomainException("Snapshot výplatní pásky nemá celé číslo {$key}.");
        }
        return $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function boolean(array $snapshot, string $key): bool
    {
        $value = $snapshot[$key] ?? null;
        if (!is_bool($value)) {
            throw new \DomainException("Snapshot výplatní pásky nemá logickou hodnotu {$key}.");
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<PayslipLine>
     */
    private function lines(array $snapshot, string $key, bool $detailRecorded, bool $hourly = false): array
    {
        $rows = $snapshot[$key] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \DomainException("Snapshot výplatní pásky nemá seznam {$key}.");
        }
        $result = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \DomainException(
                    "Řádek {$key}.{$index} ve snapshotu výplatní pásky není objekt.",
                );
            }
            $line = [];
            foreach ($row as $rowKey => $value) {
                if (!is_string($rowKey)) {
                    throw new \DomainException(
                        "Řádek {$key}.{$index} má neplatný klíč.",
                    );
                }
                $line[$rowKey] = $value;
            }
            $basis = null;
            $exemptPart = 0;
            if ($detailRecorded && ($line['exemption_basis'] ?? null) !== null) {
                $basis = PayrollExemptionBasis::tryFrom(
                    is_string($line['exemption_basis']) ? $line['exemption_basis'] : '',
                ) ?? throw new \DomainException(
                    "Řádek {$key}.{$index} má neznámý podklad osvobození.",
                );
                $exemptPart = $this->integer($line, 'exempt_part_minor_units');
            }
            $result[] = new PayslipLine(
                $this->text($line, 'label'),
                $this->integer($line, 'amount_minor_units'),
                $basis,
                $exemptPart,
                $hourly ? ($line['hourly_detail'] ?? null) : null,
            );
        }
        return $result;
    }
}
