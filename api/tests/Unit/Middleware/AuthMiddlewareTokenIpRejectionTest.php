<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\ApiRequestLogger;
use MyInvoice\Service\Auth\ApiTokenService;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Odmítnutí platného tokenu kvůli IP adrese je bezpečnostní událost a MUSÍ nést
 * tu adresu — jinak majitel instalace neví, co má povolit ani co prošetřit.
 *
 * Zápis do `api_request_log` existoval, ale to je provozní přehled volání tokenu;
 * v `log/app.log`, kde se odmítnuté přístupy hledají, nebyla stopa žádná.
 */
final class AuthMiddlewareTokenIpRejectionTest extends TestCase
{
    /** IP z TEST-NET-3 (RFC 5737) — nikdy neodpovídá reálnému provozu. */
    private const CLIENT_IP = '203.0.113.42';

    public function testRejectedIpIsLoggedWithContext(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed, message:string, context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $response = $this->process($logger);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $logger->records, 'Odmítnutí musí zanechat právě jeden záznam.');

        $record = $logger->records[0];
        self::assertSame('warning', $record['level']);
        self::assertSame(self::CLIENT_IP, $record['context']['ip'] ?? null,
            'Bez IP adresy je záznam k ničemu — právě ta se povoluje nebo prošetřuje.');
        self::assertSame(7, $record['context']['token_id'] ?? null);
        self::assertSame('/api/invoices', $record['context']['route'] ?? null);
    }

    /** Volající je držitel platného tokenu a svou odchozí adresu za NATem jinak nezjistí. */
    public function testResponseNamesTheRejectedIp(): void
    {
        $response = $this->process(null);

        self::assertSame(403, $response->getStatusCode());
        $response->getBody()->rewind();
        self::assertStringContainsString(self::CLIENT_IP, (string) $response->getBody()->getContents());
    }

    private function process(?object $logger): ResponseInterface
    {
        $tokens = $this->createStub(ApiTokenService::class);
        $tokens->method('validate')->willReturn([
            'id'                   => 7,
            'user_id'              => 3,
            'supplier_id'          => 1,
            'scope'                => 'read',
            'user_email'           => 'token@example.test',
            'user_name'            => 'Token',
            'user_role_id'         => 2,
            'user_role_name'       => 'Účetní',
            'user_role_type'       => 'system',
            'user_role_active'     => 1,
            'user_role_system_key' => 'accountant',
            'user_locale'          => 'cs',
        ]);
        // Nenulová sada pravidel je podmínka, aby se allowlist vůbec vyhodnocoval.
        $tokens->method('ipRulesFor')->willReturn(['198.51.100.0/24']);

        $ipMatcher = $this->createStub(IpMatcher::class);
        $ipMatcher->method('clientIp')->willReturn(self::CLIENT_IP);
        $ipMatcher->method('matches')->willReturn(false);

        $middleware = new AuthMiddleware(
            new Config([]),
            $this->createStub(SessionManager::class),
            $this->createStub(Connection::class),
            new ResponseFactory(),
            $tokens,
            $ipMatcher,
            $this->createStub(UserRoleProfile::class),
            $this->apiRequestLoggerStub(),
            $logger,
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'http://localhost/api/invoices', ['REMOTE_ADDR' => self::CLIENT_IP])
            ->withHeader('Authorization', 'Bearer token-value');

        return $middleware->process($request, $this->neverCalledHandler());
    }

    /** `ApiRequestLogger` je final a mimo bypass-finals allowlist — stavíme ho reálně nad mock Connection. */
    private function apiRequestLoggerStub(): ApiRequestLogger
    {
        return new ApiRequestLogger($this->createStub(Connection::class), new NullLogger());
    }

    private function neverCalledHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Request s nepovolenou IP se nesmí dostat dál.');
            }
        };
    }
}
