<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollPredecessorObligationScope;
use PDO;

/**
 * Podklady pro přehled mzdových termínů.
 *
 * Modul uměl lhůty SPOČÍTAT ({@see \MyInvoice\Service\Payroll\Deadline\PayrollLevyDeadlinePolicy},
 * lhůty podání), ale nikomu je neřekl: nebyl žádný dotaz, který by za firmu
 * vrátil „co je do kdy a co je po termínu". Přehled se skládal ručně z tří
 * různých obrazovek, každá za jiné období, a zmeškaný termín se nikde
 * nezvýraznil.
 *
 * Repozitář vrací HOLÁ DATA ze tří pramenů; o tom, co je „brzy" a co „po
 * termínu", rozhoduje až
 * {@see \MyInvoice\Service\Payroll\Deadline\PayrollDeadlineOverviewService}
 * proti hodinám — aby se to dalo otestovat bez posouvání systémového času.
 */
final readonly class PayrollDeadlineOverviewRepository
{
    /**
     * Druhy závazků, které jsou ODVODEM se zákonnou splatností. Čistá mzda,
     * srážky ani exekuce sem nepatří: jejich termín plyne ze smlouvy nebo
     * z rozhodnutí, ne ze zákonné lhůty odvodu, a promíchat je do jednoho
     * seznamu by z přehledu udělalo výpis všech plateb.
     */
    private const LEVY_KINDS = [
        'social_insurance',
        'health_insurance',
        'advance_tax',
        'withholding_tax',
        'statutory_insurance',
    ];

    public function __construct(private Connection $db) {}

    /**
     * Nesplněné povinnosti podání s termínem v okně.
     *
     * @return list<array{
     *   obligation_id:int,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   status:string,earliest_submission_on:string,due_on:string,
     *   ruleset_id:string,submission_status:?string
     * }>
     */
    public function submissionDeadlines(
        int $supplierId,
        string $environment,
        string $from,
        string $to,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id AS obligation_id,
                    obligation.agenda_code,
                    obligation.subject_type,
                    obligation.subject_reference,
                    obligation.period_start,
                    obligation.period_end,
                    obligation.status,
                    deadline.earliest_submission_on,
                    deadline.due_on,
                    deadline.ruleset_id,
                    (SELECT submission.status
                       FROM payroll_submissions submission
                      WHERE submission.supplier_id = obligation.supplier_id
                        AND submission.environment = obligation.environment
                        AND submission.obligation_id = obligation.id
                      ORDER BY submission.created_at DESC, submission.id DESC
                      LIMIT 1) AS submission_status
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = \'regular\'
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.status NOT IN (\'fulfilled\', \'cancelled\')
                AND deadline.due_on >= ?
                AND deadline.due_on <= ?
              ORDER BY deadline.due_on ASC, obligation.id ASC'
        );
        $statement->execute([$supplierId, $environment, $from, $to]);

        return $this->rows($statement);
    }

    /**
     * Nezaplacené odvody s termínem v okně.
     *
     * Bere se jen AKTUÁLNÍ revize běhu: závazky přepočtené revize zůstávají
     * v evidenci kvůli dohledatelnosti, ale platit se má ta poslední. Jinak
     * by přehled hlásil dva termíny na tentýž odvod.
     *
     * „Nezaplacený" znamená bez úhrady V PLATEBNÍ KNIZE i bez provizorního
     * signálu ({@see PayrollPaymentSettlementSignalRepository}). Signál sám
     * závazek nezavírá — jen tvrdí, že peníze už odešly, a to stačí na to,
     * aby hlídač termínů přestal strašit odvodem, který je dávno zaplacený.
     *
     * @return list<array{
     *   liability_id:int,liability_kind:string,due_on:string,
     *   amount_minor:int,settled_minor:int,recipient_reference:string,
     *   recipient_name:?string,period_start:string,run_id:int
     * }>
     */
    public function levyDeadlines(
        int $supplierId,
        string $from,
        string $to,
    ): array {
        $kinds = implode(
            ',',
            array_fill(0, count(self::LEVY_KINDS), '?'),
        );
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id AS liability_id,
                    liability.liability_kind,
                    liability.due_on,
                    liability.amount_minor,
                    liability.recipient_reference,
                    institution_account.institution_name AS recipient_name,
                    run.period_start,
                    run.id AS run_id,
                    COALESCE(settlement.settled_minor, 0) AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
                AND run.current_revision_no = revision.revision_no
          LEFT JOIN payroll_institution_accounts institution_account
                 ON institution_account.supplier_id = liability.supplier_id
                AND institution_account.id = CASE
                      WHEN liability.recipient_reference
                           LIKE "institution:%:account:%"
                      THEN CAST(SUBSTRING_INDEX(
                             liability.recipient_reference, ":", -1
                           ) AS UNSIGNED)
                      ELSE NULL
                    END
          LEFT JOIN (
                    SELECT supplier_id, liability_id,
                           SUM(amount_minor) AS settled_minor
                      FROM payroll_payment_matches
                     WHERE supplier_id = ? AND liability_id IS NOT NULL
                     GROUP BY supplier_id, liability_id
               ) settlement
                 ON settlement.supplier_id = liability.supplier_id
                AND settlement.liability_id = liability.id
              WHERE liability.supplier_id = ?
                AND liability.direction = \'outgoing\'
                AND liability.liability_kind IN (' . $kinds . ')
                AND liability.due_on >= ?
                AND liability.due_on <= ?
                AND liability.amount_minor
                    > COALESCE(settlement.settled_minor, 0)
                -- Provizorní signál úhrady (bankovní avízo, ruční prohlášení
                -- účetní — migrace 1750): peníze z účtu prokazatelně odešly,
                -- jen ještě nedorazil výpis, kterým se to doúčtuje. Hlídač
                -- termínů proto mlčí; v saldu závazek dál otevřený zůstává.
                AND NOT EXISTS (
                      SELECT 1
                        FROM payroll_payment_settlement_signals settlement_signal
                       WHERE settlement_signal.supplier_id = liability.supplier_id
                         AND settlement_signal.liability_id = liability.id
                         AND settlement_signal.resolved_at IS NULL
                    )
              ORDER BY liability.due_on ASC, liability.id ASC'
        );
        $statement->execute([
            $supplierId,
            $supplierId,
            ...self::LEVY_KINDS,
            $from,
            $to,
        ]);

        return $this->rows($statement);
    }

    /**
     * Nevyřízené položky nástupních a výstupních checklistů s termínem v okně.
     *
     * Položka, ke které existuje DOKLAD (evidenční list, potvrzení, evidovaná
     * povinnost podání), se do přehledu nedostane, i když ji nikdo neodklikl —
     * jinak by hlídač připomínal to, co je hotové.
     *
     * `$itemKey` zúží výběr na jeden druh položky — seskupený přehled pak
     * nemusí kvůli jedné skupině tahat celou firmu.
     *
     * @return list<array{
     *   item_id:int,employment_id:int,employee_id:int,full_name:string,
     *   employment_code:?string,employment_end_date:?string,phase:string,item_key:string,due_date:string,
     *   deadline_source:?string,deadline_source_status:?string
     * }>
     */
    public function checklistDeadlines(
        int $supplierId,
        string $from,
        string $to,
        ?string $itemKey = null,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id AS item_id,
                    item.employment_id,
                    employment.employee_id,
                    employee.full_name,
                    employment.code AS employment_code,
                    employment.end_date AS employment_end_date,
                    item.phase,
                    item.item_key,
                    item.due_date,
                    item.deadline_source,
                    item.deadline_source_status
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id
                AND employment.id = item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE item.supplier_id = ?
                AND item.due_date IS NOT NULL
                AND item.due_date >= ?
                AND item.due_date <= ?
                AND (? IS NULL OR item.item_key = ?)
                AND ' . self::openChecklistItem() . '
              ORDER BY item.due_date ASC, item.id ASC'
        );
        $statement->execute([$supplierId, $from, $to, $itemKey, $itemKey]);

        return $this->rows($statement);
    }

    /**
     * Nevyřízené položky checklistu BEZ odvozeného termínu.
     *
     * Termín chybí tam, kde ho zákon neukládá nebo kde ho aplikace odvodit
     * nesmí (přihláška ČSSZ u nástupu před 1. 7. 2026, potvrzení o příjmech
     * na žádost, kontrola exekucí). Povinnost tím nezmizela: mzdový běh ji
     * dál hlásí jako chybějící přihlášku a radí ji odškrtnout. Bez tohohle
     * dotazu byla k vidění jen na kartě každého vztahu zvlášť, takže 225 lidí
     * po importu znamenalo 225 ručních odškrtnutí.
     *
     * Výběr je TENTÝŽ jako u položek s termínem ({@see self::openChecklistItem()}),
     * liší se jen podmínkou na `due_date`.
     *
     * @return list<array{
     *   item_id:int,employment_id:int,employee_id:int,full_name:string,
     *   employment_code:?string,employment_end_date:?string,phase:string,item_key:string,due_date:null,
     *   deadline_source:?string,deadline_source_status:?string
     * }>
     */
    public function checklistWithoutDeadline(int $supplierId, ?string $itemKey = null): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id AS item_id,
                    item.employment_id,
                    employment.employee_id,
                    employee.full_name,
                    employment.code AS employment_code,
                    employment.end_date AS employment_end_date,
                    item.phase,
                    item.item_key,
                    item.due_date,
                    item.deadline_source,
                    item.deadline_source_status
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id
                AND employment.id = item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE item.supplier_id = ?
                AND item.due_date IS NULL
                AND (? IS NULL OR item.item_key = ?)
                AND ' . self::openChecklistItem() . '
              ORDER BY item.id ASC'
        );
        $statement->execute([$supplierId, $itemKey, $itemKey]);

        return $this->rows($statement);
    }

    /**
     * Počty nevyřízených položek bez termínu podle druhu — seskupený přehled
     * kvůli řádku „Registrace ČSSZ / JMHZ, 225 osob" nemusí tahat jména.
     *
     * @return array<string,int> klíčem je `item_key`
     */
    public function checklistWithoutDeadlineCounts(int $supplierId): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT item.item_key, COUNT(*) AS item_count
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id
                AND employment.id = item.employment_id
              WHERE item.supplier_id = ?
                AND item.due_date IS NULL
                AND ' . self::openChecklistItem() . '
              GROUP BY item.item_key'
        );
        $statement->execute([$supplierId]);

        $counts = [];
        foreach ($this->rows($statement) as $row) {
            $counts[(string) $row['item_key']] = (int) $row['item_count'];
        }

        return $counts;
    }

    /**
     * Co je „nevyřízená položka checklistu" — sdílené oběma dotazům výše, aby
     * skupina s termínem a skupina bez termínu nevybíraly podle dvou pravidel.
     *
     * Vyřazuje položky, ke kterým existuje DOKLAD — tentýž výraz
     * ({@see PayrollChecklistEvidenceSql}) jako `effective_status` na kartě
     * vztahu; jinak by hlídač připomínal to, co je hotové, nebo naopak mlčel
     * u odhlášky, která je jen připravená. Stejně tak vyřazuje povinnosti, které
     * vyřídil předchozí program ({@see PayrollPredecessorObligationScope}).
     *
     * Předpokládá aliasy `item` a `employment`.
     */
    private static function openChecklistItem(): string
    {
        return "item.status = 'pending'
                AND employment.status NOT IN ('no_show', 'archived')
                AND NOT (" . PayrollChecklistEvidenceSql::evidencePresent() . ')
                AND NOT (' . PayrollPredecessorObligationScope::sql('item') . ')';
    }

    /**
     * Položky checklistu podle id, jen v rámci firmy. Cizí nebo neexistující
     * id se ve výsledku prostě neobjeví a volající je ohlásí jako nenalezené.
     *
     * @param list<int> $itemIds
     * @return array<int,array{
     *   item_id:int,employment_id:int,item_key:string,status:string,
     *   row_version:int,full_name:string
     * }> klíčem je id položky
     */
    public function checklistItemsByIds(int $supplierId, array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($itemIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT item.id AS item_id,
                    item.employment_id,
                    item.item_key,
                    item.status,
                    item.row_version,
                    employee.full_name
               FROM payroll_employment_checklist_items item
               JOIN payroll_employments employment
                 ON employment.supplier_id = item.supplier_id
                AND employment.id = item.employment_id
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE item.supplier_id = ?
                AND item.id IN ('
            . implode(',', array_fill(0, count($itemIds), '?'))
            . ')'
        );
        $statement->execute([$supplierId, ...$itemIds]);

        $items = [];
        foreach ($this->rows($statement) as $row) {
            $items[(int) $row['item_id']] = [
                'item_id' => (int) $row['item_id'],
                'employment_id' => (int) $row['employment_id'],
                'item_key' => (string) $row['item_key'],
                'status' => (string) $row['status'],
                'row_version' => (int) $row['row_version'],
                'full_name' => (string) $row['full_name'],
            ];
        }

        return $items;
    }

    /**
     * Osobní čísla (kód pracovního vztahu) pro výpis lidí ve skupině termínů.
     *
     * @param list<int> $employmentIds
     * @return array<int,string> klíčem je id vztahu
     */
    public function employmentCodes(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            array_map('intval', $employmentIds),
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id, code
               FROM payroll_employments
              WHERE supplier_id = ?
                AND id IN ('
            . implode(',', array_fill(0, count($employmentIds), '?'))
            . ')'
        );
        $statement->execute([$supplierId, ...$employmentIds]);

        $codes = [];
        foreach ($this->rows($statement) as $row) {
            if ($row['code'] !== null && $row['code'] !== '') {
                $codes[(int) $row['id']] = (string) $row['code'];
            }
        }

        return $codes;
    }

    /**
     * Za která zdaňovací období vůbec vzniká povinnost podat roční vyúčtování.
     *
     * Vyúčtování podává „plátce daně, který ve zdaňovacím období zúčtoval nebo
     * vyplatil příjmy ze závislé činnosti" (§ 38j odst. 4 ZDP) — ne každá firma
     * se zapnutým modulem. Zástupným důkazem je SCHVÁLENÁ revize mzdového běhu
     * v daném roce: přesně z ní vyúčtování čerpá
     * ({@see \MyInvoice\Repository\Payroll\PayrollTaxStatementRepository::monthlyTaxTotals()}),
     * takže firma bez schváleného běhu by dostala termín k tiskopisu, který
     * nejde sestavit.
     *
     * Sražená daň se vrací zvlášť, protože vyúčtování srážkové daně má smysl
     * jen tam, kde plátce v roce opravdu srážel — jinak by hlídač připomínal
     * prázdný tiskopis každé firmě, která zaměstnává jen na HPP.
     *
     * @param list<int> $years
     * @return array<int,array{approved_runs:int,withholding_minor:int}>
     *         klíčem je zdaňovací období
     */
    public function taxStatementBasisYears(int $supplierId, array $years): array
    {
        $years = array_values(array_unique(array_map('intval', $years)));
        if ($years === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT YEAR(run.period_start) AS period_year,
                    COUNT(*) AS approved_runs,
                    COALESCE(SUM(CAST(JSON_VALUE(
                        tax.result_snapshot_json,
                        "$.withholding_tax_minor_units"
                    ) AS SIGNED)), 0) AS withholding_minor
               FROM payroll_runs run
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = run.supplier_id
                AND revision.run_id = run.id
                AND revision.revision_no = run.current_revision_no
                AND revision.status = \'approved\'
          LEFT JOIN payroll_statutory_results tax
                 ON tax.supplier_id = revision.supplier_id
                AND tax.revision_id = revision.id
                AND tax.calculation_kind = \'income_tax\'
                AND tax.result_status = \'calculated\'
              WHERE run.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?
              GROUP BY YEAR(run.period_start)'
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', min($years)),
            sprintf('%04d-01-01', max($years) + 1),
        ]);

        $basis = [];
        foreach ($this->rows($statement) as $row) {
            $year = (int) $row['period_year'];
            if (!in_array($year, $years, true)) {
                continue;
            }
            $basis[$year] = [
                'approved_runs' => (int) $row['approved_runs'],
                'withholding_minor' => (int) $row['withholding_minor'],
            ];
        }

        return $basis;
    }

    /**
     * Prokazatelně PODANÁ roční vyúčtování jako `form_code` → seznam období.
     *
     * Archivace snímku ({@see \MyInvoice\Service\Report\TaxSubmissionArchiver})
     * povinnost nesplní — stažené XML může skončit v koši. Termín proto mizí až
     * ve stavu `submitted`/`accepted`, tedy tam, kde je doložený čas podání
     * a identifikátor podatelny; stejné měřítko používá i řetězec dodatečných
     * přiznání. Na variantě nezáleží: řádné, opravné i dodatečné vyúčtování
     * je podání za tentýž rok.
     *
     * @param list<string> $formCodes
     * @param list<int> $years
     * @return array<string,list<int>>
     */
    public function filedTaxStatementYears(
        int $supplierId,
        array $formCodes,
        array $years,
    ): array {
        $years = array_values(array_unique(array_map('intval', $years)));
        $formCodes = array_values(array_unique(array_map('strval', $formCodes)));
        if ($years === [] || $formCodes === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT form_code, period_year
               FROM tax_submissions
              WHERE supplier_id = ?
                AND form_code IN ('
            . implode(',', array_fill(0, count($formCodes), '?'))
            . ')
                AND period_year IN ('
            . implode(',', array_fill(0, count($years), '?'))
            . ')
                AND period_month IS NULL
                AND period_quarter IS NULL
                AND status IN (\'submitted\', \'accepted\')
              GROUP BY form_code, period_year'
        );
        $statement->execute([$supplierId, ...$formCodes, ...$years]);

        $filed = [];
        foreach ($this->rows($statement) as $row) {
            $filed[(string) $row['form_code']][] = (int) $row['period_year'];
        }

        return $filed;
    }

    /** @return list<array<string,mixed>> */
    /**
     * Požádal, ale zúčtování dosud neproběhlo (§ 38ch odst. 4 — do 31. 3.).
     * Kdo musí podat přiznání, tomu se zúčtování neprovádí (§ 38ch odst. 1
     * věta druhá), takže do přehledu nepatří.
     *
     * @param list<int> $years
     * @return list<array{employee_id:int,full_name:string,tax_year:int}>
     */
    public function annualSettlementsToPerform(int $supplierId, array $years): array
    {
        if ($years === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT request.employee_id, employee.full_name, request.tax_year
               FROM payroll_annual_settlement_requests request
               JOIN payroll_employees employee
                 ON employee.supplier_id = request.supplier_id
                AND employee.id = request.employee_id
          LEFT JOIN payroll_annual_settlement_outcomes outcome
                 ON outcome.supplier_id = request.supplier_id
                AND outcome.employee_id = request.employee_id
                AND outcome.tax_year = request.tax_year
              WHERE request.supplier_id = ?
                AND request.tax_year IN (' . implode(',', array_fill(0, count($years), '?')) . ')
                AND request.request_status = "requested"
                AND request.filing_obligation <> "required"
                AND outcome.id IS NULL'
        );
        $statement->execute([$supplierId, ...$years]);

        return array_map(static fn (array $row): array => [
            'employee_id' => (int) $row['employee_id'],
            'full_name' => (string) $row['full_name'],
            'tax_year' => (int) $row['tax_year'],
        ], $this->rows($statement));
    }

    /**
     * Provedené zúčtování s přeplatkem k výplatě, který ještě nevyplatil žádný
     * mzdový běh (§ 38ch odst. 5 — nejpozději se mzdou za březen).
     *
     * @param list<int> $years
     * @return list<array{employee_id:int,full_name:string,tax_year:int,payable_minor:int}>
     */
    public function annualSettlementRefundsUnpaid(int $supplierId, array $years): array
    {
        if ($years === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT outcome.employee_id, employee.full_name, outcome.tax_year,
                    outcome.payable_minor
               FROM payroll_annual_settlement_outcomes outcome
               JOIN payroll_employees employee
                 ON employee.supplier_id = outcome.supplier_id
                AND employee.id = outcome.employee_id
              WHERE outcome.supplier_id = ?
                AND outcome.tax_year IN (' . implode(',', array_fill(0, count($years), '?')) . ')
                AND outcome.payable_minor > 0
                AND outcome.payout_run_id IS NULL'
        );
        $statement->execute([$supplierId, ...$years]);

        return array_map(static fn (array $row): array => [
            'employee_id' => (int) $row['employee_id'],
            'full_name' => (string) $row['full_name'],
            'tax_year' => (int) $row['tax_year'],
            'payable_minor' => (int) $row['payable_minor'],
        ], $this->rows($statement));
    }

    /**
     * Kolik lidí s příjmem v roce ještě nemá rozhodnuto, jestli o roční
     * zúčtování žádá (žádost neexistuje nebo je „nevíme").
     *
     * Příjem = čistý výsledek aktuální schválené revize běhu v roce. Rozhoduje
     * se po lidech, ne po vztazích — zúčtování je za poplatníka.
     *
     * @param list<int> $years
     * @return array<int,int> rok → počet lidí
     */
    public function annualSettlementUndecidedCounts(int $supplierId, array $years): array
    {
        if ($years === []) {
            return [];
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT YEAR(person.period_start) AS tax_year,
                    COUNT(DISTINCT person.employee_id) AS undecided
               FROM payroll_run_persons person
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = person.supplier_id
                AND revision.id = person.revision_id
                AND revision.status = "approved"
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
                AND run.current_revision_no = revision.revision_no
          LEFT JOIN payroll_annual_settlement_requests request
                 ON request.supplier_id = person.supplier_id
                AND request.employee_id = person.employee_id
                AND request.tax_year = YEAR(person.period_start)
              WHERE person.supplier_id = ?
                AND person.status = "calculated"
                AND person.period_start >= ?
                AND person.period_start < ?
                AND (request.id IS NULL OR request.request_status = "unknown")
              GROUP BY YEAR(person.period_start)'
        );
        $statement->execute([
            $supplierId,
            sprintf('%04d-01-01', min($years)),
            sprintf('%04d-01-01', max($years) + 1),
        ]);
        $counts = [];
        foreach ($this->rows($statement) as $row) {
            $year = (int) $row['tax_year'];
            if (in_array($year, $years, true)) {
                $counts[$year] = (int) $row['undecided'];
            }
        }

        return $counts;
    }

    /**
     * Povolení cizince (k pobytu, k zaměstnání), jehož platnost končí v okně
     * a nemá nástupce. Nástupcem je i povolení téhož druhu zapsané později
     * bez výslovné vazby — dohled se ptá, jestli má člověk po konci platnosti
     * dál čím pracovat, ne jak bylo zapsané. Jen lidé s trvajícím vztahem.
     *
     * @return list<array{permit_id:int,employee_id:int,full_name:string,permit_kind:string,permit_label:string,valid_until:string}>
     */
    public function foreignPermitExpiries(int $supplierId, string $from, string $to): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT permit.id AS permit_id, permit.employee_id, employee.full_name,
                    permit.permit_kind, permit.permit_label, permit.valid_until
               FROM payroll_person_foreign_permits permit
               JOIN payroll_employees employee
                 ON employee.supplier_id = permit.supplier_id
                AND employee.id = permit.employee_id
              WHERE permit.supplier_id = ?
                AND permit.valid_until IS NOT NULL
                AND permit.valid_until BETWEEN ? AND ?
                AND NOT EXISTS (
                    SELECT 1 FROM payroll_person_foreign_permits newer
                     WHERE newer.supplier_id = permit.supplier_id
                       AND newer.employee_id = permit.employee_id
                       AND newer.permit_kind = permit.permit_kind
                       AND newer.id <> permit.id
                       AND (newer.supersedes_permit_id = permit.id
                            OR newer.effective_from > permit.effective_from)
                )
                AND EXISTS (
                    SELECT 1 FROM payroll_employments employment
                     WHERE employment.supplier_id = permit.supplier_id
                       AND employment.employee_id = permit.employee_id
                       AND employment.status IN ("planned", "preregistered", "active", "suspended")
                       AND (employment.end_date IS NULL OR employment.end_date >= permit.valid_until)
                )
              ORDER BY permit.valid_until, permit.id'
        );
        $statement->execute([$supplierId, $from, $to]);

        return array_map(static fn (array $row): array => [
            'permit_id' => (int) $row['permit_id'],
            'employee_id' => (int) $row['employee_id'],
            'full_name' => (string) $row['full_name'],
            'permit_kind' => (string) $row['permit_kind'],
            'permit_label' => (string) $row['permit_label'],
            'valid_until' => (string) $row['valid_until'],
        ], $this->rows($statement));
    }

    /**
     * Nevyřízené žádosti o potvrzení § 38j s termínem v okně. Pravidlo
     * vyřízení drží {@see PayrollTaxableIncomeConfirmationRequestRepository}.
     *
     * @return list<array{request_id:int,employee_id:int,employment_id:?int,full_name:string,requested_on:string,income_year:int,due_on:string}>
     */
    public function taxableIncomeRequestDeadlines(int $supplierId, string $from, string $to): array
    {
        return (new PayrollTaxableIncomeConfirmationRequestRepository($this->db))
            ->openDeadlines($supplierId, $from, $to);
    }

    /**
     * Nevyřízené výzvy a žádosti v důchodovém pojištění s termínem v okně.
     * Pravidlo vyřízení drží {@see PayrollPensionRequestRepository}.
     *
     * @return list<array{request_id:int,employee_id:int,employment_id:?int,full_name:string,request_kind:string,legacy_kind:?string,requester:string,received_on:string,period_year:?int,period_from:?string,due_on:string,deadline_rule:string,legal_basis:string}>
     */
    public function pensionRequestDeadlines(int $supplierId, string $from, string $to): array
    {
        return (new PayrollPensionRequestRepository($this->db))
            ->openDeadlines($supplierId, $from, $to);
    }

    /**
     * Rozpracované (nevyúčtované) pracovní cesty, které skončily nejdřív
     * `$arrivedFrom` — podklad lhůt § 183 odst. 1 ZP. Termín se dopočítá
     * v pracovních dnech, proto se okno zužuje až v PHP; dolní mez je jen
     * pojistka proti neomezenému čtení staré historie.
     *
     * @return list<array{trip_id:int,employee_id:int,employment_id:int,full_name:string,arrival_at_utc:string,timezone_name:string,documents_submitted_on:?string,destination_place:string,settlement_period:string}>
     */
    public function openBusinessTrips(int $supplierId, string $arrivedFrom): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT trip.id AS trip_id, trip.employee_id, trip.employment_id, employee.full_name,
                    trip.arrival_at_utc, trip.timezone_name, trip.documents_submitted_on,
                    trip.destination_place, trip.settlement_period_start
               FROM payroll_business_trips trip
               JOIN payroll_employees employee
                 ON employee.supplier_id = trip.supplier_id
                AND employee.id = trip.employee_id
              WHERE trip.supplier_id = ?
                AND trip.status = "draft"
                AND trip.arrival_at_utc >= ?
              ORDER BY trip.arrival_at_utc, trip.id'
        );
        $statement->execute([$supplierId, $arrivedFrom . ' 00:00:00']);

        return array_map(static fn (array $row): array => [
            'trip_id' => (int) $row['trip_id'],
            'employee_id' => (int) $row['employee_id'],
            'employment_id' => (int) $row['employment_id'],
            'full_name' => (string) $row['full_name'],
            'arrival_at_utc' => (string) $row['arrival_at_utc'],
            'timezone_name' => (string) $row['timezone_name'],
            'documents_submitted_on' => $row['documents_submitted_on'] === null
                ? null
                : (string) $row['documents_submitted_on'],
            'destination_place' => (string) $row['destination_place'],
            'settlement_period' => substr((string) $row['settlement_period_start'], 0, 7),
        ], $this->rows($statement));
    }

    private function rows(\PDOStatement $statement): array
    {
        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }
}
