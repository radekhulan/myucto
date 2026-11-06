<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Service\Eet\Transport\EetEnvironment;

/**
 * Zdroj podpisového certifikátu pro odeslání tržby (implementuje EetCertificateVault).
 * Null = firma nemá pro prostředí platný aktivní certifikát; záznam pak čeká
 * ve frontě s kódem `certificate_missing`, nezahazuje se.
 */
interface EetCredentialProvider
{
    public function activeCredential(int $supplierId, EetEnvironment $env, ?string $eic = null): ?EetActiveCredential;
}
