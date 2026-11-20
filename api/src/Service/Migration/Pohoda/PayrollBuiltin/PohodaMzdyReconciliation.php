<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda\PayrollBuiltin;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollStatutoryAccumulatorRepository;
use MyInvoice\Service\Migration\Pohoda\PohodaXml;
use MyInvoice\Service\Payroll\Migration\PayrollMigrationReferenceTotals;

/**
 * Kontrola převodu mezd z agendy Mzdy v POHODĚ proti zdroji, po měsících a v úhrnech.
 *
 * Dvě strany:
 *  1. **Mzda sama se sebou** ({@see self::payslip()}): hrubá mzda je součtem sloupců, které
 *     převod rozložil do složek a nepřítomností (a nenulových sloupců, které nepřevedl),
 *     čistá mzda, záloha na daň, náhrada mzdy při nemoci a částka k výplatě sedí na své
 *     sloupce. Nesedí-li, mzda nese částku ve sloupci, který převod nezná, a převzatý
 *     měsíc by ji tiše ztratil ze složek.
 *  2. **Převzato proti zdroji** ({@see self::takeover()}): co převod v MyÚčtu uložil jako
 *     převzaté úhrny (`payroll_migration_reference_totals`) a počáteční stavy daně, proti
 *     úhrnům mezd POHODY za týž měsíc: hrubá mzda, pojistné zaměstnance i zaměstnavatele,
 *     záloha a srážková daň, slevy na dani, bonus, čistá mzda a výplata.
 *
 * Obě strany sčítá po měsících, ne po osobách: protokol převodu nese jen úhrny.
 */
final class PohodaMzdyReconciliation
{
    /** Kód rozdílu: mzda POHODY nesedí sama se sebou. */
    public const SOURCE_MISMATCH = 'pohoda_payroll_source_mismatch';
    /** Kód rozdílu: převzaté úhrny měsíce nesedí na mzdy POHODY. */
    public const TAKEOVER_MISMATCH = 'pohoda_payroll_takeover_mismatch';
    /** Zdroj převzatých mezd, pod kterým je ukládá převod PAMICA. */
    private const SOURCE = 'pamica';

    /** Úhrny měsíce v haléřích; pořadí je pořadí sloupců kontroly v protokolu. */
    public const METRICS = [
        'gross' => 'hrubá mzda',
        'employee_social' => 'sociální pojištění zaměstnance',
        'employer_social' => 'sociální pojištění zaměstnavatele',
        'employee_health' => 'zdravotní pojištění zaměstnance',
        'employer_health' => 'zdravotní pojištění zaměstnavatele',
        'advance_tax' => 'záloha na daň',
        'withholding_tax' => 'srážková daň',
        'tax_reliefs' => 'slevy na dani',
        'tax_bonus' => 'daňový bonus',
        'net' => 'čistá mzda',
        'payout' => 'k výplatě',
    ];

    /** Sloupce převzatých úhrnů v MyÚčtu podle metriky (slevy nesou počáteční stavy daně). */
    private const STORED = [
        'gross' => 'gross_minor',
        'employee_social' => 'employee_social_minor',
        'employer_social' => 'employer_social_minor',
        'employee_health' => 'employee_health_minor',
        'employer_health' => 'employer_health_minor',
        'advance_tax' => 'advance_tax_minor',
        'withholding_tax' => 'withholding_tax_minor',
        'tax_bonus' => 'tax_bonus_minor',
        'net' => 'net_minor',
        'payout' => 'net_payable_minor',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollStatutoryAccumulatorRepository $accumulators,
    ) {}

