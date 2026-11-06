<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Closing;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\{AccountingFrameworkRepository, AccountingPeriodRepository, ChartOfAccountsRepository, ClosingRepository, JournalEntryRepository};
use MyInvoice\Service\Accounting\{AccountingFrameworkService, AccountingRoleProfile};
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use PDO;

final class DeferredTax504Service
{
    public function __construct(private readonly Connection $db, private readonly JournalEntryRepository $journal,
        private readonly AccountingPeriodRepository $periods, private readonly ClosingRepository $closing,
        private readonly DocumentSeriesService $series) {}

    public function preview(int $supplierId, int $periodId, array $body = []): array
    {
        $pdo = $this->db->pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->exec('START TRANSACTION READ ONLY');
        try {
            $period = $this->periods->findById($supplierId, $periodId);
            $this->assertFramework($supplierId, $period, false);
            $read = new DeferredTax504Repository($this->db, $this->journal, false);
            $state = $body === [] && !$read->hasApplicableDecision($supplierId, $period['ends_on'])
                ? ['effective_body' => null, 'balances' => [], 'decisions' => [], 'rows' => [], 'assessment_current' => true]
                : $read->state($supplierId, $period['ends_on']);
            $assessment = DeferredTax504Builder::assessment($body === [] ? ($state['effective_body'] ?? []) : $body);
            $movements = ['opening' => [], 'closing' => []];
            if ($assessment['enabled']) {
                $basis = $state['balances'];
                if ($assessment['method']['first_adoption'] && $body !== []) {
                    if ($state['decisions'] !== [] || $state['rows'] !== []) throw DeferredTax504Repository::changed();
                    $movements['opening'] = DeferredTax504Builder::movements($assessment, [], 'opening');
                    $basis = array_values($assessment['opening']['cells']);
                }
                $movements['closing'] = DeferredTax504Builder::movements($assessment, $basis, 'closing');
            }
            return ['accounting_framework' => 'cz_504', 'as_of' => $period['ends_on'], 'enabled' => $assessment['enabled'],
                'method' => $assessment['method'], 'assessment' => $assessment, 'balances' => $state['balances'],
                'source_hash' => DeferredTax504Repository::sourceHash($this->db, $supplierId, $periodId), 'decision' => $state['effective_body'], 'assessment_current' => $state['assessment_current'], 'movements' => $movements, 'row_version' => (int) $period['row_version']];
        } finally { if ($own && $pdo->inTransaction()) $pdo->rollBack(); }
    }

    public function closingCheck(int $supplierId, int $periodId, bool $currentRead = false): array
    {
        $pdo = $this->db->pdo();
        if ($currentRead && !$pdo->inTransaction()) throw new \LogicException('Current deferred closing check requires caller transaction.');
        $own = !$pdo->inTransaction();
        if ($own) $pdo->exec('START TRANSACTION READ ONLY');
        try {
            if ($currentRead) (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $period = $currentRead ? $this->periods->findForUpdate($periodId, $supplierId) : $this->periods->findById($supplierId, $periodId);
            $this->assertFramework($supplierId, $period, $currentRead);
            $read = new DeferredTax504Repository($this->db, $this->journal, $currentRead);
            if (!$read->hasApplicableDecision($supplierId, $period['ends_on'])) return ['applicable' => false, 'complete' => true];
            $state = $read->state($supplierId, $period['ends_on']);
            $step = (new LegalClosing504Repository($this->db, $this->journal, $currentRead))->step($supplierId, $periodId, 'deferred_tax');
            return ['applicable' => true, 'complete' => $state['assessment_current'] && $state['effective_period_id'] === $periodId && $step['status'] === 'done'];
        } finally { if ($own && $pdo->inTransaction()) $pdo->rollBack(); }
    }

    public function run(int $supplierId, int $periodId, array $body, int $expectedVersion, array $meta = []): array
    {
        return (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $periodId, $body, $expectedVersion, $meta): array {
            (new \MyInvoice\Service\Accounting\Dimension\SystemDimensionWritePolicy($this->db))->lockContext($supplierId);
            $period = $this->periods->findForUpdate($periodId, $supplierId);
            $this->assertFramework($supplierId, $period, true);
            if ($period['status'] !== 'closing') throw new ClosingException('period_not_closing', 'Období není ve stavu uzavírání.', 409);
            if ((int) $period['row_version'] !== $expectedVersion) throw new ClosingException('version_conflict', 'Stav období se změnil. Obnovte uzávěrku.', 409);
            if (!is_string($body['source_hash'] ?? null) || !hash_equals($body['source_hash'], DeferredTax504Repository::sourceHash($this->db, $supplierId, $periodId, true))) throw DeferredTax504Repository::changed();
            $assessment = DeferredTax504Builder::assessment($body);
            if (!$assessment['enabled']) throw new ClosingException('deferred_tax_method_required', 'Před účtováním výslovně zvolte vlastní metodu odložené daně.', 422);
            \MyInvoice\Service\Accounting\FrameworkOperationPolicy::assertAllowed(new AccountingRoleProfile(AccountingFrameworkService::CZ_504), 'deferred_tax', $body);
            $accountIds = self::prepareAccounts($this->db, $supplierId, ($body['prepare_accounts'] ?? false) === true, self::accountDate($period, $assessment));
            $read = new LegalClosing504Repository($this->db, $this->journal);
            $step = $read->step($supplierId, $periodId, 'deferred_tax');
            $payload = $step['payload'];
            $payload['deferred504_pending'] = ['status' => 'pending', 'expected_version' => $expectedVersion,
                'body' => array_intersect_key($body, array_flip(['method', 'titles', 'opening_titles', 'source_hash'])), 'account_ids' => $accountIds,
                'documents' => ['opening' => $this->series->next($supplierId, 'manual', (int) $period['fiscal_year']),
                    'closing' => $this->series->next($supplierId, 'manual', (int) $period['fiscal_year'])],
                'posted_at' => date('Y-m-d H:i:s'), 'posted_by' => $meta['posted_by'] ?? null, 'meta' => $meta];
            $this->closing->upsertStep($supplierId, $periodId, 'deferred_tax', $step['status'], $payload, $step['note'], $meta['user_id'] ?? null);
            return $this->journal->insertCurrentDeferredTax504($supplierId, $periodId, $expectedVersion);
        });
    }

