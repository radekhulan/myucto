<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail\Imap;

/** Uložené heslo nelze použít pro jiný server, port, šifrování ani uživatele. */
final class ImapPasswordRequiredException extends \InvalidArgumentException
{
    public const CODE = 'imap_password_required';
}
