<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Action\Submission\SubmissionOutboxAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\ChannelCredentials;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Jedno potvrzení Mobilního klíče → víc odeslaných podání.
 *
 * Bez tohohle by účetní musela potvrzovat v mobilu zvlášť pro ČSSZ a pro
 * každou ze sedmi zdravotních pojišťoven — osm potvrzení měsíčně.
 */
final class OutboxMobileKeyBatchDispatchTest extends TestCase
{
    public function testConfirmedSessionSendsEveryRequestedIdAndThenLogsOut(): void
    {
        $sentIds = [];
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSendBatch')->willReturnCallback(
            function (int $supplierId, array $ids, int $userId, ChannelContext $context) use (&$sentIds): array {
                $sentIds = $ids;
                return array_map(static fn (int $id): array => [
                    'id' => $id,
                    'dispatched' => true,
                    'row' => ['id' => $id],
                    'error_code' => null,
                    'error_message' => null,
                ], $ids);
            },
        );

        $loggedOut = false;
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
        $authenticator->method('logout')->willReturnCallback(function () use (&$loggedOut): void {
            $loggedOut = true;
        });

        $response = $this->action($outbox, $authenticator)->mobileKeyConfirmBatch(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test', 'outbox_ids' => [11, 12, 13]]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([11, 12, 13], $sentIds);
        self::assertTrue($loggedOut, 'Relace se musí odhlásit i po úspěšném odeslání.');
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(3, $body['results']);
        self::assertTrue($body['results'][0]['dispatched']);
    }

    public function testOneFailureDoesNotHideTheOtherResults(): void
    {
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSendBatch')->willReturn([
            ['id' => 1, 'dispatched' => true, 'row' => ['id' => 1], 'error_code' => null, 'error_message' => null],
            ['id' => 2, 'dispatched' => false, 'row' => null, 'error_code' => 'submission_not_found', 'error_message' => 'Podání ve frontě není.'],
        ]);
        $authenticator = $this->confirmedFlow();

        $response = $this->action($outbox, $authenticator)->mobileKeyConfirmBatch(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test', 'outbox_ids' => [1, 2]]),
            new Response(),
        );

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['results'][0]['dispatched']);
        self::assertFalse($body['results'][1]['dispatched']);
        self::assertSame('submission_not_found', $body['results'][1]['error_code']);
    }

    public function testUnconfirmedSessionDoesNotTouchAnySubmissionOrLogout(): void
    {
        $calls = 0;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSendBatch')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            return [];
        });
        $authenticator = $this->createMock(MobileKeyIsdsAuthenticator::class);
        $authenticator->method('continue')->willReturn([
            'state' => 1,
            'description' => 'Čeká se na potvrzení v mobilu.',
            'context' => null,
        ]);
        $authenticator->expects(self::never())->method('logout');

        $response = $this->action($outbox, $authenticator)->mobileKeyConfirmBatch(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test', 'outbox_ids' => [1]]),
            new Response(),
        );

        self::assertSame(0, $calls);
        $body = json_decode((string) $response->getBody(), true);
        self::assertNull($body['results']);
    }

    public function testEmptyIdListIsRejectedBeforeStartingTheFlow(): void
    {
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $authenticator = $this->confirmedFlow();

        $response = $this->action($outbox, $authenticator)->mobileKeyConfirmBatch(
            $this->request(['flow_token' => 'flow-abc', 'environment' => 'test', 'outbox_ids' => []]),
            new Response(),
        );

        self::assertSame(400, $response->getStatusCode());
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
            ->createServerRequest('POST', '/api/submissions/outbox/mobile-key/confirm-batch')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
