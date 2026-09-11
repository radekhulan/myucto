<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Je oprava zápisu jen PŘESUNEM mezi účty, který nemá daňový dopad?
 *
 * Zámek k datu (`accounting_supplier_settings.locked_until`) se posouvá s podaným
 * přiznáním k DPH. Chrání tedy DPH, ne kontaci nákladu: evidence DPH se počítá
 * z řádků dokladu ({@see \MyInvoice\Service\Report\VatLedgerService}), ne z deníku.
 * Přeúčtování 511 → 518.100 u zamčeného data proto na podané přiznání nesahá, a přesto
 * se dosud muselo dělat stornem a novým zápisem k dnešku. Náklad se tím přesunul
 * do jiného měsíce a v deníku zůstaly tři zápisy místo jednoho.
 *
 * Tahle třída rozhoduje, kdy se smí zápis v zamčeném datu přepsat NA MÍSTĚ. Podmínky
 * (obě strany se porovnávají v haléřích, per účet a stranu):
 *   - na každé straně (MD / Dal) se celkem nic nemění, jen se částka přesouvá mezi účty,
 *   - žádný měněný účet není daňový (34x: DPH, daň z příjmů, ostatní daně),
 *   - všechny měněné účty patří do TÉŽE účtové třídy (5 ↔ 5, ne 5 ↔ 0),
 *   - nemění se daňová uznatelnost: na každé straně sedí součet daňových i nedaňových
 *     účtů zvlášť, takže 518.100 → 518.990 projde jen jako storno.
 *
 * Uzavřené účetní období (uzávěrka roku) tahle třída neřeší. To hlídá volající a platí
 * tam bez výjimky §35 ZoÚ. Je to čistá funkce, aby ji mohly zavolat obě strany téže
 * operace: {@see DocumentRepostService} (rozhodnutí před zápisem) i {@see PostingService}
 * (znovu pod zámkem, těsně před přepisem).
 */
final class TaxNeutralReclassification
{
    public const UNKNOWN_ACCOUNT = 'unknown_account';
    public const SPECIAL_ACCOUNT = 'special_account';
    public const TAX_ACCOUNT_CHANGED = 'tax_account_changed';
    public const AMOUNTS_CHANGED = 'amounts_changed';
    public const ACCOUNT_CLASS_CHANGED = 'account_class_changed';
    public const TAX_DEDUCTIBILITY_CHANGED = 'tax_deductibility_changed';

    private const MESSAGES = [
        self::UNKNOWN_ACCOUNT           => 'oprava používá účet, který není v osnově',
        self::SPECIAL_ACCOUNT           => 'oprava mění závěrkový nebo podrozvahový účet',
        self::TAX_ACCOUNT_CHANGED       => 'oprava mění účet daně (34x)',
        self::AMOUNTS_CHANGED           => 'oprava mění částky, ne jen účty',
        self::ACCOUNT_CLASS_CHANGED     => 'oprava přesouvá částku do jiné účtové třídy',
        self::TAX_DEDUCTIBILITY_CHANGED => 'oprava mění daňovou uznatelnost',
    ];

    /**
     * @param list<array{account_id:int, side:string, amount:float|int|string}> $before řádky v deníku
     * @param list<array{account_id:int, side:string, amount:float|int|string}> $after  opravené řádky
     * @param array<int, array{code:string, account_type?:string, tax_deductibility?:string}> $accounts
     *
     * @return ?string NULL = daňově neutrální přesun (nebo žádná změna), jinak kód důvodu
     */
    public static function violation(array $before, array $after, array $accounts): ?string
    {
        $diff = [];
        foreach ([[$before, -1], [$after, 1]] as [$lines, $sign]) {
            foreach ($lines as $line) {
                $key = (int) $line['account_id'] . '|' . (string) $line['side'];
                $diff[$key] = ($diff[$key] ?? 0) + $sign * (int) round(((float) $line['amount']) * 100.0);
            }
        }
        $changed = array_filter($diff, static fn (int $cents): bool => $cents !== 0);
        if ($changed === []) {
            return null;
        }

        $perSide = [];
        $perDeductibility = [];
        $classes = [];
        foreach ($changed as $key => $cents) {
            [$accountId, $side] = explode('|', (string) $key, 2);
            $account = $accounts[(int) $accountId] ?? null;
            if ($account === null) {
                return self::UNKNOWN_ACCOUNT;
            }
            $code = (string) $account['code'];
            if (in_array($account['account_type'] ?? '', ['closing', 'offbalance'], true)) {
                return self::SPECIAL_ACCOUNT;
            }
            if (str_starts_with($code, '34')) {
                return self::TAX_ACCOUNT_CHANGED;
            }
            $perSide[$side] = ($perSide[$side] ?? 0) + $cents;
            $classes[$code[0] ?? ''] = true;
            $bucket = $side . '|' . ($account['tax_deductibility'] ?? 'deductible');
            $perDeductibility[$bucket] = ($perDeductibility[$bucket] ?? 0) + $cents;
        }

        foreach ($perSide as $cents) {
            if ($cents !== 0) {
                return self::AMOUNTS_CHANGED;
            }
        }
        if (count($classes) > 1) {
            return self::ACCOUNT_CLASS_CHANGED;
        }
        foreach ($perDeductibility as $cents) {
            if ($cents !== 0) {
                return self::TAX_DEDUCTIBILITY_CHANGED;
            }
        }

        return null;
    }

    /** Lidský popis důvodu pro hlášku. */
    public static function describe(string $code): string
    {
        return self::MESSAGES[$code] ?? $code;
    }
}
