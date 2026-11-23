<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Support\Sql\JournalSettlementAllocationSql;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;

/**
 * Úhrady dokladů z Money S3, ke kterým převedená data nemají doklad úhrady.
 *
 * Money vede úhradu dokladu datem `Uhrazeno` a číslem posledního dokladu úhrady
 * (`UDoklad`). Převod z nich nastaví vydanému dokladu uhrazenou částku (`paid_total`)
 * a stav, evidovanou platbu ale zakládá jen k pohybu banky, pokladnímu dokladu a zápočtu
 * ({@see IssuedPaymentRecorder}). Úhrada zápočtem či interním dokladem mimo párovací
 * symbol, nebo s prázdným `UDoklad`, tak zůstala jen v `paid_total`. Každá změna plateb
 * dokladu ({@see \MyInvoice\Service\Invoice\InvoicePaymentService::recomputeLocked()})
 * pak `paid_total` přepočte ze součtu plateb a doklad se vrátí mezi neuhrazené
 * (upomínky, saldokonto, výpis neuhrazených).
 *
 * Vydaný doklad (faktura, zálohová faktura) proto dostane na rozdíl `paid_total` proti
 * evidovaným platbám:
 *  - zápočet interním dokladem ({@see recordSettlement()}), když na něj převod navázal
 *    ruční zápis interního dokladu, který saldo dokladu snižuje (vazba
 *    `journal_entry_document_links`). Stejně jako {@see PairingSymbolMatcher}: `invoice_settlements`
 *    s vazbou na zápis a evidovaná platba `settlement`. Saldokonto pak čte zápočet jen
 *    přes platbu, vazba zůstane odkazem ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}),
 *    jinak by vazbu i platbu započetlo dvakrát,
 *  - zbytek jednou platbou `settlement` s poznámkou {@see TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE}
 *    ke dni úhrady. Bez bankovního pohybu, pokladního dokladu i zápisu v deníku, smazat ji
 *    nejde ({@see TakenOverPayment::isProtected()}).
 *
 * Přijatý doklad uhrazený podle Money, který evidované úhrady nepokrývají, dostane stejně
 * zápočet interním dokladem navázaným na doklad a zbytek do `settled_outside_amount`
 * ({@see restorePurchaseSettlements()}), jako převod z POHODY.
 *
 * Jen doklady uhrazené do konce posledního převáděného období ({@see lastTransferredPeriodEnd()}):
 * úhradu po něm nese rok, který už běží v MyÚčtu. Vše se nastavuje absolutně a nejvýš do
 * uhrazené částky podle Money, opakovaný převod ani oprava dříve převedených firem
 * ({@see MoneyS3SettledPaymentRepair}) nic nezdvojí. Deník, `paid_total`, stav a datum
 * úhrady dokladu se nemění. Platba je v měně dokladu.
 */
final class MoneyS3SettledPayments
{
    public const SOURCE = 'settlement';

    /** Druhy vydaných dokladů s evidovanými platbami (jako {@see \MyInvoice\Service\Invoice\InvoicePaymentService}). */
    public const PAYABLE_TYPES = ['invoice', 'proforma'];

    /** Druhy přijatých dokladů, které se platí (DDKP úhradu nemá, dobropis má částku k úhradě zápornou). */
    public const PURCHASE_KINDS = ['invoice', 'advance'];

    /**
     * Saldokontní účty dokladů Money, na kterých zápis interního dokladu doklad vyrovnává,
     * a strana, na které saldo dokladu snižuje: vydané pohledávky a přijaté zálohy na straně
     * Dal, přijaté závazky a poskytnuté zálohy na straně Má dáti.
     */
    private const SETTLEMENT_ACCOUNTS = [
        'invoice' => ['side' => 'credit', 'prefixes' => ['311', '315', '324']],
        'purchase_invoice' => ['side' => 'debit', 'prefixes' => ['321', '325', '314']],
    ];

    public function __construct(private readonly Connection $db) {}

    /** Poznámka platby bez dokladu úhrady, s číslem úhrady z Money (`UDoklad`), pokud ho Money vede. */
    public static function note(?string $paymentNo = null): string
    {
        $paymentNo = trim((string) $paymentNo);
        return mb_substr(TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . ($paymentNo === '' ? '' : ' (' . $paymentNo . ')'), 0, 255);
    }

    /**
     * Konec posledního převáděného období: do něj patří úhrady, které převod z Money přebírá.
     * Úhradu po něm vede Money v roce, který už běží v MyÚčtu.
     *
     * V běhu převodu je to poslední období rozvrhu deníku (`ImportContext::$periods`), v opravě
     * dříve převedené firmy poslední období z mapy převodu (druh `period`).
     *
     * V běhu převodu se ptá {@see ImportContext::lastPeriodEnd()} (stejné pravidlo jako
     * {@see ImportContext::paidWithin()} u data úhrady dokladu).
     */
    public function lastTransferredPeriodEnd(int $supplierId, ?ImportContext $ctx = null): ?string
    {
        if ($ctx !== null) {
            $end = $ctx->lastPeriodEnd();
            return $end === null ? null : substr($end, 0, 10);
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(ap.ends_on) FROM money_s3_import_map m
               JOIN accounting_periods ap ON ap.id = m.target_id AND ap.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.kind = ?"
        );
        $stmt->execute([$supplierId, MoneyS3ImportRepository::KIND_PERIOD]);
        $end = $stmt->fetchColumn();
        return is_string($end) && $end !== '' ? substr($end, 0, 10) : null;
    }

