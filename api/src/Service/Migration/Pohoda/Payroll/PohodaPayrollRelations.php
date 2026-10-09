<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollImportLinkRepository;
use MyInvoice\Service\Codebook\HealthInsurers;
use MyInvoice\Service\License\LicenseCapacityGate;
use MyInvoice\Service\License\LicensePayrollLimitExceeded;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\CzechBirthNumber;
use MyInvoice\Service\Payroll\Import\Attendance\AttendanceMeaning;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEmploymentWriter;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverEvidencePeriod;
use MyInvoice\Service\Payroll\Migration\PayrollTakeoverPersonLookup;
use MyInvoice\Service\Payroll\PayrollPersonCreateService;

/**
 * Pracovní vztahy z PAMICA (`ZAMpomer`) založené ve firmě dřív, než se převedou měsíce.
 *
 * PAMICA vede vztahy na osobě (`ZAM`): souběh i opakovaný nástup jsou další `ZAMpomer`
 * téže osoby s osobním číslem `OsCislo-Poradi` ({@see PohodaPayrollPeople::personalNumber()}).
 * Import docházky, kterým jdou měsíce, ale umí jen „osobu, která ve firmě není" - druhý
 * vztah téže osoby zakládal jako novou osobu a narazil na unikátní rodné číslo, nebo ho
 * podle rodného čísla či uložené vazby jména přiřadil k jinému vztahu téže osoby. Proto se
 * vztahy zakládají tady, po jednom a podle identity osoby:
 *
 *  1. vztah s tímto osobním číslem ve firmě je → nic,
 *  2. jiný vztah téže osoby z PAMICA ve firmě je, nebo osobu najde rodné číslo či OIČ
 *     ({@see PayrollTakeoverPersonLookup}) → další vztah téže osoby,
 *  3. jinak nová osoba s tímto vztahem.
 *
 * Druh (DPP podle `JeDPP`, jinak pracovní poměr), nástup a úvazek jsou ze zdroje; skončení
 * zapíše krok osob ({@see PohodaPayrollPeopleWriter}). Vazba osobního čísla na vztah se uloží
 * do vazeb importu docházky, takže měsíc pak řádek přiřadí právě tomuto vztahu.
 *
 * Zakládají se vztahy, které mají v převáděném roce uzavřenou mzdu, a vztahy, které v roce
 * začaly a ke dni exportu trvají (nástup bez první výplaty). Vztah bez mzdy, který už skončil,
 * se nezakládá: MyÚčto by ho nemělo z čeho doplnit.
 */
