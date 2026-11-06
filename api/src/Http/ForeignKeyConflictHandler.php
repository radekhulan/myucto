<?php

declare(strict_types=1);

namespace MyInvoice\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Záchranná síť pro mazání, které neošetřilo navázaný záznam (MariaDB 1451). Uživatel místo
 * holé 500 „Chyba" dostane 409 s vysvětlením. Vazbu i dotaz zapíše DbErrorLogger, ostatní
 * chyby databáze jdou dál výchozím handlerem.
 */
final class ForeignKeyConflictHandler
{
    private const ER_ROW_IS_REFERENCED = 1451;

    /** @param callable(ServerRequestInterface, Throwable, bool, bool, bool): ResponseInterface $fallback */
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private $fallback,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if (!$exception instanceof \PDOException || (int) ($exception->errorInfo[1] ?? 0) !== self::ER_ROW_IS_REFERENCED) {
            return ($this->fallback)($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
        }

        return Json::error(
            $this->responses->createResponse(),
            'referenced_record',
            'Záznam nelze smazat, protože na něj navazují jiná data. Nic se nesmazalo.',
            409,
        );
    }
}
