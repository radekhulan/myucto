<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\JournalDescriptionBuilder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;

/**
 * Účetní období a účetní deník z Money.
 *
 * Deník je autoritativní kopie toho, co bylo v Money — zaúčtování se nepřepočítává,
 * doklady se k němu jen připojí ({@see DocumentLinker}). Rekonciliace na haléř stojí
 * právě na tom, že deník je přenesený beze změny.
 *
 * Počáteční stavy (zdroj `XP`) tvoří jeden otevírací zápis k prvnímu dni období se
 * zdrojem `opening` a klíčem = id období. Je to přesně klíč, pod kterým otevírací zápis
 * zakládá uzávěrka MyÚčta ({@see \MyInvoice\Service\Accounting\Closing\ClosingService::openNext()}),
 * takže otevření roku po uzávěrce předchozího převzaté počáteční stavy najde a nezdvojí.
 */
final class JournalImporter
{
    public const STEP = 'journal';

    /**
     * Rozvrh období zálohy podle voleb, které na něj mají vliv. Rozvrh čte deník všech let;
     * za jeden převod ho chce kontrola před převodem, nenavazující roky i převod deníku.
     * Záloha se během převodu nemění a klíčem je objekt zálohy, takže rozvrh zmizí s ní.
     *
     * @var \WeakMap<Ms3Backup,array<string,list<array<string,mixed>>>>
     */
    private \WeakMap $plans;

    public function __construct(
        private readonly Connection $db,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalEntryRepository $journal,
        private readonly MoneyS3ImportRepository $map,
    ) {
        $this->plans = new \WeakMap();
    }

    /**
     * Rozvrh období: rok a jeho hranice pro každý adresář ROK.nnn. Bez zápisu — používá
     * ho i kontrola před převodem.
     *
     * Adresář ROK je jeden účetní rok Money a stává se jedním účetním obdobím firmy podle
     * KALENDÁŘE FIRMY (`$calendar`, nastavení „Účetní rok začíná"): rok = label období
     * (rok jeho začátku), hranice = hranice období. Zda zdroj vede stejný tvar roku, ověří
     * kontrola před převodem z `source_dates` ({@see MigrationPeriods::assertSourceYear()}).
     * Volba „Převést od roku" (`fromYear`) je label období firmy; začátek prvního období
     * (`firstPeriodStart`) platí, když datum padne do prvního převáděného období.
     *
     * @return list<array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool,source_dates:list<string>}>
     */
    public function plan(Ms3Backup $backup, ImportOptions $options, ?FiscalCalendar $calendar = null): array
    {
        $calendar ??= FiscalCalendar::calendar();
        $key = ($options->fromYear ?? '') . '|' . ($options->firstPeriodStart ?? '') . '|' . $calendar->startMonth();
        $cached = $this->plans[$backup] ?? [];
        if (!isset($cached[$key])) {
            $cached[$key] = $this->buildPlan($backup, $options, $calendar);
            $this->plans[$backup] = $cached;
        }
        return $cached[$key];
    }

