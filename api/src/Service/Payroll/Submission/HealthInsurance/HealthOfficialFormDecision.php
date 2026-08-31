<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\HealthInsurance;

/**
 * Odpověď na otázku „vytiskne se úřední tiskopis, nebo vlastní sestava?".
 *
 * Když se tiskopis použít nedá, NIKDY se to nezamlčí: `reasonCode`
 * a jednovětný `reason` doputují až do odpovědi API i do patky vlastního PDF,
 * takže účetní čte důvod tam, kde se rozhodnutí projeví.
 */
final readonly class HealthOfficialFormDecision
{
    private function __construct(
        public ?string $formId,
        public ?string $reasonCode,
        public ?string $reason,
    ) {}

    public static function official(string $formId): self
    {
        return new self($formId, null, null);
    }

    public static function ownDocument(string $reasonCode, string $reason): self
    {
        return new self(null, $reasonCode, $reason);
    }

    public function usesOfficialForm(): bool
    {
        return $this->formId !== null;
    }

    /** @return array{used:bool,form_id:?string,reason_code:?string,reason:?string} */
    public function toArray(): array
    {
        return [
            'used' => $this->usesOfficialForm(),
            'form_id' => $this->formId,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
        ];
    }
}
