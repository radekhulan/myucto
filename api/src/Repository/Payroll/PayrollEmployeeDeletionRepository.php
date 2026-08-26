<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Smazání zaměstnance, kterého uživatel založil omylem.
 *
 * ── Vodicí princip ────────────────────────────────────────────────────────────
 * Blokovat smí VÝHRADNĚ důkaz pohybu: vnější úkon (podání, registrace u ČSSZ nebo
 * zdravotní pojišťovny), schválený výpočet, nebo peníze. Nic jiného. Vlastní
 * evidence osoby, poznámky, odškrtnuté checklisty ani rozepsané údaje důvodem
 * k blokaci nejsou — když nejsou pohyby, není co chránit.
 *
 * ── Rekurze přes vztahy ───────────────────────────────────────────────────────
 * Zaměstnance jde smazat právě tehdy, když jdou smazat VŠECHNY jeho pracovní
 * vztahy. Databáze sice `payroll_employments` kaskáduje, jenže vztahy mají vlastní
 * RESTRICT potomky — slepá kaskáda by skončila syrovou FK chybou. Proto se každý
 * vztah nejdřív posoudí `PayrollEmploymentDeletionRepository::canDelete()` a když
 * některý blokuje, hláška JMENUJE ten vztah a řekne proč.
 *
 * ── Sdílená tabulka se starší agendou ─────────────────────────────────────────
 * `payroll_employees` je TÁŽ tabulka, kterou používá Účetnictví → Mzdová
 * rekapitulace. Osoba proto může mít zaúčtované mzdy ve staré agendě
 * (`payroll_monthly_records`), i když v novém modulu nemá nic. Ta tabulka má
 * ON DELETE CASCADE, takže by ji databáze tiše smetla a v deníku by zůstaly
 * zaúčtované mzdy bez vazby na osobu. Blokujeme ji proto výslovně — je to důkaz
 * pohybu jako každý jiný.
 */
