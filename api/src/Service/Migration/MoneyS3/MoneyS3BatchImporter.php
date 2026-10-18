<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\License\LicenseCompanyLimitExceeded;
use MyInvoice\Service\Migration\Shared\AbstractImportJobService;
use MyInvoice\Service\Migration\Shared\CompanyProfileCarryOver;
use MyInvoice\Service\Migration\Shared\FiledDppoFiling;
use MyInvoice\Service\Migration\Shared\FiledDppoImporter;
use MyInvoice\Service\Migration\Shared\MigrationBatchActor;
use MyInvoice\Service\Migration\Shared\MigrationBatchRunner;
use MyInvoice\Service\Migration\Shared\MigrationCompanyIdentity;
use MyInvoice\Service\Migration\Shared\MigrationCompanyResolver;
use MyInvoice\Service\Migration\Shared\ReconciliationCriteria;
use MyInvoice\Service\Tax\Return\TaxReturnException;

/**
 * Převod jedné zálohy Money S3 v dávce více firem (účetní kancelář): najde firmu podle
 * IČO v záloze, nebo ji založí (identita ze zálohy, podaného DPPO a ARES), zvolí rok
 * „od", pustí produktový převod ({@see MoneyS3Importer}, tentýž jako průvodce jedné
 * firmy) s protokolem uloženým k firmě, převezme podaná přiznání k DPPO
 * ({@see FiledDppoImporter}) a vrátí souhrn pro protokol dávky (K1–K4, uzávěrka,
 * převzatá přiznání, upozornění k identitě).
 *
 * Opakovatelnost: firma, kterou dávka už založila, se při dalším běhu najde podle IČO;
 * s volbou „převést znovu" převod doplní jen to, co chybí (mapa převodu), jinak se
 * přeskočí. Nastavení firmy přenese {@see CompanyProfileCarryOver}.
 *
 * Zkouška nanečisto založí firmu i celý převod v transakci, která se na konci vrátí:
 * v databázi nezůstane nic (ani firma, ani záznam běhu), výsledek je jen v souhrnu.
 */
