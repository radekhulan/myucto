<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\PurchaseInvoice\BankPaymentOrderSubmissionAction;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\BankConnectionRepository;
use MyInvoice\Repository\BankPaymentOrderSubmissionRepository;
use MyInvoice\Repository\PaymentOrderRepository;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Bank\Connector\BankConnector;
use MyInvoice\Service\Bank\Connector\BankConnectorCallGuard;
use MyInvoice\Service\Bank\Connector\BankConnectorRegistry;
use MyInvoice\Service\Bank\Connector\BankPaymentOrderSubmissionService;
use MyInvoice\Service\Bank\Connector\BankPaymentSubmissionService;
use MyInvoice\Service\Payment\PaymentOrderService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class BankPaymentOrderSubmissionActionTest extends TestCase
{
    #[DataProvider('outcomes')]
    public function testHttpStatusFollowsPersistedSubmissionOutcome(string $status, int $expectedHttp, bool $ok): void
    {
        $response = $this->action($status)->post($this->request(), new Response(), ['orderId' => 501]);

        self::assertSame($expectedHttp, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame($status, $body['submission']['status'] ?? null);
        self::assertSame($ok, !isset($body['error']));
    }

    public static function outcomes(): iterable
    {
        yield 'čeká na autorizaci' => ['accepted_awaiting_authorization', 202, true];
        yield 'banka převzala soubor k importu' => ['import_started', 202, true];
        yield 'banka odmítla' => ['rejected', 422, true];
        yield 'nejistý výsledek' => ['unknown', 502, true];
    }

    public function testSigningAndRefreshNeedSessionAndBothWritePermissions(): void
    {
        $action = (new \ReflectionClass(BankPaymentOrderSubmissionAction::class))->newInstanceWithoutConstructor();
        $cases = [
            'API token' => [$this->request()->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'token')],
            'jen čtení účtů' => [$this->request([
                'purchase_invoices.payment_orders' => AccessLevel::WRITE->value, 'settings.bank_accounts' => AccessLevel::READ->value,
            ])->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')],
            'jen čtení příkazů' => [$this->request([
                'purchase_invoices.payment_orders' => AccessLevel::READ->value, 'settings.bank_accounts' => AccessLevel::WRITE->value,
            ])->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session')],
        ];
        foreach ($cases as $label => [$request]) {
            foreach (['sign', 'refresh'] as $method) {
                $response = $action->{$method}($request, new Response(), ['orderId' => 501]);
                self::assertSame(403, $response->getStatusCode(), $label . ' ' . $method);
            }
        }
    }

    public function testSubmissionStatusesMapToSemanticHttpCodes(): void
    {
        foreach (['accepted_awaiting_authorization' => 202, 'import_started' => 202, 'awaiting_signature' => 202, 'signed' => 202,
            'completed' => 202, 'rejected' => 422, 'partially_rejected' => 422, 'expired' => 422, 'unknown' => 502, 'creating' => 502] as $status => $http) {
            self::assertSame($http, \MyInvoice\Service\Bank\Connector\BankPaymentSubmissionHttp::forStatus($status), $status);
        }
        foreach (['payment_order_not_domestic' => 422, 'csas_payments_scope_missing' => 422, 'payment_order_invalid' => 422,
            'payment_signature_unavailable' => 409, 'bank_connection_busy' => 409, 'payment_status_unavailable' => 502,
            'payment_order_not_found' => 404] as $code => $http) {
            self::assertSame($http, \MyInvoice\Service\Bank\Connector\BankPaymentSubmissionHttp::forError($code), $code);
        }
    }

    public function testPayrollSignatureErrorsUseSameHttpStatusesAsPaymentOrders(): void
    {
        $payroll = (new \ReflectionClass(\MyInvoice\Action\Payroll\PayrollBankSubmissionAction::class))->newInstanceWithoutConstructor();
        $failure = new \ReflectionMethod($payroll, 'signatureFailure');
        foreach (['payment_signature_failed' => 502, 'payment_status_unavailable' => 502, 'payment_order_no_longer_payable' => 409,
            'payment_signature_unavailable' => 409, 'csas_payments_scope_missing' => 422, 'payment_order_not_found' => 404] as $code => $http) {
            $response = $failure->invoke($payroll, new Response(), new \MyInvoice\Service\Bank\Connector\BankConnectorOperationException($code));
            self::assertSame($http, $response->getStatusCode(), $code);
            self::assertSame(\MyInvoice\Service\Bank\Connector\BankPaymentSubmissionHttp::forError($code), $response->getStatusCode(), $code);
        }
    }

    public function testDuplicateLinesAreReturnedToTheClient(): void
    {
        $action = (new \ReflectionClass(BankPaymentOrderSubmissionAction::class))->newInstanceWithoutConstructor();
        $failure = new \ReflectionMethod($action, 'failure');
        $response = $failure->invoke($action, new Response(),
            new \MyInvoice\Service\Bank\Connector\BankConnectorOperationException('payment_order_duplicate_items', ['duplicate_lines' => [[1, 3]]]));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame([[1, 3]], json_decode((string) $response->getBody(), true)['error']['duplicate_lines'] ?? null);
    }

    private function action(string $finalStatus): BankPaymentOrderSubmissionAction
    {
        $connections = $this->createMock(BankConnectionRepository::class);
        $submissions = $this->createMock(BankPaymentOrderSubmissionRepository::class);
        $orders = $this->createMock(PaymentOrderRepository::class);
        $paymentOrders = $this->createMock(PaymentOrderService::class);
        $registry = $this->createMock(BankConnectorRegistry::class);
        $calls = $this->createMock(BankConnectorCallGuard::class);
        $secrets = $this->createMock(SecretEncryption::class);

        $connector = new class ($finalStatus) implements BankConnector {
            public function __construct(private readonly string $status) {}
            public function provider(): string { return 'rb'; }
            public function downloadStatement(#[\SensitiveParameter] string $token, string $from, string $to): string
            {
                throw new \LogicException('Výpis se v testu odeslání nestahuje.');
            }
            public function submitPaymentOrder(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $abo): array
            {
                return match ($this->status) {
                    'import_started' => ['accepted' => true, 'reference' => 'SYNTHETIC-ASYNC', 'status' => 'import_started'],
                    'accepted_awaiting_authorization' => ['accepted' => true, 'reference' => 'SYNTHETIC-REF'],
                    'rejected' => throw new \MyInvoice\Service\Bank\Connector\BankConnectorException('payment_rejected', 'safe', false, 422),
                    default => throw new \MyInvoice\Service\Bank\Connector\BankConnectorException('remote_unavailable', 'safe', true),
                };
            }
        };

        $connections->method('findWithCredentialById')->willReturn([
            'id' => 101, 'supplier_id' => 1, 'currency_id' => 11, 'provider' => 'rb',
            'enabled' => true, 'has_token' => true, 'token_ciphertext' => 'enc:v2:synthetic', 'is_active' => true,
            'account_code' => 'CZK', 'bank_code' => '5500', 'account_number' => '1000000005', 'iban' => null,
            'verified_account_number' => '1000000005', 'verified_bank_code' => '5500', 'verified_currency' => 'CZK',
        ]);
        $calls->method('withConnectionLock')->willReturnCallback(static fn (int $s, int $c, callable $cb): mixed => $cb());
        $calls->method('call')->willReturnCallback(static fn (string $t, callable $cb): mixed => $cb());
        $orders->method('find')->willReturn([
            'id' => 501, 'supplier_id' => 1, 'currency' => 'CZK', 'payer_currency_id' => 11,
            'payer_account_number' => '1000000005', 'payer_bank_code' => '5500', 'mark_paid' => false,
            'payment_date' => date('Y-m-d'),
            'items' => [['payee_account_number' => '1000000005', 'payee_bank_code' => '0100', 'payee_iban' => null, 'currency' => 'CZK']],
        ]);
        $orders->method('allItemsStillPayable')->willReturn(true);
        $paymentOrders->method('download')->willReturn(['bytes' => 'SYNTHETIC-ABO']);
        $secrets->method('validateKey')->willReturn(null);
        $secrets->method('decryptFor')->willReturn('synthetic-token');
        $registry->method('get')->willReturn($connector);
        $registry->method('supportsBankCode')->willReturn(true);

        $row = static fn (string $status): array => [
            'id' => 901, 'payment_order_id' => 501, 'connection_id' => 101, 'provider' => 'rb', 'status' => $status,
            'provider_reference' => null, 'error_code' => null, 'accepted_count' => null, 'rejected_count' => null,
            'created_at' => '2026-10-22 10:00:00', 'submitted_at' => null,
        ];
        $submissions->method('find')->willReturnOnConsecutiveCalls(null, $row($finalStatus));
        $submissions->method('begin')->willReturn(['created' => true, 'submission' => $row('unknown')]);

        $engine = new BankPaymentSubmissionService($connections, $submissions, $registry, $calls, $secrets);
        return new BankPaymentOrderSubmissionAction(
            new BankPaymentOrderSubmissionService($engine, $orders, $paymentOrders),
            new \MyInvoice\Service\Bank\Connector\BankPaymentSignatureService($submissions, $engine, $calls,
                $this->createMock(\MyInvoice\Service\OAuth\OAuthClientFlow::class), $this->createMock(\MyInvoice\Service\ActivityLogger::class)),
        );
    }

    /** @param array<string,int>|null $permissions */
    private function request(?array $permissions = null): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/purchase-invoices/payment-orders/501/submit')
            ->withParsedBody(['connection_id' => 101])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 1)
            ->withAttribute('auth.effective_role', new EffectiveRole(10, 'Testovací role', 'staff', true, $permissions ?? [
                'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
                'settings.bank_accounts' => AccessLevel::WRITE->value,
            ]));
    }
}
