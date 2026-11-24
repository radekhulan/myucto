<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Vat;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Repository\VatClearingRunRepository;
use MyInvoice\Service\Accounting\Pair\PairGroups;
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Accounting\TakenOverRecord;
use PDO;

/**
 * Interní doklad „zúčtování DPH" na konci zdaňovacího období.
 *
 * PROČ. Od migrace 1323 nesedí daň na jednom plochém 343, ale na analytikách
 * (viz {@see PostingService::INPUT_VAT_ACCOUNT} a spol.):
 *   343.100  daň na vstupu (odpočet)   343.200  daň na výstupu   343.900  zúčtování s FÚ
 * Účetní pak na konci KAŽDÉHO zdaňovacího období převede obrat období na zúčtovací účet:
 *
 *     MD 343.200 / D 343.900     … daň na VÝSTUPU za období
 *     MD 343.900 / D 343.100     … daň na VSTUPU za období
 *
 * Po dokladu jsou účty daně za období nulové a na 343.900 leží přesně
 * částka, která se odvádí (nebo se má vrátit) — tu pak vynuluje bankovní úhrada
 * (kontace `vat.payment`, {@see \MyInvoice\Service\Accounting\Bank\Detect\TaxRemittanceDetector}).
 * Zůstatek zúčtovacího účtu je tím pádem přímo srovnatelný se saldem u správce daně,
 * což na plochém 343 (kde se vstup s výstupem hned vyruší) z principu nešlo.
 *
 * IDEMPOTENCE. Zápis nese `source_type = 'vat_clearing'` a DETERMINISTICKÉ `source_id`
 * odvozené z období ({@see sourceIdFor()}), takže platí kontrakt
 * `uq_je_supplier_source` popsaný v {@see PostingService}: opakovaný běh existující
 * zápis PŘEPÍŠE (přepočítá) místo aby založil druhý. Dopočtená částka přitom vlastní
 * zápis IGNORUJE (source_type se z obratu vylučuje), jinak by každé přepočítání
 * přičetlo samo sebe.
 *
 * CO SE DO OBRATU NEPOČÍTÁ:
 *   - uzavření/otevření knih ('closing'/'opening') — převod zůstatku na 702/701 není
 *     daňová transakce (stejná výjimka jako ve {@see \MyInvoice\Service\Report\VatCrossCheckService}),
 *   - vlastní zúčtovací doklady ('vat_clearing') — viz idempotence výše,
 *   - koncepty (posted_at IS NULL) — nezaúčtovaný doklad ještě není v knihách.
 *
 * BEZPEČNOST ZÁPISU. Doklad se skládá z vyváženého páru pro každý účet
 * s nenulovou daní. Pokud je daň na všech účtech nulová, doklad se NEZAKLÁDÁ vůbec (status `skipped_zero`) — a pokud z dřívějška
 * existuje, SMAŽE se ({@see STATUS_DELETED_ZERO}).
 *
 * ── CO DOKLAD SPOUŠTÍ (migrace 1332) ──────────────────────────────────────────
 * Kalendář NENÍ okamžik, kdy je daň za období známá. Doklad, který do období přibude
 * později (opožděná přijatá faktura, oprava, doklad vytěžený AI o pár dní později),
 * změní obrat — a zúčtovací zápis pak odpovídá jinému číslu, než jaké se podalo.
 * V knihách by ležel jiný závazek vůči FÚ než ten přiznaný.
 *
 * Autoritativní okamžik je proto PŘIZNÁNÍ:
 *   1. `return_filed`  — přiznání k DPH (dphdp3) označeno jako PODANÉ
 *      ({@see \MyInvoice\Service\Accounting\Vat\VatClearingTrigger::onSubmissionFiled()}).
 *      Dodatečné (D) i opravné (O) přiznání doklad přepočítají znovu.
 *   2. `return_draft`  — vygenerování konceptu přiznání OBNOVÍ už existující doklad
 *      (nikdy nezakládá nový — viz VatClearingTrigger).
 *   3. `manual`        — účetní z agendy DPH (s náhledem před zápisem).
 *   4. `cron`          — záchranná síť pro období, za která se přiznání nepodalo.
 *
 * ── PŘEPIS, NIKDY STORNO ──────────────────────────────────────────────────────
 * Aktualizace jde VÝHRADNĚ přepisem na místě: stejný `source_type`+`source_id` →
 * {@see PostingService::postDocument()} spadne do `rewriteExisting()`, které smaže
 * řádky a přepíše hlavičku se ZACHOVANÝM `id`. Zúčtování je dopočtená veličina, ne
 * hospodářská operace — stornovaná stopa po každém přepočtu by z deníku udělala
 * smetiště a na saldu 343.900 by se nic nezměnilo. Proto se tady NIKDY nevolá
 * {@see PostingService::reverse()} ani `DocumentJournalSync::reconcileForceEdit()`.
 *
 * ── ZAVŘENÉ A ZAMČENÉ OBDOBÍ ──────────────────────────────────────────────────
 * „Smaž a udělej znovu" platí jen pro OTEVŘENÉ období. Do období ve stavu jiném než
 * `open` a do data ≤ `accounting_supplier_settings.locked_until` se nezapisuje ani
 * nemaže — {@see assertWritable()} vyhodí {@see PostingException} a volající z toho
 * udělá NÁLEZ (kontrola uzávěrky `vat_clearing_stale`, hláška v agendě DPH), ne zápis.
 *
 * ── ZASTARALOST SE POČÍTÁ, NEUKLÁDÁ ───────────────────────────────────────────
 * Příznak „stale" nikde neleží; {@see status()} ho odvodí živě porovnáním čerstvě
 * spočítaného obratu období proti tomu, co na dokladu SKUTEČNĚ je. Uložený příznak by
 * byl druhý zdroj pravdy, který se rozejde právě tehdy, kdy se rozejít nesmí (změna
 * dokladu cestou, která by ho neuměla zneplatnit).
 */
