<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Bank\Match\MatchScorer;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Migration\Shared\BankAccountRegistrar;
use MyInvoice\Service\Migration\Shared\ForeignCurrencyTakeover;
use MyInvoice\Support\Sql\PayablePredicate;
use PDO;

/**
 * Pohyby, které POHODA nezaúčtovala (předkontace „Nevím", v deníku zápis nemají).
 *
 * Typicky jsou to úhrady faktur, které účetní v POHODĚ ještě nezlikvidovala. Převod je
 * spáruje s fakturou jen tam, kde je shoda jednoznačná, a rovnou zaúčtuje úhradu
 * (321/221, 221/311) jako bankovní zápis pohybu - stejnou cestou jako spárovanou platbu
 * v MyÚčtu ({@see BankPostingService}). Pořadí síly důkazu:
 *   1. vazba, kterou eviduje POHODA (úhrada dokladu tímto pohybem - páruje ji
 *      {@see DocumentLinker::matchPayments()}, tady se jen zaúčtuje),
 *   2. párovací symbol pohybu nebo jeho položky = číslo či VS otevřené faktury + částka,
 *   3. variabilní symbol pohybu = VS otevřené faktury + částka,
 *   4. účet protistrany = účet na přijaté faktuře + částka + datum v okně splatnosti.
 * Vždy příjem proti vydané, výdej proti přijaté faktuře, a jen oboustranně jedinečná
 * shoda (jedna faktura pro pohyb a jeden pohyb pro fakturu). Nejistá shoda je jen návrh
 * párování (`bank_match_suggestions`), platby kartou a zbytek zůstávají na Doúčtování.
 *
 * Co převod takhle založil, eviduje mapa převodu ({@see PohodaImportRepository::KIND_DERIVED_MATCH},
 * {@see PohodaImportRepository::KIND_DERIVED_ENTRY}): opakovaný převod nic nezdvojí,
 * zápisy nejsou „cizí" pro kontrolu před převodem, a když POHODA pohyb v novějším exportu
 * zaúčtuje sama, odvozený zápis se stornuje a platí zápis z deníku POHODY ({@see supersede()}).
 */
