<?php

declare(strict_types=1);
namespace MyInvoice\Repository\Property\Payments;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Repository\Property\PropertyReadRepository;
use MyInvoice\Service\Property\PropertyInstant;
use MyInvoice\Service\Property\PropertyInput;
use MyInvoice\Service\Property\Payments\PaymentException;
use PDO;

final class PaymentWorkflowReadRepository
{
    public function __construct(private readonly Connection $db) {}

    public function context(int $supplier, string $asOf, bool $bank, bool $cash): array
    {
        PropertyInstant::normalize($asOf);
        $bankPage = $this->candidates($supplier, 'bank', 0, 100, $bank);
        $cashPage = $this->candidates($supplier, 'cash', 0, 100, $cash);
        $candidates = [...$bankPage['items'], ...$cashPage['items']];
        $dimensionRepository = new \MyInvoice\Repository\DimensionRepository($this->db);
        [$typeScope, $typeParams] = $dimensionRepository->visibleSql($supplier, 't');
        [$valueScope, $valueParams] = $dimensionRepository->visibleSql($supplier, 'v');
        $dimensions = $this->all('SELECT t.id type_id,t.name type_name,t.system_key,v.id,v.name,v.code FROM dimension_types t JOIN dimension_values v ON v.type_id=t.id WHERE ' . $typeScope . ' AND ' . $valueScope . ' AND t.is_active=1 AND v.is_active=1 ORDER BY t.id,v.sort_order,v.id', [...$typeParams, ...$valueParams]);
        foreach ($dimensions as &$row) { $row['id'] = (int) $row['id']; $row['type_id'] = (int) $row['type_id']; } unset($row);
        $registers = $cash ? $this->all("SELECT id,name,currency_code FROM cash_registers WHERE supplier_id=? AND is_active=1 ORDER BY id", [$supplier]) : [];
        foreach ($registers as &$row) $row['id'] = (int) $row['id']; unset($row);
        return ['as_of' => $asOf, 'candidates' => $candidates, 'candidate_coverage' => ['limit_per_kind' => 100, 'truncated' => $bankPage['next_cursor'] !== null || $cashPage['next_cursor'] !== null,
            'eligibility' => 'current_preview_required', 'fx' => 'incomplete'], 'registers' => $registers,
            'units' => (new PropertyReadRepository($this->db))->list($supplier, 'unit', $asOf), 'dimensions' => $dimensions];
    }

