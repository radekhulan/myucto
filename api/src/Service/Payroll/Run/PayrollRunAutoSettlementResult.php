<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Výsledek automatického překlopení běhu do `paid`.
 *
 * `pending` NENÍ chyba — je to normální stav mezi odesláním příkazu do banky
 * a příchodem výpisu. Volající ho vypisuje jako informaci („čeká na výpis"),
 * nikdy jako blokaci.
 */
final readonly class PayrollRunAutoSettlementResult
{
    public const SETTLED = 'settled';
    public const PENDING = 'pending';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    /**
     * @param array{
     *   liability_count:int,
     *   uncovered_count:int,
     *   required_minor:int,
     *   settled_minor:int
     * }|null $coverage
     */
    private function __construct(
        public string $state,
        public ?array $coverage = null,
        public ?string $reason = null,
    ) {}

    /** @param array<string,mixed> $coverage */
    public static function settled(array $coverage): self
    {
        return new self(self::SETTLED, self::summarize($coverage));
    }

    /** @param array<string,mixed> $coverage */
    public static function pending(array $coverage): self
    {
        return new self(self::PENDING, self::summarize($coverage));
    }

    /** @param array<string,mixed> $coverage */
    public static function failed(array $coverage): self
    {
        return new self(self::FAILED, self::summarize($coverage));
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, null, $reason);
    }

    public function didSettle(): bool
    {
        return $this->state === self::SETTLED;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $payload = ['state' => $this->state];
        if ($this->coverage !== null) {
            $payload['coverage'] = $this->coverage;
        }
        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $coverage
     * @return array{
     *   liability_count:int,
     *   uncovered_count:int,
     *   required_minor:int,
     *   settled_minor:int
     * }
     */
    private static function summarize(array $coverage): array
    {
        $uncovered = $coverage['uncovered'] ?? [];

        return [
            'liability_count' => (int) ($coverage['liability_count'] ?? 0),
            'uncovered_count' => is_array($uncovered) ? count($uncovered) : 0,
            'required_minor' => (int) ($coverage['required_minor'] ?? 0),
            'settled_minor' => (int) ($coverage['settled_minor'] ?? 0),
        ];
    }
}
