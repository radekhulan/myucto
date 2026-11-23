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
 * Přečíslování přijatých dokladů jednoho měsíce podle data pro číslování.
 *
 * Interní číslo dostává doklad při přijetí, takže pořadí v řadě odpovídá pořadí
 * přijímání, ne datu. Přečíslování vezme automaticky očíslované doklady, jejichž datum
 * pro číslování ({@see PurchaseNumberingDate}: vystavení, přijetí nebo DUZP) leží v měsíci,
 * seřadí je podle něj a v každé řadě jim přidělí čísla od 1. Řadu určuje AKTUÁLNÍ typ
 * dokladu, takže doklad, kterému se po očíslování změnil typ, se přesune do správné řady.
 *
 * Volbu data bere náhled z nastavení firmy (`supplier.purchase_numbering_date_basis`,
 * stejné nastavení, podle kterého dostává číslo nový doklad), dialog ji může změnit
 * a provedené přečíslování ji uloží. Doklad bez zvoleného data se řadí podle data vystavení.
 *
 * Doklad měsíce s automatickým číslem jiného měsíce (číslo dostal podle jiného data)
 * dostane číslo tohoto měsíce. Číslo původního měsíce se tím uvolní, nic jiného se
 * v jeho řadě nemění. Nepřesune se doklad, který je sám po uzávěrce nebo uzamčený
 * (`document_locked`), ani když je po uzávěrce nebo uzamčený původní měsíc čísla
 * (`number_month_locked`) či přečíslovaný měsíc (`month_locked`). Doklad s číslem tohoto
 * měsíce, jehož datum pro číslování leží jinde, si číslo nechá (`date_other_month`),
 * přesune ho přečíslování jeho měsíce.
 *
 * Mimo přečíslování zůstávají ručně zadaná a cizí čísla (neodpovídají šabloně).
 * Jejich čísla se při přidělování přeskočí.
 *
 * U přijatých dokladů se souvislá řada nevyžaduje a do kontrolního hlášení jde
 * číslo dodavatele, takže přečíslování daňově nic nemění. Kopie interního čísla
 * v deníku (číslo dokladu a popis zápisu, včetně storna) a v popisu příjemky se
 * přepíší. Měsíc, jehož doklad je po uzávěrce (uzavřené i uzavírané účetní období,
 * dokončená roční uzávěrka daňové evidence) nebo uzamčený k datu, se nepřečísluje vůbec.
 */
