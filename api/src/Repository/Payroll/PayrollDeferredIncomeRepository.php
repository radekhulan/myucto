<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Potvrzení odloženého příjmu (JMHZ scénář 8, typ 10548) za skončený pracovní
 * vztah a měsíc, do kterého se příjem zúčtoval.
 *
 * Mzdový běh si potvrzení zmrazí do vstupu. Bez něj příjem po skončení vztahu
 * nemá doložené, ke kterému měsíci patří, a výpočet pojistného ho odmítne
 * (`post_termination_income_attribution_unverified`).
 */
final class PayrollDeferredIncomeRepository
{
    /**
     * Typy číselníku „Typ odloženého příjmu", které aplikace zpracuje sama:
     * 1 příjem po skončení, 4 roční zúčtování po skončení a jejich spojení 5
     * (Pravidla podání JMHZ 1.4.5, kap. 6 body 1 a 3, číselník 10548).
     * Náhrada mzdy při neplatném skončení (2, 3, 6) má vlastní pravidla pro
     * ELDP a pojistné a potvrdit ji tu nejde.
     */
    public const SUPPORTED_TYPES = ['1', '4', '5'];

    /**
     * Typy, které se hlásí bez zúčtovaného příjmu. Mzdový běh si skončený
     * vztah s takovým potvrzením vezme, i když za měsíc nemá žádný vstup
     * (typ 4: 10286 = 0, Pravidla podání JMHZ 1.4.5, kap. 6 bod 3).
     */
    public const TYPES_WITHOUT_INCOME = ['4'];

    /**
     * Typy s příjmem zúčtovaným po skončení, jehož pojistné patří do měsíce
     * zúčtování a ELDP nese kód s „P" (kap. 6 bod 1): typ 1 a typ 5
     * (příjem po skončení spolu s ročním zúčtováním po skončení).
     */
    public const TYPES_WITH_POST_TERMINATION_INCOME = ['1', '5'];

    /**
     * Typy s ročním zúčtováním po skončení, hlásí se jen za leden až březen
     * (kap. 6 bod 3 b).
     */
    public const TYPES_WITH_ANNUAL_SETTLEMENT = ['4', '5'];

    /**
     * Náhrada mzdy při neplatném skončení: 2 (vztah dál neprobíhá), 3 (vztah
     * probíhá) a 6 (2 + roční zúčtování). Zůstávají na ePortálu ČSSZ, protože
     * je aplikace doložitelně nesestaví:
     *  - ELDP se hlásí za každý měsíc s náhradou (10537/10538, Pravidla podání
     *    JMHZ 1.4.5, kap. 6 bod 2) s vyměřovacím základem připadajícím na
     *    měsíc; rozpočítání náhrady podle soudního rozhodnutí aplikace nezná,
     *  - 10476 se podle Pravidel hlásí rovněž po měsících, ale `eldpObdobi`
     *    v XSD 1.4.3.6 (formOdlozenyPrijem.xsd) pro něj místo nemá,
     *  - měsíce před 1. 1. 2026 se podle úvodu kap. 6 opravují opravným ELDP,
     *    kdežto vzorové hlášení MPSV (TEST_odložený příjem_1.1.2026, sloupce
     *    H až M) je vykazuje přes 10537/10538,
     *  - typu 2 předchází REGZEC změna s výmazem data skončení (bod 2.1 a).
     */
    public const INVALID_TERMINATION_TYPES = ['2', '3', '6'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @return list<array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}>
     */
    public function listForEmployment(int $supplierId, int $employmentId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employment_id, period_start, deferred_type, note, row_version, updated_at
               FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY period_start DESC, id DESC',
        );
        $statement->execute([$supplierId, $employmentId]);

        return array_map(
            static fn (array $row): array => self::cast($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Potvrzení za měsíc po vztazích, zdroj pro zmrazený vstup mzdového běhu.
     *
     * @return array<int,array{deferred_type:string,note:?string,row_version:int}>
     */
    public function forPeriod(int $supplierId, string $periodStart): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT employment_id, deferred_type, note, row_version
               FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND period_start = ?',
        );
        $statement->execute([$supplierId, $periodStart]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']] = [
                'deferred_type' => (string) $row['deferred_type'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'row_version' => (int) $row['row_version'],
            ];
        }

        return $result;
    }

