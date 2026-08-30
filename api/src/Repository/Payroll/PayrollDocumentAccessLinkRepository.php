<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úložiště zabezpečených odkazů na osobní mzdové dokumenty.
 *
 * Tabulka je zároveň fronta rozesílky, takže tady žije i `claimNext()` ve stejném
 * duchu jako {@see PayrollAnnualDocumentBatchRepository::claimNext()}.
 *
 * ČAS: všechny lhůty (`expires_at`, `next_attempt_at`, `leased_until`) se zapisují
 * i porovnávají výhradně přes `UTC_TIMESTAMP()`. Sloupcový DEFAULT
 * `CURRENT_TIMESTAMP` je místní čas a používá se jen u `created_at`, tedy tam, kde
 * na hodnotě nezávisí žádné rozhodnutí.
 *
 * TENANT: každý dotaz nese `supplier_id` v predikátu. Výjimky jsou dvě a obě
 * vědomé:
 *   1. `findByTokenHash()` tenanta teprve ZJIŠŤUJE — veřejný návštěvník žádného
 *      nemá. Hledá přes `UNIQUE (token_hash)` a `supplier_id` z nalezeného řádku
 *      je pak povinným vstupem každého dalšího volání.
 *   2. Výběr práce ve frontě (`claimNext()`, `recoverStale()`, `pruneExpired()`)
 *      jde napříč tenanty, protože worker obsluhuje všechny — stejně jako
 *      {@see PayrollAnnualDocumentBatchRepository::claimNext()}. Každá MUTACE
 *      konkrétního odkazu už `supplier_id` v predikátu má.
 */
final class PayrollDocumentAccessLinkRepository
{
    /** Po kolika sekundách se považuje uvíznutý lease za mrtvý. */
    private const LEASE_SECONDS = 300;

    public function __construct(private readonly Connection $db) {}

