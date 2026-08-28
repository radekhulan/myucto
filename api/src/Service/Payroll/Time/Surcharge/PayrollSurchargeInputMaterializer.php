<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Time\Surcharge;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollComponentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollSurchargeClaimRepository;
use MyInvoice\Repository\Payroll\PayrollTimeValue;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;
use PDOException;

/**
 * Převádí spočítané zákonné příplatky § 114 až § 118 ZP na SCHVÁLENÉ mzdové
 * vstupy, aby se skutečně vyplatily.
 *
 * Bez tohoto kroku byl výpočet ({@see PayrollSurchargeService}) slepá větev:
 * spočítal se a zahodil. Příplatek je přitom zákonný nárok, ne návrh — a nárok,
 * který se nedostane do `payroll_inputs`, se nedostane ani na výplatní pásku,
 * ani do vyměřovacího základu pojistného, ani do JMHZ.
 *
 * ── Idempotence ─────────────────────────────────────────────────────────────
 *
 * Klíčem není částka, ale OTISK PODKLADU (`evidence_hash`, viz migrace 1627).
 * Opakované spuštění nad nezměněnou docházkou je no-op; změněná docházka
 * vyrobí OPRAVU. Rozhodovat podle částky by nestačilo — dvě různé evidence
 * mohou vyjít nastejno a „vyšlo to stejně" není totéž co „je to tentýž nárok".
 *
 * ── Oprava, ne druhý vstup ──────────────────────────────────────────────────
 *
 * Změní-li se docházka, NEZAPISUJE se nová plná částka. Zapíše se ROZDÍL proti
 * tomu, co už je za druh příplatku a měsíc schválené. Plná částka podruhé by
 * příplatek zaplatila dvakrát, a protože obě čísla vypadají věrohodně, poznalo
 * by se to až při kontrole. Původní vstup se přitom nemaže ani nepřepisuje:
 * splněný nárok je právní skutečnost.
 *
 * Táž mechanika obslouží i opravnou revizi. Znovu otevřený a znovu schválený
 * měsíc dá nový výpočet, ten se porovná s kumulativem a rozdíl se doplatí nebo
 * srazí. Klesne-li nárok na nulu, vznikne `reversal` — příplatek zanikl, což
 * je jiná informace než „změnil se".
 *
 * ── Fail-closed ─────────────────────────────────────────────────────────────
 *
 * Chybějící podklad nikdy nekončí nulou. Chybí-li průměrný výdělek, sjednaná
 * zásada u svátku nebo počet ztěžujících vlivů, vyhodí to
 * {@see PayrollSurchargeException} s hláškou, co doplnit. Nula se zapíše
 * jedině tehdy, když evidence říká nula minut — a to se nezapisuje vůbec,
 * protože nulový vstup by na mzdovém listu tvrdil, že nárok vznikl.
 */
