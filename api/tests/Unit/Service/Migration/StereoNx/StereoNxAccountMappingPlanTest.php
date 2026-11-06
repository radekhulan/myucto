<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingPlan;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxAccountMappingPlanTest extends TestCase
{
    public function testProposalIncludesOnlyUsedAccountsAndPreservesSourceKeys(): void
    {
        $prepared = self::prepared([self::entry('518001', '321'), self::entry('518001', '321', order: '2')], ['999']);
        $proposal = (new StereoNxAccountMappingPlan())->propose($prepared, []);
        self::assertSame([321 => '321', 518001 => '518.001'], $proposal['accounts']);
        self::assertSame(['321', '518001'], array_column($proposal['used_accounts'], 'source_code'));
        self::assertSame([2, 2], array_column($proposal['used_accounts'], 'count'));
        self::assertNull($proposal['vat']);
    }

    public function testApplyPreservesEverySourceFieldIncludingRedStornoOpeningAndFiscalYear(): void
    {
        $entry = self::entry('518001', '321') + ['source_hash' => 'synthetic-hash', 'is_red_storno' => true,
            'is_opening' => true, 'amount_cents' => 12345, 'source_year' => 2025, 'date' => '2026-02-03'];
        $prepared = self::prepared([$entry]);
        $mapped = (new StereoNxAccountMappingPlan())->apply($prepared, ['accounts' => ['518001' => '518022']], []);
        self::assertSame($entry + ['target_debit' => '518.022', 'target_credit' => '321'], $mapped['accounting_plan']['entries'][0]);
        self::assertSame($prepared['accounting_plan']['chart'], $mapped['accounting_plan']['chart']);
        self::assertSame(['518001' => '518.022'], $mapped['account_mapping']['accounts']);
    }

    public function testSeparateBankSeriesGetDistinctAnalyticsWithoutOccupyingSourceChartAccounts(): void
    {
        $prepared = self::prepared([self::entry('221', '311', 'B', 'BANK-A'),
            self::entry('221', '311', 'B', 'BANK-B')], ['221100']);
        $payments = self::payments('BANK-B', 'BANK-A');
        $planner = new StereoNxAccountMappingPlan();
        $proposal = $planner->propose($prepared, $payments);
        self::assertSame(['BANK-A' => '221.101', 'BANK-B' => '221.102'], $proposal['bank_accounts']);
        $mapped = $planner->apply($prepared, $proposal, $payments);
        self::assertSame(['221.101', '221.102'], array_column($mapped['accounting_plan']['entries'], 'target_debit'));
    }

    public function testSingleBankKeepsUnambiguousSourceAnalyticAndRoutesOpeningToIt(): void
    {
        $prepared = self::prepared([self::entry('221005', '311', 'B', 'BANK-A'),
            self::entry('221005', '701', 'I') + ['is_opening' => true]]);
        $planner = new StereoNxAccountMappingPlan();
        $payments = self::payments('BANK-A');
        $proposal = $planner->propose($prepared, $payments);
        self::assertSame(['BANK-A' => '221.005'], $proposal['bank_accounts']);
        self::assertSame(['221.005', '221.005'], array_column($planner->apply($prepared, $proposal, $payments)['accounting_plan']['entries'], 'target_debit'));
    }

    public function testSourceAnalyticSharedByBanksIsNeverAssignedToBoth(): void
    {
        $prepared = self::prepared([self::entry('221100', '311', 'B', 'A'), self::entry('221100', '311', 'B', 'B')]);
        $proposal = (new StereoNxAccountMappingPlan())->propose($prepared, self::payments('A', 'B'));
        self::assertSame(['A' => '221.101', 'B' => '221.102'], $proposal['bank_accounts']);
    }

    public function testPartialBankConfigurationCannotMakeAnAmbiguousOpeningUnambiguous(): void
    {
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Účet banky bez jednoznačné řady');
        (new StereoNxAccountMappingPlan())->apply(self::prepared([self::entry('221', '701') + ['is_opening' => true]]),
            ['bank_accounts' => ['A' => '221.100']], self::payments('A', 'B'));
    }

    public function testBankSeriesAliasesShareOneAnalyticAndOnePhysicalIdentity(): void
    {
        $payments = self::bankAliases();
        $prepared = self::prepared([self::entry('221005', '311', 'B', 'A'), self::entry('221005', '311', 'B', 'B'),
            self::entry('221005', '701') + ['is_opening' => true]]);
        $planner = new StereoNxAccountMappingPlan();
        $proposal = $planner->propose($prepared, $payments);
        self::assertSame(['A' => '221.005', 'B' => '221.005'], $proposal['bank_accounts']);
        self::assertSame($proposal['banks'][0]['identity_key'], $proposal['banks'][1]['identity_key']);
        $proposal['accounts'] = [];
        $mapped = $planner->apply($prepared, $proposal, $payments);
        self::assertSame(['221.005', '221.005', '221.005'], array_column($mapped['accounting_plan']['entries'], 'target_debit'));
    }

    public function testBankSeriesAliasesCannotAssignDifferentAnalyticsToOnePhysicalAccount(): void
    {
        $this->expectException(StereoNxException::class);
        $this->expectExceptionMessage('Řady stejného bankovního účtu');
        (new StereoNxAccountMappingPlan())->apply(self::prepared([]),
            ['bank_accounts' => ['A' => '221.100', 'B' => '221.101']], self::bankAliases());
    }

    public function testMissingAliasChoiceUsesAlreadyConfirmedPhysicalBankTarget(): void
    {
        $mapped = (new StereoNxAccountMappingPlan())->apply(self::prepared([self::entry('221', '311', 'B', 'B')]),
            ['bank_accounts' => ['A' => '221.100']], self::bankAliases());
        self::assertSame(['A' => '221.100', 'B' => '221.100'], $mapped['account_mapping']['bank_accounts']);
        self::assertSame('221.100', $mapped['accounting_plan']['entries'][0]['target_debit']);
    }

    public function testBankIdentityIncludesCurrencyAndBankAndNeverMergesMissingMetadata(): void
    {
        $planner = new StereoNxAccountMappingPlan();
        $payments = self::bankAliases();
        $payments['records']['bank_accounts'][1]['currency'] = 'EUR';
        $proposal = $planner->propose(self::prepared([]), $payments);
        self::assertNotSame($proposal['banks'][0]['identity_key'], $proposal['banks'][1]['identity_key']);
        $payments = self::bankAliases();
        $payments['records']['bank_accounts'][1]['bank_code'] = '0300';
        $proposal = $planner->propose(self::prepared([]), $payments);
        self::assertNotSame($proposal['banks'][0]['identity_key'], $proposal['banks'][1]['identity_key']);
        $proposal = $planner->propose(self::prepared([]), self::payments('A', 'B'));
        self::assertNotSame($proposal['banks'][0]['identity_key'], $proposal['banks'][1]['identity_key']);
    }

    public function testMultipleBanksRequireExplicitAccountForSharedOpening(): void
    {
        $planner = new StereoNxAccountMappingPlan();
        $prepared = self::prepared([self::entry('221', '701', 'I') + ['is_opening' => true]]);
        $payments = self::payments('A', 'B');
        $configuration = ['bank_accounts' => ['A' => '221.100', 'B' => '221.101']];
        try {
            $planner->apply($prepared, $configuration, $payments);
            self::fail('Neurčená banka nesmí převzít náhodnou analytiku.');
        } catch (StereoNxException $e) {
            self::assertSame('account_mapping_bank_unresolved', $e->errorCode);
        }
        $configuration['accounts'] = ['221' => '221.900'];
        self::assertSame('221.900', $planner->apply($prepared, $configuration, $payments)['accounting_plan']['entries'][0]['target_debit']);
    }

    public function testVatInputOutputAndRemittanceAreDeterminedBySourceEvidence(): void
    {
        $prepared = self::prepared([self::entry('343001', '321', 'PF'),
            self::entry('311', '343VAT', 'VF'), self::entry('343', '221', 'B', 'BANK')]);
        $planner = new StereoNxAccountMappingPlan();
        $payments = self::payments('BANK');
        $proposal = $planner->propose($prepared, $payments);
        self::assertSame(['input' => 1, 'output' => 1, 'settlement' => 1, 'unresolved' => 0, 'reverse_charge_unresolved' => 0], $proposal['vat_counts']);
        $entries = $planner->apply($prepared, $proposal, $payments)['accounting_plan']['entries'];
        self::assertSame('343.100', $entries[0]['target_debit']);
        self::assertSame('343.200', $entries[1]['target_credit']);
        self::assertSame('343.900', $entries[2]['target_debit']);
    }

    public function testCreditNoteAndRedStornoDoNotReverseVatRole(): void
    {
        $prepared = self::prepared([self::entry('321', '343', 'PF'), self::entry('343', '311', 'VF') + ['is_red_storno' => true]]);
        $planner = new StereoNxAccountMappingPlan();
        $entries = $planner->apply($prepared, $planner->propose($prepared, []), [])['accounting_plan']['entries'];
        self::assertSame('343.100', $entries[0]['target_credit']);
        self::assertSame('343.200', $entries[1]['target_debit']);
    }

    public function testReverseChargeRequiresProvenDocumentAndUsesBothVatSides(): void
    {
        $prepared = self::prepared([self::entry('518', '321', 'PF'), self::entry('343', '343', 'PF', order: '2')]);
        $planner = new StereoNxAccountMappingPlan();
        $entry = $planner->apply($prepared, $planner->propose($prepared, []), [])['accounting_plan']['entries'][1];
        self::assertSame('343.100', $entry['target_debit']);
        self::assertSame('343.200', $entry['target_credit']);
        $unknown = self::prepared([self::entry('343', '343', 'I')]);
        self::assertSame(2, $planner->propose($unknown, [])['vat_counts']['unresolved']);
    }

    #[DataProvider('reverseChargeDirections')]
    public function testReverseChargeFollowsProvenSiblingDirectionIncludingCreditNotesAndRedStorno(
        string $agenda, string $debit, string $credit, bool $red, string $vatDebit, string $vatCredit,
    ): void {
        $prepared = self::prepared([self::entry($debit, $credit, $agenda) + ['is_red_storno' => $red],
            self::entry('343', '343', $agenda, order: '2') + ['is_red_storno' => $red, 'amount_cents' => 21000]]);
        $planner = new StereoNxAccountMappingPlan();
        $entry = $planner->apply($prepared, $planner->propose($prepared, []), [])['accounting_plan']['entries'][1];
        self::assertSame($vatDebit, $entry['target_debit']);
        self::assertSame($vatCredit, $entry['target_credit']);
        self::assertSame($red, $entry['is_red_storno']);
        self::assertSame(21000, $entry['amount_cents']);
    }

    public static function reverseChargeDirections(): iterable
    {
        yield 'běžná přijatá' => ['PF', '518', '321', false, '343.100', '343.200'];
        yield 'přijatý dobropis' => ['PF', '321', '518', false, '343.200', '343.100'];
        yield 'červené storno přijaté' => ['PF', '518', '321', true, '343.100', '343.200'];
        yield 'červené storno dobropisu' => ['PF', '321', '518', true, '343.200', '343.100'];
        yield 'vydaná strana' => ['VF', '311', '602', false, '343.100', '343.200'];
        yield 'vydaný dobropis' => ['VF', '602', '311', false, '343.200', '343.100'];
    }

    public function testUnknownReverseChargeCannotCollapseBothVatLegsToGenericUnresolvedTarget(): void
    {
        $prepared = self::prepared([self::entry('343', '343', 'PF')]);
        $planner = new StereoNxAccountMappingPlan();
        $configuration = $planner->propose($prepared, []);
        self::assertSame(2, $configuration['vat_counts']['unresolved']);
        self::assertSame(2, $configuration['vat_counts']['reverse_charge_unresolved']);
        self::assertSame('vat_reverse_charge_ambiguous', $configuration['unresolved'][0]['reason']);
        $configuration['vat']['unresolved'] = '343.800';
        try {
            $planner->apply($prepared, $configuration, []);
            self::fail('Dvojice bez doloženého směru nesmí ztratit obě nohy na společném cílovém účtu.');
        } catch (StereoNxException $e) {
            self::assertSame('account_mapping_vat_reverse_charge_unresolved', $e->errorCode);
        }
        $configuration['vat'] = null;
        self::assertSame('343', $planner->apply($prepared, $configuration, [])['accounting_plan']['entries'][0]['target_debit']);
    }

    public function testReverseChargeWithConflictingSidesOrDifferentStornoModeRemainsUnresolved(): void
    {
        $planner = new StereoNxAccountMappingPlan();
        $conflicting = self::prepared([self::entry('518', '321', 'PF'),
            self::entry('321', '518', 'PF', order: '2'), self::entry('343', '343', 'PF', order: '3')]);
        self::assertSame(2, $planner->propose($conflicting, [])['vat_counts']['unresolved']);
        $stornoMismatch = self::prepared([self::entry('518', '321', 'PF'),
            self::entry('343', '343', 'PF', order: '2') + ['is_red_storno' => true]]);
        self::assertSame(2, $planner->propose($stornoMismatch, [])['vat_counts']['unresolved']);
    }

    public function testSiblingProofIsScopedToSourceLineAndFiscalYear(): void
    {
        $prepared = self::prepared([self::entry('518', '321', 'I'), self::entry('343', '343', 'I', order: '2'),
            self::entry('343', '343', 'I', key: 'OTHER'),
            array_replace(self::entry('343', '343', 'I'), ['posting_year' => 2027])]);
        $counts = (new StereoNxAccountMappingPlan())->propose($prepared, [])['vat_counts'];
        self::assertSame(['input' => 1, 'output' => 1, 'settlement' => 0, 'unresolved' => 4, 'reverse_charge_unresolved' => 4], $counts);
    }

    public function testCashVatOpeningAndContradictoryEvidenceRequireExplicitUnresolvedTarget(): void
    {
        $prepared = self::prepared([self::entry('343', '211', 'P'),
            self::entry('343', '701', 'I') + ['is_opening' => true], self::entry('343', '311', 'PF')]);
        $planner = new StereoNxAccountMappingPlan();
        $configuration = $planner->propose($prepared, []);
        self::assertSame(3, $configuration['vat_counts']['unresolved']);
        try {
            $planner->apply($prepared, $configuration, []);
            self::fail('Nejednoznačné DPH nesmí bez potvrzení dostat cílovou analytiku.');
        } catch (StereoNxException $e) {
            self::assertSame('account_mapping_vat_unresolved', $e->errorCode);
        }
        $configuration['vat']['unresolved'] = '343.800';
        self::assertSame(['343.800', '343.800', '343.800'], array_column($planner->apply($prepared, $configuration, [])['accounting_plan']['entries'], 'target_debit'));
    }

    public function testBankPostingWithExpenseSiblingDoesNotMasqueradeAsTaxRemittance(): void
    {
        $prepared = self::prepared([self::entry('518', '221', 'B'), self::entry('343', '221', 'B', order: '2')]);
        self::assertSame(1, (new StereoNxAccountMappingPlan())->propose($prepared, [])['vat_counts']['unresolved']);
    }

    public function testUnresolvedOpeningIncludesReadableSourceDocumentAndPreservesUncertainty(): void
    {
        $entry = self::entry('701', '343', 'ID', 'PS') + ['is_opening' => true,
            'date' => '2026-01-01', 'document_no' => 'TEST-PS-9',
            'description' => 'Testovací počáteční stav DPH', 'amount_cents' => 123456];
        $proposal = (new StereoNxAccountMappingPlan())->propose(self::prepared([$entry]), []);
        self::assertSame([['source_key' => $entry['source_key'], 'side' => 'credit', 'source_code' => '343',
            'reason' => 'opening_balance', 'date' => '2026-01-01', 'document_no' => 'TEST-PS-9',
            'description' => 'Testovací počáteční stav DPH', 'amount_cents' => 123456,
            'is_opening' => true, 'debit' => '701', 'credit' => '343', 'source_name' => 'Test 343']], $proposal['unresolved']);
        self::assertSame(1, $proposal['vat_counts']['unresolved']);
        self::assertSame(0, $proposal['vat_counts']['settlement']);
        self::assertNull($proposal['vat']['unresolved']);
    }

    public function testUnresolvedSampleIsBoundedWhileCountIncludesEveryPosting(): void
    {
        $entries = [];
        for ($index = 0; $index < 125; $index++) {
            $entries[] = self::entry('343', '211', 'P', key: (string) $index);
        }
        $proposal = (new StereoNxAccountMappingPlan())->propose(self::prepared($entries), []);
        self::assertCount(100, $proposal['unresolved']);
        self::assertSame(125, $proposal['vat_counts']['unresolved']);
        self::assertSame('vat_role_ambiguous', $proposal['unresolved'][0]['reason']);
    }

    public function testDisablingAutomaticVatHonorsExplicitSourceMapping(): void
    {
        $prepared = self::prepared([self::entry('343', '211', 'P')]);
        $mapped = (new StereoNxAccountMappingPlan())->apply($prepared, ['accounts' => ['343' => '343.700'], 'vat' => null], []);
        self::assertSame('343.700', $mapped['accounting_plan']['entries'][0]['target_debit']);
    }

    public function testNativeVatRolesUseConfirmedManualTargetsFromPurchaseSaleAndRemittance(): void
    {
        $prepared = self::prepared([self::entry('34301', '321', 'PF'), self::entry('311', '34302', 'VF'),
            self::entry('34303', '221', 'B')]);
        $planner = new StereoNxAccountMappingPlan();
        $mapped = $planner->apply($prepared, ['accounts' => ['34301' => '343.110', '34302' => '343.210',
            '34303' => '343.910', '221' => '221'], 'vat' => null], []);
        self::assertSame(['input' => '343.110', 'output' => '343.210', 'settlement' => '343.910'], $planner->nativeVatAccounts($mapped));
    }

    public function testNativeVatRoleCannotHideTwoConflictingManualTargets(): void
    {
        $prepared = self::prepared([self::entry('34301', '321', 'PF'), self::entry('34302', '321', 'PF', key: '2')]);
        $planner = new StereoNxAccountMappingPlan();
        $mapped = $planner->apply($prepared, ['accounts' => ['34301' => '343.110', '34302' => '343.120'], 'vat' => null], []);
        try {
            $planner->nativeVatAccounts($mapped);
            self::fail('Jedna nativní role nesmí zamlčet další použitý účet.');
        } catch (StereoNxException $e) {
            self::assertSame('account_mapping_vat_role_conflict', $e->errorCode);
        }
    }

    public function testOpeningAndUnprovenVatDoNotEstablishNativeRole(): void
    {
        $prepared = self::prepared([self::entry('34301', '701', 'ID') + ['is_opening' => true],
            self::entry('34302', '211', 'P')]);
        $planner = new StereoNxAccountMappingPlan();
        $mapped = $planner->apply($prepared, ['accounts' => ['34301' => '343.110', '34302' => '343.210'], 'vat' => null], []);
        self::assertSame([], $planner->nativeVatAccounts($mapped));
    }

    public function testNativeVatRolesFollowVerifiedReverseChargeCreditNoteSides(): void
    {
        $prepared = self::prepared([self::entry('321', '518', 'PF'), self::entry('34302', '34301', 'PF', order: '2')]);
        $planner = new StereoNxAccountMappingPlan();
        $mapped = $planner->apply($prepared, ['accounts' => ['34301' => '343.110', '34302' => '343.210'], 'vat' => null], []);
        self::assertSame(['output' => '343.210', 'input' => '343.110'], $planner->nativeVatAccounts($mapped));
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidMappingsFailBeforeWritingAnything(array $configuration, string $error): void
    {
        $prepared = self::prepared([self::entry('518', '321')]);
        try {
            (new StereoNxAccountMappingPlan())->apply($prepared, $configuration, self::payments('A', 'B'));
            self::fail('Neplatné mapování musí být odmítnuto.');
        } catch (StereoNxException $e) {
            self::assertSame($error, $e->errorCode);
        }
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'nepoužitý účet' => [['accounts' => ['999' => '999']], 'account_mapping_source_unknown'];
        yield 'neznámá banka' => [['bank_accounts' => ['UNKNOWN' => '221.100']], 'account_mapping_source_unknown'];
        yield 'jiná syntetika' => [['accounts' => ['518' => '501.100']], 'account_mapping_synthetic_changed'];
        yield 'prázdný účet' => [['accounts' => ['518' => '']], 'account_mapping_target_invalid'];
        yield 'dlouhý účet' => [['accounts' => ['518' => '5181234567']], 'account_mapping_target_invalid'];
        yield 'mezera uvnitř' => [['accounts' => ['518' => '518 100']], 'account_mapping_target_invalid'];
        yield 'netextový účet' => [['accounts' => ['518' => 518100]], 'account_mapping_target_invalid'];
        yield 'analytika banky není číselná' => [['bank_accounts' => ['A' => '221.cash']], 'account_mapping_bank_invalid'];
        yield 'sdílená analytika bank' => [['bank_accounts' => ['A' => '221.100', 'B' => '221100']], 'account_mapping_bank_duplicate'];
        yield 'sdílené role DPH' => [['vat' => ['input' => '343.100', 'output' => '343100', 'settlement' => '343.900']], 'account_mapping_vat_duplicate'];
    }

    private static function entry(string $debit, string $credit, string $agenda = 'I', string $series = 'TEST', string $key = '1', string $order = '1'): array
    {
        return ['source_key' => json_encode([$agenda, $series, 'TEST-1', $key, $order], JSON_THROW_ON_ERROR),
            'debit' => $debit, 'credit' => $credit, 'posting_year' => 2026];
    }

    private static function prepared(array $entries, array $unused = []): array
    {
        $codes = $unused;
        foreach ($entries as $entry) array_push($codes, $entry['debit'], $entry['credit']);
        return ['accounting_plan' => ['entries' => $entries,
            'chart' => array_map(static fn (string $code): array => ['code' => $code, 'name' => 'Test ' . $code,
                'source_hash' => 'synthetic-' . $code], array_values(array_unique($codes)))]];
    }

    private static function payments(string ...$series): array
    {
        return ['records' => ['bank_accounts' => array_map(static fn (string $key): array => ['source_key' => $key, 'label' => 'Test ' . $key], $series)]];
    }

    private static function bankAliases(): array
    {
        return ['records' => ['bank_accounts' => [
            ['source_key' => 'A', 'account_number' => '1000000005', 'bank_code' => '0100', 'currency' => 'CZK'],
            ['source_key' => 'B', 'account_number' => '0-1000000005', 'bank_code' => '0100', 'currency' => 'CZK'],
        ]]];
    }
}
