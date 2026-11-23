<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Reports\ReportException;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use MyInvoice\Service\Migration\Shared\ReconciliationTolerance;
use PDO;

/**
 * Kontrola 5: saldokonto 311, 321, 324 a 325 proti hlavní knize po účtech a letech.
 *
 * Sestava Saldokonto ({@see SaldoService}) porovná otevřené položky dokladů se zůstatkem
 * účtu v hlavní knize. Kontrola ji spustí ke konci každého účetního období s deníkem (nebo
 * k poslednímu zápisu, k dnešku) stejně jako rekonciliace převodu z POHODY a vypíše rozdíly
 * nad zaokrouhlení ({@see ReconciliationTolerance::isRounding()}). Rozdíl může být oprávněný
 * (přeplatek bez dokladu, ruční zápis), proto kontrola jen hlásí a proklikem otevře
 * saldokonto účtu k danému dni.
 */
final class SubledgerLedgerCheck extends SourceScopedCheck
{
    public const KEY = 'subledger_vs_ledger';

    /** Saldokontní účty kontroly. */
    public const ACCOUNTS = ['311', '321', '324', '325'];

    public function __construct(
        MigrationSources $sources,
        private readonly Connection $db,
        private readonly SaldoService $saldo,
    ) {
        parent::__construct($sources);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function title(): string
    {
        return 'Saldokonto 311, 321, 324 a 325 proti hlavní knize';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#1267-saldokonto-proti-hlavni-knize';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3, MigrationSources::ABRA, MigrationSources::PREMIER,
            MigrationSources::STEREO_NX, MigrationSources::HELIOS];
    }

    public function detect(int $supplierId): CheckResult
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT ap.id, ap.fiscal_year, ap.ends_on, MAX(e.entry_date) AS last_entry
               FROM accounting_periods ap
               JOIN journal_entries e ON e.supplier_id = ap.supplier_id AND e.period_id = ap.id AND e.posted_at IS NOT NULL
              WHERE ap.supplier_id = ? AND ap.starts_on <= ?
              GROUP BY ap.id, ap.fiscal_year, ap.ends_on
              ORDER BY ap.starts_on"
        );
        $today = date('Y-m-d');
        $stmt->execute([$supplierId, $today]);
        $items = [];
        $notes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $asOf = min((string) $p['ends_on'], substr((string) $p['last_entry'], 0, 10), $today);
            foreach (self::ACCOUNTS as $code) {
                try {
                    $data = $this->saldo->buildCached($supplierId, (int) $p['id'], $asOf, $code);
                } catch (ReportException $e) {
                    $notes[] = sprintf('Rok %d, účet %s: saldokonto nešlo sestavit (%s).', (int) $p['fiscal_year'], $code, $e->getMessage());
                    continue;
                }
                foreach ($data['accounts'] as $block) {
                    $difference = round((float) $block['difference'], 2);
                    if (ReconciliationTolerance::isRounding($difference)) {
                        continue;
                    }
                    $items[] = new CheckItem('account', null, sprintf('%s %s, rok %d', $block['account']['code'], $block['account']['name'], (int) $p['fiscal_year']),
                        $asOf, $difference, CheckItem::REVIEW,
                        sprintf('Zůstatek hlavní knihy %s, součet otevřených položek %s, rozdíl %s. Rozdíl může být oprávněný (přeplatek bez dokladu), ověřte ho v saldokontu.',
                            self::money((float) $block['gl_balance']), self::money((float) $block['open_items_total']), self::money($difference)),
                        details: ['account' => (string) $block['account']['code'], 'fiscal_year' => (int) $p['fiscal_year'], 'period_id' => (int) $p['id'],
                            'as_of' => $asOf, 'gl_balance' => round((float) $block['gl_balance'], 2), 'open_items_total' => round((float) $block['open_items_total'], 2)]);
                }
            }
        }
        return new CheckResult($items, $notes);
    }
}
