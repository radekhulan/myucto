<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Connector;

use MyInvoice\Repository\KbPlusOAuthRepository;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnectorException;
use MyInvoice\Service\Bank\Connector\KbPlusAboBatchMapper;
use MyInvoice\Service\Bank\Connector\KbPlusApiClient;
use MyInvoice\Service\Bank\Connector\KbPlusConnector;
use MyInvoice\Service\Bank\Connector\KbPlusCredentialVault;
use MyInvoice\Service\Bank\Connector\KbPlusTransactionParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class KbPlusConnectorTest extends TestCase
{
    use \MyInvoice\Tests\Support\InlineOAuthRotation;

    private KbPlusApiClient&MockObject $api;
    private KbPlusCredentialVault $vault;
    private KbPlusConnector $connector;

    protected function setUp(): void
    {
        $this->api = $this->createMock(KbPlusApiClient::class);
        $this->vault = new KbPlusCredentialVault(
            $this->api,
            $this->createMock(KbPlusOAuthRepository::class),
            $this->createMock(SecretEncryption::class),
            $this->inlineRotationFlow(),
        );
        $this->connector = new KbPlusConnector($this->api, $this->vault, new KbPlusTransactionParser(), new KbPlusAboBatchMapper());
    }

    public function testReadOnlyConsentRefusesPaymentsBeforeCallingBank(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $this->api->expects(self::never())->method('refreshAccessToken');
        $token = $this->vault->encode($this->credentials('', 'adaa'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
        try {
            $this->connector->submitPaymentOrder($token, 'SYNTHETIC-ABO');
            self::fail('Bez souhlasu bpisp se příkaz nesmí předat bance.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_submission_unavailable', $e->errorCode);
            self::assertFalse($e->ambiguousPaymentOutcome);
        }
    }

    public function testSeparateBatchKeyWithoutBpispConsentStillRefusesPayments(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $token = $this->vault->encode($this->credentials('synthetic-batchda-key', 'adaa'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBpispConsentEnablesPaymentsWithoutSeparateBatchKey(): void
    {
        $token = $this->vault->encode($this->credentials('', 'adaa bpisp'));

        self::assertTrue($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBpispConsentWithSeparateBatchKeyCanSubmitPayments(): void
    {
        $token = $this->vault->encode($this->credentials('synthetic-batchda-key', 'adaa bpisp'));

        self::assertTrue($this->connector->canSubmitPaymentOrder($token));
    }

    public function testBasicPlanRefusesPaymentsEvenWithBpispConsent(): void
    {
        $this->api->expects(self::never())->method('submitPaymentBatch');
        $token = $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'adaa bpisp'));

        self::assertFalse($this->connector->canSubmitPaymentOrder($token));
        try {
            $this->connector->submitPaymentOrder($token, 'SYNTHETIC-ABO');
            self::fail('Varianta Basic příkazy neumí, nesmí se předat bance.');
        } catch (BankConnectorException $e) {
            self::assertSame('payment_submission_unavailable', $e->errorCode);
        }
    }

    public function testBasicPlanDownloadsStatementsOnlyUpToYesterday(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        $weekAgo = (new \DateTimeImmutable('-7 days'))->format('Y-m-d');
        $periods = [];
        $this->api->expects(self::never())->method('transactions');
        $this->api->method('statements')->willReturnCallback(
            static function (string $accessToken, string $accountId, string $from, string $to) use (&$periods): array {
                $periods[] = [$from, $to];
                return [];
            },
        );
        $token = $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'statda'));

        $this->connector->downloadStatement($token, $weekAgo, $today);
        $this->connector->downloadStatement($token, $today, $today);

        self::assertSame([[$weekAgo, $yesterday], [$yesterday, $yesterday]], $periods);
    }

    /** Basic nemá ADAA; bez souhlasu statda by KB dotaz odmítla s 401/403. */
    public function testBasicPlanWithoutStatdaConsentStopsBeforeCallingBank(): void
    {
        $this->api->expects(self::never())->method('statements');
        $this->api->expects(self::never())->method('transactions');
        $this->api->expects(self::never())->method('refreshAccessToken');
        $token = $this->vault->encode(['api_plan' => 'basic', 'access_expires_at' => 1] + $this->credentials('', 'adaa bpisp'));

        try {
            $this->connector->downloadStatement($token, '2026-09-01', '2026-09-07');
            self::fail('Basic bez souhlasu statda nesmí volat banku.');
        } catch (BankConnectorException $e) {
            self::assertSame('kb_plus_statements_unavailable', $e->errorCode);
        }
    }

    /** KM nese čísla účtů ve vnitřním formátu KB; import je musí vrátit v edičním tvaru. */
    public function testBasicPlanImportsKmStatementWithEditionAccountNumbers(): void
    {
        $this->api->method('statements')->willReturn([self::kmFile()]);
        $token = $this->vault->encode(
            ['api_plan' => 'basic', 'account_iban' => 'CZ0401000000191000000005'] + $this->credentials('', 'statda'),
        );

        $parsed = $this->connector->parseStatement($this->connector->downloadStatement($token, '2026-09-14', '2026-09-14'));

        self::assertSame('CZ0401000000191000000005', $parsed['header']['account_number']);
        self::assertSame('2026-09-14', $parsed['header']['statement_date']);
        self::assertSame('012', $parsed['header']['statement_number']);
        self::assertSame(1000.0, $parsed['header']['prev_balance']);
        self::assertSame(10989.9, $parsed['header']['curr_balance']);
        self::assertCount(2, $parsed['transactions']);
        [$credit, $fee] = $parsed['transactions'];
        self::assertSame(10000.0, $credit['amount']);
        self::assertSame('0000001000000005', $credit['counterparty_account']);
        self::assertSame('0100', $credit['counterparty_bank']);
        self::assertSame('12345', $credit['variable_symbol']);
        self::assertSame('308', $credit['constant_symbol']);
        self::assertSame('CZK', $credit['currency']);
        self::assertSame('2026-09-14', $credit['posted_at']);
        self::assertSame(-10.1, $fee['amount']);
        self::assertNull($fee['counterparty_account']);
    }

    /** Údaje uložené před zavedením volby varianty se chovají jako Plus. */
    public function testPlusAndLegacyCredentialsKeepRequestedPeriod(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $periods = [];
        $this->api->method('transactions')->willReturnCallback(
            static function (array $credentials, string $accessToken, string $accountId, string $from, string $to) use (&$periods): array {
                $periods[] = [$from, $to];
                return ['transactions' => [], 'pages' => 1];
            },
        );

        $this->connector->downloadStatement($this->vault->encode($this->credentials('', 'adaa')), $today, $today);
        $this->connector->downloadStatement($this->vault->encode(['api_plan' => 'plus'] + $this->credentials('', 'adaa')), $today, $today);

        self::assertSame([[$today, $today], [$today, $today]], $periods);
    }

    public function testAutomaticSyncIntervalFollowsPlan(): void
    {
        self::assertSame(86400, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode(['api_plan' => 'basic'] + $this->credentials('', 'adaa')),
        ));
        self::assertSame(3660, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode(['api_plan' => 'plus'] + $this->credentials('', 'adaa')),
        ));
        self::assertSame(3660, $this->connector->minimumAutomaticSyncIntervalSeconds(
            $this->vault->encode($this->credentials('', 'adaa')),
        ));
    }

    public function testUnknownPlanIsRejectedByVault(): void
    {
        $this->expectException(BankConnectorException::class);

        $this->vault->encode(['api_plan' => 'pro'] + $this->credentials('', 'adaa'));
    }

    /** Basic ukládá každý den jako originál výpisu banky, aby ho import spároval s dřívějšími pohyby z ADAA. */
    public function testBasicStatementFilesAreBankDocumentsPerDay(): void
    {
        $this->api->method('statements')->willReturn([self::kmFile()]);
        $token = $this->vault->encode(
            ['api_plan' => 'basic', 'account_iban' => 'CZ0401000000191000000005'] + $this->credentials('', 'statda'),
        );

        $files = $this->connector->statementFiles($this->connector->downloadStatement($token, '2026-09-14', '2026-09-14'));

        self::assertCount(1, $files);
        self::assertSame('gpc', $files[0]['source']);
        self::assertSame(self::kmFile(), $files[0]['content']);
        self::assertMatchesRegularExpression('/^kb-km-2026-09-14-[a-f0-9]{12}\.gpc$/D', $files[0]['filename']);
        self::assertSame('0000191000000005', $files[0]['parsed']['header']['account_number']);
        self::assertSame(10989.9, $files[0]['parsed']['header']['curr_balance']);
        self::assertSame('0000001000000005', $files[0]['parsed']['transactions'][0]['counterparty_account']);
    }

    public function testPlusStatementFilesKeepSingleApiRecord(): void
    {
        $this->api->method('transactions')->willReturn(['transactions' => [], 'pages' => 1]);
        $content = $this->connector->downloadStatement($this->vault->encode($this->credentials('', 'adaa')), '2026-09-14', '2026-09-14');

        $files = $this->connector->statementFiles($content);

        self::assertCount(1, $files);
        self::assertSame(
            ['content' => $content, 'filename' => 'kb_plus-2026-09-14-2026-09-14.json', 'source' => 'bank_api'],
            array_diff_key($files[0], ['parsed' => true]),
        );
        self::assertSame([], $files[0]['parsed']['transactions']);
    }

    /** Ověření účtu u Basic nesmí spotřebovat jedno z 50 měsíčních stažení. */
    public function testBasicAccountVerificationDoesNotCallBank(): void
    {
        $this->api->expects(self::never())->method('statements');
        $this->api->expects(self::never())->method('refreshAccessToken');
        $token = $this->vault->encode(
            ['api_plan' => 'basic', 'account_iban' => 'CZ0401000000191000000005'] + $this->credentials('', 'statda'),
        );

        self::assertSame(
            ['account_number' => 'CZ0401000000191000000005', 'bank_code' => '0100', 'currency' => 'CZK'],
            $this->connector->verifyAccount($token),
        );
    }

    /** Syntetický výpis KM účtu 19-1000000005/0100: příchozí platba a poplatek, účty ve vnitřním formátu. */
    private static function kmFile(): string
    {
        $lines = [
            '074' . '5000100000000019' . str_repeat(' ', 20) . '130926' . sprintf('%014d', 100000) . '+'
                . sprintf('%014d', 1098990) . '+' . sprintf('%014d', 1010) . '0' . sprintf('%014d', 1000000) . '0'
                . '012' . '140926' . 'CZ040100' . 'MB' . str_repeat(' ', 4),
            '075' . '5000100000000019' . '5000100000000000' . '0914000000012' . sprintf('%012d', 1000000) . '2'
                . sprintf('%010d', 12345) . '00' . '0100' . '0308' . str_repeat('0', 10) . '000000'
                . str_pad('SYNTETICKY ODBERATEL', 20) . '0' . str_repeat(' ', 4) . '140926',
            '075' . '5000100000000019' . str_repeat('0', 16) . '0914000000012' . sprintf('%012d', 1010) . '1'
                . str_repeat('0', 30) . '000000'
                . str_pad('POPLATEK', 20) . '0' . str_repeat(' ', 4) . '140926',
        ];
        return implode("\r\n", $lines) . "\r\n";
    }

    /** @return array<string,mixed> */
    private function credentials(string $batchKey, string $scope): array
    {
        return [
            'version' => 1,
            'supplier_id' => 7,
            'connection_id' => 19,
            'oauth_api_key' => 'synthetic-oauth-key',
            'adaa_api_key' => 'synthetic-adaa-key',
            'batchda_api_key' => $batchKey,
            'client_id' => 'synthetic-client',
            'client_secret' => 'synthetic-client-secret',
            'redirect_uri' => 'https://example.invalid/callback',
            'scope' => $scope,
            'refresh_token' => 'synthetic-refresh-token-0001',
            'access_token' => 'synthetic-access-token-0001',
            'access_expires_at' => time() + 180,
            'account_id' => 'synthetic-account',
            'account_iban' => 'CZ0000000000000000000000',
            'account_currency' => 'CZK',
            'call_guard_key' => str_repeat('g', 43),
        ];
    }
}
