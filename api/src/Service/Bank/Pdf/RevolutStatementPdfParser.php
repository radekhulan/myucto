<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Card\CardNumberMask;
use MyInvoice\Service\Payment\IbanValidator;

/**
 * Parser PDF výpisu z účtu Revolut Business (Revolut Bank UAB, BIC REVOLT21), česká
 * i anglická lokalizace.
 *
 * Hlavička: „Výpis z účtu" / „Account statement", „Měna" / „Currency", „IBAN",
 * „Přehled zůstatků" se čtyřmi částkami (počáteční, příchozí, odchozí, koncový zůstatek)
 * a „Transakce od <datum> do <datum>" / „Transactions from … to …". Částky mají
 * desetinnou TEČKU, tisíce mezerou (anglická lokalizace i čárkou) a kód měny za
 * částkou. Výpis je vždy za jeden měnový účet; částky pohybů jsou v jeho měně.
 *
 * Tabulka „Datum (UTC) | Popis | Odchozí | Příchozí | Zůstatek" má na pohyb řádek
 * s datem, kódem typu (CAR, MOS, MOR, MOA, ATM, EXO, EXI, FEE), částkou a zůstatkem
 * po pohybu; popis pokračuje na dalších řádcích (reference, původní částka a kurz
 * u platby kartou v cizí měně). Textová vrstva nezachová, ve kterém sloupci částka
 * stojí, a tiskne ji bez znaménka. Směr pohybu se proto odvodí ze zůstatku: rozdíl
 * proti zůstatku předchozího pohybu musí dát právě částku pohybu. Pohyby se čtou
 * z pozic textu na stránce, sloupce určí záhlaví tabulky na každé straně.
 *
 * Variabilní symbol Revolut nemá; reference zůstává v popisu.
 *
 * Self-check: řetěz zůstatků od počátečního ke koncovému, součty příchozích
 * a odchozích pohybů proti „Přehledu zůstatků" a počáteční + pohyby = koncový
 * zůstatek. Nesoulad zamítne celé parsování.
 */
final class RevolutStatementPdfParser implements BankStatementPdfParserInterface
{
    private const MONEY = '[-−]? ?(?:[A-Z]{3} ?|[€£$] ?)?[-−]?\d{1,3}(?:[ ,]\d{3})*\.\d{2}(?: ?[A-Z]{3})?';
    private const DATE = '/^(\d{1,2})\.?\s+(\p{L}{3,})\.?,?\s+(\d{4})(?:\s+([A-Z]{3}))?$/u';
    private const TYPE = '/^[A-Z]{3}$/';

    /** Tolerance svislé pozice (pt), ve které leží buňky téhož řádku. */
    private const SAME_ROW = 1.5;
    /** Tolerance vodorovné pozice (pt) vůči levému okraji sloupce ze záhlaví. */
    private const COLUMN_SLACK = 5.0;

    /** Popisky sloupců tabulky pohybů: CZ a EN lokalizace. */
    private const COLUMNS = [
        'date'        => ['Datum', 'Datum (UTC)', 'Date', 'Date (UTC)'],
        'description' => ['Popis', 'Description'],
        'out'         => ['Odchozí', 'Money out'],
        'in'          => ['Příchozí', 'Money in'],
        'balance'     => ['Zůstatek', 'Balance'],
    ];

    /** Texty pod tabulkou pohybů: souhrn podle typů a patička stránky. */
    private const TABLE_END = [
        'Typy transakcí',
        'Transaction types',
        'Nahlásit ztrátu',
        'Report lost',
        'Získejte pomoc',
        'Get help',
        'Revolut Bank UAB je',
        'Revolut Bank UAB is',
        '©',
    ];

