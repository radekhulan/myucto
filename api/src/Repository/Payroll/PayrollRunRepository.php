<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Run\PayrollRunInputSnapshot;
use MyInvoice\Service\Payroll\Run\PayrollRunValidationMessageFormatter;
use PDO;

final class PayrollRunRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Verze vstupního snapshotu, od které běh umí materializovat výplaty.
     * Jediná definice pro SQL v seznamu i pro PHP test v detailu.
     */
    private const INPUT_SCHEMA_WITH_PAYOUTS = 'payroll-run-input.v2';

    /** Strop stránky seznamu — víc než rok a půl období naráz nikdo nečte. */
    public const LIST_MAX_LIMIT = 50;

    public const LIST_DEFAULT_LIMIT = 12;

    /**
     * Seznam mzdových běhů — VÝHRADNĚ lehká data.
     *
     * ── Co se opravovalo ────────────────────────────────────────────────────────
     * Dotaz tahal `revision.input_snapshot_json` i `revision.result_snapshot_json`
     * (oba LONGTEXT) pro VŠECHNY běhy firmy a oba `json_decode`oval do paměti.
     * Období je přitom volitelné, takže `GET /payroll/runs` bez filtru načetl
     * kompletní mzdovou historii firmy — u firmy se stovkou zaměstnanců a pár lety
     * provozu to znamená stovky megabajtů a pád na `memory_limit`. Ze vstupního
     * snapshotu se přitom používal JEDINÝ boolean a z výsledkového tři částky.
     *
     * ── Co se dělá teď ──────────────────────────────────────────────────────────
     * Oba LONGTEXTy zůstávají v databázi a čtou se celé jen v detailu běhu
     * ({@see self::detail()}). Do seznamu se z nich SQL vytáhne jen to, co seznam
     * skutečně zobrazuje, a stránkuje se s tvrdým stropem.
     *
     * Osobní rozpad (`result_snapshot.people`) v seznamu ZÁMĚRNĚ není — to je ta
     * objemná část a frontend si ji dotahuje na vyžádání pro jeden konkrétní běh.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function list(
        int $supplierId,
        ?string $periodStart = null,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $where = ' WHERE run.supplier_id = ?';
        $params = [$supplierId];
        if ($periodStart !== null) {
            $where .= ' AND run.period_start = ?';
            $params[] = $periodStart;
        }

        $countStmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_runs run' . $where,
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // `payment_materialization_supported`: schéma v2 a KAŽDÁ osoba nese pole
        // `payout_accounts`. Wildcard `$.people[*].payout_accounts` vrací v MariaDB
        // pole všech nalezených hodnot (i pro jedinou osobu), takže osoba bez klíče
        // sníží počet — porovnání délek je tedy úplný test „nikomu nechybí".
        // Prázdné `people` dá 0 = 0, tedy true, stejně jako se dřív choval PHP cyklus.
        // Jediná vědomá odchylka od původního PHP testu: `payout_accounts` uložené
        // jako objekt místo pole tu projde. Snapshot builder pole zapisuje vždy,
        // a cena za odstranění dekódu celého LONGTEXTu je to nesrovnatelně nižší.
        $sql = 'SELECT run.*,
                       revision.id AS revision_id,
                       revision.revision_no,
                       revision.revision_kind,
                       revision.status AS revision_status,
                       revision.calculated_by,
                       revision.reviewed_by,
                       revision.approved_by,
                       (revision.input_snapshot_json IS NOT NULL
                        AND JSON_VALUE(revision.input_snapshot_json, "$.schema_version")
                            = "' . self::INPUT_SCHEMA_WITH_PAYOUTS . '"
                        AND JSON_TYPE(JSON_QUERY(revision.input_snapshot_json, "$.people")) = "ARRAY"
                        AND JSON_LENGTH(revision.input_snapshot_json, "$.people")
                            = COALESCE(JSON_LENGTH(JSON_EXTRACT(
                                  revision.input_snapshot_json, "$.people[*].payout_accounts"
                              )), 0)
                       ) AS payment_materialization_supported,
                       (revision.result_snapshot_json IS NOT NULL) AS has_result_snapshot,
                       JSON_VALUE(revision.result_snapshot_json, "$.totals.cash_payable_minor")
                           AS total_cash_payable_minor,
                       JSON_VALUE(revision.result_snapshot_json, "$.totals.enforcement_withheld_minor")
                           AS total_enforcement_withheld_minor,
                       JSON_VALUE(revision.result_snapshot_json, "$.totals.payable_after_enforcement_minor")
                           AS total_payable_after_enforcement_minor
                  FROM payroll_runs run
             LEFT JOIN payroll_run_revisions revision
                    ON revision.supplier_id = run.supplier_id
                   AND revision.run_id = run.id
                   AND revision.revision_no = run.current_revision_no'
            . $where
            . ' ORDER BY run.period_start DESC, run.office_scope_id, run.id
                 LIMIT ? OFFSET ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $position = 1;
        foreach ($params as $param) {
            $stmt->bindValue($position++, $param);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $run = self::castRun($row);
            $run['revision_id'] = $row['revision_id'] === null
                ? null
                : (int) $row['revision_id'];
            $run['revision_no'] = $row['revision_no'] === null
                ? null
                : (int) $row['revision_no'];
            $run['revision_kind'] = $row['revision_kind'];
            $run['revision_status'] = $row['revision_status'];
            $run['payment_materialization_supported'] =
                (bool) (int) $row['payment_materialization_supported'];
            $run['result_snapshot'] = self::listTotals($row);
            foreach (['calculated_by', 'reviewed_by', 'approved_by'] as $field) {
                $run[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
            foreach ([
                'has_result_snapshot',
                'total_cash_payable_minor',
                'total_enforcement_withheld_minor',
                'total_payable_after_enforcement_minor',
            ] as $scratch) {
                unset($run[$scratch]);
            }
            $items[] = $run;
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Jeden běh včetně CELÉHO výsledkového snapshotu — objemná data, která seznam
     * záměrně neposílá. Vstupní snapshot se nevrací vůbec: nese osobní údaje všech
     * zaměstnanců a žádná obrazovka ho nezobrazuje.
     *
     * @return array<string,mixed>|null
     */
    public function detail(int $supplierId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.*,
                    revision.id AS revision_id,
                    revision.revision_no,
                    revision.revision_kind,
                    revision.status AS revision_status,
                    revision.result_snapshot_json,
                    revision.input_snapshot_json,
                    revision.calculated_by,
                    revision.reviewed_by,
                    revision.approved_by
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?',
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $run = self::castRun($row);
        $run['revision_id'] = $row['revision_id'] === null ? null : (int) $row['revision_id'];
        $run['revision_no'] = $row['revision_no'] === null ? null : (int) $row['revision_no'];
        $run['revision_kind'] = $row['revision_kind'];
        $run['revision_status'] = $row['revision_status'];
        $inputSnapshot = $row['input_snapshot_json'] === null
            ? null
            : json_decode((string) $row['input_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        $run['payment_materialization_supported'] =
            self::supportsPaymentMaterialization($inputSnapshot);
        $run['result_snapshot'] = $row['result_snapshot_json'] === null
            ? null
            : json_decode((string) $row['result_snapshot_json'], true, flags: JSON_THROW_ON_ERROR);
        foreach (['calculated_by', 'reviewed_by', 'approved_by'] as $field) {
            $run[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        unset($run['result_snapshot_json'], $run['input_snapshot_json']);

        return $run;
    }

    /**
     * Zmenšený `result_snapshot` pro seznam: `null`, když revize výsledek nemá,
     * jinak jen blok `totals`. Tvar odpovědi tak zůstává týž jako dřív, jen bez
     * osobního rozpadu — frontend čte `result_snapshot?.totals` beze změny.
     *
     * @param array<string,mixed> $row
     * @return array{totals: array<string,int|null>}|null
     */
    private static function listTotals(array $row): ?array
    {
        if ((int) $row['has_result_snapshot'] !== 1) {
            return null;
        }

        return [
            'totals' => [
                'cash_payable_minor' => self::nullableMinor($row['total_cash_payable_minor']),
                'enforcement_withheld_minor' => self::nullableMinor($row['total_enforcement_withheld_minor']),
                'payable_after_enforcement_minor' => self::nullableMinor(
                    $row['total_payable_after_enforcement_minor'],
                ),
            ],
        ];
    }

    private static function nullableMinor(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /** @return array<string,mixed> */
    public function createOrGet(
        int $supplierId,
        string $periodStart,
        string $paymentDate,
        ?int $officeId,
        ?int $actorUserId,
    ): array {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_runs
                (supplier_id, office_id, period_start, payment_date,
                 created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            $supplierId,
            $officeId,
            $periodStart,
            $paymentDate,
            $actorUserId,
            $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($id <= 0) {
            $find = $pdo->prepare(
                'SELECT id FROM payroll_runs
                  WHERE supplier_id = ? AND period_start = ?
                    AND office_scope_id = COALESCE(?, 0)'
            );
            $find->execute([$supplierId, $periodStart, $officeId]);
            $id = (int) $find->fetchColumn();
        }
        $run = $this->find($supplierId, $id)
            ?? throw new \RuntimeException('Mzdový běh se nepodařilo načíst.');
        if ((string) $run['payment_date'] !== $paymentDate) {
            throw new \DomainException(
                'Mzdový běh pro období už existuje s jiným datem výplaty.',
            );
        }
        if ($stmt->rowCount() === 1) {
            $this->insertEvent(
                $supplierId,
                $id,
                null,
                'created',
                null,
                'draft',
                $actorUserId,
                null,
                [
                    'period_start' => $periodStart,
                    'payment_date' => $paymentDate,
                    'office_id' => $officeId,
                ],
            );
        }
        return $run;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_runs WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRun($row);
    }

    /** @return array<string,mixed>|null */
    public function lock(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_runs
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRun($row);
    }

    public function canDelete(
        int $supplierId,
        int $runId,
    ): ?PayrollRunDeletionDecision {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.status,
                    run.row_version,
                    run.current_revision_no,
                    ownership.processor AS ownership_processor,
                    ownership.source_type AS ownership_source_type,
                    ownership.source_id AS ownership_source_id,
                    (
                        SELECT MIN(sibling.id)
                          FROM payroll_runs sibling
                         WHERE sibling.supplier_id = run.supplier_id
                           AND sibling.period_start = run.period_start
                           AND sibling.id <> run.id
                    ) AS replacement_owner_run_id,
                    (
                        SELECT COUNT(*)
                          FROM payroll_generated_documents document
                         WHERE document.supplier_id = run.supplier_id
                           AND document.run_id = run.id
                    ) AS document_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_posting_batches posting
                         WHERE posting.supplier_id = run.supplier_id
                           AND posting.run_id = run.id
                    ) AS posting_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_run_revisions payment_revision
                         WHERE payment_revision.supplier_id = run.supplier_id
                           AND payment_revision.run_id = run.id
                           AND (
                               EXISTS (
                                   SELECT 1
                                     FROM payroll_payment_liabilities liability
                                    WHERE liability.supplier_id = payment_revision.supplier_id
                                      AND liability.revision_id = payment_revision.id
                               )
                               OR EXISTS (
                                   SELECT 1
                                     FROM payroll_payout_allocations allocation
                                    WHERE allocation.supplier_id = payment_revision.supplier_id
                                      AND allocation.revision_id = payment_revision.id
                               )
                               OR EXISTS (
                                   SELECT 1
                                     FROM payroll_deduction_ledger deduction
                                    WHERE deduction.supplier_id = payment_revision.supplier_id
                                      AND deduction.revision_id = payment_revision.id
                               )
                           )
                    ) AS payment_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_submissions submission
                          JOIN payroll_run_revisions submission_revision
                            ON submission_revision.supplier_id = submission.supplier_id
                           AND submission_revision.id = submission.source_revision_id
                         WHERE submission_revision.supplier_id = run.supplier_id
                           AND submission_revision.run_id = run.id
                    ) AS submission_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_run_revisions revision
                         WHERE revision.supplier_id = run.supplier_id
                           AND revision.run_id = run.id
                    ) AS revision_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_run_commands command_receipt
                         WHERE command_receipt.supplier_id = run.supplier_id
                           AND command_receipt.run_id = run.id
                    ) AS command_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_run_events event
                         WHERE event.supplier_id = run.supplier_id
                           AND event.run_id = run.id
                    ) AS event_count,
                    (
                        SELECT COUNT(*)
                          FROM payroll_run_events event
                         WHERE event.supplier_id = run.supplier_id
                           AND event.run_id = run.id
                           AND event.event_type = "created"
                           AND event.revision_id IS NULL
                           AND event.from_status IS NULL
                           AND event.to_status = "draft"
                           AND event.reason IS NULL
                           AND event.actor_user_id <=> run.created_by
                    ) AS created_event_count,
                    (
                        SELECT MIN(event.id)
                          FROM payroll_run_events event
                         WHERE event.supplier_id = run.supplier_id
                           AND event.run_id = run.id
                           AND event.event_type = "created"
                           AND event.revision_id IS NULL
                           AND event.from_status IS NULL
                           AND event.to_status = "draft"
                           AND event.reason IS NULL
                           AND event.actor_user_id <=> run.created_by
                    ) AS created_event_id,
                    (
                        SELECT COUNT(*)
                          FROM payroll_period_ownership stale_ownership
                         WHERE stale_ownership.supplier_id = run.supplier_id
                           AND stale_ownership.source_id = run.id
                           AND (
                               stale_ownership.period_start <> run.period_start
                               OR stale_ownership.processor <> "payroll"
                               OR stale_ownership.source_type <> "payroll_run"
                           )
                    ) AS conflicting_ownership_count
               FROM payroll_runs run
          LEFT JOIN payroll_period_ownership ownership
                 ON ownership.supplier_id = run.supplier_id
                AND ownership.period_start = run.period_start
              WHERE run.supplier_id = ? AND run.id = ?',
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        foreach ([
            ['document_count', 'payroll_run_has_documents', 'Mzdový běh má vygenerované dokumenty.'],
            ['posting_count', 'payroll_run_has_posting', 'Mzdový běh má účetní stopu.'],
            ['payment_count', 'payroll_run_has_payments', 'Mzdový běh má platební stopu.'],
            ['submission_count', 'payroll_run_has_submissions', 'Mzdový běh je zdrojem podání.'],
            ['revision_count', 'payroll_run_has_revision', 'Mzdový běh už obsahuje neměnnou revizi.'],
        ] as [$field, $code, $message]) {
            if ((int) $row[$field] > 0) {
                return PayrollRunDeletionDecision::blocked($code, $message);
            }
        }
        if ((int) $row['current_revision_no'] !== 0) {
            return PayrollRunDeletionDecision::blocked(
                'payroll_run_has_revision',
                'Mzdový běh odkazuje na neměnnou revizi.',
            );
        }
        $status = (string) $row['status'];
        if (!in_array($status, ['draft', 'cancelled'], true)) {
            return PayrollRunDeletionDecision::blocked(
                'payroll_run_status_not_deletable',
                'Mzdový běh v tomto stavu nelze smazat.',
            );
        }
        if ((int) $row['created_event_count'] !== 1
            || (int) $row['created_event_id'] <= 0
        ) {
            return PayrollRunDeletionDecision::blocked(
                'payroll_run_has_event_history',
                'Mzdový běh nemá platnou počáteční auditní událost.',
            );
        }

        $cancelCommandId = null;
        $cancelEventId = null;
        if ($status === 'draft') {
            if ((int) $row['row_version'] !== 1) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_command_history',
                    'Koncept mzdového běhu nemá počáteční verzi.',
                );
            }
            if ((int) $row['command_count'] !== 0) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_command_history',
                    'Koncept mzdového běhu už obsahuje historii příkazů.',
                );
            }
            if ((int) $row['event_count'] !== 1) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_event_history',
                    'Koncept mzdového běhu nemá pouze počáteční auditní událost.',
                );
            }
        } else {
            if ((int) $row['row_version'] !== 2) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_command_history',
                    'Zrušený mzdový běh nemá kanonickou verzi po zrušení.',
                );
            }
            if ((int) $row['command_count'] !== 1) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_command_history',
                    'Zrušený mzdový běh nemá jediný kanonický příkaz zrušení.',
                );
            }
            if ((int) $row['event_count'] !== 2) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_event_history',
                    'Zrušený mzdový běh nemá pouze počáteční událost a událost zrušení.',
                );
            }
            $cancelCommandId = $this->canonicalCancelCommandId(
                $supplierId,
                $runId,
                (int) $row['row_version'],
            );
            if ($cancelCommandId === null) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_command_history',
                    'Příkaz zrušení mzdového běhu není kanonický.',
                );
            }
            $cancelEventId = $this->canonicalCancelEventId(
                $supplierId,
                $runId,
                (int) $row['row_version'],
                $cancelCommandId,
            );
            if ($cancelEventId === null) {
                return PayrollRunDeletionDecision::blocked(
                    'payroll_run_has_event_history',
                    'Auditní událost zrušení mzdového běhu není kanonická.',
                );
            }
        }
        if ((int) $row['conflicting_ownership_count'] > 0) {
            return PayrollRunDeletionDecision::blocked(
                'payroll_run_period_ownership_conflict',
                'Rezervace mzdového období neodpovídá tomuto běhu.',
            );
        }

        $ownsPeriod = $row['ownership_source_id'] !== null
            && (int) $row['ownership_source_id'] === $runId;
        if ($ownsPeriod
            && (
                (string) $row['ownership_processor'] !== 'payroll'
                || (string) $row['ownership_source_type'] !== 'payroll_run'
            )
        ) {
            return PayrollRunDeletionDecision::blocked(
                'payroll_run_period_ownership_conflict',
                'Rezervace mzdového období neodpovídá tomuto běhu.',
            );
        }

        return PayrollRunDeletionDecision::allowed(
            (int) $row['created_event_id'],
            $cancelEventId,
            $cancelCommandId,
            $ownsPeriod,
            $row['replacement_owner_run_id'] === null
                ? null
                : (int) $row['replacement_owner_run_id'],
        );
    }

    private function canonicalCancelCommandId(
        int $supplierId,
        int $runId,
        int $rowVersion,
    ): ?int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT command_receipt.id
               FROM payroll_runs run
               JOIN payroll_run_commands command_receipt
                 ON command_receipt.supplier_id = run.supplier_id
                AND command_receipt.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.id = ?
                AND run.status = "cancelled"
                AND run.row_version = ?
                AND run.row_version = 2
                AND run.current_revision_no = 0
                AND command_receipt.command_name = "cancel"
                AND command_receipt.revision_id IS NULL
                AND command_receipt.expected_row_version = 1
                AND command_receipt.from_status = "draft"
                AND command_receipt.to_status = "cancelled"
                AND command_receipt.actor_user_id <=> run.updated_by
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.run_id")
                ) = CAST(run.id AS CHAR)
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.from_status")
                ) = "draft"
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.to_status")
                ) = "cancelled"
                AND CAST(JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.row_version")
                ) AS UNSIGNED) = run.row_version',
        );
        $stmt->execute([$supplierId, $runId, $rowVersion]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function canonicalCancelEventId(
        int $supplierId,
        int $runId,
        int $rowVersion,
        int $commandId,
    ): ?int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cancel_event.id
               FROM payroll_runs run
               JOIN payroll_run_commands command_receipt
                 ON command_receipt.supplier_id = run.supplier_id
                AND command_receipt.run_id = run.id
                AND command_receipt.id = ?
               JOIN payroll_run_events cancel_event
                 ON cancel_event.supplier_id = run.supplier_id
                AND cancel_event.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.id = ?
                AND run.status = "cancelled"
                AND run.row_version = ?
                AND run.row_version = 2
                AND run.current_revision_no = 0
                AND cancel_event.event_type = "cancel"
                AND cancel_event.revision_id IS NULL
                AND cancel_event.from_status = "draft"
                AND cancel_event.to_status = "cancelled"
                AND cancel_event.actor_user_id <=> command_receipt.actor_user_id
                AND cancel_event.reason IS NOT NULL
                AND TRIM(cancel_event.reason) <> ""
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(cancel_event.metadata_json, "$.request_hash")
                ) = command_receipt.request_hash
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(
                        cancel_event.metadata_json,
                        "$.idempotency_key_hash"
                    )
                ) = LOWER(HEX(command_receipt.idempotency_key_hash))
                AND CAST(JSON_UNQUOTE(
                    JSON_EXTRACT(cancel_event.metadata_json, "$.row_version")
                ) AS UNSIGNED) = run.row_version',
        );
        $stmt->execute([$commandId, $supplierId, $runId, $rowVersion]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function enableEmptyRunDeleteGuard(
        int $supplierId,
        int $runId,
        int $rowVersion,
        int $eventId,
        ?int $cancelEventId,
        ?int $cancelCommandId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SET @payroll_empty_run_delete_supplier_id = ?,
                 @payroll_empty_run_delete_run_id = ?,
                 @payroll_empty_run_delete_row_version = ?,
                 @payroll_empty_run_delete_event_id = ?,
                 @payroll_empty_run_delete_cancel_event_id = ?,
                 @payroll_empty_run_delete_cancel_command_id = ?',
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $rowVersion,
            $eventId,
            $cancelEventId,
            $cancelCommandId,
        ]);
    }

    public function clearEmptyRunDeleteGuard(): void
    {
        $this->db->pdo()->exec(
            'SET @payroll_empty_run_delete_supplier_id = NULL,
                 @payroll_empty_run_delete_run_id = NULL,
                 @payroll_empty_run_delete_row_version = NULL,
                 @payroll_empty_run_delete_event_id = NULL,
                 @payroll_empty_run_delete_cancel_event_id = NULL,
                 @payroll_empty_run_delete_cancel_command_id = NULL',
        );
    }

    public function deleteCanonicalCancelEvent(
        int $supplierId,
        int $runId,
        int $rowVersion,
        int $createdEventId,
        int $cancelEventId,
        int $cancelCommandId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'DELETE cancel_event
               FROM payroll_run_events cancel_event
               JOIN payroll_runs run
                 ON run.supplier_id = cancel_event.supplier_id
                AND run.id = cancel_event.run_id
               JOIN payroll_run_events created_event
                 ON created_event.supplier_id = run.supplier_id
                AND created_event.run_id = run.id
                AND created_event.id = ?
               JOIN payroll_run_commands command_receipt
                 ON command_receipt.supplier_id = run.supplier_id
                AND command_receipt.run_id = run.id
                AND command_receipt.id = ?
          LEFT JOIN payroll_run_events other_event
                 ON other_event.supplier_id = run.supplier_id
                AND other_event.run_id = run.id
                AND other_event.id NOT IN (created_event.id, cancel_event.id)
          LEFT JOIN payroll_run_commands other_command
                 ON other_command.supplier_id = run.supplier_id
                AND other_command.run_id = run.id
                AND other_command.id <> command_receipt.id
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_generated_documents document
                 ON document.supplier_id = run.supplier_id
                AND document.run_id = run.id
          LEFT JOIN payroll_posting_batches posting
                 ON posting.supplier_id = run.supplier_id
                AND posting.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.id = ?
                AND run.status = "cancelled"
                AND run.row_version = ?
                AND run.row_version = 2
                AND run.current_revision_no = 0
                AND cancel_event.id = ?
                AND cancel_event.event_type = "cancel"
                AND cancel_event.revision_id IS NULL
                AND cancel_event.from_status = "draft"
                AND cancel_event.to_status = "cancelled"
                AND cancel_event.actor_user_id <=> command_receipt.actor_user_id
                AND cancel_event.reason IS NOT NULL
                AND TRIM(cancel_event.reason) <> ""
                AND command_receipt.command_name = "cancel"
                AND command_receipt.revision_id IS NULL
                AND command_receipt.expected_row_version = 1
                AND command_receipt.from_status = "draft"
                AND command_receipt.to_status = "cancelled"
                AND command_receipt.actor_user_id <=> run.updated_by
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(cancel_event.metadata_json, "$.request_hash")
                ) = command_receipt.request_hash
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(
                        cancel_event.metadata_json,
                        "$.idempotency_key_hash"
                    )
                ) = LOWER(HEX(command_receipt.idempotency_key_hash))
                AND CAST(JSON_UNQUOTE(
                    JSON_EXTRACT(cancel_event.metadata_json, "$.row_version")
                ) AS UNSIGNED) = run.row_version
                AND created_event.event_type = "created"
                AND created_event.revision_id IS NULL
                AND created_event.from_status IS NULL
                AND created_event.to_status = "draft"
                AND created_event.reason IS NULL
                AND created_event.actor_user_id <=> run.created_by
                AND other_event.id IS NULL
                AND other_command.id IS NULL
                AND revision.id IS NULL
                AND document.id IS NULL
                AND posting.id IS NULL',
        );
        $stmt->execute([
            $createdEventId,
            $cancelCommandId,
            $supplierId,
            $runId,
            $rowVersion,
            $cancelEventId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function deleteCanonicalCancelCommand(
        int $supplierId,
        int $runId,
        int $rowVersion,
        int $createdEventId,
        int $cancelCommandId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'DELETE command_receipt
               FROM payroll_run_commands command_receipt
               JOIN payroll_runs run
                 ON run.supplier_id = command_receipt.supplier_id
                AND run.id = command_receipt.run_id
               JOIN payroll_run_events created_event
                 ON created_event.supplier_id = run.supplier_id
                AND created_event.run_id = run.id
                AND created_event.id = ?
          LEFT JOIN payroll_run_events other_event
                 ON other_event.supplier_id = run.supplier_id
                AND other_event.run_id = run.id
                AND other_event.id <> created_event.id
          LEFT JOIN payroll_run_commands other_command
                 ON other_command.supplier_id = run.supplier_id
                AND other_command.run_id = run.id
                AND other_command.id <> command_receipt.id
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_generated_documents document
                 ON document.supplier_id = run.supplier_id
                AND document.run_id = run.id
          LEFT JOIN payroll_posting_batches posting
                 ON posting.supplier_id = run.supplier_id
                AND posting.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.id = ?
                AND run.status = "cancelled"
                AND run.row_version = ?
                AND run.row_version = 2
                AND run.current_revision_no = 0
                AND command_receipt.id = ?
                AND command_receipt.command_name = "cancel"
                AND command_receipt.revision_id IS NULL
                AND command_receipt.expected_row_version = 1
                AND command_receipt.from_status = "draft"
                AND command_receipt.to_status = "cancelled"
                AND command_receipt.actor_user_id <=> run.updated_by
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.run_id")
                ) = CAST(run.id AS CHAR)
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.from_status")
                ) = "draft"
                AND JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.to_status")
                ) = "cancelled"
                AND CAST(JSON_UNQUOTE(
                    JSON_EXTRACT(command_receipt.result_json, "$.row_version")
                ) AS UNSIGNED) = run.row_version
                AND created_event.event_type = "created"
                AND created_event.revision_id IS NULL
                AND created_event.from_status IS NULL
                AND created_event.to_status = "draft"
                AND created_event.reason IS NULL
                AND created_event.actor_user_id <=> run.created_by
                AND other_event.id IS NULL
                AND other_command.id IS NULL
                AND revision.id IS NULL
                AND document.id IS NULL
                AND posting.id IS NULL',
        );
        $stmt->execute([
            $createdEventId,
            $supplierId,
            $runId,
            $rowVersion,
            $cancelCommandId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function deleteInitialEvent(
        int $supplierId,
        int $runId,
        int $rowVersion,
        int $eventId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'DELETE event
               FROM payroll_run_events event
               JOIN payroll_runs run
                 ON run.supplier_id = event.supplier_id
                AND run.id = event.run_id
          LEFT JOIN payroll_run_events other_event
                 ON other_event.supplier_id = event.supplier_id
                AND other_event.run_id = event.run_id
                AND other_event.id <> event.id
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_run_commands command_receipt
                 ON command_receipt.supplier_id = run.supplier_id
                AND command_receipt.run_id = run.id
          LEFT JOIN payroll_generated_documents document
                 ON document.supplier_id = run.supplier_id
                AND document.run_id = run.id
          LEFT JOIN payroll_posting_batches posting
                 ON posting.supplier_id = run.supplier_id
                AND posting.run_id = run.id
              WHERE event.supplier_id = ?
                AND event.run_id = ?
                AND event.id = ?
                AND run.row_version = ?
                AND run.status IN ("draft", "cancelled")
                AND (
                    (run.status = "draft" AND run.row_version = 1)
                    OR (run.status = "cancelled" AND run.row_version = 2)
                )
                AND run.current_revision_no = 0
                AND event.event_type = "created"
                AND event.revision_id IS NULL
                AND event.from_status IS NULL
                AND event.to_status = "draft"
                AND event.reason IS NULL
                AND event.actor_user_id <=> run.created_by
                AND other_event.id IS NULL
                AND revision.id IS NULL
                AND command_receipt.id IS NULL
                AND document.id IS NULL
                AND posting.id IS NULL',
        );
        $stmt->execute([$supplierId, $runId, $eventId, $rowVersion]);

        return $stmt->rowCount() === 1;
    }

    public function transferOrReleasePeriodOwnership(
        int $supplierId,
        string $periodStart,
        int $runId,
        ?int $replacementRunId,
    ): void {
        if ($replacementRunId !== null) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE payroll_period_ownership
                    SET source_id = ?, updated_at = CURRENT_TIMESTAMP
                  WHERE supplier_id = ? AND period_start = ?
                    AND processor = "payroll"
                    AND source_type = "payroll_run"
                    AND source_id = ?',
            );
            $stmt->execute([
                $replacementRunId,
                $supplierId,
                $periodStart,
                $runId,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'DELETE FROM payroll_period_ownership
                  WHERE supplier_id = ? AND period_start = ?
                    AND processor = "payroll"
                    AND source_type = "payroll_run"
                    AND source_id = ?',
            );
            $stmt->execute([$supplierId, $periodStart, $runId]);
        }
        if ($stmt->rowCount() !== 1) {
            throw new PayrollRunDeletionException(
                'payroll_run_period_ownership_conflict',
                'Rezervace mzdového období se mezitím změnila.',
            );
        }
    }

    public function deleteEmptyRunRow(
        int $supplierId,
        int $runId,
        int $rowVersion,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'DELETE run
               FROM payroll_runs run
          LEFT JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_run_commands command_receipt
                 ON command_receipt.supplier_id = run.supplier_id
                AND command_receipt.run_id = run.id
          LEFT JOIN payroll_run_events event
                 ON event.supplier_id = run.supplier_id
                AND event.run_id = run.id
          LEFT JOIN payroll_generated_documents document
                 ON document.supplier_id = run.supplier_id
                AND document.run_id = run.id
          LEFT JOIN payroll_posting_batches posting
                 ON posting.supplier_id = run.supplier_id
                AND posting.run_id = run.id
              WHERE run.supplier_id = ?
                AND run.id = ?
                AND run.row_version = ?
                AND run.status IN ("draft", "cancelled")
                AND (
                    (run.status = "draft" AND run.row_version = 1)
                    OR (run.status = "cancelled" AND run.row_version = 2)
                )
                AND run.current_revision_no = 0
                AND revision.id IS NULL
                AND command_receipt.id IS NULL
                AND event.id IS NULL
                AND document.id IS NULL
                AND posting.id IS NULL',
        );
        $stmt->execute([$supplierId, $runId, $rowVersion]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function currentRevision(int $supplierId, int $runId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.*
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
              WHERE run.supplier_id = ? AND run.id = ?'
        );
        $stmt->execute([$supplierId, $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRevision($row);
    }

    /** @return array<string,mixed>|null */
    public function revision(int $supplierId, int $revisionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_revisions WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRevision($row);
    }

    /** @return array<string,mixed>|null */
    public function latestApprovedRevision(
        int $supplierId,
        int $runId,
        ?int $beforeRevisionNo = null,
    ): ?array
    {
        $beforeSql = $beforeRevisionNo === null ? '' : ' AND revision_no < ?';
        $params = [$supplierId, $runId];
        if ($beforeRevisionNo !== null) {
            $params[] = $beforeRevisionNo;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ? AND status = "approved"'
                . $beforeSql . '
              ORDER BY revision_no DESC
              LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castRevision($row);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, run_id, revision_no, previous_revision_id,
                    revision_kind, status, schema_version, ruleset_manifest_hash,
                    input_snapshot_hash, result_snapshot_hash, calculated_by,
                    reviewed_by, approved_by, calculated_at, reviewed_at, approved_at,
                    created_at,
                    (input_snapshot_json IS NOT NULL) AS has_input_snapshot,
                    (result_snapshot_json IS NOT NULL) AS has_result_snapshot
               FROM payroll_run_revisions
              WHERE supplier_id = ? AND run_id = ?
              ORDER BY revision_no'
        );
        $stmt->execute([$supplierId, $runId]);
        return array_values(array_map(
            self::castRevisionSummary(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * @return array{
     *   run_id:int,
     *   revisions:list<array<string,mixed>>,
     *   events:list<array<string,mixed>>
     * }|null
     */
    public function history(int $supplierId, int $runId): ?array
    {
        $runStmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_runs WHERE supplier_id = ? AND id = ?',
        );
        $runStmt->execute([$supplierId, $runId]);
        if ($runStmt->fetchColumn() === false) {
            return null;
        }

        $revisionStmt = $this->db->pdo()->prepare(
            'SELECT revision.id, revision.revision_no, revision.previous_revision_id,
                    revision.revision_kind, revision.status,
                    revision.ruleset_manifest_hash, revision.input_snapshot_hash,
                    revision.result_snapshot_hash, revision.calculated_at,
                    revision.reviewed_at, revision.approved_at, revision.created_at,
                    previous.id AS diff_parent_revision_id,
                    previous.input_snapshot_hash AS diff_parent_input_snapshot_hash,
                    previous.ruleset_manifest_hash AS diff_parent_ruleset_manifest_hash,
                    previous.result_snapshot_hash AS diff_parent_result_snapshot_hash,
                    JSON_VALUE(revision.result_snapshot_json,
                        "$.totals.cash_payable_minor") AS diff_cash_payable_after,
                    JSON_VALUE(previous.result_snapshot_json,
                        "$.totals.cash_payable_minor") AS diff_cash_payable_before,
                    JSON_VALUE(revision.result_snapshot_json,
                        "$.totals.enforcement_withheld_minor") AS diff_enforcement_withheld_after,
                    JSON_VALUE(previous.result_snapshot_json,
                        "$.totals.enforcement_withheld_minor") AS diff_enforcement_withheld_before,
                    JSON_VALUE(revision.result_snapshot_json,
                        "$.totals.payable_after_enforcement_minor") AS diff_payable_after_enforcement_after,
                    JSON_VALUE(previous.result_snapshot_json,
                        "$.totals.payable_after_enforcement_minor") AS diff_payable_after_enforcement_before
               FROM payroll_run_revisions revision
          LEFT JOIN payroll_run_revisions previous
                 ON previous.supplier_id = revision.supplier_id
                AND previous.run_id = revision.run_id
                AND previous.id = revision.previous_revision_id
              WHERE revision.supplier_id = ? AND revision.run_id = ?
              ORDER BY revision.revision_no',
        );
        $revisionStmt->execute([$supplierId, $runId]);

        return [
            'run_id' => $runId,
            'revisions' => array_values(array_map(
                self::castHistoryRevision(...),
                $revisionStmt->fetchAll(PDO::FETCH_ASSOC),
            )),
            'events' => $this->historyEvents($supplierId, $runId),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function events(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_events
              WHERE supplier_id = ? AND run_id = ?
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $runId]);
        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['run_id'] = (int) $row['run_id'];
                $row['revision_id'] = $row['revision_id'] === null
                    ? null
                    : (int) $row['revision_id'];
                $row['actor_user_id'] = $row['actor_user_id'] === null
                    ? null
                    : (int) $row['actor_user_id'];
                $row['metadata'] = json_decode(
                    (string) $row['metadata_json'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * Sloupce validace i s tím, kdo k ní schválil výjimku.
     *
     * Jméno schvalovatele se dotahuje JOINem: samotné `overridden_by` je číslo,
     * které na kartě běhu nikomu nic neřekne — a věta „schválil Jan Novák" je
     * celý smysl toho, že se schvalovatel eviduje.
     */
    private const VALIDATION_SELECT =
        'SELECT validation.*, actor.name AS overridden_by_name
           FROM payroll_run_validations validation
      LEFT JOIN users actor ON actor.id = validation.overridden_by';

    /** @return list<array<string,mixed>> */
    public function validations(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            self::VALIDATION_SELECT
            . ' WHERE validation.supplier_id = ? AND validation.revision_id = ?
                ORDER BY FIELD(validation.severity, "blocker", "warning", "info"),
                         validation.id'
        );
        $stmt->execute([$supplierId, $revisionId]);
        return array_values(array_map(
            self::castValidation(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed>|null */
    public function validation(int $supplierId, int $validationId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            self::VALIDATION_SELECT
            . ' WHERE validation.supplier_id = ? AND validation.id = ?'
        );
        $stmt->execute([$supplierId, $validationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castValidation($row);
    }

    /**
     * Validace i s během, ke kterému patří, pod zámkem.
     *
     * `payroll_run_validations` nese jen `revision_id`, takže příslušnost k běhu
     * z URL se musí ověřit přes revizi — jinak by šlo cizí validací hýbat přes
     * vlastní běh. `FOR UPDATE` drží řádek do konce transakce, aby dva souběžné
     * zápisy výjimky nepřepsaly jeden druhého.
     *
     * @return array<string,mixed>|null
     */
    public function lockValidation(int $supplierId, int $validationId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT validation.*, revision.run_id, revision.status AS revision_status
               FROM payroll_run_validations validation
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = validation.supplier_id
                AND revision.id = validation.revision_id
              WHERE validation.supplier_id = ? AND validation.id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $validationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['run_id'] = (int) $row['run_id'];
        return self::castValidation($row);
    }

    /**
     * Zapíše schválení výjimky.
     *
     * Podmínky v `WHERE` nejsou ozdoba: `requires_override = 1` zabrání tomu, aby
     * někdo „odklidil" blokující nález, a `overridden_at IS NULL` udělá ze zápisu
     * jednorázovou akci — druhý souběžný pokus ovlivní nula řádků a volající se
     * o tom dozví, místo aby tiše přepsal cizí odůvodnění.
     */
    public function applyValidationOverride(
        int $supplierId,
        int $validationId,
        string $reason,
        int $actorUserId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_validations
                SET override_reason = ?, overridden_by = ?, overridden_at = NOW()
              WHERE supplier_id = ? AND id = ?
                AND requires_override = 1
                AND overridden_at IS NULL'
        );
        $stmt->execute([$reason, $actorUserId, $supplierId, $validationId]);
        return $stmt->rowCount() === 1;
    }

    /** Odvolá schválenou výjimku; historii nese `payroll_run_events`. */
    public function clearValidationOverride(
        int $supplierId,
        int $validationId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_validations
                SET override_reason = NULL, overridden_by = NULL, overridden_at = NULL
              WHERE supplier_id = ? AND id = ?
                AND requires_override = 1
                AND overridden_at IS NOT NULL'
        );
        $stmt->execute([$supplierId, $validationId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castValidation(array $row): array
    {
        foreach (['id', 'revision_id'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['entity_id', 'overridden_by'] as $field) {
            $row[$field] = ($row[$field] ?? null) === null
                ? null
                : (int) $row[$field];
        }
        $row['requires_override'] = (bool) $row['requires_override'];
        return $row;
    }

    /** @return array{blockers:int,unresolved_overrides:int} */
    public function validationCounts(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                SUM(severity = "blocker") AS blockers,
                SUM(
                    severity = "warning"
                    AND requires_override = 1
                    AND overridden_at IS NULL
                ) AS unresolved_overrides
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?'
        );
        $stmt->execute([$supplierId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'blockers' => (int) ($row['blockers'] ?? 0),
            'unresolved_overrides' => (int) ($row['unresolved_overrides'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $result
     */
    public function replaceEnforcementValidations(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
                AND code = "enforcement_manual_review"'
        );
        $delete->execute([$supplierId, $revisionId]);
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "blocker", "enforcement_manual_review", "employee",
                     ?, ?, "/payroll/enforcement", 0)'
        );
        foreach ($result['people'] ?? [] as $person) {
            if (!is_array($person) || array_is_list($person)) {
                throw new \UnexpectedValueException('Výsledek osoby není platný.');
            }
            $enforcement = $person['enforcement'] ?? null;
            $enforcementResult = is_array($enforcement)
                ? ($enforcement['result'] ?? null)
                : null;
            if (!is_array($enforcementResult)
                || ($enforcementResult['status'] ?? null) !== 'manual_review'
            ) {
                continue;
            }
            $issues = $enforcementResult['issues'] ?? [];
            $message = PayrollRunValidationMessageFormatter::enforcement(
                is_array($issues)
                    ? array_values(array_filter(
                        $issues,
                        static fn (mixed $issue): bool => is_string($issue),
                    ))
                    : [],
            );
            $insert->execute([
                $supplierId,
                $revisionId,
                (int) ($person['employee_id'] ?? 0),
                mb_substr($message, 0, 500),
            ]);
        }
    }

    /** @param array<string,mixed> $result */
    public function replaceStatutoryValidations(
        int $supplierId,
        int $revisionId,
        array $result,
    ): void {
        $delete = $this->db->pdo()->prepare(
            'DELETE FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id = ?
                AND code = "statutory_calculation_manual_review"'
        );
        $delete->execute([$supplierId, $revisionId]);
        $statutory = $result['statutory'] ?? null;
        if (!is_array($statutory) || array_is_list($statutory)
            || ($statutory['status'] ?? null) === 'calculated'
        ) {
            return;
        }
        $issues = $statutory['issues'] ?? [];
        $message = PayrollRunValidationMessageFormatter::statutory(
            is_array($issues)
                ? array_values(array_filter(
                    $issues,
                    static fn (mixed $issue): bool => is_string($issue),
                ))
                : [],
        );
        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, "blocker", "statutory_calculation_manual_review",
                     "run", NULL, ?, "/payroll/runs", 0)'
        );
        $insert->execute([$supplierId, $revisionId, mb_substr($message, 0, 500)]);
    }

    /** @return array<string,mixed>|null */
    public function commandReceipt(int $supplierId, string $keyHashBinary): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_run_commands
              WHERE supplier_id = ? AND idempotency_key_hash = ?'
        );
        $stmt->execute([$supplierId, $keyHashBinary]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        foreach (['id', 'run_id', 'expected_row_version'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['revision_id'] = $row['revision_id'] === null
            ? null
            : (int) $row['revision_id'];
        $row['result'] = json_decode(
            (string) $row['result_json'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        return $row;
    }

    public function insertRevision(
        int $supplierId,
        int $runId,
        int $revisionNo,
        ?int $previousRevisionId,
        string $kind,
        PayrollRunInputSnapshot $snapshot,
        string $idempotencyKeyHash,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_revisions
                (supplier_id, run_id, revision_no, previous_revision_id,
                 revision_kind, status, schema_version, ruleset_manifest_hash,
                 input_snapshot_json, input_snapshot_hash, idempotency_key_hash)
             VALUES (?, ?, ?, ?, ?, "snapshot", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionNo,
            $previousRevisionId,
            $kind,
            (string) $snapshot->data['schema_version'],
            $snapshot->rulesetManifestHash,
            $snapshot->json,
            $snapshot->hash,
            $idempotencyKeyHash,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertSnapshotGraph(
        int $supplierId,
        int $revisionId,
        PayrollRunInputSnapshot $snapshot,
    ): void {
        $periodStart = $this->periodStartForRevision($supplierId, $revisionId);
        $personInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_persons
                (supplier_id, revision_id, period_start, employee_id)
             VALUES (?, ?, ?, ?)'
        );
        $employmentInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_employments
                (supplier_id, revision_id, period_start, employee_id, employment_id,
                 input_json, input_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshot->data['people'] as $person) {
            if (!is_array($person) || !is_array($person['employee'] ?? null)) {
                throw new \UnexpectedValueException('Snapshot osoby není platný.');
            }
            $employeeId = (int) $person['employee']['id'];
            $personInsert->execute([$supplierId, $revisionId, $periodStart, $employeeId]);
            foreach ($person['employments'] ?? [] as $employment) {
                if (!is_array($employment)
                    || !is_array($employment['employment'] ?? null)
                ) {
                    throw new \UnexpectedValueException('Snapshot vztahu není platný.');
                }
                $json = CanonicalJson::encode($employment);
                $employmentInsert->execute([
                    $supplierId,
                    $revisionId,
                    $periodStart,
                    $employeeId,
                    (int) $employment['employment']['id'],
                    $json,
                    hash('sha256', $json),
                ]);
            }
        }
        $validationInsert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_validations
                (supplier_id, revision_id, severity, code, entity_type,
                 entity_id, message, remediation_path, requires_override)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($snapshot->validations as $validation) {
            $validationInsert->execute([
                $supplierId,
                $revisionId,
                $validation->severity,
                $validation->code,
                $validation->entityType,
                $validation->entityId,
                $validation->message,
                $validation->remediationPath,
                $validation->requiresOverride ? 1 : 0,
            ]);
        }
    }

    private function periodStartForRevision(int $supplierId, int $revisionId): string
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ? AND revision.id = ?'
        );
        $statement->execute([$supplierId, $revisionId]);
        $periodStart = $statement->fetchColumn();
        if (!is_string($periodStart) || $periodStart === '') {
            throw new \DomainException('Revize mzdového běhu nemá platné období.');
        }

        return $periodStart;
    }

    public function lockApprovedInputs(
        int $supplierId,
        int $revisionId,
        string $periodStart,
        ?int $officeId,
    ): void {
        $officeSql = $officeId === null ? '1 = 1' : 'employment.office_id = ?';
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_inputs input
               JOIN payroll_employments employment
                 ON employment.supplier_id = input.supplier_id
                AND employment.id = input.employment_id
               JOIN payroll_run_employments frozen
                 ON frozen.supplier_id = input.supplier_id
                AND frozen.revision_id = ?
                AND frozen.employment_id = input.employment_id
                SET input.status = "locked",
                    input.row_version = input.row_version + 1
              WHERE input.supplier_id = ?
                AND input.period_start = ?
                AND input.status = "approved"
                AND ' . $officeSql
        );
        $stmt->execute([
            $revisionId,
            $supplierId,
            $periodStart,
            ...($officeId === null ? [] : [$officeId]),
        ]);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function saveCalculation(
        int $supplierId,
        int $revisionId,
        array $result,
        int $actorUserId,
    ): void {
        $json = CanonicalJson::encode($result);
        $hash = hash('sha256', $json);
        $updateRevision = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "calculated",
                    result_snapshot_json = ?,
                    result_snapshot_hash = ?,
                    calculated_by = ?,
                    calculated_at = NOW(),
                    reviewed_by = NULL,
                    reviewed_at = NULL
              WHERE supplier_id = ? AND id = ?
                AND status IN ("snapshot", "calculated", "reviewed")'
        );
        $updateRevision->execute([
            $json,
            $hash,
            $actorUserId,
            $supplierId,
            $revisionId,
        ]);
        if ($updateRevision->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze v aktuálním stavu přepočítat.');
        }

        $employmentUpdate = $this->db->pdo()->prepare(
            'UPDATE payroll_run_employments
                SET result_json = ?, result_hash = ?, status = "calculated"
              WHERE supplier_id = ? AND revision_id = ? AND employment_id = ?'
        );
        $personUpdate = $this->db->pdo()->prepare(
            'UPDATE payroll_run_persons
                SET result_json = ?, result_hash = ?, status = "calculated"
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?'
        );
        foreach ($result['people'] ?? [] as $person) {
            if (!is_array($person)) {
                throw new \UnexpectedValueException('Výsledek osoby není platný.');
            }
            foreach ($person['employments'] ?? [] as $employment) {
                if (!is_array($employment)) {
                    throw new \UnexpectedValueException('Výsledek vztahu není platný.');
                }
                $employmentJson = CanonicalJson::encode($employment);
                $employmentUpdate->execute([
                    $employmentJson,
                    hash('sha256', $employmentJson),
                    $supplierId,
                    $revisionId,
                    (int) $employment['employment_id'],
                ]);
                if ($employmentUpdate->rowCount() !== 1) {
                    throw new \RuntimeException('Výsledek vztahu se nepodařilo uložit.');
                }
            }
            $personJson = CanonicalJson::encode($person);
            $personUpdate->execute([
                $personJson,
                hash('sha256', $personJson),
                $supplierId,
                $revisionId,
                (int) $person['employee_id'],
            ]);
            if ($personUpdate->rowCount() !== 1) {
                throw new \RuntimeException('Výsledek osoby se nepodařilo uložit.');
            }
        }
    }

    public function markRevisionReviewed(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "reviewed", reviewed_by = ?, reviewed_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "calculated"'
        );
        $stmt->execute([$actorUserId, $supplierId, $revisionId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze označit za zkontrolovanou.');
        }
    }

    public function markRevisionApproved(
        int $supplierId,
        int $revisionId,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_run_revisions
                SET status = "approved", approved_by = ?, approved_at = NOW()
              WHERE supplier_id = ? AND id = ? AND status = "reviewed"'
        );
        $stmt->execute([$actorUserId, $supplierId, $revisionId]);
        if ($stmt->rowCount() !== 1) {
            throw new \DomainException('Revizi nelze schválit.');
        }
    }

    /** @return array<string,mixed> */
    public function updateRun(
        int $supplierId,
        int $runId,
        int $expectedVersion,
        string $status,
        ?int $currentRevisionNo,
        int $actorUserId,
    ): array {
        $revisionSql = $currentRevisionNo === null
            ? ''
            : ', current_revision_no = ?';
        $params = [$status];
        if ($currentRevisionNo !== null) {
            $params[] = $currentRevisionNo;
        }
        $params = [
            ...$params,
            $actorUserId,
            $supplierId,
            $runId,
            $expectedVersion,
        ];
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_runs
                SET status = ?' . $revisionSql . ',
                    updated_by = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            $current = $this->find($supplierId, $runId);
            throw new PayrollRunConflictException(
                $current === null ? 0 : (int) $current['row_version'],
            );
        }
        return $this->find($supplierId, $runId)
            ?? throw new \RuntimeException('Aktualizovaný mzdový běh nebyl nalezen.');
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function insertEvent(
        int $supplierId,
        int $runId,
        ?int $revisionId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $actorUserId,
        ?string $reason,
        array $metadata,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_events
                (supplier_id, run_id, revision_id, event_type, from_status,
                 to_status, actor_user_id, reason, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionId,
            $eventType,
            $fromStatus,
            $toStatus,
            $actorUserId,
            $reason,
            CanonicalJson::encode($metadata),
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $result
     */
    public function insertCommandReceipt(
        int $supplierId,
        int $runId,
        ?int $revisionId,
        string $commandName,
        string $keyHashBinary,
        string $requestHash,
        int $expectedVersion,
        string $fromStatus,
        string $toStatus,
        array $result,
        int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_run_commands
                (supplier_id, run_id, revision_id, command_name,
                 idempotency_key_hash, request_hash, expected_row_version,
                 from_status, to_status, result_json, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $revisionId,
            $commandName,
            $keyHashBinary,
            $requestHash,
            $expectedVersion,
            $fromStatus,
            $toStatus,
            CanonicalJson::encode($result),
            $actorUserId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRun(array $row): array
    {
        foreach ([
            'id',
            'supplier_id',
            'office_scope_id',
            'current_revision_no',
            'row_version',
        ] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach (['office_id', 'created_by', 'updated_by'] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function castRevision(array $row): array
    {
        foreach (['id', 'supplier_id', 'run_id', 'revision_no'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach ([
            'previous_revision_id',
            'calculated_by',
            'reviewed_by',
            'approved_by',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        foreach (['input_snapshot_json', 'result_snapshot_json'] as $field) {
            $row[str_replace('_json', '', $field)] = $row[$field] === null
                ? null
                : json_decode(
                    (string) $row[$field],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
        }
        unset($row['idempotency_key_hash']);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function castRevisionSummary(array $row): array
    {
        foreach (['id', 'supplier_id', 'run_id', 'revision_no'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        foreach ([
            'previous_revision_id',
            'calculated_by',
            'reviewed_by',
            'approved_by',
        ] as $field) {
            $row[$field] = $row[$field] === null ? null : (int) $row[$field];
        }
        foreach (['has_input_snapshot', 'has_result_snapshot'] as $field) {
            $row[$field] = (bool) (int) $row[$field];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,
     *   revision_no:int,
     *   previous_revision_id:?int,
     *   revision_kind:string,
     *   status:string,
     *   created_at:string,
     *   calculated_at:?string,
     *   reviewed_at:?string,
     *   approved_at:?string,
     *   ruleset_manifest_hash:string,
     *   input_snapshot_hash:string,
     *   result_snapshot_hash:?string,
     *   totals:array<string,?int>,
     *   diff_from_previous:?array<string,mixed>
     * }
     */
    private static function castHistoryRevision(array $row): array
    {
        $diff = self::historyDiff($row);

        return [
            'id' => (int) $row['id'],
            'revision_no' => (int) $row['revision_no'],
            'previous_revision_id' => $row['previous_revision_id'] === null
                ? null
                : (int) $row['previous_revision_id'],
            'revision_kind' => (string) $row['revision_kind'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'calculated_at' => $row['calculated_at'],
            'reviewed_at' => $row['reviewed_at'],
            'approved_at' => $row['approved_at'],
            'ruleset_manifest_hash' => (string) $row['ruleset_manifest_hash'],
            'input_snapshot_hash' => (string) $row['input_snapshot_hash'],
            'result_snapshot_hash' => $row['result_snapshot_hash'],
            'totals' => [
                'cash_payable_minor' => self::nullableMinor(
                    $row['diff_cash_payable_after'],
                ),
                'enforcement_withheld_minor' => self::nullableMinor(
                    $row['diff_enforcement_withheld_after'],
                ),
                'payable_after_enforcement_minor' => self::nullableMinor(
                    $row['diff_payable_after_enforcement_after'],
                ),
            ],
            'diff_from_previous' => $diff,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    private static function historyDiff(array $row): ?array
    {
        if ($row['diff_parent_revision_id'] === null) {
            return null;
        }

        return [
            'input_changed' =>
                $row['input_snapshot_hash'] !== $row['diff_parent_input_snapshot_hash'],
            'ruleset_changed' =>
                $row['ruleset_manifest_hash'] !== $row['diff_parent_ruleset_manifest_hash'],
            'result_changed' =>
                $row['result_snapshot_hash'] !== $row['diff_parent_result_snapshot_hash'],
            'totals' => [
                'cash_payable_minor' => self::historyTotal(
                    $row['diff_cash_payable_before'],
                    $row['diff_cash_payable_after'],
                ),
                'enforcement_withheld_minor' => self::historyTotal(
                    $row['diff_enforcement_withheld_before'],
                    $row['diff_enforcement_withheld_after'],
                ),
                'payable_after_enforcement_minor' => self::historyTotal(
                    $row['diff_payable_after_enforcement_before'],
                    $row['diff_payable_after_enforcement_after'],
                ),
            ],
        ];
    }

    /** @return array{before:?int,after:?int,delta:?int} */
    private static function historyTotal(mixed $before, mixed $after): array
    {
        $before = self::nullableMinor($before);
        $after = self::nullableMinor($after);

        return [
            'before' => $before,
            'after' => $after,
            'delta' => $before === null || $after === null ? null : $after - $before,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function historyEvents(int $supplierId, int $runId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT event.id, event.revision_id, event.event_type, event.from_status,
                    event.to_status, event.reason, actor.name AS actor_name,
                    event.created_at
               FROM payroll_run_events event
          LEFT JOIN users actor ON actor.id = event.actor_user_id
              WHERE event.supplier_id = ? AND event.run_id = ?
              ORDER BY event.id',
        );
        $stmt->execute([$supplierId, $runId]);

        return array_values(array_map(
            static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['revision_id'] = $row['revision_id'] === null
                    ? null
                    : (int) $row['revision_id'];

                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    private static function supportsPaymentMaterialization(mixed $snapshot): bool
    {
        if (!is_array($snapshot) || array_is_list($snapshot)
            || ($snapshot['schema_version'] ?? null) !== self::INPUT_SCHEMA_WITH_PAYOUTS
        ) {
            return false;
        }
        $people = $snapshot['people'] ?? null;
        if (!is_array($people) || !array_is_list($people)) {
            return false;
        }
        foreach ($people as $person) {
            if (!is_array($person) || array_is_list($person)
                || !array_key_exists('payout_accounts', $person)
                || !is_array($person['payout_accounts'])
                || !array_is_list($person['payout_accounts'])
            ) {
                return false;
            }
        }

        return true;
    }
}
