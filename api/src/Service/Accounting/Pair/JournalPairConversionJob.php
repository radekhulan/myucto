<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Pair;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Service\Accounting\EntryForm\EntryForm;
use MyInvoice\Service\Accounting\EntryForm\EntryFormService;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Výpočetní část převodu starších zápisů na dvojice: jeden běh
 * `journal_pair_conversion_runs` (náhled nanečisto i provedení). Spouští ho worker
 * `api/bin/journal-pair-conversion-worker.php`; zakládání běhů, stav a zrušení drží
 * {@see JournalPairConversionService}.
 *
 * Zápisy se procházejí po dávkách {@see BATCH} podle (entry_date, id) po indexu
 * `idx_je_supplier_entry_date`; řádky, vazby a vlastníci se dotahují jedním dotazem
 * na dávku. Celý rok nikdy neleží v jednom PHP poli.
 *
 * Klasifikace zaúčtovaného zápisu ({@see classifyBatch()}):
 *  - `paired`       už má dvojice (aspoň jeden řádek s `pair_no`);
 *  - `composite_mn` JournalPairer ho neumí rozložit (M : N, stejný účet, smíšené
 *                   červené storno); zůstává složený a je k ruční úpravě;
 *  - `ineligible`   rozložit by šel (1 : N), ale převod nesmí jeho řádky fyzicky dělit:
 *                   uzavřené nebo zamčené období, mzdový zápis (trigger neměnnosti),
 *                   typovaný vlastník (SVJ, neziskovky, 504, sdružené rozúčtování),
 *                   stornovaný zápis nebo protizápis, vazba rozpadu
 *                   `journal_settlement_allocations` nebo saldokontní okruh na děleném
 *                   řádku (klíčované `line_no`). Zobrazení ho smí rozložit za běhu;
 *  - `auto`         převod ho rozdělí přes JournalEntryRepository::splitIntoPairs().
 *
 * Náhled nezapisuje nic kromě řádku běhu. Provedení dělí jen `auto` zápisy, jejichž
 * datum leží ve formě `single` (invariant I6 jinak `pair_no` zakazuje), každý zápis ve
 * vlastní transakci pod zámkem období a s novou kontrolou nad čerstvými daty; ostatním
 * zapíše stav do `journal_entry_pair_status`. Řádky se nikdy nemažou a uzavřená období
 * zůstávají beze změny. Opakovaný běh je bezpečný (rozdělené zápisy jsou `paired`),
 * zrušení se kontroluje po každé dávce.
 */
final class JournalPairConversionJob
{
    public const BATCH = 500;
    public const SAMPLE_LIMIT = 100;

    public const CLASS_PAIRED = 'paired';
    public const CLASS_AUTO = 'auto';
    public const CLASS_COMPOSITE_MN = 'composite_mn';
    public const CLASS_INELIGIBLE = 'ineligible';

    public const REASON_NO_LINES = 'no_lines';
    public const REASON_CLOSED_PERIOD = 'closed_period';
    public const REASON_DATE_LOCKED = 'date_locked';
    public const REASON_PAYROLL = 'payroll';
    public const REASON_TYPED_OWNER = 'typed_owner';
    public const REASON_REVERSED = 'reversed';
    public const REASON_REVERSAL = 'reversal';
    public const REASON_SETTLEMENT_ALLOCATION = 'settlement_allocation';
    public const REASON_SALDO_PAIRING = 'saldo_pairing';

    public const AUDIT_CONVERTED = 'accounting.pairs_converted';

    /** Zápisy typovaných zapisovačů (SVJ, neziskovky): mají vlastní důkazy nad řádky. */
    private const TYPED_SOURCE_TYPES = [
        'unit_charge', 'unit_payment', 'unit_payment_revision',
        'property_service_settlement', 'property_service_lifecycle', 'property_funding',
        'nonprofit_capital', 'nonprofit_membership',
    ];

