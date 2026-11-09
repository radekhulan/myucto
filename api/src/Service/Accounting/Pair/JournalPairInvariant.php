<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Jediná implementace invariantů dvojic jednoduchého zápisu (I1 až I6). Čistá funkce
 * bez DB; volá ji zapisovač řádků deníku, ruční zápis i párovač, takže se pravidlo
 * nedá obejít jinou cestou.
 *
 * Platí pro zápis, jehož forma k `entry_date` je jednoduchá, a pro KAŽDÝ zápis, který
 * má aspoň jeden řádek s `pair_no`:
 *
 *  - I1 každý řádek má `pair_no`; celý zápis bez `pair_no` smí mít jednoduchá firma jen
 *    s hlavičkou `pair_status` = composite_mn | ineligible (nerozdělitelný automatický
 *    zápis, převzatý zápis);
 *  - I2 každé `pair_no` má právě dva řádky, jeden s účinnou stranou MD, druhý Dal
 *    (červené storno účinnou stranu obrací);
 *  - I3 oba řádky mají stejnou kladnou částku (v haléřích) a stejné `is_red_storno`;
 *  - I4 účty řádků dvojice se liší;
 *  - I5 čísla dvojic jsou 1..n bez mezer a v pořadí řádků (`line_no`, jinak pořadí pole)
 *    se poprvé objevují vzestupně;
 *  - I6 firma ve složené formě nesmí dostat `pair_no`. Výjimka: storno nebo přeúčtování
 *    dvojice z jednoduchého období datované do složeného (hlavička `pairs_inherited`).
 *
 * Středisko, dimenze a měnu invariant záměrně nehlídá: automatika je razítkuje jen na
 * výsledkový, resp. saldokontní řádek a vynucení shody by změnilo dnešní sestavy.
 */
final class JournalPairInvariant
{
    public const MISSING_DEBIT_ACCOUNT = 'pair_missing_debit_account';
    public const MISSING_CREDIT_ACCOUNT = 'pair_missing_credit_account';
    public const SAME_ACCOUNT = 'pair_same_account';
    public const AMOUNT_NOT_POSITIVE = 'pair_amount_not_positive';
    public const UNBALANCED = 'pair_unbalanced';
    public const INCOMPLETE = 'pair_incomplete';
    public const NUMBERING = 'pair_numbering';
    public const SINGLE_UNPAIRED = 'single_unpaired';
    public const COMPOSITE_FORM = 'pair_in_composite_form';

    /** Stavy z journal_entry_pair_status, se kterými smí jednoduchá firma zapsat zápis bez dvojic. */
    public const UNPAIRED_STATUSES = ['composite_mn', 'ineligible'];

    private const MAX_PAIR_NO = 65535;

    private const MESSAGES = [
        self::MISSING_DEBIT_ACCOUNT  => 'Dvojici chybí účet MD.',
        self::MISSING_CREDIT_ACCOUNT => 'Dvojici chybí účet Dal.',
        self::SAME_ACCOUNT           => 'Účet MD a účet Dal dvojice musí být různé.',
        self::AMOUNT_NOT_POSITIVE    => 'Částka dvojice musí být kladná.',
        self::UNBALANCED             => 'Dvojice musí mít jeden řádek MD a jeden Dal se stejnou částkou a stejným červeným stornem.',
        self::INCOMPLETE             => 'Každý řádek jednoduchého zápisu musí patřit do dvojice o dvou řádcích.',
        self::NUMBERING              => 'Čísla dvojic musí jít v pořadí řádků od 1 bez mezer.',
        self::SINGLE_UNPAIRED        => 'Zápis v období s jednoduchou formou zápisu musí být rozdělený na dvojice.',
        self::COMPOSITE_FORM         => 'Firma k datu zápisu účtuje složeným zápisem, řádky nesmí nést číslo dvojice.',
    ];

