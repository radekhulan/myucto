<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollDeferredIncomeRepository;
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
use MyInvoice\Service\Payroll\Calculation\PayrollWageRounding;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationModuleSetup;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotalsWriter;
use MyInvoice\Service\Payroll\Migration\PayrollPostingMapProposalService;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverAccidentInsuranceRate;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverRepeatedMonth;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverSicknessCompensation;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceImportService;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceProfileComponents;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceRules;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use MyInvoice\Service\Payroll\Component\PayrollRecurringMaterializer;
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
    /** Kód kontroly: firma bez začátku vedení mezd ho převodem dostane (kontext `start_period`). */
    public const START_WILL_SET = 'payroll_start_will_set';
    /** Kód kontroly: převod je omezený na rozsah měsíců (kontext `period_from`, `period_to`). */
    public const PERIOD_RANGE = 'payroll_period_range';
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
        private readonly PayrollRecurringMaterializer $recurring,
        private readonly PayrollComponentJmhzMappingRepository $jmhzMappings,
        private readonly PayrollTakeoverAccidentInsuranceRate $accidentRate,
        private readonly PayrollDeferredIncomeRepository $deferredIncomes,
        private readonly PayrollComponentRepository $components,
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

    /**
     * Poslední měsíc, za který převod nastavuje začátek vedení mezd: pozdější z posledního
     * měsíce převáděného roku a posledního měsíce celé úlohy. PAMICA i POHODA exportují
     * každý rok zvlášť, takže převod roku 2025 o mzdách 2026 neví; kdyby nastavil začátek
     * za prosinec 2025, převod roku 2026 v téže úloze by narazil na začátek před měsíci,
     * které předchozí program už zpracoval.
     */
    public static function setupLastPeriod(?string $yearLast, ?string $jobLast): ?string
    {
        if ($yearLast === null || $yearLast === '') {
            return $jobLast === null || $jobLast === '' ? null : $jobLast;
        }
        return $jobLast !== null && $jobLast > $yearLast ? $jobLast : $yearLast;
    }

    /** Začátek vedení mezd firmy (`YYYY-MM-DD`), nebo `null`. */
    public function startPeriod(int $supplierId): ?string
    {
        return $this->sickness->startPeriod($supplierId);
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

    /**
     * Nepřítomnosti z mezd měsíců mimo rozsah převodu se nezapisují: měsíc po konci rozsahu
     * počítá MyÚčto a jeho nepřítomnosti přijdou z docházky toho měsíce. Převzaté z dřívějška
     * by se s ní potkaly a doba by se krátila dvakrát.
     *
     * @param list<array<string,mixed>> $records {@see PohodaPayrollPeople::read()}
     * @return list<array<string,mixed>>
     */
    public static function recordsInRange(array $records, PohodaPayrollPeriodRange $range): array
    {
        if (!$range->limited()) {
            return $records;
        }
        foreach ($records as $index => $record) {
            $records[$index]['absences'] = array_values(array_filter(
                (array) ($record['absences'] ?? []),
                static fn (array $absence): bool => $range->contains((string) $absence['from']),
            ));
        }

        return $records;
    }

    private static function periodRangeMessage(PohodaPayrollPeriodRange $range): string
    {
        return sprintf(
            'Převedou se jen měsíce %s. Mzdy, srovnávací úhrny a podaná hlášení za ostatní měsíce exportu se nepřebírají; '
            . 'měsíce po konci rozsahu spočítá MyÚčto samo od začátku vedení mezd.',
            $range->label(),
        );
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
     * Úloha s víc roky předá `$jobLastPeriod`, poslední uzavřený měsíc přes všechny své roky:
     * začátek vedení mezd, který převod firmě bez začátku nastaví, se počítá od něj, ne od
     * posledního měsíce převáděného roku ({@see self::setupLastPeriod()}).
     *
     * Rozsah měsíců (`$range`) omezí poslední zpracovaný měsíc na svou horní mez
     * ({@see PohodaPayrollPeriodRange::capLast()}).
     *
     * @param array<string,mixed>|null $summary
     * @return list<array{level:string,code:string,message:string,context:array<string,mixed>}>
     */
    public function preflight(int $supplierId, string $file, int $year, ?array $summary = null, ?string $jobLastPeriod = null, ?PohodaPayrollPeriodRange $range = null): array
    {
        $range ??= PohodaPayrollPeriodRange::all();
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
        $last = $range->capLast(isset($summary['last_overall']) ? (string) $summary['last_overall'] : null);
        $setupLast = self::setupLastPeriod($last, $jobLastPeriod);
        $plan = $setupLast === null ? null : $this->moduleSetup->plan($supplierId, $setupLast);
        $willSetUp = ($plan['outcome'] ?? null) === PayrollMigrationModuleSetup::OUTCOME_READY;
        $stmt = $pdo->prepare('SELECT payroll_enabled FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            if ($willSetUp) {
                $add('info', 'payroll_module_will_enable', 'Firma nemá zapnutý modul Mzdy. Převod ho zapne.');
            } else {
                $add('error', 'payroll_disabled', 'Firma nemá zapnutý modul Mzdy. Zapněte ho v Nastavení → Moduly, jinak mzdy nejde převést.');
            }
        }
        // Náhled průvodce zná jen svůj rok; při víc vybraných rocích ukáže začátek podle
        // nejpozdějšího z nich (kontext `start_period`), tak jako ho nastaví úloha.
        if ($willSetUp && ($plan['start_period'] ?? null) !== null) {
            $add('info', self::START_WILL_SET, sprintf(
                'Firma nemá nastavený začátek vedení mezd v MyÚčtu. Převod ho nastaví na %s, měsíc po posledním měsíci zpracovaném předchozím programem (%s).',
                self::monthLabel((string) $plan['start_period']), self::monthLabel($setupLast),
            ), ['start_period' => $plan['start_period'], 'last' => $setupLast]);
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
        if ($range->limited()) {
            $add('info', self::PERIOD_RANGE, self::periodRangeMessage($range), $range->toParams());
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
     * @param ?string $jobLastPeriod poslední uzavřený měsíc přes všechny roky úlohy (`YYYY-MM`); firmě bez
     *        začátku vedení mezd ho převod nastaví až za něj ({@see self::setupLastPeriod()})
     * @param ?PohodaPayrollPeriodRange $range převáděné měsíce, `null` = všechny uzavřené
     */
    public function run(int $supplierId, int $userId, string $file, int $year, bool $dryRun, ?int $runId = null, ?callable $progress = null, ?callable $shouldCancel = null, bool $confirmIdentifiers = false, bool $approveTakenOver = false, ?string $startDecision = null, bool $acceptDifferences = false, ?string $jobLastPeriod = null, ?PohodaPayrollPeriodRange $range = null): ImportProtocol
    {
        $range ??= PohodaPayrollPeriodRange::all();
        $protocol = new ImportProtocol($dryRun ? 'dry_run' : 'import', $acceptDifferences);
        $protocol->set('kind', 'payroll');
        if ($range->limited()) {
            $protocol->set('period_range', $range->toParams());
        }
        $preflight = $this->preflight($supplierId, $file, $year, null, $jobLastPeriod, $range);
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
            $last = $range->capLast(self::lastPeriod($converter));
            if ($last !== null) {
                PayrollMigrationModuleSetup::report($protocol, self::STEP_PREFLIGHT,
                    $this->moduleSetup->ensure($supplierId, $userOrNull, (string) self::setupLastPeriod($last, $jobLastPeriod)), 'PAMICA');
                // PAMICA zaokrouhluje krácenou mzdu a náhrady matematicky; navazující měsíce
                // v MyÚčtu tak sedí s převzatými, dokud firma nepočítá vlastní běhy.
                if ($this->moduleSetup->adoptWageRounding($supplierId, PayrollWageRounding::HalfUp)) {
                    $protocol->info(self::STEP_PREFLIGHT, 'payroll_wage_rounding_adopted',
                        'Krácená mzda a náhrady mzdy se zaokrouhlují matematicky jako v PAMICA. Zákonné zaokrouhlení nahoru (§ 142 odst. 2 ZP) zapnete v Mzdy → Nastavení.');
                }
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
            $periods = array_values(array_filter($converter->closedPeriods($year), $range->contains(...)));
            $outside = array_values(array_diff($converter->closedPeriods($year), $periods));
            if ($outside !== []) {
                $protocol->count(self::STEP_PREFLIGHT, 'months_outside_range', count($outside));
                $protocol->info(self::STEP_PREFLIGHT, 'payroll_months_outside_range', sprintf(
                    'Mimo rozsah převodu %s zůstaly měsíce exportu: %s.',
                    $range->label(), implode(', ', array_map(self::monthLabel(...), $outside)),
                ), ['periods' => $outside] + $range->toParams());
            }
            $months = [];
            /** @var array<string,int> $componentInputs kód složky => vstupy s částkou */
            $componentInputs = [];
            /** @var array<string,array<string,int>> $componentMonths kód složky => osobní číslo => měsíce s částkou */
            $componentMonths = [];
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
                            $number = (string) ($row['Osobní číslo'] ?? '');
                            $componentMonths[$code][$number] = ($componentMonths[$code][$number] ?? 0) + 1;
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
            $records = self::recordsInRange(PohodaPayrollPeople::read($file, $year), $range);
            // Podání z PAMICA (hlášení JMHZ, registrace) se čtou taky jednou: podmínky
            // vztahů z nich se doplňují po měsících, zbytek po údajích osob.
            $jmhz = PohodaPayrollJmhzWriter::read($file, $year);
            // Hlášení za měsíc mimo rozsah podává MyÚčto (po horní mezi), nebo patří k měsíci,
            // který se tímto převodem nepřebírá.
            if ($range->limited()) {
                $jmhz['reports'] = array_values(array_filter($jmhz['reports'],
                    static fn (array $report): bool => $range->contains((string) $report['period'])));
                $jmhz['effective'] = PohodaPayrollJmhzReports::effective($jmhz['reports']);
            }

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
            // plyne z druhu složky, doplní aplikace sama při jejím založení; co z druhu neplyne,
            // převezme převod z hlášení, která PAMICA odeslala. Co nerozhodnou ani ta, převod
            // nehádá a předá účetní se seznamem kódů a počty vstupů.
            $unclassified = [];
            /** @var array<string,string> $bonusTargets kód složky => zařazení do JMHZ (z hlášení PAMICA nebo podle četnosti) */
            $bonusTargets = [];
            /** @var array<string,array{component_kind:string,frequency_kind:string,tax_treatment:string,jmhz_treatment:string}> $needsTarget */
            $needsTarget = [];
            /** @var array<string,string> $kindTargets vlastní složka PAMICA => zařazení odhadnuté z druhu */
            $kindTargets = [];
            foreach ($profile['components'] as $component) {
                // Složku výchozího číselníku zakládá číselník se svou klasifikací; druh
                // v profilu je jen náhradní. Osvobozený benefit zařazení do rozpadu nepotřebuje.
                // Plnění mimo hlášení (nezdaněná náhrada výdajů) zařazení nepotřebuje vůbec.
                $default = PayrollComponentDefaults::classification($component['code'])
                    ?? ['component_kind' => $component['kind'], 'frequency_kind' => 'one_off', 'tax_treatment' => 'included', 'jmhz_treatment' => 'included'];
                if (in_array($component['code'], PohodaPayrollCatalog::EXEMPT_BENEFITS, true)) {
                    $default = array_replace($default, array_intersect_key(PohodaPayrollCatalog::EXEMPT_BENEFIT_TREATMENT, $default));
                }
                if (JmhzComponentSourceRule::issueCode($default['jmhz_treatment'], null, $default['tax_treatment'], $default['component_kind']) === null) {
                    continue;
                }
                $kindTarget = PayrollComponentJmhzMappingDefaults::targetFor($component['code'], $default['component_kind'], $default['frequency_kind'], $default['tax_treatment']);
                if ($kindTarget === null) {
                    $needsTarget[$component['code']] = $default;
                } elseif (str_starts_with($component['code'], 'PAM_')
                    && $default['component_kind'] === 'premium'
                    && PayrollComponentJmhzMappingDefaults::targetForCode($component['code']) === null
                ) {
                    $kindTargets[$component['code']] = $kindTarget;
                }
            }
            // Zařazení, které z druhu neplyne, PAMICA použila v odeslaných hlášeních: kolonka,
            // do které částku složky započetla, je její zařazení ({@see PohodaPayrollJmhzTargets}).
            // U vlastních příplatků PAMICA je druh jen odhad z názvu položky, takže hlášení, která
            // PAMICA za ně odeslala, mají přednost i před zařazením podle druhu. Doplatek,
            // dorovnání a školení jsou mzda za práci záměrně, ty hlášení nepřeřadí.
            $reported = $needsTarget === [] && $kindTargets === [] ? [] : PohodaPayrollJmhzTargets::infer(
                $converter->itemAmounts(),
                PohodaPayrollJmhzTargets::reports($file, $year),
                array_map('strval', [...array_keys($needsTarget), ...array_keys($kindTargets)]),
            );
            /** @var array<string,string> $replaceKindTargets kód => zařazení podle druhu, které hlášení PAMICA vyvrátila */
            $replaceKindTargets = [];
            foreach ($kindTargets as $code => $kindTarget) {
                $code = (string) $code;
                if (isset($reported[$code]) && $reported[$code] !== $kindTarget) {
                    $bonusTargets[$code] = $reported[$code];
                    $replaceKindTargets[$code] = $kindTarget;
                }
            }
            foreach ($needsTarget as $code => $default) {
                $code = (string) $code;
                if (isset($reported[$code])) {
                    $bonusTargets[$code] = $reported[$code];
                    continue;
                }
                // Prémie a odměny, které hlášení nerozhodla: pravidelnost z druhu neplyne,
                // ale převod ji zná ze mzd PAMICA. Složka, kterou vztahy dostávaly skoro
                // každý měsíc, je pravidelně měsíčně zúčtovaná (10330), jinak nepravidelná (10331).
                if ($default['component_kind'] === 'bonus' && isset($componentMonths[$code])) {
                    $bonusTargets[$code] = self::bonusTarget($componentMonths[$code], count($periods));
                    continue;
                }
                $unclassified[$code] = 0;
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
            $this->classifyExemptBenefits($supplierId, $year, $profile['components'], $protocol);
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
                    if ($approveTakenOver && !self::countedByModule($period, $moduleStart)) {
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
                    // Úvazek měsíce ze mzdy PAMICA ještě před zápisem docházky: sjednaný fond
                    // měsíce se počítá z podmínek platných při zápisu. Pracoviště (doplňuje se
                    // do poslední verze podmínek) a oprava úvazku z podaného hlášení jdou až po něm.
                    $this->people->writeMonthWeeklyHours($supplierId, $userOrNull, $records, $period, $protocol, self::STEP_MONTHS);
                    $applied = $this->attendance->apply(
                        $supplierId, $period, [$workbook], null, [], true, true, $userOrNull, null, true, $profileId,
                        false, true, true, false, false,
                        self::countedByModule($period, $moduleStart),
                    );
                    $skipped = count($applied['skipped'] ?? []);
                    // Vstup, který brána odmítla, by jinak zmizel beze stopy (měsíc „ok", částka nikde).
                    foreach ($applied['inputs']['errors'] ?? [] as $error) {
                        $protocol->count(self::STEP_MONTHS, 'inputs_failed');
                        if ($messages++ < self::MESSAGE_LIMIT) {
                            $protocol->warn(self::STEP_MONTHS, 'input_failed', "{$period}: {$error['error_message']}", ['period' => $period]);
                        }
                    }
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
                    // ze 40 h místo podaného (oprava by přišla až po schválení). Měsíc, který
                    // počítá MyÚčto, se schvaluje až po náhradách při nemoci (krok nemocí):
                    // souhrn s nemocí bez zmrazené náhrady nejde zařadit a měsíc by zůstal
                    // neschválený (nástup v měsíci, kdy převod ještě neměl kalendář vztahu).
                    if ($approveTakenOver && !self::countedByModule($period, $moduleStart)) {
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
            // Složky odměn zakládá teprve zápis měsíců; zařazení do JMHZ až po něm.
            $this->classifyBonuses($supplierId, $userOrNull, $bonusTargets, $reported, $replaceKindTargets, $protocol);
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
                // Dny pracovní neschopnosti, karantény a ošetřování v měsících, které počítá MyÚčto,
                // naopak snižují minimum s doloženým důvodem: data má PAMICA, jen je měsíční sešit
                // nese jako hodiny, ze kterých výpočet dny snížení neodvodí.
                foreach ($records as $index => $record) {
                    $records[$index]['health_minimum_sickness'] = array_values(array_map(
                        static fn (array $absence): array => ['from' => (string) $absence['from'], 'to' => (string) $absence['to']],
                        array_filter(
                            (array) ($record['absences'] ?? []),
                            static fn (array $absence): bool => in_array($absence['type'], ['dpn', 'quarantine', 'ocr', 'long_term_care'], true)
                                && $moduleStart !== null && self::countedByModule(substr((string) $absence['from'], 0, 7), $moduleStart),
                        ),
                    ));
                }
                $this->people->write($supplierId, $userOrNull, $records, $year, $confirmIdentifiers, $protocol, self::STEP_PEOPLE,
                    PohodaPayrollPeople::institutions($file), PohodaPayrollPeople::cards($file, $year, $exportedOn));
                [$sourceTotals, $sourceSkipped] = $this->storeReferenceTotals($supplierId, $file, $year, $protocol, $exportedOn, $range);
                $this->accidentInsuranceRate($supplierId, $file, $year, $userOrNull, $protocol);
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
                // Docházka a vstupy měsíce, který počítá MyÚčto: až nad zmrazenými náhradami.
                if ($approveTakenOver) {
                    foreach ($compensationBatches as $period => $batchId) {
                        $this->approveTakenOverBatch($supplierId, $userOrNull, (string) $period, (int) $batchId, $protocol);
                    }
                }
                // Předpisy mzdy až po náhradách při nemoci: poměrnou část měsíční mzdy krácené
                // za nemoc jde doložit teprve nad zmrazeným výpočtem náhrady. Výslovné nuly až
                // po předpisech, ať nevzniknou tam, kde předpis mzdu doplnil.
                foreach (array_keys($compensationBatches) as $period) {
                    $this->recurringWages($supplierId, $userOrNull, (string) $period, $approveTakenOver, $protocol);
                    $this->zeroInputs($supplierId, $userOrNull, (string) $period, $protocol);
                    $this->deferredIncomes($supplierId, $userOrNull, (string) $period, $protocol);
                }
                // Pracovní měsíce schválené dřív, než převod zapsal nemoci, ošetřovné nebo
                // neomluvené absence, mají souhrn bez jejich hodin a evidenční list by měsíční
                // hlášení zastavil. Schválí se znovu z aktuálních zdrojů.
                if ($approveTakenOver) {
                    foreach ($compensationBatches as $period => $batchId) {
                        $this->refreshWorkSummaries($supplierId, $userOrNull, (string) $period, (int) $batchId, $protocol);
                    }
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

    private function refreshWorkSummaries(int $supplierId, ?int $userId, string $period, int $batchId, ImportProtocol $protocol): void
    {
        if ($batchId <= 0) {
            return;
        }
        try {
            $result = $this->attendance->refreshTakenOverBatch($supplierId, $batchId, $userId);
        } catch (\InvalidArgumentException|\DomainException|\RuntimeException $e) {
            $protocol->warn(self::STEP_SICKNESS, 'work_summary_refresh_failed', "{$period}: " . $e->getMessage(), ['period' => $period]);
            return;
        }
        if ($result['refreshed'] > 0) {
            $protocol->count(self::STEP_SICKNESS, 'time_months_refreshed', $result['refreshed']);
        }
        if ($result['exceptions'] !== []) {
            $protocol->warn(self::STEP_SICKNESS, 'work_summary_refresh_failed', sprintf(
                '%s: pracovní souhrn %d vztahů se po doplnění nepřítomností nepodařilo schválit znovu (první: %s). '
                . 'Měsíční hlášení u nich zastaví evidenční list; měsíc otevřete a schvalte v Mzdy → Pracovní doba.',
                $period, count($result['exceptions']), $result['exceptions'][0]['message'],
            ), ['period' => $period]);
        }
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
     * Předpisy mzdy (měsíční mzda a opakované složky) za měsíc, který počítá MyÚčto. Zapsal
     * je teprve krok osob, takže bez tohoto kroku by běh převedeného měsíce neměl základní
     * mzdu a účetní by předpisy musela vygenerovat a schválit ručně. Počítá se stejně jako
     * z obrazovky vstupů ({@see PayrollRecurringMaterializer}), poměrná část za nepřítomnost
     * z docházky převedeného měsíce.
     */
    private function recurringWages(int $supplierId, ?int $userId, string $period, bool $approve, ImportProtocol $protocol): void
    {
        try {
            $report = $this->recurring->materialize($supplierId, $period, $userId);
        } catch (\InvalidArgumentException|\DomainException $e) {
            $protocol->warn(self::STEP_SICKNESS, 'recurring_wages_failed',
                "{$period}: předpisy mzdy se nepodařilo vygenerovat - " . $e->getMessage(), ['period' => $period]);
            return;
        }
        $protocol->count(self::STEP_SICKNESS, 'recurring_inputs', (int) $report['created_count']);
        if ((int) $report['manual_review_count'] > 0) {
            $protocol->warn(self::STEP_SICKNESS, 'recurring_inputs_manual_review', sprintf(
                '%s: předpis mzdy %d vztahů nešlo spočítat (%s). Zkontrolujte je v Mzdy → Vstupy → Opakované složky.',
                $period,
                (int) $report['manual_review_count'],
                implode('; ', array_slice(array_map(
                    static fn (array $row): string => trim((string) ($row['full_name'] ?? '')) . ': ' . (string) $row['reason'],
                    $report['manual_review'],
                ), 0, 5)),
            ), ['period' => $period]);
        }
        if (!$approve) {
            return;
        }
        $ids = array_map(static fn (array $item): int => (int) $item['input_id'], [...$report['created'], ...$report['replayed']]);
        $approved = 0;
        foreach (array_chunk($ids, PayrollInputRepository::APPROVE_BATCH_MAX) as $chunk) {
            $approved += count($this->inputs->approveBatch($supplierId, $chunk, $userId)['approved']);
        }
        $protocol->count(self::STEP_SICKNESS, 'recurring_inputs_approved', $approved);
    }

    /**
     * Zařazení odměny do JMHZ podle četnosti ve mzdách PAMICA: průměrný podíl měsíců
     * s částkou u vztahů, které ji dostaly, aspoň tři čtvrtiny převáděných měsíců znamená
     * pravidelně měsíčně zúčtovanou odměnu (10330), jinak nepravidelnou (10331).
     *
     * @param array<string,int> $monthsByRelation osobní číslo => měsíce s částkou
     */
    public static function bonusTarget(array $monthsByRelation, int $periods): string
    {
        if ($monthsByRelation === [] || $periods <= 0) {
            return '10331';
        }
        $ratio = array_sum($monthsByRelation) / count($monthsByRelation) / $periods;

        return $ratio >= 0.75 ? '10330' : '10331';
    }

    /**
     * Benefity Z23/Z24, které PAMICA vedla bez daně a pojistného ({@see PohodaPayrollCatalog::EXEMPT_BENEFITS}).
     * Výchozí číselník je zakládá k ručnímu posouzení a vstupní brána takovou složku
     * odmítne, takže by se z převodu nedostal do vstupů ani koruna. Převod proto ještě
     * před měsíci zapíše verzím složky platným v převáděném roce zacházení, které
     * doložil zdroj; chybějící verzi roku založí stejně jako import docházky. Verzi,
     * o které už rozhodla účetní (není k ručnímu posouzení), nemění.
     *
     * @param list<array{code:string,name:string,kind:string}> $profileComponents
     */
    private function classifyExemptBenefits(int $supplierId, int $year, array $profileComponents, ImportProtocol $protocol): void
    {
        $from = $year . '-01-01';
        $to = $year . '-12-31';
        $versions = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $classified = [];
        foreach ($profileComponents as $component) {
            $code = $component['code'];
            if (!in_array($code, PohodaPayrollCatalog::EXEMPT_BENEFITS, true)) {
                continue;
            }
            try {
                $this->components->ensureDefaults($supplierId);
                $versions->execute([$supplierId, $code, $from, $from]);
                if ($versions->fetchAll(\PDO::FETCH_COLUMN) === []) {
                    $definition = array_replace(AttendanceProfileComponents::definition($component, $from), PohodaPayrollCatalog::EXEMPT_BENEFIT_TREATMENT);
                    $next = $this->components->nextVersionStart($supplierId, $code, $from);
                    if ($next !== null) {
                        $definition['valid_to'] = (new \DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d');
                    }
                    $this->components->create($supplierId, $definition);
                    $classified[$code] = true;
                }
                $versions->execute([$supplierId, $code, $to, $from]);
                foreach ($versions->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                    $current = $this->components->find($supplierId, (int) $id);
                    if ($current === null || $current['tax_treatment'] !== 'manual_review') {
                        continue;
                    }
                    $this->components->update($supplierId, (int) $id,
                        array_replace($current, PohodaPayrollCatalog::EXEMPT_BENEFIT_TREATMENT), (int) $current['row_version']);
                    $classified[$code] = true;
                }
            } catch (\InvalidArgumentException|\DomainException $e) {
                $protocol->warn(self::STEP_PROFILE, 'benefit_treatment_failed',
                    "{$code}: zacházení s benefitem se nepodařilo zapsat - " . $e->getMessage());
            }
        }
        if ($classified !== []) {
            $protocol->count(self::STEP_PROFILE, 'benefits_classified', count($classified));
            $protocol->info(self::STEP_PROFILE, 'benefits_classified', sprintf(
                'Benefity, které PAMICA vedla bez daně a pojistného, jsou osvobozené v ročním limitu: %s.',
                implode(', ', array_keys($classified)),
            ));
        }
    }

    /**
     * Zařazení složek do JMHZ, které z druhu neplyne: podle odeslaných hlášení PAMICA
     * ({@see PohodaPayrollJmhzTargets}), u odměn, které hlášení nerozhodla, podle četnosti
     * ({@see self::bonusTarget()}). Složku založí číselník při prvním použití, takže
     * chybějící se založí tady; zařazení, které už složka má (zadala ho účetní), převod nemění.
     * Výjimkou je zařazení, které aplikace sama odhadla z druhu vlastní složky PAMICA a které
     * hlášení PAMICA vyvrátila: to účetní nezadala, takže ho převod opraví.
     *
     * @param array<string,string> $targets kód složky => atribut JMHZ
     * @param array<string,string> $reported zařazení převzatá z hlášení
     * @param array<string,string> $replaceKindTargets kód složky => zařazení podle druhu, které se nahradí
     */
    private function classifyBonuses(int $supplierId, ?int $userId, array $targets, array $reported, array $replaceKindTargets, ImportProtocol $protocol): void
    {
        if ($targets === []) {
            return;
        }
        $find = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions WHERE supplier_id = ? AND code = ? ORDER BY valid_from DESC, id DESC LIMIT 1'
        );
        $setByApplication = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ? AND id = ? AND created_by IS NULL AND updated_by IS NULL'
        );
        $done = ['reports' => [], 'frequency' => []];
        foreach ($targets as $code => $target) {
            $find->execute([$supplierId, $code]);
            $componentId = $find->fetchColumn();
            if ($componentId === false) {
                continue;
            }
            try {
                $mapping = $this->jmhzMappings->find($supplierId, (int) $componentId);
                if ($mapping === null) {
                    $this->jmhzMappings->put($supplierId, (int) $componentId, $target, null, $userId);
                } elseif (isset($replaceKindTargets[$code])
                    && (bool) $mapping['is_active']
                    && ($mapping['is_current_package'] ?? false) === true
                    && (string) $mapping['target_attribute_id'] === $replaceKindTargets[$code]
                ) {
                    $setByApplication->execute([$supplierId, (int) $mapping['id']]);
                    if ($setByApplication->fetchColumn() === false) {
                        continue;
                    }
                    $this->jmhzMappings->put($supplierId, (int) $componentId, $target, (int) $mapping['row_version'], $userId);
                } elseif (isset($reported[$code])
                    && self::isFrequencyGuess($code, $mapping)
                    && (string) $mapping['target_attribute_id'] !== $target
                ) {
                    // Četnost odhadl dřívější převod roku bez odeslaných hlášení; hlášení
                    // PAMICA, která přišla s pozdějším rokem, jsou skutečné zařazení.
                    $this->jmhzMappings->put($supplierId, (int) $componentId, $target, (int) $mapping['row_version'], $userId);
                } elseif ((string) $mapping['target_attribute_id'] !== $target) {
                    continue;
                }
                $done[isset($reported[$code]) ? 'reports' : 'frequency'][] = $code . ' → ' . $target;
            } catch (\InvalidArgumentException|\DomainException|\OutOfBoundsException $e) {
                $protocol->warn(self::STEP_MONTHS, 'component_jmhz_failed', "{$code}: zařazení do JMHZ se nepodařilo zapsat - " . $e->getMessage());
            }
        }
        if ($done['reports'] !== []) {
            $protocol->count(self::STEP_MONTHS, 'components_jmhz_from_reports', count($done['reports']));
            $protocol->info(self::STEP_MONTHS, 'components_jmhz_from_reports', sprintf(
                'Složky zařazené do JMHZ tak, jak je PAMICA vykázala v odeslaných hlášeních: %s.',
                implode(', ', $done['reports']),
            ));
        }
        if ($done['frequency'] !== []) {
            $protocol->count(self::STEP_MONTHS, 'components_jmhz_by_frequency', count($done['frequency']));
            $protocol->info(self::STEP_MONTHS, 'components_jmhz_by_frequency', sprintf(
                'Odměny zařazené do JMHZ podle četnosti ve mzdách PAMICA (10330 pravidelné, 10331 nepravidelné): %s.',
                implode(', ', $done['frequency']),
            ));
        }
    }

    /**
     * Zařazení vlastní odměny PAMICA podle četnosti, jak ho založil převod: 10330/10331
     * a od založení nezměněné. Účetní, která ho upravila, zvýšila verzi řádku.
     *
     * @param array<string,mixed> $mapping
     */
    public static function isFrequencyGuess(string $code, array $mapping): bool
    {
        return str_starts_with($code, 'PAM_')
            && (bool) ($mapping['is_active'] ?? false)
            && ($mapping['is_current_package'] ?? false) === true
            && (int) ($mapping['row_version'] ?? 0) === 1
            && in_array((string) ($mapping['target_attribute_id'] ?? ''), ['10330', '10331'], true);
    }

    /**
     * Doplatek po skončení vztahu, který PAMICA zúčtovala v měsíci počítaném MyÚčtem:
     * převod potvrdí odložený příjem typu 1 (příjem po skončení zaměstnání), stejně jako
     * by ho potvrdila účetní na kartě vztahu. Bez potvrzení běh příjem odmítne
     * (`post_termination_income_attribution_unverified`), přestože ho předchozí program
     * zúčtoval a hlásil.
     */
    private function deferredIncomes(int $supplierId, ?int $userId, string $period, ImportProtocol $protocol): void
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT DISTINCT e.id
               FROM payroll_employments e
               JOIN payroll_inputs i ON i.supplier_id = e.supplier_id AND i.employment_id = e.id
              WHERE e.supplier_id = ? AND e.end_date IS NOT NULL AND e.end_date < ?
                AND i.period_start = ? AND i.status <> 'cancelled' AND i.amount_minor <> 0
                AND NOT EXISTS (SELECT 1 FROM payroll_employment_deferred_incomes d
                                 WHERE d.supplier_id = e.supplier_id AND d.employment_id = e.id
                                   AND d.period_start = i.period_start)"
        );
        $statement->execute([$supplierId, $period . '-01', $period . '-01']);
        $confirmed = 0;
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $employmentId) {
            try {
                $this->deferredIncomes->save($supplierId, (int) $employmentId, $period . '-01', '1',
                    'Převzato z PAMICA: příjem zúčtovaný po skončení zaměstnání.', $userId);
                $confirmed++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $protocol->warn(self::STEP_SICKNESS, 'deferred_income_failed',
                    "{$period}: odložený příjem vztahu {$employmentId} se nepodařilo potvrdit - " . $e->getMessage(), ['period' => $period]);
            }
        }
        if ($confirmed > 0) {
            $protocol->count(self::STEP_SICKNESS, 'deferred_incomes', $confirmed);
        }
    }

    /**
     * Výslovná nula u vztahu, kterému PAMICA za měsíc zpracovala mzdu bez příjmu (celý
     * měsíc nemoc, mateřská, neplacené volno) a v MyÚčtu mu žádný vstup nevznikl. Mzdový
     * běh trvající vztah bez vstupu odmítne, protože nulové hlášení JMHZ má vzniknout
     * z výslovně zadané nuly, ne z mlčení; podkladem nuly je tady zpracovaná mzda zdroje.
     */
    private function zeroInputs(int $supplierId, ?int $userId, string $period, ImportProtocol $protocol): void
    {
        $pdo = $this->db->pdo();
        $component = $pdo->prepare(
            "SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = 'MZDA_MESICNI' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1"
        );
        $component->execute([$supplierId, $period . '-01', $period . '-01']);
        $componentId = $component->fetchColumn();
        if ($componentId === false) {
            return;
        }
        $missing = $pdo->prepare(
            "SELECT DISTINCT ref.employee_id, ref.employment_id
               FROM payroll_migration_reference_totals ref
               JOIN payroll_employments e ON e.supplier_id = ref.supplier_id AND e.id = ref.employment_id
              WHERE ref.supplier_id = ? AND ref.source = ? AND ref.period_start = ? AND ref.employment_id IS NOT NULL
                -- Vztah skončený před měsícem nulu nepotřebuje: PAMICA mu zpracovala jen
                -- doplatek ze zúčtování, ne mzdu, a nula by z něj dělala příjem po skončení.
                AND (e.end_date IS NULL OR e.end_date >= ref.period_start)
                AND NOT EXISTS (SELECT 1 FROM payroll_inputs i
                                 WHERE i.supplier_id = ref.supplier_id AND i.employment_id = ref.employment_id
                                   AND i.period_start = ref.period_start AND i.status <> 'cancelled')"
        );
        $missing->execute([$supplierId, self::SOURCE, $period . '-01']);
        $created = 0;
        foreach ($missing->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            try {
                $this->inputs->createApproved($supplierId, [
                    'employee_id' => (int) $row['employee_id'],
                    'employment_id' => (int) $row['employment_id'],
                    'component_id' => (int) $componentId,
                    'period_start' => $period . '-01',
                    'source_period_start' => $period . '-01',
                    'amount_minor' => 0,
                    'quantity_milliunits' => null,
                    // Ruční vstup (dávkový `import` musí mít dávku importu); `external_id` drží
                    // idempotenci opakovaného převodu.
                    'source_kind' => 'manual',
                    'external_id' => 'pamica-zero:' . $period . ':' . $row['employment_id'],
                ], $userId);
                $created++;
            } catch (\InvalidArgumentException|\DomainException $e) {
                $protocol->warn(self::STEP_SICKNESS, 'zero_input_failed',
                    "{$period}: nulový vstup vztahu {$row['employment_id']} se nepodařilo založit - " . $e->getMessage(), ['period' => $period]);
            }
        }
        if ($created > 0) {
            $protocol->count(self::STEP_SICKNESS, 'zero_inputs', $created);
        }
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
    /**
     * Sazba zákonného pojištění odpovědnosti z plateb pojišťovně a převzatých základů
     * ({@see PayrollTakeoverAccidentInsuranceRate}). Bez ní by příprava plateb za
     * čtvrtletí, které už počítá MyÚčto, stála na chybějící sazbě.
     */
    private function accidentInsuranceRate(int $supplierId, string $file, int $year, ?int $userId, ImportProtocol $protocol): void
    {
        $payments = array_values(array_filter(
            PohodaPayrollPeople::statutoryInsurancePayments($file),
            static fn (array $payment): bool => (int) substr($payment['due_on'], 0, 4) === $year,
        ));
        $written = $this->accidentRate->apply($supplierId, $payments, $year, self::SOURCE, $userId);
        if ($written !== null) {
            $protocol->count(self::STEP_PEOPLE, 'accident_insurance_rate');
            $protocol->info(self::STEP_PEOPLE, 'accident_insurance_rate', sprintf(
                'Sazba zákonného pojištění odpovědnosti %s ‰ (%s) odvozená z %d plateb pojišťovně a převzatých '
                . 'vyměřovacích základů; platí od 1. 1. %d. Odpovídá-li jiná sazba výměru, upravte ji v Nastavení mezd.',
                str_replace('.', ',', $written['rate']), $written['code'], $written['payments'], $year,
            ));
        }
    }

    private function storeReferenceTotals(int $supplierId, string $file, int $year, ImportProtocol $protocol, ?string $exportedOn, PohodaPayrollPeriodRange $range): array
    {
        $matched = $this->people->matchedRelations();
        $totals = [];
        $skipped = 0;
        // Doplňkové údaje mzdy (`MZ2`, vazba `RefAg` = `MZ.ID`): příspěvky zaměstnavatele na
        // penzijní produkty pro roční koš osvobození ({@see PayrollMigrationTakeoverFacts::fromPohodaMz()}).
        $extra = [];
        foreach (PohodaXml::records($file, 'MZ2') as $row) {
            $extra[PohodaXml::text($row, 'RefAg')] = array_intersect_key($row, ['KcPDP' => true, 'KcDIP' => true]);
        }
        foreach (PohodaXml::records($file, 'MZ') as $mz) {
            $mz += $extra[PohodaXml::text($mz, 'ID')] ?? [];
            if ((int) PohodaXml::text($mz, 'Rok') !== $year) {
                continue;
            }
            // Rozpracovaný měsíc se nepřebírá, takže nemá ani srovnávací úhrny:
            // počítá ho MyÚčto a pár mezd z předchozího programu by srovnání mátlo.
            $period = sprintf('%04d-%02d', $year, (int) PohodaXml::text($mz, 'RelMes'));
            if (PohodaPayrollConverter::openPeriod($period, $exportedOn) || !$range->contains($period)) {
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
