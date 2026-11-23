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
    private const COLUMNS = 'u.id, u.unit_number, u.name, u.kind, u.nace_code, u.activity_note, u.records_sales,
        u.indirect_representation, u.ruian_code, u.street, u.house_number, u.orientation_number, u.municipality,
        u.municipality_part, u.postal_code, u.location_text, u.vehicle_kind, u.vehicle_kind_note,
        u.valid_from, u.valid_to, u.notified_to_fs, u.created_at, u.updated_at';

    /** Údaje pro DIS+ v pořadí sloupců tabulky ({@see EetUnitDisFormat}). */
    private const DIS_FIELDS = [
        'nace_code', 'activity_note', 'records_sales', 'indirect_representation', 'ruian_code', 'street',
        'house_number', 'orientation_number', 'municipality', 'municipality_part', 'postal_code',
        'location_text', 'vehicle_kind', 'vehicle_kind_note',
    ];

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
            'INSERT INTO eet_units (supplier_id, unit_number, name, kind, valid_from, valid_to, notified_to_fs, '
            . implode(', ', self::DIS_FIELDS) . ')
             VALUES (?, ?, ?, ?, ?, ?, ?' . str_repeat(', ?', count(self::DIS_FIELDS)) . ')'
        )->execute([
            $supplierId, $data['unit_number'], $data['name'], $data['kind'],
            $data['valid_from'], $data['valid_to'], $data['notified_to_fs'] ? 1 : 0,
            ...self::disValues($data),
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
        if ($current['unit_number'] !== null && $data['unit_number'] === null && $this->hasDevices($supplierId, $id)) {
            throw EetException::validation(
                'Jednotka má pokladní zařízení, číslo z DIS+ jí proto nejde odebrat.',
                ['field' => 'unit_number'],
            );
        }
        $this->db->pdo()->prepare(
            'UPDATE eet_units SET unit_number = ?, name = ?, kind = ?, valid_from = ?, valid_to = ?, notified_to_fs = ?, '
            . implode(', ', array_map(static fn (string $f): string => $f . ' = ?', self::DIS_FIELDS)) . '
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $data['unit_number'], $data['name'], $data['kind'], $data['valid_from'], $data['valid_to'],
            $data['notified_to_fs'] ? 1 : 0, ...self::disValues($data), $supplierId, $id,
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
     * Jednotky k hromadnému importu do DIS+: účinné k danému dni, a bez
     * `$all` jen ty, kterým DIS+ ještě nepřidělil číslo.
     *
     * @return list<array<string,mixed>>
     */
    public function listForDisImport(int $supplierId, string $date, bool $all): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM eet_units u
              WHERE u.supplier_id = ? AND (u.valid_to IS NULL OR u.valid_to >= ?)'
            . ($all ? '' : ' AND u.unit_number IS NULL')
            . ' ORDER BY u.unit_number, u.id'
        );
        $stmt->execute([$supplierId, $date]);
        return array_map(self::present(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    private function hasDevices(int $supplierId, int $unitId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM eet_devices WHERE supplier_id = ? AND unit_id = ? LIMIT 1');
        $stmt->execute([$supplierId, $unitId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return list<mixed>
     */
    private static function disValues(array $data): array
    {
        return array_map(
            static fn (string $field): mixed => is_bool($data[$field]) ? ($data[$field] ? 1 : 0) : $data[$field],
            self::DIS_FIELDS,
        );
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private static function validate(array $input, ?array $current): array
    {
        $pick = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? null);

        // Číslo přiděluje DIS+; jednotku připravenou k importu jde uložit bez něj.
        $number = $pick('unit_number');
        if (is_string($number) && ctype_digit($number)) {
            $number = (int) $number;
        }
        if ($number === '' || $number === 0) {
            $number = null;
        }
        if ($number !== null && (!is_int($number) || $number < 1 || $number > EetIdentifiers::UNIT_NUMBER_MAX)) {
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
        ] + EetUnitDisFormat::normalize($input, $current);
    }

    private function assertUniqueNumber(int $supplierId, ?int $number, ?int $exceptId): void
    {
        if ($number === null) {
            return;
        }
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
            'unit_number' => $row['unit_number'] === null ? null : (int) $row['unit_number'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'nace_code' => $row['nace_code'],
            'activity_note' => $row['activity_note'],
            'records_sales' => (int) $row['records_sales'] === 1,
            'indirect_representation' => (int) $row['indirect_representation'] === 1,
            'ruian_code' => $row['ruian_code'] === null ? null : (int) $row['ruian_code'],
            'street' => $row['street'],
            'house_number' => $row['house_number'],
            'orientation_number' => $row['orientation_number'],
            'municipality' => $row['municipality'],
            'municipality_part' => $row['municipality_part'],
            'postal_code' => $row['postal_code'],
            'location_text' => $row['location_text'],
            'vehicle_kind' => $row['vehicle_kind'],
            'vehicle_kind_note' => $row['vehicle_kind_note'],
            'valid_from' => (string) $row['valid_from'],
            'valid_to' => $row['valid_to'] === null ? null : (string) $row['valid_to'],
            'notified_to_fs' => (int) $row['notified_to_fs'] === 1,
            'created_at' => EetTime::iso($row['created_at']),
            'updated_at' => EetTime::iso($row['updated_at']),
        ];
    }
}
