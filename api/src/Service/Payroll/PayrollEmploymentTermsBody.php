<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

/**
 * Uložená verze podmínek pracovního vztahu jako vstup validátoru
 * ({@see PayrollEmploymentValidator::terms()}) pro opravu na místě
 * ({@see \MyInvoice\Repository\Payroll\PayrollEmploymentRepository::correctTerms()}).
 *
 * `correctTerms()` bere CÍLOVÝ stav — pole, které tělo nenese, se vyprázdní.
 * Kdo opravuje jeden údaj (oprava z registrace REGZEC A1, hromadné doplnění
 * pracoviště), musí proto poslat celou verzi. Seznam polí je tady jednou, aby
 * nový sloupec podmínek nezmizel jen z jedné ze zapisovacích cest.
 */
final class PayrollEmploymentTermsBody
{
    private const KEYS = [
        'office_id',
        'contract_signed_on',
        'planned_start_on',
        'actual_start_on',
        'fixed_term_end_on',
        'weekly_hours',
        'workload_basis_points',
        'work_place',
        'regular_workplace',
        'jmhz_workplace_municipality_code',
        'jmhz_workplace_country_code',
        'jmhz_workplace_address_point_code',
        'jmhz_workplace_street',
        'jmhz_workplace_house_number',
        'jmhz_workplace_orientation_number',
        'jmhz_workplace_postal_code',
        'jmhz_state_prosecutor',
        'jmhz_external_codebook_overlay_key',
        'jmhz_external_codebook_manifest_sha256',
        'jmhz_apz_contribution_status',
        'jmhz_apz_instrument_code',
        'jmhz_functional_benefits_status',
        'jmhz_temporary_assignment_status',
        'jmhz_assignment_user_kind',
        'jmhz_assignment_user_ico',
        'jmhz_assignment_user_country_code',
        'jmhz_assignment_user_foreign_id',
        'jmhz_assignment_user_name',
        'jmhz_orchard_discount_eligible',
        'jmhz_specific_legal_fact_applies',
        'jmhz_ozp_employment_support_applies',
        'jmhz_deep_mining_work_applies',
        'cz_isco_code',
        'activity_code',
        'jmhz_relationship_detail_code',
        'social_insurance_participation',
        'health_insurance_participation',
        'health_association_member',
        'tax_regime',
        'other_withholding_eligibility',
        'foreign_legislation_country_code',
        'a1_certificate_until',
        'risky_work',
        'social_employer_rate_category',
        'social_employer_rate_category_evidence',
        'jmhz_risk_categorization_code',
        'social_part_time_discount_reason',
        'social_part_time_discount_evidence',
        'social_part_time_discount_notified_on',
        'is_primary',
    ];

    /**
     * @param array<string,mixed> $current řádek z
     *        {@see \MyInvoice\Repository\Payroll\PayrollEmploymentRepository::currentTerms()}
     * @return array<string,mixed>
     */
    public static function fromCurrent(array $current, string $changeReason): array
    {
        $body = [];
        foreach (self::KEYS as $key) {
            $body[$key] = $current[$key] ?? null;
        }
        $override = $current['leave_entitlement_weeks_override'] ?? null;
        $body['leave_entitlement_weeks_override'] = $override === null
            ? null
            : (int) $override;
        // Pravděpodobný výdělek (§ 355 ZP) se opisuje beze změny — oprava
        // jiného údaje nesmí zahodit číslo, ze kterého se plní JMHZ 10345.
        $probable = $current['probable_hourly_earning_minor'] ?? null;
        $body['probable_hourly_earning_minor'] = $probable === null ? null : (int) $probable;
        $body['probable_earning_rationale'] = $current['probable_earning_rationale'] ?? null;
        $body['change_reason'] = $changeReason;

        return $body;
    }
}
