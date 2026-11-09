<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Service\Accounting\JournalLineAmount;

/**
 * Rozloží vyvážené řádky zápisu na dvojice jednoduchého zápisu. Čistá třída bez DB,
 * deterministická (stejný vstup = stejný výstup), jediné místo, kde se dvojice tvoří:
 * zaúčtování dokladů, ruční zápis, převod starších zápisů i zobrazení starších 1 : N
 * zápisů po dvojicích za běhu.
 *
 * Vstup = rozřešené řádky z PostingService::prepareDocumentLines() (`account_id`, `side`,
 * `amount`, `is_red_storno`, měna, středisko, zakázka, dimenze, rozpady, `*_origins`).
 * Volitelné klíče řádku, které smí nastavit builder (kontrakt):
 *
 *  - `pair_no`    řádek už je dvojice (ruční zápis, šablona, přeúčtování); jen se ověří;
 *  - `pair_group` řetězec, páruje se jen uvnitř skupiny (mzdy, záloha, přenesení daně,
 *                 položka faktury s vlastním účtem Dal); NULL = jedna společná skupina;
 *  - `pair_role`  `'rounding'` = haléřové vyrovnání, smí se párovat postupně (krok c);
 *  - `line_text`  text dvojice (zapíše se na oba řádky);
 *  - `pair_link`  vazba dvojice na doklad pro hromadnou platbu; pairer ji nechá na řádku.
 *
 * Postup ve skupině:
 *  a) shodná částka: částka, která je na obou účinných stranách ve stejném počtu řádků,
 *     se páruje po pořadí (stejné pravidlo jako zobrazení protiúčtu v
 *     Reports\JournalLineContext); dvojice se stejným účtem se tu nepáruje;
 *  b) 1 : N: jediný řádek jedné strany se rozdělí na díly podle řádků protistrany;
 *  c) zbývá-li na straně jeden „velký" řádek a ostatní jsou `rounding`, páruje se
 *     postupně (severozápadní roh) s velkým řádkem první;
 *  d) jinak je skupina M : N bez pravidla a celý zápis je `unpairable`.
 *
 * Dělený řádek: díly dědí všechny klíče řádku (účet, strana, storno, středisko, zakázka,
 * dimenze, rozpady v procentech, `*_origins`, měna a kurz); `amount_foreign` se dělí
 * poměrem v haléřích, zbytek na poslední díl. Řádek, který se nedělí, zůstává beze
 * změny. Součty po účtech, stranách, měně a dimenzích se tím nemění.
 *
 * Výstup: dvojice seřazené podle `pair_no`, v každé nejdřív účinná strana MD, pak Dal.
 * Nesl-li vstup `line_no`, řádky se přečíslují souvisle od nejmenšího vstupního
 * `line_no`; jinak `line_no` nenastavuje (zapisovač čísluje podle pořadí).
 * Klíče `pair_group` a `pair_role` výstup nenese. Při `unpairable` vrací vstup beze změny.
 */
final class JournalPairer
{
    public const STATUS_PAIRED = 'paired';
    public const STATUS_UNPAIRABLE = 'unpairable';

    public const ROLE_ROUNDING = 'rounding';

    public const REASON_MANY_TO_MANY = 'many_to_many';
    public const REASON_SAME_ACCOUNT = JournalPairInvariant::SAME_ACCOUNT;
    public const REASON_MIXED_RED_STORNO = 'mixed_red_storno';
    public const REASON_GROUP_UNBALANCED = 'group_unbalanced';

    /** Řídicí klíče builderu, které pairer spotřebuje a do výstupu nepropustí. */
    public const CONTROL_KEYS = ['pair_group', 'pair_role'];

