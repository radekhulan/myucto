<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Submission;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use PDOException;

/**
 * Příchozí zprávy z datové schránky + stav dotazování (migrace 1381).
 *
 * `submission_inbox_polls` je tu proto, aby prázdný seznam a nefunkční dotaz
 * nevypadaly stejně. Bez toho záznamu znamená „0 nových zpráv" obojí a nikdo
 * si nevšimne, že se vyzvedávání protokolů zastavilo.
 */
final class SubmissionInboxRepository
{
    private const TABLE = 'submission_inbox_messages';
    private const POLL_TABLE = 'submission_inbox_polls';

    private const COLUMNS = 'id, supplier_id, environment, channel, external_message_id,
        sender_box_id, sender_name, subject, sender_ident, signature_status, classification, matched_outbox_id,
        document_id, delivered_at, accepted_at, raw_sha256, fetched_at, processed_at';

    /** Závěr o doručení (migrace 1394) — čte se jen tam, kde už migrace proběhla. */
    private const DELIVERY_COLUMNS = 'delivery_basis, delivered_on, fiction_statutory_on, fiction_due_on,
        fiction_days, fiction_days_source, sender_is_public_authority, delivery_resolved_at, delivery_note';

    private const PRIVACY_COLUMNS = 'hidden_at, hidden_by, local_content_state,
        local_content_purged_at, local_content_purged_by, lifecycle_row_version';

    public function __construct(private readonly Connection $db) {}

    public function isAvailable(): bool
    {
        return $this->db->hasTable(self::TABLE);
    }

    /**
     * Umí databáze uložit závěr o doručení? Bez migrace 1394 se čtení i zápis
     * mlčky přeskočí — ale volající to musí vědět, aby uživateli neukázal
     * prázdno jako „nevíme" tam, kde je to ve skutečnosti „neumíme".
     */
    public function supportsDeliveryResolution(): bool
    {
        return $this->isAvailable() && $this->db->hasColumn(self::TABLE, 'delivery_basis');
    }

    private function columns(): string
    {
        $columns = $this->supportsDeliveryResolution()
            ? self::COLUMNS . ', ' . self::DELIVERY_COLUMNS
            : self::COLUMNS;
        return $this->supportsPrivacyLifecycle()
            ? $columns . ', ' . self::PRIVACY_COLUMNS
            : $columns;
    }

    public function supportsPrivacyLifecycle(): bool
    {
        return $this->isAvailable()
            && $this->db->hasColumn(self::TABLE, 'lifecycle_row_version');
    }

    public function exists(int $supplierId, string $channel, string $environment, string $messageId): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = ? AND environment = ? AND external_message_id = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment, $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array{
     *   supplier_id:int, environment:string, channel:string, external_message_id:string,
     *   sender_box_id:?string, sender_name:?string, subject:?string, sender_ident:?string,
     *   classification:string, matched_outbox_id:?int, document_id:?int,
     *   delivered_at:?string, accepted_at:?string, raw_sha256:?string
     * } $data
     * @return array<string,mixed> Uložený řádek (nebo existující při opakovaném stažení).
     */
    public function record(array $data): array
    {
        $this->assertAvailable();
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO ' . self::TABLE . '
                    (supplier_id, environment, channel, external_message_id, sender_box_id,
                     sender_name, subject, sender_ident, classification, matched_outbox_id,
                     document_id, delivered_at, accepted_at, raw_sha256, processed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                $data['supplier_id'],
                $data['environment'],
                $data['channel'],
                $data['external_message_id'],
                $data['sender_box_id'],
                $data['sender_name'],
                $data['subject'],
                $data['sender_ident'],
                $data['classification'],
                $data['matched_outbox_id'],
                $data['document_id'],
                $data['delivered_at'],
                $data['accepted_at'],
                $data['raw_sha256'],
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // Zpráva už je stažená — opakované stažení nesmí založit druhou.
            $existing = $this->find($data['supplier_id'], $data['channel'], $data['environment'], $data['external_message_id']);
            if ($existing === null) {
                throw $e;
            }
            return $existing;
        }

        $found = $this->find($data['supplier_id'], $data['channel'], $data['environment'], $data['external_message_id']);
        if ($found === null) {
            throw new \RuntimeException('Zpráva se uložila, ale nepodařilo se ji načíst.');
        }
        return $found;
    }