    /**
     * Založí odkaz BEZ tokenu. Lokátor vzniká až v okamžiku odeslání
     * ({@see self::attachToken()}), protože jeho plaintext existuje jen v té jedné
     * odeslané zprávě a fronta by ho neměla kam odložit, aniž by ho tím prozradila.
     *
     * @return array{id:int,created:bool}
     */
    public function create(
        int $supplierId,
        int $documentId,
        int $employeeId,
        string $recipientEmailHashBinary,
        string $recipientMasked,
        string $idempotencyKey,
        int $ttlDays,
        ?int $createdBy,
    ): array {
        $pdo = $this->db->pdo();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO payroll_document_access_links
                (supplier_id, payroll_document_id, employee_id,
                 recipient_email_hash, recipient_masked, idempotency_key,
                 expires_at, next_attempt_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?,
                     DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY),
                     UTC_TIMESTAMP(), ?)',
        );
        $insert->execute([
            $supplierId,
            $documentId,
            $employeeId,
            $recipientEmailHashBinary,
            $recipientMasked,
            $idempotencyKey,
            $ttlDays,
            $createdBy,
        ]);
        if ($insert->rowCount() === 1) {
            return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
        }

        // Idempotentní opakování téhož požadavku: vrátíme existující odkaz místo
        // druhého tokenu na tentýž dokument. Bez toho by dvojklik účetní poslal
        // zaměstnanci dva různě platné odkazy na jednu pásku.
        $existing = $pdo->prepare(
            'SELECT id FROM payroll_document_access_links
              WHERE supplier_id = ? AND idempotency_key = ?',
        );
        $existing->execute([$supplierId, $idempotencyKey]);
        $id = $existing->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('Zabezpečený odkaz se nepodařilo založit.');
        }
        return ['id' => (int) $id, 'created' => false];
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, int $linkId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_document_access_links
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $linkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /**
     * Vyplní lokátor. Jen pod platným leasem a jen na dosud NEODESLANÉM odkazu —
     * neúspěšný pokus smí token přerazit, doručený už ne (hlídá i trigger v DB).
     */
    public function attachToken(
        int $supplierId,
        int $linkId,
        string $tokenHash,
        string $leaseToken,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_links
                SET token_hash = ?
              WHERE supplier_id = ? AND id = ? AND lease_token = ?
                AND dispatch_state = "sending" AND sent_at IS NULL',
        );
        $stmt->execute([$tokenHash, $supplierId, $linkId, $leaseToken]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Vyhledání veřejným lokátorem. Jediné místo bez tenantového predikátu —
     * návštěvník tenanta nezná a `token_hash` je globálně unikátní. `supplier_id`
     * z nalezeného řádku je pak povinným vstupem každého dalšího volání.
     *
     * @return array<string,mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        if ($tokenHash === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_document_access_links
              WHERE token_hash = ? AND token_hash IS NOT NULL',
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::cast($row);
    }

    /** @return list<array<string,mixed>> */
    public function forDocument(int $supplierId, int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_document_access_links
              WHERE supplier_id = ? AND payroll_document_id = ?
              ORDER BY id DESC',
        );
        $stmt->execute([$supplierId, $documentId]);
        return array_values(array_map(
            self::cast(...),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }

    /**
     * @param list<int> $documentIds
     * @return array<int,array<string,mixed>> nejnovější živý odkaz na dokument
     */
    public function summariesForDocuments(int $supplierId, array $documentIds): array
    {
        $documentIds = array_values(array_filter(
            $documentIds,
            static fn (int $id): bool => $id > 0,
        ));
        if ($documentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM payroll_document_access_links
              WHERE supplier_id = ? AND payroll_document_id IN (' . $placeholders . ')
              ORDER BY payroll_document_id, id DESC',
        );
        $stmt->execute([$supplierId, ...$documentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documentId = (int) $row['payroll_document_id'];
            $result[$documentId] ??= self::cast($row);
        }
        return $result;
    }

    /**
     * Převezme jednu čekající položku rozesílky. `SKIP LOCKED` dovoluje víc
     * workerů; `recoverStale()` vrátí do fronty ty, jejichž worker spadl.
     *
     * @return array<string,mixed>|null
     */
    public function claimNext(int $maxAttempts): ?array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_document_access_claim');
        }
        try {
            $this->recoverStale();
            $select = $pdo->prepare(
                'SELECT ' . self::COLUMNS . '
                   FROM payroll_document_access_links
                  WHERE dispatch_state = "pending"
                    AND revoked_at IS NULL
                    AND expires_at > UTC_TIMESTAMP()
                    AND attempt_count < ?
                    AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP())
                  ORDER BY next_attempt_at, id
                  LIMIT 1 FOR UPDATE SKIP LOCKED',
            );
            $select->execute([$maxAttempts]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->finish($pdo, $ownsTransaction);
                return null;
            }
            $lease = bin2hex(random_bytes(16));
            $attempt = (int) $row['attempt_count'] + 1;
            $update = $pdo->prepare(
                'UPDATE payroll_document_access_links
                    SET dispatch_state = "sending", attempt_count = ?,
                        lease_token = ?,
                        leased_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                        last_error_code = NULL
                  WHERE supplier_id = ? AND id = ? AND dispatch_state = "pending"',
            );
            $update->execute([
                $attempt,
                $lease,
                self::LEASE_SECONDS,
                (int) $row['supplier_id'],
                (int) $row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Položku rozesílky se nepodařilo zamknout.');
            }
            $this->finish($pdo, $ownsTransaction);
            $claim = self::cast($row);
            $claim['attempt_count'] = $attempt;
            $claim['lease_token'] = $lease;
            return $claim;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->inTransaction() && $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_document_access_claim');
            }
            throw $exception;
        }
    }

    public function markSent(int $supplierId, int $linkId, string $leaseToken): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_links
                SET dispatch_state = "sent", sent_at = UTC_TIMESTAMP(),
                    lease_token = NULL, leased_until = NULL, next_attempt_at = NULL
              WHERE supplier_id = ? AND id = ? AND lease_token = ?
                AND dispatch_state = "sending"',
        );
        $stmt->execute([$supplierId, $linkId, $leaseToken]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Neúspěch. Dokud zbývají pokusy, položka se vrací do `pending` s exponenciálním
     * odstupem; po jejich vyčerpání končí ve `failed` a nikdo ji už sám nezkusí.
     */
    public function markAttemptFailed(
        int $supplierId,
        int $linkId,
        string $leaseToken,
        string $errorCode,
        int $maxAttempts,
        bool $permanent = false,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_links
                SET dispatch_state = CASE
                      WHEN ? = 1 OR attempt_count >= ? THEN "failed" ELSE "pending" END,
                    next_attempt_at = CASE
                      WHEN ? = 1 OR attempt_count >= ? THEN NULL
                      ELSE DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(POW(4, attempt_count), 960) MINUTE)
                      END,
                    last_error_code = ?, lease_token = NULL, leased_until = NULL
              WHERE supplier_id = ? AND id = ? AND lease_token = ?
                AND dispatch_state = "sending"',
        );
        $permanentFlag = $permanent ? 1 : 0;
        $stmt->execute([
            $permanentFlag,
            $maxAttempts,
            $permanentFlag,
            $maxAttempts,
            substr($errorCode, 0, 64),
            $supplierId,
            $linkId,
            $leaseToken,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Vrátí do fronty položky, jejichž worker zemřel s drženým lease. */
    public function recoverStale(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_links
                SET dispatch_state = "pending", lease_token = NULL,
                    leased_until = NULL, next_attempt_at = UTC_TIMESTAMP(),
                    last_error_code = "lease_expired"
              WHERE dispatch_state = "sending"
                AND leased_until IS NOT NULL
                AND leased_until < UTC_TIMESTAMP()
                AND attempt_count < ?',
        );
        $stmt->execute([10]);
        return $stmt->rowCount();
    }

    /**
     * Zneplatnění. Ruší i všechny ověřené relace, aby zaměstnanec, který má
     * otevřené okno, po odvolání odkazu už nic nestáhl.
     */
    public function revoke(int $supplierId, int $linkId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'UPDATE payroll_document_access_links
                SET revoked_at = UTC_TIMESTAMP(),
                    dispatch_state = CASE
                      WHEN dispatch_state IN ("pending", "sending") THEN "cancelled"
                      ELSE dispatch_state END,
                    lease_token = NULL, leased_until = NULL, next_attempt_at = NULL
              WHERE supplier_id = ? AND id = ? AND revoked_at IS NULL',
        );
        $stmt->execute([$supplierId, $linkId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $pdo->prepare(
            'UPDATE payroll_document_access_sessions
                SET revoked_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND link_id = ? AND revoked_at IS NULL',
        )->execute([$supplierId, $linkId]);
        $pdo->prepare(
            'UPDATE payroll_document_access_codes
                SET used_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND link_id = ? AND used_at IS NULL',
        )->execute([$supplierId, $linkId]);
        return true;
    }

    public function recordDownload(int $supplierId, int $linkId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_links
                SET download_count = download_count + 1,
                    first_downloaded_at = COALESCE(first_downloaded_at, UTC_TIMESTAMP()),
                    last_downloaded_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$supplierId, $linkId]);
    }

    // ---- jednorázové kódy -------------------------------------------------

    /** @return array<string,mixed>|null */
    public function activeCode(int $supplierId, int $linkId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code_hash, attempts, created_at,
                    UNIX_TIMESTAMP(created_at) AS created_ts,
                    (expires_at > UTC_TIMESTAMP()) AS is_fresh
               FROM payroll_document_access_codes
              WHERE supplier_id = ? AND link_id = ? AND used_at IS NULL
                AND expires_at > UTC_TIMESTAMP()
              ORDER BY id DESC
              LIMIT 1',
        );
        $stmt->execute([$supplierId, $linkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Sekundy od vydání posledního kódu (i spotřebovaného) — podklad pro cooldown. */
    public function secondsSinceLastCode(int $supplierId, int $linkId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), UTC_TIMESTAMP())
               FROM payroll_document_access_codes
              WHERE supplier_id = ? AND link_id = ?',
        );
        $stmt->execute([$supplierId, $linkId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    public function invalidateCodes(int $supplierId, int $linkId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_codes
                SET used_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND link_id = ? AND used_at IS NULL',
        )->execute([$supplierId, $linkId]);
    }

    public function insertCode(
        int $supplierId,
        int $linkId,
        string $codeHash,
        int $ttlSeconds,
        ?string $ipBinary,
    ): int {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO payroll_document_access_codes
                (supplier_id, link_id, code_hash, expires_at, created_at, ip)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                     UTC_TIMESTAMP(), ?)',
        )->execute([$supplierId, $linkId, $codeHash, $ttlSeconds, $ipBinary]);
        return (int) $pdo->lastInsertId();
    }

    public function markCodeUsed(int $supplierId, int $codeId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_document_access_codes
                SET used_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND id = ? AND used_at IS NULL',
        )->execute([$supplierId, $codeId]);
    }

    public function bumpCodeAttempts(int $supplierId, int $codeId): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'UPDATE payroll_document_access_codes
                SET attempts = attempts + 1
              WHERE supplier_id = ? AND id = ? AND attempts < 20',
        )->execute([$supplierId, $codeId]);
        $stmt = $pdo->prepare(
            'SELECT attempts FROM payroll_document_access_codes
              WHERE supplier_id = ? AND id = ?',
        );
        $stmt->execute([$supplierId, $codeId]);
        return (int) $stmt->fetchColumn();
    }

    // ---- relace -----------------------------------------------------------

    public function createSession(
        int $supplierId,
        int $linkId,
        string $sessionHash,
        int $ttlSeconds,
        ?string $ipBinary,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_document_access_sessions
                (supplier_id, link_id, session_hash, expires_at, created_at, last_seen_at, ip)
             VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                     UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)',
        )->execute([$supplierId, $linkId, $sessionHash, $ttlSeconds, $ipBinary]);
    }

    public function touchValidSession(
        int $supplierId,
        int $linkId,
        string $sessionHash,
    ): bool {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM payroll_document_access_sessions
              WHERE supplier_id = ? AND link_id = ? AND session_hash = ?
                AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
        );
        $stmt->execute([$supplierId, $linkId, $sessionHash]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        $pdo->prepare(
            'UPDATE payroll_document_access_sessions
                SET last_seen_at = UTC_TIMESTAMP()
              WHERE supplier_id = ? AND id = ?',
        )->execute([$supplierId, (int) $id]);
        return true;
    }

    /** Retenční úklid — volá se z cron-cleanup. */
    public function pruneExpired(): int
    {
        $pdo = $this->db->pdo();
        $codes = $pdo->exec(
            'DELETE FROM payroll_document_access_codes
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)',
        );
        $sessions = $pdo->exec(
            'DELETE FROM payroll_document_access_sessions
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
        );
        return (int) $codes + (int) $sessions;
    }

    private const COLUMNS = 'id, supplier_id, payroll_document_id, employee_id,
                    token_hash, recipient_email_hash, recipient_masked,
                    dispatch_state, attempt_count, next_attempt_at, lease_token,
                    leased_until, last_error_code, idempotency_key, expires_at,
                    sent_at, revoked_at, first_downloaded_at, last_downloaded_at,
                    download_count, created_by, created_at,
                    (revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()) AS is_live';

    private function finish(PDO $pdo, bool $ownsTransaction): void
    {
        $ownsTransaction
            ? $pdo->commit()
            : $pdo->exec('RELEASE SAVEPOINT payroll_document_access_claim');
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function cast(array $row): array
    {
        foreach ([
            'id', 'supplier_id', 'payroll_document_id', 'employee_id',
            'attempt_count', 'download_count',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['created_by'] = isset($row['created_by']) ? (int) $row['created_by'] : null;
        $row['is_live'] = (bool) ($row['is_live'] ?? false);
        return $row;
    }
}