    /**
     * Zdroje úhrady po stránkách. `$search` hledá u banky přesnou shodu VS nebo SS
     * a výskyt v protistraně či popisu, `$id` vrátí jediný zdroj (předvýběr z banky).
     * Obě podmínky jen zužují stránku nad omezením na firmu a volný pohyb.
     */
    public function candidates(int $supplier, string $kind, int $after = 0, int $limit = 25, bool $allowNative = false, string $search = '', ?int $id = null): array
    {
        if (!in_array($kind, ['bank', 'cash'], true) || $after < 0 || $limit < 1 || $limit > 100 || mb_strlen($search) > 100) PropertyInput::invalid('page');
        if ($id !== null) PropertyInput::id($id, 'id');
        $size = $limit + 1;
        $search = trim($search);
        $like = '%' . addcslashes($search, "%_\\") . '%';
        if ($kind === 'bank') {
            $filter = '';
            $filterParams = [];
            if ($id !== null) {
                $filter .= ' AND bt.id=?';
                $filterParams[] = $id;
            }
            if ($search !== '') {
                $filter .= ' AND (bt.variable_symbol=? OR bt.specific_symbol=? OR bt.counterparty_name LIKE ? OR bt.description LIKE ?)';
                array_push($filterParams, $search, $search, $like, $like);
            }
            $sql = "SELECT bt.id,bt.posted_at date,bt.amount,COALESCE(NULLIF(bt.currency,''),bs.currency) currency,bt.counterparty_name partner_name,bt.description,bt.variable_symbol,bt.specific_symbol,
                ps.id payment_source_id,ps.amount_cents,ps.consumed_cents,ps.currency_code,ps.native_amount_minor,ps.consumed_native_minor,ps.fx_rate
                FROM bank_transactions bt JOIN bank_statements bs ON bs.id=bt.statement_id
                LEFT JOIN unit_payment_sources ps ON ps.bank_transaction_id=bt.id AND ps.supplier_id=?
                WHERE " . BankStatementOwnershipResolver::sql() . " AND bt.source='statement' AND bt.match_status='unmatched' AND bt.matched_invoice_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id=bt.id)
                AND NOT EXISTS (SELECT 1 FROM invoice_payments ip WHERE ip.bank_transaction_id=bt.id)
                AND NOT EXISTS (SELECT 1 FROM payroll_payment_matches pp WHERE pp.bank_transaction_id=bt.id)
                AND bt.id>?" . $filter . ($allowNative ? '' : ' AND ps.id IS NOT NULL') . " ORDER BY bt.id LIMIT $size";
            $rows = $this->all($sql, [$supplier, ...BankStatementOwnershipResolver::params($supplier), $after, ...$filterParams]);
        } else {
            $filter = '';
            $filterParams = [];
            if ($id !== null) {
                $filter .= ' AND c.id=?';
                $filterParams[] = $id;
            }
            if ($search !== '') {
                $filter .= ' AND (c.partner_name LIKE ? OR c.description LIKE ?)';
                array_push($filterParams, $like, $like);
            }
            $rows = $this->all("SELECT c.id,c.issue_date date,c.total_amount amount,c.currency_code currency,c.partner_name,c.description,NULL variable_symbol,NULL specific_symbol,c.doc_type,
                ps.id payment_source_id,ps.amount_cents,ps.consumed_cents,ps.currency_code,ps.native_amount_minor,ps.consumed_native_minor,ps.fx_rate FROM cash_documents c LEFT JOIN unit_payment_sources ps ON ps.cash_document_id=c.id AND ps.supplier_id=c.supplier_id
                WHERE c.supplier_id=? AND c.id>? AND c.purpose='other' AND c.vat_mode='none' AND (c.status='draft' OR ps.id IS NOT NULL OR (c.status='posted' AND c.currency_code='EUR'))" . $filter . ($allowNative ? '' : ' AND ps.id IS NOT NULL') . " ORDER BY c.id LIMIT $size", [$supplier, $after, ...$filterParams]);
        }
        $more = count($rows) > $limit;
        if ($more) array_pop($rows);
        return ['items' => array_map(fn (array $row): array => $this->candidate($kind, $row), $rows),
            'next_cursor' => $more ? (int) $rows[array_key_last($rows)]['id'] : null,
            'coverage' => ['eligibility' => 'current_preview_required', 'fx' => 'incomplete']];
    }

    public function history(int $supplier): array
    {
        return $this->historyPage($supplier, 0, 100)['items'];
    }

