<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Http\IsoDate;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Evidenční jednotky EET — místní obraz provozoven a kanálů oznámených v DIS+.
 *
 * Jednotka se fyzicky nemaže: „smazání" ukončí účinnost (`valid_to`), aby staré
 * záznamy i čekající opakování dál ukazovaly na existující jednotku.
 */
final class EetUnitRepository
{
    private const COLUMNS = 'u.id, u.unit_number, u.name, u.kind, u.valid_from, u.valid_to, u.notified_to_fs, u.created_at, u.updated_at';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . self::COLUMNS . ' FROM eet_units u WHERE u.supplier_id = ? AND u.id = ?');
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::present($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM eet_units u WHERE u.supplier_id = ? ORDER BY u.unit_number, u.id'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::present(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Počet jednotek účinných k danému dni. */
    public function countValidOn(int $supplierId, string $date): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM eet_units
              WHERE supplier_id = ? AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)'
        );
        $stmt->execute([$supplierId, $date, $date]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $input): array
    {
        $data = self::validate($input, null);
        $this->assertUniqueNumber($supplierId, $data['unit_number'], null);
        $this->db->pdo()->prepare(
            'INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from, valid_to, notified_to_fs)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['unit_number'], $data['name'], $data['kind'],
            $data['valid_from'], $data['valid_to'], $data['notified_to_fs'] ? 1 : 0,
        ]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Uloženou jednotku se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $input): array
    {
        $current = $this->find($supplierId, $id) ?? throw EetException::notFound('Jednotka EET nebyla nalezena.');
        $data = self::validate($input, $current);
        $this->assertUniqueNumber($supplierId, $data['unit_number'], $id);
        $this->db->pdo()->prepare(
            'UPDATE eet_units SET unit_number = ?, name = ?, kind = ?, valid_from = ?, valid_to = ?, notified_to_fs = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $data['unit_number'], $data['name'], $data['kind'], $data['valid_from'], $data['valid_to'],
            $data['notified_to_fs'] ? 1 : 0, $supplierId, $id,
        ]);
        return $this->find($supplierId, $id) ?? throw EetException::notFound('Jednotka EET nebyla nalezena.');
    }

    /**
     * Ukončí účinnost jednotky k zadanému dni (výchozí dnes). Už ukončenou
     * jednotku s dřívějším koncem nechá být.
     *
     * @return array<string,mixed>
     */
    public function endValidity(int $supplierId, int $id, ?string $validTo = null): array
    {
        $current = $this->find($supplierId, $id) ?? throw EetException::notFound('Jednotka EET nebyla nalezena.');
        $validTo ??= (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!IsoDate::isValid($validTo)) {
            throw EetException::validation('Datum ukončení účinnosti není platné datum (RRRR-MM-DD).', ['field' => 'valid_to']);
        }
        if ($validTo < $current['valid_from']) {
            $validTo = (string) $current['valid_from'];
        }
        if ($current['valid_to'] !== null && $current['valid_to'] <= $validTo) {
            return $current;
        }
        $this->db->pdo()->prepare('UPDATE eet_units SET valid_to = ? WHERE supplier_id = ? AND id = ?')
            ->execute([$validTo, $supplierId, $id]);
        return $this->find($supplierId, $id) ?? throw EetException::notFound('Jednotka EET nebyla nalezena.');
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array{unit_number:int,name:string,kind:string,valid_from:string,valid_to:?string,notified_to_fs:bool}
     */
    private static function validate(array $input, ?array $current): array
    {
        $pick = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? null);

        $number = $pick('unit_number');
        if (is_string($number) && ctype_digit($number)) {
            $number = (int) $number;
        }
        if (!is_int($number) || $number < 1 || $number > EetIdentifiers::UNIT_NUMBER_MAX) {
            throw EetException::validation('Číslo jednotky (id_jednotky z DIS+) musí být celé číslo 1–999999999.', ['field' => 'unit_number']);
        }
        $name = trim((string) (is_scalar($pick('name')) ? $pick('name') : ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw EetException::validation('Název jednotky je povinný a může mít nejvýš 120 znaků.', ['field' => 'name']);
        }
        $kind = $pick('kind');
        if (!is_string($kind) || !in_array($kind, EetIdentifiers::UNIT_KINDS, true)) {
            throw EetException::validation('Typ jednotky musí být jeden z: ' . implode(', ', EetIdentifiers::UNIT_KINDS) . '.', ['field' => 'kind']);
        }
        $validFrom = $pick('valid_from');
        if (!is_string($validFrom) || !IsoDate::isValid($validFrom)) {
            throw EetException::validation('Účinnost od je povinné platné datum (RRRR-MM-DD).', ['field' => 'valid_from']);
        }
        $validTo = $pick('valid_to');
        if ($validTo === '') {
            $validTo = null;
        }
        if ($validTo !== null && (!is_string($validTo) || !IsoDate::isValid($validTo))) {
            throw EetException::validation('Účinnost do není platné datum (RRRR-MM-DD).', ['field' => 'valid_to']);
        }
        if ($validTo !== null && $validTo < $validFrom) {
            throw EetException::validation('Účinnost do nesmí předcházet účinnosti od.', ['field' => 'valid_to']);
        }
        $notified = $pick('notified_to_fs');
        $notified = $notified === null ? true : filter_var($notified, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($notified === null) {
            throw EetException::validation('Příznak oznámení finanční správě musí být true/false.', ['field' => 'notified_to_fs']);
        }

        return [
            'unit_number' => $number,
            'name' => $name,
            'kind' => $kind,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'notified_to_fs' => $notified,
        ];
    }

    private function assertUniqueNumber(int $supplierId, int $number, ?int $exceptId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_units WHERE supplier_id = ? AND unit_number = ?');
        $stmt->execute([$supplierId, $number]);
        $id = $stmt->fetchColumn();
        if ($id !== false && (int) $id !== $exceptId) {
            throw new EetException(EetException::CONFLICT, 'Jednotka s tímto číslem už ve firmě existuje.', 409, ['field' => 'unit_number']);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'unit_number' => (int) $row['unit_number'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'valid_from' => (string) $row['valid_from'],
            'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
            'notified_to_fs' => (int) $row['notified_to_fs'] === 1,
            'created_at' => EetTime::iso($row['created_at']),
            'updated_at' => EetTime::iso($row['updated_at']),
        ];
    }
}
