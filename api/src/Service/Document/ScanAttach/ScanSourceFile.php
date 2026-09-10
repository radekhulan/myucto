<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document\ScanAttach;

/** Jeden soubor ze zdroje skenů. */
final class ScanSourceFile
{
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly int $size,
        public readonly ?string $error = null,
    ) {}
}