final class PurchaseInvoiceRenumberService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly DocumentLockService $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /** Datum pro výběr měsíce a pořadí, viz {@see PurchaseNumberingDate::BASES}. */
    public const BASES = PurchaseNumberingDate::BASES;

    /** @var array<string, bool> období (YYYYMM / YYYY) → po uzávěrce nebo uzamčené */
    private array $periodLocks = [];

    /** Nastavení firmy ({@see BASES}), bez něj datum vystavení. */
    public function storedBasis(int $supplierId): string
    {
        return PurchaseNumberingDate::stored($this->db, $supplierId);
    }

    /**
     * Náhled přečíslování (nic nezapisuje).
     *
     * @param string|null $basis datum pro výběr a pořadí ({@see BASES}), null = nastavení firmy
     * @return array{period: string, date_basis: string, changes: list<array<string,mixed>>, kept: list<array<string,mixed>>, blocked: list<array<string,mixed>>}
     */
    public function preview(int $supplierId, string $period, ?string $basis = null): array
    {
        $period = self::normalizePeriod($period);
        $basis = $basis === null ? $this->storedBasis($supplierId) : self::normalizeBasis($basis);
        $this->periodLocks = [];
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

        $basisDate = PurchaseNumberingDate::sql($basis, 'p.');
        $from = substr($period, 0, 4) . '-' . substr($period, 4, 2) . '-01';
        $to = date('Y-m-t', (int) strtotime($from));
        $stmt = $this->db->pdo()->prepare(
            "SELECT p.id, p.supplier_id, p.varsymbol, p.vendor_invoice_number, p.document_kind, p.issue_date,
                    p.tax_date, p.received_at, p.effective_cost_date, p.booked_at, p.status, p.vat_deduction,
                    p.tax_deductible, c.company_name
               FROM purchase_invoices p
               LEFT JOIN clients c ON c.id = p.vendor_id AND c.supplier_id = p.supplier_id
              WHERE p.supplier_id = ? AND p.varsymbol IS NOT NULL AND p.varsymbol <> ''
              ORDER BY {$basisDate} ASC, p.issue_date ASC, p.id ASC"
        );
        $stmt->execute([$supplierId]);

        $participants = [];
        $kept = [];
        /** @var array<string, true> $occupied čísla řad tohoto měsíce, která přečíslování nesmí použít */
        $occupied = [];
        $monthLocked = null;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $vs = (string) $row['varsymbol'];
            $row['date_fallback'] = PurchaseNumberingDate::usesFallback($row, $basis);
            $row['basis_date'] = PurchaseNumberingDate::of($row, $basis);
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
            if (!$inMonth) {
                if ($auto) {
                    $occupied[$vs] = true;
                }
                if ($monthNumber) {
                    // Číslo tohoto měsíce, ale datum pro číslování leží jinde: číslo zůstane obsazené.
                    $kept[] = self::describe($row) + ['reason' => 'date_other_month'];
                }
                continue;
            }
            $origin = self::numberOrigin($series, $vs);
            if ($origin === null) {
                $occupied[$vs] = true;
                $kept[] = self::describe($row) + ['reason' => 'manual_number'];
                continue;
            }
            // Automatické číslo jiného měsíce: doklad dostane číslo tohoto měsíce, pokud se
            // nic nemění po uzávěrce ani v zamčeném období (doklad, původní ani tento měsíc).
            $row['from_period'] = $origin;
            $lock = $this->locks->forPurchaseInvoice($row);
            $monthLocked ??= $this->periodLocked($supplierId, $period);
            $reason = match (true) {
                $lock->inClosedPeriod || $lock->inClosingPeriod || $lock->dateLocked => 'document_locked',
                $this->periodLocked($supplierId, $origin, substr((string) $row['issue_date'], 0, 4)) => 'number_month_locked',
                $monthLocked => 'month_locked',
                default => null,
            };
            if ($reason !== null) {
                $kept[] = self::describe($row) + ['reason' => $reason];
                continue;
            }
            $participants[] = $row;
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
     * Období automatického čísla podle šablony některé řady (YYYYMM, u roční řady YYYY,
     * u šablony bez roku jen MM, u řady bez data prázdný řetězec), null = ruční nebo cizí číslo.
     *
     * @param array<string, array{template: string, kinds: list<string>}> $series
     */
    private static function numberOrigin(array $series, string $number): ?string
    {
        foreach ($series as $s) {
            $p = PurchaseNumberSeries::numberPeriod($s['template'], $number);
            if ($p !== null) {
                return ($p['year'] ?? '') . ($p['month'] ?? '');
            }
        }
        return null;
    }

    /**
     * Je období (YYYYMM nebo YYYY) po uzávěrce nebo aspoň zčásti uzamčené k datu? Rok
     * s dokončenou roční uzávěrkou daňové evidence, uzavřené nebo uzavírané účetní období
     * na začátku či konci období, zámek k datu od jeho začátku.
     *
     * @param string $fallbackYear rok pro číslo bez roku (šablona jen s {MM})
     */
    private function periodLocked(int $supplierId, string $period, string $fallbackYear = ''): bool
    {
        if (strlen($period) === 2) {
            $period = $fallbackYear . $period;
        }
        if (!preg_match('/^\d{4}(\d{2})?$/', $period)) {
            return false;
        }
        if (isset($this->periodLocks[$period])) {
            return $this->periodLocks[$period];
        }
        $year = substr($period, 0, 4);
        $first = strlen($period) === 6 ? $year . '-' . substr($period, 4, 2) . '-01' : $year . '-01-01';
        $last = strlen($period) === 6 ? date('Y-m-t', (int) strtotime($first)) : $year . '-12-31';
        $start = $this->locks->forDate($supplierId, $first);
        $end = $this->locks->forDate($supplierId, $last);
        return $this->periodLocks[$period] = TaxEvidenceYearLock::isFinal($this->db, $supplierId, (int) $year)
            || $start->dateLocked
            || $start->inClosedPeriod || $start->inClosingPeriod
            || $end->inClosedPeriod || $end->inClosingPeriod;
    }

    /**
     * Přečísluje měsíc. Vrací stejný tvar jako {@see preview()} + počet změněných dokladů.
     * Zadané datum pro výběr a pořadí se uloží do nastavení firmy (platí i pro číslo nového dokladu).
     *
     * @param string|null $basis datum pro výběr a pořadí ({@see BASES}), null = nastavení firmy
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
                $pdo->prepare('UPDATE supplier SET ' . PurchaseNumberingDate::COLUMN . ' = ? WHERE id = ?')->execute([$basis, $supplierId]);
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
            // Období čísla jiného měsíce (YYYYMM / YYYY), které doklad opouští; null = číslo tohoto měsíce.
            'from_period'           => isset($row['from_period']) ? (string) $row['from_period'] : null,
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
