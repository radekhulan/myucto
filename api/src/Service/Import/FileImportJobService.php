<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\ImportJobRepository;

/**
 * Import nahraných souborů (Pohoda XML / ISDOC / ZIP) na pozadí.
 *
 * Druhý vchod do {@see InvoiceImportService::importBundle()}, ne druhý import — sdílený
 * je celý zápis včetně detekce směru dokladu, zakládání účetních období a závěrečného
 * dorovnání číselných řad. Tahle třída řeší jen to, co job přidává navíc: přečíst
 * odložené soubory, hlásit průběh, reagovat na zrušení a uklidit po sobě.
 *
 * Proč vůbec: dávka z jiného systému má běžně tisíce dokladů. Synchronní request na ni
 * nestačí a jeho utnutí uprostřed je horší než selhání — doklady zůstanou založené, ale
 * závěrečné kroky (číselné řady, přepočet statistik klientů) neproběhnou, takže seznam
 * klientů ukazuje stará čísla a další vystavená faktura dostane číslo, které v importu
 * už je.
 *
 * Soubory čekají na disku ({@see stagingDir()}), protože worker běží až po skončení
 * requestu, ve kterém se nahrály. Maže je běh sám — i ten neúspěšný.
 */
final class FileImportJobService
{
    /** Zápis průběhu do DB po N dokladech (ne po každém — dávka jich má tisíce). */
    private const PROGRESS_EVERY = 25;

