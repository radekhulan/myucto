<?php

declare(strict_types=1);

namespace MyInvoice\Service\Portfolio;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Service\Accounting\UnbookedDocumentsCounter;
use MyInvoice\Service\Crm\CrmAggregationService;
use MyInvoice\Service\Supplier\SupplierDirectory;
use MyInvoice\Service\Supplier\SupplierDirectoryQuery;

/**
 * Přehled firem pro účetní kancelář — cross-supplier dashboard (Fáze F,
 * audit 2026-07, finding "Účetní kancelář nemá pohled přes firmy" ~ř.1167,
 * návrh "Přehled firem pro účetní kancelář" ~ř.1217).
 *
 * Agreguje PŘES `user_suppliers` — jen firmy, ke kterým má přihlášený uživatel
 * přístup. Membership sémantika je stejná jako u SupplierAccessResolver (F0):
 *   - globální admin: bez omezení (vidí všechny firmy)
 *   - uživatel BEZ membership řádků: bez omezení (BC, stejné jako dnešní
 *     chování zbytku appky)
 *   - uživatel S membership: jen přiřazené firmy
 * Role 'client' se sem vůbec nedostane — PermissionMiddleware ji na /api/portfolio
 * nepustí (terminální client permission rules větev bez fallbacku).
 */
