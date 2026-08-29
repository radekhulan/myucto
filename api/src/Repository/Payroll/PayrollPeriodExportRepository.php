<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPeriodExportRepository
{
    private int $savepointSequence = 0;

    public function __construct(private readonly Connection $db) {}

    /**
     * @return array{
     *   data:array<string,mixed>,
     *   documents:list<array<string,mixed>>,
     *   artifacts:list<array<string,mixed>>,
     *   protocols:list<array<string,mixed>>
     * }
     */
    public function source(
        int $supplierId,
        string $scope,
        string $periodStart,
        string $periodEnd,
    ): array {
        $revisions = $this->rows(
            'SELECT revision.id, revision.run_id, run.office_id,
                    run.period_start, run.payment_date,
                    revision.revision_no, revision.revision_kind,
                    revision.status, revision.schema_version,
                    revision.ruleset_manifest_hash,
                    revision.input_snapshot_json,
                    revision.input_snapshot_hash,
                    revision.result_snapshot_json,
                    revision.result_snapshot_hash,
                    revision.approved_at
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND run.period_start BETWEEN ? AND ?
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL
              ORDER BY run.period_start, run.office_scope_id,
                       revision.revision_no, revision.id',
            [$supplierId, $periodStart, $periodEnd],
        );
        if ($revisions === []) {
            throw new \DomainException(
                'Ve zvoleném období není žádná schválená mzdová revize.',
            );
        }
        foreach ($revisions as &$revision) {
            $revision = $this->decodeVerifiedJsonFields(
                $revision,
                [
                    'input_snapshot_json' => 'input_snapshot_hash',
                    'result_snapshot_json' => 'result_snapshot_hash',
                ],
            );
        }
        unset($revision);
        $revisionIds = array_map(
            fn (array $revision): int => $this->integer($revision, 'id'),
            $revisions,
        );

        $people = $this->revisionRows(
            'SELECT id, revision_id, employee_id, status,
                    result_json, result_hash
               FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, employee_id, id',
            $supplierId,
            $revisionIds,
            ['result_json' => 'result_hash'],
        );
        $employments = $this->revisionRows(
            'SELECT id, revision_id, employee_id, employment_id, status,
                    input_json, input_hash, result_json, result_hash
               FROM payroll_run_employments
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, employee_id, employment_id, id',
            $supplierId,
            $revisionIds,
            [
                'input_json' => 'input_hash',
                'result_json' => 'result_hash',
            ],
        );
        $statutory = $this->revisionRows(
            'SELECT id, revision_id, calculation_kind, schema_version,
                    result_status, ruleset_id, ruleset_hash,
                    input_snapshot_json, input_snapshot_hash,
                    result_snapshot_json, result_snapshot_hash,
                    result_set_hash, created_at
               FROM payroll_statutory_results
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, calculation_kind, id',
            $supplierId,
            $revisionIds,
            [
                'input_snapshot_json' => 'input_snapshot_hash',
                'result_snapshot_json' => 'result_snapshot_hash',
            ],
        );
        $statutoryPeople = $this->revisionRows(
            'SELECT id, statutory_result_id, revision_id,
                    calculation_kind, employee_id, result_status,
                    input_snapshot_json, input_snapshot_hash,
                    result_snapshot_json, result_snapshot_hash,
                    created_at
               FROM payroll_statutory_person_results
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, calculation_kind, employee_id, id',
            $supplierId,
            $revisionIds,
            [
                'input_snapshot_json' => 'input_snapshot_hash',
                'result_snapshot_json' => 'result_snapshot_hash',
            ],
        );
        $statutoryEmployments = $this->revisionRows(
            'SELECT id, statutory_result_id, person_result_id, revision_id,
                    calculation_kind, employee_id, employment_id,
                    result_status, input_snapshot_json, input_snapshot_hash,
                    result_snapshot_json, result_snapshot_hash, created_at
               FROM payroll_statutory_relationship_results
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, calculation_kind, employee_id,
                       employment_id, id',
            $supplierId,
            $revisionIds,
            [
                'input_snapshot_json' => 'input_snapshot_hash',
                'result_snapshot_json' => 'result_snapshot_hash',
            ],
        );
        $netResults = $this->revisionRows(
            'SELECT id, revision_id, employee_id, cash_income_minor,
                    non_cash_income_minor, employee_social_minor,
                    employee_health_minor, advance_tax_minor,
                    withholding_tax_minor, tax_bonus_minor,
                    correction_minor, deducted_minor, net_payable_minor,
                    result_json, result_hash, created_at
               FROM payroll_net_results
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, employee_id, id',
            $supplierId,
            $revisionIds,
            ['result_json' => 'result_hash'],
        );
        $allocations = $this->revisionRows(
            'SELECT id, revision_id, employee_id, net_result_id,
                    allocation_reference, destination_kind,
                    destination_reference, allocation_kind, amount_minor,
                    allocation_order, created_at
               FROM payroll_payout_allocations
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, employee_id, allocation_order, id',
            $supplierId,
            $revisionIds,
        );
        $validations = $this->revisionRows(
            'SELECT id, revision_id, severity, code, entity_type, entity_id,
                    message, remediation_path, requires_override,
                    override_reason, overridden_at, created_at
               FROM payroll_run_validations
              WHERE supplier_id = ? AND revision_id IN (%s)
              ORDER BY revision_id, id',
            $supplierId,
            $revisionIds,
        );

        $annualRevisions = [];
        $annualSources = [];
        $annualIds = [];
        if ($scope === 'annual') {
            $year = (int) substr($periodStart, 0, 4);
            $annualRevisions = $this->rows(
                'SELECT annual.id, annual.employee_id, annual.tax_year,
                        annual.purpose, annual.revision_no,
                        annual.previous_revision_id,
                        annual.snapshot_ciphertext, annual.snapshot_hash,
                        annual.source_manifest_json,
                        annual.source_manifest_hash, annual.approved_at,
                        annual.created_at
                   FROM payroll_annual_document_revisions annual
                  WHERE annual.supplier_id = ? AND annual.tax_year = ?
                    AND NOT EXISTS (
                        SELECT 1
                          FROM payroll_annual_document_revisions newer
                         WHERE newer.supplier_id = annual.supplier_id
                           AND newer.employee_id = annual.employee_id
                           AND newer.tax_year = annual.tax_year
                           AND newer.purpose = annual.purpose
                           AND newer.revision_no > annual.revision_no
                    )
                  ORDER BY annual.employee_id, annual.purpose, annual.id',
                [$supplierId, $year],
            );
            foreach ($annualRevisions as &$annualRevision) {
                $annualRevision = $this->decodeVerifiedJsonFields(
                    $annualRevision,
                    ['source_manifest_json' => 'source_manifest_hash'],
                );
            }
            unset($annualRevision);
            $annualIds = array_map(
                fn (array $revision): int => $this->integer($revision, 'id'),
                $annualRevisions,
            );
            if ($annualIds !== []) {
                $annualSources = $this->idRows(
                    'SELECT id, annual_revision_id, run_revision_id,
                            employee_id, period_start, person_result_hash,
                            created_at
                       FROM payroll_annual_document_sources
                      WHERE supplier_id = ? AND annual_revision_id IN (%s)
                      ORDER BY annual_revision_id, period_start,
                               run_revision_id, id',
                    $supplierId,
                    $annualIds,
                );
            }
        }

        $documents = $this->documents(
            $supplierId,
            $scope,
            $revisionIds,
            $annualIds,
        );
        $submissionRows = $this->rows(
            'SELECT submission.id
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ?
                AND obligation.period_start <= ?
                AND obligation.period_end >= ?
              ORDER BY submission.environment, obligation.period_start,
                       obligation.agenda_code, submission.id',
            [$supplierId, $periodEnd, $periodStart],
        );
        $submissionIds = array_map(
            fn (array $submission): int => $this->integer(
                $submission,
                'id',
            ),
            $submissionRows,
        );
        $artifacts = [];
        $receipts = [];
        if ($submissionIds !== []) {
            $artifacts = $this->idRows(
                'SELECT artifact.id, artifact.submission_id,
                        artifact.environment, artifact.part_id,
                        artifact.artifact_kind, artifact.direction,
                        artifact.mime_type, artifact.byte_size,
                        artifact.artifact_sha256, artifact.xsd_version,
                        artifact.catalog_version, artifact.channel,
                        artifact.created_at
                   FROM payroll_submission_artifacts artifact
                  WHERE artifact.supplier_id = ?
                    AND artifact.submission_id IN (%s)
                    AND artifact.artifact_kind IN (
                      "outbound_xml", "outbound_pdf", "outbound_zip",
                      "validation_protocol", "receipt_original",
                      "receipt_parsed"
                    )
                  ORDER BY artifact.environment,
                           artifact.submission_id, artifact.id',
                $supplierId,
                $submissionIds,
            );
            $receipts = $this->idRows(
                'SELECT id, environment, submission_id, part_id,
                        artifact_id, receipt_reference,
                        correlation_reference, protocol_code,
                        remote_status, verification_status, summary_hash,
                        request_fingerprint, received_at, created_at
                   FROM payroll_submission_receipts
                  WHERE supplier_id = ? AND submission_id IN (%s)
                  ORDER BY environment, submission_id, received_at, id',
                $supplierId,
                $submissionIds,
            );
        }
        $protocols = $this->rows(
            'SELECT id, environment, protocol_kind, variable_symbol,
                    period_month, period_year, submission_guid,
                    correlation_reference, payload_sha256, payload_xml
               FROM payroll_imported_jmhz_protocols
              WHERE supplier_id = ?
                AND period_year = ?
                AND (? = "annual" OR period_month = ?)
              ORDER BY environment, period_year, period_month, id',
            [
                $supplierId,
                (int) substr($periodStart, 0, 4),
                $scope,
                (int) substr($periodStart, 5, 2),
            ],
        );

        return [
            'data' => [
                'scope' => $scope,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'revisions' => $revisions,
                'people' => $people,
                'employments' => $employments,
                'statutory_results' => $statutory,
                'statutory_person_results' => $statutoryPeople,
                'statutory_employment_results' => $statutoryEmployments,
                'net_results' => $netResults,
                'payout_allocations' => $allocations,
                'validations' => $validations,
                'annual_revisions' => $annualRevisions,
                'annual_sources' => $annualSources,
                'submission_receipts' => $receipts,
                'omissions' => [
                    [
                        'category' => 'manual_submission_attachments',
                        'reason' => 'Libovolné ruční přílohy nejsou bezpečný automatický obsah mzdového exportu.',
                    ],
                    [
                        'category' => 'credentials_and_keys',
                        'reason' => 'Přihlašovací údaje, certifikáty a soukromé klíče se nikdy neexportují.',
                    ],
                    [
                        'category' => 'mutable_personnel_master_data',
                        'reason' => 'Export období vychází ze schválených snapshotů, nikoli z dnešní editovatelné karty zaměstnance.',
                    ],
                    [
                        'category' => 'undated_unmatched_protocols',
                        'reason' => 'Protokol bez doloženého období se automaticky nepřiřazuje odhadem.',
                    ],
                    [
                        'category' => 'mutable_submission_workflow_state',
                        'reason' => 'Proměnlivý provozní stav podání není účetním zdrojem; export obsahuje jeho neměnné artefakty a doručenky.',
                    ],
                ],
            ],
            'documents' => $documents,
            'artifacts' => $artifacts,
            'protocols' => $protocols,
        ];
    }

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,source_manifest_hash:string,manifest_json:string,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string,created_at:string}|null */
    public function find(int $supplierId, int $exportId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, export_scope, period_start, period_end,
                    source_manifest_hash, manifest_json, file_sha256,
                    size_bytes, mime_type, storage_key,
                    suggested_filename, created_at
               FROM payroll_period_exports
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $exportId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->exportRow($row);
    }

    /**
     * @param array{supplier_id:int,export_scope:string,period_start:string,period_end:string,source_manifest_hash:string,manifest_json:string,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string,created_by:?int} $record
     * @return array{id:int,export_scope:string,period_start:string,period_end:string,source_manifest_hash:string,manifest_json:string,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string,created_at:string}
     */
    public function insertOrGet(array $record): array
    {
        $existing = $this->findBySource(
            $record['supplier_id'],
            $record['export_scope'],
            $record['period_start'],
            $record['period_end'],
            $record['source_manifest_hash'],
        );
        if ($existing !== null) {
            return $existing;
        }
        try {
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_period_exports
                    (supplier_id, export_scope, period_start, period_end,
                     source_manifest_hash, manifest_json, file_sha256,
                     size_bytes, mime_type, storage_key,
                     suggested_filename, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $record['supplier_id'],
                $record['export_scope'],
                $record['period_start'],
                $record['period_end'],
                $record['source_manifest_hash'],
                $record['manifest_json'],
                $record['file_sha256'],
                $record['size_bytes'],
                $record['mime_type'],
                $record['storage_key'],
                $record['suggested_filename'],
                $record['created_by'],
            ]);
        } catch (\PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
        $result = $this->findBySource(
            $record['supplier_id'],
            $record['export_scope'],
            $record['period_start'],
            $record['period_end'],
            $record['source_manifest_hash'],
        );
        if ($result === null) {
            throw new \RuntimeException(
                'Export mezd se nepodařilo bezpečně archivovat.',
            );
        }
        $this->revokeSupersededGrants(
            (int) $record['supplier_id'],
            (string) $record['export_scope'],
            (string) $record['period_start'],
            (string) $record['period_end'],
            (int) $result['id'],
        );

        return $result;
    }

    /**
     * Zneplatní nepoužité download granty exportů, které nová revize téhož
     * období nahradila (W30 / D-06).
     *
     * Nový export za stejné období a rozsah vzniká proto, že se zdrojová data
     * změnila — mzdový běh dostal novou revizi, přibyl dokument, opravilo se
     * podání. Nevyzvednutý grant na PŘEDCHOZÍ export je od té chvíle platný
     * odkaz na archiv, který už neodpovídá stavu evidence, a nikdo ho
     * nezneplatňoval. Řádek se maže, ne jen expiruje: nevyzvednutý grant nemá
     * co doložit (stažení se loguje až při vyzvednutí) a CHECK
     * `expires_at > created_at` by přepis času neustál.
     *
     * Použité granty zůstávají — ty jsou stopou, že se soubor stáhl.
     */
    private function revokeSupersededGrants(
        int $supplierId,
        string $scope,
        string $periodStart,
        string $periodEnd,
        int $currentExportId,
    ): void {
        $this->db->pdo()->prepare(
            'DELETE grant_row
               FROM payroll_period_export_download_grants grant_row
               JOIN payroll_period_exports export_row
                 ON export_row.supplier_id = grant_row.supplier_id
                AND export_row.id = grant_row.export_id
              WHERE grant_row.supplier_id = ?
                AND grant_row.export_id <> ?
                AND grant_row.used_at IS NULL
                AND export_row.export_scope = ?
                AND export_row.period_start = ?
                AND export_row.period_end = ?',
        )->execute([
            $supplierId,
            $currentExportId,
            $scope,
            $periodStart,
            $periodEnd,
        ]);
    }

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,source_manifest_hash:string,manifest_json:string,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string,created_at:string}|null */
    public function findBySource(
        int $supplierId,
        string $scope,
        string $periodStart,
        string $periodEnd,
        string $sourceManifestHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, export_scope, period_start, period_end,
                    source_manifest_hash, manifest_json, file_sha256,
                    size_bytes, mime_type, storage_key,
                    suggested_filename, created_at
               FROM payroll_period_exports
              WHERE supplier_id = ? AND export_scope = ?
                AND period_start = ? AND period_end = ?
                AND source_manifest_hash = ?',
        );
        $statement->execute([
            $supplierId,
            $scope,
            $periodStart,
            $periodEnd,
            $sourceManifestHash,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->exportRow($row);
    }

    public function currentUtcDateTime(): \DateTimeImmutable
    {
        $statement = $this->db->pdo()->query('SELECT UTC_TIMESTAMP(6)');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Databázový čas exportu není dostupný.');
        }
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Databázový čas exportu není dostupný.');
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public function insertGrant(
        int $supplierId,
        int $exportId,
        int $userId,
        string $tokenHash,
        string $createdAt,
        string $expiresAt,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_period_export_download_grants
                (supplier_id, export_id, user_id, token_hash,
                 created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $exportId,
            $userId,
            $tokenHash,
            $createdAt,
            $expiresAt,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{grant_id:int,expires_at:string,used_at:?string,export_id:int,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string}|null */
    public function grantMetadata(
        int $supplierId,
        int $userId,
        string $tokenHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT grant_row.id AS grant_id, grant_row.expires_at,
                    grant_row.used_at, export_row.id AS export_id,
                    export_row.file_sha256, export_row.size_bytes,
                    export_row.mime_type, export_row.storage_key,
                    export_row.suggested_filename
               FROM payroll_period_export_download_grants grant_row
               JOIN payroll_period_exports export_row
                 ON export_row.supplier_id = grant_row.supplier_id
                AND export_row.id = grant_row.export_id
              WHERE grant_row.supplier_id = ?
                AND grant_row.user_id = ?
                AND grant_row.token_hash = ?',
        );
        $statement->execute([$supplierId, $userId, $tokenHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        $row = $this->associative($row);

        return [
            'grant_id' => $this->integer($row, 'grant_id'),
            'expires_at' => $this->string($row, 'expires_at'),
            'used_at' => $this->nullableString($row, 'used_at'),
            'export_id' => $this->integer($row, 'export_id'),
            'file_sha256' => $this->string($row, 'file_sha256'),
            'size_bytes' => $this->integer($row, 'size_bytes'),
            'mime_type' => $this->string($row, 'mime_type'),
            'storage_key' => $this->string($row, 'storage_key'),
            'suggested_filename' => $this->string(
                $row,
                'suggested_filename',
            ),
        ];
    }

    public function consumeGrant(
        int $grantId,
        string $tokenHash,
        string $usedAt,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_period_export_download_grants
                SET used_at = ?
              WHERE id = ? AND token_hash = ?
                AND used_at IS NULL AND expires_at >= ?',
        );
        $statement->execute([$usedAt, $grantId, $tokenHash, $usedAt]);

        return $statement->rowCount() === 1;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_period_export_'
                . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    /**
     * @param list<int> $revisionIds
     * @param list<int> $annualRevisionIds
     * @return list<array<string,mixed>>
     */
    private function documents(
        int $supplierId,
        string $scope,
        array $revisionIds,
        array $annualRevisionIds,
    ): array {
        $placeholders = implode(',', array_fill(0, count($revisionIds), '?'));
        $annualClause = $scope === 'annual' && $annualRevisionIds !== []
            ? ' OR document.annual_revision_id IN ('
                . implode(',', array_fill(0, count($annualRevisionIds), '?'))
                . ')'
            : '';
        $parameters = [$supplierId, ...$revisionIds];
        if ($annualClause !== '') {
            $parameters = [...$parameters, ...$annualRevisionIds];
        }
        return $this->rows(
            'SELECT document.id, document.run_id, document.revision_id,
                    document.annual_revision_id,
                    document.employment_exit_revision_id,
                    document.employee_id, document.document_kind,
                    document.document_revision_no,
                    document.supersedes_document_id,
                    document.revision_snapshot_hash,
                    document.source_snapshot_hash,
                    document.template_version, document.renderer_version,
                    document.file_sha256, document.size_bytes,
                    document.mime_type, document.storage_key,
                    document.created_at
               FROM payroll_generated_documents document
              WHERE document.supplier_id = ?
                AND document.document_kind <> "monthly_bundle"
                AND (document.revision_id IN (' . $placeholders . ')' . $annualClause . ')
              ORDER BY document.id',
            $parameters,
        );
    }

    /**
     * @param list<int> $revisionIds
     * @param array<string,string> $jsonFields
     * @return list<array<string,mixed>>
     */
    private function revisionRows(
        string $sql,
        int $supplierId,
        array $revisionIds,
        array $jsonFields = [],
    ): array {
        return $this->idRows(
            $sql,
            $supplierId,
            $revisionIds,
            $jsonFields,
        );
    }

    /**
     * @param list<int> $ids
     * @param array<string,string> $jsonFields
     * @return list<array<string,mixed>>
     */
    private function idRows(
        string $sql,
        int $supplierId,
        array $ids,
        array $jsonFields = [],
    ): array {
        if ($ids === []) {
            return [];
        }
        $rows = $this->rows(
            sprintf($sql, implode(',', array_fill(0, count($ids), '?'))),
            [$supplierId, ...$ids],
        );
        if ($jsonFields !== []) {
            foreach ($rows as &$row) {
                $row = $this->decodeVerifiedJsonFields($row, $jsonFields);
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * @param list<mixed> $parameters
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->associative($row);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function decodeVerifiedJsonFields(
        array $row,
        array $fields,
    ): array
    {
        foreach ($fields as $field => $hashField) {
            $value = $row[$field] ?? null;
            $expectedHash = $row[$hashField] ?? null;
            if ($value === null) {
                if ($expectedHash !== null) {
                    throw new \UnexpectedValueException(
                        'Snapshot exportu mezd nemá platný otisk.',
                    );
                }
                $row[$field] = null;
                continue;
            }
            if (!is_string($value)
                || !is_string($expectedHash)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedHash) !== 1
                || !hash_equals($expectedHash, hash('sha256', $value))
            ) {
                throw new \UnexpectedValueException(
                    'Snapshot exportu mezd neodpovídá uloženému otisku.',
                );
            }
            $row[$field] = json_decode(
                $value,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function associative(mixed $row): array
    {
        if (!is_array($row) || array_is_list($row)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek exportu mezd.',
            );
        }
        $result = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Řádek exportu mezd nemá textové klíče.',
                );
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** @return array{id:int,export_scope:string,period_start:string,period_end:string,source_manifest_hash:string,manifest_json:string,file_sha256:string,size_bytes:int,mime_type:string,storage_key:string,suggested_filename:string,created_at:string} */
    private function exportRow(mixed $value): array
    {
        $row = $this->associative($value);

        return [
            'id' => $this->integer($row, 'id'),
            'export_scope' => $this->string($row, 'export_scope'),
            'period_start' => $this->string($row, 'period_start'),
            'period_end' => $this->string($row, 'period_end'),
            'source_manifest_hash' => $this->string(
                $row,
                'source_manifest_hash',
            ),
            'manifest_json' => $this->string($row, 'manifest_json'),
            'file_sha256' => $this->string($row, 'file_sha256'),
            'size_bytes' => $this->integer($row, 'size_bytes'),
            'mime_type' => $this->string($row, 'mime_type'),
            'storage_key' => $this->string($row, 'storage_key'),
            'suggested_filename' => $this->string(
                $row,
                'suggested_filename',
            ),
            'created_at' => $this->string($row, 'created_at'),
        ];
    }

    /** @param array<string,mixed> $row */
    private function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $integer;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není text.",
            );
        }

        return $value === '' ? null : $value;
    }
}
