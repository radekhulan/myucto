<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Employment;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollPeopleRepository;
use MyInvoice\Repository\Payroll\PayrollTermsSettledException;
use MyInvoice\Service\Payroll\Import\Registration\RegistrationImportWriter;
use MyInvoice\Service\Payroll\PayrollEmploymentValidator;
use PDO;

/**
 * Otevřená dohoda o provedení práce osoby.
 *
 * Brigádník, kterému se ke každé akci zakládá nová DPP, má u ČSSZ tolik
 * registrací, kolik bylo akcí, a každou je potřeba přihlásit i odhlásit.
 * Jedna dlouhodobá DPP (třeba do konce roku) znamená jednu registraci. Import
 * docházky i ruční založení vztahu se proto před založením další DPP ptají,
 * jestli osoba nemá otevřenou dohodu, kterou jde použít.
 *
 * Otevřená = DPP ve stavu plánovaná, předregistrovaná, aktivní nebo přerušená,
 * bez sjednaného konce nebo s koncem v den nástupu nové dohody či později.
 * Použít místo nové jde jen tu, která nástup nové dohody už pokrývá; sjednaný
 * konec se jí v případě potřeby prodlouží.
 */
final class PayrollOpenAgreements
{
    public const STATUSES = ['planned', 'preregistered', 'active', 'suspended'];

