<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\MoneyS3ImportRepository;
use MyInvoice\Service\ActivityLogger;
use PDO;

/**
 * Oprava firem převedených z Money S3 dřív, než převod zakládal úhrady bez dokladu úhrady
 * ({@see MoneyS3SettledPayments}) a vazbu platby zálohy na DDKP z Money
 * ({@see AdvanceLinker::linkTaxDocumentPayments()}).
 *
 * Převod nesl úhradu zápočtem či interním dokladem a úhradu bez čísla dokladu úhrady jen
 * v `invoices.paid_total`; první změna plateb dokladu (spárování, smazání platby) ji
 * přepočtem ze součtu plateb smaže. Oprava ji doplní dřív, než se to stane:
 *  - vydaný doklad z mapy převodu (faktura, zálohová faktura) uhrazený do konce
 *    posledního převedeného období dostane na rozdíl `paid_total` proti evidovaným platbám
 *    zápočet interním dokladem navázaným na doklad, zbytek platbu bez dokladu úhrady k datu
 *    úhrady. Doklad se spárovaným pohybem banky bez evidované platby výplň nedostane a jen
 *    se ohlásí: platbu doplní opakovaný převod ({@see MoneyS3SettledPayments::pendingBankMatches()}),
 *  - přijatý doklad uhrazený podle Money dostane zápočet interním dokladem navázaným na
 *    doklad a zbytek nekrytý evidovanými úhradami do `settled_outside_amount`,
 *  - jediná platba zálohy z Money se naváže na jediný DDKP z Money se stejnou částkou.
 *
 * Mění jen evidované platby, zápočty, `settled_outside_amount` a vazbu platby na DDKP:
 * deník, `paid_total`, stav ani datum úhrady dokladů se nemění (součet plateb se po opravě
 * rovná `paid_total`). Jinak výjimka a celá firma se vrátí. Číslo úhrady z Money v poznámce
 * oprava nezná (záloha Money není k dispozici), doplní ho opakovaný převod. Idempotentní:
 * po opravě je rozdíl nulový. Každý doplněný doklad má záznam v auditní stopě. Bez
 * `$commit` jen vypíše, co by udělala.
 */