    /** @return list<array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool,source_dates:list<string>}> */
    private function buildPlan(Ms3Backup $backup, ImportOptions $options, FiscalCalendar $calendar): array
    {
        $plan = [];
        foreach ($backup->yearDirs() as $dir) {
            $table = $backup->table('UcDenik', $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            $summary = Ms3Journal::summarize($table->rows(Ms3Journal::FIELDS), $calendar);
            $year = $summary['fiscal_year'];
            if ($year === null) {
                continue;
            }
            [$startsOn, $endsOn] = MigrationPeriods::bounds($calendar, $year);
            $plan[] = [
                'dir' => basename($dir),
                'year' => $year,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'has_opening' => $summary['opening_rows'] > 0,
                'source_dates' => $summary['source_dates'],
                'first_entry' => $summary['first_entry'],
            ];
        }
        usort($plan, static fn (array $a, array $b): int => $a['year'] <=> $b['year']);
        if ($options->fromYear !== null) {
            $plan = array_values(array_filter($plan, static fn (array $p): bool => $p['year'] >= $options->fromYear));
        }

        // První účetní období firmy založené během roku začíná dnem vzniku, ne prvním dnem
        // účetního roku — jinak nesedí zdaňovací období proti podanému přiznání. Money den
        // vzniku v záloze nedrží: platí výslovně zadané datum, jinak první zápis deníku, ale
        // JEN když rok nemá počáteční stavy (firma s PS existovala už dřív a její období
        // začíná prvním dnem účetního roku).
        if ($plan !== []) {
            $first = &$plan[0];
            if ($options->firstPeriodStart !== null) {
                if ($calendar->fiscalYearOfDate($options->firstPeriodStart) === $first['year']) {
                    $first['starts_on'] = $options->firstPeriodStart;
                }
            } elseif (!$first['has_opening'] && $first['first_entry'] !== null) {
                $first['starts_on'] = $first['first_entry'];
            }
            unset($first);
        }
        return array_map(static function (array $p): array {
            unset($p['first_entry']);
            return $p;
        }, $plan);
    }

    /** Kalendář účetního roku firmy ({@see AccountingPeriodRepository::fiscalCalendar()}). */
    public function companyCalendar(int $supplierId): FiscalCalendar
    {
        return $this->periods->fiscalCalendar($supplierId);
    }

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        $plan = $this->plan($ctx->backup, $ctx->options, $ctx->calendar);
        if ($plan !== [] && $plan[0]['starts_on'] !== $ctx->calendar->periodStart($plan[0]['year'])) {
            $p->info(self::STEP, 'first_period_start', 'První účetní období začíná ' . $plan[0]['starts_on']
                . ($ctx->options->firstPeriodStart === null ? ' (podle prvního zápisu deníku — ověřte den vzniku firmy).' : '.'));
        }

        $years = [];
        foreach ($plan as $item) {
            $ctx->dirYears[$item['dir']] = $item['year'];
            if ($ctx->options->relinkOnly && $this->periods->findByYear($ctx->supplierId, $item['year']) === null && $ctx->skipsNew(self::STEP)) {
                continue; // opravný převod období nezakládá
            }
            $period = $this->ensurePeriod($ctx, $item);
            $ctx->periods[$item['year']] = $period;
            if ($period['locked']) {
                $p->info(self::STEP, 'year_locked', "Rok {$item['year']} je v MyÚčtu už uzavřený, deník se do něj znovu nenahrává.", ['year' => $item['year']]);
                continue;
            }
            $years[] = $this->importYear($ctx, $item, $period);
        }
        $p->set('journal', $years);
        $p->finish(self::STEP);
    }