    /** @return array<string,mixed>|null */
    public function find(int $supplierId, string $channel, string $environment, string $messageId): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND channel = ? AND environment = ? AND external_message_id = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment, $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? self::normalize($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listRecent(
        int $supplierId,
        string $environment,
        ?string $classification = null,
        int $limit = 100,
        string $visibility = 'active',
    ): array
    {
        $this->assertAvailable();
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT ' . $this->columns() . ' FROM ' . self::TABLE . '
                 WHERE supplier_id = ? AND environment = ?';
        $params = [$supplierId, $environment];
        if (!in_array($visibility, ['active', 'hidden', 'all'], true)) {
            throw new \InvalidArgumentException('Neznámý pohled příchozích zpráv.');
        }
        if ($this->supportsPrivacyLifecycle() && $visibility !== 'all') {
            $sql .= $visibility === 'hidden'
                ? ' AND hidden_at IS NOT NULL'
                : ' AND hidden_at IS NULL';
        }
        if ($classification !== null) {
            $sql .= ' AND classification = ?';
            $params[] = $classification;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed>|null */
    public function findById(int $supplierId, int $id): ?array
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . self::TABLE . ' WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Doručenky, které se k žádnému podání nepřiřadily.
     *
     * Nespárovaná doručenka nesmí tiše zmizet: uživatel ji nahrál, aplikace ji
     * uložila, a když ji neumí přiřadit, musí ji aspoň umět ukázat. Prázdný
     * seznam tady znamená „všechno je spárované", ne „nic jsme nedostali".
     *
     * @return list<array<string,mixed>>
     */
    public function listUnmatchedReceipts(int $supplierId, string $environment, int $limit = 100): array
    {
        $this->assertAvailable();
        $limit = max(1, min(500, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ?
                AND classification = \'delivery_receipt\' AND matched_outbox_id IS NULL
              ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$supplierId, $environment]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Naváže zprávu na podání — jen pokud ještě navázaná není.
     *
     * Podmínka `matched_outbox_id IS NULL` je zámek idempotence: druhé
     * (i souběžné) potvrzení téže doručenky nezmění nic a volající se to dozví
     * z návratové hodnoty, místo aby přepsal existující vazbu.
     */
    public function linkToOutbox(int $supplierId, int $id, int $outboxId): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET matched_outbox_id = ?, processed_at = UTC_TIMESTAMP()
              WHERE id = ? AND supplier_id = ? AND matched_outbox_id IS NULL'
        );
        $stmt->execute([$outboxId, $id, $supplierId]);

        return $stmt->rowCount() > 0;
    }

    /** Ruční zařazení zprávy, kterou automat nepoznal. */
    public function reclassify(int $supplierId, int $id, string $classification, ?int $matchedOutboxId): bool
    {
        $this->assertAvailable();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . ' SET classification = ?, matched_outbox_id = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$classification, $matchedOutboxId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<string> */
    public function privacyBlockers(int $supplierId, int $id): array
    {
        $this->assertPrivacyLifecycle();
        $row = $this->findById($supplierId, $id);
        if ($row === null) {
            return ['not_found'];
        }
        $blockers = [];
        if ((string) $row['classification'] !== 'unclassified') {
            $blockers[] = 'classified';
        }
        if ($row['matched_outbox_id'] !== null) {
            $blockers[] = 'matched_outbox';
        }
        foreach ([
            'defect_notice' => 'SELECT 1 FROM submission_defect_notices WHERE supplier_id = ? AND inbox_message_id = ? LIMIT 1',
            'receipt' => 'SELECT 1 FROM submission_outbox WHERE supplier_id = ? AND receipt_inbox_message_id = ? LIMIT 1',
        ] as $code => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId, $id]);
            if ($stmt->fetchColumn() !== false) {
                $blockers[] = $code;
            }
        }
        return $blockers;
    }

    public function setHidden(
        int $supplierId,
        int $id,
        bool $hidden,
        int $expectedVersion,
        ?int $userId,
    ): bool {
        $this->assertPrivacyLifecycle();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET hidden_at = ' . ($hidden ? 'UTC_TIMESTAMP()' : 'NULL') . ',
                    hidden_by = ?, lifecycle_row_version = lifecycle_row_version + 1
              WHERE supplier_id = ? AND id = ? AND lifecycle_row_version = ?'
        );
        $stmt->execute([$hidden ? $userId : null, $supplierId, $id, $expectedVersion]);
        return $stmt->rowCount() === 1;
    }

    public function markLocalContentPurged(
        int $supplierId,
        int $id,
        int $expectedVersion,
        ?int $userId,
    ): bool {
        $this->assertPrivacyLifecycle();
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET document_id = NULL, local_content_state = \'purged\',
                    local_content_purged_at = UTC_TIMESTAMP(),
                    local_content_purged_by = ?,
                    lifecycle_row_version = lifecycle_row_version + 1
              WHERE supplier_id = ? AND id = ? AND lifecycle_row_version = ?
                AND local_content_state = \'available\''
        );
        $stmt->execute([$userId, $supplierId, $id, $expectedVersion]);
        return $stmt->rowCount() === 1;
    }

