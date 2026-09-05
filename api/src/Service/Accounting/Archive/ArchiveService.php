<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Archive;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Closing\ClosingException;
use MyInvoice\Service\Backup\BackupZipPermissions;
use MyInvoice\Service\Document\JournalAttachmentStorage;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * ArchiveService — per-firma archivace účetních dat (Epic F4, §3.5/R15).
 *
 * Export = ZIP `myucto-archiv-sup{N}-{Ymd-His}.zip` v RuntimePaths::storage
 * ('archives/sup-{N}'): manifest.json (schema_version, app verze, supplier,
 * tabulka → {rows, sha256}) + per tabulka {table}.jsonl (JSON Lines, streamovaně
 * po dávkách 1000 řádků). Tenant izolace: každá tabulka se filtruje na supplier_id
 * (přímo, JOINem přes rodiče, nebo XOR FK u payment_matches). PDF/objektové binárky
 * NEJSOU součástí (kryje cron-backup-pdf) — BLOB sloupce bank_statements se vynechávají.
 *
 * Skutečnou obnovu (import jako NOVÁ firma s remapem id) řeší
 * {@see ArchiveRestoreService} + api/bin/archive-restore.php.
 */
final class ArchiveService
{
    /**
     * Verze formátu manifestu. v2 (audit 2026-07, Fáze F): přidán řádek `supplier`
     * (bez citlivých credential sloupců), pokladna, sklad, přílohy deníku (+binárky
     * v sekci `files`), platby a daň z příjmů; oproti v1 (jen deník/doklady/banka).
     */
    private const MANIFEST_VERSION = 2;

    /** Tabulky s přímým supplier_id (pořadí = pořadí exportu, respektuje FK). */
    private const DIRECT_TABLES = [
        'accounting_periods',
        'chart_of_accounts',
        'posting_rules',
        'bank_rule_templates',
        'cost_centers',
        'accounting_supplier_settings',
        'accounting_closing_steps',
        'accounting_document_series',
        'journal_entries',
        'journal_entry_lines',
        'clients',
        'client_bank_accounts',
        'currencies',
        'invoices',
        'purchase_invoices',
        'assets',
        'asset_improvements',
        'depreciation_entries',
        'payment_matches',
        // Audit 2026-07 (Fáze F) — rozšíření archivu na kompletní účetní stopu firmy:
        'cash_registers',            // pokladna (1019)
        'cash_documents',
        'invoice_payments',          // částečné úhrady faktur (0108)
        'income_tax_returns',        // daň z příjmů (1030)
        'tax_losses',                // daňová ztráta (1042)
        'tax_loss_applications',
        'tax_advance_schedules',     // zálohy na daň (1044)
        'journal_entry_attachments', // §33a přílohy zápisů (metadata; binárky viz files)
    ];

    /**
     * Skladové tabulky (SKLAD 1022/1028) — všechny nesou denormalizovaný supplier_id,
     * takže se filtrují přímo. Exportují se JEN u firem se `supplier.stock_enabled`.
     * Hodnota = 'id' (keyset PK) nebo řadicí klíč složeného PK (LIMIT/OFFSET).
     * Pořadí respektuje FK závislosti pro pozdější obnovu.
     */
    private const STOCK_TABLES = [
        'warehouses'                  => 'id',
        'stock_items'                 => 'id',
        'manufacturers'               => 'id',
        'stock_media'                 => 'id',
        'stock_categories'            => 'id',
        'stock_category_i18n'         => 'id',
        'stock_item_categories'       => 'stock_item_id, category_id',
        'stock_tags'                  => 'id',
        'stock_item_tags'             => 'stock_item_id, tag_id',
        'stock_attributes'            => 'id',
        'stock_attribute_options'     => 'id',
        'stock_attribute_i18n'        => 'id',
        'stock_item_attribute_values' => 'id',
        'stock_fee_types'             => 'id',
        'stock_item_fees'             => 'id',
        'stock_item_prices'           => 'id',
        'stock_item_vendors'          => 'id',
        'stock_item_i18n'             => 'id',
        'stock_levels'                => 'warehouse_id, stock_item_id',
        'stock_documents'             => 'id',
        'stock_document_lines'        => 'id',
        'stock_landed_costs'          => 'id',
        'stock_takes'                 => 'id',
        'stock_take_lines'            => 'id',
    ];

