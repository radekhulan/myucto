<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
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
 *  - nese variabilní symbol zálohy, nebo protistranu se jménem odběratele zálohy,
 *  - částkou sedí s tolerancí kurzu ({@see amountFits()}),
 *  - ještě nehradí jiný vydaný doklad (evidovaná platba).
 * Jen jednoznačný kandidát; evidovaná platba vznikne v měně zálohy ke dni pohybu a výplň se
 * pak zmenší ({@see DocumentLinker::matchPayments()}). Uhrazená částka, stav ani deník se
 * nemění, převod jen nahradí výplň doložitelnou úhradou - proto i v uzavřeném roce.
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
        $matched = 0;
        $ambiguous = [];
        foreach ($advances as $a) {
            $found = [];
            foreach ($byDay[$a['paid_on']] ?? [] as $r) {
                if ((self::symbolMatches($r['symbol'], $a['number']) || self::nameMatches($r['name'], $a['client']))
                    && self::amountFits($r['czk'], $a['room'], $a['currency'], $a['rate'], $r['currency'], $r['amount'])) {
                    $found[] = $r;
                }
            }
            if (count($found) > 1) {
                $ambiguous[] = $a['number'];
                continue;
            }
            if ($found === []) {
                continue;
            }
            $r = $found[0];
            $amount = $r['currency'] === $a['currency'] ? min($a['room'], $r['amount']) : $a['room'];
            if ($payments->record($ctx, $a['id'], 'bank', $r['id'], null, $amount, $r['date'], $moneyNo[$r['id']]) !== null) {
                unset($byDay[$r['date']][$r['id']]);
                $matched++;
            }
        }
        if ($matched > 0) {
            $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'advance_bank_receipts', $matched);
        }
        if ($ambiguous !== []) {
            $ctx->protocol->info(DocumentLinker::STEP_PAYMENTS, 'advance_bank_receipt_ambiguous', count($ambiguous)
                . ' zálohových faktur má v den úhrady víc možných příjmů na 324, úhradu převod nespároval: ' . implode(', ', array_slice($ambiguous, 0, 20)) . '.',
                ['documents' => array_slice($ambiguous, 0, ImportProtocol::LIST_LIMIT)]);
        }
        return $matched;
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

    private static function symbolMatches(string $symbol, string $number): bool
    {
        $a = preg_replace('/\D+/', '', $symbol) ?? '';
        $b = preg_replace('/\D+/', '', $number) ?? '';
        return $a !== '' && $b !== '' && ltrim($a, '0') === ltrim($b, '0');
    }

    private static function nameMatches(string $name, string $client): bool
    {
        $norm = static fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s)) ?? '';
        return $norm($name) !== '' && $norm($name) === $norm($client);
    }

    /**
     * Zálohové faktury převodu uhrazené do `$lastEnd`, jejichž uhrazenou částku evidované
     * platby (bez výplně) nepokrývají a které bankovní platbu ještě nemají.
     *
     * @return list<array{id:int,number:string,client:string,paid_on:string,currency:string,rate:?float,room:float}>
     */
    private function advances(int $supplierId, string $lastEnd): array
    {
        $filler = MoneyS3SettledPayments::fillerSql('ip');
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol, COALESCE(cl.company_name, '') AS client, DATE(i.paid_at) AS paid_on, cur.code AS currency, i.exchange_rate,
                    i.paid_total - COALESCE(pay.recorded, 0) AS room
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
            $out[] = ['id' => (int) $r['id'], 'number' => (string) $r['varsymbol'], 'client' => (string) $r['client'], 'paid_on' => (string) $r['paid_on'],
                'currency' => (string) $r['currency'], 'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'room' => round((float) $r['room'], 2)];
        }
        return $out;
    }

    /**
     * Bankovní příjmy dnů `$from`–`$to` s živým převzatým zápisem s Dal na 324, které ještě
     * nehradí žádný vydaný doklad: částka na 324 v Kč, částka a měna pohybu, symbol, protistrana.
     *
     * @return list<array{id:int,date:string,amount:float,currency:string,symbol:string,name:string,czk:float}>
     */
    private function receipts(int $supplierId, string $from, string $to): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT t.id, DATE(t.posted_at) AS posted_on, t.amount, t.currency, COALESCE(t.variable_symbol, '') AS symbol,
                    COALESCE(t.counterparty_name, '') AS name, SUM(l.amount) AS czk
               FROM bank_transactions t
               JOIN bank_statements s ON s.id = t.statement_id
               JOIN journal_entries e ON e.supplier_id = s.supplier_id AND e.source_type = 'bank' AND e.source_id = t.id AND e.reversed_by IS NULL
               JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id AND l.side = 'credit'
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.account_code LIKE '324%'
              WHERE s.supplier_id = ? AND t.posted_at >= ? AND t.posted_at < ? + INTERVAL 1 DAY AND t.amount > 0
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = t.id)
              GROUP BY t.id, t.posted_at, t.amount, t.currency, t.variable_symbol, t.counterparty_name"
        );
        $stmt->execute([$supplierId, $from, $to]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['id' => (int) $r['id'], 'date' => (string) $r['posted_on'], 'amount' => (float) $r['amount'], 'currency' => (string) $r['currency'],
                'symbol' => (string) $r['symbol'], 'name' => (string) $r['name'], 'czk' => round((float) $r['czk'], 2)];
        }
        return $out;
    }
}
