<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ScanBatchRepository;
use MyInvoice\Service\Document\DocumentStorage;
use PDO;

/**
 * Úklid nahraných souborů dávek skenů (`storage/documents/sup-N/_jobs/scan-M`).
 *
 * Dávka si nahrané soubory drží, dokud je všechny neuloží do Dokumentů — jinak
 * by „Spustit znovu" nemělo odkud brát. Tady se uklidí to, co už nikdo
 * nepotřebuje:
 *   - dávka neexistuje (smazaná dávka, přerušené založení) — hned,
 *   - dávka se nahrává nebo běží, ale přes {@see ABANDONED_HOURS} nedala známku
 *     života (opuštěné nahrávání, mrtvý worker),
 *   - dávka skončila (hotová, neúspěšná, zrušená) před víc než {@see RETENTION_DAYS}.
 */
final class ScanStagingCleaner
{
    public const RETENTION_DAYS = 7;
    public const ABANDONED_HOURS = 48;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param int|null $supplierId jen jedna firma (při zakládání dávky); null = všechny (cron)
     * @return int počet uklizených dávek
     */
    public function purge(?int $supplierId = null): int
    {
        $n = 0;
        foreach ($this->stagingDirs($supplierId) as [$sid, $jobId, $dir]) {
            if ($this->isDisposable($sid, $jobId)) {
                (new UploadedScanSource($dir))->cleanup();
                $n++;
            }
        }
        return $n;
    }

    /** @return list<array{0:int,1:int,2:string}> firma, dávka, adresář */
    private function stagingDirs(?int $supplierId): array
    {
        $sids = [];
        if ($supplierId !== null) {
            $sids[] = $supplierId;
        } else {
            $stmt = $this->db->pdo()->query('SELECT id FROM supplier');
            $sids = $stmt !== false ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
            // Adresáře firem, které už neexistují, patří taky ven.
            foreach (glob(dirname(DocumentStorage::baseDir(0)) . '/sup-*', GLOB_ONLYDIR) ?: [] as $d) {
                if (preg_match('/^sup-(\d+)$/', basename($d), $m) === 1) {
                    $sids[] = (int) $m[1];
                }
            }
        }
        $out = [];
        foreach (array_unique($sids) as $sid) {
            foreach (glob(DocumentStorage::baseDir($sid) . '/_jobs/scan-*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (preg_match('/^scan-(\d+)$/', basename($dir), $m) === 1) {
                    $out[] = [$sid, (int) $m[1], $dir];
                }
            }
        }
        return $out;
    }

    private function isDisposable(int $supplierId, int $jobId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status,
                    updated_at < NOW() - INTERVAL ? HOUR AS idle,
                    COALESCE(finished_at, updated_at) < NOW() - INTERVAL ? DAY AS expired
               FROM import_jobs
              WHERE id = ? AND supplier_id = ? AND source = ?'
        );
        $stmt->execute([self::ABANDONED_HOURS, self::RETENTION_DAYS, $jobId, $supplierId, ScanBatchRepository::SOURCE]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return true;
        }
        return in_array($row['status'], ['queued', 'running'], true) ? (bool) $row['idle'] : (bool) $row['expired'];
    }
}
