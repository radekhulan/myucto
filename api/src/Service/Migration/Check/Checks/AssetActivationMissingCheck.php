<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check\Checks;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\LedgerReportRepository;
use MyInvoice\Service\Migration\Check\CheckItem;
use MyInvoice\Service\Migration\Check\CheckResult;
use MyInvoice\Service\Migration\Check\MigrationSources;
use MyInvoice\Service\Migration\Check\SourceScopedCheck;
use PDO;

final class AssetActivationMissingCheck extends SourceScopedCheck
{
    public const KEY = 'asset_activation_missing';

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
        return 'Majetek pořízený v roce převodu bez zařazení na účet majetku';
    }

    public function manual(): string
    {
        return self::MANUAL_CHAPTER . '#12616-majetek-porizeny-v-roce-prevodu-bez-zarazeni-na-ucet-majetku';
    }

    protected function sources(): array
    {
        return [MigrationSources::POHODA, MigrationSources::MONEY_S3, MigrationSources::ABRA,
            MigrationSources::PREMIER, MigrationSources::STEREO_NX, MigrationSources::HELIOS];
    }

    public function detect(int $supplierId): CheckResult
    {
        $asOf = date('Y-m-d');
        $anchor = $this->ledger->openingAnchor($supplierId, $asOf);
        $stmt = $this->db->pdo()->prepare(
            "WITH accounting_start AS (
                SELECT s.id AS supplier_id, COALESCE(s.accounting_starts_on,
                        MIN(CASE WHEN p.created_reason = 'setup' THEN p.starts_on END), MIN(p.starts_on)) AS starts_on
                  FROM supplier s
                  JOIN accounting_periods p ON p.supplier_id = s.id
                 WHERE s.id = ? AND s.accounting_mode = 'double_entry'
                 GROUP BY s.id, s.accounting_starts_on
            ), acquisition_balances AS (
                SELECT c.account_code, LEFT(c.account_code, 3) AS synthetic,
                       SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS balance
                  FROM chart_of_accounts c
                  STRAIGHT_JOIN journal_entry_lines l ON l.supplier_id = c.supplier_id AND l.account_id = c.id
                  STRAIGHT_JOIN journal_entries e ON e.supplier_id = l.supplier_id AND e.id = l.entry_id
                  JOIN accounting_start s ON s.supplier_id = c.supplier_id
                 WHERE c.supplier_id = ? AND c.account_code LIKE '04%' AND e.posted_at IS NOT NULL
                   AND e.entry_date >= COALESCE(?, s.starts_on) AND e.entry_date <= ? AND e.source_type <> 'closing'
                 GROUP BY c.account_code
            ), candidates AS (
                SELECT a.supplier_id, a.id, a.inventory_number, a.name, a.acquisition_date, a.input_price,
                       a.acquisition_account_code, SUM(b.balance) AS acquisition_balance
                  FROM assets a
                  JOIN accounting_start s ON s.supplier_id = a.supplier_id
                  JOIN accounting_periods p ON p.supplier_id = s.supplier_id AND s.starts_on BETWEEN p.starts_on AND p.ends_on
                  JOIN acquisition_balances b ON b.account_code = a.acquisition_account_code
                    OR (CHAR_LENGTH(a.acquisition_account_code) = 3 AND b.synthetic = a.acquisition_account_code)
                 WHERE a.supplier_id = ? AND a.status IN ('draft', 'in_use') AND a.input_price > 0
                   AND a.acquisition_date >= s.starts_on AND a.acquisition_date <= p.ends_on
                   AND (a.asset_account_code LIKE '01%' OR a.asset_account_code LIKE '02%')
                 GROUP BY a.supplier_id, a.id, a.inventory_number, a.name, a.acquisition_date, a.input_price, a.acquisition_account_code
                HAVING SUM(b.balance) > 0
            ), posted_assets AS (
                SELECT e.entry_date, e.source_type, COALESCE(n.id, m.id, p.id) AS asset_id,
                       SUM(CASE l.side WHEN 'debit' THEN l.signed_amount ELSE -l.signed_amount END) AS acquisition_price
                  FROM chart_of_accounts c
                  STRAIGHT_JOIN journal_entry_lines l ON l.supplier_id = c.supplier_id AND l.account_id = c.id
                  STRAIGHT_JOIN journal_entries e ON e.supplier_id = l.supplier_id AND e.id = l.entry_id
                  JOIN accounting_start s ON s.supplier_id = c.supplier_id
                  LEFT JOIN assets n ON n.supplier_id = e.supplier_id AND n.id = e.source_id AND e.source_type = 'asset'
                  LEFT JOIN assets m ON m.supplier_id = e.supplier_id AND m.inventory_number = e.document_no AND e.source_type = 'manual'
                  LEFT JOIN assets p ON p.supplier_id = e.supplier_id AND p.purchase_invoice_id = e.source_id AND e.source_type = 'purchase_invoice'
                 WHERE c.supplier_id = ? AND (c.account_code LIKE '01%' OR c.account_code LIKE '02%')
                   AND e.entry_date >= s.starts_on AND e.posted_at IS NOT NULL AND e.reversed_by IS NULL
                   AND COALESCE(n.id, m.id, p.id) IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM journal_entries original
                                    WHERE original.supplier_id = e.supplier_id AND original.reversed_by = e.id)
                 GROUP BY e.id, e.entry_date, e.source_type, COALESCE(n.id, m.id, p.id)
                HAVING acquisition_price > 0
            )
            SELECT a.* FROM candidates a
             WHERE NOT EXISTS (
                SELECT 1 FROM posted_assets e
                 WHERE e.asset_id = a.id AND e.entry_date >= a.acquisition_date
                   AND (e.acquisition_price = a.input_price OR (e.source_type = 'asset'
                     AND e.acquisition_price = a.input_price + COALESCE((
                        SELECT SUM(i.amount) FROM asset_improvements i
                         WHERE i.supplier_id = a.supplier_id AND i.asset_id = a.id AND i.completed_on <= e.entry_date), 0)))
             )
             ORDER BY a.id"
        );
        $stmt->execute([$supplierId, $supplierId, $anchor, $asOf, $supplierId, $supplierId]);

        $items = [];
        while ($card = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $account = $card['acquisition_account_code'];
            $balance = (float) $card['acquisition_balance'];
            $items[] = new CheckItem('asset', (int) $card['id'], $card['inventory_number'] . ' ' . $card['name'],
                $card['acquisition_date'], (float) $card['input_price'], CheckItem::REVIEW,
                sprintf('Na účtu pořízení %s je otevřený zůstatek %s. U zařazené karty použijte Vrátit do konceptu a poté kartu zařaďte se zápisem o zařazení.',
                    $account, self::money($balance)),
                details: ['acquisition_account_code' => $account, 'acquisition_balance' => $balance]);
        }
        return new CheckResult($items);
    }
}
