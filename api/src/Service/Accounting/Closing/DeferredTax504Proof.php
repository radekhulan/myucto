<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingFrameworkRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingRoleProfile, JournalLineComparison, PostingException};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Accounting\Dimension\{DimensionAccountRouter, DimensionRuleService, DimensionStamper};
use PDO;

final class DeferredTax504Proof
{
    private readonly string $savepoint;
    private function __construct(private readonly Connection $db, public readonly array $stepRow, public readonly array $pending,
        public readonly array $assessment, public readonly array $batches, public readonly array $sourceLineIds,
        public readonly array $header = [], public readonly array $lines = [], private readonly array $retained = [], private readonly ?int $batchIndex = null) {
        $this->savepoint = 'deferred504_' . bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    public static function forPending(Connection $db, JournalEntryRepository $journal, int $supplierId, int $periodId, int $expectedVersion): self
    {
        (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($db))->lockContext($supplierId);
        $pdo = $db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM accounting_periods WHERE supplier_id=? AND id=? FOR UPDATE');
        $stmt->execute([$supplierId, $periodId]);
        $period = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($period === false || $period['status'] !== 'closing') throw self::changed();
        $framework = new AccountingFrameworkService($db, new AccountingFrameworkRepository($db));
        if (array_values(array_unique($framework->forInterval($supplierId, $period['starts_on'], $period['ends_on'], true))) !== [AccountingFrameworkService::CZ_504]) throw self::changed();
        $stmt = $pdo->prepare("SELECT * FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='deferred_tax' FOR UPDATE");
        $stmt->execute([$supplierId, $periodId]);
        $step = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($step === false) throw self::changed();
        $step['decoded_payload'] = json_decode((string) $step['payload'], true, flags: JSON_THROW_ON_ERROR);
        $pending = $step['decoded_payload']['deferred504_pending'] ?? null;
        if (!is_array($pending) || ($pending['expected_version'] ?? null) !== $expectedVersion
            || !in_array($pending['status'] ?? null, ['pending', 'written'], true)) throw self::changed();
        $written = $pending['status'] === 'written';
        if ((int) $period['row_version'] !== $expectedVersion + ($written ? 1 : 0)) throw self::changed();
        if (!is_string($pending['body']['source_hash'] ?? null) || !hash_equals($pending['body']['source_hash'], DeferredTax504Repository::sourceHash($db, $supplierId, $periodId, true))) throw self::changed();
        $assessment = DeferredTax504Builder::assessment($pending['body']);
        if (!$assessment['enabled']) throw self::changed();
        $ids = DeferredTax504Service::assertAccounts($db, $supplierId, DeferredTax504Service::accountDate($period, $assessment));
        if ($ids !== $pending['account_ids']) throw self::changed();
        $state = (new DeferredTax504Repository($db, $journal))->state($supplierId, $period['ends_on']);
        $read = new LegalClosing504Repository($db, $journal);
        $batches = [];
        if ($written) {
            $record = $step['decoded_payload']['deferred504_decisions'][$pending['record_index'] ?? -1] ?? null;
            if (!is_array($record) || $record['body'] !== $pending['body'] || $record['assessment'] !== $assessment) throw self::changed();
            foreach ($record['batches'] as $batch) {
                $entry = $journal->findForUpdate((int) $batch['entry_id'], $supplierId);
                if ($entry === null || $entry['reversed_by'] !== null || (int) $entry['row_version'] !== $batch['entry_version']) throw self::changed();
                $header = self::header($supplierId, $period, $pending, $batch['phase']);
                foreach ($header as $key => $value) if ((string) ($entry[$key] ?? '') !== (string) ($value ?? '')) throw self::changed();
                $batches[] = $batch;
            }
        } else {
            $current = $state['balances'];
            if ($assessment['method']['first_adoption']) {
                if ($state['decisions'] !== [] || $state['rows'] !== [] || $framework->forDateForUpdate($supplierId, $period['starts_on']) !== AccountingFrameworkService::CZ_504) throw self::changed();
                $opening = DeferredTax504Builder::movements($assessment, [], 'opening');
                if ($opening !== []) $batches[] = self::batchData($supplierId, $period, $pending, 'opening', $read->resolveAccounts($supplierId, $opening), []);
                $current = array_values($assessment['opening']['cells']);
            }
            $lines = DeferredTax504Builder::movements($assessment, $current, 'closing');
            if ($lines !== []) $batches[] = self::batchData($supplierId, $period, $pending, 'closing', $read->resolveAccounts($supplierId, $lines), $current);
            self::assertNewBatches($db, $supplierId, $batches);
        }
        return new self($db, $step, $pending, $assessment, $batches, $state['source_line_ids']);
    }

    private static function assertNewBatches(Connection $db, int $supplierId, array $batches): void
    {
        $stmt = $db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id=? FOR UPDATE');
        $stmt->execute([$supplierId]);
        $lockedUntil = $stmt->fetchColumn();
        foreach ($batches as $batch) {
            $date = $batch['header']['entry_date'];
            $accounts = array_column((new \MyInvoice\Repository\AccountDefinitionRepository($db))->listForDate($supplierId, $date, true), null, 'id');
            $parents = array_filter(array_column($accounts, 'parent_id'));
            if ($lockedUntil && $date <= $lockedUntil) throw new PostingException('date_locked', 'Datum zápisu leží v uzamčené části účetnictví.', 422);
            foreach ($batch['lines'] as $line) {
                $account = $accounts[$line['account_id']] ?? null;
                if ($account === null || !$account['is_active'] || $account['account_type'] === 'offbalance' || in_array($account['id'], $parents, true)) {
                    throw new ClosingException('deferred_tax_accounts_required', 'Účet vlastní metody chybí, není aktivní nebo má analytické členění.', 422);
                }
            }
            $newLines = array_values(array_diff_key($batch['lines'], $batch['retained']));
            if ($newLines === []) continue;
            (new DimensionRuleService($db))->check($supplierId, 'manual', $newLines, $date);
            $stamper = new DimensionStamper($db);
            $prepared = $stamper->stamp($supplierId, 'manual', null, $newLines, null, $date);
            $prepared = $stamper->linkColumns($supplierId, (new DimensionAccountRouter($db))->apply($supplierId, $prepared, $date));
            if (!JournalLineComparison::sameLines($newLines, $prepared)) {
                throw new ClosingException('deferred_tax_account_mapping_changed', 'Výchozí dimenze nebo kontace mění potvrzenou metodu. Doplňte výslovné společné alokace.', 422);
            }
        }
    }

    private static function batchData(int $supplierId, array $period, array $pending, string $phase, array $lines, array $before): array
    {
        $retained = [];
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        $capacities = [];
        foreach ($before as $cell) $capacities[$cell['kind'] . ':' . LegalClosing504Builder::cellKey($cell)] = $cell['balance_cents'];
        foreach (array_chunk($lines, 2) as $pairIndex => $pair) {
            $kind = null;
            if ($pair[0]['account_code'] === $profile->account('deferred_tax.liability')) $kind = 'liability';
            elseif ($pair[1]['account_code'] === $profile->account('deferred_tax.asset')) $kind = 'asset';
            if ($kind !== null && $phase === 'closing') {
                $key = $kind . ':' . LegalClosing504Builder::cellKey($pair[0]);
                $cents = ProfitDistribution504Builder::allocationCents($pair[0]['amount']);
                if (($capacities[$key] ?? 0) < $cents) throw self::changed();
                $capacities[$key] -= $cents;
                foreach ([0, 1] as $offset) $retained[$pairIndex * 2 + $offset] = ['dimensions' => $pair[$offset]['dimensions']];
            }
        }
        foreach ($lines as $index => &$line) {
            $line['line_no'] = $index;
            foreach ($line['dimensions'] as $type => $value) $line['dimension_origins'][$type] = ['origin' => 'module', 'ref' => 'deferred_tax:' . $period['id']];
        }
        unset($line);
        return ['phase' => $phase, 'header' => self::header($supplierId, $period, $pending, $phase), 'lines' => $lines, 'retained' => $retained];
    }

    private static function header(int $supplierId, array $period, array $pending, string $phase): array
    {
        if (!in_array($phase, ['opening', 'closing'], true) || !is_string($pending['documents'][$phase] ?? null)
            || $pending['documents'][$phase] === '' || !is_string($pending['posted_at'] ?? null)) throw self::changed();
        $date = $phase === 'opening' ? $period['starts_on'] : $period['ends_on'];
        return ['supplier_id' => $supplierId, 'period_id' => (int) $period['id'], 'entry_date' => $date, 'document_date' => $date,
            'source_type' => 'manual', 'source_id' => null, 'document_no' => $pending['documents'][$phase],
            'description' => $phase === 'opening' ? 'První zavedení vlastní metody odložené daně' : 'Odložená daň podle vlastní metody',
            'posted_at' => $pending['posted_at'], 'posted_by' => $pending['posted_by'] ?? null];
    }

    public function batch(int $index): self
    {
        $batch = $this->batches[$index] ?? throw self::changed();
        if (!isset($batch['header'], $batch['lines'], $batch['retained'])) throw self::changed();
        return new self($this->db, $this->stepRow, $this->pending, $this->assessment, [], $this->sourceLineIds, $batch['header'], $batch['lines'], $batch['retained'], $index);
    }

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw self::changed();
        try { $db->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint); }
        catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) throw self::changed();
            throw $e;
        }
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Deferred tax proofs cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Deferred tax proofs cannot be restored.'); }

    public function indices(Connection $db, array $header, array $lines): array
    {
        $this->assertConnection($db);
        if ($this->batchIndex === null) throw self::changed();
        $journal = new JournalEntryRepository($db, new \MyInvoice\Service\ActivityLogger($db));
        $current = self::forPending($db, $journal, (int) $this->stepRow['supplier_id'], (int) $this->stepRow['period_id'], (int) $this->pending['expected_version']);
        $batch = $current->batches[$this->batchIndex] ?? null;
        if ($batch === null || $header !== $this->header || $lines !== $this->lines
            || $batch['header'] !== $this->header || $batch['lines'] !== $this->lines || $batch['retained'] !== $this->retained
            || $current->pending !== $this->pending || $current->assessment !== $this->assessment
            || $current->sourceLineIds !== $this->sourceLineIds || $current->stepRow !== $this->stepRow) throw self::changed();
        return array_fill_keys(array_keys($this->retained), true);
    }

    public static function changed(): ClosingException { return DeferredTax504Repository::changed(); }
}
