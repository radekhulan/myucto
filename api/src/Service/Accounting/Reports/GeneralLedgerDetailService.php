<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Reports;

use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Accounting\AccountCodeRange;
use MyInvoice\Service\Accounting\AccountCodeRangeException;

/** Podrobný export sdílí PS a obraty s hlavní knihou; doklady předává po dávkách. */
final class GeneralLedgerDetailService
{
    /**
     * Rozpis se skládá v paměti (mPDF i PhpSpreadsheet drží celý dokument) a musí
     * stihnout limit PHP (512 MB, 60 s). Změřeno na syntetickém deníku: 10 000 řádků
     * ≈ 30 s. Nad limitem vrací 422 dřív, než se začne sázet.
     */
    public const MAX_LINES = 10000;

    /** Účty jdou v PDF plynule za sebou; hlavička, počáteční stav a součty účtu zaberou asi 6 řádků. */
    public const PDF_ACCOUNT_LINES = 6;

    public function __construct(
        private readonly LedgerReportRepository $ledger,
        private readonly JournalLineContext $context,
        /** Limit rozpisu pro tuto instanci; test akce ho snižuje, aby nepotřeboval 10 000 řádků. */
        private readonly int $maxLines = self::MAX_LINES,
    ) {}

    /** Limit velikosti rozpisu (viz assertExportSize()) s limitem této instance. */
    public function checkExportSize(array $data): void
    {
        self::assertExportSize($data, $this->maxLines);
    }

    /**
     * Účty knihy v rozsahu (pravidlo AccountCodeRange, stejné jako filtr deníku) a součty
     * přepočtené jen z nich. Akce rozsah ověřuje už před sestavením knihy.
     */
    public static function selectAccounts(array $data, string $from = '', string $to = ''): array
    {
        try {
            $range = AccountCodeRange::parse($from, $to);
        } catch (AccountCodeRangeException $e) {
            throw new ReportException('validation_failed', $e->getMessage(), 422);
        }
        $data['account_from'] = $range->from;
        $data['account_to'] = $range->to;
        // Bez rozpadu jsou v datech jen syntetiky: mez s analytikou (501.100) vybere celou
        // syntetiku 501 na obou krajích rozsahu, ne jen na horním.
        $syntheticOnly = empty($data['analytics']);
        $data['accounts'] = array_values(array_filter($data['accounts'],
            static fn (array $a): bool => $range->contains((string) $a['account_code'], $syntheticOnly)));
        foreach ($data['totals'] as $key => $_) {
            $cents = 0;
            foreach ($data['accounts'] as $a) $cents += (int) round($a[$key] * 100);
            $data['totals'][$key] = $cents / 100;
        }
        return $data;
    }

    /** @return array{name:string, ic:string} */
    public function supplierIdentity(int $supplierId): array
    {
        return $this->ledger->supplierIdentity($supplierId);
    }

    /** Velikost rozpisu vybraných účtů (`line_count` ze souhrnu a hlavičky a součty účtů) proti limitu PDF. */
    public static function assertExportSize(array $data, int $maxLines = self::MAX_LINES): void
    {
        $lines = 0;
        foreach ($data['accounts'] as $a) {
            // Počet řádků dává jen kniha sestavená pro rozpis (`forDetail`). Bez něj by
            // limit propustil libovolně velký rozpis a vynechal účty vyrušené červeným stornem.
            if (!array_key_exists('line_count', $a)) {
                throw new \LogicException('Rozpis hlavní knihy potřebuje knihu sestavenou s forDetail.');
            }
            $lines += (int) $a['line_count'];
        }
        $size = $lines + count($data['accounts']) * self::PDF_ACCOUNT_LINES;
        if ($size > $maxLines) {
            throw new ReportException('export_too_large', sprintf(
                'Rozpis má %s řádků v %s účtech, pro PDF je to příliš mnoho (nejvýše %s řádků, hlavička a součty účtu se počítají jako %d řádků). Zužte období nebo rozsah účtů.',
                number_format($lines, 0, ',', ' '), number_format(count($data['accounts']), 0, ',', ' '),
                number_format($maxLines, 0, ',', ' '), self::PDF_ACCOUNT_LINES,
            ), 422);
        }
    }

