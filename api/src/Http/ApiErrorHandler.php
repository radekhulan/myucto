<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use MyInvoice\Bootstrap;
use MyInvoice\Middleware\InternalErrorRedactionMiddleware;
use MyInvoice\Service\Auth\EncryptionKeyUnavailableException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler;
use Throwable;

/**
 * Výchozí handler nezachycených výjimek API.
 *
 * Výchozí handler Slimu se zapnutým `displayErrorDetails` (vývoj, `app.debug`)
 * posílal v odpovědi typ výjimky, absolutní cestu k souboru, řádek a celý stack
 * trace. Na sdílené vývojové nebo testovací instanci, kam se dívají i lidé mimo
 * vývoj, tak odpověď prozrazovala rozložení serveru, a frontend ji stejně neuměl
 * přečíst (čeká `{error: {code, message}}`), takže uživatel viděl jen anglické
 * „Request failed with status code 500".
 *
 * Co dělá:
 *   - chybějící šifrovací klíč ({@see EncryptionKeyUnavailableException}) je stav
 *     nastavení instance, ne programová chyba: 503 s kódem
 *     `encryption_key_unavailable` a hláškou, co se stalo a kdo to opraví,
 *   - ostatní výjimky: 500 s kódem `internal_error`, obecnou hláškou a `request_id`,
 *     pod kterým je v logu celý detail (typ, soubor, řádek, trace),
 *   - odpověď nikdy nenese soubor, řádek ani trace; ve vývoji a s `app.debug`
 *     přidá jen `debug.type` a `debug.message` s cestami zkrácenými na relativní,
 *   - HTTP výjimky Slimu (404, 405, …) a odpovědi mimo `/api` vykreslí Slim jako
 *     dřív, jen bez detailů výjimky.
 *
 * Záznam v logu zůstává ve formátu Slimu (`Slim Application Error Type: … File: …`),
 * se kterým počítá Diagnostika ({@see \MyInvoice\Service\System\DiagnosticsLogReader}).
 */
final class ApiErrorHandler extends ErrorHandler
{
    public const CODE_ENCRYPTION_KEY_UNAVAILABLE = 'encryption_key_unavailable';
    public const CODE_INTERNAL = 'internal_error';

    public const ENCRYPTION_KEY_UNAVAILABLE_MESSAGE = 'Šifrované osobní údaje nejde přečíst, protože tato instalace nemá šifrovací klíč, kterým byly uložené. Nejde o chybu v datech, ale o nastavení instalace: správce instalace musí do její konfigurace vrátit původní šifrovací klíč. Do té doby nejde připravit podání ani zobrazit údaje, které šifrované osobní údaje potřebují.';

    private string $requestId = '';

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $this->requestId = bin2hex(random_bytes(8));

        return parent::__invoke($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
    }

    protected function determineStatusCode(): int
    {
        if ($this->method !== 'OPTIONS' && self::isEncryptionKeyUnavailable($this->exception)) {
            return 503;
        }

        return parent::determineStatusCode();
    }

    protected function logError(string $error): void
    {
        parent::logError($error . "\nRequest ID: " . $this->requestId);
    }

    protected function respond(): ResponseInterface
    {
        $debug = $this->displayErrorDetails;
        // Detail výjimky (soubor, řádek, trace) do odpovědi nepatří nikdy; je v logu.
        $this->displayErrorDetails = false;

        if ($this->exception instanceof HttpException
            || $this->method === 'OPTIONS'
            || !$this->wantsJson()
        ) {
            return parent::respond();
        }

        $response = $this->responseFactory->createResponse();
        if ($this->statusCode === 503) {
            return Json::error(
                $response,
                self::CODE_ENCRYPTION_KEY_UNAVAILABLE,
                self::ENCRYPTION_KEY_UNAVAILABLE_MESSAGE,
                503,
                ['request_id' => $this->requestId],
            )
                ->withoutHeader(Json::HEADER_PUBLIC_MESSAGE)
                ->withHeader(InternalErrorRedactionMiddleware::HEADER_REQUEST_ID, $this->requestId);
        }

        $extra = ['request_id' => $this->requestId];
        if ($debug) {
            $extra['debug'] = [
                'type' => $this->exception::class,
                'message' => self::withoutRootPath($this->exception->getMessage()),
            ];
        }

        return Json::internalError(
            $response,
            self::CODE_INTERNAL,
            InternalErrorRedactionMiddleware::genericMessage($this->requestId),
            500,
            $extra,
        )->withHeader(InternalErrorRedactionMiddleware::HEADER_REQUEST_ID, $this->requestId);
    }

    private function wantsJson(): bool
    {
        if ($this->contentType === 'application/json') {
            return true;
        }
        $path = $this->request->getUri()->getPath();

        return $path === '/api' || str_starts_with($path, '/api/');
    }

    private static function isEncryptionKeyUnavailable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof EncryptionKeyUnavailableException) {
                return true;
            }
        }

        return false;
    }

    /** Absolutní cesty pod kořenem instalace zkrátí na relativní (sdílí i redakce 500). */
    public static function withoutRootPath(string $message): string
    {
        $root = rtrim(Bootstrap::rootDir(), '/\\');
        if ($root === '') {
            return $message;
        }

        return str_replace(
            [$root . '/', $root . '\\', str_replace('\\', '/', $root) . '/'],
            '',
            $message,
        );
    }
}
