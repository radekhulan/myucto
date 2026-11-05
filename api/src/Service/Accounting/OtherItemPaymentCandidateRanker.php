<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Pořadí a důvod shody mezi platbou a ostatní položkou. Čistá funkce bez databáze:
 * sdílí ji výběr položek k pohybu ({@see OtherItemBankMatchService::candidates}) i výběr
 * plateb k položce ({@see OtherItemService::paymentCandidates}) a staví na ní i budoucí
 * automatické párování.
 *
 * Úrovně (score), od nejsilnější:
 *  - 100 VS + účet protistrany + částka splátky,
 *  -  80 VS (+5 se shodou částky splátky nebo zbytku, +3 s účtem),
 *  -  70 účet protistrany + částka splátky (+5 v okně splatnosti),
 *  -  60 částka splátky v okně splatnosti ({@see DUE_WINDOW_DAYS}),
 *  -  50 celý zbývající dluh (+5 s účtem),
 *  -  40 částka splátky mimo okno splatnosti,
 *  -  30 jen účet protistrany,
 *  -   0 nic (kandidát jen z hledání).
 * `confidence` je score / 100.
 */
final class OtherItemPaymentCandidateRanker
{
    public const AMOUNT_TOLERANCE = 0.01;
    public const DUE_WINDOW_DAYS = 15;

    public const REASON_VARIABLE_SYMBOL = 'variable_symbol';
    public const REASON_COUNTERPARTY_ACCOUNT = 'counterparty_account';
    public const REASON_INSTALLMENT_AMOUNT = 'installment_amount';
    public const REASON_INSTALLMENT_DUE = 'installment_due';
    public const REASON_REMAINING_AMOUNT = 'remaining_amount';

    /**
     * @param array{amount:float,variable_symbol:?string,counterparty_account:?string,
     *     counterparty_bank:?string,payment_on:?string} $payment volná (kladná) částka platby
     * @param array{variable_symbol:?string,counterparty_account:?string,counterparty_bank_code:?string,
     *     remaining_amount:float,installments:list<array<string,mixed>>} $item `installments` = výstup
     *     {@see OtherItemInstallmentCoverage::compute}
     * @return array{score:int,confidence:float,reasons:list<string>,installment:?array{id:int,position:int,
     *     due_on:string,remaining_amount:float}}
     */
    public static function evaluate(array $payment, array $item): array
    {
        $amount = round(abs((float) $payment['amount']), 2);
        $vs = trim((string) ($payment['variable_symbol'] ?? ''));
        $itemVs = trim((string) ($item['variable_symbol'] ?? ''));
        $vsMatch = $vs !== '' && $itemVs !== '' && ltrim($vs, '0') === ltrim($itemVs, '0');
        $accountMatch = OtherItemCounterpartyAccount::matches(
            $item['counterparty_account'] ?? null, $item['counterparty_bank_code'] ?? null,
            OtherItemCounterpartyAccount::fromTransaction($payment['counterparty_account'] ?? null, $payment['counterparty_bank'] ?? null));
        $installment = self::installment($item['installments'], $amount, $payment['payment_on'] ?? null);
        $nearDue = $installment !== null && $installment['near_due'];
        $remainingMatch = abs((float) $item['remaining_amount'] - $amount) <= self::AMOUNT_TOLERANCE + 0.0001;

        $reasons = [];
        if ($vsMatch) $reasons[] = self::REASON_VARIABLE_SYMBOL;
        if ($accountMatch) $reasons[] = self::REASON_COUNTERPARTY_ACCOUNT;
        if ($installment !== null) $reasons[] = self::REASON_INSTALLMENT_AMOUNT;
        if ($nearDue) $reasons[] = self::REASON_INSTALLMENT_DUE;
        if ($remainingMatch) $reasons[] = self::REASON_REMAINING_AMOUNT;

        $score = match (true) {
            $vsMatch && $accountMatch && $installment !== null => 100,
            $vsMatch => 80 + ($installment !== null || $remainingMatch ? 5 : 0) + ($accountMatch ? 3 : 0),
            $accountMatch && $installment !== null => 70 + ($nearDue ? 5 : 0),
            $nearDue => 60,
            $remainingMatch => 50 + ($accountMatch ? 5 : 0),
            $installment !== null => 40,
            $accountMatch => 30,
            default => 0,
        };
        if ($installment !== null) unset($installment['near_due']);
        return [
            'score' => $score,
            'confidence' => round($score / 100, 2),
            'reasons' => $reasons,
            'installment' => $installment,
        ];
    }

    /**
     * Seřadí kandidáty podle score sestupně; při shodě drží vstupní pořadí.
     *
     * @param array<string,mixed> $payment
     * @param list<array<string,mixed>> $items každý prvek nese pole pro {@see evaluate}
     * @return list<array<string,mixed>> prvky doplněné o `match` (výstup {@see evaluate})
     */
    public static function rank(array $payment, array $items): array
    {
        foreach ($items as $index => $item) {
            $items[$index]['match'] = self::evaluate($payment, $item);
        }
        usort($items, static fn (array $a, array $b): int => $b['match']['score'] <=> $a['match']['score']);
        return $items;
    }

    /**
     * Otevřená splátka se zbytkem ve výši platby; přednost má ta v okně splatnosti,
     * jinak první podle pořadí.
     *
     * @param list<array<string,mixed>> $installments
     * @return array{id:int,position:int,due_on:string,remaining_amount:float,near_due:bool}|null
     */
    private static function installment(array $installments, float $amount, ?string $paymentOn): ?array
    {
        $found = null;
        foreach ($installments as $row) {
            $open = round((float) $row['remaining_amount'], 2);
            if ($open <= 0 || abs($open - $amount) > self::AMOUNT_TOLERANCE + 0.0001) continue;
            $near = $paymentOn !== null && $paymentOn !== '' && self::withinDays((string) $row['due_on'], $paymentOn);
            $candidate = [
                'id' => (int) $row['id'], 'position' => (int) $row['position'], 'due_on' => (string) $row['due_on'],
                'remaining_amount' => $open, 'near_due' => $near,
            ];
            if ($near) return $candidate;
            $found ??= $candidate;
        }
        return $found;
    }

    private static function withinDays(string $dueOn, string $paymentOn): bool
    {
        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($dueOn, 0, 10));
        $paid = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($paymentOn, 0, 10));
        if ($due === false || $paid === false) return false;
        return (int) $due->diff($paid)->days <= self::DUE_WINDOW_DAYS;
    }
}
