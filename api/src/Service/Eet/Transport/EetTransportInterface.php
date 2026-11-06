<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

interface EetTransportInterface
{
    /**
     * Odešle jednu datovou zprávu. Chyby dat před odesláním hlásí výjimkou
     * {@see EetValidationException}; vše, co nastane po odeslání, vrací ve výsledku.
     */
    public function send(
        EetEnvironment $environment,
        EetSaleData $data,
        EetMessageHeader $header,
        EetSigningCredential $credential,
        EetTimeouts $timeouts,
    ): EetSendResult;
}
