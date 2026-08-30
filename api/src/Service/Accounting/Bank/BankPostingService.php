<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\BankPostingRuleRepository;
use MyInvoice\Repository\BankPostingSuggestionRepository;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\TaxAdvanceScheduleRepository;
use MyInvoice\Service\Accounting\AutoPostingPolicyService;
use MyInvoice\Service\Accounting\OperationType;
use MyInvoice\Service\Accounting\PolicyInput;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Payroll\Payment\PayrollBankEvidenceGuard;
use MyInvoice\Service\Accounting\Bank\Detect\BankDetectorChain;
use MyInvoice\Service\Accounting\Bank\Detect\DetectionResult;
use MyInvoice\Service\Accounting\Learning\CorrectionRecorder;
use MyInvoice\Service\Accounting\Learning\RulePromotionService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Bank\AccountNumberNormalizer;
use MyInvoice\Service\Bank\FxPaymentSettlement;
use MyInvoice\Service\Bank\VariableSymbolNormalizer;
use MyInvoice\Service\Currency\CnbExchangeRateClient;
use MyInvoice\Service\Currency\FixedExchangeRateService;
use MyInvoice\Service\Ai\AiKillSwitchService;
use MyInvoice\Service\Ai\AiSuggestionService;
use MyInvoice\Service\Ai\AnomalyDetector;
use MyInvoice\Service\Ai\EmbeddingWriter;
use PDO;

/**
 * Engine automatického zaúčtování bankovních transakcí (mini-epic AUTOMATIZACE).
 *
 * „Mysli jako účetní": spárované platby FV/PF → přímý zápis 221/311 | 321/221
 * (guard předpisu H1), opakované platby bez dokladu → pravidla (suggest/auto) nebo
 * naučený návrh (learned). Zaúčtování VÝHRADNĚ přes {@see PostingService}
 * (source_type='bank', idempotence na ('bank', bt.id)). handleTransaction je
 * best-effort hook (nikdy nevyhazuje — loguje), Action cesty (approve/reject/post/
 * unpost) chyby propagují jako {@see PostingException}.
 *
 * CZK-only v1, avíza se nikdy neúčtují, saldokonta mimo automatiku (blacklist H2).
 */
final class BankPostingService
{
    /** Saldokontní účty, které do pravidla/manuálu na ne-bankovní stranu nepatří (H2). */
    private const SALDO_BLACKLIST = ['311', '321', '314', '324', '325'];

