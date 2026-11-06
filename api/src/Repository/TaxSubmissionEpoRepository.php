<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class TaxSubmissionEpoRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Kdy má smysl nabídnout „Obnovit stav".
     *
     * Musí odpovídat větvím, které {@see \MyInvoice\Service\Epo\EpoDirectSubmissionService::refreshStatus}
     * skutečně umí zpracovat:
     *   - dotaz na stav podání (podací číslo + heslo) nebo vyzvednutí off-line výsledku,
     *   - ZNOVUOVĚŘENÍ bezpečně uložené potvrzenky, která poprvé neprošla.
     *
     * Ta poslední větev v podmínce chyběla a byla to past. Potvrzenka se ukládá DŘÍV, než
     * se zapíše podací číslo a heslo — pokus, u kterého ověření selhalo, tedy nikdy nemá
     * `remote_submission_ref`, tlačítko se nezobrazilo a jediná cesta ven vedla přes ruční
     * nahrání souboru, který uživatel nemá odkud vzít (leží zašifrovaný v databázi).
     * Přesně to potkalo ostré kontrolní hlášení, kde na serveru chyběl CA bundle:
     * podání bylo přijaté, aplikace o tom měla důkaz, a přesto ho nešlo dotáhnout.
     */
    private const REFRESH_AVAILABLE_SQL =
        "((remote_submission_ref IS NOT NULL AND state_password_ciphertext IS NOT NULL)
           OR (offline_transfer_id IS NOT NULL AND offline_password_ciphertext IS NOT NULL)
           OR (status = 'uncertain'
               AND error_code IN ('invalid_confirmation', 'confirmation_trust_store_unavailable')
               AND confirmation_ciphertext IS NOT NULL
               AND submitted_signed_ciphertext IS NOT NULL))";

    /** @return array{vat_root_folder_id:?int,income_tax_root_folder_id:?int} */
    public function settings(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_root_folder_id, income_tax_root_folder_id
               FROM tax_submission_settings
              WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'vat_root_folder_id' => $row !== false && $row['vat_root_folder_id'] !== null
                ? (int) $row['vat_root_folder_id']
                : null,
            'income_tax_root_folder_id' => $row !== false && $row['income_tax_root_folder_id'] !== null
                ? (int) $row['income_tax_root_folder_id']
                : null,
        ];
    }

    public function saveSettings(
        int $supplierId,
        ?int $vatRootFolderId,
        ?int $incomeTaxRootFolderId,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_settings
                (supplier_id, vat_root_folder_id, income_tax_root_folder_id, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                vat_root_folder_id = VALUES(vat_root_folder_id),
                income_tax_root_folder_id = VALUES(income_tax_root_folder_id),
                updated_by = VALUES(updated_by)'
        )->execute([$supplierId, $vatRootFolderId, $incomeTaxRootFolderId, $userId]);
    }

    /** @return array<string,mixed>|null */
    public function lockSubmission(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tax_submissions
              WHERE id = ? AND supplier_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function expireAttempts(int $submissionId, int $supplierId): void
    {
        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'expired'
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND status IN ('prepared','handoff_created','awaiting_confirmation')
                AND (
                    (handoff_expires_at IS NOT NULL AND handoff_expires_at <= CURRENT_TIMESTAMP)
                    OR (status = 'prepared' AND requested_at <= CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
                )"
        )->execute([$submissionId, $supplierId]);
    }

    /** @return array<string,mixed>|null */
    public function activeAttempt(
        int $submissionId,
        int $supplierId,
        string $environment = 'production',
    ): ?array
    {
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND (epo_environment = 'production' OR epo_environment = ?)
                AND (
                  (channel = 'epo_assisted'
                    AND status IN ('prepared','handoff_created','awaiting_confirmation')
                    AND (handoff_expires_at IS NULL
                      OR handoff_expires_at > CURRENT_TIMESTAMP))
                  OR
                  (channel = 'epo_direct'
                    AND (
                      status IN ('submitting','processing','uncertain')
                      OR (status = 'confirmed' AND epo_environment = 'production')
                    ))
                )
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId, $environment]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeAttempt($row) : null;
    }

    /**
     * Zpráva datové schránky, která snapshot drží: zařazená, odesílaná, nejistá nebo
     * odeslaná. Jeden snapshot = jeden kanál — dokud tahle zpráva existuje, EPO cesta
     * snapshot nepodá a naopak. Zrušená a neúspěšná zpráva snapshot uvolňuje.
     *
     * @return array<string,mixed>|null
     */
    public function activeIsdsDispatch(int $submissionId, int $supplierId): ?array
    {
        if (!$this->db->hasTable('submission_outbox')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, dispatch_state, dispatch_mode, recipient_id, recipient_box_id,
                    external_message_id, sent_at, delivered_at
               FROM submission_outbox
              WHERE supplier_id = ? AND artifact_kind = 'tax_submission' AND artifact_id = ?
                AND channel = 'isds' AND environment = 'production'
                AND dispatch_state IN ('ready','sending','send_uncertain','sent','delivered')
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function insertAttempt(
        int $supplierId,
        int $submissionId,
        string $idempotencyKey,
        string $requestSha256,
        ?int $userId,
        string $environment,
    ): int {
        (new \MyInvoice\Service\Submission\AnonymizedCloneSubmissionProvenance($this->db))->assertDispatchable($supplierId, $submissionId);
        $environment = $this->normalizeEnvironment($environment);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_attempts
                (supplier_id, tax_submission_id, epo_environment,
                 idempotency_key, request_sha256, requested_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $supplierId,
            $submissionId,
            $environment,
            $idempotencyKey,
            $requestSha256,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function markHandoffCreated(int $attemptId, int $httpStatus, string $expiresAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'awaiting_confirmation',
                    response_http_status = ?,
                    handoff_expires_at = ?,
                    error_code = NULL,
                    error_message = NULL
              WHERE id = ? AND status = 'prepared'"
        );
        $stmt->execute([$httpStatus, $expiresAt, $attemptId]);
        return $stmt->rowCount() > 0;
    }

    public function markAttemptFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        ?int $httpStatus,
    ): bool {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'failed',
                    response_http_status = ?,
                    error_code = ?,
                    error_message = ?
              WHERE id = ? AND status = 'prepared'"
        );
        $stmt->execute([$httpStatus, $errorCode, mb_substr($errorMessage, 0, 500), $attemptId]);
        return $stmt->rowCount() > 0;
    }

    public function cancelActiveAttempt(int $attemptId, int $submissionId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'cancelled'
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                AND status IN ('prepared','handoff_created','awaiting_confirmation')"
        );
        $stmt->execute([$attemptId, $submissionId, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Poslední asistované předání snapshotu.
     *
     * Vazba dodejky na konkrétní pokus je prvotně z `attempt_id` v uploadu. Když chybí
     * (účetní nahrává soubory k podání, které se v aplikaci nezakládalo přes tlačítko),
     * je nejblíž pravdě poslední asistovaný pokus — jiný kanál by dodejce neseděl
     * a vymyslet nový pokus by znamenalo zapsat historii, která se nestala.
     *
     * @return array<string,mixed>|null
     */
    public function latestAssistedAttempt(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, status, epo_environment, remote_submission_ref, submitted_at
               FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ? AND channel = 'epo_assisted'
                AND status NOT IN ('cancelled','failed','expired')
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /**
     * Zapíše k asistovanému pokusu, co se přečetlo z ručně nahrané dodejky.
     *
     * Heslo pro dotaz na stav se přepisuje jen tehdy, když nějaké přišlo — opakované
     * nahrání téhož souboru už ho nenese (z metadat artefaktu se zásadně neukládá),
     * a přepsat ho NULLem by znamenalo přijít o jedinou kopii, kterou EPO vydává jednou.
     *
     * Stav pokusu se ZÁMĚRNĚ nemění: nahrání dodejky je důkaz, ne právní úkon. Podání
     * označí za podané až účetní přes `TaxSubmissionAction::submit()`.
     */
    public function recordAssistedConfirmation(
        int $attemptId,
        int $submissionId,
        int $supplierId,
        string $reference,
        string $submittedAt,
        ?string $statePasswordCiphertext,
    ): bool {
        // Existence se ověřuje zvlášť: MySQL u UPDATE hlásí POČET ZMĚNĚNÝCH řádků, takže
        // opakované nahrání téže dodejky (stejné hodnoty) by vrátilo 0 a volající by si
        // myslel, že pokus neexistuje — a přestal by ho v odpovědi nabízet.
        $guard = $this->db->pdo()->prepare(
            "SELECT id FROM tax_submission_attempts
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                AND channel = 'epo_assisted'"
        );
        $guard->execute([$attemptId, $submissionId, $supplierId]);
        if ($guard->fetchColumn() === false) {
            return false;
        }

        $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET remote_submission_ref = ?,
                    submitted_at = ?,
                    state_password_ciphertext = COALESCE(?, state_password_ciphertext)
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?
                AND channel = 'epo_assisted'"
        )->execute([
            mb_substr($reference, 0, 100),
            $submittedAt,
            $statePasswordCiphertext,
            $attemptId,
            $submissionId,
            $supplierId,
        ]);
        return true;
    }

    public function markAttemptConfirmed(int $attemptId, string $confirmedAt): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'confirmed', confirmed_at = ?
              WHERE id = ? AND status = 'awaiting_confirmation'"
        );
        $stmt->execute([$confirmedAt, $attemptId]);
        return $stmt->rowCount() > 0;
    }

    /** @return array{id:int,status:string,epo_environment:string}|null */
    public function latestConfirmableAttempt(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, status, epo_environment FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['status'] !== 'awaiting_confirmation') {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'epo_environment' => $this->normalizeEnvironment((string) $row['epo_environment']),
        ];
    }

    /**
     * Snapshot, který uživatel (nebo potvrzené předání) označil za podaný.
     * Tvrdá zábrana — z tohohle stavu se ven nedostane nic než oprava dat.
     */
    private const SUBMITTED_SNAPSHOT_STATUSES = ['submitted', 'accepted'];

    /**
     * Stavy pokusu, ve kterých EPO podání PROKAZATELNĚ převzalo.
     *
     * `rejected` je tu záměrně: odmítnutí je odpověď OSTRÉHO podání (`test=0`), tedy
     * doklad o tom, že se na EPO reálně komunikovalo, a patří k němu archivovaný chybový
     * XML. Naproti tomu neúspěšný test končí jako `test_failed` a nedokazuje nic.
     */
    private const DELIVERED_ATTEMPT_STATUSES = [
        'submitted',
        'processing',
        'confirmed',
        'rejected',
    ];

    /**
     * Stavy pokusu, u kterých aplikace NEVÍ, jestli podání odešlo.
     *
     * `prepared`/`handoff_created`/`awaiting_confirmation` = asistované předání; uživatel
     * dostal odkaz do portálu EPO a mohl tam podat, aniž by se to sem vrátilo.
     * `submitting` = request je na drátě, `uncertain` = odpověď nešla vyhodnotit.
     * Blokují mazání, ale jde je vědomě uzavřít jako „nepodáno" ({@see releaseUnresolvedAttempts()}).
     */
    private const UNRESOLVED_ATTEMPT_STATUSES = [
        'prepared',
        'handoff_created',
        'awaiting_confirmation',
        'submitting',
        'uncertain',
    ];

    /**
     * Artefakty, které samy o sobě dokazují přijetí podání na straně EPO.
     *
     * `source_xml`, `epo_xml`, `signed_submission_p7s` ani `epo_error_xml` mezi ně NEPATŘÍ:
     * vznikají i při testu (`test=1`), na který EPO odpovídá „Podání nebylo přijato, protože
     * bylo odesláno v testovacím režimu." Právě tahle záměna zamykala testované snapshoty.
     */
    private const EVIDENCE_ARTIFACT_KINDS = [
        'confirmation_p7s', 'receipt_pdf', 'epo_status_xml', 'isds_delivery_zfo',
    ];

    /** Snapshot je označený jako podaný. */
    public const BLOCK_SUBMITTED = 'submitted_snapshot';

    /** Existuje důkaz, že EPO podání převzalo (doručenka, podací číslo, potvrzený pokus). */
    public const BLOCK_DELIVERED = 'delivered_attempt';

    /** Existuje pokus, u kterého nevíme, jak dopadl. Uživatel ho může vědomě uzavřít. */
    public const BLOCK_UNRESOLVED = 'unresolved_attempt';

    /**
     * Snapshot čeká ve frontě datové schránky nebo se jeho odeslání nedořešilo. Uvolní ho
     * jen zrušení zprávy ve frontě, případně dořešení nejistého odeslání — ne mazání.
     */
    public const BLOCK_ISDS_PENDING = 'isds_pending';

    /**
     * Proč nejde snapshot smazat — `null` = nic nebrání.
     *
     * ── Proč to není „existuje řádek v tax_submission_attempts" ─────────────────────
     * Původní `hasEvidence()` blokovalo mazání, jakmile u snapshotu existoval JAKÝKOLI
     * pokus. Jenže pokus vzniká i pro test EPO (`test=1`), který se z principu nikdy
     * nepodá — EPO na něj odpovídá `TEST_REZIM`. Otestovaný snapshot tak šlo zamknout
     * navždy, aniž by kdy cokoli odešlo.
     *
     * Rozlišujeme proto tři situace a každou jinak:
     *   1. prokazatelně odešlo  → {@see BLOCK_SUBMITTED} / {@see BLOCK_DELIVERED}, natvrdo;
     *      je to zákonný důkaz podání a mazat se nesmí ani se souhlasem uživatele,
     *   2. nevíme                → {@see BLOCK_UNRESOLVED}, blokuje, ale jde uzavřít jako
     *      „nepodáno" (uživatel to vědomě potvrdí, uzavření se zapíše do auditu),
     *   3. prokazatelně neodešlo → NEBLOKUJE. Sem patří `testing`/`test_passed`/`test_failed`
     *      (test EPO), `failed` (pád před odesláním – po odeslání se stav vždy zapisuje jako
     *      `uncertain`, ne `failed`), `expired` (propadlé předání) a `cancelled` (vědomě uzavřené).
     *
     * @return self::BLOCK_*|null
     */
    public function deletionBlocker(int $submissionId, int $supplierId): ?string
    {
        return $this->deletionBlockers([$submissionId], $supplierId)[$submissionId] ?? null;
    }

    /**
     * Batchovaná varianta {@see deletionBlocker()} — jediné místo, kde pravidlo žije.
     * Používá ji i {@see enrich()}, aby UI hlásilo přesně to, co brána skutečně udělá.
     *
     * @param list<int> $submissionIds
     * @return array<int,?string> id snapshotu → self::BLOCK_*|null
     */
    public function deletionBlockers(array $submissionIds, int $supplierId): array
    {
        $ids = array_values(array_unique(array_filter($submissionIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $idIn = implode(',', array_fill(0, count($ids), '?'));
        $submittedIn = implode(',', array_fill(0, count(self::SUBMITTED_SNAPSHOT_STATUSES), '?'));
        $deliveredIn = implode(',', array_fill(0, count(self::DELIVERED_ATTEMPT_STATUSES), '?'));
        $kindIn = implode(',', array_fill(0, count(self::EVIDENCE_ARTIFACT_KINDS), '?'));
        $unresolvedIn = implode(',', array_fill(0, count(self::UNRESOLVED_ATTEMPT_STATUSES), '?'));

        // Tvrdý důkaz doručení nehlídáme jen podle stavu: `submitted_at`, `confirmed_at`,
        // `remote_submission_ref` i `offline_transfer_id` se zapisují VÝHRADNĚ z odpovědi
        // ostrého podání (stageConfirmation / recordConfirmed / recordOffline), takže
        // pravidlo platí, i kdyby se enum stavů někdy rozšířil.
        // Artefakty se schválně NEfiltrují přes `documents.deleted_at` — doručenka
        // přesunutá do koše je pořád důkaz, že podání odešlo.
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id,
                    (s.status IN ($submittedIn)) AS submitted_snapshot,
                    (EXISTS(
                        SELECT 1 FROM tax_submission_attempts a
                         WHERE a.tax_submission_id = s.id AND a.supplier_id = s.supplier_id
                           AND (
                                a.status IN ($deliveredIn)
                             OR a.submitted_at IS NOT NULL
                             OR a.confirmed_at IS NOT NULL
                             OR a.remote_submission_ref IS NOT NULL
                             OR a.offline_transfer_id IS NOT NULL
                           )
                    )
                    OR EXISTS(
                        SELECT 1 FROM tax_submission_artifacts f
                         WHERE f.tax_submission_id = s.id AND f.supplier_id = s.supplier_id
                           AND f.artifact_kind IN ($kindIn)
                    )
                    OR EXISTS(
                        SELECT 1 FROM submission_outbox o
                         WHERE o.supplier_id = s.supplier_id
                           AND o.artifact_kind = 'tax_submission' AND o.artifact_id = s.id
                           AND (o.external_message_id IS NOT NULL
                                OR o.dispatch_state IN ('sent','delivered'))
                    )) AS delivered,
                    EXISTS(
                        SELECT 1 FROM tax_submission_attempts u
                         WHERE u.tax_submission_id = s.id AND u.supplier_id = s.supplier_id
                           AND u.status IN ($unresolvedIn)
                    ) AS unresolved,
                    EXISTS(
                        SELECT 1 FROM submission_outbox q
                         WHERE q.supplier_id = s.supplier_id
                           AND q.artifact_kind = 'tax_submission' AND q.artifact_id = s.id
                           AND q.dispatch_state IN ('ready','sending','send_uncertain')
                    ) AS isds_pending
               FROM tax_submissions s
              WHERE s.supplier_id = ? AND s.id IN ($idIn)"
        );
        $stmt->execute([
            ...self::SUBMITTED_SNAPSHOT_STATUSES,
            ...self::DELIVERED_ATTEMPT_STATUSES,
            ...self::EVIDENCE_ARTIFACT_KINDS,
            ...self::UNRESOLVED_ATTEMPT_STATUSES,
            $supplierId,
            ...$ids,
        ]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['id']] = match (true) {
                (bool) $row['submitted_snapshot'] => self::BLOCK_SUBMITTED,
                (bool) $row['delivered'] => self::BLOCK_DELIVERED,
                (bool) $row['isds_pending'] => self::BLOCK_ISDS_PENDING,
                (bool) $row['unresolved'] => self::BLOCK_UNRESOLVED,
                default => null,
            };
        }

        return $out;
    }

    /**
     * Pokusy, u kterých aplikace neví, jestli podání odešlo.
     *
     * @return list<array{id:int,channel:string,status:string,epo_environment:string,requested_at:string}>
     */
    public function unresolvedAttempts(int $submissionId, int $supplierId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::UNRESOLVED_ATTEMPT_STATUSES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, channel, status, epo_environment, requested_at
               FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND status IN ($placeholders)
              ORDER BY id"
        );
        $stmt->execute([$submissionId, $supplierId, ...self::UNRESOLVED_ATTEMPT_STATUSES]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'channel' => (string) $row['channel'],
                'status' => (string) $row['status'],
                'epo_environment' => (string) $row['epo_environment'],
                'requested_at' => (string) $row['requested_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Vědomé uzavření nedořešených pokusů jako „nepodáno / zrušeno".
     *
     * Nesahá na pokusy s tvrdým důkazem doručení — ty odfiltruje stejná podmínka jako
     * {@see deletionBlocker()}, takže tudy nejde obejít blokaci skutečného podání.
     */
    public function releaseUnresolvedAttempts(
        int $submissionId,
        int $supplierId,
        ?int $resolvedBy,
        ?string $note,
    ): int {
        $placeholders = implode(',', array_fill(0, count(self::UNRESOLVED_ATTEMPT_STATUSES), '?'));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE tax_submission_attempts
                SET status = 'cancelled',
                    resolution_code = ?,
                    resolution_note = ?,
                    resolved_by = ?,
                    resolved_at = CURRENT_TIMESTAMP,
                    next_poll_at = NULL
              WHERE tax_submission_id = ? AND supplier_id = ?
                AND status IN ($placeholders)
                AND submitted_at IS NULL
                AND confirmed_at IS NULL
                AND remote_submission_ref IS NULL
                AND offline_transfer_id IS NULL"
        );
        $stmt->execute([
            // Rozlišujeme, co se skutečně stalo. Poznámka znamená, že uživatel ověřil
            // v portálu EPO, že podání nevzniklo. Bez ní víme jen to, že smazání
            // vědomě potvrdil — tvrdit v auditní stopě „ověřeno" by bylo nepravdivé.
            $note !== null && trim($note) !== '' ? 'verified_not_submitted' : 'discarded_by_user',
            $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            $resolvedBy,
            $submissionId,
            $supplierId,
            ...self::UNRESOLVED_ATTEMPT_STATUSES,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Fyzické smazání snapshotu.
     *
     * `$acknowledgeNote` je vědomé potvrzení uživatele, že nedořešené pokusy nakonec
     * podané nebyly. Bez něj {@see BLOCK_UNRESOLVED} blokuje dál; tvrdé blokace
     * ({@see BLOCK_SUBMITTED}, {@see BLOCK_DELIVERED}) neuvolní ani s ním.
     *
     * Vrací i počty navázaných řádků, které smazání strhne s sebou (FK ON DELETE CASCADE),
     * aby je volající mohl zapsat do auditní stopy — po commitu už je nikde nepřečte.
     *
     * @return array{result:'deleted'|'blocked'|'not_found', blocker:?string,
     *               purged:array{attempts:int,artifacts:int,status_events:int},
     *               released_attempts:int}
     */
    public function deleteSubmission(
        int $submissionId,
        int $supplierId,
        ?int $resolvedBy = null,
        ?string $acknowledgeNote = null,
    ): array {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $blocked = static fn (?string $code): array => [
                'result' => $code === null ? 'not_found' : 'blocked',
                'blocker' => $code,
                'purged' => ['attempts' => 0, 'artifacts' => 0, 'status_events' => 0],
                'released_attempts' => 0,
            ];

            if ($this->lockSubmission($submissionId, $supplierId) === null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $blocked(null);
            }

            $released = 0;
            $blocker = $this->deletionBlocker($submissionId, $supplierId);
            if ($blocker === self::BLOCK_UNRESOLVED) {
                $released = $this->releaseUnresolvedAttempts(
                    $submissionId,
                    $supplierId,
                    $resolvedBy,
                    $acknowledgeNote,
                );
                $blocker = $this->deletionBlocker($submissionId, $supplierId);
            }
            if ($blocker !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $blocked($blocker);
            }

            $purged = $this->relatedRowCounts($submissionId, $supplierId);

            $stmt = $pdo->prepare(
                'DELETE FROM tax_submissions WHERE id = ? AND supplier_id = ?'
            );
            $stmt->execute([$submissionId, $supplierId]);
            $deleted = $stmt->rowCount() > 0;
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'result' => $deleted ? 'deleted' : 'not_found',
                'blocker' => null,
                'purged' => $purged,
                'released_attempts' => $released,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Co smazání snapshotu strhne s sebou přes `ON DELETE CASCADE`.
     *
     * Dokumenty v DMS mezi tím NEJSOU: `fk_tart_document` míří opačným směrem
     * (smazaný dokument ruší vazbu, ne naopak), takže soubory zůstávají v archivu.
     *
     * @return array{attempts:int,artifacts:int,status_events:int}
     */
    private function relatedRowCounts(int $submissionId, int $supplierId): array
    {
        $counts = [];
        foreach ([
            'attempts' => 'tax_submission_attempts',
            'artifacts' => 'tax_submission_artifacts',
            'status_events' => 'tax_submission_status_events',
        ] as $key => $table) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM $table WHERE tax_submission_id = ? AND supplier_id = ?"
            );
            $stmt->execute([$submissionId, $supplierId]);
            $counts[$key] = (int) $stmt->fetchColumn();
        }

        return $counts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function attempts(int $submissionId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, channel, epo_environment, status, request_sha256, signing_credential_id,
                    signing_fingerprint, response_http_status, test_passed,
                    test_messages_json, tested_at, error_code, error_message,
                    requested_by, requested_at, handoff_expires_at, submitted_at,
                    remote_submission_ref, last_status_json, last_status_at,
                    confirmed_at, poll_count, next_poll_at,
                    resolution_code, resolution_note, resolved_by, resolved_at,
                    (remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL) AS status_query_available,
                    ' . self::REFRESH_AVAILABLE_SQL . ' AS refresh_available,
                    (submitted_signed_ciphertext IS NOT NULL) AS confirmation_recovery_available,
                    updated_at
               FROM tax_submission_attempts
              WHERE tax_submission_id = ? AND supplier_id = ?
              ORDER BY id DESC'
        );
        $stmt->execute([$submissionId, $supplierId]);
        return array_map(
            fn (array $row): array => $this->normalizeAttempt($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Přepíše výsledek ověření u JIŽ ULOŽENÉHO artefaktu.
     *
     * Soubor se nemění (klíč je hash obsahu), mění se to, co o něm aplikace ví. Metadata
     * se totiž odvozují kódem, a ten se vyvíjí: dodejka archivovaná dřív, než uměla
     * aplikace vytáhnout podací číslo nebo identitu podepisujícího, by jinak zůstala
     * navždy bez nich a detail podání by tvářil, že v ní nic není.
     *
     * @param array<string,mixed>|null $verification
     */
    public function refreshArtifactVerification(
        int $artifactId,
        int $supplierId,
        string $verificationStatus,
        ?array $verification,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tax_submission_artifacts
                SET verification_status = ?, verification_json = ?
              WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([
            $verificationStatus,
            $verification === null || $verification === []
                ? null
                : json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $artifactId,
            $supplierId,
        ]);
    }

    public function addArtifact(
        int $supplierId,
        int $submissionId,
        ?int $attemptId,
        int $documentId,
        string $kind,
        string $sha256,
        string $verificationStatus,
        ?array $verification,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tax_submission_artifacts
                (supplier_id, tax_submission_id, attempt_id, document_id, artifact_kind,
                 sha256, verification_status, verification_json, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                attempt_id = VALUES(attempt_id),
                document_id = VALUES(document_id),
                verification_status = VALUES(verification_status),
                verification_json = VALUES(verification_json),
                uploaded_by = VALUES(uploaded_by),
                created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            $supplierId,
            $submissionId,
            $attemptId,
            $documentId,
            $kind,
            $sha256,
            $verificationStatus,
            $verification !== null
                ? json_encode($verification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            $userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function attemptBelongsToSubmission(int $attemptId, int $submissionId, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM tax_submission_attempts
              WHERE id = ? AND tax_submission_id = ? AND supplier_id = ?'
        );
        $stmt->execute([$attemptId, $submissionId, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    public function artifact(int $artifactId, int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.tax_submission_id, a.document_id,
                    d.sha256 AS document_sha256, d.filename, d.original_name,
                    d.mime_type, d.doc_type, d.size_bytes
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.id = ? AND a.tax_submission_id = ? AND a.supplier_id = ?
                AND d.deleted_at IS NULL'
        );
        $stmt->execute([$artifactId, $submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function artifactByKindAndSha(
        int $submissionId,
        int $supplierId,
        string $kind,
        string $sha256,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND a.artifact_kind = ? AND a.sha256 = ?
                AND d.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$submissionId, $supplierId, $kind, $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeArtifact($row) : null;
    }

    /** @return array<string,mixed>|null */
    public function sourceArtifact(int $submissionId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND a.artifact_kind = 'source_xml'
                AND d.deleted_at IS NULL
              ORDER BY a.id DESC LIMIT 1"
        );
        $stmt->execute([$submissionId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeArtifact($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function artifacts(int $submissionId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id
              WHERE a.tax_submission_id = ? AND a.supplier_id = ?
                AND d.deleted_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $stmt->execute([$submissionId, $supplierId]);
        return array_map(
            fn (array $row): array => $this->normalizeArtifact($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Doplní list snapshotů o pokusy a artefakty bez N+1 dotazů.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public function enrich(array $rows, int $supplierId): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $rows,
        )));
        if ($ids === []) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = [$supplierId, ...$ids];

        $attemptStmt = $this->db->pdo()->prepare(
            "SELECT id, tax_submission_id, channel, epo_environment, status, request_sha256,
                    signing_credential_id, signing_fingerprint, response_http_status,
                    test_passed, test_messages_json, tested_at,
                    error_code, error_message, requested_by, requested_at,
                    handoff_expires_at, submitted_at, remote_submission_ref,
                    last_status_json, last_status_at, confirmed_at,
                    poll_count, next_poll_at,
                    resolution_code, resolution_note, resolved_by, resolved_at,
                    (remote_submission_ref IS NOT NULL
                      AND state_password_ciphertext IS NOT NULL) AS status_query_available,
                    " . self::REFRESH_AVAILABLE_SQL . " AS refresh_available,
                    (submitted_signed_ciphertext IS NOT NULL) AS confirmation_recovery_available,
                    updated_at
               FROM tax_submission_attempts
              WHERE supplier_id = ? AND tax_submission_id IN ($placeholders)
              ORDER BY id DESC"
        );
        $attemptStmt->execute($params);
        $attempts = [];
        foreach ($attemptStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attempt) {
            $attempts[(int) $attempt['tax_submission_id']][] = $this->normalizeAttempt($attempt);
        }

        $artifactStmt = $this->db->pdo()->prepare(
            "SELECT a.*, d.title, d.original_name, d.size_bytes, d.doc_type, d.folder_id
               FROM tax_submission_artifacts a
               JOIN documents d ON d.id = a.document_id AND d.deleted_at IS NULL
              WHERE a.supplier_id = ? AND a.tax_submission_id IN ($placeholders)
              ORDER BY a.created_at DESC, a.id DESC"
        );
        $artifactStmt->execute($params);
        $artifacts = [];
        foreach ($artifactStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $artifact) {
            $artifacts[(int) $artifact['tax_submission_id']][] = $this->normalizeArtifact($artifact);
        }

        // Důvod blokace počítá stejná brána, která mazání skutečně provádí — UI tak
        // nemůže nabídnout tlačítko, které backend odmítne, ani skrýt to, co by prošlo.
        $blockers = $this->deletionBlockers($ids, $supplierId);

        // Poslední zpráva datové schránky ke snapshotu (i zrušená nebo neúspěšná — UI
        // podle ní nabídne nové zařazení). Stav drží fronta, tady se jen promítá.
        $dispatchStmt = $this->db->pdo()->prepare(
            "SELECT o.id, o.artifact_id, o.dispatch_state, o.dispatch_mode, o.recipient_id,
                    o.recipient_box_id, r.name AS recipient_name, o.subject,
                    o.correlation_reference, o.external_message_id, o.sent_at, o.delivered_at,
                    o.receipt_document_id, o.receipt_attached_at,
                    o.last_error_code, o.last_error_message, o.created_at
               FROM submission_outbox o
               LEFT JOIN submission_recipients r ON r.id = o.recipient_id
              WHERE o.supplier_id = ? AND o.artifact_kind = 'tax_submission'
                AND o.channel = 'isds' AND o.artifact_id IN ($placeholders)
              ORDER BY o.id DESC"
        );
        $dispatchStmt->execute($params);
        $dispatches = [];
        foreach ($dispatchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $dispatch) {
            $submissionId = (int) $dispatch['artifact_id'];
            if (isset($dispatches[$submissionId])) {
                continue;
            }
            $dispatch['id'] = (int) $dispatch['id'];
            $dispatch['recipient_id'] = $dispatch['recipient_id'] !== null ? (int) $dispatch['recipient_id'] : null;
            $dispatch['receipt_document_id'] = $dispatch['receipt_document_id'] !== null
                ? (int) $dispatch['receipt_document_id']
                : null;
            unset($dispatch['artifact_id']);
            $dispatches[$submissionId] = $dispatch;
        }

        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['attempts'] = $attempts[$id] ?? [];
            $row['artifacts'] = $artifacts[$id] ?? [];
            $row['isds_dispatch'] = $dispatches[$id] ?? null;
            $blocker = $blockers[$id] ?? null;
            $row['delete_blocker'] = $blocker;
            $row['deletable'] = $blocker === null;
            // Nedořešené předání smí uživatel vědomě uzavřít jako „nepodáno" a teprve
            // pak mazat; u tvrdého důkazu podání žádná cesta ven neexistuje.
            $row['delete_needs_acknowledgement'] = $blocker === self::BLOCK_UNRESOLVED;
        }
        unset($row);

        return $rows;
    }

    private function normalizeAttempt(array $row): array
    {
        $row['id'] = (int) $row['id'];
        if (isset($row['tax_submission_id'])) {
            $row['tax_submission_id'] = (int) $row['tax_submission_id'];
        }
        $row['response_http_status'] = $row['response_http_status'] !== null
            ? (int) $row['response_http_status']
            : null;
        if (array_key_exists('signing_credential_id', $row)) {
            $row['signing_credential_id'] = $row['signing_credential_id'] !== null
                ? (int) $row['signing_credential_id']
                : null;
        }
        if (array_key_exists('test_passed', $row)) {
            $row['test_passed'] = $row['test_passed'] !== null
                ? (bool) $row['test_passed']
                : null;
        }
        if (array_key_exists('test_messages_json', $row)) {
            $row['test_messages'] = $row['test_messages_json'] !== null
                ? (json_decode((string) $row['test_messages_json'], true) ?: [])
                : [];
            unset($row['test_messages_json']);
        }
        if (array_key_exists('last_status_json', $row)) {
            $row['remote_status'] = $row['last_status_json'] !== null
                ? (json_decode((string) $row['last_status_json'], true) ?: null)
                : null;
            unset($row['last_status_json']);
        }
        if (array_key_exists('poll_count', $row)) {
            $row['poll_count'] = (int) $row['poll_count'];
        }
        if (array_key_exists('resolved_by', $row)) {
            $row['resolved_by'] = $row['resolved_by'] !== null ? (int) $row['resolved_by'] : null;
        }
        if (array_key_exists('status_query_available', $row)) {
            $row['status_query_available'] = (bool) $row['status_query_available'];
        }
        if (array_key_exists('refresh_available', $row)) {
            $row['refresh_available'] = (bool) $row['refresh_available'];
        }
        if (array_key_exists('confirmation_recovery_available', $row)) {
            $row['confirmation_recovery_available'] = (bool) $row['confirmation_recovery_available'];
        }
        $row['requested_by'] = $row['requested_by'] !== null ? (int) $row['requested_by'] : null;
        unset($row['idempotency_key']);
        return $row;
    }

    private function normalizeArtifact(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tax_submission_id'] = (int) $row['tax_submission_id'];
        $row['attempt_id'] = $row['attempt_id'] !== null ? (int) $row['attempt_id'] : null;
        $row['document_id'] = (int) $row['document_id'];
        $row['uploaded_by'] = $row['uploaded_by'] !== null ? (int) $row['uploaded_by'] : null;
        if (isset($row['size_bytes'])) {
            $row['size_bytes'] = (int) $row['size_bytes'];
        }
        if (array_key_exists('folder_id', $row)) {
            $row['folder_id'] = $row['folder_id'] !== null ? (int) $row['folder_id'] : null;
        }
        $row['verification'] = $row['verification_json'] !== null
            ? (json_decode((string) $row['verification_json'], true) ?: null)
            : null;
        unset($row['verification_json']);
        return $row;
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
