<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Repository\PayrollMonthlyRecordRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Accounting\PostingService;
use PDO;

/**
 * Předání měsíce od RUČNÍ mzdové rekapitulace modulu Mzdy.
 *
 * ── Proč to vzniklo ─────────────────────────────────────────────────────────
 * Firma, která mzdy dělala v Účetnictví → Mzdová rekapitulace a pak si zapnula
 * modul Mzdy, mohla do modulu vzít jen měsíce OD přechodu dál. Starší měsíc
 * držela rezervace `payroll_period_ownership.processor = 'legacy'` a
 * {@see PayrollPeriodOwnershipService::releaseLegacy()} ji fail-closed odmítala
 * uvolnit, dokud po legacy větvi zbývala data — účetní zápis rekapitulace nebo
 * řádek ve mzdovém listu.
 *
 * Zápis šlo stornovat z aplikace. Mzdový list ale ne: repozitář uměl jen
 * `upsert()`, takže jediná cesta ven vedla ručním DELETE v databázi. Tím pádem
 * NEEXISTOVALA aplikační cesta, jak převést do modulu už zpracovaný měsíc —
 * a rok, který začal ručně, zůstal navždy rozpůlený mezi dvě agendy.
 *
 * ── Co dělá ─────────────────────────────────────────────────────────────────
 * Jedním krokem, v jedné transakci a s povinným důvodem:
 *
 *  1. STORNUJE účetní zápis rekapitulace ({@see PostingService::reverse()}).
 *     Původní zápis v deníku zůstává i s protizápisem — § 35 odst. 6 ZoÚ
 *     opravu mazáním nezná.
 *  2. ODLOŽÍ mzdový list za období (`retired_at`, migrace 1719). Taky se
 *     nemaže: je to evidence podle § 38j ZDP. Jen přestane platit, aby měsíc
 *     nešel do ročního listu i do kumulovaných základů dvakrát.
 *  3. UVOLNÍ rezervaci období, takže si ho může vzít mzdový běh.
 *
 * Krok 3 volá touž fail-closed cestu jako uživatel z obrazovky. Pořadí je
 * podstatné: kdyby se rezervace uvolňovala dřív, mohl by mezi kroky vzniknout
 * mzdový běh nad měsícem, který má pořád živé legacy zaúčtování — a mzda by
 * v deníku seděla dvakrát.
 */
final class PayrollLegacyRecapitulationService
{
    /** Zdrojový typ účetního zápisu ruční mzdové rekapitulace. */
    private const LEGACY_JOURNAL_SOURCE_TYPE = 'manual';

    /** Druhy kumulace, pro které počáteční stavy existují (shodné s opening službou). */
    private const OPENING_KINDS = ['social_insurance', 'income_tax'];

    /** Daňová pole počátečního stavu — musí sedět na PayrollOpeningBalanceService. */
    private const OPENING_TAX_FIELDS = [
        'advance_base_minor_units',
        'withholding_base_minor_units',
        'advance_tax_minor_units',
        'withholding_tax_minor_units',
        'applied_non_refundable_credits_minor_units',
        'applied_child_credit_minor_units',
        'tax_bonus_minor_units',
        'bonus_qualifying_income_minor_units',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PostingService $posting,
        private readonly PayrollMonthlyRecordRepository $records,
        private readonly PayrollPeriodOwnershipService $ownership,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
    ) {}