    /** Dotaz na zrušení po N dokladech (každý je SELECT). */
    private const CANCEL_CHECK_EVERY = 50;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly InvoiceImportService $importer,
    ) {}

    /** Adresář, kam action odloží nahrané soubory, než je worker přečte. */
    public static function stagingDir(int $supplierId, string $token): string
    {
        return RuntimePaths::storage('import-jobs/' . $supplierId . '/' . $token);
    }

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) return;
        if (!$this->jobs->markRunning($jobId)) return; // race — jiný worker už běží

        $supplierId = (int) $job['supplier_id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $userId = (int) ($job['created_by'] ?? 0);
        $kind = (string) ($params['kind'] ?? 'auto');
        // Starší job (z doby před volbou stavu) `purchase_status` nemá — pro něj platí
        // `draft`, což je stav, ve kterém tehdy doklady skutečně vznikaly.
        $purchaseStatus = ((string) ($params['purchase_status'] ?? 'draft')) === 'received' ? 'received' : 'draft';
        $dir = (string) ($params['staging_dir'] ?? '');

        try {
            $files = $this->readStaged($dir, $params['files'] ?? []);
            if ($files === []) {
                $this->jobs->markFailed($jobId, 'Nahrané soubory se nepodařilo načíst (job je nejspíš starší než úklid dočasných souborů).');
                return;
            }

            $this->jobs->appendLog($jobId, 'Načteno ' . count($files) . ' souborů, spouštím import.');
            $this->jobs->updateProgress($jobId, ['current_step' => 'Čtu doklady']);

            $ticks = 0;
            $cancelChecks = 0;
            $cancelled = false;

            $report = $this->importer->importBundle(
                $files,
                $supplierId,
                $userId,
                $kind,
                function (int $processed, int $total, array $counts) use ($jobId, &$ticks): void {
                    $ticks++;
                    // První a poslední hlášení posíláme vždy: první nese `total_items`,
                    // bez kterého UI neumí spočítat procenta, poslední srovnává čísla
                    // s reportem (jinak by u dávky nedělitelné krokem zůstalo o pár
                    // dokladů méně, než jich doopravdy proběhlo).
                    if ($ticks === 1 || $processed === $total || $ticks % self::PROGRESS_EVERY === 0) {
                        $this->jobs->updateProgress($jobId, [
                            'total_items'   => $total,
                            'processed'     => $processed,
                            'created_count' => $counts['created'] + $counts['duplicates'],
                            'skipped_count' => $counts['skipped'],
                            'failed_count'  => $counts['failed'],
                            'current_step'  => 'Zpracovávám doklady ' . $processed . ' / ' . $total,
                        ]);
                    }
                },
                function () use ($jobId, &$cancelChecks): bool {
                    $cancelChecks++;
                    if ($cancelChecks % self::CANCEL_CHECK_EVERY !== 1) return false;
                    return $this->jobs->isCancelRequested($jobId);
                },
                $purchaseStatus,
            );

            $cancelled = (bool) ($report['cancelled'] ?? false);
            $this->storeReport($jobId, $supplierId, $report);

            $s = $report['summary'];
            $this->jobs->updateProgress($jobId, [
                'created_count' => (int) $s['created'] + (int) $s['duplicates'],
                'skipped_count' => (int) $s['skipped'],
                'failed_count'  => (int) $s['failed'],
                'current_step'  => $cancelled ? 'Zrušeno uživatelem' : 'Hotovo',
            ]);
            $this->jobs->appendLog($jobId, sprintf(
                'Vytvořeno %d, duplicit %d, přeskočeno %d, chyb %d.',
                $s['created'], $s['duplicates'], $s['skipped'], $s['failed'],
            ));

            if ($cancelled) {
                $this->jobs->appendLog($jobId, 'Zrušeno uživatelem, nezpracováno zůstalo ' . (int) ($report['not_processed'] ?? 0) . ' dokladů. Doklady založené do té chvíle v systému zůstávají.');
                $this->jobs->markCancelled($jobId);
            } elseif ((int) $s['failed'] > 0) {
                // Chyby jsou u importu z cizího systému běžné (chybějící sazba, cizí
                // doklad v dávce) a dávku jako celek neshazují — proto „hotovo s
                // upozorněními", ne „selhalo". Report drží, který doklad a proč.
                $this->jobs->markCompletedWithWarnings($jobId);
            } else {
                $this->jobs->markCompleted($jobId);
            }
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
        } finally {
            $this->cleanup($dir, $supplierId);
        }
    }

    /**
     * Načtení odložených souborů zpátky do paměti.
     *
     * Cesta se skládá ze staging adresáře a holého jména souboru — jméno z uploadu se
     * do ní nedostane (basename + uložený index), takže vlastní název souboru nemůže
     * ukázat mimo adresář jobu.
     *
     * @param mixed $entries
     * @return list<array{name:string, content:string}>
     */
    private function readStaged(string $dir, mixed $entries): array
    {
        if ($dir === '' || !is_dir($dir) || !is_array($entries)) return [];

        $out = [];
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) continue;
            $path = $dir . '/' . $i . '.bin';
            if (!is_file($path)) continue;
            $content = @file_get_contents($path);
            if ($content === false) continue;
            $out[] = [
                'name'    => (string) ($entry['name'] ?? ('soubor-' . $i)),
                'content' => $content,
            ];
        }
        return $out;
    }

    /**
     * Report je pro UI to hlavní, ale u dávky s tisíci doklady má megabajty — do
     * `log_text` nepatří a přes polling stavu by chodil znovu a znovu. Leží proto
     * vedle jobu jako soubor a stahuje se jednou, až běh skončí.
     *
     * @param array<string,mixed> $report
     */
    private function storeReport(int $jobId, int $supplierId, array $report): void
    {
        $dir = RuntimePaths::storage('import-jobs/' . $supplierId . '/reports');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $this->jobs->appendLog($jobId, 'Report se nepodařilo uložit (úložiště není zapisovatelné) — souhrn zůstává jen v čítačích jobu.');
            return;
        }
        $path = $dir . '/' . $jobId . '.json';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($path, $json) === false) {
            $this->jobs->appendLog($jobId, 'Report se nepodařilo uložit — souhrn zůstává jen v čítačích jobu.');
            return;
        }
        $this->jobs->setResult($jobId, 'import-jobs/' . $supplierId . '/reports/' . $jobId . '.json',
            'import-report-' . $jobId . '.json', (int) filesize($path), 'application/json');
    }

    /** Nahrané soubory po sobě uklidí i neúspěšný běh — jinak by v úložišti zůstaly navždy. */
    private function cleanup(string $dir, int $supplierId): void
    {
        if ($dir === '' || !is_dir($dir)) return;
        // Pojistka proti smazání něčeho mimo staging: cesta musí ležet pod adresářem
        // jobů daného tenanta. Casing na Windows nesedí spolehlivě, proto strtolower.
        $base = RuntimePaths::storage('import-jobs/' . $supplierId);
        $realDir = realpath($dir);
        $realBase = realpath($base);
        if ($realDir === false || $realBase === false) return;
        if (!str_starts_with(strtolower($realDir), strtolower($realBase) . DIRECTORY_SEPARATOR)) return;

        foreach ((array) glob($realDir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
        @rmdir($realDir);
    }
}
