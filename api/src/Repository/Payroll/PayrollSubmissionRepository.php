<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Payroll;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Payroll\PayrollYearCloseGuard;
use MyInvoice\Service\Payroll\Submission\PayrollAgendaGroupCatalog;
use PDO;

final class PayrollSubmissionRepository
{
    /**
     * Tvrdý strop stránky přehledu podání. Řádek je jedna povinnost období,
     * takže jich přibývá s počtem agend a pracovišť.
     */
    public const LIST_MAX_LIMIT = 200;

    public const LIST_DEFAULT_LIMIT = 50;

    /**
     * Zařazení agendy do skupiny, kterou ukazuje jeden panel.
     *
     * Klasifikace patří na SERVER, ne na frontend: přehled se stránkuje na
     * serveru, takže kdyby si panel skupinu filtroval až z přijaté stránky,
     * pager by počítal řádky obou agend a tabulka ukazovala jen některé —
     * čísla pod sebou by si odporovala.
     *
     * Samotný předpis ale nepatří ani sem: dřív to byl ručně psaný REGEXP,
     * který se rozešel s konstantami `AGENDA_CODE` (neuměl ročník v kódu).
     * Výraz proto staví {@see PayrollAgendaGroupCatalog} z týchž konstant,
     * které kódy zapisují.
     */
    private static function agendaGroupSql(): string
    {
        return PayrollAgendaGroupCatalog::sqlExpression(
            'obligation.agenda_code',
        );
    }

    /** @var list<string> */
    public const AGENDA_GROUPS = PayrollAgendaGroupCatalog::GROUPS;

    private const OVERVIEW_FROM =
        ' FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
               LEFT JOIN (
                    SELECT ranked.*
                      FROM (
                           SELECT submission.id, submission.supplier_id,
                                  submission.environment,
                                  submission.obligation_id,
                                  submission.status,
                                  submission.submission_kind,
                                  submission.channel,
                                  submission.submitted_at,
                                  submission.decided_at,
                                  ROW_NUMBER() OVER (
                                      PARTITION BY submission.supplier_id,
                                                   submission.environment,
                                                   submission.obligation_id
                                      ORDER BY submission.created_at DESC,
                                               submission.id DESC
                                  ) AS row_rank
                             FROM payroll_submissions submission
                            WHERE submission.supplier_id = ?
                              AND submission.environment = ?
                      ) ranked
                     WHERE ranked.row_rank = 1
               ) latest_submission
                 ON latest_submission.supplier_id = obligation.supplier_id
                AND latest_submission.environment = obligation.environment
                AND latest_submission.obligation_id = obligation.id
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.period_start <= ?
                AND obligation.period_end >= ?';

    private const OVERVIEW_ORDER =
        ' ORDER BY deadline.due_on ASC,
                       obligation.agenda_code ASC,
                       obligation.id ASC';

    private int $savepointSequence = 0;
    private readonly PayrollYearCloseGuard $yearClose;

