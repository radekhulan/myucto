<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollImportProfileRepository;
use MyInvoice\Repository\Payroll\PayrollInputFilter;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Absence\ImportAbsenceCompensationRates;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRepeatedMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverSicknessCompensation;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollComponentJmhzMappingDefaults;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzComponentSourceRule;

/**
 * Převod mezd z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`) do mezd firmy.
 *
 * Jde stejnou cestou jako ruční import v Mzdy → Importy → Docházka
 * ({@see AttendanceImportService}): profil importu „POHODA mzdy (převod)", pro každý
 * měsíc sešit z {@see PohodaPayrollConverter}, náhled, založení osob, které ve firmě
 * nejsou, a použití dávky (vazby, vstupy, chybějící mzdové složky, měsíční mzda vztahu,
 * souhrn docházky, srážky). Převod účetnictví se mzdami nepracuje - mzdy jsou vlastní
 * akce průvodce, i pro export, ve kterém jsou jen mzdy.
 *
 * **Opakovaný převod** měsíc, který už prošel (období a otisk sešitu v mapě převodu),
 * přeskočí; import dávky je navíc idempotentní sám (otisk dávky). Měsíc, který počítá
 * MyÚčto a jehož sešit se od dřívějšího převodu změnil, převede znovu: otevře pracovní
 * měsíce z dřívější dávky a zruší její vstupy, které nová dávka nenese
 * ({@see PayrollTakeoverRepeatedMonth}), pokud měsíc nemá běh se zamčenými vstupy.
 * **Zkouška nanečisto** běží celá v jedné transakci, která se na konci vrátí.
 */
