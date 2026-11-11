<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\Payroll\PayrollDeletionException;
use MyInvoice\Repository\Payroll\PayrollEmploymentConflictException;
use MyInvoice\Repository\Payroll\PayrollEmploymentDeletionRepository;
use MyInvoice\Repository\Payroll\PayrollEmploymentNotFoundException;
use MyInvoice\Repository\Payroll\PayrollEmploymentRepository;
use MyInvoice\Repository\Payroll\PayrollInputRepository;
use MyInvoice\Repository\Payroll\PayrollJmhzWorkMonthRevisionPurger;
use MyInvoice\Repository\Payroll\PayrollTimeRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\Absence\PayrollImportAbsenceCompensationMaterializer;
use PDO;

/**
 * Sloučení dvou pracovních vztahů téže osoby: data ZDROJOVÉHO vztahu se
 * přesunou k CÍLOVÉMU a zdroj se smaže.
 *
 * ── Kdy ───────────────────────────────────────────────────────────────────────
 * Import registrací ČSSZ založí osobě vztah s registrací, účetní jí ručně založí
 * druhý vztah a na ten naimportuje docházku a mzdové vstupy. Registrovaný vztah
 * je ten správný pro ČSSZ i JMHZ, data ale leží na ručním. Registrovaný smazat
 * nejde (registrace proběhla navenek), ruční nejde kvůli vstupům. Sloučení data
 * přesune a ruční vztah smaže.
 *
 * ── Co blokuje ────────────────────────────────────────────────────────────────
 * Zdroj smí mít jen to, co by šlo smazat, a navíc data, která se jen přesouvají:
 * mzdové vstupy, docházku, nepřítomnosti, směny, kalendáře a pravidla
 * příplatků. Z blokátorů mazání ({@see PayrollEmploymentDeletionRepository})
 * proto při sloučení neplatí jen „mzdové vstupy" (`payroll_employment_has_inputs`):
 * vstupy se nemažou, ale stěhují. Schválení vstupů a docházky zdroje sloučení
 * samo vrátí ({@see self::revertApprovals()}), protože se posuzovalo
 * podle zdroje a u cíle se schvaluje znovu; blokuje jen zamčený vstup.
 * Mění-li se převzetím podmínek druh cílového vztahu, vrátí stejně i schválení
 * cíle: posuzovalo se podle dosavadního druhu.
 * Mzdový běh zdroje i cíle za dotčené měsíce blokuje dřív, takže vrácené
 * schválení nikde započítané není. Všechno ostatní (registrace,
 * JMHZ, ELDP, mzdový běh, výstupní doklady, nároky hodinové mzdy, cesty,
 * spoření, dávky) drží dál, protože se počítalo nebo hlásilo právě pod id
 * zdroje a přesunem by se ten vztah k podání ztratil.
 *
 * Souhrn pracovního měsíce pro JMHZ (`payroll_jmhz_work_month_revisions`)
 * vzniká už schválením docházky, není to podání. Po znovuotevření měsíce
 * zůstane jen jako historie schválení měsíce, který se přesouvá, a zmizí se
 * zdrojem ({@see PayrollJmhzWorkMonthRevisionPurger}, stejnou cestou jako při
 * smazání vztahu). Totéž platí pro prázdný
 * měsíc cíle, který nahradí měsíc zdroje.
 *
 * Každá tabulka s `employment_id` má v {@see self::TABLES} výslovné rozhodnutí.
 * Nová tabulka bez rozhodnutí neprojde testem `PayrollEmploymentMergeTablesTest`.
 */
final class PayrollEmploymentMergeService
{
    /** Řádky se přesunou k cílovému vztahu. */
    public const MOVE = 'move';

    /** Řádek u zdroje sloučení zablokuje. */
    public const BLOCK = 'block';

    /** Lešení zdroje, které zmizí se zdrojem (FK kaskáda nebo mazání vztahu). */
    public const DROP = 'drop';

