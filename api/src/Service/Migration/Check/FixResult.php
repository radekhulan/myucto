<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/** Výsledek provedené opravy: počet a částka opravených nálezů a ohlášené zbylé. */
final class FixResult
{
    /**
     * @param list<CheckItem> $fixed
     * @param list<CheckItem> $skipped
     * @param list<string> $notes
     */
    public function __construct(
        public readonly array $fixed,
        public readonly array $skipped,
        public readonly array $notes = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $amount = 0.0;
        foreach ($this->fixed as $c) {
            $amount += abs($c->amountCzk ?? 0.0);
        }
        return [
            'fixed_count' => count($this->fixed),
            'amount_czk' => round($amount, 2),
            'skipped_count' => count($this->skipped),
            'fixed' => array_map(static fn (CheckItem $i): array => $i->toArray(), $this->fixed),
            'skipped' => array_map(static fn (CheckItem $i): array => $i->toArray(), $this->skipped),
            'notes' => $this->notes,
        ];
    }
}