    private const MONTHS = [
        'led' => 1, 'úno' => 2, 'bře' => 3, 'dub' => 4, 'kvě' => 5, 'čvn' => 6,
        'čvc' => 7, 'srp' => 8, 'zář' => 9, 'říj' => 10, 'lis' => 11, 'pro' => 12,
        'leden' => 1, 'ledna' => 1, 'únor' => 2, 'února' => 2, 'březen' => 3, 'března' => 3,
        'duben' => 4, 'dubna' => 4, 'květen' => 5, 'května' => 5, 'červen' => 6, 'června' => 6,
        'červenec' => 7, 'července' => 7, 'srpen' => 8, 'srpna' => 8, 'září' => 9,
        'říjen' => 10, 'října' => 10, 'listopad' => 11, 'listopadu' => 11,
        'prosinec' => 12, 'prosince' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    public function key(): string
    {
        return 'revolut';
    }

    public function supports(string $text): bool
    {
        $text = $this->normalize($text);
        return (str_contains($text, 'Revolut Bank UAB') || str_contains($text, 'REVOLT21'))
            && (str_contains($text, 'Výpis z účtu') || str_contains($text, 'Account statement'))
            && preg_match('/(?:Datum|Date)\s*\(UTC\)/u', $text) === 1;
    }

    public function parse(string $pdfBytes, string $text): array
    {
        return $this->parseChunks(PdfPositionedText::fromBytes($pdfBytes), $text);
    }

    /**
     * @param list<array{page:int,x:float,y:float,text:string}> $chunks Text stránek s pozicí (PDF body, y roste nahoru).
     * @return array{header:array<string,mixed>,transactions:list<array<string,mixed>>}
     */
    public function parseChunks(array $chunks, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $rows = $this->parseTransactionsFromChunks($chunks);

        $prevCents = $this->toCents($header['prev_balance']);
        $currCents = $this->toCents($header['curr_balance']);
        $signed = $this->signedByBalance($rows, $prevCents);
        if ($signed === null) {
            throw new \RuntimeException('Revolut PDF: zůstatky u pohybů nenavazují na počáteční zůstatek. Parsování zamítnuto.');
        }

        $creditCents = 0;
        $debitCents = 0;
        foreach ($signed as $cents) {
            if ($cents > 0) $creditCents += $cents;
            else $debitCents -= $cents;
        }
        if ($creditCents !== $this->toCents($header['credit_total']) || $debitCents !== $this->toCents($header['debit_total'])) {
            throw new \RuntimeException(sprintf(
                'Revolut PDF: součet pohybů (příchozí %.2f, odchozí %.2f) nesedí na přehled zůstatků (%.2f, %.2f). Parsování zamítnuto.',
                $creditCents / 100,
                $debitCents / 100,
                $header['credit_total'],
                $header['debit_total'],
            ));
        }
        if ($prevCents + $creditCents - $debitCents !== $currCents) {
            throw new \RuntimeException('Revolut PDF: počáteční zůstatek a pohyby nedávají koncový zůstatek. Parsování zamítnuto.');
        }

        $currency = (string) $header['currency'];
        $transactions = [];
        foreach ($rows as $index => $row) {
            unset($row['amount_cents'], $row['balance_cents']);
            $row['amount'] = $signed[$index] / 100.0;
            $row['currency'] = $currency;
            $transactions[] = $row;
        }

        unset($header['currency']);
        $header['account_currency'] = $currency;

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float,
     *   currency:string, period_kind:string, period_from:string}
     */
    public function parseHeaderFromText(string $text): array
    {
        $text = $this->normalize($text);

        $iban = $this->iban($text);
        if ($iban === null) {
            throw new \RuntimeException('Revolut PDF: chybí platný IBAN účtu v hlavičce.');
        }
        if (!preg_match('/(?:^|\n)[ \t]*(?:Měna|Currency)[ \t]+([A-Z]{3})\b/u', $text, $c)) {
            throw new \RuntimeException('Revolut PDF: chybí měna účtu.');
        }
        if (!preg_match(
            '/(?:Transakce|Transactions)\s*(?:od|from)\s*(\d{1,2})\.?\s*(\p{L}+)\.?,?\s*(\d{4})\s*(?:do|to)\s*(\d{1,2})\.?\s*(\p{L}+)\.?,?\s*(\d{4})/u',
            $text,
            $p,
        )) {
            throw new \RuntimeException('Revolut PDF: chybí období výpisu.');
        }
        $from = $this->date($p[1], $p[2], $p[3]);
        $to = $this->date($p[4], $p[5], $p[6]);
        if ($from === null || $to === null) {
            throw new \RuntimeException('Revolut PDF: neplatné období výpisu.');
        }

        return [
            'account_number'   => $iban,
            'statement_date'   => $to,
            'statement_number' => '',
            'prev_balance'     => $this->amountAfter($text, ['Počáteční zůstatek', 'Opening balance']),
            'curr_balance'     => $this->amountAfter($text, ['Koncový zůstatek', 'Closing balance']),
            'debit_total'      => abs($this->amountAfter($text, ['Odchozí', 'Money out'])),
            'credit_total'     => abs($this->amountAfter($text, ['Příchozí', 'Money in'])),
            'currency'         => $c[1],
            'period_kind'      => 'period',
            'period_from'      => $from,
        ];
    }

    /**
     * Pohyby s částkou BEZ znaménka (`amount_cents`) a zůstatkem po pohybu
     * (`balance_cents`); směr určí {@see parseChunks()} z řetězu zůstatků.
     *
     * @param list<array{page:int,x:float,y:float,text:string}> $chunks
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromChunks(array $chunks): array
    {
        $pages = [];
        foreach ($chunks as $chunk) {
            $raw = str_replace("\u{00A0}", ' ', (string) $chunk['text']);
            $text = trim((string) preg_replace('/\s{2,}/u', ' ', $raw));
            if ($text === '') continue;
            $pages[$chunk['page']][] = [
                'x' => (float) $chunk['x'],
                'y' => (float) $chunk['y'],
                'text' => $text,
                'wraps' => preg_match('/\s$/u', $raw) === 1,
            ];
        }
        ksort($pages);

        $blocks = [];
        foreach ($pages as $page) {
            $columns = $this->columns($page);
            if ($columns === null) continue;
            $cells = $this->tableCells($page, $columns);
            usort($cells, static fn (array $a, array $b): int => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

            $starts = [];
            foreach ($cells as $cell) {
                if ($cell['x'] < $columns['description'] - self::COLUMN_SLACK && preg_match(self::DATE, $cell['text'])) {
                    $starts[] = $cell['y'];
                }
            }
            $starts = array_values(array_unique($starts, SORT_REGULAR));

            $pageBlocks = array_fill_keys(array_keys($starts), []);
            foreach ($cells as $cell) {
                $owner = null;
                foreach ($starts as $index => $y) {
                    if ($y >= $cell['y'] - self::SAME_ROW) $owner = $index;
                }
                if ($owner !== null) {
                    $pageBlocks[$owner][] = $cell;
                } elseif ($blocks !== []) {
                    // Popis zalomený na další stranu patří poslednímu pohybu.
                    $blocks[array_key_last($blocks)]['cells'][] = $cell + ['continued' => true];
                }
            }
            foreach ($starts as $index => $y) {
                $blocks[] = ['y' => $y, 'columns' => $columns, 'cells' => $pageBlocks[$index]];
            }
        }

        return array_map(fn (array $block): array => $this->row($block), $blocks);
    }

    /**
     * Znaménkové částky (haléře) podle řetězu zůstatků. Revolut řadí pohyby od
     * nejstaršího; opačné pořadí se zkusí, když řetěz nenavazuje. Null, když nesedí ani jedno.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,int>|null
     */
    private function signedByBalance(array $rows, int $openingCents): ?array
    {
        foreach ([array_keys($rows), array_reverse(array_keys($rows))] as $order) {
            $balance = $openingCents;
            $signed = [];
            foreach ($order as $index) {
                $delta = $rows[$index]['balance_cents'] - $balance;
                if (abs($delta) !== $rows[$index]['amount_cents']) {
                    $signed = null;
                    break;
                }
                $signed[$index] = $delta;
                $balance = $rows[$index]['balance_cents'];
            }
            if ($signed !== null) {
                ksort($signed);
                return $signed;
            }
        }
        return null;
    }

    /** @param array{y:float,columns:array<string,float>,cells:list<array<string,mixed>>} $block */
    private function row(array $block): array
    {
        $columns = $block['columns'];
        $startY = $block['y'];
        $amountLeft = ($columns['description'] + $columns['out']) / 2;
        $postedAt = null;
        $type = null;
        $amounts = [];
        $lines = [];

        foreach ($block['cells'] as $cell) {
            $text = $cell['text'];
            $sameRow = empty($cell['continued']) && abs($cell['y'] - $startY) <= self::SAME_ROW;
            if ($cell['x'] < $columns['description'] - self::COLUMN_SLACK) {
                if ($sameRow && $postedAt === null && preg_match(self::DATE, $text, $d)) {
                    $postedAt = $this->date($d[1], $d[2], $d[3]);
                    $type = ($d[4] ?? '') !== '' ? $d[4] : $type;
                } elseif ($sameRow && $type === null && preg_match(self::TYPE, $text)) {
                    $type = $text;
                }
                continue;
            }
            if ($cell['x'] >= $amountLeft && preg_match('/^' . self::MONEY . '$/u', $text)) {
                if ($sameRow) $amounts[] = ['x' => $cell['x'], 'cents' => $this->cents($text)];
                continue;
            }
            $lines[] = $cell;
        }
        if ($postedAt === null) {
            throw new \RuntimeException('Revolut PDF: pohyb s neplatným datem. Parsování zamítnuto.');
        }
        if (count($amounts) !== 2) {
            throw new \RuntimeException(sprintf('Revolut PDF: pohyb ze dne %s nemá částku a zůstatek. Parsování zamítnuto.', $postedAt));
        }
        usort($amounts, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);

        $description = '';
        foreach ($lines as $i => $line) {
            if ($i > 0) $description .= $lines[$i - 1]['wraps'] ? ' ' : ' | ';
            $description .= $line['text'];
        }
        $full = implode(' | ', array_filter([$type, $description], static fn (?string $p): bool => $p !== null && $p !== ''));

        return [
            'posted_at'            => $postedAt,
            'amount_cents'         => abs($amounts[0]['cents']),
            'balance_cents'        => $amounts[1]['cents'],
            'variable_symbol'      => null,
            'constant_symbol'      => null,
            'specific_symbol'      => null,
            'counterparty_account' => $this->counterpartyIban($description),
            'counterparty_bank'    => null,
            'counterparty_name'    => null,
            'card_last4'           => CardNumberMask::last4FromText($description),
            'description'          => $full !== '' ? mb_substr($full, 0, 255) : null,
            'bank_ref'             => null,
        ];
    }

    /**
     * Levé okraje sloupců ze záhlaví tabulky; null = stránka bez tabulky pohybů.
     * Popisky „Příchozí"/„Odchozí" má i přehled zůstatků, proto se berou jen ty
     * na řádku záhlaví s datem.
     *
     * @param list<array{x:float,y:float,text:string}> $page
     * @return array{date:float,description:float,out:float,in:float,balance:float,top:float}|null
     */
    private function columns(array $page): ?array
    {
        foreach ($page as $date) {
            if (!in_array($date['text'], self::COLUMNS['date'], true)) continue;
            $found = ['date' => $date['x']];
            foreach (['description', 'out', 'in', 'balance'] as $key) {
                foreach ($page as $chunk) {
                    if (abs($chunk['y'] - $date['y']) <= self::SAME_ROW && in_array($chunk['text'], self::COLUMNS[$key], true)) {
                        $found[$key] = $chunk['x'];
                        break;
                    }
                }
            }
            if (count($found) === 5 && $found['date'] < $found['description'] && $found['description'] < $found['out']) {
                return $found + ['top' => $date['y']];
            }
        }
        return null;
    }

    /**
     * Buňky pod záhlavím tabulky a nad jejím koncem (souhrn typů nebo patička stránky).
     *
     * @param list<array{x:float,y:float,text:string}> $page
     * @param array<string,float> $columns
     * @return list<array<string,mixed>>
     */
    private function tableCells(array $page, array $columns): array
    {
        $top = $columns['top'] - self::SAME_ROW;
        $bottom = null;
        foreach ($page as $chunk) {
            if ($chunk['y'] >= $top) continue;
            foreach (self::TABLE_END as $marker) {
                if (str_starts_with($chunk['text'], $marker)) {
                    $bottom = max($bottom ?? $chunk['y'], $chunk['y']);
                }
            }
        }

        return array_values(array_filter(
            $page,
            static fn (array $c): bool => $c['y'] < $top && ($bottom === null || $c['y'] > $bottom + 0.5),
        ));
    }

    /** IBAN účtu z hlavičky: nejdelší úsek za popiskem, který projde kontrolním součtem. */
    private function iban(string $text): ?string
    {
        if (!preg_match('/(?:^|\n)[ \t]*IBAN[: \t]+([A-Z]{2}\d{2}[A-Z0-9 ]*)/u', $text, $m)) {
            return null;
        }
        return $this->longestValidIban($m[1]);
    }

    /** IBAN protistrany z popisu pohybu (např. u odchozí nebo příchozí platby), jinak null. */
    private function counterpartyIban(string $description): ?string
    {
        if (!preg_match_all('/(?<![A-Z0-9])[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,3})?/u', $description, $m)) {
            return null;
        }
        foreach ($m[0] as $candidate) {
            $iban = $this->longestValidIban($candidate);
            if ($iban !== null) return $iban;
        }
        return null;
    }

    private function longestValidIban(string $raw): ?string
    {
        $validator = new IbanValidator();
        $tokens = preg_split('/\s+/', trim($raw)) ?: [];
        $best = null;
        $compact = '';
        foreach ($tokens as $token) {
            $compact .= $token;
            if (strlen($compact) > 34) break;
            if ($validator->isValid($compact)) $best = $compact;
        }
        return $best;
    }

    /** @param list<string> $labels */
    private function amountAfter(string $text, array $labels): float
    {
        $alternatives = implode('|', array_map(static fn (string $l): string => preg_quote($l, '/'), $labels));
        if (!preg_match('/(?:' . $alternatives . ')[ \t]*(' . self::MONEY . ')/u', $text, $m)) {
            throw new \RuntimeException('Revolut PDF: chybí „' . $labels[0] . '" v přehledu zůstatků.');
        }
        return $this->cents($m[1]) / 100.0;
    }

    /** „6 422.64 CZK" / „- 250.00 CZK" / „€1,250.00" → haléře se znaménkem. */
    private function cents(string $value): int
    {
        $negative = preg_match('/[-−]/u', $value) === 1;
        $digits = (string) preg_replace('/[^\d.]/', '', $value);
        if (!preg_match('/^(\d+)\.(\d{2})$/', $digits, $m)) {
            throw new \RuntimeException('Revolut PDF: neplatná částka.');
        }
        $cents = (int) $m[1] * 100 + (int) $m[2];
        return $negative ? -$cents : $cents;
    }

    private function toCents(float $value): int
    {
        return (int) round($value * 100);
    }

    private function date(string $day, string $month, string $year): ?string
    {
        $m = self::MONTHS[mb_strtolower(rtrim($month, '.'))] ?? null;
        if ($m === null || !checkdate($m, (int) $day, (int) $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', (int) $year, $m, (int) $day);
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    }
}