final class PohodaPayrollImporter
{
    public const STEP_PREFLIGHT = 'payroll_preflight';
    public const STEP_PROFILE = 'payroll_profile';
    public const STEP_MONTHS = 'payroll_months';
    public const STEP_PEOPLE = 'payroll_people';
    public const STEP_JMHZ = 'payroll_jmhz';
    public const STEP_DEDUCTIONS = 'payroll_deductions';
    public const STEP_SICKNESS = 'payroll_sickness';
    public const STEP_POSTING_MAP = 'payroll_posting_map';
    public const STEP_INVARIANTS = 'payroll_invariants';
    /** Zdroj převzatých mezd v `payroll_migration_reference_totals`. */
    private const SOURCE = 'pamica';
    /** Kód kontroly: začátek vedení mezd leží před měsíci, které PAMICA zpracovala. */
    public const START_BEHIND = 'payroll_start_behind_takeover';
    /** Rozhodnutí k začátku vedení mezd: posunout ho za poslední zpracovaný měsíc a pak převést. */
    public const START_ADVANCE = 'advance';
    /** Rozhodnutí k začátku vedení mezd: vědomě ponechat, MyÚčto dotčené měsíce spočítá znovu. */
    public const START_KEEP = 'keep';
    private const PERSON_CHUNK = 100;
    private const MESSAGE_LIMIT = 20;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly AttendanceImportService $attendance,
        private readonly PayrollImportProfileRepository $profiles,
        private readonly PohodaPayrollPeopleWriter $people,
        private readonly PayrollInputRepository $inputs,
        private readonly PohodaPayrollDeductionsWriter $deductions,
        private readonly PohodaPayrollSicknessWriter $sickness,
        private readonly PayrollMigrationReferenceTotalsWriter $referenceTotals,
        private readonly PayrollPostingMapProposalService $postingMap,
        private readonly PayrollMigrationModuleSetup $moduleSetup,
        private readonly PohodaPayrollJmhzWriter $jmhz,
        private readonly PayrollImportAbsenceCompensationMaterializer $absenceCompensations,
        private readonly PayrollTakeoverRepeatedMonth $repeatedMonth,
        private readonly PayrollTakeoverSicknessCompensation $sicknessCompensation,
        private readonly PohodaPayrollRegistrations $registrations,
        private readonly PohodaPayrollRelations $relations,
        private readonly PayrollTakeoverInvariants $invariants,
    ) {}

    /**
     * Přehled souboru mezd, ze kterého vychází kontrola před převodem ({@see PohodaExport::payrollSummary()}).
     * Počítá ho job nahrání do `meta.json`; tady jen pro volajícího, který ho po ruce nemá.
     *
     * @return array{ico:string,employees:int,months:int,payslips:int,first:?string,last:?string,last_overall:?string}
     */
    public static function summary(string $file, int $year): array
    {
        if (!is_file($file)) {
            throw new PohodaException('payroll_missing', 'Export neobsahuje mzdy (91_mzdy.xml).');
        }
        return PohodaExport::payrollSummary($file, $year);
    }

    /**
     * Přehled z `meta.json` je úplný (nahraný před přidáním `last_overall` ho nemá,
     * před oddělením rozpracovaných měsíců chybí `open`).
     */
    public static function summaryComplete(mixed $summary): bool
    {
        return is_array($summary) && array_key_exists('last_overall', $summary) && array_key_exists('open', $summary)
            && isset($summary['employees'], $summary['months']) && array_key_exists('first', $summary) && array_key_exists('last', $summary);
    }

    /**
     * Poslední UZAVŘENÝ měsíc mezd v exportu (všechny roky), nebo `null`. Rozpracovaný
     * měsíc ({@see PohodaPayrollConverter::openPeriod()}) se nepočítá: začátek vedení
     * mezd za ním by nechal měsíc, který nikdo nespočítá.
     */
    private static function lastPeriod(PohodaPayrollConverter $converter): ?string
    {
        $periods = $converter->closedPeriods();
        return $periods === [] ? null : (string) max($periods);
    }

    /**
     * Začátek vedení mezd leží před posledním měsícem, který PAMICA zpracovala.
     *
     * Převod ho sám neposouvá (může jít o vědomé rozhodnutí podané měsíce
     * v MyÚčtu přepočítat), ale nesmí o tom mlčet: jinak MyÚčto tvrdí, že mzdy
     * počítá od měsíce, za který žádný běh nemá a převzít ho nejde.
     *
     * Bez vlastních běhů v dotčených měsících rozhoduje uživatel před ostrým převodem
     * ({@see self::START_ADVANCE} / {@see self::START_KEEP}); bez rozhodnutí se ostrý
     * převod odmítne, jinak by měsíce zpracované PAMICA dostaly vstupy z docházky
     * a dohody o srážkách jako měsíce počítané MyÚčtem.
     *
     * @return array{code:string,message:string,context:array<string,mixed>}|null
     */
    private function startBehindMessage(int $supplierId, string $last): ?array
    {
        $advance = $this->moduleSetup->startAdvance($supplierId, $last);
        if ($advance['to'] === null) {
            return null;
        }
        $context = ['from' => $advance['from'], 'to' => $advance['to'], 'last' => $last];
        if ($advance['blocking_runs'] !== []) {
            return ['code' => 'payroll_start_behind_takeover_runs', 'message' => sprintf(
                'Začátek vedení mezd v MyÚčtu je %s, PAMICA ale zpracovala mzdy až do %s a MyÚčto už má za %s vlastní '
                . 'mzdové běhy. Zkontrolujte, jestli se tyto měsíce nepočítají dvakrát.',
                self::monthLabel((string) $advance['from']), self::monthLabel($last),
                implode(', ', array_map(self::monthLabel(...), $advance['blocking_runs'])),
            ), 'context' => $context + ['blocking_runs' => $advance['blocking_runs']]];
        }
        return ['code' => self::START_BEHIND, 'message' => sprintf(
            'Začátek vedení mezd v MyÚčtu je %s, PAMICA ale zpracovala mzdy až do %s. Bez posunu začátku by převod '
            . 'měsíce %s až %s převedl jako měsíce, které počítá MyÚčto (vstupy z docházky, dohody o srážkách). '
            . 'Doporučený postup je posunout začátek na %s a převést; převod bez posunu jde jen s vědomým potvrzením.',
            self::monthLabel((string) $advance['from']), self::monthLabel($last),
            self::monthLabel((string) $advance['from']), self::monthLabel($last), self::monthLabel((string) $advance['to']),
        ), 'context' => $context];
    }

    private static function outsideEmploymentMessage(string $period, string $number): string
    {
        return sprintf(
            '%s: osobní číslo %s má mzdu zúčtovanou mimo trvání vztahu (typicky doplatek po skončení). '
            . 'Mzdové vstupy za tento měsíc se k vztahu nezapsaly; převzaté úhrny měsíce u vztahu jsou. '
            . 'Pokud je doplatek potřeba v MyÚčtu, zadejte ho ručně.',
            $period, $number,
        );
    }

    /** `YYYY-MM` → `M/YYYY`. */
    private static function monthLabel(string $period): string
    {
        return preg_match('/^([0-9]{4})-([0-9]{2})/', $period, $m) === 1 ? ((int) $m[2]) . '/' . $m[1] : $period;
    }

    /**
     * Počítá měsíc MyÚčto (leží od začátku vedení mezd dál)? Bez začátku se nic
     * za převzaté nepovažuje, jako dřív.
     */
    private static function countedByModule(string $period, ?string $moduleStart): bool
    {
        return $moduleStart === null || $moduleStart === '' || $period >= substr($moduleStart, 0, 7);
    }

    /** Věta o rozpracovaných měsících exportu pro kontrolu před převodem i protokol. */
    private static function openPeriodsMessage(array $open, ?string $exportedOn): string
    {
        $parts = [];
        foreach ($open as $period => $count) {
            $parts[] = sprintf('%s (%d mezd)', $period, (int) $count);
        }
        return sprintf(
            'Export z %s nese i měsíce, které v den exportu ještě neskončily: %s. Předchozí program je nemohl '
            . 'uzavřít ani podat, převod je proto nepřebírá a MyÚčto je spočítá samo od začátku vedení mezd. '
            . 'Pokud je předchozí program přesto uzavřel a podal, vyexportujte data znovu po konci měsíce.',
            $exportedOn === null ? '?' : date('j. n. Y', (int) strtotime($exportedOn)),
            implode(', ', $parts),
        );
    }

    /** @return list<string> */
    public static function stepKeys(): array
    {
        return [self::STEP_PREFLIGHT, self::STEP_PROFILE, self::STEP_MONTHS, self::STEP_PEOPLE, self::STEP_JMHZ,
            self::STEP_DEDUCTIONS, self::STEP_SICKNESS, self::STEP_POSTING_MAP, self::STEP_INVARIANTS];
    }

    /**
     * Kontrola před převodem mezd - nic nezapisuje.
     *
     * S přehledem souboru (`$summary` z `meta.json`, {@see summary()}) soubor mezd vůbec
     * nečte a stojí jen pár dotazů do databáze - tak ji volá náhled průvodce. Bez něj
     * přehled spočítá jedním průchodem souborem (převod na pozadí).
     *
     * @param array<string,mixed>|null $summary
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, string $file, int $year, ?array $summary = null): array
    {
        $out = [];
        $add = static function (string $level, string $code, string $message, array $context = []) use (&$out): void {
            $out[] = ['level' => $level, 'code' => $code, 'message' => $message, 'context' => $context];
        };
        $readError = null;
        if (!self::summaryComplete($summary)) {
            try {
                $summary = self::summary($file, $year);
            } catch (PohodaException $e) {
                $summary = null;
                $readError = $e;
            }
        }
        $pdo = $this->db->pdo();
        // Chybějící nastavení mezd převod doplní sám ({@see PayrollMigrationModuleSetup});
        // chybou zůstává jen to, co doplnit nejde (licence).
        $last = $summary['last_overall'] ?? null;
        $plan = $last === null ? null : $this->moduleSetup->plan($supplierId, (string) $last);
        $willSetUp = ($plan['outcome'] ?? null) === PayrollMigrationModuleSetup::OUTCOME_READY;
        $stmt = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            if ($willSetUp) {
                $add('info', 'payroll_module_will_enable', 'Firma nemá zapnutý modul Mzdy. Převod ho zapne'
                    . ($plan['start_period'] !== null ? ' a nastaví začátek vedení mezd na ' . $plan['start_period'] : '') . '.');
            } else {
                $add('error', 'payroll_disabled', 'Firma nemá zapnutý modul Mzdy. Zapněte ho v Nastavení → Moduly, jinak mzdy nejde převést.');
            }
        }
        // Počáteční stavy ročních kumulací se zapisují za měsíce před začátkem vedení mezd
        // v MyÚčtu; bez něj je převod nezapíše a mzdový běh je bude hlásit jako chybějící.
        if ($this->db->hasTable('payroll_module_state') && ($plan['start_period'] ?? null) === null) {
            $moduleStart = $pdo->prepare('SELECT start_period FROM payroll_module_state WHERE supplier_id = ?');
            $moduleStart->execute([$supplierId]);
            if (($moduleStart->fetchColumn() ?: null) === null) {
                $add('warning', 'payroll_start_missing', 'Firma nemá nastavený začátek vedení mezd v MyÚčtu (Mzdy → Nastavení). Převod bez něj nezapíše počáteční stavy ročních kumulací; nastavte první měsíc, který PAMICA nezpracovala, a převod zopakujte.');
            }
        }
        if ($last !== null && ($plan['start_period'] ?? null) === null) {
            $stale = $this->startBehindMessage($supplierId, (string) $last);
            if ($stale !== null) {
                $add('warning', $stale['code'], $stale['message'], $stale['context']);
            }
        }
        if ($this->db->hasTable('payroll_employer_settings')) {
            $office = $pdo->prepare('SELECT default_office_id FROM payroll_employer_settings WHERE supplier_id = ?');
            $office->execute([$supplierId]);
            if (($office->fetchColumn() ?: null) === null) {
                if ($willSetUp) {
                    $add('info', 'payroll_office_will_create', 'Firma nemá nastavení mezd zaměstnavatele. Převod založí výchozí mzdovou účtárnu; VS ČSSZ a účty institucí doplníte v Mzdy → Nastavení.');
                } else {
                    $add('error', 'payroll_office_missing', 'Chybí výchozí mzdová účtárna zaměstnavatele (Mzdy → Nastavení). Bez ní převod nezaloží pracovní vztahy.');
                }
            }
        }
        if ($readError !== null) {
            $add('error', $readError->errorCode, $readError->getMessage());
        } elseif ((int) ($summary['months'] ?? 0) === 0) {
            $add('error', 'payroll_no_months', "Export neobsahuje zpracované mzdy za rok {$year}.");
        } else {
            $months = (int) $summary['months'];
            $add('info', 'payroll_summary', sprintf('Mzdy za %d měsíců (%s až %s), zaměstnanců v exportu %d.',
                $months, (string) $summary['first'], (string) $summary['last'], (int) $summary['employees']), ['months' => $months]);
        }
        if ($summary !== null && is_array($summary['open'] ?? null) && $summary['open'] !== []) {
            $add('warning', 'payroll_open_months', self::openPeriodsMessage($summary['open'], $summary['exported_on'] ?? null),
                ['open' => $summary['open']]);
        }
        return $out;
    }

    /**
     * @param (callable(string,int,int):void)|null $progress
     * @param (callable():bool)|null $shouldCancel
     * @param bool $confirmIdentifiers uživatel potvrdil, že OIČ a ID PPV v PAMICA pocházejí z protokolů ČSSZ
     * @param bool $approveTakenOver převzatá docházka a mzdové vstupy se rovnou schválí (viz {@see approveTakenOverInputs()})
     * @param ?string $startDecision rozhodnutí k začátku vedení mezd, který leží před měsíci zpracovanými
     *        PAMICA ({@see self::START_ADVANCE} / {@see self::START_KEEP}); ostrý převod bez něj skončí chybou
     * @param bool $acceptDifferences ostrý převod přijme měsíce, které se nepřevedly ({@see ImportProtocol::difference()})
     */
    public function run(int $supplierId, int $userId, string $file, int $year, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, bool $confirmIdentifiers = false, bool $approveTakenOver = false, ?string $startDecision = null, bool $acceptDifferences = false): ImportProtocol
    {
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import', $acceptDifferences);
        $protocol->set('kind', 'payroll');
        $preflight = $this->preflight($supplierId, $file, $year);
        $protocol->set('preflight', $preflight);
        $protocol->begin(self::STEP_PREFLIGHT);
        foreach ($preflight as $m) {
            if ($m['level'] === 'error') {
                $protocol->error(self::STEP_PREFLIGHT, $m['code'], $m['message'], $m['context']);
            }
            if ($m['code'] === self::START_BEHIND && !$dryRun
                && !in_array($startDecision, [self::START_ADVANCE, self::START_KEEP], true)
            ) {
                $protocol->error(self::STEP_PREFLIGHT, 'payroll_start_decision_required', sprintf(
                    'Převod mezd se nespustil: začátek vedení mezd v MyÚčtu (%s) leží před měsíci, které PAMICA už zpracovala '
                    . '(do %s). V náhledu převodu zvolte „Posunout začátek na %s a převést", nebo vědomě potvrďte převod bez posunu.',
                    self::monthLabel((string) $m['context']['from']), self::monthLabel((string) $m['context']['last']),
                    self::monthLabel((string) $m['context']['to']),
                ), $m['context']);
            }
        }
        if ($protocol->hasErrors()) {
            $protocol->fail(self::STEP_PREFLIGHT);
            return $protocol;
        }
        $protocol->finish(self::STEP_PREFLIGHT);

        $converter = PohodaPayrollConverter::read($file, $year);
        $protocol->set('agenda', ['ico' => $converter->ico, 'year' => $year, 'program' => 'POHODA Mzdy', 'exported_at' => null, 'dir' => basename(dirname($file))]);
        $userOrNull = $userId > 0 ? $userId : null;

        $pdo = $this->db->pdo();
        $savepoint = $dryRun && $pdo->inTransaction();
        if ($savepoint) {
            $pdo->exec('SAVEPOINT pohoda_payroll_dry_run');
        } elseif ($dryRun) {
            $pdo->beginTransaction();
        }
        try {
            // Nastavení mezd, které firma nemá, doplní převod dřív, než založí první osobu.
            $last = self::lastPeriod($converter);
            if ($last !== null) {
                PayrollMigrationModuleSetup::report($protocol, self::STEP_PREFLIGHT,
                    $this->moduleSetup->ensure($supplierId, $userOrNull, $last), 'PAMICA');
                if ($startDecision === self::START_ADVANCE) {
                    try {
                        $moved = $this->moduleSetup->advanceStartBeforeTakeover($supplierId, $userOrNull, $last);
                    } catch (\DomainException $e) {
                        $moved = null;
                        $protocol->warn(self::STEP_PREFLIGHT, 'payroll_start_not_advanced', 'Začátek vedení mezd se neposunul: ' . $e->getMessage());
                    }
                    if ($moved !== null) {
                        $protocol->info(self::STEP_PREFLIGHT, 'payroll_start_advanced', sprintf(
                            'Začátek vedení mezd v MyÚčtu se před převodem posunul z %s na %s (poslední měsíc zpracovaný PAMICA + 1). '
                            . 'Měsíce do %s se převezmou jako zpracované předchozím programem.',
                            self::monthLabel($moved['from']), self::monthLabel($moved['to']), self::monthLabel($last),
                        ), $moved);
                    }
                }
                $stale = $this->startBehindMessage($supplierId, $last);
                if ($stale !== null) {
                    $protocol->warn(self::STEP_PREFLIGHT, $stale['code'], $stale['message'], $stale['context']);
                }
            }
            // Začátek vedení mezd v MyÚčtu (po případném nastavení převodem): měsíce
            // před ním jsou převzaté a nepočítá je žádný běh.
            $moduleStart = $this->sickness->startPeriod($supplierId);
            // Jen uzavřené měsíce. Rozpracovaný měsíc (export uprostřed září nese
            // září i říjen s několika výstupními mzdami) by se jinak převzal jako
            // hotový a MyÚčto by ho už nespočítalo.
            // Sešity měsíců se tady projdou jen kvůli souhrnům (sloupce profilu, vynechané údaje,
            // srážky bez druhu); řádky si nedrží, měsíc se znovu složí až při převodu.
            $periods = $converter->closedPeriods($year);
            $months = [];
            /** @var array<string,int> $componentInputs kód složky => vstupy s částkou */
            $componentInputs = [];
            foreach ($periods as $period) {
                $month = $converter->month($period);
                foreach ($month['columns'] as $header => $meta) {
                    if ($meta['meaning'] !== 'component') {
                        continue;
                    }
                    $code = (string) ($meta['code'] ?? '');
                    foreach ($month['rows'] as $row) {
                        $value = $row[$header] ?? null;
                        if (is_numeric($value) && abs((float) $value) > 0.0) {
                            $componentInputs[$code] = ($componentInputs[$code] ?? 0) + 1;
                        }
                    }
                }
                unset($month['rows']);
                $months[] = $month;
            }
            unset($month);
            $open = $converter->openPeriods($year);
            if ($open !== []) {
                $protocol->count(self::STEP_PREFLIGHT, 'open_months', count($open));
                $protocol->warn(self::STEP_PREFLIGHT, 'payroll_open_months',
                    self::openPeriodsMessage($open, $converter->exportedOn), ['open' => $open]);
            }
            // Údaje osob a vztahů se čtou jednou: krok měsíců z nich zapisuje pracoviště
            // průběžně a krok osob pak zbytek.
            $records = PohodaPayrollPeople::read($file, $year);
            // Podání z PAMICA (hlášení JMHZ, registrace) se čtou taky jednou: podmínky
            // vztahů z nich se doplňují po měsících, zbytek po údajích osob.
            $jmhz = PohodaPayrollJmhzWriter::read($file, $year);

            $protocol->begin(self::STEP_PROFILE);
            $profile = PohodaPayrollConverter::profile($months);
            $obstacleRate = PohodaPayrollConverter::obstacleEmployerRate($months);
            $existing = array_values(array_filter(
                $this->profiles->list($supplierId, AttendanceMeaning::SOURCE_SYSTEM),
                static fn (array $p): bool => $p['name'] === $profile['name'],
            ));
            $saved = $this->profiles->save(
                $supplierId,
                AttendanceMeaning::SOURCE_SYSTEM,
                isset($existing[0]['id']) ? (int) $existing[0]['id'] : null,
                $profile['name'],
                AttendanceRules::validate($profile['rules']),
                $userOrNull,
                AttendanceProfileComponents::validate($profile['components']),
            );
            $profileId = (int) ($saved['id'] ?? 0);
            $protocol->count(self::STEP_PROFILE, 'rules', count($profile['rules']));
            $protocol->count(self::STEP_PROFILE, 'components', count($profile['components']));
            // Složka bez zařazení do JMHZ zastaví zmrazení měsíčního hlášení. Zařazení, které
            // plyne z druhu složky, doplní aplikace sama při jejím založení; co z druhu neplyne
            // (přesčas, doplatky), převod nehádá a předá účetní se seznamem kódů a počty vstupů.
            $unclassified = [];
            foreach ($profile['components'] as $component) {
                // Složku výchozího číselníku zakládá číselník se svou klasifikací; druh
                // v profilu je jen náhradní. Osvobozený benefit zařazení do rozpadu nepotřebuje.
                // Plnění mimo hlášení (nezdaněná náhrada výdajů) zařazení nepotřebuje vůbec.
                $default = PayrollComponentDefaults::classification($component['code'])
                    ?? ['component_kind' => $component['kind'], 'frequency_kind' => 'one_off', 'tax_treatment' => 'included', 'jmhz_treatment' => 'included'];
                if (JmhzComponentSourceRule::issueCode($default['jmhz_treatment'], null, $default['tax_treatment'], $default['component_kind']) === null) {
                    continue;
                }
                if (PayrollComponentJmhzMappingDefaults::targetFor($component['code'], $default['component_kind'], $default['frequency_kind'], $default['tax_treatment']) === null) {
                    $unclassified[$component['code']] = 0;
                }
            }
            foreach ($unclassified as $code => $inputs) {
                $unclassified[$code] = $componentInputs[(string) $code] ?? 0;
            }
            if ($unclassified !== []) {
                arsort($unclassified);
                $list = [];
                foreach ($unclassified as $code => $inputs) {
                    $list[] = "{$code} ({$inputs} vstupů)";
                    $protocol->count(self::STEP_PROFILE, 'components_without_jmhz');
                }
                $protocol->warn(self::STEP_PROFILE, 'components_without_jmhz', sprintf(
                    'Mzdové složky bez zařazení do JMHZ: %s. Zařazení z druhu složky neplyne a převod ho '
                    . 'nehádá, protože chybná hodnota by prošla do hlášení tiše. U prémií a odměn rozhodněte, '
                    . 'zda se zúčtovávají pravidelně každý měsíc (10330), nebo nepravidelně (10331). Zařaďte je '
                    . 'v Mzdy → Mzdové složky, jinak nepůjde zmrazit měsíční hlášení.',
                    implode(', ', $list),
                ));
            }
            $protocol->finish(self::STEP_PROFILE);

            $protocol->begin(self::STEP_MONTHS);
            // Osoby a vztahy roku dřív než měsíce: druhý vztah téže osoby jde k ní, ne jako
            // nová osoba, a řádek měsíce pak najde svůj vztah podle osobního čísla.
            $sourceRelations = PohodaPayrollRelations::read($file, $year, $converter->exportedOn);
            $ensured = $this->relations->ensure($supplierId, $userOrNull, $sourceRelations,
                $protocol, self::STEP_MONTHS, self::MESSAGE_LIMIT);
            /** @var array<string,string> $sourceEnds osobní číslo => skončení vztahu podle zdroje */
            $sourceEnds = [];
            foreach ($sourceRelations as $sourceRelation) {
                if (is_string($sourceRelation['end'] ?? null)) {
                    $sourceEnds[mb_strtoupper((string) $sourceRelation['personal_number'])] = $sourceRelation['end'];
                }
            }
            foreach ($ensured as $name => $count) {
                if ($count > 0) {
                    $protocol->count(self::STEP_MONTHS, $name, $count);
                }
            }
            $done = $this->map->all($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH);
            /** @var array<string,int> $compensationBatches měsíc, který počítá MyÚčto => dávka docházky */
            $compensationBatches = [];
            $messages = 0;
            // Vynechané údaje osob stačí vypsat jednou, ne v každém měsíci.
            $omitted = [];
            foreach ($months as $month) {
                foreach ($month['omitted'] as $text) {
                    $omitted[$text] = true;
                }
            }
            foreach (array_keys($omitted) as $text) {
                $protocol->count(self::STEP_MONTHS, 'data_omitted');
                if ($messages++ < self::MESSAGE_LIMIT) {
                    $protocol->warn(self::STEP_MONTHS, 'person_data_omitted', ucfirst($text));
                }
            }
            // Srážka, jejíž druh v číselníku PAMICA není, se do sešitu nedostane: bez druhu
            // ji nejde odlišit od exekuce a tichá záměna by ji buď srazila dvakrát, nebo
            // vůbec. Vypíše se proto k ručnímu dořešení, stejně jako složky bez JMHZ.
            $unclassifiedDeductions = [];
            foreach ($months as $month) {
                foreach ($month['unclassified_deductions'] ?? [] as $key => $entry) {
                    $unclassifiedDeductions[$key] ??= ['code' => $entry['code'], 'name' => $entry['name'], 'inputs' => 0];
                    $unclassifiedDeductions[$key]['inputs'] += (int) $entry['inputs'];
                }
            }
            if ($unclassifiedDeductions !== []) {
                uasort($unclassifiedDeductions, static fn (array $a, array $b): int => $b['inputs'] <=> $a['inputs']);
                $protocol->count(self::STEP_MONTHS, 'deductions_without_kind', count($unclassifiedDeductions));
                $list = array_map(
                    static fn (array $e): string => trim($e['code'] . ' ' . $e['name']) . " ({$e['inputs']} vstupů)",
                    array_slice($unclassifiedDeductions, 0, self::MESSAGE_LIMIT),
                );
                $protocol->warn(self::STEP_MONTHS, 'deductions_without_kind', sprintf(
                    'Srážky bez druhu v číselníku PAMICA: %s. Do mzdových vstupů se nepřevedly, protože bez '
                    . 'druhu nejde poznat, jestli jde o dobrovolnou srážku, nebo o exekuci. Doplňte je ručně '
                    . 'v Mzdy → Vstupy, u exekucí v Mzdy → Exekuce a insolvence.',
                    implode(', ', $list),
                ));
            }
            // Položka katalogu, kterou převod nezná, by jinak zmizela beze stopy: chyběla by
            // ve mzdě, v souhrnu hodin i v hlášení, a nikdo by se to nedozvěděl.
            $unconverted = PohodaPayrollConverter::unconvertedItemsMessage($months, self::MESSAGE_LIMIT);
            if ($unconverted !== null) {
                $protocol->count(self::STEP_MONTHS, 'items_not_converted', count(array_unique(array_merge(
                    ...array_map(static fn (array $month): array => array_keys($month['unconverted_items'] ?? []), $months),
                ))));
                $protocol->warn(self::STEP_MONTHS, 'items_not_converted', $unconverted);
            }
            foreach ($periods as $index => $period) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $protocol->fail('cancelled');
                    break;
                }
                if ($progress !== null) {
                    $progress(self::STEP_MONTHS, $index, count($periods));
                }
                $month = $converter->month($period);
                $workbook = PohodaPayrollConverter::workbook($month);
                // Otisk dat měsíce, ne souboru: XLSX nese časová razítka a byl by pokaždé jiný.
                $key = $period . '|' . hash('sha256', (string) json_encode([$month['columns'], $month['rows']], JSON_UNESCAPED_UNICODE));
                // Mzda zúčtovaná po skončení vztahu podle zdroje (doplatek) nejde do vstupů
                // ani docházky. Rozhoduje skončení ze zdroje, ne stav vztahu ve firmě: převod
                // novějšího roku před starším by jinak doplatek zapsal k vztahu, který ještě
                // nemá zapsané skončení, a výsledek by závisel na pořadí let.
                $afterEnd = [];
                $kept = [];
                foreach ($month['rows'] as $row) {
                    $end = $sourceEnds[mb_strtoupper((string) ($row['Osobní číslo'] ?? ''))] ?? null;
                    if ($end !== null && $end < $period . '-01') {
                        $afterEnd[] = (string) $row['Osobní číslo'];
                        continue;
                    }
                    // Sjednanou mzdu vztahu, který skončil dřív, než MyÚčto mzdy počítá, převod
                    // nepřenáší (PayrollTakeoverEmploymentWriter::monthlyWage); sešit ji proto
                    // nenese ani tehdy, když skončení ještě není ve firmě zapsané.
                    if ($end !== null && !self::countedByModule(substr($end, 0, 7), $moduleStart) && array_key_exists('Měsíční mzda', $row)) {
                        $row['Měsíční mzda'] = '';
                        $wageCleared = true;
                    }
                    $kept[] = $row;
                }
                if ($afterEnd !== [] || ($wageCleared ?? false)) {
                    $wageCleared = false;
                    $month['rows'] = $kept;
                    $workbook = PohodaPayrollConverter::workbook($month);
                }
                if (isset($done[$key])) {
                    $protocol->count(self::STEP_MONTHS, 'existing');
                    // Měsíc, který už jednou prošel, se neimportuje znovu - ale schválení
                    // převzatých podkladů si uživatel mohl vyžádat až teď, takže se dodatečně
                    // dožene nad hotovou dávkou. Jinak by volba u dříve převedené firmy
                    // neudělala nic a běh by pořád stál na blokujících kontrolách.
                    if ($approveTakenOver) {
                        $this->approveTakenOverBatch($supplierId, $userOrNull, $period, $done[$key], $protocol);
                    }
                    if (self::countedByModule($period, $moduleStart)) {
                        $compensationBatches[$period] = (int) $done[$key];
                    }
                    continue;
                }
                // Dávky dřívějších převodů téhož měsíce (jiný otisk sešitu). U měsíce, který
                // počítá MyÚčto, by jejich schválené pracovní měsíce a vstupy nové dávce
                // překážely ({@see PayrollTakeoverRepeatedMonth}).
                $previousBatches = [];
                foreach ($done as $doneKey => $doneBatch) {
                    if (str_starts_with((string) $doneKey, $period . '|')) {
                        $previousBatches[] = (int) $doneBatch;
                    }
                }
                $repeated = $previousBatches !== [] && self::countedByModule($period, $moduleStart);
                if ($repeated) {
                    $locking = $this->repeatedMonth->lockingRun($supplierId, $period);
                    if ($locking !== null) {
                        $protocol->count(self::STEP_MONTHS, 'repeated_months_locked');
                        $protocol->warn(self::STEP_MONTHS, 'repeated_month_locked', sprintf(
                            '%s: převod měsíce se nezopakoval, protože mzdový běh už má zamčené vstupy (stav %s). '
                            . 'Neschválený běh zrušte v Mzdy → Mzdové běhy a převod spusťte znovu; schválený měsíc převod nepřepisuje.',
                            $period,
                            $locking['status'],
                        ), ['period' => $period, 'run_id' => $locking['id']]);
                        continue;
                    }
                    $this->repeatedMonth->reopenWorkMonths($supplierId, $period, $previousBatches, $userOrNull, 'PAMICA', $protocol, self::STEP_MONTHS);
                }
                foreach ($afterEnd as $number) {
                    $protocol->count(self::STEP_MONTHS, 'payslips_outside_employment');
                    if ($messages++ < self::MESSAGE_LIMIT) {
                        $protocol->warn(self::STEP_MONTHS, 'payslip_outside_employment', self::outsideEmploymentMessage($period, $number),
                            ['period' => $period, 'personal_number' => $number]);
                    }
                }
                if ($month['rows'] === []) {
                    continue;
                }
                try {
                    $preview = $this->attendance->preview($supplierId, $period, [$workbook], null, $profileId);
                    $created = 0;
                    // Řádek vztahu, který ve firmě je, ale v měsíci už neplatí (doplatek zúčtovaný
                    // po skončení), osobu nezakládá: byla by to druhá osoba téhož rodného čísla.
                    $toCreate = [];
                    foreach (self::personsToCreate($preview['persons'], $month) as $person) {
                        if ($person['personal_number'] !== null && $this->employmentExists($supplierId, $person['personal_number'])) {
                            $protocol->count(self::STEP_MONTHS, 'payslips_outside_employment');
                            if ($messages++ < self::MESSAGE_LIMIT) {
                                $protocol->warn(self::STEP_MONTHS, 'payslip_outside_employment',
                                    self::outsideEmploymentMessage($period, (string) $person['personal_number']),
                                    ['period' => $period, 'personal_number' => $person['personal_number']]);
                            }
                            continue;
                        }
                        $toCreate[] = $person;
                    }
                    foreach (array_chunk($toCreate, self::PERSON_CHUNK) as $chunk) {
                        $result = $this->attendance->persons($supplierId, $period, $chunk, $userOrNull, null, 'pohoda-import', [$workbook], null, $profileId);
                        foreach ($result['results'] as $item) {
                            if ($item['status'] === 'created') {
                                $created++;
                            } else {
                                $protocol->count(self::STEP_MONTHS, 'persons_failed');
                                if ($messages++ < self::MESSAGE_LIMIT) {
                                    $protocol->warn(self::STEP_MONTHS, 'person_failed', "{$period}: osobu se nepodařilo založit - {$item['message']}");
                                }
                            }
                        }
                    }
                    // Dohody o srážkách po měsících zakládá import jen za měsíce, které
                    // MyÚčto počítá. Měsíc zpracovaný předchozím programem je převzatý:
                    // žádný běh ho nepočítá a dohoda „za 3/2026" na kartě jen překáží
                    // (dřív jich tam bylo tolik, kolik převedených měsíců). Trvalou
                    // srážku pro další měsíce zapíše jednou krok srážek.
                    $applied = $this->attendance->apply(
                        $supplierId, $period, [$workbook], null, [], true, true, $userOrNull, null, true, $profileId,
                        false, true, true, false, false,
                        self::countedByModule($period, $moduleStart),
                    );
                    $skipped = count($applied['skipped'] ?? []);
                    $this->map->put($supplierId, PohodaImportRepository::KIND_PAYROLL_MONTH, $key, (int) ($applied['batch']['id'] ?? $applied['import_id'] ?? 0), $runId);
                    if ($repeated) {
                        $this->repeatedMonth->supersedeInputs($supplierId, $period, $previousBatches,
                            (int) ($applied['batch']['id'] ?? $applied['import_id'] ?? 0), 'PAMICA', $protocol, self::STEP_MONTHS);
                    }
                    if (self::countedByModule($period, $moduleStart)) {
                        $compensationBatches[$period] = (int) ($applied['batch']['id'] ?? $applied['import_id'] ?? 0);
                    }
                    // Pracoviště a CZ-ISCO ještě v tomhle měsíci, dokud je jeho verze podmínek
                    // ta poslední; další verze si je pak opíší. Po všech měsících už by je
                    // dostala jen verze poslední a starší měsíce by zůstaly bez pracoviště.
                    $this->people->writeWorkplaces($supplierId, $userOrNull, $records, $protocol, self::STEP_MONTHS);
                    $this->jmhz->monthTerms($supplierId, $userOrNull, $records, $jmhz, $period, $protocol, self::STEP_MONTHS);
                    // Schválení až po podmínkách z hlášení téhož měsíce: schválený pracovní měsíc
                    // si úvazek a fond zmrazí, a dřív by u prvního měsíce zůstal úvazek dosazený
                    // ze 40 h místo podaného (oprava by přišla až po schválení).
                    if ($approveTakenOver) {
                        $this->approveTakenOverBatch($supplierId, $userOrNull, $period, (int) ($applied['batch']['id'] ?? $applied['import_id'] ?? 0), $protocol);
                    }
                    $protocol->count(self::STEP_MONTHS, 'months');
                    $protocol->count(self::STEP_MONTHS, 'payslips', $month['totals']['rows']);
                    $protocol->count(self::STEP_MONTHS, 'persons_created', $created);
                    $protocol->count(self::STEP_MONTHS, 'skipped', $skipped);
                    $protocol->info(self::STEP_MONTHS, 'payroll_month', sprintf(
                        '%s: %d mezd, hrubá mzda %s Kč, čistá %s Kč; nově založeno osob %d%s.',
                        $period, $month['totals']['rows'], self::money($month['totals']['gross_minor']), self::money($month['totals']['net_minor']),
                        $created, $skipped > 0 ? ", přeskočeno řádků {$skipped}" : '',
                    ), ['period' => $period]);
                } catch (\InvalidArgumentException|\DomainException|PohodaException $e) {
                    $protocol->difference(self::STEP_MONTHS, 'payroll_month_failed', "{$period}: " . $e->getMessage(), ['period' => $period]);
                }
            }
            $protocol->finish(self::STEP_MONTHS);
            // Mzdy a položky roku už nikdo nečte, stačí den exportu.
            $exportedOn = $converter->exportedOn;
            unset($converter, $months, $month, $workbook);

            // Údaje osob a vztahů, které sešity měsíců nenesou (adresa, OIČ, skončení,
            // podaná hlášení, počáteční stavy). Až po mzdách: osoby a vztahy už existují.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_PEOPLE);
                if ($progress !== null) {
                    $progress(self::STEP_PEOPLE, 0, 1);
                }
                // Slevu pracujícího důchodce nese podané hlášení, ne karta.
                $records = PohodaPayrollPeople::withSubmittedDiscounts($records, $jmhz['effective'], $year);
                $this->people->write($supplierId, $userOrNull, $records, $year, $confirmIdentifiers, $protocol, self::STEP_PEOPLE,
                    PohodaPayrollPeople::institutions($file));
                [$sourceTotals, $sourceSkipped] = $this->storeReferenceTotals($supplierId, $file, $year, $protocol, $exportedOn);
                // Náhrady mzdy z hodin docházky až po osobách: stojí na průměrném výdělku,
                // který zapisuje teprve tenhle krok.
                // Souhrn měsíce nese dávku, která ho zapsala naposledy se změnou. Opakovaný
                // převod se změněným exportem založí novou dávku, ale nezměněné souhrny
                // zůstanou u dřívější; proto všechny dávky převodu za tentýž měsíc.
                foreach ($compensationBatches as $period => $batchId) {
                    $batches = [$batchId];
                    foreach ($done as $doneKey => $doneBatch) {
                        if (str_starts_with((string) $doneKey, $period . '|')) {
                            $batches[] = (int) $doneBatch;
                        }
                    }
                    $this->absenceCompensations($supplierId, $userOrNull, $period, array_values(array_unique($batches)), $obstacleRate, $approveTakenOver, $protocol);
                }
                $protocol->finish(self::STEP_PEOPLE);
            }

            // Odeslaná hlášení JMHZ a registrace z PAMICA: historie podání a doplnění toho,
            // co karty nenesou. Až po osobách: potřebuje párování vztahů a přepisovat nemá co.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_JMHZ);
                if ($progress !== null) {
                    $progress(self::STEP_JMHZ, 0, 1);
                }
                $this->jmhz->write($supplierId, $userOrNull, $file, $year, $records, $jmhz, $this->people->matchedRelations(),
                    $confirmIdentifiers, $protocol, self::STEP_JMHZ);
                // VS a kód OSSZ z přijatých registrací; import registrací podle VS ověřuje, že věty patří firmě.
                $this->employerIdentifiers($supplierId, $userOrNull, $jmhz['registrations'], $protocol);
                // Přijaté registrace produktovým importem: až po podmínkách z hlášení, věty jen doplňují.
                $this->submittedRegistrations($supplierId, $userOrNull, $jmhz['registrations'], $runId, $protocol);
                $protocol->finish(self::STEP_JMHZ);
            }
            // Podání a údaje osob jsou zapsané; další kroky čtou soubor samy.
            unset($jmhz, $records);

            // Trvalé srážky, exekuce a insolvence z karet zaměstnanců. Až po osobách:
            // exekuční případ i dohoda o srážkách visí na zaměstnanci, který už musí být
            // ve firmě založený.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_DEDUCTIONS);
                if ($progress !== null) {
                    $progress(self::STEP_DEDUCTIONS, 0, 1);
                }
                $this->deductions->write($supplierId, $userOrNull, PohodaPayrollDeductions::read($file, $year, $moduleStart), $year,
                    $protocol, self::STEP_DEDUCTIONS, $runId);
                $protocol->finish(self::STEP_DEDUCTIONS);
            }

            // Rozpracovaná neschopnost přes první měsíc vedení mezd. Až po osobách:
            // nepřítomnosti z převedených mezd už existují a tenhle krok jim jen dopíše
            // dny okna náhrady mzdy, které vyčerpal předchozí plátce. Bez nich by MyÚčto
            // začalo čtrnáctidenní okno počítat znovu od začátku.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_SICKNESS);
                if ($progress !== null) {
                    $progress(self::STEP_SICKNESS, 0, 1);
                }
                $this->sickness->write(
                    $supplierId,
                    $userOrNull,
                    PohodaPayrollSickness::read($file, $year, $this->sickness->startPeriod($supplierId)),
                    $protocol,
                    self::STEP_SICKNESS,
                );
                // Náhrada mzdy při DPN za dny od začátku vedení mezd. Až po zápisu případů:
                // okno § 192 ZP potřebuje dny vyčerpané předchozím plátcem.
                $sicknessStart = $this->sickness->startPeriod($supplierId);
                if ($sicknessStart !== null) {
                    $this->sicknessCompensation->compensate($supplierId, $sicknessStart, $userOrNull,
                        PohodaPayrollTakeover::policy(), $protocol, self::STEP_SICKNESS);
                }
                $protocol->finish(self::STEP_SICKNESS);
            }

            // Návrh kontací mezd z převzatého zaúčtování. Účetní zápisy se NEPŘENÁŠEJÍ:
            // mzdy zaúčtuje MyÚčto vlastní cestou a převzaté zápisy by proti převedeným
            // dokladům vyrobily duplicitu. Ukládá se jen návrh nastavení; do nastavení
            // zaměstnavatele sáhne teprve potvrzení účetní.
            if (!$protocol->failed()) {
                $protocol->begin(self::STEP_POSTING_MAP);
                if ($progress !== null) {
                    $progress(self::STEP_POSTING_MAP, 0, 1);
                }
                $stored = $this->postingMap->refresh(
                    $supplierId,
                    PohodaPayrollPostingMap::read($file, $year),
                    $year,
                    basename($file),
                );
                if ($stored !== null) {
                    $protocol->set('posting_map', $stored['proposal']);
                    $protocol->count(self::STEP_POSTING_MAP, 'posting_map_conflicts',
                        (int) ($stored['proposal']['summary']['conflict'] ?? 0));
                }
                $protocol->finish(self::STEP_POSTING_MAP);
            }

            // Brána G2: převzaté mzdy proti zdroji a vlastní data proti dvojím osobám
            // a překryvům verzí. Porušení je rozdíl k přijetí, ne tiché upozornění.
            if (!$protocol->failed()) {
                PayrollTakeoverInvariants::report($protocol, self::STEP_INVARIANTS,
                    $this->invariants->verify($supplierId, self::SOURCE, $sourceTotals ?? [], $sourceSkipped ?? 0, !$dryRun));
            }
        } finally {
            if ($savepoint) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_payroll_dry_run');
            } elseif ($dryRun && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
        return $protocol;
    }

    /** @param list<array<string,mixed>> $registrations */
    private function employerIdentifiers(int $supplierId, ?int $userId, array $registrations, ImportProtocol $protocol): void
    {
        $source = PohodaPayrollJmhzReports::employerIdentifiers($registrations);
        $result = $this->moduleSetup->fillEmployerIdentifiers($supplierId, $userId, $source['symbol'], $source['office'],
            'Převod mezd z PAMICA: VS z přijatých registrací ČSSZ, účinnost od začátku vedení mezd v MyÚčtu');
        $labels = ['symbol' => 'VS ČSSZ mzdové účtárny', 'registration' => 'registrační číslo zaměstnavatele', 'office' => 'kód OSSZ'];
        foreach ($result['filled'] as $key => $value) {
            $protocol->count(self::STEP_JMHZ, 'employer_' . $key . '_filled', 1);
            $protocol->info(self::STEP_JMHZ, 'employer_identifier_filled', sprintf('Z přijatých registrací PAMICA doplněno: %s %s.', $labels[$key], $value));
        }
        foreach ($result['conflicts'] as $key => $conflict) {
            $protocol->warn(self::STEP_JMHZ, 'employer_identifier_conflict', sprintf(
                'Nastavení mezd má %s %s, přijaté registrace PAMICA uvádějí %s. Hodnota se nepřepsala, ověřte ji v Mzdy → Nastavení.',
                $labels[$key], $conflict['current'], $conflict['source'],
            ), ['field' => $key]);
        }
        if ($source['ambiguous'] !== []) {
            $protocol->warn(self::STEP_JMHZ, 'employer_identifier_ambiguous',
                'Přijaté registrace PAMICA uvádějí víc různých hodnot VS nebo kódu OSSZ; nic se nedoplnilo, vyplňte je v Mzdy → Nastavení.');
        }
    }

    /**
     * Přijaté registrace ČSSZ z PAMICA převzaté produktovým importem ({@see PohodaPayrollRegistrations}).
     *
     * @param list<array<string,mixed>> $registrations
     */
    private function submittedRegistrations(int $supplierId, ?int $userId, array $registrations, ?int $runId, ImportProtocol $protocol): void
    {
        $result = $this->registrations->import($supplierId, $userId, $registrations, $runId);
        $counts = $result['counts'];
        foreach ($counts as $name => $count) {
            if ($count > 0) {
                $protocol->count(self::STEP_JMHZ, $name, $count);
            }
        }
        foreach (array_slice($result['problems'], 0, self::MESSAGE_LIMIT) as $problem) {
            $protocol->warn(self::STEP_JMHZ, $problem['code'], $problem['text'], $problem['context']);
        }
        if (count($result['problems']) > self::MESSAGE_LIMIT) {
            $protocol->warn(self::STEP_JMHZ, 'registrations_more', sprintf('Další nepřevzaté věty registrací: %d.', count($result['problems']) - self::MESSAGE_LIMIT));
        }
        if ($counts['registrations_sentences'] === 0) {
            return;
        }
        $protocol->info(self::STEP_JMHZ, 'registrations_imported', sprintf(
            'Registrace ČSSZ přijaté z PAMICA: vět %d, už dříve převzatých %d, zapsáno %d, beze změny %d, bez odpovídajícího vztahu %d, '
            . 'v rozporu s kartou %d, nezapsáno %d (zablokováno %d, selhalo %d). Neodeslaná nebo nepřijatá podání (%d) se nepřebírají.',
            $counts['registrations_sentences'],
            $counts['registrations_done'],
            $counts['registrations_applied'],
            $counts['registrations_unchanged'],
            $counts['registrations_unmatched'],
            $counts['registrations_differs'],
            $counts['registrations_blocked'] + $counts['registrations_failed'],
            $counts['registrations_blocked'],
            $counts['registrations_failed'],
            $counts['registrations_files_unaccepted'],
        ));
    }

    /**
     * Osoby z náhledu, které ve firmě nejsou: jméno a nástup ze sešitu, druh vztahu
     * a úvazek z náhledu. Rodné číslo, datum narození a pojišťovnu doplní import sám
     * ze sešitu.
     *
     * @param list<array<string,mixed>> $persons
     * @param array{period:string, rows:list<array<string,mixed>>} $month
     * @return list<array<string,mixed>>
     */
    private static function personsToCreate(array $persons, array $month): array
    {
        $rows = [];
        foreach ($month['rows'] as $row) {
            $rows[mb_strtoupper((string) $row['Osobní číslo'])] = $row;
        }
        $create = [];
        foreach ($persons as $person) {
            if (($person['match']['status'] ?? '') !== 'not_found') {
                continue;
            }
            $number = (string) ($person['personal_number'] ?? '');
            $row = $rows[mb_strtoupper($number)] ?? [];
            $first = (string) ($row['Jméno'] ?? '');
            $last = (string) ($row['Příjmení'] ?? '');
            $wage = (float) str_replace([' ', ','], ['', '.'], (string) ($person['monthly_wage'] ?? ''));
            $weekly = (string) ($person['weekly_hours'] ?? '');
            $create[] = [
                'person_key' => $person['key'],
                'full_name' => trim($first . ' ' . $last) ?: (string) ($person['display_name'] ?? ''),
                'first_name' => $first,
                'last_name' => $last,
                'birth_number' => null,
                'relation_type' => preg_match('/\bdpp\b/i', (string) ($person['relation_label'] ?? '')) === 1 ? 'dpp' : 'employment',
                'weekly_hours' => $weekly === '' ? null : str_replace(',', '.', $weekly),
                'monthly_gross' => $wage > 0 ? (int) round($wage) : null,
                'planned_start_on' => $person['start_on'] ?? ($row['_start'] ?? null) ?? $month['period'] . '-01',
                'personal_number' => $number === '' ? null : $number,
                'activate' => true,
            ];
        }
        return $create;
    }

    private function employmentExists(int $supplierId, string $code): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM payroll_employments WHERE supplier_id = ? AND code = ? LIMIT 1');
        $stmt->execute([$supplierId, $code]);

        return $stmt->fetchColumn() !== false;
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }

    /**
     * Schválení převzatých mzdových vstupů měsíce.
     *
     * Import zakládá vstupy jako koncepty, protože u ručně nahrané docházky je má
     * účetní projít. Tady ale jde o měsíc, který v PAMICA proběhl a je podaný -
     * konceptem by zablokoval mzdový běh kontrolou `draft_inputs_present`, kterou
     * nejde přebít výjimkou (ta je vyhrazená varováním). Schvaluje se výhradně
     * dávka tohoto importu, ne cokoli, co v měsíci leží z jiného zdroje.
     */
    /**
     * Dodatečné schválení měsíce, který už v MyÚčtu jednou prošel: docházka nad hotovou
     * dávkou a pak její mzdové vstupy. `$batchId` je dávka importu docházky z mapy převodu,
     * vstupy visí na vlastní dávce, kterou vrátí až služba importu.
     */
    private function approveTakenOverBatch(int $supplierId, ?int $userId, string $period, int $batchId, ImportProtocol $protocol): void
    {
        if ($batchId <= 0) {
            return;
        }
        try {
            $result = $this->attendance->approveTakenOverBatch($supplierId, $batchId, $userId);
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $protocol->warn(self::STEP_MONTHS, 'taken_over_approve_failed',
                "{$period}: převzatou docházku se nepodařilo dodatečně schválit - " . $e->getMessage(), ['period' => $period]);
            return;
        }
        $this->reportTimeApproval($period, $result['time'] ?? null, $protocol);
        $this->approveTakenOverInputs($supplierId, $userId, $period, (int) $result['input_import_id'], $protocol);
    }

    /**
     * Výsledek schválení převzaté docházky do protokolu — stejně u nového i už
     * převedeného měsíce.
     *
     * Dřív se počítal jen u opakovaného převodu a jen „nově schválené": u prvního
     * převodu protokol tvrdil 0 schválených měsíců, u opakovaného taky 0 (všechno
     * už schválené bylo), a měsíce, které schválit nešlo, zmizely beze slova.
     *
     * @param array<string,mixed>|null $time {@see \MyInvoice\Service\Payroll\Time\PayrollTimeImportApprovalService::applyBatch()}
     */
    private function reportTimeApproval(string $period, ?array $time, ImportProtocol $protocol): void
    {
        if ($time === null) {
            return;
        }
        $protocol->count(self::STEP_MONTHS, 'time_months_approved', (int) ($time['approved'] ?? 0));
        $protocol->count(self::STEP_MONTHS, 'time_months_already_approved', (int) ($time['already_approved'] ?? 0));
        $exceptions = is_array($time['exceptions'] ?? null) ? $time['exceptions'] : [];
        if ($exceptions === []) {
            return;
        }
        $protocol->count(self::STEP_MONTHS, 'time_months_not_approved', count($exceptions));
        $names = array_map(
            static fn (array $row): string => trim((string) ($row['name'] ?? '')) . ': ' . (string) ($row['message'] ?? ''),
            array_slice($exceptions, 0, 5),
        );
        $protocol->warn(self::STEP_MONTHS, 'time_months_not_approved', sprintf(
            '%s: docházku %d vztahů převod neschválil, protože souhrn měsíce nesedí (%s%s). Schvalte ji v Mzdy → Docházka a směny.',
            $period,
            count($exceptions),
            implode('; ', $names),
            count($exceptions) > 5 ? '; …' : '',
        ), ['period' => $period]);
    }

    /**
     * Náhrady mzdy za dovolenou, lékaře, placené volno a překážky na straně zaměstnavatele v měsíci, který
     * počítá MyÚčto. Sešit nese jen hodiny (náhradu v něm PAMICA nemá jako mzdovou složku),
     * takže bez tohoto kroku by běh vyplatil jen krácenou základní mzdu. Počítá se stejně jako
     * u importu docházky: hodiny × převzatý průměr × sazba, u překážek sazba, se kterou
     * počítala PAMICA. Převzatý měsíc (před začátkem vedení mezd) náhrady nedostává, jeho
     * hrubou mzdu nese PAMICA.
     */
    /** @param list<int> $batchIds */
    private function absenceCompensations(int $supplierId, ?int $userId, string $period, array $batchIds, ?int $obstacleRate, bool $approve, ImportProtocol $protocol): void
    {
        $missingAverage = 0;
        foreach ($batchIds as $batchId) {
            if ($batchId <= 0) {
                continue;
            }
            try {
                $report = $this->absenceCompensations->materializeFromBatch(
                    $supplierId,
                    $batchId,
                    $userId,
                    $obstacleRate === null ? null : ImportAbsenceCompensationRates::fromMap([AttendanceRules::RATE_MEANING => $obstacleRate]),
                    // Předpis měsíční mzdy dostane převod jen vztahu bez hodinové a úkolové
                    // mzdy ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter::recurringWage()}),
                    // takže vztah bez něj je ten, kterému PAMICA za svátek platila náhradu (`V02`).
                    holidayWithoutMonthlyWage: true,
                    // `V03` mimo lékaře je v PAMICA „Placené volno" a `V18` „Sick days"
                    // ({@see PohodaPayrollCatalog::absence()}): placené volno, za které PAMICA
                    // platí průměr (`KcPlacV`, u Sick days sazba `Hodnota9`, výchozí 100 %).
                    paidEmployeeObstacle: true,
                );
            } catch (\InvalidArgumentException|\DomainException $e) {
                $protocol->warn(self::STEP_PEOPLE, 'absence_compensation_failed',
                    "{$period}: náhrady mzdy z hodin nepřítomnosti se nepodařilo spočítat - " . $e->getMessage(), ['period' => $period]);
                continue;
            }
            $protocol->count(self::STEP_PEOPLE, 'absence_compensations', $report['created'] + $report['updated']);
            foreach ($report['skipped'] as $skipped) {
                if (str_starts_with($skipped['reason'], 'Chybí schválený průměrný výdělek')) {
                    $missingAverage++;
                }
            }
        }
        if ($missingAverage > 0) {
            $protocol->warn(self::STEP_PEOPLE, 'absence_compensation_without_average', sprintf(
                '%s: u %d nepřítomností chybí průměrný výdělek, náhrada mzdy se nespočítala. Doplňte průměr '
                . 'v Mzdy → Nepřítomnosti a náhradu zadejte v Mzdy → Vstupy.',
                $period,
                $missingAverage,
            ), ['period' => $period]);
        }
        if (!$approve) {
            return;
        }
        $statement = $this->db->pdo()->prepare(
            "SELECT id FROM payroll_inputs
              WHERE supplier_id = ? AND period_start = ? AND status = 'draft' AND source_kind = 'absence'
                AND external_id LIKE ?"
        );
        $statement->execute([
            $supplierId,
            $period . '-01',
            PayrollImportAbsenceCompensationMaterializer::EXTERNAL_ID_PREFIX . $period . ':%',
        ]);
        $ids = array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
        $approved = 0;
        foreach (array_chunk($ids, PayrollInputRepository::APPROVE_BATCH_MAX) as $chunk) {
            $approved += count($this->inputs->approveBatch($supplierId, $chunk, $userId)['approved']);
        }
        $protocol->count(self::STEP_PEOPLE, 'absence_compensations_approved', $approved);
    }

    private function approveTakenOverInputs(int $supplierId, ?int $userId, string $period, int $importId, ImportProtocol $protocol): void
    {
        if ($importId <= 0) {
            return;
        }
        $filter = new PayrollInputFilter($period . '-01', null, null, null, [], [], [], ['draft'], $importId);
        $approved = 0;
        $failed = 0;
        $afterId = 0;
        // Schvalování běží po dávkách s časovým rozpočtem; pokračuje se od posledního id.
        for ($guard = 0; $guard < 1000; $guard++) {
            $result = $this->inputs->approveByFilter($supplierId, $filter, $userId, $afterId);
            $approved += count($result['approved']);
            $failed += count($result['failed']);
            if ($result['complete'] || $result['next_after_id'] === $afterId) {
                break;
            }
            $afterId = (int) $result['next_after_id'];
        }
        $protocol->count(self::STEP_MONTHS, 'inputs_approved', $approved);
        // Kolik vstupů dávky už schválených bylo: u opakovaného převodu jinak
        // protokol hlásil „schváleno 0" a vypadalo to, že volba nic neudělala.
        $already = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_inputs
              WHERE supplier_id = ? AND import_id = ? AND status IN ("approved", "locked")'
        );
        $already->execute([$supplierId, $importId]);
        $protocol->count(self::STEP_MONTHS, 'inputs_already_approved', max(0, (int) $already->fetchColumn() - $approved));
        if ($failed > 0) {
            $protocol->warn(self::STEP_MONTHS, 'inputs_approve_failed',
                "{$period}: {$failed} převzatých mzdových vstupů se nepodařilo schválit, zůstávají jako koncept.", ['period' => $period]);
        }
    }

    /**
     * Úhrny zpracovaných mezd z PAMICA tak, jak je spočítal původní program.
     *
     * Bez nich nejde po přepočtu zjistit, jestli se MyÚčto trefilo do toho, co už bylo
     * podané - a právě to je jediná obrana proti tichému rozejití s hlášeními. Ukládají
     * se při převodu, ne až při generování sestavy: měsíce po převodu už export nikdo
     * po ruce nemá, a přesně tehdy se historický měsíc přepočítává.
     */
    /** @return array{0:list<PayrollMigrationReferenceTotals>,1:int} úhrny ze zdroje a mzdy, které se do nich nedostaly */
    private function storeReferenceTotals(int $supplierId, string $file, int $year, ImportProtocol $protocol, ?string $exportedOn): array
    {
        $matched = $this->people->matchedRelations();
        $totals = [];
        $skipped = 0;
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            if ((int) PohodaXml::text($mz, 'Rok') !== $year) {
                continue;
            }
            // Rozpracovaný měsíc se nepřebírá, takže nemá ani srovnávací úhrny:
            // počítá ho MyÚčto a pár mezd z předchozího programu by srovnání mátlo.
            if (PohodaPayrollConverter::openPeriod(sprintf('%04d-%02d', $year, (int) PohodaXml::text($mz, 'RelMes')), $exportedOn)) {
                continue;
            }
            $pair = $matched[PohodaXml::text($mz, 'RefPomer')] ?? null;
            try {
                $totals[] = PayrollMigrationReferenceTotals::fromPohodaMz(
                    $mz,
                    $year,
                    $pair['employee_id'] ?? null,
                    $pair['employment_id'] ?? null,
                    $pair['relation_type'] ?? null,
                    $pair['activity_code'] ?? null,
                );
            } catch (\InvalidArgumentException) {
                // Mzda bez platného měsíce nebo bez identifikace vztahu: do sestavy nepatří,
                // ale ani kvůli ní nemá padnout celý převod.
                $protocol->count(self::STEP_PEOPLE, 'reference_totals_skipped');
                $skipped++;
            }
        }
        if ($totals !== []) {
            $protocol->count(self::STEP_PEOPLE, 'reference_totals', $this->referenceTotals->store($supplierId, self::SOURCE, $totals, basename($file)));
        }

        return [$totals, $skipped];
    }

}
