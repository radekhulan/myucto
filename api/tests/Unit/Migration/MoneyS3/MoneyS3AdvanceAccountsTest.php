<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\MoneyS3AdvanceAccounts;
use MyInvoice\Service\Migration\MoneyS3\MoneyS3BatchImporter;
use MyInvoice\Service\Migration\MoneyS3\Ms3Backup;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PHPUnit\Framework\TestCase;

final class MoneyS3AdvanceAccountsTest extends TestCase
{
    public function testSelectsSingleAnalyticFromDocumentsAndUsesJournalForOtherDirection(): void
    {
        $detected = $this->detect(SyntheticAgenda::filesWithAdvanceAccounts());

        self::assertSame(['account' => '324.700', 'candidates' => ['324.700'], 'source' => 'documents'], $detected[324]);
        self::assertSame(['account' => '314.300', 'candidates' => ['314.300'], 'source' => 'journal'], $detected[314]);
        self::assertSame([], $this->detect(SyntheticAgenda::filesWithAdvanceAccounts(), ['ROK.001']));
    }

    public function testUsesClearMajorityOfAdvanceDocuments(): void
    {
        $detected = $this->detect(SyntheticAgenda::filesWithAdvanceAccounts(['324700', '324700', '324800']));

        self::assertSame('324.700', $detected[324]['account']);
        self::assertSame(['324.700', '324.800'], $detected[324]['candidates']);
    }

    public function testAmbiguousDocumentsAndJournalGenerateWarningsWithoutRules(): void
    {
        $files = SyntheticAgenda::filesWithAdvanceAccounts(['324700', '324800']);
        $files = SyntheticAgenda::patch($files, 'ROK.002/UcDenik.DAT', null, [[
            'Cislo' => 804, 'Zdroj' => 'PK', 'Doklad' => 'PV25011', 'Datum' => '2025-05-09',
            'UcMD' => '211000', 'UcD' => '314800', 'Castka' => -1210.0,
        ]]);
        $detected = $this->detect($files);
        $chart = array_fill_keys(['324.700', '324.800', '314.300', '314.800'], ['is_active' => true]);
        $effective = [
            'advance.received.collection' => $this->rule('advance.received.collection', '221', '324'),
            'advance.paid.payment' => $this->rule('advance.paid.payment', '314', '221'),
        ];
        $result = MoneyS3AdvanceAccounts::plan($detected, $chart, $effective);

        self::assertSame([], $result['rules']);
        self::assertSame(['advance_accounts_ambiguous_documents', 'advance_accounts_ambiguous_journal'], array_column($result['warnings'], 'code'));
        self::assertSame(['324.700, 324.800', '314.300, 314.800'], array_column(array_column($result['warnings'], 'context'), 'accounts'));
    }

    public function testPlanChangesOnlyAdvanceSideAndKeepsInactiveOverride(): void
    {
        $detected = $this->detect(SyntheticAgenda::filesWithAdvanceAccounts());
        $chart = array_fill_keys(['324.700', '314.300'], ['is_active' => true]);
        $effective = [];
        foreach ([
            ['advance.received.collection', '221', '324'],
            ['advance.received.settlement', '324', '311'],
            ['advance.received.vatdocument', '324', '343.200'],
            ['advance.paid.payment', '314', '221'],
            ['advance.paid.settlement', '321', '314'],
            ['advance.paid.vatdocument', '343.100', '314'],
        ] as [$key, $debit, $credit]) {
            $effective[$key] = $this->rule($key, $debit, $credit);
        }
        $key = 'advance.received.collection';
        $inactive = array_replace($this->rule($key, '221', '324.800'), ['supplier_id' => 1, 'is_active' => false]);
        $result = MoneyS3AdvanceAccounts::plan($detected, $chart, $effective, [$key => [$inactive]]);

        self::assertSame([
            'advance.received.settlement' => '324.700/311',
            'advance.received.vatdocument' => '324.700/343.200',
            'advance.paid.payment' => '314.300/221',
            'advance.paid.settlement' => '321/314.300',
            'advance.paid.vatdocument' => '343.100/314.300',
        ], array_column(array_map(static fn (array $rule): array => $rule + [
            'pair' => $rule['debit_account_code'] . '/' . $rule['credit_account_code'],
        ], $result['rules']), 'pair', 'rule_key'));
        self::assertSame($inactive, $result['current'][0]);
        self::assertSame([], $result['warnings']);
    }

    private function rule(string $key, string $debit, string $credit): array
    {
        return ['rule_key' => $key, 'supplier_id' => null, 'is_active' => true, 'description' => $key,
            'debit_account_code' => $debit, 'credit_account_code' => $credit];
    }

    private function detect(array $files, ?array $onlyDirs = null): array
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ms3advance_' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700);
        try {
            SyntheticAgenda::writeLzFiles($tmp . '/agenda.lz', $files);
            $backup = Ms3Backup::extract($tmp . '/agenda.lz', $tmp . '/agenda');
            return MoneyS3AdvanceAccounts::detect($backup, $onlyDirs);
        } finally {
            MoneyS3BatchImporter::removeTree($tmp);
        }
    }
}
