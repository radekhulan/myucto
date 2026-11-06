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
use MyInvoice\Service\Submission\Channel\Isds\SmsIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\SensitiveValue;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Hromadné odeslání jménem a heslem, SMS kódem a systémovým certifikátem.
 *
 * Dávka nabízela jen Mobilní klíč, kdežto jednotlivé podání všech pět
 * způsobů. Účetní s účtem jen na heslo tak přehledy pojišťovnám připravila
 * a hromadně odeslat nemohla.
 */
final class OutboxBatchSendMethodsTest extends TestCase
{
    public function testPasswordBatchSendsEveryIdWithOneLogin(): void
    {
        $captured = null;
        $outbox = $this->recordingOutbox($sentIds, $captured);

        $response = $this->action($outbox)->passwordSendBatch(
            $this->request([
                'environment' => 'test',
                'username' => 'synthetic-user',
                'password' => 'synthetic-password',
                'outbox_ids' => [21, 22, 22, 23],
            ]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([21, 22, 23], $sentIds);
        self::assertInstanceOf(ChannelContext::class, $captured);
        self::assertSame('password', $captured->credentials->authMode);
        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(3, $body['results']);
        self::assertTrue($body['results'][0]['dispatched']);
    }

    public function testPasswordBatchRejectsMissingPasswordBeforeSending(): void
    {
        $sentIds = null;
        $captured = null;
        $outbox = $this->recordingOutbox($sentIds, $captured);

        $response = $this->action($outbox)->passwordSendBatch(
            $this->request(['environment' => 'test', 'username' => 'u', 'password' => '', 'outbox_ids' => [1]]),
            new Response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($sentIds);
    }

    public function testCertificateBatchRequiresExplicitAuthorization(): void
    {
        $sentIds = null;
        $captured = null;
        $outbox = $this->recordingOutbox($sentIds, $captured);

        $response = $this->action($outbox)->certificateSendBatch(
            $this->request(['environment' => 'test', 'outbox_ids' => [1, 2]]),
            new Response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($sentIds);
    }

    public function testCertificateBatchSendsWithCompanyCertificate(): void
    {
        $sentIds = null;
        $captured = null;
        $outbox = $this->recordingOutbox($sentIds, $captured);
        $credentials = $this->createStub(SubmissionCredentialService::class);
        $credentials->method('unlock')->willReturn($this->context('certificate'));

        $response = $this->action($outbox, $credentials)->certificateSendBatch(
            $this->request(['environment' => 'test', 'authorized' => true, 'outbox_ids' => [5, 6]]),
            new Response(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([5, 6], $sentIds);
        self::assertSame('certificate', $captured?->credentials->authMode);
    }

    public function testEmptyBatchIsRejected(): void
    {
        $sentIds = null;
        $captured = null;
        $outbox = $this->recordingOutbox($sentIds, $captured);

        $response = $this->action($outbox)->passwordSendBatch(
            $this->request(['environment' => 'test', 'username' => 'u', 'password' => 'p', 'outbox_ids' => []]),
            new Response(),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertNull($sentIds);
    }

    /** @param-out list<int>|null $sentIds */
    private function recordingOutbox(?array &$sentIds, ?ChannelContext &$captured): SubmissionOutboxService
    {
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSendBatch')->willReturnCallback(
            function (int $supplierId, array $ids, int $userId, ChannelContext $context) use (&$sentIds, &$captured): array {
                $sentIds = $ids;
                $captured = $context;

                return array_map(static fn (int $id): array => [
                    'id' => $id,
                    'dispatched' => true,
                    'row' => ['id' => $id],
                    'error_code' => null,
                    'error_message' => null,
                ], $ids);
            },
        );

        return $outbox;
    }

    private function context(string $authMode): ChannelContext
    {
        return new ChannelContext(
            7,
            'test',
            new ChannelCredentials(
                boxId: '',
                authMode: $authMode,
                sessionCookie: SensitiveValue::fromProducer(static fn (): string => 'synthetic'),
            ),
        );
    }

    private function action(
        SubmissionOutboxService $outbox,
        ?SubmissionCredentialService $credentials = null,
    ): SubmissionOutboxAction {
        return new SubmissionOutboxAction(
            $outbox,
            $credentials ?? $this->createStub(SubmissionCredentialService::class),
            $this->createStub(ActivityLogger::class),
            $this->createStub(MobileKeyIsdsAuthenticator::class),
            $this->createStub(IsdsMobileCredentialService::class),
            (new \ReflectionClass(SmsIsdsAuthenticator::class))->newInstanceWithoutConstructor(),
        );
    }

    /** @param array<string,mixed> $body */
    private function request(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/submissions/outbox/password-send-batch')
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }
}
