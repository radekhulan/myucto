<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

use DateTimeImmutable;
use FilesystemIterator;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Měření spotřeby místa instance (H-10).
 *
 * ── Co se měří a proč právě to ────────────────────────────────────────────
 * Hosting počítá do kvóty **soubory instance BEZ adresáře záloh aplikace plus
 * databázi**. Musíme počítat identicky — kdybychom počítali jinak, hlásíme si
 * navzájem dvě různá čísla a nikdo nepozná, které platí.
 *
 * ⚠️ Zálohy se do kvóty NEZAPOČÍTÁVAJÍ. Instalace, která se zamkne vlastními
 * zálohami, je nejtrapnější možná varianta selhání: čím déle běží, tím dřív
 * se zastaví, a uklidit to zvenčí nejde. Adresáře záloh se proto z procházení
 * PRUNUJÍ (nesestupuje se do nich vůbec) a jejich velikost se drží zvlášť,
 * jen jako doklad.
 *
 * ── Cena ──────────────────────────────────────────────────────────────────
 * Úloha běží z cronu, takže musí být levná:
 *
 *  - **databáze přes katalog**, ne `COUNT(*)` po tabulkách. Jeden dotaz do
 *    `information_schema.tables` vrátí `data_length + index_length` celého
 *    schématu; `COUNT(*)` by na velké instalaci znamenal full scan každé
 *    tabulky, tedy minuty I/O za číslo, které katalog zná zadarmo.
 *  - **soubory se při requestu NIKDY neprocházejí.** Web i telemetrie čtou
 *    hotové číslo z `instance_storage_usage` ({@see latest()}); strom se
 *    prochází jen v cronu ({@see measure()}), a i tam ho brzdí
 *    {@see measureIfStale()} minimálním intervalem, aby opakovaně spuštěná
 *    úloha nezaměstnala disk.
 *  - Procházení má strop na počet položek i na čas. Když do něj narazí, je
 *    výsledek DOLNÍ ODHAD a řádek nese `truncated = 1`. Podměřená spotřeba
 *    instanci nezamkne — to je bezpečný směr; přeměřená by ji zamkla neprávem.
 *
 * ── ⚠️ null vs. nula ──────────────────────────────────────────────────────
 * Když se nepovede změřit (nedostupná DB, chybějící tabulka, nečitelný
 * adresář), vrací se {@see StorageUsageSnapshot::unmeasured()} — samé `null`.
 * NIKDY nula. Nula je tvrzení „instance je prázdná", null je „nevím".
 */
final class StorageUsageMeter
{
    public const TABLE = 'instance_storage_usage';

    /** Jak často nejvýš se smí opravdu měřit (`storage_quota.min_measure_interval_sec`). */
    public const DEFAULT_MIN_INTERVAL_SEC = 300;

    /** Strop na počet položek jednoho průchodu — pojistka proti runaway stromu. */
    public const MAX_ENTRIES = 2_000_000;