    /**
     * Tabulky s cizím klíčem na zápis nebo řádek deníku, které NEJSOU vlastníkem řádků:
     * doklady a deníkové přílohy jen ukazují na zápis, rozdělení řádků je nerozbije.
     * Každá jiná odkazující tabulka (i budoucí) se bere jako typovaný vlastník, takže
     * nový modul s vlastním důkazem nad řádky převod nerozbije ani omylem.
     * Vazby klíčované `line_no` řeší převod zvlášť (jen u dělených řádků).
     */
    private const GENERIC_REFERENCES = [
        'journal_entries', 'journal_entry_lines', 'journal_settlement_allocations',
        'journal_line_pairing', 'journal_line_pairing_items', 'journal_entry_pair_status',
        'journal_pair_reversals', 'journal_document_postings', 'journal_entry_attachments',
        'journal_entry_document_links', 'journal_entry_notes', 'journal_entry_line_dimensions',
        'journal_entry_line_dimension_splits', 'cash_documents', 'invoice_settlements', 'other_items',
        'stock_documents', 'assets', 'internal_documents', 'offset_agreements', 'gopay_movements',
        'gopay_clearings', 'bank_posting_suggestions', 'fuelings', 'credit_card_accounts',
        'accounting_reclassification_items',
    ];

    /** Odkazy na zápis/řádek bez cizího klíče (vlastníci, které information_schema neukáže). */
    private const EXTRA_OWNER_REFERENCES = [
        ['joint_allocation_batches', 'journal_entry_id', 'entry'],
        ['joint_allocation_cancellations', 'reversal_entry_id', 'entry'],
        ['unit_payment_operations', 'read_source_entry_id', 'entry'],
        ['nonprofit_tax_saving_events', 'cost_line_id', 'line'],
        ['nonprofit_tax_assessment_gift_elections', 'origin_line_id', 'line'],
    ];

    /** Saldokontní a rozpadové vazby klíčované (entry_id, line_no). */
    private const LINE_NO_REFERENCES = [
        'journal_settlement_allocations' => self::REASON_SETTLEMENT_ALLOCATION,
        'journal_line_pairing_items'     => self::REASON_SALDO_PAIRING,
        'journal_line_pairing'           => self::REASON_SALDO_PAIRING,
    ];

    /** @var list<array{table:string, column:string, level:'entry'|'line', reason:string, tenant:bool}>|null */
    private ?array $owners = null;

    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly EntryFormService $entryForms,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Zpracuje čekající běh. Běh, který už někdo převzal nebo skončil, nechá být.
     * Programová chyba (LogicException) i odvolaná transakce běh označí `failed`
     * a letí dál; chyba jednoho zápisu běh nezastaví (počítá se do `failed`).
     */
    public function run(int $runId): void
    {
        $pdo = $this->db->pdo();
        $claim = $pdo->prepare("UPDATE journal_pair_conversion_runs SET status = 'running' WHERE id = ? AND status = 'queued'");
        $claim->execute([$runId]);
        if ($claim->rowCount() !== 1) {
            return;
        }
        $stmt = $pdo->prepare('SELECT * FROM journal_pair_conversion_runs WHERE id = ?');
        $stmt->execute([$runId]);
        $run = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) {
            return;
        }

        try {
            $result = $this->process(
                $runId,
                (int) $run['supplier_id'],
                (string) $run['mode'] === 'apply',
                $run['date_from'] !== null ? (string) $run['date_from'] : null,
                $run['date_to'] !== null ? (string) $run['date_to'] : null,
            );
        } catch (\Throwable $e) {
            if (!$pdo->inTransaction() || !TransactionFailure::aborted($e)) {
                $pdo->prepare(
                    "UPDATE journal_pair_conversion_runs SET status = 'failed', error = ?, finished_at = NOW()
                      WHERE id = ? AND status = 'running'"
                )->execute([mb_substr($e->getMessage(), 0, 255), $runId]);
            }
            throw $e;
        }

