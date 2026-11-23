<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\AccountingFrameworkException;
use MyInvoice\Service\Accounting\AccountingFrameworkImportGuard;
use MyInvoice\Infrastructure\Database\TableStatistics;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\AccountingSupplierSettingsRepository;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Migration\Shared\FiscalYearMismatchException;
use MyInvoice\Service\Migration\Shared\MigrationPeriods;
use MyInvoice\Service\Migration\Shared\RecurringSeriesTakeover;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
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
        private readonly InvoicePdfRenderer $pdf,
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
        [$options, $notes, $explicit, $openingOnly] = $this->resolveToYear($supplierId, $backup, $agenda, $options, $journalPreview);
        return [...$notes, ...$this->checks($supplierId, $backup, $agenda, $options, $journalPreview, $explicit, $openingOnly)];
    }

    /**
     * Volba „Převést do roku" pro převod: výslovně zvolený rok, jinak výchozí podle firmy
     * ({@see defaultToYear()}). Vrací volby s rokem, zprávy kontroly před převodem (výchozí
     * rok, vynechané pozdější adresáře zálohy), zda roky převodu vybral uživatel a rok, ze
     * kterého se převezmou jen počáteční stavy ({@see ImportOptions::$openingOnlyNext}).
     *
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     * @return array{0:ImportOptions,1:list<array{level:string,code:string,message:string,context:array<string,mixed>}>,2:array{from:bool,to:bool},3:?array<string,mixed>}
     */
    private function resolveToYear(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options, ?array $journalPreview): array
    {
        $explicit = ['from' => $options->fromYear !== null, 'to' => $options->toYear !== null];
        if ($agenda->taxRecordsOnly()) {
            return [$options, [], $explicit, null];
        }
        $full = $this->fullPlan($supplierId, $backup, $options, $journalPreview);
        $notes = [];
        if ($options->toYear === null) {
            $default = $this->defaultToYear($supplierId, $full, $options);
            if ($default !== null) {
                $options = $options->withToYear($default['to_year']);
                $notes[] = ['level' => 'warning', 'code' => 'to_year_default', 'message' => sprintf(
                    'Účetní období %s už v MyÚčtu obsahují vlastní zápisy. Převod proto skončí rokem %d (volba „Převést do roku“); pozdější roky z Money nepřimíchá do účetnictví vedeného v MyÚčtu.',
                    implode(', ', $default['own_years']), $default['to_year'],
                ), 'context' => $default];
            }
        }
        $openingOnly = null;
        if ($options->openingOnlyNext && !$options->relinkOnly) {
            if ($options->toYear === null && count($full) >= 2) {
                // Bez „Převést do roku" je posledním převáděným rokem předposlední rok zálohy.
                $options = $options->withToYear((int) $full[count($full) - 2]['year']);
            }
            $openingOnly = $options->toYear !== null ? self::openingOnlyItem($full, $options->toYear) : null;
            if ($openingOnly === null) {
                $notes[] = ['level' => 'warning', 'code' => 'opening_only_missing', 'message' => sprintf(
                    'Záloha nemá adresář roku %s s počátečními stavy. Rok %s proto nepůjde ověřit proti Money ani uzavřít, zůstane otevřený.',
                    $options->toYear !== null ? (string) ($options->toYear + 1) : 'po posledním převáděném', $options->toYear ?? 'poslední',
                ), 'context' => ['to_year' => $options->toYear]];
            } else {
                $notes[] = ['level' => 'info', 'code' => 'opening_only_next', 'message' => sprintf(
                    'Z adresáře %s se převezmou jen počáteční stavy roku %d. Rok %d se proti nim ověří a uzavře; doklady, banka ani deník roku %2$d z Money do MyÚčta nepřijdou.',
                    $openingOnly['dir'], $openingOnly['year'], $options->toYear,
                ), 'context' => ['year' => $openingOnly['year'], 'dir' => $openingOnly['dir'], 'to_year' => $options->toYear]];
            }
        }
        if ($options->toYear !== null) {
            $later = array_values(array_filter($full, static fn (array $p): bool => $p['year'] > $options->toYear
                && ($openingOnly === null || $p['dir'] !== $openingOnly['dir'])));
            if ($later !== []) {
                $notes[] = ['level' => 'info', 'code' => 'later_years_skipped', 'message' => sprintf(
                    'Adresáře zálohy po roce %d se nepřevádějí: %s. Jejich deník, doklady ani banka do MyÚčta nepřijdou.',
                    $options->toYear,
                    implode(', ', array_map(static fn (array $p): string => "{$p['year']} ({$p['dir']})", $later)),
                ), 'context' => ['to_year' => $options->toYear, 'years' => array_column($later, 'year'), 'dirs' => array_column($later, 'dir')]];
            }
            if ($full !== [] && $later === $full) {
                $notes[] = ['level' => 'error', 'code' => 'to_year_empty', 'message' => "Do roku {$options->toYear} záloha nemá žádný účetní rok — není co převést.",
                    'context' => ['to_year' => $options->toYear]];
            }
        }
        return [$options, $notes, $explicit, $openingOnly];
    }

    /**
     * Adresář roku po posledním převáděném (`$toYear` + 1), ze kterého se převezmou jen
     * počáteční stavy. Má-li rok v záloze víc adresářů, platí poslední (Money v něm vede
     * aktuální počáteční stavy).
     *
     * @param list<array<string,mixed>> $plan rozvrh bez horní hranice
     * @return array<string,mixed>|null
     */
    private static function openingOnlyItem(array $plan, int $toYear): ?array
    {
        $found = null;
        foreach ($plan as $item) {
            if ((int) $item['year'] === $toYear + 1) {
                $found = $item;
            }
        }
        return $found;
    }

    /**
     * Výchozí „Převést do roku": poslední rok zálohy, který v MyÚčtu nemá vlastní zápisy,
     * když po něm v rozvrhu následují roky, které už vlastní zápisy mají (firma v MyÚčtu
     * účtuje rok 2027 a z Money se převádí rok 2026). Bez takových let null - převádějí se
     * všechny roky jako dřív. Null i tehdy, když by v rozsahu nezůstal žádný rok, do kterého
     * převod zapisuje (otevřené nebo nové období): kontrola pak ohlásí vlastní zápisy
     * (`journal_not_empty`) jako dřív. Opravný převod vazeb vlastní zápisy nechává, hranici
     * nepotřebuje.
     *
     * @param list<array<string,mixed>> $plan rozvrh bez horní hranice
     * @return array{to_year:int,own_years:list<int>}|null
     */
    private function defaultToYear(int $supplierId, array $plan, ImportOptions $options): ?array
    {
        if ($options->relinkOnly || $plan === []) {
            return null;
        }
        $own = [];
        $last = null;
        $writable = [];
        foreach ($plan as $item) {
            $period = $this->periods->findByYear($supplierId, (int) $item['year']);
            if ($period !== null && $this->journal->foreignEntryCount($supplierId, (int) $period['id']) > 0) {
                $own[] = (int) $item['year'];
            } else {
                $last = (int) $item['year'];
            }
            if ($period === null || (string) $period['status'] === 'open') {
                $writable[] = (int) $item['year'];
            }
        }
        if ($last === null || $last === (int) $plan[count($plan) - 1]['year']
            || array_filter($writable, static fn (int $y): bool => $y <= $last) === []) {
            return null;
        }
        return ['to_year' => $last, 'own_years' => array_values(array_filter($own, static fn (int $y): bool => $y > $last))];
    }

    /**
     * „Převést do roku" pro firmu a zálohu: výslovně zvolený rok, jinak výchozí podle firmy
     * ({@see defaultToYear()}). Null = převádějí se všechny roky zálohy.
     */
    public function effectiveToYear(int $supplierId, Ms3Backup $backup, ImportOptions $options): ?int
    {
        if ($options->toYear !== null) {
            return $options->toYear;
        }
        return $this->defaultToYear($supplierId, $this->fullPlan($supplierId, $backup, $options, null), $options)['to_year'] ?? null;
    }

    /**
     * Rozvrh zálohy bez horní hranice (volba „Převést od roku" platí): z náhledu nahrání,
     * když ho volby dovolí, jinak z deníku zálohy.
     *
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     * @return list<array<string,mixed>>
     */
    private function fullPlan(int $supplierId, Ms3Backup $backup, ImportOptions $options, ?array $journalPreview): array
    {
        $calendar = $this->periods->fiscalCalendar($supplierId);
        if ($options->fromYear === null && $options->firstPeriodStart === null && self::journalPreviewComplete($journalPreview)
            && $journalPreview['start_month'] === $calendar->startMonth()) {
            return $journalPreview['plan'];
        }
        return $this->journal->plan($backup, $options->withToYear(null), $calendar);
    }

    /**
     * Kontroly před převodem s rozhodnutým „Převést do roku" ({@see resolveToYear()}).
     * `$explicit` = které hranice převodu vybral uživatel (od/do roku): uzavřené období roku
     * zvoleného v „Převést do roku" je pak chyba, ne jen přeskočený rok. Uzavřený rok
     * „od roku" chybou není - opakovaný převod jím běžně začíná a uzavřené roky přeskočí.
     *
     * @param array{plan:list<array<string,mixed>>,chain_breaks:list<array<string,mixed>>}|null $journalPreview
     * @param array{from:bool,to:bool} $explicit
     * @param array<string,mixed>|null $openingOnly rok jen s počátečními stavy ({@see openingOnlyItem()})
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function checks(int $supplierId, Ms3Backup $backup, AgendaInfo $agenda, ImportOptions $options, ?array $journalPreview, array $explicit, ?array $openingOnly = null): array
    {
        $calendar = $this->periods->fiscalCalendar($supplierId);
        if ($options->fromYear !== null || $options->firstPeriodStart !== null || !self::journalPreviewComplete($journalPreview)
            || $journalPreview['start_month'] !== $calendar->startMonth()) {
            $journalPreview = null;
        } elseif ($options->toYear !== null) {
            // Náhled nahrání je rozvrh celé zálohy; horní hranice ho jen ořízne.
            $journalPreview['plan'] = array_values(array_filter($journalPreview['plan'], static fn (array $p): bool => (int) $p['year'] <= $options->toYear));
            $journalPreview['chain_breaks'] = array_values(array_filter($journalPreview['chain_breaks'], static fn (array $b): bool => (int) $b['next'] <= $options->toYear));
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

        if ($options->relinkOnly && !$this->map->hasAny($supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY)) {
            $add('error', 'relink_without_conversion', 'Opravný převod vazeb předpokládá firmu převedenou z Money S3; deník převodu tu chybí.');
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
        if ($plan !== []) {
            try { (new AccountingFrameworkImportGuard($this->db))->assertCz500Periods($supplierId, $plan); }
            catch (AccountingFrameworkException $e) { $add('error', $e->errorCode, $e->getMessage()); }
        }
        if (!$taxRecords && $plan === [] && $options->toYear !== null) {
            // Prázdný rozvrh pod horní hranicí ohlásila už volba „Převést do roku" (to_year_empty).
        } elseif (!$taxRecords && $plan === [] && $options->fromYear !== null) {
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
        $lockedUntil = $taxRecords ? null : $this->settings->getLockedUntil($supplierId);
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
            $blocker = self::periodBlocker((int) $item['year'], $period ?? ['status' => 'open', 'starts_on' => $item['starts_on']], $lockedUntil);
            if ($blocker !== null && $blocker->errorCode === 'date_locked' && $options->relinkOnly) {
                // Opravný převod vazeb deník ani DPH nemění, jen vazby, párování a úhrady.
                $add('warning', 'relink_under_date_lock', "Účetní období {$item['year']} zasahuje do zámku účtování k "
                    . date('j. n. Y', (int) strtotime((string) $lockedUntil)) . '. Opravný převod vazeb deník ani DPH nemění, vazby, párování a úhrady převzatých dokladů ale opraví i v zamčeném období.',
                    ['year' => $item['year'], 'locked_until' => $lockedUntil]);
            } elseif ($blocker !== null && $blocker->errorCode === 'date_locked') {
                // Rok, do kterého by převod zapisoval, zasahuje do zámku účtování k datu.
                $add('error', $blocker->errorCode, $blocker->getMessage(), $blocker->context);
            }
            if ($period === null) {
                continue;
            }
            $mapped = $this->map->get($supplierId, MoneyS3ImportRepository::KIND_PERIOD, (string) $item['year']) !== null;
            $foreign = $this->journal->foreignEntryCount($supplierId, (int) $period['id']);
            if ($foreign > 0 && $options->relinkOnly) {
                $add('info', 'relink_keeps_own_journal', "Účetní období {$item['year']} obsahuje {$foreign} zápisů zadaných v MyÚčtu. Opravný převod je nechá beze změny a nové doklady z Money nepřidá.", ['year' => $item['year'], 'entries' => $foreign]);
            } elseif ($foreign > 0) {
                $add('error', 'journal_not_empty', "Účetní období {$item['year']} už obsahuje {$foreign} zápisů, které nevznikly převodem z Money. Deník z Money se do rozjetého účetnictví nepřimíchává.", ['year' => $item['year'], 'entries' => $foreign]);
            }
            if ((string) $period['status'] !== 'open' && !$mapped) {
                $add('error', 'period_not_open', "Účetní období {$item['year']} je v MyÚčtu uzavřené.", ['year' => $item['year']]);
            } elseif ((string) $period['status'] !== 'open' && $explicit['to'] && !$options->relinkOnly && !$options->openingOnlyNext && (int) $item['year'] === $options->toYear) {
                // Uzavřený historický rok převod jen přeskočí; rok, do kterého má převod podle
                // volby „Převést do roku" zapisovat, ale uzavřený být nesmí. S počátečními stavy
                // dalšího roku ho uzavírá sám převod, opakovaný převod ho jen přeskočí.
                $add('error', 'period_not_open', "Účetní období {$item['year']}, do kterého má převod zapisovat (volba „Převést do roku“), je v MyÚčtu uzavřené.", ['year' => $item['year']]);
            }
        }
        if ($openingOnly !== null && !$taxRecords) {
            array_push($out, ...$this->openingOnlyChecks($supplierId, $openingOnly, $lockedUntil));
        }
        if ($plan !== [] && !$taxRecords && $conflict === null && ($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            $add('info', 'switch_to_double_entry', 'Firma se převodem přepne do podvojného účetnictví.');
        }
        return $out;
    }

    /**
     * Kontrola roku, ze kterého se převezmou jen počáteční stavy: období musí být otevřené
     * a mimo zámek k datu (převod do něj zapisuje otevírací zápis). Vlastní zápisy z MyÚčta
     * nevadí; otevírací zápis z MyÚčta převod nemění a jen ho ohlásí.
     *
     * @param array<string,mixed> $item
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    private function openingOnlyChecks(int $supplierId, array $item, ?string $lockedUntil): array
    {
        $out = [];
        $year = (int) $item['year'];
        if (!($item['has_opening'] ?? false)) {
            $out[] = ['level' => 'warning', 'code' => 'opening_only_no_opening', 'message' => "Adresář {$item['dir']} roku {$year} nemá v Money počáteční stavy (XP). Rok " . ($year - 1)
                . ' proto nepůjde ověřit ani uzavřít.', 'context' => ['year' => $year, 'dir' => $item['dir']]];
        }
        $period = $this->periods->findByYear($supplierId, $year);
        if ($period !== null && (string) $period['status'] !== 'open') {
            $out[] = ['level' => 'error', 'code' => 'opening_only_period_not_open', 'message' => "Účetní období {$year}, do kterého se mají převzít počáteční stavy z Money, je v MyÚčtu uzavřené.",
                'context' => ['year' => $year]];
            return $out;
        }
        $blocker = self::periodBlocker($year, $period ?? ['status' => 'open', 'starts_on' => $item['starts_on']], $lockedUntil);
        if ($blocker !== null) {
            $out[] = ['level' => 'error', 'code' => $blocker->errorCode, 'message' => $blocker->getMessage(), 'context' => $blocker->context];
        }
        if ($period !== null && $this->map->get($supplierId, MoneyS3ImportRepository::KIND_JOURNAL_ENTRY, $year . '|' . Ms3Journal::OPENING_SOURCE) === null) {
            $stmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = ? AND period_id = ? AND source_type = 'opening' AND reversed_by IS NULL");
            $stmt->execute([$supplierId, (int) $period['id']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $out[] = ['level' => 'info', 'code' => 'opening_only_existing', 'message' => "Účetní období {$year} už má v MyÚčtu otevírací zápis. Převod ho nemění, počáteční stavy z Money s ním porovná uzávěrka roku " . ($year - 1) . '.',
                    'context' => ['year' => $year]];
            }
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
        [$options, $notes, $explicit, $openingOnly] = $this->resolveToYear($supplierId, $backup, $agenda, $options, $journalPreview);
        $protocol->set('options', $options->toArray());

        $preflight = [...$notes, ...$this->checks($supplierId, $backup, $agenda, $options, $journalPreview, $explicit, $openingOnly)];
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
        $ctx->openingOnly = $openingOnly;
        if (!$ctx->taxRecords) {
            $ctx->lockedUntil = $this->settings->getLockedUntil($supplierId);
        }
        // Daňová evidence se neúčtuje: automatika účtování ani výchozí nastavení účetní
        // jednotky se jí netýkají a převod je nemění.
        $automationManaged = !$ctx->taxRecords;

        $frameworkGuard = new AccountingFrameworkImportGuard($this->db);
        $frameworkPeriods = $this->journal->plan($backup, $options, $this->periods->fiscalCalendar($supplierId));
        if ($openingOnly !== null) {
            // Rok jen s počátečními stavy: převod do něj zapisuje otevírací zápis.
            $frameworkPeriods[] = $openingOnly;
        }
        // Roky, do kterých převod zapisuje: otevřená (nebo nová) období rozvrhu. Uzavřené
        // historické roky převod přeskočí; zapisované se ověřují v transakci každého kroku.
        $targets = $automationManaged ? $this->writableTargets($supplierId, $frameworkPeriods) : [];
        // Opravný převod vazeb deník ani DPH nemění: zámek k datu ho nezastaví, jen uzavření období.
        $lockChecked = !$options->relinkOnly;
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
            // Zkouška nanečisto vypnutí automatiky jen ohlásí. Zápis do řádku firmy by v její
            // jediné transakci držel zámek až do konce a každý nový doklad firmy (cizí klíč
            // na firmu) by na něj čekal; převod sám automatiku ke své práci nepotřebuje.
            // Daňová evidence účetní období ani účetní rámec nemá, ochrana rámce se jí netýká.
            if ($automationManaged && !$dryRun) {
                $frameworkGuard->writeCz500Periods($supplierId, $frameworkPeriods, function () use ($runId, $supplierId, $snapshot, $userId, $targets, $lockChecked): void {
                    $this->assertPeriodsWritable($supplierId, $targets, true, $lockChecked);
                    if ($runId !== null) $this->map->saveAutomationSnapshot($runId, $supplierId, $snapshot);
                    $this->unit->disableAutomation($supplierId, $userId > 0 ? $userId : null);
                });
            } elseif ($automationManaged) {
                $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                $this->assertPeriodsWritable($supplierId, $targets, false, $lockChecked);
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
                        // Zkouška nanečisto zámky v jediné transakci nedrží (blokovala by firmu).
                        $this->assertPeriodsWritable($supplierId, $targets, false, $lockChecked);
                        $fn();
                    } else {
                        $this->transactional(function () use ($frameworkGuard, $supplierId, $frameworkPeriods, $fn, $targets, $lockChecked): void {
                            $frameworkGuard->assertCz500Periods($supplierId, $frameworkPeriods, true);
                            $this->assertPeriodsWritable($supplierId, $targets, true, $lockChecked);
                            $fn();
                        });
                    }
                    if ($key === HistoricalYearCloser::STEP) {
                        // Roky, které uzavřela uzávěrka převodu, už další kroky nezapisují.
                        $targets = $this->writableTargets($supplierId, $frameworkPeriods);
                    }
                    $protocol->finish($key);
                } catch (\Throwable $e) {
                    // Odvolaná transakce (1020/1213) nesmí zůstat viset jako otevřená:
                    // savepoint zkušebního běhu i zbytek převodu by jinak pracovaly naslepo.
                    TransactionFailure::rollbackIfAborted($pdo, $e);
                    if ($e instanceof MoneyS3Exception || $e instanceof AccountingFrameworkException) {
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
                // Vydané doklady aktualizované podle Money: uložené PDF má staré částky.
                foreach (array_unique($ctx->refreshedInvoices) as $invoiceId) {
                    try {
                        $this->pdf->invalidate($invoiceId, 'invalidate_money_s3_refresh');
                    } catch (\Throwable $e) {
                        if (TransactionFailure::aborted($e)) {
                            throw $e;
                        }
                        // Úklid souboru po zapsaném převodu jeho výsledek nemění.
                        error_log(sprintf('Money S3: PDF faktury %d se po aktualizaci nezahodilo: %s', $invoiceId, $e->getMessage()));
                    }
                }
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
        } catch (AccountingFrameworkException | MoneyS3Exception $e) {
            $protocol->error(self::STEP_PREFLIGHT, $e->errorCode, $e->getMessage());
            $protocol->fail(self::STEP_PREFLIGHT);
        } finally {
            if ($savepoint && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($dryRun) {
                // Forma zápisu zapsaná zkouškou se vrátila s ní; sdílená odpověď procesu taky.
                EntryFormService::forgetShared($supplierId);
            }
        }
        return $protocol;
    }

    /**
     * Proč převod do období nesmí zapisovat: období není otevřené (`period_not_open`), nebo
     * zasahuje do zámku účtování k datu (`date_locked`). Null = smí. Shodná pravidla jako
     * převod POHODY ({@see \MyInvoice\Service\Migration\Pohoda\PohodaImporter}).
     *
     * @param array{status?:string,starts_on:string} $period
     */
    private static function periodBlocker(int $year, array $period, ?string $lockedUntil): ?MoneyS3Exception
    {
        if (($period['status'] ?? 'open') !== 'open') {
            return new MoneyS3Exception('period_not_open', "Účetní období {$year} není v MyÚčtu otevřené. Převod do něj nezapisuje.", ['year' => $year]);
        }
        if ($lockedUntil !== null && $lockedUntil >= (string) $period['starts_on']) {
            return new MoneyS3Exception('date_locked', sprintf(
                'Účetní období %d zasahuje do zámku účtování k %s. Převod by zapsal pod zámek, proto se nespustí. Zámek zrušte (nebo posuňte před začátek roku %1$d) a převod spusťte znovu.',
                $year, date('j. n. Y', (int) strtotime($lockedUntil)),
            ), ['year' => $year, 'locked_until' => $lockedUntil]);
        }
        return null;
    }

    /**
     * Roky rozvrhu, do kterých převod zapisuje: období v MyÚčtu ještě není, nebo je otevřené.
     *
     * @param list<array<string,mixed>> $plan
     * @return array<int,string> rok => začátek období
     */
    private function writableTargets(int $supplierId, array $plan): array
    {
        $out = [];
        foreach ($plan as $item) {
            $period = $this->periods->findByYear($supplierId, (int) $item['year']);
            if ($period === null || (string) $period['status'] === 'open') {
                $out[(int) $item['year']] = (string) ($period['starts_on'] ?? $item['starts_on']);
            }
        }
        return $out;
    }

    /**
     * Zapisovaná období jsou pořád otevřená a mimo zámek k datu. V transakci kroku se období
     * i zámek čtou s `FOR UPDATE` (`$currentRead`): uzavření nebo zámek nastavený souběžně
     * s převodem počká na konec kroku, nebo krok zastaví dřív, než cokoli zapíše.
     *
     * @param array<int,string> $targets rok => začátek období ({@see writableTargets()})
     * @param bool $lockChecked hlídat i zámek k datu (opravný převod vazeb ho nehlídá)
     */
    private function assertPeriodsWritable(int $supplierId, array $targets, bool $currentRead, bool $lockChecked = true): void
    {
        if ($targets === []) {
            return;
        }
        $lockedUntil = $lockChecked ? $this->settings->getLockedUntil($supplierId, $currentRead) : null;
        foreach ($targets as $year => $startsOn) {
            $period = $this->periods->findByYear($supplierId, $year, $currentRead) ?? ['status' => 'open', 'starts_on' => $startsOn];
            $blocker = self::periodBlocker($year, $period, $lockedUntil);
            if ($blocker !== null) {
                throw $blocker;
            }
        }
    }

    /**
     * Kroky, které v opravném převodu vazeb neběží: přepnutí účetnictví a předkontace firmu
     * nastavují, dimenze přepisují řádky deníku, opakované fakturace, majetek, koeficienty,
     * uzávěrka a mzdy zakládají nebo účtují nové záznamy a rekonciliace srovnává celou
     * zálohu, jejíž novější doklady opravný převod záměrně nepřidá.
     */
    private const RELINK_SKIPPED_STEPS = [
        self::STEP_ACCOUNTING_MODE, CodebookImporter::STEP_POSTING_RULES, DimensionImporter::STEP, RecurringSeriesTakeover::STEP,
        AssetImporter::STEP, AssetImporter::STEP_SMALL, VatCoefficientSeeder::STEP, HistoricalYearCloser::STEP,
        PayrollImporter::STEP, MoneyS3Reconciler::STEP,
    ];

    /** @return array<string,callable():void> */
    private function steps(ImportContext $ctx): array
    {
        $steps = $this->allSteps($ctx);
        return $ctx->options->relinkOnly ? array_diff_key($steps, array_flip(self::RELINK_SKIPPED_STEPS)) : $steps;
    }

    /** @return array<string,callable():void> */
    private function allSteps(ImportContext $ctx): array
    {
        $plan = $this->journal->plan($ctx->backup, $ctx->options, $ctx->calendar);
        $first = $plan[0];
        $seedDate = $this->periods->findByYear($ctx->supplierId, $first['year'])['starts_on'] ?? $first['starts_on'];
        return [
            ChartImporter::STEP => fn () => $this->chart->run($ctx, $seedDate),
            JournalImporter::STEP => function () use ($ctx): void {
                // Forma zápisu nové firmy dřív, než do deníku přijde první převzatý zápis.
                $this->unit->applyEntryFormDefault($ctx->supplierId);
                $this->journal->run($ctx);
            },
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
