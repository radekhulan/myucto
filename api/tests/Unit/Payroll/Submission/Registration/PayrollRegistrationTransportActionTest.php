<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use MyInvoice\Action\Payroll\PayrollRegistrationTransportAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Repository\Payroll\PayrollModuleStateRepository;
use MyInvoice\Service\Payroll\PayrollModuleAccess;
use MyInvoice\Service\Payroll\PayrollProductionGate;
use MyInvoice\Service\Payroll\PayrollProductionGateException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationTransportService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class PayrollRegistrationTransportActionTest extends TestCase
{
    public function testBearerTokenCannotTriggerRegistrationTransport(): void
    {
        $transport = $this->createMock(PayrollRegistrationTransportService::class);
        $transport->expects(self::never())->method('send');
        $access = $this->createMock(PayrollModuleAccess::class);
        $access->expects(self::never())->method('isEnabled');
        $request = $this->request('bearer')
            ->withHeader('Idempotency-Key', 'must-not-run');

        $response = (new PayrollRegistrationTransportAction(
            $transport,
            $access,
            $this->openGate(),
        ))->send(
            $request,
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('session_required', $this->json($response)['error']['code']);
    }

    public function testSessionWithoutIdempotencyKeyCannotTriggerTransport(): void
    {
        $transport = $this->createMock(PayrollRegistrationTransportService::class);
        $transport->expects(self::never())->method('send');
        $access = $this->createMock(PayrollModuleAccess::class);
        $access->expects(self::once())->method('isEnabled')->with(11)->willReturn(true);

        $response = (new PayrollRegistrationTransportAction(
            $transport,
            $access,
            $this->openGate(),
        ))->send(
            $this->request('session'),
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', $this->json($response)['error']['code']);
    }

    public function testOneAccountantCanExplicitlyTriggerOneScopedSend(): void
    {
        $transport = $this->createMock(PayrollRegistrationTransportService::class);
        $transport->expects(self::once())
            ->method('send')
            ->with(11, 'test', 42, 'accountant-click-1', 9)
            ->willReturn([
                'agenda_code' => 'PREZEC26',
                'submission_class' => 'CSSZ_PREZEC',
                'payload_sha256' => str_repeat('a', 64),
                'attempt' => ['id' => 7, 'status' => 'awaiting_protocol'],
                'acknowledgement' => null,
                'settled' => false,
            ]);
        $access = $this->createMock(PayrollModuleAccess::class);
        $access->expects(self::once())->method('isEnabled')->with(11)->willReturn(true);
        $request = $this->request('session')
            ->withHeader('Idempotency-Key', 'accountant-click-1')
            ->withParsedBody(['environment' => 'test']);

        // SKUTEČNÁ brána, ne mock: `PayrollProductionGate` je final a BypassFinals
        // odstraňuje `final` jen při načtení třídy — v plné sadě ji stihne načíst
        // dřívější test a zdvojení skončí na ClassIsFinalException.
        $gate = new PayrollProductionGate(
            $this->createStub(PayrollModuleStateRepository::class),
            releasedOverride: false,
        );
        $response = (new PayrollRegistrationTransportAction(
            $transport,
            $access,
            $gate,
        ))->send(
            $request,
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PREZEC26', $this->json($response)['agenda_code']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testSessionCanResumeTheLatestAttemptWithoutPollingCssz(): void
    {
        $transport = $this->createMock(PayrollRegistrationTransportService::class);
        $transport->expects(self::once())
            ->method('status')
            ->with(11, 'test', 42)
            ->willReturn([
                'agenda_code' => 'PREZEC26',
                'submission_class' => 'CSSZ_PREZEC',
                'attempt' => ['id' => 7, 'status' => 'awaiting_protocol'],
            ]);
        $access = $this->createMock(PayrollModuleAccess::class);
        $access->expects(self::once())->method('isEnabled')->with(11)->willReturn(true);
        $request = $this->request('session')
            ->withMethod('GET')
            ->withQueryParams(['environment' => 'test']);

        $response = (new PayrollRegistrationTransportAction(
            $transport,
            $access,
            $this->openGate(),
        ))->status(
            $request,
            new Response(),
            ['submissionId' => '42'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(7, $this->json($response)['attempt']['id']);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testUnqualifiedProductionRegistrationDispatchIsRejected(): void
    {
        $transport = $this->createMock(PayrollRegistrationTransportService::class);
        $transport->expects(self::never())->method('send');
        $access = $this->createStub(PayrollModuleAccess::class);
        $access->method('isEnabled')->willReturn(true);
        // SKUTEČNÁ brána, ne mock: `PayrollProductionGate` je final a BypassFinals
        // odstraňuje `final` jen při načtení třídy — v plné sadě ji stihne načíst
        // dřívější test a zdvojení skončí na ClassIsFinalException.
        $gate = new PayrollProductionGate(
            $this->createStub(PayrollModuleStateRepository::class),
            releasedOverride: false,
        );
        $request = $this->request('session')
            ->withHeader('Idempotency-Key', 'production-must-not-run')
            ->withParsedBody(['environment' => 'production']);

        $response = (new PayrollRegistrationTransportAction(
            $transport,
            $access,
            $gate,
        ))->send($request, new Response(), ['submissionId' => '42']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(
            PayrollProductionGateException::ERROR_CODE,
            $this->json($response)['error']['code'],
        );
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/registration-transport/42')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 11)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 9, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method);
    }

    /** @return array<string,mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Brána, která nic neblokuje — tyhle testy ověřují jiné pojistky
     * (bearer token, idempotenční klíč), ne uvolnění produktu.
     *
     * Skutečná instance, ne stub: `PayrollProductionGate` je final a
     * BypassFinals odstraňuje `final` jen při načtení třídy, takže
     * v plné sadě zdvojení selže podle pořadí testů.
     */
    private function openGate(): PayrollProductionGate
    {
        return new PayrollProductionGate(
            $this->createStub(PayrollModuleStateRepository::class),
            releasedOverride: true,
        );
    }
}
