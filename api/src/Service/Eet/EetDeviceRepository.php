<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Logická zařízení (pokladny) EET. Kód zařízení je `id_pokl` datové zprávy
 * a nese vlastní číselnou řadu účtenek (`sequence_year`, `next_sequence`).
 *
 * Zařízení se nemaže, jen deaktivuje — záznamy na něj odkazují a čítač řady
 * se nesmí ztratit. Kód nejde změnit, jakmile zařízení vydalo první číslo.
 */
final class EetDeviceRepository
{
    private const COLUMNS = 'd.id, d.unit_id, d.code, d.name, d.cash_register_id, d.delegation, d.delegating_eic, d.active, d.sequence_year, d.next_sequence,
        d.created_at, d.updated_at, u.unit_number, u.name AS unit_name, u.valid_from AS unit_valid_from, u.valid_to AS unit_valid_to';

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM eet_devices d
               JOIN eet_units u ON u.supplier_id = d.supplier_id AND u.id = d.unit_id
              WHERE d.supplier_id = ? AND d.id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? self::present($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listFor(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM eet_devices d
               JOIN eet_units u ON u.supplier_id = d.supplier_id AND u.id = d.unit_id
              WHERE d.supplier_id = ?
              ORDER BY d.active DESC, d.code, d.id'
        );
        $stmt->execute([$supplierId]);
        return array_map(self::present(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** Počet aktivních zařízení na jednotce účinné k danému dni. */
    public function countActiveOn(int $supplierId, string $date): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM eet_devices d
               JOIN eet_units u ON u.supplier_id = d.supplier_id AND u.id = d.unit_id
              WHERE d.supplier_id = ? AND d.active = 1
                AND u.valid_from <= ? AND (u.valid_to IS NULL OR u.valid_to >= ?)'
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
        $data = $this->validate($supplierId, $input, null);
        $this->assertUniqueCode($supplierId, $data['code'], null);
        $this->db->pdo()->prepare(
            'INSERT INTO eet_devices (supplier_id, unit_id, code, name, cash_register_id, delegation, delegating_eic, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $data['unit_id'], $data['code'], $data['name'], $data['cash_register_id'],
            $data['delegation']->mode, $data['delegation']->delegatingEic, $data['active'] ? 1 : 0,
        ]);
        return $this->find($supplierId, (int) $this->db->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Uložené zařízení se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $supplierId, int $id, array $input): array
    {
        $current = $this->find($supplierId, $id) ?? throw EetException::notFound('Zařízení EET nebylo nalezeno.');
        $data = $this->validate($supplierId, $input, $current);
        if ($data['code'] !== $current['code'] && ($current['next_sequence'] > 1 || $current['sequence_year'] !== null)) {
            throw new EetException(EetException::CONFLICT, 'Kód zařízení nelze změnit, zařízení už vydalo účtenky. Založte nové zařízení.', 409, ['field' => 'code']);
        }
        $this->assertUniqueCode($supplierId, $data['code'], $id);
        $this->db->pdo()->prepare(
            'UPDATE eet_devices SET unit_id = ?, code = ?, name = ?, cash_register_id = ?, delegation = ?, delegating_eic = ?, active = ?
              WHERE supplier_id = ? AND id = ?'
        )->execute([
            $data['unit_id'], $data['code'], $data['name'], $data['cash_register_id'],
            $data['delegation']->mode, $data['delegation']->delegatingEic, $data['active'] ? 1 : 0, $supplierId, $id,
        ]);
        if (!$data['active']) {
            $this->clearDefault($supplierId, $id);
        }
        return $this->find($supplierId, $id) ?? throw EetException::notFound('Zařízení EET nebylo nalezeno.');
    }

    /** @return array<string,mixed> */
    public function deactivate(int $supplierId, int $id): array
    {
        if ($this->find($supplierId, $id) === null) {
            throw EetException::notFound('Zařízení EET nebylo nalezeno.');
        }
        $this->db->pdo()->prepare('UPDATE eet_devices SET active = 0 WHERE supplier_id = ? AND id = ?')
            ->execute([$supplierId, $id]);
        $this->clearDefault($supplierId, $id);
        return $this->find($supplierId, $id) ?? throw EetException::notFound('Zařízení EET nebylo nalezeno.');
    }

    private function clearDefault(int $supplierId, int $id): void
    {
        $this->db->pdo()->prepare('UPDATE eet_settings SET default_device_id = NULL WHERE supplier_id = ? AND default_device_id = ?')
            ->execute([$supplierId, $id]);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $current
     * @return array{unit_id:int,code:string,name:string,cash_register_id:?int,delegation:EetDelegation,active:bool}
     */
    private function validate(int $supplierId, array $input, ?array $current): array
    {
        $pick = static fn (string $key): mixed => array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? null);

        $unitId = self::positiveInt($pick('unit_id'));
        if ($unitId === null) {
            throw EetException::validation('Vyberte evidenční jednotku zařízení.', ['field' => 'unit_id']);
        }
        $unit = $this->db->pdo()->prepare('SELECT id FROM eet_units WHERE supplier_id = ? AND id = ?');
        $unit->execute([$supplierId, $unitId]);
        if ($unit->fetchColumn() === false) {
            throw EetException::validation('Vybraná jednotka EET ve firmě neexistuje.', ['field' => 'unit_id']);
        }

        $code = $pick('code');
        $code = is_string($code) ? trim($code) : '';
        if (!EetIdentifiers::isDeviceCode($code)) {
            throw EetException::validation(
                'Kód zařízení (id_pokl) má 1–20 znaků z písmen bez diakritiky, číslic, mezery a znaků . , : ; / # - _',
                ['field' => 'code'],
            );
        }
        $name = $pick('name');
        $name = is_scalar($name) ? trim((string) $name) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            throw EetException::validation('Název zařízení je povinný a může mít nejvýš 120 znaků.', ['field' => 'name']);
        }

        $rawRegister = $pick('cash_register_id');
        $cashRegisterId = null;
        if ($rawRegister !== null && $rawRegister !== '' && $rawRegister !== 0) {
            $cashRegisterId = self::positiveInt($rawRegister);
            $register = $this->db->pdo()->prepare('SELECT id FROM cash_registers WHERE supplier_id = ? AND id = ?');
            $register->execute([$supplierId, $cashRegisterId ?? 0]);
            if ($cashRegisterId === null || $register->fetchColumn() === false) {
                throw EetException::validation('Vybraná pokladna ve firmě neexistuje.', ['field' => 'cash_register_id']);
            }
        }

        $active = $pick('active');
        $active = $active === null ? true : filter_var($active, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            throw EetException::validation('Příznak aktivního zařízení musí být true/false.', ['field' => 'active']);
        }

        $delegation = $this->delegation($supplierId, $pick);
        // Zařízení v pověření dostává tržby jen výslovnou volbou: pokladna ho nevybere
        // automaticky a nesmí být výchozím zařízením firmy.
        if ($delegation->isDelegated()) {
            if ($cashRegisterId !== null) {
                throw EetException::validation(
                    'Zařízení s evidencí v pověření nelze přiřadit pokladně: tržby z jejích dokladů by se tiše evidovaly v pověření.',
                    ['field' => 'cash_register_id'],
                );
            }
            if ($current !== null && $this->isDefault($supplierId, (int) $current['id'])) {
                throw EetException::validation(
                    'Výchozí zařízení firmy nemůže evidovat v pověření. Nejdřív nastavte jiné výchozí zařízení.',
                    ['field' => 'delegation'],
                );
            }
        }

        return [
            'unit_id' => $unitId,
            'code' => $code,
            'name' => $name,
            'cash_register_id' => $cashRegisterId,
            'delegation' => $delegation,
            'active' => $active,
        ];
    }

    private function isDefault(int $supplierId, int $deviceId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM eet_settings WHERE supplier_id = ? AND default_device_id = ?');
        $stmt->execute([$supplierId, $deviceId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Kódy zařízení, u kterých je dané EIČ pověřujícím poplatníkem.
     *
     * @return list<string>
     */
    public function codesDelegatedBy(int $supplierId, string $eic): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT code FROM eet_devices WHERE supplier_id = ? AND delegation <> 'none' AND delegating_eic = ? ORDER BY code"
        );
        $stmt->execute([$supplierId, $eic]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Pověření zařízení (situace 4 a 5 metodiky). Při druhu `none` se EIČ pověřujícího
     * zahodí, aby přepnutí zpět na vlastní evidenci nenechalo v zařízení starou hodnotu.
     *
     * @param \Closure(string):mixed $pick
     */
    private function delegation(int $supplierId, \Closure $pick): EetDelegation
    {
        $mode = $pick('delegation') ?? EetDelegation::NONE;
        if (!is_string($mode) || !in_array($mode, EetDelegation::MODES, true)) {
            throw EetException::validation('Druh pověření musí být none, single nebo multiple.', ['field' => 'delegation']);
        }
        $rawEic = $mode === EetDelegation::NONE ? null : $pick('delegating_eic');
        $eic = is_string($rawEic) && trim($rawEic) !== '' ? EetIdentifiers::normalizeEic($rawEic) : null;
        if ($rawEic !== null && !is_string($rawEic)) {
            throw EetException::validation('EIČ pověřujícího poplatníka musí být text.', ['field' => 'delegating_eic']);
        }
        $delegation = new EetDelegation($mode, $eic);
        $stmt = $this->db->pdo()->prepare('SELECT eic FROM eet_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $taxpayer = $stmt->fetchColumn();
        $violation = $delegation->violation(is_string($taxpayer) ? $taxpayer : null);
        if ($violation !== null) {
            throw EetException::validation($violation['message'], ['field' => $violation['field']]);
        }
        return $delegation;
    }

    private function assertUniqueCode(int $supplierId, string $code, ?int $exceptId): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM eet_devices WHERE supplier_id = ? AND code = ?');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false && (int) $id !== $exceptId) {
            throw new EetException(EetException::CONFLICT, 'Zařízení s tímto kódem už ve firmě existuje.', 409, ['field' => 'code']);
        }
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'unit_id' => (int) $row['unit_id'],
            'unit_number' => (int) $row['unit_number'],
            'unit_name' => (string) $row['unit_name'],
            'unit_valid_from' => (string) $row['unit_valid_from'],
            'unit_valid_to' => $row['unit_valid_to'] === null ? null : (string) $row['unit_valid_to'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'cash_register_id' => $row['cash_register_id'] === null ? null : (int) $row['cash_register_id'],
            'delegation' => (string) $row['delegation'],
            'delegating_eic' => $row['delegating_eic'] === null ? null : (string) $row['delegating_eic'],
            'active' => (int) $row['active'] === 1,
            'sequence_year' => $row['sequence_year'] === null ? null : (int) $row['sequence_year'],
            'next_sequence' => (int) $row['next_sequence'],
            'created_at' => EetTime::iso($row['created_at']),
            'updated_at' => EetTime::iso($row['updated_at']),
        ];
    }
}
