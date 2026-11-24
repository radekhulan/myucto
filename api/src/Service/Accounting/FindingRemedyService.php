<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PostingRuleRepository;

/**
 * Návrh DOÚČTOVÁNÍ k nálezu kontroly spárovaných plateb.
 *
 * Kontrola umí říct, CO nesedí; tahle služba u části nálezů umí říct i JAK to napravit.
 * Rozdělení je záměrné a úzké — návrh se nabízí jen tam, kde je účetní řešení
 * jednoznačné z dat:
 *
 *   `amount_mismatch` u CZK úhrady cizoměnového dokladu, kde na bankovním zápisu CHYBÍ
 *   563/663 → doúčtovat kurzový rozdíl. Očekávaná částka se počítá kurzem DOKLADU,
 *   platí se kurzem dne úhrady a rozdíl JE kurzový; účty vezme z kontací fx.loss /
 *   fx.gain, protiúčet je saldokontní účet z předpisu dokladu.
 *
 *   `fx_on_czk_czk` → opak: zaúčtovaný kurzový rozdíl na CZK↔CZK transakci vzniknout
 *   NEMĚL. Návrhem je jeho STORNO, ne další zápis.
 *
 * Pro ostatní nálezy návrh VĚDOMĚ nevzniká:
 *   `amount_mismatch` z reálného přeplatku či nedoplatku — z dat nejde poznat, jestli
 *   jde o částečnou úhradu, přeplatek, nebo špatné spárování; to rozhodne člověk.
 *   `currency_mismatch` — spárování je nejspíš samo o sobě špatně a doúčtovat by
 *   znamenalo chybu zabetonovat.
 *   `counterparty_mismatch` — žádný účetní zápis se nekoná, jde o evidenci.
 *
 * Předvyplnit návrh tam, kde systém správnou odpověď nezná, by znamenalo nechat si
 * od uživatele odklepnout chybu — a schválený nesprávný zápis se hledá hůř než
 * neopravený nález.
 *
 * Read-only: nic neúčtuje, jen navrhuje.
 */
