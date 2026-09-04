<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Report\VatLedgerService;
use PDO;

/**
 * PostingService — JEDINÝ zdroj pravdy pro zaúčtování do deníku (Epic F1),
 * analogicky k {@see VatLedgerService} pro DPH. Sestaví vyvážený účetní zápis
 * (Σ MD = Σ D), zařadí ho do otevřeného účetního období dle data případu a
 * zapíše hlavičku + řádky v jedné transakci.
 *
 * KLÍČOVÉ INVARIANTY / SÉMANTIKA
 * ------------------------------------------------------------------------------
 * - Podvojnost: Σ MD == Σ D se ověřuje v HALÉŘÍCH (int), NIKDY přes float ==.
 *   Peníze jsou v celém codebase DECIMAL(x,2) ↔ PHP float zaokr. na 2 des. místa;
 *   porovnání součtů proto vždy přes (int) round($amount * 100). Nevyváženost →
 *   {@see UnbalancedEntryException}.
 * - Období: zápis se zařadí do období obsahujícího entry_date. Období MUSÍ být
 *   'open' — 'closing'/'closed' → {@see PostingException} (§35 ZoÚ: uzavřené období
 *   je neměnné). Chybí-li období ÚPLNĚ, založí se otevřené
 *   ({@see AccountingPeriodProvisioner}) — a to i do minulosti, protože přesně tak
 *   vypadá migrace historických dokladů z jiného systému do instalace, která má
 *   účetnictví aktivované od letoška. Existujícím obdobím se automat nedotkne, takže
 *   §35 ZoÚ platí dál: nad uzavřeným obdobím zápis skončí na 'period_not_open'.
 *   Neotevře se rok mimo rozsah 2000–2200 a rok, jehož dopočtené hranice by se
 *   překryly s existující (nepravidelnou) řadou — tam skončí zápis na
 *   'no_accounting_period' a rozhodne účetní.
 * - Idempotence (source_type + source_id): opakované postDocument téhož dokladu
 *   NEDUPLIKUJE. Existující zápis se přepočítá a přepíše IN-PLACE (smaž řádky +
 *   přepiš hlavičku i řádky, row_version++), zachová se stejné entry.id. Přechod
 *   koncept (posted_at NULL) → zaúčtováno je podporován. Oprava zaúčtovaného
 *   zápisu v UZAVŘENÉM období není možná — kontrola otevřenosti období vyhodí
 *   PostingException dřív; korekce po uzávěrce se dělá výhradně přes reverse()
 *   + nový zápis (§35). Manuální zápisy bez source_id se neidempotují (vždy nový).
 * - Audit: každý post/reverse zapíše do activity_log (action accounting.posted /
 *   accounting.reversed) s payloadem (before/after u přepisu).
 */
final class PostingService
{
    public const POSTABLE_ISSUED_INVOICE_TYPES = ['invoice', 'credit_note', 'tax_document', 'penalty'];

    /**
     * Strop haléřového dorovnání 648/548 (v haléřích). Nad tímto rozdílem hlavička
     * dokladu (total_with_vat) a DPH evidence (základ+daň) prokazatelně nesedí (chybná
     * klasifikace řádku, ručně přepsaný total, §75 bez správného zaokrouhlení) — takový
     * rozdíl se NESMÍ tiše sesypat do 648/výnosu nebo 548/nákladu (masky nekonzistence
     * dat), ale musí jít zpátky k účetní jako chyba dokladu. 2 Kč (dvojnásobek
     * BankPostingService::ROUNDING_TOLERANCE_CENTS, protože sem navíc vstupuje i
     * kurzové zaokrouhlení dvou nezávislých přepočtů — hlavičky a per-řádku).
     */
    private const ROUNDING_TOLERANCE_CENTS = 200;

    /**
     * Fallback účtu pro daň v režimu OSS, když kontace `oss.output.vat` chybí (instance,
     * která ještě nemá migraci 1295). Analytika k 345 (ostatní daně) — daň v režimu
     * jednoho správního místa NENÍ česká daň na výstupu: patří státu spotřeby, do
     * přiznání k DPH ani do KH nevstupuje a odvádí se samostatně. Na 343 by trvale
     * rozbíjela vazbu „zůstatek účtu = přiznání".
     */
    private const OSS_OUTPUT_VAT_ACCOUNT = '345.100';

    /**
     * Analytiky DPH (migrace 1323). Do teď měla KAŽDÁ noha daně holé '343', takže se
     * daň na vstupu a na výstupu na jednom účtu okamžitě vzájemně vynetovala. Účetní
     * je vede odděleně a na konci každého zdaňovacího období je interním dokladem
     * převádí na zúčtovací účet (viz {@see Vat\VatClearingService}):
     *   MD 343.200 / D 343.900  … daň na výstupu za období
     *   MD 343.900 / D 343.100  … daň na vstupu za období
     * Po něm jsou 343.100 i 343.200 za období nulové a na 343.900 leží přesně to, co
     * se odvede (nebo vrátí) — úhrada z banky ho pak vynuluje.
     *
     * Jsou to FALLBACKY: přednost má vždy kontace z `posting_rules` (viz {@see ruleCode()}),
     * ať si tenant může analytiky přepnout nebo zůstat na plochém 343.
     */
    /** Syntetika DPH — prefix, pod který všechny tři analytiky patří. */
    public const VAT_SYNTHETIC = '343';

    public const INPUT_VAT_ACCOUNT = '343.100';
    public const OUTPUT_VAT_ACCOUNT = '343.200';
    public const VAT_SETTLEMENT_ACCOUNT = '343.900';

    /** @var array<int, ?string> per-request cache supplier_id => locked_until (B8). */
    private array $lockedUntilCache = [];

    /** @var array<string, bool> per-request cache "supplierId|code" => účet je v osnově a aktivní. */
    private array $postableAccountCache = [];

    /** @var array<int, array<string,string>> per-request cache supplier_id => [syntetika => jediná analytika]. */
    private array $singleAnalyticCache = [];

    /** @var array<string,string> per-request cache "supplierId|code" => výsledný nedaňový účet. */
    private array $nonDeductibleAccountCache = [];

