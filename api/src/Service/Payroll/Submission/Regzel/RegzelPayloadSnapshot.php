<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Regzel;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class RegzelPayloadSnapshot
{
    public const LEGACY_SCHEMA_REFERENCE = 'payroll-regzeldopl25-payload.v1';
    public const LEGACY_MAPPING_VERSION = 'regzeldopl25-map-1';
    public const SCHEMA_REFERENCE = 'payroll-regzeldopl25-payload.v2';
    public const MAPPING_VERSION = 'regzeldopl25-map-2';
    public const XSD_VERSION = '1.2';

    public function __construct(
        public int $supplierId,
        public int $officeId,
        public string $environment,
        public string $interaction,
        public string $csszWorkplaceCode,
        public string $taxOfficeCode,
        public ?string $taxOfficeWorkplaceCode,
        public string $socialSecurityVariableSymbol,
        public ?string $payerReferenceNumber,
        public ?string $notificationDataBoxId,
        public bool $socialEnterprise,
        public bool $employmentAgency,
        public bool $protectedLaborMarket,
        public int $employerSettingsRowVersion,
        public int $officeRowVersion,
        public int $profileRowVersion,
        public string $supplierUpdatedAt,
        public string $schemaReference = self::SCHEMA_REFERENCE,
        public string $mappingVersion = self::MAPPING_VERSION,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => $this->schemaReference,
            'mapping_version' => $this->mappingVersion,
            'xsd_version' => self::XSD_VERSION,
            'supplier_id' => $this->supplierId,
            'office_id' => $this->officeId,
            'environment' => $this->environment,
            'interaction' => $this->interaction,
            'header' => [
                'cssz_workplace_code' => $this->csszWorkplaceCode,
                'tax_office_code' => $this->taxOfficeCode,
                'tax_office_workplace_code' =>
                    $this->taxOfficeWorkplaceCode,
            ],
            'employer' => [
                'social_security_variable_symbol' =>
                    $this->socialSecurityVariableSymbol,
                'payer_reference_number' => $this->payerReferenceNumber,
                'notification_data_box_id' => $this->notificationDataBoxId,
                'supplemental_information' => [
                    'social_enterprise' => $this->socialEnterprise,
                    'employment_agency' => $this->employmentAgency,
                    'protected_labor_market' =>
                        $this->protectedLaborMarket,
                ],
            ],
            'source_versions' => [
                'employer_settings_row_version' =>
                    $this->employerSettingsRowVersion,
                'office_row_version' => $this->officeRowVersion,
                'profile_row_version' => $this->profileRowVersion,
                'supplier_updated_at' => $this->supplierUpdatedAt,
            ],
        ];
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
