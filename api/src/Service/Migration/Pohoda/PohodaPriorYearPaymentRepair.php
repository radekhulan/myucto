<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Http\IsoDate;
use MyInvoice\Service\Invoice\InvoicePaymentService;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
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
 *
 * Doklady, jejichž úhrady minulých let už přepočet smazal (rozdíl nulový), a doklady, kterým
 * stará oprava připsala jako úhradu minulých let i úhradu z agendy bez pohybu v exportu,
 * opraví {@see runBalances()} podle knihy pohledávek účetní k datu.
 */
final class PohodaPriorYearPaymentRepair
{
    public const AUDIT_ACTION = 'invoice.pohoda_prior_year_payment_restored';
    public const MODE_BALANCES = 'balances';

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaPriorYearPayments $payments,
        private readonly AccountingPeriodRepository $periods,
        private readonly ActivityLogger $activity,
        private readonly PohodaImportRepository $imports,
        private readonly InvoicePaymentService $invoicePayments,
        private readonly MigrationCheckLocks $locks,
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
        $journalBefore = $this->journalState($supplierId);
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
        if ($this->journalState($supplierId) !== $journalBefore) {
            throw new \RuntimeException("Oprava by změnila deník firmy {$supplierId}, nic se nezapsalo.");
        }
    }

    /** Otisk deníku firmy pro pojistku „oprava deník nemění". */
    private function journalState(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM journal_entries WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        return implode('/', $stmt->fetch(PDO::FETCH_NUM) ?: []);
    }

    /**
     * Oprava úhrad minulých let podle knihy pohledávek účetní k datu `$asOf` (zbývá uhradit
     * za doklad v Kč, POHODA ji tiskne jen za neuhrazené doklady).
     *
     * Původní úhrady z POHODY jsou po přepočtu ztracené, kniha je jediný zdroj. Doklad
     * z převodu (faktura, zálohový list), vystavený před začátkem agendy a uvedený v knize,
     * dostane úhradu minulých let absolutně:
     * `k úhradě − zbývá podle knihy − platby dokladu s datem do $asOf` (bez úhrady minulých
     * let samotné; platby před agendou se odečtou také, ať se úhrada nezdvojí). Snižuje i úhradu
     * minulých let, kterou stará oprava nadsadila o úhradu z agendy bez pohybu v exportu.
     * Uhrazenou částku, stav a datum úhrady pak přepočte {@see InvoicePaymentService} ze
     * součtu plateb, uhrazený je doklad jen podle evidovaných plateb.
     *
     * Odmítne (nezapíše, jen ohlásí) doklad, který by po všech dnešních platbách byl
     * přeplacený, doklad se zápornou úhradou minulých let a doklad v cizí měně (kniha je v Kč).
     * Ohlásí čísla z knihy, která ve firmě nejsou, doklady z knihy, které oprava neřeší, a doklady
     * před agendou, které v knize chybí (k datu knihy uhrazené, nemění se).
     *
     * Pojistky jako u doplnění: deník se nezmění, platba je k poslednímu dni před agendou
     * a blokuje ji jen zámek účtování k datu a podané období DPH
     * ({@see MigrationCheckLocks::paymentRecordReason()}), pak se nezapíše nic. Každá změna
     * má záznam v auditní stopě ({@see AUDIT_ACTION}, `mode` = {@see MODE_BALANCES}).
     * Idempotentní: druhý běh se stejnou knihou nic nemění. Bez `$commit` jen vypíše plán.
     *
     * @param array<string|int,float> $balances číslo dokladu => zbývá uhradit podle knihy ({@see parseBalances()})
     * @return array<string,mixed>
     */
    public function runBalances(int $supplierId, array $balances, string $asOf, bool $commit, ?int $userId = null): array
    {
        if (!IsoDate::isValid($asOf)) {
            throw new \InvalidArgumentException("Neplatné datum knihy pohledávek {$asOf}.");
        }
        $pdo = $this->db->pdo();
        $company = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $company->execute([$supplierId]);
        $row = [
            'supplier_id' => $supplierId, 'company' => (string) ($company->fetchColumn() ?: ''), 'mode' => self::MODE_BALANCES, 'as_of' => $asOf,
            'period_start' => null, 'paid_on' => null, 'status' => 'nothing', 'reason' => null,
            'documents' => [], 'skipped' => [], 'not_found' => [], 'missing' => [],
            'totals' => ['count' => 0, 'changed' => 0, 'refused' => 0, 'old_prior' => 0.0, 'new_prior' => 0.0, 'remaining_after' => 0.0],
        ];
        $start = $this->agendaStart($supplierId);
        if ($start === null) {
            return ['status' => 'skipped', 'reason' => 'Začátek první převedené agendy nejde určit (chybí dokončený převod nebo počáteční stavy z POHODY).'] + $row;
        }
        $row['period_start'] = $start;
        $row['paid_on'] = PohodaPriorYearPayments::paidOn($start);
        if ($asOf < $start) {
            return ['status' => 'skipped', 'reason' => "Kniha pohledávek k {$asOf} je starší než začátek agendy {$start}."] + $row;
        }

        $own = !$pdo->inTransaction();
        if ($commit && $own) {
            $pdo->beginTransaction();
        }
        try {
            $found = [];
            foreach ($this->balanceDocuments($supplierId, array_map('strval', array_keys($balances)), $start, $asOf, $commit) as $d) {
                $no = $d['document_no'];
                $eligible = $d['from_pohoda'] && in_array($d['invoice_type'], PohodaPriorYearPayments::PAYABLE_TYPES, true)
                    && !in_array($d['status'], ['draft', 'cancelled'], true) && $d['issue_date'] < $start;
                if (!array_key_exists($no, $balances)) {
                    if ($eligible) {
                        $row['missing'][] = $d + ['remaining' => round($d['amount_to_pay'] - $d['paid_total'], 2)];
                    }
                    continue;
                }
                $found[$no] = true;
                $book = round((float) $balances[$no], 2);
                if (!$eligible) {
                    $row['skipped'][] = $d + [
                        'book_remaining' => $book, 'remaining' => round($d['amount_to_pay'] - $d['paid_total'], 2),
                        'expected_remaining' => round($book - $d['paid_later'], 2), 'reason' => $this->ineligibleReason($d, $start),
                    ];
                    continue;
                }
                $row['documents'][] = $this->balancePlan($d, $book, $asOf);
            }
            foreach (array_keys($balances) as $no) {
                if (!isset($found[(string) $no])) {
                    $row['not_found'][] = (string) $no;
                }
            }
            foreach ($row['documents'] as $doc) {
                $row['totals']['count']++;
                if ($doc['state'] === 'change') {
                    $row['totals']['changed']++;
                } elseif ($doc['state'] === 'refused') {
                    $row['totals']['refused']++;
                }
                if ($doc['state'] !== 'refused') {
                    $row['totals']['old_prior'] += $doc['old_prior'];
                    $row['totals']['new_prior'] += $doc['new_prior'];
                    $row['totals']['remaining_after'] += $doc['remaining_after'];
                }
            }
            foreach (['old_prior', 'new_prior', 'remaining_after'] as $k) {
                $row['totals'][$k] = round($row['totals'][$k], 2);
            }
            if ($row['totals']['changed'] > 0) {
                $lock = $this->locks->paymentRecordReason($supplierId, $row['paid_on']);
                if ($lock !== null) {
                    $row['status'] = 'skipped';
                    $row['reason'] = $lock;
                } else {
                    $row['status'] = $commit ? 'repaired' : 'would_repair';
                    if ($commit) {
                        $this->applyBalances($supplierId, $start, $asOf, $row['documents'], $userId);
                    }
                }
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
        if ($commit && $own && $row['status'] === 'repaired') {
            // PDF (řádek Uhrazeno, QR na zbytek) a statistiky pohledávek až po commitu; chyba
            // úklidu nesmí změnit výsledek provedené opravy.
            foreach ($row['documents'] as $doc) {
                if ($doc['state'] === 'change') {
                    try {
                        $this->invoicePayments->recompute($doc['id']);
                    } catch (\Throwable $e) {
                        error_log("Obnova PDF a statistik dokladu #{$doc['id']} po opravě úhrad minulých let selhala: " . $e->getMessage());
                    }
                }
            }
        }
        return $row;
    }

    /**
     * Kniha pohledávek z CSV: řádky `číslo dokladu;zbývá uhradit`, volitelná hlavička,
     * BOM, české desetinné čárky a mezery v částkách (i nezlomitelné).
     *
     * @return array<string|int,float> číslo dokladu => zbývá uhradit
     */
    public static function parseBalances(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $out = [];
        $errors = [];
        $first = true;
        foreach (preg_split('/\R/', $csv) ?: [] as $n => $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = array_map(static fn (string $f): string => trim(trim($f), '"'), explode(';', $line));
            if (count($fields) === 3 && $fields[2] === '') {
                array_pop($fields);
            }
            $amount = count($fields) === 2 ? self::parseAmount($fields[1]) : null;
            if ($first) {
                $first = false;
                if ($amount === null && count($fields) === 2) {
                    continue;
                }
            }
            $no = $fields[0] ?? '';
            if ($amount === null || $no === '') {
                $errors[] = sprintf('řádek %d: „%s"', $n + 1, mb_substr($line, 0, 80));
                continue;
            }
            if (array_key_exists($no, $out)) {
                $errors[] = sprintf('řádek %d: doklad %s je v knize podruhé', $n + 1, $no);
                continue;
            }
            $out[$no] = $amount;
        }
        if ($errors !== []) {
            throw new \InvalidArgumentException('Neplatná kniha pohledávek (očekáváno „číslo dokladu;zbývá uhradit"): ' . implode('; ', $errors));
        }
        return $out;
    }

    private static function parseAmount(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}", "\u{202F}", "\t"], '', $value);
        if (str_contains($value, ',')) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? round((float) $value, 2) : null;
    }

    /**
     * Doklady firmy z knihy (podle čísla) a doklady vystavené před agendou (ty bez knihy
     * volající ohlásí jako chybějící), s platbami rozdělenými podle data knihy.
     *
     * @param list<string> $numbers
     * @return list<array<string,mixed>>
     */
    private function balanceDocuments(int $supplierId, array $numbers, string $start, string $asOf, bool $forUpdate): array
    {
        $own = 'ip.source = ? AND ip.note <=> ?';
        $in = $numbers === [] ? '0 = 1' : 'i.varsymbol IN (' . implode(',', array_fill(0, count($numbers), '?')) . ')';
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.varsymbol AS document_no, i.invoice_type, i.status, i.issue_date, cur.code AS currency, i.amount_to_pay, i.paid_total,
                    COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(i.client_snapshot, '$.company_name')), ''), c.company_name, '') AS client,
                    EXISTS (SELECT 1 FROM pohoda_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = 'invoice' AND m.target_id = i.id) AS from_pohoda,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND {$own}) AS own_prior,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND NOT ({$own}) AND ip.paid_on <= ?) AS paid_through,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND NOT ({$own}) AND ip.paid_on > ?) AS paid_later
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
               LEFT JOIN clients c ON c.id = i.client_id
              WHERE i.supplier_id = ? AND ({$in} OR i.issue_date < ?)
              ORDER BY i.issue_date, i.id" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $note = PohodaPriorYearPayments::NOTE;
        $source = PohodaPriorYearPayments::SOURCE;
        $stmt->execute([$source, $note, $source, $note, $asOf, $source, $note, $asOf, $supplierId, ...$numbers, $start]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int) $r['id'], 'document_no' => (string) ($r['document_no'] ?? ('#' . $r['id'])), 'client' => (string) $r['client'],
                'invoice_type' => (string) $r['invoice_type'], 'status' => (string) $r['status'], 'issue_date' => (string) $r['issue_date'],
                'currency' => (string) $r['currency'], 'amount_to_pay' => (float) $r['amount_to_pay'], 'paid_total' => (float) $r['paid_total'],
                'from_pohoda' => (bool) $r['from_pohoda'], 'old_prior' => round((float) $r['own_prior'], 2),
                'paid_through' => round((float) $r['paid_through'], 2), 'paid_later' => round((float) $r['paid_later'], 2),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $d */
    private function ineligibleReason(array $d, string $start): string
    {
        return match (true) {
            !$d['from_pohoda'] => 'Doklad nepochází z převodu POHODY.',
            in_array($d['status'], ['draft', 'cancelled'], true) => 'Koncept nebo stornovaný doklad.',
            $d['invoice_type'] === 'credit_note' => 'Dobropis nemá evidované platby, zbývající částku nese stav dokladu.',
            !in_array($d['invoice_type'], PohodaPriorYearPayments::PAYABLE_TYPES, true) => "Druh dokladu {$d['invoice_type']} nemá evidované platby.",
            default => "Vystaven v převedené agendě (od {$start}), úhradu minulých let mít nemůže.",
        };
    }

    /**
     * Plán jednoho dokladu: nová úhrada minulých let, zbytek po všech dnešních platbách
     * a stav `change` | `unchanged` | `refused` s důvodem.
     *
     * @param array<string,mixed> $d
     * @return array<string,mixed>
     */
    private function balancePlan(array $d, float $book, string $asOf): array
    {
        $tolerance = InvoicePaymentService::TOLERANCE;
        $prior = round($d['amount_to_pay'] - $book - $d['paid_through'], 2);
        $remainingAfter = round($d['amount_to_pay'] - max($prior, 0.0) - $d['paid_through'] - $d['paid_later'], 2);
        $plan = $d + [
            'book_remaining' => $book, 'new_prior' => $prior, 'remaining_before' => round($d['amount_to_pay'] - $d['paid_total'], 2),
            'remaining_after' => $remainingAfter, 'state' => 'change', 'reason' => null,
        ];
        $fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
        if ($d['currency'] !== 'CZK') {
            return ['state' => 'refused', 'reason' => 'Doklad v cizí měně, kniha pohledávek je v Kč. Opravte ho ručně.'] + $plan;
        }
        if ($prior < -$tolerance) {
            return ['state' => 'refused', 'reason' => sprintf('Kniha uvádí zbytek %s, víc než dovolí platby do %s (%s); úhrada minulých let by byla záporná.',
                $fmt($book), $asOf, $fmt($d['amount_to_pay'] - $d['paid_through']))] + $plan;
        }
        if ($remainingAfter < -$tolerance) {
            return ['state' => 'refused', 'reason' => sprintf('Po platbách po %s (%s) by byl doklad přeplacený o %s.',
                $asOf, $fmt($d['paid_later']), $fmt(-$remainingAfter))] + $plan;
        }
        $prior = $prior < 0.01 ? 0.0 : $prior;
        $plan['new_prior'] = $prior;
        $plan['remaining_after'] = round($d['amount_to_pay'] - $prior - $d['paid_through'] - $d['paid_later'], 2);
        if (abs($prior - $d['old_prior']) < 0.005) {
            $plan['state'] = 'unchanged';
        }
        return $plan;
    }

    /**
     * Zápis plánu podle knihy s pojistkami: úhrada minulých let i zbytek dokladu po přepočtu
     * sedí na plán, uhrazená částka je součet plateb a deník firmy se nezmění. Jinak výjimka
     * a volající vrátí celou firmu.
     *
     * @param list<array<string,mixed>> $documents
     */
    private function applyBalances(int $supplierId, string $start, string $asOf, array $documents, ?int $userId): void
    {
        $pdo = $this->db->pdo();
        $journalBefore = $this->journalState($supplierId);
        $check = $pdo->prepare('SELECT i.status, i.paid_total, i.paid_at, i.amount_to_pay,
                (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS paid,
                (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id AND ip.source = ? AND ip.note = ?) AS prior
              FROM invoices i WHERE i.id = ? AND i.supplier_id = ?');
        $state = static function (int $id) use ($check, $supplierId): array|false {
            $check->execute([PohodaPriorYearPayments::SOURCE, PohodaPriorYearPayments::NOTE, $id, $supplierId]);
            return $check->fetch(PDO::FETCH_ASSOC);
        };
        foreach ($documents as $doc) {
            if ($doc['state'] !== 'change') {
                continue;
            }
            $before = $state($doc['id']);
            $action = $this->payments->set($supplierId, $doc['id'], $start, $doc['new_prior'], $doc['currency'], $userId);
            $this->invoicePayments->recompute($doc['id'], false);
            $after = $state($doc['id']);
            if ($before === false || $after === false || abs((float) $after['prior'] - $doc['new_prior']) >= 0.005
                || abs((float) $after['paid_total'] - (float) $after['paid']) >= 0.005
                || abs((float) $after['amount_to_pay'] - (float) $after['paid_total'] - $doc['remaining_after']) >= 0.005) {
                throw new \RuntimeException("Doklad #{$doc['id']} ({$doc['document_no']}) by po opravě podle knihy nesouhlasil, firma {$supplierId} se neopravuje.");
            }
            $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', $doc['id'], [
                'source' => 'pohoda_prior_year_payment_repair',
                'mode' => self::MODE_BALANCES,
                'as_of' => $asOf,
                'document_no' => $doc['document_no'],
                'action' => $action,
                'book_remaining' => $doc['book_remaining'],
                'prior_before' => $doc['old_prior'],
                'prior_after' => $doc['new_prior'],
                'payments_through_as_of' => $doc['paid_through'],
                'payments_after_as_of' => $doc['paid_later'],
                'paid_total_before' => (float) $before['paid_total'],
                'paid_total_after' => (float) $after['paid_total'],
                'status_before' => (string) $before['status'],
                'status_after' => (string) $after['status'],
                'remaining_after' => $doc['remaining_after'],
                'currency' => $doc['currency'],
                'paid_on' => PohodaPriorYearPayments::paidOn($start),
                'note' => PohodaPriorYearPayments::NOTE,
            ], null, null, $supplierId);
        }
        if ($this->journalState($supplierId) !== $journalBefore) {
            throw new \RuntimeException("Oprava by změnila deník firmy {$supplierId}, nic se nezapsalo.");
        }
    }
}
