<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingFrameworkRepository;
use MyInvoice\Service\Accounting\AccountingFrameworkService;
use PDO;

final class AssetReserveWorkspace
{
    public function __construct(private readonly Connection $db, private readonly ClosingService $closing) {}

    public function read(int $supplierId, int $periodId, array $query, bool $includeAssets = true): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $statement = $pdo->prepare('SELECT id,ends_on,status,row_version FROM accounting_periods WHERE supplier_id=? AND id=?');
            $statement->execute([$supplierId, $periodId]);
            $period = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$period || (new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db)))->forDate($supplierId, $period['ends_on']) !== AccountingFrameworkService::CZ_504) {
                throw AssetReserveSourceDecision::invalid();
            }
            $assetId = $includeAssets ? max(0, (int) ($query['asset_id'] ?? 0)) : 0;
            $search = mb_substr(trim((string) ($query['q'] ?? '')), 0, 100);
            $assets = [];
            $moreAssets = false;
            if ($includeAssets) {
                $statement = $pdo->prepare('SELECT id,name,inventory_number,asset_account_code,input_price,put_into_use_date,status,disposal_date FROM assets WHERE supplier_id=? AND (?=0 OR id=?) AND (name LIKE ? OR inventory_number LIKE ?) ORDER BY name,id LIMIT 101');
                $statement->execute([$supplierId, $assetId, $assetId, '%' . $search . '%', '%' . $search . '%']);
                $assets = $statement->fetchAll(PDO::FETCH_ASSOC);
                $moreAssets = count($assets) > 100;
                $assets = array_slice($assets, 0, 100);
                if ($assetId > 0 && $assets === []) throw AssetReserveSourceDecision::changed();
            }
            $state = ['current' => null, 'revisions' => [], 'blocking_entries' => []];
            $lines = [];
            $moreLines = false;
            $page = min(100000, max(1, (int) ($query['page'] ?? 1)));
            if ($assetId > 0) {
                $state = $this->closing->assetReserveSourceState($supplierId, $periodId, $assetId);
                $lineSearch = mb_substr(trim((string) ($query['line_q'] ?? '')), 0, 100);
                $offset = ($page - 1) * 100;
                $statement = $pdo->prepare("SELECT l.id AS line_id,e.id AS entry_id,e.document_no,e.entry_date,e.description,l.amount,l.cost_center,l.project_id
                    FROM journal_entry_lines l JOIN journal_entries e ON e.id=l.entry_id AND e.supplier_id=l.supplier_id
                    JOIN chart_of_accounts a ON a.id=l.account_id AND a.supplier_id=l.supplier_id
                    WHERE l.supplier_id=? AND a.account_code=? AND l.side='debit' AND l.is_red_storno=0 AND l.amount>0
                    AND e.posted_at IS NOT NULL AND e.entry_date<=? AND (e.source_type<>'asset' OR e.source_id=?)
                    AND (COALESCE(e.document_no,'') LIKE ? OR COALESCE(e.description,'') LIKE ?)
                    ORDER BY e.entry_date,e.id,l.id LIMIT 101 OFFSET $offset");
                $statement->execute([$supplierId, $assets[0]['asset_account_code'], $period['ends_on'], $assetId, '%' . $lineSearch . '%', '%' . $lineSearch . '%']);
                $lines = $statement->fetchAll(PDO::FETCH_ASSOC);
                $moreLines = count($lines) > 100;
                $lines = array_slice($lines, 0, 100);
            }
            $statement = $pdo->prepare("SELECT payload FROM accounting_closing_steps WHERE supplier_id=? AND period_id=? AND step_key='provisions'");
            $statement->execute([$supplierId, $periodId]);
            $payload = json_decode((string) ($statement->fetchColumn() ?: '{}'), true, flags: JSON_THROW_ON_ERROR);
            $entries = [];
            foreach ($payload['entries'] ?? [] as $entry) {
                if (!in_array($entry['rule_key'] ?? '', ['reserve.repairs.create', 'reserve.repairs.release', 'allowance.receivable.legal.change'], true)) continue;
                $entries[] = array_intersect_key($entry, array_flip(['entry_id', 'document_no', 'rule_key', 'amount', 'description', 'invoice_id']));
            }
            $statement = $pdo->prepare('SELECT v.id,v.name FROM dimension_values v JOIN dimension_types t ON t.id=v.type_id AND t.supplier_id=v.supplier_id WHERE v.supplier_id=? ORDER BY v.id');
            $statement->execute([$supplierId]);
            return ['period' => $period, 'assets' => $assets, 'more_assets' => $moreAssets, 'lines' => $lines,
                'line_page' => $page, 'more_lines' => $moreLines, 'state' => $state, 'entries' => $entries,
                'dimension_labels' => array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'name', 'id')];
        } finally {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
        }
    }
}
