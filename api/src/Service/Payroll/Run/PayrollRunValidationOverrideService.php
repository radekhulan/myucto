<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Run;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollRunConflictException;
use MyInvoice\Repository\Payroll\PayrollRunIdempotencyException;
use MyInvoice\Repository\Payroll\PayrollRunRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use PDO;

/**
 * Schválení a odvolání výjimky u mzdové validace — druhá půlka override.
 *
 * ─── CO TU CHYBĚLO ──────────────────────────────────────────────────────────
 *
 * Migrace 1210 založila u `payroll_run_validations` sloupce `override_reason`,
 * `overridden_by` a `overridden_at` a {@see PayrollRunWorkflow} na nich staví
 * podmínku schválení běhu: `unresolvedOverrideCount > 0` zastaví příkaz
 * `approve`. Jenže k těm sloupcům nevedla ŽÁDNÁ routa ani obrazovka — nikdo je
 * nikdy nenastavil. Každé varování s `requires_override = 1` proto natrvalo
 * zablokovalo mzdový běh a nešlo ho odklidit; jediná cesta ven byla zásah
 * přímo v databázi. Tahle služba tu půlku doplňuje.
 *
 * ─── KDO SMÍ SCHVALOVAT ─────────────────────────────────────────────────────
 *
 * Právo `payroll.approve` („Schválit mzdový běh"). Schválení výjimky je
 * rozhodnutí „vím o vadě a přesto se vyplácí" — tedy věcně část schválení
 * běhu, ne jeho příprava. Slabší právo (`payroll.review`) by znamenalo, že
 * překážku ke schválení odklízí někdo, kdo sám schválit nesmí, a schvalovatel
 * by pak podepisoval cizí rozhodnutí, aniž by ho mohl odmítnout jinak než
 * vrácením celého běhu.
 *
 * ─── ČTYŘI OČI: POLITIKA, NE BLOKACE ────────────────────────────────────────
 *
 * Výjimku smí schválit i ten, kdo revizi počítal, kontroloval a následně
 * schválí. {@see PayrollRunWorkflow} vyžaduje jednotlivé odborné kroky a
 * neměnnou auditní stopu, nikoli druhého uživatele. Historické pole
 * `four_eyes_met` zůstává jen kompatibilní auditní metadatou; nikdy neblokuje
 * výjimku ani mzdový běh.
 *
 * ─── ODVOLATELNOST ──────────────────────────────────────────────────────────
 *
 * Výjimku lze vzít zpět, dokud běh není schválený ({@see self::MUTABLE_STATUSES}).
 * Po schválení už ne: schválená revize je neměnný doklad a odebrání výjimky by
 * zpětně měnilo podklad, na jehož základě se vyplatilo. Že výjimka existovala,
 * zůstane v `payroll_run_events` navždy — události jsou append-only na úrovni
 * databázového triggeru, takže ani odvolání minulost nepřepíše.
 */
final class PayrollRunValidationOverrideService
{
    private const SAVEPOINT = 'payroll_run_validation_override';

    public const COMMAND_GRANT = 'validation_override';

    public const COMMAND_REVOKE = 'validation_override_revoke';

    public const EVENT_GRANTED = 'validation_override';

    public const EVENT_REVOKED = 'validation_override_revoked';

    /**
     * Stavy běhu, ve kterých se s výjimkou ještě smí hýbat.
     *
     * Končí před `approved` — od schválení dál je revize doklad, ne rozpracovaný
     * podklad. `correction_pending` tu chybí záměrně: oprava vytvoří NOVOU revizi
     * s vlastní sadou validací, takže rozhodnutí se dělá znovu, ne se opravuje
     * to staré.
     *
     * @var list<string>
     */
    private const MUTABLE_STATUSES = [
        'inputs_locked',
        'calculated',
        'reviewed',
        'reopened',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollRunRepository $runs,
    ) {}

    public function grant(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        mixed $reason,
    ): PayrollRunValidationOverrideResult {
        return $this->execute(
            $supplierId,
            $runId,
            $validationId,
            $expectedVersion,
            $idempotencyKey,
            $actorUserId,
            PayrollRunOverrideReason::normalize($reason),
            true,
        );
    }

