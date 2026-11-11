<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Myucto;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\SchemaMetadataProvider;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\AccountingModeRepository;
use MyInvoice\Security\FrameworkScopePolicy;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\Accounting\UnusedAccountingPeriods;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\Instance\TenantScopeResolver;
use PDO;
use RuntimeException;

/** Atomic graph import into an existing tenant; stored money is never recalculated. */
final class MyuctoImporter
{
    private readonly UnusedAccountingPeriods $unusedPeriods;
    private readonly ActivityLogger $activity;
    /** @var array<string,array<string,string>> vazby podle cizích klíčů schématu, po tabulkách */
    private array $fkReferences = [];
    /** @var array<string,int> vynechané vazby uvnitř JSON hodnot (vazba konceptu interního dokladu) */
    private array $droppedLinks = [];

    public function __construct(
        private readonly Connection $db,
        private readonly TenantScopeResolver $scopes,
        private readonly MyuctoImportFiles $files,
        ?UnusedAccountingPeriods $unusedPeriods = null,
        ?ActivityLogger $activity = null
    )
    {
        $this->unusedPeriods = $unusedPeriods ?? new UnusedAccountingPeriods($db);
        $this->activity = $activity ?? new ActivityLogger($db);
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
        // Export bez formy zápisu (starší verze), bez dokumentů, dimenzí a dalších později
        // přidaných tabulek a export firmy, která je nemá vyplněné, mají stejný otisk dat.
        foreach ([MyuctoImportProfile::ENTRY_FORM_HISTORY_TABLE, ...MyuctoImportProfile::KEYLESS_JOURNAL_TABLES,
            ...MyuctoImportProfile::OPTIONAL_GRAPH_TABLES, ...array_keys(MyuctoImportProfile::KEYLESS_TABLES)] as $table) {
            if (($tables[$table] ?? null) === []) {
                unset($tables[$table]);
            }
        }
        // Preserve the source history order, including entries with identical timestamps.
        if (isset($tables['de_movement_classification_history'])) {
            ksort($tables['de_movement_classification_history'], SORT_NUMERIC);
        }
        $sourceSupplier = MyuctoExportReader::id($package['manifest']['supplier']['id']);
        $root = $tables['supplier'][$sourceSupplier];
        $stored = $package['stored_files'] ?? [];
        $sourceKey = 'myucto:' . hash('sha256', $source . ':' . $sourceSupplier);
        $digestParts = [$tables, $package['assets'] ?? []];
        $shared = $package['shared_dimensions'] ?? [];
        if ($shared !== []) {
            $digestParts['shared_dimensions'] = $shared;
        }
        if ($stored !== []) {
            $digestParts[] = array_map(static fn (array $file): array => [$file['area'], $file['path'], $file['sha256']], $stored);
        }
        $digest = MyuctoImportProfile::VERSION . ':' . hash('sha256', serialize($digestParts));
        $schema = SchemaMetadataProvider::load($pdo);
        $this->fkReferences = [];
        $this->droppedLinks = [];
        [$dropped, $softDetached] = $this->pruneOutsideGraph($tables);
        $this->validateRows($tables, $sourceSupplier, $schema);
        $this->validateKeylessRows($tables, $sourceSupplier, $schema);
        MyuctoAccountingGraph::validate($tables);
        $entryFormHistory = $this->assertExportEntryForm($tables, $sourceSupplier, $schema);
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
            $this->assertAccountingFramework($tables, $package['context']['supplier_accounting_frameworks'] ?? null, $sourceSupplier, $supplierId);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1');
            $stmt->execute([$actorId]);
            if ($stmt->fetchColumn() === false) {
                throw new RuntimeException('Aktér importu musí být existující aktivní uživatel.');
            }
            $stmt = $pdo->prepare("SELECT external_id FROM external_entity_map WHERE supplier_id = ? AND source_key = ? AND entity_type = 'myucto_import'");
            $stmt->execute([$supplierId, $sourceKey]);
            $previous = $stmt->fetchColumn();
            if ($previous !== false && MyuctoImportProfile::isEarlierRestoreOfSameData((string) $previous, $digest)) {
                // Shodná data obnovená starším profilem se ověří jako opakovaný import.
                $previous = $digest;
            }
            if ($previous !== false && !str_starts_with($previous, MyuctoImportProfile::VERSION . ':')) {
                throw new RuntimeException('Firma již byla obnovena starším importním profilem. Doplnění převáděných účetních dat vyžaduje novou obnovu do prázdné firmy.');
            }
            if ($previous !== false && $previous !== $digest) {
                throw new RuntimeException('Tento zdroj již byl importován s jinými daty. Opakovaný import nesmí přepsat existující doklady.');
            }
            $this->restoreEntryForm($pdo, $entryFormHistory, $supplierId, $actorId, $previous === false);
            $replacedPeriods = [];
            $replacedSetup = [];
            if ($previous === false) {
                $replacedPeriods = $this->replaceUnusedPeriods($pdo, $supplierId, ($tables['accounting_periods'] ?? []) !== []);
                $replacedSetup = $this->replaceSetupDefaults($pdo, $tables, $supplierId);
                $this->assertEmptyTarget($pdo, $tables, $supplierId, $replacedPeriods !== null);
            }
            $writes = $this->files->prepare($tables, $package['assets'] ?? [], $supplierId, substr($sourceKey, 7, 16) . '-' . substr($digest, 0, 16));
            $writes += $this->files->prepareStored($stored, $supplierId);
            $reportFiles = count($writes);
            $map = ['supplier' => [$sourceSupplier => $supplierId]];
            $reused = [];
            $existing = [];
            $detached = self::detachedReferences($tables);
            foreach ($softDetached as $column => $count) {
                $detached[$column] = ($detached[$column] ?? 0) + $count;
            }
            ksort($detached);
            $report = ['dry_run' => $dryRun, 'supplier_id' => $supplierId, 'source_supplier_id' => $sourceSupplier,
                'created' => [], 'reused' => [], 'existing' => [], 'replaced_periods' => $replacedPeriods ?? [], 'outside_scope' => $package['skipped'],
                'files' => $reportFiles, 'reconciliation' => [], 'detached' => $detached,
                'replaced_setup' => $replacedSetup, 'dropped' => $dropped, 'missing_files' => $package['missing_files'] ?? [],
                'numbering_check' => self::numberingCheck($tables)];
            foreach (MyuctoImportProfile::GLOBAL_KEYS as $table => $keys) {
                foreach ($tables[$table] ?? [] as $id => $row) {
                    $map[$table][$id] = $this->lookup($pdo, $table, $keys, $row, null)
                        ?? throw new RuntimeException('Chybí shodný globální číselník: ' . $table . ':' . $id . '.');
                }
            }
            $sharedMap = $this->mapSharedDimensions($pdo, $shared, $target['supplier_group_id'] === null ? null : (int) $target['supplier_group_id']);
            foreach ($sharedMap as $table => $ids) {
                foreach ($ids as $sourceId => $targetId) {
                    $map[$table][$sourceId] = $targetId;
                }
                $report['shared_dimensions'][$table] = count($ids);
            }
            $previousMap = [];
            if ($previous !== false) {
                // Mapování celé předchozí obnovy jedním dotazem, ne po řádcích.
                $stmt = $pdo->prepare("SELECT external_id, internal_id, entity_type FROM external_entity_map WHERE supplier_id = ? AND source_key = ? AND entity_type IN ('myucto_graph', 'myucto_reused')");
                $stmt->execute([$supplierId, $sourceKey]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mapped) {
                    $previousMap[(string) $mapped['external_id']] = $mapped;
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
                        $mapped = $previousMap[$table . ':' . $id] ?? throw new RuntimeException('Chybí mapování již importovaného grafu.');
                        $map[$table][$id] = (int) $mapped['internal_id'];
                        if ($mapped['entity_type'] === 'myucto_reused') {
                            $reused[$table][$id] = true;
                        } else {
                            $existing[$table][$id] = true;
                        }
                    } else {
                        $keys = MyuctoImportProfile::REUSE_KEYS[$table] ?? null;
                        $lookupRow = $keys === null ? null : $this->reuseLookupRow($table, $row, $map, $schema);
                        $mapped = $lookupRow === null ? null : $this->lookup($pdo, $table, $keys, $lookupRow, $supplierId);
                        if ($mapped !== null) {
                            $map[$table][$id] = $mapped;
                            $reused[$table][$id] = true;
                        } else {
                            $map[$table][$id] = ++$next;
                        }
                    }
                }
            }
            if ($previous === false) {
                $this->assertNoExtraTargetPostingRules($pdo, $supplierId, array_keys($reused['posting_rules'] ?? []), $map);
            }
            $ready = ['supplier' => [$sourceSupplier => true], 'countries' => array_fill_keys(array_keys($map['countries'] ?? []), true),
                'vat_rates' => array_fill_keys(array_keys($map['vat_rates'] ?? []), true)];
            foreach ([$reused, $existing] as $group) {
                foreach ($group as $table => $ids) {
                    $ready[$table] = ($ready[$table] ?? []) + $ids;
                }
            }
            foreach ($sharedMap as $table => $ids) {
                $ready[$table] = ($ready[$table] ?? []) + array_fill_keys(array_keys($ids), true);
            }
            $pending = [];
            $prepared = [];
            $restoredEntries = [];
            $deferred = [];
            foreach (MyuctoImportProfile::TABLES as $table) {
                foreach ($tables[$table] ?? [] as $id => $row) {
                    $prepared[$table][$id] = $this->remap($table, $row, $map, $actorId, $schema);
                    if (!isset($ready[$table][$id])) {
                        $pending[$table][$id] = $row;
                    }
                }
            }
            foreach ($this->droppedLinks as $key => $count) {
                $report['dropped'][$key] = ($report['dropped'][$key] ?? 0) + $count;
            }
            if ($previous === false) {
                $this->adoptSetupRows($pdo, $reused, $prepared, $map, $schema);
            }
            $report['adopted_chart'] = $this->reconcileChart($pdo, $reused, $prepared, $map, $previous === false);
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
                        // Stornovaný zápis, jehož storno ještě není vložené, by byl do doplnění
                        // `reversed_by` aktivní a kolidoval by s novým zaúčtováním téhož dokladu
                        // (uq_je_supplier_active_source). Vazbu na doklad proto dostane až spolu se stornem.
                        if ($table === 'journal_entries' && array_key_exists('reversed_by', $late) && ($write['source_id'] ?? null) !== null) {
                            $late['source_id'] = $write['source_id'];
                            $write['source_id'] = null;
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
                        if ($table === 'journal_entries' && ($write['posted_at'] ?? null) !== null) {
                            $restoredEntries[$map[$table][$id]] = $id;
                        }
                        $progress = true;
                    }
                }
                if (!$progress) {
                    throw new RuntimeException('Importní graf má nepodporovaný cyklus povinných vazeb: ' . implode(', ', array_keys($pending)) . '.');
                }
            }
            // § 33a: převzatý zaúčtovaný zápis potřebuje auditní stopu stejně jako zápis zaúčtovaný v MyÚčtu (invariant I28).
            foreach ($restoredEntries as $entryId => $sourceEntryId) {
                $this->activity->log('accounting.posted', $actorId, 'journal_entry', (int) $entryId,
                    ['source' => 'myucto_import', 'source_entry_id' => (int) $sourceEntryId], null, null, $supplierId);
            }
            $this->restoreKeylessJournal($pdo, $tables, $map, $supplierId, $actorId, $previous === false, $schema, $report);
            $this->restoreKeylessTables($pdo, $tables, $map, $supplierId, $previous === false, $schema, $report);
            if ($previous === false && ($package['manifest']['origin']['type'] ?? null) !== 'myinvoice-backup') {
                $report['numbering_adopted'] = $this->adoptSupplierNumbering($pdo, $root, $target, $supplierId, $schema);
            }
            foreach ($deferred as [$table, $id, $values]) {
                $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($values)));
                foreach (['updated_at', 'computed_at'] as $stamp) {
                    if (isset($schema['columns'][$table][$stamp]) && !isset($values[$stamp])) {
                        $set .= ', `' . $stamp . '` = `' . $stamp . '`';
                    }
                }
                $pdo->prepare('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ?')->execute([...array_values($values), $id]);
            }
            foreach ($prepared as $table => $rows) {
                $actualRows = [];
                foreach (array_chunk(array_map(static fn (int|string $id): int => $map[$table][$id], array_keys($rows)), 500) as $chunk) {
                    $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')');
                    $stmt->execute($chunk);
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $actualRows[(int) $row['id']] = $row;
                    }
                }
                foreach ($rows as $id => $expected) {
                    $actual = $actualRows[$map[$table][$id]] ?? false;
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
            foreach (['detached', 'dropped', 'replaced_setup'] as $section) {
                ksort($report[$section]);
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
        } finally {
            EntryFormService::forgetShared($supplierId);
        }
    }

    /**
     * Kolik měkkých vazeb na nastavení mimo export obnova odpojí, po sloupcích.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @return array<string,int> `tabulka.sloupec` => počet řádků
     */
    private static function detachedReferences(array $tables): array
    {
        $counts = [];
        foreach (MyuctoImportProfile::DETACHED_REFERENCES as $table => $columns) {
            foreach ($tables[$table] ?? [] as $row) {
                foreach ($columns as $column) {
                    if (($row[$column] ?? null) !== null) {
                        $counts[$table . '.' . $column] = ($counts[$table . '.' . $column] ?? 0) + 1;
                    }
                }
            }
        }
        ksort($counts);

        return $counts;
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
                        && !in_array($column, MyuctoImportProfile::POLYMORPHIC_REFERENCES[$table] ?? [], true)
                        && !in_array($column, MyuctoImportProfile::PERIOD_KEYS[$table] ?? [], true)
                        && !in_array($column, MyuctoImportProfile::DETACHED_REFERENCES[$table] ?? [], true)) {
                        throw new RuntimeException('Nepodporovaná vazba ' . $table . '.' . $column . '; data se nebudou tiše zahazovat.');
                    }
                }
            }
        }
    }

    /**
     * Forma účetního zápisu z exportu musí sedět na převáděný deník: v období jednoduchého
     * zápisu má každý zápis řádky po dvojicích, nebo posouzení bez dvojic, ve složeném
     * žádný řádek číslo dvojice nemá. Obnova přenáší řádky beze změny a invariant dvojic
     * za ni nikdo jiný nehlídá, proto nesoulad odmítne celý, dřív než cokoli zapíše.
     * Export bez historie formy (starší verze, firma bez volby) je celý ve složeném zápisu.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @return list<array<string,mixed>> historie formy zdroje vzestupně podle valid_from
     */
    private function assertExportEntryForm(array $tables, int $sourceSupplier, array $schema): array
    {
        $history = [];
        $dates = [];
        foreach ($tables[MyuctoImportProfile::ENTRY_FORM_HISTORY_TABLE] ?? [] as $row) {
            $from = $row['valid_from'] ?? null;
            if (MyuctoExportReader::id($row['supplier_id'] ?? null) !== $sourceSupplier
                || !is_string($from) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $from) || isset($dates[$from])
                || EntryForm::tryFrom((string) ($row['entry_form'] ?? '')) === null
                || array_diff_key($row, $schema['columns'][MyuctoImportProfile::ENTRY_FORM_HISTORY_TABLE] ?? []) !== []) {
                throw new RuntimeException('Neplatná historie formy účetního zápisu v exportu.');
            }
            $dates[$from] = true;
            $history[] = $row;
        }
        usort($history, static fn (array $a, array $b): int => strcmp($a['valid_from'], $b['valid_from']));
        $entries = $tables['journal_entries'] ?? [];
        foreach (MyuctoImportProfile::KEYLESS_JOURNAL_TABLES as $table) {
            foreach ($tables[$table] ?? [] as $row) {
                if (MyuctoExportReader::id($row['supplier_id'] ?? null) !== $sourceSupplier
                    || array_diff_key($row, $schema['columns'][$table] ?? []) !== []) {
                    throw new RuntimeException('Export obsahuje neplatný řádek ' . $table . '.');
                }
                foreach (MyuctoImportProfile::KEYLESS_ENTRY_REFERENCES[$table] as $column) {
                    if (!isset($entries[MyuctoExportReader::id($row[$column] ?? null)])) {
                        throw new RuntimeException('Graf nemá zdrojový zápis deníku: ' . $table . '.' . $column . '.');
                    }
                }
            }
        }
        $assessed = [];
        foreach ($tables['journal_entry_pair_status'] ?? [] as $row) {
            $assessed[MyuctoExportReader::id($row['entry_id'])] = true;
        }
        $paired = [];
        $unpaired = [];
        foreach ($tables['journal_entry_lines'] ?? [] as $line) {
            $entryId = MyuctoExportReader::id($line['entry_id'] ?? null);
            if (($line['pair_no'] ?? null) !== null && $line['pair_no'] !== '') {
                $paired[$entryId] = true;
            } else {
                $unpaired[$entryId] = true;
            }
        }
        $single = 0;
        $composite = 0;
        foreach ($entries as $id => $entry) {
            $isSingle = $history !== [] && EntryFormService::formFromHistory($history, (string) ($entry['entry_date'] ?? ''))->isSingle();
            if ($isSingle && isset($unpaired[(int) $id]) && !isset($assessed[(int) $id])) {
                $single++;
            } elseif (!$isSingle && isset($paired[(int) $id])) {
                $composite++;
            }
        }
        if ($single > 0) {
            throw new RuntimeException(sprintf(
                'Export není úplný: %d zápisů deníku v období jednoduchého zápisu nemá dvojice ani posouzení zápisu bez dvojic.',
                $single,
            ));
        }
        if ($composite > 0) {
            throw new RuntimeException(sprintf(
                'Export není úplný: %d zápisů deníku je po dvojicích, ale export pro jejich datum nenese jednoduchý zápis (historii formy zápisu).',
                $composite,
            ));
        }
        return $history;
    }

    /**
     * Obnova zachovává formu účetního zápisu zdroje, výchozí formou instalace se neřídí.
     * Firmě, do které se obnovuje poprvé a která nemá zápis v deníku ani vlastní volbu
     * formy (nanejvýš výchozí formu instalace z založení), nahradí historii historií zdroje.
     * Jinak (opakovaná obnova, firma s vlastní volbou) musí forma ke každému datu sedět;
     * skutečný rozpor obnovu odmítne dřív, než cokoli zapíše.
     *
     * @param list<array<string,mixed>> $source historie formy z exportu
     */
    private function restoreEntryForm(PDO $pdo, array $source, int $supplierId, int $actorId, bool $firstRestore): void
    {
        $stmt = $pdo->prepare('SELECT valid_from, entry_form, note FROM accounting_entry_form_history WHERE supplier_id = ? ORDER BY valid_from FOR UPDATE');
        $stmt->execute([$supplierId]);
        $target = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $journal = $pdo->prepare('SELECT 1 FROM journal_entries WHERE supplier_id = ? LIMIT 1');
        $journal->execute([$supplierId]);
        $replaceable = $firstRestore && $journal->fetchColumn() === false
            && array_filter($target, static fn (array $row): bool => !EntryFormService::isInstallationDefaultRow($row)) === [];
        if ($replaceable) {
            (new EntryFormService($this->db, $this->activity, new AccountingModeRepository($this->db), new FrameworkScopePolicy($this->db)))
                ->restoreHistory($supplierId, $source, $actorId, ['source' => 'myucto_import']);
            return;
        }
        $expected = EntryFormService::effectiveChanges($source);
        $actual = EntryFormService::effectiveChanges($target);
        if ($expected === $actual) {
            return;
        }
        $source = $this->sortedHistory($source);
        $dates = array_unique([...array_column($expected, 0), ...array_column($actual, 0)]);
        sort($dates);
        foreach ($dates as $date) {
            $exported = EntryFormService::formFromHistory($source, $date);
            $current = EntryFormService::formFromHistory($target, $date);
            if ($exported !== $current) {
                break;
            }
        }
        $label = static fn (EntryForm $form): string => $form->isSingle() ? 'jednoduchým zápisem (po dvojicích)' : 'složeným zápisem';
        throw new RuntimeException(sprintf(
            'Forma účetního zápisu cílové firmy se liší od exportu k %s: export účtuje %s, cílová firma %s. '
            . 'Obnova formu zdroje zachovává; obnovte firmu do nové firmy bez vlastní volby formy zápisu.',
            $date,
            $label($exported),
            $label($current),
        ));
    }

    /** @param list<array<string,mixed>> $history */
    private function sortedHistory(array $history): array
    {
        usort($history, static fn (array $a, array $b): int => strcmp((string) $a['valid_from'], (string) $b['valid_from']));
        return $history;
    }

    /**
     * Posouzení zápisů bez dvojic a storna dvojic patří k deníku a nemají vlastní `id`:
     * po zapsání zápisů se přemapují na nové zápisy a ověří. Vazba na běh převodu
     * (`run_id`) se nepřenáší, běhy převodu jsou provozní historie zdrojové instalace.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @param array<string,array<int|string,int>> $map
     */
    private function restoreKeylessJournal(PDO $pdo, array $tables, array $map, int $supplierId, int $actorId, bool $firstRestore, array $schema, array &$report): void
    {
        foreach (MyuctoImportProfile::KEYLESS_JOURNAL_TABLES as $table) {
            $key = $table === 'journal_entry_pair_status' ? ['supplier_id', 'entry_id'] : ['supplier_id', 'entry_id', 'pair_no'];
            foreach ($tables[$table] ?? [] as $row) {
                $out = $row;
                $out['supplier_id'] = $supplierId;
                foreach (MyuctoImportProfile::KEYLESS_ENTRY_REFERENCES[$table] as $column) {
                    $out[$column] = $map['journal_entries'][MyuctoExportReader::id($row[$column])]
                        ?? throw new RuntimeException('Chybí zdrojová vazba ' . $table . '.' . $column . ' → journal_entries.');
                }
                if (array_key_exists('run_id', $out)) {
                    $out['run_id'] = null;
                }
                if (($out['created_by'] ?? null) !== null) {
                    $out['created_by'] = $actorId;
                }
                $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE ' . implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', $key)) . ' FOR UPDATE');
                $params = array_map(static fn (string $c): mixed => $out[$c], $key);
                $stmt->execute($params);
                $actual = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($actual === false) {
                    if (!$firstRestore) {
                        throw new RuntimeException('Rekonciliace: cílový řádek chybí (' . $table . ').');
                    }
                    $columns = array_keys($out);
                    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
                        ->execute(array_values($out));
                    $report['created'][$table] = ($report['created'][$table] ?? 0) + 1;
                    $stmt->execute($params);
                    $actual = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif ($firstRestore) {
                    throw new RuntimeException('Cílová firma už obsahuje vlastní data: ' . $table . '.');
                } else {
                    $report['existing'][$table] = ($report['existing'][$table] ?? 0) + 1;
                }
                foreach ($out as $column => $value) {
                    if (!$this->same($value, $actual[$column] ?? null, $schema['columns'][$table][$column]['DATA_TYPE'])) {
                        throw new RuntimeException('Rekonciliace: liší se ' . $table . '.' . $column . '.');
                    }
                }
                $report['reconciliation'][$table] = ($report['reconciliation'][$table] ?? 0) + 1;
            }
        }
    }

    /**
     * Sdílené dimenze skupiny firem, na které data firmy odkazují. Obnova je nezakládá
     * (patří skupině, ne firmě, a druhá kopie by rozdělila přehledy skupiny), ale dohledá
     * je ve skupině cílové firmy podle kódu typu a kódu hodnoty. Ve stejné instanci a skupině
     * tak zůstanou původní ID, v jiné instanci se přemapují na dimenze, které skupina vede.
     * Chybí-li cílové firmě skupina, typ nebo hodnota, obnova skončí s výpisem rozdílů dřív,
     * než cokoli zapíše: přiřazení by jinak tiše zmizela nebo mířila na cizí hodnotu.
     *
     * @param array<string,array<int,array<string,mixed>>> $shared
     * @return array<string,array<int,int>> tabulka => zdrojové ID => cílové ID
     */
    private function mapSharedDimensions(PDO $pdo, array $shared, ?int $group): array
    {
        $types = $shared['dimension_types'] ?? [];
        if ($types === []) {
            return [];
        }
        $problems = [];
        $out = ['dimension_types' => [], 'dimension_values' => []];
        $targetTypes = [];
        if ($group !== null) {
            $stmt = $pdo->prepare('SELECT id, code, kind FROM dimension_types WHERE supplier_id IS NULL AND supplier_group_id = ?');
            $stmt->execute([$group]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $targetTypes[mb_strtolower((string) $row['code'])] = $row;
            }
        }
        foreach ($types as $id => $row) {
            $match = $targetTypes[mb_strtolower((string) $row['code'])] ?? null;
            if ($match === null) {
                $problems[] = 'typ „' . $row['code'] . '" (' . $row['name'] . ')';
            } elseif ((string) $match['kind'] !== (string) ($row['kind'] ?? $match['kind'])) {
                $problems[] = 'typ „' . $row['code'] . '" má ve skupině jiný druh (' . $match['kind'] . ')';
            } else {
                $out['dimension_types'][$id] = (int) $match['id'];
            }
        }
        $values = $shared['dimension_values'] ?? [];
        $targetValues = [];
        if ($values !== [] && $out['dimension_types'] !== []) {
            $typeIds = array_values(array_unique($out['dimension_types']));
            $stmt = $pdo->prepare('SELECT id, type_id, code FROM dimension_values WHERE supplier_id IS NULL AND supplier_group_id = ? AND type_id IN ('
                . implode(', ', array_fill(0, count($typeIds), '?')) . ')');
            $stmt->execute([$group, ...$typeIds]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $targetValues[(int) $row['type_id'] . "\x1f" . mb_strtolower((string) $row['code'])] = (int) $row['id'];
            }
        }
        foreach ($values as $id => $row) {
            $type = $out['dimension_types'][MyuctoExportReader::id($row['type_id'] ?? null)] ?? null;
            $match = $type === null ? null : ($targetValues[$type . "\x1f" . mb_strtolower((string) $row['code'])] ?? null);
            if ($match === null) {
                $problems[] = 'hodnota „' . $row['code'] . '" (' . $row['name'] . ') typu „' . $types[MyuctoExportReader::id($row['type_id'])]['code'] . '"';
            } else {
                $out['dimension_values'][$id] = $match;
            }
        }
        if ($problems !== []) {
            $count = count($problems);
            throw new RuntimeException(($group === null
                ? 'Data firmy používají sdílené dimenze skupiny firem, ale cílová firma do žádné skupiny nepatří.'
                : 'Skupina cílové firmy nemá sdílené dimenze, na které data firmy odkazují.')
                . ' Chybí (' . $count . '): ' . implode('; ', array_slice($problems, 0, 20)) . ($count > 20 ? '; …' : '')
                . '. Zařaďte cílovou firmu do skupiny firem, která tyto typy a hodnoty dimenzí vede (párují se podle kódu), a obnovu opakujte. Obnova nic nezměnila.');
        }
        return array_filter($out);
    }

    /**
     * Řádky bez vlastního `id` mimo deník ({@see MyuctoImportProfile::KEYLESS_TABLES}).
     * Cíl se načte po tabulkách jedním dotazem a porovná v paměti; při první obnově se
     * chybějící řádky vloží, při opakované musí všechny sedět. Počítadlo číselné řady,
     * které cíl už má, se jen zvedne na hodnotu zdroje (nikdy nesníží) a při opakované
     * obnově smí být výš, protože firma mezitím vydala další doklady.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @param array<string,array<int|string,int>> $map
     */
    private function restoreKeylessTables(PDO $pdo, array $tables, array $map, int $supplierId, bool $firstRestore, array $schema, array &$report): void
    {
        foreach (MyuctoImportProfile::KEYLESS_TABLES as $table => $definition) {
            $rows = $tables[$table] ?? [];
            if ($rows === []) {
                continue;
            }
            $columns = $schema['columns'][$table] ?? throw new RuntimeException('Cílová verze nezná tabulku exportu: ' . $table . '.');
            $counters = $definition['counter'] ?? [];
            $keyOf = static fn (array $row): string => implode("\x1f", array_map(static fn (string $c): string => (string) $row[$c], $definition['key']));
            $load = function () use ($pdo, $table, $columns, $supplierId, $keyOf): array {
                if (isset($columns['supplier_id'])) {
                    $stmt = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE supplier_id = ? FOR UPDATE');
                } else {
                    $stmt = $pdo->prepare('SELECT c.* FROM `' . $table . '` c JOIN number_series n ON n.id = c.series_id WHERE n.supplier_id = ? FOR UPDATE');
                }
                $stmt->execute([$supplierId]);
                $out = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[$keyOf($row)] = $row;
                }
                return $out;
            };
            $actual = $load();
            $expected = [];
            $inserts = [];
            foreach ($rows as $row) {
                $out = $this->remapKeyless($table, $definition, $row, $map, $supplierId, $columns, $report);
                if ($out === null) {
                    continue;
                }
                $key = $keyOf($out);
                if (isset($expected[$key])) {
                    throw new RuntimeException('Export obsahuje duplicitní řádek ' . $table . '.');
                }
                $expected[$key] = $out;
                if (!isset($actual[$key])) {
                    if (!$firstRestore) {
                        throw new RuntimeException('Rekonciliace: cílový řádek chybí (' . $table . ').');
                    }
                    // Přiřazení dimenzí jdou do statisíců řádků: vkládá se po dávkách, ne po řádku.
                    $inserts[implode("\x1f", array_keys($out))][] = $out;
                    $report['created'][$table] = ($report['created'][$table] ?? 0) + 1;
                } elseif ($firstRestore && $counters === []) {
                    throw new RuntimeException('Cílová firma už obsahuje vlastní data: ' . $table . '.');
                } elseif ($firstRestore) {
                    $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = GREATEST(`' . $c . '`, ?)', $counters));
                    $where = implode(' AND ', array_map(static fn (string $c): string => '`' . $c . '` = ?', $definition['key']));
                    $pdo->prepare('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $where)->execute([
                        ...array_map(static fn (string $c): mixed => $out[$c], $counters),
                        ...array_map(static fn (string $c): mixed => $out[$c], $definition['key']),
                    ]);
                    $report['existing'][$table] = ($report['existing'][$table] ?? 0) + 1;
                } else {
                    $report['existing'][$table] = ($report['existing'][$table] ?? 0) + 1;
                }
            }
            foreach ($inserts as $batch) {
                $names = array_keys($batch[0]);
                $tuple = '(' . implode(', ', array_fill(0, count($names), '?')) . ')';
                foreach (array_chunk($batch, 500) as $chunk) {
                    $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES ' . implode(', ', array_fill(0, count($chunk), $tuple)))
                        ->execute(array_merge(...array_map('array_values', $chunk)));
                }
            }
            $actual = $load();
            foreach ($expected as $key => $out) {
                $row = $actual[$key] ?? throw new RuntimeException('Rekonciliace: cílový řádek chybí (' . $table . ').');
                foreach ($out as $column => $value) {
                    if (in_array($column, $counters, true)) {
                        if (bccomp((string) $row[$column], (string) $value) < 0) {
                            throw new RuntimeException('Rekonciliace: počítadlo ' . $table . '.' . $column . ' je níž než ve zdroji.');
                        }
                        continue;
                    }
                    if ($column === 'updated_at') {
                        continue;
                    }
                    if (!$this->same($value, $row[$column] ?? null, $columns[$column]['DATA_TYPE'])) {
                        throw new RuntimeException('Rekonciliace: liší se ' . $table . '.' . $column . '.');
                    }
                }
                $report['reconciliation'][$table] = ($report['reconciliation'][$table] ?? 0) + 1;
            }
        }
    }

    /**
     * Přemapuje řádek tabulky bez `id`; vrátí null, když tolerantní tabulka míří na doklad
     * mimo export (řádek se vynechá a vykáže v `dropped`).
     *
     * @param array<string,mixed> $definition
     * @param array<string,array<int|string,int>> $map
     * @return array<string,mixed>|null
     */
    private function remapKeyless(string $table, array $definition, array $row, array $map, int $supplierId, array $columns, array &$report): ?array
    {
        $out = $row;
        if (isset($columns['supplier_id'])) {
            $out['supplier_id'] = $supplierId;
        }
        foreach ($definition['refs'] ?? [] as $column => $target) {
            $value = $row[$column] ?? null;
            if ($value === null || (string) $value === '0') {
                continue;
            }
            $out[$column] = $map[$target][MyuctoExportReader::id($value)]
                ?? throw new RuntimeException('Chybí zdrojová vazba ' . $table . '.' . $column . ' → ' . $target . '.');
        }
        foreach ($definition['polymorphic'] ?? [] as $column => [$typeColumn, $targets]) {
            $type = (string) ($row[$typeColumn] ?? '');
            $target = $targets[$type] ?? null;
            $mapped = $target === null ? null : ($map[$target][MyuctoExportReader::id($row[$column] ?? null)] ?? null);
            if ($mapped === null) {
                if (!($definition['tolerant'] ?? false)) {
                    throw new RuntimeException('Chybí zdrojová vazba ' . $table . '.' . $column . ' (' . $type . ').');
                }
                $report['dropped'][$table . '.' . $type] = ($report['dropped'][$table . '.' . $type] ?? 0) + 1;
                return null;
            }
            $out[$column] = $mapped;
        }
        foreach ($definition['detached'] ?? [] as $column) {
            if (($out[$column] ?? null) !== null) {
                $report['detached'][$table . '.' . $column] = ($report['detached'][$table . '.' . $column] ?? 0) + 1;
                $out[$column] = null;
            }
        }
        if ($table === 'internal_document_lines' && ($row['dimensions_json'] ?? null) !== null) {
            $out['dimensions_json'] = $this->remapDimensionsJson((string) $row['dimensions_json'], $map);
        }
        foreach ($out as $column => $_) {
            if (($columns[$column]['GENERATION_EXPRESSION'] ?? '') !== '') {
                unset($out[$column]);
            }
        }
        return $out;
    }

    /** Dimenze řádku interního dokladu jsou mapa typ dimenze → hodnota; obě ID se přemapují. */
    private function remapDimensionsJson(string $json, array $map): string
    {
        $dimensions = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($dimensions) || $dimensions === []) {
            return $json;
        }
        $out = [];
        foreach ($dimensions as $type => $value) {
            $mappedType = $map['dimension_types'][MyuctoExportReader::id((string) $type)]
                ?? throw new RuntimeException('Chybí zdrojová vazba dimenze řádku interního dokladu.');
            $out[(string) $mappedType] = $map['dimension_values'][MyuctoExportReader::id($value)]
                ?? throw new RuntimeException('Chybí zdrojová vazba hodnoty dimenze řádku interního dokladu.');
        }
        return json_encode($out, JSON_THROW_ON_ERROR);
    }

    /**
     * Vazby konceptu interního dokladu na doklady firmy se přemapují; vazba na doklad, který
     * export nenese, se vynechá a vykáže (stejně jako obnova účetního archivu).
     */
    private function remapInternalDocumentLinks(string $json, array $map): ?string
    {
        $links = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($links) || $links === []) {
            return $json;
        }
        $restored = [];
        foreach ($links as $link) {
            $reference = ['invoice' => 'invoices', 'purchase_invoice' => 'purchase_invoices', 'bank' => 'bank_transactions',
                'cash' => 'cash_documents'][is_array($link) ? (string) ($link['doc_type'] ?? '') : ''] ?? null;
            $id = is_array($link) ? ($link['doc_id'] ?? null) : null;
            $newId = $reference !== null && (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0
                ? ($map[$reference][(int) $id] ?? null) : null;
            if ($newId === null) {
                $this->droppedLinks['internal_documents.document_links_json'] = ($this->droppedLinks['internal_documents.document_links_json'] ?? 0) + 1;
                continue;
            }
            $restored[] = array_replace($link, ['doc_id' => $newId]);
        }
        return $restored === [] ? null : json_encode($restored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Číselná řada nese ve svém klíči (`legacy_key`) ID pokladny, klienta, kategorie tržby
     * nebo bankovního účtu, ke kterému patří. V cíli má tentýž klíč s přemapovaným ID,
     * jinak by řada z průvodce a převedená řada byly dvě různé řady.
     */
    private static function numberSeriesKey(string $key, mixed $sourceScope, mixed $targetScope): string
    {
        if (preg_match('/\A(client|revenue_category):([0-9]+):([a-z_]+)\z/D', $key, $m) === 1) {
            [$embedded, $build] = [$m[2], static fn (string $id): string => $m[1] . ':' . $id . ':' . $m[3]];
        } elseif (preg_match('/\Adoc:([a-z_]+):([0-9]+)\z/D', $key, $m) === 1 && $m[2] !== '0') {
            [$embedded, $build] = [$m[2], static fn (string $id): string => 'doc:' . $m[1] . ':' . $id];
        } elseif (preg_match('/\Abank:([0-9]+)\z/D', $key, $m) === 1) {
            [$embedded, $build] = [$m[1], static fn (string $id): string => 'bank:' . $id];
        } else {
            return $key;
        }
        if ($embedded !== (string) $sourceScope || $targetScope === null) {
            throw new RuntimeException('Klíč číselné řady nesedí na její rozsah: ' . $key . '.');
        }
        return $build((string) $targetScope);
    }

    /**
     * Řádek pro párování podle přirozeného klíče: vazby v klíči (typ dimenze, pokladna řady)
     * se porovnají už s přemapovanými hodnotami cíle. Vrátí null, když řádek párovat nejde
     * (nová řada bez `legacy_key`, rodič bez mapování).
     *
     * @param array<string,array<int|string,int>> $map
     * @return array<string,mixed>|null
     */
    private function reuseLookupRow(string $table, array $row, array $map, array $schema): ?array
    {
        $refs = $this->references($table, $schema, $row);
        $columns = [...MyuctoImportProfile::REUSE_KEYS[$table], ...($table === 'number_series' ? ['scope_id'] : [])];
        $source = $row;
        foreach ($columns as $column) {
            $target = $refs[$column] ?? null;
            if ($target === null || $target === 'users' || ($row[$column] ?? null) === null || (string) $row[$column] === '0') {
                continue;
            }
            $mapped = $map[$target][MyuctoExportReader::id($row[$column])] ?? null;
            if ($mapped === null) {
                return null;
            }
            $row[$column] = $mapped;
        }
        if ($table === 'number_series') {
            if (($row['legacy_key'] ?? null) === null) {
                return null;
            }
            $row['legacy_key'] = self::numberSeriesKey((string) $row['legacy_key'], $source['scope_id'] ?? null, $row['scope_id'] ?? null);
        }
        return $row;
    }

    /**
     * Před kontrolou grafu: řady pro ostatní doklady (jejich druhy obnova nepřenáší)
     * a historie oprav účtování u smazaného dokladu se vynechají, měkké vazby na řádek,
     * který export nenese (ani po vynechání), se vynulují. Obojí se vykáže, nic nezmizí potichu.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @return array{0:array<string,int>,1:array<string,int>} [vynechané řádky, odpojené vazby]
     */
    private function pruneOutsideGraph(array &$tables): array
    {
        $dropped = [];
        $detached = [];
        $droppedSeries = [];
        foreach (['number_series' => ['scope_id', 'scope_type'], 'accounting_document_series' => ['register_id', 'series_code']] as $table => [$column, $typeColumn]) {
            $targets = MyuctoImportProfile::POLYMORPHIC_TARGETS[$table][$column][1];
            foreach ($tables[$table] ?? [] as $id => $row) {
                $type = (string) ($row[$typeColumn] ?? '');
                if (!array_key_exists($type, $targets) && (string) ($row[$column] ?? '0') !== '0') {
                    unset($tables[$table][$id]);
                    $dropped[$table . '.' . $type] = ($dropped[$table . '.' . $type] ?? 0) + 1;
                    if ($table === 'number_series') {
                        $droppedSeries[(int) $id] = true;
                    }
                }
            }
        }
        foreach ($tables['number_series_counters'] ?? [] as $id => $row) {
            if (isset($droppedSeries[MyuctoExportReader::id($row['series_id'] ?? null)])) {
                unset($tables['number_series_counters'][$id]);
                $dropped['number_series_counters'] = ($dropped['number_series_counters'] ?? 0) + 1;
            }
        }
        foreach (MyuctoImportProfile::SOFT_REFERENCES as $table => $columns) {
            foreach ($tables[$table] ?? [] as $id => $row) {
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $target = MyuctoImportProfile::EXTRA_REFERENCES[$table][$column];
                    if ($value !== null && !isset($tables[$target][MyuctoExportReader::id($value)])) {
                        $tables[$table][$id][$column] = null;
                        $detached[$table . '.' . $column] = ($detached[$table . '.' . $column] ?? 0) + 1;
                    }
                }
            }
        }
        foreach (MyuctoImportProfile::OUTSIDE_GRAPH_OWNERS as $table => $columns) {
            foreach ($tables[$table] ?? [] as $id => $row) {
                foreach ($columns as $column) {
                    if (($row[$column] ?? null) !== null) {
                        unset($tables[$table][$id]);
                        $dropped[$table . '.' . $column] = ($dropped[$table . '.' . $column] ?? 0) + 1;
                        continue 2;
                    }
                }
            }
        }
        foreach (MyuctoImportProfile::TOLERANT_TABLES as $table) {
            foreach ($tables[$table] ?? [] as $id => $row) {
                foreach (MyuctoImportProfile::POLYMORPHIC_TARGETS[$table] ?? [] as $column => [$typeColumn, $targets]) {
                    $type = (string) ($row[$typeColumn] ?? '');
                    $target = $targets[$type] ?? null;
                    if ($target !== null && ($row[$column] ?? null) !== null && !isset($tables[$target][MyuctoExportReader::id($row[$column])])) {
                        unset($tables[$table][$id]);
                        $dropped[$table . '.' . $type] = ($dropped[$table . '.' . $type] ?? 0) + 1;
                        continue 2;
                    }
                }
            }
        }
        ksort($dropped);
        return [$dropped, $detached];
    }

    /**
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     */
    private function validateKeylessRows(array $tables, int $sourceSupplier, array $schema): void
    {
        foreach (MyuctoImportProfile::KEYLESS_TABLES as $table => $definition) {
            foreach ($tables[$table] ?? [] as $row) {
                if (array_diff_key($row, $schema['columns'][$table] ?? []) !== []) {
                    throw new RuntimeException('Cílová verze nezná sloupce exportu: ' . $table . '.');
                }
                foreach ($definition['key'] as $column) {
                    if (!array_key_exists($column, $row) || $row[$column] === null) {
                        throw new RuntimeException('Export obsahuje neplatný řádek ' . $table . '.');
                    }
                }
                if (isset($schema['columns'][$table]['supplier_id'])) {
                    if (MyuctoExportReader::id($row['supplier_id'] ?? null) !== $sourceSupplier) {
                        throw new RuntimeException('Export obsahuje data jiné firmy: ' . $table . '.');
                    }
                } elseif (!isset($tables['number_series'][MyuctoExportReader::id($row['series_id'] ?? null)])) {
                    throw new RuntimeException('Graf nemá zdrojového rodiče: ' . $table . '.');
                }
                foreach ($row as $value) {
                    if ($value !== null && !is_scalar($value)) {
                        throw new RuntimeException('Neplatná hodnota řádku exportu.');
                    }
                }
            }
        }
    }

    /**
     * Výchozí nastavení nové firmy, které export nese, první obnova nahradí nastavením
     * zdroje ({@see MyuctoImportProfile::REPLACED_SETUP_TABLES}).
     *
     * @return array<string,int> počet smazaných výchozích řádků po tabulkách
     */
    private function replaceSetupDefaults(PDO $pdo, array $tables, int $supplierId): array
    {
        $replaced = [];
        foreach (MyuctoImportProfile::REPLACED_SETUP_TABLES as $table => $condition) {
            if (($tables[$table] ?? []) === []) {
                continue;
            }
            $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE supplier_id = ? AND ' . $condition);
            $stmt->execute([$supplierId]);
            if ($stmt->rowCount() > 0) {
                $replaced[$table] = $stmt->rowCount();
            }
        }
        return $replaced;
    }

    /**
     * Počítadla, bez kterých by další vydaný doklad nenavázal na převedené: export
     * s doklady, ale bez počítadla jejich řady (starší verze, MyInvoice, zdroj s čísly
     * zadanými ručně), vyžaduje kontrolu řad před vystavením dalšího dokladu.
     *
     * @return list<string>
     */
    private static function numberingCheck(array $tables): array
    {
        $check = [];
        foreach (['invoices' => 'invoice_counters', 'purchase_invoices' => 'purchase_invoice_counters'] as $documents => $counters) {
            if (($tables[$documents] ?? []) !== [] && ($tables[$counters] ?? []) === []) {
                $check[] = $counters;
            }
        }
        return $check;
    }

    /**
     * Firma se obnovou nepřepisuje, převezme jen číslovací nastavení zdroje (šablony čísel
     * dokladů, období řady faktur, číslování přijatých dokladů), ať další doklad navazuje
     * na převedené. Jen při první obnově a jen u nativního exportu MyÚčta.
     *
     * @return list<string> převzaté sloupce
     */
    private function adoptSupplierNumbering(PDO $pdo, array $root, array $target, int $supplierId, array $schema): array
    {
        $values = [];
        foreach ($root as $column => $value) {
            if ((str_ends_with($column, MyuctoImportProfile::SUPPLIER_NUMBERING_SUFFIX) || in_array($column, MyuctoImportProfile::SUPPLIER_NUMBERING_COLUMNS, true))
                && isset($schema['columns']['supplier'][$column]) && array_key_exists($column, $target)
                && !$this->same($value, $target[$column], $schema['columns']['supplier'][$column]['DATA_TYPE'])) {
                $values[$column] = $value;
            }
        }
        if ($values === []) {
            return [];
        }
        $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($values)));
        if (isset($schema['columns']['supplier']['updated_at'])) {
            $set .= ', updated_at = updated_at';
        }
        $pdo->prepare('UPDATE supplier SET ' . $set . ' WHERE id = ?')->execute([...array_values($values), $supplierId]);
        $columns = array_keys($values);
        sort($columns);
        return $columns;
    }

    /**
     * Nese-li export účetnictví, musí cíl vést v každém okamžiku stejný účetní rámec
     * (vyhláška 500, nebo 504 u neziskových organizací). Osnova i deník se přebírají
     * beze změny, takže deník podle vyhlášky 504 ve firmě s rámcem 500 (a naopak) by
     * dal chybné výkazy. Den bez záznamu má rámec 500 jako {@see AccountingFrameworkService::forDate()};
     * export bez historie rámce je proto celý v rámci 500.
     *
     * @param array<string,array<int|string,array<string,mixed>>> $tables
     * @param array<int|string,array<string,mixed>>|null $source
     */
    private function assertAccountingFramework(
        array $tables,
        ?array $source,
        int $sourceSupplier,
        int $targetSupplier
    ): void
    {
        $bound = false;
        foreach (MyuctoImportProfile::FRAMEWORK_BOUND_TABLES as $table) {
            $bound = $bound || ($tables[$table] ?? []) !== [];
        }
        if (!$bound) {
            return;
        }
        $frameworks = [AccountingFrameworkService::CZ_500, AccountingFrameworkService::CZ_504];
        $sourceByDate = [];
        foreach ($source ?? [] as $row) {
            $framework = $row['accounting_framework'] ?? null;
            if (MyuctoExportReader::id($row['supplier_id'] ?? null) !== $sourceSupplier
                || !is_string($row['effective_from'] ?? null) || isset($sourceByDate[$row['effective_from']])
                || !in_array($framework, $frameworks, true)) {
                throw new RuntimeException('Neplatná historie účetního rámce v exportu.');
            }
            $sourceByDate[$row['effective_from']] = $framework;
        }
        $targetByDate = array_column((new AccountingFrameworkRepository($this->db))->listForTenantForUpdate($targetSupplier), 'accounting_framework', 'effective_from');
        $dates = array_unique([...array_keys($sourceByDate), ...array_keys($targetByDate)]);
        sort($dates);
        $left = AccountingFrameworkService::CZ_500;
        $right = AccountingFrameworkService::CZ_500;
        foreach ($dates as $date) {
            $left = $sourceByDate[$date] ?? $left;
            $right = $targetByDate[$date] ?? $right;
            if ($left !== $right) {
                throw new RuntimeException(sprintf(
                    'Účetní rámec cílové firmy se liší od exportu k %s: export vede účetnictví podle vyhlášky %s, cílová firma podle vyhlášky %s.',
                    $date, substr($left, 3), substr($right, 3),
                ));
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
        if ($table === 'journal_document_postings') {
            $refs['doc_id'] = MyuctoImportProfile::DOCUMENT_TYPES[$row['doc_type'] ?? '']
                ?? throw new RuntimeException('Nepodporovaný typ dokladu skupiny zaúčtování.');
        }
        foreach (MyuctoImportProfile::POLYMORPHIC_TARGETS[$table] ?? [] as $column => [$typeColumn, $targets]) {
            $value = $row[$column] ?? null;
            if ($value === null || (string) $value === '0') {
                continue;
            }
            $type = (string) ($row[$typeColumn] ?? '');
            if (!array_key_exists($type, $targets)) {
                throw new RuntimeException('Nepodporovaný druh vazby ' . $table . '.' . $column . ': ' . $type . '.');
            }
            if ($targets[$type] !== null) {
                $refs[$column] = $targets[$type];
            }
        }
        if (isset($schema['columns'][$table]['supplier_id'])) {
            $refs['supplier_id'] = 'supplier';
        }
        if (!isset($this->fkReferences[$table])) {
            $this->fkReferences[$table] = [];
            foreach ($schema['foreignKeyRows'] as $fk) {
                if ($fk['TABLE_NAME'] === $table) {
                    // U složeného FK (supplier_id, entry_id) je supplier_id stále
                    // vlastníkem firmy, nikoli ID rodičovského zápisu.
                    if ($fk['COLUMN_NAME'] === 'supplier_id') continue;
                    // Odpojená vazba míří mimo převáděný graf a remap() ji vynuluje.
                    if (in_array($fk['COLUMN_NAME'], MyuctoImportProfile::DETACHED_REFERENCES[$table] ?? [], true)) continue;
                    // Složený FK (type_id, id) na hodnotu dimenze: typ mapuje vlastní FK nebo EXTRA_REFERENCES.
                    if ($fk['REFERENCED_TABLE_NAME'] === 'dimension_values' && $fk['REFERENCED_COLUMN_NAME'] === 'type_id') continue;
                    if ($fk['REFERENCED_COLUMN_NAME'] !== 'id') {
                        throw new RuntimeException('Nepodporovaná reference schématu.');
                    }
                    $this->fkReferences[$table][$fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
                }
            }
        }
        return [...$refs, ...$this->fkReferences[$table]];
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
                // Zúčtování DPH nese klíč zdaňovacího období (VatClearingService::sourceIdFor()), ne ID dokladu.
                'vat_clearing' => null,
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
        if ($table === 'credit_note_offsets') {
            $target = MyuctoImportProfile::DOCUMENT_TYPES[$row['doc_type']] ?? throw new RuntimeException('Nepodporovaný typ zápočtu dobropisu.');
            foreach (['invoice_id', 'credit_note_id'] as $column) {
                $out[$column] = $map[$target][MyuctoExportReader::id($row[$column])] ?? throw new RuntimeException('Chybí doklad zápočtu dobropisu.');
            }
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
        foreach ([...MyuctoImportProfile::RESET_COLUMNS[$table] ?? [], ...MyuctoImportProfile::DETACHED_REFERENCES[$table] ?? []] as $column) {
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
        if ($table === 'number_series' && ($row['legacy_key'] ?? null) !== null) {
            $out['legacy_key'] = self::numberSeriesKey((string) $row['legacy_key'], $row['scope_id'] ?? null, $out['scope_id'] ?? null);
        }
        if ($table === 'documents' && ($out['thumb_status'] ?? null) === 'generated') {
            $out['thumb_status'] = 'none';
        }
        if ($table === 'internal_documents' && ($row['document_links_json'] ?? null) !== null) {
            $out['document_links_json'] = $this->remapInternalDocumentLinks((string) $row['document_links_json'], $map);
        }
        if ($table === 'accounting_closing_steps' && ($row['step_key'] ?? null) === 'provisions' && ($row['payload'] ?? null) !== null) {
            $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $changed = false;
            foreach (is_array($payload) && is_array($payload['entries'] ?? null) ? $payload['entries'] : [] as $index => $entry) {
                foreach (['invoice_id' => 'invoices', 'entry_id' => 'journal_entries'] as $key => $reference) {
                    if (is_array($entry) && !empty($entry[$key])) {
                        $payload['entries'][$index][$key] = $map[$reference][MyuctoExportReader::id($entry[$key])]
                            ?? throw new RuntimeException('Chybí zdrojová vazba opravné položky uzávěrky → ' . $reference . '.');
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $out['payload'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        }
        foreach ($out as $column => $_) {
            if (($schema['columns'][$table][$column]['GENERATION_EXPRESSION'] ?? '') !== '') {
                unset($out[$column]);
            }
        }
        return $out;
    }

    /**
     * Založení firmy hned otevře účetní období pro rok založení a uživatel ho v aplikaci
     * smazat nemůže. Dokud je nepoužité ({@see UnusedAccountingPeriods}), firmu za prázdnou
     * nepovažuje: nese-li export vlastní období, nahradí ho, jinak ho ponechá.
     *
     * @return list<array{fiscal_year:int, starts_on:string, ends_on:string}>|null smazaná období;
     *         null = firma žádná období nemá, nebo je některé použité
     */
    private function replaceUnusedPeriods(PDO $pdo, int $supplierId, bool $packageHasPeriods): ?array
    {
        $stmt = $pdo->prepare('SELECT id, fiscal_year, starts_on, ends_on FROM accounting_periods WHERE supplier_id = ? ORDER BY starts_on FOR UPDATE');
        $stmt->execute([$supplierId]);
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($periods === [] || $this->unusedPeriods->blocking($supplierId) !== []) {
            return null;
        }
        if (!$packageHasPeriods) {
            return [];
        }
        $pdo->prepare('DELETE FROM accounting_periods WHERE supplier_id = ?')->execute([$supplierId]);
        return array_map(static fn (array $p): array => ['fiscal_year' => (int) $p['fiscal_year'],
            'starts_on' => (string) $p['starts_on'], 'ends_on' => (string) $p['ends_on']], $periods);
    }

    /**
     * Výchozí nastavení prázdné firmy spárované při první obnově převezme popisné údaje
     * ze zdroje ({@see MyuctoImportProfile::ADOPTED_SETUP_COLUMNS}). Přirozený klíč párování
     * (číslo účtu a banka, kód měny) zůstává a hlídá ho dál assertReusable().
     *
     * @param array<string,array<int|string,true>> $reused
     * @param array<string,array<int|string,array<string,mixed>>> $prepared
     * @param array<string,array<int|string,int>> $map
     */
    private function adoptSetupRows(PDO $pdo, array $reused, array $prepared, array $map, array $schema): void
    {
        foreach (MyuctoImportProfile::ADOPTED_SETUP_COLUMNS as $table => $columns) {
            $columns = array_values(array_filter($columns, static fn (string $c): bool => isset($schema['columns'][$table][$c])));
            foreach (array_keys($reused[$table] ?? []) as $id) {
                $row = $prepared[$table][$id] ?? null;
                if ($row === null) {
                    continue;
                }
                $values = array_intersect_key($row, array_flip($columns));
                if ($values === []) {
                    continue;
                }
                $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($values)));
                if (isset($schema['columns'][$table]['updated_at'])) {
                    $set .= ', updated_at = updated_at';
                }
                $pdo->prepare('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ?')->execute([...array_values($values), $map[$table][$id]]);
            }
        }
    }

    /**
     * Účty osnovy, které cílová firma už má, se párují podle kódu. Osnova exportované firmy
     * je pro její deník závazná: při první obnově (cíl prošel kontrolou prázdné firmy, nemá
     * tedy žádný zápis deníku ani doklad) převezmou spárované účty význam a název zdroje,
     * jinak by obnova výchozí osnovy nové firmy odmítla kvůli jiné daňové uznatelnosti
     * nebo druhu účtu. Při opakované obnově (firma už převzatá data má a mohla na nich
     * účtovat) se osnova nemění; rozdíl obnovu zastaví s výpisem účtů dřív, než cokoli zapíše.
     *
     * @param array<string,array<int|string,true>> $reused
     * @param array<string,array<int|string,array<string,mixed>>> $prepared
     * @param array<string,array<int|string,int>> $map
     * @return list<string> převzaté účty (kód: změněné sloupce)
     */
    private function reconcileChart(PDO $pdo, array $reused, array $prepared, array $map, bool $adopt): array
    {
        $ids = array_keys($reused['chart_of_accounts'] ?? []);
        if ($ids === []) {
            return [];
        }
        $actual = [];
        foreach (array_chunk(array_map(static fn (int|string $id): int => $map['chart_of_accounts'][$id], $ids), 500) as $chunk) {
            $stmt = $pdo->prepare('SELECT * FROM chart_of_accounts WHERE id IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ') FOR UPDATE');
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $actual[(int) $row['id']] = $row;
            }
        }
        $columns = $adopt ? [...MyuctoImportProfile::CHART_SEMANTIC_COLUMNS, ...MyuctoImportProfile::CHART_ADOPTED_COLUMNS]
            : MyuctoImportProfile::CHART_SEMANTIC_COLUMNS;
        $changes = [];
        foreach ($ids as $id) {
            $expected = $prepared['chart_of_accounts'][$id];
            $current = $actual[$map['chart_of_accounts'][$id]] ?? throw new RuntimeException('Rekonciliace: cílový řádek chybí.');
            $diff = [];
            foreach ($columns as $column) {
                if (array_key_exists($column, $expected) && (string) $expected[$column] !== (string) ($current[$column] ?? null)) {
                    $diff[$column] = $expected[$column];
                }
            }
            if ($diff !== []) {
                $changes[(string) $expected['account_code']] = [$map['chart_of_accounts'][$id], $diff, $current];
            }
        }
        ksort($changes, SORT_STRING);
        if (!$adopt) {
            $conflicts = [];
            foreach ($changes as $code => [, $diff, $current]) {
                $conflicts[] = $code . ' (' . implode(', ', array_map(
                    static fn (string $c): string => $c . ': export ' . ($diff[$c] ?? 'NULL') . ', cílová firma ' . ($current[$c] ?? 'NULL'),
                    array_keys($diff),
                )) . ')';
            }
            if ($conflicts !== []) {
                $count = count($conflicts);
                throw new RuntimeException('Účtová osnova cílové firmy má u ' . $count . ' účtů jiný význam než export: '
                    . implode('; ', array_slice($conflicts, 0, 20)) . ($count > 20 ? '; …' : '')
                    . '. Firma už převzatá data má, proto obnova osnovu nemění. Sjednoťte účty v cílové firmě, nebo obnovte do nové prázdné firmy. Obnova nic nezměnila.');
            }
            return [];
        }
        $adopted = [];
        foreach ($changes as $code => [$targetId, $diff]) {
            $set = implode(', ', array_map(static fn (string $c): string => '`' . $c . '` = ?', array_keys($diff)));
            $pdo->prepare('UPDATE chart_of_accounts SET ' . $set . ' WHERE id = ?')->execute([...array_values($diff), $targetId]);
            $keys = array_keys($diff);
            sort($keys);
            $adopted[] = $code . ': ' . implode(', ', $keys);
        }
        return $adopted;
    }

    private function assertEmptyTarget(PDO $pdo, array $tables, int $supplierId, bool $unusedPeriods): void
    {
        $scopes = $this->scopes->resolveAll($supplierId);
        foreach (MyuctoImportProfile::TABLES as $table) {
            if (isset(MyuctoImportProfile::REUSE_KEYS[$table]) || ($unusedPeriods && $table === 'accounting_periods')) {
                continue;
            }
            // Tabulku, kterou export nenese (starší verze, MyInvoice), obnova nezapisuje.
            if (in_array($table, MyuctoImportProfile::OPTIONAL_GRAPH_TABLES, true) && ($tables[$table] ?? []) === []) {
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

    /**
     * Předkontace se při první obnově párují na existující pravidla cílové firmy. Pravidlo,
     * které cíl má a zdroj ne, by po obnově měnilo účinná pravidla oproti zdroji, takže
     * cílová firma není prázdná a obnova se zastaví stejně jako u ostatních tabulek.
     *
     * @param list<int|string> $reusedSourceIds
     * @param array<string,array<int|string,int>> $map
     */
    private function assertNoExtraTargetPostingRules(PDO $pdo, int $supplierId, array $reusedSourceIds, array $map): void
    {
        $matched = [];
        foreach ($reusedSourceIds as $sourceId) {
            $matched[$map['posting_rules'][$sourceId]] = true;
        }
        $stmt = $pdo->prepare('SELECT id, rule_key, priority FROM posting_rules WHERE supplier_id = ? ORDER BY rule_key, priority');
        $stmt->execute([$supplierId]);
        $extra = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            if (!isset($matched[(int) $rule['id']])) {
                $extra[] = $rule['rule_key'] . ' (' . $rule['priority'] . ')';
            }
        }
        if ($extra !== []) {
            throw new RuntimeException('Cílová firma už obsahuje vlastní předkontace, které v exportu nejsou: ' . implode(', ', array_slice($extra, 0, 10))
                . (count($extra) > 10 ? ' a další' : '') . '.');
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
            'currencies' => ['code', 'decimals'], 'chart_of_accounts' => ['account_code', ...MyuctoImportProfile::CHART_SEMANTIC_COLUMNS],
            'vat_classifications' => ['code', 'direction', 'dphdp3_line', 'dphdp3_line_secondary', 'kh_section', 'vat_rate', 'is_reverse_charge', 'kod_pred_pl', 'kh_regime_code', 'kh_bad_debt', 'archived'],
            'cash_registers' => ['currency_code', 'account_code'], 'supplier_bank_accounts' => ['account_number', 'bank_code', 'currency'],
            'posting_rules' => ['rule_key', 'priority', 'description', 'debit_account_code', 'credit_account_code', 'is_active'],
            'dimension_types' => ['code', 'kind'], 'dimension_values' => ['type_id', 'code'],
            'number_series' => ['legacy_key', 'doc_kind', 'scope_type', 'scope_id'],
            'accounting_document_series' => ['series_code', 'fiscal_year', 'register_id'],
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