    /**
     * Události start/line/end dovolují oběma formátům zpracovat i dlouhý účet
     * bez pole celého roku. Kontrolují se proti téže agregaci jako souhrnná kniha.
     * @return \Generator<int,array<string,mixed>>
     */
    public function events(int $supplierId, array $data, array $filters, bool $afterClosing, int $batchSize = 500): \Generator
    {
        $accounts = $data['accounts'];
        if ($accounts === []) return;
        $rows = $this->ledger->generalLedgerLineBatches($supplierId, $data, $filters, $afterClosing, $batchSize);
        $index = 0;
        $started = false;
        $balance = $md = $d = $count = 0;
        foreach ($rows as $batch) {
            foreach ($this->context->withCounterAccounts($supplierId, $batch) as $line) {
                while ((int) $accounts[$index]['account_id'] !== (int) $line['group_id']) {
                    $a = $accounts[$index++];
                    if (!$started) yield ['kind' => 'start', 'account' => $a];
                    yield $this->end($a, $started ? $balance : (int) round(($a['opening_md'] - $a['opening_d']) * 100), $md, $d, $count);
                    $started = false;
                    $md = $d = $count = 0;
                    if (!isset($accounts[$index])) {
                        throw self::mismatch(['group_id' => (int) $line['group_id'], 'group_code' => (string) $line['group_code']]);
                    }
                }
                $a = $accounts[$index];
                if (!$started) {
                    $balance = (int) round(($a['opening_md'] - $a['opening_d']) * 100);
                    yield ['kind' => 'start', 'account' => $a];
                    $started = true;
                }
                $count++;
                $amount = (int) round($line['amount'] * 100);
                if ($line['side'] === 'debit') { $md += $amount; $balance += $amount; }
                else { $d += $amount; $balance -= $amount; }
                yield ['kind' => 'line', 'item' => $line + ['balance' => $balance / 100]];
            }
        }
        while (isset($accounts[$index])) {
            $a = $accounts[$index++];
            if (!$started) yield ['kind' => 'start', 'account' => $a];
            yield $this->end($a, $started ? $balance : (int) round(($a['opening_md'] - $a['opening_d']) * 100), $md, $d, $count);
            $started = false;
            $md = $d = $count = 0;
        }
    }

    private function end(array $account, int $balance, int $md, int $d, int $count): array
    {
        // Počet řádků chytí i ztracenou nebo zdvojenou dvojici, která se v částkách vynuluje
        // (zápis a jeho červené storno). Souhrn ho nese jen v knize sestavené pro rozpis.
        if ($md !== (int) round($account['turnover_md'] * 100)
            || $d !== (int) round($account['turnover_d'] * 100)
            || $balance !== (int) round(($account['closing_md'] - $account['closing_d']) * 100)
            || (array_key_exists('line_count', $account) && $count !== (int) $account['line_count'])) {
            throw self::mismatch([
                'account_code' => (string) $account['account_code'],
                'detail_cents' => ['md' => $md, 'd' => $d, 'balance' => $balance],
                'lines' => ['detail' => $count, 'summary' => $account['line_count'] ?? null],
                'summary' => ['md' => $account['turnover_md'], 'd' => $account['turnover_d'], 'balance' => $account['closing_md'] - $account['closing_d']],
            ]);
        }
        return ['kind' => 'end', 'account' => $account];
    }

    /** Souhrn i rozpis čtou tentýž snímek, nesoulad je tedy chyba sestavy, ne souběh. */
    private static function mismatch(array $context): ReportException
    {
        return new ReportException('ledger_detail_mismatch', 'Rozpis dokladů nesouhlasí se souhrnem hlavní knihy. Kontaktujte podporu.', 409, $context);
    }
}
