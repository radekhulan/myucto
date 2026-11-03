<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Ruleset;

use InvalidArgumentException;
use MyInvoice\Service\Payroll\Calculation\DecimalRate;
use MyInvoice\Service\Payroll\Calculation\Money;

final readonly class PayrollRuleValue
{
    private function __construct(
        public string $type,
        public string|int|bool $value,
        public PayrollRulesetCapability $capability,
        public ?string $note,
    ) {}

    public static function rate(string $value): self
    {
        return new self(
            'decimal_rate',
            DecimalRate::fromString($value)->toCanonicalString(),
            PayrollRulesetCapability::Supported,
            null,
        );
    }

    public static function moneyMinor(int $minorUnits): self
    {
        $money = Money::fromMinorUnits($minorUnits);

        return new self('money_minor', $money->minorUnits, PayrollRulesetCapability::Supported, null);
    }

    public static function integer(int $value): self
    {
        return new self('integer', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function text(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Ruleset text values cannot be empty.');
        }

        return new self('text', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function boolean(bool $value): self
    {
        return new self('boolean', $value, PayrollRulesetCapability::Supported, null);
    }

    public static function manualReview(string $reason): self
    {
        if ($reason === '') {
            throw new InvalidArgumentException('Manual-review rules must explain why calculation is blocked.');
        }

        return new self('manual_review', $reason, PayrollRulesetCapability::ManualReview, $reason);
    }

    /**
     * Rekonstrukce z kanonického snapshotu (tenantová evidence rulesetů).
     * Hodnota se protahuje týmiž továrnami jako při zadání, takže uložený řádek
     * nemůže do runtime propašovat tvar, který by přes veřejné API nevznikl.
     *
     * @param array<string, mixed> $row
     */
    public static function fromCanonicalArray(array $row): self
    {
        $type = $row['type'] ?? null;
        $value = $row['value'] ?? null;
        if (!is_string($type)) {
            throw new InvalidArgumentException('Parametr rulesetu nemá typ.');
        }

        return match ($type) {
            'decimal_rate' => is_string($value)
                ? self::rate($value)
                : throw new InvalidArgumentException('Sazba rulesetu není text.'),
            'money_minor' => is_int($value)
                ? self::moneyMinor($value)
                : throw new InvalidArgumentException('Částka rulesetu není celé číslo haléřů.'),
            'integer' => is_int($value)
                ? self::integer($value)
                : throw new InvalidArgumentException('Celočíselný parametr rulesetu není celé číslo.'),
            'text' => is_string($value)
                ? self::text($value)
                : throw new InvalidArgumentException('Textový parametr rulesetu není text.'),
            'boolean' => is_bool($value)
                ? self::boolean($value)
                : throw new InvalidArgumentException('Logický parametr rulesetu není boolean.'),
            'manual_review' => is_string($value)
                ? self::manualReview($value)
                : throw new InvalidArgumentException('Důvod manuální kontroly není text.'),
            default => throw new InvalidArgumentException("Neznámý typ parametru rulesetu {$type}."),
        };
    }

    /** @return array{capability:string,note:?string,type:string,value:bool|int|string} */
    public function toCanonicalArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'note' => $this->note,
            'type' => $this->type,
            'value' => $this->value,
        ];
    }

    public function assertCalculationReady(string $parameter): void
    {
        if ($this->capability !== PayrollRulesetCapability::Supported) {
            throw new PayrollRulesetException(
                "Parametr legislativní sady {$parameter} nemá hodnotu pro výpočet: {$this->note}",
            );
        }
    }
}
