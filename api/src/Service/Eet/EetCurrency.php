<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Service\Currency\ExchangeRateUnavailableException;
use MyInvoice\Service\Currency\PaymentExchangeRate;

/**
 * Částka evidované tržby je vždy v CZK. Platba v cizí měně se přepočte podle
 * obecných pravidel kurzového přepočtu pro určení rozhodného příjmu, tedy stejným
 * kurzem a zaokrouhlením, jakým účetnictví ocení tutéž úhradu ke dni přijetí
 * ({@see PaymentExchangeRate}). Chybí-li kurz, platba se odmítne stejně jako
 * zaúčtování; jiný kurz se tiše nepoužije.
 *
 * Výsledek nese CZK částku a stopu přepočtu (původní měna a částka, kurz, zdroj)
 * pro sloupce `payment_events`.
 */
final class EetCurrency
{
    public function __construct(private readonly PaymentExchangeRate $rates) {}

    /**
     * @return array{amount:string, currency:string, original_currency:?string, original_amount:?string,
     *               exchange_rate:?string, exchange_rate_source:?string}
     * @throws EetCommandException 422 `eet_fx_rate_unavailable`
     */
    public function forPayment(int $supplierId, string $currency, string $amount, \DateTimeImmutable $receivedAt): array
    {
        $currency = strtoupper($currency);
        if ($currency === 'CZK') {
            return self::czk($amount);
        }
        $day = EetValues::local($receivedAt)->format('Y-m-d');
        try {
            $rate = $this->rates->forDay($supplierId, $currency, $day);
        } catch (ExchangeRateUnavailableException $e) {
            throw new EetCommandException('eet_fx_rate_unavailable',
                $e->getMessage() . ' Platbu v cizí měně nelze přepočítat na Kč pro evidenci tržby; doplňte kurz a zkuste to znovu.',
                422, ['field' => 'currency', 'currency' => $currency, 'date' => $day]);
        }
        return self::converted($currency, $amount, $rate['rate'], $rate['source'] . ':' . $rate['rate_date']);
    }

    /**
     * Stopa přepočtu pro doklad, který už CZK částku nese (valutová pokladna). EET
     * bere CZK ekvivalent dokladu tak, jak se zaúčtuje, nepočítá ho znovu.
     *
     * @return array{amount:string, currency:string, original_currency:?string, original_amount:?string,
     *               exchange_rate:?string, exchange_rate_source:?string}
     */
    public static function fromDocument(string $currency, string $czkAmount, ?string $foreignAmount, ?float $rate, string $source): array
    {
        $currency = strtoupper($currency);
        if ($currency === 'CZK' || $foreignAmount === null || $rate === null || $rate <= 0) {
            return self::czk($czkAmount);
        }
        return [
            'amount' => EetValues::amount($czkAmount),
            'currency' => 'CZK',
            'original_currency' => $currency,
            'original_amount' => EetValues::amount($foreignAmount),
            'exchange_rate' => self::rate($rate),
            'exchange_rate_source' => mb_substr($source, 0, 40),
        ];
    }

    /**
     * @return array{amount:string, currency:string, original_currency:?string, original_amount:?string,
     *               exchange_rate:?string, exchange_rate_source:?string}
     */
    public static function converted(string $currency, string $amount, float $rate, string $source): array
    {
        $foreign = EetValues::amount($amount);
        return [
            'amount' => EetValues::fromCents(PaymentExchangeRate::toCzkCents(EetValues::toCents($foreign), $rate)),
            'currency' => 'CZK',
            'original_currency' => strtoupper($currency),
            'original_amount' => $foreign,
            'exchange_rate' => self::rate($rate),
            'exchange_rate_source' => mb_substr($source, 0, 40),
        ];
    }

    public static function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 8, '.', ''), '0'), '.');
    }

    /**
     * @return array{amount:string, currency:string, original_currency:null, original_amount:null,
     *               exchange_rate:null, exchange_rate_source:null}
     */
    private static function czk(string $amount): array
    {
        return [
            'amount' => EetValues::amount($amount),
            'currency' => 'CZK',
            'original_currency' => null,
            'original_amount' => null,
            'exchange_rate' => null,
            'exchange_rate_source' => null,
        ];
    }
}
