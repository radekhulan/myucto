<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

final readonly class EetHttpResponse
{
    /**
     * @param array<string,string> $headers názvy hlaviček malými písmeny
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
        public int $durationMs = 0,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
