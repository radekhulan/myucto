<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Oprava vydaných dokladů převedených z POHODY dřív, než převod zakládal platbu za úhrady
 * minulých let ({@see PohodaPriorYearPayments}).
 *
 * Převod podvojného účetnictví úhrady s datem před začátkem agendy přeskočil, uhrazenou
 * část nesl jen `invoices.paid_total`. První změna plateb dokladu (spárování, smazání
 * platby) ji přepočtem ze součtu plateb smaže. Oprava ji doplní dřív, než se to stane:
 * doklad z mapy převodu (`pohoda_import_map`, druh `invoice`), vystavený před začátkem
 * první převedené agendy firmy, dostane na rozdíl `paid_total` proti součtu evidovaných
 * plateb platbu k poslednímu dni před začátkem agendy. Jen faktury a zálohové listy,
 * které nejsou koncept ani stornované.
 *
 * Mění jen evidované platby: deník, `paid_total`, stav ani datum úhrady dokladu zůstávají
 * (součet plateb se po opravě rovná `paid_total`, který už saldokonto bralo jako uhrazenou
 * část). Doklad, jehož úhrady se přepočtem už ztratily, má rozdíl nulový a oprava ho
 * nevidí. Rozdíly u dokladů vystavených v agendě jen vypíše: úhradou minulého roku být
 * nemohou. Idempotentní: po opravě je rozdíl nulový; existující platbu se stejnou poznámkou
 * jen navýší. Každá doplněná platba má záznam v auditní stopě. Bez `$commit` jen vypíše,
 * co by udělala.
 */
