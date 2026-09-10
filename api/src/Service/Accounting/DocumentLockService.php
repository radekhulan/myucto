<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\PurchaseInvoiceSubmissionRepository;
use PDO;

/**
 * Jednotný zámek zaúčtovaných dokladů (Epic F6, §4).
 *
 * lockedForClient(doc) = booked_at IS NOT NULL
 *                      ∨ aktivní posted zápis (posted_at NOT NULL, reversed_by NULL)
 *                      ∨ double_entry firma a refDate spadá do období closing/closed/approved
 *                      ∨ jen PF: status = 'booked'
 *
 * PF status 'paid' NEZAMYKÁ (M2). tax_evidence firmy: období se vůbec nedotazuje —
 * zámek stojí čistě na booked_at. refDate = effective_tax_date/effective_cost_date
 * (migrace 1009/1010) s fallbackem na issue_date.
 */
final class DocumentLockService
{
    /** @var array<string, DocumentLock> per-request memoizace, klíč "type:supplier:id" */
    private array $cache = [];

    /** @var array<int, ?string> supplier_id => accounting_mode */
    private array $modeCache = [];

    /** @var array<int, list<array<string,mixed>>> supplier_id => účetní období */
    private array $periodsCache = [];

    /** @var array<int, ?string> supplier_id => locked_until (B8) */
    private array $lockedUntilCache = [];

    public function __construct(
        private readonly Connection $db,
        private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods,
        private readonly PurchaseInvoiceSubmissionRepository $submissions,
    ) {}

