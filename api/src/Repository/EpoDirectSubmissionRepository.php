<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

final class EpoDirectSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    public function createAttempt(
        int $supplierId,
        int $submissionId,
        int $credentialId,
        string $fingerprint,
        string $requestSha256,
        ?int $userId,
        string $environment,
    ): int {
        (new \MyInvoice\Service\Submission\AnonymizedCloneSubmissionProvenance($this->db))->assertDispatchable($supplierId, $submissionId);
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, channel, epo_environment,
                 status, idempotency_key,
                 request_sha256, signing_credential_id, signing_fingerprint, requested_by)
             VALUES (?, ?, 'epo_direct', ?, 'prepared', ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId,
            $submissionId,
            $environment,
            bin2hex(random_bytes(16)),
            $requestSha256,
            $credentialId,
            $fingerprint,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function setStatus(
        int $attemptId,
        string $status,
        ?int $httpStatus = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        $this->db->pdo()->prepare(
            'UPDATE tax_submission_attempts
                SET status = ?, response_http_status = ?,
                    error_code = ?, error_message = ?
              WHERE id = ? AND channel = \'epo_direct\''
        )->execute([
            $status,
            $httpStatus,
            $errorCode,
            $errorMessage !== null ? mb_substr($errorMessage, 0, 500) : null,
            $attemptId,
        ]);
    }

    public function claimTestPassedAttempt(
        int $attemptId,
        int $submissionId,
        int $supplierId,
        int $requestedBy,
        string $requestSha256,
        string $environment,
    ): bool {
        $environment = $this->normalizeEnvironment($environment);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $lock = $pdo->prepare(
                'SELECT status FROM tax_submissions
                  WHERE id = ? AND supplier_id = ?
                  FOR UPDATE'
            );
            $lock->execute([$submissionId, $supplierId]);
            $submissionStatus = $lock->fetchColumn();
            if (
                $submissionStatus === false
                || in_array((string) $submissionStatus, ['submitted', 'accepted'], true)
            ) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            // Stejná brána jako {@see self::hasUnresolvedLiveAttempt()} — včetně toho,
            // že asistovaný pokus překonaný pozdějším úspěšným přímým testem už
            // ostré odeslání neblokuje. Kdyby se rozešly, UI by tlačítko nabídlo
            // a tenhle claim by ho tiše odmítl.
            $active = $pdo->prepare(
                "SELECT 1
                   FROM tax_submission_attempts a
                  WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                    AND a.id <> ?
                    AND (a.epo_environment = 'production' OR a.epo_environment = ?)
                    AND (
                      (a.channel = 'epo_direct'
                        AND (
                          a.status IN ('submitting','processing','uncertain')
                          OR (a.status = 'confirmed' AND a.epo_environment = 'production')
                        ))
                      OR
                      (a.channel = 'epo_assisted'
                        AND a.status IN ('prepared','handoff_created','awaiting_confirmation')
                        AND (
                          (a.status = 'prepared'
                            AND a.requested_at > CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
                          OR
                          (a.status <> 'prepared'
                            AND (a.handoff_expires_at > CURRENT_TIMESTAMP
                              OR (a.handoff_expires_at IS NULL
                                AND a.updated_at > CURRENT_TIMESTAMP - INTERVAL 30 MINUTE)))
                        )
                        AND NOT EXISTS (
                          SELECT 1
                            FROM tax_submission_attempts d
                           WHERE d.tax_submission_id = a.tax_submission_id
                             AND d.supplier_id = a.supplier_id
                             AND d.channel = 'epo_direct'
                             AND d.status = 'test_passed'
                             AND (d.epo_environment = 'production' OR d.epo_environment = ?)
                             AND d.requested_at > a.requested_at
                        ))
                    )
                  LIMIT 1"
            );
            $active->execute([$submissionId, $supplierId, $attemptId, $environment, $environment]);
            if ($active->fetchColumn() !== false) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            $stmt = $pdo->prepare(
                "UPDATE tax_submission_attempts
                    SET status = 'submitting', error_code = NULL, error_message = NULL
                  WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                    AND channel = 'epo_direct' AND status = 'test_passed'
                    AND epo_environment = ?
                    AND test_passed = 1 AND requested_by = ? AND request_sha256 = ?
                    AND tested_at >= CURRENT_TIMESTAMP - INTERVAL 30 MINUTE"
            );
            $stmt->execute([
                $attemptId,
                $submissionId,
                $supplierId,
                $environment,
                $requestedBy,
                $requestSha256,
            ]);
            $claimed = $stmt->rowCount() === 1;
            if ($ownsTransaction) {
                $claimed ? $pdo->commit() : $pdo->rollBack();
            }
            return $claimed;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Zruší otevřená asistovaná předání snapshotu, když uživatel vědomě podává
     * certifikátem přes ně. Volá se ve stejné transakci jako
     * {@see self::claimTestPassedAttempt()}, aby mezi zrušením a claimem nevzniklo
     * nové předání.
     *
     * @return list<int> zrušené pokusy
     */
    public function cancelOpenAssistedAttempts(int $submissionId, int $supplierId, string $environment): array
    {
        $environment = $this->normalizeEnvironment($environment);
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('cancelOpenAssistedAttempts vyžaduje transakci.');
        }
        $pdo->prepare('SELECT id FROM tax_submissions WHERE id = ? AND supplier_id = ? FOR UPDATE')
            ->execute([$submissionId, $supplierId]);
        $select = $pdo->prepare(
            "SELECT id FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND channel = 'epo_assisted'
                AND status IN ('prepared','handoff_created','awaiting_confirmation')
                AND (epo_environment = 'production' OR epo_environment = ?)
              FOR UPDATE"
        );
        $select->execute([$submissionId, $supplierId, $environment]);
        $ids = array_map('intval', $select->fetchAll(\PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare(
                "UPDATE tax_submission_attempts
                    SET status = 'cancelled', error_code = 'superseded_by_direct',
                        error_message = 'Nahrazeno podáním certifikátem.'
                  WHERE id IN ($marks)"
            )->execute($ids);
        }
        return $ids;
    }

    /**
     * Běží na tomtéž snapshotu něco, co ještě může skončit podáním?
     *
     * Asistované předání (odkaz do EPO) blokuje jen dokud reálně žije:
     *  - `prepared` 5 minut od založení,
     *  - dál podle `handoff_expires_at`; když ho záznam nemá, platí náhradních
     *    30 minut od poslední změny stavu. Bez toho by takový řádek blokoval
     *    přímé podání navždy, protože `NULL > NOW()` nikdy nenastane.
     *  - a vůbec ne, pokud po jeho založení proběhl ÚSPĚŠNÝ přímý test
     *    (`epo_direct` / `test_passed`) — tím uživatel vědomě zvolil druhou
     *    cestu a starší asistovaný záznam je překonaný.
     *
     * Ochrana proti dvojímu ODESLÁNÍ tím nemizí: přímé pokusy ve stavech
     * `submitting`/`processing`/`uncertain` a potvrzené produkční podání
     * blokují beze změny.
     */
    public function hasUnresolvedLiveAttempt(
        int $submissionId,
        int $supplierId,
        string $environment,
    ): bool
    {
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM tax_submission_attempts a
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND (a.epo_environment = 'production' OR a.epo_environment = ?)
                AND (
                  (a.channel = 'epo_direct'
                    AND (
                      a.status IN ('submitting','processing','uncertain')
                      OR (a.status = 'confirmed' AND a.epo_environment = 'production')
                    ))
                  OR
                  (a.channel = 'epo_assisted'
                    AND a.status IN ('prepared','handoff_created','awaiting_confirmation')
                    AND (
                      (a.status = 'prepared'
                        AND a.requested_at > CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
                      OR
                      (a.status <> 'prepared'
                        AND (a.handoff_expires_at > CURRENT_TIMESTAMP
                          OR (a.handoff_expires_at IS NULL
                            AND a.updated_at > CURRENT_TIMESTAMP - INTERVAL 30 MINUTE)))
                    )
                    AND NOT EXISTS (
                      SELECT 1
                        FROM tax_submission_attempts d
                       WHERE d.tax_submission_id = a.tax_submission_id
                         AND d.supplier_id = a.supplier_id
                         AND d.channel = 'epo_direct'
                         AND d.status = 'test_passed'
                         AND (d.epo_environment = 'production' OR d.epo_environment = ?)
                         AND d.requested_at > a.requested_at
                    ))
                )
              LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId, $environment, $environment]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasUnresolvedDirectAttempt(
        int $submissionId,
        int $supplierId,
        string $environment,
    ): bool
    {
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1
               FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND channel = 'epo_direct'
                AND (epo_environment = 'production' OR epo_environment = ?)
                AND (
                  status IN ('submitting','processing','uncertain')
                  OR (status = 'confirmed' AND epo_environment = 'production')
                )
              LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId, $environment]);
        return $stmt->fetchColumn() !== false;
    }

    public function storeEncryptedTestPayload(int $attemptId, string $ciphertext): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET test_signed_ciphertext = ?
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([$ciphertext, $attemptId]);
    }

    public function storeEncryptedSubmittedPayload(int $attemptId, string $ciphertext): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET submitted_signed_ciphertext = ?
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([$ciphertext, $attemptId]);
    }

    public function storeEncryptedResponse(int $attemptId, string $ciphertext, int $httpStatus): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET last_response_ciphertext = ?, response_http_status = ?
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([$ciphertext, $httpStatus, $attemptId]);
    }

    public function storeEncryptedConfirmationPayload(
        int $attemptId,
        string $ciphertext,
        int $httpStatus,
    ): void {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET confirmation_ciphertext = ?, response_http_status = ?
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([$ciphertext, $httpStatus, $attemptId]);
    }

    public function stageConfirmation(
        int $attemptId,
        string $reference,
        string $submittedAt,
        string $statePasswordCiphertext,
        string $confirmationCiphertext,
        int $httpStatus,
    ): void {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET response_http_status = ?, remote_submission_ref = ?,
                    state_password_ciphertext = ?, confirmation_ciphertext = ?,
                    submitted_at = ?, error_code = NULL, error_message = NULL,
                    poll_count = 0,
                    next_poll_at = CURRENT_TIMESTAMP + INTERVAL 1 MINUTE
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([
            $httpStatus,
            $reference,
            $statePasswordCiphertext,
            $confirmationCiphertext,
            $submittedAt,
            $attemptId,
        ]);
    }

    /** @param list<array<string,mixed>> $messages */
    public function recordTest(
        int $attemptId,
        bool $passed,
        array $messages,
        int $httpStatus,
    ): void {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = ?, response_http_status = ?, test_passed = ?,
                    test_messages_json = ?, tested_at = CURRENT_TIMESTAMP,
                    error_code = ?, error_message = ?
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([
            $passed ? 'test_passed' : 'test_failed',
            $httpStatus,
            $passed ? 1 : 0,
            json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $passed ? null : 'epo_validation_failed',
            $passed ? null : $this->messagesSummary($messages),
            $attemptId,
        ]);
    }

    public function recordConfirmed(
        int $attemptId,
        string $reference,
        string $submittedAt,
        string $statePasswordCiphertext,
        int $httpStatus,
    ): void {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'confirmed', response_http_status = ?,
                    remote_submission_ref = ?, state_password_ciphertext = ?,
                    submitted_at = ?, confirmed_at = ?,
                    error_code = NULL, error_message = NULL,
                    next_poll_at = NULL
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([
            $httpStatus,
            $reference,
            $statePasswordCiphertext,
            $submittedAt,
            $submittedAt,
            $attemptId,
        ]);
    }

    public function recordOffline(
        int $attemptId,
        string $transferId,
        string $passwordCiphertext,
        int $httpStatus,
    ): void {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'processing', response_http_status = ?,
                    offline_transfer_id = ?, offline_password_ciphertext = ?,
                    submitted_at = CURRENT_TIMESTAMP,
                    error_code = NULL, error_message = NULL,
                    poll_count = 0,
                    next_poll_at = CURRENT_TIMESTAMP + INTERVAL 1 MINUTE
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([$httpStatus, $transferId, $passwordCiphertext, $attemptId]);
    }

    /** @param array<string,mixed> $status */
    public function recordRemoteStatus(int $attemptId, string $lifecycle, array $status): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = ?, last_status_json = ?, last_status_at = CURRENT_TIMESTAMP
              WHERE id = ? AND channel = 'epo_direct'"
        )->execute([
            $lifecycle,
            json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $attemptId,
        ]);
    }

    public function scheduleNextPoll(int $attemptId, int $delaySeconds): void
    {
        $delaySeconds = max(60, min(3600, $delaySeconds));
        $this->db->pdo()->prepare(
            'UPDATE tax_submission_attempts
                SET next_poll_at = IF(
                        poll_count + 1 >= 12,
                        NULL,
                        DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND)
                    ),
                    poll_count = poll_count + 1
              WHERE id = ? AND channel = \'epo_direct\'
                AND status IN (\'processing\',\'confirmed\',\'uncertain\')'
        )->execute([$delaySeconds, $attemptId]);
    }

    public function clearScheduledPoll(int $attemptId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tax_submission_attempts
                SET next_poll_at = NULL
              WHERE id = ? AND channel = \'epo_direct\''
        )->execute([$attemptId]);
    }

    /**
     * @return list<array{attempt_id:int,submission_id:int,supplier_id:int,user_id:int,environment:string}>
     */
    public function pollableAttempts(int $limit = 50, string $environment = 'production'): array
    {
        $limit = max(1, min(200, $limit));
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Neplatné prostředí EPO.');
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id AS attempt_id, tax_submission_id AS submission_id,
                    supplier_id, requested_by AS user_id, epo_environment
               FROM tax_submission_attempts
              WHERE channel = 'epo_direct'
                AND epo_environment = ?
                AND status IN ('processing','confirmed','uncertain')
                AND next_poll_at IS NOT NULL
                AND next_poll_at <= CURRENT_TIMESTAMP
                AND poll_count < 12
                AND requested_by IS NOT NULL
                AND (
                    (offline_transfer_id IS NOT NULL AND offline_password_ciphertext IS NOT NULL)
                    OR
                    (remote_submission_ref IS NOT NULL AND state_password_ciphertext IS NOT NULL)
                )
              ORDER BY next_poll_at ASC, id ASC
              LIMIT ?"
        );
        $stmt->bindValue(1, $environment);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            static fn (array $row): array => [
                'attempt_id' => (int) $row['attempt_id'],
                'submission_id' => (int) $row['submission_id'],
                'supplier_id' => (int) $row['supplier_id'],
                'user_id' => (int) ($row['user_id'] ?? 0),
                'environment' => (string) $row['epo_environment'],
            ],
            $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
        );
    }

    public function resolveAsNotSubmitted(
        int $attemptId,
        int $submissionId,
        int $supplierId,
        int $resolvedBy,
        string $note,
    ): bool {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $submission = $pdo->prepare(
                'SELECT status FROM tax_submissions
                  WHERE id = ? AND supplier_id = ?
                  FOR UPDATE'
            );
            $submission->execute([$submissionId, $supplierId]);
            $submissionStatus = $submission->fetchColumn();
            if (
                $submissionStatus === false
                || in_array((string) $submissionStatus, ['submitted', 'accepted'], true)
            ) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }

            $stmt = $pdo->prepare(
                "UPDATE tax_submission_attempts
                    SET status = 'cancelled',
                        resolution_code = 'verified_not_submitted',
                        resolution_note = ?,
                        resolved_by = ?,
                        resolved_at = CURRENT_TIMESTAMP,
                        next_poll_at = NULL,
                        error_code = NULL,
                        error_message = NULL
                  WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                    AND channel = 'epo_direct'
                    AND remote_submission_ref IS NULL
                    AND offline_transfer_id IS NULL
                    AND (
                        status = 'uncertain'
                        OR (
                            status = 'submitting'
                            AND updated_at <= CURRENT_TIMESTAMP - INTERVAL 15 MINUTE
                        )
                    )"
            );
            $stmt->execute([
                mb_substr(trim($note), 0, 500),
                $resolvedBy,
                $attemptId,
                $submissionId,
                $supplierId,
            ]);
            $resolved = $stmt->rowCount() === 1;
            if ($ownsTransaction) {
                $resolved ? $pdo->commit() : $pdo->rollBack();
            }
            return $resolved;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setSubmissionRemoteStatus(
        int $submissionId,
        int $supplierId,
        string $status,
    ): void {
        if (!in_array($status, ['accepted', 'rejected'], true)) {
            throw new \InvalidArgumentException('Neplatný stav podání.');
        }
        $this->db->pdo()->prepare(
            'UPDATE tax_submissions SET status = ? WHERE id = ? AND supplier_id = ?'
        )->execute([$status, $submissionId, $supplierId]);
    }

    /**
     * @param bool $anyChannel Pustí i ASISTOVANÉ předání. Dotaz na stav podání
     *     (podací číslo + heslo) a odhalení hesla fungují stejně pro oba kanály — obě
     *     hodnoty pocházejí z téhož dokumentu, z dodejky EPO. Odesílání a obnova
     *     potvrzenky zůstávají výhradně přímé, proto to není výchozí chování.
     *
     * @return array<string,mixed>|null
     */
    public function findAttempt(
        int $attemptId,
        int $submissionId,
        int $supplierId,
        bool $anyChannel = false,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            "SELECT *
               FROM tax_submission_attempts
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                AND (channel = 'epo_direct' OR ? = 1)"
        );
        $stmt->execute([$attemptId, $submissionId, $supplierId, $anyChannel ? 1 : 0]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Výsledek dotazu na stav u ASISTOVANÉHO předání.
     *
     * Stav pokusu se přepisuje jen tehdy, když o něm EPO řekne něco definitivního
     * (přijato / zamítnuto). Průběžné „zpracovává se" by u asistovaného pokusu shodilo
     * stav z `awaiting_confirmation` a účetní by tím přišla o možnost označit podání
     * za podané — a to je u tohohle kanálu pořád její úkon, ne náš.
     *
     * @param array<string,mixed> $status
     */
    public function recordAssistedRemoteStatus(
        int $attemptId,
        ?string $lifecycle,
        array $status,
    ): void {
        $sql = $lifecycle !== null
            ? "UPDATE tax_submission_attempts
                  SET status = ?, last_status_json = ?, last_status_at = CURRENT_TIMESTAMP
                WHERE id = ? AND channel = 'epo_assisted'"
            : "UPDATE tax_submission_attempts
                  SET last_status_json = ?, last_status_at = CURRENT_TIMESTAMP
                WHERE id = ? AND channel = 'epo_assisted'";
        $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->db->pdo()->prepare($sql)->execute(
            $lifecycle !== null ? [$lifecycle, $json, $attemptId] : [$json, $attemptId],
        );
    }

    /** @param array<string,mixed> $details */
    public function addEvent(
        int $supplierId,
        int $submissionId,
        int $attemptId,
        string $eventType,
        string $status,
        ?int $httpStatus,
        array $details,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_status_events
                (supplier_id, tax_submission_id, attempt_id, epo_environment,
                 event_type, status, http_status, details_json, created_by)
             SELECT ?, ?, ?, a.epo_environment, ?, ?, ?, ?, ?
               FROM tax_submission_attempts a
              WHERE a.id = ?'
        )->execute([
            $supplierId,
            $submissionId,
            $attemptId,
            $eventType,
            $status,
            $httpStatus,
            $details !== []
                ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            $userId,
            $attemptId,
        ]);
    }

    /** @param list<array<string,mixed>> $messages */
    private function messagesSummary(array $messages): string
    {
        $parts = [];
        foreach (array_slice($messages, 0, 3) as $message) {
            $text = trim((string) ($message['text'] ?? ''));
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return mb_substr($parts !== [] ? implode(' ', $parts) : 'EPO nalezlo chyby v podání.', 0, 500);
    }

    private function normalizeEnvironment(string $environment): string
    {
        $environment = strtolower(trim($environment));
        if (!in_array($environment, ['production', 'test'], true)) {
            throw new \InvalidArgumentException('Neplatné prostředí EPO.');
        }
        return $environment;
    }
}
