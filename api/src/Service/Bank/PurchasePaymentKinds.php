<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Druhy přijatých dokladů, které se hradí bankovní platbou. Jediné místo výčtu pro
 * návrhy párování, ruční i sloučené párování, automatické párování podle částky a data
 * a pro zaúčtování korunové úhrady cizoměnového dokladu.
 *
 * - {@see BANK_PAYABLE}: faktura, zálohová faktura a účtenka. Účtenka vzniká se
 *   závazkem stejně jako faktura (321, případně účet z nastavení firmy, viz
 *   {@see \MyInvoice\Service\Accounting\PurchasePayableAccount}) a úhrada ho odúčtuje
 *   stejnou cestou. DPH účtenky se váže na DUZP, úhrada evidenci DPH nemění.
 *   Dobropis má vlastní tok vratky (příchozí platba, CreditRefundAllocation) a DDKP
 *   (tax_document) platebním cílem není, peníze patří k zálohové faktuře.
 * - {@see CROSS_CURRENCY}: korunová úhrada cizoměnového dokladu s kurzovým rozdílem
 *   563/663 ({@see FxPaymentSettlement}). Cizoměnovou poskytnutou zálohu (314) banka
 *   automaticky nezaúčtuje (BankPostingService vrací fx_not_supported), proto se
 *   korunovou platbou nepáruje.
 */
final class PurchasePaymentKinds
{
    /** @var list<string> */
    public const BANK_PAYABLE = ['invoice', 'advance', 'receipt'];

    /** @var list<string> */
    public const CROSS_CURRENCY = ['invoice', 'receipt'];

    /** SQL podmínka „doklad se hradí bankou" nad aliasem tabulky purchase_invoices. */
    public static function bankPayableSql(string $alias): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $alias) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias tabulky.');
        }
        return $alias . ".document_kind IN ('" . implode("','", self::BANK_PAYABLE) . "')";
    }

    public static function isBankPayable(string $kind): bool
    {
        return in_array($kind, self::BANK_PAYABLE, true);
    }

    /** Smí doklad uhradit korunová platba, když je vystavený v cizí měně? */
    public static function allowsCrossCurrency(string $kind): bool
    {
        return in_array($kind, self::CROSS_CURRENCY, true);
    }
}
