<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Shared;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\IssuedCzkAmounts;
use MyInvoice\Service\Invoice\PaidWithoutPaymentRepair;
use MyInvoice\Service\Invoice\TakenOverPayment;
use MyInvoice\Service\Migration\Check\MigrationCheckLocks;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Pohoda\DocumentLinker;
use MyInvoice\Service\Migration\Pohoda\PohodaIssuedRemainders;
use MyInvoice\Service\Migration\Pohoda\PohodaPriorYearPaymentRepair;
use PDO;

/**
 * Doplnění evidence úhrad vydaných dokladů převedených z jiného programu, jejichž uhrazenou
 * částku (`paid_total`) nenesou evidované platby.
 *
 * Uhrazenou část vede původní program a převod ji nesl jen v `paid_total`. První změna plateb
 * dokladu (spárování, smazání platby) ji přepočtem ze součtu plateb smaže a doklad vrátí mezi
 * neuhrazené. Oprava doplní chráněnou platbu dorovnání ({@see TakenOverPayment::isProtected()})
 * k datu úhrady podle převodu, u dokladu bez data úhrady k DUZP. Deník, `paid_total`, stav ani
 * datum úhrady se nemění, součet plateb se po opravě rovná `paid_total`.
 *
 * Zdroje:
 *  - POHODA: doklady roku agendy ({@see PohodaPriorYearPaymentRepair}, `other_invoices`),
 *    poznámka {@see DocumentLinker::REMAINDER_NOTE} jako u dnešního převodu
 *    ({@see PohodaIssuedRemainders}). Úhrady minulých let (doklady vystavené před začátkem
 *    agendy) řeší ta oprava, tahle je nemění.
 *  - Helios: doklady z mapy převodu (`helios_import_map`), poznámka
 *    {@see TakenOverPayment::HELIOS_REMAINDER_NOTE}.
 *
 * Jen se ohlásí:
 *  - `linked_unrecorded`: doklad má spárovaný bankovní pohyb nebo pokladní doklad bez záznamu
 *    platby ({@see PaidWithoutPaymentRepair::linkedUnrecordedSql()}); doplní ho párování,
 *  - `journal_settled`: doklad vyrovnává ruční zápis v deníku (vazba nebo rozpad), platba
 *    by vyrovnání v saldokontu započetla podruhé,
 *  - `locked`: datum dorovnání leží za zámkem účtování nebo v podaném období DPH.
 *
 * Firmu opravuje celou v jedné transakci: nesedí-li po zápisu kterýkoli doklad nebo by se
 * změnil deník, nezapíše nic. Idempotentní. Bez `$commit` jen vypíše, co by udělala.
 */
final class IssuedRemainderRepair
{
    public const AUDIT_ACTION = 'invoice.taken_over_remainder_payment_restored';
    public const FIXABLE = 'fixable';
    public const JOURNAL_SETTLED = 'journal_settled';
    public const LOCKED = 'locked';
    public const LINKED_UNRECORDED = PaidWithoutPaymentRepair::LINKED_UNRECORDED;
    public const SOURCES = [MigrationSources::POHODA, MigrationSources::HELIOS];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaPriorYearPaymentRepair $pohoda,
        private readonly MigrationSources $sources,
        private readonly MigrationCheckLocks $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return list<int> firmy převedené ze zdroje */
    public function convertedSuppliers(string $source): array
    {
        self::assertSource($source);
        return $source === MigrationSources::POHODA
            ? $this->pohoda->convertedSuppliers()
            : array_values(array_filter($this->sources->suppliers(), fn (int $id): bool => $this->sources->has($id, $source)));
    }

