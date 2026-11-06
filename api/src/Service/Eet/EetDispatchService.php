<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\NamedLockName;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetSendResult;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * Odesílání EET záznamů (durable outbox nad `eet_records`).
 *
 * Okamžitý pokus po commitu ({@see dispatchNow()}) a záložní worker
 * ({@see processDue()}) soutěží o stejný lease: claim je jediný podmíněný UPDATE
 * (stav + čas), takže záznam odešle vždy jen jeden z nich. Během claimu se do
 * `next_attempt_at` zapíše konec lease; spadlý proces tak záznam po vypršení
 * lease vrátí workeru bez zvláštní obnovy.
 *
 * Síťové volání nikdy neběží v DB transakci. Pokus se zapíše do `eet_attempts`
 * PŘED odesláním (nové UUID, `prvni_zaslani` jen u úplně prvního pokusu), takže
 * pád uprostřed volání zanechá stopu a další pokus už není „první". Data tržby
 * (EIČ, jednotka, zařízení, číslo, čas, částka) se při retry nemění.
 *
 * Výsledky: potvrzeno → confirmed / confirmed_warning (varování v warnings_json),
 * odmítnuto → action_required, dočasná chyba → retry_wait s backoffem a jitterem
 * (30 s, 1, 2, 5, 10, 15 min strop), chybějící certifikát → action_required
 * s kódem `certificate_missing` a opakovanou kontrolou, záznam zůstává ve frontě.
 * Po 48 h od tržby se nastaví `overdue = 1` a odesílá se dál.
 *
 * `eet_settings.dispatch_blocked` a anonymizovaný klon databáze → nic se neodesílá.
 * Záznam ve stavu `external` (tržbu evidoval jiný systém) žádný claim nepřevezme,
 * protože podmínky claimu i výběr workeru berou jen otevřené stavy.
 */
final class EetDispatchService
{
    /** Backoff dočasných chyb v sekundách podle pořadí pokusu; poslední hodnota je strop. */
    public const BACKOFF_SECONDS = [30, 60, 120, 300, 600, 900];

    /** Jak často se znovu zkusí záznam bez platného certifikátu. */
    public const CERTIFICATE_RECHECK_SECONDS = 300;

    /** Jmenný prostor zámku workeru ({@see NamedLockName}). */
    public const WORKER_LOCK_SCOPE = 'eet-dispatch';

    private const NON_FINAL = ['pending', 'sending', 'retry_wait', 'action_required'];

    private ?bool $anonymizedClone = null;