    /**
     * Co za období po ruční rekapitulaci zbývá — bez zápisu, pro obrazovku.
     *
     * @return array{
     *   period:string,
     *   journal_entries:list<array{id:int,entry_date:string,description:?string}>,
     *   monthly_records:int,
     *   ownership:array<string,mixed>
     * }
     */
    public function status(int $supplierId, int $year, int $month): array
    {
        self::assertPeriod($year, $month);

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'journal_entries' => $this->activeEntries($supplierId, $year, $month, false),
            'monthly_records' => $this->activeRecordCount($supplierId, $year, $month),
            'ownership' => $this->ownership->legacyClaimStatus($supplierId, $year, $month),
        ];
    }

    /**
     * Předá období modulu: storno zápisu → odložení mzdového listu → uvolnění
     * rezervace.
     *
     * Idempotentní: měsíc, kde už legacy nic nedrží, projde bez zápisu.
     *
     * @param ?string $reversalDate datum protizápisu; `null` = datum originálu
     *                (u uzamčeného data se posune na dnešek, viz PostingService)
     * @return array{
     *   period:string,
     *   reversed_entry_ids:list<int>,
     *   reversal_entry_ids:list<int>,
     *   retired_records:int,
     *   ownership_released:bool
     * }
     */
    public function handOverToModule(
        int $supplierId,
        int $year,
        int $month,
        ?int $userId,
        string $reason,
        ?string $reversalDate = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        self::assertPeriod($year, $month);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new \InvalidArgumentException(
                'Předání mzdového období modulu vyžaduje důvod (max. 500 znaků).',
            );
        }
        if ($reversalDate !== null
            && \DateTimeImmutable::createFromFormat('!Y-m-d', $reversalDate) === false
        ) {
            throw new \InvalidArgumentException(
                'Datum storna musí být ve formátu YYYY-MM-DD.',
            );
        }

        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $reversedIds = [];
            $reversalIds = [];
            foreach ($this->activeEntries($supplierId, $year, $month, true) as $entry) {
                $reversedIds[] = $entry['id'];
                $reversalIds[] = $this->posting->reverse($supplierId, $entry['id'], [
                    'user_id' => $userId,
                    'posted_by' => $userId,
                    'ip' => $ip,
                    'user_agent' => $userAgent,
                    'entry_date' => $reversalDate,
                    'description' => sprintf(
                        'Storno mzdové rekapitulace %02d/%d — období přebírá modul Mzdy',
                        $month,
                        $year,
                    ),
                ]);
            }

            // Koho se odložení týká, se musí zjistit PŘED ním — potom už řádky
            // z filtrovaných čtení zmizí.
            $employeeIds = $this->employeesWithActiveRecord($supplierId, $year, $month);
            $retired = $this->records->retireForPeriod(
                $supplierId,
                $year,
                $month,
                $userId,
                $reason,
            );
            $openingsAdjusted = 0;
            foreach ($employeeIds as $employeeId) {
                $openingsAdjusted += $this->shrinkOpeningBalance(
                    $supplierId,
                    $employeeId,
                    $year,
                    $month,
                    $userId,
                );
            }

            $released = false;
            $claim = $this->ownership->legacyClaimStatus($supplierId, $year, $month);
            if (($claim['processor'] ?? null) === PayrollPeriodOwnershipService::PROCESSOR_LEGACY) {
                $this->ownership->releaseLegacy(
                    $supplierId,
                    $year,
                    $month,
                    $userId,
                    $reason,
                    $ip,
                    $userAgent,
                );
                $released = true;
            }

            $this->activityLogger->log(
                'payroll.legacy_recapitulation.handed_over',
                $userId,
                'payroll_period_ownership',
                null,
                [
                    'period' => sprintf('%04d-%02d', $year, $month),
                    'reversed_entry_ids' => $reversedIds,
                    'reversal_entry_ids' => $reversalIds,
                    'retired_records' => $retired,
                    'openings_adjusted' => $openingsAdjusted,
                    'ownership_released' => $released,
                    'reason' => $reason,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'period' => sprintf('%04d-%02d', $year, $month),
                'reversed_entry_ids' => $reversedIds,
                'reversal_entry_ids' => $reversalIds,
                'retired_records' => $retired,
                'openings_adjusted' => $openingsAdjusted,
                'ownership_released' => $released,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Dorovná počáteční stavy roku podle toho, co z ruční rekapitulace ještě
     * platí.
     *
     * Opravná cesta pro data převedená dřív, než {@see handOverToModule()}
     * uměl počáteční stavy ubírat. Projde odložené měsíce roku a každý z nich
     * z evidence openingu odebere. Idempotentní — měsíc, který v evidenci
     * openingu není, se přeskočí.
     *
     * @return int počet upravených kumulací
     */
    public function resyncOpeningBalances(
        int $supplierId,
        int $year,
        ?int $userId,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT employee_id, month
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ?
                AND retired_at IS NOT NULL
              ORDER BY employee_id, month',
        );
        $stmt->execute([$supplierId, $year]);

        $adjusted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $adjusted += $this->shrinkOpeningBalance(
                $supplierId,
                (int) $row['employee_id'],
                $year,
                (int) $row['month'],
                $userId,
            );
        }

        return $adjusted;
    }

    /**
     * Ubere z počátečních stavů roku měsíc, který nově počítá modul.
     *
     * ── Proč to musí být tady ───────────────────────────────────────────────
     * Počáteční stavy (`payroll_statutory_accumulator_openings`) jsou roční
     * kumulace za měsíce PŘED prvním obdobím modulu. U firmy, která začala
     * ruční rekapitulací, se plní právě z ní — u téhle instalace doslova
     * `source_reference = "… (payroll_monthly_records)"`. Jakmile měsíc přebere
     * modul, spočítá si ho sám a přičte ho ke KUMULACI, ve které už jednou je:
     * roční základ pojistného i daňová kumulace by ten měsíc počítaly dvakrát
     * a měsíce, kde se opening a vlastní výpočet potkají, skončí na blokátoru
     * `annual_accumulator_missing`.
     *
     * Uživatel to opravit nemohl: {@see PayrollOpeningBalanceService::save()}
     * je (správně) zavřená, jakmile je za rok jakákoli schválená mzda — a při
     * převodu už schválená je. Opravu proto dělá TA operace, která počáteční
     * stav zneplatnila, ve stejné transakci a s doloženým předchůdcem.
     *
     * Ubírá se jen měsíc, který je v evidenci openingu skutečně rozepsaný.
     * Opening zadaný ručně od jiného plátce se nikdy netrefí, takže se
     * nezmění.
     *
     * @return int počet upravených kumulací (druhů)
     */
    private function shrinkOpeningBalance(
        int $supplierId,
        int $employeeId,
        int $year,
        int $month,
        ?int $userId,
    ): int {
        $adjusted = 0;
        foreach (self::OPENING_KINDS as $kind) {
            $previous = $this->accumulators->openingBalance(
                $supplierId,
                $employeeId,
                $year,
                $kind,
            );
            if ($previous === null) {
                continue;
            }
            $evidence = is_array($previous['evidence'] ?? null)
                ? $previous['evidence']
                : [];
            $months = is_array($evidence['months'] ?? null)
                ? array_values($evidence['months'])
                : [];
            $remaining = array_values(array_filter(
                $months,
                static fn (mixed $row): bool => !is_array($row)
                    || (int) ($row['month'] ?? 0) !== $month,
            ));
            if (count($remaining) === count($months)) {
                continue;
            }

            $values = $kind === 'social_insurance'
                ? ['assessment_base_minor_units' => 0]
                : ['completed_months' => count($remaining)]
                    + array_fill_keys(self::OPENING_TAX_FIELDS, 0);
            foreach ($remaining as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ($kind === 'social_insurance') {
                    $values['assessment_base_minor_units'] +=
                        (int) ($row['social_assessment_base_minor_units'] ?? 0);
                    continue;
                }
                foreach (self::OPENING_TAX_FIELDS as $field) {
                    $values[$field] += (int) ($row[$field] ?? 0);
                }
            }

            $this->accumulators->appendOpeningBalance(
                $supplierId,
                $employeeId,
                $year,
                $kind,
                $values,
                sprintf(
                    'Převod %02d/%d do modulu Mzdy — měsíc odebrán z počátečních stavů',
                    $month,
                    $year,
                ),
                ['months' => $remaining],
                sprintf(
                    'legacy-handover:%04d-%02d:%d:%s',
                    $year,
                    $month,
                    $employeeId,
                    $kind,
                ),
                (int) $previous['id'],
                $userId,
            );
            ++$adjusted;
        }

        return $adjusted;
    }

    /**
     * Zaměstnanci, kteří mají za období PLATNÝ (neodložený) mzdový list.
     *
     * @return list<int>
     */
    private function employeesWithActiveRecord(
        int $supplierId,
        int $year,
        int $month,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT employee_id
               FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ? AND month = ?
                AND retired_at IS NULL
              ORDER BY employee_id',
        );
        $stmt->execute([$supplierId, $year, $month]);

        return array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Nestornované zápisy ruční rekapitulace za období.
     *
     * @return list<array{id:int,entry_date:string,description:?string}>
     */
    private function activeEntries(
        int $supplierId,
        int $year,
        int $month,
        bool $lock,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, entry_date, description
               FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id = ?
                AND reversed_by IS NULL
              ORDER BY id'
            . ($lock ? ' FOR UPDATE' : ''),
        );
        $stmt->execute([
            $supplierId,
            self::LEGACY_JOURNAL_SOURCE_TYPE,
            $year * 100 + $month,
        ]);

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = [
                'id' => (int) $row['id'],
                'entry_date' => (string) $row['entry_date'],
                'description' => $row['description'] === null
                    ? null
                    : (string) $row['description'],
            ];
        }

        return $entries;
    }

    private function activeRecordCount(int $supplierId, int $year, int $month): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM payroll_monthly_records
              WHERE supplier_id = ? AND year = ? AND month = ?
                AND retired_at IS NULL',
        );
        $stmt->execute([$supplierId, $year, $month]);

        return (int) $stmt->fetchColumn();
    }

    private static function assertPeriod(int $year, int $month): void
    {
        if (!checkdate($month, 1, $year)) {
            throw new \InvalidArgumentException('Neplatné mzdové období.');
        }
    }
}