    /**
     * Úhrny jedné mzdy a kontroly mzdy samé se sebou.
     *
     * @param array<string,mixed> $mz řádek `MZ` tak, jak ho nese export POHODY
     * @param array{gross:array{source:float,converted:float,unconverted:float}} $map rozklad mzdy ({@see PohodaMzdyPayslip::map()})
     * @param float $compensation součet náhrad mzdy při nemoci této mzdy v `MZnahr`
     * @return array{metrics:array<string,int>,failures:list<string>}
     */
    public static function payslip(array $mz, array $map, float $compensation): array
    {
        $withholding = trim(PohodaXml::text($mz, 'ZvlSazD')) === '1';
        $metrics = [
            'gross' => self::minor(PohodaXml::num($mz, 'KcHrubaM')),
            'employee_social' => self::minor(PohodaXml::num($mz, 'KcSoc')),
            'employer_social' => self::minor(PohodaXml::num($mz, 'KcSocL')),
            'employee_health' => self::minor(PohodaXml::num($mz, 'KcZdr')),
            'employer_health' => self::minor(PohodaXml::num($mz, 'KcZdrL')),
            'advance_tax' => $withholding ? 0 : self::minor(PohodaXml::num($mz, 'KcZalDan')),
            'withholding_tax' => $withholding ? self::minor(PohodaXml::num($mz, 'KcZalDan')) : 0,
            // Sleva na poplatníka a další slevy (`KcDanPrS` - `KcDanZal`) a sleva na dítě
            // uplatněná na záloze (`KcDanZal` - `KcZalDan`); srážková daň slevy nemá.
            'tax_reliefs' => $withholding ? 0 : max(0, self::minor(PohodaXml::num($mz, 'KcDanPrS')) - self::minor(PohodaXml::num($mz, 'KcZalDan'))),
            'tax_bonus' => self::minor(PohodaXml::num($mz, 'KcDanBon')),
            'net' => self::minor(PayrollMigrationReferenceTotals::pohodaNet($mz)),
            'payout' => self::minor(PohodaXml::num($mz, 'KcVyuct')),
        ];
        $failures = [];
        $parts = self::minor($map['gross']['converted']) + self::minor($map['gross']['unconverted']);
        if ($parts !== $metrics['gross']) {
            $failures[] = sprintf('hrubá mzda %s Kč, převedené a známé sloupce %s Kč', self::money($metrics['gross']), self::money($parts));
        }
        // Přeplatek daně z ročního zúčtování (`KcPreplDanRZ`) POHODA vyplácí v čisté mzdě,
        // doplatek daňového bonusu z ročního zúčtování (`KcDoplDanBonRZ`) jen ve výplatě.
        $net = $metrics['gross'] - $metrics['employee_social'] - $metrics['employee_health'] - self::minor(PohodaXml::num($mz, 'KcZalDan'))
            + self::minor(PohodaXml::num($mz, 'KcPreplDanRZ'));
        if ($net !== self::minor(PohodaXml::num($mz, 'KcCistaM'))) {
            $failures[] = sprintf('čistá mzda %s Kč, hrubá mzda po pojistném a dani %s Kč', self::money(self::minor(PohodaXml::num($mz, 'KcCistaM'))), self::money($net));
        }
        if (!$withholding) {
            $afterTaxpayer = max(0, self::minor(PohodaXml::num($mz, 'KcDanPrS')) - self::minor(PohodaXml::num($mz, 'KcNzdZak')) - self::minor(PohodaXml::num($mz, 'KcNzdInv')) - self::minor(PohodaXml::num($mz, 'KcNzdOst')));
            $afterChild = max(0, self::minor(PohodaXml::num($mz, 'KcDanZal')) - self::minor(PohodaXml::num($mz, 'KcNzdDet')));
            if ($afterTaxpayer !== self::minor(PohodaXml::num($mz, 'KcDanZal')) || $afterChild !== self::minor(PohodaXml::num($mz, 'KcZalDan'))) {
                $failures[] = sprintf('záloha na daň %s Kč, záloha před slevami %s Kč po slevách %s Kč',
                    self::money(self::minor(PohodaXml::num($mz, 'KcZalDan'))), self::money(self::minor(PohodaXml::num($mz, 'KcDanPrS'))), self::money(max(0, $afterTaxpayer - self::minor(PohodaXml::num($mz, 'KcNzdDet')))));
            }
        }
        if (self::minor($compensation) !== self::minor(PohodaXml::num($mz, 'KcNahrNem'))) {
            $failures[] = sprintf('náhrada mzdy při nemoci %s Kč, rozpis náhrad %s Kč', self::money(self::minor(PohodaXml::num($mz, 'KcNahrNem'))), self::money(self::minor($compensation)));
        }
        $payout = self::minor(PohodaXml::num($mz, 'KcCistaM')) + self::minor(PohodaXml::num($mz, 'KcNahrNem')) + self::minor(PohodaXml::num($mz, 'KcDanBon')) + self::minor(PohodaXml::num($mz, 'KcStrav'))
            + self::minor(PohodaXml::num($mz, 'KcDoplDanBonRZ')) - self::minor(PohodaXml::num($mz, 'KcSrazky')) - self::minor(PohodaXml::num($mz, 'KcZaloha'));
        if ($payout !== $metrics['payout']) {
            $failures[] = sprintf('k výplatě %s Kč, čistá mzda s náhradou, bonusem a stravným po srážkách %s Kč', self::money($metrics['payout']), self::money($payout));
        }

        return ['metrics' => $metrics, 'failures' => $failures];
    }

