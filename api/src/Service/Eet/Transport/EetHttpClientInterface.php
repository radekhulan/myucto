<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

interface EetHttpClientInterface
{
    /**
     * POST SOAP zprávy. Vrací odpověď s jakýmkoli HTTP stavem; výjimku
     * {@see EetHttpException} hází jen tehdy, když odpověď vůbec nedorazila.
     *
     * @param array<string,string> $headers
     */
    public function post(string $url, string $body, array $headers, EetTimeouts $timeouts): EetHttpResponse;
}
