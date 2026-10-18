<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\RecurringSeriesTakeover;
use PDO;

/**
 * Převod agendy Money S3 do firmy v MyÚčtu — orchestrátor kroků průvodce.
 *
 * Pořadí: osnova → období a deník → režim účetní jednotky → adresář a předkontace →
 * faktury → pokladna a banka → vazby dokladů na deník a úhrady → pozastavené šablony
 * pravidelných faktur z historie → uzávěrka historických
 * let → mzdy (návrh kontací, kontrolní úhrny, zapnutí modulu) → rekonciliace. Automatika účtování je po celou dobu vypnutá
 * ({@see AccountingUnitSwitch}).
 *
 * **Zkouška nanečisto** běží stejným kódem v jedné transakci, která se na konci vrátí —
 * protokol ukáže přesně to, co by udělal ostrý převod, a v databázi nic nezůstane.
 *
 * **Ostrý převod** zapisuje po krocích a každý krok je idempotentní
 * ({@see MoneyS3ImportRepository}): opakovaný běh téže zálohy nic nezdvojí a běh
 * přerušený chybou pokračuje tam, kde skončil.
 */
final class MoneyS3Importer
{
    public const STEP_PREFLIGHT = 'preflight';
    public const STEP_ACCOUNTING_MODE = 'accounting_mode';

