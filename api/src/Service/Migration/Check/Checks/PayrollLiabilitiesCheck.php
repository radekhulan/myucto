<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Shared\MigratedOtherItemPolicy;
use PDO;

/**
 * Kontrola 4: mzdové závazky na účtech 331, 336 a 342 chybějící v ostatních závazcích.
 *
 * Převod z POHODY a z ABRA Flexi dřív bral závazky na mzdových účtech jako předpisy
 * mzdového modulu a nechal je jen v deníku ({@see MigratedOtherItemPolicy::payroll()}).
 * Převedená firma mzdy v MyÚčtu nepočítá, takže v agendě Ostatní pohledávky a závazky
 * chyběly a nešly párovat s úhradami.
 *
 * Kontrola porovná ke konci posledního převedeného roku zůstatek každého mzdového účtu
 * v hlavní knize s neuhrazenými ostatními závazky (a pohledávkami) na tomto účtu k témuž
 * dni. K pozdějšímu dni se neporovnává: mzdy počítané v MyÚčtu ostatní položky nezakládají.
 * Oprava bez zdroje převodu není jednoznačná (nejde poznat, které předpisy chybí), proto
 * kontrola jen hlásí rozdíl s odkazem na postup v manuálu.
 */
final class PayrollLiabilitiesCheck extends SourceScopedCheck
{
    public const KEY = 'payroll_liabilities';

    /** Rozdíl do koruny je zaokrouhlení. */
    private const TOLERANCE = 1.0;

    /** Zdroje, jejichž převod mzdové závazky nesl jen v deníku. */
    private const SOURCES = [MigrationSources::POHODA, MigrationSources::ABRA];

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly LedgerReportRepository $ledger,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Mzdové závazky 331, 336 a 342 v ostatních závazcích';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1266-mzdove-zavazky-v-ostatnich-zavazcich';
    }

    protected function sources(): array
    {
        return self::SOURCES;
    }

    public function detect(int $supplierId): CheckResult
    {
        $asOf = $this->lastMigratedDay($supplierId);
        if ($asOf === null) {
            return new CheckResult([], ['Konec posledního převedeného roku nejde určit, firma nemá založené účetní období převedeného roku.']);
        }
        $codes = MigratedOtherItemPolicy::PAYROLL_ACCOUNTS;
        $ledger = [];
        $names = [];
        foreach ($this->ledger->syntheticBalances($supplierId, $asOf, null, onlyCodes: $codes) as $b) {
            $ledger[(string) $b['code']] = round((float) $b['d'] - (float) $b['md'], 2);
            $names[(string) $b['code']] = (string) $b['name'];
        }
        $items = $this->openItems($supplierId, $asOf);
        $out = [];
        foreach ($codes as $code) {
            $balance = $ledger[$code] ?? 0.0;
            $open = $items[$code] ?? 0.0;
            $diff = round($balance - $open, 2);
            if (abs($diff) < self::TOLERANCE) {
                continue;
            }
            $out[] = new CheckItem('account', null, trim($code . ' ' . ($names[$code] ?? '')), $asOf, $diff, CheckItem::REVIEW,
                sprintf('Zůstatek účtu v hlavní knize %s, neuhrazené ostatní závazky na účtu %s, rozdíl %s. Chybějící mzdové závazky založte podle postupu v manuálu.',
                    self::money($balance), self::money($open), self::money($diff)),
                details: ['account' => $code, 'as_of' => $asOf, 'ledger_balance' => $balance, 'open_items' => $open]);
        }
        return new CheckResult($out);
    }

    /** Poslední den posledního převedeného roku (konec účetního období roku agendy), nejvýš dnešek. */
    private function lastMigratedDay(int $supplierId): ?string
    {
        $ends = array_filter(array_map(fn (string $source): ?string => $this->sources->agendaEnd($supplierId, $source), self::SOURCES));
        return $ends === [] ? null : min(max($ends), date('Y-m-d'));
    }

    /**
     * Neuhrazené potvrzené ostatní závazky minus pohledávky na mzdových účtech k datu, v Kč.
     * Úhrada se počítá k datu platby (storno po datu ji nezruší), úhrada mimo evidenci
     * převodu (`settled_outside_amount`) vždy.
     *
     * @return array<string,float> syntetický účet => částka
     */
    private function openItems(int $supplierId, string $asOf): array
    {
        $codes = MigratedOtherItemPolicy::PAYROLL_ACCOUNTS;
        $like = implode(' OR ', array_fill(0, count($codes), 'oi.account_code LIKE ?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT LEFT(oi.account_code, 3) AS code,
                    SUM(CASE WHEN oi.side = 'payable' THEN 1 ELSE -1 END
                        * GREATEST(oi.amount - oi.settled_outside_amount - COALESCE((
                              SELECT SUM(a.amount) FROM other_item_allocations a
                               WHERE a.supplier_id = oi.supplier_id AND a.other_item_id = oi.id
                                 AND a.payment_on <= ? AND (a.reversed_on IS NULL OR a.reversed_on > ?)), 0), 0)
                        * CASE WHEN oi.amount <> 0 AND oi.amount_czk IS NOT NULL THEN oi.amount_czk / oi.amount ELSE 1 END) AS open_czk
               FROM other_items oi
              WHERE oi.supplier_id = ? AND oi.side IN ('payable', 'receivable') AND oi.status IN ('confirmed', 'posted')
                AND oi.deleted_at IS NULL AND (oi.accounting_on <= ? OR (oi.accounting_on IS NULL AND oi.issued_on <= ?)) AND ({$like})
              GROUP BY LEFT(oi.account_code, 3)"
        );
        $stmt->execute([$asOf, $asOf, $supplierId, $asOf, $asOf, ...array_map(static fn (string $c): string => $c . '%', $codes)]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['code']] = round((float) $r['open_czk'], 2);
        }
        return $out;
    }
}
