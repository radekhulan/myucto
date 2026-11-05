<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Účetní události ostatní položky, ke kterým druh nese účetní profil a položka vlastní
 * kontaci (migrace 2238). Hodnota je `event` v other_item_posting_profiles a overrides.
 *
 * Aplikace zatím účtuje vznik a úhradu z banky či pokladny. Další události (úroky,
 * časové rozlišení, odpis, přeúčtování dlouhodobé části na krátkodobou) jsou tu, aby se
 * pro ně počítalo s místem; profil pro ně uložit nejde, dokud je neumí účtovat.
 */
enum OtherItemPostingEvent: string
{
    case Origin = 'origin';
    case PaymentBank = 'payment_bank';
    case PaymentCash = 'payment_cash';
    case Interest = 'interest';
    case Deferral = 'deferral';
    case WriteOff = 'write_off';
    case ReclassShortLong = 'reclass_short_long';

    public function implemented(): bool
    {
        return match ($this) {
            self::Origin, self::PaymentBank, self::PaymentCash => true,
            default => false,
        };
    }

    public function isPayment(): bool
    {
        return $this === self::PaymentBank || $this === self::PaymentCash;
    }

    /** @return list<self> */
    public static function implementedCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $event): bool => $event->implemented()));
    }

    /** Událost, kterou aplikace umí účtovat; jinak validační chyba. */
    public static function fromInput(mixed $value): self
    {
        $event = is_string($value) ? self::tryFrom($value) : null;
        if ($event === null || !$event->implemented()) {
            throw new OtherItemException('invalid_posting_event', 'Neznámá účetní událost.');
        }
        return $event;
    }
}
