<?php

declare(strict_types=1);

namespace MyInvoice\Service\System;

final class CompanyPurgeException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const IMMUTABLE_REQUIRED = 'immutable_required';
    public const NO_TRIGGER_PRIVILEGE = 'no_trigger_privilege';
    public const BUSY = 'purge_in_progress';
    public const BLOCKED = 'purge_blocked';
    public const TRIGGER_RESTORE_FAILED = 'trigger_restore_failed';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
