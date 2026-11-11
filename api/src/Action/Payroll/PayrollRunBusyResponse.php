<?php

declare(strict_types=1);

namespace MyInvoice\Action\Payroll;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Souběh dvou požadavků nad týmž mzdovým během (výpočet 500 osob a k tomu
 * výjimka nebo obnova podkladů) končil vypršením zámku nebo deadlockem jako
 * chyba 500 bez návodu. Je to stav „počkejte a zkuste znovu", proto 409:
 * obrazovka běhu po 409 data znovu načte.
 */
final class PayrollRunBusyResponse
{
    public const CODE = 'payroll_run_busy';

    public const MESSAGE = 'Nad tímto mzdovým během právě probíhá jiný požadavek (výpočet, výjimka '
        . 'nebo obnova podkladů). Počkejte chvíli, až doběhne, a akci zopakujte. Data běhu jsme '
        . 'načetli znovu.';

    public static function from(Response $response, \PDOException $failure): ?Response
    {
        if (!TransactionFailure::lockConflict($failure)) {
            return null;
        }

        return Json::error($response, self::CODE, self::MESSAGE, 409);
    }
}
