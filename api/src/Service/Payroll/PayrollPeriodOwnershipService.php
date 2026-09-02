<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;
use PDOException;

/**
 * Rezervace mzdového období: buď ho zpracovává původní ruční zaúčtování
 * (`legacy`), nebo mzdový modul (`payroll`). Nikdy obojí.
 *
 * ── Uvolnění legacy rezervace ───────────────────────────────────────────────
 * Legacy rezervaci zakládá {@see PayrollPostingService} při zaúčtování ruční
 * mzdové rekapitulace, a to i z cronu. Dokud nešla uvolnit z aplikace, stačilo,
 * aby si legacy větev vzala měsíc dřív než modul, a mzdový běh za ten měsíc už
 * nešlo založit — jediná cesta ven vedla ručním DELETE v databázi. Na tohle
 * narazil první reálný pokus o červencový běh.
 *
 * `releaseLegacy()` je bezpečná cesta ven a je fail-closed: uvolní rezervaci
 * jen tehdy, když za dané období po legacy větvi nezbyla žádná data — tedy
 * není tu aktivní (nestornovaný) účetní zápis mzdové rekapitulace ani snapshot
 * v mzdovém listu. Zbytek je věc volajícího: právo (`payroll.reopen`) a audit
 * si služba hlídá sama.
 *
 * ── Uvolnění payroll rezervace ──────────────────────────────────────────────
 * Opačný směr dlouho neexistoval vůbec. Rezervaci pro modul zakládá už SAMO
 * ZALOŽENÍ mzdového běhu ({@see Run\PayrollRunCommandService::createRun()}),
 * tedy dřív, než se cokoliv spočítá, zaúčtuje nebo podá. Kdo běh založil na
 * špatný měsíc, zabral ho modulu natrvalo: `claimLegacy()` od té chvíle hází
 * výjimku, `releaseLegacy()` cizí rezervaci odmítá a zrušení běhu ji neuvolní.
 * Hláška to navíc tvrdila opačně („uvolní ji zrušení nebo smazání běhu"),
 * takže odkazovala na krok, který nic nedělá — jediná cesta ven vedla ručním
 * DELETE v databázi.
 *
 * `releasePayroll()` je tedy zrcadlo `releaseLegacy()` a je stejně fail-closed:
 * uvolní rezervaci jen tehdy, když za období po modulu nezbylo NIC, o co by se
 * dalo opřít — žádný živý běh, žádná schválená ani odsunutá revize, žádné
 * zaúčtování, platba, podání ani vydaný doklad. Zrušený a rozdělaný běh
 * překážkou není: ten měsíc nedrží, jen v něm leží.
 */
final class PayrollPeriodOwnershipService
{
    public const PROCESSOR_LEGACY = 'legacy';
    public const PROCESSOR_PAYROLL = 'payroll';

    /** Zdrojový typ účetního zápisu ruční mzdové rekapitulace. */
    private const LEGACY_JOURNAL_SOURCE_TYPE = 'manual';

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function claimLegacy(
        int $supplierId,
        int $year,
        int $month,
        int $sourceId,
        ?int $userId,
    ): void {
        $this->claim($supplierId, $year, $month, self::PROCESSOR_LEGACY, 'accounting_payroll', $sourceId, $userId);
    }

    public function claimPayroll(
        int $supplierId,
        int $year,
        int $month,
        string $sourceType,
        ?int $sourceId,
        ?int $userId,
    ): void {
        $this->claim($supplierId, $year, $month, self::PROCESSOR_PAYROLL, $sourceType, $sourceId, $userId);
    }

