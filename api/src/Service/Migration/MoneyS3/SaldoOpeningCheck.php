<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Service\Accounting\Reports\SaldoService;

/**
 * Kontrolní sestava převodu: saldokonto ke konci posledního převáděného období proti zůstatkům
 * účtů, tedy proti počátečním stavům dalšího roku. Otevřené doklady, zálohy a ostatní položky
 * na saldokontních účtech mají v MyÚčtu v roce 2027 pokrýt přesně počáteční stav; rozdíl znamená
 * zůstatek bez dokladu (doklady z let před převodem, mzdy, zápočty mimo doklady), který se
 * v dalším roce nedá spárovat s úhradou.
 *
 * Počítá stejná sestava jako Účetnictví → Saldokonto ({@see SaldoService}), převod nic nezapisuje.
 */
final class SaldoOpeningCheck
{
    public const STEP = 'saldo_check';

    /** Saldokontní účty kontroly: odběratelé, dodavatelé, zálohy, zaměstnanci, pojistné, daň ze mzdy. */
    public const ACCOUNTS = ['311', '321', '314', '324', '331', '336', '342'];

    public function __construct(private readonly SaldoService $saldo) {}

    public function run(ImportContext $ctx): void
    {
        $p = $ctx->protocol;
        if ($ctx->periods === []) {
            $p->finish(self::STEP);
            return;
        }
        $last = $ctx->periods[max(array_keys($ctx->periods))];
        $asOf = (string) $last['ends_on'];
        $next = (new \DateTimeImmutable($asOf))->modify('+1 day')->format('j. n. Y');
        $rows = [];
        $differences = [];
        foreach (self::ACCOUNTS as $code) {
            try {
                $report = $this->saldo->build($ctx->supplierId, (int) $last['id'], $asOf, $code);
            } catch (\Throwable $e) {
                if (TransactionFailure::aborted($e)) {
                    throw $e;
                }
                // Kontrola je jen informativní: sestavu, kterou nejde spočítat, převod ohlásí.
                $rows[] = ['account' => $code, 'error' => $e->getMessage()];
                continue;
            }
            foreach ($report['accounts'] ?? [] as $block) {
                $row = [
                    'account' => (string) $block['account']['code'],
                    'balance' => (float) $block['gl_balance'],
                    'open_items' => (float) $block['open_items_total'],
                    'open_items_count' => (int) $block['open_items_count'],
                    'difference' => (float) $block['difference'],
                ];
                $rows[] = $row;
                if (!$block['matches']) {
                    $differences[] = $row;
                }
            }
        }
        $p->set(self::STEP, ['as_of' => $asOf, 'accounts' => $rows]);
        $fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $p->warn(self::STEP, 'saldo_check_failed', "Saldokonto účtu {$row['account']} k {$next} se nepodařilo spočítat: {$row['error']}", ['account' => $row['account']]);
            }
        }
        if ($differences === []) {
            $p->info(self::STEP, 'saldo_check_ok', "Saldokonto k {$next} sedí na počáteční stavy účtů " . implode(', ', array_column(array_filter($rows, static fn (array $r): bool => !isset($r['error'])), 'account')) . '.');
        } else {
            $p->warn(self::STEP, 'saldo_check_difference', sprintf(
                'Saldokonto k %s nesedí na počáteční stavy: %s. Zůstatek bez dokladu nejde v dalším roce spárovat s úhradou; založte k němu položku ze zůstatku nebo doklad počátečního stavu.',
                $next, implode('; ', array_map(static fn (array $r): string => sprintf('%s počáteční stav %s, otevřené položky %s (%d), rozdíl %s',
                    $r['account'], $fmt($r['balance']), $fmt($r['open_items']), $r['open_items_count'], $fmt($r['difference'])), $differences)),
            ), ['as_of' => $asOf, 'accounts' => $differences]);
        }
        $p->finish(self::STEP);
    }
}
