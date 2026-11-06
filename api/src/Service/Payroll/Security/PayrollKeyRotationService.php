<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Security;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Payroll\Document\AnnualSettlementSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\AnnualTaxCertificateSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\AverageEarningsSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\EmploymentExitSnapshotBuilder;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKeyRing;
use MyInvoice\Service\Payroll\Document\PayrollDocumentKind;
use MyInvoice\Service\Payroll\Document\PayrollSheetSnapshotBuilder;
use MyInvoice\Service\Payroll\Export\PayrollPeriodExportStorage;
use MyInvoice\Service\Payroll\Garnishment\Xmlzam\XmlzamCooperationArtifactStore;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentBatchBuilder;
use MyInvoice\Service\Payroll\Payment\PayrollPaymentExportStorage;
use MyInvoice\Service\Payroll\Submission\Eldp\EldpStatementService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzEldpEvidenceSnapshotService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotService;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationEventService;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshotService;
use MyInvoice\Service\Payroll\Submission\Regzel\EmployerRegistrationService;
use PDO;

/**
 * Rotace master klíče mzdových dat: přebalení všech `enc:v2:` hodnot na
 * aktuální `app.secret_encryption_key`.
 *
 * ── Postup rotace ───────────────────────────────────────────────────────────
 * 1. Do `app.secret_encryption_key` přijde nový klíč, dosavadní se přesune do
 *    `app.secret_encryption_previous_keys`. Od té chvíle se zapisuje novým
 *    klíčem a staré hodnoty jdou dál číst (identifikátor klíče je součástí
 *    ciphertextu, {@see SecretEncryption::decryptFor()}).
 * 2. {@see rewrapAll()} přebalí vše, co ještě nese starý klíč.
 * 3. Až {@see status()} hlásí nulu, starý klíč se z konfigurace odebere.
 *
 * ── Co se přebaluje ─────────────────────────────────────────────────────────
 * - sloupce v tabulkách z {@see targets()}: kontext (AAD) každé hodnoty se
 *   skládá z řádku voláním TÉŽE funkce, kterou hodnotu zapisuje a čte její
 *   vlastník, takže se tu žádný vzorec neopisuje. Kdyby se přesto rozešel,
 *   nic se nezkazí: AES-GCM s jiným AAD nedešifruje a hodnota skončí mezi
 *   selháními, ne přepsaná;
 * - datové klíče mzdových dokumentů ({@see PayrollDocumentKeyRing::rewrapAll()}):
 *   samotné PDF jsou zašifrované DEKem, ne master klíčem, takže se nepřepisují;
 * - soubory měsíčních a platebních exportů (`payroll-period-exports`,
 *   `payroll-payment-exports`), které jsou šifrované master klíčem přímo.
 *
 * Zápis je vždy compare-and-swap na původní ciphertext. Append-only tabulky
 * pustí změnu jen přes adresnou výjimku z migrace 1915 (session proměnná
 * `@payroll_key_rewrap_<tabulka>` pro konkrétní řádek); `updated_at` ani
 * `row_version` se nemění, protože se nemění obsah.
 */
final class PayrollKeyRotationService
{
    private const STALE_PATTERN = 'enc:v2:________________:%';
    private const BATCH = 50;