    public function revoke(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        mixed $reason = null,
    ): PayrollRunValidationOverrideResult {
        return $this->execute(
            $supplierId,
            $runId,
            $validationId,
            $expectedVersion,
            $idempotencyKey,
            $actorUserId,
            PayrollRunOverrideReason::normalizeOptional($reason),
            false,
        );
    }

    private function execute(
        int $supplierId,
        int $runId,
        int $validationId,
        int $expectedVersion,
        string $idempotencyKey,
        int $actorUserId,
        ?string $reason,
        bool $granting,
    ): PayrollRunValidationOverrideResult {
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Uživatel příkazu není platný.');
        }
        if ($supplierId <= 0
            || $runId <= 0
            || $validationId <= 0
            || $expectedVersion <= 0
        ) {
            throw new \InvalidArgumentException(
                'Identifikace mzdové výjimky není platná.',
            );
        }
        $normalizedKey = trim($idempotencyKey);
        if (mb_strlen($normalizedKey) < 8 || mb_strlen($normalizedKey) > 190) {
            throw new \InvalidArgumentException(
                'Idempotency key musí mít 8 až 190 znaků.',
            );
        }
        $command = $granting ? self::COMMAND_GRANT : self::COMMAND_REVOKE;
        $keyHashBinary = hash('sha256', $normalizedKey, true);
        $keyHashHex = hash('sha256', $normalizedKey);
        $requestHash = hash('sha256', CanonicalJson::encode([
            'actor_user_id' => $actorUserId,
            'command' => $command,
            'expected_row_version' => $expectedVersion,
            'reason' => $reason,
            'run_id' => $runId,
            'supplier_id' => $supplierId,
            'validation_id' => $validationId,
        ]));

