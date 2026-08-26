<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Payment;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class PayrollPaymentReconciliationQueryService
{
    public const LIST_DEFAULT_LIMIT = 25;
    public const LIST_MAX_LIMIT = 200;

    /**
     * Kolik nabídky se posílá rovnou s rekonciliací.
     *
     * Nabídky pro výběr (alokace, bankovní a pokladní důkazy) se dřív posílaly
     * CELÉ. Bankovní důkazy přitom sahají od nejstaršího data splatnosti do
     * dneška, takže u dlouho běžící firmy je to neohraničená odpověď.
     *
     * Stránkovat je nešlo — z pickeru by se stalo „vybrat jde jen to, co je na
     * první straně". Řešením je serverové hledání (viz {@see searchOptions()});
     * tenhle strop jen říká, kolik se pošle napřed, aby krátký seznam fungoval
     * bez jediného dalšího dotazu. Že je toho víc, odpověď PŘIZNÁ příznakem
     * `*_truncated` — uživatel nesmí uvěřit, že vidí všechno.
     */
    public const OFFERED_LIMIT = 50;

    public const PICKER_DEFAULT_LIMIT = 20;
    public const PICKER_MAX_LIMIT = 50;

    public const PICKER_KINDS = [
        'allocations',
        'incoming_liabilities',
        'bank_evidence',
        'cash_evidence',
    ];
    public const PICKER_USAGES = ['match', 'reversal'];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string,mixed> */
    public function forPeriod(
        int $supplierId,
        string $period,
        int $limit = self::LIST_DEFAULT_LIMIT,
        int $offset = 0,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma párování plateb musí být kladné číslo.',
            );
        }
        // Strop se klampuje i tady, ne jen na HTTP hranici: službu volá i jiný
        // kód než akce a „vypiš celou historii" nesmí jít objednat nikudy.
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $offset = max(0, $offset);
        [$from, $to] = $this->periodRange($period);
        $evidenceRange = $this->evidenceRange(
            $supplierId,
            $from,
            $to,
        );

        $allocations = $this->trim(
            $this->allocations($supplierId, $from, $to, [], self::OFFERED_LIMIT + 1),
            self::OFFERED_LIMIT,
        );
        $incomingLiabilities = $this->trim(
            $this->incomingLiabilities(
                $supplierId,
                $from,
                $to,
                [],
                self::OFFERED_LIMIT + 1,
            ),
            self::OFFERED_LIMIT,
        );
        $bankEvidence = $evidenceRange === null
            ? ['items' => [], 'truncated' => false]
            : $this->trim(
                $this->bankEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                    [],
                    self::OFFERED_LIMIT + 1,
                ),
                self::OFFERED_LIMIT,
            );
        $cashEvidence = $evidenceRange === null
            ? ['items' => [], 'truncated' => false]
            : $this->trim(
                $this->cashEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                    [],
                    self::OFFERED_LIMIT + 1,
                ),
                self::OFFERED_LIMIT,
            );

        return [
            'period' => $period,
            // Nabídky pro výběr se posílají OŘEZANÉ a ořezání se přiznává.
            // Kdo se do stropu vejde, má picker kompletní a nepotřebuje jediné
            // další volání; kdo ne, hledá přes /reconciliation/options.
            'allocations' => $allocations['items'],
            'allocations_truncated' => $allocations['truncated'],
            'incoming_liabilities' => $incomingLiabilities['items'],
            'incoming_liabilities_truncated' =>
                $incomingLiabilities['truncated'],
            'offered_limit' => self::OFFERED_LIMIT,
            'matches' => $this->matches(
                $supplierId,
                $from,
                $to,
                false,
                $limit,
                $offset,
            ),
            'matches_total' => $this->matchCount($supplierId, $from, $to),
            'matches_limit' => $limit,
            'matches_offset' => $offset,
            // Nabídka „co lze stornovat" NENÍ stránka historie: kdyby se brala
            // z ní, zmizely by z výběru události, které jen leží na jiné straně,
            // a storno by šlo udělat jen tehdy, když má uživatel štěstí na
            // stránkování. Vratné události jsou zároveň úzká, samo se
            // vyprazdňující množina.
            'reversible_matches' => $this->matches(
                $supplierId,
                $from,
                $to,
                true,
                self::LIST_MAX_LIMIT,
                0,
            ),
            'bank_evidence' => $bankEvidence['items'],
            'bank_evidence_truncated' => $bankEvidence['truncated'],
            'cash_evidence' => $cashEvidence['items'],
            'cash_evidence_truncated' => $cashEvidence['truncated'],
        ];
    }

    /**
     * Serverové hledání v nabídce pickeru.
     *
     * Vrací nejvýš `$limit` nejlepších shod a příznak `truncated`, když jich
     * je víc. Ten příznak je celý smysl téhle metody: seznam oříznutý mlčky
     * tvrdí „nic dalšího neexistuje", a přesně na tom se v párování plateb dá
     * přehlédnout hledaná transakce.
     *
     * Zúžení podle měny, směru a použitelnosti (`match` / `reversal`) padá
     * SEM, ne do prohlížeče. Kdyby zůstalo v klientovi, mohlo by dvacet
     * serverem vybraných řádků po klientském filtru zbýt prázdných — a to
     * vypadá jako „žádný důkaz neexistuje".
     *
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,mixed>>,truncated:bool,limit:int}
     */
    public function searchOptions(
        int $supplierId,
        string $period,
        string $kind,
        array $filters = [],
        int $limit = self::PICKER_DEFAULT_LIMIT,
    ): array {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException(
                'Firma párování plateb musí být kladné číslo.',
            );
        }
        if (!in_array($kind, self::PICKER_KINDS, true)) {
            throw new \InvalidArgumentException(
                'Druh nabídky párování plateb není platný.',
            );
        }
        $usage = $filters['usage'] ?? 'match';
        if (!in_array($usage, self::PICKER_USAGES, true)) {
            throw new \InvalidArgumentException(
                'Použití důkazu musí být match nebo reversal.',
            );
        }
        $filters['usage'] = $usage;
        $limit = max(1, min(self::PICKER_MAX_LIMIT, $limit));
        [$from, $to] = $this->periodRange($period);

        if ($kind === 'allocations') {
            $page = $this->trim(
                $this->allocations($supplierId, $from, $to, $filters, $limit + 1),
                $limit,
            );

            return $page + ['limit' => $limit];
        }
        if ($kind === 'incoming_liabilities') {
            $page = $this->trim(
                $this->incomingLiabilities(
                    $supplierId,
                    $from,
                    $to,
                    $filters,
                    $limit + 1,
                ),
                $limit,
            );

            return $page + ['limit' => $limit];
        }

        $evidenceRange = $this->evidenceRange($supplierId, $from, $to);
        if ($evidenceRange === null) {
            return ['items' => [], 'truncated' => false, 'limit' => $limit];
        }
        $page = $kind === 'bank_evidence'
            ? $this->trim(
                $this->bankEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                    $filters,
                    $limit + 1,
                ),
                $limit,
            )
            : $this->trim(
                $this->cashEvidence(
                    $supplierId,
                    $evidenceRange[0],
                    $evidenceRange[1],
                    $filters,
                    $limit + 1,
                ),
                $limit,
            );

        return $page + ['limit' => $limit];
    }

    /**
     * Oříznutí na strop s poznáním, že se ořezávalo.
     *
     * Dotaz si vždy vyžádá `limit + 1` řádek; přebývající řádek je důkaz, že
     * jich je víc, a nestojí to další COUNT dotaz.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{items:list<array<string,mixed>>,truncated:bool}
     */
    private function trim(array $rows, int $limit): array
    {
        return count($rows) > $limit
            ? ['items' => array_slice($rows, 0, $limit), 'truncated' => true]
            : ['items' => $rows, 'truncated' => false];
    }

    /** Escapování zástupných znaků LIKE — `%` v hledaném textu není wildcard. */
    private static function likeTerm(string $value): string
    {
        return '%' . str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\%', '\_'],
            $value,
        ) . '%';
    }

    /**
     * Nabídka alokací k párování.
     *
     * `remaining > 0` je součást DOTAZU, ne pozdější filtr v prohlížeči:
     * doplacenou alokaci nelze zaplatit znovu, takže do nabídky nepatří,
     * a kdyby ji odfiltroval až klient, mohl by ze serverem vybrané
     * dvacítky zbýt prázdný seznam.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function allocations(
        int $supplierId,
        string $from,
        string $to,
        array $filters,
        int $limit,
    ): array {
        $search = trim((string) ($filters['search'] ?? ''));
        $searchClause = $search === ''
            ? ''
            : ' AND (employee.full_name LIKE ?
                     OR payment_item.item_reference LIKE ?
                     OR payment_batch.batch_reference LIKE ?
                     OR liability.liability_kind LIKE ?)';
        $statement = $this->db->pdo()->prepare(
            'SELECT allocation.id, allocation.item_id,
                    payment_item.item_reference,
                    payment_batch.id AS batch_id,
                    payment_batch.batch_reference,
                    payment_batch.channel,
                    payment_batch.planned_payment_date,
                    liability.id AS liability_id,
                    liability.liability_kind,
                    liability.direction,
                    liability.currency_code,
                    employee.full_name AS employee_name,
                    allocation.amount_minor,
                    COALESCE(SUM(payment_match.amount_minor), 0)
                      AS settled_minor
               FROM payroll_payment_allocations allocation
               JOIN payroll_payment_items payment_item
                 ON payment_item.supplier_id = allocation.supplier_id
                AND payment_item.id = allocation.item_id
               JOIN payroll_payment_batches payment_batch
                 ON payment_batch.supplier_id = payment_item.supplier_id
                AND payment_batch.id = payment_item.batch_id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = allocation.supplier_id
                AND liability.id = allocation.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
               LEFT JOIN payroll_payment_matches payment_match
                 ON payment_match.supplier_id = allocation.supplier_id
                AND payment_match.allocation_id = allocation.id
              WHERE allocation.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?'
            . $searchClause
            . ' GROUP BY allocation.id, allocation.item_id,
                       payment_item.item_reference, payment_batch.id,
                       payment_batch.batch_reference,
                       payment_batch.channel,
                       payment_batch.planned_payment_date,
                       liability.id, liability.liability_kind,
                       liability.direction, liability.currency_code,
                       employee.full_name, allocation.amount_minor
              HAVING allocation.amount_minor
                     - COALESCE(SUM(payment_match.amount_minor), 0) > 0
              ORDER BY payment_batch.planned_payment_date,
                       employee.full_name, allocation.id
              LIMIT ?',
        );
        $position = 0;
        $statement->bindValue(++$position, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(++$position, $from, PDO::PARAM_STR);
        $statement->bindValue(++$position, $to, PDO::PARAM_STR);
        if ($search !== '') {
            $term = self::likeTerm($search);
            for ($i = 0; $i < 4; ++$i) {
                $statement->bindValue(++$position, $term, PDO::PARAM_STR);
            }
        }
        $statement->bindValue(++$position, $limit, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'platební alokaci');
            $amount = self::integer($row, 'amount_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($amount <= 0 || $settled < 0 || $settled > $amount) {
                throw new \UnexpectedValueException(
                    'Součet platební alokace je mimo povolené meze.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'item_id' => self::integer($row, 'item_id'),
                'item_reference' => self::text(
                    $row,
                    'item_reference',
                ),
                'batch_id' => self::integer($row, 'batch_id'),
                'batch_reference' => self::text(
                    $row,
                    'batch_reference',
                ),
                'channel' => self::enum(
                    $row,
                    'channel',
                    ['bank', 'cash'],
                ),
                'planned_payment_date' => self::date(
                    $row,
                    'planned_payment_date',
                ),
                'liability_id' => self::integer($row, 'liability_id'),
                'liability_kind' => self::text(
                    $row,
                    'liability_kind',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'employee_name' => self::nullableText(
                    $row,
                    'employee_name',
                ),
                'amount_minor' => $amount,
                'settled_minor' => $settled,
                'remaining_minor' => $amount - $settled,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function incomingLiabilities(
        int $supplierId,
        string $from,
        string $to,
        array $filters,
        int $limit,
    ): array {
        $search = trim((string) ($filters['search'] ?? ''));
        $currency = trim((string) ($filters['currency'] ?? ''));
        $searchClause = $search === ''
            ? ''
            : ' AND (employee.full_name LIKE ?
                     OR liability.liability_reference LIKE ?
                     OR liability.liability_kind LIKE ?)';
        $currencyClause = $currency === ''
            ? ''
            : ' AND liability.currency_code = ?';
        $statement = $this->db->pdo()->prepare(
            'SELECT liability.id, liability.liability_reference,
                    liability.liability_kind, liability.direction,
                    liability.due_on, liability.currency_code,
                    liability.amount_minor,
                    employee.full_name AS employee_name,
                    COALESCE(SUM(payment_match.amount_minor), 0)
                      AS settled_minor
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
          LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
          LEFT JOIN payroll_payment_matches payment_match
                 ON payment_match.supplier_id = liability.supplier_id
                AND payment_match.liability_id = liability.id
                AND payment_match.allocation_id IS NULL
              WHERE liability.supplier_id = ?
                AND liability.direction = "incoming"
                AND run.period_start >= ?
                AND run.period_start < ?'
            . $currencyClause
            . $searchClause
            . ' GROUP BY liability.id, liability.liability_reference,
                       liability.liability_kind, liability.direction,
                       liability.due_on, liability.currency_code,
                       liability.amount_minor, employee.full_name
              HAVING liability.amount_minor
                     - COALESCE(SUM(payment_match.amount_minor), 0) > 0
              ORDER BY liability.due_on, employee.full_name, liability.id
              LIMIT ?',
        );
        $position = 0;
        $statement->bindValue(++$position, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(++$position, $from, PDO::PARAM_STR);
        $statement->bindValue(++$position, $to, PDO::PARAM_STR);
        if ($currency !== '') {
            $statement->bindValue(++$position, $currency, PDO::PARAM_STR);
        }
        if ($search !== '') {
            $term = self::likeTerm($search);
            for ($i = 0; $i < 3; ++$i) {
                $statement->bindValue(++$position, $term, PDO::PARAM_STR);
            }
        }
        $statement->bindValue(++$position, $limit, PDO::PARAM_INT);
        $statement->execute();

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'příchozí mzdový závazek');
            $amount = self::integer($row, 'amount_minor');
            $settled = self::integer($row, 'settled_minor');
            if ($amount <= 0 || $settled < 0 || $settled > $amount) {
                throw new \UnexpectedValueException(
                    'Součet přijatých vratek závazku je mimo povolené meze.',
                );
            }
            $result[] = [
                'id' => self::integer($row, 'id'),
                'liability_reference' => self::text(
                    $row,
                    'liability_reference',
                ),
                'liability_kind' => self::text($row, 'liability_kind'),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['incoming'],
                ),
                'due_on' => self::date($row, 'due_on'),
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'employee_name' => self::nullableText(
                    $row,
                    'employee_name',
                ),
                'amount_minor' => $amount,
                'settled_minor' => $settled,
                'remaining_minor' => $amount - $settled,
            ];
        }

        return $result;
    }

    private function matchCount(
        int $supplierId,
        string $from,
        string $to,
    ): int {
        $statement = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
               FROM payroll_payment_matches payment_match
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = payment_match.supplier_id
                AND liability.id = payment_match.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE payment_match.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?',
        );
        $statement->execute([$supplierId, $from, $to]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function matches(
        int $supplierId,
        string $from,
        string $to,
        bool $reversibleOnly,
        int $limit,
        int $offset,
    ): array {
        $statement = $this->db->pdo()->prepare(
            'SELECT payment_match.id, payment_match.allocation_id,
                    payment_match.liability_id,
                    payment_match.event_kind,
                    payment_match.source_match_id,
                    payment_match.amount_minor,
                    payment_match.bank_statement_id,
                    payment_match.bank_transaction_id,
                    payment_match.cash_document_id,
                    payment_match.actual_payment_date,
                    payment_match.evidence_amount_minor,
                    payment_match.evidence_currency_code,
                    payment_match.evidence_fact_hash,
                    payment_match.created_at,
                    payment_batch.batch_reference,
                    liability.liability_kind,
                    liability.direction AS allocation_direction,
                    liability.currency_code AS allocation_currency_code,
                    employee.full_name AS employee_name,
                    CASE
                      WHEN payment_match.event_kind = "matched"
                      THEN payment_match.amount_minor + COALESCE((
                        SELECT SUM(reversal.amount_minor)
                          FROM payroll_payment_matches reversal
                         WHERE reversal.supplier_id =
                               payment_match.supplier_id
                           AND reversal.source_match_id = payment_match.id
                           AND reversal.event_kind = "reversed"
                      ), 0)
                      ELSE 0
                    END AS reversible_minor
               FROM payroll_payment_matches payment_match
          LEFT JOIN payroll_payment_allocations allocation
                 ON allocation.supplier_id = payment_match.supplier_id
                AND allocation.id = payment_match.allocation_id
          LEFT JOIN payroll_payment_items payment_item
                 ON payment_item.supplier_id = allocation.supplier_id
                AND payment_item.id = allocation.item_id
          LEFT JOIN payroll_payment_batches payment_batch
                 ON payment_batch.supplier_id = payment_item.supplier_id
                AND payment_batch.id = payment_item.batch_id
               JOIN payroll_payment_liabilities liability
                 ON liability.supplier_id = payment_match.supplier_id
                AND liability.id = payment_match.liability_id
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
               LEFT JOIN payroll_employees employee
                 ON employee.supplier_id = liability.supplier_id
                AND employee.id = liability.employee_id
              WHERE payment_match.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?'
            . ($reversibleOnly
                ? ' AND payment_match.event_kind = "matched"
                    AND payment_match.amount_minor + COALESCE((
                          SELECT SUM(reversal.amount_minor)
                            FROM payroll_payment_matches reversal
                           WHERE reversal.supplier_id =
                                 payment_match.supplier_id
                             AND reversal.source_match_id = payment_match.id
                             AND reversal.event_kind = "reversed"
                        ), 0) > 0'
                : '')
            . ' ORDER BY payment_match.actual_payment_date DESC,
                        payment_match.id DESC
               LIMIT ? OFFSET ?',
        );
        $statement->bindValue(1, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(2, $from, PDO::PARAM_STR);
        $statement->bindValue(3, $to, PDO::PARAM_STR);
        $statement->bindValue(4, $limit, PDO::PARAM_INT);
        $statement->bindValue(5, $offset, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'spárování platby');
            $eventKind = self::enum(
                $row,
                'event_kind',
                ['matched', 'reversed'],
            );
            $amount = self::integer($row, 'amount_minor');
            $reversible = self::integer($row, 'reversible_minor');
            if (($eventKind === 'matched' && $amount <= 0)
                || ($eventKind === 'reversed' && $amount >= 0)
                || $reversible < 0
            ) {
                throw new \UnexpectedValueException(
                    'Platební událost má neplatné částky.',
                );
            }
            $bankTransactionId = self::nullableInteger(
                $row,
                'bank_transaction_id',
            );
            $result[] = [
                'id' => self::integer($row, 'id'),
                'allocation_id' => self::nullableInteger(
                    $row,
                    'allocation_id',
                ),
                'liability_id' => self::integer($row, 'liability_id'),
                'event_kind' => $eventKind,
                'source_match_id' => self::nullableInteger(
                    $row,
                    'source_match_id',
                ),
                'amount_minor' => $amount,
                'evidence_kind' => $bankTransactionId === null
                    ? 'cash'
                    : 'bank',
                'bank_statement_id' => self::nullableInteger(
                    $row,
                    'bank_statement_id',
                ),
                'bank_transaction_id' => $bankTransactionId,
                'cash_document_id' => self::nullableInteger(
                    $row,
                    'cash_document_id',
                ),
                'actual_payment_date' => self::date(
                    $row,
                    'actual_payment_date',
                ),
                'evidence_amount_minor' => self::integer(
                    $row,
                    'evidence_amount_minor',
                ),
                'evidence_currency_code' => self::currency(
                    $row,
                    'evidence_currency_code',
                ),
                'evidence_fact_hash' => self::hash(
                    $row,
                    'evidence_fact_hash',
                ),
                'batch_reference' => self::nullableText(
                    $row,
                    'batch_reference',
                ),
                'liability_kind' => self::text(
                    $row,
                    'liability_kind',
                ),
                // Směr a měna PŘÍSLUŠNÉ ALOKACE jedou s událostí, ne dohledáním
                // v nabídce alokací: ta je od zavedení stropu jen výsek, takže
                // by se storno u alokace mimo výsek tiše nedalo nabídnout.
                'allocation_direction' => self::enum(
                    $row,
                    'allocation_direction',
                    ['outgoing', 'incoming'],
                ),
                'allocation_currency_code' => self::currency(
                    $row,
                    'allocation_currency_code',
                ),
                'employee_name' => self::nullableText(
                    $row,
                    'employee_name',
                ),
                'reversible_minor' => $reversible,
                'created_at' => self::text($row, 'created_at'),
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function bankEvidence(
        int $supplierId,
        string $from,
        string $to,
        array $filters,
        int $limit,
    ): array {
        $search = trim((string) ($filters['search'] ?? ''));
        $currency = trim((string) ($filters['currency'] ?? ''));
        $direction = trim((string) ($filters['direction'] ?? ''));
        $usage = $filters['usage'] ?? null;
        $where = '';
        $having = '';
        if ($currency !== '') {
            $where .= ' AND COALESCE(bank_transaction.currency,
                                     bank_statement.currency) = ?';
        }
        if ($direction === 'outgoing') {
            $where .= ' AND bank_transaction.amount < 0';
        } elseif ($direction === 'incoming') {
            $where .= ' AND bank_transaction.amount > 0';
        }
        if ($search !== '') {
            $where .= ' AND bank_transaction.description LIKE ?';
        }
        // Použitelnost je součást DOTAZU: transakce, ze které už nezbývá co
        // spárovat (resp. co stornovat), do nabídky nepatří vůbec.
        if ($usage === 'match') {
            $having = ' HAVING CAST(ROUND(ABS(bank_transaction.amount) * 100) AS SIGNED)
                        - COALESCE(SUM(CASE WHEN payroll_match.event_kind = "matched"
                                            THEN ABS(payroll_match.amount_minor)
                                            ELSE 0 END), 0) > 0';
        } elseif ($usage === 'reversal') {
            $having = ' HAVING CAST(ROUND(ABS(bank_transaction.amount) * 100) AS SIGNED)
                        - COALESCE(SUM(CASE WHEN payroll_match.event_kind = "reversed"
                                            THEN ABS(payroll_match.amount_minor)
                                            ELSE 0 END), 0) > 0';
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT bank_statement.id AS bank_statement_id,
                    bank_transaction.id AS bank_transaction_id,
                    bank_transaction.posted_at,
                    CAST(ROUND(ABS(bank_transaction.amount) * 100)
                      AS SIGNED) AS amount_minor,
                    COALESCE(bank_transaction.currency,
                             bank_statement.currency) AS currency_code,
                    CASE WHEN bank_transaction.amount < 0
                         THEN "outgoing" ELSE "incoming" END AS direction,
                    bank_transaction.description,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "matched"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS matched_minor,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "reversed"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS reversed_minor
               FROM bank_statements bank_statement
               JOIN bank_transactions bank_transaction
                 ON bank_transaction.statement_id = bank_statement.id
               LEFT JOIN payroll_payment_matches payroll_match
                 ON payroll_match.supplier_id = bank_statement.supplier_id
                AND payroll_match.bank_statement_id = bank_statement.id
                AND payroll_match.bank_transaction_id =
                    bank_transaction.id
              WHERE bank_statement.supplier_id = ?
                AND bank_transaction.posted_at >= ?
                AND bank_transaction.posted_at < ?
                AND bank_transaction.amount <> 0
                AND bank_transaction.matched_invoice_id IS NULL
                AND bank_transaction.match_status = "unmatched"
                AND NOT EXISTS (
                  SELECT 1 FROM invoice_payments invoice_payment
                   WHERE invoice_payment.bank_transaction_id =
                         bank_transaction.id
                )
                AND NOT EXISTS (
                  SELECT 1 FROM payment_matches payment_match
                   WHERE payment_match.bank_transaction_id =
                         bank_transaction.id
                )'
            . $where
            . ' GROUP BY bank_statement.id, bank_transaction.id,
                       bank_transaction.posted_at,
                       bank_transaction.amount,
                       bank_transaction.currency,
                       bank_statement.currency,
                       bank_transaction.description'
            . $having
            . ' ORDER BY bank_transaction.posted_at DESC,
                       bank_transaction.id DESC
              LIMIT ?',
        );
        $position = 0;
        $statement->bindValue(++$position, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(++$position, $from, PDO::PARAM_STR);
        $statement->bindValue(++$position, $to, PDO::PARAM_STR);
        if ($currency !== '') {
            $statement->bindValue(++$position, $currency, PDO::PARAM_STR);
        }
        if ($search !== '') {
            $statement->bindValue(++$position, self::likeTerm($search), PDO::PARAM_STR);
        }
        $statement->bindValue(++$position, $limit, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'bankovní důkaz');
            $amount = self::integer($row, 'amount_minor');
            $matched = self::integer($row, 'matched_minor');
            $reversed = self::integer($row, 'reversed_minor');
            if ($amount <= 0 || $matched < 0 || $matched > $amount
                || $reversed < 0 || $reversed > $amount
            ) {
                throw new \UnexpectedValueException(
                    'Využití bankovního důkazu je mimo povolené meze.',
                );
            }
            $result[] = [
                'kind' => 'bank',
                'bank_statement_id' => self::integer(
                    $row,
                    'bank_statement_id',
                ),
                'bank_transaction_id' => self::integer(
                    $row,
                    'bank_transaction_id',
                ),
                'cash_document_id' => null,
                'date' => self::date($row, 'posted_at'),
                'amount_minor' => $amount,
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'description' => self::nullableText(
                    $row,
                    'description',
                ),
                'available_match_minor' => $amount - $matched,
                'available_reversal_minor' => $amount - $reversed,
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function cashEvidence(
        int $supplierId,
        string $from,
        string $to,
        array $filters,
        int $limit,
    ): array {
        $search = trim((string) ($filters['search'] ?? ''));
        $currency = trim((string) ($filters['currency'] ?? ''));
        $direction = trim((string) ($filters['direction'] ?? ''));
        $usage = $filters['usage'] ?? null;
        $documentId = isset($filters['cash_document_id'])
            ? (int) $filters['cash_document_id']
            : 0;
        $where = '';
        $having = '';
        if ($currency !== '') {
            $where .= ' AND cash_document.currency_code = ?';
        }
        if ($direction === 'outgoing') {
            $where .= ' AND cash_document.doc_type = "out"';
        } elseif ($direction === 'incoming') {
            $where .= ' AND cash_document.doc_type <> "out"';
        }
        // Storno pokladního dokladu se váže na TENTÝŽ doklad, ne na jiný se
        // stejnou částkou — proto přesný filtr, ne hledání podle textu.
        if ($documentId > 0) {
            $where .= ' AND cash_document.id = ?';
        }
        if ($search !== '') {
            $where .= ' AND (cash_document.doc_number LIKE ?
                             OR cash_document.description LIKE ?)';
        }
        if ($usage === 'match') {
            $where .= ' AND cash_document.status = "posted"';
            $having = ' HAVING CAST(ROUND(ABS(cash_document.total_amount) * 100) AS SIGNED)
                        - COALESCE(SUM(CASE WHEN payroll_match.event_kind = "matched"
                                            THEN ABS(payroll_match.amount_minor)
                                            ELSE 0 END), 0) > 0';
        } elseif ($usage === 'reversal') {
            $where .= ' AND cash_document.status = "reversed"';
            $having = ' HAVING CAST(ROUND(ABS(cash_document.total_amount) * 100) AS SIGNED)
                        - COALESCE(SUM(CASE WHEN payroll_match.event_kind = "reversed"
                                            THEN ABS(payroll_match.amount_minor)
                                            ELSE 0 END), 0) > 0';
        }
        $statement = $this->db->pdo()->prepare(
            'SELECT cash_document.id AS cash_document_id,
                    cash_document.issue_date,
                    CAST(ROUND(ABS(cash_document.total_amount) * 100)
                      AS SIGNED) AS amount_minor,
                    cash_document.currency_code,
                    CASE WHEN cash_document.doc_type = "out"
                         THEN "outgoing" ELSE "incoming" END AS direction,
                    cash_document.status, cash_document.doc_number,
                    cash_document.description,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "matched"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS matched_minor,
                    COALESCE(SUM(
                      CASE WHEN payroll_match.event_kind = "reversed"
                           THEN ABS(payroll_match.amount_minor) ELSE 0 END
                    ), 0) AS reversed_minor
               FROM cash_documents cash_document
               LEFT JOIN payroll_payment_matches payroll_match
                 ON payroll_match.supplier_id = cash_document.supplier_id
                AND payroll_match.cash_document_id = cash_document.id
              WHERE cash_document.supplier_id = ?
                AND cash_document.issue_date >= ?
                AND cash_document.issue_date < ?
                AND cash_document.total_amount <> 0
                AND cash_document.status IN ("posted", "reversed")
                AND cash_document.purpose = "other"
                AND cash_document.invoice_id IS NULL
                AND cash_document.purchase_invoice_id IS NULL
                AND cash_document.invoice_payment_id IS NULL'
            . $where
            . ' GROUP BY cash_document.id, cash_document.issue_date,
                       cash_document.total_amount,
                       cash_document.currency_code,
                       cash_document.doc_type, cash_document.status,
                       cash_document.doc_number,
                       cash_document.description'
            . $having
            . ' ORDER BY cash_document.issue_date DESC,
                       cash_document.id DESC
              LIMIT ?',
        );
        $position = 0;
        $statement->bindValue(++$position, $supplierId, PDO::PARAM_INT);
        $statement->bindValue(++$position, $from, PDO::PARAM_STR);
        $statement->bindValue(++$position, $to, PDO::PARAM_STR);
        if ($currency !== '') {
            $statement->bindValue(++$position, $currency, PDO::PARAM_STR);
        }
        if ($documentId > 0) {
            $statement->bindValue(++$position, $documentId, PDO::PARAM_INT);
        }
        if ($search !== '') {
            $term = self::likeTerm($search);
            $statement->bindValue(++$position, $term, PDO::PARAM_STR);
            $statement->bindValue(++$position, $term, PDO::PARAM_STR);
        }
        $statement->bindValue(++$position, $limit, PDO::PARAM_INT);
        $statement->execute();
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $rawRow) {
            $row = self::row($rawRow, 'pokladní důkaz');
            $amount = self::integer($row, 'amount_minor');
            $matched = self::integer($row, 'matched_minor');
            $reversed = self::integer($row, 'reversed_minor');
            if ($amount <= 0 || $matched < 0 || $matched > $amount
                || $reversed < 0 || $reversed > $amount
            ) {
                throw new \UnexpectedValueException(
                    'Využití pokladního důkazu je mimo povolené meze.',
                );
            }
            $result[] = [
                'kind' => 'cash',
                'bank_statement_id' => null,
                'bank_transaction_id' => null,
                'cash_document_id' => self::integer(
                    $row,
                    'cash_document_id',
                ),
                'date' => self::date($row, 'issue_date'),
                'amount_minor' => $amount,
                'currency_code' => self::currency(
                    $row,
                    'currency_code',
                ),
                'direction' => self::enum(
                    $row,
                    'direction',
                    ['outgoing', 'incoming'],
                ),
                'status' => self::enum(
                    $row,
                    'status',
                    ['posted', 'reversed'],
                ),
                'reference' => self::nullableText(
                    $row,
                    'doc_number',
                ),
                'description' => self::nullableText(
                    $row,
                    'description',
                ),
                'available_match_minor' => $amount - $matched,
                'available_reversal_minor' => $amount - $reversed,
            ];
        }

        return $result;
    }

    /** @return array{string,string} */
    private function periodRange(string $period): array
    {
        if (preg_match(
            '/^(20[0-9]{2}|21[0-9]{2})-(0[1-9]|1[0-2])$/D',
            $period,
        ) !== 1) {
            throw new \InvalidArgumentException(
                'Mzdové období musí mít tvar RRRR-MM.',
            );
        }
        $from = new \DateTimeImmutable($period . '-01');
        return [
            $from->format('Y-m-d'),
            $from->modify('first day of next month')->format('Y-m-d'),
        ];
    }

    /**
     * @return array{string,string}|null
     */
    private function evidenceRange(
        int $supplierId,
        string $periodFrom,
        string $periodTo,
    ): ?array {
        $statement = $this->db->pdo()->prepare(
            'SELECT MIN(liability.due_on) AS evidence_from
               FROM payroll_payment_liabilities liability
               JOIN payroll_run_revisions revision
                 ON revision.supplier_id = liability.supplier_id
                AND revision.id = liability.revision_id
               JOIN payroll_runs run
                 ON run.supplier_id = revision.supplier_id
                AND run.id = revision.run_id
              WHERE liability.supplier_id = ?
                AND run.period_start >= ?
                AND run.period_start < ?',
        );
        $statement->execute([$supplierId, $periodFrom, $periodTo]);
        $row = self::row(
            $statement->fetch(PDO::FETCH_ASSOC),
            'rozsah platebních důkazů',
        );
        $earliestDueOn = self::nullableText($row, 'evidence_from');
        if ($earliestDueOn === null) {
            return null;
        }
        $from = new \DateTimeImmutable(
            min($periodFrom, $earliestDueOn),
        );
        $to = new \DateTimeImmutable('tomorrow');
        if ($to <= $from) {
            return null;
        }

        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    /** @return array<string,mixed> */
    private static function row(mixed $value, string $context): array
    {
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

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $field): int
    {
        $value = $row[$field] ?? null;
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }
        $result = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($result)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není celé číslo.",
            );
        }

        return $result;
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
    private static function text(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není neprázdný text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function nullableText(
        array $row,
        string $field,
    ): ?string {
        $value = $row[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není text.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function date(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není datum.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function currency(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není měna.",
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $row
     * @param non-empty-list<string> $allowed
     */
    private static function enum(
        array $row,
        string $field,
        array $allowed,
    ): string {
        $value = self::text($row, $field);
        if (!in_array($value, $allowed, true)) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není povolená.",
            );
        }

        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function hash(array $row, string $field): string
    {
        $value = self::text($row, $field);
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(
                "Hodnota {$field} není SHA-256.",
            );
        }

        return $value;
    }
}
