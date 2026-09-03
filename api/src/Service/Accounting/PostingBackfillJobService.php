<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Accounting\Activation\DocumentBackfill;

/**
 * Doúčtování nezaúčtovaných dokladů na pozadí.
 *
 * PROČ TO EXISTUJE
 * ------------------------------------------------------------------------------
 * Schopnost projít existující doklady a zaúčtovat je ({@see DocumentBackfill}) tu byla
 * od začátku, ale žila VÝHRADNĚ uvnitř průvodce aktivací účetnictví. Jakmile byla
 * aktivace hotová, nešla zavolat odnikud — a přitom je to přesně operace, kterou
 * uživatel potřebuje po každém importu historie.
 *
 * Co zbývalo: hromadné zaúčtování z výběru v seznamu se stropem 500 dokladů na dávku.
 * Na migraci s tisíci doklady to znamená osmkrát ručně naklikat výběr.
 *
 * A automatika účtování to nespraví ani omylem: `maybeAutoPost()` je HÁČEK NA VZNIK
 * dokladu (vystavení, přechod přijaté faktury do stavu „přijatá", opakovaná fakturace),
 * ne zametač existujících. Doklad, který už v systému leží — typicky naimportovaný —
 * jí neprojde nikdy, ať je zapnutá jakkoli.
 *
 * Účtovací logika se tu NEOPAKUJE: běh je tenká obálka nad `DocumentBackfill`, tedy
 * nad týmž kódem, který používá průvodce. Přidává jen to, co dělá z operace úlohu —
 * průběh, zrušení, protokol a report.
 *
 * Strop 500 dokladů tím padá: každý doklad se účtuje samostatně (vlastní transakce
 * v {@see PostingService}), takže dávka může být libovolně velká a jeden vadný doklad
 * ji nezastaví — skončí v reportu jako `skipped`/`failed` s důvodem.
 */
final class PostingBackfillJobService
{
    /** Zápis průběhu do DB po N dokladech (ne po každém — dávka jich má tisíce). */
    private const PROGRESS_EVERY = 25;

    /** Dotaz na zrušení po N dokladech (každý je SELECT). */
    private const CANCEL_CHECK_EVERY = 25;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly DocumentBackfill $backfill,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) return;
        if (!$this->jobs->markRunning($jobId)) return; // race — jiný worker už běží

        $supplierId = (int) $job['supplier_id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $from = isset($params['from']) && $params['from'] !== null && $params['from'] !== ''
            ? (string) $params['from']
            : null;
        $year = isset($params['year']) && $params['year'] !== null ? (int) $params['year'] : null;
        $dryRun = !empty($params['dry_run']);

        try {
            $this->jobs->updateProgress($jobId, [
                'current_step' => $dryRun ? 'Kontrola nanečisto' : 'Hledám nezaúčtované doklady',
            ]);

            $ticks = 0;
            $cancelChecks = 0;
            $logBuffer = '';

            $result = $this->backfill->run(
                $supplierId,
                $from,
                $year,
                $dryRun,
                false,
                // Protokol se sbírá po řádcích, ale do DB jde po blocích — u tisíců
                // dokladů by jinak vznikl jeden UPDATE na každý řádek protokolu.
                function (string $line) use ($jobId, &$logBuffer): void {
                    $logBuffer .= $line;
                    if (strlen($logBuffer) >= 4096) {
                        $this->jobs->appendLog($jobId, rtrim($logBuffer, "\n"));
                        $logBuffer = '';
                    }
                },
                function () use ($jobId, &$cancelChecks): bool {
                    $cancelChecks++;
                    if ($cancelChecks % self::CANCEL_CHECK_EVERY !== 1) return false;
                    return $this->jobs->isCancelRequested($jobId);
                },
                false,
                function (int $processed, int $total, array $stats) use ($jobId, &$ticks): void {
                    $ticks++;
                    // První hlášení nese `total_items`, bez kterého UI neumí spočítat
                    // procenta; poslední srovnává čísla s reportem.
                    if ($ticks === 1 || $processed === $total || $ticks % self::PROGRESS_EVERY === 0) {
                        $posted = $stats['invoice']['posted'] + $stats['purchase_invoice']['posted']
                            + $stats['invoice']['updated'] + $stats['purchase_invoice']['updated'];
                        $skipped = $stats['invoice']['skipped'] + $stats['purchase_invoice']['skipped'];
                        $failed = $stats['invoice']['failed'] + $stats['purchase_invoice']['failed'];
                        $this->jobs->updateProgress($jobId, [
                            'total_items'   => $total,
                            'processed'     => $processed,
                            'created_count' => $posted,
                            'skipped_count' => $skipped,
                            'failed_count'  => $failed,
                            'current_step'  => 'Účtuji doklady ' . $processed . ' / ' . $total,
                        ]);
                    }
                },
            );

            if ($logBuffer !== '') {
                $this->jobs->appendLog($jobId, rtrim($logBuffer, "\n"));
            }

            $posted  = (int) $result['invoice']['posted'] + (int) $result['purchase_invoice']['posted'];
            $updated = (int) $result['invoice']['updated'] + (int) $result['purchase_invoice']['updated'];
            $skipped = (int) $result['invoice']['skipped'] + (int) $result['purchase_invoice']['skipped'];
            $failed  = (int) $result['invoice']['failed'] + (int) $result['purchase_invoice']['failed'];
            $cancelled = !empty($result['cancelled']);

            $this->storeReport($jobId, $supplierId, $result);
            $this->jobs->updateProgress($jobId, [
                'created_count' => $posted + $updated,
                'skipped_count' => $skipped,
                'failed_count'  => $failed,
                'current_step'  => $cancelled ? 'Zastaveno uživatelem' : ($dryRun ? 'Kontrola hotova' : 'Hotovo'),
            ]);

            // Nevyrovnaný deník je nález, který nesmí zapadnout mezi čítači — podvojnost
            // je invariant, ne statistika.
            if (empty($result['balance']['balanced'])) {
                $this->jobs->appendLog($jobId, 'POZOR: deník firmy není vyrovnaný — zkontrolujte zápisy v Účetnictví → Deník.');
            }

            if ($cancelled) {
                $this->jobs->markCancelled($jobId);
            } elseif ($failed > 0 || $skipped > 0) {
                // Přeskočený doklad (uzavřené období, doklad nepostovatelný) ani chyba
                // jednoho dokladu neznamenají selhání dávky — zbytek je zaúčtovaný.
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /**
     * Report leží vedle jobu jako soubor: seznam problémových dokladů má u velké dávky
     * megabajty a přes polling stavu by chodil znovu a znovu.
     *
     * @param array<string,mixed> $result
     */
    private function storeReport(int $jobId, int $supplierId, array $result): void
    {
        $dir = RuntimePaths::storage('posting-backfill/' . $supplierId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->jobs->appendLog($jobId, 'Report se nepodařilo uložit (úložiště není zapisovatelné) — souhrn zůstává v čítačích úlohy.');
            return;
        }
        $path = $dir . '/' . $jobId . '.json';
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json) === false) {
            $this->jobs->appendLog($jobId, 'Report se nepodařilo uložit — souhrn zůstává v čítačích úlohy.');
            return;
        }
        $this->jobs->setResult(
            $jobId,
            'posting-backfill/' . $supplierId . '/' . $jobId . '.json',
            'zauctovani-' . $jobId . '.json',
            (int) filesize($path),
            'application/json',
        );
    }
}
