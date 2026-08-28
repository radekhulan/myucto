<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

final class PayrollStatutoryAccumulatorRepository
{
    private const SAVEPOINT = 'payroll_statutory_accumulators';

    /** Velikost dávky pro množinové načtení nad zmrazenou sadou osob. */
    private const CHUNK_SIZE = 500;

    /** @var array<string,list<string>> */
    private const VALUE_FIELDS = [
        'social_insurance' => [
            'assessment_base_minor_units',
        ],
        'income_tax' => [
            'completed_months',
            'advance_base_minor_units',
            'withholding_base_minor_units',
            'advance_tax_minor_units',
            'withholding_tax_minor_units',
            'applied_non_refundable_credits_minor_units',
            'applied_child_credit_minor_units',
            'tax_bonus_minor_units',
            'bonus_qualifying_income_minor_units',
        ],
    ];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $values
     * @param array<mixed> $evidence
     */
    public function appendOpeningBalance(
        int $supplierId,
        int $employeeId,
        int $year,
        string $calculationKind,
        array $values,
        string $sourceReference,
        array $evidence,
        string $idempotencyKey,
        ?int $replacesOpeningId = null,
        ?int $actorUserId = null,
    ): int {
        $this->validateIdentity($supplierId, $employeeId, $year);
        $normalizedValues = $this->normalizeValues(
            $calculationKind,
            $values,
            true,
        );
        $sourceReference = trim($sourceReference);
        if (mb_strlen($sourceReference) > 190) {
            throw new \InvalidArgumentException(
                'Volitelná reference počátečního stavu smí mít nejvýše 190 znaků.',
            );
        }
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException(
                'Opening balance vyžaduje idempotency klíč.',
            );
        }
        if ($replacesOpeningId !== null && $replacesOpeningId <= 0) {
            throw new \InvalidArgumentException(
                'Nahrazovaný opening balance musí mít platné ID.',
            );
        }

        $valuesJson = CanonicalJson::encode($normalizedValues);
        $evidenceJson = CanonicalJson::encode($evidence);
        $recordHash = hash('sha256', CanonicalJson::encode([
            'schema_version' => 'payroll-statutory-opening.v1',
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'year' => $year,
            'calculation_kind' => $calculationKind,
            'values' => $normalizedValues,
            'source_reference' => $sourceReference,
            'evidence' => $evidence,
            'replaces_opening_id' => $replacesOpeningId,
        ]));
        $keyHash = hash('sha256', $idempotencyKey, true);