    /**
     * @param array{entry_date?:string, pair_status?:?string, pairs_inherited?:bool} $header
     * @param list<array<string,mixed>> $lines
     * @throws JournalPairException
     */
    public static function assert(array $header, array $lines, EntryForm $form): void
    {
        $paired = 0;
        foreach ($lines as $line) {
            if (self::pairNoOf($line) !== null) {
                $paired++;
            }
        }

        if ($paired === 0) {
            if ($form->isSingle() && $lines !== []
                && !in_array($header['pair_status'] ?? null, self::UNPAIRED_STATUSES, true)
            ) {
                self::fail(self::SINGLE_UNPAIRED);
            }
            return;
        }

        if (!$form->isSingle() && empty($header['pairs_inherited'])) {
            self::fail(self::COMPOSITE_FORM);
        }
        if ($paired !== count($lines)) {
            self::fail(self::INCOMPLETE);
        }

        /** @var array<int, list<array<string,mixed>>> $groups */
        $groups = [];
        $order = [];
        foreach (self::ordered($lines) as $line) {
            $pairNo = self::pairNoOf($line);
            if (!is_int($pairNo) || $pairNo < 1 || $pairNo > self::MAX_PAIR_NO) {
                self::fail(self::NUMBERING);
            }
            if (!isset($groups[$pairNo])) {
                $order[] = $pairNo;
            }
            $groups[$pairNo][] = $line;
        }
        if ($order !== range(1, count($order))) {
            self::fail(self::NUMBERING);
        }
        foreach ($groups as $pairNo => $pair) {
            if (count($pair) !== 2) {
                self::fail(self::INCOMPLETE, ['pair_no' => $pairNo]);
            }
            $violation = self::pairViolation($pair[0], $pair[1]);
            if ($violation !== null) {
                self::fail($violation, ['pair_no' => $pairNo]);
            }
        }
    }

    /**
     * Porušení I2 až I4 pro dva řádky jedné dvojice; null = platná dvojice.
     *
     * Účet se porovná podle `account_id`, u nerozřešeného vstupu podle `account_code`.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    public static function pairViolation(array $a, array $b): ?string
    {
        $sideA = self::effectiveSide($a);
        $sideB = self::effectiveSide($b);
        if ($sideA === null || $sideB === null || $sideA === $sideB) {
            return self::UNBALANCED;
        }
        [$debit, $credit] = $sideA === 'debit' ? [$a, $b] : [$b, $a];
        $debitAccount = self::accountKey($debit);
        $creditAccount = self::accountKey($credit);
        if ($debitAccount === null) {
            return self::MISSING_DEBIT_ACCOUNT;
        }
        if ($creditAccount === null) {
            return self::MISSING_CREDIT_ACCOUNT;
        }
        $debitCents = self::centsOf($debit);
        $creditCents = self::centsOf($credit);
        if ($debitCents === null || $creditCents === null || $debitCents <= 0 || $creditCents <= 0) {
            return self::AMOUNT_NOT_POSITIVE;
        }
        if ($debitCents !== $creditCents || !empty($a['is_red_storno']) !== !empty($b['is_red_storno'])) {
            return self::UNBALANCED;
        }
        if ($debitAccount === $creditAccount) {
            return self::SAME_ACCOUNT;
        }
        return null;
    }

    /** Účinná strana řádku: červené storno stranu obrací. Null = neplatná strana. */
    public static function effectiveSide(array $line): ?string
    {
        $side = $line['side'] ?? null;
        if ($side !== 'debit' && $side !== 'credit') {
            return null;
        }
        if (!empty($line['is_red_storno'])) {
            return $side === 'debit' ? 'credit' : 'debit';
        }
        return $side;
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'Neplatná dvojice účetního zápisu.';
    }

    /** `pair_no` řádku jako int; null = řádek bez dvojice; neplatná hodnota vrací -1. */
    public static function pairNoOf(array $line): ?int
    {
        $value = $line['pair_no'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d{1,5}$/D', $value) === 1) {
            return (int) $value;
        }
        return -1;
    }

    /**
     * Řádky v pořadí `line_no`, pokud ho nesou všechny; jinak v pořadí pole.
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private static function ordered(array $lines): array
    {
        foreach ($lines as $line) {
            if (!isset($line['line_no'])) {
                return array_values($lines);
            }
        }
        $indexed = [];
        foreach (array_values($lines) as $i => $line) {
            $indexed[] = [(int) $line['line_no'], $i, $line];
        }
        usort($indexed, static fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
        return array_map(static fn (array $row): array => $row[2], $indexed);
    }

    private static function accountKey(array $line): ?string
    {
        $id = $line['account_id'] ?? null;
        if ($id !== null && $id !== '' && (int) $id > 0) {
            return 'id:' . (int) $id;
        }
        $code = isset($line['account_code']) ? trim((string) $line['account_code']) : '';
        return $code !== '' ? 'code:' . $code : null;
    }

    private static function centsOf(array $line): ?int
    {
        $amount = $line['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount) && !is_string($amount)) {
            return null;
        }
        try {
            return JournalLineAmount::cents($amount);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $context */
    private static function fail(string $code, array $context = []): never
    {
        throw new JournalPairException($code, self::message($code), 422, $context);
    }
}
