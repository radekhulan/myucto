<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\PurchaseInvoice\BankPaymentOrderSubmissionAction;
use MyInvoice\Action\Settings\BankConnectionAction;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\EffectiveRole;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BankConnectorAuthorizationTest extends TestCase
{
    public function testCsasOnboardingRequiresSessionAndWriteBeforeServiceAccess(): void
    {
        $action = (new \ReflectionClass(\MyInvoice\Action\Settings\CsasOnboardingAction::class))->newInstanceWithoutConstructor();
        $readonly = $this->request(['settings.bank_accounts' => AccessLevel::READ->value])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session');
        $this->assertForbidden($action->start($readonly, new Response(), ['currencyId' => 1]));
        $this->assertForbidden($action->callback($readonly, new Response()));
        $token = $this->request(['settings.bank_accounts' => AccessLevel::WRITE->value])
            ->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'token');
        $this->assertForbidden($action->status($token, new Response(), ['currencyId' => 1]));
        $this->assertForbidden($action->start($token, new Response(), ['currencyId' => 1]));
        $this->assertForbidden($action->callback($token, new Response()));
    }

    public function testPayrollSubmissionRequiresBothPermissionsBeforeAccessingServices(): void
    {
        $action = (new \ReflectionClass(\MyInvoice\Action\Payroll\PayrollBankSubmissionAction::class))->newInstanceWithoutConstructor();
        foreach ([['payroll.payments' => AccessLevel::WRITE->value], ['settings.bank_accounts' => AccessLevel::WRITE->value],
            ['payroll.payments' => AccessLevel::READ->value, 'settings.bank_accounts' => AccessLevel::READ->value]] as $permissions) {
            $request = $this->request($permissions)->withAttribute(\MyInvoice\Middleware\AuthMiddleware::ATTR_METHOD, 'session');
            $this->assertForbidden($action->post($request, new Response(), ['batchId' => 1]));
        }
    }

    public function testKbOnboardingMutationsRequireWriteBeforeServiceAccess(): void
    {
        $action = (new \ReflectionClass(\MyInvoice\Action\Settings\KbPlusOnboardingAction::class))->newInstanceWithoutConstructor();
        $request = $this->request(['settings.bank_accounts' => AccessLevel::READ->value]);
        $this->assertForbidden($action->start($request, new Response(), ['currencyId' => 1]));
        $this->assertForbidden($action->registrationCallback($request, new Response()));
        $this->assertForbidden($action->oauthCallback($request, new Response()));
    }

    public function testPaymentOrderDeletionRequiresWriteBeforeServiceAccess(): void
    {
        $action = (new \ReflectionClass(\MyInvoice\Action\PurchaseInvoice\PaymentOrderAction::class))->newInstanceWithoutConstructor();
        $this->assertForbidden($action->delete(
            $this->request(['purchase_invoices.payment_orders' => AccessLevel::READ->value]),
            new Response(),
            ['id' => 1],
        ));
    }

    public function testBankConnectionListRequiresReadBeforeTenantAndServiceAccess(): void
    {
        $response = $this->bankConnectionAction()->list(
            $this->request([]),
            new Response(),
        );

        $this->assertForbidden($response);
    }

    public function testBankConnectionMutationsRequireWriteBeforeTenantAndServiceAccess(): void
    {
        $action = $this->bankConnectionAction();
        $request = $this->request([
            'settings.bank_accounts' => AccessLevel::READ->value,
        ]);

        foreach (['put', 'delete', 'sync'] as $method) {
            $response = $action->{$method}($request, new Response(), ['currencyId' => 1]);
            $this->assertForbidden($response);
        }
    }

    public function testPaymentOrderGetRequiresReadBeforeTenantAndServiceAccess(): void
    {
        $response = $this->paymentOrderAction()->get(
            $this->request([]),
            new Response(),
            ['orderId' => 1],
        );

        $this->assertForbidden($response);
    }

    public function testPaymentOrderPostRequiresWriteOnBothPermissions(): void
    {
        $permissionSets = [
            'missing bank connection write' => [
                'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
                'settings.bank_accounts' => AccessLevel::READ->value,
            ],
            'missing payment order write' => [
                'purchase_invoices.payment_orders' => AccessLevel::READ->value,
                'settings.bank_accounts' => AccessLevel::WRITE->value,
            ],
            'missing payment order permission' => [
                'settings.bank_accounts' => AccessLevel::WRITE->value,
            ],
            'missing bank connection permission' => [
                'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
            ],
        ];
        $action = $this->paymentOrderAction();

        foreach ($permissionSets as $label => $permissions) {
            $response = $action->post(
                $this->request($permissions),
                new Response(),
                ['orderId' => 1],
            );
            $this->assertForbidden($response, $label);
        }
    }

    public function testInactiveRoleCannotReachEitherBankService(): void
    {
        $permissions = [
            'settings.bank_accounts' => AccessLevel::WRITE->value,
            'purchase_invoices.payment_orders' => AccessLevel::WRITE->value,
        ];
        $request = $this->request($permissions, false);

        $this->assertForbidden($this->bankConnectionAction()->list($request, new Response()));
        $this->assertForbidden($this->paymentOrderAction()->post(
            $request,
            new Response(),
            ['orderId' => 1],
        ));
    }

    /** @param array<string,int> $permissions */
    private function request(array $permissions, bool $active = true): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/test')
            ->withParsedBody(['connection_id' => 1])
            ->withAttribute('auth.effective_role', new EffectiveRole(
                10,
                'Testovací role',
                'staff',
                $active,
                $permissions,
            ));
    }

    private function bankConnectionAction(): BankConnectionAction
    {
        return (new \ReflectionClass(BankConnectionAction::class))->newInstanceWithoutConstructor();
    }

    private function paymentOrderAction(): BankPaymentOrderSubmissionAction
    {
        return (new \ReflectionClass(BankPaymentOrderSubmissionAction::class))->newInstanceWithoutConstructor();
    }

    private function assertForbidden(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame(403, $response->getStatusCode(), $message);
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('forbidden', $body['error']['code'] ?? null, $message);
    }
}
