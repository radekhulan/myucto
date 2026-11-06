<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Abra;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AbraImportRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;

/** Počáteční převod a následná read-only synchronizace ABRA Flexi na pozadí. */
final class AbraImportJobService extends AbstractImportJobService
{
    public const SOURCE = 'abra_flexi_import';
    public const MODE_INITIAL = 'initial';
    public const MODE_SYNC = 'sync';
    public const MODE_CATALOG = 'catalog';

    protected const LOG_PREFIX = 'ABRA Flexi';
    protected const UPLOAD_NOUN = 'online dat';
    protected const EXCEPTION_CLASS = AbraException::class;
    protected const RUN_FAILED = 'Převod z ABRA Flexi selhal na neočekávané chybě. Podrobnosti jsou v logu serveru.';
    protected const STEP_LABELS = [
        'ucetni-obdobi' => 'Účetní období',
        'ucetni-osnova' => 'Účtová osnova',
        'ucet' => 'Účty',
        'adresar' => 'Adresář partnerů',
        'faktura-vydana' => 'Vydané faktury',
        'faktura-prijata' => 'Přijaté faktury',
        'prodejka' => 'Prodejky',
        'zavazek' => 'Závazky',
        'pohledavka' => 'Pohledávky',
        'bankovni-ucet' => 'Bankovní účty',
        'majetek' => 'Majetek',
        'banka' => 'Bankovní doklady',
        'pokladni-pohyb' => 'Pokladní doklady',
        'vazba' => 'Vazby dokladů',
        'ucetni-denik' => 'Zaúčtovaný deník',
        'pohyb-na-uctech' => 'Pohyby na účtech',
        'stav-uctu' => 'Stavy účtů',
        'periods' => 'Zakládám účetní období',
        'chart' => 'Převádím účtovou osnovu',
        'documents' => 'Převádím dokladové snapshoty',
        'payments' => 'Převádím banku a pokladnu',
        'journal' => 'Převádím původní kontace',
        'links' => 'Páruji úhrady a kontroluji data',
    ];

    /** @var list<string> */
    private const STEPS = [
        'ucetni-obdobi', 'ucetni-osnova', 'ucet', 'adresar', 'faktura-vydana',
        'faktura-prijata', 'prodejka', 'zavazek', 'pohledavka', 'bankovni-ucet', 'majetek', 'banka', 'pokladni-pohyb', 'vazba', 'ucetni-denik',
        'pohyb-na-uctech', 'stav-uctu', 'periods', 'chart', 'documents', 'payments',
        'journal', 'links',
    ];

    public function __construct(
        Config $config,
        AbraImportRepository $runs,
        private readonly AbraConnectionService $connections,
        private readonly AbraSnapshotBuilder $snapshots,
        private readonly AbraSnapshotStore $snapshotStore,
        private readonly AbraAccountingImporter $importer,
        private readonly AbraCatalogImporter $catalogImporter,
        private readonly AbraStockOpeningImporter $stockOpeningImporter,
        private readonly AbraReadOnlyClient $catalogClient,
        ActivityLogger $logger,
    ) {
        $jobs = Connection::withoutSharedTestConnection(
            static fn (): ImportJobRepository => new ImportJobRepository(new Connection($config)),
        );
        parent::__construct($jobs, $runs, $logger);
    }

    protected function supportsPrepareJob(): bool
    {
        return false;
    }

    protected function uploads(): ChunkedUploadStore
    {
        throw new \LogicException('ABRA Flexi používá online read-only API, ne nahraný soubor.');
    }

    protected function extractUpload(string $part, int $supplierId, string $token): mixed
    {
        throw new \LogicException('ABRA Flexi nepodporuje zpracování nahraného souboru.');
    }

    protected function describeUpload(mixed $extracted, int $supplierId, string $token): array
    {
        throw new \LogicException('ABRA Flexi nepodporuje zpracování nahraného souboru.');
    }

