<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use PDO;

/**
 * Saldo POHODY nad převedeným deníkem: řádky saldokontního účtu se stejným párovacím symbolem
 * (`journal_line_pairing`) tvoří jednu skupinu a skupina se součtem nula je
 * vyrovnaná. Řádky bez symbolu jsou souhrnné zápisy, typicky počáteční stav.
 */
final class PohodaSaldoPairing
{
    /** Kolik největších skupin vypíše protokol. */
    private const TOP = 5;

    public function __construct(private readonly Connection $db) {}

    /**
     * Zůstatek účtu po skupinách, na normální straně účtu nezáleží: MD je kladně.
     *
     * @return array<string,int> symbol => haléře, '' = řádky bez symbolu
     */
    public function groups(int $supplierId, string $synthetic, string $asOf): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COALESCE(p.pairing_symbol, '') AS symbol,
                    SUM(CASE WHEN l.side = 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS balance
               FROM journal_entry_lines l
               JOIN journal_entries e ON e.id = l.entry_id AND e.supplier_id = l.supplier_id
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
               LEFT JOIN journal_line_pairing p ON p.supplier_id = l.supplier_id AND p.entry_id = l.entry_id AND p.line_no = l.line_no
              WHERE l.supplier_id = ? AND a.account_code LIKE ? AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL AND e.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM journal_entries o WHERE o.supplier_id = e.supplier_id AND o.reversed_by = e.id)
              GROUP BY COALESCE(p.pairing_symbol, '')"
        );
        $stmt->execute([$supplierId, $synthetic . '%', $asOf]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['symbol']] = (int) round((float) $row['balance'] * 100);
        }
        return $out;
    }

    /**
     * Rozpad zůstatku účtu pro protokol; `null`, když deník symboly nenese (převod z XML).
     *
     * @return array{closed:int,open:int,without_symbol:float,top:list<array{symbol:string,balance:float}>}|null
     */
    public function breakdown(int $supplierId, string $synthetic, string $asOf): ?array
    {
        $groups = $this->groups($supplierId, $synthetic, $asOf);
        $without = $groups[''] ?? 0;
        unset($groups['']);
        if ($groups === []) {
            return null;
        }
        $open = array_filter($groups, static fn (int $cents): bool => !ReconciliationTolerance::isRounding($cents / 100));
        uasort($open, static fn (int $a, int $b): int => abs($b) <=> abs($a));
        $top = [];
        foreach (array_slice($open, 0, self::TOP, true) as $symbol => $cents) {
            $top[] = ['symbol' => (string) $symbol, 'balance' => $cents / 100];
        }
        return ['closed' => count($groups) - count($open), 'open' => count($open), 'without_symbol' => $without / 100, 'top' => $top];
    }

    /** @param array{closed:int,open:int,without_symbol:float,top:list<array{symbol:string,balance:float}>}|null $breakdown */
    public static function describe(?array $breakdown): string
    {
        if ($breakdown === null) {
            return '';
        }
        $money = static fn (float $v): string => number_format($v, 2, ',', ' ');
        $top = implode(', ', array_map(static fn (array $g): string => $g['symbol'] . ' ' . $money($g['balance']), $breakdown['top']));
        return sprintf(
            ' Deník podle párovacích symbolů POHODY: vyrovnaných skupin %d, otevřených %d%s, řádky bez symbolu %s Kč.',
            $breakdown['closed'], $breakdown['open'], $top === '' ? '' : ' (největší ' . $top . ')', $money($breakdown['without_symbol']),
        );
    }
}
