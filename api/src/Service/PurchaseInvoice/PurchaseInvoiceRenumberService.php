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
 * Přečíslování přijatých dokladů jednoho měsíce podle zvoleného data.
 *
 * Interní číslo dostává doklad při přijetí, takže pořadí v řadě odpovídá pořadí
 * přijímání, ne datu. Přečíslování vezme automaticky očíslované doklady měsíce
 * (číslo odpovídá šabloně některé řady pro daný měsíc), seřadí je podle zvoleného data
 * a v každé řadě jim přidělí čísla od 1. Řadu určuje AKTUÁLNÍ typ dokladu, takže
 * doklad, kterému se po očíslování změnil typ, se přesune do správné řady.
 *
 * Datum pro výběr měsíce i pořadí ({@see BASES}): datum vystavení (výchozí), datum přijetí
 * nebo DUZP. Doklad bez zvoleného data se řadí podle data vystavení. Volba se uloží
 * do `supplier.purchase_renumber_date_basis` při provedení přečíslování.
 *
 * Měsíc v čísle dokladu plyne z data vystavení ({@see PurchaseInvoiceRepository::ensureVarsymbol()}),
 * takže při výběru podle jiného data může doklad měsíce nést číslo jiného měsíce. Takový
 * doklad zůstane beze změny (důvod `number_other_month`), stejně jako doklad s číslem
 * tohoto měsíce, jehož zvolené datum leží v jiném měsíci (`date_other_month`).
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

    /** Datum pro výběr měsíce a pořadí: vystavení (výchozí), přijetí, DUZP. */
    public const BASES = ['issue', 'received', 'tax'];

    private const BASIS_COLUMN = ['issue' => 'issue_date', 'received' => 'received_at', 'tax' => 'tax_date'];

    /** Uložená volba firmy ({@see BASES}), bez uložené volby datum vystavení. */
    public function storedBasis(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT purchase_renumber_date_basis FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $basis = (string) $stmt->fetchColumn();
        return in_array($basis, self::BASES, true) ? $basis : 'issue';
    }

    /**
     * Náhled přečíslování (nic nezapisuje).
     *
     * @param string|null $basis datum pro výběr a pořadí ({@see BASES}), null = uložená volba firmy
     * @return array{period: string, date_basis: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>}
     */
    public function preview(int $supplierId, string $period, ?string $basis = null): array
    {
        $period = self::normalizePeriod($period);
        $basis = $basis === null ? $this->storedBasis($supplierId) : self::normalizeBasis($basis);
        $settings = $this->repo->purchaseSeriesSettings($supplierId);
        $series = PurchaseNumberSeries::all($settings);
        $regexes = [];
        /** @var array<string, true> $monthly řady, jejichž číslo nese měsíc */
        $monthly = [];
        foreach ($series as $key => $s) {
            $regex = PurchaseNumberSeries::counterRegex($s['template'], $period);
            if ($regex !== null) {
                $regexes[$key] = $regex;
                if (str_contains($s['template'], '{MM}')) {
                    $monthly[$key] = true;
                }
            }
        }

        // Prázdné nebo nulové datum (starší data z nestriktního režimu) = doklad bez data.
        $column = self::BASIS_COLUMN[$basis];
        $basisDate = $basis === 'issue'
            ? 'p.issue_date'
            : "CASE WHEN p.{$column} >= '1000-01-01' THEN p.{$column} END";

        $from = substr($period, 0, 4) . '-' . substr($period, 4, 2) . '-01';
        $to = date('Y-m-t', (int) strtotime($from));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.supplier_id, p.varsymbol, p.vendor_invoice_number, p.document_kind, p.issue_date,
                    p.tax_date, p.received_at, p.effective_cost_date, p.booked_at, p.status, p.vat_deduction,
                    p.tax_deductible, c.company_name, {$basisDate} AS basis_date
               FROM purchase_invoices p
               LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.varsymbol IS NOT NULL AND p.varsymbol <> ''
              ORDER BY COALESCE({$basisDate}, p.issue_date) ASC, p.issue_date ASC, p.id ASC"
        );
        $stmt->execute([$supplierId]);

        $participants = [];
        $kept = [];
        /** @var array<string, true> $occupied čísla, která přečíslování nesmí použít */
        $occupied = [];
        /** @var array<string, list<string>> $issueRegexes vzory čísel měsíce vystavení (YYYYMM) */
        $issueRegexes = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $vs = (string) $row['varsymbol'];
            $row['date_fallback'] = $row['basis_date'] === null;
            $row['basis_date'] = $row['basis_date'] ?? $row['issue_date'];
            $inMonth = $row['basis_date'] >= $from && $row['basis_date'] <= $to;
            $auto = false;
            $monthNumber = false;
            foreach ($regexes as $key => $regex) {
                if (preg_match($regex, $vs)) {
                    $auto = true;
                    $monthNumber = isset($monthly[$key]);
                    break;
                }
            }
            if ($inMonth && $auto) {
                $participants[] = $row;
                continue;
            }
            $occupied[$vs] = true;
            if ($inMonth) {
                $issuePeriod = substr(str_replace('-', '', (string) $row['issue_date']), 0, 6);
                $issueRegexes[$issuePeriod] ??= self::monthlyRegexes($series, $issuePeriod);
                $otherMonth = $issuePeriod !== $period && self::matchesAny($issueRegexes[$issuePeriod], $vs);
                $kept[] = self::describe($row) + ['reason' => $otherMonth ? 'number_other_month' : 'manual_number'];
            } elseif ($monthNumber) {
                // Číslo tohoto měsíce, ale zvolené datum leží jinde: číslo zůstane obsazené.
                $kept[] = self::describe($row) + ['reason' => 'date_other_month'];
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

        return ['period' => $period, 'date_basis' => $basis, 'changes' => $changes, 'kept' => $kept, 'blocked' => $blocked];
    }

    /**
     * Vzory čísel řad s měsícem v šabloně pro dané období.
     *
     * @param array<string, array{template: string, kinds: list<string>}> $series
     * @return list<string>
     */
    private static function monthlyRegexes(array $series, string $period): array
    {
        $out = [];
        foreach ($series as $s) {
            $regex = str_contains($s['template'], '{MM}') ? PurchaseNumberSeries::counterRegex($s['template'], $period) : null;
            if ($regex !== null) {
                $out[] = $regex;
            }
        }
        return $out;
    }

    /** @param list<string> $regexes */
    private static function matchesAny(array $regexes, string $value): bool
    {
        foreach ($regexes as $regex) {
            if (preg_match($regex, $value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Přečísluje měsíc. Vrací stejný tvar jako {@see preview()} + počet změněných dokladů.
     * Zadané datum pro výběr a pořadí se uloží jako volba firmy.
     *
     * @param string|null $basis datum pro výběr a pořadí ({@see BASES}), null = uložená volba firmy
     * @return array{period: string, date_basis: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>, renumbered: int}
     * @throws \DomainException měsíc obsahuje doklad v uzavřeném nebo uzamčeném období
     */
    public function renumber(int $supplierId, string $period, ?int $userId, ?string $basis = null): array
    {
        $basis = $basis === null ? null : self::normalizeBasis($basis);
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

            $plan = $this->preview($supplierId, $period, $basis);
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

            if ($basis !== null) {
                $pdo->prepare('UPDATE supplier SET purchase_renumber_date_basis = ? WHERE id = ?')->execute([$basis, $supplierId]);
            }

            if ($moves !== []) {
                $this->activity->log('purchase_invoice.renumbered', $userId, 'supplier', $supplierId, [
                    'period'     => $plan['period'],
                    'date_basis' => $plan['date_basis'],
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

        // Storno nese source_id NULL (PostingService), k originálu ho váže jen reversed_by.
        $pdo->prepare(
            "UPDATE journal_entries r
               JOIN journal_entries o ON o.supplier_id = r.supplier_id AND o.reversed_by = r.id
                SET r.document_no = CASE
                        WHEN r.document_no = CONCAT('STORNO ', ?) THEN CONCAT('STORNO ', ?)
                        ELSE r.document_no END,
                    r.description = REGEXP_REPLACE(r.description, ?, ?),
                    r.row_version = r.row_version + 1
              WHERE o.supplier_id = ? AND o.source_type = 'purchase_invoice' AND o.source_id = ?
                AND r.source_id IS NULL
                AND (r.document_no = CONCAT('STORNO ', ?) OR r.description REGEXP ?)"
        )->execute([$old, $new, $pattern, $new, $supplierId, $id, $old, $pattern]);

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
            'received_at'           => (string) ($row['received_at'] ?? ''),
            'tax_date'              => (string) ($row['tax_date'] ?? ''),
            'date'                  => (string) ($row['basis_date'] ?? $row['issue_date']),
            'date_fallback'         => (bool) ($row['date_fallback'] ?? false),
        ];
    }

    private static function normalizeBasis(string $basis): string
    {
        if (!in_array($basis, self::BASES, true)) {
            throw new \InvalidArgumentException('Datum pro přečíslování musí být issue, received nebo tax.');
        }
        return $basis;
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