    /** @param array<string,mixed> $invoice řádek invoices (id, supplier_id, booked_at, effective_tax_date, issue_date, status) */
    public function forInvoice(array $invoice): DocumentLock
    {
        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $id = (int) ($invoice['id'] ?? 0);
        $key = "invoice:{$supplierId}:{$id}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        return $this->cache[$key] = $this->compose(
            'invoice',
            $supplierId,
            $id,
            self::nullableString($invoice['booked_at'] ?? null),
            false,
            self::invoiceRefDate($invoice),
        );
    }

    /** @param array<string,mixed> $pi řádek purchase_invoices (id, supplier_id, booked_at, status, effective_cost_date, issue_date) */
    public function forPurchaseInvoice(array $pi): DocumentLock
    {
        $supplierId = (int) ($pi['supplier_id'] ?? 0);
        $id = (int) ($pi['id'] ?? 0);
        $key = "purchase_invoice:{$supplierId}:{$id}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        return $this->cache[$key] = $this->compose(
            'purchase_invoice',
            $supplierId,
            $id,
            self::nullableString($pi['booked_at'] ?? null),
            (string) ($pi['status'] ?? '') === 'booked',
            self::purchaseRefDate($pi),
        );
    }

    /** Zámek období pro konkrétní datum (create/issue/změna data — H1). */
    public function forDate(int $supplierId, string $refDate): DocumentLock
    {
        $key = "date:{$supplierId}:{$refDate}";
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        [$closed, $closing, $status] = $this->periodFlags($supplierId, $refDate);
        return $this->cache[$key] = new DocumentLock(
            booked: false,
            bookedAt: null,
            posted: false,
            journalEntryId: null,
            inClosedPeriod: $closed,
            inClosingPeriod: $closing,
            periodStatus: $status,
            dateLocked: $this->isDateLocked($supplierId, $refDate),
        );
    }

    /**
     * Batch pro listy — jeden IN dotaz na posted zápisy + jeden na doklady, žádné N+1.
     *
     * @param 'invoice'|'purchase_invoice' $sourceType
     * @param list<int> $ids
     * @return array<int, DocumentLock> id => lock
     */
    public function lockedMapForSources(int $supplierId, string $sourceType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || !in_array($sourceType, ['invoice', 'purchase_invoice'], true)) {
            return [];
        }
        $pdo = $this->db->pdo();
        $place = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT source_id, id FROM journal_entries
              WHERE supplier_id = ? AND source_type = ? AND source_id IN ($place)
                AND posted_at IS NOT NULL AND reversed_by IS NULL"
        );
        $stmt->execute(array_merge([$supplierId, $sourceType], $ids));
        $postedMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $postedMap[(int) $row['source_id']] = (int) $row['id'];
        }

        if ($sourceType === 'invoice') {
            $docStmt = $pdo->prepare(
                "SELECT id, booked_at, status, effective_tax_date, issue_date
                   FROM invoices WHERE supplier_id = ? AND id IN ($place)"
            );
        } else {
            $docStmt = $pdo->prepare(
                "SELECT id, booked_at, status, effective_cost_date, tax_date, issue_date
                   FROM purchase_invoices WHERE supplier_id = ? AND id IN ($place)"
            );
        }
        $docStmt->execute(array_merge([$supplierId], $ids));

        $map = [];
        $accountantManaged = $sourceType === 'purchase_invoice'
            ? $this->submissions->accountantManagedMap($supplierId, $ids)
            : [];
        foreach ($docStmt->fetchAll(PDO::FETCH_ASSOC) as $doc) {
            $id = (int) $doc['id'];
            $bookedAt = self::nullableString($doc['booked_at'] ?? null);
            $statusBooked = $sourceType === 'purchase_invoice' && (string) ($doc['status'] ?? '') === 'booked';
            $refDate = $sourceType === 'invoice' ? self::invoiceRefDate($doc) : self::purchaseRefDate($doc);
            [$closed, $closing, $periodStatus] = $this->periodFlags($supplierId, $refDate);
            $lock = new DocumentLock(
                booked: $bookedAt !== null || $statusBooked,
                bookedAt: $bookedAt,
                posted: isset($postedMap[$id]),
                journalEntryId: $postedMap[$id] ?? null,
                inClosedPeriod: $closed,
                inClosingPeriod: $closing,
                periodStatus: $periodStatus,
                dateLocked: $this->isDateLocked($supplierId, $refDate),
                accountantManaged: isset($accountantManaged[$id]),
            );
            $map[$id] = $lock;
            $this->cache["{$sourceType}:{$supplierId}:{$id}"] = $lock;
        }
        return $map;
    }

    /** refDate vydané faktury = effective_tax_date ?? tax_date ?? issue_date (1009). */
    public static function invoiceRefDate(array $doc): ?string
    {
        return self::nullableString($doc['effective_tax_date'] ?? null)
            ?? self::nullableString($doc['tax_date'] ?? null)
            ?? self::nullableString($doc['issue_date'] ?? null);
    }

    /** refDate přijaté faktury = effective_cost_date ?? GREATEST(COALESCE(tax_date, issue_date), issue_date) (1010). */
    public static function purchaseRefDate(array $doc): ?string
    {
        $eff = self::nullableString($doc['effective_cost_date'] ?? null);
        if ($eff !== null) {
            return $eff;
        }
        $issue = self::nullableString($doc['issue_date'] ?? null);
        $base = self::nullableString($doc['tax_date'] ?? null) ?? $issue;
        if ($base === null) {
            return $issue;
        }
        return $issue !== null ? max($base, $issue) : $base;
    }

    private function compose(
        string $sourceType,
        int $supplierId,
        int $id,
        ?string $bookedAt,
        bool $statusBooked,
        ?string $refDate,
    ): DocumentLock {
        $posted = false;
        $entryId = null;
        if ($id > 0 && $supplierId > 0) {
            $entry = $this->journal->findBySource($supplierId, $sourceType, $id);
            if ($entry !== null && $entry['posted_at'] !== null && $entry['reversed_by'] === null) {
                $posted = true;
                $entryId = (int) $entry['id'];
            }
        }
        [$closed, $closing, $periodStatus] = $this->periodFlags($supplierId, $refDate);
        return new DocumentLock(
            booked: $bookedAt !== null || $statusBooked,
            bookedAt: $bookedAt,
            posted: $posted,
            journalEntryId: $entryId,
            inClosedPeriod: $closed,
            inClosingPeriod: $closing,
            periodStatus: $periodStatus,
            dateLocked: $this->isDateLocked($supplierId, $refDate),
            accountantManaged: $sourceType === 'purchase_invoice'
                && $this->submissions->isAccountantManaged($supplierId, $id),
        );
    }

    /**
     * @return array{0:bool, 1:bool, 2:?string} [inClosedPeriod, inClosingPeriod, periodStatus]
     */
    private function periodFlags(int $supplierId, ?string $refDate): array
    {
        if ($refDate === null || $supplierId <= 0) {
            return [false, false, null];
        }
        // tax_evidence firmy: journal ani období neexistují → zámek stojí jen na booked_at
        if ($this->accountingMode($supplierId) !== 'double_entry') {
            return [false, false, null];
        }
        $period = $this->matchPeriod($supplierId, $refDate);
        if ($period === null) {
            return [false, false, null];
        }
        $status = (string) $period['status'];
        return [
            AccountingPeriodStatus::isClosed($status),
            $status === 'closing',
            $status,
        ];
    }

    /** B8: refDate spadá do zamčeného rozsahu (refDate <= locked_until). */
    private function isDateLocked(int $supplierId, ?string $refDate): bool
    {
        if ($refDate === null || $supplierId <= 0) {
            return false;
        }
        $lockedUntil = $this->lockedUntil($supplierId);
        return $lockedUntil !== null && $refDate <= $lockedUntil;
    }

    private function lockedUntil(int $supplierId): ?string
    {
        if (!array_key_exists($supplierId, $this->lockedUntilCache)) {
            $stmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
            $stmt->execute([$supplierId]);
            $v = $stmt->fetchColumn();
            $this->lockedUntilCache[$supplierId] = ($v === false || $v === null) ? null : (string) $v;
        }
        return $this->lockedUntilCache[$supplierId];
    }

    private function accountingMode(int $supplierId): ?string
    {
        if (!array_key_exists($supplierId, $this->modeCache)) {
            $stmt = $this->db->pdo()->prepare('SELECT accounting_mode FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $v = $stmt->fetchColumn();
            $this->modeCache[$supplierId] = $v === false || $v === null ? null : (string) $v;
        }
        return $this->modeCache[$supplierId];
    }

    /** Období obsahující datum — stejný determinismus jako findForDate (starts_on DESC, id DESC). */
    private function matchPeriod(int $supplierId, string $date): ?array
    {
        if (!array_key_exists($supplierId, $this->periodsCache)) {
            $this->periodsCache[$supplierId] = $this->periods->listForTenant($supplierId);
        }
        $best = null;
        foreach ($this->periodsCache[$supplierId] as $period) {
            $starts = (string) $period['starts_on'];
            $ends = (string) $period['ends_on'];
            if ($date < $starts || $date > $ends) {
                continue;
            }
            if ($best === null
                || $starts > (string) $best['starts_on']
                || ($starts === (string) $best['starts_on'] && (int) $period['id'] > (int) $best['id'])
            ) {
                $best = $period;
            }
        }
        return $best;
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
