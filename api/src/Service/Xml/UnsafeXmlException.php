<?php

declare(strict_types=1);

namespace MyInvoice\Service\Xml;

/**
 * Nedůvěryhodné XML se odmítlo načíst ({@see SafeXmlLoader}). `reason` říká proč,
 * `detail` nese první hlášku libxml u syntaktické chyby.
 */
final class UnsafeXmlException extends \RuntimeException
{
    public const TOO_LARGE      = 'too_large';
    public const TOO_MANY_NODES = 'too_many_nodes';
    public const DOCTYPE        = 'doctype';
    public const INVALID        = 'invalid';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $detail = '',
    ) {
        parent::__construct($message);
    }
}