final class VatClearingService
{
    /** source_type zúčtovacího dokladu (ENUM `journal_entries.source_type`, migrace 1324). */
    public const SOURCE_TYPE = 'vat_clearing';

    public const STATUS_POSTED            = 'posted';
    public const STATUS_DRY_RUN           = 'dry_run';
    public const STATUS_ZERO              = 'skipped_zero';
    public const STATUS_DELETED_ZERO      = 'deleted_zero';
    public const STATUS_NOT_VAT_PAYER     = 'skipped_not_vat_payer';
    public const STATUS_NOT_DOUBLE_ENTRY  = 'skipped_not_double_entry';
    public const STATUS_MISSING_ACCOUNTS  = 'skipped_missing_accounts';
    public const STATUS_FLAT_VAT_ACCOUNT  = 'skipped_flat_vat_account';
    /**
     * Období má zápisy převzaté z jiného účetního programu ({@see TakenOverRecord}).
     * DPH za ně vyrovnal (nebo nevyrovnal) zdroj; automatický přepočet by do převedeného
     * deníku přidal zápis, který zdroj nemá. Vyrovnat ho jde jen vědomě ručně
     * ({@see TRIGGER_MANUAL}, náhled nese `taken_over`) nebo podáním přiznání.
     */
    public const STATUS_TAKEN_OVER        = 'skipped_taken_over';

    /** Spouštěče bez rozhodnutí uživatele: v období s převzatými zápisy nic nezapíší ani nesmažou. */
    private const AUTOMATIC_TRIGGERS = [self::TRIGGER_CRON, self::TRIGGER_RETURN_DRAFT];

    /** Čím byl přepočet vyvolán — hodnoty ENUM `vat_clearing_runs.trigger_source`. */
    public const TRIGGER_RETURN_FILED = 'return_filed';
    public const TRIGGER_RETURN_DRAFT = 'return_draft';
    public const TRIGGER_MANUAL       = 'manual';
    public const TRIGGER_CRON         = 'cron';

    /**
     * Materialita hlášení nesouladu (v Kč, na každou nohu zvlášť).
     *
     * PROČ TO NENÍ NULA. Období vyčištěné JINAK než tímhle dokladem — typicky ručním
     * zápisem účetní, který se zaokrouhluje na celé koruny — nechává na 343.100/343.200
     * haléřový zbytek. Ten zbytek není zastaralé zúčtování, je to zaokrouhlení: hlásit
     * ho by znamenalo mít v uzávěrce trvale svítící nález, se kterým nejde nic udělat
     * (a takový nález se přestane číst, čímž zabije i ty pravé). Do obratu se totiž
     * ručně zaúčtované převody počítají — z něj se vylučují jen vlastní `vat_clearing`,
     * `closing` a `opening` — takže ručně vyrovnané období vyjde samo skoro na nulu.
     *
     * Práh se týká VÝHRADNĚ hlášení ({@see status()}, {@see staleForRange()}).
     * Zaúčtování samo pracuje s přesnými částkami — {@see postForPeriod()} nikdy
     * nezaokrouhluje daň na koruny.
     */
    public const MATERIALITY = 1.00;

    /** Zúčtování odpovídá aktuálnímu obratu období (v rámci {@see MATERIALITY}). */
    public const FRESHNESS_OK = 'ok';
    /** Období má nenulovou daň, ale zúčtovací doklad neexistuje. */
    public const FRESHNESS_MISSING = 'missing';
    /** Doklad existuje, ale obrat období se od jeho zaúčtování změnil. */
    public const FRESHNESS_STALE = 'stale';
    /** Zúčtování v tomhle období nedává smysl (neplátce, plochý 343, chybějící osnova). */
    public const FRESHNESS_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PostingRuleRepository $rules,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly JournalEntryRepository $journal,
        private readonly VatClearingRunRepository $runs,
        private readonly AccountingPeriodRepository $periods,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    // ── čistá aritmetika období (bez DB — jednotkově testovatelné) ────────────

    /**
     * Hranice zdaňovacího období, do kterého spadá zadaný měsíc.
     *
     * @param 'monthly'|'quarterly' $periodType
     * @return array{0:string, 1:string, 2:int, 3:int} [start, end, firstMonth, lastMonth]
     */
    public static function periodBounds(int $year, int $month, string $periodType): array
    {
        $month = max(1, min(12, $month));
        if ($periodType === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $first = ($quarter - 1) * 3 + 1;
            $last = $quarter * 3;
        } else {
            $first = $month;
            $last = $month;
        }
        $start = sprintf('%04d-%02d-01', $year, $first);
        $end = date('Y-m-t', (int) mktime(0, 0, 0, $last, 1, $year));

        return [$start, $end, $first, $last];
    }

