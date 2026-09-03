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
use MyInvoice\Service\Accounting\PostingException;
use MyInvoice\Service\Accounting\PostingService;
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
 * Po tomhle dokladu jsou 343.100 i 343.200 za období nulové a na 343.900 leží přesně
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
 * BEZPEČNOST ZÁPISU. Doklad se nikdy nepostne nevyvážený: skládá se ze dvou
 * self-balanced párů, každý pár se přidá jen když je jeho částka nenulová. Když jsou
 * nulové obě, doklad se NEZAKLÁDÁ vůbec (status `skipped_zero`) — a pokud z dřívějška
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
     *   status:?string, entry_id:?int
     * }
     */
    public function preview(int $supplierId, int $year, int $month): array
    {
        $periodType = $this->vatPeriodFor($supplierId);
        [$start, $end] = self::periodBounds($year, $month, $periodType);
        $sourceId = self::sourceIdFor($year, $month, $periodType);

        $inputAcc      = $this->ruleCode($supplierId, 'vat.clearing.input', 'credit', PostingService::INPUT_VAT_ACCOUNT);
        $outputAcc     = $this->ruleCode($supplierId, 'vat.clearing.output', 'debit', PostingService::OUTPUT_VAT_ACCOUNT);
        $settlementAcc = $this->ruleCode($supplierId, 'vat.clearing.output', 'credit', PostingService::VAT_SETTLEMENT_ACCOUNT);

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
            'accounts'     => ['input' => $inputAcc, 'output' => $outputAcc, 'settlement' => $settlementAcc],
            'status'       => null,
            'entry_id'     => $existing !== null ? (int) $existing['id'] : null,
        ];

        // Tenant, který si analytiky vypnul (obě nohy míří na týž účet), nemá co
        // převádět — doklad by byl 343/343 v obou párech, tedy prázdné gesto.
        if ($inputAcc === $outputAcc || $inputAcc === $settlementAcc || $outputAcc === $settlementAcc) {
            $base['status'] = self::STATUS_FLAT_VAT_ACCOUNT;
            return $base;
        }
        foreach ([$inputAcc, $outputAcc, $settlementAcc] as $code) {
            $account = $this->accounts->findByCode($supplierId, $code);
            if ($account === null || empty($account['is_active'])) {
                $base['status'] = self::STATUS_MISSING_ACCOUNTS;
                return $base;
            }
        }

        $turnover = $this->turnover($supplierId, $start, $end, [$inputAcc, $outputAcc]);
        // Vstup má přirozeně debetní zůstatek, výstup kreditní — počítáme je tak, aby
        // kladné číslo znamenalo „normální" směr a záporné obrácený (dobropisy).
        $inputVat  = round($turnover[$inputAcc]['debit'] - $turnover[$inputAcc]['credit'], 2);
        $outputVat = round($turnover[$outputAcc]['credit'] - $turnover[$outputAcc]['debit'], 2);

        $base['input_vat']  = $inputVat;
        $base['output_vat'] = $outputVat;
        $base['settlement'] = round($outputVat - $inputVat, 2);
        if (self::cents($inputVat) === 0 && self::cents($outputVat) === 0) {
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

        $lines = self::buildLines(
            $result['input_vat'],
            $result['output_vat'],
            $result['accounts']['input'],
            $result['accounts']['output'],
            $result['accounts']['settlement'],
        );
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
            $lines,
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
            ? $this->postedAmounts(
                $supplierId,
                (int) $result['entry_id'],
                (string) $result['accounts']['input'],
                (string) $result['accounts']['output'],
            )
            : null;
        $result['posted'] = $posted;

        if ($notApplicable) {
            $result['freshness'] = self::FRESHNESS_NOT_APPLICABLE;
        } elseif ($posted === null) {
            // Bez dokladu je období v pořádku, dokud v něm nezůstává NEPŘEVEDENÁ daň
            // nad práh materiality (nulové období nemá co převádět; haléřový zbytek po
            // ručním vyrovnání taky ne — viz MATERIALITY).
            $result['freshness'] = self::immaterial((float) $result['input_vat'], (float) $result['output_vat'])
                ? self::FRESHNESS_OK
                : self::FRESHNESS_MISSING;
        } else {
            $matches = self::immaterial(
                (float) $result['input_vat'] - $posted['input_vat'],
                (float) $result['output_vat'] - $posted['output_vat'],
            );
            $result['freshness'] = $matches ? self::FRESHNESS_OK : self::FRESHNESS_STALE;
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
     * @return list<array<string,mixed>>
     */
    public function staleForRange(int $supplierId, string $from, string $to, ?\DateTimeImmutable $today = null): array
    {
        $today ??= new \DateTimeImmutable('today');
        $periodType = $this->vatPeriodFor($supplierId);
        $step = $periodType === 'quarterly' ? 3 : 1;

        $cursor = (new \DateTimeImmutable($from))->modify('first day of this month');
        $limit = new \DateTimeImmutable($to);
        $seen = [];
        $out = [];
        while ($cursor <= $limit) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $sourceId = self::sourceIdFor($year, $month, $periodType);
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
        $note = $status['freshness'] === self::FRESHNESS_MISSING
            ? 'Za období zůstává nepřevedená daň — zúčtovací doklad chybí.'
            : sprintf(
                'Zaúčtováno %s, z dnešních dat vychází %s.',
                number_format((float) ($status['posted']['settlement'] ?? 0), 2, ',', ' '),
                number_format((float) $status['settlement'], 2, ',', ' '),
            );
        if (!$status['writable']) {
            $note .= $status['writable_reason'] === 'date_locked'
                ? ' Období je uzamčené — přepočet až po posunutí zámku (jen admin).'
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
     * @return array{input_vat:float, output_vat:float, settlement:float}
     */
    private function postedAmounts(int $supplierId, int $entryId, string $inputAccount, string $outputAccount): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.account_code,
                    COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0) AS d,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0) AS c
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE l.entry_id = ? AND l.supplier_id = ? AND a.account_code IN (?, ?)
              GROUP BY a.account_code"
        );
        $stmt->execute([$entryId, $supplierId, $inputAccount, $outputAccount]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['account_code']] = [(float) $row['d'], (float) $row['c']];
        }
        [$outDebit, $outCredit] = $rows[$outputAccount] ?? [0.0, 0.0];
        [$inDebit, $inCredit] = $rows[$inputAccount] ?? [0.0, 0.0];

        $outputVat = round($outDebit - $outCredit, 2);
        $inputVat = round($inCredit - $inDebit, 2);

        return [
            'input_vat'  => $inputVat,
            'output_vat' => $outputVat,
            'settlement' => round($outputVat - $inputVat, 2),
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
     * Řádky dokladu — dva self-balanced páry, každý jen když je nenulový.
     * Kladná částka = obvyklý směr; záporná (převaha dobropisů) obrací strany, aby
     * se nikdy neúčtovala záporná částka (`chk_jel_amount_positive`).
     *
     * @return list<array{account_code:string, side:'debit'|'credit', amount:float}>
     */
    public static function buildLines(
        float $inputVat,
        float $outputVat,
        string $inputAccount,
        string $outputAccount,
        string $settlementAccount,
    ): array {
        $lines = [];
        // MD 343.200 / D 343.900 — daň na výstupu období na zúčtovací účet.
        if (self::cents($outputVat) !== 0) {
            $amount = round(abs($outputVat), 2);
            $outSide = $outputVat > 0 ? 'debit' : 'credit';
            $lines[] = ['account_code' => $outputAccount, 'side' => $outSide, 'amount' => $amount];
            $lines[] = [
                'account_code' => $settlementAccount,
                'side'         => $outSide === 'debit' ? 'credit' : 'debit',
                'amount'       => $amount,
            ];
        }
        // MD 343.900 / D 343.100 — daň na vstupu období na zúčtovací účet.
        if (self::cents($inputVat) !== 0) {
            $amount = round(abs($inputVat), 2);
            $inSide = $inputVat > 0 ? 'credit' : 'debit';
            $lines[] = ['account_code' => $inputAccount, 'side' => $inSide, 'amount' => $amount];
            $lines[] = [
                'account_code' => $settlementAccount,
                'side'         => $inSide === 'credit' ? 'debit' : 'credit',
                'amount'       => $amount,
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
                    COALESCE(SUM(CASE WHEN l.side = 'debit'  THEN l.amount ELSE 0 END), 0) AS d,
                    COALESCE(SUM(CASE WHEN l.side = 'credit' THEN l.amount ELSE 0 END), 0) AS c
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

    private function ruleCode(int $supplierId, string $ruleKey, string $side, string $fallback): string
    {
        $rule = $this->rules->resolve($supplierId, $ruleKey);
        $code = $rule[$side . '_account_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : $fallback;
    }

    /** Peníze se porovnávají v haléřích (int), nikdy přes float == (viz PostingService). */
    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Jsou obě nohy pod prahem {@see MATERIALITY}? (porovnání v haléřích, ne přes float) */
    private static function immaterial(float $input, float $output): bool
    {
        $limit = self::cents(self::MATERIALITY);

        return abs(self::cents($input)) <= $limit && abs(self::cents($output)) <= $limit;
    }
}
