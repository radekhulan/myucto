<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Smazání pracovního vztahu, který **vůbec neměl vzniknout**.
 *
 * ── Proč to není totéž co `no_show` ───────────────────────────────────────────
 * „Nenástup" je záznam o tom, že něco nastalo: člověk byl přijat a nenastoupil.
 * Tohle je pro jiný případ — vztah byl založený omylem a nemá po sobě nechat
 * fiktivní nenástup. `no_show` proto zůstává a obě akce žijí vedle sebe.
 *
 * ── Co smí blokovat ───────────────────────────────────────────────────────────
 * Blokovat smí VÝHRADNĚ důkaz pohybu: vnější úkon (registrace u ČSSZ nebo
 * zdravotní pojišťovny, odeslané hlášení JMHZ), schválený výpočet (revize
 * mzdového běhu, výstupní doklad), nebo peníze (mzdové vstupy, cestovní náhrady,
 * příspěvky na spoření). Nic jiného. Když u něčeho nejsou pohyby, není co chránit.
 *
 * Odškrtnutý checklist proto NEBLOKUJE. Položka „Registrace ČSSZ — Splněno" je
 * poznámka člověka: někdo v aplikaci klikl. Navenek se nestalo nic. Důkazem je až
 * řádek v `payroll_registration_identity_snapshots` / `payroll_employment_external_ids`
 * — teprve ten mazání drží.
 *
 * ── Souběh ────────────────────────────────────────────────────────────────────
 * Mezi vykreslením `can_delete` a kliknutím mohla vzniknout vazba. Rozhodnutí se
 * proto pod zámkem řádku přeověřuje a samotný DELETE je znovu podmíněný VŠEMI
 * blokátory (vzor `PayrollRunRepository::deleteEmptyRunRow()`), takže souběh skončí
 * srozumitelnou hláškou, ne syrovou FK chybou z databáze.
 */
final class PayrollEmploymentDeletionRepository
{
    /**
     * Důkazy pohybu. Klíč = alias, hodnota = tabulky, kód pro frontend a věta,
     * podle které se dá jednat. Z téhle jediné definice se staví jak rozhodnutí,
     * tak podmínka mazání — nemůžou se tedy rozejít.
     *
     * @var array<string,array{tables:list<string>,code:string,message:string}>
     */
    private const BLOCKERS = [
        'registration' => [
            'tables' => [
                'payroll_registration_a1_profiles',
                'payroll_registration_identity_snapshots',
                'payroll_registration_event_snapshots',
                'payroll_registration_a2_evidence_ledger',
                'payroll_employment_external_ids',
                'payroll_identity_resolution_tasks',
            ],
            'code' => 'payroll_employment_registered',
            'message' => 'Pracovní vztah má skutečný záznam registrace u ČSSZ nebo zdravotní pojišťovny. '
                . 'Ten úkon už proběhl navenek, takže vztah smazat nelze. '
                . 'Pokud člověk nakonec nenastoupil, použijte Označit nenástup.',
        ],
        'submission' => [
            'tables' => [
                'payroll_jmhz_work_month_revisions',
                'payroll_jmhz_eldp_evidence_snapshots',
                'payroll_jmhz_eldp_idempotency_claims',
                'payroll_jmhz_ordinary_evidence_snapshots',
                'payroll_jmhz_ordinary_evidence_idempotency_claims',
            ],
            'code' => 'payroll_employment_has_submissions',
            'message' => 'Za tento pracovní vztah už bylo připraveno nebo odesláno hlášení JMHZ. '
                . 'Podání nejde vzít zpět smazáním vztahu — použijte Označit nenástup nebo Ukončit.',
        ],
        'eldp' => [
            'tables' => [
                'payroll_eldp_statements',
                'payroll_eldp_statement_claims',
            ],
            'code' => 'payroll_employment_has_eldp_statement',
            'message' => 'Za tento pracovní vztah je sestavený evidenční list důchodového '
                . 'pojištění. Z něj se člověku jednou počítá důchod, takže je neměnný a vztah '
                . 'smazat nelze — použijte Označit nenástup nebo Ukončit.',
        ],
        'run' => [
            'tables' => ['payroll_run_employments'],
            'code' => 'payroll_employment_in_run',
            'message' => 'Pracovní vztah je zahrnutý v revizi mzdového běhu. '
                . 'Nejdřív smažte nebo zrušte ten mzdový běh, teprve pak půjde vztah smazat.',
        ],
        'exit_document' => [
            'tables' => ['payroll_employment_exit_revisions'],
            'code' => 'payroll_employment_has_exit_documents',
            'message' => 'K pracovnímu vztahu je vydaný výstupní doklad. '
                . 'Ten je neměnný, takže vztah smazat nelze.',
        ],
        'input' => [
            'tables' => ['payroll_inputs'],
            'code' => 'payroll_employment_has_inputs',
            'message' => 'Pracovní vztah má zadané mzdové vstupy. '
                . 'Nejdřív je smažte v Mzdových vstupech, teprve pak půjde vztah smazat.',
        ],
        'trip' => [
            'tables' => ['payroll_business_trips'],
            'code' => 'payroll_employment_has_trips',
            'message' => 'Pracovní vztah má zaevidované pracovní cesty s náhradami. '
                . 'Nejdřív smažte ty cesty, teprve pak půjde vztah smazat.',
        ],
        'savings' => [
            'tables' => ['payroll_risky_savings_contributions'],
            'code' => 'payroll_employment_has_savings',
            'message' => 'K pracovnímu vztahu jsou zaevidované příspěvky na spoření na stáří '
                . 'z rizikové práce. Jde o peníze, takže vztah smazat nelze.',
        ],
    ];

