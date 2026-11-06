<?php

declare(strict_types=1);

namespace MyInvoice\Action\Report;

use MyInvoice\Http\Json;
use MyInvoice\Service\Accounting\PostingException;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Překlad výjimky z builderu výkazu na JSON chybu.
 *
 * Builder hlásí očekávané, uživatelem řešitelné stavy jako {@see PostingException}: chybí
 * základna dodatečného přiznání, chybí datum zjištění, dodatečné nemá co vykázat. Akce je
 * dřív chytaly jako `\Throwable` a přebalovaly na `build_failed`/500 — tím se ztratil
 * strojový kód, podle kterého rozhraní nabízí AKCI (např. „označte podaný snapshot"), a
 * uživatel dostal hlášku o chybě serveru na stav, který chybou serveru není.
 *
 * Ostatní výjimky zůstávají `build_failed`/500 — o těch nevíme, že jsou řešitelné.
 */
final class ReportBuildError
{
    public static function toJson(Response $response, \Throwable $e): Response
    {
        if ($e instanceof PostingException) {
            return Json::error($response, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->context);
        }
        return Json::internalError($response, 'build_failed', $e->getMessage(), 500);
    }
}