    /**
     * @param list<int> $supplierIds
     * @return list<array<string,mixed>>
     */
    public function run(string $source, array $supplierIds, bool $commit, ?int $userId = null): array
    {
        self::assertSource($source);
        $out = [];
        foreach ($supplierIds as $supplierId) {
            $out[] = $this->supplier($source, $supplierId, $commit, $userId);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function supplier(string $source, int $supplierId, bool $commit, ?int $userId): array
    {
        if ($source === MigrationSources::POHODA) {
            $base = $this->pohoda->run([$supplierId], false)[0];
            $docs = $base['status'] === 'skipped' ? [] : ($base['other_invoices'] ?? []);
        } else {
            $company = $this->db->pdo()->prepare('SELECT company_name FROM supplier WHERE id = ?');
            $company->execute([$supplierId]);
            $base = ['company' => (string) ($company->fetchColumn() ?: ''), 'period_start' => null, 'status' => 'nothing', 'reason' => null];
            $docs = $this->heliosCandidates($supplierId);
        }
        $row = [
            'source' => $source, 'supplier_id' => $supplierId, 'company' => (string) $base['company'], 'period_start' => $base['period_start'] ?? null,
            'status' => 'nothing', 'reason' => $base['reason'] ?? null, 'invoices' => [],
            'counts' => [self::FIXABLE => 0, self::LINKED_UNRECORDED => 0, self::JOURNAL_SETTLED => 0, self::LOCKED => 0],
            'amount_czk' => [self::FIXABLE => 0.0, self::LINKED_UNRECORDED => 0.0, self::JOURNAL_SETTLED => 0.0, self::LOCKED => 0.0],
        ];
        if (($base['status'] ?? '') === 'skipped') {
            return ['status' => 'skipped'] + $row;
        }
        if ($docs === []) {
            return $row;
        }
        $details = $this->details($supplierId, array_map(static fn (array $d): int => (int) $d['id'], $docs));
        foreach ($docs as $d) {
            $info = $details[(int) $d['id']] ?? null;
            if ($info === null) {
                continue;
            }
            $lock = $this->locks->paymentRecordReason($supplierId, $info['paid_on']);
            [$category, $reason] = match (true) {
                $info['linked_unrecorded'] => [self::LINKED_UNRECORDED, 'Doklad má spárovaný bankovní pohyb nebo pokladní doklad bez záznamu platby. Úhradu doplní párování, ne dorovnání.'],
                $info['journal_settled'] => [self::JOURNAL_SETTLED, 'Doklad vyrovnává ruční zápis v deníku, platba by vyrovnání započetla podruhé. Ověřte úhrady dokladu.'],
                $lock !== null => [self::LOCKED, $lock],
                default => [self::FIXABLE, $info['date_fallback']
                    ? sprintf('Uhrazená část bez platby, doplní se dorovnání k %s (převod datum úhrady nenese, použije se DUZP).', $info['paid_on'])
                    : sprintf('Uhrazená část bez platby, doplní se dorovnání k %s.', $info['paid_on'])],
            };
            $row['counts'][$category]++;
            $row['amount_czk'][$category] = round($row['amount_czk'][$category] + (float) $d['amount_czk'], 2);
            $row['invoices'][] = $d + ['paid_on' => $info['paid_on'], 'date_fallback' => $info['date_fallback'], 'category' => $category, 'reason' => $reason];
        }
        if ($row['counts'][self::FIXABLE] > 0) {
            $row['status'] = $commit ? 'repaired' : 'would_repair';
        }
        if ($commit && $row['counts'][self::FIXABLE] > 0) {
            $this->apply($source, $supplierId, array_values(array_filter($row['invoices'], static fn (array $i): bool => $i['category'] === self::FIXABLE)), $userId);
        }
        return $row;
    }

    /**
     * Doklady převodu z Heliosu s rozdílem `paid_total` proti součtu plateb (stejný tvar jako
     * `other_invoices` opravy POHODY).
     *
     * @return list<array<string,mixed>>
     */
    private function heliosCandidates(int $supplierId): array
    {
        $types = "'" . implode("','", PohodaIssuedRemainders::PAYABLE_TYPES) . "'";
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS document_no, i.invoice_type, i.status, i.issue_date,
                    cur.code AS currency, i.exchange_rate, i.amount_to_pay, i.import_amount_to_pay_czk, i.paid_total,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS paid_before
               FROM invoices i
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.supplier_id = ? AND i.invoice_type IN ({$types}) AND i.status NOT IN ('draft', 'cancelled')
                AND EXISTS (SELECT 1 FROM helios_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = 'invoice' AND m.target_id = i.id)
             HAVING i.paid_total - paid_before >= 0.01
              ORDER BY i.issue_date, i.id"
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
     * @param list<int> $ids
     * @return array<int,array{paid_on:string,date_fallback:bool,journal_settled:bool,linked_unrecorded:bool}>
     */
    private function details(int $supplierId, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            $rows = $this->db->pdo()->query(
                'SELECT i.id, ' . PohodaIssuedRemainders::paidOnSql('i') . ' AS paid_on, i.paid_at IS NULL AS date_fallback,
                        ' . PohodaIssuedRemainders::journalSettledSql('i') . ' AS journal_settled,
                        ' . PaidWithoutPaymentRepair::linkedUnrecordedSql('i') . " AS linked_unrecorded
                   FROM invoices i WHERE i.supplier_id = {$supplierId} AND i.id IN ({$in})"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $out[(int) $r['id']] = ['paid_on' => (string) $r['paid_on'], 'date_fallback' => (bool) $r['date_fallback'],
                    'journal_settled' => (bool) $r['journal_settled'], 'linked_unrecorded' => (bool) $r['linked_unrecorded']];
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $invoices */
    private function apply(string $source, int $supplierId, array $invoices, ?int $userId): void
    {
        $note = $source === MigrationSources::HELIOS ? TakenOverPayment::HELIOS_REMAINDER_NOTE : DocumentLinker::REMAINDER_NOTE;
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $journal = static function () use ($pdo, $supplierId): string {
                $stmt = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(id), 0) FROM journal_entries WHERE supplier_id = ?');
                $stmt->execute([$supplierId]);
                return implode('/', $stmt->fetch(PDO::FETCH_NUM) ?: []);
            };
            $journalBefore = $journal();
            $check = $pdo->prepare('SELECT i.status, i.paid_total, i.paid_at,
                    (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS paid
                  FROM invoices i WHERE i.id = ? AND i.supplier_id = ? FOR UPDATE');
            foreach ($invoices as $inv) {
                $check->execute([$inv['id'], $supplierId]);
                $before = $check->fetch(PDO::FETCH_ASSOC);
                if ($before === false) {
                    throw new \RuntimeException("Doklad #{$inv['id']} mezitím zmizel, firma {$supplierId} se neopravuje.");
                }
                $amount = round((float) $before['paid_total'] - (float) $before['paid'], 2);
                if ($amount < 0.01) {
                    continue;
                }
                PohodaIssuedRemainders::record($pdo, $supplierId, (int) $inv['id'], (string) $inv['paid_on'], $amount, (string) $inv['currency'], $userId, $note);
                $check->execute([$inv['id'], $supplierId]);
                $after = $check->fetch(PDO::FETCH_ASSOC);
                if ($after === false || $after['status'] !== $before['status'] || $after['paid_total'] !== $before['paid_total']
                    || $after['paid_at'] !== $before['paid_at'] || abs((float) $after['paid'] - (float) $after['paid_total']) >= 0.005) {
                    throw new \RuntimeException("Doklad #{$inv['id']} ({$inv['document_no']}) by po doplnění platby nesouhlasil, firma {$supplierId} se neopravuje.");
                }
                $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', (int) $inv['id'], [
                    'source' => 'issued_remainder_repair',
                    'migration_source' => $source,
                    'document_no' => $inv['document_no'],
                    'amount' => $amount,
                    'currency' => $inv['currency'],
                    'paid_on' => $inv['paid_on'],
                    'date_fallback' => $inv['date_fallback'],
                    'paid_total' => (float) $before['paid_total'],
                    'payments_before' => (float) $before['paid'],
                    'note' => $note,
                ], null, null, $supplierId);
            }
            if ($journal() !== $journalBefore) {
                throw new \RuntimeException("Oprava by změnila deník firmy {$supplierId}, nic se nezapsalo.");
            }
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function assertSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException('Nepodporovaný zdroj převodu: ' . $source);
        }
    }
}