    // ───────────────────────── rozhodný den doručení ─────────────────────────

    /**
     * Zapíše závěr o doručení (migrace 1394).
     *
     * Přepis je záměrně dovolený: závěr se mění, jak přibývají fakta — dodaná
     * zpráva nejdřív čeká (`pending`), po uplynutí lhůty se z ní stane `fiction`,
     * a když ISDS dodatečně vrátí čas přihlášení, přepíše to obojí na `login`.
     * Zamykat tenhle sloupec by znamenalo zakonzervovat první, nejméně
     * informovaný odhad. Auditní stopu drží `delivery_resolved_at` a
     * `activity_log`, ne nemožnost zápisu.
     *
     * @param array{
     *   delivery_basis:string, delivered_on:?string, fiction_statutory_on:?string,
     *   fiction_due_on:?string, fiction_days:?int, fiction_days_source:?string,
     *   sender_is_public_authority:?int, delivery_note:string
     * } $delivery
     */
    public function saveDeliveryResolution(int $supplierId, int $id, array $delivery): bool
    {
        if (!$this->supportsDeliveryResolution()) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE ' . self::TABLE . '
                SET delivery_basis = ?, delivered_on = ?, fiction_statutory_on = ?, fiction_due_on = ?,
                    fiction_days = ?, fiction_days_source = ?, sender_is_public_authority = ?,
                    delivery_resolved_at = UTC_TIMESTAMP(), delivery_note = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $delivery['delivery_basis'],
            $delivery['delivered_on'],
            $delivery['fiction_statutory_on'],
            $delivery['fiction_due_on'],
            $delivery['fiction_days'],
            $delivery['fiction_days_source'],
            $delivery['sender_is_public_authority'],
            $delivery['delivery_note'],
            $id,
            $supplierId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Zprávy, u kterých lhůta fikce běží nebo se nikdy nevyhodnotila.
     *
     * `unknown` je ve výběru schválně: zpráva, kterou aplikace neuměla posoudit,
     * se má znovu zkusit, až se doplní odesílatel do číselníku nebo se dorovná
     * migrace. Bez toho by „nevíme" bylo doživotní.
     *
     * @return list<array<string,mixed>>
     */
    public function listDeliveryPending(int $supplierId, string $environment, int $limit = 200): array
    {
        if (!$this->supportsDeliveryResolution()) {
            return [];
        }
        $limit = max(1, min(1000, $limit));
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . $this->columns() . ' FROM ' . self::TABLE . '
              WHERE supplier_id = ? AND environment = ?
                AND delivery_basis IN (\'pending\', \'unknown\')
                AND classification <> \'delivery_receipt\'
              ORDER BY id ASC LIMIT ' . $limit
        );
        $stmt->execute([$supplierId, $environment]);

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    // ───────────────────────── stav dotazování ─────────────────────────

    public function recordPollSuccess(int $supplierId, string $channel, string $environment, int $count): void
    {
        $this->assertPollAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::POLL_TABLE . '
                (supplier_id, channel, environment, last_attempt_at, last_ok_at, last_ok_count,
                 consecutive_failures, last_error_code, last_error_message)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, 0, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                last_attempt_at = UTC_TIMESTAMP(), last_ok_at = UTC_TIMESTAMP(),
                last_ok_count = VALUES(last_ok_count), consecutive_failures = 0,
                last_error_code = NULL, last_error_message = NULL'
        );
        $stmt->execute([$supplierId, $channel, $environment, $count]);
    }

    public function recordPollFailure(int $supplierId, string $channel, string $environment, string $errorCode, string $errorMessage): void
    {
        $this->assertPollAvailable();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO ' . self::POLL_TABLE . '
                (supplier_id, channel, environment, last_attempt_at, consecutive_failures,
                 last_error_code, last_error_message)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_attempt_at = UTC_TIMESTAMP(),
                consecutive_failures = consecutive_failures + 1,
                last_error_code = VALUES(last_error_code),
                last_error_message = VALUES(last_error_message)'
        );
        $stmt->execute([$supplierId, $channel, $environment, $errorCode, mb_substr($errorMessage, 0, 500)]);
    }

