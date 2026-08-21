<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

/** Jediná heuristika názvů sloupců, které vyžadují explicitní secret politiku. */
final class TenantSecretColumnDetector
{
    private const PATTERN = '/(?:_enc|_ciphertext|password|secret|token|private_key|credential|salt)/i';

    public static function matches(string $column): bool
    {
        return preg_match(self::PATTERN, $column) === 1;
    }
}