    /**
     * Syntetiky, u kterých analytiku vybírá KONTEXT dokladu, ne osnova — proto se na ně
     * automatický přesměr {@see singleAnalyticMap()} nikdy nepoužije:
     *
     *  - 221 / 211 … analytika je dána bankovním účtem výpisu, resp. pokladnou dokladu
     *    ({@see Bank\BankAnalyticResolver}, CashRegisterService). Firma může mít dnes
     *    jeden účet a zítra dva; „jediná analytika" je u nich náhodný okamžitý stav a
     *    svést na ni nespárovaný pohyb by zamaskovalo neznámou protistranu.
     *  - 343 … vstup/výstup/zúčtování rozhoduje směr daně ({@see vatAccount()},
     *    Vat\VatClearingService). Se třemi analytikami by přesměr stejně nenaskočil,
     *    ale kdyby si tenant dvě smazal, nesmí zbylá spolknout všechno.
     *  - 345 … šablona pod ním veze 345.100 = DPH v režimu OSS, tedy daň JINÉHO státu.
     *    To je podmnožina, ne náhrada: daň z nemovitostí ani silniční na ni nepatří.
     *    Bez téhle výjimky by přesměr nastal u KAŽDÉHO tenanta hned po naseedování.
     */
    private const CONTEXT_DRIVEN_SYNTHETICS = ['211', '221', '343', '345'];

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly AccountingPeriodRepository $periods,
        private readonly PostingRuleRepository $rules,
        private readonly JournalEntryRepository $journal,
        private readonly VatLedgerService $vatLedger,
        private readonly ActivityLogger $activity,
        private readonly ExpenseClassificationService $expenseClassification,
    ) {}

    /**
     * Zakladatel chybějících období. Staví se tady, ne v konstruktoru: potřebuje jen
     * závislosti, které tahle služba už má, a přidání devátého parametru do ctoru by
     * se protáhlo do každého místa, které PostingService skládá ručně. Stejný vzor
     * jako `new UnbookedDocumentsCounter($this->db)` v CrmAggregationService.
     */
    private function provisioner(): AccountingPeriodProvisioner
    {
        return new AccountingPeriodProvisioner($this->db, $this->periods, $this->activity);
    }

    /**
     * Chybějící účetní období → chyba, která uživatele DOVEDE tam, kde se období
     * zakládá. Doteď hláška končila u konstatování „období neexistuje", případně
     * odkazovala na sekci menu, která se tak nejmenuje (viz
     * {@see \MyInvoice\Service\Accounting\AccountingPeriodHealthService}) — účetní
     * pak hledala po záložkách. `entry_date`/`fiscal_year` v kontextu chyby jsou
     * strojová část: rozhraní z nich staví proklik na Uzávěrku s předvyplněným
     * rokem (`web/src/api/errors.ts::accountingPeriodTarget`), stejným vzorem jako
     * `payroll_year_closed`.
     */
    private static function noPeriodException(string $date, string $message): PostingException
    {
        return new PostingException('no_accounting_period', $message, 422, [
            'entry_date'  => $date,
            'fiscal_year' => (int) substr($date, 0, 4),
        ]);
    }

    /**
     * Sestaví a zapíše vyvážený účetní zápis. Vrací id zápisu (existující při
     * re-postu, nový jinak).
     *
     * @param 'invoice'|'purchase_invoice'|'bank'|'cash'|'asset'|'manual'|'closing'|'opening'|'payroll'|'vat_clearing' $sourceType
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float|int|string, cost_center?:?string}> $lines
     * @param array{
     *     entry_date:string, document_date?:?string, document_no?:?string, description?:?string,
     *     posted?:bool, posted_at?:?string, posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string,
     *     expected_row_version?:?int
     * } $meta
     *
     * @throws UnbalancedEntryException když Σ MD ≠ Σ D
     * @throws PostingException při chybějícím účtu / zavřeném / chybějícím období
     */
    public function postDocument(int $supplierId, string $sourceType, ?int $sourceId, array $lines, array $meta): int
    {
        if ($lines === []) {
            throw new PostingException('empty_entry', 'Účetní zápis nemá žádné řádky.');
        }
        $entryDate = (string) ($meta['entry_date'] ?? '');
        if ($entryDate === '') {
            throw new PostingException('missing_entry_date', 'Chybí datum účetního případu (meta.entry_date).');
        }
        if ($sourceType === 'payroll') {
            $this->assertPayrollPostingContext($supplierId, $sourceId);
        }

        // 1) překlad CODE → account_id (v rámci osnovy firmy) + kontrola vyváženosti.
        //    Vyváženost se ověřuje na RESOLVED řádcích (částky zaokr. na 2 des. místa),
        //    tj. přesně na tom, co se uloží — ne na surovém vstupu.
        $codeMap = $this->accounts->codeToIdMap($supplierId);
        $resolved = $this->resolveLines($supplierId, $lines, $codeMap, $sourceType);
        $resolved = $this->stampProjectDimension($supplierId, $sourceType, $sourceId, $resolved);
        self::assertBalanced($resolved); // v haléřích; UnbalancedEntryException při nerovnosti

        // R7 (Epic F4): flag allow_closing_period smí nastavit VÝHRADNĚ ClosingService —
        // závěrkové zápisy k ends_on se účtují do období ve stavu 'closing' (nikdy closed/approved).
        $allowClosing = !empty($meta['allow_closing_period']);
        $allowedStatuses = $allowClosing ? ['open', 'closing'] : ['open'];

        $posted   = (bool) ($meta['posted'] ?? true);
        $postedBy = $posted ? ($meta['posted_by'] ?? null) : null;

        // 2)+3) zápis v transakci. EP-3 (souběh účtování × uzávěrky): období pro entry_date
        //   se načítá AŽ UVNITŘ transakce a POD řádkovým zámkem (SELECT … FOR UPDATE), takže
        //   souběžná uzávěrka (ClosingService::start/closeBooks… drží týž řádek období přes
        //   lockPeriod → findForUpdate) se SERIALIZUJE: buď tento zápis zamkne období první a
        //   uzávěrka počká, nebo uzávěrka commitne přechod na 'closing'/'closed' a tento zápis
        //   pak POD zámkem uvidí nový stav a odmítne (period_not_open). Dřív se stav ověřoval
        //   PŘED začátkem transakce, takže zápis mohl propadnout do právě uzavíraného období.
        //   Pořadí zámků je vždy období → řádky deníku (shodné s ClosingService::lockPeriod →
        //   postDocument), takže nevzniká cyklus (deadlock).
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $period = $this->periods->findForDateForUpdate($supplierId, $entryDate);
            if ($period === null) {
                // Chybějící období (zapomenutý přelom roku, naimportovaná historie) se
                // založí otevřené — jediné pravidlo pro to má
                // {@see AccountingPeriodProvisioner}, sdílené s importem. Nedotýká se
                // ničeho existujícího, takže §35 ZoÚ zůstává v platnosti: pokrývá-li
                // datum UZAVŘENÉ období, provisioner ho jen vrátí a kontrola stavu níž
                // zápis odmítne. Znovu pod zámkem, ať se souběh serializuje jako dřív.
                $this->provisioner()->ensureOpenPeriodForDate(
                    $supplierId,
                    $entryDate,
                    AccountingPeriodProvisioner::REASON_POSTING,
                    isset($meta['user_id']) ? (int) $meta['user_id'] : null,
                );
                $period = $this->periods->findForDateForUpdate($supplierId, $entryDate);
            }
            if ($period === null) {
                throw self::noPeriodException($entryDate, 'Pro datum ' . $entryDate . ' neexistuje účetní období.');
            }
            if (!in_array($period['status'], $allowedStatuses, true)) {
                throw new PostingException(
                    'period_not_open',
                    'Účetní období ' . $period['fiscal_year'] . ' je ve stavu "' . $period['status']
                        . '" — do uzavřeného období nelze účtovat (§35 ZoÚ).',
                );
            }

            // B8 (audit 2026-07): měkký zámek k datu (soft-close) — jemnější než celý rok.
            // Datum <= locked_until je zamčené (podané DPH přiznání / ruční zámek). Zámek je
            // tvrdá brána bez bypassu z requestu (§35) — opravu zamčeného zápisu řeší storno
            // protizápisem do otevřeného data (viz reverse()), ne obcházení tohoto zámku.
            $lockedUntil = $this->lockedUntilForUpdate($supplierId);
            if ($lockedUntil !== null && $entryDate <= $lockedUntil) {
                throw new PostingException(
                    'date_locked',
                    'Datum účetního případu ' . $entryDate . ' je uzamčené k ' . $lockedUntil
                        . ' (podané daňové přiznání / ruční zámek období) — do zamčeného data nelze účtovat. '
                        . 'Zámek posuň v nastavení účetnictví (jen admin).',
                );
            }

            // Popis zápisu: když ho volající nedodá (auto-post / bulk / ruční post bez
            // textu), dopočítá se deterministický default z dat dokladu — deník nesmí
            // zůstat s prázdným popisem („—"). Explicitní popis (storno/oprava/uzávěrka)
            // má vždy přednost a nechává se beze změny.
            $description = $meta['description'] ?? null;
            if ($description === null || trim((string) $description) === '') {
                $description = $this->defaultDescription($supplierId, $sourceType, $sourceId);
            }
            $header = [
                'supplier_id'   => $supplierId,
                'period_id'     => (int) $period['id'],
                'entry_date'    => $entryDate,
                'document_date' => $meta['document_date'] ?? null,
                'document_no'   => $meta['document_no'] ?? null,
                'description'   => $description,
                'source_type'   => $sourceType,
                'source_id'     => $sourceId,
                'posted_at'     => $posted ? ($meta['posted_at'] ?? date('Y-m-d H:i:s')) : null,
                'posted_by'     => $postedBy,
            ];

            $existing = $sourceId !== null
                ? $this->journal->findBySourceForUpdate($supplierId, $sourceType, $sourceId)
                : null;

            if ($existing !== null) {
                if ($sourceType === 'payroll') {
                    throw new PostingException(
                        'payroll_rewrite_forbidden',
                        'Zaúčtovaný mzdový předpis je neměnný; oprava patří do nové revize.',
                    );
                }
                $existing['lines'] = $this->journal->linesForEntry((int) $existing['id'], $supplierId);
                $entryId = $this->rewriteExisting(
                    $supplierId,
                    $existing,
                    $header,
                    $resolved,
                    $allowClosing,
                    isset($meta['expected_row_version']) ? (int) $meta['expected_row_version'] : null,
                );
                $auditPayload = [
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'reposted'    => true,
                    'before'      => ['posted_at' => $existing['posted_at'], 'lines' => $existing['lines'] ?? []],
                    'after'       => ['posted_at' => $header['posted_at'], 'lines' => $resolved],
                ];
            } else {
                try {
                    $entryId = $this->journal->insert($header, $resolved);
                } catch (\PDOException $e) {
                    // Souběžný request stihl vložit zápis pro týž zdroj dřív (unique
                    // uq_je_supplier_source) → převeď na idempotentní přepis místo duplicity.
                    if (($e->errorInfo[0] ?? null) !== '23000' || $sourceId === null) {
                        throw $e;
                    }
                    // ZAMYKAJÍCÍ čtení: prostý findBySource by v REPEATABLE READ vracel
                    // snapshot z počátku transakce, kde vítězný (čerstvě commitnutý) řádek
                    // ještě není → null a re-throw. FOR UPDATE přečte poslední commitnutou
                    // verzi, takže retry dostane AKTUÁLNÍ vítězný zápis, ne stale/null.
                    $raced = $this->journal->findBySourceForUpdate($supplierId, $sourceType, $sourceId);
                    if ($raced === null) {
                        throw $e;
                    }
                    if ($sourceType === 'payroll') {
                        throw new PostingException(
                            'payroll_rewrite_forbidden',
                            'Zaúčtovaný mzdový předpis je neměnný; oprava patří do nové revize.',
                        );
                    }
                    $raced['lines'] = $this->journal->linesForEntry((int) $raced['id'], $supplierId);
                    $entryId = $this->rewriteExisting(
                        $supplierId,
                        $raced,
                        $header,
                        $resolved,
                        $allowClosing,
                        isset($meta['expected_row_version']) ? (int) $meta['expected_row_version'] : null,
                    );
                }
                $auditPayload = [
                    'source_type' => $sourceType,
                    'source_id'   => $sourceId,
                    'reposted'    => false,
                    'lines'       => $resolved,
                ];
            }

            $this->activity->log(
                'accounting.posted',
                $meta['user_id'] ?? null,
                'journal_entry',
                $entryId,
                $auditPayload,
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
            );

            if ($ownTx) {
                $pdo->commit();
            }
            return $entryId;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Vytvoří zrcadlový (storno) zápis k existujícímu — prohodí strany řádků,
     * zaúčtuje do otevřeného období data storna a naváže original.reversed_by.
     * Neměnnost po uzávěrce (§35): původní zápis se nemaže, opravuje se protizápisem.
     *
     * @param array{entry_date?:string, description?:?string, posted_by?:?int, user_id?:?int, ip?:?string, user_agent?:?string} $meta
     * @return int id storno zápisu
     *
     * @throws PostingException zápis neexistuje / už stornován / období storna není otevřené
     */
    public function reverse(int $supplierId, int $entryId, array $meta = []): int
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
        $original = $this->journal->findForUpdate($entryId, $supplierId);
        if ($original === null) {
            throw new PostingException('entry_not_found', 'Účetní zápis #' . $entryId . ' neexistuje.', 404);
        }
        if (($original['source_type'] ?? null) === 'payroll') {
            throw new PostingException(
                'payroll_reversal_forbidden',
                'Mzdový předpis se opravuje výhradně novou mzdovou revizí a rozdílovým zápisem.',
            );
        }
        if ($original['reversed_by'] !== null) {
            throw new PostingException('already_reversed', 'Zápis #' . $entryId . ' už byl stornován.');
        }
        // Storno má smysl jen pro ZAÚČTOVANÝ zápis; koncept (posted_at NULL) se
        // opraví/smaže rovnou, ne protizápisem (audit C-5).
        if (($original['posted_at'] ?? null) === null) {
            throw new PostingException('entry_not_posted', 'Zápis #' . $entryId . ' je koncept — storno se dělá jen u zaúčtovaného zápisu.');
        }
        /** @var list<array<string,mixed>> $origLines */
        $origLines = $original['lines'] ?? [];
        if ($origLines === []) {
            throw new PostingException('empty_entry', 'Zápis #' . $entryId . ' nemá řádky ke stornu.');
        }

        // B8: storno MUSÍ být možné i pro zamčený doklad, ale protizápis (mirror) se má
        // zaúčtovat do OTEVŘENÉHO (nezamčeného) data. Když volající datum storna nezadá
        // a původní datum je zamčené, posuneme protizápis na dnešek; explicitně zadané
        // zamčené datum odmítneme (date_locked). Zavřené/uzavírané období (§35) je tvrdší
        // zámek a řeší se kontrolou stavu období níž — proto se date-lock posuzuje jen
        // když je období otevřené.
        $explicitDate = (isset($meta['entry_date']) && (string) $meta['entry_date'] !== '')
            ? (string) $meta['entry_date'] : null;
        $reversalDate = $explicitDate ?? (string) $original['entry_date'];
        // R7 (Epic F4): storno dohadu/čas. rozlišení z uzávěrkového průvodce ve stavu 'closing'.
        $allowedStatuses = !empty($meta['allow_closing_period']) ? ['open', 'closing'] : ['open'];

        $period = $this->provisioner()->ensureOpenPeriodForDate(
            $supplierId,
            $reversalDate,
            AccountingPeriodProvisioner::REASON_POSTING,
            isset($meta['user_id']) ? (int) $meta['user_id'] : null,
        );
        if ($period === null) {
            throw self::noPeriodException($reversalDate, 'Pro datum storna ' . $reversalDate . ' neexistuje účetní období.');
        }
        $periodOpen = in_array($period['status'], $allowedStatuses, true);

        $lockedUntil = $this->lockedUntil($supplierId);
        if ($periodOpen && $lockedUntil !== null && $reversalDate <= $lockedUntil) {
            if ($explicitDate !== null) {
                throw new PostingException(
                    'date_locked',
                    'Datum storna ' . $reversalDate . ' je uzamčené k ' . $lockedUntil
                        . ' — storno musí být datované do otevřeného (nezamčeného) data.',
                );
            }
            $today = date('Y-m-d');
            if ($today <= $lockedUntil) {
                throw new PostingException(
                    'date_locked',
                    'Zámek období k ' . $lockedUntil . ' zasahuje i aktuální datum — storno nelze '
                        . 'zaúčtovat do otevřeného data. Posuň zámek zpět v nastavení účetnictví (jen admin).',
                );
            }
            $reversalDate = $today;
            $period = $this->provisioner()->ensureOpenPeriodForDate(
                $supplierId,
                $reversalDate,
                AccountingPeriodProvisioner::REASON_POSTING,
                isset($meta['user_id']) ? (int) $meta['user_id'] : null,
            );
            if ($period === null) {
                // Auto-posun protizápisu na dnešek, ale pro dnešní datum není založené
                // účetní období a ani ho nelze automaticky otevřít (řada dnešek přeskakuje
                // nebo firma nemá jediné období).
                throw self::noPeriodException(
                    $reversalDate,
                    'Storno zamčeného zápisu se má zaúčtovat k dnešku (' . $reversalDate
                        . '), ale pro toto datum neexistuje otevřené účetní období — '
                        . 'založ účetní období pro aktuální rok v Účetnictví → Uzávěrka, nebo zadej '
                        . 'datum storna do otevřeného (nezamčeného) období ručně.',
                );
            }
            $periodOpen = in_array($period['status'], $allowedStatuses, true);
        }

        if (!$periodOpen) {
            throw new PostingException('period_not_open', 'Období storna je "' . $period['status'] . '" — storno nelze zaúčtovat.');
        }

        // Zrcadlo: stejný účet, opačná strana, stejná částka (vč. cizoměnové stopy).
        $mirror = [];
        foreach ($origLines as $line) {
            $mirror[] = [
                'account_id'     => (int) $line['account_id'],
                'side'           => $line['side'] === 'debit' ? 'credit' : 'debit',
                'amount'         => $line['amount'],
                'currency_code'  => $line['currency_code'] ?? null,
                'fx_rate'        => $line['fx_rate'] ?? null,
                'amount_foreign' => $line['amount_foreign'] ?? null,
                'cost_center'    => $line['cost_center'] ?? null,
                // Zakázka se do storna MUSÍ přenést, jinak by protizápis náklad akce
                // odečetl z „bez zakázky" a v marži by původní řádek zůstal navždy.
                'project_id'     => isset($line['project_id']) ? (int) $line['project_id'] : null,
            ];
        }

        $header = [
            'supplier_id'   => $supplierId,
            'period_id'     => (int) $period['id'],
            'entry_date'    => $reversalDate,
            'document_date' => $original['document_date'] ?? null,
            'document_no'   => $original['document_no'] !== null
                ? mb_substr('STORNO ' . $original['document_no'], 0, 50)
                : null,
            'description'   => $meta['description'] ?? ('Storno účetního zápisu #' . $entryId),
            'source_type'   => (string) $original['source_type'],
            // source_id ZÁMĚRNĚ NULL — storno není zdrojový doklad, jinak by findBySource
            // idempotence našla storno místo originálu. Vazba je přes reversed_by.
            'source_id'     => null,
            'posted_at'     => date('Y-m-d H:i:s'),
            'posted_by'     => $meta['posted_by'] ?? null,
        ];

            $reversalId = $this->journal->insert($header, $mirror);
            // Atomická pojistka proti dvojímu stornu (audit F-1): podmíněný UPDATE
            // reversed_by IS NULL. Když mezitím stornoval někdo jiný, vrátí false →
            // rollback celé transakce (i právě vloženého zrcadla).
            if (!$this->journal->setReversedBy($entryId, $supplierId, $reversalId)) {
                throw new PostingException('already_reversed', 'Zápis #' . $entryId . ' byl mezitím stornován.');
            }

            $this->activity->log(
                'accounting.reversed',
                $meta['user_id'] ?? null,
                'journal_entry',
                $reversalId,
                ['original_entry_id' => $entryId, 'reversal_entry_id' => $reversalId, 'lines' => $mirror],
                $meta['ip'] ?? null,
                $meta['user_agent'] ?? null,
                $supplierId,
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

    private function assertPayrollPostingContext(
        int $supplierId,
        ?int $revisionId,
    ): void {
        if ($revisionId === null || $revisionId <= 0) {
            throw new PostingException(
                'payroll_posting_context_required',
                'Mzdový předpis vyžaduje připravenou dávku schválené revize.',
            );
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT batch.status, batch.journal_entry_id
               FROM payroll_posting_batches batch
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = batch.supplier_id
                AND revision.id = batch.revision_id
                AND revision.run_id = batch.run_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE batch.supplier_id = ?
                AND batch.revision_id = ?
                AND revision.status = "approved"
                AND run.current_revision_no = revision.revision_no
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $revisionId]);
        $context = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($context)) {
            throw new PostingException(
                'payroll_posting_context_required',
                'Mzdový předpis vyžaduje připravenou dávku schválené revize.',
            );
        }
        $status = $context['status'] ?? null;
        $journalEntryId = $context['journal_entry_id'] ?? null;
        if ($status !== 'prepared'
            && !($status === 'posted' && $journalEntryId !== null)
        ) {
            throw new PostingException(
                'payroll_posting_context_required',
                'Mzdový předpis vyžaduje připravenou dávku schválené revize.',
            );
        }
    }

    /**
     * Idempotentní přepis existujícího zápisu (re-post téhož dokladu). Před přepisem
     * vynucuje dvě neměnnostní pojistky (§35 ZoÚ), které surový replace() neměl:
     *   - HIGH: zápis už STORNOVANÝ (reversed_by != NULL) se NEpřepisuje — jinak by
     *     zůstal viset protizápis na staré částky → nevyrovnané knihy. Oprava po
     *     stornu se dělá novým zápisem, ne mutací stornovaného.
     *   - HIGH: zápis, jehož SOUČASNÉ období je zavřené/uzavírané, nelze přepsat ani
     *     přesunout do jiného období — do uzavřeného období se nesahá (kontrola nového
     *     entry_date už proběhla ve postDocument; tady hlídáme staré období zápisu).
     *
     * @param array<string,mixed> $existing zápis z findBySource (+ načtené 'lines')
     * @param array<string,mixed> $header
     * @param list<array{account_id:int, side:'debit'|'credit', amount:float, cost_center:?string}> $resolved
     */
    private function rewriteExisting(
        int $supplierId,
        array $existing,
        array $header,
        array $resolved,
        bool $allowClosing = false,
        ?int $expectedRowVersion = null,
    ): int
    {
        $entryId = (int) $existing['id'];

        if ($expectedRowVersion !== null && (int) ($existing['row_version'] ?? -1) !== $expectedRowVersion) {
            throw new PostingException(
                'version_conflict',
                'Zápis mezitím změnil jiný uživatel - načtěte aktuální stav.',
                409,
            );
        }

        if (($existing['reversed_by'] ?? null) !== null) {
            throw new PostingException(
                'entry_reversed',
                'Zápis #' . $entryId . ' je stornovaný — přepis není možný (§35). '
                    . 'Opravu zaúčtuj novým zápisem.',
            );
        }

        // B8: re-post do zamčeného data se odmítne stejně jako u zavřeného období —
        // hlídá se PŮVODNÍ datum zápisu (existing.entry_date). Oprava zamčeného zápisu
        // se dělá výhradně přes storno + nový zápis do otevřeného data.
        $lockedUntil = $this->lockedUntil($supplierId);
        $origDate = (string) ($existing['entry_date'] ?? '');
        if ($lockedUntil !== null && $origDate !== '' && $origDate <= $lockedUntil) {
            throw new PostingException(
                'date_locked',
                'Zápis #' . $entryId . ' má datum ' . $origDate . ' v uzamčeném období (zámek k '
                    . $lockedUntil . ') — re-post do zamčeného data není možný. '
                    . 'Oprava přes storno + nový zápis do otevřeného data.',
            );
        }

        // R7 (Epic F4): re-run závěrkového kroku přepisuje zápis v období 'closing'.
        $allowedStatuses = $allowClosing ? ['open', 'closing'] : ['open'];
        $currentPeriod = $this->periods->findById($supplierId, (int) $existing['period_id']);
        if ($currentPeriod !== null && !in_array($currentPeriod['status'], $allowedStatuses, true)) {
            throw new PostingException(
                'period_not_open',
                'Zápis #' . $entryId . ' je v období "' . $currentPeriod['status']
                    . '" — do uzavřeného období nelze zasahovat (§35 ZoÚ).',
            );
        }

        $this->journal->replace($entryId, $header, $resolved);
        return $entryId;
    }

    // ── build helpers (vrací řádky; zápis dělá postDocument → jednotkově testovatelné) ──

    /**
     * Sestaví řádky pro vydanou fakturu: 311 (odběratelé) / 6xx (výnos) + 343 (DPH).
     * Základ i daň se berou z {@see VatLedgerService} (jediný zdroj DPH), zaokr. rozdíl
     * (haléřové zaokrouhlení faktury) padne na 648/548, aby seděla podvojnost.
     *
     * Dobropis (invoice_type='credit_note', poznatelný i podle záporného total_with_vat)
     * má všechny strany OTOČENÉ oproti běžné faktuře (311 D / 6xx MD / 343 MD) a částky
     * v abs() — ledger u dobropisu vrací base_czk/vat_czk se stejným (záporným)
     * znaménkem jako doklad, takže $net/$vat/$totalCzk zůstávají signed až do sestavení
     * řádků (audit B4).
     *
     * @param array{rule_key?:string, cost_center?:?string, item_classification_overrides?:array<int,array{expense_kind:string,expense_account_code:?string}>} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    public function buildFromInvoice(int $supplierId, int $invoiceId, array $opts = []): array
    {
        $inv = $this->fetchDocHeader('invoices', $supplierId, $invoiceId);
        if ($inv === null) {
            throw new PostingException('entry_not_found', 'Vydaná faktura #' . $invoiceId . ' neexistuje.', 404);
        }
        if (in_array((string) ($inv['status'] ?? ''), ['draft', 'cancelled'], true)) {
            throw new PostingException(
                'document_not_postable',
                'Vydanou fakturu #' . $invoiceId . ' ve stavu "' . ($inv['status'] ?? '') . '" nelze zaúčtovat.',
            );
        }

        // Účtovatelnost podle TYPU dokladu. Konstanta existovala už dřív, ale jen ji
        // ČETLI volající (fronty „k zaúčtování", backfill) — sem se nikdy nepromítla,
        // takže typ mimo seznam propadl do běžné větve a zaúčtoval se jako faktura.
        // Konkrétně `cancellation` (interní storno): `ClosingRepository::unpostedInvoices`
        // ho vylučuje, PDF ho značí „Storno (interní)", ale `buildFromInvoice` ho pustil
        // na 311/602/343 — fantomový výnos i daň na výstupu. V ostrých datech 0 výskytů
        // (sonda private/scripts/cancellation_exposure.php), takže díra byla latentní.
        // Kontrola je záměrně proti SEZNAMU, ne proti jedné hodnotě: nová hodnota enumu
        // z budoucí migrace tak neprojde mlčky, ale narazí. Zrcadlí `advance_payment_only`
        // na přijaté větvi. `proforma` sem spadá taky — dřív selhala až o kus níž na
        // prázdné rekapitulaci DPH, což byla správná odpověď z nesprávného důvodu.
        $invoiceType = (string) ($inv['invoice_type'] ?? 'invoice');
        if (!in_array($invoiceType, self::POSTABLE_ISSUED_INVOICE_TYPES, true)) {
            throw new PostingException(
                'document_not_postable',
                'Vydaný doklad #' . $invoiceId . ' typu "' . $invoiceType . '" se do deníku neúčtuje.',
            );
        }

        // DDKP (daňový doklad k přijaté platbě, §28/2/d ZDPH) NENÍ běžná faktura:
        // zákazník už zaplatil zálohu (zaúčtováno 221/324), doklad jen přiznává DPH ze
        // zálohy → 324/343, ŽÁDNÉ 311, ŽÁDNÝ výnos 602 (viz buildAdvanceVatDocumentLines).
        if ((string) ($inv['invoice_type'] ?? 'invoice') === 'tax_document') {
            return $this->buildAdvanceVatDocumentLines($supplierId, $invoiceId, $inv, $opts);
        }

        // Penalizační faktura (úrok z prodlení, NV 351/2013): celá částka je výnos
        // 644 (smluvní pokuty a úroky z prodlení), MIMO předmět DPH (§2 ZDPH → žádná
        // noha 343, doklad není v DPH evidenci — VatLedgerService ho vylučuje).
        if ((string) ($inv['invoice_type'] ?? 'invoice') === 'penalty') {
            return $this->buildPenaltyInvoiceLines($supplierId, $invoiceId, $inv, $opts);
        }

        [$net, $vat] = $this->ledgerTotals(
            $supplierId,
            'sale',
            $invoiceId,
            (string) ($inv['tax_date'] ?? $inv['issue_date']),
            (string) $inv['issue_date'],
        );

        $rate    = $this->fxRate($inv);
        $totalCzk = round((float) $inv['total_with_vat'] * $rate, 2);

        // OSS (§110 a násl. ZDPH) — VatLedgerService OSS řádky z DPH evidence VYLUČUJE
        // (patří do přiznání státu spotřeby, ne do českého), takže do $net/$vat vůbec
        // nepřitečou a doklad se o ně musí doplnit ZVLÁŠŤ. Bez toho hlavička (která OSS
        // obsahuje) neseděla na základ+daň a doklad končil na 'totals_mismatch',
        // resp. u čistě OSS dokladu rovnou na 'document_not_postable'.
        [$ossNet, $ossVat] = $this->ossItemTotals($invoiceId, $rate);

        if ($net + $vat + $ossNet + $ossVat === 0.0) {
            throw new PostingException('document_not_postable', 'Faktura #' . $invoiceId . ' nemá DPH řádky k zaúčtování (proforma/storno?).');
        }

        $isCreditNote = ((string) ($inv['invoice_type'] ?? 'invoice') === 'credit_note') || $totalCzk < 0.0;

        // Výnosový účet: explicitní opts['rule_key'] vyhrává; jinak volitelný příznak na
        // hlavičce faktury (revenue_rule_key, např. asset.sale.revenue → 311/641 u prodeje
        // dlouhodobého majetku); default invoice.services.issued (602). Řeší se TADY, ne u
        // jednotlivých volajících, aby doklad účtoval stejně bez ohledu na cestu (auto-post,
        // ruční post, re-post po editaci) a stávající faktury (revenue_rule_key NULL) se nehnuly.
        $headerRuleKey = ($inv['revenue_rule_key'] ?? null) !== null && (string) $inv['revenue_rule_key'] !== ''
            ? (string) $inv['revenue_rule_key']
            : 'invoice.services.issued';
        $rule = $this->rules->resolve($supplierId, $opts['rule_key'] ?? $headerRuleKey);
        $receivable = $rule['debit_account_code'] ?? '311';
        $revenue    = $rule['credit_account_code'] ?? '602';
        $cc = $opts['cost_center'] ?? null;

        // Rozpad výnosu po POLOŽKÁCH (1177) — zrcadlo nákladové strany z §DM. Faktura běžně
        // míchá prodej majetku se službami a jedna noha by celý základ poslala na jeden účet.
        // Váhy se počítají jen když je aspoň jedna položka navázaná na kartu majetku; jinak
        // zůstane null a doklad se zaúčtuje jednou nohou jako dosud (byte-identické chování).
        // Explicitní rule_key od volajícího je adresný pokyn pro CELÝ doklad (ruční zaúčtování
        // s vybranou předkontací) → rozpad se pak nedělá; hlavičkový revenue_rule_key naopak
        // slouží jen jako výchozí účet NEklasifikovaných řádků, takže rozpad neruší.
        $weights = isset($opts['rule_key'])
            ? null
            : $this->revenueWeights($supplierId, $invoiceId, $rate, $revenue);

        // Normálně 311 MD / 6xx+343 D; dobropis obrací obě strany (§ vratka výnosu i DPH).
        $receivableSide = $isCreditNote ? 'credit' : 'debit';
        $otherSide      = $isCreditNote ? 'debit'  : 'credit';

        $lines = [];
        // Saldokonto 311 nese i cizí měnu/kurz/částku (§4/12 — přecenění §24/6).
        $lines[] = $this->withForeign($this->line($receivable, $receivableSide, abs($totalCzk), $cc), $inv, $rate);
        // Výnos je výnos bez ohledu na to, kterému státu patří daň — OSS základ jde na
        // TÝŽ výnosový účet (a do téhož rozpadu; revenueWeights počítá váhy ze VŠECH
        // položek dokladu, tedy i z těch OSS, takže by rozpad jinak nesouhlasil).
        $this->appendSplit($lines, $weights, $revenue, $otherSide, $net + $ossNet, $cc);
        if ($vat !== 0.0) {
            $lines[] = $this->line($this->outputVatAccount($supplierId), $otherSide, abs($vat), $cc);
        }
        // Daň odváděná do jiného členského státu na vlastní účet (kontace oss.output.vat,
        // default analytika 345.100). Tohle je celý smysl rozdělení: na 343 zůstane přesně
        // to, co jde do přiznání k DPH, takže zůstatek účtu jde s přiznáním srovnat.
        if ($ossVat !== 0.0) {
            $ossAccount = $this->ruleCode($supplierId, 'oss.output.vat', 'credit', self::OSS_OUTPUT_VAT_ACCOUNT);
            $lines[] = $this->line($ossAccount, $otherSide, abs($ossVat), $cc);
        }
        $this->appendRounding($lines, $totalCzk, $net + $vat + $ossNet + $ossVat, $cc, $isCreditNote);

        // Vyúčtovací faktura z proformy (parent_invoice_id → proforma): DOPLŇ zúčtování
        // skutečně přijaté zálohy 324 MD / 311 D (self-balanced pár — nemění vyváženost
        // vlastní faktury). Běžná faktura bez proformy zůstává beze změny.
        $this->appendAdvanceSettlementSale($supplierId, $inv, $lines);

        return $lines;
    }

    /**
     * Řádky DDKP (daňový doklad k přijaté platbě proformy): 324 MD (čerpání přijaté
     * zálohy o DPH) / 343 D (DPH na výstupu), rule_key 'advance.received.vatdocument'.
     * Obě strany nesou POUZE částku daně — DDKP nenese výnos (602) ani nezakládá
     * pohledávku (311), jen carve-out DPH ze zálohy zaúčtované dřív na 324. Základ/daň
     * z {@see VatLedgerService} (jediný zdroj DPH; tax_document je v evidenci, na rozdíl
     * od proformy). Žádné haléřové dorovnání — pár je vyvážený z konstrukce (obě strany
     * = daň), hlavička DDKP (total_with_vat = brutto úplaty) se záměrně nedorovnává.
     *
     * OSS se tady VĚDOMĚ neřeší (na rozdíl od {@see buildFromInvoice}): DDKP se vystavuje
     * k přijaté záloze na tuzemské plnění, kdežto v režimu OSS se daň přiznává ke dni
     * přijetí úplaty přímo v OSS přiznání a samostatný daňový doklad k záloze se nevydává.
     * Kdyby přesto DDKP nesl jen OSS řádky, ledger nevrátí žádnou daň a doklad skončí
     * HLASITĚ na 'document_not_postable' — nikdy tiše bez daňové nohy.
     *
     * @param array<string,mixed> $inv
     * @param array{rule_key?:string, cost_center?:?string, debit_account_code?:string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    private function buildAdvanceVatDocumentLines(int $supplierId, int $invoiceId, array $inv, array $opts): array
    {
        [, $vat] = $this->ledgerTotals(
            $supplierId,
            'sale',
            $invoiceId,
            (string) ($inv['tax_date'] ?? $inv['issue_date']),
            (string) $inv['issue_date'],
        );
        if (self::cents($vat) === 0) {
            throw new PostingException(
                'document_not_postable',
                'Daňový doklad k přijaté platbě #' . $invoiceId . ' nemá DPH k zaúčtování '
                    . '(neplátce / reverse charge / nulová sazba?) — DDKP bez daně se neúčtuje.',
            );
        }
        $ruleKey = $opts['rule_key'] ?? 'advance.received.vatdocument';
        $draw    = $this->ruleCode($supplierId, $ruleKey, 'debit', '324');
        $vatAcc  = $this->vatAccount($supplierId, $ruleKey, 'credit', self::OUTPUT_VAT_ACCOUNT);
        $cc = $opts['cost_center'] ?? null;

        // Opravný DDKP (snížení dříve přiznané DPH ze zálohy, vat < 0) obrací strany:
        // 343 MD / 324 D. Zrcadlo buildPurchaseAdvanceVatDocumentLines na přijaté větvi.
        // Bez otočení by se abs($vat) zaúčtovalo na původní strany, tedy PŘESNĚ OBRÁCENĚ —
        // zápis by zůstal vyvážený a JournalIntegrityService by to nechytil, protože
        // checkAmountMismatch porovnává ABS(l.amount). Pro vat > 0 je výstup nezměněný.
        $drawSide = $vat < 0.0 ? 'credit' : 'debit';
        $vatSide  = $vat < 0.0 ? 'debit'  : 'credit';

        return [
            $this->line($draw, $drawSide, abs($vat), $cc),
            $this->line($vatAcc, $vatSide, abs($vat), $cc),
        ];
    }

    /**
     * Řádky penalizační faktury (úrok z prodlení, NV 351/2013): 311 MD (pohledávka)
     * / 644 D (smluvní pokuty a úroky z prodlení). Úrok z prodlení je MIMO předmět
     * DPH (§2 ZDPH — není plnění), takže se NEúčtuje žádná noha 343 a doklad není
     * v DPH evidenci (VatLedgerService::fetchSales penalty vylučuje). Základ = daň =
     * celá částka dokladu (total_with_vat, DPH = 0). Bez haléřového dorovnání —
     * jediný výnosový řádek přesně kryje pohledávku.
     *
     * @param array<string,mixed> $inv
     * @param array{rule_key?:string, cost_center?:?string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    private function buildPenaltyInvoiceLines(int $supplierId, int $invoiceId, array $inv, array $opts): array
    {
        $rate     = $this->fxRate($inv);
        $totalCzk = round((float) $inv['total_with_vat'] * $rate, 2);
        if (self::cents($totalCzk) === 0) {
            throw new PostingException('document_not_postable', 'Penalizační faktura #' . $invoiceId . ' má nulovou částku.');
        }

        $rule       = $this->rules->resolve($supplierId, $opts['rule_key'] ?? 'invoice.penalty.issued');
        $receivable = $rule['debit_account_code'] ?? '311';
        $revenue    = $rule['credit_account_code'] ?? '644';
        $cc = $opts['cost_center'] ?? null;

        // Dobropis penalizace (záporná částka) obrací strany.
        $isCredit       = $totalCzk < 0.0;
        $receivableSide = $isCredit ? 'credit' : 'debit';
        $revenueSide    = $isCredit ? 'debit'  : 'credit';

        return [
            $this->withForeign($this->line($receivable, $receivableSide, abs($totalCzk), $cc), $inv, $rate),
            $this->line($revenue, $revenueSide, abs($totalCzk), $cc),
        ];
    }

    /**
     * Zálohová platba (money leg proti účtu záloh, NE proti saldokontu faktury — proforma
     * / zálohová PF není daňový doklad a nemá zaúčtovaný předpis, který by se párem uzavíral):
     *   received (vydaná strana): moneyAccount MD / 324 D  ('advance.received.collection')
     *   paid     (přijatá strana): 314 MD / moneyAccount D ('advance.paid.payment')
     * Částka = částka platby (může být i částečná úhrada zálohy — běžné). Bankovní i pokladní
     * úhrady jsou v1 CZK-only, takže money leg nenese cizoměnovou stopu.
     *
     * @param 'received'|'paid' $direction
     * @param array{rule_key?:string, cost_center?:?string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    public function buildFromAdvancePayment(int $supplierId, string $direction, string $moneyAccount, float $amount, array $opts = []): array
    {
        $amt = round(abs($amount), 2);
        if (self::cents($amt) <= 0) {
            throw new PostingException('nonpositive_amount', 'Zálohová platba musí mít kladnou částku.');
        }
        $cc = $opts['cost_center'] ?? null;

        if ($direction === 'received') {
            $adv = $this->ruleCode($supplierId, $opts['rule_key'] ?? 'advance.received.collection', 'credit', '324');
            return [
                $this->line($moneyAccount, 'debit', $amt, $cc),
                $this->line($adv, 'credit', $amt, $cc),
            ];
        }
        if ($direction === 'paid') {
            $adv = $this->ruleCode($supplierId, $opts['rule_key'] ?? 'advance.paid.payment', 'debit', '314');
            return [
                $this->line($adv, 'debit', $amt, $cc),
                $this->line($moneyAccount, 'credit', $amt, $cc),
            ];
        }
        throw new PostingException('invalid_advance_direction', 'Neplatný směr zálohové platby: ' . $direction . '.');
    }

    /**
     * Sestaví řádky pro přijatou fakturu: 5xx|042 (náklad/majetek) + 343 (odpočet) / 321.
     * U tuzemského reverse-charge (§92) se DPH samovyměří: 343 MD (odpočet) i 343 D
     * (povinnost) ze stejné částky, takže se vyruší a závazek 321 je jen základ.
     *
     * vat_deduction (§75) řídí zdroj základu/daně a jejich rozúčtování:
     *  - 'full' (default): base/vat z {@see VatLedgerService} (jediný zdroj DPH), beze
     *    změny oproti původnímu chování.
     *  - 'none' (bez nároku): VatLedgerService takový doklad z DPH evidence úplně
     *    vyřazuje (vrátil by [0,0] → nepostovatelné), takže se base/vat berou přímo
     *    z purchase_invoice_items; DPH se neuplatňuje vůbec, celá částka vč. daně jde
     *    na nákladový/majetkový účet, ŽÁDNÝ řádek 343.
     *  - 'proportional': base/vat taky z položek (VatLedgerService by je krátil na
     *    vat_deduction_percent, což je správné pro DPH evidenci, ale ne pro účetní
     *    náklad); 343 MD nese jen poměrnou (uplatněnou) část daně, neuplatněná část se
     *    PŘIČTE k nákladu (nesmí tiše zmizet v haléřovém dorovnání).
     * Kombinace RC + 'none'/'proportional' (samovyměření z pořízení bez plného nároku)
     * není podporovaná — vyžaduje ruční zápis, aby nedošlo k tiché chybě ve výpočtu.
     *
     * Dobropis (document_kind='credit_note', poznatelný i podle záporného
     * total_with_vat) obrací strany všech řádků (321 MD / 5xx+343 D) a amounty jsou
     * v abs() — viz buildFromInvoice, stejná signed-until-the-end logika (audit B4).
     *
     * @param array{rule_key?:string, cost_center?:?string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    public function buildFromPurchaseInvoice(int $supplierId, int $purchaseInvoiceId, array $opts = []): array
    {
        $pi = $this->fetchDocHeader('purchase_invoices', $supplierId, $purchaseInvoiceId);
        if ($pi === null) {
            throw new PostingException('entry_not_found', 'Přijatá faktura #' . $purchaseInvoiceId . ' neexistuje.', 404);
        }
        if (in_array((string) ($pi['status'] ?? ''), ['draft', 'cancelled'], true)) {
            throw new PostingException(
                'document_not_postable',
                'Přijatou fakturu #' . $purchaseInvoiceId . ' ve stavu "' . ($pi['status'] ?? '') . '" nelze zaúčtovat.',
            );
        }
        if ((string) ($pi['document_kind'] ?? '') === 'advance') {
            throw new PostingException(
                'advance_payment_only',
                'Přijatá zálohová výzva se neúčtuje jako předpis. Zaúčtujte její úhradu z banky nebo pokladny na účet 314.',
                422,
            );
        }
        // DDKP k POSKYTNUTÉ záloze (daňový doklad k přijaté platbě, § 28 ZDPH) — jen odpočet
        // DPH ze zálohy 343/314, NIKDY náklad. Musí být PŘED běžnou větví, jinak by se základ
        // chybně naúčtoval na 518 (dvojí náklad s vyúčtovací fakturou). Zrcadlo vydané strany
        // (invoice_type='tax_document' → buildAdvanceVatDocumentLines).
        if ((string) ($pi['document_kind'] ?? '') === 'tax_document') {
            return $this->buildPurchaseAdvanceVatDocumentLines($supplierId, $purchaseInvoiceId, $pi, $opts);
        }
        $debitOverride = array_key_exists('debit_account_code', $opts)
            ? $this->validatePurchaseDebitOverride($supplierId, (string) $opts['debit_account_code'])
            : null;

        $rate         = $this->fxRate($pi);
        $totalCzk     = round((float) $pi['total_with_vat'] * $rate, 2);
        $isRc         = (bool) $pi['reverse_charge'];
        $isFixedAsset = (bool) ($pi['is_fixed_asset'] ?? false);
        $taxDeductible = (bool) ($pi['tax_deductible'] ?? true);
        $vatDeduction = (string) ($pi['vat_deduction'] ?? 'full');
        $isCreditNote = ((string) ($pi['document_kind'] ?? 'invoice') === 'credit_note') || $totalCzk < 0.0;

        $allocations = $this->purchaseVatAllocations($supplierId, $purchaseInvoiceId);
        if ($allocations !== []) {
            return $this->buildAllocatedPurchaseInvoiceLines($supplierId, $pi, $allocations, $opts);
        }

        if ($vatDeduction !== 'full' && $isRc) {
            throw new PostingException(
                'rc_partial_deduction_unsupported',
                'Přijatá faktura #' . $purchaseInvoiceId . ' kombinuje tuzemské samovyměření DPH (reverse charge) '
                    . 's omezeným nárokem na odpočet (' . $vatDeduction . ') — tuto kombinaci systém zatím '
                    . 'nezaúčtuje automaticky, zaúčtuj ji ručním zápisem.',
            );
        }

        if ($vatDeduction === 'full') {
            // received_at do okna jen u ruční PF (received_at_source='manual'), kde ho
            // VatLedgerService zohledňuje v období odpočtu (§ 73/1/a) — jinak by scanner
            // minul řádek zařazený do pozdějšího roku (přelom roku, audit C6').
            $receivedAt = ((string) ($pi['received_at_source'] ?? '') === 'manual')
                ? ($pi['received_at'] !== null ? (string) $pi['received_at'] : null)
                : null;
            [$net, $vat] = $this->ledgerTotals(
                $supplierId,
                'purchase',
                $purchaseInvoiceId,
                (string) ($pi['tax_date'] ?? $pi['issue_date']),
                (string) $pi['issue_date'],
                $receivedAt,
            );
        } else {
            [$net, $vat] = $this->purchaseItemTotals($purchaseInvoiceId, $rate);
        }
        if ($net + $vat === 0.0) {
            throw new PostingException('document_not_postable', 'Přijatá faktura #' . $purchaseInvoiceId . ' nemá řádky k zaúčtování.');
        }

        $defaultKey = $isFixedAsset ? 'invoice.dhm.received' : 'invoice.services.received';
        $rule = $this->rules->resolve($supplierId, $opts['rule_key'] ?? $defaultKey);
        $expense = $debitOverride ?? ($rule['debit_account_code'] ?? ($isFixedAsset ? '042' : '518'));
        if (!$taxDeductible) {
            $expense = $this->nonDeductibleExpenseAccount($supplierId, $expense);
        }
        $payable = $rule['credit_account_code'] ?? '321';
        $cc = $opts['cost_center'] ?? null;

        $pct = max(0.0, min(100.0, (float) ($pi['vat_deduction_percent'] ?? 100))) / 100.0;

        // §DM: rozpad nákladu po POLOŽKÁCH (faktura běžně míchá majetek i služby). Váhy se
        // počítají jen když je aspoň jedna položka klasifikovaná — jinak zůstane null a
        // doklad se zaúčtuje jednou nohou jako dosud (byte-identické chování).
        // Ruční/AI override účtu je adresný pokyn pro CELÝ doklad → rozpad se pak nedělá.
        $weights = $debitOverride !== null
            ? null
            : $this->purchaseExpenseWeights(
                $supplierId,
                $purchaseInvoiceId,
                $rate,
                $isRc,
                $vatDeduction,
                $pct,
                $expense,
                $taxDeductible,
                (array) ($opts['item_classification_overrides'] ?? []),
            );

        // Normálně 5xx/042 MD + 343 MD / 321 D; dobropis obrací obě strany.
        $expenseSide   = $isCreditNote ? 'credit' : 'debit';
        $payableSide   = $isCreditNote ? 'debit'  : 'credit';
        $totalOnCredit = !$isCreditNote;

        $lines = [];
        if ($isRc) {
            // Vendor fakturuje bez DPH → závazek = základ; daň se samovyměří na 343 (obě strany).
            $this->appendSplit($lines, $weights, $expense, $expenseSide, $net, $cc);
            if ($vat !== 0.0) {
                // Samovyměření: obě nohy jsou tatáž částka, ale patří na RŮZNÉ analytiky —
                // odpočet na vstup, přiznaná daň na výstup. Na plochém 343 se okamžitě
                // vynetovaly a v zúčtování období po nich nezůstala stopa.
                $lines[] = $this->line($this->inputVatAccount($supplierId), $expenseSide, abs($vat), $cc);  // nárok na odpočet
                $lines[] = $this->line($this->outputVatAccount($supplierId), $payableSide, abs($vat), $cc); // povinnost přiznat daň
            }
            $lines[] = $this->withForeign($this->line($payable, $payableSide, abs($totalCzk), $cc), $pi, $rate);
            $this->appendRounding($lines, $totalCzk, $net, $cc, $totalOnCredit);
        } elseif ($vatDeduction === 'none') {
            $expenseAmount = round($net + $vat, 2);
            $this->appendSplit($lines, $weights, $expense, $expenseSide, $expenseAmount, $cc);
            $lines[] = $this->withForeign($this->line($payable, $payableSide, abs($totalCzk), $cc), $pi, $rate);
            $this->appendRounding($lines, $totalCzk, $expenseAmount, $cc, $totalOnCredit);
        } elseif ($vatDeduction === 'proportional') {
            $deductibleVat    = round($vat * $pct, 2);
            $nonDeductibleVat = round($vat - $deductibleVat, 2);
            $expenseAmount    = round($net + $nonDeductibleVat, 2);
            $this->appendSplit($lines, $weights, $expense, $expenseSide, $expenseAmount, $cc);
            if ($deductibleVat !== 0.0) {
                $lines[] = $this->line($this->inputVatAccount($supplierId), $expenseSide, abs($deductibleVat), $cc);
            }
            $lines[] = $this->withForeign($this->line($payable, $payableSide, abs($totalCzk), $cc), $pi, $rate);
            $this->appendRounding($lines, $totalCzk, $expenseAmount + $deductibleVat, $cc, $totalOnCredit);
        } else {
            $this->appendSplit($lines, $weights, $expense, $expenseSide, $net, $cc);
            if ($vat !== 0.0) {
                $lines[] = $this->line($this->inputVatAccount($supplierId), $expenseSide, abs($vat), $cc);
            }
            $lines[] = $this->withForeign($this->line($payable, $payableSide, abs($totalCzk), $cc), $pi, $rate);
            $this->appendRounding($lines, $totalCzk, $net + $vat, $cc, $totalOnCredit);
        }

        // Finální PF navázaná na zaplacenou zálohu (advance_purchase_invoice_id): DOPLŇ
        // zúčtování skutečně zaplacené zálohy 321 MD / 314 D (self-balanced pár).
        $this->appendAdvanceSettlementPurchase($supplierId, $pi, $lines);

        return $lines;
    }

    /**
     * Řádky DDKP k POSKYTNUTÉ záloze (daňový doklad k přijaté platbě z pohledu odběratele,
     * § 28 ZDPH): 343 MD (odpočet DPH ze zálohy) / 314 D (snížení poskytnuté zálohy o DPH),
     * rule_key 'advance.paid.vatdocument'. Zrcadlo vydané strany {@see buildAdvanceVatDocumentLines}
     * (324 MD / 343 D). Nese POUZE částku daně — DDKP nezakládá náklad (5xx) ani závazek (321);
     * základ zůstává na 314 a náklad vzniká teprve u vyúčtovací faktury. Základ/daň z
     * {@see VatLedgerService} (tax_document JE v evidenci odpočtu, na rozdíl od zálohové výzvy
     * 'advance', kterou fetchPurchases vylučuje). Období odpočtu se řídí received_at jen u ruční
     * PF (received_at_source='manual', § 73/1/a) — shodně s běžnou přijatou fakturou. Žádné
     * haléřové dorovnání — pár je vyvážený z konstrukce (obě strany = daň).
     *
     * @param array<string,mixed> $pi hlavička DDKP (z fetchDocHeader)
     * @param array{rule_key?:string, cost_center?:?string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    private function buildPurchaseAdvanceVatDocumentLines(int $supplierId, int $purchaseInvoiceId, array $pi, array $opts): array
    {
        // Reverse charge (§92 / pořízení z JČS) nemá „daňový doklad k přijaté platbě": dodavatel
        // DPH nefakturuje a záloha se na 314 nezdaňuje. VatLedgerService by u RC s total_vat=0
        // samovyměřil nenulovou daň, takže by guard cents($vat)===0 níže NEzabral a doklad by se
        // mis-postnul jako jednonohé 343/314 bez protinohy samovyměření → odmítni, ať to účetní
        // zaúčtuje ručním zápisem.
        if ((bool) ($pi['reverse_charge'] ?? false)) {
            throw new PostingException(
                'ddkp_reverse_charge_unsupported',
                'Daňový doklad k poskytnuté záloze #' . $purchaseInvoiceId . ' v režimu přenesené daňové '
                    . 'povinnosti (reverse charge) se automaticky neúčtuje — zaúčtuj ho ručním zápisem.',
            );
        }
        $receivedAt = ((string) ($pi['received_at_source'] ?? '') === 'manual')
            ? ($pi['received_at'] !== null ? (string) $pi['received_at'] : null)
            : null;
        [, $vat] = $this->ledgerTotals(
            $supplierId,
            'purchase',
            $purchaseInvoiceId,
            (string) ($pi['tax_date'] ?? $pi['issue_date']),
            (string) $pi['issue_date'],
            $receivedAt,
        );
        if (self::cents($vat) === 0) {
            throw new PostingException(
                'document_not_postable',
                'Daňový doklad k poskytnuté záloze #' . $purchaseInvoiceId . ' nemá DPH k zaúčtování '
                    . '(neplátce / nulová sazba?) — DDKP bez daně se neúčtuje.',
            );
        }
        $ruleKey = $opts['rule_key'] ?? 'advance.paid.vatdocument';
        $vatAcc  = $this->vatAccount($supplierId, $ruleKey, 'debit', self::INPUT_VAT_ACCOUNT);  // odpočet DPH
        $draw    = $this->ruleCode($supplierId, $ruleKey, 'credit', '314'); // čerpání zálohy o DPH
        $cc = $opts['cost_center'] ?? null;

        // Opravný DDKP (snížení dříve odečtené DPH ze zálohy, vat < 0) obrací strany: 314 MD / 343 D.
        $vatSide  = $vat < 0.0 ? 'credit' : 'debit';
        $drawSide = $vat < 0.0 ? 'debit'  : 'credit';

        return [
            $this->line($vatAcc, $vatSide, abs($vat), $cc),
            $this->line($draw, $drawSide, abs($vat), $cc),
        ];
    }

    /**
     * §DM — váhy pro rozpad nákladové strany na účty podle `expense_kind` položek.
     *
     * Vrací `účet => váha` (CZK, se znaménkem), nebo NULL když se rozpad dělat NEMÁ:
     *   - žádná položka není klasifikovaná (NULL u všech) → dosavadní jedna noha,
     *   - doklad nemá položky,
     *   - |Σ vah| ≈ 0 → podíly by byly nesmysl (dělení nulou) → radši jedna noha.
     *
     * Neklasifikované položky na částečně klasifikovaném dokladu padnou na `$defaultAccount`
     * (dnešní chování: 518, resp. 042 u hlavičkového DHM), takže rozpad vždy pokryje celý základ.
     *
     * Váha = podíl položky na TÉ ČÁSTCE, která se rozpadá — proto se počítá podle větve DPH:
     *   full/RC        → základ
     *   none           → základ + celá daň (daň se kapitalizuje do nákladu)
     *   proportional   → základ + neuplatnitelná část daně
     * Jinak by se u položek s různou sazbou rozpad rozešel s poměrem, který větev účtuje.
     *
     * @return array<string,float>|null
     */
    private function purchaseExpenseWeights(
        int $supplierId,
        int $purchaseInvoiceId,
        float $rate,
        bool $isRc,
        string $vatDeduction,
        float $pct,
        string $defaultAccount,
        bool $taxDeductible,
        array $itemOverrides = [],
    ): ?array {
        $suggestions = $this->expenseClassification->suggestForInvoice($supplierId, $purchaseInvoiceId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, expense_kind, expense_account_code, total_without_vat, total_vat
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            return null;
        }

        $anyClassified = false;
        $weights = [];
        foreach ($items as $row) {
            $kindValue = $row['expense_kind'] !== null ? (string) $row['expense_kind'] : null;
            $override = trim((string) ($row['expense_account_code'] ?? ''));
            $override = $override === '' ? null : $override;

            $approved = $itemOverrides[(int) $row['id']] ?? null;
            if (is_array($approved)) {
                $approvedKind = ExpenseKind::tryFromNullable((string) ($approved['expense_kind'] ?? ''));
                if ($approvedKind !== null) {
                    $kindValue = $approvedKind->value;
                }
                $approvedAccount = trim((string) ($approved['expense_account_code'] ?? ''));
                $override = $approvedAccount === '' ? null : $approvedAccount;
            }

            // Náhled i samotné zaúčtování musí použít jistý návrh stejně jako auto-post,
            // ale bez zápisu do dokladu. Ručně zvolený účet má vždy přednost.
            $suggestion = $suggestions[(int) $row['id']] ?? null;
            if ($approved === null && $override === null && $suggestion !== null && !empty($suggestion['auto'])) {
                $kindValue = (string) $suggestion['expense_kind'];
                $suggestedAccount = trim((string) ($suggestion['expense_account_code'] ?? ''));
                $override = $suggestedAccount === '' ? null : $suggestedAccount;
            }
            $kind = ExpenseKind::tryFromNullable($kindValue);

            // Dvě různé osy: `expense_kind` = CO to je (evidence, sestavy, práh §26/2),
            // `expense_account_code` = KAM to jde. Pojistné je druhem SLUŽBA, ale vyhláška
            // 500/2002 ho řadí na 548 (F.5. Jiné provozní náklady), ne na 518 (A.3. Služby).
            // Účet na řádku proto přebíjí odvození z druhu.
            if ($override !== null) {
                $anyClassified = true;   // adresný účet je klasifikace sám o sobě
            } elseif ($kind !== null) {
                $anyClassified = true;
            }
            $account = $override !== null
                ? $this->validatePurchaseDebitOverride($supplierId, $override)
                : ($kind !== null ? $this->accountForExpenseKind($supplierId, $kind) : $defaultAccount);
            if (!$taxDeductible) {
                $account = $this->nonDeductibleExpenseAccount($supplierId, $account);
            }

            $net = round((float) $row['total_without_vat'] * $rate, 2);
            $vat = round((float) $row['total_vat'] * $rate, 2);
            $w = match (true) {
                $isRc => $net,
                $vatDeduction === 'none' => round($net + $vat, 2),
                $vatDeduction === 'proportional' => round($net + round($vat * (1.0 - $pct), 2), 2),
                default => $net,
            };
            $weights[$account] = round(($weights[$account] ?? 0.0) + $w, 2);
        }

        if (!$anyClassified) {
            return null;
        }
        if (count($weights) === 1) {
            // Všechno na jeden účet → rozpad by vyrobil tutéž jedinou nohu, jen oklikou.
            // Vracíme váhy i tak: účet z klasifikace se MUSÍ prosadit proti $defaultAccount.
            return $weights;
        }
        if (abs(array_sum($weights)) < 0.005) {
            return null;
        }
        return $weights;
    }

    /** Účet pro druh výdaje — z `posting_rules` (tenant si ho může přesměrovat), ne natvrdo. */
    private function accountForExpenseKind(int $supplierId, ExpenseKind $kind): string
    {
        $rule = $this->rules->resolve($supplierId, $kind->ruleKey());
        return (string) ($rule['debit_account_code'] ?? $kind->fallbackAccount());
    }

    /**
     * Váhy pro rozpad VÝNOSOVÉ strany podle vazby položek na karty majetku (migrace 1177).
     * Zrcadlo {@see purchaseExpenseWeights}, jen klasifikace není enum druhu, ale vazba na
     * kartu — z ní plyne účet:
     *
     *   asset_id       → 641 (tržba z prodeje dlouhodobého majetku, rule asset.sale.revenue)
     *   small_asset_id → 642 (tržba z prodeje materiálu — drobný majetek se pořízením
     *                    zaúčtoval na 501, nikdy nebyl na 02x, takže 641 mu nepatří)
     *   bez vazby      → $defaultAccount (hlavičkový revenue_rule_key, default 602)
     *
     * Vrací NULL (tedy dosavadní jedna noha) když doklad nemá položky, žádná položka není
     * navázaná, nebo |Σ vah| ≈ 0 (podíly by dělily nulou).
     *
     * SLEVOVÉ ŘÁDKY (item_kind='discount') vazbu na kartu nemají a spadnou proto na
     * $defaultAccount — procentní sleva z hlavičky se generuje per sazba DPH, ne per položka,
     * takže není ke které kartě ji přiřadit. Shodné chování má nákladová strana (viz komentář
     * o slevách Alzy v appendSplit); u prodeje majetku se sleva na hlavičce stejně nepoužívá,
     * cena se zadá rovnou na řádku.
     *
     * @return array<string,float>|null
     */
    private function revenueWeights(int $supplierId, int $invoiceId, float $rate, string $defaultAccount): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT small_asset_id, asset_id, total_without_vat
               FROM invoice_items
              WHERE invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($items === []) {
            return null;
        }

        $anyClassified = false;
        $weights = [];
        foreach ($items as $row) {
            // asset_id má přednost — kdyby řádek nesl obojí (aplikační invariant to zakazuje,
            // CHECK ho kvůli FK ON DELETE SET NULL vynutit nejde), rozhodne dražší majetek.
            if ($row['asset_id'] !== null) {
                $anyClassified = true;
                $account = $this->ruleCode($supplierId, 'asset.sale.revenue', 'credit', '641');
            } elseif ($row['small_asset_id'] !== null) {
                $anyClassified = true;
                $account = $this->ruleCode($supplierId, 'small_asset.sale.revenue', 'credit', '642');
            } else {
                $account = $defaultAccount;
            }

            $w = round((float) $row['total_without_vat'] * $rate, 2);
            $weights[$account] = round(($weights[$account] ?? 0.0) + $w, 2);
        }

        if (!$anyClassified) {
            return null;
        }
        if (count($weights) === 1) {
            // Celý doklad na jeden účet → rozpad vyrobí tutéž jedinou nohu. Váhy se vrací i tak:
            // účet z klasifikace se MUSÍ prosadit proti $defaultAccount (jinak by prodej majetku
            // na samostatné faktuře spadl na 602).
            return $weights;
        }
        if (abs(array_sum($weights)) < 0.005) {
            return null;
        }
        return $weights;
    }

    /**
     * Druhová strana dokladu (náklad u přijaté, výnos u vydané): buď jedna noha (dosud),
     * nebo rozpad dle vah z klasifikace položek.
     *
     * Σ rozpadu se MUSÍ rovnat $amount na haléř — $amount je autoritativní (u plného odpočtu
     * pochází z VatLedgerService, ne ze součtu položek). Proto se rozděluje $amount podle vah,
     * ne že by se sečetly položky: jinak by se 343 i 321 pohnuly a DPH by se rozešlo s podáním.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<string,float>|null $weights
     */
    private function appendSplit(array &$lines, ?array $weights, string $account, string $side, float $amount, ?string $cc): void
    {
        if ($weights === null) {
            $lines[] = $this->line($account, $side, abs($amount), $cc);
            return;
        }
        foreach ($this->distribute($amount, $weights) as $code => $part) {
            if (self::cents($part) === 0) {
                continue;
            }
            // Skupina, která vyjde OPAČNÝM znaménkem než celek, patří na OPAČNOU stranu.
            // Reálný případ (Alza PF 105): monitor 34 919,26 → 501, ale řádky „Sleva na
            // dopravné" a „Služba Sleva" dají na 518 dohromady −100,00. Slepé abs() z toho
            // udělalo 518 MD 100,00 místo D — zápis se rozvážil o dvojnásobek (200,00)
            // a zaúčtování spadlo na assertBalanced.
            //
            // Porovnává se se ZNAMÉNKEM $amount, ne s nulou: u dobropisu je záporné všechno
            // a $side už je překlopená volajícím, takže pravidlo „záporné = opačná strana"
            // by tam otočilo úplně každý řádek.
            $flipped = ($part < 0) !== ($amount < 0);
            $lineSide = $flipped ? ($side === 'debit' ? 'credit' : 'debit') : $side;

            // (string): číslo účtu '501' je numerický string, takže ho PHP jako klíč pole
            // tiše přetypuje na int 501 (kdežto '042' kvůli nule zůstane stringem). Bez
            // přetypování sem přiteče int a line() spadne na TypeError.
            $lines[] = $this->line((string) $code, $lineSide, abs($part), $cc);
        }
    }

    /**
     * Rozdělí $total podle vah tak, aby Σ výsledku byla PŘESNĚ $total (v haléřích).
     *
     * Zaokrouhlovací zbytek dostane účet s největší absolutní vahou — největší nosič nejmíň
     * zkreslí. Bez tohoto kroku by Σ nohou nesedělo na základ a appendRounding by rozdíl
     * poslal na 548/648, tedy by se haléře tvářily jako provozní náklad.
     *
     * @param array<string,float> $weights
     * @return array<string,float>
     */
    private function distribute(float $total, array $weights): array
    {
        $sumW = array_sum($weights);
        if (abs($sumW) < 0.005) {
            return $weights === [] ? [] : [array_key_first($weights) => $total];
        }

        $totalCents = self::cents($total);
        $out = [];
        $assigned = 0;
        foreach ($weights as $code => $w) {
            $c = (int) round($totalCents * ($w / $sumW));
            $out[$code] = $c;
            $assigned += $c;
        }

        $residual = $totalCents - $assigned;
        if ($residual !== 0) {
            $biggest = array_key_first($weights);
            $max = -1.0;
            foreach ($weights as $code => $w) {
                if (abs($w) > $max) {
                    $max = abs($w);
                    $biggest = $code;
                }
            }
            $out[$biggest] += $residual;
        }

        return array_map(static fn(int $c): float => $c / 100, $out);
    }

    private function validatePurchaseDebitOverride(int $supplierId, string $code): string
    {
        $code = trim($code);
        if ($code === '' || preg_match('/^(?:311|321|314|324|325|33|34|221|211)/', $code) === 1) {
            throw new PostingException('invalid_override', 'Navržený účet nelze použít pro náklad přijaté faktury.', 422);
        }
        $account = $this->accounts->findByCode($supplierId, $code);
        if ($account === null || !($account['is_active'] ?? false)) {
            throw new PostingException('invalid_override', 'Navržený účet není aktivní v účtové osnově firmy.', 422);
        }
        return $code;
    }

    /**
     * Zachová věcný druh nákladu a přepne pouze jeho daňovou analytiku. Konkrétní
     * účet již označený jako nedaňový (např. 513/528/543) má vždy přednost.
     * Chybějící nebo uživatelem jinak klasifikovaná .990 analytika znamená bezpečný
     * fallback na původní účet; hlavička dokladu stále zajistí přičtení v DPFO/DPPO.
     */
    private function nonDeductibleExpenseAccount(int $supplierId, string $accountCode): string
    {
        $cacheKey = $supplierId . '|' . $accountCode;
        if (isset($this->nonDeductibleAccountCache[$cacheKey])) {
            return $this->nonDeductibleAccountCache[$cacheKey];
        }

        $candidate = ChartOfAccountsTemplate::nonDeductibleAnalyticFor($accountCode);
        if ($candidate === null || $candidate === $accountCode) {
            return $this->nonDeductibleAccountCache[$cacheKey] = $accountCode;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT source.tax_deductibility AS source_tax_deductibility,
                    target.account_code AS target_account_code
               FROM chart_of_accounts source
          LEFT JOIN chart_of_accounts target
                 ON target.supplier_id = source.supplier_id
                AND target.account_code = ?
                AND target.account_type = "expense"
                AND target.tax_deductibility = "non_deductible"
                AND target.is_active = 1
              WHERE source.supplier_id = ? AND source.account_code = ? AND source.is_active = 1
              LIMIT 1'
        );
        $stmt->execute([$candidate, $supplierId, $accountCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (string) $row['source_tax_deductibility'] === 'non_deductible') {
            return $this->nonDeductibleAccountCache[$cacheKey] = $accountCode;
        }

        $target = $row['target_account_code'] !== null ? (string) $row['target_account_code'] : $accountCode;
        return $this->nonDeductibleAccountCache[$cacheKey] = $target;
    }

    /**
     * @param array<string,mixed> $pi
     * @param list<array<string,mixed>> $allocations
     * @param array{cost_center?:?string} $opts
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}>
     */
    private function buildAllocatedPurchaseInvoiceLines(int $supplierId, array $pi, array $allocations, array $opts): array
    {
        if ((bool) ($pi['reverse_charge'] ?? false)) {
            throw new PostingException(
                'rc_partial_deduction_unsupported',
                'Účetní alokace nelze automaticky zaúčtovat u reverse charge dokladu.',
            );
        }
        $rate = $this->fxRate($pi);
        $totalCzk = round((float) $pi['total_with_vat'] * $rate, 2);
        $isCreditNote = ((string) ($pi['document_kind'] ?? 'invoice') === 'credit_note') || $totalCzk < 0.0;
        $debitSide = $isCreditNote ? 'credit' : 'debit';
        $payableSide = $isCreditNote ? 'debit' : 'credit';
        $cc = $opts['cost_center'] ?? null;
        $lines = [];
        $allocatedCzk = 0.0;

        foreach ($allocations as $allocation) {
            $base = round((float) $allocation['base_amount'] * $rate, 2);
            $vat = round((float) $allocation['vat_amount'] * $rate, 2);
            $deduction = (string) $allocation['vat_deduction'];
            $deductibleVat = match ($deduction) {
                'none' => 0.0,
                'proportional' => round($vat * max(0.0, min(100.0, (float) $allocation['vat_deduction_percent'])) / 100, 2),
                default => $vat,
            };
            $accountAmount = round($base + ($vat - $deductibleVat), 2);
            if ($accountAmount !== 0.0) {
                $accountCode = (string) $allocation['account_code'];
                if (!(bool) ($pi['tax_deductible'] ?? true)
                    || (string) ($allocation['tax_treatment'] ?? '') === 'non_deductible') {
                    $accountCode = $this->nonDeductibleExpenseAccount($supplierId, $accountCode);
                }
                $lines[] = $this->line($accountCode, $debitSide, abs($accountAmount), $cc);
            }
            if ($deductibleVat !== 0.0) {
                $lines[] = $this->line($this->inputVatAccount($supplierId), $debitSide, abs($deductibleVat), $cc);
            }
            $allocatedCzk = round($allocatedCzk + $accountAmount + $deductibleVat, 2);
        }

        $rule = $this->rules->resolve($supplierId, 'invoice.services.received');
        $payable = $rule['credit_account_code'] ?? '321';
        $lines[] = $this->withForeign($this->line($payable, $payableSide, abs($totalCzk), $cc), $pi, $rate);
        $this->appendRounding($lines, $totalCzk, $allocatedCzk, $cc, !$isCreditNote);
        $this->appendAdvanceSettlementPurchase($supplierId, $pi, $lines);
        return $lines;
    }

    /** @return list<array<string,mixed>> */
    private function purchaseVatAllocations(int $supplierId, int $purchaseInvoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT description, usage_type, vat_rate, base_amount, vat_amount,
                    total_amount, vat_deduction, vat_deduction_percent,
                    tax_treatment, account_code, vat_classification_code
               FROM purchase_invoice_vat_allocations
              WHERE supplier_id = ? AND purchase_invoice_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$supplierId, $purchaseInvoiceId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ── zálohový cyklus (zúčtování skutečně přijaté/zaplacené zálohy) ─────────

    /**
     * Doplní k vyúčtovací faktuře řádek zúčtování přijaté zálohy 324 MD / 311 D za
     * SKUTEČNĚ přijatou zálohu (součet živých inkasních zápisů 324 navázaných na proformu,
     * NE nominál proformy — ta může být zaplacená jen zčásti). Snižuje pohledávku 311 o
     * zálohu a nuluje závazek ze zálohy 324. Běžná faktura (bez proformy) i nezaplacená
     * proforma → beze změny.
     *
     * Mimo scope v1 (hlasitá chyba místo tichého rozvahového rozhozu): proforma s DDKP
     * (VAT už odštěpená z 324 → prostý brutto settlement by 324 přetáhl) a proforma s víc
     * než jednou vyúčtovací fakturou (nejednoznačná částka) → 'advance_settlement_ambiguous'.
     *
     * @param array<string,mixed> $inv hlavička vyúčtovací faktury (z fetchDocHeader)
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}> $lines
     */
    private function appendAdvanceSettlementSale(int $supplierId, array $inv, array &$lines): void
    {
        if ((string) ($inv['invoice_type'] ?? 'invoice') !== 'invoice') {
            return; // jen finální vyúčtovací faktura (ne dobropis/proforma/DDKP)
        }
        $parentId = (int) ($inv['parent_invoice_id'] ?? 0);
        if ($parentId <= 0) {
            return;
        }
        $parent = $this->fetchDocHeader('invoices', $supplierId, $parentId);
        if ($parent === null || (string) ($parent['invoice_type'] ?? '') !== 'proforma') {
            return; // parent není proforma → nejde o zálohový cyklus
        }

        if ($this->hasActiveTaxDocument($supplierId, $parentId)) {
            throw new PostingException(
                'advance_settlement_ambiguous',
                'Proforma #' . $parentId . ' má daňový doklad k přijaté platbě (DDKP) — kombinace '
                    . 'DDKP + vyúčtování se ve v1 neúčtuje automaticky, zaúčtuj zúčtování zálohy ručně.',
            );
        }
        if ($this->activeFinalInvoiceCount($supplierId, $parentId) > 1) {
            throw new PostingException(
                'advance_settlement_ambiguous',
                'Proforma #' . $parentId . ' má víc než jednu vyúčtovací fakturu — výši zúčtování '
                    . 'zálohy nelze jednoznačně určit, zaúčtuj ji ručně.',
            );
        }

        $received = min(
            $this->postedAdvanceReceived($supplierId, $parentId),
            abs((float) ($inv['total_with_vat'] ?? 0) * $this->fxRate($inv)),
        );
        if (self::cents($received) <= 0) {
            return; // proforma nezaplacena / žádné inkaso na 324 → běžná faktura beze změny
        }

        $draw = $this->ruleCode($supplierId, 'advance.received.settlement', 'debit', '324');
        $recv = $this->ruleCode($supplierId, 'advance.received.settlement', 'credit', '311');
        $lines[] = $this->line($draw, 'debit', $received, null);
        $lines[] = $this->line($recv, 'credit', $received, null);
    }

    /**
     * Doplní k finální PF řádek zúčtování poskytnuté zálohy 321 MD / 314 D za SKUTEČNĚ
     * zaplacenou zálohu (součet živých úhradových zápisů 314 navázaných na zálohu). Snižuje
     * závazek 321 o zálohu a nuluje pohledávku ze zálohy 314. Vazba advance_purchase_invoice_id
     * je 1:1 (UNIQUE), takže nehrozí víc finálních → není třeba ambiguity guard.
     *
     * @param array<string,mixed> $pi hlavička finální PF (z fetchDocHeader)
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}> $lines
     */
    private function appendAdvanceSettlementPurchase(int $supplierId, array $pi, array &$lines): void
    {
        if ((string) ($pi['document_kind'] ?? 'invoice') !== 'invoice') {
            return; // jen běžná finální PF (ne dobropis/záloha)
        }
        $advId = (int) ($pi['advance_purchase_invoice_id'] ?? 0);
        if ($advId <= 0) {
            return;
        }
        $adv = $this->fetchDocHeader('purchase_invoices', $supplierId, $advId);
        if ($adv === null) {
            return;
        }
        $advKind = (string) ($adv['document_kind'] ?? '');
        // Vyúčtovat lze zálohovou fakturu (advance), ale i SAMOSTATNÝ daňový doklad k platbě
        // (§28/8 ZDPH — typicky nákup kartou, kdy žádná zálohová faktura nevzniká).
        // PurchaseInvoiceRepository::linkAdvance() dovoluje navázat oba (viz tamní
        // $advanceIsStandaloneDdkp) — PostingService je musí rozeznat stejně, jinak
        // zúčtování finální PF navázané na samostatný DDKP tiše proběhne BEZ zúčtování
        // 321/314 a zůstatek na 314 zůstane navždy otevřený beze stopy chyby.
        $advIsStandaloneDdkp = $advKind === 'tax_document' && ($adv['parent_purchase_invoice_id'] ?? null) === null;
        if ($advKind !== 'advance' && !$advIsStandaloneDdkp) {
            return;
        }

        // Je-li záloha (nebo je-li sama zálohou) DDKP, byla už část 314 vyčerpána o DPH
        // (343/314). Automatické zúčtování 321/314 na PLNOU zaplacenou zálohu by pak
        // přečerpalo 314 do minusu o už uplatněnou daň → ve v1 (symetricky k vydané straně)
        // neúčtujeme automaticky a necháme účetní zúčtovat ručně. Hláška rovnou spočítá,
        // kolik daně má na 343 zbýt doúčtovat — "zaúčtuj ručně" bez čísla nutí účetní
        // dopočítávat totéž z hlavy z dvou různých dokladů.
        $ddkp = $advIsStandaloneDdkp ? $adv : $this->activePurchaseTaxDocument($supplierId, $advId);
        if ($ddkp !== null) {
            $finalVat = abs((float) ($pi['total_vat'] ?? 0));
            $ddkpVat  = abs((float) ($ddkp['total_vat'] ?? 0));
            $remaining = round($finalVat - $ddkpVat, 2);
            throw new PostingException(
                'advance_settlement_ambiguous',
                ($advIsStandaloneDdkp
                    ? 'Doklad #' . $advId . ' je samostatný daňový doklad k platbě (DDKP)'
                    : 'Poskytnutá záloha #' . $advId . ' má daňový doklad k platbě (DDKP)')
                    . ' — kombinace DDKP + vyúčtování se ve v1 neúčtuje automaticky, zúčtování '
                    . 'zálohy zaúčtuj ručně. Na 343 zbývá doúčtovat ' . number_format($remaining, 2, ',', ' ')
                    . ' Kč (DPH finální faktury ' . number_format($finalVat, 2, ',', ' ') . ' Kč − už uplatněná '
                    . 'DPH z DDKP ' . number_format($ddkpVat, 2, ',', ' ') . ' Kč).',
            );
        }

        $paid = min(
            $this->postedAdvancePaid($supplierId, $advId),
            abs((float) ($pi['total_with_vat'] ?? 0) * $this->fxRate($pi)),
        );
        if (self::cents($paid) <= 0) {
            return;
        }

        $draw    = $this->ruleCode($supplierId, 'advance.paid.settlement', 'debit', '321');
        $advAcc  = $this->ruleCode($supplierId, 'advance.paid.settlement', 'credit', '314');
        $lines[] = $this->line($draw, 'debit', $paid, null);
        $lines[] = $this->line($advAcc, 'credit', $paid, null);
    }

    /**
     * Skutečně přijatá záloha k proformě = součet ALOKOVANÝCH částek plateb navázaných na
     * proformu (invoice_payments.amount z banky + cash_documents.total_amount z pokladny),
     * ale JEN z těch, jejichž úhradový zápis v deníku je ŽIVÝ (source bank/cash, reversed_by
     * IS NULL). Sčítá se alokace ke KONKRÉTNÍ proformě, ne řádky účtu 324 celého zápisu —
     * sloučená úhrada na víc proforem nese v jednom zápisu víc 324 řádků, a entry-wide součet
     * by cizí zálohu přesčítal (a při vyúčtování druhé proformy načetl tentýž zápis znovu).
     */
    private function postedAdvanceReceived(int $supplierId, int $proformaId): float
    {
        $bank = $this->scalarFloat(
            'SELECT COALESCE(SUM(ip.amount), 0)
               FROM invoice_payments ip
               JOIN journal_entries je
                 ON je.supplier_id = ip.supplier_id AND je.source_type = :st
                AND je.source_id = ip.bank_transaction_id AND je.reversed_by IS NULL
              WHERE ip.supplier_id = :sid AND ip.invoice_id = :pid
                AND ip.bank_transaction_id IS NOT NULL',
            [':st' => 'bank', ':sid' => $supplierId, ':pid' => $proformaId],
        );
        $cash = $this->scalarFloat(
            'SELECT COALESCE(SUM(cd.total_amount), 0)
               FROM cash_documents cd
               JOIN journal_entries je
                 ON je.supplier_id = cd.supplier_id AND je.source_type = :st
                AND je.source_id = cd.id AND je.reversed_by IS NULL
              WHERE cd.supplier_id = :sid AND cd.invoice_id = :pid',
            [':st' => 'cash', ':sid' => $supplierId, ':pid' => $proformaId],
        );
        return round($bank + $cash, 2);
    }

    /**
     * Skutečně zaplacená poskytnutá záloha k zálohové PF = součet ALOKOVANÝCH úhrad navázaných
     * na zálohu (payment_matches.amount z banky + cash_documents.total_amount z pokladny) z
     * ŽIVÝCH zápisů. Symetrie k {@see postedAdvanceReceived}: jedna odchozí platba smí přes
     * payment_matches uhradit víc dokladů → sčítáme jen alokaci k této záloze, ne 314 celého zápisu.
     */
    private function postedAdvancePaid(int $supplierId, int $advanceId): float
    {
        $bank = $this->scalarFloat(
            'SELECT COALESCE(SUM(pm.amount), 0)
               FROM payment_matches pm
               JOIN journal_entries je
                 ON je.supplier_id = pm.supplier_id AND je.source_type = :st
                AND je.source_id = pm.bank_transaction_id AND je.reversed_by IS NULL
              WHERE pm.supplier_id = :sid AND pm.purchase_invoice_id = :pid',
            [':st' => 'bank', ':sid' => $supplierId, ':pid' => $advanceId],
        );
        $cash = $this->scalarFloat(
            'SELECT COALESCE(SUM(cd.total_amount), 0)
               FROM cash_documents cd
               JOIN journal_entries je
                 ON je.supplier_id = cd.supplier_id AND je.source_type = :st
                AND je.source_id = cd.id AND je.reversed_by IS NULL
              WHERE cd.supplier_id = :sid AND cd.purchase_invoice_id = :pid',
            [':st' => 'cash', ':sid' => $supplierId, ':pid' => $advanceId],
        );
        return round($bank + $cash, 2);
    }

    /**
     * B8 (audit 2026-07): měkký zámek účtování k datu (accounting_supplier_settings.locked_until).
     * Per-request cache — v rámci jednoho postDocument/reverse je stav konzistentní.
     */
    private function lockedUntil(int $supplierId): ?string
    {
        if (!array_key_exists($supplierId, $this->lockedUntilCache)) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?'
            );
            $stmt->execute([$supplierId]);
            $v = $stmt->fetchColumn();
            $this->lockedUntilCache[$supplierId] = ($v === false || $v === null) ? null : (string) $v;
        }
        return $this->lockedUntilCache[$supplierId];
    }

    private function lockedUntilForUpdate(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT locked_until
               FROM accounting_supplier_settings
              WHERE supplier_id = ?
              FOR UPDATE',
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();
        $lockedUntil = $value === false || $value === null
            ? null
            : (string) $value;
        $this->lockedUntilCache[$supplierId] = $lockedUntil;

        return $lockedUntil;
    }

    /** @param array<string,int|string> $params */
    private function scalarFloat(string $sql, array $params): float
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Má proforma navázaný ŽIVÝ DDKP (daňový doklad k přijaté platbě)?
     *
     * „Živý" = vystavený, tedy NE draft a NE stornovaný. Draft DDKP nemá zápis v deníku,
     * z 324 nic neodčerpal a nevyrobí ani odpočtové řádky § 37a — blokovat kvůli němu
     * zaúčtování legitimní vyúčtovací faktury (advance_settlement_ambiguous) je chybné.
     * Stejnou definici používá FinalFromProformaCreator při sestavování § 37a řádků.
     *
     * Vazba se hledá OBĚMA cestami — přes parent_invoice_id i přes
     * invoice_payments.tax_document_invoice_id. Druhá cesta pokrývá historicky rozpojené
     * doklady (rozpojení dnes brání guard v InvoiceRepository::unlinkAdvance, ale
     * self-heal v PaymentTaxDocumentCreator s takovými řádky výslovně počítá). Bez ní
     * guard u rozpojeného DDKP nezabere a zúčtování zálohy se zaúčtuje 324 MD / 311 D
     * na plnou zálohu, přestože DDKP z 324 daň už odčerpal. Stejnou dvojí vazbu používá
     * FinalFromProformaCreator, CancelInvoiceAction, IssueInvoiceAction i unlinkAdvance.
     */
    private function hasActiveTaxDocument(int $supplierId, int $proformaId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM invoices td
              WHERE td.supplier_id = ? AND td.invoice_type = 'tax_document'
                AND td.status NOT IN ('draft', 'cancelled')
                AND (td.parent_invoice_id = ?
                     OR td.id IN (SELECT p.tax_document_invoice_id FROM invoice_payments p
                                   WHERE p.invoice_id = ? AND p.tax_document_invoice_id IS NOT NULL))
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $proformaId, $proformaId]);
        return $stmt->fetchColumn() !== false;
    }

    private function activeFinalInvoiceCount(int $supplierId, int $proformaId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM invoices
              WHERE supplier_id = ? AND parent_invoice_id = ? AND invoice_type = 'invoice'
                AND status <> 'cancelled'"
        );
        $stmt->execute([$supplierId, $proformaId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Živý DDKP (daňový doklad k platbě) navázaný na danou poskytnutou zálohu jako DÍTĚ
     * (parent_purchase_invoice_id, přetíženo dle document_kind, jako hasActiveTaxDocument
     * na vydané straně přes parent_invoice_id). Vrací ID + total_vat (ne jen bool) —
     * appendAdvanceSettlementPurchase z toho dopočítá, kolik DPH finální faktury ještě
     * zbývá doúčtovat na 343 nad rámec toho, co DDKP uplatnil už při platbě.
     *
     * „Živý" = NE draft a NE stornovaný — zrcadlo hasActiveTaxDocument. Draft nemá zápis
     * v deníku a z 314 nic neodčerpal, takže kvůli němu nemá co blokovat zúčtování zálohy.
     * Platební vazba (invoice_payments) na přijaté větvi neexistuje, proto jen parent.
     *
     * @return array{id:int,total_vat:float}|null
     */
    private function activePurchaseTaxDocument(int $supplierId, int $advanceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, total_vat FROM purchase_invoices
              WHERE supplier_id = ? AND parent_purchase_invoice_id = ? AND document_kind = 'tax_document'
                AND status NOT IN ('draft', 'cancelled') LIMIT 1"
        );
        $stmt->execute([$supplierId, $advanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['id' => (int) $row['id'], 'total_vat' => (float) $row['total_vat']];
    }

    /** Kód účtu z kontace (per-tenant override) se strany + fallback. */
    private function ruleCode(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $code = $rule[$side . '_account_code'] ?? null;
        return is_string($code) && $code !== '' ? $code : $fallback;
    }

    /**
     * Účet daně na VSTUPU (nárok na odpočet) — kontace `invoice.vat.input`, fallback
     * {@see INPUT_VAT_ACCOUNT}. Tenant, který chce zůstat na plochém 343, si rule_key
     * přepíše na '343' a chování se vrátí do stavu před migrací 1323.
     */
    private function inputVatAccount(int $supplierId): string
    {
        return $this->vatAccount($supplierId, 'invoice.vat.input', 'debit', self::INPUT_VAT_ACCOUNT);
    }

    /** Účet daně na VÝSTUPU — kontace `invoice.vat.output`, fallback {@see OUTPUT_VAT_ACCOUNT}. */
    private function outputVatAccount(int $supplierId): string
    {
        return $this->vatAccount($supplierId, 'invoice.vat.output', 'credit', self::OUTPUT_VAT_ACCOUNT);
    }

    /**
     * Daňový účet s DEGRADACÍ NA SYNTETIKU. Pořadí: kontace → analytika ze šablony →
     * holé 343.
     *
     * Ta poslední větev je záměrná pojistka pro nasazení, kde kód už běží, ale migrace
     * 1323 ještě neproběhla (rolling deploy, kontejner nahozený před entrypointem), nebo
     * kde si tenant analytiku smazal či deaktivoval. Bez ní by KAŽDÝ doklad s DPH spadl
     * na `unknown_account` — účtování by se u takové firmy zastavilo úplně. Degradace na
     * syntetiku je horší účetně (vstup a výstup se na 343 vynetují, měsíční zúčtování
     * takovou firmu přeskočí), ale je to stav, ve kterém aplikace fungovala doteď.
     *
     * Naopak když v osnově není ANI 343 (firma bez podvojného účetnictví, nezaseedovaná
     * osnova), vrací se kód z kontace beze změny — ať chybu nahlásí resolveLines()
     * hlasitě a se správným kódem, místo aby ji tahle metoda zamaskovala.
     *
     * Bankovní ani pokladní analytiky (221.x / 211.x) obdobnou pojistku NEPOTŘEBUJÍ:
     * {@see \MyInvoice\Service\Accounting\Bank\BankAnalyticAssigner::ensureChartAccount()}
     * a CashRegisterService si chybějící analytiku samy dohrají do osnovy dřív, než na ni
     * pošlou řádek.
     */
    private function vatAccount(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $code = $this->ruleCode($supplierId, $ruleKey, $side, $fallback);
        if ($code === self::VAT_SYNTHETIC || $this->isPostableAccount($supplierId, $code)) {
            return $code;
        }

        return $this->isPostableAccount($supplierId, self::VAT_SYNTHETIC) ? self::VAT_SYNTHETIC : $code;
    }

    /**
     * Kód účtu po přesměrování syntetiky na její jedinou analytiku — pro NÁHLED kontace.
     *
     * Přesměr dělá {@see resolveLines()} až v okamžiku zápisu, takže návrh ve frontě
     * ukazoval syrové kódy z pravidla („261/221") a v deníku pak vznikl zápis jiný
     * („261.100/221.400"). Náhled, který ukazuje něco jiného, než co se zapíše, je
     * v účetnictví nepřijatelný, a druhá kopie pravidla přesměru by se s originálem
     * rozešla — proto se sem pouští TÁŽ mapa, jakou používá zaúčtování.
     */
    public function redirectedAccountCode(int $supplierId, string $code): string
    {
        return $this->singleAnalyticMap($supplierId)[$code] ?? $code;
    }

    /**
     * Mapa „trojmístná syntetika → její JEDINÁ aktivní daňová analytika" pro firmu.
     *
     * PROČ. Jakmile syntetika dostane potomka, nesmí se na ni dál účtovat — součet
     * analytik by neseděl na syntetiku a v hlavní knize by účet měl vlastní zůstatek
     * vedle svých dětí. Kontace se přesměrovat dají (a jsou), ale spousta účtů se
     * v enginu volí NATVRDO podle druhu operace: 511 u servisu vozidla, 563/663
     * u kurzových rozdílů, 648/548 u haléřového dorovnání, 261 u převodu mezi
     * vlastními účty. Honit každý literál zvlášť je nekonečná práce; když má
     * syntetika právě jednu analytiku, je odpověď jednoznačná — patří tam.
     *
     * KDY SE PŘESMĚR NEDĚLÁ (a proč se to nedá zjednodušit):
     *  - firma přepínač vypnula (`accounting_supplier_settings.single_analytic_redirect`),
     *  - nedaňová analytika se nepočítá — tu smí vybrat jen daňový příznak dokladu,
     *  - syntetika má 0 nebo 2+ aktivních daňových analytik → volba není jednoznačná, řeší ji
     *    kontace nebo kontext (proto se nic nemění firmám bez analytik — ty mají 0),
     *  - jde o {@see CONTEXT_DRIVEN_SYNTHETICS} (221/211/343/345),
     *  - analytika NENÍ v tečkovaném tvaru `NNN.NNN`. Tahle podmínka je zásadní:
     *    šablona osnovy veze pod 311 účet `311D` (dlouhodobé pohledávky) a pod 461
     *    účet `461K` (krátkodobá část úvěrů). Obojí je ÚZCE ÚČELOVÁ podmnožina, ne
     *    náhrada syntetiky — bez téhle podmínky by se úplně všechny pohledávky
     *    každého tenanta přesypaly na 311D. Tečkovaný tvar naproti tomu znamená
     *    „běžná analytika" v konvenci, kterou drží zbytek osnovy (501.100, 221.100).
     *
     * @return array<string,string> kód syntetiky → kód analytiky (prázdné = neměň nic)
     */
    private function singleAnalyticMap(int $supplierId): array
    {
        if (isset($this->singleAnalyticCache[$supplierId])) {
            return $this->singleAnalyticCache[$supplierId];
        }
        if (!$this->singleAnalyticRedirectEnabled($supplierId)) {
            return $this->singleAnalyticCache[$supplierId] = [];
        }
        $excluded = implode(', ', array_fill(0, count(self::CONTEXT_DRIVEN_SYNTHETICS), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.account_code AS synthetic, MIN(c.account_code) AS analytic
               FROM chart_of_accounts p
               JOIN chart_of_accounts c
                 ON c.supplier_id = p.supplier_id AND c.parent_id = p.id AND c.is_active = 1
              WHERE p.supplier_id = ?
                AND p.is_active = 1
                AND p.account_code REGEXP '^[0-9]{3}$'
                AND p.account_code NOT IN ({$excluded})
                AND c.tax_deductibility = 'deductible'
              GROUP BY p.id, p.account_code
             HAVING COUNT(*) = 1
                AND MIN(c.account_code) REGEXP '^[0-9]{3}[.][0-9]{1,6}$'"
        );
        $stmt->execute([$supplierId, ...self::CONTEXT_DRIVEN_SYNTHETICS]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['synthetic']] = (string) $row['analytic'];
        }

        return $this->singleAnalyticCache[$supplierId] = $map;
    }

    /**
     * Přepínač přesměru (migrace 1326). Default zapnuto — firmy bez analytik se
     * nezmění tak jako tak (nemají co přesměrovat) a firma, která si jedinou
     * analytiku vědomě založila, ji chce používat. Kill switch je tu pro případ,
     * kdy je jediná analytika naopak úzce účelová a syntetika má zůstat výchozí.
     */
    private function singleAnalyticRedirectEnabled(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT single_analytic_redirect FROM accounting_supplier_settings WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        // Chybějící řádek nastavení = firma si nic neměnila → platí default (zapnuto).
        return $value === false || (bool) $value;
    }

    /** Je kód v osnově firmy a aktivní? Per-request cache — volá se na každý daňový řádek. */
    private function isPostableAccount(int $supplierId, string $code): bool
    {
        $key = $supplierId . '|' . $code;
        if (!array_key_exists($key, $this->postableAccountCache)) {
            $account = $this->accounts->findByCode($supplierId, $code);
            $this->postableAccountCache[$key] = $account !== null && !empty($account['is_active']);
        }

        return $this->postableAccountCache[$key];
    }

    // ── vyváženost (pure — jednotkově testovatelné bez DB) ────────────────────

    /**
     * Součty stran v haléřích (int) — peníze se NIKDY neporovnávají přes float.
     *
     * @param list<array{side:'debit'|'credit', amount:float|int|string}> $lines
     * @return array{debit:int, credit:int}
     */
    public static function balanceCents(array $lines): array
    {
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $cents = self::cents($line['amount']);
            if ($line['side'] === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        return ['debit' => $debit, 'credit' => $credit];
    }

    /**
     * Ověří Σ MD == Σ D v haléřích. Nevyváženost → UnbalancedEntryException.
     *
     * @param list<array{side:'debit'|'credit', amount:float|int|string}> $lines
     */
    public static function assertBalanced(array $lines): void
    {
        $b = self::balanceCents($lines);
        if ($b['debit'] !== $b['credit']) {
            throw new UnbalancedEntryException($b['debit'], $b['credit']);
        }
    }

    /** Peníze → haléře (int). Vstup je float zaokr. na 2 des. místa (viz money repr.). */
    private static function cents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100.0);
    }

    /**
     * Přepíše zakázku na řádcích JIŽ zaúčtovaného dokladu (issue #29).
     *
     * Proč to smí obejít §35: `project_id` je čistě analytická dimenze — nemění účet,
     * stranu, částku ani období, takže deník zůstává co do účetního obsahu totožný a
     * není co opravovat protizápisem. Zařazení dokladu k akci navíc typicky přijde AŽ
     * po zaúčtování (účetní zaúčtuje došlou fakturu, projekťák ji pak přiřadí k akci);
     * kdyby to šlo jen přeúčtováním, výsledovka po zakázkách by v praxi zůstala prázdná.
     *
     * Vědomé omezení: storna (`source_id` je u nich ZÁMĚRNĚ NULL, viz reverse()) se
     * nepřerazítkují. Stornovaný doklad se k jiné zakázce nepřeřazuje — ruší se.
     *
     * @param 'invoice'|'purchase_invoice'|'cash' $sourceType
     * @return int počet přerazítkovaných řádků deníku
     */
    public function restampProjectDimension(int $supplierId, string $sourceType, int $sourceId, ?int $projectId): int
    {
        if (!isset(self::PROJECT_DIMENSION_SOURCES[$sourceType])) {
            return 0;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE journal_entry_lines jel
               JOIN journal_entries je ON je.id = jel.entry_id
                SET jel.project_id = ?
              WHERE je.supplier_id = ? AND je.source_type = ? AND je.source_id = ?
                AND jel.supplier_id = ?'
        );
        $stmt->execute([
            $projectId !== null && $projectId > 0 ? $projectId : null,
            $supplierId,
            $sourceType,
            $sourceId,
            $supplierId,
        ]);
        return $stmt->rowCount();
    }

    // ── interní ───────────────────────────────────────────────────────────────

    /**
     * Zdrojové doklady, které zakázku (`project_id`) na hlavičce nesou — sloupec je
     * u všech tří stejný, liší se jen tabulka.
     *
     * Bankovní pohyb tu ZÁMĚRNĚ není: úhrada je rozvahový pohyb (321/311 × 221), do
     * výsledovky nevstupuje, a dimenze na ní by jen zdvojila náklad už zaúčtovaný
     * fakturou. Uzávěrkové a odpisové zápisy dimenzi nemají z podstaty (jsou souhrnné).
     */
    private const PROJECT_DIMENSION_SOURCES = [
        'invoice'          => 'invoices',
        'purchase_invoice' => 'purchase_invoices',
        'cash'             => 'cash_documents',
    ];

    /**
     * Dorazítkuje řádky zakázkou ze zdrojového dokladu (issue #29).
     *
     * Proč centrálně tady a ne v každém builderu: zápis vzniká šesti cestami
     * (auto-post, ruční zaúčtování z JournalAction, aktivační backfill, pokladna,
     * doúčtování historie…) a dimenze, kterou razítkuje jen část z nich, dělá
     * výsledovku po zakázkách nepoužitelnou — chybějící náklad se v ní neprojeví
     * jako nula, ale jako falešně vyšší marže.
     *
     * Řádky, které si zakázku přinesly z builderu, zůstávají beze změny.
     *
     * @param list<array<string,mixed>> $resolved
     * @return list<array<string,mixed>>
     */
    private function stampProjectDimension(int $supplierId, string $sourceType, ?int $sourceId, array $resolved): array
    {
        $table = self::PROJECT_DIMENSION_SOURCES[$sourceType] ?? null;
        if ($table === null || $sourceId === null) {
            return $resolved;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT project_id FROM {$table} WHERE id = ? AND supplier_id = ?"
        );
        $stmt->execute([$sourceId, $supplierId]);
        $projectId = $stmt->fetchColumn();
        if ($projectId === false || $projectId === null) {
            return $resolved;
        }
        $projectId = (int) $projectId;

        foreach ($resolved as $i => $line) {
            if (($line['project_id'] ?? null) === null) {
                $resolved[$i]['project_id'] = $projectId;
            }
        }
        return $resolved;
    }

    /**
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float|int|string, cost_center?:?string}> $lines
     * @param array<string, array{id:int, is_active:bool, account_type:string}> $codeMap
     * @param 'invoice'|'purchase_invoice'|'bank'|'cash'|'asset'|'manual'|'closing'|'opening'|'payroll'|'vat_clearing' $sourceType
     * @return list<array{account_id:int, side:'debit'|'credit', amount:float, cost_center:?string}>
     */
    private function resolveLines(int $supplierId, array $lines, array $codeMap, string $sourceType): array
    {
        $out = [];
        $sawOffbalance = false;
        $sawOnBalance = false;
        $singleAnalytics = $this->singleAnalyticMap($supplierId);
        foreach ($lines as $i => $line) {
            $code = (string) $line['account_code'];
            // Syntetika s JEDINOU analytikou → účtuj na tu analytiku (viz singleAnalyticMap()).
            $code = $singleAnalytics[$code] ?? $code;
            if (!isset($codeMap[$code])) {
                throw new PostingException(
                    'unknown_account',
                    'Účet ' . $code . ' není v účtové osnově — zkontroluj kód nebo naseeduj osnovu.',
                );
            }
            $account = $codeMap[$code];
            if (!$account['is_active']) {
                throw new PostingException(
                    'account_inactive',
                    'Účet ' . $code . ' je v osnově deaktivovaný — nelze na něj nově účtovat.',
                );
            }
            // R7/B7: 70x (701/702/710) patří výhradně uzávěrce (ClosingService); podrozvaha
            // (75x/79x) se smí účtovat jen jednostranně proti jiné podrozvaze (§ nikdy proti
            // rozvaze/výsledovce, jinak by se zdvojila do bilance/VZZ).
            if ($account['account_type'] === 'closing' && !in_array($sourceType, ['closing', 'opening'], true)) {
                throw new PostingException(
                    'closing_account_forbidden',
                    'Účet ' . $code . ' je závěrkový — smí se použít jen v uzávěrkovém zápisu, ne v "' . $sourceType . '".',
                );
            }
            if ($account['account_type'] === 'offbalance') {
                $sawOffbalance = true;
            } else {
                $sawOnBalance = true;
            }
            $side = $line['side'];
            if ($side !== 'debit' && $side !== 'credit') {
                throw new PostingException('invalid_side', 'Neplatná strana řádku #' . $i . ': ' . (string) $side . '.');
            }
            $amount = round((float) $line['amount'], 2);
            if (self::cents($amount) <= 0) {
                throw new PostingException('nonpositive_amount', 'Řádek #' . $i . ' (' . $code . ') má nekladnou částku ' . $amount . '.');
            }
            $resolvedLine = [
                'account_id'  => $account['id'],
                'side'        => $side,
                'amount'      => $amount,
                'cost_center' => $line['cost_center'] ?? null,
                // Zakázka (issue #29): builder ji smí určit per řádek (rozúčtování jednoho
                // dokladu na víc akcí); co nechá NULL, dorazítkuje stampProjectDimension()
                // ze zdrojového dokladu.
                'project_id'  => isset($line['project_id']) && (int) $line['project_id'] > 0
                    ? (int) $line['project_id']
                    : null,
            ];
            // cizoměnová stopa (jen saldokontní řádky cizoměnových dokladů)
            if (isset($line['currency_code'])) {
                $resolvedLine['currency_code']  = $line['currency_code'];
                $resolvedLine['fx_rate']        = $line['fx_rate'] ?? null;
                $resolvedLine['amount_foreign'] = $line['amount_foreign'] ?? null;
            }
            $out[] = $resolvedLine;
        }
        if ($sawOffbalance && $sawOnBalance) {
            throw new PostingException(
                'offbalance_account_mixed',
                'Podrozvahový účet (75x/79x) nelze v jednom zápisu kombinovat s rozvahovým/výsledkovým — podrozvaha se účtuje jednostranně proti jiné podrozvaze (typicky 799).',
            );
        }
        return $out;
    }

    /**
     * Součet základu a daně (CZK) z VatLedgerService pro jeden doklad (vat_deduction
     * 'full' — jediný případ, kdy je ledger korektním zdrojem, viz buildFromPurchaseInvoice).
     *
     * Okno roku NENÍ jen GREATEST(docDate, issueDate): VatLedgerService zařazuje tuzemská
     * plnění dle GREATEST, ale zahraniční reverse-charge (fetchPurchases, issue #117)
     * VĚDOMĚ dle samotného tax_date (DUZP), bez ohledu na issue_date — viz komentář tam.
     * Kdyby tady scanner sledoval jen GREATEST, doklad se zahraničním RC a issue_date až
     * v následujícím roce (běžné — zahraniční dodavatel fakturuje s odstupem) by ledger
     * zařadil do dřívějšího roku (dle tax_date), ale scanner by hledal v tom pozdějším
     * (dle issue_date) → řádek by nenašel → 'document_not_postable' (audit B2 doaudit,
     * nález 2). Řešení: naskenovat CELÉ rozpětí MIN..MAX(docDate, issueDate) — filtr níž
     * je stejně přesně na invoice_id, širší okno tedy nemůže míchat cizí doklady, jen
     * nemůže minout ten správný bez ohledu na to, které přesné pravidlo ledger použil.
     *
     * Třetí rozměr okna je $receivedAt (audit C6'): u ruční přijaté faktury
     * (received_at_source='manual') zařazuje VatLedgerService odpočet dle
     * GREATEST(received_at, DUZP, vystavení) — § 73/1/a, doklad fyzicky přijat později.
     * Když received_at spadá do POZDĚJŠÍHO roku než DUZP i vystavení (běžné na přelomu
     * roku: prosincové DUZP, lednové přijetí), ledger řádek zařadí do onoho pozdějšího
     * roku, ale scanner by ho bez tohoto rozšíření minul → 'document_not_postable'.
     * Proto received_at (jen manual) vstupuje do min/max stejně jako docDate/issueDate.
     *
     * @param 'sale'|'purchase' $source
     * @return array{0:float,1:float} [net, vat]
     */
    private function ledgerTotals(int $supplierId, string $source, int $invoiceId, string $docDate, string $issueDate, ?string $receivedAt = null): array
    {
        $dates = [$docDate, $issueDate];
        if ($receivedAt !== null && $receivedAt !== '') {
            $dates[] = $receivedAt;
        }
        $startYear = (int) substr(min($dates), 0, 4);
        $endYear   = (int) substr(max($dates), 0, 4);
        $start = sprintf('%04d-01-01', $startYear);
        $end   = sprintf('%04d-12-31', $endYear);
        $net = 0.0;
        $vat = 0.0;
        foreach ($this->vatLedger->rows($supplierId, $start, $end, true) as $row) {
            if ($row['source'] === $source && (int) $row['invoice_id'] === $invoiceId) {
                $net += (float) $row['base_czk'];
                $vat += (float) $row['vat_czk'];
            }
        }
        return [round($net, 2), round($vat, 2)];
    }

    /**
     * Součet základu a daně (CZK) přímo z položek přijaté faktury — pro vat_deduction
     * 'none'/'proportional', kde VatLedgerService doklad z DPH evidence buď úplně
     * vyřazuje ('none'), nebo krátí na vat_deduction_percent ('proportional'); pro
     * účetní zápis ale potřebujeme PLNOU částku dokladu (audit B1), ne DPH pohled.
     *
     * @return array{0:float,1:float} [net, vat]
     */
    private function purchaseItemTotals(int $purchaseInvoiceId, float $rate): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_without_vat, total_vat FROM purchase_invoice_items WHERE purchase_invoice_id = ?'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $net = 0.0;
        $vat = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $net += round((float) $row['total_without_vat'] * $rate, 2);
            $vat += round((float) $row['total_vat'] * $rate, 2);
        }
        return [round($net, 2), round($vat, 2)];
    }

    /**
     * Základ a daň OSS řádků vydané faktury (CZK) — pro daň v režimu jednoho správního
     * místa (§110 a násl. ZDPH). Zdrojem jsou POLOŽKY, ne hlavička: OSS je příznak na
     * řádku, takže jeden doklad běžně míchá tuzemské plnění s plněním do jiného
     * členského státu a hlavičkový `total_vat` obojí slévá dohromady.
     *
     * Proč zvlášť a ne přes {@see VatLedgerService}: ledger je jediný zdroj ČESKÉ DPH
     * evidence a OSS řádky z ní vědomě vyřazuje (nepatří do přiznání ani do KH). Pro
     * účetní zápis ale doklad potřebujeme CELÝ, jinak neodpovídá hlavičce — stejná
     * situace jako {@see purchaseItemTotals} u §75.
     *
     * Zaokrouhlení je per řádek (ne až ze součtu), aby součet seděl s váhami
     * z {@see revenueWeights}, které se počítají stejně — jinak by rozpad výnosu
     * vyšel o haléře jinak než základ, který se rozděluje.
     *
     * Instance bez OSS schématu (chybí migrace 0137) vrací [0,0] → zápis vypadá
     * přesně jako dřív.
     *
     * @return array{0:float,1:float} [ossNet, ossVat]
     */
    private function ossItemTotals(int $invoiceId, float $rate): array
    {
        if (!$this->db->hasColumn('invoice_items', 'oss_applicable')) {
            return [0.0, 0.0];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT total_without_vat, total_vat
               FROM invoice_items
              WHERE invoice_id = ? AND COALESCE(oss_applicable, 0) = 1'
        );
        $stmt->execute([$invoiceId]);
        $net = 0.0;
        $vat = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $net += round((float) $row['total_without_vat'] * $rate, 2);
            $vat += round((float) $row['total_vat'] * $rate, 2);
        }
        return [round($net, 2), round($vat, 2)];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchDocHeader(string $table, int $supplierId, int $id): ?array
    {
        $isPurchase = $table === 'purchase_invoices';
        // whitelisted table names (interní volání) — ne z uživatelského vstupu
        $stmt = $this->db->pdo()->prepare(
            "SELECT d.id, d.status, d.issue_date, d.tax_date, d.currency_id, d.exchange_rate, d.reverse_charge,
                    d.total_without_vat, d.total_vat, d.total_with_vat, d.rounding,
                    cur.code AS currency_code,
                    " . ($isPurchase ? 'd.is_fixed_asset' : '0 AS is_fixed_asset') . ",
                    " . ($isPurchase ? "'invoice' AS invoice_type" : 'd.invoice_type') . ",
                    " . ($isPurchase ? 'd.document_kind' : "'invoice' AS document_kind") . ",
                    " . ($isPurchase
                        ? 'd.advance_purchase_invoice_id, NULL AS parent_invoice_id, d.parent_purchase_invoice_id'
                        : 'd.parent_invoice_id, NULL AS advance_purchase_invoice_id, NULL AS parent_purchase_invoice_id') . ",
                    " . ($isPurchase ? 'd.vat_deduction, d.vat_deduction_percent' : "'full' AS vat_deduction, 100 AS vat_deduction_percent") . ",
                    " . ($isPurchase ? 'd.tax_deductible' : '1 AS tax_deductible') . ",
                    " . ($isPurchase ? 'd.received_at, d.received_at_source' : 'NULL AS received_at, NULL AS received_at_source') . ",
                    " . ($isPurchase ? 'NULL AS revenue_rule_key' : 'd.revenue_rule_key') . "
               FROM {$table} d
               LEFT JOIN currencies cur ON cur.id = d.currency_id
              WHERE d.id = ? AND d.supplier_id = ?"
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Deterministický default popisu zápisu, když volající žádný nedodá (auto-post
     * přes {@see DocumentAutoPoster}, bulk zaúčtování, ruční post bez textu) — deník
     * jinak zůstane s prázdným POPISEM („—"). Text se skládá čistě z dat dokladu
     * (typ + protistrana + číslo), takže idempotentní re-post vygeneruje TÝŽ popis.
     *
     * Pokrývá vydané a přijaté faktury; bank/cash cesty si popis vždy předávají samy
     * (BankPostingService::entryDescription, cash_documents.description). Neznámý typ,
     * ruční zápis bez zdroje nebo chybějící doklad → null (chování beze změny).
     */
    private function defaultDescription(int $supplierId, string $sourceType, ?int $sourceId): ?string
    {
        if ($sourceId === null) {
            return null;
        }
        if ($sourceType === 'purchase_invoice') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT pi.vendor_invoice_number, pi.document_kind, c.company_name
                   FROM purchase_invoices pi
                   LEFT JOIN clients c ON c.id = pi.vendor_id
                  WHERE pi.id = ? AND pi.supplier_id = ?'
            );
            $stmt->execute([$sourceId, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            $label = match ((string) ($row['document_kind'] ?? 'invoice')) {
                'credit_note'  => 'Přijatý dobropis',
                'receipt'      => 'Přijatá účtenka',
                'advance'      => 'Přijatá zálohová faktura',
                'tax_document' => 'Daňový doklad k platbě',
                default        => 'Přijatá faktura',
            };
            return self::composeDescription($label, [$row['company_name'], $row['vendor_invoice_number']]);
        }
        if ($sourceType === 'invoice') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT i.varsymbol, i.invoice_type, c.company_name
                   FROM invoices i
                   LEFT JOIN clients c ON c.id = i.client_id
                  WHERE i.id = ? AND i.supplier_id = ?'
            );
            $stmt->execute([$sourceId, $supplierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return null;
            }
            $label = match ((string) ($row['invoice_type'] ?? 'invoice')) {
                'credit_note'  => 'Vydaný dobropis',
                'tax_document' => 'Daňový doklad k přijaté platbě',
                'penalty'      => 'Penalizační faktura',
                default        => 'Vydaná faktura',
            };
            return self::composeDescription($label, [$row['company_name'], $row['varsymbol']]);
        }
        return null;
    }

    /**
     * Poskládá „label protistrana číslo" z neprázdných částí; ořez na délku sloupce
     * journal_entries.description (VARCHAR 255).
     *
     * @param list<mixed> $parts
     */
    private static function composeDescription(string $label, array $parts): string
    {
        $out = $label;
        foreach ($parts as $part) {
            $part = trim((string) ($part ?? ''));
            if ($part !== '') {
                $out .= ' ' . $part;
            }
        }
        return mb_substr($out, 0, 255);
    }

    /**
     * Kurz dokladu k účetní měně. Zrcadlí VatLedgerService: doklad v CZK má VŽDY
     * kurz 1.0, i kdyby v exchange_rate byla omylem nenulová hodnota — jinak by
     * totalCzk (z hlavičky) neseděl na base/vat z ledgeru (rate 1.0) a rozdíl by
     * spadl do 648/548 (audit C-4). Doklad v CIZÍ měně bez kurzu (NULL/≤0) NELZE
     * ocenit v Kč → PostingException('missing_exchange_rate', 422); tichý fallback
     * na 1.0 by zaúčtoval nominál cizí měny jako CZK (audit H1). Stejnou bránu má
     * i VatLedgerService::normalize().
     *
     * @param array<string,mixed> $doc
     *
     * @throws PostingException doklad v cizí měně nemá vyplněný kurz
     */
    private function fxRate(array $doc): float
    {
        if (($doc['currency_code'] ?? 'CZK') === 'CZK') {
            return 1.0;
        }
        $rate = $doc['exchange_rate'] ?? null;
        if ($rate === null || (float) $rate <= 0) {
            throw new PostingException(
                'missing_exchange_rate',
                'Doklad v cizí měně (' . (string) ($doc['currency_code'] ?? '?') . ') nemá vyplněný směnný kurz — '
                    . 'doplň kurz k datu účetního případu; bez něj nelze doklad ocenit v Kč (§4/12 ZoÚ).',
            );
        }
        return (float) $rate;
    }

    /**
     * @return array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}
     */
    private function line(string $code, string $side, float $amount, ?string $cc): array
    {
        $l = ['account_code' => $code, 'side' => $side, 'amount' => round($amount, 2)];
        if ($cc !== null) {
            $l['cost_center'] = $cc;
        }
        /** @var array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string} $l */
        return $l;
    }

    /**
     * Doplní řádku (typicky saldokonto 311/321) cizoměnovou stopu, pokud doklad
     * NENÍ v účetní měně (CZK). U CZK dokladu ponechá řádek bez měny (NULL =
     * účetní měna, není co přeceňovat). amount_foreign = celková částka dokladu
     * v jeho měně (§4/12 souběžné vedení; podklad pro přecenění §24/6).
     *
     * @param array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string} $line
     * @param array<string,mixed> $doc
     * @return array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string, currency_code?:string, fx_rate?:float, amount_foreign?:float}
     */
    private function withForeign(array $line, array $doc, float $rate): array
    {
        $code = (string) ($doc['currency_code'] ?? 'CZK');
        if ($code === 'CZK') {
            return $line;
        }
        $line['currency_code']  = $code;
        $line['fx_rate']        = $rate;
        // abs(): u dobropisu (B4) je resolved 'amount' taky abs (strany se obrací
        // přes side, ne přes znaménko) — cizoměnová stopa musí nést stejnou magnitudu.
        $line['amount_foreign'] = abs(round((float) $doc['total_with_vat'], 2));
        return $line;
    }

    /**
     * Doúčtuje haléřové zaokrouhlení, aby seděla podvojnost. Rozdíl = totalCzk (částka
     * z hlavičky dokladu) − ledgerTotal (základ+daň, jak je skutečně rozúčtovaná na
     * ostatních řádcích).
     *
     * Sémantika je odvozená z VYDANÉ faktury (celková částka na straně MD: 311 total /
     * D 6xx+343): kladný rozdíl = chybí na straně D → 648 (ostatní provozní výnos),
     * záporný → 548 (ostatní provozní náklad). U PŘIJATÉ faktury (a u dobropisu vydané
     * faktury) je geometrie zrcadlová (celková částka na straně D), takže stejný rozdíl
     * míří na OPAČNOU stranu — proto $totalOnCredit otočí znaménko, ať dorovnání padne
     * správně (548 MD = zaokrouhlovací náklad / 648 D = zaokrouhlovací výnos), a ne aby
     * se nevyváženost zdvojnásobila do UnbalancedEntryException (audit H2).
     *
     * Strop {@see ROUNDING_TOLERANCE_CENTS}: nad něj rozdíl NENÍ haléřové zaokrouhlení,
     * ale prokazatelný nesoulad hlavičky dokladu s DPH evidencí/položkami — tichý zápis
     * na 648/548 by takovou chybu jen maskoval jako výnos/náklad (audit B3). Místo toho
     * PostingException, ať si účetní doklad opraví.
     *
     * @param list<array{account_code:string, side:'debit'|'credit', amount:float, cost_center?:?string}> $lines
     */
    private function appendRounding(array &$lines, float $totalCzk, float $ledgerTotal, ?string $cc, bool $totalOnCredit = false): void
    {
        // abs(): u dobropisu (a zrcadlově u přijaté faktury) jsou totalCzk/ledgerTotal
        // SIGNED (záporné), ale skutečně zaúčtované řádky vždy abs() — porovnání musí
        // jet nad stejnou (nezápornou) magnitudou jako řádky, jinak dvojitá negace
        // (záporné vstupy + $totalOnCredit flip) vrátí dorovnání na špatnou stranu a
        // MÍSTO vynulování nevyváženosti ji zdvojnásobí (audit B4 doaudit, nález 1).
        $diff = abs($totalCzk) - abs($ledgerTotal);
        if ($totalOnCredit) {
            $diff = -$diff;
        }
        $cents = self::cents($diff);
        if ($cents === 0) {
            return;
        }
        if (abs($cents) > self::ROUNDING_TOLERANCE_CENTS) {
            throw new PostingException(
                'totals_mismatch',
                sprintf(
                    'Doklad nesedí: hlavička %.2f Kč vs. základ+DPH %.2f Kč (rozdíl %.2f Kč přesahuje toleranci %.2f Kč) — zkontroluj položky/DPH klasifikaci dokladu.',
                    $totalCzk,
                    $ledgerTotal,
                    $totalCzk - $ledgerTotal,
                    self::ROUNDING_TOLERANCE_CENTS / 100,
                ),
            );
        }
        if ($cents > 0) {
            $lines[] = $this->line('648', 'credit', $cents / 100, $cc);
        } else {
            $lines[] = $this->line('548', 'debit', -$cents / 100, $cc);
        }
    }
}
