<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\License;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\License\LicenseClient;
use PHPUnit\Framework\TestCase;

final class LicenseClientPayrollContractTest extends TestCase
{
    public function testActivationAndRenewalReportMeasuredPayrollUsage(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = new LicenseClient(new Config([]), http: new Client(['handler' => HandlerStack::create($mock)]));

        $client->activate('key', 'iid', 'fingerprint', '1.2.3', false, 4, 2, 26, 3);
        $activation = json_decode((string) $mock->getLastRequest()?->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(26, $activation['payroll_employees_active']);
        self::assertSame(3, $activation['payroll_users_active']);

        $client->renew('key', 'iid', 2, 'nonce', 4, 2, 27, 5, '1.2.3');
        $renewal = json_decode((string) $mock->getLastRequest()?->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(27, $renewal['payroll_employees_active']);
        self::assertSame(5, $renewal['payroll_users_active']);
    }

    public function testPayrollChangeKeepsMeasuredUsageSeparateFromRequestedCapacity(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"ok":true,"quote_token":"quote"}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $client = new LicenseClient(new Config([]), http: new Client(['handler' => HandlerStack::create($mock)]));

        $client->payrollQuote('key', 'iid', true, 25, 2, 50, 4);
        $quoteRequest = $mock->getLastRequest();
        self::assertSame('api/license/payroll/quote', $quoteRequest?->getUri()->getPath());
        $quote = json_decode((string) $quoteRequest?->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            'license_key' => 'key',
            'instance_id' => 'iid',
            'enabled' => true,
            'payroll_employees_active' => 25,
            'payroll_users_active' => 2,
            'payroll_employees_target' => 50,
            'payroll_users_target' => 4,
        ], $quote);

        $client->payrollChange('key', 'iid', true, 25, 2, 50, 4, 'quote');
        $changeRequest = $mock->getLastRequest();
        self::assertSame('api/license/payroll', $changeRequest?->getUri()->getPath());
        $change = json_decode((string) $changeRequest?->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(25, $change['payroll_employees_active']);
        self::assertSame(2, $change['payroll_users_active']);
        self::assertSame(50, $change['payroll_employees_target']);
        self::assertSame(4, $change['payroll_users_target']);
        self::assertSame('quote', $change['quote_token']);
    }
}