    /**
     * Rozhodnutí pro každou tabulku s `employment_id`.
     *
     * - `unique`: sloupce, které spolu s vztahem tvoří unikátní klíč. Když je
     *   cíl má se stejnou hodnotou, sloučení skončí 409 místo chyby databáze.
     * - `blocks`: řádky přesouvané tabulky, které sloučení přesto blokují.
     * - `handler`: tabulka se přesouvá vlastní cestou (viz `move*()`).
     *
     * Pořadí je pořadí přesunu: kalendáře před směnami (směna ukazuje na kalendář).
     *
     * @var array<string,array{action:string,reason?:string,unique?:list<string>,blocks?:array{where:string,reason:string},handler?:string}>
     */
    public const TABLES = [
        // ── Přesouvá se ─────────────────────────────────────────────────────
        // Mzdové vstupy. Schválené se před přesunem vrátí do konceptu, zamčený
        // zpracoval mzdový běh. Klíč deduplikace nese id vztahu, přepisuje se.
        'payroll_inputs' => [
            'action' => self::MOVE,
            'handler' => 'inputs',
            'blocks' => ['where' => "{t}.status = 'locked'", 'reason' => 'locked'],
        ],
        // Pracovní měsíc docházky. Souhrny importu a ruční souhrny jsou
        // neměnné (triggery), takže se měsíc zkopíruje k cíli i s nimi
        // a originál zmizí s FK kaskádou zdroje. Schválený se nejdřív otevře.
        'payroll_time_months' => [
            'action' => self::MOVE,
            'handler' => 'time_months',
        ],
        'payroll_time_month_import_summaries' => ['action' => self::MOVE, 'handler' => 'time_month_child'],
        'payroll_time_month_manual_summaries' => ['action' => self::MOVE, 'handler' => 'time_month_child'],
        'payroll_time_entries' => ['action' => self::MOVE, 'unique' => ['source_kind', 'source_hash']],
        // Kalendáře se přesunou, jen když je cíl nemá. Jinak platí kalendář
        // cíle a směny zdroje se na něj přepojí.
        'payroll_work_calendars' => ['action' => self::MOVE, 'handler' => 'calendars'],
        'payroll_shifts' => ['action' => self::MOVE, 'handler' => 'shifts'],
        'payroll_attendance_import_rows' => ['action' => self::MOVE, 'unique' => ['import_id', 'meaning', 'component_code']],
        'payroll_absences' => ['action' => self::MOVE],
        'payroll_employment_deferred_incomes' => ['action' => self::MOVE, 'unique' => ['period_start']],
        // Dvě historie pravidel příplatků nejdou proložit, přesunou se jen k cíli bez pravidel.
        'payroll_employment_surcharge_policies' => ['action' => self::MOVE, 'handler' => 'exclusive'],
        'payroll_surcharge_period_claims' => ['action' => self::MOVE, 'unique' => ['period_start', 'surcharge_kind']],
        'payroll_overtime_consents' => ['action' => self::MOVE],
        'payroll_overtime_protections' => ['action' => self::MOVE, 'unique' => ['protection', 'valid_from']],
        'payroll_overtime_compensations' => ['action' => self::MOVE, 'unique' => ['overtime_date']],
        // Pravidelné složky drží koncepty vstupů zdroje (FK), stěhují se s nimi.
        'payroll_recurring_components' => ['action' => self::MOVE, 'unique' => ['component_id', 'valid_from']],
        // Párování importu na vztah: další import téhož zdroje má trefit cíl.
        'payroll_import_links' => ['action' => self::MOVE],

        // ── Lešení zdroje, zmizí s ním ──────────────────────────────────────
        // Podmínky: s `adopt_terms` je cíl převezme, jinak platí podmínky cíle.
        'payroll_employment_terms' => ['action' => self::DROP],
        'payroll_employment_checklist_items' => ['action' => self::DROP],
        // Časovou osu zdroje nahradí událost `merged` u cíle a auditní záznam.
        'payroll_employment_events' => ['action' => self::DROP],
        // Účetní zatřídění platí cíle; odlišné zatřídění zdroje hlásí varování.
        'payroll_employment_dimensions' => ['action' => self::DROP],
        'payroll_registration_change_scans' => ['action' => self::DROP],
        // Souhrny JMHZ dřívějších schválení docházky zdroje. Jsou neměnné a drží
        // je cizí klíč, proto je maže PayrollJmhzWorkMonthRevisionPurger ještě před přesunem měsíců.
        'payroll_jmhz_work_month_revisions' => ['action' => self::DROP],
        // Převzatý profil registrace A1 a návrhy hlášení změn jsou pracovní
        // podklady, ne úkon u ČSSZ. Zmizí se zdrojem stejně jako při smazání
        // vztahu (PayrollEmploymentDeletionRepository). Kdyby blokovaly, vztah
        // z importu registrací by nešel sloučit ani jedním směrem.
        'payroll_registration_a1_profiles' => ['action' => self::DROP],
        'payroll_registration_change_proposals' => ['action' => self::DROP],
        // Průměrný výdělek je výpočet nad vztahem a se smazaným vztahem zmizí
        // (FK kaskáda). Kde se podle něj už počítalo, drží zdroj mzdový běh
        // nebo kniha dovolené. Cíl si průměr spočítá znovu nad sloučenými daty.
        'payroll_average_earning_snapshots' => ['action' => self::DROP],

        // ── Blokuje ─────────────────────────────────────────────────────────
        // Registraci drží jen skutečný úkon: snímek podané registrace, událost,
        // evidence A2, identifikátory vztahu od ČSSZ a úkol ztotožnění.
        'payroll_registration_identity_snapshots' => ['action' => self::BLOCK, 'reason' => 'registration'],
        'payroll_registration_event_snapshots' => ['action' => self::BLOCK, 'reason' => 'registration'],
        'payroll_registration_a2_evidence_ledger' => ['action' => self::BLOCK, 'reason' => 'registration'],
        'payroll_employment_external_ids' => ['action' => self::BLOCK, 'reason' => 'registration'],
        'payroll_identity_resolution_tasks' => ['action' => self::BLOCK, 'reason' => 'registration'],
        'payroll_jmhz_eldp_evidence_snapshots' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_jmhz_eldp_idempotency_claims' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_jmhz_ordinary_evidence_snapshots' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_jmhz_ordinary_evidence_idempotency_claims' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_jmhz_deferrals' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_external_jmhz_submission_forms' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_pension_requests' => ['action' => self::BLOCK, 'reason' => 'submission'],
        'payroll_eldp_statements' => ['action' => self::BLOCK, 'reason' => 'eldp'],
        'payroll_eldp_statement_claims' => ['action' => self::BLOCK, 'reason' => 'eldp'],
        'payroll_run_employments' => ['action' => self::BLOCK, 'reason' => 'run'],
        'payroll_statutory_relationship_results' => ['action' => self::BLOCK, 'reason' => 'run'],
        'payroll_migration_reference_totals' => ['action' => self::BLOCK, 'reason' => 'takeover'],
        'payroll_employment_exit_revisions' => ['action' => self::BLOCK, 'reason' => 'document'],
        'payroll_wage_statement_revisions' => ['action' => self::BLOCK, 'reason' => 'document'],
        'payroll_taxable_income_confirmation_requests' => ['action' => self::BLOCK, 'reason' => 'document'],
        'payroll_hourly_wage_claims' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_hourly_time_entitlements' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_hourly_time_entitlement_revisions' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_hourly_included_consumption' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_hourly_evidence_revisions' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_hourly_wage_materializations' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_surcharge_input_materializations' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_standby_floor_proofs' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_termination_floor_proofs' => ['action' => self::BLOCK, 'reason' => 'calculated'],
        'payroll_business_trips' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_risky_savings_contributions' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_risky_savings_evidence' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_insolvency_payment_instructions' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_enforcement_termination_notices' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_discount_intents' => ['action' => self::BLOCK, 'reason' => 'money'],
        'payroll_leave_ledger' => ['action' => self::BLOCK, 'reason' => 'leave'],
        'payroll_leave_entitlement_snapshots' => ['action' => self::BLOCK, 'reason' => 'leave'],
        'payroll_sickness_cases' => ['action' => self::BLOCK, 'reason' => 'leave'],
        'payroll_employment_terminations' => ['action' => self::BLOCK, 'reason' => 'termination'],
        'payroll_employment_survivors' => ['action' => self::BLOCK, 'reason' => 'termination'],
    ];