    /** Kroky, bez kterých nemá smysl pokračovat — další na nich stojí. */
    private const CRITICAL_STEPS = [ChartImporter::STEP, JournalImporter::STEP, self::STEP_ACCOUNTING_MODE, TaxRecordsImporter::STEP_PLAN];

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3ImportRepository $map,
        private readonly AccountingPeriodRepository $periods,
        private readonly ChartImporter $chart,
        private readonly JournalImporter $journal,
        private readonly AccountingUnitSwitch $unit,
        private readonly CodebookImporter $codebooks,
        private readonly InvoiceImporter $invoices,
        private readonly CashBankImporter $cashBank,
        private readonly DocumentLinker $linker,
        private readonly DimensionImporter $dimensions,
        private readonly AssetImporter $assets,
        private readonly VatCoefficientSeeder $coefficients,
        private readonly HistoricalYearCloser $closer,
        private readonly MoneyS3Reconciler $reconciler,
        private readonly TableStatistics $statistics,
        private readonly PayrollImporter $payroll,
        private readonly RecurringSeriesTakeover $recurring,
        private readonly AccountingModeRepository $modes,
        private readonly TaxRecordsImporter $taxRecords,
        private readonly TaxRecordsReconciler $taxRecordsReconciler,
        private readonly VatFilingsTakeover $vatFilings,
        private readonly AccountingSupplierSettingsRepository $settings,
    ) {}

    /**
     * Klíče kroků v pořadí, v jakém běží. `$taxRecords` = převod agendy vedené v Money jako
     * daňová evidence ({@see taxEvidenceSteps()}).
     *
     * @return list<string>
     */
    public static function stepKeys(bool $taxRecords = false): array
    {
        if ($taxRecords) {
            return [
                TaxRecordsImporter::STEP_PLAN,
                CodebookImporter::STEP_PARTNERS,
                TaxRecordsImporter::STEP_VAT_STATUS,
                InvoiceImporter::STEP_PURCHASE,
                InvoiceImporter::STEP_ISSUED,
                CashBankImporter::STEP_CASH,
                CashBankImporter::STEP_BANK,
                TaxRecordsImporter::STEP_PAYMENTS,
                TaxRecordsImporter::STEP_CLASSIFICATION,
                TaxRecordsImporter::STEP_OTHER_ITEMS,
                RecurringSeriesTakeover::STEP,
                TaxRecordsReconciler::STEP,
                VatFilingsTakeover::STEP,
            ];
        }
        return [
            ChartImporter::STEP,
            JournalImporter::STEP,
            self::STEP_ACCOUNTING_MODE,
            CodebookImporter::STEP_PARTNERS,
            CodebookImporter::STEP_POSTING_RULES,
            InvoiceImporter::STEP_PURCHASE,
            InvoiceImporter::STEP_ISSUED,
            CashBankImporter::STEP_CASH,
            CashBankImporter::STEP_BANK,
            DocumentLinker::STEP_LINK,
            DimensionImporter::STEP,
            DocumentLinker::STEP_PAYMENTS,
            RecurringSeriesTakeover::STEP,
            AssetImporter::STEP,
            AssetImporter::STEP_SMALL,
            VatCoefficientSeeder::STEP,
            HistoricalYearCloser::STEP,
            PayrollImporter::STEP,
            MoneyS3Reconciler::STEP,
        ];
    }

    /**
     * Rozvrh období a nenavazující roky zálohy při výchozích volbách převodu - to, co
     * z deníku potřebuje kontrola před převodem v náhledu průvodce. Počítá ho job nahrání
     * do `meta.json`; náhled pak deníky (u velké agendy gigabajty) znovu nečte.
     *
     * Rozvrh se počítá v kalendáři účetního roku firmy (`$supplierId`, bez něj kalendářní
     * rok) a nese jeho počáteční měsíc: změní-li firma nastavení po nahrání, kontrola před
     * převodem náhled zahodí a deník přečte znovu.
     *
     * @return array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>,start_month:int}
     */
    public function journalPreview(Ms3Backup $backup, ?int $supplierId = null): array
    {
        $options = new ImportOptions();
        $calendar = $supplierId !== null ? $this->periods->fiscalCalendar($supplierId) : FiscalCalendar::calendar();
        return [
            'plan' => $this->journal->plan($backup, $options, $calendar),
            'chain_breaks' => $this->journal->chainBreaks($backup, $options, $calendar),
            'start_month' => $calendar->startMonth(),
        ];
    }

    /**
     * Náhled deníku z `meta.json` platí pro kalendář účetního roku firmy - po změně nastavení
     * „Účetní rok začíná" se musí přečíst znovu.
     */
    public function journalPreviewCurrent(mixed $preview, int $supplierId): bool
    {
        return self::journalPreviewComplete($preview) && $preview['start_month'] === $this->periods->fiscalCalendar($supplierId)->startMonth();
    }

    /**
     * Náhled agendy pro průvodce v účetních letech FIRMY: náhled nahrání počítá rok adresáře
     * ROK v kalendářním roce, převod ale adresář převádí jako účetní období firmy podle
     * rozvrhu deníku ({@see JournalImporter::plan()}). U firmy s hospodářským rokem proto rok
     * (`fiscal_year`) nahradí label období firmy a přidá jeho hranice (`period_start`,
     * `period_end`) - podle stejného roku se pak páruje i nahraná předvaha z Money a volba
     * „Převést od roku". Kalendářní firma dostane náhled beze změny.
     *
     * @param array<string,mixed> $agenda {@see AgendaInfo::toArray()}
     * @param array{plan:list<array<string,mixed>>,start_month:int} $journalPreview náhled deníku v kalendáři firmy ({@see journalPreview()})
     * @return array<string,mixed>
     */
    public static function companyAgenda(array $agenda, array $journalPreview): array
    {
        if ($journalPreview['start_month'] === 1) {
            return $agenda;
        }
        $plan = array_column($journalPreview['plan'], null, 'dir');
        foreach ((array) ($agenda['years'] ?? []) as $i => $year) {
            $item = $plan[(string) ($year['dir'] ?? '')] ?? null;
            if ($item === null) {
                continue;
            }
            $agenda['years'][$i]['fiscal_year'] = (int) $item['year'];
            $agenda['years'][$i]['period_start'] = (string) $item['starts_on'];
            $agenda['years'][$i]['period_end'] = (string) $item['ends_on'];
        }
        return $agenda;
    }

    /** Náhled deníku z `meta.json` je úplný (nahrání před jeho zavedením ho nemá). */
    public static function journalPreviewComplete(mixed $preview): bool
    {
        return is_array($preview) && is_array($preview['plan'] ?? null) && is_array($preview['chain_breaks'] ?? null)
            && is_int($preview['start_month'] ?? null)
            && array_all($preview['plan'], static fn (mixed $item): bool => is_array($item) && is_array($item['source_dates'] ?? null));
    }

    /**
     * Kontrola před převodem — nic nezapisuje. Chyba převod zastaví, upozornění ne.
     *
     * `$journalPreview` = rozvrh a nenavazující roky z `meta.json` ({@see journalPreview()});
     * platí jen pro výchozí volby převodu, s jinými se deník čte ze zálohy.
     *
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options, ?array $journalPreview = null): array
    {
        $calendar = $this->periods->fiscalCalendar($supplierId);
        if ($options->fromYear !== null || $options->firstPeriodStart !== null || !self::journalPreviewComplete($journalPreview)
            || $journalPreview['start_month'] !== $calendar->startMonth()) {
            $journalPreview = null;
        }
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };

        $stmt = $this->db->pdo()->prepare('SELECT id, ic, company_name, accounting_mode FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($supplier === false) {
            $add('error', 'supplier_missing', 'Cílová firma neexistuje.');
            return $out;
        }

        $supplierIco = CodebookImporter::ico((string) ($supplier['ic'] ?? ''));
        $agendaIco = CodebookImporter::ico($agenda->ico);
        if ($agendaIco === '') {
            $add('warning', 'agenda_ico_missing', 'Záloha neobsahuje IČO firmy — ověřte, že jde o agendu této firmy.');
        } elseif ($supplierIco === '') {
            $add('warning', 'supplier_ico_missing', "Firma v MyÚčtu nemá vyplněné IČO, záloha je agenda IČO {$agendaIco}.");
        } elseif ($supplierIco !== $agendaIco) {
            // Záloha cizí firmy by se jinak vmíchala do účetnictví téhle — tenantová
            // izolace na úrovni obsahu, ne jen oprávnění.
            $add('error', 'ico_mismatch', "Záloha je agenda IČO {$agendaIco}, firma v MyÚčtu má IČO {$supplierIco}.", ['agenda' => $agendaIco, 'supplier' => $supplierIco]);
        }
        if (($agendaIco === '' || $supplierIco === '') && !$options->isDryRun() && !$options->confirmedIco) {
            // Bez IČO na jedné ze stran nejde ověřit, že agenda patří téhle firmě. Ostrý
            // převod by cizí účetnictví vmíchal natrvalo — spustí se jen s potvrzením.
            $add('error', 'ico_unverified', 'Nelze ověřit, že záloha patří této firmě (chybí IČO). Ostrý převod vyžaduje výslovné potvrzení.');
        }

        foreach ($agenda->warnings as $w) {
            $add('warning', $w['code'], $w['message']);
        }

        // Převod nikdy nezapíše podvojné účetnictví pro rok, který Money vede jako daňovou
        // evidenci: agendu, která se do daňové evidence vrátila, zastaví.
        $conflict = $agenda->taxRecordsAfterDoubleEntry();
        if ($conflict !== null) {
            $add('error', 'tax_records_after_double_entry', AgendaInfo::textTaxRecordsAfterDoubleEntry($conflict), $conflict);
        }
        $taxRecords = $agenda->taxRecordsOnly();
        if ($taxRecords) {
            array_push($out, ...$this->taxRecordsPreflight($supplierId, $backup, $agenda, $options));
        }

        // Daňová evidence deník nemá: rozvrh roků je rok Money ({@see TaxRecordsImporter::plan()}),
        // roky a jejich kontrolu vypsal taxRecordsPreflight().
        $plan = $taxRecords ? [] : ($journalPreview['plan'] ?? $this->journal->plan($backup, $options, $calendar));
        if (!$taxRecords && $plan === [] && $options->fromYear !== null) {
            $add('error', 'from_year_empty', "Od roku {$options->fromYear} záloha nemá žádný účetní rok — není co převést.", ['from_year' => $options->fromYear]);
        } elseif (!$taxRecords && $plan === []) {
            $add('error', 'no_journal', 'Záloha neobsahuje účetní deník — není co převést.');
        }
        // meta.json ukládá celé částky bez desetinné části jako celá čísla.
        $breaks = $taxRecords ? [] : ($journalPreview !== null
            ? array_map(static fn (array $b): array => ['accounts' => array_map('floatval', (array) $b['accounts'])] + $b, $journalPreview['chain_breaks'])
            : $this->journal->chainBreaks($backup, $options, $calendar));
        foreach ($breaks as $b) {
            $code = (string) array_key_first($b['accounts']);
            $add('warning', 'opening_chain_break', sprintf(
                $b['next_has_opening']
                    ? 'Konečné stavy roku %d nesedí v Money na počáteční stavy roku %d (%d účtů, např. %s: %s Kč). Rok %1$d a všechny pozdější proto nepůjde uzavřít.'
                    : 'Rok %2$d nemá v Money počáteční stavy, konečné stavy roku %1$d na něj nenavazují (%3$d účtů, např. %4$s: %5$s Kč). Rok %1$d a všechny pozdější proto nepůjde uzavřít.',
                $b['year'], $b['next'], count($b['accounts']), $code, number_format($b['accounts'][$code], 2, ',', ' ')
            ), ['year' => $b['year'], 'next' => $b['next'], 'accounts' => array_slice($b['accounts'], 0, 20, true)]);
        }
        $suggested = JournalImporter::suggestedFromYear($breaks);
        if ($suggested !== null) {
            $add('info', 'suggested_from_year', "Roky od {$suggested} v Money navazují — převod od roku {$suggested} (volba „Převést od roku“) půjde celý uzavřít. Starší roky zůstanou v archivu Money.", ['from_year' => $suggested]);
        }
        foreach ($plan as $item) {
            // Adresář ROK = jeden účetní rok Money = jedno účetní období firmy. Tvar roku
            // určuje nastavení firmy „Účetní rok začíná"; rok zdroje, který by se rozpadl do
            // dvou období, převod zastaví s návrhem správného měsíce.
            try {
                MigrationPeriods::assertSourceYear($calendar, (array) $item['source_dates'], "{$item['year']} (adresář {$item['dir']})");
            } catch (FiscalYearMismatchException $e) {
                $add('error', 'fiscal_year_mismatch', $e->getMessage(), ['year' => $item['year'], 'suggested_start_month' => $e->suggestedStartMonth]);
            }
            $period = $this->periods->findByYear($supplierId, $item['year']);
            if ($period === null) {
                continue;
            }
            $mapped = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $item['year']) !== null;
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$item['year']} už obsahuje {$foreign} zápisů, které nevznikly převodem z Money. Deník z Money se do rozjetého účetnictví nepřimíchává.", ['year' => $item['year'], 'entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$item['year']} je v MyÚčtu uzavřené.", ['year' => $item['year']]);
            }
        }
        if ($plan !== [] && !$taxRecords && $conflict === null && ($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        return $out;
    }

    /**
     * Kontrola agendy vedené v Money jako daňová evidence ({@see TaxRecordsPlan}): co v ní
     * je, co by převod zastavilo, a že firma zůstane v daňové evidenci. Firma, která roky
     * agendy vede v MyÚčtu jako podvojné účetnictví, převod nedostane — převod režim firmy
     * nemění.
     *
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function taxRecordsPreflight(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options): array
    {
        $out = [];
        $check = TaxRecordsPlan::check($backup, $agenda, $options->fromYear);
        if ($check['years'] === [] && $options->fromYear !== null) {
            $out[] = ['level' => 'error', 'code' => 'from_year_empty', 'message' => "Od roku {$options->fromYear} záloha nemá žádný rok daňové evidence — není co převést.",
                'context' => ['from_year' => $options->fromYear]];
        }
        // Daňová evidence se vede po kalendářních letech (§ 7b ZDP).
        if ($this->periods->fiscalCalendar($supplierId)->startMonth() !== 1) {
            $out[] = ['level' => 'error', 'code' => 'tax_records_fiscal_year', 'message' => 'Daňová evidence se vede po kalendářních letech, firma má v MyÚčtu nastavený '
                . 'hospodářský rok (Firma → Nastavení, Daně a účetnictví, Účetní rok začíná). Nastavte leden a převod spusťte znovu.', 'context' => []];
        }
        array_push($out, ...$this->taxRecordsTargetChecks($supplierId, array_values(array_filter(array_map(
            static fn (array $y): ?int => $y['year'], $check['years'])))));
        foreach ($check['years'] as $y) {
            $links = $y['links'] === [] ? 'žádné' : implode(', ', array_map(static fn (string $t, int $n): string => "{$t} {$n}", array_keys($y['links']), $y['links']));
            $out[] = ['level' => 'info', 'code' => 'tax_records_year_plan', 'message' => sprintf(
                '%s: peněžní deník má %d pohybů (banka %d, pokladna %d, interní doklady %d), vazby na uhrazené doklady: %s.',
                $y['year'] !== null ? "Rok {$y['year']} (adresář {$y['dir']})" : "Rok v adresáři {$y['dir']}",
                $y['movements'], $y['bank'], $y['cash'], $y['internal'], $links,
            ), 'context' => $y];
        }
        foreach ($check['issues'] as $issue) {
            $out[] = $issue;
        }
        // Rozhoduje režim firmy v letech agendy (firma, která v MyÚčtu přešla na podvojné
        // účetnictví až později, historii daňové evidence převzít smí). Záloha bez roků
        // Money se porovná s dnešním režimem firmy.
        $years = [];
        foreach ($agenda->years as $y) {
            if (($y['accounting_mode'] ?? null) === 'tax_evidence' && ($y['money_year'] ?? null) !== null) {
                $years[] = (int) $y['money_year'];
            }
        }
        $doubleEntry = array_values(array_filter($years, fn (int $year): bool => $this->modes->forYear($supplierId, $year) === 'double_entry'));
        if ($doubleEntry !== [] || ($years === [] && $this->modes->forYear($supplierId, (int) date('Y')) === 'double_entry')) {
            sort($doubleEntry);
            $out[] = ['level' => 'error', 'code' => 'target_double_entry', 'message' => 'Agenda vede v Money daňovou evidenci, firma v MyÚčtu '
                . ($doubleEntry !== [] ? 'vede roky ' . implode(', ', $doubleEntry) . ' jako podvojné účetnictví' : 'vede podvojné účetnictví')
                . '. Převod režim firmy nemění, daňovou evidenci převede jen do firmy v daňové evidenci.', 'context' => ['years' => $doubleEntry]];
        } else {
            $out[] = ['level' => 'info', 'code' => 'tax_evidence_kept', 'message' => 'Firma zůstane v daňové evidenci, převod její režim nemění.', 'context' => []];
        }
        return $out;
    }

    /**
     * Cílová firma pro převod daňové evidence: převáděné roky nesmí obsahovat doklady, výpisy
     * ani pokladní doklady, které nevznikly převodem z Money (do rozjeté evidence se převod
     * nepřimíchává), a první převod nesmí zapisovat pod zámek dokladů firmy. Opakovaný převod
     * (doklady z mapy) projde.
     *
     * @param list<int> $years
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function taxRecordsTargetChecks(int $supplierId, array $years): array
    {
        $out = [];
        $pdo = $this->db->pdo();
        $foreign = [
            'invoices' => ["SELECT COUNT(*) FROM invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'invoice' AND m.target_id = d.id)", 'vydaných dokladů'],
            'purchase_invoices' => ["SELECT COUNT(*) FROM purchase_invoices d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'purchase_invoice' AND m.target_id = d.id)", 'přijatých dokladů'],
            'cash_documents' => ["SELECT COUNT(*) FROM cash_documents d WHERE d.supplier_id = ? AND d.issue_date BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = d.supplier_id AND m.kind = 'cash_document' AND m.target_id = d.id)", 'pokladních dokladů'],
            'bank_transactions' => ["SELECT COUNT(*) FROM bank_transactions d JOIN bank_statements s ON s.id = d.statement_id
                WHERE s.supplier_id = ? AND d.posted_at BETWEEN ? AND ?
                AND NOT EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = s.supplier_id AND m.kind = 'bank_transaction' AND m.target_id = d.id)", 'bankovních pohybů'],
        ];
        foreach ($years as $year) {
            $found = [];
            foreach ($foreign as [$sql, $label]) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$supplierId, sprintf('%04d-01-01', $year), sprintf('%04d-12-31 23:59:59', $year)]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $found[] = "{$count} {$label}";
                }
            }
            if ($found !== []) {
                $out[] = ['level' => 'error', 'code' => 'tax_records_year_not_empty', 'message' => "Rok {$year} už v MyÚčtu obsahuje " . implode(', ', $found)
                    . ', které nevznikly převodem z Money. Daňová evidence z Money se do rozjeté evidence nepřimíchává.', 'context' => ['year' => $year]];
            }
        }
        $lockedUntil = $this->settings->getLockedUntil($supplierId);
        $first = $years === [] ? null : min($years);
        if ($lockedUntil !== null && $first !== null && sprintf('%04d-01-01', $first) <= $lockedUntil
            && $this->map->all($supplierId, MoneyS3ImportRepository::KIND_INVOICE) === []
            && $this->map->all($supplierId, MoneyS3ImportRepository::KIND_BANK_TRANSACTION) === []) {
            $out[] = ['level' => 'error', 'code' => 'tax_records_locked', 'message' => 'Doklady firmy jsou v MyÚčtu uzamčené do ' . date('j. n. Y', (int) strtotime($lockedUntil))
                . ", převod by zapisoval do uzamčeného období (od roku {$first}). Zámek zrušte a převod spusťte znovu.", 'context' => ['locked_until' => $lockedUntil]];
        }
        return $out;
    }

    /**
     * `$agenda` a `$journalPreview` = náhled agendy a deníku, který už spočítal job nahrání
     * (`meta.json`); bez nich se čtou ze zálohy.
     *
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     */
    public function run(
        int $supplierId,
        int $userId,
        Ms3Backup $backup,
        ImportOptions $options,
        ?int $runId = null,
        ?callable $progress = null,
        ?callable $shouldCancel = null,
        ?AgendaInfo $agenda = null,
        ?array $journalPreview = null,
    ): ImportProtocol {
        $protocol = new ImportProtocol($options->mode, $options->acceptDifferences);
        $agenda ??= AgendaInfo::fromBackup($backup);
        $protocol->set('agenda', $agenda->toArray());
        $protocol->set('options', $options->toArray());

        $preflight = $this->preflight($supplierId, $backup, $agenda, $options, $journalPreview);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $ctx = new ImportContext($supplierId, $userId, $backup, $agenda, $options, $protocol, $this->periods->fiscalCalendar($supplierId));
        $ctx->runId = $runId;
        $ctx->progress = $progress;
        $ctx->taxRecords = $agenda->taxRecordsOnly();
        // Daňová evidence se neúčtuje: automatika účtování ani výchozí nastavení účetní
        // jednotky se jí netýkají a převod je nemění.
        $automationManaged = !$ctx->taxRecords;

        $pdo = $this->db->pdo();
        $dryRun = $options->isDryRun();
        // Zkouška nanečisto uvnitř cizí transakce (testy, vnořené volání) jede přes
        // savepoint — vlastní BEGIN by PDO odmítlo a vnější transakci by nesměla vrátit.
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT money_s3_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            // Stav před PRVNÍM ostrým během, po kterém se automatika neobnovila (neúspěšný
            // nebo spadlý běh ji nechal vypnutou) — jinak by se „obnovilo" vypnuto. K běhu
            // se ukládá PŘED vypnutím: kdyby spadl i tenhle běh, další snímek najde.
            $snapshot = $automationManaged ? (($dryRun ? null : $this->map->pendingAutomationSnapshot($supplierId)) ?? $this->unit->snapshot($supplierId)) : [];
            if ($automationManaged && !$dryRun && $runId !== null) {
                $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
            }
            // Zkouška nanečisto vypnutí automatiky jen ohlásí. Zápis do řádku firmy by v její
            // jediné transakci držel zámek až do konce a každý nový doklad firmy (cizí klíč
            // na firmu) by na něj čekal; převod sám automatiku ke své práci nepotřebuje.
            if ($automationManaged && !$dryRun) {
                $this->unit->disableAutomation($supplierId, $userId > 0 ? $userId : null);
            }
            $automation = ['before' => $snapshot, 'during' => $dryRun || !$automationManaged ? 'off' : $this->unit->automationLevel($supplierId), 'restored' => false, 'after' => null];
            if ($automationManaged) {
                $protocol->set('automation', $automation);
            }

            $steps = $ctx->taxRecords ? $this->taxEvidenceSteps($ctx) : $this->steps($ctx);
            $total = count($steps);
            $index = 0;
            foreach ($steps as $key => $fn) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                $ctx->report($key, $index++, $total);
                $protocol->begin($key);
                if (!$dryRun && in_array($key, [MoneyS3Reconciler::STEP, TaxRecordsReconciler::STEP], true)) {
                    $this->statistics->refreshAfterImport(['money_s3_import_map']);
                }
                try {
                    // Uzávěrka si transakce řídí sama (každý krok průvodce je atomický);
                    // obalit ji by znamenalo, že se chyba uprostřed kroku nevrátí.
                    if ($dryRun || $key === HistoricalYearCloser::STEP) {
                        $fn();
                    } else {
                        $this->transactional($fn);
                    }
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof MoneyS3Exception) {
                        $protocol->error($key, $e->errorCode, $e->getMessage());
                    } else {
                        // Text výjimky (SQL, cesty) do protokolu viditelného v UI nepatří.
                        error_log(sprintf('Money S3: krok %s převodu firmy %d selhal: %s', $key, $supplierId, (string) $e));
                        $protocol->error($key, 'unexpected', 'Krok převodu selhal na neočekávané chybě, podrobnosti jsou v logu serveru.');
                    }
                    $protocol->fail($key);
                    break;
                }
                if (in_array($key, self::CRITICAL_STEPS, true) && $protocol->blocks($key)) {
                    $protocol->fail($key);
                    break;
                }
            }
            $ctx->report('done', $total, $total);
            if (!$dryRun) {
                $this->invoices->recomputeClientStats($ctx);
            }

            if ($automationManaged && !$dryRun && !$protocol->hasErrors()) {
                $this->unit->restoreAutomation($supplierId, $snapshot, $userId > 0 ? $userId : null);
                $this->map->markAutomationRestored($supplierId);
                $automation['restored'] = true;
                $automation['after'] = $this->unit->automationLevel($supplierId);
            }
            if ($automationManaged) {
                $protocol->set('automation', $automation);
            }
        } finally {
            if ($savepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @return array<string,callable():void> */
    private function steps(ImportContext $ctx): array
    {
        return [
            ChartImporter::STEP => fn () => $this->chart->run($ctx),
            JournalImporter::STEP => fn () => $this->journal->run($ctx),
            self::STEP_ACCOUNTING_MODE => fn () => $this->switchMode($ctx),
            CodebookImporter::STEP_PARTNERS => fn () => $this->codebooks->importPartners($ctx),
            CodebookImporter::STEP_POSTING_RULES => fn () => $this->codebooks->importPostingRules($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            DocumentLinker::STEP_LINK => fn () => $this->linker->link($ctx),
            DimensionImporter::STEP => fn () => $this->dimensions->run($ctx),
            DocumentLinker::STEP_PAYMENTS => fn () => $this->linker->matchPayments($ctx),
            RecurringSeriesTakeover::STEP => fn () => $this->takeOverRecurring($ctx),
            AssetImporter::STEP =>fn () => $this->assets->importLongTerm($ctx),
            AssetImporter::STEP_SMALL => fn () => $this->assets->importSmall($ctx),
            VatCoefficientSeeder::STEP => fn () => $this->coefficients->run($ctx),
            HistoricalYearCloser::STEP => fn () => $this->closer->run($ctx),
            PayrollImporter::STEP => fn () => $this->payroll->run($ctx),
            MoneyS3Reconciler::STEP => fn () => $this->reconciler->run($ctx),
        ];
    }

    /**
     * Kroky převodu agendy vedené v Money jako daňová evidence: bez osnovy, deníku, režimu
     * účetní jednotky, předkontací, vazeb na deník, dimenzí, uzávěrky, majetku a mezd.
     * Doklady, banku a pokladnu převádějí stávající kroky, z peněžního deníku přibudou
     * úhrady, zařazení pohybů, ostatní položky a plátcovství ({@see TaxRecordsImporter}),
     * na konci rekonciliace peněžního deníku a podaná přiznání DPH.
     *
     * @return array<string,callable():void>
     */
    private function taxEvidenceSteps(ImportContext $ctx): array
    {
        return [
            TaxRecordsImporter::STEP_PLAN => fn () => $this->taxRecords->plan($ctx),
            CodebookImporter::STEP_PARTNERS => fn () => $this->codebooks->importPartners($ctx),
            TaxRecordsImporter::STEP_VAT_STATUS => fn () => $this->taxRecords->vatStatus($ctx),
            InvoiceImporter::STEP_PURCHASE => fn () => $this->invoices->importPurchases($ctx),
            InvoiceImporter::STEP_ISSUED => fn () => $this->invoices->importIssued($ctx),
            CashBankImporter::STEP_CASH => fn () => $this->cashBank->importCash($ctx),
            CashBankImporter::STEP_BANK => fn () => $this->cashBank->importBank($ctx),
            TaxRecordsImporter::STEP_PAYMENTS => fn () => $this->taxRecords->payments($ctx),
            TaxRecordsImporter::STEP_CLASSIFICATION => fn () => $this->taxRecords->classification($ctx),
            TaxRecordsImporter::STEP_OTHER_ITEMS => fn () => $this->taxRecords->otherItems($ctx),
            RecurringSeriesTakeover::STEP => fn () => $this->takeOverRecurring($ctx),
            TaxRecordsReconciler::STEP => fn () => $this->taxRecordsReconciler->run($ctx),
            VatFilingsTakeover::STEP => fn () => $this->vatFilings->run($ctx),
        ];
    }

    private function takeOverRecurring(ImportContext $ctx): void
    {
        $result = $this->recurring->run($ctx->supplierId, $ctx->userId);
        $ctx->protocol->setCount(RecurringSeriesTakeover::STEP, 'created', $result['created']);
        if ($result['existing'] > 0) {
            $ctx->protocol->setCount(RecurringSeriesTakeover::STEP, 'existing', $result['existing']);
        }
        if ($result['templates'] !== []) {
            $ctx->protocol->info(RecurringSeriesTakeover::STEP, 'paused_templates', sprintf(
                'Z pravidelných faktur v historii vzniklo %d pozastavených šablon pravidelné fakturace (%s). Nic se samo nevystaví: zkontrolujte je v Pravidelné fakturace a spusťte.',
                count($result['templates']),
                implode(', ', array_map(static fn (array $t): string => $t['client'], array_slice($result['templates'], 0, 10))) . (count($result['templates']) > 10 ? ', …' : ''),
            ), ['templates' => array_slice($result['templates'], 0, 50)]);
        }
    }

    private function switchMode(ImportContext $ctx): void
    {
        $starts = array_map(static fn (array $p): string => $p['starts_on'], $ctx->periods);
        if ($starts === []) {
            return;
        }
        sort($starts);
        $ends = array_map(static fn (array $p): string => $p['ends_on'], $ctx->periods);
        $this->unit->switchToDoubleEntry($ctx->supplierId, $starts[0], !$ctx->options->isDryRun(), max($ends));
        $ctx->protocol->info(self::STEP_ACCOUNTING_MODE, 'double_entry', 'Podvojné účetnictví od ' . $starts[0] . '.');
    }


    private function transactional(callable $fn): void
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $fn();
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
