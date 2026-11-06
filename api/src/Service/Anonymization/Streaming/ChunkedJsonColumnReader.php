<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use PDO;

final class ChunkedJsonColumnReader
{
    public const CHUNK_BYTES = 524_288;

    /** @var array<int,array<string,bool>> Pořadí markerů; true = reader již skončil. */
    private static array $open = [];
    /** @var array<int,true> SQL a evidence markerů se nesmí proložit úklidem z GC. */
    private static array $busy = [];

    public function __construct(private readonly PDO $pdo) {}

    /** @return iterable<string> */
    public function read(FinancialArchiveJsonColumn $source, int $supplierId, int $id): iterable
    {
        if ($supplierId < 1 || $id < 1) throw new \InvalidArgumentException('Archivní JSON vyžaduje přesný tenant a primární klíč.');
        if (!$this->pdo->inTransaction()) throw new \LogicException('Čtení archivního JSON vyžaduje vlastní transakci.');
        $table = $source->table();
        $column = $source->column();
        $metadataSql = "SELECT OCTET_LENGTH({$column}) AS bytes, SHA2({$column},256) AS sha256 FROM {$table} WHERE id=? AND supplier_id=? FOR UPDATE";
        $metadata = $this->metadata($metadataSql, $id, $supplierId);
        $savepoint = 'json_chunk_' . bin2hex(random_bytes(12));
        $this->openSavepoint($savepoint);
        try {
            for ($offset = 1; $offset <= $metadata['bytes']; $offset += self::CHUNK_BYTES) {
                $this->advanceSavepoint($savepoint);
                $statement = $this->pdo->prepare("SELECT SUBSTRING(CAST({$column} AS BINARY),?,?) FROM {$table} WHERE id=? AND supplier_id=?");
                $statement->execute([$offset, self::CHUNK_BYTES, $id, $supplierId]);
                $chunk = $statement->fetchColumn();
                if (!is_string($chunk) || $chunk === '' || strlen($chunk) > self::CHUNK_BYTES) {
                    throw new \RuntimeException('Archivní JSON se během čtení změnil.');
                }
                yield $chunk;
            }
            $this->advanceSavepoint($savepoint);
            $current = $this->metadata($metadataSql, $id, $supplierId);
            if ($current !== $metadata) throw new \RuntimeException('Archivní JSON se během čtení změnil.');
            $this->finishSavepoint($savepoint);
        } finally {
            $this->retireSavepoint($savepoint);
        }
    }

    /** @return array{bytes:int,sha256:string} */
    private function metadata(string $sql, int $id, int $supplierId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$id, $supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_numeric($row['bytes'] ?? null) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException('Archivní JSON nebyl nalezen.');
        }
        return ['bytes' => (int) $row['bytes'], 'sha256' => strtolower($row['sha256'])];
    }

    /**
     * Plain PDO používá i izolovaný streamovací worker, proto zde nelze vyžadovat
     * TransactionSavepointToken nad LoggingPdo. Společný poslední SQL marker dokazuje
     * nepřerušenou transakci všech readerů; uvolnění staršího by v MariaDB zrušilo novější.
     * Nespravuje cizí savepointy založené mezi chunky: obnova posledního markeru při otevření
     * dalšího readeru i při dalším chunku je zruší a ROLLBACK TO cizího savepointu označí
     * živý reader za nahrazený. Čtení proto nesmí proplétat cizí savepointy.
     */
    private function openSavepoint(string $savepoint): void
    {
        $this->critical(function (int $connection) use ($savepoint): void {
            if (!empty(self::$open[$connection])) {
                try {
                    $this->renewNewest($connection);
                } catch (\PDOException $failure) {
                    if ((int) ($failure->errorInfo[1] ?? 0) !== 1305) throw $failure;
                    // Nová transakce smí dostat nový reader; staré náhodné tokeny
                    // už v evidenci nejsou a žádná stará instance tím neožije.
                    unset(self::$open[$connection]);
                }
            }
            self::$open[$connection][$savepoint] = false;
            try {
                $this->pdo->exec('SAVEPOINT ' . $savepoint);
            } catch (\Throwable $failure) {
                unset(self::$open[$connection][$savepoint]);
                throw $failure;
            }
        });
    }

    private function advanceSavepoint(string $savepoint): void
    {
        $this->critical(fn (int $connection) => $this->assertSavepoint($connection, $savepoint));
    }

    /** Úspěch vyžaduje důkaz až po posledních metadatech, i když jiný reader ještě žije. */
    private function finishSavepoint(string $savepoint): void
    {
        $this->critical(function (int $connection) use ($savepoint): void {
            $this->assertSavepoint($connection, $savepoint);
            self::$open[$connection][$savepoint] = true;
        }, strictCleanup: true);
    }

    private function assertSavepoint(int $connection, string $savepoint): void
    {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Transakce archivního JSON byla ukončena.');
        if (!array_key_exists($savepoint, self::$open[$connection] ?? [])) {
            throw new \RuntimeException('Transakce archivního JSON byla nahrazena.');
        }
        try {
            $this->renewNewest($connection);
        } catch (\Throwable $failure) {
            unset(self::$open[$connection]);
            if (TransactionFailure::aborted($failure)) throw $failure;
            throw new \RuntimeException('Transakce archivního JSON byla nahrazena.', 0, $failure);
        }
    }

    private function renewNewest(int $connection): void
    {
        $newest = array_key_last(self::$open[$connection]);
        $this->pdo->exec('RELEASE SAVEPOINT ' . $newest);
        $this->pdo->exec('SAVEPOINT ' . $newest);
    }

    private function retireSavepoint(string $savepoint): void
    {
        $connection = spl_object_id($this->pdo);
        if (!array_key_exists($savepoint, self::$open[$connection] ?? [])) return;
        self::$open[$connection][$savepoint] = true;
        if (!isset(self::$busy[$connection])) $this->critical(static function (int $connection): void {});
    }

    private function critical(callable $work, bool $strictCleanup = false): void
    {
        $connection = spl_object_id($this->pdo);
        if (isset(self::$busy[$connection])) throw new \LogicException('Vnořená SQL operace archivního JSON není povolena.');
        self::$busy[$connection] = true;
        try {
            $work($connection);
        } catch (\Throwable $failure) {
            if (TransactionFailure::aborted($failure)) unset(self::$open[$connection]);
            throw $failure;
        } finally {
            try {
                $this->releaseRetired($connection, $strictCleanup);
            } finally {
                unset(self::$busy[$connection]);
            }
        }
    }

    /** Uklízí pouze hotový suffix; GC uvnitř exec smí jen označit další markery. */
    private function releaseRetired(int $connection, bool $strict): void
    {
        while (!empty(self::$open[$connection])) {
            if (!$this->pdo->inTransaction()) {
                unset(self::$open[$connection]);
                if ($strict) throw new \RuntimeException('Transakce archivního JSON byla ukončena.');
                break;
            }
            $newest = array_key_last(self::$open[$connection]);
            if (!self::$open[$connection][$newest]) break;
            try {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $newest);
            } catch (\Throwable $failure) {
                unset(self::$open[$connection]);
                // Jen opuštěný generátor může tolerovat neexistující marker.
                // Úspěšné dočtení ani odvolanou transakci nesmí úklid zamaskovat.
                if ($strict || TransactionFailure::aborted($failure)) throw $failure;
                break;
            }
            unset(self::$open[$connection][$newest]);
        }
        if (empty(self::$open[$connection])) unset(self::$open[$connection]);
    }
}
