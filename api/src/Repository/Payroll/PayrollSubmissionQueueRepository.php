<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine;
use PDO;

/**
 * Fronta odchozích mzdových podání — všechno připravené a neodeslané, napříč
 * agendami i zaměstnanci, v jednom dotazu.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Proč to není další režim `PayrollSubmissionRepository::listOverview()`
 * ═══════════════════════════════════════════════════════════════════════════
 * Přehled podání je OBDOBÍ-CENTRICKÝ: povinný parametr `period` a jedna
 * skupina agend na panel. To je správně pro otázku „jak jsme na tom za
 * srpen", ale odpověď na „co mám rozděláno a ještě neodešlo" tím zmizí —
 * podání po lhůtě je typicky ze STARŠÍHO období, než které má účetní zrovna
 * nastavené, takže ho v přehledu neuvidí. Fronta proto období ignoruje úplně
 * a řadí podle LHŮTY.
 *
 * Druhý důvod je zrnitost: přehled je řádek na POVINNOST (a podání je jen
 * jeho poslední stav), fronta je řádek na PODÁNÍ — protože odesílá se podání.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * Které stavy fronta bere
 * ═══════════════════════════════════════════════════════════════════════════
 * `validated`, `prepared` a `ready`. Odeslat jde jen `ready` (zmrazené),
 * ale ostatní dvě se ukazují taky a s důvodem: `prepared` je stav, ve kterém
 * končí ELDP, a kdyby fronta brala jen `ready`, účetní by připravený
 * evidenční list nikde neviděla a myslela si, že o něm aplikace neví.
 * `draft` se nebere — rozdělaná práce ve formuláři není podání k odeslání.
 *
 * K tomu stavy, ze kterých se podání smí VRÁTIT k odeslání
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionStateMachine::REOPENABLE_STATUSES}).
 * Odeslané podání čekající na odpověď úřadu do fronty na první pohled nepatří —
 * jenže právě tam končí ta podání, u kterých odpověď nikdy nepřijde: ČSSZ
 * zprávu převezme a zpracovat ji odmítne, takže věc dál vypadá jako „čeká se",
 * ale nečeká se na nic. Rozeznat to od normálního čekání aplikace neumí a
 * předstírat, že umí, by bylo horší než to ukázat: účetní vidí odpověď úřadu
 * i tlačítko, kterým pokus zahodí a podá znovu. Odeslat se z těchhle stavů
 * pochopitelně nedá, brána je blokuje s vlastní větou
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollDispatchGate::blockedReason()}).
 *
 * Vyhodnocení, jestli konkrétní řádek odeslat JDE, nepatří sem: závisí na
 * katalogu odesílatelnosti a prostředí, ne na SQL. Dělá ho
 * {@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionQueueService}.
 */
final class PayrollSubmissionQueueRepository
{
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /** @var list<string> */
    public const QUEUED_STATUSES = ['validated', 'prepared', 'ready'];

    /**
     * Stavy, které fronta ukazuje jen proto, aby z nich VEDLA CESTA VEN.
     *
     * Drží se schválně odděleně od {@see self::QUEUED_STATUSES}: tamty stavy
     * znamenají „ještě to neodešlo", tyhle „odešlo, ale nedopadlo to". Kdyby
     * splynuly do jednoho seznamu, první pravidlo brány by uvízlému podání
     * poradilo dokončit přípravu v agendě — což je rada do zdi.
     *
     * @var list<string>
     */
    public const REOPENABLE_STATUSES =
        PayrollSubmissionStateMachine::REOPENABLE_STATUSES;

    /** @var list<string> */
    public const LISTED_STATUSES = [
        ...self::QUEUED_STATUSES,
        ...self::REOPENABLE_STATUSES,
    ];

    /**
     * Tentýž seznam pro `IN (...)`.
     *
     * Je to RUČNÍ KOPIE {@see self::LISTED_STATUSES}, protože konstantní výraz
     * v PHP neumí zavolat `implode()` a dotaz je konstanta. Aby se ty dvě verze
     * nerozešly, hlídá jejich shodu
     * {@see \MyInvoice\Tests\Architecture\PayrollSubmissionQueueStatusSqlTest}.
     */
    public const LISTED_STATUSES_SQL =
        '"validated","prepared","ready",'
        . '"submitted","processing","waiting_for_identity","rejected"';

    /**
     * Ruční kopie {@see self::QUEUED_STATUSES} ze stejného důvodu; shodu hlídá
     * tentýž architektonický test.
     */
    public const QUEUED_STATUSES_SQL = '"validated","prepared","ready"';