    /** BLOB sloupce mimo export (binárky kryje celoinstanční backup, R15). */
    private const EXCLUDED_COLUMNS = [
        'bank_statements' => ['file_content', 'pdf_content'],
    ];

    /**
     * Citlivé sloupce tabulky `supplier`, které se do přenositelného archivu NEexportují
     * (šifrované API/OAuth/TSA/cert credentials). Všechny jsou nullable → obnovený řádek
     * je dostane NULL a účetní je po obnově zadá znovu (bezpečnější default). Matchuje se
     * case-insensitive jako substring/suffix nad názvem sloupce.
     */
    private const SUPPLIER_SECRET_PATTERNS = [
        '_enc', 'password', 'secret', 'access_token', 'refresh_token',
        'api_key', 'client_id', 'private_key', 'ai_pseudo_salt',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Export archivu firmy. Vrací řádek metadat z accounting_archives.
     *
     * @return array<string,mixed>
     */
    public function export(int $supplierId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $supplier = $this->fetchSupplier($supplierId);
        if ($supplier === null) {
            throw new ClosingException('not_found', 'Firma #' . $supplierId . ' neexistuje.', 404);
        }

        $targetDir = RuntimePaths::storage('archives/sup-' . $supplierId);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Nelze vytvořit adresář archivu: ' . $targetDir);
        }
        $tmpDir = $targetDir . '/tmp-' . bin2hex(random_bytes(8));
        if (!mkdir($tmpDir, 0775, true)) {
            throw new \RuntimeException('Nelze vytvořit pracovní adresář: ' . $tmpDir);
        }

        $zipPath = null;
        try {
            // Konzistentní snapshot všech SELECTů (REPEATABLE READ v jedné transakci).
            $ownTx = !$pdo->inTransaction();
            if ($ownTx) {
                $pdo->beginTransaction();
            }
            try {
                $tables = [];
                foreach ($this->tableSpecs($supplierId) as $table => $spec) {
                    $tables[$table] = $this->exportTable($table, $spec, $tmpDir . '/' . $table . '.jsonl');
                }
                // §33a průkaznost: k přílohám deníku patří i samotné binárky (ne jen
                // metadata). Sbíráme distinct (sha256, filename) ještě ve snapshotu.
                $attachmentFiles = $this->collectJournalAttachments($supplierId);
                if ($ownTx) {
                    $pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // Binárky příloh deníku → sekce files (zip cesta, sha256, velikost).
            $files = [];
            foreach ($attachmentFiles as $att) {
                $diskPath = JournalAttachmentStorage::baseDir($supplierId)
                    . '/' . substr($att['sha256'], 0, 2) . '/' . $att['filename'];
                $zipName = 'files/journal/' . $att['filename'];
                if (!is_file($diskPath)) {
                    $files[$zipName] = ['sha256' => $att['sha256'], 'size_bytes' => 0, 'missing' => true];
                    $this->logger->warning('Příloha deníku chybí na disku — vynechána z archivu', [
                        'supplier_id' => $supplierId,
                        'sha256' => $att['sha256'],
                    ]);
                    continue;
                }
                $files[$zipName] = [
                    'sha256' => (string) hash_file('sha256', $diskPath),
                    'size_bytes' => (int) filesize($diskPath),
                    'disk_path' => $diskPath,
                ];
            }

            $exportedAt = date('Y-m-d H:i:s');
            $manifest = [
                'format' => 'myucto-archive',
                'version' => self::MANIFEST_VERSION,
                'schema_version' => $this->schemaVersion(),
                'app_version' => $this->appVersion(),
                'supplier' => [
                    'id' => (int) $supplier['id'],
                    'name' => (string) $supplier['company_name'],
                    'ico' => $supplier['ic'],
                ],
                'exported_at' => $exportedAt,
                'exported_by' => $userId,
                'note_pdf' => 'PDF binárky faktur nejsou součástí archivu — kryje je celoinstanční zálohování (cron-backup-pdf). Přílohy účetního deníku (§33a) jsou v sekci files.',
                'tables' => $tables,
                'files' => array_map(
                    static fn (array $f): array => array_diff_key($f, ['disk_path' => true]),
                    $files,
                ),
            ];
            file_put_contents(
                $tmpDir . '/manifest.json',
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );

            $filename = sprintf('myucto-archiv-sup%d-%s.zip', $supplierId, date('Ymd-His'));
            $zipPath = $targetDir . '/' . $filename;
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Nelze vytvořit ZIP: ' . $zipPath);
            }
            foreach (array_merge(['manifest.json'], array_map(static fn ($t) => $t . '.jsonl', array_keys($tables))) as $name) {
                $zip->addFile($tmpDir . '/' . $name, $name);
                $zip->setCompressionName($name, \ZipArchive::CM_DEFLATE);
                if (!BackupZipPermissions::neutralize($zip, $name)) {
                    throw new \RuntimeException('Nelze sjednotit práva položky archivu: ' . $name);
                }
            }
            foreach ($files as $zipName => $meta) {
                if (($meta['missing'] ?? false) === true || !isset($meta['disk_path'])) {
                    continue;
                }
                $zip->addFile($meta['disk_path'], $zipName);
                $zip->setCompressionName($zipName, \ZipArchive::CM_DEFLATE);
                if (!BackupZipPermissions::neutralize($zip, $zipName)) {
                    throw new \RuntimeException('Nelze sjednotit práva položky archivu: ' . $zipName);
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('ZIP se nepodařilo zapsat: ' . $zipPath);
            }

            $sha256 = hash_file('sha256', $zipPath);
            $size = (int) filesize($zipPath);
            $pdo->prepare(
                'INSERT INTO accounting_archives (supplier_id, filename, size_bytes, sha256, scope, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $filename,
                $size,
                $sha256,
                json_encode($tables, JSON_UNESCAPED_UNICODE),
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();

            $this->logger->info('Archiv firmy exportován', [
                'supplier_id' => $supplierId,
                'archive_id' => $id,
                'filename' => $filename,
                'size_bytes' => $size,
            ]);

            $row = $this->find($supplierId, $id);
            if ($row === null) {
                throw new \RuntimeException('Metadata archivu se nepodařilo načíst.');
            }
            return $row;
        } catch (\Throwable $e) {
            if ($zipPath !== null && is_file($zipPath)) {
                @unlink($zipPath);
            }
            throw $e;
        } finally {
            $this->removeDir($tmpDir);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, filename, size_bytes, sha256, scope, created_by, created_at
               FROM accounting_archives
              WHERE supplier_id = ?
              ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map(fn (array $r): array => $this->cast($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, filename, size_bytes, sha256, scope, created_by, created_at
               FROM accounting_archives
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($row);
    }

    /** Absolutní cesta k ZIP souboru archivu (pro streaming download v akci). */
    public function filePath(int $supplierId, array $archive): string
    {
        return RuntimePaths::storage('archives/sup-' . $supplierId) . '/' . basename((string) $archive['filename']);
    }

    /**
     * Smaže soubor + řádek metadat. Vrací smazaný řádek (audit) nebo null.
     *
     * Retenční brána (§ 31 ZoÚ) tu ZÁMĚRNĚ NENÍ. Archiv je EXPORT účetních dat, ne jejich
     * jediný nosič — zdrojové záznamy zůstávají v databázi a obnova archivu zakládá novou
     * firmu, nikoli nahrazuje původní. Blokovat mazání ZIPu by tedy nechránilo žádný účetní
     * záznam, jen by bránilo úklidu duplikátů. Povinnost uchovávat se vymáhá tam, kde
     * záznam skutečně zaniká — {@see \MyInvoice\Action\Invoice\DeleteInvoiceAction}.
     *
     * @return array<string,mixed>|null
     */
    public function delete(int $supplierId, int $id): ?array
    {
        $archive = $this->find($supplierId, $id);
        if ($archive === null) {
            return null;
        }
        $path = $this->filePath($supplierId, $archive);
        if (is_file($path)) {
            @unlink($path);
        }
        $this->db->pdo()
            ->prepare('DELETE FROM accounting_archives WHERE id = ? AND supplier_id = ?')
            ->execute([$id, $supplierId]);
        return $archive;
    }

    // ── interní ───────────────────────────────────────────────────────────────

    /**
     * Definice filtrů per tabulka: WHERE + params + řadicí/keyset klíč.
     * Tabulky bez supplier_id se filtrují JOINem přes rodiče (tenant izolace!);
     * bank_transactions/bank_statements přes payment_matches / matched_invoice_id;
     * exchange_rates (globální) na měny firmy a rozsah dat období.
     *
     * @return array<string, array{where:string, params:list<mixed>, pk:?string, order:string}>
     */
    private function tableSpecs(int $supplierId): array
    {
        $specs = [];
        // Vlastní master řádek firmy (bez credential sloupců, viz columnList) — nutný,
        // aby šla firma obnovit jako NOVÁ (ArchiveRestoreService). Filtr `id = supplier`.
        $specs['supplier'] = [
            'where' => 'id = ?',
            'params' => [$supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];
        foreach (self::DIRECT_TABLES as $table) {
            $specs[$table] = [
                'where' => 'supplier_id = ?',
                'params' => [$supplierId],
                'pk' => $table === 'accounting_supplier_settings' ? null : 'id',
                'order' => $table === 'accounting_supplier_settings' ? 'supplier_id' : 'id',
            ];
        }
        // Pokladní DPH řádky nemají supplier_id — filtr přes rodičovský cash_documents.
        $specs['cash_document_vat_lines'] = [
            'where' => 'cash_document_id IN (SELECT id FROM cash_documents WHERE supplier_id = ?)',
            'params' => [$supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];
        // Sklad (SKLAD 1022/1028) — jen u firem s opt-in modulem.
        if ($this->stockEnabled($supplierId)) {
            foreach (self::STOCK_TABLES as $table => $keyCols) {
                $hasId = $keyCols === 'id';
                $specs[$table] = [
                    'where' => 'supplier_id = ?',
                    'params' => [$supplierId],
                    'pk' => $hasId ? 'id' : null,
                    'order' => $keyCols,
                ];
            }
        }
        $specs['invoice_items'] = [
            'where' => 'invoice_id IN (SELECT id FROM invoices WHERE supplier_id = ?)',
            'params' => [$supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];
        $specs['purchase_invoice_items'] = [
            'where' => 'purchase_invoice_id IN (SELECT id FROM purchase_invoices WHERE supplier_id = ?)',
            'params' => [$supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];
        $txWhere = '(id IN (SELECT bank_transaction_id FROM payment_matches WHERE supplier_id = ?)'
            . ' OR matched_invoice_id IN (SELECT id FROM invoices WHERE supplier_id = ?)'
            . ' OR id IN (SELECT last_bank_transaction_id FROM client_bank_accounts'
            . ' WHERE supplier_id = ? AND last_bank_transaction_id IS NOT NULL))';
        $specs['bank_transactions'] = [
            'where' => $txWhere,
            'params' => [$supplierId, $supplierId, $supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];
        $specs['bank_statements'] = [
            'where' => 'id IN (SELECT statement_id FROM bank_transactions WHERE statement_id IS NOT NULL AND ' . $txWhere . ')',
            'params' => [$supplierId, $supplierId, $supplierId],
            'pk' => 'id',
            'order' => 'id',
        ];

        [$minDate, $maxDate] = $this->periodRange($supplierId);
        if ($minDate !== null && $maxDate !== null) {
            $specs['exchange_rates'] = [
                'where' => 'currency_code IN (SELECT code FROM currencies WHERE supplier_id = ?) AND rate_date BETWEEN ? AND ?',
                'params' => [$supplierId, $minDate, $maxDate],
                'pk' => null,
                'order' => 'rate_date, currency_code',
            ];
        } else {
            $specs['exchange_rates'] = [
                'where' => '1 = 0',
                'params' => [],
                'pk' => null,
                'order' => 'rate_date, currency_code',
            ];
        }
        return $specs;
    }

    /**
     * Streamovaný export tabulky do JSONL (dávky po 1000: keyset přes PK, jinak
     * LIMIT/OFFSET). Vrací {rows, sha256} pro manifest.
     *
     * @param array{where:string, params:list<mixed>, pk:?string, order:string} $spec
     * @return array{rows:int, sha256:string}
     */
    private function exportTable(string $table, array $spec, string $filePath): array
    {
        $pdo = $this->db->pdo();
        $columns = $this->columnList($table);
        $fh = fopen($filePath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Nelze zapsat ' . $filePath);
        }
        $hash = hash_init('sha256');
        $rows = 0;
        try {
            $lastId = null;
            $offset = 0;
            while (true) {
                if ($spec['pk'] !== null) {
                    $sql = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $spec['where']
                        . ($lastId !== null ? ' AND ' . $spec['pk'] . ' > ?' : '')
                        . ' ORDER BY ' . $spec['pk'] . ' LIMIT 1000';
                    $params = $lastId !== null ? array_merge($spec['params'], [$lastId]) : $spec['params'];
                } else {
                    $sql = 'SELECT ' . $columns . ' FROM ' . $table . ' WHERE ' . $spec['where']
                        . ' ORDER BY ' . $spec['order'] . ' LIMIT 1000 OFFSET ' . $offset;
                    $params = $spec['params'];
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($batch === []) {
                    break;
                }
                foreach ($batch as $row) {
                    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
                    if ($line === false) {
                        throw new \RuntimeException('JSON encode selhal pro ' . $table . ' (řádek ' . ($rows + 1) . ').');
                    }
                    $line .= "\n";
                    fwrite($fh, $line);
                    hash_update($hash, $line);
                    $rows++;
                }
                if ($spec['pk'] !== null) {
                    $lastId = $batch[count($batch) - 1][$spec['pk']];
                } else {
                    $offset += count($batch);
                }
                if (count($batch) < 1000) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }
        return ['rows' => $rows, 'sha256' => hash_final($hash)];
    }

    /** Explicitní seznam sloupců (bez vyloučených BLOBů / credentials), jinak `*`. */
    private function columnList(string $table): string
    {
        $excluded = self::EXCLUDED_COLUMNS[$table] ?? [];
        $filterSecrets = $table === 'supplier';
        if ($excluded === [] && !$filterSecrets) {
            return '*';
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
              ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$table]);
        $cols = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) {
            if (in_array($col, $excluded, true)) {
                continue;
            }
            if ($filterSecrets && $this->isSupplierSecret((string) $col)) {
                continue;
            }
            $cols[] = '`' . $col . '`';
        }
        return implode(', ', $cols);
    }

    /** Je název sloupce supplier tabulky citlivý (credential) → vynechat z archivu. */
    private function isSupplierSecret(string $column): bool
    {
        $lower = strtolower($column);
        foreach (self::SUPPLIER_SECRET_PATTERNS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Má firma zapnutý skladový modul (opt-in, SKLAD 1023)? */
    private function stockEnabled(int $supplierId): bool
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT stock_enabled FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Distinct (sha256, filename) příloh deníku firmy — content-addressed, takže
     * jeden fyzický soubor (sdílený víc zápisy dedupem) exportujeme jen jednou.
     *
     * @return list<array{sha256:string, filename:string}>
     */
    private function collectJournalAttachments(int $supplierId): array
    {
        try {
            $stmt = $this->db->pdo()->prepare(
                'SELECT DISTINCT sha256, filename FROM journal_entry_attachments WHERE supplier_id = ?'
            );
            $stmt->execute([$supplierId]);
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = ['sha256' => (string) $row['sha256'], 'filename' => (string) $row['filename']];
        }
        return $out;
    }

    /** @return array{0:?string,1:?string} */
    private function periodRange(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(starts_on), MAX(ends_on) FROM accounting_periods WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if ($row === false || $row[0] === null) {
            return [null, null];
        }
        return [(string) $row[0], (string) $row[1]];
    }

    /** Nejvyšší aplikovaná migrace (schema_version manifestu). */
    private function schemaVersion(): string
    {
        try {
            $version = $this->db->pdo()->query('SELECT MAX(filename) FROM migrations')->fetchColumn();
            return $version === false || $version === null ? 'unknown' : (string) $version;
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** Verze aplikace z VERSION souboru v rootu instalace (je-li). */
    private function appVersion(): ?string
    {
        foreach ([\MyInvoice\Bootstrap::rootDir() . '/VERSION', dirname(\MyInvoice\Bootstrap::rootDir()) . '/VERSION'] as $path) {
            if (is_file($path)) {
                $content = trim((string) file_get_contents($path));
                return $content === '' ? null : $content;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function fetchSupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, company_name, ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function cast(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['supplier_id'] = (int) $r['supplier_id'];
        $r['size_bytes'] = (int) $r['size_bytes'];
        $r['created_by'] = $r['created_by'] === null ? null : (int) $r['created_by'];
        if (is_string($r['scope'] ?? null)) {
            $decoded = json_decode($r['scope'], true);
            $r['scope'] = is_array($decoded) ? $decoded : null;
        }
        return $r;
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
            @unlink($dir . '/' . $item);
        }
        @rmdir($dir);
    }
}
