<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Repository\Payroll\PayrollRegzelRepository;

final readonly class RegzelPayloadSnapshotBuilder
{
    public function __construct(private PayrollRegzelRepository $repository) {}

    public function buildSupplementalInformation(
        int $supplierId,
        int $officeId,
        string $environment,
    ): RegzelPayloadSnapshot {
        if ($supplierId <= 0 || $officeId <= 0) {
            throw new RegzelValidationException(
                'regzel_source_scope_invalid',
                'Firma nebo mzdová účtárna REGZEL není platná.',
            );
        }
        $source = $this->repository->source($supplierId, $officeId);
        if ($source === null) {
            throw new RegzelValidationException(
                'regzel_source_incomplete',
                'Chybí nastavení zaměstnavatele, účtárny nebo potvrzený REGZEL profil.',
            );
        }
        if (!$source['office_is_active']) {
            throw new RegzelValidationException(
                'regzel_office_inactive',
                'Doplňující REGZEL údaje nelze připravit pro neaktivní účtárnu.',
            );
        }

        return new RegzelPayloadSnapshot(
            supplierId: $source['supplier_id'],
            officeId: $source['office_id'],
            environment: trim($environment),
            interaction: 'supplemental_information',
            csszWorkplaceCode:
                $source['social_security_office_code'] ?? '',
            taxOfficeCode: $source['regzel_tax_office_code'] ?? '',
            taxOfficeWorkplaceCode:
                $source['regzel_tax_office_workplace_code'],
            socialSecurityVariableSymbol:
                $source['social_security_variable_symbol'] ?? '',
            payerReferenceNumber:
                $source['regzel_payer_reference_number'],
            notificationDataBoxId: $source['data_box_id'],
            socialEnterprise: $source['social_enterprise'],
            employmentAgency: $source['employment_agency'],
            protectedLaborMarket: $source['protected_labor_market'],
            employerSettingsRowVersion:
                $source['employer_settings_row_version'],
            officeRowVersion: $source['office_row_version'],
            profileRowVersion: $source['profile_row_version'],
            supplierUpdatedAt: $source['supplier_updated_at'],
        );
    }
}
