<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Database;

use MyInvoice\Infrastructure\Database\SchemaCache;
use PHPUnit\Framework\TestCase;

/**
 * Cache drží odpovědi na „existuje tenhle sloupec?" mezi requesty. Chyba tady je
 * zákeřná: aplikace by tvrdila, že sloupec zavedený migrací neexistuje, a tiše
 * běžela bez příslušné funkce. Proto se testuje hlavně to, KDY se cache NEPOUŽIJE.
 */
final class SchemaCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/schema-cache-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/storage/cache/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir . '/storage/cache');
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    private function path(string $db = 'testdb'): string
    {
        $p = SchemaCache::pathFor($this->dir, $db);
        self::assertIsString($p);

        return $p;
    }

    public function testRoundTripPersistsBothPositiveAndNegativeAnswers(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->put('table:license', true);
        // Negativní odpověď se MUSÍ cachovat taky — feature-detekce se ptá hlavně
        // na věci, které ještě neexistují, a právě ty dotazy jsou ty drahé.
        $c->put('table:jeste_nemigrovana', false);
        $c->flush();

        $fresh = new SchemaCache($this->path(), 'testdb');
        self::assertTrue($fresh->get('table:license'));
        self::assertFalse($fresh->get('table:jeste_nemigrovana'));
        self::assertNull($fresh->get('table:nikdy_nedotazovana'), 'Neznámý klíč musí vrátit null, ne false.');
    }

    public function testCacheFromAnotherDatabaseIsIgnored(): void
    {
        // Kdyby se klíče míchaly mezi databázemi, testovací běh by otrávil ostrou
        // instalaci (nebo naopak) a projevilo by se to až chybějící funkcí.
        $c = new SchemaCache($this->path('db_a'), 'db_a');
        $c->put('table:license', true);
        $c->flush();

        $other = new SchemaCache($this->path('db_a'), 'db_b');
        self::assertNull($other->get('table:license'));
    }

    public function testExpiredCacheIsIgnored(): void
    {
        $c = new SchemaCache($this->path(), 'testdb', 300);
        $c->put('table:license', true);
        $c->flush();

        // Posuň zápis do minulosti za TTL — pojistka pro schéma změněné mimo migrace.
        $raw = json_decode((string) file_get_contents($this->path()), true);
        $raw['written_at'] = time() - 301;
        file_put_contents($this->path(), json_encode($raw));

        $expired = new SchemaCache($this->path(), 'testdb', 300);
        self::assertNull($expired->get('table:license'));

        $stillValid = new SchemaCache($this->path(), 'testdb', 3600);
        self::assertTrue($stillValid->get('table:license'), 'Delší TTL musí tentýž soubor ještě uznat.');
    }

    public function testInvalidateRemovesTheFile(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->put('table:license', true);
        $c->flush();
        self::assertFileExists($this->path());

        self::assertTrue(SchemaCache::invalidate($this->path()));
        self::assertFileDoesNotExist($this->path());

        // Druhé volání nesmí spadnout ani lhát, že něco smazalo.
        self::assertFalse(SchemaCache::invalidate($this->path()));
        self::assertFalse(SchemaCache::invalidate(null));
    }

    public function testCorruptFileDegradesToMissInsteadOfThrowing(): void
    {
        $path = $this->path();
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, '{ tohle není validní JSON');

        $c = new SchemaCache($path, 'testdb');
        self::assertNull($c->get('table:license'), 'Poškozený soubor = cache miss, ne výjimka.');
    }

    /**
     * Každý endpoint se ptá na jinou podmnožinu schématu. Kdyby zápis soubor
     * přepisoval, dvojice requestů s různými potřebami by si cache donekonečna
     * mazala a introspekční dotazy by se vrátily.
     */
    public function testFlushMergesInsteadOfOverwriting(): void
    {
        $a = new SchemaCache($this->path(), 'testdb');
        $a->put('table:license', true);
        $a->flush();

        $b = new SchemaCache($this->path(), 'testdb');
        $b->put('table:supplier', true);
        $b->flush();

        $reader = new SchemaCache($this->path(), 'testdb');
        self::assertTrue($reader->get('table:license'), 'Klíč z prvního zápisu nesmí zmizet.');
        self::assertTrue($reader->get('table:supplier'));
    }

    /**
     * Slučování se NESMÍ týkat prošlého souboru — jinak by se staré klíče při
     * každém zápisu omladily na aktuální written_at a TTL by přestalo fungovat
     * jako pojistka proti schématu změněnému mimo migrace.
     */
    public function testMergeDoesNotResurrectExpiredEntries(): void
    {
        $old = new SchemaCache($this->path(), 'testdb', 300);
        $old->put('table:stary_klic', true);
        $old->flush();

        $raw = json_decode((string) file_get_contents($this->path()), true);
        $raw['written_at'] = time() - 301;
        file_put_contents($this->path(), json_encode($raw));

        $fresh = new SchemaCache($this->path(), 'testdb', 300);
        $fresh->put('table:novy_klic', true);
        $fresh->flush();

        $reader = new SchemaCache($this->path(), 'testdb', 300);
        self::assertTrue($reader->get('table:novy_klic'));
        self::assertNull($reader->get('table:stary_klic'), 'Prošlý klíč se nesmí vrátit zpět do cache.');
    }

    public function testFlushWithoutChangesDoesNotCreateFile(): void
    {
        $c = new SchemaCache($this->path(), 'testdb');
        $c->flush();
        self::assertFileDoesNotExist($this->path());
    }

    public function testPathIsNullWhenThereIsNowhereToWrite(): void
    {
        self::assertNull(SchemaCache::pathFor(null, 'testdb'));
        self::assertNull(SchemaCache::pathFor($this->dir, ''));
    }

    public function testDatabaseNameCannotEscapeTheCacheDirectory(): void
    {
        $p = SchemaCache::pathFor($this->dir, '../../etc/passwd');
        self::assertIsString($p);
        self::assertStringNotContainsString('..', basename($p));
        self::assertSame($this->dir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', dirname($p));
    }
    public function testInvalidationRejectsAnOldWriterAndRefreshesExistingReaders(): void
    {
        $old = new SchemaCache($this->path(), 'testdb');
        $old->put('table:before', true);
        $old->flush();
        $reader = new SchemaCache($this->path(), 'testdb');
        self::assertTrue($reader->get('table:before'));
        $old->put('table:stale', true);
        SchemaCache::invalidate($this->path());
        $new = new SchemaCache($this->path(), 'testdb');
        $new->put('table:after', true);
        $new->flush();
        $old->flush();
        self::assertNull($reader->get('table:before'));
        self::assertNull($reader->get('table:stale'));
        self::assertTrue($reader->get('table:after'));
    }

    public function testSnapshotCannotBePublishedAcrossAnInvalidation(): void
    {
        $cache = new SchemaCache($this->path(), 'testdb');
        $generation = $cache->generation();
        SchemaCache::invalidate($this->path());
        $cache->putSnapshot(['tables' => ['old' => 'BASE TABLE']], $generation);
        $cache->flush();
        self::assertNull((new SchemaCache($this->path(), 'testdb'))->snapshot());
    }

    public function testSnapshotCorruptionIsIgnored(): void
    {
        $cache = new SchemaCache($this->path(), 'testdb');
        $snapshot = ['tables' => ['example' => 'BASE TABLE'], 'columns' => []];
        $cache->putSnapshot($snapshot, $cache->generation());
        $cache->flush();
        self::assertSame($snapshot, (new SchemaCache($this->path(), 'testdb'))->snapshot());
        $data = json_decode(file_get_contents($this->path() . '.snapshot.json'), true);
        $data['snapshot']['tables'] = [];
        file_put_contents($this->path() . '.snapshot.json', json_encode($data));
        self::assertNull((new SchemaCache($this->path(), 'testdb'))->snapshot());
        $data['snapshot'] = $snapshot;
        $data['entries'] = 42;
        file_put_contents($this->path() . '.snapshot.json', json_encode($data));
        self::assertNull((new SchemaCache($this->path(), 'testdb'))->snapshot());
    }

    public function testConnectionIdentitiesHaveDifferentCachePaths(): void
    {
        self::assertNotSame(
            SchemaCache::pathFor($this->dir, 'same_database', 'host_a:3306:user'),
            SchemaCache::pathFor($this->dir, 'same_database', 'host_b:3306:user'),
        );
    }

    public function testMissingPersistenceDoesNotPreventQueries(): void
    {
        $path = $this->path();
        mkdir(dirname($path), 0o775, true);
        mkdir($path . '.lock');
        $cache = new SchemaCache($path, 'testdb');
        self::assertNull($cache->generation());
        self::assertNull($cache->snapshot());
        $cache->put('table:example', true);
        $cache->flush();
        self::assertFileDoesNotExist($path);
        rmdir($path . '.lock');
    }

    public function testConcurrentWritersMergeWithoutLosingEntries(): void
    {
        $this->runConcurrentWriters(false);
    }

    public function testConcurrentWritersCannotResurrectAnInvalidatedGeneration(): void
    {
        $this->runConcurrentWriters(true);
    }

    private function runConcurrentWriters(bool $invalidate): void
    {
        $path = $this->path();
        $seed = new SchemaCache($path, 'testdb');
        $seed->put('table:seed', true);
        $seed->flush();
        $code = <<<'PHP'
require $argv[1];
require $argv[2];
$cache = new \MyInvoice\Infrastructure\Database\SchemaCache($argv[3], 'testdb');
$cache->get('table:seed');
file_put_contents($argv[4] . '.ready', '1');
$deadline = microtime(true) + 10;
while (!is_file($argv[4] . '.go')) {
    if (microtime(true) > $deadline) exit(2);
    usleep(1000);
}
$cache->put($argv[5], true);
$cache->flush();
PHP;
        $processes = [];
        $markers = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $marker = dirname($path) . '/writer-' . $i;
                $markers[] = $marker;
                $process = proc_open([
                    PHP_BINARY, '-r', $code,
                    (new \ReflectionClass(SchemaCache::class))->getFileName(),
                    (new \ReflectionClass(\MyInvoice\Infrastructure\Database\SchemaMetadataProvider::class))->getFileName(),
                    $path, $marker, 'table:writer_' . $i,
                ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 5;
            do {
                $ready = is_file($markers[0] . '.ready') && is_file($markers[1] . '.ready');
                if (!$ready) {
                    usleep(1000);
                }
            } while (!$ready && microtime(true) < $deadline);
            self::assertTrue($ready, 'Oba procesy musí přečíst původní generaci před pokračováním.');
            if ($invalidate) {
                SchemaCache::invalidate($path);
                $new = new SchemaCache($path, 'testdb');
                $new->put('table:new_generation', true);
                $new->flush();
            }
            foreach ($markers as $marker) {
                file_put_contents($marker . '.go', '1');
            }
            foreach ($processes as [$process, $pipes]) {
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
            }
            $processes = [];
            $reader = new SchemaCache($path, 'testdb');
            if ($invalidate) {
                self::assertTrue($reader->get('table:new_generation'));
                self::assertNull($reader->get('table:seed'));
                self::assertNull($reader->get('table:writer_0'));
                self::assertNull($reader->get('table:writer_1'));
            } else {
                self::assertTrue($reader->get('table:seed'));
                self::assertTrue($reader->get('table:writer_0'));
                self::assertTrue($reader->get('table:writer_1'));
            }
        } finally {
            foreach ($processes as [$process, $pipes]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    foreach ($pipes as $pipe) {
                        if (is_resource($pipe)) fclose($pipe);
                    }
                    proc_close($process);
                }
            }
        }
    }

}
