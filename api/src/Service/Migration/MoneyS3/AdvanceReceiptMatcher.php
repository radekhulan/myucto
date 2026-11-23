<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use PDO;

/**
 * Úhrada zálohy z Money spárovaná s převzatým bankovním příjmem na 324.
 *
 * Money vede úhradu zálohové faktury datem a číslem dokladu úhrady (`UDoklad`). Když číslo na
 * pohyb nevede (interní doklad bez zápisu, úhrada z devizového účtu s jiným číslem) a částky
 * přesně nesedí kvůli kurzu, párování podle čísla ani symbolu ji nenajde a uhrazenou zálohu
 * kryje jen výplň bez dokladu úhrady ({@see MoneyS3SettledPayments}). Přijatou zálohu ale nese
 * převzatý bankovní zápis na 324 - konečná faktura bez něj skončí na „Úhrada zálohy není
 * zaúčtovaná" ({@see \MyInvoice\Service\Accounting\AdvanceSettlementEvidenceReader::advanceReceived()}).
 *
 * Zálohová faktura s částí krytou jen výplní se proto spáruje s bankovním příjmem, který:
 *  - má živý převzatý bankovní zápis s Dal na 324,
 *  - je ze dne úhrady zálohy podle Money,
 *  - nese variabilní symbol zálohy, nebo protistranu se jménem odběratele zálohy, a identita
 *    si neodporuje: symbol nepatří jiné zálohové faktuře ani dokladu jiného odběratele a jméno
 *    protistrany se od odběratele neliší,
 *  - částí zaúčtovanou na 324 sedí na nekrytou část zálohy ({@see receivedOn324()}, {@see amountFits()}),
 *  - ještě nehradí jiný vydaný doklad (evidovaná platba).
 * Párování je jednoznačné v obou směrech: záloha má jediný kandidátní příjem a příjem jedinou
 * kandidátní zálohu. Evidovaná platba vznikne v měně zálohy ke dni pohybu, nejvýš do částky,
 * kterou řádky 324 zápisu přijaly - z ní čte doložené čerpání konečná faktura. Výplň se pak
 * zmenší ({@see DocumentLinker::matchPayments()}). Uhrazená částka, stav ani deník se nemění.
 * Běžný převod páruje jen v zapisovatelném období ({@see ImportContext::writable()}), uzavřené
 * roky a data pod zámkem jen opravný převod vazeb, stejně jako ostatní párování úhrad.
 * Nejednoznačné, rozporné, neocenitelné a nezapisovatelné případy zůstanou ve výplni
 * a protokol je ohlásí k ověření.
 */
final class AdvanceReceiptMatcher
{
    /** Tolerance přepočtu kurzem: částka zálohy v Kč proti příjmu na 324. */
    private const RATE_TOLERANCE = 0.015;

    public function __construct(
        private readonly Connection $db,
    ) {}

