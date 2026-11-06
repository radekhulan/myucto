<?php

declare(strict_types=1);

namespace MyInvoice\Action\Admin\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Migration\ImportYears;
use MyInvoice\Service\Migration\Pohoda\PartnerImporter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollImporter;
use MyInvoice\Service\Migration\Pohoda\Payroll\PohodaPayrollPeriodRange;
use MyInvoice\Service\Migration\Pohoda\PohodaAgendaYear;
use MyInvoice\Service\Migration\Pohoda\PohodaException;
use MyInvoice\Service\Migration\Pohoda\PohodaExport;
use MyInvoice\Service\Migration\Pohoda\PohodaImporter;
use MyInvoice\Service\Migration\Pohoda\PohodaImportJobService;
use MyInvoice\Service\Migration\Pohoda\PohodaUploads;
use MyInvoice\Service\Migration\Shared\ChunkedUploadStore;
use MyInvoice\Service\Migration\Shared\MigrationUploadLimits;
use MyInvoice\Service\Migration\Shared\NonprofitMigrationNotice;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Průvodce „Přechod z POHODA" - nahrání XML exportu agendy (ZIP), náhled, zkouška
 * nanečisto a ostrý převod vybraných roků na pozadí, protokoly a exportní nástroj.
 *
 *   POST /api/admin/imports/pohoda/uploads/chunked                {file_name, size}
 *   POST /api/admin/imports/pohoda/uploads/{token}/chunks         multipart `chunk` + `offset`
 *   POST /api/admin/imports/pohoda/uploads/{token}/complete
 *   GET  /api/admin/imports/pohoda/uploads/{token}
 *   POST /api/admin/imports/pohoda/uploads/{token}/start          {mode, years: int[] (nebo year), kind: accounting|payroll,
 *                                                                  stock?: {warehouses: {kód skladu: goods|material|skip}}}
 *   GET  /api/admin/imports/pohoda/runs
 *   GET  /api/admin/imports/pohoda/runs/{id}
 *   DELETE /api/admin/imports/pohoda/runs/{id}                    jen zkouška nanečisto
 *   GET  /api/admin/imports/pohoda/tool                           soubory exportního nástroje
 *   GET  /api/admin/imports/pohoda/tool/download[?name=]          ZIP celého nástroje nebo jeden soubor
 *
 * Export se vytváří nástrojem `tools/pohoda-export` přímo u POHODY (oficiální XML
 * rozhraní, jen čte). ZIP se nahrává po částech jako záloha Money S3 a rozbalí ho job na
 * pozadí ({@see AbstractMigrationAction}). Stav běžícího převodu jde přes společné
 * GET /api/admin/imports/{id}.
 */
final class PohodaMigrationAction extends AbstractMigrationAction
{
    protected const JOB_SERVICE = PohodaImportJobService::class;
    protected const EXCEPTION_CLASS = PohodaException::class;
    protected const ALLOWED_EXTENSIONS = ['zip'];
    protected const MAX_BYTES = MigrationUploadLimits::POHODA_MAX_BYTES;

    protected const TEXT_DENIED = 'Převod z POHODY smí spustit jen admin nebo účetní.';
    protected const TEXT_INVALID_FILE_TYPE = 'Nahrajte ZIP s XML exportem z POHODY.';
    protected const TEXT_INVALID_SIZE = 'Chybí velikost exportu.';
    protected const TEXT_TOO_LARGE = 'Export je příliš velký (nejvýš 2 GB).';
    protected const TEXT_TOO_MANY_UPLOADS = 'Firma má rozpracovaných příliš mnoho exportů, počkejte na dokončení běžícího zpracování.';
    protected const TEXT_CHUNK_MISSING = 'Chybí část exportu.';
    protected const TEXT_OFFSET_MISSING = 'Chybí pozice části exportu.';
    protected const TEXT_ALREADY_PROCESSING = 'Jiný export z POHODY se právě zpracovává, počkejte na jeho dokončení.';
    protected const TEXT_UPLOAD_FAILED = 'Export z POHODY se nepodařilo načíst.';
    protected const TEXT_UPLOAD_INCOMPLETE = 'Export ještě není nahraný celý.';
    protected const TEXT_MIGRATION_REQUIRED = 'Chybí databázová migrace pro převod z POHODY - spusťte `php api/bin/migrate.php`.';
    protected const RUN_ENTITY = 'pohoda_import';
    protected const DRY_RUN_DELETED_EVENT = 'import.pohoda_dry_run_deleted';

