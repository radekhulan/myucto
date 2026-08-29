<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Garnishment;

use InvalidArgumentException;

final readonly class DeductionClaim
{
    public function __construct(
        public string $id,
        public DeductionLegalBasis $legalBasis,
        public ClaimCategory $category,
        public int $outstandingMinorUnits,
        public ?string $priorityDate,
        public bool $legalTitleVerified,
        public bool $orderOrNoticeDelivered,
        public ?string $orderIssuedOn,
        public bool $priorityClassificationVerified,
        public bool $agreementVerified = false,
        public ?int $maintenanceWeightMinorUnits = null,
        public bool $dueMonetaryClaimVerified = false,
        public bool $active = true,
        public ?string $enforcementOrderId = null,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Deduction claim ID is required.');
        }
        if ($outstandingMinorUnits < 0) {
            throw new InvalidArgumentException('Deduction claim balance cannot be negative.');
        }
        if ($maintenanceWeightMinorUnits !== null && $maintenanceWeightMinorUnits < 0) {
            throw new InvalidArgumentException('Maintenance allocation weight cannot be negative.');
        }
    }

    /**
     * Táž pohledávka se sníženým zůstatkem. Používá
     * {@see GarnishmentBatchCalculator}, aby se doplatek rozpuštěný do víc
     * období nesrazil na týž dluh několikrát (§ 276 o. s. ř.).
     */
    public function withOutstanding(int $outstandingMinorUnits): self
    {
        if ($outstandingMinorUnits === $this->outstandingMinorUnits) {
            return $this;
        }

        return new self(
            $this->id,
            $this->legalBasis,
            $this->category,
            $outstandingMinorUnits,
            $this->priorityDate,
            $this->legalTitleVerified,
            $this->orderOrNoticeDelivered,
            $this->orderIssuedOn,
            $this->priorityClassificationVerified,
            $this->agreementVerified,
            $this->maintenanceWeightMinorUnits,
            $this->dueMonetaryClaimVerified,
            $this->active,
            $this->enforcementOrderId,
        );
    }

    /** @return array<string,mixed> */
    public function toCanonicalArray(): array
    {
        return [
            'active' => $this->active,
            'agreement_verified' => $this->agreementVerified,
            'category' => $this->category->value,
            'due_monetary_claim_verified' => $this->dueMonetaryClaimVerified,
            'enforcement_order_id' => $this->enforcementOrderId,
            'id' => $this->id,
            'legal_basis' => $this->legalBasis->value,
            'legal_title_verified' => $this->legalTitleVerified,
            'maintenance_weight_minor_units' => $this->maintenanceWeightMinorUnits,
            'order_issued_on' => $this->orderIssuedOn,
            'order_or_notice_delivered' => $this->orderOrNoticeDelivered,
            'outstanding_minor_units' => $this->outstandingMinorUnits,
            'priority_classification_verified' =>
                $this->priorityClassificationVerified,
            'priority_date' => $this->priorityDate,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromCanonicalArray(array $data): self
    {
        return new self(
            self::string($data, 'id'),
            DeductionLegalBasis::from(self::string($data, 'legal_basis')),
            ClaimCategory::from(self::string($data, 'category')),
            self::int($data, 'outstanding_minor_units'),
            self::nullableString($data, 'priority_date'),
            self::bool($data, 'legal_title_verified'),
            self::bool($data, 'order_or_notice_delivered'),
            self::nullableString($data, 'order_issued_on'),
            self::bool($data, 'priority_classification_verified'),
            self::bool($data, 'agreement_verified'),
            self::nullableInt($data, 'maintenance_weight_minor_units'),
            self::bool($data, 'due_monetary_claim_verified'),
            self::bool($data, 'active'),
            self::nullableString($data, 'enforcement_order_id'),
        );
    }

    /** @param array<string,mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a string.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a nullable string.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new InvalidArgumentException("{$key} must be a nullable integer.");
        }
        return $value;
    }

    /** @param array<string,mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$key} must be a boolean.");
        }
        return $value;
    }
}