        $pdo = $this->db->pdo();
        $nested = $pdo->inTransaction();
        if ($nested) {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->beginTransaction();
        }
        try {
            // Zámek na běhu je jediné pořadové místo celé operace: dvě souběžná
            // schválení téže validace se tady seřadí za sebou, takže druhé v pořadí
            // uvidí už zapsaný `overridden_at` (a neplatnou `row_version`) místo
            // aby ten první přepsalo.
            $run = $this->runs->lock($supplierId, $runId);
            if ($run === null) {
                throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
            }

            $receipt = $this->runs->commandReceipt($supplierId, $keyHashBinary);
            if ($receipt !== null) {
                $result = $this->replay(
                    $supplierId,
                    $runId,
                    $validationId,
                    $command,
                    $requestHash,
                    $receipt,
                );
                $this->finish($pdo, $nested);
                return $result;
            }

            $currentVersion = (int) $run['row_version'];
            if ($currentVersion !== $expectedVersion) {
                throw new PayrollRunConflictException($currentVersion);
            }
            $status = (string) $run['status'];
            if (!in_array($status, self::MUTABLE_STATUSES, true)) {
                throw new \DomainException($granting
                    ? 'Ve stavu ' . $status . ' už výjimku schválit nelze — '
                        . 'rozhodnutí patří k běhu před jeho schválením.'
                    : 'Schválenou výjimku lze vzít zpět jen dokud běh není '
                        . 'schválený; potom by to přepisovalo historii.');
            }

            $validation = $this->runs->lockValidation($supplierId, $validationId);
            if ($validation === null
                || (int) $validation['run_id'] !== $runId
            ) {
                throw new \OutOfBoundsException(
                    'Validace mzdového běhu nebyla nalezena.',
                );
            }
            $revisionId = (int) $validation['revision_id'];
            $currentRevision = $this->runs->currentRevision($supplierId, $runId);
            if ($currentRevision === null
                || (int) $currentRevision['id'] !== $revisionId
            ) {
                throw new \DomainException(
                    'Výjimku lze řešit jen u validací aktuální revize běhu.',
                );
            }
            if (!(bool) $validation['requires_override']) {
                throw new \DomainException(
                    'Tato kontrola schválení výjimky nevyžaduje.',
                );
            }

            if ($granting) {
                if ($validation['overridden_at'] !== null) {
                    throw new \DomainException(
                        'Výjimka u této kontroly je už schválená.',
                    );
                }
                $applied = $this->runs->applyValidationOverride(
                    $supplierId,
                    $validationId,
                    (string) $reason,
                    $actorUserId,
                );
            } else {
                if ($validation['overridden_at'] === null) {
                    throw new \DomainException(
                        'U této kontroly není co odvolávat — výjimka schválená není.',
                    );
                }
                $applied = $this->runs->clearValidationOverride(
                    $supplierId,
                    $validationId,
                );
            }
            if (!$applied) {
                // Nemělo by nastat — běh držíme zamčený. Když ano, je to souběh,
                // který se nesmí utopit v tichu.
                throw new PayrollRunConflictException($currentVersion);
            }

            $calculatedBy = $currentRevision['calculated_by'] === null
                ? null
                : (int) $currentRevision['calculated_by'];
            $fourEyesMet = $calculatedBy === null || $calculatedBy !== $actorUserId;

            $run = $this->runs->updateRun(
                $supplierId,
                $runId,
                $expectedVersion,
                $status,
                null,
                $actorUserId,
            );

            $metadata = [
                'calculated_by' => $calculatedBy,
                'four_eyes_met' => $fourEyesMet,
                'idempotency_key_hash' => $keyHashHex,
                'request_hash' => $requestHash,
                'row_version' => (int) $run['row_version'],
                'run_status' => $status,
                'validation_code' => (string) $validation['code'],
                'validation_entity_id' => $validation['entity_id'] === null
                    ? null
                    : (int) $validation['entity_id'],
                'validation_entity_type' => (string) $validation['entity_type'],
                'validation_id' => $validationId,
                'validation_severity' => (string) $validation['severity'],
            ];
            if (!$granting) {
                // Odvolání smaže `override_reason` z validace; kdyby ho neneslo
                // sem, původní odůvodnění by z historie zmizelo úplně.
                $metadata['revoked_reason'] = $validation['override_reason'] === null
                    ? null
                    : (string) $validation['override_reason'];
            }
            $this->runs->insertEvent(
                $supplierId,
                $runId,
                $revisionId,
                $granting ? self::EVENT_GRANTED : self::EVENT_REVOKED,
                null,
                null,
                $actorUserId,
                $reason,
                $metadata,
            );
            $this->runs->insertCommandReceipt(
                $supplierId,
                $runId,
                $revisionId,
                $command,
                $keyHashBinary,
                $requestHash,
                $expectedVersion,
                $status,
                $status,
                [
                    'four_eyes_met' => $fourEyesMet,
                    'granted' => $granting,
                    'row_version' => (int) $run['row_version'],
                    'run_id' => $runId,
                    'validation_id' => $validationId,
                ],
                $actorUserId,
            );

            $stored = $this->runs->validation($supplierId, $validationId)
                ?? throw new \RuntimeException(
                    'Validace mzdového běhu po zápisu výjimky zmizela.',
                );
            $this->finish($pdo, $nested);

            return new PayrollRunValidationOverrideResult(
                $run,
                $stored,
                $granting,
                $fourEyesMet,
                false,
            );
        } catch (\Throwable $e) {
            $this->rollback($pdo, $nested);
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $receipt
     */
    private function replay(
        int $supplierId,
        int $runId,
        int $validationId,
        string $command,
        string $requestHash,
        array $receipt,
    ): PayrollRunValidationOverrideResult {
        if ((int) $receipt['run_id'] !== $runId
            || (string) $receipt['command_name'] !== $command
            || !hash_equals((string) $receipt['request_hash'], $requestHash)
        ) {
            throw new PayrollRunIdempotencyException();
        }
        $run = $this->runs->find($supplierId, $runId)
            ?? throw new \OutOfBoundsException('Mzdový běh nebyl nalezen.');
        $validation = $this->runs->validation($supplierId, $validationId)
            ?? throw new \OutOfBoundsException(
                'Validace mzdového běhu nebyla nalezena.',
            );
        $result = is_array($receipt['result'] ?? null) ? $receipt['result'] : [];

        return new PayrollRunValidationOverrideResult(
            $run,
            $validation,
            (bool) ($result['granted'] ?? false),
            (bool) ($result['four_eyes_met'] ?? true),
            true,
        );
    }

    private function finish(PDO $pdo, bool $nested): void
    {
        if ($nested) {
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } else {
            $pdo->commit();
        }
    }

    private function rollback(PDO $pdo, bool $nested): void
    {
        if ($nested) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        } elseif ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
