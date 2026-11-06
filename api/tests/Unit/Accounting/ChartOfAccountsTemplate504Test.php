<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Accounting;

use MyInvoice\Service\Accounting\ChartOfAccounts504Policy;
use MyInvoice\Service\Accounting\ChartOfAccountsTemplate504;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChartOfAccountsTemplate504Test extends TestCase
{
    public function testFullTemplateHasUniqueCodesValidRolesAndResolvedParents(): void
    {
        $accounts = array_column(ChartOfAccountsTemplate504::ACCOUNTS, null, 'code');
        self::assertCount(183, $accounts);
        self::assertSame(183, ChartOfAccountsTemplate504::count());
        $classes = [];
        foreach (ChartOfAccountsTemplate504::ACCOUNTS as $account) {
            $code = $account['code'];
            self::assertNull(ChartOfAccounts504Policy::validationError($code, $account['type'], $account['normal_side'], $account['parent_code'] ?? null), $code);
            $classes[$code[0]] = true;
            if (isset($account['parent_code'])) {
                $parent = $accounts[$account['parent_code']];
                self::assertSame($parent['type'], $account['type']);
                self::assertSame($parent['normal_side'], $account['normal_side']);
                self::assertSame($parent['report_rows'], $account['report_rows']);
            }
            if (in_array($account['type'], ['expense', 'revenue'], true)) {
                self::assertSame('unassessed', $account['tax_assessment']);
                self::assertNotEmpty($account['report_rows']);
            }
            foreach ($account['report_rows'] as $row) {
                self::assertMatchesRegularExpression('/^(UR|US|UT):[1-9][0-9]*$/', $row);
            }
        }
        self::assertSame([0, 1, 2, 3, 5, 6, 9], array_keys($classes));
        foreach (['151', '411', '431', '701', '702', '710', '963', '552.100', '501.900'] as $code) {
            self::assertArrayNotHasKey($code, $accounts);
        }
    }

    #[DataProvider('roles')]
    public function testRoleTypesSidesAndReportReferences(string $code, string $type, ?string $side, array $rows): void
    {
        $account = ChartOfAccounts504Policy::defaultAccount($code);
        self::assertNotNull($account);
        self::assertSame($type, $account['type']);
        self::assertSame($side, $account['normal_side']);
        self::assertSame($rows, $account['report_rows']);
    }

    public static function roles(): array
    {
        return [
            ['901', 'equity', 'credit', ['US:3']],
            ['911', 'equity', 'credit', ['US:4']],
            ['931', 'equity', null, ['US:8']],
            ['932', 'equity', null, ['US:9']],
            ['933', 'equity', null, ['US:7', 'UT:63']],
            ['961', 'closing', null, []],
            ['962', 'closing', null, []],
            ['549.100', 'expense', 'debit', ['UT:28']],
            ['544', 'expense', 'debit', ['UT:24']],
            ['644', 'revenue', 'credit', ['UT:51']],
            ['545', 'expense', 'debit', ['UT:25']],
            ['645', 'revenue', 'credit', ['UT:52']],
            ['541', 'expense', 'debit', ['UT:22']],
            ['641', 'revenue', 'credit', ['UT:49']],
            ['552', 'expense', 'debit', ['UT:31']],
            ['652', 'revenue', 'credit', ['UT:56']],
            ['543', 'expense', 'debit', ['UT:23']],
            ['546', 'expense', 'debit', ['UT:26']],
            ['955', 'liability', 'credit', ['US:17']],
            ['955.100', 'liability', 'credit', ['US:17']],
            ['324.200', 'liability', 'credit', ['US:24']],
            ['549.900', 'expense', 'debit', ['UT:28']],
            ['549.910', 'expense', 'debit', ['UT:28']],
            ['518.200', 'expense', 'debit', ['UT:8']],
            ['649.100', 'revenue', 'credit', ['UT:54']],
            ['649.200', 'revenue', 'credit', ['UT:54']],
            ['379.910', 'liability', 'credit', ['US:38']],
            ['379.920', 'liability', 'credit', ['US:38']],
            ['082', 'asset', 'credit', ['UR:36']],
            ['391', 'asset', 'credit', ['UR:71']],
            ['255', 'liability', 'debit', ['US:42']],
            ['571', 'expense', 'credit', ['UT:11']],
        ];
    }
}
