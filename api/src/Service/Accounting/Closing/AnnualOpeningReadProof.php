<?php

declare(strict_types=1);
namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClosingCarryReadRepository;
use MyInvoice\Service\Accounting\{AccountingPeriodStatus,AccountingRoleProfile,AccountingFrameworkService,JournalLineComparison};
use PDO;

final class AnnualOpeningReadProof
{
    public function __construct(private readonly Connection $db) {}

    public function matches(int $supplier, int $entryId): bool
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Annual opening verification requires a stable transaction.');
        $read = new ClosingCarryReadRepository($this->db, false);
        $periods = $read->periods($supplier);
        $q = $this->db->pdo()->prepare('SELECT * FROM journal_entries WHERE supplier_id=? AND id=?');
        $q->execute([$supplier,$entryId]);
        $entry = $q->fetch(PDO::FETCH_ASSOC);
        if (!$entry || $entry['source_type'] !== 'opening' || $entry['posted_at'] === null || $entry['reversed_by'] !== null) return false;
        $target = $periods[$entry['period_id']] ?? null;
        if (!$target || (int) $entry['source_id'] !== (int) $target['id'] || $entry['entry_date'] !== $target['starts_on']) return false;
        foreach ($periods as $source) {
            if ($target['starts_on'] !== (new \DateTimeImmutable($source['ends_on']))->modify('+1 day')->format('Y-m-d')
                || !AccountingPeriodStatus::isClosed($source['status'])) continue;
            $step = $read->step($supplier, (int) $source['id'], 'open_next');
            $payload = $step['decoded_payload'] ?? [];
            $record = $payload['carry']['open_next'] ?? [];
            if (($step['status'] ?? null) !== 'done' || ($payload['opening_source'] ?? null) === 'taken_over'
                || (int) ($payload['entry_id'] ?? 0) !== $entryId || (int) ($payload['next_period_id'] ?? 0) !== (int) $target['id']
                || ($record['status'] ?? null) !== 'written' || (int) ($record['entry_id'] ?? 0) !== $entryId
                || (int) ($record['entry_version'] ?? 0) !== (int) $entry['row_version']
                || (int) ($record['target_period_id'] ?? 0) !== (int) $target['id'] || ($record['entry_date'] ?? null) !== $entry['entry_date']) return false;
            $roles = \MyInvoice\Service\Accounting\AccountRoleResolver::fromConnection($this->db);
            if ($roles->forDate($supplier, $source['ends_on'])->framework !== AccountingFrameworkService::CZ_504
                || $roles->forDate($supplier, $target['starts_on'])->framework !== AccountingFrameworkService::CZ_504) return false;
            $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
            $snapshot = $read->snapshot($supplier, $source, $target, 'open_next', $target['starts_on'], $profile);
            $closed = $read->step($supplier, (int) $source['id'], 'close_books');
            $closedEntry = (int) ($closed['decoded_payload']['entry_id'] ?? 0);
            $closing = $read->snapshot($supplier, $source, $source, 'close_books', $source['ends_on'], $profile);
            return $closedEntry > 0 && $this->sameLines($read, $supplier, $closing['lines'], $closedEntry)
                && $this->sameLines($read, $supplier, $snapshot['lines'], $entryId);
        }
        return false;
    }

    private function sameLines(ClosingCarryReadRepository $read, int $supplier, array $expected, int $entryId): bool
    {
        $q = $this->db->pdo()->prepare('SELECT account_code,id FROM chart_of_accounts WHERE supplier_id=?');
        $q->execute([$supplier]);
        $accounts = $q->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($expected as &$line) {
            $line['account_id'] = (int) ($accounts[$line['account_code']] ?? 0);
            if ($line['account_id'] === 0) return false;
        }
        unset($line);
        return JournalLineComparison::sameLines($expected, $read->entryLines($supplier, $entryId));
    }
}
