<?php

declare(strict_types=1);

namespace MyInvoice\Service\Property\Payments;

use MyInvoice\Service\Accounting\JournalLineAmount;

final class PaymentSourceValue
{
    public static function fromLedger(string $kind, array $raw, string $currency, ?int $currencyDecimals, array $money, array $clearing): array
    {
        $amount = JournalLineAmount::decimal($clearing['amount']);
        if ($amount !== JournalLineAmount::decimal($money['amount']) || bccomp($amount, '0', 2) <= 0) self::mismatch();
        $cents = bcmul($amount, '100', 0);
        $currency = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) self::mismatch();

        if ($currency === 'CZK') {
            self::domestic($money);
            self::domestic($clearing);
            $rawAmount = JournalLineAmount::decimal($kind === 'bank' ? ltrim((string) $raw['amount'], '-') : (string) $raw['total_amount']);
            if ($rawAmount !== $amount) self::mismatch();
            return ['amount_cents' => $cents, 'currency' => 'CZK', 'native' => null];
        }
        if ($currency !== 'EUR' || $currencyDecimals !== 2) {
            PaymentException::reject('fx_review_required', ['incomplete' => true, 'capability' => 'foreign_currency_two_decimals']);
        }

        $moneyFx = self::foreign($money, $currency);
        $clearingFx = self::foreign($clearing, $currency);
        if ($moneyFx !== $clearingFx) self::mismatch();
        $rawForeign = $kind === 'bank' ? ltrim((string) $raw['amount'], '-') : ($raw['amount_foreign'] ?? null);
        if ($rawForeign === null || JournalLineAmount::decimal($rawForeign) !== $moneyFx['amount']) self::mismatch();
        if ($kind === 'cash' && JournalLineAmount::decimal($raw['total_amount']) !== $amount) self::mismatch();
        if (bcround(bcmul($moneyFx['amount'], $moneyFx['fx_rate'], 8), 2) !== $amount) self::mismatch();

        return ['amount_cents' => $cents, 'currency' => $currency, 'native' => [
            'currency' => $currency,
            'amount' => $moneyFx['amount'],
            'amount_minor' => bcmul($moneyFx['amount'], '100', 0),
            'minor_unit_decimals' => 2,
            'fx_rate' => $moneyFx['fx_rate'],
        ]];
    }

    private static function domestic(array $line): void
    {
        if (($line['currency_code'] ?? null) !== null || ($line['amount_foreign'] ?? null) !== null || ($line['fx_rate'] ?? null) !== null) self::mismatch();
    }

    private static function foreign(array $line, string $currency): array
    {
        $amount = $line['amount_foreign'] ?? null;
        $rate = $line['fx_rate'] ?? null;
        if (($line['currency_code'] ?? null) !== $currency || !is_string($amount) || !is_string($rate)) self::mismatch();
        $amount = JournalLineAmount::decimal($amount);
        if (preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,6})?$/D', $rate) !== 1 || bccomp($amount, '0', 2) <= 0 || bccomp($rate, '0', 6) <= 0) self::mismatch();
        return ['amount' => $amount, 'fx_rate' => bcround($rate, 6)];
    }

    private static function mismatch(): never
    {
        PaymentException::reject('source_clearing_mismatch');
    }
}
