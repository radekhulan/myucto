<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Http;

use MyInvoice\Http\ForeignKeyConflictHandler;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ForeignKeyConflictHandlerTest extends TestCase
{
    public function testRowIsReferencedBecomesConflict(): void
    {
        $response = $this->handle(self::pdoError('23000', 1451));

        self::assertSame(409, $response->getStatusCode());
        $response->getBody()->rewind();
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('referenced_record', $body['error']['code']);
    }

    public function testOtherDatabaseErrorsGoToDefaultHandler(): void
    {
        self::assertSame(500, $this->handle(self::pdoError('23000', 1062))->getStatusCode(), 'Duplicita není vazba.');
        self::assertSame(500, $this->handle(self::pdoError('42S02', 1146))->getStatusCode());
        self::assertSame(500, $this->handle(new \RuntimeException('x'))->getStatusCode());
    }

    private function handle(\Throwable $e): \Psr\Http\Message\ResponseInterface
    {
        $handler = new ForeignKeyConflictHandler(new ResponseFactory(), static fn (): Response => new Response(500));
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/api/x');

        return $handler($request, $e, false, true, true);
    }

    private static function pdoError(string $state, int $code): \PDOException
    {
        $e = new \PDOException('SQLSTATE error');
        $e->errorInfo = [$state, $code, 'detail'];

        return $e;
    }
}
