<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Dimension\SystemDimensionCatalog;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\MoneyS3\AccountCode;
use MyInvoice\Service\Migration\Shared\ChartAccountCreator;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;

/**
 * Účtová osnova, účetní období a účetní deník z Pohody.
 *
 * Osnova: firma má osnovu ze šablony MyÚčta, z Pohody se doplní jen účty, na které deník
 * účtuje (`321001` → `321.001` pod syntetikou `321`), s názvem z osnovy Pohody. Chybějící
 * syntetika dostane typ od sourozence ze skupiny, jinak ze třídy (stejně jako u Money S3).
 *
 * Deník je autoritativní kopie Pohody - zaúčtování se nepřepočítává, doklady se k němu
 * jen připojí ({@see DocumentLinker}). Počáteční stavy tvoří jeden otevírací zápis se
 * zdrojem `opening` a klíčem = id období, pod stejným klíčem ho zakládá uzávěrka MyÚčta.
 */
final class ChartJournalImporter
{
    public const STEP_CHART = 'chart';
    public const STEP_JOURNAL = 'journal';

    public function __construct(
        private readonly Connection $db,
        private readonly ChartOfAccountsRepository $accounts,
        private readonly ChartOfAccountsSeeder $seeder,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly PohodaImportRepository $map,
    ) {}