    public function __construct(
        private readonly Connection $db,
        private readonly EetTransportInterface $transport,
        private readonly EetCredentialProvider $credentials,
        private readonly EetSettingsService $settings,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Okamžitý pokus až po commitu transakce, ve které záznam vznikl (pokladní doklad
     * zaúčtovaný v cizí transakci). Rollback pokus zahodí; bez transakce se odešle hned.
     * Chyba odpověď operace nemění: záznam zůstává ve frontě a odešle ho worker.
     */
    public function dispatchAfterCommit(int $recordId): void
    {
        $send = function () use ($recordId): void {
            try {
                $this->dispatchNow($recordId);
            } catch (\Throwable $e) {
                error_log('Okamžité odeslání EET záznamu #' . $recordId . ' selhalo: ' . $e->getMessage());
            }
        };
        $pdo = $this->db->pdo();
        if ($pdo instanceof \MyInvoice\Infrastructure\Database\LoggingPdo) {
            $pdo->afterCommit('eet.dispatch.' . $recordId, $send);
        } elseif (!$pdo->inTransaction()) {
            $send();
        }
    }

    /**
     * Okamžitý pokus po commitu příkazu. Zkrácený lease; nesoutěží s běžícím pokusem.
     *
     * @return array<string,mixed> stav záznamu po pokusu
     */
    public function dispatchNow(int $recordId, ?\DateTimeImmutable $now = null): array
    {
        $now = EetValues::local($now ?? $this->clock->now());
        $supplierId = $this->supplierOf($recordId);
        if ($supplierId === null) {
            throw EetCommandException::notFound('Záznam EET nenalezen.');
        }
        $settings = $this->settings->get($supplierId);
        if (!$this->dispatchAllowed($settings)) {
            return $this->state($supplierId, $recordId, false);
        }
        $lease = $this->leaseSeconds($settings, true);
        $claimed = $this->claim($supplierId, $recordId, $now, $lease, true);
        if ($claimed === null) {
            return $this->state($supplierId, $recordId, false);
        }
        $this->attempt($claimed, $settings, $now);
        return $this->state($supplierId, $recordId, true);
    }

    /**
     * Ruční „Opakovat teď": záznam čekající na retry nebo zásah vrátí do fronty
     * a hned zkusí odeslat. Potvrzený záznam se znovu neodesílá.
     *
     * @return array<string,mixed>
     */
    public function retryNow(int $supplierId, int $recordId, ?\DateTimeImmutable $now = null): array
    {
        $now = EetValues::local($now ?? $this->clock->now());
        $stmt = $this->db->pdo()->prepare(
            "UPDATE eet_records SET status = 'pending', action_code = NULL, next_attempt_at = ?
              WHERE id = ? AND supplier_id = ? AND status IN ('retry_wait', 'action_required')"
        );
        $stmt->execute([EetValues::dbTime($now), $recordId, $supplierId]);
        if ($stmt->rowCount() === 0) {
            $state = $this->state($supplierId, $recordId, false);
            if (!in_array($state['status'], ['pending'], true)) {
                throw EetCommandException::stateConflict('Záznam nyní nelze opakovat (stav ' . $state['status'] . ').', ['status' => $state['status']]);
            }
        }
        return $this->dispatchNow($recordId, $now);
    }

    /**
     * Worker: odešle splatné záznamy napříč firmami. Vrací počet zpracovaných.
     */
    public function processDue(int $limit): int
    {
        $pdo = $this->db->pdo();
        if ($this->isAnonymizedClone()) {
            return 0;
        }
        // Souběžný běh workeru (cron se překryl s pomalým FS) by jen soutěžil o lease;
        // druhý běh skončí hned, první dokončí dávku.
        $lockName = NamedLockName::for($this->db, self::WORKER_LOCK_SCOPE);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            return 0;
        }
        try {
            return $this->processDueLocked($limit);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /**
     * Dávka pod zámkem workeru. Čas se bere z hodin pro KAŽDÝ záznam (claim, lease,
     * hlavička zprávy, backoff): odeslání předchozích záznamů trvá a lease spočítaný
     * od začátku dávky by u pozdějších záznamů mohl být už vypršelý, takže by je
     * převzal okamžitý pokus a odeslal podruhé; `dat_odesl` by byl zastaralý.
     */
    private function processDueLocked(int $limit): int
    {
        $now = $this->now();
        $pdo = $this->db->pdo();

        // Lhůta 48 h: příznak se nastaví všem neodeslaným, odesílání pokračuje.
        $pdo->prepare(
            "UPDATE eet_records SET overdue = 1
              WHERE status IN ('pending','sending','retry_wait','action_required') AND overdue = 0 AND deadline_at < ?"
        )->execute([EetValues::dbSecond($now)]);

        $limit = max(1, min(500, $limit));
        $candidates = [];
        $select = $pdo->prepare(
            'SELECT r.id, r.supplier_id
               FROM eet_records r
               JOIN eet_settings s ON s.supplier_id = r.supplier_id AND s.dispatch_blocked = 0
              WHERE r.status = ? AND r.next_attempt_at <= ?
              ORDER BY r.next_attempt_at, r.id
              LIMIT ' . $limit
        );
        foreach (self::NON_FINAL as $status) {
            $select->execute([$status, EetValues::dbTime($now)]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $candidates[] = $row;
            }
        }
        usort($candidates, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

        $processed = 0;
        $settingsCache = [];
        foreach (array_slice($candidates, 0, $limit) as $row) {
            $supplierId = (int) $row['supplier_id'];
            $settings = $settingsCache[$supplierId] ??= $this->settings->get($supplierId);
            if (!$this->dispatchAllowed($settings)) {
                continue;
            }
            $claimed = $this->claim($supplierId, (int) $row['id'], $this->now(), $this->leaseSeconds($settings, false), false);
            if ($claimed === null) {
                continue;
            }
            $this->attempt($claimed, $settings, $this->now());
            $processed++;
        }
        return $processed;
    }

    private function now(): \DateTimeImmutable
    {
        return EetValues::local($this->clock->now());
    }

    /**
     * Podmíněný claim. Interaktivní cesta bere čekající záznam hned, worker jen splatný;
     * oba převezmou `sending` s vypršelým lease.
     *
     * @return array<string,mixed>|null řádek záznamu s `lease_token`, nebo null když vyhrál jiný
     */
    private function claim(int $supplierId, int $recordId, \DateTimeImmutable $now, int $leaseSeconds, bool $interactive): ?array
    {
        $token = bin2hex(random_bytes(32));
        $leaseUntil = EetValues::dbTime($now->modify('+' . $leaseSeconds . ' seconds'));
        $nowDb = EetValues::dbTime($now);
        $eligible = $interactive
            ? "status IN ('pending', 'retry_wait')"
            : "status IN ('pending', 'retry_wait', 'action_required') AND next_attempt_at <= ?";
        $params = [$token, $leaseUntil, $leaseUntil, $recordId, $supplierId];
        if (!$interactive) {
            $params[] = $nowDb;
        }
        $params[] = $nowDb;
        $stmt = $this->db->pdo()->prepare(
            "UPDATE eet_records
                SET status = 'sending', lease_token = ?, lease_until = ?, next_attempt_at = ?,
                    attempt_count = attempt_count + 1
              WHERE id = ? AND supplier_id = ?
                AND (({$eligible}) OR (status = 'sending' AND next_attempt_at <= ?))"
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $row = $this->db->pdo()->prepare('SELECT * FROM eet_records WHERE id = ? AND supplier_id = ? AND lease_token = ?');
        $row->execute([$recordId, $supplierId, $token]);
        $record = $row->fetch(PDO::FETCH_ASSOC);
        return $record === false ? null : $record;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $settings */
    private function attempt(array $record, array $settings, \DateTimeImmutable $now): void
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Odeslání EET nesmí běžet v databázové transakci.');
        }
        $supplierId = (int) $record['supplier_id'];
        $recordId = (int) $record['id'];
        $token = (string) $record['lease_token'];

        $environment = EetEnvironment::from((string) $record['environment']);
        $active = $this->credentials->activeCredential($supplierId, $environment, (string) $record['eic']);
        if ($active === null) {
            $this->finish($recordId, $supplierId, $token, $now, [
                'status' => 'action_required',
                'action_code' => 'certificate_missing',
                'next_attempt_at' => EetValues::dbTime($now->modify('+' . self::CERTIFICATE_RECHECK_SECONDS . ' seconds')),
                'last_error_code' => null,
                'last_error_message' => 'Firma nemá pro prostředí ' . $environment->value . ' platný pokladní certifikát.',
            ]);
            return;
        }

        $next = $pdo->prepare('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM eet_attempts WHERE supplier_id = ? AND record_id = ?');
        $next->execute([$supplierId, $recordId]);
        $attemptNo = (int) $next->fetchColumn();
        $header = EetMessageHeader::create($attemptNo === 1, false, $now);

        $pdo->prepare(
            "INSERT INTO eet_attempts (supplier_id, record_id, attempt_no, uuid_zpravy, sent_at, first_attempt, verification, certificate_id, outcome)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 'sending')"
        )->execute([
            $supplierId, $recordId, $attemptNo, $header->uuid, EetValues::dbTime($header->sentAt),
            $header->firstAttempt ? 1 : 0, $active->certificateId,
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        $data = new EetSaleData(
            (string) $record['eic'],
            (int) $record['unit_number'],
            (string) $record['device_code'],
            (string) $record['sequence_number'],
            new \DateTimeImmutable((string) $record['sale_at'] . (string) $record['sale_at_offset']),
            EetValues::toCents((string) $record['total_amount']),
            $record['credit_topup_amount'] !== null ? EetValues::toCents((string) $record['credit_topup_amount']) : null,
            $record['credit_draw_amount'] !== null ? EetValues::toCents((string) $record['credit_draw_amount']) : null,
            $record['delegating_eic'] !== null ? (string) $record['delegating_eic'] : null,
            (int) $record['multiple_delegators'] === 1 ? true : null,
        );
        $totalMs = max(2000, (int) ($settings['response_timeout_ms'] ?? 3000));
        $timeouts = new EetTimeouts(min(2000, $totalMs), $totalMs);

        try {
            $result = $this->transport->send($environment, $data, $header, $active->credential, $timeouts);
        } catch (\Throwable $e) {
            // Transport má chyby vracet výsledkem; neočekávaná výjimka je nejistý výsledek → retry.
            error_log('EET transport výjimka u záznamu #' . $recordId . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE eet_attempts SET outcome = 'transport_error', error_message = ? WHERE id = ? AND supplier_id = ?")
                ->execute(['Neočekávaná chyba při odesílání.', $attemptId, $supplierId]);
            $after = $this->now();
            $this->finish($recordId, $supplierId, $token, $after, $this->retryPatch($attemptNo, $after, null, 'Neočekávaná chyba při odesílání.'));
            return;
        }

        // Backoff a potvrzení počítat od konce volání, ne od claimu.
        $after = $this->now();
        $this->storeAttempt($attemptId, $supplierId, $result);
        $this->finish($recordId, $supplierId, $token, $after, $this->patchFor($result, $attemptNo, $after));
    }

    private function storeAttempt(int $attemptId, int $supplierId, EetSendResult $result): void
    {
        $request = $result->requestXml !== '' ? $result->requestXml : null;
        $this->db->pdo()->prepare(
            'UPDATE eet_attempts
                SET outcome = ?, http_status = ?, error_code = ?, error_message = ?, pok = ?, test_flag = ?,
                    signature_verified = ?, duration_ms = ?, request_sha256 = ?, request_xml = ?, response_xml = ?,
                    received_at = ?
              WHERE id = ? AND supplier_id = ?'
        )->execute([
            $result->outcome->value,
            $result->httpStatus,
            $result->errorCode,
            self::cut($result->errorMessage, 500),
            $result->pok,
            $result->testFlag === null ? null : ($result->testFlag ? 1 : 0),
            $result->signatureVerified ? 1 : 0,
            $result->durationMs !== null ? max(0, $result->durationMs) : null,
            $request !== null ? hash('sha256', $request) : null,
            $request,
            $result->responseXml,
            self::cut($result->receivedAt, 32),
            $attemptId, $supplierId,
        ]);
    }

    /** @return array<string,mixed> */
    private function patchFor(EetSendResult $result, int $attemptNo, \DateTimeImmutable $now): array
    {
        $outcome = $result->outcome->value;
        if ($result->isConfirmed()) {
            $warnings = array_values($result->warnings);
            return [
                'status' => ($outcome === 'confirmed_warning' || $warnings !== []) ? 'confirmed_warning' : 'confirmed',
                'action_code' => null,
                'next_attempt_at' => null,
                'pok' => $result->pok,
                'confirmed_at' => EetValues::dbTime($now),
                'warnings_json' => $warnings !== [] ? json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'last_error_code' => null,
                'last_error_message' => null,
            ];
        }
        if ($result->isRetryable()) {
            return $this->retryPatch($attemptNo, $now, $result->errorCode, $result->errorMessage);
        }
        return [
            'status' => 'action_required',
            'action_code' => match ($outcome) {
                'rejected' => 'rejected',
                'verification_ok' => 'unexpected_verification',
                default => $outcome,
            },
            'next_attempt_at' => null,
            'last_error_code' => $result->errorCode,
            'last_error_message' => self::cut($result->errorMessage, 500),
        ];
    }

    /** @return array<string,mixed> */
    private function retryPatch(int $attemptNo, \DateTimeImmutable $now, ?int $code, ?string $message): array
    {
        return [
            'status' => 'retry_wait',
            'action_code' => null,
            'next_attempt_at' => EetValues::dbTime($now->modify('+' . self::backoffSeconds($attemptNo) . ' seconds')),
            'last_error_code' => $code,
            'last_error_message' => self::cut($message, 500),
        ];
    }

    /** Backoff po N-tém neúspěšném pokusu včetně jitteru (0–10 %). */
    public static function backoffSeconds(int $attemptNo): int
    {
        $base = self::BACKOFF_SECONDS[min(max(1, $attemptNo), count(self::BACKOFF_SECONDS)) - 1];
        return $base + random_int(0, intdiv($base, 10));
    }

    /**
     * Zápis výsledku pod lease. Když lease mezitím převzal jiný proces, nic se nepřepíše.
     *
     * @param array<string,mixed> $patch
     */
    private function finish(int $recordId, int $supplierId, string $token, \DateTimeImmutable $now, array $patch): void
    {
        $sets = [];
        $params = [];
        foreach ($patch as $column => $value) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
        $sets[] = 'lease_token = NULL';
        $sets[] = 'lease_until = NULL';
        $sets[] = 'overdue = IF(deadline_at < ?, 1, overdue)';
        $params[] = EetValues::dbSecond($now);
        $params[] = $recordId;
        $params[] = $supplierId;
        $params[] = $token;
        $this->db->pdo()->prepare(
            'UPDATE eet_records SET ' . implode(', ', $sets) . ' WHERE id = ? AND supplier_id = ? AND lease_token = ?'
        )->execute($params);
    }

    /** @return array<string,mixed> */
    private function state(int $supplierId, int $recordId, bool $attempted): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, public_id, status, action_code, pok, environment, warnings_json, overdue, attempt_count,
                    next_attempt_at, last_error_code, last_error_message
               FROM eet_records WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$recordId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw EetCommandException::notFound('Záznam EET nenalezen.');
        }
        return EetRecordPresenter::summary($row) + ['attempted' => $attempted];
    }

    private function supplierOf(int $recordId): ?int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM eet_records WHERE id = ?');
        $stmt->execute([$recordId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    /** @param array<string,mixed> $settings */
    private function dispatchAllowed(array $settings): bool
    {
        return empty($settings['dispatch_blocked']) && !$this->isAnonymizedClone();
    }

    private function isAnonymizedClone(): bool
    {
        return $this->anonymizedClone ??= $this->db->pdo()
            ->query("SELECT COUNT(*) FROM app_meta WHERE k = 'anonymized_clone'")->fetchColumn() > 0;
    }

    /** @param array<string,mixed> $settings */
    private function leaseSeconds(array $settings, bool $interactive): int
    {
        $transport = (int) ceil(max(2000, (int) ($settings['response_timeout_ms'] ?? 3000)) / 1000);
        return $transport + ($interactive ? 10 : 60);
    }

    private static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
