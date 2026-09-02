<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollOfficeRegistrationRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, int $officeId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, office_id, effective_from, social_security_variable_symbol,
                    source_reference, created_by, created_at
               FROM payroll_office_registration_versions
              WHERE supplier_id = ? AND office_id = ?
              ORDER BY effective_from DESC, id DESC',
        );
        $statement->execute([$supplierId, $officeId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'office_id' => (int) $row['office_id'],
            'effective_from' => (string) $row['effective_from'],
            'social_security_variable_symbol' => (string) $row['social_security_variable_symbol'],
            'source_reference' => (string) $row['source_reference'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    public function add(
        int $supplierId,
        int $officeId,
        string $effectiveFrom,
        string $symbol,
        string $sourceReference,
        int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $office = $pdo->prepare(
                'SELECT id FROM payroll_offices WHERE supplier_id = ? AND id = ? FOR UPDATE',
            );
            $office->execute([$supplierId, $officeId]);
            if ($office->fetchColumn() === false) {
                throw new \OutOfBoundsException('Mzdová účtárna neexistuje.');
            }
            $insert = $pdo->prepare(
                'INSERT INTO payroll_office_registration_versions
                    (supplier_id, office_id, effective_from, social_security_variable_symbol,
                     source_reference, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
            );
            $insert->execute([$supplierId, $officeId, $effectiveFrom, $symbol, $sourceReference, $actorUserId]);
            $id = (int) $pdo->lastInsertId();
            $this->syncOfficeSymbol($supplierId, $officeId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return $this->byId($supplierId, $id)
            ?? throw new \RuntimeException('Registrace mzdové účtárny nebyla nalezena.');
    }

    /**
     * Smaže NEJNOVĚJŠÍ verzi registrace účtárny.
     *
     * PROČ to jde: variabilní symbol přiděluje ČSSZ při registraci
     * zaměstnavatele, ne v den, kdy ho účetní opíše. Kdo ho uložil
     * s dnešním datem, zablokoval si tím mzdy za předchozí měsíce a
     * dřívější účinnost už doplnit nešlo, protože nová verze musí
     * navazovat až za poslední uloženou.
     *
     * Starší verze chráněné zůstávají — podle nich se už mohlo počítat
     * a podávat. Že na mazané verzi nevisí podání ani mzdový běh, hlídá
     * volající; tady se drží jen zámek a pravidlo „jen nejnovější".
     *
     * @return bool false, když verze neexistuje nebo patří jiné firmě
     */
    public function deleteNewest(
        int $supplierId,
        int $officeId,
        int $registrationId,
    ): bool {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare(
                'SELECT id FROM payroll_office_registration_versions
                  WHERE supplier_id = ? AND office_id = ? AND id = ?
                  FOR UPDATE',
            );
            $lock->execute([$supplierId, $officeId, $registrationId]);
            if ($lock->fetchColumn() === false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }

                return false;
            }
            $delete = $pdo->prepare(
                'DELETE FROM payroll_office_registration_versions
                  WHERE supplier_id = ? AND office_id = ? AND id = ?',
            );
            $delete->execute([$supplierId, $officeId, $registrationId]);
            $this->syncOfficeSymbol($supplierId, $officeId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return true;
    }

    /**
     * Promítne nejnovější registrovaný VS do `payroll_offices`.
     *
     * Variabilní symbol ČSSZ žije na DVOU místech: v historii registrace
     * účtárny (tady) a ve sloupci `payroll_offices.social_security_variable_symbol`.
     * Zapisovala se ale jen historie — a předpis sociálního pojištění, měsíční
     * hlášení JMHZ i přehled PVPOJ čtou ten sloupec. Účetní tedy VS řádně
     * zaregistrovala, obrazovka ho ukazovala, a příprava plateb přesto spadla
     * na „social_security_variable_symbol není neprázdný text"; sloupec navíc
     * hromadné uložení nastavení účtárny nepřepíše, takže z aplikace nebylo
     * kudy ho doplnit.
     *
     * Historie zůstává zdrojem pravdy, sloupec je jen její poslední hodnota.
     */
    private function syncOfficeSymbol(int $supplierId, int $officeId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_offices office
                SET office.social_security_variable_symbol = (
                    SELECT version.social_security_variable_symbol
                      FROM payroll_office_registration_versions version
                     WHERE version.supplier_id = office.supplier_id
                       AND version.office_id = office.id
                     ORDER BY version.effective_from DESC, version.id DESC
                     LIMIT 1
                )
              WHERE office.supplier_id = ? AND office.id = ?'
        )->execute([$supplierId, $officeId]);
    }

    /** @return array<string,mixed>|null */
    public function effective(int $supplierId, int $officeId, string $onDate): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, effective_from, social_security_variable_symbol, source_reference
               FROM payroll_office_registration_versions
              WHERE supplier_id = ? AND office_id = ? AND effective_from <= ?
              ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $statement->execute([$supplierId, $officeId, $onDate]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'id' => (int) $row['id'], 'effective_from' => (string) $row['effective_from'],
            'social_security_variable_symbol' => (string) $row['social_security_variable_symbol'],
            'source_reference' => (string) $row['source_reference'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function byId(int $supplierId, int $id): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, office_id, effective_from, social_security_variable_symbol,
                    source_reference, created_by, created_at
               FROM payroll_office_registration_versions WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'id' => (int) $row['id'], 'office_id' => (int) $row['office_id'],
            'effective_from' => (string) $row['effective_from'],
            'social_security_variable_symbol' => (string) $row['social_security_variable_symbol'],
            'source_reference' => (string) $row['source_reference'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
