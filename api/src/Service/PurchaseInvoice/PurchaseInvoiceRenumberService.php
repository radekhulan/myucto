<?php

declare(strict_types=1);

namespace MyInvoice\Service\PurchaseInvoice;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\TaxEvidence\TaxEvidenceYearLock;
use PDO;

/**
 * Přečíslování přijatých dokladů jednoho měsíce podle data vystavení.
 *
 * Interní číslo dostává doklad při přijetí, takže pořadí v řadě odpovídá pořadí
 * přijímání, ne datu. Přečíslování vezme automaticky očíslované doklady měsíce
 * (číslo odpovídá šabloně některé řady pro daný měsíc), seřadí je podle data vystavení
 * a v každé řadě jim přidělí čísla od 1. Řadu určuje AKTUÁLNÍ typ dokladu, takže
 * doklad, kterému se po očíslování změnil typ, se přesune do správné řady.
 *
 * Mimo přečíslování zůstávají ručně zadaná a cizí čísla (neodpovídají šabloně)
 * i čísla jiného měsíce. Jejich čísla se při přidělování přeskočí.
 *
 * U přijatých dokladů se souvislá řada nevyžaduje a do kontrolního hlášení jde
 * číslo dodavatele, takže přečíslování daňově nic nemění. Kopie interního čísla
 * v deníku (číslo dokladu a popis zápisu, včetně storna) a v popisu příjemky se
 * přepíší. Měsíc po uzávěrce (uzavřené i uzavírané účetní období, dokončená roční
 * uzávěrka daňové evidence) nebo uzamčený k datu se nepřečísluje vůbec.
 */