        // Zrušený běh si nechá stav `cancelled`, ale dostane počty a vzorek do chvíle zastavení.
        $pdo->prepare(
            "UPDATE journal_pair_conversion_runs
                SET status = IF(status = 'running', 'done', status), counts = ?, sample = ?,
                    finished_at = COALESCE(finished_at, NOW())
              WHERE id = ? AND status IN ('running', 'cancelled')"
        )->execute([self::json($result['counts']), self::json($result['sample']), $runId]);
    }

    /**
     * @return array{counts: array<string,mixed>, sample: list<array<string,mixed>>}
     */
    private function process(int $runId, int $supplierId, bool $apply, ?string $from, ?string $to): array
    {
        $pdo = $this->db->pdo();
        $this->entryForms->forget($supplierId);
        $history = $this->entryForms->history($supplierId);

        $counts = [
            'total'        => $this->countCandidates($supplierId, $from, $to),
            'processed'    => 0,
            self::CLASS_PAIRED       => 0,
            self::CLASS_AUTO         => 0,
            self::CLASS_COMPOSITE_MN => 0,
            self::CLASS_INELIGIBLE   => 0,
            'composite_mn_reasons' => [],
            'ineligible_reasons'   => [],
            'by_source_type'       => [],
        ];
        if ($apply) {
            $counts['converted'] = 0;
            $counts['skipped_composite_form'] = 0;
            $counts['failed'] = 0;
        }
        $manual = [];
        $blocked = [];

        $cursor = null;
        while (true) {
            $entries = $this->nextBatch($supplierId, $from, $to, $cursor);
            if ($entries === []) {
                break;
            }
            $last = end($entries);
            $cursor = [(string) $last['entry_date'], (int) $last['id']];

            foreach ($this->classifyBatch($supplierId, $entries) as $entryId => $item) {
                $entry = $entries[$entryId];
                $class = $item['class'];
                $counts['processed']++;
                $counts[$class]++;
                $source = (string) $entry['source_type'];
                $counts['by_source_type'][$source][$class] = ($counts['by_source_type'][$source][$class] ?? 0) + 1;
                if ($class === self::CLASS_COMPOSITE_MN || $class === self::CLASS_INELIGIBLE) {
                    $key = $class . '_reasons';
                    $counts[$key][$item['reason']] = ($counts[$key][$item['reason']] ?? 0) + 1;
                    $row = self::sampleRow($entry, $class, $item['reason']);
                    if ($class === self::CLASS_COMPOSITE_MN && count($manual) < self::SAMPLE_LIMIT) {
                        $manual[] = $row;
                    } elseif ($class === self::CLASS_INELIGIBLE && count($blocked) < self::SAMPLE_LIMIT) {
                        $blocked[] = $row;
                    }
                }
                if (!$apply) {
                    continue;
                }
                if ($class === self::CLASS_COMPOSITE_MN || $class === self::CLASS_INELIGIBLE) {
                    $this->storeStatus($supplierId, $entryId, $class, $item['reason'], $runId);
                    continue;
                }
                if ($class !== self::CLASS_AUTO) {
                    continue;
                }
                if (EntryFormService::formFromHistory($history, (string) $entry['entry_date']) !== EntryForm::Single) {
                    $counts['skipped_composite_form']++;
                    continue;
                }
                $ownsTransaction = !$pdo->inTransaction();
                try {
                    $outcome = $this->convertEntry($runId, $supplierId, $entryId);
                } catch (\RuntimeException $e) {
                    // Odvolaná transakce volajícího (savepoint) nejde přeskočit: dostane ji vlastník.
                    if (!$ownsTransaction && TransactionFailure::aborted($e)) {
                        throw $e;
                    }
                    $counts['failed']++;
                    if (count($blocked) < self::SAMPLE_LIMIT) {
                        $blocked[] = self::sampleRow($entry, 'failed', $e instanceof JournalPairException ? $e->errorCode : 'error')
                            + ['error' => mb_substr($e->getMessage(), 0, 255)];
                    }
                    continue;
                }
                if ($outcome['class'] === self::CLASS_AUTO) {
                    $counts['converted']++;
                } else {
                    // Mezi náhledem dávky a zámkem se zápis změnil: platí čerstvé posouzení.
                    $counts[self::CLASS_AUTO]--;
                    $counts[$outcome['class']]++;
                    if ($outcome['class'] !== self::CLASS_PAIRED) {
                        $key = $outcome['class'] . '_reasons';
                        $counts[$key][$outcome['reason']] = ($counts[$key][$outcome['reason']] ?? 0) + 1;
                    }
                }
            }

            $this->saveProgress($runId, $counts);
            if ($this->cancelled($runId)) {
                $counts['cancelled'] = true;
                break;
            }
        }

        return ['counts' => $counts, 'sample' => array_slice([...$manual, ...$blocked], 0, 2 * self::SAMPLE_LIMIT)];
    }

    /**
     * Rozdělí jeden zápis ve vlastní transakci. Zámky ve stejném pořadí jako zaúčtování
     * (období → nastavení firmy → zápis), posouzení se opakuje nad čerstvými daty.
     *
     * @return array{class:string, reason:?string}
     */
    private function convertEntry(int $runId, int $supplierId, int $entryId): array
    {
        $pdo = $this->db->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT pair_conversion_entry');
        }
        try {
            $entry = $pdo->prepare(
                'SELECT id, period_id, entry_date, document_no, source_type, source_id, reversed_by, row_version
                   FROM journal_entries WHERE supplier_id = ? AND id = ? AND posted_at IS NOT NULL'
            );
            $entry->execute([$supplierId, $entryId]);
            $row = $entry->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new JournalPairException('entry_not_found', 'Zápis už neexistuje.', 409);
            }
            $period = $pdo->prepare('SELECT status FROM accounting_periods WHERE supplier_id = ? AND id = ? FOR UPDATE');
            $period->execute([$supplierId, (int) $row['period_id']]);
            $period->fetchColumn();
            $lock = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
            $lock->execute([$supplierId]);
            $lock->fetchColumn();
            $this->loadSupplierContext($supplierId);
            if ($this->entryForms->formAt($supplierId, (string) $row['entry_date'], currentRead: true) !== EntryForm::Single) {
                throw new JournalPairException(
                    JournalPairInvariant::COMPOSITE_FORM,
                    JournalPairInvariant::message(JournalPairInvariant::COMPOSITE_FORM),
                    409,
                );
            }
            $entry = $pdo->prepare(
                'SELECT id, period_id, entry_date, document_no, source_type, source_id, reversed_by, row_version
                   FROM journal_entries WHERE supplier_id = ? AND id = ? FOR UPDATE'
            );
            $entry->execute([$supplierId, $entryId]);
            $row = $entry->fetch(PDO::FETCH_ASSOC);

            $item = $this->classifyBatch($supplierId, [$entryId => $row], forUpdate: true)[$entryId];
            if ($item['class'] !== self::CLASS_AUTO) {
                if ($item['class'] !== self::CLASS_PAIRED) {
                    $this->storeStatus($supplierId, $entryId, $item['class'], $item['reason'], $runId);
                }
            } else {
                $this->journal->splitIntoPairs($entryId, $supplierId, (int) $row['row_version'], $item['lines']);
                $pdo->prepare('DELETE FROM journal_entry_pair_status WHERE supplier_id = ? AND entry_id = ?')
                    ->execute([$supplierId, $entryId]);
                $this->logger->log(
                    self::AUDIT_CONVERTED,
                    null,
                    'journal_entry',
                    $entryId,
                    [
                        'run_id'      => $runId,
                        'pairs'       => intdiv(count($item['lines']), 2),
                        'lines_before' => $item['lines_before'],
                        'lines_after'  => count($item['lines']),
                    ],
                    supplierId: $supplierId,
                );
            }

            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT pair_conversion_entry');
            }
        } catch (\Throwable $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$ownsTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT pair_conversion_entry');
                $pdo->exec('RELEASE SAVEPOINT pair_conversion_entry');
            }
            throw $e;
        }

        return ['class' => $item['class'], 'reason' => $item['reason']];
    }

    /**
     * Posoudí dávku zápisů (hlavičky indexované id). Jeden dotaz na řádky, jeden na
     * protizápisy, jeden na každou vazbu a vlastníka.
     *
     * @param array<int, array<string,mixed>> $entries
     * @return array<int, array{class:string, reason:?string, lines:list<array<string,mixed>>, lines_before:int}>
     */
    private function classifyBatch(int $supplierId, array $entries, bool $forUpdate = false): array
    {
        if ($entries === []) {
            return [];
        }
        $pdo = $this->db->pdo();
        $ids = array_map('intval', array_keys($entries));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $stmt = $pdo->prepare(
            "SELECT id, entry_id, account_id, side, amount, is_red_storno, currency_code, fx_rate, amount_foreign,
                    cost_center, project_id, line_no, pair_no, line_text
               FROM journal_entry_lines
              WHERE supplier_id = ? AND entry_id IN ({$marks})
              ORDER BY entry_id, line_no, id{$lock}"
        );
        $stmt->execute([$supplierId, ...$ids]);
        $lines = [];
        $lineIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $lines[(int) $line['entry_id']][] = $line;
            $lineIds[] = (int) $line['id'];
        }

        $reversals = [];
        $stmt = $pdo->prepare("SELECT reversed_by FROM journal_entries WHERE supplier_id = ? AND reversed_by IN ({$marks})");
        $stmt->execute([$supplierId, ...$ids]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $reversals[(int) $id] = true;
        }

        $lineNoRefs = [];
        foreach (self::LINE_NO_REFERENCES as $table => $reason) {
            $stmt = $pdo->prepare("SELECT entry_id, line_no FROM {$table} WHERE supplier_id = ? AND entry_id IN ({$marks})");
            $stmt->execute([$supplierId, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ref) {
                $lineNoRefs[(int) $ref['entry_id'] . ':' . (int) $ref['line_no']] ??= $reason;
            }
        }

        [$entryOwners, $lineOwners] = $this->ownerReferences($supplierId, $ids, $lineIds);

        $out = [];
        foreach ($entries as $entryId => $entry) {
            $entryLines = $lines[(int) $entryId] ?? [];
            $out[(int) $entryId] = $this->classifyEntry($entry, $entryLines, $reversals, $lineNoRefs, $entryOwners, $lineOwners);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $entry
     * @param list<array<string,mixed>> $lines
     * @param array<int,bool> $reversals
     * @param array<string,string> $lineNoRefs
     * @param array<int,string> $entryOwners
     * @param array<int,string> $lineOwners
     * @return array{class:string, reason:?string, lines:list<array<string,mixed>>, lines_before:int}
     */
    private function classifyEntry(array $entry, array $lines, array $reversals, array $lineNoRefs, array $entryOwners, array $lineOwners): array
    {
        $result = static fn (string $class, ?string $reason, array $paired = []): array => [
            'class' => $class, 'reason' => $reason, 'lines' => $paired, 'lines_before' => count($lines),
        ];
        if ($lines === []) {
            return $result(self::CLASS_INELIGIBLE, self::REASON_NO_LINES);
        }
        foreach ($lines as $line) {
            if ($line['pair_no'] !== null) {
                return $result(self::CLASS_PAIRED, null);
            }
        }

        // `line_no` se do párovače záměrně nedává: zachovaný díl nese původní číslo
        // řádku a nové díly čísluje zapisovač za maximem (vazby klíčované line_no).
        $input = array_map(static fn (array $line): array => [
            'id'             => (int) $line['id'],
            'account_id'     => (int) $line['account_id'],
            'side'           => (string) $line['side'],
            'amount'         => (string) $line['amount'],
            'is_red_storno'  => (int) $line['is_red_storno'] === 1,
            'currency_code'  => $line['currency_code'],
            'fx_rate'        => $line['fx_rate'],
            'amount_foreign' => $line['amount_foreign'],
            'cost_center'    => $line['cost_center'],
            'project_id'     => $line['project_id'] !== null ? (int) $line['project_id'] : null,
            'line_text'      => $line['line_text'],
        ], $lines);
        $paired = JournalPairer::pair($input);
        if ($paired['status'] !== JournalPairer::STATUS_PAIRED) {
            return $result(self::CLASS_COMPOSITE_MN, $paired['reason'] ?? JournalPairer::REASON_MANY_TO_MANY);
        }

        $entryId = (int) $entry['id'];
        $reason = $this->blockingReason($entry, $reversals, $entryOwners);
        if ($reason === null) {
            $pieces = array_count_values(array_map(static fn (array $l): int => (int) $l['id'], $paired['lines']));
            foreach ($lines as $line) {
                $lineId = (int) $line['id'];
                if (isset($lineOwners[$lineId])) {
                    $reason = $lineOwners[$lineId];
                    break;
                }
                if (($pieces[$lineId] ?? 0) > 1 && isset($lineNoRefs[$entryId . ':' . (int) $line['line_no']])) {
                    $reason = $lineNoRefs[$entryId . ':' . (int) $line['line_no']];
                    break;
                }
            }
        }
        if ($reason !== null) {
            return $result(self::CLASS_INELIGIBLE, $reason);
        }
        return $result(self::CLASS_AUTO, null, $paired['lines']);
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<int,bool> $reversals
     * @param array<int,string> $entryOwners
     */
    private function blockingReason(array $entry, array $reversals, array $entryOwners): ?string
    {
        $entryId = (int) $entry['id'];
        $context = $this->supplierContext();
        $periodStatus = $context['periods'][(int) $entry['period_id']] ?? null;
        if ($periodStatus !== 'open') {
            return self::REASON_CLOSED_PERIOD;
        }
        $lockedUntil = $context['locked_until'];
        if ($lockedUntil !== null && (string) $entry['entry_date'] <= $lockedUntil) {
            return self::REASON_DATE_LOCKED;
        }
        $source = (string) $entry['source_type'];
        if ($source === 'payroll') {
            return self::REASON_PAYROLL;
        }
        if (in_array($source, self::TYPED_SOURCE_TYPES, true)) {
            return self::REASON_TYPED_OWNER;
        }
        if (isset($entryOwners[$entryId])) {
            return $entryOwners[$entryId];
        }
        if ($entry['reversed_by'] !== null) {
            return self::REASON_REVERSED;
        }
        if (isset($reversals[$entryId])) {
            return self::REASON_REVERSAL;
        }
        return null;
    }

    /** @var array{supplier:int, periods:array<int,string>, locked_until:?string}|null */
    private ?array $context = null;

    /**
     * Stav období a zámek firmy. V dávce se čte jednou; při rozdělení (forUpdate) se
     * čte znovu pod zámkem, proto ho {@see nextBatch()} i {@see convertEntry()} obnovují.
     *
     * @return array{supplier:int, periods:array<int,string>, locked_until:?string}
     */
    private function supplierContext(): array
    {
        if ($this->context === null) {
            throw new \LogicException('Kontext firmy převodu není načtený.');
        }
        return $this->context;
    }

    private function loadSupplierContext(int $supplierId): void
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, status FROM accounting_periods WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $periods = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $periods[(int) $row['id']] = (string) $row['status'];
        }
        $stmt = $pdo->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $locked = $stmt->fetchColumn();
        $this->context = [
            'supplier'     => $supplierId,
            'periods'      => $periods,
            'locked_until' => is_string($locked) && $locked !== '' ? $locked : null,
        ];
    }

    /**
     * @return array<int, array<string,mixed>> hlavičky indexované id, v pořadí (entry_date, id)
     */
    private function nextBatch(int $supplierId, ?string $from, ?string $to, ?array $cursor): array
    {
        $this->loadSupplierContext($supplierId);
        $where = ['supplier_id = ?', 'posted_at IS NOT NULL'];
        $params = [$supplierId];
        if ($from !== null) {
            $where[] = 'entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'entry_date <= ?';
            $params[] = $to;
        }
        if ($cursor !== null) {
            $where[] = '(entry_date > ? OR (entry_date = ? AND id > ?))';
            array_push($params, $cursor[0], $cursor[0], $cursor[1]);
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, supplier_id, period_id, entry_date, document_no, source_type, source_id, reversed_by, row_version
               FROM journal_entries FORCE INDEX (idx_je_supplier_entry_date)
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY entry_date, id
              LIMIT ' . self::BATCH
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    private function countCandidates(int $supplierId, ?string $from, ?string $to): int
    {
        $where = ['supplier_id = ?', 'posted_at IS NOT NULL'];
        $params = [$supplierId];
        if ($from !== null) {
            $where[] = 'entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'entry_date <= ?';
            $params[] = $to;
        }
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*) FROM journal_entries WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Zápisy a řádky, na které ukazuje typovaný vlastník.
     *
     * @param list<int> $entryIds
     * @param list<int> $lineIds
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    private function ownerReferences(int $supplierId, array $entryIds, array $lineIds): array
    {
        $pdo = $this->db->pdo();
        $entryOwners = [];
        $lineOwners = [];
        foreach ($this->owners() as $owner) {
            $ids = $owner['level'] === 'entry' ? $entryIds : $lineIds;
            if ($ids === []) {
                continue;
            }
            foreach (array_chunk($ids, 1000) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $sql = "SELECT DISTINCT `{$owner['column']}` FROM `{$owner['table']}` WHERE `{$owner['column']}` IN ({$marks})";
                $params = $chunk;
                if ($owner['tenant']) {
                    $sql .= ' AND supplier_id = ?';
                    $params[] = $supplierId;
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                    if ($owner['level'] === 'entry') {
                        $entryOwners[(int) $id] ??= $owner['reason'];
                    } else {
                        $lineOwners[(int) $id] ??= $owner['reason'];
                    }
                }
            }
        }
        return [$entryOwners, $lineOwners];
    }

    /**
     * Seznam vlastníků zápisů a řádků podle aktuálního schématu (jednou na instanci).
     *
     * @return list<array{table:string, column:string, level:'entry'|'line', reason:string, tenant:bool}>
     */
    private function owners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }
        $pdo = $this->db->pdo();
        $refs = [];
        $stmt = $pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND REFERENCED_TABLE_NAME IN ('journal_entries', 'journal_entry_lines')
                AND REFERENCED_COLUMN_NAME = 'id'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (in_array($table, self::GENERIC_REFERENCES, true)) {
                continue;
            }
            $refs[$table . '.' . $row['COLUMN_NAME']] = [
                $table,
                (string) $row['COLUMN_NAME'],
                $row['REFERENCED_TABLE_NAME'] === 'journal_entries' ? 'entry' : 'line',
            ];
        }
        foreach (self::EXTRA_OWNER_REFERENCES as [$table, $column, $level]) {
            if ($this->db->hasColumn($table, $column)) {
                $refs[$table . '.' . $column] ??= [$table, $column, $level];
            }
        }
        ksort($refs);

        $owners = [];
        foreach ($refs as [$table, $column, $level]) {
            if (preg_match('/^[a-z0-9_]+$/D', $table . $column) !== 1) {
                continue;
            }
            $owners[] = [
                'table'  => $table,
                'column' => $column,
                'level'  => $level,
                'reason' => str_starts_with($table, 'payroll_') ? self::REASON_PAYROLL : self::REASON_TYPED_OWNER,
                'tenant' => $this->db->hasColumn($table, 'supplier_id'),
            ];
        }
        return $this->owners = $owners;
    }

    private function storeStatus(int $supplierId, int $entryId, string $status, ?string $reason, int $runId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO journal_entry_pair_status (supplier_id, entry_id, status, reason, run_id, assessed_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason),
                                     run_id = VALUES(run_id), assessed_at = VALUES(assessed_at)'
        )->execute([$supplierId, $entryId, $status, $reason !== null ? mb_substr($reason, 0, 64) : null, $runId]);
    }

    /** @param array<string,mixed> $counts */
    private function saveProgress(int $runId, array $counts): void
    {
        $this->db->pdo()->prepare(
            "UPDATE journal_pair_conversion_runs SET counts = ? WHERE id = ? AND status = 'running'"
        )->execute([self::json($counts), $runId]);
    }

    private function cancelled(int $runId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT status FROM journal_pair_conversion_runs WHERE id = ?');
        $stmt->execute([$runId]);
        return $stmt->fetchColumn() === 'cancelled';
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private static function sampleRow(array $entry, string $status, ?string $reason): array
    {
        return [
            'entry_id'    => (int) $entry['id'],
            'entry_date'  => (string) $entry['entry_date'],
            'document_no' => $entry['document_no'] !== null ? (string) $entry['document_no'] : null,
            'source_type' => (string) $entry['source_type'],
            'source_id'   => $entry['source_id'] !== null ? (int) $entry['source_id'] : null,
            'status'      => $status,
            'reason'      => $reason,
        ];
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
