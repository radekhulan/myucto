<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use PDO;
use RuntimeException;

/** Atomic graph import into an existing tenant; stored money is never recalculated. */
final class MyuctoImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantScopeResolver $scopes,
        private readonly MyuctoImportFiles $files
    )
    {
    }

    public function import(
        array $package,
        int $supplierId,
        int $actorId,
        string $source,
        bool $dryRun = true
    ): array
    {
        if (!preg_match('/\A[a-zA-Z0-9._-]{1,80}\z/D', $source)) {
            throw new RuntimeException('Zdroj musí mít stabilní název o 1–80 znacích (písmena, číslice, tečka, podtržítko, pomlčka).');
        }
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Import vyžaduje vlastní transakci.');
        }
        $tables = $package['tables'];
        // Preserve the source history order, including entries with identical timestamps.
        if (isset($tables['de_movement_classification_history'])) {
            ksort($tables['de_movement_classification_history'], SORT_NUMERIC);
        }
        $sourceSupplier = MyuctoExportReader::id($package['manifest']['supplier']['id']);
        $root = $tables['supplier'][$sourceSupplier];
        $schema = SchemaMetadataProvider::load($pdo);
        $this->validateRows($tables, $sourceSupplier, $schema);
        MyuctoAccountingGraph::validate($tables);
        $sourceKey = 'myucto:' . hash('sha256', $source . ':' . $sourceSupplier);
        $digest = MyuctoImportProfile::VERSION . ':' . hash('sha256', serialize([$tables, $package['assets'] ?? []]));
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM supplier WHERE id = ? FOR UPDATE');
            $stmt->execute([$supplierId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($target === false) {
                throw new RuntimeException('Cílová firma neexistuje.');
            }
            foreach (['ic', 'accounting_mode', 'is_vat_payer', 'is_identified', 'taxpayer_type', 'vat_period'] as $column) {
                if (
                    !array_key_exists($column, $root)
                    || (string) $root[$column] !== (string) $target[$column]
                ) {
                    throw new RuntimeException('Zdrojová a cílová firma se liší: ' . $column . '.');
                }
            }
            if (trim((string) $root['ic']) === '') {
                throw new RuntimeException('Zdrojová i cílová firma musí mít vyplněné shodné IČO.');
            }
            $sourceCurrency = $tables['currencies'][MyuctoExportReader::id($root['default_currency_id'] ?? null)] ?? null;
            $stmt = $pdo->prepare('SELECT code FROM currencies WHERE id = ? AND supplier_id = ?');
            $stmt->execute([$target['default_currency_id'], $supplierId]);
            if ($sourceCurrency === null || $sourceCurrency['code'] !== $stmt->fetchColumn()) {
                throw new RuntimeException('Výchozí měna cílové firmy se liší od exportu.');
            }
            $sourceCountry = $tables['countries'][MyuctoExportReader::id($root['country_id'] ?? null)] ?? null;
            $stmt = $pdo->prepare('SELECT iso2 FROM countries WHERE id = ?');
            $stmt->execute([$target['country_id']]);
            if ($sourceCountry === null || $sourceCountry['iso2'] !== $stmt->fetchColumn()) {
                throw new RuntimeException('Země cílové firmy se liší od exportu.');
            }
            $this->assertVatHistory($pdo, $tables['supplier_vat_status_history'] ?? null, $sourceSupplier, $supplierId);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
            $stmt->execute([$actorId]);
            if ($stmt->fetchColumn() === false) {
                throw new RuntimeException('Aktér importu musí být existující aktivní uživatel.');
            }
            $stmt = $pdo->prepare("SELECT external_id FROM external_entity_map WHERE supplier_id = ? AND source_key = ? AND entity_type = 'myucto_import'");
            $stmt->execute([$supplierId, $sourceKey]);
            $previous = $stmt->fetchColumn();
            if ($previous !== false && !str_starts_with($previous, MyuctoImportProfile::VERSION . ':')) {
                throw new RuntimeException('Firma již byla obnovena starším importním profilem. Doplnění předkontací a klasifikací vyžaduje novou obnovu do prázdné firmy.');
            }
            if ($previous !== false && $previous !== $digest) {
                throw new RuntimeException('Tento zdroj již byl importován s jinými daty. Opakovaný import nesmí přepsat existující doklady.');
            }
            if ($previous === false) {
                $this->assertEmptyTarget($pdo, $supplierId);
            }
            $writes = $this->files->prepare($tables, $package['assets'] ?? [], $supplierId, substr($sourceKey, 7, 16) . '-' . substr($digest, 0, 16));
            $reportFiles = count($writes);
            $map = ['supplier' => [$sourceSupplier => $supplierId]];
            $reused = [];
            $existing = [];
            $report = ['dry_run' => $dryRun, 'supplier_id' => $supplierId, 'source_supplier_id' => $sourceSupplier,
                'created' => [], 'reused' => [], 'existing' => [], 'outside_scope' => $package['skipped'],
                'files' => $reportFiles, 'reconciliation' => []];
            foreach (MyuctoImportProfile::GLOBAL_KEYS as $table => $keys) {
                foreach ($tables[$table] ?? [] as $id => $row) {
                    $map[$table][$id] = $this->lookup($pdo, $table, $keys, $row, null)
                        ?? throw new RuntimeException('Chybí shodný globální číselník: ' . $table . ':' . $id . '.');
                }
            }
            foreach (MyuctoImportProfile::TABLES as $table) {
                $rows = $tables[$table] ?? [];
                if ($rows === []) {
                    continue;
                }
                if (
                    ($schema['primaryKeys'][$table]['cols'] ?? []) !== ['id']
                    || ($schema['primaryKeys'][$table]['autoInc'] ?? null) !== 'id'
                ) {
                    throw new RuntimeException('Nepodporovaný primární klíč importního grafu: ' . $table . '.');
                }
                $next = (int) $pdo->query('SELECT id FROM `' . $table . '` ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
                foreach ($rows as $id => $row) {
                    if ($previous !== false) {
                        $stmt = $pdo->prepare("SELECT internal_id, entity_type FROM external_entity_map WHERE supplier_id = ? AND source_key = ? AND entity_type IN ('myucto_graph', 'myucto_reused') AND external_id = ?");
                        $stmt->execute([$supplierId, $sourceKey, $table . ':' . $id]);
                        $mapped = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($mapped === false) {
                            throw new RuntimeException('Chybí mapování již importovaného grafu.');
                        }
                        $map[$table][$id] = (int) $mapped['internal_id'];
                        if ($mapped['entity_type'] === 'myucto_reused') {
                            $reused[$table][$id] = true;
                        } else {
                            $existing[$table][$id] = true;
                        }
                    } else {
                        $keys = MyuctoImportProfile::REUSE_KEYS[$table] ?? null;
                        $mapped = $keys === null ? null : $this->lookup($pdo, $table, $keys, $row, $supplierId);
                        if ($mapped !== null) {
                            $map[$table][$id] = $mapped;
                            $reused[$table][$id] = true;
                        } else {
                            $map[$table][$id] = ++$next;
                        }
                    }
                }
            }
            $ready = ['supplier' => [$sourceSupplier => true], 'countries' => array_fill_keys(array_keys($map['countries'] ?? []), true),
                'vat_rates' => array_fill_keys(array_keys($map['vat_rates'] ?? []), true)];
            foreach ([$reused, $existing] as $group) {
                foreach ($group as $table => $ids) {
                    $ready[$table] = ($ready[$table] ?? []) + $ids;
                }
            }
            $pending = [];
            $prepared = [];
            $deferred = [];
            foreach (MyuctoImportProfile::TABLES as $table) {
                foreach ($tables[$table] ?? [] as $id => $row) {
                    $prepared[$table][$id] = $this->remap($table, $row, $map, $actorId, $schema);
                    if (!isset($ready[$table][$id])) {
                        $pending[$table][$id] = $row;
                    }
                }
            }
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $table => $rows) {
                    foreach ($rows as $id => $row) {
                        $write = $prepared[$table][$id];
                        $wait = false;
                        $late = [];
                        foreach ($this->references($table, $schema, $row) as $column => $reference) {
                            $sourceId = $row[$column] ?? null;
                            if ($sourceId === null || $reference === 'users') {
                                continue;
                            }
                            if (!isset($ready[$reference][MyuctoExportReader::id($sourceId)])) {
                                if (($schema['columns'][$table][$column]['IS_NULLABLE'] ?? '') === 'YES'
                                    && $table !== 'de_movement_classification') {
                                    $late[$column] = $write[$column];
                                    $write[$column] = null;
                                } else {
                                    $wait = true;
                                    break;
                                }
                            }
                        }
                        if ($wait) {
                            continue;
                        }
                        $columns = array_keys($write);
                        $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($write));
                        $this->recordMap($pdo, $supplierId, $sourceKey, $table, $id, $map[$table][$id]);
                        if ($late !== []) {
                            $deferred[] = [$table, $map[$table][$id], $late];
                        }
                        $ready[$table][$id] = true;
                        unset($pending[$table][$id]);
                        if ($pending[$table] === []) {
                            unset($pending[$table]);
                        }
                        $report['created'][$table] = ($report['created'][$table] ?? 0) + 1;
                        $progress = true;
                    }
                }
                if (!$progress) {
                    throw new RuntimeException('Importní graf má nepodporovaný cyklus povinných vazeb: ' . implode(', ', array_keys($pending)) . '.');
                }
            }
            foreach ($deferred as [$table, $id, $values]) {
                $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($values)));
                if (isset($schema['columns'][$table]['updated_at'])) {
                    $set .= ', updated_at = updated_at';
                }
                $pdo->prepare('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ?')->execute([...array_values($values), $id]);
            }
            foreach ($prepared as $table => $rows) {
                foreach ($rows as $id => $expected) {
                    $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE id = ?');
                    $stmt->execute([$map[$table][$id]]);
                    $actual = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($actual === false) {
                        throw new RuntimeException('Rekonciliace: cílový řádek chybí.');
                    }
                    if (isset($actual['supplier_id']) && (int) $actual['supplier_id'] !== $supplierId) {
                        throw new RuntimeException('Rekonciliace: cílový řádek patří jiné firmě.');
                    }
                    if (isset($reused[$table][$id])) {
                        $this->assertReusable($table, $expected, $actual);
                        $report['reused'][$table] = ($report['reused'][$table] ?? 0) + 1;
                        if ($previous === false) {
                            $this->recordMap($pdo, $supplierId, $sourceKey, $table, $id, $map[$table][$id], true);
                        }
                    } else {
                        foreach ($expected as $column => $value) {
                            // ON UPDATE timestamps may change when deferred references are attached.
                            if ($column === 'updated_at' && $table !== 'de_movement_classification') {
                                continue;
                            }
                            if (!$this->same($value, $actual[$column] ?? null, $schema['columns'][$table][$column]['DATA_TYPE'])) {
                                throw new RuntimeException('Rekonciliace: liší se ' . $table . '.' . $column . ' (zdrojové ID ' . $id . ').');
                            }
                        }
                        if (isset($existing[$table][$id])) {
                            $report['existing'][$table] = ($report['existing'][$table] ?? 0) + 1;
                        }
                    }
                    $report['reconciliation'][$table] = ($report['reconciliation'][$table] ?? 0) + 1;
                }
            }
            if ($previous === false) {
                $pdo->prepare("INSERT INTO external_entity_map (supplier_id, source_key, entity_type, external_id, internal_id) VALUES (?, ?, 'myucto_import', ?, ?)")->execute([$supplierId, $sourceKey, $digest, $supplierId]);
            }
            if ($previous === false) {
                $this->files->publish($writes);
            } else {
                foreach ($writes as $path => $asset) {
                    if (
                        !is_file($path)
                        || !hash_equals($asset['sha256'], (string) hash_file('sha256', $path))
                    ) {
                        throw new RuntimeException('Soubor již převzatého dokladu chybí nebo byl změněn.');
                    }
                }
            }
            if ($dryRun) {
                $pdo->rollBack();
                $this->files->rollback();
            } else {
                $pdo->commit();
                $this->files->commit();
            }
            return $report;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->files->rollback();
            throw $e;
        }
    }

    private function validateRows(array $tables, int $supplierId, array $schema): void
    {
        foreach (MyuctoImportProfile::TABLES as $table) {
            foreach ($tables[$table] ?? [] as $id => $row) {
                if (isset($row['supplier_id'])) {
                    if (MyuctoExportReader::id($row['supplier_id']) !== $supplierId) {
                        throw new RuntimeException('Export obsahuje data jiné firmy: ' . $table . '.');
                    }
                } else {
                    [$column, $parent] = MyuctoImportProfile::OWNERS[$table] ?? throw new RuntimeException('Graf nemá jednoznačného vlastníka: ' . $table . '.');
                    if (!isset($tables[$parent][MyuctoExportReader::id($row[$column] ?? null)])) {
                        throw new RuntimeException('Graf nemá zdrojového rodiče: ' . $table . '.');
                    }
                }
                if (array_diff_key($row, $schema['columns'][$table] ?? []) !== []) {
                    throw new RuntimeException('Cílová verze nezná sloupce exportu: ' . $table . '.');
                }
                if (
                    in_array($table, ['invoices', 'purchase_invoices', 'recurring_invoice_templates'], true)
                    && !array_key_exists('prices_include_vat', $row)
                ) {
                    throw new RuntimeException('Export neobsahuje cenový režim DPH: ' . $table . '.');
                }
                foreach ($row as $column => $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new RuntimeException('Neplatná hodnota řádku exportu.');
                    }
                    if ($value !== null && $column !== 'id' && (str_ends_with($column, '_id') || str_ends_with($column, '_by'))
                        && !isset($this->references($table, $schema, $row)[$column])
                        && !in_array($column, MyuctoImportProfile::PRESERVED_IDS, true)
                        && !in_array($column, ['source_id', 'doc_id'], true)) {
                        throw new RuntimeException('Nepodporovaná vazba ' . $table . '.' . $column . '; data se nebudou tiše zahazovat.');
                    }
                }
            }
        }
    }

    private function assertVatHistory(
        PDO $pdo,
        ?array $source,
        int $sourceSupplier,
        int $targetSupplier
    ): void
    {
        if ($source === null || $source === []) {
            throw new RuntimeException('Export postrádá historii plátcovství DPH; použijte aktuální úplný export.');
        }
        $sourceByDate = [];
        foreach ($source as $row) {
            if (MyuctoExportReader::id($row['supplier_id'] ?? null) !== $sourceSupplier
                || !is_string($row['effective_from'] ?? null) || isset($sourceByDate[$row['effective_from']])) {
                throw new RuntimeException('Neplatná historie plátcovství v exportu.');
            }
            $sourceByDate[$row['effective_from']] = $row;
        }
        $stmt = $pdo->prepare('SELECT * FROM supplier_vat_status_history WHERE supplier_id = ? ORDER BY effective_from FOR UPDATE');
        $stmt->execute([$targetSupplier]);
        $targetByDate = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'effective_from');
        ksort($sourceByDate);
        ksort($targetByDate);
        $dates = array_unique([...array_keys($sourceByDate), ...array_keys($targetByDate)]);
        sort($dates);
        $left = null;
        $right = null;
        foreach ($dates as $date) {
            $left = $sourceByDate[$date] ?? $left;
            $right = $targetByDate[$date] ?? $right;
            foreach (['is_vat_payer', 'is_identified', 'annual_deduction_percent'] as $column) {
                if (($left[$column] ?? null) !== null && ($right[$column] ?? null) !== null
                    && is_numeric($left[$column]) && is_numeric($right[$column]) && bccomp((string) $left[$column], (string) $right[$column], 6) === 0) {
                    continue;
                }
                if (($left[$column] ?? null) !== ($right[$column] ?? null)) {
                    throw new RuntimeException('Historie DPH cílové firmy se liší od exportu k ' . $date . '.');
                }
            }
        }
    }

    private function references(string $table, array $schema, array $row = []): array
    {
        $refs = MyuctoImportProfile::EXTRA_REFERENCES[$table] ?? [];
        if ($table === 'de_movement_classification_history') {
            $refs['source_id'] = MyuctoAccountingGraph::movementTable($row['source_type'] ?? null);
        }
        if (isset($schema['columns'][$table]['supplier_id'])) {
            $refs['supplier_id'] = 'supplier';
        }
        foreach ($schema['foreignKeyRows'] as $fk) {
            if ($fk['TABLE_NAME'] === $table) {
                if ($fk['REFERENCED_COLUMN_NAME'] !== 'id' && $fk['COLUMN_NAME'] !== 'supplier_id') {
                    throw new RuntimeException('Nepodporovaná reference schématu.');
                }
                $refs[$fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
            }
        }
        return $refs;
    }

    private function remap(string $table, array $row, array $map, int $actorId, array $schema): array
    {
        $out = $row;
        $out['id'] = $map[$table][MyuctoExportReader::id($row['id'])];
        foreach ($this->references($table, $schema, $row) as $column => $target) {
            if (!array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }
            $out[$column] = $target === 'users' ? $actorId : ($map[$target][MyuctoExportReader::id($row[$column])]
                ?? throw new RuntimeException('Chybí zdrojová vazba ' . $table . '.' . $column . ' → ' . $target . '.'));
        }
        if ($table === 'bank_transactions' && ($row['import_fingerprint'] ?? null) !== null) {
            // Portable identity remains unchanged; StatementImporter scopes fingerprints by tenant.
            $portable = $row['portable_fingerprint'] ?? $row['import_fingerprint'];
            $out['import_fingerprint'] = hash('sha256', 'supplier:' . reset($map['supplier']) . ':' . $portable);
        }
        if ($table === 'journal_entries' && ($row['source_id'] ?? null) !== null) {
            $target = match ($row['source_type']) {
                'invoice', 'provision' => 'invoices', 'purchase_invoice' => 'purchase_invoices', 'cash' => 'cash_documents',
                'bank' => 'bank_transactions', 'asset', 'asset_disposal' => 'assets', 'depreciation' => 'depreciation_entries',
                'offset' => 'offset_agreements', 'settlement' => 'invoice_settlements',
                'closing', 'opening', 'income_tax', 'deferred_tax', 'profit_distribution', 'stock' => 'accounting_periods',
                'manual' => null,
                default => throw new RuntimeException('Nepodporovaný zdroj účetního zápisu: ' . $row['source_type'] . '.'),
            };
            if ($target !== null) {
                $out['source_id'] = $map[$target][MyuctoExportReader::id($row['source_id'])]
                    ?? throw new RuntimeException('Chybí zdroj účetního zápisu; syntetická závěrková ID vyžadují další adaptér.');
            }
        }
        if (in_array($table, ['invoice_settlements', 'offset_agreement_items'], true)) {
            $target = MyuctoImportProfile::DOCUMENT_TYPES[$row['doc_type']] ?? throw new RuntimeException('Nepodporovaný typ vypořádávaného dokladu.');
            $out['doc_id'] = $map[$target][MyuctoExportReader::id($row['doc_id'])] ?? throw new RuntimeException('Chybí vypořádávaný doklad.');
        }
        if ($table === 'invoices' && ($row['supplier_snapshot'] ?? null) !== null) {
            $snapshot = json_decode((string) $row['supplier_snapshot'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || array_is_list($snapshot)) {
                throw new RuntimeException('Neplatný snapshot dodavatele.');
            }
            if (isset($snapshot['id'])) {
                $snapshot['id'] = $map['supplier'][MyuctoExportReader::id($snapshot['id'])] ?? throw new RuntimeException('Snapshot patří jiné firmě.');
            }
            if (!empty($snapshot['email_profile_id'])) {
                throw new RuntimeException('Snapshot používá e-mailový profil, který je mimo tento importní graf.');
            }
            $out['supplier_snapshot'] = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        foreach (MyuctoImportProfile::RESET_COLUMNS[$table] ?? [] as $column) {
            if (isset($schema['columns'][$table][$column])) {
                $out[$column] = null;
            }
        }
        if ($table === 'invoices') {
            $out['auto_send_reminders'] = 0;
            if (($row['approval_status'] ?? null) === 'requested') {
                $out['approval_status'] = 'none';
                $out['approval_requested_at'] = null;
                $out['approval_reminder_at'] = null;
                $out['approval_reminder_count'] = 0;
            }
        }
        if ($table === 'recurring_invoice_templates') {
            $out['status'] = 'paused';
            $out['auto_issue'] = 0;
            $out['auto_send_email'] = 0;
        }
        if ($table === 'currencies' || $table === 'cash_registers') {
            $out['is_default'] = 0;
        }
        foreach ($out as $column => $_) {
            if (($schema['columns'][$table][$column]['GENERATION_EXPRESSION'] ?? '') !== '') {
                unset($out[$column]);
            }
        }
        return $out;
    }

    private function assertEmptyTarget(PDO $pdo, int $supplierId): void
    {
        $scopes = $this->scopes->resolveAll($supplierId);
        foreach (MyuctoImportProfile::TABLES as $table) {
            if (isset(MyuctoImportProfile::REUSE_KEYS[$table])) {
                continue;
            }
            $scope = $scopes[$table] ?? throw new RuntimeException('Nelze ověřit vlastnictví cílového grafu ' . $table . '.');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $scope->where);
            $stmt->execute($scope->params);
            if ((int) $stmt->fetchColumn() !== 0) {
                throw new RuntimeException('Cílová firma už obsahuje vlastní data: ' . $table . '.');
            }
        }
    }

    private function lookup(PDO $pdo, string $table, array $keys, array $row, ?int $supplierId): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new RuntimeException('Chybí přirozený klíč číselníku ' . $table . '.' . $key . '.');
            }
        }
        $where = implode(' AND ', array_map(static fn (string $k): string => '`' . $k . '` <=> ?', $keys));
        $values = array_map(static fn (string $k): mixed => $row[$k], $keys);
        if ($supplierId !== null) {
            $where .= ' AND supplier_id = ?';
            $values[] = $supplierId;
        }
        $stmt = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE ' . $where . ' FOR UPDATE');
        $stmt->execute($values);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) {
            throw new RuntimeException('Nejednoznačné mapování číselníku ' . $table . '.');
        }
        return $ids === [] ? null : (int) $ids[0];
    }

    private function assertReusable(string $table, array $expected, array $actual): void
    {
        $fields = match ($table) {
            'currencies' => ['code', 'decimals'], 'chart_of_accounts' => ['account_code', 'account_type', 'normal_side', 'is_synthetic', 'tax_deductibility', 'is_clearing'],
            'vat_classifications' => ['code', 'direction', 'dphdp3_line', 'dphdp3_line_secondary', 'kh_section', 'vat_rate', 'is_reverse_charge', 'kod_pred_pl', 'kh_regime_code', 'kh_bad_debt', 'archived'],
            'cash_registers' => ['currency_code', 'account_code'], 'supplier_bank_accounts' => ['account_number', 'bank_code', 'currency'],
            'posting_rules' => ['rule_key', 'priority', 'description', 'debit_account_code', 'credit_account_code', 'is_active'],
            default => ['code', 'label'],
        };
        foreach ($fields as $field) {
            if ($table === 'posting_rules') {
                $type = in_array($field, ['priority', 'is_active'], true) ? 'int' : 'varchar';
                if (!array_key_exists($field, $expected)
                    || !$this->same($expected[$field], $actual[$field] ?? null, $type)) {
                    throw new RuntimeException('Cílový číselník má jiný význam: ' . $table . '.' . $field . '.');
                }
                continue;
            }
            if (
                array_key_exists($field, $expected)
                && (string) $expected[$field] !== (string) ($actual[$field] ?? null)
            ) {
                throw new RuntimeException('Cílový číselník má jiný význam: ' . $table . '.' . $field . '.');
            }
        }
    }

    private function same(mixed $expected, mixed $actual, string $type): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }
        if (in_array($type, ['decimal', 'tinyint', 'smallint', 'int', 'bigint', 'float', 'double'], true)) {
            return is_numeric($expected) && is_numeric($actual) && bccomp((string) $expected, (string) $actual, 12) === 0;
        }
        return (string) $expected === (string) $actual;
    }

    private function recordMap(
        PDO $pdo,
        int $supplierId,
        string $sourceKey,
        string $table,
        int $sourceId,
        int $targetId,
        bool $reused = false
    ): void
    {
        $pdo->prepare("INSERT INTO external_entity_map (supplier_id, source_key, entity_type, external_id, internal_id) VALUES (?, ?, ?, ?, ?)")->execute([$supplierId, $sourceKey, $reused ? 'myucto_reused' : 'myucto_graph', $table . ':' . $sourceId, $targetId]);
    }
}
