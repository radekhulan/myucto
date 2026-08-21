<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

enum TenantSecretPolicy: string
{
    case ReencryptV1 = 'reencrypt_v1';
    case ReencryptV2 = 'reencrypt_v2';
    case ReencryptPersonalWithDualConsent = 'reencrypt_personal_with_dual_consent';
    case OmitAndReconfigure = 'omit_and_reconfigure';
    case ExternalReference = 'external_reference';
    case NotSecret = 'not_secret';
}
