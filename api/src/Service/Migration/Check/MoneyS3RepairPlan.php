<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPaymentRepair;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3SettledPayments;
use PDO;

/**
 * Plán oprav převodu z Money S3 pro kontroly dat po převodu: co oprava opravdu změní.
 *
 * Opravy převodu ({@see MoneyS3SettledPaymentRepair}, {@see MoneyS3SettledPayments::restorePurchaseSettlements()})
 * opravují firmu celou a samy vybírají doklady, mimo jiné i doklady s nulovým rozdílem
 * (náhrada úhrady mimo evidenci zápočtem) a vazby plateb záloh na DDKP. Plán proto vzniká
 * tak, že se oprava provede v savepointu a z jejího výsledku a rozdílu vazeb se sestaví
 * seznam všech změn. Pro náhled se savepoint vždy vrátí; při opravě se ponechá jen tehdy,
 * když žádná změna neleží za zámkem účtování k datu nebo v podaném období DPH
 * ({@see MigrationCheckLocks::paymentRecordReason()}). Volat uvnitř transakce.
 */
final class MoneyS3RepairPlan
{
    public function __construct(
        private readonly Connection $db,
        private readonly MoneyS3SettledPaymentRepair $repair,
        private readonly MoneyS3SettledPayments $payments,
        private readonly MigrationCheckLocks $locks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Celá oprava Money S3: vydané doklady, krytí přijatých dokladů a vazby plateb záloh na DDKP.
     *
     * @return array{items:list<CheckItem>,kept:bool}
     */
    public function full(int $supplierId, ?int $userId, bool $keep): array
    {
        return $this->inSavepoint($keep, function () use ($supplierId, $userId): array {
            $before = $this->taxDocumentLinks($supplierId);
            $row = $this->repair->run([$supplierId], true, $userId)[0];
            if ($row['status'] === 'skipped') {
                return [];
            }
            $items = [];
            foreach ($row['invoices'] as $i) {
                $rate = $i['currency'] === 'CZK' ? 1.0 : ($i['rate'] ?? 1.0);
                $amount = round($i['settled'] + $i['amount'] - ($i['action'] === 'inserted' ? 0.0 : $i['own_amount']), 2);
                $items[] = $this->item('invoice', (int) $i['id'], (string) $i['document_no'], $i['paid_at'], $amount, $rate, (string) $i['currency'],
                    $i['action'] === 'deleted'
                        ? 'Výplň úhrady po konci převáděných období se zruší, úhradu nese rok vedený v MyÚčtu.'
                        : 'Zápočet nebo úhrada bez dokladu úhrady jen v uhrazené částce, doplní se zápočet nebo platba k datu úhrady.',
                    ['settled' => round((float) $i['settled'], 2), 'without_document' => round((float) $i['amount'], 2), 'action' => $i['action']], $supplierId);
            }
            foreach ($row['purchases'] as $d) {
                $items[] = $this->purchaseItem($supplierId, $d);
            }
            foreach ($row['left_for_matching'] as $i) {
                $rate = $i['currency'] === 'CZK' ? 1.0 : ($i['rate'] ?? 1.0);
                $items[] = new CheckItem('invoice', (int) $i['id'], (string) $i['document_no'], $i['paid_at'], round($i['amount'] * $rate, 2), CheckItem::REVIEW,
                    'Úhrada je spárovaná s pohybem převedené banky bez evidované platby. Doplní ji opakovaný převod z Money S3.',
                    (float) $i['amount'], (string) $i['currency'], ['source' => MigrationSources::MONEY_S3]);
            }
            return array_merge($items, $this->newTaxDocumentLinks($supplierId, $before));
        });
    }

    /**
     * Jen krytí přijatých dokladů ({@see MoneyS3SettledPayments::restorePurchaseSettlements()})
     * se záznamem v auditní stopě.
     *
     * @return array{items:list<CheckItem>,kept:bool}
     */
    public function purchases(int $supplierId, ?int $userId, bool $keep): array
    {
        return $this->inSavepoint($keep, function () use ($supplierId, $userId): array {
            $lastEnd = $this->payments->lastTransferredPeriodEnd($supplierId);
            if ($lastEnd === null) {
                return [];
            }
            $items = [];
            foreach ($this->payments->restorePurchaseSettlements($supplierId, $userId, $lastEnd)['documents'] as $d) {
                $this->activity->log(MoneyS3SettledPaymentRepair::AUDIT_ACTION_PURCHASE, $userId, 'purchase_invoice', (int) $d['id'], [
                    'source' => 'migration_data_check',
                    'document_no' => $d['document_no'],
                    'currency' => $d['currency'],
                    'amount_to_pay' => $d['amount_to_pay'],
                    'covered' => $d['covered'],
                    'internal_settlement' => $d['settled'],
                    'settled_outside_before' => $d['outside'],
                    'settled_outside_amount' => $d['target'],
                ], null, null, $supplierId);
                $items[] = $this->purchaseItem($supplierId, $d);
            }
            return $items;
        });
    }

    /**
     * @param callable():list<CheckItem> $run
     * @return array{items:list<CheckItem>,kept:bool}
     */
    private function inSavepoint(bool $keep, callable $run): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Plán opravy Money S3 se počítá jen uvnitř transakce.');
        }
        $pdo->exec('SAVEPOINT migration_check_money_plan');
        try {
            $items = MigrationCheckLocks::allOrNothing(new CheckResult($run()))->items;
        } catch (\Throwable $e) {
            // Odvolaná transakce (deadlock, lock wait) savepoint nemá, ROLLBACK TO by přemaskoval příčinu.
            if (!TransactionFailure::rollbackIfAborted($pdo, $e) && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT migration_check_money_plan');
            }
            throw $e;
        }
        $kept = $keep && array_filter($items, static fn (CheckItem $i): bool => $i->state === CheckItem::FIXABLE) !== []
            && array_filter($items, static fn (CheckItem $i): bool => $i->state === CheckItem::LOCKED) === [];
        $pdo->exec($kept ? 'RELEASE SAVEPOINT migration_check_money_plan' : 'ROLLBACK TO SAVEPOINT migration_check_money_plan');
        return ['items' => $items, 'kept' => $kept];
    }

    /** @param array<string,mixed> $d doklad z výsledku krytí přijatých dokladů */
    private function purchaseItem(int $supplierId, array $d): CheckItem
    {
        $rate = $d['currency'] === 'CZK' ? 1.0 : ($d['rate'] ?? 1.0);
        $amount = round($d['settled'] + $d['target'] - $d['outside'], 2);
        return $this->item('purchase_invoice', (int) $d['id'], (string) $d['document_no'], $d['paid_at'], $amount, $rate, (string) $d['currency'],
            'Uhrazeno podle Money S3, evidované úhrady doklad nepokrývají. Doplní se zápočet interním dokladem, zbytek jako úhrada mimo evidenci.',
            ['settled' => round((float) $d['settled'], 2), 'outside_before' => round((float) $d['outside'], 2), 'outside_after' => round((float) $d['target'], 2)], $supplierId);
    }

    /** @param array<string,mixed> $details */
    private function item(string $type, int $id, string $label, ?string $date, float $amount, float $rate, string $currency, string $reason, array $details, int $supplierId): CheckItem
    {
        $lock = $this->locks->paymentRecordReason($supplierId, $date);
        return new CheckItem($type, $id, $label, $date, round($amount * $rate, 2), $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED,
            $lock ?? $reason, $amount, $currency, ['source' => MigrationSources::MONEY_S3] + $details);
    }

    /** @return array<int,int> platba => DDKP */
    private function taxDocumentLinks(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, tax_document_invoice_id FROM invoice_payments WHERE supplier_id = ? AND tax_document_invoice_id IS NOT NULL');
        $stmt->execute([$supplierId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /**
     * Vazby plateb záloh na DDKP, které oprava přidala nebo přesunula (DDKP, ke kterému patří).
     *
     * @param array<int,int> $before
     * @return list<CheckItem>
     */
    private function newTaxDocumentLinks(int $supplierId, array $before): array
    {
        $changed = [];
        foreach ($this->taxDocumentLinks($supplierId) as $payment => $taxDocument) {
            if (($before[$payment] ?? null) !== $taxDocument) {
                $changed[$taxDocument] = $payment;
            }
        }
        if ($changed === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare('SELECT td.id, COALESCE(NULLIF(td.varsymbol, \'\'), CONCAT(\'#\', td.id)) AS document_no,
                COALESCE(td.tax_date, td.issue_date) AS tax_date, td.total_with_vat, cur.code AS currency, td.exchange_rate
              FROM invoices td JOIN currencies cur ON cur.id = td.currency_id
             WHERE td.supplier_id = ? AND td.id IN (' . implode(',', array_map('intval', array_keys($changed))) . ') ORDER BY td.id');
        $stmt->execute([$supplierId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $td) {
            $rate = $td['currency'] === 'CZK' || (float) $td['exchange_rate'] <= 0 ? 1.0 : (float) $td['exchange_rate'];
            $out[] = $this->item('invoice', (int) $td['id'], (string) $td['document_no'], (string) $td['tax_date'], (float) $td['total_with_vat'], $rate,
                (string) $td['currency'], 'Platba zálohy z Money se naváže na tento DDKP z Money (úplata je zdaněná DDKP).',
                ['tax_document_link' => true], $supplierId);
        }
        return $out;
    }
}
