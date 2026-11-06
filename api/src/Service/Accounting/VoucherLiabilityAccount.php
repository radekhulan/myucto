<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\PostingRuleRepository;

/**
 * Saldokonto prodaných víceúčelových poukazů a kreditu (§ 37c ZDPH): vlastní analytika
 * přijatých záloh „Prodané poukazy a kredit". Jediné místo, které rozhoduje, na jaký
 * účet jde prodej poukazu ({@see \MyInvoice\Service\Invoice\MultiPurposeVoucher}) a z jakého
 * se čerpá při uplatnění ({@see InvoiceSettlementService::settleVoucherRedemption()}).
 * Zůstatek analytiky (D − MD) je hodnota nevyčerpaných poukazů.
 *
 * Účet určuje předkontace `voucher.liability` (strana D). Bez vlastního účtu v ní
 * je to analytika {@see SUFFIX} pod syntetikou účtu přijatých záloh z předkontace
 * `advance.received.collection` (výchozí 324 → 324.900). Firma, která si zálohy
 * přenastavila, tak dostane analytiku pod svou syntetikou. Chybějící analytiku dohraje
 * do osnovy {@see ensure()} stejně jako analytiky mezičlenu karet
 * ({@see Card\CardClearingAccounts}).
 *
 * Analytika je vyhrazená ({@see dedicatedAnalyticSql()}): přesměr na jedinou analytiku
 * syntetiky na ni nesmí posílat běžné zálohy z proforem.
 * Evidenci jednotlivých poukazů (kódů) aplikace nevede.
 */
final class VoucherLiabilityAccount
{
    public const RULE_KEY = 'voucher.liability';
    public const SUFFIX = '900';
    public const NAME = 'Prodané poukazy a kredit';
    private const ADVANCE_RULE_KEY = 'advance.received.collection';
    private const ADVANCE_DEFAULT = '324';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $chart,
    ) {}

    /** Kód účtu poukazů bez zápisu do osnovy. */
    public function code(int $supplierId): string
    {
        $own = $this->rules->resolve($supplierId, self::RULE_KEY)['credit_account_code'] ?? null;
        if (is_string($own) && trim($own) !== '') {
            return trim($own);
        }
        return $this->synthetic($supplierId) . '.' . self::SUFFIX;
    }

    /**
     * Kód účtu poukazů; výchozí analytiku dohraje do osnovy pod syntetiku záloh
     * (idempotentně). Účet z vlastní předkontace se nezakládá: kdo si ho zvolil,
     * má ho mít v osnově, jinak zaúčtování hlasitě selže na neznámém účtu.
     */
    public function ensure(int $supplierId): string
    {
        $code = $this->code($supplierId);
        if ($this->chart->findByCode($supplierId, $code) !== null) {
            return $code;
        }
        $synthetic = $this->synthetic($supplierId);
        if ($code !== $synthetic . '.' . self::SUFFIX) {
            return $code;
        }
        $parent = $this->chart->findByCode($supplierId, $synthetic);
        if ($parent === null) {
            return $code;
        }
        $this->chart->insert($supplierId, [
            'account_code' => $code,
            'name'         => self::NAME,
            'account_type' => $parent['account_type'] ?? 'liability',
            'normal_side'  => $parent['normal_side'] ?? 'credit',
            'is_synthetic' => false,
            'parent_id'    => (int) $parent['id'],
            'is_active'    => true,
        ]);
        return $code;
    }

    /**
     * Hodnota nevyčerpaných poukazů a kreditu (D − MD analytiky) k datu, null když firma
     * deník nevede (daňová evidence) a zůstatek tedy neexistuje.
     */
    public function outstanding(int $supplierId, ?string $asOf = null): ?float
    {
        $mode = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) $mode->fetchColumn() === 'tax_evidence') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE -l.signed_amount END), 0)
               FROM chart_of_accounts a
               JOIN journal_entry_lines l ON l.account_id = a.id AND l.supplier_id = a.supplier_id
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
              WHERE a.supplier_id = ? AND a.account_code = ? AND e.posted_at IS NOT NULL
                AND (? IS NULL OR e.entry_date <= ?)"
        );
        $stmt->execute([$supplierId, $this->code($supplierId), $asOf, $asOf]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Varování k úhradě kanálem „Poukaz / kredit", která převyšuje zůstatek prodaných
     * poukazů. Platbu neblokuje: uplatněný poukaz mohl být jednoúčelový (ten se při
     * uplatnění neúčtuje) nebo prodaný před začátkem evidence.
     *
     * @return array{code:string, message:string, balance:float, amount:float}|null
     */
    public function shortfallWarning(int $supplierId, float $amountCzk): ?array
    {
        $outstanding = $this->outstanding($supplierId);
        if ($outstanding === null || (int) round($amountCzk * 100) <= (int) round($outstanding * 100)) {
            return null;
        }
        return [
            'code' => 'voucher_balance_insufficient',
            'message' => 'Zůstatek prodaných poukazů a kreditu nestačí, ověřte, zda nejde o jednoúčelový poukaz '
                . '(ten se při uplatnění neúčtuje).',
            'balance' => $outstanding,
            'amount' => round($amountCzk, 2),
        ];
    }

    /**
     * SQL podmínka „analytika $child pod syntetikou $parent je účet poukazů" pro
     * {@see PostingService::dedicatedAnalyticSql()}: výchozí analytika pod syntetikou
     * záloh, nebo účet z vlastní předkontace firmy.
     */
    public static function dedicatedAnalyticSql(string $child, string $parent): string
    {
        return "(({$child}.account_code = CONCAT({$parent}.account_code, '." . self::SUFFIX . "')
                  AND {$parent}.account_code = COALESCE((
                        SELECT SUBSTRING_INDEX(adv.credit_account_code, '.', 1) FROM posting_rules adv
                         WHERE adv.rule_key = '" . self::ADVANCE_RULE_KEY . "' AND adv.is_active = 1
                           AND adv.credit_account_code IS NOT NULL AND adv.credit_account_code <> ''
                           AND (adv.supplier_id = {$child}.supplier_id OR adv.supplier_id IS NULL)
                         ORDER BY adv.supplier_id IS NULL, adv.priority DESC LIMIT 1), '" . self::ADVANCE_DEFAULT . "'))
                OR EXISTS (
                    SELECT 1 FROM posting_rules vlr
                     WHERE vlr.rule_key = '" . self::RULE_KEY . "' AND vlr.is_active = 1
                       AND vlr.supplier_id = {$child}.supplier_id AND vlr.credit_account_code = {$child}.account_code
                ))";
    }

    /** Syntetika účtu přijatých záloh z předkontace `advance.received.collection`. */
    private function synthetic(int $supplierId): string
    {
        $code = $this->rules->resolve($supplierId, self::ADVANCE_RULE_KEY)['credit_account_code'] ?? null;
        $code = is_string($code) && trim($code) !== '' ? trim($code) : self::ADVANCE_DEFAULT;
        return explode('.', $code, 2)[0];
    }
}
