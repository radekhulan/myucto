<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Logbook;

use MyInvoice\Action\Logbook\OdometerSourceAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Security\RequestAuthorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Správa automatického stavu tachometru patří roli s právem zápisu do knihy jízd
 * ({@see RequestAuthorization::canManageOdometerSources()}); API token nesmí nic.
 * Odmítnutí proběhne dřív, než akce sáhne na službu nebo databázi.
 */
final class OdometerSourceActionAccessTest extends TestCase
{
    public static function levels(): iterable
    {
        yield 'bez práva' => [0, false];
        yield 'jen čtení' => [1, false];
        yield 'zápis' => [2, true];
    }

    #[DataProvider('levels')]
    public function testManagingRequiresLogbookWrite(int $level, bool $allowed): void
    {
        self::assertSame($allowed, RequestAuthorization::canManageOdometerSources($this->request('session', $level)));
    }

    /** @return iterable<string, array{string}> */
    public static function mutations(): iterable
    {
        foreach (['connect', 'continue', 'attach', 'selectVehicle', 'sync', 'disconnect'] as $method) yield $method => [$method];
    }

    #[DataProvider('mutations')]
    public function testReadOnlyRoleCannotChangeConnection(string $method): void
    {
        $response = $this->action()->$method($this->request('session', 1), new Response(), ['id' => '1']);

        self::assertSame(403, $response->getStatusCode());
    }

    #[DataProvider('mutations')]
    public function testApiTokenCannotChangeConnection(string $method): void
    {
        $response = $this->action()->$method($this->request('bearer', 2), new Response(), ['id' => '1']);

        self::assertSame(403, $response->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function reads(): iterable
    {
        foreach (['catalog', 'status'] as $method) yield $method => [$method];
    }

    #[DataProvider('reads')]
    public function testApiTokenCannotReadConnection(string $method): void
    {
        $response = $this->action()->$method($this->request('bearer', 2), new Response(), ['id' => '1']);

        self::assertSame(403, $response->getStatusCode());
    }

    private function action(): OdometerSourceAction
    {
        // Bez závislostí: odmítnutí musí přijít dřív, než je akce potřebuje.
        return (new \ReflectionClass(OdometerSourceAction::class))->newInstanceWithoutConstructor();
    }

    private function request(string $method, int $logbookWrite): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/logbook/cars/1/odometer-source/connect')
            ->withAttribute(AuthMiddleware::ATTR_METHOD, $method)
            ->withAttribute('auth.effective_role', new EffectiveRole(4, 'Delegovaná role', 'staff', true, [
                'logbook' => 2, 'logbook.write' => $logbookWrite, 'logbook.delete' => 2,
            ]));
    }
}