    /** Tolerance dorovnání dle StatementMatcher::PARTIAL_MATCH_TOLERANCE = 1,00 Kč (haléře). */
    private const ROUNDING_TOLERANCE_CENTS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $postingRules,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly BankPostingRuleRepository $rules,
        private readonly BankPostingSuggestionRepository $suggestions,
        private readonly BankRuleMatcher $ruleMatcher,
        private readonly ActivityLogger $activity,
        private readonly CnbExchangeRateClient $cnb,
        private readonly FixedExchangeRateService $fixedRates,
        private readonly ?BankDetectorChain $detectors = null,
        private readonly ?TransferPairService $transfers = null,
        private readonly ?AutoPostingPolicyService $policy = null,
        private readonly ?TaxAdvanceScheduleRepository $taxSchedules = null,
        private readonly ?CorrectionRecorder $corrections = null,
        private readonly ?RulePromotionService $promotion = null,
        private readonly ?AnomalyDetector $anomalies = null,
        private readonly ?AiSuggestionService $aiSuggestions = null,
        private readonly ?AiKillSwitchService $aiKillSwitch = null,
        private readonly ?EmbeddingWriter $embeddingWriter = null,
        private readonly ?LegacyBankPaymentReconciler $legacyPayments = null,
        private readonly ?BankAnalyticResolver $bankAnalytics = null,
        private readonly ?BankPostingPreview $previews = null,
        /**
         * Ú-16: pohyb, který si nárokovaly mzdy, nesmí zaúčtovat i banka.
         *
         * Stráž existovala jen pro fakturační párování; účtování o ní nevědělo,
         * takže odvod na předčíslí 0710 mohl detektor zaúčtovat 336/221 i poté,
         * co týž pohyb zaúčtovala úhrada mzdového závazku — a závazek by se
         * odúčtoval DVAKRÁT. Mzdy `match_status` záměrně nepřepisují, takže
         * pohyb jinak vypadá jako nespárovaný a nic jiného by tomu nezabránilo.
         *
         * Pořadí nerozhoduje: kdo je první, ten pohyb zaúčtuje, a druhý ho
         * uvidí ({@see \MyInvoice\Service\Payroll\Payment\PayrollPaymentPostingService}
         * si cizí zápis poznamená jako `posted_elsewhere`, banka ho přeskočí).
         */
        private readonly ?PayrollBankEvidenceGuard $payrollEvidence = null,
    ) {}

    /**
     * Nasměruje bankovní nohu ('221') na dedikovanou analytiku vlastního účtu
     * výpisu (#35 — analytic_suffix). No-op bez resolveru / bez suffixu.
     *
     * @param array<string,mixed> $tx
     * @param list<array<string,mixed>> $lines
     * @return list<array<string,mixed>>
     */
    private function withBankAnalytic(int $supplierId, array $tx, array $lines): array
    {
        return $this->bankAnalytics?->apply($supplierId, $tx, $lines) ?? $lines;
    }

    /**
     * Má transakce živý zápis TOTOŽNÝ s předloženými řádky? Vrací jeho id, jinak null.
     *
     * Porovnává se množina (strana, kód účtu, částka v haléřích) — tedy účetní obsah,
     * ne pořadí řádků ani jejich id. Částky přes haléře, ať float reprezentace nedělá
     * falešné rozdíly.
     *
     * @param list<array{account_code:string, side:string, amount:float|int|string}> $lines
     */
    private function liveEntryMatching(int $supplierId, int $txId, array $lines): ?int
    {
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing === null || ($existing['reversed_by'] ?? null) !== null) {
            return null;
        }
        $entryId = (int) $existing['id'];

        $stmt = $this->db->pdo()->prepare(
            'SELECT c.account_code AS code, jel.side, jel.amount
               FROM journal_entry_lines jel
               JOIN chart_of_accounts c ON c.id = jel.account_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?'
        );
        $stmt->execute([$entryId, $supplierId]);

        $signature = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) {
                $out[] = sprintf(
                    '%s|%s|%d',
                    (string) $r['side'],
                    (string) $r['code'],
                    (int) round(((float) $r['amount']) * 100.0)
                );
            }
            sort($out);
            return $out;
        };

        $proposed = $signature(array_map(
            static fn (array $l): array => [
                'side'   => $l['side'],
                'code'   => $l['account_code'],
                'amount' => $l['amount'],
            ],
            $lines
        ));

        return $signature($stmt->fetchAll(\PDO::FETCH_ASSOC)) === $proposed ? $entryId : null;
    }

    // ── hlavní hook ─────────────────────────────────────────────────────────────

    /**
     * Hlavní hook po každém (re)match/importu. Nikdy nevyhazuje (best-effort, loguje).
     *
     * Při výjimce vrací `reason='error'` a NAVÍC `message` s textem výjimky. Bez toho
     * je dávkový běh slepý: 14 transakcí padalo na chybějící sloupec z neaplikované
     * migrace a report ukazoval jen `error`, takže se příčina musela dolovat reflexí.
     *
     * @return array{action:string, reason?:string, message?:string, entry_id?:int, suggestion_id?:int}
     */
    public function handleTransaction(
        int $txId,
        ?int $userId = null,
        bool $suggestOnly = false,
        ?int $activationSupplierId = null,
    ): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        $savepoint = 'bank_posting_' . max(0, $txId);
        if ($ownTx) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $this->handleTransactionInTransaction($txId, $userId, $suggestOnly, $activationSupplierId);
            if ($ownTx) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            try {
                $this->activity->log('bank_posting.error', $userId, 'bank_transaction', $txId,
                    ['message' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            return ['action' => 'skipped', 'reason' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** @return array{action:string, reason?:string, entry_id?:int, suggestion_id?:int} */
    private function handleTransactionInTransaction(
        int $txId,
        ?int $userId,
        bool $suggestOnly,
        ?int $activationSupplierId,
    ): array {
            $tx = $this->loadTx($txId);
            if ($tx === null) {
                return ['action' => 'skipped', 'reason' => 'transaction_not_found'];
            }

            // 1) tenant z čísla účtu výpisu (M4 — kandidáti, nikdy „první vyhrává").
            $candidates = $this->resolveSupplierCandidates($tx);
            if ($candidates === []) {
                return ['action' => 'skipped', 'reason' => 'unknown_supplier'];
            }
            if (count($candidates) > 1) {
                $this->activity->log('bank_posting.ambiguous_supplier', $userId, 'bank_transaction', $txId,
                    ['candidates' => $candidates]);
                return ['action' => 'skipped', 'reason' => 'ambiguous_supplier'];
            }
            $supplierId = $candidates[0];

            // 2) jen podvojné účetnictví (hook běží z cronů/importu → gate v service).
            $activationBackfill = $suggestOnly && $activationSupplierId === $supplierId;
            if ($this->supplierMode($supplierId) !== 'double_entry' && !$activationBackfill) {
                return ['action' => 'skipped', 'reason' => 'not_double_entry'];
            }
            // 3) avízo je provizorní duplikát — nikdy se neúčtuje (GPC takeover doúčtuje GPC tx).
            if ((string) ($tx['source'] ?? 'statement') !== 'statement') {
                return ['action' => 'skipped', 'reason' => 'email_notice_provisional'];
            }
            // 4) ignorovaná tx se neúčtuje.
            if ((string) $tx['match_status'] === 'ignored') {
                return ['action' => 'skipped', 'reason' => 'ignored'];
            }
            // 4b) pohyb spotřebovaný mzdovou platbou účtuje mzdová strana.
            if ($this->payrollEvidence?->isUsedByPayrollSafely($txId) === true) {
                return ['action' => 'skipped', 'reason' => 'payroll_payment'];
            }
            $isMatched = in_array((string) $tx['match_status'], ['auto_exact', 'auto_partial', 'manual'], true)
                || !empty($tx['has_explicit_allocation']);

            // Jediný hook detektorů před obecným FX guardem. Matched platby mají
            // přednost; stejnoměnný cizoměnový vlastní převod smí projít tierem 20.
            if (!$isMatched) {
                $detected = $this->detectors?->run($supplierId, $tx, $userId, $suggestOnly);
                if ($detected !== null) {
                    return $detected instanceof DetectionResult
                        ? $this->applyDetection($supplierId, $tx, $detected, $userId, $suggestOnly)
                        : $detected;
                }
            }

            // 5) cizí měna: spárovaná úhrada saldokonta jde FX větví (B6 — přepočet úhrady
            //    kurzem ČNB dne banky, kurzový rozdíl proti kurzu předpisu na 563/663).
            //    NESpárovaná cizoměnová tx (pravidla/učení) zůstává mimo automatiku —
            //    pravidla i learned pracují s CZK částkou, ne s cizoměnovým přepočtem.
            if ($this->effectiveCurrency($tx) !== 'CZK' && !$isMatched
                && !$this->hasMatchingRuleForCurrency($supplierId, $tx)) {
                return ['action' => 'skipped', 'reason' => 'fx_not_supported'];
            }

            // 6) stavová tabulka existujícího zápisu (H3).
            $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
            $hasLiveEntry = $existing !== null && ($existing['reversed_by'] ?? null) === null;
            if ($hasLiveEntry && !$isMatched) {
                return ['action' => 'skipped', 'reason' => 'already_posted'];
            }

            // 7) matched → postMatched (idempotentní rewrite); jinak → applyRules.
            if ($isMatched) {
                return $this->matchedOutcome($supplierId, $tx, $userId, $activationBackfill);
            }
            return $this->applyRules($supplierId, $txId, $userId, $suggestOnly);
    }

    // ── spárované platby (guard H1 + M1) ────────────────────────────────────────

    /**
     * Spárovaná tx (FV/PF) → přímý zápis 221/311 | 321/221. Vrací entry id, null = skip.
     */
    public function postMatched(int $supplierId, int $txId, ?int $userId = null): ?int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $tx = $this->loadTx($txId);
            if ($tx === null) {
                if ($ownTx) {
                    $pdo->commit();
                }
                return null;
            }
            $res = $this->matchedOutcome($supplierId, $tx, $userId);
            if ($ownTx) {
                $pdo->commit();
            }
            return $res['action'] === 'posted' ? ($res['entry_id'] ?? null) : null;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{action:string, reason?:string, entry_id?:int, suggestion_id?:int}
     */
    private function matchedOutcome(int $supplierId, array $tx, ?int $userId, bool $activationBackfill = false): array
    {
        $txId = (int) $tx['id'];
        $amount = (float) $tx['amount'];
        $absCents = (int) round(abs($amount) * 100.0);
        if ($absCents === 0) {
            return ['action' => 'skipped', 'reason' => 'zero_amount'];
        }

        if ($amount > 0.0 && $this->legacyPayments !== null) {
            $this->legacyPayments->reconcileMatchedIncoming($supplierId, $txId);
        }

        // Haléřový nedoplatek/přeplatek (protistrana platí zaokrouhleně na koruny) → srovnat
        // alokaci na nominál předpisu, ať appendRounding() níž vytvoří 548/648 nohu a doklad
        // se uzavře. Idempotentní (po srovnání už alokace ≠ částka tx → no-op).
        // Obě větve, každá se guarduje sama podle znaménka pohybu.
        $this->normalizeRoundingFullPurchase($supplierId, $txId);
        $this->normalizeRoundingFullInvoice($supplierId, $txId);

        try {
            $build = $this->buildMatched($supplierId, $tx);
        } catch (PostingException $e) {
            if ($e->errorCode === 'already_paid_verify') {
                return $this->paymentMatchSuggestion($supplierId, $tx, 'already_paid_verify');
            }
            // Nejistá křížová měna (dvojí konverze / hrubá odchylka kurzu / proforma) →
            // blokovaný návrh k ručnímu ověření (ne tichý skip). Čistý případ „CZK faktura
            // uhrazená přes cizoměnový účet" se zaúčtuje automaticky (viz buildIncomingCrossCurrencyFx).
            if ($e->errorCode === 'cross_currency') {
                return $this->paymentMatchSuggestion($supplierId, $tx, 'cross_currency');
            }
            // fx_not_supported / document_not_posted / allocation_mismatch → skip bez suggestion.
            return ['action' => 'skipped', 'reason' => $e->errorCode];
        }

        $debitCode = '';
        $creditCode = '';
        $debitTotal = 0.0;
        foreach ($build['lines'] as $line) {
            if (($line['side'] ?? null) === 'debit') {
                $debitCode = $debitCode ?: (string) $line['account_code'];
                $debitTotal += (float) $line['amount'];
            } else {
                $creditCode = $creditCode ?: (string) $line['account_code'];
            }
        }

        // Rewrite spárované tx je no-op, pokud živý zápis odpovídá tomu, co bychom teď
        // zaúčtovali. Bez téhle zkratky každý další průchod nad UŽ zaúčtovanou tx (rematch,
        // reimport výpisu, accept match suggestion) spadl do policy větve níž a založil nový
        // `pending` návrh — na ostrých datech se takhle nasbíralo 88 „duchů", které kartu
        // „Zaúčtuj doklady" nafukovaly proti reálné frontě. Rozdílný zápis (přepárování na
        // jinou fakturu, doúčtovaný haléřový rozdíl) touhle podmínkou neprojde a rewrite
        // i návrh proběhnou jako dřív. Porovnává se to, co se reálně zaúčtuje — tedy až po
        // withBankAnalytic(), ne syrové $build['lines'].
        //
        // Guard je záměrně AŽ za buildMatched() (potřebuje řádky) a JEŠTĚ PŘED policy->decide():
        // jinak by tx, které se mezitím přepnula politika na `suggest`/`skip`, vyrobila
        // spurious návrh, resp. `policy_off` skip proti zápisu, který už existuje.
        $postedLines = $this->withBankAnalytic($supplierId, $tx, $build['lines']);
        $liveEntryId = $this->liveEntryMatching($supplierId, $txId, $postedLines);
        if ($liveEntryId !== null) {
            // 'posted' (ne 'skipped') — volající se ptá „ať je tahle tx zaúčtovaná takhle",
            // a ona je. postMatched() jinak vrátí null, což SampleDataGenerator bere jako
            // chybu, a FE by ukázal matoucí toast „spárováno, ale nezaúčtováno".
            return ['action' => 'posted', 'reason' => 'already_posted', 'entry_id' => $liveEntryId];
        }

        if ($this->policy !== null && !$activationBackfill) {
            // Podezření na DVOJÍ ÚHRADU (stejný VS i částka v okně 10 dní) se musí dostat
            // k člověku i u prokazatelně spárované platby — proto jde vlastním kanálem
            // (`duplicateSuspect` → needs_input), zatímco vybočení částky z historie
            // protistrany (`amount_zscore`) u jistoty 1.00 automatiku neblokuje, viz
            // AutoPostingPolicyService::decide().
            $codes = $this->anomalyCodes($supplierId, $tx);
            $anomaly = $codes !== [];
            $policy = $this->policy->decide($supplierId, new PolicyInput(
                OperationType::BANK_PAYMENT_MATCHED,
                'payment_match',
                1.00,
                round($debitTotal, 2),
                $this->effectiveCurrency($tx),
                (string) $tx['posted_at'],
                $debitCode,
                $creditCode,
                duplicateSuspect: in_array('duplicate_payment', $codes, true),
                anomaly: $anomaly,
            ));
            if ($policy->decision === 'skip') {
                return ['action' => 'skipped', 'reason' => 'policy_off'];
            }
            if ($policy->decision !== 'auto') {
                $result = $this->paymentMatchSuggestion($supplierId, $tx, $policy->note ?? 'policy_suggest');
                if ($anomaly) {
                    $this->anomalies?->recordDegraded($supplierId);
                }
                return $result;
            }
        }

        $postedAt = (string) $tx['posted_at'];
        $period = $this->periods->ensureOpenPeriodFor($supplierId, $postedAt);
        if (($period['status'] ?? 'open') !== 'open') {
            return $this->paymentMatchSuggestion($supplierId, $tx, 'period_closed');
        }

        try {
            $entryId = $this->posting->postDocument($supplierId, 'bank', $txId, $postedLines, [
                'entry_date'    => $postedAt,
                'document_date' => $postedAt,
                'document_no'   => $this->documentNo($tx),
                'description'   => $this->entryDescription($tx),
                'posted'        => true,
                'user_id'       => $userId,
                'posted_by'     => $userId,
            ]);
        } catch (PostingException $e) {
            if (in_array($e->errorCode, ['period_not_open', 'no_accounting_period', 'entry_reversed'], true)) {
                return $this->paymentMatchSuggestion($supplierId, $tx, 'period_closed');
            }
            throw $e;
        }

        // Úspěšný (re)zápis → pending i auto_posted suggestion řádky tx superseded (H3).
        $this->suggestions->supersedeMatchedForTx($supplierId, $txId);
        $this->suggestions->createAutoPosted([
            'supplier_id'         => $supplierId,
            'bank_transaction_id' => $txId,
            'rule_id'             => null,
            'source'              => 'payment_match',
            'debit_account_code'  => $debitCode,
            'credit_account_code' => $creditCode,
            'amount'              => round($debitTotal, 2),
            'description'         => $this->entryDescription($tx),
            'journal_entry_id'    => $entryId,
            'confidence'          => 1.00,
            'operation_type'      => OperationType::BANK_PAYMENT_MATCHED,
        ]);
        $this->activity->log(
            'bank_match.auto_posted',
            $userId,
            'bank_transaction',
            $txId,
            ['journal_entry_id' => $entryId],
            supplierId: $supplierId,
        );
        return ['action' => 'posted', 'reason' => 'matched', 'entry_id' => $entryId];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{lines:list<array<string,mixed>>}
     */
    private function buildMatched(int $supplierId, array $tx): array
    {
        $isForeign = $this->effectiveCurrency($tx) !== 'CZK';
        if ((float) $tx['amount'] > 0) {
            return $isForeign
                ? $this->buildIncomingMatchedFx($supplierId, $tx)
                : $this->buildIncomingMatched($supplierId, $tx);
        }
        return $isForeign
            ? $this->buildOutgoingMatchedFx($supplierId, $tx)
            : $this->buildOutgoingMatched($supplierId, $tx);
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildIncomingMatched(int $supplierId, array $tx): array
    {
        $txId = (int) $tx['id'];
        $absAmount = round(abs((float) $tx['amount']), 2);

        // Alokace z invoice_payments (idempotentně přes bank_transaction_id).
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_id, amount FROM invoice_payments WHERE bank_transaction_id = ?'
        );
        $stmt->execute([$txId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $purchaseRefunds = $this->db->pdo()->prepare(
            'SELECT purchase_invoice_id, amount FROM payment_matches
              WHERE bank_transaction_id = ? AND purchase_invoice_id IS NOT NULL'
        );
        $purchaseRefunds->execute([$txId]);
        $purchaseRefundAllocations = $purchaseRefunds->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Legacy fallback matched_invoice_id (M1).
        if ($allocations === [] && $tx['matched_invoice_id'] !== null) {
            $inv = $this->loadInvoice($supplierId, (int) $tx['matched_invoice_id']);
            if ($inv !== null && (string) $inv['status'] === 'paid') {
                // paid ∧ žádný invoice_payments řádek → člověk ověří (přeplatek?).
                throw new PostingException('already_paid_verify', 'Faktura je označená jako uhrazená bez evidované platby.');
            }
            $allocations = [['invoice_id' => (int) $tx['matched_invoice_id'], 'amount' => $absAmount]];
        }
        if ($allocations === [] && $purchaseRefundAllocations === []) {
            throw new PostingException('document_not_posted', 'Spárovaná příchozí platba nemá alokaci na fakturu.');
        }

        $rule = $this->postingRules->resolve($supplierId, 'payment.receivable.bank');
        $bankAcc = $rule['debit_account_code'] ?? '221';
        $receivable = $rule['credit_account_code'] ?? '311';

        // CZK pohyb proti jedné cizoměnové vydané faktuře má dvě peněžní
        // hodnoty: skutečnou CZK částku banky a alokaci v měně faktury.
        // Saldokonto proto odúčtujeme kurzem předpisu a rozdíl vedeme na 563/663.
        if (count($allocations) === 1 && $purchaseRefundAllocations === []) {
            $invoiceId = (int) $allocations[0]['invoice_id'];
            $invoice = $this->loadInvoice($supplierId, $invoiceId);
            if ($invoice !== null && FxPaymentSettlement::isCzkPaymentOfForeignInvoice(
                $this->effectiveCurrency($tx),
                (string) $invoice['currency'],
            )) {
                return $this->buildIncomingCzkFx(
                    $supplierId,
                    $tx,
                    $invoiceId,
                    (float) $allocations[0]['amount'],
                    (string) $receivable,
                    (string) $bankAcc,
                );
            }
        }

        $lines = [$this->line($bankAcc, 'debit', $absAmount)];
        $allocSum = 0.0;
        foreach ($allocations as $a) {
            $invId = (int) $a['invoice_id'];
            $counter = $this->incomingCounterAccount($supplierId, $invId, $receivable);
            $alloc = round((float) $a['amount'], 2);
            $allocSum += $alloc;
            $lines[] = $this->line($counter, 'credit', $alloc);
        }
        foreach ($purchaseRefundAllocations as $a) {
            $pfId = (int) $a['purchase_invoice_id'];
            $counter = $this->incomingPurchaseRefundCounter($supplierId, $pfId);
            $alloc = round((float) $a['amount'], 2);
            $allocSum += $alloc;
            $lines[] = $this->line($counter, 'credit', $alloc);
        }

        $this->appendRounding($lines, $absAmount - round($allocSum, 2));
        return ['lines' => $lines];
    }

    /**
     * CZK úhrada jedné cizoměnové vydané faktury. Bankovní noha zůstává
     * ve skutečné CZK částce, zatímco 311 se odúčtuje v CZK hodnotě alokace
     * podle kurzu předpisu. Rozdíl je realizovaný kurzový výsledek 563/663.
     *
     * @return array{lines:list<array<string,mixed>>}
     */
    private function buildIncomingCzkFx(
        int $supplierId,
        array $tx,
        int $invoiceId,
        float $foreignAmount,
        string $receivable,
        string $bankAcc,
    ): array {
        $invoice = $this->loadInvoice($supplierId, $invoiceId);
        if ($invoice === null
            || (string) $invoice['currency'] === FxPaymentSettlement::LOCAL_CURRENCY
            || (string) $invoice['invoice_type'] === 'proforma'
            || $foreignAmount <= 0.0
        ) {
            throw new PostingException('fx_not_supported', 'Křížovou měnu této vydané faktury nelze automaticky zaúčtovat.');
        }

        $entry = $this->journal->findBySource($supplierId, 'invoice', $invoiceId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' nemá zaúčtovaný předpis.');
        }
        $rate = $this->predpisFxRate($supplierId, (int) $entry['id'], $invoice);
        $foreign = round($foreignAmount, 2);
        $predpisCzk = FxPaymentSettlement::expectedLocalAmount($foreign, $rate);
        $bankCzk = round(abs((float) $tx['amount']), 2);

        $lines = [
            $this->line($bankAcc, 'debit', $bankCzk),
            $this->withFxTrace(
                $this->line($receivable, 'credit', $predpisCzk),
                (string) $invoice['currency'],
                $rate,
                $foreign,
            ),
        ];
        $this->appendFxDifference($lines, $supplierId, 0.0, true);
        return ['lines' => $lines];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildIncomingFeeGap(int $supplierId, array $tx, float $feeAmount): array
    {
        if ((float) $tx['amount'] <= 0.0 || $this->effectiveCurrency($tx) !== 'CZK' || $feeAmount <= 0.0) {
            throw new PostingException('fee_gap_not_supported', 'Poplatek lze tímto návrhem zaúčtovat jen u příchozí korunové platby.', 409);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_id, amount FROM invoice_payments WHERE bank_transaction_id = ? ORDER BY id'
        );
        $stmt->execute([(int) $tx['id']]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($allocations === []) {
            throw new PostingException('document_not_posted', 'Platba s poplatkem nemá alokaci na vydanou fakturu.');
        }

        $net = round(abs((float) $tx['amount']), 2);
        $fee = round($feeAmount, 2);
        $gross = round(array_sum(array_map(static fn (array $row): float => (float) $row['amount'], $allocations)), 2);
        if (abs($gross - round($net + $fee, 2)) > 0.01) {
            throw new PostingException('allocation_mismatch', 'Poplatek neodpovídá rozdílu mezi platbou a alokovanými fakturami.');
        }

        $rule = $this->postingRules->resolve($supplierId, 'payment.receivable.bank');
        $bankAcc = $rule['debit_account_code'] ?? '221';
        $receivable = $rule['credit_account_code'] ?? '311';
        $lines = [
            $this->line($bankAcc, 'debit', $net),
            $this->line('568', 'debit', $fee),
        ];
        foreach ($allocations as $allocation) {
            $counter = $this->incomingCounterAccount($supplierId, (int) $allocation['invoice_id'], $receivable);
            $lines[] = $this->line($counter, 'credit', (float) $allocation['amount']);
        }
        return ['lines' => $lines];
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildOutgoingMatched(int $supplierId, array $tx): array
    {
        $txId = (int) $tx['id'];
        $absAmount = round(abs((float) $tx['amount']), 2);

        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_id, purchase_invoice_id, amount FROM payment_matches
              WHERE bank_transaction_id = ?'
        );
        $stmt->execute([$txId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($allocations === []) {
            throw new PostingException('document_not_posted', 'Spárovaná odchozí platba nemá alokaci na přijatou fakturu.');
        }

        $rule = $this->postingRules->resolve($supplierId, 'payment.payable.bank');
        $payable = $rule['debit_account_code'] ?? '321';
        $bankAcc = $rule['credit_account_code'] ?? '221';

        // Karetní platba z CZK účtu za cizoměnovou službu: payment_matches.amount je
        // částka transakce v CZK, zatímco závazek se musí odúčtovat v CZK hodnotě
        // předpisu cizoměnové faktury. Existující 1:1 párování je autoritativní;
        // rozdíl bankovní částky proti předpisu je kurzový výsledek 563/663.
        if (count($allocations) === 1 && $allocations[0]['purchase_invoice_id'] !== null) {
            $purchase = $this->loadPurchase($supplierId, (int) $allocations[0]['purchase_invoice_id']);
            if ($purchase !== null && (string) $purchase['currency'] !== 'CZK') {
                return $this->buildOutgoingCzkCardFx(
                    $supplierId,
                    $tx,
                    (int) $allocations[0]['purchase_invoice_id'],
                    (string) $payable,
                    (string) $bankAcc,
                );
            }
        }

        $lines = [];
        $allocSum = 0.0;
        foreach ($allocations as $a) {
            $counter = $a['invoice_id'] !== null
                ? $this->outgoingIssuedRefundCounter($supplierId, (int) $a['invoice_id'])
                : $this->outgoingCounterAccount($supplierId, (int) $a['purchase_invoice_id'], $payable);
            $alloc = round((float) $a['amount'], 2);
            $allocSum += $alloc;
            $lines[] = $this->line($counter, 'debit', $alloc);
        }
        $lines[] = $this->line($bankAcc, 'credit', $absAmount);

        // diff = ΣMD − ΣD = allocSum − absAmount; záporné → 548 (odchozí náklad).
        $this->appendRounding($lines, round($allocSum, 2) - $absAmount);
        return ['lines' => $lines];
    }

    /**
     * CZK karetní/bankovní úhrada jedné cizoměnové přijaté faktury. Částka 321 se
     * bere z nominálu a kurzu předpisu, bankovní noha je skutečně odepsaná CZK částka.
     * Rozdíl je kurzový zisk/ztráta; nejde o haléřové dorovnání 548/648.
     *
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildOutgoingCzkCardFx(
        int $supplierId,
        array $tx,
        int $purchaseId,
        string $payable,
        string $bankAcc,
    ): array {
        $purchase = $this->loadPurchase($supplierId, $purchaseId);
        if ($purchase === null
            || (string) $purchase['currency'] === 'CZK'
            || (string) $purchase['document_kind'] === 'advance'
            || (float) ($purchase['amount_to_pay'] ?? 0) <= 0.0
        ) {
            throw new PostingException('fx_not_supported', 'Křížovou měnu této přijaté faktury nelze automaticky zaúčtovat.');
        }

        $entry = $this->journal->findBySource($supplierId, 'purchase_invoice', $purchaseId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Přijatá faktura #' . $purchaseId . ' nemá zaúčtovaný předpis.');
        }
        $rate = $this->predpisFxRate($supplierId, (int) $entry['id'], $purchase);
        $foreign = round((float) $purchase['amount_to_pay'], 2);
        $predpisCzk = round($foreign * $rate, 2);
        $bankCzk = round(abs((float) $tx['amount']), 2);

        $lines = [
            $this->withFxTrace(
                $this->line($payable, 'debit', $predpisCzk),
                (string) $purchase['currency'],
                $rate,
                $foreign,
            ),
            $this->line($bankAcc, 'credit', $bankCzk),
        ];
        $this->appendFxDifference($lines, $supplierId, 0.0, false);
        return ['lines' => $lines];
    }

    /**
     * Protiúčet příchozí spárované platby (B5): proforma = inkaso přijaté zálohy 221/324
     * (proforma není daňový doklad, nemá zaúčtovaný předpis), běžná FV = saldokonto 311
     * (guard H1: CZK + zaúčtovaný nestornovaný předpis).
     */
    private function incomingCounterAccount(int $supplierId, int $invoiceId, string $receivable): string
    {
        $inv = $this->loadInvoice($supplierId, $invoiceId);
        if ($inv === null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' neexistuje.');
        }
        if ((string) $inv['currency'] !== 'CZK') {
            throw new PostingException('fx_not_supported', 'Cizoměnová faktura se zatím neúčtuje.');
        }
        if ((string) $inv['invoice_type'] === 'proforma') {
            $rule = $this->postingRules->resolve($supplierId, 'advance.received.collection');
            return ($rule['credit_account_code'] ?? null) ?: '324';
        }
        $entry = $this->journal->findBySource($supplierId, 'invoice', $invoiceId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' nemá zaúčtovaný předpis.');
        }
        return $receivable;
    }

    /**
     * Protiúčet odchozí spárované platby (B5): zálohová PF (document_kind=advance) =
     * poskytnutá záloha 314/221, běžná PF = saldokonto 321 (guard H1: CZK + zaúčtovaný
     * nestornovaný předpis).
     */
    private function outgoingCounterAccount(int $supplierId, int $pfId, string $payable): string
    {
        $pf = $this->loadPurchase($supplierId, $pfId);
        if ($pf === null) {
            throw new PostingException('document_not_posted', 'Přijatá faktura #' . $pfId . ' neexistuje.');
        }
        if ((string) $pf['currency'] !== 'CZK') {
            throw new PostingException('fx_not_supported', 'Cizoměnová přijatá faktura se zatím neúčtuje.');
        }
        // DDKP navázaný na zálohovou fakturu vlastní úhradu nemá — zaplatila se ZÁLOHA a DDKP
        // k ní jen dokládá daň (účtuje 343/314). Úhradu proto patří spárovat se zálohou;
        // jinak by vznikl fantomový zápis proti dokladu, který nemá 321 nohu.
        if ((string) $pf['document_kind'] === 'tax_document'
            && ($pf['parent_purchase_invoice_id'] ?? null) !== null
        ) {
            throw new PostingException(
                'ddkp_not_payable',
                'Daňový doklad k platbě (DDKP) #' . $pfId . ' se neplatí samostatně — úhradu spáruj se '
                    . 'zálohovou fakturou (document_kind=advance), ke které DDKP patří.',
            );
        }
        // SAMOSTATNÝ DDKP (bez zálohové faktury) naopak vlastní úhradu MÁ — je to jediný doklad,
        // který k té platbě existuje. Typicky nákup zaplacený kartou, kde prodejce vystaví
        // „daňový doklad ke dni přijaté úplaty" (§ 28/8) a fakturu na zboží pošle až s dodáním.
        // Úhrada je pak poskytnutá záloha (§ 20a/2 — daň se přiznává k přijetí úplaty), takže
        // patří na 314 stejně jako u zálohové faktury: na 314 se sejde platba (MD) i odpočet
        // DPH z DDKP (D) a zbylý základ čeká na konečnou fakturu.
        //
        // Bez téhle větve platba skončila na `ddkp_not_payable`, doklad zůstal viset a na 314
        // byl jen kredit z DPH bez protistrany.
        if ((string) $pf['document_kind'] === 'advance'
            || (string) $pf['document_kind'] === 'tax_document'
        ) {
            $rule = $this->postingRules->resolve($supplierId, 'advance.paid.payment');
            return ($rule['debit_account_code'] ?? null) ?: '314';
        }
        $entry = $this->journal->findBySource($supplierId, 'purchase_invoice', $pfId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Přijatá faktura #' . $pfId . ' nemá zaúčtovaný předpis.');
        }
        return $payable;
    }

    private function incomingPurchaseRefundCounter(int $supplierId, int $pfId): string
    {
        $pf = $this->loadPurchase($supplierId, $pfId);
        if ($pf === null) {
            throw new PostingException('document_not_posted', 'Přijatý dobropis #' . $pfId . ' neexistuje.');
        }
        // P1.15: doklad reálně existuje, jen není dobropis — platba je vratka, patří na
        // dobropis, ne na běžnou fakturu (stalo se u vratky Adaptic, bt 1171).
        if ((string) $pf['document_kind'] !== 'credit_note') {
            throw new PostingException(
                'refund_target_not_credit_note',
                'Platba je navázaná na přijatou fakturu #' . $pfId . ', ne na dobropis — vratka patří na dobropis.',
            );
        }
        if ((string) $pf['currency'] !== 'CZK') {
            throw new PostingException('fx_not_supported', 'Cizoměnová refundace dobropisu se zatím neúčtuje automaticky.');
        }
        $entry = $this->journal->findBySource($supplierId, 'purchase_invoice', $pfId);
        if ($entry === null || ($entry['posted_at'] ?? null) === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Přijatý dobropis #' . $pfId . ' nemá zaúčtovaný předpis.');
        }
        $rule = $this->postingRules->resolve($supplierId, 'payment.payable.bank');
        return ($rule['debit_account_code'] ?? null) ?: '321';
    }

    private function outgoingIssuedRefundCounter(int $supplierId, int $invoiceId): string
    {
        $inv = $this->loadInvoice($supplierId, $invoiceId);
        if ($inv === null) {
            throw new PostingException('document_not_posted', 'Vydaný dobropis #' . $invoiceId . ' neexistuje.');
        }
        // P1.15: doklad reálně existuje, jen není dobropis — platba je vratka, patří na
        // dobropis, ne na běžnou fakturu (stalo se u vratky Adaptic, bt 1171).
        if ((string) $inv['invoice_type'] !== 'credit_note') {
            throw new PostingException(
                'refund_target_not_credit_note',
                'Platba je navázaná na vydanou fakturu #' . $invoiceId . ', ne na dobropis — vratka patří na dobropis.',
            );
        }
        if ((string) $inv['currency'] !== 'CZK') {
            throw new PostingException('fx_not_supported', 'Cizoměnová refundace dobropisu se zatím neúčtuje automaticky.');
        }
        $entry = $this->journal->findBySource($supplierId, 'invoice', $invoiceId);
        if ($entry === null || ($entry['posted_at'] ?? null) === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Vydaný dobropis #' . $invoiceId . ' nemá zaúčtovaný předpis.');
        }
        $rule = $this->postingRules->resolve($supplierId, 'payment.receivable.bank');
        return ($rule['credit_account_code'] ?? null) ?: '311';
    }

    // ── cizoměnové spárované úhrady (B6 — kurzový rozdíl 563/663) ────────────────

    /**
     * Tolerance nealokovaného zbytku cizoměnové platby (v jednotkách cizí měny × 100).
     * Nad ní jde o přeplatek/nedoplatek, ne kurzový rozdíl → allocation_mismatch (ruční
     * zaúčtování); kurzový rozdíl sám žádný strop nemá (legitimní, ne chyba dat).
     */
    private const FX_ALLOCATION_TOLERANCE = 100;

    /**
     * Příchozí cizoměnová úhrada FV (EUR faktura, EUR platba na EUR účet): saldokonto 311
     * se odúčtuje v CZK hodnotě PŘEDPISU (cizí částka × kurz předpisu), banka 221 v CZK
     * hodnotě SKUTEČNÉ úhrady (cizí částka × kurz ČNB dne banky), rozdíl → 563 MD (ztráta) /
     * 663 D (zisk). Podporuje i částečnou / sloučenou úhradu (per-alokace poměrná část).
     *
     * @param array<string,mixed> $tx
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildIncomingMatchedFx(int $supplierId, array $tx): array
    {
        $txId = (int) $tx['id'];
        $currency = $this->effectiveCurrency($tx);
        $absForeign = round(abs((float) $tx['amount']), 2);

        $stmt = $this->db->pdo()->prepare(
            'SELECT invoice_id, amount FROM invoice_payments WHERE bank_transaction_id = ?'
        );
        $stmt->execute([$txId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $usedFallback = false;
        if ($allocations === [] && $tx['matched_invoice_id'] !== null) {
            // Parita s CZK cestou (buildIncomingMatched, M1): faktura paid bez evidované
            // platby → člověk ověří (přeplatek/duplicita). Bez guardu by fallback jiné tx
            // se stejným matched_invoice_id (idempotence je per-tx, ne per-fakturu) podruhé
            // odúčtoval 311 a rozhodil saldokonto.
            $inv = $this->loadInvoice($supplierId, (int) $tx['matched_invoice_id']);
            if ($inv !== null && (string) $inv['status'] === 'paid') {
                throw new PostingException('already_paid_verify', 'Faktura je označená jako uhrazená bez evidované platby.');
            }
            // Částku doplní až konkrétní větev (stejná měna = plná částka pohybu, CZK faktura = zbytek dluhu).
            $allocations = [['invoice_id' => (int) $tx['matched_invoice_id'], 'amount' => null]];
            $usedFallback = true;
        }
        if ($allocations === []) {
            throw new PostingException('document_not_posted', 'Spárovaná příchozí cizoměnová platba nemá alokaci na fakturu.');
        }

        // Křížová měna: měna transakce ≠ měna faktury. Čistý případ „CZK faktura uhrazená přes
        // cizoměnový účet" (reálné inkaso AVYX — EUR na korunovou fakturu; saldokonto nativně
        // v CZK, banka v cizí měně) se zaúčtuje automaticky. Dvojí měnová konverze (faktura v
        // jiné cizí měně než úhrada) nebo směs měn → ruční ověření (blokovaný návrh).
        $class = $this->classifyIncomingFxCurrency($supplierId, $allocations, $currency);
        if ($class === 'czk_invoice') {
            return $this->buildIncomingCrossCurrencyFx($supplierId, $tx, $allocations, $currency, $absForeign, $usedFallback);
        }
        if ($class === 'cross_currency') {
            throw new PostingException('cross_currency', 'Křížovou měnu (cizí úhrada × jiná cizí měna faktury) nelze zaúčtovat automaticky.');
        }

        $rule = $this->postingRules->resolve($supplierId, 'payment.receivable.bank');
        $bankAcc = $rule['debit_account_code'] ?? '221';
        $receivable = $rule['credit_account_code'] ?? '311';

        $paymentRate = $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
        $bankCzk = round($absForeign * $paymentRate, 2);
        $lines = [$this->line($bankAcc, 'debit', $bankCzk)];

        $allocForeignSum = 0.0;
        foreach ($allocations as $a) {
            $invId = (int) $a['invoice_id'];
            // Fallback (bez invoice_payments) u stejnoměnové úhrady = plná částka pohybu.
            $allocForeign = $usedFallback ? $absForeign : round((float) $a['amount'], 2);
            $allocForeignSum += $allocForeign;
            [$counter, $fxRate] = $this->incomingFxSaldo($supplierId, $invId, $receivable, $currency);
            $lines[] = $this->withFxTrace(
                $this->line($counter, 'credit', round($allocForeign * $fxRate, 2)),
                $currency, $fxRate, $allocForeign,
            );
        }

        $this->assertFxAllocation($absForeign, round($allocForeignSum, 2));
        $remainderCzk = round(($absForeign - round($allocForeignSum, 2)) * $paymentRate, 2);
        $this->appendFxDifference($lines, $supplierId, $remainderCzk, true);
        return ['lines' => $lines];
    }

    /**
     * Klasifikace měny alokací příchozí cizoměnové úhrady vůči měně transakce:
     *   - 'same_foreign'   — všechny faktury v měně transakce (standardní B6),
     *   - 'czk_invoice'    — všechny faktury CZK (korunová faktura uhrazená cizí měnou),
     *   - 'cross_currency' — směs, nebo faktura v jiné cizí měně než úhrada (dvojí konverze)
     *                        → ruční ověření.
     *
     * @param list<array<string,mixed>> $allocations
     */
    private function classifyIncomingFxCurrency(int $supplierId, array $allocations, string $txCurrency): string
    {
        $sawSameForeign = false;
        $sawCzk = false;
        $sawOther = false;
        foreach ($allocations as $a) {
            $inv = $this->loadInvoice($supplierId, (int) $a['invoice_id']);
            if ($inv === null) {
                // Neexistující fakturu vyřeší až incomingFxSaldo (document_not_posted).
                $sawSameForeign = true;
                continue;
            }
            $c = strtoupper((string) $inv['currency']);
            if ($c === strtoupper($txCurrency)) {
                $sawSameForeign = true;
            } elseif ($c === 'CZK') {
                $sawCzk = true;
            } else {
                $sawOther = true;
            }
        }
        if ($sawOther || ($sawCzk && $sawSameForeign)) {
            return 'cross_currency';
        }
        return $sawCzk ? 'czk_invoice' : 'same_foreign';
    }

    /**
     * „CZK faktura uhrazená přes cizoměnový účet" (reálný případ AVYX — EUR inkaso na korunovou
     * fakturu 2409012). Saldokonto 311 se odúčtuje v NOMINÁLNÍ CZK hodnotě faktury (nativní,
     * bez kurzu), banka 221 v CZK hodnotě skutečné cizoměnové úhrady (cizí částka × kurz ČNB
     * dne banky), rozdíl → 563 MD (ztráta) / 663 D (zisk). Jediná noha reálně v cizí měně je
     * banka — nese cizoměnovou stopu (§4/12), symetrie s ručním zápisem i {@see buildOutgoingCzkCardFx}.
     *
     * @param array<string,mixed> $tx
     * @param list<array<string,mixed>> $allocations
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildIncomingCrossCurrencyFx(
        int $supplierId,
        array $tx,
        array $allocations,
        string $currency,
        float $absForeign,
        bool $usedFallback,
    ): array {
        $rule = $this->postingRules->resolve($supplierId, 'payment.receivable.bank');
        $bankAcc = $rule['debit_account_code'] ?? '221';
        $receivable = $rule['credit_account_code'] ?? '311';

        $paymentRate = $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
        $bankCzk = round($absForeign * $paymentRate, 2);
        $lines = [$this->withFxTrace(
            $this->line($bankAcc, 'debit', $bankCzk),
            $currency, $paymentRate, $absForeign,
        )];

        $settledCzk = 0.0;
        foreach ($allocations as $a) {
            [$counter, $allocCzk] = $this->incomingCzkSaldo(
                $supplierId,
                (int) $a['invoice_id'],
                $receivable,
                $usedFallback ? null : ($a['amount'] ?? null),
            );
            $settledCzk += $allocCzk;
            // Saldokonto korunové faktury je nativně v CZK — žádná cizoměnová stopa.
            $lines[] = $this->line($counter, 'credit', $allocCzk);
        }

        // Pojistka: kurzový rozdíl u korunové faktury nesmí přesáhnout rozumnou odchylku od
        // skutečně inkasované částky — jinak jde spíš o špatně spárovaný doklad/přeplatek než
        // o kurzový rozdíl → ruční ověření (blokovaný návrh).
        $this->assertCrossCurrencySane($bankCzk, round($settledCzk, 2));
        $this->appendFxDifference($lines, $supplierId, 0.0, true);
        return ['lines' => $lines];
    }

    /**
     * Saldokonto + CZK částka k odúčtování pro korunovou fakturu hrazenou cizí měnou. Guard H1
     * (zaúčtovaný nestornovaný předpis) jako u {@see incomingFxSaldo}. Proforma → ruční ověření.
     * CZK částka = evidovaná platba v měně faktury (CZK); fallback (bez evidované platby) = zbytek
     * dluhu faktury (amount_to_pay − paid_total).
     *
     * @return array{0:string, 1:float} [receivable account, CZK částka salda]
     */
    private function incomingCzkSaldo(int $supplierId, int $invoiceId, string $receivable, mixed $allocAmount): array
    {
        $inv = $this->loadInvoice($supplierId, $invoiceId);
        if ($inv === null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' neexistuje.');
        }
        if (strtoupper((string) $inv['currency']) !== 'CZK') {
            throw new PostingException('cross_currency', 'Křížovou měnu faktury #' . $invoiceId . ' nelze zaúčtovat automaticky.');
        }
        if ((string) $inv['invoice_type'] === 'proforma') {
            throw new PostingException('cross_currency', 'Cizoměnová úhrada korunové proformy vyžaduje ruční ověření.');
        }
        $entry = $this->journal->findBySource($supplierId, 'invoice', $invoiceId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' nemá zaúčtovaný předpis.');
        }
        $allocCzk = $allocAmount !== null
            ? round((float) $allocAmount, 2)
            : round((float) ($inv['amount_to_pay'] ?? 0) - (float) ($inv['paid_total'] ?? 0), 2);
        if ($allocCzk <= 0.0) {
            throw new PostingException('allocation_mismatch', 'CZK částka úhrady faktury #' . $invoiceId . ' není kladná.');
        }
        return [$receivable, $allocCzk];
    }

    /**
     * Pojistka pro křížovou měnu korunové faktury: realizovaný kurzový rozdíl (|banka − saldo|)
     * nesmí přesáhnout {@see CROSS_FX_MAX_DEVIATION_PCT} inkasované částky. Nad ni jde spíš o
     * špatně spárovaný doklad/přeplatek než o legitimní kurzový pohyb → cross_currency (blokovaný
     * návrh k ručnímu ověření). Legitimní kurzový rozdíl (jednotky %) projde.
     */
    private const CROSS_FX_MAX_DEVIATION_PCT = 0.10;

    private function assertCrossCurrencySane(float $bankCzk, float $settledCzk): void
    {
        if ($settledCzk <= 0.0) {
            throw new PostingException('allocation_mismatch', 'Nulové saldo křížové úhrady.');
        }
        if (abs($bankCzk - $settledCzk) / $settledCzk > self::CROSS_FX_MAX_DEVIATION_PCT) {
            throw new PostingException(
                'cross_currency',
                'Kurzový rozdíl cizoměnové úhrady korunové faktury je nezvykle velký — vyžaduje ruční ověření.',
            );
        }
    }

    /**
     * Odchozí cizoměnová úhrada PF: saldokonto 321 se odúčtuje v CZK hodnotě předpisu,
     * banka 221 v CZK hodnotě skutečné úhrady (kurz ČNB dne banky), rozdíl → 563/663.
     * Symetrie k {@see buildIncomingMatchedFx}.
     *
     * @param array<string,mixed> $tx
     * @return array{lines:list<array{account_code:string, side:string, amount:float}>}
     */
    private function buildOutgoingMatchedFx(int $supplierId, array $tx): array
    {
        $txId = (int) $tx['id'];
        $currency = $this->effectiveCurrency($tx);
        $absForeign = round(abs((float) $tx['amount']), 2);

        $stmt = $this->db->pdo()->prepare(
            'SELECT purchase_invoice_id, amount FROM payment_matches
              WHERE bank_transaction_id = ? AND purchase_invoice_id IS NOT NULL'
        );
        $stmt->execute([$txId]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($allocations === []) {
            throw new PostingException('document_not_posted', 'Spárovaná odchozí cizoměnová platba nemá alokaci na přijatou fakturu.');
        }

        $rule = $this->postingRules->resolve($supplierId, 'payment.payable.bank');
        $payable = $rule['debit_account_code'] ?? '321';
        $bankAcc = $rule['credit_account_code'] ?? '221';

        $paymentRate = $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
        $bankCzk = round($absForeign * $paymentRate, 2);

        $lines = [];
        $allocForeignSum = 0.0;
        foreach ($allocations as $a) {
            $pfId = (int) $a['purchase_invoice_id'];
            $allocForeign = round((float) $a['amount'], 2);
            $allocForeignSum += $allocForeign;
            [$counter, $fxRate] = $this->outgoingFxSaldo($supplierId, $pfId, $payable, $currency);
            $lines[] = $this->withFxTrace(
                $this->line($counter, 'debit', round($allocForeign * $fxRate, 2)),
                $currency, $fxRate, $allocForeign,
            );
        }
        $lines[] = $this->line($bankAcc, 'credit', $bankCzk);

        $this->assertFxAllocation($absForeign, round($allocForeignSum, 2));
        $remainderCzk = round(($absForeign - round($allocForeignSum, 2)) * $paymentRate, 2);
        $this->appendFxDifference($lines, $supplierId, $remainderCzk, false);
        return ['lines' => $lines];
    }

    /**
     * Saldokonto + kurz předpisu pro cizoměnovou příchozí úhradu FV. Scope B6 (EUR faktura
     * placená EUR): měna faktury == měna transakce a faktura má ZAÚČTOVANÝ nestornovaný
     * předpis (guard H1). Proforma (cizoměnová záloha), CZK faktura placená cizí měnou i
     * křížová měna → fx_not_supported (skip, ruční zaúčtování).
     *
     * @return array{0:string, 1:float} [receivable account, kurz předpisu]
     */
    private function incomingFxSaldo(int $supplierId, int $invoiceId, string $receivable, string $txCurrency): array
    {
        $inv = $this->loadInvoice($supplierId, $invoiceId);
        if ($inv === null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' neexistuje.');
        }
        if ((string) $inv['currency'] === 'CZK' || (string) $inv['currency'] !== $txCurrency) {
            throw new PostingException('fx_not_supported', 'Měna úhrady neodpovídá měně faktury #' . $invoiceId . '.');
        }
        if ((string) $inv['invoice_type'] === 'proforma') {
            throw new PostingException('fx_not_supported', 'Cizoměnová záloha (proforma) se zatím neúčtuje automaticky.');
        }
        $entry = $this->journal->findBySource($supplierId, 'invoice', $invoiceId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Vydaná faktura #' . $invoiceId . ' nemá zaúčtovaný předpis.');
        }
        return [$receivable, $this->predpisFxRate($supplierId, (int) $entry['id'], $inv)];
    }

    /**
     * Saldokonto + kurz předpisu pro cizoměnovou odchozí úhradu PF (symetrie k
     * {@see incomingFxSaldo}). Zálohová PF (document_kind=advance) → fx_not_supported.
     *
     * @return array{0:string, 1:float} [payable account, kurz předpisu]
     */
    private function outgoingFxSaldo(int $supplierId, int $pfId, string $payable, string $txCurrency): array
    {
        $pf = $this->loadPurchase($supplierId, $pfId);
        if ($pf === null) {
            throw new PostingException('document_not_posted', 'Přijatá faktura #' . $pfId . ' neexistuje.');
        }
        if ((string) $pf['currency'] === 'CZK' || (string) $pf['currency'] !== $txCurrency) {
            // Korunová PF hrazená cizí měnou i dvojí konverze (jiná cizí měna) → ruční ověření
            // (blokovaný návrh), ne tichý skip. payment_matches.amount je v měně transakce, takže
            // CZK odúčtování závazku nelze spolehlivě odvodit — proto ne automaticky.
            throw new PostingException('cross_currency', 'Měna úhrady neodpovídá měně přijaté faktury #' . $pfId . '.');
        }
        if ((string) $pf['document_kind'] === 'advance') {
            throw new PostingException('fx_not_supported', 'Cizoměnová poskytnutá záloha se zatím neúčtuje automaticky.');
        }
        $entry = $this->journal->findBySource($supplierId, 'purchase_invoice', $pfId);
        if ($entry === null || ($entry['reversed_by'] ?? null) !== null) {
            throw new PostingException('document_not_posted', 'Přijatá faktura #' . $pfId . ' nemá zaúčtovaný předpis.');
        }
        return [$payable, $this->predpisFxRate($supplierId, (int) $entry['id'], $pf)];
    }

    /**
     * Kurz předpisu z cizoměnové stopy saldokontního řádku deníku (§35 — kurz zafixovaný
     * při zaúčtování, i kdyby se exchange_rate hlavičky dokladu později změnil). Fallback
     * na exchange_rate dokladu, když stopa chybí (předpis bez FX stopy).
     *
     * @param array<string,mixed> $doc
     */
    private function predpisFxRate(int $supplierId, int $entryId, array $doc): float
    {
        foreach ($this->journal->linesForEntry($entryId, $supplierId) as $l) {
            if ($l['currency_code'] !== null && $l['fx_rate'] !== null && (float) $l['fx_rate'] > 0) {
                return (float) $l['fx_rate'];
            }
        }
        $rate = isset($doc['exchange_rate']) ? (float) $doc['exchange_rate'] : 0.0;
        if ($rate > 0) {
            return $rate;
        }
        throw new PostingException('missing_exchange_rate', 'Předpis dokladu nemá kurz pro přepočet cizoměnové úhrady.');
    }

    /**
     * Doplní vyrovnávací řádek kurzového rozdílu: ΣMD − ΣD dosud sestavených řádků > 0
     * (banka přinesla víc / závazek byl vyšší v CZK) → kurzový ZISK 663 D, < 0 → kurzová
     * ZTRÁTA 563 MD. Nula (kurz úhrady == kurz předpisu) → žádný řádek. Účty z kontací
     * fx.gain/fx.loss (jako FxRevaluationService), bez stropu (kurzový rozdíl je legitimní).
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function appendFxDifference(array &$lines, int $supplierId, float $remainderCzk = 0.0, bool $incoming = true): void
    {
        $balance = PostingService::balanceCents($lines);
        $diff = $balance['debit'] - $balance['credit'];
        $remainderCents = (int) round(abs($remainderCzk) * 100.0);
        if ($remainderCents > 0) {
            $lines[] = $incoming
                ? $this->line('648', 'credit', $remainderCents / 100.0)
                : $this->line('548', 'debit', $remainderCents / 100.0);
            $diff += $incoming ? -$remainderCents : $remainderCents;
        }
        if ($diff === 0) {
            return;
        }
        [$loss, $gain] = $this->fxResultAccounts($supplierId);
        if ($diff > 0) {
            $lines[] = $this->line($gain, 'credit', $diff / 100.0);
        } else {
            $lines[] = $this->line($loss, 'debit', -$diff / 100.0);
        }
    }

    /**
     * Účty kurzového rozdílu z kontací fx.loss (MD 563) / fx.gain (D 663). Protiúčet je
     * dynamický (saldokonto/banka daného případu), proto seed nese jen jednu stranu.
     *
     * @return array{0:string, 1:string} [loss, gain]
     */
    private function fxResultAccounts(int $supplierId): array
    {
        $lossRule = $this->postingRules->resolve($supplierId, 'fx.loss');
        $gainRule = $this->postingRules->resolve($supplierId, 'fx.gain');
        $loss = ($lossRule['debit_account_code'] ?? null) ?: '563';
        $gain = ($gainRule['credit_account_code'] ?? null) ?: '663';
        return [(string) $loss, (string) $gain];
    }

    /**
     * Nealokovaný zbytek cizoměnové platby nad toleranci = přeplatek/nedoplatek (ne
     * kurzový rozdíl) → allocation_mismatch. Bez toho by se zbytek cizí měny tiše zavlekl
     * do kurzového rozdílu 563/663.
     */
    private function assertFxAllocation(float $absForeign, float $allocForeignSum): void
    {
        $diffCents = (int) round(($absForeign - $allocForeignSum) * 100.0);
        if (abs($diffCents) > self::FX_ALLOCATION_TOLERANCE) {
            throw new PostingException('allocation_mismatch', 'Alokace cizoměnové platby se liší od částky transakce o víc než toleranci.');
        }
    }

    /**
     * Doplní saldokontnímu řádku cizoměnovou stopu (§4/12 — souběžné vedení v cizí měně;
     * podklad pro přecenění §24/6). amount zůstává v CZK (hodnota předpisu).
     *
     * @param array{account_code:string, side:string, amount:float} $line
     * @return array{account_code:string, side:string, amount:float, currency_code:string, fx_rate:float, amount_foreign:float}
     */
    private function withFxTrace(array $line, string $currency, float $fxRate, float $foreign): array
    {
        $line['currency_code'] = $currency;
        $line['fx_rate'] = $fxRate;
        $line['amount_foreign'] = round($foreign, 2);
        return $line;
    }

    /**
     * Memo kurzů v rámci requestu, klíč supplier|měna|den. Fronta nezaúčtovaných pohybů se
     * ptá řádek po řádku; bez memo by stejný den+měna sahal na DB (a při cache miss až na
     * HTTP ČNB) tolikrát, kolik je pohybů.
     *
     * @var array<string, float>
     */
    private array $paymentRateMemo = [];

    /** Kurz úhrady podle kurzového režimu firmy; v daily režimu ČNB. */
    private function paymentRateForDay(int $supplierId, string $currency, string $date): float
    {
        $key = $supplierId . '|' . $currency . '|' . $date;
        if (isset($this->paymentRateMemo[$key])) {
            return $this->paymentRateMemo[$key];
        }
        $day = new \DateTimeImmutable($date);
        $fixed = $this->fixedRates->resolve($supplierId, $currency, $day);
        if ($fixed !== null && (float) $fixed['rate'] > 0) {
            return $this->paymentRateMemo[$key] = (float) $fixed['rate'];
        }
        $info = $this->cnb->getRate($currency, $day);
        if ($info === null || (float) $info['rate'] <= 0) {
            throw new PostingException('fx_rate_unavailable', 'Kurz ČNB pro měnu ' . $currency . ' k ' . $date . ' není k dispozici.');
        }
        return $this->paymentRateMemo[$key] = (float) $info['rate'];
    }

    /**
     * Kurz pro přepočet pohybu na CZK pro čtecí vrstvu (fronta pohybů → předvyplnění
     * rozúčtování v UI). Schválně sdílí {@see paymentRateForDay} s zaúčtováním: kdyby
     * fronta počítala kurz vlastním SQL nad `exchange_rates`, ignorovala by pevný kurz
     * firmy (§24/7) a UI by ukázalo jiný kurz, než jakým se pohyb nakonec zaúčtuje.
     *
     * CZK → 1.0; chybějící kurz → null (výpis pohybů se kvůli tomu nesmí rozbít, volající
     * jen nenabídne přepočet).
     */
    public function czkRateFor(int $supplierId, ?string $currency, string $date): ?float
    {
        $code = strtoupper(trim((string) $currency));
        if ($code === '' || $code === 'CZK') {
            return 1.0;
        }
        try {
            return $this->paymentRateForDay($supplierId, $code, $date);
        } catch (PostingException) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{action:string, reason:string, suggestion_id:int}
     */
    private function paymentMatchSuggestion(int $supplierId, array $tx, string $note): array
    {
        $rule = $this->postingRules->resolve($supplierId, (float) $tx['amount'] > 0 ? 'payment.receivable.bank' : 'payment.payable.bank');
        $debit = $rule['debit_account_code'] ?? ((float) $tx['amount'] > 0 ? '221' : '321');
        $credit = $rule['credit_account_code'] ?? ((float) $tx['amount'] > 0 ? '311' : '221');
        $res = $this->suggestions->createIfNoPending([
            'supplier_id'         => $supplierId,
            'bank_transaction_id' => (int) $tx['id'],
            'rule_id'             => null,
            'source'              => 'payment_match',
            'debit_account_code'  => $debit,
            'credit_account_code' => $credit,
            'amount'              => round(abs((float) $tx['amount']), 2),
            'description'         => $this->entryDescription($tx),
            'note'                => $note,
            'confidence'          => 1.00,
            'operation_type'      => OperationType::BANK_PAYMENT_MATCHED,
            'status'              => in_array($note, ['period_closed', 'cross_currency'], true)
                ? 'blocked' : ($note === 'already_paid_verify' ? 'needs_input' : 'pending'),
        ]);
        return ['action' => 'suggested', 'reason' => $note, 'suggestion_id' => $res['id']];
    }

    // ── nespárované transakce → pravidla + učení ────────────────────────────────

    /**
     * Backfill degraduje auto pravidla na suggest ($suggestOnly = true, §7): auto se
     * při dávkovém běhu nikdy nezaúčtuje samo, jen navrhne.
     *
     * @return array{action:string, reason?:string, entry_id?:int, suggestion_id?:int}
     */
    public function applyRules(int $supplierId, int $txId, ?int $userId = null, bool $suggestOnly = false): array
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            return ['action' => 'skipped', 'reason' => 'transaction_not_found'];
        }
        $amount = (float) $tx['amount'];
        $absCents = (int) round(abs($amount) * 100.0);
        if ($absCents === 0) {
            return ['action' => 'skipped', 'reason' => 'zero_amount'];
        }
        $direction = $amount > 0 ? 'incoming' : 'outgoing';

        $matchTx = $this->matchTxArray($tx);
        $matches = [];
        foreach ($this->rules->findActive($supplierId, $direction) as $rule) {
            if (strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) !== strtoupper($this->effectiveCurrency($tx))) {
                continue;
            }
            if ($this->suggestions->hasRejected($supplierId, $txId, (int) $rule['id'])) {
                continue; // M3a — odmítnuté (tx, rule) už nenabízet
            }
            if ($this->ruleMatcher->matching($rule, $matchTx)) {
                $matches[] = $rule;
            }
        }

        if (count($matches) === 1
            || (count($matches) >= 2 && (int) $matches[0]['priority'] < (int) $matches[1]['priority'])) {
            return $this->applySingleRule($supplierId, $tx, $matches[0], $userId, $suggestOnly);
        }
        if (count($matches) >= 2) {
            // Stejná nejvyšší priorita → vítěz dle hit_count, ale nikdy auto.
            return $this->ruleSuggestion($supplierId, $tx, $matches[0], 'rule_conflict');
        }

        // 0 matchů → learned detekce.
        $learned = $this->findLearnedKontace($supplierId, $tx);
        if ($learned !== null) {
            $res = $this->suggestions->createIfNoPending([
                'supplier_id'         => $supplierId,
                'bank_transaction_id' => $txId,
                'rule_id'             => null,
                'source'              => 'learned',
                'debit_account_code'  => $learned['debit_account_code'],
                'credit_account_code' => $learned['credit_account_code'],
                'amount'              => round(abs($amount), 2),
                'description'         => $this->entryDescription($tx),
                'note'                => ($learned['corrected'] ? 'corrected_from:#' : 'looks_like:#') . $learned['source_tx_id'],
                'confidence'          => 0.50,
                'operation_type'      => OperationType::BANK_LEARNED,
            ]);
            return ['action' => 'suggested', 'reason' => 'learned', 'suggestion_id' => $res['id']];
        }

        if ($this->aiSuggestions?->enqueueBank($supplierId, $txId)) {
            return ['action' => 'skipped', 'reason' => 'ai_queued'];
        }
        return ['action' => 'skipped', 'reason' => 'no_rule'];
    }

    /**
     * Použije při backfillu výhradně zadané pravidlo. Volající předává transakci
     * vybranou tenantovým dotazem; i zde se znovu ověří stav, směr, měna a shoda.
     *
     * @return array{action:string, reason?:string, suggestion_id?:int, created?:bool}
     */
    public function suggestRuleForBackfill(
        int $supplierId,
        int $txId,
        int $ruleId,
        ?int $userId = null,
    ): array {
        $rule = $this->rules->find($supplierId, $ruleId);
        if ($rule === null) {
            return ['action' => 'skipped', 'reason' => 'rule_not_found'];
        }
        if (!(bool) $rule['is_active']) {
            return ['action' => 'skipped', 'reason' => 'rule_inactive'];
        }

        $tx = $this->loadTx($txId);
        if ($tx === null || (int) ($tx['statement_supplier_id'] ?? 0) !== $supplierId) {
            return ['action' => 'skipped', 'reason' => 'transaction_not_found'];
        }
        $amount = (float) $tx['amount'];
        $direction = $amount > 0 ? 'incoming' : 'outgoing';
        if ($amount === 0.0 || $direction !== (string) $rule['direction']) {
            return ['action' => 'skipped', 'reason' => 'rule_not_matched'];
        }
        if (strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) !== strtoupper($this->effectiveCurrency($tx))) {
            return ['action' => 'skipped', 'reason' => 'rule_not_matched'];
        }
        $entry = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($entry !== null && ($entry['reversed_by'] ?? null) === null) {
            return ['action' => 'skipped', 'reason' => 'already_posted'];
        }
        if ($this->suggestions->hasRejected($supplierId, $txId, $ruleId)) {
            return ['action' => 'skipped', 'reason' => 'rule_rejected'];
        }
        if (!$this->ruleMatcher->matching($rule, $this->matchTxArray($tx))) {
            return ['action' => 'skipped', 'reason' => 'rule_not_matched'];
        }
        if ($this->transfers !== null && $this->transfers->detectTransaction($supplierId, $txId) !== null) {
            return $this->transfers->handle($supplierId, $tx, $userId, true)
                ?? ['action' => 'skipped', 'reason' => 'rule_not_matched'];
        }

        // P1.9: backfill je explicitní žádost „přepočítat návrhy pro tohle pravidlo" — starší
        // pending návrh jiného původu (jiné/starší pravidlo, learned detekce) by createIfNoPending()
        // v ruleSuggestion() jinak tiše zablokoval a pravidlo by se na tuhle transakci nikdy
        // neuplatnilo (stalo se u odvodů VZP). Vlastní pending od téhož pravidla necháme být.
        $existingPending = $this->suggestions->pendingForTx($supplierId, $txId);
        if ($existingPending !== null && (int) ($existingPending['rule_id'] ?? 0) !== $ruleId) {
            $this->suggestions->supersedePendingForTx($supplierId, $txId, 'superseded_by_rule_backfill');
        }

        return $this->applySingleRule($supplierId, $tx, $rule, $userId, true);
    }

    /** @return array{action:string,reason?:string,entry_id?:int,suggestion_id?:int} */
    private function applyDetection(
        int $supplierId,
        array $tx,
        DetectionResult $detected,
        ?int $userId,
        bool $suggestOnly,
    ): array {
        if ($this->policy === null) {
            return ['action' => 'skipped', 'reason' => 'policy_unavailable'];
        }
        $txId = (int) $tx['id'];
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
            return ['action' => 'skipped', 'reason' => 'already_posted'];
        }
        $amount = round(abs((float) $tx['amount']), 2);
        $anomaly = $this->hasAnomaly($supplierId, $tx);
        $decision = $this->policy->decide($supplierId, new PolicyInput(
            $detected->operationType,
            $detected->source,
            $detected->confidence,
            $amount,
            'CZK',
            (string) $tx['posted_at'],
            $detected->debitAccountCode,
            $detected->creditAccountCode,
            detectorKey: $detected->detectorKey,
            autoAllowed: $detected->autoAllowed,
            anomaly: $anomaly,
        ));
        $kind = $suggestOnly && $decision->decision === 'auto' ? 'suggest' : $decision->decision;
        $note = $decision->note ?? $detected->note;
        if ($kind === 'skip') {
            return ['action' => 'skipped', 'reason' => 'detector_off'];
        }
        if ($kind === 'auto') {
            $pdo = $this->db->pdo();
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                $entryId = $this->posting->postDocument($supplierId, 'bank', $txId, $this->withBankAnalytic($supplierId, $tx, [
                    $this->line($detected->debitAccountCode, 'debit', $amount),
                    $this->line($detected->creditAccountCode, 'credit', $amount),
                ]), [
                    'entry_date' => (string) $tx['posted_at'],
                    'document_date' => (string) $tx['posted_at'],
                    'document_no' => $this->documentNo($tx),
                    'description' => $detected->description ?: $this->entryDescription($tx),
                    'posted' => true,
                    'user_id' => $userId,
                    'posted_by' => $userId,
                ]);
                $this->markSchedulePaid($supplierId, $detected->scheduleId, $tx);
                $this->suggestions->supersedePendingForTx($supplierId, $txId, 'overwritten_by_auto');
                $suggestionId = $this->suggestions->createAutoPosted([
                    'supplier_id' => $supplierId,
                    'bank_transaction_id' => $txId,
                    'rule_id' => null,
                    'source' => $detected->source,
                    'debit_account_code' => $detected->debitAccountCode,
                    'credit_account_code' => $detected->creditAccountCode,
                    'amount' => $amount,
                    'description' => $detected->description ?: $this->entryDescription($tx),
                    'journal_entry_id' => $entryId,
                    'confidence' => $detected->confidence,
                    'detector' => $detected->detectorKey,
                    'operation_type' => $detected->operationType,
                    'tax_advance_schedule_id' => $detected->scheduleId,
                    'note' => $note,
                ]);
                if ($ownTx) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            return ['action' => 'posted', 'reason' => 'detector', 'entry_id' => $entryId, 'suggestion_id' => $suggestionId];
        }
        $status = in_array($kind, ['needs_input', 'blocked'], true) ? $kind : 'pending';
        $pending = $this->suggestions->pendingForTx($supplierId, $txId);
        if ($pending !== null && (
            (string) $pending['source'] !== $detected->source
            || (string) ($pending['detector'] ?? '') !== $detected->detectorKey
            || (string) ($pending['operation_type'] ?? '') !== $detected->operationType
            || (int) ($pending['tax_advance_schedule_id'] ?? 0) !== (int) ($detected->scheduleId ?? 0)
            || (string) $pending['debit_account_code'] !== $detected->debitAccountCode
            || (string) $pending['credit_account_code'] !== $detected->creditAccountCode
            || abs((float) $pending['amount'] - $amount) > 0.00001
            || abs((float) ($pending['confidence'] ?? 0) - $detected->confidence) > 0.00001
            || (string) ($pending['status'] ?? '') !== $status
            || (string) ($pending['note'] ?? '') !== (string) ($note ?? '')
        )) {
            $this->suggestions->supersedePendingForTx($supplierId, $txId, 'overwritten_by_detector');
        }
        $result = $this->suggestions->createIfNoPending([
            'supplier_id' => $supplierId,
            'bank_transaction_id' => $txId,
            'rule_id' => null,
            'source' => $detected->source,
            'debit_account_code' => $detected->debitAccountCode,
            'credit_account_code' => $detected->creditAccountCode,
            'amount' => $amount,
            'description' => $detected->description ?: $this->entryDescription($tx),
            'status' => $status,
            'note' => $note,
            'confidence' => $detected->confidence,
            'detector' => $detected->detectorKey,
            'operation_type' => $detected->operationType,
            'tax_advance_schedule_id' => $detected->scheduleId,
        ]);
        if ($anomaly && $result['created']) {
            $this->anomalies?->recordDegraded($supplierId);
        }
        return ['action' => 'suggested', 'reason' => $note ?? 'detector', 'suggestion_id' => $result['id']];
    }

    /**
     * @param array<string,mixed> $tx
     * @param array<string,mixed> $rule
     * @return array{action:string, reason?:string, entry_id?:int, suggestion_id?:int}
     */
    private function applySingleRule(int $supplierId, array $tx, array $rule, ?int $userId, bool $suggestOnly = false): array
    {
        $foreignAmount = round(abs((float) $tx['amount']), 2);
        $currency = strtoupper($this->effectiveCurrency($tx));
        $fxRate = $currency === 'CZK' ? null : $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
        $absAmount = $fxRate === null ? $foreignAmount : round($foreignAmount * $fxRate, 2);
        $operationType = (string) (($rule['operation_type'] ?? null) ?: OperationType::BANK_RULE_CUSTOM);
        $anomaly = $this->hasAnomaly($supplierId, $tx);
        $decision = $this->policy?->decide($supplierId, new PolicyInput(
            $operationType,
            'rule',
            0.90,
            $absAmount,
            $currency,
            (string) $tx['posted_at'],
            (string) $rule['debit_account_code'],
            (string) $rule['credit_account_code'],
            rule: $rule,
            anomaly: $anomaly,
        ));
        $decisionKind = $decision?->decision ?? ((string) $rule['mode'] === 'auto' ? 'auto' : 'suggest');
        if ($suggestOnly && $decisionKind === 'auto') {
            $decisionKind = 'suggest';
        }
        $autoEligible = $decisionKind === 'auto';

        if ($autoEligible) {
            $postedAt = (string) $tx['posted_at'];
            $period = $this->periods->ensureOpenPeriodFor($supplierId, $postedAt);
            if (($period['status'] ?? 'open') !== 'open') {
                return $this->ruleSuggestion($supplierId, $tx, $rule, 'period_closed', 'blocked', $absAmount);
            }
            try {
                $entryId = $this->posting->postDocument($supplierId, 'bank', (int) $tx['id'], $this->withBankAnalytic($supplierId, $tx, [
                    $fxRate === null
                        ? $this->line((string) $rule['debit_account_code'], 'debit', $absAmount)
                        : $this->withFxTrace($this->line((string) $rule['debit_account_code'], 'debit', $absAmount), $currency, $fxRate, $foreignAmount),
                    $fxRate === null
                        ? $this->line((string) $rule['credit_account_code'], 'credit', $absAmount)
                        : $this->withFxTrace($this->line((string) $rule['credit_account_code'], 'credit', $absAmount), $currency, $fxRate, $foreignAmount),
                ]), [
                    'entry_date'    => $postedAt,
                    'document_date' => $postedAt,
                    'document_no'   => $this->documentNo($tx),
                    'description'   => (string) ($rule['description'] ?? '') !== '' ? (string) $rule['description'] : $this->entryDescription($tx),
                    'posted'        => true,
                    'user_id'       => $userId,
                    'posted_by'     => $userId,
                ]);
            } catch (PostingException $e) {
                if (in_array($e->errorCode, ['period_not_open', 'no_accounting_period', 'entry_reversed'], true)) {
                    return $this->ruleSuggestion($supplierId, $tx, $rule, 'period_closed', 'blocked', $absAmount);
                }
                throw $e;
            }
            // Starší pending návrhy téže tx (learned / suggest éra pravidla) by po auto-postu
            // osiřely a jejich Odmítnout by falešně penalizovalo funkční pravidlo (R7).
            $this->suggestions->supersedePendingForTx($supplierId, (int) $tx['id'], 'overwritten_by_auto');
            $suggestionId = $this->suggestions->createAutoPosted([
                'supplier_id'         => $supplierId,
                'bank_transaction_id' => (int) $tx['id'],
                'rule_id'             => (int) $rule['id'],
                'source'              => 'rule',
                'debit_account_code'  => (string) $rule['debit_account_code'],
                'credit_account_code' => (string) $rule['credit_account_code'],
                'amount'              => $absAmount,
                'description'         => $this->entryDescription($tx),
                'journal_entry_id'    => $entryId,
                'confidence'          => 0.90,
                'operation_type'      => $operationType,
            ]);
            $this->rules->recordHit((int) $rule['id']);
            $this->activity->log('bank_rule.auto_posted', $userId, 'bank_transaction', (int) $tx['id'],
                ['rule_id' => (int) $rule['id'], 'entry_id' => $entryId]);
            return ['action' => 'posted', 'reason' => 'auto_rule', 'entry_id' => $entryId, 'suggestion_id' => $suggestionId];
        }

        if ($decisionKind === 'skip') {
            return ['action' => 'skipped', 'reason' => 'policy_off'];
        }
        $result = $this->ruleSuggestion(
            $supplierId,
            $tx,
            $rule,
            $decision?->note,
            in_array($decisionKind, ['needs_input', 'blocked'], true) ? $decisionKind : 'pending',
            $absAmount,
        );
        if ($anomaly) {
            $this->anomalies?->recordDegraded($supplierId);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $tx
     * @param array<string,mixed> $rule
     * @return array{action:string, reason:string, suggestion_id:int, created:bool}
     */
    private function ruleSuggestion(
        int $supplierId,
        array $tx,
        array $rule,
        ?string $note,
        string $status = 'pending',
        ?float $amount = null,
    ): array
    {
        $res = $this->suggestions->createIfNoPending([
            'supplier_id'         => $supplierId,
            'bank_transaction_id' => (int) $tx['id'],
            'rule_id'             => (int) $rule['id'],
            'source'              => 'rule',
            'debit_account_code'  => (string) $rule['debit_account_code'],
            'credit_account_code' => (string) $rule['credit_account_code'],
            'amount'              => $amount ?? round(abs((float) $tx['amount']), 2),
            'description'         => $this->entryDescription($tx),
            'note'                => $note,
            'status'              => $status,
            'confidence'          => (string) ($rule['mode'] ?? 'suggest') === 'auto' ? 0.90 : 0.70,
            'operation_type'      => (string) (($rule['operation_type'] ?? null) ?: OperationType::BANK_RULE_CUSTOM),
        ]);
        return [
            'action' => 'suggested',
            'reason' => $note ?? 'rule',
            'suggestion_id' => $res['id'],
            'created' => $res['created'],
        ];
    }

    // ── approve / reject / postManual / unpost ──────────────────────────────────

    /**
     * @param array{user_id?:?int, posted_by?:?int, ip?:?string, user_agent?:?string} $meta
     * @param array{debit_account_code?:string, credit_account_code?:string} $overrides
     */
    /**
     * Čistý plán účetního dopadu návrhu. Provádí stejné účetní guardy jako approve,
     * ale nevytváří zápis ani nemění stav návrhu.
     *
     * @return array{suggestion_id:int,bank_transaction_id:int,currency:string,lines:list<array<string,mixed>>}
     */
    public function previewSuggestion(int $supplierId, int $suggestionId): array
    {
        $sug = $this->suggestions->find($supplierId, $suggestionId);
        if ($sug === null) {
            throw new PostingException('not_found', 'Návrh nenalezen.', 404);
        }
        if ((string) $sug['status'] !== 'pending') {
            throw new PostingException('suggestion_not_pending', 'Návrh už byl vyřízen.', 409);
        }
        if (in_array((string) $sug['source'], BankPostingSuggestionRepository::AI_SOURCES, true)) {
            throw new PostingException('ai_bulk_forbidden', 'AI návrhy nelze zpracovat hromadně.', 422);
        }

        $txId = (int) $sug['bank_transaction_id'];
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Transakce nenalezena.', 404);
        }
        $source = (string) $sug['source'];
        $currency = strtoupper($this->effectiveCurrency($tx));
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
            throw new PostingException('already_posted', 'Transakce už je zaúčtovaná.', 409);
        }

        if (in_array($source, ['rule', 'learned'], true) && $this->transfers !== null) {
            $transfer = $this->transfers->detectTransaction($supplierId, $txId);
            if ($transfer !== null && $transfer['cross_currency']) {
                throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
            }
            if ($transfer !== null && !$this->transfers->matchesDetectedPosting($supplierId, $sug)) {
                throw new PostingException('suggestion_replaced', 'Návrh musí být nahrazen rozpoznáním vlastního převodu.', 409);
            }
        }

        if ($source === 'payment_match') {
            $this->assertPostableTx($tx, true);
            $lines = (string) ($sug['note'] ?? '') === 'fee_gap'
                ? $this->buildIncomingFeeGap($supplierId, $tx, (float) $sug['amount'])['lines']
                : $this->buildMatched($supplierId, $tx)['lines'];
        } elseif ($source === 'transfer') {
            if ($this->transfers === null) {
                throw new PostingException('not_supported', 'Vlastní převody nejsou aktivní.', 409);
            }
            $transfer = $this->transfers->detectTransaction($supplierId, $txId);
            if ($transfer === null) {
                throw new PostingException('suggestion_replaced', 'Převod už nelze bezpečně rozpoznat.', 409);
            }
            if ($transfer['cross_currency']) {
                throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
            }
            $lines = [
                $this->line((string) $sug['debit_account_code'], 'debit', (float) $sug['amount']),
                $this->line((string) $sug['credit_account_code'], 'credit', (float) $sug['amount']),
            ];
        } else {
            $rule = $source === 'rule' && $sug['rule_id'] !== null
                ? $this->rules->find($supplierId, (int) $sug['rule_id']) : null;
            $isForeignRule = $currency !== 'CZK'
                && $rule !== null
                && strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) === $currency;
            $this->assertPostableTx($tx, $isForeignRule);
            $debit = (string) $sug['debit_account_code'];
            $credit = (string) $sug['credit_account_code'];
            $this->assertSaldoBlacklist((float) $tx['amount'], $debit, $credit);
            $foreignAmount = round(abs((float) $tx['amount']), 2);
            if ($isForeignRule) {
                $this->assertFxResultAccounts((float) $tx['amount'], $debit, $credit);
                $fxRate = $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
                $amount = round($foreignAmount * $fxRate, 2);
                $lines = [
                    $this->withFxTrace($this->line($debit, 'debit', $amount), $currency, $fxRate, $foreignAmount),
                    $this->withFxTrace($this->line($credit, 'credit', $amount), $currency, $fxRate, $foreignAmount),
                ];
            } else {
                $lines = [
                    $this->line($debit, 'debit', $foreignAmount),
                    $this->line($credit, 'credit', $foreignAmount),
                ];
            }
        }

        PostingService::assertBalanced($lines);
        $baseCurrency = $currency !== 'CZK' && array_filter($lines, static fn (array $line): bool => isset($line['currency_code']))
            ? 'CZK' : $currency;
        // Náhled dopadu musí ukazovat kontaci PO analytických přepisech — jinak uživatel
        // hromadně schvaluje `261/221`, kdežto do deníku spadne `261.100/221.400`.
        $resolved = true;
        foreach ($lines as $i => $line) {
            $preview = $this->previews?->code($supplierId, $tx, (string) $line['account_code']);
            if ($preview === null) {
                continue;
            }
            $lines[$i]['account_code'] = $preview['code'];
            $resolved = $resolved && $preview['resolved'];
        }
        return [
            'suggestion_id' => $suggestionId,
            'bank_transaction_id' => $txId,
            'currency' => $baseCurrency,
            'lines' => $lines,
            'accounts_resolved' => $resolved,
        ];
    }

    public function approveSuggestion(
        int $supplierId,
        int $suggestionId,
        array $meta,
        array $overrides = [],
        ?int $selectedRuleId = null,
    ): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $sug = $this->suggestions->findForUpdate($supplierId, $suggestionId);
            if ($sug === null) {
                throw new PostingException('not_found', 'Návrh nenalezen.', 404);
            }
            if (!in_array((string) $sug['status'], ['pending', 'needs_input', 'blocked'], true)) {
                throw new PostingException('suggestion_not_pending', 'Návrh už byl vyřízen.', 409);
            }
            if (in_array((string) $sug['source'], ['rule', 'learned'], true) && $this->transfers !== null) {
                $transfer = $this->transfers->detectTransaction($supplierId, (int) $sug['bank_transaction_id']);
                if ($transfer !== null) {
                    if ($transfer['cross_currency']) {
                        throw new PostingException('cross_currency_manual_only', 'Převod mezi měnami se účtuje ručně.', 409);
                    }
                    if ($overrides !== []) {
                        throw new PostingException('override_not_allowed', 'Kontaci vlastního převodu nelze přepsat.', 422);
                    }
                    if ($this->transfers->matchesDetectedPosting($supplierId, $sug)) {
                        $entryId = $this->transfers->approveLeg($supplierId, $sug, $meta);
                        if ($ownTx) {
                            $pdo->commit();
                        }
                        $this->enqueueBankEmbedding($supplierId, (int) $sug['bank_transaction_id']);
                        return $entryId;
                    }
                    $this->transfers->replaceForReview($supplierId, (int) $sug['bank_transaction_id']);
                    if ($ownTx) $pdo->commit();
                    throw new PostingException(
                        'suggestion_replaced',
                        'Návrh byl nahrazen rozpoznáním vlastního převodu; zkontrolujte nový návrh.',
                        409,
                    );
                }
            }
            if ((string) $sug['source'] === 'transfer') {
                if ($overrides !== []) {
                    throw new PostingException('override_not_allowed', 'Kontaci vlastního převodu nelze přepsat.', 422);
                }
                if ($this->transfers === null) {
                    throw new PostingException('not_supported', 'Vlastní převody nejsou aktivní.', 409);
                }
                $entryId = $this->transfers->approveLeg($supplierId, $sug, $meta);
                if ($ownTx) {
                    $pdo->commit();
                }
                $this->enqueueBankEmbedding($supplierId, (int) $sug['bank_transaction_id']);
                return $entryId;
            }
            $txId = (int) $sug['bank_transaction_id'];
            $tx = $this->loadTx($txId);
            if ($tx === null) {
                throw new PostingException('not_found', 'Transakce nenalezena.', 404);
            }
            $source = (string) $sug['source'];
            $currency = strtoupper($this->effectiveCurrency($tx));
            if ($selectedRuleId !== null) {
                $selectedRule = $this->rules->find($supplierId, $selectedRuleId);
                $direction = (float) $tx['amount'] > 0 ? 'incoming' : 'outgoing';
                if ((string) ($sug['note'] ?? '') !== 'rule_conflict'
                    || $selectedRule === null
                    || !(bool) $selectedRule['is_active']
                    || (string) $selectedRule['direction'] !== $direction
                    || !$this->ruleMatcher->matching($selectedRule, $this->matchTxArray($tx))) {
                    throw new PostingException('invalid_rule_selection', 'Vybrané pravidlo už této transakci neodpovídá.', 422);
                }
                $pdo->prepare(
                    'UPDATE bank_posting_suggestions
                        SET rule_id=?, source="rule", debit_account_code=?, credit_account_code=?, note=NULL
                      WHERE id=? AND supplier_id=?'
                )->execute([
                    $selectedRuleId,
                    (string) $selectedRule['debit_account_code'],
                    (string) $selectedRule['credit_account_code'],
                    $suggestionId,
                    $supplierId,
                ]);
                $sug['rule_id'] = $selectedRuleId;
                $sug['source'] = 'rule';
                $sug['debit_account_code'] = (string) $selectedRule['debit_account_code'];
                $sug['credit_account_code'] = (string) $selectedRule['credit_account_code'];
                $sug['note'] = null;
                $source = 'rule';
            }
            $rule = $source === 'rule' && $sug['rule_id'] !== null
                ? $this->rules->find($supplierId, (int) $sug['rule_id']) : null;
            $isForeignRule = $currency !== 'CZK'
                && $rule !== null
                && strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) === $currency;
            $this->assertPostableTx($tx, $source === 'payment_match' || $isForeignRule);
            $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
            if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
                throw new PostingException('already_posted', 'Transakce už je zaúčtovaná.', 409);
            }

            if ($source === 'payment_match') {
                if ($overrides !== []) {
                    throw new PostingException('override_not_allowed', 'Kontaci spárované platby nelze přepsat.', 422);
                }
                $lines = (string) ($sug['note'] ?? '') === 'fee_gap'
                    ? $this->buildIncomingFeeGap($supplierId, $tx, (float) $sug['amount'])['lines']
                    : $this->buildMatched($supplierId, $tx)['lines'];
            } else {
                $debit = $overrides['debit_account_code'] ?? (string) $sug['debit_account_code'];
                $credit = $overrides['credit_account_code'] ?? (string) $sug['credit_account_code'];
                $this->assertSaldoBlacklist((float) $tx['amount'], $debit, $credit);
                $foreignAmount = round(abs((float) $tx['amount']), 2);
                if ($isForeignRule) {
                    $this->assertFxResultAccounts((float) $tx['amount'], $debit, $credit);
                    $fxRate = $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
                    $absAmount = round($foreignAmount * $fxRate, 2);
                    $lines = [
                        $this->withFxTrace($this->line($debit, 'debit', $absAmount), $currency, $fxRate, $foreignAmount),
                        $this->withFxTrace($this->line($credit, 'credit', $absAmount), $currency, $fxRate, $foreignAmount),
                    ];
                } else {
                    $lines = [
                        $this->line($debit, 'debit', $foreignAmount),
                        $this->line($credit, 'credit', $foreignAmount),
                    ];
                }
            }

            $entryId = $this->posting->postDocument($supplierId, 'bank', $txId, $this->withBankAnalytic($supplierId, $tx, $lines), [
                'entry_date'    => (string) $tx['posted_at'],
                'document_date' => (string) $tx['posted_at'],
                'document_no'   => $this->documentNo($tx),
                'description'   => $this->entryDescription($tx),
                'posted'        => true,
                'user_id'       => $meta['user_id'] ?? null,
                'posted_by'     => $meta['posted_by'] ?? ($meta['user_id'] ?? null),
            ]);

            $this->suggestions->markApproved($supplierId, $suggestionId, $entryId, $meta['user_id'] ?? null);
            $this->markSchedulePaid($supplierId, $sug['tax_advance_schedule_id'] ?? null, $tx);
            $overridden = $overrides !== [];
            if ($overridden) {
                $this->corrections?->fromSuggestion(
                    $supplierId,
                    'approve_override',
                    $sug,
                    $debit,
                    $credit,
                    $meta['user_id'] ?? null,
                );
            }
            if ($rule !== null) {
                if ($this->promotion !== null) {
                    $this->promotion->onApprove($supplierId, $rule, !$overridden, $meta['user_id'] ?? null);
                } else {
                    $this->rules->recordHit((int) $sug['rule_id']);
                }
            }

            if ($ownTx) {
                $pdo->commit();
            }
            $this->recordAiDecision($supplierId, $source, $txId, $overridden ? 'overridden_count' : 'accepted_count');
            $this->enqueueBankEmbedding($supplierId, $txId);
            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array{user_id?:?int} $meta
     */
    public function rejectSuggestion(int $supplierId, int $suggestionId, array $meta, ?string $note = null): void
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $sug = $this->suggestions->findForUpdate($supplierId, $suggestionId);
            if ($sug === null) {
                throw new PostingException('not_found', 'Návrh nenalezen.', 404);
            }
            if (!in_array((string) $sug['status'], ['pending', 'needs_input', 'blocked'], true)) {
                throw new PostingException('suggestion_not_pending', 'Návrh už byl vyřízen.', 409);
            }
            $this->suggestions->markRejected($supplierId, $suggestionId, $note, $meta['user_id'] ?? null);
            $this->corrections?->fromSuggestion(
                $supplierId,
                'reject',
                $sug,
                null,
                null,
                $meta['user_id'] ?? null,
                $note,
            );
            if ($sug['rule_id'] !== null) {
                $res = $this->rules->recordReject((int) $sug['rule_id'], (int) $sug['bank_transaction_id']);
                if ($res['disabled']) {
                    $this->corrections?->ruleEvent(
                        $supplierId,
                        'rule_disabled',
                        (int) $sug['rule_id'],
                        $meta['user_id'] ?? null,
                        '3_rejects',
                    );
                    $this->activity->log('bank_rule.auto_disabled', $meta['user_id'] ?? null, 'bank_posting_rule',
                        (int) $sug['rule_id'], ['rejected_streak' => $res['streak']], supplierId: $supplierId);
                }
            }
            if ($ownTx) $pdo->commit();
            $this->recordAiDecision(
                $supplierId,
                (string) $sug['source'],
                (int) $sug['bank_transaction_id'],
                'rejected_count',
            );
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ruční zaúčtování z UI. Vrací entry + volitelně založené pravidlo + learned hint.
     *
     * Dva režimy:
     *  - `debit_account_code` + `credit_account_code` → dvouřádkový zápis, obě strany na částku
     *    pohybu (dosavadní chování, beze změny),
     *  - `lines[]` → libovolný počet řádků s vlastními částkami. Nutné všude, kde se částka řádku
     *    liší od částky pohybu: prodej cenných papírů (odúčtování pořizovací ceny ≠ tržba),
     *    kurzový rozdíl, rozúčtování jedné platby na víc účtů. Dvouřádkový režim to z principu
     *    neumí — obě strany tam dostanou tutéž částku.
     *
     * Cizí měna je povolená (na rozdíl od automatiky): dvouřádkový režim přepočte částku pohybu
     * kurzem ke dni platby sám, v `lines[]` zadává uživatel částky rovnou v CZK (kurz pro
     * předvyplnění bankovní nohy dostane z fronty pohybů — {@see czkRateFor}). Cizoměnovou stopu
     * dostane bankovní noha v obou režimech.
     *
     * @param array{debit_account_code?:string, credit_account_code?:string, description?:?string,
     *   lines?:list<array{account_code:string, side:string, amount:float|int|string}>,
     *   create_rule?:array<string,mixed>} $input
     * @param array{user_id?:?int, posted_by?:?int} $meta
     * @return array{entry_id:int, rule_id:?int, rule_hint:?array<string,mixed>}
     */
    public function postManual(int $supplierId, int $txId, array $input, array $meta): array
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            throw new PostingException('not_found', 'Transakce nenalezena.', 404);
        }
        // Tenant hranice: volající supplier je ZNÁMÝ (session), takže se vlastnictví
        // NEODVOZUJE z čísla účtu. resolveSupplierCandidates() je nástroj pro opačnou
        // úlohu (kdo je vlastník neznámé tx) a při kolizi account_canonical vrací víc
        // firem — `in_array` pak pustil kteroukoli z nich. Rozhoduje výhradně
        // {@see BankStatementOwnershipResolver}: bs.supplier_id, a jen u legacy výpisů
        // bez něj jednoznačná shoda účtu (fail-closed při dvou a více kandidátech).
        if (!$this->txOwnedBySupplier($txId, $supplierId)) {
            throw new PostingException('not_found', 'Transakce nepatří této firmě.', 404);
        }
        if ($this->supplierMode($supplierId) !== 'double_entry') {
            throw new PostingException('not_double_entry', 'Firma nevede podvojné účetnictví.');
        }
        if ((string) ($tx['source'] ?? 'statement') !== 'statement') {
            throw new PostingException('email_notice_provisional', 'Avízo se neúčtuje.');
        }
        // Ruční zaúčtování cizí měny povolené — automatika ji odmítá (pravidla pracují s CZK),
        // ale člověk s doklady v ruce ji zaúčtovat umí a jinak by pohyb visel navždy.
        $this->assertPostableTx($tx, allowForeign: true);
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
            throw new PostingException('already_posted', 'Transakce už je zaúčtovaná.', 409);
        }

        $signedAmount  = (float) $tx['amount'];
        $currency      = strtoupper($this->effectiveCurrency($tx));
        $foreignAmount = round(abs($signedAmount), 2);
        $fxRate        = $currency === 'CZK'
            ? null
            : $this->paymentRateForDay($supplierId, $currency, (string) $tx['posted_at']);
        $absAmount     = $fxRate === null ? $foreignAmount : round($foreignAmount * $fxRate, 2);

        $rawLines = $input['lines'] ?? null;
        if (is_array($rawLines) && $rawLines !== []) {
            // Pravidlo z rozúčtování nelze založit: multi-line smí saldokonta (viz manualLines),
            // pravidlo ne (H2) — jinak by se přes tuhle cestu dal blacklist obejít.
            if (isset($input['create_rule']) && is_array($input['create_rule']) && $input['create_rule'] !== []) {
                throw new PostingException('validation_failed',
                    'Z rozúčtování na víc řádků nelze založit pravidlo — pravidlo dává smysl jen u dvojice MD/D.');
            }
            $lines = $this->manualLines($rawLines, $signedAmount, $absAmount, $currency, $fxRate, $foreignAmount);
            [$debit, $credit] = $this->primaryPair($lines, $signedAmount);
        } else {
            $debit  = trim((string) ($input['debit_account_code'] ?? ''));
            $credit = trim((string) ($input['credit_account_code'] ?? ''));
            if ($debit === '' || $credit === '') {
                throw new PostingException('validation_failed', 'Zadej kontaci MD/D nebo řádky rozúčtování.');
            }
            // Saldokonto se tu — na rozdíl od pravidel (H2) — NEzakazuje. Zákaz mířil na
            // automatiku a do dvojice MD/D se dostal jen souběhem s R6 guardem; ruční
            // rozúčtování na víc řádků saldokonta odjakživa smí ({@see manualLines}) a
            // vzniká z něj TÝŽ zápis, takže zákaz nic nechránil — jen z nabídky vyřízl
            // celé 311/321/…, takže našeptávač po napsání „311" nenabídl ani 311.100.
            $this->assertBankSide($signedAmount, $debit, $credit);
            $lines = $fxRate === null
                ? [
                    $this->line($debit, 'debit', $absAmount),
                    $this->line($credit, 'credit', $absAmount),
                ]
                : [
                    $this->withFxTrace($this->line($debit, 'debit', $absAmount), $currency, $fxRate, $foreignAmount),
                    $this->withFxTrace($this->line($credit, 'credit', $absAmount), $currency, $fxRate, $foreignAmount),
                ];
        }

        $description = isset($input['description']) && trim((string) $input['description']) !== ''
            ? trim((string) $input['description'])
            : $this->entryDescription($tx);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $pending = $this->suggestions->pendingForTx($supplierId, $txId);
            // Bankovní noha patří na analytiku vlastního účtu výpisu (#35) — stejně jako
            // u automatiky i schvalování návrhu. Ruční zaúčtování jako jediné tenhle krok
            // vynechávalo, takže holé '221' z modalu skončilo na syntetice a rozbilo
            // jednoúčtovou (a tím jednoměnovou) analytiku, kterou zbytek systému udržuje.
            $entryId = $this->posting->postDocument($supplierId, 'bank', $txId, $this->withBankAnalytic($supplierId, $tx, $lines), [
                'entry_date'    => (string) $tx['posted_at'],
                'document_date' => (string) $tx['posted_at'],
                'document_no'   => $this->documentNo($tx),
                'description'   => $description,
                'posted'        => true,
                'user_id'       => $meta['user_id'] ?? null,
                'posted_by'     => $meta['posted_by'] ?? ($meta['user_id'] ?? null),
            ]);

            $ruleId = null;
            if (isset($input['create_rule']) && is_array($input['create_rule']) && $input['create_rule'] !== []) {
                $ruleId = $this->insertRuleFromPayload($supplierId, $input['create_rule'], $meta['user_id'] ?? null);
                $this->activity->log('bank_rule.created', $meta['user_id'] ?? null, 'bank_posting_rule', $ruleId,
                    ['via' => 'post_manual', 'bank_transaction_id' => $txId]);
            }

            // Existující pending suggestion tx → superseded.
            $this->suggestions->supersedePendingForTx($supplierId, $txId, 'superseded');
            if ($pending !== null) {
                $this->corrections?->fromSuggestion(
                    $supplierId,
                    'manual_post',
                    $pending,
                    $debit,
                    $credit,
                    $meta['user_id'] ?? null,
                );
            } else {
                $this->corrections?->record($supplierId, [
                    'event_type' => 'manual_post',
                    'entity_type' => 'bank_transaction',
                    'entity_id' => $txId,
                    'suggestion_source' => 'manual',
                    'final_debit' => $debit,
                    'final_credit' => $credit,
                    'amount' => $absAmount,
                    'created_by' => $meta['user_id'] ?? null,
                ]);
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->enqueueBankEmbedding($supplierId, $txId);
        $hint = $ruleId === null ? $this->ruleHintFor($supplierId, $txId) : null;
        return ['entry_id' => $entryId, 'rule_id' => $ruleId, 'rule_hint' => $hint];
    }

    /**
     * Zrušení zaúčtování: reverse + detachSource (R10). Vrací id storna.
     *
     * @param array{user_id?:?int, posted_by?:?int, entry_date?:?string, description?:?string, reason?:?string} $meta
     */
    public function unpost(int $supplierId, int $txId, array $meta): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $scheduleId = $this->suggestions->scheduleIdForTx($supplierId, $txId);
            $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
            if ($existing === null || ($existing['reversed_by'] ?? null) !== null) {
                throw new PostingException('not_found', 'K transakci není zaúčtovaný zápis ke stornu.', 404);
            }
            $entryId = (int) $existing['id'];
            $sourceSuggestion = $this->suggestions->findByEntryId($supplierId, $entryId);
            $originalDate = (string) $existing['entry_date'];
            $period = $this->periods->findForDate($supplierId, $originalDate);
            $lockStmt = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=?');
            $lockStmt->execute([$supplierId]);
            $lockedUntil = $lockStmt->fetchColumn();
            if ($period === null || (string) $period['status'] !== 'open'
                || ($lockedUntil !== false && $lockedUntil !== null && $originalDate <= (string) $lockedUntil)) {
                throw new PostingException(
                    'period_closed',
                    'Období původního zápisu je uzavřené nebo zamčené — storno nelze provést.',
                    409,
                );
            }
            $reversalId = $this->posting->reverse($supplierId, $entryId, [
                'entry_date' => $originalDate,
                'description' => $meta['description'] ?? ('Storno bankovního zápisu #' . $entryId),
                'user_id'    => $meta['user_id'] ?? null,
                'posted_by'  => $meta['posted_by'] ?? ($meta['user_id'] ?? null),
            ]);
            $this->journal->detachSource($entryId, $supplierId);
            $this->releasePosting(
                $supplierId,
                $txId,
                $entryId,
                $sourceSuggestion,
                $scheduleId,
                $meta,
                'reversed_by_user',
            );

            if ($ownTx) {
                $pdo->commit();
            }
            return $reversalId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Guard pro ignore transakce (M3c): zaúčtovanou tx nelze ignorovat (409),
     * jinak pending suggestion tx → superseded. Best-effort mimo double_entry (no-op).
     */
    public function onIgnore(int $supplierId, int $txId): void
    {
        if ($this->supplierMode($supplierId) !== 'double_entry') {
            return;
        }
        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing !== null && ($existing['reversed_by'] ?? null) === null) {
            throw new PostingException('posted_transaction_cannot_be_ignored',
                'Zaúčtovanou transakci nelze ignorovat — nejdřív zrušte zaúčtování.', 409);
        }
        $this->transfers?->releasePairs($supplierId, $txId);
        $this->suggestions->supersedePendingForTx($supplierId, $txId, 'superseded');
    }

    // ── learned hint (§4.2 / §4.3) ──────────────────────────────────────────────

    /**
     * Learned hint pro UI po ručním zaúčtování (§4.2).
     *
     * @return array<string,mixed>|null
     */
    public function ruleHintFor(int $supplierId, int $txId): ?array
    {
        $tx = $this->loadTx($txId);
        if ($tx === null) {
            return null;
        }
        $cands = $this->learnedCandidates($supplierId, $tx);
        if (count($cands) < 1) {
            return null;
        }
        // Aktivní pravidlo tx nechytá (jinak by hint duplikoval pravidlo).
        $direction = (float) $tx['amount'] > 0 ? 'incoming' : 'outgoing';
        $matchTx = $this->matchTxArray($tx);
        foreach ($this->rules->findActive($supplierId, $direction) as $rule) {
            if ($this->ruleMatcher->matching($rule, $matchTx)) {
                return null;
            }
        }
        // Jediná distinctní 2-řádková kontace shodná napříč kandidáty.
        $kontace = $this->distinctKontace($cands);
        if ($kontace === null) {
            return null;
        }
        $amounts = array_map(static fn (array $c): float => abs((float) $c['amount']), $cands);
        $amounts[] = abs((float) $tx['amount']);
        $vs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));

        return [
            'previous_count' => count($cands),
            'prefill' => [
                'name'                 => (string) ($tx['counterparty_name'] ?? ''),
                'direction'            => $direction,
                'counterparty_account' => $tx['counterparty_account'] !== null
                    ? AccountNumberNormalizer::normalize((string) $tx['counterparty_account']) : null,
                'counterparty_bank'    => $tx['counterparty_bank'] !== null ? (string) $tx['counterparty_bank'] : null,
                'variable_symbol'      => $vs !== '' ? $vs : null,
                'message_contains'     => $this->commonMessagePrefix($cands, $tx),
                'amount_min'           => floor(min($amounts) * 0.9),
                'amount_max'           => ceil(max($amounts) * 1.1),
                'debit_account_code'   => $kontace['debit_account_code'],
                'credit_account_code'  => $kontace['credit_account_code'],
                'mode'                 => 'suggest',
            ],
        ];
    }

    /**
     * Learned kontace pro suggestion po importu (§4.3): jediná distinctní 2-řádková
     * kontace mezi předchozími zaúčtovanými tx (mimo saldokonto).
     *
     * @param array<string,mixed> $tx
     * @return array{debit_account_code:string, credit_account_code:string, source_tx_id:int, corrected:bool}|null
     */
    private function findLearnedKontace(int $supplierId, array $tx): ?array
    {
        $corrected = $this->correctionCandidates($supplierId, $tx);
        if ($corrected !== []) {
            $seen = [];
            $kontace = null;
            foreach ($corrected as $candidate) {
                $key = (string) $candidate['final_debit'] . '/' . (string) $candidate['final_credit'];
                $seen[$key] = true;
                $kontace = [
                    'debit_account_code' => (string) $candidate['final_debit'],
                    'credit_account_code' => (string) $candidate['final_credit'],
                ];
            }
            if (count($seen) !== 1 || $kontace === null) return null;
            foreach ($kontace as $code) {
                foreach (self::SALDO_BLACKLIST as $prefix) {
                    if (str_starts_with($code, $prefix)) return null;
                }
            }
            return $kontace + ['source_tx_id' => (int) $corrected[0]['tx_id'], 'corrected' => true];
        }
        $cands = $this->learnedCandidates($supplierId, $tx);
        if ($cands === []) {
            return null;
        }
        $kontace = $this->distinctKontace($cands);
        if ($kontace === null) {
            return null;
        }
        // Obranná pojistka: learned s účtem na blacklistu se nevytvoří.
        foreach ([$kontace['debit_account_code'], $kontace['credit_account_code']] as $code) {
            foreach (self::SALDO_BLACKLIST as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    return null;
                }
            }
        }
        return $kontace + ['source_tx_id' => (int) $cands[0]['tx_id'], 'corrected' => false];
    }

    /** @param array<string,mixed> $tx */
    private function hasAnomaly(int $supplierId, array $tx): bool
    {
        return $this->anomalyCodes($supplierId, $tx) !== [];
    }

    /**
     * Kódy nalezených anomálií (`amount_zscore`, `duplicate_payment`). Volající se
     * podle nich rozhoduje různě: dvojí úhrada patří vždy člověku, kdežto vybočení
     * částky z historie protistrany je u prokazatelně spárované platby jen statistika.
     *
     * @param array<string,mixed> $tx
     * @return list<string>
     */
    private function anomalyCodes(int $supplierId, array $tx): array
    {
        if ($this->anomalies === null) {
            return [];
        }
        return array_values(array_map(
            static fn (array $a): string => (string) $a['code'],
            $this->anomalies->checkBankTx($supplierId, $tx),
        ));
    }

    private function recordAiDecision(int $supplierId, string $source, int $txId, string $metric): void
    {
        if (!in_array($source, BankPostingSuggestionRepository::AI_SOURCES, true)) {
            return;
        }
        try {
            $this->aiSuggestions?->metric($supplierId, $source, $metric);
            $this->aiKillSwitch?->evaluate($supplierId, $source);
        } catch (\Throwable) {
            // Účetní rozhodnutí nesmí selhat kvůli pomocné AI telemetrii.
        }
    }

    /**
     * Připraví bankovní transakci na fyzické odstranění jejího zápisu v deníku.
     * Volající musí držet databázovou transakci a po návratu odstranit právě $entryId;
     * při rollbacku se tak atomicky vrátí i fronta návrhů, páry převodů a daňový předpis.
     *
     * @param array{user_id?:?int, reason?:?string} $meta
     */
    public function prepareEntryDeletion(
        int $supplierId,
        int $txId,
        int $entryId,
        array $meta,
    ): void {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Příprava smazání bankovního zápisu musí proběhnout v transakci.');
        }

        $existing = $this->journal->findBySource($supplierId, 'bank', $txId);
        if ($existing === null || (int) $existing['id'] !== $entryId || ($existing['reversed_by'] ?? null) !== null) {
            throw new PostingException('not_found', 'K transakci není odpovídající aktivní účetní zápis.', 404);
        }

        $this->releasePosting(
            $supplierId,
            $txId,
            $entryId,
            $this->suggestions->findByEntryId($supplierId, $entryId),
            $this->suggestions->scheduleIdForTx($supplierId, $txId),
            $meta + ['reason' => 'delete'],
            'deleted_by_user',
        );
    }

    /**
     * @param array<string,mixed>|null $sourceSuggestion
     * @param array{user_id?:?int, reason?:?string} $meta
     */
    private function releasePosting(
        int $supplierId,
        int $txId,
        int $entryId,
        ?array $sourceSuggestion,
        ?int $scheduleId,
        array $meta,
        string $requeueNote,
    ): void {
        if ($sourceSuggestion !== null) {
            $this->corrections?->fromSuggestion(
                $supplierId,
                'unpost',
                $sourceSuggestion,
                null,
                null,
                $meta['user_id'] ?? null,
                $meta['reason'] ?? 'unpost',
            );
            if ((string) $sourceSuggestion['source'] === 'rule' && $sourceSuggestion['rule_id'] !== null) {
                $rule = $this->rules->find($supplierId, (int) $sourceSuggestion['rule_id']);
                if ($rule !== null && (string) $rule['mode'] === 'auto') {
                    $this->promotion?->demote(
                        $supplierId,
                        (int) $sourceSuggestion['rule_id'],
                        $meta['user_id'] ?? null,
                        'unpost',
                    );
                } else {
                    $this->rules->resetApprovedStreak((int) $sourceSuggestion['rule_id']);
                }
            }
        } else {
            $codes = ['debit' => null, 'credit' => null];
            foreach ($this->journal->linesForEntry($entryId, $supplierId) as $line) {
                $codes[(string) $line['side']] = $this->accountCode((int) $line['account_id']);
            }
            $this->corrections?->record($supplierId, [
                'event_type' => 'unpost',
                'entity_type' => 'bank_transaction',
                'entity_id' => $txId,
                'suggestion_source' => 'manual',
                'suggested_debit' => $codes['debit'],
                'suggested_credit' => $codes['credit'],
                'amount' => abs((float) ($this->loadTx($txId)['amount'] ?? 0)),
                'reason' => $meta['reason'] ?? 'unpost',
                'created_by' => $meta['user_id'] ?? null,
            ]);
        }
        $this->transfers?->releasePairs($supplierId, $txId);
        $this->suggestions->supersedePendingForTx($supplierId, $txId, 'superseded');
        $this->suggestions->requeueReversedForTx($supplierId, $txId, $entryId, $requeueNote);
        if ($scheduleId !== null) {
            $this->taxSchedules?->unmatch($supplierId, $scheduleId, $txId);
        }
    }

    private function enqueueBankEmbedding(int $supplierId, int $txId): void
    {
        try {
            $this->embeddingWriter?->enqueueFromDecision($supplierId, 'bank_transaction', $txId);
        } catch (\Throwable) {
        }
    }

    /** @param array<string,mixed> $tx @return list<array<string,mixed>> */
    private function correctionCandidates(int $supplierId, array $tx): array
    {
        $account = $tx['counterparty_account'] !== null ? (string) $tx['counterparty_account'] : '';
        $normalizedAccount = AccountNumberNormalizer::normalize($account);
        if ($normalizedAccount === '') return [];
        $sign = (float) $tx['amount'] > 0 ? '> 0' : '< 0';
        $bankCode = $tx['counterparty_bank'] !== null ? (string) $tx['counterparty_bank'] : null;
        $txVs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.entity_id AS tx_id, c.final_debit, c.final_credit, c.created_at,
                    bt.amount, bt.counterparty_account, bt.counterparty_bank, bt.variable_symbol
               FROM accounting_corrections c
               JOIN bank_transactions bt ON bt.id = c.entity_id
              WHERE c.supplier_id = ? AND c.entity_type = 'bank_transaction'
                AND c.event_type IN ('approve_override','manual_post')
                AND c.final_debit IS NOT NULL AND c.final_credit IS NOT NULL
                AND c.entity_id <> ? AND bt.amount {$sign}
                AND TRIM(LEADING '0' FROM REGEXP_REPLACE(IFNULL(bt.counterparty_account, ''), '[^0-9]', '')) = ?
                AND (? IS NULL OR bt.counterparty_bank IS NULL OR bt.counterparty_bank = ?)
                AND (? = '' OR REGEXP_REPLACE(IFNULL(bt.variable_symbol, ''), '[^0-9]', '') = ''
                     OR REGEXP_REPLACE(IFNULL(bt.variable_symbol, ''), '[^0-9]', '') = ?)
                AND c.created_at >= (NOW() - INTERVAL 400 DAY)
              ORDER BY c.created_at DESC, c.id DESC LIMIT 6"
        );
        $stmt->execute([
            $supplierId,
            (int) $tx['id'],
            $normalizedAccount,
            $bankCode,
            $bankCode,
            $txVs,
            $txVs,
        ]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!AccountNumberNormalizer::equals($account, (string) ($row['counterparty_account'] ?? ''))) continue;
            if ($tx['counterparty_bank'] !== null && $row['counterparty_bank'] !== null
                && (string) $tx['counterparty_bank'] !== (string) $row['counterparty_bank']) continue;
            $candidateVs = VariableSymbolNormalizer::digits((string) ($row['variable_symbol'] ?? ''));
            if ($txVs !== '' && $candidateVs !== '' && $txVs !== $candidateVs) continue;
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Kandidáti učení: zaúčtované bank tx stejného směru a protiúčtu (H2b: jen z
     * ne-matched zdrojových tx), ≤400 dnů zpět, limit 6, VS musí sedět (má-li obě).
     *
     * @param array<string,mixed> $tx
     * @return list<array<string,mixed>>
     */
    private function learnedCandidates(int $supplierId, array $tx): array
    {
        $account = $tx['counterparty_account'] !== null ? (string) $tx['counterparty_account'] : '';
        if ($account === '') {
            return [];
        }
        $direction = (float) $tx['amount'] > 0 ? 'incoming' : 'outgoing';
        $sign = $direction === 'incoming' ? '> 0' : '< 0';
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.id AS entry_id, bt.id AS tx_id, bt.amount, bt.counterparty_account,
                    bt.counterparty_bank, bt.variable_symbol, bt.description
               FROM journal_entries je
               JOIN bank_transactions bt ON bt.id = je.source_id
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.reversed_by IS NULL
                AND bt.id <> ? AND bt.amount {$sign}
                AND bt.match_status NOT IN ('auto_exact','auto_partial','manual')
                AND je.posted_at >= (NOW() - INTERVAL 400 DAY)
              ORDER BY je.posted_at DESC
              LIMIT 6"
        );
        $stmt->execute([$supplierId, (int) $tx['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $txVs = VariableSymbolNormalizer::digits((string) ($tx['variable_symbol'] ?? ''));
        $out = [];
        foreach ($rows as $r) {
            if (!AccountNumberNormalizer::equals($account, (string) ($r['counterparty_account'] ?? ''))) {
                continue;
            }
            if ($tx['counterparty_bank'] !== null && $r['counterparty_bank'] !== null
                && (string) $tx['counterparty_bank'] !== (string) $r['counterparty_bank']) {
                continue;
            }
            $candVs = VariableSymbolNormalizer::digits((string) ($r['variable_symbol'] ?? ''));
            if ($txVs !== '' && $candVs !== '' && $txVs !== $candVs) {
                continue;
            }
            $r['lines'] = $this->journal->linesForEntry((int) $r['entry_id'], $supplierId);
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Jediná distinctní 2-řádková kontace napříč kandidáty, jinak null.
     *
     * @param list<array<string,mixed>> $cands
     * @return array{debit_account_code:string, credit_account_code:string}|null
     */
    private function distinctKontace(array $cands): ?array
    {
        $seen = [];
        $result = null;
        foreach ($cands as $c) {
            $lines = $c['lines'] ?? [];
            if (count($lines) !== 2) {
                return null; // složený zápis → nedeterministické
            }
            $debit = null;
            $credit = null;
            foreach ($lines as $l) {
                $code = $this->accountCode((int) $l['account_id']);
                if ($l['side'] === 'debit') {
                    $debit = $code;
                } else {
                    $credit = $code;
                }
            }
            if ($debit === null || $credit === null) {
                return null;
            }
            [$debit, $credit] = self::genericBankLeg($debit, $credit);
            $key = $debit . '/' . $credit;
            $seen[$key] = true;
            $result = ['debit_account_code' => $debit, 'credit_account_code' => $credit];
        }
        return count($seen) === 1 ? $result : null;
    }

    /**
     * Bankovní noha zpět na obecné '221' — kontace se čte ze zaúčtované HISTORIE, ale
     * míří do PRAVIDLA (resp. návrhu), který platí i pro platby na jiném vlastním účtu.
     * V deníku už na bankovní noze leží analytika účtu výpisu (#35, `221.400`); kdyby se
     * opsala do pravidla, přibila by ho k jednomu bankovnímu účtu a platba na jiném by
     * skončila na cizí analytice. Konkrétní účet dosadí až {@see withBankAnalytic()}
     * podle výpisu — přesně proto pravidla v DB vedou holé '221'.
     *
     * Převod mezi vlastními účty (obě nohy 221*) se nechává být: tam nesou obě analytiky
     * význam a která z nich je „účet výpisu" se z kontace poznat nedá.
     *
     * @return array{0:string, 1:string}
     */
    private static function genericBankLeg(string $debit, string $credit): array
    {
        $debitBank  = str_starts_with($debit, '221');
        $creditBank = str_starts_with($credit, '221');
        if ($debitBank === $creditBank) {
            return [$debit, $credit];
        }
        return $debitBank ? ['221', $credit] : [$debit, '221'];
    }

    /**
     * Nejdelší společný prefix normalizovaných zpráv (min. 4 znaky, jinak null).
     *
     * @param list<array<string,mixed>> $cands
     * @param array<string,mixed> $tx
     */
    private function commonMessagePrefix(array $cands, array $tx): ?string
    {
        $msgs = [BankMessageNormalizer::normalize((string) ($tx['description'] ?? ''))];
        foreach ($cands as $c) {
            $msgs[] = BankMessageNormalizer::normalize((string) ($c['description'] ?? ''));
        }
        $msgs = array_filter($msgs, static fn (string $m): bool => $m !== '');
        if ($msgs === []) {
            return null;
        }
        $prefix = array_shift($msgs);
        foreach ($msgs as $m) {
            $len = min(strlen($prefix), strlen($m));
            $i = 0;
            while ($i < $len && $prefix[$i] === $m[$i]) {
                $i++;
            }
            $prefix = substr($prefix, 0, $i);
            if ($prefix === '') {
                break;
            }
        }
        $prefix = trim($prefix);
        return strlen($prefix) >= 4 ? $prefix : null;
    }

    // ── validace / helpers ──────────────────────────────────────────────────────

    /**
     * Saldokontní blacklist (H2) na OBOU stranách + R6 guard.
     *
     * Platí pro AUTOMATIKU — pravidla, šablony a schvalování návrhů: kontace účtující
     * 311 naslepo u každé odpovídající platby rozvrátí saldo ve velkém a bez dokladu
     * v ruce. Jednorázové ruční zaúčtování člověka, který doklad má, si volá jen
     * {@see assertBankSide()} (stejně jako ruční rozúčtování na víc řádků).
     */
    private function assertSaldoBlacklist(float $amount, string $debit, string $credit): void
    {
        foreach (self::SALDO_BLACKLIST as $prefix) {
            if (str_starts_with($debit, $prefix) || str_starts_with($credit, $prefix)) {
                throw new PostingException('rule_saldo_forbidden',
                    'Platby faktur se párují, ne účtují pravidlem.');
            }
        }
        $this->assertBankSide($amount, $debit, $credit);
    }

    /** R6 guard: bankovní pohyb vždy hýbe bankou — incoming = MD 221x, outgoing = D 221x. */
    private function assertBankSide(float $amount, string $debit, string $credit): void
    {
        $bank = $amount > 0 ? $debit : $credit;
        if (!str_starts_with($bank, '221')) {
            throw new PostingException('rule_bank_side_required',
                'Bankovní strana zápisu musí být účet 221.');
        }
    }

    /** @param array<string,mixed> $tx */
    private function assertPostableTx(array $tx, bool $allowForeign = false): void
    {
        if ((string) $tx['match_status'] === 'ignored') {
            throw new PostingException('transaction_ignored', 'Ignorovanou transakci nelze účtovat.');
        }
        if (!$allowForeign && $this->effectiveCurrency($tx) !== 'CZK') {
            throw new PostingException('foreign_currency', 'Cizoměnovou transakci zatím nelze zaúčtovat.');
        }
    }

    /**
     * Cizoměnové pravidlo smí na protistranu účtovat:
     *   a) výsledkový účet 5xx/6xx — náklad/výnos se spotřebuje kurzem dne a už se nikdy
     *      nepřeceňuje, takže cizoměnová pozice nikde nezůstane viset;
     *   b) 221* — vlastní bankovní účet/analytiku.
     *
     * Smysl guardu je „nenech cizoměnovou pozici na účtu, který nikdo nepřeceňuje". Účty 221*
     * jsou ale právě ty, které uzávěrka přeceňuje (FxRevaluationService bere bankovní zůstatky
     * jako {account_code, currency_code, foreign_balance}), takže pozice na nich viset nezůstane.
     *
     * Případ b) je převod mezi vlastními cizoměnovými účty (běžný účet → termínovaný vklad):
     * obě nohy nesou stopu téhož kurzu dne, zápis je v CZK triviálně vyrovnaný a kurzový rozdíl
     * nevzniká — jen se přesune, co se přeceňuje. Bez téhle větve nešel EUR termínovaný vklad
     * zaúčtovat pravidlem vůbec, přestože CZK varianta téhož pravidla (221100/221) existuje
     * a je v pořádku — guard tedy blokoval už posvěcený vzorec jen kvůli měně.
     *
     * Saldokonta (311/321/…) zůstávají zakázaná zvlášť přes SALDO_BLACKLIST — ta se párují,
     * ne účtují pravidlem.
     */
    private function assertFxResultAccounts(float $amount, string $debit, string $credit): void
    {
        $counter = $amount > 0 ? $credit : $debit;
        if (str_starts_with($counter, '221')) {
            return;
        }
        if (!in_array(substr($counter, 0, 1), ['5', '6'], true)) {
            throw new PostingException('rule_account_forbidden', 'Cizoměnové pravidlo smí účtovat jen výsledkový účet nebo vlastní účet 221.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function insertRuleFromPayload(int $supplierId, array $payload, ?int $userId): int
    {
        $direction = (string) ($payload['direction'] ?? '');
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new PostingException('rule_criteria_missing', 'Chybí směr pravidla.');
        }
        $data = [
            'name'                 => trim((string) ($payload['name'] ?? '')) !== '' ? trim((string) $payload['name']) : 'Pravidlo',
            'direction'            => $direction,
            'counterparty_account' => self::nn($payload['counterparty_account'] ?? null,
                static fn (string $v): string => AccountNumberNormalizer::normalize($v)),
            'counterparty_bank'    => self::nn($payload['counterparty_bank'] ?? null),
            'variable_symbol'      => self::nn($payload['variable_symbol'] ?? null,
                static fn (string $v): string => VariableSymbolNormalizer::digits($v)),
            'message_contains'     => self::nn($payload['message_contains'] ?? null,
                static fn (string $v): string => BankMessageNormalizer::normalize($v)),
            'amount_min'           => isset($payload['amount_min']) && $payload['amount_min'] !== null ? round((float) $payload['amount_min'], 2) : null,
            'amount_max'           => isset($payload['amount_max']) && $payload['amount_max'] !== null ? round((float) $payload['amount_max'], 2) : null,
            'debit_account_code'   => (string) ($payload['debit_account_code'] ?? ''),
            'credit_account_code'  => (string) ($payload['credit_account_code'] ?? ''),
            'description'          => self::nn($payload['description'] ?? null),
            'mode'                 => 'suggest', // create vždy suggest (H4e)
        ];
        if ($data['counterparty_account'] === null && $data['variable_symbol'] === null && $data['message_contains'] === null) {
            throw new PostingException('rule_criteria_missing', 'Pravidlo musí mít alespoň jedno kritérium.');
        }
        $this->assertSaldoBlacklist($direction === 'incoming' ? 1.0 : -1.0, $data['debit_account_code'], $data['credit_account_code']);
        return $this->rules->insert($supplierId, $data, $userId);
    }

    private static function nn(mixed $v, ?callable $transform = null): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $s = $transform !== null ? $transform($s) : $s;
        return $s === '' ? null : $s;
    }

    /**
     * @return array{account_code:string, side:string, amount:float}
     */
    private function line(string $code, string $side, float $amount): array
    {
        return ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2)];
    }

    /**
     * Validace řádků rozúčtování z UI. Vyváženost (Σ MD = Σ D) i existenci účtů ověří až
     * PostingService::postDocument(); tady se hlídá to, co je specifické pro bankovní pohyb:
     *
     *  - každý řádek má platný účet, stranu a kladnou částku,
     *  - **banka se musí pohnout přesně o částku výpisu**: Σ(221 MD) − Σ(221 D) = amount.
     *    To je klíčový invariant multi-line režimu — bez něj by šlo zaúčtovat pohyb na jinou
     *    částku, než jaká reálně odešla z účtu, a 221 by se rozešel s výpisem.
     *
     * Saldokontní účty tu — na rozdíl od pravidel (H2) — POVOLENÉ jsou (a od té doby,
     * co byl zákaz z ruční dvojice MD/D odstraněn, platí totéž i pro ni).
     * H2 míří na automatiku: pravidlo účtující 311 naslepo u každé odpovídající platby by
     * saldo rozvrátilo ve velkém a bez dokladu v ruce. Ruční rozúčtování je jednorázový
     * úkon člověka, který doklad má, a bez 311 nejde zaúčtovat legitimní případ „cizoměnová
     * úhrada korunové faktury" (311 D v částce předpisu, 221 MD kurzem dne, rozdíl 563/663).
     * Zákaz by ho nezastavil, jen odklonil do ručního zápisu v deníku (ten saldokonta
     * nijak neomezuje) — tedy bez vazby na pohyb a bez invariantu 221 = výpis.
     * Dvojí započtení úhrady tím nevzniká: postManual odmítá tx s živým zápisem
     * (`already_posted`), postDocument je idempotentní na ('bank', txId) → na pohyb je vždy
     * nejvýš JEDEN zápis, a pravidlo z multi-line založit nelze ({@see postManual}).
     *
     * @param list<array<string,mixed>> $raw
     * @param float $signedAmount částka pohybu se znaménkem (+ příchozí, − odchozí)
     * @param float $absAmount    částka v CZK (u cizí měny už přepočtená)
     * @return list<array{account_code:string, side:string, amount:float}>
     */
    private function manualLines(
        array $raw,
        float $signedAmount,
        float $absAmount,
        ?string $currency = null,
        ?float $fxRate = null,
        float $foreignAmount = 0.0,
    ): array {
        $lines = [];
        $bankCents = 0;
        foreach ($raw as $i => $r) {
            $code = trim((string) ($r['account_code'] ?? ''));
            $side = (string) ($r['side'] ?? '');
            $amount = round((float) ($r['amount'] ?? 0), 2);
            if ($code === '' || !in_array($side, ['debit', 'credit'], true)) {
                throw new PostingException('validation_failed', 'Řádek ' . ($i + 1) . ': chybí účet nebo strana.');
            }
            if ($amount <= 0.0) {
                throw new PostingException('validation_failed', 'Řádek ' . ($i + 1) . ': částka musí být kladná.');
            }
            $line = $this->line($code, $side, $amount);
            if (str_starts_with($code, '221')) {
                $bankCents += (int) round($amount * 100.0) * ($side === 'debit' ? 1 : -1);
                // §4/12 — cizoměnový účet se vede i v cizí měně. Stopa patří na bankovní
                // nohu: jen ta je skutečně v cizí měně (protiúčty jsou korunové předpisy).
                if ($fxRate !== null && $currency !== null && $absAmount > 0.0) {
                    $line = $this->withFxTrace($line, $currency, $fxRate,
                        round($foreignAmount * $amount / $absAmount, 2));
                }
            }
            $lines[] = $line;
        }

        $expectedCents = (int) round(($signedAmount > 0 ? $absAmount : -$absAmount) * 100.0);
        if ($bankCents !== $expectedCents) {
            throw new PostingException('validation_failed', sprintf(
                'Pohyb na účtu 221 v zápisu (%s Kč) neodpovídá částce z výpisu (%s Kč).',
                number_format($bankCents / 100, 2, ',', ' '),
                number_format($expectedCents / 100, 2, ',', ' '),
            ));
        }
        return $lines;
    }

    /**
     * Reprezentativní dvojice MD/D pro protokol oprav a nabídku pravidla. U rozúčtování bereme
     * bankovní stranu (dle znaménka) a k ní první protiúčet — pravidlo z multi-line zápisu stejně
     * nedává smysl zakládat, ale záznam v accounting_corrections má mít čím být popsaný.
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     * @return array{0:string, 1:string}
     */
    private function primaryPair(array $lines, float $signedAmount): array
    {
        $wantBankSide = $signedAmount > 0 ? 'debit' : 'credit';
        $bank = '';
        $counter = '';
        foreach ($lines as $l) {
            if ($bank === '' && str_starts_with($l['account_code'], '221') && $l['side'] === $wantBankSide) {
                $bank = $l['account_code'];
            } elseif ($counter === '' && !str_starts_with($l['account_code'], '221')) {
                $counter = $l['account_code'];
            }
        }
        $counter = $counter !== '' ? $counter : $bank;
        return $signedAmount > 0 ? [$bank, $counter] : [$counter, $bank];
    }

    /**
     * Alokace spárované přijaté faktury je plná úhrada, pokud pokrývá doklad v toleranci:
     * u stejné měny do 1 Kč (dorovnání 548/648), u CZK karetní platby cizoměnového dokladu
     * do 4 % vůči kurzu předpisu (kurzový rozdíl 563/663). Alokaci srovná na NOMINÁL předpisu
     * a auto_partial povýší na auto_exact; ruční párování nechá `manual` (provenience zůstává).
     *
     * PROČ: dodavatelé (typicky operátoři) platí/inkasují zaokrouhleně na celé koruny, zatímco
     * předpis má haléře. payment_matches.amount drží částku TRANSAKCE, takže bez srovnání by
     * na 321 zůstal viset haléřový zbytek a doklad by nikdy nebyl plně uhrazen. Po srovnání
     * je rozdíl mezi nominálem a skutečně zaplacenou částkou tím, čím je — provozní
     * náklad/výnos ze zaokrouhlení — a {@see self::appendRounding()} ho pošle na 548/648.
     *
     * Idempotence: po srovnání platí alokace ≠ částka tx, takže guard níž vrátí false.
     *
     * Volá se z {@see self::matchedOutcome()} (živý import) i z {@see BankPostingBackfill}
     * (dávka, kvůli reportu `normalized_full`) — jedna logika, dvě vstupní cesty (R9).
     */
    /**
     * Zrcadlo {@see self::normalizeRoundingFullPurchase()} pro VYDANOU větev.
     *
     * Alokace spárované příchozí platby je plná úhrada, pokud pokrývá doklad do 1 Kč.
     * Alokaci srovná na NOMINÁL předpisu, přepočítá `paid_total` a fakturu uzavře;
     * rozdíl mezi nominálem a skutečně přijatou částkou pak {@see self::appendRounding()}
     * pošle na 648 (přeplatek) nebo 548 (nedoplatek).
     *
     * PROČ tohle vzniklo (fáze F1, §3.5 registru SSOT): přijatá větev dorovnání měla,
     * vydaná ne — měla jen `InvoicePaymentService::TOLERANCE` = 0,05 Kč pro uzavření
     * dokladu. Rozdíl mezi 0,05 a 1,00 Kč tedy nechal fakturu viset otevřenou
     * s haléřovým zbytkem na 311 a doklad se nikdy neuzavřel.
     *
     * ⚠ VĚDOMÝ DŮSLEDEK: nedoplatek zákazníka do 1 Kč se tím automaticky odpustí
     * a z pohledávek zmizí. Rozhodnuto 26. 7. 2026 ve prospěch symetrie s přijatou
     * větví. Hranice je `ROUNDING_TOLERANCE_CENTS` v `appendRounding()`, který nad ní
     * stejně hodí `allocation_mismatch` — tolerance jsou tedy svázané, ne dvě čísla.
     *
     * Idempotence: po srovnání platí alokace ≠ částka tx, takže guard níž vrátí false.
     */
    public function normalizeRoundingFullInvoice(int $supplierId, int $txId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT bt.id, bt.source, bt.amount, bt.currency AS tx_currency, bt.match_status,
                    bs.currency AS statement_currency,
                    ip.id AS payment_id, ip.invoice_id, ip.amount AS allocated,
                    i.status, i.amount_to_pay, cur.code AS invoice_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN invoice_payments ip ON ip.bank_transaction_id = bt.id AND ip.supplier_id = ?
               JOIN invoices i ON i.id = ip.invoice_id AND i.supplier_id = ip.supplier_id
          LEFT JOIN currencies cur ON cur.id = i.currency_id
              WHERE bt.id = ?
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $txId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Jen jednoznačná 1:1 alokace — split platba i duplicitní párování se hádat nesmí.
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows[0];

        $txCurrency = strtoupper(trim((string) ($row['tx_currency'] ?: $row['statement_currency'])));
        $invoiceCurrency = strtoupper((string) ($row['invoice_currency'] ?? ''));
        $txAmount = abs((float) $row['amount']);
        $invoiceAmount = (float) $row['amount_to_pay'];

        // Cizoměnová úhrada se tu NEŘEŠÍ: rozdíl je tam kurzový (563/663), ne haléřový.
        // Přijatá větev na to má vlastní cross-currency cestu, vydaná ji nemá — a vymýšlet
        // ji naslepo by bylo horší než ji nemít.
        if ((string) $row['source'] !== 'statement'
            || (float) $row['amount'] <= 0.0
            || !in_array((string) $row['match_status'], ['auto_partial', 'auto_exact', 'manual'], true)
            || $txCurrency === ''
            || $txCurrency !== $invoiceCurrency
            || !in_array((string) $row['status'], ['issued', 'sent', 'reminded', 'paid'], true)
            || $invoiceAmount <= 0.0
            || abs((float) $row['allocated'] - $txAmount) >= 0.005
            || abs($txAmount - $invoiceAmount) > self::ROUNDING_TOLERANCE_CENTS / 100.0
            || abs($txAmount - $invoiceAmount) < 0.005
        ) {
            return false;
        }

        $updated = $pdo->prepare('UPDATE invoice_payments SET amount = ? WHERE id = ? AND amount = ?');
        $updated->execute([$invoiceAmount, (int) $row['payment_id'], (float) $row['allocated']]);
        if ($updated->rowCount() !== 1) {
            return false;
        }

        // paid_total i lifecycle drží `invoices`; po změně alokace se musí přepočítat,
        // jinak by doklad zůstal otevřený s číslem, které už neplatí.
        $pdo->prepare(
            'UPDATE invoices i
                SET i.paid_total = (SELECT COALESCE(SUM(p.amount), 0)
                                      FROM invoice_payments p WHERE p.invoice_id = i.id)
              WHERE i.id = ?'
        )->execute([(int) $row['invoice_id']]);
        $pdo->prepare(
            "UPDATE invoices
                SET status = 'paid',
                    paid_at = COALESCE(paid_at, (SELECT MAX(p.paid_on) FROM invoice_payments p
                                                  WHERE p.invoice_id = invoices.id))
              WHERE id = ? AND status IN ('issued', 'sent', 'reminded')
                AND paid_total >= amount_to_pay"
        )->execute([(int) $row['invoice_id']]);

        if ((string) $row['match_status'] === 'auto_partial') {
            $pdo->prepare("UPDATE bank_transactions SET match_status = 'auto_exact' WHERE id = ? AND match_status = 'auto_partial'")
                ->execute([$txId]);
        }

        return true;
    }

    public function normalizeRoundingFullPurchase(int $supplierId, int $txId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT bt.id, bt.source, bt.amount, bt.currency AS tx_currency, bt.match_status, bt.posted_at,
                    bs.currency AS statement_currency,
                    pm.id AS match_id, pm.invoice_id, pm.purchase_invoice_id, pm.amount AS allocated,
                    pm.match_type, pm.match_confidence,
                    pi.status, pi.amount_to_pay, pi.exchange_rate, cur.code AS invoice_currency
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
               JOIN payment_matches pm ON pm.bank_transaction_id = bt.id AND pm.supplier_id = ?
               JOIN purchase_invoices pi ON pi.id = pm.purchase_invoice_id AND pi.supplier_id = pm.supplier_id
               JOIN currencies cur ON cur.id = pi.currency_id
              WHERE bt.id = ?
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $txId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Jen jednoznačná 1:1 alokace. Víc řádků = split platba (legitimní) nebo duplicitní
        // párování (datová vada) — v obou případech není co srovnávat a hádat se nesmí.
        if (count($rows) !== 1) {
            return false;
        }
        $row = $rows[0];
        $txCurrency = strtoupper(trim((string) ($row['tx_currency'] ?: $row['statement_currency'])));
        $txAmount = abs((float) $row['amount']);
        $invoiceAmount = (float) $row['amount_to_pay'];
        $invoiceCurrency = strtoupper((string) $row['invoice_currency']);
        // Ruční párování nemá confidence (NULL) — důkazem je člověk, ne skóre.
        $manualMatch = (string) $row['match_type'] === 'manual';
        $confidence = (int) ($row['match_confidence'] ?? 0);
        $sameCurrency = $txCurrency !== '' && $txCurrency === $invoiceCurrency;
        $sameCurrencyFull = $sameCurrency
            && ($manualMatch || $confidence >= 70)
            && abs($txAmount - $invoiceAmount) <= FxPaymentSettlement::AMOUNT_TOLERANCE;
        $invoiceRate = (float) ($row['exchange_rate'] ?? 0);
        $expectedCzk = $invoiceRate > 0.0
            ? FxPaymentSettlement::expectedLocalAmount($invoiceAmount, $invoiceRate)
            : 0.0;
        $crossCurrencyFull = !$sameCurrency
            && FxPaymentSettlement::isCzkPaymentOfForeignInvoice($txCurrency, $invoiceCurrency)
            && ($manualMatch || $confidence >= 60)
            && $expectedCzk > 0.0
            && abs($txAmount - $expectedCzk) <= FxPaymentSettlement::matchTolerance($expectedCzk);
        if ((string) $row['source'] !== 'statement'
            || (float) $row['amount'] >= 0.0
            // auto_exact patří do seznamu taky: rozdíl do EXACT_MATCH_TOLERANCE (0,05) sice
            // fakturu rovnou označí jako paid, ale alokace i tak drží částku TRANSAKCE, takže
            // haléřový zbytek zůstane viset na 321 a doklad vypadá uzavřeně, aniž by byl.
            // Je to týž jev jako u auto_partial, jen v užším pásmu — a o to bezpečnější:
            // pod 5 haléři nejde o částečnou úhradu nikdy.
            || !in_array((string) $row['match_status'], ['auto_partial', 'auto_exact', 'manual'], true)
            || $row['invoice_id'] !== null
            || $row['purchase_invoice_id'] === null
            || !in_array((string) $row['match_type'], ['auto', 'manual'], true)
            || !in_array((string) $row['status'], ['received', 'booked', 'paid'], true)
            || $invoiceAmount <= 0.0
            || abs((float) $row['allocated'] - $txAmount) >= 0.005
            || (!$sameCurrencyFull && !$crossCurrencyFull)
        ) {
            return false;
        }

        $predpisStmt = $pdo->prepare(
            "SELECT 1 FROM journal_entries
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                AND posted_at IS NOT NULL AND reversed_by IS NULL
              LIMIT 1"
        );
        $predpisStmt->execute([$supplierId, (int) $row['purchase_invoice_id']]);
        if ($predpisStmt->fetchColumn() === false) {
            return false;
        }

        if ($sameCurrencyFull) {
            $updated = $pdo->prepare('UPDATE payment_matches SET amount = ? WHERE id = ? AND amount = ?');
            $updated->execute([$invoiceAmount, (int) $row['match_id'], (float) $row['allocated']]);
            if ($updated->rowCount() !== 1) {
                return false;
            }
        }
        $pdo->prepare(
            "UPDATE purchase_invoices
                SET status = 'paid', paid_at = COALESCE(paid_at, ?)
              WHERE id = ? AND supplier_id = ? AND status IN ('received','booked')"
        )->execute([(string) $row['posted_at'], (int) $row['purchase_invoice_id'], $supplierId]);
        // 'manual' se nepovyšuje — ruční párování je terminální stav a pro účtování
        // stejně platí jako matched; přepsat ho na auto_exact by smazalo stopu člověka.
        $pdo->prepare(
            "UPDATE bank_transactions
                SET match_status = 'auto_exact'
              WHERE id = ? AND match_status = 'auto_partial'"
        )->execute([$txId]);
        return true;
    }

    /**
     * Dorovnání do 1,00 Kč: diff = ΣMD − ΣD. Kladné → 648 (credit), záporné → 548
     * (debit). Nad tolerancí → allocation_mismatch (ruční zaúčtování).
     *
     * @param list<array{account_code:string, side:string, amount:float}> $lines
     */
    private function appendRounding(array &$lines, float $diff): void
    {
        $cents = (int) round($diff * 100.0);
        if ($cents === 0) {
            return;
        }
        if (abs($cents) > self::ROUNDING_TOLERANCE_CENTS) {
            throw new PostingException('allocation_mismatch', 'Alokace plateb se liší od částky o víc než 1 Kč.');
        }
        if ($cents > 0) {
            $lines[] = $this->line('648', 'credit', $cents / 100.0);
        } else {
            $lines[] = $this->line('548', 'debit', -$cents / 100.0);
        }
    }

    private function documentNo(array $tx): string
    {
        $ref = isset($tx['bank_ref']) && trim((string) $tx['bank_ref']) !== '' ? trim((string) $tx['bank_ref']) : null;
        return $ref ?? ('BANK-' . (int) $tx['id']);
    }

    private function entryDescription(array $tx): string
    {
        $name = trim((string) ($tx['counterparty_name'] ?? ''));
        $desc = trim((string) ($tx['description'] ?? ''));
        if ($name !== '' && $desc !== '') {
            return mb_substr($name . ' — ' . $desc, 0, 255);
        }
        $one = $name !== '' ? $name : $desc;
        return $one !== '' ? mb_substr($one, 0, 255) : ('BANK-' . (int) $tx['id']);
    }

    /** @param array<string,mixed> $tx */
    private function hasMatchingRuleForCurrency(int $supplierId, array $tx): bool
    {
        $direction = (float) $tx['amount'] > 0 ? 'incoming' : 'outgoing';
        $currency = strtoupper($this->effectiveCurrency($tx));
        $match = $this->matchTxArray($tx);
        foreach ($this->rules->findActive($supplierId, $direction) as $rule) {
            if (strtoupper((string) ($rule['applies_currency'] ?? 'CZK')) === $currency
                && $this->ruleMatcher->matching($rule, $match)) {
                return true;
            }
        }
        return false;
    }

    private function markSchedulePaid(int $supplierId, mixed $scheduleId, array $tx): void
    {
        if ($scheduleId === null || $this->taxSchedules === null) {
            return;
        }
        try {
            $this->taxSchedules->markPaid(
                $supplierId,
                (int) $scheduleId,
                round(abs((float) $tx['amount']), 2),
                substr((string) $tx['posted_at'], 0, 10),
                (int) $tx['id'],
            );
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? null) !== '23000') {
                throw $e;
            }
            $this->activity->log('tax_advance_schedule.match_conflict', null, 'bank_transaction', (int) $tx['id'], [
                'schedule_id' => (int) $scheduleId,
            ], supplierId: $supplierId);
        }
    }

    /**
     * @param array<string,mixed> $tx
     * @return array{amount:float, variable_symbol:?string, counterparty_account:?string,
     *   counterparty_bank:?string, description:?string}
     */
    private function matchTxArray(array $tx): array
    {
        return [
            'amount'               => (float) $tx['amount'],
            'variable_symbol'      => $tx['variable_symbol'] !== null ? (string) $tx['variable_symbol'] : null,
            'counterparty_account' => $tx['counterparty_account'] !== null ? (string) $tx['counterparty_account'] : null,
            'counterparty_bank'    => $tx['counterparty_bank'] !== null ? (string) $tx['counterparty_bank'] : null,
            'description'          => $tx['description'] !== null ? (string) $tx['description'] : null,
            'counterparty_name'    => ($tx['counterparty_name'] ?? null) !== null ? (string) $tx['counterparty_name'] : null,
        ];
    }

    // ── data access ─────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function loadTx(int $txId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.*, bs.supplier_id AS statement_supplier_id,
                    bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency,
                    (EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id = bt.id)
                     OR EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = bt.id))
                        AS has_explicit_allocation
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $stmt->execute([$txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Patří transakce (přes hlavičku svého výpisu) danému supplieru?
     *
     * Jediná povolená kontrola tam, kde volající supplier už známe. Predikát je
     * sdílený s ostatními bankovními cestami ({@see BankStatementOwnershipResolver}),
     * takže „vidím výpis" a „smím z něj účtovat" nemůžou rozejít.
     */
    private function txOwnedBySupplier(int $txId, int $supplierId): bool
    {
        if ($txId <= 0 || $supplierId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.id = ? AND ' . BankStatementOwnershipResolver::sql('bs') . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$txId], BankStatementOwnershipResolver::params($supplierId)));

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Distinktní supplier_id kandidáti pro číslo účtu výpisu (mirror StatementMatcher).
     *
     * POZOR: slouží VÝHRADNĚ k dohledání vlastníka transakce, jejíhož tenanta ještě
     * neznáme (automatický hook nad importem). Nikdy to není autorizační kontrola —
     * tam, kde supplier přichází ze session, patří {@see txOwnedBySupplier()}.
     *
     * @param array<string,mixed> $tx
     * @return list<int>
     */
    private function resolveSupplierCandidates(array $tx): array
    {
        $recipient = (string) ($tx['recipient_account'] ?? '');
        $ids = [];
        $txId = (int) ($tx['id'] ?? 0);
        if ($txId > 0) {
            $explicit = $this->db->pdo()->prepare(
                'SELECT supplier_id FROM invoice_payments WHERE bank_transaction_id = ?
                 UNION
                 SELECT supplier_id FROM payment_matches WHERE bank_transaction_id = ?
                 UNION
                 SELECT i.supplier_id
                   FROM invoices i
                   JOIN bank_transactions bt ON bt.matched_invoice_id = i.id
                  WHERE bt.id = ?'
            );
            $explicit->execute([$txId, $txId, $txId]);
            foreach ($explicit->fetchAll(PDO::FETCH_COLUMN) as $supplierId) {
                $ids[(int) $supplierId] = true;
            }
            if ($ids !== []) {
                return array_map('intval', array_keys($ids));
            }
        }
        if ($recipient === '') {
            return array_map('intval', array_keys($ids));
        }
        $recipientBank = isset($tx['recipient_bank']) && (string) $tx['recipient_bank'] !== '' ? (string) $tx['recipient_bank'] : null;
        $stmt = $this->db->pdo()->query(
            'SELECT supplier_id, account_number, iban, bank_code FROM currencies
              WHERE (account_number IS NOT NULL OR iban IS NOT NULL) AND supplier_id IS NOT NULL'
        );
        if ($stmt === false) {
            return [];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $iban = isset($c['iban']) && is_string($c['iban']) ? $c['iban'] : null;
            if ($recipientBank !== null) {
                $candidateBank = (string) ($c['bank_code'] ?? '');
                if ($candidateBank === '' && $iban !== null) {
                    $candidateBank = (string) AccountNumberNormalizer::czechIbanBankCode($iban);
                }
                if ($candidateBank !== '' && $candidateBank !== $recipientBank) {
                    continue;
                }
            }
            if (AccountNumberNormalizer::matchesAny($recipient, $c['account_number'] ?? null, $iban)) {
                $ids[(int) $c['supplier_id']] = true;
            }
        }
        $canonical = AccountNumberNormalizer::canonical($recipient);
        if ($canonical !== null) {
            // Kód banky striktně (zrcadlo BankStatementOwnershipResolver::bankCodeMatch()):
            // shodný, nebo prázdný na OBOU stranách. `IN ('', ?)` dělalo z neúplně
            // vyplněného vlastního účtu wildcard, který přibíral cizí firmy do kandidátů.
            $bank = AccountNumberNormalizer::canonicalBankCode($recipientBank);
            $sql = 'SELECT supplier_id FROM supplier_bank_accounts
                     WHERE is_active = 1 AND account_canonical = ? AND bank_code_norm = ?';
            $params = [$canonical, $bank ?? ''];
            $registered = $this->db->pdo()->prepare($sql);
            $registered->execute($params);
            foreach ($registered->fetchAll(PDO::FETCH_COLUMN) as $supplierId) {
                $ids[(int) $supplierId] = true;
            }
        }
        return array_map('intval', array_keys($ids));
    }

    private function supplierMode(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** @param array<string,mixed> $tx */
    private function effectiveCurrency(array $tx): string
    {
        $c = $tx['currency'] ?? $tx['statement_currency'] ?? null;
        return is_string($c) && $c !== '' ? $c : 'CZK';
    }

    /** @return array<string,mixed>|null */
    private function loadInvoice(int $supplierId, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT i.id, i.invoice_type, i.status, i.exchange_rate, i.amount_to_pay, i.paid_total,
                    cur.code AS currency
               FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ? AND i.supplier_id = ?'
        );
        $stmt->execute([$invoiceId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function loadPurchase(int $supplierId, int $pfId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT pi.id, pi.document_kind, pi.status, pi.exchange_rate, pi.amount_to_pay,
                    pi.parent_purchase_invoice_id, cur.code AS currency
               FROM purchase_invoices pi JOIN currencies cur ON cur.id = pi.currency_id
              WHERE pi.id = ? AND pi.supplier_id = ?'
        );
        $stmt->execute([$pfId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function accountCode(int $accountId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT account_code FROM chart_of_accounts WHERE id = ?');
        $stmt->execute([$accountId]);
        $v = $stmt->fetchColumn();
        return $v === false ? '?' : (string) $v;
    }

    /**
     * Stav zaúčtování transakcí — jediný zdroj pravdy sdílený mezi detailem výpisu
     * ({@see \MyInvoice\Action\Bank\BankStatementAction}) a záložkou „Všechny pohyby"
     * / frontou k zaúčtování ({@see \MyInvoice\Action\Accounting\Bank\BankPostingSuggestionAction}),
     * ať se stav (posted/suggested/transfer) nepočítá na dvou místech jinak. Jen double_entry
     * firma; jinak prázdné (FE gate na accounting_mode). posted = nestornovaný bank zápis,
     * suggested = pending návrh.
     *
     * @param list<int> $txIds
     * @return array<int,array<string,mixed>>
     */
    public function transactionPostingInfo(int $supplierId, array $txIds): array
    {
        if ($txIds === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $mode = $pdo->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
        $mode->execute([$supplierId]);
        if ((string) $mode->fetchColumn() !== 'double_entry') {
            return [];
        }

        $ph = implode(',', array_fill(0, count($txIds), '?'));
        $out = [];

        $entries = $pdo->prepare(
            "SELECT je.source_id AS tx_id, je.id AS entry_id, je.document_no,
                    (EXISTS(SELECT 1 FROM bank_posting_suggestions aps
                             WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                               AND aps.status = 'auto_posted')
                     OR EXISTS(SELECT 1 FROM activity_log aal
                                WHERE aal.supplier_id = je.supplier_id AND aal.action = 'bank_match.auto_posted'
                                  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(aal.payload, '$.journal_entry_id')) AS UNSIGNED) = je.id)) AS automated,
                    COALESCE((SELECT aps.source FROM bank_posting_suggestions aps
                      WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                        AND aps.status IN ('auto_posted', 'approved')
                      ORDER BY aps.id DESC LIMIT 1),
                      CASE WHEN EXISTS(SELECT 1 FROM activity_log aal
                                        WHERE aal.supplier_id = je.supplier_id AND aal.action = 'bank_match.auto_posted'
                                          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(aal.payload, '$.journal_entry_id')) AS UNSIGNED) = je.id)
                           THEN 'payment_match' ELSE NULL END) AS automation_source,
                    (SELECT r.name FROM bank_posting_suggestions aps
                       JOIN bank_posting_rules r ON r.id = aps.rule_id AND r.supplier_id = aps.supplier_id
                      WHERE aps.supplier_id = je.supplier_id AND aps.journal_entry_id = je.id
                        AND aps.status IN ('auto_posted', 'approved')
                      ORDER BY aps.id DESC LIMIT 1) AS automation_rule_name
               FROM journal_entries je
              WHERE je.supplier_id = ? AND je.source_type = 'bank' AND je.reversed_by IS NULL
                AND je.source_id IN ($ph)"
        );
        $entries->execute(array_merge([$supplierId], $txIds));
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['tx_id']] = [
                'status'           => 'posted',
                'journal_entry_id' => (int) $r['entry_id'],
                'document_no'      => $r['document_no'] !== null ? (string) $r['document_no'] : null,
                'automated'        => (bool) $r['automated'],
                'automation_source'=> $r['automation_source'] !== null ? (string) $r['automation_source'] : null,
                'rule_name'        => $r['automation_rule_name'] !== null ? (string) $r['automation_rule_name'] : null,
            ];
        }

        $sugs = $pdo->prepare(
            "SELECT s.bank_transaction_id AS tx_id, s.id, s.source, s.rule_id,
                    s.debit_account_code, s.credit_account_code, s.note, r.name AS rule_name
               FROM bank_posting_suggestions s
          LEFT JOIN bank_posting_rules r ON r.id = s.rule_id
              WHERE s.supplier_id = ? AND s.status = 'pending' AND s.bank_transaction_id IN ($ph)"
        );
        $sugs->execute(array_merge([$supplierId], $txIds));
        foreach ($sugs->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $txId = (int) $r['tx_id'];
            if (isset($out[$txId])) {
                continue; // posted má přednost
            }
            $out[$txId] = [
                'status'              => 'suggested',
                'suggestion_id'       => (int) $r['id'],
                'suggestion_source'   => (string) $r['source'],
                'rule_id'             => $r['rule_id'] !== null ? (int) $r['rule_id'] : null,
                'rule_name'           => $r['rule_name'] !== null ? (string) $r['rule_name'] : null,
                'debit_account_code'  => (string) $r['debit_account_code'],
                'credit_account_code' => (string) $r['credit_account_code'],
                'note'                => $r['note'] !== null ? (string) $r['note'] : null,
            ];
        }

        $accountStmt = $pdo->prepare(
            'SELECT label, account_canonical, bank_code, iban
               FROM supplier_bank_accounts WHERE supplier_id = ? AND is_active = 1'
        );
        $accountStmt->execute([$supplierId]);
        $ownAccounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
        $transfers = $pdo->prepare(
            "SELECT bt.id AS tx_id, bt.amount, bt.counterparty_account, bt.counterparty_bank,
                    CASE WHEN m.out_transaction_id = bt.id THEN m.in_transaction_id ELSE m.out_transaction_id END AS pair_tx_id,
                    pair_bt.statement_id AS pair_statement_id, pair_bt.posted_at AS pair_posted_at,
                    pair_je.id AS pair_entry_id
               FROM bank_transactions bt
          LEFT JOIN bank_transfer_matches m ON m.supplier_id = ?
                 AND (m.out_transaction_id = bt.id OR m.in_transaction_id = bt.id)
          LEFT JOIN bank_transactions pair_bt ON pair_bt.id = CASE
                    WHEN m.out_transaction_id = bt.id THEN m.in_transaction_id ELSE m.out_transaction_id END
          LEFT JOIN journal_entries pair_je ON pair_je.supplier_id = ? AND pair_je.source_type = 'bank'
                 AND pair_je.source_id = pair_bt.id AND pair_je.reversed_by IS NULL
              WHERE bt.id IN ($ph)
                AND EXISTS (SELECT 1 FROM bank_posting_suggestions s
                             WHERE s.supplier_id = ? AND s.bank_transaction_id = bt.id
                               AND s.source = 'transfer' AND s.status IN ('pending','approved','auto_posted'))"
        );
        $transfers->execute(array_merge([$supplierId, $supplierId], $txIds, [$supplierId]));
        foreach ($transfers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $txId = (int) $row['tx_id'];
            if (!isset($out[$txId])) continue;
            $label = null;
            $counterpartyCanonical = AccountNumberNormalizer::canonical((string) ($row['counterparty_account'] ?? ''));
            $counterpartyBank = AccountNumberNormalizer::canonicalBankCode(
                $row['counterparty_bank'] !== null ? (string) $row['counterparty_bank'] : null,
                (string) ($row['counterparty_account'] ?? ''),
            );
            foreach ($ownAccounts as $account) {
                if ($counterpartyCanonical !== (string) $account['account_canonical']) continue;
                $accountBank = AccountNumberNormalizer::canonicalBankCode(
                    $account['bank_code'] !== null ? (string) $account['bank_code'] : null,
                    $account['iban'] !== null ? (string) $account['iban'] : null,
                );
                if ($counterpartyBank !== null && $accountBank !== null && $counterpartyBank !== $accountBank) continue;
                $label = $account['label'] !== null ? (string) $account['label'] : null;
                break;
            }
            $pair = $row['pair_tx_id'] === null ? null : [
                'tx_id' => (int) $row['pair_tx_id'],
                'statement_id' => (int) $row['pair_statement_id'],
                'posted_at' => (string) $row['pair_posted_at'],
                'entry_id' => $row['pair_entry_id'] === null ? null : (int) $row['pair_entry_id'],
            ];
            $out[$txId]['transfer'] = [
                'direction' => (float) $row['amount'] < 0 ? 'out' : 'in',
                'own_account_label' => $label,
                'pair' => $pair,
            ];
            $out[$txId]['suggestion_source'] = 'transfer';
        }

        return $out;
    }
}
