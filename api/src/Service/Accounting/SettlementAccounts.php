<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Jediný zdroj pravdy pro účty, které nesmí stát na položce dokladu jako protiúčet
 * výnosu nebo nákladu: saldokonto odběratelů a dodavatelů (311, 321), zálohy (314, 324),
 * ostatní závazky (325), zúčtování se zaměstnanci, institucemi a DPH (33x, 34x) a peněžní
 * účty (211, 221).
 *
 * Takový účet na položce by obešel saldokonto nebo evidenci DPH: pohledávka z faktury
 * a její úhrada se párují na 311/321, DPH vede jen VatLedgerService na 343. Platí pro
 * položku přijaté i vydané faktury, pravidla nákladů a výnosů i AI návrhy.
 */
final class SettlementAccounts
{
    public const PREFIXES = ['311', '321', '314', '324', '325', '33', '34', '221', '211'];

    public static function isSettlement(string $code): bool
    {
        $code = trim($code);
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** Vzor pro MariaDB `REGEXP` nad kódem účtu. */
    public static function sqlRegexp(): string
    {
        return '^(' . implode('|', self::PREFIXES) . ')';
    }
}