    /** @var array<string,array{code:string,message:string}> */
    private const BLOCK_REASONS = [
        'registration' => [
            'code' => 'payroll_merge_source_registered',
            'message' => 'Slučovaný vztah má z aplikace podanou nebo připravenou registraci ČSSZ, nebo identifikátory '
                . 'vztahu od ČSSZ. Slučujte opačně: otevřete druhý vztah a sloučte ho do tohoto.',
        ],
        'submission' => [
            'code' => 'payroll_merge_source_has_submissions',
            'message' => 'Za slučovaný vztah už bylo připravené nebo odeslané hlášení JMHZ nebo jiné podání ČSSZ. '
                . 'Podání se váže k tomuto vztahu, proto ho sloučit nelze.',
        ],
        'eldp' => [
            'code' => 'payroll_merge_source_has_eldp_statement',
            'message' => 'Za slučovaný vztah je sestavený evidenční list důchodového pojištění, proto ho sloučit nelze.',
        ],
        // Text doplní PayrollEmploymentDeletionRepository::runBlockerMessage() o konkrétní běh.
        'run' => [
            'code' => 'payroll_merge_source_in_run',
            'message' => 'Slučovaný vztah je zahrnutý v mzdovém běhu nebo má vypočtené zákonné výsledky běhu. '
                . 'Výpočet se vázal na tento vztah, proto ho sloučit nelze.',
        ],
        'takeover' => [
            'code' => 'payroll_merge_source_has_takeover',
            'message' => 'Slučovaný vztah má převzaté výsledky mezd z předchozího programu. Převzaté výsledky '
                . 'se vážou k tomuto vztahu, proto ho sloučit nelze. Slučujte opačně: otevřete druhý vztah '
                . 'a sloučte ho do tohoto.',
        ],
        'document' => [
            'code' => 'payroll_merge_source_has_documents',
            'message' => 'K slučovanému vztahu je vydaný výstupní doklad, mzdový výměr nebo potvrzení o příjmech. '
                . 'Ty jsou neměnné, proto vztah sloučit nelze.',
        ],
        'calculated' => [
            'code' => 'payroll_merge_source_has_calculations',
            'message' => 'Slučovaný vztah má vypočtené nároky (hodinová mzda, příplatky, pohotovost). '
                . 'Výpočet se vázal na tento vztah, proto ho sloučit nelze.',
        ],
        'money' => [
            'code' => 'payroll_merge_source_has_money',
            'message' => 'Slučovaný vztah má pracovní cesty, příspěvky na spoření, exekuci, insolvenci nebo slevu '
                . 'na pojistném. Ty se sloučením nepřenášejí, proto vztah sloučit nelze.',
        ],
        'leave' => [
            'code' => 'payroll_merge_source_has_leave',
            'message' => 'Slučovaný vztah má knihu dovolené nebo případ nemocenské. '
                . 'Ty se počítaly podle tohoto vztahu, proto ho sloučit nelze.',
        ],
        'termination' => [
            'code' => 'payroll_merge_source_has_termination',
            'message' => 'Slučovaný vztah má zapsané skončení nebo pozůstalé. Takový vztah sloučit nelze.',
        ],
        'locked' => [
            'code' => 'payroll_merge_source_has_locked_inputs',
            'message' => 'Slučovaný vztah má zamčené mzdové vstupy, které už zpracoval mzdový běh. '
                . 'Takový vztah sloučit nelze.',
        ],
    ];

