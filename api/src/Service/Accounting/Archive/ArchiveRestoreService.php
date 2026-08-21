<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Archive;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingSourceId;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use PDO;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * ArchiveRestoreService — ověřená obnova per-firma archivu (Fáze F, audit 2026-07,
 * návrh „Ověřená obnova archivu firmy").
 *
 * BEZPEČNOSTNÍ MODEL — „obnov jako NOVÁ firma":
 * Obnova NIKDY nepřepisuje existující firmu. Vždy zakládá NOVÝ řádek `supplier` a
 * všechna AUTO_INCREMENT id z archivu remapuje na nová (per tabulka mapa old→new,
 * FK sloupce se překládají při insertu). Tím je vyloučená kolize s daty existujících
 * firem — nejhorší možný dopad chyby remapu je vadný vnitřní odkaz UVNITŘ obnovené
 * kopie, nikdy cross-tenant poškození jiné firmy.
 *
 * Proč remap do STEJNÉ DB a ne staging schéma: prostředí drží jednu DB/schéma
 * (Connection má jediný DSN); staging schéma by znamenalo replikovat celou sadu
 * migrací zvlášť. Remap na nový supplier_id je proti kolizi bezpečnější i jednodušší
 * a je to přesně scénář „selektivní obnova jedné firmy do běžící instance" (spor/forenzní).
 *
 * Ochranné vrstvy:
 *   1) Validace PŘED importem: sha256 per tabulka i binárka, počty řádků z manifestu,
 *      schema_version — {@see validate()} (stejné kontroly jako CLI --dry-run).
 *   2) Celý import běží v JEDNÉ transakci se zapnutými FK checks — jakákoli
 *      porušená FK/kolize → rollback celého importu (žádná částečná/tichá koruze).
 *   3) FK graf se čte z information_schema (ne ručně) — remapuje se KAŽDÝ FK sloupec
 *      mířící na tenant tabulku (jinak by stará id tiše ukázala na cizí firmu).
 *   4) PO importu: kontrola Σ MD = Σ D per období (podvojnost) + rozdílový report.
 *
 * Polymorfní journal_entries.source_id (bez DB FK) se remapuje dle source_type
 * (dokladové typy přes příslušné mapy, závěrkové sloty přes mapu období — viz
 * {@see resolveSourceId()}); uq_je_supplier_source zajistí, že chybný remap spadne
 * hlučně (duplicita) místo tiché duplikace.
 */
final class ArchiveRestoreService
{
    /** @var array<string, array<string,string>> table → col → referenced_table (z info_schema) */
    private array $fkGraph = [];

    /** @var array<string, array<string,bool>> table → col → is_nullable */
    private array $nullable = [];

    /** @var array<string,bool> table → má supplier_id (per-tenant)? */
    private array $isTenant = [];

    /**
     * @var array<string, array<string,bool>> table → col → je GENERATED?
     *
     * Generovaný sloupec se do INSERTu nesmí dostat. Mimo striktní režim ho server
     * jen ignoruje s warningem, ve striktním (výchozí stav čerstvé MySQL/MariaDB)
     * shodí celý import chybou 1906 — a s ním i obnovu archivu.
     */
    private array $generated = [];

    /** @var array<string, array<int|string,int>> table → (old id → new id) */
    private array $maps = [];