    public const GUIDANCE = 'Jedna dlouhodobá DPP znamená jednu registraci u ČSSZ. Každou novou dohodu je potřeba '
        . 'u ČSSZ znovu přihlásit a po skončení odhlásit, proto je výhodnější použít otevřenou dohodu '
        . 'a případně jí prodloužit sjednaný konec.';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentValidator $validator,
    ) {}

    /**
     * Neukončené DPP firmy, případně jen vybraných osob. Bez ohledu na konec:
     * otevřenost vůči konkrétnímu nástupu rozhodne {@see self::isOpenFor()}.
     *
     * @param list<int>|null $employeeIds
     * @return list<array{employment_id:int,employee_id:int,employee_name:string,code:string,status:string,start_date:?string,end_date:?string,row_version:int}>
     */
    public function candidates(int $supplierId, ?array $employeeIds = null): array
    {
        if ($employeeIds !== null) {
            $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn (int $id): bool => $id > 0)));
            if ($employeeIds === []) {
                return [];
            }
        }
        $statuses = implode(',', array_fill(0, count(self::STATUSES), '?'));
        $sql = 'SELECT employment.id, employment.employee_id, employment.code, employment.status,
                       COALESCE(employment.actual_start_date, employment.start_date) AS start_date,
                       employment.end_date, employment.row_version,
                       ' . PayrollPeopleRepository::fullNameExpression('employee') . ' AS employee_name
                  FROM payroll_employments employment
                  JOIN payroll_employees employee
                    ON employee.supplier_id = employment.supplier_id
                   AND employee.id = employment.employee_id
                 WHERE employment.supplier_id = ?
                   AND employment.relation_type = "dpp"
                   AND employment.is_legacy_projection = 0
                   AND employment.status IN (' . $statuses . ')';
        $params = [$supplierId, ...self::STATUSES];
        if ($employeeIds !== null) {
            $sql .= ' AND employment.employee_id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            array_push($params, ...$employeeIds);
        }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY employment.employee_id, start_date, employment.id');
        $stmt->execute($params);

        return array_map(static fn (array $row): array => [
            'employment_id' => (int) $row['id'],
            'employee_id' => (int) $row['employee_id'],
            'employee_name' => (string) ($row['employee_name'] ?? ''),
            'code' => (string) $row['code'],
            'status' => (string) $row['status'],
            'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
            'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
            'row_version' => (int) $row['row_version'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Otevřené DPP osoby vůči nástupu nové dohody.
     *
     * @return list<array{employment_id:int,employee_id:int,employee_name:string,code:string,status:string,start_date:?string,end_date:?string,row_version:int}>
     */
    public function openFor(int $supplierId, int $employeeId, string $start): array
    {
        return array_values(array_filter(
            $this->candidates($supplierId, [$employeeId]),
            static fn (array $agreement): bool => self::isOpenFor($agreement, $start),
        ));
    }

    /** @param array{end_date:?string} $agreement */
    public static function isOpenFor(array $agreement, string $start): bool
    {
        return $agreement['end_date'] === null || $agreement['end_date'] >= $start;
    }

    /** @param array{start_date:?string,end_date:?string} $agreement */
    public static function reusableFor(array $agreement, string $start): bool
    {
        return self::isOpenFor($agreement, $start)
            && ($agreement['start_date'] === null || $agreement['start_date'] <= $start);
    }

    /**
     * Na kolik se má sjednaný konec otevřené dohody prodloužit, aby pokryla
     * konec nové; `null` = prodlužovat není potřeba.
     *
     * @param array{end_date:?string} $agreement
     */
    public static function extendTo(array $agreement, ?string $end): ?string
    {
        return $end !== null && $agreement['end_date'] !== null && $end > $agreement['end_date'] ? $end : null;
    }

    /**
     * Tvar pro náhled a dialog: dohoda, jestli ji jde použít a do kdy se
     * prodlouží.
     *
     * @param array{employment_id:int,employee_id:int,employee_name:string,code:string,status:string,start_date:?string,end_date:?string,row_version:int} $agreement
     * @return array{employment_id:int,employee_id:int,employee_name:string,code:string,status:string,start_date:?string,end_date:?string,reusable:bool,extend_to:?string}
     */
    public static function describe(array $agreement, string $start, ?string $end): array
    {
        $reusable = self::reusableFor($agreement, $start);

        return [
            'employment_id' => $agreement['employment_id'],
            'employee_id' => $agreement['employee_id'],
            'employee_name' => $agreement['employee_name'],
            'code' => $agreement['code'],
            'status' => $agreement['status'],
            'start_date' => $agreement['start_date'],
            'end_date' => $agreement['end_date'],
            'reusable' => $reusable,
            'extend_to' => $reusable ? self::extendTo($agreement, $end) : null,
        ];
    }

    /**
     * Použije otevřenou dohodu místo založení nové. Ověří, že je to pořád
     * neukončená DPP (případně dané osoby), která nástup nové dohody pokrývá,
     * a je-li sjednaný konec nové dohody pozdější, prodlouží ho.
     *
     * Prodloužení je oprava platné verze podmínek (stejnou cestou jako karta
     * vztahu). Když platná verze zasahuje do zúčtovaného měsíce, vznikne nová
     * verze od nástupu nové dohody.
     *
     * @return array{employment_id:int,employee_id:int,extended_to:?string}
     */
    public function reuse(
        int $supplierId,
        int $employmentId,
        ?int $employeeId,
        string $start,
        ?string $end,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $agreement = null;
        foreach ($this->candidates($supplierId, $employeeId === null ? null : [$employeeId]) as $candidate) {
            if ($candidate['employment_id'] === $employmentId) {
                $agreement = $candidate;
            }
        }
        if ($agreement === null) {
            throw new \InvalidArgumentException(
                'Vybraná dohoda už není otevřená DPP této osoby. Načtěte údaje znovu.',
            );
        }
        if (!self::reusableFor($agreement, $start)) {
            throw new \InvalidArgumentException(sprintf(
                'Dohoda %s nástup %s nepokrývá, místo nové ji použít nejde.',
                $agreement['code'],
                self::czechDate($start),
            ));
        }
        $extend = self::extendTo($agreement, $end);
        if ($extend !== null) {
            $this->atomic(fn () => $this->extend($supplierId, $agreement, $extend, $start, $userId, $ip, $userAgent));
        }

        return [
            'employment_id' => $agreement['employment_id'],
            'employee_id' => $agreement['employee_id'],
            'extended_to' => $extend,
        ];
    }

    /** @param array{employment_id:int,code:string,row_version:int} $agreement */
    private function extend(
        int $supplierId,
        array $agreement,
        string $end,
        string $start,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $employmentId = $agreement['employment_id'];
        $current = $this->employments->currentTerms($supplierId, $employmentId)
            ?? throw new \DomainException('Dohoda nemá verzi sjednaných podmínek, do které by šlo konec zapsat.');
        $body = RegistrationImportWriter::termsBody(
            $current,
            'Prodloužení sjednaného konce dohody místo založení nové DPP.',
        );
        $body['fixed_term_end_on'] = $end;
        $body['effective_from'] = (string) $current['effective_from'];
        $terms = fn (array $body): array => $this->validator->terms(
            $body,
            $this->employments->currentCzIscoCode($supplierId, $employmentId),
            $this->employments->currentOtherWithholdingEligibility($supplierId, $employmentId),
            'dpp',
            $current,
        );
        try {
            $this->employments->correctTerms(
                $supplierId,
                $employmentId,
                $terms($body),
                $agreement['row_version'],
                $userId,
                $ip,
                $userAgent,
            );
        } catch (PayrollTermsSettledException $e) {
            if ($start <= (string) $current['effective_from']) {
                throw $e;
            }
            $body['effective_from'] = $start;
            $this->employments->addTerms(
                $supplierId,
                $employmentId,
                $terms($body),
                $agreement['row_version'],
                $userId,
                $ip,
                $userAgent,
            );
        }
    }

    public static function czechDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('j. n. Y');
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function atomic(callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_open_agreement');
        }
        try {
            $result = $work();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_open_agreement');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e) || !$pdo->inTransaction()) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_open_agreement');
                $pdo->exec('RELEASE SAVEPOINT payroll_open_agreement');
            }
            throw $e;
        }
    }
}
