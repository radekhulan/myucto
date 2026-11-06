<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Transport;

/**
 * Síťové limity jednoho pokusu. Navázání spojení a celková doba jsou oddělené:
 * pomalé DNS/TCP se pozná dřív, než vyprší celý pokus.
 */
final readonly class EetTimeouts
{
    public function __construct(
        public int $connectMs = 2000,
        public int $totalMs = 3000,
    ) {}
}
