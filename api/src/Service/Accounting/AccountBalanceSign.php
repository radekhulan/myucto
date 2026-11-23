<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Znaménko zůstatku podle typu účtu a neobvyklá strana. Deník i sestavy drží zůstatek
 * jako MD − D, tahle třída ho převádí na prezentaci pro účetní.
 *
 * Znaménko se řídí TYPEM účtu (A/P/N/V): aktiva a náklady MD − D, pasiva, vlastní kapitál
 * a výnosy D − MD. Oprávky (typ aktiva, normální strana Dal) proto vycházejí záporně,
 * stejně jako je rozvaha odečítá od brutto hodnoty, a totéž platí pro účet 429 v pasivech.
 *
 * Neobvyklá strana se řídí NORMÁLNÍ STRANOU z osnovy, ne znaménkem: záporné oprávky jsou
 * v pořádku, záporná pohledávka ne. Saldní účty bez normální strany (261, 343, 395, 431)
 * neobvyklou stranu nemají. Stejné pravidlo a stejnou toleranci používá
 * {@see \MyInvoice\Repository\ClosingRepository::accountsOnUnusualSide()} v SQL.
 *
 * Protokol inventarizace záměrně neorientuje podle typu, ale podle normální strany
 * ({@see self::inventoryFactor()}): účetní do něj opisuje napočítanou částku, u oprávek
 * tedy kladné číslo, a záporné zadání by vedlo k dvojnásobnému rozdílu.
 */
final class AccountBalanceSign
{
    private const TOLERANCE = 0.005;

    /** +1 = kladný zůstatek je MD (A, N), −1 = kladný zůstatek je Dal (P, vlastní kapitál, V). */
    public static function factor(string $accountType): int
    {
        return in_array($accountType, ['liability', 'equity', 'revenue'], true) ? -1 : 1;
    }

    /** Zůstatek MD − D se znaménkem podle typu účtu. */
    public static function signed(float $debitMinusCredit, string $accountType): float
    {
        return self::clean($debitMinusCredit * self::factor($accountType));
    }

    public static function isUnusual(float $debitMinusCredit, ?string $normalSide): bool
    {
        return match ($normalSide) {
            'debit'  => $debitMinusCredit < -self::TOLERANCE,
            'credit' => $debitMinusCredit > self::TOLERANCE,
            default  => false,
        };
    }

    /** Orientace protokolu inventarizace: kladně na normální straně, saldní účet MD − D. */
    public static function inventoryFactor(?string $normalSide): int
    {
        return $normalSide === 'credit' ? -1 : 1;
    }

    /** Zaokrouhlí na haléře a odstraní zápornou nulu, která by se vypsala jako „−0,00“. */
    public static function clean(float $amount): float
    {
        $rounded = round($amount, 2);
        return $rounded == 0.0 ? 0.0 : $rounded;
    }
}