    /**
     * Exportní nástroje po programech. POHODA se exportuje přes XML rozhraní, PAMICA nemá
     * XML rozhraní a čte se přímo z mzdového datového souboru, takže má vlastní skript.
     * Obojí má i variantu pro SQL Server; `*.example.json` jsou vzory konfigurace připojení.
     */
    private const TOOL_DIRS = [
        'pohoda' => '/tools/pohoda-export',
        'pamica' => '/tools/pamica-export',
    ];
    /** Soubory z jiné složky, které nástroj potřebuje vedle sebe (připojení k SQL Serveru je společné). */
    private const TOOL_SHARED_FILES = [
        'pamica' => ['/tools/pohoda-export/PohodaSql-Common.ps1'],
    ];
    // JSON jen jako vzor (*.example.json): vyplněná konfigurace s heslem se nikdy nevystaví.
    private const TOOL_FILE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}(\.ps1|\.cmd|\.example\.json)$/';

    public function __construct(
        ImportJobRepository $jobs,
        PohodaImportRepository $runs,
        private readonly PohodaImporter $importer,
        ActivityLogger $logger,
        IpMatcher $ipMatcher,
        private readonly Connection $db,
        private readonly PohodaPayrollImporter $payroll,
    ) {
        parent::__construct($jobs, $runs, $logger, $ipMatcher);
    }

    protected function uploads(): ChunkedUploadStore
    {
        return PohodaUploads::store();
    }

    /** @param array<string,string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        try {
            $pending = $this->pendingUpload($supplierId, $token);
            if ($pending !== null) {
                return Json::ok($response, $pending);
            }
            $meta = PohodaUploads::meta($supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        // Náhled soubory exportu nečte: co z nich potřebuje (přehled mezd, pozdější roky
        // deníku), spočítal job nahrání do `meta.json`. Přehled nahraný před tím ho nemá -
        // pak ho dopočítá job na pozadí a průvodce mezitím ukazuje jeho průběh. Synchronně
        // by čtení 50MB souboru mezd přesáhlo timeout webserveru.
        if (!self::metaHasFacts($meta, $supplierIco)) {
            return Json::ok($response, $this->describeInBackground($request, $supplierId, $token));
        }
        $preflight = [];
        $payrollPreflight = [];
        $defaultYear = null;
        // Firma s hospodářským rokem vybírá účetnictví po svých účetních rocích: agenda nese
        // `company_year` (období firmy, hranice, pozdější období) a kontrola před převodem je
        // pod rokem firmy. Kalendářní firma dostane náhled beze změny.
        $calendar = $this->importer->companyCalendar($supplierId);
        $companyYears = [];
        foreach ((array) ($meta['agendas'] ?? []) as $agenda) {
            if ($supplierIco === '' || (string) $agenda['ico'] !== $supplierIco) {
                continue;
            }
            $year = (int) $agenda['year'];
            $defaultYear = max($defaultYear ?? 0, $year);
            $agendaDir = PohodaUploads::exportDir($supplierId, $token) . DIRECTORY_SEPARATOR . $agenda['dir'];
            if ((bool) ($agenda['has_payroll'] ?? false)) {
                try {
                    $payrollPreflight[(string) $year] = $this->payroll->preflight($supplierId, $agendaDir . DIRECTORY_SEPARATOR . PohodaExport::FILES['payroll'], $year,
                        (array) $agenda['payroll']);
                } catch (\Throwable $e) {
                    error_log(sprintf('POHODA: náhled mezd exportu %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                    $payrollPreflight[(string) $year] = [['level' => 'error', 'code' => 'payroll_unreadable', 'message' => 'Mzdy v exportu nejde přečíst.', 'context' => []]];
                }
            }
            if (!(bool) ($agenda['has_accounting'] ?? true)) {
                continue;
            }
            try {
                $export = PohodaExport::open($agendaDir);
                $agendaYear = $calendar->isCalendar() ? null : PohodaAgendaYear::of($export, $calendar);
                if ($agendaYear !== null) {
                    $companyYears[$year] = PohodaImportJobService::companyYear($agendaYear);
                }
                $preflight[(string) ($agendaYear?->label ?? $year)] = $this->importer->preflight($supplierId, $export, [],
                    array_map('intval', (array) $agenda['counts']['later_years']), $agendaYear);
            } catch (\Throwable $e) {
                if (!$e instanceof PohodaException) {
                    error_log(sprintf('POHODA: náhled exportu %s firmy %d selhal: %s', $token, $supplierId, (string) $e));
                }
                $preflight[(string) $year] = [['level' => 'error', 'code' => 'export_unreadable',
                    'message' => $e instanceof PohodaException ? $e->getMessage() : 'Agendu exportu nejde přečíst.', 'context' => []]];
            }
        }
        if ($companyYears !== []) {
            $meta['agendas'] = array_map(static function (array $agenda) use ($companyYears, $supplierIco): array {
                $year = (int) ($agenda['year'] ?? 0);
                if ((string) ($agenda['ico'] ?? '') === $supplierIco && isset($companyYears[$year])) {
                    $agenda['company_year'] = $companyYears[$year];
                }
                return $agenda;
            }, (array) $meta['agendas']);
        }
        return Json::ok($response, NonprofitMigrationNotice::attach($this->db, $supplierId, $meta + [
            'status' => PohodaUploads::STATUS_READY,
            'supplier_ico' => $supplierIco,
            'default_year' => $defaultYear,
            'preflight' => (object) $preflight,
            'payroll_preflight' => (object) $payrollPreflight,
        ]));
    }

    /**
     * `meta.json` nese všechno, co náhled z exportu potřebuje, u každé agendy s IČO firmy:
     * úplný přehled mezd ({@see PohodaPayrollImporter::summaryComplete()}) a pozdější roky deníku.
     *
     * @param array<string,mixed> $meta
     */
    public static function metaHasFacts(array $meta, string $supplierIco): bool
    {
        foreach ((array) ($meta['agendas'] ?? []) as $agenda) {
            if ($supplierIco === '' || (string) ($agenda['ico'] ?? '') !== $supplierIco) {
                continue;
            }
            if ((bool) ($agenda['has_payroll'] ?? false) && !PohodaPayrollImporter::summaryComplete($agenda['payroll'] ?? null)) {
                return false;
            }
            if ((bool) ($agenda['has_accounting'] ?? true) && !is_array($agenda['counts']['later_years'] ?? null)) {
                return false;
            }
            // Přehled skladu (sklady k volbě v průvodci) nese jen přehled nahraný po jeho zavedení.
            if (!array_key_exists('stock', $agenda) && in_array(PohodaExport::FILES['stock'], array_map(
                static fn (array $f): string => ($f['state'] ?? 'missing') !== 'missing' ? (string) ($f['file'] ?? '') : '', (array) ($agenda['files'] ?? [])), true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Přehled exportu dopočítá job na pozadí ({@see PohodaImportJobService::MODE_DESCRIBE});
     * průvodce dostane stav `processing` s průběhem jobu a dotazuje se znovu. Běžící job se
     * znovu nezakládá; doběhlý job, po kterém přehled pořád chybí, se hlásí jako chyba,
     * kterou jde zopakovat (`?retry=1`).
     *
     * @return array<string,mixed>
     */
    private function describeInBackground(Request $request, int $supplierId, string $token): array
    {
        $retry = (string) ($request->getQueryParams()['retry'] ?? '') === '1';
        $userId = self::userId($request);
        $this->jobs->reapStale($supplierId, PohodaImportJobService::SOURCE);
        $result = PohodaUploads::withUploadLock($supplierId, $token, function () use ($supplierId, $token, $retry, $userId): array {
            $state = PohodaUploads::state($supplierId, $token) ?? [];
            $jobId = isset($state['describe_job_id']) ? (int) $state['describe_job_id'] : null;
            $job = $jobId !== null ? $this->jobs->find($jobId, $supplierId) : null;
            if ($job !== null && in_array($job['status'], ['queued', 'running'], true)) {
                return ['job' => $job, 'spawn' => false];
            }
            if ($job !== null && !$retry) {
                return ['job' => $job, 'spawn' => false];
            }
            $jobId = $this->jobs->create($supplierId, PohodaImportJobService::SOURCE, [
                'token' => $token,
                'mode' => PohodaImportJobService::MODE_DESCRIBE,
            ], $userId);
            PohodaUploads::updateState($supplierId, $token, ['describe_job_id' => $jobId]);
            return ['job' => $this->jobs->find($jobId, $supplierId), 'spawn' => true];
        });
        $job = $result['job'];
        if ($result['spawn'] && $job !== null) {
            $this->spawnWorker((int) $job['id']);
        }
        $meta = PohodaUploads::meta($supplierId, $token);
        $running = $job !== null && in_array($job['status'], ['queued', 'running'], true);
        return [
            'token' => $token,
            'status' => $running ? PohodaUploads::STATUS_PROCESSING : PohodaUploads::STATUS_FAILED,
            'file_name' => (string) ($meta['file_name'] ?? ''),
            'size' => 0,
            'received' => 0,
            'job_id' => $job !== null ? (int) $job['id'] : null,
            'progress' => $job !== null ? self::jobProgress($job) : null,
            'error' => $running ? null : (trim((string) ($job['last_error'] ?? '')) ?: 'Náhled exportu se nepodařilo připravit.'),
            // Export na serveru zůstává, chyba přípravy náhledu jde zopakovat.
            'retryable' => !$running,
        ];
    }

    /** @param array<string,string> $args */
    public function start(Request $request, Response $response, array $args): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::WRITE);
        if ($denied !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $token = (string) ($args['token'] ?? '');
        $body = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($body['mode'] ?? 'dry_run');
        if (!in_array($mode, ['dry_run', 'import'], true)) {
            return Json::error($response, 'invalid_mode', 'Neznámý režim převodu.', 422);
        }
        if (!ImportYears::validBody($body)) {
            return Json::error($response, 'invalid_year', 'Roky převodu musí být seznam roků.', 422);
        }
        $years = ImportYears::fromParams($body);
        $kind = (string) ($body['kind'] ?? 'accounting');
        if (!in_array($kind, ['accounting', 'payroll'], true)) {
            return Json::error($response, 'invalid_kind', 'Neznámý druh převodu.', 422);
        }
        $acceptDifferences = self::acceptDifferences($body, $mode);
        if ($acceptDifferences === null) {
            return self::invalidAcceptDifferences($response);
        }
        $startDecision = $body['start_decision'] ?? null;
        if ($startDecision === '' || $kind !== 'payroll') {
            $startDecision = null;
        }
        if ($startDecision !== null && !in_array($startDecision, [PohodaPayrollImporter::START_ADVANCE, PohodaPayrollImporter::START_KEEP], true)) {
            return Json::error($response, 'invalid_start_decision', 'Neznámé rozhodnutí k začátku vedení mezd.', 422);
        }
        try {
            $periodRange = $kind === 'payroll' ? PohodaPayrollPeriodRange::fromParams($body) : PohodaPayrollPeriodRange::all();
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'invalid_period_range', $e->getMessage(), 422);
        }
        $stock = $kind === 'accounting' ? self::stockChoice($body['stock'] ?? null) : null;
        if ($stock === false) {
            return Json::error($response, 'invalid_stock', 'Volba převodu skladu je neplatná.', 422);
        }
        if ($kind === 'payroll') {
            // Mzdy zakládají osoby, vztahy, vstupy a profil importu - i zkouška nanečisto jde celou cestou.
            $missing = self::missingPayrollRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Převod mezd zakládá zaměstnance, pracovní vztahy a mzdové vstupy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        } elseif ($mode === 'import') {
            $missing = self::missingLiveImportRights($request);
            if ($missing !== []) {
                return Json::error($response, 'forbidden', 'Ostrý převod zapisuje účetní deník a mění nastavení firmy - chybí oprávnění: '
                    . implode(', ', $missing) . '.', 403, ['missing_permissions' => $missing]);
            }
        }
        try {
            $meta = PohodaUploads::meta($supplierId, $token);
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 404);
        }
        $supplierIco = $this->supplierIco($supplierId);
        try {
            // Stejný plán jako job: rok s agendou IČO firmy, nebo pozdější rok vybrané agendy
            // (u hospodářského roku firmy účetní roky firmy).
            PohodaImportJobService::plan($meta, $supplierIco, $years, $kind === 'payroll', $supplierId, $token, $kind === 'payroll' ? null
                : PohodaImportJobService::companyYears($meta, $supplierIco, $this->importer->companyCalendar($supplierId), $supplierId, $token));
        } catch (PohodaException $e) {
            return Json::error($response, $e->errorCode, $e->getMessage(), 422, $e->context);
        }
        PohodaUploads::touch($supplierId, $token);
        $running = $this->alreadyRunning($response, $supplierId);
        if ($running !== null) {
            return $running;
        }

        $userId = self::userId($request);
        $jobId = $this->createRunJob($response, $supplierId, [
            'token' => $token,
            'mode' => $mode,
            // Vybrané roky vzestupně; `year` = první z nich pro starší čtení parametrů jobu.
            'years' => $years,
            'year' => $years[0],
            'ico' => $supplierIco,
            'kind' => $kind,
            // OIČ a ID PPV z PAMICA se převezmou jen s potvrzením, že pocházejí z protokolů ČSSZ.
            'confirm_identifiers' => $kind === 'payroll' && filter_var($body['confirm_identifiers'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Převzatá docházka a mzdové vstupy se rovnou schválí. Bez toho zůstanou měsíce
            // `open` a vstupy `draft` a mzdový běh nad nimi narazí na blokující kontroly
            // `time_month_not_approved` a `draft_inputs_present`, které nejdou přebít výjimkou.
            'approve_taken_over' => $kind === 'payroll' && filter_var($body['approve_taken_over'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Začátek vedení mezd před měsíci zpracovanými PAMICA: posunout a převést, nebo vědomě ponechat.
            // Bez rozhodnutí ostrý převod skončí chybou ({@see PohodaPayrollImporter::run()}).
            'start_decision' => $startDecision,
            // Převáděné měsíce mezd (`YYYY-MM`, obě meze včetně); bez nich všechny uzavřené měsíce exportu.
            ...$periodRange->toParams(),
            // Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto.
            'accept_differences' => $acceptDifferences,
            // Sklad z nejnovější agendy: volba po skladech pro karty bez druhu zásoby, `null` = nepřevádí se.
            'stock' => $stock,
        ], $userId);
        if ($jobId instanceof Response) {
            return $jobId;
        }
        $this->spawnWorker($jobId);
        $this->logger->log('import.pohoda_started', $userId, 'import_job', $jobId, ['mode' => $mode, 'years' => $years, 'kind' => $kind] + ($acceptDifferences ? ['accept_differences' => true] : []),
            $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued', 'mode' => $mode], 201);
    }

    /**
     * Volba převodu skladu z těla požadavku: `null` = sklad se nepřevádí, `false` = neplatná.
     *
     * @return array{warehouses:array<string,string>}|null|false
     */
    public static function stockChoice(mixed $raw): array|null|false
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !is_array($raw['warehouses'] ?? null) || count($raw['warehouses']) > 500) {
            return false;
        }
        $out = [];
        foreach ($raw['warehouses'] as $code => $choice) {
            if (!is_string($code) && !is_int($code)) {
                return false;
            }
            $code = (string) $code;
            if (mb_strlen($code) > 20 || !in_array($choice, ['goods', 'material', 'skip'], true)) {
                return false;
            }
            $out[$code] = $choice;
        }
        return ['warehouses' => $out];
    }

    /** Soubory exportního nástroje, které si uživatel stáhne k POHODĚ. */
    public function tool(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $variant = self::toolVariant($request);
        $files = [];
        foreach (self::toolFiles($variant) as $name => $path) {
            $files[] = ['name' => $name, 'size' => (int) filesize($path)];
        }
        return Json::ok($response, ['files' => $files]);
    }

    /**
     * Stažení nástroje: bez jména ZIP se všemi soubory, s `?name=` jeden soubor. Jméno jde
     * v query, ne v cestě - IIS i nginx přípony `.cmd`/`.ps1` v cestě URL blokují.
     */
    public function toolDownload(Request $request, Response $response): Response
    {
        $denied = $this->deny($request, $response, AccessLevel::READ);
        if ($denied !== null) {
            return $denied;
        }
        $variant = self::toolVariant($request);
        $files = self::toolFiles($variant);
        if ($files === []) {
            return Json::error($response, 'tool_missing', 'Exportní nástroj v této instalaci chybí.', 404);
        }
        $name = (string) ($request->getQueryParams()['name'] ?? '');
        if ($name !== '') {
            if (!isset($files[$name])) {
                return Json::error($response, 'not_found', 'Soubor nástroje nenalezen.', 404);
            }
            $response->getBody()->write((string) file_get_contents($files[$name]));
            return $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
                ->withHeader('Cache-Control', 'no-store');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pohoda-tool');
        $zip = new \ZipArchive();
        if ($tmp === false || $zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Balíček nástroje se nepodařilo vytvořit.', 500);
        }
        foreach ($files as $fileName => $path) {
            $zip->addFile($path, $variant . '-export/' . $fileName);
        }
        $zip->close();
        $response->getBody()->write((string) file_get_contents($tmp));
        @unlink($tmp);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $variant . '-export.zip"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Práva, která ostrý převod navíc potřebuje: zapisuje účetní deník a přepíná režim
     * účetnictví a automatiku. Zkouška nanečisto stačí s `utilities.import`.
     *
     * @return list<string>
     */
    public static function missingLiveImportRights(Request $request): array
    {
        return self::missingRights($request, self::LIVE_IMPORT_RIGHTS);
    }

    /**
     * Práva, která převod mezd potřebuje navíc k `utilities.import`: stejná jako ruční
     * import docházky a mezd (vstupy, osoby a vztahy, profil importu).
     *
     * @return list<string>
     */
    public static function missingPayrollRights(Request $request): array
    {
        return self::missingRights($request, ['payroll.inputs.write', 'payroll.person.write', 'payroll.settings']);
    }

    /** Program, jehož nástroj se servíruje; neznámá hodnota spadne na POHODU. */
    private static function toolVariant(Request $request): string
    {
        $variant = (string) ($request->getQueryParams()['variant'] ?? 'pohoda');
        return isset(self::TOOL_DIRS[$variant]) ? $variant : 'pohoda';
    }

    /** @return array<string,string> jméno => cesta */
    private static function toolFiles(string $variant = 'pohoda'): array
    {
        $dir = self::TOOL_DIRS[$variant] ?? self::TOOL_DIRS['pohoda'];
        $out = [];
        $paths = glob(Bootstrap::rootDir() . $dir . '/*') ?: [];
        foreach (self::TOOL_SHARED_FILES[$variant] ?? [] as $shared) {
            $paths[] = Bootstrap::rootDir() . $shared;
        }
        foreach ($paths as $path) {
            $name = basename($path);
            if (is_file($path) && preg_match(self::TOOL_FILE_PATTERN, $name) === 1) {
                $out[$name] = $path;
            }
        }
        ksort($out);
        return $out;
    }

    private function supplierIco(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        return PartnerImporter::ico((string) $stmt->fetchColumn());
    }
}
