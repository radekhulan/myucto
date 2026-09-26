<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Migration;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Zápis převzatých mzdových úhrnů do `payroll_migration_reference_totals`.
 *
 * Volá se z převodu z původního systému. Je idempotentní přes UNIQUE
 * (firma, zdroj, období, vztah): opakovaný převod téhož exportu řádek přepíše,
 * nezaloží druhý. Díky tomu smí běžet i po zkoušce nanečisto.
 */
final class PayrollMigrationReferenceTotalsWriter
{
    /**
     * Zdroje, ze kterých převzatá strana může pocházet (musí sedět na ENUM
     * v migraci 1849 ve znění 1851).
     *
     * `other` je obecný zdroj pro tabulkový import z libovolného mzdového
     * systému. Existuje proto, aby převzaté mzdy nebyly vázané na PAMICU:
     * pojmenované zdroje jsou jen ty, pro které v aplikaci běží vlastní feeder.
     * `jmhz` plní import přijatých měsíčních hlášení (migrace 1892).
     */
    public const SOURCES = ['pamica', 'pohoda', 'money_s3', 'other', 'stereo_nx', 'jmhz'];
    public const SOURCE_JMHZ = 'jmhz';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param list<PayrollMigrationReferenceTotals> $totals
     * @return int počet zapsaných řádků
     */
    public function store(
        int $supplierId,
        string $source,
        array $totals,
        ?string $importReference = null,
    ): int {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma musí být zvolená.');
        }
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Neznámý zdroj převzatých mezd: {$source}.");
        }
        if ($totals === []) {
            return 0;
        }

        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_migration_reference_totals
                 (supplier_id, source, period_start, external_person_ref,
                  external_relationship_ref, employee_id, employment_id,
                  gross_minor, net_minor, social_base_minor, health_base_minor,
                  employee_social_minor, employee_health_minor,
                  employer_social_minor, employer_health_minor,
                  advance_tax_minor, withholding_tax_minor, tax_bonus_minor,
                  relationship_start_date, relationship_end_date, relation_type,
                  activity_code, pension_participation, insurance_days,
                  excluded_days, worked_days_hundredths, worked_minutes,
                  deductions_minor, net_payable_minor, payout_date,
                  import_reference)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 external_person_ref = VALUES(external_person_ref),
                 employee_id = VALUES(employee_id),
                 employment_id = VALUES(employment_id),
                 gross_minor = VALUES(gross_minor),
                 net_minor = VALUES(net_minor),
                 social_base_minor = VALUES(social_base_minor),
                 health_base_minor = VALUES(health_base_minor),
                 employee_social_minor = VALUES(employee_social_minor),
                 employee_health_minor = VALUES(employee_health_minor),
                 employer_social_minor = VALUES(employer_social_minor),
                 employer_health_minor = VALUES(employer_health_minor),
                 advance_tax_minor = VALUES(advance_tax_minor),
                 withholding_tax_minor = VALUES(withholding_tax_minor),
                 tax_bonus_minor = VALUES(tax_bonus_minor),
                 relationship_start_date = VALUES(relationship_start_date),
                 relationship_end_date = VALUES(relationship_end_date),
                 relation_type = VALUES(relation_type),
                 activity_code = VALUES(activity_code),
                 pension_participation = VALUES(pension_participation),
                 insurance_days = VALUES(insurance_days),
                 excluded_days = VALUES(excluded_days),
                 worked_days_hundredths = VALUES(worked_days_hundredths),
                 worked_minutes = VALUES(worked_minutes),
                 deductions_minor = VALUES(deductions_minor),
                 net_payable_minor = VALUES(net_payable_minor),
                 payout_date = VALUES(payout_date),
                 import_reference = VALUES(import_reference)',
        );

        $written = 0;
        foreach ($totals as $row) {
            $facts = $row->facts;
            $statement->execute([
                $supplierId,
                $source,
                $row->period . '-01',
                $row->externalPersonRef,
                $row->externalRelationshipRef,
                $row->employeeId,
                $row->employmentId,
                $row->grossMinor,
                $row->netMinor,
                $row->socialBaseMinor,
                $row->healthBaseMinor,
                $row->employeeSocialMinor,
                $row->employeeHealthMinor,
                $row->employerSocialMinor,
                $row->employerHealthMinor,
                $row->advanceTaxMinor,
                $row->withholdingTaxMinor,
                $row->taxBonusMinor,
                $facts->relationshipStartDate,
                $facts->relationshipEndDate,
                $facts->relationType,
                $facts->activityCode,
                $facts->pensionParticipation ? 1 : 0,
                $facts->insuranceDays,
                $facts->excludedDays,
                $facts->workedDaysHundredths,
                $facts->workedMinutes,
                $facts->deductionsMinor,
                $facts->netPayableMinor,
                $facts->payoutDate,
                $importReference,
            ]);
            $written++;
        }

        return $written;
    }
}
