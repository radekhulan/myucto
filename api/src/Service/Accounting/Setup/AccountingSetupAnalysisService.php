<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting\Setup;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ExpenseKeywordCatalogRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\Bank\BankMessageNormalizer;
use MyInvoice\Service\Accounting\Expense\ExpenseClassificationService;
use MyInvoice\Service\Accounting\Expense\ExpenseKind;
use MyInvoice\Service\Automation\RuleProposalService;
use PDO;

final class AccountingSetupAnalysisService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ImportJobRepository $jobs,
        private readonly AccountingSetupRepository $setup,
        private readonly ChartOfAccountsRepository $chart,
        private readonly PostingRuleRepository $postingRules,
        private readonly ExpenseClassificationRuleRepository $expenseRules,
        private readonly ExpenseKeywordCatalogRepository $catalog,
        private readonly ExpenseClassificationService $classification,
        private readonly RuleProposalService $bankProposals,
        private readonly AccountingSetupAiSampleBuilder $aiSamples,
        private readonly AccountingSetupAiEnricherInterface $aiEnricher,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null || $job['source'] !== 'accounting_setup_analysis' || !$this->jobs->markRunning($jobId)) {
            return;
        }
        $supplierId = (int) $job['supplier_id'];
        $params = (array) ($job['params'] ?? []);
        $catalogVersion = $this->catalog->latestVersion();
        $run = $this->setup->runByJob($jobId);
        $runId = $run === null
            ? $this->setup->createRun($supplierId, $jobId, $params, $catalogVersion, (int) $job['created_by'])
            : (int) $run['id'];

        try {
            $rows = $this->items($supplierId, $params);
            $this->jobs->updateProgress($jobId, ['total_items' => count($rows), 'current_step' => 'purchase_invoices']);
            $catalog = $this->catalog->active($catalogVersion);
            $activeExpenseRules = $this->expenseRules->activeFor($supplierId);
            $chart = $this->chartState($supplierId);
            $fixedAssetAnalytic = $this->analyticForGroup($chart, 'fixed_asset', 'fixed_asset');
            $fixedAssetCount = 0;
            $fixedAssetAmount = 0.0;
            $groups = [];
            $unclassified = 0;
            $storedScorable = 0;
            $storedAgreement = 0;
            $accountScorable = 0;
            $accountAgreement = 0;
            $hashRows = [];
            $created = 0;
            $unclassifiedRows = [];

            foreach ($rows as $index => $row) {
                if ($this->jobs->isCancelRequested($jobId)) {
                    $this->jobs->markCancelled($jobId);
                    return;
                }
                $description = (string) $row['description'];
                $normalized = BankMessageNormalizer::normalizeKeepDigits($description);
                $matched = self::matchCatalog($normalized, $catalog);
                $year = (int) $row['acq_year'];
                $unitPriceCzk = abs((float) $row['unit_price_without_vat']) * self::fxRate($row['exchange_rate']);
                $suggestion = $this->classification->suggestForItem(
                    $supplierId,
                    $description,
                    $row['vendor_name'] !== null ? (string) $row['vendor_name'] : null,
                    $row['vendor_id'] !== null ? (int) $row['vendor_id'] : null,
                    $unitPriceCzk,
                    $year,
                );

                if ($suggestion === null) {
                    $unclassified++;
                    $unclassifiedRows[] = $row;
                }
                if ($suggestion !== null && $row['expense_kind'] !== null) {
                    $storedScorable++;
                    if ($suggestion->kind->value === (string) $row['expense_kind']) {
                        $storedAgreement++;
                    }
                }
                if ($suggestion !== null && $row['historical_account'] !== null) {
                    $accountScorable++;
                    $suggestedAccount = $suggestion->accountCode ?? $suggestion->kind->fallbackAccount();
                    if (self::sameSynthetic($suggestedAccount, (string) $row['historical_account'])) {
                        $accountAgreement++;
                    }
                }

                if ($matched !== null && $suggestion !== null) {
                    $ruleKind = self::baseRuleKind($matched, $suggestion->kind->value);
                    $groupVendorId = self::groupingVendorId($ruleKind, $row['vendor_id']);
                    $key = implode('|', [
                        (string) ($groupVendorId ?? 0), $matched['locale'], $matched['concept_key'],
                        $matched['phrase'], $ruleKind, (string) ($suggestion->accountCode ?? ''),
                    ]);
                    $groups[$key] ??= [
                        'vendor_id' => $groupVendorId,
                        'vendor_name' => $groupVendorId !== null && $row['vendor_name'] !== null
                            ? (string) $row['vendor_name'] : null,
                        'locale' => (string) $matched['locale'],
                        'concept' => (string) $matched['concept_key'],
                        'phrase' => (string) $matched['phrase'],
                        'kind' => $ruleKind,
                        'account' => $ruleKind === 'fixed_asset'
                            ? null
                            : $this->activeAccountOrNull($supplierId, $suggestion->accountCode),
                        'confidence' => $suggestion->confidence,
                        'count' => 0,
                        'amount' => 0.0,
                        'samples' => [],
                    ];
                    $groups[$key]['count']++;
                    $groups[$key]['amount'] += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                    if (count($groups[$key]['samples']) < 3) {
                        $groups[$key]['samples'][] = [
                            'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                            'item_id' => (int) $row['id'],
                            'description' => mb_substr($description, 0, 180),
                            'year' => $year,
                        ];
                    }

                    if ($suggestion->kind->value === 'fixed_asset') {
                        $limit = $this->classification->assetLimitForYear($year);
                        $signature = hash('sha256', 'asset|' . $row['id'] . '|' . $year . '|' . $limit);
                        $this->setup->addProposal(
                            $runId, $supplierId, 'asset_candidate', $signature,
                            'Kandidát na dlouhodobý majetek', $suggestion->confidence, 1,
                            abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']),
                            [
                                'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                                'item_id' => (int) $row['id'],
                                'item_description' => mb_substr($description, 0, 180),
                                'expense_kind' => 'fixed_asset',
                                'target_account_code' => $fixedAssetAnalytic['account_code'] ?? null,
                                'acquisition_year' => $year,
                                'unit_price_czk' => round($unitPriceCzk, 2),
                                'fixed_asset_limit' => $limit,
                                'requires_asset_card' => true,
                            ],
                            ['reason' => $suggestion->reason, 'sample' => mb_substr($description, 0, 180), 'source' => 'catalog'],
                        );
                        $fixedAssetCount++;
                        $fixedAssetAmount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                        $created++;
                    }
                }

                $hashRows[] = [(int) $row['id'], (string) $row['updated_at'], hash('sha256', $normalized)];
                if (($index + 1) % 25 === 0 || $index + 1 === count($rows)) {
                    $this->jobs->updateProgress($jobId, ['processed' => $index + 1]);
                }
            }

            $chartProposals = [];
            foreach ($groups as &$group) {
                if ($group['count'] < 2) {
                    continue;
                }
                $analytic = $this->analyticForGroup($chart, (string) $group['concept'], (string) $group['kind']);
                if ($analytic === null) {
                    if (!$this->isAnalyticAccount($chart, $group['account'])) {
                        $group['account'] = null;
                    }
                    continue;
                }
                $group['account'] = $analytic['account_code'];
                if (!empty($analytic['create'])) {
                    $code = (string) $analytic['account_code'];
                    $chartProposals[$code] ??= $analytic;
                    $chartProposals[$code]['occurrence_count'] = (int) ($chartProposals[$code]['occurrence_count'] ?? 0) + (int) $group['count'];
                    $chartProposals[$code]['affected_amount'] = (float) ($chartProposals[$code]['affected_amount'] ?? 0) + (float) $group['amount'];
                }
            }
            unset($group);

            if ($fixedAssetCount > 0 && !empty($fixedAssetAnalytic['create'])) {
                $code = (string) $fixedAssetAnalytic['account_code'];
                $chartProposals[$code] = $fixedAssetAnalytic;
                $chartProposals[$code]['occurrence_count'] = $fixedAssetCount;
                $chartProposals[$code]['affected_amount'] = $fixedAssetAmount;
            }

            foreach ($chartProposals as $proposal) {
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'chart_account', $signature,
                    'Nová analytika ' . $proposal['account_code'] . ' - ' . $proposal['name'],
                    0.88, (int) ($proposal['occurrence_count'] ?? 0), (float) ($proposal['affected_amount'] ?? 0),
                    array_diff_key($proposal, ['occurrence_count' => true, 'affected_amount' => true, 'create' => true]),
                    ['reason' => 'flat_chart', 'parent_account_code' => $proposal['parent_account_code'], 'source' => 'catalog'],
                );
                $created++;
            }

            $aiSummary = [
                'requested' => !empty($params['use_ai']),
                'status' => 'not_requested',
                'sample_limit' => 50,
                'samples_sent' => 0,
                'requests_sent' => 0,
                'classified_items' => 0,
                'proposals' => 0,
            ];
            if (!empty($params['use_ai'])) {
                $this->jobs->updateProgress($jobId, ['current_step' => 'ai_enrichment']);
                $sampleLimit = in_array((int) ($params['ai_sample_limit'] ?? 50), [50, 100, 200], true)
                    ? (int) $params['ai_sample_limit']
                    : 50;
                $sampleSet = $this->aiSamples->build($unclassifiedRows, $sampleLimit);
                $aiResult = $this->aiEnricher->enrich($supplierId, $sampleSet['samples'], self::aiChartShape($chart));
                $aiApplied = in_array(($aiResult['status'] ?? null), ['ok', 'partial'], true)
                    ? $this->addAiRecommendations(
                        $runId,
                        $supplierId,
                        (array) ($aiResult['recommendations'] ?? []),
                        $sampleSet['rows_by_sample'],
                        $chart,
                        self::reservedAnalyticCodes($chartProposals),
                        $activeExpenseRules,
                        isset($chartProposals['042.100']),
                    )
                    : ['created' => 0, 'classified' => 0, 'kind_scorable' => 0, 'kind_agreement' => 0, 'account_scorable' => 0, 'account_agreement' => 0];
                $created += $aiApplied['created'];
                $unclassified = max(0, $unclassified - $aiApplied['classified']);
                $aiSummary = [
                    'requested' => true,
                    'status' => (string) ($aiResult['status'] ?? 'failed'),
                    'error' => $aiResult['error'] ?? null,
                    'samples_sent' => (int) ($aiResult['samples_sent'] ?? 0),
                    'sample_limit' => $sampleLimit,
                    'requests_sent' => (int) ($aiResult['requests_sent'] ?? 0),
                    'classified_items' => $aiApplied['classified'],
                    'proposals' => $aiApplied['created'],
                    'provider' => $aiResult['provider'] ?? null,
                    'model' => $aiResult['model'] ?? null,
                    'validation' => [
                        'kind_scorable' => $aiApplied['kind_scorable'],
                        'kind_agreement_pct' => $aiApplied['kind_scorable'] === 0
                            ? null : round(100 * $aiApplied['kind_agreement'] / $aiApplied['kind_scorable'], 1),
                        'account_scorable' => $aiApplied['account_scorable'],
                        'account_agreement_pct' => $aiApplied['account_scorable'] === 0
                            ? null : round(100 * $aiApplied['account_agreement'] / $aiApplied['account_scorable'], 1),
                    ],
                ];
                if (in_array(($aiResult['status'] ?? null), ['failed', 'partial'], true)) {
                    $this->jobs->appendLog($jobId, 'AI doplnění bylo přeskočeno: ' . (string) ($aiResult['error'] ?? 'unknown'));
                }
            }

            foreach ($groups as $group) {
                if ($group['count'] < 2) {
                    continue;
                }
                if (!$this->isAnalyticAccount($chart, $group['account'])
                    && !isset($chartProposals[(string) $group['account']])) {
                    continue;
                }
                $proposal = [
                    'name' => trim(($group['vendor_name'] ?? self::expenseKindLabel((string) $group['kind'])) . ' - ' . $group['phrase']),
                    'vendor_client_id' => $group['vendor_id'],
                    'vendor_name_contains' => null,
                    'description_contains' => $group['phrase'],
                    'expense_kind' => $group['kind'],
                    'target_account_code' => $group['account'],
                    'application_mode' => 'suggest',
                    'priority' => 100,
                    'is_active' => true,
                    'locale' => $group['locale'],
                ];
                if ($this->hasEquivalentExpenseRule($activeExpenseRules, $proposal)) {
                    continue;
                }
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'expense_rule', $signature,
                    $proposal['name'], $group['confidence'], $group['count'], $group['amount'],
                    $proposal,
                    ['samples' => $group['samples'], 'concept' => $group['concept'], 'source' => 'catalog'],
                );
                $created++;
            }

            $postingTargets = $this->postingTargets($chart, $groups);
            foreach ($postingTargets as $kindValue => $targetAccount) {
                $kind = ExpenseKind::tryFrom($kindValue);
                if ($kind === null) {
                    continue;
                }
                $ruleKey = $kind->ruleKey();
                $current = $this->postingRules->resolve($supplierId, $ruleKey);
                $credit = (string) ($current['credit_account_code'] ?? '321');
                if ((string) ($current['debit_account_code'] ?? '') === $targetAccount && $credit === '321') {
                    continue;
                }
                $proposal = [
                    'rule_key' => $ruleKey,
                    'description' => 'Přijatá faktura - ' . self::expenseKindLabel($kindValue)
                        . ' (' . $targetAccount . '/321)',
                    'debit_account_code' => $targetAccount,
                    'credit_account_code' => '321',
                ];
                $this->setup->addProposal(
                    $runId, $supplierId, 'posting_rule', hash('sha256', self::canonicalJson($proposal)),
                    'Předkontace pro ' . self::expenseKindLabel($kindValue) . ' na ' . $targetAccount . '/321',
                    0.88, 0, 0.0, $proposal,
                    ['reason' => 'analytic_default', 'expense_kind' => $kindValue, 'source' => 'catalog'],
                );
                $created++;
            }

            $this->jobs->updateProgress($jobId, ['current_step' => 'bank_rules']);
            $bank = $this->bankProposals->analyze($supplierId, (int) ($params['months_back'] ?? 60), true);
            foreach ((array) ($bank['clusters'] ?? []) as $cluster) {
                $proposal = (array) ($cluster['proposal'] ?? []);
                if (($proposal['debit_account_code'] ?? null) === null || ($proposal['credit_account_code'] ?? null) === null) {
                    continue;
                }
                $signature = hash('sha256', self::canonicalJson($proposal));
                $this->setup->addProposal(
                    $runId, $supplierId, 'bank_rule', $signature,
                    (string) ($proposal['name'] ?? 'Bankovní pravidlo'), 0.9,
                    (int) ($cluster['tx_count'] ?? 0), 0.0, $proposal,
                    ['first_seen' => $cluster['first_seen'] ?? null, 'last_seen' => $cluster['last_seen'] ?? null, 'source' => 'history'],
                );
                $created++;
            }

            if ($unclassified > 0) {
                $this->setup->addProposal(
                    $runId, $supplierId, 'data_quality', hash('sha256', 'unclassified|' . $unclassified),
                    'Položky bez spolehlivé klasifikace', 0.0, $unclassified, 0.0,
                    ['code' => 'unclassified_items'], ['count' => $unclassified, 'source' => 'history'],
                );
                $created++;
            }

            $summary = [
                'documents' => count(array_unique(array_column($rows, 'purchase_invoice_id'))),
                'items' => count($rows),
                'proposals' => $created,
                'unclassified' => $unclassified,
                'classification_coverage_pct' => self::coveragePct(count($rows), $unclassified),
                'catalog_version' => $catalogVersion,
                'catalog_locales' => ['cs', 'sk', 'de', 'en'],
                'ai' => $aiSummary,
                'validation' => [
                    'kind_scorable' => $storedScorable,
                    'kind_agreement_pct' => $storedScorable === 0 ? null : round(100 * $storedAgreement / $storedScorable, 1),
                    'account_scorable' => $accountScorable,
                    'account_agreement_pct' => $accountScorable === 0 ? null : round(100 * $accountAgreement / $accountScorable, 1),
                ],
                'locked_period_documents' => $this->lockedDocumentCount($supplierId, $params),
            ];
            $this->setup->completeRun(
                $runId,
                hash('sha256', self::canonicalJson($hashRows)),
                $this->tableHash($supplierId, 'chart_of_accounts', ['account_code', 'account_type', 'is_active']),
                $this->tableHash($supplierId, 'expense_classification_rules', ['id', 'updated_at']),
                $summary,
            );
            $this->jobs->updateProgress($jobId, ['processed' => count($rows), 'created_count' => $created, 'current_step' => 'completed']);
            $this->jobs->markCompleted($jobId);
        } catch (\Throwable $e) {
            $this->jobs->appendLog($jobId, 'Analýza selhala: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    private function items(int $supplierId, array $params): array
    {
        $where = ['pi.supplier_id = ?', "pi.status NOT IN ('draft','cancelled')", "pi.document_kind NOT IN ('advance','tax_document')"];
        $bind = [$supplierId];
        if (!empty($params['date_from'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $bind[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = 'COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $bind[] = $params['date_to'];
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT pii.id, pii.purchase_invoice_id, pii.description, pii.unit_price_without_vat,
                    pii.total_without_vat, pii.expense_kind, pi.updated_at,
                    pi.vendor_id, pi.exchange_rate, c.company_name vendor_name,
                    YEAR(COALESCE(pi.tax_date, pi.issue_date)) acq_year,
                    (SELECT CASE WHEN COUNT(DISTINCT coa.account_code) = 1 THEN MIN(coa.account_code) END
                       FROM journal_entries je
                       JOIN journal_entry_lines jel ON jel.entry_id = je.id AND jel.supplier_id = je.supplier_id
                       JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = je.supplier_id
                      WHERE je.supplier_id = pi.supplier_id AND je.source_type = 'purchase_invoice'
                        AND je.source_id = pi.id AND je.reversed_by IS NULL
                        AND (coa.account_code LIKE '5%' OR coa.account_code LIKE '04%')) historical_account
               FROM purchase_invoice_items pii
               JOIN purchase_invoices pi ON pi.id = pii.purchase_invoice_id
               LEFT JOIN clients c ON c.id = pi.vendor_id AND c.supplier_id = pi.supplier_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY pii.id'
        );
        $stmt->execute($bind);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lockedDocumentCount(int $supplierId, array $params): int
    {
        $sql = "SELECT COUNT(DISTINCT pi.id)
                  FROM purchase_invoices pi
                  JOIN accounting_periods ap ON ap.supplier_id = pi.supplier_id
                   AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ap.starts_on AND ap.ends_on
                  LEFT JOIN accounting_supplier_settings aset ON aset.supplier_id = pi.supplier_id
                 WHERE pi.supplier_id = ?
                   AND (ap.status <> 'open' OR COALESCE(pi.tax_date, pi.issue_date) <= aset.locked_until)";
        $bind = [$supplierId];
        if (!empty($params['date_from'])) {
            $sql .= ' AND COALESCE(pi.tax_date, pi.issue_date) >= ?';
            $bind[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $sql .= ' AND COALESCE(pi.tax_date, pi.issue_date) <= ?';
            $bind[] = $params['date_to'];
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($bind);
        return (int) $stmt->fetchColumn();
    }

    private function tableHash(int $supplierId, string $table, array $columns): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ' . implode(',', $columns) . " FROM {$table} WHERE supplier_id = ? ORDER BY id");
        $stmt->execute([$supplierId]);
        return hash('sha256', self::canonicalJson($stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * @param list<array<string,mixed>> $recommendations
     * @param array<string,list<array<string,mixed>>> $rowsBySample
     * @param array<string,bool> $reservedCodes
     * @return array{created:int,classified:int,kind_scorable:int,kind_agreement:int,account_scorable:int,account_agreement:int}
     */
    private function addAiRecommendations(
        int $runId,
        int $supplierId,
        array $recommendations,
        array $rowsBySample,
        array $chart,
        array $reservedCodes,
        array $activeExpenseRules,
        bool $fixedAssetAnalyticAlreadyProposed,
    ): array {
        $created = 0;
        $classifiedIds = [];
        $kindScorable = 0;
        $kindAgreement = 0;
        $accountScorable = 0;
        $accountAgreement = 0;
        $analytics = [];
        $fixedAssetAnalytic = $this->analyticForGroup($chart, 'fixed_asset', 'fixed_asset');
        $fixedAssetCount = 0;
        $fixedAssetAmount = 0.0;
        $fixedAssetConfidence = 0.0;

        foreach ($recommendations as $recommendation) {
            $nature = self::correctAiNature(
                (string) ($recommendation['nature'] ?? ''),
                (string) ($recommendation['keyword'] ?? ''),
                (string) ($recommendation['analytic_name'] ?? ''),
            );
            $kind = self::kindForAiNature($nature);
            $parentCode = self::parentForAiNature($nature);
            if ($kind === null || $parentCode === null) {
                continue;
            }
            $rows = [];
            foreach ((array) ($recommendation['sample_ids'] ?? []) as $sampleId) {
                foreach ($rowsBySample[(string) $sampleId] ?? [] as $row) {
                    $rows[(int) $row['id']] = $row;
                }
            }
            if (count($rows) < 2) {
                continue;
            }

            $analyticName = trim((string) ($recommendation['analytic_name'] ?? ''));
            $analyticKey = $parentCode . '|' . mb_strtolower($analyticName);
            $analytic = $analytics[$analyticKey] ?? null;
            if (!array_key_exists($analyticKey, $analytics)) {
                $analytic = $this->nextAiAnalytic($chart, $parentCode, $analyticName, $reservedCodes);
                $analytics[$analyticKey] = $analytic;
                if ($analytic !== null && !empty($analytic['create'])) {
                    $analyticProposal = array_diff_key($analytic, ['create' => true]);
                    $this->setup->addProposal(
                        $runId,
                        $supplierId,
                        'chart_account',
                        hash('sha256', self::canonicalJson($analyticProposal)),
                        'Nová analytika ' . $analytic['account_code'] . ' - ' . $analytic['name'],
                        (float) $recommendation['confidence'],
                        count($rows),
                        self::rowsAmount($rows),
                        $analyticProposal,
                        ['reason' => 'ai_flat_chart', 'source' => 'ai'],
                    );
                    $created++;
                }
            }
            $targetAccount = $analytic['account_code'] ?? null;
            if ($targetAccount === null) {
                continue;
            }

            $keyword = trim((string) ($recommendation['keyword'] ?? ''));
            $proposal = [
                'name' => 'AI - ' . $analyticName,
                'vendor_client_id' => null,
                'vendor_name_contains' => null,
                'description_contains' => $keyword,
                'expense_kind' => $kind->value,
                'target_account_code' => $targetAccount,
                'application_mode' => 'suggest',
                'priority' => 90,
                'is_active' => true,
                'locale' => 'multi',
            ];
            if ($this->hasEquivalentExpenseRule($activeExpenseRules, $proposal)) {
                continue;
            }
            $this->setup->addProposal(
                $runId,
                $supplierId,
                'expense_rule',
                hash('sha256', self::canonicalJson($proposal)),
                $proposal['name'],
                (float) $recommendation['confidence'],
                count($rows),
                self::rowsAmount($rows),
                $proposal,
                ['source' => 'ai', 'nature' => $nature, 'sample_count' => count($recommendation['sample_ids'])],
            );
            $created++;

            foreach ($rows as $row) {
                $rowId = (int) $row['id'];
                $classifiedIds[$rowId] = true;
                $year = (int) $row['acq_year'];
                $unitPriceCzk = abs((float) $row['unit_price_without_vat']) * self::fxRate($row['exchange_rate']);
                $effectiveKind = $kind;
                $effectiveAccount = $targetAccount;
                if ($nature === 'tangible_asset'
                    && self::isAboveFixedAssetLimit($unitPriceCzk, $this->classification->assetLimitForYear($year))) {
                    $effectiveKind = ExpenseKind::FixedAsset;
                    $effectiveAccount = (string) ($fixedAssetAnalytic['account_code'] ?? '042');
                    $limit = $this->classification->assetLimitForYear($year);
                    $asset = [
                        'purchase_invoice_id' => (int) $row['purchase_invoice_id'],
                        'item_id' => $rowId,
                        'item_description' => mb_substr((string) $row['description'], 0, 180),
                        'expense_kind' => 'fixed_asset',
                        'target_account_code' => $fixedAssetAnalytic['account_code'] ?? null,
                        'acquisition_year' => $year,
                        'unit_price_czk' => round($unitPriceCzk, 2),
                        'fixed_asset_limit' => $limit,
                        'requires_asset_card' => true,
                    ];
                    $this->setup->addProposal(
                        $runId,
                        $supplierId,
                        'asset_candidate',
                        hash('sha256', 'ai-asset|' . $rowId . '|' . $year . '|' . $limit),
                        'Kandidát na dlouhodobý majetek',
                        (float) $recommendation['confidence'],
                        1,
                        abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']),
                        $asset,
                        ['source' => 'ai', 'nature' => $nature],
                    );
                    $fixedAssetCount++;
                    $fixedAssetAmount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
                    $fixedAssetConfidence = max($fixedAssetConfidence, (float) $recommendation['confidence']);
                    $created++;
                }
                if ($row['expense_kind'] !== null) {
                    $kindScorable++;
                    if ((string) $row['expense_kind'] === $effectiveKind->value) {
                        $kindAgreement++;
                    }
                }
                if ($row['historical_account'] !== null) {
                    $accountScorable++;
                    if (self::sameSynthetic($effectiveAccount, (string) $row['historical_account'])) {
                        $accountAgreement++;
                    }
                }
            }
        }

        if ($fixedAssetCount > 0 && !empty($fixedAssetAnalytic['create']) && !$fixedAssetAnalyticAlreadyProposed) {
            $analyticProposal = array_diff_key($fixedAssetAnalytic, [
                'occurrence_count' => true,
                'affected_amount' => true,
                'create' => true,
            ]);
            $this->setup->addProposal(
                $runId,
                $supplierId,
                'chart_account',
                hash('sha256', self::canonicalJson($analyticProposal)),
                'Nová analytika ' . $fixedAssetAnalytic['account_code'] . ' - ' . $fixedAssetAnalytic['name'],
                $fixedAssetConfidence,
                $fixedAssetCount,
                $fixedAssetAmount,
                $analyticProposal,
                ['reason' => 'ai_fixed_asset', 'source' => 'ai'],
            );
            $created++;
        }

        return [
            'created' => $created,
            'classified' => count($classifiedIds),
            'kind_scorable' => $kindScorable,
            'kind_agreement' => $kindAgreement,
            'account_scorable' => $accountScorable,
            'account_agreement' => $accountAgreement,
        ];
    }

    /** @param list<array<string,mixed>> $activeRules @param array<string,mixed> $proposal */
    private function hasEquivalentExpenseRule(array $activeRules, array $proposal): bool
    {
        foreach ($activeRules as $existing) {
            if (AccountingRuleEquivalence::expense($existing, $proposal)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,bool> $reservedCodes */
    private function nextAiAnalytic(array $chart, string $parentCode, string $name, array &$reservedCodes): ?array
    {
        $parent = $chart['by_code'][$parentCode] ?? null;
        if ($parent === null || empty($parent['is_active']) || empty($parent['is_synthetic']) || $name === '') {
            return null;
        }
        $normalizedName = BankMessageNormalizer::normalizeKeepDigits($name);
        foreach ($chart['by_code'] as $account) {
            if (!empty($account['is_active']) && empty($account['is_synthetic'])
                && (int) ($account['parent_id'] ?? 0) === (int) $parent['id']
                && BankMessageNormalizer::normalizeKeepDigits((string) ($account['name'] ?? '')) === $normalizedName
            ) {
                return ['account_code' => (string) $account['account_code'], 'create' => false];
            }
        }
        for ($suffix = 100; $suffix <= 999; $suffix++) {
            $code = $parentCode . '.' . str_pad((string) $suffix, 3, '0', STR_PAD_LEFT);
            if (isset($chart['by_code'][$code]) || isset($reservedCodes[$code])) {
                continue;
            }
            $reservedCodes[$code] = true;
            return [
                'account_code' => $code,
                'name' => mb_substr($name, 0, 160),
                'parent_account_code' => $parentCode,
                'account_type' => (string) $parent['account_type'],
                'normal_side' => $parent['normal_side'],
                'is_synthetic' => false,
                'is_active' => true,
                'create' => true,
            ];
        }
        return null;
    }

    private static function kindForAiNature(string $nature): ?ExpenseKind
    {
        return match ($nature) {
            'service', 'repair', 'insurance' => ExpenseKind::Service,
            'material', 'energy', 'fuel' => ExpenseKind::Material,
            'tangible_asset' => ExpenseKind::SmallAsset,
            'intangible_asset' => ExpenseKind::SmallIntangible,
            default => null,
        };
    }

    private static function parentForAiNature(string $nature): ?string
    {
        return match ($nature) {
            'material', 'fuel', 'tangible_asset' => '501',
            'energy' => '502',
            'service', 'intangible_asset' => '518',
            'repair' => '511',
            'insurance' => '548',
            default => null,
        };
    }

    private static function correctAiNature(string $nature, string $keyword, string $analyticName): string
    {
        if (!in_array($nature, ['material', 'service'], true)) {
            return $nature;
        }
        $text = BankMessageNormalizer::normalizeKeepDigits($keyword . ' ' . $analyticName);
        foreach ([
            'elektr', 'spotreba energie', 'energy consumption', 'utility bill',
            'zemni plyn', 'spotreba plynu', 'dodavka plynu', 'natural gas', 'gas supply', 'gasverbrauch', 'erdgas',
            'teplo', 'tepelna energie', 'heat supply', 'district heating', 'fernwarme', 'waermeversorgung',
            'vodne', 'stocne', 'dodavka vody', 'water supply', 'water and sewer', 'wasser', 'abwasser',
        ] as $energyTerm) {
            if (str_contains($text, $energyTerm)) {
                return 'energy';
            }
        }
        return $nature;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function rowsAmount(array $rows): float
    {
        $amount = 0.0;
        foreach ($rows as $row) {
            $amount += abs((float) $row['total_without_vat']) * self::fxRate($row['exchange_rate']);
        }
        return $amount;
    }

    /** @return list<array{code:string,is_synthetic:bool,analytic_count:int}> */
    private static function aiChartShape(array $chart): array
    {
        $shape = [];
        foreach (['501', '502', '511', '518', '548', '042'] as $code) {
            $account = $chart['by_code'][$code] ?? null;
            if ($account === null || empty($account['is_active'])) {
                continue;
            }
            $shape[] = [
                'code' => $code,
                'is_synthetic' => (bool) $account['is_synthetic'],
                'analytic_count' => (int) ($chart['children'][(int) $account['id']] ?? 0),
            ];
        }
        return $shape;
    }

    private function activeAccountOrNull(int $supplierId, ?string $accountCode): ?string
    {
        if ($accountCode === null || $accountCode === '') {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM chart_of_accounts WHERE supplier_id = ? AND account_code = ? AND is_active = 1'
        );
        $stmt->execute([$supplierId, $accountCode]);
        return $stmt->fetchColumn() === false ? null : $accountCode;
    }

    /** @return array{by_code:array<string,array<string,mixed>>,children:array<int,int>} */
    private function chartState(int $supplierId): array
    {
        $byCode = [];
        $children = [];
        foreach ($this->chart->listForTenant($supplierId, true) as $account) {
            $byCode[(string) $account['account_code']] = $account;
            if ($account['parent_id'] !== null && $account['is_active']) {
                $children[(int) $account['parent_id']] = ($children[(int) $account['parent_id']] ?? 0) + 1;
            }
        }
        return ['by_code' => $byCode, 'children' => $children];
    }

    /** @return array<string,mixed>|null */
    private function analyticForGroup(array $chart, string $concept, string $kind): ?array
    {
        $templates = self::analyticTemplates();
        $template = $templates[$concept] ?? ($templates[$kind] ?? null);
        if ($template === null) {
            return null;
        }
        [$code, $parentCode, $name] = $template;
        $parent = $chart['by_code'][$parentCode] ?? null;
        if ($parent === null || empty($parent['is_active']) || empty($parent['is_synthetic'])) {
            return null;
        }
        $existing = $chart['by_code'][$code] ?? null;
        if ($existing !== null) {
            return !empty($existing['is_active']) && empty($existing['is_synthetic'])
                && (int) ($existing['parent_id'] ?? 0) === (int) $parent['id']
                ? ['account_code' => $code, 'create' => false]
                : null;
        }
        return [
            'account_code' => $code,
            'name' => $name,
            'parent_account_code' => $parentCode,
            'account_type' => (string) $parent['account_type'],
            'normal_side' => $parent['normal_side'],
            'is_synthetic' => false,
            'is_active' => true,
            'create' => true,
            'occurrence_count' => 0,
            'affected_amount' => 0.0,
        ];
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    private static function analyticTemplates(): array
    {
        return [
            'fuel' => ['501.100', '501', 'Pohonné hmoty'],
            'small_asset' => ['501.200', '501', 'Drobný majetek'],
            'material' => ['501.900', '501', 'Ostatní materiál'],
            'energy' => ['502.100', '502', 'Spotřeba energie'],
            'vehicle_repair' => ['511.100', '511', 'Opravy vozidel'],
            'repair' => ['511.900', '511', 'Ostatní opravy a údržba'],
            'insurance' => ['548.100', '548', 'Pojištění'],
            'service' => ['518.100', '518', 'Ostatní služby'],
            'small_intangible' => ['518.200', '518', 'Drobný nehmotný majetek'],
            'fixed_asset' => ['042.100', '042', 'Pořízení DHM'],
        ];
    }

    private function isAnalyticAccount(array $chart, mixed $accountCode): bool
    {
        $code = trim((string) $accountCode);
        $account = $code === '' ? null : ($chart['by_code'][$code] ?? null);
        return $account !== null && !empty($account['is_active']) && empty($account['is_synthetic']);
    }

    /** @param array<string,array<string,mixed>> $chartProposals @return array<string,bool> */
    private static function reservedAnalyticCodes(array $chartProposals): array
    {
        $codes = array_fill_keys(array_keys($chartProposals), true);
        foreach (self::analyticTemplates() as [$code]) {
            $codes[$code] = true;
        }
        return $codes;
    }

    /** @return array<string,string> expense_kind => account_code */
    private function postingTargets(array $chart, array $groups): array
    {
        $preferredConcept = [
            'material' => 'material',
            'small_asset' => 'small_asset',
            'service' => 'service',
            'fixed_asset' => 'fixed_asset',
        ];
        $observedConcepts = [];
        foreach ($groups as $group) {
            if ((int) $group['count'] >= 2) {
                $observedConcepts[(string) $group['concept']] = true;
            }
        }
        $out = [];
        foreach ($preferredConcept as $kind => $concept) {
            if (!isset($observedConcepts[$concept])) {
                continue;
            }
            $analytic = $this->analyticForGroup($chart, $concept, $kind);
            if ($analytic !== null) {
                $out[$kind] = (string) $analytic['account_code'];
            }
        }
        return $out;
    }

    private static function matchCatalog(string $text, array $catalog): ?array
    {
        $veto = [];
        foreach ($catalog as $entry) {
            $phrase = BankMessageNormalizer::normalizeKeepDigits((string) ($entry['phrase'] ?? ''));
            if (($entry['polarity'] ?? '') === 'veto' && self::contains($text, $phrase)) {
                $veto[(string) $entry['concept_key']] = true;
            }
        }
        foreach ($catalog as $entry) {
            if (($entry['polarity'] ?? '') !== 'positive') {
                continue;
            }
            $phrase = BankMessageNormalizer::normalizeKeepDigits((string) ($entry['phrase'] ?? ''));
            if (!self::contains($text, $phrase)) {
                continue;
            }
            if (($entry['expense_kind'] ?? '') === 'small_asset' && isset($veto['asset_veto'])) {
                continue;
            }
            if (($entry['concept_key'] ?? '') === 'fuel' && isset($veto['fuel_veto'])) {
                continue;
            }
            return $entry;
        }
        return null;
    }

    private static function contains(string $text, string $phrase): bool
    {
        return $phrase !== '' && preg_match('/(?:^| )' . preg_quote($phrase, '/') . '(?= |$)/', $text) === 1;
    }

    private static function sameSynthetic(string $a, string $b): bool
    {
        return substr(str_replace('.', '', $a), 0, 3) === substr(str_replace('.', '', $b), 0, 3);
    }

    private static function fxRate(mixed $rate): float
    {
        $value = (float) $rate;
        return $value > 0 ? $value : 1.0;
    }

    private static function baseRuleKind(array $catalogMatch, string $suggestedKind): string
    {
        $catalogKind = (string) ($catalogMatch['expense_kind'] ?? '');
        return ExpenseKind::tryFrom($catalogKind) !== null ? $catalogKind : $suggestedKind;
    }

    private static function isAboveFixedAssetLimit(float $unitPriceCzk, float $limit): bool
    {
        return $unitPriceCzk > $limit;
    }

    private static function groupingVendorId(string $kind, mixed $vendorId): ?int
    {
        if (in_array($kind, ['small_asset', 'small_intangible'], true)) {
            return null;
        }
        return $vendorId !== null ? (int) $vendorId : null;
    }

    private static function coveragePct(int $items, int $unclassified): float
    {
        if ($items <= 0) {
            return 0.0;
        }
        return round(100 * max(0, $items - max(0, $unclassified)) / $items, 1);
    }

    private static function expenseKindLabel(string $kind): string
    {
        return match ($kind) {
            'service' => 'služby',
            'material' => 'materiál, energie a PHM',
            'small_asset' => 'drobný hmotný majetek',
            'small_intangible' => 'drobný nehmotný majetek',
            'fixed_asset' => 'dlouhodobý majetek',
            default => 'ostatní náklady',
        };
    }

    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
