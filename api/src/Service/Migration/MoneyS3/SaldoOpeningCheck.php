<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ClosingRepository;
use MyInvoice\Service\Accounting\Reports\SaldoService;

/**
 * Kontrolní sestava převodu: saldokonto ke konci posledního převáděného období proti počátečním
 * stavům dalšího roku. Otevřené doklady, zálohy a ostatní položky na saldokontních účtech mají
 * v MyÚčtu v roce 2027 pokrýt přesně počáteční stav; rozdíl znamená zůstatek bez dokladu (doklady
 * z let před převodem, mzdy, zápočty mimo doklady), který se v dalším roce nedá spárovat s úhradou.
 *
 * Počáteční stav je skutečný otevírací zápis následujícího období v MyÚčtu (převzatý z Money,
 * nebo založený v MyÚčtu), který se může od konečných stavů roku lišit. Nemá-li následující
 * období otevírací zápis, porovná se saldokonto s konečnými stavy účtů a protokol to výslovně
 * uvede (`saldo_check_against_closing`).
 *
 * Počítá stejná sestava jako Účetnictví → Saldokonto ({@see SaldoService}), převod nic nezapisuje.
 */
final class SaldoOpeningCheck
{
    public const STEP = 'saldo_check';

    /** Saldokontní účty kontroly: odběratelé, dodavatelé, zálohy, zaměstnanci, pojistné, daň ze mzdy. */
    public const ACCOUNTS = ['311', '321', '314', '324', '331', '336', '342'];

    public function __construct(
        private readonly SaldoService $saldo,
        private readonly AccountingPeriodRepository $periods,
        private readonly ClosingRepository $closing,
    ) {}

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
        $asOfText = (new \DateTimeImmutable($asOf))->format('j. n. Y');
        $nextPeriod = $this->periods->nextPeriod($ctx->supplierId, $asOf);
        $opening = $nextPeriod !== null && $this->closing->openingEntriesInPeriod($ctx->supplierId, (int) $nextPeriod['id']) !== []
            ? $this->closing->openingBalancesInPeriod($ctx->supplierId, (int) $nextPeriod['id']) : null;
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
                if ($opening !== null) {
                    $row['opening'] = self::openingBalance($opening, $row['account'], (string) $block['account']['normal_side']);
                    $row['difference'] = round($row['opening'] - $row['open_items'], 2);
                }
                $rows[] = $row;
                if ((int) round($row['difference'] * 100) !== 0) {
                    $differences[] = $row;
                }
            }
        }
        $basis = $opening !== null ? 'opening_entry' : 'closing_balances';
        $p->set(self::STEP, ['as_of' => $asOf, 'basis' => $basis, 'accounts' => $rows]);
        $fmt = static fn (float $v): string => number_format($v, 2, ',', ' ');
        if ($opening === null) {
            $p->info(self::STEP, 'saldo_check_against_closing', ($nextPeriod === null ? "Období od {$next} v MyÚčtu není" : "Období od {$next} nemá v MyÚčtu otevírací zápis")
                . ", saldokonto se proto porovnalo s konečnými stavy účtů k {$asOfText}, ne se skutečnými počátečními stavy.", ['as_of' => $asOf]);
        }
        $target = $opening !== null ? "počáteční stavy k {$next} (otevírací zápis)" : "konečné stavy účtů k {$asOfText}";
        $label = $opening !== null ? 'počáteční stav' : 'konečný stav';
        foreach ($rows as $row) {
            if (isset($row['error'])) {
                $p->warn(self::STEP, 'saldo_check_failed', "Saldokonto účtu {$row['account']} k {$next} se nepodařilo spočítat: {$row['error']}", ['account' => $row['account']]);
            }
        }
        if ($differences === []) {
            $p->info(self::STEP, 'saldo_check_ok', "Saldokonto k {$asOfText} sedí na {$target} účtů " . implode(', ', array_column(array_filter($rows, static fn (array $r): bool => !isset($r['error'])), 'account')) . '.', ['basis' => $basis]);
        } else {
            $p->warn(self::STEP, 'saldo_check_difference', sprintf(
                'Saldokonto k %s nesedí na %s: %s. Zůstatek bez dokladu nejde v dalším roce spárovat s úhradou; založte k němu položku ze zůstatku nebo doklad počátečního stavu.',
                $asOfText, $target, implode('; ', array_map(static fn (array $r): string => sprintf('%s %s %s, otevřené položky %s (%d), rozdíl %s',
                    $r['account'], $label, $fmt($r['opening'] ?? $r['balance']), $fmt($r['open_items']), $r['open_items_count'], $fmt($r['difference'])), $differences)),
            ), ['as_of' => $asOf, 'basis' => $basis, 'accounts' => $differences]);
        }
        $p->finish(self::STEP);
    }

    /**
     * Počáteční stav syntetického účtu z otevíracího zápisu na normální straně účtu (kladný:
     * pohledávka MD, závazek D), stejně jako zůstatek v saldokontu.
     *
     * @param array<string,float> $opening kód účtu => MD − D ({@see ClosingRepository::openingBalancesInPeriod()})
     */
    private static function openingBalance(array $opening, string $synthetic, string $normalSide): float
    {
        $signed = 0.0;
        foreach ($opening as $code => $balance) {
            if (substr((string) $code, 0, strlen($synthetic)) === $synthetic) {
                $signed += $balance;
            }
        }
        return round($normalSide === 'debit' ? $signed : -$signed, 2);
    }
}
