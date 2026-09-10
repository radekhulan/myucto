<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Psr\Log\LoggerInterface;

/**
 * Parser PDF výpisu od KB (Komerční banka). Text extrahuje registry (Smalot\PdfParser),
 * tahle třída ho rozparsuje deterministicky (žádné AI — finanční data). Protějšek
 * {@see CreditasStatementPdfParser}/{@see CsobStatementPdfParser} pro layout KB.
 *
 * Layout KB je „vertikální" — na rozdíl od ČSOB nemá transakce na jednom řádku, ale
 * rozprostřenou do víc fyzických řádků (datum zúčtování / datum transakce / popis /
 * identifikace / název protiúčtu / protiúčet a kód banky / VS / KS / SS / částka).
 * Ne každá transakce má všechny řádky. Běžný zůstatek se u řádků NEuvádí (jen sloupce
 * Připsáno/Odepsáno = částka se znaménkem; kredit bez znaménka, debet s „-").
 *
 * Kotvy parsování transakce:
 *   - Slice začíná řádkem s celým datem „DD.MM.YYYY" (popis může být nalepený za ním).
 *   - Částka = POSLEDNÍ peněžní hodnota ve slice (sloupec Připsáno/Odepsáno je vpravo).
 *   - Protiúčet = první řádek `<číslo>/<kód banky>`; VS/KS/SS = celočíselné tokeny ZA ním.
 *
 * Self-check: součet transakcí musí sedět na `curr_balance - prev_balance` z hlavičky.
 */
final class KbStatementPdfParser implements BankStatementPdfParserInterface
{
    /** Peněžní hodnota: volitelné znaménko, tisíce oddělené mezerou/NBSP, čárka desetinná. */
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';

    /** Řádky, kterými tabulka transakcí končí (za nimi je rekapitulace / zůstatky dle data). */
    private const END_LINE_PATTERNS = [
        '/^KONEČNÝ ZŮSTATEK/u',
        '/^Rekapitulace transakcí/u',
        '/^Zůstatek podle data/u',
        '/^Vklad na tomto účtu/u',
    ];