    public function historyPage(int $supplier, int $before = 0, int $limit = 25): array
    {
        if ($before < 0 || $limit < 1 || $limit > 100) PropertyInput::invalid('page');
        $scope = $before === 0 ? '' : ' AND id < ?';
        $params = $before === 0 ? [$supplier] : [$supplier, $before];
        $rows = $this->all("SELECT id,source_kind,bank_transaction_id,cash_document_id,status,plan_json,preview_hash,recorded_at FROM unit_payment_workflows WHERE supplier_id=? AND status='confirmed'{$scope} ORDER BY id DESC LIMIT " . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(static function (array $row): array {
            $plan = json_decode($row['plan_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!hash_equals($row['preview_hash'], PropertyInput::hash($plan))) PaymentException::reject('workflow_hash_mismatch');
            return ['id' => (int) $row['id'], 'kind' => $row['source_kind'], 'source_id' => (int) ($row['bank_transaction_id'] ?? $row['cash_document_id']),
                'date' => $plan['source']['date'], 'amount_cents' => $plan['source']['amount_cents'], 'settled_cents' => $plan['settled_cents'],
                'available_cents' => $plan['available_cents'], 'recorded_at' => $row['recorded_at']];
        }, $rows);
        return ['items' => $items, 'next_cursor' => $more ? end($items)['id'] : null];
    }

    public function source(int $supplier, string $kind, int $id, bool $allowNative = false): array
    {
        return (new \MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard($this->db))->transaction(function () use ($supplier, $kind, $id, $allowNative): array {
            (new \MyInvoice\Service\Property\Payments\PaymentLocks($this->db))->acquire($supplier);
            $source = (new \MyInvoice\Service\Property\Payments\PaymentSourceProof($this->db))->current($supplier, $kind, $id);
            if ($source['id'] === null && (!$allowNative || !isset($source['receipt']['native']))) PaymentException::reject('source_not_found');
            $receipt = $source['receipt'];
            $cells = $source['clearing_cells'] ?? $receipt['clearing_cells'] ?? [['key' => 'original', 'amount_cents' => $receipt['amount_cents'], 'line' => $receipt['clearing_line']]];
            $available = \MyInvoice\Service\Property\Payments\Workflow\ClearingCells::available($cells, $source['cell_consumed'] ?? ['original' => $source['consumed_cents']]);
            $rows = [];
            foreach ($cells as $cell) {
                $native = isset($cell['line']['amount_foreign']) ? bcmul($cell['line']['amount_foreign'], '100', 0) : null;
                $usedNative = $native === null ? null : ($source['cell_native_consumed'][$cell['key']] ?? '0');
                $rows[] = ['key' => $cell['key'], 'amount_cents' => $cell['amount_cents'], 'available_cents' => $available[$cell['key']],
                    'native_amount_minor' => $native, 'consumed_native_minor' => $usedNative,
                    'available_native_minor' => $native === null ? null : bcsub($native, $usedNative, 0),
                    'dimensions' => $cell['line']['dimensions'], 'dimension_splits' => $cell['line']['dimension_splits']];
            }
            return ['source_id' => $source['id'], 'source_version' => $source['version'], 'revision_id' => $source['revision_id'] ?? null,
                'revision_date' => $source['revision_date'] ?? null, 'kind' => $kind, 'id' => $id, 'date' => $receipt['date'], 'direction' => $receipt['direction'],
                'amount_cents' => $receipt['amount_cents'], 'consumed_cents' => $source['consumed_cents'], 'available_cents' => $source['available_cents'],
                'currency' => $receipt['currency'], 'native' => $receipt['native'] ?? null,
                'consumed_native_minor' => $source['consumed_native_minor'], 'available_native_minor' => $source['available_native_minor'], 'cells' => $rows];
        });
    }

    private function candidate(string $kind, array $row): array
    {
        $amount = \MyInvoice\Service\Accounting\JournalLineAmount::decimal($row['amount']);
        return ['kind' => $kind, 'id' => (int) $row['id'], 'date' => $row['date'], 'amount' => $amount, 'currency' => $row['currency'],
            'direction' => $row['doc_type'] ?? (bccomp($amount, '0', 2) > 0 ? 'in' : 'out'), 'partner_name' => $row['partner_name'],
            'description' => $row['description'], 'variable_symbol' => $row['variable_symbol'], 'specific_symbol' => $row['specific_symbol'] ?? null, 'existing_owner' => $row['payment_source_id'] !== null,
            'available_cents' => $row['payment_source_id'] === null ? ($row['currency'] === 'CZK' ? bcmul(ltrim($amount, '-'), '100', 0) : '0') : bcsub($row['amount_cents'], $row['consumed_cents'], 0),
            'native_amount_minor' => $row['native_amount_minor'],
            'available_native_minor' => $row['native_amount_minor'] === null ? null : bcsub($row['native_amount_minor'], $row['consumed_native_minor'], 0),
            'support' => in_array($row['currency'], ['CZK', 'EUR'], true) || $row['payment_source_id'] !== null ? 'preview_required' : 'fx_incomplete'];
    }

    private function all(string $sql, array $values): array
    {
        $q = $this->db->pdo()->prepare($sql); $q->execute($values); return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
