<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Submission;

use MyInvoice\Action\Submission\SubmissionOutboxAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Submission\Channel\ChannelContext;
use MyInvoice\Service\Submission\Channel\Isds\DirectIsdsInboxTransport;
use MyInvoice\Service\Submission\Channel\Isds\MobileKeyIsdsAuthenticator;
use MyInvoice\Service\Submission\Channel\Isds\SmsIsdsAuthenticator;
use MyInvoice\Service\Submission\IsdsMobileCredentialService;
use MyInvoice\Service\Submission\SubmissionCredentialService;
use MyInvoice\Service\Submission\SubmissionOutboxService;
use MyInvoice\Tests\Support\InMemoryIsdsAuthFlowStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Odeslání podání v relaci potvrzené kódem z SMS.
 *
 * Čtení schránky SMS kód umělo, odeslání ne — účetní s účtem chráněným SMS
 * tak zprávu připravila a odeslat ji z aplikace nemohla.
 */
final class OutboxSmsDispatchTest extends TestCase
{
    /** @var list<string> */
    private array $isdsCalls = [];

    public function testSmsSessionIsHandedToTheDispatchAndLoggedOut(): void
    {
        $captured = null;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSend')->willReturnCallback(
            function (int $supplierId, int $id, int $userId, ChannelContext $context) use (&$captured): array {
                $captured = $context;
                return ['dispatched' => true, 'row' => ['id' => $id]];
            },
        );
        $action = $this->action($outbox);

        $start = $action->smsStart(
            $this->request('start', ['username' => 'synthetic-user', 'password' => 'synthetic-password', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );
        self::assertSame(200, $start->getStatusCode());
        $startBody = json_decode((string) $start->getBody(), true);
        self::assertStringNotContainsString('synthetic-password', (string) $start->getBody());

        $complete = $action->smsComplete(
            $this->request('complete', ['flow_token' => $startBody['flow_token'], 'sms_code' => '123456', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(200, $complete->getStatusCode());
        self::assertInstanceOf(ChannelContext::class, $captured);
        self::assertSame('sms', $captured->credentials->authMode);
        self::assertTrue(
            DirectIsdsInboxTransport::hasConfirmedSession($captured),
            'Kontext musí projít bránou přímého transportu, jinak se odeslání tiše svede na náhradní cestu.',
        );
        self::assertTrue(json_decode((string) $complete->getBody(), true)['result']['dispatched']);
        self::assertSame(['send_sms', 'login', 'logout'], $this->isdsCalls);
    }

    public function testRejectedCodeDoesNotTouchTheSubmission(): void
    {
        $calls = 0;
        $outbox = $this->createStub(SubmissionOutboxService::class);
        $outbox->method('confirmAndSend')->willReturnCallback(
            function () use (&$calls): array {
                $calls++;
                return ['dispatched' => true, 'row' => []];
            },
        );
        $action = $this->action($outbox, rejectCode: true);
        $start = json_decode((string) $action->smsStart(
            $this->request('start', ['username' => 'synthetic-user', 'password' => 'synthetic-password', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        )->getBody(), true);

        $response = $action->smsComplete(
            $this->request('complete', ['flow_token' => $start['flow_token'], 'sms_code' => '123456', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(0, $calls, 'Bez potvrzené relace se podání nesmí odeslat.');
    }

    public function testMissingFlowTokenIsRejectedBeforeIsdsIsContacted(): void
    {
        $response = $this->action($this->createStub(SubmissionOutboxService::class))->smsComplete(
            $this->request('complete', ['sms_code' => '123456', 'environment' => 'test']),
            new Response(),
            ['id' => '42'],
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->isdsCalls);
    }

    public function testTokenAuthenticationCannotSend(): void
    {
        $response = $this->action($this->createStub(SubmissionOutboxService::class))->smsStart(
            $this->request('start', ['username' => 'synthetic-user', 'password' => 'synthetic-password', 'environment' => 'test'])
                ->withAttribute(AuthMiddleware::ATTR_METHOD, 'token'),
            new Response(),
            ['id' => '42'],
        );

        self::assertNotSame(200, $response->getStatusCode());
        self::assertSame([], $this->isdsCalls);
    }

    private function action(SubmissionOutboxService $outbox, bool $rejectCode = false): SubmissionOutboxAction
    {
        $sms = new SmsIsdsAuthenticator(
            $this->crypto(),
            new InMemoryIsdsAuthFlowStore(),
            function (string $operation) use ($rejectCode): array {
                $this->isdsCalls[] = $operation;
                if ($operation === 'login') {
                    return $rejectCode
                        ? ['status' => 401, 'body' => '', 'cookies' => [], 'headers' => []]
                        : ['status' => 302, 'body' => '', 'cookies' => ['IPCZ-X-COOKIE' => 'sms-session-cookie-123'], 'headers' => []];
                }

                return ['status' => 302, 'body' => '', 'cookies' => [], 'headers' => []];
            },
        );

        return new SubmissionOutboxAction(
            $outbox,
            $this->createStub(SubmissionCredentialService::class),
            $this->createStub(ActivityLogger::class),
            $this->createStub(MobileKeyIsdsAuthenticator::class),
            $this->createStub(IsdsMobileCredentialService::class),
            $sms,
            (new \ReflectionClass(\MyInvoice\Service\Submission\IsdsAccessService::class))->newInstanceWithoutConstructor(),
        );
    }

    /** @param array<string,mixed> $body */
    private function request(string $step, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/submissions/outbox/42/sms/' . $step)
            ->withParsedBody($body)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 7)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 3, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session');
    }

    private function crypto(): SecretEncryption
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty($config, 'data');
        $property->setValue($config, [
            'app' => [
                'secret_encryption_key' => base64_encode(random_bytes(32)),
                'secret_encryption_previous_keys' => [],
                'pepper' => '',
            ],
        ]);

        return new SecretEncryption($config);
    }
}
