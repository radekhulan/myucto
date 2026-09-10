<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

/**
 * GPC/ABO parser — český formát bankovního výpisu.
 *
 * Záznamy jsou fixed-width řádky, identifikované 3-digit prefixem:
 *   074 — statement header (account, date, balance)
 *   075 — transaction (amount, VS, KS, SS, counterparty)
 *
 * Detail layoutu: viz https://www.gpcsoft.cz/gpc-format/
 *
 * Vrací: ['header' => [...], 'transactions' => [...]]
 */
final class GpcParser
{
    public function parse(string $content): array
    {
        // GPC formát je fixed-width (single-byte CP1250). DŮLEŽITÉ: parsujeme z RAW CP1250
        // bajtů, jinak se po iconv→UTF-8 multibyte znaky v poli "account name" (např. `í`,
        // `ý`) protáhnou ze 1 na 2 bajty a všechny offsety za názvem se posunou. Iconv až
        // na konkrétních textových polích po extrakci.
        $isUtf8 = mb_check_encoding($content, 'UTF-8');
        // Pokud vstup vypadá jako UTF-8 a obsahuje multibyte znaky, převeď zpět na CP1250
        // pro byte-aligned parsing (TRANSLIT zachová ASCII digity beze změny).
        if ($isUtf8 && preg_match('/[\x80-\xFF]/', $content)) {
            $back = @iconv('UTF-8', 'CP1250//TRANSLIT//IGNORE', $content);
            if ($back !== false) {
                $content = $back;
            }
        }

        $lines = preg_split('/\r\n|\n|\r/', $content);
        $header = null;
        $reconstructed = false;
        $transactions = [];
        $lastTxIndex = -1; // index poslední 075 transakce (pro navázání 078 avíza)

        foreach ($lines as $line) {
            if ($line === '' || strlen($line) < 3) continue;
            $type = substr($line, 0, 3);
            if ($type === '074') {
                $header = $this->parseHeader($line);
                $reconstructed = $reconstructed || $header['reconstructed'];
            } elseif ($type === '075') {
                $transactions[] = $this->parseTransaction($line);
                $lastTxIndex = count($transactions) - 1;
            } elseif ($type === '078' || $type === '079') {
                // Doplňující údaj / avízo k předchozí transakci — u kartových plateb tu
                // banka uvádí název obchodníka + lokalitu + maskované číslo karty
                // (např. "078NAZEV OBCHODU MESTO ZEME PK: 000000******0000"). V 075 je
                // client_name u karet prázdné, takže bez tohohle by se název ztratil.
                if ($lastTxIndex >= 0) {
                    $this->mergeAdditionalInfo($transactions[$lastTxIndex], substr($line, 3));
                }
            }
        }

        if ($header === null) {
            throw new \RuntimeException('GPC: chybí header (074 řádek).');
        }

        $header['reconstructed'] = $reconstructed;
        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * Layout 074 (statement header):
     *   1-3     "074"
     *   4-19    account number (16)
     *   20-39   account name (20)
     *   40-45   old balance date DDMMYY (6)
     *   46-59   old balance value v haléřích (14)
     *   60      old balance sign (+ / -)
     *   61-74   new balance value (14)
     *   75      new balance sign
     *   76-89   debit total (14)
     *   90      debit sign
     *   91-104  credit total (14)
     *   105     credit sign
     *   106-108 statement number (3)
     *   109-114 statement date DDMMYY (6)
     *
     * Reference: https://github.com/mbursa/gpc2csv (oficiální Python parser).
     */
    private function parseHeader(string $line): array
    {
        $pad = str_pad($line, 128, ' ');
        $accountNumber = trim(substr($pad, 3, 16));
        $oldBalanceDate = $this->parseDate(trim(substr($pad, 39, 6)));
        // Statement date je na pozici 108-114 (DDMMYY); pos 39-45 je old_balance_date.
        $statementDate = $this->parseDate(trim(substr($pad, 108, 6)));
        // Fallback: některé banky (Air Bank) občas vrátí nesmyslnou hodnotu / chybný layout —
        // místo SQL crashe použij old_balance_date (datum předchozí pozice = den před výpisem).
        if ($statementDate === null) {
            $statementDate = $oldBalanceDate ?? date('Y-m-d');
        }
        // Pozn.: balance sign je BYTE PŘED hodnotou v pythonu — substr(59,1) + substr(45,14) — viz reference.
        $prevBalance = $this->parseAmountWithSign(substr($pad, 45, 14), substr($pad, 59, 1));
        $currBalance = $this->parseAmountWithSign(substr($pad, 60, 14), substr($pad, 74, 1));
        $debitTotal  = $this->parseAmountWithSign(substr($pad, 75, 14), substr($pad, 89, 1));
        $creditTotal = $this->parseAmountWithSign(substr($pad, 90, 14), substr($pad, 104, 1));
        $statementNumber = trim(substr($pad, 105, 3));

        return [
            'account_number'   => $accountNumber,
            'reconstructed'    => trim(substr($pad, 19, 20)) === 'MYUCTO EXPORT',
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $currBalance,
            'debit_total'      => $debitTotal,
            'credit_total'     => $creditTotal,
        ];
    }

    /**
     * Layout 075 (transaction record):
     *   1-3     "075"
     *   4-19    own account (16)
     *   20-35   counterparty account (16)
     *   36-48   document number / record_number (13)
     *   49-60   amount v haléřích (12)
     *   61      posting code: 1=debit, 2=credit, 4=debit_storno, 5=credit_storno
     *   62-71   variable symbol (10)
     *   72-73   filler (2)
     *   74-77   counterparty bank code (4)            ← MEZI VS A KS!
     *   78-81   constant symbol (4)                   ← KS je jen 4 znaky
     *   82-91   specific symbol (10)
     *   92-97   filler / value_date (6)
     *   98-117  client_name / description (20)
     *   118-122 currency code (5)
     *   123-128 posting date DDMMYY (6)
     *
     * Reference: https://github.com/mbursa/gpc2csv (oficiální Python parser).
     */
    private function parseTransaction(string $line): array
    {
        $pad = str_pad($line, 160, ' ');
        $counterpartyAccount = trim(substr($pad, 19, 16));
        // Číslo dokladu (pozice 36-48) = ID pohybu, které banka pohybu přidělila (Fio,
        // ČSOB, KB i Air Bank ho sem plní). Je to JEDINÁ bankovní identita pohybu, kterou
        // GPC nese — bez ní dva legitimní pohyby stejné částky, dne, VS a popisu dostanou
        // v StatementImporter identický otisk a druhý se zahodí jako duplicita.
        $documentNumber = $this->normalizeDocumentNumber(trim(substr($pad, 35, 13)));
        $amountCents = (int) trim(substr($pad, 48, 12));
        $postingCode = trim(substr($pad, 60, 1));
        // VS/KS/SS jsou zleva doplněné nulami (right-justified). Strhni JEN vedoucí nuly
        // (padding) přes ltrim — NE trim(" 0"), které utne i VÝZNAMNÉ koncové nuly
        // (issue #150: VS 260100010 → 26010001). Samé nuly / mezery → '' → null.
        $vs = ltrim(trim(substr($pad, 61, 10)), '0');
        $bankCode = trim(substr($pad, 73, 4));   // pozice 74-77 (1-based)
        $ks = ltrim(trim(substr($pad, 77, 4)), '0');   // pozice 78-81, jen 4 znaky
        $ss = ltrim(trim(substr($pad, 81, 10)), '0');
        // Pole textová — konverze CP1250 → UTF-8 až po byte-aligned extrakci.
        $description = $this->cp1250ToUtf8(trim(substr($pad, 97, 20)));   // pozice 98-117 (client_name)
        $currency = trim(substr($pad, 117, 5));      // pozice 118-122 (currency code)
        $postedAt = $this->parseDate(trim(substr($pad, 122, 6)));   // pozice 123-128 DDMMYY

        $amount = $amountCents / 100.0;
        // Posting code: 1=debit (-), 2=credit (+), 4=storno debit (+), 5=storno credit (-)
        if (in_array($postingCode, ['1', '4'], true)) {
            $amount = -$amount;
        }
        // 4 = storno debit (vrácení odchozí) → +; 5 = storno credit (vrácení příchozí) → -
        if ($postingCode === '4') $amount = abs($amount);
        if ($postingCode === '5') $amount = -abs($amount);

        // Currency je 5-char ISO numeric (203 = CZK, 978 = EUR, …) nebo prázdné.
        // Některé banky tam dají alpha "CZK", jiné nechají prázdné — normalizujeme.
        $currencyCode = $this->normalizeCurrency($currency);

        return [
            'posted_at'            => $postedAt,
            'amount'               => round($amount, 2),
            'currency'             => $currencyCode,
            'variable_symbol'      => $vs ?: null,
            'constant_symbol'      => $ks ?: null,
            'specific_symbol'      => $ss ?: null,
            // Karetní pohyby (a poplatky) nemají protistranu — banka pole vyplní samými
            // nulami (`0000000000000000`). To NENÍ číslo účtu; kdyby se uložilo, tvářilo
            // by se jako známá protistrana a shodilo by cross-source dedup s e-mailovým
            // avízem („Blokace" bez protiúčtu vs. GPC s nulami = asymetrie → nepřevzato).
            'counterparty_account' => ltrim($counterpartyAccount, '0') !== '' ? $counterpartyAccount : null,
            'counterparty_bank'    => $bankCode ?: null,
            'counterparty_name'    => $description ?: null,  // GPC client_name = jméno protistrany
            'description'          => $description ?: null,
            'bank_ref'             => $documentNumber,
        ];
    }

    /**
     * Číslo dokladu z 075 → bankovní reference. Pole je zleva doplněné nulami
     * (right-justified), takže vodicí nuly strháváme, aby byla hodnota stabilní
     * napříč výpisy i zdroji (PDF parsery vracejí referenci bez paddingu).
     * Samé nuly / prázdné pole = banka ID pohybu neposlala → NULL (fallback otisk).
     */
    private function normalizeDocumentNumber(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $stripped = ltrim($raw, '0');
        if ($stripped === '') return null;
        return mb_substr($stripped, 0, 40);
    }

    /**
     * Navázání 078/079 avíza (doplňující text) na transakci. Sjednotí víc avíz do
     * description; pokud transakce nemá název protistrany (typicky karetní platba,
     * kde je 075 client_name prázdné), použije z avíza i jméno obchodníka.
     *
     * @param array<string,mixed> $tx by-ref
     */
    private function mergeAdditionalInfo(array &$tx, string $rawInfo): void
    {
        $info = $this->cp1250ToUtf8(trim($rawInfo));
        if ($info === '') return;

        // description: připoj (nebo nastav) — chceme zachovat vše (název, lokalita, karta).
        $existing = (string) ($tx['description'] ?? '');
        $merged = $existing === '' ? $info : ($existing . ' | ' . $info);
        $tx['description'] = mb_substr($merged, 0, 255);

        // Koncovka karty z maskovaného čísla („PK: 000000******1234") do vlastního
        // sloupce — párování plateb kartou na ní stojí. Celé číslo GPC nenese.
        if (empty($tx['card_last4'])) {
            $last4 = \MyInvoice\Service\Bank\Card\CardNumberMask::last4FromText($info);
            if ($last4 !== null) {
                $tx['card_last4'] = $last4;
            }
        }

        // counterparty_name: jen pokud chybí. Vezmi část před "PK:" (maskované č. karty),
        // jinak celý text. Tím u karet doplníme obchodníka (název před " PK:").
        if (empty($tx['counterparty_name'])) {
            $name = $info;
            $pkPos = stripos($name, ' PK:');
            if ($pkPos !== false) {
                $name = substr($name, 0, $pkPos);
            }
            $name = trim(\MyInvoice\Service\Bank\Card\CardNumberMask::stripMasked($name));
            if ($name !== '') {
                $tx['counterparty_name'] = mb_substr($name, 0, 190);
            }
        }
    }

    /**
     * GPC numeric currency code (ISO 4217 numeric) → ISO 4217 alpha (CZK / EUR / USD …).
     * Některé banky vrací alpha přímo. Vrací NULL pokud nelze rozpoznat.
     */
    private function normalizeCurrency(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/^[A-Z]{3}$/', $raw)) return $raw;
        // GPC ukládá ISO numeric jako zero-padded "00203" — strip leading zeros pro lookup.
        $stripped = ltrim($raw, '0');
        if ($stripped === '') return null;
        $map = [
            '203' => 'CZK', '978' => 'EUR', '840' => 'USD', '826' => 'GBP',
            '756' => 'CHF', '985' => 'PLN', '348' => 'HUF', '946' => 'RON',
            '208' => 'DKK', '752' => 'SEK', '578' => 'NOK', '124' => 'CAD',
            '36'  => 'AUD', '392' => 'JPY',
        ];
        return $map[$stripped] ?? null;
    }

    private function cp1250ToUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'ASCII')) return $s;
        $u = @iconv('CP1250', 'UTF-8//TRANSLIT', $s);
        return $u !== false ? $u : $s;
    }

    private function parseAmountWithSign(string $rawAmount, string $sign): float
    {
        $cents = (int) trim($rawAmount);
        $val = $cents / 100.0;
        return ($sign === '-') ? -$val : $val;
    }

    /**
     * GPC datum DDMMYY. Roky 50-99 = 19xx, 00-49 = 20xx.
     */
    private function parseDate(string $ddmmyy): ?string
    {
        if (!preg_match('/^\d{6}$/', $ddmmyy)) return null;
        $d = (int) substr($ddmmyy, 0, 2);
        $m = (int) substr($ddmmyy, 2, 2);
        $y = (int) substr($ddmmyy, 4, 2);
        $year = $y >= 50 ? (1900 + $y) : (2000 + $y);
        if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }
}