    /**
     * Vlastní lešení vztahu — zmizí spolu s ním. Klíč se propisuje do potvrzovacího
     * dialogu (musí jmenovat, co přesně zmizí) a do auditního payloadu.
     *
     * @var array<string,list<string>>
     */
    private const CASCADE = [
        'terms' => ['payroll_employment_terms'],
        'checklist' => ['payroll_employment_checklist_items'],
        'events' => ['payroll_employment_events'],
        'dimensions' => ['payroll_employment_dimensions'],
        'components' => ['payroll_recurring_components'],
        'time' => [
            'payroll_work_calendars',
            'payroll_shifts',
            'payroll_time_entries',
            'payroll_time_months',
        ],
        'absences' => [
            'payroll_absences',
            'payroll_leave_ledger',
            'payroll_leave_entitlement_snapshots',
            'payroll_average_earning_snapshots',
        ],
    ];

    /**
     * Lešení, které má FK RESTRICT — databáze ho sama nekaskáduje, musíme ho smazat
     * ručně. Dimenze jsou účetní zatřídění a pravidelné složky stálý předpis; ani
     * jedno není pohyb. Zbytek lešení odklidí ON DELETE CASCADE, což zároveň korektně
     * obejde append-only triggery na vnucích (FK kaskáda triggery nespouští).
     *
     * @var list<string>
     */
    private const RESTRICT_SCAFFOLD = [
        'payroll_employment_dimensions',
        'payroll_recurring_components',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /**
     * Vrací `null`, když vztah v tomto tenantu neexistuje — volající to překlopí na
     * 404, aby cizí tenant nezjistil ani to, jestli id existuje.
     */
    public function canDelete(int $supplierId, int $employmentId): ?PayrollDeletionDecision
    {
        $stmt = $this->db->pdo()->prepare(self::decisionSql());
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        foreach (self::BLOCKERS as $alias => $blocker) {
            if ((int) $row["blocker_{$alias}"] > 0) {
                return PayrollDeletionDecision::blocked($blocker['code'], $blocker['message']);
            }
        }

        $cascade = [];
        foreach (array_keys(self::CASCADE) as $alias) {
            $cascade[$alias] = (int) $row["cascade_{$alias}"];
        }

        return PayrollDeletionDecision::allowed($cascade);
    }

    /**
     * Smaže vztah ve vlastní transakci (nebo savepointu, běží-li už transakce).
     *
     * @return array<string,int> počty smazaného lešení pro audit a potvrzení
     */
    public function delete(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employment_delete');
        }

        try {
            $result = $this->deleteLocked(
                $supplierId,
                $employmentId,
                $expectedVersion,
                $userId,
                $ip,
                $userAgent,
            );
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_delete');
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employment_delete');
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_delete');
            }
            throw $e;
        }
    }

    /**
     * Smaže vztah i jeho vlastní lešení. Musí běžet uvnitř transakce volajícího.
     *
     * @return array<string,int> počty smazaného lešení pro audit a potvrzení
     */
    public function deleteLocked(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employment = $this->lock($supplierId, $employmentId);
        if ($employment === null) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $rowVersion = (int) $employment['row_version'];
        if ($expectedVersion !== null && $rowVersion !== $expectedVersion) {
            throw new PayrollEmploymentConflictException($rowVersion);
        }

        // Přeověření POD ZÁMKEM — `can_delete` z vykresleného seznamu už mohlo zestárnout.
        $decision = $this->canDelete($supplierId, $employmentId);
        if ($decision === null) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        if (!$decision->canDelete) {
            throw new PayrollDeletionException(
                (string) $decision->blockerCode,
                (string) $decision->blockerMessage,
                $employmentId,
                (string) $employment['code'],
            );
        }

        foreach (self::RESTRICT_SCAFFOLD as $table) {
            $stmt = $this->db->pdo()->prepare(
                "DELETE FROM {$table} WHERE supplier_id = ? AND employment_id = ?"
            );
            $stmt->execute([$supplierId, $employmentId]);
        }

        $guard = $this->db->pdo()->prepare(self::guardedDeleteSql());
        $guard->execute([$supplierId, $employmentId, $rowVersion]);
        if ($guard->rowCount() !== 1) {
            throw new PayrollDeletionException(
                'payroll_employment_delete_conflict',
                'Pracovní vztah se mezitím změnil — někdo k němu mezitím přidal záznam. '
                . 'Načtěte kartu znovu a zkuste to prosím ještě jednou.',
                $employmentId,
                (string) $employment['code'],
            );
        }

        // Řádek zmizel, ale fakt, že existoval a kdo ho smazal, zůstat musí.
        $this->activityLogger->log(
            'payroll.employment.deleted',
            $userId,
            'payroll_employment',
            $employmentId,
            [
                'employee_id' => (int) $employment['employee_id'],
                'code' => (string) $employment['code'],
                'relation_type' => (string) $employment['relation_type'],
                'status' => (string) $employment['status'],
                'start_date' => $employment['start_date'],
                'end_date' => $employment['end_date'],
                'row_version' => $rowVersion,
                'cascade' => $decision->cascade,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $decision->cascade;
    }

    /**
     * První vztah osoby, který blokuje mazání — jedním dotazem, ne dotazem na
     * vztah. Seznam zaměstnanců počítá `can_delete` pro každý řádek, takže
     * rekurze „dotaz na vztah" by z něj udělala N+1.
     *
     * @return array{id:int,code:string,code_key:string,message:string}|null
     */
    public function firstBlockingEmployment(int $supplierId, int $employeeId): ?array
    {
        $branches = [];
        foreach (self::BLOCKERS as $alias => $blocker) {
            $branches[] = 'WHEN ' . self::existsExpression($blocker['tables'])
                . " THEN '{$alias}'";
        }
        $sql = 'SELECT employment.id, employment.code, CASE '
            . implode(' ', $branches)
            . ' END AS blocker'
            . ' FROM payroll_employments employment'
            . ' WHERE employment.supplier_id = ? AND employment.employee_id = ?'
            . ' HAVING blocker IS NOT NULL'
            . ' ORDER BY employment.id ASC LIMIT 1';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $alias = (string) $row['blocker'];
        $blocker = self::BLOCKERS[$alias]
            ?? throw new \LogicException('Neznámý blokátor pracovního vztahu.');

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'code_key' => $blocker['code'],
            'message' => $blocker['message'],
        ];
    }

    /** @return array<string,string|int|null>|null */
    public function lock(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, code, relation_type, status,
                    start_date, end_date, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || $value === null)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** Existují vůbec pro tenhle vztah nějaké pohyby? Pro rekurzi z mazání osoby. */
    private static function decisionSql(): string
    {
        $columns = [];
        foreach (self::BLOCKERS as $alias => $blocker) {
            $columns[] = self::countExpression($blocker['tables']) . " AS blocker_{$alias}";
        }
        foreach (self::CASCADE as $alias => $tables) {
            $columns[] = self::countExpression($tables) . " AS cascade_{$alias}";
        }

        return 'SELECT ' . implode(",\n       ", $columns)
            . "\n  FROM payroll_employments employment"
            . "\n WHERE employment.supplier_id = ? AND employment.id = ?";
    }

    /**
     * DELETE podmíněný VŠEMI blokátory najednou. Když mezi rozhodnutím a mazáním
     * vznikla vazba, smaže se 0 řádků a volající to ohlásí jako souběh — místo aby
     * uživatel dostal syrovou FK chybu z databáze.
     */
    private static function guardedDeleteSql(): string
    {
        $tables = [];
        foreach (self::BLOCKERS as $blocker) {
            foreach ($blocker['tables'] as $table) {
                $tables[] = $table;
            }
        }
        foreach (self::RESTRICT_SCAFFOLD as $table) {
            $tables[] = $table;
        }

        $joins = [];
        $conditions = [];
        foreach ($tables as $index => $table) {
            $alias = "guard{$index}";
            $joins[] = "  LEFT JOIN {$table} {$alias}"
                . "\n         ON {$alias}.supplier_id = employment.supplier_id"
                . "\n        AND {$alias}.employment_id = employment.id";
            $conditions[] = "   AND {$alias}.id IS NULL";
        }

        return "DELETE employment\n  FROM payroll_employments employment\n"
            . implode("\n", $joins)
            . "\n WHERE employment.supplier_id = ?"
            . "\n   AND employment.id = ?"
            . "\n   AND employment.row_version = ?\n"
            . implode("\n", $conditions);
    }

    /** @param list<string> $tables */
    private static function existsExpression(array $tables): string
    {
        $parts = [];
        foreach ($tables as $index => $table) {
            $alias = 'e' . $index . '_' . substr(md5($table), 0, 6);
            $parts[] = "EXISTS (SELECT 1 FROM {$table} {$alias}"
                . " WHERE {$alias}.supplier_id = employment.supplier_id"
                . " AND {$alias}.employment_id = employment.id)";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param list<string> $tables */
    private static function countExpression(array $tables): string
    {
        $parts = [];
        foreach ($tables as $index => $table) {
            $alias = 'c' . $index . '_' . substr(md5($table), 0, 6);
            $parts[] = "(SELECT COUNT(*) FROM {$table} {$alias}"
                . " WHERE {$alias}.supplier_id = employment.supplier_id"
                . " AND {$alias}.employment_id = employment.id)";
        }

        return implode(' + ', $parts);
    }
}