        return $this->transactional(function () use (
            $supplierId,
            $employeeId,
            $year,
            $calculationKind,
            $valuesJson,
            $sourceReference,
            $evidenceJson,
            $replacesOpeningId,
            $keyHash,
            $recordHash,
            $actorUserId,
        ): int {
            $existing = $this->openingByIdempotencyKey($supplierId, $keyHash);
            if ($existing !== null) {
                return $this->replayedId(
                    $existing,
                    $recordHash,
                    'Idempotency klíč už používá jiný opening balance.',
                );
            }

            $current = $this->currentOpening(
                $supplierId,
                $employeeId,
                $year,
                $calculationKind,
                true,
            );
            if ($replacesOpeningId === null && $current !== null) {
                throw new \DomainException(
                    'Opening balance už existuje; oprava musí explicitně navázat na aktuální verzi.',
                );
            }
            if ($replacesOpeningId !== null
                && ($current === null || $current['id'] !== $replacesOpeningId)
            ) {
                throw new \DomainException(
                    'Oprava opening balance nenavazuje na aktuální verzi.',
                );
            }

            try {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_statutory_accumulator_openings
                        (supplier_id, employee_id, tax_year, calculation_kind,
                         values_json, source_reference, evidence_json,
                         replaces_opening_id, idempotency_key_hash, record_hash,
                         created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $employeeId,
                    $year,
                    $calculationKind,
                    $valuesJson,
                    $sourceReference,
                    $evidenceJson,
                    $replacesOpeningId,
                    $keyHash,
                    $recordHash,
                    $actorUserId,
                ]);
                return (int) $this->db->pdo()->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                $replayed = $this->openingByIdempotencyKey($supplierId, $keyHash);
                if ($replayed !== null) {
                    return $this->replayedId(
                        $replayed,
                        $recordHash,
                        'Idempotency klíč už používá jiný opening balance.',
                        $e,
                    );
                }
                throw new \DomainException(
                    'Opening balance mezitím změnila jiná operace.',
                    previous: $e,
                );
            }
        });
    }

    /**
     * @param array<string,mixed> $values
     */
    public function appendApprovedResult(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        string $calculationKind,
        array $values,
        string $sourceResultHash,
        ?int $actorUserId = null,
    ): int {
        if ($supplierId <= 0 || $revisionId <= 0 || $employeeId <= 0) {
            throw new \InvalidArgumentException(
                'Firma, revize a zaměstnanec musí mít platná ID.',
            );
        }
        $normalizedValues = $this->normalizeValues(
            $calculationKind,
            $values,
            false,
        );
        if (preg_match('/^[0-9a-f]{64}$/D', $sourceResultHash) !== 1) {
            throw new \InvalidArgumentException(
                'Hash zákonného výsledku musí být SHA-256 v hexadecimálním tvaru.',
            );
        }

        return $this->transactional(function () use (
            $supplierId,
            $revisionId,
            $employeeId,
            $calculationKind,
            $normalizedValues,
            $sourceResultHash,
            $actorUserId,
        ): int {
            $revision = $this->approvedPersonRevision(
                $supplierId,
                $revisionId,
                $employeeId,
            );
            $personResult = $this->authoritativePersonResult(
                $supplierId,
                $revisionId,
                $employeeId,
                $calculationKind,
            );
            if ($personResult['result_status'] !== 'calculated') {
                throw new PayrollStatutoryAccumulatorUnavailableException(
                    'Do roční kumulace lze převzít jen vypočtený zákonný výsledek osoby.',
                );
            }
            if ($personResult['result_snapshot_hash'] !== $sourceResultHash) {
                throw new \DomainException(
                    'Hash příspěvku neodpovídá neměnnému zákonnému výsledku osoby.',
                );
            }
            $periodStart = (string) $revision['period_start'];
            $year = (int) substr($periodStart, 0, 4);
            $replacesEntryId = null;
            if ($revision['revision_kind'] === 'correction') {
                $previousRevisionId = $revision['previous_revision_id'];
                if (!is_int($previousRevisionId)) {
                    throw new \DomainException(
                        'Opravná revize nemá explicitní předchozí revizi.',
                    );
                }
                $previous = $this->entryByRevision(
                    $supplierId,
                    $previousRevisionId,
                    $employeeId,
                    $calculationKind,
                    true,
                );
                if ($previous === null
                    || $previous['period_start'] !== $periodStart
                    || $this->hasEntrySuccessor($supplierId, $previous['id'])
                ) {
                    throw new \DomainException(
                        'Opravná revize nenavazuje na aktuální zákonný výsledek období.',
                    );
                }
                $replacesEntryId = $previous['id'];
            }

            $recordHash = hash('sha256', CanonicalJson::encode([
                'schema_version' => 'payroll-statutory-accumulator-entry.v1',
                'supplier_id' => $supplierId,
                'employee_id' => $employeeId,
                'year' => $year,
                'period_start' => $periodStart,
                'revision_id' => $revisionId,
                'calculation_kind' => $calculationKind,
                'values' => $normalizedValues,
                'source_result_hash' => $sourceResultHash,
                'replaces_entry_id' => $replacesEntryId,
            ]));
            $existing = $this->entryByRevision(
                $supplierId,
                $revisionId,
                $employeeId,
                $calculationKind,
                true,
            );
            if ($existing !== null) {
                return $this->replayedId(
                    $existing,
                    $recordHash,
                    'Revize už obsahuje jiný příspěvek do zákonné kumulace.',
                );
            }

            try {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_statutory_accumulator_entries
                        (supplier_id, employee_id, tax_year, period_start,
                         revision_id, calculation_kind, values_json,
                         source_result_hash, replaces_entry_id, record_hash,
                         created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $supplierId,
                    $employeeId,
                    $year,
                    $periodStart,
                    $revisionId,
                    $calculationKind,
                    CanonicalJson::encode($normalizedValues),
                    $sourceResultHash,
                    $replacesEntryId,
                    $recordHash,
                    $actorUserId,
                ]);
                return (int) $this->db->pdo()->lastInsertId();
            } catch (PDOException $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                $replayed = $this->entryByRevision(
                    $supplierId,
                    $revisionId,
                    $employeeId,
                    $calculationKind,
                    false,
                );
                if ($replayed !== null) {
                    return $this->replayedId(
                        $replayed,
                        $recordHash,
                        'Revize už obsahuje jiný příspěvek do zákonné kumulace.',
                        $e,
                    );
                }
                throw new \DomainException(
                    'Zákonná kumulace období mezitím změnila jiná operace.',
                    previous: $e,
                );
            }
        });
    }

    /** @return array<string,mixed> */
    public function stateBeforePeriod(
        int $supplierId,
        int $employeeId,
        int $year,
        string $periodStart,
        string $calculationKind,
    ): array {
        return $this->buildState(
            $supplierId,
            $employeeId,
            $year,
            $periodStart,
            $calculationKind,
            null,
        );
    }

    /**
     * Kumulace za CELÝ rok — podklad ročního zúčtování (§ 38ch ZDP).
     *
     * Liší se od stateBeforePeriod() jen dvěma věcmi, a obě jsou nutné:
     *   - bere i prosincový přírůstek, ne jen měsíce PŘED zadaným obdobím,
     *   - připouští dvanáct uzavřených měsíců, kdežto vstup do dalšího běhu
     *     jich smí mít nejvýš jedenáct (dvanáctý je právě ten počítaný).
     *
     * Sečtení a zapečetění dělá totéž assembleState() jako obě ostatní cesty —
     * kdyby to bylo vedle, mohly by se otisky rozejít a přehrání běhu by
     * neselhalo, jen by tiše porovnávalo něco jiného.
     *
     * @return array<string,mixed>
     */
    public function stateForYear(
        int $supplierId,
        int $employeeId,
        int $year,
        string $calculationKind,
    ): array {
        return $this->buildState(
            $supplierId,
            $employeeId,
            $year,
            sprintf('%04d-01-01', $year),
            $calculationKind,
            null,
            true,
        );
    }

    /**
     * Dávkový protějšek stateBeforePeriod(): pět dotazů na CELOU množinu osob
     * místo tří na každou z nich.
     *
     * Osoba, které chybí doložený opening balance (nebo jejíž daňová kumulace
     * přeteče jedenáct uzavřených měsíců), ve výsledku CHYBÍ — je to táž informace,
     * jakou stateBeforePeriod() sděluje výjimkou
     * PayrollStatutoryAccumulatorUnavailableException. Osoba, která firmě nepatří,
     * naopak shodí celé volání DomainException, stejně jako u jedné osoby.
     *
     * @param list<int> $employeeIds
     * @return array<int,array<string,mixed>>
     */
    public function statesBeforePeriod(
        int $supplierId,
        array $employeeIds,
        int $year,
        string $periodStart,
        string $calculationKind,
    ): array {
        return $this->statesBeforePeriodByKind(
            $supplierId,
            $employeeIds,
            $year,
            $periodStart,
            [$calculationKind],
        )[$calculationKind];
    }

    /**
     * Táž dávka jako statesBeforePeriod(), ale pro VÍC druhů kumulace najednou.
     *
     * Snapshot běhu potřebuje `social_insurance` i `income_tax`; volané po jednom
     * to stálo dvakrát tři dotazy (kontrola příslušnosti osob k firmě, opening
     * balance, záznamy před obdobím), přestože se druh kumulace liší jen jednou
     * hodnotou ve WHERE. Druhy se proto berou jedním `IN (...)` a výsledek se
     * rozděluje v paměti — dotazů zůstává tři bez ohledu na počet druhů.
     *
     * Sestavení stavu i jeho otisk se nemění: stav se skládá pro tutéž dvojici
     * (osoba, druh) a ze stejných řádků jako u volání po jednom druhu.
     *
     * @param list<int> $employeeIds
     * @param list<string> $calculationKinds
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function statesBeforePeriodByKind(
        int $supplierId,
        array $employeeIds,
        int $year,
        string $periodStart,
        array $calculationKinds,
    ): array {
        $kinds = array_values(array_unique($calculationKinds));
        if ($kinds === []) {
            throw new \InvalidArgumentException(
                'Nepodporovaný druh zákonné kumulace.',
            );
        }
        $unique = array_values(array_unique($employeeIds));
        if ($unique === []) {
            return array_fill_keys($kinds, []);
        }
        foreach ($unique as $employeeId) {
            $this->assertIdentityArguments($supplierId, $employeeId, $year);
        }
        foreach ($kinds as $calculationKind) {
            $this->normalizeValues(
                $calculationKind,
                array_fill_keys(self::VALUE_FIELDS[$calculationKind] ?? [], 0),
                true,
            );
        }
        $this->assertPeriodStart($periodStart, $year);
        $this->assertEmployeesBelongToSupplier($supplierId, $unique);

        $openings = $this->currentOpenings($supplierId, $unique, $year, $kinds);
        $withOpening = [];
        foreach ($openings as $byEmployee) {
            foreach (array_keys($byEmployee) as $employeeId) {
                $withOpening[$employeeId] = true;
            }
        }
        $entries = $this->entriesBeforePeriod(
            $supplierId,
            array_keys($withOpening),
            $year,
            $kinds,
            $periodStart,
        );

        $result = [];
        foreach ($kinds as $calculationKind) {
            $states = [];
            foreach ($unique as $employeeId) {
                $opening = $openings[$calculationKind][$employeeId] ?? null;
                if ($opening === null) {
                    continue;
                }
                try {
                    $states[$employeeId] = $this->assembleState(
                        $supplierId,
                        $employeeId,
                        $year,
                        $periodStart,
                        $calculationKind,
                        null,
                        $opening,
                        $entries[$calculationKind][$employeeId] ?? [],
                    );
                } catch (PayrollStatutoryAccumulatorUnavailableException) {
                    continue;
                }
            }
            $result[$calculationKind] = $states;
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function stateBeforeRevision(
        int $supplierId,
        int $employeeId,
        int $revisionId,
        string $calculationKind,
    ): array {
        if ($revisionId <= 0) {
            throw new \InvalidArgumentException('Revize musí mít platné ID.');
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               JOIN payroll_run_persons person
                 ON person.supplier_id = revision.supplier_id
                AND person.revision_id = revision.id
                AND person.employee_id = ?
              WHERE revision.supplier_id = ? AND revision.id = ?'
        );
        $stmt->execute([$employeeId, $supplierId, $revisionId]);
        $periodStart = $stmt->fetchColumn();
        if (!is_string($periodStart)) {
            throw new \DomainException(
                'Revize nebo její zaměstnanec nepatří zadané firmě.',
            );
        }

        return $this->buildState(
            $supplierId,
            $employeeId,
            (int) substr($periodStart, 0, 4),
            $periodStart,
            $calculationKind,
            $revisionId,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildState(
        int $supplierId,
        int $employeeId,
        int $year,
        string $periodStart,
        string $calculationKind,
        ?int $beforeRevisionId,
        bool $wholeYear = false,
    ): array {
        $this->validateIdentity($supplierId, $employeeId, $year);
        $this->normalizeValues(
            $calculationKind,
            array_fill_keys(self::VALUE_FIELDS[$calculationKind] ?? [], 0),
            true,
        );
        $this->assertPeriodStart($periodStart, $year);

        $opening = $this->currentOpening(
            $supplierId,
            $employeeId,
            $year,
            $calculationKind,
            false,
        );
        if ($opening === null) {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                'Chybí explicitně doložený opening balance zákonné kumulace.',
            );
        }

        // Roční čtení bere celý rok včetně prosince, běhové čtení jen měsíce
        // před zadaným obdobím. Je to jediný rozdíl v dotazu; zbytek cesty
        // sdílejí, aby se otisky nemohly rozejít.
        $stmt = $this->db->pdo()->prepare(sprintf(
            'SELECT entry.*
               FROM payroll_statutory_accumulator_entries entry
              WHERE entry.supplier_id = ?
                AND entry.employee_id = ?
                AND entry.tax_year = ?
                AND entry.calculation_kind = ?
                %s
                AND NOT EXISTS (
                  SELECT 1
                    FROM payroll_statutory_accumulator_entries successor
                   WHERE successor.supplier_id = entry.supplier_id
                     AND successor.replaces_entry_id = entry.id
                )
              ORDER BY entry.period_start, entry.id',
            $wholeYear ? '' : 'AND entry.period_start < ?',
        ));
        $stmt->execute($wholeYear
            ? [$supplierId, $employeeId, $year, $calculationKind]
            : [$supplierId, $employeeId, $year, $calculationKind, $periodStart]);
        $entries = array_values(array_map(
            fn (array $row): array => $this->castEntry($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));

        return $this->assembleState(
            $supplierId,
            $employeeId,
            $year,
            $periodStart,
            $calculationKind,
            $beforeRevisionId,
            $opening,
            $entries,
            $wholeYear,
        );
    }

    /**
     * Sečte kumulaci a zapečetí ji otiskem.
     *
     * Sdílené jádro dotazované i dávkové cesty — kdyby existovalo dvakrát, mohly
     * by se rozejít otisky, které se porovnávají při přehrání běhu.
     *
     * @param array<string,mixed> $opening
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function assembleState(
        int $supplierId,
        int $employeeId,
        int $year,
        string $periodStart,
        string $calculationKind,
        ?int $beforeRevisionId,
        array $opening,
        array $entries,
        bool $wholeYear = false,
    ): array {
        $totals = $opening['values'];
        foreach ($entries as $entry) {
            foreach ($entry['values'] as $field => $value) {
                $totals[$field] = $this->add(
                    $totals[$field],
                    $value,
                    $field,
                );
            }
        }
        // Vstup do dalšího běhu smí mít nejvýš jedenáct uzavřených měsíců —
        // dvanáctý je právě ten počítaný. Roční čtení naopak dvanáct mít MÁ,
        // jinak by v prosinci nešlo roční zúčtování vůbec sestavit.
        $monthLimit = $wholeYear ? 12 : 11;
        if ($calculationKind === 'income_tax'
            && $totals['completed_months'] > $monthLimit
        ) {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                $wholeYear
                    ? 'Daňová kumulace roku obsahuje více než dvanáct uzavřených měsíců.'
                    : 'Daňová kumulace před obdobím obsahuje více než jedenáct uzavřených měsíců.',
            );
        }

        $state = [
            'schema_version' => 'payroll-statutory-accumulator-state.v1',
            'supplier_id' => $supplierId,
            'employee_id' => $employeeId,
            'year' => $year,
            'before_period_start' => $periodStart,
            'calculation_kind' => $calculationKind,
            'opening_balance' => $opening,
            'approved_results' => $entries,
            'totals' => $totals,
        ];
        if ($wholeYear) {
            // Roční stav není „stav před obdobím". Kdyby nesl jen
            // `before_period_start`, byl by k nerozeznání od lednového čtení
            // a jeho otisk by se s ním dokonce shodoval.
            $state['scope'] = 'whole_year';
        }
        if ($beforeRevisionId !== null) {
            $state['before_revision_id'] = $beforeRevisionId;
        }
        $state['snapshot_hash'] = hash('sha256', CanonicalJson::encode($state));

        return $state;
    }

    private function validateIdentity(int $supplierId, int $employeeId, int $year): void
    {
        $this->assertIdentityArguments($supplierId, $employeeId, $year);
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \DomainException(
                'Zaměstnanec nepatří zadané firmě.',
            );
        }
    }

    private function assertIdentityArguments(
        int $supplierId,
        int $employeeId,
        int $year,
    ): void {
        if ($supplierId <= 0 || $employeeId <= 0 || $year < 2000 || $year > 2200) {
            throw new \InvalidArgumentException(
                'Firma, zaměstnanec nebo rok zákonné kumulace nejsou platné.',
            );
        }
    }

    private function assertPeriodStart(string $periodStart, int $year): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);
        if ($date === false
            || $date->format('Y-m-d') !== $periodStart
            || $date->format('d') !== '01'
            || (int) $date->format('Y') !== $year
        ) {
            throw new \InvalidArgumentException(
                'Období kumulace musí být první den zadaného roku.',
            );
        }
    }

    /** @param list<int> $employeeIds */
    private function assertEmployeesBelongToSupplier(
        int $supplierId,
        array $employeeIds,
    ): void {
        $found = [];
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT id FROM payroll_employees
                  WHERE supplier_id = ? AND id IN (%s)',
                implode(', ', array_fill(0, count($chunk), '?')),
            ));
            $stmt->execute([$supplierId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(int) $row['id']] = true;
            }
        }
        foreach ($employeeIds as $employeeId) {
            if (!isset($found[$employeeId])) {
                throw new \DomainException(
                    'Zaměstnanec nepatří zadané firmě.',
                );
            }
        }
    }

    /**
     * Aktuální (nenahrazené) opening balance pro celou množinu osob.
     *
     * Řetěz oprav je díky UNIQUE (supplier_id, employee_id, tax_year,
     * calculation_kind, predecessor_scope_id) lineární, takže na scope připadá
     * právě jeden nenahrazený řádek — dávkový dotaz tedy nemůže vybrat jiný
     * řádek než dotaz za jednu osobu.
     *
     * @param list<int> $employeeIds
     * @param list<string> $calculationKinds
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function currentOpenings(
        int $supplierId,
        array $employeeIds,
        int $year,
        array $calculationKinds,
    ): array {
        $openings = array_fill_keys($calculationKinds, []);
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT opening.*
                   FROM payroll_statutory_accumulator_openings opening
                  WHERE opening.supplier_id = ?
                    AND opening.employee_id IN (%s)
                    AND opening.tax_year = ?
                    AND opening.calculation_kind IN (%s)
                    AND NOT EXISTS (
                      SELECT 1
                        FROM payroll_statutory_accumulator_openings successor
                       WHERE successor.supplier_id = opening.supplier_id
                         AND successor.replaces_opening_id = opening.id
                    )',
                implode(', ', array_fill(0, count($chunk), '?')),
                implode(', ', array_fill(0, count($calculationKinds), '?')),
            ));
            $stmt->execute([$supplierId, ...$chunk, $year, ...$calculationKinds]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $kind = (string) $row['calculation_kind'];
                $openings[$kind][(int) $row['employee_id']] ??= $this->castOpening($row);
            }
        }

        return $openings;
    }

    /**
     * Nenahrazené záznamy kumulace před obdobím pro celou množinu osob.
     *
     * @param list<int> $employeeIds
     * @param list<string> $calculationKinds
     * @return array<string,array<int,list<array<string,mixed>>>>
     */
    private function entriesBeforePeriod(
        int $supplierId,
        array $employeeIds,
        int $year,
        array $calculationKinds,
        string $periodStart,
    ): array {
        $entries = array_fill_keys($calculationKinds, []);
        foreach (array_chunk($employeeIds, self::CHUNK_SIZE) as $chunk) {
            $stmt = $this->db->pdo()->prepare(sprintf(
                'SELECT entry.*
                   FROM payroll_statutory_accumulator_entries entry
                  WHERE entry.supplier_id = ?
                    AND entry.employee_id IN (%s)
                    AND entry.tax_year = ?
                    AND entry.calculation_kind IN (%s)
                    AND entry.period_start < ?
                    AND NOT EXISTS (
                      SELECT 1
                        FROM payroll_statutory_accumulator_entries successor
                       WHERE successor.supplier_id = entry.supplier_id
                         AND successor.replaces_entry_id = entry.id
                    )
                  ORDER BY entry.period_start, entry.id',
                implode(', ', array_fill(0, count($chunk), '?')),
                implode(', ', array_fill(0, count($calculationKinds), '?')),
            ));
            $stmt->execute([
                $supplierId,
                ...$chunk,
                $year,
                ...$calculationKinds,
                $periodStart,
            ]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $kind = (string) $row['calculation_kind'];
                $entries[$kind][(int) $row['employee_id']][] = $this->castEntry($row);
            }
        }

        return $entries;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,int>
     */
    private function normalizeValues(
        string $calculationKind,
        array $values,
        bool $opening,
    ): array {
        $fields = self::VALUE_FIELDS[$calculationKind] ?? null;
        if ($fields === null) {
            throw new \InvalidArgumentException(
                'Nepodporovaný druh zákonné kumulace.',
            );
        }
        $provided = array_keys($values);
        sort($provided, SORT_STRING);
        $expected = $fields;
        sort($expected, SORT_STRING);
        if ($provided !== $expected) {
            throw new \InvalidArgumentException(
                'Zákonná kumulace neobsahuje přesně předepsané hodnoty.',
            );
        }
        $normalized = [];
        foreach ($fields as $field) {
            $value = $values[$field];
            if (!is_int($value) || $value < 0) {
                throw new \InvalidArgumentException(
                    "Hodnota {$field} musí být nezáporné celé číslo.",
                );
            }
            $normalized[$field] = $value;
        }
        if ($calculationKind === 'income_tax') {
            $maximumMonths = $opening ? 11 : 1;
            if (!array_key_exists('completed_months', $normalized)) {
                throw new \LogicException(
                    'Daňové schéma kumulace neobsahuje počet uzavřených měsíců.',
                );
            }
            if ($normalized['completed_months'] > $maximumMonths) {
                throw new \InvalidArgumentException(
                    'Počet uzavřených měsíců v daňové kumulaci není platný.',
                );
            }
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    private function approvedPersonRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.revision_kind, revision.previous_revision_id,
                    revision.status, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               JOIN payroll_run_persons person
                 ON person.supplier_id = revision.supplier_id
                AND person.revision_id = revision.id
                AND person.employee_id = ?
              WHERE revision.supplier_id = ? AND revision.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$employeeId, $supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \DomainException(
                'Revize nebo její zaměstnanec nepatří zadané firmě.',
            );
        }
        if ($row['status'] !== 'approved') {
            throw new \DomainException(
                'Do roční kumulace lze zapsat jen schválenou revizi.',
            );
        }

        return [
            'revision_kind' => (string) $row['revision_kind'],
            'previous_revision_id' => $row['previous_revision_id'] === null
                ? null
                : (int) $row['previous_revision_id'],
            'period_start' => (string) $row['period_start'],
        ];
    }

    /**
     * @return array{result_status:string,result_snapshot_hash:string}
     */
    private function authoritativePersonResult(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        string $calculationKind,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT result_status, result_snapshot_hash
               FROM payroll_statutory_person_results
              WHERE supplier_id = ?
                AND revision_id = ?
                AND employee_id = ?
                AND calculation_kind = ?
              FOR UPDATE'
        );
        $stmt->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $calculationKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PayrollStatutoryAccumulatorUnavailableException(
                'Revize nemá neměnný zákonný výsledek osoby pro zadaný druh výpočtu.',
            );
        }

        return [
            'result_status' => (string) $row['result_status'],
            'result_snapshot_hash' => (string) $row['result_snapshot_hash'],
        ];
    }

    /** @return array<string,mixed>|null */
    /**
     * Aktuální (nenahrazená) verze počátečního stavu, nebo `null`.
     *
     * Pro průvodce počátečními stavy: potřebuje `id`, protože oprava se na
     * aktuální verzi musí explicitně navázat — jinak ji `appendOpeningBalance()`
     * odmítne jako duplicitu.
     *
     * @return array<string,mixed>|null
     */
    public function openingBalance(
        int $supplierId,
        int $employeeId,
        int $year,
        string $calculationKind,
    ): ?array {
        return $this->currentOpening($supplierId, $employeeId, $year, $calculationKind, false);
    }

    /**
     * Má zaměstnanec za daný rok schválený zákonný výsledek?
     *
     * Řádek kumulace vzniká JEN schválením revize, takže je to nejlevnější
     * a nejpřesnější doklad o tom, že se z počátečního stavu už počítalo.
     * Po něm ho měnit nelze — přepsal by se základ hotového výpočtu.
     */
    public function hasApprovedResult(int $supplierId, int $employeeId, int $year): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_statutory_accumulator_entries
              WHERE supplier_id = ? AND employee_id = ? AND tax_year = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId, $year]);

        return $stmt->fetchColumn() !== false;
    }

    private function currentOpening(
        int $supplierId,
        int $employeeId,
        int $year,
        string $calculationKind,
        bool $forUpdate,
    ): ?array {
        $sql = 'SELECT opening.*
                  FROM payroll_statutory_accumulator_openings opening
                 WHERE opening.supplier_id = ?
                   AND opening.employee_id = ?
                   AND opening.tax_year = ?
                   AND opening.calculation_kind = ?
                   AND NOT EXISTS (
                     SELECT 1
                       FROM payroll_statutory_accumulator_openings successor
                      WHERE successor.supplier_id = opening.supplier_id
                        AND successor.replaces_opening_id = opening.id
                   )';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $employeeId, $year, $calculationKind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castOpening($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function openingByIdempotencyKey(int $supplierId, string $keyHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_statutory_accumulator_openings
              WHERE supplier_id = ? AND idempotency_key_hash = ?'
        );
        $stmt->execute([$supplierId, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castOpening($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function entryByRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
        string $calculationKind,
        bool $forUpdate,
    ): ?array {
        $sql = 'SELECT *
                  FROM payroll_statutory_accumulator_entries
                 WHERE supplier_id = ?
                   AND revision_id = ?
                   AND employee_id = ?
                   AND calculation_kind = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $calculationKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->castEntry($row) : null;
    }

    private function hasEntrySuccessor(int $supplierId, int $entryId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_statutory_accumulator_entries
              WHERE supplier_id = ? AND replaces_entry_id = ?'
        );
        $stmt->execute([$supplierId, $entryId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @param array<string,mixed> $row */
    private function replayedId(
        array $row,
        string $recordHash,
        string $message,
        ?PDOException $previous = null,
    ): int {
        if ($row['record_hash'] !== $recordHash) {
            throw new \DomainException($message, previous: $previous);
        }

        return $row['id'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castOpening(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'values' => $this->decodeIntMap($row['values_json']),
            'source_reference' => (string) $row['source_reference'],
            'evidence' => json_decode(
                (string) $row['evidence_json'],
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'replaces_opening_id' => $row['replaces_opening_id'] === null
                ? null
                : (int) $row['replaces_opening_id'],
            'record_hash' => (string) $row['record_hash'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castEntry(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'revision_id' => (int) $row['revision_id'],
            'period_start' => (string) $row['period_start'],
            'values' => $this->decodeIntMap($row['values_json']),
            'source_result_hash' => (string) $row['source_result_hash'],
            'replaces_entry_id' => $row['replaces_entry_id'] === null
                ? null
                : (int) $row['replaces_entry_id'],
            'record_hash' => (string) $row['record_hash'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array<string,int> */
    private function decodeIntMap(mixed $json): array
    {
        $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException(
                'Uložená zákonná kumulace nemá platný tvar.',
            );
        }
        $result = [];
        foreach ($decoded as $field => $value) {
            if (!is_string($field) || !is_int($value) || $value < 0) {
                throw new \UnexpectedValueException(
                    'Uložená zákonná kumulace obsahuje neplatnou hodnotu.',
                );
            }
            $result[$field] = $value;
        }

        return $result;
    }

    private function add(int $left, int $right, string $field): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new \OverflowException(
                "Roční kumulace {$field} překročila rozsah celého čísla.",
            );
        }

        return $left + $right;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transactional(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;

        return $driverCode === 1062 || $driverCode === '1062';
    }
}
