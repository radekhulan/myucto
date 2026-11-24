<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;

/**
 * Opravný převod vazeb z příkazové řádky (`api/bin/pohoda-relink.php`): nový export
 * z POHODY do už vedeného účetnictví firmy ({@see PohodaContext::$relinkOnly}). Zápisy
 * zadané v MyÚčtu zůstanou, nové doklady se nepřidají, doplní se vazby (daňové doklady
 * k zálohám a platbám, úhrady, zápočty). Mzdy ani sklad se nepřevádějí.
 *
 * Export se čte stejně jako v průvodci: ZIP rozbalí {@see PohodaExport::extractArchive()},
 * agendy firmy najde {@see PohodaExport::agendaDirs()} a každou otevře {@see PohodaExport::open()}.
 * Agendy jdou vzestupně, každá s vlastním záznamem běhu v historii převodů.
 */
final class PohodaRelinkRunner
{
    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_INVALID = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImporter $importer,
        private readonly PohodaImportRepository $runs,
    ) {}

    /**
     * @param string $source ZIP exportu, složka agendy (`IČO_rok`) nebo složka s agendami
     * @param (callable(string):void) $print výstup protokolu po řádcích
     * @return int kód návratu ({@see EXIT_OK}, {@see EXIT_FAILED}, {@see EXIT_INVALID})
     */
    public function run(int $supplierId, string $source, bool $commit, ?int $year, ?int $userId, callable $print): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false) {
            $print("Firma {$supplierId} neexistuje.");
            return self::EXIT_INVALID;
        }
        $ico = PartnerImporter::ico((string) $ic);
        if ($ico === '') {
            $print("Firma {$supplierId} nemá IČO, agendu exportu k ní nejde přiřadit.");
            return self::EXIT_INVALID;
        }

        $tmp = null;
        $locked = false;
        try {
            if (is_file($source)) {
                $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pohoda_relink_' . bin2hex(random_bytes(6));
                PohodaExport::extractArchive($source, $tmp);
                $root = $tmp;
            } elseif (is_dir($source)) {
                $root = $source;
            } else {
                $print("Export {$source} neexistuje.");
                return self::EXIT_INVALID;
            }
            $dirs = preg_match('/^\d{6,8}_(\d{4})$/', basename(rtrim($root, '/\\')), $m) === 1
                ? [(int) $m[1] => rtrim($root, '/\\')]
                : PohodaExport::agendaDirs($root, $ico);
            if ($year !== null) {
                $dirs = array_intersect_key($dirs, [$year => true]);
            }
            if ($dirs === []) {
                $print("Export neobsahuje agendu IČO {$ico}" . ($year !== null ? " za rok {$year}" : '') . ' (složka IČO_rok).');
                return self::EXIT_INVALID;
            }
            if (!$this->runs->acquireLock($supplierId)) {
                $print('Převod této firmy právě běží, opravný převod spusťte po jeho dokončení.');
                return self::EXIT_FAILED;
            }
            $locked = true;

            $print(($commit ? 'Opravný převod vazeb' : '[NANEČISTO] Opravný převod vazeb') . " z POHODY, firma {$supplierId} (IČO {$ico}), agendy " . implode(', ', array_keys($dirs)) . '.');
            $exit = self::EXIT_OK;
            foreach ($dirs as $dir) {
                $export = PohodaExport::open($dir);
                $mode = $commit ? 'import' : 'dry_run';
                $runId = $this->runs->startRun($supplierId, null, $mode, [
                    'ico' => $export->ico, 'year' => $export->year, 'program' => $export->info['program'],
                ], $userId);
                try {
                    $protocol = $this->importer->run($supplierId, $userId ?? 0, $export, !$commit, $runId, relinkOnly: true);
                } catch (\Throwable $e) {
                    $this->runs->finishRun($runId, $supplierId, 'failed', ['mode' => $mode, 'status' => 'failed', 'failure' => 'unexpected', 'error' => $e->getMessage(), 'steps' => [], 'kind' => 'accounting']);
                    throw $e;
                }
                $this->runs->finishRun($runId, $supplierId, $protocol->status(), $protocol->toArray() + ['kind' => 'accounting']);
                self::printProtocol($protocol, $export, $runId, $print);
                if ($protocol->hasErrors()) {
                    $exit = self::EXIT_FAILED;
                    break;
                }
            }
            if ($exit === self::EXIT_OK && !$commit) {
                $print('Nic nezměněno. Zápis provede spuštění s --commit.');
            }
            return $exit;
        } catch (PohodaException $e) {
            $print('Chyba: ' . $e->getMessage());
            return self::EXIT_FAILED;
        } finally {
            if ($locked) {
                $this->runs->releaseLock($supplierId);
            }
            if ($tmp !== null) {
                self::removeDir($tmp);
            }
        }
    }

    /** @param (callable(string):void) $print */
    private static function printProtocol(ImportProtocol $protocol, PohodaExport $export, int $runId, callable $print): void
    {
        $data = $protocol->toArray();
        $print('');
        $print("Agenda {$export->ico}_{$export->year} (běh #{$runId}): " . $data['status'] . ($data['failure'] !== null ? " - přerušeno v kroku {$data['failure']}" : ''));
        foreach ($data['steps'] as $step) {
            $counts = [];
            foreach ($step['counts'] as $name => $value) {
                $counts[] = "{$name}={$value}";
            }
            $print(sprintf('  %-28s %-8s %s', $step['key'], $step['status'], implode(', ', $counts)));
            foreach ($step['messages'] as $m) {
                $print(sprintf('    [%s] %s', $m['level'], $m['text']));
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