    /**
     * Podání, u kterého je doručení DOLOŽENÉ, do fronty k odeslání nepatří.
     *
     * Fronta ukazuje odeslaná podání jen proto, aby z nich vedla cesta ven —
     * zahodit pokus a podat znovu. Jenže zprávu, kterou datová schránka
     * doručila, zahodit nelze: druhé podání téhož by u úřadu založilo
     * duplicitu, kterou nejde vzít zpět. Řádek tedy nenabízí žádnou akci
     * a ve frontě „K odeslání" jen mate — přesně to se stalo u přehledu VZP
     * za 08/2026, který tam seděl odeslaný, s doručenkou, a nabízel „Zahodit
     * a podat znovu".
     *
     * Podmínka je ZRCADLO {@see \MyInvoice\Service\Payroll\Submission\PayrollSubmissionDeliveryProof::reason()};
     * shodu hlídá `PayrollSubmissionQueueStatusSqlTest`. Rozejít se nesmí:
     * v SQL navíc znamená řádek, který zmizel, aniž by k němu vedla akce;
     * v PHP navíc znamená řádek s tlačítkem, které server odmítne.
     */
    private const DELIVERY_PROVEN_SQL =
        'outbox.dispatch_state = "delivered"
              OR outbox.receipt_document_id IS NOT NULL
              OR outbox.acceptance_state = "accepted"';