    /**
     * @param list<array<string,mixed>> $resolvedLines
     * @param int $firstPairNo první číslo pro nově vzniklé dvojice (pokračování za
     *                         zachovanými dvojicemi při výměně části zápisu)
     * @return array{lines: list<array<string,mixed>>, status: 'paired'|'unpairable', reason?: string}
     * @throws JournalPairException když vstupní dvojice s `pair_no` neplatí
     */
    public static function pair(array $resolvedLines, int $firstPairNo = 1): array
    {
        $input = array_values($resolvedLines);
        if ($input === []) {
            return ['lines' => [], 'status' => self::STATUS_PAIRED];
        }

        $preset = [];
        $groups = [];
        foreach ($input as $idx => $line) {
            $pairNo = JournalPairInvariant::pairNoOf($line);
            if ($pairNo !== null) {
                if ($pairNo < 1) {
                    throw new JournalPairException(
                        JournalPairInvariant::NUMBERING,
                        JournalPairInvariant::message(JournalPairInvariant::NUMBERING),
                    );
                }
                $preset[$pairNo][] = $idx;
                continue;
            }
            $group = isset($line['pair_group']) && $line['pair_group'] !== '' ? 'g:' . (string) $line['pair_group'] : 'null';
            $groups[$group][] = $idx;
        }

        /** @var list<array{pair_no:?int, key:array{int,int,int}, debit:array<string,mixed>, credit:array<string,mixed>, text_from_counter:bool}> $pairs */
        $pairs = [];
        $maxPreset = 0;
        foreach ($preset as $pairNo => $indexes) {
            if (count($indexes) !== 2) {
                throw new JournalPairException(
                    JournalPairInvariant::INCOMPLETE,
                    JournalPairInvariant::message(JournalPairInvariant::INCOMPLETE),
                    422,
                    ['pair_no' => $pairNo],
                );
            }
            [$a, $b] = [$input[$indexes[0]], $input[$indexes[1]]];
            $violation = JournalPairInvariant::pairViolation($a, $b);
            if ($violation !== null) {
                throw new JournalPairException($violation, JournalPairInvariant::message($violation), 422, ['pair_no' => $pairNo]);
            }
            $aDebit = JournalPairInvariant::effectiveSide($a) === 'debit';
            $pairs[] = [
                'pair_no' => $pairNo,
                'key'     => [$indexes[0], $indexes[1], 0],
                'debit'   => $aDebit ? $a : $b,
                'credit'  => $aDebit ? $b : $a,
                'text_from_counter' => false,
            ];
            $maxPreset = max($maxPreset, $pairNo);
        }

        $generated = [];
        foreach ($groups as $indexes) {
            $result = self::pairGroup($input, $indexes);
            if (isset($result['reason'])) {
                return ['lines' => $resolvedLines, 'status' => self::STATUS_UNPAIRABLE, 'reason' => $result['reason']];
            }
            array_push($generated, ...$result['pairs']);
        }
        usort($generated, static fn (array $x, array $y): int => $x['key'] <=> $y['key']);
        $next = max($maxPreset, $firstPairNo - 1) + 1;
        foreach ($generated as $pair) {
            $pair['pair_no'] = $next++;
            $pairs[] = $pair;
        }
        usort($pairs, static fn (array $x, array $y): int => $x['pair_no'] <=> $y['pair_no']);

        $lineNoBase = null;
        foreach ($input as $line) {
            if (isset($line['line_no'])) {
                $lineNoBase = $lineNoBase === null ? (int) $line['line_no'] : min($lineNoBase, (int) $line['line_no']);
            }
        }

        $out = [];
        foreach ($pairs as $pair) {
            $text = self::pairText($pair['debit'], $pair['credit'], $pair['text_from_counter'] ?? false, $pair['counter_is_debit'] ?? false);
            foreach ([$pair['debit'], $pair['credit']] as $line) {
                foreach (self::CONTROL_KEYS as $key) {
                    unset($line[$key]);
                }
                $line['pair_no'] = $pair['pair_no'];
                if ($text !== null) {
                    $line['line_text'] = $text;
                } else {
                    unset($line['line_text']);
                }
                if ($lineNoBase !== null) {
                    $line['line_no'] = $lineNoBase + count($out);
                }
                $out[] = $line;
            }
        }

        return ['lines' => $out, 'status' => self::STATUS_PAIRED];
    }

