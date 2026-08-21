<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

enum TenantDataPolicy: string
{
    case TenantRoot = 'tenant_root';
    case TenantOwned = 'tenant_owned';
    case TenantOwnedIndirect = 'tenant_owned_indirect';
    case TenantRelation = 'tenant_relation';
    case GlobalReference = 'global_reference';
    case InstanceOwned = 'instance_owned';
    case PersonalSecretAttachment = 'personal_secret_attachment';
    case RuntimeDerived = 'runtime_derived';
    case Unsupported = 'unsupported';
}
