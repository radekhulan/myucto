<?php

declare(strict_types=1);

namespace MyInvoice\Service\TenantTransfer\Registry;

enum TenantDataObjectKind: string
{
    case Table = 'table';
    case FileArea = 'file_area';
}
