<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Repository\AccountingSetupRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Repository\ExpenseClassificationRuleRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Repository\PostingRuleRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\Setup\AccountingHistoryReclassificationService;
use MyInvoice\Service\Accounting\Setup\AccountingSetupApprovalService;
use MyInvoice\Service\Accounting\Setup\AccountingSetupAnalysisService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class AccountingSetupApprovalServiceTest extends BankPostingTestCase
{
    public function testPendingProposalCanBeEditedOnlyBeforeApprovalAndWithinTenant(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $otherSupplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);

        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'editable'), 'Původní pravidlo', 0.9, 3, 3000, [
            'name' => 'Původní pravidlo',
            'description_contains' => 'puvodni text',
            'expense_kind' => 'service',
            'target_account_code' => '501.900',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], []);
        $proposal = $setup->proposals($supplierId, $runId)[0];

        self::assertFalse($setup->updatePendingProposal(
            $otherSupplierId,
            $runId,
            (int) $proposal['id'],
            'Cizí změna',
            ['name' => 'Cizí změna'],
        ));
        self::assertTrue($setup->updatePendingProposal(
            $supplierId,
            $runId,
            (int) $proposal['id'],
            'Upravené pravidlo',
            array_merge($proposal['proposal_json'], [
                'name' => 'Upravené pravidlo',
                'description_contains' => 'upraveny text',
            ]),
        ));
        $updated = $setup->proposals($supplierId, $runId)[0];
        self::assertSame('Upravené pravidlo', $updated['title']);
        self::assertSame('upraveny text', $updated['proposal_json']['description_contains']);

        $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [(int) $proposal['id']], $this->userId);
        self::assertFalse($setup->updatePendingProposal(
            $supplierId,
            $runId,
            (int) $proposal['id'],
            'Pozdní změna',
            array_merge($updated['proposal_json'], ['name' => 'Pozdní změna']),
        ));
    }

    public function testApprovalCreatesAnalyticBeforeDependentRules(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $chart = $this->container->get(ChartOfAccountsRepository::class);
        $postingRules = $this->container->get(PostingRuleRepository::class);
        $expenseRules = $this->container->get(ExpenseClassificationRuleRepository::class);

        $existingAnalytic = $chart->findByCode($supplierId, '548.990');
        if ($existingAnalytic !== null) {
            self::assertTrue($chart->delete($supplierId, (int) $existingAnalytic['id']));
        }

        self::assertNull($chart->findByCode($supplierId, '548.100'));
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);

        $setup->addProposal($runId, $supplierId, 'posting_rule', hash('sha256', 'posting'), 'Předkontace', 0.9, 2, 0, [
            'rule_key' => 'invoice.services.received',
            'description' => 'Testovací předkontace',
            'debit_account_code' => '548.100',
            'credit_account_code' => '321',
        ], []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'expense'), 'Pojištění', 0.9, 2, 2000, [
            'name' => 'Pojištění',
            'description_contains' => 'pojisteni',
            'expense_kind' => 'service',
            'target_account_code' => '548.100',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], []);
        $setup->addProposal($runId, $supplierId, 'chart_account', hash('sha256', 'chart'), 'Analytika 548.100', 0.9, 2, 2000, [
            'account_code' => '548.100',
            'name' => 'Pojištění',
            'parent_account_code' => '548',
            'account_type' => 'expense',
            'normal_side' => 'debit',
            'is_synthetic' => false,
            'is_active' => true,
        ], []);

        $ids = array_column($setup->proposals($supplierId, $runId), 'id');
        $result = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, $ids, $this->userId);

        self::assertSame(1, $result['chart_accounts_created']);
        self::assertSame(1, $result['posting_rules_created']);
        self::assertSame(1, $result['expense_rules_created']);
        $parent = $chart->findByCode($supplierId, '548');
        $analytic = $chart->findByCode($supplierId, '548.100');
        self::assertNotNull($parent);
        self::assertNotNull($analytic);
        self::assertSame($parent['id'], $analytic['parent_id']);
        self::assertSame('548.100', $postingRules->resolve($supplierId, 'invoice.services.received')['debit_account_code']);
        self::assertSame('548.100', $expenseRules->activeFor($supplierId)[0]['target_account_code']);
        self::assertSame('suggest', $expenseRules->activeFor($supplierId)[0]['application_mode']);
    }

    public function testNewBundleIncludesPreviouslyActiveExpenseRules(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $expenseRules = $this->container->get(ExpenseClassificationRuleRepository::class);
        $existingId = $expenseRules->insert($supplierId, [
            'name' => 'Existující pravidlo drobného majetku',
            'description_contains' => 'synteticky notebook',
            'expense_kind' => 'small_asset',
            'target_account_code' => '501.900',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], $this->userId);

        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'new-rule'), 'Nové pravidlo', 0.9, 2, 2000, [
            'name' => 'Nové pravidlo',
            'description_contains' => 'synteticky material',
            'expense_kind' => 'material',
            'target_account_code' => '501.900',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], []);
        $proposalId = (int) $setup->proposals($supplierId, $runId)[0]['id'];

        $bundle = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [$proposalId], $this->userId);
        $rules = array_values(array_filter(
            $bundle['payload'],
            static fn (array $item): bool => ($item['type'] ?? null) === 'expense_rule',
        ));

        self::assertCount(2, $rules);
        self::assertNotEmpty(array_filter(
            $rules,
            static fn (array $item): bool => ($item['source'] ?? null) === 'existing'
                && (int) ($item['proposal']['id'] ?? 0) === $existingId,
        ));
    }

    public function testBundleCanBeCreatedFromActiveRulesWithoutNewProposal(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $this->container->get(ExpenseClassificationRuleRepository::class)->insert($supplierId, [
            'name' => 'Existující pravidlo služby',
            'description_contains' => 'synteticka sluzba',
            'expense_kind' => 'service',
            'target_account_code' => '501.900',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], $this->userId);

        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);

        $bundle = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [], $this->userId);

        self::assertCount(1, $bundle['payload']);
        self::assertSame('existing', $bundle['payload'][0]['source']);
        self::assertSame('expense_rule', $bundle['payload'][0]['type']);
    }

    public function testApprovalRejectsExpenseRuleWithoutAnalyticAccount(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'synthetic-target'), 'Neplatný cíl', 0.9, 2, 1000, [
            'name' => 'Neplatný cíl',
            'description_contains' => 'synteticky vzorek',
            'expense_kind' => 'material',
            'target_account_code' => '501',
        ], []);

        $proposal = $setup->proposals($supplierId, $runId)[0];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('target_account_not_analytic');
        $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [(int) $proposal['id']], $this->userId);
    }

    public function testApprovalRejectsReceivedInvoicePostingRuleWithoutAnalyticExpenseAccount(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'posting_rule', hash('sha256', 'synthetic-posting-target'), 'Neplatná předkontace', 0.9, 2, 1000, [
            'rule_key' => 'invoice.services.received',
            'description' => 'Neplatná předkontace',
            'debit_account_code' => '518',
            'credit_account_code' => '321',
        ], []);

        $proposal = $setup->proposals($supplierId, $runId)[0];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('target_account_not_analytic');
        $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [(int) $proposal['id']], $this->userId);
    }

    public function testProposedAnalyticCanRedirectAllPendingDependenciesToExistingAccount(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $chartRepository = $this->container->get(ChartOfAccountsRepository::class);
        $replacementParent = $chartRepository->findByCode($supplierId, '518');
        self::assertNotNull($replacementParent);
        if ($chartRepository->findByCode($supplierId, '518.100') === null) {
            $chartRepository->insert($supplierId, [
                'account_code' => '518.100',
                'name' => 'Testovací služby',
                'account_type' => 'expense',
                'normal_side' => 'debit',
                'is_synthetic' => false,
                'parent_id' => (int) $replacementParent['id'],
                'is_active' => true,
            ]);
        }

        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $jobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'chart_account', hash('sha256', 'redirect-chart'), 'Analytika 501.101', 0.9, 3, 3000, [
            'account_code' => '501.101',
            'name' => 'Testovací analytika',
            'parent_account_code' => '501',
            'account_type' => 'expense',
            'normal_side' => 'debit',
            'is_synthetic' => false,
            'is_active' => true,
        ], []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'redirect-expense'), 'Testovací náklad', 0.9, 3, 3000, [
            'name' => 'Testovací náklad',
            'description_contains' => 'synteticky vzorek',
            'expense_kind' => 'material',
            'target_account_code' => '501.101',
        ], []);
        $setup->addProposal($runId, $supplierId, 'posting_rule', hash('sha256', 'redirect-posting'), 'Testovací předkontace', 0.9, 3, 0, [
            'rule_key' => 'invoice.material.received',
            'description' => 'Testovací předkontace',
            'debit_account_code' => '501.101',
            'credit_account_code' => '321',
        ], []);
        $setup->addProposal($runId, $supplierId, 'bank_rule', hash('sha256', 'redirect-bank'), 'Testovací bankovní pravidlo', 0.9, 3, 0, [
            'name' => 'Testovací bankovní pravidlo',
            'debit_account_code' => '221',
            'credit_account_code' => '501.101',
        ], []);

        $rows = $setup->proposals($supplierId, $runId);
        $chart = array_values(array_filter($rows, static fn (array $row): bool => $row['proposal_type'] === 'chart_account'))[0];
        self::assertTrue($setup->updatePendingChartProposal(
            $supplierId,
            $runId,
            (int) $chart['id'],
            'Přejmenovaná analytika 501.101',
            array_merge($chart['proposal_json'], [
                'name' => 'Přejmenovaná testovací analytika',
                'create' => true,
                'replacement_account_code' => null,
            ]),
        ));
        $chart = array_values(array_filter(
            $setup->proposals($supplierId, $runId),
            static fn (array $row): bool => $row['proposal_type'] === 'chart_account',
        ))[0];
        self::assertTrue($setup->updatePendingChartProposal(
            $supplierId,
            $runId,
            (int) $chart['id'],
            'Použít existující účet 518.100',
            array_merge($chart['proposal_json'], [
                'create' => false,
                'replacement_account_code' => '518.100',
            ]),
        ));

        $redirected = $setup->proposals($supplierId, $runId);
        $byType = [];
        foreach ($redirected as $row) {
            $byType[$row['proposal_type']] = $row;
        }
        self::assertFalse($byType['chart_account']['proposal_json']['create']);
        self::assertSame('518.100', $byType['chart_account']['proposal_json']['replacement_account_code']);
        self::assertSame('518.100', $byType['expense_rule']['proposal_json']['target_account_code']);
        self::assertSame('518.100', $byType['posting_rule']['proposal_json']['debit_account_code']);
        self::assertSame('518.100', $byType['bank_rule']['proposal_json']['credit_account_code']);

        self::assertTrue($setup->updatePendingChartProposal(
            $supplierId,
            $runId,
            (int) $chart['id'],
            'Analytika 501.101',
            array_merge($byType['chart_account']['proposal_json'], [
                'create' => true,
                'replacement_account_code' => null,
            ]),
        ));
        $restored = $setup->proposals($supplierId, $runId);
        foreach ($restored as $row) {
            if ($row['proposal_type'] === 'expense_rule') {
                self::assertSame('501.101', $row['proposal_json']['target_account_code']);
            } elseif ($row['proposal_type'] === 'posting_rule') {
                self::assertSame('501.101', $row['proposal_json']['debit_account_code']);
            } elseif ($row['proposal_type'] === 'bank_rule') {
                self::assertSame('501.101', $row['proposal_json']['credit_account_code']);
            }
        }

        $chart = array_values(array_filter($restored, static fn (array $row): bool => $row['proposal_type'] === 'chart_account'))[0];
        self::assertTrue($setup->updatePendingChartProposal(
            $supplierId,
            $runId,
            (int) $chart['id'],
            'Použít existující účet 518.100',
            array_merge($chart['proposal_json'], [
                'create' => false,
                'replacement_account_code' => '518.100',
            ]),
        ));
        $selected = array_column(array_filter(
            $setup->proposals($supplierId, $runId),
            static fn (array $row): bool => $row['proposal_type'] !== 'bank_rule',
        ), 'id');
        $approved = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, $selected, $this->userId);
        self::assertSame(0, $approved['chart_accounts_created']);
        self::assertNull($chartRepository->findByCode($supplierId, '501.101'));
        self::assertSame('518.100', $this->container->get(ExpenseClassificationRuleRepository::class)
            ->activeFor($supplierId)[0]['target_account_code']);
    }

    public function testRepeatedApprovalDoesNotDuplicateEquivalentExpenseAndBankRules(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $approval = $this->container->get(AccountingSetupApprovalService::class);

        $results = [];
        for ($run = 1; $run <= 2; $run++) {
            $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', ['attempt' => $run], $this->userId);
            $runId = $setup->createRun($supplierId, $jobId, ['attempt' => $run], 1, $this->userId);
            $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
            $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'expense-' . $run), 'Cloudové služby', 0.9, 4, 4000, [
                'name' => 'Cloudové služby',
                'vendor_client_id' => null,
                'vendor_name_contains' => null,
                'description_contains' => 'cloud',
                'expense_kind' => 'service',
                'target_account_code' => '501.900',
                'application_mode' => 'suggest',
                'priority' => 100,
                'is_active' => true,
            ], []);
            $setup->addProposal($runId, $supplierId, 'bank_rule', hash('sha256', 'bank-' . $run), 'Bankovní poplatek', 0.9, 4, 0, [
                'name' => 'Bankovní poplatek',
                'direction' => 'outgoing',
                'counterparty_account' => '1000000005',
                'counterparty_bank' => '0100',
                'variable_symbol' => null,
                'message_contains' => null,
                'amount_min' => 100.0,
                'amount_max' => 100.0,
                'debit_account_code' => '568',
                'credit_account_code' => '221',
                'description' => 'Pravidelný bankovní poplatek',
                'mode' => 'suggest',
                'own_transfer' => false,
                'tx_ids' => [],
            ], []);

            $results[] = $approval->approve(
                $supplierId,
                $runId,
                array_column($setup->proposals($supplierId, $runId), 'id'),
                $this->userId,
            );
        }

        self::assertSame(1, $results[0]['expense_rules_created']);
        self::assertSame(1, $results[0]['bank_rules_created']);
        self::assertSame(0, $results[1]['expense_rules_created']);
        self::assertSame(0, $results[1]['bank_rules_created']);
        self::assertCount(1, $this->container->get(ExpenseClassificationRuleRepository::class)->activeFor($supplierId));
        self::assertCount(1, $this->container->get(\MyInvoice\Repository\BankPostingRuleRepository::class)->findActive($supplierId, 'outgoing'));
    }

    public function testAppliedSmallAssetReclassificationCreatesEvidenceCard(): void
    {
        $supplierId = $this->supplierId;
        $chart = $this->container->get(ChartOfAccountsRepository::class);
        foreach ([
            ['501.990', '501', 'Testovací drobný majetek'],
        ] as [$code, $parentCode, $name]) {
            if ($chart->findByCode($supplierId, $code) !== null) {
                continue;
            }
            $parent = $chart->findByCode($supplierId, $parentCode);
            self::assertNotNull($parent);
            $chart->insert($supplierId, [
                'account_code' => $code,
                'name' => $name,
                'account_type' => 'expense',
                'normal_side' => 'debit',
                'is_synthetic' => false,
                'parent_id' => (int) $parent['id'],
                'is_active' => true,
            ]);
        }

        $vendorId = $this->client('Syntetický dodavatel');
        $invoiceId = $this->purchaseInvoice('SYN-ASSET-001', $vendorId, 1_000.0);
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $vatRateId);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset, expense_kind, expense_account_code)
             VALUES (?, 'Syntetický přístroj', 1, 'ks', 1000, ?, 0, 1000, 0, 1000, 0,
                     '40', 0, 'service', '501.990')"
        )->execute([$invoiceId, $vatRateId]);
        $this->posting->postDocument(
            $supplierId,
            'purchase_invoice',
            $invoiceId,
            $this->posting->buildFromPurchaseInvoice($supplierId, $invoiceId),
            [
                'entry_date' => self::YEAR . '-06-10',
                'posted' => true,
                'posted_at' => date('Y-m-d H:i:s'),
                'posted_by' => $this->userId,
            ],
        );

        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $analysisJobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $analysisJobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'small-asset-sync'), 'Testovací majetek', 0.9, 1, 1000, [
            'name' => 'Testovací majetek',
            'description_contains' => 'synteticky pristroj',
            'expense_kind' => 'small_asset',
            'target_account_code' => '501.990',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], []);
        $setup->addProposal($runId, $supplierId, 'expense_rule', hash('sha256', 'material-sync'), 'Testovací materiál', 0.9, 1, 1000, [
            'name' => 'Testovací materiál',
            'description_contains' => 'synteticky spotrebni material',
            'expense_kind' => 'material',
            'target_account_code' => '501.990',
            'application_mode' => 'suggest',
            'priority' => 100,
            'is_active' => true,
        ], []);
        $proposalIds = array_column($setup->proposals($supplierId, $runId), 'id');
        $bundle = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, $proposalIds, $this->userId);

        $baseParams = [
            'bundle_id' => (int) $bundle['id'],
            'bundle_hash' => (string) $bundle['bundle_hash'],
            'input_hash' => (string) $bundle['input_hash'],
            'date_from' => self::YEAR . '-06-01',
            'date_to' => self::YEAR . '-06-30',
        ];
        $excludedDryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', array_merge(
            $baseParams,
            ['date_from' => self::YEAR . '-07-01', 'date_to' => self::YEAR . '-07-31', 'dry_run' => true],
        ), $this->userId);
        $runner = $this->container->get(AccountingHistoryReclassificationService::class);
        $runner->run($excludedDryJobId);
        self::assertCount(0, $setup->reclassificationItems($supplierId, $excludedDryJobId));

        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ? WHERE id = ?')
            ->execute(['cancelled', $invoiceId]);
        $cancelledDryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + ['dry_run' => true], $this->userId);
        $runner->run($cancelledDryJobId);
        self::assertCount(0, $setup->reclassificationItems($supplierId, $cancelledDryJobId));
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET status = ? WHERE id = ?')
            ->execute(['received', $invoiceId]);

        $dryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + ['dry_run' => true], $this->userId);
        $runner->run($dryJobId);
        $dryItems = $setup->reclassificationItems($supplierId, $dryJobId);
        self::assertCount(1, $dryItems, json_encode($jobs->find($dryJobId, $supplierId)) ?: 'dry-run bez výsledku');
        self::assertSame('would_change', $dryItems[0]['status']);
        $mismatchedApplyJobId = $jobs->create($supplierId, 'accounting_history_reclassification', array_merge(
            $baseParams,
            [
                'date_to' => self::YEAR . '-06-29',
                'dry_run' => false,
                'dry_run_job_id' => $dryJobId,
            ],
        ), $this->userId);
        $runner->run($mismatchedApplyJobId);
        $mismatchedApply = $jobs->find($mismatchedApplyJobId, $supplierId);
        self::assertSame('failed', $mismatchedApply['status']);
        self::assertSame('matching_dry_run_required', $mismatchedApply['last_error']);

        $originalTaxDate = (string) $this->db->pdo()->query(
            "SELECT tax_date FROM purchase_invoices WHERE id = {$invoiceId}"
        )->fetchColumn();
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET tax_date = ? WHERE id = ?')
            ->execute([self::YEAR . '-07-15', $invoiceId]);
        $missingDocumentJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + [
            'dry_run' => false,
            'dry_run_job_id' => $dryJobId,
        ], $this->userId);
        $runner->run($missingDocumentJobId);
        $missingDocumentJob = $jobs->find($missingDocumentJobId, $supplierId);
        self::assertSame('failed', $missingDocumentJob['status']);
        self::assertSame('result_changed_after_dry_run', $missingDocumentJob['last_error']);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET tax_date = ? WHERE id = ?')
            ->execute([$originalTaxDate, $invoiceId]);

        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET description = ? WHERE purchase_invoice_id = ?')
            ->execute(['Syntetický spotřební materiál', $invoiceId]);
        $changedClassificationJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + [
            'dry_run' => false,
            'dry_run_job_id' => $dryJobId,
        ], $this->userId);
        $runner->run($changedClassificationJobId);
        $changedClassificationItems = $setup->reclassificationItems($supplierId, $changedClassificationJobId);
        self::assertCount(1, $changedClassificationItems);
        self::assertSame('failed', $changedClassificationItems[0]['status']);
        $unchangedClassification = $this->db->pdo()->query(
            "SELECT expense_kind FROM purchase_invoice_items WHERE purchase_invoice_id = {$invoiceId}"
        )->fetchColumn();
        self::assertSame('service', $unchangedClassification);

        $this->db->pdo()->prepare('UPDATE purchase_invoice_items SET description = ? WHERE purchase_invoice_id = ?')
            ->execute(['Syntetický přístroj', $invoiceId]);
        $dryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + ['dry_run' => true], $this->userId);
        $runner->run($dryJobId);

        $applyJobId = $jobs->create($supplierId, 'accounting_history_reclassification', $baseParams + [
            'dry_run' => false,
            'dry_run_job_id' => $dryJobId,
        ], $this->userId);
        $runner->run($applyJobId);
        $applyItems = $setup->reclassificationItems($supplierId, $applyJobId);
        self::assertCount(1, $applyItems, json_encode($jobs->find($applyJobId, $supplierId)) ?: 'ostrý běh bez výsledku');
        self::assertSame('applied', $applyItems[0]['status'], json_encode($applyItems[0]));

        $item = $this->db->pdo()->query(
            "SELECT expense_kind, expense_account_code FROM purchase_invoice_items WHERE purchase_invoice_id = {$invoiceId}"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('small_asset', $item['expense_kind']);
        self::assertSame('501.990', $item['expense_account_code']);
        $cards = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM small_assets WHERE supplier_id = {$supplierId} AND purchase_invoice_id = {$invoiceId}"
        )->fetchColumn();
        self::assertSame(1, $cards);
        self::assertTrue($setup->hasRollbackSnapshot($supplierId, $applyJobId));

        $rollbackJobId = $jobs->create($supplierId, 'accounting_history_reclassification', [
            'rollback_of_job_id' => $applyJobId,
        ], $this->userId);
        $runner->run($rollbackJobId);
        self::assertTrue($jobs->hasAccountingRollbackFor($supplierId, $applyJobId));
        $restoredItem = $this->db->pdo()->query(
            "SELECT expense_kind, expense_account_code FROM purchase_invoice_items WHERE purchase_invoice_id = {$invoiceId}"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('service', $restoredItem['expense_kind']);
        self::assertSame('501.990', $restoredItem['expense_account_code']);
        $remainingCards = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM small_assets WHERE supplier_id = {$supplierId} AND purchase_invoice_id = {$invoiceId}"
        )->fetchColumn();
        self::assertSame(0, $remainingCards);
        self::assertSame(1, $setup->deleteRollbackSnapshot($supplierId, $applyJobId));
        self::assertFalse($setup->hasRollbackSnapshot($supplierId, $applyJobId));
        $blockedRollbackJobId = $jobs->create($supplierId, 'accounting_history_reclassification', [
            'rollback_of_job_id' => $applyJobId,
        ], $this->userId);
        $runner->run($blockedRollbackJobId);
        $blockedRollback = $jobs->find($blockedRollbackJobId, $supplierId);
        self::assertSame('failed', $blockedRollback['status']);
        self::assertSame('rollback_snapshot_missing', $blockedRollback['last_error']);
    }

    public function testReclassificationIncludesUnclassifiedDocumentWhenPostingRuleMovesDefaultToAnalytic(): void
    {
        $supplierId = $this->supplierId;
        $chart = $this->container->get(ChartOfAccountsRepository::class);
        $parent = $chart->findByCode($supplierId, '518');
        self::assertNotNull($parent);
        foreach (['518.100', '518.101'] as $code) {
            if ($chart->findByCode($supplierId, $code) !== null) {
                continue;
            }
            $chart->insert($supplierId, [
                'account_code' => $code,
                'name' => 'Testovací služba ' . $code,
                'account_type' => 'expense',
                'normal_side' => 'debit',
                'is_synthetic' => false,
                'parent_id' => (int) $parent['id'],
                'is_active' => true,
            ]);
        }
        $this->container->get(PostingRuleRepository::class)->upsertOverride(
            $supplierId,
            'invoice.services.received',
            '518',
            '321',
            'Testovací původní syntetická předkontace',
        );

        $vendorId = $this->client('Syntetický dodavatel služby');
        $invoiceId = $this->purchaseInvoice('SYN-SERVICE-001', $vendorId, 1_000.0);
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        self::assertGreaterThan(0, $vatRateId);
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset, expense_kind, expense_account_code)
             VALUES (?, 'Syntetická obecná položka', 1, 'ks', 1000, ?, 0, 1000, 0, 1000, 0,
                     '40', 0, NULL, NULL)"
        )->execute([$invoiceId, $vatRateId]);
        $entryId = $this->posting->postDocument(
            $supplierId,
            'purchase_invoice',
            $invoiceId,
            $this->posting->buildFromPurchaseInvoice($supplierId, $invoiceId),
            [
                'entry_date' => self::YEAR . '-06-10',
                'posted' => true,
                'posted_at' => date('Y-m-d H:i:s'),
                'posted_by' => $this->userId,
            ],
        );
        $initialAccounts = $this->entryAccountCodes($entryId, $supplierId);
        self::assertContains('518', $initialAccounts);
        self::assertNotContains('518.100', $initialAccounts);

        $setup = $this->container->get(AccountingSetupRepository::class);
        $jobs = $this->container->get(ImportJobRepository::class);
        $analysisJobId = $jobs->create($supplierId, 'accounting_setup_analysis', [], $this->userId);
        $runId = $setup->createRun($supplierId, $analysisJobId, [], 1, $this->userId);
        $setup->completeRun($runId, str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64), []);
        $setup->addProposal($runId, $supplierId, 'posting_rule', hash('sha256', 'service-default-analytic'), 'Výchozí analytika služeb', 1.0, 1, 1_000, [
            'rule_key' => 'invoice.services.received',
            'description' => 'Testovací výchozí analytika služeb',
            'debit_account_code' => '518.100',
            'credit_account_code' => '321',
        ], []);
        $proposalId = (int) $setup->proposals($supplierId, $runId)[0]['id'];
        $bundle = $this->container->get(AccountingSetupApprovalService::class)
            ->approve($supplierId, $runId, [$proposalId], $this->userId);

        $matchedDryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', [
            'bundle_id' => (int) $bundle['id'],
            'bundle_hash' => (string) $bundle['bundle_hash'],
            'input_hash' => (string) $bundle['input_hash'],
            'date_from' => self::YEAR . '-06-01',
            'date_to' => self::YEAR . '-06-30',
            'scope_mode' => 'matched',
            'dry_run' => true,
        ], $this->userId);
        $runner = $this->container->get(AccountingHistoryReclassificationService::class);
        $runner->run($matchedDryJobId);
        self::assertCount(0, $setup->reclassificationItems($supplierId, $matchedDryJobId));

        $allDryJobId = $jobs->create($supplierId, 'accounting_history_reclassification', [
            'bundle_id' => (int) $bundle['id'],
            'bundle_hash' => (string) $bundle['bundle_hash'],
            'input_hash' => (string) $bundle['input_hash'],
            'date_from' => self::YEAR . '-06-01',
            'date_to' => self::YEAR . '-06-30',
            'scope_mode' => 'all',
            'dry_run' => true,
        ], $this->userId);
        $runner->run($allDryJobId);
        $items = $setup->reclassificationItems($supplierId, $allDryJobId);
        self::assertCount(1, $items);
        self::assertSame('would_change', $items[0]['status']);
        $afterCodes = array_column((array) $items[0]['after_json']['lines'], 'account_code');
        self::assertContains('518.100', $afterCodes);
        self::assertNotContains('518', $afterCodes);
    }

    public function testFixedAssetCandidateUsesProposedAnalyticAndIsNamed(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $issue = self::YEAR . '-07-01';
        $this->db->pdo()->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Syntetický dodavatel", "Test 1", "Praha", "11000", ?,
                     "synthetic@example.test", "cs", ?, 0, 1)'
        )->execute([$supplierId, $this->czId, $this->currencyId]);
        $vendorId = (int) $this->db->pdo()->lastInsertId();
        $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                 issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                 total_without_vat, total_vat, total_with_vat, status, created_by)
             VALUES (?, ?, "SYN-FIXED-001", "{}", "invoice", "full", ?, ?, ?, ?, ?, 0, 0,
                     90000, 0, 90000, "received", ?)'
        )->execute([$supplierId, $vendorId, $issue, $issue, $issue, $issue, $this->currencyId, $this->userId]);
        $invoiceId = (int) $this->db->pdo()->lastInsertId();
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->pdo()->prepare(
            "INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                 vat_classification_code, is_fixed_asset, expense_kind)
             VALUES (?, 'Syntetický notebook', 1, 'ks', 90000, ?, 0, 90000, 0, 90000, 0,
                     '40', 0, NULL)"
        )->execute([$invoiceId, $vatRateId]);

        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [
            'date_from' => $issue,
            'date_to' => $issue,
            'use_ai' => false,
        ], $this->userId);
        $this->container->get(AccountingSetupAnalysisService::class)->run($jobId);
        $setup = $this->container->get(AccountingSetupRepository::class);
        $run = $setup->runByJob($jobId);
        self::assertNotNull($run);
        $proposals = $setup->proposals($supplierId, (int) $run['id']);
        $asset = array_values(array_filter(
            $proposals,
            static fn (array $proposal): bool => $proposal['proposal_type'] === 'asset_candidate',
        ));
        self::assertCount(1, $asset);
        self::assertSame('Syntetický notebook', $asset[0]['proposal_json']['item_description']);
        self::assertSame('042.100', $asset[0]['proposal_json']['target_account_code']);
        self::assertNotEmpty(array_filter(
            $proposals,
            static fn (array $proposal): bool => $proposal['proposal_type'] === 'chart_account'
                && ($proposal['proposal_json']['account_code'] ?? null) === '042.100',
        ));
    }

    public function testSmallAssetKeywordAggregatesAcrossDifferentVendors(): void
    {
        $supplierId = $this->cloneSupplier('double_entry');
        $this->container->get(ChartOfAccountsSeeder::class)->seedForSupplier($supplierId);
        $issue = self::YEAR . '-07-02';
        $vatRateId = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();

        foreach ([1, 2] as $index) {
            $this->db->pdo()->prepare(
                'INSERT INTO clients
                    (supplier_id, company_name, street, city, zip, country_id, main_email,
                     language, currency_default_id, is_customer, is_vendor)
                 VALUES (?, ?, "Test 1", "Praha", "11000", ?, ?, "cs", ?, 0, 1)'
            )->execute([
                $supplierId,
                "Syntetický dodavatel {$index}",
                $this->czId,
                "synthetic-vendor-{$index}@example.test",
                $this->currencyId,
            ]);
            $vendorId = (int) $this->db->pdo()->lastInsertId();
            $this->db->pdo()->prepare(
                'INSERT INTO purchase_invoices
                    (supplier_id, vendor_id, vendor_invoice_number, vendor_snapshot, document_kind, vat_deduction,
                     issue_date, tax_date, due_date, received_at, currency_id, reverse_charge, is_fixed_asset,
                     total_without_vat, total_vat, total_with_vat, status, created_by)
                 VALUES (?, ?, ?, "{}", "invoice", "full", ?, ?, ?, ?, ?, 0, 0,
                         20000, 0, 20000, "received", ?)'
            )->execute([
                $supplierId,
                $vendorId,
                "SYN-SMALL-00{$index}",
                $issue,
                $issue,
                $issue,
                $issue,
                $this->currencyId,
                $this->userId,
            ]);
            $invoiceId = (int) $this->db->pdo()->lastInsertId();
            $this->db->pdo()->prepare(
                'INSERT INTO purchase_invoice_items
                    (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                     vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index,
                     vat_classification_code, is_fixed_asset, expense_kind)
                 VALUES (?, ?, 1, "ks", 20000, ?, 0, 20000, 0, 20000, 0, "40", 0, NULL)'
            )->execute([$invoiceId, "Syntetický notebook {$index}", $vatRateId]);
        }

        $jobs = $this->container->get(ImportJobRepository::class);
        $jobId = $jobs->create($supplierId, 'accounting_setup_analysis', [
            'date_from' => $issue,
            'date_to' => $issue,
            'use_ai' => false,
        ], $this->userId);
        $this->container->get(AccountingSetupAnalysisService::class)->run($jobId);
        $run = $this->container->get(AccountingSetupRepository::class)->runByJob($jobId);
        self::assertNotNull($run);
        $proposals = $this->container->get(AccountingSetupRepository::class)
            ->proposals($supplierId, (int) $run['id']);
        $rules = array_values(array_filter(
            $proposals,
            static fn (array $proposal): bool => $proposal['proposal_type'] === 'expense_rule'
                && ($proposal['proposal_json']['expense_kind'] ?? null) === 'small_asset',
        ));

        self::assertCount(1, $rules);
        self::assertNull($rules[0]['proposal_json']['vendor_client_id']);
        self::assertSame('notebook', $rules[0]['proposal_json']['description_contains']);
        self::assertSame('501.200', $rules[0]['proposal_json']['target_account_code']);
        self::assertSame(2, $rules[0]['occurrence_count']);
    }

    /** @return list<string> */
    private function entryAccountCodes(int $entryId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT coa.account_code
               FROM journal_entry_lines jel
               JOIN chart_of_accounts coa ON coa.id = jel.account_id AND coa.supplier_id = jel.supplier_id
              WHERE jel.entry_id = ? AND jel.supplier_id = ?
              ORDER BY jel.line_no, jel.id'
        );
        $stmt->execute([$entryId, $supplierId]);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
