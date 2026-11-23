<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

final class OtherItemException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        /** Pole formuláře, ke kterému chyba patří (klient ji ukáže u něj). */
        public readonly ?string $field = null,
        /** Doplňující údaje chyby pro klienta (např. neznámé zástupné znaky). */
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