final class PurchaseInvoiceRenumberService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Náhled přečíslování (nic nezapisuje).
     *
     * @return array{period: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>}
     */
    public function preview(int $supplierId, string $period): array
    {
        $period = self::normalizePeriod($period);
        $settings = $this->repo->purchaseSeriesSettings($supplierId);
        $series = PurchaseNumberSeries::all($settings);
        $regexes = [];
        foreach ($series as $key => $s) {
            $regex = PurchaseNumberSeries::counterRegex($s['template'], $period);
            if ($regex !== null) {
                $regexes[$key] = $regex;
            }
        }

        $from = substr($period, 0, 4) . '-' . substr($period, 4, 2) . '-01';
        $to = date('Y-m-t', (int) strtotime($from));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.supplier_id, p.varsymbol, p.vendor_invoice_number, p.document_kind, p.issue_date,
                    p.tax_date, p.effective_cost_date, p.booked_at, p.status, p.vat_deduction, p.tax_deductible,
                    c.company_name
               FROM purchase_invoices p
               LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.varsymbol IS NOT NULL AND p.varsymbol <> ''
              ORDER BY p.issue_date ASC, p.id ASC"
        );
        $stmt->execute([$supplierId]);

        $participants = [];
        $kept = [];
        /** @var array<string, true> $occupied čísla, která přečíslování nesmí použít */
        $occupied = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $vs = (string) $row['varsymbol'];
            $inMonth = $row['issue_date'] >= $from && $row['issue_date'] <= $to;
            $auto = false;
            foreach ($regexes as $regex) {
                if (preg_match($regex, $vs)) {
                    $auto = true;
                    break;
                }
            }
            if ($inMonth && $auto) {
                $participants[] = $row;
            } else {
                $occupied[$vs] = true;
                if ($inMonth) {
                    $kept[] = self::describe($row) + ['reason' => 'manual_number'];
                }
            }
        }

        // Po uzávěrce (i rozpracované) se čísla nemění: podvojné účetnictví podle stavu
        // účetního období, daňová evidence podle dokončené roční uzávěrky.
        $yearClosed = TaxEvidenceYearLock::isFinal($this->db, $supplierId, (int) substr($period, 0, 4));
        $blocked = [];
        foreach ($participants as $row) {
            $lock = $this->locks->forPurchaseInvoice($row);
            $reason = match (true) {
                $yearClosed              => 'year_closed',
                $lock->inClosedPeriod    => 'period_closed',
                $lock->inClosingPeriod   => 'period_closing',
                $lock->dateLocked        => 'date_locked',
                default                  => null,
            };
            if ($reason !== null) {
                $blocked[] = self::describe($row) + ['reason' => $reason];
            }
        }

        $counters = [];
        $changes = [];
        foreach ($participants as $row) {
            ['key' => $key, 'template' => $template] = PurchaseNumberSeries::resolve($settings, (string) $row['document_kind']);
            $prefix = PurchaseInvoiceRepository::varsymbolPrefix((string) ($row['vat_deduction'] ?? 'full'), (bool) ($row['tax_deductible'] ?? 1));
            do {
                $counters[$key] = ($counters[$key] ?? 0) + 1;
                $new = PurchaseNumberSeries::render($template, $prefix, $period, $counters[$key]);
            } while (isset($occupied[$new]));
            $occupied[$new] = true;
            $changes[] = self::describe($row) + ['new_varsymbol' => $new, 'series' => $key, 'changed' => $new !== (string) $row['varsymbol']];
        }

        return ['period' => $period, 'changes' => $changes, 'kept' => $kept, 'blocked' => $blocked];
    }

    /**
     * Přečísluje měsíc. Vrací stejný tvar jako {@see preview()} + počet změněných dokladů.
     *
     * @return array{period: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>, renumbered: int}
     * @throws \DomainException měsíc obsahuje doklad v uzavřeném nebo uzamčeném období
     */
    public function renumber(int $supplierId, string $period, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }
        try {
            // Zámek řádků počítadel firmy: souběžné přijetí dokladu by si jinak vzalo číslo,
            // které přečíslování právě rozdává.
            $pdo->prepare('SELECT period FROM purchase_invoice_counters WHERE supplier_id = ? FOR UPDATE')->execute([$supplierId]);

            $plan = $this->preview($supplierId, $period);
            if ($plan['blocked'] !== []) {
                throw new \DomainException('Měsíc je po uzávěrce nebo uzamčený k datu, přečíslovat ho nelze.');
            }
            $moves = array_values(array_filter($plan['changes'], static fn (array $c): bool => $c['changed']));

            // Dvoufázově kvůli unikátnímu indexu: nová čísla se mezi doklady měsíce přesouvají.
            $tmp = $pdo->prepare('UPDATE purchase_invoices SET varsymbol = ? WHERE id = ? AND supplier_id = ?');
            foreach ($moves as $m) {
                $tmp->execute(['~RN' . $m['id'], $m['id'], $supplierId]);
            }
            foreach ($moves as $m) {
                $tmp->execute([$m['new_varsymbol'], $m['id'], $supplierId]);
                $this->rewriteCopies($supplierId, (int) $m['id'], (string) $m['varsymbol'], (string) $m['new_varsymbol']);
            }

            $settings = $this->repo->purchaseSeriesSettings($supplierId);
            foreach (PurchaseNumberSeries::all($settings) as $key => $s) {
                $highest = $this->repo->highestUsedPurchaseCounter($supplierId, $s['template'], $plan['period']);
                $this->repo->setPurchaseCounter($supplierId, PurchaseNumberSeries::counterKey($key, $s['template'], $plan['period']), $highest);
            }

            if ($moves !== []) {
                $this->activity->log('purchase_invoice.renumbered', $userId, 'supplier', $supplierId, [
                    'period'  => $plan['period'],
                    'changes' => array_map(static fn (array $m): array => [
                        'id' => $m['id'], 'from' => $m['varsymbol'], 'to' => $m['new_varsymbol'],
                    ], $moves),
                ], null, null, $supplierId);
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $plan + ['renumbered' => count($moves)];
    }

    /**
     * Přepíše kopie interního čísla: číslo dokladu a popis zápisů deníku dokladu (i storna)
     * a popis příjemky „Příjem z PF …". Popis se mění jen v celém slově, aby PF2610001
     * nepřepsal začátek čísla PF26100015.
     */
    private function rewriteCopies(int $supplierId, int $id, string $old, string $new): void
    {
        $pdo = $this->db->pdo();
        $pattern = '(?<![0-9A-Za-z])' . preg_quote($old, '/') . '(?![0-9A-Za-z])';
        $pdo->prepare(
            "UPDATE journal_entries
                SET document_no = CASE
                        WHEN document_no = ? THEN ?
                        WHEN document_no = CONCAT('STORNO ', ?) THEN CONCAT('STORNO ', ?)
                        ELSE document_no END,
                    description = REGEXP_REPLACE(description, ?, ?),
                    row_version = row_version + 1
              WHERE supplier_id = ? AND source_type = 'purchase_invoice' AND source_id = ?
                AND (document_no IN (?, CONCAT('STORNO ', ?)) OR description REGEXP ?)"
        )->execute([$old, $new, $old, $new, $pattern, $new, $supplierId, $id, $old, $old, $pattern]);

        // Další zápisy, které doklad vlastní ve skupině zaúčtování (DPH, ruční člen).
        $pdo->prepare(
            "UPDATE journal_document_postings jdp
               JOIN journal_entries je ON je.supplier_id = jdp.supplier_id AND je.id = jdp.entry_id
                SET je.document_no = CASE
                        WHEN je.document_no = ? THEN ?
                        WHEN je.document_no = CONCAT('STORNO ', ?) THEN CONCAT('STORNO ', ?)
                        ELSE je.document_no END,
                    je.description = REGEXP_REPLACE(je.description, ?, ?),
                    je.row_version = je.row_version + 1
              WHERE jdp.supplier_id = ? AND jdp.doc_type = 'purchase_invoice' AND jdp.doc_id = ?
                AND NOT (je.source_type = 'purchase_invoice' AND je.source_id <=> ?)
                AND (je.document_no IN (?, CONCAT('STORNO ', ?)) OR je.description REGEXP ?)"
        )->execute([$old, $new, $old, $new, $pattern, $new, $supplierId, $id, $id, $old, $old, $pattern]);

        $pdo->prepare(
            "UPDATE stock_documents d
               JOIN purchase_invoices p ON p.id = d.purchase_invoice_id AND p.supplier_id = ?
                SET d.description = REGEXP_REPLACE(d.description, ?, ?)
              WHERE d.purchase_invoice_id = ? AND d.description REGEXP ?"
        )->execute([$supplierId, $pattern, $new, $id, $pattern]);
    }

    /** @param array<string,mixed> $row */
    private static function describe(array $row): array
    {
        return [
            'id'                    => (int) $row['id'],
            'varsymbol'             => (string) $row['varsymbol'],
            'vendor_invoice_number' => (string) ($row['vendor_invoice_number'] ?? ''),
            'vendor_name'           => (string) ($row['company_name'] ?? ''),
            'document_kind'         => (string) ($row['document_kind'] ?? 'invoice'),
            'issue_date'            => (string) $row['issue_date'],
        ];
    }

    private static function normalizePeriod(string $period): string
    {
        $digits = preg_replace('/\D/', '', $period) ?? '';
        if (!preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $digits)) {
            throw new \InvalidArgumentException('Období musí být ve tvaru RRRR-MM.');
        }
        return $digits;
    }
}
