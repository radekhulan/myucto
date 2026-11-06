<?php

declare(strict_types=1);

namespace MyInvoice\Repository\Property\Receivables;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Property\Charges\Issuance\IssuanceReadRepository;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Service\Property\Charges\Issuance\IssuanceJournalProof;
use MyInvoice\Service\Property\Payments\PaymentLocks;
use PDO;

/**
 * Doplnění dluhů k předpisům zaúčtovaným před zavedením hromadné registrace.
 *
 * Kandidátem je zaúčtovaný kladný předpis, jehož některý kladný řádek nemá dluh. Registraci provede
 * ChargeDebtRegistrar se stejnými výchozími údaji jako při zaúčtování dávky, takže předpis s částečně
 * ručně registrovanými dluhy zůstane výjimkou a jen se vykáže. Autorem dluhu je uživatel, který dávku
 * předpisů vystavil. Každý blok běží ve vlastní transakci pod zámky plateb; opakování je idempotentní.
 */
final class PostedChargeDebtBackfill
{
    public const CHUNK = 500;

    public function __construct(private readonly Connection $db) {}

    /** @return list<int> firmy se zaúčtovanými předpisy */
    public function suppliers(): array
    {
        $rows = $this->db->pdo()->query('SELECT s.id FROM supplier s WHERE EXISTS (SELECT 1 FROM unit_charges c WHERE c.supplier_id=s.id) ORDER BY s.id')->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    }

    /**
     * @return array{candidates:int,registered:int,partial:int,debts:array<int,int>}
     *   candidates = předpisy s kladným řádkem bez dluhu, registered = nově registrované (v dry-run
     *   registrovatelné) předpisy, partial = předpisy s částí řádků už v ručně registrovaném dluhu
     */
    public function run(int $supplier, bool $dryRun, int $chunk = self::CHUNK): array
    {
        if ($chunk < 1) throw new \InvalidArgumentException('chunk');
        $out = ['candidates' => 0, 'registered' => 0, 'partial' => 0, 'debts' => []];
        $after = 0;
        while (true) {
            $page = $this->postedPage($supplier, $after, $chunk);
            if ($page === []) break;
            $after = (int) array_key_last($page);
            [$unbound, $partial] = $this->lineState($supplier, array_keys($page));
            $candidates = array_intersect_key($page, $unbound);
            if ($candidates === []) continue;
            $out['candidates'] += count($candidates);
            $out['partial'] += count($partial);
            if ($dryRun) {
                $out['registered'] += count($candidates) - count($partial);
                continue;
            }
            $created = (new DimensionPeriodGuard($this->db))->transaction(fn(): array => $this->register($supplier, $candidates));
            $out['registered'] += count($created);
            $out['debts'] += $created;
        }
        return $out;
    }

    /** @param array<int,int> $candidates charge_id => autor dávky */
    private function register(int $supplier, array $candidates): array
    {
        (new PaymentLocks($this->db))->acquire($supplier);
        $charges = (new IssuanceReadRepository($this->db))->findMany($supplier, array_keys($candidates), true);
        $proofs = [];
        $byActor = [];
        foreach ($charges as $charge) {
            // Mezi výběrem a zámkem mohl být předpis stornován, takový se přeskočí.
            if ($charge['status'] !== 'posted') continue;
            $proofs[] = ['entry_id' => $charge['journal_entry_id'], 'charge' => $charge, 'reversal' => false];
            $byActor[$candidates[$charge['id']]][] = $charge;
        }
        if ($proofs === []) return [];
        (new IssuanceJournalProof($this->db))->assertEntries($supplier, $proofs);
        ksort($byActor, SORT_NUMERIC);
        $created = [];
        $registrar = new ChargeDebtRegistrar($this->db);
        foreach ($byActor as $actor => $group) {
            $created += $registrar->registerPosted($supplier, $group, $actor);
        }
        return $created;
    }

    /**
     * Stránka zaúčtovaných kladných předpisů po primárním klíči firmy, aby průchod zůstal lineární
     * i nad miliony předpisů.
     *
     * @return array<int,int> charge_id => autor dávky, seřazeno podle id
     */
    private function postedPage(int $supplier, int $after, int $limit): array
    {
        $statement = $this->db->pdo()->prepare(
            "SELECT id,batch_id FROM unit_charges WHERE supplier_id=? AND id>? AND status='posted' AND amount_cents>0 ORDER BY supplier_id,id LIMIT ".$limit
        );
        $statement->execute([$supplier, $after]);
        $charges = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($charges === []) return [];
        $batches = array_values(array_unique(array_map('intval', array_column($charges, 'batch_id'))));
        $statement = $this->db->pdo()->prepare(
            'SELECT id,created_by FROM unit_charge_batches WHERE supplier_id=? AND id IN ('.implode(',', array_fill(0, count($batches), '?')).')'
        );
        $statement->execute([$supplier, ...$batches]);
        $actors = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $actors[(int) $row['id']] = (int) $row['created_by'];
        $out = [];
        foreach ($charges as $row) $out[(int) $row['id']] = $actors[(int) $row['batch_id']];
        return $out;
    }

    /**
     * @return array{0:array<int,true>,1:list<int>} předpisy s kladným řádkem bez dluhu
     *   a z nich ty, jejichž jiný řádek už dluh má
     */
    private function lineState(int $supplier, array $chargeIds): array
    {
        $marks = implode(',', array_fill(0, count($chargeIds), '?'));
        $statement = $this->db->pdo()->prepare(
            'SELECT l.charge_id,SUM(dl.debt_id IS NULL) unbound,SUM(dl.debt_id IS NOT NULL) bound FROM unit_charge_lines l'
            .' LEFT JOIN unit_payment_debt_lines dl ON dl.supplier_id=l.supplier_id AND dl.charge_line_id=l.id'
            .' WHERE l.supplier_id=? AND l.charge_id IN ('.$marks.') AND l.amount_cents>0 GROUP BY l.charge_id ORDER BY l.charge_id'
        );
        $statement->execute([$supplier, ...$chargeIds]);
        $unbound = [];
        $partial = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['unbound'] === 0) continue;
            $unbound[(int) $row['charge_id']] = true;
            if ((int) $row['bound'] > 0) $partial[] = (int) $row['charge_id'];
        }
        return [$unbound, $partial];
    }
}