    /**
     * Deterministické `source_id` — YYYYMMD, kde MM je PRVNÍ měsíc období a poslední
     * číslice odlišuje čtvrtletní plátce (1) od měsíčního (0). Bez toho příznaku by
     * lednový doklad měsíčního plátce a doklad za Q1 sdílely týž klíč a při změně
     * zdaňovacího období by si navzájem přepsaly zápis.
     *
     * @param 'monthly'|'quarterly' $periodType
     */
    public static function sourceIdFor(int $year, int $month, string $periodType): int
    {
        [, , $first] = self::periodBounds($year, $month, $periodType);

        return $year * 1000 + $first * 10 + ($periodType === 'quarterly' ? 1 : 0);
    }

    /** Označení období pro popis a číslo dokladu ('01/2026', 'Q1/2026'). */
    public static function periodLabel(int $year, int $month, string $periodType): string
    {
        [, , $first] = self::periodBounds($year, $month, $periodType);

        return $periodType === 'quarterly'
            ? sprintf('Q%d/%04d', (int) ceil($first / 3), $year)
            : sprintf('%02d/%04d', $first, $year);
    }

    /**
     * Období, které se má zaúčtovat k danému dni — poslední UZAVŘENÉ (tj. to, do
     * kterého spadá předchozí měsíc). Cron běží 1. dne v měsíci, takže měsíčnímu
     * plátci vyjde minulý měsíc a čtvrtletnímu čtvrtletí, do kterého minulý měsíc
     * patří (pro nekoncové měsíce je to čtvrtletí ještě neuzavřené — proto
     * {@see isPeriodClosed()}).
     *
     * @return array{0:int, 1:int} [rok, měsíc]
     */
    public static function previousPeriod(\DateTimeImmutable $today): array
    {
        // `first day of this month` PŘED odečtením měsíce (31. 3. − 1 měsíc = 3. 3.).
        $target = $today->modify('first day of this month')->modify('-1 month');

        return [(int) $target->format('Y'), (int) $target->format('n')];
    }

    /**
     * Je období obsahující (rok, měsíc) k danému dni už kompletní? U čtvrtletního
     * plátce se doklad smí udělat až po posledním měsíci čtvrtletí.
     *
     * @param 'monthly'|'quarterly' $periodType
     */
    public static function isPeriodClosed(int $year, int $month, string $periodType, \DateTimeImmutable $today): bool
    {
        [, $end] = self::periodBounds($year, $month, $periodType);

        return $end < $today->format('Y-m-d');
    }

    // ── čtení konfigurace tenanta ─────────────────────────────────────────────

    /**
     * Dodavatelé, kteří zúčtovací doklad vůbec mohou mít: podvojné účetnictví
     * a plátce (nebo identifikovaná osoba — ta taky podává přiznání).
     *
     * @return list<int>
     */
    public function candidateSupplierIds(): array
    {
        $rows = $this->db->pdo()->query(
            "SELECT id FROM supplier
              WHERE accounting_mode = 'double_entry'
                AND (is_vat_payer = 1 OR is_identified = 1)
              ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $rows ?: []);
    }

    /**
     * Může tenhle dodavatel mít zúčtovací doklad? Bodový dotaz místo
     * {@see candidateSupplierIds()} — cesta „přepočet při podání přiznání" se ptá
     * na jednu firmu, ne na seznam.
     */
    public function isCandidate(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM supplier
              WHERE id = ? AND accounting_mode = 'double_entry'
                AND (is_vat_payer = 1 OR is_identified = 1)"
        );
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Zdaňovací období tenanta. Identifikovaná osoba podává měsíčně bez ohledu na
     * `vat_period` (shodně s {@see \MyInvoice\Action\Report\DphPriznaniAction}).
     *
     * @return 'monthly'|'quarterly'
     */
    public function vatPeriodFor(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_period, is_vat_payer, is_identified FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 'monthly';
        }
        if ((bool) ($row['is_identified'] ?? false) && !(bool) ($row['is_vat_payer'] ?? false)) {
            return 'monthly';
        }
        $period = (string) ($row['vat_period'] ?? 'monthly');

