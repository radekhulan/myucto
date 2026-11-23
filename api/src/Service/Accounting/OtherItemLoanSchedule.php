<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/**
 * Návrh splátkového kalendáře úvěru, půjčky nebo leasingu s rozdělením na jistinu,
 * úrok a poplatek. Čistá funkce bez databáze; výsledek je jen návrh pro editor splátek,
 * uživatel ho může upravit a uloží ho {@see OtherItemScheduleService::setInstallments}.
 *
 * Úrok období = zůstatek jistiny × roční sazba / počet období v roce (12 nebo 4),
 * zaokrouhlený na haléře, jak počítají banky u měsíčně a čtvrtletně splácených úvěrů.
 *
 *  * Anuita: stejná splátka jistiny a úroku A = P·r / (1 − (1 + r)^−n) zaokrouhlená
 *    na haléře, jistina období = A − úrok. Poslední splátka doplatí přesný zůstatek
 *    jistiny, takže se může od ostatních lišit o haléře.
 *  * Lineární: stejná jistina P / n na haléře, zbytek po zaokrouhlení nese poslední
 *    splátka; úrok klesá se zůstatkem.
 *
 * Součet jistiny je vždy přesně P. Poplatek (např. za vedení úvěru) se přičte ke každé
 * splátce. Nulová sazba dá splátky bez úroku.
 */
final class OtherItemLoanSchedule
{
    public const METHODS = ['annuity', 'linear'];
    public const FREQUENCIES = ['monthly' => 1, 'quarterly' => 3];
    public const MIN_COUNT = 2;
    public const MAX_COUNT = 120;

    /**
     * @param array<string,mixed> $input principal, annual_rate (% p. a.), count, first_due_on,
     *     frequency (monthly|quarterly), method (annuity|linear), fee (na splátku, volitelně)
     * @return list<array{due_on:string,amount:float,principal_amount:float,interest_amount:float,fee_amount:float}>
     */
    public static function plan(array $input): array
    {
        $principal = self::money($input['principal'] ?? null, 'invalid_loan_principal', 'Jistina musí být kladná částka na haléře.', false);
        $rate = filter_var($input['annual_rate'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($rate === false || !is_finite((float) $rate) || $rate < 0 || $rate > 100
            || round((float) $rate, 4) !== (float) $rate) {
            throw new OtherItemException('invalid_loan_rate', 'Roční úroková sazba musí být 0 až 100 % nejvýš na čtyři desetinná místa.');
        }
        $count = filter_var($input['count'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => self::MIN_COUNT, 'max_range' => self::MAX_COUNT]]);
        if ($count === false) {
            throw new OtherItemException('invalid_loan_count', 'Zadejte 2 až 120 splátek.');
        }
        $frequency = (string) ($input['frequency'] ?? 'monthly');
        if (!isset(self::FREQUENCIES[$frequency])) {
            throw new OtherItemException('invalid_frequency', 'Vyberte měsíční nebo čtvrtletní splátky.');
        }
        $method = (string) ($input['method'] ?? 'annuity');
        if (!in_array($method, self::METHODS, true)) {
            throw new OtherItemException('invalid_loan_method', 'Vyberte anuitní nebo lineární splácení.');
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['first_due_on'] ?? ''));
        if ($first === false || $first->format('Y-m-d') !== (string) $input['first_due_on']) {
            throw new OtherItemException('invalid_date', 'Zadejte platné datum první splátky.');
        }
        $fee = self::money($input['fee'] ?? 0, 'invalid_loan_fee', 'Poplatek musí být nezáporná částka na haléře.', true);

        $periodRate = (float) $rate / 100 / (12 / self::FREQUENCIES[$frequency]);
        $balance = $principal;
        $annuity = $method === 'annuity' ? self::annuityCents($principal, $periodRate, $count) : 0;
        $linear = intdiv($principal, $count);
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $interest = (int) round($balance * $periodRate);
            $last = $index === $count - 1;
            $part = match (true) {
                $last => $balance,
                $method === 'annuity' => max(0, min($balance, $annuity - $interest)),
                default => min($balance, $linear),
            };
            $balance -= $part;
            $rows[] = [
                'due_on' => self::dueDate($first, $index * self::FREQUENCIES[$frequency]),
                'amount' => ($part + $interest + $fee) / 100.0,
                'principal_amount' => $part / 100.0,
                'interest_amount' => $interest / 100.0,
                'fee_amount' => $fee / 100.0,
            ];
        }
        return $rows;
    }

    private static function annuityCents(int $principal, float $periodRate, int $count): int
    {
        if ($periodRate <= 0.0) {
            return (int) ceil($principal / $count);
        }
        return (int) round($principal * $periodRate / (1 - (1 + $periodRate) ** -$count));
    }

    /** Den splatnosti první splátky v každém dalším období, nejvýš poslední den měsíce. */
    private static function dueDate(\DateTimeImmutable $first, int $months): string
    {
        $month = $first->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int) $first->format('d'), (int) $month->format('t'));
        return $month->setDate((int) $month->format('Y'), (int) $month->format('m'), $day)->format('Y-m-d');
    }

    private static function money(mixed $value, string $code, string $message, bool $allowZero): int
    {
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite((float) $number) || $number < 0 || (!$allowZero && $number <= 0)
            || $number > 999999999999.99 || round((float) $number, 2) !== (float) $number) {
            throw new OtherItemException($code, $message);
        }
        return (int) round((float) $number * 100);
    }
}
