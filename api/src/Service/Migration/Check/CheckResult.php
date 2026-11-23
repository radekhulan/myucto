<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/**
 * Výsledek kontroly: nálezy a z nich odvozený stav.
 *
 * Stav `ok` = bez nálezu, `fixable` = aspoň jeden nález oprava opraví, `review` = nálezy
 * jen ke kontrole (oprava není jednoznačná, nebo leží v uzavřeném či podaném období).
 * Částka je součet nálezů v Kč (u kontrol nad účty rozdíl, u dokladů chybějící částka).
 */
final class CheckResult
{
    public const OK = 'ok';
    public const FIXABLE = 'fixable';
    public const REVIEW = 'review';

    /**
     * @param list<CheckItem> $items
     * @param list<string> $notes upozornění ke kontrole jako celku (česky)
     */
    public function __construct(
        public readonly array $items,
        public readonly array $notes = [],
    ) {}

    public function status(): string
    {
        if ($this->countState(CheckItem::FIXABLE) > 0) {
            return self::FIXABLE;
        }
        return $this->items === [] ? self::OK : self::REVIEW;
    }

    public function countState(string $state): int
    {
        return count(array_filter($this->items, static fn (CheckItem $i): bool => $i->state === $state));
    }

    public function amountCzk(?string $state = null): float
    {
        $sum = 0.0;
        foreach ($this->items as $i) {
            if ($state === null || $i->state === $state) {
                $sum += abs($i->amountCzk ?? 0.0);
            }
        }
        return round($sum, 2);
    }

    /** @return list<CheckItem> */
    public function itemsInState(string $state): array
    {
        return array_values(array_filter($this->items, static fn (CheckItem $i): bool => $i->state === $state));
    }

    /** @return array<string,mixed> souhrn bez seznamu nálezů */
    public function summary(): array
    {
        return [
            'status' => $this->status(),
            'count' => count($this->items),
            'fixable_count' => $this->countState(CheckItem::FIXABLE),
            'locked_count' => $this->countState(CheckItem::LOCKED),
            'review_count' => $this->countState(CheckItem::REVIEW),
            'amount_czk' => $this->amountCzk(),
            'fixable_amount_czk' => $this->amountCzk(CheckItem::FIXABLE),
            'notes' => $this->notes,
        ];
    }
}