    /** @return int počet spárovaných záloh */
    public function match(ImportContext $ctx, IssuedPaymentRecorder $payments): int
    {
        $lastEnd = $ctx->lastPeriodEnd();
        if ($lastEnd === null) {
            return 0;
        }
        $advances = $this->advances($ctx->supplierId, substr($lastEnd, 0, 10));
        if ($advances === []) {
            return 0;
        }
        $dates = array_unique(array_column($advances, 'paid_on'));
        sort($dates);
        $receipts = $this->receipts($ctx->supplierId, $dates[0], $dates[count($dates) - 1]);
        $moneyNo = [];
        foreach ($ctx->bankTransactions as $key => $id) {
            $moneyNo[(int) $id] = explode('|', (string) $key, 3)[1] ?? '';
        }
        $byDay = [];
        foreach ($receipts as $r) {
            if (isset($moneyNo[$r['id']])) {
                $byDay[$r['date']][$r['id']] = $r;
            }
        }
        $owners = $this->symbolOwners($ctx->supplierId, array_merge([], ...array_values(array_map('array_values', $byDay))));

        $found = [];
        $claims = [];
        $conflicts = [];
        $review = ['conflict' => [], 'unpriced' => [], 'ambiguous' => [], 'closed' => []];
        foreach ($advances as $i => $a) {
            foreach ($byDay[$a['paid_on']] ?? [] as $r) {
                $bySymbol = self::symbolMatches($r['symbol'], $a['number']);
                if (!$bySymbol && !self::nameMatches($r['name'], $a['client'])) {
                    continue;
                }
                if (self::identityConflict($r, $a, $bySymbol, $owners)) {
                    $conflicts[$i] = true;
                    continue;
                }
                $received = self::receivedOn324($r, $a['currency'], $a['rate']);
                if ($received === null) {
                    // Neocenitelný příjem může být úhradou zálohy: blokuje jednoznačnost v obou směrech.
                    $found[$i][$r['id']] = null;
                    $claims[$r['id']][$i] = true;
                    continue;
                }
                if (self::receiptFits($r, $a, $received)) {
                    $found[$i][$r['id']] = $received;
                    $claims[$r['id']][$i] = true;
                }
            }
        }

        foreach (array_keys(array_diff_key($conflicts, $found)) as $i) {
            $review['conflict'][$advances[$i]['number']] = true;
        }
        $matched = 0;
        foreach ($found as $i => $candidates) {
            $a = $advances[$i];
            $txId = array_key_first($candidates);
            if (count($candidates) > 1 || count($claims[$txId]) > 1) {
                $review['ambiguous'][$a['number']] = true;
                continue;
            }
            if ($candidates[$txId] === null) {
                $review['unpriced'][$a['number']] = true;
                continue;
            }
            $r = $byDay[$a['paid_on']][$txId];
            if (!$ctx->options->relinkOnly && !$ctx->writable($r['date'])) {
                $review['closed'][$a['number']] = true;
                continue;
            }
            $amount = round(min($a['room'], $candidates[$txId]['amount']), 2);
            if ($payments->record($ctx, $a['id'], 'bank', $r['id'], null, $amount, $r['date'], $moneyNo[$r['id']]) !== null) {
                $matched++;
            }
        }
        if ($matched > 0) {
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_bank_receipts', $matched);
        }
        $this->report($ctx, $review);
        return $matched;
    }