    /**
     * Vydané doklady převodu, jejichž uhrazená částka (`paid_total`) převyšuje evidované
     * platby, nebo které už platbu bez dokladu úhrady mají (k přepočtu): id, číslo, měna,
     * kurz, částky a datum úhrady. Jen doklady uhrazené do `$lastEnd`; platba bez dokladu
     * úhrady u dokladu uhrazeného později se zruší.
     *
     * @return list<array{id:int,document_no:string,invoice_type:string,status:string,currency:string,rate:?float,
     *                    paid_at:?string,paid_total:float,amount_to_pay:float,others:float,own_id:?int,own_amount:float,in_range:bool,payment_no:?string}>
     */
    public function issuedCandidates(int $supplierId, string $lastEnd, bool $forUpdate = false): array
    {
        $types = "'" . implode("','", self::PAYABLE_TYPES) . "'";
        $note = TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE;
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS document_no, i.invoice_type, i.status,
                    cur.code AS currency, i.exchange_rate, i.paid_at, i.paid_total, i.amount_to_pay,
                    COALESCE(pay.others, 0) AS others, pay.own_id, pay.own_note, COALESCE(pay.own_amount, 0) AS own_amount
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
               LEFT JOIN (SELECT ip.invoice_id,
                                 SUM(CASE WHEN ip.source = ? AND ip.note LIKE ? THEN 0 ELSE ip.amount END) AS others,
                                 MIN(CASE WHEN ip.source = ? AND ip.note LIKE ? THEN ip.id END) AS own_id,
                                 MIN(CASE WHEN ip.source = ? AND ip.note LIKE ? THEN ip.note END) AS own_note,
                                 SUM(CASE WHEN ip.source = ? AND ip.note LIKE ? THEN ip.amount ELSE 0 END) AS own_amount
                            FROM invoice_payments ip WHERE ip.supplier_id = ? GROUP BY ip.invoice_id) pay ON pay.invoice_id = i.id
              WHERE i.supplier_id = ? AND i.invoice_type IN ({$types}) AND i.status NOT IN ('draft', 'cancelled')
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = ? AND m.target_id = i.id)
                AND (i.paid_total - COALESCE(pay.others, 0) - COALESCE(pay.own_amount, 0) >= 0.01 OR pay.own_id IS NOT NULL)
              ORDER BY i.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([self::SOURCE, $note . '%', self::SOURCE, $note . '%', self::SOURCE, $note . '%', self::SOURCE, $note . '%', $supplierId, $supplierId, MoneyS3ImportRepository::KIND_INVOICE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $paidAt = $r['paid_at'] === null ? null : substr((string) $r['paid_at'], 0, 10);
            $out[] = [
                'id' => (int) $r['id'], 'document_no' => (string) $r['document_no'], 'invoice_type' => (string) $r['invoice_type'],
                'status' => (string) $r['status'], 'currency' => (string) $r['currency'],
                'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'paid_at' => $paidAt, 'paid_total' => (float) $r['paid_total'], 'amount_to_pay' => (float) $r['amount_to_pay'],
                'others' => (float) $r['others'], 'own_id' => $r['own_id'] !== null ? (int) $r['own_id'] : null,
                'own_amount' => (float) $r['own_amount'], 'in_range' => $paidAt !== null && $paidAt <= $lastEnd,
                'payment_no' => self::paymentNoFromNote($r['own_note'] !== null ? (string) $r['own_note'] : null),
            ];
        }
        return $out;
    }

