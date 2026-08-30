<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

final class PayrollDocumentRepository
{
    /**
     * Tvrdý strop seznamu dokumentů. Za měsíc vzniká výplatní páska na každý
     * pracovní poměr plus balíček, za rok mzdový list na každého zaměstnance —
     * seznam tedy roste s počtem lidí i s počtem období a bez stropu by u
     * větší firmy načítal celou archivní historii najednou.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    public function __construct(private readonly Connection $db) {}

    /**
     * Revize, ze které se smí VYSTAVIT nový dokument.
     *
     * Musí to být AKTUÁLNÍ schválená revize běhu, ne jen „nějaká schválená".
     * Dokud stačilo `status IN ("approved","superseded")`, existovaly po
     * opravné revizi dvě schválené a dávka mohla vystavit předkorekční
     * výplatní pásku, přestože účetnictví i JMHZ už jely z nové revize.
     *
     * Podmínka je dvojitá schválně: `status = "approved"` funguje díky tomu,
     * že schválení opravné revize předchozí odsune, a `NOT EXISTS` novější
     * schválené revize drží i pro běhy z doby, kdy se ještě neodsouvalo.
     *
     * Už vydané dokumenty tím nezanikají — čtou se z archivu vygenerovaných
     * dokumentů přes vlastní `revision_id`, ne přes tuhle bránu.
     *
     * @return array<string,mixed>|null
     */
    public function approvedRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.*, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.run_id = ?
                AND revision.id = ?
                AND revision.status = "approved"
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status = "approved"
                       AND newer.result_snapshot_hash IS NOT NULL
                )'
        );
        $stmt->execute([$supplierId, $runId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Revize, ze které vznikl JIŽ ARCHIVOVANÝ doklad — tedy i taková, kterou
     * mezitím přebila novější oprava.
     *
     * Záměrně to NENÍ {@see approvedRevision()}: ta pouští jen poslední
     * schválenou revizi, což je správné pro rozhodnutí „smí se z tohohle
     * vydat doklad", ale nepoužitelné pro předchůdce v řetězu oprav — ten
     * novější revizi má vždycky, takže by dotaz nevrátil nic a přegenerování
     * pásky po opravě mzdy by skončilo hláškou o neschválené revizi.
     * Tady jde výhradně o zjištění pořadí, ne o oprávnění.
     *
     * @return array<string,mixed>|null
     */
    public function archivedDocumentRevision(
        int $supplierId,
        int $runId,
        int $revisionId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT revision.*, run.period_start
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.run_id = ?
                AND revision.id = ?
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL'
        );
        $stmt->execute([$supplierId, $runId, $revisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function approvedAnnualRevision(
        int $supplierId,
        int $annualRevisionId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_annual_document_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $annualRevisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findByIdempotency(int $supplierId, string $keyHash): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND idempotency_key_hash = UNHEX(?)'
        );
        $stmt->execute([$supplierId, $keyHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Zúžení na jednu osobu (`$employeeId`) padá do TÉHOŽ dotazu jako stránkování.
     * Dokud filtroval prohlížeč nad načtenou stránkou, dokument z jiné strany se
     * tiše neprojevil. Hromadný balík měsíce (`monthly_bundle`) nemá osobu, takže
     * ze zúženého seznamu vypadne — patří běhu, ne konkrétnímu člověku.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listForPeriod(
        int $supplierId,
        string $periodStart,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employeeId = null,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici: repozitář volá
        // i jiný kód než akce a „nekonečný" seznam nesmí jít objednat nikudy.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        if ($employeeId !== null && $employeeId <= 0) {
            throw new \InvalidArgumentException('Osoba musí být kladné číslo.');
        }

        $from = ' FROM payroll_generated_documents document
               JOIN payroll_runs run
                 ON run.supplier_id = document.supplier_id
                AND run.id = document.run_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = document.supplier_id
                AND revision.id = document.revision_id
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = document.supplier_id
                AND employee.id = document.employee_id
          LEFT JOIN payroll_offices office
                 ON office.supplier_id = run.supplier_id
                AND office.id = run.office_id
              WHERE document.supplier_id = ?
                AND run.period_start = ?'
            . ($employeeId === null ? '' : ' AND document.employee_id = ?');

        $filterParams = [$supplierId, $periodStart];
        if ($employeeId !== null) {
            $filterParams[] = $employeeId;
        }

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($filterParams);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    revision.revision_no,
                    revision.status AS revision_status,
                    employee.full_name AS employee_name,
                    run.office_id,
                    office.name AS office_name'
            . $from
            . ' ORDER BY document.document_kind = "monthly_bundle" DESC,
                       employee.full_name,
                       document.document_kind,
                       document.id DESC
              LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($filterParams as $param) {
            $stmt->bindValue(
                $position++,
                $param,
                is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR,
            );
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_values(array_map(
                self::cast(...),
                $stmt->fetchAll(PDO::FETCH_ASSOC),
            )),
            'total' => $total,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function approvedRevisionsForPeriod(
        int $supplierId,
        string $periodStart,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT run.id AS run_id,
                    run.office_id,
                    office.name AS office_name,
                    revision.id AS revision_id,
                    revision.revision_no,
                    revision.status
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
          LEFT JOIN payroll_offices office
                 ON office.supplier_id = run.supplier_id
                AND office.id = run.office_id
              WHERE run.supplier_id = ?
                AND run.period_start = ?
                AND revision.status IN ("approved", "superseded")
                AND revision.result_snapshot_hash IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1
                      FROM payroll_run_revisions newer
                     WHERE newer.supplier_id = revision.supplier_id
                       AND newer.run_id = revision.run_id
                       AND newer.revision_no > revision.revision_no
                       AND newer.status IN ("approved", "superseded")
                       AND newer.result_snapshot_hash IS NOT NULL
                )
              ORDER BY office.name, run.id'
        );
        $stmt->execute([$supplierId, $periodStart]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['run_id', 'revision_id', 'revision_no'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            $row['office_id'] = $row['office_id'] === null
                ? null
                : (int) $row['office_id'];
        }
        unset($row);
        return array_values($rows);
    }

    /** @return list<array<string,mixed>> */
    public function forRevision(int $supplierId, int $revisionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ? AND revision_id = ?
                AND document_kind <> "monthly_bundle"
              ORDER BY id'
        );
        $stmt->execute([$supplierId, $revisionId]);
        return array_values(array_map(
            self::cast(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /** @return array<string,mixed>|null */
    public function latestForRevisionKind(
        int $supplierId,
        int $revisionId,
        ?int $employeeId,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ?
                AND revision_id = ?
                AND employee_id <=> ?
                AND document_kind = ?
              ORDER BY document_revision_no DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $revisionId,
            $employeeId,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function approvedEmploymentExitRevision(
        int $supplierId,
        int $employmentExitRevisionId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_employment_exit_revisions
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentExitRevisionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function latestForRunKind(
        int $supplierId,
        int $runId,
        ?int $employeeId,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payroll_generated_documents
              WHERE supplier_id = ?
                AND run_id = ?
                AND employee_id <=> ?
                AND document_kind = ?
              ORDER BY document_revision_no DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $runId,
            $employeeId,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function latestForAnnualKind(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        string $purpose,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    annual.tax_year,
                    annual.purpose,
                    annual.revision_no AS annual_revision_no
               FROM payroll_generated_documents document
               JOIN payroll_annual_document_revisions annual
                 ON annual.supplier_id = document.supplier_id
                AND annual.id = document.annual_revision_id
              WHERE document.supplier_id = ?
                AND document.employee_id = ?
                AND annual.tax_year = ?
                AND annual.purpose = ?
                AND document.document_kind = ?
              ORDER BY document.document_revision_no DESC, document.id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $employeeId,
            $taxYear,
            $purpose,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function latestForEmploymentExitKind(
        int $supplierId,
        int $employmentId,
        string $purpose,
        string $documentKind,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    exit_revision.employment_id,
                    exit_revision.employment_end_date,
                    exit_revision.purpose,
                    exit_revision.revision_no AS employment_exit_revision_no
               FROM payroll_generated_documents document
               JOIN payroll_employment_exit_revisions exit_revision
                 ON exit_revision.supplier_id = document.supplier_id
                AND exit_revision.id = document.employment_exit_revision_id
              WHERE document.supplier_id = ?
                AND exit_revision.employment_id = ?
                AND exit_revision.purpose = ?
                AND document.document_kind = ?
              ORDER BY document.document_revision_no DESC, document.id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            $purpose,
            $documentKind,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return array<string,mixed>|null */
    public function findAnnualArtifact(
        int $supplierId,
        int $annualRevisionId,
        int $employeeId,
        string $documentKind,
        string $templateVersion,
        string $rendererVersion,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    annual.tax_year,
                    annual.purpose,
                    annual.revision_no AS annual_revision_no
               FROM payroll_generated_documents document
               JOIN payroll_annual_document_revisions annual
                 ON annual.supplier_id = document.supplier_id
                AND annual.id = document.annual_revision_id
              WHERE document.supplier_id = ?
                AND document.annual_revision_id = ?
                AND document.employee_id = ?
                AND document.document_kind = ?
                AND document.template_version = ?
                AND document.renderer_version = ?
              ORDER BY document.document_revision_no DESC, document.id DESC
              LIMIT 1'
        );
        $stmt->execute([
            $supplierId,
            $annualRevisionId,
            $employeeId,
            $documentKind,
            $templateVersion,
            $rendererVersion,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::cast($row);
    }

    /**
     * Roční dokumenty; `$employeeId` zúží seznam na jednu osobu ve stejném
     * dotazu, do kterého padá stránkování.
     *
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listAnnualDocuments(
        int $supplierId,
        int $taxYear,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?int $employeeId = null,
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        if ($employeeId !== null && $employeeId <= 0) {
            throw new \InvalidArgumentException('Osoba musí být kladné číslo.');
        }

        $from = ' FROM payroll_generated_documents document
               JOIN payroll_annual_document_revisions annual
                 ON annual.supplier_id = document.supplier_id
                AND annual.id = document.annual_revision_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = document.supplier_id
                AND employee.id = document.employee_id
              WHERE document.supplier_id = ? AND annual.tax_year = ?'
            . ($employeeId === null ? '' : ' AND document.employee_id = ?');

        $filterParams = [$supplierId, $taxYear];
        if ($employeeId !== null) {
            $filterParams[] = $employeeId;
        }

        $countStmt = $this->db->pdo()->prepare('SELECT COUNT(*)' . $from);
        $countStmt->execute($filterParams);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    annual.tax_year,
                    annual.purpose,
                    annual.revision_no AS annual_revision_no,
                    employee.full_name AS employee_name'
            . $from
            . ' ORDER BY employee.full_name,
                       document.document_kind,
                       document.document_revision_no DESC,
                       document.id DESC
              LIMIT ? OFFSET ?'
        );
        $position = 1;
        foreach ($filterParams as $param) {
            $stmt->bindValue($position++, $param, PDO::PARAM_INT);
        }
        $stmt->bindValue($position++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($position, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_values(array_map(
                self::cast(...),
                $stmt->fetchAll(PDO::FETCH_ASSOC),
            )),
            'total' => $total,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listEmploymentExitDocuments(
        int $supplierId,
        int $employmentId,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT document.*,
                    exit_revision.employment_id,
                    exit_revision.employment_end_date,
                    exit_revision.purpose,
                    exit_revision.revision_no
                        AS employment_exit_revision_no,
                    employee.full_name AS employee_name
               FROM payroll_generated_documents document
               JOIN payroll_employment_exit_revisions exit_revision
                 ON exit_revision.supplier_id = document.supplier_id
                AND exit_revision.id = document.employment_exit_revision_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = document.supplier_id
                AND employee.id = document.employee_id
              WHERE document.supplier_id = ?
                AND exit_revision.employment_id = ?
              ORDER BY document.document_kind,
                       document.document_revision_no DESC,
                       document.id DESC',
        );
        $stmt->execute([$supplierId, $employmentId]);

        return array_values(array_map(
            self::cast(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    public function employeeBelongsToRevision(
        int $supplierId,
        int $revisionId,
        int $employeeId,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_run_persons
              WHERE supplier_id = ? AND revision_id = ? AND employee_id = ?
                AND status = "calculated" AND result_hash IS NOT NULL'
        );
        $stmt->execute([$supplierId, $revisionId, $employeeId]);
        return $stmt->fetchColumn() !== false;
    }

    public function countByStorageKey(int $supplierId, string $storageKey): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_generated_documents
              WHERE supplier_id = ? AND storage_key = ?',
        );
        $stmt->execute([$supplierId, $storageKey]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function insertOrGet(array $record): array
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByIdempotency(
            (int) $record['supplier_id'],
            (string) $record['idempotency_key_hash'],
        );
        if ($existing !== null) {
            return self::requireMatchingReplay($existing, $record);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_generated_documents
                (supplier_id, run_id, revision_id, annual_revision_id,
                 employment_exit_revision_id, employee_id, document_kind,
                 document_revision_no, supersedes_document_id, source_snapshot_hash,
                 revision_snapshot_hash,
                 template_version, renderer_version, file_sha256, size_bytes,
                  mime_type, storage_key, suggested_filename, manifest_json,
                  idempotency_key_hash, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     UNHEX(?), ?, COALESCE(?, CURRENT_TIMESTAMP))'
        );
        try {
            $stmt->execute([
                $record['supplier_id'],
                $record['run_id'],
                $record['revision_id'],
                $record['annual_revision_id'] ?? null,
                $record['employment_exit_revision_id'] ?? null,
                $record['employee_id'],
                $record['document_kind'],
                $record['document_revision_no'],
                $record['supersedes_document_id'],
                $record['source_snapshot_hash'],
                $record['revision_snapshot_hash'],
                $record['template_version'],
                $record['renderer_version'],
                $record['file_sha256'],
                $record['size_bytes'],
                $record['mime_type'],
                $record['storage_key'],
                $record['suggested_filename'],
                $record['manifest_json'],
                $record['idempotency_key_hash'],
                $record['created_by'],
                $record['created_at'] ?? null,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $replayed = $this->findByIdempotency(
                (int) $record['supplier_id'],
                (string) $record['idempotency_key_hash'],
            );
            if ($replayed === null) {
                throw new \RuntimeException(
                    'Generated payroll document conflicts with an existing artifact.',
                    previous: $exception,
                );
            }
            return self::requireMatchingReplay($replayed, $record);
        }
        $id = (int) $pdo->lastInsertId();
        $found = $this->find((int) $record['supplier_id'], $id);
        if ($found === null) {
            throw new \RuntimeException('Generated payroll document could not be loaded.');
        }
        return self::requireMatchingReplay($found, $record);
    }

    /**
     * @param array<string,mixed> $found
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function requireMatchingReplay(array $found, array $record): array
    {
        if (
            $found['source_snapshot_hash'] !== $record['source_snapshot_hash']
            || $found['document_kind'] !== $record['document_kind']
            || $found['employee_id'] !== $record['employee_id']
            || $found['run_id'] !== $record['run_id']
            || $found['revision_id'] !== $record['revision_id']
            || $found['annual_revision_id'] !== ($record['annual_revision_id'] ?? null)
            || $found['employment_exit_revision_id']
                !== ($record['employment_exit_revision_id'] ?? null)
        ) {
            throw new \RuntimeException('Payroll document idempotency key was reused for another request.');
        }
        return $found;
    }

    public function createDownloadGrant(
        int $supplierId,
        int $documentId,
        int $userId,
        string $tokenHash,
        string $expiresAt,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_download_grants
                (supplier_id, document_id, user_id, token_hash, expires_at)
             VALUES (?, ?, ?, UNHEX(?), ?)'
        );
        $stmt->execute([$supplierId, $documentId, $userId, $tokenHash, $expiresAt]);
    }

    public function consumeDownloadGrant(
        int $supplierId,
        int $documentId,
        int $userId,
        string $tokenHash,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_download_grants
                SET used_at = NOW()
              WHERE supplier_id = ? AND document_id = ? AND user_id = ?
                AND token_hash = UNHEX(?) AND used_at IS NULL AND expires_at >= NOW()'
        );
        $stmt->execute([$supplierId, $documentId, $userId, $tokenHash]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param 'handover'|'downloaded'|'external_notification' $eventType
     * @return array<string,mixed>
     */
    public function appendDeliveryEvent(
        int $supplierId,
        int $documentId,
        int $employeeId,
        string $eventType,
        int $actorUserId,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_delivery_events
                (supplier_id, payroll_document_id, employee_id, event_type, recorded_by)
             VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->execute([$supplierId, $documentId, $employeeId, $eventType, $actorUserId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $event = $this->deliveryEvent($supplierId, $documentId, $id);
        if ($event === null) {
            throw new \RuntimeException('Payroll document delivery event could not be loaded.');
        }
        return $event;
    }

    /** @return list<array<string,mixed>> */
    public function deliveryEventsForDocument(int $supplierId, int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, payroll_document_id, employee_id, event_type, recorded_by, occurred_at, created_at
               FROM payroll_document_delivery_events
              WHERE supplier_id = ? AND payroll_document_id = ?
              ORDER BY occurred_at, id',
        );
        $stmt->execute([$supplierId, $documentId]);
        return array_values(array_map(self::castDeliveryEvent(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<int> $documentIds
     * @return array<int,array{handed_over_at:?string,downloaded_at:?string,external_notification_at:?string}>
     */
    public function deliverySummaries(int $supplierId, array $documentIds): array
    {
        $documentIds = array_values(array_filter($documentIds, static fn (int $id): bool => $id > 0));
        if ($documentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT payroll_document_id,
                    MAX(CASE WHEN event_type = "handover" THEN occurred_at END) AS handed_over_at,
                    MAX(CASE WHEN event_type = "downloaded" THEN occurred_at END) AS downloaded_at,
                    MAX(CASE WHEN event_type = "external_notification" THEN occurred_at END) AS external_notification_at
               FROM payroll_document_delivery_events
              WHERE supplier_id = ? AND payroll_document_id IN (' . $placeholders . ')
              GROUP BY payroll_document_id',
        );
        $stmt->execute([$supplierId, ...$documentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['payroll_document_id']] = [
                'handed_over_at' => $row['handed_over_at'] === null ? null : (string) $row['handed_over_at'],
                'downloaded_at' => $row['downloaded_at'] === null ? null : (string) $row['downloaded_at'],
                'external_notification_at' => $row['external_notification_at'] === null ? null : (string) $row['external_notification_at'],
            ];
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    private function deliveryEvent(int $supplierId, int $documentId, int $eventId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, payroll_document_id, employee_id, event_type, recorded_by, occurred_at, created_at
               FROM payroll_document_delivery_events
              WHERE supplier_id = ? AND payroll_document_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $documentId, $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::castDeliveryEvent($row);
    }

    public function linkToDms(
        int $supplierId,
        int $payrollDocumentId,
        int $dmsDocumentId,
        ?int $actorUserId,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_dms_links
                (supplier_id, payroll_document_id, dms_document_id, linked_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $payrollDocumentId,
            $dmsDocumentId,
            $actorUserId,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'document_revision_no',
            'size_bytes', 'revision_no', 'annual_revision_no', 'tax_year',
            'employment_id', 'employment_exit_revision_no',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach ([
            'employee_id',
            'supersedes_document_id',
            'created_by',
            'office_id',
            'annual_revision_id',
            'employment_exit_revision_id',
            'run_id',
            'revision_id',
        ] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        if (array_key_exists('manifest_json', $row)) {
            $row['manifest'] = $row['manifest_json'] === null
                ? null
                : json_decode((string) $row['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
            unset($row['manifest_json']);
        }
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function castDeliveryEvent(array $row): array
    {
        foreach (['id', 'payroll_document_id', 'employee_id'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['recorded_by'] = $row['recorded_by'] === null ? null : (int) $row['recorded_by'];
        return $row;
    }
}
