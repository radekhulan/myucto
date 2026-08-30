<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Action\Submission\SubmissionOutboxAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Odeslání datovkou v relaci potvrzené Mobilním klíčem.
 *
 * Backend to uměl už od `c6fb8c3b9`, ale nikdo mu živou relaci nepředal:
 * odesílací akce si stavěla kontext z uloženého certifikátu, u kterého
 * {@see DirectIsdsInboxTransport::hasConfirmedSession()} vrací `false`, takže
 * `SessionAwareIsdsTransport` vždycky sáhl po náhradní cestě a uživateli zbylo
 * „otevřete si datovku a odešlete to sami".
 *
 * Test drží dvě věci, na kterých ta cesta stojí:
 *  * potvrzená relace se do odeslání skutečně dostane, a to jako `mobile_key`
 *    se session cookie — tedy tvar, který přímý transport pustí;
 *  * dokud člověk potvrzení v mobilu neudělá, podání se NIJAK nedotkneme.
 */
final class OutboxMobileKeyDispatchTest extends TestCase
{
    public function testConfirmedSessionIsHandedToTheDispatch(): void
    {
        $captured = null;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSend')->willReturnCallback(
            function (int $supplierId, int $id, int $userId, ChannelContext $context) use (&$captured): array {
                $captured = $context;
                return ['dispatched' => true, 'row' => ['id' => $id]];
            },
        );

        $response = $this->action($outbox, $this->confirmedFlow())->mobileKeyConfirm(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(ChannelContext::class, $captured);
        self::assertSame('mobile_key', $captured->credentials->authMode);
        self::assertTrue(
            DirectIsdsInboxTransport::hasConfirmedSession($captured),
            'Kontext musí projít bránou přímého transportu, jinak se odeslání tiše svede na náhradní cestu.',
        );
    }

    public function testUnconfirmedSessionDoesNotTouchTheSubmission(): void
    {
        $calls = 0;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSend')->willReturnCallback(
            function () use (&$calls): array {
                $calls++;
                return ['dispatched' => true, 'row' => []];
            },
        );

        $response = $this->action($outbox, $this->pendingFlow())->mobileKeyConfirm(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $calls, 'Dokud člověk relaci nepotvrdí, podání se nesmí odeslat.');
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('result', $body);
        self::assertNull($body['result']);
        self::assertSame(1, $body['state']);
    }

    public function testMissingFlowTokenIsRejectedBeforeAnythingHappens(): void
    {
        $calls = 0;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSend')->willReturnCallback(
            function () use (&$calls): array {
                $calls++;
                return ['dispatched' => true, 'row' => []];
            },
        );

        $response = $this->action($outbox, $this->confirmedFlow())->mobileKeyConfirm(
            $this->request(['environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(0, $calls);
    }

    private function confirmedFlow(): MobileKeyIsdsAuthenticator
    {
        $authenticator = $this->createStub(MobileKeyIsdsAuthenticator::class);
        $authenticator->method('continue')->willReturn([
            'state' => 2,
            'description' => 'Přihlášení potvrzeno.',
            'context' => new ChannelContext(
                7,
                'test',
                new ChannelCredentials(
                    boxId: '',
                    authMode: 'mobile_key',
                    sessionCookie: SensitiveValue::fromProducer(static fn (): string => 'IPCZ-X-COOKIE=abc'),
                ),
            ),
        ]);

        return $authenticator;
    }

    private function pendingFlow(): MobileKeyIsdsAuthenticator
    {
        $authenticator = $this->createStub(MobileKeyIsdsAuthenticator::class);
        $authenticator->method('continue')->willReturn([
            'state' => 1,
            'description' => 'Čeká se na potvrzení v mobilu.',
            'context' => null,
        ]);

        return $authenticator;
    }

    private function action(
        SubmissionOutboxService $outbox,
        MobileKeyIsdsAuthenticator $authenticator,
    ): SubmissionOutboxAction {
        return new SubmissionOutboxAction(
            $outbox,
            $this->createStub(SubmissionCredentialService::class),
            $this->createStub(ActivityLogger::class),
            $authenticator,
            $this->createStub(IsdsMobileCredentialService::class),
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/submissions/outbox/42/mobile-key/confirm')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
