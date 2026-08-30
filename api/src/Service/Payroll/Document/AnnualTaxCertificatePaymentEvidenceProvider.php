<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Document;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class AnnualTaxCertificatePaymentEvidenceProvider
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   schema_version:string,
     *   run_id:int,
     *   revision_id:int,
     *   expected_net_minor_units:int,
     *   cutoff:string,
     *   last_payment_date:string,
     *   liabilities:list<array{
     *     liability_id:int,
     *     revision_id:int,
     *     revision_no:int,
     *     direction:string,
     *     amount_minor_units:int,
     *     settled_minor_units:int,
     *     events:list<array{
     *       match_id:int,
     *       event_kind:string,
     *       amount_minor_units:int,
     *       actual_payment_date:string,
     *       evidence_fact_hash:string
     *     }>
     *   }>
     * }
     */
    public function prove(
        int $supplierId,
        int $employeeId,
        int $runId,
        int $revisionId,
        int $expectedNetMinorUnits,
        string $cutoff,
    ): array {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException(
                'Důkaz skutečné výplaty vyžaduje aktivní transakci.',
            );
        }
        if ($supplierId <= 0
            || $employeeId <= 0
            || $runId <= 0
            || $revisionId <= 0
            || $expectedNetMinorUnits <= 0
        ) {
            throw new \InvalidArgumentException(
                'Identita platebního důkazu daňového potvrzení není platná.',
            );
        }
        $cutoffDate = self::date($cutoff, 'mezní datum skutečné výplaty');
        $statement = $pdo->prepare(
            'SELECT liability.id AS liability_id,
                    liability.revision_id,
                    revision.revision_no,
                    liability.direction,
                    liability.currency_code,
                    liability.amount_minor AS liability_amount_minor,
                    allocation.id AS allocation_id,
                    payment_match.id AS match_id,
                    payment_match.event_kind,
                    payment_match.amount_minor AS match_amount_minor,
                    payment_match.actual_payment_date,
                    payment_match.evidence_fact_hash
               FROM payroll_run_revisions selected_revision
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = selected_revision.supplier_id
                AND revision.run_id = selected_revision.run_id
                AND revision.revision_no <= selected_revision.revision_no
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = revision.supplier_id
                AND liability.revision_id = revision.id
                AND liability.employee_id = ?
                AND liability.liability_kind = "net_wage"
          LEFT JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = liability.supplier_id
                AND allocation.liability_id = liability.id
          LEFT JOIN payroll_payment_matches payment_match
                 ON payment_match.supplier_id = allocation.supplier_id
                AND payment_match.allocation_id = allocation.id
              WHERE selected_revision.supplier_id = ?
                AND selected_revision.id = ?
                AND selected_revision.run_id = ?
                AND selected_revision.status IN ("approved", "superseded")
              ORDER BY liability.id, allocation.id, payment_match.id
              FOR UPDATE',
        );
        $statement->execute([
            $employeeId,
            $supplierId,
            $revisionId,
            $runId,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new \DomainException(
                'Skutečnou výplatu nelze doložit: pro schválenou revizi '
                . 'neexistuje neměnný závazek čisté mzdy.',
            );
        }

        $liabilities = [];
        foreach ($rows as $fetched) {
            $row = self::associativeRow($fetched);
            $liabilityId = self::positiveInt($row, 'liability_id');
            if (!isset($liabilities[$liabilityId])) {
                $direction = self::text($row, 'direction');
                if (!in_array($direction, ['outgoing', 'incoming'], true)) {
                    throw new \DomainException(
                        'Závazek čisté mzdy má neplatný směr.',
                    );
                }
                if (self::text($row, 'currency_code') !== 'CZK') {
                    throw new \DomainException(
                        'Daňové potvrzení podporuje pouze doložené výplaty v CZK.',
                    );
                }
                $liabilities[$liabilityId] = [
                    'liability_id' => $liabilityId,
                    'revision_id' => self::positiveInt($row, 'revision_id'),
                    'revision_no' => self::positiveInt($row, 'revision_no'),
                    'direction' => $direction,
                    'amount_minor_units' =>
                        self::positiveInt($row, 'liability_amount_minor'),
                    'settled_minor_units' => 0,
                    'events' => [],
                ];
            }
            $matchId = self::nullablePositiveInt($row, 'match_id');
            if ($matchId === null) {
                continue;
            }
            $actualPaymentDate = self::nullableText(
                $row,
                'actual_payment_date',
            );
            if ($actualPaymentDate === null) {
                throw new \DomainException(
                    'Skutečnou výplatu nelze doložit bez data účetního důkazu.',
                );
            }
            $paymentDate = self::date(
                $actualPaymentDate,
                'datum účetního důkazu',
            );
            $evidenceHash = self::nullableText(
                $row,
                'evidence_fact_hash',
            );
            if ($evidenceHash === null
                || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1
            ) {
                throw new \DomainException(
                    'Skutečnou výplatu nelze doložit bez otisku účetního důkazu.',
                );
            }
            $eventKind = self::text($row, 'event_kind');
            $eventAmount = self::int($row, 'match_amount_minor');
            if (($eventKind === 'matched' && $eventAmount <= 0)
                || ($eventKind === 'reversed' && $eventAmount >= 0)
                || !in_array($eventKind, ['matched', 'reversed'], true)
            ) {
                throw new \DomainException(
                    'Událost platebního důkazu má neplatný směr.',
                );
            }
            // Mezní datum se uplatňuje POUZE na příchozí úhradu. Příjem vyplacený
            // až po 31. lednu do zdaňovacího období nepatří (§ 5 odst. 4 zákona),
            // takže se `matched` událost za hranicí nezapočítá a závazek zůstane
            // nedoložený — potvrzení se nevydá.
            //
            // Reverzace je ale důkaz OPAČNÉHO směru: banka peníze vrátila, takže
            // ta dřívější `matched` událost mzdu nevyrovnala. Kdyby se ignorovala
            // stejným pravidlem, stačilo by, aby se vrácení platby zaúčtovalo
            // 1. února, a nevyplacená mzda by se na potvrzení objevila jako řádně
            // vyplacená. Ověřeno simulací: úhrada 15. 2. 2026 vrácená 5. 2. 2027
            // vydala potvrzení na 40 000 Kč, přestože zaměstnanec nedostal nic.
            // Reverzace se proto započítává vždy, bez ohledu na datum; směrem ven
            // je to fail-closed (uzavře cestu, neotevírá ji).
            if ($eventKind === 'matched' && $paymentDate > $cutoffDate) {
                continue;
            }
            if (isset($liabilities[$liabilityId]['events'][$matchId])) {
                continue;
            }
            $liabilities[$liabilityId]['settled_minor_units'] = self::add(
                $liabilities[$liabilityId]['settled_minor_units'],
                $eventAmount,
            );
            $liabilities[$liabilityId]['events'][$matchId] = [
                'match_id' => $matchId,
                'event_kind' => $eventKind,
                'amount_minor_units' => $eventAmount,
                'actual_payment_date' => $actualPaymentDate,
                'evidence_fact_hash' => $evidenceHash,
            ];
        }

        $netLiability = 0;
        $lastPaymentDate = null;
        $proof = [];
        foreach ($liabilities as $liability) {
            if ($liability['settled_minor_units']
                !== $liability['amount_minor_units']
            ) {
                throw new \DomainException(sprintf(
                    'Čistá mzda nebyla podle neměnné evidence plně vyplacena '
                    . 'do %s.',
                    self::displayDate($cutoff),
                ));
            }
            $netLiability = self::add(
                $netLiability,
                $liability['direction'] === 'outgoing'
                    ? $liability['amount_minor_units']
                    : -$liability['amount_minor_units'],
            );
            $events = array_values($liability['events']);
            foreach ($events as $event) {
                if ($event['event_kind'] === 'matched'
                    && ($lastPaymentDate === null
                        || $event['actual_payment_date'] > $lastPaymentDate)
                ) {
                    $lastPaymentDate = $event['actual_payment_date'];
                }
            }
            $liability['events'] = $events;
            $proof[] = $liability;
        }
        if ($netLiability !== $expectedNetMinorUnits) {
            throw new \DomainException(
                'Vektor platebních závazků neodpovídá schválené čisté mzdě.',
            );
        }
        if ($lastPaymentDate === null) {
            throw new \DomainException(
                'Skutečnou výplatu nelze doložit bez kladné platební události.',
            );
        }

        return [
            'schema_version' =>
                'annual-tax-certificate-payment-evidence.v1',
            'run_id' => $runId,
            'revision_id' => $revisionId,
            'expected_net_minor_units' => $expectedNetMinorUnits,
            'cutoff' => $cutoff,
            'last_payment_date' => $lastPaymentDate,
            'liabilities' => $proof,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function positiveInt(array $row, string $field): int
    {
        $value = self::int($row, $field);
        if ($value <= 0) {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není kladné celé číslo.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullablePositiveInt(
        array $row,
        string $field,
    ): ?int {
        return ($row[$field] ?? null) === null
            ? null
            : self::positiveInt($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function int(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není celé číslo.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private static function text(array $row, string $field): string
    {
        return self::nullableText($row, $field)
            ?? throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není text.",
            );
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(
                "Pole {$field} platebního důkazu není text.",
            );
        }

        return $value;
    }

    private static function date(
        string $value,
        string $label,
    ): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false
                && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new \InvalidArgumentException(
                "Pole {$label} platebního důkazu není platné datum.",
            );
        }

        return $date;
    }

    private static function displayDate(string $value): string
    {
        $date = self::date($value, 'datum');

        return implode('. ', [
            (string) (int) $date->format('d'),
            (string) (int) $date->format('m'),
            $date->format('Y'),
        ]);
    }

    private static function add(int $left, int $right): int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)
        ) {
            throw new \OverflowException(
                'Součet platebního důkazu přetekl.',
            );
        }

        return $left + $right;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(
                'Platební evidence vrátila neplatný řádek.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Platební evidence vrátila neplatný název sloupce.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }
}