    /**
     * Případy k ověření: úhrada zálohy zůstane ve výplni bez dokladu úhrady.
     *
     * @param array<string,array<string,true>> $review
     */
    private function report(ImportContext $ctx, array $review): void
    {
        $texts = [
            'ambiguous' => ['advance_bank_receipt_ambiguous', 'zálohových faktur má v den úhrady víc možných příjmů na 324, nebo příjem připadá na víc záloh'],
            'conflict' => ['advance_bank_receipt_conflict', 'zálohových faktur má v den úhrady příjem na 324, jehož symbol patří jinému dokladu nebo jehož protistrana se liší od odběratele'],
            'unpriced' => ['advance_bank_receipt_unpriced', 'zálohových faktur má v den úhrady příjem na 324 v cizí měně, jehož část na 324 nejde ocenit v měně zálohy (řádek bez valuty, smíšené řádky)'],
            'closed' => ['advance_bank_receipt_closed_period', 'zálohových faktur má jednoznačný příjem na 324 v uzavřeném období; spáruje je opravný převod vazeb'],
        ];
        foreach ($texts as $key => [$code, $text]) {
            $numbers = array_keys($review[$key]);
            if ($numbers === []) {
                continue;
            }
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, $code, count($numbers));
            $message = count($numbers) . ' ' . $text . ', úhradu převod nespároval a ponechal ve výplni bez dokladu úhrady: '
                . implode(', ', array_slice($numbers, 0, 20)) . '.';
            $context = ['documents' => array_slice($numbers, 0, ImportProtocol::LIST_LIMIT)];
            // Uzavřené období opakovaný převod jen připomene (stejně jako ostatní párování ho přeskočí), ostatní k ověření.
            if ($key === 'closed') {
                $ctx->protocol->info(DocumentLinker::STEP_PAYMENTS, $code, $message, $context);
            } else {
                $ctx->protocol->warn(DocumentLinker::STEP_PAYMENTS, $code, $message . ' Ověřte úhradu zálohy a případně ji spárujte ručně.', $context);
            }
        }
    }

    /**
     * Část příjmu zaúčtovaná na 324 v měně zálohy, nebo null, když ji nejde ocenit:
     *  - záloha v Kč: Kč řádků 324,
     *  - řádky 324 nesou valutu (vlastní, nebo řádek Money, ze kterého pocházejí - bankovní
     *    řádek valutového účtu) a všechny v měně zálohy: součet valut (přesně),
     *  - řádky 324 bez valuty a příjem v měně zálohy: celý pohyb, jen když na 324 jde celý
     *    zápis (přesně); rozdělený příjem bez valuty ocenit nejde,
     *  - příjem v jiné měně: Kč řádků 324 přepočtené kurzem zálohy, stejně jako je zpět
     *    přepočte zúčtování zálohy ({@see \MyInvoice\Service\Accounting\PostingService}),
     *    takže doložené čerpání nepřesáhne Kč přijaté na 324; bez kurzu ocenit nejde.
     * Smíšené řádky (část s valutou, jiná měna valuty) ocenit nejde.
     *
     * @param array{czk:float,total:float,currency:string,amount:float,lines:int,fx_lines:int,fx_currencies:int,fx_currency:string,fx:float} $r
     * @return array{amount:float,exact:bool}|null
     */
    public static function receivedOn324(array $r, string $currency, ?float $rate): ?array
    {
        if ($r['czk'] < 0.01) {
            return null;
        }
        if ($currency === 'CZK') {
            return ['amount' => $r['czk'], 'exact' => true];
        }
        if ($r['fx_lines'] > 0) {
            return $r['fx_lines'] === $r['lines'] && $r['fx_currencies'] === 1 && $r['fx_currency'] === $currency && $r['fx'] >= 0.01
                ? ['amount' => $r['fx'], 'exact' => true]
                : null;
        }
        if ($r['currency'] === $currency) {
            return abs($r['czk'] - $r['total']) < 0.005 ? ['amount' => $r['amount'], 'exact' => true] : null;
        }
        return $rate !== null && $rate > 0 ? ['amount' => round($r['czk'] / $rate, 2), 'exact' => false] : null;
    }

    /**
     * @param array{czk:float,currency:string,amount:float} $r
     * @param array{currency:string,rate:?float,room:float} $a
     * @param array{amount:float,exact:bool} $received
     */
    private static function receiptFits(array $r, array $a, array $received): bool
    {
        if ($a['currency'] !== 'CZK' && $received['exact']) {
            return abs($received['amount'] - $a['room']) < 0.005;
        }
        return self::amountFits($r['czk'], $a['room'], $a['currency'], $a['rate'], $r['currency'], $r['amount']);
    }

    /**
     * Sedí příjem na 324 částkou na nekrytou část zálohy? Ve stejné měně na haléř, jinak
     * přes Kč s tolerancí kurzu (záloha v Kč na devizový účet, záloha ve měně přepočtená
     * jiným kurzem, než jakým Money příjem zaúčtovalo).
     *
     * @param float $czk324 příjem zaúčtovaný na Dal 324 (Kč)
     * @param float $amount nekrytá část zálohy v měně zálohy
     */
    public static function amountFits(float $czk324, float $amount, string $currency, ?float $rate, string $txCurrency, float $txAmount): bool
    {
        if ($currency === $txCurrency && $currency !== 'CZK') {
            return abs($txAmount - $amount) < 0.005;
        }
        $expected = $currency === 'CZK' ? $amount : ($rate !== null && $rate > 0 ? $amount * $rate : null);
        if ($expected === null || $expected <= 0) {
            return false;
        }
        if ($currency === 'CZK' && $txCurrency === 'CZK') {
            return abs($czk324 - $expected) < 0.005;
        }
        return abs($czk324 - $expected) <= max(1.0, $expected * self::RATE_TOLERANCE);
    }

    /**
     * Odporuje si identita příjmu a zálohy? Symbol příjmu patří jiné zálohové faktuře, nebo
     * dokladu jiného odběratele (daňový doklad k platbě či konečná faktura téhož odběratele
     * symbol zálohy nést smí). U shody podle symbolu se navíc jméno protistrany nesmí lišit
     * od odběratele zálohy (jedno nemusí být celé, např. bez části názvu).
     *
     * @param array{symbol:string,name:string} $r
     * @param array{id:int,client_id:int,client:string} $a
     * @param array<string,list<array{id:int,proforma:bool,client_id:int}>> $owners
     */
    private static function identityConflict(array $r, array $a, bool $bySymbol, array $owners): bool
    {
        foreach ($owners[VariableSymbolNormalizer::matchKey($r['symbol'])] ?? [] as $o) {
            if ($o['id'] !== $a['id'] && ($o['proforma'] || $o['client_id'] !== $a['client_id'])) {
                return true;
            }
        }
        if (!$bySymbol) {
            return false;
        }
        $name = self::normalizedName($r['name']);
        $client = self::normalizedName($a['client']);
        return $name !== '' && $client !== '' && !str_contains($name, $client) && !str_contains($client, $name);
    }

    private static function symbolMatches(string $symbol, string $number): bool
    {
        $a = preg_replace('/\D+/', '', $symbol) ?? '';
        $b = preg_replace('/\D+/', '', $number) ?? '';
        return $a !== '' && $b !== '' && ltrim($a, '0') === ltrim($b, '0');
    }

    private static function nameMatches(string $name, string $client): bool
    {
        return self::normalizedName($name) !== '' && self::normalizedName($name) === self::normalizedName($client);
    }

    private static function normalizedName(string $s): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
    }

    /**
     * Zálohové faktury převodu uhrazené do `$lastEnd`, jejichž uhrazenou částku evidované
     * platby (bez výplně) nepokrývají a které bankovní platbu ještě nemají.
     *
     * @return list<array{id:int,number:string,client_id:int,client:string,paid_on:string,currency:string,rate:?float,room:float}>
     */
    private function advances(int $supplierId, string $lastEnd): array
    {
        $filler = MoneyS3SettledPayments::fillerSql('ip');
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, COALESCE(i.client_id, 0) AS client_id, COALESCE(cl.company_name, '') AS client, DATE(i.paid_at) AS paid_on,
                    cur.code AS currency, i.exchange_rate, i.paid_total - COALESCE(pay.recorded, 0) AS room
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
               LEFT JOIN clients cl ON cl.id = i.client_id AND cl.supplier_id = i.supplier_id
               LEFT JOIN (SELECT ip.invoice_id, SUM(CASE WHEN {$filler} THEN 0 ELSE ip.amount END) AS recorded,
                                 MAX(ip.bank_transaction_id IS NOT NULL) AS has_bank
                            FROM invoice_payments ip WHERE ip.supplier_id = ? GROUP BY ip.invoice_id) pay ON pay.invoice_id = i.id
              WHERE i.supplier_id = ? AND i.invoice_type = 'proforma' AND i.status NOT IN ('draft', 'cancelled')
                AND i.paid_at IS NOT NULL AND i.paid_at <= ? AND COALESCE(pay.has_bank, 0) = 0
                AND i.paid_total - COALESCE(pay.recorded, 0) >= 0.01
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = ? AND m.target_id = i.id)"
        );
        $stmt->execute([$supplierId, $supplierId, $lastEnd . ' 23:59:59', MoneyS3ImportRepository::KIND_INVOICE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int) $r['id'], 'number' => (string) $r['varsymbol'], 'client_id' => (int) $r['client_id'], 'client' => (string) $r['client'],
                'paid_on' => (string) $r['paid_on'], 'currency' => (string) $r['currency'],
                'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'room' => round((float) $r['room'], 2)];
        }
        return $out;
    }

    /**
     * Vydané doklady (bez konceptů a stornovaných), jejichž číslo nebo variabilní symbol nese
     * symbol některého z příjmů: klíč symbolu => doklady.
     *
     * @param list<array{symbol:string}> $receipts
     * @return array<string,list<array{id:int,proforma:bool,client_id:int}>>
     */
    private function symbolOwners(int $supplierId, array $receipts): array
    {
        $keys = [];
        foreach ($receipts as $r) {
            $key = VariableSymbolNormalizer::matchKey($r['symbol']);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        if ($keys === []) {
            return [];
        }
        $keys = array_keys($keys);
        $in = implode(',', array_fill(0, count($keys), '?'));
        $select = "SELECT id, invoice_type, COALESCE(client_id, 0) AS client_id, %s AS match_key FROM invoices
                    WHERE supplier_id = ? AND %s IN ({$in}) AND status NOT IN ('draft', 'cancelled')";
        $stmt = $this->db->pdo()->prepare(sprintf($select, 'varsymbol_match_key', 'varsymbol_match_key') . ' UNION '
            . sprintf($select, 'payment_variable_symbol_match_key', 'payment_variable_symbol_match_key'));
        $stmt->execute(array_merge([$supplierId], $keys, [$supplierId], $keys));
        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $key = (string) $o['match_key'];
            if (isset($seen[$key][(int) $o['id']])) {
                continue;
            }
            $seen[$key][(int) $o['id']] = true;
            $out[$key][] = ['id' => (int) $o['id'], 'proforma' => $o['invoice_type'] === 'proforma', 'client_id' => (int) $o['client_id']];
        }
        return $out;
    }

    /**
     * Bankovní příjmy dnů `$from`–`$to` s živým převzatým zápisem s Dal na 324, které ještě
     * nehradí žádný vydaný doklad: čistý příjem na 324 v Kč a celý zápis v Kč, částka a měna
     * pohybu, symbol, protistrana a valuta řádků 324. Valutu řádku 324 nese řádek sám, nebo
     * protistrana téhož řádku deníku Money (MD a D jdou za sebou, {@see JournalImporter}) -
     * u příjmu na devizový účet bankovní řádek s měnou účtu.
     *
     * @return list<array{id:int,date:string,amount:float,currency:string,symbol:string,name:string,czk:float,total:float,lines:int,fx_lines:int,fx_currencies:int,fx_currency:string,fx:float}>
     */
    private function receipts(int $supplierId, string $from, string $to): array
    {
        $is324 = 'a.id IS NOT NULL';
        $fxCurrency = 'COALESCE(l.currency_code, pl.currency_code)';
        $fxAmount = 'COALESCE(l.signed_amount_foreign, pl.signed_amount_foreign, 0)';
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, DATE(t.posted_at) AS posted_on, t.amount, t.currency, COALESCE(t.variable_symbol, '') AS symbol,
                    COALESCE(t.counterparty_name, '') AS name,
                    SUM(CASE WHEN {$is324} AND l.side = 'credit' THEN l.signed_amount WHEN {$is324} THEN -l.signed_amount ELSE 0 END) AS czk,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE 0 END) AS total,
                    COUNT(a.id) AS line_count,
                    COUNT(CASE WHEN {$is324} THEN {$fxCurrency} END) AS fx_lines,
                    COUNT(DISTINCT CASE WHEN {$is324} THEN {$fxCurrency} END) AS fx_currencies,
                    MAX(CASE WHEN {$is324} THEN {$fxCurrency} END) AS fx_currency,
                    SUM(CASE WHEN {$is324} AND l.side = 'credit' THEN {$fxAmount} WHEN {$is324} THEN -{$fxAmount} ELSE 0 END) AS fx
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
               JOIN journal_entries e ON e.supplier_id = s.supplier_id AND e.source_type = 'bank' AND e.source_id = t.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
               LEFT JOIN chart_of_accounts a ON a.id = l.account_id AND a.account_code LIKE '324%'
               LEFT JOIN journal_entry_lines pl ON {$is324} AND l.currency_code IS NULL
                    AND pl.supplier_id = l.supplier_id AND pl.entry_id = l.entry_id AND pl.side <> l.side
                    AND pl.line_no = IF(l.side = 'credit', l.line_no - 1, l.line_no + 1)
                    AND pl.amount = l.amount AND pl.is_red_storno = l.is_red_storno
              WHERE s.supplier_id = ? AND t.posted_at >= ? AND t.posted_at < ? + INTERVAL 1 DAY AND t.amount > 0
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = t.id)
              GROUP BY t.id, t.posted_at, t.amount, t.currency, t.variable_symbol, t.counterparty_name
             HAVING czk >= 0.01"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int) $r['id'], 'date' => (string) $r['posted_on'], 'amount' => (float) $r['amount'], 'currency' => (string) $r['currency'],
                'symbol' => (string) $r['symbol'], 'name' => (string) $r['name'], 'czk' => round((float) $r['czk'], 2), 'total' => round((float) $r['total'], 2),
                'lines' => (int) $r['line_count'], 'fx_lines' => (int) $r['fx_lines'], 'fx_currencies' => (int) $r['fx_currencies'],
                'fx_currency' => (string) ($r['fx_currency'] ?? ''), 'fx' => round((float) $r['fx'], 2)];
        }
        return $out;
    }
}
