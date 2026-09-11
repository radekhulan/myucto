<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Neúspěšné opakované ověření uživatele u citlivé operace
 * ({@see SensitiveOperationReauth}).
 *
 * Nese kód i HTTP status, aby volající akce jen přeložila výjimku na odpověď a
 * nemusela si stavy domýšlet — rozdíl mezi 401 (špatné heslo), 403 (spotřebovaný
 * proof) a 429 (zamčeno po pokusech) je pro UI podstatný.
 */
final class ReauthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