    /**
     * Převzaté úhrny v MyÚčtu proti úhrnům mezd POHODY, po měsících.
     *
     * @param array<string,array{payslips:int,metrics:array<string,int>,relations:list<string>}> $months
     *        měsíc `Y-m` => úhrny ze zdroje ({@see PohodaMzdyAdapter::prepare()}); jen měsíce, které převod převzal
     * @return list<array{period:string,payslips:int,stored:int,metrics:array<string,array{source:int,takeover:?int}>,ok:bool}>
     */
    public function takeover(int $supplierId, array $months): array
    {
        if ($months === []) {
            return [];
        }
        ksort($months);
        $stored = $this->storedTotals($supplierId, $months);
        $reliefs = $this->storedReliefs($supplierId, $months);
        $out = [];
        foreach ($months as $period => $month) {
            $row = ['period' => (string) $period, 'payslips' => $month['payslips'], 'stored' => (int) ($stored[$period]['rows'] ?? 0), 'metrics' => [], 'ok' => true];
            foreach (array_keys(self::METRICS) as $metric) {
                $have = $metric === 'tax_reliefs'
                    ? ($reliefs[$period] ?? null)
                    : (isset($stored[$period]) ? (int) $stored[$period][$metric] : 0);
                $row['metrics'][$metric] = ['source' => $month['metrics'][$metric] ?? 0, 'takeover' => $have];
                // Slevy nesou jen počáteční stavy daně, a ty převod zapisuje za měsíce před
                // začátkem vedení mezd v MyÚčtu; měsíc, který počítá MyÚčto, je nemá.
                if ($have !== null && $have !== ($month['metrics'][$metric] ?? 0)) {
                    $row['ok'] = false;
                }
            }
            if ($row['stored'] !== $month['payslips']) {
                $row['ok'] = false;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Porušení pro bránu převodu ({@see \MyInvoice\Service\Payroll\Migration\PayrollTakeoverInvariants::report()}).
     *
     * @param array<string,list<string>> $sourceFailures měsíc => popisy nesouladu mzdy samé se sebou
     * @param list<array{period:string,payslips:int,stored:int,metrics:array<string,array{source:int,takeover:?int}>,ok:bool}> $takeover
     * @return list<array{code:string,text:string,context:array<string,mixed>}>
     */
    public static function violations(array $sourceFailures, array $takeover): array
    {
        $out = [];
        ksort($sourceFailures);
        foreach ($sourceFailures as $period => $failures) {
            $count = count($failures);
            $out[] = ['code' => self::SOURCE_MISMATCH, 'text' => sprintf(
                'Mzdy POHODY za %s nesedí samy se sebou (%d %s): %s. Mzda nese částku ve sloupci, který převod nezná, '
                . 'nebo byl export upraven; převzaté úhrny měsíce jsou z POHODY, ale složky a nepřítomnosti měsíce nemusí být úplné.',
                $period, $count, $count === 1 ? 'nesoulad' : ($count < 5 ? 'nesoulady' : 'nesouladů'),
                implode('; ', array_slice(array_unique($failures), 0, 5)),
            ), 'context' => ['period' => (string) $period, 'failures' => $count]];
        }
        foreach ($takeover as $month) {
            if ($month['ok']) {
                continue;
            }
            $parts = [];
            if ($month['stored'] !== $month['payslips']) {
                $parts[] = sprintf('mezd %d, převzatých úhrnů %d', $month['payslips'], $month['stored']);
            }
            foreach ($month['metrics'] as $metric => $values) {
                if ($values['takeover'] !== null && $values['takeover'] !== $values['source']) {
                    $parts[] = sprintf('%s POHODA %s Kč, MyÚčto %s Kč', self::METRICS[$metric], self::money($values['source']), self::money($values['takeover']));
                }
            }
            $out[] = ['code' => self::TAKEOVER_MISMATCH, 'text' => sprintf('Převzaté mzdy za %s nesedí na mzdy POHODY: %s.', $month['period'], implode(', ', $parts)),
                'context' => ['period' => $month['period']]];
        }

        return $out;
    }

    /** Řádek protokolu s úhrny měsíce: POHODA proti MyÚčtu. */
    public static function line(array $month): string
    {
        $parts = [];
        foreach ($month['metrics'] as $metric => $values) {
            if ($values['source'] === 0 && ($values['takeover'] ?? 0) === 0) {
                continue;
            }
            $parts[] = self::METRICS[$metric] . ' ' . self::money($values['source'])
                . ($values['takeover'] === null ? ' (MyÚčto počítá samo)' : ($values['takeover'] === $values['source'] ? '' : ' / MyÚčto ' . self::money($values['takeover'])));
        }

        return sprintf('%s: %d mezd, %s Kč - %s.', $month['period'], $month['payslips'], implode(', ', $parts), $month['ok'] ? 'sedí' : 'ROZDÍL');
    }

    /**
     * @param array<string,array{relations:list<string>}> $months
     * @return array<string,array<string,int>> měsíc => metrika => haléře, plus `rows`
     */
    private function storedTotals(int $supplierId, array $months): array
    {
        $periods = array_keys($months);
        $relations = array_values(array_unique(array_merge(...array_map(static fn (array $m): array => $m['relations'], array_values($months)))));
        if ($relations === []) {
            return [];
        }
        $sums = implode(', ', array_map(static fn (string $metric, string $column): string => "SUM({$column}) AS {$metric}", array_keys(self::STORED), self::STORED));
        $out = [];
        foreach (array_chunk($relations, 500) as $chunk) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT period_start, COUNT(*) AS `rows`, {$sums}
                   FROM payroll_migration_reference_totals
                  WHERE supplier_id = ? AND source = ? AND period_start >= ? AND period_start < ?
                    AND external_relationship_ref IN (" . implode(', ', array_fill(0, count($chunk), '?')) . ')
                  GROUP BY period_start'
            );
            $last = (new \DateTimeImmutable(max($periods) . '-01'))->modify('+1 month')->format('Y-m-d');
            $stmt->execute([$supplierId, self::SOURCE, min($periods) . '-01', $last, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $period = substr((string) $row['period_start'], 0, 7);
                if (!isset($months[$period])) {
                    continue;
                }
                foreach ($row as $key => $value) {
                    if ($key !== 'period_start') {
                        $out[$period][$key] = ($out[$period][$key] ?? 0) + (int) $value;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Uplatněné slevy na dani (sleva na poplatníka a další slevy a sleva na dítě) z počátečních
     * stavů daně osob převodu, po měsících. Měsíc bez počátečního stavu chybí.
     *
     * @param array<string,array{relations:list<string>}> $months
     * @return array<string,int>
     */
    private function storedReliefs(int $supplierId, array $months): array
    {
        $employees = [];
        foreach (array_chunk(array_values(array_unique(array_merge(...array_map(static fn (array $m): array => $m['relations'], array_values($months))))), 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $stmt = $this->db->pdo()->prepare(
                'SELECT DISTINCT employee_id FROM payroll_migration_reference_totals
                  WHERE supplier_id = ? AND source = ? AND employee_id IS NOT NULL
                    AND external_relationship_ref IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute([$supplierId, self::SOURCE, ...$chunk]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $employees[(int) $id] = true;
            }
        }
        $out = [];
        $years = array_unique(array_map(static fn (string $period): int => (int) substr($period, 0, 4), array_map('strval', array_keys($months))));
        foreach ($years as $year) {
            foreach ($this->accumulators->currentOpeningsForSupplier($supplierId, $year, 'income_tax') as $employeeId => $opening) {
                if (!isset($employees[$employeeId])) {
                    continue;
                }
                foreach ((array) ($opening['evidence']['months'] ?? []) as $row) {
                    $period = sprintf('%04d-%02d', $year, (int) ($row['month'] ?? 0));
                    if (!isset($months[$period])) {
                        continue;
                    }
                    $out[$period] = ($out[$period] ?? 0) + (int) ($row['applied_non_refundable_credits_minor_units'] ?? 0)
                        + (int) ($row['applied_child_credit_minor_units'] ?? 0);
                }
            }
        }

        return $out;
    }

    private static function minor(float $value): int
    {
        return (int) round($value * 100);
    }

    private static function money(int $minor): string
    {
        return number_format($minor / 100, 2, ',', ' ');
    }
}
