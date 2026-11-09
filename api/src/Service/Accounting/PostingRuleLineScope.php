<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Použitelnost předkontace na řádku faktury: přijaté (účet nákladu MD + závazek Dal)
 * a vydané (pohledávka MD + výnos Dal). Předkontace jsou jedna sdílená mapa pro všechny
 * druhy dokladů (banka, pokladna, mzdy, uzávěrky...), takže řádek faktury z nich smí
 * nabízet jen ty, které popisují právě fakturu daného směru.
 *
 * Systémové klíče určuje katalog níže, spolehlivě a bez ohledu na účty, které si firma
 * v přepsání pravidla nastavila. Klíč mimo katalog (import číselníku, vlastní pravidlo)
 * se odhadne z účtů: přijatá = náklad nebo majetek na MD a závazek 32x na Dal,
 * vydaná = pohledávka 31x na MD a výnos třídy 6 na Dal.
 */
final class PostingRuleLineScope
{
    /** Předkontace řádku přijaté faktury. */
    private const PURCHASE = [
        'invoice.services.received',
        'invoice.material.received',
        'invoice.small_asset.received',
        'invoice.small_intangible.received',
        'invoice.dhm.received',
    ];

    /** Předkontace řádku vydané faktury. */
    private const SALE = [
        'invoice.services.issued',
        'invoice.goods.issued',
        'invoice.products.issued',
        'invoice.penalty.issued',
    ];

    /** Klíče systémových skupin, které se na řádek faktury nikdy nenabízejí (platby, zálohy, DPH, rozlišení...). */
    private const SYSTEM_PREFIXES = [
        'accrual.', 'advance.', 'allowance.', 'asset.', 'bank.', 'cash.', 'deferred_tax.', 'deposit.',
        'depreciation.', 'estimate.', 'fx.', 'income_tax.', 'insurance.', 'inventory.', 'invoice.vat.',
        'offset.', 'oss.', 'payment.', 'payroll.', 'rent.', 'reserve.', 'small_asset.', 'stock.', 'tax.',
        'transfer.', 'vat.', 'voucher.',
    ];

    /**
     * @return array{purchase:bool, sale:bool}
     */
    public static function forRule(string $ruleKey, ?string $debitCode, ?string $creditCode): array
    {
        if (in_array($ruleKey, self::PURCHASE, true)) {
            return ['purchase' => true, 'sale' => false];
        }
        if (in_array($ruleKey, self::SALE, true)) {
            return ['purchase' => false, 'sale' => true];
        }
        foreach (self::SYSTEM_PREFIXES as $prefix) {
            if (str_starts_with($ruleKey, $prefix)) {
                return ['purchase' => false, 'sale' => false];
            }
        }

        $d = $debitCode ?? '';
        $c = $creditCode ?? '';
        $purchase = $c !== '' && str_starts_with($c, '32') && $d !== ''
            && (preg_match('/^[501]/', $d) === 1) && !str_starts_with($d, '32') && !str_starts_with($d, '343');
        $sale = $d !== '' && str_starts_with($d, '31') && $c !== '' && str_starts_with($c, '6');

        return ['purchase' => $purchase, 'sale' => $sale];
    }
}
