<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

/**
 * Syntetická source_id pro idempotentní klíče závěrkových zápisů (Epic F4, R6).
 *
 * Klíč zápisu je (supplier_id, source_type, source_id); pro source_type
 * 'fx_revaluation' se sloty odvozují z period_id: `period_id * 10 + SLOT_*`
 * (BIGINT dává ×10 obrovský headroom). Closing = ('closing', period_id),
 * opening = ('opening', next_period_id) — bez slotů.
 *
 * Krok „Zásoby" (SKLAD §3.4, způsob B) účtuje přes source_type 'closing'
 * (ENUM deníku nemá vyhrazený typ; 'stock' je rezervovaný pro způsob A v2).
 * Aby se source_id NIKDY nesrazil s close_books zápisem (source_id = plain
 * period_id, tentýž source_type 'closing'), jsou skladové sloty odsazené do
 * disjunktního vysokého pásma STOCK_SLOT_BASE + period_id*10 + SLOT (≥ 1e12,
 * mimo dosah period_id i FX slotů) — jinak by u firmy s obdobími v poměru
 * ~10:1 mohl skladový zápis přepsat close_books zápis (tichá koruze závěrky).
 */
final class ClosingSourceId
{
    public const PROVISION_BASE = 4_000_000_000_000;

    /** Odsazení skladových slotů mimo rozsah period_id (close_books) a FX slotů. */
    public const STOCK_SLOT_BASE = 1_000_000_000_000;

    /**
     * Odsazení rozpouštěcího zápisu časového rozlišení drobného majetku (§DM) v N+1
     * mimo rozsah period_id — defer zápis N nese source_id = plain period_id
     * (source_type 'small_asset_accrual'), rozpuštění v N+1 musí mít disjunktní klíč,
     * jinak by findBySource vracel jeden zápis pro obě strany. 2e12 leží nad
     * STOCK_SLOT_BASE i nad reálným rozsahem period_id, takže se nesrazí.
     */
    public const SMALL_ASSET_RELEASE_BASE = 2_000_000_000_000;

    /**
     * Odsazení rozpouštěcího zápisu časového rozlišení nákladů příštích období (§DČR) v N+1
     * mimo rozsah period_id — defer zápis N nese source_id = plain period_id (source_type
     * 'prepaid_expense_accrual'), rozpuštění v N+1 musí mít disjunktní klíč. 3e12 leží nad
     * SMALL_ASSET_RELEASE_BASE i nad reálným rozsahem period_id, takže se nesrazí.
     */
    public const PREPAID_EXPENSE_RELEASE_BASE = 3_000_000_000_000;

    /** FX přecenění saldokonta (311/321) k rozvahovému dni (R10a). */
    public const SLOT_FX_SALDO = 1;

    /** FX přecenění banky/valutové pokladny k rozvahovému dni (R10b). */
    public const SLOT_FX_BANK = 2;

    /** Storno přecenění saldokonta k 1. dni následujícího období (R11). */
    public const SLOT_FX_REVERSAL = 3;

    /** Konečný stav zásob k rozvahovému dni — MD 112/132 / D 501/504 (SKLAD §3.4 krok 2). */
    public const SLOT_STOCK_CLOSING = 4;

    /** Reklasifikace inventurních mank do 549 (SKLAD §3.4 krok 3). */
    public const SLOT_STOCK_SHORTAGE = 5;

    /** Inventurní přebytky do 648 (SKLAD §3.4 krok 4). */
    public const SLOT_STOCK_SURPLUS = 6;

    /** Otevření roku — počáteční stav zásob zpět do spotřeby, posted do N+1 (SKLAD §3.4 krok 5). */
    public const SLOT_STOCK_OPENING = 7;

    public static function fxSaldo(int $periodId): int
    {
        return $periodId * 10 + self::SLOT_FX_SALDO;
    }

    public static function fxBank(int $periodId): int
    {
        return $periodId * 10 + self::SLOT_FX_BANK;
    }

    public static function fxReversal(int $periodId): int
    {
        return $periodId * 10 + self::SLOT_FX_REVERSAL;
    }

    public static function stockClosing(int $periodId): int
    {
        return self::STOCK_SLOT_BASE + $periodId * 10 + self::SLOT_STOCK_CLOSING;
    }

    public static function stockShortage(int $periodId): int
    {
        return self::STOCK_SLOT_BASE + $periodId * 10 + self::SLOT_STOCK_SHORTAGE;
    }

    public static function stockSurplus(int $periodId): int
    {
        return self::STOCK_SLOT_BASE + $periodId * 10 + self::SLOT_STOCK_SURPLUS;
    }

    public static function stockOpening(int $periodId): int
    {
        return self::STOCK_SLOT_BASE + $periodId * 10 + self::SLOT_STOCK_OPENING;
    }

    /** Časové rozlišení drobného majetku (§DM) — defer zápis MD 381 / D 501 v období N. */
    public static function smallAssetAccrual(int $periodId): int
    {
        return $periodId;
    }

    /** Rozpuštění časového rozlišení drobného majetku (MD 501 / D 381) v N+1 — disjunktní klíč. */
    public static function smallAssetAccrualRelease(int $periodId): int
    {
        return self::SMALL_ASSET_RELEASE_BASE + $periodId;
    }

    /** Časové rozlišení nákladů příštích období (§DČR) — defer zápis MD 381 / D 5xx v období N. */
    public static function prepaidExpenseAccrual(int $periodId): int
    {
        return $periodId;
    }

    /** Rozpuštění časového rozlišení nákladů příštích období (MD 5xx / D 381) v N+1 — disjunktní klíč. */
    public static function prepaidExpenseAccrualRelease(int $periodId): int
    {
        return self::PREPAID_EXPENSE_RELEASE_BASE + $periodId;
    }

    /**
     * Všechny FX sloty období — pro abort/reopen guard a revert kroků (R3, R12).
     *
     * @return list<int>
     */
    public static function allFxSlots(int $periodId): array
    {
        return [self::fxSaldo($periodId), self::fxBank($periodId), self::fxReversal($periodId)];
    }

    /**
     * Sloty kroku „Zásoby" účtované DO uzavíraného období (source_type 'closing');
     * opening release (SLOT_STOCK_OPENING) žije až v N+1 a reverte se s open_next.
     *
     * @return list<int>
     */
    public static function stockClosingSlots(int $periodId): array
    {
        return [self::stockClosing($periodId), self::stockShortage($periodId), self::stockSurplus($periodId)];
    }
}
