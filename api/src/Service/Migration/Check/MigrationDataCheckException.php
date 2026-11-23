<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Check;

/** Odmítnutý požadavek na kontrolu (neznámá kontrola, kontrola bez opravy, změněná data, souběh). */
final class MigrationDataCheckException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
