<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Service\Eet\Transport\EetSigningCredential;

/** Odemčený pokladní certifikát pro jeden pokus o odeslání; `certificateId` jde do historie pokusů. */
final class EetActiveCredential
{
    public function __construct(
        public readonly int $certificateId,
        public readonly EetSigningCredential $credential,
    ) {}
}