final class MoneyS3SettledPaymentRepair
{
    public const AUDIT_ACTION = 'invoice.money_s3_settled_payment_restored';
    public const AUDIT_ACTION_PURCHASE = 'purchase_invoice.money_s3_settled_outside_restored';

    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3SettledPayments $payments,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return list<int> firmy s dokončeným převodem z Money S3 */
    public function convertedSuppliers(): array
    {
        return array_map('intval', $this->db->pdo()->query(
            "SELECT DISTINCT supplier_id FROM money_s3_imports
              WHERE mode = 'import' AND status IN ('completed', 'completed_with_warnings') ORDER BY supplier_id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $supplierIds
     * @return list<array<string,mixed>>
     */
    public function run(array $supplierIds, bool $commit, ?int $userId = null): array
    {
        $out = [];
        foreach ($supplierIds as $supplierId) {
            $out[] = $this->supplier($supplierId, $commit, $userId);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function supplier(int $supplierId, bool $commit, ?int $userId): array
    {
        $pdo = $this->db->pdo();
        $company = $pdo->prepare('SELECT company_name FROM supplier WHERE id = ?');
        $company->execute([$supplierId]);
        $row = [
            'supplier_id' => $supplierId, 'company' => (string) ($company->fetchColumn() ?: ''), 'last_period_end' => null,
            'status' => 'nothing', 'reason' => null,
            'issued_count' => 0, 'issued_amount_czk' => 0.0, 'settlements' => 0, 'settlement_amount_czk' => 0.0, 'without_document_amount_czk' => 0.0,
            'purchase_count' => 0, 'purchase_amount_czk' => 0.0, 'purchase_settlements' => 0, 'purchase_settlement_amount_czk' => 0.0, 'tax_document_links' => 0,
            'invoices' => [], 'purchases' => [], 'left_for_matching' => [],
        ];
        $lastEnd = $this->payments->lastTransferredPeriodEnd($supplierId);
        if ($lastEnd === null) {
            return ['status' => 'skipped', 'reason' => 'Firma nemá období převedené z Money S3 (podvojné účetnictví).'] + $row;
        }
        $row['last_period_end'] = $lastEnd;

        // Zkouška nanečisto jde stejnou cestou jako zápis a na konci se vrátí: výpis tak
        // přesně odpovídá tomu, co by zápis udělal.
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT money_s3_settled_repair');
        }
        try {
            $journalBefore = $this->journal($supplierId);
            $before = $this->issuedState($supplierId);

            $issued = $this->payments->recordIssued($supplierId, $userId, $lastEnd);
            foreach ($issued['invoices'] as $i) {
                $rate = $i['currency'] === 'CZK' ? 1.0 : ($i['rate'] ?? 1.0);
                $row['issued_count']++;
                $row['issued_amount_czk'] += round(($i['settled'] + $i['amount'] - ($i['action'] === 'inserted' ? 0.0 : $i['own_amount'])) * $rate, 2);
                $row['settlement_amount_czk'] += round($i['settled'] * $rate, 2);
                $row['without_document_amount_czk'] += round(($i['amount'] - ($i['action'] === 'inserted' ? 0.0 : $i['own_amount'])) * $rate, 2);
                $row['invoices'][] = $i;
            }
            $row['settlements'] = $issued['settlements'];
            $row['left_for_matching'] = $issued['left_for_matching'];

            $purchases = $this->payments->restorePurchaseSettlements($supplierId, $userId, $lastEnd);
            foreach ($purchases['documents'] as $d) {
                $rate = $d['currency'] === 'CZK' ? 1.0 : ($d['rate'] ?? 1.0);
                $row['purchase_count']++;
                $row['purchase_amount_czk'] += round(($d['settled'] + $d['target'] - $d['outside']) * $rate, 2);
                $row['purchase_settlement_amount_czk'] += round($d['settled'] * $rate, 2);
                $row['purchases'][] = $d;
            }
            $row['purchase_settlements'] = $purchases['settlements'];
            $row['tax_document_links'] = (new AdvanceLinker($this->db))->linkTaxDocumentPayments($supplierId);

            foreach (['issued_amount_czk', 'settlement_amount_czk', 'without_document_amount_czk', 'purchase_amount_czk', 'purchase_settlement_amount_czk'] as $k) {
                $row[$k] = round($row[$k], 2);
            }
            $this->assertUnchanged($supplierId, $journalBefore, $before);
            if ($row['invoices'] !== [] || $row['purchases'] !== [] || $row['tax_document_links'] > 0) {
                $row['status'] = $commit ? 'repaired' : 'would_repair';
            }
            if ($commit) {
                $this->audit($supplierId, $userId, $row);
            }
            if ($own) {
                $commit ? $pdo->commit() : $pdo->rollBack();
            } else {
                if (!$commit) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_settled_repair');
                }
                $pdo->exec('RELEASE SAVEPOINT money_s3_settled_repair');
            }
        } catch (\Throwable $e) {
            // Odvolaná transakce (deadlock, lock wait) savepoint nemá, ROLLBACK TO by přemaskoval příčinu.
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif (!$own && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT money_s3_settled_repair');
                $pdo->exec('RELEASE SAVEPOINT money_s3_settled_repair');
            }
            throw $e;
        }
        return $row;
    }

    /**
     * Pojistky: deník firmy, `paid_total`, stav a datum úhrady vydaných dokladů se nesmí
     * změnit a součet plateb opravených dokladů nesmí přesáhnout `paid_total`.
     *
     * @param array<int,array{status:string,paid_total:string,paid_at:?string}> $before
     */
    private function assertUnchanged(int $supplierId, string $journalBefore, array $before): void
    {
        if ($this->journal($supplierId) !== $journalBefore) {
            throw new \RuntimeException("Oprava by změnila deník firmy {$supplierId}, nic se nezapsalo.");
        }
        $after = $this->issuedState($supplierId);
        foreach ($after as $id => $a) {
            $b = $before[$id] ?? null;
            if ($b === null || $a['status'] !== $b['status'] || $a['paid_total'] !== $b['paid_total'] || $a['paid_at'] !== $b['paid_at']) {
                throw new \RuntimeException("Doklad #{$id} by po opravě změnil stav nebo uhrazenou částku, firma {$supplierId} se neopravuje.");
            }
            if ((float) $a['paid'] - (float) $a['paid_total'] >= 0.005 && (float) $b['paid'] - (float) $b['paid_total'] < 0.005) {
                throw new \RuntimeException("Doklad #{$id} by měl platby nad uhrazenou částkou, firma {$supplierId} se neopravuje.");
            }
        }
    }

    /** @return array<int,array{status:string,paid_total:string,paid_at:?string,paid:string}> */
    private function issuedState(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.status, i.paid_total, i.paid_at, COALESCE(p.paid, 0) AS paid
               FROM invoices i
               LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM invoice_payments WHERE supplier_id = ? GROUP BY invoice_id) p ON p.invoice_id = i.id
              WHERE i.supplier_id = ?
                AND EXISTS (SELECT 1 FROM money_s3_import_map m WHERE m.supplier_id = i.supplier_id AND m.kind = ? AND m.target_id = i.id)"
        );
        $stmt->execute([$supplierId, $supplierId, MoneyS3ImportRepository::KIND_INVOICE]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = ['status' => (string) $r['status'], 'paid_total' => (string) $r['paid_total'],
                'paid_at' => $r['paid_at'] === null ? null : (string) $r['paid_at'], 'paid' => (string) $r['paid']];
        }
        return $out;
    }

    private function journal(int $supplierId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*), COALESCE(MAX(e.id), 0), COALESCE(MAX(e.row_version), 0),
                    (SELECT COUNT(*) FROM journal_entry_lines l WHERE l.supplier_id = ?),
                    (SELECT COALESCE(SUM(l.signed_amount), 0) FROM journal_entry_lines l WHERE l.supplier_id = ?)
               FROM journal_entries e WHERE e.supplier_id = ?'
        );
        $stmt->execute([$supplierId, $supplierId, $supplierId]);
        return implode('/', $stmt->fetch(PDO::FETCH_NUM) ?: []);
    }

    /** @param array<string,mixed> $row */
    private function audit(int $supplierId, ?int $userId, array $row): void
    {
        foreach ($row['invoices'] as $i) {
            $this->activity->log(self::AUDIT_ACTION, $userId, 'invoice', $i['id'], [
                'source' => 'money_s3_settled_payment_repair',
                'document_no' => $i['document_no'],
                'currency' => $i['currency'],
                'paid_on' => $i['paid_at'],
                'paid_total' => $i['paid_total'],
                'payments_before' => round($i['others'] + $i['own_amount'], 2),
                'internal_settlement' => $i['settled'],
                'without_document' => $i['amount'],
                'action' => $i['action'],
            ], null, null, $supplierId);
        }
        foreach ($row['purchases'] as $d) {
            $this->activity->log(self::AUDIT_ACTION_PURCHASE, $userId, 'purchase_invoice', $d['id'], [
                'source' => 'money_s3_settled_payment_repair',
                'document_no' => $d['document_no'],
                'currency' => $d['currency'],
                'amount_to_pay' => $d['amount_to_pay'],
                'covered' => $d['covered'],
                'internal_settlement' => $d['settled'],
                'settled_outside_before' => $d['outside'],
                'settled_outside_amount' => $d['target'],
            ], null, null, $supplierId);
        }
    }
}
