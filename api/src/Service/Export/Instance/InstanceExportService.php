<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Backup\BackupFileCollector;
use MyInvoice\Service\Backup\ReadableDocumentArchiveLayout;
use MyInvoice\Service\Cron\BackupEncryption;
use MyInvoice\Service\Export\ClosingPackageService;
use MyInvoice\Service\Export\ExportFilename;
use MyInvoice\Service\Export\IsdocExporter;
use MyInvoice\Service\Export\MonthlyExportService;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Pdf\PurchaseInvoicePdfRenderer;
use MyInvoice\Service\Vat\VatStatusService;
use MyInvoice\Repository\InvoiceRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * H-14 — kompletní export dat jedné firmy do jednoho archivu.
 *
 * Proč to existuje: hostovaná služba dává po skončení 60 dnů na stažení dat a
 * retence měsíčních kopií hostingu je jen 3 měsíce. Chyba, na kterou se přijde při
 * uzávěrce po víc než třech měsících, se tedy neřeší zálohou hostingu, ale tímhle
 * exportem — a zákonná archivace účetnictví je na roky. Proto je požadavek tvrdý:
 * **archiv musí být použitelný BEZ naší aplikace.**
 *
 * Co je uvnitř (viz `CTI-MNE.txt` v archivu):
 *   data/{tabulka}.jsonl   strojově čitelný export všech agend (JSON Lines)
 *   shared/payroll/…       globální mzdové podklady nutné k obnově
 *   doklady/{rok}/…        PDF a ISDOC vydaných, PDF přijatých, výpisy z účtu
 *   prilohy/…              skeny, přílohy deníku (§ 33a), dokumenty DMS, mzdové doklady
 *   manifest.json          co v archivu je, kolik toho je, k jakému datu, jaká verze
 *   CHECKSUMS.txt          SHA-256 každé položky
 *
 * Čtyři věci, které tenhle kód dělá jinak, než by se čekalo, a proč:
 *
 * • **Izolace firem** neřeší tenhle soubor, ale {@see TenantScopeResolver} — a řeší ji
 *   DEFAULT DENY: co se nepodaří navázat na `supplier_id`, se neexportuje. U účetní
 *   kanceláře je vydání cizí firmy únik dat, ne chyba v souboru.
 *
 * • **Nestaví se kopie a pak ZIP.** Každá tabulka se zapíše do jednoho dočasného
 *   souboru, ten se vloží do archivu a hned zahodí ({@see InstanceExportArchive}).
 *   Dočasné místo je tak velikost NEJVĚTŠÍ tabulky, ne celého exportu — jinak by
 *   export vyčerpal kvótu instance a přepnul ji do režimu jen pro čtení.
 *
 * • **Řádky se čtou po dávkách** (keyset přes PK, jinak LIMIT/OFFSET). Celá tabulka
 *   do pole se nenačítá nikdy; BLOBy bankovních výpisů se navíc tahají po jednom
 *   řádku a jdou rovnou do souboru.
 *
 * • **Data se NEčtou v jedné transakci.** Snapshot držený hodiny (export s PDF tak
 *   dlouho běží) by na sdílené instanci zapíchl undo log a poškodil všechny ostatní
 *   firmy víc, než kolik přinese atomicita archivu. Manifest proto nese
 *   `data_snapshot: "non-atomic"` a časy začátku a konce čtení, aby bylo poznat,
 *   v jakém okně archiv vznikl.
 */
final class InstanceExportService
{
    public const PART_DATA = 'data';
    public const PART_DOCUMENTS = 'documents';
    public const PART_FILES = 'files';
    /** Úplný archiv lze obnovit přímo do čisté databáze přes `archive-restore.php`. */
    public const PART_RESTORE = 'restore';
    /** DPH podklady po kalendářních měsících (a pro kvartální plátce i čtvrtletích). */
    public const PART_VAT_EXPORTS = 'vat_exports';
    /** Uzávěrkové balíčky za účetní období — jen pro podvojné účetnictví. */
    public const PART_CLOSING_PACKAGES = 'closing_packages';

    /** @var list<string> */
    public const ALL_PARTS = [
        self::PART_RESTORE,
        self::PART_DATA,
        self::PART_DOCUMENTS,
        self::PART_FILES,
        self::PART_VAT_EXPORTS,
        self::PART_CLOSING_PACKAGES,
    ];

    /** Verze formátu archivu — čtečky se podle ní mají rozhodovat. */
    private const FORMAT_VERSION = 5;

    /**
     * Globální mzdová konfigurace a připnuté legislativní podklady. Nejsou
     * tenantově omezené, ale jsou nutné pro reprodukci mzdových snapshotů a
     * jejich cizí klíče. Seznam je explicitní: nová globální tabulka se nesmí
     * do archivu dostat bez bezpečnostního posouzení.
     *
     * Pořadí respektuje rodiče před potomky kvůli triggerům při obnově.
     *
     * @var list<string>
     */
    public const SHARED_PAYROLL_TABLES = [
        'payroll_rulesets',
        'payroll_jmhz_spec_packages',
        'payroll_jmhz_codebooks',
        'payroll_jmhz_dictionary_attributes',
        'payroll_jmhz_codebook_entries',
        'payroll_jmhz_control_catalogs',
        'payroll_jmhz_control_definitions',
        'payroll_jmhz_control_attribute_refs',
        'payroll_jmhz_control_parameters',
        'payroll_jmhz_control_parameter_refs',
        'payroll_jmhz_control_parameter_values',
        'payroll_jmhz_scenario_catalogs',
        'payroll_jmhz_requirement_matrices',
        'payroll_jmhz_scenario_definitions',
        'payroll_jmhz_interaction_definitions',
        'payroll_jmhz_field_requirements',
        'payroll_jmhz_interaction_attribute_refs',
        'payroll_jmhz_master_attribute_axis',
        'payroll_jmhz_matrix_evidence_axes',
        'payroll_jmhz_matrix_evidence_members',
    ];

    /**
     * Globální ruleset je nutný pro shodný budoucí výpočet, ale jeho správcovská
     * provenance nepatří firmě, která si stahuje vlastní archiv. Auditní tabulka
     * se neexportuje vůbec; z efektivního rulesetu zůstává pouze výpočetní obsah.
     *
     * @var array<string,array<string,string>>
     */
    private const SHARED_PAYROLL_OMITTED_COLUMNS = [
        'payroll_rulesets' => [
            'reason' => 'global_admin_reason',
        ],
    ];

    /**
     * Nula znamená „úkon existoval, identita správce byla odstraněna“. Zachovává
     * schvalovací stav nutný pro shodný runtime rulesetu, ale není ID uživatele.
     * Sloupce nemají FK na users právě proto, že globální ruleset není tenantový.
     *
     * @var array<string,list<string>>
     */
    private const SHARED_PAYROLL_NEUTRALIZED_USER_COLUMNS = [
        'payroll_rulesets' => [
            'created_by', 'updated_by', 'reviewed_by', 'approved_by',
            'activated_by', 'superseded_by',
        ],
    ];

    /** Řádků na dávku při čtení tabulky. */
    private const BATCH = 1000;

    /** Výchozí platnost hotového archivu (dnů), než ho úklid smaže. */
    private const DEFAULT_TTL_DAYS = 14;