final class PayrollEmployeeDeletionRepository
{
    /**
     * Důkazy pohybu vázané přímo na OSOBU (nemají `employment_id`). Pohyby vázané
     * na konkrétní vztah sem nepatří — ty se hlásí rekurzí přes vztahy, protože
     * hláška musí jmenovat, KTERÝ vztah mazání blokuje.
     *
     * @var array<string,array{tables:list<string>,code:string,message:string}>
     */
    private const BLOCKERS = [
        'legacy_payroll' => [
            'tables' => ['payroll_monthly_records'],
            'code' => 'payroll_employee_has_legacy_payroll',
            'message' => 'Zaměstnanec má spočítané mzdy ve starší agendě Mzdová rekapitulace '
                . 'a ty jsou navázané na účetní deník. Smazat ho nelze — místo toho ho '
                . 'deaktivujte.',
        ],
        'run' => [
            'tables' => ['payroll_run_persons'],
            'code' => 'payroll_employee_in_run',
            'message' => 'Zaměstnanec je zahrnutý v revizi mzdového běhu. '
                . 'Nejdřív smažte nebo zrušte ten mzdový běh, teprve pak půjde osobu smazat.',
        ],
        'document' => [
            'tables' => [
                'payroll_generated_documents',
                'payroll_annual_document_revisions',
                'payroll_annual_document_sources',
            ],
            'code' => 'payroll_employee_has_documents',
            'message' => 'Zaměstnanci už byla vydaná výplatní páska, mzdový list nebo roční '
                . 'potvrzení. Ty doklady jsou neměnné, takže osobu smazat nelze.',
        ],
        'registration' => [
            'tables' => ['payroll_person_external_ids'],
            'code' => 'payroll_employee_registered',
            'message' => 'Zaměstnanec má přidělený identifikátor z registrace u ČSSZ nebo MPSV. '
                . 'Ten úkon už proběhl navenek, takže osobu smazat nelze.',
        ],
        // Záměr uplatňovat slevu na pojistném je evidence úkonu vůči ČSSZ
        // (§ 23e) a zároveň jediný doklad o tom, proč se pojistné odvedlo
        // ponížené. Smazat osobu i s ním by ten doklad odstranilo, přestože
        // podle § 7c odst. 3 může být předmětem doměření.
        'discount_intent' => [
            'tables' => ['payroll_discount_intents'],
            'code' => 'payroll_employee_has_discount_intent',
            'message' => 'Za zaměstnance je evidovaný záměr uplatňovat slevu na pojistném '
                . '(OZUSPOJ). Je to doklad k odvedenému pojistnému, takže osobu smazat nelze.',
        ],
        'calculation' => [
            'tables' => ['payroll_net_results', 'payroll_statutory_accumulator_openings'],
            'code' => 'payroll_employee_has_calculation',
            'message' => 'Zaměstnanec má schválený mzdový výpočet nebo počáteční stavy '
                . 'zákonných úhrnů. Smazat ho nelze.',
        ],
        // Provedené roční zúčtování je právní úkon plátce daně vůči poplatníkovi
        // (§ 38ch ZDP) a váže se na neměnný doklad. Smazat osobu, které se
        // zúčtování provedlo, by znamenalo ztratit vazbu na ten doklad.
        // ŽÁDOST samotná blokující není — je to jen evidence podkladů a mizí
        // s osobou (viz CASCADE níž).
        'annual_settlement' => [
            'tables' => ['payroll_annual_settlement_outcomes'],
            'code' => 'payroll_employee_has_annual_settlement',
            'message' => 'Zaměstnanci bylo provedeno roční zúčtování záloh. '
                . 'Ten doklad je neměnný, takže osobu smazat nelze.',
        ],
        'money' => [
            'tables' => [
                'payroll_payment_liabilities',
                'payroll_payout_allocations',
                'payroll_deduction_ledger',
                'payroll_benefit_accumulators',
            ],
            'code' => 'payroll_employee_has_money',
            'message' => 'Na zaměstnance jsou navázané peníze — platební závazek, výplata '
                . 'nebo sražená částka. Smazat ho nelze.',
        ],
        'deduction' => [
            'tables' => ['payroll_deduction_agreements'],
            'code' => 'payroll_employee_has_deduction_agreement',
            'message' => 'Zaměstnanec má sjednanou dohodu o srážkách ze mzdy. '
                . 'Nejdřív ji zrušte, teprve pak půjde osobu smazat.',
        ],
        'enforcement' => [
            'tables' => [
                'payroll_enforcement_cases',
                'payroll_enforcement_dependants',
                'payroll_enforcement_month_results',
                'payroll_enforcement_person_month_evidence',
                'payroll_insolvency_payment_instructions',
            ],
            'code' => 'payroll_employee_has_enforcement',
            'message' => 'Na zaměstnance je vedená exekuce nebo insolvence. '
                . 'Ty záznamy jsou neměnné, takže osobu smazat nelze.',
        ],
    ];

    /**
     * Vlastní evidence osoby — zmizí spolu s ní. Klíč se propisuje do potvrzovacího
     * dialogu a do auditního payloadu.
     *
     * @var array<string,list<string>>
     */
    private const CASCADE = [
        'employments' => ['payroll_employments'],
        'profile' => [
            'payroll_employee_profiles',
            'payroll_person_identity_history',
            'payroll_person_addresses',
            'payroll_person_contacts',
            'payroll_person_identifiers',
            'payroll_person_accounts',
        ],
        'dependants' => ['payroll_dependants'],
        'tax' => [
            'payroll_person_tax_declarations',
            'payroll_person_tax_residences',
            'payroll_person_tax_credit_claims',
            'payroll_person_tax_child_claims',
            // Žádost o roční zúčtování a její podklady (§ 38ch odst. 1 a 3).
            // Bez osoby nemají smysl a nic navenek neprokazují — na rozdíl od
            // PROVEDENÉHO zúčtování, které mazání blokuje.
            'payroll_annual_settlement_requests',
            // Potvrzení od předchozích plátců (§ 38ch odst. 3). Je to podklad
            // k neprovedenému zúčtování téže osoby — bez ní nemá co dokládat.
            // Provedené zúčtování mazání blokuje dál, takže se tím nemaže nic,
            // o co by se pak někdo opřel.
            'payroll_annual_settlement_certificates',
        ],
        'insurance' => [
            'payroll_person_health_coverage_history',
            'payroll_person_health_minimum_reductions',
            'payroll_person_health_month_evidence',
            'payroll_person_health_other_employer_bases',
            'payroll_person_social_jurisdictions',
            'payroll_person_social_discount_claims',
        ],
        'payout_rules' => ['payroll_payout_rules'],
    ];

