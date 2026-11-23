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
 * (náhrada úhrady mimo evidenci zápočtem), vazby plateb záloh na DDKP a jejich přesun či
 * zánik při rušení výplně. Plán proto vzniká z rozdílu dat: oprava se provede v savepointu
 * a porovnají se řádky, na které smí sáhnout (evidované platby, zápočty, úhrada mimo
 * evidenci), před a po ní. Každá změna nese skutečné hodnoty (datum, částka, interní
 * zápis, protiúčet, DDKP) a projde kontrolou období nad starým i novým datem a nad daty
 * dotčených DDKP ({@see MigrationCheckLocks::paymentRecordReason()}). Pro náhled se savepoint
 * vždy vrátí; při opravě se ponechá jen tehdy, když nic neleží v chráněném období.
 * Volat uvnitř transakce.
 */
final class MoneyS3RepairPlan
{
    /** Sloupce evidovaných plateb, které oprava mění (bez `id`, to nové řádky nemají stejné). */
    private const PAYMENT_COLUMNS = ['invoice_id', 'paid_on', 'amount', 'currency', 'source', 'note', 'tax_document_invoice_id'];

    /** Sloupce zápočtů (vazba na novou platbu se do otisku nebere, její id se mění). */
    private const SETTLEMENT_COLUMNS = ['doc_type', 'doc_id', 'settled_on', 'amount', 'account_id', 'journal_entry_id', 'status'];

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
        return $this->inSavepoint($supplierId, $keep, function () use ($supplierId, $userId): array {
            $row = $this->repair->run([$supplierId], true, $userId)[0];
            $review = [];
            foreach ($row['left_for_matching'] ?? [] as $i) {
                $rate = $i['currency'] === 'CZK' ? 1.0 : ($i['rate'] ?? 1.0);
                $review[] = new CheckItem('invoice', (int) $i['id'], (string) $i['document_no'], $i['paid_at'], round($i['amount'] * $rate, 2), CheckItem::REVIEW,
                    'Úhrada je spárovaná s pohybem převedené banky bez evidované platby. Doplní ji opakovaný převod z Money S3.',
                    (float) $i['amount'], (string) $i['currency'], ['source' => MigrationSources::MONEY_S3]);
            }
            return $review;
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
        return $this->inSavepoint($supplierId, $keep, function () use ($supplierId, $userId): array {
            $lastEnd = $this->payments->lastTransferredPeriodEnd($supplierId);
            if ($lastEnd === null) {
                return [];
            }
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
            }
            return [];
        });
    }

