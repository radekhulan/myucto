<?php

declare(strict_types=1);

namespace MyInvoice\Service\Ai;

/**
 * Serverová kontrola návrhů ostatních pohledávek a závazků od AI (Účetnictví → Ostatní
 * pohledávky a závazky). Návrh nic nezakládá; koncept založí až uživatel běžným
 * `POST /api/accounting/other-items`. Hodnoty mimo povolené číselníky se opraví nebo
 * položka vypadne, splátky se zkontrolují stejně jako v OtherItemScheduleService
 * (vzestupné termíny od vzniku, součet na haléř = částka).
 */
final class AiOtherItemNormalizer
{
    public const MAX_ITEMS = 20;
    public const MAX_INSTALLMENTS = 120;
    private const KINDS = ['other', 'rent', 'loan', 'deposit', 'insurance', 'fee', 'claim'];

    /**
     * @param mixed                                    $raw        pole položek tak, jak je vrátil model
     * @param array<string,string>                     $chartNames kód účtu => název
     * @param array<string,array{id:int,name:string}> $partners   IČO => klient firmy
     * @param list<string>                             $hiddenKinds skryté vestavěné druhy `strana:kód` — návrh
     *                                                              by šel uložit jen jako jiná pohledávka / jiný závazek
     * @return list<array<string,mixed>>
     */
    public static function normalize(mixed $raw, array $chartNames, string $today, array $partners, array $hiddenKinds = []): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $side = in_array($item['side'] ?? null, ['receivable', 'payable'], true) ? (string) $item['side'] : null;
            $amount = is_numeric($item['amount'] ?? null) ? round((float) $item['amount'], 2) : 0.0;
            $title = trim((string) ($item['title'] ?? ''));
            if ($side === null || $amount <= 0 || $amount > 999999999999.99 || $title === '') {
                continue;
            }
            $cents = (int) round($amount * 100);
            $kind = in_array($item['kind'] ?? null, self::KINDS, true) ? (string) $item['kind'] : 'other';
            if (in_array($side . ':' . $kind, $hiddenKinds, true)) {
                $kind = 'other';
            }
            $issued = self::date($item['issued_on'] ?? null, $today);
            $due = self::date($item['due_on'] ?? null, $issued);
            if ($due < $issued) {
                $due = $issued;
            }
            $ico = preg_replace('/\D+/', '', (string) ($item['partner_ico'] ?? '')) ?? '';
            $partner = $ico !== '' ? ($partners[$ico] ?? null) : null;
            $partnerName = trim((string) ($item['partner_name'] ?? ''));
            $vs = preg_replace('/\D+/', '', (string) ($item['variable_symbol'] ?? '')) ?? '';
            $unknown = [];
            $account = self::account($item['account_code'] ?? null, $chartNames, $unknown);
            $counter = self::account($item['counter_account_code'] ?? null, $chartNames, $unknown);

            $installments = [];
            $sum = 0;
            $previous = '';
            $valid = true;
            foreach (array_slice(array_values(is_array($item['installments'] ?? null) ? $item['installments'] : []), 0, self::MAX_INSTALLMENTS) as $row) {
                $date = is_array($row) ? self::date($row['due_on'] ?? null, '') : '';
                $part = is_array($row) && is_numeric($row['amount'] ?? null) ? round((float) $row['amount'], 2) : 0.0;
                if ($date === '' || $part <= 0) {
                    $valid = false;
                    continue;
                }
                if ($date <= $previous || $date < $issued) {
                    $valid = false;
                }
                $previous = $date;
                $sum += (int) round($part * 100);
                $installments[] = ['due_on' => $date, 'amount' => $part];
            }
            if (count($installments) === 1) {
                $installments = [];
            }
            if ($installments !== [] && $sum !== $cents) {
                $valid = false;
            }

            $note = trim((string) ($item['note'] ?? ''));
            $out[] = [
                'side' => $side,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 255),
                'partner_id' => $partner['id'] ?? null,
                'partner_name' => $partner['name'] ?? ($partnerName !== '' ? mb_substr($partnerName, 0, 190) : null),
                'partner_ico' => $ico !== '' ? $ico : null,
                'amount' => (float) ($cents / 100),
                'currency' => 'CZK',
                'issued_on' => $issued,
                'due_on' => $due,
                'variable_symbol' => $vs !== '' ? substr($vs, 0, 20) : null,
                'account_code' => $account,
                'account_name' => $account !== null ? ($chartNames[$account] ?? null) : null,
                'counter_account_code' => $counter,
                'counter_account_name' => $counter !== null ? ($chartNames[$counter] ?? null) : null,
                'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
                'installments' => $installments,
                'installments_valid' => $installments === [] || $valid,
                'unknown_accounts' => $unknown,
            ];
        }
        return $out;
    }

    /**
     * @param array<string,string> $chartNames
     * @param list<string>         $unknown
     */
    private static function account(mixed $value, array $chartNames, array &$unknown): ?string
    {
        $code = preg_replace('/\s+/u', '', (string) ($value ?? '')) ?? '';
        if ($code === '' || mb_strlen($code) > 20) {
            return null;
        }
        if ($chartNames !== [] && !array_key_exists($code, $chartNames) && !in_array($code, $unknown, true)) {
            $unknown[] = $code;
        }
        return $code;
    }

    private static function date(mixed $value, string $fallback): string
    {
        $v = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $v;
        }
        return $fallback;
    }
}