    public function chart(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($this->accounts->count($ctx->supplierId) === 0) {
            $p->setCount(self::STEP_CHART, 'seeded', $this->seeder->seedForSupplier($ctx->supplierId, $this->periods->findByYear($ctx->supplierId, $ctx->year())['starts_on'] ?? $ctx->calendar->periodStart($ctx->year())));
        }

        $names = [];
        foreach ($ctx->export->records('chart', 'itemAccount') as $r) {
            $code = trim((string) ($r['@code'] ?? ''));
            $name = trim((string) ($r['@name'] ?? ''));
            if ($code !== '' && ctype_digit($code) && $name !== '') {
                $names[$code] = $name;
            }
        }
        $p->setCount(self::STEP_CHART, 'pohoda_accounts', count($names));

        $used = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::isYearEndClosing($item)) {
                continue;
            }
            foreach (['accounting/credit', 'accounting/debit'] as $path) {
                $code = PohodaXml::text($item, $path);
                if ($code !== '') {
                    $used[$code] = true;
                }
            }
        }
        ksort($used, SORT_STRING);

        $ctx->accountIds = [];
        foreach ($this->accounts->codeToIdMap($ctx->supplierId) as $code => $row) {
            $ctx->accountIds[(string) $code] = (int) $row['id'];
        }

        foreach (array_keys($used) as $raw) {
            $pohodaCode = (string) $raw;
            $target = AccountCode::fromMoney($pohodaCode);
            if ($target === null) {
                $p->error(self::STEP_CHART, 'invalid_account', "Deník Pohody účtuje na účet „{$pohodaCode}\", který není číselný kód účtu.", ['account' => $pohodaCode]);
                continue;
            }
            if (isset($ctx->accountIds[$target])) {
                $p->count(self::STEP_CHART, 'existing');
                continue;
            }
            if ($ctx->skipsNew(self::STEP_CHART)) {
                continue;
            }
            $synthetic = substr($target, 0, 3);
            $parent = $this->accounts->findByCode($ctx->supplierId, $synthetic) ?? $this->createSynthetic($ctx, $synthetic, $names);
            if ($parent === null) {
                continue;
            }
            $id = (new ChartAccountCreator($this->accounts))->createAnalytic($ctx->supplierId, $target, $names[$pohodaCode] ?? ('Analytika ' . $pohodaCode), $parent);
            $ctx->accountIds[$target] = $id;
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_ACCOUNT, $target, $id, $ctx->runId);
            $p->count(self::STEP_CHART, 'created');
        }
        $p->finish(self::STEP_CHART);
    }

    public function journal(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $year = $ctx->year();
        $period = $this->ensurePeriod($ctx, $year);
        $ctx->period = $period;
        $ctx->periods = [$year => $period];

        $groups = [];
        $closingRows = 0;
        $unknownAgendas = [];
        foreach ($ctx->export->records('journal', 'accountingItem') as $item) {
            if (PohodaJournal::isYearEndClosing($item)) {
                $closingRows++;
                continue;
            }
            $groups[PohodaJournal::groupKey($item)][] = $item;
            $source = PohodaJournal::source($item);
            if (str_starts_with($source, PohodaJournal::UNKNOWN_AGENDA_PREFIX)) {
                $unknownAgendas[$source] = ($unknownAgendas[$source] ?? 0) + 1;
            }
            if (PohodaJournal::source($item) === PohodaJournal::CASH) {
                foreach (['accounting/credit', 'accounting/debit'] as $path) {
                    $code = PohodaXml::text($item, $path);
                    if (str_starts_with($code, '211')) {
                        $ctx->cashAccountsByNumber[PohodaJournal::number($item)] = (string) AccountCode::fromMoney($code);
                    }
                }
            }
        }
        if ($period['locked']) {
            $p->info(self::STEP_JOURNAL, 'year_locked', "Rok {$year} je v MyÚčtu už uzavřený, deník se do něj znovu nenahrává.", ['year' => $year]);
            $p->finish(self::STEP_JOURNAL);
            return;
        }
        if ($closingRows > 0) {
            $p->info(self::STEP_JOURNAL, 'year_end_closing_skipped', "Uzávěrkové zápisy z Pohody ({$closingRows} řádků) se nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta.");
        }
        foreach ($unknownAgendas as $agenda => $rows) {
            $p->warn(self::STEP_JOURNAL, 'unknown_journal_source',
                "{$agenda}: převod tuto agendu POHODY nezná, {$rows} řádků deníku převedl jako ruční zápisy bez vazby na doklad. Zkontrolujte je v deníku.",
                ['agenda' => $agenda, 'rows' => $rows]);
        }

        // Firma 504, které činnost založil dřívější převod: od té doby ji deník u nákladů
        // a výnosů vyžaduje hned při zápisu, razítko v závěru převodu by přišlo pozdě.
        $activity = null;
        if ($ctx->export->hasNonprofitChart()) {
            $known = $this->db->pdo()->prepare("SELECT 1 FROM dimension_types WHERE supplier_id = ? AND system_key = 'activity'");
            $known->execute([$ctx->supplierId]);
            if ($known->fetchColumn() !== false) {
                $activity = (new SystemDimensionCatalog($this->db))->ensureForFramework($ctx->supplierId, $period['starts_on'], $ctx->userOrNull())['activity'] ?? null;
            }
        }
        $activityFor = static function (string $code) use ($activity, $ctx): array {
            if ($activity === null || preg_match('/^[56]/', $code) !== 1) {
                return [];
            }
            $ctx->nonprofitLines++;
            return ['dimensions' => [(int) $activity['id'] => (int) $activity['values']['main']]];
        };

        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        $stats = ['year' => $year, 'entries' => 0, 'existing' => 0, 'lines' => 0, 'debit' => 0.0, 'credit' => 0.0, 'skipped_rows' => 0, 'swapped_rows' => 0, 'relocated' => 0, 'later_year_skipped' => 0];
        $moved = [];
        $later = [];
        $now = date('Y-m-d H:i:s');
        $done = 0;
        $total = count($groups);

        foreach ($groups as $groupKey => $rows) {
            if (++$done % 500 === 0) {
                $ctx->report(self::STEP_JOURNAL, $done, $total);
            }
            $key = $year . '|' . $groupKey;
            // Doklad roku agendy, který už přinesla agenda minulého roku (vedla i doklady
            // po svém konci a převod je zapsal do tohoto období) - tentýž zápis podruhé nevzniká.
            $carried = $existing[($year - 1) . '|' . $groupKey] ?? null;
            if ($carried !== null && !isset($existing[$key]) && $groupKey !== PohodaJournal::OPENING_KEY) {
                $stats['existing']++;
                continue;
            }
            if (isset($existing[$key])) {
                $stats['existing']++;
                $date = PohodaXml::date($rows[0], 'date') ?? '';
                $target = $date > $period['ends_on'] ? $this->laterPeriod($ctx, $date) : null;
                if ($target !== null) {
                    $later[$target['year']] = ($later[$target['year']] ?? 0) + 1;
                    if ($this->relocate($ctx, $existing[$key], $period, $target, $date)) {
                        $stats['relocated']++;
                    }
                }
                continue;
            }
            if ($ctx->skipsNew(self::STEP_JOURNAL)) {
                continue;
            }
            $isOpening = $groupKey === PohodaJournal::OPENING_KEY;
            $first = $rows[0];
            if (!$isOpening && $ctx->skipsDate(PohodaXml::date($first, 'date'))) {
                $stats['later_year_skipped']++;
                continue;
            }
            $number = PohodaJournal::number($first);

            $lines = [];
            $lineNo = 0;
            foreach ($rows as $item) {
                $effect = PohodaJournal::effect($item);
                if ($effect === null) {
                    $stats['skipped_rows']++;
                    continue;
                }
                if (PohodaXml::num($item, 'homeCurrency/priceSum') < 0) {
                    $stats['swapped_rows']++;
                }
                $debitId = $ctx->accountIds[AccountCode::fromMoney($effect['debit']) ?? ''] ?? null;
                $creditId = $ctx->accountIds[AccountCode::fromMoney($effect['credit']) ?? ''] ?? null;
                if ($debitId === null || $creditId === null) {
                    throw new PohodaException('unknown_account', sprintf(
                        'Doklad %s účtuje na účet %s/%s, který v osnově chybí.', $number ?: $groupKey, $effect['debit'], $effect['credit']
                    ));
                }
                $amount = number_format($effect['amount'], 2, '.', '');
                $centre = mb_substr(PohodaXml::text($item, 'centre/ids'), 0, 50);
                if ($centre !== '') {
                    $ctx->costCenters[$centre] = PohodaXml::text($item, 'centre/name');
                }
                $lines[] = ['account_id' => $debitId, 'side' => 'debit', 'amount' => $amount, 'line_no' => ++$lineNo, 'cost_center' => $centre !== '' ? $centre : null] + $activityFor((string) $effect['debit']);
                $lines[] = ['account_id' => $creditId, 'side' => 'credit', 'amount' => $amount, 'line_no' => ++$lineNo, 'cost_center' => $centre !== '' ? $centre : null] + $activityFor((string) $effect['credit']);
            }
            if ($lines === []) {
                continue;
            }
            PostingService::assertBalanced($lines);

            $entryDate = $isOpening ? $period['starts_on'] : (PohodaXml::date($first, 'date') ?? '');
            $documentDate = $isOpening ? null : PohodaXml::date($first, 'dateTax');
            if ($entryDate === '') {
                $p->difference(self::STEP_JOURNAL, 'entry_without_date', "Doklad {$number} nemá datum zápisu - nepřenesen.", ['document_no' => $number]);
                continue;
            }
            // Agenda POHODY vede i doklady po konci roku (výpisy a faktury ledna až srpna
            // dalšího roku). Ty patří do svého období, ne k 31. 12. - období se založí
            // otevřené a uzávěrku s převodem zůstatků udělá účetní.
            $entryPeriod = $entryDate > $period['ends_on'] ? $this->laterPeriod($ctx, $entryDate) : null;
            if ($entryPeriod !== null) {
                $later[$entryPeriod['year']] = ($later[$entryPeriod['year']] ?? 0) + 1;
            } elseif ($entryDate < $period['starts_on'] || $entryDate > $period['ends_on']) {
                $documentDate ??= $entryDate;
                $entryDate = $entryDate < $period['starts_on'] ? $period['starts_on'] : $period['ends_on'];
                $moved[] = $number ?: $groupKey;
            }
            // Pohoda veze v řádku deníku JEN volný text (`act:text`), který je u celé
            // řady dokladů shodný („Fakturujeme Vám za …"). Do popisu proto jde i
            // agenda a číslo dokladu, ať se zápisy v deníku dají rozlišit; protistranu
            // doplní {@see DocumentLinker} po navázání dokladů (source_id) přes
            // {@see \MyInvoice\Service\Accounting\JournalDescriptionRebuilder}.
            $description = $isOpening
                ? 'Počáteční stavy ' . $year . ' (převzato z Pohody)'
                : JournalDescriptionBuilder::composeParts([
                    trim(PohodaJournal::shortLabel(PohodaJournal::source($first)) . ' ' . $number),
                    PohodaXml::text($first, 'text'),
                ]);
            if ($description === '') {
                $description = 'Účetní zápis z Pohody';
            }

            $entryId = $this->journal->insert([
                'supplier_id' => $ctx->supplierId,
                'period_id' => ($entryPeriod ?? $period)['id'],
                'entry_date' => $entryDate,
                'document_date' => $documentDate,
                'document_no' => $isOpening ? null : (mb_substr($number, 0, 50) ?: null),
                'description' => mb_substr($description, 0, 255),
                'source_type' => $isOpening ? 'opening' : PohodaJournal::sourceType(PohodaJournal::source($first)),
                'source_id' => $isOpening ? $period['id'] : null,
                'posted_at' => $now,
                'posted_by' => $ctx->userOrNull(),
            ], $lines);
            $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);

            $stats['entries']++;
            $stats['lines'] += count($lines);
            foreach ($lines as $l) {
                $stats[$l['side']] += (float) $l['amount'];
            }
        }
        $ctx->report(self::STEP_JOURNAL, $total, $total);
        $this->storePairingSymbols($ctx, $groups);

        $stats['debit'] = round($stats['debit'], 2);
        $stats['credit'] = round($stats['credit'], 2);
        $stats['moved_entries'] = count($moved);
        foreach (['entries', 'existing', 'lines', 'skipped_rows'] as $k) {
            $p->setCount(self::STEP_JOURNAL, $k, $stats[$k]);
        }
        if ($stats['relocated'] > 0) {
            $p->setCount(self::STEP_JOURNAL, 'relocated', $stats['relocated']);
        }
        if ($stats['later_year_skipped'] > 0) {
            $p->setCount(self::STEP_JOURNAL, 'later_year_skipped', $stats['later_year_skipped']);
            $p->info(self::STEP_JOURNAL, 'later_year_skipped', sprintf(
                '%d zápisů s datem v nevybraném roce %s se nepřevedlo. Opakovaný převod s tímto rokem je doplní.',
                $stats['later_year_skipped'], implode(', ', $ctx->skippedYearList()),
            ), ['entries' => $stats['later_year_skipped'], 'years' => $ctx->skippedYearList()]);
        }
        foreach ($ctx->periods as $periodYear => $target) {
            if ($periodYear === $year) {
                continue;
            }
            $p->info(self::STEP_JOURNAL, 'later_period', sprintf(
                'Agenda %d obsahuje doklady roku %d: %d zápisů je v účetním období %d podle skutečného data%s. Rok %d zůstává neuzavřený - uzávěrku a převod zůstatků do roku %d provede účetní v MyÚčtu.',
                $year, $periodYear, $later[$periodYear] ?? 0, $periodYear,
                $stats['relocated'] > 0 ? " (z toho {$stats['relocated']} dříve převzatých zápisů přesunuto z " . self::day($period['ends_on']) . ')' : '',
                $year, $periodYear,
            ), ['year' => $periodYear, 'period_id' => $target['id'], 'entries' => $later[$periodYear] ?? 0]);
        }
        if ($stats['swapped_rows'] > 0) {
            $p->info(self::STEP_JOURNAL, 'negative_amounts', "{$stats['swapped_rows']} řádků se zápornou částkou přeneseno s prohozenými stranami (účetně totéž).");
        }
        if ($moved !== []) {
            $p->warn(self::STEP_JOURNAL, 'entry_date_outside_year', sprintf(
                '%d dokladů s datem mimo rok %d zaúčtováno k hranici období, původní datum zůstává jako datum dokladu: %s.',
                count($moved), $year, implode(', ', array_slice($moved, 0, 10)) . (count($moved) > 10 ? ', …' : '')
            ), ['documents' => array_slice($moved, 0, 200)]);
        }
        if (!ReconciliationTolerance::sameCent($stats['debit'], $stats['credit'])) {
            $p->error(self::STEP_JOURNAL, 'journal_unbalanced', "Rok {$year}: Σ MD ≠ Σ D.");
        }
        $p->set('journal', [$stats]);
        $p->finish(self::STEP_JOURNAL);
    }

    /**
     * Párovací symboly řádků deníku, i u zápisů z dřívějšího převodu. Čísluje řádky stejně
     * jako zápis, zápis se tím nemění. Export bez symbolů (XML) nezapisuje nic.
     *
     * @param array<string,list<array<string,mixed>>> $groups
     */
    private function storePairingSymbols(PohodaContext $ctx, array $groups): void
    {
        $entries = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        $year = $ctx->year();
        $update = $this->db->pdo()->prepare('INSERT INTO journal_line_pairing (supplier_id, entry_id, line_no, pairing_symbol) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE pairing_symbol = VALUES(pairing_symbol)');
        foreach ($groups as $groupKey => $rows) {
            $entryId = $entries[$year . '|' . $groupKey] ?? $entries[($year - 1) . '|' . $groupKey] ?? null;
            if ($entryId === null) {
                continue;
            }
            $lineNo = 0;
            foreach ($rows as $item) {
                $effect = PohodaJournal::effect($item);
                if ($effect === null) {
                    continue;
                }
                foreach (['debit', 'credit'] as $side) {
                    $lineNo++;
                    $symbol = PohodaJournal::pairingSymbol($item, $effect[$side]);
                    if ($symbol !== null) {
                        $update->execute([$ctx->supplierId, (int) $entryId, $lineNo, $symbol]);
                    }
                }
            }
        }
    }

    /**
     * Zápisy období, které nevznikly převodem (ani uzávěrkou nad ním) - do rozjeté
     * účetní evidence se deník z Pohody nepřimíchává. Převodem vznikly i zápisy úhrad,
     * které převod odvodil u pohybů bez zápisu v deníku POHODY, a jejich storna.
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND e.source_type NOT IN ('closing', 'fx_revaluation')
                AND NOT EXISTS (
                    SELECT 1 FROM pohoda_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind IN (?, ?) AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, $periodId, PohodaImportRepository::KIND_JOURNAL_ENTRY, PohodaImportRepository::KIND_DERIVED_ENTRY]);
        return (int) $stmt->fetchColumn();
    }

    /** Datum nejstaršího zápisu firmy, který nevznikl převodem (ani uzávěrkou nad ním). */
    public function firstForeignEntryDate(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MIN(e.entry_date)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.source_type NOT IN ('closing', 'fx_revaluation')
                AND NOT EXISTS (
                    SELECT 1 FROM pohoda_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind IN (?, ?) AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY, PohodaImportRepository::KIND_DERIVED_ENTRY]);
        $date = $stmt->fetchColumn();
        return is_string($date) ? substr($date, 0, 10) : null;
    }

    /**
     * Roky po roce agendy, do kterých padají zápisy deníku (agenda POHODY vede doklady
     * i po 31. 12.) - převod je dá do jejich vlastního účetního období.
     *
     * Kalendářní roky pro průvodce, který firmu nezná; samotný převod je počítá podle
     * kalendáře firmy ({@see PohodaAgendaYear::laterYears()}).
     *
     * @return list<int>
     */
    public static function laterYears(PohodaExport $export): array
    {
        $years = [];
        foreach ($export->records('journal', 'accountingItem') as $item) {
            $year = PohodaJournal::laterYear($item, $export->year);
            if ($year !== null) {
                $years[$year] = true;
            }
        }
        ksort($years);
        return array_keys($years);
    }

    /**
     * Otevřené období pro zápis s datem po konci roku agendy. `null` = zápis zůstane
     * v období agendy k jeho poslednímu dni (období je uzavřené nebo ho nejde založit
     * bez překryvu s řadou období firmy).
     *
     * Období se zakládá stejně jako období agendy ({@see ensurePeriod()}, proč ne
     * AccountingPeriodProvisioner vysvětluje {@see MigrationPeriods}).
     *
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool,year:int}|null
     */
    private function laterPeriod(PohodaContext $ctx, string $date): ?array
    {
        $year = $ctx->calendar->fiscalYearOfDate($date);
        if (!array_key_exists($year, $ctx->periods)) {
            $found = $this->periods->findForDate($ctx->supplierId, $date);
            [$starts, $ends] = MigrationPeriods::bounds($ctx->calendar, $year);
            $foreignShape = $found !== null
                ? (int) $found['fiscal_year'] !== $year
                : $this->periods->overlapping($ctx->supplierId, $starts, $ends) !== null;
            $period = $foreignShape ? null : $this->ensurePeriod($ctx, $year);
            if ($period !== null && $period['locked']) {
                $ctx->protocol->warn(self::STEP_JOURNAL, 'later_period_closed', "Účetní období {$year} je v MyÚčtu uzavřené, zápisy roku {$year} z agendy zůstávají k " . self::day($ctx->periodEnd()) . '.', ['year' => $year]);
                $period = null;
            }
            $ctx->periods[$year] = $period;
        }
        $period = $ctx->periods[$year];
        return $period === null ? null : $period + ['year' => $year];
    }

    /**
     * Zápis, který dřívější převod posunul k 31. 12. roku agendy, se přesune do období
     * podle skutečného data. Jen v otevřených obdobích a mimo uzamčené datum (podané DPH).
     *
     * @param array{id:int,ends_on:string,locked:bool} $from
     * @param array{id:int,locked:bool} $to
     */
    private function relocate(PohodaContext $ctx, int $entryId, array $from, array $to, string $date): bool
    {
        if ($from['locked'] || $to['locked']) {
            return false;
        }
        $pdo = $this->db->pdo();
        $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $lock->execute([$ctx->supplierId]);
        $lockedUntil = $lock->fetchColumn();
        if (is_string($lockedUntil) && $lockedUntil >= $from['ends_on']) {
            return false;
        }
        $stmt = $pdo->prepare(
            'UPDATE journal_entries SET period_id = ?, entry_date = ?, document_date = COALESCE(document_date, ?)
              WHERE id = ? AND supplier_id = ? AND period_id = ? AND entry_date = ? AND reversed_by IS NULL'
        );
        $stmt->execute([$to['id'], $date, $date, $entryId, $ctx->supplierId, $from['id'], $from['ends_on']]);
        return $stmt->rowCount() > 0;
    }

    /** `2026-12-31` → `31. 12. 2026` */
    private static function day(string $date): string
    {
        return sprintf('%d. %d. %s', (int) substr($date, 8, 2), (int) substr($date, 5, 2), substr($date, 0, 4));
    }

    /**
     * Období firmy daného labelu v kalendáři účetního roku firmy ({@see PohodaAgendaYear}).
     *
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}
     */
    private function ensurePeriod(PohodaContext $ctx, int $year): array
    {
        [$starts, $ends] = MigrationPeriods::bounds($ctx->calendar, $year);
        return (new MigrationPeriods($this->periods))->ensure(
            $ctx->supplierId, $year, $starts, $ends, $ctx->protocol, self::STEP_JOURNAL,
            fn (int $id) => $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PERIOD, (string) $year, $id, $ctx->runId),
        );
    }

    /**
     * @param array<string,string> $names
     * @return array<string,mixed>|null
     */
    private function createSynthetic(PohodaContext $ctx, string $synthetic, array $names): ?array
    {
        $created = (new ChartAccountCreator($this->accounts))
            ->createSynthetic($ctx->supplierId, $synthetic, $names[$synthetic . '000'] ?? ('Účet ' . $synthetic), $ctx->protocol, self::STEP_CHART);
        if ($created === null) {
            return null;
        }
        $ctx->accountIds[$synthetic] = $created['id'];
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_ACCOUNT, $synthetic, $created['id'], $ctx->runId);
        return $this->accounts->findById($ctx->supplierId, $created['id']);
    }
}
