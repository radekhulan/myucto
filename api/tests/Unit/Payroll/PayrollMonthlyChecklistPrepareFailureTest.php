<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll;

use MyInvoice\Action\Payroll\PayrollMonthlyChecklistPrepareAction;
use MyInvoice\Http\ApiErrorHandler;
use MyInvoice\Service\Auth\EncryptionKeyUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * „Jen připravit" v měsíčním přehledu podání na instalaci bez šifrovacího
 * klíče vracelo 422 s anglickým „Encryption key is unavailable". Stejná situace
 * v „Otestovat XML" dávala 503 s českou hláškou; přehled ji teď předá témuž
 * handleru.
 */
final class PayrollMonthlyChecklistPrepareFailureTest extends TestCase
{
    public function testMissingEncryptionKeyIsNotAValidationError(): void
    {
        $missingKey = new EncryptionKeyUnavailableException('Encryption key is unavailable');

        foreach ([$missingKey, new \RuntimeException('Příprava selhala.', 0, $missingKey)] as $exception) {
            try {
                PayrollMonthlyChecklistPrepareAction::failureResponse(new Response(), $exception);
                self::fail('Chybějící klíč nesmí skončit jako 422.');
            } catch (\RuntimeException $thrown) {
                self::assertSame($exception, $thrown);
            }
        }

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/payroll/submissions/monthly-checklist/prepare')
            ->withHeader('Accept', 'application/json');
        $response = (new ApiErrorHandler(new CallableResolver(), new ResponseFactory(), new NullLogger()))(
            $request,
            $missingKey,
            false,
            false,
            false,
        );
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('encryption_key_unavailable', $body['error']['code']);
        self::assertSame(ApiErrorHandler::ENCRYPTION_KEY_UNAVAILABLE_MESSAGE, $body['error']['message']);
    }

    public function testOrdinaryFailuresKeepTheirStatus(): void
    {
        $invalid = PayrollMonthlyChecklistPrepareAction::failureResponse(
            new Response(),
            new \RuntimeException('Za období chybí schválený mzdový běh.'),
        );
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('chybí schválený mzdový běh', (string) $invalid->getBody());

        $conflict = PayrollMonthlyChecklistPrepareAction::failureResponse(
            new Response(),
            new \DomainException('Podání už existuje.'),
        );
        self::assertSame(409, $conflict->getStatusCode());
    }
}
