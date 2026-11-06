<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Sdílená peněžní pravidla pro CZK úhradu cizoměnového dokladu.
 *
 * Bankovní pohyb zůstává ve své skutečné měně a částce. Evidence platby na
 * faktuře naproti tomu pracuje v měně dokladu, takže úplná FX úhrada vypořádá
 * celý zbývající obnos, pokud se CZK pohyb vejde do přijímací tolerance.
 */
final class FxPaymentSettlement
{
    /** Tuzemská měna, ve které přichází protihodnota cizoměnové faktury. */
    public const LOCAL_CURRENCY = 'CZK';
    /** Absolutní floor tolerance pro peněžní shodu v CZK. */
    public const AMOUNT_TOLERANCE = 1.0;
    /**
     * Bankovní spread a pohyb kurzu mezi vystavením a úhradou běžně dosáhne
     * jednotek procent; 4 % ponechá rezervu, ale stále odmítne zjevnou podplatbu.
     */
    public const MATCH_TOLERANCE_PCT = 0.04;
    /**
     * Platba kartou: karetní schéma přepočítá vlastním kurzem a banka k němu přidá
     * přirážku, takže proti kurzu ČNB z data dokladu se běžně liší o 4 až 5 %.
     */
    public const CARD_MATCH_TOLERANCE_PCT = 0.06;

    public static function expectedLocalAmount(float $invoiceAmount, float $rate): float
    {
        return round($invoiceAmount * ($rate > 0.0 ? $rate : 1.0), 2);
    }

    public static function matchTolerance(
        float $expectedAmount,
        float $absoluteTolerance = self::AMOUNT_TOLERANCE,
        bool $card = false,
    ): float {
        return max($absoluteTolerance, abs($expectedAmount) * self::tolerancePct($card));
    }

    public static function tolerancePct(bool $card): float
    {
        return $card ? self::CARD_MATCH_TOLERANCE_PCT : self::MATCH_TOLERANCE_PCT;
    }

    public static function isCzkPaymentOfForeignInvoice(
        ?string $transactionCurrency,
        string $invoiceCurrency,
    ): bool {
        return $transactionCurrency !== null
            && strtoupper($transactionCurrency) === self::LOCAL_CURRENCY
            && strtoupper($invoiceCurrency) !== self::LOCAL_CURRENCY;
    }

    public static function isFullCzkSettlement(
        float $transactionAmount,
        float $remaining,
        string $invoiceCurrency,
        float $rate,
        ?string $transactionCurrency,
        bool $card = false,
    ): bool {
        if ($transactionAmount <= 0.0 || $remaining <= 0.0 || $rate <= 0.0
            || !self::isCzkPaymentOfForeignInvoice($transactionCurrency, $invoiceCurrency)
        ) {
            return false;
        }
        $expected = self::expectedLocalAmount($remaining, $rate);
        return abs($transactionAmount - $expected) <= self::matchTolerance($expected, card: $card);
    }

    /**
     * Pokryje platba ZBYTEK dokladu natolik, aby ho směla uzavřít (status `paid`)?
     *
     * Hranice je táž, s jakou pak účtuje banka: ve stejné měně rozdíl do
     * {@see AMOUNT_TOLERANCE} srovná `BankPostingService::normalizeRoundingFullPurchase()`
     * na nominál a dorovná 548/648; korunová úhrada cizoměnového dokladu v kurzové
     * toleranci odúčtuje celý nominál a rozdíl jde na 563/663. Nad tou hranicí na
     * saldokontě zbytek zůstane — a doklad, který by se přesto tvářil jako uhrazený,
     * by ho před účetní schoval. Přeplatek doklad uzavírá (zbytek řeší kontrola přeplatku).
     *
     * Kombinaci měn, kterou převést neumíme (cizí měna pohybu × jiná měna dokladu),
     * nerozhodujeme: vrací true a doklad se chová jako dosud — banka ho automaticky
     * nezaúčtuje (`cross_currency`) a jde k ručnímu ověření.
     */
    public static function settlesRemaining(
        float $transactionAmount,
        float $remaining,
        string $invoiceCurrency,
        float $rate,
        ?string $transactionCurrency,
        bool $card = false,
    ): bool {
        if ($remaining <= 0.005) {
            return true;
        }
        $invoiceCurrency = strtoupper($invoiceCurrency);
        if ($transactionCurrency === null || strtoupper($transactionCurrency) === $invoiceCurrency) {
            return round($transactionAmount, 2) >= round($remaining - self::AMOUNT_TOLERANCE, 2);
        }
        if (self::isCzkPaymentOfForeignInvoice($transactionCurrency, $invoiceCurrency) && $rate > 0.0) {
            $expected = self::expectedLocalAmount($remaining, $rate);
            return $transactionAmount >= $expected - self::matchTolerance($expected, card: $card);
        }
        return true;
    }

    public static function amountInInvoiceCurrency(
        float $transactionAmount,
        string $invoiceCurrency,
        float $rate,
        ?string $transactionCurrency,
        float $fallback,
        bool $settleRemaining = false,
    ): float {
        $invoiceCurrency = strtoupper($invoiceCurrency);
        if ($transactionCurrency === null || strtoupper($transactionCurrency) === $invoiceCurrency) {
            return round($transactionAmount, 2);
        }
        if ($settleRemaining) {
            return round($fallback, 2);
        }
        if (strtoupper($transactionCurrency) === self::LOCAL_CURRENCY) {
            return round($transactionAmount / ($rate > 0.0 ? $rate : 1.0), 2);
        }
        return round($fallback, 2);
    }
}