    /** Stavy cíle, do kterého se už nic přesouvat nesmí. */
    private const CLOSED_TARGET = ['ended', 'archived', 'no_show'];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentRepository $employments,
        private readonly PayrollEmploymentDeletionRepository $deletion,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollInputRepository $inputs,
        private readonly PayrollTimeRepository $time,
        private readonly PayrollJmhzWorkMonthRevisionPurger $workMonthRevisions,
    ) {}

    /**
     * @return array{employment:array<string,mixed>,warnings:list<array{code:string,message:string}>,merged_employment_id:int}
     */
    public function merge(
        int $supplierId,
        int $sourceId,
        int $targetId,
        int $sourceVersion,
        int $targetVersion,
        bool $adoptTerms,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        if ($sourceId === $targetId) {
            throw new PayrollFieldValidationException('Vyberte jiný pracovní vztah, než který slučujete.', 'target_employment_id');
        }
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employment_merge');
        }
        try {
            $result = $this->mergeLocked($supplierId, $sourceId, $targetId, $sourceVersion, $targetVersion, $adoptTerms, $note, $userId, $ip, $userAgent);
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_merge');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employment_merge');
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_merge');
            }
            throw $e;
        }
    }

    /**
     * @return array{employment:array<string,mixed>,warnings:list<array{code:string,message:string}>,merged_employment_id:int}
     */
    private function mergeLocked(
        int $supplierId,
        int $sourceId,
        int $targetId,
        int $sourceVersion,
        int $targetVersion,
        bool $adoptTerms,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        // Zámky v pořadí id, aby dvě protisměrná sloučení nevytvořila deadlock.
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status, start_date, actual_start_date, end_date, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id IN (?, ?)
              ORDER BY id
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceId, $targetId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        if (!isset($rows[$sourceId], $rows[$targetId])) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $source = $rows[$sourceId];
        $target = $rows[$targetId];
        if ((int) $source['row_version'] !== $sourceVersion) {
            throw new PayrollEmploymentConflictException((int) $source['row_version']);
        }
        if ((int) $target['row_version'] !== $targetVersion) {
            throw new PayrollEmploymentConflictException((int) $target['row_version']);
        }
        $sourceCode = (string) $source['code'];
        $targetCode = (string) $target['code'];
        if ((int) $source['employee_id'] !== (int) $target['employee_id']) {
            throw new PayrollDeletionException(
                'payroll_merge_other_person',
                'Sloučit jde jen dva pracovní vztahy téže osoby.',
                $sourceId,
                $sourceCode,
            );
        }
        if (in_array((string) $target['status'], self::CLOSED_TARGET, true)) {
            throw new PayrollDeletionException(
                'payroll_merge_target_closed',
                'Cílový vztah je ukončený, archivovaný nebo označený jako nenástup. Data do něj přesunout nejde; '
                . 'nejdřív vraťte jeho ukončení, nebo vyberte jiný vztah.',
                $targetId,
                $targetCode,
            );
        }

        $this->assertSourceMovable($supplierId, $sourceId, $sourceCode);

        $sourceStart = self::nullableString($source['actual_start_date'] ?? null) ?? self::nullableString($source['start_date'] ?? null);
        $targetStart = self::nullableString($target['actual_start_date'] ?? null) ?? self::nullableString($target['start_date'] ?? null);
        $periods = $this->affectedPeriods($supplierId, $sourceId);
        if ($adoptTerms && $sourceStart !== null && $targetStart !== null && $sourceStart < $targetStart) {
            $periods = array_merge($periods, self::monthsBetween($sourceStart, $targetStart));
        }
        $periods = array_values(array_unique($periods));
        sort($periods);
        $this->assertTargetNotInRun($supplierId, $targetId, $targetCode, $periods);

        $typeChanges = $adoptTerms && (string) $source['relation_type'] !== (string) $target['relation_type'];
        $reverted = $this->revertApprovals($supplierId, $sourceId, $userId);
        if ($typeChanges) {
            $targetReverted = $this->revertApprovals($supplierId, $targetId, $userId);
            $reverted = [
                'inputs' => $reverted['inputs'] + $targetReverted['inputs'],
                'months' => $reverted['months'] + $targetReverted['months'],
            ];
        }
        $plan = $this->timeMonthPlan($supplierId, $sourceId, $targetId, $targetCode);
        $inputs = $this->inputPlan($supplierId, $sourceId, $targetId, $targetCode);
        $this->assertNoUniqueCollisions($supplierId, $sourceId, $targetId, $targetCode);
        $sourceTerms = $this->latestTerms($supplierId, $sourceId);
        $dimensions = $this->count($supplierId, 'payroll_employment_dimensions', $sourceId);

        $droppedSummaries = array_merge(
            $this->workMonthRevisions->drop($supplierId, 'employment_id', [$sourceId]),
            $typeChanges
                ? $this->workMonthRevisions->drop($supplierId, 'employment_id', [$targetId])
                : $this->workMonthRevisions->drop($supplierId, 'time_month_id', $plan['replace']),
        );
        $moved = [];
        foreach (self::TABLES as $table => $decision) {
            if ($decision['action'] !== self::MOVE) {
                continue;
            }
            $count = match ($decision['handler'] ?? null) {
                'inputs' => $this->moveInputs($supplierId, $targetId, $inputs),
                'time_months' => $this->moveTimeMonths($supplierId, $targetId, $plan),
                'time_month_child' => null,
                'calendars' => $this->moveCalendars($supplierId, $sourceId, $targetId),
                'shifts' => $this->moveShifts($supplierId, $sourceId, $targetId),
                'exclusive' => $this->moveExclusive($supplierId, $table, $sourceId, $targetId, $targetCode),
                default => $this->moveRows($supplierId, $table, $sourceId, $targetId),
            };
            if ($count !== null && $count > 0) {
                $moved[$table] = $count;
            }
        }

        $this->deletion->deleteLocked($supplierId, $sourceId, $sourceVersion, $userId, $ip, $userAgent);

        $completed = $this->employments->completeMerge(
            $supplierId,
            $targetId,
            [
                'id' => $sourceId,
                'code' => $sourceCode,
                'relation_type' => (string) $source['relation_type'],
                'start' => $sourceStart,
                'fixed_term_end_on' => self::nullableString($sourceTerms['fixed_term_end_on'] ?? null),
                'end_date' => self::nullableString($source['end_date'] ?? null),
                'remuneration_type' => self::nullableString($sourceTerms['remuneration_type'] ?? null),
                'hourly_rate_minor' => isset($sourceTerms['hourly_rate_minor']) ? (int) $sourceTerms['hourly_rate_minor'] : null,
                'remuneration_basis_kind' => self::nullableString($sourceTerms['remuneration_basis_kind'] ?? null),
                'remuneration_basis' => self::nullableString($sourceTerms['remuneration_basis'] ?? null),
            ],
            $adoptTerms,
            $note,
            $moved,
            $userId,
        );
        if ($adoptTerms && isset($completed['diff']['start_date'])) {
            $this->followCalendarStart($supplierId, $targetId, (string) $targetStart, (string) $sourceStart);
        }

        $warnings = $this->warnings(
            $supplierId,
            $targetId,
            $completed['diff'],
            $source,
            $sourceCode,
            $targetCode,
            $moved,
            $inputs,
            $dimensions,
            $reverted,
        );
        $this->activityLogger->log(
            'payroll.employment.merged',
            $userId,
            'payroll_employment',
            $targetId,
            $completed['diff'] + [
                'adopt_terms' => $adoptTerms,
                'note' => $note,
                'dropped_jmhz_work_month_revisions' => $droppedSummaries,
                'reverted_approvals' => $reverted,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return ['employment' => $completed['employment'], 'warnings' => $warnings, 'merged_employment_id' => $sourceId];
    }

    private function assertSourceMovable(int $supplierId, int $sourceId, string $sourceCode): void
    {
        $columns = [];
        $reasons = [];
        foreach (self::TABLES as $table => $decision) {
            if ($decision['action'] === self::BLOCK) {
                $where = null;
                $reason = (string) $decision['reason'];
            } elseif (isset($decision['blocks'])) {
                $where = $decision['blocks']['where'];
                $reason = $decision['blocks']['reason'];
            } else {
                continue;
            }
            $alias = 'b' . count($columns);
            $columns[] = "EXISTS (SELECT 1 FROM {$table} {$alias} WHERE {$alias}.supplier_id = employment.supplier_id"
                . " AND {$alias}.employment_id = employment.id"
                . ($where === null ? '' : ' AND ' . str_replace('{t}', $alias, $where))
                . ") AS {$alias}";
            $reasons[$alias] = $reason;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM payroll_employments employment WHERE employment.supplier_id = ? AND employment.id = ?'
        );
        $stmt->execute([$supplierId, $sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($reasons as $alias => $reason) {
            if ((int) ($row[$alias] ?? 0) > 0) {
                $blocker = self::BLOCK_REASONS[$reason];
                $message = $blocker['message'];
                $run = $reason === 'run' ? $this->deletion->runInvolvement($supplierId, $sourceId) : null;
                if ($run !== null) {
                    $message = sprintf(
                        'Slučovaný vztah je zahrnutý v %s. Výpočet běhu se vázal na tento vztah, proto ho sloučit nelze.',
                        $run['label'],
                    );
                }
                throw new PayrollDeletionException($blocker['code'], $message, $sourceId, $sourceCode);
            }
        }
    }

    /**
     * Měsíce, kterých se přesun týká. Mzdový běh cíle za kterýkoli z nich už
     * počítal bez přesouvaných dat, proto sloučení blokuje.
     *
     * @return list<string> první dny měsíců
     */
    private function affectedPeriods(int $supplierId, int $sourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_start FROM payroll_inputs WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT period_start FROM payroll_time_months WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT DATE_FORMAT(starts_at_utc, '%Y-%m-01') FROM payroll_time_entries WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT DATE_FORMAT(starts_at_utc, '%Y-%m-01') FROM payroll_shifts WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT period_start FROM payroll_employment_deferred_incomes WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT period_start FROM payroll_surcharge_period_claims WHERE supplier_id = ? AND employment_id = ?
             UNION SELECT import.period_start
                     FROM payroll_attendance_import_rows import_row
                     JOIN payroll_attendance_imports import
                       ON import.supplier_id = import_row.supplier_id AND import.id = import_row.import_id
                    WHERE import_row.supplier_id = ? AND import_row.employment_id = ?"
        );
        $stmt->execute(array_merge(...array_fill(0, 7, [$supplierId, $sourceId])));
        $periods = array_map(static fn (mixed $value): string => substr((string) $value, 0, 8) . '01', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $absences = $this->db->pdo()->prepare(
            'SELECT date_from, date_to FROM payroll_absences WHERE supplier_id = ? AND employment_id = ?'
        );
        $absences->execute([$supplierId, $sourceId]);
        foreach ($absences->fetchAll(PDO::FETCH_ASSOC) as $absence) {
            $periods = array_merge($periods, self::monthsBetween((string) $absence['date_from'], (string) $absence['date_to']));
        }

        return array_values(array_unique($periods));
    }

    /** @param list<string> $periods */
    private function assertTargetNotInRun(int $supplierId, int $targetId, string $targetCode, array $periods): void
    {
        if ($periods === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($periods), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT MIN(period_start) FROM payroll_run_employments
              WHERE supplier_id = ? AND employment_id = ? AND period_start IN ({$placeholders})"
        );
        $stmt->execute([$supplierId, $targetId, ...$periods]);
        $period = $stmt->fetchColumn();
        if (is_string($period) && $period !== '') {
            throw new PayrollDeletionException(
                'payroll_merge_target_in_run',
                sprintf(
                    'Cílový vztah je zahrnutý v mzdovém běhu za %s, kterého se přesun týká. Běh se počítal bez '
                    . 'přesouvaných dat; nejdřív ho smažte nebo zrušte, teprve pak půjde vztahy sloučit.',
                    self::monthLabel($period),
                ),
                $targetId,
                $targetCode,
            );
        }
    }

    /**
     * Co se stane s pracovními měsíci: měsíc zdroje s docházkou se zkopíruje
     * k cíli, prázdný měsíc cíle ve stejném období se smaže, měsíc zdroje bez
     * docházky se zahodí. Když docházku za týž měsíc mají oba vztahy, je to 409.
     *
     * @return array{copy:list<int>,replace:list<int>}
     */
    private function timeMonthPlan(int $supplierId, int $sourceId, int $targetId, string $targetCode): array
    {
        $months = $this->db->pdo()->prepare(
            "SELECT month.id, month.employment_id, month.period_start, month.status, month.work_source,
                    EXISTS (SELECT 1 FROM payroll_time_month_import_summaries summary
                             WHERE summary.supplier_id = month.supplier_id AND summary.time_month_id = month.id) AS has_import,
                    EXISTS (SELECT 1 FROM payroll_time_month_manual_summaries summary
                             WHERE summary.supplier_id = month.supplier_id AND summary.time_month_id = month.id) AS has_manual,
                    EXISTS (SELECT 1 FROM payroll_jmhz_work_month_revisions revision
                             WHERE revision.supplier_id = month.supplier_id AND revision.time_month_id = month.id) AS has_jmhz
               FROM payroll_time_months month
              WHERE month.supplier_id = ? AND month.employment_id IN (?, ?)
              FOR UPDATE"
        );
        $months->execute([$supplierId, $sourceId, $targetId]);
        $byOwner = [$sourceId => [], $targetId => []];
        foreach ($months->fetchAll(PDO::FETCH_ASSOC) as $month) {
            $byOwner[(int) $month['employment_id']][(string) $month['period_start']] = $month;
        }
        $entries = $this->entryPeriods($supplierId, [$sourceId, $targetId]);
        // Hodiny nesou jen vstupy z docházky. Ručně zadaná odměna nebo nulová
        // měsíční mzda cíle z měsíce druhý zdroj hodin nedělá.
        $targetInputs = $this->db->pdo()->prepare(
            "SELECT DISTINCT period_start FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND status <> 'cancelled'
                AND (external_id LIKE 'attendance:%' OR external_id LIKE ?)"
        );
        $targetInputs->execute([$supplierId, $targetId, PayrollImportAbsenceCompensationMaterializer::EXTERNAL_ID_PREFIX . '%']);
        $inputPeriods = array_flip(array_map('strval', $targetInputs->fetchAll(PDO::FETCH_COLUMN)));

        $plan = ['copy' => [], 'replace' => []];
        $periods = array_unique(array_merge(array_keys($byOwner[$sourceId]), array_keys($entries[$sourceId] ?? [])));
        foreach ($periods as $period) {
            $sourceMonth = $byOwner[$sourceId][$period] ?? null;
            $sourceUses = isset($entries[$sourceId][$period]) || ($sourceMonth !== null && self::monthHasWork($sourceMonth));
            if (!$sourceUses) {
                continue;
            }
            $targetMonth = $byOwner[$targetId][$period] ?? null;
            // Schválení a souhrn JMHZ prázdného měsíce cíle hodiny nenesou,
            // měsíc zdroje ho nahradí.
            $targetUses = isset($entries[$targetId][$period])
                || isset($inputPeriods[$period])
                || ($targetMonth !== null && self::monthHasContent($targetMonth));
            if ($targetUses) {
                throw new PayrollDeletionException(
                    'payroll_merge_time_month_conflict',
                    sprintf(
                        'Docházku za %s mají oba vztahy (záznamy, souhrn nebo mzdové vstupy). Sloučení by z jednoho '
                        . 'měsíce udělalo dva zdroje hodin. Docházku za ten měsíc nechte jen u jednoho vztahu.',
                        self::monthLabel($period),
                    ),
                    $targetId,
                    $targetCode,
                );
            }
            if ($targetMonth !== null) {
                $plan['replace'][] = (int) $targetMonth['id'];
            }
            if ($sourceMonth !== null) {
                $plan['copy'][] = (int) $sourceMonth['id'];
            }
        }

        return $plan;
    }

    /** @param array<string,mixed> $month */
    private static function monthHasContent(array $month): bool
    {
        return (string) $month['work_source'] !== 'entries'
            || (int) $month['has_import'] > 0
            || (int) $month['has_manual'] > 0;
    }

    /** @param array<string,mixed> $month */
    private static function monthHasWork(array $month): bool
    {
        return (string) $month['status'] !== 'open'
            || (string) $month['work_source'] !== 'entries'
            || (int) $month['has_import'] > 0
            || (int) $month['has_manual'] > 0;
    }

    /**
     * @param list<int> $employmentIds
     * @return array<int,array<string,true>>
     */
    private function entryPeriods(int $supplierId, array $employmentIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($employmentIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT DISTINCT employment_id, DATE_FORMAT(starts_at_utc, '%Y-%m-01') AS period_start
               FROM payroll_time_entries
              WHERE supplier_id = ? AND employment_id IN ({$placeholders}) AND status <> 'superseded'"
        );
        $stmt->execute([$supplierId, ...$employmentIds]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['employment_id']][(string) $row['period_start']] = true;
        }

        return $result;
    }

    /**
     * Vstupy zdroje s přepsaným klíčem deduplikace. Kolize s nezrušeným vstupem
     * cíle za stejné období a se stejným klíčem je 409.
     *
     * @return list<array{id:int,external_id:?string,status:string}>
     */
    private function inputPlan(int $supplierId, int $sourceId, int $targetId, string $targetCode): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, period_start, source_kind, external_id, status
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $sourceId]);
        $plan = [];
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $externalId = self::rebindExternalId(self::nullableString($row['external_id']), $sourceId, $targetId);
            $plan[] = ['id' => (int) $row['id'], 'external_id' => $externalId, 'status' => (string) $row['status']];
            if ($externalId !== null && (string) $row['status'] !== 'cancelled') {
                $keys[(string) $row['period_start'] . '|' . (string) $row['source_kind'] . ':' . $externalId] = true;
            }
        }
        if ($keys === []) {
            return $plan;
        }
        $existing = $this->db->pdo()->prepare(
            'SELECT period_start, external_dedupe_key
               FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND external_dedupe_key IS NOT NULL'
        );
        $existing->execute([$supplierId, $targetId]);
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($keys[(string) $row['period_start'] . '|' . (string) $row['external_dedupe_key']])) {
                throw new PayrollDeletionException(
                    'payroll_merge_input_conflict',
                    sprintf(
                        'Cílový vztah už má za %s stejný mzdový vstup ze stejného zdroje (%s). Jeden z nich nejdřív '
                        . 'zrušte, teprve pak půjde vztahy sloučit.',
                        self::monthLabel((string) $row['period_start']),
                        (string) $row['external_dedupe_key'],
                    ),
                    $targetId,
                    $targetCode,
                );
            }
        }

        return $plan;
    }

    /**
     * Klíče vstupů, které nesou id vztahu. Musí po přesunu ukazovat na cíl,
     * jinak by je kontroly importu docházky (`PayrollTimeRepository::activeImportInputs()`)
     * nenašly a další import by vstup založil podruhé.
     */
    public static function rebindExternalId(?string $externalId, int $sourceId, int $targetId): ?string
    {
        if ($externalId === null) {
            return null;
        }
        $patterns = [
            // attendance:{RRRR-MM}:{vztah}:{kód} a leave:attendance:{RRRR-MM}:{vztah}:{význam}
            '/^((?:' . preg_quote(PayrollImportAbsenceCompensationMaterializer::EXTERNAL_ID_PREFIX, '/') . '|attendance:)\d{4}-\d{2}:)' . $sourceId . '(:.*)$/s',
            // termination:{druh}:{vztah}
            '/^(termination:[a-z0-9-]+:)' . $sourceId . '()$/',
            // převzetí mezd: {prefix}{RRRR-MM}:{vztah}
            '/^(' . preg_quote(PayrollLegacyHandoverInputCarrier::EXTERNAL_PREFIX, '/') . '\d{4}-\d{2}:)' . $sourceId . '()$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $externalId, $match) === 1) {
                return $match[1] . $targetId . $match[2];
            }
        }

        return $externalId;
    }

    private function assertNoUniqueCollisions(int $supplierId, int $sourceId, int $targetId, string $targetCode): void
    {
        foreach (self::TABLES as $table => $decision) {
            $unique = $decision['unique'] ?? [];
            if ($decision['action'] !== self::MOVE || $unique === []) {
                continue;
            }
            $on = implode(' AND ', array_map(static fn (string $column): string => "other.{$column} = moved.{$column}", $unique));
            $stmt = $this->db->pdo()->prepare(
                "SELECT 1 FROM {$table} moved
                   JOIN {$table} other ON other.supplier_id = moved.supplier_id AND other.employment_id = ? AND {$on}
                  WHERE moved.supplier_id = ? AND moved.employment_id = ?
                  LIMIT 1"
            );
            $stmt->execute([$targetId, $supplierId, $sourceId]);
            if ($stmt->fetchColumn() !== false) {
                throw new PayrollDeletionException(
                    'payroll_merge_conflict',
                    sprintf(
                        'Oba vztahy mají záznam se stejným určením (%s). Sloučení by ho zdvojilo; '
                        . 'nechte ho jen u jednoho vztahu, teprve pak půjde vztahy sloučit.',
                        self::tableLabel($table),
                    ),
                    $targetId,
                    $targetCode,
                );
            }
        }
    }

    /**
     * Vrátí schválení vztahu: schválené vstupy do konceptu a schválené měsíce
     * docházky znovu otevře. Vstupy první, otevření měsíce s importem schválené
     * importní vstupy nepustí. Stejná cesta jako tlačítka Vrátit do konceptu
     * a Znovu otevřít, jen za účetní najednou.
     *
     * @return array{inputs:int,months:int}
     */
    private function revertApprovals(int $supplierId, int $employmentId, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $inputs = $pdo->prepare(
            "SELECT id, row_version FROM payroll_inputs
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
              ORDER BY id"
        );
        $inputs->execute([$supplierId, $employmentId]);
        $revertedInputs = 0;
        foreach ($inputs->fetchAll(PDO::FETCH_ASSOC) as $input) {
            $this->inputs->revertToDraft($supplierId, (int) $input['id'], (int) $input['row_version']);
            ++$revertedInputs;
        }
        $months = $pdo->prepare(
            "SELECT period_start, row_version FROM payroll_time_months
              WHERE supplier_id = ? AND employment_id = ? AND status = 'approved'
              ORDER BY period_start"
        );
        $months->execute([$supplierId, $employmentId]);
        $reopened = 0;
        foreach ($months->fetchAll(PDO::FETCH_ASSOC) as $month) {
            $this->time->reopenMonth(
                $supplierId,
                $employmentId,
                (string) $month['period_start'],
                (int) $month['row_version'],
                'Sloučení do jiného pracovního vztahu téže osoby',
                $userId,
            );
            ++$reopened;
        }

        return ['inputs' => $revertedInputs, 'months' => $reopened];
    }

    /** @param list<array{id:int,external_id:?string,status:string}> $inputs */
    private function moveInputs(int $supplierId, int $targetId, array $inputs): int
    {
        $update = $this->db->pdo()->prepare(
            'UPDATE payroll_inputs SET employment_id = ?, external_id = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ?'
        );
        foreach ($inputs as $input) {
            $update->execute([$targetId, $input['external_id'], $supplierId, $input['id']]);
        }

        return count($inputs);
    }

    /** @param array{copy:list<int>,replace:list<int>} $plan */
    private function moveTimeMonths(int $supplierId, int $targetId, array $plan): int
    {
        // Prázdný měsíc cíle uvolní místo (unikátní vztah + období). Jeho
        // události smaže FK kaskáda, která append-only triggery nespouští.
        if ($plan['replace'] !== []) {
            $placeholders = implode(', ', array_fill(0, count($plan['replace']), '?'));
            $this->db->pdo()->prepare(
                "DELETE FROM payroll_time_months WHERE supplier_id = ? AND id IN ({$placeholders})"
            )->execute([$supplierId, ...$plan['replace']]);
        }
        foreach ($plan['copy'] as $monthId) {
            $copyId = $this->copyRow($supplierId, 'payroll_time_months', $monthId, ['employment_id' => $targetId]);
            foreach (['payroll_time_month_import_summaries', 'payroll_time_month_events'] as $child) {
                foreach ($this->childIds($supplierId, $child, 'time_month_id', $monthId) as $childId) {
                    $overrides = ['time_month_id' => $copyId];
                    if ($child !== 'payroll_time_month_events') {
                        $overrides['employment_id'] = $targetId;
                    }
                    $this->copyRow($supplierId, $child, $childId, $overrides);
                }
            }
            foreach ($this->childIds($supplierId, 'payroll_time_month_manual_summaries', 'time_month_id', $monthId) as $summaryId) {
                $summaryCopy = $this->copyRow($supplierId, 'payroll_time_month_manual_summaries', $summaryId, [
                    'time_month_id' => $copyId,
                    'employment_id' => $targetId,
                ]);
                foreach ($this->childIds($supplierId, 'payroll_time_month_manual_segments', 'summary_id', $summaryId) as $segmentId) {
                    $this->copyRow($supplierId, 'payroll_time_month_manual_segments', $segmentId, ['summary_id' => $summaryCopy]);
                }
            }
        }

        return count($plan['copy']);
    }

    private function moveCalendars(int $supplierId, int $sourceId, int $targetId): int
    {
        if ($this->count($supplierId, 'payroll_work_calendars', $targetId) > 0) {
            return 0;
        }

        return $this->moveRows($supplierId, 'payroll_work_calendars', $sourceId, $targetId);
    }

    /**
     * Směna ukazující na kalendář zdroje, který zůstal u zdroje (cíl má vlastní),
     * se přepojí na kalendář cíle platný ke dni směny. Kalendář zdroje pak
     * zmizí se zdrojem.
     */
    private function moveShifts(int $supplierId, int $sourceId, int $targetId): int
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_shifts shift
                SET shift.calendar_id = (
                    SELECT calendar.id FROM payroll_work_calendars calendar
                     WHERE calendar.supplier_id = shift.supplier_id AND calendar.employment_id = ?
                       AND calendar.valid_from <= DATE(shift.starts_at_utc)
                       AND (calendar.valid_to IS NULL OR calendar.valid_to >= DATE(shift.starts_at_utc))
                     ORDER BY calendar.valid_from DESC
                     LIMIT 1)
              WHERE shift.supplier_id = ? AND shift.employment_id = ?
                AND shift.calendar_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM payroll_work_calendars own
                                 WHERE own.supplier_id = shift.supplier_id AND own.id = shift.calendar_id
                                   AND own.employment_id = ?)'
        )->execute([$targetId, $supplierId, $sourceId, $targetId]);

        return $this->moveRows($supplierId, 'payroll_shifts', $sourceId, $targetId);
    }

    private function moveExclusive(int $supplierId, string $table, int $sourceId, int $targetId, string $targetCode): int
    {
        if ($this->count($supplierId, $table, $sourceId) === 0) {
            return 0;
        }
        if ($this->count($supplierId, $table, $targetId) > 0) {
            throw new PayrollDeletionException(
                'payroll_merge_conflict',
                sprintf(
                    'Oba vztahy mají vlastní %s. Dvě historie nejde proložit; nechte je jen u jednoho vztahu, '
                    . 'teprve pak půjde vztahy sloučit.',
                    self::tableLabel($table),
                ),
                $targetId,
                $targetCode,
            );
        }

        return $this->moveRows($supplierId, $table, $sourceId, $targetId);
    }

    private function moveRows(int $supplierId, string $table, int $sourceId, int $targetId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "UPDATE {$table} SET employment_id = ? WHERE supplier_id = ? AND employment_id = ?"
        );
        $stmt->execute([$targetId, $supplierId, $sourceId]);

        return $stmt->rowCount();
    }

    /**
     * Nástup cíle se posunul dřív: nejstarší kalendář cíle, který začínal
     * starým nástupem, začne novým, jinak by dny mezi nimi neměly rozvrh.
     */
    private function followCalendarStart(int $supplierId, int $targetId, string $from, string $to): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_work_calendars calendar
               JOIN (SELECT MIN(valid_from) AS first_from FROM payroll_work_calendars
                      WHERE supplier_id = ? AND employment_id = ?) earliest
                 ON earliest.first_from = calendar.valid_from
                SET calendar.valid_from = ?, calendar.row_version = calendar.row_version + 1
              WHERE calendar.supplier_id = ? AND calendar.employment_id = ? AND calendar.valid_from = ?'
        )->execute([$supplierId, $targetId, $to, $supplierId, $targetId, $from]);
    }

    /**
     * @param array<string,mixed> $diff
     * @param array<string,mixed> $source
     * @param array<string,int> $moved
     * @param list<array{id:int,external_id:?string,status:string}> $inputs
     * @return list<array{code:string,message:string}>
     */
    private function warnings(
        int $supplierId,
        int $targetId,
        array $diff,
        array $source,
        string $sourceCode,
        string $targetCode,
        array $moved,
        array $inputs,
        int $dimensions,
        array $reverted,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT EXISTS (SELECT 1 FROM payroll_registration_a1_profiles profile
                             WHERE profile.supplier_id = ? AND profile.employment_id = ?)
                 OR EXISTS (SELECT 1 FROM payroll_employment_external_ids external
                             WHERE external.supplier_id = ? AND external.employment_id = ?)
                 OR EXISTS (SELECT 1 FROM payroll_registration_identity_snapshots snapshot
                             WHERE snapshot.supplier_id = ? AND snapshot.employment_id = ?)'
        );
        $stmt->execute([$supplierId, $targetId, $supplierId, $targetId, $supplierId, $targetId]);
        $registered = (int) $stmt->fetchColumn() > 0;

        $warnings = [];
        if ($registered && isset($diff['start_date'])) {
            $warnings[] = [
                'code' => 'registration_start_differs',
                'message' => sprintf(
                    'Nástup cílového vztahu se posunul z %s na %s, registrace ČSSZ ale vede vztah od původního dne. '
                    . 'Podejte opravu registrace.',
                    self::dateLabel((string) $diff['start_date']['from']),
                    self::dateLabel((string) $diff['start_date']['to']),
                ),
            ];
        }
        if ($registered && isset($diff['relation_type'])) {
            $warnings[] = [
                'code' => 'imported_registration_differs',
                'message' => 'Vztah má registraci ČSSZ, která ho vede s původním druhem. Pokud jste ho u ČSSZ '
                    . 'přihlásili s nesprávným druhem činnosti, podejte opravu registrace.',
            ];
        }
        $sourceEnd = self::nullableString($source['end_date'] ?? null);
        if ($sourceEnd !== null) {
            $warnings[] = [
                'code' => 'source_end_not_adopted',
                'message' => sprintf(
                    'Slučovaný vztah skončil %s. Cílový vztah zůstal ve svém stavu; skončil-li také, ukončete ho akcí Ukončit.',
                    self::dateLabel($sourceEnd),
                ),
            ];
        }
        if ($reverted['inputs'] > 0 || $reverted['months'] > 0) {
            $warnings[] = [
                'code' => 'source_approval_reverted',
                'message' => 'Schválení docházky a mzdových vstupů se vrátilo, protože se posuzovalo podle slučovaného '
                    . 'vztahu nebo podle dosavadního druhu cílového vztahu. U cílového vztahu měsíce zkontrolujte a znovu schvalte.',
            ];
        }
        $drafts = count(array_filter($inputs, static fn (array $input): bool => $input['status'] === 'draft'));
        if ($drafts > 0) {
            $warnings[] = [
                'code' => 'draft_inputs_follow',
                'message' => "Mzdové vstupy v konceptu ({$drafts}) se přesunuly k cílovému vztahu. Před schválením je zkontrolujte.",
            ];
        }
        if (($moved['payroll_recurring_components'] ?? 0) > 0) {
            $warnings[] = [
                'code' => 'recurring_components_moved',
                'message' => 'Pravidelné mzdové složky slučovaného vztahu převzal cílový vztah. Zkontrolujte, že se nezdvojují s jeho vlastními.',
            ];
        }
        if ($dimensions > 0) {
            $warnings[] = [
                'code' => 'dimensions_not_moved',
                'message' => 'Účetní zatřídění slučovaného vztahu se nepřeneslo, platí zatřídění cílového vztahu. Zkontrolujte ho.',
            ];
        }
        if ($sourceCode !== '' && $sourceCode !== $targetCode) {
            $warnings[] = [
                'code' => 'attendance_code_differs',
                'message' => sprintf(
                    'Import docházky páruje lidi podle osobního čísla. Slučovaný vztah měl %s, cílový má %s. '
                    . 'Pokud docházkový systém posílá %s, změňte cílovému vztahu osobní číslo.',
                    $sourceCode,
                    $targetCode,
                    $sourceCode,
                ),
            ];
        }

        return $warnings;
    }

    /** @return array<string,mixed> */
    private function latestTerms(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fixed_term_end_on, remuneration_type, hourly_rate_minor, remuneration_basis_kind, remuneration_basis
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function count(int $supplierId, string $table, int $employmentId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employment_id = ?"
        );
        $stmt->execute([$supplierId, $employmentId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function childIds(int $supplierId, string $table, string $parentColumn, int $parentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM {$table} WHERE supplier_id = ? AND {$parentColumn} = ? ORDER BY id"
        );
        $stmt->execute([$supplierId, $parentId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Kopie řádku se všemi sloupci kromě id a generovaných, s přepsanými
     * hodnotami. Sloupce se čtou z tabulky, aby kopie nepřišla o sloupec
     * přidaný později.
     *
     * @param array<string,int> $overrides
     */
    private function copyRow(int $supplierId, string $table, int $id, array $overrides): int
    {
        $columns = [];
        foreach ($this->db->pdo()->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $name = (string) $column['Field'];
            $extra = strtolower((string) ($column['Extra'] ?? ''));
            if ($name === 'id' || str_contains($extra, 'generated') || str_contains($extra, 'virtual') || str_contains($extra, 'stored')) {
                continue;
            }
            $columns[] = $name;
        }
        $select = array_map(
            static fn (string $column): string => array_key_exists($column, $overrides) ? '?' : "`{$column}`",
            $columns,
        );
        $params = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $overrides)) {
                $params[] = $overrides[$column];
            }
        }
        $quoted = array_map(static fn (string $column): string => "`{$column}`", $columns);
        $this->db->pdo()->prepare(
            "INSERT INTO {$table} (" . implode(', ', $quoted) . ')'
            . ' SELECT ' . implode(', ', $select) . " FROM {$table} WHERE supplier_id = ? AND id = ?"
        )->execute([...$params, $supplierId, $id]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<string> první dny měsíců od `$from` do `$to` včetně */
    private static function monthsBetween(string $from, string $to): array
    {
        $month = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $last = substr($to, 0, 7) . '-01';
        $months = [];
        while ($month->format('Y-m-d') <= $last && count($months) < 600) {
            $months[] = $month->format('Y-m-d');
            $month = $month->modify('+1 month');
        }

        return $months;
    }

    private static function monthLabel(string $period): string
    {
        return (int) substr($period, 5, 2) . '/' . substr($period, 0, 4);
    }

    private static function dateLabel(string $date): string
    {
        return (int) substr($date, 8, 2) . '. ' . (int) substr($date, 5, 2) . '. ' . substr($date, 0, 4);
    }

    private static function tableLabel(string $table): string
    {
        return match ($table) {
            'payroll_time_entries' => 'časový záznam docházky',
            'payroll_attendance_import_rows' => 'řádek téhož importu docházky',
            'payroll_employment_deferred_incomes' => 'odložený příjem za týž měsíc',
            'payroll_employment_surcharge_policies' => 'pravidla příplatků',
            'payroll_surcharge_period_claims' => 'nárok na příplatek za týž měsíc',
            'payroll_overtime_protections' => 'ochrana před přesčasy od téhož dne',
            'payroll_overtime_compensations' => 'náhradní volno za přesčas téhož dne',
            'payroll_recurring_components' => 'pravidelná mzdová složka od téhož dne',
            default => $table,
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
