<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Accrual;

/**
 * Druhy položek časového rozlišení a jejich účty. Rozpouštění v agendě podporují náklady
 * (381) a výnosy (384) příštích období; ostatní druhy jsou v datovém modelu připravené
 * (komplexní náklady 382, výdaje a příjmy příštích období 383/385 s vypořádáním dokladem)
 * a v odsouhlasení se jejich účty zobrazují.
 */
final class AccrualKinds
{
    public const PREFIXES = [
        'prepaid_expense' => '381',
        'complex_cost' => '382',
        'accrued_expense' => '383',
        'deferred_revenue' => '384',
        'accrued_revenue' => '385',
    ];

    /** Druhy, které agenda umí založit a rozpouštět. */
    public const SUPPORTED = ['prepaid_expense', 'deferred_revenue'];

    public const STATUSES = ['draft', 'active', 'completed', 'terminated', 'cancelled'];

    public const SOURCE_TYPES = ['invoice', 'purchase_invoice', 'other_item', 'internal_document', 'manual', 'migration'];

    /** Aktivní (MD) zůstatek +1, pasivní (D) −1: zůstatek položky = (MD − D) × znaménko. */
    public static function normalSign(string $kind): int
    {
        return in_array($kind, ['deferred_revenue', 'accrued_expense'], true) ? -1 : 1;
    }

    public static function kindForAccount(string $accountCode): ?string
    {
        $prefix = substr($accountCode, 0, 3);
        $kind = array_search($prefix, self::PREFIXES, true);
        return $kind === false ? null : (string) $kind;
    }

    /**
     * Řádky zápisu rozpuštění: snížení zůstatku 38x proti protiúčtu. Záporná částka
     * (dobropis, oprava) strany prohodí.
     *
     * @return list<array{account_code:string, side:string, amount:float, cost_center?:?string}>
     */
    public static function releaseLines(string $kind, string $accrualAccount, string $counterAccount, float $amount, ?string $costCenter = null): array
    {
        $reduceSide = self::normalSign($kind) === 1 ? 'credit' : 'debit';
        $other = $reduceSide === 'credit' ? 'debit' : 'credit';
        if ($amount < 0) {
            [$reduceSide, $other] = [$other, $reduceSide];
            $amount = -$amount;
        }
        $amount = round($amount, 2);
        return [
            ['account_code' => $accrualAccount, 'side' => $reduceSide, 'amount' => $amount],
            ['account_code' => $counterAccount, 'side' => $other, 'amount' => $amount, 'cost_center' => $costCenter],
        ];
    }

    /** Podpis vazby rozpuštění na účet 38x (MD − D): snižuje zůstatek položky. */
    public static function releaseLinkAmount(string $kind, float $amount): float
    {
        return round(-self::normalSign($kind) * $amount, 2);
    }
}
