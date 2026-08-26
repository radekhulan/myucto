<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export\Instance;

use PDO;
use ZipArchive;

/** Obnova jediného kompletního exportu do čisté, předem migrované databáze. */
final class CompleteInstanceRestoreService
{
    private const FORMAT = 'myucto-instance-export';
    private const VERSION = 5;
    private const SUPPORTED_VERSIONS = [3, 4, self::VERSION];
    private const DISABLED_PASSWORD_HASH = '$2y$10$K6q6A1qORRMi5gzg1me.bO4w0NqJGb9jY36Tv1azcLYtKpIwZxjua';
    private const RESTORED_RULESET_REASON = 'Obnoveno z úplného exportu firmy bez globální správcovské provenance.';

    /** @var array<string,list<string>> potomek => rodiče ověřované triggerem bez FK */
    private const TRIGGER_DEPENDENCIES = [
        'payroll_payment_liabilities' => ['payroll_run_persons'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $storageRoot,
        private readonly string $password = '',
        private readonly bool $restoreDocuments = false,
    ) {}

    /** @return array{manifest:array<string,mixed>,counts:array<string,int>,files:int,documents:int,blobs:int} */
    public function validate(string $archivePath): array
    {
        $dir = $this->extract($archivePath);
        try {
            $manifest = $this->manifest($dir);
            $counts = $this->validateContents($dir, $manifest);
            $this->assertTargetSchema($manifest);
            return [
                'manifest' => $manifest,
                'counts' => $counts,
                'files' => count($manifest['restore']['files'] ?? []),
                'documents' => count($manifest['restore']['documents'] ?? []),
                'blobs' => count($manifest['restore']['blobs'] ?? []),
            ];
        } finally {
            $this->removeDir($dir);
        }
    }

    /** @return array{manifest:array<string,mixed>,counts:array<string,int>,files:int,documents:int,blobs:int} */
    public function restore(string $archivePath): array
    {
        $dir = $this->extract($archivePath);
        try {
            $manifest = $this->manifest($dir);
            $counts = $this->validateContents($dir, $manifest);
            $this->assertTargetSchema($manifest);
            $this->assertEmptyTarget();
            $this->assertEmptyStorage();

            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->pdo->beginTransaction();
            try {
                foreach ((array) ($manifest['sections']['data']['shared_payroll_tables'] ?? []) as $table => $info) {
                    $this->restoreSharedEntry($dir, (string) $table, $info['entry'] ?? null, $counts);
                }
                $identity = (array) ($manifest['sections']['data']['identity']['entries'] ?? []);
                foreach (['roles', 'role_permissions', 'users'] as $table) {
                    $this->restoreEntry($dir, $table, $identity[$table]['entry'] ?? null, $counts, $table === 'roles' || $table === 'role_permissions');
                }
                $tables = (array) ($manifest['sections']['data']['tables'] ?? []);
                foreach ($this->restoreTableOrder(array_keys($tables)) as $table) {
                    $info = (array) ($tables[$table] ?? []);
                    $this->restoreEntry($dir, (string) $table, $info['entry'] ?? null, $counts);
                }
                $this->restoreEntry($dir, 'user_suppliers', $identity['user_suppliers']['entry'] ?? null, $counts);
                $this->restoreBlobs($dir, (array) ($manifest['restore']['blobs'] ?? []));
                $this->nullReferencesToOmittedSecrets($manifest);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }

            $violations = $this->foreignKeyViolations();
            if ($violations !== []) {
                throw new InstanceExportException('restore_fk_invalid', 'Obnova vytvořila neplatné vazby: ' . implode('; ', $violations));
            }
            $files = $this->restoreFiles($dir, (array) ($manifest['restore']['files'] ?? []));
            $documents = $this->restoreDocuments
                ? $this->restoreDocumentFiles($dir, (array) ($manifest['restore']['documents'] ?? []))
                : 0;
            return [
                'manifest' => $manifest,
                'counts' => $counts,
                'files' => $files,
                'documents' => $documents,
                'blobs' => count($manifest['restore']['blobs'] ?? []),
            ];
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * FOREIGN_KEY_CHECKS dovolí vložit potomka před rodičem, databázové triggery
     * ale běží dál. Pořadí JSONL v archivu proto nestačí: přímo tenantové tabulky
     * mají v resolveru stejnou hloubku a starší archiv mohl uvést například
     * payroll_generated_documents před payroll_run_revisions. Cílové schéma je
     * autoritativní zdroj vazeb a rodiče řadí před potomky i pro archiv verze 3/4.
     *
     * @param list<int|string> $tables
     * @return list<string>
     */
    private function restoreTableOrder(array $tables): array
    {
        $orderedInput = array_values(array_unique(array_map('strval', $tables)));
        $included = array_fill_keys($orderedInput, true);
        $position = array_flip($orderedInput);
        $children = array_fill_keys($orderedInput, []);
        $edges = [];
        $foreignKeyStatement = $this->pdo->query(
            'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IS NOT NULL',
        );
        $foreignKeys = $foreignKeyStatement === false
            ? []
            : ($foreignKeyStatement->fetchAll(PDO::FETCH_ASSOC) ?: []);
        foreach ($foreignKeys as $foreignKey) {
            $child = (string) $foreignKey['TABLE_NAME'];
            $parent = (string) $foreignKey['REFERENCED_TABLE_NAME'];
            if ($child === $parent || !isset($included[$child], $included[$parent])) {
                continue;
            }
            $edge = $parent . "\0" . $child;
            if (isset($edges[$edge])) {
                continue;
            }
            $edges[$edge] = true;
            $children[$parent][] = $child;
        }
        foreach (self::TRIGGER_DEPENDENCIES as $child => $parents) {
            foreach ($parents as $parent) {
                if (!isset($included[$child], $included[$parent])) {
                    continue;
                }
                $edge = $parent . "\0" . $child;
                if (isset($edges[$edge])) {
                    continue;
                }
                $edges[$edge] = true;
                $children[$parent][] = $child;
            }
        }

        // Tenantové schéma obsahuje skutečné cykly (typicky firma ↔ její výchozí
        // číselník). Nejdřív je proto stáhneme do silně souvislých komponent a
        // topologicky seřadíme až jejich acyklický graf. Potomci komponenty se tak
        // nedostanou před ni jen proto, že jeden její člen odkazuje zpět.
        $nextIndex = 0;
        $indices = [];
        $lowLinks = [];
        $stack = [];
        $onStack = [];
        $components = [];
        $visit = function (string $table) use (
            &$visit,
            &$nextIndex,
            &$indices,
            &$lowLinks,
            &$stack,
            &$onStack,
            &$components,
            $children,
        ): void {
            $indices[$table] = $nextIndex;
            $lowLinks[$table] = $nextIndex;
            $nextIndex++;
            $stack[] = $table;
            $onStack[$table] = true;
            foreach ($children[$table] as $child) {
                if (!array_key_exists($child, $indices)) {
                    $visit($child);
                    $lowLinks[$table] = min($lowLinks[$table], $lowLinks[$child]);
                } elseif (isset($onStack[$child])) {
                    $lowLinks[$table] = min($lowLinks[$table], $indices[$child]);
                }
            }
            if ($lowLinks[$table] !== $indices[$table]) {
                return;
            }
            $component = [];
            do {
                $member = array_pop($stack);
                if (!is_string($member)) {
                    throw new \LogicException('Graf pořadí obnovy obsahuje neúplnou komponentu.');
                }
                unset($onStack[$member]);
                $component[] = $member;
            } while ($member !== $table);
            $components[] = $component;
        };
        foreach ($orderedInput as $table) {
            if (!array_key_exists($table, $indices)) {
                $visit($table);
            }
        }

        $componentOf = [];
        $componentPosition = [];
        foreach ($components as $componentId => &$component) {
            usort($component, static fn (string $left, string $right): int => $position[$left] <=> $position[$right]);
            $componentPosition[$componentId] = min(array_map(
                static fn (string $table): int => $position[$table],
                $component,
            ));
            foreach ($component as $table) {
                $componentOf[$table] = $componentId;
            }
        }
        unset($component);

        $componentChildren = array_fill(0, count($components), []);
        $componentInDegree = array_fill(0, count($components), 0);
        $componentEdges = [];
        foreach ($children as $parent => $childTables) {
            foreach ($childTables as $child) {
                $from = $componentOf[$parent];
                $to = $componentOf[$child];
                if ($from === $to || isset($componentEdges[$from . ':' . $to])) {
                    continue;
                }
                $componentEdges[$from . ':' . $to] = true;
                $componentChildren[$from][] = $to;
                $componentInDegree[$to]++;
            }
        }
        $ready = array_keys(array_filter(
            $componentInDegree,
            static fn (int $degree): bool => $degree === 0,
        ));
        usort($ready, static fn (int $left, int $right): int => $componentPosition[$left] <=> $componentPosition[$right]);
        $result = [];
        while ($ready !== []) {
            $componentId = array_shift($ready);
            array_push($result, ...$components[$componentId]);
            foreach ($componentChildren[$componentId] as $childId) {
                $componentInDegree[$childId]--;
                if ($componentInDegree[$childId] === 0) {
                    $ready[] = $childId;
                }
            }
            usort($ready, static fn (int $left, int $right): int => $componentPosition[$left] <=> $componentPosition[$right]);
        }
        if (count($result) !== count($orderedInput)) {
            throw new \LogicException(
                'Graf pořadí obnovy nepokryl všechny tabulky archivu.',
            );
        }
        return $result;
    }

    private function manifest(string $dir): array
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT
            || !in_array((int) ($manifest['version'] ?? 0), self::SUPPORTED_VERSIONS, true)) {
            throw new InstanceExportException('restore_format_invalid', 'Archiv není kompletní obnovitelný export podporovaného formátu.');
        }
        if (($manifest['restore']['available'] ?? false) !== true || !isset($manifest['sections']['data']['tables'])) {
            throw new InstanceExportException('restore_incomplete', 'Archiv neobsahuje obnovitelná data; při exportu zvolte „Úplný obnovitelný archiv“.');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @return array<string,int> */
    private function validateContents(string $dir, array $manifest): array
    {
        $checksums = (array) ($manifest['checksums'] ?? []);
        foreach ($checksums as $entry => $expected) {
            $path = $this->entryPath($dir, (string) $entry);
            if (!is_file($path) || hash_file('sha256', $path) !== (string) ($expected['sha256'] ?? '') || filesize($path) !== (int) ($expected['size'] ?? -1)) {
                throw new InstanceExportException('restore_checksum_invalid', 'Kontrolní součet nebo velikost nesedí: ' . $entry);
            }
        }
        $counts = [];
        foreach ((array) ($manifest['sections']['data']['tables'] ?? []) as $table => $info) {
            $counts[(string) $table] = $this->validateJsonl($dir, $info['entry'] ?? null, (int) ($info['rows'] ?? 0), (string) $table);
        }
        foreach ((array) ($manifest['sections']['data']['shared_payroll_tables'] ?? []) as $table => $info) {
            $counts[(string) $table] = $this->validateJsonl($dir, $info['entry'] ?? null, (int) ($info['rows'] ?? 0), (string) $table);
        }
        foreach ((array) ($manifest['sections']['data']['identity']['entries'] ?? []) as $table => $info) {
            $counts[(string) $table] = $this->validateJsonl($dir, $info['entry'] ?? null, (int) ($info['rows'] ?? 0), (string) $table);
        }
        foreach (array_merge(
            (array) ($manifest['restore']['files'] ?? []),
            (array) ($manifest['restore']['documents'] ?? []),
            (array) ($manifest['restore']['blobs'] ?? []),
        ) as $asset) {
            if (!is_array($asset) || !isset($asset['entry']) || !is_file($this->entryPath($dir, (string) $asset['entry']))) {
                throw new InstanceExportException('restore_asset_missing', 'V archivu chybí soubor pro obnovu.');
            }
        }
        return $counts;
    }

    private function validateJsonl(string $dir, mixed $entry, int $expectedRows, string $table): int
    {
        if ($entry === null) {
            return $expectedRows === 0 ? 0 : throw new InstanceExportException('restore_jsonl_missing', "Chybí JSONL tabulky {$table}.");
        }
        $path = $this->entryPath($dir, (string) $entry);
        if (!is_file($path)) {
            throw new InstanceExportException('restore_jsonl_missing', "Chybí JSONL tabulky {$table}.");
        }
        $count = 0;
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            if (!is_array(json_decode($line, true))) {
                throw new InstanceExportException('restore_jsonl_invalid', "Neplatný JSONL řádek: {$table}.");
            }
            $count++;
        }
        fclose($fh);
        if ($count !== $expectedRows) {
            throw new InstanceExportException('restore_row_count_invalid', "Počet řádků nesedí: {$table}.");
        }
        return $count;
    }

    /** @param array<string,int> $counts */
    private function restoreEntry(string $dir, string $table, mixed $entry, array &$counts, bool $ignoreDuplicates = false): void
    {
        if ($entry === null || !isset($counts[$table])) {
            return;
        }
        $columns = $this->tableColumns($table);
        $fh = fopen($this->entryPath($dir, (string) $entry), 'rb');
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $row = InstanceExportBinaryCodec::decodeRow($row);
            if ($table === 'users') {
                $row['password_hash'] = self::DISABLED_PASSWORD_HASH;
                $row['totp_enabled'] = 0;
                $row['totp_secret'] = null;
                $row['is_active'] = 0;
            }
            $row = array_intersect_key($row, $columns);
            if ($row === []) {
                continue;
            }
            $names = array_keys($row);
            $sql = 'INSERT ' . ($ignoreDuplicates ? 'IGNORE ' : '') . 'INTO `' . $table . '` (`'
                . implode('`, `', $names) . '`) VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $this->pdo->prepare($sql)->execute(array_values($row));
        }
        fclose($fh);
    }

    /** @param array<string,int> $counts */
    private function restoreSharedEntry(string $dir, string $table, mixed $entry, array &$counts): void
    {
        if ($entry === null || !isset($counts[$table])) {
            return;
        }
        if (!in_array($table, InstanceExportService::SHARED_PAYROLL_TABLES, true)) {
            throw new InstanceExportException('restore_shared_table_invalid', 'Archiv obsahuje nepovolenou globální mzdovou tabulku.');
        }
        $columns = $this->tableColumns($table);
        $primary = $this->primaryKeyColumns($table);
        if ($primary === []) {
            throw new InstanceExportException('restore_shared_primary_key_missing', 'Globální mzdová tabulka nemá primární klíč: ' . $table);
        }
        $fh = fopen($this->entryPath($dir, (string) $entry), 'rb');
        if ($fh === false) {
            throw new InstanceExportException('restore_jsonl_missing', 'Chybí JSONL tabulky ' . $table . '.');
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $row = InstanceExportBinaryCodec::decodeRow($row);
                $row = array_intersect_key($row, $columns);
                foreach ($primary as $column) {
                    if (!array_key_exists($column, $row)) {
                        throw new InstanceExportException('restore_shared_primary_key_missing', 'V archivu chybí primární klíč ' . $table . '.' . $column . '.');
                    }
                }
                $where = implode(' AND ', array_map(static fn (string $column): string => '`' . $column . '` = ?', $primary));
                $lookup = $this->pdo->prepare('SELECT * FROM `' . $table . '` WHERE ' . $where);
                $lookup->execute(array_map(static fn (string $column): mixed => $row[$column], $primary));
                $existing = $lookup->fetch(PDO::FETCH_ASSOC);
                if ($existing !== false) {
                    $existingComparable = array_intersect_key($existing, $row);
                    if ($table === 'payroll_rulesets') {
                        foreach ([
                            'created_by', 'updated_by', 'reviewed_by', 'approved_by',
                            'activated_by', 'superseded_by',
                        ] as $column) {
                            if (($row[$column] ?? null) === 0 || ($row[$column] ?? null) === '0') {
                                $existingComparable[$column] = $existing[$column] === null ? null : 0;
                            }
                        }
                    }
                    if (!$this->sameDatabaseRow($row, $existingComparable)) {
                        throw new InstanceExportException('restore_shared_conflict', 'Cílová instalace má odlišný globální mzdový podklad: ' . $table . '.');
                    }
                    continue;
                }
                $names = array_keys($row);
                if ($table === 'payroll_rulesets' && !array_key_exists('reason', $row)) {
                    $row['reason'] = self::RESTORED_RULESET_REASON;
                    $names[] = 'reason';
                }
                $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES ('
                    . implode(', ', array_fill(0, count($names), '?')) . ')';
                try {
                    $this->pdo->prepare($sql)->execute(array_values($row));
                } catch (\Throwable) {
                    throw new InstanceExportException(
                        'restore_shared_conflict',
                        'Globální mzdový podklad nelze bezpečně sloučit: ' . $table . '.',
                    );
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /** @param list<array<string,mixed>> $assets */
    private function restoreBlobs(string $dir, array $assets): void
    {
        foreach ($assets as $asset) {
            $table = (string) ($asset['table'] ?? '');
            $column = (string) ($asset['column'] ?? '');
            if (!$this->safeIdentifier($table) || !$this->safeIdentifier($column) || !isset($this->tableColumns($table)[$column])) {
                throw new InstanceExportException('restore_blob_invalid', 'Neplatná definice binárního souboru v archivu.');
            }
            $data = file_get_contents($this->entryPath($dir, (string) ($asset['entry'] ?? '')));
            $stmt = $this->pdo->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE id = ?");
            $stmt->bindValue(1, $data, PDO::PARAM_LOB);
            $stmt->bindValue(2, (int) ($asset['id'] ?? 0), PDO::PARAM_INT);
            $stmt->execute();
            if ($stmt->rowCount() !== 1) {
                throw new InstanceExportException('restore_blob_target_missing', 'Cíl binárního souboru v databázi chybí.');
            }
        }
    }

    /** @param list<array<string,mixed>> $assets */
    private function restoreFiles(string $dir, array $assets): int
    {
        $count = 0;
        $root = rtrim($this->storageRoot, '/\\');
        foreach ($assets as $asset) {
            $relative = str_replace('\\', '/', (string) ($asset['storage_path'] ?? ''));
            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
                throw new InstanceExportException('restore_file_path_invalid', 'Neplatná cílová cesta přílohy.');
            }
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new InstanceExportException('restore_file_write_failed', 'Nelze vytvořit adresář přílohy.');
            }
            $source = $this->entryPath($dir, (string) ($asset['entry'] ?? ''));
            if (!copy($source, $target)) {
                throw new InstanceExportException('restore_file_write_failed', 'Nelze obnovit přílohu.');
            }
            if (($asset['sha256'] ?? null) !== null && hash_file('sha256', $target) !== (string) $asset['sha256']) {
                throw new InstanceExportException('restore_file_checksum_invalid', 'Kontrolní součet obnovené přílohy nesedí.');
            }
            $count++;
        }
        return $count;
    }

    /** Obnoví PDF dokladů a až po úspěšném zápisu je propojí s jejich DB řádky. */
    private function restoreDocumentFiles(string $dir, array $assets): int
    {
        $count = $this->restoreFiles($dir, $assets);
        foreach ($assets as $asset) {
            $link = $asset['link'] ?? null;
            if ($link === null) {
                continue;
            }
            if (!is_array($link)) {
                throw new InstanceExportException('restore_document_link_invalid', 'Neplatná vazba dokladu v archivu.');
            }
            $table = (string) ($link['table'] ?? '');
            $column = (string) ($link['column'] ?? '');
            $value = str_replace('\\', '/', (string) ($link['value'] ?? ''));
            if (!in_array([$table, $column], [['invoices', 'pdf_path']], true)
                || $value === '' || str_contains($value, '..') || str_starts_with($value, '/')) {
                throw new InstanceExportException('restore_document_link_invalid', 'Neplatná vazba dokladu v archivu.');
            }
            $stmt = $this->pdo->prepare('UPDATE `invoices` SET `pdf_path` = ? WHERE `id` = ?');
            $stmt->execute([$value, (int) ($link['id'] ?? 0)]);
            $target = $this->pdo->prepare('SELECT COUNT(*) FROM `invoices` WHERE `id` = ?');
            $target->execute([(int) ($link['id'] ?? 0)]);
            if ((int) $target->fetchColumn() !== 1) {
                throw new InstanceExportException('restore_document_target_missing', 'Cíl vazby dokladu v databázi chybí.');
            }
        }
        return $count;
    }

    /** @param array<string,mixed> $manifest */
    private function assertTargetSchema(array $manifest): void
    {
        foreach ((array) ($manifest['sections']['data']['shared_payroll_tables'] ?? []) as $table => $info) {
            if ((int) ($info['rows'] ?? 0) > 0) {
                $this->tableColumns((string) $table);
            }
        }
        foreach ((array) ($manifest['sections']['data']['tables'] ?? []) as $table => $info) {
            // Prázdná tabulka ze starší instance nemusí v cílové novější verzi
            // existovat; není co ztratit. Řádky ale nikdy tiše nezahodíme.
            if ((int) ($info['rows'] ?? 0) > 0) {
                $this->tableColumns((string) $table);
            }
        }
        foreach (['roles', 'role_permissions', 'users', 'user_suppliers'] as $table) {
            $this->tableColumns($table);
        }
    }

    private function assertEmptyTarget(): void
    {
        foreach (['supplier', 'users', 'user_suppliers'] as $table) {
            if ((int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() > 0) {
                throw new InstanceExportException('restore_target_not_empty', 'Cílová databáze není prázdná (tabulka ' . $table . ').');
            }
        }
    }

    private function assertEmptyStorage(): void
    {
        if (!is_dir($this->storageRoot) && !mkdir($this->storageRoot, 0775, true) && !is_dir($this->storageRoot)) {
            throw new InstanceExportException('restore_storage_unavailable', 'Nelze vytvořit cílový datový adresář.');
        }
        foreach (scandir($this->storageRoot) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                throw new InstanceExportException('restore_storage_not_empty', 'Cílový datový adresář není prázdný.');
            }
        }
    }

    /** @return array<string,true> */
    private function tableColumns(string $table): array
    {
        if (!$this->safeIdentifier($table)) {
            throw new InstanceExportException('restore_table_invalid', 'Neplatný název tabulky v archivu.');
        }
        $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = "")');
        $stmt->execute([$table]);
        $columns = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
        if ($columns === []) {
            throw new InstanceExportException('restore_schema_missing', 'Cílové schéma nemá tabulku ' . $table . '.');
        }
        return $columns;
    }

    /** @return list<string> */
    private function foreignKeyViolations(): array
    {
        $sql = 'SELECT k.CONSTRAINT_NAME, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME
                  FROM information_schema.KEY_COLUMN_USAGE k
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION';
        $violations = [];
        $constraints = [];
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fk) {
            $key = $fk['TABLE_NAME'] . ':' . $fk['CONSTRAINT_NAME'];
            $constraints[$key][] = $fk;
        }
        foreach ($constraints as $parts) {
            $first = $parts[0];
            foreach ($parts as $fk) {
                foreach (['TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'] as $key) {
                    if (!$this->safeIdentifier((string) $fk[$key])) {
                        continue 3;
                    }
                }
            }
            $joins = implode(' AND ', array_map(static fn (array $fk): string => 'c.`' . $fk['COLUMN_NAME'] . '` = p.`' . $fk['REFERENCED_COLUMN_NAME'] . '`', $parts));
            $present = implode(' AND ', array_map(static fn (array $fk): string => 'c.`' . $fk['COLUMN_NAME'] . '` IS NOT NULL', $parts));
            $missing = 'p.`' . $first['REFERENCED_COLUMN_NAME'] . '` IS NULL';
            $query = sprintf('SELECT COUNT(*) FROM `%s` c LEFT JOIN `%s` p ON %s WHERE %s AND %s', $first['TABLE_NAME'], $first['REFERENCED_TABLE_NAME'], $joins, $present, $missing);
            $count = (int) $this->pdo->query($query)->fetchColumn();
            if ($count > 0) {
                $violations[] = $first['TABLE_NAME'] . '.' . $first['CONSTRAINT_NAME'] . ' (' . $count . ')';
            }
        }
        return $violations;
    }

    /** @param array<string,mixed> $manifest */
    private function nullReferencesToOmittedSecrets(array $manifest): void
    {
        $included = array_fill_keys(array_keys((array) ($manifest['sections']['data']['tables'] ?? [])), true);
        $included += array_fill_keys(array_keys((array) ($manifest['sections']['data']['shared_payroll_tables'] ?? [])), true);
        $included += array_fill_keys(['roles', 'role_permissions', 'users', 'user_suppliers'], true);
        $sql = 'SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, c.IS_NULLABLE
                  FROM information_schema.KEY_COLUMN_USAGE k
                  JOIN information_schema.COLUMNS c
                    ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
                 WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
                   AND k.CONSTRAINT_NAME IN (
                       SELECT constraint_name FROM information_schema.KEY_COLUMN_USAGE
                        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                        GROUP BY TABLE_NAME, constraint_name HAVING COUNT(*) = 1
                   )';
        foreach ($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fk) {
            $child = (string) $fk['TABLE_NAME'];
            $column = (string) $fk['COLUMN_NAME'];
            $parent = (string) $fk['REFERENCED_TABLE_NAME'];
            if (isset($included[$parent]) || strtoupper((string) $fk['IS_NULLABLE']) !== 'YES'
                || !$this->safeIdentifier($child) || !$this->safeIdentifier($column) || !$this->safeIdentifier($parent)) {
                continue;
            }
            if ((int) $this->pdo->query("SELECT COUNT(*) FROM `{$parent}`")->fetchColumn() !== 0) {
                continue;
            }
            $this->pdo->exec("UPDATE `{$child}` SET `{$column}` = NULL WHERE `{$column}` IS NOT NULL");
        }
    }

    /** @return list<string> */
    private function primaryKeyColumns(string $table): array
    {
        if (!$this->safeIdentifier($table)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = "PRI"
              ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function sameDatabaseRow(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $column => $value) {
            if (!array_key_exists($column, $right)) {
                return false;
            }
            $other = $right[$column];
            if ($value === null || $other === null) {
                if ($value !== null || $other !== null) {
                    return false;
                }
                continue;
            }
            if ((string) $value !== (string) $other) {
                return false;
            }
        }
        return true;
    }

    private function extract(string $archivePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new InstanceExportException('restore_zip_invalid', 'Archiv nelze otevřít.');
        }
        if ($this->password !== '') {
            $zip->setPassword($this->password);
        }
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'myucto-restore-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700, true)) {
            $zip->close();
            throw new InstanceExportException('restore_temp_failed', 'Nelze vytvořit pracovní adresář obnovy.');
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_starts_with($name, '/') || str_contains(str_replace('\\', '/', $name), '../')) {
                    throw new InstanceExportException('restore_zip_path_invalid', 'Archiv obsahuje nebezpečnou cestu.');
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $target = $this->entryPath($dir, $name);
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                    throw new InstanceExportException('restore_temp_failed', 'Nelze rozbalit archiv.');
                }
                $in = $zip->getStream($name);
                $out = $in === false ? false : fopen($target, 'wb');
                if ($in === false || $out === false) {
                    throw new InstanceExportException('restore_zip_read_failed', 'Položku archivu nelze přečíst (zkontrolujte heslo).');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);
            }
        } catch (\Throwable $e) {
            $this->removeDir($dir);
            throw $e;
        } finally {
            $zip->close();
        }
        return $dir;
    }

    private function entryPath(string $base, string $entry): string
    {
        return $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
    }

    private function safeIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
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