final class PayrollSurchargeInputMaterializer
{
    /** Prefix `external_id` mzdového vstupu vzniklého materializací příplatku. */
    public const EXTERNAL_PREFIX = 'surcharge:';

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollComponentRepository $components,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollSurchargeService $surcharges,
        private readonly PayrollSurchargeClaimRepository $claims,
    ) {}

    /**
     * @param string $periodStart první den měsíce, `RRRR-MM-01`
     * @return array<string,mixed>
     */
    public function materialize(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        ?int $userId,
    ): array {
        if (preg_match('/^\d{4}-\d{2}-01$/D', $periodStart) !== 1) {
            throw PayrollSurchargeException::of(
                'invalid_period',
                'Období příplatků musí být první den měsíce.',
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $month = $this->approvedTimeMonth($supplierId, $employmentId, $periodStart);
            $revisionNo = PayrollTimeValue::int($month['revision_no'] ?? null, 'revision_no');

            $result = $this->surcharges->forPeriod(
                $supplierId,
                $employmentId,
                $periodStart,
                $this->averageHourlyMinor($supplierId, $employmentId, $periodStart),
            );

            $ledger = $this->latestByKind($supplierId, $employmentId, $periodStart);
            if ($result->lines === [] && $ledger === []) {
                // Měsíc bez jediné příznakové minuty. Nemá cenu ani sahat na
                // číselník složek — a hlavně to nesmí být důvod, proč by
                // schválení docházky spadlo.
                $this->commitOwned($pdo, $ownsTransaction);

                return $this->report($employmentId, $periodStart, $revisionNo, [], [], $result);
            }

            $this->assertNoQuickSurchargeInput($supplierId, $employmentId, $periodStart);
            $this->assertInputsNotLocked($supplierId, $employmentId, $periodStart);
            $this->components->ensureDefaults($supplierId);

            $written = [];
            $unchanged = [];
            foreach (PayrollSurchargeKind::all() as $kind) {
                $outcome = $this->reconcileKind(
                    $supplierId,
                    $employmentId,
                    $periodStart,
                    $revisionNo,
                    $kind,
                    $result,
                    $ledger[$kind->value] ?? null,
                    $userId,
                );
                if ($outcome === null) {
                    continue;
                }
                if ($outcome['written']) {
                    $written[] = $outcome['item'];
                } else {
                    $unchanged[] = $outcome['item'];
                }
            }
            $this->commitOwned($pdo, $ownsTransaction);
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $this->report(
            $employmentId,
            $periodStart,
            $revisionNo,
            $written,
            $unchanged,
            $result,
        );
    }

    /**
     * Srovná jeden druh příplatku: nic, nový vstup, nebo rozdíl.
     *
     * @param array<string,mixed>|null $previous poslední řádek ledgeru
     * @return array{written:bool,item:array<string,mixed>}|null
     */
    private function reconcileKind(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $revisionNo,
        PayrollSurchargeKind $kind,
        PayrollSurchargeResult $result,
        ?array $previous,
        ?int $userId,
    ): ?array {
        $line = $result->lineFor($kind);
        $desired = $line?->amountMinor ?? 0;
        $evidenceHash = $this->evidenceHash($kind, $line, $result);

        if ($previous === null) {
            if ($desired === 0) {
                return null;
            }

            return [
                'written' => true,
                'item' => $this->append(
                    $supplierId,
                    $employmentId,
                    $periodStart,
                    $revisionNo,
                    $kind,
                    1,
                    'original',
                    null,
                    $desired,
                    $desired,
                    $evidenceHash,
                    $this->snapshot($kind, $line, $result, $revisionNo, $desired, $desired),
                    $userId,
                ),
            ];
        }

        $cumulative = PayrollTimeValue::int($previous['cumulative_minor'] ?? null, 'cumulative_minor');
        $previousHash = $previous['evidence_hash'];
        if (is_string($previousHash) && hash_equals($previousHash, $evidenceHash)) {
            // Týž podklad, týž nárok. Opakované spuštění nesmí nic zapsat.
            return ['written' => false, 'item' => $this->item($previous, 'replayed')];
        }

        $delta = $desired - $cumulative;
        if ($delta === 0) {
            // Podklad se změnil, částka ne (typicky přesun hodin mezi dny).
            // Vstup s nulovou částkou se nezakládá: na mzdovém listu by tvrdil,
            // že vznikl další nárok. Stopu o přepočtu nese schválená revize
            // docházky, ne mzdový vstup.
            return ['written' => false, 'item' => $this->item($previous, 'amount_unchanged')];
        }

        return [
            'written' => true,
            'item' => $this->append(
                $supplierId,
                $employmentId,
                $periodStart,
                $revisionNo,
                $kind,
                PayrollTimeValue::int($previous['sequence_no'] ?? null, 'sequence_no') + 1,
                $desired === 0 ? 'reversal' : 'correction',
                PayrollTimeValue::int($previous['id'] ?? null, 'materialization_id'),
                $delta,
                $desired,
                $evidenceHash,
                $this->snapshot($kind, $line, $result, $revisionNo, $delta, $desired),
                $userId,
            ),
        ];
    }

    /**
     * Zapíše mzdový vstup i řádek ledgeru.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function append(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        int $revisionNo,
        PayrollSurchargeKind $kind,
        int $sequenceNo,
        string $materializationKind,
        ?int $supersedesId,
        int $amountMinor,
        int $cumulativeMinor,
        string $evidenceHash,
        array $snapshot,
        ?int $userId,
    ): array {
        // Nárok se zabírá dřív, než vznikne mzdový vstup. Unikátní klíč zápisu
        // nároku (migrace 1628) je jediná zábrana, která obstojí i tehdy, když
        // schválení docházky a uložení rychlého vstupu běží současně: obě cesty
        // zamykají různé řádky, takže samotný dotaz „nemá to už ten druhý?"
        // může u obou vyjít prázdně a obě by zapsaly.
        $this->claims->claim(
            $supplierId,
            $employmentId,
            $periodStart,
            $kind,
            PayrollSurchargeClaimRepository::SOURCE_TIME,
            $userId,
        );

        $externalId = sprintf(
            '%s%s:%s:%d',
            self::EXTERNAL_PREFIX,
            $kind->value,
            $periodStart,
            $sequenceNo,
        );
        $existing = $this->inputByExternalId($supplierId, $employmentId, $periodStart, $externalId);
        $inputId = $existing === null
            ? PayrollTimeValue::int(
                $this->inputs->createApproved($supplierId, [
                    'employee_id' => $this->employeeId($supplierId, $employmentId),
                    'employment_id' => $employmentId,
                    'component_id' => $this->componentId(
                        $supplierId,
                        $kind->componentCode(),
                        $periodStart,
                    ),
                    'period_start' => $periodStart,
                    // Oprava je nárok TOHOTO období, ne doplatek za minulé:
                    // vzniká z docházky téhož měsíce, jen se zjistila později.
                    'source_period_start' => null,
                    'amount_minor' => $amountMinor,
                    'quantity_milliunits' => null,
                    // `time`, protože podkladem je evidence docházky. `correction`
                    // je vyhrazená pro storno cizího vstupu; tady jde pořád o týž
                    // nárok, jen upřesněný.
                    'source_kind' => 'time',
                    'external_id' => $externalId,
                ], $userId)['id'] ?? null,
                'input_id',
            )
            : PayrollTimeValue::int($existing['id'] ?? null, 'input_id');

        $json = CanonicalJson::encode($snapshot);
        try {
            $this->db->pdo()->prepare(
                'INSERT INTO payroll_surcharge_input_materializations
                    (supplier_id, employment_id, period_start, surcharge_kind,
                     sequence_no, materialization_kind, time_month_revision_no,
                     input_id, amount_minor, cumulative_minor, evidence_hash,
                     supersedes_materialization_id, source_snapshot_json,
                     source_snapshot_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $supplierId,
                $employmentId,
                $periodStart,
                $kind->value,
                $sequenceNo,
                $materializationKind,
                $revisionNo,
                $inputId,
                $amountMinor,
                $cumulativeMinor,
                $evidenceHash,
                $supersedesId,
                $json,
                hash('sha256', $json, true),
                $userId,
            ]);
        } catch (PDOException $exception) {
            // Souběžná materializace téhož měsíce. Unikátní klíč ji zastavil
            // dřív, než stihla zdvojit nárok; čte se, co doopravdy vzniklo.
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        $row = $this->materialization($supplierId, $employmentId, $periodStart, $kind, $sequenceNo)
            ?? throw new \RuntimeException('Materializaci příplatku se nepodařilo uložit.');

        return $this->item($row, 'written');
    }

    /**
     * Otisk PODKLADU, ze kterého nárok vznikl.
     *
     * Vědomě NEobsahuje číslo revize docházky: znovu otevřený a beze změny
     * znovu schválený měsíc je týž nárok a nesmí vyrobit opravu. Obsahuje
     * naopak otisk sady pravidel — změna zákonné sazby JE změnou nároku.
     */
    private function evidenceHash(
        PayrollSurchargeKind $kind,
        ?PayrollSurchargeLine $line,
        PayrollSurchargeResult $result,
    ): string {
        return hash('sha256', CanonicalJson::encode([
            'kind' => 'payroll_surcharge_evidence.v1',
            'surcharge_kind' => $kind->value,
            'period_start' => $result->periodStart,
            'ruleset_id' => $result->rulesetId,
            'ruleset_content_hash' => $result->rulesetContentHash,
            'line' => $line?->jsonSerialize(),
        ]), true);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(
        PayrollSurchargeKind $kind,
        ?PayrollSurchargeLine $line,
        PayrollSurchargeResult $result,
        int $revisionNo,
        int $amountMinor,
        int $cumulativeMinor,
    ): array {
        return [
            'kind' => 'payroll_surcharge_materialization.v1',
            'surcharge_kind' => $kind->value,
            'section' => $kind->section(),
            'component_code' => $kind->componentCode(),
            'period_start' => $result->periodStart,
            'time_month_revision_no' => $revisionNo,
            'amount_minor' => $amountMinor,
            'cumulative_minor' => $cumulativeMinor,
            'support_status' => $result->supportStatus,
            'ruleset_id' => $result->rulesetId,
            'ruleset_content_hash' => $result->rulesetContentHash,
            'line' => $line?->jsonSerialize(),
            'findings' => $result->findings,
        ];
    }

    /** @return array<string,mixed> */
    private function approvedTimeMonth(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, revision_no
               FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw PayrollSurchargeException::of(
                'time_month_missing',
                'Do mzdy lze promítnout jen příplatky ze schválené docházky; '
                . 'měsíc dosud schválen nebyl.',
            );
        }
        if (($row['status'] ?? null) !== 'approved') {
            throw PayrollSurchargeException::of(
                'time_month_not_approved',
                'Do mzdy lze promítnout jen příplatky ze schválené docházky.',
            );
        }

        return PayrollTimeValue::row($row, 'payroll_time_months');
    }

    /**
     * Schválený a podložený průměrný výdělek pro čtvrtletí období.
     *
     * Vrací nulu, není-li žádný. Nula NENÍ tichý průchod: kalkulátor na ni
     * u příplatku počítaného z průměrného výdělku vyhodí `average_earning_missing`,
     * kdežto u § 117 (základ je minimální mzda) ji vůbec nepotřebuje. Kdyby se
     * fail-closed dělal tady, nešel by spočítat ani příplatek za ztížené
     * prostředí zaměstnanci, kterému průměr ještě nikdo nezjistil.
     */
    private function averageHourlyMinor(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): int {
        $year = (int) substr($periodStart, 0, 4);
        $quarter = intdiv((int) substr($periodStart, 5, 2) - 1, 3) + 1;
        $stmt = $this->db->pdo()->prepare(
            'SELECT average_hourly_minor
               FROM payroll_average_earning_snapshots
              WHERE supplier_id = ? AND employment_id = ?
                AND applicable_year = ? AND applicable_quarter = ?
                AND status = "approved" AND support_status = "supported"
              ORDER BY revision_no DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $year, $quarter]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    /**
     * Poslední řádek ledgeru pro každý druh příplatku.
     *
     * `FOR UPDATE` na celý rozsah vztahu a měsíce, ne jen na nalezené řádky:
     * souběžná materializace by jinak mezi čtením a zápisem stihla založit
     * vlastní `sequence_no` a nároky by se sečetly dvakrát.
     *
     * @return array<string,array<string,mixed>>
     */
    private function latestByKind(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_surcharge_input_materializations
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
              ORDER BY surcharge_kind, sequence_no
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        $latest = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $latest[(string) $row['surcharge_kind']] = $row;
        }

        return $latest;
    }

    /** @return array<string,mixed>|null */
    private function materialization(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        PayrollSurchargeKind $kind,
        int $sequenceNo,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_surcharge_input_materializations
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND surcharge_kind = ? AND sequence_no = ?'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, $kind->value, $sequenceNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function inputByExternalId(
        int $supplierId,
        int $employmentId,
        string $periodStart,
        string $externalId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND source_kind = "time" AND external_id = ? AND status <> "cancelled"
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, $externalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Po `lock_inputs` už mzdový běh pracuje se zmrazeným snapshotem vstupů.
     * Nový vstup do uzamčeného období by se do výplaty nedostal a přitom by
     * v přehledu vypadal, že se dostal — tichý nedoplatek. Odmítá se proto
     * dřív, než vznikne.
     */
    private function assertInputsNotLocked(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND period_start = ?
                AND status = "locked"
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart]);
        if ($stmt->fetchColumn() !== false) {
            throw PayrollSurchargeException::of(
                'inputs_locked',
                'Mzdové vstupy období jsou uzamčené mzdovým během. Příplatky do nich '
                . 'promítnout nelze — otevřete běh znovu a materializaci zopakujte.',
            );
        }
    }

    /**
     * Příplatek zadaný rychlým formulářem a týž příplatek z docházky jsou DVA
     * podklady pro TÝŽ nárok. Kdyby se materializovaly oba, zaměstnanec dostane
     * zaplaceno dvakrát.
     *
     * Do W20 se hlídal jen přesčas, protože jen ten šlo rychlým zadáním zadat.
     * Od chvíle, kdy jdou zadat i § 115 až § 118, musí zábrana pokrývat VŠECHNY
     * druhy — a musí říct, KTERÝ druh koliduje, ne jen že něco koliduje.
     *
     * Tohle je čitelná polovina zábrany. Tvrdou polovinu, která obstojí i proti
     * souběhu dvou transakcí, dělá zápis nároku v {@see reconcileKind()}.
     */
    private function assertNoQuickSurchargeInput(
        int $supplierId,
        int $employmentId,
        string $periodStart,
    ): void {
        $codes = [
            // Sběrná složka: přesčas zadaný celkovou částkou. Vlastní druh
            // příplatku nemá, ale nárok § 114 uspokojuje stejně.
            'PREMIE_PRIPLATKY' => PayrollSurchargeKind::Overtime,
            ...array_combine(
                array_map(
                    static fn (PayrollSurchargeKind $kind): string => $kind->componentCode(),
                    PayrollSurchargeKind::all(),
                ),
                PayrollSurchargeKind::all(),
            ),
        ];
        $stmt = $this->db->pdo()->prepare(
            'SELECT component.code
               FROM payroll_inputs input
               JOIN payroll_component_definitions component
                 ON component.supplier_id = input.supplier_id
                AND component.id = input.component_id
              WHERE input.supplier_id = ? AND input.employment_id = ?
                AND input.period_start = ? AND input.status <> "cancelled"
                AND input.source_kind = "manual"
                AND input.external_id LIKE "quick-monthly:%"
                AND component.code IN ('
            . implode(',', array_fill(0, count($codes), '?'))
            . ')
                AND input.amount_minor <> 0
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId, $periodStart, ...array_keys($codes)]);
        $code = $stmt->fetchColumn();
        if ($code === false) {
            return;
        }
        $kind = $codes[(string) $code] ?? PayrollSurchargeKind::Overtime;
        throw PayrollSurchargeException::of(
            $kind === PayrollSurchargeKind::Overtime
                // Kód zůstává, protože na něj reaguje prohlížeč i starší testy.
                ? 'overtime_conflict'
                : 'surcharge_source_conflict',
            sprintf(
                '%s (%s) je za toto období evidován rychlým zadáním i docházkou. '
                . 'Ponechte jen jeden podklad, jinak by se příplatek vyplatil dvakrát.',
                $kind->label(),
                $kind->section(),
            ),
        );
    }

    private function employeeId(int $supplierId, int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employee_id FROM payroll_employments WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw PayrollSurchargeException::of(
                'employment_missing',
                'Pracovní vztah nepatří této firmě.',
            );
        }

        return (int) $value;
    }

    private function componentId(int $supplierId, string $code, string $periodStart): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_component_definitions
              WHERE supplier_id = ? AND code = ? AND is_active = 1
                AND valid_from <= ? AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$supplierId, $code, $periodStart, $periodStart]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw PayrollSurchargeException::of(
                'component_missing',
                sprintf('Pro příplatek chybí účinná mzdová složka %s.', $code),
            );
        }

        return (int) $id;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function item(array $row, string $outcome): array
    {
        return [
            'outcome' => $outcome,
            'materialization_id' => PayrollTimeValue::int($row['id'] ?? null, 'materialization_id'),
            'input_id' => PayrollTimeValue::int($row['input_id'] ?? null, 'input_id'),
            'surcharge_kind' => PayrollTimeValue::string(
                $row['surcharge_kind'] ?? null,
                'surcharge_kind',
            ),
            'materialization_kind' => PayrollTimeValue::string(
                $row['materialization_kind'] ?? null,
                'materialization_kind',
            ),
            'sequence_no' => PayrollTimeValue::int($row['sequence_no'] ?? null, 'sequence_no'),
            'amount_minor' => PayrollTimeValue::int($row['amount_minor'] ?? null, 'amount_minor'),
            'cumulative_minor' => PayrollTimeValue::int(
                $row['cumulative_minor'] ?? null,
                'cumulative_minor',
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $written
     * @param list<array<string,mixed>> $unchanged
     * @return array<string,mixed>
     */
    private function report(
        int $employmentId,
        string $periodStart,
        int $revisionNo,
        array $written,
        array $unchanged,
        PayrollSurchargeResult $result,
    ): array {
        return [
            'employment_id' => $employmentId,
            'period_start' => $periodStart,
            'time_month_revision_no' => $revisionNo,
            'total_minor' => $result->totalMinor,
            'written_count' => count($written),
            'unchanged_count' => count($unchanged),
            'written' => $written,
            'unchanged' => $unchanged,
            'requires_manual_review' => $result->requiresManualReview(),
            'findings' => $result->findings,
        ];
    }

    private function commitOwned(PDO $pdo, bool $ownsTransaction): void
    {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    }
}