        return $period === 'quarterly' ? 'quarterly' : 'monthly';
    }

    // ── výpočet a zaúčtování ──────────────────────────────────────────────────

    /**
     * Spočítá zúčtování za období obsahující (rok, měsíc) — BEZ zápisu do deníku.
     *
     * @return array{
     *   supplier_id:int, period_type:string, period_start:string, period_end:string,
     *   period_label:string, source_id:int, input_vat:float, output_vat:float,
     *   settlement:float, accounts:array{input:string, output:string, settlement:string},
     *   accounts_detail:list<array{account_code:string,direction:string,amount:float}>,
     *   status:?string, entry_id:?int, taken_over:bool
     * }
     */
    public function preview(int $supplierId, int $year, int $month): array
    {
        $periodType = $this->vatPeriodFor($supplierId);
        [$start, $end] = self::periodBounds($year, $month, $periodType);
        $sourceId = self::sourceIdFor($year, $month, $periodType);

        $rules = $this->rules->effectiveMap($supplierId, 'invoice.vat.')
            + $this->rules->effectiveMap($supplierId, 'vat.clearing.');
        $rates = VatPostingAccounts::byRate($rules);
        $common = $codes = [];
        $settlementAcc = VatPostingAccounts::settlement($rules);
        foreach (VatPostingAccounts::SIDES as $direction => $side) {
            $common[$direction] = VatPostingAccounts::common($rules, $direction);
            $legacyKey = 'vat.clearing.' . $direction;
            $legacy = ($rules[$legacyKey]['supplier_id'] ?? null) !== null
                ? VatPostingAccounts::ruleCode($rules, $legacyKey, $side === 'debit' ? 'credit' : 'debit')
                : null;
            $codes[$direction] = array_values(array_unique(array_filter([$common[$direction], ...array_values($rates[$direction]), $legacy])));
            $codes[$direction] = array_values(array_unique(array_map(
                fn (string $code): string => $this->posting->redirectedAccountCode($supplierId, $code, $end),
                $codes[$direction],
            )));
        }
        $settlementAcc = $this->posting->redirectedAccountCode($supplierId, $settlementAcc, $end);

        $existing = $this->journal->findBySource($supplierId, self::SOURCE_TYPE, $sourceId);

        $base = [
            'supplier_id'  => $supplierId,
            'period_type'  => $periodType,
            'period_start' => $start,
            'period_end'   => $end,
            'period_label' => self::periodLabel($year, $month, $periodType),
            'source_id'    => $sourceId,
            'input_vat'    => 0.0,
            'output_vat'   => 0.0,
            'settlement'   => 0.0,
            'accounts'     => $common + ['settlement' => $settlementAcc],
            'accounts_detail' => [],
            'status'       => null,
            'entry_id'     => $existing !== null ? (int) $existing['id'] : null,
            'taken_over'   => $this->hasTakenOverEntries($supplierId, $start, $end),
        ];

        $taxCodes = [...$codes['output'], ...$codes['input']];
        if (array_intersect($codes['input'], $codes['output']) !== [] || in_array($settlementAcc, $taxCodes, true)) {
            $base['status'] = self::STATUS_FLAT_VAT_ACCOUNT;
            return $base;
        }
        $active = $this->accounts->codeToIdMap($supplierId, $end);
        foreach ([...$taxCodes, $settlementAcc] as $code) {
            if (empty($active[$code]['is_active'])) {
                $base['status'] = self::STATUS_MISSING_ACCOUNTS;
                return $base;
            }
        }

        $turnover = $this->turnover($supplierId, $start, $end, $taxCodes);
        $hasTurnover = false;
        foreach (['output', 'input'] as $direction) {
            foreach ($codes[$direction] as $code) {
                $debit = $turnover[$code]['debit'];
                $credit = $turnover[$code]['credit'];
                $amount = round($direction === 'output' ? $credit - $debit : $debit - $credit, 2);
                $base['accounts_detail'][] = ['account_code' => $code, 'direction' => $direction, 'amount' => $amount];
                $base[$direction . '_vat'] += $amount;
                $hasTurnover = $hasTurnover || self::cents($amount) !== 0;
            }
            $base[$direction . '_vat'] = round($base[$direction . '_vat'], 2);
        }
        $base['settlement'] = round($base['output_vat'] - $base['input_vat'], 2);
        if (!$hasTurnover) {
            $base['status'] = self::STATUS_ZERO;
        }

        return $base;
    }

    /**
     * Spočítá a ZAÚČTUJE zúčtovací doklad za období obsahující (rok, měsíc).
     *
     * Idempotentní PŘEPISEM NA MÍSTĚ — stejný `source_type`+`source_id` provede
     * {@see PostingService::postDocument()} přes `rewriteExisting()`, takže zápis
     * si ZACHOVÁ `id` a v deníku nezůstane stornovaná stopa (zúčtování je dopočtená
     * veličina, ne hospodářská operace). Storno se tady nevolá nikdy.
     *
     * Vyjde-li období nulové a doklad z dřívějška existuje, doklad se SMAŽE
     * ({@see STATUS_DELETED_ZERO}) — prázdný ani stornovaný zápis po sobě nenechává.
     *
     * @param array<string,mixed> $meta auditní meta pro {@see PostingService::postDocument()};
     *        navíc rozumí `trigger` ({@see TRIGGER_MANUAL} a spol.) a `submission`
     *        (řádek `tax_submissions`, kterému doklad odpovídá)
     * @return array<string,mixed> tvar {@see preview()} + status/entry_id
     *
     * @throws PostingException zavřené / chybějící / zamčené období
     */
    public function postForPeriod(int $supplierId, int $year, int $month, array $meta = [], bool $dryRun = false): array
    {
        $result = $this->preview($supplierId, $year, $month);
        $trigger = self::normalizeTrigger($meta['trigger'] ?? null);

        if ($result['taken_over'] && in_array($trigger, self::AUTOMATIC_TRIGGERS, true)) {
            $result['status'] = self::STATUS_TAKEN_OVER;
            return $result;
        }

        // Nulové období: doklad nemá co převádět. Pokud z dřívějška existuje (období se
        // po zaúčtování vynulovalo — dobropis, přeřazení dokladu), musí zmizet, jinak by
        // na 343.900 dál viselo saldo, které už nic nepodkládá.
        if ($result['status'] === self::STATUS_ZERO) {
            if ($result['entry_id'] === null) {
                return $result;
            }
            if ($dryRun) {
                $result['status'] = self::STATUS_DRY_RUN;
                return $result;
            }
            $this->deleteEntry($supplierId, (int) $result['entry_id'], $result['period_end'], $meta);
            $this->runs->forget($supplierId, (int) $result['source_id']);
            $result['status'] = self::STATUS_DELETED_ZERO;
            $result['entry_id'] = null;

            return $result;
        }
        if ($result['status'] !== null) {
            return $result;
        }

        $lines = self::buildLines($result['accounts_detail'], $result['accounts']['settlement']);
        if ($lines === []) {
            $result['status'] = self::STATUS_ZERO;
            return $result;
        }
        if ($dryRun) {
            $result['status'] = self::STATUS_DRY_RUN;
            return $result;
        }

        $entryId = $this->posting->postDocument(
            $supplierId,
            self::SOURCE_TYPE,
            $result['source_id'],
            PairGroups::consecutive($lines, 'vat_clearing'),
            [
                'entry_date'  => $result['period_end'],
                'document_no' => 'DPH-' . $result['period_label'],
                'description' => 'Zúčtování DPH za ' . $result['period_label'],
                'posted'      => true,
                'posted_by'   => $meta['user_id'] ?? null,
                'user_id'     => $meta['user_id'] ?? null,
                'ip'          => $meta['ip'] ?? null,
                'user_agent'  => $meta['user_agent'] ?? null,
            ],
        );

        $result['status'] = self::STATUS_POSTED;
        $result['entry_id'] = $entryId;
        $this->recordRun($result, $trigger, is_array($meta['submission'] ?? null) ? $meta['submission'] : null, $meta['user_id'] ?? null);

        return $result;
    }

    /** Má deník firmy v rozsahu dat zápis převzatý z jiného účetního programu? */
    public function hasTakenOverEntries(int $supplierId, string $from, string $to): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entries je
              WHERE je.supplier_id = ? AND je.entry_date BETWEEN ? AND ?
                AND ' . TakenOverRecord::journalEntrySql('je') . ' LIMIT 1'
        );
        $stmt->execute([$supplierId, $from, $to]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Smaže zúčtovací doklad (nulové období). Guardy jsou ZÁMĚRNĚ tytéž jako u zápisu —
     * do zavřeného období a za zámek se nesahá ani mazáním. Řádky odejdou kaskádou
     * (`fk_jel_entry_supplier ON DELETE CASCADE`).
     *
     * @param array<string,mixed> $meta
     *
     * @throws PostingException
     */
    private function deleteEntry(int $supplierId, int $entryId, string $entryDate, array $meta): void
    {
        $this->assertWritable($supplierId, $entryDate);

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            (new \MyInvoice\Service\Property\Charges\Issuance\IssuanceUseGuard($this->db))->assertPostingEntriesMutable($supplierId, [$entryId]);
            (new \MyInvoice\Service\Accounting\Allocation\JointAllocationUseGuard($this->db))->assertEntriesMutable($supplierId, [$entryId]);
            // Řádek 343 spárovaný v okruhu s platbou daně by smazání odneslo kaskádou.
            (new \MyInvoice\Service\Accounting\JournalPairingUseGuard($this->db))->assertEntriesFree($supplierId, [$entryId]);
            (new \MyInvoice\Service\Accounting\JournalSettlementUseGuard($this->db))->assertEntriesFree($supplierId, [$entryId]);
            $stmt = $pdo->prepare('DELETE FROM journal_entries WHERE id = ? AND supplier_id = ? AND source_type = ?');
            $stmt->execute([$entryId, $supplierId, self::SOURCE_TYPE]);
            $pdo->prepare("INSERT INTO activity_log (action, user_id, entity_type, entity_id, payload, ip, user_agent, supplier_id)
                           VALUES ('accounting.vat_clearing_deleted', ?, 'journal_entry', ?, ?, ?, ?, ?)")
                ->execute([
                    $meta['user_id'] ?? null,
                    $entryId,
                    json_encode(['reason' => 'zero_period', 'entry_date' => $entryDate], JSON_UNESCAPED_UNICODE),
                    $meta['ip'] ?? null,
                    $meta['user_agent'] ?? null,
                    $supplierId,
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
    }

    /**
     * Smí se k tomuhle datu vůbec zapisovat/mazat? Stejná pravidla jako
     * {@see PostingService::postDocument()} — jen vyhodnocená DOPŘEDU, aby volající
     * (přepočet při podání přiznání) mohl místo výjimky vyrobit nález.
     *
     * @throws PostingException `no_accounting_period` | `period_not_open` | `date_locked`
     */
    private function assertWritable(int $supplierId, string $entryDate): void
    {
        // ⚠️ Chybějící období se tady ZÁMĚRNĚ nezakládá, na rozdíl od účtování dokladu
        // ({@see \MyInvoice\Service\Accounting\AccountingPeriodProvisioner}). Tahle metoda
        // je PREDIKÁT, ne zápis: její výsledek plní `writable_reason` v přehledu DPH
        // (DphPriznaniReport.vue), takže by se účetní období zakládala jako vedlejší
        // efekt otevření sestavy. Doklad zúčtování DPH vzniká ke KONCI zdaňovacího
        // období, do kterého se celý měsíc účtovalo — období tedy v praxi dávno existuje
        // (otevřel ho první zaúčtovaný doklad).
        $period = $this->periods->findForDate($supplierId, $entryDate);
        if ($period === null) {
            throw new PostingException(
                'no_accounting_period',
                'Pro datum ' . $entryDate . ' neexistuje účetní období.',
                422,
                ['fiscal_year' => (int) substr($entryDate, 0, 4)],
            );
        }
        if ((string) $period['status'] !== 'open') {
            throw new PostingException(
                'period_not_open',
                'Účetní období ' . $period['fiscal_year'] . ' je ve stavu "' . $period['status']
                    . '" — do uzavřeného období nelze zasahovat (§35 ZoÚ).',
            );
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId);
        if ($lockedUntil !== null && $entryDate <= $lockedUntil) {
            throw new PostingException(
                'date_locked',
                'Datum ' . $entryDate . ' je uzamčené k ' . $lockedUntil
                    . ' (podané daňové přiznání / ruční zámek období).',
            );
        }
    }

    /**
     * Zapíše auditní stopu běhu — čím byl přepočet vyvolán a kterému podání odpovídá.
     *
     * @param array<string,mixed>      $result výsledek {@see postForPeriod()}
     * @param array<string,mixed>|null $submission řádek `tax_submissions`
     */
    private function recordRun(array $result, string $trigger, ?array $submission, ?int $userId): void
    {
        $this->runs->record([
            'supplier_id'        => (int) $result['supplier_id'],
            'source_id'          => (int) $result['source_id'],
            'period_year'        => (int) substr((string) $result['period_start'], 0, 4),
            'period_first_month' => (int) substr((string) $result['period_start'], 5, 2),
            'period_type'        => (string) $result['period_type'],
            'period_start'       => (string) $result['period_start'],
            'period_end'         => (string) $result['period_end'],
            'entry_id'           => $result['entry_id'] !== null ? (int) $result['entry_id'] : null,
            'input_vat'          => (float) $result['input_vat'],
            'output_vat'         => (float) $result['output_vat'],
            'settlement'         => (float) $result['settlement'],
            'status'             => (string) $result['status'],
            'trigger_source'     => $trigger,
            'submission_id'      => $submission !== null ? (int) $submission['id'] : null,
            'submission_form'    => $submission !== null ? (string) $submission['form_code'] : null,
            'submission_variant' => $submission !== null ? (string) $submission['form_variant'] : null,
            'submitted_at'       => $submission['submitted_at'] ?? null,
            'computed_by'        => $userId !== null ? (int) $userId : null,
        ]);
    }

    private static function normalizeTrigger(mixed $trigger): string
    {
        $allowed = [self::TRIGGER_RETURN_FILED, self::TRIGGER_RETURN_DRAFT, self::TRIGGER_MANUAL, self::TRIGGER_CRON];

        return is_string($trigger) && in_array($trigger, $allowed, true) ? $trigger : self::TRIGGER_MANUAL;
    }

    // ── aktuálnost zúčtování (počítá se živě, neukládá se) ────────────────────

    /**
     * Stav zúčtování za období: co by se zaúčtovalo dnes, co je zaúčtované teď, jestli
     * se to rozchází a jestli se s tím vůbec smí hnout.
     *
     * `freshness` se NEČTE z žádného uloženého příznaku — porovnává se čerstvě spočítaný
     * obrat období proti částkám, které na dokladu SKUTEČNĚ leží. Tím kontrola chytí
     * i změnu, která přišla cestou, jež by uložený příznak neuměla zneplatnit (import,
     * hromadná oprava, ruční zásah v deníku).
     *
     * @return array<string,mixed> tvar {@see preview()} + `freshness`, `posted`,
     *         `writable`, `writable_reason`, `run`
     */
    public function status(int $supplierId, int $year, int $month): array
    {
        $result = $this->preview($supplierId, $year, $month);
        $result['run'] = $this->runs->find($supplierId, (int) $result['source_id']);

        $notApplicable = in_array(
            $result['status'],
            [self::STATUS_MISSING_ACCOUNTS, self::STATUS_FLAT_VAT_ACCOUNT],
            true,
        );

        $posted = $result['entry_id'] !== null
            ? $this->postedAmounts($supplierId, (int) $result['entry_id'], $result['accounts_detail'], $result['accounts']['settlement'])
            : null;
        $result['posted'] = $posted;

        if ($notApplicable) {
            $result['freshness'] = self::FRESHNESS_NOT_APPLICABLE;
        } else {
            $remaining = $posted['account_balances'] ?? [];
            $matches = true;
            foreach ($result['accounts_detail'] as $detail) {
                $code = $detail['account_code'];
                $expected = $detail['direction'] === 'output' ? $detail['amount'] : -$detail['amount'];
                $matches = $matches && self::immaterial($expected - ($remaining[$code] ?? 0.0));
                unset($remaining[$code]);
            }
            unset($remaining[$result['accounts']['settlement']]);
            $matches = $matches
                && self::immaterial($result['input_vat'] - ($posted['input_vat'] ?? 0.0))
                && self::immaterial($result['output_vat'] - ($posted['output_vat'] ?? 0.0))
                && self::immaterial($result['settlement'] - ($posted['settlement'] ?? 0.0));
            foreach ($remaining as $amount) {
                $matches = $matches && self::immaterial($amount);
            }
            $result['freshness'] = $matches
                ? self::FRESHNESS_OK
                : ($posted === null ? self::FRESHNESS_MISSING : self::FRESHNESS_STALE);
        }

        try {
            $this->assertWritable($supplierId, (string) $result['period_end']);
            $result['writable'] = true;
            $result['writable_reason'] = null;
        } catch (PostingException $e) {
            $result['writable'] = false;
            $result['writable_reason'] = $e->errorCode;
        }

        return $result;
    }

    /**
     * Období DPH překrývající zadaný rozsah, u kterých se zúčtování rozchází se
     * skutečností — podklad pro kontrolu uzávěrky `vat_clearing_stale`.
     *
     * Vrací JEN vadná období (prázdné pole = vše sedí). Období, do kterého se nesmí
     * zapisovat, se hlásí taky — právě proto, že se samo neopraví.
     *
     * PROBÍHAJÍCÍ OBDOBÍ SE NEHLÁSÍ. Zúčtování se dělá až po konci zdaňovacího období,
     * takže dokud období běží, jeho „chybějící" doklad není vada. Bez téhle podmínky by
     * měsíční kontrola u ČTVRTLETNÍHO plátce hlásila nález pokaždé, když se pustí uprostřed
     * kvartálu — rozsah je jeden měsíc, ale zdaňovací období celé čtvrtletí, které ještě
     * neskončilo. Trvale svítící nález se přestane číst a zabije i ty pravé.
     *
     * OBDOBÍ PŘEVZATÉ Z JINÉHO PROGRAMU SE NEHLÁSÍ. Deník do konce převodu zaúčtoval zdroj
     * (POHODA, Money S3, PREMIER …) i se svým vypořádáním DPH, které zúčtovací doklad
     * MyÚčta nezná: POHODA převádí daň na 343 jen dvěma doklady za rok, jiný program
     * jinak. Zúčtovací doklad MyÚčta by do převzatého deníku přidal zápis, který zdroj
     * nemá, a rozbil by rekonciliaci převodu. Převzaté je každé období, ve kterém leží
     * převzatý zápis, i to, ve kterém převod skončil (převádí se celé období).
     *
     * @return list<array<string,mixed>>
     */
    public function staleForRange(int $supplierId, string $from, string $to, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $periodType = $this->vatPeriodFor($supplierId);
        $step = $periodType === 'quarterly' ? 3 : 1;
        $takenOverUntil = $this->takenOverUntil($supplierId);

        $cursor = (new \DateTimeImmutable($from))->modify('first day of this month');
        $limit = new \DateTimeImmutable($to);
        $seen = [];
        $out = [];
        while ($cursor <= $limit) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $sourceId = self::sourceIdFor($year, $month, $periodType);
            if ($takenOverUntil !== null && $cursor->format('Y-m-d') <= $takenOverUntil) {
                $cursor = $cursor->modify('+' . $step . ' month');
                continue;
            }
            if (!isset($seen[$sourceId]) && self::isPeriodClosed($year, $month, $periodType, $today)) {
                $seen[$sourceId] = true;
                $status = $this->status($supplierId, $year, $month);
                if (in_array($status['freshness'], [self::FRESHNESS_STALE, self::FRESHNESS_MISSING], true)) {
                    $out[] = self::finding($status);
                }
            }
            $cursor = $cursor->modify('+' . $step . ' month');
        }

        return $out;
    }

    /** Datum posledního zápisu deníku převzatého z jiného programu; null = firma nic nepřevzala. */
    private function takenOverUntil(int $supplierId): ?string
    {
        $until = null;
        foreach (TakenOverRecord::MAPS as $map => $kinds) {
            if (!$this->db->hasTable($map)) {
                continue;
            }
            $kindList = (array) $kinds[TakenOverRecord::JOURNAL_ENTRY];
            $placeholders = implode(', ', array_fill(0, count($kindList), '?'));
            $stmt = $this->db->pdo()->prepare(
                "SELECT MAX(je.entry_date) FROM {$map} m
                   JOIN journal_entries je ON je.id = m.target_id AND je.supplier_id = m.supplier_id
                  WHERE m.supplier_id = ? AND m.kind IN ({$placeholders})"
            );
            $stmt->execute([$supplierId, ...$kindList]);
            $date = $stmt->fetchColumn();
            if (is_string($date) && ($until === null || $date > $until)) {
                $until = substr($date, 0, 10);
            }
        }
        return $until;
    }

    /**
     * Nález pro kontrolu uzávěrky. Klíče schválně odpovídají tomu, co umí přečíst
     * {@see \MyInvoice\Service\Accounting\Closing\CheckFindingNormalizer} (`doc_date`,
     * `doc_no`, `amount`, `entry_id`, `note`, `detail`) — jinak by se nález sice spočítal,
     * ale v tabulce by se vykreslil prázdný řádek.
     *
     * @param array<string,mixed> $status výstup {@see status()}
     * @return array<string,mixed>
     */
    private static function finding(array $status): array
    {
        $note = match (true) {
            $status['freshness'] === self::FRESHNESS_MISSING => 'Za období zůstává nepřevedená daň. Zúčtovací doklad chybí.',
            self::cents((float) $status['settlement']) === self::cents((float) ($status['posted']['settlement'] ?? 0)) =>
                'Částka k zúčtování sedí, na účtech daně se obrat liší od zaúčtovaného rozpisu.',
            default => sprintf(
                'Zaúčtováno %s, z dnešních dat vychází %s.',
                number_format((float) ($status['posted']['settlement'] ?? 0), 2, ',', ' '),
                number_format((float) $status['settlement'], 2, ',', ' '),
            ),
        };
        if (!$status['writable']) {
            $note .= $status['writable_reason'] === 'date_locked'
                ? ' Období je uzamčené — přepočet až po posunutí zámku (smí správce nebo role s právem posunout zámek).'
                : ' Období není otevřené — rozdíl patří do dodatečného přiznání, ne do zavřených knih.';
        }

        return [
            'doc_type'        => 'journal_entry',
            'entry_id'        => $status['entry_id'],
            'doc_no'          => $status['period_label'],
            'doc_date'        => $status['period_end'],
            'amount'          => $status['settlement'],
            // `issues` se schválně NEPOSÍLÁ: klient při jejich přítomnosti zahodí `note`
            // a vypíše jen přeložené kódy (CheckFindings.vue) — tady je ale nesená
            // informace právě ve větě s konkrétními čísly, ne v kódu.
            'note'            => $note,
            'freshness'       => $status['freshness'],
            'source_id'       => $status['source_id'],
            'writable'        => $status['writable'],
            'writable_reason' => $status['writable_reason'],
            'detail'          => [
                'input_vat'     => $status['input_vat'],
                'output_vat'    => $status['output_vat'],
                'posted_input'  => $status['posted']['input_vat'] ?? null,
                'posted_output' => $status['posted']['output_vat'] ?? null,
            ],
        ];
    }

    /**
     * Částky, které na zúčtovacím dokladu SKUTEČNĚ leží — inverze {@see buildLines()}:
     * z řádku na účtu daně na výstupu se čte kladně strana MD, z účtu daně na vstupu
     * kladně strana D (obrácené strany = převaha dobropisů, tedy záporná daň).
     *
     * @return array{input_vat:float, output_vat:float, settlement:float, account_balances:array<string,float>}
     */
    private function postedAmounts(int $supplierId, int $entryId, array $details, string $settlementAccount): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code, SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS net
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ?
              GROUP BY a.account_code"
        );
        $stmt->execute([$entryId, $supplierId]);
        $balances = array_map('floatval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        $totals = ['input_vat' => 0.0, 'output_vat' => 0.0];
        foreach ($details as $detail) {
            $direction = $detail['direction'];
            $net = $balances[$detail['account_code']] ?? 0.0;
            $totals[$direction . '_vat'] += $direction === 'output' ? $net : -$net;
        }
        return array_map(static fn (float $amount): float => round($amount, 2), $totals) + [
            'settlement' => 0.0 - ($balances[$settlementAccount] ?? 0.0),
            'account_balances' => $balances,
        ];
    }

    /**
     * Zdaňovací období z řádku `tax_submissions` → (rok, měsíc) pro {@see postForPeriod()}.
     * Kvartální podání se mapuje na POSLEDNÍ měsíc kvartálu; měsíční na svůj měsíc.
     * Vrací null, když řádek nenese jednoznačné období (roční výkazy).
     *
     * @param array<string,mixed> $submission
     * @return array{0:int, 1:int}|null
     */
    public static function periodFromSubmission(array $submission): ?array
    {
        $year = (int) ($submission['period_year'] ?? 0);
        if ($year < 2000) {
            return null;
        }
        $month = $submission['period_month'] ?? null;
        if ($month !== null && (int) $month >= 1 && (int) $month <= 12) {
            return [$year, (int) $month];
        }
        $quarter = $submission['period_quarter'] ?? null;
        if ($quarter !== null && (int) $quarter >= 1 && (int) $quarter <= 4) {
            return [$year, (int) $quarter * 3];
        }

        return null;
    }

    /**
     * Každý nenulový účet tvoří vlastní vyvážený pár se zúčtovacím účtem.
     * Záporná daň obrací strany.
     *
     * @param list<array{account_code:string, direction:string, amount:float}> $details
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    public static function buildLines(array $details, string $settlementAccount): array
    {
        $lines = [];
        foreach ($details as $detail) {
            $amount = $detail['amount'];
            if (self::cents($amount) === 0) {
                continue;
            }
            $side = ($detail['direction'] === 'output') === ($amount > 0) ? 'debit' : 'credit';
            $lines[] = ['account_code' => $detail['account_code'], 'side' => $side, 'amount' => round(abs($amount), 2)];
            $lines[] = [
                'account_code' => $settlementAccount,
                'side' => $side === 'debit' ? 'credit' : 'debit',
                'amount' => round(abs($amount), 2),
            ];
        }

        return $lines;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    /**
     * Obrat období na zadaných účtech (MD/D zvlášť). Vylučuje koncepty, uzávěrkové
     * převody i vlastní zúčtovací doklady — viz třídní docblock.
     *
     * @param list<string> $codes
     * @return array<string, array{debit:float, credit:float}>
     */
    private function turnover(int $supplierId, string $start, string $end, array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out[$code] = ['debit' => 0.0, 'credit' => 0.0];
        }
        if ($codes === []) {
            return $out;
        }
        $placeholders = implode(', ', array_fill(0, count($codes), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.signed_amount ELSE 0 END), 0) AS d,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.signed_amount ELSE 0 END), 0) AS c
               FROM journal_entry_lines l
               JOIN journal_entries e    ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a  ON a.id = l.account_id
              WHERE l.supplier_id = ?
                AND a.account_code IN ({$placeholders})
                AND e.posted_at IS NOT NULL
                AND e.entry_date BETWEEN ? AND ?
                AND e.source_type NOT IN ('closing', 'opening', '" . self::SOURCE_TYPE . "')
              GROUP BY a.account_code"
        );
        $stmt->execute([$supplierId, ...$codes, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['account_code']] = [
                'debit'  => round((float) $row['d'], 2),
                'credit' => round((float) $row['c'], 2),
            ];
        }

        return $out;
    }

    /** Peníze se porovnávají v haléřích (int), nikdy přes float == (viz PostingService). */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function immaterial(float $amount): bool
    {
        return abs(self::cents($amount)) <= self::cents(self::MATERIALITY);
    }
}