final class PortfolioAggregationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserSupplierRepository $memberships,
        private readonly CrmAggregationService $crm,
        private readonly SupplierDirectory $directory,
    ) {}

    /**
     * @return array{companies: list<array<string,mixed>>, total: int, generated_at: string}
     */
    public function overview(int $userId, bool $isSuperadmin, \DateTimeImmutable $now, ?int $boundSupplierId = null): array
    {
        $supplierIds = $this->allowedSupplierIds($userId, $isSuperadmin, $boundSupplierId);
        $volumes = (new PortfolioVolumeCounter($this->db))->countsFor($supplierIds);
        // Urgenci počítá jediné místo, totéž co ve správě firem. Vlastní varianta by se
        // od ní rozešla v predikátech (schvalované koncepty, pohyby mimo faktury).
        $directory = [];
        if ($supplierIds !== []) {
            foreach ($this->directory->list($supplierIds, new SupplierDirectoryQuery(), $now) as $entry) {
                $directory[(int) $entry['id']] = $entry;
            }
        }
        $companies = [];
        foreach ($supplierIds as $sid) {
            $row = $this->buildRow($sid, $now, $volumes[$sid] ?? array_fill_keys(PortfolioVolumeCounter::KEYS, 0));
            if ($row !== null) {
                $row['urgency'] = $directory[$sid]['urgency'] ?? null;
                $row['last_invoice_date'] = $directory[$sid]['last_invoice_date'] ?? null;
                $row['last_activity_at'] = $directory[$sid]['last_activity_at'] ?? null;
                $companies[] = $row;
            }
        }

        // Urgence: nejbližší termín nahoře (nejmenší `days`, i záporné/po termínu),
        // firmy bez termínu (neplátci DPH) na konec, sekundárně dle jména.
        usort($companies, static function (array $a, array $b): int {
            $ad = $a['next_deadline']['days'] ?? null;
            $bd = $b['next_deadline']['days'] ?? null;
            if ($ad === null && $bd === null) {
                return strcmp((string) $a['company_name'], (string) $b['company_name']);
            }
            if ($ad === null) {
                return 1;
            }
            if ($bd === null) {
                return -1;
            }
            return $ad <=> $bd;
        });

        return [
            'companies'    => $companies,
            'total'        => count($companies),
            'generated_at' => $now->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Smí uživatel vidět tuhle firmu v přehledu? Táž membership sémantika jako
     * {@see overview()} — endpointy, které berou `supplier_id` z URL, se musí ptát
     * TADY, ne skládat vlastní variantu pravidla.
     */
    public function userCanAccess(int $userId, bool $isSuperadmin, int $supplierId, ?int $boundSupplierId = null): bool
    {
        return $supplierId > 0
            && in_array($supplierId, $this->allowedSupplierIds($userId, $isSuperadmin, $boundSupplierId), true);
    }

    /**
     * Vázaný požadavek (token pro jednu firmu, vlastní doména) dostane nejvýš tuto
     * firmu, i když je vlastník globální admin nebo člen více firem.
     *
     * @return list<int>
     */
    private function allowedSupplierIds(int $userId, bool $isSuperadmin, ?int $boundSupplierId = null): array
    {
        if ($isSuperadmin) {
            $ids = $this->allSupplierIds();
        } else {
            $ids = array_map('intval', array_keys($this->memberships->assignmentsForUser($userId)));
            sort($ids);
        }
        if ($boundSupplierId === null) {
            return $ids;
        }
        return in_array($boundSupplierId, $ids, true) ? [$boundSupplierId] : [];
    }

    /** @return list<int> */
    private function allSupplierIds(): array
    {
        return array_map('intval', $this->db->pdo()->query('SELECT id FROM supplier ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string,int> $volume
     * @return array<string,mixed>|null
     */
    private function buildRow(int $supplierId, \DateTimeImmutable $now, array $volume): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, company_name, display_name, ic, accounting_mode, is_vat_payer FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $sup = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($sup === false) {
            return null;
        }

        $accountingMode = (string) $sup['accounting_mode'];
        $isDoubleEntry = $accountingMode === 'double_entry';

        // Rozpad se posílá i do UI: součet míchá tři různé entity a bez něj se nedá
        // proklik nasměrovat tam, kde položky opravdu leží.
        $unbookedBreakdown = $isDoubleEntry
            ? (new UnbookedDocumentsCounter($this->db))->breakdown($supplierId)
            : [];

        return [
            'supplier_id'     => $supplierId,
            'company_name'    => (string) ($sup['display_name'] !== null && $sup['display_name'] !== '' ? $sup['display_name'] : $sup['company_name']),
            'ic'              => $sup['ic'] !== null ? (string) $sup['ic'] : null,
            'is_vat_payer'    => (bool) $sup['is_vat_payer'],
            'accounting_mode' => $accountingMode,
            'next_deadline'   => $this->crm->nextTaxDeadline($supplierId, $now),
            'unbooked_documents'          => UnbookedDocumentsCounter::totalOf($unbookedBreakdown),
            'unbooked_breakdown'          => $unbookedBreakdown,
            'unmatched_bank_transactions' => $this->unmatchedBankCount($supplierId, $now),
            'purchase_drafts'             => $this->purchaseDraftsCount($supplierId),
            'inbox_pending'               => $this->inboxPendingCount($supplierId),
            'period_status'      => $this->periodStatus($supplierId),
            'last_bank_import_at' => $this->lastBankImportAt($supplierId),
            'volume'             => $volume,
        ];
    }

    /**
     * Nespárované příchozí bankovní platby — stejný scope jako action item
     * `bank_unmatched` (BankStatementOwnershipResolver, SEC-01). Jen posledních
     * 90 dní (shoda se zdrojovým predikátem).
     */
    private function unmatchedBankCount(int $supplierId, \DateTimeImmutable $now): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id
              WHERE bt.match_status = 'unmatched'
                AND " . \MyInvoice\Service\Bank\BankNoticeLifecycle::activeSql('bt') . "
                AND bt.amount > 0
                AND bt.posted_at >= DATE_SUB(?, INTERVAL 90 DAY)
                AND " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql()
        );
        $stmt->execute(array_merge(
            [$now->format('Y-m-d')],
            \MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId),
        ));
        return (int) $stmt->fetchColumn();
    }

    /** Koncepty přijatých faktur (draft) — shodné s action item `purchase_drafts`. */
    private function purchaseDraftsCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoices WHERE supplier_id = ? AND status = 'draft'"
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Doklady v příchozích dokladech, které čekají na vytěžení. Patří sem i podání,
     * jehož vytěžení selhalo: vrací se do stavu `submitted` a čeká na další pokus.
     */
    private function inboxPendingCount(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM purchase_invoice_submissions WHERE supplier_id = ? AND status = 'submitted'"
        );
        $stmt->execute([$supplierId]);
        return (int) $stmt->fetchColumn();
    }

    /** Stav aktuálního (nejvyššího fiscal_year) účetního období, nebo null (žádné/daňová evidence). */
    private function periodStatus(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT fiscal_year, status FROM accounting_periods
              WHERE supplier_id = ? ORDER BY fiscal_year DESC LIMIT 1'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['fiscal_year' => (int) $row['fiscal_year'], 'status' => (string) $row['status']];
    }

    /** Datum posledního importu bankovního výpisu (stejný scope jako unmatchedBankCount). */
    private function lastBankImportAt(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT MAX(bs.imported_at) FROM bank_statements bs
              WHERE " . \MyInvoice\Repository\BankStatementOwnershipResolver::sql()
        );
        $stmt->execute(\MyInvoice\Repository\BankStatementOwnershipResolver::params($supplierId));
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string) $v;
    }
}
