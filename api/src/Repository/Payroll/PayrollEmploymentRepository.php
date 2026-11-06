<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Payroll\PayrollAccountingDefaults;
use MyInvoice\Service\Payroll\PayrollEmploymentAccountingClassifier;
use MyInvoice\Service\Payroll\PayrollEmploymentJmhzActivityFamily;
use MyInvoice\Service\Payroll\Deadline\PayrollChecklistDeadlinePolicy;
use MyInvoice\Service\Payroll\PayrollEmploymentLifecycle;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use PDO;

/**
 * @phpstan-import-type TermsInput from \MyInvoice\Service\Payroll\PayrollEmploymentValidator
 * @phpstan-import-type EmploymentCreateInput from \MyInvoice\Service\Payroll\PayrollEmploymentValidator
 */
final class PayrollEmploymentRepository
{
    /** @var array<string,list<string>> */
    private const CHECKLISTS = [
        'onboarding' => [
            'employment_contract',
            'health_insurance_registration',
            'social_jmhz_registration',
            'tax_declaration',
        ],
        'change' => [
            'contract_amendment',
            'health_insurance_change',
            'social_jmhz_change',
        ],
        'offboarding' => [
            'termination_document',
            'health_insurance_deregistration',
            'social_jmhz_deregistration',
            // Evidenční list a potvrzení o zdanitelných příjmech tu dřív
            // chyběly, takže checklist hlásil „hotovo" u vztahu, u kterého
            // se ELDP vůbec nepodal. `eldp_submission` se přitom NEZAKLÁDÁ
            // vždy — od roku 2026 ho sestavuje ČSSZ z měsíčního hlášení
            // a rozhoduje o tom PayrollChecklistDeadlinePolicy.
            'eldp_submission',
            'taxable_income_confirmation',
            'enforcement_insolvency_review',
            'later_income_review',
        ],
    ];

    /**
     * Doklady, jejichž existence položku odškrtne sama.
     *
     * Odškrtnutí se NEZAPISUJE — stavem dál zůstává to, co nastavil člověk.
     * Odvozuje se až při čtení jako `effective_status`, protože doklad může
     * vzniknout i zaniknout (storno revize, smazaný výkaz) a přepsaný stav
     * by pak tvrdil hotovo nad něčím, co už neexistuje.
     *
     * Výčet i dotaz drží {@see PayrollChecklistEvidenceSql}, sdílený s přehledem
     * termínů.
     *
     * @var array<string,string>
     */
    private const CHECKLIST_EVIDENCE = PayrollChecklistEvidenceSql::EVIDENCE_KINDS;

    /**
     * Povinnosti, které na daný druh vztahu nesedí a nemají se ani zakládat.
     * Checklist se dřív seedoval pro každý vztah stejně, takže „Příjem společníka"
     * dostal „Pracovní smlouvu / dohodu" — dokument, který u něj nevzniká.
     *
     * DPP ani DPČ tu záměrně NEJSOU. Účast na pojištění u nich není vlastností
     * druhu vztahu, ale prahovou agregací příjmu (`SocialParticipationResolver`),
     * takže automatika podle druhu by mlčky vynechala povinnost, která vzniknout
     * může. Tam se položka založí a uživatel ji podle skutečnosti označí
     * „Netýká se".
     *
     * @var array<string,list<string>>
     */
    /**
     * Úkoly, které nezakládá fáze vztahu, ale převzetí ze zdroje
     * ({@see ensureFollowUpItem()}).
     *
     * @var list<string>
     */
    public const FOLLOW_UP_ITEMS = ['takeover_deductions_review', 'takeover_sickness_review'];