    public function __construct(private readonly Connection $db)
    {
        $this->yearClose = new PayrollYearCloseGuard($db);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = null;
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $savepoint = 'payroll_submission_' . ++$this->savepointSequence;
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            } elseif ($savepoint !== null) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            } elseif ($savepoint !== null) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    public function lockSupplier(int $supplierId): bool
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id FROM supplier WHERE id = ? FOR UPDATE',
        );
        $statement->execute([$supplierId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Stránka přehledu podání i s celkovým počtem.
     *
     * Dřív měl dotaz natvrdo `LIMIT 200` a odpověď hlásila jako `total` počet
     * řádků PO oříznutí. Číslo tedy vypadalo jako pravda, ale bylo to jen
     * „kolik se vešlo" — a povinnosti za hranicí nešlo ani spočítat, ani
     * zobrazit.
     *
     * @return array{items:list<array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   obligation_kind:string,preferred_channel:string,status:string,
     *   row_version:int,agenda_group:string,
     *   earliest_submission_on:string,due_on:string,
     *   calendar_basis:string,latest_submission:?array{
     *     id:int,status:string,submission_kind:string,channel:string,
     *     submitted_at:?string,decided_at:?string
     *   }
     * }>,total:int}
     */
    public function listOverview(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
        ?string $agendaGroup = null,
    ): array {
        // Strop se klampuje i tady, ne jen na HTTP hranici.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        $filter = self::agendaFilter($agendaGroup);
        $params = self::overviewParams(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            $agendaGroup,
        );

        $countStatement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)' . self::OVERVIEW_FROM . $filter,
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();

        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.environment,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end, obligation.obligation_kind,
                    obligation.preferred_channel, obligation.status,
                    obligation.row_version,
                    ' . self::agendaGroupSql() . ' AS agenda_group,
                    deadline.earliest_submission_on, deadline.due_on,
                    deadline.calendar_basis,
                    latest_submission.id AS submission_id,
                    latest_submission.status AS submission_status,
                    latest_submission.submission_kind,
                    latest_submission.channel AS submission_channel,
                    latest_submission.submitted_at,
                    latest_submission.decided_at'
            . self::OVERVIEW_FROM
            . $filter
            . self::OVERVIEW_ORDER
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
        );
        $statement->execute($params);

        $result = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'přehled mzdových podání');
            $submissionId = self::nullableInteger($row, 'submission_id');
            $result[] = [
                'id' => self::integer($row, 'id'),
                'environment' => self::string($row, 'environment'),
                'agenda_code' => self::string($row, 'agenda_code'),
                'subject_type' => self::string($row, 'subject_type'),
                'subject_reference' => self::string(
                    $row,
                    'subject_reference',
                ),
                'period_start' => self::string($row, 'period_start'),
                'period_end' => self::string($row, 'period_end'),
                'obligation_kind' => self::string($row, 'obligation_kind'),
                'preferred_channel' => self::string(
                    $row,
                    'preferred_channel',
                ),
                'status' => self::string($row, 'status'),
                'row_version' => self::integer($row, 'row_version'),
                'agenda_group' => self::string($row, 'agenda_group'),
                'earliest_submission_on' => self::string(
                    $row,
                    'earliest_submission_on',
                ),
                'due_on' => self::string($row, 'due_on'),
                'calendar_basis' => self::string($row, 'calendar_basis'),
                'latest_submission' => $submissionId === null
                    ? null
                    : [
                        'id' => $submissionId,
                        'status' => self::string($row, 'submission_status'),
                        'submission_kind' => self::string(
                            $row,
                            'submission_kind',
                        ),
                        'channel' => self::string(
                            $row,
                            'submission_channel',
                        ),
                        'submitted_at' => self::nullableString(
                            $row,
                            'submitted_at',
                        ),
                        'decided_at' => self::nullableString(
                            $row,
                            'decided_at',
                        ),
                    ],
            ];
        }

        return ['items' => $result, 'total' => $total];
    }

    /**
     * Lehká projekce CELÉHO filtrovaného rozsahu pro souhrny.
     *
     * Souhrny nad stránkou by lhaly stejně jako dřívější `total`: „kolik toho
     * je po termínu" nesmí záviset na tom, kde je uživatel v seznamu. Tenhle
     * dotaz proto stránku ignoruje, ale nese jen čtyři pole, která posouzení
     * termínu potřebuje — ne celý zobrazovaný řádek.
     *
     * @return list<array{
     *   status:string,earliest_submission_on:string,due_on:string,
     *   submission_status:?string
     * }>
     */
    public function overviewSummaryRows(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
        ?string $agendaGroup = null,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.status,
                    deadline.earliest_submission_on, deadline.due_on,
                    latest_submission.status AS submission_status'
            . self::OVERVIEW_FROM
            . self::agendaFilter($agendaGroup),
        );
        $statement->execute(self::overviewParams(
            $supplierId,
            $environment,
            $periodStart,
            $periodEnd,
            $agendaGroup,
        ));

        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $row = self::associativeRow($row, 'souhrn mzdových podání');
            $rows[] = [
                'status' => self::string($row, 'status'),
                'earliest_submission_on' => self::string(
                    $row,
                    'earliest_submission_on',
                ),
                'due_on' => self::string($row, 'due_on'),
                'submission_status' => self::nullableString(
                    $row,
                    'submission_status',
                ),
            ];
        }

        return $rows;
    }

    private static function agendaFilter(?string $agendaGroup): string
    {
        return $agendaGroup === null
            ? ''
            : ' AND ' . self::agendaGroupSql() . ' = ?';
    }

    /** @return list<string> */
    private static function overviewParams(
        int $supplierId,
        string $environment,
        string $periodStart,
        string $periodEnd,
        ?string $agendaGroup,
    ): array {
        $params = [
            (string) $supplierId,
            $environment,
            (string) $supplierId,
            $environment,
            $periodEnd,
            $periodStart,
        ];
        if ($agendaGroup !== null) {
            $params[] = $agendaGroup;
        }

        return $params;
    }

    /**
     * @return array{
     *   id:int,due_on:string,status:string,row_version:int,
     *   request_fingerprint:string
     * }|null
     */
    public function findObligationByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.status,
                    obligation.row_version, obligation.request_fingerprint,
                    deadline.due_on
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'mzdovou povinnost');

        return [
            'id' => self::integer($row, 'id'),
            'due_on' => self::string($row, 'due_on'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
        ];
    }

    /**
     * @param list<string> $sourceReferences
     * @return array<string,array{
     *   id:int,source_event_hash:string,status:string,duplicate_count:int,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,obligation_kind:string,preferred_channel:string,
     *   request_fingerprint:string,idempotency_key_hash:string,
     *   earliest_submission_on:?string,
     *   due_on:?string,calendar_basis:?string,ruleset_id:?string,
     *   ruleset_hash:?string,trigger_event_hash:?string
     * }>
     */
    public function obligationStatesBySourceReferences(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $sourceEventType,
        array $sourceReferences,
    ): array {
        $result = [];
        foreach (array_chunk(array_values(array_unique($sourceReferences)), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->db->pdo()->prepare(
                'SELECT o.id, o.source_event_reference, o.source_event_hash,
                        o.status, o.subject_type, o.subject_reference,
                        o.period_start, o.period_end, o.obligation_kind,
                        o.preferred_channel, o.request_fingerprint,
                        o.idempotency_key_hash,
                        d.earliest_submission_on, d.due_on,
                        d.calendar_basis, d.ruleset_id, d.ruleset_hash,
                        d.trigger_event_hash
                   FROM payroll_obligations o
                   LEFT JOIN payroll_submission_deadlines d
                     ON d.supplier_id = o.supplier_id
                    AND d.environment = o.environment
                    AND d.obligation_id = o.id
                    AND d.deadline_kind = "regular"
                  WHERE o.supplier_id = ?
                    AND o.environment = ?
                    AND o.agenda_code = ?
                    AND o.source_event_type = ?
                    AND o.source_event_reference IN (' . $placeholders . ')
                  ORDER BY o.id DESC',
            );
            $statement->execute([
                $supplierId,
                $environment,
                $agendaCode,
                $sourceEventType,
                ...$chunk,
            ]);
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $reference = (string) $row['source_event_reference'];
                if (isset($result[$reference])) {
                    $result[$reference]['duplicate_count']++;
                    continue;
                }
                $result[$reference] = [
                    'id' => (int) $row['id'],
                    'source_event_hash' => (string) $row['source_event_hash'],
                    'status' => (string) $row['status'],
                    'duplicate_count' => 1,
                    'subject_type' => (string) $row['subject_type'],
                    'subject_reference' => (string) $row['subject_reference'],
                    'period_start' => (string) $row['period_start'],
                    'period_end' => (string) $row['period_end'],
                    'obligation_kind' => (string) $row['obligation_kind'],
                    'preferred_channel' => (string) $row['preferred_channel'],
                    'request_fingerprint' => (string) $row['request_fingerprint'],
                    'idempotency_key_hash' => (string) $row['idempotency_key_hash'],
                    'earliest_submission_on' => $row['earliest_submission_on'] === null
                        ? null : (string) $row['earliest_submission_on'],
                    'due_on' => $row['due_on'] === null
                        ? null : (string) $row['due_on'],
                    'calendar_basis' => $row['calendar_basis'] === null
                        ? null : (string) $row['calendar_basis'],
                    'ruleset_id' => $row['ruleset_id'] === null
                        ? null : (string) $row['ruleset_id'],
                    'ruleset_hash' => $row['ruleset_hash'] === null
                        ? null : (string) $row['ruleset_hash'],
                    'trigger_event_hash' => $row['trigger_event_hash'] === null
                        ? null : (string) $row['trigger_event_hash'],
                ];
            }
        }

        return $result;
    }

    public function insertObligation(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $subjectType,
        string $subjectReference,
        string $periodStart,
        string $periodEnd,
        string $obligationKind,
        string $channel,
        string $sourceEventType,
        string $sourceEventReference,
        string $sourceEventHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        ?int $responsibleUserId,
        ?int $createdBy,
    ): int {
        return $this->transaction(function () use (
            $supplierId,
            $environment,
            $agendaCode,
            $subjectType,
            $subjectReference,
            $periodStart,
            $periodEnd,
            $obligationKind,
            $channel,
            $responsibleUserId,
            $sourceEventType,
            $sourceEventReference,
            $sourceEventHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $createdBy,
        ): int {
            if ($environment === 'production') {
                $this->yearClose->assertOpenForDateRange($supplierId, $periodStart, $periodEnd);
            }
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_obligations
                    (supplier_id, environment, agenda_code, subject_type,
                     subject_reference, period_start, period_end,
                     obligation_kind, preferred_channel,
                     responsible_user_id, source_event_type,
                     source_event_reference, source_event_hash,
                     request_fingerprint, idempotency_key_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $environment,
                $agendaCode,
                $subjectType,
                $subjectReference,
                $periodStart,
                $periodEnd,
                $obligationKind,
                $channel,
                $responsibleUserId,
                $sourceEventType,
                $sourceEventReference,
                $sourceEventHash,
                $requestFingerprint,
                $idempotencyKeyHash,
                $createdBy,
            ]);

            return (int) $this->db->pdo()->lastInsertId();
        });
    }

    public function insertDeadline(
        int $supplierId,
        string $environment,
        int $obligationId,
        string $deadlineKind,
        string $earliestSubmissionOn,
        string $dueOn,
        string $calendarBasis,
        ?int $fictionDeliveryDays,
        string $rulesetId,
        string $rulesetHash,
        string $triggerEventHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_deadlines
                (supplier_id, environment, obligation_id, deadline_kind,
                 earliest_submission_on, due_on, calendar_basis,
                 fiction_delivery_days, ruleset_id, ruleset_hash,
                 trigger_event_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $obligationId,
            $deadlineKind,
            $earliestSubmissionOn,
            $dueOn,
            $calendarBasis,
            $fictionDeliveryDays,
            $rulesetId,
            $rulesetHash,
            $triggerEventHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @param list<array{
     *   supplier_id:int,environment:string,agenda_code:string,
     *   subject_type:string,subject_reference:string,period_start:string,
     *   period_end:string,obligation_kind:string,preferred_channel:string,
     *   responsible_user_id:?int,source_event_type:string,
     *   source_event_reference:string,source_event_hash:string,
     *   request_fingerprint:string,idempotency_key_hash:string,created_by:?int
     * }> $rows
     */
    public function insertObligationsBatch(array $rows): void
    {
        $this->transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                if ($row['environment'] === 'production') {
                    $this->yearClose->assertOpenForDateRange(
                        $row['supplier_id'],
                        $row['period_start'],
                        $row['period_end'],
                    );
                }
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                if ($chunk === []) {
                    continue;
                }
                $values = [];
                $parameters = [];
                foreach ($chunk as $row) {
                    $values[] = '(' . implode(',', array_fill(0, 16, '?')) . ')';
                    array_push(
                        $parameters,
                        $row['supplier_id'],
                        $row['environment'],
                        $row['agenda_code'],
                        $row['subject_type'],
                        $row['subject_reference'],
                        $row['period_start'],
                        $row['period_end'],
                        $row['obligation_kind'],
                        $row['preferred_channel'],
                        $row['responsible_user_id'],
                        $row['source_event_type'],
                        $row['source_event_reference'],
                        $row['source_event_hash'],
                        $row['request_fingerprint'],
                        $row['idempotency_key_hash'],
                        $row['created_by'],
                    );
                }
                $statement = $this->db->pdo()->prepare(
                    'INSERT INTO payroll_obligations
                        (supplier_id, environment, agenda_code, subject_type,
                         subject_reference, period_start, period_end,
                         obligation_kind, preferred_channel,
                         responsible_user_id, source_event_type,
                         source_event_reference, source_event_hash,
                         request_fingerprint, idempotency_key_hash, created_by)
                     VALUES ' . implode(',', $values),
                );
                $statement->execute($parameters);
            }
        });
    }

    /**
     * @param list<array{
     *   supplier_id:int,environment:string,obligation_id:int,
     *   deadline_kind:string,earliest_submission_on:string,due_on:string,
     *   calendar_basis:string,fiction_delivery_days:?int,ruleset_id:string,
     *   ruleset_hash:string,trigger_event_hash:string,created_by:?int
     * }> $rows
     */
    public function insertDeadlinesBatch(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $values = [];
            $parameters = [];
            foreach ($chunk as $row) {
                $values[] = '(' . implode(',', array_fill(0, 12, '?')) . ')';
                array_push(
                    $parameters,
                    $row['supplier_id'],
                    $row['environment'],
                    $row['obligation_id'],
                    $row['deadline_kind'],
                    $row['earliest_submission_on'],
                    $row['due_on'],
                    $row['calendar_basis'],
                    $row['fiction_delivery_days'],
                    $row['ruleset_id'],
                    $row['ruleset_hash'],
                    $row['trigger_event_hash'],
                    $row['created_by'],
                );
            }
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_submission_deadlines
                    (supplier_id, environment, obligation_id, deadline_kind,
                     earliest_submission_on, due_on, calendar_basis,
                     fiction_delivery_days, ruleset_id, ruleset_hash,
                     trigger_event_hash, created_by)
                 VALUES ' . implode(',', $values),
            );
            $statement->execute($parameters);
        }
    }

    public function obligationExistsForUpdate(
        int $supplierId,
        int $obligationId,
        string $environment = 'production',
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_obligations
               WHERE supplier_id = ? AND environment = ? AND id = ?
               FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{
     *   id:int,environment:string,agenda_code:string,subject_type:string,
     *   subject_reference:string,period_start:string,period_end:string,
     *   obligation_kind:string,status:string,row_version:int,
     *   earliest_submission_on:string,due_on:string
     * }|null
     */
    public function lockObligation(
        int $supplierId,
        int $obligationId,
        string $environment,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.environment,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end, obligation.obligation_kind,
                    obligation.status, obligation.row_version,
                    deadline.earliest_submission_on, deadline.due_on
               FROM payroll_obligations obligation
               JOIN payroll_submission_deadlines deadline
                 ON deadline.supplier_id = obligation.supplier_id
                AND deadline.environment = obligation.environment
                AND deadline.obligation_id = obligation.id
                AND deadline.deadline_kind = "regular"
              WHERE obligation.supplier_id = ?
                AND obligation.environment = ?
                AND obligation.id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $obligationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'mzdovou povinnost');

        return [
            'id' => self::integer($row, 'id'),
            'environment' => self::string($row, 'environment'),
            'agenda_code' => self::string($row, 'agenda_code'),
            'subject_type' => self::string($row, 'subject_type'),
            'subject_reference' => self::string($row, 'subject_reference'),
            'period_start' => self::string($row, 'period_start'),
            'period_end' => self::string($row, 'period_end'),
            'obligation_kind' => self::string($row, 'obligation_kind'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'earliest_submission_on' => self::string(
                $row,
                'earliest_submission_on',
            ),
            'due_on' => self::string($row, 'due_on'),
        ];
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function findSubmissionByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function lockSubmission(
        int $supplierId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    /**
     * @param list<string> $statuses
     * @return array{id:int,status:string,submission_kind:string}|null
     */
    public function latestSubmissionForScopeForUpdate(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $subjectType,
        string $subjectReference,
        string $periodStart,
        string $periodEnd,
        array $statuses,
    ): ?array {
        if ($statuses === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id, submission.status,
                    submission.submission_kind
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND obligation.agenda_code = ?
                AND obligation.subject_type = ?
                AND obligation.subject_reference = ?
                AND obligation.period_start = ?
                AND obligation.period_end = ?
                AND submission.submission_kind IN (\'regular\', \'correction\')
                AND submission.status IN (' . $placeholders . ')
              ORDER BY submission.id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            $subjectType,
            $subjectReference,
            $periodStart,
            $periodEnd,
            ...$statuses,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'předchozí mzdové podání');

        return [
            'id' => self::integer($row, 'id'),
            'status' => self::string($row, 'status'),
            'submission_kind' => self::string($row, 'submission_kind'),
        ];
    }

    /**
     * @return array{id:int,submission_kind:string,corrects_submission_id:?int}|null
     */
    public function submissionForSourceEventForUpdate(
        int $supplierId,
        string $environment,
        string $agendaCode,
        string $sourceEventType,
        string $sourceEventReference,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT submission.id, submission.submission_kind,
                    submission.corrects_submission_id
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND obligation.agenda_code = ?
                AND obligation.source_event_type = ?
                AND obligation.source_event_reference = ?
              ORDER BY submission.id DESC
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $agendaCode,
            $sourceEventType,
            $sourceEventReference,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'podání zdrojové události');

        return [
            'id' => self::integer($row, 'id'),
            'submission_kind' => self::string($row, 'submission_kind'),
            'corrects_submission_id' => self::nullableInteger(
                $row,
                'corrects_submission_id',
            ),
        ];
    }

    /**
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }|null
     */
    public function findSubmission(
        int $supplierId,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version, request_fingerprint,
                    source_snapshot_hash, submission_kind, channel,
                    environment, obligation_id, corrects_submission_id,
                    correlation_reference
               FROM payroll_submissions
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? null
            : self::submissionRow(
                self::associativeRow($row, 'mzdové podání'),
            );
    }

    /** @return list<array{id:int,status:string,row_version:int}> */
    public function resolvedCorrectionsForRoot(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version
               FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ?
                AND corrects_submission_id = ?
                AND submission_kind = \'correction\'
                AND status IN (\'accepted\', \'partially_accepted\')
              ORDER BY id
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $regularSubmissionId]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'návazné opravné podání');
            $rows[] = [
                'id' => self::integer($row, 'id'),
                'status' => self::string($row, 'status'),
                'row_version' => self::integer($row, 'row_version'),
            ];
        }

        return $rows;
    }

    /** @return list<array{id:int,status:string,submission_kind:string}> */
    public function jmhzChainForRoot(
        int $supplierId,
        string $environment,
        int $regularSubmissionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, submission_kind
               FROM payroll_submissions
              WHERE supplier_id = ? AND environment = ?
                AND (id = ? OR corrects_submission_id = ?)
              ORDER BY CASE WHEN id = ? THEN 0 ELSE 1 END, id',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $regularSubmissionId,
            $regularSubmissionId,
            $regularSubmissionId,
        ]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'řetězec podání JMHZ');
            $rows[] = [
                'id' => self::integer($row, 'id'),
                'status' => self::string($row, 'status'),
                'submission_kind' => self::string($row, 'submission_kind'),
            ];
        }

        return $rows;
    }

    public function insertSubmission(
        int $supplierId,
        string $environment,
        int $obligationId,
        ?int $correctsSubmissionId,
        string $submissionKind,
        string $channel,
        ?int $sourceRevisionId,
        string $sourceSnapshotHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        return $this->transaction(function () use (
            $supplierId,
            $environment,
            $obligationId,
            $correctsSubmissionId,
            $submissionKind,
            $channel,
            $sourceRevisionId,
            $sourceSnapshotHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $createdBy,
        ): int {
            $this->yearClose->assertOpenForObligation($supplierId, $obligationId);
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_submissions
                    (supplier_id, environment, obligation_id, corrects_submission_id,
                     submission_kind, channel, source_revision_id,
                     source_snapshot_hash, request_fingerprint,
                     idempotency_key_hash, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $environment,
                $obligationId,
                $correctsSubmissionId,
                $submissionKind,
                $channel,
                $sourceRevisionId,
                $sourceSnapshotHash,
                $requestFingerprint,
                $idempotencyKeyHash,
                $createdBy,
            ]);

            return (int) $this->db->pdo()->lastInsertId();
        });
    }

    public function insertPart(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $partReference,
        string $agendaCode,
        string $subjectReference,
        string $sourceEntityType,
        string $sourceEntityReference,
        string $sourceSnapshotHash,
    ): int {
        return $this->transaction(function () use (
            $supplierId,
            $environment,
            $submissionId,
            $partReference,
            $agendaCode,
            $subjectReference,
            $sourceEntityType,
            $sourceEntityReference,
            $sourceSnapshotHash,
        ): int {
            $this->yearClose->assertOpenForSubmission($supplierId, $submissionId);
            $statement = $this->db->pdo()->prepare(
                'INSERT INTO payroll_submission_parts
                    (supplier_id, environment, submission_id, part_reference,
                     agenda_code, subject_reference, source_entity_type,
                     source_entity_reference, source_snapshot_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            );
            $statement->execute([
                $supplierId,
                $environment,
                $submissionId,
                $partReference,
                $agendaCode,
                $subjectReference,
                $sourceEntityType,
                $sourceEntityReference,
                $sourceSnapshotHash,
            ]);

            return (int) $this->db->pdo()->lastInsertId();
        });
    }

    public function partBelongsToSubmission(
        int $supplierId,
        int $submissionId,
        int $partId,
        string $environment = 'production',
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_parts
               WHERE supplier_id = ? AND environment = ?
                 AND submission_id = ? AND id = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
        ]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{id:int,status:string,row_version:int}|null */
    public function lockPart(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $partId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, status, row_version
               FROM payroll_submission_parts
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND id = ?
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'část podání');

        return [
            'id' => self::integer($row, 'id'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
        ];
    }

    public function updatePartStatus(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $partId,
        int $expectedRowVersion,
        string $status,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submission_parts
                SET status = ?, row_version = row_version + 1
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $status,
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Část podání se mezitím změnila.',
            );
        }
    }

    /**
     * @return array{
     *   id:int,submission_id:int,part_id:?int,artifact_kind:string,
     *   direction:string,mime_type:string,byte_size:int,
     *   artifact_sha256:string,xsd_version:?string,
     *   catalog_version:?string,channel:string,environment:string
     * }|null
     */
    public function findArtifactByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, part_id, artifact_kind,
                    direction, mime_type, byte_size, artifact_sha256,
                    xsd_version, catalog_version, channel, environment
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'artefakt podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'part_id' => self::nullableInteger($row, 'part_id'),
            'artifact_kind' => self::string($row, 'artifact_kind'),
            'direction' => self::string($row, 'direction'),
            'mime_type' => self::string($row, 'mime_type'),
            'byte_size' => self::integer($row, 'byte_size'),
            'artifact_sha256' => self::hash($row, 'artifact_sha256'),
            'xsd_version' => self::nullableString($row, 'xsd_version'),
            'catalog_version' => self::nullableString(
                $row,
                'catalog_version',
            ),
            'channel' => self::string($row, 'channel'),
            'environment' => self::string($row, 'environment'),
        ];
    }

    public function insertArtifact(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        string $artifactKind,
        string $direction,
        string $mimeType,
        string $contentCiphertext,
        int $byteSize,
        string $artifactSha256,
        ?string $xsdVersion,
        ?string $catalogVersion,
        string $channel,
        string $idempotencyKeyHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_artifacts
                (supplier_id, environment, submission_id, part_id, artifact_kind,
                 direction, mime_type, content_ciphertext, byte_size,
                 artifact_sha256, xsd_version, catalog_version, channel,
                 idempotency_key_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $artifactKind,
            $direction,
            $mimeType,
            $contentCiphertext,
            $byteSize,
            $artifactSha256,
            $xsdVersion,
            $catalogVersion,
            $channel,
            $idempotencyKeyHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * ID zmrazené datové věty podání.
     *
     * Běh na pozadí nemá od uživatele nic — ani variabilní symbol, kterým se
     * VREP ptá na výsledek. Jediný zdroj, který ho nese v podobě, jakou ČSSZ
     * skutečně dostala, je artefakt odeslaného XML; dohledávat symbol jinde
     * (nastavení pracovišť) by mohlo tiše sáhnout po jiném.
     *
     * Bere se NEJSTARŠÍ odchozí XML, protože to je ten dokument, který se
     * zmrazil a odeslal.
     */
    public function findOutboundXmlArtifactId(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?int {
        return $this->findOutboundArtifactId(
            $supplierId,
            $environment,
            $submissionId,
            'outbound_xml',
        );
    }

    public function findOutboundPdfArtifactId(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?int {
        return $this->findOutboundArtifactId(
            $supplierId,
            $environment,
            $submissionId,
            'outbound_pdf',
        );
    }

    public function findOutboundArtifactIdByCatalogVersion(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $artifactKind,
        string $catalogVersion,
    ): ?int
    {
        if (!in_array($artifactKind, ['outbound_xml', 'outbound_pdf'], true)) {
            throw new \InvalidArgumentException('Druh odchozího artefaktu není podporovaný.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_artifacts
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND artifact_kind = ?
                AND direction = "outbound"
                AND catalog_version = ?
              ORDER BY id
              LIMIT 2',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $artifactKind,
            $catalogVersion,
        ]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) {
            throw new \UnexpectedValueException(
                'Podání má více artefaktů se stejnou zmrazenou volbou přílohy.',
            );
        }

        return $ids === [] ? null : (int) $ids[0];
    }

    private function findOutboundArtifactId(
        int $supplierId,
        string $environment,
        int $submissionId,
        string $artifactKind,
    ): ?int {
        if (!in_array($artifactKind, ['outbound_xml', 'outbound_pdf'], true)) {
            throw new \InvalidArgumentException('Druh odchozího artefaktu není podporovaný.');
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_submission_artifacts
              WHERE supplier_id = ?
                AND environment = ?
                AND submission_id = ?
                AND artifact_kind = ?
                AND direction = "outbound"
              ORDER BY id
              LIMIT 1',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $artifactKind,
        ]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Povinnost a její stav podle podání — bez zámku, pro čtení na pozadí.
     *
     * @return array{
     *   id:int,status:string,row_version:int,agenda_code:string,
     *   subject_type:string,subject_reference:string,
     *   period_start:string,period_end:string
     * }|null
     */
    public function findObligationOfSubmission(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT obligation.id, obligation.status, obligation.row_version,
                    obligation.agenda_code, obligation.subject_type,
                    obligation.subject_reference, obligation.period_start,
                    obligation.period_end
               FROM payroll_submissions submission
               JOIN payroll_obligations obligation
                 ON obligation.supplier_id = submission.supplier_id
                AND obligation.environment = submission.environment
                AND obligation.id = submission.obligation_id
              WHERE submission.supplier_id = ?
                AND submission.environment = ?
                AND submission.id = ?',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'row_version' => (int) $row['row_version'],
            'agenda_code' => (string) $row['agenda_code'],
            'subject_type' => (string) $row['subject_type'],
            'subject_reference' => (string) $row['subject_reference'],
            'period_start' => (string) $row['period_start'],
            'period_end' => (string) $row['period_end'],
        ];
    }

    /**
     * @return array{
     *   id:int,submission_id:int,part_id:?int,content_ciphertext:string,
     *   byte_size:int,artifact_sha256:string,environment:string,
     *   artifact_kind:string,direction:string,mime_type:string,
     *   xsd_version:?string,catalog_version:?string,channel:string
     * }|null
     */
    public function findArtifact(int $supplierId, int $artifactId): ?array
    {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, part_id, content_ciphertext,
                    byte_size, artifact_sha256, environment, artifact_kind,
                    direction, mime_type, xsd_version, catalog_version, channel
               FROM payroll_submission_artifacts
              WHERE supplier_id = ? AND id = ?',
        );
        $statement->execute([$supplierId, $artifactId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'artefakt podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'part_id' => self::nullableInteger($row, 'part_id'),
            'content_ciphertext' => self::string(
                $row,
                'content_ciphertext',
            ),
            'byte_size' => self::integer($row, 'byte_size'),
            'artifact_sha256' => self::hash($row, 'artifact_sha256'),
            'environment' => self::string($row, 'environment'),
            'artifact_kind' => self::string($row, 'artifact_kind'),
            'direction' => self::string($row, 'direction'),
            'mime_type' => self::string($row, 'mime_type'),
            'xsd_version' => self::nullableString($row, 'xsd_version'),
            'catalog_version' => self::nullableString(
                $row,
                'catalog_version',
            ),
            'channel' => self::string($row, 'channel'),
        ];
    }

    /**
     * @return array{
     *   id:int,submission_id:int,artifact_id:int,remote_status:?string,
     *   summary_hash:string,request_fingerprint:string
     * }|null
     */
    public function findReceiptByIdempotencyForUpdate(
        int $supplierId,
        string $idempotencyKeyHash,
        string $environment = 'production',
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, submission_id, artifact_id,
                    remote_status, summary_hash, request_fingerprint
               FROM payroll_submission_receipts
              WHERE supplier_id = ? AND environment = ?
                AND idempotency_key_hash = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $environment, $idempotencyKeyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'protokol podání');

        return [
            'id' => self::integer($row, 'id'),
            'submission_id' => self::integer($row, 'submission_id'),
            'artifact_id' => self::integer($row, 'artifact_id'),
            'remote_status' => self::nullableString($row, 'remote_status'),
            'summary_hash' => self::hash($row, 'summary_hash'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
        ];
    }

    public function insertReceipt(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        int $artifactId,
        string $receiptReference,
        ?string $correlationReference,
        string $protocolCode,
        ?string $remoteStatus,
        string $verificationStatus,
        string $summaryHash,
        string $requestFingerprint,
        string $idempotencyKeyHash,
        string $receivedAt,
        ?int $importedBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_receipts
                (supplier_id, environment, submission_id, part_id, artifact_id,
                  receipt_reference, correlation_reference, protocol_code,
                  remote_status, verification_status, summary_hash,
                  request_fingerprint, idempotency_key_hash,
                  received_at, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $artifactId,
            $receiptReference,
            $correlationReference,
            $protocolCode,
            $remoteStatus,
            $verificationStatus,
            $summaryHash,
            $requestFingerprint,
            $idempotencyKeyHash,
            $receivedAt,
            $importedBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertJmhzProtocolFormOutcome(
        int $supplierId,
        string $environment,
        int $submissionId,
        int $receiptId,
        int $artifactId,
        ?int $partId,
        string $formGuid,
        ?int $protocolStatusCode,
        ?string $protocolStatusName,
        ?string $remoteStatus,
        ?string $externalPersonReference,
        ?string $externalEmploymentReference,
        int $errorCount,
        string $errorsCiphertext,
        string $errorsSha256,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_jmhz_protocol_form_outcomes
                (supplier_id, environment, submission_id, receipt_id,
                 artifact_id, part_id, form_guid, protocol_status_code,
                 protocol_status_name, remote_status,
                 external_person_reference, external_employment_reference,
                 error_count, errors_ciphertext, errors_sha256)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $receiptId,
            $artifactId,
            $partId,
            $formGuid,
            $protocolStatusCode,
            $protocolStatusName,
            $remoteStatus,
            $externalPersonReference,
            $externalEmploymentReference,
            $errorCount,
            $errorsCiphertext,
            $errorsSha256,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function listJmhzProtocolFormOutcomes(
        int $supplierId,
        string $environment,
        int $receiptId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, environment, submission_id, receipt_id,
                    artifact_id, part_id, form_guid, protocol_status_code,
                    protocol_status_name, remote_status,
                    external_person_reference, external_employment_reference,
                    error_count, errors_ciphertext, errors_sha256, created_at
               FROM payroll_jmhz_protocol_form_outcomes
              WHERE supplier_id = ? AND environment = ? AND receipt_id = ?
              ORDER BY id',
        );
        $statement->execute([$supplierId, $environment, $receiptId]);
        $outcomes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'výsledek formuláře protokolu JMHZ');
            foreach ([
                'id', 'supplier_id', 'submission_id', 'receipt_id',
                'artifact_id', 'error_count',
            ] as $field) {
                $row[$field] = self::integer($row, $field);
            }
            $row['part_id'] = self::nullableInteger($row, 'part_id');
            $row['protocol_status_code'] = self::nullableInteger(
                $row,
                'protocol_status_code',
            );
            $outcomes[] = $row;
        }

        return $outcomes;
    }

    /**
     * @return list<array{
     *   receipt_id:int,verification_status:string,remote_status:?string,
     *   protocol_code:string,form_guid:?string,form_remote_status:?string,
     *   external_person_reference:?string,external_employment_reference:?string
     * }>
     */
    public function listJmhzReceiptEvidenceForSubmission(
        int $supplierId,
        string $environment,
        int $submissionId,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT receipt.id AS receipt_id, receipt.verification_status,
                    receipt.remote_status, receipt.protocol_code,
                    outcome.form_guid,
                    outcome.remote_status AS form_remote_status,
                    outcome.external_person_reference,
                    outcome.external_employment_reference
               FROM payroll_submission_receipts receipt
               LEFT JOIN payroll_jmhz_protocol_form_outcomes outcome
                 ON outcome.supplier_id = receipt.supplier_id
                AND outcome.environment = receipt.environment
                AND outcome.receipt_id = receipt.id
              WHERE receipt.supplier_id = ? AND receipt.environment = ?
                AND receipt.submission_id = ?
              ORDER BY receipt.received_at, receipt.id, outcome.id',
        );
        $statement->execute([$supplierId, $environment, $submissionId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = self::associativeRow($row, 'důkaz protokolu JMHZ');
            $rows[] = [
                'receipt_id' => self::integer($row, 'receipt_id'),
                'verification_status' => self::string($row, 'verification_status'),
                'remote_status' => self::nullableString($row, 'remote_status'),
                'protocol_code' => self::string($row, 'protocol_code'),
                'form_guid' => self::nullableString($row, 'form_guid'),
                'form_remote_status' => self::nullableString($row, 'form_remote_status'),
                'external_person_reference' => self::nullableString(
                    $row,
                    'external_person_reference',
                ),
                'external_employment_reference' => self::nullableString(
                    $row,
                    'external_employment_reference',
                ),
            ];
        }

        return $rows;
    }

    public function updateSubmissionStatus(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
        string $status,
        ?string $correlationReference,
        ?string $submittedAt,
        ?string $decidedAt,
    ): int {
        return $this->transaction(function () use (
            $supplierId,
            $submissionId,
            $expectedRowVersion,
            $status,
            $correlationReference,
            $submittedAt,
            $decidedAt,
        ): int {
            if (!in_array($status, ['accepted', 'superseded', 'cancelled_in_time'], true)) {
                $this->yearClose->assertOpenForSubmission($supplierId, $submissionId);
            }
            $statement = $this->db->pdo()->prepare(
                'UPDATE payroll_submissions
                    SET status = ?,
                        correlation_reference =
                          COALESCE(?, correlation_reference),
                        submitted_at = COALESCE(?, submitted_at),
                        decided_at = COALESCE(?, decided_at),
                        row_version = row_version + 1
                  WHERE supplier_id = ?
                    AND id = ?
                    AND row_version = ?',
            );
            $statement->execute([
                $status,
                $correlationReference,
                $submittedAt,
                $decidedAt,
                $supplierId,
                $submissionId,
                $expectedRowVersion,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new PayrollSubmissionConflictException(
                    'Podání se mezitím změnilo.',
                );
            }

            return $expectedRowVersion + 1;
        });
    }

    public function bumpSubmissionVersion(
        int $supplierId,
        int $submissionId,
        int $expectedRowVersion,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_submissions
                SET row_version = row_version + 1
              WHERE supplier_id = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $supplierId,
            $submissionId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Podání se mezitím změnilo.',
            );
        }

        return $expectedRowVersion + 1;
    }

    public function updateObligationStatus(
        int $supplierId,
        string $environment,
        int $obligationId,
        int $expectedRowVersion,
        string $status,
    ): void {
        $statement = $this->db->pdo()->prepare(
            'UPDATE payroll_obligations
                SET status = ?, row_version = row_version + 1
              WHERE supplier_id = ?
                AND environment = ?
                AND id = ?
                AND row_version = ?',
        );
        $statement->execute([
            $status,
            $supplierId,
            $environment,
            $obligationId,
            $expectedRowVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new PayrollSubmissionConflictException(
                'Povinnost podání se mezitím změnila.',
            );
        }
    }

    public function insertIssue(
        int $supplierId,
        string $environment,
        int $submissionId,
        ?int $partId,
        string $severity,
        string $validationStage,
        string $issueCode,
        ?string $entityType,
        ?string $entityReference,
        ?string $detailsCiphertext,
        ?string $detailsHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_submission_issues
                (supplier_id, environment, submission_id, part_id, severity,
                 validation_stage, issue_code, entity_type,
                 entity_reference, details_ciphertext, details_hash,
                 created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $environment,
            $submissionId,
            $partId,
            $severity,
            $validationStage,
            $issueCode,
            $entityType,
            $entityReference,
            $detailsCiphertext,
            $detailsHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function insertAgendaMatrix(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
        ?string $validTo,
        string $replacementMode,
        string $rulesetId,
        string $rulesetHash,
        ?int $createdBy,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'INSERT INTO payroll_agenda_matrix
                (supplier_id, agenda_code, valid_from, valid_to,
                 replacement_mode, ruleset_id, ruleset_hash, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $validFrom,
            $validTo,
            $replacementMode,
            $rulesetId,
            $rulesetHash,
            $createdBy,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * @return array{
     *   id:int,valid_to:?string,replacement_mode:string,
     *   ruleset_id:string,ruleset_hash:string
     * }|null
     */
    public function findAgendaMatrixByStartForUpdate(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT id, valid_to, replacement_mode, ruleset_id, ruleset_hash
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from = ?
              FOR UPDATE',
        );
        $statement->execute([$supplierId, $agendaCode, $validFrom]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'řádek matice agendy');

        return [
            'id' => self::integer($row, 'id'),
            'valid_to' => self::nullableString($row, 'valid_to'),
            'replacement_mode' => self::string($row, 'replacement_mode'),
            'ruleset_id' => self::string($row, 'ruleset_id'),
            'ruleset_hash' => self::hash($row, 'ruleset_hash'),
        ];
    }

    public function agendaMatrixOverlapsForUpdate(
        int $supplierId,
        string $agendaCode,
        string $validFrom,
        ?string $validTo,
    ): bool {
        $statement = $this->db->pdo()->prepare(
            'SELECT id
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from <= COALESCE(?, "9999-12-31")
                AND COALESCE(valid_to, "9999-12-31") >= ?
              LIMIT 1
              FOR UPDATE',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $validTo,
            $validFrom,
        ]);

        return $statement->fetchColumn() !== false;
    }

    public function effectiveAgendaReplacementMode(
        int $supplierId,
        string $agendaCode,
        string $onDate,
    ): ?string {
        $statement = $this->db->pdo()->prepare(
            'SELECT replacement_mode
               FROM payroll_agenda_matrix
              WHERE supplier_id = ?
                AND agenda_code = ?
                AND valid_from <= ?
                AND (valid_to IS NULL OR valid_to >= ?)
              ORDER BY valid_from DESC
              LIMIT 1',
        );
        $statement->execute([
            $supplierId,
            $agendaCode,
            $onDate,
            $onDate,
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *   status:string,period_start:string,result_snapshot_hash:?string,
     *   run_id:int,office_id:?int
     * }|null
     */
    public function approvedRevisionScope(
        int $supplierId,
        int $revisionId,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT revision.status, run.period_start,
                    revision.result_snapshot_hash, run.id AS run_id,
                    run.office_id
               FROM payroll_run_revisions revision
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE revision.supplier_id = ?
                AND revision.id = ?',
        );
        $statement->execute([$supplierId, $revisionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = self::associativeRow($row, 'zdrojovou revizi podání');

        return [
            'status' => self::string($row, 'status'),
            'period_start' => self::string($row, 'period_start'),
            'result_snapshot_hash' => self::nullableString(
                $row,
                'result_snapshot_hash',
            ),
            'run_id' => self::integer($row, 'run_id'),
            'office_id' => self::nullableInteger($row, 'office_id'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *   id:int,status:string,row_version:int,request_fingerprint:string,
     *   source_snapshot_hash:string,submission_kind:string,channel:string,
     *   environment:string,obligation_id:int,corrects_submission_id:?int,
     *   correlation_reference:?string
     * }
     */
    private static function submissionRow(array $row): array
    {
        return [
            'id' => self::integer($row, 'id'),
            'status' => self::string($row, 'status'),
            'row_version' => self::integer($row, 'row_version'),
            'request_fingerprint' => self::hash(
                $row,
                'request_fingerprint',
            ),
            'source_snapshot_hash' => self::hash(
                $row,
                'source_snapshot_hash',
            ),
            'submission_kind' => self::string($row, 'submission_kind'),
            'channel' => self::string($row, 'channel'),
            'environment' => self::string($row, 'environment'),
            'obligation_id' => self::integer($row, 'obligation_id'),
            'corrects_submission_id' => self::nullableInteger(
                $row,
                'corrects_submission_id',
            ),
            'correlation_reference' => self::nullableString(
                $row,
                'correlation_reference',
            ),
        ];
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }
        $normalized = filter_var($value, FILTER_VALIDATE_INT);
        if ($normalized === false) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není celé číslo.",
            );
        }

        return $normalized;
    }

    /** @param array<string,mixed> $row */
    private static function nullableInteger(
        array $row,
        string $field,
    ): ?int {
        return ($row[$field] ?? null) === null
            ? null
            : self::integer($row, $field);
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableString(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není text.",
            );
        }

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::string($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Databázová hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private static function associativeRow(
        mixed $value,
        string $context,
    ): array {
        if (!is_array($value) || array_is_list($value)) {
            throw new \UnexpectedValueException(
                "Databáze vrátila neplatný {$context}.",
            );
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(
                    "Databázový {$context} nemá textové klíče.",
                );
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
