<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Compatibility;

/**
 * Pojmenovaný směrový kompatibilitní profil. V1 registruje pouze identity;
 * případný budoucí adaptér musí deklarovat konkrétní zdrojový a cílový profil.
 */
interface CompatibilityProfile
{
    public function id(): string;

    public function sourceProfile(): string;

    public function targetProfile(): string;

    /** @return list<CompatibilityIssue> */
    public function evaluate(CompatibilityFingerprint $source, CompatibilityFingerprint $target): array;
}
