<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Anonymizace osoby — protějšek úplného výmazu pro případ, kdy osoba MÁ účetní stopu.
 *
 * ── Proč vůbec dvě operace ────────────────────────────────────────────────────
 * {@see PayrollEmployeeDeletionRepository} umí osobu smazat celou, ale jen když po
 * ní nezůstal žádný pohyb — žádná zaúčtovaná mzda, žádná výplata, žádné podání.
 * Právě tahle podmínka je zároveň odpovědí na otázku „smí se to smazat, nebo jen
 * odosobnit?": osoba s účetní stopou zmizet NESMÍ, protože účetní záznam musí
 * zůstat. Rozhodnutí se proto NEDUPLIKUJE — bere se z `canDelete()`.
 *
 * ── Co anonymizace dělá ───────────────────────────────────────────────────────
 * Ruší IDENTITU, ne záznamy. Nesmaže ANI JEDEN řádek s částkou:
 *
 *   ZŮSTÁVÁ  mzdové snapshoty, výplaty, závazky, srážky, zákonné úhrny, zápisy
 *            v deníku — všechno včetně `employee_id`, takže se uzavřený rok
 *            nerozpadne a vazba v deníku neosiří.
 *   MIZÍ     jméno, rodné číslo, datum narození, adresy, kontakty, čísla účtů,
 *            historie identity a údaje o dětech.
 *
 * `payroll_employees` se proto NEMAŽE, jen přepisuje. Kdyby se řádek smazal,
 * databáze by kaskádou smetla `payroll_monthly_records` a s nimi mzdy navázané
 * na účetní deník — přesně ten scénář, před kterým varuje blokátor
 * `legacy_payroll` v mazací třídě.
 *
 * ── Co anonymizace VĚDOMĚ nedělá ──────────────────────────────────────────────
 * Nesahá na vydané dokumenty (`payroll_generated_documents`, roční potvrzení,
 * snapshoty podání). Ty nesou osobní údaj uvnitř zmrazeného obsahu a jejich
 * úklid je samostatná úloha — mazání souborů z úložiště má jinou třídu rizika
 * než UPDATE. {@see residue()} je proto SPOČÍTÁ a návrh je ukáže dopředu, aby
 * bylo vidět, co po anonymizaci ještě zbývá. Tichá mezera je horší než přiznaná.
 *
 * ── ROZHODNUTO: jméno přežívá úplný výmaz v auditní stopě ────────────────────
 * Tahle třída do auditu jméno NEZAPISUJE (payload nese jen počty a neosobní
 * zařazení; hlídá to test `testAuditPayloadCarriesNoPersonalData`). Úplný výmaz
 * ale jde přes {@see PayrollEmployeeDeletionRepository::delete()}, který loguje
 * `full_name` — a `full_name` záměrně není mezi `ActivityLogger::REDACT_KEYS`.
 * Po úplném výmazu tedy jméno v `activity_log` zůstává.
 *
 * Zadavatel v 8/2026 rozhodl, že to tak MÁ být, a redakce se nepřidává. Úplný
 * výmaz je nevratný a řádek po něm neexistuje; auditní zápis bez jména by
 * neřekl, koho se úkon týkal, a nešlo by z něj odlišit omylem založený
 * duplicitní záznam od skutečného člověka. Typickým důvodem ručního výmazu je
 * přitom právě omylem založená osoba, u které tam jméno patří. Řádné ukončení
 * retence jménem neprochází — to je anonymizace, tedy tahle třída.
 *
 * Kdo by to chtěl „opravit" jako přehlédnutí: odůvodnění je i u samotného
 * seznamu {@see \MyInvoice\Service\ActivityLogger}, protože zásah do REDACT_KEYS
 * by se dotkl sdíleného auditu celé aplikace, ne jen mezd.
 */