    private const SKIP_LINE_PATTERNS = [
        '/^Pokračování na další straně/u',
        '/^Komerční banka, a\.s\./u',
        '/^se sídlem: Praha/u',
        '/^zapsaná v obchodním rejstříku/u',
        '/^DN\d/u', // technický identifikátor stránky „DN260630_…"
        // Opakovaná záhlaví sloupců (na každé stránce znovu).
        '/^Datum$/u',
        '/^zúčtování$/u',
        '/^transakce$/u',
        '/^Popis transakce$/u',
        '/^Identifikace transakce$/u',
        '/^Název protiúčtu \/ Číslo a typ karty$/u',
        '/^Protiúčet a kód banky \/ Obchodní místo$/u',
        '/^VS$/u',
        '/^KS$/u',
        '/^SS$/u',
        '/^Připsáno$/u',
        '/^Odepsáno$/u',
        // Opakovaná hlavička výpisu na dalších stránkách.
        '/^VÝPIS PERIODICKÝ/u',
        '/^k účtu:/u',
        '/^IBAN:/u',
        '/^typ:/u',
        '/^měna:/u',
        '/^Datum výpisu:/u',
        '/^Číslo výpisu:/u',
        '/^Strana:/u',
        '/^Zaslání:/u',
        '/^Frekvence:/u',
        '/^Za období:/u',
        '/^POČÁTEČNÍ ZŮSTATEK/u',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function key(): string
    {
        return 'kb';
    }

    public function supports(string $text): bool
    {
        return (str_contains($text, 'KOMBCZPP') || str_contains($text, 'Komerční banka'))
            && (str_contains($text, 'VÝPIS PERIODICKÝ') || str_contains($text, 'www.kb.cz'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);

        // Self-check: součet transakcí musí souhlasit s pohybem zůstatku na haléř přesně.
        $sum = 0.0;
        foreach ($transactions as $tx) $sum += (float) $tx['amount'];
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($sum, 2) - $expected) > 0.01) {
            throw new \RuntimeException(sprintf(
                'KB PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $sum,
                $expected,
            ));
        }

        $currency = $header['currency'] ?? 'CZK';
        foreach ($transactions as &$tx) {
            $tx['currency'] = $currency;
        }
        unset($tx);
        unset($header['currency']);

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float, currency:?string}
     */
    public function parseHeaderFromText(string $text): array
    {
        $money = '(' . self::MONEY . ')';

        if (!preg_match('/k účtu:\s*([\d\-]+)\/(\d{3,4})/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "k účtu:" v hlavičce.');
        }
        $accountNumber = $m[1];

        if (!preg_match('/Datum výpisu:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Datum výpisu:" v hlavičce.');
        }
        $statementDate = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

        $statementNumber = preg_match('/Číslo výpisu:\s*(\d+)/u', $text, $m) ? $m[1] : '';
        $currency = preg_match('/měna:\s*([A-Za-z]{3})/u', $text, $m) ? strtoupper($m[1]) : null;

        // KB píše zůstatky bez dvojtečky, oddělené mezerami: „Počáteční zůstatek   304 038,38".
        if (!preg_match('/Počáteční zůstatek\s+' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Počáteční zůstatek" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);

        if (!preg_match('/Konečný zůstatek\s+' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Konečný zůstatek" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        // „Obraty na účtu   18 412,72   -13 050,87" → Připsáno (kredit) / Odepsáno (debet).
        $creditTotal = 0.0;
        $debitTotal = 0.0;
        if (preg_match('/Obraty na účtu\s+' . $money . '\s+' . $money . '/u', $text, $m)) {
            $creditTotal = abs($this->num($m[1]));
            $debitTotal = abs($this->num($m[2]));
        }

        return [
            'account_number'   => $accountNumber,
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $currBalance,
            'debit_total'      => $debitTotal,
            'credit_total'     => $creditTotal,
            'currency'         => $currency,
        ];
    }

    /**
     * Testovací seam — vytěží transakce z prostého textu (bez PDF bytes / self-checku).
     *
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

        // Rozsekat na slice = řádky jedné transakce. Nový slice začíná řádkem s celým
        // datem „DD.MM.YYYY". Za koncovým markerem (rekapitulace / zůstatky dle data)
        // parsování končí — jinak by se „Zůstatek podle data" s daty a zůstatky rozparsoval
        // jako falešné transakce. Opakovaná záhlaví/patičky (page break) přeskakujeme.
        $slices = [];
        $current = [];
        foreach ($lines as $raw) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', (string) $raw));
            if ($t === '') continue;
            if ($this->isEndLine($t)) break;
            if ($this->isSkipLine($t)) continue;

            if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}/u', $t)) {
                if ($current !== []) $slices[] = $current;
                $current = [$t];
            } elseif ($current !== []) {
                $current[] = $t;
            }
        }
        if ($current !== []) $slices[] = $current;

        $rows = [];
        foreach ($slices as $slice) {
            $row = $this->parseSlice($slice);
            if ($row !== null) $rows[] = $row;
        }
        return $rows;
    }

    private function isEndLine(string $line): bool
    {
        foreach (self::END_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    /**
     * @param list<string> $slice
     */
    private function parseSlice(array $slice): ?array
    {
        // 1) Datum zúčtování + volitelně popis nalepený za ním („05.06.2026OKAMŽITÁ …").
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(.*)$/u', $slice[0], $m)) {
            return null;
        }
        $day = (int) $m[1];
        $mon = (int) $m[2];
        $year = (int) $m[3];
        if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) return null;
        $postingDate = sprintf('%04d-%02d-%02d', $year, $mon, $day);

        $n = count($slice);
        $idx = 1;
        $type = trim($m[4]);
        if ($type === '') {
            // Vertikální layout: další řádek může být datum transakce (přeskočit), pak popis.
            if ($idx < $n && preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}$/u', $slice[$idx])) $idx++;
            if ($idx < $n) { $type = $slice[$idx]; $idx++; }
        }

        // 2) Částka = POSLEDNÍ peněžní hodnota ve slice (nese vlastní znaménko).
        $amount = null;
        foreach ($slice as $line) {
            if (preg_match_all('/' . self::MONEY . '/u', $line, $mm)) {
                $amount = $this->num($mm[0][count($mm[0]) - 1]);
            }
        }
        if ($amount === null) {
            return null; // řádek bez částky = informativní / falešný slice
        }

        // 3) Protiúčet + VS/KS/SS. Kotva = první řádek `<číslo>/<kód banky>`; symboly jsou
        //    celočíselné tokeny ZA ním (na jeho konci nebo na dalších řádcích), název
        //    protistrany = řádek těsně PŘED protiúčtem (má-li písmeno a není to štítek).
        $account = null;
        $bankCode = null;
        $accountIdx = null;
        for ($i = $idx; $i < $n; $i++) {
            if (preg_match('/^([\d][\d\-]{3,})\/(\d{3,4})(.*)$/u', $slice[$i], $acm)) {
                $account = $acm[1];
                $bankCode = $acm[2];
                $accountIdx = $i;
                break;
            }
        }

        $vs = null; $ks = null; $ss = null;
        $counterpartyName = null;
        if ($accountIdx !== null) {
            // Symboly: celočíselné tokeny za protiúčtem (částku z řádku napřed odstraníme).
            $symbolLines = [(string) preg_replace('/^[\d][\d\-]{3,}\/\d{3,4}/u', '', $slice[$accountIdx])];
            for ($i = $accountIdx + 1; $i < $n; $i++) $symbolLines[] = $slice[$i];
            $nums = [];
            foreach ($symbolLines as $sl) {
                $sl = trim((string) preg_replace('/' . self::MONEY . '/u', '', $sl));
                foreach (preg_split('/[\t ]+/u', $sl) ?: [] as $tok) {
                    if (preg_match('/^\d+$/', $tok)) $nums[] = $tok;
                }
            }
            $vs = isset($nums[0]) ? (ltrim($nums[0], '0') ?: null) : null;
            $ks = isset($nums[1]) ? (ltrim($nums[1], '0') ?: null) : null;
            $ss = isset($nums[2]) ? (ltrim($nums[2], '0') ?: null) : null;

            // Název protistrany = poslední řádek před protiúčtem s písmenem (ne „Zpráva…").
            for ($i = $accountIdx - 1; $i >= $idx; $i--) {
                $cand = trim($slice[$i]);
                if ($cand === '' || preg_match('/^Zpráva pro příjemce:/u', $cand)) continue;
                if (preg_match('/\p{L}/u', $cand)) { $counterpartyName = $cand; break; }
            }
        }

        // Karetní platba: sloupec „Číslo a typ karty" nese maskované číslo, řádek pod ním
        // („Obchodní místo") obchodníka. Protiúčet karta nemá, takže jméno protistrany
        // výše nevzniklo — doplníme ho z obchodního místa.
        $cardLast4 = null;
        $cardIdx = null;
        for ($i = $idx; $i < $n; $i++) {
            $last4 = \MyInvoice\Service\Bank\Card\CardNumberMask::last4FromText($slice[$i]);
            if ($last4 !== null) {
                $cardLast4 = $last4;
                $cardIdx = $i;
                break;
            }
        }
        if ($cardIdx !== null && $counterpartyName === null) {
            for ($i = $cardIdx + 1; $i < $n; $i++) {
                $cand = trim((string) preg_replace('/' . self::MONEY . '/u', '', $slice[$i]));
                if ($cand !== '' && preg_match('/\p{L}/u', $cand)) {
                    $counterpartyName = $cand;
                    break;
                }
            }
        }

        // Popis: typ + zbývající poznámkové řádky (bez protiúčtu, symbolů a částky).
        $descParts = [];
        if ($type !== '') $descParts[] = $type;
        for ($i = $idx; $i < $n; $i++) {
            if ($i === $accountIdx) continue;
            $line = trim((string) preg_replace('/' . self::MONEY . '/u', '', $slice[$i]));
            if ($line === '') continue;
            if ($line === $counterpartyName) continue;
            if (preg_match('/^\d[\d\-]*$/', $line)) continue; // čisté číselné tokeny (symboly/identifikace)
            if (preg_match('/^Zpráva pro příjemce:$/u', $line)) continue;
            $descParts[] = $line;
        }
        $description = $descParts !== [] ? mb_substr(implode(' | ', $descParts), 0, 255) : null;

        $row = [
            'posted_at'            => $postingDate,
            'amount'               => round($amount, 2),
            'variable_symbol'      => $vs,
            'constant_symbol'      => $ks,
            'specific_symbol'      => $ss,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bankCode,
            'counterparty_name'    => $counterpartyName !== null ? mb_substr($counterpartyName, 0, 190) : null,
            'description'          => $description,
            'bank_ref'             => null,
        ];
        if ($cardLast4 !== null) {
            $row['card_last4'] = $cardLast4;
        }
        return $row;
    }

    /** „1 234,56" / „-9 999,00" → float (mezery/NBSP oddělovač tisíců, čárka desetinná). */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }
}
