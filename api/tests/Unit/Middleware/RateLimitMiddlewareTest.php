<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Cache\RedisFactory;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\RateLimitMiddleware;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RateLimitMiddlewareTest extends TestCase
{
    public function testSessionPollingHasHighPerSessionLimitWithoutExposingToken(): void
    {
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);

        foreach ([
            ['GET', '/api/auth/session/status'],
            ['POST', '/api/auth/session/activity'],
        ] as [$method, $path]) {
            $first = $this->rule($this->request($method, $path, $firstToken));
            $second = $this->rule($this->request($method, $path, $secondToken));

            self::assertSame(120, $first[1]);
            self::assertSame(60, $first[2]);
            self::assertNotSame($first[0], $second[0]);
            self::assertStringNotContainsString($firstToken, $first[0]);
            self::assertStringContainsString(hash('sha256', $firstToken), $first[0]);
        }
    }

    public function testSessionMutationKeepsLowerPerUserLimit(): void
    {
        $rule = $this->rule($this->request(
            'POST',
            '/api/auth/session/lock',
            str_repeat('a', 64),
        ));

        self::assertSame(20, $rule[1]);
        self::assertSame(60, $rule[2]);
        self::assertStringContainsString('user:17', $rule[0]);
    }

    public function testPasswordChangeHasOwnPerUserLimit(): void
    {
        $rule = $this->rule($this->request('POST', '/api/auth/change-password', str_repeat('a', 64)));

        self::assertSame(['rl:password-change:user:17', 10, 3600], $rule);
    }

    /**
     * 1GB záloha Money S3 je 122 částí po 8 MB; ve sdíleném mutation bucketu
     * (60–120/min) upload spadl na 429 těsně před koncem.
     */
    public function testUploadChunksHaveOwnBucketOutsideMutationLimit(): void
    {
        $chunk = $this->rule($this->request('POST', '/api/admin/imports/money-s3/uploads/0123456789abcdef/chunks', str_repeat('a', 64)));
        self::assertSame('rl:upload-chunk:user:17', $chunk[0]);
        self::assertSame(600, $chunk[1]);
        self::assertSame(60, $chunk[2]);

        $complete = $this->rule($this->request('POST', '/api/admin/imports/money-s3/uploads/0123456789abcdef/complete', str_repeat('a', 64)));
        self::assertSame('rl:mut:user:17', $complete[0]);
    }

    /**
     * AI vytěžení platí firma z vlastního klíče. Token měl dřív vlastní bucket 600/min
     * dřív, než se došlo k AI limitu, a víc tokenů téhož uživatele ho násobilo (#233).
     */
    public function testAiEndpointsKeepPerUserAiLimitForApiTokens(): void
    {
        foreach ([
            '/api/purchase-invoices/import-ai',
            '/api/v1/purchase-invoices/import-ai',
            '/api/purchase-invoices/scan-inbox',
            '/api/admin/imports/ai-extract-pdf',
        ] as $path) {
            foreach ([0, 5, 6] as $tokenId) {
                $rule = $this->rule($this->request('POST', $path, str_repeat('a', 64)), $tokenId);
                self::assertSame(['rl:ai:user:17', 30, 300], $rule, $path . ' token ' . $tokenId);
            }
        }
        self::assertSame('rl:api:tok:5', $this->rule($this->request('POST', '/api/purchase-invoices/import-structured', str_repeat('a', 64)), 5)[0]);
    }

    /**
     * @return array{0:string,1:int,2:int}
     */
    private function rule(ServerRequestInterface $request, int $tokenId = 0): array
    {
        $config = new Config([]);
        $middleware = new RateLimitMiddleware(
            $config,
            new RedisFactory($config),
            new ResponseFactory(),
            new IpMatcher($config),
            // MyÚčto má DB fallback limiteru; ruleFor() na spojení nesahá.
            // Stub, ne mock: nic se na něm neověřuje, jen dosazuje typ.
            $this->createStub(Connection::class),
        );
        $method = new \ReflectionMethod($middleware, 'ruleFor');
        $rule = $method->invoke(
            $middleware,
            $request->getUri()->getPath(),
            strtoupper($request->getMethod()),
            '127.0.0.1',
            17,
            $tokenId,
            $request,
        );
        self::assertIsArray($rule);
        return $rule;
    }

    private function request(string $method, string $path, string $token): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => 17])
            ->withAttribute(AuthMiddleware::ATTR_TOKEN, $token);
    }

    /**
     * Veřejný náhled výkazu práce běží BEZ přihlášení (userId = 0), takže generic
     * per-user limit se na něj neuplatní a musí ho krýt vlastní IP pravidlo.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function anonymousRule(string $method, string $path): array
    {
        $config = new Config([]);
        $middleware = new RateLimitMiddleware(
            $config,
            new RedisFactory($config),
            new ResponseFactory(),
            new IpMatcher($config),
            $this->createStub(Connection::class),
        );
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        $rule = (new \ReflectionMethod($middleware, 'ruleFor'))
            ->invoke($middleware, $path, strtoupper($method), '198.51.100.7', 0, 0, $request);
        self::assertIsArray($rule, "Veřejná cesta {$method} {$path} nemá rate limit — anonymní DoS.");
        return $rule;
    }

    /**
     * `request-code` ODESÍLÁ e-mail s ověřovacím kódem a `verify` je brute-force
     * plocha na ten kód. Captcha to na běžném self-hostu nekryje: výchozí
     * `captcha.provider = 'none'` nechá TurnstileVerifier vrátit true bez ověření.
     */
    public function testPublicWorkReportMutationsAreRateLimited(): void
    {
        foreach (['/api/public/work-report/abc123/request-code', '/api/public/work-report/abc123/verify'] as $path) {
            [$key, $limit, $window] = $this->anonymousRule('POST', $path);
            self::assertStringStartsWith('rl:pubwr-post:ip:', $key, $path);
            self::assertSame(10, $limit, 'Mutace veřejného výkazu mají mít přísnější limit než čtení.');
            self::assertSame(60, $window);
        }
    }

    /** Čtení náhledu má vlastní, volnější bucket — legit návštěva je jeden GET. */
    public function testPublicWorkReportReadIsRateLimited(): void
    {
        [$key, $limit] = $this->anonymousRule('GET', '/api/public/work-report/abc123');
        self::assertStringStartsWith('rl:pubwr:ip:', $key);
        self::assertSame(60, $limit);
    }

    /** Ostatní veřejné plochy limit mají už dřív — kontrola, že nezmizel. */
    public function testOtherPublicSurfacesKeepTheirLimits(): void
    {
        self::assertStringStartsWith('rl:approval:ip:', $this->anonymousRule('GET', '/api/public/approval/abc123')[0]);
        self::assertStringStartsWith('rl:pubinv:ip:', $this->anonymousRule('GET', '/api/public/invoice/abc123')[0]);
    }
}