    /**
     * Stav rezervace období pro obrazovku — bez zápisu.
     *
     * @return array{
     *   claimed:bool,
     *   processor:?string,
     *   source_type:?string,
     *   source_id:?int,
     *   claimed_by:?int,
     *   claimed_at:?string,
     *   releasable:bool,
     *   blockers:list<array{code:string,message:string,count:int}>
     * }
     */
    public function legacyClaimStatus(
        int $supplierId,
        int $year,
        int $month,
    ): array {
        $period = self::period($year, $month);
        $row = $this->findClaim($supplierId, $period, false);
        if ($row === null) {
            return self::emptyStatus();
        }
        $processor = (string) $row['processor'];
        $blockers = $processor === self::PROCESSOR_LEGACY
            ? $this->legacyBlockers($supplierId, $year, $month)
            : [[
                'code' => 'payroll_period_not_legacy',
                'message' => 'Období nedrží původní zaúčtování, ale mzdový modul.',
                'count' => 1,
            ]];

        return [
            'claimed' => true,
            'processor' => $processor,
            'source_type' => (string) $row['source_type'],
            'source_id' => $row['source_id'] === null
                ? null
                : (int) $row['source_id'],
            'claimed_by' => $row['claimed_by'] === null
                ? null
                : (int) $row['claimed_by'],
            'claimed_at' => (string) $row['claimed_at'],
            'releasable' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Uvolní legacy rezervaci období, pokud po legacy větvi nezbyla data.
     *
     * @throws \OutOfBoundsException období žádnou rezervaci nemá
     * @throws PayrollPeriodOwnedException rezervaci drží mzdový modul,
     *         nebo za období pořád existují legacy data
     */
    public function releaseLegacy(
        int $supplierId,
        int $year,
        int $month,
        ?int $userId,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma není platná.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException(
                'Uvolnění mzdového období vyžaduje důvod (max. 500 znaků).',
            );
        }
        $period = self::period($year, $month);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->findClaim($supplierId, $period, $ownsTransaction);
            if ($row === null) {
                throw new \OutOfBoundsException(
                    'Mzdové období žádnou rezervaci nemá.',
                );
            }
            if ((string) $row['processor'] !== self::PROCESSOR_LEGACY) {
                throw new PayrollPeriodOwnedException(
                    'Rezervaci období drží mzdový modul — uvolněte ji přes '
                        . '„Vrátit období původnímu zaúčtování“, ne touto cestou.',
                );
            }
            $blockers = $this->legacyBlockers($supplierId, $year, $month);
            if ($blockers !== []) {
                throw new PayrollPeriodOwnedException(sprintf(
                    'Období %04d-%02d nelze uvolnit: %s',
                    $year,
                    $month,
                    implode(' ', array_column($blockers, 'message')),
                ));
            }
            $delete = $pdo->prepare(
                'DELETE FROM payroll_period_ownership
                  WHERE supplier_id = ? AND period_start = ?
                    AND processor = ?',
            );
            $delete->execute([$supplierId, $period, self::PROCESSOR_LEGACY]);
            if ($delete->rowCount() !== 1) {
                throw new PayrollPeriodOwnedException(
                    'Rezervace mzdového období se mezitím změnila.',
                );
            }
            $this->activityLogger->log(
                'payroll.period_ownership.legacy_released',
                $userId,
                'payroll_period_ownership',
                null,
                [
                    'period' => sprintf('%04d-%02d', $year, $month),
                    'source_type' => (string) $row['source_type'],
                    'source_id' => $row['source_id'] === null
                        ? null
                        : (int) $row['source_id'],
                    'previous_claimed_by' => $row['claimed_by'] === null
                        ? null
                        : (int) $row['claimed_by'],
                    'reason' => $reason,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Stav rezervace, kterou drží MZDOVÝ MODUL — bez zápisu.
     *
     * Zrcadlo {@see legacyClaimStatus()}; obrazovka z něj kreslí, jestli jde
     * měsíc vrátit původnímu zaúčtování, a když ne, čím to je.
     *
     * @return array{
     *   claimed:bool,
     *   processor:?string,
     *   source_type:?string,
     *   source_id:?int,
     *   claimed_by:?int,
     *   claimed_at:?string,
     *   releasable:bool,
     *   blockers:list<array{code:string,message:string,count:int}>
     * }
     */
    public function payrollClaimStatus(
        int $supplierId,
        int $year,
        int $month,
    ): array {
        $period = self::period($year, $month);
        $row = $this->findClaim($supplierId, $period, false);
        if ($row === null) {
            return self::emptyStatus();
        }
        $processor = (string) $row['processor'];
        $blockers = $processor === self::PROCESSOR_PAYROLL
            ? $this->payrollBlockers($supplierId, $period)
            : [[
                'code' => 'payroll_period_not_payroll',
                'message' => 'Období nedrží mzdový modul, ale původní zaúčtování.',
                'count' => 1,
            ]];

        return [
            'claimed' => true,
            'processor' => $processor,
            'source_type' => (string) $row['source_type'],
            'source_id' => $row['source_id'] === null
                ? null
                : (int) $row['source_id'],
            'claimed_by' => $row['claimed_by'] === null
                ? null
                : (int) $row['claimed_by'],
            'claimed_at' => (string) $row['claimed_at'],
            'releasable' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    /**
     * Uvolní rezervaci drženou mzdovým modulem, pokud po něm za období
     * nezbyla data, o která by se dalo opřít.
     *
     * @throws \OutOfBoundsException období žádnou rezervaci nemá
     * @throws PayrollPeriodOwnedException rezervaci drží legacy větev,
     *         nebo za období pořád existují mzdová data
     */
    public function releasePayroll(
        int $supplierId,
        int $year,
        int $month,
        ?int $userId,
        string $reason,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException('Firma není platná.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException(
                'Uvolnění mzdového období vyžaduje důvod (max. 500 znaků).',
            );
        }
        $period = self::period($year, $month);
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $row = $this->findClaim($supplierId, $period, $ownsTransaction);
            if ($row === null) {
                throw new \OutOfBoundsException(
                    'Mzdové období žádnou rezervaci nemá.',
                );
            }
            if ((string) $row['processor'] !== self::PROCESSOR_PAYROLL) {
                throw new PayrollPeriodOwnedException(
                    'Rezervaci období drží původní zaúčtování — uvolněte ji '
                        . 'přes uvolnění legacy období, ne touto cestou.',
                );
            }
            $blockers = $this->payrollBlockers($supplierId, $period);
            if ($blockers !== []) {
                throw new PayrollPeriodOwnedException(sprintf(
                    'Období %04d-%02d nelze uvolnit: %s',
                    $year,
                    $month,
                    implode(' ', array_column($blockers, 'message')),
                ));
            }
            $delete = $pdo->prepare(
                'DELETE FROM payroll_period_ownership
                  WHERE supplier_id = ? AND period_start = ?
                    AND processor = ?',
            );
            $delete->execute([$supplierId, $period, self::PROCESSOR_PAYROLL]);
            if ($delete->rowCount() !== 1) {
                throw new PayrollPeriodOwnedException(
                    'Rezervace mzdového období se mezitím změnila.',
                );
            }
            $this->activityLogger->log(
                'payroll.period_ownership.payroll_released',
                $userId,
                'payroll_period_ownership',
                null,
                [
                    'period' => sprintf('%04d-%02d', $year, $month),
                    'source_type' => (string) $row['source_type'],
                    'source_id' => $row['source_id'] === null
                        ? null
                        : (int) $row['source_id'],
                    'previous_claimed_by' => $row['claimed_by'] === null
                        ? null
                        : (int) $row['claimed_by'],
                    'reason' => $reason,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Co za období po mzdovém modulu zbylo. Prázdné pole = období jde uvolnit.
     *
     * Rozdělaný ani zrušený běh překážkou NENÍ — to je přesně ten stav „běh se
     * založil omylem", kvůli kterému se rezervace uvolňuje. Běh sám zůstává
     * (jeho revize i auditní stopa jsou append-only), jen měsíc přestává držet.
     *
     * @return list<array{code:string,message:string,count:int}>
     */
    private function payrollBlockers(int $supplierId, string $period): array
    {
        $blockers = [];
        foreach ([
            [
                'payroll_run_active',
                'Za období existuje rozpracovaný mzdový běh — nejdřív ho zrušte.',
                'SELECT COUNT(*) FROM payroll_runs
                  WHERE supplier_id = ? AND period_start = ?
                    AND status NOT IN ("draft", "cancelled")',
            ],
            [
                'payroll_approved_revision_exists',
                'Za období je schválená mzdová revize.',
                'SELECT COUNT(*) FROM payroll_run_revisions revision
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE revision.supplier_id = ? AND run.period_start = ?
                    AND revision.status IN ("approved", "superseded")',
            ],
            [
                'payroll_posting_exists',
                'Za období je mzdové zaúčtování.',
                'SELECT COUNT(*) FROM payroll_posting_batches batch
                   JOIN payroll_runs run
                     ON run.supplier_id = batch.supplier_id
                    AND run.id = batch.run_id
                  WHERE batch.supplier_id = ? AND run.period_start = ?
                    AND batch.journal_entry_id IS NOT NULL',
            ],
            [
                'payroll_payment_exists',
                'Za období existuje mzdová platební stopa.',
                'SELECT COUNT(*) FROM payroll_payment_liabilities liability
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = liability.supplier_id
                    AND revision.id = liability.revision_id
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE liability.supplier_id = ? AND run.period_start = ?',
            ],
            [
                'payroll_submission_exists',
                'Za období je mzdový modul zdrojem podání.',
                'SELECT COUNT(*) FROM payroll_submissions submission
                   JOIN payroll_run_revisions revision
                     ON revision.supplier_id = submission.supplier_id
                    AND revision.id = submission.source_revision_id
                   JOIN payroll_runs run
                     ON run.supplier_id = revision.supplier_id
                    AND run.id = revision.run_id
                  WHERE submission.supplier_id = ? AND run.period_start = ?',
            ],
            [
                'payroll_document_exists',
                'Za období jsou vydané mzdové doklady.',
                'SELECT COUNT(*) FROM payroll_generated_documents document
                   JOIN payroll_runs run
                     ON run.supplier_id = document.supplier_id
                    AND run.id = document.run_id
                  WHERE document.supplier_id = ? AND run.period_start = ?',
            ],
        ] as [$code, $message, $sql]) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$supplierId, $period]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $blockers[] = [
                    'code' => $code,
                    'message' => $message,
                    'count' => $count,
                ];
            }
        }

        return $blockers;
    }

    /**
     * Data, která po legacy zaúčtování za období zbyla. Prázdné pole = období
     * jde uvolnit.
     *
     * Stornovaný zápis (`reversed_by IS NOT NULL`) se nepočítá — to je přesně
     * ten případ „legacy zaúčtování bylo zrušeno", kvůli kterému se období
     * uvolňuje. Původní zápis v deníku zůstává (§ 35 ZoÚ), rezervace ale držet
     * nemá co.
     *
     * @return list<array{code:string,message:string,count:int}>
     */
    private function legacyBlockers(
        int $supplierId,
        int $year,
        int $month,
    ): array {
        $sourceId = $year * 100 + $month;
        $blockers = [];

        $entries = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND reversed_by IS NULL',
        );
        $entries->execute([
            $supplierId,
            self::LEGACY_JOURNAL_SOURCE_TYPE,
            $sourceId,
        ]);
        $entryCount = (int) $entries->fetchColumn();
        if ($entryCount > 0) {
            $blockers[] = [
                'code' => 'legacy_journal_entry_exists',
                'message' => 'Za období je zaúčtovaná mzdová rekapitulace — '
                    . 'nejdřív ji stornujte.',
                'count' => $entryCount,
            ];
        }

        // Odložený řádek (`retired_at`, migrace 1719) se nepočítá: to je přesně
        // ten stav „mzdový list po legacy větvi zůstal jako doklad, ale už
        // neplatí, protože měsíc přebírá modul". Kdyby blokoval dál, nezbývalo
        // by než ho z databáze smazat — a mazat evidenci podle § 38j ZDP nejde.
        $records = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ? AND month = ?
                AND retired_at IS NULL',
        );
        $records->execute([$supplierId, $year, $month]);
        $recordCount = (int) $records->fetchColumn();
        if ($recordCount > 0) {
            $blockers[] = [
                'code' => 'legacy_monthly_record_exists',
                'message' => 'Za období existují záznamy ve mzdovém listu '
                    . 'z původního zaúčtování.',
                'count' => $recordCount,
            ];
        }

        return $blockers;
    }

    /** @return array<string,mixed>|null */
    private function findClaim(
        int $supplierId,
        string $period,
        bool $lock,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT processor, source_type, source_id, claimed_by, claimed_at
               FROM payroll_period_ownership
              WHERE supplier_id = ? AND period_start = ?'
            . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([$supplierId, $period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{
     *   claimed:bool,processor:null,source_type:null,source_id:null,
     *   claimed_by:null,claimed_at:null,releasable:bool,blockers:list<array{
     *     code:string,message:string,count:int
     *   }>
     * }
     */
    private static function emptyStatus(): array
    {
        return [
            'claimed' => false,
            'processor' => null,
            'source_type' => null,
            'source_id' => null,
            'claimed_by' => null,
            'claimed_at' => null,
            'releasable' => false,
            'blockers' => [],
        ];
    }

    private static function period(int $year, int $month): string
    {
        if (!checkdate($month, 1, $year)) {
            throw new \InvalidArgumentException('Neplatné mzdové období.');
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function claim(
        int $supplierId,
        int $year,
        int $month,
        string $processor,
        string $sourceType,
        ?int $sourceId,
        ?int $userId,
    ): void {
        $period = self::period($year, $month);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO payroll_period_ownership
                (supplier_id, period_start, processor, source_type, source_id, claimed_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                source_type = IF(processor = VALUES(processor), VALUES(source_type), source_type),
                source_id = IF(processor = VALUES(processor), VALUES(source_id), source_id),
                claimed_by = IF(processor = VALUES(processor), VALUES(claimed_by), claimed_by),
                updated_at = IF(processor = VALUES(processor), CURRENT_TIMESTAMP, updated_at)'
        );
        try {
            $stmt->execute([$supplierId, $period, $processor, $sourceType, $sourceId, $userId]);
        } catch (PDOException $e) {
            throw new PayrollPeriodOwnedException('Mzdové období nelze rezervovat.', previous: $e);
        }

        $check = $this->db->pdo()->prepare(
            'SELECT processor FROM payroll_period_ownership WHERE supplier_id = ? AND period_start = ?'
        );
        $check->execute([$supplierId, $period]);
        $owner = $check->fetchColumn();
        if ($owner !== $processor) {
            throw new PayrollPeriodOwnedException(sprintf(
                'Období %04d-%02d už zpracovává %s mzdový modul.',
                $year,
                $month,
                $owner === self::PROCESSOR_LEGACY ? 'původní' : 'nový',
            ));
        }
    }
}
