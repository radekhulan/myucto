<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eshop\Pricing;

final class PricingInputException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly array $details = [])
    {
        parent::__construct(match ($errorCode) {
            'missing_exchange_rate' => 'Chybí kurz pro přepočet ceny.',
            'missing_pricing_exchange_rate' => 'Chybí obchodní kurz pro přepočet ceny.',
            'stale_pricing_exchange_rate' => 'Obchodní kurz je pro přepočet ceny příliš starý.',
            'missing_pricing_rule' => 'Pro kartu a měnu není dostupné cenové pravidlo.',
            'missing_purchase_cost' => 'Pro kartu chybí nákladová cena.',
            'invalid_target_margin' => 'Cílová marže je neplatná.',
            'stale_price_input' => 'Karta byla mezitím změněna.',
            default => 'Cenu se nepodařilo přepočítat.',
        });
    }

    public function httpStatus(): int
    {
        return $this->errorCode === 'stale_price_input' ? 409 : 422;
    }
}
