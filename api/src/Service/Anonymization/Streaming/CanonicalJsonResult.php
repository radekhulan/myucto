<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

final readonly class CanonicalJsonResult
{
    public function __construct(
        public string $sha256,
        public int $bytes,
        public int $nodes,
        public int $members,
    ) {}
}
