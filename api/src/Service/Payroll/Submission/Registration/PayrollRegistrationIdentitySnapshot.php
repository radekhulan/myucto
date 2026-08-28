<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

final readonly class PayrollRegistrationIdentitySnapshot
{
    public const SCHEMA_REFERENCE = 'payroll-registration-identity-snapshot.v1';

    /**
     * @param array{
     *   supplier_id:int,submission_id:int,source_revision_id:?int,
     *   employee_id:int,employment_id:int,environment:string,
     *   agenda_code:string,effective_on:string
     * } $scope
     * @param array<string,mixed> $identity
     * @param array{
     *   birth_number:?string,ecp:?string,vcp:?string,
     *   foreign_tax_identifier:?string
     * } $identifiers
     * @param array<string,mixed>|null $employmentExternalIdentifier
     * @param array<string,mixed> $registrationEligibility
     * @param array<string,mixed> $sourceVersions
     */
    public function __construct(
        public array $scope,
        public array $identity,
        public array $identifiers,
        public ?array $employmentExternalIdentifier,
        public array $registrationEligibility,
        public array $sourceVersions,
        public ?PayrollRegistrationA1Snapshot $regzecA1 = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $a1Ready = $this->scope['agenda_code'] === 'REGZEC25'
            && $this->regzecA1 !== null;

        $result = [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'document_kind' => 'registration_identity_snapshot',
            'workflow_status' => $a1Ready
                ? 'regzec_a1_frozen'
                : 'identity_frozen_only',
            'official_submission' => $a1Ready
                ? ['supported' => true, 'reason_code' => null]
                : [
                    'supported' => false,
                    'reason_code' => 'xml_and_legal_validation_not_implemented',
                ],
            'scope' => $this->scope,
            'identity' => $this->identity,
            'identifiers' => $this->identifiers,
            'employment_external_identifier' =>
                $this->employmentExternalIdentifier,
            'registration_eligibility' => $this->registrationEligibility,
            'source_versions' => $this->sourceVersions,
        ];
        if ($this->regzecA1 !== null) {
            $result['regzec_a1'] = $this->regzecA1->toArray();
        }

        return $result;
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray());
    }
}
