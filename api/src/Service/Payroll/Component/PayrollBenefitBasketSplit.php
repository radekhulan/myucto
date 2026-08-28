<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Component;

/**
 * Rozpad jednoho benefitního plnění na osvobozenou a nadlimitní část.
 *
 * `usedBeforeMinor` je úhrn koše PŘED tímhle plněním, ne po něm — pořadí čerpání
 * je dané pořadím schválení a rozpad se proto zmrazuje, ne dopočítává.
 */
final readonly class PayrollBenefitBasketSplit implements \JsonSerializable
{
    public function __construct(
        public PayrollBenefitExemptionBasket $basket,
        public int $limitMinor,
        public int $usedBeforeMinor,
        public int $amountMinor,
        public int $exemptMinor,
        public int $taxableMinor,
        /**
         * Počet nároků na osvobozený příspěvek na stravování, ze kterých se strop
         * poskládal. `null` u košů, jejichž limit na směnách nestojí — nula by
         * tam tvrdila, že se za období nic neodpracovalo.
         */
        public ?int $shiftEntitlements = null,
        /** @var array<string,int|string>|null */
        public ?array $allocation = null,
    ) {}

    public function usedAfterMinor(): int
    {
        return $this->usedBeforeMinor + $this->amountMinor;
    }

    public function remainingMinor(): int
    {
        return max(0, $this->limitMinor - $this->usedAfterMinor());
    }

    public function exceedsLimit(): bool
    {
        return $this->taxableMinor > 0;
    }

    /** @return array<string,int|string|bool|array<string,int|string>|null> */
    public function jsonSerialize(): array
    {
        return [
            'basket' => $this->basket->value,
            'statute' => $this->basket->statute(),
            'shift_entitlements' => $this->shiftEntitlements,
            'limit_minor' => $this->limitMinor,
            'used_before_minor' => $this->usedBeforeMinor,
            'used_after_minor' => $this->usedAfterMinor(),
            'remaining_minor' => $this->remainingMinor(),
            'exempt_minor' => $this->exemptMinor,
            'taxable_minor' => $this->taxableMinor,
            'limit_exceeded' => $this->exceedsLimit(),
            'allocation' => $this->allocation,
        ];
    }
}
