<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Middleware;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\IpAllowlistMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * R1 — režim `apply_to = admin_only` četl syrovou cestu, takže
 * `/api/%61dmin/users` na str_starts_with('/api/admin/') nesedělo a IP kontrola
 * se úplně PŘESKOČILA; router přitom Action normálně doručil.
 *
 * Díru dřív maskovalo jen to, že ACL na tutéž escapovanou cestu nenašla pravidlo
 * a zavřela ji 403 — po opravě PermissionMiddleware by se odemkla.
 */
final class IpAllowlistPathEscapeTest extends TestCase
{
    private const ALLOWED_IP = '10.1.1.1';
    private const FOREIGN_IP = '203.0.113.9';

    public function testAdminOnlyModeBlocksForeignIpOnPercentEncodedAdminPath(): void
    {
        self::assertSame(403, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', '/api/%61dmin/users'));
        self::assertSame(403, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', '/api/admin/users'));
        self::assertSame(403, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', '/api/admin/./users'));
        self::assertSame(403, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', '/api//admin//users'));
    }

    public function testAdminOnlyModeStillLetsAllowedIpAndNonAdminPathsThrough(): void
    {
        self::assertSame(204, $this->statusFor('admin_only', self::ALLOWED_IP, 'GET', '/api/%61dmin/users'));
        self::assertSame(204, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', '/api/invoices'));
    }

    public function testAdminOnlyModeAlsoProtectsTechnicalAdministration(): void
    {
        foreach (['/api/instance-admin/status', '/api/%69nstance-admin/smtp',
            '/api/instance-admin/./suppliers', '/api//instance-admin//audit'] as $path) {
            self::assertSame(403, $this->statusFor('admin_only', self::FOREIGN_IP, 'GET', $path), $path);
            self::assertSame(204, $this->statusFor('admin_only', self::ALLOWED_IP, 'GET', $path), $path);
        }
    }

    /** Escapovaná /api/public/ výjimka platí přesně tam, kam router doručí. */
    public function testPublicPathExemptionMatchesRouterView(): void
    {
        self::assertSame(204, $this->statusFor('all', self::FOREIGN_IP, 'GET', '/api/public/approval/abc'));
        self::assertSame(204, $this->statusFor('all', self::FOREIGN_IP, 'GET', '/api/%70ublic/approval/abc'));
        self::assertSame(403, $this->statusFor('all', self::FOREIGN_IP, 'GET', '/api/invoices'));
    }

    private function statusFor(string $applyTo, string $ip, string $method, string $path): int
    {
        $config = new Config([
            'ip_allowlist' => [
                'enabled'  => true,
                'allow'    => ['10.1.1.0/24'],
                'apply_to' => $applyTo,
                'mode'     => 'block',
            ],
        ]);

        $middleware = new IpAllowlistMiddleware(
            $config,
            new IpMatcher($config),
            $this->createStub(ActivityLogger::class),
            new ResponseFactory(),
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $path, ['REMOTE_ADDR' => $ip]);

        return $middleware->process($request, $this->handler())->getStatusCode();
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