final class MoneyS3BatchImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3Importer $importer,
        private readonly JournalImporter $journal,
        private readonly MoneyS3ImportRepository $runs,
        private readonly MigrationCompanyResolver $companies,
        private readonly FiledDppoImporter $filed,
        private readonly CompanyProfileCarryOver $profiles,
    ) {}

    /**
     * Rozhodnutí platná pro celou dávku: skupina firem (u ostrého převodu se případně
     * založí) a spřízněné osoby (IČO firem dávky a skupiny).
     *
     * @param list<string> $icos IČO agend dávky
     */
    public function prepareBatch(MoneyS3BatchOptions $options, array $icos): MoneyS3BatchOptions
    {
        $groupId = $options->groupId;
        if ($groupId === null && $options->groupName !== null && !$options->isDryRun()) {
            $groupId = $this->companies->ensureGroup(null, $options->groupName);
        } elseif ($groupId !== null) {
            $groupId = $this->companies->ensureGroup($groupId, null);
        }
        $related = [];
        if ($options->relatedParties) {
            $related = array_merge($icos, $options->relatedPartyIcos, $groupId !== null ? $this->companies->groupIcos($groupId) : []);
            $related = array_values(array_unique(array_filter(array_map([CodebookImporter::class, 'ico'], $related))));
        }
        return $options->withGroup($groupId, $related);
    }

    /**
     * Převod jedné zálohy.
     *
     * @param string $lzPath záloha agendy (.lz)
     * @param string $workDir prázdný pracovní adresář pro rozbalení (po převodu se smaže)
     * @param list<array{filing:FiledDppoFiling,xml:string,file?:string}> $filings podaná přiznání dávky (všech firem)
     * @param (callable(string,int,int):void)|null $progress průběh kroků převodu
     * @param (callable():bool)|null $cancel požádal uživatel o zrušení?
     * @return array{status:string,target_supplier_id:?int,company_action:?string,from_year:?int,run_id:?int,summary:array<string,mixed>,error:?string}
     */
    public function importCompany(
        string $lzPath,
        string $workDir,
        MoneyS3BatchOptions $options,
        MigrationBatchActor $actor,
        array $filings = [],
        ?int $jobId = null,
        ?callable $progress = null,
        ?callable $cancel = null,
    ): array {
        self::removeTree($workDir);
        try {
            $backup = Ms3Backup::extract($lzPath, $workDir);
            $agenda = AgendaInfo::fromBackup($backup);
            $ico = CodebookImporter::ico($agenda->ico);
            $summary = ['agenda' => ['name' => $agenda->name, 'ico' => $agenda->ico, 'version' => $agenda->version, 'years' => $agenda->fiscalYears()]];
            if ($ico === '') {
                throw new MoneyS3Exception('agenda_ico_missing', 'Záloha nenese IČO firmy, dávka ji k firmě nepřiřadí. Převeďte ji průvodcem jedné firmy.');
            }
            $conflict = $agenda->taxRecordsAfterDoubleEntry();
            if ($conflict !== null) {
                // Převod by firmu přepnul do podvojného účetnictví i pro roky daňové evidence.
                throw new MoneyS3Exception('tax_records_after_double_entry', AgendaInfo::textTaxRecordsAfterDoubleEntry($conflict));
            }

            $existing = $this->companies->findExisting($ico, $actor->allowedSupplierIds);
            if ($existing['access'] === MigrationCompanyResolver::ACCESS_FORBIDDEN) {
                throw new MoneyS3Exception('company_forbidden', "Firma s IČO {$ico} už v MyÚčtu je, ale nemáte k ní přístup. Převod do ní musí spustit její účetní.");
            }
            if ($existing['access'] === MigrationCompanyResolver::ACCESS_AMBIGUOUS) {
                throw new MoneyS3Exception('company_ambiguous', "V MyÚčtu je víc firem s IČO {$ico}. Zálohu převeďte průvodcem jedné firmy v té správné.");
            }
            $targetId = $existing['supplier_id'];
            if ($targetId !== null && $options->existing === MoneyS3BatchOptions::EXISTING_SKIP) {
                return self::result(MigrationBatchRunner::SKIPPED, $targetId, 'skipped', null, null,
                    $summary + ['note' => 'Firma už v MyÚčtu je, dávka ji podle volby přeskočila.'], null);
            }
            if ($targetId === null && !$actor->canCreate) {
                throw new MoneyS3Exception('create_forbidden', "Firma s IČO {$ico} v MyÚčtu není a zakládat firmy nemáte oprávnění.");
            }

            $perYear = FiledDppoFiling::latestPerYear($filings, $ico, static fn (array $e): string => (string) ($e['file'] ?? ''));
            $fromYear = $this->fromYear($backup, $options, $targetId);
            $summary['from_year_auto'] = $options->fromYear === MoneyS3BatchOptions::FROM_YEAR_AUTO;
            $summary['filings_available'] = array_keys($perYear);

            $dryRun = $options->isDryRun();
            $pdo = $this->db->pdo();
            $savepoint = $dryRun && $pdo->inTransaction();
            if ($savepoint) {
                $pdo->exec('SAVEPOINT money_s3_batch_dry_run');
            } elseif ($dryRun) {
                $pdo->beginTransaction();
            }
            try {
                $action = 'existing';
                if ($targetId === null) {
                    $identity = $this->identity($ico, $agenda, $backup, $perYear, $options);
                    $summary['identity'] = $identity->toArray();
                    $targetId = $this->companies->create($identity, $actor->userId, $actor->assignCreator, $options->useRegistry);
                    $action = 'created';
                }
                if ($options->groupId !== null) {
                    $this->companies->assignGroup($targetId, $options->groupId);
                }
                $firstPeriodStart = $action === 'created' ? ($summary['identity']['first_period_start'] ?? null) : null;
                $profile = !$dryRun && $action === 'existing' ? $this->profiles->capture($targetId) : null;

                $importOptions = new ImportOptions(
                    $options->mode,
                    $options->closeHistory,
                    $firstPeriodStart,
                    array_values(array_diff($options->relatedPartyIcos, [$ico])),
                    [],
                    false,
                    $fromYear,
                    $options->disposalYearTax,
                );
                [$protocol, $runId] = $this->runImport($targetId, $actor->userId, $backup, $lzPath, $agenda, $importOptions, $jobId, $progress, $cancel);
                $result = $protocol->toArray();
                $summary += self::protocolSummary($result);
                $status = $result['failure'] === 'cancelled' ? MigrationBatchRunner::CANCELLED : $protocol->status();
                // Převedená = bez chyb, nebo s jedinou chybou nesouhlasu rekonciliace (rozdíl bývá už
                // v Money). Jiná chyba (nepřevzatý doklad) další kroky nad firmou nepouští.
                $converted = !$protocol->hasErrors() || self::onlyReconciliationErrors($result);
                $summary['converted'] = $converted && $status !== MigrationBatchRunner::CANCELLED;

                $notices = [];
                if (!$dryRun && $summary['converted']) {
                    if ($options->takeOverFilings && $perYear !== []) {
                        [$summary['filings'], $filingWarnings] = $this->takeOverFilings($targetId, $actor->userId, $perYear);
                        $notices = array_merge($notices, $filingWarnings);
                    }
                    if ($profile !== null) {
                        $notices = array_merge($notices, $this->profiles->restore($targetId, $profile));
                    }
                } elseif ($dryRun) {
                    $summary['protocol'] = $result;
                }
                $summary['notices'] = array_merge($summary['identity']['notes'] ?? [], $notices);
                if ($status === MigrationBatchRunner::COMPLETED && ($notices !== [] || ($summary['identity']['notes'] ?? []) !== [])) {
                    $status = MigrationBatchRunner::COMPLETED_WITH_WARNINGS;
                }

                $error = null;
                if ($status === MigrationBatchRunner::FAILED) {
                    $where = $dryRun ? 'v protokolu zkoušky' : "v protokolu převodu #{$runId} u firmy";
                    $error = match (true) {
                        self::onlyReconciliationErrors($result) => "Převod firmy doběhl, ale nesedí rekonciliace — podrobnosti {$where}.",
                        $protocol->failed() => "Převod firmy nedoběhl — podrobnosti {$where}.",
                        default => "Převod firmy doběhl s chybami (" . ($summary['errors'] ?? 0) . ", první: " . ($summary['first_error']['text'] ?? '') . ") — podrobnosti {$where}.",
                    };
                }
                return self::result($status, $dryRun && $action === 'created' ? null : $targetId, $action, $fromYear,
                    $dryRun ? null : $runId, $summary, $error);
            } finally {
                if ($savepoint) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_batch_dry_run');
                } elseif ($dryRun && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (LicenseCompanyLimitExceeded) {
            throw new MoneyS3Exception('license_company_limit', 'Byl dosažen počet firem podle licence, další firmu dávka nezaloží.');
        } finally {
            self::removeTree($workDir);
        }
    }

    /**
     * Převod se zámkem firmy a záznamem běhu — stejně jako job průvodce jedné firmy
     * ({@see MoneyS3ImportJobService}).
     *
     * @return array{0:ImportProtocol,1:int}
     */
    private function runImport(int $supplierId, int $userId, Ms3Backup $backup, string $lzPath, AgendaInfo $agenda, ImportOptions $options, ?int $jobId, ?callable $progress, ?callable $cancel): array
    {
        if (!$this->runs->acquireLock($supplierId)) {
            throw new MoneyS3Exception('already_running', 'Převod této firmy už běží v jiném procesu, dávka ho nespouští podruhé.');
        }
        try {
            $this->runs->closeInterruptedRuns($supplierId);
            $runId = $this->runs->startRun($supplierId, $jobId, $options->mode, [
                'agenda_ico' => $agenda->ico,
                'agenda_name' => $agenda->name,
                'money_version' => $agenda->version,
                'backup_sha256' => (string) hash_file('sha256', $lzPath),
            ], $userId > 0 ? $userId : null);
            try {
                $protocol = $this->importer->run($supplierId, $userId, $backup, $options, $runId, $progress, $cancel, $agenda);
            } catch (\Throwable $e) {
                $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $options->mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => 'Převod selhal na neočekávané chybě.', 'steps' => []]);
                throw $e;
            }
            $array = $protocol->toArray();
            $this->runs->finishRun($runId, $supplierId, $array['failure'] === 'cancelled' ? 'cancelled' : $protocol->status(), $array);
            return [$protocol, $runId];
        } finally {
            $this->runs->releaseLock($supplierId);
        }
    }

    /** Rok = label účetního období firmy; nově zakládaná firma vede kalendářní rok. */
    private function fromYear(Ms3Backup $backup, MoneyS3BatchOptions $options, ?int $targetId): ?int
    {
        if ($options->fromYear === MoneyS3BatchOptions::FROM_YEAR_AUTO) {
            $calendar = $targetId !== null ? $this->journal->companyCalendar($targetId) : null;
            return JournalImporter::suggestedFromYear($this->journal->chainBreaks($backup, new ImportOptions(), $calendar));
        }
        return is_int($options->fromYear) ? $options->fromYear : null;
    }

    /**
     * @param array<int,array{filing:FiledDppoFiling}> $perYear
     */
    private function identity(string $ico, AgendaInfo $agenda, Ms3Backup $backup, array $perYear, MoneyS3BatchOptions $options): MigrationCompanyIdentity
    {
        $latest = $perYear !== [] ? $perYear[array_key_last($perYear)]['filing'] : null;
        $registry = $options->useRegistry ? $this->companies->lookupRegistry($ico, $agenda->dic !== '' ? $agenda->dic : (string) $latest?->dic) : ['ares' => null, 'vat_payer' => null];
        return MigrationCompanyIdentity::merge(
            $ico,
            ['name' => $agenda->name, 'dic' => $agenda->dic, 'street' => $agenda->street, 'city' => $agenda->city, 'zip' => $agenda->zip],
            $latest,
            $registry['ares'],
            $registry['vat_payer'],
            $registry['vat_payer'] === null ? self::journalUsesVat($backup) : null,
            FiledDppoFiling::firstPeriodStart($perYear),
        );
    }

    /**
     * Převzetí podaných přiznání vzestupně po letech (ztráta vzniklá v jednom roce navazuje
     * na uplatnění v dalších). Existující rozpracované přiznání se nepřepisuje, finální
     * se nemění nikdy ({@see FiledDppoImporter::apply()}).
     *
     * @param array<int,array{filing:FiledDppoFiling,xml:string}> $perYear
     * @return array{0:list<array<string,mixed>>,1:list<string>} převzetí po letech a upozornění
     */
    private function takeOverFilings(int $supplierId, int $userId, array $perYear): array
    {
        $out = [];
        $warnings = [];
        foreach ($perYear as $year => $entry) {
            try {
                $r = $this->filed->apply($supplierId, $entry['xml'], $userId > 0 ? $userId : null, $year);
                $out[] = [
                    'year' => $year,
                    'forma' => $entry['filing']->forma,
                    'status' => (string) ($r['status'] ?? 'created'),
                    'input_mismatches' => (int) ($r['input_mismatches'] ?? 0),
                    'losses' => $r['losses'] ?? null,
                    'notices' => array_values((array) ($r['notices'] ?? [])),
                ];
                if ((int) ($r['input_mismatches'] ?? 0) > 0) {
                    $warnings[] = sprintf('Přiznání DPPO %d: po převzetí se liší %d řádků ze vstupů, zkontrolujte ho v Daních.', $year, (int) $r['input_mismatches']);
                }
            } catch (TaxReturnException $e) {
                $out[] = ['year' => $year, 'forma' => $entry['filing']->forma, 'status' => 'failed', 'error' => $e->getMessage()];
                $warnings[] = sprintf('Přiznání DPPO %d se nepřevzalo: %s', $year, $e->getMessage());
            }
        }
        return [$out, $warnings];
    }

    /**
     * Souhrn protokolu převodu pro protokol dávky.
     *
     * @param array<string,mixed> $result {@see ImportProtocol::toArray()}
     * @return array<string,mixed>
     */
    public static function protocolSummary(array $result): array
    {
        [$errors, $warnings] = AbstractImportJobService::messageCounts($result);
        $byStep = array_column((array) ($result['steps'] ?? []), null, 'key');
        $firstError = null;
        foreach ((array) ($result['steps'] ?? []) as $step) {
            foreach ((array) ($step['messages'] ?? []) as $m) {
                if (($m['level'] ?? '') === 'error') {
                    $firstError ??= ['step' => $step['key'], 'code' => $m['code'], 'text' => $m['text']];
                }
            }
        }
        $criteria = ReconciliationCriteria::summarize((array) ($result['reconciliation'] ?? []));
        return [
            'protocol_status' => (string) ($result['status'] ?? ''),
            'errors' => $errors,
            'warnings' => $warnings,
            'first_error' => $firstError,
            'years' => array_values(array_map(static fn (array $r): int => (int) $r['year'], (array) ($result['reconciliation'] ?? []))),
            'criteria' => $criteria,
            'closing' => array_values(array_map(
                static fn (array $c): array => ['year' => (int) $c['year'], 'status' => (string) $c['status']],
                (array) ($result['closing'] ?? []),
            )),
            'journal' => (array) ($byStep[JournalImporter::STEP]['counts'] ?? []),
        ];
    }

    /**
     * Převod doběhl a jedinou chybou je nesouhlas rekonciliace (doklady × deník, předvaha).
     * Takový rozdíl bývá už ve zdroji a firma je převedená — další kroky dávky (převzetí
     * přiznání, obnova nastavení) proto běží.
     *
     * @param array<string,mixed> $result
     */
    public static function onlyReconciliationErrors(array $result): bool
    {
        $codes = [];
        foreach ((array) ($result['steps'] ?? []) as $s) {
            foreach ((array) ($s['messages'] ?? []) as $m) {
                if (($m['level'] ?? '') === 'error') {
                    $codes[] = (string) $m['code'];
                }
            }
        }
        return $codes !== [] && array_diff(array_unique($codes), ['reconciliation_failed']) === [] && ($result['failure'] ?? null) !== 'cancelled';
    }

    /** Účtuje firma DPH? Obraty na účtu 343 v kterémkoli roce zálohy (bez počátečních a konečných stavů). */
    private static function journalUsesVat(Ms3Backup $backup): bool
    {
        foreach ($backup->rowsAcrossYears('UcDenik', ['Zdroj', 'UcMD', 'UcD']) as $r) {
            $source = trim((string) ($r['Zdroj'] ?? ''));
            if ($source !== 'XP' && $source !== 'XZ'
                && (str_starts_with(trim((string) ($r['UcMD'] ?? '')), '343') || str_starts_with(trim((string) ($r['UcD'] ?? '')), '343'))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $summary
     * @return array{status:string,target_supplier_id:?int,company_action:?string,from_year:?int,run_id:?int,summary:array<string,mixed>,error:?string}
     */
    private static function result(string $status, ?int $targetId, ?string $action, ?int $fromYear, ?int $runId, array $summary, ?string $error): array
    {
        return [
            'status' => $status,
            'target_supplier_id' => $targetId,
            'company_action' => $action,
            'from_year' => $fromYear,
            'run_id' => $runId,
            'summary' => $summary,
            'error' => $error,
        ];
    }

    /**
     * Z více záloh téže firmy platí nejnovější (datum zálohy z Money, bez něj čas souboru);
     * starší se nepřevádějí — převedly by se tytéž doklady znovu jen s horší aktuálností.
     *
     * @param list<array{ico:string,backup_at?:string,mtime?:int}> $backups
     * @return array{keep:list<int>,superseded:list<int>} indexy vstupu
     */
    public static function latestPerIco(array $backups): array
    {
        $best = [];
        $keys = [];
        foreach ($backups as $index => $b) {
            $ico = CodebookImporter::ico((string) $b['ico']);
            $key = $ico !== '' ? $ico : '#' . $index;
            $stamp = [self::backupStamp((string) ($b['backup_at'] ?? '')), (int) ($b['mtime'] ?? 0)];
            if (!isset($best[$key]) || $stamp > $keys[$key]) {
                $best[$key] = $index;
                $keys[$key] = $stamp;
            }
        }
        $keep = array_values($best);
        sort($keep);
        return ['keep' => $keep, 'superseded' => array_values(array_diff(array_keys($backups), $keep))];
    }

    /** Datum zálohy z `AgendaInfo.ini` („10.01.2026 08:15") jako řaditelný text. */
    public static function backupStamp(string $backupAt): string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', trim($backupAt), $m) === 1) {
            return sprintf('%04d-%02d-%02d %02d:%02d', (int) $m[3], (int) $m[2], (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
        }
        return '';
    }

    public static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