    public static function accountDate(array $period, array $assessment): string
    {
        return $assessment['method']['first_adoption'] ? $period['starts_on'] : $period['ends_on'];
    }

    public static function assertAccounts(Connection $db, int $supplierId, string $date): array
    {
        return self::prepareAccounts($db, $supplierId, false, $date);
    }

    private static function prepareAccounts(Connection $db, int $supplierId, bool $create, string $date): array
    {
        if (!$db->pdo()->inTransaction()) throw new \LogicException('Account preparation requires caller period-first locks.');
        $accounts = array_column((new \MyInvoice\Repository\AccountDefinitionRepository($db))->listForDate($supplierId,$date,true), null, 'account_code');
        $profile = new AccountingRoleProfile(AccountingFrameworkService::CZ_504);
        $definitions = [
            'asset' => ['name' => 'Odložená daňová pohledávka podle vlastní metody', 'account_type' => 'asset', 'normal_side' => 'debit'],
            'expense' => ['name' => 'Odložená daň podle vlastní metody', 'account_type' => 'expense', 'normal_side' => 'debit'],
            'liability' => ['name' => 'Odložený daňový závazek podle vlastní metody', 'account_type' => 'liability', 'normal_side' => 'credit'],
        ];
        $ids = [];
        foreach ($definitions as $role => $definition) {
            $code = $profile->account('deferred_tax.' . $role);
            $account = $accounts[$code] ?? null;
            $parent = $role === 'liability' ? ($accounts['959'] ?? null) : null;
            if ($role === 'liability' && ($parent === null || !$parent['is_active'] || $parent['account_type'] !== 'liability')) throw self::accountError();
            if ($account === null) {
                if (!$create) throw self::accountError();
                $ids[$code] = (new ChartOfAccountsRepository($db))->insert($supplierId, $definition + ['account_code' => $code,
                    'is_synthetic' => $role !== 'liability', 'parent_id' => $parent['id'] ?? null, 'tax_deductibility' => $role === 'expense' ? 'non_deductible' : 'deductible'],$date);
            } else {
                if (!$account['is_active'] || $account['account_type'] !== $definition['account_type'] || $account['normal_side'] !== $definition['normal_side']
                    || ($role === 'expense' && $account['tax_deductibility'] !== 'non_deductible')
                    || (int) ($account['parent_id'] ?? 0) !== (int) ($parent['id'] ?? 0)) throw self::accountError();
                foreach ($accounts as $child) if ((int) ($child['parent_id'] ?? 0) === (int) $account['id']) throw self::accountError();
                $ids[$code] = (int) $account['id'];
            }
        }
        return $ids;
    }

    private function assertFramework(int $supplierId, ?array $period, bool $current): void
    {
        if ($period === null) throw new ClosingException('not_found', 'Účetní období neexistuje.', 404);
        $framework = new AccountingFrameworkService($this->db, new AccountingFrameworkRepository($this->db));
        $resolved = array_values(array_unique($framework->forInterval($supplierId, $period['starts_on'], $period['ends_on'], $current)));
        if ($resolved !== [AccountingFrameworkService::CZ_504]) throw new ClosingException('accounting_framework_operation_not_supported', 'Vlastní metoda je určena pouze pro celé účetní období v rámci 504.', 422);
    }

    private static function accountError(): ClosingException
    {
        return new ClosingException('deferred_tax_accounts_required', 'Výslovně připravte účty vlastní metody odložené daně. Existující nekompatibilní účty se automaticky nemění.', 422);
    }
}