    /** @var list<array{table:string,newId:int,col:string,ref:string,old:int,special?:string,type?:string}> */
    private array $deferred = [];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly AccountingArchiveCatalog $catalog,
    ) {}

    /**
     * Obnoví archiv jako NOVOU firmu. Vrací report (nové supplier_id, počty, bilance,
     * varování). Při jakékoli chybě je transakce vrácena a nevznikne žádná firma.
     *
     * @return array<string,mixed>
     * @throws RestoreException
     */
    public function restore(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            throw new RestoreException('file_not_found', 'Soubor archivu neexistuje: ' . $zipPath);
        }
        $tmpDir = sys_get_temp_dir() . '/myucto-restore-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new RestoreException('tmp_failed', 'Nelze vytvořit temp adresář.');
        }

        try {
            $manifest = $this->extractAndValidate($zipPath, $tmpDir);
            return $this->import($manifest, $tmpDir);
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /**
     * Rozbalí + zvaliduje archiv (sha256 tabulek i binárek, počty řádků, formát,
     * schema_version). Vrací manifest. Sdílená brána pro CLI i round-trip test.
     *
     * @return array<string,mixed>
     * @throws RestoreException
     */
    public function extractAndValidate(string $zipPath, string $tmpDir): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RestoreException('zip_open', 'ZIP nelze otevřít.');
        }
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            throw new RestoreException('zip_extract', 'ZIP nelze rozbalit.');
        }
        $zip->close();

        $manifestPath = $tmpDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            throw new RestoreException('manifest_missing', 'manifest.json v archivu chybí.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new RestoreException('manifest_parse', 'manifest.json není parsovatelný JSON.');
        }
        if (($manifest['format'] ?? null) !== 'myucto-archive') {
            throw new RestoreException('manifest_format', 'Neočekávaný formát archivu.');
        }
        $version = (int) ($manifest['version'] ?? 0);
        if ($version < 1 || $version > 2) {
            throw new RestoreException('manifest_version', 'Nepodporovaná verze archivu: ' . $version);
        }

        $errors = $this->validate($manifest, $tmpDir);
        if ($errors !== []) {
            throw new RestoreException('validation', "Validace archivu selhala:\n  - " . implode("\n  - ", $errors));
        }
        return $manifest;
    }

    /**
     * Validace obsahu archivu vůči manifestu (sha256 tabulek i binárek, počty řádků,
     * schema_version ≤ lokální migrace). Vrací seznam chyb (prázdný = OK).
     *
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    public function validate(array $manifest, string $tmpDir): array
    {
        $errors = [];

        // schema_version ≤ nejvyšší lokální migrace
        $schemaVersion = (string) ($manifest['schema_version'] ?? '');
        $migrationsDir = \MyInvoice\Bootstrap::rootDir() . '/db/migrations';
        $local = array_map('basename', glob($migrationsDir . '/*.sql') ?: []);
        sort($local, SORT_STRING);
        $localMax = $local === [] ? '' : (string) end($local);
        if ($schemaVersion === '' || $schemaVersion === 'unknown') {
            $errors[] = 'manifest: schema_version chybí';
        } elseif ($localMax === '' || strcmp($schemaVersion, $localMax) > 0) {
            $errors[] = "schema_version archivu ({$schemaVersion}) je novější než lokální migrace ({$localMax})";
        }

        // tabulky: sha256 + počty řádků
        $tables = $manifest['tables'] ?? [];
        if (!is_array($tables) || $tables === []) {
            $errors[] = 'manifest: sekce tables chybí nebo je prázdná';
            $tables = [];
        }
        foreach ($tables as $table => $info) {
            $path = $tmpDir . '/' . $table . '.jsonl';
            $expectedRows = (int) ($info['rows'] ?? -1);
            $expectedSha = (string) ($info['sha256'] ?? '');
            if (!is_file($path)) {
                if ($expectedRows > 0) {
                    $errors[] = "{$table}: soubor {$table}.jsonl chybí";
                }
                continue;
            }
            if (hash_file('sha256', $path) !== $expectedSha) {
                $errors[] = "{$table}: sha256 nesouhlasí";
            }
            $rows = 0;
            $fh = fopen($path, 'rb');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    if (trim($line) !== '') {
                        $rows++;
                    }
                }
                fclose($fh);
            }
            if ($rows !== $expectedRows) {
                $errors[] = "{$table}: počet řádků nesouhlasí (manifest {$expectedRows}, soubor {$rows})";
            }
        }

        // binárky příloh deníku: sha256
        foreach (($manifest['files'] ?? []) as $zipName => $info) {
            if (($info['missing'] ?? false) === true) {
                continue;
            }
            $path = $tmpDir . '/' . $zipName;
            if (!is_file($path)) {
                $errors[] = "{$zipName}: binárka v archivu chybí";
                continue;
            }
            if (hash_file('sha256', $path) !== (string) ($info['sha256'] ?? '')) {
                $errors[] = "{$zipName}: sha256 binárky nesouhlasí";
            }
        }

        return $errors;
    }

    /**
     * Vlastní import (transakce, remap). Předpokládá validovaný $tmpDir.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     * @throws RestoreException
     */
    private function import(array $manifest, string $tmpDir): array
    {
        $tables = (array) ($manifest['tables'] ?? []);
        $this->assertKnownTables(array_keys($tables));
        $this->loadSchema(array_keys($tables));

        $oldSupplierId = (int) (($manifest['supplier']['id'] ?? 0));
        if ($oldSupplierId <= 0 || !isset($tables['supplier'])) {
            throw new RestoreException('no_supplier', 'Archiv neobsahuje řádek supplier — obnovu jako novou firmu nelze provést. '
                . '(Archiv verze 1 supplier neexportoval; obnova vyžaduje archiv verze ≥ 2.)');
        }

        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }

        $this->maps = [];
        $this->deferred = [];
        $warnings = [];
        $counts = [];
        $newSupplierId = 0;

        $processedTables = [];
        try {
            foreach ($this->catalog->forRestore() as $archiveTable) {
                $table = $archiveTable->name;
                if (!isset($tables[$table])) {
                    continue;
                }
                $counts[$table] = $this->importTable(
                    $archiveTable,
                    $tmpDir . '/' . $table . '.jsonl',
                    $newSupplierId,
                    $processedTables,
                    $warnings,
                );
                $processedTables[] = $table;
                if ($table === 'supplier') {
                    $newSupplierId = $this->maps['supplier'][$oldSupplierId]
                        ?? throw new RestoreException('supplier_map', 'Nový supplier_id se nepodařilo zjistit.');
                }
            }

            $this->runDeferred($warnings);
            $this->copyAttachmentBinaries($manifest, $tmpDir, $oldSupplierId, $newSupplierId, $warnings);

            $balance = $this->checkBalance($newSupplierId);
            $imbalanced = array_filter($balance, static fn (array $b): bool => $b['diff'] !== '0.00');
            if ($imbalanced !== []) {
                // Podvojnost porušena → obnova je vadná, radši rollback než uložit rozbitá data.
                $detail = implode(', ', array_map(
                    static fn (array $b): string => 'období ' . $b['period_id'] . ': MD ' . $b['debit'] . ' ≠ D ' . $b['credit'],
                    $imbalanced,
                ));
                throw new RestoreException('unbalanced', 'Kontrola Σ MD = Σ D po obnově selhala (' . $detail . ').');
            }

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Binárky příloh deníku se kopírují na disk PŘED commitem (DB transakce na
            // soubory nedosáhne) — při rollbacku (např. porušená podvojnost) by jinak
            // zůstaly orphan soubory pod supplier_id, který nikdy nevznikl. Uklidit.
            if ($newSupplierId > 0) {
                $this->removeDir(JournalAttachmentStorage::baseDir($newSupplierId));
            }
            if ($e instanceof RestoreException) {
                throw $e;
            }
            throw new RestoreException('import_failed', 'Import selhal (transakce vrácena): ' . $e->getMessage(), $e);
        }

        $this->logger->info('Archiv firmy obnoven jako nová firma', [
            'old_supplier_id' => $oldSupplierId,
            'new_supplier_id' => $newSupplierId,
            'tables' => count($counts),
        ]);

        return [
            'new_supplier_id' => $newSupplierId,
            'old_supplier_id' => $oldSupplierId,
            'counts' => $counts,
            'balance' => array_values($balance),
            'warnings' => $warnings,
        ];
    }

    /**
     * Import jedné tabulky (řádek po řádku kvůli mapě old→new id). Vrací počet vložených řádků.
     *
     * @param list<string> $processed názvy tabulek s hotovou mapou
     * @param list<string> $warnings (by-ref)
     */
    private function importTable(
        AccountingArchiveTable $archiveTable,
        string $path,
        int $target,
        array $processed,
        array &$warnings,
    ): int {
        if (!is_file($path)) {
            return 0;
        }
        $table = $archiveTable->name;
        $processedSet = array_fill_keys($processed, true);
        $hasId = $archiveTable->primaryKey === ['id'];
        $isExchange = $table === 'exchange_rates';
        $isBankStatement = $table === 'bank_statements';
        $pdo = $this->db->pdo();

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RestoreException('read_failed', "Nelze číst {$table}.jsonl");
        }
        $inserted = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    throw new RestoreException('json_row', "{$table}: neparsovatelný řádek");
                }
                $oldId = $hasId ? ($row['id'] ?? null) : null;

                if ($isExchange) {
                    $this->insertExchangeRate($row);
                    $inserted++;
                    continue;
                }

                if ($isBankStatement) {
                    $newId = $this->importBankStatement($row, $target, $processedSet, $warnings);
                    $inserted++;
                    if ($oldId !== null) {
                        $this->maps[$table][(int) $oldId] = $newId;
                    }
                    continue;
                }

                [$cols, $vals, $rowDefers] = $this->buildInsert($table, $row, $target, $processedSet, $warnings);
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
                $pdo->prepare($sql)->execute($vals);
                $inserted++;

                $newId = $hasId ? (int) $pdo->lastInsertId() : 0;
                if ($hasId && $oldId !== null) {
                    $this->maps[$table][(int) $oldId] = $newId;
                }
                foreach ($rowDefers as $d) {
                    if (!$hasId) {
                        // Kompozitní/no-id tabulky nesmí mít odložený FK (v naší sadě nemají).
                        throw new RestoreException('defer_no_id', "{$table}.{$d['col']}: odložený FK na tabulce bez id není podporován.");
                    }
                    $d['table'] = $table;
                    $d['newId'] = $newId;
                    $this->deferred[] = $d;
                }
            }
        } finally {
            fclose($fh);
        }
        return $inserted;
    }

    /**
     * Sestaví (sloupce, hodnoty, odložené FK) pro INSERT jednoho řádku s remapem.
     *
     * @param array<string,mixed> $row
     * @param array<string,bool> $processedSet
     * @param list<string> $warnings
     * @return array{0:list<string>,1:list<mixed>,2:list<array{col:string,ref:string,old:int,special?:string,type?:string}>}
     */
    private function buildInsert(string $table, array $row, int $target, array $processedSet, array &$warnings): array
    {
        $cols = [];
        $vals = [];
        $defers = [];
        $fks = $this->fkGraph[$table] ?? [];
        $archiveTable = $this->catalog->get($table);
        $nonFk = $archiveTable === null ? [] : $archiveTable->softReferences;

        foreach ($row as $col => $val) {
            if ($col === 'id') {
                continue; // AUTO_INCREMENT přidělí nové
            }
            if (isset($this->generated[$table][$col])) {
                continue; // dopočítá si ho server; explicitní hodnota = chyba 1906
            }
            if ($col === 'supplier_id') {
                $cols[] = $col;
                $vals[] = $target;
                continue;
            }

            // journal_entries.source_id — polymorfní, bez FK, dle source_type
            if ($table === 'journal_entries' && $col === 'source_id') {
                $resolved = $this->resolveSourceId((string) ($row['source_type'] ?? ''), $val, $processedSet, $defers);
                $cols[] = $col;
                $vals[] = $resolved;
                continue;
            }

            $refTable = $fks[$col] ?? $nonFk[$col] ?? null;
            if ($refTable !== null) {
                $cols[] = $col;
                $vals[] = $this->remapRef($table, $col, $refTable, $val, $processedSet, $defers, $warnings);
                continue;
            }

            // ostatní sloupce (globální FK jako vat_rate_id/country_id, data) beze změny
            $cols[] = $col;
            $vals[] = $this->scalar($val);
        }

        return [$cols, $vals, $defers];
    }

    /**
     * Remapuje jednu FK/ref hodnotu. Vrací hodnotu k insertu (může odložit do $defers).
     *
     * @param array<string,bool> $processedSet
     * @param list<array<string,mixed>> $defers (by-ref)
     * @param list<string> $warnings (by-ref)
     */
    private function remapRef(
        string $table,
        string $col,
        string $refTable,
        mixed $val,
        array $processedSet,
        array &$defers,
        array &$warnings,
    ): mixed {
        if ($val === null) {
            return null;
        }
        $old = (int) $val;
        $nullable = $this->nullable[$table][$col] ?? true;

        if ($refTable === 'supplier') {
            return $this->maps['supplier'] === [] ? null : ($this->maps['supplier'][$old] ?? null);
        }
        // KRITICKÉ (adversariální review, audit 2026-07): kritérium "remapovat, nebo
        // ponechat staré id?" NESMÍ být jen isTenant (má sloupec supplier_id) — tabulky
        // jako bank_transactions/bank_statements jsou per-firmu jen TRANZITIVNĚ (přes
        // JOIN na payment_matches/faktury; export je tak i filtruje), fyzicky supplier_id
        // nemají, ale JSOU v archivním profilu (importují se, mají mapu old→new). Kdyby se
        // nechalo staré id, FK by tiše mířilo na řádek PŮVODNÍ firmy v běžící instanci
        // (cross-tenant propojení; u ON DELETE CASCADE i cross-tenant destrukce dat).
        // Skutečně globální tabulka (users, countries, vat_rates, units…) je jen ta, která
        // NENÍ tenant (dle supplier_id) A ZÁROVEŇ se vůbec neimportuje.
        $isGlobal = !($this->isTenant[$refTable] ?? false)
            && !$this->catalog->has($refTable);
        if ($isGlobal) {
            // id platí instančně (u users tím zůstává zachovaná auditní stopa
            // created_by/posted_by); cross-instance obnova vyžaduje tytéž globální
            // řádky, jinak FK spadne (rollback).
            return $old;
        }
        // Tenant tabulka (přímo nebo tranzitivně) — MUSÍ se remapovat přes mapu.
        if (isset($processedSet[$refTable])) {
            if (isset($this->maps[$refTable][$old])) {
                return $this->maps[$refTable][$old];
            }
            // rodič v archivu není (odfiltrován) → nullni nebo chyba
            if ($nullable) {
                $warnings[] = "{$table}.{$col}: odkaz #{$old} do {$refTable} bez rodiče v archivu → NULL";
                return null;
            }
            throw new RestoreException('dangling_fk', "{$table}.{$col}: povinný odkaz #{$old} do {$refTable} chybí v archivu.");
        }
        if ($this->catalog->has($refTable)) {
            // dopředná/cyklická reference → odlož na druhý průchod
            $defers[] = ['col' => $col, 'ref' => $refTable, 'old' => $old];
            return $nullable ? null : $this->anyId($refTable);
        }
        // per-tenant tabulka, která NENÍ v archivu (tax_submissions, offset_agreements…)
        if ($nullable) {
            $warnings[] = "{$table}.{$col}: odkaz na neexportovanou tabulku {$refTable} → NULL";
            return null;
        }
        throw new RestoreException('unmapped_ref', "{$table}.{$col}: povinný odkaz na neexportovanou {$refTable}.");
    }

    /**
     * `bank_statements` NENÍ tenant tabulka svým obsahem — je to celoinstanční
     * content-addressed dedup (UNIQUE `file_hash`), stejná sémantika jako
     * {@see \MyInvoice\Service\Bank\StatementImporter} / BankEmailNoticeRepository
     * (find-or-create podle hashe). Restore firmy do BĚŽÍCÍ instance, kde originální
     * (nebo jakákoli jiná) firma už tentýž soubor výpisu má naimportovaný, by na
     * INSERT jinak spadl na UNIQUE constraint — místo insertu se proto řádek se
     * shodným file_hash namapuje na existující id (žádná duplicita dat, sdílený
     * `bank_statements` řádek beztak neobsahuje nic tenant-specifického).
     *
     * @param array<string,mixed> $row
     * @param array<string,bool> $processedSet
     * @param list<string> $warnings (by-ref)
     */
    private function importBankStatement(array $row, int $target, array $processedSet, array &$warnings): int
    {
        $hash = (string) ($row['file_hash'] ?? '');
        if ($hash !== '') {
            $stmt = $this->db->pdo()->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
            $stmt->execute([$hash]);
            $existing = $stmt->fetchColumn();
            if ($existing !== false) {
                return (int) $existing;
            }
        }
        [$cols, $vals, $rowDefers] = $this->buildInsert('bank_statements', $row, $target, $processedSet, $warnings);
        if ($rowDefers !== []) {
            // bank_statements nemá tenantovou archivní FK kromě globálního imported_by
            // (users) — odložený FK by znamenal nečekanou schema změnu, zastav bezpečně.
            throw new RestoreException('unexpected_defer', 'bank_statements: neočekávaný odložený FK sloupec.');
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `bank_statements` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
        $this->db->pdo()->prepare($sql)->execute($vals);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Remap polymorfního journal_entries.source_id dle source_type.
     * Dokladové typy → příslušná mapa; závěrkové sloty → mapa období (dekódování slotů);
     * 'cash' se odkládá (cash_documents se importuje až po deníku).
     *
     * @param array<string,bool> $processedSet
     * @param list<array<string,mixed>> $defers (by-ref)
     */
    private function resolveSourceId(string $sourceType, mixed $val, array $processedSet, array &$defers): mixed
    {
        if ($val === null) {
            return null;
        }
        $old = (int) $val;

        $docMap = [
            'invoice' => 'invoices',
            'purchase_invoice' => 'purchase_invoices',
            'provision' => 'invoices',        // opravná položka k pohledávce → invoice id
            'bank' => 'bank_transactions',
            'asset' => 'assets',
            'asset_disposal' => 'assets',
            'depreciation' => 'depreciation_entries',
        ];
        if (isset($docMap[$sourceType])) {
            $ref = $docMap[$sourceType];
            if (isset($processedSet[$ref])) {
                return $this->maps[$ref][$old] ?? null;
            }
            $defers[] = ['col' => 'source_id', 'ref' => $ref, 'old' => $old, 'special' => 'source_id'];
            return null;
        }
        if ($sourceType === 'cash') {
            // cash_documents se importuje až po deníku → odlož
            $defers[] = ['col' => 'source_id', 'ref' => 'cash_documents', 'old' => $old, 'special' => 'source_id'];
            return null;
        }
        // závěrkové rodiny — source_id kóduje period_id (příp. slot)
        if (in_array($sourceType, ['opening', 'income_tax', 'profit_distribution'], true)) {
            return $this->maps['accounting_periods'][$old] ?? null; // plain period id
        }
        if ($sourceType === 'fx_revaluation') {
            return $this->remapPeriodSlot($old, 0);
        }
        if ($sourceType === 'closing') {
            if ($old >= ClosingSourceId::STOCK_SLOT_BASE) {
                return $this->remapPeriodSlot($old - ClosingSourceId::STOCK_SLOT_BASE, ClosingSourceId::STOCK_SLOT_BASE);
            }
            return $this->maps['accounting_periods'][$old] ?? null; // plain period id
        }
        // manual / offset (offset_agreements neexportováno) / neznámé → bez zdroje
        return null;
    }

    /** Dekóduje slot source_id (period*10+slot), remapuje period a znovu zakóduje (+base). */
    private function remapPeriodSlot(int $encoded, int $base): mixed
    {
        $oldPeriod = intdiv($encoded, 10);
        $slot = $encoded % 10;
        $newPeriod = $this->maps['accounting_periods'][$oldPeriod] ?? null;
        if ($newPeriod === null) {
            return null;
        }
        return $base + $newPeriod * 10 + $slot;
    }

    /** exchange_rates jsou globální — INSERT IGNORE bez remapu (dedup na přir. klíči). */
    private function insertExchangeRate(array $row): void
    {
        unset($row['id']);
        if ($row === []) {
            return;
        }
        $cols = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT IGNORE INTO `exchange_rates` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
        $this->db->pdo()->prepare($sql)->execute(array_map([$this, 'scalar'], array_values($row)));
    }

    /** Druhý průchod — dořeší odložené (dopředné/cyklické/self-ref) FK a source_id. */
    private function runDeferred(array &$warnings): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->deferred as $d) {
            $ref = (string) $d['ref'];
            $old = (int) $d['old'];
            $new = $this->maps[$ref][$old] ?? null;

            if ($new === null && ($d['special'] ?? '') === 'source_id') {
                $warnings[] = "journal_entries#{$d['newId']}.source_id: zdroj #{$old} ({$ref}) neobnoven → NULL";
            } elseif ($new === null) {
                $warnings[] = "{$d['table']}#{$d['newId']}.{$d['col']}: odkaz #{$old} do {$ref} neobnoven → NULL";
            }

            $sql = 'UPDATE `' . $d['table'] . '` SET `' . $d['col'] . '` = ? WHERE id = ?';
            $pdo->prepare($sql)->execute([$new, (int) $d['newId']]);
        }
    }

    /**
     * Zkopíruje binárky příloh deníku z archivu do úložiště NOVÉ firmy
     * (content-addressed layout storage/journal/sup-{new}/{sha0:2}/{filename}).
     *
     * @param array<string,mixed> $manifest
     * @param list<string> $warnings (by-ref)
     */
    private function copyAttachmentBinaries(array $manifest, string $tmpDir, int $oldSupplierId, int $newSupplierId, array &$warnings): void
    {
        $base = JournalAttachmentStorage::baseDir($newSupplierId);
        foreach (($manifest['files'] ?? []) as $zipName => $info) {
            if (($info['missing'] ?? false) === true) {
                $warnings[] = "{$zipName}: binárka chyběla už při exportu — neobnovena";
                continue;
            }
            $src = $tmpDir . '/' . $zipName;
            if (!is_file($src)) {
                $warnings[] = "{$zipName}: binárka v archivu chybí — neobnovena";
                continue;
            }
            $sha = (string) ($info['sha256'] ?? '');
            $filename = basename((string) $zipName);
            $dir = $base . '/' . substr($sha, 0, 2);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RestoreException('attach_dir', "Nelze vytvořit adresář příloh: {$dir}");
            }
            $dst = $dir . '/' . $filename;
            if (!is_file($dst) && !@copy($src, $dst)) {
                throw new RestoreException('attach_copy', "Nelze zkopírovat přílohu deníku: {$zipName}");
            }
        }
    }

    /**
     * Kontrola podvojnosti Σ MD = Σ D per účetní období (jen zaúčtované zápisy).
     *
     * @return array<int, array{period_id:int, debit:string, credit:string, diff:string}>
     */
    private function checkBalance(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT je.period_id,
                    SUM(CASE WHEN jel.side = 'debit'  THEN jel.amount ELSE 0 END) AS md,
                    SUM(CASE WHEN jel.side = 'credit' THEN jel.amount ELSE 0 END) AS d
               FROM journal_entries je
               JOIN journal_entry_lines jel ON jel.entry_id = je.id
              WHERE je.supplier_id = ? AND je.posted_at IS NOT NULL
              GROUP BY je.period_id"
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $md = (float) $r['md'];
            $d = (float) $r['d'];
            $out[(int) $r['period_id']] = [
                'period_id' => (int) $r['period_id'],
                'debit' => number_format($md, 2, '.', ''),
                'credit' => number_format($d, 2, '.', ''),
                'diff' => number_format($md - $d, 2, '.', ''),
            ];
        }
        return $out;
    }

    // ── schema introspekce ──────────────────────────────────────────────────

    /** Načte FK graf, nullability a per-tenant příznak pro dané tabulky (+ jejich ref cíle). */
    private function loadSchema(array $tables): void
    {
        $pdo = $this->db->pdo();

        // FK: table → col → referenced_table
        $stmt = $pdo->query(
            'SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $this->fkGraph[(string) $r['TABLE_NAME']][(string) $r['COLUMN_NAME']] = (string) $r['REFERENCED_TABLE_NAME'];
        }

        // nullability + generované sloupce
        $stmt = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, EXTRA
               FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
        );
        $tenantCols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (string) $r['TABLE_NAME'];
            $c = (string) $r['COLUMN_NAME'];
            $this->nullable[$t][$c] = ((string) $r['IS_NULLABLE']) === 'YES';
            if (str_contains((string) $r['EXTRA'], 'GENERATED')) {
                $this->generated[$t][$c] = true;
            }
            if ($c === 'supplier_id') {
                $tenantCols[$t] = true;
            }
        }
        $this->isTenant = $tenantCols;
    }

    /** @param list<string> $tables */
    private function assertKnownTables(array $tables): void
    {
        foreach ($tables as $t) {
            if (!is_string($t) || !$this->catalog->has($t)) {
                throw new RestoreException('unknown_table', "Archiv obsahuje neznámou tabulku '{$t}' — obnova zastavena (bezpečnost).");
            }
        }
    }

    private function anyId(string $table): mixed
    {
        try {
            $v = $this->db->pdo()->query('SELECT id FROM `' . $table . '` LIMIT 1')->fetchColumn();
            return $v === false ? null : (int) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private function scalar(mixed $val): mixed
    {
        if (is_bool($val)) {
            return $val ? 1 : 0;
        }
        if (is_array($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        return $val;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
