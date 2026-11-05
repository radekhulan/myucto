<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Kdy smí automatika přiřadit bankovní pohyb ostatní položce. Čistá funkce bez databáze
 * nad kandidáty {@see OtherItemBankMatchService::candidates} a stavem jejich splátek
 * ({@see OtherItemInstallmentCoverage::compute}). Pořadí a důvody shody bere
 * z {@see OtherItemPaymentCandidateRanker}, sama rozhoduje jen o jistotě.
 *
 * Jistá shoda (přiřadí se bez potvrzení):
 *  - VS a účet protistrany,
 *  - VS a částka příští otevřené splátky,
 *  - VS a celý zbývající dluh,
 *  - účet protistrany a částka příští otevřené splátky, která je splatná v okně
 *    {@see OtherItemPaymentCandidateRanker::DUE_WINDOW_DAYS} dní.
 * Platba nikdy nesmí převýšit zbývající dluh (přeplatek se nepřiřazuje).
 *
 * Automatika přiřadí jen tehdy, když jistou shodu má právě jeden kandidát. Jinak
 * kandidáta se score aspoň {@see SUGGEST_MIN_SCORE} jen označí k ručnímu výběru.
 */
final class OtherItemAutoMatchPolicy
{
    public const DECISION_ALLOCATE = 'allocate';
    public const DECISION_SUGGEST = 'suggest';
    public const DECISION_NONE = 'none';

    public const REASON_VS_ACCOUNT = 'other_item_vs_account';
    public const REASON_VS_INSTALLMENT = 'other_item_vs_installment';
    public const REASON_VS_REMAINING = 'other_item_vs_remaining';
    public const REASON_ACCOUNT_INSTALLMENT = 'other_item_account_installment';

    /** Nejslabší shoda, kterou automatika nabídne k ručnímu výběru (celý zbývající dluh). */
    public const SUGGEST_MIN_SCORE = 50;

    /**
     * @param array{amount:float,variable_symbol:?string,counterparty_account:?string,
     *     counterparty_bank:?string,payment_on:?string} $payment volná (kladná) částka platby
     * @param list<array{id:int,variable_symbol:?string,counterparty_account:?string,
     *     counterparty_bank_code:?string,remaining_amount:float,installments:list<array<string,mixed>>}> $items
     * @return array{decision:string,item_id:?int,reason:?string,score:int}
     */
    public static function decide(array $payment, array $items): array
    {
        $sure = [];
        $best = 0;
        foreach ($items as $item) {
            $match = OtherItemPaymentCandidateRanker::evaluate($payment, $item);
            $best = max($best, $match['score']);
            $reason = self::sureReason($payment, $item, $match['reasons']);
            if ($reason !== null) {
                $sure[] = ['id' => (int) $item['id'], 'reason' => $reason, 'score' => $match['score']];
            }
        }
        if (count($sure) === 1) {
            return ['decision' => self::DECISION_ALLOCATE, 'item_id' => $sure[0]['id'],
                'reason' => $sure[0]['reason'], 'score' => $sure[0]['score']];
        }
        return [
            'decision' => $best >= self::SUGGEST_MIN_SCORE ? self::DECISION_SUGGEST : self::DECISION_NONE,
            'item_id' => null, 'reason' => null, 'score' => $best,
        ];
    }

    /**
     * Kód jisté shody, nebo null.
     *
     * @param array<string,mixed> $payment
     * @param array<string,mixed> $item
     * @param list<string> $reasons důvody z {@see OtherItemPaymentCandidateRanker::evaluate}
     */
    public static function sureReason(array $payment, array $item, array $reasons): ?string
    {
        $amount = round(abs((float) $payment['amount']), 2);
        if ($amount <= 0 || $amount - round((float) $item['remaining_amount'], 2) > OtherItemPaymentCandidateRanker::AMOUNT_TOLERANCE) {
            return null;
        }
        $vs = in_array(OtherItemPaymentCandidateRanker::REASON_VARIABLE_SYMBOL, $reasons, true);
        $account = in_array(OtherItemPaymentCandidateRanker::REASON_COUNTERPARTY_ACCOUNT, $reasons, true);
        $remaining = in_array(OtherItemPaymentCandidateRanker::REASON_REMAINING_AMOUNT, $reasons, true);
        $next = OtherItemInstallmentCoverage::open($item['installments'] ?? [])[0] ?? null;
        $nextAmount = $next !== null
            && abs(round((float) $next['remaining_amount'], 2) - $amount) <= OtherItemPaymentCandidateRanker::AMOUNT_TOLERANCE + 0.0001;
        $paymentOn = (string) ($payment['payment_on'] ?? '');
        $nextDue = $nextAmount && $paymentOn !== ''
            && OtherItemPaymentCandidateRanker::withinDays((string) $next['due_on'], $paymentOn);
        return match (true) {
            $vs && $account => self::REASON_VS_ACCOUNT,
            $vs && $nextAmount => self::REASON_VS_INSTALLMENT,
            $vs && $remaining => self::REASON_VS_REMAINING,
            $account && $nextDue => self::REASON_ACCOUNT_INSTALLMENT,
            default => null,
        };
    }
}
