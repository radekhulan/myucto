<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization;

use PDO;

final class FinancialCloneHashMap implements FinancialCloneHashResolver
{
    private bool $dropped = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
        private readonly string $token,
        private readonly string $mapTable,
        private readonly string $ownerTable,
    ) {}

    public static function create(PDO $pdo): self
    {
        if ($pdo->inTransaction()) {
            throw new \LogicException('Hash mapa finančního klonu musí vzniknout mimo transakci.');
        }
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new \LogicException('Hash mapa finančního klonu vyžaduje zvolenou databázi.');
        }
        $suffix = bin2hex(random_bytes(8));
        $token = hash('sha256', random_bytes(32));
        $mapTable = 'anon_fin_hash_' . $suffix;
        $ownerTable = 'anon_fin_owner_' . $suffix;
        $map = new self($pdo, $database, $token, $mapTable, $ownerTable);
        $map->createTables();
        return $map;
    }

    public function record(FinancialCloneHashKind $kind, int $ownerId, string $oldHash, string $newHash): void
    {
        $this->recordOwned($kind, 0, $ownerId, $oldHash, $newHash);
    }

    public function recordOwned(FinancialCloneHashKind $kind, int $supplierId, int $ownerId, string $oldHash, string $newHash): void
    {
        $this->assertActiveTransaction();
        if ($supplierId < 0 || $ownerId <= 0) {
            throw new \InvalidArgumentException('Vlastník náhrady hashe musí být kladný.');
        }
        $oldHash = self::validatedHash($oldHash);
        $newHash = self::validatedHash($newHash);

        $insert = $this->pdo->prepare(
            "INSERT INTO `{$this->mapTable}` (run_token, old_hash, new_hash, marker) VALUES (?, ?, ?, 0) "
            . 'ON DUPLICATE KEY UPDATE old_hash = VALUES(old_hash)'
        );
        $insert->execute([$this->token, $oldHash, $newHash]);

        $select = $this->pdo->prepare(
            "SELECT new_hash FROM `{$this->mapTable}` WHERE run_token = ? AND old_hash = ? AND marker = 0 FOR UPDATE"
        );
        $select->execute([$this->token, $oldHash]);
        $stored = $select->fetchColumn();
        if (!is_string($stored) || !hash_equals($stored, $newHash)) {
            throw new \LogicException('Hash mapa finančního klonu obsahuje konfliktní náhradu.');
        }

        $link = $this->pdo->prepare(
            "INSERT INTO `{$this->ownerTable}` (run_token, old_hash, kind, supplier_id, owner_id, marker) VALUES (?, ?, ?, ?, ?, 0) "
            . 'ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id)'
        );
        $link->execute([$this->token, $oldHash, $kind->value, $supplierId, $ownerId]);
    }

    public function resolve(string $oldHash): ?string
    {
        $this->assertActiveTransaction();
        $oldHash = self::validatedHash($oldHash);
        $query = $this->pdo->prepare(
            "SELECT new_hash FROM `{$this->mapTable}` WHERE run_token = ? AND old_hash = ? AND marker = 0"
        );
        $query->execute([$this->token, $oldHash]);
        $value = $query->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function resolveOwnedMany(array $requests): array
    {
        $this->assertActiveTransaction();
        if ($requests === [] || count($requests) > 3000) {
            throw new \InvalidArgumentException('Dávka vlastnických hashů musí obsahovat 1 až 3000 položek.');
        }
        $normalized = [];
        foreach ($requests as $offset => $request) {
            if (!is_array($request) || !($request['kind'] ?? null) instanceof FinancialCloneHashKind
                || !is_int($request['supplier_id'] ?? null) || $request['supplier_id'] <= 0
                || !is_int($request['owner_id'] ?? null) || $request['owner_id'] <= 0
                || !is_string($request['old_hash'] ?? null)) {
                throw new \InvalidArgumentException('Vlastnický požadavek na hash je neplatný.');
            }
            $normalized[$offset] = [
                'kind' => $request['kind'],
                'supplier_id' => $request['supplier_id'],
                'owner_id' => $request['owner_id'],
                'old_hash' => self::validatedHash($request['old_hash']),
            ];
        }
        $resolved = [];
        foreach (array_chunk($normalized, 250, true) as $chunk) {
            $tuples = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
            $params = [$this->token];
            foreach ($chunk as $request) {
                $params[] = $request['kind']->value;
                $params[] = $request['supplier_id'];
                $params[] = $request['owner_id'];
                $params[] = $request['old_hash'];
            }
            $query = $this->pdo->prepare(
                "SELECT o.kind,o.supplier_id,o.owner_id,o.old_hash,m.new_hash FROM `{$this->ownerTable}` o "
                . "JOIN `{$this->mapTable}` m ON m.run_token=o.run_token AND m.old_hash=o.old_hash AND m.marker=0 "
                . "WHERE o.run_token=? AND o.marker=0 AND (o.kind,o.supplier_id,o.owner_id,o.old_hash) IN ({$tuples})"
            );
            $query->execute($params);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = self::ownedKey((string) $row['kind'], (int) $row['supplier_id'], (int) $row['owner_id'], (string) $row['old_hash']);
                if (isset($resolved[$key]) && !hash_equals($resolved[$key], (string) $row['new_hash'])) {
                    throw new \LogicException('Vlastnická hash mapa obsahuje konfliktní náhradu.');
                }
                $resolved[$key] = (string) $row['new_hash'];
            }
        }
        $out = [];
        foreach ($normalized as $offset => $request) {
            $key = self::ownedKey($request['kind']->value, $request['supplier_id'], $request['owner_id'], $request['old_hash']);
            $out[$offset] = $resolved[$key] ?? null;
        }
        return $out;
    }

    public function mappingCount(): int
    {
        return $this->countRows($this->mapTable, 'marker = 0');
    }

    public function ownerCount(): int
    {
        return $this->countRows($this->ownerTable, 'marker = 0');
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Hash mapu finančního klonu nelze odstranit uvnitř transakce.');
        }
        $this->assertDatabase();
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $exists->execute([$this->database, $this->mapTable]);
        if ((int) $exists->fetchColumn() === 0) {
            $this->dropped = true;
            return;
        }
        $proof = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `{$this->mapTable}` WHERE run_token = ? AND marker = 1 AND old_hash IS NULL AND new_hash IS NULL"
        );
        $proof->execute([$this->token]);
        if ((int) $proof->fetchColumn() !== 1) {
            throw new \LogicException('Hash mapu finančního klonu nevlastní aktuální běh.');
        }
        $ownerProof = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `{$this->ownerTable}` WHERE run_token = ? AND marker = 1 AND old_hash IS NULL AND kind IS NULL AND supplier_id IS NULL AND owner_id IS NULL"
        );
        $ownerProof->execute([$this->token]);
        if ((int) $ownerProof->fetchColumn() !== 1) {
            throw new \LogicException('Vlastnické vazby hash mapy finančního klonu nevlastní aktuální běh.');
        }
        $this->pdo->exec("DROP TABLE `{$this->ownerTable}`");
        $this->pdo->exec("DROP TABLE `{$this->mapTable}`");
        $this->dropped = true;
    }

    private function createTables(): void
    {
        $mapCreated = false;
        $ownerCreated = false;
        try {
            $this->pdo->exec(
                "CREATE TABLE `{$this->mapTable}` ("
                . 'entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'old_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'new_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'marker TINYINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (entry_id), '
                . 'UNIQUE KEY uq_hash (run_token, old_hash), '
                . 'KEY ix_marker (run_token, marker)'
                . ') ENGINE=InnoDB'
            );
            $mapCreated = true;
            $marker = $this->pdo->prepare(
                "INSERT INTO `{$this->mapTable}` (run_token, old_hash, new_hash, marker) VALUES (?, NULL, NULL, 1)"
            );
            $marker->execute([$this->token]);
            $this->pdo->exec(
                "CREATE TABLE `{$this->ownerTable}` ("
                . 'entry_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'run_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'old_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'supplier_id BIGINT UNSIGNED NULL, '
                . 'owner_id BIGINT UNSIGNED NULL, '
                . 'marker TINYINT UNSIGNED NOT NULL, '
                . 'PRIMARY KEY (entry_id), '
                . 'UNIQUE KEY uq_owner (run_token, kind, supplier_id, owner_id, old_hash), '
                . 'KEY ix_owner (run_token, old_hash)'
                . ') ENGINE=InnoDB'
            );
            $ownerCreated = true;
            $ownerMarker = $this->pdo->prepare(
                "INSERT INTO `{$this->ownerTable}` (run_token, old_hash, kind, supplier_id, owner_id, marker) VALUES (?, NULL, NULL, NULL, NULL, 1)"
            );
            $ownerMarker->execute([$this->token]);
        } catch (\Throwable $e) {
            $this->cleanupFailedCreate($mapCreated, $ownerCreated);
            throw $e;
        }
    }

    private function cleanupFailedCreate(bool $mapCreated, bool $ownerCreated): void
    {
        $this->assertDatabase();
        if ($ownerCreated) {
            $this->pdo->exec("DROP TABLE `{$this->ownerTable}`");
        }
        if ($mapCreated) {
            $this->pdo->exec("DROP TABLE `{$this->mapTable}`");
        }
        $this->dropped = true;
    }

    private function countRows(string $table, string $predicate): int
    {
        $this->assertDatabase();
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE run_token = ? AND {$predicate}");
        $query->execute([$this->token]);
        return (int) $query->fetchColumn();
    }

    private function assertActiveTransaction(): void
    {
        if ($this->dropped || !$this->pdo->inTransaction()) {
            throw new \LogicException('Hash mapa finančního klonu vyžaduje aktivní vlastnickou transakci.');
        }
        $this->assertDatabase();
    }

    private function assertDatabase(): void
    {
        $current = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!hash_equals($this->database, $current)) {
            throw new \LogicException('Hash mapa finančního klonu patří jiné databázi.');
        }
    }

    private static function validatedHash(string $hash): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Hash musí být malý hexadecimální SHA-256.');
        }
        return $hash;
    }

    private static function ownedKey(string $kind, int $supplierId, int $ownerId, string $oldHash): string
    {
        return $kind . ':' . $supplierId . ':' . $ownerId . ':' . $oldHash;
    }
}
