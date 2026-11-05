<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;

/**
 * Co se od vzniku snímku vstupů změnilo v podkladech, se kterými revize počítá.
 *
 * Porovnává ZMRAZENÝ snímek s tím, co by z databáze načetl nový snímek
 * ({@see PayrollRunSnapshotBuilder::currentSources()} používá tytéž dotazy
 * i normalizaci jako `build()`). Nic nepočítá ani neukládá.
 *
 * Záměrně jen podklady, které se po zámku mění běžnou prací účetní: mzdové
 * vstupy, nepřítomnosti, množina vztahů v období, hodinové podmínky a zákonná evidence osob.
 * Obnova podkladů sama bere VŠECHNO — tohle je jen detektor, který má
 * účetní upozornit a schválení zastavit.
 */
final readonly class PayrollRunSourceDrift
{
    public function __construct(
        public int $inputsAdded = 0,
        public int $inputsChanged = 0,
        public int $inputsRemoved = 0,
        public int $absencesAdded = 0,
        public int $absencesChanged = 0,
        public int $absencesRemoved = 0,
        public int $employmentsAdded = 0,
        public int $employmentsRemoved = 0,
        public int $statutoryEvidenceChanged = 0,
        public int $remunerationChanged = 0,
    ) {}

    /**
     * @param array<string,mixed> $frozenSnapshot vstupní snímek revize
     * @param array{
     *   eligible_employment_ids:list<int>,
     *   inputs:array<int,list<array<string,mixed>>>,
     *   absences:array<int,list<array<string,mixed>>>,
     *   hourly_remuneration_terms?:array<int,list<array<string,mixed>>>,
     *   statutory_evidence:array<int,mixed>|null
     * } $current
     */
    public static function between(array $frozenSnapshot, array $current): self
    {
        $frozenInputs = [];
        $frozenAbsences = [];
        $frozenEmployments = [];
        $frozenEvidence = [];
        $frozenHourlyTerms = [];
        $people = is_array($frozenSnapshot['people'] ?? null) ? $frozenSnapshot['people'] : [];
        foreach ($people as $person) {
            if (!is_array($person) || !is_array($person['employee'] ?? null)) {
                continue;
            }
            $frozenEvidence[(int) ($person['employee']['id'] ?? 0)] =
                $person['statutory_evidence'] ?? null;
            $employments = is_array($person['employments'] ?? null) ? $person['employments'] : [];
            foreach ($employments as $employment) {
                if (!is_array($employment) || !is_array($employment['employment'] ?? null)) {
                    continue;
                }
                $frozenEmployments[(int) ($employment['employment']['id'] ?? 0)] = true;
                $frozenHourlyTerms[(int) ($employment['employment']['id'] ?? 0)] =
                    $employment['hourly_remuneration_terms'] ?? [];
                foreach (self::rows($employment['inputs'] ?? null) as $row) {
                    $frozenInputs[] = $row;
                }
                foreach (self::rows($employment['absences'] ?? null) as $row) {
                    $frozenAbsences[] = $row;
                }
            }
        }

        $currentInputs = [];
        foreach ($current['inputs'] as $rows) {
            foreach ($rows as $row) {
                $currentInputs[] = $row;
            }
        }
        $currentAbsences = [];
        foreach ($current['absences'] as $rows) {
            foreach ($rows as $row) {
                $currentAbsences[] = $row;
            }
        }
        [$inputsAdded, $inputsChanged, $inputsRemoved] = self::compare(
            $frozenInputs,
            $currentInputs,
        );
        [$absencesAdded, $absencesChanged, $absencesRemoved] = self::compare(
            $frozenAbsences,
            $currentAbsences,
        );

        $eligible = array_fill_keys($current['eligible_employment_ids'], true);
        $statutoryChanged = 0;
        if ($current['statutory_evidence'] !== null) {
            foreach ($frozenEvidence as $employeeId => $evidence) {
                if (self::fingerprint($evidence)
                    !== self::fingerprint($current['statutory_evidence'][$employeeId] ?? null)
                ) {
                    $statutoryChanged++;
                }
            }
        }

        $remunerationChanged = 0;
        if (isset($current['hourly_remuneration_terms'])) {
            foreach ($frozenHourlyTerms as $employmentId => $terms) {
                if (self::fingerprint($terms) !== self::fingerprint(
                    $current['hourly_remuneration_terms'][$employmentId] ?? [],
                )) {
                    ++$remunerationChanged;
                }
            }
        }

        return new self(
            inputsAdded: $inputsAdded,
            inputsChanged: $inputsChanged,
            inputsRemoved: $inputsRemoved,
            absencesAdded: $absencesAdded,
            absencesChanged: $absencesChanged,
            absencesRemoved: $absencesRemoved,
            employmentsAdded: count(array_diff_key($eligible, $frozenEmployments)),
            employmentsRemoved: count(array_diff_key($frozenEmployments, $eligible)),
            statutoryEvidenceChanged: $statutoryChanged,
            remunerationChanged: $remunerationChanged,
        );
    }

    public function total(): int
    {
        return $this->inputsAdded
            + $this->inputsChanged
            + $this->inputsRemoved
            + $this->absencesAdded
            + $this->absencesChanged
            + $this->absencesRemoved
            + $this->employmentsAdded
            + $this->employmentsRemoved
            + $this->statutoryEvidenceChanged
            + $this->remunerationChanged;
    }

    public function hasChanges(): bool
    {
        return $this->total() > 0;
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'inputs_added' => $this->inputsAdded,
            'inputs_changed' => $this->inputsChanged,
            'inputs_removed' => $this->inputsRemoved,
            'absences_added' => $this->absencesAdded,
            'absences_changed' => $this->absencesChanged,
            'absences_removed' => $this->absencesRemoved,
            'employments_added' => $this->employmentsAdded,
            'employments_removed' => $this->employmentsRemoved,
            'statutory_evidence_changed' => $this->statutoryEvidenceChanged,
            'remuneration_changed' => $this->remunerationChanged,
        ];
    }

    /**
     * Řádky podle `id`: přibyl, změnil se, zmizel.
     *
     * @param list<array<string,mixed>> $before
     * @param list<array<string,mixed>> $after
     * @return array{int,int,int}
     */
    private static function compare(array $before, array $after): array
    {
        $beforeById = self::byId($before);
        $afterById = self::byId($after);
        $changed = 0;
        foreach (array_intersect_key($afterById, $beforeById) as $id => $fingerprint) {
            if ($beforeById[$id] !== $fingerprint) {
                $changed++;
            }
        }

        return [
            count(array_diff_key($afterById, $beforeById)),
            $changed,
            count(array_diff_key($beforeById, $afterById)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,string>
     */
    private static function byId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) ($row['id'] ?? 0)] = self::fingerprint($row);
        }

        return $indexed;
    }

    private static function fingerprint(mixed $value): string
    {
        return CanonicalJson::encode(['value' => $value]);
    }

    /** @return list<array<string,mixed>> */
    private static function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
