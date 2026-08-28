<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Component\PayrollComponentDefaults;
use PDO;
use PDOException;

final class PayrollComponentRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentDeletionRepository $deletion,
        private readonly PayrollComponentDefaults $defaults,
    ) {}

    /**
     * Kódy složek, které si aplikace zakládá sama (`ensureDefaults()`). Mazání je
     * potřebuje znát: smazaná systémová složka by se při dalším výpisu vrátila,
     * takže tam mazání nedává smysl a nabízí se místo něj deaktivace.
     *
     * @return list<string>
     */
    public static function defaultCodes(): array
    {
        return PayrollComponentDefaults::codes();
    }

    /** @return list<array<string,mixed>> */
    public function list(int $supplierId, ?string $effectiveOn = null): array
    {
        $this->ensureDefaults($supplierId);
        $params = [$supplierId];
        $where = '';
        if ($effectiveOn !== null) {
            $where = ' AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)';
            $params[] = $effectiveOn;
            $params[] = $effectiveOn;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_component_definitions
              WHERE supplier_id = ?'
            . $where
            . ' ORDER BY is_active DESC, code ASC'
        );
        $stmt->execute($params);

        return $this->deletion->decorate(
            $supplierId,
            array_map(
                self::cast(...),
                PayrollTimeValue::rows(
                    $stmt->fetchAll(PDO::FETCH_ASSOC),
                    'payroll_component_definitions',
                ),
            ),
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $id): ?array
    {
        $this->ensureDefaults($supplierId);
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false
            ? null
            : $this->deletion->decorateOne(
                $supplierId,
                self::cast(PayrollTimeValue::row($row, 'payroll_component_definition')),
            );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function create(int $supplierId, array $data): array
    {
        $this->ensureDefaults($supplierId);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $this->prepareVersionInterval($supplierId, $data);
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_component_definitions
                    (supplier_id, code, name, component_kind, value_kind,
                     frequency_kind, tax_treatment,
                     social_participation_treatment, social_treatment,
                     health_participation_treatment, health_treatment,
                     average_earning_treatment,
                     enforcement_treatment, jmhz_treatment,
                     statistics_treatment, accounting_debit_code,
                     accounting_credit_code, annual_limit_minor, exemption_basket,
                     exemption_basis, valid_from, valid_to, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $supplierId,
                $data['code'],
                $data['name'],
                $data['component_kind'],
                $data['value_kind'],
                $data['frequency_kind'],
                $data['tax_treatment'],
                $data['social_participation_treatment'],
                $data['social_treatment'],
                $data['health_participation_treatment'],
                $data['health_treatment'],
                $data['average_earning_treatment'],
                $data['enforcement_treatment'],
                $data['jmhz_treatment'],
                $data['statistics_treatment'],
                $data['accounting_debit_code'],
                $data['accounting_credit_code'],
                $data['annual_limit_minor'],
                $data['exemption_basket'],
                $data['exemption_basis'],
                $data['valid_from'],
                $data['valid_to'],
                PayrollTimeValue::bool($data['is_active'] ?? null, 'is_active') ? 1 : 0,
            ]);
            $id = PayrollTimeValue::int($pdo->lastInsertId(), 'last_insert_id');
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException(
                    'Tato verze mzdové složky už existuje nebo překrývá jinou platnost.',
                    previous: $e,
                );
            }
            self::rethrowCheckViolation($e);
            throw $e;
        } catch (\Throwable $e) {
            $this->rollbackOwned($pdo, $ownsTransaction);
            throw $e;
        }

        return $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Mzdovou složku se nepodařilo načíst.');
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function update(
        int $supplierId,
        int $id,
        array $data,
        int $expectedVersion,
    ): ?array {
        $current = $this->find($supplierId, $id);
        if ($current === null) {
            return null;
        }
        if (PayrollTimeValue::string($current['code'] ?? null, 'code')
                !== PayrollTimeValue::string($data['code'] ?? null, 'code')
            || PayrollTimeValue::string(
                $current['valid_from'] ?? null,
                'valid_from',
            ) !== PayrollTimeValue::string(
                $data['valid_from'] ?? null,
                'valid_from',
            )
        ) {
            throw new \InvalidArgumentException(
                'Kód a začátek platnosti verze nelze měnit; založte novou verzi.'
            );
        }
        $this->assertNotUsedByApprovedInput($supplierId, $id);
        $this->assertJmhzMappingCompatible(
            $supplierId,
            $id,
            PayrollTimeValue::string($data['jmhz_treatment'] ?? null, 'jmhz_treatment'),
        );
        $currentVersion = PayrollTimeValue::int(
            $current['row_version'] ?? null,
            'row_version',
        );
        if ($currentVersion !== $expectedVersion) {
            throw new PayrollComponentConflictException($currentVersion);
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_component_definitions
                SET code = ?, name = ?, component_kind = ?, value_kind = ?,
                    frequency_kind = ?, tax_treatment = ?,
                    social_participation_treatment = ?, social_treatment = ?,
                    health_participation_treatment = ?, health_treatment = ?,
                    average_earning_treatment = ?,
                    enforcement_treatment = ?, jmhz_treatment = ?,
                    statistics_treatment = ?, accounting_debit_code = ?,
                    accounting_credit_code = ?, annual_limit_minor = ?,
                    exemption_basket = ?, exemption_basis = ?,
                    valid_from = ?, valid_to = ?, is_active = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        try {
            $stmt->execute([
                $data['code'],
                $data['name'],
                $data['component_kind'],
                $data['value_kind'],
                $data['frequency_kind'],
                $data['tax_treatment'],
                $data['social_participation_treatment'],
                $data['social_treatment'],
                $data['health_participation_treatment'],
                $data['health_treatment'],
                $data['average_earning_treatment'],
                $data['enforcement_treatment'],
                $data['jmhz_treatment'],
                $data['statistics_treatment'],
                $data['accounting_debit_code'],
                $data['accounting_credit_code'],
                $data['annual_limit_minor'],
                $data['exemption_basket'],
                $data['exemption_basis'],
                $data['valid_from'],
                $data['valid_to'],
                PayrollTimeValue::bool($data['is_active'] ?? null, 'is_active') ? 1 : 0,
                $supplierId,
                $id,
                $expectedVersion,
            ]);
        } catch (PDOException $e) {
            self::rethrowCheckViolation($e);
            throw $e;
        }
        if ($stmt->rowCount() !== 1) {
            $latest = $this->find($supplierId, $id);
            throw new PayrollComponentConflictException(
                $latest === null
                    ? $expectedVersion
                    : PayrollTimeValue::int(
                        $latest['row_version'] ?? null,
                        'row_version',
                    ),
            );
        }

        return $this->find($supplierId, $id);
    }

    /**
     * Založí firmě chybějící verze výchozího číselníku mzdových složek.
     *
     * Dvě věci, které se tady dřív neděly:
     *
     *  - `annual_limit_minor` VŮBEC NEBYL mezi vkládanými sloupci, takže výchozí
     *    benefitní složky měly roční limit NULL. `PayrollInputRepository::approve()`
     *    hlídá strop jen u složky s NENULOVÝM limitem, takže se roční limit
     *    osvobození benefitů u výchozích složek nehlídal vůbec. Od migrace 1480
     *    zákonnou hranici drží `exemption_basket` a strop na složce zůstal jen
     *    jako vlastní pravidlo zaměstnavatele — vkládá se proto koš, ne limit.
     *  - `valid_from` bylo natvrdo `'2026-01-01'`, takže legislativní změnu
     *    klasifikace nešlo do existující firmy rozvést: `INSERT IGNORE` narazil na
     *    `uq_payroll_component_version (supplier_id, code, valid_from)` a tiše
     *    neudělal nic.
     *
     * Verze se zakládají chronologicky. Před založením verze, která není nejstarší,
     * se předchozí OTEVŘENÉ verzi téhož kódu dopočítá `valid_to` na den před
     * účinností nové — stejný vzor jako {@see prepareVersionInterval()} u ručně
     * zakládané verze. Historie se tím nepřepisuje: stará verze si ponechá svoje
     * hodnoty i svůj interval a schválené mzdové vstupy dál ukazují na ni.
     *
     * Obojí je idempotentní: `INSERT IGNORE` podruhé nic nevloží a uzavírací UPDATE
     * podruhé nenajde otevřenou starší verzi.
     */
    public function ensureDefaults(int $supplierId): void
    {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_component_definitions
                (supplier_id, code, name, component_kind, value_kind,
                 frequency_kind, tax_treatment,
                 social_participation_treatment, social_treatment,
                 health_participation_treatment, health_treatment,
                 average_earning_treatment,
                 enforcement_treatment, jmhz_treatment, statistics_treatment,
                 exemption_basket, exemption_basis, valid_from)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $closePrevious = $this->db->pdo()->prepare(
            'UPDATE payroll_component_definitions
                SET valid_to = DATE_SUB(?, INTERVAL 1 DAY),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND code = ?
                AND valid_from < ? AND valid_to IS NULL'
        );
        foreach ($this->defaults->versions() as $index => $version) {
            foreach ($version['rows'] as $row) {
                if ($index > 0) {
                    $closePrevious->execute([
                        $version['valid_from'],
                        $supplierId,
                        $row['code'],
                        $version['valid_from'],
                    ]);
                }
                $insert->execute([
                    $supplierId,
                    $row['code'],
                    $row['name'],
                    $row['component_kind'],
                    $row['value_kind'],
                    $row['frequency_kind'],
                    $row['tax_treatment'],
                    $row['social_treatment'],
                    $row['social_treatment'],
                    $row['health_treatment'],
                    $row['health_treatment'],
                    $row['average_earning_treatment'],
                    $row['enforcement_treatment'],
                    $row['jmhz_treatment'],
                    $row['statistics_treatment'],
                    $row['exemption_basket'],
                    $row['exemption_basis'],
                    $version['valid_from'],
                ]);
            }
        }
    }

    private function assertJmhzMappingCompatible(
        int $supplierId,
        int $componentId,
        string $jmhzTreatment,
    ): void {
        if ($jmhzTreatment === 'included') {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_component_jmhz_mappings
              WHERE supplier_id = ? AND component_definition_id = ? AND is_active = 1
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $componentId]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException(
                'Před vyřazením složky z JMHZ nejprve zrušte její aktivní mapování.',
            );
        }
    }

    /** @param array<string,mixed> $data */
    private function prepareVersionInterval(int $supplierId, array $data): void
    {
        $code = PayrollTimeValue::string($data['code'] ?? null, 'code');
        $validFrom = PayrollTimeValue::string(
            $data['valid_from'] ?? null,
            'valid_from',
        );
        $requestedTo = ($data['valid_to'] ?? null) === null
            ? null
            : PayrollTimeValue::string($data['valid_to'], 'valid_to');
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, valid_from, valid_to
               FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ?
              ORDER BY valid_from DESC
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $code]);
        $versions = PayrollTimeValue::rows(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            'component_versions',
        );
        foreach ($versions as $version) {
            $from = PayrollTimeValue::string(
                $version['valid_from'] ?? null,
                'valid_from',
            );
            $to = ($version['valid_to'] ?? null) === null
                ? null
                : PayrollTimeValue::string($version['valid_to'], 'valid_to');
            if ($from === $validFrom
                || ($from < $validFrom && ($to === null || $to >= $validFrom))
                || ($from > $validFrom
                    && ($requestedTo === null || $from <= $requestedTo))
            ) {
                if ($from < $validFrom && $to === null) {
                    $this->db->pdo()->prepare(
                        'UPDATE payroll_component_definitions
                            SET valid_to = DATE_SUB(?, INTERVAL 1 DAY),
                                row_version = row_version + 1
                          WHERE supplier_id = ? AND id = ? AND valid_to IS NULL'
                    )->execute([
                        $validFrom,
                        $supplierId,
                        PayrollTimeValue::int($version['id'] ?? null, 'id'),
                    ]);
                    continue;
                }
                throw new \InvalidArgumentException(
                    'Platnost verze mzdové složky se překrývá s existující verzí.'
                );
            }
        }
    }

    private function assertNotUsedByApprovedInput(int $supplierId, int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_inputs
              WHERE supplier_id = ? AND component_id = ?
                AND status IN ("approved", "locked")
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $id]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException(
                'Použitou mzdovou složku nelze měnit; založte novou účinnou verzi.'
            );
        }
    }

    /**
     * Porušení databázového CHECKu přeloží na chybu vstupu (422), ne na 500.
     *
     * MariaDB hlásí CHECK jako SQLSTATE HY000 s driver kódem 3819
     * („Constraint %s failed"), tedy MIMO třídu 23000, kterou repozitář hlídal.
     * Neošetřená `PDOException` se propsala do HTTP 500 bez použitelné hlášky —
     * přesně to potkalo analytickou předkontaci `521.100`, dokud ji CHECK
     * na `payroll_component_definitions` odmítal (migrace 1613).
     *
     * Kontrola je záměrně obecná: kterýkoli budoucí CHECK na téhle tabulce
     * dostane srozumitelnou hlášku sám od sebe, místo aby se to muselo
     * doplňovat po jednom.
     */
    private static function rethrowCheckViolation(PDOException $e): void
    {
        $driverCode = $e->errorInfo[1] ?? null;
        if ((string) $e->getCode() !== 'HY000' || (int) $driverCode !== 3819) {
            return;
        }
        $constraint = null;
        if (preg_match(
            '/CONSTRAINT `?([A-Za-z0-9_]+)`? failed/i',
            (string) ($e->errorInfo[2] ?? $e->getMessage()),
            $match,
        ) === 1) {
            $constraint = $match[1];
        }

        throw new \InvalidArgumentException(
            $constraint === 'chk_payroll_component_accounts'
                ? 'Účet mzdové složky musí být třímístná syntetika, '
                    . 'volitelně s analytikou (například 521.100).'
                : 'Mzdová složka nesplňuje databázové omezení'
                    . ($constraint === null ? '.' : " {$constraint}."),
            previous: $e,
        );
    }

    private function rollbackOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach (['id', 'supplier_id', 'annual_limit_minor', 'row_version'] as $key) {
            if (($row[$key] ?? null) !== null) {
                $row[$key] = PayrollTimeValue::int($row[$key], $key);
            }
        }
        $row['is_active'] = PayrollTimeValue::bool(
            $row['is_active'] ?? null,
            'is_active',
        );
        return $row;
    }
}
