<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

final readonly class PayrollRegistrationA1Snapshot
{
    public const SCHEMA_REFERENCE = 'payroll-registration-regzec-a1.v1';

    /**
     * @param array<string,mixed> $source
     * @param array<string,mixed> $permanentAddress
     * @param array<string,mixed>|null $taxResidency
     * @param array<string,mixed> $employment
     * @param array<string,mixed>|null $pension
     * @param array<string,mixed>|null $facts
     * @param array<string,mixed>|null $foreignLegislation
     * @param array<string,mixed>|null $proofIdentity
     * @param array<string,mixed>|null $foreignWorker
     * @param array<string,mixed>|null $czechResidenceAddress
     * @param array<string,mixed>|null $contactAddress
     * @param list<array<string,mixed>> $attachments
     */
    public function __construct(
        public string $variant,
        public array $source,
        public array $permanentAddress,
        public ?array $taxResidency,
        public array $employment,
        public ?array $pension,
        public ?string $healthInsuranceCode,
        public ?array $facts,
        public ?array $foreignLegislation,
        public ?array $proofIdentity,
        public ?array $foreignWorker,
        public ?array $czechResidenceAddress,
        public ?array $contactAddress,
        public array $attachments,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_reference' => self::SCHEMA_REFERENCE,
            'variant' => $this->variant,
            'source' => $this->source,
            'permanent_address' => $this->permanentAddress,
            'tax_residency' => $this->taxResidency,
            'employment' => $this->employment,
            'pension' => $this->pension,
            'health_insurance_code' => $this->healthInsuranceCode,
            'facts' => $this->facts,
            'foreign_legislation' => $this->foreignLegislation,
            'proof_identity' => $this->proofIdentity,
            'foreign_worker' => $this->foreignWorker,
            'czech_residence_address' => $this->czechResidenceAddress,
            'contact_address' => $this->contactAddress,
            'attachments' => $this->attachments,
        ];
    }
}

