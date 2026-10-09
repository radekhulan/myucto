<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\ImportWorkerMemory;

/**
 * Převod z POHODY na pozadí (`import_jobs.source = pohoda_import`, migrace 1844).
 *
 * Job dělá dvě věci: zpracuje nahraný ZIP exportu (`params.mode = prepare`: kontrolní
 * součet, rozbalení, přehled agend) a spustí převod vybraných roků (`params.years`
 * vzestupně, každý rok vlastní běh a protokol; {@see PohodaImporter}). Kostra jobu je
 * společná s ostatními převody ({@see AbstractImportJobService}).
 */
final class PohodaImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'pohoda_import';

    protected const LOG_PREFIX = 'POHODA';
    protected const UPLOAD_NOUN = 'exportu';
    protected const EXCEPTION_CLASS = PohodaException::class;
    protected const PREPARE_FAILED = 'Export z POHODY se nepodařilo přečíst.';
    protected const RUN_FAILED = 'Převod z POHODY selhal na neočekávané chybě, podrobnosti jsou v logu serveru.';
    protected const PREPARE_BUSY = 'Export už zpracovává jiný proces.';
    protected const UPLOAD_INCOMPLETE = 'Export není nahraný celý, nahrajte ho znovu.';
    protected const DEFAULT_FILE_NAME = 'pohoda_export.zip';
    protected const UPLOADED_EVENT = 'import.pohoda_uploaded';
    protected const PREPARE_STEPS = ['Kontrolní součet exportu', 'Rozbaluji export', 'Čtu agendy exportu'];

    protected const STEP_LABELS = [
        'chart' => 'Účtová osnova',
        'journal' => 'Účetní období a deník',
        'accounting_mode' => 'Režim účetní jednotky',
        'partners' => 'Adresář partnerů',
        'posting_rules' => 'Předkontace',
        'purchase_invoices' => 'Přijaté doklady',
        'issued_invoices' => 'Vydané doklady',
        'internal_tax_documents' => 'Daňové doklady k platbám',
        'cash' => 'Pokladna',
        'bank' => 'Banka',
        'link' => 'Vazby dokladů na deník',
        'payments' => 'Úhrady dokladů',
        'assets' => 'Dlouhodobý majetek',
        'payroll_preflight' => 'Kontrola před převodem mezd',
        'payroll_profile' => 'Profil importu mezd',
        'payroll_months' => 'Mzdy po měsících',
        'payroll_people' => 'Údaje osob a vztahů',
        'payroll_jmhz' => 'Podaná hlášení a registrace',
        'payroll_deductions' => 'Srážky, exekuce a insolvence',
        'payroll_sickness' => 'Nemocenská a náhrady mzdy',
        'payroll_posting_map' => 'Kontace z původního programu',
        'payroll_invariants' => 'Kontrola převzetí mezd',
        'small_assets' => 'Drobný majetek',
        'stock' => 'Sklad',
        'reconciliation' => 'Rekonciliace',
        'done' => 'Dokončuji',
    ];

    /**
     * Dopočítání přehledu rozbaleného exportu do `meta.json` (přehled mezd, pozdější roky
     * deníku). Spouští ho náhled průvodce u exportu nahraného dřív, než přehled tyhle
     * údaje nesl ({@see \MyInvoice\Action\Admin\Import\PohodaMigrationAction::metaHasFacts()}).
     * Nic nepřevádí a nebere zámek firmy, jen zámek exportu.
     */
    public const MODE_DESCRIBE = 'describe';
    private const DESCRIBE_STEP = 'Připravuji náhled exportu';

    public function __construct(
        ImportJobRepository $jobs,
        PohodaImportRepository $runs,
        private readonly PohodaImporter $importer,
        ActivityLogger $logger,
        private readonly PohodaPayrollImporter $payroll,
    ) {
        parent::__construct($jobs, $runs, $logger);
    }

    /** @param array<string,mixed> $job řádek import_jobs */
    public static function isDescribeJob(array $job): bool
    {
        return is_array($job['params'] ?? null) && ($job['params']['mode'] ?? null) === self::MODE_DESCRIBE;
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job !== null && self::isDescribeJob($job)) {
            if ($this->jobs->markRunning($jobId)) {
                ImportWorkerMemory::raise();
                $this->describe($jobId, $job, (int) $job['supplier_id']);
            }
            return;
        }
        parent::run($jobId);
    }

    /**
     * Přehled agend znovu z rozbalených dat; údaje nahrání (token, jméno, otisk, kdo a kdy)
     * v `meta.json` zůstávají. Chyba nechá export i původní přehled beze změny.
     *
     * @param array<string,mixed> $job
     */
    private function describe(int $jobId, array $job, int $supplierId): void
    {
        $token = (string) ($job['params']['token'] ?? '');
        $lock = null;
        try {
            $lock = PohodaUploads::acquireJobLock($supplierId, $token);
            if ($lock === null) {
                $this->jobs->markFailed($jobId, static::PREPARE_BUSY);
                return;
            }
            $meta = PohodaUploads::meta($supplierId, $token);
            $this->jobs->updateProgress($jobId, ['total_items' => 0, 'processed' => 0, 'current_step' => self::DESCRIBE_STEP]);
            $agendas = PohodaExport::overview(PohodaUploads::exportDir($supplierId, $token),
                function (string $agenda, int $index, int $total) use ($jobId): void {
                    $this->jobs->updateProgress($jobId, ['total_items' => $total, 'processed' => $index,
                        'current_step' => self::DESCRIBE_STEP . ' - agenda ' . $agenda]);
                });
            if ($agendas === []) {
                throw new PohodaException('no_agenda', 'Rozbalený export neobsahuje žádnou agendu. Nahrajte export znovu.');
            }
            $meta['agendas'] = $agendas;
            PohodaUploads::writeMeta($supplierId, $token, $meta);
            $this->jobs->updateProgress($jobId, ['total_items' => count($agendas), 'processed' => count($agendas), 'current_step' => 'Hotovo']);
            $this->jobs->appendLog($jobId, 'Náhled exportu z POHODY připraven (' . count($agendas) . ' agend).');
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $this->failureMessage($e, sprintf('náhled exportu %s firmy %d selhal', $token, $supplierId), static::PREPARE_FAILED));
        } finally {
            if ($lock !== null) {
                PohodaUploads::releaseJobLock($lock);
            }
        }
    }

    protected function uploads(): ChunkedUploadStore
    {
        return PohodaUploads::store();
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        $root = PohodaUploads::exportDir($supplierId, $token);
        PohodaExport::extractArchive($part, $root);
        return $root;
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        $agendas = PohodaExport::overview($extracted);
        if ($agendas === []) {
            throw new PohodaException('no_agenda', 'ZIP neobsahuje export agendy z POHODY (složku IČO_rok s účetním deníkem). Nahrajte ZIP, který vytvořil exportní nástroj.');
        }
        return [
            'meta' => ['agendas' => $agendas],
            'activity' => ['agendas' => array_map(static fn (array $a): string => $a['ico'] . '/' . $a['year'], $agendas)],
            'log' => 'Export z POHODY načten (' . count($agendas) . ' agend).',
        ];
    }

    /**
     * Vybrané roky jdou vzestupně, každý s vlastním záznamem běhu a protokolem
     * ({@see AbstractImportJobService::runYears()}).
     *
     * @param array<string,mixed> $job
     */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $userId = (int) ($job['created_by'] ?? 0);
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $token = (string) ($params['token'] ?? '');
        $dryRun = ($params['mode'] ?? '') !== 'import';
        // Mzdy jsou vlastní akce průvodce (i pro export, ve kterém jsou jen mzdy).
        $payroll = ($params['kind'] ?? '') === 'payroll';
        $ico = (string) ($params['ico'] ?? '');
        $lock = null;
        $purge = false;

        try {
            // Zámek exportu: denní úklid ani nové nahrání nesmaže export, ze kterého se převádí.
            $lock = $this->acquireRunUploadLock($supplierId, $token, 'Export právě zpracovává jiný proces, nebo už byl uklizen - nahrajte ho znovu.');
            $this->closeInterruptedRuns($jobId, $supplierId);
            $meta = PohodaUploads::meta($supplierId, $token);
            $plan = self::plan($meta, $ico, ImportYears::fromParams($params), $payroll, $supplierId, $token);
            $planYears = array_column($plan, 'year');
            $steps = $payroll ? PohodaPayrollImporter::stepKeys() : PohodaImporter::stepKeys();
            if ($payroll) {
                // Začátek vedení mezd firmě bez začátku nastaví úloha až za poslední měsíc
                // posledního vybraného roku. Začátek, který firma neměla před úlohou, nastavila
                // úloha sama: pozdější rok ho proto posune bez dotazu na rozhodnutí uživatele.
                $params['job_last_period'] = self::payrollLastPeriod($meta, $ico, $planYears, $supplierId, $token);
                if (!isset($params['start_decision']) && $this->payroll->startPeriod($supplierId) === null) {
                    $params['start_decision'] = PohodaPayrollImporter::START_ADVANCE;
                }
            }

            $status = $this->runYears($jobId, $planYears, $dryRun, $steps,
                function (int $index, array &$totals) use ($jobId, $params, $meta, $supplierId, $userId, $plan, $planYears, $steps): array {
                    return $this->runPlanYear($jobId, $params, $meta, $supplierId, $userId, $plan[$index], $index, $planYears, $steps, $totals);
                });
            // Export se po převodu smaže, jen když z něj nic nezbývá: prošly všechny agendy
            // firmy a žádná nemá druhou část (účetnictví / mzdy).
            $purge = !$dryRun && ($status === 'completed' || $status === 'completed_with_warnings')
                && self::nothingLeft($meta, $ico, $planYears, $payroll);
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $this->failureMessage($e, sprintf('převod exportu %s firmy %d selhal', $token, $supplierId), static::RUN_FAILED));
        } finally {
            if ($lock !== null) {
                PohodaUploads::releaseJobLock($lock);
            }
        }
        // Až po uvolnění zámku - otevřený soubor zámku by na Windows adresář nechal viset.
        if ($purge) {
            PohodaUploads::purge($supplierId, $token);
        }
    }

    /**
     * Převod jednoho roku (agendy) s vlastním záznamem běhu a protokolem.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $meta
     * @param array{year:int,skip:list<int>,later:list<int>} $item
     * @param list<int> $planYears
     * @param list<string> $steps
     * @param array{created:int,skipped:int,failed:int} $totals MĚNÍ SE: počty za celý job
     * @return array{status:string,year:int,run_id:?int,error:?string}
     */
    private function runPlanYear(int $jobId, array $params, array $meta, int $supplierId, int $userId, array $item, int $index, array $planYears, array $steps, array &$totals): array
    {
        return $this->runYear($jobId, $params, $supplierId, $index, $planYears, $steps, $totals,
            function () use ($jobId, $params, $meta, $supplierId, $userId, $item): array {
                $token = (string) ($params['token'] ?? '');
                $mode = ($params['mode'] ?? '') === 'import' ? 'import' : 'dry_run';
                $dryRun = $mode === 'dry_run';
                $payroll = ($params['kind'] ?? '') === 'payroll';
                $year = $item['year'];
                $agenda = self::agenda($meta, (string) ($params['ico'] ?? ''), $year);
                if ($agenda === null) {
                    throw new PohodaException('agenda_not_found', "Export neobsahuje agendu roku {$year} této firmy.");
                }
                $agendaDir = PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir'];
                if ($payroll && !$agenda['has_payroll']) {
                    throw new PohodaException('payroll_missing', "Export roku {$year} neobsahuje mzdy (91_mzdy.xml).");
                }
                if (!$payroll && !$agenda['has_accounting']) {
                    throw new PohodaException('accounting_missing', "Export roku {$year} obsahuje jen mzdy, účetnictví v něm není.");
                }
                $export = $payroll ? null : PohodaExport::open($agendaDir);
                $runId = $this->runs->startRun($supplierId, $jobId, $mode, [
                    'ico' => $export !== null ? $export->ico : $agenda['ico'],
                    'year' => $export !== null ? $export->year : $agenda['year'],
                    'program' => $export !== null ? $export->info['program'] : 'POHODA Mzdy',
                    'sha256' => $meta['sha256'] ?? null,
                ], $userId > 0 ? $userId : null);

                $included = array_values(array_diff($item['later'], $item['skip']));
                return [
                    'run_id' => $runId,
                    'log' => ($dryRun ? 'Zkouška nanečisto' : 'Ostrý převod') . ($payroll ? ' mezd' : ' agendy')
                        . " IČO {$agenda['ico']}, rok {$agenda['year']}"
                        . ($included !== [] ? ' (včetně dokladů roku ' . implode(', ', $included) . ')' : '')
                        . ($item['skip'] !== [] ? ', bez dokladů nevybraného roku ' . implode(', ', $item['skip']) : '') . '.',
                    'kind' => $payroll ? 'payroll' : 'accounting',
                    'import' => fn (?callable $progress, ?callable $cancel): object => $export === null
                        ? $this->payroll->run($supplierId, $userId, $agendaDir . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], (int) $agenda['year'], $dryRun, $runId, $progress, $cancel,
                            (bool) ($params['confirm_identifiers'] ?? false), (bool) ($params['approve_taken_over'] ?? false),
                            isset($params['start_decision']) ? (string) $params['start_decision'] : null, (bool) ($params['accept_differences'] ?? false),
                            isset($params['job_last_period']) ? (string) $params['job_last_period'] : null)
                        : $this->importer->run($supplierId, $userId, $export, $dryRun, $runId, $progress, $cancel, $item['skip'], (bool) ($params['accept_differences'] ?? false),
                            self::stockFor($meta, (string) ($params['ico'] ?? ''), $year, $params['stock'] ?? null)),
                    'journal' => static fn (array $byStep): array => $payroll
                        ? ['entries' => $byStep[PohodaPayrollImporter::STEP_MONTHS]['counts']['months'] ?? 0, 'existing' => $byStep[PohodaPayrollImporter::STEP_MONTHS]['counts']['existing'] ?? 0]
                        : $byStep['journal']['counts'] ?? [],
                ];
            });
    }

    /**
     * Plán převodu vybraných roků: agendy vzestupně a u každé roky po ní, které vede
     * (`later`) a které se nepřevádějí (`skip`). Rok bez vlastní agendy je jen pozdější rok
     * agendy (POHODA vede doklady i po 31. 12.) - převést ho jde jen s rokem té agendy.
     *
     * @param array<string,mixed> $meta
     * @param list<int> $years
     * @return list<array{year:int,skip:list<int>,later:list<int>}>
     * @throws PohodaException `invalid_year` / `invalid_kind` (kód 422)
     */
    public static function plan(array $meta, string $ico, array $years, bool $payroll, int $supplierId, string $token): array
    {
        if ($years === []) {
            throw new PohodaException('invalid_year', 'Vyberte aspoň jeden rok převodu.', [], 422);
        }
        $plan = [];
        $covered = [];
        foreach ($years as $year) {
            $agenda = self::agenda($meta, $ico, $year);
            if ($agenda === null) {
                continue;
            }
            if ($payroll ? !$agenda['has_payroll'] : !$agenda['has_accounting']) {
                throw new PohodaException('invalid_kind', $payroll
                    ? "Export roku {$year} neobsahuje mzdy (91_mzdy.xml)."
                    : "Export roku {$year} obsahuje jen mzdy, účetnictví v něm není.", ['year' => $year], 422);
            }
            $later = $payroll ? [] : self::laterYears($agenda, $supplierId, $token);
            $plan[] = ['year' => $year, 'skip' => array_values(array_diff($later, $years)), 'later' => $later];
            foreach ($later as $y) {
                $covered[$y] = true;
            }
        }
        foreach ($years as $year) {
            if (self::agenda($meta, $ico, $year) === null && !isset($covered[$year])) {
                throw new PohodaException('invalid_year', "Export neobsahuje agendu roku {$year} s IČO této firmy a vybraná agenda předchozího roku doklady roku {$year} nevede."
                    . ' Pozdější rok agendy jde převést jen spolu s rokem agendy.', ['year' => $year], 422);
            }
        }
        return $plan;
    }

    /**
     * Poslední uzavřený měsíc mezd přes všechny roky úlohy (`YYYY-MM`), nebo `null`. Bere ho
     * z přehledu exportu (`meta.json`); agendu, jejíž přehled je neúplný, přečte ze souboru.
     *
     * @param array<string,mixed> $meta
     * @param list<int> $years
     */
    public static function payrollLastPeriod(array $meta, string $ico, array $years, int $supplierId, string $token): ?string
    {
        $last = null;
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if (!in_array((int) ($a['year'] ?? 0), $years, true) || ($ico !== '' && (string) ($a['ico'] ?? '') !== $ico)
                || !(bool) ($a['has_payroll'] ?? false)
            ) {
                continue;
            }
            $summary = $a['payroll'] ?? null;
            if (!PohodaPayrollImporter::summaryComplete($summary)) {
                try {
                    $summary = PohodaPayrollImporter::summary(PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR
                        . (string) $a['dir'] . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], (int) $a['year']);
                } catch (PohodaException) {
                    continue;
                }
            }
            $last = PohodaPayrollImporter::setupLastPeriod($last, isset($summary['last_overall']) ? (string) $summary['last_overall'] : null);
        }
        return $last;
    }

    /**
     * Volba převodu skladu pro běh agendy `$year`. Stav skladu platí k datu exportu, proto
     * se převádí jen z nejnovější agendy účetnictví firmy v exportu; starší agenda by stav
     * založila podruhé a k jinému dni.
     *
     * @param array<string,mixed> $meta
     * @return array{warehouses:array<string,string>}|null
     */
    public static function stockFor(array $meta, string $ico, int $year, mixed $stock): ?array
    {
        if (!is_array($stock) || !is_array($stock['warehouses'] ?? null)) {
            return null;
        }
        $newest = null;
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if (($ico === '' || (string) ($a['ico'] ?? '') === $ico) && (bool) ($a['has_accounting'] ?? true)) {
                $newest = max($newest ?? 0, (int) ($a['year'] ?? 0));
            }
        }
        return $newest === $year ? ['warehouses' => $stock['warehouses']] : null;
    }

    /**
     * Roky po roce agendy z přehledu exportu; přehled nahraný před výběrem roků je nemá,
     * pak se čtou z deníku agendy.
     *
     * @param array{dir:string,later_years:?list<int>} $agenda
     * @return list<int>
     */
    public static function laterYears(array $agenda, int $supplierId, string $token): array
    {
        if ($agenda['later_years'] !== null) {
            return $agenda['later_years'];
        }
        return ChartJournalImporter::laterYears(PohodaExport::open(PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir']));
    }

    /**
     * Z exportu po převodu nic nezbývá: prošly všechny agendy firmy a žádná nemá druhou
     * část (účetnictví / mzdy), kterou převádí druhý průvodce.
     *
     * @param array<string,mixed> $meta
     * @param list<int> $done
     */
    private static function nothingLeft(array $meta, string $ico, array $done, bool $payroll): bool
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ($ico !== '' && (string) ($a['ico'] ?? '') !== $ico) {
                continue;
            }
            $agenda = self::agenda($meta, $ico, (int) ($a['year'] ?? 0));
            if ($agenda === null || !in_array($agenda['year'], $done, true) || ($payroll ? $agenda['has_accounting'] : $agenda['has_payroll'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Agenda zvoleného roku z přehledu nahraného exportu.
     *
     * @param array<string,mixed> $meta
     * @return array{dir:string,ico:string,year:int,has_accounting:bool,has_payroll:bool,later_years:?list<int>}|null
     */
    public static function agenda(array $meta, string $ico, int $year): ?array
    {
        foreach ((array) ($meta['agendas'] ?? []) as $a) {
            if ((int) ($a['year'] ?? 0) === $year && ($ico === '' || (string) ($a['ico'] ?? '') === $ico)) {
                $later = $a['counts']['later_years'] ?? null;
                return [
                    'dir' => (string) $a['dir'],
                    'ico' => (string) $a['ico'],
                    'year' => (int) $a['year'],
                    // Přehled nahraný před podporou mezd klíče nemá: to byla vždy agenda účetnictví.
                    'has_accounting' => (bool) ($a['has_accounting'] ?? true),
                    'has_payroll' => (bool) ($a['has_payroll'] ?? false),
                    // Přehled nahraný před výběrem roků je nemá ({@see laterYears()}).
                    'later_years' => is_array($later) ? array_values(array_map('intval', $later)) : null,
                ];
            }
        }
        return null;
    }
}
