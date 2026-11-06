<?php

declare(strict_types=1);

namespace MyInvoice\Service\Nonprofit\Membership;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\PostingException;
use PDO;

final class MembershipUseGuard
{
    public const TABLES = [
        'nonprofit_membership_charge_claims', 'nonprofit_membership_charge_operations',
        'nonprofit_membership_charge_commands', 'nonprofit_membership_charge_cases',
        'nonprofit_member_revisions', 'nonprofit_members',
    ];

    public function __construct(private readonly Connection $db) {}

    public function withIssueOwner(int $supplier, int $case, callable $work): mixed
    {
        $this->requireTransaction();
        $q = $this->db->pdo()->prepare('SELECT id FROM nonprofit_membership_charge_cases WHERE supplier_id=? AND id=? FOR UPDATE');
        $q->execute([$supplier, $case]);
        if ($q->fetchColumn() === false) throw new MembershipException('nonprofit_membership_case_not_found', 'Účetní případ nebyl nalezen.', 404);
        return $this->session('@myucto_membership_case_id', (string) $case, $work);
    }

    public function withReversalOwner(int $supplier, int $entry, callable $work): mixed
    {
        $this->requireTransaction();
        $q = $this->db->pdo()->prepare("SELECT o.id FROM nonprofit_membership_charge_operations o JOIN journal_entries e ON e.supplier_id=o.supplier_id AND e.id=o.journal_entry_id WHERE o.supplier_id=? AND o.journal_entry_id=? AND o.operation='issue' AND e.reversed_by IS NULL FOR UPDATE");
        $q->execute([$supplier, $entry]);
        if ($q->fetchColumn() === false) throw new MembershipException('nonprofit_membership_reversal_source_invalid', 'Předpis nemá živý doložený účetní zápis.', 409);
        return $this->session('@myucto_membership_reversal_entry', $supplier . ':' . $entry, $work);
    }

    public function assertEntriesMutable(int $supplier, array $entryIds): void
    {
        $this->requireTransaction();
        $entryIds = array_values(array_unique(array_filter(array_map('intval', $entryIds), static fn (int $id): bool => $id > 0)));
        if ($entryIds === []) return;
        foreach (array_chunk($entryIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT journal_entry_id FROM nonprofit_membership_charge_operations WHERE supplier_id=? AND journal_entry_id IN ($marks) ORDER BY journal_entry_id FOR UPDATE");
            $q->execute([$supplier, ...$chunk]);
            while (($owned = $q->fetchColumn()) !== false) {
                if ($this->reversalEntry() !== $supplier . ':' . (int) $owned) {
                    throw new PostingException('nonprofit_membership_source_immutable', 'Zápis patří členskému příspěvku a lze jej opravit jen řízeným stornem.', 409);
                }
            }
        }
    }

    public function assertHeaderOwner(array $header): void
    {
        if (($header['source_type'] ?? null) !== 'nonprofit_membership') return;
        $this->requireTransaction();
        $supplier = (int) ($header['supplier_id'] ?? 0);
        $source = $header['source_id'] ?? null;
        if ($supplier <= 0
            || ($source !== null && (string) $this->db->pdo()->query('SELECT @myucto_membership_case_id')->fetchColumn() !== (string) (int) $source)
            || ($source === null && !str_starts_with((string) $this->reversalEntry(), $supplier . ':'))) {
            throw new PostingException('nonprofit_membership_owner_required', 'Zápis členského příspěvku vyžaduje živý vlastnický kontext.', 409);
        }
    }

    public function assertLinesMutable(int $supplier, array $lineIds): void
    {
        $this->requireTransaction();
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $id): bool => $id > 0)));
        if ($lineIds === []) return;
        foreach (array_chunk($lineIds, 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $this->db->pdo()->prepare("SELECT o.id FROM nonprofit_membership_charge_operations o WHERE o.supplier_id=? AND (o.receivable_line_id IN ($marks) OR o.revenue_line_id IN ($marks)) LIMIT 1 FOR UPDATE");
            $q->execute([$supplier, ...$chunk, ...$chunk]);
            if ($q->fetchColumn() !== false) throw new PostingException('nonprofit_membership_source_immutable', 'Řádek patří členskému příspěvku a lze jej opravit jen řízeným stornem.', 409);
        }
    }

    private function session(string $variable, string $value, callable $work): mixed
    {
        $pdo = $this->db->pdo();
        $read = $pdo->query('SELECT ' . $variable)->fetchColumn();
        if ($read !== null) throw new \LogicException('Membership journal owner context is already active.');
        $set = $pdo->prepare('SET ' . $variable . '=?');
        $set->execute([$value]);
        try {
            return $work();
        } finally {
            $pdo->exec('SET ' . $variable . '=NULL');
        }
    }

    private function reversalEntry(): ?string
    {
        $value = $this->db->pdo()->query('SELECT @myucto_membership_reversal_entry')->fetchColumn();
        return $value === null ? null : (string) $value;
    }

    private function requireTransaction(): void
    {
        if (!$this->db->pdo()->inTransaction()) throw new \LogicException('Membership ownership requires the caller transaction.');
    }
}
