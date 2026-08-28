<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeException;
use MyInvoice\Service\Payroll\Time\Surcharge\PayrollSurchargeKind;
use PDO;
use PDOException;

/**
 * Kdo si za daný měsíc nárokuje který zákonný příplatek — docházka, nebo ruční
 * měsíční zadání (migrace 1628).
 *
 * Od W20 vedou k témuž nároku dvě cesty a obě umí zapsat mzdový vstup. Kdyby se
 * uplatnily obě, zaměstnanec dostane příplatek dvakrát. Dotaz „nemá to už ta
 * druhá strana?" na to nestačí: schválení docházky a uložení rychlého vstupu
 * jsou dvě transakce, které zamykají různé řádky, takže obě mohou přečíst
 * prázdno a obě zapsat.
 *
 * Nárok se proto nejdřív ZABERE ({@see claim()}), a to zápisem chráněným
 * unikátním klíčem. Druhý zapisovatel narazí na integritu, ne na tichý
 * duplikát, a dozví se, KTERÝ druh a ODKUD koliduje.
 */
final class PayrollSurchargeClaimRepository
{
    public const SOURCE_TIME = 'time';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(private readonly Connection $db) {}

    /**
     * Zabere druh příplatku pro daný zdroj, nebo vysvětlí, proč to nejde.
     *
     * Idempotentní pro TÝŽ zdroj: opakované zabrání téhož nároku je no-op,
     * protože oprava už zabraného měsíce je pořád týž nárok. Pro CIZÍ zdroj je
     * to vždy chyba — a je to ta chyba, kvůli které celá tabulka existuje.
     */
    public function claim(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        PayrollSurchargeKind $kind,
        string $source,
        ?int $userId,
    ): void {
        self::assertSource($source);
        $existing = $this->lock($supplierId, $employmentId, $periodStart, $kind);
        if ($existing !== null) {
            $held = (string) $existing['claim_source'];
            if ($held !== $source) {
                throw self::conflict($kind, $held, $source);
            }

            return;
        }

        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_surcharge_period_claims
                    (supplier_id, employment_id, period_start, surcharge_kind,
                     claim_source, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $employmentId,
                $periodStart,
                $kind->value,
                $source,
                $userId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            // Souběžný zapisovatel byl rychlejší. Přečte se, KDO nárok drží,
            // ať hláška mluví o skutečném stavu, ne o domněnce.
            $row = $this->lock($supplierId, $employmentId, $periodStart, $kind);
            $held = $row === null ? 'time' : (string) $row['claim_source'];
            if ($held === $source) {
                return;
            }
            throw self::conflict($kind, $held, $source);
        }
    }

    /**
     * Pustí nárok, drží-li ho právě tenhle zdroj.
     *
     * Vyprázdněné hodiny v rychlém zadání musí nárok uvolnit, jinak by měsíc už
     * nešlo doplnit z docházky. Cizí nárok se nepouští nikdy — to by byla tichá
     * krádež podkladu.
     */
    public function release(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        PayrollSurchargeKind $kind,
        string $source,
    ): void {
        self::assertSource($source);
        $this->db->pdo()->prepare(
            'DELETE FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ? AND claim_source = ?'
        )->execute([$supplierId, $employmentId, $periodStart, $kind->value, $source]);
    }

    /**
     * Kdo drží které druhy za měsíc. Klíč = hodnota {@see PayrollSurchargeKind}.
     *
     * @param list<int> $employmentIds
     * @return array<int,array<string,string>> vztah → druh → zdroj
     */
    public function sourcesForPeriod(
        int $supplierId,
        string $periodStart,
        array $employmentIds,
    ): array {
        if ($employmentIds === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment_id, surcharge_kind, claim_source
               FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND period_start = ?
                AND employment_id IN ('
            . implode(',', array_fill(0, count($employmentIds), '?'))
            . ')'
        );
        $stmt->execute([$supplierId, $periodStart, ...$employmentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][(string) $row['surcharge_kind']]
                = (string) $row['claim_source'];
        }

        return $result;
    }

    /**
     * `FOR UPDATE` i na neexistující řádek: mezerový zámek na unikátním klíči
     * je právě to, co obě cesty serializuje dřív, než stihnou obě zapsat.
     *
     * @return array<string,mixed>|null
     */
    private function lock(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        PayrollSurchargeKind $kind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, claim_source
               FROM payroll_surcharge_period_claims
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, $kind->value]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function conflict(
        PayrollSurchargeKind $kind,
        string $held,
        string $wanted,
    ): PayrollSurchargeException {
        return PayrollSurchargeException::of(
            'surcharge_source_conflict',
            sprintf(
                '%s (%s) je za toto období evidován %s. Nelze ho zároveň %s — '
                . 'příplatek by se vyplatil dvakrát. Ponechte jen jeden podklad.',
                $kind->label(),
                $kind->section(),
                $held === self::SOURCE_TIME
                    ? 'docházkou'
                    : 'ručním zadáním v rychlém měsíčním vstupu',
                $wanted === self::SOURCE_TIME
                    ? 'promítnout z docházky'
                    : 'zadat ručně',
            ),
        );
    }

    private static function assertSource(string $source): void
    {
        if ($source !== self::SOURCE_TIME && $source !== self::SOURCE_MANUAL) {
            throw new \InvalidArgumentException('Neznámý zdroj nároku na příplatek.');
        }
    }
}