final class PayrollPersonAnonymizationRepository
{
    /**
     * Tabulky, které existují VÝHRADNĚ kvůli identifikaci osoby. Mažou se celé —
     * není v nich nic, co by po zániku identity dávalo smysl uchovat.
     *
     * @var array<string,list<string>>
     */
    private const IDENTITY_TABLES = [
        'identity_history' => ['payroll_person_identity_history'],
        'addresses' => ['payroll_person_addresses'],
        'contacts' => ['payroll_person_contacts'],
        'identifiers' => ['payroll_person_identifiers'],
        'accounts' => ['payroll_person_accounts'],
        'dependants' => ['payroll_dependants'],
        // Osobní číslo a normalizované jméno z importu docházky.
        'import_links' => ['payroll_import_links'],
    ];

    /**
     * Nosiče osobního údaje UVNITŘ obsahu, na které anonymizace nesahá. Nejsou to
     * blokátory — jsou to zbytky, které musí být vidět v návrhu.
     *
     * @var list<string>
     */
    private const RESIDUE_TABLES = [
        'payroll_generated_documents',
        'payroll_annual_document_revisions',
        'payroll_jmhz_eldp_evidence_snapshots',
        'payroll_jmhz_ordinary_evidence_snapshots',
        'payroll_registration_identity_snapshots',
        // Zmrazené mzdové běhy drží jméno i vstupy (včetně srážek) osoby.
        'payroll_run_persons',
        'payroll_run_revisions',
        // Archiv mezd za období obsahuje výplatní pásky a data osoby v ZIPu.
        'payroll_period_exports',
    ];

    /**
     * Zbytkové tabulky bez sloupce `employee_id`: osoba je v nich vnořená
     * v obsahu, takže se počítají přes vazbu na `payroll_run_persons`.
     * Placeholdery jsou vždy `supplier_id`, `employee_id`.
     *
     * @var array<string,string>
     */
    private const DERIVED_RESIDUE_SQL = [
        'payroll_run_revisions' =>
            'SELECT COUNT(DISTINCT r.id)
               FROM payroll_run_revisions r
               JOIN payroll_run_persons p ON p.revision_id = r.id AND p.supplier_id = r.supplier_id
              WHERE r.supplier_id = ? AND p.employee_id = ?',
        'payroll_period_exports' =>
            'SELECT COUNT(*)
               FROM payroll_period_exports e
              WHERE e.supplier_id = ?
                AND EXISTS (SELECT 1 FROM payroll_run_persons p
                             WHERE p.supplier_id = e.supplier_id AND p.employee_id = ?
                               AND p.period_start BETWEEN e.period_start AND e.period_end)',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @return list<string> */
    public static function identityTables(): array
    {
        $tables = [];
        foreach (self::IDENTITY_TABLES as $group) {
            foreach ($group as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /** @return list<string> */
    public static function residueTables(): array
    {
        return self::RESIDUE_TABLES;
    }

    /**
     * Zbytkové tabulky, které osobu nesou jen v obsahu (bez `employee_id`).
     *
     * @return list<string>
     */
    public static function derivedResidueTables(): array
    {
        return array_keys(self::DERIVED_RESIDUE_SQL);
    }

    /**
     * Kolik osobních údajů po anonymizaci ZŮSTANE ve zmrazeném obsahu.
     *
     * @return array<string,int> tabulka => počet, jen nenulové
     */
    public function residue(int $supplierId, int $employeeId): array
    {
        $out = [];
        foreach (self::RESIDUE_TABLES as $table) {
            $stmt = $this->db->pdo()->prepare(
                self::DERIVED_RESIDUE_SQL[$table]
                    ?? "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employee_id = ?"
            );
            $stmt->execute([$supplierId, $employeeId]);
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $out;
    }

    /**
     * Náhled: co přesně z osoby zmizí. Volá se PŘED schválením, aby návrh uměl
     * pojmenovat rozsah, ne jen slíbit „anonymizace".
     *
     * Vrací `null` pro cizí tenant i pro neexistující osobu — z odpovědi se
     * nesmí dát vyčíst, že id existuje.
     *
     * @return array{identity:array<string,int>,residue:array<string,int>}|null
     */
    public function preview(int $supplierId, int $employeeId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            return null;
        }

        $identity = [];
        foreach (self::IDENTITY_TABLES as $alias => $tables) {
            $count = 0;
            foreach ($tables as $table) {
                $probe = $this->db->pdo()->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE supplier_id = ? AND employee_id = ?"
                );
                $probe->execute([$supplierId, $employeeId]);
                $count += (int) $probe->fetchColumn();
            }
            if ($count > 0) {
                $identity[$alias] = $count;
            }
        }

        return ['identity' => $identity, 'residue' => $this->residue($supplierId, $employeeId)];
    }

    /**
     * Už je osoba anonymizovaná? Rozpoznává se podle náhradního jména, které
     * {@see anonymize()} zapisuje — opakované spuštění návrhu tak neanonymizuje
     * podruhé a auditní stopa nenaroste o prázdné záznamy.
     */
    public function isAnonymized(int $supplierId, int $employeeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT full_name FROM payroll_employees WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name === self::placeholderName($employeeId);
    }

    /**
     * Náhradní jméno je DETERMINISTICKÉ a neidentifikující. Musí být, protože
     * `full_name` je NOT NULL a sestavy nad uzavřeným rokem musí dál něco vypsat —
     * prázdný řetězec by v mzdovém listu vypadal jako poškozený záznam.
     */
    public static function placeholderName(int $employeeId): string
    {
        return 'Anonymizováno #' . $employeeId;
    }

    /**
     * @return array<string,int> počty zrušené evidence pro audit a potvrzení
     */
    public function anonymize(
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
            $pdo->exec('SAVEPOINT payroll_person_anonymize');
        }

        try {
            $result = $this->anonymizeLocked($supplierId, $employeeId, $userId, $ip, $userAgent);
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_person_anonymize');
            }

            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_person_anonymize');
                $pdo->exec('RELEASE SAVEPOINT payroll_person_anonymize');
            }
            throw $e;
        }
    }