    /**
     * Provede opravu v savepointu a z rozdílu dat sestaví plán. `$run` vrací položky jen ke
     * kontrole (mimo rozdíl dat).
     *
     * @param callable():list<CheckItem> $run
     * @return array{items:list<CheckItem>,kept:bool}
     */
    private function inSavepoint(int $supplierId, bool $keep, callable $run): array
    {
        $pdo = $this->db->pdo();
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Plán opravy Money S3 se počítá jen uvnitř transakce.');
        }
        $pdo->exec('SAVEPOINT migration_check_money_plan');
        try {
            $before = $this->snapshot($supplierId);
            $review = $run();
            $changes = $this->changes($supplierId, $before, $this->snapshot($supplierId));
            $items = MigrationCheckLocks::allOrNothing(new CheckResult(array_merge($changes, $review)))->items;
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

    /**
     * Řádky, na které opravy Money S3 sahají: evidované platby, zápočty a úhrada mimo evidenci
     * přijatých dokladů firmy.
     *
     * @return array{payments:array<int,array<string,mixed>>,settlements:array<int,array<string,mixed>>,outside:array<int,string>}
     */
    private function snapshot(int $supplierId): array
    {
        $pdo = $this->db->pdo();
        $out = ['payments' => [], 'settlements' => [], 'outside' => []];
        $stmt = $pdo->prepare('SELECT id, ' . implode(', ', self::PAYMENT_COLUMNS) . ' FROM invoice_payments WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['payments'][(int) $r['id']] = $r;
        }
        $stmt = $pdo->prepare('SELECT id, ' . implode(', ', self::SETTLEMENT_COLUMNS) . ' FROM invoice_settlements WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['settlements'][(int) $r['id']] = $r;
        }
        $stmt = $pdo->prepare('SELECT id, settled_outside_amount FROM purchase_invoices WHERE supplier_id = ? AND settled_outside_amount <> 0');
        $stmt->execute([$supplierId]);
        $out['outside'] = array_map('strval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        return $out;
    }

    /**
     * Změny mezi dvěma snímky jako nálezy: přidané, změněné a odebrané řádky se starými
     * i novými hodnotami. Nové řádky se porovnávají obsahem (jejich id se mezi náhledem
     * a opravou liší), existující podle id.
     *
     * @param array{payments:array<int,array<string,mixed>>,settlements:array<int,array<string,mixed>>,outside:array<int,string>} $before
     * @param array{payments:array<int,array<string,mixed>>,settlements:array<int,array<string,mixed>>,outside:array<int,string>} $after
     * @return list<CheckItem>
     */
    private function changes(int $supplierId, array $before, array $after): array
    {
        $raw = [];
        foreach (['payments' => self::PAYMENT_COLUMNS, 'settlements' => self::SETTLEMENT_COLUMNS] as $table => $columns) {
            foreach ($after[$table] + $before[$table] as $id => $_) {
                $old = isset($before[$table][$id]) ? array_intersect_key($before[$table][$id], array_flip($columns)) : null;
                $new = isset($after[$table][$id]) ? array_intersect_key($after[$table][$id], array_flip($columns)) : null;
                if ($old === $new) {
                    continue;
                }
                $raw[] = ['table' => $table, 'id' => $old !== null ? $id : null, 'before' => $old, 'after' => $new];
            }
        }
        foreach ($after['outside'] + $before['outside'] as $id => $_) {
            $old = $before['outside'][$id] ?? '0.00';
            $new = $after['outside'][$id] ?? '0.00';
            if ((float) $old !== (float) $new) {
                $raw[] = ['table' => 'outside', 'id' => $id, 'before' => ['settled_outside_amount' => $old], 'after' => ['settled_outside_amount' => $new]];
            }
        }
        if ($raw === []) {
            return [];
        }
        [$invoices, $purchases] = $this->documents($raw);
        $items = [];
        foreach ($raw as $c) {
            $items[] = $this->changeItem($supplierId, $c, $invoices, $purchases);
        }
        usort($items, static fn (CheckItem $a, CheckItem $b): int => [$a->entityType, $a->entityId, json_encode($a->details)] <=> [$b->entityType, $b->entityId, json_encode($b->details)]);
        return $items;
    }

    /**
     * @param array{table:string,id:?int,before:?array<string,mixed>,after:?array<string,mixed>} $c
     * @param array<int,array<string,mixed>> $invoices
     * @param array<int,array<string,mixed>> $purchases
     */
    private function changeItem(int $supplierId, array $c, array $invoices, array $purchases): CheckItem
    {
        $row = $c['after'] ?? $c['before'];
        $op = $c['before'] === null ? 'add' : ($c['after'] === null ? 'remove' : 'change');
        $dates = [];
        if ($c['table'] === 'payments') {
            [$type, $docId] = ['invoice', (int) $row['invoice_id']];
            foreach ([$c['before'], $c['after']] as $r) {
                if ($r !== null) {
                    $dates[] = (string) $r['paid_on'];
                    if ($r['tax_document_invoice_id'] !== null) {
                        $dates[] = $invoices[(int) $r['tax_document_invoice_id']]['date'] ?? null;
                    }
                }
            }
            $amount = (float) ($c['after']['amount'] ?? 0) - (float) ($c['before']['amount'] ?? 0);
            $reason = match (true) {
                $op === 'add' => sprintf('Doplní se platba %s k %s.', (string) $row['note'], (string) $row['paid_on']),
                $op === 'remove' => sprintf('Zruší se platba %s z %s.', (string) $row['note'], (string) $row['paid_on']),
                ($c['before']['tax_document_invoice_id'] ?? null) !== ($c['after']['tax_document_invoice_id'] ?? null)
                    => 'Změní se vazba platby na DDKP z Money.',
                default => sprintf('Upraví se platba %s k %s.', (string) $row['note'], (string) $row['paid_on']),
            };
        } elseif ($c['table'] === 'settlements') {
            [$type, $docId] = [(string) $row['doc_type'], (int) $row['doc_id']];
            foreach ([$c['before'], $c['after']] as $r) {
                if ($r !== null) {
                    $dates[] = (string) $r['settled_on'];
                }
            }
            $amount = (float) ($c['after']['amount'] ?? 0) - (float) ($c['before']['amount'] ?? 0);
            $reason = sprintf('%s zápočet interním dokladem (zápis #%d) k %s.', $op === 'remove' ? 'Zruší se' : 'Doplní se',
                (int) $row['journal_entry_id'], (string) $row['settled_on']);
        } else {
            [$type, $docId] = ['purchase_invoice', (int) $c['id']];
            $dates[] = $purchases[$docId]['date'] ?? null;
            $amount = (float) $c['after']['settled_outside_amount'] - (float) $c['before']['settled_outside_amount'];
            $reason = sprintf('Úhrada mimo evidenci %s Kč → %s.', $c['before']['settled_outside_amount'], $c['after']['settled_outside_amount']);
        }
        $doc = ($type === 'invoice' ? $invoices : $purchases)[$docId] ?? ['label' => '#' . $docId, 'currency' => null, 'rate' => 1.0, 'date' => null];
        $lock = null;
        foreach (array_unique(array_filter($dates)) as $date) {
            $lock ??= $this->locks->paymentRecordReason($supplierId, (string) $date);
        }
        $details = ['source' => MigrationSources::MONEY_S3, 'table' => $c['table'], 'op' => $op, 'row' => $c['id'], 'before' => $c['before'], 'after' => $c['after']];
        return new CheckItem($type, $docId, (string) $doc['label'], (string) (array_values(array_filter($dates))[0] ?? $doc['date'] ?? ''),
            round($amount * (float) $doc['rate'], 2), $lock === null ? CheckItem::FIXABLE : CheckItem::LOCKED, $lock ?? $reason,
            round($amount, 2), $doc['currency'], $details);
    }

    /**
     * Doklady dotčené změnami (číslo, měna, kurz, datum): vydané včetně DDKP z vazeb plateb
     * a přijaté. Jedním dotazem na druh dokladu.
     *
     * @param list<array<string,mixed>> $raw
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function documents(array $raw): array
    {
        $invoiceIds = [];
        $purchaseIds = [];
        foreach ($raw as $c) {
            foreach ([$c['before'], $c['after']] as $r) {
                if ($r === null) {
                    continue;
                }
                if ($c['table'] === 'payments') {
                    $invoiceIds[(int) $r['invoice_id']] = true;
                    if ($r['tax_document_invoice_id'] !== null) {
                        $invoiceIds[(int) $r['tax_document_invoice_id']] = true;
                    }
                } elseif ($c['table'] === 'settlements') {
                    $r['doc_type'] === 'invoice' ? $invoiceIds[(int) $r['doc_id']] = true : $purchaseIds[(int) $r['doc_id']] = true;
                }
            }
            if ($c['table'] === 'outside') {
                $purchaseIds[(int) $c['id']] = true;
            }
        }
        $load = function (string $sql, array $ids): array {
            if ($ids === []) {
                return [];
            }
            $out = [];
            foreach ($this->db->pdo()->query(sprintf($sql, implode(',', array_map('intval', array_keys($ids)))))->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int) $r['id']] = ['label' => (string) $r['label'], 'currency' => (string) $r['currency'],
                    'rate' => (string) $r['currency'] === 'CZK' || (float) $r['rate'] <= 0 ? 1.0 : (float) $r['rate'],
                    'date' => $r['date'] !== null ? substr((string) $r['date'], 0, 10) : null];
            }
            return $out;
        };
        return [
            $load("SELECT i.id, COALESCE(NULLIF(i.varsymbol, ''), CONCAT('#', i.id)) AS label, c.code AS currency, i.exchange_rate AS rate,
                          CASE WHEN i.invoice_type = 'tax_document' THEN COALESCE(i.tax_date, i.issue_date) ELSE i.paid_at END AS date
                     FROM invoices i JOIN currencies c ON c.id = i.currency_id WHERE i.id IN (%s)", $invoiceIds),
            $load("SELECT p.id, COALESCE(NULLIF(p.vendor_invoice_number, ''), CONCAT('#', p.id)) AS label, c.code AS currency, p.exchange_rate AS rate,
                          p.paid_at AS date
                     FROM purchase_invoices p JOIN currencies c ON c.id = p.currency_id WHERE p.id IN (%s)", $purchaseIds),
        ];
    }
}
