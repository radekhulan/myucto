<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Jeden nález kontroly: doklad, výpis nebo účet s rozdílem.
 *
 * `state` říká, co s nálezem udělá oprava: `fixable` opraví, `locked` jen ohlásí (uzavřené,
 * rozpracované či zamčené období, podané období DPH), `review` jen ohlásí, protože oprava
 * není jednoznačná. `entity_type` a `entity_id` slouží k prokliku na doklad.
 */
final class CheckItem
{
    public const FIXABLE = 'fixable';
    public const LOCKED = 'locked';
    public const REVIEW = 'review';

    /** Druhy záznamů, na které umí rozhraní prokliknout. */
    public const ENTITY_TYPES = ['invoice', 'purchase_invoice', 'bank_statement', 'account', 'other_item'];

    /** @param array<string,scalar|null> $details doplňující údaje nálezu (účet, rok, číslo zálohy …) */
    public function __construct(
        public readonly string $entityType,
        public readonly ?int $entityId,
        public readonly string $label,
        public readonly ?string $date,
        public readonly ?float $amountCzk,
        public readonly string $state,
        public readonly string $reason,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly array $details = [],
    ) {
        if (!in_array($state, [self::FIXABLE, self::LOCKED, self::REVIEW], true)) {
            throw new \InvalidArgumentException("Neznámý stav nálezu {$state}.");
        }
        if (!in_array($entityType, self::ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException("Neznámý druh záznamu {$entityType}.");
        }
    }

    public function withState(string $state, string $reason): self
    {
        return new self($this->entityType, $this->entityId, $this->label, $this->date, $this->amountCzk, $state, $reason,
            $this->amount, $this->currency, $this->details);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'label' => $this->label,
            'date' => $this->date,
            'amount_czk' => $this->amountCzk === null ? null : round($this->amountCzk, 2),
            'amount' => $this->amount === null ? null : round($this->amount, 2),
            'currency' => $this->currency,
            'state' => $this->state,
            'reason' => $this->reason,
            'details' => $this->details,
        ];
    }
}