    /** @return array<string,int> */
    private function anonymizeLocked(
        int $supplierId,
        int $employeeId,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $pdo = $this->db->pdo();
        $lock = $pdo->prepare(
            'SELECT id, employment_type, taxpayer_type
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $lock->execute([$supplierId, $employeeId]);
        $employee = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($employee)) {
            throw new PayrollEmploymentNotFoundException('Zaměstnanec nebyl nalezen.');
        }

        $removed = [];
        foreach (self::IDENTITY_TABLES as $alias => $tables) {
            $count = 0;
            foreach ($tables as $table) {
                $stmt = $pdo->prepare(
                    "DELETE FROM {$table} WHERE supplier_id = ? AND employee_id = ?"
                );
                $stmt->execute([$supplierId, $employeeId]);
                $count += $stmt->rowCount();
            }
            if ($count > 0) {
                $removed[$alias] = $count;
            }
        }

        // Osoba zůstává, mizí jen to, čím se dá identifikovat. Řádek se NESMÍ smazat:
        // kaskáda by s ním vzala i zaúčtované mzdy.
        $update = $pdo->prepare(
            'UPDATE payroll_employees
                SET full_name = ?, birth_number = NULL, birth_date = NULL,
                    address = NULL, is_active = 0
              WHERE supplier_id = ? AND id = ?'
        );
        $update->execute([self::placeholderName($employeeId), $supplierId, $employeeId]);
        $removed['person'] = $update->rowCount();

        $residue = $this->residue($supplierId, $employeeId);

        // Fakt, že osoba existovala a byla odosobněna, zůstat MUSÍ — payload proto
        // nese jen počty a neosobní zařazení, žádné jméno ani rodné číslo.
        $this->activityLogger->log(
            'payroll.employee.anonymized',
            $userId,
            'payroll_employee',
            $employeeId,
            [
                'employment_type' => (string) $employee['employment_type'],
                'taxpayer_type' => (string) $employee['taxpayer_type'],
                'removed' => $removed,
                'residue' => $residue,
            ],
            $ip,
            $userAgent,
            $supplierId,
        );

        return $removed;
    }
}