final class FindingRemedyService
{
    /** Nálezy, ke kterým existuje jednoznačné účetní řešení. */
    public const REMEDIABLE = ['amount_mismatch', 'fx_on_czk_czk'];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingRuleRepository $postingRules,
        private readonly FxSettlementPosting $settlementPosting,
    ) {}

    /**
     * Návrh doúčtování k nálezu, nebo null, když jednoznačné řešení neexistuje.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @return array{
     *   kind:string, label:string, description:string,
     *   lines:list<array{account_code:string, side:string, amount:float}>
     * }|null
     */
    public function propose(int $supplierId, string $docType, int $docId, string $issue, array $detail): ?array
    {
        return match ($issue) {
            'amount_mismatch' => $this->proposeFxDifference($supplierId, $docType, $docId, $detail),
            'fx_on_czk_czk'   => $this->proposeFxReversal($detail),
            default           => null,
        };
    }

    /**
     * Kurzový rozdíl, který na bankovním zápisu chybí.
     *
     * Návrh vzniká JEN u cizoměnového dokladu hrazeného v CZK. U tuzemského dokladu
     * je rozdíl přeplatek nebo nedoplatek, ne kurzový rozdíl — a ten se automaticky
     * doúčtovat nedá.
     *
     * @param array<string,mixed> $detail
     */
    private function proposeFxDifference(int $supplierId, string $docType, int $docId, array $detail): ?array
    {
        $diff = round((float) ($detail['diff'] ?? 0), 2);
        if ((int) round($diff * 100) === 0) {
            return null;
        }
        // Kurzový rozdíl už zaúčtovaný je → není co doúčtovávat.
        if ((int) round(((float) ($detail['fx_booked'] ?? 0)) * 100) !== 0) {
            return null;
        }
        if (!$this->documentIsForeignCurrency($supplierId, $docType, $docId)) {
            return null;
        }

        [$loss, $gain] = $this->fxResultAccounts($supplierId);
        try {
            $settlement = $this->settlementPosting->saldoAccountOrFallback(
                $supplierId, $docType, $docId, $docType === 'purchase_invoice' ? '321' : '311',
            );
        } catch (PostingException $e) {
            if (!in_array($e->errorCode, ['opening_saldo_ambiguous', 'posting_group_saldo_ambiguous'], true)) {
                throw $e;
            }
            return null;
        }
        $amount = abs($diff);

        // Zaplaceno MÍŇ, než činil předpis v CZK → závazek se vypořádal levněji, tedy
        // kurzový ZISK. Opačně kurzová ztráta. U pohledávky se strany obracejí.
        $isGain = $docType === 'purchase_invoice' ? $diff < 0 : $diff > 0;

        $lines = $isGain
            ? [
                ['account_code' => $settlement, 'side' => 'debit',  'amount' => $amount],
                ['account_code' => $gain,       'side' => 'credit', 'amount' => $amount],
            ]
            : [
                ['account_code' => $loss,       'side' => 'debit',  'amount' => $amount],
                ['account_code' => $settlement, 'side' => 'credit', 'amount' => $amount],
            ];

        return [
            'kind'        => 'fx_difference',
            'label'       => sprintf('Zaúčtovat kurzový rozdíl %s Kč', number_format($amount, 2, ',', ' ')),
            'description' => sprintf(
                'Kurzový %s %s Kč — předpis přepočtený kurzem dokladu se liší od skutečné úhrady. '
                    . 'Rozdíl patří na %s.',
                $isGain ? 'zisk' : 'ztráta',
                number_format($amount, 2, ',', ' '),
                $isGain ? $gain : $loss,
            ),
            'lines'       => $lines,
        ];
    }

    /**
     * Kurzový rozdíl zaúčtovaný na transakci, kde JSOU obě strany v CZK — konverze tam
     * neproběhla, takže rozdíl vzniknout nemohl. Návrhem je STORNO, ne doúčtování.
     *
     * @param array<string,mixed> $detail
     */
    private function proposeFxReversal(array $detail): ?array
    {
        $amount = round(abs((float) ($detail['amount'] ?? 0)), 2);
        if ((int) round($amount * 100) === 0) {
            return null;
        }

        return [
            'kind'        => 'fx_reversal',
            'label'       => sprintf('Stornovat kurzový rozdíl %s Kč', number_format($amount, 2, ',', ' ')),
            'description' => 'Na transakci jsou obě strany v korunách, takže žádná konverze neproběhla '
                . 'a kurzový rozdíl vzniknout nemohl. Zápis je třeba stornovat, ne doúčtovat — '
                . 'stornujte bankovní zápis a zaúčtujte jej znovu.',
            'lines'       => [],
        ];
    }

    private function documentIsForeignCurrency(int $supplierId, string $docType, int $docId): bool
    {
        $table = $docType === 'purchase_invoice' ? 'purchase_invoices' : 'invoices';
        $stmt = $this->db->pdo()->prepare(
            "SELECT cur.code FROM {$table} d JOIN currencies cur ON cur.id = d.currency_id WHERE d.supplier_id = ? AND d.id = ?"
        );
        $stmt->execute([$supplierId, $docId]);
        $code = $stmt->fetchColumn();

        return $code !== false && (string) $code !== 'CZK';
    }

    /**
     * Účty kurzového rozdílu z kontací fx.loss (MD 563) / fx.gain (D 663) — táž cesta
     * jako v `BankPostingService`, aby se návrh nerozešel s tím, co účtuje banka.
     *
     * @return array{0:string, 1:string} [loss, gain]
     */
    private function fxResultAccounts(int $supplierId): array
    {
        $lossRule = $this->postingRules->resolve($supplierId, 'fx.loss');
        $gainRule = $this->postingRules->resolve($supplierId, 'fx.gain');

        return [
            (string) (($lossRule['debit_account_code'] ?? null) ?: '563'),
            (string) (($gainRule['credit_account_code'] ?? null) ?: '663'),
        ];
    }
}