    /**
     * Výplatní pravidla mají FK RESTRICT, ale jsou to jen instrukce kam poslat
     * peníze — ne pohyb. Skutečné výplaty (`payroll_payout_allocations`) blokují.
     *
     * @var list<string>
     */
    private const RESTRICT_SCAFFOLD = ['payroll_payout_rules'];

    /**
     * Tabulky STARŠÍ agendy (Účetnictví → Mzdová rekapitulace) sdílené přes
     * `payroll_employees`. Do sondy na data NOVÉHO modulu nepatří: osoba, která má
     * jen zaúčtované mzdy ve staré agendě, by jinak dostala hlášku, že její data
     * leží v novém modulu — a to není pravda.
     *
     * @var list<string>
     */
    private const LEGACY_AGENDA_TABLES = ['payroll_monthly_records'];

    /**
     * Pohyby vázané na konkrétní VZTAH. Rozhodnutí je hlásí rekurzí (aby hláška
     * jmenovala vztah), atomická podmínka mazání je ale musí obsahovat taky —
     * jinak by souběh skončil syrovou FK chybou místo srozumitelné věty.
     *
     * @var list<string>
     */
    private const GUARD_ONLY = [
        'payroll_run_employments',
        'payroll_inputs',
        'payroll_business_trips',
        'payroll_jmhz_eldp_evidence_snapshots',
        'payroll_eldp_statements',
        'payroll_jmhz_ordinary_evidence_snapshots',
        'payroll_jmhz_ordinary_evidence_idempotency_claims',
        'payroll_employment_exit_revisions',
        'payroll_employment_external_ids',
        'payroll_identity_resolution_tasks',
        'payroll_registration_identity_snapshots',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollEmploymentDeletionRepository $employments,
    ) {}

    /**
     * Vrací `null`, když osoba v tomto tenantu neexistuje — volající to překlopí na
     * 404, aby cizí tenant nezjistil ani to, jestli id existuje.
     */
    public function canDelete(int $supplierId, int $employeeId): ?PayrollDeletionDecision
    {
        $stmt = $this->db->pdo()->prepare(self::decisionSql());
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        foreach (self::BLOCKERS as $alias => $blocker) {
            if ((int) $row["blocker_{$alias}"] > 0) {
                return PayrollDeletionDecision::blocked($blocker['code'], $blocker['message']);
            }
        }

        // Osoba jde smazat právě tehdy, když jdou smazat všechny její vztahy.
        // Blokující vztah hledáme jedním dotazem — seznam zaměstnanců počítá
        // `can_delete` pro každý řádek a dotaz na vztah by z něj udělal N+1.
        $blocking = $this->employments->firstBlockingEmployment($supplierId, $employeeId);
        if ($blocking !== null) {
            return PayrollDeletionDecision::blockedByEmployment(
                $blocking['code_key'],
                "Pracovní vztah {$blocking['code']} nejde smazat, a proto nejde smazat "
                . 'ani zaměstnanec. ' . $blocking['message'],
                $blocking['id'],
                $blocking['code'],
            );
        }

        $cascade = [];
        foreach (array_keys(self::CASCADE) as $alias) {
            $cascade[$alias] = (int) $row["cascade_{$alias}"];
        }

        return PayrollDeletionDecision::allowed($cascade);
    }