    private const FROM = '
          FROM payroll_submissions submission
          JOIN payroll_obligations obligation
            ON obligation.supplier_id = submission.supplier_id
           AND obligation.environment = submission.environment
           AND obligation.id = submission.obligation_id
          JOIN payroll_submission_deadlines deadline
            ON deadline.supplier_id = obligation.supplier_id
           AND deadline.environment = obligation.environment
           AND deadline.obligation_id = obligation.id
           AND deadline.deadline_kind = "regular"
          -- Poslední pokus o odeslání. `failed` BEZ `sent_at` se ZAPOČÍTÁVÁ
          -- (na rozdíl od `listReadySubmissions`, kde se přeskakuje): fronta
          -- ho musí UKÁZAT i s chybovou hláškou, protože „odeslání selhalo"
          -- je přesně ta informace, kvůli které se sem účetní dívá. To, že
          -- takový pokus dalšímu odeslání NEBRÁNÍ, rozhoduje až služba.
          LEFT JOIN (
               SELECT ranked.*
                 FROM (
                      SELECT attempt.id, attempt.supplier_id,
                             attempt.environment, attempt.submission_id,
                             attempt.attempt_no, attempt.status,
                             attempt.channel, attempt.error_code,
                             attempt.error_message, attempt.sent_at,
                             attempt.correlation_reference,
                             ROW_NUMBER() OVER (
                                 PARTITION BY attempt.supplier_id,
                                              attempt.environment,
                                              attempt.submission_id
                                 ORDER BY attempt.attempt_no DESC,
                                          attempt.id DESC
                             ) AS row_rank
                        FROM payroll_submission_transport_attempts attempt
                       WHERE attempt.supplier_id = ?
                         AND attempt.environment = ?
                 ) ranked
                WHERE ranked.row_rank = 1
          ) attempt
            ON attempt.supplier_id = submission.supplier_id
           AND attempt.environment = submission.environment
           AND attempt.submission_id = submission.id
          -- Zařazení do odchozí fronty datové schránky. Bez něj by u agend
          -- na ISDS fronta pořád nabízela „Zařadit", i když už zařazené je.
          LEFT JOIN submission_outbox outbox
            ON outbox.id = (
               SELECT MAX(candidate.id)
                 FROM submission_outbox candidate
                 JOIN payroll_submission_artifacts queued_artifact
                   ON queued_artifact.supplier_id = candidate.supplier_id
                  AND queued_artifact.environment = candidate.environment
                  AND queued_artifact.id = candidate.artifact_id
                  AND queued_artifact.submission_id = submission.id
                WHERE candidate.supplier_id = submission.supplier_id
                  AND candidate.environment = submission.environment
                  AND candidate.channel = "isds"
                  AND candidate.artifact_kind = "payroll_submission"
            )
         WHERE submission.supplier_id = ?
           AND submission.environment = ?
           AND submission.status IN ('
        . self::LISTED_STATUSES_SQL . ')
           -- Neodeslaná podání zůstávají vždycky; odeslaná jen dokud z nich
           -- vede cesta ven (viz DELIVERY_PROVEN_SQL).
           AND (
                submission.status IN (' . self::QUEUED_STATUSES_SQL . ')
             OR outbox.id IS NULL
             OR NOT (' . self::DELIVERY_PROVEN_SQL . ')
           )
           -- A stejně tak: uzavřená povinnost už nic nedluží. Odeslané podání
           -- pod ní nemá kam vést — zahodit a podat znovu by znamenalo znovu
           -- otevřít měsíc, který je hotový. Připravená podání se NEVYNECHÁVAJÍ
           -- ani tady: oprava k uzavřené povinnosti se odesílá pořád odtud.
           AND (
                submission.status IN (' . self::QUEUED_STATUSES_SQL . ')
             OR obligation.status NOT IN ("fulfilled", "cancelled")
           )';

    /**
     * Řazení. Výchozí je podle lhůty — fronta se čte shora dolů a co je
     * nejblíž lhůtě, se řeší dřív. Řazení podle agendy je pro dávku: účetní
     * s denními změnami chce vidět všechny registrace pohromadě a odeslat je
     * jedním úkonem.
     *
     * Sloupce jsou z WHITELISTU, ne z požadavku — do `ORDER BY` se nedá
     * parametrizovat a slepené jméno sloupce je SQL injection.
     *
     * @var array<string,string>
     */
    private const ORDERS = [
        'due' => '
         ORDER BY deadline.due_on ASC,
                  obligation.agenda_code ASC,
                  submission.id ASC',
        'agenda' => '
         ORDER BY obligation.agenda_code ASC,
                  deadline.due_on ASC,
                  submission.id ASC',
    ];

    /** @var list<string> */
    public const SORTS = ['due', 'agenda'];

    public function __construct(private readonly Connection $db) {}

    /**
     * @param string|null $agendaCode přesný kód agendy, nebo `null` = všechny
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listQueue(
        int $supplierId,
        string $environment,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?string $agendaCode = null,
        string $sort = 'due',
    ): array {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $filter = $agendaCode === null ? '' : ' AND obligation.agenda_code = ?';
        $params = [
            $supplierId,
            $environment,
            $supplierId,
            $environment,
        ];
        if ($agendaCode !== null) {
            $params[] = $agendaCode;
        }

        $countStatement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)' . self::FROM . $filter,
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->db->pdo()->prepare(
            self::SELECT_COLUMNS
            . self::FROM
            . $filter
            . (self::ORDERS[$sort] ?? self::ORDERS['due'])
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        $statement->execute($params);

        $items = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = self::mapRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Které agendy ve frontě vůbec jsou, i s počty — podklad pro filtr.
     *
     * Počítá se nad CELOU frontou, ne nad stránkou: filtr, který nabízí jen
     * to, co je zrovna vidět, je při stovce položek k ničemu.
     *
     * @return list<array{agenda_code:string,count:int}>
     */
    public function agendaFacets(int $supplierId, string $environment): array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.agenda_code, COUNT(*) AS row_count'
            . self::FROM
            . ' GROUP BY obligation.agenda_code
                ORDER BY obligation.agenda_code ASC',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $supplierId,
            $environment,
        ]);

        $facets = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $facets[] = [
                    'agenda_code' => (string) $row['agenda_code'],
                    'count' => (int) $row['row_count'],
                ];
            }
        }

        return $facets;
    }

    /**
     * Jeden řádek fronty podle podání.
     *
     * Odeslání si stav MUSÍ přečíst znovu — mezi zobrazením a kliknutím ho
     * mohla změnit jiná obrazovka, jiný uživatel nebo cron. Procházet kvůli
     * tomu stránkovaný seznam by u delší fronty tiše nenašlo řádek za dvoustým
     * místem a odeslání by spadlo na „už tu není", i když tam je.
     *
     * @return array<string,mixed>|null
     */
    public function findQueued(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            self::SELECT_COLUMNS
            . self::FROM
            . ' AND submission.id = ?',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $supplierId,
            $environment,
            $submissionId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::mapRow($row) : null;
    }

    private const SELECT_COLUMNS =
            'SELECT submission.id AS submission_id,
                    submission.status AS submission_status,
                    -- Verze podání jde ven kvůli optimistickému zámku u zahození
                    -- rozdělaného odeslání: bez ní by šlo zahodit podání, které se
                    -- mezitím pohnulo (třeba právě dotažený protokol).
                    submission.row_version AS submission_row_version,
                    submission.submission_kind,
                    submission.channel AS submission_channel,
                    submission.created_at AS submission_created_at,
                    submission.corrects_submission_id,
                    obligation.id AS obligation_id,
                    obligation.agenda_code,
                    obligation.subject_type,
                    obligation.subject_reference,
                    obligation.period_start,
                    obligation.period_end,
                    obligation.obligation_kind,
                    obligation.status AS obligation_status,
                    deadline.earliest_submission_on,
                    deadline.due_on,
                    attempt.id AS attempt_id,
                    attempt.attempt_no,
                    attempt.status AS attempt_status,
                    attempt.channel AS attempt_channel,
                    attempt.error_code AS attempt_error_code,
                    attempt.error_message AS attempt_error_message,
                    attempt.sent_at AS attempt_sent_at,
                    attempt.correlation_reference AS attempt_correlation,
                    outbox.id AS outbox_id,
                    outbox.dispatch_state AS outbox_dispatch_state,
                    outbox.acceptance_state AS outbox_acceptance_state,
                    outbox.correlation_reference AS outbox_correlation';

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function mapRow(array $row): array
    {
        return [
                'submission_id' => (int) $row['submission_id'],
                'submission_status' => (string) $row['submission_status'],
                'submission_row_version' => (int) $row['submission_row_version'],
                'submission_kind' => (string) $row['submission_kind'],
                'submission_channel' => (string) $row['submission_channel'],
                'created_at' => (string) $row['submission_created_at'],
                'corrects_submission_id' => self::nullableInt(
                    $row['corrects_submission_id'],
                ),
                'obligation_id' => (int) $row['obligation_id'],
                'agenda_code' => (string) $row['agenda_code'],
                'subject_type' => (string) $row['subject_type'],
                'subject_reference' => (string) $row['subject_reference'],
                'period_start' => (string) $row['period_start'],
                'period_end' => (string) $row['period_end'],
                'obligation_kind' => (string) $row['obligation_kind'],
                'obligation_status' => (string) $row['obligation_status'],
                'earliest_submission_on' => (string) $row['earliest_submission_on'],
                'due_on' => (string) $row['due_on'],
                'attempt' => $row['attempt_id'] === null ? null : [
                    'id' => (int) $row['attempt_id'],
                    'attempt_no' => (int) $row['attempt_no'],
                    'status' => (string) $row['attempt_status'],
                    'channel' => (string) $row['attempt_channel'],
                    'error_code' => self::nullableString($row['attempt_error_code']),
                    'error_message' => self::nullableString(
                        $row['attempt_error_message'],
                    ),
                    'sent_at' => self::nullableString($row['attempt_sent_at']),
                    'correlation_reference' => self::nullableString(
                        $row['attempt_correlation'],
                    ),
                ],
                'outbox' => $row['outbox_id'] === null ? null : [
                    'id' => (int) $row['outbox_id'],
                    'dispatch_state' => (string) $row['outbox_dispatch_state'],
                    'acceptance_state' => (string) $row['outbox_acceptance_state'],
                    'correlation_reference' => self::nullableString(
                        $row['outbox_correlation'],
                    ),
                ],
        ];
    }

    /**
     * Nevyřešené blokující nedostatky podání. Fronta je nesmí spočítat sama
     * z `items`: chce je pro zobrazenou STRÁNKU, ne pro celou tabulku.
     *
     * @param list<int> $submissionIds
     * @return array<int,int> `submission_id` → počet
     */
    public function blockingIssueCounts(
        int $supplierId,
        string $environment,
        array $submissionIds,
    ): array {
        $submissionIds = array_values(array_unique(array_filter(
            $submissionIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($submissionIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT submission_id, COUNT(*) AS open_count
               FROM payroll_submission_issues
              WHERE supplier_id = ?
                AND environment = ?
                AND is_resolved = 0
                AND severity IN ("blocker", "error")
                AND submission_id IN (' . $placeholders . ')
              GROUP BY submission_id',
        );
        $statement->execute([$supplierId, $environment, ...$submissionIds]);

        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $counts[(int) $row['submission_id']] = (int) $row['open_count'];
            }
        }

        return $counts;
    }

    /**
     * Jména zaměstnanců k `subject_reference` tvaru `employment:{id}`.
     *
     * Tohle je hlavní důvod, proč fronta existuje: u registrací a ELDP je
     * předmětem povinnosti pracovní VZTAH a bez jména by účetní musela každý
     * řádek rozklikávat, aby zjistila, koho se týká.
     * {@see \MyInvoice\Service\Payroll\Submission\PayrollObligationSubjectFormatter}
     * to schválně neumí — pracuje nad daty, která jméno nemají; tady se
     * dohledává dotazem.
     *
     * @param list<int> $employmentIds
     * @return array<int,string> `employment_id` → jméno
     */
    public function employmentNames(int $supplierId, array $employmentIds): array
    {
        $employmentIds = array_values(array_unique(array_filter(
            $employmentIds,
            static fn (int $id): bool => $id > 0,
        )));
        if ($employmentIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($employmentIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT employment.id, employee.full_name
               FROM payroll_employments employment
               JOIN payroll_employees employee
                 ON employee.supplier_id = employment.supplier_id
                AND employee.id = employment.employee_id
              WHERE employment.supplier_id = ?
                AND employment.id IN (' . $placeholders . ')',
        );
        $statement->execute([$supplierId, ...$employmentIds]);

        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) $row['full_name']);
            if ($name !== '') {
                $names[(int) $row['id']] = $name;
            }
        }

        return $names;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
