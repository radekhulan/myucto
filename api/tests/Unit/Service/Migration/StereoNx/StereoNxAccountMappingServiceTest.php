<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Migration\StereoNx;

use MyInvoice\Service\Migration\StereoNx\StereoNxAccountMappingService;
use MyInvoice\Service\Migration\StereoNx\StereoNxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StereoNxAccountMappingServiceTest extends TestCase
{
    public function testExistingTargetIdentityRetainsLegacyCodeAndPrefersExactNativeAccount(): void
    {
        $legacy = ['account_code' => '501001'];
        $native = ['account_code' => '501.001'];
        self::assertSame(['501.001' => '501001'], StereoNxAccountMappingService::existingTargets([$legacy]));
        self::assertSame(['501.001' => '501.001'], StereoNxAccountMappingService::existingTargets([$legacy, $native]));
        self::assertSame(['501.001' => '501.001'], StereoNxAccountMappingService::existingTargets([$native, $legacy]));
    }

    public function testControlAccountProofRetainsAllTargetsRatherThanGuessingOne(): void
    {
        $journal = ['accounting_plan' => ['entries' => [
            ['debit' => '518001', 'credit' => '321001', 'target_debit' => '518.700', 'target_credit' => '321.700'],
            ['debit' => '311002', 'credit' => '602', 'target_debit' => '311.800', 'target_credit' => '602'],
            ['debit' => '321002', 'credit' => '221', 'target_debit' => '321.701', 'target_credit' => '221.100'],
        ]]];
        self::assertSame([321 => ['321.700', '321.701'], 311 => ['311.800']], StereoNxAccountMappingService::controlAccounts($journal));
    }

    public function testNullConfigurationHasStableDistinctFingerprint(): void
    {
        self::assertNull(StereoNxAccountMappingService::configuration(null));
        self::assertSame(StereoNxAccountMappingService::fingerprint(null), StereoNxAccountMappingService::fingerprint(null));
        self::assertNotSame(StereoNxAccountMappingService::fingerprint(null), StereoNxAccountMappingService::fingerprint(self::configuration()));
    }

    public function testFingerprintIgnoresObjectKeyOrderingAndOuterWhitespace(): void
    {
        $configuration = self::configuration();
        $reordered = ['vat' => array_reverse($configuration['vat'], true),
            'bank_accounts' => array_reverse($configuration['bank_accounts'], true),
            'accounts' => array_reverse($configuration['accounts'], true)];
        $reordered['accounts']['518001'] = ' 518.101 ';
        self::assertSame(StereoNxAccountMappingService::fingerprint($configuration), StereoNxAccountMappingService::fingerprint($reordered));
    }

    public function testNormalizedTargetsAreAuthoritativeForBankSettingsAndVatRules(): void
    {
        $configuration = self::configuration();
        $raw = $configuration;
        $raw['accounts']['518001'] = '518101';
        $raw['bank_accounts']['BANK-A'] = '221100';
        $raw['vat']['input'] = '343100';
        self::assertSame($configuration, StereoNxAccountMappingService::configuration($raw));
        self::assertSame(StereoNxAccountMappingService::fingerprint($configuration), StereoNxAccountMappingService::fingerprint($raw));
    }

    public function testSyntheticVatRoleAndExistingAlphaAccountAreLegal(): void
    {
        $configuration = self::configuration();
        $configuration['vat']['unresolved'] = '343';
        $configuration['accounts']['518001'] = '518Office';
        self::assertSame($configuration, StereoNxAccountMappingService::configuration($configuration));
    }

    #[DataProvider('changedChoices')]
    public function testEveryAccountingChoiceChangesDryRunFingerprint(string $part, string $key, ?string $value): void
    {
        $configuration = self::configuration();
        $changed = $configuration;
        $changed[$part][$key] = $value;
        self::assertNotSame(StereoNxAccountMappingService::fingerprint($configuration), StereoNxAccountMappingService::fingerprint($changed));
    }

    public static function changedChoices(): iterable
    {
        yield 'běžný účet' => ['accounts', '518001', '518.102'];
        yield 'banka' => ['bank_accounts', 'BANK-A', '221.102'];
        yield 'vstupní DPH' => ['vat', 'input', '343.110'];
        yield 'výstupní DPH' => ['vat', 'output', '343.210'];
        yield 'zúčtování DPH' => ['vat', 'settlement', '343.910'];
        yield 'neurčené DPH' => ['vat', 'unresolved', '343.800'];
    }

    #[DataProvider('malformedConfigurations')]
    public function testUntrustedMalformedConfigurationIsRejected(mixed $configuration): void
    {
        $this->expectException(StereoNxException::class);
        StereoNxAccountMappingService::configuration($configuration);
    }

    public static function malformedConfigurations(): iterable
    {
        $valid = self::configuration();
        yield 'text místo objektu' => ['invalid'];
        yield 'chybějící mapy' => [[]];
        yield 'neznámá vrchní volba' => [$valid + ['posting_year' => 2027]];
        yield 'neznámá role DPH' => [array_replace($valid, ['vat' => $valid['vat'] + ['extra' => '343.300']])];
        yield 'pole místo cíle' => [array_replace($valid, ['accounts' => ['518' => []]])];
        yield 'prázdný cíl' => [array_replace($valid, ['accounts' => ['518' => ' ']])];
        yield 'příliš dlouhý cíl po normalizaci' => [array_replace($valid, ['accounts' => ['518' => '5181234567']])];
        yield 'řídicí znak' => [array_replace($valid, ['accounts' => ['518' => "518\n100"]])];
        yield 'nečíselná analytika banky' => [array_replace($valid, ['bank_accounts' => ['BANK-A' => '221.bank']])];
        yield 'DPH jiná syntetika' => [array_replace_recursive($valid, ['vat' => ['input' => '342.100']])];
        yield 'duplicitní role DPH' => [array_replace_recursive($valid, ['vat' => ['input' => '343200']])];
    }

    private static function configuration(): array
    {
        return ['accounts' => ['321' => '321', '518001' => '518.101'],
            'bank_accounts' => ['BANK-A' => '221.100', 'BANK-B' => '221.101'],
            'vat' => ['input' => '343.100', 'output' => '343.200', 'settlement' => '343.900', 'unresolved' => null]];
    }
}
