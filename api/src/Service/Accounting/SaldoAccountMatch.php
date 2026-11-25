<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Patří účet řádku deníku pod saldokontní účet z pravidla úhrady (321, 311)?
 *
 * Syntetika jsou první tři znaky kódu (stejně jako LEFT(account_code, 3) ve výkazech
 * a {@see PurchasePayableAccount::paymentCode()}), takže analytikou syntetiky 321 je
 * tečkovaná 321.001 i převzatá bez tečky 321001 (ABRA Flexi, Premier, starší převody).
 * Účet z pravidla, který sám je analytikou (321.001), se porovnává jen se svými
 * tečkovanými podúčty: první tři znaky tam o příslušnosti nerozhodují.
 */
final class SaldoAccountMatch
{
    private const SYNTHETIC = '/^\d{3}$/D';

    public static function isSynthetic(string $code): bool
    {
        return preg_match(self::SYNTHETIC, $code) === 1;
    }

    /** Analytika účtu $code (ne účet sám). */
    public static function isAnalyticOf(string $account, string $code): bool
    {
        if (self::isSynthetic($code)) {
            return strlen($account) > 3 && str_starts_with($account, $code);
        }
        return str_starts_with($account, $code . '.');
    }

    /** Účet $code nebo jeho analytika. */
    public static function belongsTo(string $account, string $code): bool
    {
        return $account === $code || self::isAnalyticOf($account, $code);
    }
}