    /**
     * Řádky bez řídicích klíčů a bez `pair_no` / `line_text` — tvar složeného zápisu,
     * když volající zápis nerozdělí (unpairable, složená firma).
     *
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    public static function stripPairKeys(array $lines): array
    {
        return array_map(static function (array $line): array {
            foreach ([...self::CONTROL_KEYS, 'pair_no', 'line_text'] as $key) {
                unset($line[$key]);
            }
            return $line;
        }, array_values($lines));
    }

    /**
     * @param list<array<string,mixed>> $input
     * @param list<int> $indexes
     * @return array{pairs: list<array<string,mixed>>}|array{reason: string}
     */
    private static function pairGroup(array $input, array $indexes): array
    {
        $info = [];
        foreach ($indexes as $idx) {
            $line = $input[$idx];
            $side = JournalPairInvariant::effectiveSide($line);
            if ($side === null) {
                return ['reason' => self::REASON_GROUP_UNBALANCED];
            }
            $info[$idx] = [
                'side'     => $side,
                'cents'    => JournalLineAmount::cents($line['amount']),
                'rounding' => ($line['pair_role'] ?? null) === self::ROLE_ROUNDING,
            ];
        }

        $pairs = [];
        $used = [];

        // a) shodná částka ve stejném počtu řádků na obou účinných stranách
        $buckets = [];
        foreach ($indexes as $idx) {
            $buckets[$info[$idx]['cents']][$info[$idx]['side']][] = $idx;
        }
        foreach ($buckets as $sides) {
            $debits = $sides['debit'] ?? [];
            $credits = $sides['credit'] ?? [];
            if ($debits === [] || count($debits) !== count($credits)) {
                continue;
            }
            foreach ($debits as $k => $d) {
                $c = $credits[$k];
                if (JournalPairInvariant::pairViolation($input[$d], $input[$c]) !== null) {
                    continue;
                }
                $used[$d] = $used[$c] = true;
                $pairs[] = [
                    'key'    => [min($d, $c), max($d, $c), 0],
                    'debit'  => $input[$d],
                    'credit' => $input[$c],
                    'text_from_counter' => false,
                ];
            }
        }

        $debits = [];
        $credits = [];
        foreach ($indexes as $idx) {
            if (!isset($used[$idx])) {
                if ($info[$idx]['side'] === 'debit') {
                    $debits[] = $idx;
                } else {
                    $credits[] = $idx;
                }
            }
        }
        if ($debits === [] && $credits === []) {
            return ['pairs' => $pairs];
        }
        if ($debits === [] || $credits === []) {
            return ['reason' => self::REASON_GROUP_UNBALANCED];
        }
        $sum = static fn (array $list): int => array_sum(array_map(static fn (int $i): int => $info[$i]['cents'], $list));
        if ($sum($debits) !== $sum($credits)) {
            return ['reason' => self::REASON_GROUP_UNBALANCED];
        }

        // b) 1 : N — jediný řádek strany je hub; c) jeden velký řádek + haléřové řádky
        $big = null;
        if (count($debits) === 1) {
            $big = 'debit';
        } elseif (count($credits) === 1) {
            $big = 'credit';
        } elseif (self::singleNonRounding($debits, $info)) {
            $big = 'debit';
        } elseif (self::singleNonRounding($credits, $info)) {
            $big = 'credit';
        }
        if ($big === null) {
            return ['reason' => self::REASON_MANY_TO_MANY];
        }
        $hubSide = $big === 'debit' ? self::bigFirst($debits, $info) : self::bigFirst($credits, $info);
        $counterSide = $big === 'debit' ? $credits : $debits;

        $cells = self::northWestCorner($hubSide, $counterSide, $info);
        $pieces = [];
        foreach ($cells as [$h, $c, $cents]) {
            $pieces[$h][] = $cents;
            $pieces[$c][] = $cents;
        }
        $split = [];
        foreach ($pieces as $idx => $parts) {
            $split[$idx] = self::split($input[$idx], $parts);
        }
        $cursor = [];
        foreach ($cells as $seq => [$h, $c]) {
            $hubPiece = $split[$h][$cursor[$h] = ($cursor[$h] ?? -1) + 1];
            $counterPiece = $split[$c][$cursor[$c] = ($cursor[$c] ?? -1) + 1];
            if (!empty($hubPiece['is_red_storno']) !== !empty($counterPiece['is_red_storno'])) {
                return ['reason' => self::REASON_MIXED_RED_STORNO];
            }
            if (JournalPairInvariant::pairViolation($hubPiece, $counterPiece) === JournalPairInvariant::SAME_ACCOUNT) {
                return ['reason' => self::REASON_SAME_ACCOUNT];
            }
            if (JournalPairInvariant::pairViolation($hubPiece, $counterPiece) !== null) {
                return ['reason' => self::REASON_GROUP_UNBALANCED];
            }
            $pairs[] = [
                'key'    => [min($h, $c), max($h, $c), $seq],
                'debit'  => $big === 'debit' ? $hubPiece : $counterPiece,
                'credit' => $big === 'debit' ? $counterPiece : $hubPiece,
                'text_from_counter' => true,
                'counter_is_debit'  => $big !== 'debit',
            ];
        }

        return ['pairs' => $pairs];
    }

