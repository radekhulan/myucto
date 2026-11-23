<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Accounting;

use MyInvoice\Action\Accounting\InvoiceSettlementAction;
use MyInvoice\Service\Accounting\PostingException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Účetní chyba při zápočtu (zamčené období, neplatný účet) je validační hláška.
 * Mapování volalo neexistující mapError(), takže každá taková chyba skončila HTTP 500.
 */
final class InvoiceSettlementErrorMappingTest extends TestCase
{
    public function testPostingExceptionIsValidationError(): void
    {
        $action = (new \ReflectionClass(InvoiceSettlementAction::class))->newInstanceWithoutConstructor();
        $map = new \ReflectionMethod($action, 'mapSettlementError');

        $response = $map->invoke(
            $action,
            (new ResponseFactory())->createResponse(),
            new PostingException('period_locked', 'Období je uzamčené.'),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('period_locked', $body['error']['code'] ?? null);
    }
}
