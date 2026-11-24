<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

/**
 * Doprovodný výsledek příkazu mzdového běhu. Samotný přechod stavu neřekne,
 * CO se při něm doopravdy stalo — u zaúčtování a plateb je rozdíl mezi
 * „provedeno", „už bylo hotové" a „nepoužije se" zásadní: firma v daňové
 * evidenci se musí dostat přes `post` dál, ale nesmí to vypadat, že vznikl
 * účetní zápis. Výsledek se ukládá do potvrzenky příkazu, takže idempotentní
 * replay vrátí přesně totéž.
 */
final readonly class PayrollRunCommandOutcome
{
    public const POSTED = 'posted';
    public const ALREADY_POSTED = 'already_posted';
    public const POSTING_NOT_APPLICABLE = 'posting_not_applicable';
    public const PAYMENTS_PREPARED = 'payments_prepared';
    public const PAYMENTS_NOT_APPLICABLE = 'payments_not_applicable';
    public const PAYMENTS_SETTLED = 'payments_settled';
    /** Obnova podkladů nenašla žádnou změnu: revize zůstává, nová nevznikla. */
    public const INPUTS_UNCHANGED = 'inputs_unchanged';

    /** @param array<string,mixed> $details */
    public function __construct(
        public string $outcome,
        public array $details = [],
    ) {
        if ($outcome === '') {
            throw new \InvalidArgumentException(
                'Výsledek mzdového příkazu musí mít kód.',
            );
        }
    }

    /** @return array{outcome:string,details:array<string,mixed>} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'details' => $this->details,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): ?self
    {
        $outcome = $payload['outcome'] ?? null;
        if (!is_string($outcome) || $outcome === '') {
            return null;
        }
        $details = $payload['details'] ?? [];

        return new self(
            $outcome,
            is_array($details) && !array_is_list($details) ? $details : [],
        );
    }
}
