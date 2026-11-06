<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\JournalEntryRepository;
use MyInvoice\Repository\Statement504DataRepository;
use MyInvoice\Service\Accounting\Closing\{ClosingException, DeferredTax504Repository};

final class DeferredTax504Statement
{
    public const DISCLOSURE = 'Odložená daň je vykázána podle výslovně zvolené vlastní metody pro věrný a poctivý obraz (§ 7 odst. 2 a § 36 zákona o účetnictví). Podpoložky oddělují splatnou a odloženou daň; pohledávka a závazek se vzájemně nezapočítávají. Účty 349 a 592 a jejich členění ve výkazu nejsou přímým zákonným předpisem účtování.';

    public static function methodNote(array $method): string
    {
        return self::DISCLOSURE . "\nRozhodnutí: " . $method['reference'] . "\nVěrný obraz: " . $method['faithful_image_reason']
            . "\nDůvod vlastní metody: " . $method['standard_departure_reason'] . "\nDůvod odděleného vykázání: " . $method['presentation_reason']
            . (!empty($method['first_adoption']) ? "\nPrvní zavedení minulých rozdílů proti účtu 932: " . $method['first_adoption_reason'] : '');
    }

    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal, private readonly Statement504DataRepository $data) {}

    public function note(int $supplierId, array $period): ?array
    {
        return $this->read(function () use ($supplierId, $period): ?array {
            $context = $this->context($supplierId, $period['ends_on']);
            if ($context === null) return null;
            $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', ' ') . ' Kč';
            $profit = $this->data->profitLines($supplierId, $period['starts_on'], $period['ends_on']);
            $balances = $this->data->balances($supplierId, $period['starts_on'], $period['ends_on']);
            $content = self::methodNote($context['method']);
            foreach (['349' => 'Odložená daňová pohledávka', '959.100' => 'Odložený daňový závazek'] as $code => $label) {
                $start = $end = 0;
                foreach ($balances as $balance) if ($balance['code'] === $code) {
                    $start += $balance['opening_cents'] * ($code === '349' ? 1 : -1);
                    $end += $balance['closing_cents'] * ($code === '349' ? 1 : -1);
                }
                $content .= "\n" . $label . ': počátek ' . $money($start) . ', konec ' . $money($end) . '.';
            }
            foreach (['main' => 'Hlavní činnost', 'economic' => 'Hospodářská činnost'] as $activity => $label) {
                $current = $deferred = 0;
                foreach ($profit as $line) if ($line['activity'] === $activity) {
                    if ($line['code'] === '592') $deferred += $line['balance_cents'];
                    elseif (in_array(substr($line['code'], 0, 3), ['591', '595'], true)) $current += $line['balance_cents'];
                }
                $content .= "\n" . $label . ': splatná daň ' . $money($current) . ', odložená daň ve výsledku ' . $money($deferred) . '.';
            }
            $last = ['assessment' => $context['effective_assessment']];
            $content .= "\nNeuznaná odložená daňová pohledávka podle posledního doloženého posouzení: " . $money($last['assessment']['closing']['unrecognized_asset_cents']) . '.';
            foreach ($last['assessment']['closing']['titles'] as $title) {
                $content .= "\nTitul " . $title['reference'] . ': rozdíl ' . $money($title['difference_cents']) . ', uznaná část rozdílu ' . $money($title['recognized_difference_cents'])
                    . ', sazba ' . number_format($title['rate_basis_points'] / 100, 2, ',', ' ') . ' %, uznaná daň ' . $money($title['recognized_tax_cents'])
                    . ', neuznaná daň ' . $money($title['unrecognized_tax_cents']) . '. Podklad: ' . $title['evidence'] . '. Sazba: ' . $title['rate_basis'] . '.'
                    . ($title['recoverability_evidence'] !== null ? ' Využitelnost pohledávky: ' . $title['recoverability_evidence'] . '.' : '');
            }
            return ['key' => 'n504_deferred_tax_method', 'label' => 'Vlastní metoda odložené daně a oddělený rozpis',
                'legal' => '§ 7 odst. 2 a § 36 ZoÚ; § 29 a § 30 vyhlášky 504/2002 Sb.; ČÚS 412 a 413',
                'hint' => null, 'scope' => 'all', 'auto' => true, 'readonly' => true, 'content' => $content, 'filled' => true, 'carried_over_from_year' => null];
        });
    }

    public function read(callable $operation): mixed
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->exec('START TRANSACTION READ ONLY');
        try { return $operation(); }
        finally { if ($own && $pdo->inTransaction()) $pdo->rollBack(); }
    }

    public function context(int $supplierId, string $asOf): ?array
    {
        try {
            $repository = new DeferredTax504Repository($this->db, $this->journal, false);
            if (!$repository->hasApplicableDecision($supplierId, $asOf)) return null;
            $state = $repository->state($supplierId, $asOf);
            if (!$state['assessment_current']) throw DeferredTax504Repository::changed();
        } catch (ClosingException $e) {
            throw new ReportException($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        return $state['method'] === null ? null : $state;
    }

    public static function maps(array $maps, ?array $context): array
    {
        if ($context === null) return $maps;
        foreach (['balance_sheet' => ['349', 'UR.60'], 'income_statement' => ['592', 'UT.38']] as $type => [$code, $row]) {
            $prefixes = [$code => true];
            $hasExact = false;
            $compatible = true;
            foreach ($maps[$type] as $map) {
                $prefix = (string) $map['account_prefix'];
                if (!str_starts_with($prefix, $code)) continue;
                $prefixes[$prefix] = true;
                $hasExact = $hasExact || $prefix === $code;
                $compatible = $compatible && $map['row_code'] === $row && $map['target'] === 'gross'
                    && (int) $map['sign'] === 1 && in_array($map['balance_condition'], ['any', 'debit', 'credit'], true);
            }
            if (!$hasExact) $maps[$type][] = ['account_prefix' => $code, 'row_code' => $row, 'target' => 'gross', 'balance_condition' => 'any', 'sign' => 1];
            $mapper = new StatementMapper();
            foreach (array_keys($prefixes) as $prefix) {
                foreach ([1, -1] as $balance) {
                    if (count($mapper->entriesFor($maps[$type], (string) $prefix, $balance)) !== 1) $compatible = false;
                }
            }
            if (!$compatible) throw new ReportException('statement_mapping_invalid', 'Mapování odporuje potvrzenému oddělenému vykázání vlastní metody odložené daně.');
        }
        return $maps;
    }

    public static function decorate(array $report, array $profit, array $balances, ?array $context): array
    {
        if ($context === null) return $report;
        $report['deferred_tax_method'] = $context['method'] + ['disclosure' => self::DISCLOSURE];
        if (isset($report['rows'])) {
            $tax = ['main' => 0, 'economic' => 0, 'total' => 0];
            foreach ($profit as $line) if ($line['code'] === '592') $tax[$line['activity']] += $line['balance_cents'];
            $tax['total'] = $tax['main'] + $tax['economic'];
            self::components($report['rows'], ['UT.38', 'UT.37'], $tax, 'current_tax', 'Splatná daň z příjmů', 'deferred_tax', 'Odložená daň podle vlastní metody');
        }
        if (isset($report['assets'])) {
            $asset = $liability = ['start' => 0, 'end' => 0];
            foreach ($balances as $balance) {
                if ($balance['code'] === '349') { $asset['start'] += $balance['opening_cents']; $asset['end'] += $balance['closing_cents']; }
                if ($balance['code'] === '959.100') { $liability['start'] -= $balance['opening_cents']; $liability['end'] -= $balance['closing_cents']; }
            }
            self::components($report['assets'], ['UR.60', 'UR.52'], $asset, 'other_receivables', 'Ostatní část pohledávek v položce', 'deferred_tax_asset', 'Odložená daňová pohledávka podle vlastní metody');
            self::components($report['liabilities'], ['US.20', 'US.13'], $liability, 'other_liabilities', 'Ostatní část závazků v položce', 'deferred_tax_liability', 'Odložený daňový závazek podle vlastní metody');
        }
        return $report;
    }

    private static function components(array &$rows, array $codes, array $deferred, string $otherKey, string $otherLabel, string $key, string $label): void
    {
        foreach ($codes as $code) foreach ($rows as &$row) {
            if ($row['row_code'] !== $code) continue;
            $other = ['key' => $otherKey, 'label' => $otherLabel];
            $part = ['key' => $key, 'label' => $label];
            foreach ($deferred as $column => $cents) {
                $total = (int) round($row[$column] * 100);
                $other[$column] = ($total - $cents) / 100.0;
                $part[$column] = $cents / 100.0;
            }
            $row['components'] = [$other, $part];
            return;
        }
        throw new ReportException('statement_version_missing', 'Ve výkazu chybí položka pro oddělený rozpis odložené daně.');
    }
}