    /** @return array<string,mixed>|null */
    public function pollState(int $supplierId, string $channel, string $environment): ?array
    {
        if (!$this->db->hasTable(self::POLL_TABLE)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, channel, environment, last_attempt_at, last_ok_at, last_ok_count,
                    consecutive_failures, last_error_code, last_error_message
               FROM ' . self::POLL_TABLE . '
              WHERE supplier_id = ? AND channel = ? AND environment = ?'
        );
        $stmt->execute([$supplierId, $channel, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['consecutive_failures'] = (int) $row['consecutive_failures'];
        $row['last_ok_count'] = $row['last_ok_count'] !== null ? (int) $row['last_ok_count'] : null;
        return $row;
    }

    private function assertAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new \DomainException('Příchozí schránka není v databázi k dispozici (chybí migrace 1381).');
        }
    }

    private function assertPrivacyLifecycle(): void
    {
        if (!$this->supportsPrivacyLifecycle()) {
            throw new \DomainException('Soukromí příchozí ISDS není k dispozici (chybí migrace 1574).');
        }
    }

    private function assertPollAvailable(): void
    {
        if (!$this->db->hasTable(self::POLL_TABLE)) {
            throw new \DomainException('Stav dotazování schránky není v databázi k dispozici (chybí migrace 1381).');
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        foreach (['matched_outbox_id', 'document_id', 'fiction_days'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
        }
        foreach (['hidden_by', 'local_content_purged_by', 'lifecycle_row_version'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] !== null ? (int) $row[$key] : null;
            }
        }
        if (!array_key_exists('hidden_at', $row)) {
            $row['hidden_at'] = null;
            $row['hidden_by'] = null;
            $row['local_content_state'] = 'available';
            $row['local_content_purged_at'] = null;
            $row['local_content_purged_by'] = null;
            $row['lifecycle_row_version'] = 1;
        }
        if (array_key_exists('sender_is_public_authority', $row)) {
            $row['sender_is_public_authority'] = $row['sender_is_public_authority'] !== null
                ? (bool) $row['sender_is_public_authority']
                : null;
        }
        return $row;
    }
}
