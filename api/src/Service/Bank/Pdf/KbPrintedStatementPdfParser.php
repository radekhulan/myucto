<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Card\CardNumberMask;

/**
 * Parser PDF výpisu KB vytištěného z internetového bankovnictví („Vytištěno dne
 * DD/MM/RRRR pro účet …" v patičce). Nadpis „VÝPIS PERIODICKÝ" je tu rozdělený do
 * dvou řádků a hlavička i sloupce se liší od výpisu, který čte {@see KbStatementPdfParser}:
 *
 *   k účtu: 115-…   číslo výpisu: N   za období: <měsíc v 2. pádě> RRRR
 *   Počáteční zůstatek: …   Konečný zůstatek: …   Připsáno: …   Odepsáno …   Počet transakcí: N
 *
 * Sloupce: Datum splatnosti / Datum zúčtování / Datum odepsání v JB / Popis platby /
 * Název protiúčtu / Protiúčet a kód banky / Identifikace transakce / VS / KS / SS /
 * Připsáno / Odepsáno. Text jednoho pohybu tedy vypadá takto:
 *
 *   <datum splatnosti>
 *   <datum zúčtování>
 *   [<datum odepsání v JB>]          (jen u platby z jiné banky)
 *   <popis platby>
 *   [<zpráva, název protiúčtu, údaje karty…>]
 *   [<protiúčet>/<banka>]
 *   <identifikace transakce>         „NNN-DDMMRRRR …"
 *   <VS>
 *   <KS>
 *   <SS>
 *   <částka se znaménkem>
 *
 * Do výpisu pohyb patří datem zúčtování (druhé datum). Výpis nese počet transakcí,
 * obraty i zůstatky; všechno se ověřuje a při neshodě se parsování zamítne.
 */
final class KbPrintedStatementPdfParser implements BankStatementPdfParserInterface
{
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';
    private const DATE_LINE = '/^(\d{2})\.(\d{2})\.(\d{4})$/';
    private const REFERENCE_LINE = '/^\d{3}-\d{8}\s+\S/';

    private const MONTHS = [
        'ledna' => 1, 'února' => 2, 'března' => 3, 'dubna' => 4, 'května' => 5, 'června' => 6,
        'července' => 7, 'srpna' => 8, 'září' => 9, 'října' => 10, 'listopadu' => 11, 'prosince' => 12,
    ];

    public function key(): string
    {
        return 'kb_printed';
    }

    public function supports(string $text): bool
    {
        $text = $this->normalize($text);
        return str_contains($text, 'Komerční banka')
            && str_contains($text, 'Datum odepsání v JB')
            && preg_match('/^k účtu:\s*[\d\-]+/mu', $text) === 1
            && preg_match('/Vytištěno dne \d{2}\/\d{2}\/\d{4} pro účet/u', $text) === 1;
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);

        if ($header['transaction_count'] !== null && $header['transaction_count'] !== count($transactions)) {
            throw new \RuntimeException(sprintf(
                'KB PDF: výpis uvádí %d transakcí, přečteno %d. Parsování zamítnuto.',
                $header['transaction_count'],
                count($transactions),
            ));
        }