final class PohodaPriorYearPaymentRepair
{
    public const AUDIT_ACTION = 'invoice.pohoda_prior_year_payment_restored';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaPriorYearPayments $payments,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $activity,
        private readonly PohodaImportRepository $imports,
    ) {}

    /** @return list<int> firmy s dokončeným převodem z POHODY */
    public function convertedSuppliers(): array
    {
        return $this->imports->completedSuppliers();
    }

    /**
     * `$onlyInvoiceIds` omezí opravu na vybrané doklady (Kontrola dat po převodu zapisuje jen
     * doklady z potvrzeného náhledu).
     *
     * @param list<int> $supplierIds
     * @param ?list<int> $onlyInvoiceIds
     * @return list<array{supplier_id:int,company:string,period_start:?string,paid_on:?string,
     *                    status:'repaired'|'would_repair'|'nothing'|'skipped',reason:?string,
     *                    count:int,amount_czk:float,other_count:int,other_amount_czk:float,
     *                    invoices:list<array{id:int,document_no:string,invoice_type:string,status:string,issue_date:string,
     *                                       currency:string,amount_to_pay:float,paid_total:float,paid_before:float,amount:float,amount_czk:float}>,
     *                    other_invoices:list<array<string,mixed>>}> `other_invoices` = rozdíly u dokladů roku agendy, oprava je nemění
     */
    public function run(array $supplierIds, bool $commit, ?int $userId = null, ?array $onlyInvoiceIds = null): array
    {
        $out = [];
        foreach ($supplierIds as $supplierId) {
            $out[] = $this->supplier($supplierId, $commit, $userId, $onlyInvoiceIds);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    /** @param ?list<int> $onlyInvoiceIds jen tyto doklady (oprava potvrzeného plánu); `null` = všechny */
    private function supplier(int $supplierId, bool $commit, ?int $userId, ?array $onlyInvoiceIds = null): array
    {
        $pdo = $this->db->pdo();
        $company = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $company->execute([$supplierId]);
        $row = [
            'supplier_id' => $supplierId, 'company' => (string) ($company->fetchColumn() ?: ''), 'period_start' => null, 'paid_on' => null,
            'status' => 'nothing', 'reason' => null, 'count' => 0, 'amount_czk' => 0.0, 'other_count' => 0, 'other_amount_czk' => 0.0, 'invoices' => [],
            'other_invoices' => [],
        ];
        $start = $this->agendaStart($supplierId);
        if ($start === null) {
            return ['status' => 'skipped', 'reason' => 'Začátek první převedené agendy nejde určit (chybí dokončený převod nebo počáteční stavy z POHODY).'] + $row;
        }
        $row['period_start'] = $start;
        $row['paid_on'] = PohodaPriorYearPayments::paidOn($start);

        $own = !$pdo->inTransaction();
        if ($commit && $own) {
            $pdo->beginTransaction();
        }
        try {
            $candidates = $this->candidates($supplierId, $start, $commit);
            if ($onlyInvoiceIds !== null) {
                $only = array_fill_keys($onlyInvoiceIds, true);
                $candidates = array_values(array_filter($candidates, static fn (array $c): bool => isset($only[$c['id']])));
            }
            foreach ($candidates as $c) {
                if ($c['issue_date'] >= $start) {
                    $row['other_count']++;
                    $row['other_amount_czk'] += $c['amount_czk'];
                    $row['other_invoices'][] = $c;
                    continue;
                }
                $row['count']++;
                $row['amount_czk'] += $c['amount_czk'];
                $row['invoices'][] = $c;
            }
            $row['amount_czk'] = round($row['amount_czk'], 2);
            $row['other_amount_czk'] = round($row['other_amount_czk'], 2);
            if ($row['invoices'] !== []) {
                $row['status'] = $commit ? 'repaired' : 'would_repair';
            }
            if ($commit && $row['invoices'] !== []) {
                $this->apply($supplierId, $start, $row['invoices'], $userId);
            }
            if ($commit && $own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($commit && $own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $row;
    }

    /**
     * Začátek první převedené agendy: počáteční stavy z deníku POHODY nesou první den
     * účetního období ({@see PohodaAgendaYear}), i u hospodářského roku, který rok agendy
     * v názvu exportu nepozná. Daňová evidence se vede po kalendářních letech; podvojná
     * agenda bez počátečních stavů jen u firmy s kalendářním rokem.
     */
    private function agendaStart(int $supplierId): ?string
    {
        $pdo = $this->db->pdo();
        $opening = $pdo->prepare("SELECT MIN(e.entry_date) FROM pohoda_import_map m
              JOIN journal_entries e ON e.id = m.target_id AND e.supplier_id = m.supplier_id
             WHERE m.supplier_id = ? AND m.kind = 'journal_entry' AND e.source_type = 'opening'");
        $opening->execute([$supplierId]);
        $date = $opening->fetchColumn();
        if (is_string($date) && $date !== '') {
            return substr($date, 0, 10);
        }
        $year = $pdo->prepare("SELECT MIN(agenda_year) FROM pohoda_imports
            WHERE supplier_id = ? AND mode = 'import' AND status IN ('completed', 'completed_with_warnings') AND agenda_year IS NOT NULL");
        $year->execute([$supplierId]);
        $agendaYear = $year->fetchColumn();
        if ($agendaYear === null || $agendaYear === false) {
            return null;
        }
        $taxEvidence = $pdo->prepare("SELECT MIN(CAST(pohoda_key AS UNSIGNED)) FROM pohoda_import_map WHERE supplier_id = ? AND kind = 'tax_evidence_year'");
        $taxEvidence->execute([$supplierId]);
        $teYear = $taxEvidence->fetchColumn();
        if ($teYear !== null && $teYear !== false) {
            return sprintf('%04d-01-01', min((int) $teYear, (int) $agendaYear));
        }
        return $this->periods->fiscalCalendar($supplierId)->isCalendar() ? sprintf('%04d-01-01', (int) $agendaYear) : null;
    }

    /**
     * Doklady převodu s rozdílem `paid_total` proti součtu plateb (i ty vystavené v agendě,
     * ty volající jen vypíše).
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $supplierId, string $start, bool $forUpdate): array
    {
        $types = "'" . implode("','", PohodaPriorYearPayments::PAYABLE_TYPES) . "'";
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS document_no, i.invoice_type, i.status, i.issue_date,
                    cur.code AS currency, i.exchange_rate, i.amount_to_pay, i.import_amount_to_pay_czk, i.paid_total,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS paid_before
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.invoice_type IN ({$types}) AND i.status NOT IN ('draft', 'cancelled')
                AND EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = 'invoice' AND m.target_id = i.id)
             HAVING i.paid_total - paid_before >= 0.01
              ORDER BY i.issue_date, i.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $amount = round((float) $r['paid_total'] - (float) $r['paid_before'], 2);
            $rate = (string) $r['currency'] !== 'CZK' ? (IssuedCzkAmounts::settlementRate($r) ?: 1.0) : 1.0;
            $out[] = [
                'id' => (int) $r['id'], 'document_no' => (string) $r['document_no'], 'invoice_type' => (string) $r['invoice_type'],
                'status' => (string) $r['status'], 'issue_date' => (string) $r['issue_date'], 'currency' => (string) $r['currency'],
                'amount_to_pay' => (float) $r['amount_to_pay'], 'paid_total' => (float) $r['paid_total'],
                'paid_before' => (float) $r['paid_before'], 'amount' => $amount, 'amount_czk' => IssuedCzkAmounts::allocated($r, $amount, $rate, (float) $r['paid_before']),
            ];
        }
        return $out;
    }

    /**
     * Zápis s pojistkami: deník firmy, `paid_total` a stav dokladů se nesmí změnit a součet
     * plateb opravených dokladů se musí rovnat `paid_total`. Jinak výjimka a volající vrátí
     * celou firmu.
     *
     * @param list<array<string,mixed>> $invoices
     */
    private function apply(int $supplierId, string $start, array $invoices, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $journal = static function () use ($pdo, $supplierId): string {
            $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM journal_entries WHERE supplier_id = ?');
            $stmt->execute([$supplierId]);
            return implode('/', $stmt->fetch(PDO::FETCH_NUM) ?: []);
        };
        $journalBefore = $journal();
        $check = $pdo->prepare('SELECT i.status, i.paid_total, i.paid_at,
                (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS paid
              FROM invoices i WHERE i.id = ? AND i.supplier_id = ?');
        foreach ($invoices as $inv) {
            $check->execute([$inv['id'], $supplierId]);
            $before = $check->fetch(PDO::FETCH_ASSOC);
            $this->payments->add($supplierId, $inv['id'], $start, $inv['amount'], $inv['currency'], $userId);
            $check->execute([$inv['id'], $supplierId]);
            $after = $check->fetch(PDO::FETCH_ASSOC);
            if ($before === false || $after === false || $after['status'] !== $before['status'] || $after['paid_total'] !== $before['paid_total']
                || $after['paid_at'] !== $before['paid_at'] || abs((float) $after['paid'] - (float) $after['paid_total']) >= 0.005) {
                throw new \RuntimeException("Doklad #{$inv['id']} ({$inv['document_no']}) by po doplnění platby nesouhlasil, firma {$supplierId} se neopravuje.");
            }
            $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', $inv['id'], [
                'source' => 'pohoda_prior_year_payment_repair',
                'document_no' => $inv['document_no'],
                'amount' => $inv['amount'],
                'currency' => $inv['currency'],
                'paid_on' => PohodaPriorYearPayments::paidOn($start),
                'paid_total' => $inv['paid_total'],
                'payments_before' => $inv['paid_before'],
                'note' => PohodaPriorYearPayments::NOTE,
            ], null, null, $supplierId);
        }
        if ($journal() !== $journalBefore) {
            throw new \RuntimeException("Oprava by změnila deník firmy {$supplierId}, nic se nezapsalo.");
        }
    }
}
