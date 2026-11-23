<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

/** Jediný PHP výklad částky řádku deníku včetně červeného storna. */
final class JournalLineAmount
{
    public static function decimal(float|int|string $amount): string
    {
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \InvalidArgumentException('Částka musí být konečné číslo.');
            }
            return number_format($amount, 2, '.', '');
        }
        $value = trim((string) $amount);
        if (!preg_match('/^([+-]?)(\d+(?:\.\d*)?|\.\d+)(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
            throw new \InvalidArgumentException('Částka musí být číslo.');
        }
        if (isset($parts[3])) {
            $mantissa = $parts[2];
            $point = strpos($mantissa, '.');
            $point = $point === false ? strlen($mantissa) : $point;
            $digits = str_replace('.', '', $mantissa);
            $leading = strlen($digits) - strlen(ltrim($digits, '0'));
            $digits = substr($digits, $leading);
            if ($digits === '') {
                return '0.00';
            }
            $position = $point - $leading + (int) $parts[3];
            if ($position < -2) {
                return '0.00';
            }
            if ($position > 309) {
                throw new \InvalidArgumentException('Částka je mimo číselný rozsah.');
            }
            $value = $parts[1] . ($position <= 0
                ? '0.' . str_repeat('0', -$position) . $digits
                : substr(str_pad($digits, $position, '0'), 0, $position) . '.' . substr($digits, $position));
        }
        return bcround($value, 2);
    }

    public static function cents(float|int|string $amount): int
    {
        $cents = bcmul(self::decimal($amount), '100', 0);
        if (bccomp($cents, (string) PHP_INT_MAX, 0) > 0 || bccomp($cents, (string) PHP_INT_MIN, 0) < 0) {
            throw new \InvalidArgumentException('Částka je mimo rozsah haléřů.');
        }
        return (int) $cents;
    }

    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signed(array $line): float
    {
        $amount = (float) $line['amount'];
        return !empty($line['is_red_storno']) ? -$amount : $amount;
    }

    /** @param array{amount:float|int|string,is_red_storno?:bool|int} $line */
    public static function signedCents(array $line): int
    {
        $cents = self::cents($line['amount']);
        return !empty($line['is_red_storno']) ? -$cents : $cents;
    }

    /**
     * Protizápis běžného řádku obrátí stranu. Červený řádek už je záporný,
     * proto jej vyruší kladný řádek na stejné straně.
     *
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public static function reversal(array $line): array
    {
        if (empty($line['is_red_storno'])) {
            $line['side'] = $line['side'] === 'debit' ? 'credit' : 'debit';
        }
        $line['is_red_storno'] = false;
        return $line;
    }

    /**
     * Zrcadlo řádků pro storno celého zápisu i jen vybraných dvojic (jediné místo,
     * kde se protizápis řádků skládá). Dimenze, rozpady a jejich původ se předávají
     * podle id původního řádku: storno musí odečíst tam, kam původní řádek přičetl.
     * Zakázka se přenáší ze stejného důvodu. Číslo a text dvojice se přenesou jen
     * u řádku, který je nese (složený zápis dostane tentýž tvar jako dosud).
     *
     * @param list<array<string,mixed>> $lines řádky originálu (s `id`)
     * @param array<int,array<mixed>> $dimensions id řádku => dimenze
     * @param array<int,array<mixed>> $splits id řádku => rozpady
     * @param array<int,array<mixed>> $origins id řádku => původ dimenzí
     * @param array<int,array<mixed>> $splitOrigins id řádku => původ rozpadů
     * @return list<array<string,mixed>>
     */
    public static function mirrorLines(
        array $lines,
        array $dimensions = [],
        array $splits = [],
        array $origins = [],
        array $splitOrigins = [],
    ): array {
        $mirror = [];
        foreach ($lines as $line) {
            $id = (int) ($line['id'] ?? 0);
            $reversalLine = self::reversal($line);
            $row = [
                'account_id'     => (int) $line['account_id'],
                'side'           => $reversalLine['side'],
                'amount'         => $line['amount'],
                'is_red_storno'  => $reversalLine['is_red_storno'],
                'currency_code'  => $line['currency_code'] ?? null,
                'fx_rate'        => $line['fx_rate'] ?? null,
                'amount_foreign' => $line['amount_foreign'] ?? null,
                'cost_center'    => $line['cost_center'] ?? null,
                'project_id'     => isset($line['project_id']) ? (int) $line['project_id'] : null,
                'dimensions'     => $dimensions[$id] ?? [],
                'dimension_splits' => $splits[$id] ?? [],
                'dimension_origins' => $origins[$id] ?? [],
                'dimension_split_origins' => $splitOrigins[$id] ?? [],
            ];
            if (isset($line['pair_no'])) {
                $row['pair_no'] = (int) $line['pair_no'];
            }
            if (isset($line['line_text']) && $line['line_text'] !== '') {
                $row['line_text'] = (string) $line['line_text'];
            }
            $mirror[] = $row;
        }
        return $mirror;
    }
}