    /**
     * Zapíše úhrady vydaných dokladů bez dokladu úhrady (viz třída). Volat UVNITŘ transakce
     * volajícího.
     *
     * Výplň (platba bez dokladu úhrady) nevznikne u dokladu, jehož úhrada je v převedené bance
     * či pokladně ({@see pendingBankMatches()}, `$leftForMatching`): patří párování, které ji
     * zaeviduje jako bankovní či pokladní platbu; dřívější výplň se u něj zruší. Takové doklady
     * vrací `left_for_matching` k ohlášení.
     *
     * @param array<int,string> $paymentNos id dokladu => číslo úhrady z Money (`UDoklad`) do poznámky
     * @param array<int,true> $leftForMatching doklady, jejichž úhrada má kandidáty v převedené bance či pokladně
     * @param bool $inferSettlements bez zálohy Money (opravné CLI): zápis zápočtu, jehož číslo úhrady
     *                               chybí, se hledá podle data úhrady a částky ({@see inferNamed()});
     *                               nepotvrzené shody vrací `proposals`
     * @return array{settlements:int,settlement_amount:float,inserted:int,updated:int,deleted:int,invoices:list<array<string,mixed>>,left_for_matching:list<array<string,mixed>>,proposals:list<array<string,mixed>>}
     */
    public function recordIssued(int $supplierId, ?int $userId, string $lastEnd, array $paymentNos = [], array $leftForMatching = [], bool $inferSettlements = false): array
    {
        $out = ['settlements' => 0, 'settlement_amount' => 0.0, 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'invoices' => [], 'left_for_matching' => [], 'proposals' => []];
        $candidates = $this->issuedCandidates($supplierId, $lastEnd, true);
        if ($candidates === []) {
            return $out;
        }
        $leftForMatching += $this->pendingBankMatches($supplierId, array_column($candidates, 'id'));
        $pdo = $this->db->pdo();
        $named = [];
        foreach ($candidates as $c) {
            $no = $paymentNos[$c['id']] ?? $c['payment_no'];
            if ($c['in_range'] && $no !== null && $no !== '') {
                $named[$c['id']] = ['no' => $no, 'date' => (string) $c['paid_at']];
            }
        }
        if ($inferSettlements) {
            $inferred = $this->inferNamed($supplierId, 'invoice', array_map(static fn (array $c): array => $c + ['room' => round($c['paid_total'] - $c['others'], 2)],
                array_filter($candidates, static fn (array $c): bool => $c['in_range'] && !isset($named[$c['id']]))));
            $named += $inferred['named'];
            $out['proposals'] = $inferred['proposals'];
        }
        $capacity = [];
        $linked = $this->settlementEntries($supplierId, 'invoice', array_column($candidates, 'id'), $named, $capacity);
        $insert = $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare('UPDATE invoice_payments SET amount = ?, paid_on = ?, note = ? WHERE id = ? AND supplier_id = ?');
        foreach ($candidates as $c) {
            $room = round($c['paid_total'] - $c['others'], 2);
            $settled = 0.0;
            if ($c['in_range']) {
                foreach ($linked[$c['id']] ?? [] as $entry) {
                    $amount = $this->capped($c, $entry, $room - $settled, $capacity);
                    if ($amount < 0.01 || $entry['account_id'] === null) {
                        continue;
                    }
                    if (!$this->recordSettlement($supplierId, $userId, 'invoice', $c, $entry, $amount)) {
                        continue;
                    }
                    $settled += $amount;
                    $out['settlements']++;
                    $out['settlement_amount'] += $amount;
                }
            }
            $target = $c['in_range'] ? round($room - $settled, 2) : 0.0;
            if ($target >= 0.01 && isset($leftForMatching[$c['id']])) {
                $out['left_for_matching'][] = $c + ['amount' => $target];
                $target = 0.0;
            }
            $note = self::note($paymentNos[$c['id']] ?? null);
            $action = null;
            if ($c['own_id'] !== null) {
                if ($target < 0.01) {
                    $this->moveTaxDocumentLink($supplierId, $c['id']);
                    $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source = ? AND note LIKE ?')
                        ->execute([$c['id'], $supplierId, self::SOURCE, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%']);
                    $out['deleted']++;
                    $action = 'deleted';
                } else {
                    // Nejvýš jedna platba bez dokladu úhrady na doklad: případné další se slijí do první.
                    $this->moveTaxDocumentLink($supplierId, $c['id'], $c['own_id']);
                    $pdo->prepare('DELETE FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND source = ? AND note LIKE ? AND id <> ?')
                        ->execute([$c['id'], $supplierId, self::SOURCE, TakenOverPayment::MONEY_S3_WITHOUT_DOCUMENT_NOTE . '%', $c['own_id']]);
                    if (abs($target - $c['own_amount']) >= 0.005 || isset($paymentNos[$c['id']])) {
                        $update->execute([number_format($target, 2, '.', ''), (string) $c['paid_at'], isset($paymentNos[$c['id']]) ? $note : $this->currentNote($c['own_id']), $c['own_id'], $supplierId]);
                        if ($update->rowCount() > 0) {
                            $out['updated']++;
                            $action = 'updated';
                        }
                    }
                }
            } elseif ($target >= 0.01) {
                $insert->execute([$supplierId, $c['id'], (string) $c['paid_at'], number_format($target, 2, '.', ''), $c['currency'], $note, self::SOURCE, $userId]);
                $out['inserted']++;
                $action = 'inserted';
            }
            if ($action !== null || $settled >= 0.01) {
                $out['invoices'][] = $c + ['settled' => round($settled, 2), 'amount' => max(0.0, $target), 'action' => $action];
            }
        }
        $out['settlement_amount'] = round($out['settlement_amount'], 2);
        return $out;
    }

    /**
     * Vydané doklady se spárovaným pohybem banky, ke kterému chybí evidovaná platba (dřívější
     * běh převodu párování zapsal a platbu ne): úhrada je v převedené bance a doplní ji
     * párování (opakovaný převod), ne výplň.
     *
     * @param list<int> $invoiceIds
     * @return array<int,true>
     */
    public function pendingBankMatches(int $supplierId, array $invoiceIds): array
    {
        $out = [];
        foreach (array_chunk($invoiceIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $stmt = $this->db->pdo()->prepare(
                "SELECT DISTINCT pm.invoice_id FROM payment_matches pm
                  WHERE pm.supplier_id = ? AND pm.invoice_id IN ({$in})
                    AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = pm.invoice_id AND ip.bank_transaction_id = pm.bank_transaction_id)"
            );
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $out[(int) $id] = true;
            }
        }
        return $out;
    }

    /**
     * Vazba rušené výplně na DDKP přejde na platbu, která úhradu nese: přednostně na výplň,
     * která zůstává (`$keepId`), jinak na nejstarší platbu převodu dokladu bez vazby. Bez ní
     * by úplata zdaněná DDKP z Money ztratila odkaz na svůj DDKP.
     */
    private function moveTaxDocumentLink(int $supplierId, int $invoiceId, ?int $keepId = null): void
    {
        $pdo = $this->db->pdo();
        $links = $pdo->prepare('SELECT id, tax_document_invoice_id FROM invoice_payments
              WHERE invoice_id = ? AND supplier_id = ? AND ' . self::fillerSql('invoice_payments') . ' AND tax_document_invoice_id IS NOT NULL'
            . ($keepId !== null ? ' AND id <> ' . (int) $keepId : ''));
        $links->execute([$invoiceId, $supplierId]);
        foreach ($links->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $target = $keepId;
            if ($target === null) {
                $pick = $pdo->prepare("SELECT id FROM invoice_payments WHERE invoice_id = ? AND supplier_id = ? AND tax_document_invoice_id IS NULL
                      AND source IN ('bank', 'cash', 'credit_note', 'settlement') AND note LIKE ? AND NOT " . self::fillerSql('invoice_payments') . ' ORDER BY id LIMIT 1');
                $pick->execute([$invoiceId, $supplierId, IssuedPaymentRecorder::NOTE_PREFIX . '%']);
                $target = ($id = $pick->fetchColumn()) !== false ? (int) $id : null;
            }
            if ($target !== null) {
                // Vazba na DDKP je jedinečná: rušená výplň ji uvolní dřív, než ji převezme platba.
                $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = NULL WHERE id = ? AND supplier_id = ?')->execute([(int) $l['id'], $supplierId]);
                $pdo->prepare('UPDATE invoice_payments SET tax_document_invoice_id = ? WHERE id = ? AND supplier_id = ? AND tax_document_invoice_id IS NULL')
                    ->execute([(int) $l['tax_document_invoice_id'], $target, $supplierId]);
            }
        }
    }

    /** SQL podmínka „platba `$alias` je výplň bez dokladu úhrady" (alias `invoice_payments`). */
    public static function fillerSql(string $alias): string
    {
        return TakenOverPayment::moneyS3FillerSql($alias);
    }

    /**
     * Přijaté doklady převodu uhrazené podle Money do `$lastEnd`, které evidované úhrady
     * (banka, pokladna, zápočty) nepokrývají, nebo které už úhradu mimo evidenci mají (k přepočtu).
     * Money vede úhradu jen datem a celou: nekrytá je částka k úhradě minus evidované úhrady,
     * v měně dokladu.
     *
     * @return list<array{id:int,document_no:string,currency:string,rate:?float,paid_at:string,amount_to_pay:float,covered:float,outside:float}>
     */
    public function purchaseCandidates(int $supplierId, string $lastEnd, bool $forUpdate = false): array
    {
        $kinds = "'" . implode("','", self::PURCHASE_KINDS) . "'";
        $settled = PurchaseSettledExpr::settled('pi', currentRead: $forUpdate);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM (
                SELECT pi.id, COALESCE(NULLIF(pi.vendor_invoice_number, ''), NULLIF(pi.varsymbol, ''), CONCAT('#', pi.id)) AS document_no,
                       cur.code AS currency, pi.exchange_rate, pi.paid_at, pi.amount_to_pay, pi.settled_outside_amount AS outside,
                       ({$settled}) - pi.settled_outside_amount AS covered
                  FROM purchase_invoices pi
                  JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ? AND pi.status = 'paid' AND pi.document_kind IN ({$kinds})
                   AND pi.paid_at IS NOT NULL AND pi.paid_at <= ? AND pi.amount_to_pay > 0
                   AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = pi.supplier_id AND m.kind = ? AND m.target_id = pi.id)
             ) d
             WHERE d.amount_to_pay - d.covered - d.outside >= 0.01 OR d.outside >= 0.01
             ORDER BY d.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId, $lastEnd, MoneyS3ImportRepository::KIND_PURCHASE_INVOICE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'document_no' => (string) $r['document_no'], 'currency' => (string) $r['currency'],
                'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'paid_at' => substr((string) $r['paid_at'], 0, 10), 'amount_to_pay' => (float) $r['amount_to_pay'],
                'covered' => round(max(0.0, (float) $r['covered']), 2), 'outside' => (float) $r['outside'],
            ];
        }
        return $out;
    }

    /**
     * Úhrady přijatých dokladů uhrazených podle Money, které evidované úhrady nepokrývají:
     * zápočet interním dokladem navázaným na doklad (`invoice_settlements` se zápisem, jako
     * {@see PairingSymbolMatcher}), zbytek do `settled_outside_amount` (absolutně). Bez nich
     * párování banky bere doklad uhrazený zápočtem v Money jako nekrytý a spáruje s ním
     * platbu se stejným symbolem. Volat UVNITŘ transakce volajícího.
     *
     * @param array<int,string> $paymentNos id dokladu => číslo úhrady z Money (`UDoklad`)
     * @param bool $inferSettlements bez zálohy Money (opravné CLI), viz {@see recordIssued()}
     * @return array{updated:int,settlements:int,settlement_amount:float,amount:float,documents:list<array<string,mixed>>,proposals:list<array<string,mixed>>}
     */
    public function restorePurchaseSettlements(int $supplierId, ?int $userId, string $lastEnd, array $paymentNos = [], bool $inferSettlements = false): array
    {
        $out = ['updated' => 0, 'settlements' => 0, 'settlement_amount' => 0.0, 'amount' => 0.0, 'documents' => [], 'proposals' => []];
        $rows = $this->purchaseCandidates($supplierId, $lastEnd, true);
        if ($rows === []) {
            return $out;
        }
        $named = [];
        foreach ($rows as $r) {
            if (($paymentNos[$r['id']] ?? '') !== '') {
                $named[$r['id']] = ['no' => $paymentNos[$r['id']], 'date' => $r['paid_at']];
            }
        }
        if ($inferSettlements) {
            $inferred = $this->inferNamed($supplierId, 'purchase_invoice', array_map(static fn (array $r): array => $r + ['room' => round($r['amount_to_pay'] - $r['covered'], 2)],
                array_filter($rows, static fn (array $r): bool => !isset($named[$r['id']]))));
            $named += $inferred['named'];
            $out['proposals'] = $inferred['proposals'];
        }
        $capacity = [];
        $linked = $this->settlementEntries($supplierId, 'purchase_invoice', array_column($rows, 'id'), $named, $capacity);
        $update = $this->db->pdo()->prepare('UPDATE purchase_invoices SET settled_outside_amount = ? WHERE id = ? AND supplier_id = ?');
        foreach ($rows as $r) {
            $open = round($r['amount_to_pay'] - $r['covered'], 2);
            $settled = 0.0;
            foreach ($linked[$r['id']] ?? [] as $entry) {
                $amount = $this->capped($r, $entry, $open - $settled, $capacity);
                if ($amount < 0.01 || $entry['account_id'] === null) {
                    continue;
                }
                if (!$this->recordSettlement($supplierId, $userId, 'purchase_invoice', $r, $entry, $amount)) {
                    continue;
                }
                $settled += $amount;
                $out['settlements']++;
                $out['settlement_amount'] += $amount;
            }
            $target = round(max(0.0, $open - $settled), 2);
            if (abs($target - $r['outside']) >= 0.005) {
                $update->execute([number_format($target, 2, '.', ''), $r['id'], $supplierId]);
                $out['updated'] += $update->rowCount();
                $out['amount'] += $target - $r['outside'];
            } elseif ($settled < 0.01) {
                continue;
            }
            $out['documents'][] = $r + ['settled' => round($settled, 2), 'target' => $target];
        }
        $out['amount'] = round($out['amount'], 2);
        $out['settlement_amount'] = round($out['settlement_amount'], 2);
        return $out;
    }

    /**
     * Číslo úhrady dokladů, ke kterým ho opravné CLI nemá (doklady převedené před výplní, záloha
     * Money v CLI není): převzatý zápis interního dokladu ze dne úhrady, který na saldokontní
     * straně vyrovnává právě nekrytou částku dokladu v Kč, ještě není zápočtem a nemá vazbu na
     * doklad (zápis s vazbou bere {@see linkedSettlements()} u svého dokladu).
     *
     * Den a částka samy zápočet nepotvrdí: týž den může stejnou částkou jít třeba přeúčtování
     * pohledávky jiného odběratele. Převzatý deník Money párovací symbol ani IČO řádku nenese,
     * zápočtem se proto zápis stane, jen když
     *  - jeho popis nebo text řádku nese identifikátor dokladu (číslo dokladu, číslo dokladu
     *    dodavatele, variabilní symbol); z více zápisů dne s touto částkou platí ten jediný,
     *    který ho nese, nebo
     *  - je jediný takový zápis dne pro jediný doklad s touto částkou, vyrovnává saldokontní
     *    účet dokladu proti saldokontnímu účtu druhé strany zápočtu (311 proti 321, 324 proti
     *    325; průběžný účet jako 395 se v zápisu musí vynulovat, přeúčtování na něj zápočtem
     *    není) a protistrana dokladu má podle Money týž den uhrazený doklad opačného druhu
     *    (vzájemný zápočet s týmž partnerem).
     * Ostatní jednoznačné shody vrací jako návrh k ručnímu posouzení, doklad dostane výplň
     * jako dřív. Převod číslo úhrady zná ze zálohy.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param array<int|string,array<string,mixed>> $docs doklady s `id`, `document_no`, `paid_at`, `currency`, `rate`, `room`
     * @return array{named:array<int,array{no:string,date:string}>,proposals:list<array<string,mixed>>}
     */
    private function inferNamed(int $supplierId, string $docType, array $docs): array
    {
        $out = ['named' => [], 'proposals' => []];
        // Doklad, na který vede vazba zápisu zápočtu, ho má z vazby ({@see linkedSettlements()}).
        $linked = $this->linkedSettlements($supplierId, $docType, array_map(static fn (array $d): int => (int) $d['id'], array_values($docs)));
        $wanted = [];
        $byId = [];
        foreach ($docs as $d) {
            if (($linked[(int) $d['id']] ?? []) !== []) {
                continue;
            }
            $rate = $d['currency'] === 'CZK' ? 1.0 : ($d['rate'] ?? null);
            if (($d['paid_at'] ?? null) !== null && $rate !== null && $d['room'] >= 0.01) {
                $wanted[(int) $d['id']] = ['date' => (string) $d['paid_at'], 'czk' => round($d['room'] * $rate, 2)];
                $byId[(int) $d['id']] = $d;
            }
        }
        if ($wanted === []) {
            return $out;
        }
        $side = self::SETTLEMENT_ACCOUNTS[$docType]['side'];
        $receivable = implode(' OR ', array_map(static fn (string $p): string => "COALESCE(pa.account_code, ca.account_code) LIKE '{$p}%'", self::SETTLEMENT_ACCOUNTS[$docType]['prefixes']));
        $offsetType = $docType === 'invoice' ? 'purchase_invoice' : 'invoice';
        $offset = implode(' OR ', array_map(static fn (string $p): string => "COALESCE(pa.account_code, ca.account_code) LIKE '{$p}%'", self::SETTLEMENT_ACCOUNTS[$offsetType]['prefixes']));
        $byDay = [];
        foreach (array_chunk(array_values(array_unique(array_column($wanted, 'date'))), 500) as $dates) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT e.id, e.entry_date, e.document_no, e.description,
                        GROUP_CONCAT(DISTINCT l.line_text SEPARATOR '\n') AS line_texts,
                        SUM(CASE WHEN ({$receivable}) OR ({$offset}) THEN 0 WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS other_net,
                        SUM(CASE WHEN ({$offset}) THEN (CASE WHEN l.side = '{$side}' THEN -l.signed_amount ELSE l.signed_amount END) ELSE 0 END) AS offset_czk,
                        SUM(CASE WHEN ({$receivable}) THEN (CASE WHEN l.side = '{$side}' THEN l.signed_amount ELSE -l.signed_amount END) ELSE 0 END) AS czk
                   FROM journal_entries e
                   JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                   LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  WHERE e.supplier_id = ? AND e.entry_date IN (" . implode(',', array_fill(0, count($dates), '?')) . ")
                    AND e.source_type = 'manual' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.document_no IS NOT NULL
                    AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = ? AND m.target_id = e.id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_settlements s WHERE s.supplier_id = e.supplier_id AND s.journal_entry_id = e.id
                                       AND s.doc_type = ? AND s.status = 'confirmed')
                    AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links dl WHERE dl.supplier_id = e.supplier_id AND dl.entry_id = e.id)
                  GROUP BY e.id, e.entry_date, e.document_no, e.description
                 HAVING czk >= 0.01"
            );
            $stmt->execute([$supplierId, ...$dates, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $docType]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byDay[substr((string) $r['entry_date'], 0, 10)][] = $r;
            }
        }
        $docsPerKey = array_count_values(array_map(static fn (array $w): string => $w['date'] . '|' . number_format($w['czk'], 2, '.', ''), $wanted));
        $found = [];
        foreach ($wanted as $id => $w) {
            $same = array_values(array_filter($byDay[$w['date']] ?? [], static fn (array $r): bool => abs((float) $r['czk'] - $w['czk']) < 0.005));
            if ($same !== []) {
                $found[$id] = $same;
            }
        }
        if ($found === []) {
            return $out;
        }
        $identity = $this->documentIdentity($supplierId, $docType, array_keys($found));
        $proposal = static fn (int $id, array $r, string $reason): array => [
            'doc_type' => $docType, 'id' => $id, 'document_no' => (string) $byId[$id]['document_no'], 'currency' => (string) $byId[$id]['currency'],
            'entry_id' => (int) $r['id'], 'entry_no' => (string) $r['document_no'], 'date' => $wanted[$id]['date'], 'amount_czk' => $wanted[$id]['czk'],
            'reason' => $reason,
        ];
        $partnerCheck = [];
        foreach ($found as $id => $same) {
            $mentioning = array_values(array_filter($same, static fn (array $r): bool => self::mentionsAny(
                (string) $r['description'] . "\n" . (string) ($r['line_texts'] ?? ''), $identity[$id]['ids'] ?? [])));
            if (count($mentioning) === 1) {
                $out['named'][$id] = ['no' => (string) $mentioning[0]['document_no'], 'date' => $wanted[$id]['date']];
                continue;
            }
            // Bez identifikátoru jen jednoznačně: v den úhrady jediný doklad s touto částkou a jediný takový zápis.
            if (count($same) !== 1 || $docsPerKey[$wanted[$id]['date'] . '|' . number_format($wanted[$id]['czk'], 2, '.', '')] !== 1) {
                continue;
            }
            $r = $same[0];
            if (abs((float) $r['other_net']) >= 0.005 || (float) $r['offset_czk'] < 0.01) {
                $out['proposals'][] = $proposal($id, $r, 'zápis nenese číslo dokladu a protiúčet není saldokontní účet zápočtu');
            } elseif (($identity[$id]['partner'] ?? null) === null) {
                $out['proposals'][] = $proposal($id, $r, 'zápis nenese číslo dokladu a doklad nemá protistranu');
            } else {
                $partnerCheck[$id] = $r;
            }
        }
        if ($partnerCheck !== []) {
            $paid = $this->partnersPaidOn($supplierId, $offsetType,
                array_values(array_unique(array_map(static fn (int $id): string => $wanted[$id]['date'], array_keys($partnerCheck)))),
                array_values(array_unique(array_map(static fn (int $id): int => (int) $identity[$id]['partner'], array_keys($partnerCheck)))));
            foreach ($partnerCheck as $id => $r) {
                if (isset($paid[$wanted[$id]['date'] . '|' . $identity[$id]['partner']])) {
                    $out['named'][$id] = ['no' => (string) $r['document_no'], 'date' => $wanted[$id]['date']];
                } else {
                    $out['proposals'][] = $proposal($id, $r, 'zápis nenese číslo dokladu a protistrana nemá týž den uhrazený doklad opačného druhu');
                }
            }
        }
        return $out;
    }

    /**
     * Identifikátory dokladů, které může nést text zápisu (číslo dokladu, číslo dokladu
     * dodavatele, variabilní symbol), a protistrana (adresář; převod vede jednoho partnera
     * na IČO, {@see CodebookImporter::resolvePartner()}).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param list<int> $ids
     * @return array<int,array{ids:list<string>,partner:?int}>
     */
    private function documentIdentity(int $supplierId, string $docType, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $stmt = $this->db->pdo()->prepare($docType === 'invoice'
                ? "SELECT id, varsymbol, NULL AS vendor_no, payment_variable_symbol, client_id AS partner_id
                     FROM invoices WHERE supplier_id = ? AND id IN ({$in})"
                : "SELECT id, varsymbol, vendor_invoice_number AS vendor_no, payment_variable_symbol, vendor_id AS partner_id
                     FROM purchase_invoices WHERE supplier_id = ? AND id IN ({$in})");
            $stmt->execute([$supplierId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['id']] = [
                    'ids' => array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), [$r['varsymbol'], $r['vendor_no'], $r['payment_variable_symbol']]),
                        static fn (string $v): bool => mb_strlen($v) >= 5 && preg_match('/\d{4}/', $v) === 1))),
                    'partner' => $r['partner_id'] !== null ? (int) $r['partner_id'] : null,
                ];
            }
        }
        return $out;
    }

    /**
     * Nese text identifikátor jako samostatné slovo? Identifikátory kratší než 5 znaků nebo
     * bez čtyř číslic po sobě vyřadí už {@see documentIdentity()}, stejně jako
     * {@see PairingSymbolMatcher::popisRefs()} v převodu (částka či rok v popisu nejsou číslo
     * dokladu). Na rozdíl od něj se text nedělí na oddělovačích: číslo dokladu dodavatele
     * nese pomlčky a lomítka („DF-2025-640") a hledá se celé.
     *
     * @param list<string> $ids
     */
    private static function mentionsAny(string $text, array $ids): bool
    {
        foreach ($ids as $id) {
            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($id, '/') . '(?![\p{L}\p{N}])/iu', $text) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Partneři s dokladem převodu druhu `$docType`, který Money vede jako uhrazený v daný den.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param list<string> $dates
     * @param list<int> $partners
     * @return array<string,true> "den|id partnera"
     */
    private function partnersPaidOn(int $supplierId, string $docType, array $dates, array $partners): array
    {
        $out = [];
        $partnerIn = implode(',', array_map('intval', $partners));
        $dateIn = implode(',', array_fill(0, count($dates), '?'));
        if ($docType === 'invoice') {
            $types = "'" . implode("','", self::PAYABLE_TYPES) . "'";
            $sql = "SELECT d.paid_at, d.client_id AS partner_id FROM invoices d
                     WHERE d.supplier_id = ? AND d.client_id IN ({$partnerIn}) AND d.paid_at IN ({$dateIn})
                       AND d.invoice_type IN ({$types}) AND d.status NOT IN ('draft', 'cancelled')
                       AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = ? AND m.target_id = d.id)";
            $kind = MoneyS3ImportRepository::KIND_INVOICE;
        } else {
            $kinds = "'" . implode("','", self::PURCHASE_KINDS) . "'";
            $sql = "SELECT d.paid_at, d.vendor_id AS partner_id FROM purchase_invoices d
                     WHERE d.supplier_id = ? AND d.vendor_id IN ({$partnerIn}) AND d.paid_at IN ({$dateIn})
                       AND d.document_kind IN ({$kinds}) AND d.status = 'paid'
                       AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = ? AND m.target_id = d.id)";
            $kind = MoneyS3ImportRepository::KIND_PURCHASE_INVOICE;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, ...$dates, $kind]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[substr((string) $r['paid_at'], 0, 10) . '|' . (int) $r['partner_id']] = true;
        }
        return $out;
    }

    /** Číslo úhrady z poznámky platby bez dokladu úhrady ({@see note()}), pokud ho nese. */
    public static function paymentNoFromNote(?string $note): ?string
    {
        if ($note === null || preg_match('/\(([^()]+)\)$/u', $note, $m) !== 1) {
            return null;
        }
        return trim($m[1]);
    }

    /**
     * Zápisy, kterými Money doklad vyrovnalo: ruční zápis navázaný na doklad
     * ({@see linkedSettlements()}) a zápis interního dokladu, jehož číslo a datum Money
     * u dokladu vede jako úhradu (`UDoklad` a `Uhrazeno`; převod ho k dokladu nepřiřadil,
     * protože nese jiný párovací symbol). Zápis interního dokladu bývá zápočtem víc dokladů:
     * jeho kapacitu (vyrovnání v Kč na saldokontní straně minus už evidované zápočty téhož
     * druhu) hlídá `$capacity` napříč doklady.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param list<int> $docIds
     * @param array<int,array{no:string,date:string}> $named id dokladu => číslo a datum úhrady
     * @param array<int,float> $capacity id zápisu => zbývající vyrovnání v Kč (MĚNÍ SE)
     * @return array<int,list<array<string,mixed>>>
     */
    private function settlementEntries(int $supplierId, string $docType, array $docIds, array $named, array &$capacity): array
    {
        $out = $this->linkedSettlements($supplierId, $docType, $docIds);
        if ($named === []) {
            return $out;
        }
        $side = self::SETTLEMENT_ACCOUNTS[$docType]['side'];
        $receivable = implode(' OR ', array_map(static fn (string $p): string => "COALESCE(pa.account_code, ca.account_code) LIKE '{$p}%'", self::SETTLEMENT_ACCOUNTS[$docType]['prefixes']));
        $pdo = $this->db->pdo();
        foreach (array_chunk($named, 500, true) as $chunk) {
            $dates = array_values(array_unique(array_column($chunk, 'date')));
            $numbers = array_values(array_unique(array_column($chunk, 'no')));
            $stmt = $pdo->prepare(
                "SELECT e.id AS entry_id, e.entry_date, e.document_no,
                        SUM(CASE WHEN ({$receivable}) THEN (CASE WHEN l.side = '{$side}' THEN l.signed_amount ELSE -l.signed_amount END) ELSE 0 END) AS czk,
                        SUM(CASE WHEN ({$receivable}) THEN (CASE WHEN l.side = '{$side}' THEN COALESCE(l.signed_amount_foreign, 0) ELSE -COALESCE(l.signed_amount_foreign, 0) END) ELSE 0 END) AS foreign_amount,
                        MAX(CASE WHEN ({$receivable}) THEN l.currency_code END) AS currency_code,
                        (SELECT COALESCE(SUM(s.amount), 0) FROM invoice_settlements s
                          WHERE s.supplier_id = e.supplier_id AND s.journal_entry_id = e.id AND s.doc_type = ? AND s.status = 'confirmed') AS used
                   FROM journal_entries e
                   JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                   LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  WHERE e.supplier_id = ? AND e.entry_date IN (" . implode(',', array_fill(0, count($dates), '?')) . ")
                    AND e.document_no IN (" . implode(',', array_fill(0, count($numbers), '?')) . ")
                    AND e.source_type = 'manual' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                    AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = e.supplier_id AND m.kind = ? AND m.target_id = e.id)
                  GROUP BY e.id, e.entry_date, e.document_no
                 HAVING czk >= 0.01"
            );
            $stmt->execute([$docType, $supplierId, ...$dates, ...$numbers, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY]);
            $byKey = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byKey[substr((string) $r['entry_date'], 0, 10) . '|' . $r['document_no']][] = $r;
            }
            $entries = [];
            foreach ($chunk as $docId => $w) {
                $found = $byKey[$w['date'] . '|' . $w['no']] ?? [];
                if (count($found) !== 1) {
                    continue; // číslo dokladu Money se může opakovat; bez jednoznačného zápisu nic
                }
                $r = $found[0];
                $entryId = (int) $r['entry_id'];
                $capacity[$entryId] ??= round((float) $r['czk'] - (float) $r['used'], 2);
                if (in_array($entryId, array_column($out[$docId] ?? [], 'entry_id'), true)) {
                    continue;
                }
                $entries[$entryId] = true;
                $out[$docId][] = [
                    'entry_id' => $entryId, 'date' => substr((string) $r['entry_date'], 0, 10), 'document_no' => (string) $r['document_no'],
                    'czk' => round((float) $r['czk'], 2), 'foreign' => round((float) $r['foreign_amount'], 2),
                    'currency' => $r['currency_code'] !== null ? (string) $r['currency_code'] : null, 'account_id' => null, 'shared' => true,
                ];
            }
            if ($entries !== []) {
                $counter = $this->counterAccounts($supplierId, array_keys($entries), $receivable, $side === 'credit' ? 'debit' : 'credit');
                foreach ($out as $docId => $list) {
                    foreach ($list as $i => $e) {
                        if (($e['shared'] ?? false) && $e['account_id'] === null) {
                            $out[$docId][$i]['account_id'] = $counter[$e['entry_id']] ?? null;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Částka zápočtu v měně dokladu: vyrovnání zápisu, nejvýš do `$room` a u zápisu
     * sdíleného víc doklady do jeho zbývající kapacity v Kč (`$capacity` se sníží).
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $entry
     * @param array<int,float> $capacity
     */
    private function capped(array $doc, array $entry, float $room, array &$capacity): float
    {
        $amount = round(min($this->inDocumentCurrency($doc, $entry), $room), 2);
        if (!($entry['shared'] ?? false) || $amount < 0.01) {
            return $amount;
        }
        $rate = $doc['currency'] === 'CZK' ? 1.0 : ($doc['rate'] ?? null);
        $left = $capacity[$entry['entry_id']] ?? 0.0;
        if ($rate === null || $left < 0.01) {
            return 0.0;
        }
        $amount = round(min($amount, $left / $rate), 2);
        $capacity[$entry['entry_id']] = round($left - $amount * $rate, 2);
        return $amount;
    }

    /**
     * Ruční zápisy navázané na doklady, které saldo dokladu snižují a saldokonto je dnes čte
     * jen přes vazbu ({@see \MyInvoice\Support\Sql\LinkedManualSettlementSql}): zápis navázaný
     * právě na jeden doklad téhož druhu, bez zápočtu se zápisem, bez rozpadu a mimo skupinu
     * zaúčtování dokladu. Po dokladech v pořadí data, s protiúčtem (účet druhé strany s největší
     * částkou) a vyrovnáním v Kč a v cizí měně.
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param list<int> $docIds
     * @return array<int,list<array{entry_id:int,date:string,document_no:string,czk:float,foreign:float,currency:?string,account_id:?int}>>
     */
    private function linkedSettlements(int $supplierId, string $docType, array $docIds): array
    {
        if ($docIds === []) {
            return [];
        }
        $side = self::SETTLEMENT_ACCOUNTS[$docType]['side'];
        $receivable = implode(' OR ', array_map(static fn (string $p): string => "COALESCE(pa.account_code, ca.account_code) LIKE '{$p}%'", self::SETTLEMENT_ACCOUNTS[$docType]['prefixes']));
        $out = [];
        foreach (array_chunk($docIds, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $stmt = $this->db->pdo()->prepare(
                "SELECT dl.doc_id, e.id AS entry_id, e.entry_date, COALESCE(e.document_no, '') AS document_no,
                        SUM(CASE WHEN ({$receivable}) THEN (CASE WHEN l.side = '{$side}' THEN l.signed_amount ELSE -l.signed_amount END) ELSE 0 END) AS czk,
                        SUM(CASE WHEN ({$receivable}) THEN (CASE WHEN l.side = '{$side}' THEN COALESCE(l.signed_amount_foreign, 0) ELSE -COALESCE(l.signed_amount_foreign, 0) END) ELSE 0 END) AS foreign_amount,
                        MAX(CASE WHEN ({$receivable}) THEN l.currency_code END) AS currency_code
                   FROM journal_entry_document_links dl
                   JOIN journal_entries e ON e.id = dl.entry_id AND e.supplier_id = dl.supplier_id
                   JOIN journal_entry_lines l ON l.entry_id = e.id AND l.supplier_id = e.supplier_id
                   JOIN chart_of_accounts ca ON ca.id = l.account_id
                   LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
                  WHERE dl.supplier_id = ? AND dl.doc_type = ? AND dl.doc_id IN ({$in})
                    AND e.source_type = 'manual' AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                    AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links other
                                     WHERE other.supplier_id = dl.supplier_id AND other.entry_id = dl.entry_id
                                       AND other.doc_type = dl.doc_type AND other.doc_id <> dl.doc_id)
                    AND NOT EXISTS (SELECT 1 FROM invoice_settlements s
                                     WHERE s.supplier_id = dl.supplier_id AND s.journal_entry_id = dl.entry_id
                                       AND s.doc_type = dl.doc_type AND s.doc_id = dl.doc_id AND s.status = 'confirmed')
                    AND " . JournalSettlementAllocationSql::noAllocationSql('dl.supplier_id', 'dl.entry_id', 'dl.doc_type') . "
                    AND NOT EXISTS (SELECT 1 FROM journal_document_postings own
                                     WHERE own.supplier_id = dl.supplier_id AND own.entry_id = dl.entry_id
                                       AND own.doc_type = dl.doc_type AND own.doc_id = dl.doc_id)
                  GROUP BY dl.doc_id, e.id, e.entry_date, e.document_no
                 HAVING czk >= 0.01
                  ORDER BY dl.doc_id, e.entry_date, e.id"
            );
            $stmt->execute([$supplierId, $docType]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                continue;
            }
            $counter = $this->counterAccounts($supplierId, array_map(static fn (array $r): int => (int) $r['entry_id'], $rows), $receivable, $side === 'credit' ? 'debit' : 'credit');
            foreach ($rows as $r) {
                $out[(int) $r['doc_id']][] = [
                    'entry_id' => (int) $r['entry_id'], 'date' => substr((string) $r['entry_date'], 0, 10),
                    'document_no' => (string) $r['document_no'], 'czk' => round((float) $r['czk'], 2),
                    'foreign' => round((float) $r['foreign_amount'], 2), 'currency' => $r['currency_code'] !== null ? (string) $r['currency_code'] : null,
                    'account_id' => $counter[(int) $r['entry_id']] ?? null,
                ];
            }
        }
        return $out;
    }

    /**
     * Protiúčet zápočtu: účet na druhé straně zápisu mimo saldokontní účty (kam zápis
     * interního dokladu doklad vyrovnal), s největší částkou.
     *
     * @param list<int> $entryIds
     * @param 'debit'|'credit' $side
     * @return array<int,int>
     */
    private function counterAccounts(int $supplierId, array $entryIds, string $receivable, string $side): array
    {
        $in = implode(',', array_map('intval', array_unique($entryIds)));
        $stmt = $this->db->pdo()->prepare(
            "SELECT l.entry_id, l.account_id, SUM(l.signed_amount) AS amount
               FROM journal_entry_lines l
               JOIN chart_of_accounts ca ON ca.id = l.account_id
               LEFT JOIN chart_of_accounts pa ON pa.id = ca.parent_id
              WHERE l.supplier_id = ? AND l.entry_id IN ({$in}) AND l.side = ? AND NOT ({$receivable})
              GROUP BY l.entry_id, l.account_id
              ORDER BY l.entry_id, amount DESC, l.account_id"
        );
        $stmt->execute([$supplierId, $side]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['entry_id']] ??= (int) $r['account_id'];
        }
        return $out;
    }

    /**
     * Vyrovnání zápisu v měně dokladu: korunový doklad korunami, doklad v cizí měně částkou
     * řádku v jeho měně, jinak korunami kurzem dokladu.
     *
     * @param array<string,mixed> $c
     * @param array<string,mixed> $entry
     */
    private function inDocumentCurrency(array $c, array $entry): float
    {
        if ($c['currency'] === 'CZK') {
            return $entry['czk'];
        }
        if ($entry['currency'] === $c['currency'] && $entry['foreign'] >= 0.01) {
            return $entry['foreign'];
        }
        return $c['rate'] !== null ? round($entry['czk'] / $c['rate'], 2) : 0.0;
    }

    /**
     * Zápočet interním dokladem jako {@see PairingSymbolMatcher}: `invoice_settlements`
     * s vazbou na zápis, vydaný doklad i s evidovanou platbou `settlement` (saldokonto vydaných
     * dokladů čte jen ji).
     *
     * @param 'invoice'|'purchase_invoice' $docType
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $entry
     */
    private function recordSettlement(int $supplierId, ?int $userId, string $docType, array $doc, array $entry, float $amount): bool
    {
        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT 1 FROM invoice_settlements WHERE supplier_id = ? AND doc_type = ? AND doc_id = ? AND journal_entry_id = ?');
        $exists->execute([$supplierId, $docType, $doc['id'], $entry['entry_id']]);
        if ($exists->fetchColumn() !== false) {
            return false;
        }
        $docNo = $entry['document_no'] !== '' ? $entry['document_no'] : '#' . $entry['entry_id'];
        $paymentId = null;
        if ($docType === 'invoice') {
            $pdo->prepare('INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$supplierId, $doc['id'], $entry['date'], number_format($amount, 2, '.', ''), $doc['currency'],
                    IssuedPaymentRecorder::note('settlement', $docNo), self::SOURCE, $userId]);
            $paymentId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            "INSERT INTO invoice_settlements (supplier_id, doc_type, doc_id, settled_on, amount, account_id, note, status, journal_entry_id, invoice_payment_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?)"
        )->execute([$supplierId, $docType, $doc['id'], $entry['date'], number_format($amount, 2, '.', ''), $entry['account_id'],
            'Převzato z Money S3, interní doklad ' . $docNo, $entry['entry_id'], $paymentId, $userId]);
        return true;
    }

    private function currentNote(int $paymentId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT note FROM invoice_payments WHERE id = ?');
        $stmt->execute([$paymentId]);
        return (string) $stmt->fetchColumn();
    }
}