    /**
     * Kolik záznamů NOVÉHO mzdového modulu na osobě visí — po tabulkách, jen nenulové.
     *
     * Sonda pro STARŠÍ agendu (Účetnictví → Mzdová rekapitulace). Ta smí
     * `payroll_employees` mazat, jenže tabulka je SPOLEČNÁ a databáze pod ní kaskáduje
     * celý mzdový profil. Dokud modul není `active`, blokátory nad ním se neuplatní —
     * a data v něm přesto existovat můžou (stav `setup`: osoba se založí, vztah se
     * rozepíše, modul se ještě nepřeklopil). Volající je pak musí odmítnout,
     * ne je nechat tiše smést kaskádou.
     *
     * Vrací `[]` i pro neexistující osobu — „nemá data nového modulu" je pro cizí
     * tenant táž odpověď jako „neexistuje", takže se z ní nedá nic vyčíst.
     *
     * @return array<string,int> tabulka => počet
     */
    public function moduleDataCounts(int $supplierId, int $employeeId): array
    {
        $tables = self::moduleTables();
        $columns = [];
        foreach ($tables as $index => $table) {
            $columns[] = self::countExpression([$table]) . " AS probe{$index}";
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . implode(",\n       ", $columns)
            . "\n  FROM payroll_employees employee"
            . "\n WHERE employee.supplier_id = ? AND employee.id = ?"
        );
        $stmt->execute([$supplierId, $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }

        $counts = [];
        foreach ($tables as $index => $table) {
            $count = (int) $row["probe{$index}"];
            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /**
     * Registr tabulek nového modulu vázaných na osobu — jediný zdroj pravdy je
     * seznam, podle kterého se rozhoduje a maže. Kdyby si sonda vedla vlastní
     * kopii, nová tabulka by v ní chyběla a stará agenda by ji zase tiše smazala.
     *
     * Veřejný a statický záměrně: schématový test proti němu ověřuje, že žádná
     * tabulka s cizím klíčem na `payroll_employees` nezůstala mimo registr —
     * a to jde jen tehdy, když se dá SEZNAM ZAVOLAT (viz AGENTS.md).
     *
     * @return list<string>
     */
    public static function moduleTables(): array
    {
        $tables = [];
        foreach (self::BLOCKERS as $blocker) {
            foreach ($blocker['tables'] as $table) {
                $tables[] = $table;
            }
        }
        foreach (self::CASCADE as $group) {
            foreach ($group as $table) {
                $tables[] = $table;
            }
        }
        foreach (self::GUARD_ONLY as $table) {
            $tables[] = $table;
        }
        foreach (self::RESTRICT_SCAFFOLD as $table) {
            $tables[] = $table;
        }

        return array_values(array_diff(array_unique($tables), self::LEGACY_AGENDA_TABLES));
    }

    /**
     * Tabulky STARŠÍ agendy — protějšek {@see self::moduleTables()}. Schématový test
     * proti nim ověřuje, že každá tabulka s cizím klíčem na `payroll_employees`
     * patří buď novému modulu, nebo sem; jinak by ji stará agenda smazala naslepo.
     *
     * @return list<string>
     */
    public static function legacyAgendaTables(): array
    {
        return self::LEGACY_AGENDA_TABLES;
    }

    /**
     * @return array<string,int> počty smazané evidence pro audit a potvrzení
     */
    public function delete(
        int $supplierId,
        int $employeeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employee_delete');
        }

        try {
            $result = $this->deleteLocked($supplierId, $employeeId, $userId, $ip, $userAgent);
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employee_delete');
            }

            return $result;
        } catch (\Throwable $e) {
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employee_delete');
                $pdo->exec('RELEASE SAVEPOINT payroll_employee_delete');
            }
            throw $e;
        }
    }