    /**
     * @param array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool} $item
     * @return array{id:int,starts_on:string,ends_on:string,status:string,locked:bool}
     */
    private function ensurePeriod(ImportContext $ctx, array $item): array
    {
        $year = $item['year'];
        $remember = fn (int $id) => $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $year, $id, $ctx->runId);
        // Období založené dřív (ručně nebo jiným převodem) se zapíše do mapy, pokud v ní chybí.
        $adopt = function (int $id) use ($ctx, $year, $remember): void {
            if ($this->map->get($ctx->supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $year) === null) {
                $remember($id);
            }
        };
        return (new MigrationPeriods($this->periods))
            ->ensure($ctx->supplierId, $year, $item['starts_on'], $item['ends_on'], $ctx->protocol, self::STEP, $remember, $adopt, true);
    }

    /**
     * @param array{dir:string,year:int,starts_on:string,ends_on:string,has_opening:bool} $item
     * @param array{id:int,starts_on:string,ends_on:string,status:string,locked:bool} $period
     * @return array<string,int|float|string>
     */
    private function importYear(ImportContext $ctx, array $item, array $period): array
    {
        $p = $ctx->protocol;
        $year = $item['year'];
        $table = $ctx->backup->table('UcDenik', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $item['dir']);

        [$groups, $closingRows] = self::groupRows($table !== null ? $table->rows([...Ms3Journal::FIELDS, ...Ms3Journal::CURRENCY_FIELDS]) : []);
        $fxAccounts = $this->foreignAccounts($ctx, $item['dir']);
        $fillForeign = $this->db->pdo()->prepare(
            'UPDATE journal_entry_lines SET currency_code = ?, fx_rate = ?, amount_foreign = ?
              WHERE supplier_id = ? AND entry_id = ? AND line_no = ? AND account_id = ? AND side = ? AND currency_code IS NULL'
        );
        $ctx->yearEndClosingRows[$year] = $closingRows;
        if ($closingRows > 0) {
            $p->info(self::STEP, 'year_end_closing_skipped', "Rok {$year}: uzávěrkové zápisy z Money ({$closingRows} řádků) se nepřebírají, rok uzavře průvodce uzávěrkou MyÚčta.", ['year' => $year]);
        }

        // Klíč zápisu v mapě je `rok|skupina` - stačí zápisy tohoto roku.
        $existing = $this->map->all($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|');
        $stats = [
            'year' => $year, 'entries' => 0, 'existing' => 0, 'lines' => 0, 'debit' => 0.0, 'credit' => 0.0,
            'skipped_rows' => 0, 'swapped_rows' => 0, 'deleted_rows' => $table?->skippedDeleted() ?? 0, 'moved' => [],
        ];
        $now = date('Y-m-d H:i:s');
        $done = 0;
        $total = count($groups);

        foreach ($groups as $groupKey => $groupRows) {
            $done++;
            if ($done % 200 === 0) {
                $ctx->report(self::STEP, $done, $total);
            }
            $key = $year . '|' . $groupKey;
            $isOpening = $groupKey === Ms3Journal::OPENING_SOURCE;
            $first = $groupRows[0];
            if (isset($existing[$key])) {
                $stats['existing']++;
                // Zápis z dřívějšího převodu (před převzetím valut): měnu dostanou jen řádky,
                // které ji ještě nemají; částky ani strany se nemění.
                if ($fxAccounts !== []) {
                    $filled = 0;
                    foreach ($this->groupLines($ctx, $groupRows, $groupKey, $fxAccounts, $isOpening, $period['starts_on'])[0] as $l) {
                        if (!isset($l['currency_code'])) {
                            continue;
                        }
                        $fillForeign->execute([$l['currency_code'], $l['fx_rate'], $l['amount_foreign'], $ctx->supplierId, $existing[$key], $l['line_no'], $l['account_id'], $l['side']]);
                        $filled += $fillForeign->rowCount();
                    }
                    if ($filled > 0) {
                        $p->count(self::STEP, 'foreign_filled', $filled);
                    }
                }
                continue;
            }
            if ($ctx->skipsNew(self::STEP)) {
                continue;
            }

            [$lines, $skipped, $swapped] = $this->groupLines($ctx, $groupRows, $groupKey, $fxAccounts, $isOpening, $period['starts_on']);
            $stats['skipped_rows'] += $skipped;
            $stats['swapped_rows'] += $swapped;
            if ($lines === []) {
                continue;
            }
            PostingService::assertBalanced($lines);

            $entryDate = $isOpening ? $period['starts_on'] : (string) ($first['Datum'] ?? '');
            $documentDate = $isOpening ? null : (((string) ($first['DatPlnDPH'] ?? '')) ?: null);
            if ($entryDate === '') {
                $p->difference(self::STEP, 'entry_outside_period', sprintf(
                    'Doklad %s nemá datum zápisu — nepřenesen.',
                    trim((string) ($first['Doklad'] ?? ''))
                ), ['year' => $year, 'document_no' => trim((string) ($first['Doklad'] ?? ''))]);
                continue;
            }
            // Money vede v knize roku i doklad s datem z vedlejšího roku (faktura z 31. 12.
            // zaúčtovaná až v novém roce) a do obratů toho roku ho počítá. MyÚčto zápis mimo
            // hranice období nepřijme, takže jde na první (poslední) den období a původní
            // datum zůstává jako datum dokladu — obraty roku tak sedí na sestavy z Money.
            if ($entryDate < $period['starts_on'] || $entryDate > $period['ends_on']) {
                $documentDate ??= $entryDate;
                $entryDate = $entryDate < $period['starts_on'] ? $period['starts_on'] : $period['ends_on'];
                $stats['moved'][] = trim((string) ($first['Doklad'] ?? '')) ?: $groupKey;
            }
            $docNo = $isOpening ? null : (mb_substr(trim((string) ($first['Doklad'] ?? '')), 0, 50) ?: null);
            // Money veze v řádku deníku jen `Popis`, který je u celé řady dokladů
            // shodný. Do popisu proto jde i agenda a číslo dokladu; protistranu
            // doplní {@see DocumentLinker} po navázání dokladů (source_id) přes
            // {@see \MyInvoice\Service\Accounting\JournalDescriptionRebuilder}.
            $description = $isOpening
                ? 'Počáteční stavy ' . $year . ' (převzato z Money S3)'
                : JournalDescriptionBuilder::composeParts([
                    trim(Ms3Journal::shortLabel((string) ($first['Zdroj'] ?? '')) . ' ' . ($docNo ?? '')),
                    (string) ($first['Popis'] ?? ''),
                ]);
            if ($description === '') {
                $description = 'Účetní zápis z Money S3';
            }

            $entryId = $this->journal->insert([
                'supplier_id' => $ctx->supplierId,
                'period_id' => $period['id'],
                'entry_date' => $entryDate,
                'document_date' => $documentDate,
                'document_no' => $docNo,
                'description' => mb_substr($description, 0, 255),
                'source_type' => $isOpening ? 'opening' : Ms3Journal::sourceType((string) ($first['Zdroj'] ?? '')),
                'source_id' => $isOpening ? $period['id'] : null,
                'posted_at' => $now,
                'posted_by' => $ctx->userId > 0 ? $ctx->userId : null,
            ], $lines);
            $this->map->put($ctx->supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $key, $entryId, $ctx->runId);

            $stats['entries']++;
            $stats['lines'] += count($lines);
            foreach ($lines as $l) {
                $stats[$l['side']] += (float) $l['amount'];
            }
        }
        $ctx->report(self::STEP, $total, $total);

        $stats['debit'] = round($stats['debit'], 2);
        $stats['credit'] = round($stats['credit'], 2);
        $p->count(self::STEP, 'entries', $stats['entries']);
        $p->count(self::STEP, 'existing', $stats['existing']);
        $p->count(self::STEP, 'lines', $stats['lines']);
        if ($stats['swapped_rows'] > 0) {
            $p->info(self::STEP, 'negative_amounts', "Rok {$year}: {$stats['swapped_rows']} řádků se zápornou částkou přeneseno s prohozenými stranami (účetně totéž).", ['year' => $year]);
        }
        $moved = $stats['moved'];
        unset($stats['moved']);
        $stats['moved_entries'] = count($moved);
        if ($moved !== []) {
            $p->warn(self::STEP, 'entry_date_outside_year', sprintf(
                'Rok %d: %d dokladů s datem mimo rok (Money je vede v knize roku %d) zaúčtováno k hranici období, původní datum zůstává jako datum dokladu: %s.',
                $year, count($moved), $year, implode(', ', array_slice($moved, 0, 10)) . (count($moved) > 10 ? ', …' : '')
            ), ['year' => $year, 'documents' => $moved]);
        }
        if (!ReconciliationTolerance::sameCent($stats['debit'], $stats['credit'])) {
            $p->error(self::STEP, 'journal_unbalanced', "Rok {$year}: Σ MD ≠ Σ D.", ['year' => $year]);
        }
        return $stats;
    }

    /**
     * Řádky zápisu z jedné skupiny deníku Money: každý řádek Money = řádek MD a řádek D
     * (číslování shodné s {@see lineDimensions()}). Řádek na účtu valutové pokladny nebo
     * valutového bankovního účtu nese měnu, kurz a částku ve valutě z řádku Money, když
     * ho Money v té měně vede ({@see rowForeign()}).
     *
     * @param list<array<string,mixed>> $groupRows
     * @param array<int,array{currency:string,code:string,opening:?array{foreign:float,rate:float}}> $fxAccounts
     * @return array{0:list<array<string,mixed>>,1:int,2:int} řádky, vynechané řádky Money, řádky s prohozenými stranami
     */
    private function groupLines(ImportContext $ctx, array $groupRows, string $groupKey, array $fxAccounts, bool $isOpening, string $periodStart): array
    {
        $first = $groupRows[0];
        $lines = [];
        $lineNo = 0;
        $skipped = 0;
        $swapped = 0;
        foreach ($groupRows as $r) {
            $effect = Ms3Journal::effect($r);
            if ($effect === null) {
                $skipped++;
                continue;
            }
            if ((float) ($r['Castka'] ?? 0) < 0) {
                $swapped++;
            }
            $debitId = $ctx->accountIds[AccountCode::fromMoney($effect['debit']) ?? ''] ?? null;
            $creditId = $ctx->accountIds[AccountCode::fromMoney($effect['credit']) ?? ''] ?? null;
            if ($debitId === null || $creditId === null) {
                throw new MoneyS3Exception('unknown_account', sprintf(
                    'Doklad %s účtuje na účet %s/%s, který v osnově chybí.',
                    trim((string) ($first['Doklad'] ?? '')) ?: $groupKey, $effect['debit'], $effect['credit']
                ));
            }
            $amount = number_format($effect['amount'], 2, '.', '');
            $cc = Ms3Journal::costCenter($r);
            $fx = $fxAccounts !== [] ? self::rowForeign($r) : null;
            $lines[] = self::withForeign(['account_id' => $debitId, 'side' => 'debit', 'amount' => $amount, 'cost_center' => $cc, 'line_no' => ++$lineNo], $fxAccounts, $fx);
            $lines[] = self::withForeign(['account_id' => $creditId, 'side' => 'credit', 'amount' => $amount, 'cost_center' => $cc, 'line_no' => ++$lineNo], $fxAccounts, $fx);
        }
        if ($isOpening && $lines !== [] && $fxAccounts !== []) {
            $lines = $this->openingForeign($ctx, $lines, $fxAccounts, $periodStart);
        }
        return [$lines, $skipped, $swapped];
    }

    /**
     * @param array<string,mixed> $line
     * @param array<int,array{currency:string}> $fxAccounts
     * @param array{currency:string,rate:?float,foreign:float}|null $fx
     * @return array<string,mixed>
     */
    private static function withForeign(array $line, array $fxAccounts, ?array $fx): array
    {
        if ($fx === null || ($fxAccounts[$line['account_id']]['currency'] ?? null) !== $fx['currency']) {
            return $line;
        }
        return $line + ['currency_code' => $fx['currency'], 'fx_rate' => $fx['rate'], 'amount_foreign' => number_format($fx['foreign'], 2, '.', '')];
    }

    /**
     * Valuta řádku deníku Money (`Mena`, `Kurz`, `CastkaCM`), nebo null u řádku v Kč.
     * Kurz je za jednotku měny: Money vede u některých měn (HUF) kurz za 100 jednotek,
     * proto se `Kurz` ověří proti poměru `Castka` / `CastkaCM` a při neshodě platí poměr.
     * Řádek s měnou a nulovou částkou ve valutě (kurzový rozdíl) nese měnu s nulou -
     * tak ho bere přecenění kurzových rozdílů ({@see \MyInvoice\Repository\ClosingRepository::ledgerFxBalances()}).
     *
     * @param array<string,mixed> $r
     * @return array{currency:string,rate:?float,foreign:float}|null
     */
    public static function rowForeign(array $r): ?array
    {
        $mena = MoneyS3Currency::code($r['Mena'] ?? null);
        if ($mena === 'CZK') {
            return null;
        }
        $czk = abs(round((float) ($r['Castka'] ?? 0), 2));
        $foreign = abs(round((float) ($r['CastkaCM'] ?? 0), 2));
        $kurz = (float) ($r['Kurz'] ?? 0) / max(1.0, (float) ($r['PocetJedn'] ?? 1));
        $rate = $kurz > 0 ? $kurz : null;
        if ($foreign > 0 && $czk > 0) {
            $implied = $czk / $foreign;
            $rate = $implied;
            foreach ([$kurz, $kurz / 100] as $candidate) {
                if ($candidate > 0 && abs($candidate - $implied) <= $implied * 0.01) {
                    $rate = $candidate;
                    break;
                }
            }
        }
        return ['currency' => $mena, 'rate' => $rate !== null ? round($rate, 6) : null, 'foreign' => $foreign];
    }

    /**
     * Počáteční stav valutového účtu v otevíracím zápisu. Měnu nese jen otevírací zápis
     * roku, před kterým účet v deníku MyÚčta valutové řádky nemá (první převáděný rok,
     * nebo rok po uzavřeném roce převedeném bez valut). Pozdější otevírací zápisy ji
     * nést nesmí: uzávěrka MyÚčta zůstatek uzavírá v Kč a valutová pozice účtu vede
     * dál z řádků předchozího roku - jinak by se počáteční stav započetl dvakrát.
     * Když řádek Money měnu nenese, doplní se počátečním stavem pokladny/účtu z Money
     * (`PocStav` ve valutě, kurz `PSKurz` za `PSMnozstvi`), pokud jeho přepočet sedí
     * na korunový počáteční stav v deníku.
     *
     * @param list<array<string,mixed>> $lines
     * @param array<int,array{currency:string,code:string,opening:?array{foreign:float,rate:float}}> $fxAccounts
     * @return list<array<string,mixed>>
     */
    private function openingForeign(ImportContext $ctx, array $lines, array $fxAccounts, string $periodStart): array
    {
        $earlier = $this->db->pdo()->prepare(
            'SELECT 1 FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.entry_id
              WHERE l.supplier_id = ? AND l.account_id = ? AND l.currency_code = ? AND e.entry_date < ? LIMIT 1'
        );
        foreach ($fxAccounts as $accountId => $fx) {
            $indexes = array_keys(array_filter($lines, static fn (array $l): bool => $l['account_id'] === $accountId));
            if ($indexes === []) {
                continue;
            }
            $earlier->execute([$ctx->supplierId, $accountId, $fx['currency'], $periodStart]);
            if ($earlier->fetchColumn() !== false) {
                foreach ($indexes as $i) {
                    unset($lines[$i]['currency_code'], $lines[$i]['fx_rate'], $lines[$i]['amount_foreign']);
                }
                continue;
            }
            $untagged = array_values(array_filter($indexes, static fn (int $i): bool => !isset($lines[$i]['currency_code'])));
            if ($untagged === []) {
                continue;
            }
            $czk = (float) $lines[$untagged[0]]['amount'];
            // Money u bankovního účtu `PocStav` často nevede (nula, záporný součet let);
            // spolehlivý je zůstatek převedeného výpisu k začátku roku, ověřený kurzem na Kč.
            $statement = count($untagged) === 1 && ($fx['bank'] ?? false) && ($fx['rate'] ?? 0) > 0
                ? $this->statementOpening($ctx->supplierId, $fx['currency'], $periodStart)
                : null;
            if ($statement !== null && abs(round($statement * $fx['rate'], 2) - $czk) <= 1.0
                && ($fx['opening'] === null || abs(round($fx['opening']['foreign'] * $fx['opening']['rate'], 2) - $czk) > 1.0)) {
                $fx['opening'] = ['foreign' => $statement, 'rate' => $fx['rate']];
            }
            if ($fx['opening'] === null) {
                continue;
            }
            $expected = round($fx['opening']['foreign'] * $fx['opening']['rate'], 2);
            if (count($untagged) === 1 && abs($expected - $czk) <= 1.0) {
                $lines[$untagged[0]] += [
                    'currency_code' => $fx['currency'],
                    'fx_rate' => round($fx['opening']['rate'], 6),
                    'amount_foreign' => number_format($fx['opening']['foreign'], 2, '.', ''),
                ];
                continue;
            }
            $ctx->protocol->warn(self::STEP, 'foreign_opening_mismatch', sprintf(
                'Počáteční stav účtu %s v %s: počáteční stav z Money (%s %s) nesedí na deník (%s Kč), valuta se do otevíracího zápisu nedoplnila. Přecenění účtu zkontrolujte ručně.',
                $fx['code'], $fx['currency'], number_format($fx['opening']['foreign'], 2, ',', ' '), $fx['currency'], number_format($czk, 2, ',', ' ')
            ), ['account' => $fx['code'], 'period_start' => $periodStart]);
        }
        return $lines;
    }

    /**
     * Zůstatek převedeného výpisu jediného bankovního účtu firmy v dané měně na začátku
     * dne `$periodStart` (počáteční zůstatek prvního výpisu od toho dne). Víc účtů v téže
     * měně nejde od sebe rozeznat - null.
     */
    private function statementOpening(int $supplierId, string $currency, string $periodStart): ?float
    {
        $pdo = $this->db->pdo();
        $accounts = $pdo->prepare('SELECT COUNT(DISTINCT account_number, bank_code) FROM bank_statements WHERE supplier_id = ? AND currency = ?');
        $accounts->execute([$supplierId, $currency]);
        if ((int) $accounts->fetchColumn() !== 1) {
            return null;
        }
        $first = $pdo->prepare(
            'SELECT prev_balance FROM bank_statements WHERE supplier_id = ? AND currency = ? AND statement_date >= ?
              ORDER BY statement_date, id LIMIT 1'
        );
        $first->execute([$supplierId, $currency, $periodStart]);
        $balance = $first->fetchColumn();
        return $balance === false || $balance === null ? null : abs(round((float) $balance, 2));
    }

    /**
     * Účty valutových pokladen a valutových bankovních účtů Money (`SzUcPokl.Mena`,
     * primární účet `PrimUcet`) s počátečním stavem roku ve valutě. Měna z roku adresáře,
     * jinak z posledního roku, ve kterém pokladna/účet je.
     *
     * @return array<int,array{currency:string,code:string,opening:?array{foreign:float,rate:float},rate:float,bank:bool}> chart_of_accounts.id => valuta
     */
    private function foreignAccounts(ImportContext $ctx, string $dir): array
    {
        $byAccount = [];
        $collect = static function (iterable $rows, bool $ownYear) use (&$byAccount, $ctx): void {
            foreach ($rows as $r) {
                $code = AccountCode::fromMoney((string) ($r['PrimUcet'] ?? ''));
                $mena = MoneyS3Currency::code($r['Mena'] ?? null);
                $accountId = $code !== null ? ($ctx->accountIds[$code] ?? null) : null;
                if ($accountId === null || !in_array(strtoupper(trim((string) ($r['UcPokl'] ?? ''))), ['P', 'U'], true)) {
                    continue;
                }
                if ($mena === 'CZK') {
                    if ($ownYear) {
                        $byAccount[$accountId] = null;
                    }
                    continue;
                }
                $bank = strtoupper(trim((string) ($r['UcPokl'] ?? ''))) === 'U';
                if ($ownYear) {
                    $rate = (float) ($r['PSKurz'] ?? 0) / max(1.0, (float) ($r['PSMnozstvi'] ?? 1));
                    $foreign = abs(round((float) ($r['PocStav'] ?? 0), 2));
                    $byAccount[$accountId] = ['currency' => $mena, 'code' => $code, 'opening' => $rate > 0 && $foreign > 0 ? ['foreign' => $foreign, 'rate' => $rate] : null,
                        'rate' => $rate, 'bank' => $bank];
                } elseif (!array_key_exists($accountId, $byAccount)) {
                    $byAccount[$accountId] = ['currency' => $mena, 'code' => $code, 'opening' => null, 'rate' => 0.0, 'bank' => $bank];
                }
            }
        };
        $own = $ctx->backup->table('SzUcPokl', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $dir);
        $collect($own !== null && $own->hasData() ? $own->rows() : [], true);
        $all = iterator_to_array($ctx->backup->rowsAcrossYears('SzUcPokl'), false);
        $collect(array_reverse($all), false);
        return array_filter($byAccount, static fn (?array $a): bool => $a !== null);
    }

    /**
     * Dimenze z Money po řádcích převedeného deníku, po letech (rok => dimenze roku):
     * klíč zápisu v mapě převodu (`rok|skupina`) => číslo řádku => středisko a zakázka.
     * Řádky se číslují stejně jako v {@see importYear()} (každý řádek Money = řádek MD
     * a řádek D), takže jde dimenze doplnit i do zápisů z dřívějšího převodu. V paměti
     * je vždy jen deník jednoho roku.
     *
     * Vrací dvojice [předpona klíčů v mapě, dimenze]. Záloha, ve které má týž účetní rok
     * víc adresářů, vrátí jediný celek bez předpony: pozdější adresář přepíše klíče
     * dřívějšího na jejich místě.
     *
     * @return \Generator<int,array{0:?string,1:array<string,array<int,array{stred:?string,zakazka:?string}>>}>
     */
    public function lineDimensions(ImportContext $ctx): \Generator
    {
        $merged = count(array_unique($ctx->dirYears)) !== count($ctx->dirYears);
        $out = [];
        foreach ($ctx->dirYears as $dir => $year) {
            $table = $ctx->backup->table('UcDenik', $ctx->backup->dir() . DIRECTORY_SEPARATOR . $dir);
            if ($table === null || !$table->hasData()) {
                continue;
            }
            [$groups] = self::groupRows($table->rows(['Zdroj', 'Doklad', 'Datum', 'Castka', 'UcMD', 'UcD', 'Stred', 'Zakazka']));
            foreach ($groups as $groupKey => $groupRows) {
                $lineNo = 0;
                $lines = [];
                foreach ($groupRows as $r) {
                    if (Ms3Journal::effect($r) === null) {
                        continue;
                    }
                    $dims = ['stred' => Ms3Journal::costCenter($r), 'zakazka' => Ms3Journal::jobCode($r)];
                    $lines[++$lineNo] = $dims;
                    $lines[++$lineNo] = $dims;
                }
                if ($lines !== []) {
                    $out[$year . '|' . $groupKey] = $lines;
                }
            }
            unset($groups);
            if (!$merged) {
                yield [$year . '|', $out];
                $out = [];
            }
        }
        if ($merged) {
            yield [null, $out];
        }
    }

    /**
     * Řádky deníku roku po účetních zápisech, bez uzávěrkových zápisů Money.
     *
     * @param iterable<array<string,mixed>> $rows
     * @return array{0:array<string,list<array<string,mixed>>>,1:int} skupiny a počet vynechaných uzávěrkových řádků
     */
    private static function groupRows(iterable $rows): array
    {
        $groups = [];
        $closingRows = 0;
        foreach ($rows as $r) {
            if (Ms3Journal::isYearEndClosing($r)) {
                $closingRows++;
                continue;
            }
            $groups[Ms3Journal::groupKey($r)][] = $r;
        }
        return [$groups, $closingRows];
    }

    /**
     * První rok, od kterého v Money roky navazují (rok za posledním přerušením řetězu),
     * nebo null, když navazují všechny. Převod od něj půjde celý uzavřít; doporučuje ho
     * kontrola před převodem a volí ho dávkový převod s „od roku automaticky".
     *
     * @param list<array{year:int,next:int}> $breaks výsledek {@see chainBreaks()}
     */
    public static function suggestedFromYear(array $breaks): ?int
    {
        return $breaks !== [] ? (int) $breaks[count($breaks) - 1]['next'] : null;
    }

    /**
     * Místa, kde v Money nenavazují roky: konečné stavy roku (počáteční stavy + deník bez
     * uzávěrky XZ) nesedí na počáteční stavy dalšího roku. Money to dovolí (počáteční stavy
     * jdou přepsat ručně, starý rok může být v agendě jen zčásti), uzávěrka MyÚčta ne —
     * rok s rozdílem nepůjde uzavřít a s ním ani žádný pozdější. Porovnávají se rozvahové
     * účty tříd 0–4 bez 43x (výsledek hospodaření přechází do dalšího roku až uzávěrkou)
     * a bez 70x.
     *
     * @return list<array{year:int,next:int,next_has_opening:bool,accounts:array<string,float>}>
     */
    public function chainBreaks(Ms3Backup $backup, ImportOptions $options, ?FiscalCalendar $calendar = null): array
    {
        $years = [];
        foreach ($this->plan($backup, $options, $calendar) as $item) {
            $table = $backup->table('UcDenik', $backup->dir() . DIRECTORY_SEPARATOR . $item['dir']);
            $opening = [];
            $closing = [];
            foreach ($table !== null ? $table->rows(['Zdroj', 'Castka', 'UcMD', 'UcD']) : [] as $r) {
                if (Ms3Journal::isYearEndClosing($r)) {
                    continue;
                }
                $effect = Ms3Journal::effect($r);
                if ($effect === null) {
                    continue;
                }
                foreach ([[$effect['debit'], 1], [$effect['credit'], -1]] as [$code, $sign]) {
                    $code = (string) $code;
                    if ($code === '' || $code[0] > '4' || str_starts_with($code, '43')) {
                        continue;
                    }
                    if (Ms3Journal::isOpening($r)) {
                        $opening[$code] = ($opening[$code] ?? 0.0) + $sign * $effect['amount'];
                    }
                    $closing[$code] = ($closing[$code] ?? 0.0) + $sign * $effect['amount'];
                }
            }
            $years[] = ['year' => $item['year'], 'opening' => $opening, 'closing' => $closing, 'has_opening' => $item['has_opening']];
        }

        $breaks = [];
        for ($i = 0, $n = count($years) - 1; $i < $n; $i++) {
            [$cur, $next] = [$years[$i], $years[$i + 1]];
            $diffs = [];
            foreach (array_unique(array_merge(array_keys($cur['closing']), array_keys($next['opening']))) as $code) {
                $diff = round(($cur['closing'][$code] ?? 0.0) - ($next['opening'][$code] ?? 0.0), 2);
                if (!ReconciliationTolerance::isZeroCent($diff)) {
                    $diffs[(string) $code] = $diff;
                }
            }
            if ($diffs !== []) {
                uasort($diffs, static fn (float $a, float $b): int => abs($b) <=> abs($a));
                $breaks[] = ['year' => $cur['year'], 'next' => $next['year'], 'next_has_opening' => $next['has_opening'], 'accounts' => $diffs];
            }
        }
        return $breaks;
    }

    /**
     * Zápisy období, které nevznikly převodem (ani uzávěrkou nad ním) — do rozjeté
     * účetní evidence se deník z Money přimíchat nesmí.
     */
    public function foreignEntryCount(int $supplierId, int $periodId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*)
               FROM journal_entries e
              WHERE e.supplier_id = ? AND e.period_id = ?
                AND e.source_type NOT IN ('closing', 'fx_revaluation')
                AND NOT EXISTS (
                    SELECT 1 FROM money_s3_import_map m
                     WHERE m.supplier_id = e.supplier_id AND m.kind = 'journal_entry' AND m.target_id = e.id
                )"
        );
        $stmt->execute([$supplierId, $periodId]);
        return (int) $stmt->fetchColumn();
    }
}