    private const CHECKLIST_EXCEPTIONS = [
        'partner_dependent' => ['employment_contract'],
        'statutory_body' => ['employment_contract'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollEmploymentLifecycle $lifecycle,
        private readonly PayrollEmploymentAccountingClassifier $accounting,
        private readonly ActivityLogger $activityLogger,
        private readonly PayrollEmploymentDeletionRepository $deletion,
        private readonly PayrollEmployerSettingsRepository $employerSettings,
    ) {}

    /**
     * Účty firmy — v rámci jednoho požadavku se nemění, ale karta osoby se ptá
     * za každý vztah zvlášť.
     *
     * @var array<int,array<string,string>>
     */
    private array $accountsCache = [];

    /**
     * Předkontace na kartě musí ukazovat účty, na které se mzda SKUTEČNĚ
     * zaúčtuje, ne obecné defaulty.
     *
     * Klasifikátor umí konfigurované účty přijmout od začátku, jenže mu je nikdo
     * nepředával — karta pak firmě s vlastním rozvrhem tvrdila „523/366", zatímco
     * běh účtoval podle nastavení zaměstnavatele (u firem, které si účty
     * přenastavily, klidně 521.100/331.100). Rozdíl si nikdo nemohl ověřit jinak
     * než v deníku po zaúčtování.
     *
     * @return array<string,string>
     */
    private function configuredAccounts(int $supplierId): array
    {
        if (!array_key_exists($supplierId, $this->accountsCache)) {
            $accounts = $this->employerSettings->get($supplierId)['accounts'] ?? null;
            $this->accountsCache[$supplierId] = is_array($accounts)
                ? array_map(strval(...), $accounts)
                : PayrollAccountingDefaults::codes();
        }

        return $this->accountsCache[$supplierId];
    }

    /** @return list<array<string,mixed>> */
    public function listForEmployee(int $supplierId, int $employeeId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT employment.id, employment.employee_id, employment.office_id,
                    office.code AS office_code, office.name AS office_name,
                    employment.code, employment.relation_type,
                    employment.meal_entitlement_basis, employment.status,
                    employment.is_primary, employment.start_date,
                    employment.actual_start_date, employment.start_estimated,
                    employment.end_date,
                    employment.archived_at, employment.is_legacy_projection,
                    ' . PayrollEmploymentLifecycleSql::effectiveMonthlyGrossToday() . '
                      AS monthly_gross_minor,
                    employment.row_version
               FROM payroll_employments employment
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = employment.supplier_id
                AND office.id = employment.office_id
              WHERE employment.supplier_id = ? AND employment.employee_id = ?
              /* Živé vztahy napřed: u člověka se souběhy nebo s historií se jinak
                 nedá poznat, který vztah je ten stávající. Legacy projekce je
                 zastřešující obal ze starší agendy — mezi živými patří dozadu,
                 ne dopředu, jinak odsune skutečné vztahy pod sebe. */
              ORDER BY (employment.status IN (\'ended\', \'archived\', \'no_show\')) ASC,
                       employment.is_primary DESC,
                       employment.is_legacy_projection ASC,
                       employment.start_date ASC,
                       employment.id ASC'
        );
        $stmt->execute([$supplierId, $employeeId]);

        // Prohlášení k dani se vede u OSOBY, ne u vztahu — čte se proto jednou
        // za osobu, ne v cyklu přes vztahy. Karta vztahu ho jen ukazuje
        // a odkazuje na zákonnou evidenci, kde se nastavuje.
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        /*
         * Zdravotní pojišťovna je stejný případ jako prohlášení k dani: vede ji
         * zákonná evidence OSOBY, ale rozhoduje o odvodu z tohoto vztahu —
         * a účetní ji na kartě hledala. Zrcadlo, ne druhé zadávací místo.
         *
         * Obojí se čte k ROZHODNÉMU dni vztahu: u vztahu, který ještě nezačal,
         * ke dni nástupu. Pojišťovna zadaná s platností od nástupu jinak
         * svítila „Nezadáno" až do dne nástupu, přestože je v pořádku.
         * Čte se jednou za rozhodný den, ne za každý vztah.
         *
         * @var array<string,array{tax:?array<string,mixed>,insurer:?array<string,mixed>}> $statutoryByDate
         */
        $statutoryByDate = [];

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->row($fetched);
            $employmentId = (int) $row['id'];
            $relationType = (string) $row['relation_type'];
            $startDate = $row['start_date'] === null ? null : (string) $row['start_date'];
            $onDate = $startDate !== null && $startDate > $today ? $startDate : $today;
            $statutoryByDate[$onDate] ??= [
                'tax' => $this->taxDeclaration($supplierId, $employeeId, $onDate),
                'insurer' => $this->healthInsurer($supplierId, $employeeId, $onDate),
            ];
            $taxDeclaration = $statutoryByDate[$onDate]['tax'];
            $healthInsurer = $statutoryByDate[$onDate]['insurer'];
            // Rozhodnutí o mazání patří i do seznamu — jinak by frontend musel
            // nabízet akci naslepo a důvod blokace by se dozvěděl až po kliknutí.
            $deletion = $this->deletion->canDelete($supplierId, $employmentId);
            $result[] = [
                'id' => $employmentId,
                'employee_id' => (int) $row['employee_id'],
                'office_id' => $row['office_id'] === null ? null : (int) $row['office_id'],
                'office_code' => $row['office_code'] === null ? null : (string) $row['office_code'],
                'office_name' => $row['office_name'] === null ? null : (string) $row['office_name'],
                'code' => (string) $row['code'],
                'relation_type' => $relationType,
                'meal_entitlement_basis' => (string) $row['meal_entitlement_basis'],
                'status' => (string) $row['status'],
                'is_primary' => (bool) $row['is_primary'],
                'start_date' => $row['start_date'] === null ? null : (string) $row['start_date'],
                'actual_start_date' => $row['actual_start_date'] === null
                    ? null
                    : (string) $row['actual_start_date'],
                'start_estimated' => (bool) $row['start_estimated'],
                'end_date' => $row['end_date'] === null ? null : (string) $row['end_date'],
                'archived_at' => $row['archived_at'] === null ? null : (string) $row['archived_at'],
                'is_legacy_projection' => (bool) $row['is_legacy_projection'],
                'monthly_gross_minor' => $row['monthly_gross_minor'] === null
                    ? null
                    : (int) $row['monthly_gross_minor'],
                'row_version' => (int) $row['row_version'],
                'allowed_transitions' => $this->allowedTransitions(
                    $supplierId,
                    $employmentId,
                    (string) $row['status'],
                ),
                'can_delete' => $deletion !== null && $deletion->canDelete,
                'delete_blocker' => $deletion?->blockerPayload(),
                'delete_cascade' => $deletion === null ? [] : $deletion->cascade,
                'accounting' => ($this->accounting)(
                    $relationType,
                    $this->configuredAccounts($supplierId),
                ),
                'tax_declaration' => $taxDeclaration,
                'health_insurer' => $healthInsurer,
                'terms' => $this->terms($supplierId, $employmentId),
                'checklist' => $this->checklist($supplierId, $employmentId),
                'timeline' => $this->events($supplierId, $employmentId),
            ];
        }
        return $result;
    }

    /** @param EmploymentCreateInput $data
     *  @return array<string,mixed>
     */
    public function create(
        int $supplierId,
        int $employeeId,
        array $data,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employeeId,
            $data,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $this->lockEmployee($supplierId, $employeeId);
            $data['terms']['office_id'] = $this->resolveOffice(
                $supplierId,
                $data['terms']['office_id'],
            );
            $this->assertPrimaryAvailable($supplierId, $employeeId, $data['terms']['is_primary'], null);
            /*
             * Druh činnosti pro ČSSZ se u nového vztahu PŘEDVYPLNÍ podle druhu
             * a pořadí: první pracovní poměr „1", druhý souběžný u téhož
             * zaměstnavatele „2", DPČ „A", DPP „T"… Zůstával prázdný, takže se
             * na něj přišlo až u registrace nebo měsíčního hlášení. Zadaný kód
             * má vždy přednost.
             */
            if (($data['terms']['activity_code'] ?? null) === null
                && PayrollEmploymentJmhzActivityFamily::appliesTo((string) $data['relation_type'])
            ) {
                [$activityCode, $detailCode] = PayrollEmploymentJmhzActivityFamily::nextRelationDefaults(
                    (string) $data['relation_type'],
                    $this->concurrentActivityCodes(
                        $supplierId,
                        $employeeId,
                        (string) ($data['terms']['planned_start_on'] ?? $data['terms']['effective_from']),
                    ),
                );
                if ($activityCode !== null) {
                    $data['terms']['activity_code'] = $activityCode;
                    $data['terms']['jmhz_relationship_detail_code'] ??= $detailCode;
                }
            }
            $data['terms']['tax_declaration_signed'] = $this->taxDeclarationSigned(
                $supplierId,
                $employeeId,
                (string) $data['terms']['effective_from'],
            );
            $data['terms']['monthly_gross_minor'] = $data['monthly_gross_minor'];
            if ($data['code'] === '') {
                $data['code'] = $this->nextEmploymentCode($supplierId, $employeeId);
            }

            $stmt = $this->db->pdo()->prepare(
                "INSERT INTO payroll_employments
                    (supplier_id, employee_id, office_id, code, relation_type,
                     meal_entitlement_basis,
                     status, is_primary, start_date, actual_start_date, end_date,
                     monthly_gross_minor, is_legacy_projection, row_version)
                 VALUES (?, ?, ?, ?, ?, ?, 'planned', ?, ?, ?, ?, ?, 0, 1)"
            );
            $stmt->execute([
                $supplierId,
                $employeeId,
                $data['terms']['office_id'],
                $data['code'],
                $data['relation_type'],
                $data['meal_entitlement_basis'],
                (int) $data['terms']['is_primary'],
                $data['terms']['planned_start_on'],
                $data['terms']['actual_start_on'],
                $data['terms']['fixed_term_end_on'],
                $data['monthly_gross_minor'],
            ]);
            $employmentId = (int) $this->db->pdo()->lastInsertId();
            $this->insertTerms($supplierId, $employmentId, $data['terms'], $userId);
            $this->insertEvent(
                $supplierId,
                $employmentId,
                'created',
                null,
                'planned',
                $data['terms']['effective_from'],
                $data['terms']['change_reason'],
                ['relation_type' => ['from' => null, 'to' => $data['relation_type']]],
                $userId,
            );
            $this->ensureChecklist(
                $supplierId,
                $employmentId,
                'onboarding',
                $data['terms']['planned_start_on'],
                $data['relation_type'],
            );
            $this->activityLogger->log(
                'payroll.employment.created',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'employee_id' => $employeeId,
                    'relation_type' => $data['relation_type'],
                    'status' => 'planned',
                    'effective_from' => $data['terms']['effective_from'],
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, $employeeId, $employmentId);
        });
    }

    /**
     * Kód CZ-ISCO, který u vztahu právě platí. Čte ho validátor smluvních
     * podmínek, aby nezablokoval uložení kvůli historické hodnotě, na kterou
     * uživatel vůbec nesahá — viz PayrollEmploymentValidator::optionalCzIscoCode().
     */
    public function currentCzIscoCode(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT cz_isco_code
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function currentRelationType(int $supplierId, int $employmentId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT relation_type
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        return $value;
    }

    /**
     * Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP, které u vztahu právě
     * platí. Čte ho validátor smluvních podmínek, aby ho neshodila obrazovka,
     * která o poli neví — viz PayrollEmploymentValidator::otherWithholdingEligibility().
     */
    public function currentOtherWithholdingEligibility(
        int $supplierId,
        int $employmentId,
    ): ?string {
        $stmt = $this->db->pdo()->prepare(
            'SELECT other_withholding_eligibility
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Datum účinnosti verze podmínek, která u vztahu právě platí.
     *
     * Oprava platné verze žádnou účinnost NEZADÁVÁ — bere tu uloženou. Payload
     * podmínek ji ale vyžaduje, takže si ji akce vyzvedne odsud a doplní do
     * těla požadavku; jinak by klient musel posílat údaj, kterým stejně nesmí
     * nic změnit (a mohl by ho poslat špatně).
     */
    public function currentTermsEffectiveFrom(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT effective_from
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string,mixed>|null */
    public function currentTerms(int $supplierId, int $employmentId): ?array
    {
        return $this->terms($supplierId, $employmentId)[0] ?? null;
    }

    /**
     * OPRAVA platné verze podmínek — přepis na místě, bez nové verze.
     *
     * Why: verzování podmínek je právně správně („od 1. 9. platí jiný úvazek"),
     * ale jako JEDINÁ cesta k úpravě je to past. Kdo si přišel spravit překlep
     * v úvazku nebo doplnit účtárnu, kterou nikdo nevyplnil, dostal formulář
     * „Nová verze podmínek" s povinným datem účinnosti — a založil tím druhou
     * verzi, která tvrdí, že se podmínky k tomu datu změnily. Časová osa pak
     * lže a mzdový běh počítá dvě období tam, kde je jedno.
     *
     * Rozdíl proti {@see addTerms()}:
     *   - `effective_from` se nemění, DOKUD o to uživatel výslovně nepožádá
     *     (`$correctEffectiveFrom`) — viz níže,
     *   - řádek podmínek se přepíše, nevzniká nový,
     *   - událost je `terms_corrected`, ne `terms_changed`,
     *   - NEZAKLÁDÁ se sada povinností: oprava překlepu není změna podmínek,
     *     ze které by plynula nová oznamovací povinnost.
     *
     * ## Oprava data účinnosti
     *
     * Formulář nové verze nabízí jako výchozí účinnost DNEŠEK. Kdo přidal
     * verzi dřív, než si vzpomněl, že zvýšení platí od 1. 7., si tím zapsal
     * špatné datum a neuměl ho vrátit: oprava účinnost MLČKY přepisovala
     * uloženou hodnotou, nová verze musí začínat později a smazat verzi nejde.
     * S `$correctEffectiveFrom` se proto datum posunout dá — ale jen tam, kde
     * to nic nepřepisuje: za konec předchozí verze a mimo období, za které je
     * mzda zaúčtovaná nebo vyplacená (kontroluje se STARÝ i NOVÝ rozsah).
     * Předchozí verze se posunu přizpůsobí, aby řada zůstala souvislá.
     *
     * @param TermsInput $data
     * @return array<string,mixed>
     */
    public function correctTerms(
        int $supplierId,
        int $employmentId,
        array $data,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        bool $replaceMonthlyGross = false,
        ?int $monthlyGrossMinor = null,
        bool $correctEffectiveFrom = false,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $data,
            $expectedVersion,
            $userId,
            $ip,
            $userAgent,
            $replaceMonthlyGross,
            $monthlyGrossMinor,
            $correctEffectiveFrom,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $employeeId = (int) $employment['employee_id'];
            $status = (string) $employment['status'];
            if (in_array($status, ['ended', 'archived', 'no_show'], true)) {
                throw new \DomainException(
                    'U ukončeného, archivovaného nebo nenastoupeného vztahu nelze podmínky opravit.'
                );
            }
            $actualStart = $employment['actual_start_date'] === null
                ? null
                : (string) $employment['actual_start_date'];
            if ($actualStart !== null) {
                $data['actual_start_on'] = $actualStart;
            }
            $this->lockEmployee($supplierId, $employeeId);
            $data['office_id'] = $this->resolveOffice(
                $supplierId,
                $data['office_id'],
                $employment['office_id'] === null ? null : (int) $employment['office_id'],
            );
            $this->assertPrimaryAvailable($supplierId, $employeeId, $data['is_primary'], $employmentId);

            $previous = $this->latestTermsForUpdate($supplierId, $employmentId);
            if ($previous === null) {
                throw new \DomainException('Pracovní vztah nemá žádnou verzi podmínek k opravě.');
            }
            $data['monthly_gross_minor'] = $replaceMonthlyGross
                ? $monthlyGrossMinor
                : ($previous['monthly_gross_minor'] ?? $employment['monthly_gross_minor']);
            $storedFrom = (string) $previous['effective_from'];
            $storedTo = $previous['effective_to'] === null
                ? null
                : (string) $previous['effective_to'];
            // Účinnost je vlastnost opravované verze, ne údaj z požadavku —
            // dokud si uživatel výslovně neřekne, že opravuje právě ji.
            if (!$correctEffectiveFrom) {
                $data['effective_from'] = $storedFrom;
            }
            $movedFrom = (string) $data['effective_from'];
            $data['tax_declaration_signed'] = $this->taxDeclarationSigned(
                $supplierId,
                $employeeId,
                $movedFrom,
            );
            $this->assertTermsOpenFrom($supplierId, $employmentId, $storedFrom, $storedTo);
            $predecessor = null;
            if ($movedFrom !== $storedFrom) {
                // Nový rozsah musí být volný stejně jako ten starý; jinak by
                // se oprava data protáhla do už vyplaceného měsíce.
                $this->assertTermsOpenFrom($supplierId, $employmentId, $movedFrom, $storedTo);
                $predecessor = $this->previousTermsForUpdate(
                    $supplierId,
                    $employmentId,
                    (int) $previous['id'],
                );
                $this->assertEffectiveFromMovable(
                    $movedFrom,
                    $storedTo,
                    $predecessor,
                    $employment,
                );
            }

            $this->updateTerms($supplierId, (int) $previous['id'], $data);
            if ($movedFrom !== $storedFrom) {
                // `updateTerms()` účinnost záměrně nepíše — je to vlastnost
                // verze, ne položka formuláře. Posun je proto vlastní zápis.
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employment_terms
                        SET effective_from = ?
                      WHERE supplier_id = ? AND id = ?'
                )->execute([$movedFrom, $supplierId, (int) $previous['id']]);
            }
            if ($predecessor !== null) {
                // Řada podmínek musí zůstat souvislá — posunutá verze si bere
                // den, který dosud patřil té předchozí.
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employment_terms
                        SET effective_to = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ?'
                )->execute([
                    (new \DateTimeImmutable($movedFrom))->modify('-1 day')->format('Y-m-d'),
                    $supplierId,
                    (int) $predecessor['id'],
                ]);
            }
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET office_id = ?, is_primary = ?,
                        start_estimated = CASE WHEN start_date <=> ? AND actual_start_date <=> ?
                                               THEN start_estimated ELSE 0 END,
                        start_date = ?,
                        actual_start_date = ?, end_date = ?,
                        monthly_gross_minor =
                            CASE WHEN ? = 1 THEN ? ELSE monthly_gross_minor END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([
                $data['office_id'],
                (int) $data['is_primary'],
                $data['planned_start_on'],
                $data['actual_start_on'],
                $data['planned_start_on'],
                $data['actual_start_on'],
                $data['fixed_term_end_on'],
                (int) $replaceMonthlyGross,
                $monthlyGrossMinor,
                $supplierId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $diff = $this->diff($previous, $data);
            /*
             * Oprava, která nic nezměnila, na časovou osu nepatří — jinak by
             * se do historie zapisovalo každé otevření a zavření formuláře.
             */
            if ($diff !== []) {
                $this->insertEvent(
                    $supplierId,
                    $employmentId,
                    'terms_corrected',
                    null,
                    null,
                    $data['effective_from'],
                    $data['change_reason'],
                    $diff,
                    $userId,
                );
            }
            $this->activityLogger->log(
                'payroll.employment.terms_corrected',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'effective_from' => $data['effective_from'],
                    'changed_fields' => array_keys($diff),
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, $employeeId, $employmentId);
        });
    }

    /** @param TermsInput $data
     *  @return array<string,mixed>
     */
    public function addTerms(
        int $supplierId,
        int $employmentId,
        array $data,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        bool $replaceMonthlyGross = false,
        ?int $monthlyGrossMinor = null,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $data,
            $expectedVersion,
            $userId,
            $ip,
            $userAgent,
            $replaceMonthlyGross,
            $monthlyGrossMinor,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $employeeId = (int) $employment['employee_id'];
            $status = (string) $employment['status'];
            if (in_array($status, ['ended', 'archived', 'no_show'], true)) {
                throw new \DomainException(
                    'U ukončeného, archivovaného nebo nenastoupeného vztahu nelze přidat novou verzi podmínek.'
                );
            }
            $actualStart = $employment['actual_start_date'] === null
                ? null
                : (string) $employment['actual_start_date'];
            if ($actualStart !== null
                && $data['actual_start_on'] !== null
                && $data['actual_start_on'] !== $actualStart) {
                throw new \DomainException(
                    'Skutečné datum nástupu nelze změnit novou verzí smluvních podmínek.'
                );
            }
            if ($actualStart !== null) {
                $data['actual_start_on'] = $actualStart;
            }
            $this->lockEmployee($supplierId, $employeeId);
            // Obrazovky, které účtárnu nenabízejí, posílají null — nesmí tím
            // shodit tu, kterou vztah drží (viz {@see resolveOffice()}).
            $data['office_id'] = $this->resolveOffice(
                $supplierId,
                $data['office_id'],
                $employment['office_id'] === null
                    ? null
                    : (int) $employment['office_id'],
            );
            $this->assertPrimaryAvailable($supplierId, $employeeId, $data['is_primary'], $employmentId);
            $data['tax_declaration_signed'] = $this->taxDeclarationSigned(
                $supplierId,
                $employeeId,
                (string) $data['effective_from'],
            );

            $previous = $this->latestTermsForUpdate($supplierId, $employmentId);
            if ($previous !== null && $data['effective_from'] <= (string) $previous['effective_from']) {
                throw new \DomainException(
                    'Nová smluvní verze musí začínat později než dosud poslední verze.'
                );
            }
            // Nová verze platí od svého data napořád — nesmí začít v měsíci,
            // za který je mzda zaúčtovaná nebo vyplacená, ani před ním.
            $this->assertTermsOpenFrom($supplierId, $employmentId, (string) $data['effective_from'], null);
            $data['monthly_gross_minor'] = $replaceMonthlyGross
                ? $monthlyGrossMinor
                : ($previous['monthly_gross_minor'] ?? $employment['monthly_gross_minor']);
            if ($previous !== null) {
                $previousEnd = (new \DateTimeImmutable($data['effective_from']))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employment_terms
                        SET effective_to = ?, row_version = row_version + 1
                      WHERE supplier_id = ? AND id = ?'
                )->execute([$previousEnd, $supplierId, (int) $previous['id']]);
            }

            $this->insertTerms($supplierId, $employmentId, $data, $userId);
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET office_id = ?, is_primary = ?,
                        start_estimated = CASE WHEN start_date <=> ? AND actual_start_date <=> ?
                                               THEN start_estimated ELSE 0 END,
                        start_date = ?,
                        actual_start_date = ?, end_date = ?,
                        monthly_gross_minor =
                            CASE WHEN ? = 1 THEN ? ELSE monthly_gross_minor END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([
                $data['office_id'],
                (int) $data['is_primary'],
                $data['planned_start_on'],
                $data['actual_start_on'],
                $data['planned_start_on'],
                $data['actual_start_on'],
                $data['fixed_term_end_on'],
                (int) $replaceMonthlyGross,
                $monthlyGrossMinor,
                $supplierId,
                $employmentId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $diff = $this->diff($previous, $data);
            $this->insertEvent(
                $supplierId,
                $employmentId,
                'terms_changed',
                null,
                null,
                $data['effective_from'],
                $data['change_reason'],
                $diff,
                $userId,
            );
            $this->ensureChecklist(
                $supplierId,
                $employmentId,
                'change',
                $data['effective_from'],
                (string) $employment['relation_type'],
            );
            $this->activityLogger->log(
                'payroll.employment.terms_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'effective_from' => $data['effective_from'],
                    'changed_fields' => array_keys($diff),
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, $employeeId, $employmentId);
        });
    }

    /** @return array<string,mixed> */
    /**
     * Přejmenování označení pro import docházky. Nemění nic o vztahu samotném,
     * jen jeho párovací klíč — proto žádná událost na časové ose, ale záznam
     * do auditní stopy.
     *
     * @return array<string,mixed>
     */
    public function rename(
        int $supplierId,
        int $employmentId,
        string $code,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $code,
            $expectedVersion,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $previous = (string) $employment['code'];
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET code = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([$code, $supplierId, $employmentId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }
            $this->activityLogger->log(
                'payroll.employment.renamed',
                $userId,
                'payroll_employment',
                $employmentId,
                ['from' => $previous, 'to' => $code],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find(
                $supplierId,
                (int) $employment['employee_id'],
                $employmentId,
            );
        });
    }

    /** @return array<string,mixed> */
    public function setMealEntitlementBasis(
        int $supplierId,
        int $employmentId,
        string $basis,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $basis,
            $expectedVersion,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $owner = $this->db->pdo()->prepare(
                'SELECT employee_id
                   FROM payroll_employments
                  WHERE supplier_id = ? AND id = ?'
            );
            $owner->execute([$supplierId, $employmentId]);
            $employeeId = $owner->fetchColumn();
            if ($employeeId === false) {
                throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
            }
            $this->lockEmployee($supplierId, (int) $employeeId);
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $previous = (string) $employment['meal_entitlement_basis'];
            if ($previous === $basis) {
                return $this->find(
                    $supplierId,
                    (int) $employment['employee_id'],
                    $employmentId,
                );
            }
            $activeMealInput = $this->db->pdo()->prepare(
                'SELECT 1
                   FROM payroll_inputs input
                   JOIN payroll_benefit_accumulators accumulator
                     ON accumulator.supplier_id = input.supplier_id
                    AND accumulator.input_id = input.id
                  WHERE input.supplier_id = ?
                    AND input.employee_id = ?
                    AND input.status IN ("approved", "locked")
                    AND input.benefit_basket = "meal_per_shift"
                    AND accumulator.status = "active"
                  LIMIT 1'
            );
            $activeMealInput->execute([$supplierId, (int) $employeeId]);
            if ($activeMealInput->fetchColumn() !== false) {
                throw new PayrollMealEntitlementBasisLockedException();
            }
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET meal_entitlement_basis = ?, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([$basis, $supplierId, $employmentId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }
            $this->activityLogger->log(
                'payroll.employment.meal_entitlement_basis_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                ['from' => $previous, 'to' => $basis],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, (int) $employment['employee_id'], $employmentId);
        });
    }

    public function transition(
        int $supplierId,
        int $employmentId,
        string $target,
        int $expectedVersion,
        string $effectiveOn,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $target,
            $expectedVersion,
            $effectiveOn,
            $note,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $from = (string) $employment['status'];
            $this->lifecycle->assertTransition($from, $target);
            $this->assertTransitionDate($employment, $target, $effectiveOn);

            // Návrat z omylem zapsaného ukončení. Datum konce musí zmizet —
            // jinak by vztah byl „aktivní do včerejška", což nedává smysl ani
            // mzdovému běhu, ani hlášení.
            $reopening = $from === 'ended' && $target === 'active';
            if ($reopening) {
                $this->assertReopenable($supplierId, $employmentId);
            }

            $assignments = ['status = ?', 'row_version = row_version + 1'];
            $values = [$target];
            if ($target === 'active' && $employment['actual_start_date'] === null) {
                $assignments[] = 'actual_start_date = ?';
                $values[] = $effectiveOn;
            }
            if ($reopening) {
                $assignments[] = 'end_date = NULL';
            }
            // Návrat z archivu je ÚKLID, ne nové ukončení: datum konce už platí
            // a přepsat ho dnešním dnem by z opravy omylu udělalo změnu historie.
            $unarchiving = $from === 'archived';
            if (in_array($target, ['ended', 'no_show'], true) && !$unarchiving) {
                $assignments[] = 'end_date = ?';
                $values[] = $effectiveOn;
                $assignments[] = 'is_primary = 0';
            }
            if ($unarchiving) {
                $assignments[] = 'archived_at = NULL';
            }
            if ($target === 'archived') {
                $assignments[] = 'archived_at = CURRENT_TIMESTAMP';
                $assignments[] = 'is_primary = 0';
            }
            $values[] = $supplierId;
            $values[] = $employmentId;
            $values[] = $expectedVersion;
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments SET ' . implode(', ', $assignments)
                . ' WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute($values);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }

            $this->insertEvent(
                $supplierId,
                $employmentId,
                'status_changed',
                $from,
                $target,
                $effectiveOn,
                $note,
                ['status' => ['from' => $from, 'to' => $target]],
                $userId,
            );
            $this->alignCreatedEvent($supplierId, $employmentId, $effectiveOn);
            if ($target === 'ended') {
                $this->ensureChecklist(
                    $supplierId,
                    $employmentId,
                    'offboarding',
                    $effectiveOn,
                    (string) $employment['relation_type'],
                );
            }
            $this->activityLogger->log(
                'payroll.employment.status_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                [
                    'from_status' => $from,
                    'to_status' => $target,
                    'effective_on' => $effectiveOn,
                ],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find(
                $supplierId,
                (int) $employment['employee_id'],
                $employmentId,
            );
        });
    }

    /**
     * Posune nástup už nastoupeného vztahu na DŘÍVĚJŠÍ den, který doložila podání
     * (typicky řada přijatých měsíčních hlášení nahraná až po založení vztahu).
     *
     * Nástup nese víc míst najednou a všechna se musí posunout spolu, jinak by
     * evidence ke dni nového nástupu neexistovala: sloupce vztahu, první verze
     * podmínek, události vzniku a aktivace (z nich se čte stav vztahu v čase),
     * identifikátory ČSSZ vztahu a nejstarší záznamy osobní evidence, které začínaly
     * právě starým nástupem (identita, adresy, rezidence, příslušnost, pojišťovna,
     * OIČ). Záznam osoby, který začínal jindy, patří jinému vztahu a zůstává.
     *
     * Jen dřív, nikdy později: pozdější nástup by uřízl dobu, za kterou už vztah
     * mohl mít zapsané údaje. A jen tehdy, když v posunutém rozsahu žádná mzda
     * není zaúčtovaná ani vyplacená.
     *
     * @return array{from:string,to:string}
     */
    public function correctStartEarlier(
        int $supplierId,
        int $employmentId,
        string $newStart,
        ?int $expectedVersion,
        string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $newStart,
            $expectedVersion,
            $note,
            $userId,
            $ip,
            $userAgent,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $status = (string) $employment['status'];
            if (!in_array($status, ['active', 'suspended', 'ended'], true)) {
                throw new \DomainException('Nástup jde opravit jen u vztahu, který už začal.');
            }
            $current = (string) ($employment['actual_start_date'] ?? $employment['start_date'] ?? '');
            if (\DateTimeImmutable::createFromFormat('!Y-m-d', $newStart)?->format('Y-m-d') !== $newStart) {
                throw new \InvalidArgumentException('Nový nástup musí být datum.');
            }
            if ($current === '' || $newStart >= $current) {
                throw new \DomainException('Nástup jde posunout jen na dřívější den, než eviduje vztah.');
            }
            $this->assertTermsOpenFrom($supplierId, $employmentId, $newStart, $current);
            $employeeId = (int) $employment['employee_id'];
            $pdo = $this->db->pdo();

            $first = $pdo->prepare(
                'SELECT id, effective_from, planned_start_on, actual_start_on
                   FROM payroll_employment_terms
                  WHERE supplier_id = ? AND employment_id = ?
                  ORDER BY effective_from, id
                  LIMIT 1
                  FOR UPDATE'
            );
            $first->execute([$supplierId, $employmentId]);
            $terms = $first->fetch(PDO::FETCH_ASSOC);
            if ($terms === false || (string) $terms['effective_from'] > $current) {
                throw new \DomainException('Pracovní vztah nemá verzi podmínek platnou od nástupu.');
            }
            $pdo->prepare(
                'UPDATE payroll_employment_terms
                    SET effective_from = ?,
                        planned_start_on = CASE WHEN planned_start_on IS NULL OR planned_start_on > ? THEN ? ELSE planned_start_on END,
                        actual_start_on = CASE WHEN actual_start_on IS NULL THEN NULL WHEN actual_start_on > ? THEN ? ELSE actual_start_on END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ?'
            )->execute([$newStart, $newStart, $newStart, $newStart, $newStart, $supplierId, (int) $terms['id']]);

            $update = $pdo->prepare(
                'UPDATE payroll_employments
                    SET start_date = CASE WHEN start_date IS NULL OR start_date > ? THEN ? ELSE start_date END,
                        actual_start_date = CASE WHEN actual_start_date IS NULL THEN NULL WHEN actual_start_date > ? THEN ? ELSE actual_start_date END,
                        start_estimated = 0,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([$newStart, $newStart, $newStart, $newStart, $supplierId, $employmentId, (int) $employment['row_version']]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException((int) $employment['row_version']);
            }

            // Stav vztahu v čase se čte z událostí: aktivace ke starému nástupu
            // by nechala dobu mezi novým a starým nástupem „plánovanou".
            $pdo->prepare(
                "UPDATE payroll_employment_events
                    SET effective_on = ?
                  WHERE supplier_id = ? AND employment_id = ?
                    AND event_type = 'status_changed' AND to_status = 'active' AND effective_on = ?"
            )->execute([$newStart, $supplierId, $employmentId, $current]);
            $this->alignCreatedEvent($supplierId, $employmentId, $newStart);

            $this->moveEarliestRows($supplierId, 'payroll_employment_external_ids', 'valid_from',
                ['employment_id' => $employmentId], ['environment', 'identifier_type'], $current, $newStart);
            $this->moveEarliestRows($supplierId, 'payroll_person_external_ids', 'valid_from',
                ['employee_id' => $employeeId], ['environment', 'identifier_type'], $current, $newStart);
            $this->moveEarliestRows($supplierId, 'payroll_person_identity_history', 'effective_from',
                ['employee_id' => $employeeId], [], $current, $newStart);
            $this->moveEarliestRows($supplierId, 'payroll_person_addresses', 'effective_from',
                ['employee_id' => $employeeId], ['address_type'], $current, $newStart);
            foreach (['payroll_person_tax_residences', 'payroll_person_social_jurisdictions', 'payroll_person_health_coverage_history'] as $table) {
                $this->moveEarliestRows($supplierId, $table, 'effective_from', ['employee_id' => $employeeId], [], $current, $newStart);
            }

            $diff = ['start_date' => ['from' => $current, 'to' => $newStart]];
            $this->insertEvent($supplierId, $employmentId, 'terms_corrected', null, null, $newStart, $note, $diff, $userId);
            $this->activityLogger->log(
                'payroll.employment.start_corrected',
                $userId,
                'payroll_employment',
                $employmentId,
                ['from' => $current, 'to' => $newStart],
                $ip,
                $userAgent,
                $supplierId,
            );

            return ['from' => $current, 'to' => $newStart];
        });
    }

    /**
     * Nejstarší záznam každé skupiny (`$partition`), který začínal přesně starým
     * nástupem, začne novým. Starší záznam skupiny znamená, že údaj platil už před
     * vztahem (jiný vztah osoby) — pak se skupina nemění.
     *
     * @param array<string,int> $owner sloupec vlastníka => id
     * @param list<string> $partition
     */
    private function moveEarliestRows(
        int $supplierId,
        string $table,
        string $dateColumn,
        array $owner,
        array $partition,
        string $from,
        string $to,
    ): void {
        $ownerColumn = (string) array_key_first($owner);
        $columns = implode(', ', ['id', $dateColumn, ...$partition]);
        $stmt = $this->db->pdo()->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE supplier_id = ? AND {$ownerColumn} = ?
              ORDER BY {$dateColumn}, id
              FOR UPDATE"
        );
        $stmt->execute([$supplierId, $owner[$ownerColumn]]);
        $seen = [];
        $update = $this->db->pdo()->prepare(
            "UPDATE {$table} SET {$dateColumn} = ?, row_version = row_version + 1 WHERE supplier_id = ? AND id = ?"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $group = implode('|', array_map(static fn (string $column): string => (string) $row[$column], $partition));
            if (isset($seen[$group])) {
                continue;
            }
            $seen[$group] = true;
            if ((string) $row[$dateColumn] === $from) {
                $update->execute([$to, $supplierId, (int) $row['id']]);
            }
        }
    }

    /** @return array<string,mixed> */
    public function updateChecklist(
        int $supplierId,
        int $employmentId,
        string $itemKey,
        int $expectedVersion,
        string $status,
        ?string $note,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
        ?string $requestedOn = null,
    ): array {
        $deadline = null;
        if ($requestedOn !== null) {
            if ($itemKey !== PayrollChecklistDeadlinePolicy::TAXABLE_INCOME_CONFIRMATION_ITEM) {
                throw new \InvalidArgumentException(
                    'Den žádosti se zapisuje jen u potvrzení o zdanitelných příjmech.',
                );
            }
            $deadline = (new PayrollChecklistDeadlinePolicy())
                ->taxableIncomeConfirmationOnRequest($requestedOn);
        }

        return $this->transaction(function () use (
            $supplierId,
            $employmentId,
            $itemKey,
            $expectedVersion,
            $status,
            $note,
            $userId,
            $ip,
            $userAgent,
            $deadline,
        ): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, null);
            $item = $this->db->pdo()->prepare(
                'SELECT id, status, row_version
                   FROM payroll_employment_checklist_items
                  WHERE supplier_id = ? AND employment_id = ? AND item_key = ?
                  FOR UPDATE'
            );
            $item->execute([$supplierId, $employmentId, $itemKey]);
            $fetched = $item->fetch(PDO::FETCH_ASSOC);
            if ($fetched === false) {
                throw new PayrollEmploymentNotFoundException('Položka checklistu nebyla nalezena.');
            }
            $current = $this->row($fetched);
            if ((int) $current['row_version'] !== $expectedVersion) {
                throw new PayrollEmploymentConflictException((int) $current['row_version']);
            }
            $this->assertChecklistPrerequisite($itemKey, $status, $employment);
            $update = $this->db->pdo()->prepare(
                "UPDATE payroll_employment_checklist_items
                    SET status = ?, note = ?,
                        completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                        completed_by = CASE WHEN ? = 'completed' THEN ? ELSE NULL END,
                        row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?"
            );
            $update->execute([
                $status,
                $note,
                $status,
                $status,
                $userId,
                $supplierId,
                (int) $current['id'],
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }
            if ($deadline !== null) {
                // Lhůta vzniká až žádostí; zapisuje se do téhož řádku, ze
                // kterého ji čte hlídač termínů, takže se neobjeví dvakrát.
                $this->db->pdo()->prepare(
                    'UPDATE payroll_employment_checklist_items
                        SET due_date = ?, deadline_ruleset_id = ?, deadline_source = ?,
                            deadline_source_status = ?
                      WHERE supplier_id = ? AND id = ?'
                )->execute([
                    $deadline->dueOn,
                    $deadline->rulesetId,
                    $deadline->source,
                    $deadline->sourceStatus,
                    $supplierId,
                    (int) $current['id'],
                ]);
            }

            $this->insertEvent(
                $supplierId,
                $employmentId,
                'checklist_changed',
                null,
                null,
                (new \DateTimeImmutable('today'))->format('Y-m-d'),
                $note,
                [
                    $itemKey => [
                        'from' => (string) $current['status'],
                        'to' => $status,
                    ],
                ],
                $userId,
            );
            $this->activityLogger->log(
                'payroll.employment.checklist_changed',
                $userId,
                'payroll_employment',
                $employmentId,
                ['item_key' => $itemKey, 'status' => $status],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find(
                $supplierId,
                (int) $employment['employee_id'],
                $employmentId,
            );
        });
    }

    /**
     * Nevyřízené položky checklistu dané fáze založené od `$since` (čas
     * databáze, {@see databaseNow()}). Import podle nich pozná povinnosti,
     * které založil sám, a starší rozpracované nechá být.
     *
     * @return list<array{item_key:string,row_version:int}>
     */
    public function pendingChecklistItemsSince(int $supplierId, int $employmentId, string $phase, string $since): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT item_key, row_version
               FROM payroll_employment_checklist_items
              WHERE supplier_id = ? AND employment_id = ? AND phase = ?
                AND status = 'pending' AND created_at >= ?
              ORDER BY item_key"
        );
        $stmt->execute([$supplierId, $employmentId, $phase, $since]);

        return array_map(
            static fn (array $row): array => [
                'item_key' => (string) $row['item_key'],
                'row_version' => (int) $row['row_version'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function databaseNow(): string
    {
        return (string) $this->db->pdo()->query('SELECT NOW()')->fetchColumn();
    }

    /** @return array<string,mixed> */
    /**
     * Prohlášení k dani osoby platné k danému dni.
     *
     * Jediný zdroj pravdy je zákonná evidence osoby — zaškrtávátko na kartě
     * vztahu z ní hodnotu jen přebírá. Chybí-li záznam, bere se prohlášení za
     * NEPODEPSANÉ: bez něj se měsíční sleva podle § 38k odst. 4 ZDP uplatnit
     * nesmí a za nesraženou zálohu ručí plátce (§ 38s ZDP).
     */
    private function taxDeclarationSigned(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): bool {
        return ($this->taxDeclaration($supplierId, $employeeId, $onDate)['status'] ?? null)
            === 'signed';
    }

    /**
     * Druhy činnosti živých vztahů osoby, které se s novým vztahem překrývají
     * (nejsou ukončené, archivované ani nenastoupené a neskončily před jeho
     * nástupem). Bere se poslední verze podmínek každého vztahu.
     *
     * @return list<string>
     */
    private function concurrentActivityCodes(int $supplierId, int $employeeId, string $startOn): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT latest.activity_code
               FROM (
                 SELECT terms.activity_code,
                        ROW_NUMBER() OVER (
                          PARTITION BY terms.employment_id
                          ORDER BY terms.effective_from DESC, terms.id DESC
                        ) AS version_rank
                   FROM payroll_employment_terms terms
                   JOIN payroll_employments employment
                     ON employment.supplier_id = terms.supplier_id
                    AND employment.id = terms.employment_id
                  WHERE employment.supplier_id = ?
                    AND employment.employee_id = ?
                    AND employment.status NOT IN ('ended', 'archived', 'no_show')
                    AND (employment.end_date IS NULL OR employment.end_date >= ?)
               ) latest
              WHERE latest.version_rank = 1
                AND latest.activity_code IS NOT NULL"
        );
        $stmt->execute([$supplierId, $employeeId, $startOn]);

        return array_values(array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return array{status:string,effective_from:string,effective_to:?string}|null */
    private function taxDeclaration(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT status, effective_from, effective_to
               FROM payroll_person_tax_declarations
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'effective_from' => (string) $row['effective_from'],
            'effective_to' => $row['effective_to'] === null
                ? null
                : (string) $row['effective_to'],
        ];
    }

    /**
     * Zdravotní pojišťovna osoby platná k danému dni.
     *
     * Karta vztahu ji jen UKAZUJE a vede do zákonné evidence, kde se
     * nastavuje — stejný důvod jako u prohlášení k dani: druhé editovatelné
     * místo pro tentýž údaj se dřív nebo později rozejde s prvním.
     *
     * @return array{status:string,code:?string,effective_from:string}|null
     */
    private function healthInsurer(
        int $supplierId,
        int $employeeId,
        string $onDate,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT insurer_status, insurer_code, effective_from
               FROM payroll_person_health_coverage_history
              WHERE supplier_id = ? AND employee_id = ?
                AND effective_from <= ?
                AND (effective_to IS NULL OR effective_to >= ?)
              ORDER BY effective_from DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employeeId, $onDate, $onDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'status' => (string) $row['insurer_status'],
            'code' => $row['insurer_code'] === null ? null : (string) $row['insurer_code'],
            'effective_from' => (string) $row['effective_from'],
        ];
    }

    private function find(int $supplierId, int $employeeId, int $employmentId): array
    {
        foreach ($this->listForEmployee($supplierId, $employeeId) as $employment) {
            if ($employment['id'] === $employmentId) {
                return $employment;
            }
        }
        throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
    }

    /** @return list<array<string,mixed>> */
    private function terms(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT terms.id, terms.office_id, office.code AS office_code,
                    terms.effective_from, terms.effective_to,
                    terms.contract_signed_on, terms.planned_start_on,
                    terms.actual_start_on, terms.fixed_term_end_on,
                    terms.monthly_gross_minor,
                    terms.weekly_hours, terms.leave_entitlement_weeks_override,
                    terms.probable_hourly_earning_minor,
                    terms.probable_earning_rationale,
                    terms.workload_basis_points,
                    terms.work_place, terms.regular_workplace,
                    terms.jmhz_workplace_municipality_code,
                    terms.jmhz_workplace_country_code,
                    terms.jmhz_workplace_address_point_code,
                    terms.jmhz_workplace_street,
                    terms.jmhz_workplace_house_number,
                    terms.jmhz_workplace_orientation_number,
                    terms.jmhz_workplace_postal_code,
                    terms.jmhz_state_prosecutor,
                    terms.jmhz_external_codebook_overlay_key,
                    terms.jmhz_external_codebook_manifest_sha256,
                    terms.jmhz_apz_contribution_status,
                    terms.jmhz_apz_instrument_code,
                    terms.jmhz_functional_benefits_status,
                    terms.jmhz_temporary_assignment_status,
                    terms.jmhz_assignment_user_kind,
                    terms.jmhz_assignment_user_ico,
                    terms.jmhz_assignment_user_country_code,
                    terms.jmhz_assignment_user_foreign_id,
                    terms.jmhz_assignment_user_name,
                    terms.jmhz_orchard_discount_eligible,
                    terms.jmhz_specific_legal_fact_applies,
                    terms.jmhz_ozp_employment_support_applies,
                    terms.jmhz_deep_mining_work_applies,
                    terms.cz_isco_code, terms.activity_code,
                    terms.jmhz_relationship_detail_code,
                    terms.social_insurance_participation,
                    terms.health_insurance_participation,
                    terms.health_association_member, terms.tax_regime,
                    terms.other_withholding_eligibility,
                    terms.foreign_legislation_country_code,
                    terms.a1_certificate_until, terms.risky_work,
                    terms.social_employer_rate_category,
                    terms.social_employer_rate_category_evidence,
                    terms.jmhz_risk_categorization_code,
                    terms.social_part_time_discount_reason,
                    terms.social_part_time_discount_evidence,
                    terms.social_part_time_discount_notified_on,
                    terms.tax_declaration_signed, terms.is_primary,
                    terms.change_reason, terms.row_version, terms.created_at
               FROM payroll_employment_terms terms
               LEFT JOIN payroll_offices office
                 ON office.supplier_id = terms.supplier_id
                AND office.id = terms.office_id
              WHERE terms.supplier_id = ? AND terms.employment_id = ?
              ORDER BY terms.effective_from DESC, terms.id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = $this->cast($this->row($row));
        }
        return $result;
    }

    /**
     * Checklist vztahu i s DOKLADEM, který položku odškrtává sám.
     *
     * `effective_status` je to, co se má ukázat: uložený stav, dokud
     * o položce nikdo nerozhodl, jinak `completed`, existuje-li doklad nebo
     * vyřídil-li povinnost předchozí program ({@see PayrollPredecessorObligationScope}).
     * Uložený stav se nepřepisuje — kdyby se doklad ztratil (storno revize,
     * smazaný výkaz), tvrdil by checklist hotovo nad něčím, co neexistuje.
     *
     * @return list<array<string,mixed>>
     */
    private function checklist(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT item.id, item.phase, item.item_key, item.status,
                    item.due_date, item.deadline_ruleset_id,
                    item.deadline_source, item.deadline_source_status,
                    item.completed_at, item.note, item.row_version,
                    item.created_at, item.updated_at,
                    ' . PayrollChecklistEvidenceSql::evidencePresent() . ' AS evidence_present,
                    ' . PayrollPredecessorObligationScope::sql('item') . ' AS predecessor_handled
               FROM payroll_employment_checklist_items item
              WHERE item.supplier_id = ? AND item.employment_id = ?
              ORDER BY FIELD(item.phase, \'onboarding\', \'change\', \'offboarding\'),
                       item.due_date IS NULL, item.due_date ASC, item.item_key ASC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $item = $this->cast($this->row($row));
            $evidence = (bool) $item['evidence_present'];
            $item['evidence_present'] = $evidence;
            $item['evidence_kind'] = $evidence
                ? (self::CHECKLIST_EVIDENCE[$item['item_key']] ?? null)
                : null;
            $predecessor = (bool) $item['predecessor_handled'];
            $item['predecessor_handled'] = $predecessor;
            $item['effective_status'] = $item['status'] === 'pending' && ($evidence || $predecessor)
                ? 'completed'
                : $item['status'];
            $item['effective_reason'] = $item['status'] !== 'pending'
                ? null
                : ($evidence ? 'evidence' : ($predecessor ? PayrollPredecessorObligationScope::REASON : null));
            $result[] = $item;
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function events(int $supplierId, int $employmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, event_type, from_status, to_status, effective_on,
                    note, diff_json, created_at
               FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_on DESC, id DESC'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fetched) {
            $row = $this->row($fetched);
            $diff = $row['diff_json'] === null
                ? null
                : json_decode((string) $row['diff_json'], true, 512, JSON_THROW_ON_ERROR);
            $events[] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'from_status' => $row['from_status'] === null ? null : (string) $row['from_status'],
                'to_status' => $row['to_status'] === null ? null : (string) $row['to_status'],
                'effective_on' => (string) $row['effective_on'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
                'diff' => $diff,
                'created_at' => (string) $row['created_at'],
            ];
        }
        return $events;
    }

    /**
     * @param TermsInput $data
     *
     * `tax_declaration_signed` se NEBERE z těla požadavku, i když ho validátor
     * pořád přijímá (starší klienti ho posílají). Prohlášení k dani je právní
     * skutečnost vedená v čase v `payroll_person_tax_declarations`; druhé
     * editovatelné místo pro tentýž údaj znamenalo, že se obě hodnoty rozešly
     * a mzdový běh spadl na `tax_declaration_term_conflict`. Sloupec tak
     * zůstává odvozeným zrcadlem evidence k začátku účinnosti verze.
     */
    private function insertTerms(
        int $supplierId,
        int $employmentId,
        array $data,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_terms
                (supplier_id, employment_id, office_id, effective_from,
                 contract_signed_on, planned_start_on, actual_start_on,
                 fixed_term_end_on, monthly_gross_minor, weekly_hours, leave_entitlement_weeks_override,
                 probable_hourly_earning_minor, probable_earning_rationale,
                 workload_basis_points,
                 work_place, regular_workplace, cz_isco_code, activity_code,
                 jmhz_relationship_detail_code,
                 jmhz_workplace_municipality_code,
                 jmhz_workplace_country_code,
                 jmhz_external_codebook_overlay_key,
                 jmhz_external_codebook_manifest_sha256,
                 jmhz_apz_contribution_status, jmhz_apz_instrument_code,
                 jmhz_functional_benefits_status,
                 jmhz_temporary_assignment_status,
                 jmhz_orchard_discount_eligible,
                 jmhz_specific_legal_fact_applies,
                 jmhz_ozp_employment_support_applies,
                 jmhz_deep_mining_work_applies,
                 social_insurance_participation, health_insurance_participation,
                 tax_regime, other_withholding_eligibility,
                 foreign_legislation_country_code,
                 a1_certificate_until, risky_work,
                 social_employer_rate_category, social_employer_rate_category_evidence,
                 social_part_time_discount_reason, social_part_time_discount_evidence,
                 social_part_time_discount_notified_on,
                 tax_declaration_signed,
                 is_primary, change_reason, created_by,
                 jmhz_assignment_user_kind, jmhz_assignment_user_ico,
                 jmhz_assignment_user_country_code, jmhz_assignment_user_foreign_id,
                 jmhz_assignment_user_name, jmhz_risk_categorization_code,
                 health_association_member,
                 jmhz_workplace_address_point_code, jmhz_workplace_street,
                 jmhz_workplace_house_number, jmhz_workplace_orientation_number,
                 jmhz_workplace_postal_code, jmhz_state_prosecutor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employmentId,
            $data['office_id'],
            $data['effective_from'],
            $data['contract_signed_on'],
            $data['planned_start_on'],
            $data['actual_start_on'],
            $data['fixed_term_end_on'],
            $data['monthly_gross_minor'],
            $data['weekly_hours'],
            $data['leave_entitlement_weeks_override'],
            $data['probable_hourly_earning_minor'],
            $data['probable_earning_rationale'],
            $data['workload_basis_points'],
            $data['work_place'],
            $data['regular_workplace'],
            $data['cz_isco_code'],
            $data['activity_code'],
            $data['jmhz_relationship_detail_code'],
            $data['jmhz_workplace_municipality_code'],
            $data['jmhz_workplace_country_code'],
            $data['jmhz_external_codebook_overlay_key'],
            $data['jmhz_external_codebook_manifest_sha256'],
            $data['jmhz_apz_contribution_status'],
            $data['jmhz_apz_instrument_code'],
            $data['jmhz_functional_benefits_status'],
            $data['jmhz_temporary_assignment_status'],
            (int) $data['jmhz_orchard_discount_eligible'],
            (int) $data['jmhz_specific_legal_fact_applies'],
            (int) $data['jmhz_ozp_employment_support_applies'],
            (int) $data['jmhz_deep_mining_work_applies'],
            $data['social_insurance_participation'],
            $data['health_insurance_participation'],
            $data['tax_regime'],
            $data['other_withholding_eligibility'],
            $data['foreign_legislation_country_code'],
            $data['a1_certificate_until'],
            (int) $data['risky_work'],
            $data['social_employer_rate_category'],
            $data['social_employer_rate_category_evidence'],
            $data['social_part_time_discount_reason'],
            $data['social_part_time_discount_evidence'],
            $data['social_part_time_discount_notified_on'],
            (int) $data['tax_declaration_signed'],
            (int) $data['is_primary'],
            $data['change_reason'],
            $userId,
            $data['jmhz_assignment_user_kind'] ?? null,
            $data['jmhz_assignment_user_ico'] ?? null,
            $data['jmhz_assignment_user_country_code'] ?? null,
            $data['jmhz_assignment_user_foreign_id'] ?? null,
            $data['jmhz_assignment_user_name'] ?? null,
            $data['jmhz_risk_categorization_code'] ?? null,
            (int) ($data['health_association_member'] ?? false),
            ...self::workplaceAddressValues($data),
        ]);
    }

    /**
     * Adresa místa výkonu a příznak státního zástupce (JMHZ 2027, migrace 2213)
     * v pořadí sloupců INSERT i UPDATE.
     *
     * @param array<string,mixed> $data
     * @return list<string|int|null>
     */
    private static function workplaceAddressValues(array $data): array
    {
        $prosecutor = $data['jmhz_state_prosecutor'] ?? null;

        return [
            $data['jmhz_workplace_address_point_code'] ?? null,
            $data['jmhz_workplace_street'] ?? null,
            $data['jmhz_workplace_house_number'] ?? null,
            $data['jmhz_workplace_orientation_number'] ?? null,
            $data['jmhz_workplace_postal_code'] ?? null,
            $prosecutor === null ? null : (int) (bool) $prosecutor,
        ];
    }

    /**
     * Přepis řádku podmínek na místě. Sloupce jsou tytéž jako u
     * {@see insertTerms()} kromě `effective_from` a `effective_to`, které
     * drží zařazení verze v čase — a to oprava měnit nesmí.
     *
     * @param TermsInput $data
     */
    private function updateTerms(int $supplierId, int $termsId, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employment_terms SET
                 office_id = ?,
                 contract_signed_on = ?, planned_start_on = ?, actual_start_on = ?,
                 fixed_term_end_on = ?, monthly_gross_minor = ?, weekly_hours = ?,
                 leave_entitlement_weeks_override = ?,
                 probable_hourly_earning_minor = ?, probable_earning_rationale = ?,
                 workload_basis_points = ?,
                 work_place = ?, regular_workplace = ?, cz_isco_code = ?,
                 activity_code = ?, jmhz_relationship_detail_code = ?,
                 jmhz_workplace_municipality_code = ?, jmhz_workplace_country_code = ?,
                 jmhz_external_codebook_overlay_key = ?,
                 jmhz_external_codebook_manifest_sha256 = ?,
                 jmhz_apz_contribution_status = ?, jmhz_apz_instrument_code = ?,
                 jmhz_functional_benefits_status = ?,
                 jmhz_temporary_assignment_status = ?,
                 jmhz_orchard_discount_eligible = ?,
                 jmhz_specific_legal_fact_applies = ?,
                 jmhz_ozp_employment_support_applies = ?,
                 jmhz_deep_mining_work_applies = ?,
                 social_insurance_participation = ?, health_insurance_participation = ?,
                 tax_regime = ?, other_withholding_eligibility = ?,
                 foreign_legislation_country_code = ?, a1_certificate_until = ?,
                 risky_work = ?,
                 social_employer_rate_category = ?, social_employer_rate_category_evidence = ?,
                 social_part_time_discount_reason = ?, social_part_time_discount_evidence = ?,
                 social_part_time_discount_notified_on = ?,
                 tax_declaration_signed = ?, is_primary = ?, change_reason = ?,
                 jmhz_assignment_user_kind = ?, jmhz_assignment_user_ico = ?,
                 jmhz_assignment_user_country_code = ?, jmhz_assignment_user_foreign_id = ?,
                 jmhz_assignment_user_name = ?, jmhz_risk_categorization_code = ?,
                 health_association_member = ?,
                 jmhz_workplace_address_point_code = ?, jmhz_workplace_street = ?,
                 jmhz_workplace_house_number = ?, jmhz_workplace_orientation_number = ?,
                 jmhz_workplace_postal_code = ?, jmhz_state_prosecutor = ?,
                 row_version = row_version + 1
               WHERE supplier_id = ? AND id = ?'
        )->execute([
            $data['office_id'],
            $data['contract_signed_on'],
            $data['planned_start_on'],
            $data['actual_start_on'],
            $data['fixed_term_end_on'],
            $data['monthly_gross_minor'],
            $data['weekly_hours'],
            $data['leave_entitlement_weeks_override'],
            $data['probable_hourly_earning_minor'],
            $data['probable_earning_rationale'],
            $data['workload_basis_points'],
            $data['work_place'],
            $data['regular_workplace'],
            $data['cz_isco_code'],
            $data['activity_code'],
            $data['jmhz_relationship_detail_code'],
            $data['jmhz_workplace_municipality_code'],
            $data['jmhz_workplace_country_code'],
            $data['jmhz_external_codebook_overlay_key'],
            $data['jmhz_external_codebook_manifest_sha256'],
            $data['jmhz_apz_contribution_status'],
            $data['jmhz_apz_instrument_code'],
            $data['jmhz_functional_benefits_status'],
            $data['jmhz_temporary_assignment_status'],
            (int) $data['jmhz_orchard_discount_eligible'],
            (int) $data['jmhz_specific_legal_fact_applies'],
            (int) $data['jmhz_ozp_employment_support_applies'],
            (int) $data['jmhz_deep_mining_work_applies'],
            $data['social_insurance_participation'],
            $data['health_insurance_participation'],
            $data['tax_regime'],
            $data['other_withholding_eligibility'],
            $data['foreign_legislation_country_code'],
            $data['a1_certificate_until'],
            (int) $data['risky_work'],
            $data['social_employer_rate_category'],
            $data['social_employer_rate_category_evidence'],
            $data['social_part_time_discount_reason'],
            $data['social_part_time_discount_evidence'],
            $data['social_part_time_discount_notified_on'],
            (int) $data['tax_declaration_signed'],
            (int) $data['is_primary'],
            $data['change_reason'],
            $data['jmhz_assignment_user_kind'] ?? null,
            $data['jmhz_assignment_user_ico'] ?? null,
            $data['jmhz_assignment_user_country_code'] ?? null,
            $data['jmhz_assignment_user_foreign_id'] ?? null,
            $data['jmhz_assignment_user_name'] ?? null,
            $data['jmhz_risk_categorization_code'] ?? null,
            (int) ($data['health_association_member'] ?? false),
            ...self::workplaceAddressValues($data),
            $supplierId,
            $termsId,
        ]);
    }

    /**
     * Opravit na místě jde jen verzi, ze které ještě nikdo nic nevyplatil.
     *
     * Zúčtované období je uzavřený výstup: mzdový list, odvody i podání
     * vycházejí z podmínek, které v něm platily. Přepsat je zpětně by
     * znamenalo, že se doklad a data pod ním rozejdou, aniž by o tom byl
     * jediný záznam. Od chvíle, kdy je běh zaúčtovaný nebo vyplacený, je
     * proto správná cesta NOVÁ VERZE od konkrétního data — a uživatel se to
     * musí dozvědět větou, ne obecným „nepovedlo se".
     *
     * Totéž hlídá i nová verze ({@see addTerms()}, rozsah bez konce): změna
     * se nesmí protáhnout do zúčtovaného měsíce ani jednou z cest. Veřejné,
     * aby se na totéž pravidlo mohl předem zeptat import (náhled změn mzdy).
     */
    public function assertTermsOpenFrom(
        int $supplierId,
        int $employmentId,
        string $effectiveFrom,
        ?string $effectiveTo,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MIN(run.period_start)
               FROM payroll_run_employments run_employment
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run_employment.supplier_id
                AND revision.id = run_employment.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE run_employment.supplier_id = ?
                AND run_employment.employment_id = ?
                AND run.status IN ("posted", "payment_ready", "paid", "closed")
                AND run.period_start >= ?
                AND (? IS NULL OR run.period_start <= ?)'
        );
        $stmt->execute([
            $supplierId,
            $employmentId,
            // Období běhu je vždy prvním dnem měsíce, kdežto účinnost verze
            // může začít uprostřed — porovnává se proto k začátku měsíce.
            substr($effectiveFrom, 0, 8) . '01',
            $effectiveTo,
            $effectiveTo,
        ]);
        $settled = $stmt->fetchColumn();
        if (is_string($settled) && $settled !== '') {
            throw new PayrollTermsSettledException($settled);
        }
    }

    /**
     * @param array<string,string|int|bool|null>|null $previous
     * @param TermsInput $current
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private function diff(?array $previous, array $current): array
    {
        $diff = [];
        foreach ($current as $key => $value) {
            if (in_array($key, ['change_reason'], true)) {
                continue;
            }
            $old = $previous[$key] ?? null;
            if ($old !== $value && (string) $old !== (string) $value) {
                $diff[$key] = ['from' => $old, 'to' => $value];
            }
        }
        return $diff;
    }

    /** @return array<string,string|int|bool|null>|null */
    /**
     * Verze podmínek TĚSNĚ PŘED danou — protějšek posunuté účinnosti.
     *
     * @return array<string,string|int|bool|null>|null
     */
    private function previousTermsForUpdate(
        int $supplierId,
        int $employmentId,
        int $currentTermsId,
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ? AND id <> ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId, $currentTermsId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->cast($this->row($row));
    }

    /**
     * Kam až smí posunutá účinnost.
     *
     * Věty musí říct, čím je posun omezený — „nepovedlo se" by uživatele
     * poslalo hádat mezi třemi různými důvody.
     *
     * @param array<string,string|int|bool|null>|null $predecessor
     * @param array<string,string|int|bool|null> $employment
     */
    private function assertEffectiveFromMovable(
        string $movedFrom,
        ?string $storedTo,
        ?array $predecessor,
        array $employment,
    ): void {
        if ($storedTo !== null && $movedFrom > $storedTo) {
            throw new \DomainException(sprintf(
                'Účinnost nelze posunout za konec této verze (%s).',
                $storedTo,
            ));
        }
        if ($predecessor !== null) {
            $predecessorFrom = (string) $predecessor['effective_from'];
            if ($movedFrom <= $predecessorFrom) {
                throw new \DomainException(sprintf(
                    'Účinnost musí zůstat pozdější než předchozí verze podmínek'
                    . ' (od %s). Opravte nejdřív ji, nebo verzi ponechte a novou'
                    . ' skutečnost zapište od data, kdy začíná platit.',
                    $predecessorFrom,
                ));
            }

            return;
        }
        // Bez předchozí verze je hranicí nástup: podmínky nemůžou platit dřív,
        // než vztah vůbec vznikl.
        $start = $employment['actual_start_date'] ?? $employment['start_date'] ?? null;
        if (is_string($start) && $start !== '' && $movedFrom < $start) {
            throw new \DomainException(sprintf(
                'První verze podmínek nemůže začít před nástupem (%s).',
                $start,
            ));
        }
    }

    private function latestTermsForUpdate(int $supplierId, int $employmentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
               FROM payroll_employment_terms
              WHERE supplier_id = ? AND employment_id = ?
              ORDER BY effective_from DESC, id DESC
              LIMIT 1
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->cast($this->row($row));
    }

    /** @param array<string,mixed> $diff */
    private function insertEvent(
        int $supplierId,
        int $employmentId,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        string $effectiveOn,
        ?string $note,
        array $diff,
        ?int $userId,
    ): void {
        $this->db->pdo()->prepare(
            'INSERT INTO payroll_employment_events
                (supplier_id, employment_id, event_type, from_status, to_status,
                 effective_on, note, diff_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId,
            $employmentId,
            $eventType,
            $fromStatus,
            $toStatus,
            $effectiveOn,
            $note,
            json_encode($diff, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $userId,
        ]);
    }

    /**
     * Založení vztahu nesmí v časové ose ležet později než změna jeho stavu.
     *
     * Efektivní stav se čte jako poslední událost podle `effective_on`
     * ({@see PayrollEmploymentLifecycleSql}). Vztah zapsaný dnes, ale s nástupem
     * loni, měl událost `created → planned` s dnešním datem, takže zpětně
     * potvrzený nástup (`status_changed → active` k loňskému datu) zůstal pod ní
     * a vztah se dál tvářil jako plánovaný: v seznamu lidí svítil jako aktivní
     * (ten čte sloupec `status`), ale z rychlých vstupů, docházky i z karet na
     * přehledu mezd vypadl. Založení proto ustoupí zpět na datum té změny —
     * plánovaným vztah byl od chvíle, kdy podle evidence začal, ne od chvíle,
     * kdy ho někdo naťukal.
     */
    private function alignCreatedEvent(int $supplierId, int $employmentId, string $effectiveOn): void
    {
        $this->db->pdo()->prepare(
            "UPDATE payroll_employment_events
                SET effective_on = ?
              WHERE supplier_id = ? AND employment_id = ?
                AND event_type = 'created' AND effective_on > ?"
        )->execute([$effectiveOn, $supplierId, $employmentId, $effectiveOn]);
    }

    /**
     * Pořadové číslo vztahu u osoby — první vztah `1`, druhý `2`.
     *
     * Bez prefixu a bez ročníku: unikátnost hlídá `uq_payroll_employment_code`
     * per firma+osoba, takže pořadí stačí. Přeskakují se obsazená čísla, protože
     * u převzatých osob můžou existovat vlastní kódy i značka `legacy`.
     *
     * Volá se pod zámkem osoby (`lockEmployee`), takže dva souběžné požadavky
     * nedostanou totéž číslo.
     */
    private function nextEmploymentCode(int $supplierId, int $employeeId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ?'
        );
        $stmt->execute([$supplierId, $employeeId]);
        $taken = array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));

        $next = 1;
        while (in_array((string) $next, $taken, true)) {
            ++$next;
        }

        return (string) $next;
    }

    /**
     * Z archivu vede zpátky JEDINÁ cesta — do stavu, ze kterého se archivovalo.
     *
     * Lifecycle zná jen stav, ne historii, takže by nabídl „skončený" i
     * „nenastoupil" naráz a uživatel by si musel vybrat mezi dvěma nabídkami,
     * z nichž jedna přepisuje minulost. Předchozí stav je přitom zaznamenaný
     * v události archivace.
     *
     * @return list<string>
     */
    private function allowedTransitions(
        int $supplierId,
        int $employmentId,
        string $status,
    ): array {
        $targets = $this->lifecycle->allowedTargets($status);
        if ($status !== 'archived') {
            return $targets;
        }
        $previous = $this->statusBeforeArchive($supplierId, $employmentId);

        return $previous !== null && in_array($previous, $targets, true) ? [$previous] : [];
    }

    private function statusBeforeArchive(int $supplierId, int $employmentId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT from_status
               FROM payroll_employment_events
              WHERE supplier_id = ? AND employment_id = ?
                AND event_type = 'status_changed' AND to_status = 'archived'
              ORDER BY id DESC
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employmentId]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param string $eventOn den události fáze — nástup, účinnost změny,
     *                        skončení. NENÍ to termín: ten se z něj teprve
     *                        odvodí podle zákonné lhůty jednotlivé položky.
     */
    private function ensureChecklist(
        int $supplierId,
        int $employmentId,
        string $phase,
        string $eventOn,
        string $relationType,
    ): void {
        $insert = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, due_date,
                 deadline_ruleset_id, deadline_source, deadline_source_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $skipped = self::CHECKLIST_EXCEPTIONS[$relationType] ?? [];
        $policy = new PayrollChecklistDeadlinePolicy();
        $startPeriod = $this->moduleStartPeriod($supplierId);
        foreach (self::CHECKLISTS[$phase] as $itemKey) {
            if (in_array($itemKey, $skipped, true)) {
                continue;
            }
            $deadline = $policy->forItem($itemKey, $eventOn, $relationType);
            // null = povinnost na tenhle vztah nedopadá (evidenční list od
            // roku 2026). Založit ji a nechat obsluhu, ať ji odklikne jako
            // „netýká se", by byl planý poplach u každého skončení.
            if ($deadline === null) {
                continue;
            }
            // Událost před začátkem vedení mezd v MyÚčtu vyřídil předchozí
            // program — typicky převzetí vztahu z importu.
            if (PayrollPredecessorObligationScope::handledByPredecessor(
                $startPeriod,
                $itemKey,
                $phase,
                $deadline->dueOn,
                $phase === 'onboarding' ? $eventOn : null,
                $phase === 'offboarding' ? $eventOn : null,
                $phase === 'change' ? $eventOn : null,
            )) {
                continue;
            }
            $insert->execute([
                $supplierId,
                $employmentId,
                $phase,
                $itemKey,
                $deadline->dueOn,
                $deadline->rulesetId,
                $deadline->source,
                $deadline->sourceStatus,
            ]);
        }
    }

    /**
     * Úkol na vztahu mimo zákonný checklist fáze — zakládá ho převzetí, když
     * zdroj něco dokládá jen příznakem. Existující položka (i vyřízená) se
     * nechává být, takže opakovaný import úkol nevrátí.
     *
     * @return bool true = položka vznikla
     */
    public function ensureFollowUpItem(
        int $supplierId,
        int $employmentId,
        string $itemKey,
        string $dueOn,
        string $note,
    ): bool {
        if (!in_array($itemKey, self::FOLLOW_UP_ITEMS, true)) {
            throw new \InvalidArgumentException("Neznámý úkol na vztahu: {$itemKey}.");
        }
        $insert = $this->db->pdo()->prepare(
            "INSERT IGNORE INTO payroll_employment_checklist_items
                (supplier_id, employment_id, phase, item_key, due_date,
                 deadline_ruleset_id, deadline_source, deadline_source_status, note)
             VALUES (?, ?, 'change', ?, ?, NULL, ?, 'not_derived', ?)"
        );
        $insert->execute([
            $supplierId,
            $employmentId,
            $itemKey,
            $dueOn,
            'první mzda v MyÚčtu',
            mb_substr($note, 0, 500),
        ]);

        return $insert->rowCount() === 1;
    }

    /**
     * Příznak „nástup je jen odhad z hlášení". Nemění verzi řádku: je to
     * vlastnost doložení nástupu, ne údaj, který by kolidoval s rozepsaným
     * formulářem karty.
     */
    public function markStartEstimated(int $supplierId, int $employmentId, bool $estimated): void
    {
        $this->db->pdo()->prepare(
            'UPDATE payroll_employments SET start_estimated = ? WHERE supplier_id = ? AND id = ?'
        )->execute([(int) $estimated, $supplierId, $employmentId]);
    }

    /**
     * Uživatel ověřil nástup odhadnutý z hlášení (smlouva, přihláška) a potvrdil
     * ho beze změny data.
     *
     * @return array<string,mixed>
     */
    public function confirmEstimatedStart(
        int $supplierId,
        int $employmentId,
        int $expectedVersion,
        ?int $userId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        return $this->transaction(function () use ($supplierId, $employmentId, $expectedVersion, $userId, $ip, $userAgent): array {
            $employment = $this->lockEmployment($supplierId, $employmentId, $expectedVersion);
            $update = $this->db->pdo()->prepare(
                'UPDATE payroll_employments
                    SET start_estimated = 0, row_version = row_version + 1
                  WHERE supplier_id = ? AND id = ? AND row_version = ?'
            );
            $update->execute([$supplierId, $employmentId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new PayrollEmploymentConflictException($expectedVersion);
            }
            $this->activityLogger->log(
                'payroll.employment.start_confirmed',
                $userId,
                'payroll_employment',
                $employmentId,
                ['start_date' => $employment['actual_start_date'] ?? $employment['start_date']],
                $ip,
                $userAgent,
                $supplierId,
            );

            return $this->find($supplierId, (int) $employment['employee_id'], $employmentId);
        });
    }

    private function moduleStartPeriod(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT start_period FROM payroll_module_state WHERE supplier_id = ?'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function lockEmployee(int $supplierId, int $employeeId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_employees
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new PayrollEmploymentNotFoundException('Zaměstnanec nebyl nalezen.');
        }
    }

    /** @return array<string,string|int|bool|null> */
    private function lockEmployment(
        int $supplierId,
        int $employmentId,
        ?int $expectedVersion,
    ): array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, employee_id, office_id, code, relation_type,
                    meal_entitlement_basis, status, is_primary,
                    start_date,
                    actual_start_date, end_date, monthly_gross_minor, row_version
               FROM payroll_employments
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE'
        );
        $stmt->execute([$supplierId, $employmentId]);
        $fetched = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fetched === false) {
            throw new PayrollEmploymentNotFoundException('Pracovní vztah nebyl nalezen.');
        }
        $row = $this->cast($this->row($fetched));
        if ($expectedVersion !== null && (int) $row['row_version'] !== $expectedVersion) {
            throw new PayrollEmploymentConflictException((int) $row['row_version']);
        }
        return $row;
    }

    /**
     * Mzdová účtárna vztahu je POVINNÁ, ale uživatel ji nezadává.
     *
     * Variabilní symbol zaměstnavatele pro sociální pojistné vychází výhradně
     * z `payroll_offices`, takže vztah bez účtárny nejde odvést; běh zúžený na
     * účtárnu by ho navíc tiše vynechal. Formulář vztahu přitom účtárnu vůbec
     * nenabízí a rychlá editace o poli neví — kdyby byla povinná na vstupu,
     * nebylo by ji kde vyplnit. Doplňuje se proto tady: co drží vztah dnes,
     * pak výchozí účtárna zaměstnavatele (`default_office_id` je NOT NULL).
     * Když ani ta není, je to pojmenovaná překážka při zakládání vztahu, tedy
     * v okamžiku, kdy se dá napravit.
     */
    private function resolveOffice(
        int $supplierId,
        ?int $officeId,
        ?int $currentOfficeId = null,
    ): int {
        $officeId ??= $currentOfficeId ?? $this->defaultOffice($supplierId);
        if ($officeId === null) {
            throw new \InvalidArgumentException(
                'Pracovní vztah nelze vést bez mzdové účtárny —'
                . ' nejdřív ji nastavte u zaměstnavatele.',
            );
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_offices
              WHERE supplier_id = ? AND id = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $officeId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Mzdová účtárna neexistuje nebo není aktivní.');
        }

        return $officeId;
    }

    private function defaultOffice(int $supplierId): ?int
    {
        if (!$this->db->hasTable('payroll_employer_settings')) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT settings.default_office_id
               FROM payroll_employer_settings settings
               JOIN payroll_offices office
                 ON office.supplier_id = settings.supplier_id
                AND office.id = settings.default_office_id
              WHERE settings.supplier_id = ? AND office.is_active = 1'
        );
        $stmt->execute([$supplierId]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    private function assertPrimaryAvailable(
        int $supplierId,
        int $employeeId,
        bool $isPrimary,
        ?int $exceptEmploymentId,
    ): void {
        if (!$isPrimary) {
            return;
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT id
               FROM payroll_employments
              WHERE supplier_id = ? AND employee_id = ? AND is_primary = 1
                AND status IN ('planned', 'preregistered', 'active', 'suspended')
                AND (? IS NULL OR id <> ?)
              LIMIT 1"
        );
        $stmt->execute([$supplierId, $employeeId, $exceptEmploymentId, $exceptEmploymentId]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException('Osoba už má jiný primární pracovní vztah.');
        }
    }

    /**
     * Položku „Doplnit datum nástupu" jde odškrtnout až tehdy, když datum
     * skutečně je. Jinak by to bylo prázdné gesto: checklist by hlásil hotovo
     * a na kartě by dál svítily tři pomlčky.
     *
     * @param array<string,string|int|bool|null> $employment
     */
    private function assertChecklistPrerequisite(
        string $itemKey,
        string $status,
        array $employment,
    ): void {
        if ($itemKey !== 'legacy_start_date' || $status !== 'completed') {
            return;
        }
        if (($employment['start_date'] ?? null) === null
            && ($employment['actual_start_date'] ?? null) === null
        ) {
            throw new \DomainException(
                'Nejdřív doplňte datum nástupu v podmínkách vztahu, teprve pak jde '
                . 'položku odškrtnout.',
            );
        }
    }

    /**
     * Vrátit ukončení jde, dokud z něj nevyšel doklad ven.
     *
     * Výstupní doklad (zápočtový list, potvrzení o zdanitelných příjmech) je
     * neměnný a odešel zaměstnanci; jeho revize se navíc váže PŘESNĚ na datum
     * konce, takže by vztah bez data konce nechala viset. Zbytek — hlášení,
     * mzdy za odpracované období — návratu nepřekáží: ta období se nemění.
     */
    private function assertReopenable(int $supplierId, int $employmentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
               FROM payroll_employment_exit_revisions
              WHERE supplier_id = ? AND employment_id = ?
              LIMIT 1'
        );
        $stmt->execute([$supplierId, $employmentId]);
        if ($stmt->fetchColumn() !== false) {
            throw new \DomainException(
                'K tomuhle vztahu je už vydaný výstupní doklad, takže ukončení'
                . ' nejde vzít zpět. Pokud osoba pokračuje, založte jí nový'
                . ' pracovní vztah od data, kdy znovu nastoupila.',
            );
        }
    }

    /** @param array<string,string|int|bool|null> $employment */
    private function assertTransitionDate(array $employment, string $target, string $effectiveOn): void
    {
        // Návrat z archivu žádné datum nenastavuje, takže se proti nástupu
        // nemá co porovnávat — jinak by oprava omylu spadla na tom, že se
        // vztah vrací dřív, než kdy začal.
        if (($employment['status'] ?? null) === 'archived') {
            return;
        }
        $start = $employment['actual_start_date'] ?? $employment['start_date'];
        if (in_array($target, ['ended', 'no_show'], true)
            && $start !== null
            && $effectiveOn < (string) $start) {
            throw new \DomainException('Datum skončení nesmí předcházet nástupu.');
        }
        if ($target === 'archived' && $employment['end_date'] !== null
            && $effectiveOn < (string) $employment['end_date']) {
            throw new \DomainException('Archivace nesmí předcházet skončení vztahu.');
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT payroll_employment_change');
        }
        try {
            $result = $callback();
            if ($owns) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_change');
            }
            return $result;
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($owns) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT payroll_employment_change');
                $pdo->exec('RELEASE SAVEPOINT payroll_employment_change');
            }
            throw $e;
        }
    }

    /** @param array<string,string|int|bool|null> $row
     *  @return array<string,string|int|bool|null>
     */
    private function cast(array $row): array
    {
        $ints = [
            'id',
            'employee_id',
            'office_id',
            'monthly_gross_minor',
            'probable_hourly_earning_minor',
            'workload_basis_points',
            'row_version',
        ];
        $bools = [
            'is_primary',
            'is_legacy_projection',
            'risky_work',
            'tax_declaration_signed',
            'jmhz_orchard_discount_eligible',
            'jmhz_specific_legal_fact_applies',
            'jmhz_ozp_employment_support_applies',
            'jmhz_deep_mining_work_applies',
            'health_association_member',
            'jmhz_state_prosecutor',
        ];
        foreach ($ints as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach ($bools as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (bool) $row[$key];
            }
        }
        return $row;
    }

    /** @return array<string,string|int|bool|null> */
    private function row(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Databáze vrátila neplatný řádek pracovního vztahu.');
        }
        $row = [];
        foreach ($value as $key => $cell) {
            if (!is_string($key)
                || (!is_string($cell) && !is_int($cell) && !is_bool($cell) && $cell !== null)
            ) {
                throw new \UnexpectedValueException('Databáze vrátila neplatnou hodnotu pracovního vztahu.');
            }
            $row[$key] = $cell;
        }
        return $row;
    }
}
