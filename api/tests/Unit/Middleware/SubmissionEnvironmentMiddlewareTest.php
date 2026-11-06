<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SubmissionEnvironmentMiddleware;
use MyInvoice\Service\Submission\SubmissionEnvironmentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SubmissionEnvironmentMiddlewareTest extends TestCase
{
    #[DataProvider('rejectedRequestProvider')]
    public function testProductionInstallationRejectsTestEnvironment(string $method, string $path, array $query, ?array $body): void
    {
        $response = $this->middleware('production')->process($this->request($method, $path, $query, $body), $this->handler());

        self::assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
        self::assertStringContainsString(SubmissionEnvironmentPolicy::ERROR_CODE, (string) $response->getBody());
    }

    public static function rejectedRequestProvider(): array
    {
        return [
            'registrace tělem' => ['POST', '/api/payroll/submissions/registration/12/prepare', [], ['environment' => 'test']],
            'fronta query' => ['GET', '/api/payroll/submissions/queue', ['environment' => 'test'], null],
            'ISDS odeslání' => ['POST', '/api/submissions/outbox', [], ['environment' => 'test']],
            'brána ISDS' => ['POST', '/api/settings/isds-gateway/active', [], ['environment' => 'test']],
            'velká písmena' => ['POST', '/api/payroll/jmhz/deferrals', [], ['environment' => 'TEST']],
        ];
    }

    #[DataProvider('passingRequestProvider')]
    public function testPassesProductionEpoAndCleanup(string $method, string $path, array $query, ?array $body): void
    {
        $response = $this->middleware('production')->process($this->request($method, $path, $query, $body), $this->handler());

        self::assertSame(204, $response->getStatusCode(), $method . ' ' . $path);
    }

    public static function passingRequestProvider(): array
    {
        return [
            'produkce' => ['POST', '/api/payroll/submissions/registration/12/prepare', [], ['environment' => 'production']],
            'bez prostředí' => ['GET', '/api/payroll/submissions/queue', [], null],
            'zkušební EPO' => ['POST', '/api/reports/submissions/5/epo-direct', [], ['environment' => 'test']],
            'mazání testovacích údajů' => ['DELETE', '/api/settings/databox/test', [], null],
            'jiná oblast' => ['GET', '/api/payrollish', ['environment' => 'test'], null],
        ];
    }

    public function testDevelopmentInstallationAllowsTest(): void
    {
        $response = $this->middleware('development')->process(
            $this->request('POST', '/api/payroll/submissions/registration/12/prepare', [], ['environment' => 'test']),
            $this->handler(),
        );

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * Prostředí v argumentu cesty (`/api/settings/databox/inbox-storage/{environment}`)
     * je vidět jen za routingem. Test staví aplikaci ve stejném pořadí jako Bootstrap.
     */
    public function testRouteArgumentIsCheckedBehindRouting(): void
    {
        $app = new App(new ResponseFactory());
        $app->put('/api/settings/databox/inbox-storage/{environment:production|test}', static function ($request, $response) {
            return $response->withStatus(204);
        });
        $app->add($this->middleware('production'));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $test = $app->handle($this->request('PUT', '/api/settings/databox/inbox-storage/test', [], null));
        $production = $app->handle($this->request('PUT', '/api/settings/databox/inbox-storage/production', [], null));

        self::assertSame(403, $test->getStatusCode());
        self::assertSame(204, $production->getStatusCode());
    }

    public function testPolicyExposesDefaultProduction(): void
    {
        $policy = new SubmissionEnvironmentPolicy(new Config(['app' => ['env' => 'production']]));

        self::assertFalse($policy->testAllowed());
        self::assertTrue($policy->allows('production'));
        self::assertFalse($policy->allows('test'));
        self::assertSame('production', $policy->defaultEnvironment());
        self::assertTrue((new SubmissionEnvironmentPolicy(new Config(['app' => ['env' => 'development']])))->allows('test'));
    }

    public function testBootstrapRegistersMiddlewareInsideRouting(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Bootstrap.php');
        $middleware = strpos($source, '$app->add(\\MyInvoice\\Middleware\\SubmissionEnvironmentMiddleware::class)');
        $bodyParsing = strpos($source, '$app->addBodyParsingMiddleware()');

        self::assertNotFalse($middleware);
        self::assertNotFalse($bodyParsing);
        self::assertLessThan($bodyParsing, $middleware, 'Politika musí být přidaná před parsováním těla, aby běžela až za ním.');
    }

    private function middleware(string $env): SubmissionEnvironmentMiddleware
    {
        return new SubmissionEnvironmentMiddleware(
            new SubmissionEnvironmentPolicy(new Config(['app' => ['env' => $env]])),
            new ResponseFactory(),
        );
    }

    private function request(string $method, string $path, array $query, ?array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)->withQueryParams($query);
        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new ResponseFactory())->createResponse(204);
            }
        };
    }
}
