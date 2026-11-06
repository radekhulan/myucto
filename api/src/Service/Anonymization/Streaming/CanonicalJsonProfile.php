<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

enum CanonicalJsonProfile
{
    case NONPROFIT;
    case PROPERTY;

    public function flags(): int
    {
        return JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | match ($this) {
            self::NONPROFIT => JSON_UNESCAPED_SLASHES,
            self::PROPERTY => JSON_PRESERVE_ZERO_FRACTION,
        };
    }
}