    /** @var array<string,true> neznámé id klíčů posledního {@see status()} */
    private array $unknownKeyIds = [];

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $encryption,
        private readonly PayrollDocumentKeyRing $documentKeys,
    ) {}

    /**
     * Kolik hodnot ještě nese jiný než aktuální klíč, a kolik z nich klíčem,
     * který v konfiguraci vůbec není (takové hodnoty nejdou přečíst).
     *
     * @return array{
     *   current_key_id:string,
     *   stale_total:int,
     *   unknown_total:int,
     *   targets:list<array{name:string,stale:int,unknown:int}>,
     *   unknown_key_ids:list<string>
     * }
     */
    public function status(?int $supplierId = null): array
    {
        $this->unknownKeyIds = [];
        $current = $this->encryption->currentKeyId();
        $targets = [];
        $staleTotal = 0;
        $unknownTotal = 0;

        foreach (self::targets() as $target) {
            if (!$this->db->hasTable($target['table'])) {
                continue;
            }
            $column = $target['column'];
            $sql = "SELECT SUBSTRING({$column}, 8, 16) AS key_id, COUNT(*) AS cnt
                      FROM {$target['table']}
                     WHERE {$column} LIKE ? AND {$column} NOT LIKE ?"
                . ($supplierId === null ? '' : ' AND supplier_id = ?')
                . ' GROUP BY key_id';
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute(array_merge(
                [self::STALE_PATTERN, 'enc:v2:' . $current . ':%'],
                $supplierId === null ? [] : [$supplierId],
            ));
            $this->tally(
                $target['table'] . '.' . $column,
                $stmt->fetchAll(PDO::FETCH_KEY_PAIR),
                $targets,
                $staleTotal,
                $unknownTotal,
            );
        }

        $keys = $this->db->pdo()->prepare(
            'SELECT SUBSTRING(wrapped_key, 8, 16) AS key_id, COUNT(*) AS cnt
               FROM payroll_document_data_keys
              WHERE destroyed_at IS NULL AND wrapped_key LIKE ? AND wrapped_key NOT LIKE ?'
                . ($supplierId === null ? '' : ' AND supplier_id = ?')
                . ' GROUP BY key_id',
        );
        $keys->execute(array_merge(
            [self::STALE_PATTERN, 'enc:v2:' . $current . ':%'],
            $supplierId === null ? [] : [$supplierId],
        ));
        $this->tally(
            'payroll_document_data_keys.wrapped_key',
            $keys->fetchAll(PDO::FETCH_KEY_PAIR),
            $targets,
            $staleTotal,
            $unknownTotal,
        );

        foreach (self::fileStores() as $name => $store) {
            $byKey = [];
            foreach ($this->storeFiles($store['root'], $supplierId) as $file) {
                $keyId = $this->encryption->keyIdOf((string) @file_get_contents($file['path'], false, null, 0, 64));
                if ($keyId !== null && $keyId !== $current) {
                    $byKey[$keyId] = ($byKey[$keyId] ?? 0) + 1;
                }
            }
            $this->tally($name, $byKey, $targets, $staleTotal, $unknownTotal);
        }

        return [
            'current_key_id' => $current,
            'stale_total' => $staleTotal,
            'unknown_total' => $unknownTotal,
            'targets' => $targets,
            // Id klíčů (ne klíče) — podle nich Diagnostika pozná, že uložené
            // měření zastaralo, když správce chybějící klíč doplnil.
            'unknown_key_ids' => array_map('strval', array_keys($this->unknownKeyIds)),
        ];
    }

    /** Zná konfigurace (aktuální nebo předchozí klíče) klíč s tímto id? */
    public function isKnownKeyId(string $keyId): bool
    {
        return $this->encryption->hasKeyId($keyId);
    }

    /**
     * Levná kontrola, zda data nenesou klíč, který konfigurace nezná.
     *
     * Plný průchod {@see status()} se nevyplatí pouštět při každém otevření
     * Diagnostiky, jenže nebezpečný je právě stav BEZ rozpracované rotace:
     * správce vymění klíč a starý do `previous_keys` nedá, nebo obnoví
     * databázi z jiné instance. Proto se vždy změří:
     *
     * - všechny datové klíče dokumentů (`payroll_document_data_keys` je malá),
     * - u každé šifrované tabulky jen NEJSTARŠÍ a NEJNOVĚJŠÍ šifrovaný řádek
     *   (dva dotazy po primárním klíči).
     *
     * Tím se chytí obě typické chyby: výměna klíče bez předchozího (neznámé
     * jsou nejstarší řádky) i cizí databáze (neznámé je všechno). Soubory
     * exportů se tu nečtou.
     *
     * @return array{
     *   current_key_id:string,
     *   sampled:int,
     *   unknown_total:int,
     *   unknown_key_ids:list<string>,
     *   targets:list<array{name:string,unknown:int}>
     * }
     */
    public function quickStatus(): array
    {
        $unknownKeyIds = [];
        $targets = [];
        $sampled = 0;
        $unknownTotal = 0;
        $record = function (string $name, array $keyIds) use (&$unknownKeyIds, &$targets, &$sampled, &$unknownTotal): void {
            $unknown = 0;
            foreach ($keyIds as $keyId) {
                ++$sampled;
                if (!$this->encryption->hasKeyId($keyId)) {
                    ++$unknown;
                    $unknownKeyIds[$keyId] = true;
                }
            }
            if ($unknown > 0) {
                $targets[] = ['name' => $name, 'unknown' => $unknown];
                $unknownTotal += $unknown;
            }
        };

        if ($this->db->hasTable('payroll_document_data_keys')) {
            $keys = $this->db->pdo()->prepare(
                'SELECT DISTINCT SUBSTRING(wrapped_key, 8, 16)
                   FROM payroll_document_data_keys
                  WHERE destroyed_at IS NULL AND wrapped_key LIKE ?',
            );
            $keys->execute([self::STALE_PATTERN]);
            $record('payroll_document_data_keys.wrapped_key', array_map('strval', $keys->fetchAll(PDO::FETCH_COLUMN)));
        }

        foreach (self::targets() as $target) {
            if (!$this->db->hasTable($target['table'])) {
                continue;
            }
            $column = $target['column'];
            $keyIds = [];
            foreach (['ASC', 'DESC'] as $direction) {
                $stmt = $this->db->pdo()->prepare(
                    "SELECT SUBSTRING({$column}, 8, 16) FROM {$target['table']}
                      WHERE {$column} LIKE ? ORDER BY id {$direction} LIMIT 1",
                );
                $stmt->execute([self::STALE_PATTERN]);
                $keyId = $stmt->fetchColumn();
                if (is_string($keyId) && $keyId !== '') {
                    $keyIds[] = $keyId;
                }
            }
            $record($target['table'] . '.' . $column, array_values(array_unique($keyIds)));
        }

        return [
            'current_key_id' => $this->encryption->currentKeyId(),
            'sampled' => $sampled,
            'unknown_total' => $unknownTotal,
            // Hexadecimální id z číslic by se jako klíč pole změnilo na int.
            'unknown_key_ids' => array_map('strval', array_keys($unknownKeyIds)),
            'targets' => $targets,
        ];
    }

    /**
     * @return array{
     *   dry_run:bool,
     *   rewrapped:int,
     *   would_rewrap:int,
     *   failed:int,
     *   limit_reached:bool,
     *   targets:list<array{name:string,rewrapped:int,would_rewrap:int,failed:int}>
     * }
     */
    public function rewrapAll(
        ?int $supplierId = null,
        bool $dryRun = true,
        ?int $limit = null,
    ): array {
        if ($limit !== null && $limit <= 0) {
            throw new \InvalidArgumentException('Limit must be positive.');
        }
        $result = [
            'dry_run' => $dryRun,
            'rewrapped' => 0,
            'would_rewrap' => 0,
            'failed' => 0,
            'limit_reached' => false,
            'targets' => [],
        ];

        $keys = $this->documentKeys->rewrapAll($supplierId, $dryRun);
        $this->record($result, 'payroll_document_data_keys.wrapped_key', $keys['rewrapped'], $keys['would_rewrap'], $keys['failed']);

        foreach (self::targets() as $target) {
            if ($this->budgetLeft($result, $limit) === 0) {
                $result['limit_reached'] = true;
                break;
            }
            if (!$this->db->hasTable($target['table'])) {
                continue;
            }
            [$done, $would, $failed] = $this->rewrapTarget(
                $target,
                $supplierId,
                $dryRun,
                $this->budgetLeft($result, $limit),
            );
            $this->record($result, $target['table'] . '.' . $target['column'], $done, $would, $failed);
        }

        foreach (self::fileStores() as $name => $store) {
            if ($this->budgetLeft($result, $limit) === 0) {
                $result['limit_reached'] = true;
                break;
            }
            [$done, $would, $failed] = $this->rewrapFiles(
                $store,
                $supplierId,
                $dryRun,
                $this->budgetLeft($result, $limit),
            );
            $this->record($result, $name, $done, $would, $failed);
        }

        return $result;
    }

    /**
     * @param array{table:string,column:string,context:callable(array<string,mixed>):string,guard:?string,updated_at:bool} $target
     * @return array{0:int,1:int,2:int}
     */
    private function rewrapTarget(array $target, ?int $supplierId, bool $dryRun, ?int $budget): array
    {
        $pdo = $this->db->pdo();
        $table = $target['table'];
        $column = $target['column'];
        $current = $this->encryption->currentKeyId();
        $update = $pdo->prepare(
            "UPDATE {$table} SET {$column} = ?"
                . ($target['updated_at'] ? ', updated_at = updated_at' : '')
                . " WHERE id = ? AND supplier_id = ? AND {$column} = ?",
        );
        $done = $would = $failed = 0;
        $lastId = 0;
        do {
            $select = $pdo->prepare(
                "SELECT * FROM {$table}
                  WHERE id > ? AND {$column} LIKE ? AND {$column} NOT LIKE ?"
                    . ($supplierId === null ? '' : ' AND supplier_id = ?')
                    . ' ORDER BY id LIMIT ' . self::BATCH,
            );
            $select->execute(array_merge(
                [$lastId, self::STALE_PATTERN, 'enc:v2:' . $current . ':%'],
                $supplierId === null ? [] : [$supplierId],
            ));
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                if ($budget !== null && $done + $would + $failed >= $budget) {
                    return [$done, $would, $failed];
                }
                $old = (string) $row[$column];
                try {
                    $new = $this->encryption->rewrapFor($old, ($target['context'])($row));
                    if ($new === null) {
                        continue;
                    }
                    if ($dryRun) {
                        ++$would;
                        continue;
                    }
                    $this->guarded($target['guard'], $lastId, static function () use ($update, $new, $row, $old): void {
                        $update->execute([$new, (int) $row['id'], (int) $row['supplier_id'], $old]);
                    });
                    $update->rowCount() === 1 ? ++$done : ++$failed;
                } catch (\Throwable) {
                    ++$failed;
                }
            }
        } while (count($rows) === self::BATCH);

        return [$done, $would, $failed];
    }

    /**
     * Session proměnná pro adresnou výjimku z append-only triggeru (migrace
     * 1915) platí jen po dobu jednoho UPDATE a jen pro jeden řádek.
     */
    private function guarded(?string $variable, int $id, callable $update): void
    {
        if ($variable === null) {
            $update();

            return;
        }
        if (preg_match('/^payroll_key_rewrap_[a-z0-9_]{1,45}$/D', $variable) !== 1) {
            throw new \LogicException('Invalid rewrap guard variable.');
        }
        $pdo = $this->db->pdo();
        $pdo->prepare("SET @{$variable} = CAST(? AS UNSIGNED)")->execute([$id]);
        try {
            $update();
        } finally {
            $pdo->exec("SET @{$variable} = NULL");
        }
    }

    /**
     * Přepíše soubor exportu přebaleným ciphertextem: dočasný soubor,
     * přejmenování přes originál, znovu přečíst a ověřit. Když ověření po
     * zápisu selže, vrátí se původní ciphertext (je pořád v paměti).
     *
     * @param array{root:string,context:callable(int,string):string} $store
     * @return array{0:int,1:int,2:int}
     */
    private function rewrapFiles(array $store, ?int $supplierId, bool $dryRun, ?int $budget): array
    {
        $done = $would = $failed = 0;
        foreach ($this->storeFiles($store['root'], $supplierId) as $file) {
            if ($budget !== null && $done + $would + $failed >= $budget) {
                break;
            }
            $old = @file_get_contents($file['path']);
            if (!is_string($old)) {
                ++$failed;
                continue;
            }
            $context = ($store['context'])($file['supplier_id'], $file['storage_key']);
            try {
                $new = $this->encryption->rewrapFor($old, $context);
                if ($new === null) {
                    continue;
                }
                if (!hash_equals($file['storage_key'], hash('sha256', $this->encryption->decryptFor($new, $context)))) {
                    throw new \RuntimeException('Rewrapped export does not match its storage key.');
                }
                if ($dryRun) {
                    ++$would;
                    continue;
                }
                $this->replaceFile($file['path'], $new);
                try {
                    $check = (string) file_get_contents($file['path']);
                    if (!hash_equals($file['storage_key'], hash('sha256', $this->encryption->decryptFor($check, $context)))) {
                        throw new \RuntimeException('Rewritten export failed verification.');
                    }
                } catch (\Throwable $e) {
                    $this->replaceFile($file['path'], $old);
                    throw $e;
                }
                ++$done;
            } catch (\Throwable) {
                ++$failed;
            }
        }

        return [$done, $would, $failed];
    }

    private function replaceFile(string $path, string $contents): void
    {
        $tmp = dirname($path) . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(12));
        try {
            if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents)) {
                throw new \RuntimeException('Temporary export could not be written.');
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('Export could not be replaced.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Soubory úložiště `{root}/sup-{id}/{hh}/{sha256}`. Symbolické odkazy se
     * přeskakují a každá cesta musí po `realpath()` ležet pod kořenem -
     * porovnání bez ohledu na velikost písmen, protože Windows vrací casing
     * podle disku, ne podle zadání.
     *
     * @return \Generator<int,array{path:string,supplier_id:int,storage_key:string}>
     */
    private function storeFiles(string $root, ?int $supplierId): \Generator
    {
        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return;
        }
        foreach (scandir($realRoot) ?: [] as $supplierDir) {
            if (preg_match('/^sup-([1-9][0-9]{0,9})$/D', $supplierDir, $match) !== 1) {
                continue;
            }
            $sid = (int) $match[1];
            if ($supplierId !== null && $sid !== $supplierId) {
                continue;
            }
            $base = $realRoot . DIRECTORY_SEPARATOR . $supplierDir;
            if (is_link($base) || !is_dir($base)) {
                continue;
            }
            foreach (scandir($base) ?: [] as $shard) {
                if (preg_match('/^[a-f0-9]{2}$/D', $shard) !== 1) {
                    continue;
                }
                $dir = $base . DIRECTORY_SEPARATOR . $shard;
                if (is_link($dir) || !is_dir($dir)) {
                    continue;
                }
                foreach (scandir($dir) ?: [] as $name) {
                    if (preg_match('/^[a-f0-9]{64}$/D', $name) !== 1 || !str_starts_with($name, $shard)) {
                        continue;
                    }
                    $path = $dir . DIRECTORY_SEPARATOR . $name;
                    $real = realpath($path);
                    if (is_link($path) || $real === false || !is_file($real) || !self::inside($real, $realRoot)) {
                        continue;
                    }
                    yield ['path' => $real, 'supplier_id' => $sid, 'storage_key' => $name];
                }
            }
        }
    }

    private static function inside(string $path, string $base): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $base = strtolower(rtrim(str_replace('\\', '/', $base), '/'));

        return str_starts_with($path, $base . '/');
    }

    /**
     * @param array<string,int|string> $byKey
     * @param list<array{name:string,stale:int,unknown:int}> $targets
     */
    private function tally(string $name, array $byKey, array &$targets, int &$staleTotal, int &$unknownTotal): void
    {
        $stale = 0;
        $unknown = 0;
        foreach ($byKey as $keyId => $count) {
            $stale += (int) $count;
            if (!$this->encryption->hasKeyId((string) $keyId)) {
                $unknown += (int) $count;
                $this->unknownKeyIds[(string) $keyId] = true;
            }
        }
        $targets[] = ['name' => $name, 'stale' => $stale, 'unknown' => $unknown];
        $staleTotal += $stale;
        $unknownTotal += $unknown;
    }

    /** @param array<string,mixed> $result */
    private function record(array &$result, string $name, int $done, int $would, int $failed): void
    {
        $result['targets'][] = [
            'name' => $name,
            'rewrapped' => $done,
            'would_rewrap' => $would,
            'failed' => $failed,
        ];
        $result['rewrapped'] += $done;
        $result['would_rewrap'] += $would;
        $result['failed'] += $failed;
    }

    /** @param array<string,mixed> $result */
    private function budgetLeft(array $result, ?int $limit): ?int
    {
        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $result['rewrapped'] - $result['would_rewrap'] - $result['failed']);
    }

    /** @return array<string,array{root:string,context:callable(int,string):string}> */
    private static function fileStores(): array
    {
        return [
            'payroll-period-exports' => [
                'root' => RuntimePaths::storage('payroll-period-exports'),
                'context' => PayrollPeriodExportStorage::context(...),
            ],
            'payroll-payment-exports' => [
                'root' => RuntimePaths::storage('payroll-payment-exports'),
                'context' => PayrollPaymentExportStorage::context(...),
            ],
        ];
    }

    /**
     * Mzdové sloupce šifrované master klíčem (a další zapečetěné stejnou
     * primitivou, např. rodná čísla daňového profilu). Kontext každé hodnoty
     * skládá funkce jejího vlastníka; výčet odpovídá inventáři citlivých sloupců
     * v `AnonymizationPolicy` a hlídá ho `PayrollKeyRotationCatalogTest`.
     *
     * @return list<array{
     *   table:string,
     *   column:string,
     *   context:callable(array<string,mixed>):string,
     *   guard:?string,
     *   updated_at:bool
     * }>
     */
    public static function targets(): array
    {
        $sealed = static fn (PayrollSensitiveField $field, string $entity = 'id'): callable
            => static fn (array $r): string => PayrollSensitiveData::context($field, (int) $r['supplier_id'], (int) $r[$entity]);
        $nullableInt = static fn (mixed $v): ?int => $v === null ? null : (int) $v;
        $nullableString = static fn (mixed $v): ?string => $v === null ? null : (string) $v;

        return [
            [
                'table' => 'payroll_dependants',
                'column' => 'birth_number_ciphertext',
                'context' => $sealed(PayrollSensitiveField::PERSONAL_IDENTIFIER),
                'guard' => null,
                'updated_at' => true,
            ],
            // Rodná čísla rodiny z daňového profilu (H28) pečetí tatáž primitiva
            // master klíčem, takže rotace musí jít i přes ně. Tabulky nemají
            // `updated_at`.
            [
                'table' => 'tax_profile_children',
                'column' => 'birth_number_ciphertext',
                'context' => $sealed(PayrollSensitiveField::TAX_FAMILY_IDENTIFIER),
                'guard' => null,
                'updated_at' => false,
            ],
            [
                'table' => 'tax_profile_spouse_claims',
                'column' => 'birth_number_ciphertext',
                'context' => $sealed(PayrollSensitiveField::TAX_FAMILY_IDENTIFIER),
                'guard' => null,
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_person_tax_child_claims',
                'column' => 'other_caregiver_birth_number_ciphertext',
                'context' => $sealed(PayrollSensitiveField::PERSONAL_IDENTIFIER),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_person_contacts',
                'column' => 'contact_value_ciphertext',
                'context' => static fn (array $r): string => PayrollSensitiveData::context(
                    $r['contact_type'] === 'email'
                        ? PayrollSensitiveField::CONTACT_EMAIL
                        : PayrollSensitiveField::CONTACT_PHONE,
                    (int) $r['supplier_id'],
                    (int) $r['id'],
                ),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_person_identifiers',
                'column' => 'value_ciphertext',
                'context' => static fn (array $r): string => PayrollSensitiveData::context(
                    $r['identifier_type'] === 'foreign_tax_identifier'
                        ? PayrollSensitiveField::FOREIGN_TAX_IDENTIFIER
                        : PayrollSensitiveField::PERSONAL_IDENTIFIER,
                    (int) $r['supplier_id'],
                    (int) $r['id'],
                ),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_person_accounts',
                'column' => 'bank_account_ciphertext',
                'context' => $sealed(PayrollSensitiveField::BANK_ACCOUNT),
                // Bez výjimky by trigger při změně ciphertextu smazal ověření účtu.
                'guard' => 'payroll_key_rewrap_person_accounts',
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_institution_accounts',
                'column' => 'bank_account_ciphertext',
                'context' => $sealed(PayrollSensitiveField::BANK_ACCOUNT),
                'guard' => 'payroll_key_rewrap_institution_accounts',
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_person_external_ids',
                'column' => 'value_ciphertext',
                'context' => $sealed(PayrollSensitiveField::PERSON_EXTERNAL_IDENTIFIER),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_employment_external_ids',
                'column' => 'value_ciphertext',
                'context' => $sealed(PayrollSensitiveField::EMPLOYMENT_EXTERNAL_IDENTIFIER),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_registration_a1_profiles',
                'column' => 'profile_ciphertext',
                'context' => $sealed(PayrollSensitiveField::REGISTRATION_A1_PROFILE, 'employment_id'),
                'guard' => 'payroll_key_rewrap_registration_a1_profiles',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_annual_document_revisions',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => match ((string) $r['purpose']) {
                    PayrollSheetSnapshotBuilder::PURPOSE => PayrollSheetSnapshotBuilder::encryptionContext(
                        (int) $r['supplier_id'],
                        (int) $r['employee_id'],
                        (int) $r['tax_year'],
                        (string) $r['source_manifest_hash'],
                    ),
                    AnnualSettlementSnapshotBuilder::PURPOSE => AnnualSettlementSnapshotBuilder::encryptionContext(
                        (int) $r['supplier_id'],
                        (int) $r['employee_id'],
                        (int) $r['tax_year'],
                        (string) $r['source_manifest_hash'],
                    ),
                    default => AnnualTaxCertificateSnapshotBuilder::encryptionContext(
                        (int) $r['supplier_id'],
                        (int) $r['employee_id'],
                        (int) $r['tax_year'],
                        PayrollDocumentKind::from((string) $r['purpose']),
                        (string) $r['source_manifest_hash'],
                    ),
                },
                'guard' => 'payroll_key_rewrap_annual_document_revisions',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_employment_exit_revisions',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => (string) $r['purpose'] === 'employment_certificate'
                    ? EmploymentExitSnapshotBuilder::encryptionContext(
                        (int) $r['supplier_id'],
                        (int) $r['employee_id'],
                        (int) $r['employment_id'],
                        (string) $r['source_manifest_hash'],
                    )
                    : AverageEarningsSnapshotBuilder::encryptionContext(
                        (int) $r['supplier_id'],
                        (int) $r['employee_id'],
                        (int) $r['employment_id'],
                        (string) $r['purpose'],
                        (string) $r['source_manifest_hash'],
                    ),
                'guard' => 'payroll_key_rewrap_employment_exit_revisions',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_payment_items',
                'column' => 'instruction_ciphertext',
                'context' => static fn (array $r): string => PayrollPaymentBatchBuilder::itemContext(
                    (int) $r['supplier_id'],
                    (string) $r['item_reference'],
                ),
                'guard' => 'payroll_key_rewrap_payment_items',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_payment_batches',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => PayrollPaymentBatchBuilder::batchContext(
                    (int) $r['supplier_id'],
                    (string) $r['batch_reference'],
                ),
                'guard' => 'payroll_key_rewrap_payment_batches',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_submission_artifacts',
                'column' => 'content_ciphertext',
                'context' => static fn (array $r): string => PayrollSubmissionService::artifactContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['submission_id'],
                    $nullableInt($r['part_id']),
                    (string) $r['artifact_kind'],
                    (string) $r['direction'],
                    (string) $r['mime_type'],
                    (string) $r['artifact_sha256'],
                    $nullableString($r['xsd_version']),
                    $nullableString($r['catalog_version']),
                    (string) $r['channel'],
                ),
                'guard' => 'payroll_key_rewrap_submission_artifacts',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_jmhz_protocol_form_outcomes',
                'column' => 'errors_ciphertext',
                'context' => static fn (array $r): string => PayrollSubmissionService::formOutcomeErrorsContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['submission_id'],
                    (int) $r['receipt_id'],
                    (string) $r['form_guid'],
                    (string) $r['errors_sha256'],
                ),
                'guard' => 'payroll_key_rewrap_jmhz_protocol_form_outcomes',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_submission_issues',
                'column' => 'details_ciphertext',
                'context' => static fn (array $r): string => PayrollSubmissionService::issueDetailsContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['submission_id'],
                    $nullableInt($r['part_id']),
                    (string) $r['severity'],
                    (string) $r['validation_stage'],
                    (string) $r['issue_code'],
                    $nullableString($r['entity_type']),
                    $nullableString($r['entity_reference']),
                    (string) $r['details_hash'],
                ),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_enforcement_xmlzam_requests',
                'column' => 'snapshot_ciphertext',
                // Čtecí cesta hashe před složením kontextu převádí na malá písmena.
                'context' => static fn (array $r): string => XmlzamCooperationArtifactStore::requestSnapshotContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    strtolower((string) $r['source_xml_sha256']),
                    strtolower((string) $r['snapshot_fingerprint']),
                ),
                'guard' => 'payroll_key_rewrap_enforcement_xmlzam_requests',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_enforcement_xmlzam_responses',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => XmlzamCooperationArtifactStore::responseSnapshotContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['request_id'],
                    (string) $r['snapshot_fingerprint'],
                ),
                'guard' => 'payroll_key_rewrap_enforcement_xmlzam_responses',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_enforcement_xmlzam_responses',
                'column' => 'xml_ciphertext',
                'context' => static fn (array $r): string => XmlzamCooperationArtifactStore::responseXmlContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['request_id'],
                    (string) $r['snapshot_fingerprint'],
                ),
                'guard' => 'payroll_key_rewrap_enforcement_xmlzam_responses',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_regzel_payload_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => EmployerRegistrationService::encryptionContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (string) $r['source_snapshot_hash'],
                ),
                'guard' => 'payroll_key_rewrap_regzel_payload_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_jmhz_eldp_evidence_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => JmhzEldpEvidenceSnapshotService::encryptionContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['source_revision_id'],
                    (int) $r['employment_id'],
                    (string) $r['snapshot_fingerprint'],
                    (string) $r['source_manifest_sha256'],
                ),
                'guard' => 'payroll_key_rewrap_jmhz_eldp_evidence_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_jmhz_ordinary_evidence_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => JmhzOrdinaryEvidenceService::encryptionContext(
                    (int) $r['supplier_id'],
                    (int) $r['source_revision_id'],
                    (int) $r['employment_id'],
                    (string) $r['snapshot_fingerprint'],
                    (string) $r['source_manifest_sha256'],
                ),
                'guard' => 'payroll_key_rewrap_jmhz_ordinary_evidence_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_jmhz_preparation_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => JmhzPreparationSnapshotService::encryptionContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['source_revision_id'],
                    (string) $r['snapshot_fingerprint'],
                    (string) $r['source_manifest_sha256'],
                    (string) $r['readiness_sha256'],
                ),
                'guard' => 'payroll_key_rewrap_jmhz_preparation_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_registration_identity_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => PayrollRegistrationIdentitySnapshotService::encryptionContext(
                    [
                        'supplier_id' => (int) $r['supplier_id'],
                        'environment' => (string) $r['environment'],
                        'submission_id' => (int) $r['submission_id'],
                        'source_revision_id' => (int) $r['source_revision_id'],
                        'employee_id' => (int) $r['employee_id'],
                        'employment_id' => (int) $r['employment_id'],
                        'agenda_code' => (string) $r['agenda_code'],
                        'effective_on' => (string) $r['effective_on'],
                    ],
                    (string) $r['snapshot_fingerprint'],
                    (string) $r['source_manifest_hash'],
                ),
                'guard' => 'payroll_key_rewrap_registration_identity_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_registration_event_snapshots',
                'column' => 'snapshot_ciphertext',
                'context' => static fn (array $r): string => PayrollRegistrationEventService::context(
                    (int) $r['supplier_id'],
                    (int) $r['employment_id'],
                    (string) $r['source_manifest_hash'],
                ),
                'guard' => 'payroll_key_rewrap_registration_event_snapshots',
                'updated_at' => false,
            ],
            [
                'table' => 'payroll_eldp_statements',
                'column' => 'statement_ciphertext',
                'context' => static fn (array $r): string => EldpStatementService::encryptionContext(
                    (int) $r['supplier_id'],
                    (string) $r['environment'],
                    (int) $r['employment_id'],
                    (int) $r['statement_year'],
                    (string) $r['statement_fingerprint'],
                    (string) $r['source_manifest_sha256'],
                ),
                'guard' => 'payroll_key_rewrap_eldp_statements',
                'updated_at' => false,
            ],
            // Podání předchozím programem (převod PAMICA, nahrané XML hlášení).
            // Tabulky nejsou append-only, výjimku z triggeru nepotřebují.
            [
                'table' => 'payroll_external_jmhz_submissions',
                'column' => 'payload_ciphertext',
                'context' => $sealed(PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD),
                'guard' => null,
                'updated_at' => true,
            ],
            [
                'table' => 'payroll_external_jmhz_submission_forms',
                'column' => 'payload_ciphertext',
                'context' => $sealed(PayrollSensitiveField::EXTERNAL_JMHZ_PAYLOAD),
                'guard' => null,
                'updated_at' => false,
            ],
        ];
    }
}