        $credit = 0.0;
        $debit = 0.0;
        foreach ($transactions as $tx) {
            if ($tx['amount'] > 0) {
                $credit += $tx['amount'];
            } else {
                $debit -= $tx['amount'];
            }
        }
        $credit = round($credit, 2);
        $debit = round($debit, 2);
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($credit - $debit, 2) - $expected) > 0.001) {
            throw new \RuntimeException(sprintf(
                'KB PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $credit - $debit,
                $expected,
            ));
        }
        if ($header['credit_total'] !== null && abs($credit - $header['credit_total']) > 0.001
            || $header['debit_total'] !== null && abs($debit - $header['debit_total']) > 0.001) {
            throw new \RuntimeException('KB PDF: obraty transakcí nesedí na obraty v hlavičce. Parsování zamítnuto.');
        }

        if ($header['statement_date'] === null) {
            $dates = array_column($transactions, 'posted_at');
            if ($dates === []) {
                throw new \RuntimeException('KB PDF: chybí období výpisu („za období").');
            }
            $header['statement_date'] = max($dates);
        }

        $currency = $header['currency'];
        foreach ($transactions as &$tx) {
            $tx['currency'] = $currency;
        }
        unset($tx);
        unset($header['currency'], $header['transaction_count']);
        $header['credit_total'] = $credit;
        $header['debit_total'] = $debit;
        $header['account_currency'] = $currency;

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:?string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:?float, credit_total:?float,
     *   currency:string, period_kind:string, transaction_count:?int}
     */
    public function parseHeaderFromText(string $text): array
    {
        $text = $this->normalize($text);
        $money = '(' . self::MONEY . ')';

        if (!preg_match('/^k účtu:\s*([\d\-]+)/mu', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "k účtu:" v hlavičce.');
        }
        $accountNumber = $m[1];

        if (!preg_match('/Počáteční zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Počáteční zůstatek" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);
        if (!preg_match('/Konečný zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('KB PDF: chybí "Konečný zůstatek" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        $statementDate = null;
        if (preg_match('/za období:\s*(\p{L}+)\s+(\d{4})/u', $text, $m)) {
            $month = self::MONTHS[mb_strtolower($m[1])] ?? null;
            if ($month !== null) {
                $statementDate = (new \DateTimeImmutable(sprintf('%04d-%02d-01', (int) $m[2], $month)))->format('Y-m-t');
            }
        }

        return [
            'account_number'    => $accountNumber,
            'statement_date'    => $statementDate,
            'statement_number'  => preg_match('/číslo výpisu:\s*(\d+)/u', $text, $m) ? $m[1] : '',
            'prev_balance'      => $prevBalance,
            'curr_balance'      => $currBalance,
            'debit_total'       => preg_match('/^Odepsáno:?[ \t]+' . $money . '/mu', $text, $m) ? abs($this->num($m[1])) : null,
            'credit_total'      => preg_match('/^Připsáno:?[ \t]+' . $money . '/mu', $text, $m) ? abs($this->num($m[1])) : null,
            'currency'          => preg_match('/^měna:\s*([A-Z]{3})/mu', $text, $m) ? $m[1] : 'CZK',
            'period_kind'       => 'period',
            'transaction_count' => preg_match('/Počet transakcí:\s*(\d+)/u', $text, $m) ? (int) $m[1] : null,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function parseTransactionsFromText(string $text): array
    {
        $lines = [];
        // Hlavička výpisu a záhlaví sloupců se opakují na každé stránce: od začátku
        // stránky („Komerční banka", patička „Vytištěno dne") po poslední záhlaví sloupce.
        $inHeader = true;
        foreach (explode("\n", $this->normalize($text)) as $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            if (str_starts_with($line, 'Komerční banka, a') || str_starts_with($line, 'Vytištěno dne')) {
                $inHeader = true;
                continue;
            }
            if ($inHeader) {
                if ($line === 'Odepsáno') $inHeader = false;
                continue;
            }
            $lines[] = $line;
        }

        $rows = [];
        $n = count($lines);
        $i = 0;
        while ($i < $n) {
            $dates = [];
            while ($i < $n && preg_match(self::DATE_LINE, $lines[$i], $m)) {
                $dates[] = $this->date($m[1], $m[2], $m[3]);
                $i++;
            }
            if ($dates === []) {
                throw new \RuntimeException(sprintf('KB PDF: nečekaný řádek „%s" mimo pohyb.', $lines[$i]));
            }
            $body = [];
            while ($i < $n && !preg_match(self::REFERENCE_LINE, $lines[$i])) {
                if (preg_match(self::DATE_LINE, $lines[$i])) {
                    throw new \RuntimeException('KB PDF: pohyb bez identifikace transakce.');
                }
                $body[] = $lines[$i];
                $i++;
            }
            if ($i >= $n) {
                throw new \RuntimeException('KB PDF: pohyb bez identifikace transakce.');
            }
            $reference = $lines[$i++];
            $symbols = [];
            while (count($symbols) < 3 && $i < $n && preg_match('/^\d+$/', $lines[$i])) {
                $symbols[] = $lines[$i++];
            }
            if ($i >= $n || count($symbols) !== 3 || !preg_match('/^' . self::MONEY . '$/u', $lines[$i])) {
                throw new \RuntimeException(sprintf('KB PDF: u pohybu %s chybí symboly nebo částka.', $reference));
            }
            $amount = $this->num($lines[$i++]);
            $rows[] = $this->row($dates, $body, $reference, $symbols, $amount);
        }
        return $rows;
    }

    /**
     * @param list<string> $dates
     * @param list<string> $body
     * @param list<string> $symbols
     * @return array<string,mixed>
     */
    private function row(array $dates, array $body, string $reference, array $symbols, float $amount): array
    {
        $type = $body[0] ?? '';
        $rest = array_slice($body, 1);

        $account = null;
        $bank = null;
        $name = null;
        $cardLast4 = null;
        foreach ($rest as $index => $line) {
            if (preg_match('/^(\d[\d\-]{3,})\/(\d{4})$/', $line, $m)) {
                $account = $m[1];
                $bank = $m[2];
                $before = $rest[$index - 1] ?? null;
                // Bez názvu protiúčtu stojí nad účtem identifikátor platby KB („YU0007B7HZR").
                if ($before !== null && preg_match('/\p{L}/u', $before)
                    && preg_match('/^(?=\S*\d)[A-Z0-9]{8,}$/', $before) !== 1) {
                    $name = $before;
                }
                break;
            }
        }
        if ($account === null) {
            foreach ($rest as $line) {
                if (preg_match('/^[A-Z]{2}\d{2}[A-Z]{4}\d[A-Z0-9]{9,26}$/', $line)) {
                    $account = $line;
                    break;
                }
            }
        }

        // Karetní platba: obchodník stojí mezi řádkem „… PLATEBNÍ KARTY" a maskovaným číslem karty.
        foreach ($rest as $index => $line) {
            $last4 = CardNumberMask::last4FromText($line);
            if ($last4 === null) continue;
            $cardLast4 = $last4;
            if ($name === null) {
                $merchant = [];
                for ($j = $index - 1; $j >= 0 && !str_contains($rest[$j], 'PLATEBNÍ KARTY'); $j--) {
                    array_unshift($merchant, $rest[$j]);
                }
                if ($merchant !== []) $name = implode(' ', $merchant);
            }
            break;
        }

        $parts = $type !== '' ? [$type] : [];
        foreach ($rest as $line) {
            if ($line === $name || $line === $account || $line === $account . '/' . $bank) continue;
            if (!in_array($line, $parts, true)) $parts[] = $line;
        }

        $row = [
            'posted_at'            => $dates[1] ?? $dates[0],
            'amount'               => round($amount, 2),
            'variable_symbol'      => $this->symbol($symbols[0]),
            'constant_symbol'      => $this->symbol($symbols[1]),
            'specific_symbol'      => $this->symbol($symbols[2]),
            'counterparty_account' => $account,
            'counterparty_bank'    => $bank,
            'counterparty_name'    => $name !== null ? mb_substr($name, 0, 190) : null,
            'description'          => $parts !== [] ? mb_substr(implode(' | ', $parts), 0, 255) : null,
            'bank_ref'             => $reference,
        ];
        if ($cardLast4 !== null) {
            $row['card_last4'] = $cardLast4;
        }
        return $row;
    }

    private function symbol(string $value): ?string
    {
        $value = ltrim($value, '0');
        return $value !== '' ? $value : null;
    }

    private function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
    }

    private function date(string $day, string $month, string $year): string
    {
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new \RuntimeException('KB PDF: neplatné datum.');
        }
        return sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);
    }

    private function num(string $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], $value);
    }
}
