<?php

declare(strict_types=1);

namespace MyInvoice\Service\Currency;

use DateTimeImmutable;

/**
 * Kurz, kterým se oceňuje přijatá (nebo vydaná) platba v cizí měně ke dni platby,
 * a zaokrouhlení přepočtu na haléře. Jediné místo pravidla pro zaúčtování
 * cizoměnové úhrady v bance ({@see \MyInvoice\Service\Accounting\Bank\BankPostingService})
 * i pro částku evidované tržby v CZK (EET).
 *
 * Kurz podle kurzového režimu firmy: pevný kurz období (§ 24 odst. 7 ZoÚ,
 * {@see FixedExchangeRateService}), jinak kurz ČNB platný ke dni platby
 * ({@see CnbExchangeRateClient::getRate()}, víkend a svátek berou poslední
 * vyhlášený kurz). Když kurz chybí, nepoužije se tiše jiný: volající dostane
 * {@see ExchangeRateUnavailableException} a operaci odmítne.
 *
 * Daňová evidence oceňuje úhradu v peněžním deníku kurzem z `exchange_rates`
 * k datu úhrady (poslední vyhlášený). Firma v daňové evidenci má denní režim,
 * takže jde o tentýž kurz ČNB.
 */
final class PaymentExchangeRate
{
    public function __construct(
        private readonly FixedExchangeRateService $fixed,
        private readonly CnbExchangeRateClient $cnb,
    ) {}

    /**
     * @return array{rate: float, rate_date: string, source: string}
     * @throws ExchangeRateUnavailableException
     */
    public function forDay(int $supplierId, string $currency, string $date): array
    {
        $code = strtoupper(trim($currency));
        $day = new DateTimeImmutable(substr($date, 0, 10));
        $fixed = $this->fixed->resolve($supplierId, $code, $day);
        if ($fixed !== null && (float) $fixed['rate'] > 0) {
            return ['rate' => (float) $fixed['rate'], 'rate_date' => (string) $fixed['rate_date'], 'source' => (string) $fixed['mode']];
        }
        $info = $this->cnb->getRate($code, $day);
        if ($info === null || (float) $info['rate'] <= 0) {
            throw new ExchangeRateUnavailableException($code, $day->format('Y-m-d'));
        }
        return ['rate' => (float) $info['rate'], 'rate_date' => (string) $info['rate_date'], 'source' => 'cnb'];
    }

    /**
     * Přepočet cizí částky v haléřích na haléře CZK s obchodním zaokrouhlením.
     * round() nad plovoucími korunami by 180,98 × 24,25 = 4 388,765 (binárně
     * 4 388,76499…) zaokrouhlil dolů; předzaokrouhlení na 4 místa šum odstraní
     * a výsledek odpovídá přesnému DECIMAL součinu v databázi.
     */
    public static function toCzkCents(int $foreignCents, float $rate): int
    {
        return self::roundCents($foreignCents * $rate);
    }

    /** Haléře (i zlomkové, např. z poměrného dělení) na celé haléře, viz {@see toCzkCents()}. */
    public static function roundCents(float $cents): int
    {
        return (int) round(round($cents, 4));
    }
}