    /**
     * Uloží potvrzení za měsíc. Odložený příjem se váže na SKONČENÝ vztah
     * (kontrola 336 ČSSZ): měsíc zúčtování musí ležet až za dnem skončení.
     *
     * @return array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}
     */
    public function save(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $deferredType,
        ?string $note,
        ?int $userId,
    ): array {
        if (in_array($deferredType, self::INVALID_TERMINATION_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Náhradu mzdy při neplatném skončení (odložený příjem typu 2, 3 a 6) aplikace'
                . ' sama nevykáže: hlásí se za každý měsíc s náhradou zvlášť (měsíc a rok'
                . ' 10537/10538 s vlastním evidenčním listem) a typu 2 předchází změna'
                . ' registrace s výmazem data skončení a soudním rozhodnutím v příloze.'
                . ' Hlášení podejte ručně přes ePortál ČSSZ.',
            );
        }
        if (!in_array($deferredType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Typ odloženého příjmu musí být z číselníku ČSSZ (1 až 6).',
            );
        }
        // Pravidla podání JMHZ 1.4.5, kap. 6 bod 3 b): roční zúčtování po
        // skončení se hlásí za rozhodné období leden až březen.
        if (in_array($deferredType, self::TYPES_WITH_ANNUAL_SETTLEMENT, true)
            && !in_array(substr($periodStart, 5, 2), ['01', '02', '03'], true)
        ) {
            throw new \InvalidArgumentException(
                'Roční zúčtování po skončení pracovního vztahu se hlásí jen za leden, únor nebo březen.',
            );
        }
        $employment = $this->db->pdo()->prepare(
            'SELECT end_date, relation_type FROM payroll_employments WHERE supplier_id = ? AND id = ?',
        );
        $employment->execute([$supplierId, $employmentId]);
        $row = $employment->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $endDate = $row['end_date'];
        // Zdravotní pojistné z příjmu po skončení dohody patří do měsíce jejího
        // konce; pozdější měsíc bez příjmu výpočet nespočítá (pojistka je
        // i v běhu, `post_termination_annual_settlement_agreement_unsupported`).
        if (in_array($deferredType, self::TYPES_WITHOUT_INCOME, true)
            && in_array($row['relation_type'], ['dpp', 'dpc'], true)
        ) {
            throw new \InvalidArgumentException(
                'Roční zúčtování po skončení dohody o provedení práce nebo o pracovní činnosti'
                . ' aplikace sama nevykáže. Hlášení za dohodu podejte ručně přes ePortál ČSSZ.',
            );
        }
        if (!is_string($endDate) || $endDate === '' || substr($endDate, 0, 7) >= substr($periodStart, 0, 7)) {
            throw new \InvalidArgumentException(
                'Odložený příjem se potvrzuje za měsíc po skončení pracovního vztahu.'
                . ' Vztah musí mít vyplněný den skončení dřív, než začíná měsíc zúčtování.',
            );
        }
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_deferred_incomes
                (supplier_id, employment_id, period_start, deferred_type, note,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                deferred_type = VALUES(deferred_type),
                note = VALUES(note),
                updated_by = VALUES(updated_by),
                row_version = row_version + 1',
        )->execute([
            $supplierId,
            $employmentId,
            $periodStart,
            $deferredType,
            $note,
            $userId,
            $userId,
        ]);

        foreach ($this->listForEmployment($supplierId, $employmentId) as $row) {
            if ($row['period_start'] === $periodStart) {
                return $row;
            }
        }
        throw new \LogicException('Uložené potvrzení odloženého příjmu se nepodařilo načíst.');
    }

    public function delete(int $supplierId, int $employmentId, string $periodStart): bool
    {
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM payroll_employment_deferred_incomes
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?',
        );
        $statement->execute([$supplierId, $employmentId, $periodStart]);

        return $statement->rowCount() > 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int,employment_id:int,period_start:string,deferred_type:string,note:?string,row_version:int,updated_at:string}
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'employment_id' => (int) $row['employment_id'],
            'period_start' => (string) $row['period_start'],
            'deferred_type' => (string) $row['deferred_type'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'row_version' => (int) $row['row_version'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