    /** Výchozí strop velikosti archivu (bajty) — přebije cfg `export.instance.max_bytes`. */
    private const DEFAULT_MAX_BYTES = 20 * 1024 * 1024 * 1024;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly LoggerInterface $log,
        private readonly TenantScopeResolver $scopes,
        private readonly InstanceExportJobStore $jobs,
        private readonly ImportJobRepository $reportJobs,
        private readonly MonthlyExportService $monthlyExports,
        private readonly ClosingPackageService $closingPackages,
        private readonly VatStatusService $vatStatus,
        private readonly InvoiceRepository $invoiceRepo,
        private readonly InvoicePdfRenderer $invoicePdf,
        private readonly PurchaseInvoicePdfRenderer $purchasePdf,
        private readonly IsdocExporter $isdoc,
    ) {}

    /**
     * @param list<string> $requested
     * @return list<string>
     */
    public static function normalizeParts(array $requested): array
    {
        $valid = array_values(array_intersect(self::ALL_PARTS, $requested));
        if ($valid === []) {
            return self::ALL_PARTS;
        }
        // Obnovitelný export není druhý vložený ZIP: je to tento archiv. Proto
        // vždy nese databázi, binární výpisy i nahrané soubory, bez nichž by
        // obnova nebyla úplná. Ostatní části si uživatel volí samostatně.
        if (in_array(self::PART_RESTORE, $valid, true)) {
            $valid = [...$valid, self::PART_DATA, self::PART_DOCUMENTS, self::PART_FILES];
        }
        return array_values(array_unique($valid));
    }

    /**
     * Kořen úložiště archivů. Mimo docroot (přes {@see RuntimePaths}, takže i mimo
     * image při `MYINVOICE_DATA_DIR`) — hotový archiv je kompletní účetnictví firmy
     * a nesmí být stažitelný bez autentizace.
     */
    public function storageBaseDir(): string
    {
        return RuntimePaths::storage('instance-exports');
    }

    /** Absolutní cesta k archivu z relativní `sup-N/soubor.zip`. */
    public function resolveResultPath(string $relative): string
    {
        return $this->storageBaseDir() . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }

    /** @return array{accounting_mode:string,is_vat_payer:bool,vat_period:?string} */
    public function supplierProfile(int $supplierId): array
    {
        $supplier = $this->fetchSupplier($supplierId) ?? [];
        $vatPeriod = $supplier['vat_period'] ?? null;
        return [
            'accounting_mode' => (string) ($supplier['accounting_mode'] ?? 'tax_evidence'),
            'is_vat_payer' => (bool) ($supplier['is_vat_payer'] ?? false),
            'vat_period' => in_array($vatPeriod, ['monthly', 'quarterly'], true) ? $vatPeriod : null,
        ];
    }

    /**
     * Entrypoint workeru — běh navázaný na řádek `instance_exports`.
     * Vlastní try/catch, aby job nikdy nezůstal viset v `running`.
     */
    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) {
            $this->log->warning('Export instance: job neexistuje', ['job_id' => $jobId]);
            return;
        }
        if (!$this->jobs->markRunning($jobId)) {
            // Jiný proces nás předběhl (nebo job není queued) — nic nedělej.
            return;
        }

        $supplierId = (int) $job['supplier_id'];
        $parts = self::normalizeParts(array_map('strval', (array) ($job['parts'] ?? [])));
        $lock = null;
        try {
            $lock = InstanceExportLock::tryAcquire($supplierId);
            if ($lock === null) {
                throw new InstanceExportException(
                    'already_running',
                    'Export téhle firmy už běží v jiném procesu.',
                    409,
                );
            }
            $result = $this->build(
                $supplierId,
                $parts,
                $job['date_from'] === null ? null : (string) $job['date_from'],
                $job['date_to'] === null ? null : (string) $job['date_to'],
                $jobId,
                isset($job['created_by']) ? (int) $job['created_by'] : null,
                null,
            );
            $this->jobs->setResult(
                $jobId,
                $result['rel_path'],
                $result['file_name'],
                $result['size_bytes'],
                $result['sha256'],
                $result['encrypted'],
                $result['manifest'],
                date('Y-m-d H:i:s', time() + $this->ttlDays() * 86400),
            );
            $this->jobs->markCompleted($jobId);
            // Úklid expirovaných archivů rovnou tady, ne až v cronu. Nový archiv je
            // přesně ten okamžik, kdy na disku přibylo nejvíc místa navíc — a instance
            // bez nastaveného cronu by jinak kvótu plnila donekonečna.
            try {
                $this->cleanupExpired();
            } catch (\Throwable $e) {
                $this->log->warning('Úklid expirovaných exportů selhal: ' . $e->getMessage());
            }
        } catch (InstanceExportCancelled) {
            $this->jobs->appendLog($jobId, 'Export zrušen uživatelem.');
            $this->jobs->markCancelled($jobId);
        } catch (InstanceExportException $e) {
            $this->jobs->appendLog($jobId, 'Chyba: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        } catch (\Throwable $e) {
            $this->log->error('Export instance selhal: ' . $e->getMessage(), ['exception' => $e, 'job_id' => $jobId]);
            $this->jobs->markFailed($jobId, 'Export se nepodařilo dokončit: ' . $e->getMessage());
        } finally {
            $lock?->release();
        }
    }

    /**
     * Přímý běh bez jobu — pro CLI (`api/bin/export-instance.php`), typicky když se
     * export pouští ručně při odchodu zákazníka nebo do jiného cíle.
     *
     * @param list<string>                $parts
     * @param null|callable(string):void  $progress
     * @return array<string,mixed>
     */
    public function runForSupplier(
        int $supplierId,
        array $parts,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $targetDir = null,
        ?callable $progress = null,
    ): array {
        $lock = InstanceExportLock::tryAcquire($supplierId);
        if ($lock === null) {
            throw new InstanceExportException(
                'already_running',
                'Export firmy #' . $supplierId . ' už běží (jiný proces drží zámek).',
                409,
            );
        }
        try {
            return $this->build($supplierId, self::normalizeParts($parts), $dateFrom, $dateTo, null, null, $progress, $targetDir);
        } finally {
            $lock->release();
        }
    }

    /**
     * Smaže archivy po expiraci (soubor i sidecar se součtem). Historie běhu v DB
     * zůstane — zákazník má vidět, že export proběhl a kdy vypršel.
     *
     * @return int počet smazaných archivů
     */
    public function cleanupExpired(): int
    {
        $removed = 0;
        foreach ($this->jobs->expired() as $job) {
            $rel = (string) ($job['result_path'] ?? '');
            if ($rel === '') {
                continue;
            }
            $abs = $this->safeResultPath($rel);
            if ($abs !== null) {
                @unlink($abs);
                @unlink($abs . '.sha256');
            }
            $this->jobs->forgetResult((int) $job['id']);
            $removed++;
        }
        if ($removed > 0) {
            $this->log->info('Export instance: uklizeny expirované archivy', ['count' => $removed]);
        }
        return $removed;
    }

    /**
     * Absolutní cesta k archivu, ověřená proti path traversalu, nebo null.
     *
     * Porovnává se case-insensitive: Windows `realpath()` vrací nekonzistentní
     * casing a citlivé porovnání by tam guard tiše zneplatnilo.
     */
    public function safeResultPath(string $relative): ?string
    {
        $abs = realpath($this->resolveResultPath($relative));
        $base = realpath($this->storageBaseDir());
        if ($abs === false || $base === false || !is_file($abs)) {
            return null;
        }
        $needle = $base . DIRECTORY_SEPARATOR;
        return str_starts_with(strtolower($abs), strtolower($needle)) ? $abs : null;
    }

    // ── vlastní běh ───────────────────────────────────────────────────────────

    /**
     * @param list<string>               $parts
     * @param null|callable(string):void $progress
     * @return array<string,mixed>
     */
    private function build(
        int $supplierId,
        array $parts,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $jobId,
        ?int $userId,
        ?callable $progress,
        ?string $targetDirOverride = null,
    ): array {
        $supplier = $this->fetchSupplier($supplierId);
        if ($supplier === null) {
            throw new InstanceExportException('not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }

        $password = BackupEncryption::passwordFromConfig($this->config);
        if (($reason = BackupEncryption::unsupportedReason($password)) !== null) {
            throw new InstanceExportException('encryption_unsupported', $reason, 500);
        }
        if ($password === '') {
            // Stejná konvence jako u záloh: nešifrovaný archiv je vědomé rozhodnutí,
            // ale musí být jednou za běh vidět v logu.
            $this->log->warning(
                'Export instance běží NEŠIFROVANĚ — cfg `cron.backup.password` není nastavené. '
                . 'Archiv obsahuje kompletní účetnictví firmy.',
                ['supplier_id' => $supplierId],
            );
            $this->note($jobId, $progress, 'Upozornění: archiv se NEšifruje (cfg cron.backup.password je prázdné).');
        }

        $relDir = 'sup-' . $supplierId;
        $absDir = $targetDirOverride ?? ($this->storageBaseDir() . DIRECTORY_SEPARATOR . $relDir);
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new InstanceExportException('storage_failed', 'Nelze vytvořit úložiště exportů: ' . $absDir);
        }
        $workDir = $absDir . DIRECTORY_SEPARATOR . 'tmp-' . bin2hex(random_bytes(6));
        if (!@mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new InstanceExportException('storage_failed', 'Nelze vytvořit pracovní adresář: ' . $workDir);
        }

        $slug = ExportFilename::sanitize((string) $supplier['company_name'], 'firma');
        $fileName = sprintf('myucto-export-%s-sup%d-%s.zip', substr($slug, 0, 60), $supplierId, date('Ymd-His'));
        $absPath = $absDir . DIRECTORY_SEPARATOR . $fileName;
        $relPath = $relDir . '/' . $fileName;

        $startedAt = date('Y-m-d H:i:s');
        $archive = new InstanceExportArchive($absPath, $password, $this->maxBytes());
        $sections = [];
        $restoreAssets = ['files' => [], 'blobs' => [], 'documents' => []];

        try {
            if (in_array(self::PART_DATA, $parts, true)) {
                $sections['data'] = $this->exportData($archive, $supplierId, $jobId, $progress, $workDir);
            }
            if (in_array(self::PART_DOCUMENTS, $parts, true)) {
                $sections['doklady'] = $this->exportDocuments(
                    $archive,
                    $supplierId,
                    $dateFrom,
                    $dateTo,
                    $jobId,
                    $progress,
                    $workDir,
                    $restoreAssets['blobs'],
                    $restoreAssets['documents'],
                );
            }
            if (in_array(self::PART_FILES, $parts, true)) {
                $sections['prilohy'] = $this->exportFiles($archive, $supplierId, $jobId, $progress, $restoreAssets['files']);
            }
            if (in_array(self::PART_VAT_EXPORTS, $parts, true)) {
                $sections['dph'] = $this->exportVatPackages(
                    $archive, $supplierId, $supplier, $dateFrom, $dateTo, $userId, $jobId, $progress,
                );
            }
            if (in_array(self::PART_CLOSING_PACKAGES, $parts, true)) {
                $sections['uzaverky'] = $this->exportClosingPackages(
                    $archive, $supplierId, $supplier, $dateFrom, $dateTo, $userId, $jobId, $progress,
                );
            }

            $this->step($jobId, $progress, 'Manifest a kontrolní součty');
            $manifest = [
                'format' => 'myucto-instance-export',
                'version' => self::FORMAT_VERSION,
                'app_version' => $this->appVersion(),
                'schema_version' => $this->schemaVersion(),
                'supplier' => [
                    'id' => (int) $supplier['id'],
                    'name' => (string) $supplier['company_name'],
                    'ico' => $supplier['ic'] ?? null,
                    'dic' => $supplier['dic'] ?? null,
                ],
                'profile' => [
                    'accounting_mode' => (string) ($supplier['accounting_mode'] ?? 'tax_evidence'),
                    'is_vat_payer' => (bool) ($supplier['is_vat_payer'] ?? false),
                    'vat_period' => $supplier['vat_period'] ?? null,
                ],
                'restore' => [
                    'format' => 'myucto-instance-export',
                    'compatibility' => 'Obnova do čisté, předem migrované databáze stejné nebo novější verze MyÚčto.',
                    'available' => in_array(self::PART_RESTORE, $parts, true),
                    'files' => $restoreAssets['files'],
                    'blobs' => $restoreAssets['blobs'],
                    'documents' => $restoreAssets['documents'],
                ],
                'range' => ['from' => $dateFrom, 'to' => $dateTo],
                'parts' => $parts,
                'encrypted' => $password !== '' ? 'AES-256' : false,
                'data_snapshot' => 'non-atomic',
                'read_started_at' => $startedAt,
                'read_finished_at' => date('Y-m-d H:i:s'),
                'sections' => $sections,
                'totals' => [
                    'entries' => $archive->entryCount(),
                    'uncompressed_bytes' => $archive->rawBytes(),
                ],
                'checksums' => $archive->entries(),
            ];
            $archive->addString('manifest.json', (string) json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $archive->addString('CHECKSUMS.txt', $this->renderChecksums($archive->entries()));
            $archive->addString('CTI-MNE.txt', $this->renderReadme($supplier, $manifest, $password !== ''));

            [$sizeBytes, $sha256] = $archive->finish();
        } catch (\Throwable $e) {
            $archive->discard();
            $this->removeDir($workDir);
            throw $e;
        }
        $this->removeDir($workDir);

        // Součet celého archivu nemůže být uvnitř archivu (kroutil by se sám do sebe),
        // takže jde vedle jako sidecar a do DB. Podle něj zákazník pozná, že se
        // stáhl celý soubor, ne jen kus.
        @file_put_contents($absPath . '.sha256', $sha256 . '  ' . $fileName . "\n");

        $this->log->info('Export instance dokončen', [
            'supplier_id' => $supplierId,
            'file' => $fileName,
            'size_bytes' => $sizeBytes,
            'entries' => $manifest['totals']['entries'],
            'encrypted' => $password !== '',
        ]);

        return [
            'rel_path' => $relPath,
            'abs_path' => $absPath,
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'encrypted' => $password !== '',
            'manifest' => $manifest,
        ];
    }

    /**
     * Měsíční/čtvrtletní podklady DPH vložené jako samostatné ZIPy. Využívá stejný
     * MonthlyExportService jako obrazovka hromadného exportu, takže kritické datum
     * nároku na odpočet a formát KH mají jeden zdroj pravdy.
     *
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function exportVatPackages(
        InstanceExportArchive $archive,
        int $supplierId,
        array $supplier,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $userId,
        ?int $jobId,
        ?callable $progress,
    ): array {
        [$from, $to] = $this->exportRange($supplierId, $dateFrom, $dateTo);
        if ($from === null || $to === null) {
            return ['status' => 'skipped', 'reason' => 'no_dated_documents', 'packages' => []];
        }
        if (!$this->vatStatus->wasPayerDuring($supplierId, $from, $to)) {
            return ['status' => 'skipped', 'reason' => 'not_vat_payer', 'packages' => []];
        }

        $packages = [];
        $quarters = [];
        foreach ($this->monthsBetween($from, $to) as [$year, $month]) {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
            if (!$this->vatStatus->wasPayerDuring($supplierId, $start, $end)) {
                continue;
            }
            $label = sprintf('%04d-%02d', $year, $month);
            $this->step($jobId, $progress, 'DPH podklady ' . $label);
            $result = $this->runNestedReport(
                $archive,
                $supplierId,
                'monthly_export',
                ['period' => 'monthly', 'year' => $year, 'month' => $month, 'parts' => ['dph_book', 'vat_control_statement']],
                $userId,
                'dph/mesice/' . $label . '.zip',
            );
            $packages[] = ['period' => $label] + $result;
            if ((string) ($supplier['vat_period'] ?? 'monthly') === 'quarterly') {
                $quarters[sprintf('%04d-Q%d', $year, (int) ceil($month / 3))] = [$year, (int) ceil($month / 3)];
            }
        }

        foreach ($quarters as $label => [$year, $quarter]) {
            $this->step($jobId, $progress, 'Čtvrtletní DPH podklady ' . $label);
            $result = $this->runNestedReport(
                $archive,
                $supplierId,
                'monthly_export',
                ['period' => 'quarterly', 'year' => $year, 'quarter' => $quarter, 'parts' => ['dph_book', 'vat_control_statement']],
                $userId,
                'dph/ctvrtleti/' . $label . '.zip',
            );
            $packages[] = ['period' => $label] + $result;
        }

        return ['status' => 'completed', 'range' => ['from' => $from, 'to' => $to], 'packages' => $packages];
    }

    /**
     * Kompletní uzávěrky za období spadající do rozsahu. Zvolení části u firmy
     * bez podvojného účetnictví je legitimní konfigurace — manifest pak namísto
     * tichého vynechání vysvětlí, proč žádný balíček nevznikl.
     *
     * @param array<string,mixed> $supplier
     * @return array<string,mixed>
     */
    private function exportClosingPackages(
        InstanceExportArchive $archive,
        int $supplierId,
        array $supplier,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $userId,
        ?int $jobId,
        ?callable $progress,
    ): array {
        if ((string) ($supplier['accounting_mode'] ?? '') !== 'double_entry') {
            return ['status' => 'skipped', 'reason' => 'not_double_entry', 'packages' => []];
        }
        $sql = 'SELECT id, fiscal_year, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($dateFrom !== null && $dateTo !== null) {
            $sql .= ' AND starts_on <= ? AND ends_on >= ?';
            $params[] = $dateTo;
            $params[] = $dateFrom;
        }
        $sql .= ' ORDER BY starts_on, id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $packages = [];
        foreach ($periods as $period) {
            $label = (string) $period['fiscal_year'];
            $this->step($jobId, $progress, 'Uzávěrkový balíček ' . $label);
            $result = $this->runNestedReport(
                $archive,
                $supplierId,
                'closing_package',
                ['period_id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year'], 'parts' => ClosingPackageService::ALL_PARTS, 'include_xlsx' => true],
                $userId,
                'uzaverky/' . $label . '.zip',
            );
            $packages[] = ['period_id' => (int) $period['id'], 'fiscal_year' => (int) $period['fiscal_year']] + $result;
        }
        return ['status' => 'completed', 'packages' => $packages];
    }

    /**
     * Spustí existující background službu synchronně v kontextu nadřazeného
     * exportu, vloží její výsledek do něj a uklidí dočasný import_job i ZIP.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function runNestedReport(
        InstanceExportArchive $archive,
        int $supplierId,
        string $source,
        array $params,
        ?int $userId,
        string $entry,
    ): array {
        $effectiveUserId = $userId ?? $this->fallbackUserId();
        if ($effectiveUserId === null) {
            return ['status' => 'skipped', 'reason' => 'no_user_context'];
        }
        $id = $this->reportJobs->create($supplierId, $source, $params, $effectiveUserId);
        $resultPath = null;
        try {
            if ($source === 'monthly_export') {
                $this->monthlyExports->run($id);
            } else {
                $this->closingPackages->run($id);
            }
            $job = $this->reportJobs->find($id, $supplierId);
            $status = (string) ($job['status'] ?? 'failed');
            if (!in_array($status, ['completed', 'completed_with_warnings'], true) || empty($job['result_path'])) {
                return ['status' => $status, 'error' => $job['last_error'] ?? 'Dílčí export nevytvořil soubor.'];
            }
            $resultPath = $source === 'monthly_export'
                ? $this->monthlyExports->resolveResultPath((string) $job['result_path'])
                : $this->closingPackages->resolveResultPath((string) $job['result_path']);
            if (!is_file($resultPath)) {
                return ['status' => 'failed', 'error' => 'Dílčí exportní soubor chybí.'];
            }
            $archive->addFile($entry, $resultPath);
            $archive->flushNow();
            return ['status' => $status, 'entry' => $entry, 'size_bytes' => (int) filesize($resultPath)];
        } finally {
            if ($resultPath !== null && is_file($resultPath)) {
                @unlink($resultPath);
            }
            $this->reportJobs->delete($id, $supplierId);
        }
    }

    /** @return array{0:?string,1:?string} */
    private function exportRange(int $supplierId, ?string $from, ?string $to): array
    {
        if ($from !== null && $to !== null) {
            return [$from, $to];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(d) AS min_date, MAX(d) AS max_date FROM (
                SELECT effective_tax_date AS d FROM invoices WHERE supplier_id = ?
                UNION ALL SELECT issue_date AS d FROM purchase_invoices WHERE supplier_id = ?
                UNION ALL SELECT issue_date AS d FROM cash_documents WHERE supplier_id = ?
            ) dates WHERE d IS NOT NULL'
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [($row['min_date'] ?? null) ?: null, ($row['max_date'] ?? null) ?: null];
    }

    /** @return list<array{0:int,1:int}> */
    private function monthsBetween(string $from, string $to): array
    {
        $current = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        $months = [];
        while ($current <= $last) {
            $months[] = [(int) $current->format('Y'), (int) $current->format('n')];
            $current = $current->modify('+1 month');
        }
        return $months;
    }

    private function fallbackUserId(): ?int
    {
        $id = $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')?->fetchColumn();
        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Řádková data všech agend do `data/{tabulka}.jsonl`.
     *
     * @param null|callable(string):void $progress
     * @return array<string,mixed>
     */
    private function exportData(
        InstanceExportArchive $archive,
        int $supplierId,
        ?int $jobId,
        ?callable $progress,
        string $workDir,
    ): array {
        $scopes = $this->scopes->resolveAll($supplierId);
        $this->setTotal($jobId, count($scopes));
        $tables = [];
        $index = 0;
        foreach ($scopes as $table => $scope) {
            $this->assertNotCancelled($jobId);
            $index++;
            $this->step($jobId, $progress, sprintf('Data %d/%d — %s', $index, count($scopes), $table), $index);

            $tmp = $workDir . DIRECTORY_SEPARATOR . $table . '.jsonl';
            $rows = $this->writeTableJsonl($scope, $tmp);
            if ($rows === 0) {
                // Prázdné tabulky do archivu nedáváme, ale v manifestu je uvedeme —
                // jinak by nešlo odlišit "nic tam nebylo" od "zapomněli jsme to".
                @unlink($tmp);
                $tables[$table] = ['rows' => 0, 'entry' => null, 'scope' => $scope->via];
                continue;
            }
            $entry = 'data/' . $table . '.jsonl';
            $archive->addFile($entry, $tmp, deleteAfterFlush: true);
            $tables[$table] = [
                'rows' => $rows,
                'entry' => $entry,
                'scope' => $scope->via,
                'redacted_columns' => $scope->redacted,
            ];
        }
        $sharedPayrollTables = $this->exportSharedPayrollTables($archive, $workDir);
        $identity = $this->exportIdentity($archive, $supplierId, $workDir);
        // Dočasné soubory drží ZipArchive až do close — vynutíme zápis, ať se uvolní.
        $archive->flushNow();

        return [
            'format' => 'JSON Lines (UTF-8, jeden JSON objekt na řádek)',
            'tables' => $tables,
            'shared_payroll_tables' => $sharedPayrollTables,
            'shared_payroll_note' => 'Globální legislativní podklady a výpočetní obsah správcovských '
                . 'odchylek jsou součástí obnovy. Identita správců, jejich důvody ani globální audit se neexportují.',
            'identity' => $identity,
            'skipped_tables' => array_diff_key(
                $this->scopes->skipped(),
                array_fill_keys(self::SHARED_PAYROLL_TABLES, true),
            ),
            'skipped_note' => 'Vynechané tabulky jsou systémové, globální číselníky, '
                . 'nebo se je nepodařilo jednoznačně přiřadit této firmě. Data firmy v nich nejsou.',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function exportSharedPayrollTables(InstanceExportArchive $archive, string $workDir): array
    {
        $tables = [];
        foreach (self::SHARED_PAYROLL_TABLES as $table) {
            $columns = $this->exportableColumns($table);
            if ($columns === null) {
                continue;
            }
            if ($columns['unsafe_redacted'] !== []) {
                throw new InstanceExportException(
                    'shared_payroll_secret_column',
                    'Globální mzdová tabulka obsahuje tajný sloupec a nelze ji bezpečně obnovit: ' . $table . '.',
                );
            }
            $neutralized = array_map(
                static fn (string $column): string => $column . ' (global_admin_user_id_neutralized)',
                self::SHARED_PAYROLL_NEUTRALIZED_USER_COLUMNS[$table] ?? [],
            );
            $tmp = $workDir . DIRECTORY_SEPARATOR . 'shared-' . $table . '.jsonl';
            $rows = $this->writeSharedTableJsonl($table, $columns['exported'], $columns['order_by'], $tmp);
            if ($rows === 0) {
                @unlink($tmp);
                $tables[$table] = [
                    'rows' => 0,
                    'entry' => null,
                    'scope' => 'global_payroll_reference',
                    'redacted_columns' => [...$columns['redacted'], ...$neutralized],
                ];
                continue;
            }
            $entry = 'shared/payroll/' . $table . '.jsonl';
            $archive->addFile($entry, $tmp, deleteAfterFlush: true);
            $tables[$table] = [
                'rows' => $rows,
                'entry' => $entry,
                'scope' => 'global_payroll_reference',
                'redacted_columns' => [...$columns['redacted'], ...$neutralized],
            ];
        }
        return $tables;
    }

    /**
     * @return array{exported:list<string>,redacted:list<string>,unsafe_redacted:list<string>,order_by:string}|null
     */
    private function exportableColumns(string $table): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_NAME, COLUMN_KEY
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = "")
                AND (EXTRA IS NULL OR EXTRA NOT LIKE "%GENERATED%")
              ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return null;
        }
        $exported = [];
        $redacted = [];
        $unsafeRedacted = [];
        $primary = [];
        $omitted = self::SHARED_PAYROLL_OMITTED_COLUMNS[$table] ?? [];
        foreach ($rows as $row) {
            $column = (string) $row['COLUMN_NAME'];
            if (isset($omitted[$column])) {
                $redacted[] = $column . ' (' . $omitted[$column] . ')';
                continue;
            }
            if (TenantScopeResolver::isSecretColumn($column)) {
                $redacted[] = $column . ' (credential)';
                $unsafeRedacted[] = $column;
                continue;
            }
            $exported[] = $column;
            if ((string) $row['COLUMN_KEY'] === 'PRI') {
                $primary[] = $column;
            }
        }
        if ($exported === []) {
            throw new InstanceExportException('shared_payroll_empty', 'Globální mzdová tabulka nemá bezpečné sloupce: ' . $table);
        }
        $order = $primary !== [] ? $primary : [$exported[0]];
        return [
            'exported' => $exported,
            'redacted' => $redacted,
            'unsafe_redacted' => $unsafeRedacted,
            'order_by' => implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $order)),
        ];
    }

    /** @param list<string> $columns */
    private function writeSharedTableJsonl(string $table, array $columns, string $orderBy, string $filePath): int
    {
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $stmt = $this->db->pdo()->query('SELECT ' . $select . ' FROM `' . $table . '` ORDER BY ' . $orderBy);
        if ($stmt === false) {
            throw new InstanceExportException('shared_payroll_read_failed', 'Nelze načíst globální mzdovou tabulku ' . $table . '.');
        }
        $fh = fopen($filePath, 'wb');
        if ($fh === false) {
            throw new InstanceExportException('storage_failed', 'Nelze zapsat ' . $filePath);
        }
        $rows = 0;
        try {
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                foreach (self::SHARED_PAYROLL_NEUTRALIZED_USER_COLUMNS[$table] ?? [] as $column) {
                    if (array_key_exists($column, $row) && $row[$column] !== null) {
                        $row[$column] = 0;
                    }
                }
                $row = InstanceExportBinaryCodec::encodeRow($row);
                $line = json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
                );
                if ($line === false) {
                    throw new InstanceExportException('encode_failed', 'JSON encode selhal pro ' . $table . '.');
                }
                fwrite($fh, $line . "\n");
                $rows++;
            }
        } finally {
            $stmt->closeCursor();
            fclose($fh);
        }
        return $rows;
    }

    /**
     * Přihlašovací tajemství se nepřenáší, ale členové firmy ano. Po obnově jsou
     * všichni zablokovaní, dokud jim správce cílové instance neposílí pozvánku či
     * reset hesla. Uchování jejich ID zachovává účetní auditní stopu.
     *
     * @return array<string,mixed>
     */
    private function exportIdentity(InstanceExportArchive $archive, int $supplierId, string $workDir): array
    {
        $pdo = $this->db->pdo();
        $rows = [
            'users' => [],
            'user_suppliers' => [],
            'roles' => [],
            'role_permissions' => [],
        ];
        $userIds = [];
        $members = $pdo->prepare('SELECT user_id FROM user_suppliers WHERE supplier_id = ?');
        $members->execute([$supplierId]);
        foreach ($members->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            $userIds[(int) $id] = true;
        }
        // Auditní sloupce v tenantových agendách nebývají všude fyzickým FK. Jejich
        // uživatele ale přeneseme také, aby historické created_by/posted_by odkazy
        // nezůstaly v nové databázi viset.
        foreach ($this->scopes->resolveAll($supplierId) as $scope) {
            foreach ($scope->columns as $column) {
                if ($column !== 'user_id' && !str_ends_with($column, '_by')) {
                    continue;
                }
                $sql = 'SELECT DISTINCT `' . $column . '` FROM `' . $scope->table . '` WHERE ' . $scope->where
                    . ' AND `' . $column . '` IS NOT NULL';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($scope->params);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                    $userIds[(int) $id] = true;
                }
            }
        }
        $roleIds = [];
        if ($userIds !== []) {
            $marks = implode(',', array_fill(0, count($userIds), '?'));
            $members = $pdo->prepare('SELECT id, email, name, role, role_id, locale, created_at, updated_at FROM users WHERE id IN (' . $marks . ') ORDER BY id');
            $members->execute(array_keys($userIds));
        } else {
            $members = null;
        }
        foreach ($members?->fetchAll(PDO::FETCH_ASSOC) ?: [] as $member) {
            $rows['users'][] = array_filter([
                'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'],
                'role' => $member['role'], 'role_id' => $member['role_id'], 'locale' => $member['locale'],
                'is_active' => 0, 'created_at' => $member['created_at'], 'updated_at' => $member['updated_at'],
            ], static fn (mixed $value): bool => $value !== null);
            foreach ([$member['role_id']] as $roleId) {
                if ($roleId !== null) {
                    $roleIds[(int) $roleId] = true;
                }
            }
        }
        $members = $pdo->prepare('SELECT user_id, supplier_id, role, role_id, created_at FROM user_suppliers WHERE supplier_id = ? ORDER BY user_id');
        $members->execute([$supplierId]);
        foreach ($members->fetchAll(PDO::FETCH_ASSOC) ?: [] as $membership) {
            $rows['user_suppliers'][] = array_filter($membership, static fn (mixed $value): bool => $value !== null);
            if ($membership['role_id'] !== null) {
                $roleIds[(int) $membership['role_id']] = true;
            }
        }
        if ($roleIds !== []) {
            $marks = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = $pdo->prepare('SELECT id, system_key, name, role_type, is_active, created_at, updated_at FROM roles WHERE id IN (' . $marks . ')');
            $stmt->execute(array_keys($roleIds));
            $rows['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare('SELECT role_id, permission_key, access_level FROM role_permissions WHERE role_id IN (' . $marks . ')');
            $stmt->execute(array_keys($roleIds));
            $rows['role_permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $entries = [];
        foreach ($rows as $name => $data) {
            $entry = 'identity/' . $name . '.jsonl';
            $tmp = $workDir . DIRECTORY_SEPARATOR . 'identity-' . $name . '.jsonl';
            $count = $this->writeRowsJsonl($data, $tmp);
            if ($count > 0) {
                $archive->addFile($entry, $tmp, deleteAfterFlush: true);
                $entries[$name] = ['entry' => $entry, 'rows' => $count];
            } else {
                @unlink($tmp);
                $entries[$name] = ['entry' => null, 'rows' => 0];
            }
        }
        return ['entries' => $entries, 'passwords_restored' => false, 'users_restored_disabled' => true];
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeRowsJsonl(array $rows, string $filePath): int
    {
        $fh = fopen($filePath, 'wb');
        if ($fh === false) {
            throw new InstanceExportException('storage_failed', 'Nelze zapsat ' . $filePath);
        }
        try {
            foreach ($rows as $row) {
                $row = InstanceExportBinaryCodec::encodeRow($row);
                $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
                if ($line === false) {
                    throw new InstanceExportException('encode_failed', 'JSON encode selhal pro identitu uživatele.');
                }
                fwrite($fh, $line . "\n");
            }
        } finally {
            fclose($fh);
        }
        return count($rows);
    }

    /**
     * Streamovaný zápis jedné tabulky do JSONL. Nikdy nenačte víc než jednu dávku.
     *
     * @return int počet řádků
     */
    private function writeTableJsonl(TenantTableScope $scope, string $filePath): int
    {
        if ($scope->columns === []) {
            return 0;
        }
        $pdo = $this->db->pdo();
        $columns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $scope->columns));
        $fh = fopen($filePath, 'wb');
        if ($fh === false) {
            throw new InstanceExportException('storage_failed', 'Nelze zapsat ' . $filePath);
        }
        $rows = 0;
        try {
            $lastKey = null;
            $offset = 0;
            while (true) {
                if ($scope->keysetPk !== null) {
                    // Keyset: stabilní i při souběžných zápisech a nezpomaluje se
                    // s rostoucím offsetem (OFFSET 500000 znamená přeskákat 500k řádků).
                    $sql = 'SELECT ' . $columns . ' FROM `' . $scope->table . '` WHERE ' . $scope->where
                        . ($lastKey !== null ? ' AND `' . $scope->keysetPk . '` > ?' : '')
                        . ' ORDER BY `' . $scope->keysetPk . '` LIMIT ' . self::BATCH;
                    $params = $lastKey !== null ? [...$scope->params, $lastKey] : $scope->params;
                } else {
                    $sql = 'SELECT ' . $columns . ' FROM `' . $scope->table . '` WHERE ' . $scope->where
                        . ' ORDER BY ' . $scope->orderBy . ' LIMIT ' . self::BATCH . ' OFFSET ' . $offset;
                    $params = $scope->params;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                $batchCount = 0;
                $lastRow = null;
                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $encodedRow = InstanceExportBinaryCodec::encodeRow($row);
                    $line = json_encode(
                        $encodedRow,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
                    );
                    if ($line === false) {
                        throw new InstanceExportException(
                            'encode_failed',
                            'JSON encode selhal pro ' . $scope->table . ' (řádek ' . ($rows + 1) . ').',
                        );
                    }
                    fwrite($fh, $line . "\n");
                    $rows++;
                    $batchCount++;
                    $lastRow = $row;
                }
                $stmt->closeCursor();

                if ($batchCount === 0) {
                    break;
                }
                if ($scope->keysetPk !== null) {
                    $lastKey = $lastRow[$scope->keysetPk] ?? null;
                    if ($lastKey === null) {
                        break;
                    }
                } else {
                    $offset += $batchCount;
                }
                if ($batchCount < self::BATCH) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }
        return $rows;
    }

    /**
     * Doklady jako PDF v adresářích po letech a agendách — tohle je ta část, kterou
     * zákazník v praxi opravdu otevře.
     *
     * @param null|callable(string):void $progress
     * @return array<string,mixed>
     */
    private function exportDocuments(
        InstanceExportArchive $archive,
        int $supplierId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $jobId,
        ?callable $progress,
        string $workDir,
        array &$restoreBlobs,
        array &$restoreDocuments,
    ): array {
        $summary = ['vydane_pdf' => 0, 'vydane_original_pdf' => 0, 'vydane_isdoc' => 0, 'prijate_pdf' => 0, 'vypisy' => 0];
        $warnings = [];

        // 1) Vydané doklady — PDF (z archivu/cache, jinak vyrenderuje) + ISDOC.
        $issued = $this->fetchIssuedInvoices($supplierId, $dateFrom, $dateTo);
        $done = 0;
        foreach ($issued as $row) {
            $this->assertNotCancelled($jobId);
            $id = (int) $row['id'];
            $year = substr((string) ($row['issue_date'] ?? ''), 0, 4) ?: 'bez-data';
            $kind = match ((string) ($row['invoice_type'] ?? 'invoice')) {
                'proforma' => 'Proforma',
                'credit_note' => 'Dobropis',
                'cancellation' => 'Storno',
                'tax_document' => 'DanovyDoklad',
                default => 'Faktura',
            };
            $base = $kind . '-' . ExportFilename::sanitize((string) ($row['varsymbol'] ?? ('navrh-' . $id)), 'doklad-' . $id) . '-' . $id;
            try {
                $pdfPath = $this->invoicePdf->render($id);
                if (is_file($pdfPath)) {
                    $entry = "doklady/{$year}/vydane-faktury/{$base}.pdf";
                    $archive->addFile($entry, $pdfPath);
                    // Export dat vzniká před renderem PDF. Proto při obnově nenásledujeme
                    // případně zastaralé `pdf_path` z JSONL, ale explicitně jej přepojíme
                    // na právě exportovanou podobu dokladu.
                    $relativePath = 'sup-' . $supplierId . '/restored/invoice-' . $id . '.pdf';
                    $restoreDocuments[] = [
                        'entry' => $entry,
                        'storage_path' => 'invoices/' . $relativePath,
                        'sha256' => hash_file('sha256', $pdfPath) ?: null,
                        'kind' => 'issued_invoice_pdf',
                        'link' => ['table' => 'invoices', 'id' => $id, 'column' => 'pdf_path', 'value' => $relativePath],
                    ];
                    $summary['vydane_pdf']++;
                }
            } catch (\Throwable $e) {
                $warnings[] = "Vydaná {$base}: PDF — " . $e->getMessage();
            }
            $imported = $this->archiveDocumentSource($this->invoiceImportArchiveRoot(), (string) ($row['imported_pdf_path'] ?? ''));
            if ($imported !== null) {
                try {
                    $entry = "doklady/{$year}/vydane-faktury-original/{$base}-original.pdf";
                    $archive->addFile($entry, $imported);
                    $restoreDocuments[] = [
                        'entry' => $entry,
                        'storage_path' => 'invoices-imported/' . (string) $row['imported_pdf_path'],
                        'sha256' => hash_file('sha256', $imported) ?: null,
                        'kind' => 'issued_invoice_original_pdf',
                    ];
                    $summary['vydane_original_pdf']++;
                } catch (\Throwable $e) {
                    $warnings[] = "Vydaná {$base}: originál PDF — " . $e->getMessage();
                }
            }
            try {
                $invoice = $this->invoiceRepo->find($id);
                if ($invoice !== null) {
                    $archive->addString("doklady/{$year}/vydane-faktury-isdoc/{$base}.isdoc", $this->isdoc->buildXml($invoice));
                    $summary['vydane_isdoc']++;
                }
            } catch (\Throwable $e) {
                $warnings[] = "Vydaná {$base}: ISDOC — " . $e->getMessage();
            }
            $done++;
            if ($done % 10 === 0) {
                $this->step($jobId, $progress, sprintf('Vydané doklady %d/%d', $done, count($issued)));
            }
        }

        // 2) Přijaté doklady — přednost má ORIGINÁL od dodavatele (průkazný podle
        //    § 35 ZDPH); rekonstrukce z našich dat je až náhrada, a je tak i pojmenovaná.
        $purchase = $this->fetchPurchaseInvoices($supplierId, $dateFrom, $dateTo);
        $archiveRoot = $this->purchaseArchiveRoot();
        $archiveRootReal = realpath($archiveRoot);
        $done = 0;
        foreach ($purchase as $row) {
            $this->assertNotCancelled($jobId);
            $id = (int) $row['id'];
            $year = substr((string) ($row['issue_date'] ?? ''), 0, 4) ?: 'bez-data';
            $label = ExportFilename::sanitize(
                (string) ($row['varsymbol'] ?? $row['vendor_invoice_number'] ?? ('id-' . $id))
                . '-' . (string) ($row['vendor_company_name'] ?? 'dodavatel'),
                'prijata-' . $id,
            );
            $label = substr($label, 0, 100) . '-' . $id;

            $original = $archiveRootReal === false ? null : $this->archiveDocumentSource($archiveRoot, (string) ($row['pdf_path'] ?? ''));
            try {
                if ($original !== null) {
                    $entry = "doklady/{$year}/prijate-faktury/Prijata-{$label}.pdf";
                    $archive->addFile($entry, $original);
                    $restoreDocuments[] = [
                        'entry' => $entry,
                        'storage_path' => 'purchase-invoices/' . (string) $row['pdf_path'],
                        'sha256' => hash_file('sha256', $original) ?: null,
                        'kind' => 'purchase_invoice_original_pdf',
                    ];
                } else {
                    $archive->addString(
                        "doklady/{$year}/prijate-faktury/Prijata-{$label}-rekonstrukce.pdf",
                        $this->purchasePdf->render($id, $supplierId),
                    );
                }
                $summary['prijate_pdf']++;
            } catch (\Throwable $e) {
                $warnings[] = "Přijatá {$label}: " . $e->getMessage();
            }
            $done++;
            if ($done % 10 === 0) {
                $this->step($jobId, $progress, sprintf('Přijaté doklady %d/%d', $done, count($purchase)));
            }
        }

        // 3) Výpisy z účtu — obsah je BLOB, takže se tahá PO JEDNOM řádku a jde rovnou
        //    do dočasného souboru. Načíst celý sloupec do pole by u roku provozu
        //    znamenalo stovky MB v paměti.
        $summary['vypisy'] = $this->exportBankStatementFiles($archive, $supplierId, $dateFrom, $dateTo, $jobId, $progress, $workDir, $warnings, $restoreBlobs);

        if ($warnings !== []) {
            foreach (array_slice($warnings, 0, 50) as $w) {
                $this->note($jobId, $progress, 'Upozornění: ' . $w);
            }
        }
        return $summary + ['warnings' => count($warnings)];
    }

    /**
     * @param list<string> $warnings
     */
    private function exportBankStatementFiles(
        InstanceExportArchive $archive,
        int $supplierId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $jobId,
        ?callable $progress,
        string $workDir,
        array &$warnings,
        array &$restoreBlobs,
    ): int {
        $scopes = $this->scopes->resolveAll($supplierId);
        $scope = $scopes['bank_statements'] ?? null;
        if ($scope === null) {
            // Tabulku se nepodařilo přiřadit firmě → default deny. Radši výpisy
            // vynechat, než riskovat cizí.
            $warnings[] = 'Bankovní výpisy nešlo jednoznačně přiřadit firmě — vynechány.';
            return 0;
        }

        $where = $scope->where;
        $params = $scope->params;
        if ($dateFrom !== null && $dateTo !== null) {
            $where .= ' AND (statement_date IS NULL OR statement_date BETWEEN ? AND ?)';
            $params[] = $dateFrom;
            $params[] = $dateTo;
        }
        $pdo = $this->db->pdo();
        $idStmt = $pdo->prepare('SELECT id FROM bank_statements WHERE ' . $where . ' ORDER BY id');
        $idStmt->execute($params);
        $ids = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $count = 0;
        foreach ($ids as $i => $id) {
            $this->assertNotCancelled($jobId);
            // Klíčové: WHERE nese celý tenant scope znovu, ne jen id. I kdyby se
            // seznam id někde ušpinil, řádek cizí firmy se sem nedostane.
            $stmt = $pdo->prepare(
                'SELECT statement_date, account_number, file_name, pdf_name, file_content, pdf_content
                   FROM bank_statements WHERE id = ? AND (' . $scope->where . ')'
            );
            $stmt->execute([$id, ...$scope->params]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if ($row === false) {
                continue;
            }
            $year = substr((string) ($row['statement_date'] ?? ''), 0, 4) ?: 'bez-data';
            $account = ExportFilename::sanitize((string) ($row['account_number'] ?? 'ucet'), 'ucet');

            foreach ([['file_content', 'file_name', 'gpc'], ['pdf_content', 'pdf_name', 'pdf']] as [$contentCol, $nameCol, $ext]) {
                $content = $row[$contentCol] ?? null;
                if (!is_string($content) || $content === '') {
                    continue;
                }
                $name = (string) ($row[$nameCol] ?? '');
                $name = $name !== '' ? ExportFilename::sanitize($name) : sprintf('vypis-%s-%d.%s', $account, $id, $ext);
                $tmp = $workDir . DIRECTORY_SEPARATOR . 'stmt-' . $id . '.' . $ext;
                file_put_contents($tmp, $content);
                unset($content, $row[$contentCol]);
                // Dva zdrojové soubory jednoho výpisu mohou mít stejný původní
                // název. ID i typ je proto součástí cesty — položka ZIPu je pak
                // jednoznačný obnovovací zdroj pro konkrétní BLOB.
                $entry = "doklady/{$year}/vypisy-z-uctu/vypis-{$id}-{$contentCol}-{$name}";
                $archive->addFile($entry, $tmp, deleteAfterFlush: true);
                $restoreBlobs[] = ['entry' => $entry, 'table' => 'bank_statements', 'id' => $id, 'column' => $contentCol];
                $count++;
            }
            unset($row);
            if (($i + 1) % 10 === 0) {
                $this->step($jobId, $progress, sprintf('Výpisy z účtu %d/%d', $i + 1, count($ids)));
                $archive->flushNow();
            }
        }
        $archive->flushNow();
        return $count;
    }

    /**
     * Nahrané soubory — skeny přijatých faktur, přílohy deníku (§ 33a), dokumenty DMS
     * a mzdové doklady.
     *
     * Izolaci tu dělá CESTA: každé z těch úložišť má per-firma adresář `sup-{id}`,
     * takže se sbírá jen ten. DMS a deník před vložením přeloží
     * {@see ReadableDocumentArchiveLayout} z interních SHA cest do složek a
     * původních názvů; surový sběrač zůstává jen pro ostatní typy příloh.
     *
     * @param null|callable(string):void $progress
     * @return array<string,mixed>
     */
    private function exportFiles(
        InstanceExportArchive $archive,
        int $supplierId,
        ?int $jobId,
        ?callable $progress,
        array &$restoreFiles,
    ): array {
        $readableFiles = (new ReadableDocumentArchiveLayout($this->db->pdo()))->forSupplier(
            $supplierId,
            'prilohy/dokumenty',
            'prilohy/denik',
        );
        $sources = [
            [RuntimePaths::storage('payroll-documents/sup-' . $supplierId), null, 'prilohy/mzdy'],
            [RuntimePaths::storage('payroll-payment-exports/sup-' . $supplierId), null, 'prilohy/mzdove-platebni-exporty'],
            [RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/_archive', null, 'prilohy/vydane-faktury-archiv'],
            [RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/attachments', null, 'prilohy/vydane-faktury-prilohy'],
        ];
        $skipped = [];
        $files = BackupFileCollector::collect(
            $sources,
            // Náhledy se dají vyrobit znovu; `_jobs` jsou pracovní soubory.
            ['/_thumbs/', '/_jobs/'],
            ['.tmp-'],
            static function (string $abs) use (&$skipped): void { $skipped[] = $abs; },
        );

        $total = count($readableFiles) + count($files);
        $done = 0;
        $bytes = 0;
        foreach ($readableFiles as $file) {
            $this->assertNotCancelled($jobId);
            $archive->addFile($file['entry'], $file['source']);
            $restoreFiles[] = [
                'entry' => $file['entry'], 'storage_path' => $file['storage_path'],
                'sha256' => $file['sha256'], 'kind' => $file['kind'],
            ];
            $bytes += (int) (@filesize($file['source']) ?: 0);
            $done++;
            if ($done % 50 === 0) {
                $this->step($jobId, $progress, sprintf('Přílohy %d/%d', $done, $total));
            }
        }
        foreach ($files as $abs => $entry) {
            $this->assertNotCancelled($jobId);
            $archive->addFile($entry, $abs);
            $storagePath = null;
            foreach ($sources as [$sourceRoot]) {
                $root = rtrim(str_replace('\\', '/', $sourceRoot), '/');
                $normalized = str_replace('\\', '/', $abs);
                if (str_starts_with(strtolower($normalized), strtolower($root . '/'))) {
                    $storagePath = ltrim(substr($normalized, strlen(RuntimePaths::storage()) + 1), '/');
                    break;
                }
            }
            if ($storagePath !== null) {
                $restoreFiles[] = [
                    'entry' => $entry,
                    'storage_path' => $storagePath,
                    'sha256' => $archive->entries()[$entry]['sha256'] ?? null,
                    'kind' => 'attachment',
                ];
            }
            $bytes += (int) (@filesize($abs) ?: 0);
            $done++;
            if ($done % 50 === 0) {
                $this->step($jobId, $progress, sprintf('Přílohy %d/%d', $done, $total));
            }
        }
        $archive->flushNow();

        return [
            'files' => $done,
            'bytes' => $bytes,
            'sources' => ['prilohy/dokumenty', 'prilohy/denik', ...array_map(static fn (array $s): string => $s[2], $sources)],
            'skipped_outside_root' => count($skipped),
        ];
    }

    // ── dotazy ────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function fetchIssuedInvoices(int $supplierId, ?string $from, ?string $to): array
    {
        $sql = 'SELECT id, varsymbol, invoice_type, issue_date, imported_pdf_path FROM invoices WHERE supplier_id = ?';
        $params = [$supplierId];
        if ($from !== null && $to !== null) {
            $sql .= ' AND issue_date BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY issue_date, id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function fetchPurchaseInvoices(int $supplierId, ?string $from, ?string $to): array
    {
        // Jméno dodavatele je na kontaktu, ne na dokladu. LEFT JOIN schválně: chybějící
        // kontakt smí zhoršit jen pojmenování souboru, nikdy vypustit doklad z archivu.
        $sql = 'SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number, pi.issue_date, pi.pdf_path,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
             LEFT JOIN clients c ON c.id = pi.vendor_id
                 WHERE pi.supplier_id = ?';
        $params = [$supplierId];
        if ($from !== null && $to !== null) {
            $sql .= ' AND pi.issue_date BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY pi.issue_date, pi.id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    private function fetchSupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name, ic, dic, accounting_mode, is_vat_payer, vat_period FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Stejné pořadí zdrojů jako v MonthlyExportService — jeden výklad konfigurace. */
    private function purchaseArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/purchase-invoices';
        }
        return RuntimePaths::storage('purchase-invoices');
    }

    /** Stejná konfigurace jako DownloadImportedPdfAction a oba importéry vydaných faktur. */
    private function invoiceImportArchiveRoot(): string
    {
        $dir = (string) $this->config->get('invoice.import_archive_storage', '');
        if ($dir !== '') {
            return $dir;
        }
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') {
            return dirname($uploads) . '/invoices-imported';
        }
        return RuntimePaths::storage('invoices-imported');
    }

    /** Vrátí soubor pouze tehdy, když relativní DB cesta bezpečně míří pod archive root. */
    private function archiveDocumentSource(string $archiveRoot, string $relativePath): ?string
    {
        if ($relativePath === '') {
            return null;
        }
        $root = realpath($archiveRoot);
        if ($root === false) {
            return null;
        }
        $candidate = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($candidate === false || !is_file($candidate)) {
            return null;
        }
        $needle = strtolower(rtrim($root, '/\\') . DIRECTORY_SEPARATOR);
        return str_starts_with(strtolower($candidate), $needle) ? $candidate : null;
    }

    // ── pomocné ───────────────────────────────────────────────────────────────

    private function ttlDays(): int
    {
        $days = (int) $this->config->get('export.instance.ttl_days', self::DEFAULT_TTL_DAYS);
        return $days > 0 ? $days : self::DEFAULT_TTL_DAYS;
    }

    private function maxBytes(): int
    {
        $max = (int) $this->config->get('export.instance.max_bytes', self::DEFAULT_MAX_BYTES);
        return $max > 0 ? $max : self::DEFAULT_MAX_BYTES;
    }

    private function setTotal(?int $jobId, int $total): void
    {
        if ($jobId !== null) {
            $this->jobs->updateProgress($jobId, ['total_steps' => $total]);
        }
    }

    /** @param null|callable(string):void $progress */
    private function step(?int $jobId, ?callable $progress, string $label, ?int $processed = null): void
    {
        if ($jobId !== null) {
            $updates = ['current_step' => mb_substr($label, 0, 160)];
            if ($processed !== null) {
                $updates['processed_steps'] = $processed;
            }
            $this->jobs->updateProgress($jobId, $updates);
        }
        if ($progress !== null) {
            $progress($label);
        }
    }

    /** @param null|callable(string):void $progress */
    private function note(?int $jobId, ?callable $progress, string $line): void
    {
        if ($jobId !== null) {
            $this->jobs->appendLog($jobId, $line);
        }
        if ($progress !== null) {
            $progress($line);
        }
    }

    private function assertNotCancelled(?int $jobId): void
    {
        if ($jobId !== null && $this->jobs->isCancelRequested($jobId)) {
            throw new InstanceExportCancelled('Export zrušen uživatelem.');
        }
    }

    /** @param array<string, array{sha256:string, size:int}> $entries */
    private function renderChecksums(array $entries): string
    {
        $out = "# SHA-256 položek archivu (formát: sha256<mezera><mezera>cesta)\n"
            . "# Ověření: sha256sum -c CHECKSUMS.txt  (Windows: certutil -hashfile <soubor> SHA256)\n"
            . "# CHECKSUMS.txt, manifest.json a CTI-MNE.txt tu nejsou — vznikají až po nich.\n";
        foreach ($entries as $name => $meta) {
            $out .= $meta['sha256'] . '  ' . $name . "\n";
        }
        return $out;
    }

    /** @param array<string,mixed> $manifest */
    private function renderReadme(array $supplier, array $manifest, bool $encrypted): string
    {
        $sections = $manifest['sections'] ?? [];
        $tableCount = count($sections['data']['tables'] ?? []);
        $sharedPayrollTableCount = count($sections['data']['shared_payroll_tables'] ?? []);
        $lines = [
            'EXPORT DAT — ' . (string) $supplier['company_name'],
            str_repeat('=', 60),
            '',
            'Vytvořeno:      ' . (string) $manifest['read_finished_at'],
            'Verze aplikace: ' . (string) ($manifest['app_version'] ?? 'neuvedena'),
            'Formát archivu: ' . (string) $manifest['format'] . ' v' . (string) $manifest['version'],
            'Položek:        ' . (string) ($manifest['totals']['entries'] ?? 0),
            '',
            'CO V ARCHIVU JE',
            str_repeat('-', 60),
            'obnova         Tento ZIP je přímo obnovitelný (je-li zvolený); nevzniká druhý archiv.',
            '               Lze jej obnovit do prázdné, předem migrované databáze stejné nebo',
            '               NOVĚJŠÍ verze MyÚčto přes archive-restore.php.',
            'data/          Strojově čitelný export databáze — jeden soubor na tabulku,',
            '               formát JSON Lines (JSONL): jeden JSON objekt na řádek, UTF-8.',
            '               Otevře ho Excel/LibreOffice přes import, Python (pandas.read_json',
            '               s lines=True), jq i běžný textový editor. Tabulek: ' . $tableCount . '.',
            'shared/payroll Globální legislativní podklady mezd nutné k úplné obnově.',
            '               Tabulek: ' . $sharedPayrollTableCount . '; neobsahují data jiných firem.',
            'doklady/       PDF dokladů po LETECH a agendách (vydané faktury, přijaté faktury,',
            '               výpisy z účtu) a ISDOC vydaných faktur. Tohle je část, kterou',
            '               v praxi potřebuješ nejčastěji — otevře ji jakákoli čtečka PDF.',
            'prilohy/       Nahrané soubory: skeny, přílohy účetního deníku, dokumenty, mzdové',
            '               doklady a zašifrované bankovní exporty mzdových plateb.',
            'dph/           Hromadné podklady DPH po měsících; u čtvrtletního plátce i',
            '               za čtvrtletí (Kniha DPH v PDF a Kontrolní hlášení v XML).',
            'uzaverky/      Kompletní uzávěrkové balíčky za účetní období firmy vedené',
            '               v podvojném účetnictví.',
            'manifest.json  Co v archivu je, kolik toho je, k jakému datu a z jaké verze',
            '               aplikace. Obsahuje i seznam tabulek, které v archivu ZÁMĚRNĚ nejsou.',
            'CHECKSUMS.txt  SHA-256 každé položky archivu.',
            '',
            'JAK OVĚŘIT, ŽE JE ARCHIV CELÝ',
            str_repeat('-', 60),
            'Vedle staženého ZIPu je soubor stejného jména s příponou .sha256 — obsahuje',
            'kontrolní součet CELÉHO archivu. Porovnej ho s tím, co spočítá tvůj počítač:',
            '  Linux/macOS:  sha256sum <soubor>.zip',
            '  Windows:      certutil -hashfile <soubor>.zip SHA256',
            'Jednotlivé položky uvnitř ověříš proti CHECKSUMS.txt.',
            '',
        ];
        if ($encrypted) {
            $lines[] = 'ŠIFROVÁNÍ';
            $lines[] = str_repeat('-', 60);
            $lines[] = 'Archiv je šifrovaný AES-256 (WinZip AE-2), stejně jako zálohy.';
            $lines[] = 'Vestavěný Průzkumník Windows ho NEOTEVŘE — použij 7-Zip, WinRAR';
            $lines[] = 'nebo `unzip -P <heslo>`. Heslo je to z konfigurace instance.';
            $lines[] = '';
        }
        $lines[] = 'POZNÁMKA K ROZSAHU';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'Archiv obsahuje data JEDNÉ firmy (' . (string) $supplier['company_name'] . ', ID '
            . (string) $supplier['id'] . '). Pokud vedeš v aplikaci víc firem, vyexportuj';
        $lines[] = 'každou zvlášť.';
        $lines[] = '';
        $lines[] = 'Přihlašovací údaje, tokeny a klíče k integracím v archivu ZÁMĚRNĚ nejsou —';
        $lines[] = 'nejsou to účetní záznamy a archiv opouští instalaci. Seznam vynechaných';
        $lines[] = 'sloupců je v manifest.json.';
        $lines[] = 'Globální audit mzdových rulesetů ani důvody a identity správců se nepřenášejí;';
        $lines[] = 'u schválených odchylek zůstává jen neosobní informace, že schválení proběhlo.';
        $lines[] = 'Mzdové osobní údaje a platební exporty zůstávají uvnitř kontextově zašifrované;';
        $lines[] = 'pro jejich čtení po obnově musí cílová instalace bezpečně převzít původní';
        $lines[] = 'app.secret_encryption_key (nebo jej ponechat mezi previous keys). Klíč v archivu není.';
        $lines[] = '';
        $lines[] = 'OBNOVA';
        $lines[] = str_repeat('-', 60);
        $lines[] = 'V cílové, stejné nebo NOVĚJŠÍ instalaci nejdřív připrav prázdnou migrovanou databázi';
        $lines[] = 'a prázdný datový adresář. Nad TÍMTO ZIPem spusť:';
        $lines[] = '  php api/bin/archive-restore.php --file=export.zip --database=prazdna_db --dry-run';
        $lines[] = 'a po úspěšné kontrole stejný příkaz s `--restore --storage=cesta-k-novym-datum --documents`.';
        $lines[] = 'Přepínač --documents vrátí PDF přijatých i vydaných faktur do úložiště aplikace';
        $lines[] = 'a vydané PDF znovu propojí s doklady. Obnova zachová interní ID a vazby;';
        $lines[] = 'nepřepíše proto existující data.';
        $lines[] = '';
        $lines[] = 'Data se čtou průběžně, ne v jednom okamžiku (viz read_started_at /';
        $lines[] = 'read_finished_at v manifestu) — archiv vznikl v tomhle časovém okně.';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function schemaVersion(): string
    {
        try {
            $version = $this->db->pdo()->query('SELECT MAX(filename) FROM migrations')?->fetchColumn();
            return $version === false || $version === null ? 'unknown' : (string) $version;
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function appVersion(): ?string
    {
        foreach ([Bootstrap::rootDir() . '/VERSION', dirname(Bootstrap::rootDir()) . '/VERSION'] as $path) {
            if (is_file($path)) {
                $content = trim((string) file_get_contents($path));
                return $content === '' ? null : $content;
            }
        }
        return null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
