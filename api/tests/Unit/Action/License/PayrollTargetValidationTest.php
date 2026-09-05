<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\License;

use MyInvoice\Action\License\PayrollChangeAction;
use MyInvoice\Action\License\PayrollQuoteAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\License\LicenseService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PayrollTargetValidationTest extends TestCase
{
    /** @return iterable<string,array{string,int}> */
    public static function invalidTargets(): iterable
    {
        yield 'negative employees' => ['payroll_employees_target', -1];
        yield 'employees overflow' => ['payroll_employees_target', 4294967296];
        yield 'zero users' => ['payroll_users_target', 0];
        yield 'users overflow' => ['payroll_users_target', 101];
    }

    #[DataProvider('invalidTargets')]
    public function testQuoteRejectsTargetsOutsideLicensedRange(string $field, int $value): void
    {
        $service = (new \ReflectionClass(LicenseService::class))->newInstanceWithoutConstructor();
        $action = new PayrollQuoteAction($service);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/payroll/quote')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin'])
            ->withParsedBody(['enabled' => true, $field => $value]);

        $response = $action($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    #[DataProvider('invalidTargets')]
    public function testChangeRejectsTargetsOutsideLicensedRange(string $field, int $value): void
    {
        $service = (new \ReflectionClass(LicenseService::class))->newInstanceWithoutConstructor();
        $action = new PayrollChangeAction($service);
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/license/payroll')
            ->withAttribute(AuthMiddleware::ATTR_USER, ['role' => 'admin'])
            ->withParsedBody(['enabled' => true, 'quote_token' => 'quote', $field => $value]);

        $response = $action($request, new Response());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->errorCode($response));
    }

    private function errorCode(Response $response): ?string
    {
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $body['error']['code'] ?? null;
    }
}
