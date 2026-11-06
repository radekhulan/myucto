<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

use MyInvoice\Repository\TaxProfileRepository;
use MyInvoice\Service\Payroll\Security\PayrollRevealPurpose;
use MyInvoice\Service\Payroll\Security\PayrollSensitiveData;
use PDO;

/**
 * Převod otevřeně uložených rodných čísel daňového profilu do zapečetěné podoby
 * (H28, migrace 2160).
 *
 * Proč PHP a ne UPDATE v migraci: šifruje se kontextovým AES-GCM klíčem instance
 * ({@see PayrollSensitiveData}), to SQL neumí, a otisk i maska musí vzniknout
 * stejnou funkcí, kterou je aplikace pak čte.
 *
 * Bezpečnost převodu:
 *  - zapečetěná hodnota se před zápisem zkusmo dešifruje a porovná s původní;
 *  - otevřený sloupec se vyprázdní v TÉMŽE příkazu, který zapisuje šifru, a jen
 *    když se řádek mezitím nezměnil (podmínka na původní hodnotu). Nepovedený
 *    řádek si otevřenou hodnotu nechá a aplikace z ní čte dál;
 *  - idempotentní: bere jen řádky s otevřenou hodnotou a bez šifry, opakovaný
 *    běh je no-op a auto-backfill v migrate.php se nespouští dokola.
 */
final class TaxFamilyBirthNumberBackfill
{
    private const BATCH = 500;
    private const TABLES = ['tax_profile_children', 'tax_profile_spouse_claims'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PayrollSensitiveData $sensitiveData,
    ) {}

    /** Kolik řádků ještě nese otevřené rodné číslo. */
    public function pending(): int
    {
        return self::pendingIn($this->pdo);
    }

    /** Počet bez šifrovacích klíčů — auto-backfill v migrate.php se ptá dřív, než sestaví aplikaci. */
    public static function pendingIn(PDO $pdo): int
    {
        $count = 0;
        foreach (self::TABLES as $table) {
            $count += (int) $pdo->query(
                "SELECT COUNT(*) FROM {$table}
                  WHERE birth_number IS NOT NULL AND TRIM(birth_number) <> '' AND birth_number_ciphertext IS NULL"
            )->fetchColumn();
        }

        return $count;
    }

    /**
     * @param callable(string,int,string):void|null $onRow tabulka, id řádku, výsledek (sealed|would_seal|failed)
     * @return array{found:int, sealed:int, failed:int}
     */
    public function run(bool $apply, ?callable $onRow = null): array
    {
        $found = 0;
        $sealedCount = 0;
        $failed = 0;
        $field = TaxProfileRepository::FAMILY_FIELD;
        foreach (self::TABLES as $table) {
            $select = $this->pdo->prepare(
                "SELECT id, supplier_id, birth_number FROM {$table}
                  WHERE id > ? AND birth_number IS NOT NULL AND TRIM(birth_number) <> '' AND birth_number_ciphertext IS NULL
                  ORDER BY id LIMIT " . self::BATCH
            );
            $update = $this->pdo->prepare(
                "UPDATE {$table}
                    SET birth_number_ciphertext = ?, birth_number_hash = ?, birth_number_masked = ?,
                        birth_number = NULL
                  WHERE id = ? AND supplier_id = ? AND birth_number = ? AND birth_number_ciphertext IS NULL"
            );
            $lastId = 0;
            do {
                $select->execute([$lastId]);
                $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $lastId = (int) $row['id'];
                    $supplierId = (int) $row['supplier_id'];
                    $plain = (string) $row['birth_number'];
                    $found++;
                    try {
                        $sealed = $this->sensitiveData->seal($plain, $field, $supplierId, $lastId);
                        $check = $this->sensitiveData->reveal(
                            $sealed->ciphertext,
                            $field,
                            $supplierId,
                            $lastId,
                            PayrollRevealPurpose::MIGRATION_VERIFY,
                        );
                        if (!hash_equals(trim($plain), $check)) {
                            throw new \RuntimeException('Zapečetěná hodnota neodpovídá původní.');
                        }
                        if (!$apply) {
                            $onRow !== null && $onRow($table, $lastId, 'would_seal');
                            continue;
                        }
                        $update->execute([
                            $sealed->ciphertext, $sealed->lookupHash, $sealed->masked,
                            $lastId, $supplierId, $plain,
                        ]);
                        if ($update->rowCount() !== 1) {
                            throw new \RuntimeException('Řádek se mezitím změnil.');
                        }
                        $sealedCount++;
                        $onRow !== null && $onRow($table, $lastId, 'sealed');
                    } catch (\Throwable) {
                        $failed++;
                        $onRow !== null && $onRow($table, $lastId, 'failed');
                    }
                }
            } while (count($rows) === self::BATCH);
        }

        return ['found' => $found, 'sealed' => $sealedCount, 'failed' => $failed];
    }
}