    /** @return array<string,int> */
    private function deleteLocked(
        int $supplierId,
        int $employeeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $employee = $this->lock($supplierId, $employeeId);
        if ($employee === null) {
            throw new PayrollEmploymentNotFoundException('Zaměstnanec nebyl nalezen.');
        }

        // Přeověření POD ZÁMKEM — `can_delete` z vykresleného seznamu už mohlo zestárnout.
        $decision = $this->canDelete($supplierId, $employeeId);
        if ($decision === null) {
            throw new PayrollEmploymentNotFoundException('Zaměstnanec nebyl nalezen.');
        }
        if (!$decision->canDelete) {
            throw new PayrollDeletionException(
                (string) $decision->blockerCode,
                (string) $decision->blockerMessage,
                $decision->blockedEmploymentId,
                $decision->blockedEmploymentCode,
            );
        }

        foreach ($this->employmentIds($supplierId, $employeeId) as $employmentId => $code) {
            $this->employments->deleteLocked(
                $supplierId,
                $employmentId,
                null,
                $userId,
                $ip,
                $userAgent,
            );
        }

        foreach (self::RESTRICT_SCAFFOLD as $table) {
            $stmt = $this->db->pdo()->prepare(
                "DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?"
            );
            $stmt->execute([$supplierId, $employeeId]);
        }

        $guard = $this->db->pdo()->prepare(self::guardedDeleteSql());
        $guard->execute([$supplierId, $employeeId]);
        if ($guard->rowCount() !== 1) {
            throw new PayrollDeletionException(
                'payroll_employee_delete_conflict',
                'Zaměstnanec se mezitím změnil — někdo k němu přidal záznam. '
                . 'Načtěte seznam znovu a zkuste to prosím ještě jednou.',
            );
        }

        // Řádek zmizel, ale fakt, že existoval a kdo ho smazal, zůstat musí.
        $this->activityLogger->log(
            'payroll.employee.deleted',
            $userId,
            'payroll_employee',
            $employeeId,
            [
                'full_name' => (string) $employee['full_name'],
                'employment_type' => (string) $employee['employment_type'],
                'taxpayer_type' => (string) $employee['taxpayer_type'],
                'is_active' => (int) $employee['is_active'] === 1,
                'cascade' => $decision->cascade,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $decision->cascade;
    }

    /** @return array<string,string|int|null>|null */
    private function lock(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, full_name, employment_type, taxpayer_type, is_active
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
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

    /** @return array<int,string> id vztahu => kód vztahu */
    private function employmentIds(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY id ASC'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $result[(int) $row['id']] = (string) $row['code'];
            }
        }

        return $result;
    }

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
            . "\n  FROM payroll_employees employee"
            . "\n WHERE employee.supplier_id = ? AND employee.id = ?";
    }

    /**
     * DELETE podmíněný VŠEMI blokátory a zbytkem vztahů. Když mezi rozhodnutím
     * a mazáním vznikla vazba, smaže se 0 řádků a volající to ohlásí jako souběh —
     * místo aby uživatel dostal syrovou FK chybu z databáze.
     */
    private static function guardedDeleteSql(): string
    {
        $tables = ['payroll_employments'];
        foreach (self::BLOCKERS as $blocker) {
            foreach ($blocker['tables'] as $table) {
                $tables[] = $table;
            }
        }
        foreach (self::GUARD_ONLY as $table) {
            $tables[] = $table;
        }
        foreach (self::RESTRICT_SCAFFOLD as $table) {
            $tables[] = $table;
        }

        $joins = [];
        $conditions = [];
        foreach ($tables as $index => $table) {
            $alias = "guard{$index}";
            $joins[] = "  LEFT JOIN {$table} {$alias}"
                . "\n         ON {$alias}.supplier_id = employee.supplier_id"
                . "\n        AND {$alias}.employee_id = employee.id";
            $conditions[] = "   AND {$alias}.id IS NULL";
        }

        return "DELETE employee\n  FROM payroll_employees employee\n"
            . implode("\n", $joins)
            . "\n WHERE employee.supplier_id = ?"
            . "\n   AND employee.id = ?\n"
            . implode("\n", $conditions);
    }

    /** @param list<string> $tables */
    private static function countExpression(array $tables): string
    {
        $parts = [];
        foreach ($tables as $index => $table) {
            $alias = 'c' . $index . '_' . substr(md5($table), 0, 6);
            $parts[] = "(SELECT COUNT(*) FROM {$table} {$alias}"
                . " WHERE {$alias}.supplier_id = employee.supplier_id"
                . " AND {$alias}.employee_id = employee.id)";
        }

        return implode(' + ', $parts);
    }
}