final class PohodaPayrollRelations
{
    private const SAVEPOINT = 'pohoda_payroll_relation';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollTakeoverEmploymentWriter $employments,
        private readonly PayrollTakeoverPersonLookup $lookup,
        private readonly PayrollPersonCreateService $personCreate,
        private readonly PayrollImportLinkRepository $links,
        private readonly LicenseCapacityGate $license,
    ) {}

    /**
     * @return list<array{personal_number:string,person_key:string,first_name:string,last_name:string,birth_number:?string,
     *   birth_date:?string,oic:?string,insurer_code:?string,relation_type:string,start:string,end:?string,weekly_hours:?string,order:int}>
     */
    public static function read(string $file, int $year, ?string $exportedOn): array
    {
        $people = [];
        $relations = [];
        $payslips = [];
        $insurers = [];
        foreach (PohodaXml::scan($file, ['ZAM', 'ZAMpomer', 'MZ', 'sMzPoj']) as $table => $row) {
            if ($table === 'ZAM') {
                $people[PohodaXml::text($row, 'ID')] = $row;
            } elseif ($table === 'ZAMpomer') {
                $relations[PohodaXml::text($row, 'ID')] = $row;
            } elseif ($table === 'sMzPoj') {
                $insurers[PohodaXml::text($row, 'ID')] = PohodaXml::text($row, 'Kod');
            } elseif ((int) PohodaXml::text($row, 'Rok') === $year
                && !PohodaPayrollConverter::openPeriod(sprintf('%04d-%02d', $year, (int) PohodaXml::text($row, 'RelMes')), $exportedOn)
            ) {
                $period = sprintf('%04d-%02d-01', $year, (int) PohodaXml::text($row, 'RelMes'));
                $key = PohodaXml::text($row, 'RefPomer');
                $payslips[$key] = min($payslips[$key] ?? $period, $period);
            }
        }
        $count = [];
        foreach ($relations as $relation) {
            $count[PohodaXml::text($relation, 'RefZAM')] = ($count[PohodaXml::text($relation, 'RefZAM')] ?? 0) + 1;
        }
        $today = $exportedOn ?? date('Y-m-d');
        $out = [];
        foreach ($relations as $id => $relation) {
            $personKey = PohodaXml::text($relation, 'RefZAM');
            $person = $people[$personKey] ?? null;
            $start = self::date(PohodaXml::text($relation, 'DatNast')) ?? self::date(PohodaXml::text($relation, 'DatVstup'));
            $end = self::date(PohodaXml::text($relation, 'DatOdch'));
            $paid = $payslips[(string) $id] ?? null;
            if ($person === null) {
                continue;
            }
            // Vztah se mzdou v roce se zakládá vždy, i když ji PAMICA zúčtovala po skončení
            // (doplatek) nebo vztah nemá nástup; bez nástupu začíná měsícem první mzdy.
            if ($paid !== null) {
                $start ??= $paid;
            } elseif ($start === null || $start > sprintf('%04d-12-31', $year)
                || ($end !== null && ($end < sprintf('%04d-01-01', $year) || $end < $today))) {
                continue;
            }
            $dpp = in_array(strtolower(trim(PohodaXml::text($relation, 'JeDPP'))), ['1', '-1', 'true'], true);
            $weekly = PohodaXml::num($relation, 'TUvazek');
            $birthNumber = PohodaXml::text($person, 'RodCisl');
            $insurer = $insurers[PohodaXml::text($person, 'RefPoj')] ?? '';
            $oic = preg_replace('/\D/', '', PohodaXml::text($person, 'OIC')) ?? '';
            $out[] = [
                'personal_number' => PohodaPayrollPeople::personalNumber($person, $relation, $count[$personKey] ?? 1),
                'person_key' => $personKey,
                'first_name' => PohodaXml::text($person, 'Jmeno'),
                'last_name' => PohodaXml::text($person, 'Prijmeni'),
                'birth_number' => $birthNumber === '' ? null : $birthNumber,
                'birth_date' => self::date(PohodaXml::text($person, 'DatNar')),
                'oic' => $oic === '' ? null : $oic,
                'insurer_code' => HealthInsurers::isValid($insurer) ? $insurer : null,
                'relation_type' => $dpp ? 'dpp' : 'employment',
                'start' => $start,
                'end' => $end,
                'weekly_hours' => !$dpp && $weekly > 0 ? sprintf('%.2F', $weekly) : null,
                'order' => (int) (PohodaXml::text($relation, 'Poradi') ?: '1'),
            ];
        }
        // První vztah osoby (nejnižší pořadí) zakládá osobu, další se k ní přidávají.
        usort($out, static fn (array $a, array $b): int => [$a['person_key'], $a['order'], $a['personal_number']]
            <=> [$b['person_key'], $b['order'], $b['personal_number']]);

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $relations {@see self::read()}
     * @return array{persons_created:int,employments_added:int}
     */
    public function ensure(int $supplierId, ?int $userId, array $relations, ImportProtocol $protocol, string $step, int $messageLimit): array
    {
        $today = date('Y-m-d');
        $policy = PohodaPayrollTakeover::policy();
        $created = ['persons_created' => 0, 'employments_added' => 0];
        $messages = 0;
        $personStarts = PayrollTakeoverEvidencePeriod::earliestByPerson(array_map(
            static fn (array $relation): array => [(string) $relation['person_key'], substr((string) $relation['start'], 0, 7) . '-01'],
            $relations,
        ));
        foreach ($relations as $relation) {
            $number = (string) $relation['personal_number'];
            if ($this->employmentId($supplierId, $number) !== null) {
                continue;
            }
            $pdo = $this->db->pdo();
            $owns = !$pdo->inTransaction();
            $owns ? $pdo->beginTransaction() : $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
            try {
                $employeeId = $this->siblingEmployee($supplierId, $relations, $relation)
                    ?? $this->lookup->employeeId($supplierId, $relation['birth_number'], $relation['oic'],
                        $relation['first_name'], $relation['last_name'], $relation['birth_date']);
                $fullName = trim($relation['first_name'] . ' ' . $relation['last_name']) ?: 'Zaměstnanec ' . $number;
                if ($employeeId !== null) {
                    $employmentId = $this->employments->addEmployment($supplierId, $employeeId, $fullName, $number,
                        $relation['relation_type'], $relation['start'], null, $relation['weekly_hours'], $userId);
                    $created['employments_added']++;
                } else {
                    // Osobu zakládá vztah s nejnižším pořadím, který nemusí být nejstarší.
                    // Zdravotní pojištění od jeho nástupu by nechalo měsíce staršího vztahu
                    // bez pojištění; takovou osobu založí bez pojišťovny a evidenci od
                    // nejstaršího vztahu zapíše krok osob (PohodaPayrollTakeover::personEvidenceStarts).
                    $olderRelation = substr((string) $relation['start'], 0, 7) . '-01'
                        > ($personStarts[(string) $relation['person_key']] ?? '');
                    [$employeeId, $employmentId] = $this->license->mutatePayrollEmployees(
                        fn (): array => $this->createPerson($supplierId, $relation, $fullName, $userId, !$olderRelation),
                    );
                    $created['persons_created']++;
                }
                $this->employments->activateTakenOver($supplierId, $employmentId, $relation['start'], $today, $userId, $policy);
                $this->links->save($supplierId, AttendanceMeaning::SOURCE_SYSTEM, PayrollImportLinkRepository::KIND_PERSONAL_NUMBER,
                    $number, $employeeId, $employmentId, $userId);
                $owns ? $pdo->commit() : $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } catch (\InvalidArgumentException|\DomainException|PayrollEmploymentConflictException|LicensePayrollLimitExceeded|\PDOException $e) {
                if ($owns) {
                    $pdo->rollBack();
                } elseif ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                    $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
                }
                if ($e instanceof \PDOException && $e->getCode() !== '23000') {
                    throw $e;
                }
                $protocol->count($step, 'persons_failed');
                if ($messages++ < $messageLimit) {
                    $protocol->warn($step, 'person_failed', "Osobní číslo {$number}: pracovní vztah se nepodařilo založit - "
                        . ($e instanceof LicensePayrollLimitExceeded ? 'dalšího aktivního zaměstnance lze přidat až po rozšíření mzdového doplňku.' : $e->getMessage()),
                        ['personal_number' => $number]);
                }
            }
        }

        return $created;
    }

    /**
     * Nová osoba s prvním vztahem. Rodné číslo nebo kód pojišťovny, které kontrola odmítne,
     * osobu nezastaví (převod je ohlásí z měsíčního sešitu): založí se bez nich.
     *
     * @param array<string,mixed> $relation
     * @return array{0:int,1:int}
     */
    private function createPerson(int $supplierId, array $relation, string $fullName, ?int $userId, bool $seedInsurer = true): array
    {
        $birthNumber = $relation['birth_number'];
        if ($birthNumber !== null) {
            try {
                $birthNumber = CzechBirthNumber::normalize($birthNumber);
            } catch (\InvalidArgumentException) {
                $birthNumber = null;
            }
        }
        $input = [
            'full_name' => $fullName,
            'first_name' => $relation['first_name'],
            'last_name' => $relation['last_name'],
            'birth_date' => $relation['birth_date'],
            'birth_number' => $birthNumber,
            'health_insurer_code' => $seedInsurer ? $relation['insurer_code'] : null,
            'relation_type' => $relation['relation_type'],
            'planned_start_on' => $relation['start'],
            'weekly_hours' => $relation['weekly_hours'],
            'employment_code' => $this->employments->employmentCodeAvailable($supplierId, (string) $relation['personal_number'])
                ? (string) $relation['personal_number'] : null,
        ];
        $person = $this->personCreate->create($supplierId, $input, $userId, null, 'pohoda-import');
        $employeeId = (int) ($person['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierId, $employeeId]);
        $employmentId = (int) $stmt->fetchColumn();
        if ($employmentId <= 0) {
            throw new \LogicException('Nově založený pracovní vztah nebyl nalezen.');
        }

        return [$employeeId, $employmentId];
    }

    /**
     * Osoba z jiného vztahu téže osoby v PAMICA, který už ve firmě je.
     *
     * @param list<array<string,mixed>> $relations
     * @param array<string,mixed> $relation
     */
    private function siblingEmployee(int $supplierId, array $relations, array $relation): ?int
    {
        foreach ($relations as $other) {
            if ($other['person_key'] !== $relation['person_key'] || $other['personal_number'] === $relation['personal_number']) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare('SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND code = ? LIMIT 1');
            $stmt->execute([$supplierId, $other['personal_number']]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    private function employmentId(int $supplierId, string $code): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM payroll_employments WHERE supplier_id = ? AND code = ? LIMIT 1');
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Datum `Y-m-d`; prázdné nebo nulové datum Accessu (před rokem 1901) = null. */
    private static function date(string $value): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && (int) $m[1] >= 1901 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
