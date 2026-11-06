<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Repository\ClosingCarryReadRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use MyInvoice\Service\Accounting\AccountingPeriodStatus;
use MyInvoice\Service\Accounting\AccountingRoleProfile;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

final class ClosingCarryJournalProof
{
    private readonly string $savepoint;

    private function __construct(
        private readonly Connection $db,
        public readonly array $header,
        public readonly array $lines,
        public readonly array $record,
        public readonly array $stepRow,
        public readonly string $step,
        public readonly array $sourceLineIds,
    ) {
        $this->savepoint = 'closing_carry_' . bin2hex(random_bytes(10));
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    public static function forPending(Connection $db, int $supplierId, int $periodId, string $step, int $expectedVersion): self
    {
        $read = new ClosingCarryReadRepository($db);
        $periods = $read->periods($supplierId);
        $source = $periods[$periodId] ?? null;
        $stepKey = $step;
        if ($source === null || !in_array($step, ['close_books', 'open_next'], true)) throw $read::changed();
        $stepRow = $read->step($supplierId, $periodId, $stepKey);
        $record = $stepRow['decoded_payload']['carry'][$step] ?? null;
        if (!is_array($record) || ($record['expected_version'] ?? null) !== $expectedVersion
            || !in_array($record['status'] ?? null, ['pending', 'written'], true)) throw $read::changed();
        $allowed = ['status', 'expected_version', 'target_period_id', 'entry_date', 'source_hash', 'previous_entry_id',
            'previous_entry_version', 'document_no', 'description', 'posted_at', 'posted_by', 'meta', 'entry_id', 'entry_version', 'source_line_ids'];
        if (array_diff(array_keys($record), $allowed) !== []) throw $read::changed();
        $target = $periods[$record['target_period_id'] ?? 0] ?? null;
        if ($target === null) throw $read::changed();
        $written = $record['status'] === 'written';
        $versionPeriod = $source;
        if ((int) $versionPeriod['row_version'] !== $expectedVersion
            && !($written && (int) $versionPeriod['row_version'] === $expectedVersion + 1)) throw $read::changed();
        $date = (string) ($record['entry_date'] ?? '');
        if ($date < $target['starts_on'] || $date > $target['ends_on']) throw $read::changed();
        if ($step === 'close_books') {
            if ((int) $target['id'] !== $periodId || $date !== $source['ends_on']
                || ($source['status'] !== 'closing' && (!$written || !AccountingPeriodStatus::isClosed((string) $source['status'])))) throw $read::changed();
        } else {
            if ($target['starts_on'] !== (new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d')
                || !in_array($target['status'], ['open'], true)
                || !AccountingPeriodStatus::isClosed((string) $source['status'])
                || $date !== $target['starts_on']) throw $read::changed();
        }
        $framework = new AccountingFrameworkService($db, new AccountingFrameworkRepository($db));
        if ($framework->forDateForUpdate($supplierId, $date) !== AccountingFrameworkService::CZ_504
            || $framework->forDateForUpdate($supplierId, $source['ends_on']) !== AccountingFrameworkService::CZ_504) throw $read::changed();
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        $locked = $db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ? FOR UPDATE');
        $locked->execute([$supplierId]);
        $lockedUntil = $locked->fetchColumn();
        if ($lockedUntil && $date <= $lockedUntil && !$written) throw new PostingException('date_locked', 'Datum zápisu leží v uzamčené části účetnictví.', 422);
        $snapshot = $read->snapshot($supplierId, $source, $target, $step, $date, $profile);
        if (!is_string($record['source_hash'] ?? null) || !hash_equals($record['source_hash'], $snapshot['source_hash'])) throw $read::changed();
        $accounts = $db->pdo()->prepare('SELECT id,account_code,account_type,is_active FROM chart_of_accounts WHERE supplier_id = ? ORDER BY id FOR UPDATE');
        $accounts->execute([$supplierId]);
        $byCode = array_column($accounts->fetchAll(PDO::FETCH_ASSOC), null, 'account_code');
        $lines = [];
        $sum = 0;
        foreach ($snapshot['lines'] as $index => $line) {
            $account = $byCode[$line['account_code']] ?? null;
            if ($account === null || !$account['is_active'] || $account['account_type'] === 'offbalance') throw new PostingException('account_inactive', 'Účet přenosu chybí nebo není aktivní.', 422);
            $cents = \MyInvoice\Service\Accounting\JournalLineAmount::signedCents($line);
            if ($cents <= 0 || !in_array($line['side'], ['debit', 'credit'], true)) throw $read::changed();
            $sum += $line['side'] === 'debit' ? $cents : -$cents;
            $line['account_id'] = (int) $account['id'];
            $line['line_no'] = $index;
            foreach ($line['dimensions'] ?? [] as $type => $value) {
                $line['dimension_origins'][$type] = ['origin' => 'module', 'ref' => 'closing_carry:' . substr($snapshot['source_hash'], 0, 32)];
            }
            $lines[] = $line;
        }
        if ($sum !== 0 || $lines === []) throw $read::changed();
        $values = $db->pdo()->prepare('SELECT v.id, v.type_id FROM dimension_values v JOIN dimension_types t ON t.id = v.type_id
            WHERE v.supplier_id = ? AND t.supplier_id = ? ORDER BY v.id FOR UPDATE');
        $values->execute([$supplierId, $supplierId]);
        $typesByValue = $values->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($lines as $line) {
            foreach ($line['dimensions'] ?? [] as $type => $value) {
                if ((int) ($typesByValue[$value] ?? 0) !== (int) $type) throw $read::changed();
            }
            foreach ($line['dimension_splits'] ?? [] as $type => $parts) {
                foreach ($parts as $value => $share) if ((int) ($typesByValue[$value] ?? 0) !== (int) $type) throw $read::changed();
            }
        }
        $sourceType = ['close_books' => 'closing', 'open_next' => 'opening'][$step];
        $header = ['supplier_id' => $supplierId, 'period_id' => (int) $target['id'], 'entry_date' => $date,
            'document_date' => $date, 'source_type' => $sourceType, 'source_id' => $step === 'open_next' ? (int) $target['id'] : $periodId,
            'document_no' => $record['document_no'] ?? null, 'description' => $record['description'] ?? null,
            'posted_at' => $record['posted_at'] ?? null, 'posted_by' => $record['posted_by'] ?? null];
        if ($header['posted_at'] === null) throw $read::changed();
        return new self($db, $header, $lines, $record, $stepRow, $step, $snapshot['source_line_ids']);
    }

    private function __clone() {}
    public function __serialize(): array { throw new \LogicException('Closing carry proofs cannot be serialized.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Closing carry proofs cannot be restored.'); }

    public function assertConnection(Connection $db): void
    {
        if ($db->pdo() !== $this->db->pdo() || !$db->pdo()->inTransaction()) throw ClosingCarryReadRepository::changed();
        try { $db->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepoint); }
        catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1305) throw ClosingCarryReadRepository::changed();
            throw $e;
        }
        $db->pdo()->exec('SAVEPOINT ' . $this->savepoint);
    }

    public function indices(Connection $db, array $header, array $lines): array
    {
        $this->assertConnection($db);
        $current = self::forPending($db, (int) $this->header['supplier_id'], (int) $this->stepRow['period_id'], $this->step, (int) $this->record['expected_version']);
        if ($header !== $this->header || $lines !== $this->lines
            || $current->header !== $this->header || $current->lines !== $this->lines
            || $current->record !== $this->record || $current->sourceLineIds !== $this->sourceLineIds
            || (int) $current->stepRow['id'] !== (int) $this->stepRow['id']) throw ClosingCarryReadRepository::changed();
        return array_fill_keys(array_keys($lines), true);
    }
}