    /** @param array<string,mixed> $job */
    protected function runLocked(int $jobId, array $job, int $supplierId): void
    {
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $mode = (string) ($params['mode'] ?? '');
        if ($mode === self::MODE_CATALOG) {
            $this->runCatalogLocked($jobId, $job, $supplierId, $params);
            return;
        }
        $userId = (int) ($job['created_by'] ?? 0);
        $runId = null;
        $purgeSnapshot = true;

        try {
            $this->snapshotStore->pruneStale($supplierId);
            if (!in_array($mode, [self::MODE_INITIAL, self::MODE_SYNC], true)) {
                throw new AbraException('invalid_mode', 'Neplatný režim převodu ABRA Flexi.');
            }
            $this->closeInterruptedRuns($jobId, $supplierId);
            $status = $this->connections->status($supplierId);
            $queuedVersion = filter_var($params['connection_version'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]);
            if (!is_int($queuedVersion) || $queuedVersion !== (int) ($status['connection_version'] ?? 0)) {
                throw new AbraException('connection_changed', 'Připojení ABRA Flexi se po zařazení úlohy změnilo. Spusťte převod znovu.');
            }
            $imported = (bool) ($status['imported'] ?? false);
            if (($mode === self::MODE_INITIAL && $imported) || ($mode === self::MODE_SYNC && !$imported)) {
                throw new AbraException('invalid_state', $mode === self::MODE_INITIAL
                    ? 'Počáteční převod již proběhl. Použijte synchronizaci.'
                    : 'Nejprve dokončete počáteční převod ABRA Flexi.');
            }
            $years = $mode === self::MODE_INITIAL
                ? ImportYears::fromParams($params)
                : self::statusYears($status);
            if ($years === []) {
                throw new AbraException('invalid_year', 'Vyberte aspoň jeden účetní rok.');
            }
            $company = is_array($status['source_company'] ?? null) ? $status['source_company'] : [];
            $syncState = is_array($status['sync_state'] ?? null) ? $status['sync_state'] : [];
            if ($mode === self::MODE_SYNC && !isset($syncState['vazba_cursor'])) {
                $syncState['vazba_cursor'] = $this->runs->maxMappedLinkId($supplierId);
            }
            $runId = $this->runs->startRun($supplierId, $jobId, 'import', [
                'year' => count($years) === 1 ? $years[0] : null,
            ], $userId > 0 ? $userId : null);

            $this->jobs->updateProgress($jobId, [
                'total_items' => count(self::STEPS),
                'processed' => 0,
                'current_step' => 'Připravuji bezpečné načtení ABRA Flexi',
            ]);
            $cancelled = $this->cancelCallback($jobId);
            $reportProgress = $this->progressCallback($jobId, self::STEPS, 0, null);
            $lastStep = '';
            $lastReportAt = 0.0;
            $progress = static function (string $step, int $done, int $all) use ($reportProgress, &$lastStep, &$lastReportAt): void {
                $now = microtime(true);
                if ($step !== $lastStep || $done === 0 || ($all > 0 && $done >= $all) || $now - $lastReportAt >= 1.0) {
                    $reportProgress($step, $done, $all);
                    $lastStep = $step;
                    $lastReportAt = $now;
                }
            };
            $purgeSnapshot = false;
            $this->snapshots->useImportedEndpointKeys($mode === self::MODE_SYNC
                ? $this->runs->importedEndpointKeys($supplierId) : []);
            $this->snapshots->usePageCache(new AbraPageCache($supplierId, $queuedVersion));
            $this->snapshots->capturePages(fn (string $evidence, array $query, int $start, array $payload)
                => $this->snapshotStore->writePage($supplierId, $jobId, $evidence, $query, $start, $payload));
            $snapshot = $this->snapshots->build(
                $this->connections->credentials($supplierId),
                $years,
                $mode,
                $syncState,
                $company,
                $progress,
                $cancelled,
            );
            $purgeSnapshot = false;
            if ($cancelled()) {
                unset($snapshot);
                $purgeSnapshot = true;
                $this->finishCancelled($jobId, $runId, $supplierId, $mode);
                return;
            }

            $report = $this->importer->import($supplierId, $userId, $snapshot, $years, $progress, $cancelled);
            $meta = is_array($snapshot['_meta'] ?? null) ? $snapshot['_meta'] : [];
            unset($snapshot);
            $report = self::normalizeReport($report);

            if ($report['cancelled']) {
                $purgeSnapshot = true;
                $this->finishCancelled($jobId, $runId, $supplierId, $mode, $report);
                return;
            }

            $sourceWarnings = is_array($meta['warnings'] ?? null) ? count($meta['warnings']) : 0;
            $warningCount = count($report['warnings']) + $sourceWarnings;
            $resolved = !$report['blocked'] && $report['changed'] === 0 && $report['failed'] === 0;
            $protocolStatus = !$resolved
                ? 'failed'
                : ($warningCount > 0 ? 'completed_with_warnings' : 'completed');
            $protocol = self::protocol($mode, $protocolStatus, $report, $meta, $warningCount);
            $this->runs->finishRun($runId, $supplierId, $protocolStatus, $protocol);

            $this->jobs->updateProgress($jobId, [
                'processed' => count(self::STEPS),
                'created_count' => $report['created'],
                'skipped_count' => $report['skipped'],
                'failed_count' => $report['failed'] + $report['changed'],
                'current_step' => $resolved ? 'Hotovo' : 'Zastaveno kvůli nevyřešeným změnám',
            ]);
            $this->jobs->appendLog($jobId, sprintf(
                'Protokol #%d: %d vytvořeno, %d beze změny, %d konfliktů, %d upozornění.',
                $runId,
                $report['created'],
                $report['skipped'],
                $report['changed'],
                $warningCount,
            ));

            if ($resolved) {
                $nextState = is_array($meta['sync_state'] ?? null) ? $meta['sync_state'] : [];
                $nextState['warnings'] = self::safeWarnings([
                    ...(array) ($meta['warnings'] ?? []),
                    ...$report['warnings'],
                ]);
                $this->connections->markSynced($supplierId, $years, $nextState);
                $purgeSnapshot = true;
            }
            $this->finishJob($jobId, $protocolStatus,
                'Převod obsahuje nevyřešené změny nebo neprošel kontrolami. Podrobnosti jsou v protokolu.');
        } catch (AbraException $e) {
            if ($e->errorCode === 'cancelled' || $this->jobs->isCancelRequested($jobId)) {
                $purgeSnapshot = true;
                if ($runId !== null) {
                    $this->finishCancelled($jobId, $runId, $supplierId, $mode);
                } else {
                    $this->jobs->markCancelled($jobId);
                }
                return;
            }
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', [
                    'mode' => $mode,
                    'status' => 'failed',
                    'failure' => 'source',
                    'error' => mb_substr($e->getMessage(), 0, 300),
                    'steps' => [],
                ]);
            }
            $this->jobs->markFailed($jobId, $e->getMessage());
        } catch (\Throwable $e) {
            error_log(static::LOG_PREFIX . ': neočekávaná výjimka ' . $e::class);
            $message = static::RUN_FAILED;
            if ($runId !== null) {
                $this->runs->finishRun($runId, $supplierId, 'failed', [
                    'mode' => $mode,
                    'status' => 'failed',
                    'failure' => 'unexpected',
                    'error' => $message,
                    'steps' => [],
                ]);
            }
            $this->jobs->markFailed($jobId, $message);
        } finally {
            $this->snapshots->useImportedEndpointKeys([]);
            $this->snapshots->capturePages(null);
            $this->snapshots->usePageCache(null);
            if ($purgeSnapshot) {
                $this->snapshotStore->purge($supplierId, $jobId);
            }
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $params */
    private function runCatalogLocked(int $jobId, array $job, int $supplierId, array $params): void
    {
        $runId = null;
        $report = ['created' => 0, 'skipped' => 0, 'changed' => 0, 'failed' => 0, 'warnings' => []];
        $warningSet = [];
        $processed = 0;
        $lastCancelAt = 0.0;
        $cancelState = false;
        $cancelled = function () use ($jobId, &$lastCancelAt, &$cancelState): bool {
            $now = microtime(true);
            if ($now - $lastCancelAt >= 1.0) {
                $cancelState = $this->jobs->isCancelRequested($jobId);
                $lastCancelAt = $now;
            }
            return $cancelState;
        };
        try {
            $status = $this->connections->status($supplierId);
            if (!$status['imported'] || (int) ($params['connection_version'] ?? 0) !== $status['connection_version']) {
                throw new AbraException('invalid_state', 'Účetní převod nebo připojení ABRA Flexi se změnilo. Spusťte ceník znovu.');
            }
            $runId = $this->runs->startRun($supplierId, $jobId, 'import', [], (int) ($job['created_by'] ?? 0) ?: null);
            $this->jobs->updateProgress($jobId, ['processed' => 0, 'total_items' => 0, 'current_step' => 'Načítám ceník']);
            $this->catalogClient->usePageCache(new AbraPageCache($supplierId, $status['connection_version']));
            $this->catalogClient->scan($this->connections->credentials($supplierId), 'cenik', [],
                function (array $rows, int $start, ?int $total) use ($supplierId, $cancelled, $jobId, &$report, &$warningSet, &$processed): void {
                    $page = $this->catalogImporter->importPage($supplierId, $rows, $cancelled);
                    foreach (['created', 'skipped', 'changed', 'failed'] as $key) $report[$key] += $page[$key];
                    foreach ($page['warnings'] as $warning) $warningSet[$warning] = true;
                    $processed = $start + count($rows);
                    $this->jobs->updateProgress($jobId, ['processed' => $processed, 'total_items' => $total ?? $processed,
                        'created_count' => $report['created'], 'skipped_count' => $report['skipped'],
                        'failed_count' => $report['failed'] + $report['changed'],
                        'current_step' => 'Ceník ' . $processed . '/' . ($total ?? $processed)]);
                }, null, $cancelled);
            $stockProcessed = 0;
            $stockDocuments = 0;
            if ($report['failed'] === 0 && $report['changed'] === 0) {
                $this->jobs->updateProgress($jobId, ['current_step' => 'Načítám aktuální stav skladu']);
                $warehouses = $this->catalogClient->list($this->connections->credentials($supplierId),
                    'sklad', ['detail' => 'custom:id,kod'], null, $cancelled);
                $this->stockOpeningImporter->prepareSourceWarehouse($supplierId, $warehouses);
                unset($warehouses);
                $this->catalogClient->scan($this->connections->credentials($supplierId), 'skladova-karta', [],
                    function (array $rows, int $start, ?int $total) use ($supplierId, $job, $cancelled, $jobId,
                        &$report, &$warningSet, &$stockProcessed, &$stockDocuments, $processed): void {
                        $page = $this->stockOpeningImporter->importPage($supplierId, (int) ($job['created_by'] ?? 0),
                            $rows, $cancelled);
                        foreach (['created', 'skipped', 'changed', 'failed'] as $key) $report[$key] += $page[$key];
                        foreach ($page['warnings'] as $warning) $warningSet[$warning] = true;
                        $stockDocuments += $page['documents'];
                        $stockProcessed = $start + count($rows);
                        $this->jobs->updateProgress($jobId, ['processed' => $processed + $stockProcessed,
                            'total_items' => $processed + ($total ?? $stockProcessed),
                            'created_count' => $report['created'], 'skipped_count' => $report['skipped'],
                            'failed_count' => $report['failed'] + $report['changed'],
                            'current_step' => 'Sklad ' . $stockProcessed . '/' . ($total ?? $stockProcessed)]);
                    }, null, $cancelled);
            }
            $report['warnings'] = array_keys($warningSet);
            $complete = $report['failed'] === 0 && $report['changed'] === 0;
            $runStatus = self::catalogRunStatus($complete, $report['warnings']);
            $this->runs->finishRun($runId, $supplierId, $runStatus, ['mode' => self::MODE_CATALOG,
                'status' => $runStatus, 'counts' => [
                    'created' => $report['created'], 'skipped' => $report['skipped'],
                    'changed' => $report['changed'], 'failed' => $report['failed'], 'source_rows' => $processed,
                    'stock_source_rows' => $stockProcessed, 'stock_documents' => $stockDocuments,
                ], 'warnings' => $report['warnings'], 'blocked' => !$complete]);
            if ($complete) $this->connections->markCatalogSynced($supplierId, $processed);
            $this->jobs->updateProgress($jobId, ['current_step' => $complete ? 'Ceník načten' : 'Ceník vyžaduje kontrolu']);
            $this->finishJob($jobId, $runStatus, 'Ceník nebo stav skladu obsahuje chyby či nevyřešené změny.');
        } catch (AbraException $error) {
            $cancelledNow = $error->errorCode === 'cancelled' || $cancelled();
            $status = $cancelledNow ? 'cancelled' : 'failed';
            if ($runId !== null) $this->runs->finishRun($runId, $supplierId, $status, ['mode' => self::MODE_CATALOG,
                'status' => $status, 'counts' => ['created' => $report['created'], 'source_rows' => $processed],
                'warnings' => [$error->errorCode], 'blocked' => true]);
            $cancelledNow ? $this->jobs->markCancelled($jobId) : $this->jobs->markFailed($jobId, $error->getMessage());
        } catch (\Throwable $error) {
            error_log('ABRA catalog importer: ' . $error::class);
            if ($runId !== null) $this->runs->finishRun($runId, $supplierId, 'failed', [
                'mode' => self::MODE_CATALOG, 'status' => 'failed', 'failure' => 'unexpected',
                'counts' => ['created' => $report['created'], 'source_rows' => $processed]]);
            $this->jobs->markFailed($jobId, 'Ceník ABRA Flexi se nepodařilo dokončit.');
        } finally {
            $this->catalogClient->usePageCache(null);
        }
    }

    /** @param array<string,mixed>|null $report */
    private function finishCancelled(int $jobId, int $runId, int $supplierId, string $mode, ?array $report = null): void
    {
        $safe = $report !== null ? self::normalizeReport($report) : self::normalizeReport([]);
        $this->runs->finishRun($runId, $supplierId, 'cancelled', [
            'mode' => $mode,
            'status' => 'cancelled',
            'failure' => 'cancelled',
            'steps' => [],
            'counts' => $safe['counts'],
        ]);
        $this->jobs->updateProgress($jobId, ['current_step' => 'Zrušeno uživatelem']);
        $this->jobs->markCancelled($jobId);
    }

    /** @param array<string,mixed> $status @return list<int> */
    private static function statusYears(array $status): array
    {
        return ImportYears::fromParams(['years' => is_array($status['selected_years'] ?? null)
            ? $status['selected_years']
            : []]);
    }

    /**
     * @param array<string,mixed> $report
     * @return array{created:int,skipped:int,changed:int,failed:int,blocked:bool,cancelled:bool,
     *     warnings:list<string>,counts:array<string,int>,reconciliation:?array{ok:bool,years:list<array{year:int,ok:bool}>,warnings:list<string>}}
     */
    private static function normalizeReport(array $report): array
    {
        $counts = [];
        foreach ((array) ($report['counts'] ?? []) as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $key) === 1 && is_numeric($value)) {
                $counts[$key] = max(0, (int) $value);
            }
        }
        return [
            'created' => max(0, (int) ($report['created'] ?? 0)),
            'skipped' => max(0, (int) ($report['skipped'] ?? 0)),
            'changed' => max(0, (int) ($report['changed'] ?? 0)),
            'failed' => max(0, (int) ($report['failed'] ?? 0)),
            'blocked' => (bool) ($report['blocked'] ?? false),
            'cancelled' => (bool) ($report['cancelled'] ?? false),
            'warnings' => self::safeWarnings($report['warnings'] ?? []),
            'counts' => $counts,
            'reconciliation' => self::safeReconciliation($report['reconciliation'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private static function protocol(string $mode, string $status, array $report, array $meta, int $warningCount): array
    {
        $protocol = [
            'mode' => $mode,
            'status' => $status,
            'failure' => null,
            'steps' => [],
            'counts' => $report['counts'] + [
                'created' => $report['created'],
                'skipped' => $report['skipped'],
                'changed' => $report['changed'],
                'failed' => $report['failed'],
                'warnings' => $warningCount,
            ],
            'blocked' => $report['blocked'],
            'warnings' => self::safeWarnings([
                ...(array) ($meta['warnings'] ?? []),
                ...$report['warnings'],
            ]),
            'sync' => [
                'strategy' => is_array($meta['sync_state'] ?? null) ? (string) ($meta['sync_state']['strategy'] ?? '') : '',
                'deletions' => array_sum(array_map('intval', (array) ($meta['deletions'] ?? []))),
                'unhandled_changes' => array_sum(array_map('intval', (array) ($meta['unhandled_changes'] ?? []))),
                'unavailable_evidences' => count((array) ($meta['unavailable'] ?? [])),
            ],
        ];
        if ($report['reconciliation'] !== null) {
            $protocol['reconciliation'] = $report['reconciliation'];
        }
        return $protocol;
    }

    /** @return list<string> */
    private static function safeWarnings(mixed $warnings): array
    {
        if (!is_array($warnings)) {
            return [];
        }
        $safe = [];
        foreach ($warnings as $warning) {
            if (is_string($warning) && $warning !== '') {
                $safe[] = mb_substr($warning, 0, 300);
            }
        }
        return array_slice(array_values(array_unique($safe)), 0, 100);
    }

    private static function catalogRunStatus(bool $complete, array $warnings): string
    {
        if (!$complete) return 'failed';
        return $warnings === [] ? 'completed' : 'completed_with_warnings';
    }

    /** @return array{ok:bool,years:list<array{year:int,ok:bool}>,warnings:list<string>}|null */
    private static function safeReconciliation(mixed $reconciliation): ?array
    {
        if (!is_array($reconciliation) || !array_key_exists('ok', $reconciliation)) {
            return null;
        }
        $years = [];
        foreach ((array) ($reconciliation['years'] ?? []) as $year) {
            if (!is_array($year)) {
                continue;
            }
            $number = filter_var($year['year'] ?? null, FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1900, 'max_range' => 2200]]);
            if (is_int($number) && array_key_exists('ok', $year)) {
                $years[] = ['year' => $number, 'ok' => (bool) $year['ok']];
            }
        }
        return [
            'ok' => (bool) $reconciliation['ok'],
            'years' => $years,
            'warnings' => self::safeWarnings($reconciliation['warnings'] ?? []),
        ];
    }
}
