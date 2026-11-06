<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Uložené profily mapování sloupců importu (pravidla podle hlaviček
 * a mzdové složky, které pravidla potřebují).
 *
 * @phpstan-type ImportProfile array{
 *   id:int,name:string,rules:list<array<string,mixed>>,components:list<array<string,mixed>>,
 *   is_sample:bool,sample_key:?string,sample_version:?int,updated_at:string
 * }
 */
final class PayrollImportProfileRepository
{
    private const COLUMNS = 'id, name, rules_json, components_json, is_sample, sample_key, sample_version, updated_at';

    /**
     * Klíč vzoru => značka na firmě, že vzor už dostala. Každý vzor má vlastní,
     * takže nový vzor dostanou i firmy, které starší vzor mají nebo smazaly.
     */
    public const SAMPLE_MARKERS = [
        'giriton' => 'payroll_attendance_sample_seeded_at',
        'myucto' => 'payroll_attendance_myucto_sample_seeded_at',
        'pamica' => 'payroll_attendance_pamica_sample_seeded_at',
    ];

    /** Vzory založené před zavedením klíčů jsou GIRITON (migrace 2206). */
    private const LEGACY_SAMPLE_KEY = 'giriton';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<ImportProfile> */
    public function list(int $supplierId, string $sourceSystem): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ?
              ORDER BY is_sample DESC, name, id'
        );
        $stmt->execute([$supplierId, $sourceSystem]);

        return array_map(
            $this->profile(...),
            PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_import_profiles'),
        );
    }

    /** @return ImportProfile|null */
    public function find(int $supplierId, string $sourceSystem, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->profile(PayrollTimeValue::row($row, 'payroll_import_profile'));
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     * @return ImportProfile|null null = profil neexistuje
     */
    public function save(
        int $supplierId,
        string $sourceSystem,
        ?int $id,
        string $name,
        array $rules,
        ?int $userId,
        array $components = [],
        bool $isSample = false,
        ?int $sampleVersion = null,
        ?string $sampleKey = null,
    ): ?array {
        $rulesJson = self::json($rules);
        $componentsJson = self::json($components);
        try {
            if ($id === null) {
                $this->db->pdo()->prepare(
                    'INSERT INTO payroll_import_profiles
                        (supplier_id, source_system, name, rules_json, components_json, is_sample, sample_key,
                         sample_version, sample_rules_sha256, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $supplierId,
                    $sourceSystem,
                    $name,
                    $rulesJson,
                    $componentsJson,
                    $isSample ? 1 : 0,
                    $isSample ? ($sampleKey ?? self::LEGACY_SAMPLE_KEY) : null,
                    $isSample ? $sampleVersion : null,
                    $isSample ? self::contentHash($rulesJson, $componentsJson) : null,
                    $userId,
                ]);
                $id = (int) $this->db->pdo()->lastInsertId();
            } else {
                if ($this->find($supplierId, $sourceSystem, $id) === null) {
                    return null;
                }
                $this->db->pdo()->prepare(
                    'UPDATE payroll_import_profiles
                        SET name = ?, rules_json = ?, components_json = ?, updated_by = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND source_system = ? AND id = ?'
                )->execute([$name, $rulesJson, $componentsJson, $userId, $supplierId, $sourceSystem, $id]);
            }
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062 || ($e->errorInfo[1] ?? null) === '1062') {
                throw new \InvalidArgumentException(
                    "Profil s názvem „{$name}“ už existuje. Zvolte jiný název nebo upravte existující profil.",
                );
            }
            throw $e;
        }

        return $this->find($supplierId, $sourceSystem, $id);
    }

    public function delete(int $supplierId, string $sourceSystem, int $id): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $sourceSystem, $id]);

        return $stmt->rowCount() === 1;
    }

    public function supplierExists(int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ukázkový profil se firmě založí jednou; značka vzoru na firmě zajistí,
     * že se smazaný vzor znovu nevrátí. Uvnitř cizí transakce se nic nezakládá —
     * zámek řádku firmy by se tam držel až do jejího konce.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     * @return bool true, pokud vzor právě založil
     */
    public function seedSampleOnce(
        int $supplierId,
        string $sourceSystem,
        string $sampleKey,
        string $name,
        array $rules,
        array $components,
        ?int $sampleVersion = null,
    ): bool {
        $marker = self::SAMPLE_MARKERS[$sampleKey]
            ?? throw new \InvalidArgumentException("Vzor profilu „{$sampleKey}“ nemá značku na firmě.");
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return false;
        }
        $check = $pdo->prepare("SELECT {$marker} FROM supplier WHERE id = ?");
        $check->execute([$supplierId]);
        $seeded = $check->fetchColumn();
        if ($seeded === false || $seeded !== null) {
            return false;
        }
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT {$marker} FROM supplier WHERE id = ? FOR UPDATE");
            $lock->execute([$supplierId]);
            $current = $lock->fetchColumn();
            if ($current === false || $current !== null) {
                $pdo->commit();

                return false;
            }
            $exists = $pdo->prepare(
                'SELECT 1 FROM payroll_import_profiles WHERE supplier_id = ? AND source_system = ? AND name = ?'
            );
            $exists->execute([$supplierId, $sourceSystem, $name]);
            $created = $exists->fetchColumn() === false;
            if ($created) {
                $this->save($supplierId, $sourceSystem, null, $name, $rules, null, $components, true, $sampleVersion, $sampleKey);
            }
            $pdo->prepare("UPDATE supplier SET {$marker} = NOW() WHERE id = ?")
                ->execute([$supplierId]);
            $pdo->commit();

            return $created;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Převede ukázkové profily firmy založené z vzoru `$sampleKey` na jeho
     * aktuální verzi; vzory s jiným klíčem se nedotknou.
     *
     * Nedotčený vzor (uložený obsah má týž otisk, jaký aplikace zapsala) se
     * nahradí; obsah, který už aktuálnímu vzoru odpovídá, jen dostane novou
     * verzi. Upravený vzor se NEPŘEPISUJE — účetní si ho přizpůsobila a nová
     * pravidla by jí změnu potichu vzala. Takový profil se vrátí, aby náhled
     * mohl nabídnout převzetí. Smazaný vzor tu není, takže se ani nevrátí.
     *
     * Zápis je podmíněný otiskem i verzí řádku, takže souběžná úprava profilu
     * se nepřepíše; zámek firmy není potřeba, a proto to jde i uvnitř cizí
     * transakce.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $components
     * @return list<array{id:int,name:string,sample_key:string,sample_version:?int}> upravené vzory starší verze
     */
    public function upgradeSample(
        int $supplierId,
        string $sourceSystem,
        string $sampleKey,
        int $version,
        array $rules,
        array $components,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, rules_json, components_json, sample_version, sample_rules_sha256, row_version
               FROM payroll_import_profiles
              WHERE supplier_id = ? AND source_system = ? AND is_sample = 1 AND sample_key = ?
                AND (sample_version IS NULL OR sample_version < ?)
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $sourceSystem, $sampleKey, $version]);
        $rulesJson = self::json($rules);
        $componentsJson = self::json($components);
        $latestHash = self::contentHash($rulesJson, $componentsJson);
        $modified = [];
        foreach (PayrollTimeValue::rows($stmt->fetchAll(PDO::FETCH_ASSOC), 'payroll_import_samples') as $row) {
            $id = PayrollTimeValue::int($row['id'] ?? null, 'id');
            $storedHash = self::contentHash((string) $row['rules_json'], (string) ($row['components_json'] ?? ''));
            $sampleVersion = $row['sample_version'] === null
                ? null
                : PayrollTimeValue::int($row['sample_version'], 'sample_version');
            if ($storedHash === $latestHash) {
                $this->db->pdo()->prepare(
                    'UPDATE payroll_import_profiles
                        SET sample_version = ?, sample_rules_sha256 = ?
                      WHERE supplier_id = ? AND id = ? AND row_version = ?'
                )->execute([$version, $latestHash, $supplierId, $id, $row['row_version']]);
                continue;
            }
            if ($row['sample_rules_sha256'] !== null && hash_equals((string) $row['sample_rules_sha256'], $storedHash)) {
                $update = $this->db->pdo()->prepare(
                    'UPDATE payroll_import_profiles
                        SET rules_json = ?, components_json = ?, sample_version = ?, sample_rules_sha256 = ?,
                            row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ? AND row_version = ? AND sample_rules_sha256 = ?'
                );
                $update->execute([
                    $rulesJson,
                    $componentsJson,
                    $version,
                    $latestHash,
                    $supplierId,
                    $id,
                    $row['row_version'],
                    $storedHash,
                ]);
                if ($update->rowCount() === 1) {
                    continue;
                }
            }
            $modified[] = ['id' => $id, 'name' => (string) $row['name'], 'sample_key' => $sampleKey, 'sample_version' => $sampleVersion];
        }

        return $modified;
    }

    /** Otisk obsahu profilu; stejný výraz počítá doplnění v migraci 1836. */
    public static function contentHash(string $rulesJson, string $componentsJson): string
    {
        return hash('sha256', $rulesJson . "\n" . $componentsJson);
    }

    /** @param list<array<string,mixed>> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $row
     * @return ImportProfile
     */
    private function profile(array $row): array
    {
        $rules = json_decode((string) ($row['rules_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        $components = json_decode((string) ($row['components_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);

        return [
            'id' => PayrollTimeValue::int($row['id'] ?? null, 'id'),
            'name' => (string) $row['name'],
            'rules' => is_array($rules) ? array_values(array_filter($rules, 'is_array')) : [],
            'components' => is_array($components) ? array_values(array_filter($components, 'is_array')) : [],
            'is_sample' => (int) ($row['is_sample'] ?? 0) === 1,
            'sample_key' => is_string($row['sample_key'] ?? null) ? $row['sample_key'] : null,
            'sample_version' => ($row['sample_version'] ?? null) === null
                ? null
                : PayrollTimeValue::int($row['sample_version'], 'sample_version'),
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
