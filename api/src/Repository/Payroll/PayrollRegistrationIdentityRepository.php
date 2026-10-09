<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollRegistrationIdentityRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function identityAt(
        int $supplierId,
        int $employeeId,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, first_name, last_name,
                    title_prefix, title_suffix, birth_surname, birth_date,
                    birth_place, birth_country_code,
                    citizenship_country_code, sex, effective_from,
                    effective_to, row_version
               FROM payroll_person_identity_history
              WHERE supplier_id = ?
                AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
               ORDER BY effective_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historická identita osoby se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->row($rows[0]);
    }

    /**
     * Dřívější příjmení osoby k danému dni (ID 10064): příjmení z dřívějších
     * záznamů historie jména, bez aktuálního a bez rodného příjmení, od
     * nejnovějšího, oddělená čárkou. `null`, když žádné nejsou. Rodné
     * příjmení nese vlastní atribut `birth/@nam` (ID 10063), a proto ho EDV
     * 1.4.0.6 („všechna další předchozí příjmení vyjma rodného") i Zásady
     * REGZEC 1.4.6 („bez aktuálního příjmení a rodného") z `ona` vylučují.
     * Rodné příjmení se v čase nemění, vylučuje se tedy podle kteréhokoli
     * záznamu historie, i když ho evidence doplnila až později.
     *
     * Atribut má v REGZEC25 nejvýš 100 znaků; vejdou se jen celá příjmení,
     * nejstarší případně vypadnou (nikdy se příjmení neuřízne).
     */
    public function previousSurnames(
        int $supplierId,
        int $employeeId,
        string $onDate,
        string $currentSurname,
        bool $forUpdate = false,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT last_name, birth_surname, effective_from
               FROM payroll_person_identity_history
              WHERE supplier_id = ?
                AND employee_id = ?
              ORDER BY effective_from DESC, id DESC'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $excluded = [mb_strtolower(trim($currentSurname))];
        foreach ($rows as $row) {
            $birth = trim((string) ($row['birth_surname'] ?? ''));
            if ($birth !== '') {
                $excluded[] = mb_strtolower($birth);
            }
        }
        $names = [];
        $length = 0;
        foreach ($rows as $row) {
            if ((string) $row['effective_from'] > $onDate) {
                continue;
            }
            $name = trim((string) ($row['last_name'] ?? ''));
            $key = mb_strtolower($name);
            if ($name === '' || in_array($key, $excluded, true)) {
                continue;
            }
            $excluded[] = $key;
            $added = mb_strlen($name) + ($names === [] ? 0 : 2);
            if ($length + $added > 100) {
                break;
            }
            $names[] = $name;
            $length += $added;
        }

        return $names === [] ? null : implode(', ', $names);
    }

    /**
     * @return list<array{
     *   id:int,identifier_type:string,value_ciphertext:string,
     *   value_hash:string,value_masked:string,row_version:int
     * }>
     */
    public function identifiers(
        int $supplierId,
        int $employeeId,
        bool $forUpdate = false,
    ): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, identifier_type, value_ciphertext, value_hash,
                    value_masked, row_version
               FROM payroll_person_identifiers
              WHERE supplier_id = ? AND employee_id = ?
              ORDER BY identifier_type, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->row($raw);
            $result[] = [
                'id' => $this->positiveInt($row, 'id'),
                'identifier_type' => $this->string($row, 'identifier_type'),
                'value_ciphertext' => $this->string($row, 'value_ciphertext'),
                'value_hash' => $this->string($row, 'value_hash'),
                'value_masked' => $this->string($row, 'value_masked'),
                'row_version' => $this->positiveInt($row, 'row_version'),
            ];
        }

        return $result;
    }

    /**
     * @param array{
     *   title_prefix:?string,title_suffix:?string,birth_date:?string,
     *   birth_place:?string,birth_country_code:?string,
     *   citizenship_country_code:?string,sex:?string
     * } $facts
     */
    public function updateIdentityFacts(
        int $supplierId,
        int $employeeId,
        int $identityId,
        int $expectedRowVersion,
        array $facts,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_person_identity_history
                SET title_prefix = ?,
                    title_suffix = ?,
                    birth_date = ?,
                    birth_place = ?,
                    birth_country_code = ?,
                    citizenship_country_code = ?,
                    sex = ?,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND employee_id = ?
                AND id = ?
                AND row_version = ?'
        );
        $statement->execute([
            $facts['title_prefix'],
            $facts['title_suffix'],
            $facts['birth_date'],
            $facts['birth_place'],
            $facts['birth_country_code'],
            $facts['citizenship_country_code'],
            $facts['sex'],
            $supplierId,
            $employeeId,
            $identityId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Historická identita neexistuje, patří jiné firmě nebo se změnila.',
            );
        }

        return $expectedRowVersion + 1;
    }

    /**
     * @return array{
     *   employee_id:int,start_date:?string,actual_start_date:?string,end_date:?string
     * }|null
     */
    public function employment(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, start_date, actual_start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'start_date' => $this->nullableString($row, 'start_date'),
            'actual_start_date' => $this->nullableString($row, 'actual_start_date'),
            'end_date' => $this->nullableString($row, 'end_date'),
        ];
    }

    /**
     * @return array{
     *   employee_id:int,start_date:?string,actual_start_date:?string,end_date:?string
     * }|null
     */
    public function lockEmployment(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT employee_id, start_date, actual_start_date, end_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'start_date' => $this->nullableString($row, 'start_date'),
            'actual_start_date' => $this->nullableString($row, 'actual_start_date'),
            'end_date' => $this->nullableString($row, 'end_date'),
        ];
    }

    public function lockEmployee(int $supplierId, int $employeeId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $employeeId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Jiné pracovní vztahy téže osoby u téže firmy se stejným druhem činnosti
     * a stejným příznakem zaměstnání malého rozsahu, které se časově
     * překrývají s tímto. ČSSZ takovou přihlášku odmítá (chyby 603 a 604);
     * navazující vztahy se od 1. 4. 2026 hlásí každý zvlášť.
     *
     * @return list<array{employment_id:int,code:string,start_on:string,end_on:?string,activity_code:string}>
     */
    public function overlappingSameActivityEmployments(
        int $supplierId,
        int $employmentId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'WITH own AS (
                SELECT employment.id, employment.employee_id,
                       COALESCE(employment.actual_start_date, employment.start_date)
                           AS start_on,
                       employment.end_date,
                       employment.relation_type = "small_scale_employment"
                           AS small_scale,
                       (SELECT terms.activity_code
                          FROM payroll_employment_terms terms
                         WHERE terms.supplier_id = employment.supplier_id
                           AND terms.employment_id = employment.id
                         ORDER BY terms.effective_from DESC, terms.id DESC
                         LIMIT 1) AS activity_code
                  FROM payroll_employments employment
                 WHERE employment.supplier_id = ? AND employment.id = ?
            )
            SELECT other.id AS employment_id, other.code,
                   COALESCE(other.actual_start_date, other.start_date) AS start_on,
                   other.end_date AS end_on, own.activity_code
              FROM own
              JOIN payroll_employments other
                ON other.supplier_id = ?
               AND other.employee_id = own.employee_id
               AND other.id <> own.id
             WHERE own.activity_code IS NOT NULL
               AND own.start_on IS NOT NULL
               AND other.status <> "no_show"
               AND (other.relation_type = "small_scale_employment") = own.small_scale
               AND (SELECT terms.activity_code
                      FROM payroll_employment_terms terms
                     WHERE terms.supplier_id = other.supplier_id
                       AND terms.employment_id = other.id
                     ORDER BY terms.effective_from DESC, terms.id DESC
                     LIMIT 1) = own.activity_code
               AND COALESCE(other.actual_start_date, other.start_date)
                   <= COALESCE(own.end_date, "9999-12-31")
               AND (other.end_date IS NULL OR other.end_date >= own.start_on)
             ORDER BY start_on, other.id',
        );
        $statement->execute([$supplierId, $employmentId, $supplierId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'employment_id' => (int) $row['employment_id'],
                'code' => (string) $row['code'],
                'start_on' => (string) $row['start_on'],
                'end_on' => $row['end_on'] === null ? null : (string) $row['end_on'],
                'activity_code' => (string) $row['activity_code'],
            ];
        }

        return $rows;
    }

    /**
     * Je zaměstnavatel uznaný na chráněném trhu práce (REGZEL, ID 10211)?
     * Bez profilu REGZEL se bere „ne" — tak se hlásí naprostá většina firem.
     */
    public function protectedLaborMarket(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT protected_labor_market
               FROM payroll_regzel_employer_profiles
              WHERE supplier_id = ?',
        );
        $statement->execute([$supplierId]);
        $value = $statement->fetchColumn();

        return $value !== false && (int) $value === 1;
    }

    /** @return array<string,mixed>|null */
    public function latestA1Profile(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        bool $forUpdate = false,
        bool $verifiedOnly = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, employee_id, employment_id, effective_on,
                    status, profile_ciphertext, profile_hash, reference_hash,
                    row_version, created_at
               FROM payroll_registration_a1_profiles
              WHERE supplier_id = ?
                AND employee_id = ?
                AND employment_id = ?'
            . ($verifiedOnly ? " AND status = 'verified'" : '')
            . ' ORDER BY row_version DESC, id DESC
              LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId, $employeeId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'id' => $this->positiveInt($row, 'id'),
            'supplier_id' => $this->positiveInt($row, 'supplier_id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'effective_on' => $this->string($row, 'effective_on'),
            'status' => $this->string($row, 'status'),
            'profile_ciphertext' => $this->string($row, 'profile_ciphertext'),
            'profile_hash' => $this->string($row, 'profile_hash'),
            'reference_hash' => $this->string($row, 'reference_hash'),
            'row_version' => $this->positiveInt($row, 'row_version'),
            'created_at' => $this->string($row, 'created_at'),
        ];
    }

    /**
     * Ověřená verze profilu A1, ze které se zmrazilo podání připravené
     * v okamžiku `$preparedAt` — tedy poslední verze uložená nejpozději tehdy.
     *
     * @return array<string,mixed>|null
     */
    public function a1ProfileAsOf(
        int $supplierId,
        int $employmentId,
        string $preparedAt,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, employee_id, employment_id, effective_on,
                    status, profile_ciphertext, profile_hash, reference_hash,
                    row_version, created_at
               FROM payroll_registration_a1_profiles
              WHERE supplier_id = ?
                AND employment_id = ?
                AND status = \'verified\'
                AND created_at <= ?
              ORDER BY row_version DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employmentId, $preparedAt]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($raw) ? $raw : null;
    }

    public function insertA1Profile(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $effectiveOn,
        string $ciphertext,
        string $profileHash,
        string $referenceHash,
        int $rowVersion,
        ?int $createdBy,
        string $status = 'verified',
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_registration_a1_profiles
                (supplier_id, employee_id, employment_id, effective_on, status,
                 profile_ciphertext, profile_hash, reference_hash,
                 row_version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $effectiveOn,
            $status,
            $ciphertext,
            $profileHash,
            $referenceHash,
            $rowVersion,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Odešlo za tenhle vztah registrační podání?
     *
     * Tohle je JEDINÉ spolehlivé „bylo odesláno". `status = 'verified'` na
     * profilu znamená jen to, že prošel přísnou kontrolou — o odeslání
     * nevypovídá nic. Rozhoduje část podání za tenhle vztah a stav podání,
     * které opustilo koncept.
     *
     * Prostředí ani agenda se schválně nerozlišují: doklad zanechalo i
     * zkušební podání a stejně tak předběžné přihlášení PREZEC — z profilu
     * se v obou případech vyrobil zmrazený obsah, který se nesmí přepsat.
     */
    public function hasSubmittedRegistration(
        int $supplierId,
        int $employmentId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_submission_parts part
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
              WHERE part.supplier_id = ?
                AND part.subject_reference = ?
                AND part.agenda_code IN (\'REGZEC25\', \'PREZEC26\')
                AND submission.status IN (
                      \'submitted\', \'processing\', \'accepted\',
                      \'partially_accepted\'
                    )
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            "payroll_employment:{$employmentId}",
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Smazání pracovního řádku profilu.
     *
     * Používá se jen k NAHRAZENÍ rozpracovaného profilu (smaž starý, vlož
     * nový) — řádek se nikdy nemění na místě, aby šifrovaný obsah a jeho otisk
     * k sobě pořád patřily. Řádek vztahu, za který už odešlo podání, odmítne
     * databázový trigger z migrace 1716.
     */
    public function deleteA1Profile(int $supplierId, int $id): void
    {
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM payroll_registration_a1_profiles
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $id]);
    }

    /**
     * Kmenová data, ze kterých se skládá NÁVRH profilu REGZEC A1. Čte se jen
     * pro předvyplnění formuláře; profil se ukládá výhradně do
     * payroll_registration_a1_profiles, takže tady nesmí být žádný zápis.
     *
     * @return array{
     *   permanent_address:?array<string,mixed>,
     *   contact_address:?array<string,mixed>,
     *   tax_residence:?array<string,mixed>,
     *   health_coverage:?array<string,mixed>,
     *   terms:?array<string,mixed>,
     *   employment:?array<string,mixed>,
     *   work_permit:?array<string,mixed>
     * }
     */
    public function a1DraftSources(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $onDate,
    ): array {
        return [
            'permanent_address' => $this->a1DraftAddress(
                $supplierId,
                $employeeId,
                'residence',
                $onDate,
            ),
            'contact_address' => $this->a1DraftAddress(
                $supplierId,
                $employeeId,
                'mailing',
                $onDate,
            ),
            'tax_residence' => $this->a1DraftTaxResidence(
                $supplierId,
                $employeeId,
                $onDate,
            ),
            'health_coverage' => $this->a1DraftHealthCoverage(
                $supplierId,
                $employeeId,
                $onDate,
            ),
            'terms' => $this->a1DraftTerms(
                $supplierId,
                $employmentId,
                $onDate,
            ),
            'employment' => $this->a1DraftEmployment(
                $supplierId,
                $employmentId,
            ),
            'work_permit' => $this->a1DraftWorkPermit(
                $supplierId,
                $employeeId,
                $onDate,
            ),
        ];
    }

    /**
     * Adresa trvalého pobytu osoby (u cizince adresa místa pobytu) ke dni
     * `$onDate`; `null`, když ji karta osoby k tomu dni nemá.
     *
     * @return array{street_line:string,city:string,postal_code:string,country_code:string}|null
     */
    public function residenceAddressAt(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $address = $this->a1DraftAddress($supplierId, $employeeId, 'residence', $onDate);
        if ($address === null) {
            return null;
        }

        return [
            'street_line' => (string) $address['street_line'],
            'city' => (string) $address['city'],
            'postal_code' => (string) $address['postal_code'],
            'country_code' => (string) $address['country_code'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftAddress(
        int $supplierId,
        int $employeeId,
        string $addressType,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT street_line, city, postal_code, country_code
               FROM payroll_person_addresses
              WHERE supplier_id = ?
                AND employee_id = ?
                AND address_type = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $addressType,
            $onDate,
            $onDate,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'street_line' => $this->string($row, 'street_line'),
            'city' => $this->string($row, 'city'),
            'postal_code' => $this->string($row, 'postal_code'),
            'country_code' => $this->string($row, 'country_code'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftTaxResidence(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT residence, country_code
               FROM payroll_person_tax_residences
              WHERE supplier_id = ?
                AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'residence' => $this->string($row, 'residence'),
            'country_code' => $this->nullableString($row, 'country_code'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftHealthCoverage(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT jurisdiction, foreign_country_code,
                    insurer_status, insurer_code
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ?
                AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'jurisdiction' => $this->string($row, 'jurisdiction'),
            'foreign_country_code' => $this->nullableString(
                $row,
                'foreign_country_code',
            ),
            'insurer_status' => $this->string($row, 'insurer_status'),
            'insurer_code' => $this->nullableString($row, 'insurer_code'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftTerms(
        int $supplierId,
        int $employmentId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT activity_code, jmhz_relationship_detail_code,
                    planned_start_on, actual_start_on, work_place,
                    jmhz_workplace_municipality_code, cz_isco_code,
                    foreign_legislation_country_code, fixed_term_end_on,
                    monthly_gross_minor
               FROM payroll_employment_terms
              WHERE supplier_id = ?
                AND employment_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employmentId, $onDate, $onDate]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'activity_code' => $this->nullableString($row, 'activity_code'),
            'relationship_detail_code' => $this->nullableString(
                $row,
                'jmhz_relationship_detail_code',
            ),
            'planned_start_on' => $this->nullableString($row, 'planned_start_on'),
            'actual_start_on' => $this->nullableString($row, 'actual_start_on'),
            'work_place' => $this->nullableString($row, 'work_place'),
            'workplace_municipality_code' => $this->nullableString(
                $row,
                'jmhz_workplace_municipality_code',
            ),
            'cz_isco_code' => $this->nullableString($row, 'cz_isco_code'),
            'foreign_legislation_country_code' => $this->nullableString(
                $row,
                'foreign_legislation_country_code',
            ),
            'fixed_term_end_on' => $this->nullableString($row, 'fixed_term_end_on'),
            'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                ? null
                : (int) $row['monthly_gross_minor'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftEmployment(
        int $supplierId,
        int $employmentId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT relation_type, start_date, actual_start_date
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([$supplierId, $employmentId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'relation_type' => $this->string($row, 'relation_type'),
            'start_date' => $this->nullableString($row, 'start_date'),
            'actual_start_date' => $this->nullableString(
                $row,
                'actual_start_date',
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private function a1DraftWorkPermit(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT permit_label, issuing_country_code,
                    effective_from, valid_until
               FROM payroll_person_foreign_permits
              WHERE supplier_id = ?
                AND employee_id = ?
                AND permit_kind = \'work\'
                AND effective_from <= ?
                AND valid_until >= ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $statement->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'permit_label' => $this->string($row, 'permit_label'),
            'issuing_country_code' => $this->string(
                $row,
                'issuing_country_code',
            ),
            'effective_from' => $this->string($row, 'effective_from'),
            'valid_until' => $this->string($row, 'valid_until'),
        ];
    }

    public function hasTrustedReceipt(
        int $supplierId,
        string $environment,
        int $receiptId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_receipts
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND verification_status = "trusted"
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $receiptId]);

        return $statement->fetchColumn() !== false;
    }

    public function hasAcceptedRegistrationIdentifierReceipt(
        int $supplierId,
        string $environment,
        int $receiptId,
        int $employeeId,
        int $employmentId,
        string $identifierType,
        string $value,
        string $registrationRulesetId,
    ): bool {
        $outcomeColumn = match ($identifierType) {
            'ik_mpsv' => 'external_person_reference',
            'id_ppv' => 'external_employment_reference',
            default => throw new \InvalidArgumentException(
                'Druh registračního identifikátoru není podporovaný.',
            ),
        };
        $employmentScope = $identifierType === 'id_ppv'
            ? ' AND employment.id = ?'
            : '';
        $statement = $this->db->pdo()->prepare(
            'SELECT receipt.id
               FROM payroll_submission_receipts receipt
               JOIN payroll_submissions submission
                 ON submission.supplier_id = receipt.supplier_id
                AND submission.environment = receipt.environment
                AND submission.id = receipt.submission_id
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
               JOIN payroll_jmhz_protocol_form_outcomes outcome
                 ON outcome.supplier_id = receipt.supplier_id
                AND outcome.environment = receipt.environment
                AND outcome.submission_id = receipt.submission_id
                AND outcome.receipt_id = receipt.id
               JOIN payroll_submission_parts part
                 ON part.supplier_id = submission.supplier_id
                AND part.environment = submission.environment
                AND part.submission_id = submission.id
                AND (
                    outcome.part_id = part.id
                    OR (
                        outcome.part_id IS NULL
                        AND 1 = (
                            SELECT COUNT(*)
                              FROM payroll_submission_parts receipt_part
                             WHERE receipt_part.supplier_id = submission.supplier_id
                               AND receipt_part.environment = submission.environment
                               AND receipt_part.submission_id = submission.id
                        )
                    )
                )
               JOIN payroll_employments employment
                 ON employment.supplier_id = part.supplier_id
                AND part.source_entity_type = "payroll_employment"
                AND part.source_entity_reference =
                    CONCAT("payroll_employment_registration:", employment.id)
              WHERE receipt.supplier_id = ?
                AND receipt.environment = ?
                AND receipt.id = ?
                AND receipt.verification_status = "trusted"
                AND receipt.remote_status IN ("accepted", "partially_accepted")
                AND submission.status IN ("accepted", "partially_accepted")
                AND submission.submission_kind = "regular"
                AND deadline.ruleset_id = ?
                AND part.agenda_code IN ("REGZEC25", "PREZEC26")
                AND outcome.remote_status = "accepted"
                AND outcome.' . $outcomeColumn . ' = ?
                AND employment.employee_id = ?'
            . $employmentScope
            . ' LIMIT 1 FOR UPDATE'
        );
        $parameters = [
            $supplierId,
            $environment,
            $receiptId,
            $registrationRulesetId,
            $value,
            $employeeId,
        ];
        if ($identifierType === 'id_ppv') {
            $parameters[] = $employmentId;
        }
        $statement->execute($parameters);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Odkud ručně zapsaný identifikátor pochází (migrace 1918). `null` =
     * starší zápis bez známého původu — volající ho bere jako ruční.
     */
    public function externalIdentifierOrigin(
        int $supplierId,
        string $identifierType,
        int $externalId,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT source_origin FROM ' . $this->externalIdTable($identifierType)
            . ' WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $externalId]);
        $origin = $statement->fetchColumn();

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    /**
     * Zapíše původ ručně vedeného identifikátoru. Původ z protokolu ČSSZ nese
     * `source_kind = trusted_receipt`, sem patří jen ruční opis a importy.
     */
    public function setExternalIdentifierOrigin(
        int $supplierId,
        string $identifierType,
        int $externalId,
        string $origin,
    ): void {
        if (!in_array(
            $origin,
            ['manual', 'cssz_employee_export', 'registration_import', 'jmhz_import'],
            true,
        )) {
            throw new \InvalidArgumentException('Neznámý původ identifikátoru ČSSZ.');
        }
        $this->db->pdo()->prepare(
            'UPDATE ' . $this->externalIdTable($identifierType)
            . ' SET source_origin = ?
              WHERE supplier_id = ? AND id = ?
                AND source_kind = "verified_manual_import"',
        )->execute([$origin, $supplierId, $externalId]);
    }

    private function externalIdTable(string $identifierType): string
    {
        return match ($identifierType) {
            'ik_mpsv' => 'payroll_person_external_ids',
            'id_ppv' => 'payroll_employment_external_ids',
            default => throw new \InvalidArgumentException(
                'Druh registračního identifikátoru není podporovaný.',
            ),
        };
    }

    /**
     * Potvrdila ČSSZ identifikátor přijetím změny/dohlášení (REGZEC A3)?
     *
     * Zaměstnanec přihlášený dřív přes ONZ nemá protokol o přijetí REGZEC
     * přihlášky, jeho OIČ a ID PPV jsou zapsané ručně. Když ale ČSSZ přijme
     * A3, které je nese, jsou tím ověřené stejně jako z protokolu. Událost
     * drží v manifestu id a verzi identifikátoru, se kterým byla schválená.
     */
    public function hasAcceptedChangeConfirmingIdentifier(
        int $supplierId,
        string $environment,
        int $employmentId,
        string $identifierType,
        int $externalId,
        int $rowVersion,
    ): bool {
        [$idPath, $versionPath] = match ($identifierType) {
            'ik_mpsv' => ['$.person_external_id', '$.person_external_row_version'],
            'id_ppv' => ['$.employment_external_id', '$.employment_external_row_version'],
            default => throw new \InvalidArgumentException(
                'Druh registračního identifikátoru není podporovaný.',
            ),
        };
        $statement = $this->db->pdo()->prepare(
            'SELECT event.id
               FROM payroll_registration_event_snapshots event
               JOIN payroll_submission_parts part
                 ON part.supplier_id = event.supplier_id
                AND part.environment = event.environment
                AND part.source_entity_type = "payroll_registration_event"
                AND part.source_entity_reference =
                    CONCAT("payroll_registration_event:", event.id)
               JOIN payroll_submissions submission
                 ON submission.supplier_id = part.supplier_id
                AND submission.environment = part.environment
                AND submission.id = part.submission_id
               JOIN payroll_submission_receipts receipt
                 ON receipt.supplier_id = submission.supplier_id
                AND receipt.environment = submission.environment
                AND receipt.submission_id = submission.id
              WHERE event.supplier_id = ?
                AND event.environment = ?
                AND event.employment_id = ?
                AND event.action_code = 3
                AND submission.status = "accepted"
                AND receipt.verification_status = "trusted"
                AND receipt.remote_status = "accepted"
                AND CAST(JSON_VALUE(event.source_manifest_json, ?) AS UNSIGNED) = ?
                AND CAST(JSON_VALUE(event.source_manifest_json, ?) AS UNSIGNED) = ?
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $employmentId,
            $idPath,
            $externalId,
            $versionPath,
            $rowVersion,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function activeExternalId(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $identifierType,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_to IS NULL
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
            $identifierType,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false ? null : $this->externalId($this->row($raw));
    }

    /** @return array<string,mixed>|null */
    public function externalIdFromReceipt(
        int $supplierId,
        int $employmentId,
        string $environment,
        int $receiptId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND employment_id = ?
                AND environment = ? AND identifier_type = "id_ppv"
                AND source_receipt_id = ?
              LIMIT 1
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
            $receiptId,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false ? null : $this->externalId($this->row($raw));
    }

    /**
     * Pracovní vztah, kterému kdy patřilo dané ID PPV — bez ohledu na to,
     * jestli je záznam pořád platný.
     *
     * ── Proč se hledá i v uzavřených záznamech ──────────────────────────────
     * Slouží k tomu, aby se odpověď ČSSZ dala přiřadit k pracovnímu vztahu
     * podle toho, co jsme ve zmrazeném hlášení ODESLALI. Kdyby se hledalo jen
     * mezi platnými, rozpadlo by se to přesně v situaci, kvůli které tahle
     * cesta existuje: ČSSZ vrátí JINÉ číslo, než jsme poslali, evidence se
     * mezitím uzavřela převodem pod jiný variabilní symbol — a nález by se
     * neměl na čem založit.
     *
     * Jednoznačnost drží klíč `uq_payroll_employment_external_id_value`
     * (supplier_id, environment, identifier_type, value_hash): jedno číslo
     * v jedné firmě a jednom prostředí patří právě jednomu záznamu.
     *
     * @return array{id:int,employee_id:int,employment_id:int}|null
     */
    public function employmentByExternalIdValueHash(
        int $supplierId,
        string $environment,
        string $identifierType,
        string $valueHash,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id
               FROM payroll_employment_external_ids
              WHERE supplier_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND value_hash = ?
              LIMIT 1'
        );
        $statement->execute([
            $supplierId,
            $environment,
            $identifierType,
            $valueHash,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
        ];
    }

    /**
     * Zahodí ručně opsaný identifikátor, aby šel přepsat správnou hodnotou.
     *
     * Číslo od ČSSZ se opisuje z protokolu ručně, takže překlep je běžný.
     * Bez tohohle kroku se překlep nedal opravit vůbec: nová hodnota narazí
     * na „v evidenci je jiná hodnota", stará se nedá ani upravit, ani zrušit,
     * a chybné číslo se pak veze v každém měsíčním hlášení.
     *
     * Maže se JEN ruční zápis (`verified_manual_import`). Číslo převzaté
     * z protokolu ČSSZ je doklad o tom, co úřad přidělil, a zůstává.
     * Odeslaná podání mají vlastní zmrazené kopie, ty se tímhle nemění.
     *
     * @return bool zda se něco smazalo
     */
    public function discardManualPersonExternalId(
        int $supplierId,
        int $externalId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM payroll_person_external_ids
              WHERE supplier_id = ? AND id = ?
                AND source_kind = "verified_manual_import"'
        );
        $statement->execute([$supplierId, $externalId]);

        return $statement->rowCount() === 1;
    }

    /** @see discardManualPersonExternalId */
    public function discardManualExternalId(
        int $supplierId,
        int $externalId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'DELETE FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND id = ?
                AND source_kind = "verified_manual_import"'
        );
        $statement->execute([$supplierId, $externalId]);

        return $statement->rowCount() === 1;
    }

    /** Je na identifikátor navázaná rozpracovaná úloha ztotožnění? */
    public function externalIdHasResolutionTask(
        int $supplierId,
        int $externalId,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT 1 FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ? AND resolved_external_id_id = ?
              LIMIT 1'
        );
        $statement->execute([$supplierId, $externalId]);

        return $statement->fetchColumn() !== false;
    }

    public function closeExternalId(
        int $supplierId,
        int $externalId,
        int $expectedRowVersion,
        string $validTo,
        ?int $updatedBy,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_external_ids
                SET valid_to = ?, updated_by = ?, row_version = row_version + 1
              WHERE supplier_id = ? AND id = ? AND row_version = ?
                AND valid_to IS NULL'
        );
        $statement->execute([
            $validTo,
            $updatedBy,
            $supplierId,
            $externalId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Aktivní ID PPV se mezitím změnilo a nelze je bezpečně nahradit.',
            );
        }
    }

    /**
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }|null
     */
    public function activePersonExternalId(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, environment, identifier_type,
                    value_ciphertext, value_hash, value_masked,
                    valid_from, valid_to, source_kind, source_receipt_id,
                    source_reference_hash, row_version
               FROM payroll_person_external_ids
              WHERE supplier_id = ?
                AND employee_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_to IS NULL
              FOR UPDATE'
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
        ]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false
            ? null
            : $this->personExternalId($this->row($raw));
    }

    /**
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }|null
     */
    public function personExternalIdAt(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, environment, identifier_type,
                    value_ciphertext, value_hash, value_masked,
                    valid_from, valid_to, source_kind, source_receipt_id,
                    source_reference_hash, row_version
               FROM payroll_person_external_ids
              WHERE supplier_id = ?
                AND employee_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historie OIČ se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->personExternalId($this->row($rows[0]));
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function externalIdAt(
        int $supplierId,
        int $employmentId,
        string $environment,
        string $identifierType,
        string $onDate,
        bool $forUpdate = false,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND identifier_type = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC, id DESC
              LIMIT 2'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
            $identifierType,
            $onDate,
            $onDate,
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new \DomainException(
                'Historie ID PPV se k rozhodnému datu překrývá.',
            );
        }
        if ($rows === []) {
            return null;
        }

        return $this->externalId($this->row($rows[0]));
    }

    /** @return list<string> */
    public function activeResolutionTaskKinds(
        int $supplierId,
        int $employmentId,
        string $environment,
        bool $forUpdate = false,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT task_kind
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ?
                AND employment_id = ?
                AND environment = ?
                AND status IN ("open", "manual_review")
              ORDER BY task_kind, id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([
            $supplierId,
            $employmentId,
            $environment,
        ]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $row = $this->row($raw);
            $result[] = $this->string($row, 'task_kind');
        }

        return $result;
    }

    public function insertExternalIdPlaceholder(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $identifierType,
        string $validFrom,
        string $sourceKind,
        ?int $sourceReceiptId,
        string $sourceReferenceHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_employment_external_ids
                (supplier_id, employee_id, employment_id, environment,
                 identifier_type, value_ciphertext, value_hash,
                 value_masked, valid_from, source_kind, source_receipt_id,
                 source_reference_hash, created_by)
             VALUES (?, ?, ?, ?, ?, 'enc:v2:pending', ?, '', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $identifierType,
            random_bytes(32),
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceReferenceHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertPersonExternalIdPlaceholder(
        int $supplierId,
        int $employeeId,
        string $environment,
        string $identifierType,
        string $validFrom,
        string $sourceKind,
        ?int $sourceReceiptId,
        string $sourceReferenceHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            "INSERT INTO payroll_person_external_ids
                (supplier_id, employee_id, environment, identifier_type,
                 value_ciphertext, value_hash, value_masked, valid_from,
                 source_kind, source_receipt_id, source_reference_hash,
                 created_by)
             VALUES (?, ?, ?, ?, 'enc:v2:pending', ?, '••••', ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $supplierId,
            $employeeId,
            $environment,
            $identifierType,
            random_bytes(32),
            $validFrom,
            $sourceKind,
            $sourceReceiptId,
            $sourceReferenceHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function sealExternalId(
        int $supplierId,
        int $id,
        string $ciphertext,
        string $hash,
        string $masked,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_employment_external_ids
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([
            $ciphertext,
            $hash,
            $masked,
            $supplierId,
            $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Externí identifikátor vztahu nelze uložit.');
        }
    }

    public function sealPersonExternalId(
        int $supplierId,
        int $id,
        string $ciphertext,
        string $hash,
        string $masked,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_person_external_ids
                SET value_ciphertext = ?, value_hash = ?, value_masked = ?
              WHERE supplier_id = ? AND id = ?'
        );
        $statement->execute([
            $ciphertext,
            $hash,
            $masked,
            $supplierId,
            $id,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('Externí identifikátor osoby nelze uložit.');
        }
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }|null
     */
    public function externalIdById(
        int $supplierId,
        int $externalId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, environment,
                    identifier_type, value_ciphertext, value_hash,
                    value_masked, valid_from, valid_to, source_kind,
                    source_receipt_id, source_reference_hash, row_version
               FROM payroll_employment_external_ids
              WHERE supplier_id = ? AND environment = ? AND id = ?'
        );
        $statement->execute([$supplierId, $environment, $externalId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);

        return $raw === false ? null : $this->externalId($this->row($raw));
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,created:bool
     * }
     */
    public function openResolutionTask(
        int $supplierId,
        int $employeeId,
        int $employmentId,
        string $environment,
        string $taskKind,
        string $reasonCode,
        ?int $candidateCount,
        ?int $sourceReceiptId,
        ?int $assignedTo,
        ?int $createdBy,
    ): array {
        $existing = $this->db->pdo()->prepare(
            'SELECT id, status, row_version
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ?
                AND environment = ?
                AND employment_id = ?
                AND task_kind = ?
                AND status IN ("open","manual_review")
              FOR UPDATE'
        );
        $existing->execute([
            $supplierId,
            $environment,
            $employmentId,
            $taskKind,
        ]);
        $raw = $existing->fetch(PDO::FETCH_ASSOC);
        if ($raw !== false) {
            $row = $this->row($raw);
            return [
                'id' => $this->positiveInt($row, 'id'),
                'status' => $this->string($row, 'status'),
                'row_version' => $this->positiveInt($row, 'row_version'),
                'created' => false,
            ];
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO payroll_identity_resolution_tasks
                (supplier_id, employee_id, employment_id, environment,
                 task_kind, reason_code, candidate_count, source_receipt_id,
                 assigned_to, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $supplierId,
            $employeeId,
            $employmentId,
            $environment,
            $taskKind,
            $reasonCode,
            $candidateCount,
            $sourceReceiptId,
            $assignedTo,
            $createdBy,
        ]);

        return [
            'id' => (int) $this->db->pdo()->lastInsertId(),
            'status' => 'open',
            'row_version' => 1,
            'created' => true,
        ];
    }

    /**
     * @return array{
     *   id:int,employee_id:int,employment_id:int,task_kind:string,
     *   status:string,row_version:int
     * }|null
     */
    public function lockResolutionTask(
        int $supplierId,
        int $taskId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, employee_id, employment_id, task_kind, status,
                    row_version
               FROM payroll_identity_resolution_tasks
              WHERE supplier_id = ? AND environment = ? AND id = ?
              FOR UPDATE'
        );
        $statement->execute([$supplierId, $environment, $taskId]);
        $raw = $statement->fetch(PDO::FETCH_ASSOC);
        if ($raw === false) {
            return null;
        }
        $row = $this->row($raw);

        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'task_kind' => $this->string($row, 'task_kind'),
            'status' => $this->string($row, 'status'),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    public function resolveTask(
        int $supplierId,
        int $taskId,
        int $expectedRowVersion,
        ?int $externalId,
        string $evidenceHash,
        int $resolvedBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_identity_resolution_tasks
                SET status = "resolved",
                    resolved_external_id_id = ?,
                    resolution_evidence_hash = ?,
                    resolved_by = ?,
                    resolved_at = CURRENT_TIMESTAMP,
                    row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?
                AND status IN ("open","manual_review")'
        );
        $statement->execute([
            $externalId,
            $evidenceHash,
            $resolvedBy,
            $supplierId,
            $taskId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException(
                'Resolution task neexistuje, patří jiné firmě nebo se změnil.',
            );
        }

        return $expectedRowVersion + 1;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,employee_id:int,employment_id:int,environment:string,
     *   identifier_type:string,value_ciphertext:string,value_hash:string,
     *   value_masked:string,valid_from:string,valid_to:?string,
     *   source_kind:string,source_receipt_id:?int,
     *   source_reference_hash:string,row_version:int
     * }
     */
    private function externalId(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'employment_id' => $this->positiveInt($row, 'employment_id'),
            'environment' => $this->string($row, 'environment'),
            'identifier_type' => $this->string($row, 'identifier_type'),
            'value_ciphertext' => $this->string($row, 'value_ciphertext'),
            'value_hash' => $this->string($row, 'value_hash'),
            'value_masked' => $this->string($row, 'value_masked'),
            'valid_from' => $this->string($row, 'valid_from'),
            'valid_to' => $this->nullableString($row, 'valid_to'),
            'source_kind' => $this->string($row, 'source_kind'),
            'source_receipt_id' => $this->nullablePositiveInt(
                $row,
                'source_receipt_id',
            ),
            'source_reference_hash' => $this->string(
                $row,
                'source_reference_hash',
            ),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,employee_id:int,environment:string,identifier_type:string,
     *   value_ciphertext:string,value_hash:string,value_masked:string,
     *   valid_from:string,valid_to:?string,source_kind:string,
     *   source_receipt_id:?int,source_reference_hash:string,row_version:int
     * }
     */
    private function personExternalId(array $row): array
    {
        return [
            'id' => $this->positiveInt($row, 'id'),
            'employee_id' => $this->positiveInt($row, 'employee_id'),
            'environment' => $this->string($row, 'environment'),
            'identifier_type' => $this->string($row, 'identifier_type'),
            'value_ciphertext' => $this->string($row, 'value_ciphertext'),
            'value_hash' => $this->string($row, 'value_hash'),
            'value_masked' => $this->string($row, 'value_masked'),
            'valid_from' => $this->string($row, 'valid_from'),
            'valid_to' => $this->nullableString($row, 'valid_to'),
            'source_kind' => $this->string($row, 'source_kind'),
            'source_receipt_id' => $this->nullablePositiveInt(
                $row,
                'source_receipt_id',
            ),
            'source_reference_hash' => $this->string(
                $row,
                'source_reference_hash',
            ),
            'row_version' => $this->positiveInt($row, 'row_version'),
        ];
    }

    /** @return array<string,mixed> */
    private function row(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                'Databáze vrátila neplatný řádek registrační identity.',
            );
        }
        $row = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    'Databáze vrátila neplatný klíč registrační identity.',
                );
            }
            $row[$key] = $item;
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private function string(array $row, string $key): string
    {
        if (!isset($row[$key]) || !is_string($row[$key])) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            throw new \UnexpectedValueException(
                "Databáze nevrátila pole {$key}.",
            );
        }
        if ($row[$key] === null) {
            return null;
        }
        if (!is_string($row[$key])) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return $row[$key];
    }

    /** @param array<string,mixed> $row */
    private function positiveInt(array $row, string $key): int
    {
        $value = filter_var(
            $row[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($value === false) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatné pole {$key}.",
            );
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $row */
    private function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            throw new \UnexpectedValueException(
                "Databáze nevrátila pole {$key}.",
            );
        }
        if ($row[$key] === null) {
            return null;
        }

        return $this->positiveInt($row, $key);
    }
}