    /** Strop na dobu jednoho průchodu v sekundách. */
    public const MAX_SECONDS = 240;

    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
    ) {}

    /**
     * Poslední uložené měření. Tohle je jediná cesta, kterou smí číst request —
     * jeden indexovaný řádek, žádný souborový systém.
     */
    public function latest(): StorageUsageSnapshot
    {
        try {
            if (!$this->db->hasTable(self::TABLE)) {
                return StorageUsageSnapshot::unmeasured();
            }
            $stmt = $this->db->pdo()->query(
                'SELECT measured_at, database_bytes, files_bytes, usage_bytes, backup_bytes,
                        file_count, duration_ms, truncated, breakdown
                   FROM ' . self::TABLE . ' WHERE id = 1'
            );
            if ($stmt === false) {
                return StorageUsageSnapshot::unmeasured();
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return StorageUsageSnapshot::fromRow(is_array($row) ? $row : null);
        } catch (Throwable) {
            // Nedostupná databáze není „nula bajtů". Nevíme nic.
            return StorageUsageSnapshot::unmeasured();
        }
    }

    /**
     * Změří jen tehdy, když je poslední měření starší než minimální interval.
     * Vrací dvojici [snapshot, doopravdy se měřilo?].
     *
     * @return array{0:StorageUsageSnapshot,1:bool}
     */
    public function measureIfStale(bool $force = false): array
    {
        $latest = $this->latest();
        if (!$force) {
            $age = $latest->ageSec();
            if ($age !== null && $age < $this->minIntervalSec()) {
                return [$latest, false];
            }
        }

        return [$this->measure(), true];
    }

    /**
     * Plné měření: katalog databáze + průchod datovým kořenem bez záloh.
     * Výsledek se rovnou uloží, aby ho request našel hotový.
     */
    public function measure(): StorageUsageSnapshot
    {
        $startedAt = microtime(true);

        $databaseBytes = $this->databaseBytes();
        $files         = $this->measureFiles();
        $backupBytes   = $this->measureBackups();

        // Nezměřitelná část se NESČÍTÁ jako nula — pak by celek tvrdil něco,
        // co neplatí. Buď víme obojí, nebo neříkáme nic.
        $usageBytes = ($databaseBytes === null || $files['bytes'] === null)
            ? null
            : $databaseBytes + $files['bytes'];

        $snapshot = new StorageUsageSnapshot(
            measuredAt:    $usageBytes === null ? null : new DateTimeImmutable('now'),
            databaseBytes: $databaseBytes,
            filesBytes:    $files['bytes'],
            usageBytes:    $usageBytes,
            backupBytes:   $backupBytes,
            fileCount:     $files['count'],
            durationMs:    (int) round((microtime(true) - $startedAt) * 1000),
            truncated:     $files['truncated'],
            breakdown:     $files['breakdown'],
        );

        $this->store($snapshot);

        return $snapshot;
    }

    /**
     * Velikost databáze z katalogu. `data_length + index_length` je přesně to,
     * co hosting vidí jako místo zabrané databází.
     *
     * `null` = katalog neodpověděl (nedostupná DB, chybějící práva).
     */
    public function databaseBytes(): ?int
    {
        try {
            $stmt = $this->db->pdo()->query(
                'SELECT SUM(data_length + index_length)
                   FROM information_schema.tables
                  WHERE table_schema = DATABASE()'
            );
            if ($stmt === false) {
                return null;
            }
            $value = $stmt->fetchColumn();

            // Prázdné schéma vrátí NULL ze SUM(); tam je nula správně —
            // katalog odpověděl, jen nemá co sčítat.
            if ($value === false) {
                return null;
            }

            return $value === null ? 0 : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Průchod datovým kořenem BEZ adresářů záloh.
     *
     * @return array{bytes:?int,count:?int,truncated:bool,breakdown:array<string,int>}
     */
    public function measureFiles(): array
    {
        return self::walk(RuntimePaths::base(), $this->excludedDirectories());
    }

    /**
     * Kolik zabírají zálohy. Do kvóty se to NEPOČÍTÁ — je to jen doklad, že
     * instanci nezamkly její vlastní zálohy.
     */
    public function measureBackups(): ?int
    {
        $total = null;
        foreach ($this->backupDirectories() as $dir) {
            if (!@is_dir($dir)) {
                continue;
            }
            $result = self::walk($dir, []);
            if ($result['bytes'] === null) {
                continue;
            }
            $total = ($total ?? 0) + $result['bytes'];
        }

        return $total;
    }

    /**
     * Adresáře, do kterých se při měření živých dat nesestupuje.
     *
     * Zálohy jsou tu povinně (definice hostingu), `storage_quota.exclude_dirs`
     * je ventil pro instalaci, která má vedle sebe ještě něco cizího.
     *
     * @return list<string> normalizované absolutní cesty
     */
    public function excludedDirectories(): array
    {
        $dirs = $this->backupDirectories();

        $extra = $this->config->get('storage_quota.exclude_dirs', []);
        if (is_array($extra)) {
            foreach ($extra as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $dirs[] = self::absolute(trim($value));
                }
            }
        }

        $out = [];
        foreach ($dirs as $dir) {
            $normalized = self::normalize($dir);
            if ($normalized !== '' && !in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }

    /**
     * Adresáře záloh aplikace. Bereme OBA konfigurační klíče — historicky
     * `storage.backup_dir`, dnes `cron.backup.output_dir`. Instalace, která
     * má nastavený jen jeden z nich, by při výčtu jediného klíče měla zálohy
     * započítané do kvóty. Výchozí konfigurace míří oběma do téhož adresáře,
     * proto se shodné cesty slučují: {@see measureBackups()} by je sečetl dvakrát.
     *
     * @return list<string>
     */
    public function backupDirectories(): array
    {
        $dirs = [];
        foreach (['cron.backup.output_dir', 'storage.backup_dir'] as $key) {
            $value = $this->config->get($key, '');
            if (is_string($value) && trim($value) !== '') {
                $dir = self::absolute(trim($value));
                $dirs[self::normalize($dir)] = $dir;
            }
        }
        $dirs = array_values($dirs);

        // Fallback JEN když není vyplněný ani jeden klíč: default layout je
        // `${data_dir}/storage/backup`. Bez tohohle by se prázdná konfigurace
        // tiše rovnala „zálohy počítej do kvóty". Když je konfigurace vyplněná,
        // fallback se nepřidává — jinak by měření sahalo do adresáře, který
        // s touhle instalací nemá nic společného.
        if ($dirs === []) {
            $dirs[] = RuntimePaths::storage('backup');
        }

        return $dirs;
    }

    /**
     * Rekurzivní součet velikostí souborů pod `$root` s vynecháním podstromů
     * z `$excluded`.
     *
     * Prořezává se filtrem NAD iterátorem, ne kontrolou u každé položky —
     * do vynechaného adresáře se tak vůbec nesestoupí. U adresáře záloh je
     * to rozdíl mezi „přeskoč tisíce souborů" a „nesahej tam".
     *
     * @param list<string> $excluded normalizované absolutní cesty
     * @return array{bytes:?int,count:?int,truncated:bool,breakdown:array<string,int>}
     */
    public static function walk(string $root, array $excluded): array
    {
        // ⚠️ Procházet se musí SKUTEČNÁ cesta, porovnávat normalizovaná. Kdyby
        // se iterovalo přes výstup normalize() (lowercase), na case-sensitive
        // souborovém systému by adresář neexistoval a měření by tiše vracelo
        // „neměřeno" na každé instalaci, jejíž cesta obsahuje velké písmeno.
        $base = @realpath($root);
        $base = is_string($base) && $base !== '' ? $base : trim($root);
        if ($base === '' || !@is_dir($base)) {
            return ['bytes' => null, 'count' => null, 'truncated' => false, 'breakdown' => []];
        }
        $compareRoot = self::normalize($base);

        // Vyloučené cesty se normalizují TADY, ne až u volajícího. Kdyby se to
        // nechalo na něm, stačí jedno volání se syrovou cestou (jiný casing,
        // zpětná lomítka, relativní zápis) a zálohy se tiše započítají do kvóty
        // — chyba, kterou nikdo nepozná, dokud se instalace nezamkne.
        $excluded = array_values(array_filter(
            array_map(static fn (string $path): string => self::normalize($path), $excluded),
            static fn (string $path): bool => $path !== '',
        ));

        $deadline  = microtime(true) + self::MAX_SECONDS;
        $bytes     = 0;
        $count     = 0;
        $seen      = 0;
        $truncated = false;
        $breakdown = [];

        try {
            $directories = new RecursiveDirectoryIterator(
                $base,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
                // Symlinky se ZÁMĚRNĚ nenásledují (FOLLOW_SYMLINKS není nastaveno):
                // jinak by smyčka symlinků měření zacyklila a jeden odkaz mimo
                // instanci by kvótu nafoukl o cizí data.
            );
            $filtered = new RecursiveCallbackFilterIterator(
                $directories,
                static function (SplFileInfo $current) use ($excluded): bool {
                    if (!$current->isDir()) {
                        return true;
                    }

                    return !self::isUnder($current->getPathname(), $excluded);
                }
            );
            $iterator = new RecursiveIteratorIterator(
                $filtered,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo) {
                    continue;
                }
                if (++$seen % 4096 === 0 && microtime(true) > $deadline) {
                    $truncated = true;
                    break;
                }
                if ($seen > self::MAX_ENTRIES) {
                    $truncated = true;
                    break;
                }
                if ($file->isLink() || !$file->isFile()) {
                    continue;
                }

                $size = @$file->getSize();
                if ($size === false) {
                    continue;
                }

                $bytes += (int) $size;
                $count++;

                $top = self::topSegment($compareRoot, $file->getPathname());
                if ($top !== null) {
                    $breakdown[$top] = ($breakdown[$top] ?? 0) + (int) $size;
                }
            }
        } catch (Throwable) {
            // Nečitelný kořen = neměřeno. Vracet částečnou sumu jako by byla
            // celá by znamenalo tiše podhodnocenou kvótu bez příznaku.
            return ['bytes' => null, 'count' => null, 'truncated' => false, 'breakdown' => []];
        }

        arsort($breakdown);

        return [
            'bytes'     => $bytes,
            'count'     => $count,
            'truncated' => $truncated,
            'breakdown' => array_slice($breakdown, 0, 20, true),
        ];
    }

    /** Uloží měření do singleton řádku. */
    public function store(StorageUsageSnapshot $snapshot): bool
    {
        try {
            if (!$this->db->hasTable(self::TABLE)) {
                return false;
            }
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (id, measured_at, database_bytes, files_bytes, usage_bytes, backup_bytes,
                     file_count, duration_ms, truncated, breakdown)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    measured_at    = VALUES(measured_at),
                    database_bytes = VALUES(database_bytes),
                    files_bytes    = VALUES(files_bytes),
                    usage_bytes    = VALUES(usage_bytes),
                    backup_bytes   = VALUES(backup_bytes),
                    file_count     = VALUES(file_count),
                    duration_ms    = VALUES(duration_ms),
                    truncated      = VALUES(truncated),
                    breakdown      = VALUES(breakdown)'
            );

            return $stmt->execute([
                $snapshot->measuredAt?->format('Y-m-d H:i:s'),
                $snapshot->databaseBytes,
                $snapshot->filesBytes,
                $snapshot->usageBytes,
                $snapshot->backupBytes,
                $snapshot->fileCount,
                $snapshot->durationMs,
                $snapshot->truncated ? 1 : 0,
                $snapshot->breakdown === [] ? null : json_encode($snapshot->breakdown, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            return false;
        }
    }

    private function minIntervalSec(): int
    {
        $value = filter_var(
            $this->config->get('storage_quota.min_measure_interval_sec', self::DEFAULT_MIN_INTERVAL_SEC),
            FILTER_VALIDATE_INT,
        );

        return $value === false ? self::DEFAULT_MIN_INTERVAL_SEC : max(0, $value);
    }

    /** Relativní cesta z konfigurace se kotví k datovému kořeni, ne k CWD cronu. */
    private static function absolute(string $path): string
    {
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1) {
            return $path;
        }

        return RuntimePaths::base() . '/' . ltrim($path, '/\\');
    }

    /**
     * Sjednocení cesty pro porovnání.
     *
     * ⚠️ Lowercase je tu POVINNÝ: na Windows vrací `realpath()` nekonzistentní
     * casing, takže `C:\Data\Storage\Backup` a `c:\data\storage\backup` by se
     * jinak neshodly a zálohy by se do kvóty započítaly.
     */
    private static function normalize(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            $path = $real;
        }

        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');

        return strtolower($path);
    }

    /**
     * Leží cesta v některém z vyloučených podstromů (nebo jím přímo je)?
     *
     * @param list<string> $roots normalizované cesty
     */
    private static function isUnder(string $path, array $roots): bool
    {
        if ($roots === []) {
            return false;
        }
        $needle = self::normalize($path);
        if ($needle === '') {
            return false;
        }

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }
            if ($needle === $root || str_starts_with($needle, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /** První segment cesty pod kořenem — kvůli rozpadu v diagnostice. */
    private static function topSegment(string $root, string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $prefix     = $root . '/';
        $lower      = strtolower($normalized);
        if (!str_starts_with($lower, $prefix)) {
            return null;
        }
        $rest = substr($normalized, strlen($prefix));
        $slash = strpos($rest, '/');

        return $slash === false ? $rest : substr($rest, 0, $slash);
    }
}