final class UnbookedBankPayments
{
    private const STEP = DocumentLinker::STEP_PAYMENTS;
    private const TOLERANCE = 0.005;
    /** Okno data platby u shody podle účtu protistrany: od vystavení - 30 dní do splatnosti + 120 dní. */
    private const DAYS_BEFORE_ISSUE = 30;
    private const DAYS_AFTER_DUE = 120;
    /** Kratší symbol (1, 12) je šum, ne odkaz na doklad. */
    private const MIN_SYMBOL_DIGITS = 3;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly DocumentLinker $linker,
        private readonly BankPostingService $bankPosting,
        private readonly PostingService $posting,
        private readonly JournalEntryRepository $journal,
        private readonly BankPostingSuggestionRepository $suggestions,
    ) {}

    /**
     * Pohyb, který převod dřív zaúčtoval odvozenou úhradou a který POHODA mezitím zaúčtovala
     * sama: odvozený zápis se stornuje (ke dni původního zápisu) a odpojí od pohybu, aby na
     * pohyb navázal zápis z deníku POHODY ({@see DocumentLinker::link()}). Spárování s fakturou
     * zůstává - potvrdí ho úhrada v POHODĚ ({@see DocumentLinker::matchPayments()} ho najde
     * jako už spárované).
     */
    public function supersede(PohodaContext $ctx): void
    {
        $derived = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_ENTRY);
        if ($derived === []) {
            return;
        }
        $booked = array_fill_keys($this->linker->bankBooking($ctx)['booked'], true);
        $reversed = [];
        foreach (array_keys($derived) as $key) {
            $parts = explode('|', (string) $key);
            if ($parts[0] === 'reversal' && isset($parts[2])) {
                $reversed[(int) $parts[2]] = true;
            }
        }
        $pdo = $this->db->pdo();
        $load = $pdo->prepare('SELECT entry_date, reversed_by FROM journal_entries WHERE id = ? AND supplier_id = ?');
        foreach ($derived as $key => $entryId) {
            $parts = explode('|', (string) $key);
            $txId = (int) ($parts[1] ?? 0);
            if ($parts[0] !== 'entry' || !isset($booked[$txId]) || isset($reversed[$entryId])) {
                continue;
            }
            $load->execute([$entryId, $ctx->supplierId]);
            $entry = $load->fetch(PDO::FETCH_ASSOC);
            if ($entry === false || $entry['reversed_by'] !== null) {
                continue;
            }
            $pdo->exec('SAVEPOINT pohoda_supersede');
            try {
                $reversalId = $this->posting->reverse($ctx->supplierId, (int) $entryId, [
                    'entry_date' => (string) $entry['entry_date'],
                    'description' => 'Storno odvozené úhrady - pohyb zaúčtovala POHODA (převod z POHODY)',
                    'user_id' => $ctx->userOrNull(),
                    'posted_by' => $ctx->userOrNull(),
                ]);
                $this->journal->detachSource((int) $entryId, $ctx->supplierId);
                $this->suggestions->supersedeMatchedForTx($ctx->supplierId, $txId, 'pohoda_booked');
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_ENTRY, 'reversal|' . $txId . '|' . $entryId, $reversalId, $ctx->runId);
                $pdo->exec('RELEASE SAVEPOINT pohoda_supersede');
                $ctx->protocol->count(DocumentLinker::STEP_LINK, 'superseded');
            } catch (PostingException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_supersede');
                $pdo->exec('RELEASE SAVEPOINT pohoda_supersede');
                $ctx->protocol->warn(DocumentLinker::STEP_LINK, 'superseded_failed', sprintf(
                    'Pohyb #%d má odvozený zápis úhrady a POHODA ho mezitím zaúčtovala sama. Odvozený zápis #%d nešlo stornovat (%s) - stornujte ho ručně, jinak je úhrada v deníku dvakrát.',
                    $txId, (int) $entryId, $e->getMessage(),
                ), ['transaction_id' => $txId, 'entry_id' => (int) $entryId]);
            }
        }
    }

    /**
     * Páruje a účtuje úhrady pohybů bez zápisu v deníku POHODY. Běží po párování úhrad
     * z POHODY ({@see DocumentLinker::matchPayments()}), v témže kroku.
     */
    public function settle(PohodaContext $ctx): void
    {
        $unbooked = $this->linker->bankBooking($ctx)['unbooked'];
        if ($unbooked === []) {
            return;
        }
        $txs = $this->transactions($ctx->supplierId, $unbooked);
        $derivedMatches = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_MATCH);
        $everPosted = [];
        foreach (array_keys($this->map->all($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_ENTRY)) as $key) {
            $everPosted[(int) (explode('|', (string) $key)[1] ?? 0)] = true;
        }

        $docs = $this->openDocuments($ctx);
        $proposals = [];
        $uncertain = [];
        foreach ($txs as $tx) {
            if ($tx['matched'] || $tx['match_status'] !== 'unmatched' || isset($derivedMatches['tx|' . $tx['id']])) {
                continue;
            }
            if ($tx['card']) {
                $ctx->protocol->count(self::STEP, 'unbooked_card');
                continue;
            }
            $found = $this->candidatesFor($ctx, $tx, $docs);
            if ($found === null) {
                continue;
            }
            if ($found['exact'] !== null && count($found['exact']) === 1) {
                $proposals[$tx['id']] = ['doc' => $found['exact'][0], 'via' => $found['via']];
            } else {
                $uncertain[$tx['id']] = $found;
            }
        }

        // Jedna faktura pro víc pohybů: shoda není jednoznačná ani pro jeden z nich.
        $claims = [];
        foreach ($proposals as $txId => $prop) {
            $claims[$prop['doc']['key']][] = $txId;
        }
        foreach ($claims as $txIds) {
            if (count($txIds) < 2) {
                continue;
            }
            foreach ($txIds as $txId) {
                $prop = $proposals[$txId];
                $uncertain[$txId] = ['via' => $prop['via'], 'exact' => [$prop['doc']], 'near' => [], 'reason' => 'shared'];
                unset($proposals[$txId]);
            }
        }

        $matched = ['parsym' => 0, 'vs' => 0, 'account' => 0];
        foreach ($proposals as $txId => $prop) {
            $doc = $prop['doc'];
            if (!$doc['clean']) {
                // Doklad částečně uhrazený v POHODĚ bez evidovaných plateb v MyÚčtu -
                // úhrada by rozbila jeho uhrazenou částku, rozhodne člověk.
                $uncertain[$txId] = ['via' => $prop['via'], 'exact' => [$doc], 'near' => [], 'reason' => 'partial'];
                continue;
            }
            $this->pair($ctx, $txs[$txId], $doc, $prop['via']);
            $matched[$prop['via']]++;
            $ctx->protocol->count(self::STEP, 'unbooked_matched_' . $prop['via']);
        }
        $suggested = 0;
        foreach ($uncertain as $txId => $found) {
            if ($this->suggest($ctx, $txs[$txId], $found)) {
                $suggested++;
                $ctx->protocol->count(self::STEP, 'unbooked_suggested');
            }
        }

        $posted = $this->post($ctx, $unbooked, $everPosted);
        if (array_sum($matched) + $suggested + $posted > 0) {
            $ctx->protocol->info(self::STEP, 'unbooked_settled', sprintf(
                'Pohyby bez zápisu v deníku POHODY: %d spárováno s fakturou (párovací symbol %d, variabilní symbol %d, účet protistrany %d), %d úhrad zaúčtováno, %d nejistých shod čeká jako návrh párování u výpisu.',
                array_sum($matched), $matched['parsym'], $matched['vs'], $matched['account'], $posted, $suggested,
            ), ['matched' => $matched, 'posted' => $posted, 'suggested' => $suggested]);
        }
    }

    /**
     * Zaúčtuje spárované pohyby bez zápisu v deníku POHODY přes {@see BankPostingService}
     * (týž engine jako spárovaná platba v MyÚčtu, aktivační režim - automatika firmy je
     * během převodu vypnutá a tady rozhoduje jistota vazby, ne politika automatiky).
     * Účtuje se jen párování, které založil převod (úhrada z POHODY nebo odvozená shoda);
     * párování uživatele ani pohyb, který převod už jednou zaúčtoval (a uživatel zápis
     * mezitím stornoval), převod neúčtuje.
     *
     * @param list<int> $unbooked
     * @param array<int,true> $everPosted
     * @return int počet zaúčtovaných
     */
    private function post(PohodaContext $ctx, array $unbooked, array $everPosted): int
    {
        $txs = $this->transactions($ctx->supplierId, $unbooked);
        $fromImport = array_fill_keys(array_merge(
            $this->map->targets($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT),
            $this->map->targets($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_MATCH),
        ), true);
        $matchesOf = $this->db->pdo()->prepare('SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?');
        $notPosted = [];
        $posted = 0;
        foreach ($txs as $tx) {
            if (!$tx['matched'] || $tx['live_entry'] || isset($everPosted[$tx['id']]) || $tx['match_status'] === 'ignored') {
                continue;
            }
            $matchesOf->execute([$ctx->supplierId, $tx['id']]);
            $ids = array_map('intval', $matchesOf->fetchAll(PDO::FETCH_COLUMN));
            if ($ids === [] || array_diff_key(array_fill_keys($ids, true), $fromImport) !== []) {
                continue;
            }
            $this->evidenceIssuedPayment($ctx, $tx);
            $res = $this->bankPosting->handleTransaction($tx['id'], $ctx->userOrNull(), true, $ctx->supplierId);
            if ($res['action'] === 'posted' && isset($res['entry_id'])) {
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_ENTRY, 'entry|' . $tx['id'] . '|' . $res['entry_id'], (int) $res['entry_id'], $ctx->runId);
                $ctx->protocol->count(self::STEP, 'unbooked_posted');
                $posted++;
                continue;
            }
            $reason = (string) ($res['reason'] ?? $res['action']);
            $ctx->protocol->count(self::STEP, 'unbooked_not_posted');
            $notPosted[$reason][] = $tx['id'];
        }
        foreach ($notPosted as $reason => $ids) {
            $ctx->protocol->info(self::STEP, 'unbooked_not_posted', sprintf(
                '%d spárovaných pohybů bez zápisu v deníku POHODY se nezaúčtovalo (%s). Zaúčtujte je v Účetnictví → Doúčtovat doklady nebo ručně u výpisu.',
                count($ids), $reason,
            ), ['reason' => $reason, 'transactions' => array_slice($ids, 0, 50)]);
        }
        return $posted;
    }

    /**
     * Spáruje pohyb s fakturou: záznam v `payment_matches` (jako párování úhrad z POHODY),
     * stav pohybu a zaplacenost faktury - přijatá je uhrazená při plné úhradě, vydaná
     * dostane evidovanou platbu (viz {@see evidenceIssuedPayment()}).
     *
     * @param array<string,mixed> $tx
     * @param array<string,mixed> $doc
     */
    private function pair(PohodaContext $ctx, array $tx, array $doc, string $via): bool
    {
        $pdo = $this->db->pdo();
        $isIssued = $doc['type'] === 'invoice';
        $amount = number_format(abs($tx['amount']), 2, '.', '');
        $pdo->exec('SAVEPOINT pohoda_pair');
        try {
            $pdo->prepare(
                "INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, match_confidence, matched_by_user_id)
                 VALUES (?, ?, ?, ?, ?, 'auto', ?, NULL)"
            )->execute([$ctx->supplierId, $tx['id'], $isIssued ? $doc['id'] : null, $isIssued ? null : $doc['id'], $amount, $via === 'account' ? 90 : 100]);
            $matchId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.match_status = 'auto_exact', t.matched_at = NOW(), t.matched_by = NULL, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
                  WHERE t.id = ? AND s.supplier_id = ? AND t.match_status = 'unmatched'"
            )->execute([$isIssued ? $doc['id'] : null, $tx['id'], $ctx->supplierId]);
            if (!$isIssued) {
                $pdo->prepare(
                    "UPDATE purchase_invoices SET status = 'paid', paid_at = ?
                      WHERE id = ? AND supplier_id = ? AND status = 'booked'" . PayablePredicate::excludeAdvanceVatDocument('')
                )->execute([$tx['date'], $doc['id'], $ctx->supplierId]);
            }
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_DERIVED_MATCH, 'tx|' . $tx['id'], $matchId, $ctx->runId);
            $pdo->exec('RELEASE SAVEPOINT pohoda_pair');
            return true;
        } catch (\PDOException $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_pair');
            $pdo->exec('RELEASE SAVEPOINT pohoda_pair');
            throw $e;
        }
    }

    /**
     * Úhrada vydané faktury pohybem potřebuje evidovanou platbu (`invoice_payments`) - bez ní
     * ji bankovní engine nezaúčtuje (uhrazená faktura bez platby jde k ověření). Převod
     * uhrazenou částku vydaných faktur vede sám podle POHODY, proto se platba zapíše jen
     * tam, kde s ní souhlasí: faktura bez jiných plateb, které tenhle pohyb hradí celou
     * uhrazenou částkou (už uhrazená podle POHODY), nebo celou částku k úhradě (odvozená
     * shoda). Součet plateb tak zůstává rovný uhrazené částce faktury.
     *
     * `InvoicePaymentService::recordPayment()` se tu nevolá: archivuje PDF faktury na disku,
     * a to by zkouška nanečisto (transakce s rollbackem) po sobě nechala.
     *
     * @param array<string,mixed> $tx
     */
    private function evidenceIssuedPayment(PohodaContext $ctx, array $tx): void
    {
        if ($tx['amount'] <= 0) {
            return;
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT i.id, i.status, i.amount_to_pay, i.import_amount_to_pay_czk, i.paid_total, i.exchange_rate, cur.code AS currency
               FROM payment_matches pm
               JOIN invoices i ON i.id = pm.invoice_id AND i.supplier_id = pm.supplier_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE pm.supplier_id = ? AND pm.bank_transaction_id = ? AND i.invoice_type = 'invoice'
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.invoice_id = i.id)"
        );
        $stmt->execute([$ctx->supplierId, $tx['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return;
        }
        $invoice = $rows[0];
        $amount = round(abs($tx['amount']), 2);
        // Pohyb je v Kč, evidovaná platba se zapisuje v měně faktury.
        $rate = $invoice['exchange_rate'] !== null ? (float) $invoice['exchange_rate'] : null;
        $paid = round((float) $invoice['paid_total'], 2);
        $due = round((float) $invoice['amount_to_pay'], 2);
        $alreadyPaid = (string) $invoice['status'] === 'paid'
            && abs(IssuedCzkAmounts::allocated($invoice, $paid, $rate ?? 1.0) - $amount) < self::TOLERANCE;
        $opening = in_array((string) $invoice['status'], ['issued', 'sent', 'reminded'], true)
            && abs($paid) < self::TOLERANCE && abs(IssuedCzkAmounts::due($invoice, $rate) - $amount) < self::TOLERANCE;
        if (!$alreadyPaid && !$opening) {
            return;
        }
        $recorded = $rate === null ? $amount : ($opening ? $due : $paid);
        $pdo->prepare(
            "INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, variable_symbol, bank_reference, note, source, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Převzato z POHODY', 'bank', ?, ?)"
        )->execute([
            $ctx->supplierId, (int) $invoice['id'], $tx['date'], number_format($recorded, 2, '.', ''), (string) $invoice['currency'],
            $tx['vs'], $tx['bank_ref'], $tx['id'], $ctx->userOrNull(),
        ]);
        if ($opening) {
            $pdo->prepare(
                "UPDATE invoices SET paid_total = ?, paid_at = ?, status = 'paid'
                  WHERE id = ? AND supplier_id = ? AND status IN ('issued', 'sent', 'reminded')"
            )->execute([number_format($recorded, 2, '.', ''), $tx['date'], (int) $invoice['id'], $ctx->supplierId]);
        }
    }

    /**
     * Návrh párování (fronta návrhů u výpisu) - stejný tvar kandidátů jako automatické
     * párování, uživatel ho přijme nebo odmítne tam. Nepřepisuje návrh, který už čeká.
     *
     * @param array<string,mixed> $tx
     * @param array{via:string,exact:?list<array<string,mixed>>,near:list<array<string,mixed>>,reason?:string} $found
     */
    private function suggest(PohodaContext $ctx, array $tx, array $found): bool
    {
        $docs = $found['exact'] ?? [];
        $exact = $docs !== [];
        if (!$exact) {
            $docs = $found['near'];
        }
        if ($docs === []) {
            return false;
        }
        $purchase = $tx['amount'] < 0;
        $reason = match (true) {
            !$exact || ($found['reason'] ?? '') === 'partial' => $purchase ? 'amount_mismatch_purchase' : 'amount_mismatch',
            $found['via'] === 'account' => 'ambiguous_amount_date_match',
            default => $purchase ? 'ambiguous_vs_purchase' : 'ambiguous_vs',
        };
        $candidates = [];
        foreach (array_slice($docs, 0, 10) as $doc) {
            $signals = [];
            if ($found['via'] === 'account') {
                $signals['known_account'] = MatchScorer::W_KNOWN_ACCOUNT;
            } else {
                $signals['vs_exact'] = MatchScorer::W_VS_EXACT;
            }
            if (abs($doc['remaining'] - abs($tx['amount'])) < self::TOLERANCE) {
                $signals['amount_remaining'] = MatchScorer::W_AMOUNT_REMAINING;
            }
            $candidates[] = [
                'type' => $doc['type'],
                'invoice_id' => $doc['type'] === 'invoice' ? $doc['id'] : null,
                'invoice_ids' => null,
                'purchase_invoice_id' => $doc['type'] === 'invoice' ? null : $doc['id'],
                'signals' => $signals,
                'flags' => [],
                'fee_amount' => null,
                'overpayment_amount' => null,
                'display' => [
                    'ref' => $doc['number'] !== '' ? $doc['number'] : null,
                    'party' => $doc['party'],
                    'amount' => $doc['remaining'],
                    'currency' => $doc['currency'],
                    'due_date' => $doc['due'],
                    'paid' => false,
                ],
                'score' => round(array_sum($signals), 3),
                'deterministic_core' => false,
            ];
        }
        // Návrh, který uživatel už vyřídil (odmítl), opakovaný převod znovu nenabízí.
        $stmt = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO bank_match_suggestions
                (supplier_id, bank_transaction_id, kind, reason, candidates_json, top_score, margin, deterministic_core, status)
             SELECT ?, ?, 'single', ?, ?, ?, ?, 0, 'pending' FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM bank_match_suggestions WHERE bank_transaction_id = ?)"
        );
        $stmt->execute([
            $ctx->supplierId, $tx['id'], $reason,
            json_encode($candidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $candidates[0]['score'],
            count($candidates) > 1 ? round($candidates[0]['score'] - $candidates[1]['score'], 3) : null,
            $tx['id'],
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kandidáti pohybu podle síly důkazu - rozhoduje první úroveň, která něco najde.
     * `exact` = doklady se zbývající částkou rovnou pohybu, `near` = doklady podle symbolu
     * s jinou částkou (jen k návrhu).
     *
     * @param array<string,mixed> $tx
     * @param array<string,array<string,mixed>> $docs
     * @return array{via:string,exact:?list<array<string,mixed>>,near:list<array<string,mixed>>}|null
     */
    private function candidatesFor(PohodaContext $ctx, array $tx, array $docs): ?array
    {
        $type = $tx['amount'] < 0 ? 'purchase_invoice' : 'invoice';
        $amount = round(abs($tx['amount']), 2);
        $near = [];
        $levels = [
            'parsym' => $this->bySymbols($ctx->bankSymbols[$tx['id']] ?? [], $type, $docs),
            'vs' => $this->bySymbols($tx['vs'] !== null ? [$tx['vs']] : [], $type, $docs, false),
            'account' => $type === 'purchase_invoice' ? $this->byAccount($tx, $docs) : [],
        ];
        foreach ($levels as $via => $found) {
            if ($found === []) {
                continue;
            }
            $exact = array_values(array_filter($found, static fn (array $d): bool => abs($d['remaining'] - $amount) < self::TOLERANCE));
            if ($exact !== []) {
                return ['via' => $via, 'exact' => $exact, 'near' => []];
            }
            if ($via !== 'account' && $near === []) {
                $near = ['via' => $via, 'docs' => $found];
            }
        }
        return $near === [] ? null : ['via' => $near['via'], 'exact' => null, 'near' => array_values($near['docs'])];
    }

    /**
     * @param list<string> $symbols
     * @param array<string,array<string,mixed>> $docs
     * @return array<string,array<string,mixed>>
     */
    private function bySymbols(array $symbols, string $type, array $docs, bool $byNumber = true): array
    {
        $out = [];
        foreach ($symbols as $symbol) {
            $digits = self::digits($symbol);
            foreach ($docs as $key => $doc) {
                if ($doc['type'] !== $type) {
                    continue;
                }
                if (($byNumber && $doc['number'] === trim($symbol))
                    || ($digits !== '' && in_array($digits, $doc['symbols'], true))) {
                    $out[$key] = $doc;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $tx
     * @param array<string,array<string,mixed>> $docs
     * @return array<string,array<string,mixed>>
     */
    private function byAccount(array $tx, array $docs): array
    {
        if ($tx['account'] === null) {
            return [];
        }
        $out = [];
        foreach ($docs as $key => $doc) {
            if ($doc['type'] !== 'purchase_invoice' || $doc['account'] !== $tx['account']) {
                continue;
            }
            $from = date('Y-m-d', strtotime($doc['issue'] . ' -' . self::DAYS_BEFORE_ISSUE . ' days'));
            $to = date('Y-m-d', strtotime(($doc['due'] ?? $doc['issue']) . ' +' . self::DAYS_AFTER_DUE . ' days'));
            if ($tx['date'] >= $from && $tx['date'] <= $to) {
                $out[$key] = $doc;
            }
        }
        return $out;
    }

    /**
     * Otevřené faktury převedené z téhle agendy (i doklady minulého období, jejichž saldo
     * nesou počáteční stavy), bez úhrady spárované s pohybem. Zbývá uhradit podle POHODY.
     *
     * @return array<string,array<string,mixed>> „typ|id“ => doklad
     */
    private function openDocuments(PohodaContext $ctx): array
    {
        $pdo = $this->db->pdo();
        $out = [];
        $numbers = ['purchase_invoice' => array_flip(array_map('intval', $ctx->purchaseInvoices)), 'invoice' => array_flip(array_map('intval', $ctx->issuedInvoices))];
        $specs = [
            'purchase_invoice' => "SELECT d.id, d.varsymbol, d.payment_variable_symbol, d.vendor_invoice_number AS original,
                                          d.payment_account_number AS account_no, d.payment_bank_code AS bank_code,
                                          d.amount_to_pay, 0 AS paid_total, d.exchange_rate, d.issue_date, d.due_date, cur.code AS currency,
                                          JSON_UNQUOTE(JSON_EXTRACT(d.vendor_snapshot, '$.company_name')) AS party
                                     FROM purchase_invoices d JOIN currencies cur ON cur.id = d.currency_id
                                    WHERE d.supplier_id = ? AND d.status = 'booked' AND d.document_kind = 'invoice'
                                      AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = d.supplier_id AND pm.purchase_invoice_id = d.id)
                                      AND d.id IN (%s)",
            'invoice' => "SELECT d.id, d.varsymbol, d.payment_variable_symbol, NULL AS original, NULL AS account_no, NULL AS bank_code,
                                 d.amount_to_pay, d.import_amount_to_pay_czk, d.paid_total, d.exchange_rate, d.issue_date, d.due_date, cur.code AS currency,
                                 JSON_UNQUOTE(JSON_EXTRACT(d.client_snapshot, '$.company_name')) AS party
                            FROM invoices d JOIN currencies cur ON cur.id = d.currency_id
                           WHERE d.supplier_id = ? AND d.status IN ('issued', 'sent', 'reminded') AND d.invoice_type = 'invoice'
                             AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.supplier_id = d.supplier_id AND pm.invoice_id = d.id)
                             AND d.id IN (%s)",
        ];
        foreach ($specs as $type => $sql) {
            foreach (array_chunk(array_keys($numbers[$type]), 500) as $chunk) {
                $stmt = $pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
                $stmt->execute(array_merge([$ctx->supplierId], $chunk));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $id = (int) $r['id'];
                    // Doklad v cizí měně jen převzatý s kurzem (pojistka převzetí) - páruje se
                    // v Kč jako pohyby, zbývá uhradit podle POHODY je v Kč i u něj.
                    $rate = $r['exchange_rate'] !== null ? (float) $r['exchange_rate'] : null;
                    if (strtoupper((string) $r['currency']) !== 'CZK' && $rate === null) {
                        continue;
                    }
                    $mine = $type === 'invoice'
                        ? round(IssuedCzkAmounts::due($r, $rate) - IssuedCzkAmounts::allocated($r, (float) $r['paid_total'], $rate ?? 1.0), 2)
                        : ForeignCurrencyTakeover::toHome((float) $r['amount_to_pay'], $rate);
                    $remaining = $ctx->remaining[$type . '|' . $id] ?? $mine;
                    if ($remaining <= self::TOLERANCE) {
                        continue;
                    }
                    $symbols = [];
                    foreach ([$r['varsymbol'], $r['payment_variable_symbol'], $r['original']] as $s) {
                        $digits = self::digits((string) $s);
                        if ($digits !== '') {
                            $symbols[] = $digits;
                        }
                    }
                    $account = self::digits((string) $r['account_no']) !== '' ? BankAccountRegistrar::accountKey((string) $r['account_no'], (string) $r['bank_code']) : null;
                    $out[$type . '|' . $id] = [
                        'key' => $type . '|' . $id,
                        'type' => $type,
                        'id' => $id,
                        'number' => (string) ($numbers[$type][$id] ?? ''),
                        'symbols' => array_values(array_unique($symbols)),
                        'account' => $account,
                        'remaining' => $remaining,
                        // Vydaná faktura, jejíž uhrazenou částku převod vede podle POHODY bez
                        // evidovaných plateb: odvozená platba jen tehdy, když je celá neuhrazená.
                        'clean' => $type === 'purchase_invoice' || (abs((float) $r['paid_total']) < self::TOLERANCE && abs($remaining - $mine) < self::TOLERANCE),
                        'issue' => (string) $r['issue_date'],
                        'due' => $r['due_date'] !== null ? (string) $r['due_date'] : null,
                        // Měna částky `remaining` (návrh párování ji zobrazí) - u dokladu v měně Kč.
                        'currency' => $rate === null ? (string) $r['currency'] : 'CZK',
                        'party' => $r['party'] !== null && $r['party'] !== '' ? (string) $r['party'] : null,
                    ];
                }
            }
        }
        return $out;
    }

    /**
     * Pohyby z téhle agendy se stavem párování a zaúčtování.
     *
     * @param list<int> $ids
     * @return array<int,array<string,mixed>>
     */
    private function transactions(int $supplierId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT t.id, t.amount, DATE(t.posted_at) AS posted_on, t.variable_symbol, t.counterparty_account, t.counterparty_bank,
                        t.match_status, t.bank_ref, COALESCE(NULLIF(t.currency, ''), NULLIF(s.currency, ''), 'CZK') AS currency,
                        EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = t.id) AS has_match,
                        " . \MyInvoice\Service\Bank\BankTransactionPostingScope::existsSql($supplierId, 't.id') . " AS live_entry
                   FROM bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                  WHERE s.supplier_id = ? AND t.source = 'statement' AND t.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
                  ORDER BY t.posted_at, t.id'
            );
            $stmt->execute(array_merge([$supplierId], $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (strtoupper((string) $r['currency']) !== 'CZK' || abs((float) $r['amount']) < self::TOLERANCE) {
                    continue;
                }
                $accountDigits = self::digits((string) $r['counterparty_account']);
                $vs = self::digits((string) $r['variable_symbol']);
                $out[(int) $r['id']] = [
                    'id' => (int) $r['id'],
                    'amount' => round((float) $r['amount'], 2),
                    'date' => (string) $r['posted_on'],
                    'vs' => $vs !== '' ? $vs : null,
                    'bank_ref' => $r['bank_ref'] !== null ? (string) $r['bank_ref'] : null,
                    // Platba kartou nemá účet protistrany (POHODA ho vede jako 0/0000).
                    'card' => $accountDigits === '',
                    'account' => $accountDigits !== '' ? BankAccountRegistrar::accountKey((string) $r['counterparty_account'], (string) $r['counterparty_bank']) : null,
                    'match_status' => (string) $r['match_status'],
                    'matched' => (bool) $r['has_match'],
                    'live_entry' => (bool) $r['live_entry'],
                ];
            }
        }
        return $out;
    }

    /** Číslice bez vodicích nul; kratší než {@see MIN_SYMBOL_DIGITS} = žádný symbol. */
    private static function digits(string $value): string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', $value), '0');
        return strlen($digits) >= self::MIN_SYMBOL_DIGITS ? $digits : '';
    }
}
