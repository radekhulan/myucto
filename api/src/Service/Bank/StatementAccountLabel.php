<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * Vlastní pojmenování účtu výpisu (`currencies.label`, např. „CZK - Fio Bank").
 *
 * Účet se hledá podle čísla (bez nul a oddělovačů), kódu banky a měny výpisu: banky vedou
 * korunový a cizoměnový účet pod stejným číslem (Raiffeisenbank, ČSOB) a bez měny by
 * korunový výpis dostal název EUR účtu. Když číslu a měně odpovídá víc bank, název se
 * nevrací (nejednoznačné). Jediné místo pravidla pro seznam i detail výpisu a pro návrhy
 * zaúčtování.
 */
final class StatementAccountLabel
{
    /**
     * Skalární subselect se jedním parametrem `?` (id firmy) nad aliasem výpisu `$statement`.
     */
    public static function sql(string $statement): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $statement) !== 1) {
            throw new \InvalidArgumentException('Neplatný alias.');
        }
        return "(SELECT CASE
                          WHEN COUNT(DISTINCT COALESCE(NULLIF(cur.bank_code, ''), '?')) = 1
                          THEN MAX(cur.label)
                          ELSE NULL
                        END
                   FROM currencies cur
                  WHERE cur.supplier_id = ?
                    AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(cur.account_number, ''), '[^0-9]', ''))
                      = TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL({$statement}.account_number, ''),  '[^0-9]', ''))
                    AND ({$statement}.currency IS NULL OR cur.code = {$statement}.currency)
                    AND ({$statement}.bank_code IS NULL OR cur.bank_code IS NULL OR cur.bank_code = {$statement}.bank_code))";
    }
}