    /** @param list<int> $list @param array<int,array{rounding:bool}> $info */
    private static function singleNonRounding(array $list, array $info): bool
    {
        $big = 0;
        foreach ($list as $idx) {
            if (!$info[$idx]['rounding']) {
                $big++;
            }
        }
        return $big === 1;
    }

    /**
     * Velký (nehaléřový) řádek první, haléřové za ním v pořadí vstupu.
     *
     * @param list<int> $list
     * @param array<int,array{rounding:bool}> $info
     * @return list<int>
     */
    private static function bigFirst(array $list, array $info): array
    {
        $first = [];
        $rest = [];
        foreach ($list as $idx) {
            if ($info[$idx]['rounding']) {
                $rest[] = $idx;
            } else {
                $first[] = $idx;
            }
        }
        return [...$first, ...$rest];
    }

    /**
     * Postupné párování dvou stejně velkých stran (severozápadní roh).
     *
     * @param list<int> $hubSide
     * @param list<int> $counterSide
     * @param array<int,array{cents:int}> $info
     * @return list<array{int,int,int}> [hub index, counter index, haléře]
     */
    private static function northWestCorner(array $hubSide, array $counterSide, array $info): array
    {
        $cells = [];
        $i = 0;
        $j = 0;
        $hubLeft = $info[$hubSide[0]]['cents'];
        $counterLeft = $info[$counterSide[0]]['cents'];
        while ($i < count($hubSide) && $j < count($counterSide)) {
            $take = min($hubLeft, $counterLeft);
            $cells[] = [$hubSide[$i], $counterSide[$j], $take];
            $hubLeft -= $take;
            $counterLeft -= $take;
            if ($hubLeft === 0 && ++$i < count($hubSide)) {
                $hubLeft = $info[$hubSide[$i]]['cents'];
            }
            if ($counterLeft === 0 && ++$j < count($counterSide)) {
                $counterLeft = $info[$counterSide[$j]]['cents'];
            }
        }
        return $cells;
    }

    /**
     * Rozdělí řádek na díly daných částek (v haléřích). Jediný díl = řádek beze změny.
     * `amount_foreign` se dělí poměrem v haléřích, zbytek jde na poslední díl.
     *
     * @param array<string,mixed> $line
     * @param list<int> $parts
     * @return list<array<string,mixed>>
     */
    private static function split(array $line, array $parts): array
    {
        if (count($parts) === 1) {
            return [$line];
        }
        $total = array_sum($parts);
        $foreign = isset($line['amount_foreign']) && $line['amount_foreign'] !== ''
            ? JournalLineAmount::cents($line['amount_foreign'])
            : null;
        $assigned = 0;
        $out = [];
        $last = count($parts) - 1;
        foreach ($parts as $k => $cents) {
            $piece = $line;
            $piece['amount'] = self::centsToDecimal($cents);
            if ($foreign !== null) {
                $share = $k === $last ? $foreign - $assigned : self::proportional($foreign, $cents, $total);
                $assigned += $share;
                $piece['amount_foreign'] = self::centsToDecimal($share);
            }
            $out[] = $piece;
        }
        return $out;
    }

    /** round(value * part / total) v celých haléřích, half away from zero, bez ztráty přesnosti floatu. */
    private static function proportional(int $value, int $part, int $total): int
    {
        $abs = (string) abs($value);
        $numerator = bcadd(bcmul(bcmul($abs, (string) $part), '2'), (string) $total);
        $result = (int) bcdiv($numerator, bcmul((string) $total, '2'), 0);
        return $value < 0 ? -$result : $result;
    }

    private static function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Text dvojice: u děleného hubu text protiřádku (je konkrétnější), jinak text řádku MD,
     * pak Dal. Prázdný text = žádný.
     *
     * @param array<string,mixed> $debit
     * @param array<string,mixed> $credit
     */
    private static function pairText(array $debit, array $credit, bool $fromCounter, bool $counterIsDebit): ?string
    {
        $candidates = $fromCounter && !$counterIsDebit ? [$credit, $debit] : [$debit, $credit];
        foreach ($candidates as $line) {
            $text = isset($line['line_text']) ? trim((string) $line['line_text']) : '';
            if ($text !== '') {
                return $text;
            }
        }
        return null;
    }
}
