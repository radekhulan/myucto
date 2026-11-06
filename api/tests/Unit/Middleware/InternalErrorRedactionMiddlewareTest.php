<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\InternalErrorRedactionMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InternalErrorRedactionMiddlewareTest extends TestCase
{
    private const LEAK = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'uq_secret' in /var/www/html/api/src/Repository/FooRepository.php:42";

    public function testProductionHidesExceptionTextInInternalServerError(): void
    {
        $logger = $this->logger();
        $response = $this->dispatch(['app' => ['env' => 'production', 'debug' => false]], 500, $logger);
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('SQLSTATE', $raw);
        self::assertStringNotContainsString('/var/www/html', $raw);
        self::assertStringNotContainsString('uq_secret', $raw);
        self::assertSame('save_failed', $body['error']['code']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['error']['request_id']);
        self::assertStringContainsString($body['error']['request_id'], $body['error']['message']);
        self::assertSame($body['error']['request_id'], $response->getHeaderLine('X-Request-Id'));

        self::assertCount(1, $logger->records);
        self::assertSame($body['error']['request_id'], $logger->records[0]['context']['request_id']);
        self::assertSame(self::LEAK, $logger->records[0]['context']['message']);
    }

    public function testDevelopmentKeepsExceptionText(): void
    {
        $logger = $this->logger();
        $response = $this->dispatch(['app' => ['env' => 'development']], 500, $logger);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(self::LEAK, $body['error']['message']);
        self::assertArrayNotHasKey('request_id', $body['error']);
        self::assertSame([], $logger->records);
    }

    public function testDebugFlagKeepsExceptionTextOutsideManagedMode(): void
    {
        $response = $this->dispatch(['app' => ['env' => 'production', 'debug' => true]], 500, $this->logger());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(self::LEAK, $body['error']['message']);
    }

    /** @return iterable<string,array{int}> */
    public static function preservedStatuses(): iterable
    {
        yield 'validation' => [400];
        yield 'conflict' => [409];
        yield 'business rule' => [422];
        yield 'smtp upstream' => [502];
        yield 'unavailable' => [503];
        yield 'storage quota' => [507];
    }

    #[DataProvider('preservedStatuses')]
    public function testProductionKeepsIntentionalMessages(int $status): void
    {
        $logger = $this->logger();
        $response = $this->dispatch(['app' => ['env' => 'production']], $status, $logger);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(self::LEAK, $body['error']['message']);
        self::assertSame([], $logger->records);
    }

    public function testProductionKeepsAuthoredMessageOfInternalServerError(): void
    {
        $logger = $this->logger();
        $middleware = new InternalErrorRedactionMiddleware(new Config(['app' => ['env' => 'production']]), $logger);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Json::error((new ResponseFactory())->createResponse(), 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
            }
        };

        $response = $middleware->process($this->request(), $handler);
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('storage_not_writable', $body['error']['code']);
        self::assertStringStartsWith('Úložiště jobů', $body['error']['message']);
        self::assertFalse($response->hasHeader(Json::HEADER_PUBLIC_MESSAGE), 'Interní značka nesmí odejít ke klientovi.');
        self::assertSame([], $logger->records);
    }

    public function testProductionLeavesNonJsonBodyUntouched(): void
    {
        $middleware = new InternalErrorRedactionMiddleware(
            new Config(['app' => ['env' => 'production']]),
            $this->logger(),
        );
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse(500)->withHeader('Content-Type', 'application/pdf');
                $response->getBody()->write('%PDF-1.7');

                return $response;
            }
        };

        $response = $middleware->process($this->request(), $handler);

        self::assertSame('%PDF-1.7', (string) $response->getBody());
    }

    public function testBootstrapRegistersMiddleware(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Bootstrap.php');

        self::assertStringContainsString('$app->add(\MyInvoice\Middleware\InternalErrorRedactionMiddleware::class);', $source);
    }

    /** @param array<string,mixed> $config */
    private function dispatch(array $config, int $status, AbstractLogger $logger): ResponseInterface
    {
        $middleware = new InternalErrorRedactionMiddleware(new Config($config), $logger);
        $handler = new class ($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Json::internalError(
                    (new ResponseFactory())->createResponse(),
                    'save_failed',
                    InternalErrorRedactionMiddlewareTest::leak(),
                    $this->status,
                );
            }
        };

        return $middleware->process($this->request(), $handler);
    }

    public static function leak(): string
    {
        return self::LEAK;
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/invoices');
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
